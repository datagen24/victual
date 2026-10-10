// The prescription refills page in a real browser (issue #701, ADR-0042, ADR-0015):
//
//   node consumption-refills.js --url http://127.0.0.1:8094 --admin-password <password>
//
// Run against a disposable instance in VICTUAL_MODE=production with a fresh database and
// VICTUAL_BOOTSTRAP_ADMIN_PASSWORD set before the first migration. It cannot run against the demo
// instance: demo and dev mode have one identity for every request, and this probe needs an owner, a
// member who can only read, a member who can edit and a user with no stock permission, each signing in
// with a password.
//
// What the PHP suites cannot see from below, each asserted on the visible page AND on the API or the
// stock ledger:
//
//   a. Permission and access. A user without stock permissions gets the 403 page. A member with a read
//      share sees the dates and none of the forms, and the API refuses the write the page hid.
//   b. The calendar date is the device's. The browser clock is fixed at 2026-03-18T01:00Z in
//      America/New_York, where the date is still 2026-03-17: every request carries as_of=2026-03-17 and
//      the page says the reorder date (2026-03-18) is approaching, while the server's own UTC date would
//      say it had passed. Moving the clock a day later turns the status to "reached".
//   c. The workflow. A fill, a rule and its range, a reorder date the person chooses and its removal, the
//      advance warning, an order, its cancellation, a second order and its receipt, a void with a reason,
//      and the notice and its "Mark as seen". After the receipt the explicit date stays gone, and no step
//      moves stock.
//   d. The words and the sinks. A prescription name, a fill note and a void reason seeded with an
//      HTML payload are shown as text and never as an element; no page text tells a person to act or says
//      a refill is allowed.
//   e. Revocation. After the owner removes a member's share, the member's next action is refused with the
//      server's sentence and the prescription leaves the list.
//   f. Accessibility. Fields have labels, the status is words and not colour, the live regions exist, the
//      detail heading takes focus, and Enter in a date field submits its form.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = arg('url', 'http://127.0.0.1:8094').replace(/\/$/, '');
const ADMIN_USER = arg('admin-user', 'admin');
const ADMIN_PASSWORD = arg('admin-password', process.env.VICTUAL_PROBE_ADMIN_PASSWORD || '');
const payload = '<img src=x onerror=window.__xss=1>';
const token = Date.now().toString(36);
const password = 'Probe-pass-' + token;
const FIXED_NOW = new Date('2026-03-18T01:00:00Z');
const stepsDone = [];

function step(name) { stepsDone.push(name); console.log('PASS ' + name); }

async function signIn(browser, username, pass, options = {})
{
	const context = await browser.newContext({ viewport: { width: 1400, height: 1600 }, timezoneId: 'America/New_York', ...options });
	const page = await context.newPage();
	page.on('dialog', dialog => dialog.accept());
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto(BASE + '/login');
	await page.locator('#username').fill(username);
	await page.locator('#password_input').fill(pass);
	await Promise.all([page.waitForNavigation(), page.locator('#login-button').click()]);
	assert.ok(!page.url().includes('/login'), username + ' signed in');
	return { context, page, errors };
}

async function apiRaw(page, path, method = 'GET', body)
{
	return page.evaluate(async ({ path, method, body }) =>
	{
		const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
		const text = await response.text();
		let parsed = null;
		try { parsed = text === '' ? null : JSON.parse(text); } catch (e) { parsed = text; }
		return { status: response.status, body: parsed };
	}, { path, method, body });
}

async function api(page, path, method = 'GET', body)
{
	const result = await apiRaw(page, path, method, body);
	if (result.status >= 400) throw new Error(method + ' ' + path + ' -> ' + result.status + ' ' + JSON.stringify(result.body));
	return result.body;
}

