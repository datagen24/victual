// Weekly organizers in a real browser: node organizers.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// An organizer is an ordinary location. This probe drives the trip scenario of plan 22 (#699)
// through the pages a person uses and reads the stock back through the API after every step:
//
//   1. Filling three organizers on /transfer moves stock and leaves the household total.
//   2. /consume with the organizer chosen takes from that organizer only.
//   3. Returning an organizer is another transfer, not a consumption.
//   4. The consumption recipe dialog's "Take from" charges only the organizer it names.
//
// The pickers on /transfer and /consume rebuild their location list from the product's stock
// locations when a product is chosen (nested-locations.js, point 7), so each step waits for the
// rebuilt list, which is shorter than the template's, before it selects a location.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const context = await browser.newContext({ viewport: { width: 1400, height: 1200 }, timezoneId: 'America/New_York' });
		const page = await context.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		page.on('dialog', dialog => dialog.accept());

		await page.goto(base + '/consumptionrecipes');

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		const unit = (await api('objects/quantity_units'))[0];
		const location = async label => (await api('objects/locations', 'POST', { name: label + ' ' + token })).created_object_id;
		const cabinet = await location('Cabinet');
		const organizers = { A: await location('Organizer A'), B: await location('Organizer B'), C: await location('Organizer C') };
		const product = (await api('objects/products', 'POST', { name: 'Organizer product ' + token, location_id: cabinet, qu_id_purchase: unit.id, qu_id_stock: unit.id, qu_id_consume: unit.id, qu_id_price: unit.id })).created_object_id;
		await api('stock/products/' + product + '/add', 'POST', { amount: 21, transaction_type: 'purchase', location_id: cabinet });

		const amounts = async () =>
		{
			const rows = await api('stock/products/' + product + '/locations');
			const at = id => rows.filter(row => String(row.location_id) === String(id)).reduce((sum, row) => sum + Number(row.amount), 0);
			return { cabinet: at(cabinet), A: at(organizers.A), B: at(organizers.B), C: at(organizers.C) };
		};
		const consumeBookings = async () => (await api('objects/stock_log?query[]=product_id=' + product + '&query[]=transaction_type=consume&query[]=undone=0')).length;

		async function chooseProduct(path, fromSelector)
		{
			await page.goto(base + path, { waitUntil: 'networkidle' });
			const before = await page.locator(fromSelector + ' option').count();
			await page.evaluate(id =>
			{
				Victual.Components.ProductPicker.SetId(id);
				Victual.Components.ProductPicker.GetPicker().trigger('change');
			}, product);
			// The rebuilt list holds only the locations that have this product in stock, plus the
			// empty option, so it is shorter than the template's whole list once the rebuild ran.
			await page.waitForFunction(({ selector, before }) => document.querySelectorAll(selector + ' option').length < before, { selector: fromSelector, before }, { timeout: 15000 });
			// The product details arrive after the list is rebuilt and reset the form's defaults, so the
			// page is let go quiet before anything is typed into it.
			await page.waitForLoadState('networkidle');
			await page.waitForTimeout(500);
		}

		async function transfer(fromId, toId, amount)
		{
			await chooseProduct('/transfer', '#location_id_from');
			await page.locator('#location_id_from').selectOption(String(fromId));
			await page.locator('#location_id_to').selectOption(String(toId));
			await page.fill('#display_amount', String(amount));
			await page.locator('#save-transfer-button').click();
			await page.waitForSelector('#toast-container a:has-text("Undo")', { timeout: 20000 });
		}

		// 1. Fill three organizers from the cabinet
		for (const key of ['A', 'B', 'C']) await transfer(cabinet, organizers[key], 7);
		assert.deepEqual(await amounts(), { cabinet: 0, A: 7, B: 7, C: 7 }, 'three organizers hold the 21 the cabinet held');
		assert.equal(await consumeBookings(), 0, 'filling books no consumption');

		// 2. Consume one from organizer A on /consume, with the location chosen
		await chooseProduct('/consume', '#location_id');
		await page.locator('#location_id').selectOption(String(organizers.A));
		await page.fill('#display_amount', '1');
		await page.locator('#save-consume-button').click();
		await page.waitForSelector('#toast-container a:has-text("Undo")', { timeout: 20000 });
		assert.deepEqual(await amounts(), { cabinet: 0, A: 6, B: 7, C: 7 }, 'only organizer A was charged');
		assert.equal(await consumeBookings(), 1);

		// 3. Return organizer C to the cabinet
		await transfer(organizers.C, cabinet, 7);
		assert.deepEqual(await amounts(), { cabinet: 7, A: 6, B: 7, C: 0 }, 'the return is a transfer');
		assert.equal(await consumeBookings(), 1, 'and books no consumption');

		// 4. The consumption recipe dialog charges only the organizer it names. The product's default
		//    consume location is organizer A, which "Any location" would use first, so charging B proves
		//    the explicit choice wins over the configured default.
		await api('objects/products/' + product, 'PUT', { default_consume_location_id: organizers.A });
		const recipe = (await api('consumption/recipes', 'POST', { name: 'Organizer recipe ' + token, lines: [{ product_id: product, amount: 2, qu_id: unit.id }] })).created_object_id;
		await page.goto(base + '/consumptionrecipes');
		const row = page.locator('#consumption-rows tr[data-recipe-id="' + recipe + '"]');
		await row.waitFor();
		await row.locator('.consumption-consume-button').click();
		await page.locator('#consumption-consume-modal.show').waitFor();
		await page.locator('#consumption-location').selectOption(String(organizers.B));
		await page.locator('#consumption-consume').click();
		await page.locator('#consumption-message').filter({ hasText: 'Consumption recorded.' }).waitFor();
		assert.deepEqual(await amounts(), { cabinet: 7, A: 6, B: 5, C: 0 }, '"Take from" organizer B charged organizer B only');

		assert.deepEqual(errors, [], 'no page error');
		console.log('ORGANIZER BROWSER CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
