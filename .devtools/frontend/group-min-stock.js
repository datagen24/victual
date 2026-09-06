// Product group minimum stock in a real browser: node group-min-stock.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Two things here cannot be asserted from PHP, which is why this file exists rather than
// another case in .devtools/pgsql/group-min-stock-tests.php.
//
// The first is the form. A PHP round-trip through /objects/product_groups proves the column
// accepts and returns a value; it says nothing about whether the field is on the page, posts
// under the right name, or renders the stored value back into the input when the form is
// reopened. Every one of those can break while the API round-trip stays green - that is what
// makes it a different assertion rather than the same one twice. The value is fractional on
// purpose: a numberpicker whose step or decimals are wrong silently rounds 2.5, and an
// integer-typed column would too, so this is the same question the PHP phase asks of the
// column asked again of the widget.
//
// The second is the short-group list and its action. It is built from nodes with .text()
// rather than concatenated into .html() (AGENTS.md, plan 21), it filters the table on click,
// and the row it filters to is one the page only carries because the controller was widened
// to include out-of-stock members of short groups. That last part is the whole reason the
// action is worth anything, and it is invisible to anything that does not drive the page.
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
		const groupName = 'Min Stock Group ' + token;
		const productName = 'Min Stock Product ' + token;

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

		// THE FORM. Created through the UI, not the API, so that what is asserted afterwards is
		// what the form posted.
		await page.goto(base + '/productgroup/new');
		await page.locator('#name').fill(groupName);
		await page.locator('#min_stock_amount').fill('2.5');
		await page.locator('#save-product-group-button').click();
		await page.waitForURL('**/productgroups');

		const created = (await api('objects/product_groups')).find(group => group.name === groupName);
		assert.ok(created, 'the form created the group');
		assert.equal(Number(created.min_stock_amount), 2.5, 'the form saved the fractional minimum');

		// Reopened, and the stored value is read back into the input. A form that saves and
		// then shows 0 on the next visit is the defect this line exists for.
		await page.goto(base + '/productgroup/' + created.id);
		assert.equal(Number(await page.locator('#min_stock_amount').inputValue()), 2.5, 'the form reloads the stored minimum');

		// A member with no stock and no minimum of its own: the row the overview's default
		// filter drops, and the one buying would fix.
		const product = await api('objects/products', 'POST', {
			name: productName,
			location_id: (await api('objects/locations'))[0].id,
			qu_id_purchase: (await api('objects/quantity_units'))[0].id,
			qu_id_stock: (await api('objects/quantity_units'))[0].id,
			product_group_id: created.id,
			min_stock_amount: 0
		});

		// THE LIST. Named groups, not a count.
		await page.goto(base + '/stockoverview');
		const entry = page.locator('.missing-product-group-button', { hasText: groupName });
		await entry.waitFor();
		assert.equal(await entry.innerText(), groupName, 'the short group is named');
		assert.ok((await page.locator('#info-missing-product-groups').innerText()).length > 0, 'the counter is shown');

		// THE ROW. Present at all only because StockController::Overview() includes active
		// members of short groups; without that widening this locator finds nothing.
		const row = page.locator('#product-' + product.created_object_id + '-row');
		assert.equal(await row.count(), 1, 'the overview carries the short group\'s out-of-stock member');

		// THE ACTION. Clicking the group filters the table to it. A location filter is set
		// first, because a zero-stock row has an empty hidden location cell: if the click did
		// not clear the other filters, this row would be filtered straight back out and the
		// list would be an instruction the page refuses to carry out.
		await page.locator('#location-filter').selectOption({ index: 1 });
		await entry.click();
		await page.locator('#product-group-filter').filter({ has: page.locator('option') }).waitFor();
		assert.equal(await page.locator('#product-group-filter').inputValue(), groupName, 'the click applied the group filter');
		assert.equal(await page.locator('#location-filter').inputValue(), 'all', 'the click cleared the location filter');
		await row.waitFor({ state: 'visible' });

		console.log('PRODUCT GROUP MIN STOCK BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
