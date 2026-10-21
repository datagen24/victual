// The Medication flyout in the sidebar (issue #1007, decision D5):
//
//   node medication-sidebar.js --url http://127.0.0.1:8095 --admin-password <password>
//
// Run against a disposable instance in VICTUAL_MODE=production with a fresh database and
// VICTUAL_BOOTSTRAP_ADMIN_PASSWORD set before the first migration. It cannot run against the demo
// instance: demo and dev mode have one identity for every request, and this probe needs one user
// who can see the entries and one who cannot, each signing in with a password.
//
// What it asserts, in a real browser:
//
//   a. One "Medication" parent and three children, in order: Medication presets, Medication inbox,
//      Refills, pointing at /consumptionrecipes, /consumptioninbox and /consumptionrefills. None of
//      the three is a top-level sidebar entry any more, and the old wording appears nowhere in the sidebar.
//   b. On each of the three pages the parent is expanded (aria-expanded="true", list shown) and exactly
//      that page's child is marked active (class and aria-current). The page heading uses the same
//      label. On another page the parent is collapsed, aria-expanded is "false" and no child is active.
//   c. A user with STOCK_VIEW sees the parent with its three children. A user without it sees no
//      parent. All three children share the STOCK_VIEW gate they had as top-level entries, so one
//      visible child and three visible children are the same case.
//   d. Keyboard. Enter and Space on the parent toggle aria-expanded; the collapsed children are not in
//      the tab order; the expanded children follow the parent in the order presets, inbox, refills.
//   e. Collapsed sidebar. The parent has a tooltip with its label; activating it expands the sidebar
//      and the flyout, and the toggler then collapses the flyout again with aria-expanded="false".
//   f. Mobile width. The flyout opens inside the responsive menu and a child link navigates.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

/**
 * Reads a named command-line option, or returns the fallback.
 * @param {string} name option name without the leading dashes
 * @param {string} fallback value used when the option is absent
 * @returns {string}
 */
function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = arg('url', 'http://127.0.0.1:8095').replace(/\/$/, '');
const ADMIN_USER = arg('admin-user', 'admin');
const ADMIN_PASSWORD = arg('admin-password', process.env.VICTUAL_PROBE_ADMIN_PASSWORD || '');
const token = Date.now().toString(36);
const password = 'Probe-pass-' + token;

const CHILDREN = [
	{ view: 'consumptionrecipes', path: '/consumptionrecipes', label: 'Medication presets' },
	{ view: 'consumptioninbox', path: '/consumptioninbox', label: 'Medication inbox' },
	{ view: 'consumptionrefills', path: '/consumptionrefills', label: 'Refills' }
];
const OTHER_PAGE = '/stockoverview';

/** Logs a PASS line for a finished step. */
function step(name) { console.log('PASS ' + name); }

/**
 * Opens a fresh browser context, signs the user in and returns the page with its collected page errors.
 * @param {import('playwright').Browser} browser the shared browser
 * @param {string} username account name
 * @param {string} pass account password
 * @param {object} options extra context options such as the viewport
 * @returns {Promise<{context: object, page: object, errors: string[]}>}
 */
async function signIn(browser, username, pass, options = {})
{
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, ...options });
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto(BASE + '/login');
	await page.locator('#username').fill(username);
	await page.locator('#password_input').fill(pass);
	await Promise.all([page.waitForNavigation(), page.locator('#login-button').click()]);
	assert.ok(!page.url().includes('/login'), username + ' signed in');
	return { context, page, errors };
}

/**
 * Calls the JSON API from the page, throwing on an error status.
 * @param {object} page signed-in page
 * @param {string} path path under /api/
 * @param {string} method HTTP method
 * @param {object} body optional JSON body
 * @returns {Promise<any>}
 */
async function api(page, path, method = 'GET', body)
{
	const result = await page.evaluate(async ({ path, method, body }) =>
	{
		const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
		const text = await response.text();
		let parsed = null;
		try { parsed = text === '' ? null : JSON.parse(text); } catch (e) { parsed = text; }
		return { status: response.status, body: parsed };
	}, { path, method, body });
	if (result.status >= 400) throw new Error(method + ' ' + path + ' -> ' + result.status + ' ' + JSON.stringify(result.body));
	return result.body;
}

