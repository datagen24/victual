// The label designer: node .devtools/frontend/label-designer.js --url URL
//
// Drives the real editor against a real instance: create a template, add a QR, drag it,
// save, publish, and read back what the server stored. The assertions are about the
// *document*, not about the canvas - the canvas is a view of the document, and a test that
// only asked Fabric what it thought the design was would pass on an editor that never told
// the server anything.
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

		const name = 'Designer probe ' + Date.now();
		await page.goto(base + '/labeltemplates');
		await page.locator('#new-template-name').fill(name);
		await page.getByRole('button', { name: 'Create a location label template' }).click();
		await page.waitForURL(/\/labeltemplate\/\d+$/);

		const templateId = Number(page.url().split('/').pop());
		await page.waitForFunction(() => document.querySelectorAll('#label-canvas').length > 0);

		// Wait for the draft to arrive before adding anything: the document is the state
		// and the canvas is a view of it, so an element added before the load lands would be
		// added to nothing.
		await page.waitForFunction(() => document.querySelector('#canvas-width-mm').value !== '', null, { timeout: 20000 });
		await page.waitForFunction(() => window.fabric !== undefined, null, { timeout: 20000 });

		// A second QR, so the assertion below is about something the editor added rather than
		// about the starting draft.
		await page.locator('#element-kind').selectOption('qr');
		await page.locator('#add-element-button').click();
		await page.waitForFunction(() => document.querySelector('#element-properties').textContent.includes('qr1'), null, { timeout: 10000 });

		// Adding text with no font uploaded says so rather than producing a document that
		// cannot publish. A template pins the font it prints with; there is no default and no
		// substitution.
		await page.locator('#element-kind').selectOption('text');
		await page.locator('#add-element-button').click();
		await page.waitForFunction(
			() => /font/i.test(document.querySelector('#template-message').textContent),
			null, { timeout: 10000 });

		await page.getByRole('button', { name: 'Save draft' }).click();
		await page.getByText('Draft saved', { exact: false }).waitFor();

		const draft = await (await page.request.get(base + '/api/labels/templates/' + templateId + '/draft')).json();
		const ids = draft.document.elements.map(element => element.id);
		assert.ok(ids.includes('qr1'), 'the element the editor added reached the server: ' + JSON.stringify(ids));
		assert.ok(!ids.some(id => id.startsWith('text')), 'no text element was added without a font');

		// A QR's payload is not editable, and the document format refuses one that tries.
		const qr = draft.document.elements.find(element => element.type === 'qr');
		assert.equal(qr.source, 'label.payload', 'a QR encodes the label identifier and nothing else');

		// Publishing takes the stored draft. The text element the starting document carries
		// pins a font nobody uploaded, so publishing refuses by naming the asset - which is
		// the refusal a designer needs rather than a failed render later.
		await page.getByRole('button', { name: 'Publish a version' }).click();
		// Publishing saves first and then publishes, so the message passes through "Draft
		// saved" on the way. Waiting for the outcome rather than reading whatever is there
		// is the difference between a probe and a race.
		await page.waitForFunction(
			() => /asset|Published|version/i.test(document.querySelector('#template-message').textContent),
			null, { timeout: 15000 });
		const text = await page.locator('#template-message').innerText();
		// A new template is QR-only, so it publishes: a household that has uploaded nothing
		// still gets a label that prints and scans.
		assert.ok(/Published/.test(text), 'a new template publishes without an uploaded asset: ' + text);

		const versions = await (await page.request.get(base + '/api/labels/templates/' + templateId + '/versions')).json();
		assert.equal(versions.length, 1, 'one published version');
		assert.match(versions[0].document_digest, /^[0-9a-f]{64}$/, 'the version carries its digest');

		assert.deepEqual(errors, [], 'no page errors');
		console.log('PASS label designer: ' + text.trim());
	} finally {
		await browser.close();
	}
})().catch(error => { console.error(error); process.exitCode = 1; });
