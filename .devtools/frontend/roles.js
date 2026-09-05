// Role create/edit and direct-vs-inherited grant behavior in a real browser.
// Run against a disposable authenticated/admin or demo instance: node roles.js <url>.
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
		const payload = '<img src=x onerror=window.__xss=1> ' + token;
		await page.goto(base + '/role/new');
		await page.locator('#role-name').fill(payload);
		await page.locator('#role-code').fill('TEST_' + token);
		await page.locator('[data-permission-name=STOCK_VIEW]').check();
		await page.locator('#role-save').click();
		await page.waitForURL('**/roles');
		assert.equal(await page.evaluate(() => window.__xss), undefined);
		const row = page.locator('tr').filter({ hasText: 'TEST_' + token });
		await row.locator('.role-delete-button').click();
		await page.locator('.bootbox-body').waitFor();
		assert.ok((await page.locator('.bootbox-body').innerText()).includes(payload));
		assert.equal(await page.locator('.bootbox-body img').count(), 0);
		await page.getByRole('button', { name: 'No', exact: true }).click();
		await row.locator('a').click();
		await page.locator('[data-permission-name=STOCK_VIEW]:checked').waitFor();
		assert.equal(await page.locator('#role-code').isDisabled(), true);

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}
		await api('users', 'POST', { username: 'role-browser-' + token, password: 'test fixture only' });
		const user = (await api('users')).find(user => user.username === 'role-browser-' + token);
		const permissions = await api('users/' + user.id + '/permissions');
		const stock = permissions.find(p => p.permission_name === 'STOCK_VIEW').permission_id;
		await api('users/' + user.id + '/permissions', 'PUT', { permissions: [stock] });
		const roles = await api('roles');
		await page.goto(base + '/user/' + user.id + '/permissions');
		await page.locator('#user-roles').selectOption(roles.filter(r => ['CHILD', 'GUEST'].includes(r.code)).map(r => String(r.id)));
		await Promise.all([page.waitForNavigation(), page.locator('#roles-save').click()]);
		const cb = page.locator('[data-permission-name=STOCK_VIEW]');
		assert.equal(await cb.isChecked(), true);
		assert.equal(await cb.isDisabled(), true);
		await page.locator('#user-roles').selectOption([]);
		await Promise.all([page.waitForNavigation(), page.locator('#roles-save').click()]);
		assert.equal(await cb.isChecked(), true);
		assert.equal(await cb.isDisabled(), false);
		assert.deepEqual((await api('users/' + user.id + '/permissions')).filter(p => p.has_permission).map(p => p.permission_name), ['STOCK_VIEW']);
		await Promise.all([page.waitForNavigation(), page.locator('#permission-save').click()]);
		console.log('ROLE BROWSER CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
