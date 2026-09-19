'use strict';

// The browser half: the same pages and the same workflows, driven against both instances.
//
//   node run-ui.js --victual http://127.0.0.1:8080 --upstream http://127.0.0.1:8081
//                  [--headed] [--out ../reports]
//
// **What this checks that the API half cannot.** Every page in this application renders
// server-side through Blade and then wires itself up with jQuery, so a page can answer 200
// with a broken client and the API can be perfect throughout. Plan 12 rebuilt that client
// layer — `request()`, `Victual.EntityList`, `Victual.EntityForm` — and the fork's own
// baseline harness in `.devtools/frontend/` records what the pages do *here*. Nothing
// compared them to what they do upstream. That is this file.
//
// **What it deliberately does not do is compare pixels.** A screenshot diff of two
// separately branded applications reports the rename on every page, which is a report
// nobody reads twice. What is compared is the things a user would notice being broken: the
// HTTP status, the console, the network failures, whether the page's main table or form
// actually rendered, and how many rows it has.

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { VICTUAL_ADMIN_PASSWORD } = require('./lib/instance');
const { classifyUi } = require('./lib/accepted');

const ROUTES_FILE = path.join(__dirname, '..', '..', 'frontend', 'routes.txt');

// Routes that exist here and not upstream, or that cannot be compared. Each says why —
// the same rule the accepted-differences registry follows, for the same reason.
const FORK_ONLY_OR_SKIPPED = new Map([
	['/logout', 'ends the session, which would break every page after it in the walk'],
	['/barcodescannertesting', 'opens the camera; headless Chromium has none and the page waits'],
	['/manifest', 'a JSON manifest rather than a page — its content is the rename']
]);

function parseArgs(argv) {
	const args = {
		victual: process.env.PARITY_VICTUAL_URL || 'http://127.0.0.1:8080',
		upstream: process.env.PARITY_UPSTREAM_URL || 'http://127.0.0.1:8081',
		out: path.join(__dirname, '..', 'reports'),
		headed: false
	};
	for (let i = 2; i < argv.length; i++) {
		if (argv[i] === '--victual') { args.victual = argv[++i]; }
		else if (argv[i] === '--upstream') { args.upstream = argv[++i]; }
		else if (argv[i] === '--out') { args.out = argv[++i]; }
		else if (argv[i] === '--headed') { args.headed = true; }
	}
	return args;
}

// Only the routes with no path parameters. A route like /product/{productId} needs an id
// that exists on both instances, and the API scenarios already cover those objects far
// better than a page walk would.
function staticRoutes() {
	const raw = fs.readFileSync(ROUTES_FILE, 'utf8').split('\n').map((l) => l.trim());
	return raw.filter((l) => l.length > 0 && !l.includes('{') && !FORK_ONLY_OR_SKIPPED.has(l));
}

// **The password field is not `#password`, and the reason is worth knowing before
// changing this.** views/login.blade.php renders a visible `#password_input` and a hidden
// `#password_base64`; the form's script encodes the typed value into the hidden field on
// submit. Filling a field named `password` — which is what the API login posts and what a
// first guess reaches for — times out against a form that has no such input. The selector
// is a fallback chain rather than one id because upstream's template is the fork's
// ancestor and need not have kept the same ids, and a login that breaks on the *upstream*
// side would silently halve this walk.
async function login(page, baseUrl, adminPassword) {
	await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
	await page.fill('#username', 'admin');

	const password = page.locator('#password_input, #password, input[type=password]').first();
	await password.waitFor({ state: 'visible', timeout: 15000 });
	await password.fill(adminPassword);

	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
		page.click('#login-button, button[type=submit]')
	]);

	// Assert the login actually took. Walking 49 routes as an anonymous user would produce
	// 49 identical redirects to /login on both instances and report perfect parity.
	const stillOnLogin = /\/login\b/.test(page.url());
	if (stillOnLogin) {
		throw new Error(`${baseUrl}: login did not take — still on ${page.url()}`);
	}
}

