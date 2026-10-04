// Issue #650, ADR-0027 decision 2: the browser shows every instant in the viewer's device
// zone, sends every typed time with the device's offset, and leaves the server's days -
// due today, overdue, a date-only chore's day - in the server's configured zone.
//
//   node timestamp-instants.js --url http://127.0.0.1:8085
//
// Run it against a demo instance whose PHP date.timezone differs from both device zones
// below (America/New_York was used), so a conversion that skipped a zone, or used the
// wrong one, shows. It creates one chore ("Tz650 probe timed") and books executions, a
// charge and a task completion through the UI, then reads them back through the API.
//
// Device zones: Europe/London, five hours ahead of New York, and Pacific/Auckland, on the
// other side of midnight from it. Each zone's own DST edges are typed into the picker: a
// wall clock the device skipped must be refused, a repeated one must name the earlier
// instant.
//
// Exits non-zero on any failed check.

const { chromium } = require('playwright');

function arg(name, fallback)
{
	const i = process.argv.indexOf('--' + name);
	return i === -1 ? fallback : process.argv[i + 1];
}

const BASE = (arg('url', 'http://127.0.0.1:8085')).replace(/\/$/, '');
const WIRE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;
let failures = 0;

function check(name, ok, detail)
{
	if (!ok) failures++;
	console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? '  -- ' + detail : ''));
}

// An instant's wall clock in a zone, as "YYYY-MM-DD HH:mm:ss", by the platform's own zone
// data rather than the code under test.
function wallClockIn(iso, zone)
{
	return new Intl.DateTimeFormat('sv-SE', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit',
		hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '');
}

function dateIn(iso, zone)
{
	return wallClockIn(iso, zone).substring(0, 10);
}

