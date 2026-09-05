// Plan 12 verification check 5: prove sweep finding S29 with a stored payload rather
// than by reading the diff.
//
// It seeds one record of each affected kind with a name of
// `&lt;img src=x onerror=window.__xss=1&gt;` - which the API's sanitiser stores as a
// *live* tag, because every column here is a text column and the text branch of
// `BaseApiController::GetParsedAndFilteredRequestBody` undoes the purifier's entity
// encoding on purpose. Then, on each page that lists or acts on that record, it opens
// the delete confirmation (or triggers the success toast) and asserts two things:
//
//   1. `window.__xss` is still undefined - nothing executed;
//   2. the payload is present as visible *text* in the dialog / toast body.
//
// One probe seeds nothing: the payload arrives in a *server error message* instead,
// injected by intercepting the route and answering 500, because
// `Victual.FrontendHelpers.ShowGenericError` renders the technical details through
// bootbox - and on PostgreSQL a uniqueness violation quotes the offending value back.
//
// A third family asks the opposite question of the others. Five columns are rendered as
// HTML on purpose - the rich text a summernote editor writes - so escaping them is not an
// option and the boundary is HTMLPurifier, server side, in
// `BaseApiController::HTML_RENDERED_COLUMNS`. That boundary is load-bearing security code
// with six render sinks behind it and, until plan 21, nothing asserting it holds. This
// family writes live payloads to each of the five through the API, reads them back, and
// fails on any stored value that still carries an event handler, a script, an iframe or a
// javascript: URI - then loads a page that renders one and checks nothing executed.
//
// A fourth family seeds nothing either, and is here because its absence is what let two
// live sinks reach master in September 2026 and sit open until an external scanner found
// them (plan 21). Everything above takes its payload from the *database*, so a sink fed by
// input the browser never sent anywhere is invisible to all of it: a chosen file's name
// rendered into its `.custom-file-label`, and a barcode typed at
// `/barcodescannertesting` echoed into `#scanned_codes`. Both were `.html()` / string
// concatenation and both executed. They are driven entirely from the page.
//
// Run it against the unfixed head first. There it must report `xss: 1` and exit non-zero,
// otherwise the check is not capable of failing and proves nothing.
//
//   node s29-payload.js --url http://127.0.0.1:8500 --out /tmp/s29-before.json
//
// `--only locations,products` limits the run to the named probes.
//
// Exits non-zero if any probe is not clean, so it can be run as a gate. A probe counts as
// failed when the payload executed, when an `<img>` was injected, when the payload is not
// visible as text, when the sink never appeared, or when the action threw - "the sink was
// not found" and "the action errored" are failures, not skips, because a run in which
// every action silently did nothing must not be able to pass.

const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');

// Names are unique per entity, so a re-run against the same database has to seed
// distinguishable records; the token is appended outside the payload itself.
const RUN_TOKEN = 's29-' + Date.now().toString(36);
const PAYLOAD_ENCODED = '&lt;img src=x onerror=window.__xss=1&gt; ' + RUN_TOKEN;
const PAYLOAD_LIVE = '<img src=x onerror=window.__xss=1> ' + RUN_TOKEN;

// The local-input probes need the payload as a *file name* on disk, because the only way
// to reach the file-label sink is to actually choose a file. Every character in the
// payload is legal in a POSIX file name; the one that would not be, "/", does not appear -
// and `GetFileNameFromPath` splits on it, so a payload containing one would be truncated
// by the code under test rather than by the check.
const PAYLOAD_FILE_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 's29-'));
const PAYLOAD_FILE = path.join(PAYLOAD_FILE_DIR, PAYLOAD_LIVE + '.png');