// One page visit, reduced to the facts worth comparing. Console and page errors are
// collected per visit rather than per context so that a noisy page cannot be blamed on the
// one before it.
async function visit(page, baseUrl, route) {
	const consoleErrors = [];
	const pageErrors = [];
	const failedRequests = [];
	// Requests that completed with an error status. `requestfailed` above is network failure
	// only - a 404 is a completed request - and a console error such as "Failed to load
	// resource" names no URL, so without this there is no way to say which request a
	// console error was about. Recorded, not compared: it is evidence for the registry.
	const httpErrors = [];

	const onConsole = (msg) => {
		if (msg.type() === 'error') consoleErrors.push(msg.text());
	};
	const onPageError = (err) => pageErrors.push(String(err && err.message ? err.message : err));
	const onRequestFailed = (req) => failedRequests.push(`${req.method()} ${req.url()}`);
	const onResponse = (res) => {
		if (res.status() >= 400) httpErrors.push(`${res.status()} ${res.request().method()} ${res.url()}`);
	};

	page.on('console', onConsole);
	page.on('pageerror', onPageError);
	page.on('requestfailed', onRequestFailed);
	page.on('response', onResponse);

	let status = null;
	let error = null;
	try {
		const response = await page.goto(`${baseUrl}${route}`, {
			waitUntil: 'networkidle',
			timeout: 30000
		});
		status = response ? response.status() : null;
	} catch (e) {
		error = String(e.message || e);
	}

	// The structural facts. Row counts are compared because both instances ran the same
	// scenarios and therefore hold the same data — a table that renders with a different
	// number of rows is a real difference, not a styling one.
	let shape = { tables: 0, rows: 0, forms: 0, inputs: 0, hasMainContent: false };
	if (!error) {
		shape = await page.evaluate(() => ({
			tables: document.querySelectorAll('table').length,
			rows: document.querySelectorAll('table tbody tr').length,
			// The layout's logout control is a POST form on the fork and a link upstream (sweep
			// finding S8: as a GET it fired from any <img src> a page carried). It is on every
			// page, so counting it made all of them differ by one and hid a real change to
			// a page's own forms behind the noise.
			forms: [...document.querySelectorAll('form')]
				.filter((f) => !/\/logout$/.test(f.getAttribute('action') || '')).length,
			inputs: document.querySelectorAll('input, select, textarea').length,
			hasMainContent: !!document.querySelector('main, .content, #page-content, .container-fluid')
		}));
	}

	page.off('console', onConsole);
	page.off('pageerror', onPageError);
	page.off('requestfailed', onRequestFailed);
	page.off('response', onResponse);

	return {
		route,
		status,
		error,
		shape,
		// Normalised: the product name appears in messages and would otherwise differ on
		// every line. Store paths and ids are not in console text, so nothing else needs it.
		consoleErrors: consoleErrors.map(normalizeText),
		pageErrors: pageErrors.map(normalizeText),
		failedRequests: failedRequests.map((r) => normalizeText(r.replace(baseUrl, ''))),
		httpErrors: httpErrors.map((r) => r.replace(baseUrl, ''))
	};
}

function normalizeText(text) {
	return String(text)
		.replace(/victual/gi, '<product>')
		.replace(/grocy/gi, '<product>')
		.replace(/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/g, '<timestamp>');
}

function compareVisits(v, u) {
	const differences = [];

	if (v.error || u.error) {
		if (v.error !== u.error) {
			differences.push({
				kind: 'navigation',
				detail: `victual: ${v.error || 'ok'} / upstream: ${u.error || 'ok'}`
			});
		}
		return differences;
	}

	if (v.status !== u.status) {
		differences.push({ kind: 'status', detail: `HTTP ${v.status} against HTTP ${u.status}` });
	}

	// A console error on one side and not the other is the finding this walk exists for:
	// it is invisible in review, invisible in the API half, and obvious here.
	const vErrors = [...v.consoleErrors, ...v.pageErrors];
	const uErrors = [...u.consoleErrors, ...u.pageErrors];
	const onlyVictual = vErrors.filter((e) => !uErrors.includes(e));
	const onlyUpstream = uErrors.filter((e) => !vErrors.includes(e));

	for (const e of onlyVictual) {
		differences.push({ kind: 'console-only-victual', detail: e });
	}
	for (const e of onlyUpstream) {
		differences.push({ kind: 'console-only-upstream', detail: e });
	}

	for (const key of ['tables', 'rows', 'forms', 'hasMainContent']) {
		if (v.shape[key] !== u.shape[key]) {
			differences.push({
				kind: `shape:${key}`,
				detail: `${JSON.stringify(v.shape[key])} against ${JSON.stringify(u.shape[key])}`
			});
		}
	}

	const vFailed = v.failedRequests.filter((r) => !u.failedRequests.includes(r));
	for (const r of vFailed) {
		differences.push({ kind: 'request-failed-only-victual', detail: r });
	}

	return differences;
}

// A real booking, done the way a person does it: through the stock overview's dialogs.
// This is the workflow `.devtools/frontend/undo-toasts.js` proves works here; running it on
// both is what says the fork did not change what the user sees while doing it.
async function purchaseWorkflow(page, baseUrl, label) {
	const result = { name: label, steps: [], error: null };
	try {
		await page.goto(`${baseUrl}/purchase`, { waitUntil: 'networkidle', timeout: 30000 });

		// Not the layout's logout form (S8): it comes first in document order, inside a closed
		// dropdown, so "the first form" was invisible on the fork and nowhere else.
		const hasForm = await page.locator('#purchase-form, form:not([action$="/logout"])').first()
			.isVisible().catch(() => false);
		result.steps.push({ step: 'purchase form visible', value: hasForm });

		// The product picker is a combobox both projects render the same way. Typing into
		// it and reading back what it offered exercises the shared frontend core plan 12
		// built without depending on any particular product existing.
		const picker = page.locator('#product_id_text_input, input[name=product_id_text_input]').first();
		const pickerVisible = await picker.isVisible().catch(() => false);
		result.steps.push({ step: 'product picker present', value: pickerVisible });

		if (pickerVisible) {
			await picker.fill('Parity');
			await page.waitForTimeout(700);
			const options = await page.locator('#product_id_text_input ~ ul li, .typeahead__list li').count()
				.catch(() => -1);
			result.steps.push({ step: 'picker offered options', value: options > 0 });
		}

		const amount = page.locator('#display_amount, input[name=display_amount], #amount').first();
		result.steps.push({
			step: 'amount field present',
			value: await amount.isVisible().catch(() => false)
		});
	} catch (e) {
		result.error = normalizeText(String(e.message || e));
	}
	return result;
}