/**
 * Creates a user holding exactly the named permissions.
 * @param {object} adminPage signed-in administrator page
 * @param {string} name username
 * @param {string[]} permissions permission names
 */
async function makeUser(adminPage, name, permissions)
{
	await api(adminPage, 'users', 'POST', { username: name, password });
	const user = (await api(adminPage, 'users')).find(u => u.username === name);
	const rows = await api(adminPage, 'users/' + user.id + '/permissions');
	const ids = {};
	rows.forEach(row => { ids[row.permission_name] = row.permission_id; });
	await api(adminPage, 'users/' + user.id + '/permissions', 'PUT', { permissions: permissions.map(n => { assert.ok(ids[n], n); return ids[n]; }) });
	const effective = (await api(adminPage, 'users/' + user.id + '/permissions')).filter(p => Number(p.has_permission) === 1).map(p => p.permission_name).sort();
	assert.deepEqual(effective, [...permissions].sort(), 'the fixture user holds exactly the intended permissions');
	return user;
}

/**
 * Reads the sidebar's Medication state: the parent and its children as the page renders them.
 * @param {object} page page to read
 * @returns {Promise<object>}
 */
function readSidebar(page)
{
	return page.evaluate(() =>
	{
		const parent = document.querySelector('#nav-medication');
		const toggle = parent ? parent.querySelector(':scope > a') : null;
		const list = document.querySelector('#sub-nav-medication');
		return {
			parentCount: document.querySelectorAll('#nav-medication').length,
			parentText: toggle ? toggle.textContent.trim() : null,
			expanded: toggle ? toggle.getAttribute('aria-expanded') : null,
			controls: toggle ? toggle.getAttribute('aria-controls') : null,
			listShown: list ? list.classList.contains('show') : false,
			children: list ? Array.from(list.querySelectorAll(':scope > li')).map(li =>
			{
				const a = li.querySelector('a');
				return { text: a.textContent.trim(), href: new URL(a.href).pathname, active: li.classList.contains('active-page'), current: a.getAttribute('aria-current') };
			}) : [],
			// Every top-level sidebar link, to prove the three old entries are gone from the top level.
			topLevelHrefs: Array.from(document.querySelectorAll('.navbar-sidenav > li > a')).map(a => a.getAttribute('href') || '')
		};
	});
}

/** Waits until the flyout list is (or is not) shown, which follows the Bootstrap transition. */
function waitForList(page, shown)
{
	return page.waitForFunction(s => { const l = document.querySelector('#sub-nav-medication'); return !!l && l.classList.contains('show') === s && !l.classList.contains('collapsing'); }, shown);
}

/** The pathname of the element that has focus, or its text when it has no path. */
function focusedTarget(page)
{
	return page.evaluate(() =>
	{
		const e = document.activeElement;
		if (!e) return null;
		return e.getAttribute('href') ? e.getAttribute('href') : (e.id || e.tagName);
	});
}

