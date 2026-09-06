'use strict';

// **Two products that end holding exactly the same stock at exactly the same prices.**
//
// This is the one assertion in the suite that does not model the application. The
// average-price oracle deliberately mirrors `stock_edited_entries`' join, which makes it a
// *compatibility* oracle: it establishes that behaviour has not changed, not that it is
// right. Where the application's behaviour is itself in question, a compatibility oracle
// cannot be the evidence, so this states the property directly instead.
//
// Both products are bought identically — 500 at 2.00 and 500 at 1.00 — and both end with 400
// units at 2.00 and 500 at 1.00. They differ only in how the 2.00 lot got to 400:
//
//   plain    edit the 500 entry down to 400
//   split    open 100 of it, which splits off a 400 remainder, then edit that to 300
//            (100 opened + 300 remaining = 400)
//
// Nothing about that difference is visible in the resulting stock, so
// `products_average_price` must answer the same number for both. It does not:
// `stock_edited_entries` matches an edit to an origin row with the same `stock_id`
// (migration 0230:27-38), a split remainder has no origin row of its own, and its edit is
// therefore invisible to the average.
//
// The assertion is registered as **known-failing** rather than dropped, because a filed task
// is not executable evidence. It runs every time, reports every time, does not turn the run
// red — and fails the run if it ever *passes*, at which point either the defect was fixed or
// this stopped testing what it claims.

const { call, arrange, bookingRows, CREATED } = require('../ops');

const PLAIN = { key: 'sedplain', name: 'Y Split-edit control' };
const SPLIT = { key: 'sedsplit', name: 'Y Split-edit subject' };
const DEAR = 2.0;
const CHEAP = 1.0;
const LOT = 500;

// Both products hold 400 at DEAR and 500 at CHEAP when this is done, so the expected average
// is the same arithmetic for each — stated here rather than read from either of them.
const EXPECTED_AVERAGE = (400 * DEAR + LOT * CHEAP) / (400 + LOT);

function splitEditProbe({ ctx, ops }) {
	const { cal, ledger, sym, world } = ctx;
	const day = cal.days - 1;
	const purchasedDate = cal.date(day);
	const group = world.productGroups[0].key;
	const loc = 'pantry';

	ops.push({ op: 'mark', note: 'isolated: does an edit reach the average price after a split?', day });

	for (const p of [PLAIN, SPLIT]) {
		ops.push(arrange({
			method: 'POST', path: '/objects/products',
			body: {
				name: p.name,
				product_group_id: `{productGroup:${group}}`,
				location_id: `{location:${loc}}`,
				qu_id_stock: '{quantityUnit:gram}',
				qu_id_purchase: '{quantityUnit:gram}',
				min_stock_amount: 0
			},
			expect: CREATED,
			bind: { [`product:${p.key}`]: 'created_object_id' },
			label: `split-edit probe: ${p.name}`
		}));
		ledger.defineProduct(sym.product(p.key), { defaultConsumeLocationId: null });
	}

	// The dear lot first so it sorts first by best-before, which is what makes "the entry we
	// mean" the one an ordered read returns first.
	const bbdDear = cal.dateOffset(day, 200);
	const bbdCheap = cal.dateOffset(day, 300);

	const buy = (p, amount, price, bbd) => {
		ops.push(call({
			method: 'POST', path: `/stock/products/{product:${p.key}}/add`,
			body: {
				amount, price, best_before_date: bbd, purchased_date: purchasedDate,
				transaction_type: 'purchase', location_id: `{location:${loc}}`
			},
			expect: bookingRows({ transactionType: 'purchase', extra: { amount } }),
			window: cal.dayWindow(day),
			label: `split-edit probe: ${p.name} buys ${amount} at ${price}`
		}));
		ledger.purchase({
			productId: sym.product(p.key), amount, bbd, purchasedDate,
			locationId: sym.location(loc), price
		});
	};

	for (const p of [PLAIN, SPLIT]) {
		buy(p, LOT, DEAR, bbdDear);
		buy(p, LOT, CHEAP, bbdCheap);
	}

	// The split: opening 100 of the dear lot leaves 100 opened and a 400 remainder, and the
	// remainder is a new stock row with a fresh stock_id and no log row of its own
	// (StockService.php:1541+).
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${SPLIT.key}}/open`,
		body: { amount: 100 },
		expect: bookingRows({ transactionType: 'product-opened' }),
		window: cal.dayWindow(day),
		label: `split-edit probe: open 100 of ${SPLIT.name}, splitting the dear lot`
	}));
	ledger.open({ productId: sym.product(SPLIT.key), amount: 100 });

	// Each product's dear entry, named by its own dates so "the entry we mean" is not a
	// position. The control's is whole; the subject's is the 400 remainder.
	const findEntry = (p, amount) => ops.push(call({
		method: 'GET',
		path: `/objects/stock?query%5B%5D=product_id%3D{product:${p.key}}` +
			`&query%5B%5D=best_before_date%3D${bbdDear}` +
			`&query%5B%5D=amount%3D${amount}&order=id:asc`,
		expect: {
			status: 200, kind: 'array', length: 1,
			rowShape: ['id', 'product_id', 'amount'],
			everyRowEquals: { amount }
		},
		window: cal.dayWindow(day),
		bind: { [`entry:${p.key}`]: '[0].id' },
		label: `split-edit probe: the ${amount}-unit dear entry of ${p.name}`
	}));

	findEntry(PLAIN, LOT);
	findEntry(SPLIT, 400);

	// The same correction in substance: the dear stock goes from 500 to 400 in both.
	const edit = (p, from, to) => {
		ops.push(call({
			method: 'PUT', path: `/stock/entry/{entry:${p.key}}`,
			body: {
				amount: to, best_before_date: bbdDear, purchased_date: purchasedDate,
				location_id: `{location:${loc}}`, price: DEAR, open: 0
			},
			expect: bookingRows({ transactionType: 'stock-edit-old', length: 2, mixedTypes: true }),
			window: cal.dayWindow(day),
			label: `split-edit probe: ${p.name} corrects ${from} to ${to}`
		}));
		const id = sym.product(p.key);
		const entry = ledger.ordered(id).find((e) => e.bbd === bbdDear && e.amount === from && !e.open);
		if (!entry) return;
		ledger.book('stock-edit-old', id, entry.amount, { price: entry.price, entryKey: entry.key });
		ledger.book('stock-edit-new', id, to, { price: entry.price, entryKey: entry.key });
		entry.amount = to;
	};

	edit(PLAIN, LOT, 400);
	edit(SPLIT, 400, 300);

	// Both now hold 400 dear and 500 cheap. Read each average; the comparison itself is an
	// invariant, because it is a claim about the pair rather than about either response.
	for (const p of [PLAIN, SPLIT]) {
		ops.push(call({
			method: 'GET', path: `/stock/products/{product:${p.key}}`,
			expect: { status: 200, kind: 'object', equals: { stock_amount: 900 } },
			window: cal.dayWindow(day),
			label: `split-edit probe: ${p.name} holds 900 either way`
		}));
	}
}

module.exports = { splitEditProbe, PLAIN, SPLIT, EXPECTED_AVERAGE, DEAR, CHEAP, LOT };