async function walk(browser, baseUrl, routes, name, adminPassword) {
	const context = await browser.newContext({ ignoreHTTPSErrors: true });
	const page = await context.newPage();
	await login(page, baseUrl, adminPassword);

	const visits = [];
	for (const route of routes) {
		process.stdout.write(`    ${name} ${route}\n`);
		visits.push(await visit(page, baseUrl, route));
	}

	const workflow = await purchaseWorkflow(page, baseUrl, 'purchase page');

	await context.close();
	return { visits, workflow };
}

async function main() {
	const args = parseArgs(process.argv);
	const routes = staticRoutes();

	console.log(`  ${routes.length} routes, both instances`);

	const browser = await chromium.launch({ headless: !args.headed });

	let victualWalk;
	let upstreamWalk;
	try {
		victualWalk = await walk(browser, args.victual, routes, 'victual ', VICTUAL_ADMIN_PASSWORD);
		upstreamWalk = await walk(browser, args.upstream, routes, 'upstream', 'admin');
	} finally {
		await browser.close();
	}

	const run = {
		startedAt: new Date().toISOString(),
		victual: args.victual,
		upstream: args.upstream,
		skipped: [...FORK_ONLY_OR_SKIPPED].map(([route, reason]) => ({ route, reason })),
		routes: [],
		workflow: { victual: victualWalk.workflow, upstream: upstreamWalk.workflow, differences: [] },
		totals: { routes: routes.length, withDifferences: 0, differences: 0, accepted: 0 }
	};

	// Classified the way the API phase classifies: an accepted difference is still recorded
	// and printed, with the record that accepted it, and only the unexplained ones count.
	for (let i = 0; i < routes.length; i++) {
		const victual = victualWalk.visits[i];
		const upstream = upstreamWalk.visits[i];
		const differences = compareVisits(victual, upstream).map((difference) => {
			const entry = classifyUi({ route: routes[i], difference, victual, upstream });
			return entry ? { ...difference, accepted: { id: entry.id, reference: entry.reference } } : difference;
		});
		run.routes.push({ route: routes[i], victual, upstream, differences });
		const reported = differences.filter((d) => !d.accepted);
		run.totals.accepted += differences.length - reported.length;
		if (reported.length > 0) {
			run.totals.withDifferences++;
			run.totals.differences += reported.length;
		}
	}

	// The workflow's steps are compared by name and value; a step present on one side only
	// is itself a difference.
	const vSteps = new Map(victualWalk.workflow.steps.map((s) => [s.step, s.value]));
	const uSteps = new Map(upstreamWalk.workflow.steps.map((s) => [s.step, s.value]));
	for (const key of new Set([...vSteps.keys(), ...uSteps.keys()])) {
		if (vSteps.get(key) !== uSteps.get(key)) {
			run.workflow.differences.push({
				step: key,
				detail: `${JSON.stringify(vSteps.get(key))} against ${JSON.stringify(uSteps.get(key))}`
			});
		}
	}
	run.totals.differences += run.workflow.differences.length;

	fs.mkdirSync(args.out, { recursive: true });
	fs.writeFileSync(path.join(args.out, 'ui-parity.json'), JSON.stringify(run, null, '\t'));

	console.log('');
	for (const r of run.routes) {
		const reported = r.differences.filter((d) => !d.accepted);
		if (reported.length === 0) continue;
		console.log(`  \x1b[31m${r.route}\x1b[0m`);
		for (const d of reported) {
			console.log(`      [${d.kind}] ${d.detail}`);
		}
	}
	if (run.totals.accepted > 0) {
		console.log(`  Accepted differences (${run.totals.accepted}) — found, classified, not failed:`);
		for (const r of run.routes) {
			for (const d of r.differences.filter((x) => x.accepted)) {
				console.log(`      ${r.route} [${d.kind}] ${d.detail}  — ${d.accepted.id}`);
			}
		}
	}
	for (const d of run.workflow.differences) {
		console.log(`  \x1b[31mpurchase workflow\x1b[0m  ${d.step}: ${d.detail}`);
	}

	console.log('');
	const ok = run.totals.differences === 0;
	console.log(ok
		? `\x1b[32mPASS — ${run.totals.routes} routes, identical status, console and structure\x1b[0m`
		: `\x1b[31mFAIL — ${run.totals.differences} differences across ${run.totals.withDifferences} routes\x1b[0m`);
	console.log(`  report: ${path.join(args.out, 'ui-parity.json')}`);

	process.exit(ok ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(2);
});
