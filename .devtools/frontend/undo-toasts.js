// Plan 12 verification check 2 (last item), and step 5a's acceptance test.
//
// Every stock booking toast carries an inline "Undo" link that calls a global -
// UndoStockTransaction, or UndoStockBookingEntry on the stock entries page. Those globals
// used to be copied into five page scripts, and three Blade views pushed purchase.js
// purely to import them; step 5 moved one of each into public/js/victual_stock_dialogs.js
// and deleted the pushes. This probe books stock on each page that shows such a toast,
// clicks the Undo link in the toast that page actually rendered, and asserts that every row
// the booking wrote in stock_log came back with undone = 1.
//
//   node undo-toasts.js --url http://127.0.0.1:8085
//
// Issue #579: this used to read stock_log through a raw `new PDO("sqlite:...")`, which needs
// the SQLite runtime ADR-0008 retired (except behind DIFFTEST_SQLITE_RUNTIME) and which CI's
// frontend-security job never ran, since it had nothing to point that flag at. It now reads
// the ledger the way every other probe in this directory reads server state: through the
// /api/ endpoints of the very server it is driving, using the transaction or booking id each
// booking's own response returns rather than a side-channel database connection. It also no
// longer waits a fixed 800ms for the consumed stock entry's row to hide (a race against
// animate.css 3.7's 500ms "faster" fade plus the row's own refresh GET) - it waits for the
// row's own d-none class instead.
//
// Issue #575: the stock entry edit form's own Undo link used to be built from `result.id`,
// which is undefined - PUT /stock/entry/{entryId} returns an array of stock_log rows, not a
// single object with an `id` - so the link posted to stock/bookings/undefined/undo and the
// undo silently failed. Covered here by the 'stockentry-edit' scenario below.

const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8200')).replace(/\/$/, '');

const results = [];
function record(page, how, booked, undone, note)
{
	results.push({ page, how, booked, undone, note: note || '' });
}

async function newPage(browser, label)
{
	const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
	page.on('pageerror', e => console.log('   pageerror on ' + label + ': ' + e.message));
	return page;
}

/**
 * Clicks the "Undo" anchor inside the toast the page just rendered, and waits for the
 * undo POST it triggers (stock/bookings/{id}/undo or stock/transactions/{id}/undo) to
 * actually complete, rather than a fixed delay every scenario paid regardless of how
 * long that request took.
 */
async function clickUndoInToast(page)
{
	const undo = page.locator('#toast-container a:has-text("Undo")').first();
	await undo.waitFor({ state: 'visible', timeout: 20000 });
	await Promise.all([
		page.waitForResponse(r => r.request().method() === 'POST'
			&& /\/api\/stock\/(bookings|transactions)\/[^/?]+\/undo(\?|$)/.test(r.url()), { timeout: 20000 }),
		undo.click()
	]);
}

async function waitForUndoToast(page)
{
	await page.waitForSelector('#toast-container a:has-text("Undo")', { timeout: 20000 });
}

/**
 * Reads the ledger rows named by `path` (a single stock/bookings/{id}, or every row of a
 * stock/transactions/{id}) through the API, using the same authenticated session the page
 * itself runs under - the same thing every other check in this directory does instead of
 * opening a database connection of its own (issue #579).
 */
async function readUndoneCount(page, path)
{
	return await page.evaluate(async (url) =>
	{
		const res = await fetch(url, { credentials: 'same-origin' });
		const body = await res.json();
		const rows = Array.isArray(body) ? body : [body];
		return { booked: rows.length, undone: rows.filter(r => Number(r.undone) === 1).length };
	}, BASE + '/api/' + path);
}

/**
 * Performs an action that triggers exactly one booking call matching `urlPattern` and
 * `method`, and returns the parsed JSON body of that call's response - the stock_log rows
 * the booking wrote, per every controller method behind these buttons ("Returns the
 * stock_log rows of the resulting transaction").
 */
async function bookingResponse(page, urlPattern, method, act)
{
	const [response] = await Promise.all([
		page.waitForResponse(r => method === r.request().method() && urlPattern.test(r.url()), { timeout: 20000 }),
		act()
	]);
	return await response.json();
}

