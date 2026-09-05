'use strict';

// What has to be true of the database after a year, checked against something other than
// the database.
//
// This is the part a differential run and a captured baseline cannot do. Both of those
// detect *change*: upstream agreeing, or a blessed hash still matching. Neither detects
// *wrongness* — a build that has been wrong since the baseline was taken passes forever, and
// two engines that make the same mistake agree. The shadow ledger tracked the whole year
// independently of the application, so "what should the stock be" has an answer here that
// was never read out of the thing under test.
//
// **A missing row must be as detectable as a wrong one.** Every check below iterates over
// what the *plan* expects and looks each one up, rather than iterating over what the
// database returned and comparing whatever happens to be there. The difference is not
// academic: an earlier version of this file walked the rows `products_average_price` sent
// back, so an empty view passed with zero comparisons; it skipped price history whenever the
// history was empty; it asserted that each transaction type appeared *at least once* rather
// than in the amounts the plan booked; it asked only that *some* undone row existed rather
// than that the bookings the plan undid were the undone ones; and it excluded edited
// products from the price oracle altogether. Five ways to pass by finding nothing.

const { CONTRIBUTION } = require('./ledger');
const { queryPoints } = require('../lib/influx');
const { collectRetained } = require('../lib/mqtt');

function ok(results, name, passed, detail) {
	results.push({ name, ok: !!passed, detail });
	return passed;
}

// Reads a growing table in pages, with an explicit stable ordering key.
//
// Gaps in that key are legitimate — deletions, merges and sequence allocation all produce
// them — so completeness is "strictly increasing and unique, and the page sequence stopped
// because a page came back short", never "contiguous".
async function readAll(instance, path, key = 'id', page = 500) {
	const rows = [];
	let seenMax = -Infinity;
	for (let offset = 0; ; offset += page) {
		const sep = path.includes('?') ? '&' : '?';
		const record = await instance.silently(() =>
			instance.get(`${path}${sep}order=${key}:asc&limit=${page}&offset=${offset}`));
		if (record.status !== 200 || !Array.isArray(record.body)) {
			throw new Error(`reading ${path} answered HTTP ${record.status}`);
		}
		for (const row of record.body) {
			const k = Number(row[key]);
			if (!(k > seenMax)) throw new Error(`${path} returned ${key}=${k} after ${seenMax} — the ordering is not strict`);
			seenMax = k;
			rows.push(row);
		}
		if (record.body.length < page) break;
	}
	return rows;
}

// The expected average price per product, computed from the plan's own booking history and
// following `products_average_price` (db/pgsql/baseline/04_views_l1a.sql:77-95) exactly:
//
//   - purchase, inventory-correction and self-production count, but only for entries that
//     were never edited;
//   - for an entry that *was* edited, the newest `stock-edit-new` counts instead, weighted
//     by `edited_origin_amount` — the edited amount plus whatever had already been consumed
//     from that entry before the edit;
//   - rows with price <= 0, amount <= 0 or undone <> 0 are excluded;
//   - the weight is the amount.
//
// Modelling it is what lets edited products be *compared* rather than skipped. Skipping them
// was a hole: the one product the year edits is the one whose price arithmetic is least
// obvious, so it is exactly the product worth checking.
function expectedAveragePrices(bookings) {
	const editedEntries = new Map();   // entryKey -> newest stock-edit-new booking
	for (const b of bookings) {
		if (b.type !== 'stock-edit-new' || b.entryKey === null) continue;
		const prior = editedEntries.get(b.entryKey);
		if (!prior || b.seq > prior.seq) editedEntries.set(b.entryKey, b);
	}

	const consumedBefore = (entryKey, beforeSeq) => bookings
		.filter((b) => b.type === 'consume' && !b.undone && b.seq < beforeSeq && b.touched)
		.reduce((n, b) => n + b.touched
			.filter((t) => t.key === entryKey)
			.reduce((m, t) => m + t.amount, 0), 0);

	const num = new Map();
	const den = new Map();
	const add = (productKey, weight, price) => {
		if (!(price > 0) || !(weight > 0)) return;
		num.set(productKey, (num.get(productKey) || 0) + weight * price);
		den.set(productKey, (den.get(productKey) || 0) + weight);
	};

	for (const b of bookings) {
		if (b.undone) continue;
		if (!['purchase', 'inventory-correction', 'self-production'].includes(b.type)) continue;
		if (b.entryKey !== null && editedEntries.has(b.entryKey)) continue;   // superseded by its edit
		add(b.productKey, b.amount, b.price);
	}
	for (const edit of editedEntries.values()) {
		if (edit.undone) continue;
		add(edit.productKey, edit.amount + consumedBefore(edit.entryKey, edit.seq), edit.price);
	}

	const out = new Map();
	for (const [productKey, weight] of den) out.set(productKey, num.get(productKey) / weight);
	return out;
}

