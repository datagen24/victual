// The consumption inbox in a real browser (issue #700, ADR-0041 rules 8 and 9, ADR-0015):
//
//   node consumption-inbox.js --url http://127.0.0.1:8093 --admin-password <password>
//
// Run against a disposable instance in VICTUAL_MODE=production with a fresh database and
// VICTUAL_BOOTSTRAP_ADMIN_PASSWORD set before the first migration. It cannot run against the demo
// instance the other probes share: demo and dev mode have exactly one identity for every request, and
// this probe needs a person with no stock permission, one with STOCK_VIEW only and one who may resolve,
// each signing in with a password, plus that person's API key for the external client's request.
//
// What the PHP suites cannot see from below, each asserted on the visible page AND on the API or the
// stock ledger:
//
//   a. Permission. A user without stock permissions gets the 403 page. A user with STOCK_VIEW only sees
//      the inbox with every action disabled and the explanation visible, and an API attempt is refused
//      with nothing changed.
//   b. Explicit linkage. A manual consumption of a consumption recipe is recorded through the recipes page.
//      An external event for the same product is PUT through the API with the user's API key and books.
//      The inbox lists it with the manual transaction as a possible duplicate. A transaction id that
//      cannot be linked shows the server's sentence and changes nothing; Link with the suggestion then
//      shows the event linked and exactly one deduction remains.
//   c. Rebook after undo. The user undoes a booked event in the stock journal page, a replay of the same
//      request leaves it undone, the inbox lists it under "undone" with Book again, and clicking it
//      deducts stock once more.
//   d. A needs_mapping event, a stale action that the server refuses with 409 invalid_transition, and a
//      dismiss.
//   e. The words and the sinks: a unit label sent by the source is shown as text, never as an element
//      (S29, AGENTS.md); the ambiguous-location candidates are shown as location names; approve_unit
//      books. A bulk dismiss of 55 events loops until the server reports none remaining.
//
//   f. The medication filter groups rows by the medication_ref the API returns.
const { chromium, request: playwrightRequest } = require('playwright');
const assert = require('node:assert/strict');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = arg('url', 'http://127.0.0.1:8093').replace(/\/$/, '');
const ADMIN_USER = arg('admin-user', 'admin');
const ADMIN_PASSWORD = arg('admin-password', process.env.VICTUAL_PROBE_ADMIN_PASSWORD || '');
const payload = '<img src=x onerror=window.__xss=1>';
const token = Date.now().toString(36);
const password = 'Probe-pass-' + token;
const SYSTEM = 'healthkit';
const stepsDone = [];

function step(name) { stepsDone.push(name); console.log('PASS ' + name); }

async function signIn(browser, username, pass, options = {})
{
	const context = await browser.newContext({ viewport: { width: 1400, height: 1400 }, timezoneId: 'America/New_York', ...options });
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

async function newApiKey(page, description)
{
	const html = await page.evaluate(async description =>
	{
		const response = await fetch('/manageapikeys/new', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'description=' + encodeURIComponent(description) });
		return response.text();
	}, description);
	const match = html.match(/id="new-api-key-value">([^<]+)</);
	assert.ok(match, 'the new API key is shown once on the response page');
	return match[1];
}

// The external client: a request carrying only the user's API key, no session cookie.
async function externalClient(key)
{
	const client = await playwrightRequest.newContext({ baseURL: BASE, extraHTTPHeaders: { 'VICTUAL-API-KEY': key, 'Content-Type': 'application/json' } });
	return {
		async put(id, body) { const r = await client.put('/api/consumption/events/' + SYSTEM + '/' + encodeURIComponent(id), { data: body }); return { status: r.status(), body: await r.json() }; },
		async mapping(ref, body) { const r = await client.put('/api/consumption/mappings/' + SYSTEM + '/' + encodeURIComponent(ref), { data: body }); return { status: r.status(), body: await r.json() }; },
		async batch(events) { const r = await client.post('/api/consumption/events/batch', { data: { events } }); return { status: r.status(), body: await r.json() }; },
		async raw(method, path, body) { const r = await client.fetch('/api/' + path, { method, data: body }); return { status: r.status(), text: await r.text() }; },
		dispose: () => client.dispose()
	};
}

const minutesAgo = minutes => new Date(Date.now() - minutes * 60000).toISOString().replace(/\.\d+Z$/, 'Z');
const takenEvent = (ref, extra = {}) => ({ status: 'taken', medication_ref: ref, quantity: 1, unit_label: 'dose', occurred_at: minutesAgo(5), ...extra });