// A 1x1 PNG. Nothing decodes it - the sink renders the *name* - but a file that is what
// its extension claims keeps the probe honest against a form that starts validating.
fs.writeFileSync(PAYLOAD_FILE, Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'));

// Written live (not entity encoded) into every HTML-rendered column, because that is what
// an attacker sends. Each has to come back stripped of the part that executes; the last one
// has to come back intact, since a boundary that also destroys legitimate formatting would
// be reported as a pass by a check that only looks for danger.
const HTML_COLUMN_PAYLOADS = [
	['img-onerror', '<img src=x onerror=window.__xss=1>'],
	['script', '<script>window.__xss=1</' + 'script>'],
	['svg-onload', '<svg onload=window.__xss=1></svg>'],
	['body-onload', '<body onload=window.__xss=1>'],
	['a-javascript-uri', '<a href="javascript:window.__xss=1">click</a>'],
	['iframe', '<iframe src="https://example.invalid/"></iframe>'],
	['object-data', '<object data="javascript:window.__xss=1"></object>'],
	['style-expression', '<div style="background:url(javascript:window.__xss=1)">x</div>'],
	// Not an attack. The formatting summernote's own toolbar produces, which has to survive.
	['legitimate-formatting', '<h1>Notes</h1><p><b>bold</b> <span style="background-color: rgb(255, 255, 0);">mark</span></p><ul><li>one</li></ul>']
];

/** What must never survive into storage, whatever shape the payload arrived in. */
const DANGEROUS = [
	[/<script/i, 'a <script> tag'],
	[/<iframe/i, 'an <iframe>'],
	[/<svg/i, 'an <svg> element'],
	[/<object/i, 'an <object>'],
	[/<embed/i, 'an <embed>'],
	[/\son[a-z]+\s*=/i, 'an inline event handler'],
	[/javascript\s*:/i, 'a javascript: URI']
];

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8500') || '').replace(/\/$/, '');
const OUT = arg('out', path.join(__dirname, 's29-payload.json'));
const ONLY = (arg('only', '') || '').split(',').map(s => s.trim()).filter(Boolean);

/** Seeds one object through the API, from inside the page so the session cookie applies. */
async function apiPost(page, apiPath, body)
{
	return page.evaluate(async ([p, b]) =>
	{
		const response = await fetch(p, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(b)
		});
		const text = await response.text();

		try
		{
			return { status: response.status, body: JSON.parse(text) };
		}
		catch (e)
		{
			return { status: response.status, body: text };
		}
	}, [apiPath, body]);
}

async function apiGet(page, apiPath)
{
	return page.evaluate(async p =>
	{
		const response = await fetch(p);
		const text = await response.text();

		try
		{
			return JSON.parse(text);
		}
		catch (e)
		{
			return text;
		}
	}, apiPath);
}

/** Writes one value to an object's column through the API and reads the stored value back. */
async function apiPut(page, apiPath, column, value)
{
	return page.evaluate(async ([p, c, v]) =>
	{
		await fetch(p, {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ [c]: v })
		});

		const response = await fetch(p);
		const object = await response.json();

		return object ? object[c] : null;
	}, [apiPath, column, value]);
}

/**
 * Writes every payload in the battery to one HTML-rendered column and reports what came
 * back. A column is clean when nothing dangerous survived *and* the legitimate formatting
 * did: a purifier that deleted everything would otherwise pass.
 */
async function checkHtmlColumn(page, entity, apiPath)
{
	const reasons = [];
	const storedByPayload = {};

	for (const [name, payload] of HTML_COLUMN_PAYLOADS)
	{
		let stored;

		try
		{
			stored = await apiPut(page, apiPath, 'description', payload);
		}
		catch (e)
		{
			reasons.push(name + ': the write threw: ' + e.message);
			continue;
		}

		storedByPayload[name] = stored;

		if (name === 'legitimate-formatting')
		{
			// Any of the four is enough: HTMLPurifier reformats (it drops the space in
			// "rgb(255, 255, 0)"), so an exact comparison would fail on tidying rather
			// than on loss.
			const survived = ['<h1>', '<b>', '<ul>', 'background-color'].filter(f => String(stored || '').includes(f));

			if (survived.length < 4)
			{
				reasons.push('legitimate formatting did not survive: kept ' + survived.length + ' of 4 markers - ' + JSON.stringify(stored));
			}

			continue;
		}

		for (const [pattern, description] of DANGEROUS)
		{
			if (pattern.test(String(stored || '')))
			{
				reasons.push(name + ': ' + description + ' survived as ' + JSON.stringify(stored));
			}
		}
	}

	return {
		probe: 'html-column:' + entity, url: apiPath, xss: null, visibleText: null,
		sinkFound: true, imgInjected: 0, error: null, precomputed: true,
		reasons: reasons, stored: storedByPayload
	};
}

