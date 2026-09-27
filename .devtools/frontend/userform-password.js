// Regression probe for issue #549: userform.js sent jsonData.password_base64 =
// btoa(jsonData.password) unconditionally on every save. #change_password (edit mode
// only) disables the password inputs when left unticked, and serializeJSON() - like a
// real form submit - omits a disabled field entirely, so jsonData.password was undefined
// and btoa(undefined) === "dW5kZWZpbmVk", the base64 of the literal string "undefined".
// The API answered that as a real new password, so an admin's edit of someone else's
// profile with the box left unticked silently set that account's password to the word
// "undefined". The PostgreSQL phase structurally cannot see this: it is entirely about
// what the browser puts in the request body before the API ever sees it.
// Run against a disposable authenticated/admin or demo instance: node userform-password.js <url>.
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

		// A page load first, the same way roles.js does before its own api() calls: the
		// browser needs the auto-logged-in demo session's cookie before fetch() from this
		// page can use it.
		await page.goto(base + '/about');
		await api('users', 'POST', { username: 'userform-password-' + token, password: 'test fixture only' });
		const otherUser = (await api('users')).find(user => user.username === 'userform-password-' + token);

		let capturedBody = null;
		const usersPutPattern = /\/api\/users\/\d+$/;
		function captureUsersPut(request)
		{
			if (request.method() === 'PUT' && usersPutPattern.test(new URL(request.url()).pathname))
			{
				capturedBody = request.postData();
			}
		}
		page.on('request', captureUsersPut);

		// An admin editing someone else's account, #change_password left unticked - exactly
		// the shape the reported exploit used, and the one an ordinary "just fix the spelling
		// of this person's name" edit takes.
		await page.goto(base + '/user/' + otherUser.id);
		await page.locator('#first_name').fill('Untouched ' + token);
		await Promise.all([page.waitForNavigation(), page.locator('#save-user-button').click()]);

		assert.ok(capturedBody, 'the save PUT to /api/users/{id} was not observed');
		const uncheckedBody = JSON.parse(capturedBody);
		assert.equal(Object.prototype.hasOwnProperty.call(uncheckedBody, 'password_base64'), false,
			'password_base64 must not be sent when #change_password is left unticked: ' + capturedBody);

		// The contrast, so the assertion above is not vacuous: ticking the box and providing
		// a real new password DOES still send password_base64.
		capturedBody = null;
		await page.goto(base + '/user/' + otherUser.id);
		await page.locator('#change_password').check();
		await page.locator('#password').fill('a new fixture password ' + token);
		await page.locator('#password_confirm').fill('a new fixture password ' + token);
		await Promise.all([page.waitForNavigation(), page.locator('#save-user-button').click()]);

		assert.ok(capturedBody, 'the second save PUT to /api/users/{id} was not observed');
		const checkedBody = JSON.parse(capturedBody);
		assert.equal(Object.prototype.hasOwnProperty.call(checkedBody, 'password_base64'), true,
			'password_base64 must still be sent when #change_password IS ticked: ' + capturedBody);

		page.off('request', captureUsersPut);
		console.log('USERFORM PASSWORD CHECKBOX CHECKS PASSED');
	}
	finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
