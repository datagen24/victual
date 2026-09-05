'use strict';

// The operations a household does rarely and a test suite needs anyway: counting the
// cupboard, correcting a mistake, undoing a booking, and the two deliberately awkward cases
// that are kept to the very end.

const { call, bookingRows } = require('../ops');
const { intBetween, pick, sample, chance } = require('../rng');

// Quarterly stocktake. Half the corrections go up and half down, because the two take
// different paths through InventoryProduct — up is an addition, down consumes in
// stock_next_use order.
function stocktake({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	const counted = sample(rng, world.products, Math.min(8, world.products.length));

	for (const product of counted) {
		const id = sym.product(product.key);
		const current = ledger.amountOf(id);
		const up = chance(rng, 0.5);
		const step = product.qu === 'gram' || product.qu === 'millilitre' ? 100 : 1;
		const newAmount = up ? current + step : Math.max(0, current - step);
		if (newAmount === current) continue;

		const bbd = cal.dateOffset(day, product.shelfLife === null ? 900 : product.shelfLife);
		const purchasedDate = cal.date(day);
		if (up && ledger.hasEntryWith(id, bbd, purchasedDate)) continue;

		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/inventory`,
			body: {
				new_amount: newAmount,
				best_before_date: bbd,
				purchased_date: purchasedDate,
				location_id: `{location:${product.loc}}`
			},
			expect: bookingRows({ transactionType: 'inventory-correction' }),
			window: cal.dayWindow(day),
			ledger: { kind: 'inventory', product: product.key, newAmount },
			label: `q: count ${product.name} to ${newAmount}`
		}));

		ledger.inventory({
			productId: id, newAmount, bbd, purchasedDate,
			locationId: sym.location(product.loc), price: null
		});
	}
}

// Something made at home rather than bought. The only way `self-production` appears in
// stock_log at all on this world — none of the recipes declares a produced product, and the
// add endpoint takes the transaction type directly.
function selfProduce({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	const product = world.products.find((p) => p.key === 'bread') || world.products[0];
	const id = sym.product(product.key);
	const bbd = cal.dateOffset(day, product.shelfLife === null ? 30 : product.shelfLife);
	const purchasedDate = cal.date(day);
	if (ledger.hasEntryWith(id, bbd, purchasedDate)) return;

	const amount = product.qu === 'slice' ? 18 : 1;
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${product.key}}/add`,
		body: {
			amount,
			best_before_date: bbd,
			purchased_date: purchasedDate,
			transaction_type: 'self-production',
			location_id: `{location:${product.loc}}`
		},
		expect: bookingRows({ transactionType: 'self-production' }),
		window: cal.dayWindow(day),
		ledger: { kind: 'self-production', product: product.key, amount },
		label: `d${day}: bake ${product.name}`
	}));
	ledger.purchase({
		productId: id, amount, bbd, purchasedDate,
		locationId: sym.location(product.loc), price: null, type: 'self-production'
	});
}

// Undoing. Always aimed at a booking bound earlier by symbol, so it cannot be aimed at a row
// that is not there — a failed undo is a rejected write, and the year deliberately contains
// none of those (that is what keeps the two id sequences in lockstep).
function undoSomething({ ctx, day, ops, recentBookings }) {
	const { rng, cal, ledger, sym } = ctx;
	if (recentBookings.length === 0) return;
	const booking = recentBookings.pop();

	ops.push(call({
		method: 'POST', path: `/stock/bookings/{${booking.symbol}}/undo`,
		body: {},
		expect: { status: 204 },
		window: cal.dayWindow(day),
		ledger: { kind: 'undo', product: booking.product, amount: booking.amount },
		label: `d${day}: undo the ${booking.product} purchase`
	}));

	// Undoing a purchase removes the stock it added. The model has to follow, or every
	// consume after this one is planned against stock that is no longer there.
	const id = sym.product(booking.product);
	const available = ledger.amountOf(id);
	if (available >= booking.amount) {
		ledger.consume({ productId: id, amount: booking.amount });
		// It is not a consumption in the ledger identity — the original booking is marked
		// undone instead, so the two cancel rather than adding a third row.
		ledger.bookings.pop();
		const original = ledger.bookings.findIndex((b) =>
			b.type === 'purchase' && b.productId === id && b.amount === booking.amount && !b.undone);
		if (original >= 0) ledger.bookings[original].undone = true;
	}
}

