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
// Every check names what it compared and why, because an invariant that fails with
// "mismatch" costs more to diagnose than it saved by existing.

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

async function check({ instance, plan, symbols, psql = null }) {
	const results = [];
	const idOf = (productKey) => symbols[`product:${productKey}`];
	const keyOfId = new Map();
	for (const [symbol, id] of Object.entries(symbols)) {
		if (symbol.startsWith('product:')) keyOfId.set(String(id), symbol.slice('product:'.length));
	}

	// --- 1. Stock is what the shadow ledger says it is ------------------------------------
	//
	// The headline check: ~1800 bookings composed into the right number, verified against a
	// model that never read the database.
	const stockRecord = await instance.silently(() => instance.get('/stock'));
	const liveAmount = new Map();
	for (const row of stockRecord.body || []) {
		liveAmount.set(String(row.product_id), Number(row.amount));
	}

	const expected = plan.ledger.expectedAmounts;
	const mismatches = [];
	for (const [symbol, want] of Object.entries(expected)) {
		const productKey = symbol.slice('product:'.length);
		const id = idOf(productKey);
		const got = liveAmount.get(String(id)) || 0;
		if (Math.abs(got - want) > 1e-6) mismatches.push(`${productKey}: live ${got}, ledger ${want}`);
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
	const byType = new Map();
	for (const row of log) {
		byType.set(row.transaction_type, (byType.get(row.transaction_type) || 0) + 1);
		if (Number(row.undone) !== 0) continue;
		const c = CONTRIBUTION[row.transaction_type];
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

	// --- 3. Every transaction type the plan emitted actually landed -------------------------
	const planned = {};
	for (const op of plan.ops) {
		const t = op.expect && op.expect.rowEquals && op.expect.rowEquals.transaction_type;
		if (t) planned[t] = (planned[t] || 0) + 1;
	}
	const missing = Object.keys(planned).filter((t) => !byType.has(t));
	ok(results, 'every planned transaction type appears in the log', missing.length === 0,
		missing.length === 0
			? [...byType].map(([t, n]) => `${t}=${n}`).sort().join(' ')
			: `never written: ${missing.join(', ')}`);

	// --- 4. Undo keeps its audit trail ------------------------------------------------------
	//
	// "Restores the prior state" means inventory state only. A build that undid by deleting
	// the row would satisfy every amount check above and have destroyed its own history, so
	// the row and its timestamp are asserted separately.
	const undone = log.filter((r) => Number(r.undone) !== 0);
	const undatedUndos = undone.filter((r) => !r.undone_timestamp);
	// Whether the *plan* contains an undo at all. The smoke profile is one month long and its
	// only month-start is day 0, when there is nothing yet to undo — so "no undone rows" is a
	// true statement about that profile rather than a defect, and an invariant that failed on
	// it would be asserting the profile rather than the application.
	const plannedUndos = plan.ops.filter((o) => /\/undo$/.test(o.path || '')).length;
	ok(results, 'undone bookings keep their row and a timestamp',
		plannedUndos === 0 ? true : (undone.length > 0 && undatedUndos.length === 0),
		plannedUndos === 0
			? 'the plan contains no undos at this profile — not applicable'
			: `${undone.length} undone rows, ${undatedUndos.length} without a timestamp`);

	// --- 5. The average-price oracle ---------------------------------------------------------
	//
	// Specified to what the view actually does (04_views_l1a.sql:77-95): purchase,
	// inventory-correction and self-production, price > 0 and amount > 0, weighted by amount.
	// Products whose entries were edited are skipped rather than guessed at — the view then
	// weights by edited_origin_amount and takes only the newest stock-edit-new, and a model
	// that approximated that would be asserting its own approximation.
	const editedProducts = new Set(log.filter((r) => String(r.transaction_type).startsWith('stock-edit')).map((r) => String(r.product_id)));
	const num = new Map();
	const den = new Map();
	for (const b of plan.ledger.bookingsDetail || []) {
		if (b.undone) continue;
		if (!['purchase', 'inventory-correction', 'self-production'].includes(b.type)) continue;
		if (!(b.price > 0) || !(b.amount > 0)) continue;
		const id = String(idOf(b.productKey));
		if (editedProducts.has(id)) continue;
		num.set(id, (num.get(id) || 0) + b.price * b.amount);
		den.set(id, (den.get(id) || 0) + b.amount);
	}
	const avgRecord = await instance.silently(() => instance.get('/objects/products_average_price'));
	const priceProblems = [];
	let compared = 0;
	for (const row of avgRecord.body || []) {
		const id = String(row.product_id);
		if (!den.has(id)) continue;
		const want = num.get(id) / den.get(id);
		const got = Number(row.price);
		compared++;
		if (Math.abs(got - want) > 0.0001) {
			priceProblems.push(`${keyOfId.get(id) || id}: view ${got.toFixed(4)}, ledger ${want.toFixed(4)}`);
		}
	}
	ok(results, 'products_average_price matches the ledger, to 4dp', priceProblems.length === 0,
		priceProblems.length === 0
			? `${compared} products compared, ${editedProducts.size} skipped as edited`
			: priceProblems.slice(0, 6).join('; '));

	// --- 6. The outbox drained ----------------------------------------------------------------
	//
	// Plan 18's whole claim, under a year of volume rather than one booking. `outbox` is not
	// an ExposedEntity on purpose, so this is the one check that needs SQL.
	if (psql) {
		try {
			const pending = Number(await psql('SELECT count(*) FROM outbox WHERE delivered_at IS NULL'));
			const total = Number(await psql('SELECT count(*) FROM outbox'));
			ok(results, 'the outbox drained completely', pending === 0,
				`${total} events, ${pending} still pending`);
		} catch (e) {
			ok(results, 'the outbox drained completely', false, `could not read outbox: ${String(e.message || e).slice(0, 120)}`);
		}
	}

	// --- 7. Price history lands on the days the plan bought things -----------------------------
	//
	// The strongest single piece of evidence that a *year* happened rather than a busy
	// minute: purchased_date is client-supplied, so these dates come from the plan, and a
	// suite whose clock or dates had collapsed would show them all on one day.
	const sampleKeys = Object.keys(expected).slice(0, 5).map((s) => s.slice('product:'.length));
	const historyProblems = [];
	for (const productKey of sampleKeys) {
		const id = idOf(productKey);
		if (!id) continue;
		const record = await instance.silently(() => instance.get(`/stock/products/${id}/price-history`));
		// `date`, not `purchased_date`: the view names the column purchased_date but the
		// endpoint renders it as `date` alongside a nested shopping_location. Reading the
		// column name made every purchase day look absent from a history that had them all.
		const days = new Set((record.body || []).map((r) => String(r.date).slice(0, 10)));
		const wanted = new Set((plan.ledger.bookingsDetail || [])
			.filter((b) => b.productKey === productKey && b.type === 'purchase' && b.purchasedDate)
			.map((b) => b.purchasedDate));
		const absent = [...wanted].filter((d) => !days.has(d));
		if (days.size > 0 && absent.length > 0) {
			historyProblems.push(`${productKey}: ${absent.length}/${wanted.size} purchase days absent from the history`);
		}
	}
	ok(results, 'price history covers the days the plan bought on', historyProblems.length === 0,
		historyProblems.length === 0 ? `${sampleKeys.length} products checked` : historyProblems.join('; '));

	return results;
}

module.exports = { check, readAll };