(async () =>
{
	assert.ok(ADMIN_PASSWORD, 'pass --admin-password or set VICTUAL_PROBE_ADMIN_PASSWORD');
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		// --- Fixtures, as the administrator -----------------------------------------------------
		const admin = await signIn(browser, ADMIN_USER, ADMIN_PASSWORD);
		const unit = (await api(admin.page, 'objects/quantity_units', 'POST', { name: 'Refill dose ' + token, name_plural: 'Refill doses ' + token })).created_object_id;
		const location = (await api(admin.page, 'objects/locations', 'POST', { name: 'Refill organizer ' + token })).created_object_id;
		const product = (await api(admin.page, 'objects/products', 'POST', { name: 'Refill product ' + token, location_id: location, qu_id_purchase: unit, qu_id_stock: unit, qu_id_consume: unit, qu_id_price: unit })).created_object_id;
		await api(admin.page, 'stock/products/' + product + '/add', 'POST', { amount: 40, transaction_type: 'purchase', location_id: location });

		const permissionIds = {};
		async function makeUser(name, permissions)
		{
			await api(admin.page, 'users', 'POST', { username: name, password });
			const user = (await api(admin.page, 'users')).find(u => u.username === name);
			const rows = await api(admin.page, 'users/' + user.id + '/permissions');
			rows.forEach(row => { permissionIds[row.permission_name] = row.permission_id; });
			await api(admin.page, 'users/' + user.id + '/permissions', 'PUT', { permissions: permissions.map(n => { assert.ok(permissionIds[n], n); return permissionIds[n]; }) });
			const effective = (await api(admin.page, 'users/' + user.id + '/permissions')).filter(p => Number(p.has_permission) === 1).map(p => p.permission_name).sort();
			assert.deepEqual(effective, [...permissions].sort(), 'the fixture user holds exactly the intended permissions');
			return user;
		}

		const owner = await makeUser('refill-owner-' + token, ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT']);
		const reader = await makeUser('refill-reader-' + token, ['STOCK_VIEW']);
		const editor = await makeUser('refill-editor-' + token, ['STOCK_VIEW']);
		const nobody = await makeUser('refill-none-' + token, ['USERS_EDIT_SELF']);

		// The owner's session has its browser clock fixed: 2026-03-18 01:00 UTC is 2026-03-17 21:00 in New York.
		const ownerSession = await signIn(browser, owner.username, password);
		await ownerSession.context.clock.setFixedTime(FIXED_NOW);
		const page = ownerSession.page;
		const requests = [];
		page.on('request', request => { if (request.url().includes('/api/')) requests.push({ method: request.method(), url: request.url() }); });

		const recipe = (await api(page, 'consumption/recipes', 'POST', { name: payload + ' Refill recipe ' + token, lines: [{ product_id: product, amount: 1, qu_id: unit }] })).created_object_id;
		// The recipe routes run a body through the HTML purifier, so the stored name is the purified form of the
		// payload, still markup-shaped text. The page must show exactly what is stored, as text.
		const recipeName = (await api(page, 'consumption/recipes/' + recipe)).name;
		assert.match(recipeName, /<img/, 'the stored name still looks like markup');
		await api(page, 'consumption/recipes/' + recipe + '/shares', 'POST', { user_id: reader.id });
		await api(page, 'consumption/recipes/' + recipe + '/shares', 'POST', { user_id: editor.id, edit: true });
		const stock = async () => Number((await api(page, 'stock/products/' + product)).stock_amount);
		const stockAtStart = await stock();

		// --- a. Permission and access -----------------------------------------------------------
		const nobodySession = await signIn(browser, nobody.username, password);
		const denied = await nobodySession.page.goto(BASE + '/consumptionrefills');
		assert.equal(denied.status(), 403, 'a user without stock permissions gets the 403 page');
		assert.equal(await nobodySession.page.locator('#refill-rows').count(), 0, 'and no table');
		assert.equal((await apiRaw(nobodySession.page, 'refills')).status, 403);
		await nobodySession.context.close();

		await api(page, 'consumption/recipes/' + recipe + '/refill/fills', 'POST', { filled_on: '2026-01-01', supplied_days: 90, note: payload + ' first fill' });
		const readerSession = await signIn(browser, reader.username, password);
		await readerSession.page.goto(BASE + '/consumptionrefills');
		const readerRow = readerSession.page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"]');
		await readerRow.waitFor();
		await readerRow.locator('.refill-details').click();
		await readerSession.page.locator('#refill-detail:not(.d-none)').waitFor();
		assert.equal(await readerSession.page.locator('#refill-forms').isVisible(), false, 'a member with a read share sees no forms');
		assert.equal(await readerSession.page.locator('#refill-read-only').isVisible(), true, 'and is told why');
		assert.equal(await readerSession.page.locator('#refill-fills-rows .refill-void').count(), 0, 'and no void buttons');
		assert.match(await readerSession.page.locator('#refill-summary').innerText(), /2026-03-18/, 'the reader sees the estimate');
		const readerWrite = await apiRaw(readerSession.page, 'consumption/recipes/' + recipe + '/refill/fills', 'POST', { filled_on: '2026-03-01', supplied_days: 30 });
		assert.equal(readerWrite.status, 403, 'the API refuses the write the page hid');
		assert.equal(readerWrite.body.error, 'right_missing');
		assert.deepEqual(readerSession.errors, []);
		await readerSession.context.close();
		step('a. permission: no stock permission gets 403; a read share sees the dates with no forms and the API refuses the write');

		// --- b. The device's calendar date -------------------------------------------------------
		await page.goto(BASE + '/consumptionrefills');
		const row = page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"]');
		await row.waitFor();
		assert.equal(await row.locator('.refill-name').innerText(), recipeName, 'the name is shown as typed');
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'the payload in the name did not run');
		assert.equal(await row.locator('img').count(), 0, 'and is no element');
		assert.equal(await row.getAttribute('data-status'), 'approaching', 'on the device date, 2026-03-17, the reorder date 2026-03-18 is approaching');
		assert.match(await row.locator('.refill-status').innerText(), /Reorder date approaching/);
		assert.equal(await row.locator('.refill-date').innerText(), '2026-03-18');
		assert.match(await row.locator('.refill-source').innerText(), /Fill date plus days supplied, minus 14 days/);
		const asOfSeen = requests.filter(r => r.url.includes('/api/refills')).map(r => new URL(r.url).searchParams.get('as_of'));
		assert.ok(asOfSeen.length >= 2 && asOfSeen.every(v => v === '2026-03-17'), 'every refill read carries the device date as_of=2026-03-17: ' + JSON.stringify(asOfSeen));
		const serverView = await api(page, 'refills');
		assert.equal(serverView.as_of_source, 'server_utc', 'without as_of the server says it used its own UTC date');
		step('b. the device date: as_of=2026-03-17 is sent and decides the status');

		// --- c. The workflow ----------------------------------------------------------------------
		const notice = page.locator('#refill-notices li.refill-notice');
		await notice.waitFor();
		assert.match(await notice.innerText(), /Estimated reorder date 2026-03-18 \(from your last fill\)/);
		assert.doesNotMatch(await notice.innerText(), /\b(take|stop|should|must|eligible|insurance|covered|urgent|overdue)\b/i);
		await notice.locator('.refill-ack').click();
		await page.locator('#refill-message').filter({ hasText: 'Notice marked as seen.' }).waitFor();
		await page.locator('#refill-notices-empty:not(.d-none)').waitFor();
		assert.equal((await api(page, 'refills/notices?as_of=2026-03-17')).notices.length, 0, 'the API agrees the notice is acknowledged');

		await row.locator('.refill-details').click();
		await page.locator('#refill-detail:not(.d-none)').waitFor();
		assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.id), 'refill-detail-title', 'the detail heading takes focus');
		assert.match(await page.locator('#refill-detail-title').innerText(), /Refill recipe/);
		const firstFill = page.locator('#refill-fills-rows tr.refill-fill').first();
		assert.equal(await firstFill.locator('.refill-fill-note').innerText(), payload + ' first fill', 'a stored note is shown as typed');
		assert.equal(await page.locator('#refill-fills-rows img').count(), 0);
		assert.equal(await page.evaluate(() => window.__xss), undefined);

		// A rule, and its range.
		await page.locator('#refill-rule-kind').selectOption('fixed_interval');
		await page.locator('#refill-rule-parameter').fill('0');
		await page.locator('#refill-rule-save').click();
		await page.locator('#refill-error').filter({ hasText: 'The value must be a whole number from 1 to 730.' }).waitFor();
		await page.locator('#refill-rule-parameter').fill('731');
		await page.evaluate(() => { document.getElementById('refill-error').textContent = ''; });
		await page.locator('#refill-rule-save').click();
		await page.locator('#refill-error').filter({ hasText: 'The value must be a whole number from 1 to 730.' }).waitFor();
		assert.equal((await api(page, 'consumption/recipes/' + recipe + '/refill?as_of=2026-03-17')).settings.rule, null, 'a refused value stored nothing');
		await page.locator('#refill-rule-parameter').fill('60');
		await page.locator('#refill-rule-save').click();
		await page.locator('#refill-message').filter({ hasText: 'Rule saved.' }).waitFor();
		assert.match(await page.locator('#refill-summary').innerText(), /2026-03-02/, 'the rule moved the estimate to 60 days after the fill');
		assert.match(await page.locator('#refill-summary').innerText(), /Rule: 60 days after the fill date/);

		// A reorder date the person chooses, and its removal.
		await page.locator('#refill-date-input').fill('2026-02-10');
		await page.locator('#refill-date-input').press('Enter');
		await page.locator('#refill-message').filter({ hasText: 'Reorder date saved.' }).waitFor();
		assert.match(await page.locator('#refill-summary').innerText(), /The date you entered/);
		await page.locator('#refill-date-clear').click();
		await page.locator('#refill-message').filter({ hasText: 'Reorder date removed.' }).waitFor();
		assert.match(await page.locator('#refill-summary').innerText(), /Rule: 60 days after the fill date/, 'the rule applies again');

		// Advance warning for this prescription.
		await page.locator('#refill-lead-input').fill('61');
		await page.locator('#refill-lead-save').click();
		await page.locator('#refill-error').filter({ hasText: 'Advance warning must be a whole number from 0 to 60.' }).waitFor();
		await page.locator('#refill-lead-input').fill('3');
		await page.locator('#refill-lead-save').click();
		await page.locator('#refill-message').filter({ hasText: 'Advance warning saved.' }).waitFor();
		assert.equal((await api(page, 'consumption/recipes/' + recipe + '/refill?as_of=2026-03-17')).estimate.lead_days, 3);
		await page.locator('#refill-lead-clear').click();
		await page.locator('#refill-message').filter({ hasText: 'Advance warning saved.' }).waitFor();
		assert.equal((await api(page, 'consumption/recipes/' + recipe + '/refill?as_of=2026-03-17')).settings.warning_lead_days, null);

		// Back to the fallback, then an order.
		await page.locator('#refill-rule-kind').selectOption('');
		await page.locator('#refill-rule-save').click();
		await page.locator('#refill-message').filter({ hasText: 'Rule saved.' }).waitFor();
		assert.equal(await page.locator('#refill-rule-parameter').isDisabled(), true, 'no rule means no value to enter');
		await page.locator('#refill-order-date').fill('2026-03-17');
		await page.locator('#refill-order-record').click();
		await page.locator('#refill-message').filter({ hasText: 'Order recorded.' }).waitFor();
		assert.match(await page.locator('#refill-summary').innerText(), /Order recorded/);
		assert.equal(await page.locator('#refill-order-record').isDisabled(), true, 'a second open order is not offered');
		assert.equal(await page.locator('#refill-fills-rows tr.refill-fill').count(), 1, 'the order recorded no fill');
		assert.equal(await stock(), stockAtStart, 'and moved no stock');
		await page.locator('#refill-order-cancel').click();
		await page.locator('#refill-message').filter({ hasText: 'Order cancelled.' }).waitFor();
		assert.match(await page.locator('#refill-summary').innerText(), /Reorder date approaching/);
		await page.locator('#refill-order-record').click();
		await page.locator('#refill-message').filter({ hasText: 'Order recorded.' }).waitFor();

		// Receive it with the fill that arrived.
		await page.locator('#refill-fill-date').fill('2026-03-17');
		await page.locator('#refill-fill-days').fill('30');
		await page.locator('#refill-fill-note').fill(payload + ' received');
		await page.locator('#refill-fill-receive').click();
		await page.locator('#refill-message').filter({ hasText: 'Order received and fill recorded.' }).waitFor();
		assert.equal(await page.locator('#refill-fills-rows tr.refill-fill').count(), 2, 'receiving recorded the fill');
		assert.match(await page.locator('#refill-summary').innerText(), /2026-04-02/, 'the estimate is calculated from the new fill: 2026-03-17 plus 16 days (30 days supplied, minus 14)');
		assert.equal(await page.locator('#refill-fill-receive').isVisible(), false, 'and there is no open order to receive');
		assert.equal(await stock(), stockAtStart, 'receiving an order is not a purchase');
		assert.equal(await page.locator('#refill-orders-rows tr[data-state="received"]').count(), 1);

		// Void the new fill, with a reason that is an HTML payload.
		await page.locator('#refill-fills-rows tr.refill-fill').first().locator('.refill-void').click();
		await page.locator('#refill-void-modal.show').waitFor();
		await page.locator('#refill-void-confirm').click();
		await page.locator('#refill-void-error').filter({ hasText: 'Enter a reason.' }).waitFor();
		await page.locator('#refill-void-reason').fill(payload + ' wrong supplier');
		await page.locator('#refill-void-confirm').click();
		await page.locator('#refill-message').filter({ hasText: 'Fill voided.' }).waitFor();
		const voided = page.locator('#refill-fills-rows tr[data-voided="1"]');
		assert.equal(await voided.count(), 1);
		assert.equal(await voided.locator('.refill-fill-state').innerText(), 'Voided: ' + payload + ' wrong supplier', 'the reason is shown as typed');
		assert.equal(await page.locator('#refill-fills-rows img').count(), 0);
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'no stored text ran');
		assert.match(await page.locator('#refill-summary').innerText(), /2026-03-18/, 'the older fill is current again');
		assert.equal(await page.locator('#refill-fills-rows tr.refill-fill').count(), 2, 'both fills stay in the history');

		// The default advance warning.
		await page.locator('#refill-lead-default').fill('61');
		await page.locator('#refill-lead-default-save').click();
		await page.locator('#refill-error').filter({ hasText: 'Advance warning must be a whole number from 0 to 60.' }).waitFor();
		await page.locator('#refill-lead-default').fill('14');
		await page.locator('#refill-lead-default-save').click();
		await page.locator('#refill-message').filter({ hasText: 'Advance warning saved.' }).waitFor();
		assert.equal((await api(page, 'user/settings/refill_warning_lead_days')).value, '14');
		assert.equal((await api(page, 'consumption/recipes/' + recipe + '/refill?as_of=2026-03-17')).estimate.lead_days, 14, 'and applies to a prescription with no value of its own');
		await api(page, 'user/settings/refill_warning_lead_days', 'DELETE', {});
		step('c. the workflow: fill, rule range, chosen date, advance warning, order, cancel, receive, void, notice, no stock moved');

		// A day later on the device the same stored dates read as reached.
		await ownerSession.context.clock.setFixedTime(new Date('2026-03-18T05:00:00Z'));
		await page.reload();
		await page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"][data-status="due"]').waitFor();
		assert.match(await page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"] .refill-status').innerText(), /Reorder date reached/);
		assert.match(await page.locator('#refill-notices').innerText(), /Reorder date reached: estimated 2026-03-18 \(from your last fill\)/);
		step('b. (continued) one device date later the status reads reached, with the same stored dates');

		// --- d. Words ---------------------------------------------------------------------------
		const bodyText = await page.locator('main, #content, body').first().innerText();
		assert.doesNotMatch(bodyText, /Time to reorder|Reorder now|You are running out|Refill eligible|Insurance allows|You missed/i, 'none of the words ADR-0015 rules out');
		assert.match(bodyText, /does not say that a pharmacy or an insurer will allow a refill/);
		step('d. the words: the estimate is described as an estimate and no instruction or approval is shown');

		// --- f. Accessibility ---------------------------------------------------------------------
		await page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"] .refill-details').click();
		await page.locator('#refill-detail:not(.d-none)').waitFor();
		for (const label of ['Filled on', 'Days supplied', 'Note', 'Rule for this prescription', 'Value', 'Reorder date', 'Days of advance warning', 'Ordered on'])
		{
			assert.ok(await page.getByLabel(label, { exact: true }).count() >= 1, 'a field is labelled "' + label + '"');
		}
		assert.equal(await page.locator('#refill-message').getAttribute('role'), 'status');
		assert.equal(await page.locator('#refill-error').getAttribute('role'), 'alert');
		assert.ok(await page.locator('#refill-table th[scope="col"]').count() >= 5, 'the table has column headers');
		assert.ok((await page.locator('#refill-rows tr .refill-status').first().innerText()).length > 3, 'the status is words, not only a colour');
		for (const button of await page.locator('#refill-rows .refill-details').all()) assert.match(await button.getAttribute('aria-label'), /^Details of /, 'a button names its prescription');
		assert.deepEqual(ownerSession.errors, [], 'no page error');
		step('f. accessibility: labels, live regions, headers, focus, status as words');

		// --- e. Revocation ------------------------------------------------------------------------
		const editorSession = await signIn(browser, editor.username, password);
		await editorSession.page.goto(BASE + '/consumptionrefills');
		const editorRow = editorSession.page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"]');
		await editorRow.waitFor();
		await editorRow.locator('.refill-details').click();
		await editorSession.page.locator('#refill-forms').waitFor({ state: 'visible' });
		await api(page, 'consumption/recipes/' + recipe + '/shares/' + editor.id, 'DELETE', {});
		await editorSession.page.locator('#refill-fill-date').fill('2026-03-18');
		await editorSession.page.locator('#refill-fill-days').fill('30');
		await editorSession.page.locator('#refill-fill-record').click();
		await editorSession.page.locator('#refill-error').filter({ hasText: 'Consumption recipe does not exist' }).waitFor();
		await editorSession.page.locator('#refill-rows tr[data-recipe-id="' + recipe + '"]').waitFor({ state: 'detached' });
		assert.equal(await editorSession.page.locator('#refill-detail').isVisible(), false, 'the prescription left the editor\'s list and its panel closed');
		assert.equal((await api(page, 'consumption/recipes/' + recipe + '/refill?as_of=2026-03-18')).fills.length, 2, 'nothing was recorded after the revoke');
		assert.deepEqual(editorSession.errors, []);
		await editorSession.context.close();
		step('e. revocation: the removed member is refused with the server\'s sentence and the prescription leaves their list');

		await ownerSession.context.close();
		await admin.context.close();
		console.log('\nCONSUMPTION REFILLS BROWSER CHECKS PASSED (' + stepsDone.length + ' scenarios)');
	}
	finally
	{
		await browser.close();
	}
})().catch(error =>
{
	console.error('\nFAILED after: ' + stepsDone.join(' | '));
	console.error(error);
	process.exit(1);
});
