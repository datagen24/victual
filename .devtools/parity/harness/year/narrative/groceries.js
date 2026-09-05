'use strict';

// Buying, eating, opening, freezing and throwing away — the bulk of the year's bookings.
//
// Every emitter here draws from its own PRNG stream and consults the shadow ledger before
// it writes anything down, so the plan cannot contain a consume the application would
// refuse. What it emits is checked against the ledger a second time at the end of
// generation (ledger.selfCheck), because a model that drifts from its own operations would
// produce expectations wrong in the same direction as the plan and agree with itself.

const { call, bookingRows, verifyStock } = require('../ops');
const { intBetween, pick, sample, chance, weighted } = require('../rng');

// Price with a seasonal shape and a yearly drift, computed rather than drawn, so that
// products_price_history and the spendings report have a signal in them and not just noise.
// Produce is cheapest in late summer; everything creeps up 3% across the year.
function priceFor(product, dayIndex, days) {
	const base = { dairy: 1.15, bakery: 1.4, produce: 0.9, pantrygrp: 1.6, frozen: 2.4, drinks: 1.1, household: 3.2, baby: 4.5 }[product.group] || 1.5;
	const amplitude = product.group === 'produce' ? 0.35 : 0.08;
	const phase = product.group === 'produce' ? 220 : 20;
	const seasonal = 1 + amplitude * Math.sin((2 * Math.PI * (dayIndex - phase)) / 365);
	const drift = 1 + (0.03 * dayIndex) / days;
	return Math.round(base * seasonal * drift * 100) / 100;
}

// How much of a product a shop buys, in its stock unit.
function purchaseAmount(rng, product) {
	if (product.qu === 'gram') return pick(rng, [200, 250, 400, 500, 1000]);
	if (product.qu === 'millilitre') return pick(rng, [250, 500, 750, 1000]);
	if (product.qu === 'slice') return 18;
	return intBetween(rng, 1, 4);
}

// The weekly shop. Tuesday, rotating shopping locations.
//
// **No two entries of one product on one day with the same best-before**, which is the
// generator's half of the `stock_next_use` tie hazard: that view has no total tie-break, so
// two such entries are ordered arbitrarily and the two engines may pick different ones. The
// ledger is asked before every add, and a collision simply skips the product this week.
function shop({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, profile, plainProducts } = ctx;
	const count = intBetween(rng, 6, 10) * profile.cadence;
	const shoppingLocation = world.shoppingLocations[cal.week(day) % world.shoppingLocations.length];
	const chosen = sample(rng, plainProducts, Math.min(count, plainProducts.length));

	for (const product of chosen) {
		const purchasedDate = cal.date(day);
		const bbd = product.shelfLife === null
			? '2999-12-31'   // the application's "never overdue" sentinel
			: cal.dateOffset(day, product.shelfLife + intBetween(rng, -1, 1));

		if (ledger.hasEntryWith(sym.product(product.key), bbd, purchasedDate)) continue;

		const amount = purchaseAmount(rng, product);
		const price = priceFor(product, day, cal.days);
		const useBarcode = chance(rng, 0.2);
		const path = useBarcode
			? `/stock/products/by-barcode/${ctx.barcodeOf(product.key)}/add`
			: `/stock/products/{product:${product.key}}/add`;

		ops.push(call({
			method: 'POST',
			path,
			body: {
				amount, price,
				best_before_date: bbd,
				purchased_date: purchasedDate,
				transaction_type: 'purchase',
				location_id: `{location:${product.loc}}`,
				shopping_location_id: `{shoppingLocation:${shoppingLocation.key}}`
			},
			expect: bookingRows({ transactionType: 'purchase', rowsSum: amount }),
			window: cal.dayWindow(day),
			bind: {
				[`booking:${product.key}:${day}`]: '[0].id',
				[`txn:${product.key}:${day}`]: '[0].transaction_id',
				[`stockident:${product.key}:${day}`]: '[0].stock_id'
			},
			ledger: { kind: 'purchase', product: product.key, amount, price, bbd, purchasedDate, location: product.loc },
			label: `w${cal.week(day)} shop: ${product.name} ${amount} @ ${price}`
		}));

		ledger.purchase({
			productId: sym.product(product.key), amount, bbd, purchasedDate,
			locationId: sym.location(product.loc), price
		});
		// The index of the booking just made, carried on the operation so that an undo later
		// in the year can name *this* booking rather than one that merely resembles it.
		ops[ops.length - 1].ledger.seq = ledger.bookings.length - 1;
		ctx.verifyAfter(ops, product, day, 'purchase');
	}
}

// Eating. Several days a week, drawn only from what the ledger says is there.
function eat({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, profile, plainProducts } = ctx;
	const meals = intBetween(rng, 2, 4) * profile.cadence;

	for (let i = 0; i < meals; i++) {
		const stocked = plainProducts.filter((p) => ledger.amountOf(sym.product(p.key)) > 0);
		if (stocked.length === 0) return;
		const product = pick(rng, stocked);
		const have = ledger.amountOf(sym.product(product.key));
		const want = product.qu === 'gram' || product.qu === 'millilitre'
			? Math.min(have, pick(rng, [50, 100, 200]))
			: Math.min(have, intBetween(rng, 1, 2));
		if (want <= 0) continue;
		// How many lots there were to choose between. One lot is no choice.
		const lotsBefore = ledger.ordered(sym.product(product.key)).length;

		const useBarcode = chance(rng, 0.12);
		ops.push(call({
			method: 'POST',
			path: useBarcode
				? `/stock/products/by-barcode/${ctx.barcodeOf(product.key)}/consume`
				: `/stock/products/{product:${product.key}}/consume`,
			body: { amount: want },
			expect: bookingRows({ transactionType: 'consume', rowsSum: -want }),
			window: cal.dayWindow(day),
			ledger: { kind: 'consume', product: product.key, amount: want },
			label: `d${day}: consume ${product.name} ${want}`
		}));
		ledger.consume({ productId: sym.product(product.key), amount: want });
		ctx.verifyAfter(ops, product, day, 'consume');
		ctx.verifyLotsAfter(ops, product, day, 'consume', lotsBefore);
	}
}

