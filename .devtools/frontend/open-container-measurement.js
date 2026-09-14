// The open/measure modal in a real browser: node open-container-measurement.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// docs/plans/28-open-container-measurement.md's own verification asks for "a browser probe
// for the open dialog, in the shape of .devtools/frontend/nested-locations.js and invoked by
// the frontend-security job rather than merely placed beside it - plan 08's Executed section
// records that distinction being missed." Four things here cannot be asserted from PHP:
//
//   1. The modal actually opens, in 'open' mode, only for a stock entry a measurement can
//      attach to (round(amount, 2) == 1), and posts the shape MeasureStockEntry() /
//      OpenProduct() expect - a nested `measurement` object with `gross`/`tare`, not flat
//      fields a form's default serialisation would produce.
//   2. Re-measuring an already-open, single-unit entry reaches the SAME button in 'remeasure'
//      mode and posts to POST /stock/entry/{id}/measure rather than /open again.
//   3. The row's own summary text - "Opened - <amount> <unit> left" - reflects a real
//      measurement after a save, is computed via .text() alone (never concatenated into
//      .html()), and a quantity unit name (household-editable master data, S29's own class of
//      sink) cannot become markup through it.
//   4. The tare field only appears, and is only required, when the gross checkbox is ticked -
//      a page behaviour with no server side to check it against.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const payload = '<img src=x onerror=window.__xss=1>';

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();

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
		await page.evaluate(() => { window.__xss = 0; });

		// The quantity unit a measurement is taken in carries a payload name - household data,
		// the same class of sink as the location name in nested-locations.js - so the row
		// summary this probe reads later has something to fail on if it were ever built by
		// concatenation.
		// Also the product's own stock unit - the measurement modal defaults to it, and the
		// common case (per docs/plans/28-open-container-measurement.md's UI section) is
		// weighing in the same unit the product is stocked in, which needs no conversion at
		// all. The gross+tare case below uses it too, for the same reason.
		const measureQu = await api('objects/quantity_units', 'POST', { name: payload + ' ' + token, name_plural: payload + ' ' + token });
		const netQuId = Number(measureQu.created_object_id);

		const product = await api('objects/products', 'POST', {
			name: 'Open Measurement Product ' + token,
			location_id: (await api('objects/locations'))[0].id,
			qu_id_purchase: netQuId,
			qu_id_stock: netQuId,
			min_stock_amount: 0
		});
		const productId = product.created_object_id;

		// Two per-unit-labelled entries (stock_label_type 2) rather than two separate
		// purchases: identical purchases would compact back into one two-unit row, which the
		// scale button does not offer a measurement on (ADR-0022 decision 8 - a measurement
		// needs a single-unit entry). The 'x'-prefixed stock_id this produces is permanently
		// exempt from stock_splits regardless.
		await api('stock/products/' + productId + '/add', 'POST', { amount: 2, transaction_type: 'purchase', stock_label_type: 2 });

		let entries = await api('objects/stock?query%5B%5D=product_id%3D' + productId);
		assert.equal(entries.length, 2, 'two sealed one-unit entries exist to work with');
		const [netEntry, grossEntry] = entries;

		// stockentries.blade.php has no ?product= URL param of its own (unlike /purchase or
		// /stockjournal) - the page's own product picker is the filter, driven the same way
		// nested-locations.js drives it on other pages.
		async function filterToProduct()
		{
			// Not ?embedded: that mode strips the shared component scripts (assumed already
			// loaded in a parent frame for a real dialog), which is why ProductPicker would
			// otherwise be undefined here.
			await page.goto(base + '/stockentries', { waitUntil: 'networkidle' });
			await page.evaluate(id =>
			{
				Victual.Components.ProductPicker.SetId(id);
				Victual.Components.ProductPicker.GetPicker().trigger('change');
			}, productId);
			await page.waitForTimeout(500);
		}

		await filterToProduct();
		await page.waitForSelector('#stock-' + netEntry.id + '-row', { state: 'visible' });

		// 1. OPEN MODE, net reading. The button only exists because amount rounds to 1;
		//    stockentries.blade.php gates it on the same coherence test the migration's CHECK
		//    enforces.
		const netButton = page.locator('.stock-measure-button[data-stockrow-id="' + netEntry.id + '"]');
		assert.equal(await netButton.count(), 1, 'the scale button is offered for a single sealed unit');
		assert.equal(await netButton.getAttribute('data-mode'), 'open', 'in open mode before the entry is opened');

		await netButton.click();
		await page.locator('#stock-measurement-modal').waitFor({ state: 'visible' });
		assert.equal(await page.locator('#stock-measurement-tare-group').isVisible(), false, '4. the tare field starts hidden');

		await page.locator('#stock-measurement-amount').fill('0.6');
		await page.locator('#stock-measurement-qu').selectOption(String(netQuId));
		await page.locator('#stock-measurement-save-button').click();
		await page.waitForURL('**'); // no-op wait to let the reload this action triggers settle
		await page.waitForLoadState('networkidle');

		let netAfter = (await api('stock/entry/' + netEntry.id));
		assert.equal(Number(netAfter.open), 1, 'the entry is open after saving with a measurement');
		assert.equal(Number(netAfter.opened_amount), 0.6, 'the net reading was stored as-is, no tare subtracted');
		assert.equal(Number(netAfter.opened_qu_id), netQuId, 'in the unit the modal was set to');

		// 3. THE ROW SUMMARY, and the sink discipline on it. The page reloaded after the save
		//    (stockentries.js's own simplification for this action), so this is the freshly
		//    rendered server-side text, not a client patch - RefreshStockEntryRow's own .text()
		//    path is exercised separately below, by the remeasure action.
		await filterToProduct();
		const netRow = page.locator('#stock-' + netEntry.id + '-opened-amount');
		await netRow.waitFor({ state: 'visible' });
		assert.match(await netRow.innerText(), /Opened.*0\.6/, 'the row states what was measured');
		assert.equal(await page.locator('#stock-' + netEntry.id + '-opened-amount img').count(), 0, 'the quantity unit name did not become an element');
		assert.equal(await page.evaluate(() => window.__xss || 0), 0, 'and nothing executed');

		// 2. REMEASURE MODE. The same button, now offering the other action.
		const remeasureButton = page.locator('.stock-measure-button[data-stockrow-id="' + netEntry.id + '"]');
		assert.equal(await remeasureButton.getAttribute('data-mode'), 'remeasure', 'the button switches mode once the entry is open');
		assert.equal(await page.locator('#stock-measurement-skip-button').isVisible({ timeout: 1 }).catch(() => false), false, '"open without measuring" is not shown yet (modal not open)');

		await remeasureButton.click();
		await page.locator('#stock-measurement-modal').waitFor({ state: 'visible' });
		assert.equal(await page.locator('#stock-measurement-skip-button').isVisible(), false, 'and is hidden once the modal is open in remeasure mode - it would be a no-op booking here');

		await page.locator('#stock-measurement-amount').fill('0.3');
		await page.locator('#stock-measurement-qu').selectOption(String(netQuId));
		await page.locator('#stock-measurement-save-button').click();

		// Remeasuring refreshes the row in place rather than reloading (StockService.php's
		// MeasureStockEntry() path through stockentries.js), so this waits on the DOM update.
		await page.waitForFunction(
			text => document.querySelector(text)?.innerText.includes('0.3'),
			'#stock-' + netEntry.id + '-opened-amount',
			{ timeout: 10000 }
		);

		netAfter = await api('stock/entry/' + netEntry.id);
		assert.equal(Number(netAfter.opened_amount), 0.3, 'the re-measurement replaced the remainder');

		// 4. GROSS + TARE, on the second entry. The tare field appears only once the checkbox
		//    is ticked, and the stored opened_amount is net (gross minus tare) - the contract
		//    ADR-0022 decision 4 names, exercised here as a person would actually enter it.
		const grossButton = page.locator('.stock-measure-button[data-stockrow-id="' + grossEntry.id + '"]');
		await grossButton.click();
		await page.locator('#stock-measurement-modal').waitFor({ state: 'visible' });

		await page.locator('#stock-measurement-amount').fill('1.4');
		await page.locator('#stock-measurement-qu').selectOption(String(netQuId));
		await page.locator('label[for="stock-measurement-gross"]').click();
		assert.equal(await page.locator('#stock-measurement-tare-group').isVisible(), true, 'the tare field appears once gross is ticked');

		await page.locator('#stock-measurement-tare').fill('0.2');
		await page.locator('#stock-measurement-save-button').click();
		await page.waitForLoadState('networkidle');

		const grossAfter = await api('stock/entry/' + grossEntry.id);
		assert.equal(Number(grossAfter.opened_amount), 1.2, 'a gross reading of 1.4 with tare 0.2 stored as net 1.2');
		assert.equal(Number(grossAfter.opened_tare), 0.2, 'and the subtracted tare is recorded for the record');

		console.log('OPEN CONTAINER MEASUREMENT BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
