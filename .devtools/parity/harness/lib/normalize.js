'use strict';

// What "the same response" means, spelled out.
//
// The rule this suite exists to check is ADR-0005's: **the engine may change; the JSON on
// the wire may not.** So normalisation here is deliberately thin. Everything it does is
// one of two things — erasing a value that cannot be equal because it names a moment or a
// row that was created at a different instant, or absorbing the one float difference
// ADR-0005 already accepted. It does *not* coerce types, and that is the point: a
// PostgreSQL `true` where SQLite sent `"1"`, or a number where a string was documented, is
// precisely the class of defect the fork can introduce without noticing, so it must
// survive normalisation and be reported.

// Fields whose value is a wall-clock moment or a per-instance identifier. Two instances
// answering the same question milliseconds apart legitimately differ here, so the name is
// masked and its *presence* still compared — a field that exists on one side and not the
// other is still a difference.
// **Temporal, and separable from the rest.** These name a moment. On the real clock two
// instances cannot agree on one, so they are masked — but under the year phase's faked,
// stepped clock they *can*, and then masking them throws away the evidence that a booking
// landed on the right simulated day. Kept as their own set so that mode can un-mask exactly
// these and nothing else.
const TEMPORAL_FIELDS = new Set([
	'row_created_timestamp',
	'last_used',
	'expires',
	'undone_timestamp',
	'used_timestamp',
	'last_login',
	'timestamp',
	'time_local',
	'time_local_sqlite3',
	'time_utc'
]);

// Credentials. Masked in every mode: a clock cannot make two instances mint the same key.
const SECRET_FIELDS = new Set([
	'api_key',
	'session_key'
]);

const VOLATILE_FIELDS = new Set([
	...TEMPORAL_FIELDS,
	...SECRET_FIELDS,

	// **The opaque booking handles, and masking them is not a concession.** `stock_id`,
	// `transaction_id` and `correlation_id` are `uniqid()` output — a hex rendering of the
	// current microsecond — so two instances cannot agree on one and no client is
	// documented to expect a particular value. Before they were masked they were 182 of
	// the first run's 231 differences, which is the shape of a report nobody reads: the
	// three findings that mattered were on page four. What is still compared is that the
	// field is present, that it is present on both, and that rows sharing a transaction on
	// one side share one on the other — because the *grouping* is what plan 13's
	// atomicity means, and grouping survives masking only if it is checked separately,
	// which `.devtools/pgsql/`'s rollback phase is what does.
	'stock_id',
	'transaction_id',
	'correlation_id'
]);

// The opaque booking handles, masked in every mode for the reason above: `uniqid()` renders
// the current microsecond, so a faked clock does not make them agree either. They are still
// *captured* as handles by the year phase — masking governs what is diffed, not what is
// bound.
const OPAQUE_FIELDS = new Set(['stock_id', 'transaction_id', 'correlation_id']);

// Fields describing the machine rather than the application. Two images built from
// different base layers legitimately ship different interpreter and library versions;
// reporting that on every run would say nothing about whether the fork changed behaviour.
// Kept separate from IDENTITY_FIELDS so that the reason is readable in the code rather
// than inferred from a list.
const ENVIRONMENT_FIELDS = new Set([
	'php_version',
	'sqlite_version',
	'os'
]);

// Fields whose value is the product's identity rather than its behaviour. The fork is
// renamed (plan 16), so these differ by construction and comparing them would report the
// rename on every run instead of once.
const IDENTITY_FIELDS = new Set([
	'grocy_version',
	'victual_version',
	'release_date',
	'version'
]);

const MASK = '<volatile>';
const IDENTITY = '<identity>';
const ENVIRONMENT = '<environment>';

// Six decimal places. ADR-0005's accepted float-accumulation exception is ~1e-15 —
// products_average_price.price being 4.124499999999999 on one engine and 4.1245 on the
// other — and six places is far enough above that to absorb it while staying far below
// anything a household would notice in a price. A difference in the second decimal place
// of a price is still a difference.
const FLOAT_PLACES = 6;

