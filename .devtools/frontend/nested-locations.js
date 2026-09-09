// Nested locations in a real browser: node nested-locations.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Six things here cannot be asserted from PHP, which is why this file exists rather than more
// cases in .devtools/pgsql/nested-locations-tests.php. That phase proves the column, the view,
// the three guards and the API; every assertion below is about something between a person and
// those, and each one can break while the PHP phase stays green.
//
//   1. The parent picker. The PHP phase writes parent_location_id straight into the table. It
//      says nothing about whether the field is on the form, posts under that name, or arrives
//      as a number rather than as the empty string a <select> hands back for "no parent" -
//      which the column would store as a parent id of 0, a location that does not exist.
//   2. The freezer default. Plan 08 question 3 keeps is_freezer literal and pays for that with
//      a checkbox that follows the chosen parent while creating. That is a page behaviour with
//      no server side at all, and getting it wrong means a person ticks nothing and their
//      frozen food gets a fresh due date.
//   3. The path in a picker. Every location dropdown now shows the whole path so that two
//      "Shelf1" rows are distinguishable. Whether the option text is the path is a template
//      question the API cannot answer.
//   4. The roll-up. Selecting an ancestor in the stock overview's location filter has to find
//      stock held at a leaf beneath it (question 4). That lives in a hidden table cell and a
//      DataTables search; nothing below the browser can see whether the row survives.
//   5. The refusal a person reads. DeleteObject answers 400 with "Location has child
//      locations", and until this work the shared delete helper showed a generic sentence and
//      hid the server's behind a click. The assertion is that the message reaches the screen.
//   6. The payload. A location name is household data and reaches the new path column, the new
//      option text and the delete confirmation, so the S29 rule (AGENTS.md, plan 21) has to
//      hold on each. The seeded name is a live <img onerror>; if any of those built markup by
//      concatenation it would execute here.
//   7. The pickers that throw the template's options away. Consume and transfer rebuild their
//      location select from the product's stock locations, which the API reports by name, so
//      the paths rendered server-side survive only if the rebuild puts them back. Asserted
//      after the rebuild, because before it the template's own options would pass.
//   8. The row that moved. A stock entry edited into another location is refreshed in place,
//      and the location filter matches on the row's ancestor chain - so a refresh that updates
//      the id and the text but not the chain leaves the row matching where it used to be, with
//      nothing about it looking wrong until a reload.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