// What plan 18 should have delivered, checked against the series and the broker rather than
// against the outbox being empty.
//
// **An empty outbox is not delivery.** A build that never enqueued satisfies "nothing
// pending" perfectly, which is why the counts below come from the plan and not from the
// table. The rules are the publisher's own (services/Influx/BookingEventPublisher.php:790+):
// a `price_paid` point exists for a booking that is a purchase, was not undone *at the
// moment the event was captured*, and carried a price; `stock_value` carries one point per
// affected product per event.
//
// That capture-time qualifier is what makes the undo case meaningful. A purchase undone in
// March had already published its price_paid in February, and the undo publishes its own
// event rather than retracting that one — so the historical point must still be there. An
// implementation that deleted history on undo would satisfy every count that ignored it.
async function checkDelivery({ plan, symbols, influx, mqtt, results }) {
	const bookings = plan.ledger.bookingsDetail || [];
	const start = `${plan.meta.startDate}T00:00:00Z`;
	const stop = `${new Date(Date.parse(`${plan.meta.endDate}T00:00:00Z`) + 3 * 86400000).toISOString().slice(0, 10)}T00:00:00Z`;

	// --- price_paid, counted and valued from the plan ---------------------------------------
	const expectedPriced = bookings.filter((b) => b.type === 'purchase' && b.price !== null && b.price > 0);
	let paid;
	try {
		paid = await queryPoints({ ...influx, measurement: 'price_paid', start, stop });
	} catch (e) {
		ok(results, 'influx holds a price_paid point for every priced purchase', false,
			`could not query influx: ${String(e.message || e).slice(0, 120)}`);
		return;
	}

	const countProblems = [];
	if (paid.pointCount !== expectedPriced.length) {
		countProblems.push(`${paid.pointCount} points, the plan booked ${expectedPriced.length} priced purchases`);
	}
	// Values, as a multiset of (product, price, amount). Matching by booking id would be
	// tighter still, but the plan does not bind an id for every purchase; a multiset already
	// fails on a wrong price, a wrong amount, a missing point and a duplicated one.
	const key = (productKey, price, amount) => `${productKey}|${Number(price).toFixed(4)}|${Number(amount)}`;
	const wanted = new Map();
    for (const b of expectedPriced) {
		const k = key(b.productKey, b.price, b.amount);
		wanted.set(k, (wanted.get(k) || 0) + 1);
	}
	const keyOfId = new Map();
	for (const [symbol, id] of Object.entries(symbols)) {
		if (symbol.startsWith('product:')) keyOfId.set(String(id), symbol.slice('product:'.length));
	}
	for (const point of paid.points) {
		const productKey = keyOfId.get(String(point.tags.product_id));
		const k = key(productKey, point.fields.price, point.fields.amount);
		if (!wanted.has(k)) { countProblems.push(`unexpected point ${k}`); continue; }
		const left = wanted.get(k) - 1;
		if (left === 0) wanted.delete(k); else wanted.set(k, left);
	}
	for (const [k, n] of [...wanted].slice(0, 4)) countProblems.push(`missing ${n} x ${k}`);

	ok(results, 'influx holds a price_paid point for every priced purchase, with its values',
		countProblems.length === 0,
		countProblems.length === 0
			? `${paid.pointCount} points match the plan exactly`
			: countProblems.slice(0, 5).join('; '));

	// --- both halves of each event were delivered ---------------------------------------------
	let values;
	try {
		values = await queryPoints({ ...influx, measurement: 'stock_value', start, stop });
	} catch (e) {
		ok(results, 'every event delivered its stock_value points too', false, String(e.message || e).slice(0, 120));
		return;
	}
	const valueEvents = new Set(values.points.map((p) => p.tags.event_id));
	const orphaned = [...new Set(paid.points.map((p) => p.tags.event_id))].filter((id) => !valueEvents.has(id));
	ok(results, 'every event delivered its stock_value points too', orphaned.length === 0,
		orphaned.length === 0
			? `${valueEvents.size} events, ${values.pointCount} stock_value points`
			: `${orphaned.length} price_paid events have no stock_value point`);

	// --- history survives an undo ---------------------------------------------------------------
	const undonePurchases = bookings.filter((b) => b.undone && b.type === 'purchase' && b.price > 0);
	if (undonePurchases.length > 0) {
		const stillThere = undonePurchases.filter((b) =>
			paid.points.some((p) => keyOfId.get(String(p.tags.product_id)) === b.productKey
				&& Math.abs(Number(p.fields.price) - b.price) < 1e-6
				&& Math.abs(Number(p.fields.amount) - b.amount) < 1e-6));
		ok(results, 'an undone purchase keeps the event it already published',
			stillThere.length === undonePurchases.length,
			`${stillThere.length} of ${undonePurchases.length} retained`);
	}

	// --- the broker holds the current state ------------------------------------------------------
	if (mqtt) {
		try {
			const [host, port] = mqtt.split(':');
			const messages = await collectRetained(host, Number(port), '#');
			const state = messages.filter((m) => m.topic.startsWith('victual/state/'));
			const notRetained = state.filter((m) => !m.retained);
			const stockTopic = state.find((m) => /\/state\/stock$/.test(m.topic));
			const problems = [];
			if (state.length === 0) problems.push('no state topics on the broker at all');
			if (notRetained.length > 0) problems.push(`${notRetained.length} state topics are not retained`);
			if (!stockTopic) problems.push('no victual/state/stock topic');
			else {
				// `state` is the number of products currently in stock; the ledger knows it.
				const expectedInStock = Object.entries(plan.ledger.expectedAmounts).filter(([, n]) => n > 0).length;
				let published = null;
				try { published = JSON.parse(stockTopic.payload).state; } catch { /* reported below */ }
				if (published === null) problems.push('victual/state/stock did not parse as JSON');
				else if (Number(published) !== expectedInStock) {
					problems.push(`the broker says ${published} products in stock, the ledger says ${expectedInStock}`);
				}
			}
			ok(results, 'the broker holds the state the ledger ends at', problems.length === 0,
				problems.length === 0 ? `${state.length} retained state topics, stock agrees` : problems.join('; '));
		} catch (e) {
			ok(results, 'the broker holds the state the ledger ends at', false,
				`could not read the broker: ${String(e.message || e).slice(0, 120)}`);
		}
	}
}

