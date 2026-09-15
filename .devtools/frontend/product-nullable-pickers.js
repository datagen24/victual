// productform.js's nullable-integer pickers, in a real browser: node product-nullable-pickers.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Regression coverage for issue #159. public/viewjs/productform.js's save handler always sent
// every nullable-integer picker's field even when nothing was picked, and serializeJSON()
// reports an unselected <select> as "" - which PostgreSQL refuses for the integer column
// underneath (invalid input syntax for type integer: ""), arriving at the browser as an opaque
// 400 naming no field. The issue reproduced this directly against POST /api/objects/products
// for four fields (parent_product_id, product_group_id, shopping_location_id,
// default_consume_location_id); the same direct reproduction, done while writing this probe,
// found the identical 400 for the other two nullable-integer pickers the form grew under plan
// 29 (default_refill_location_id_from/_to, migration 0276) - not because they were fixed
// separately, but because they are the same shape and were never distinguished from the four
// the issue named.
//
// A PHP round-trip through /objects/products cannot see this at all: it posts real integers,
// never "". This drives /product/new exactly as a person filling in only the required fields
// would - every picker left at its default blank selection - and then /product/{id} the same
// way on an untouched re-save, so a regression in either the create or the edit branch of the
// save handler (both run the same jsonData transform before branching on Victual.EditMode) is
// caught.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const NULLABLE_PICKER_FIELDS = [
	'parent_product_id',
	'product_group_id',
	'shopping_location_id',
	'default_consume_location_id',
	'default_refill_location_id_from',
	'default_refill_location_id_to'
];

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();
		const productName = 'Bare Create ' + token;

		page.on('pageerror', error => { throw new Error('page error: ' + error.message); });

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		await page.goto(base + '/stockoverview');

		// --- THE BARE CREATE ---------------------------------------------------------------
		//
		// Only the fields every product must have. Every nullable-integer picker - the parent
		// product, its group, its default store, its default consume location, and the two
		// one-tap refill locations - is left at its default blank selection.
		await page.goto(base + '/product/new');
		await page.locator('#name').fill(productName);
		await page.locator('#location_id').selectOption({ index: 1 });
		await page.locator('#qu_id_stock').selectOption({ index: 1 });
		await page.locator('#qu_id_purchase').selectOption({ index: 1 });
		await page.locator('#qu_id_consume').selectOption({ index: 1 });
		await page.locator('#qu_id_price').selectOption({ index: 1 });

		// The status is checked before waiting for the redirect, not alongside it: a 400 never
		// redirects at all, and folding the navigation wait into the same Promise.all would
		// have this hang for the full navigation timeout with no useful message instead of
		// failing immediately on the response that actually explains why.
		const [createResponse] = await Promise.all([
			page.waitForResponse(r => r.url().endsWith('/api/objects/products') && r.request().method() === 'POST'),
			page.locator('#save-product-button').click()
		]);
		if (createResponse.status() !== 200)
		{
			throw new Error('a bare create should succeed but got ' + createResponse.status() + ': ' + await createResponse.text());
		}
		// '**/product/*' also matches the /product/new page we start on, so waiting on that
		// pattern would resolve immediately instead of waiting for the post-save redirect -
		// waitForNavigation waits for the navigation event itself instead.
		await page.waitForNavigation();
		await page.waitForLoadState('load');

		const createdIdMatch = page.url().match(/\/product\/(\d+)$/);
		assert.ok(createdIdMatch, 'the save redirected to the new product\'s edit page (' + page.url() + ')');
		const createdId = createdIdMatch[1];

		const created = (await api('objects/products')).find(p => p.name === productName);
		assert.ok(created, 'the product was created');
		assert.equal(String(created.id), createdId, 'the redirect landed on the created product\'s own id');
		for (const field of NULLABLE_PICKER_FIELDS)
		{
			assert.equal(created[field], null, field + ' is null on a bare create, not "" coerced into an id of 0');
		}

		// --- THE BARE RE-SAVE ---------------------------------------------------------------
		//
		// Reopening and saving again without touching any picker exercises the PUT branch of
		// the same handler - a fix that only patched the create path would still break an
		// ordinary edit, which is exactly how the form is normally revisited.
		await page.goto(base + '/product/' + created.id);
		const [editResponse] = await Promise.all([
			page.waitForResponse(r => r.url().endsWith('/api/objects/products/' + created.id) && r.request().method() === 'PUT'),
			page.locator('#save-product-button').click()
		]);
		if (editResponse.status() !== 204)
		{
			throw new Error('a bare re-save should succeed but got ' + editResponse.status() + ': ' + await editResponse.text());
		}
		// The edit save redirects through the same saveProductPicture() path a create does,
		// back to this exact same URL - waitForURL would resolve immediately against the URL
		// we are already on, so this waits for the navigation event itself instead.
		await page.waitForNavigation();

		const resaved = await api('objects/products/' + created.id);
		for (const field of NULLABLE_PICKER_FIELDS)
		{
			assert.equal(resaved[field], null, field + ' stays null through an untouched edit save');
		}

		console.log('PRODUCT NULLABLE PICKER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