/**
 * Selects a product through the picker's own component API and lets its change chain
 * settle. SetId() triggers 'change' on the visible text input; the pages bind their
 * "product changed" chain - which is what fills the quantity unit and location selects -
 * to the hidden select behind it, so that one is triggered too.
 */
async function pickProduct(page, productId)
{
	await page.evaluate(id =>
	{
		Victual.Components.ProductPicker.SetId(id);
		Victual.Components.ProductPicker.GetPicker().trigger('change');
	}, productId);
	await page.waitForTimeout(2500);
}

/**
 * Fills a datetimepicker by typing into it, so the component's own keyup handler runs and
 * clears the custom validity. purchase and inventory both carry a required due date that
 * is only prefilled for products that have a default due-days setting.
 */
async function setDueDate(page, value)
{
	const sel = '#best_before_date input.form-control';
	if (await page.locator(sel).count() === 0) return;
	await page.fill(sel, '');
	await page.type(sel, value, { delay: 25 });
	await page.press(sel, 'End');
	await page.waitForTimeout(600);
}

/** The id of a product that has stock, so consume/transfer have something to book. */
async function productWithStock(browser)
{
	const p = await browser.newPage();
	await p.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
	const id = await p.evaluate(async base =>
	{
		const stock = await (await fetch(base + '/api/stock')).json();
		const row = stock.find(s => Number(s.amount) >= 10) || stock[0];
		return row.product_id;
	}, BASE);
	await p.close();
	return id;
}

/**
 * Issue #610: the stockentries scenario below only exercises the race it is meant to catch
 * (a sibling stock entry of the same product being refreshed - and redrawing the table -
 * while the just-consumed entry's own row is being hidden) when that product actually has a
 * second stock entry. The demo data's chosen product may or may not already have one, so
 * this purchases a small extra batch whenever /api/stock/products/{id}/entries reports
 * fewer than two, guaranteeing the fixture the scenario needs regardless of demo data.
 */
async function ensureTwoStockEntries(browser, productId)
{
	const p = await browser.newPage();
	await p.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
	await p.evaluate(async ({ base, id }) =>
	{
		const entries = await (await fetch(base + '/api/stock/products/' + id + '/entries', { credentials: 'same-origin' })).json();
		if (Array.isArray(entries) && entries.length >= 2) return;

		await fetch(base + '/api/stock/products/' + id + '/add', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ amount: 3, best_before_date: '2027-12-31' })
		});
	}, { base: BASE, id: productId });
	await p.close();
}

async function probe(browser, label, how, run)
{
	const page = await newPage(browser, label);
	try
	{
		const { booked, undone } = await run(page);
		record(label, how, booked, undone);
	}
	catch (e)
	{
		record(label, how, 0, 0, 'ERROR ' + e.message.split('\n')[0]);
	}
	await page.close();
}

