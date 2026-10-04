// The /choresoverview reschedule modal saves a reschedule with no assignee, in a real browser:
// node chore-reschedule.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Regression coverage for the reschedule modal's Save button sending the user picker's blank
// value as "". choresoverview.js PUT rescheduled_next_execution_assigned_to_user_id straight
// from Victual.Components.UserPicker.GetValue(), which reads back "" whenever no one is
// picked - always, with VICTUAL_FEATURE_FLAG_CHORES_ASSIGNMENTS off, as in demo mode. The
// column is a nullable integer, PostgreSQL refuses "" for it, and the browser got an opaque
// 400 naming no field: the reschedule was not saved. A PHPUnit test that PUTs null proves
// only that the server accepts null; PHP never runs public/viewjs, so it cannot see whether
// the save handler still turns "" into null. This drives the modal the way a person
// rescheduling a chore without assigning it would, the same way nullable-integer-forms.js
// (issues #574/#587) drives the EntityForm body() hooks with the same shape of defect.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';

		page.on('pageerror', error => { throw new Error('page error: ' + error.message); });

		async function api(path, method = 'GET', body)
		{
			return page.evaluate(async ({ path, method, body }) =>
			{
				const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
				if (!response.ok) throw new Error(path + ' -> ' + response.status + ' ' + await response.text());
				return response.status === 204 ? null : response.json();
			}, { path, method, body });
		}

		await page.goto(base + '/choresoverview');

		// Any active chore will do. The modal switches the date picker to a date-only format
		// for a chore that tracks no time (every demo chore does), so the date set below
		// follows the chore's own format.
		const chore = (await api('objects/chores')).find(candidate => candidate.active == 1);
		assert.ok(chore, 'the demo data has an active chore');
		const rescheduledDate = chore.track_date_only == 1 ? '2031-10-20' : '2031-10-20 08:00:00';

		// The reschedule button sits in a row dropdown; its handler is delegated on document,
		// so a dispatched click reaches it without opening the dropdown first.
		await page.locator('.reschedule-chore-button[data-chore-id="' + chore.id + '"]').first().dispatchEvent('click');
		await page.locator('#reschedule-chore-modal').waitFor({ state: 'visible' });

		// With chore assignments on, the modal may prefill an assignee; clear it through the
		// component's own public API so the blank-assignee path is what gets exercised.
		await page.evaluate((date) =>
		{
			Victual.Components.UserPicker.Clear();
			Victual.Components.DateTimePicker.SetValue(date);
		}, rescheduledDate);
		assert.equal(await page.evaluate(() => Victual.Components.UserPicker.GetValue()), '',
			'the user picker reads back "" with no one picked - the value the save must not send');

		// A successful save reloads the page once next assignments are recalculated; start
		// waiting for that before the click, or the reload can destroy the context the next
		// api() call runs in.
		const reload = page.waitForNavigation();
		reload.catch(() => {});
		const [response] = await Promise.all([
			page.waitForResponse(r => r.url().endsWith('/api/objects/chores/' + chore.id) && r.request().method() === 'PUT'),
			page.locator('#reschedule-chore-save-button').click()
		]);
		const sent = JSON.parse(response.request().postData());
		if (response.status() !== 204)
		{
			throw new Error('rescheduling with no assignee should save (got ' + response.status() + ': ' + await response.text() + ')');
		}
		assert.equal(sent.rescheduled_next_execution_assigned_to_user_id, null, 'a blank assignee is sent as null, not ""');

		await reload;
		const saved = await api('objects/chores/' + chore.id);
		assert.ok(saved.rescheduled_date && saved.rescheduled_date.startsWith('2031-10-20'),
			'the reschedule was stored (got ' + JSON.stringify(saved.rescheduled_date) + ')');
		assert.equal(saved.rescheduled_next_execution_assigned_to_user_id, null, 'the blank assignee is stored as NULL');

		// Put the chore back the way the modal's own Reset button does.
		await api('objects/chores/' + chore.id, 'PUT', { rescheduled_date: chore.rescheduled_date, rescheduled_next_execution_assigned_to_user_id: chore.rescheduled_next_execution_assigned_to_user_id });

		console.log('CHORE RESCHEDULE CHECKS PASSED');
	}
	finally
	{
		await browser.close();
	}
})();