// Opening a package. The interesting case is the partial one, which splits the entry.
function openSomething({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, plainProducts } = ctx;
	const candidates = plainProducts.filter((p) => {
		const id = sym.product(p.key);
		return ledger.amountOf(id) > 0 && ledger.openAmountOf(id) === 0;
	});
	if (candidates.length === 0) return;

	const product = pick(rng, candidates);
	const have = ledger.amountOf(sym.product(product.key));
	const amount = product.qu === 'gram' || product.qu === 'millilitre'
		? Math.min(have, 100)
		: 1;

	ops.push(call({
		method: 'POST',
		path: `/stock/products/{product:${product.key}}/open`,
		body: { amount },
		// Opening moves quantity into the opened state and changes no total, so the
		// interesting assertion is the pair: stock_amount unchanged, stock_amount_opened up.
		expect: bookingRows({ transactionType: 'product-opened', rowsSum: amount }),
		window: cal.dayWindow(day),
		ledger: { kind: 'open', product: product.key, amount },
		label: `d${day}: open ${product.name}`
	}));
	const lotsBeforeOpen = ledger.ordered(sym.product(product.key)).length;
	ledger.open({ productId: sym.product(product.key), amount });
	ctx.verifyAfter(ops, product, day, 'open');
	ctx.verifyLotsAfter(ops, product, day, 'open', lotsBeforeOpen);
}

// Into the freezer. Exercises default_best_before_days_after_freezing, which recalculates
// the best-before on the moved entry — arithmetic that only this path runs.
function freeze({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, plainProducts } = ctx;
	const freezable = plainProducts.filter((p) =>
		p.freezeBonus && ledger.amountAtLocation(sym.product(p.key), sym.location(p.loc)) > 0 && p.loc !== 'freezer');
	const movable = freezable.length > 0 ? freezable : plainProducts.filter((p) =>
		p.loc === 'fridge' && ledger.amountAtLocation(sym.product(p.key), sym.location('fridge')) > 0);
	if (movable.length === 0) return;

	const product = pick(rng, movable);
	const at = ledger.amountAtLocation(sym.product(product.key), sym.location(product.loc));
	const amount = Math.min(at, product.qu === 'gram' ? 200 : 1);
	if (amount <= 0) return;

	ops.push(call({
		method: 'POST',
		path: `/stock/products/{product:${product.key}}/transfer`,
		body: {
			amount,
			location_id_from: `{location:${product.loc}}`,
			location_id_to: '{location:freezer}'
		},
		// A transfer books a from/to pair that nets to zero: the product's total is unchanged
		// and only its location moved.
		expect: bookingRows({ transactionType: 'transfer_from', length: 2, rowsSum: 0 }),
		window: cal.dayWindow(day),
		ledger: { kind: 'transfer', product: product.key, amount, from: product.loc, to: 'freezer' },
		label: `d${day}: freeze ${product.name} ${amount}`
	}));
	const lotsBeforeTransfer = ledger.ordered(sym.product(product.key)).length;
	ledger.transfer({
		productId: sym.product(product.key), amount,
		fromLocationId: sym.location(product.loc), toLocationId: sym.location('freezer')
	});
	ctx.verifyAfter(ops, product, day, 'transfer');
	ctx.verifyLotsAfter(ops, product, day, 'transfer', lotsBeforeTransfer);
}

// Throwing away. Drawn from entries the ledger says are actually past their best-before on
// this simulated day, so spoilage is a consequence of the calendar rather than a die roll.
function spoil({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym, plainProducts } = ctx;
	const today = cal.date(day);
	const expired = plainProducts.filter((p) => {
		const id = sym.product(p.key);
		return ledger.ordered(id).some((e) => e.bbd < today);
	});
	if (expired.length === 0) return;

	const product = pick(rng, expired);
	const id = sym.product(product.key);
	const entry = ledger.ordered(id).find((e) => e.bbd < today);
	const amount = Math.min(entry.amount, ledger.amountOf(id));
	if (amount <= 0) return;

	ops.push(call({
		method: 'POST',
		path: `/stock/products/{product:${product.key}}/consume`,
		body: { amount, spoiled: true },
		expect: bookingRows({ transactionType: 'consume', rowsSum: -amount }),
		window: cal.dayWindow(day),
		ledger: { kind: 'spoil', product: product.key, amount },
		label: `d${day}: spoiled ${product.name} ${amount}`
	}));
	const lotsBeforeSpoil = ledger.ordered(id).length;
	ledger.consume({ productId: id, amount, spoiled: true });
	ctx.verifyAfter(ops, product, day, 'spoil');
	ctx.verifyLotsAfter(ops, product, day, 'spoil', lotsBeforeSpoil);
}

module.exports = { shop, eat, openSomething, freeze, spoil, priceFor };