// The same payload the other probes use - sweep finding S29's own tag. The API's purifier
// strips the handler on the way in, so what comes back is not what was sent; what matters is
// that whatever IS stored never becomes an element.
const payload = '<img src=x onerror=window.__xss=1>';

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		// Taller than the default: the location form grew a parent picker, and with a short
		// viewport the save button ends up under the fixed navbar, where a click lands on the
		// nav instead.
		const page = await browser.newPage({ viewport: { width: 1400, height: 1200 } });
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();

		// Every name carries the run's token, so a re-run against the same instance neither
		// collides with the last one's rows nor asserts against them. The tree is plan 08
		// question 5's, which is the layout its answers were confirmed against.
		const name = part => part + ' ' + token;

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		async function locationNamed(wanted)
		{
			return (await api('objects/locations')).find(location => location.name === wanted);
		}

		// Creates one location through /location/new, choosing its parent in the picker.
		// Returns the state of the freezer checkbox as the form had it just before saving,
		// which is the only moment the default-from-parent behaviour is observable.
		async function saveLocationForm()
		{
			await page.locator('#save-location-button').click();
			await page.waitForURL('**/locations');
		}

		// Ticked through its label rather than through the input. A bootstrap custom-control
		// checkbox is an input at opacity 0 with the visible box drawn by the label, and this
		// one's input sits at x=241 - inside the 250px-wide navigation column, which is fixed
		// and therefore on top of it. Clicking the label is both what works and what a person
		// does; the assertions still read the input's own state.
		async function setFreezer(ticked)
		{
			if (await page.locator('#is_freezer').isChecked() !== ticked)
			{
				await page.locator('label[for="is_freezer"]').click();
			}
		}

		async function createThroughTheForm(childName, parentPath)
		{
			await page.goto(base + '/location/new');
			await page.locator('#name').fill(childName);

			if (parentPath !== null)
			{
				await page.locator('#parent_location_id').selectOption({ label: parentPath });
			}

			const freezerTicked = await page.locator('#is_freezer').isChecked();

			await saveLocationForm();

			return freezerTicked;
		}

		await page.goto(base + '/stockoverview');
		await page.evaluate(() => { window.__xss = 0; });

		// 1. THE TREE, built through the form rather than the API, so that what every later
		//    assertion reads back is what the form posted.
		await createThroughTheForm(name('Basement'), null);
		await createThroughTheForm(name('Main'), null);
		await createThroughTheForm(name('StorageRoom'), name('Basement'));
		await createThroughTheForm(name('Kitchen'), name('Main'));
		await createThroughTheForm(name('Rack1'), name('Basement') + ' / ' + name('StorageRoom'));

		// UprightFreezer is the only row whose freezer flag is set by hand; everything below it
		// is supposed to inherit it through the form's default rather than through the data.
		await page.goto(base + '/location/new');
		await page.locator('#name').fill(name('UprightFreezer'));
		await page.locator('#parent_location_id').selectOption({ label: name('Basement') + ' / ' + name('StorageRoom') });
		await setFreezer(true);
		await saveLocationForm();

		const basement = await locationNamed(name('Basement'));
		const main = await locationNamed(name('Main'));
		const storageRoom = await locationNamed(name('StorageRoom'));
		const rack1 = await locationNamed(name('Rack1'));
		const uprightFreezer = await locationNamed(name('UprightFreezer'));

		assert.equal(basement.parent_location_id, null, 'a root location posts a null parent, not an empty string or 0');
		assert.equal(storageRoom.parent_location_id, basement.id, 'StorageRoom was created under Basement');
		assert.equal(rack1.parent_location_id, storageRoom.id, 'Rack1 was created under StorageRoom');
		assert.equal(uprightFreezer.parent_location_id, storageRoom.id, 'UprightFreezer was created under StorageRoom');

		// 2. THE FREEZER DEFAULT, in both directions. Under the freezer it is ticked before the
		//    save; under the rack it is not, which is the control - a checkbox that was always
		//    ticked would satisfy the first assertion alone.
		const doorPath = name('Basement') + ' / ' + name('StorageRoom') + ' / ' + name('UprightFreezer');
		const doorTicked = await createThroughTheForm(name('Door'), doorPath);
		assert.equal(doorTicked, true, 'creating a child of a freezer pre-ticks is freezer');

		const shelfTicked = await createThroughTheForm(name('Shelf3'), name('Basement') + ' / ' + name('StorageRoom') + ' / ' + name('Rack1'));
		assert.equal(shelfTicked, false, 'creating a child of an ordinary location does not');

		const door = await locationNamed(name('Door'));
		assert.equal(Number(door.is_freezer), 1, 'and the pre-ticked flag really was saved');
		assert.equal(door.parent_location_id, uprightFreezer.id, 'Door sits under UprightFreezer');

		// 6a. The payload, as a child of the tree. Created through the API rather than the form
		//     because what is being asserted is the rendering, not the writing - and the pages
		//     below have to survive it whichever way it arrived.
		await api('objects/locations', 'POST', { name: payload + ' ' + token, parent_location_id: rack1.id });

		// 3. THE PATH IN A PICKER, on the purchase page, and a purchase that lands where the
		//    option said it would.
		await page.goto(base + '/purchase');
		const doorOption = page.locator('#location_id option', { hasText: name('Door') });
		assert.equal(await doorOption.count(), 1, 'the purchase page offers Door exactly once');
		assert.equal((await doorOption.innerText()).trim(), doorPath + ' / ' + name('Door'), 'and offers it by its whole path');

		const productName = 'Nested Location Product ' + token;
		const quantityUnit = (await api('objects/quantity_units'))[0].id;
		const product = await api('objects/products', 'POST', {
			name: productName,
			location_id: door.id,
			qu_id_purchase: quantityUnit,
			qu_id_stock: quantityUnit,
			min_stock_amount: 0
		});

		// Both pickers are driven through their own component APIs rather than through the
		// comboboxes: bootstrap-combobox's dropdown is not reachable from a headless keyboard,
		// and how an id gets into the select is not what this work changed. What it changed is
		// the option text, asserted above, and where the booking lands, asserted below. The
		// extra 'change' on the hidden select is what undo-toasts.js does and for the same
		// reason - the page binds its "product changed" chain to that select.
		await page.goto(base + '/purchase', { waitUntil: 'networkidle' });
		await page.evaluate(id =>
		{
			Victual.Components.ProductPicker.SetId(id);
			Victual.Components.ProductPicker.GetPicker().trigger('change');
		}, product.created_object_id);
		await page.waitForTimeout(2500);

		await page.fill('#display_amount', '2');

		// The due date is required here and is only prefilled for a product that has a default
		// due-days setting, which this one does not. Typed rather than filled, so the
		// datetimepicker's own keyup handler clears the custom validity.
		await page.fill('#best_before_date input.form-control', '');
		await page.type('#best_before_date input.form-control', '2027-12-31', { delay: 25 });
		await page.press('#best_before_date input.form-control', 'End');
		await page.waitForTimeout(600);

		await page.evaluate(id => Victual.Components.LocationPicker.SetId(id), door.id);
		assert.equal(await page.locator('#location_id').inputValue(), String(door.id), 'the picker holds Door');

		await page.locator('#save-purchase-button').click();
		await page.waitForSelector('#toast-container a:has-text("Undo")', { timeout: 20000 });

		const entries = await api('objects/stock?query%5B%5D=product_id%3D' + product.created_object_id);
		assert.ok(entries.length > 0, 'the purchase created a stock entry');
		assert.equal(Number(entries[0].location_id), Number(door.id), 'and it landed at Door rather than at the product default');

		// 4. THE ROLL-UP. Basement is three levels above Door and has no stock of its own.
		await page.goto(base + '/stockoverview');
		const row = page.locator('#product-' + product.created_object_id + '-row');
		await row.waitFor();

		await page.locator('#location-filter').selectOption(String(basement.id));
		await row.waitFor({ state: 'visible' });
		assert.ok(await row.isVisible(), 'filtering by Basement finds stock held at Door, three levels below it');

		// The control. Main is a root with a subtree of its own, so a filter that matched
		// everything - or nothing - would be caught here rather than passing case 4 by accident.
		await page.locator('#location-filter').selectOption(String(main.id));
		await row.waitFor({ state: 'hidden' });
		assert.equal(await row.isVisible(), false, 'filtering by Main does not');

		await page.locator('#location-filter').selectOption(String(door.id));
		await row.waitFor({ state: 'visible' });
		assert.ok(await row.isVisible(), 'and filtering by Door itself still does');

		// 5. THE REFUSAL. StorageRoom has children, so the delete is answered 400 and the page
		//    has to say why rather than showing a generic failure.
		await page.goto(base + '/locations');
		const deleteButton = page.locator('.location-delete-button[data-location-id="' + storageRoom.id + '"]');
		await deleteButton.waitFor();
		await deleteButton.click();
		await page.locator('.bootbox .btn-success').click();

		const toast = page.locator('#toast-container');
		await toast.waitFor({ timeout: 15000 });
		assert.match(await toast.innerText(), /Location has child locations/, 'the refusal says what is wrong');

		assert.ok(await locationNamed(name('StorageRoom')), 'and the location is still there');

		// 7. THE PICKERS THAT REBUILD THEMSELVES. Consume and transfer do not keep the options
		//    the template gave them: choosing a product empties the location select and
		//    rebuilds it from that product's stock locations, which the API reports by
		//    location_name. Before this was fixed the paths rendered server-side were replaced
		//    by bare names on exactly the two pages where the choice decides which physical
		//    stock is consumed or moved. Asserted after the rebuild, not before it - the
		//    template's own options would pass either way.
		// Waiting for the rebuild rather than for Door is the whole point: the template's own
		// options already name Door by its path, so a wait that Door satisfies is satisfied
		// before the rebuild has replaced anything and the assertion below would pass against
		// markup the rebuild was about to throw away. What only the rebuild can produce is a
		// *short* list - this product has stock in one location - so that is what is waited
		// for, and asserted, before the text is read.
		async function pickProductAndReadLocations(path, selectSelector)
		{
			await page.goto(base + path, { waitUntil: 'networkidle' });

			const optionsBefore = await page.locator(selectSelector + ' option').count();
			assert.ok(optionsBefore > 2, path + ' starts with the whole location list (' + optionsBefore + ' options)');

			await page.evaluate(id =>
			{
				Victual.Components.ProductPicker.SetId(id);
				Victual.Components.ProductPicker.GetPicker().trigger('change');
			}, product.created_object_id);

			await page.waitForFunction(
				selector => document.querySelectorAll(selector + ' option').length === 2,
				selectSelector,
				{ timeout: 15000 });

			const texts = await page.locator(selectSelector + ' option').allInnerTexts();
			const doorOption = texts.find(text => text.includes(name('Door')));
			assert.ok(doorOption !== undefined, path + ' still offers Door after the rebuild: ' + JSON.stringify(texts));

			return doorOption;
		}

		const consumeOption = await pickProductAndReadLocations('/consume', '#location_id');
		assert.ok(consumeOption.includes(doorPath), 'the consume picker still names Door by its path after the rebuild: ' + consumeOption);

		const transferOption = await pickProductAndReadLocations('/transfer', '#location_id_from');
		assert.ok(transferOption.includes(doorPath), 'and so does the transfer picker: ' + transferOption);

		// 8. THE ROW THAT MOVED. A stock entry edited into another location has its row
		//    refreshed in place, and the location filter matches on the row's ancestor chain -
		//    so refreshing the id and the text without the chain leaves the row matching the
		//    location it came from and missing the one it went to, with nothing about it
		//    looking wrong until a reload. This drives the same sequence the page runs when
		//    the edit dialog reports success: PUT the entry, then refresh its row.
		await page.goto(base + '/stockentries', { waitUntil: 'networkidle' });

		const entryId = entries[0].id;
		const entryRowCell = page.locator('#stock-' + entryId + '-location');
		await entryRowCell.waitFor();

		const ancestorsBefore = (await entryRowCell.getAttribute('data-location-ancestors')).split(',');
		assert.ok(ancestorsBefore.includes(String(basement.id)), 'the row starts out under Basement');
		assert.ok(!ancestorsBefore.includes(String(main.id)), 'and not under Main');

		const kitchen = await locationNamed(name('Kitchen'));
		// The same body the edit form sends. `open` and `purchased_date` are read unguarded by
		// EditStockEntry, so a body without them is a 500 rather than a 400 - a pre-existing
		// gap of plan 11's, not this work's, and not widened here.
		await api('stock/entry/' + entryId, 'PUT', {
			amount: 2,
			location_id: kitchen.id,
			best_before_date: '2027-12-31',
			purchased_date: entries[0].purchased_date,
			open: false
		});
		await page.evaluate(id => RefreshStockEntryRow(id), entryId);
		await page.waitForFunction(
			([id, wanted]) => (document.querySelector('#stock-' + id + '-location')?.getAttribute('data-location-ancestors') || '').split(',').includes(String(wanted)),
			[entryId, main.id],
			{ timeout: 15000 });

		const ancestorsAfter = (await entryRowCell.getAttribute('data-location-ancestors')).split(',');
		assert.ok(ancestorsAfter.includes(String(main.id)), 'after the move the row is under Main');
		assert.ok(!ancestorsAfter.includes(String(basement.id)), 'and no longer under Basement');
		assert.match(await entryRowCell.innerText(), new RegExp(name('Main') + ' / ' + name('Kitchen')), 'and the cell shows the new path, not the bare name');

		// The filter, which is what the chain is for, follows without a reload.
		await page.locator('#location-filter').selectOption(String(main.id));
		await page.locator('#stock-' + entryId + '-row').waitFor({ state: 'visible' });
		await page.locator('#location-filter').selectOption(String(basement.id));
		await page.locator('#stock-' + entryId + '-row').waitFor({ state: 'hidden' });

		// 6b. Every page above rendered the payload row. If any of them had built markup by
		//     concatenation the handler would have run by now.
		await page.goto(base + '/locations');
		await page.locator('#locations-table tbody tr').first().waitFor();
		assert.equal(await page.locator('#locations-table img[src="x"]').count(), 0, 'the locations list did not turn the payload into an element');

		await page.goto(base + '/purchase');
		assert.equal(await page.locator('#location_id img').count(), 0, 'the location picker did not either');

		await page.goto(base + '/stockoverview');
		assert.equal(await page.locator('#location-filter img').count(), 0, 'nor did the overview filter');
		assert.equal(await page.evaluate(() => window.__xss || 0), 0, 'and nothing executed on any of them');

		console.log('NESTED LOCATION BROWSER CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