function roundFloat(n) {
	if (!Number.isFinite(n)) return n;
	if (Number.isInteger(n)) return n;
	return Number(n.toFixed(FLOAT_PLACES));
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const DATETIME_RE = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/;

// Milliseconds for a value that claims to be a moment, or null when it does not parse.
// An integer is an epoch — `GET /system/time` reports `timestamp` that way — and a string
// is one of the two ISO shapes the application writes.
function momentToMillis(value) {
	if (typeof value === 'number' && Number.isFinite(value)) return value * 1000;
	if (typeof value !== 'string') return null;
	if (DATE_RE.test(value)) return Date.parse(`${value}T00:00:00Z`);
	// **Anchored to the end of the string, and that is the whole point.** An unanchored
	// /Z|[+-]\d{2}/ matches the date's own hyphens — "2024-12-01 09:00:00" contains "-12" —
	// so the value was treated as already carrying a zone and parsed as *local* time. On a
	// host at UTC that is invisible; on one at America/New_York it is a five-hour error, and
	// it is what made the year phase's first replay decide the clock had never arrived.
	if (DATETIME_RE.test(value)) {
		const hasZone = /(Z|[+-]\d{2}:?\d{2})$/.test(value);
		return Date.parse(`${value.replace(' ', 'T')}${hasZone ? '' : 'Z'}`);
	}
	return null;
}

// A temporal field, under whichever clock the run had.
//
// **Its shape is checked before it is masked.** Masking first meant any string at all
// compared equal to any other, so a field that had started returning "0000-00-00" or a
// stray note would have read as parity forever. A value that does not parse as a moment is
// reported as malformed rather than hidden, and the malformed text is carried so the two
// sides can still differ from each other.
//
// Under a faked, stepped clock the field is not masked at all: both instances are at the
// same simulated instant, so what is compared is the day the value fell on, relative to the
// run's anchor. That is the evidence a booking landed on the right simulated day, and
// masking is what used to throw it away. Seconds are not compared — the faked clock still
// runs at real rate between steps, so second-equality would be a flake; within-day ordering
// is asserted separately, by id sequence.
function normalizeTemporal(value, opts) {
	if (value === '') return value;

	const ms = momentToMillis(value);
	if (ms === null || Number.isNaN(ms)) {
		return `<malformed-timestamp:${String(value).slice(0, 40)}>`;
	}

	if (opts && opts.clock === 'simulated' && opts.anchorMs !== undefined) {
		const days = Math.floor((ms - opts.anchorMs) / 86400000);
		return `<day${days >= 0 ? '+' : ''}${days}>`;
	}

	return MASK;
}

function normalizeValue(key, value, opts) {
	// **A null is not a moment, and neither is an absent value.** Masking them made "never
	// undone" compare equal to "undone at some instant", which is exactly what those two
	// records differ by — so the check for them comes first now, and a null stays a null.
	if (value === null || value === undefined) return value;

	if (SECRET_FIELDS.has(key) || OPAQUE_FIELDS.has(key)) return MASK;
	if (TEMPORAL_FIELDS.has(key)) return normalizeTemporal(value, opts);
	if (IDENTITY_FIELDS.has(key)) return IDENTITY;
	if (ENVIRONMENT_FIELDS.has(key)) return ENVIRONMENT;

	if (typeof value === 'number') return roundFloat(value);

	// A numeric *string* is left as a string on purpose. "4.1245" and 4.1245 are different
	// answers to the same question and ADR-0005 says only one of them is conforming; the
	// suite's job is to say which endpoint disagrees, not to make them agree. The rounding
	// below applies only so that a string carrying float noise does not report as a
	// difference when the same string on the other side carries different noise.
	if (typeof value === 'string' && /^-?\d+\.\d{7,}$/.test(value)) {
		return roundFloat(Number(value)).toString();
	}

	if (Array.isArray(value)) return value.map((v) => normalizeValue(key, v, opts));

	if (typeof value === 'object') return normalizeObject(value, opts);

	return value;
}

function normalizeObject(obj, opts) {
	if (obj === null || typeof obj !== 'object') return obj;
	if (Array.isArray(obj)) return obj.map((v) => normalizeObject(v, opts));

	const out = {};
	// Key order is not part of the wire contract — PHP's json_encode follows insertion
	// order and a view's column order is not something either project promises — so keys
	// are sorted before comparison. A *missing* or *extra* key is still a difference.
	for (const key of Object.keys(obj).sort()) {
		out[key] = normalizeValue(key, obj[key], opts);
	}
	return out;
}

// Lists come back in whatever order the engine felt like unless the endpoint documents
// one. Sorting by id where every element has one removes that as a source of noise
// without hiding a genuinely different set: the elements are still compared one by one.
function normalizeBody(body, opts) {
	const normalized = normalizeObject(body, opts);
	if (Array.isArray(normalized) && normalized.every((e) => e && typeof e === 'object' && 'id' in e)) {
		return [...normalized].sort((a, b) => Number(a.id) - Number(b.id));
	}
	return normalized;
}

// Text that is not JSON — the iCal feed, an HTML error page — still has to be compared,
// because its content is exactly what a subscribing calendar or a browsing user sees.
// What has to come out of it first is everything that names *which instance* answered.
function normalizeText(text, baseUrl) {
	let out = String(text);
	if (baseUrl) out = out.split(baseUrl).join('<base-url>');
	return out
		.replace(/victual/gi, '<product>')
		.replace(/grocy/gi, '<product>')
		// iCal stamps every event with the moment it was generated, and every UID with a
		// per-instance token.
		.replace(/\d{8}T\d{6}Z?/g, '<ical-timestamp>')
		.replace(/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/g, '<timestamp>')
		.replace(/UID:.*/g, 'UID:<uid>');
}

// Any string carrying the instance's own base URL differs by construction — the two run on
// different ports — and any string carrying a generated secret differs by design. The
// calendar sharing link is both at once.
function normalizeStrings(value, baseUrl) {
	if (typeof value === 'string') {
		let out = baseUrl ? value.split(baseUrl).join('<base-url>') : value;
		// The iCal secret is a special-purpose API key: 50 characters of base62. Masked by
		// shape rather than by field name because it arrives inside a URL.
		out = out.replace(/secret=[A-Za-z0-9]{20,}/g, 'secret=<secret>');
		return out;
	}
	if (Array.isArray(value)) return value.map((v) => normalizeStrings(v, baseUrl));
	if (value && typeof value === 'object') {
		const out = {};
		for (const key of Object.keys(value)) out[key] = normalizeStrings(value[key], baseUrl);
		return out;
	}
	return value;
}

function normalizeRecord(record, baseUrl, opts) {
	// A body that did not parse as JSON is compared as normalised text rather than being
	// declared incomparable. Reporting "at least one side did not answer JSON" for the iCal
	// feed — which is text/calendar on both sides and correct on both — was noise that hid
	// whether the feed's *contents* agreed.
	if (record.parseError && record.body && typeof record.body.__unparsed === 'string') {
		return {
			label: record.label,
			method: record.method,
			path: record.path,
			status: record.status,
			body: { __text: normalizeText(record.body.__unparsed, baseUrl) },
			parseError: null
		};
	}

	return {
		label: record.label,
		method: record.method,
		path: record.path,
		status: record.status,
		body: normalizeStrings(normalizeBody(record.body, opts), baseUrl),
		parseError: record.parseError
	};
}

module.exports = {
	normalizeBody,
	normalizeRecord,
	normalizeText,
	VOLATILE_FIELDS,
	TEMPORAL_FIELDS,
	SECRET_FIELDS,
	OPAQUE_FIELDS,
	momentToMillis,
	IDENTITY_FIELDS,
	ENVIRONMENT_FIELDS,
	FLOAT_PLACES,
	MASK,
	IDENTITY
};
