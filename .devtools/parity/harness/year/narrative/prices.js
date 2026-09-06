'use strict';

// **Three ways of saying "no price", and what each one is actually worth.**
//
// A stock entry created without a price came back as `null` in one situation and `0` in
// another. An earlier version of the model reacted by treating the two as the same value
// everywhere, which generalised a single observation into a rule: an unknown price and an
// explicit zero can mean different things in an API contract even when a valuation treats
// them alike.
//
// This fixture puts the three representations side by side and then does to them what the
// year does to everything else — purchase, partial consume, transfer, edit — so the question
// is answered by what the endpoints return rather than by a guess. What it asserts is only
// what is established:
//
//   - the fields the operation determines (amount, dates, location, open) — exactly;
//   - the *valuation* of the price, where zero and unknown are genuinely equivalent because
//     the price views themselves coalesce them (`COALESCE(price, 0) > 0`);
//
// and what it deliberately does not assert is which representation comes back, because
// nothing establishes that. The raw values stay in the trace, and `replay.js` records every
// place the representation differed from the plan's as an observation rather than a verdict.
//
// The transfer is a partial one on purpose: it splits a lot into two that `stock_next_use`
// cannot order, so the consume that follows exercises the tied-group expectation on a case
// built to produce it rather than waiting for the narrative to stumble into one.

const { call, bookingRows, verifyLots, LOT_FIELDS } = require('../ops');

// Something with no default consume location (so the ordering's location term is uniform
// across lots), no tare handling, and not the product the FIFO tie probe uses.
function subject(ctx) {
	const { world, plainProducts } = ctx;
	const taken = world.products[0].key;
	return plainProducts.find((p) => p.key !== taken && !p.consumeAt && !p.tare) || null;
}

