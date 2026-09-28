// Issue #591 (#487 remediation): which of the meal plan's consume / add-missing / product-
// consume buttons a real browser renders for CHILD, GUEST and a fully-granted user, plus a
// regression check for the week consume button's missing data-mealplan-entry-id (found while
// fixing #591 round 2).
//
//   node mealplan-permissions.js --child-url http://127.0.0.1:8090 \
//                                 --guest-url http://127.0.0.1:8091 \
//                                 --full-url  http://127.0.0.1:8092
//
// Each URL is its own disposable instance (VICTUAL_MODE=dev, a fresh, empty database) -
// never the shared demo instance the other probes in this job drive, because demo/dev mode
// has exactly one identity for every request (SessionService::GetDefaultUser(), the lowest
// user id) and PUT /api/users/{id}/permissions refuses granting anything the caller does not
// already hold (User::CheckMayGrant()) - so once that one identity is reduced to CHILD's or
// GUEST's permission set, it can never be raised back up through the API. Fixtures are
// therefore seeded first, while the seeded instance's own bootstrap administrator still holds
// every permission, and the identity is downgraded to the target shape exactly once, last.
//
// public/viewjs/mealplan.js's gates read Victual.UserPermissions, which is this same acting
// identity's resolved permission set (views/layout/default.blade.php via User::PermissionList())
// - reducing the identity to a role's exact grant set and reloading the page is what actually
// exercises those gates in a real browser, the same way a user who really held only that grant
// set would see the page.
//
// mayConsumeMealPlanRecipe()/mayAddMealPlanRecipeToShoppingList()/mayConsumeMealPlanProduct()
// (mealplan.js) never differ between CHILD and GUEST on any of the three gates this probe
// checks (both lack RECIPES_MEALPLAN and RECIPES; GUEST additionally lacks STOCK_CONSUME and
// SHOPPINGLIST_ITEMS_ADD), so both are asserted "every button hidden" - CHILD is the sharper
// regression check: on unfixed mealplan.js, CHILD's RECIPES_VIEW + STOCK_CONSUME +
// SHOPPINGLIST_ITEMS_ADD were already enough to see all three buttons (the exact defect issue
// #591 reports), while GUEST additionally shows the product-consume button used to render with
// no permission gate at all, regardless of role.

const { chromium } = require('playwright');
const assert = require('node:assert/strict');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const CHILD_URL = arg('child-url', 'http://127.0.0.1:8090').replace(/\/$/, '');
const GUEST_URL = arg('guest-url', 'http://127.0.0.1:8091').replace(/\/$/, '');
const FULL_URL = arg('full-url', 'http://127.0.0.1:8092').replace(/\/$/, '');

// Pinned so every shape's fixtures land in the same meal plan week - each shape is its own
// database, so there is no cross-shape collision.
const DAY = '2026-01-12';

// CHILD and GUEST straight from db/pgsql/roles-seed.sql. "full" is not a built-in role: it is
// every permission the three gates under test actually check (RECIPES_VIEW, STOCK_CONSUME,
// STOCK_PURCHASE, RECIPES_MEALPLAN, RECIPES, SHOPPINGLIST_ITEMS_ADD), plus MEALPLAN_VIEW (the
// page itself, RecipesController::MealPlan()) and STOCK_VIEW (so the product-consume button
// renders enabled rather than merely present-but-disabled for "stock state unknown" -
// mealplan.js's own stockStateKnown, issues #594/#599).
const SHAPES = {
	child: {
		url: CHILD_URL,
		permissions: ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_OPEN', 'SHOPPINGLIST_VIEW', 'SHOPPINGLIST_ITEMS_ADD',
			'CHORES_VIEW', 'CHORE_TRACK_EXECUTION', 'TASKS_VIEW', 'TASKS_MARK_COMPLETED', 'RECIPES_VIEW',
			'MEALPLAN_VIEW', 'CALENDAR', 'USERS_EDIT_SELF'],
		expect: { recipeConsume: 'hidden', addMissing: 'hidden', productConsume: 'hidden' }
	},
	guest: {
		url: GUEST_URL,
		permissions: ['STOCK_VIEW', 'RECIPES_VIEW', 'MEALPLAN_VIEW'],
		expect: { recipeConsume: 'hidden', addMissing: 'hidden', productConsume: 'hidden' }
	},
	full: {
		url: FULL_URL,
		permissions: ['MEALPLAN_VIEW', 'RECIPES_VIEW', 'STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_PURCHASE',
			'RECIPES_MEALPLAN', 'RECIPES', 'SHOPPINGLIST_ITEMS_ADD'],
		expect: { recipeConsume: 'enabled', addMissing: 'enabled', productConsume: 'enabled' }
	}
};