async function api(page, method, path, body)
{
	return page.evaluate(async ([method, path, body]) =>
	{
		const response = await fetch('/api/' + path, { method, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
		const text = await response.text();
		return { status: response.status, body: text ? JSON.parse(text) : null };
	}, [method, path, body]);
}

async function typeInto(page, value)
{
	await page.evaluate((v) =>
	{
		Victual.Components.DateTimePicker.SetValue(v);
		Victual.Components.DateTimePicker.GetInputElement().trigger('keyup');
	}, value);
}

(async () =>
{
	const browser = await chromium.launch();

	for (const zone of ['Europe/London', 'Pacific/Auckland'])
	{
		const page = await (await browser.newContext({ timezoneId: zone, locale: 'en-GB' })).newPage();
		const errors = [];
		page.on('pageerror', (e) => errors.push(e.message));
		await page.goto(BASE + '/choresoverview', { waitUntil: 'networkidle' });
		const serverZone = await page.evaluate(() => Victual.ServerTimezone);
		check(`${zone}: the page names the server zone`, typeof serverZone === 'string' && serverZone !== zone, serverZone);

		// A timed chore, created and executed with explicit offsets.
		const created = await api(page, 'POST', 'objects/chores', { name: 'Tz650 probe timed ' + zone, period_type: 'daily', period_interval: 1, start_date: '2026-10-04T23:30:00-04:00', track_date_only: 0, active: 1 });
		const choreId = created.body.created_object_id;
		await api(page, 'POST', `chores/${choreId}/execute`, { tracked_time: '2026-10-03T23:30:00-04:00' });
		const dateOnly = (await api(page, 'GET', 'objects/chores?query[]=track_date_only=1&limit=1')).body[0];

		await page.goto(BASE + '/choresoverview', { waitUntil: 'networkidle' });
		const shown = await page.evaluate(([choreId, dateOnlyId]) =>
		{
			const n = $('#info-due-soon-chores').data('next-x-days');
			const mismatches = [];
			$('[id$=-next-execution-time-timeago]').each(function ()
			{
				const id = this.id.split('-')[1];
				const attr = $(this).attr('datetime');
				const server = ($('#chore-' + id + '-due-filter-column').text().trim().split(/\s+/)[0]) || '';
				const browser = attr ? Victual.Instant.DueType(attr, n) : '';
				if (server !== browser) mismatches.push(id + ': server ' + server + ', browser ' + browser);
			});
			return {
				lastText: $('#chore-' + choreId + '-last-tracked-time').text().trim(),
				lastAttr: $('#chore-' + choreId + '-last-tracked-time-timeago').attr('datetime'),
				nextText: $('#chore-' + choreId + '-next-execution-time').text().trim(),
				nextAttr: $('#chore-' + choreId + '-next-execution-time-timeago').attr('datetime'),
				dateOnlyText: $('#chore-' + dateOnlyId + '-next-execution-time').text().trim(),
				dateOnlyAttr: $('#chore-' + dateOnlyId + '-next-execution-time-timeago').attr('datetime'),
				mismatches
			};
		}, [choreId, dateOnly.id]);
		check(`${zone}: the markup carries the instant`, WIRE.test(shown.lastAttr), shown.lastAttr);
		check(`${zone}: last tracked is shown in the device zone`, shown.lastText === wallClockIn(shown.lastAttr, zone), shown.lastText);
		check(`${zone}: next execution is shown in the device zone`, shown.nextText === wallClockIn(shown.nextAttr, zone), shown.nextText);
		if (shown.dateOnlyAttr)
		{
			check(`${zone}: a date-only chore shows the server's day`, shown.dateOnlyText === dateIn(shown.dateOnlyAttr, serverZone), `${shown.dateOnlyText} (device day ${dateIn(shown.dateOnlyAttr, zone)})`);
		}
		check(`${zone}: due categories are the server's`, shown.mismatches.length === 0, shown.mismatches.join('; '));

		// A typed time, through the tracking form.
		await page.goto(BASE + '/choretracking', { waitUntil: 'networkidle' });
		await page.evaluate((id) => $('#chore_id').val(id).trigger('change'), choreId);
		await page.waitForTimeout(800);
		await typeInto(page, '2026-10-04 10:15:00');
		await page.locator('.save-choretracking-button:not(.skip)').click();
		await page.waitForTimeout(2500);
		const log = (await api(page, 'GET', `objects/chores_log?query[]=chore_id=${choreId}&order=id:desc&limit=1`)).body[0];
		check(`${zone}: a typed wall clock is booked as the device's instant`, wallClockIn(log.tracked_time, zone) === '2026-10-04 10:15:00' && WIRE.test(log.tracked_time), log.tracked_time);

		// The device zone's own DST edges.
		const edges = zone === 'Europe/London'
			? { gap: '2027-03-28 01:30:00', overlap: '2026-10-25 01:30:00', earlier: '2026-10-25T00:30:00.000Z' }
			: { gap: '2026-09-27 02:30:00', overlap: '2027-04-04 02:30:00', earlier: '2027-04-03T13:30:00.000Z' };
		await typeInto(page, edges.gap);
		const gap = await page.evaluate(() => ({ instant: Victual.Components.DateTimePicker.GetInstant(), valid: Victual.Components.DateTimePicker.GetInputElement()[0].checkValidity() }));
		check(`${zone}: a wall clock the device skipped is refused`, gap.instant === null && gap.valid === false, JSON.stringify(gap));
		await typeInto(page, edges.overlap);
		const overlap = await page.evaluate(() => Victual.Components.DateTimePicker.GetInstant());
		check(`${zone}: a repeated wall clock names the earlier instant`, overlap !== null && new Date(overlap).toISOString() === edges.earlier, overlap);

		// "Now" buttons book the current instant.
		const nearNow = (iso) => Math.abs(Date.parse(iso) - Date.now()) <= 120000;
		await page.goto(BASE + '/batteriesoverview', { waitUntil: 'networkidle' });
		const batteryId = await page.evaluate(() => $('.track-charge-cycle-button').first().attr('data-battery-id'));
		await page.evaluate(() => $('.track-charge-cycle-button').first().click());
		await page.waitForTimeout(2500);
		const cycle = (await api(page, 'GET', `objects/battery_charge_cycles?query[]=battery_id=${batteryId}&order=id:desc&limit=1`)).body[0];
		check(`${zone}: charging now books the current instant`, nearNow(cycle.tracked_time), cycle.tracked_time);

		// The chore form shows the stored start in the device zone and saves it back unchanged.
		await page.goto(BASE + `/chore/${choreId}`, { waitUntil: 'networkidle' });
		const pickerValue = await page.evaluate(() => Victual.Components.DateTimePicker.GetValue());
		check(`${zone}: the chore form shows the start in the device zone`, pickerValue === wallClockIn('2026-10-05T03:30:00Z', zone), pickerValue);
		await page.evaluate(() => $('#save-chore-button').click());
		await page.waitForTimeout(2500);
		const chore = (await api(page, 'GET', `objects/chores/${choreId}`)).body;
		check(`${zone}: saving the form unchanged keeps the instant`, chore.start_date === '2026-10-05T03:30:00.000000Z', chore.start_date);

		// A calendar date is never converted.
		const stock = (await api(page, 'GET', 'stock')).body.find((row) => row.best_before_date);
		if (stock)
		{
			await page.goto(BASE + '/stockoverview', { waitUntil: 'networkidle' });
			const text = await page.evaluate((id) => $('#product-' + id + '-next-due-date').text().trim(), stock.product_id);
			check(`${zone}: a best before date is the stored date`, text === stock.best_before_date.substring(0, 10), `${text} vs ${stock.best_before_date}`);
		}

		await api(page, 'DELETE', `objects/chores/${choreId}`);
		check(`${zone}: no page errors`, errors.length === 0, errors.join(' | '));
	}

	await browser.close();
	console.log(failures === 0 ? '\nALL TIMESTAMP CHECKS PASSED' : `\n${failures} check(s) failed`);
	process.exit(failures === 0 ? 0 : 1);
})();
