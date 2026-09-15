// Directed product substitution in a real browser: node product-substitutions.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// The product-form table's rendering (the direction sentence, the link to the other product)
// and the delete refusal/confirm flow reaching the page are things
// .devtools/pgsql/product-substitutions-tests.php structurally cannot see - it asserts the
// table, the view, the guards and the API, and this asserts that a person looking at a
// product's edit page sees the right thing and can act on it.
//
// The "Add" dialog's product picker (a bootstrap-combobox typeahead, not a plain <select>) is
// deliberately not driven here. Plan 30's own probe found, in CI rather than locally, that a
// speculative interaction written without a way to see the widget actually behave is worse
// than no coverage - the payload-rendering check that session added blind was removed rather
// than debugged blind a second time. No probe in this tree yet drives that combobox, and this
// sandbox cannot boot the app to learn its behaviour first (PHP 8.5.0 is required; this
// environment carries 8.4.19), so the edge here is created through the API as fixture data,
// the same way nested-product-groups.js treats its own tree as setup for the parts of the
// page it does assert against. The create path itself is already fully exercised at the API
// layer by the PostgreSQL suite's case 8.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();
		const wholeBeansName = 'Whole Beans ' + token;
		const groundCoffeeName = 'Ground Coffee ' + token;

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		// Demo mode auto-logs-in on the first request, nested-product-groups.js's own opening
		// move - a fresh browser context carries no session cookie until something sets one.
		await page.goto(base + '/stockoverview');

		const wholeBeans = await api('objects/products', 'POST', { name: wholeBeansName, location_id: 1, qu_id_purchase: 2, qu_id_stock: 2, min_stock_amount: 0, default_best_before_days: 0 });
		const groundCoffee = await api('objects/products', 'POST', { name: groundCoffeeName, location_id: 1, qu_id_purchase: 2, qu_id_stock: 2, min_stock_amount: 0, default_best_before_days: 0 });
		const edge = await api('objects/product_substitutions', 'POST', { from_product_id: wholeBeans.created_object_id, to_product_id: groundCoffee.created_object_id });

		// THE TABLE RENDERS THE EDGE, from each endpoint's own page, worded for which side of
		// the edge that page's product is on.
		await page.goto(base + '/product/' + groundCoffee.created_object_id);
		const fromOtherSide = page.locator('#product-substitution-table td', { hasText: 'The other one can be used instead of this' });
		await fromOtherSide.waitFor();
		const beansLink = page.locator('#product-substitution-table a', { hasText: wholeBeansName });
		assert.equal(await beansLink.count(), 1, 'Ground Coffee\'s page offers Whole Beans as the substitute, linked by name');

		await page.goto(base + '/product/' + wholeBeans.created_object_id);
		const fromThisSide = page.locator('#product-substitution-table td', { hasText: 'This can be used instead of the other one' });
		await fromThisSide.waitFor();
		const coffeeLink = page.locator('#product-substitution-table a', { hasText: groundCoffeeName });
		assert.equal(await coffeeLink.count(), 1, 'Whole Beans\' own page states the direction the other way round, linked to Ground Coffee');

		// THE DELETE, from Whole Beans' page - the same confirm-then-toast-free-reload flow
		// the barcode and QU-conversion tables already use, and nested-product-groups.js's own
		// case 6 drives the same way for its delete refusal.
		const deleteButton = page.locator('.product-substitution-delete-button[data-product-substitution-id="' + edge.created_object_id + '"]');
		await deleteButton.waitFor();
		await deleteButton.click();
		await page.locator('.bootbox .btn-success').click();
		await page.waitForLoadState('load');

		const rowGone = await api('objects/product_substitutions/' + edge.created_object_id).then(() => true).catch(() => false);
		assert.equal(rowGone, false, 'the edge is gone after the confirmed delete');
		assert.equal(await page.locator('#product-substitution-table').isVisible(), true, 'the product form itself is still there - the delete reloaded rather than navigating away');

		console.log('PRODUCT SUBSTITUTION BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
