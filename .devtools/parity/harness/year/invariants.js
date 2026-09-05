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

async function check({ instance, plan, symbols, psql = null }) {
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

	return results;
}

module.exports = { check, readAll, expectedAveragePrices };
