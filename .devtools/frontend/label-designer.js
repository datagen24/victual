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

/**
 * Selects the object at `from` and drags it to `to`, both canvas-relative pixel offsets.
 *
 * Selecting first, as its own click, matters: an unselected fabric object has no resize
 * handles yet, so a single press-drag-release starting exactly on a handle's future
 * position can miss it. The step waits and slow interpolation are not decoration either -
 * this is what a real cross-implementation break in fabric's object model looked like from
 * here: with the corner control silently offset (issue #126's origin regression, below),
 * a too-fast synthetic drag and a correctly-placed one were indistinguishable by their
 * *symptom* (nothing moved) even though only one of them was a real bug.
 */
async function selectAndDrag(page, canvasLocator, from, to)
{
	// Re-measured on every call, not cached by the caller: a save() re-renders the whole
	// page and can shift scroll position, and a stale bounding box drags nothing while
	// looking, to the assertions below, exactly like a real regression.
	await canvasLocator.scrollIntoViewIfNeeded();
	const box = await canvasLocator.boundingBox();
	const start = { x: box.x + from.x, y: box.y + from.y };
	const end = { x: box.x + to.x, y: box.y + to.y };

	await page.mouse.click(start.x, start.y);
	await page.waitForTimeout(200);
	await page.mouse.move(start.x, start.y);
	await page.mouse.down();
	await page.waitForTimeout(50);
	for (let step = 1; step <= 10; step++)
	{
		await page.mouse.move(start.x + (end.x - start.x) * step / 10, start.y + (end.y - start.y) * step / 10);
		await page.waitForTimeout(20);
	}
	await page.waitForTimeout(50);
	await page.mouse.up();
	await page.waitForTimeout(200);
}

/**
 * Clicks "Save draft" and waits for the PUT itself to complete, not for the text it leaves
 * behind.
 *
 * `report()` only ever replaces `#template-message`'s contents from inside a request's own
 * callback - clicking Save does not clear it first. So a second or third save in the same
 * page still has the *previous* "Draft saved" sitting in the DOM the instant the button is
 * clicked, and `getByText('Draft saved').waitFor()` resolves against that leftover text
 * before the new PUT has even reached the server. The document read straight after (a
 * `page.request.get()` on its own connection) can then race the save it was meant to follow.
 */
