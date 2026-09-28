// Plan 12 verification check 2 (last item), and step 5a's acceptance test.
//
// Every stock booking toast carries an inline "Undo" link that calls a global -
// UndoStockTransaction, or UndoStockBookingEntry on the stock entries page. Those globals
// used to be copied into five page scripts, and three Blade views pushed purchase.js
// purely to import them; step 5 moved one of each into public/js/victual_stock_dialogs.js
// and deleted the pushes. This probe books stock on each page that shows such a toast,
// clicks the Undo link in the toast that page actually rendered, and asserts that the rows
// the booking wrote in stock_log came back with undone = 1.
//
//   node undo-toasts.js --url http://127.0.0.1:8200 --db "$VDATA/victual_en.db"
//
// stock_log has no read API, so the rows are read straight out of the database through
// `php -r`. It books and undoes real stock, so it needs a throwaway database. Exits
// non-zero if any page failed to undo, so it can be run as a gate.

const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8200')).replace(/\/$/, '');
const DB = arg('db', null);
if (!DB)
{
	console.error('--db <path to victual_en.db> is required');
	process.exit(2);
}

function query(sql)
{
	const php = '$d = new PDO("sqlite:" . $argv[1]);'
		+ ' foreach ($d->query($argv[2], PDO::FETCH_NUM) as $r) { echo json_encode($r), "\\n"; }';
	const out = execFileSync('php', ['-r', php, DB, sql], { encoding: 'utf8' });
	return out.trim().split('\n').filter(Boolean).map(JSON.parse);
}

function maxLogId()
{
	return Number(query('SELECT COALESCE(MAX(id), 0) FROM stock_log')[0][0]);
}

function rowsAfter(id)
{
	return query('SELECT id, transaction_type, undone FROM stock_log WHERE id > ' + id + ' ORDER BY id')
		.map(r => ({ id: Number(r[0]), type: r[1], undone: Number(r[2]) }));
}

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

/** Clicks the "Undo" anchor inside the toast the page just rendered. */
async function clickUndoInToast(page)
{
	const undo = page.locator('#toast-container a:has-text("Undo")').first();
	await undo.waitFor({ state: 'visible', timeout: 20000 });
	await undo.click();
	await page.waitForTimeout(2000);
}

async function waitForUndoToast(page)
{
	await page.waitForSelector('#toast-container a:has-text("Undo")', { timeout: 20000 });
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
		const before = maxLogId();
		await page.locator('a.product-consume-button:not(.disabled)').first().click();
		await waitForUndoToast(page);
		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
	});

	// ---- consume page ----------------------------------------------------------------
	await probe(browser, 'consume', 'consume form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/consume', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		await page.fill('#display_amount', '1');
		await page.waitForTimeout(500);
		const before = maxLogId();
		await page.click('#save-consume-button');
		await waitForUndoToast(page);
		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
	});

	// ---- purchase page ---------------------------------------------------------------
	await probe(browser, 'purchase', 'purchase form -> toast Undo', async page =>
	{
		await page.goto(BASE + '/purchase', { waitUntil: 'networkidle' });
		await pickProduct(page, productId);
		await page.fill('#display_amount', '2');
		await setDueDate(page, '2027-12-31');
		await page.waitForTimeout(500);
		const before = maxLogId();
		await page.click('#save-purchase-button');
		await waitForUndoToast(page);
		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
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
		const before = maxLogId();
		await page.click('#save-inventory-button');
		await waitForUndoToast(page);
		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
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
		const before = maxLogId();
		await page.click('#save-transfer-button');
		await waitForUndoToast(page);
		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
	});

	// ---- stock entries: consume one entry ---------------------------------------------
	// The only page using UndoStockBookingEntry, whose behaviour genuinely differs.
	await probe(browser, 'stockentries', 'stock entry consume -> toast Undo (UndoStockBookingEntry)', async page =>
	{
		await page.goto(BASE + '/stockentries', { waitUntil: 'networkidle' });
		await page.waitForTimeout(1200);
		const button = page.locator('a.stock-consume-button:not(.stock-consume-button-spoiled)').first();
		const stockRowId = await button.getAttribute('data-stockrow-id');
		const before = maxLogId();
		await button.click();
		await waitForUndoToast(page);

		// Audit finding H10 / issue #499: this button consumes the entry's whole amount
		// (data-consume-amount), which deletes the row server-side. The success handler's
		// own RefreshStockEntryRow() re-fetches it right after and used to get a 200 "null"
		// body it read as "hide the row"; GET /stock/entry/{id} now answers the documented
		// 400 for a gone id instead, which must still hide the row rather than surface
		// DefaultErrorHandler's "A server error occured" toast. Waited rather than asserted
		// immediately: RefreshStockEntryRow()'s own GET is a second request, fired
		// alongside the success toast rather than awaited by it.
		await page.waitForTimeout(800);
		if (await page.locator('#toast-container .toast-error').count() > 0)
		{
			throw new Error('the consumed entry\'s row refresh surfaced a server-error toast instead of hiding the row (H10 / issue #499)');
		}
		const rowClass = await page.locator('#stock-' + stockRowId + '-row').getAttribute('class');
		if (!rowClass || !rowClass.split(/\s+/).includes('d-none'))
		{
			throw new Error('the consumed entry\'s row was not hidden after its GET /stock/entry/{id} refresh (H10 / issue #499): class="' + rowClass + '"');
		}

		const booked = rowsAfter(before).length;
		await clickUndoInToast(page);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
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
		const before = maxLogId();
		const transactionId = await page.evaluate(async (a) =>
		{
			const res = await fetch(a.base + '/api/stock/products/' + a.id + '/consume', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ amount: 1, spoiled: false })
			});
			return (await res.json())[0].transaction_id;
		}, { base: BASE, id: productId });
		const booked = rowsAfter(before).length;
		await page.evaluate(id => UndoStockTransaction(id), transactionId);
		await page.waitForTimeout(2000);
		return { booked, undone: rowsAfter(before).filter(r => r.undone === 1).length };
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