function api(page, path, method = 'GET', body)
{
	return page.evaluate(async ({ path, method, body }) =>
	{
		const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
		if (!response.ok) throw new Error(method + ' ' + path + ' -> ' + response.status + ': ' + await response.text());
		return response.status === 204 ? null : response.json();
	}, { path, method, body });
}

/**
 * Seeds one recipe (one ingredient, deliberately left with NO stock) and one product entry
 * (its own product, fully in stock) for DAY, as whichever identity is currently acting - the
 * seeded bootstrap administrator, before it is ever downgraded.
 *
 * The ingredient is left unstocked on purpose: recipes_resolved.need_fulfilled_with_shopping_list
 * (db/pgsql/baseline/05_views_l3.sql) is what recipeOrderMissingButtonDisabledClasses reads
 * (public/viewjs/mealplan.js) to decide the add-missing button's own "disabled" class - stocking
 * the ingredient enough to cover the recipe's need made that flag 1 (need already fulfilled) for
 * every shape, including "full", where this probe wants to see the button rendered *enabled* to
 * prove the permission gate is what is being tested rather than this unrelated stock-state flag.
 * RecipesService::ConsumeRecipe() consumes only ingredients whose stock_amount is greater than
 * zero, so an unstocked ingredient is silently skipped rather than refused - the week button
 * click-through check below still succeeds with nothing to consume for it.
 */
async function seedFixtures(page, tag)
{
	const qu = await api(page, 'objects/quantity_units', 'POST', { name: 'Probe Piece ' + tag, name_plural: 'Probe Pieces ' + tag });
	const location = await api(page, 'objects/locations', 'POST', { name: 'Probe Location ' + tag });
	const ingredient = await api(page, 'objects/products', 'POST', {
		name: 'Probe Ingredient ' + tag, location_id: location.created_object_id,
		qu_id_purchase: qu.created_object_id, qu_id_stock: qu.created_object_id,
		qu_id_consume: qu.created_object_id, qu_id_price: qu.created_object_id
	});
	const mealProduct = await api(page, 'objects/products', 'POST', {
		name: 'Probe Meal Product ' + tag, location_id: location.created_object_id,
		qu_id_purchase: qu.created_object_id, qu_id_stock: qu.created_object_id,
		qu_id_consume: qu.created_object_id, qu_id_price: qu.created_object_id
	});
	await api(page, 'stock/products/' + mealProduct.created_object_id + '/add', 'POST', { amount: 10 });

	const recipe = await api(page, 'objects/recipes', 'POST', { name: 'Probe Recipe ' + tag, base_servings: 1 });
	await api(page, 'objects/recipes_pos', 'POST', { recipe_id: recipe.created_object_id, product_id: ingredient.created_object_id, amount: 1, qu_id: qu.created_object_id });

	const recipeEntry = await api(page, 'objects/meal_plan', 'POST', { day: DAY, type: 'recipe', recipe_id: recipe.created_object_id, recipe_servings: 1 });
	const productEntry = await api(page, 'objects/meal_plan', 'POST', { day: DAY, type: 'product', product_id: mealProduct.created_object_id, product_amount: 1 });

	return { recipeEntryId: recipeEntry.created_object_id, productEntryId: productEntry.created_object_id };
}

/** Replaces the acting identity's own permission set - see the header comment on why this is a one-way trip. */
async function downgradeTo(page, permissionNames)
{
	const me = await api(page, 'user');
	const user = Array.isArray(me) ? me[0] : me;
	const rows = await api(page, 'users/' + user.id + '/permissions');
	const idByName = {};
	rows.forEach(row => { idByName[row.permission_name] = row.permission_id; });

	const ids = permissionNames.map(name =>
	{
		assert.ok(Object.prototype.hasOwnProperty.call(idByName, name), 'unknown permission name: ' + name);
		return idByName[name];
	});
	await api(page, 'users/' + user.id + '/permissions', 'PUT', { permissions: ids });
}