async function saveDraft(page, templateId)
{
	const saved = page.waitForResponse(response =>
		response.request().method() === 'PUT' &&
		response.url().includes('/api/labels/templates/' + templateId + '/draft'));
	await page.getByRole('button', { name: 'Save draft' }).click();
	const response = await saved;
	assert.ok(response.ok(), 'saving the draft succeeded: ' + response.status());
}

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	try {
		// Tall enough that the canvas never sits under the fixed top navbar after a scroll:
		// with the default viewport, a mouse click at a screen position that boundingBox()
		// reports as being on the canvas can land on the navbar instead once the page has
		// scrolled, and fabric never sees the mousedown at all.
		const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		const name = 'Designer probe ' + Date.now();
		await page.goto(base + '/labeltemplates');
		await page.locator('#new-template-name').fill(name);
		await page.getByRole('button', { name: 'Create a location label template' }).click();
		await page.waitForURL(/\/labeltemplate\/\d+$/);

		const templateId = Number(page.url().split('/').pop());
		await page.waitForFunction(() => document.querySelectorAll('#label-canvas').length > 0);

		// Fabric loads as an ES module shim (issue #126) that always runs before
		// DOMContentLoaded, so it is asserted first: this is what caught #114's regression,
		// and checking it after the draft-load wait below left it unreachable, since that
		// wait times out first when fabric never arrived.
		await page.waitForFunction(() => window.fabric !== undefined, null, { timeout: 20000 });

		// Wait for the draft to arrive before adding anything: the document is the state
		// and the canvas is a view of it, so an element added before the load lands would be
		// added to nothing.
		await page.waitForFunction(() => document.querySelector('#canvas-width-mm').value !== '', null, { timeout: 20000 });

		// A second QR, so the assertion below is about something the editor added rather than
		// about the starting draft.
		await page.locator('#element-kind').selectOption('qr');
		await page.locator('#add-element-button').click();
		await page.waitForFunction(() => document.querySelector('#element-properties').textContent.includes('qr1'), null, { timeout: 10000 });

		// Move, reload and resize: exercising the canvas rather than only the round-trip
		// through it. A rectangle, not the QR just added, is the fixture here - absorb()
		// skips width/height for a QR (its size comes from module count, not a drag), so
		// only a shape with both a position and a size proves the resize path.
		await page.locator('#element-kind').selectOption('rect');
		await page.locator('#add-element-button').click();
		await page.waitForFunction(() => document.querySelector('#element-properties').textContent.includes('rect1'), null, { timeout: 10000 });
		await saveDraft(page, templateId);

		const fetchRect1 = async () =>
		{
			const saved = await (await page.request.get(base + '/api/labels/templates/' + templateId + '/draft')).json();
			return saved.document.elements.find(element => element.id === 'rect1');
		};

		const canvas = page.locator('#label-canvas');
		const beforeMove = await fetchRect1();

		// rect1 starts at (2mm, 2mm), 20x10mm, drawn at MM_PER_PX=4 -> canvas px (8,8)-(88,48).
		await selectAndDrag(page, canvas, { x: 40, y: 24 }, { x: 100, y: 64 });
		await saveDraft(page, templateId);
		const afterMove = await fetchRect1();
		assert.notEqual(afterMove.x_mm, beforeMove.x_mm, 'dragging the shape moved it in the saved document (x_mm): ' + JSON.stringify({ beforeMove, afterMove }));
		assert.notEqual(afterMove.y_mm, beforeMove.y_mm, 'dragging the shape moved it in the saved document (y_mm): ' + JSON.stringify({ beforeMove, afterMove }));

		// A fresh load(), not merely the canvas already on screen: draw() must rebuild the
		// shape from the document without throwing, and the moved position must survive it.
		await page.reload();
		await page.waitForFunction(() => window.fabric !== undefined, null, { timeout: 20000 });
		await page.waitForFunction(() => document.querySelector('#canvas-width-mm').value !== '', null, { timeout: 20000 });
		const afterReload = await fetchRect1();
		assert.equal(afterReload.x_mm, afterMove.x_mm, 'reload reads back the moved position (x_mm)');
		assert.equal(afterReload.y_mm, afterMove.y_mm, 'reload reads back the moved position (y_mm)');

		// Resize by dragging the bottom-right control outward, by 32x16px (8x4mm).
		const bottomRight = { x: (afterReload.x_mm + afterReload.width_mm) * 4, y: (afterReload.y_mm + afterReload.height_mm) * 4 };
		await selectAndDrag(page, canvas, bottomRight, { x: bottomRight.x + 32, y: bottomRight.y + 16 });
		await saveDraft(page, templateId);
		const afterResize = await fetchRect1();
		// A plain notEqual would also pass on a *move*: absorb() writes back getScaledWidth(),
		// which includes strokeWidth, so any object:modified - a move included - nudges
		// width_mm/height_mm by a fraction of a millimetre. Asserting most of the 8x4mm drag
		// landed is what actually tells a resize from that drift.
		assert.ok(afterResize.width_mm - afterReload.width_mm >= 4, 'resizing grew width_mm by close to the dragged 8mm: ' + JSON.stringify({ afterReload, afterResize }));
		assert.ok(afterResize.height_mm - afterReload.height_mm >= 2, 'resizing grew height_mm by close to the dragged 4mm: ' + JSON.stringify({ afterReload, afterResize }));

		// That a text element pins a font is checked on the server rather than through the
		// element panel, because the panel's refusal depends on the instance having no font
		// uploaded - which an earlier probe, or a real household, may already have changed.
		// Publishing a document that names a font nobody stored is deterministic either way.
		const orphan = {
			schema_version: 1, entity_kind: 'location',
			canvas: { width_mm: 58.9, height_mm: 30.0, max_height_mm: null,
				margins_mm: { top: 1, right: 1, bottom: 1, left: 1 } },
			elements: [{ type: 'text', id: 'name', x_mm: 2, y_mm: 2, width_mm: 30, height_mm: 8,
				field: 'location.name', font_asset: 'no-such-font-' + Date.now(), size_pt: 10 }],
		};
		const scratch = await (await page.request.post(base + '/api/labels/templates',
			{ data: { name: 'Orphan font ' + Date.now(), entity_kind: 'location' } })).json();
		const scratchDraft = await (await page.request.get(base + '/api/labels/templates/' + scratch.id + '/draft')).json();
		await page.request.put(base + '/api/labels/templates/' + scratch.id + '/draft',
			{ data: { document: orphan, revision_token: scratchDraft.revision_token } });
		const refused = await page.request.post(base + '/api/labels/templates/' + scratch.id + '/publish', { data: {} });
		assert.equal(refused.status(), 422, 'publishing a document naming an unstored font is refused');
		const refusal = await refused.json();
		assert.equal(refusal.code, 'asset_unavailable', 'and the refusal names the asset: ' + JSON.stringify(refusal));

		await saveDraft(page, templateId);

		const draft = await (await page.request.get(base + '/api/labels/templates/' + templateId + '/draft')).json();
		const ids = draft.document.elements.map(element => element.id);
		assert.ok(ids.includes('qr1'), 'the element the editor added reached the server: ' + JSON.stringify(ids));
		assert.ok(ids.includes('code'), 'the starting draft is QR-only, so a new template can publish');

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
