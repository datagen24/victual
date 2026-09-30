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
//
// Issue #637: the consume, purchase and transfer scenarios used to wait a fixed 2500ms after
// picking a product for the page's product-changed chain (two to four chained API GETs that
// fill the quantity unit and location selects) and a further 500ms after filling the form,
// then click save. On a slow CI runner the chain outlasted the delay, the click landed on a
// form whose required location or unit select was still empty, the page's save handler
// returned without posting, and bookingResponse() timed out waiting for a POST that was
// never sent. Delaying every API GET by 1500ms reproduces exactly that failure locally. The
// scenarios now wait for the conditions the save actually needs - the page's API traffic
// going quiet, then the form reporting valid - and a failing scenario prints which fields
// were invalid, what the page last requested, and saves a screenshot.

const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8200')).replace(/\/$/, '');

// Where a failing scenario's screenshot goes; the tests workflow uploads this directory.
const FAILURE_DIR = require('path').join(__dirname, 'undo-toasts-failures');

const results = [];
function record(page, how, booked, undone, note)
{
	results.push({ page, how, booked, undone, note: note || '' });
}

async function newPage(browser, label)
{
	const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
	page.on('pageerror', e => console.log('   pageerror on ' + label + ': ' + e.message));

	// Issue #637: what apiQuiet() waits on, and what a failure report prints.
	page.apiInFlight = new Set();
	page.apiStarted = 0;
	page.apiLastActivity = Date.now();
	page.apiLog = [];
	page.consoleLog = [];
	const isApi = r => r.url().startsWith(BASE + '/api/');
	const settle = (r, outcome) =>
	{
		if (!page.apiInFlight.delete(r)) return;
		page.apiLastActivity = Date.now();
		page.apiLog.push(r.method() + ' ' + r.url().slice(BASE.length) + ' -> ' + outcome);
		if (page.apiLog.length > 30) page.apiLog.shift();
	};
	page.on('request', r =>
	{
		if (!isApi(r)) return;
		page.apiInFlight.add(r);
		page.apiStarted++;
		page.apiLastActivity = Date.now();
	});
	page.on('requestfinished', async r =>
	{
		const response = await r.response().catch(() => null);
		settle(r, response ? String(response.status()) : 'no response');
	});
	page.on('requestfailed', r => settle(r, 'failed: ' + (r.failure() || {}).errorText));
	page.on('console', m =>
	{
		page.consoleLog.push(m.type() + ': ' + m.text().slice(0, 200));
		if (page.consoleLog.length > 20) page.consoleLog.shift();
	});
	return page;
}

/**
 * Waits until the page has had no API request in flight for `quietMs`. The pages' change
 * chains issue each GET from the previous one's callback, so a short gap between two of
 * them is not the end of the chain; a quiet window is. With `startedBefore` (a prior
 * page.apiStarted), it first waits for a request to have started since then: Playwright can
 * deliver the 'request' event of a chain an evaluate() just triggered after that evaluate()
 * has already resolved, and a page that has been idle for `quietMs` would otherwise count
 * as settled before the chain began.
 */
async function apiQuiet(page, quietMs = 500, timeout = 20000, startedBefore = null)
{
	const deadline = Date.now() + timeout;
	while ((startedBefore !== null && page.apiStarted <= startedBefore)
		|| page.apiInFlight.size > 0 || Date.now() - page.apiLastActivity < quietMs)
	{
		if (Date.now() > deadline)
		{
			throw new Error(startedBefore !== null && page.apiStarted <= startedBefore
				? 'the change never issued an API request'
				: 'API traffic never went quiet: ' + page.apiInFlight.size + ' request(s) still in flight');
		}
		await page.waitForTimeout(100);
	}
}

/**
 * Waits for the form the save button submits to pass the same checkValidity() its click
 * handler checks first - an invalid form makes the handler return without posting, which
 * bookingResponse() can only report as a timeout.
 */