async function check({ instance, plan, symbols, psql = null, influx = null, mqtt = null }) {
	const results = [];
	const bookings = plan.ledger.bookingsDetail || [];
	const idOf = (productKey) => symbols[`product:${productKey}`];
	const keyOfId = new Map();
	for (const [symbol, id] of Object.entries(symbols)) {
		if (symbol.startsWith('product:')) keyOfId.set(String(id), symbol.slice('product:'.length));
	}

	// --- 1. Stock is what the shadow ledger says it is ------------------------------------
	const stockRecord = await instance.silently(() => instance.get('/stock'));
	const liveAmount = new Map();
	for (const row of stockRecord.body || []) {
		liveAmount.set(String(row.product_id), Number(row.amount));
	}

	const expected = plan.ledger.expectedAmounts;
	const mismatches = [];
	for (const [symbol, want] of Object.entries(expected)) {
		const productKey = symbol.slice('product:'.length);
		const got = liveAmount.get(String(idOf(productKey)));
		// `undefined` and 0 are different answers: a product the plan expects to hold nothing
		// should still be *absent* from /stock only if the ledger says zero.
		const actual = got === undefined ? 0 : got;
		if (Math.abs(actual - want) > 1e-6) mismatches.push(`${productKey}: live ${got === undefined ? 'absent' : got}, ledger ${want}`);
	}
	ok(results, 'stock matches the shadow ledger', mismatches.length === 0,
		mismatches.length === 0
			? `${Object.keys(expected).length} products agree`
			: mismatches.slice(0, 6).join('; '));

	// --- 2. The ledger identity ------------------------------------------------------------
	//
	// Executable form, and the naive one is wrong three times over: product-opened carries a
	// positive amount but adds no stock; consume and transfer_from are already negative, so
	// an additions-minus-removals formulation double-negates; and stock-edit-old carries the
	// *old* amount positively and has to be subtracted (StockService.php:756,797).
	const log = await readAll(instance, '/objects/stock_log');
	const derived = new Map();
	const liveByType = new Map();
	for (const row of log) {
		if (Number(row.undone) !== 0) continue;
		const t = row.transaction_type;
		liveByType.set(t, (liveByType.get(t) || 0) + Number(row.amount));
		const c = CONTRIBUTION[t];
		if (c === undefined) continue;
		const pid = String(row.product_id);
		derived.set(pid, (derived.get(pid) || 0) + c * Number(row.amount));
	}

	const identityProblems = [];
	for (const [pid, want] of derived) {
		const got = liveAmount.get(pid) || 0;
		if (Math.abs(got - want) > 1e-6) {
			identityProblems.push(`product ${keyOfId.get(pid) || pid}: stock ${got}, log implies ${want}`);
		}
	}
	ok(results, 'sum(stock_log) equals stock, per product', identityProblems.length === 0,
		identityProblems.length === 0
			? `${derived.size} products, ${log.length} log rows`
			: identityProblems.slice(0, 6).join('; '));

	// --- 3. Each transaction type moved the amount the plan booked --------------------------
	//
	// Amounts rather than row counts, and that is not a softening: one consume can write
	// several `stock_log` rows when it draws down several entries, so a row count would be
	// asserting the application's splitting rather than the plan's intent. The *sum* per type
	// is exact, and unlike "the type appears at least once" it fails when a booking is
	// missing, duplicated or wrong.
	const plannedByType = new Map();
	for (const b of bookings) {
		if (b.undone) continue;
		plannedByType.set(b.type, (plannedByType.get(b.type) || 0) + b.amount);
	}
	const typeProblems = [];
	for (const [type, want] of plannedByType) {
		const got = liveByType.get(type);
		if (got === undefined) { typeProblems.push(`${type}: nothing written, plan booked ${want}`); continue; }
		if (Math.abs(got - want) > 1e-6) typeProblems.push(`${type}: log sums to ${got}, plan booked ${want}`);
	}
	for (const type of liveByType.keys()) {
		if (!plannedByType.has(type)) typeProblems.push(`${type}: written but the plan never booked it`);
	}
	ok(results, 'every transaction type moved what the plan booked', typeProblems.length === 0,
		typeProblems.length === 0
			? [...plannedByType].map(([t, n]) => `${t}=${n}`).sort().join(' ')
			: typeProblems.slice(0, 6).join('; '));

	// --- 4. Every booking the plan undid is undone, and still there --------------------------
	//
	// Named, not counted. Asking only that *some* undone row existed would pass a build that
	// undid the wrong booking, and asking nothing about the row's survival would pass one that
	// undid by deleting — which satisfies every amount check above and destroys the audit
	// trail.
	const plannedUndos = plan.ops
		.filter((op) => /\/stock\/bookings\/\{([^}]+)\}\/undo$/.test(op.path || ''))
		.map((op) => op.path.match(/\{([^}]+)\}/)[1]);
	const byLogId = new Map(log.map((r) => [String(r.id), r]));
	const undoProblems = [];
	for (const symbol of plannedUndos) {
		const id = symbols[symbol];
		if (id === undefined) { undoProblems.push(`${symbol} was never bound`); continue; }
		const row = byLogId.get(String(id));
		if (!row) { undoProblems.push(`booking ${id} (${symbol}) is not in the log at all`); continue; }
		if (Number(row.undone) === 0) undoProblems.push(`booking ${id} (${symbol}) was undone by the plan but is not marked undone`);
		else if (!row.undone_timestamp) undoProblems.push(`booking ${id} (${symbol}) is undone with no timestamp`);
	}
	// And nothing else is undone: a row the plan never touched being marked undone is as much
	// a defect as one it undid not being.
	const unexpectedUndone = log.filter((r) => Number(r.undone) !== 0)
		.filter((r) => !plannedUndos.some((sym) => String(symbols[sym]) === String(r.id)));
	for (const r of unexpectedUndone.slice(0, 3)) {
		undoProblems.push(`log row ${r.id} (${r.transaction_type}) is undone but the plan never undid it`);
	}
	ok(results, 'exactly the bookings the plan undid are undone, and retained', undoProblems.length === 0,
		undoProblems.length === 0
			? (plannedUndos.length === 0
				? 'the plan contains no undos at this profile'
				: `${plannedUndos.length} undos, each named and retained`)
			: undoProblems.slice(0, 6).join('; '));

	// --- 5. The average-price oracle ---------------------------------------------------------
	//
	// Driven from the products the *plan* expects a price for. Walking the view's rows instead
	// meant an empty view compared nothing and passed.
	const wantPrices = expectedAveragePrices(bookings);
	const avgRecord = await instance.silently(() => instance.get('/objects/products_average_price'));
	const livePrice = new Map();
	for (const row of avgRecord.body || []) livePrice.set(String(row.product_id), Number(row.price));

	const priceProblems = [];
	for (const [productKey, want] of wantPrices) {
		const id = String(idOf(productKey));
		if (!livePrice.has(id)) { priceProblems.push(`${productKey}: no row in products_average_price, expected ${want.toFixed(4)}`); continue; }
		const got = livePrice.get(id);
		if (Math.abs(got - want) > 0.0001) priceProblems.push(`${productKey}: view ${got.toFixed(4)}, ledger ${want.toFixed(4)}`);
	}
	for (const id of livePrice.keys()) {
		const productKey = keyOfId.get(id);
		if (productKey && !wantPrices.has(productKey)) {
			priceProblems.push(`${productKey}: the view priced it but the plan booked no priced entry`);
		}
	}
	ok(results, 'products_average_price matches the ledger, to 4dp', priceProblems.length === 0,
		priceProblems.length === 0
			? `${wantPrices.size} products compared, including every edited one`
			: priceProblems.slice(0, 6).join('; '));

	// --- 6. The outbox drained ----------------------------------------------------------------
	if (psql) {
		try {
			const pending = Number(await psql('SELECT count(*) FROM outbox WHERE delivered_at IS NULL'));
			const total = Number(await psql('SELECT count(*) FROM outbox'));
			// **An empty outbox is not proof of delivery.** A build that never enqueued would
			// satisfy "nothing pending", so the total is asserted against the plan's own count
			// of events worth publishing as well.
			const expectedEvents = bookings.filter((b) => !b.undone).length;
			const problems = [];
			if (pending !== 0) problems.push(`${pending} of ${total} still pending`);
			if (total === 0 && expectedEvents > 0) problems.push(`the outbox is empty but the plan booked ${expectedEvents} events`);
			ok(results, 'the outbox drained, and had something to drain', problems.length === 0,
				problems.length === 0 ? `${total} events, none pending` : problems.join('; '));
		} catch (e) {
			ok(results, 'the outbox drained, and had something to drain', false,
				`could not read outbox: ${String(e.message || e).slice(0, 120)}`);
		}
	}

	// --- 7. Price history covers every day the plan bought on ----------------------------------
	//
	// The strongest single piece of evidence that a *year* happened rather than a busy minute:
	// purchased_date is client-supplied, so these dates come from the plan. An empty history no
	// longer passes — it used to, because the check skipped itself when it found nothing.
	const historyProblems = [];
	const sampled = [...new Set(bookings.filter((b) => b.type === 'purchase' && b.purchasedDate).map((b) => b.productKey))].slice(0, 5);
	for (const productKey of sampled) {
		const id = idOf(productKey);
		if (!id) { historyProblems.push(`${productKey}: never bound`); continue; }
		const record = await instance.silently(() => instance.get(`/stock/products/${id}/price-history`));
		const days = new Set((record.body || []).map((r) => String(r.date).slice(0, 10)));
		const wanted = new Set(bookings
			.filter((b) => b.productKey === productKey && b.type === 'purchase' && b.purchasedDate && !b.undone && b.price > 0)
			.map((b) => b.purchasedDate));
		if (wanted.size === 0) continue;
		if (days.size === 0) { historyProblems.push(`${productKey}: the history is empty, ${wanted.size} purchase days expected`); continue; }
		const absent = [...wanted].filter((d) => !days.has(d));
		if (absent.length > 0) historyProblems.push(`${productKey}: ${absent.length}/${wanted.size} purchase days absent from the history`);
	}
	ok(results, 'price history covers every day the plan bought on', historyProblems.length === 0,
		historyProblems.length === 0 ? `${sampled.length} products checked` : historyProblems.join('; '));

	if (influx) await checkDelivery({ plan, symbols, influx, mqtt, results });

	return results;
}

module.exports = { check, checkDelivery, readAll, expectedAveragePrices };