function priceProbe({ ctx, ops }) {
	const { cal, world, ledger, sym } = ctx;
	const product = subject(ctx);
	if (!product) return;

	const day = cal.days - 1;
	const id = sym.product(product.key);
	const purchasedDate = cal.date(day);
	const here = sym.location(product.loc);
	const elsewhere = sym.location(world.locations.find((l) => l.key !== product.loc && !l.is_freezer).key);
	const elsewhereKey = world.locations.find((l) => l.key !== product.loc && !l.is_freezer).key;

	// Ahead of everything the year left behind, so the draws below are the ones intended.
	const bbd = (n) => cal.dateOffset(day, n);

	ops.push({ op: 'mark', note: 'isolated: price representations — omitted, null, zero', day });

	// The three lots. Only the body differs; everything else is held equal so the price is
	// the one variable.
	const lots = [
		{ tag: 'omitted', amount: 4, bbd: bbd(1), price: undefined },
		{ tag: 'null', amount: 6, bbd: bbd(2), price: null },
		{ tag: 'zero', amount: 5, bbd: bbd(3), price: 0 }
	];

	for (const lot of lots) {
		const body = {
			amount: lot.amount,
			best_before_date: lot.bbd,
			purchased_date: purchasedDate,
			transaction_type: 'purchase',
			location_id: `{location:${product.loc}}`
		};
		// The distinction the fixture exists for: an absent key, an explicit null, an
		// explicit zero. Do not let a helper normalise them on the way out.
		if (lot.price !== undefined) body.price = lot.price;

		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/add`,
			body,
			expect: bookingRows({ transactionType: 'purchase', extra: { amount: lot.amount } }),
			window: cal.dayWindow(day),
			label: `price probe: buy ${lot.amount} ${product.name} with price ${lot.tag}`
		}));
		ledger.purchase({
			productId: id, amount: lot.amount, bbd: lot.bbd, purchasedDate,
			locationId: here,
			...(lot.price === undefined ? {} : { price: lot.price })
		});
	}

	// **The representation itself, read back and left visible.** The amount is asserted
	// because the purchase determined it; the price is not, because nothing establishes
	// which of `null` and `0` the endpoint answers with. The row is in the trace either way,
	// which is the point — an unresolved case stays visible instead of being decided here.
	for (const lot of lots) {
		ops.push(call({
			method: 'GET',
			path: `/objects/stock?query%5B%5D=product_id%3D{product:${product.key}}` +
				`&query%5B%5D=best_before_date%3D${lot.bbd}&order=id:asc`,
			expect: {
				status: 200, kind: 'array', minLength: 1,
				rowShape: ['id', 'product_id', 'amount', 'price', 'best_before_date'],
				everyRowEquals: { amount: lot.amount }
			},
			window: cal.dayWindow(day),
			bind: { [`priced:${lot.tag}`]: '[0].id' },
			label: `price probe: how a ${lot.tag} price reads back`
		}));
	}

	const lotsOf = () => {
		const { exact, groups } = ledger.lotExpectation(id);
		return {
			exact: exact.map((e) => ({ ...e, location_id: `{${e.location_id}}` })),
			groups: groups.map((g) => ({ ...g, locations: g.locations.map((l) => `{${l}}`) }))
		};
	};
	const check = (note) => ops.push(verifyLots({
		productKey: product.key,
		...lotsOf(),
		window: cal.dayWindow(day),
		label: `price probe: lots after ${note}`
	}));

	check('the three purchases');

	// A partial consume out of the earliest lot: 4 in, 2 taken, 2 left at the same price.
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${product.key}}/consume`,
		body: { amount: 2 },
		expect: bookingRows({ transactionType: 'consume', rowsSum: -2 }),
		window: cal.dayWindow(day),
		label: 'price probe: consume 2, partially draining the omitted-price lot'
	}));
	ledger.consume({ productId: id, amount: 2 });
	check('a partial consume');

	// A partial transfer, which splits the null-price lot into two that share a best-before
	// date, a purchased date, an open flag and a price — indistinguishable to
	// `stock_next_use`, which is exactly the case the narrowed expectation is for.
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${product.key}}/transfer`,
		body: {
			amount: 3,
			location_id_from: `{location:${product.loc}}`,
			location_id_to: `{location:${elsewhereKey}}`
		},
		expect: bookingRows({ transactionType: 'transfer_from', length: 2, rowsSum: 0, mixedTypes: true }),
		window: cal.dayWindow(day),
		label: `price probe: transfer 3 to ${elsewhereKey}, splitting a lot into a tied pair`
	}));
	ledger.transfer({ productId: id, amount: 3, fromLocationId: here, toLocationId: elsewhere });
	check('a partial transfer');

	// Draining the rest of the first lot and then reaching into the tied pair. Which half of
	// the pair shrinks is not determined; the amount removed, the pair's total and the two
	// locations it may sit at are.
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${product.key}}/consume`,
		body: { amount: 4 },
		expect: bookingRows({ transactionType: 'consume', rowsSum: -4 }),
		window: cal.dayWindow(day),
		label: 'price probe: consume 4, reaching into the tied pair'
	}));
	ledger.consume({ productId: id, amount: 4 });
	check('a consume that had to choose between tied lots');

	// And an edit of the explicitly-zero-priced lot, found by the id its read-back bound.
	const zero = lots[2];
	const entry = ledger.ordered(id).find((e) => e.bbd === zero.bbd);
	if (!entry) return;
	const newAmount = entry.amount - 1;
	ops.push(call({
		method: 'PUT', path: '/stock/entry/{priced:zero}',
		body: {
			amount: newAmount,
			best_before_date: entry.bbd,
			purchased_date: entry.purchasedDate,
			location_id: `{location:${product.loc}}`,
			price: 0,
			open: entry.open
		},
		expect: bookingRows({ transactionType: 'stock-edit-old', length: 2, mixedTypes: true }),
		window: cal.dayWindow(day),
		label: `price probe: edit the zero-priced lot ${entry.amount} -> ${newAmount}`
	}));
	ledger.book('stock-edit-old', id, entry.amount, { price: entry.price, entryKey: entry.key });
	ledger.book('stock-edit-new', id, newAmount, { price: entry.price, entryKey: entry.key });
	entry.amount = newAmount;
	check('an edit');
}

module.exports = { priceProbe, LOT_FIELDS };
