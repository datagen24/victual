'use strict';

// Products weighed on a scale, where `amount` is not an amount.
//
// **With `enable_tare_weight_handling`, the API takes an absolute gross reading — container
// plus contents — and works out the delta itself.** On the way in the booked amount is
// `amount - stock_amount - tare_weight` and the reading must exceed tare plus what is
// already there (services/StockService.php:229-241); on the way out it is
// `|amount - stock_amount - tare_weight|` and the reading must be at least the tare
// (:562-573). Sending a delta, as every other product takes, answers "The amount cannot be
// lower than the defined tare weight" — which is what stopped the first full-year replay on
// its second day.
//
// So these products are handled here and excluded everywhere else, rather than teaching
// every emitter a second protocol for the sake of one branch. What the ledger stores is
// still the net contents; only the wire values are gross.

const { call, bookingRows } = require('../ops');
const { intBetween, chance } = require('../rng');

function tareProducts(world) {
	return world.products.filter((p) => p.tare);
}

// Refill the container: put `added` more in, expressed as the new gross reading.
function refill({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	for (const product of tareProducts(world)) {
		if (!chance(rng, 0.25)) continue;
		const id = sym.product(product.key);
		const net = ledger.amountOf(id);
		const added = intBetween(rng, 2, 6) * 100;
		const gross = product.tare + net + added;
		const bbd = product.shelfLife === null ? '2999-12-31' : cal.dateOffset(day, product.shelfLife);
		const purchasedDate = cal.date(day);
		if (ledger.hasEntryWith(id, bbd, purchasedDate)) continue;

		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/add`,
			body: {
				amount: gross, price: 3.49,
				best_before_date: bbd, purchased_date: purchasedDate,
				transaction_type: 'purchase',
				location_id: `{location:${product.loc}}`
			},
			expect: bookingRows({ transactionType: 'purchase' }),
			window: cal.dayWindow(day),
			ledger: { kind: 'purchase', product: product.key, amount: added, price: 3.49, tareGross: gross },
			label: `d${day}: refill ${product.name} to ${gross}g gross (+${added} net)`
		}));
		ledger.purchase({
			productId: id, amount: added, bbd, purchasedDate,
			locationId: sym.location(product.loc), price: 3.49
		});
	}
}

// Use some, expressed as the new gross reading after using it.
function use({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	for (const product of tareProducts(world)) {
		if (!chance(rng, 0.3)) continue;
		const id = sym.product(product.key);
		const net = ledger.amountOf(id);
		const used = Math.min(net, intBetween(rng, 1, 3) * 50);
		if (used <= 0) continue;
		const gross = product.tare + (net - used);

		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${product.key}}/consume`,
			body: { amount: gross },
			expect: bookingRows({ transactionType: 'consume' }),
			window: cal.dayWindow(day),
			ledger: { kind: 'consume', product: product.key, amount: used, tareGross: gross },
			label: `d${day}: ${product.name} down to ${gross}g gross (-${used} net)`
		}));
		ledger.consume({ productId: id, amount: used });
	}
}

module.exports = { refill, use, tareProducts };
