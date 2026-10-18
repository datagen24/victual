// The stock overview and the product card tell an unknown stock value from a known zero, in a
// real browser: node price-semantics.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Issue #774, ADR-0045 option B. The API sends the known part of a stock value with a
// value_incomplete / stock_value_incomplete flag; PHPUnit proves that, but cannot see whether
// views/stockoverview.blade.php, public/viewjs/stockoverview.js and
// public/viewjs/components/productcard.js still show it, because PHP never runs public/viewjs.
// One product is bought without a price (unknown) and one at 0 (free): the overview marks the
// first value as incomplete and not the second, shows the free product's last price instead of
// hiding it as an empty value, and the product card does the same.
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

		page.on('pageerror', error => { throw new Error('page error: ' + error.message); });

		/** Calls the API as the logged-in demo session; returns the parsed body, or null for 204. */
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

		const location = (await api('objects/locations'))[0].id;
		const unit = (await api('objects/quantity_units'))[0].id;
		/** Creates a product named name plus this run's token; returns its id. */
		const product = async (name) => (await api('objects/products', 'POST', { name: name + ' ' + token, location_id: location, qu_id_purchase: unit, qu_id_stock: unit, qu_id_consume: unit, qu_id_price: unit })).created_object_id;

		const unpriced = await product('Price semantics unpriced');
		const free = await product('Price semantics free');
		await api('stock/products/' + unpriced + '/add', 'POST', { amount: 2 });
		await api('stock/products/' + free + '/add', 'POST', { amount: 2, price: 0 });

		const details = await api('stock/products/' + unpriced);
		assert.equal(details.stock_value_incomplete, true, 'the API flags the unpriced product');
		assert.equal((await api('stock/products/' + free)).stock_value_incomplete, false);

		await page.goto(base + '/stockoverview');
		// The value and price columns are hidden by default, and DataTables detaches a hidden
		// column's cells from the document, so they are looked up among the table's own cell
		// nodes rather than with getElementById
		const inCells = async (selector) => page.evaluate((selector) =>
		{
			for (const cell of $('#stock-overview-table').DataTable().cells().nodes().toArray())
			{
				const found = cell.querySelector(selector);
				if (found !== null) return { hidden: found.classList.contains('d-none'), html: found.outerHTML };
			}
			return null;
		}, selector);
		/** Whether the overview shows product id's incomplete-value icon; null when it is not rendered. */
		const marked = async (id) => { const icon = await inCells('#product-' + id + '-value-incomplete'); return icon === null ? null : !icon.hidden; };
		assert.equal(await marked(unpriced), true, 'the overview marks the unpriced value as incomplete');
		assert.equal(await marked(free), false, 'and not the free one');

		const lastPriceShown = await page.evaluate((id) =>
		{
			const table = $('#stock-overview-table').DataTable();
			const row = table.row('#product-' + id + '-row');
			return row.any() && table.cells(row.index(), '*').nodes().toArray()
				.some(cell => cell.querySelector('span[data-toggle="tooltip"] .locale-number-currency') !== null);
		}, free);
		assert.equal(lastPriceShown, true, 'a last price of 0 is shown, not hidden as empty');

		// Refresh() returns before its request completes, so the name and the icon are read in one
		// wait: both are written by the same callback
		const cardMarked = async (id, name) =>
		{
			await page.evaluate((id) => Victual.Components.ProductCard.Refresh(id), id);
			const handle = await page.waitForFunction((name) =>
			{
				if (document.getElementById('productcard-product-name')?.textContent !== name) return null;
				return { marked: !document.getElementById('productcard-product-stock-value-incomplete').classList.contains('d-none') };
			}, name);
			return (await handle.jsonValue()).marked;
		};
		assert.equal(await cardMarked(unpriced, 'Price semantics unpriced ' + token), true, 'the product card marks the unpriced value');
		assert.equal(await cardMarked(free, 'Price semantics free ' + token), false, 'and not the free one');

		console.log('price semantics: the overview and the product card tell an unknown value from a known zero');
	}
	finally
	{
		await browser.close();
	}
})().catch(error =>
{
	console.error(error);
	process.exit(1);
});