/**
 * The render half. Storage being inert is the boundary; this is the assertion that the
 * sinks behind it behave - a page that renders one of these columns as HTML, driven with a
 * payload sitting in it, must execute nothing and must contain no dangerous node. Unlike
 * the seeded families there is no "visible as text" assertion here, because being rendered
 * as markup is what these columns are for.
 */
async function checkHtmlRender(context, name, url, action)
{
	const page = await newProbePage(context);
	const reasons = [];

	try
	{
		await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(1200);
		await action(page);
		await page.waitForTimeout(1000);

		if (await page.evaluate(() => window.__xss))
		{
			reasons.push('THE PAYLOAD EXECUTED (window.__xss set)');
		}

		const dangerous = await page.evaluate(() => ({
			handlers: document.querySelectorAll('[onerror],[onload],[onclick],[onmouseover]').length,
			frames: document.querySelectorAll('iframe:not(#shopping-list-stock-add-workflow-purchase-form-frame)').length,
			scripts: document.querySelectorAll('#description-for-print script, .note-editable script').length
		}));

		if (dangerous.handlers)
		{
			reasons.push(dangerous.handlers + ' element(s) with an inline event handler in the document');
		}

		if (dangerous.scripts)
		{
			reasons.push(dangerous.scripts + ' <script> inside a rendered description');
		}
	}
	catch (e)
	{
		reasons.push('the action threw: ' + String(e.message).split('\n')[0]);
	}

	await page.close();

	return {
		probe: name, url: url, xss: null, visibleText: null, sinkFound: true,
		imgInjected: 0, error: null, precomputed: true, reasons: reasons
	};
}

/** Fresh page with `window.__xss` unset and every console message captured. */
/**
 * Creates an API key the way the manage-keys page does: by submitting the description as a
 * form POST to /manageapikeys/new, and waiting for the page that response renders.
 *
 * Both halves of that shape are wave 2's. It was a GET with the description in the query
 * string until sweep finding S8; and it redirected to /manageapikeys?key=N until the keys
 * became hashes, after which the create response is the only place the plaintext can ever
 * be shown and so has to be the page the browser lands on.
 */
async function createApiKey(page, description)
{
	await page.evaluate((args) =>
	{
		const form = document.createElement('form');
		form.method = 'post';
		form.action = args.action;
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'description';
		input.value = args.description;
		form.appendChild(input);
		document.body.appendChild(form);
		form.submit();
	}, { action: BASE + '/manageapikeys/new', description: description });

	await page.waitForSelector('#new-api-key-value', { timeout: 15000 });
}

async function newProbePage(context)
{
	const page = await context.newPage();
	page.on('pageerror', () => { });
	return page;
}

/**
 * Where each probe's payload is expected to land, and how to read it back.
 *
 * The two seeded families render into a component that is on screen by the time the
 * assertion runs, so `innerText` is the right read: it asserts the payload arrived as
 * *visible* text rather than merely being somewhere in the subtree. The local-input sinks
 * cannot use it. `#scanned_codes` is a `<select>`, whose `innerText` is not its options'
 * text, and `.custom-file-label` carries `d-none` until a picture exists - neither is a
 * statement about whether markup was injected, which is the question being asked.
 */
const SINKS = {
	dialog: { selector: '.bootbox', read: 'innerText' },
	toast: { selector: '#toast-container', read: 'innerText' },
	// The label the delegated handler's `.next()` resolves to on /product/new, by id: the
	// page carries a second `.custom-file-label` ("No file selected") that is never written
	// to, and a class selector would read that one instead and report a clean sink forever.
	'file-label': { selector: '#product-picture-label', read: 'textContent' },
	'scanned-codes': { selector: '#scanned_codes', read: 'textContent' }
};

/**
 * Opens `url`, performs `action(page)` - which must make the named sink appear carrying
 * the payload - and reports whether the payload executed and whether it is present as
 * text. `setup`, when given, runs against the fresh page *before* the navigation, which
 * is where `page.route()` interception has to be registered.
 */
