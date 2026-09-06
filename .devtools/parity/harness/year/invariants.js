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
// The same fail-closed comparison the replay assertions use: an unusable number is a
// mismatch, never an agreement. See differsBy() in replay.js.
const { differsBy } = require('./replay');
const { collectRetained } = require('../lib/mqtt');

function ok(results, name, passed, detail) {
	results.push({ name, ok: !!passed, detail });
	return passed;
}

// **An assertion of what the application should do, kept executable while it does not.**
//
// Modelling an application's own join makes a *compatibility* oracle: it establishes that
// behaviour has not changed, not that it is right. Where the two differ the difference
// deserves an assertion of its own, or the only record of a known defect is prose and a filed
// task — and neither of those re-runs.
//
// So a known-failing invariant runs, reports, and does not turn the run red. What it does do
// is fail the run when it *passes*: at that point either the defect was fixed and this marker
// is stale, or the assertion stopped testing what it claims. Both need a person, and neither
// should be found by someone noticing a line of output months later.
//
// Currently unused: the one assertion that needed it — split-entry edits reaching the average
// price — was retired when PR #77 fixed the defect, and the marker reported STALE on the way
// out. Kept because the next known defect should not have to re-invent it.
function known(results, name, passed, detail, defect) {
	results.push({
		name, detail, defect,
		ok: !passed,            // green while the defect stands
		known: true,
		regressed: !!passed
	});
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
// following `products_average_price` and `stock_edited_entries` as migration 0267 leaves
// them:
//
//   - an *origin group* is an entry that carries a booking, plus every entry split off it by
//     a partial open (`stock_entry_origins`, which only `OpenProduct` writes —
//     StockService.php:1561 — so a transferred entry is its own group and, having no booking,
//     contributes nothing);
//   - a group with no edit contributes each of its origin bookings at its own amount and
//     price;
//   - a group with an edit contributes once, at the price of its *newest* `stock-edit-new`,
//     weighted by `origin_amount + SUM(new - old)` across every edit in the group — what was
//     booked, adjusted by every correction;
//   - rows with price <= 0, amount <= 0 or undone <> 0 are excluded.
//
// **This replaced an oracle that modelled the previous behaviour.** Before 0267 the weight
// was the edited amount plus whatever had been consumed from that entry beforehand, and an
// edit whose entry had no booking of its own was ignored entirely — which is the asymmetry
// the year found and PR #77 fixed. Modelling it is what lets edited products be *compared*
// rather than skipped, and the year edits the one product whose price arithmetic is least
// obvious.
function expectedAveragePrices(bookings, entryOrigins = {}) {
	const originOf = (entryKey) => {
		if (entryKey === null || entryKey === undefined) return null;
		const mapped = entryOrigins[entryKey];
		return mapped === undefined ? entryKey : mapped;
	};

	// What each group started with, and which product it belongs to.
	const groups = new Map();   // originKey -> { productKey, originAmount, corrections, newest }
	const of = (key, productKey) => {
		if (!groups.has(key)) {
			groups.set(key, { productKey, originAmount: 0, corrections: 0, newest: null, edits: 0 });
		}
		return groups.get(key);
	};

	for (const b of bookings) {
		if (b.undone) continue;
		if (!['purchase', 'inventory-correction', 'self-production'].includes(b.type)) continue;
		if (!(b.amount > 0)) continue;
		const key = originOf(b.entryKey);
		if (key === null) continue;
		const g = of(key, b.productKey);
		g.originAmount += b.amount;
		g.bookings = (g.bookings || []).concat([b]);
	}

	// Each edit as a signed correction, paired with the `stock-edit-old` that preceded it on
	// the same entry — which is how the application pairs them, and why an edit that raised
	// an entry counts positively.
	const lastOldOf = new Map();
	for (const b of bookings) {
		if (b.undone || b.entryKey === null) continue;
		if (b.type === 'stock-edit-old') { lastOldOf.set(b.entryKey, b); continue; }
		if (b.type !== 'stock-edit-new') continue;
		const key = originOf(b.entryKey);
		if (key === null) continue;
		const g = of(key, b.productKey);
		const old = lastOldOf.get(b.entryKey);
		g.corrections += b.amount - (old ? old.amount : b.amount);
		g.edits += 1;
		if (!g.newest || b.seq > g.newest.seq) g.newest = b;
	}

	const num = new Map();
	const den = new Map();
	const add = (productKey, weight, price) => {
		if (!(price > 0) || !(weight > 0)) return;
		num.set(productKey, (num.get(productKey) || 0) + weight * price);
		den.set(productKey, (den.get(productKey) || 0) + weight);
	};

	for (const g of groups.values()) {
		if (g.edits === 0) {
			// Untouched: every origin booking counts as itself.
			for (const b of g.bookings || []) add(b.productKey, b.amount, b.price);
			continue;
		}
		// Corrected: the group contributes once, and its origin bookings do not.
		if (!g.newest) continue;
		add(g.newest.productKey, g.originAmount + g.corrections, g.newest.price);
	}

	const out = new Map();
	for (const [productKey, weight] of den) out.set(productKey, num.get(productKey) / weight);
	return out;
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

// **The one check here that does not model the application.**
//
// Two products end holding exactly the same stock at exactly the same prices — 400 at 2.00
// and 500 at 1.00 — differing only in that one of them reached 400 by editing a whole entry
// and the other by opening 100 (which splits the lot) and editing the 400 remainder. Nothing
// about that difference is visible in the resulting stock, so `products_average_price` must
// answer the same number for both.
//
// It does not, and the reason is documented in COVERAGE.md: `stock_edited_entries` matches an
// edit to an origin row with the same `stock_id`, a split remainder has no origin row, and so
// its edit never reaches the average. Registered as known-failing rather than removed, so the
// claim stays executable until the application decides what it should do.
async function checkSplitEditEquivalence({ instance, symbols, results }) {
	const idOf = (key) => symbols[`product:${key}`];
	const read = async (key) => {
		const id = idOf(key);
		if (id === undefined) return null;
		const record = await instance.get(`/stock/products/${id}`);
		const value = record.body && record.body.avg_price;
		return value === null || value === undefined ? null : Number(value);
	};

	const control = await read('sedplain');
	const subject = await read('sedsplit');
	if (control === null || subject === null) {
		ok(results, 'an edit reaches the average price whether or not the entry was split',
			false, 'the split-edit probe did not run, so nothing was established');
		return;
	}

	// **Retired from known-failing, because the application now does this.** PR #77 added
	// `stock_entry_origins` so `stock_edited_entries` can follow a split back to the booking
	// it came from. The marker did its job on the way out: the first run against the fixed
	// build reported STALE and failed, rather than leaving a claim about a defect standing
	// after the defect was gone.
	const agree = Math.abs(control - subject) < 1e-4;
	ok(results,
		'an edit reaches the average price whether or not the entry was split',
		agree,
		`whole-entry edit gives ${control.toFixed(4)}, split-remainder edit gives ` +
		`${subject.toFixed(4)} — the same stock at the same prices`);
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
// **"Carried a price" means `price !== null`, and that is not the rule the price views
// use.** The publisher's gate is an explicit null check
// (services/Influx/BookingEventPublisher.php:795, with the reasoning at :191 — "a booking
// with no price is not a booking at a price of nothing"), so a purchase booked at an
// explicit `0` publishes a `price_paid` point of 0.0000 while one booked with no price at
// all publishes none. `products_average_price` uses `COALESCE(price, 0) > 0` and excludes
// both.
//
// So the two surfaces genuinely disagree about whether an unknown price and a zero price
// are the same thing, and an oracle that applied the view's rule here counted one point too
// few. The price-representation fixture (narrative/prices.js) is what established it: its
// explicitly-zero-priced lot produced an unexpected `butter|0.0000|5` point on its first
// run. Each surface is now modelled by its own rule rather than by a shared assumption.
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
	const expectedPriced = bookings.filter((b) => b.type === 'purchase' && b.price !== null);
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
	// Values, as a multiset of (product, day, price, amount). Matching by booking id would be
	// tighter still, but the plan does not bind an id for every purchase; a multiset already
	// fails on a wrong price, a wrong amount, a missing point and a duplicated one.
	//
	// **The day is part of the key because the point's timestamp is part of its meaning.**
	// The publisher writes each point at the booking's own `row_created_timestamp` rather
	// than at delivery time, precisely so a backlog drained after an outage lands where it
	// belongs (BookingEventPublisher.php:16-18). Without the day in the key, a point that was
	// otherwise correct but written on the wrong date compared equal to the right one, so the
	// property the publisher goes out of its way to hold was the one thing not checked.
	const key = (productKey, day, price, amount) =>
		`${productKey}|${day}|${Number(price).toFixed(4)}|${Number(amount)}`;
	const dayOf = (isoTime) => String(isoTime || '').slice(0, 10);
	const wanted = new Map();
	for (const b of expectedPriced) {
		const k = key(b.productKey, b.day, b.price, b.amount);
		wanted.set(k, (wanted.get(k) || 0) + 1);
	}
	const keyOfId = new Map();
	for (const [symbol, id] of Object.entries(symbols)) {
		if (symbol.startsWith('product:')) keyOfId.set(String(id), symbol.slice('product:'.length));
	}
	for (const point of paid.points) {
		const productKey = keyOfId.get(String(point.tags.product_id));
		const k = key(productKey, dayOf(point.time), point.fields.price, point.fields.amount);
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

	// --- every event delivered its stock_value points ------------------------------------------
	//
	// **Derived from the planned operations, not from the events that happened to arrive.**
	// This used to walk `price_paid` event ids and check each had a `stock_value` partner,
	// which says nothing at all about events that carry no price: consumes, transfers, opens,
	// spoilage, inventory corrections and undos publish `stock_value` and never `price_paid`,
	// so a build that delivered purchases and dropped everything else satisfied it completely.
	// Confirmed by the reviewer with a purchase and a consume where only the purchase was
	// delivered: both delivery checks passed.
	//
	// The publisher writes one point per product a transaction touched, at the booking's own
	// timestamp (BookingEventPublisher.php:19-21), so the claim the plan can make is about
	// *which product had an event on which day*. That is checked in both directions — a
	// missing day is a lost delivery, an unexpected one is an event nobody planned.
	//
	// Undos are the one case the bookings cannot supply: undoing writes no booking of its own
	// (it flips `undone` on the original), so the undo's own event is expected from the plan's
	// operations instead.
	let values;
	try {
		values = await queryPoints({ ...influx, measurement: 'stock_value', start, stop });
	} catch (e) {
		ok(results, 'every product with a booking has its stock_value events, day by day',
			false, String(e.message || e).slice(0, 120));
		return;
	}

	const expectedDays = new Set();
	for (const b of bookings) {
		if (b.day) expectedDays.add(`${b.productKey}|${b.day}`);
	}
	for (const op of plan.ops || []) {
		if (op.ledger && op.ledger.kind === 'undo' && op.window && op.window.from) {
			expectedDays.add(`${op.ledger.product}|${String(op.window.from).slice(0, 10)}`);
		}
	}

	const observedDays = new Set();
	for (const point of values.points) {
		const productKey = keyOfId.get(String(point.tags.product_id));
		if (productKey) observedDays.add(`${productKey}|${dayOf(point.time)}`);
	}

	const missing = [...expectedDays].filter((k) => !observedDays.has(k));
	const unexpected = [...observedDays].filter((k) => !expectedDays.has(k));
	const deliveryProblems = [
		...missing.slice(0, 4).map((k) => `no stock_value for ${k}`),
		...unexpected.slice(0, 4).map((k) => `unplanned stock_value for ${k}`)
	];
	if (missing.length > 4) deliveryProblems.push(`… and ${missing.length - 4} more missing`);
	if (unexpected.length > 4) deliveryProblems.push(`… and ${unexpected.length - 4} more unplanned`);

	ok(results, 'every product with a booking has its stock_value events, day by day',
		deliveryProblems.length === 0,
		deliveryProblems.length === 0
			? `${expectedDays.size} product-days, all delivered`
			: deliveryProblems.join('; '));

	// And the pairing that was here before, kept because it asserts something the day-level
	// comparison cannot: that a priced purchase's two measurements travelled together under
	// one event id.
	const valueEvents = new Set(values.points.map((p) => p.tags.event_id));
	const orphaned = [...new Set(paid.points.map((p) => p.tags.event_id))].filter((id) => !valueEvents.has(id));
	ok(results, 'every price_paid event delivered its stock_value points too',
		orphaned.length === 0,
		orphaned.length === 0
			? `${valueEvents.size} events, both halves present`
			: `${orphaned.length} price_paid events with no stock_value`);

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
		if (differsBy(actual, want)) mismatches.push(`${productKey}: live ${got === undefined ? 'absent' : got}, ledger ${want}`);
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
		if (differsBy(got, want)) {
			identityProblems.push(`product ${keyOfId.get(pid) || pid}: stock ${got}, log implies ${want}`);
		}
	}
	ok(results, 'sum(stock_log) equals stock, per product', identityProblems.length === 0,
		identityProblems.length === 0
			? `${derived.size} products, ${log.length} log rows`
			: identityProblems.slice(0, 6).join('; '));

	// --- 2b. And it is in the right places ---------------------------------------------------
	//
	// Totals are not positions. A transfer books a pair summing to zero and leaves the
	// product's total untouched, so every check above passes whether it moved the right
	// amount, the wrong amount or nothing. The monthly checkpoints assert position during the
	// replay; this asserts it at the end, which is what an injected move between locations
	// showed was missing — it changed nothing any end-state invariant looked at.
	const locRecord = await instance.silently(() => instance.get('/objects/stock_current_locations'));
	const liveAt = new Map();
	for (const row of locRecord.body || []) {
		liveAt.set(`${row.product_id}@${row.location_id}`, Number(row.amount));
	}
	const positionProblems = [];
	const wantedPositions = plan.ledger.expectedLocations || [];
	for (const pos of wantedPositions) {
		const productKey = String(pos.productId).replace(/^product:/, '');
		const locationKey = String(pos.locationId).replace(/^location:/, '');
		const key = `${symbols[`product:${productKey}`]}@${symbols[`location:${locationKey}`]}`;
		const got = liveAt.get(key);
		if (got === undefined) positionProblems.push(`${productKey} holds nothing at ${locationKey}, ledger says ${pos.amount}`);
		else if (differsBy(got, pos.amount)) positionProblems.push(`${productKey}@${locationKey}: live ${got}, ledger ${pos.amount}`);
		liveAt.delete(key);
	}
	for (const [key, amount] of [...liveAt].slice(0, 3)) {
		positionProblems.push(`stock of ${amount} at ${key} that the ledger does not place there`);
	}
	ok(results, 'every product sits where the ledger put it', positionProblems.length === 0,
		positionProblems.length === 0
			? `${wantedPositions.length} positions agree`
			: positionProblems.slice(0, 6).join('; '));

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
		if (differsBy(got, want)) typeProblems.push(`${type}: log sums to ${got}, plan booked ${want}`);
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
	const wantPrices = expectedAveragePrices(bookings, plan.ledger.entryOrigins || {});
	const avgRecord = await instance.silently(() => instance.get('/objects/products_average_price'));
	const livePrice = new Map();
	for (const row of avgRecord.body || []) livePrice.set(String(row.product_id), Number(row.price));

	const priceProblems = [];
	for (const [productKey, want] of wantPrices) {
		const id = String(idOf(productKey));
		if (!livePrice.has(id)) { priceProblems.push(`${productKey}: no row in products_average_price, expected ${want.toFixed(4)}`); continue; }
		const got = livePrice.get(id);
		if (differsBy(got, want, 0.0001)) priceProblems.push(`${productKey}: view ${Number(got).toFixed(4)}, ledger ${Number(want).toFixed(4)}`);
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

	await checkSplitEditEquivalence({ instance, symbols, results });
	if (influx) await checkDelivery({ plan, symbols, influx, mqtt, results });

	return results;
}

module.exports = { check, checkDelivery, readAll, expectedAveragePrices };
