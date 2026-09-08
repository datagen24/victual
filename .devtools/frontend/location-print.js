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

		await page.goto(base + '/locations');

		const button = page.locator('.location-print-button').first();
		if (await button.count() === 0) {
			// The action is gated on FEATURE_FLAG_LABELS and on a configured printer. A page
			// that offers no button is a correct page under that configuration, and saying so
			// is better than asserting against a control that was never meant to be there.
			console.log('SKIPPED: the print action is not enabled on this instance');
			process.exit(0);
		}

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

		// S29: the name is text, and nothing executed.
		assert.equal(await page.evaluate(() => window.__xss), undefined, 'no script ran');
		assert.ok((await status.innerText()).includes(payload), 'the location name is rendered as text');

		assert.deepEqual(errors, [], 'no page errors');
		console.log('PASS location print action');
	} finally {
		await browser.close();
	}
})();
