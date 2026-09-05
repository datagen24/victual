'use strict';

// The shopping list: what the household means to buy, and the three derived queries that
// fill it from stock.
//
// The derived ones are the point. `add-missing-products`, `add-overdue-products` and
// `add-expired-products` each run a query over the whole stock table against min_stock_amount
// or against the clock, so they are exactly the kind of answer that changes when an engine
// or a view changes while every individual booking still looks right.

const { call } = require('../ops');
const { intBetween, pick, sample, chance } = require('../rng');

function fillList({ ctx, day, ops }) {
	const { rng, cal, world } = ctx;

	ops.push(call({
		method: 'POST', path: '/stock/shoppinglist/add-missing-products',
		body: {},
		expect: { status: 204 },
		window: cal.dayWindow(day),
		label: `w${cal.week(day)}: add products below their minimum`
	}));

	for (const product of sample(rng, world.products, intBetween(rng, 2, 4))) {
		ops.push(call({
			method: 'POST', path: '/stock/shoppinglist/add-product',
			body: { product_id: `{product:${product.key}}`, product_amount: intBetween(rng, 1, 3) },
			expect: { status: 204 },
			window: cal.dayWindow(day),
			label: `w${cal.week(day)}: list ${product.name}`
		}));
	}

	ops.push(call({
		method: 'GET', path: '/objects/shopping_list?order=id:asc',
		expect: { status: 200, kind: 'array' },
		window: cal.dayWindow(day),
		label: `w${cal.week(day)}: read the shopping list`
	}));
}

// After the shop. `done_only` is not passed, so the whole list goes — which is what makes
// the next week's derived queries answer about the next week rather than accumulate.
function clearList({ ctx, day, ops }) {
	const { cal } = ctx;
	ops.push(call({
		method: 'POST', path: '/stock/shoppinglist/clear',
		body: {},
		expect: { status: 204 },
		window: cal.dayWindow(day),
		label: `w${cal.week(day)}: clear the list`
	}));
}

// Monthly, and separate from the weekly fill because these two read against the clock
// rather than against min_stock_amount — under a faked clock that is a different question
// on every simulated month, which is the whole reason the year moves time at all.
function sweepDueAndExpired({ ctx, day, ops }) {
	const { cal } = ctx;
	for (const what of ['add-overdue-products', 'add-expired-products']) {
		ops.push(call({
			method: 'POST', path: `/stock/shoppinglist/${what}`,
			body: {},
			expect: { status: 204 },
			window: cal.dayWindow(day),
			label: `m${cal.month(day)}: ${what}`
		}));
	}
}

module.exports = { fillList, clearList, sweepDueAndExpired };
