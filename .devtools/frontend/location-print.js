// The location print action: node .devtools/frontend/location-print.js --url URL
//
// Network fixtures rather than a live subsystem, because what this probe is about is the
// browser half - the idempotency key across a retry, the epoch read at request time, and the
// location name landing in the status region as text. Whether the job is real is
// artifact-tests.php's question and it answers it against PostgreSQL.
//
// The XSS payload is the one sweep finding S29 is about: a location name is household data
// that arrives from the database, and it reaches this region.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

const urlIndex = process.argv.indexOf('--url');
const base = urlIndex < 0 ? 'http://127.0.0.1:8200' : process.argv[urlIndex + 1];
const payload = '<img src=x onerror=window.__xss=1>';

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try {
		const page = await browser.newPage();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		const keys = [];
		let epochRequests = 0;
		let failNext = true;

		await page.route('**/api/labels/locations/*/context', async route => {
			epochRequests++;
			await route.fulfill({ json: { id: 1, name: payload, import_epoch: 7 } });
		});

		await page.route('**/api/labels/locations/*/print', async route => {
			const request = route.request();
			keys.push(request.headers()['idempotency-key']);
			const body = JSON.parse(request.postData() || '{}');
			assert.equal(body.import_epoch, 7, 'the request carries the epoch it was composed at');
			assert.ok(body.printer_id > 0, 'the request names a printer');

			if (failNext) {
				failNext = false;
				await route.fulfill({ status: 422, json: { field: 'printer_id', code: 'inactive_printer', error_message: 'Printer is offline' } });
				return;
			}
			await route.fulfill({ status: 202, json: { job_id: 5, label_uid: '0ABCDEFGHJKMN', state: 'awaiting_artifact' } });
		});

		// A location whose own name carries the payload, because the status region renders the
		// name from the row rather than from any response - seeding it in a mocked reply would
		// have tested nothing. This is the sink sweep finding S29 is about.
		//
		// The stored name is not the payload as sent: the API's purifier strips the handler
		// and leaves the tag, which is the storage half of S29 doing its job. What this probe
		// is about is the other half - that whatever *is* stored reaches the status region as
		// text. So the row is matched on the tag rather than on the string that was posted,
		// and a name already present from an earlier run is reused rather than re-seeded.
		await page.request.post(base + '/api/objects/locations', { data: { name: payload } });
		await page.goto(base + '/locations');

		// Not a skip. The instance this runs against has FEATURE_FLAG_LABELS on and
		// label-printers.js has already configured a printer, so the control must be here -
		// and a probe that shrugged when it was missing is how a TypeError in exactly this
		// branch reached a running instance with every check green.
		const button = page.locator('.location-print-button[data-location-name^="<img"]').first();
		assert.ok(await button.count() > 0, 'the locations list offers a print control for the seeded location');
		const stored = await button.getAttribute('data-location-name');
		assert.ok(stored.includes('<img'), 'the stored name still carries markup: ' + stored);
		assert.ok(!/onerror/i.test(stored), 'the API purifier stripped the handler at storage: ' + stored);

		// The form offers the same action, and it is the other page that renders the branch.
		const formPage = await browser.newPage();
		const formResponse = await formPage.goto(base + '/location/1');
		assert.equal(formResponse.status(), 200, 'the location form renders with the print action');
		assert.ok(await formPage.locator('#location-form-print-button').count() > 0, 'the form offers a print control');
		await formPage.close();

		const status = page.locator('#location-print-status');

		await button.click();
		await status.getByText('The label was not requested', { exact: false }).waitFor();
		assert.ok((await status.innerText()).includes('Printer is offline'), 'the refusal says what it was');

		// The same intention, retried. The key must be the one the failed attempt used:
		// nothing was created, so this is not a second deliberate print.
		await button.click();
		await status.getByText('It prints once its image', { exact: false }).waitFor();
		assert.equal(keys.length, 2, 'two requests');
		assert.equal(keys[0], keys[1], 'a retry after a refusal reuses the key');

		// A second deliberate print, after one succeeded, is a new intention.
		await button.click();
		await page.waitForFunction(() => true);
		await status.getByText('It prints once its image', { exact: false }).waitFor();
		assert.equal(keys.length, 3, 'three requests');
		assert.notEqual(keys[2], keys[1], 'a second deliberate print gets a new key');

		assert.equal(epochRequests, 3, 'the epoch is read immediately before each request');

		// S29: whatever is stored reaches the region as text, and nothing executed.
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'no script ran');
		assert.equal(await page.locator('#location-print-status img').count(), 0, 'the markup did not become an element');
		assert.ok((await status.innerText()).includes(stored), 'the stored name is rendered as text');

		assert.deepEqual(errors, [], 'no page errors');
		console.log('PASS location print action');
	} finally {
		await browser.close();
	}
})();
