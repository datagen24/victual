'use strict';

// The operations a household does rarely and a test suite needs anyway: counting the
// cupboard, correcting a mistake, undoing a booking, and the two deliberately awkward cases
// that are kept to the very end.

const { call, bookingRows, verifyStock } = require('../ops');
const { intBetween, pick, sample, chance } = require('../rng');
const { priceFor } = require('./groceries');

// Quarterly stocktake. Half the corrections go up and half down, because the two take
// different paths through InventoryProduct — up is an addition, down consumes in
// stock_next_use order.
function stocktake({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, plainProducts } = ctx;
	const counted = sample(rng, plainProducts, Math.min(8, plainProducts.length));

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

		// **An inventory increase says what it cost.** Sending no price does not mean no
		// price: the application fills one in, and products_average_price counts
		// inventory-correction rows, so an oracle that booked them as priceless disagreed
		// with the view in the fourth decimal place after a year — milk at 1.1845 against
		// 1.1843. Stating the price is what makes the two comparable.
		const price = priceFor(product, day, cal.days);
		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/inventory`,
			body: {
				new_amount: newAmount,
				best_before_date: bbd,
				purchased_date: purchasedDate,
				price: up ? price : undefined,
				location_id: `{location:${product.loc}}`
			},
			// An inventory correction moves the *delta*, not the new total — the endpoint takes
			// an absolute amount and books the difference.
			expect: bookingRows({ transactionType: 'inventory-correction', rowsSum: newAmount - current }),
			window: cal.dayWindow(day),
			ledger: { kind: 'inventory', product: product.key, newAmount },
			label: `q: count ${product.name} to ${newAmount}`
		}));

		ledger.inventory({
			productId: id, newAmount, bbd, purchasedDate,
			locationId: sym.location(product.loc), price: up ? price : null
		});
		ctx.verifyAfter(ops, product, day, 'inventory');
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
		expect: bookingRows({ transactionType: 'self-production', rowsSum: amount }),
		window: cal.dayWindow(day),
		ledger: { kind: 'self-production', product: product.key, amount },
		label: `d${day}: bake ${product.name}`
	}));
	ledger.purchase({
		productId: id, amount, bbd, purchasedDate,
		locationId: sym.location(product.loc), price: null, type: 'self-production'
	});
	ctx.verifyAfter(ops, product, day, 'self-production');
}

// Undoing. Always aimed at a booking bound earlier by symbol, so it cannot be aimed at a row
// that is not there — a failed undo is a rejected write, and the year deliberately contains
// none of those (that is what keeps the two id sequences in lockstep).
function undoSomething({ ctx, day, ops, recentBookings }) {
	const { rng, cal, ledger, sym } = ctx;
	if (recentBookings.length === 0) return;
	const booking = recentBookings.pop();

	// **Only undo a purchase whose stock is still there.** The emission used to be
	// unconditional while the model only followed when it could, so a purchase whose stock
	// had since been eaten was undone on the instance and not in the ledger — the two then
	// disagreed by that amount for the rest of the year. The first full-year replay hit it
	// on day 60: nine oil purchases planned, seven left un-undone, and a consume the model
	// thought was affordable that the application refused.
	//
	// It also stops the year asking for something whose outcome is not obviously defined:
	// the log after those undos implied a negative stock for the product, which is a
	// question worth asking deliberately and in isolation rather than by accident in March.
	// **The precondition is the entry, not the total.** `UndoBooking` refuses when the
	// purchased entry has later bookings against it (services/StockService.php:2064) and
	// otherwise deletes that entry whole (`:2078`), so "is there enough stock of this
	// product" was never the right question — a product can hold plenty while the specific
	// entry has been eaten.
	const id = sym.product(booking.product);
	const purchase = ledger.bookings[booking.seq];
	if (!purchase || purchase.entryKey === undefined || purchase.entryKey === null) return;
	const entry = ledger.entryByKey(purchase.entryKey);
	if (!entry || entry.amount !== booking.amount) return;

	ops.push(call({
		method: 'POST', path: `/stock/bookings/{${booking.symbol}}/undo`,
		body: {},
		expect: { status: 204 },
		window: cal.dayWindow(day),
		ledger: { kind: 'undo', product: booking.product, amount: booking.amount },
		label: `d${day}: undo the ${booking.product} purchase`
	}));

	// The entry the purchase created, removed whole — no booking of its own, because the
	// original is marked undone instead and the two cancel in the ledger identity.
	ledger.undoPurchase(purchase.entryKey);
	// **By index, not by resemblance.** Matching on (type, product, amount) marked whichever
	// purchase looked similar, and two purchases of the same amount at different prices are
	// ordinary — so the model excluded one booking from the average-price oracle while the
	// application had excluded another. It showed up as a fifth decimal place: milk at
	// 1.1843 against 1.1845 after a year.
	if (ledger.bookings[booking.seq]) ledger.bookings[booking.seq].undone = true;
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
	const { cal, world, sym, ledger } = ctx;
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
		// **The probe is isolated from the narrative, not from the model.** Leaving it out of
		// the ledger made the oracle short by exactly what the probe bought: the first
		// end-to-end run reported milk as live 3 against ledger 2, and an average price of
		// 1.2442 against 1.1600, both of which were the oracle being wrong rather than the
		// application.
		ledger.purchase({
			productId: sym.product(product.key), amount: 1, bbd, purchasedDate,
			locationId: sym.location(product.loc), price: n === 1 ? 1.11 : 2.22
		});
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
	ledger.consume({ productId: sym.product(product.key), amount: 1 });
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
	const { rng, cal, world, ledger, sym, plainProducts } = ctx;
	const stocked = plainProducts.filter((p) => ledger.amountOf(sym.product(p.key)) > 0);
	if (stocked.length === 0) return;

	// **The product has to be chosen for the case too, not just the entry.**
	//
	// Choosing at random kept landing on a product measured in whole units, whose entries are
	// consumed entire and so are never both drawn-on and still editable — seven edits a year,
	// every one on an untouched entry, which is the case that establishes nothing about
	// `edited_origin_amount`. Products measured in grams or millilitres are consumed in parts
	// and leave exactly the entry this wants, so they are preferred when one exists.
	const drawnOn = new Set();
	for (const b of ledger.bookings) {
		for (const t of b.touched || []) drawnOn.add(t.key);
	}
	const interesting = stocked.filter((p) => ledger.ordered(sym.product(p.key))
		.some((e) => e.amount > 1 && drawnOn.has(e.key)));
	const product = pick(rng, interesting.length > 0 ? interesting : stocked);
	const id = sym.product(product.key);

	// A correction downward, so the edit pair nets to a real change rather than to zero —
	// `stock-edit-old` carries the old amount and `stock-edit-new` the new one, and an edit
	// that changed nothing would exercise the path without testing the arithmetic. Only an
	// entry holding more than one unit can move.
	const step = product.qu === 'gram' || product.qu === 'millilitre' ? 50 : 1;
	const editable = ledger.ordered(id).filter((e) => e.amount > 1);
	if (editable.length === 0) return;

	// **Prefer an entry that has already been consumed from.**
	//
	// `products_average_price` weights an edited entry by `edited_origin_amount`, which the
	// application sets to the edited amount *plus* whatever had already been drawn from that
	// entry — so editing an untouched entry exercises the arithmetic with that term equal to
	// zero and establishes nothing about it. The previous version took `ordered()[0]` and
	// left hitting the real case to chance. The model records which entries each consume drew
	// on, so it can be chosen. An untouched entry is still edited when no drawn-on one is
	// available, because an edit that does not happen tests less than an easy one that does.
	const entry = editable.find((e) => drawnOn.has(e.key)) || editable[0];
	const priorConsumption = drawnOn.has(entry.key);

	// **The entry has to be named, not taken positionally.**
	//
	// The read used to be `order=id:asc&limit=1` — the lowest id — while the model chose in
	// `stock_next_use` order. Those are the same entry only by coincidence, so the edit could
	// land on one entry while the model recorded another, and every later price and lot
	// assertion for that product would be measured against the wrong one. Now the chosen
	// entry is described by its own dates and the read asserts that exactly one row matches,
	// which is what makes "this entry" mean the same thing on both instances.
	const sameDates = ledger.ordered(id)
		.filter((e) => e.bbd === entry.bbd && e.purchasedDate === entry.purchasedDate);
	if (sameDates.length !== 1) return;   // not uniquely nameable; skip rather than guess

	ops.push(call({
		method: 'GET',
		path: `/objects/stock?query%5B%5D=product_id%3D{product:${product.key}}` +
			`&query%5B%5D=best_before_date%3D${entry.bbd}` +
			`&query%5B%5D=purchased_date%3D${entry.purchasedDate}&order=id:asc`,
		expect: {
			status: 200, kind: 'array', length: 1,
			rowShape: ['id', 'product_id', 'amount'],
			everyRowEquals: { amount: entry.amount }
		},
		window: cal.dayWindow(day),
		bind: { [`entry:${product.key}:${day}`]: '[0].id' },
		label: `m${cal.month(day)}: find the ${product.name} entry of ${entry.amount} ` +
			`from ${entry.purchasedDate} to correct`
	}));

	const newAmount = Math.max(1, entry.amount - step);

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
		expect: bookingRows({ transactionType: 'stock-edit-old', length: 2, mixedTypes: true }),
		window: cal.dayWindow(day),
		ledger: { kind: 'edit', product: product.key, from: entry.amount, to: newAmount },
		label: `m${cal.month(day)}: correct ${product.name} ${entry.amount} -> ${newAmount}` +
			(priorConsumption ? ' (an entry already consumed from)' : ' (an untouched entry)')
	}));

	// The model follows: the old amount comes off and the new one goes on, which is exactly
	// what the two log rows say.
	// Priced and keyed to the entry, so the average-price oracle can apply the view's
	// edited-entry rule rather than excluding the product from the comparison.
	ledger.book('stock-edit-old', id, entry.amount, { price: entry.price, entryKey: entry.key });
	ledger.book('stock-edit-new', id, newAmount, { price: entry.price, entryKey: entry.key });
	entry.amount = newAmount;
	ctx.verifyAfter(ops, product, day, 'edit');
}

module.exports.editEntry = editEntry;
