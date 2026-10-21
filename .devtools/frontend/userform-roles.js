// Issue #762. The users list's permissions button was an unlabelled lock icon, and the user
// create form could not set roles at all. Checks in a real browser that:
//   1. the users list button reads "Roles" as visible text and carries an aria-label;
//   2. the create form has a Roles multi-select reachable through its label (the configured
//      default roles, none on a stock config, are pre-selected);
//   3. choosing other roles POSTs /api/users then PUTs /api/users/{id}/roles, and the chosen
//      roles replace the defaults;
//   4. if the PUT fails, the form says the user was created but the roles were not set, in a
//      role=alert region with a link to that user's permissions page.
// Whether the PUT happens, and what the page shows when it fails, is browser-side behaviour
// the PostgreSQL phases cannot see.
// Run against a disposable authenticated/admin or demo instance: node userform-roles.js <url>.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36).toUpperCase();

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		async function fillAndSave(username)
		{
			await page.locator('#username').fill(username);
			await page.locator('#password').fill('test fixture only');
			await page.locator('#password_confirm').fill('test fixture only');
			await page.locator('#save-user-button').click();
		}

		const roles = await (async () => { await page.goto(base + '/about'); return api('roles'); })();
		const idOf = code => String(roles.find(r => r.code === code).id);

		// 1. The list button: visible text and an accessible name that contains it.
		await page.goto(base + '/users');
		const button = page.locator('a[href*="/permissions"]').first();
		assert.equal((await button.innerText()).trim(), 'Roles', 'the button shows the word Roles');
		const label = await button.getAttribute('aria-label');
		assert.ok(label && label.startsWith('Roles'), 'the button has an aria-label that starts with its visible text: ' + label);

		// 2. The create form: labelled select, defaults pre-selected, keyboard reachable.
		await page.goto(base + '/user/new');
		const select = page.getByLabel('Roles', { exact: true });
		assert.equal(await select.count(), 1, 'the Roles select is reachable through its label');
		assert.equal(await select.evaluate(el => el.multiple), true);
		const preselected = await select.evaluate(el => Array.from(el.selectedOptions).map(o => o.value));
		// The demo's DEFAULT_ROLES is [] (the shipped default), so nothing is pre-selected here; the
		// non-empty pre-selection is covered by the rbac phase.
		await select.focus();
		assert.equal(await select.evaluate(el => el === document.activeElement), true, 'the select takes keyboard focus');
		assert.equal(await page.locator('#user-roles-error').isVisible(), false, 'no error before saving');

		// 3. Create with a chosen role: the choice replaces the defaults.
		const chosen = ['GUEST', 'CHILD', 'ADULT'].map(idOf).filter(id => !preselected.includes(id)).slice(0, 1);
		assert.equal(chosen.length, 1, 'a non-default role exists to choose');
		await select.selectOption(chosen);
		// Characters the API's query filter value pattern drops (~ { } ] and a backtick): the id
		// lookup must still find this user, so it cannot rely on that filter.
		const username = 'userform-roles-' + token + '~b{c}]`';
		await Promise.all([page.waitForURL('**/users'), fillAndSave(username)]);
		const created = (await api('users')).find(user => user.username === username);
		assert.ok(created, 'the user was created');
		assert.deepEqual((await api('users/' + created.id + '/roles')).map(r => String(r.id)), chosen, 'the chosen roles replace the defaults');

		// Empty selection: roles [] is sent and accepted, and the user ends with no roles.
		await page.goto(base + '/user/new');
		await page.getByLabel('Roles', { exact: true }).selectOption([]);
		const emptyName = 'userform-roles-empty-' + token;
		await Promise.all([page.waitForURL('**/users'), fillAndSave(emptyName)]);
		const empty = (await api('users')).find(user => user.username === emptyName);
		assert.ok(empty, 'the user with no roles was created');
		assert.deepEqual(await api('users/' + empty.id + '/roles'), [], 'an empty selection leaves the user with no roles');

		// 4. The PUT fails: the user exists, the page says the roles were not set.
		await page.route('**/api/users/*/roles', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error_message":"forced"}' }));
		await page.goto(base + '/user/new');
		const failedName = 'userform-roles-fail-' + token;
		let userPosts = 0;
		page.on('request', request =>
		{
			if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/users')) userPosts++;
		});
		await fillAndSave(failedName);
		const alert = page.locator('#user-roles-error[role=alert]');
		await alert.waitFor({ state: 'visible' });
		assert.ok((await alert.innerText()).includes('created'), 'the alert says the user was created');
		const failed = (await api('users')).find(user => user.username === failedName);
		assert.ok(failed, 'the user from the failed second step exists');
		assert.ok((await alert.locator('a').getAttribute('href')).endsWith('/user/' + failed.id + '/permissions'), 'the alert links to the permissions page');
		assert.equal(await alert.evaluate(el => el === document.activeElement), true, 'the alert takes focus so it is announced');
		assert.equal(await page.locator('#save-user-button').isDisabled(), true, 'a second save cannot create the user again');
		assert.equal(await page.locator('#username').isDisabled(), true, 'the identity inputs are disabled');
		assert.equal((await alert.locator('a').innerText()).trim().length > 0, true, 'the link has text');
		assert.equal(await page.locator('#user-picture-error').isVisible(), false, 'no picture failure is claimed');
		assert.equal(userPosts, 1, 'exactly one POST /api/users so far');

		// Retry through the Enter handler and a direct click handler call, as a user (or a
		// script) could: jQuery runs bound click handlers even on a disabled button.
		await page.evaluate(() =>
		{
			$('#username').val('retry-' + $('#username').val());
			const enter = $.Event('keydown');
			enter.keyCode = 13;
			$('#username').trigger(enter);
			$('#save-user-button').trigger('click');
		});
		await page.waitForTimeout(1000);
		assert.equal(userPosts, 1, 'Enter or click after the roles failure must not POST a second user');
		assert.equal((await api('users')).filter(user => user.username.includes(token) && user.username.includes('fail')).length, 1, 'only one user was created');

		console.log('USERFORM ROLES CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