(async () =>
{
	assert.ok(ADMIN_PASSWORD, 'pass --admin-password or set VICTUAL_PROBE_ADMIN_PASSWORD');
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	const sessions = [];

	try
	{
		const admin = await signIn(browser, ADMIN_USER, ADMIN_PASSWORD);
		sessions.push(admin);
		const reader = await makeUser(admin.page, 'sidebar-reader-' + token, ['STOCK_VIEW']);
		const nobody = await makeUser(admin.page, 'sidebar-none-' + token, ['USERS_EDIT_SELF']);

		// --- a and b. Structure and the active child on each page ----------------------------------
		for (const child of CHILDREN)
		{
			const response = await admin.page.goto(BASE + child.path);
			assert.equal(response.status(), 200, child.path + ' still answers at its old URL');
			const sidebar = await readSidebar(admin.page);
			assert.equal(sidebar.parentCount, 1, 'one Medication parent on ' + child.path);
			assert.equal(sidebar.parentText, 'Medication');
			assert.deepEqual(sidebar.children.map(c => c.text), CHILDREN.map(c => c.label), 'the three children carry the new labels');
			assert.deepEqual(sidebar.children.map(c => c.href), CHILDREN.map(c => c.path), 'the children point at the unchanged URLs');
			for (const old of CHILDREN) assert.ok(!sidebar.topLevelHrefs.some(h => h.endsWith(old.path)), old.path + ' is no longer a top-level entry');
			assert.equal(sidebar.expanded, 'true', 'the parent is expanded on ' + child.path);
			assert.equal(sidebar.listShown, true, 'the flyout is shown on ' + child.path);
			assert.equal(sidebar.controls, 'sub-nav-medication');
			assert.deepEqual(sidebar.children.filter(c => c.active).map(c => c.href), [child.path], 'only ' + child.path + ' is active');
			assert.deepEqual(sidebar.children.filter(c => c.current === 'page').map(c => c.href), [child.path], 'aria-current marks the active child');
			assert.equal((await admin.page.locator('h2.title').first().innerText()).trim(), child.label, 'the page heading uses the same label');
			assert.ok((await admin.page.title()).includes(child.label), 'the document title uses the same label');
			assert.equal(await admin.page.locator('.navbar-sidenav > li > a.active-page').count() + await admin.page.locator('.navbar-sidenav > li.active-page').count(), 1, 'the parent is the one active top-level entry');
			const bodyText = await admin.page.locator('.navbar-sidenav').innerText();
			for (const gone of ['Consumption recipes', 'Consumption inbox', 'Prescription refills']) assert.ok(!bodyText.includes(gone), 'the sidebar no longer says "' + gone + '"');
		}
		step('structure, labels, old URLs, expanded parent and active child on each page');

		await admin.page.goto(BASE + OTHER_PAGE);
		let sidebar = await readSidebar(admin.page);
		assert.equal(sidebar.expanded, 'false', 'the parent is collapsed elsewhere');
		assert.equal(sidebar.listShown, false);
		assert.equal(sidebar.children.filter(c => c.active || c.current).length, 0, 'no child is active elsewhere');
		step('collapsed with no active child on another page');

		// --- c. Visibility by permission ------------------------------------------------------------
		const readerSession = await signIn(browser, reader.username, password);
		sessions.push(readerSession);
		await readerSession.page.goto(BASE + OTHER_PAGE);
		sidebar = await readSidebar(readerSession.page);
		assert.equal(sidebar.parentCount, 1, 'a user with STOCK_VIEW sees the parent');
		assert.equal(sidebar.children.length, 3);

		const nobodySession = await signIn(browser, nobody.username, password);
		sessions.push(nobodySession);
		await nobodySession.page.goto(BASE + '/about');
		sidebar = await readSidebar(nobodySession.page);
		assert.equal(sidebar.parentCount, 0, 'a user who can see none of the children gets no parent');
		assert.equal(await nobodySession.page.locator('#sub-nav-medication').count(), 0);
		assert.ok(await nobodySession.page.locator('.navbar-sidenav').count() > 0, 'the sidebar itself still renders for that user');
		step('parent hidden without any visible child, shown with one');

		// --- d. Keyboard ------------------------------------------------------------------------------
		const kb = admin.page;
		await kb.goto(BASE + OTHER_PAGE);
		const toggle = kb.locator('#nav-medication > a');
		// A mouse click leaves focus on the trigger but no focus highlight, which is for keyboard focus only.
		await toggle.click();
		await waitForList(kb, true);
		await kb.mouse.move(900, 600);
		assert.equal(await kb.evaluate(() => document.activeElement === document.querySelector('#nav-medication > a')), true, 'the trigger keeps focus after a click');
		assert.equal(await toggle.evaluate(a => getComputedStyle(a).boxShadow), 'none', 'a mouse click leaves no focus highlight');
		await toggle.click();
		await waitForList(kb, false);
		await toggle.focus();
		assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
		await kb.keyboard.press('Tab');
		assert.ok(!(await kb.evaluate(() => !!document.activeElement.closest('#sub-nav-medication'))), 'collapsed children are not in the tab order');
		await toggle.focus();
		await kb.keyboard.press('Enter');
		await waitForList(kb, true);
		assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'Enter expands');
		assert.equal(await kb.evaluate(() => document.activeElement === document.querySelector('#nav-medication > a')), true, 'the parent keeps focus after it is activated');
		const order = [];
		for (let i = 0; i < 3; i++)
		{
			await kb.keyboard.press('Tab');
			order.push(await focusedTarget(kb));
		}
		assert.deepEqual(order.map(h => new URL(h, BASE).pathname), CHILDREN.map(c => c.path), 'Tab walks presets, inbox, refills');
		await toggle.focus();
		await kb.keyboard.press('Space');
		await waitForList(kb, false);
		assert.equal(await kb.evaluate(() => document.activeElement === document.querySelector('#nav-medication > a')), true, 'the parent keeps focus after Space');
		assert.equal(await toggle.getAttribute('aria-expanded'), 'false', 'Space collapses');
		await kb.keyboard.press('Space');
		await waitForList(kb, true);
		assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'Space expands');
		await kb.keyboard.press('Enter');
		await waitForList(kb, false);
		assert.equal(await toggle.getAttribute('aria-expanded'), 'false', 'Enter collapses');
		step('Enter and Space toggle aria-expanded, and focus order follows the visible children');

		// --- e. Collapsed sidebar ---------------------------------------------------------------------
		const cs = admin.page;
		await cs.goto(BASE + '/consumptioninbox');
		await cs.locator('#sidenavToggler').click();
		await cs.waitForFunction(() => document.body.classList.contains('sidenav-toggled'));
		assert.equal(await cs.locator('#nav-medication > a').getAttribute('aria-expanded'), 'false', 'collapsing the sidebar collapses the flyout and updates aria-expanded');
		await cs.locator('#nav-medication').hover();
		await cs.locator('.navbar-sidenav-tooltip.show .tooltip-inner').waitFor();
		assert.equal((await cs.locator('.navbar-sidenav-tooltip.show .tooltip-inner').first().innerText()).trim(), 'Medication', 'the collapsed parent has a tooltip');
		await cs.locator('#nav-medication > a').click();
		await cs.waitForFunction(() => !document.body.classList.contains('sidenav-toggled'));
		await waitForList(cs, true);
		assert.equal(await cs.locator('#nav-medication > a').getAttribute('aria-expanded'), 'true', 'activating the parent expands the sidebar and the flyout');
		await cs.evaluate(() => window.localStorage.removeItem('sidebar_state'));
		step('collapsed sidebar: tooltip, aria-expanded reset, expand on activation');

		// --- f. Mobile width -----------------------------------------------------------------------------
		const mobile = await signIn(browser, ADMIN_USER, ADMIN_PASSWORD, { viewport: { width: 390, height: 800 } });
		sessions.push(mobile);
		await mobile.page.goto(BASE + OTHER_PAGE);
		assert.ok(!(await mobile.page.locator('#nav-medication').isVisible()), 'the menu is closed at first');
		await mobile.page.locator('.navbar-toggler').click();
		await mobile.page.locator('#nav-medication > a').waitFor({ state: 'visible' });
		await mobile.page.locator('#nav-medication > a').click();
		await waitForList(mobile.page, true);
		assert.equal(await mobile.page.locator('#nav-medication > a').getAttribute('aria-expanded'), 'true');
		const refills = mobile.page.locator('#sub-nav-medication a[href$="/consumptionrefills"]');
		await refills.waitFor({ state: 'visible' });
		await Promise.all([mobile.page.waitForNavigation(), refills.click()]);
		assert.ok(mobile.page.url().endsWith('/consumptionrefills'), 'a child link navigates');
		assert.equal((await mobile.page.locator('h2.title').first().innerText()).trim(), 'Refills');
		step('mobile width: open the menu, open the flyout, follow a child');

		for (const session of sessions) assert.deepEqual(session.errors, [], 'no page errors');
		step('no page errors');
	}
	finally
	{
		for (const session of sessions) await session.context.close().catch(() => {});
		await browser.close();
	}
})().catch(error =>
{
	console.error('FAIL ' + (error && error.stack ? error.stack : error));
	process.exit(1);
});
