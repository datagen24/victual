'use strict';

// What the year reads, and when.
//
// **Paginated reads name their own ordering key.** `order=id` is not universally valid:
// several views carry a constant dummy id — products_average_price selects `1 AS id` and
// products_current_price `-1` (db/pgsql/baseline/04_views_l1a.sql) — so ordering by it is
// no ordering at all. Views with a dummy id are bounded and read whole; only genuinely
// growing tables are paged, and each says which column actually identifies a row.
//
// Gaps in that key are legitimate — deletions, merges and sequence allocation all produce
// them — so the completeness assertion in the runner is "strictly increasing and unique,
// totalling an independently read count", never "contiguous".

const { call } = require('./ops');

const PAGE = 200;

// Growing tables: paged, with a real key.
const PAGED = [
	{ path: '/objects/stock_log', key: 'id', label: 'stock log' },
	{ path: '/objects/chores_log', key: 'id', label: 'chore log' },
	{ path: '/objects/battery_charge_cycles', key: 'id', label: 'battery charge cycles' },
	{ path: '/objects/meal_plan', key: 'id', label: 'meal plan' }
];

// Bounded reads: whole, because their cardinality is part of what is being checked.
const WHOLE = [
	{ path: '/stock', label: 'stock' },
	{ path: '/stock/volatile', label: 'volatile (due, overdue, expired)' },
	{ path: '/objects/shopping_list?order=id:asc', label: 'shopping list' },
	{ path: '/chores', label: 'chores' },
	{ path: '/batteries', label: 'batteries' },
	{ path: '/tasks', label: 'tasks' },
	{ path: '/objects/products_average_price', label: 'average price (dummy id — read whole)' },
	{ path: '/objects/products_last_purchased', label: 'last purchased' },
	{ path: '/objects/stock_current_locations', label: 'stock by location' },
	{ path: '/recipes/fulfillment', label: 'recipe fulfilment' }
];

function read(ctx, day, path, label, tier) {
	return call({
		method: 'GET', path,
		expect: { status: 200 },
		window: ctx.cal.dayWindow(day),
		label: `[${tier}] ${label}`
	});
}

// Weekly: cheap, and enough to see the shape of the week.
function light({ ctx, day, ops }) {
	for (const spec of WHOLE.slice(0, 6)) ops.push(read(ctx, day, spec.path, spec.label, `w${ctx.cal.week(day)}`));
}

// Monthly: everything bounded, plus a tail slice of each growing table. A tail slice rather
// than the whole log because stock_log passes four thousand rows in a year and the question
// at a checkpoint is "what has happened lately", not "replay the year".
function medium({ ctx, day, ops }) {
	const tier = `m${ctx.cal.month(day)}`;
	for (const spec of WHOLE) ops.push(read(ctx, day, spec.path, spec.label, tier));
	for (const spec of PAGED) {
		ops.push(read(ctx, day, `${spec.path}?order=${spec.key}:desc&limit=25`, `${spec.label} (latest 25)`, tier));
	}
	for (const product of ctx.world.products.slice(0, 5)) {
		ops.push(read(ctx, day, `/stock/products/{product:${product.key}}`, `details of ${product.name}`, tier));
	}
}

// Quarterly: every product's detail and price history, which is where a year of purchases
// becomes visible as a series rather than as a number.
function quarterly({ ctx, day, ops }) {
	const tier = `q${Math.floor(ctx.cal.month(day) / 3)}`;
	medium({ ctx, day, ops });
	for (const product of ctx.world.products) {
		ops.push(read(ctx, day, `/stock/products/{product:${product.key}}/price-history`, `price history of ${product.name}`, tier));
	}
}

// Year end: the whole journal, paged, plus everything else. This is the read that only
// means anything because the clock moved — on a real clock every row would carry the same
// minute and the ordering would be an artefact of how fast the suite ran.
function yearEnd({ ctx, day, ops, expectedRows }) {
	const tier = 'year-end';
	quarterly({ ctx, day, ops });
	for (const spec of PAGED) {
		const pages = Math.max(1, Math.ceil((expectedRows[spec.label] || PAGE) / PAGE));
		for (let p = 0; p < pages; p++) {
			ops.push(read(ctx, day,
				`${spec.path}?order=${spec.key}:asc&limit=${PAGE}&offset=${p * PAGE}`,
				`${spec.label} page ${p + 1}/${pages}`, tier));
		}
	}
}

module.exports = { light, medium, quarterly, yearEnd, PAGED, WHOLE, PAGE };
