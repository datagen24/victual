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

		/** The toast toastr renders, if any - null once the container is empty. */
		async function toastText()
		{
			return page.evaluate(() =>
			{
				const el = document.querySelector('#toast-container');
				// The first line of innerText is the close button's glyph, which says nothing.
				return el ? el.innerText.split('\n').filter(l => l.trim() && l.trim() !== '×').join(' ') : null;
			});
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

		await page.goto(base + '/stockoverview');

		/**
		 * The create-blank / create-zero / resave-both-unchanged sequence issue #574's JS
		 * half needs, shared between mealplansectionform.js and userfieldform.js: identical
		 * body() hook shape (sort_number only), identical three assertions, differing only in
		 * the entity endpoint, the form's own other required fields, and the id each create
		 * redirects away from (neither form's afterSave is overridden, so both land on their
		 * list page rather than the new row's edit page - the created id is read from the
		 * POST response body instead).
		 *
		 * @param {Object} config
		 * @param {string} config.label       Short name for assertion messages, e.g. 'meal plan section'
		 * @param {string} config.formPath    Root-relative create form path, e.g. '/mealplansection/new'
		 * @param {string} config.editPath    Root-relative edit form path prefix, e.g. '/mealplansection/'
		 * @param {string} config.entity      Generic entity API segment, e.g. 'meal_plan_sections'
		 * @param {string} config.saveButton  Save button selector
		 * @param {Function} config.fillRequired  async (variant) => void - fills every other required field
		 */
		async function checkNullableSortNumberForm(config)
		{
			const apiPath = 'objects/' + config.entity;

			// --- create with sort_number left blank -> stored NULL, not "" -----------------
			await page.goto(base + config.formPath);
			await config.fillRequired('Blank');
			const createBlank = await save('/api/' + apiPath, 'POST', config.saveButton);
			assert.equal(createBlank.status(), 200, config.label + ': a blank-sort_number create should succeed: ' + await createBlank.text());
			await page.waitForNavigation();

			const blankId = (await createBlank.json()).created_object_id;
			const blankRow = await api(apiPath + '/' + blankId);
			assert.equal(blankRow.sort_number, null, config.label + ': a blank sort_number is stored as NULL, not coerced from ""');

			// --- create with sort_number 0, then resave that row unchanged -> stays 0 ------
			await page.goto(base + config.formPath);
			await config.fillRequired('Zero');
			await page.locator('#sort_number').fill('0');
			const createZero = await save('/api/' + apiPath, 'POST', config.saveButton);
			assert.equal(createZero.status(), 200, config.label + ': a sort_number of 0 should be accepted on create: ' + await createZero.text());
			await page.waitForNavigation();

			const zeroId = (await createZero.json()).created_object_id;
			await page.goto(base + config.editPath + zeroId);
			assert.equal(await page.locator('#sort_number').inputValue(), '0', config.label + ': a stored 0 renders as "0", not blank');

			const saveZero = await save('/api/' + apiPath + '/' + zeroId, 'PUT', config.saveButton);
			assert.equal(saveZero.status(), 204, config.label + ': saving the unchanged 0 row should succeed: ' + await saveZero.text());
			await page.waitForNavigation();

			const zeroRow = await api(apiPath + '/' + zeroId);
			assert.equal(Number(zeroRow.sort_number), 0, config.label + ': the row keeps sort_number at exactly 0, not NULL');

			// --- resave the blank (NULL) row unchanged -> stays NULL, nothing errors -------
			await page.goto(base + config.editPath + blankId);
			assert.equal(await page.locator('#sort_number').inputValue(), '', config.label + ': a NULL sort_number renders blank');

			const saveNull = await save('/api/' + apiPath + '/' + blankId, 'PUT', config.saveButton);
			assert.equal(saveNull.status(), 204, config.label + ': saving the unchanged NULL row should succeed: ' + await saveNull.text());
			assert.equal(await toastText(), null, config.label + ': no error toast appears saving the unchanged NULL row');
			await page.waitForNavigation();

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
				await page.locator('#name').fill('WS587 MealPlanSection ' + variant + ' ' + token);
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
				await page.locator('#entity').selectOption({ index: 1 });
				await page.locator('#name').fill('ws587_userfield_' + variant.toLowerCase() + '_' + token);
				await page.locator('#caption').fill('WS587 Userfield ' + variant + ' ' + token);
				await page.locator('#type').selectOption({ index: 1 });
			}
		});

		// ================================================================================
		// TASK (issue #587). category_id (a plain select), the user picker's user_id (a
		// bootstrap-combobox-backed select, renamed to assigned_to_user_id) and due_date (a
		// DateTimePicker, not a plain input) are all left at their default blank state:
		// the category select's blank <option>, the user picker's blank <option value="">,
		// and the date picker's own default - create mode does not initialise it to today
		// (views/taskform.blade.php sets initWithNow to false).
		// ================================================================================
		{
			const taskName = 'WS587 Task ' + token;
			const saveButton = '.save-task-button:not(.add-another)';

			await page.goto(base + '/task/new');
			await page.locator('#name').fill(taskName);
			const createTask = await save('/api/objects/tasks', 'POST', saveButton);
			assert.equal(createTask.status(), 200, 'a task with every optional field blank should be created: ' + await createTask.text());
			await page.waitForNavigation();

			const taskId = (await createTask.json()).created_object_id;
			const taskRow = await api('objects/tasks/' + taskId);
			assert.equal(taskRow.category_id, null, 'a blank category is stored as NULL, not coerced from ""');
			assert.equal(taskRow.assigned_to_user_id, null, 'a blank assignee is stored as NULL, not coerced from ""');
			assert.equal(taskRow.due_date, null, 'a blank due date is stored as NULL, not coerced from ""');

			// --- resave unchanged -> succeeds, all three stay NULL -------------------------
			await page.goto(base + '/task/' + taskId);
			const saveTask = await save('/api/objects/tasks/' + taskId, 'PUT', saveButton);
			assert.equal(saveTask.status(), 204, 'saving the unchanged task should succeed: ' + await saveTask.text());
			assert.equal(await toastText(), null, 'no error toast appears saving the unchanged task');
			await page.waitForNavigation();

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
