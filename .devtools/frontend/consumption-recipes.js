// Private consumption recipes in a real browser: node consumption-recipes.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// What a person does on /consumptionrecipes, end to end, and the three things the PHP suites cannot
// see from below:
//
//   1. The booked date. The server runs in UTC and books the date written in the offset it
//      receives (ADR-0041 rule 5), so the page must send the browser's own offset. The probe runs in
//      America/New_York and reads the request body.
//   2. The payload. A recipe name and note are private household data that reach the list, the
//      consume dialog title and the share and history dialogs. The seeded name is a live
//      <img onerror>; if any of those built markup from it, it would execute here (S29, AGENTS.md).
//   3. The refusals a person reads. A consumption that the stock refuses shows the stock's own
//      sentence and books nothing, and a location is never swapped for another.
//
// Authorization itself (who may see, consume, edit, share, undo) is covered by
// ConsumptionRecipeServiceTest and ConsumptionRecipeApiTest; this single-user instance cannot
// sign in as a second person.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const payload = '<img src=x onerror=window.__xss=1>';
// The API purifies strings on the way in, so the stored name is the tag without its handler,
// `<img src="x" alt="x" />`. What matters is that it is shown as text and never becomes an element.
const shownAsText = '<img src="x"';

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

		// Fixtures: a product with 6 in organizer A and 20 in organizer B, so a request for 8 from A is
		// refused even though B could cover it.
		const unit = (await api('objects/quantity_units'))[0];
		const organizerA = (await api('objects/locations', 'POST', { name: 'Organizer A ' + token })).created_object_id;
		const organizerB = (await api('objects/locations', 'POST', { name: 'Organizer B ' + token })).created_object_id;
		const product = (await api('objects/products', 'POST', { name: 'Consumption product ' + token, location_id: organizerA, qu_id_purchase: unit.id, qu_id_stock: unit.id, qu_id_consume: unit.id, qu_id_price: unit.id })).created_object_id;
		await api('stock/products/' + product + '/add', 'POST', { amount: 6, transaction_type: 'purchase', location_id: organizerA });
		await api('stock/products/' + product + '/add', 'POST', { amount: 20, transaction_type: 'purchase', location_id: organizerB });
		const onHand = async location => (await api('stock/products/' + product + '/locations')).filter(l => String(l.location_id) === String(location)).reduce((sum, l) => sum + Number(l.amount), 0);

		await page.reload();
		const recipeName = payload + ' ' + token;

		// Create
		await page.locator('#consumption-new').click();
		await page.locator('#consumption-name').fill(recipeName);
		await page.locator('#consumption-note').fill(payload + ' note');
		await page.locator('.consumption-line-product').first().selectOption({ label: 'Consumption product ' + token });
		await page.locator('.consumption-line-unit option').first().waitFor({ state: 'attached' });
		await page.locator('.consumption-line-amount').first().fill('8');
		await page.locator('#consumption-save').click();
		const row = page.locator('#consumption-rows tr').filter({ hasText: token });
		await row.waitFor();
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'the recipe name never executed');
		assert.ok((await row.innerText()).includes(shownAsText), 'the stored markup is visible as text');
		assert.equal(await page.locator('#consumption-rows img').count(), 0, 'and is not an element');

		// Refused: 8 are asked of a location that holds 6
		await row.locator('.consumption-consume-button').click();
		await page.locator('#consumption-consume-modal.show').waitFor();
		assert.ok((await page.locator('#consumption-consume-title').innerText()).includes(shownAsText), 'the dialog title shows the name as text');
		assert.equal(await page.locator('#consumption-consume-modal img').count(), 0);
		await page.locator('#consumption-location').selectOption({ label: 'Organizer A ' + token });
		await page.locator('#consumption-consume').click();
		await page.locator('#consumption-consume-error').filter({ hasText: /\S/ }).waitFor();
		assert.deepEqual([await onHand(organizerA), await onHand(organizerB)], [6, 20], 'a refusal books nothing and does not charge the other organizer');

		// Recorded from organizer B, with the browser's offset on the request
		let sentBody = null;
		page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/consume')) sentBody = JSON.parse(request.postData()); });
		await page.locator('#consumption-location').selectOption({ label: 'Organizer B ' + token });
		await page.locator('#consumption-consume').click();
		await page.locator('#consumption-message').filter({ hasText: /\S/ }).waitFor();
		assert.deepEqual([await onHand(organizerA), await onHand(organizerB)], [6, 12]);
		assert.match(sentBody.occurred_at, /-0[45]:00$/, 'the request carries the New York offset, not Z');
		assert.match(sentBody.request_id, /^[A-Za-z0-9._:-]{1,128}$/);
		await page.locator('#consumption-consume-modal').waitFor({ state: 'hidden' });

		// History and undo
		await row.locator('.consumption-history-button').click();
		await page.locator('#consumption-history-modal.show').waitFor();
		const eventRow = page.locator('#consumption-history-rows tr').first();
		await eventRow.waitFor();
		assert.ok((await page.locator('#consumption-history-title').innerText()).includes(shownAsText));
		assert.equal(await page.locator('#consumption-history-modal img').count(), 0);
		await eventRow.locator('.consumption-undo').click();
		await page.locator('#consumption-history-rows tr').filter({ hasText: /Undone/ }).waitFor();
		assert.deepEqual([await onHand(organizerA), await onHand(organizerB)], [6, 20], 'undo restores what was booked');
		await page.locator('#consumption-history-modal [data-dismiss=modal]').first().click();
		await page.locator('#consumption-history-modal').waitFor({ state: 'hidden' });

		// Share with a user by name, change the rights, remove
		await api('users', 'POST', { username: 'consumption-sharee-' + token, password: 'test fixture only' });
		await row.locator('.consumption-share-button').click();
		await page.locator('#consumption-share-modal.show').waitFor();
		await page.locator('#consumption-share-username').fill('consumption-sharee-' + token);
		await page.locator('#consumption-share-consume').check();
		await page.locator('#consumption-share-add').click();
		const shareRow = page.locator('#consumption-share-rows tr').filter({ hasText: 'consumption-sharee-' + token });
		await shareRow.waitFor();
		assert.equal(await shareRow.locator('input[type=checkbox]').nth(0).isChecked(), true);
		await shareRow.locator('input[type=checkbox]').nth(1).check();
		await shareRow.locator('.consumption-share-save').click();
		await page.waitForTimeout(300);
		await page.locator('#consumption-share-username').fill('nobody-by-that-name-' + token);
		await page.locator('#consumption-share-add').click();
		await page.locator('#consumption-share-error').filter({ hasText: /\S/ }).waitFor();
		await shareRow.locator('.consumption-share-remove').click();
		await shareRow.waitFor({ state: 'detached' });
		await page.locator('#consumption-share-modal [data-dismiss=modal]').first().click();
		await page.locator('#consumption-share-modal').waitFor({ state: 'hidden' });

		// Delete
		await row.locator('.consumption-delete-button').click();
		await row.waitFor({ state: 'detached' });
		assert.deepEqual(errors, [], 'no page error');
		assert.equal(await page.evaluate(() => window.__xss), undefined);
		console.log('CONSUMPTION RECIPE BROWSER CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