async function probe(context, name, url, action, sink, setup)
{
	const target = SINKS[sink];

	if (!target)
	{
		// A typo in a probe's sink name would otherwise read as "the sink never appeared",
		// which is a failure about the application rather than about this file.
		return { probe: name, url: url, xss: null, visibleText: null, sinkFound: false,
			imgInjected: 0, error: 'unknown sink "' + sink + '" - not one of ' + Object.keys(SINKS).join(', ') };
	}

	const page = await newProbePage(context);
	const record = { probe: name, url: url, xss: null, visibleText: null, sinkFound: false, imgInjected: 0, error: null };

	try
	{
		if (setup)
		{
			await setup(page);
		}

		await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
		await page.waitForTimeout(900);

		// The payload may already have fired on load (rendered markup), so read it before
		// and after and report either.
		await action(page);
		await page.waitForTimeout(1200);

		const selector = target.selector;
		// The last match, not the first: a probe that goes through more than one dialog
		// leaves the earlier one in the DOM, and it is the newest one that holds the
		// payload.
		const visible = await page.evaluate(([s, read]) =>
		{
			const el = Array.from(document.querySelectorAll(s)).pop();
			return el ? el[read] : null;
		}, [selector, target.read]);

		record.sinkFound = visible !== null;
		record.visibleText = visible === null ? null : (visible.indexOf('<img src=x onerror=') !== -1);
		record.xss = await page.evaluate(() => window.__xss);
		record.imgInjected = await page.evaluate(s =>
		{
			const el = Array.from(document.querySelectorAll(s)).pop();
			return el ? el.querySelectorAll('img[src="x"]').length : 0;
		}, selector);
	}
	catch (e)
	{
		record.error = e.message;
	}

	await page.close();
	return record;
}

/**
 * Turns one probe record into a verdict. Every way a probe can be uninformative is a
 * failure: a sink that never appeared and an action that threw both mean the assertion
 * was never made, and a run of those must not be able to report success.
 * @param {Object} record One entry of `results`
 * @returns {{ok: boolean, reasons: string[]}}
 */
function verdict(record)
{
	const reasons = [];

	// The HTML-column family computes its own reasons: "the payload is visible as text" is
	// the wrong assertion for a column whose whole purpose is to be rendered as markup.
	if (record.precomputed)
	{
		return { ok: record.reasons.length === 0, reasons: record.reasons };
	}

	if (record.seedFailure)
	{
		return { ok: false, reasons: ['the record was never created, so nothing could be probed'] };
	}

	if (record.error)
	{
		// Playwright's messages carry a multi-line call log; the first line is what belongs
		// in a one-line-per-probe summary, and the full text is in the JSON report.
		reasons.push('the action threw: ' + String(record.error).split('\n')[0]);
	}

	if (!record.sinkFound)
	{
		reasons.push('no dialog or toast appeared - the sink was never reached');
	}

	if (record.xss)
	{
		reasons.push('THE PAYLOAD EXECUTED (window.__xss = ' + JSON.stringify(record.xss) + ')');
	}

	if (record.imgInjected)
	{
		reasons.push(record.imgInjected + ' injected <img> in the sink');
	}

	if (record.sinkFound && record.visibleText !== true)
	{
		reasons.push('the payload is not present as visible text');
	}

	return { ok: reasons.length === 0, reasons: reasons };
}

// Module scope, not a local of the run below, so the catch at the bottom can close it. A
// browser left open holds the event loop open, and the process then hangs instead of
// exiting - which in CI is a job that runs to its timeout rather than a gate that fails.
let browser = null;

