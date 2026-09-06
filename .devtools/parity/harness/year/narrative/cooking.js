'use strict';

// Planning meals and cooking them.
//
// A recipe consume is the one operation whose satisfiability depends on several products at
// once, so the ledger is asked about every position before the operation is written down —
// a recipe that cannot be cooked this week simply is not cooked, which is what a household
// does too.

const { call, CREATED, verifyStock } = require('../ops');
const { intBetween, pick, chance } = require('../rng');

// Sunday: plan the coming week. A mix of the three meal_plan types, because `type` decides
// which of recipe_id / product_id / note is meaningful and a plan of only recipes would
// leave two thirds of the column set untested.
function planWeek({ ctx, day, ops }) {
	const { rng, cal, world, sym } = ctx;
	if (world.recipes.length === 0) return;

	for (let offset = 1; offset <= 5; offset++) {
		const kind = chance(rng, 0.6) ? 'recipe' : (chance(rng, 0.5) ? 'product' : 'note');
		const section = pick(rng, world.mealPlanSections);
		const dayOf = cal.date(day + offset);

		const body = { day: dayOf, type: kind, section_id: `{mealPlanSection:${section.key}}`, done: 0 };
		if (kind === 'recipe') {
			const recipe = pick(rng, world.recipes);
			body.recipe_id = `{recipe:${recipe.key}}`;
			body.recipe_servings = intBetween(rng, 1, 4);
		} else if (kind === 'product') {
			const product = pick(rng, world.products);
			body.product_id = `{product:${product.key}}`;
			body.product_amount = intBetween(rng, 1, 2);
			body.product_qu_id = `{quantityUnit:${product.qu}}`;
		} else {
			body.note = `Leftovers on ${dayOf}`;
		}

		ops.push(call({
			method: 'POST', path: '/objects/meal_plan', body,
			expect: CREATED,
			window: cal.dayWindow(day),
			bind: { [`mealplan:${day}:${offset}`]: 'created_object_id' },
			label: `w${cal.week(day)} plan: ${kind} for ${dayOf}`
		}));
	}
}

// How much of each product a recipe needs, resolved through its nestings.
function requirements(world, recipe, seen = new Set()) {
	if (seen.has(recipe.key)) return new Map();
	seen.add(recipe.key);
	const out = new Map();
	for (const pos of recipe.positions || []) {
		out.set(pos.product, (out.get(pos.product) || 0) + pos.amount);
	}
	for (const nestedKey of recipe.nests || []) {
		const nested = world.recipes.find((r) => r.key === nestedKey);
		if (!nested) continue;
		for (const [k, v] of requirements(world, nested, seen)) {
			out.set(k, (out.get(k) || 0) + v);
		}
	}
	return out;
}

// Read the fulfilment, then cook. The read is recorded because "what does the app think is
// missing" is a derived answer over the whole stock table, which is exactly the kind of
// query an engine change can break without breaking any single booking.
function cook({ ctx, day, ops }) {
	const { rng, cal, world, ledger, sym } = ctx;
	if (world.recipes.length === 0) return;

	const recipe = pick(rng, world.recipes);
	ops.push(call({
		method: 'GET', path: `/recipes/{recipe:${recipe.key}}/fulfillment`,
		expect: { status: 200, kind: 'object' },
		window: cal.dayWindow(day),
		label: `d${day}: fulfilment of ${recipe.name}`
	}));

	const needs = requirements(world, recipe);
	const satisfiable = [...needs].every(([productKey, amount]) => {
		const product = world.products.find((p) => p.key === productKey);
		return product && ledger.amountOf(sym.product(productKey)) >= amount;
	});

	if (!satisfiable) {
		// Not cooked, so put what is missing on the list instead — the same thing the
		// household would do, and it exercises the endpoint that reads the shortfall.
		ops.push(call({
			method: 'POST', path: `/recipes/{recipe:${recipe.key}}/add-not-fulfilled-products-to-shoppinglist`,
			body: {},
			expect: { status: 204 },
			window: cal.dayWindow(day),
			label: `d${day}: shortfall of ${recipe.name} to the list`
		}));
		return;
	}

	ops.push(call({
		method: 'POST', path: `/recipes/{recipe:${recipe.key}}/consume`,
		body: {},
		// 204, not a booking array: RecipesApiController:46-48 documents it, and an
		// operation that expected rows here would stop the run on its first supper.
		expect: { status: 204 },
		window: cal.dayWindow(day),
		ledger: { kind: 'cook', recipe: recipe.key },
		label: `d${day}: cook ${recipe.name}`
	}));

	// **The consume answers 204, so the only evidence is the state it left.**
	//
	// There is no booking array to check `rowsSum` against, and until this existed a recipe
	// that failed to consume its *nested* recipe's ingredients was caught only in aggregate
	// at the next monthly checkpoint, attributed to a product rather than to the recipe.
	// `requirements()` already resolves nesting, so the model knows exactly which products a
	// nested recipe should have drawn on and by how much.
	const affected = [];
	for (const [productKey, amount] of needs) {
		const lotsBefore = ledger.ordered(sym.product(productKey)).length;
		ledger.consume({ productId: sym.product(productKey), amount });
		affected.push({ productKey, amount, lotsBefore });
	}
	for (const { productKey, amount, lotsBefore } of affected) {
		const product = world.products.find((p) => p.key === productKey);
		if (!product) continue;
		ops.push(verifyStock({
			productKey,
			amount: ledger.amountOf(sym.product(productKey)),
			window: cal.dayWindow(day),
			label: `d${day}: ${recipe.name} should have taken ${amount} ${product.name}, ` +
				`leaving ${ledger.amountOf(sym.product(productKey))}`
		}));
		ctx.verifyLotsAfter(ops, product, day, `cook ${recipe.name}`, lotsBefore);
	}
}

module.exports = { planWeek, cook, requirements };
