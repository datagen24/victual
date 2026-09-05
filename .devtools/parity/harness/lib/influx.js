'use strict';

// Reading the InfluxDB side of plan 18.
//
// Lifted out of run-side-effects.js, and then given the two things a simulated year needs
// that one booking never did.
//
// **Absolute bounds, not a relative window.** The extracted query asked for `range(start:
// -30d)`, which is a window on the *real* clock. A year phase runs under a faked one and
// writes points at simulated instants that can be a year in the past, so a relative window
// answers "no points" for a bucket full of them. Every query here takes an explicit start
// and stop.
//
// **Logical points, not Flux rows.** Influx returns one row per field, so `price_paid` —
// which carries `price` and `amount` — yields two rows for one event. Counting rows
// double-counts, and a check written against row counts silently changes meaning the day a
// field is added. `queryPoints` groups back to (measurement, tag set, timestamp), which is
// what a point *is*, and exposes its fields as a map.
//
// The row cap is gone with them. `limit(n: 50)` is right for a probe that wants a sample
// and wrong for a count: a year that wrote 400 priced purchases would report 50 and pass a
// "more than zero" assertion while hiding that 350 were lost.

// The tag sets, measured 2026-09-05 against a single priced purchase, because the year
// phase has to bind every point back to the operation that caused it and the two
// measurements do not offer the same handle:
//
//   price_paid    tags: booking_id, event_id, product_id, transaction_id
//                 fields: amount, price
//   stock_value   tags:             event_id, product_id, transaction_id
//                 fields: amount, value
//
// So `booking_id` binds price_paid to a stock_log row directly, and stock_value — emitted
// for consumption, transfers and undos, which produce no price_paid at all — has to be
// bound through `transaction_id` instead.
//
// **One event_id spans both measurements.** That single purchase produced one event_id
// across two points, so `event_id` identifies an *event*, not a point: uniqueness is a
// per-event assertion, and counting distinct event_ids is not the same as counting points.
// Masking it would hide duplicate delivery, which is the defect the outbox exists to make
// impossible.

const DEFAULT_TIMEOUT_MS = 30000;

// Influx annotated CSV, parsed properly rather than by splitting on commas. Values are
// quoted when they contain a comma or a quote, and a note in a `_value` field does contain
// them — the naive split silently shifted every column after it.
function parseCsvLine(line) {
	const out = [];
	let field = '';
	let quoted = false;
	for (let i = 0; i < line.length; i++) {
		const c = line[i];
		if (quoted) {
			if (c === '"') {
				if (line[i + 1] === '"') { field += '"'; i++; }
				else quoted = false;
			} else field += c;
		} else if (c === '"') {
			quoted = true;
		} else if (c === ',') {
			out.push(field);
			field = '';
		} else field += c;
	}
	out.push(field);
	return out;
}

// Annotated CSV carries `#datatype`/`#group`/`#default` lines before each table's header,
// and a blank line between tables. Annotations are skipped; every header resets the column
// names, which is what makes multi-table responses parse at all.
function parseAnnotatedCsv(text) {
	const rows = [];
	let header = null;
	for (const raw of String(text).split('\n')) {
		const line = raw.replace(/\r$/, '');
		if (line.trim().length === 0) { header = null; continue; }
		if (line.startsWith('#')) continue;
		const cells = parseCsvLine(line);
		if (header === null) { header = cells; continue; }
		const row = {};
		for (let i = 0; i < header.length; i++) {
			if (header[i] !== '') row[header[i]] = cells[i];
		}
		rows.push(row);
	}
	return rows;
}

async function queryFlux({ url, token, org, flux, timeoutMs = DEFAULT_TIMEOUT_MS }) {
	const endpoint = `${String(url).replace(/\/+$/, '')}/api/v2/query?org=${encodeURIComponent(org)}`;
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	let response;
	try {
		response = await fetch(endpoint, {
			method: 'POST',
			headers: {
				Authorization: `Token ${token}`,
				'Content-Type': 'application/vnd.flux',
				Accept: 'application/csv'
			},
			body: flux,
			signal: controller.signal
		});
	} finally {
		clearTimeout(timer);
	}
	return { status: response.status, csv: await response.text() };
}

// Everything that is not a field, a value or Flux bookkeeping is a tag, and the tag set is
// half of a point's identity. Listing the reserved names rather than an allowlist of tags
// means a tag the publisher adds later is still part of the identity here.
const RESERVED = new Set(['result', 'table', '_start', '_stop', '_time', '_value', '_field', '_measurement']);

function tagsOf(row) {
	const tags = {};
	for (const key of Object.keys(row).sort()) {
		if (!RESERVED.has(key) && row[key] !== '') tags[key] = row[key];
	}
	return tags;
}

function identityOf(row) {
	return JSON.stringify([row._measurement, row._time, tagsOf(row)]);
}

// One measurement over an explicit interval, grouped into logical points.
//
// `start`/`stop` are RFC3339 instants or Flux durations. A year phase passes instants that
// straddle its simulated year; a probe checking "did this booking publish" can still pass a
// duration. Neither gets a default, because a default here is how a query silently stops
// covering the data it was written for.
async function queryPoints({ url, token, org, bucket, measurement, start, stop, extraFilter = '' }) {
	if (!start || !stop) throw new Error('queryPoints needs an explicit start and stop — a relative window is meaningless under a faked clock');

	const flux = `from(bucket: "${bucket}")\n` +
		`  |> range(start: ${start}, stop: ${stop})\n` +
		`  |> filter(fn: (r) => r._measurement == "${measurement}")\n` +
		(extraFilter ? `  |> filter(fn: (r) => ${extraFilter})\n` : '');

	const { status, csv } = await queryFlux({ url, token, org, flux });
	const rows = status === 200 ? parseAnnotatedCsv(csv) : [];

	const byIdentity = new Map();
	for (const row of rows) {
		if (!row._measurement) continue;
		const key = identityOf(row);
		if (!byIdentity.has(key)) {
			byIdentity.set(key, {
				measurement: row._measurement,
				time: row._time,
				tags: tagsOf(row),
				fields: {}
			});
		}
		byIdentity.get(key).fields[row._field] = row._value;
	}

	const points = [...byIdentity.values()].sort((a, b) =>
		a.time === b.time ? identityOf(a).localeCompare(identityOf(b)) : String(a.time).localeCompare(String(b.time)));

	return { status, flux, rowCount: rows.length, pointCount: points.length, points };
}

// The shape run-side-effects.js has always consumed, kept so that extracting this file is a
// no-op for that phase. It is the relative-window query, and it stays relative because that
// phase runs on the real clock and asserts on a booking it made seconds earlier.
async function queryInflux(args, measurement) {
	// -30d rather than -1h: the outbox writes with the event's own timestamp, and a suite
	// run that took a while should not lose its own points to a narrow window.
	const flux = `from(bucket: "${args.influxBucket}")\n` +
		'  |> range(start: -30d)\n' +
		`  |> filter(fn: (r) => r._measurement == "${measurement}")\n` +
		'  |> limit(n: 50)';

	const { status, csv } = await queryFlux({
		url: args.influx, token: args.influxToken, org: args.influxOrg, flux
	});

	const rows = csv.split('\n').filter((l) => l.trim().length > 0 && !l.startsWith('#'));
	return { status, rowCount: Math.max(0, rows.length - 1), sample: rows.slice(0, 4) };
}

module.exports = { queryFlux, queryPoints, queryInflux, parseAnnotatedCsv, parseCsvLine, tagsOf };