(async () =>
{
	browser = await chromium.launch({ args: ['--no-sandbox'], executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH });
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

	// One page just for seeding; hitting / first establishes the demo session.
	const seed = await context.newPage();
	await seed.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
	await seed.waitForTimeout(500);

	const ids = {};
	const stored = {};

	const quantityUnits = await apiGet(seed, '/api/objects/quantity_units');
	const locations = await apiGet(seed, '/api/objects/locations');
	const fallbackQu = Array.isArray(quantityUnits) && quantityUnits.length ? quantityUnits[0].id : 1;
	const fallbackLocation = Array.isArray(locations) && locations.length ? locations[0].id : 1;

	const seeds = [
		['role', '/api/roles', { code: RUN_TOKEN.replace(/-/g, '_').toUpperCase(), name: PAYLOAD_LIVE }],
		['location', '/api/objects/locations', { name: PAYLOAD_ENCODED }],
		['chore', '/api/objects/chores', { name: PAYLOAD_ENCODED, period_type: 'manually', period_days: 1 }],
		['quantityunit', '/api/objects/quantity_units', { name: PAYLOAD_ENCODED, name_plural: PAYLOAD_ENCODED }],
		['shoppinglist', '/api/objects/shopping_lists', { name: PAYLOAD_ENCODED }],
		['task', '/api/objects/tasks', { name: PAYLOAD_ENCODED }],
		['battery', '/api/objects/batteries', { name: PAYLOAD_ENCODED }],
		['equipment', '/api/objects/equipment', { name: PAYLOAD_ENCODED }],
		['taskcategory', '/api/objects/task_categories', { name: PAYLOAD_ENCODED }],
		['productgroup', '/api/objects/product_groups', { name: PAYLOAD_ENCODED }],
		['shoppinglocation', '/api/objects/shopping_locations', { name: PAYLOAD_ENCODED }],
		['userentity', '/api/objects/userentities', { name: RUN_TOKEN.replace(/-/g, '_'), caption: PAYLOAD_ENCODED, description: PAYLOAD_ENCODED }]
	];

	for (const [key, apiPath, body] of seeds)
	{
		const result = await apiPost(seed, apiPath, body);
		ids[key] = result.body && result.body.created_object_id;
		console.log('seed  ' + key + ' -> ' + JSON.stringify(result.status) + ' id ' + ids[key]);
	}

	const product = await apiPost(seed, '/api/objects/products', {
		name: PAYLOAD_ENCODED,
		location_id: fallbackLocation,
		qu_id_purchase: fallbackQu,
		qu_id_stock: fallbackQu,
		qu_id_consume: fallbackQu,
		qu_id_price: fallbackQu
	});
	ids.product = product.body && product.body.created_object_id;
	console.log('seed  product -> ' + product.status + ' id ' + ids.product);

	// A recipe and one ingredient on it, whose *note* carries the payload.
	// `recipes_pos` has no entry in BaseApiController::HTML_RENDERED_COLUMNS, so `note` is
	// a text column and is stored as a live tag exactly like the names above. The recipe
	// form writes it into `data-recipe-pos-note` and `recipeform.js` reads it back with
	// `.attr()`, which decodes it again, before handing it to `bootbox.alert()`.
	const recipe = await apiPost(seed, '/api/objects/recipes', { name: PAYLOAD_ENCODED, base_servings: 1 });
	ids.recipe = recipe.body && recipe.body.created_object_id;
	console.log('seed  recipe -> ' + recipe.status + ' id ' + ids.recipe);

	if (ids.recipe && ids.product)
	{
		const recipePos = await apiPost(seed, '/api/objects/recipes_pos', {
			recipe_id: ids.recipe,
			product_id: ids.product,
			amount: 1,
			note: PAYLOAD_ENCODED
		});
		ids.recipepos = recipePos.body && recipePos.body.created_object_id;
		console.log('seed  recipepos -> ' + recipePos.status + ' id ' + ids.recipepos);
	}

	// The API key description is not written through the JSON body path, so the key is
	// created the way the UI creates it, and twice the shape of that has changed:
	// sweep finding S8 made /manageapikeys/new a POST rather than a GET, so this submits a
	// form; and the key is now stored as a hash (plan 11, question 4), so the response
	// *renders* the page with the plaintext shown once instead of redirecting to it. There
	// is therefore no "?key=" in the URL to read the id out of - it comes off the row the
	// page highlights.
	await createApiKey(seed, PAYLOAD_LIVE);
	ids.apikey = await seed.evaluate(() =>
	{
		const button = document.querySelector('tr.table-info .apikey-delete-button');
		return button ? button.getAttribute('data-apikey-id') : null;
	});
	console.log('seed  apikey -> id ' + ids.apikey);

	// Read the stored values back, so the record proves the sanitiser really stored a live
	// tag rather than the entity encoded text that was sent.
	for (const [key, entity] of [
		['location', 'locations'], ['chore', 'chores'], ['quantityunit', 'quantity_units'],
		['shoppinglist', 'shopping_lists'], ['task', 'tasks'], ['battery', 'batteries'],
		['product', 'products'], ['equipment', 'equipment'],
		['taskcategory', 'task_categories'], ['productgroup', 'product_groups'],
		['shoppinglocation', 'shopping_locations'], ['recipe', 'recipes']
	])
	{
		if (!ids[key])
		{
			continue;
		}

		const row = await apiGet(seed, '/api/objects/' + entity + '/' + ids[key]);
		stored[key] = row && (row.name || row.description);
	}

	if (ids.recipepos)
	{
		const row = await apiGet(seed, '/api/objects/recipes_pos/' + ids.recipepos);
		stored.recipepos = row && row.note;
	}

	await seed.close();

	// Several of these actions live behind a Bootstrap dropdown, which has to be opened
	// before the item inside it is clickable.
	const openMenuThen = (menuSelector, selector) => async page =>
	{
		await page.locator(menuSelector).first().click();
		await page.waitForTimeout(400);
		await page.locator(selector).first().click();
	};

	const clickDelete = selector => async page =>
	{
		// .first(): several pages render the same action more than once per row
		// (choresoverview has three track buttons), and strict mode would reject the click.
		await page.locator(selector).first().click();
	};

	const probes = [
		['locations', '/locations', clickDelete('.location-delete-button[data-location-id="' + ids.location + '"]'), 'dialog'],
		['chores', '/chores', clickDelete('.chore-delete-button[data-chore-id="' + ids.chore + '"]'), 'dialog'],
		['quantityunits', '/quantityunits', clickDelete('.quantityunit-delete-button[data-quantityunit-id="' + ids.quantityunit + '"]'), 'dialog'],
		['products', '/products', clickDelete('.product-delete-button[data-product-id="' + ids.product + '"]'), 'dialog'],
		['shoppinglist', '/shoppinglist?list=' + ids.shoppinglist, openMenuThen('.dropdown:has(#delete-selected-shopping-list) [data-toggle="dropdown"]', '#delete-selected-shopping-list'), 'dialog'],
		['manageapikeys', '/manageapikeys', clickDelete('.apikey-delete-button[data-apikey-id="' + ids.apikey + '"]'), 'dialog'],
		// The QR dialog moved with the hashing: a stored key is a hash, so there is nothing
		// to encode on a regular key's row and that button is gone. The sink itself is not -
		// the description still reaches a bootbox message rendered as HTML - it is now on the
		// one-time reveal the create response shows. So this probe creates a key rather than
		// finding one, which is the only place that dialog exists.
		['manageapikeys-qr', '/manageapikeys', async page =>
		{
			await createApiKey(page, PAYLOAD_LIVE);
			await page.locator('.alert-success .apikey-show-qr-button').first().click();
		}, 'dialog'],
		['tasks-delete', '/tasks', clickDelete('.delete-task-button[data-task-id="' + ids.task + '"]'), 'dialog'],
		['tasks-toast', '/tasks', clickDelete('.do-task-button[data-task-id="' + ids.task + '"]'), 'toast'],
		['batteries', '/batteries', clickDelete('.battery-delete-button[data-battery-id="' + ids.battery + '"]'), 'dialog'],
		['equipment', '/equipment', openMenuThen('tr:has(.equipment-delete-button[data-equipment-id="' + ids.equipment + '"]) [data-toggle="dropdown"]', '.equipment-delete-button[data-equipment-id="' + ids.equipment + '"]'), 'dialog'],
		['taskcategories', '/taskcategories', clickDelete('.task-category-delete-button[data-category-id="' + ids.taskcategory + '"]'), 'dialog'],
		['roles', '/roles', clickDelete('.role-delete-button[data-role-id="' + ids.role + '"]'), 'dialog'],
		['productgroups', '/productgroups', clickDelete('.product-group-delete-button[data-group-id="' + ids.productgroup + '"]'), 'dialog'],
		['shoppinglocations', '/shoppinglocations', clickDelete('.shoppinglocation-delete-button[data-shoppinglocation-id="' + ids.shoppinglocation + '"]'), 'dialog'],
		['batteriesoverview', '/batteriesoverview', async page =>
		{
			await page.locator('.track-charge-cycle-button[data-battery-id="' + ids.battery + '"]').first().click();
		}, 'toast'],
		['choresoverview', '/choresoverview', async page =>
		{
			await page.locator('a.btn.track-chore-button:not(.skip)[data-chore-id="' + ids.chore + '"]').first().click();
		}, 'toast'],
		// The recipe form's "show notes" button: the note round-trips through
		// `data-recipe-pos-note`, so the Blade template's attribute escaping is undone by
		// the `.attr()` that reads it back, and bootbox renders the result as HTML.
		['recipeform-note', '/recipe/' + ids.recipe, clickDelete('.recipe-pos-show-note-button'), 'dialog'],
		// The technical-details dialog behind the generic error toast. Nothing is seeded
		// for this one: the payload is the server's *error message*, which is what a
		// uniqueness violation on PostgreSQL quotes back. The route, the page and the
		// click-through are forced-failure.js check 1, which is the sequence already known
		// to reach this dialog; only the body of the 500 differs.
		['error-details', '/locations', async page =>
		{
			await page.locator('.location-delete-button').first().click();
			await page.waitForTimeout(500);
			await page.locator('.bootbox .btn-success').first().click();
			await page.waitForTimeout(900);
			await page.locator('#toast-container .toast, #toast-container > div').first().click();
		}, 'dialog', async page =>
		{
			await page.route('**/api/objects/locations/*', route => route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error_message: PAYLOAD_LIVE })
			}));
		}],
		// --- local input: nothing is seeded and nothing is stored ---------------------
		//
		// The payload is chosen or typed in the browser and rendered straight back, so no
		// amount of care on the API's side is involved. One `.custom-file-input` probe
		// covers all four forms that have one: the sink is a single delegated handler in
		// victual.js, and the four Blade blocks are the same markup copied, so a second
		// probe would be asserting that a copy is still a copy.
		['file-name', '/product/new', async page =>
		{
			await page.setInputFiles('input.custom-file-input', PAYLOAD_FILE);
		}, 'file-label'],
		// The scanned barcode is echoed into an <option>. #scanned_barcode stays disabled
		// until #expected_barcode has more than one character, and the keyup handler is
		// what enables it - hence type() rather than fill() for that first field.
		['barcode-echo', '/barcodescannertesting', async page =>
		{
			await page.type('#expected_barcode', '1234');
			await page.waitForTimeout(300);
			await page.fill('#scanned_barcode', PAYLOAD_LIVE);
			await page.press('#scanned_barcode', 'Enter');
		}, 'scanned-codes']
	];

	const results = [];

	// A seed that did not come back with an id is reported as its own failure, so the
	// summary says "the record was never created" rather than leaving the reader to infer
	// it from a probe that could not find its button.
	for (const key of ['location', 'chore', 'quantityunit', 'shoppinglist', 'task', 'battery',
		'equipment', 'taskcategory', 'productgroup', 'shoppinglocation', 'product', 'apikey',
		'recipe', 'recipepos'])
	{
		if (!ids[key])
		{
			results.push({
				probe: 'seed:' + key, url: null, xss: null, visibleText: null,
				sinkFound: false, imgInjected: 0, error: null, seedFailure: true
			});
		}
	}

	// --- the HTML-rendered columns, before the page probes ------------------------
	//
	// Every one of the five is written and read back, rather than a representative: the
	// list is a per-entity constant, so an entity dropped out of it is exactly the mistake
	// that would not show up anywhere else.
	const htmlColumns = [
		['shopping_lists', '/api/objects/shopping_lists/' + ids.shoppinglist],
		['products', '/api/objects/products/' + ids.product],
		['recipes', '/api/objects/recipes/' + ids.recipe],
		['equipment', '/api/objects/equipment/' + ids.equipment],
		['chores', '/api/objects/chores/' + ids.chore]
	];

	if (!ONLY.length || ONLY.includes('html-columns'))
	{
		// Its own page: the seeding one is closed by the time this runs, and a fetch from a
		// closed page throws per call - which this family would have reported as nine
		// purifier failures per column rather than as its own mistake.
		const writer = await context.newPage();
		await writer.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
		await writer.waitForTimeout(400);

		for (const [entity, apiPath] of htmlColumns)
		{
			const record = await checkHtmlColumn(writer, entity, apiPath);
			results.push(record);
			console.log('probe ' + record.probe.padEnd(22) + ' offences=' + record.reasons.length);
		}

		// Leave a live payload in the column the render probe below reads, then prove the
		// sink behind the boundary is clean too. The shopping list's description is the
		// sink CodeQL reported (shoppinglist.js:560, alert #17): it is only ever reached
		// through the print dialog, so the probe has to open it.
		await apiPut(writer, '/api/objects/shopping_lists/' + ids.shoppinglist, 'description',
			'<img src=x onerror=window.__xss=1><svg onload=window.__xss=1></svg>');
		await writer.close();

		const rendered = await checkHtmlRender(context, 'description-render',
			'/shoppinglist?list=' + ids.shoppinglist, async page =>
			{
				// The Print entry is a dropdown item, and its dialog's confirm button is
				// what actually writes #description-for-print.
				await openMenuThen('.dropdown:has(#print-shopping-list-button) [data-toggle="dropdown"]',
					'#print-shopping-list-button')(page);
				await page.waitForTimeout(800);
				await page.locator('.bootbox .btn-primary').first().click();
			});
		results.push(rendered);
		console.log('probe ' + rendered.probe.padEnd(22) + ' offences=' + rendered.reasons.length);
	}

	for (const [name, url, action, sink, setup] of probes)
	{
		if (ONLY.length && !ONLY.includes(name))
		{
			continue;
		}

		const record = await probe(context, name, url, action, sink, setup);
		results.push(record);
		console.log('probe ' + name.padEnd(22) + ' xss=' + String(record.xss) +
			' text=' + String(record.visibleText) +
			' img=' + String(record.imgInjected) +
			(record.error ? ' ERROR ' + record.error : ''));
	}

	await browser.close();

	for (const record of results)
	{
		const outcome = verdict(record);
		record.ok = outcome.ok;
		record.reasons = outcome.reasons;
	}

	const failed = results.filter(r => !r.ok);
	const report = {
		base: BASE, when: new Date().toISOString(), runToken: RUN_TOKEN,
		payloadSent: PAYLOAD_ENCODED, ids, stored, results,
		passed: results.length - failed.length, failed: failed.length
	};
	fs.writeFileSync(OUT, JSON.stringify(report, null, '\t'));

	console.log('\nStored values read back from the API:');
	for (const key of Object.keys(stored))
	{
		console.log('  ' + key.padEnd(18) + JSON.stringify(stored[key]));
	}

	console.log('\nVerdict per probe:');
	for (const record of results)
	{
		console.log((record.ok ? 'PASS  ' : 'FAIL  ') + record.probe.padEnd(24) + record.reasons.join('; '));
	}

	console.log('\nwrote ' + OUT);

	// The seeded records are left behind on purpose (the database is throwaway and the
	// values are evidence); the payload-named file is not part of that record and is this
	// process's own litter.
	fs.rmSync(PAYLOAD_FILE_DIR, { recursive: true, force: true });

	if (!results.length)
	{
		// An empty run is a failure: it is what a mistyped --only produces, and reporting
		// "0 failed" for it would be the same lie as passing on a run where nothing fired.
		console.log('\nFAIL  no probe ran at all' + (ONLY.length ? ' - --only matched nothing: ' + ONLY.join(',') : ''));
		process.exitCode = 1;
		return;
	}

	console.log('\n' + (results.length - failed.length) + '/' + results.length + ' probes clean');

	if (failed.length)
	{
		process.exitCode = 1;
	}
})().catch(async e =>
{
	// Seeding or the browser launch itself falling over is a failed run, not a silent one:
	// without this the harness would report nothing and exit on Node's default handling.
	console.error('\nFAIL  the run itself threw before any verdict: ' + e.message);
	process.exitCode = 1;

	// And the browser has to be closed on this path too, or the process never exits.
	if (browser)
	{
		try
		{
			await browser.close();
		}
		catch (closeError)
		{
			console.error('and the browser would not close either: ' + closeError.message);
		}
	}
});