async function formValid(page, formId)
{
	await apiQuiet(page);
	await page.waitForFunction(id =>
	{
		const form = document.getElementById(id);
		return !!form && form.checkValidity();
	}, formId, { timeout: 15000 });
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
 * to the hidden select behind it, so that one is triggered too. Settled means the chain's
 * API GETs have finished (issue #637), not that a fixed delay has passed.
 */
async function pickProduct(page, productId)
{
	const startedBefore = page.apiStarted;
	await page.evaluate(id =>
	{
		Victual.Components.ProductPicker.SetId(id);
		Victual.Components.ProductPicker.GetPicker().trigger('change');
	}, productId);
	await apiQuiet(page, 500, 20000, startedBefore);
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

/**
 * The id of a product that has stock, so consume/transfer have something to book.
 *
 * Issue #637, second cause: GET /api/stock has no ORDER BY, so on PostgreSQL "the first row
 * with amount >= 10" was a different product from run to run. When it was a tare-weight
 * product (the demo data's Flour), the consume and transfer forms set min to the tare
 * weight, refused the amounts typed below, and never posted - the waitForResponse timeout
 * #638 attributed to a fixed delay. Tare-weight products are skipped and the rest ordered
 * by id, so every run books the same product.
 */
async function productWithStock(browser)
{
	const p = await browser.newPage();
	await p.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
	const id = await p.evaluate(async base =>
	{
		const stock = (await (await fetch(base + '/api/stock')).json())
			.filter(s => !s.product || Number(s.product.enable_tare_weight_handling) !== 1)
			.sort((a, b) => Number(a.product_id) - Number(b.product_id));
		const row = stock.find(s => Number(s.amount) >= 10) || stock[0];
		if (!row)
		{
			throw new Error('no product without tare-weight handling has stock');
		}
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

/**
 * Prints what the next occurrence of a failure needs to be diagnosed (issue #637): which
 * fields of each form on the page were invalid, the page's last API exchanges and console
 * lines, and a screenshot showing whether the save was clicked and what the form held.
 */
async function reportFailure(page, label)
{
	try
	{
		const forms = await page.evaluate(() => Array.from(document.forms).map(f => ({
			id: f.id,
			valid: f.checkValidity(),
			invalid: Array.from(f.elements).filter(el => el.willValidate && !el.checkValidity())
				.map(el => (el.id || el.name) + '=' + JSON.stringify(el.value) + ' (' + el.validationMessage + ')')
		})));
		console.log('   [debug #637] ' + label + ' forms: ' + JSON.stringify(forms));
		console.log('   [debug #637] ' + label + ' API requests in flight: ' + JSON.stringify(Array.from(page.apiInFlight).map(r => r.method() + ' ' + r.url().slice(BASE.length))));
		console.log('   [debug #637] ' + label + ' last API exchanges:\n      ' + page.apiLog.join('\n      '));
		console.log('   [debug #637] ' + label + ' console:\n      ' + page.consoleLog.join('\n      '));
		require('fs').mkdirSync(FAILURE_DIR, { recursive: true });
		const shot = require('path').join(FAILURE_DIR, label + '.png');
		await page.screenshot({ path: shot, fullPage: true });
		console.log('   [debug #637] ' + label + ' screenshot: ' + shot);
	}
	catch (e)
	{
		console.log('   [debug #637] ' + label + ' failure report itself failed: ' + e.message.split('\n')[0]);
	}
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
		await reportFailure(page, label);
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
		await formValid(page, 'consume-form');
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
		await formValid(page, 'purchase-form');
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
		await formValid(page, 'inventory-form');
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
		await formValid(page, 'transfer-form');
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/transfer(\?|$)/, 'POST', () =>
			page.click('#save-transfer-button'));
		await waitForUndoToast(page);
		await clickUndoInToast(page);
		return readUndoneCount(page, 'stock/transactions/' + booking[0].transaction_id);
	});

	// ---- stock entries: consume one entry ---------------------------------------------
	// The only page using UndoStockBookingEntry, whose behaviour genuinely differs.
	await probe(browser, 'stockentries', 'stock entry consume -> toast Undo (UndoStockBookingEntry)', async page =>
	{
		await page.goto(BASE + '/stockentries', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1200);

		// Issue #610 round 2: StockService's own docs (top of services/StockService.php)
		// say a batch is identified by its `stock_id`, not the row id - "splitting an
		// entry (partial open/transfer) creates additional rows sharing the same
		// stock_id". ConsumeProduct()'s stock_entry_id parameter scopes consumption to
		// that stock_id, not to one specific row - so if the row this scenario picks
		// shares its stock_id with another (undepleted) row, "consume this row's whole
		// displayed amount" can drain the *other* row first and leave this one untouched,
		// which is exactly what a repeat failure's diagnostics showed (PR #617, run
		// 36479777109: GET /stock/entry/81 kept answering 200 amount:1, unchanged from
		// before the consume - not a redraw race at all, a stock_id shared with a row
		// this scenario never clicked). Picking a row whose stock_id is unique among all
		// currently listed rows guarantees the H10 assumption this scenario relies on -
		// that clicking a row's own consume button fully depletes that specific row -
		// actually holds.
		const stockRowId = await page.evaluate(() =>
		{
			const counts = {};
			const rows = [];
			document.querySelectorAll('a.stock-consume-button:not(.stock-consume-button-spoiled)').forEach(el =>
			{
				const stockId = el.getAttribute('data-stock-id');
				counts[stockId] = (counts[stockId] || 0) + 1;
				rows.push({ rowId: el.getAttribute('data-stockrow-id'), stockId });
			});
			const unique = rows.find(r => counts[r.stockId] === 1);
			return unique ? unique.rowId : null;
		});
		if (!stockRowId)
		{
			throw new Error('no stock entry on /stockentries has a stock_id unique to its own row - every consume button would risk draining a sibling row instead');
		}
		const button = page.locator('a.stock-consume-button[data-stockrow-id="' + stockRowId + '"]:not(.stock-consume-button-spoiled)');

		// Issue #610: this scenario only exercises the race it is meant to catch - a
		// *sibling* stock entry of the same product being refreshed (and redrawing the
		// whole table) while the just-consumed entry's own row is being hidden - when the
		// product this row actually belongs to carries a second stock entry. The page is
		// unfiltered, so the first consume button is not necessarily for the shared
		// productId every other scenario books against; read the real product id off this
		// button, ensure its fixture, then reload so the new entry is in the table before
		// the same row is clicked.
		const consumedProductId = await button.getAttribute('data-product-id');
		await ensureTwoStockEntries(browser, consumedProductId);
		await page.reload({ waitUntil: 'networkidle' });
		await page.waitForTimeout(1200);

		// Each row renders two `.stock-consume-button` anchors sharing the same
		// data-stockrow-id - the plain consume button (views/stockentries.blade.php's
		// btn-danger anchor) and the "mark as spoiled" dropdown item
		// (.stock-consume-button-spoiled) - so this must exclude the spoiled one and stay
		// scoped to this row, exactly like the original locator above, or it resolves to
		// two elements.
		// Issue #610 round 2: CI still times out here intermittently even with the
		// synchronous-hide fix in place (evidence: PR #614, run 36475590815). Rather than
		// guess further at which of several plausible paths is responsible - a partial
		// consume leaving amount > 0, an unrelated 400, a stale/duplicate response - log
		// every response this row's own refresh GET receives, so a repeat failure's CI log
		// shows the actual server answer instead of just the timeout.
		const entryResponses = [];
		const entryResponseListener = async response =>
		{
			if (response.request().method() !== 'GET' || !new RegExp('/api/stock/entry/' + stockRowId + '(\\?|$)').test(response.url()))
			{
				return;
			}
			let body = '';
			try { body = (await response.text()).slice(0, 300); } catch (e) { body = '<unreadable: ' + e.message + '>'; }
			entryResponses.push({ status: response.status(), body, at: Date.now() });
		};
		page.on('response', entryResponseListener);

		const reloadedButton = page.locator('#stock-' + stockRowId + '-row a.stock-consume-button:not(.stock-consume-button-spoiled)');
		const booking = await bookingResponse(page, /\/api\/stock\/products\/\d+\/consume(\?|$)/, 'POST', () =>
			reloadedButton.click());
		await waitForUndoToast(page);

		// Audit finding H10 / issue #499: this button consumes the entry's whole amount
		// (data-consume-amount), which deletes the row server-side. The success handler's
		// own RefreshStockEntryRow() re-fetches it right after and used to get a 200 "null"
		// body it read as "hide the row"; GET /stock/entry/{id} now answers the documented
		// 400 for a gone id instead, which must still hide the row rather than surface
		// DefaultErrorHandler's "A server error occured" toast. Waits for the row's own
		// d-none class rather than a fixed delay (issue #579): a fixed 800ms raced
		// animate.css 3.7's 500ms "faster" fade plus the refresh GET, and either one
		// running long on a busy CI runner made the wait too short. Issue #610's fix
		// applies d-none synchronously rather than in an animationend callback that a
		// concurrent sibling-row redraw could cancel, and deliberately keeps the row's
		// node in the DOM (rather than removing it) so Undo can find and restore it below.
		try
		{
			await page.waitForFunction(id =>
			{
				const row = document.querySelector('#stock-' + id + '-row');
				const err = document.querySelector('#toast-container .toast-error');
				return (row && row.classList.contains('d-none')) || err;
			}, stockRowId, { timeout: 15000 });
		}
		catch (waitError)
		{
			const rowState = await page.evaluate(id =>
			{
				const row = document.querySelector('#stock-' + id + '-row');
				return row ? { found: true, className: row.className, amountText: (document.querySelector('#stock-' + id + '-amount') || {}).textContent } : { found: false };
			}, stockRowId);
			console.log('   [debug #610] row ' + stockRowId + ' GET /stock/entry responses: ' + JSON.stringify(entryResponses));
			console.log('   [debug #610] row ' + stockRowId + ' state at timeout: ' + JSON.stringify(rowState));
			page.off('response', entryResponseListener);
			throw waitError;
		}
		page.off('response', entryResponseListener);

		if (await page.locator('#toast-container .toast-error').count() > 0)
		{
			throw new Error('the consumed entry\'s row refresh surfaced a server-error toast instead of hiding the row (H10 / issue #499)');
		}
		const rowClass = await page.locator('#stock-' + stockRowId + '-row').getAttribute('class');
		if (!rowClass || !rowClass.split(/\s+/).includes('d-none'))
		{
			throw new Error('the consumed entry\'s row was not hidden after its GET /stock/entry/{id} refresh (H10 / issue #499): class="' + rowClass + '"');
		}

		// Issue #610: StockService::UndoBooking() rebuilds a whole-take consume's entry
		// under its original row id, and UndoStockBookingEntry()'s own "ProductChanged"
		// broadcast is how this page notices - RefreshStockEntryRow() gets a 200 for a row
		// it still has marked d-none, and reloads the page so the restored entry renders
		// normally. Wait for that reload (registering the waiter before the click, since
		// the reload itself follows a couple of message round trips after the undo POST
		// resolves) rather than assuming clickUndoInToast's own wait covers it, then assert
		// the row is back and visible instead of stuck hidden.
		const reloadWait = page.waitForEvent('load', { timeout: 20000 }).catch(() => null);
		await clickUndoInToast(page);
		await reloadWait;
		await page.waitForFunction(id =>
		{
			const row = document.querySelector('#stock-' + id + '-row');
			return !!row && !row.classList.contains('d-none');
		}, stockRowId, { timeout: 20000 });

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
