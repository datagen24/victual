// The stock entry edit form sends a price only when the user changed it, in a real
// browser: node stockentry-price.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Issue #774, ADR-0045 gate 1. A stored 0 marked as never entered (legacy_zero_price) is
// shown in the form as 0. The server keeps the marker only when the edit leaves the price
// out; a supplied 0 is a known zero. So a save that changes only the note must not send
// the price back, and one that changes the price, to 0 included, must send it. PHPUnit
// covers the server half (LegacyZeroPricePropagationTest) but cannot see what
// public/viewjs/stockentryform.js puts in the request.
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

		await page.goto(base + '/about');

		const location = (await api('objects/locations'))[0].id;
		const unit = (await api('objects/quantity_units'))[0].id;
		const product = (await api('objects/products', 'POST', { name: 'Stock entry price ' + token, location_id: location, qu_id_purchase: unit, qu_id_stock: unit, qu_id_consume: unit, qu_id_price: unit })).created_object_id;
		/** Purchases one unit at $price; returns the new stock entry id. */
		const entry = async (price) =>
		{
			await api('stock/products/' + product + '/add', 'POST', { amount: 1, price });
			const entries = await api('stock/products/' + product + '/entries');
			return entries[entries.length - 1].id;
		};

		// Opens the edit form, applies the edit and returns the body of the PUT it sends
		async function save(entryId, edit)
		{
			await page.goto(base + '/stockentry/' + entryId + '?embedded');
			await page.waitForFunction(() =>
			{
				const form = document.querySelector('#stockentry-form');
				return !!form && form.checkValidity();
			}, { timeout: 15000 });
			await edit();
			const [request] = await Promise.all([
				page.waitForRequest(request => request.method() === 'PUT' && /\/api\/stock\/entry\/\d+$/.test(new URL(request.url()).pathname)),
				page.locator('#save-stockentry-button').click()
			]);
			return JSON.parse(request.postData());
		}
		/** Whether a request body carries a price key at all. */
		const sendsPrice = body => Object.prototype.hasOwnProperty.call(body, 'price');

		const zero = await entry(0);
		const noteOnly = await save(zero, () => page.locator('#note').fill('note only ' + token));
		assert.equal(sendsPrice(noteOnly), false, 'a save that leaves a 0 price alone omits it: ' + JSON.stringify(noteOnly));

		const priced = await entry(2.5);
		const unchanged = await save(priced, () => page.locator('#note').fill('unchanged ' + token));
		assert.equal(sendsPrice(unchanged), false, 'and so does one that leaves any other price alone');

		const madeFree = await save(priced, () => page.locator('#price').fill('0'));
		assert.equal(sendsPrice(madeFree), true, 'a price changed to 0 is sent');
		assert.equal(Number(madeFree.price), 0);

		const zeroAgain = await entry(0);
		const repriced = await save(zeroAgain, () => page.locator('#price').fill('1.25'));
		assert.equal(Number(repriced.price), 1.25, 'a 0 changed to a price is sent');

		console.log('stock entry price: the edit form sends a price only when it was changed');
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
