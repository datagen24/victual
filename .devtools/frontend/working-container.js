// Working container replenishment in a real browser: node working-container.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Four things here cannot be asserted from PHP, which is why this file exists rather than more
// cases in .devtools/pgsql/working-container-tests.php. That phase proves the column, the view
// and the service methods; every assertion below is about something between a person and those.
//
//   1. The location form's tare fields. The PHP phase writes tare_weight/tare_qu_id straight
//      into the table. It says nothing about whether the fields are on the form, post under
//      those names, arrive as null when left blank (a nullable double and a nullable integer,
//      the same "" trap plan 08's Executed section records for parent_location_id), or read
//      back into the inputs when the form is reopened.
//   2. The product form's one-tap refill fields. Same shape, same question, for
//      quick_refill_amount and the two default_refill_location_id_* selects.
//   3. THE RULE THAT MUST NOT BE GOT WRONG, seen from the page rather than the view: the short
//      (product, location) pair is named on the stock overview, not folded into a count, and it
//      is never a status a product row carries - the click handler product groups share sets
//      the status dropdown, and this list must not.
//   4. The one-tap refill button itself. It only renders when the product has both
//      quick_refill_amount and default_refill_location_id_from configured, and clicking it has
//      to actually move stock - which is the whole point of the feature, and invisible to
//      anything that does not drive the page.
//
// The seeded location name carries the S29 payload (AGENTS.md, plan 21): it reaches the
// shortfall list's product/location text and the reopened form input, both of which are built
// with .text()/.val() rather than concatenated into markup, so nothing here should execute.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const payload = '<img src=x onerror=window.__xss=1>';

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();

		// Routes around a pre-existing defect this probe found while writing it, unrelated to
		// anything this plan touches: productform.js always sends parent_product_id (the
		// "copy settings from" picker's field, renamed from product_id) even when nothing was
		// picked, and an untouched <select> serializes that as "" - which a nullable *integer*
		// column refuses, the same "" trap plan 08's Executed section records for
		// parent_location_id. Reproduced directly against /api/objects/products with a minimal
		// payload leaving parent_product_id, product_group_id, shopping_location_id or
		// default_consume_location_id blank in turn: every one of them 400s the same way, on
		// this branch and on master alike, independent of this plan's own columns. Worth its
		// own issue; not fixed here because fixing a shared form's submit handler is a
		// different, unrelated change. The interception only touches the one field this bug is
		// about, so a real defect in this plan's own fields still reaches the API unmasked.
		await page.route('**/api/objects/products', async (route) =>
		{
			const request = route.request();
			if (request.method() !== 'POST' && request.method() !== 'PUT')
			{
				return route.continue();
			}
			const body = JSON.parse(request.postData());
			if (body.parent_product_id === '')
			{
				body.parent_product_id = null;
			}
			return route.continue({ postData: JSON.stringify(body) });
		});

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

		const quWeight = (await api('objects/quantity_units')).find(qu => qu.name === 'Gram' || qu.name === 'Kilogram') || (await api('objects/quantity_units'))[0];

		// --- THE LOCATION FORM: tare_weight / tare_qu_id round-trip -----------------------

		await page.goto(base + '/location/new');
		await page.locator('#name').fill('Dry Stores ' + token);
		await page.locator('#save-location-button').click();
		await page.waitForURL('**/locations');

		const dryStores = (await api('objects/locations')).find(l => l.name === 'Dry Stores ' + token);
		assert.ok(dryStores, 'the dry stores location was created');
		assert.equal(dryStores.tare_weight, null, 'a location created without a tare has none (control)');

		const binName = 'Bin ' + token + ' ' + payload;
		await page.goto(base + '/location/new');
		await page.locator('#name').fill(binName);
		await page.locator('#tare_weight').fill('1.5');
		await page.locator('#tare_qu_id').selectOption({ value: String(quWeight.id) });
		await page.locator('#save-location-button').click();
		await page.waitForURL('**/locations');

		const bin = (await api('objects/locations')).find(l => l.name.startsWith('Bin ' + token));
		assert.ok(bin, 'the bin location was created');
		// The purifier strips onerror on the way in (BaseApiController::CreateHtmlPurifier()) -
		// what survives is inert markup as text, which is what the rest of this probe checks
		// stays text all the way to the shortfall row rather than becoming an element.
		assert.ok(bin.name.toLowerCase().includes('img'), 'the payload survives purification as inert text');
		assert.equal(Number(bin.tare_weight), 1.5, 'the form saved the tare weight');
		assert.equal(Number(bin.tare_qu_id), quWeight.id, 'the form saved the tare unit');

		// Reopened, and the stored values are read back into the inputs - a form that saves
		// and then shows blank on the next visit is the defect this line exists for.
		await page.goto(base + '/location/' + bin.id);
		assert.equal(Number(await page.locator('#tare_weight').inputValue()), 1.5, 'the form reloads the stored tare weight');
		assert.equal(await page.locator('#tare_qu_id').inputValue(), String(quWeight.id), 'the form reloads the stored tare unit');

		// --- THE PRODUCT FORM: one-tap refill fields round-trip ---------------------------

		const shoppingLocationId = (await api('objects/shopping_locations'))[0].id;

		await page.goto(base + '/product/new');
		await page.locator('#name').fill('Working Container Product ' + token);
		await page.locator('#location_id').selectOption({ index: 1 });
		await page.locator('#qu_id_stock').selectOption({ value: String(quWeight.id) });
		await page.locator('#qu_id_purchase').selectOption({ value: String(quWeight.id) });
		await page.locator('#qu_id_consume').selectOption({ value: String(quWeight.id) });
		await page.locator('#qu_id_price').selectOption({ value: String(quWeight.id) });
		await page.locator('#product_group_id').selectOption({ index: 1 });
		await page.locator('#default_consume_location_id').selectOption({ index: 1 });
		// Three fields unrelated to this plan, filled here only to route around a pre-existing
		// defect this probe found while writing it: an untouched nullable-integer column posts
		// "" (the same trap plan 08's Executed section records for parent_location_id), and
		// the combobox below needs its own component API rather than .selectOption() because
		// the visible widget, not the hidden <select> alone, is what the page actually submits
		// from. Reproduced directly against /api/objects/products with a minimal payload
		// leaving shopping_location_id, product_group_id or default_consume_location_id blank
		// in turn, independent of any field this plan touches. Out of scope to fix here;
		// worth its own issue.
		await page.evaluate(id => Victual.Components.ShoppingLocationPicker.SetId(id), shoppingLocationId);
		await page.locator('#quick_refill_amount').fill('5');
		await page.locator('#default_refill_location_id_from').selectOption({ value: String(dryStores.id) });
		await page.locator('#default_refill_location_id_to').selectOption({ value: String(bin.id) });
		await page.locator('#save-product-button').click();
		// #save-product-button is "Save & continue" (data-location="continue"), which stays on
		// /product/{id} rather than redirecting to /products.
		await page.waitForURL('**/product/*');

		let product = (await api('objects/products')).find(p => p.name === 'Working Container Product ' + token);
		assert.ok(product, 'the product was created');
		assert.equal(Number(product.quick_refill_amount), 5, 'the form saved the refill amount');
		assert.equal(Number(product.default_refill_location_id_from), dryStores.id, 'the form saved the refill source');
		assert.equal(Number(product.default_refill_location_id_to), bin.id, 'the form saved the refill destination');

		await page.goto(base + '/product/' + product.id);
		assert.equal(Number(await page.locator('#quick_refill_amount').inputValue()), 5, 'the form reloads the refill amount');
		assert.equal(await page.locator('#default_refill_location_id_from').inputValue(), String(dryStores.id), 'the form reloads the refill source');
		assert.equal(await page.locator('#default_refill_location_id_to').inputValue(), String(bin.id), 'the form reloads the refill destination');

		// --- THE SHORTFALL LIST AND THE ONE-TAP REFILL -------------------------------------

		// Backstock in dry stores, so the refill button has something to move - and a
		// minimum on the bin, so the pair is short (5 lb kept there today; none is stocked
		// yet, so it is short by the whole minimum).
		await api('stock/products/' + product.id + '/add', 'POST', { amount: 20, best_before_date: '2999-01-01', transaction_type: 'purchase', location_id: dryStores.id });
		await api('objects/product_location_min_stock', 'POST', { product_id: product.id, location_id: bin.id, min_stock_amount: 5 });

		await page.goto(base + '/stockoverview');

		const entry = page.locator('#missing-product-locations-list li', { hasText: 'Working Container Product ' + token });
		await entry.waitFor();
		const entryText = await entry.innerText();
		assert.ok(entryText.includes('5'), 'the shortfall names the missing amount');

		// THE RULE. The row is not a .status-filter-message, so it must not carry a
		// data-status-filter attribute the way the built-in due/overdue/missing counters do -
		// a short bin is not a status a product row carries.
		assert.equal(await page.locator('#info-missing-product-locations').getAttribute('data-status-filter'), null,
			'the location shortfall counter is not wired into the status filter');

		// THE PAYLOAD. The bin's name carries the S29 tag; it must render as visible text in
		// the shortfall row rather than execute.
		assert.ok(entryText.toLowerCase().includes('img'), 'the payload is present as text in the shortfall row');
		const xssTriggered = await page.evaluate(() => window.__xss === 1);
		assert.equal(xssTriggered, false, 'the payload in the shortfall row did not execute');

		// THE ACTION. Clicking Refill books a real transfer.
		const refillButton = entry.locator('.product-location-refill-button');
		await refillButton.waitFor();
		await refillButton.click();

		await page.waitForFunction(() => document.querySelector('.toast-success') !== null, { timeout: 10000 });

		const currentAtBin = await page.evaluate(async (locationId) =>
		{
			const response = await fetch('/api/objects/stock_current_locations');
			return (await response.json()).filter(r => r.location_id == locationId);
		}, bin.id);

		assert.equal(currentAtBin.length, 1, 'the refill created exactly one stock entry at the bin');
		assert.equal(Number(currentAtBin[0].amount), 5, 'the refill moved the preset amount into the bin');

		console.log('WORKING CONTAINER BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
