// The body() hooks in mealplansectionform.js, userfieldform.js and taskform.js convert a
// blank nullable field to null before it is sent, in a real browser:
// node nullable-integer-forms.js <url>
// Run against a disposable demo instance, from the frontend-security job.
//
// Regression coverage for the JS half of issue #574 and for issue #587. A PHPUnit test that
// PUTs a correctly-typed body proves the server accepts an explicit null; it cannot see
// whether the browser's own body() hook still turns a blank field into one, because PHP never
// runs public/viewjs. Deleting mealplansectionform.js's or userfieldform.js's body() hook, or
// reverting taskform.js's, keeps every PHPUnit phase green while breaking every save from the
// real form: serializeJSON() reports a blank <select>, numberpicker or date picker as "",
// which PostgreSQL refuses for the nullable INTEGER/DATE column underneath, arriving at the
// browser as an opaque 400 naming no field (BaseApiController::WithoutDriverText()). This
// drives each form exactly as a person leaving an optional field blank would, the way
// product-nullable-pickers.js (issue #159) does for the product form's own nullable pickers.
//
// mealplansectionform.js and userfieldform.js each convert only sort_number, so they share
// one check below. taskform.js converts three fields of three different shapes - category_id
// (a plain select), assigned_to_user_id (renamed from the user picker's own user_id) and
// due_date (read from the DateTimePicker component, not a plain input) - so it gets its own.
// Issue #587 was found by generalizing #574's reproduction to every EntityForm with the same
// gap, and is covered here rather than in a probe of its own for that reason.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () =>
{
	const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });

	try
	{
		const page = await browser.newPage();
		const base = process.argv[2] || 'http://127.0.0.1:8085';
		const token = Date.now().toString(36);

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

		/**
		 * The toast toastr renders, if any - null once the container is empty. Called right
		 * after a save that is expected to succeed, which triggers the form's own navigation
		 * away almost immediately - so a destroyed-execution-context error from a navigation
		 * that wins the race is read the same as "no toast": an error toast would have
		 * blocked that very navigation from starting at all.
		 */
		async function toastText()
		{
			try
			{
				return await page.evaluate(() =>
				{
					const el = document.querySelector('#toast-container');
					// The first line of innerText is the close button's glyph, which says nothing.
					return el ? el.innerText.split('\n').filter(l => l.trim() && l.trim() !== '×').join(' ') : null;
				});
			}
			catch (error)
			{
				return null;
			}
		}

		/**
		 * Clicks a save button and waits for the request it fires. A save the client-side
		 * validator blocks (a required field left empty) never fires the request at all, so
		 * that failure mode reaches this probe as a wait timeout rather than a hang.
		 */
		async function save(urlSuffix, method, buttonSelector)
		{
			const [response] = await Promise.all([
				page.waitForResponse(r => r.url().endsWith(urlSuffix) && r.request().method() === method),
				page.locator(buttonSelector).click()
			]);

			return response;
		}

		/**
		 * Asserts a response's status, reading the body only on failure. A save that
		 * succeeds triggers the form's own navigation away almost immediately (the same
		 * response handler that resolves this response also fires it), which can race
		 * Chromium's DevTools protocol into reporting the response body already gone by the
		 * time it is read - so the body must never be read unconditionally, only when the
		 * status is already wrong and there is a real failure to explain.
		 */
		async function assertStatus(response, expected, message)
		{
			if (response.status() !== expected)
			{
				throw new Error(message + ' (got ' + response.status() + ': ' + await response.text() + ')');
			}
		}

		await page.goto(base + '/stockoverview');

		/**
		 * Looks a just-created row up by name through the read-only, non-CDP-backed api()
		 * helper, rather than reading the id out of the save response: reading any part of
		 * a save response's body - even .json(), even after the status is already known -
		 * races the same-tick navigation a successful EntityForm save triggers, and lost
		 * that race in CI (twice: once via a .text() read building an assertion message,
		 * once via a .json() read for the id - see the commits that added and then fixed
		 * this probe). Only page.waitForResponse()'s own status/headers are ever read from
		 * a save's response; every value is re-fetched from the server once the page is
		 * stable, the same way product-nullable-pickers.js (issue #159) finds its own
		 * created row by name rather than by reading its create response.
		 */
		async function findByName(apiPath, name)
		{
			const row = (await api(apiPath)).find(candidate => candidate.name === name);
			assert.ok(row, 'a row named ' + JSON.stringify(name) + ' exists at ' + apiPath);

			return row;
		}

		/**
		 * The create-blank / create-zero / resave-both-unchanged sequence issue #574's JS
		 * half needs, shared between mealplansectionform.js and userfieldform.js: identical
		 * body() hook shape (sort_number only), identical three assertions, differing only in
		 * the entity endpoint, the form's own other required fields, and the id each create
		 * redirects away from (neither form's afterSave is overridden, so both land on their
		 * list page rather than the new row's edit page - the created id is looked up by
		 * name instead, per findByName()'s own docblock).
		 *
		 * @param {Object} config
		 * @param {string} config.label       Short name for assertion messages, e.g. 'meal plan section'
		 * @param {string} config.formPath    Root-relative create form path, e.g. '/mealplansection/new'
		 * @param {string} config.editPath    Root-relative edit form path prefix, e.g. '/mealplansection/'
		 * @param {string} config.entity      Generic entity API segment, e.g. 'meal_plan_sections'
		 * @param {string} config.saveButton  Save button selector
		 * @param {Function} config.fillRequired  async (variant) => string - fills every other
		 *        required field and returns the unique name it gave the row
		 */
		async function checkNullableSortNumberForm(config)
		{
			const apiPath = 'objects/' + config.entity;

			// --- create with sort_number left blank -> stored NULL, not "" -----------------
			await page.goto(base + config.formPath);
			const blankName = await config.fillRequired('Blank');
			const blankNavigation = page.waitForNavigation();
			const createBlank = await save('/api/' + apiPath, 'POST', config.saveButton);
			await assertStatus(createBlank, 200, config.label + ': a blank-sort_number create should succeed');
			await blankNavigation;

			const blankRow = await findByName(apiPath, blankName);
			const blankId = blankRow.id;
			assert.equal(blankRow.sort_number, null, config.label + ': a blank sort_number is stored as NULL, not coerced from ""');

			// --- create with sort_number 0, then resave that row unchanged -> stays 0 ------
			await page.goto(base + config.formPath);
			const zeroName = await config.fillRequired('Zero');
			await page.locator('#sort_number').fill('0');
			const zeroCreateNavigation = page.waitForNavigation();
			const createZero = await save('/api/' + apiPath, 'POST', config.saveButton);
			await assertStatus(createZero, 200, config.label + ': a sort_number of 0 should be accepted on create');
			await zeroCreateNavigation;

			const zeroId = (await findByName(apiPath, zeroName)).id;
			await page.goto(base + config.editPath + zeroId);
			assert.equal(await page.locator('#sort_number').inputValue(), '0', config.label + ': a stored 0 renders as "0", not blank');

			const zeroSaveNavigation = page.waitForNavigation();
			const saveZero = await save('/api/' + apiPath + '/' + zeroId, 'PUT', config.saveButton);
			await assertStatus(saveZero, 204, config.label + ': saving the unchanged 0 row should succeed');
			await zeroSaveNavigation;

			const zeroRow = await api(apiPath + '/' + zeroId);
			assert.equal(Number(zeroRow.sort_number), 0, config.label + ': the row keeps sort_number at exactly 0, not NULL');

			// --- resave the blank (NULL) row unchanged -> stays NULL, nothing errors -------
			await page.goto(base + config.editPath + blankId);
			assert.equal(await page.locator('#sort_number').inputValue(), '', config.label + ': a NULL sort_number renders blank');

			const nullSaveNavigation = page.waitForNavigation();
			const saveNull = await save('/api/' + apiPath + '/' + blankId, 'PUT', config.saveButton);
			await assertStatus(saveNull, 204, config.label + ': saving the unchanged NULL row should succeed');
			assert.equal(await toastText(), null, config.label + ': no error toast appears saving the unchanged NULL row');
			await nullSaveNavigation;

			const nullRow = await api(apiPath + '/' + blankId);
			assert.equal(nullRow.sort_number, null, config.label + ': the row keeps sort_number at NULL');

			console.log(config.label.toUpperCase() + ' NULLABLE SORT NUMBER CHECKS PASSED');
		}

		await checkNullableSortNumberForm({
			label: 'meal plan section',
			formPath: '/mealplansection/new',
			editPath: '/mealplansection/',
			entity: 'meal_plan_sections',
			saveButton: '#save-mealplansection-button',
			fillRequired: async (variant) =>
			{
				const name = 'WS587 MealPlanSection ' + variant + ' ' + token;
				await page.locator('#name').fill(name);

				return name;
			}
		});

		await checkNullableSortNumberForm({
			label: 'userfield',
			formPath: '/userfield/new',
			editPath: '/userfield/',
			entity: 'userfields',
			saveButton: '#save-userfield-button',
			fillRequired: async (variant) =>
			{
				// #name is pattern-restricted to [a-zA-Z0-9_] (it doubles as the API field
				// name); #caption carries no such restriction.
				const name = 'ws587_userfield_' + variant.toLowerCase() + '_' + token;
				await page.locator('#entity').selectOption({ index: 1 });
				await page.locator('#name').fill(name);
				await page.locator('#caption').fill('WS587 Userfield ' + variant + ' ' + token);
				await page.locator('#type').selectOption({ index: 1 });

				return name;
			}
		});

		// ================================================================================
		// TASK (issue #587). category_id (a plain select), the user picker's user_id (a
		// bootstrap-combobox-backed select, renamed to assigned_to_user_id) and due_date (a
		// DateTimePicker, not a plain input) are all driven to blank: the category select's
		// blank <option>, and the date picker's own default - create mode does not
		// initialise it to today (views/taskform.blade.php sets initWithNow to false).
		//
		// The user picker is the one field create mode does NOT default to blank:
		// taskform.blade.php prefills it to the logged-in user (VICTUAL_USER_ID) so a
		// person creating a task usually assigns it to themselves without having to touch
		// the picker at all. Leaving it untouched would therefore save a real user id, not
		// exercise the blank-assignee path issue #587 is about - so it is cleared through
		// the component's own public Clear() API, the same way a person removing the
		// default assignee would via the combobox's own clear affordance.
		// ================================================================================
		{
			const taskName = 'WS587 Task ' + token;
			const saveButton = '.save-task-button:not(.add-another)';

			await page.goto(base + '/task/new');
			await page.locator('#name').fill(taskName);
			await page.evaluate(() => Victual.Components.UserPicker.Clear());
			const taskCreateNavigation = page.waitForNavigation();
			const createTask = await save('/api/objects/tasks', 'POST', saveButton);
			await assertStatus(createTask, 200, 'a task with every optional field blank should be created');
			await taskCreateNavigation;

			const taskRow = await findByName('objects/tasks', taskName);
			const taskId = taskRow.id;
			assert.equal(taskRow.category_id, null, 'a blank category is stored as NULL, not coerced from ""');
			assert.equal(taskRow.assigned_to_user_id, null, 'a blank assignee is stored as NULL, not coerced from ""');
			assert.equal(taskRow.due_date, null, 'a blank due date is stored as NULL, not coerced from ""');

			// --- resave unchanged -> succeeds, all three stay NULL -------------------------
			await page.goto(base + '/task/' + taskId);
			const taskSaveNavigation = page.waitForNavigation();
			const saveTask = await save('/api/objects/tasks/' + taskId, 'PUT', saveButton);
			await assertStatus(saveTask, 204, 'saving the unchanged task should succeed');
			assert.equal(await toastText(), null, 'no error toast appears saving the unchanged task');
			await taskSaveNavigation;

			const resavedTask = await api('objects/tasks/' + taskId);
			assert.equal(resavedTask.category_id, null, 'category_id stays NULL through an untouched edit save');
			assert.equal(resavedTask.assigned_to_user_id, null, 'assigned_to_user_id stays NULL through an untouched edit save');
			assert.equal(resavedTask.due_date, null, 'due_date stays NULL through an untouched edit save');

			console.log('TASK NULLABLE FIELD CHECKS PASSED');
		}
	}
	finally
	{
		await browser.close();
	}
})();
