// ADR-0034 gate 2. Run against a disposable dev/demo instance.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36);
		await page.goto(base + '/stockoverview');
		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, {
					method, headers: { 'Content-Type': 'application/json' },
					body: body === undefined ? undefined : JSON.stringify(body)
				});
				if (!response.ok) throw new Error(path + ': ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}
		const setting = 'user/settings/stock_overview_show_all_out_of_stock_products';
		await api(setting, 'PUT', { value: false });
		async function group(name, parent = null, minimum = 0, active = 1)
		{
			return String((await api('objects/product_groups', 'POST', {
				name, parent_product_group_id: parent, min_stock_amount: minimum, active
			})).created_object_id);
		}
		const rootAName = 'ADR34 A ' + token;
		const rootBName = 'ADR34 B ' + token;
		const sharedName = 'Same group ' + token;
		const rootA = await group(rootAName, null, 10);
		const rootB = await group(rootBName, null, 10);
		const childA = await group(sharedName, rootA, 10);
		const childB = await group(sharedName, rootB, 10);
		const intermediate = await group('Inactive ' + token, childA, 10, 0);
		const leaf = await group('Leaf ' + token, intermediate);
		const unrelated = await group('Unrelated ' + token);
		const location = (await api('objects/locations'))[0].id;
		const unit = (await api('objects/quantity_units'))[0].id;
		async function product(groupId, active = 1)
		{
			return String((await api('objects/products', 'POST', {
				name: 'ADR34 product ' + groupId + ' ' + active + ' ' + token,
				location_id: location, qu_id_purchase: unit, qu_id_stock: unit,
				product_group_id: groupId, min_stock_amount: 0, active
			})).created_object_id);
		}
		const descendant = await product(leaf);
		const otherBranch = await product(childB);
		const excluded = await product(unrelated);
		const inactive = await product(leaf, 0);
		await page.goto(base + '/stockoverview');
		await page.waitForFunction(() => typeof stockOverviewTable !== 'undefined');
		await page.evaluate(() => stockOverviewTable.page.len(-1).draw());
		const row = id => page.locator('#product-' + id + '-row');
		const button = id => page.locator('.missing-product-group-button[data-product-group-id="' + id + '"]');
		await page.locator(".missing-product-group-button").first().waitFor();
		assert.equal(await row(descendant).count(), 1, 'short ancestor carries its zero-stock descendant');
		assert.equal(await row(otherBranch).count(), 1, 'other short branch is present before filtering');
		assert.equal(await row(excluded).count(), 0, 'unrelated zero-stock product stays excluded');
		assert.equal(await row(inactive).count(), 0, 'inactive product stays excluded');
		assert.equal(await button(intermediate).count(), 0, 'inactive intermediate reports no shortfall');
		for (const [id, path] of [[childA, rootAName + ' / ' + sharedName], [childB, rootBName + ' / ' + sharedName]])
		{
			assert.equal(await page.locator('#product-group-filter option[value="' + id + '"]').textContent(), path);
			assert.equal(await button(id).textContent(), path, 'short buttons distinguish same-named paths');
		}
		await page.locator('#location-filter').selectOption(String(location));
		await button(rootA).click();
		assert.equal(await page.locator('#product-group-filter').inputValue(), rootA);
		assert.equal(await page.locator('#location-filter').inputValue(), 'all');
		assert.ok(await row(descendant).isVisible(), 'ancestor click reveals descendant through inactive intermediate');
		assert.equal(await row(otherBranch).isVisible(), false, 'ancestor excludes unrelated branch');
		await page.locator('#product-group-filter').selectOption(childB);
		assert.ok(await row(otherBranch).isVisible(), 'second same-named group filters independently');
		assert.equal(await row(descendant).isVisible(), false);
		await button(childA).click();
		assert.ok(await row(descendant).isVisible(), 'first same-named group filters independently');
		assert.equal(await row(otherBranch).isVisible(), false);
		console.log('ADR-0034 DESCENDANT GROUP BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });
