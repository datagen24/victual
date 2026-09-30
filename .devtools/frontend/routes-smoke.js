// Loads every view route in a browser and reports its HTTP status and any console
// problem. Plan 12's "load all view routes before and after: zero new console errors,
// zero non-200s" - the coarse net under the baseline harness, which walks the list and
// form pages in detail but does not touch the settings, journal, tracking or report
// pages at all.
//
//   node routes-smoke.js --url http://127.0.0.1:8500 --out /tmp/routes-before.json
//
// The route list is the non-API group of routes.php, with its {placeholders} filled from
// whatever the instance actually has.

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8500') || '').replace(/\/$/, '');
const OUT = arg('out', path.join(__dirname, 'routes-smoke.json'));

// Routes that are not HTML pages, or that deliberately end the session.
const SKIP = ['/logout', '/manifest', '/openapi/specification'];

const IGNORED_CONSOLE = [
	// Pre-existing and unrelated: the userobjects list throws for a user entity with no
	// userfields. Recorded in the 2026-09-02 baseline as the only console error in the run.
	"reading 'aDataSort'",
	// Demo pictures are fetched from releases.grocy.info, which the agent proxy denies, so
	// the demo generator writes 0-byte files and the browser reports a broken image.
	'Failed to load resource: the server responded with a status of 404',
	'Failed to load resource: net::ERR'
];

async function api(page, apiPath)
{
	return page.evaluate(async p =>
	{
		const response = await fetch(p);

		try
		{
			return JSON.parse(await response.text());
		}
		catch (e)
		{
			return null;
		}
	}, apiPath);
}

function firstId(rows)
{
	return Array.isArray(rows) && rows.length ? String(rows[0].id) : null;
}

(async () =>
{
	const routes = fs.readFileSync(path.join(__dirname, 'routes.txt'), 'utf8')
		.split('\n').map(l => l.trim()).filter(Boolean);

	const browser = await chromium.launch({ args: ['--no-sandbox'] });
	const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });

	const seed = await context.newPage();
	await seed.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
	await seed.waitForTimeout(400);

	const ids = {
		'{userfieldId}': firstId(await api(seed, '/api/objects/userfields')) || 'new',
		'{userentityId}': firstId(await api(seed, '/api/objects/userentities')) || 'new',
		'{userobjectId}': 'new',
		'{userId}': firstId(await api(seed, '/api/users')) || '1',
		'{productId}': firstId(await api(seed, '/api/objects/products')) || '1',
		'{quantityunitId}': firstId(await api(seed, '/api/objects/quantity_units')) || '1',
		'{quConversionId}': 'new',
		'{productGroupId}': firstId(await api(seed, '/api/objects/product_groups')) || 'new',
		'{entryId}': firstId(await api(seed, '/api/objects/stock')) || '1',
		'{locationId}': firstId(await api(seed, '/api/objects/locations')) || 'new',
		'{productBarcodeId}': 'new',
		'{shoppingLocationId}': firstId(await api(seed, '/api/objects/shopping_locations')) || 'new',
		'{itemId}': 'new',
		'{listId}': firstId(await api(seed, '/api/objects/shopping_lists')) || '1',
		'{recipeId}': firstId(await api(seed, '/api/objects/recipes')) || '1',
		'{recipePosId}': 'new',
		'{sectionId}': firstId(await api(seed, '/api/objects/meal_plan_sections')) || 'new',
		'{choreId}': firstId(await api(seed, '/api/objects/chores')) || 'new',
		'{batteryId}': firstId(await api(seed, '/api/objects/batteries')) || 'new',
		'{taskId}': firstId(await api(seed, '/api/objects/tasks')) || 'new',
		'{categoryId}': firstId(await api(seed, '/api/objects/task_categories')) || 'new',
		'{equipmentId}': firstId(await api(seed, '/api/objects/equipment')) || 'new'
	};

	const userentities = await api(seed, '/api/objects/userentities');
	ids['{userentityName}'] = (Array.isArray(userentities) && userentities.length) ? userentities[0].name : null;

	await seed.close();

	const results = [];

	for (const route of routes)
	{
		if (SKIP.includes(route))
		{
			continue;
		}

		let url = route;
		for (const key of Object.keys(ids))
		{
			if (url.indexOf(key) !== -1)
			{
				if (ids[key] === null)
				{
					url = null;
					break;
				}

				url = url.split(key).join(ids[key]);
			}
		}

		if (url === null)
		{
			results.push({ route, url: null, status: null, skipped: 'no object of that kind exists' });
			continue;
		}

		const page = await context.newPage();
		const problems = [];
		page.on('pageerror', e => problems.push('pageerror: ' + e.message));
		page.on('console', m =>
		{
			if (m.type() === 'error')
			{
				problems.push('console: ' + m.text());
			}
		});

		let status = null;

		try
		{
			const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
			status = response ? response.status() : null;
			await page.waitForTimeout(900);
		}
		catch (e)
		{
			problems.push('navigation: ' + e.message.split('\n')[0]);
		}

		const kept = problems.filter(p => !IGNORED_CONSOLE.some(i => p.indexOf(i) !== -1));
		results.push({ route, url, status, problems: Array.from(new Set(kept)) });
		console.log(String(status).padEnd(5) + url.padEnd(46) + (kept.length ? kept.length + ' problem(s)' : ''));

		await page.close();
	}

	await browser.close();

	fs.writeFileSync(OUT, JSON.stringify({ base: BASE, when: new Date().toISOString(), results }, null, '\t'));

	const bad = results.filter(r => r.status !== null && r.status !== 200);
	const noisy = results.filter(r => r.problems && r.problems.length);

	console.log('\n' + results.length + ' routes, ' + bad.length + ' non-200, ' + noisy.length + ' with console problems');
	for (const r of bad)
	{
		console.log('  non-200 ' + r.url + ' -> ' + r.status);
	}
	for (const r of noisy)
	{
		console.log('  noisy   ' + r.url + ' -> ' + r.problems.join(' | '));
	}
	console.log('wrote ' + OUT);
})();