(async () =>
{
	const browser = await chromium.launch({
		executablePath: process.env.CHROME_BIN || undefined,
		args: ['--no-sandbox']
	});

	const productId = await productWithStock(browser);

	// ---- stock overview: consume straight from the row -------------------------------
	// This is the page the plan singles out: it defines neither Undo helper and only
	// worked because its Blade view pushed purchase.js.
	await probe(browser, 'stockoverview', 'row consume button -> toast Undo', async page =>
	{
		await page.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/consume(\?|$)/, 'POST', () =>
			page.locator('a.product-consume-button:not(.disabled)').first().click());
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- consume page ----------------------------------------------------------------
	await probe(browser, 'consume', 'consume form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/consume', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		await page.fill('#display_amount', '1');
		await page.waitForTimeout(500);
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/consume(\?|$)/, 'POST', () =>
			page.click('#save-consume-button'));
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- purchase page ---------------------------------------------------------------
	await probe(browser, 'purchase', 'purchase form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/purchase', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		await page.fill('#display_amount', '2');
		await setDueDate(page, '2027-12-31');
		await page.waitForTimeout(500);
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/add(\?|$)/, 'POST', () =>
			page.click('#save-purchase-button'));
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- inventory page --------------------------------------------------------------
	await probe(browser, 'inventory', 'inventory form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/inventory', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		const current = Number(await page.inputValue('#display_amount')) || 0;
		await page.fill('#display_amount', String(current + 7));
		await page.dispatchEvent('#display_amount', 'keyup');
		await page.dispatchEvent('#display_amount', 'change');
		await setDueDate(page, '2027-12-31');
		await page.waitForTimeout(500);
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/inventory(\?|$)/, 'POST', () =>
			page.click('#save-inventory-button'));
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- transfer page ---------------------------------------------------------------
	await probe(browser, 'transfer', 'transfer form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/transfer', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		const from = await page.inputValue('#location_id_from');
		const to = await page.locator('#location_id_to option').evaluateAll(
			(els, f) => els.map(e => e.value).filter(v => v && v !== f)[0], from);
		await page.selectOption('#location_id_to', to);
		await page.fill('#display_amount', '1');
		await page.waitForTimeout(500);
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/transfer(\?|$)/, 'POST', () =>
			page.click('#save-transfer-button'));
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- stock entries: consume one entry ---------------------------------------------
	// The only page using UndoStockBookingEntry, whose behaviour genuinely differs.
	//
	// Issue #610: this scenario only exercises the race it is meant to catch - a *sibling*
	// stock entry of the same product being refreshed (and redrawing the whole table) while
	// the just-consumed entry's own row is being hidden - when the product actually carries
	// a second stock entry. ensureTwoStockEntries() guarantees that fixture regardless of
	// what the demo data happens to provide.
	await ensureTwoStockEntries(browser, productId);
	await probe(browser, 'stockentries', 'stock entry consume -> toast Undo (UndoStockBookingEntry)', async page =>
	{
		await page.goto(BASE + '/stockentries', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1200);
		const button = page.locator('a.stock-consume-button:not(.stock-consume-button-spoiled)').first();
		const stockRowId = await button.getAttribute('data-stockrow-id');
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/consume(\?|$)/, 'POST', () =>
			button.click());
		await waitForUndoToast(page);

		// Audit finding H10 / issue #499: this button consumes the entry's whole amount
		// (data-consume-amount), which deletes the row server-side. The success handler's
		// own RefreshStockEntryRow() re-fetches it right after and used to get a 200 "null"
		// body it read as "hide the row"; GET /stock/entry/{id} now answers the documented
		// 400 for a gone id instead, which must still hide the row rather than surface
		// DefaultErrorHandler's "A server error occured" toast. Waits for the row's own
		// d-none class or its removal from the DOM, rather than a fixed delay (issue #579):
		// a fixed 800ms raced animate.css 3.7's 500ms "faster" fade plus the refresh GET,
		// and either one running long on a busy CI runner made the wait too short. Issue
		// #610's fix removes the row through the DataTable's own API instead of a CSS fade,
		// so "gone from the DOM entirely" is as valid an end state as "present with d-none".
		await page.waitForFunction(id =>
		{
			const row = document.querySelector('#stock-' + id + '-row');
			const err = document.querySelector('#toast-container .toast-error');
			return !row || row.classList.contains('d-none') || err;
		}, stockRowId, { timeout: 15000 });

		if (await page.locator('#toast-container .toast-error').count() > 0)
		{
			throw new Error('the consumed entry\'s row refresh surfaced a server-error toast instead of hiding the row (H10 / issue #499)');
		}
		const rowLocator = page.locator('#stock-' + stockRowId + '-row');
		if (await rowLocator.count() > 0)
		{
			const rowClass = await rowLocator.getAttribute('class');
			if (!rowClass || !rowClass.split(/\s+/).includes('d-none'))
			{
				throw new Error('the consumed entry\'s row was neither removed nor hidden after its GET /stock/entry/{id} refresh (H10 / issue #499 / issue #610): class="' + rowClass + '"');
			}
		}

		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/bookings/' + booking[0].id);
	});

	// ---- stock entries: edit form's own Undo link --------------------------------------
	// Issue #575: stockentryform.js built this toast's Undo link from `result.id`, which is
	// undefined - PUT /stock/entry/{entryId} answers an array of stock_log rows, exactly
	// like every other booking endpoint this file exercises, not a single object with an
	// `id`. Opens the first entry's own edit dialog, resubmits it unchanged (a bare save is
	// a valid PUT: every field already holds the entry's current, valid value) and clicks
	// the resulting toast's Undo link the same way the consume scenario above does.
	await probe(browser, 'stockentry-edit', 'stock entry edit form -> toast Undo (UndoStockBookingEntry)', async page =>
	{
		await page.goto(BASE + '/stockentries', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1200);

		const [childFrame] = await Promise.all([
			page.waitForEvent('frameattached'),
			page.locator('a.show-as-dialog-link[href*="/stockentry/"]').first().click()
		]);
		await childFrame.waitForLoadState('load');

		// Waits for the modal's own scripts (datetimepickers, UserfieldsForm.Load()) to
		// finish setting up the fields Save's own validation reads. checkValidity() stays
		// false, and Save silently does nothing, until they have - clicking on a fixed
		// delay instead sometimes raced that setup and left bookingResponse() waiting for
		// a request that was never sent.
		await childFrame.waitForFunction(() =>
		{
			const form = document.querySelector('#stockentry-form');
			return !!form && form.checkValidity();
		}, { timeout: 15000 });

		const frame = page.frameLocator('iframe.embed-responsive');
		const booking = await bookingResponse(page, /\/api\/stock\/entry\/\d+(\?|$)/, 'PUT', () =>
			frame.locator('#save-stockentry-button').click());

		await waitForUndoToast(page);
		await clickUndoInToast(page);
		// EditStockEntry always writes two correlated rows - STOCK_EDIT_OLD and
		// STOCK_EDIT_NEW, sharing both a transaction_id and a correlation_id - and
		// UndoBooking() cascades to every row sharing the clicked one's correlation_id, so
		// the toast's single Undo link undoes both. Reading the transaction back (as the
		// transfer scenario above does, for the same two-row-per-booking reason) is what
		// actually checks that cascade rather than only the row named in the link.
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- meal plan --------------------------------------------------------------------
	// The meal plan's Undo toast only appears after consuming a meal plan entry whose
	// recipe is fully in stock, which the demo data does not reliably provide. The link
	// that toast renders is onclick="UndoStockTransaction('<id>')", so this books a
	// transaction and invokes exactly that global, on the loaded meal plan page.
	await probe(browser, 'mealplan', 'UndoStockTransaction() on the page, as its toast does', async page =>
	{
		await page.goto(BASE + '/mealplan', { waitUntil: 'networkidle' });
		const defined = await page.evaluate(() => typeof UndoStockTransaction === 'function');
		if (!defined) throw new Error('UndoStockTransaction is not defined on /mealplan');
		const transactionId = await page.evaluate(async (a) =>
		{
			const res = await fetch(a.base + '/api/stock/products/' + a.id + '/consume', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ amount: 1, spoiled: false })
			});
			return (await res.json())[0].transaction_id;
		}, { base: BASE, id: productId });
		await page.evaluate(id => UndoStockTransaction(id), transactionId);
		await page.waitForTimeout(2000);
		return readUndoneCount(page, 'stock/transactions/' + transactionId);
	});

	await browser.close();

	let failed = 0;
	console.log('');
	console.log('     %s %s %s  %s', 'page'.padEnd(14), 'booked', 'undone', 'how');
	for (const r of results)
	{
		const ok = r.booked > 0 && r.undone === r.booked && !r.note;
		if (!ok) failed++;
		console.log('%s %s %s %s  %s',
			ok ? 'PASS' : 'FAIL',
			r.page.padEnd(14),
			String(r.booked).padStart(6),
			String(r.undone).padStart(6),
			r.how + (r.note ? '  [' + r.note + ']' : ''));
	}
	console.log('\n%d/%d pages undid every row their booking wrote',
		results.length - failed, results.length);
	process.exit(failed === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