/** 'hidden' (locator matches nothing), 'disabled' (matches, class contains "disabled") or 'enabled'. */
async function buttonState(locator)
{
	const count = await locator.count();
	if (count === 0)
	{
		return 'hidden';
	}

	const classAttr = (await locator.first().getAttribute('class')) || '';
	return classAttr.split(/\s+/).includes('disabled') ? 'disabled' : 'enabled';
}

async function checkShape(browser, shapeName, shape)
{
	const page = await browser.newPage();
	// A page load first: the browser needs the auto-logged-in dev-mode session's cookie
	// before fetch() from this page can use it - the same reason userform-password.js and
	// roles.js load a page before their own api() calls.
	await page.goto(shape.url + '/about');

	const { recipeEntryId, productEntryId } = await seedFixtures(page, shapeName);
	await downgradeTo(page, shape.permissions);

	await page.goto(shape.url + '/mealplan?start=' + DAY + '&days=0');
	// mealplan-entry-done/undone-button renders unconditionally per entry (never permission
	// gated), so waiting for both of this day's two entries to have rendered one is a
	// reliable "the page finished building this day's cards" signal that does not itself
	// depend on any of the gates under test.
	await page.waitForFunction(() => document.querySelectorAll('.mealplan-entry-done-button, .mealplan-entry-undone-button').length >= 2);

	// [data-mealplan-entry-id] on .recipe-consume-button and [data-mealplan-servings] on
	// .recipe-order-missing-button both isolate the per-entry button from the week
	// aggregate's own copy of the same class, which carries neither attribute
	// (public/viewjs/mealplan.js's weekRecipeConsumeButtonHtml/weekRecipeOrderMissingButtonHtml).
	const recipeConsume = page.locator('a.recipe-consume-button[data-mealplan-entry-id="' + recipeEntryId + '"]');
	const addMissing = page.locator('a.recipe-order-missing-button[data-mealplan-servings]');
	const productConsume = page.locator('a.product-consume-button[data-mealplan-entry-id="' + productEntryId + '"]');

	const actual = {
		recipeConsume: await buttonState(recipeConsume),
		addMissing: await buttonState(addMissing),
		productConsume: await buttonState(productConsume)
	};

	assert.deepEqual(actual, shape.expect, shapeName + ': button state mismatch, got ' + JSON.stringify(actual) + ', want ' + JSON.stringify(shape.expect));
	console.log(shapeName + ': ' + JSON.stringify(actual) + ' matches expectations');

	// Coordinator round 2 fold-in, item 2, "full" shape only: the week button (the one
	// .recipe-consume-button with no data-mealplan-entry-id) used to PUT
	// objects/meal_plan/undefined after a successful consume - attr() reads back `undefined`
	// for a missing attribute, and string concatenation stringifies that into the URL. Only
	// exercised for "full", the one shape whose gate lets the button render at all.
	if (shapeName === 'full')
	{
		const weekButton = page.locator('a.recipe-consume-button:not([data-mealplan-entry-id])');
		assert.equal(await weekButton.count(), 1, 'the week consume button should have rendered once for the pinned week');

		const requestUrls = [];
		function record(request) { requestUrls.push(request.url()); }
		page.on('request', record);

		await weekButton.click();
		await page.getByRole('button', { name: 'Yes', exact: true }).click();
		// A successful consume reloads the page (navigation); a refusal along the way would
		// not - either way, give every request this click could have made time to fire before
		// reading requestUrls back.
		await page.waitForLoadState('load').catch(() => {});
		await page.waitForTimeout(500);
		page.off('request', record);

		const undefinedPut = requestUrls.find(url => url.includes('/objects/meal_plan/undefined'));
		assert.equal(undefinedPut, undefined, 'the week consume button must never PUT to .../meal_plan/undefined: ' + JSON.stringify(requestUrls));
		console.log('full: week consume button did not PUT to .../meal_plan/undefined');
	}

	await page.close();
}

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try
	{
		for (const [shapeName, shape] of Object.entries(SHAPES))
		{
			await checkShape(browser, shapeName, shape);
		}
		console.log('MEALPLAN PERMISSION CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