(async () =>
{
	assert.ok(ADMIN_PASSWORD, 'pass --admin-password or set VICTUAL_PROBE_ADMIN_PASSWORD');
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	const clients = [];

	try
	{
		// --- Fixtures, as the administrator -----------------------------------------------------
		const admin = await signIn(browser, ADMIN_USER, ADMIN_PASSWORD);
		const unit = (await api(admin.page, 'objects/quantity_units', 'POST', { name: 'Probe dose ' + token, name_plural: 'Probe doses ' + token })).created_object_id;
		const organizerA = (await api(admin.page, 'objects/locations', 'POST', { name: 'Inbox organizer A ' + token })).created_object_id;
		const organizerB = (await api(admin.page, 'objects/locations', 'POST', { name: 'Inbox organizer B ' + token })).created_object_id;
		const productBody = name => ({ name: name + ' ' + token, location_id: organizerA, qu_id_purchase: unit, qu_id_stock: unit, qu_id_consume: unit, qu_id_price: unit });
		const product = (await api(admin.page, 'objects/products', 'POST', productBody('Inbox product'))).created_object_id;
		const spread = (await api(admin.page, 'objects/products', 'POST', productBody('Inbox spread product'))).created_object_id;
		await api(admin.page, 'stock/products/' + product + '/add', 'POST', { amount: 200, transaction_type: 'purchase', location_id: organizerA });
		await api(admin.page, 'stock/products/' + spread + '/add', 'POST', { amount: 10, transaction_type: 'purchase', location_id: organizerA });
		await api(admin.page, 'stock/products/' + spread + '/add', 'POST', { amount: 10, transaction_type: 'purchase', location_id: organizerB });

		const permissionIds = {};
		async function makeUser(name, permissions)
		{
			await api(admin.page, 'users', 'POST', { username: name, password });
			const user = (await api(admin.page, 'users')).find(u => u.username === name);
			const rows = await api(admin.page, 'users/' + user.id + '/permissions');
			rows.forEach(row => { permissionIds[row.permission_name] = row.permission_id; });
			await setPermissions(user.id, permissions);
			return user;
		}
		async function setPermissions(userId, permissions)
		{
			await api(admin.page, 'users/' + userId + '/permissions', 'PUT', { permissions: permissions.map(name => { assert.ok(permissionIds[name], name); return permissionIds[name]; }) });
			const effective = (await api(admin.page, 'users/' + userId + '/permissions')).filter(p => Number(p.has_permission) === 1).map(p => p.permission_name).sort();
			assert.deepEqual(effective, [...permissions].sort(), 'the fixture user holds exactly the intended permissions');
		}

		const full = await makeUser('inbox-full-' + token, ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT']);
		const viewer = await makeUser('inbox-view-' + token, ['STOCK_VIEW', 'STOCK_CONSUME']);
		const nobody = await makeUser('inbox-none-' + token, ['USERS_EDIT_SELF']);

		// --- a. Permission ---------------------------------------------------------------------
		const viewerSession = await signIn(browser, viewer.username, password);
		const viewerKey = await newApiKey(viewerSession.page, 'inbox probe viewer');
		const viewerClient = await externalClient(viewerKey);
		clients.push(viewerClient);
		const viewerEvent = 'view-needs-mapping-' + token;
		const putByViewer = await viewerClient.put(viewerEvent, takenEvent('unmapped-view-' + token));
		assert.equal(putByViewer.status, 201);
		assert.equal(putByViewer.body.state, 'needs_mapping');
		await setPermissions(viewer.id, ['STOCK_VIEW']);

		const nobodySession = await signIn(browser, nobody.username, password);
		const denied = await nobodySession.page.goto(BASE + '/consumptioninbox');
		assert.equal(denied.status(), 403, 'a user without stock permissions gets the 403 page');
		assert.equal(await nobodySession.page.locator('#inbox-rows').count(), 0, 'and no inbox');
		assert.equal((await apiRaw(nobodySession.page, 'consumption/events')).status, 403);
		await nobodySession.context.close();

		await viewerSession.page.goto(BASE + '/consumptioninbox');
		const viewerRow = viewerSession.page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + viewerEvent + '"]');
		await viewerRow.waitFor();
		assert.match(await viewerRow.locator('.inbox-state').innerText(), /Not booked: no mapping for this medication/);
		await viewerSession.page.locator('#inbox-permission-note:not(.d-none)').waitFor();
		const viewerButtons = viewerRow.locator('button.inbox-action');
		assert.ok(await viewerButtons.count() >= 2, 'the actions are present, not hidden');
		for (let i = 0; i < await viewerButtons.count(); i++) assert.equal(await viewerButtons.nth(i).isDisabled(), true, 'action ' + i + ' is disabled');
		assert.match(await viewerButtons.first().getAttribute('title'), /permission to record consumption/);
		assert.equal(await viewerSession.page.locator('#inbox-bulk-run').isDisabled(), true);
		const refused = await apiRaw(viewerSession.page, 'consumption/events/' + SYSTEM + '/' + viewerEvent + '/resolve', 'POST', { action: 'dismiss' });
		assert.equal(refused.status, 403, 'the API refuses what the page disabled');
		assert.equal((await apiRaw(viewerSession.page, 'consumption/events/' + SYSTEM + '/' + viewerEvent)).body.state, 'needs_mapping', 'and nothing changed');
		assert.equal((await viewerClient.put('another-' + token, takenEvent('x'))).status, 403, 'a write with the key is refused too');
		assert.deepEqual(viewerSession.errors, []);
		await viewerSession.context.close();
		step('a. permission: no stock permission gets 403; STOCK_VIEW only sees disabled actions and the API refuses');

		// --- The resolving user ----------------------------------------------------------------
		const { page, errors, context } = await signIn(browser, full.username, password);
		const key = await newApiKey(page, 'inbox probe');
		const client = await externalClient(key);
		clients.push(client);

		const stock = async id => Number((await api(page, 'stock/products/' + id)).stock_amount);
		const recipe = (await api(page, 'consumption/recipes', 'POST', { name: 'Inbox recipe ' + token, lines: [{ product_id: product, amount: 1, qu_id: unit }] })).created_object_id;
		const location = { mode: 'fixed', location_id: organizerA };
		const mappingBody = (extra = {}) => ({ recipe_id: recipe, unit_labels: ['dose'], location, effective_from: '2026-01-01T00:00:00Z', ...extra });
		const mapped = async (ref, body) =>
		{
			const result = await client.mapping(ref, body);
			assert.ok([200, 201].includes(result.status), 'mapping ' + ref + ' -> ' + result.status + ' ' + JSON.stringify(result.body));
		};
		for (const ref of ['link-' + token, 'undo-' + token]) await mapped(ref, mappingBody());
		await mapped('unit-' + token, { product_id: product, unit_labels: ['dose'], location, effective_from: '2026-01-01T00:00:00Z' });
		await mapped('spread-' + token, { product_id: spread, unit_labels: ['dose'], location: { mode: 'single' }, effective_from: '2026-01-01T00:00:00Z' });

		// --- b. Explicit linkage ---------------------------------------------------------------
		const start = await stock(product);
		await page.goto(BASE + '/consumptionrecipes');
		const recipeRow = page.locator('#consumption-rows tr').filter({ hasText: 'Inbox recipe ' + token });
		await recipeRow.locator('.consumption-consume-button').click();
		await page.locator('#consumption-consume-modal.show').waitFor();
		await page.locator('#consumption-location').selectOption({ label: 'Inbox organizer A ' + token });
		await page.locator('#consumption-consume').click();
		await page.locator('#consumption-message').filter({ hasText: 'Consumption recorded.' }).waitFor();
		assert.equal(await stock(product), start - 1, 'the manual consumption deducted once');

		const linkId = 'link-event-' + token;
		const booked = await client.put(linkId, takenEvent('link-' + token, { occurred_at: minutesAgo(2) }));
		assert.equal(booked.status, 201);
		assert.equal(booked.body.state, 'booked');
		assert.equal(await stock(product), start - 2, 'the external event deducted as well, so the same dose now counts twice');
		const duplicates = booked.body.possible_duplicates;
		assert.equal(duplicates.length, 1, 'the manual consumption is offered as a possible duplicate');
		const manualTransaction = duplicates[0].transaction_id;

		await page.goto(BASE + '/consumptioninbox');
		const linkRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + linkId + '"]');
		await linkRow.waitFor();
		assert.match(await linkRow.locator('.inbox-state').innerText(), /^Booked/);
		assert.match(await linkRow.locator('.inbox-details').innerText(), new RegExp('Possible duplicate: consumption ' + manualTransaction));
		assert.match(await linkRow.locator('.inbox-details').innerText(), /Inbox product .*\(Inbox organizer A /, 'the booked line shows product and location names');
		assert.match(await linkRow.locator('.inbox-time').innerText(), /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
		assert.equal(await linkRow.locator('button.inbox-action').count(), 1, 'a booked event offers Link only');
		await linkRow.locator('.inbox-action-link').click();
		await page.locator('#inbox-link-modal.show').waitFor();
		await page.locator('#inbox-link-other').fill('no-such-transaction-' + token);
		await page.locator('#inbox-link-confirm').click();
		await page.locator('#inbox-link-error').filter({ hasText: 'That transaction cannot be linked' }).waitFor();
		assert.equal((await api(page, 'consumption/events/' + SYSTEM + '/' + linkId)).state, 'booked', 'an invalid link changes nothing');
		assert.equal(await stock(product), start - 2);
		await page.locator('#inbox-link-other').fill('');
		await page.locator('#inbox-link-confirm').click();
		await page.locator('#inbox-link-error').filter({ hasText: 'Choose a consumption or enter a transaction id.' }).waitFor();
		await page.locator('.inbox-link-choice').first().check();
		await page.locator('#inbox-link-confirm').click();
		await page.locator('#inbox-message').filter({ hasText: 'is now: Linked to an existing consumption' }).waitFor();
		await page.locator('#inbox-link-modal').waitFor({ state: 'hidden' });
		await linkRow.waitFor({ state: 'detached' });
		const linked = await api(page, 'consumption/events/' + SYSTEM + '/' + linkId);
		assert.equal(linked.state, 'linked');
		assert.equal(linked.transaction_id, manualTransaction);
		assert.equal(await stock(product), start - 1, 'exactly one deduction remains');
		const ledger = await api(page, 'objects/stock_log?query[]=product_id=' + product + '&query[]=transaction_type=consume');
		assert.equal(ledger.filter(l => Number(l.undone) === 0 && l.transaction_id === manualTransaction).length, 1, 'the manual booking is the live one');
		assert.equal(ledger.filter(l => Number(l.undone) === 0).length, 1, 'and it is the only live consumption of the product');
		step('b. linkage: possible duplicate listed, invalid id refused with nothing changed, Link leaves one deduction');

		// --- c. Rebook after undo --------------------------------------------------------------
		const undoId = 'undo-event-' + token;
		const before = await stock(product);
		const undoBody = takenEvent('undo-' + token);
		const first = await client.put(undoId, undoBody);
		assert.equal(first.body.state, 'booked');
		assert.equal(await stock(product), before - 1);
		const bookings = (await api(page, 'objects/stock_log?query[]=transaction_id=' + first.body.transaction_id));
		assert.equal(bookings.length, 1);
		await page.goto(BASE + '/stockjournal');
		const undoButton = page.locator('#stock-booking-' + bookings[0].id + '-row .undo-stock-booking-button');
		await undoButton.waitFor();
		await undoButton.click();
		await page.locator('#stock-booking-' + bookings[0].id + '-row .undo-stock-booking-button.disabled').waitFor();
		assert.equal(await stock(product), before, 'the journal undo restored the stock');
		const replay = await client.put(undoId, undoBody);
		assert.equal(replay.status, 200);
		assert.equal(replay.body.replayed, true);
		assert.equal(replay.body.state, 'undone', 'a replay of the same request leaves the event undone');
		assert.equal(await stock(product), before, 'and books nothing');

		await page.goto(BASE + '/consumptioninbox');
		await page.locator('#inbox-empty:not(.d-none), #inbox-rows tr').first().waitFor();
		const undoneRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + undoId + '"]');
		assert.equal(await undoneRow.count(), 0, 'undone events are not listed until the toggle is on');
		await page.locator('#inbox-show-undone').check();
		await undoneRow.waitFor();
		assert.match(await undoneRow.locator('.inbox-state').innerText(), /^Undone: the booking was reversed and stock was restored/);
		assert.deepEqual(await undoneRow.locator('button.inbox-action').allInnerTexts(), ['Book again', 'Dismiss']);
		await undoneRow.locator('.inbox-action-rebook').click();
		await page.locator('#inbox-message').filter({ hasText: 'is now: Booked' }).waitFor();
		assert.equal(await stock(product), before - 1, 'Book again deducted once more');
		const rebooked = await api(page, 'consumption/events/' + SYSTEM + '/' + undoId);
		assert.equal(rebooked.state, 'booked');
		await undoneRow.waitFor({ state: 'detached' });
		step('c. rebook: journal undo, replay stays undone, inbox lists it under the toggle, Book again deducts once');

		// --- d. needs_mapping, a stale action, dismiss -----------------------------------------
		const staleId = 'stale-' + token;
		const dismissId = 'dismiss-' + token;
		const atDismiss = await stock(product);
		assert.equal((await client.put(staleId, takenEvent('unmapped-a-' + token))).body.state, 'needs_mapping');
		assert.equal((await client.put(dismissId, takenEvent('unmapped-b-' + token))).body.state, 'needs_mapping');
		await page.goto(BASE + '/consumptioninbox');
		const staleRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + staleId + '"]');
		const dismissRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + dismissId + '"]');
		await staleRow.waitFor();
		await dismissRow.waitFor();
		assert.match(await dismissRow.locator('.inbox-state').innerText(), /^Not booked: no mapping for this medication$/);
		assert.deepEqual(await dismissRow.locator('button.inbox-action').allInnerTexts(), ['Try booking again', 'Dismiss']);
		// The row on screen is stale: the event was dismissed elsewhere. The server refuses with 409.
		await api(page, 'consumption/events/' + SYSTEM + '/' + staleId + '/resolve', 'POST', { action: 'dismiss' });
		await staleRow.locator('.inbox-action-retry').click();
		await page.locator('#inbox-error').filter({ hasText: 'The action retry is not allowed on an event that is dismissed' }).waitFor();
		await staleRow.waitFor({ state: 'detached' });
		await dismissRow.locator('.inbox-action-dismiss').click();
		await page.locator('#inbox-message').filter({ hasText: 'is now: Dismissed: nothing was booked' }).waitFor();
		await dismissRow.waitFor({ state: 'detached' });
		assert.equal((await api(page, 'consumption/events/' + SYSTEM + '/' + dismissId)).state, 'dismissed');
		assert.equal(await stock(product), atDismiss, 'dismissing books nothing');
		step('d. needs_mapping: stale action shows the 409 sentence, dismiss clears the row and books nothing');

		// --- e. Words, sinks, approve_unit, ambiguity, bulk ------------------------------------
		const unitId = 'unit-' + token;
		const atUnit = await stock(product);
		const unitEvent = await client.put(unitId, takenEvent('unit-' + token, { unit_label: payload }));
		assert.equal(unitEvent.body.reason, 'unit_unconfirmed');
		const ambiguousId = 'ambiguous-' + token;
		assert.equal((await client.put(ambiguousId, takenEvent('spread-' + token))).body.reason, 'ambiguous_location');
		await page.goto(BASE + '/consumptioninbox');
		const unitRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + unitId + '"]');
		await unitRow.waitFor();
		assert.ok((await unitRow.locator('.inbox-details').innerText()).includes('Unit sent by the source: "' + payload + '"'), 'the exact label is shown as text');
		assert.equal(await page.locator('#inbox-rows img').count(), 0, 'and is not an element');
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'nothing executed');
		assert.match(await unitRow.locator('.inbox-state').innerText(), /^Not booked: the unit has not been confirmed$/);
		assert.deepEqual(await unitRow.locator('button.inbox-action').allInnerTexts(), ['Try booking again', 'Confirm the unit and book', 'Dismiss', 'Link to an existing consumption']);
		const ambiguousRow = page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/' + ambiguousId + '"]');
		const ambiguousText = await ambiguousRow.locator('.inbox-details').innerText();
		assert.ok(ambiguousText.includes('Inbox organizer A ' + token) && ambiguousText.includes('Inbox organizer B ' + token), 'candidate locations are shown by name');
		assert.doesNotMatch(await ambiguousRow.locator('.inbox-state').innerText(), /missed|late|adherence|overdue/i);
		await unitRow.locator('.inbox-action-approve_unit').click();
		await page.locator('#inbox-message').filter({ hasText: 'is now: Booked' }).waitFor();
		assert.equal(await stock(product), atUnit - 1, 'approve_unit booked the event');
		await unitRow.waitFor({ state: 'detached' });
		await ambiguousRow.locator('.inbox-action-dismiss').click();
		await ambiguousRow.waitFor({ state: 'detached' });

		// Bulk: 55 unmapped events of one medication need two requests; the loop stops when none remain.
		const bulkRef = 'bulk-' + token;
		for (let from = 0; from < 55; from += 50)
		{
			const items = [];
			for (let i = from; i < Math.min(55, from + 50); i++) items.push({ source_system: SYSTEM, source_event_id: 'bulk-' + token + '-' + i, ...takenEvent(bulkRef, { occurred_at: minutesAgo(60 + i) }) });
			const result = await client.batch(items);
			assert.equal(result.status, 200);
			assert.ok(result.body.every(item => item.http_status === 201 && item.event.state === 'needs_mapping'));
		}
		await page.goto(BASE + '/consumptioninbox');
		await page.locator('#inbox-rows tr').first().waitFor();
		await page.locator('#inbox-bulk-state').selectOption({ label: 'Not booked: no mapping for this medication' });
		assert.deepEqual(await page.locator('#inbox-bulk-action option').allInnerTexts(), ['Try booking again', 'Dismiss']);
		await page.locator('#inbox-bulk-action').selectOption('dismiss');
		await page.locator('#inbox-bulk-system').selectOption(SYSTEM);
		await page.locator('#inbox-bulk-medication').fill(bulkRef);
		let bulkCalls = 0;
		page.on('request', r => { if (r.method() === 'POST' && r.url().endsWith('/api/consumption/events/resolve')) bulkCalls++; });
		await page.locator('#inbox-bulk-run').click();
		await page.locator('#inbox-bulk-result').filter({ hasText: 'Handled 55 events; 0 refused; 0 still match.' }).waitFor();
		assert.equal(bulkCalls, 2, 'a filter over 55 events took two requests');
		const bulkLeft = (await api(page, 'consumption/events?state=needs_mapping&limit=500')).filter(e => e.source_event_id.startsWith('bulk-' + token));
		assert.equal(bulkLeft.length, 0, 'every one is dismissed');
		assert.equal((await api(page, 'consumption/events?state=dismissed&limit=500')).filter(e => e.source_event_id.startsWith('bulk-' + token)).length, 55);
		step('e. unit label shown as text, approve_unit books, candidate locations by name, bulk dismiss of 55 loops to none remaining');

		// --- f. medication_ref grouping -----------------------------------------------------------
		await client.put('group-a-' + token, takenEvent('group-one-' + token));
		await client.put('group-b-' + token, takenEvent('group-two-' + token));
		await page.goto(BASE + '/consumptioninbox');
		await page.locator('#inbox-medication-label:not(.d-none)').waitFor();
		await page.locator('#inbox-medication').selectOption('group-two-' + token);
		assert.equal(await page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/group-a-' + token + '"]').count(), 0);
		assert.equal(await page.locator('#inbox-rows tr[data-event-key="' + SYSTEM + '/group-b-' + token + '"]').count(), 1);
		step('f. medication filter groups rows by the medication_ref the API returns');

		// DELETE bodies arrive through php://input, whose stream size may be unknown (#760).
		for (const contentType of ['application/json', 'application/json; charset=utf-8'])
		{
			const id = 'delete-body-' + token + '-' + (contentType.includes(';') ? 'charset' : 'plain');
			const created = await client.put(id, takenEvent('unit-' + token));
			assert.equal(created.body.state, 'booked');
			const beforeDelete = await stock(product);
			const deleted = await page.evaluate(async ({ id, contentType }) =>
			{
				const response = await fetch('/api/consumption/events/healthkit/' + id, {
					method: 'DELETE', headers: { 'Content-Type': contentType },
					body: JSON.stringify({ reason: 'medication_archived' })
				});
				return { status: response.status, body: await response.json() };
			}, { id, contentType });
			assert.equal(deleted.status, 200);
			assert.equal(deleted.body.source_removed_reason, 'medication_archived', contentType + ' preserves the DELETE reason');
			assert.equal(deleted.body.state, 'booked');
			assert.equal(await stock(product), beforeDelete, 'archiving history does not restore stock');
		}
		step('g. DELETE JSON bodies preserve reasons with plain and charset media types');

		assert.deepEqual(errors, [], 'no page error');
		assert.equal(await page.evaluate(() => window.__xss), undefined);
		await context.close();
		await admin.context.close();
		console.log('CONSUMPTION INBOX BROWSER CHECKS PASSED (' + stepsDone.length + ' scenarios)');
	}
	finally
	{
		for (const client of clients) await client.dispose().catch(() => { });
		await browser.close();
	}
})().catch(error => { console.error(error); process.exit(1); });
