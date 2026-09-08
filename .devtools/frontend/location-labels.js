// Run against a disposable app: node .devtools/frontend/location-labels.js --url URL
// Network fixtures exercise presentation/races; identity-tests.php covers authorization
// and retirement against PostgreSQL. No label issuance endpoint is invented for this UI.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const urlIndex = process.argv.indexOf('--url');
const base = urlIndex < 0 ? 'http://127.0.0.1:8200' : process.argv[urlIndex + 1];
(async () => {
	const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try {
		const page = await browser.newPage();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.goto(base + '/locations');
		await page.getByRole('link', { name: 'Scan location label' }).click();
		await page.waitForLoadState('load');
		const input = page.locator('#location-label-code');
		const status = page.locator('#location-label-status');
		const name = page.locator('#location-label-name');
		const payload = '<img src=x onerror=window.__xss=1>';
		let oldRequest;
		await page.route('**/api/labels/resolve/**', async route => {
			const code = decodeURIComponent(new URL(route.request().url()).pathname.split('/').pop());
			if (code === 'slow') { oldRequest = route; return; }
			if (code === 'failure') { await route.fulfill({ status: 500, body: '{}' }); return; }
			const result = code === 'live' ? { status: 'resolved', kind: 'location', target: { name: payload } }
				: code === 'retired' ? { status: 'retired', kind: 'location', snapshot: { name: 'Former ' + payload } }
				: { status: 'unknown' };
			await route.fulfill({ json: result });
		});
		async function scan(code, expected) {
			await input.fill(code);
			await input.press('Enter');
			await page.waitForFunction(text => document.querySelector('#location-label-status').textContent === text, expected);
		}
		await scan('live', 'Location found');
		assert.equal(await name.textContent(), payload);
		assert.equal(await name.locator('img').count(), 0);
		await scan('retired', 'Retired label — this location was deleted.');
		assert.equal(await name.textContent(), 'Former ' + payload);
		await scan('unknown', 'Unknown location label');
		assert.equal(await name.textContent(), '');
		await scan('failure', 'Could not look up the label. Try again.');
		await scan('live', 'Location found'); // Recovery after failure.
		await input.fill('slow'); await input.press('Enter');
		for (let i = 0; !oldRequest && i < 500; i++) await page.waitForTimeout(10);
		assert.ok(oldRequest, 'slow lookup was issued');
		await scan('unknown', 'Unknown location label');
		await oldRequest.fulfill({ json: { status: 'resolved', kind: 'location', target: { name: 'Stale' } } });
		await page.waitForTimeout(100);
		assert.equal(await status.textContent(), 'Unknown location label');
		await page.evaluate(() => $(document).trigger('Victual.BarcodeScanned', ['live', '#location-label-code']));
		await page.waitForFunction(() => document.querySelector('#location-label-status').textContent === 'Location found');
		await input.fill('edited');
		assert.equal(await name.textContent(), '');
		assert.equal(await status.textContent(), '');
		assert.equal(await page.evaluate(() => window.__xss), undefined);
		await page.reload();
		assert.equal(await input.inputValue(), '');
		assert.equal(await name.textContent(), '');
		assert.deepEqual(errors, []);
		console.log('PASS location labels: live, retired, unknown, literal markup, failure/recovery, scan races, camera event, input clearing, reload');
	} finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