// **The two isolated fixtures, and they are last on purpose.**
//
// `stock_next_use` has no total tie-break, so two unopened entries of one product at one
// location sharing a best-before *and* a purchased date are ordered arbitrarily and the two
// engines may choose differently. Every other emitter refuses to build that state; this one
// builds it deliberately, once, so the hazard is exercised legibly instead of contaminating
// the year. It runs after the narrative because if the engines do choose differently, every
// later price and stock value would diverge from that point on.
//
// The merge is here for the same reason: it is destructive, and a year that merged in March
// would spend nine months comparing the consequences.
function isolatedTail({ ctx, ops }) {
	const { cal, world, sym } = ctx;
	const day = cal.days - 1;
	const product = world.products[0];
	const bbd = cal.dateOffset(day, 30);
	const purchasedDate = cal.date(day);

	ops.push({ op: 'mark', note: 'isolated: FIFO tie probe — two entries the view cannot order', day });

	for (const n of [1, 2]) {
		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/add`,
			body: {
				amount: 1, price: n === 1 ? 1.11 : 2.22,
				best_before_date: bbd, purchased_date: purchasedDate,
				transaction_type: 'purchase',
				location_id: `{location:${product.loc}}`
			},
			expect: bookingRows({ transactionType: 'purchase' }),
			window: cal.dayWindow(day),
			label: `tie probe: identical entry ${n} of ${product.name}`
		}));
	}

	// The consume that has to choose between them. Its *outcome* is deliberately not pinned
	// to one value — either lot is conforming, because the application does not say which.
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${product.key}}/consume`,
		body: { amount: 1 },
		expect: {
			status: 200, kind: 'array', minLength: 1,
			rowShape: ['id', 'product_id', 'amount', 'stock_id', 'transaction_type'],
			// No rowEquals on price: both 1.11 and 2.22 are correct answers here, and
			// asserting one would make an arbitrary choice look like a defect.
			acceptableAlternatives: 'price may be either of the tied entries'
		},
		window: cal.dayWindow(day),
		label: 'tie probe: consume one of two indistinguishable entries'
	}));
}

module.exports = { stocktake, selfProduce, undoSomething, isolatedTail };

// Correcting a stock entry, which is the only path that writes `stock-edit-old` and
// `stock-edit-new`.
//
// **The entry has to be found by its numeric id, and that is not the id the booking gave
// back.** `EditStockEntry(int $stockRowId, …)` fetches `stock WHERE id = :1`
// (services/StockService.php:735), whereas the `stock_id` on a booking row is the opaque
// uniqid() handle. Binding the latter here would produce a purchase that succeeded in
// January and an edit that failed in February.
//
// So the id is read, not guessed: `/objects/stock` is an exposed read-only entity, so it
// takes a filter and an explicit `order=id:asc`, which makes "the first row" mean the same
// thing on both instances rather than whatever order a view happened to return.
function editEntry({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	const stocked = world.products.filter((p) => ledger.amountOf(sym.product(p.key)) > 0);
	if (stocked.length === 0) return;
	const product = pick(rng, stocked);
	const id = sym.product(product.key);

	const entries = ledger.ordered(id);
	if (entries.length === 0) return;
	const entry = entries[0];

	ops.push(call({
		method: 'GET',
		path: `/objects/stock?query%5B%5D=product_id%3D{product:${product.key}}&order=id:asc&limit=1`,
		expect: { status: 200, kind: 'array', minLength: 1, rowShape: ['id', 'product_id', 'amount'] },
		window: cal.dayWindow(day),
		bind: { [`entry:${product.key}:${day}`]: '[0].id' },
		label: `m${cal.month(day)}: find a ${product.name} entry to correct`
	}));

	// A small correction downward, so the edit pair nets to a real change rather than to
	// zero — `stock-edit-old` carries the old amount and `stock-edit-new` the new one, and
	// an edit that changed nothing would exercise the path without testing the arithmetic.
	const step = product.qu === 'gram' || product.qu === 'millilitre' ? 50 : 1;
	const newAmount = Math.max(1, entry.amount - step);
	if (newAmount === entry.amount) return;

	ops.push(call({
		method: 'PUT',
		path: `/stock/entry/{entry:${product.key}:${day}}`,
		body: {
			amount: newAmount,
			best_before_date: entry.bbd,
			purchased_date: entry.purchasedDate,
			location_id: `{location:${product.loc}}`,
			price: entry.price === null ? 0 : entry.price,
			open: entry.open
		},
		expect: bookingRows({ transactionType: 'stock-edit-old', length: 2 }),
		window: cal.dayWindow(day),
		ledger: { kind: 'edit', product: product.key, from: entry.amount, to: newAmount },
		label: `m${cal.month(day)}: correct ${product.name} ${entry.amount} -> ${newAmount}`
	}));

	// The model follows: the old amount comes off and the new one goes on, which is exactly
	// what the two log rows say.
	ledger.book('stock-edit-old', id, entry.amount);
	ledger.book('stock-edit-new', id, newAmount);
	entry.amount = newAmount;
}

module.exports.editEntry = editEntry;
