// Nested product groups in a real browser: node nested-product-groups.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// This is .devtools/frontend/nested-locations.js with the nouns changed, for the same reason
// the PostgreSQL phase is: the parent picker, the path shown in a group dropdown, and the
// delete refusal reaching the page are all things .devtools/pgsql/nested-product-groups-tests.php
// structurally cannot see - it asserts the column, the view, the guards and the API, and this
// asserts that a person can build a tree through the form and act on it. Every name carries a
// per-run token, so a second run against the same instance neither collides with the first
// nor asserts against it.
//
// No S29 payload row here on purpose, unlike nested-locations.js: s29-payload.js's own
// "productgroups" probe (run earlier in the same frontend-security job) already plants a
// payload-named group and asserts the list renders it as text - duplicating that here would
// assert the same property against a table whose pagination and sort order this probe does
// not control, for a property this job's own dedicated probe already owns.
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
		const spicesName = 'Spices ' + token;
		const garlicName = 'Garlic ' + token;
		const freshName = 'Fresh ' + token;

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		// Demo mode auto-logs-in on the first request, group-min-stock.js's own opening move -
		// a fresh browser context carries no session cookie until something sets one.
		await page.goto(base + '/stockoverview');

		// THE TREE, built through the form and its parent picker rather than the API, so what
		// is asserted afterwards is what the form posted.
		await page.goto(base + '/productgroup/new');
		await page.locator('#name').fill(spicesName);
		await page.locator('#save-product-group-button').click();
		await page.waitForURL('**/productgroups');

		const spices = (await api('objects/product_groups')).find(group => group.name === spicesName);
		assert.ok(spices, 'the form created Spices as a root group');

		await page.goto(base + '/productgroup/new');
		await page.locator('#name').fill(garlicName);
		await page.locator('#parent_product_group_id').selectOption({ label: spicesName });
		await page.locator('#save-product-group-button').click();
		await page.waitForURL('**/productgroups');

		const garlic = (await api('objects/product_groups')).find(group => group.name === garlicName);
		assert.ok(garlic, 'the form created Garlic');
		assert.equal(garlic.parent_product_group_id, spices.id, 'Garlic carries the parent picker\'s choice');

		await page.goto(base + '/productgroup/new');
		await page.locator('#name').fill(freshName);
		await page.locator('#parent_product_group_id').selectOption({ label: spicesName + ' / ' + garlicName });
		await page.locator('#save-product-group-button').click();
		await page.waitForURL('**/productgroups');

		const fresh = (await api('objects/product_groups')).find(group => group.name === freshName);
		assert.ok(fresh, 'the form created Fresh, offered by its full path under the parent picker');
		assert.equal(fresh.parent_product_group_id, garlic.id, 'Fresh sits under Garlic');

		// THE PATH IN A DROPDOWN. The product form's group picker is a plain <select> (not a
		// combobox), and it has to offer Fresh by its whole path rather than the bare name -
		// the same reasoning locationform.blade.php's picker exists for.
		await page.goto(base + '/product/new');
		const groupOption = page.locator('#product_group_id option', { hasText: spicesName + ' / ' + garlicName + ' / ' + freshName });
		assert.equal(await groupOption.count(), 1, 'the product form\'s group dropdown offers Fresh by its whole path');

		// THE LIST renders the path column.
		await page.goto(base + '/productgroups');
		const pathCell = page.locator('td', { hasText: spicesName + ' / ' + garlicName + ' / ' + freshName });
		await pathCell.waitFor();
		assert.equal(await pathCell.innerText(), spicesName + ' / ' + garlicName + ' / ' + freshName, 'the list shows Fresh\'s full path');

		// THE DELETE REFUSAL. Garlic has a child (Fresh), so the API's 400 has to reach the
		// page through the shared delete helper (plan 12) rather than the generic "A server
		// error occured" the trigger's own SQLSTATE text would produce.
		await page.goto(base + '/productgroups');
		const deleteButton = page.locator('.product-group-delete-button[data-group-id="' + garlic.id + '"]');
		await deleteButton.waitFor();
		await deleteButton.click();
		await page.locator('.bootbox .btn-success').click();

		const toast = page.locator('#toast-container');
		await toast.waitFor({ timeout: 15000 });
		assert.match(await toast.innerText(), /Product group has child groups/, 'the refusal says what is wrong');

		const stillThere = (await api('objects/product_groups')).find(group => group.id === garlic.id);
		assert.ok(stillThere, 'Garlic is still there after the refused delete');

		console.log('NESTED PRODUCT GROUP BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
