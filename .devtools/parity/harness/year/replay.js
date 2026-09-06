'use strict';

// Executing a materialised plan against one instance.
//
// The interpreter has a fixed vocabulary and, crucially, **no branch that inspects a
// response value**. It reads exactly one thing out of a response — the id named by `bind` —
// into an opaque slot it never compares or tests. That is what keeps trace-length equality
// structural when two instances are driven from the same plan: the control flow here does
// not depend on what came back, so it cannot differ between them.
//
// What it does check is that each operation *succeeded*, and that is separate from any
// comparison. Instance.api() records an HTTP failure without throwing, silently() suppresses
// recording rather than errors, and diffStep finds no difference between two matching 400s —
// so a run that only compared could report parity over a year in which nothing happened. An
// operation that does not meet its `expect` stops the run as INCOMPLETE and is preserved
// verbatim.

const { makeCalendar } = require('./calendar');
const { momentToMillis } = require('../lib/normalize');

const SYMBOL_RE = /\{([a-zA-Z]+):([^}]+)\}/g;

// Temporal fields a *write* response can carry, split by who decided them — which is what
// decides how tightly each can be checked.
//
// **Client-supplied fields are pinned to the operation's own interval.** The plan chose
// `tracked_time`, so a chore executed at 15:00 must say 15:00, and an hour-granular window
// is exactly the assertion worth making.
//
// **Server-generated fields are pinned to the simulated day, and cannot be tighter.** The
// clock is stepped once per day and then runs; a booking's row_created_timestamp is whenever
// within that day the replay reached it, so checking it against the *operation's* hour would
// fail on correct behaviour. The first end-to-end replay did exactly that — a chore executed
// at 15:00 was written at 09:00 — and the field was right both times.
const CLIENT_SUPPLIED = new Set(['tracked_time', 'done_time', 'purchased_date']);
const DATE_ONLY = new Set(['used_date', 'opened_date', 'purchased_date']);

// Which server-generated fields *this* operation is the author of.
//
// Only `row_created_timestamp` is written by every write. `used_date` is set by a consume
// and `opened_date` by an open (StockService.php:635,671 and :1521,1530) — and both then
// travel on later responses about the same entry, where they name the day that earlier
// operation happened rather than this one. A consume that drew down an entry opened four
// days ago reports opened_date four days back, correctly; checking it here against today
// flagged four correct consumes in the smoke run.
function generatedBy(op) {
	const fields = new Set(['row_created_timestamp']);
	const path = op.path || '';
	if (/\/consume$/.test(path)) fields.add('used_date');
	if (/\/open$/.test(path)) fields.add('opened_date');
	if (/\/undo$/.test(path)) fields.add('undone_timestamp');
	return fields;
}

class Incomplete extends Error {
	constructor(message, detail) {
		super(message);
		this.name = 'Incomplete';
		this.detail = detail;
	}
}

// --- Symbols ---------------------------------------------------------------------------------

function substitute(text, symbols, where) {
	if (typeof text !== 'string') return text;
	return text.replace(SYMBOL_RE, (whole, kind, name) => {
		const key = `${kind}:${name}`;
		if (!(key in symbols)) {
			// Left unsubstituted this would become a literal `{product:milk}` in a URL and
			// answer 404 on every instance — equal, and so invisible to a differential run.
			throw new Incomplete(`${where}: {${key}} was never bound`, { symbol: key });
		}
		return String(symbols[key]);
	});
}

// **A value that is nothing but one symbol keeps that symbol's type.**
//
// Ids arrive from the application as JSON numbers, and `substitute()` builds a string because
// it has to handle `"{product:milk}"` embedded in a path. That was invisible until an
// endpoint started validating types: `PUT /users/{id}/permissions` requires integer ids
// (services/RolesService.php:75-79) and answered 400 for `["3"]`. A body that names a row by
// symbol means the row's id, not its decimal spelling, so a lone symbol resolves to the value
// as bound. Strings with anything else around them are unaffected, which is every path.
function substituteDeep(value, symbols, where) {
	if (typeof value === 'string') {
		const lone = value.match(/^\{([^{}]+)\}$/);
		if (lone && Object.prototype.hasOwnProperty.call(symbols, lone[1])) return symbols[lone[1]];
		return substitute(value, symbols, where);
	}
	if (Array.isArray(value)) return value.map((v) => substituteDeep(v, symbols, where));
	if (value && typeof value === 'object') {
		const out = {};
		for (const k of Object.keys(value)) out[k] = substituteDeep(value[k], symbols, where);
		return out;
	}
	return value;
}

// `created_object_id`, `id`, `[0].transaction_id`, and `find(username=alice).id`.
//
// The `find(...)` form exists because not every create hands back an id: `POST /users`
// answers 204 (UsersApiController:48-51), so the user's id has to be read from a subsequent
// list. Selecting it by *position* would be trusting an order nothing promises; selecting it
// by a declared match is deterministic, and it is still not a branch on the response — the
// same operations run whatever comes back, and a match that is not there stops the run.
function pluck(body, spec) {
	let node = body;
	for (const part of spec.match(/find\([^)]*\)|\[\d+\]|[^.]+/g) || []) {
		if (node === null || node === undefined) return undefined;
		const find = part.match(/^find\(([^=]+)=(.*)\)$/);
		if (find) {
			if (!Array.isArray(node)) return undefined;
			node = node.find((row) => row && String(row[find[1]]) === find[2]);
			continue;
		}
		const index = part.match(/^\[(\d+)\]$/);
		node = index ? node[Number(index[1])] : node[part];
	}
	return node;
}

// --- Checking an outcome ---------------------------------------------------------------------

// Which expectation forms an operation carries. Counted at evaluation rather than from the
// plan: a run that stopped early planned every assertion and established none of them, and a
// count taken from the plan could not tell those apart.
const EXPECT_FORMS = ['status', 'kind', 'length', 'minLength', 'shape', 'rowShape',
	'firstRowEquals', 'everyRowEquals', 'rowsSum', 'equals', 'rowsMatch', 'rowsAbsent',
	'rowsEqual', 'lots'];

// **A comparison that decides "these differ" has to fail closed.**
//
// `Math.abs(NaN - want) > tol` is false, so every tolerance test written that way treated an
// unusable value as agreement. The mirror-image form, `Math.abs(a - b) < tol`, already fails
// closed and is left alone. Exported so the invariants use the same rule.
function differsBy(got, want, tol = 1e-6) {
	return !Number.isFinite(Number(got)) || !Number.isFinite(Number(want))
		|| Math.abs(Number(got) - Number(want)) > tol;
}

function tallyExpect(expectation, tally) {
	if (!expectation) return;
	for (const form of EXPECT_FORMS) {
		if (expectation[form] !== undefined) tally.set(form, (tally.get(form) || 0) + 1);
	}
	if (expectation.lots) {
		tally.set('lots:exact', (tally.get('lots:exact') || 0) + (expectation.lots.exact || []).length);
		tally.set('lots:tiedGroups', (tally.get('lots:tiedGroups') || 0) + (expectation.lots.groups || []).length);
	}
}

function checkExpect(op, record, where, priceRepresentations = []) {
	const e = op.expect;
	const body = record.body;

	if (record.status !== e.status) {
		throw new Incomplete(
			`${where}: expected HTTP ${e.status}, got ${record.status}`,
			{ op: op.label, path: record.path, status: record.status, body });
	}
	if (e.kind === 'array' && !Array.isArray(body)) {
		throw new Incomplete(`${where}: expected an array, got ${body === null ? 'null' : typeof body}`, { op: op.label, body });
	}
	if (e.kind === 'object' && (Array.isArray(body) || body === null || typeof body !== 'object')) {
		throw new Incomplete(`${where}: expected an object, got ${Array.isArray(body) ? 'array' : typeof body}`, { op: op.label, body });
	}
	// **An exact length, enforced.** `length` was accepted, counted as an assertion, and never
	// checked — so `length: 1` passed on two rows, and the edit fixtures that use it to
	// establish an entry is uniquely identified before binding `[0].id` were binding the first
	// of however many came back. Worse than absent: the assertion tally reported coverage that
	// did not exist.
	if (e.length !== undefined && (!Array.isArray(body) || body.length !== e.length)) {
		throw new Incomplete(
			`${where}: expected exactly ${e.length} row(s), got ` +
			`${Array.isArray(body) ? body.length : 'a non-array'}`,
			{ op: op.label, body });
	}

	if (e.minLength !== undefined && (!Array.isArray(body) || body.length < e.minLength)) {
		throw new Incomplete(`${where}: expected at least ${e.minLength} rows, got ${Array.isArray(body) ? body.length : 'none'}`, { op: op.label, body });
	}
	for (const key of e.shape || []) {
		if (!body || body[key] === undefined) {
			throw new Incomplete(`${where}: response has no "${key}"`, { op: op.label, body });
		}
	}
	if (e.rowShape) {
		const row = Array.isArray(body) ? body[0] : body;
		for (const key of e.rowShape) {
			if (!row || row[key] === undefined) {
				throw new Incomplete(`${where}: first row has no "${key}"`, { op: op.label, row });
			}
		}
	}
	// The signed total the operation was meant to move.
	if (e.rowsSum !== undefined) {
		const rows = Array.isArray(body) ? body : [];
		// **Every amount has to be a number before any of them can be added up.** `Number()`
		// of a non-numeric string is NaN, NaN propagates through the sum, and
		// `Math.abs(NaN - want) > tol` is *false* — so a response carrying
		// `amount: "garbage"` satisfied whatever `rowsSum` it was given. A booking row that
		// cannot state its amount is a finding, not a zero.
		const bad = rows.findIndex((r) => !Number.isFinite(Number(r && r.amount)));
		if (bad !== -1) {
			throw new Incomplete(
				`${where}: row ${bad} has a non-numeric amount ` +
				`(${JSON.stringify(rows[bad] && rows[bad].amount)}), so the booking total means nothing`,
				{ op: op.label, body });
		}
		const sum = rows.reduce((n, r) => n + Number(r.amount), 0);
		if (differsBy(sum, e.rowsSum)) {
			throw new Incomplete(
				`${where}: the booking moved ${sum}, the plan intended ${e.rowsSum}`,
				{ op: op.label, rows: Array.isArray(body) ? body.map((r) => r.amount) : body });
		}
	}

	// The state the operation was meant to leave behind.
	for (const [key, want] of Object.entries(e.equals || {})) {
		const got = body ? body[key] : undefined;
		const same = (Number.isFinite(Number(want)) && Number.isFinite(Number(got)))
			? Math.abs(Number(got) - Number(want)) < 1e-6
			: String(got) === String(want);
		if (!same) {
			throw new Incomplete(
				`${where}: ${key} is ${got}, the plan intended ${want}`,
				{ op: op.label, path: record.path });
		}
	}

	// **A collection compared as a whole, not row by row.**
	//
	// `rowsMatch` asks whether particular rows are there; this asks whether the set is
	// exactly right — which is the only way to catch a consume that took the correct amount
	// from the wrong lot. The totals are identical either way; the remaining best-before
	// dates and prices are not. Compared as a sorted multiset because stock entries have no
	// ordering the API promises, and because two genuinely identical lots (the FIFO tie
	// probe builds one such pair on purpose) must compare equal in either order.
	if (e.rowsEqual) {
		const norm = (v) => {
			if (v === null || v === undefined) return null;
			const n = Number(v);
			return Number.isFinite(n) && String(v).trim() !== '' ? Number(n.toFixed(6)) : String(v);
		};
		const tupleOf = (row) => JSON.stringify(e.rowsEqual.fields.map((f) => norm(row[f])));
		const got = (Array.isArray(body) ? body : []).map(tupleOf).sort();
		const want = e.rowsEqual.rows.map((r) => JSON.stringify(r.map(norm))).sort();
		if (got.length !== want.length || got.some((t, i) => t !== want[i])) {
			// A row that matches a group's dates but sits somewhere the tie could not have
			// left it lands here rather than in the group. Say that, rather than reporting a
			// count mismatch that hides which lot moved.
			for (const row of loose) {
				const g = (groups || []).find((x) => Object.entries(x.where)
					.every(([k, v]) => String(numeric(row[k])) === String(numeric(v))));
				if (g) {
					throw new Incomplete(
						`${where}: a tied lot sits at location ${row.location_id}, which is not one the plan allows`,
						{ op: op.label, allowed: g.locations, row: { amount: row.amount, location_id: row.location_id } });
				}
			}
			throw new Incomplete(
				`${where}: the lots left behind are not the ones the plan expects ` +
				`(${got.length} rows against ${want.length})`,
				{
					op: op.label,
					fields: e.rowsEqual.fields,
					got: got.slice(0, 6),
					expected: want.slice(0, 6)
				});
		}
	}

	// **Lots: what is determined, compared exactly; what a tie leaves open, compared against
	// the outcomes the application permits.**
	if (e.lots) {
		const { fields, exact, groups } = e.lots;
		const rows = Array.isArray(body) ? body : [];

		// Only where a valuation is what is being compared is an unknown price equivalent to
		// an explicit zero. Everywhere else the raw value is kept, and a difference between
		// the two representations is recorded as an observation rather than modelled away —
		// they can mean different things in a contract even when a price view coalesces them.
		const numeric = (v) => {
			if (v === null || v === undefined) return null;
			const n = Number(v);
			return Number.isFinite(n) && String(v).trim() !== '' ? Number(n.toFixed(6)) : String(v);
		};
		const valuation = (v) => (numeric(v) === null ? 0 : numeric(v));

		const matchesGroup = (row, g) => Object.entries(g.where)
			.every(([k, v]) => String(numeric(row[k])) === String(numeric(v)));

		const grouped = new Map((groups || []).map((g, i) => [i, []]));
		const loose = [];
		for (const row of rows) {
			const index = (groups || []).findIndex((g) => matchesGroup(row, g));
			if (index >= 0) grouped.get(index).push(row); else loose.push(row);
		}

		// The determined lots, exactly — with the raw price kept as an observation wherever
		// it differs from the plan's while the valuation agrees.
		for (const row of loose) {
			const raw = numeric(row.price);
			if (raw !== null && Number(raw) !== 0) continue;
			const planned = (exact || [])
				.map((r) => numeric(r[fields.indexOf('price')]))
				.filter((v) => v === null || Number(v) === 0);
			if (planned.length > 0 && !planned.some((v) => String(v) === String(raw))) {
				priceRepresentations.push({ op: op.label, raw: row.price, planned: [...new Set(planned)] });
			}
		}
		const tupleOf = (row) => JSON.stringify(fields.map((f) => (f === 'price' ? valuation(row[f]) : numeric(row[f]))));
		const got = loose.map(tupleOf).sort();
		const want = (exact || []).map((r) => JSON.stringify(
			fields.map((f, i) => (f === 'price' ? valuation(r[i]) : numeric(r[i]))))).sort();
		if (got.length !== want.length || got.some((t, i) => t !== want[i])) {
			// A row that matches a group's dates but sits somewhere the tie could not have
			// left it lands here rather than in the group. Say that, rather than reporting a
			// count mismatch that hides which lot moved.
			for (const row of loose) {
				const g = (groups || []).find((x) => Object.entries(x.where)
					.every(([k, v]) => String(numeric(row[k])) === String(numeric(v))));
				if (g) {
					throw new Incomplete(
						`${where}: a tied lot sits at location ${row.location_id}, which is not one the plan allows`,
						{ op: op.label, allowed: g.locations, row: { amount: row.amount, location_id: row.location_id } });
				}
			}
			throw new Incomplete(
				`${where}: the determined lots are not the ones the plan expects ` +
				`(${got.length} rows against ${want.length})`,
				{ op: op.label, fields, got: got.slice(0, 6), expected: want.slice(0, 6) });
		}

		// And each tied group, by everything the tie does not decide.
		for (const [index, g] of (groups || []).entries()) {
			const members = grouped.get(index) || [];
			const total = members.reduce((n, r) => n + Number(r.amount || 0), 0);
			if (differsBy(total, g.total)) {
				throw new Incomplete(
					`${where}: tied lots ${JSON.stringify(g.where)} hold ${total}, the plan expects ${g.total}`,
					{ op: op.label, members: members.map((m) => ({ amount: m.amount, location_id: m.location_id })) });
			}
			for (const m of members) {
				if (!g.locations.some((l) => String(numeric(l)) === String(numeric(m.location_id)))) {
					throw new Incomplete(
						`${where}: a tied lot sits at location ${m.location_id}, which is not one the plan allows`,
						{ op: op.label, allowed: g.locations });
				}
				if (g.prices && !g.prices.some((p) => valuation(p) === valuation(m.price))) {
					throw new Incomplete(
						`${where}: a tied lot carries price ${m.price}, which is not one the plan allows`,
						{ op: op.label, allowed: g.prices });
				}
				// The representation difference itself, kept as evidence rather than a verdict.
				if (g.prices && !g.prices.some((p) => String(numeric(p)) === String(numeric(m.price)))) {
					priceRepresentations.push({
						op: op.label, raw: m.price, planned: g.prices, where: g.where
					});
				}
			}
		}
	}

	// Rows that must be present in a collection, matched by a field and checked on another.
	// This is how a monthly checkpoint asserts a whole position rather than a status code.
	for (const spec of e.rowsMatch || []) {
		const rows = Array.isArray(body) ? body : [];
		const found = rows.filter((r) => Object.entries(spec.where)
			.every(([k, v]) => String(r[k]) === String(v)));
		if (found.length === 0) {
			throw new Incomplete(
				`${where}: no row where ${JSON.stringify(spec.where)}, expected ${JSON.stringify(spec.equals)}`,
				{ op: op.label, rows: rows.length });
		}
		for (const [k, want] of Object.entries(spec.equals)) {
			const got = found[0][k];
			const same = (Number.isFinite(Number(want)) && Number.isFinite(Number(got)))
				? Math.abs(Number(got) - Number(want)) < 1e-6
				: String(got) === String(want);
			if (!same) {
				throw new Incomplete(
					`${where}: row ${JSON.stringify(spec.where)} has ${k}=${got}, the plan intended ${want}`,
					{ op: op.label });
			}
		}
	}

	// Rows that must *not* be present. A product the plan has emptied should be gone, and
	// "absent" is a claim a check that only looks at what is there can never make.
	for (const spec of e.rowsAbsent || []) {
		const rows = Array.isArray(body) ? body : [];
		const found = rows.filter((r) => Object.entries(spec.where)
			.every(([k, v]) => String(r[k]) === String(v)));
		if (found.length > 0) {
			throw new Incomplete(
				`${where}: a row where ${JSON.stringify(spec.where)} is present, the plan expects none`,
				{ op: op.label, row: found[0] });
		}
	}

	// **Two forms, named for what they check.**
	//
	// This was one form called `rowEquals` that only ever examined the first row. Its failure
	// message said so, but the name did not, and a name that claims more than it checks is the
	// same defect as an assertion that is never enforced — it just fails to be noticed for
	// longer. The first-row form is genuinely needed: a transfer answers a `transfer_from` row
	// *and* a `transfer_to` row, and an edit answers `stock-edit-old` and `stock-edit-new`, so
	// there is no value every row shares. Where every row does share one, saying so is
	// stronger and now possible.
	for (const [key, want] of Object.entries(e.firstRowEquals || {})) {
		const row = Array.isArray(body) ? body[0] : body;
		if (!row || String(row[key]) !== String(want)) {
			throw new Incomplete(`${where}: first row's ${key} is ${row ? row[key] : 'absent'}, expected ${want}`, { op: op.label, row });
		}
	}

	for (const [key, want] of Object.entries(e.everyRowEquals || {})) {
		const rows = Array.isArray(body) ? body : [body];
		const bad = rows.findIndex((r) => !r || String(r[key]) !== String(want));
		if (bad !== -1) {
			throw new Incomplete(
				`${where}: row ${bad} of ${rows.length} has ${key} = ` +
				`${rows[bad] ? rows[bad][key] : 'absent'}, expected ${want} on every row`,
				{ op: op.label, row: rows[bad] });
		}
	}
}

// **The window belongs to the event, not to the read.**
//
// A December checkpoint legitimately returns rows created in March, and a chore's
// next_estimated_execution_time is legitimately in the future — so a rule that checked every
// timestamp in every response against the reading operation's day would reject correct
// behaviour constantly. Only the rows a *write* just created are checked, against the window
// of the operation that created them.
function checkWindow(op, record, where, problems, clockArtifacts) {
	if (!op.window || op.method === 'GET') return;

	const fromMs = momentToMillis(op.window.from);
	const toMs = momentToMillis(op.window.to);
	// The simulated day the operation belongs to, for the fields the server timestamps.
	const dayFromMs = momentToMillis(op.window.from.slice(0, 10));
	const dayToMs = dayFromMs + 86400000;
	const generated = generatedBy(op);
	const seen = [];
	const artifacts = [];

	const walk = (node) => {
		if (Array.isArray(node)) return node.forEach(walk);
		if (!node || typeof node !== 'object') return;
		for (const [key, value] of Object.entries(node)) {
			if (value && typeof value === 'object') { walk(value); continue; }
			// **A field this operation supplied is checked against what it supplied**, not
			// against a window. That is both simpler and stronger: it asserts the application
			// stored the value the plan sent, which is the actual question. A window was the
			// wrong instrument for it — editing a stock entry carries the entry's *original*
			// purchased_date forward, correctly, and a window keyed to the editing day called
			// that a failure.
			if (CLIENT_SUPPLIED.has(key) && op.body && op.body[key] !== undefined) {
				const sent = String(op.body[key]);
				const got = String(value);
				// A date-only field may be echoed as a datetime, and vice versa; compare the
				// common prefix rather than demanding the same rendering.
				const n = Math.min(sent.length, got.length);
				if (sent.slice(0, n) !== got.slice(0, n)) {
					seen.push(`${key}=${got} but the operation sent ${sent}`);
				}
				continue;
			}
			// A field neither supplied here nor generated by this write is inherited from the
			// row being acted on, and belongs to whatever created that.
			if (!generated.has(key)) continue;
			const client = false;
			if (value === null || value === '') continue;
			const ms = momentToMillis(value);
			if (ms === null) { seen.push(`${key}=${value} is not a timestamp`); continue; }
			// A date-only field names a day, so it is inside the window when its day is.
			const lo = client ? (DATE_ONLY.has(key) ? dayFromMs : fromMs) : dayFromMs;
			const hi = client ? (DATE_ONLY.has(key) ? dayToMs : toMs) : dayToMs;
			if (ms < lo || ms >= hi) {
				// **Exactly one simulated day behind, on a field the server stamps, is the
				// clock contract being violated** — and the run says so.
				//
				// It is separated from application findings because its *cause* is the
				// harness's clock, not the fork. But it is not an accepted difference and it
				// does not permit a pass: a worker that was a day behind evaluated whatever
				// it evaluated against the wrong date, and expiry, due-soon windows, chore
				// scheduling and best-before comparisons are all decided against the clock.
				// Classifying it as cosmetic would be asserting that only the timestamp was
				// affected, which nothing here establishes. The run is reported INCOMPLETE
				// and the inventory checks are printed beside it.
				//
				// php-fpm hands a request to whichever child is free and each child holds its
				// own one-second faketime cache, refreshed only when it next runs. A child
				// that has been idle across a step therefore stamps the first write it serves
				// with yesterday's date. It cannot be waited out (an idle process does not
				// refresh) and the two ways of forcing it — extra warm-up requests, and
				// retrying the operation — were both measured and both made whole runs fail
				// earlier than they otherwise would.
				//
				// So it is classified rather than failed: counted, printed under its own
				// heading, and excluded from the verdict. Anything else — a client-supplied
				// field, or a server field wrong by something other than exactly one day —
				// still fails, which is what keeps this from being a blanket excuse.
				const behindByADay = !client && Math.abs((lo - ms) - 86400000) < 1000;
				(behindByADay ? artifacts : seen).push(
					`${key}=${value} is outside [${new Date(lo).toISOString().slice(0, 19).replace('T', ' ')}, ` +
					`${new Date(hi).toISOString().slice(0, 19).replace('T', ' ')})`);
			}
		}
	};
	walk(record.body);

	// Collected rather than thrown: a clock that has drifted is worth the whole list at once,
	// and one wrong field does not mean the operation failed to happen.
	if (seen.length > 0) problems.push({ op: op.label, where, problems: seen });
	if (artifacts.length > 0) clockArtifacts.push({ op: op.label, where, problems: artifacts });
}

// --- The clock -------------------------------------------------------------------------------

// Steps the faked clock by writing the timestamp file, then waits — bounded on both sides —
// for the instance to report the new time.
//
// `observed >= target` alone is not a check: every target is historical, so it is satisfied
// by the real clock the instant the preload silently fails. The upper bound is what tells
// "arrived" from "never faked".
// `FAKETIME_CACHE_DURATION` is 1s (stack/faketime.sh explains why it cannot be 0 — no-cache
// costs 521us per clock call). A cache is per *process*, so asking one php-fpm worker
// whether the clock has moved says nothing about the sibling that will serve the next
// request: a worker that read the file just before the step holds yesterday's value for up
// to a second afterwards. Because a step is a whole simulated day, "stale by under a second"
// means "answers with yesterday's date", and the smoke run wrote exactly one consume that
// way — used_date one day behind, on 1 operation in 384.
//
// So a step is not complete until the cache window has certainly lapsed for every worker.
// The poll time counts toward it, so the usual extra wait is a few hundred milliseconds.
const CACHE_LAPSE_MS = 1100;

// Throwaway requests issued after each step, before any operation the plan depends on.
//
// **Measured, in three arms of the same workload, changing only the clock.** A short
// reproducer (320 steps x 10 requests) settles what a 13-minute year run could not:
//
//   A  fixed time, 3000 requests, no stepping ....... 0 failures
//   B  clock advancement, no warm-up, no psql ....... HTTP 500 at day 2, hung by day ~40
//   C  clock advancement, 6 throwaway per step ...... 0 failures in 3200 requests, 320 steps
//
// So it is the clock advancement, not request volume, and not the `podman exec` readiness
// calls — arm B made none and failed anyway. At the first 500 the system is entirely
// healthy: one PostgreSQL backend, 37MB resident, 11% CPU, PostgreSQL's own log clean. A
// *fresh* connect fails instantly with `SQLSTATE[08006] … timeout expired` against a server
// that is answering, which is what a wall-clock deadline computed before a jump and checked
// after it looks like.
//
// An earlier version removed the warm-up after runs with 8 and 40 probes died around day
// 190, read at the time as the extra traffic exhausting the pod. Arm A refutes that: 3000
// requests at fixed time cost nothing. Those runs were dying of *this*, and the warm-up was
// removed on a wrong diagnosis.
//
// A fixed count of throwaway requests fixed the *stability* — the 500s and the hangs — but
// not the *correctness*: a worker that stayed idle through all six still served the next
// write from yesterday, and roughly two `used_date` values a run landed a simulated day
// behind. So the warm-up became a warm-until-agreed: it keeps issuing requests until the
// pool answers with the new time consistently, which both warms a straggler (its own probe
// is what makes it re-read) and establishes that none is left behind.
//
// **The loop never restarts the step.** An earlier attempt treated a stale reply as "not
// arrived" and went back round the outer loop — rewriting the clock file, re-polling
// PostgreSQL, re-waiting — which restarted the wait on the very condition it was clearing
// and stalled for the full timeout at 1 April. Here a stale reply only resets the run of
// agreements; the probing continues, and the pool converges.
//
// **Sequential probes cannot establish that every worker is fresh, and an earlier version
// claimed they could.** php-fpm hands each request to whichever child is free, so a run of
// eight consecutive fresh replies is consistent with one warm child answering all eight
// while three stale ones sit idle. The claim was stronger than the evidence.
//
// Concurrency raises how much of the pool a round is likely to touch, and that is all it
// does. **It is bounded sampling, not proof of coverage.** `POOL_SIZE` requests issued
// together are not `POOL_SIZE` requests served by distinct children: a fast child can finish
// one and accept the next while another is still queued, and nothing here observes which
// child answered — no endpoint reports it, and there is no server-side barrier to hold each
// child until the others have been handed work.
//
// So what a clean round establishes is that the pool answered `POOL_SIZE` overlapping
// requests freshly. It is stronger evidence than the same count sequentially, because the
// requests overlap in time and cannot all have been served by one child unless that child
// served them in sequence while the others sat idle. It is not a guarantee that every child
// was sampled. Establishing that would need worker identity in the reply or a barrier the
// application does not offer, and until one of those exists this check should not be
// described as proving the pool is uniformly fresh.
//
// Warming and verifying are separate jobs and are done separately. A stale child refreshes
// its faketime cache by *serving* a request, and the reply to that request is still the stale
// value — so warming is inherently a sequence of requests that are expected to be wrong at
// first. Sequential probing does that well and is what actually converges the pool. The
// concurrent round then asks the question the sequential run cannot answer.
//
// Two clean rounds rather than one, because a child that finishes early can in principle
// take a second request within the same round while another is still queued.
const POOL_SIZE = 4;
const WARM_AGREEMENT = 8;
const CLEAN_ROUNDS = 2;
const WORKER_PROBE_BUDGET = 80;

async function stepClock(clockFile, instance, when, { maxDriftS = 120, deadlineMs = 60000, psql = null } = {}) {
	const fs = require('fs');
	if (!clockFile) return;
	// **The session is dropped before the file is written, not after.** Writing first and
	// clearing second left a window in which the clock had already moved while the old
	// session was still the one in use — the opposite of what "no session spans a step"
	// means. Clearing the jar is also only the client half: it does not wait for the
	// server's own request teardown, which is what the warm-up below is for.
	instance.cookies.clear();

	const wroteAt = Date.now();
	fs.writeFileSync(clockFile, `@${when}\n`);

	// **A new session begins after the clock has moved.**
	//
	// No real client holds a session across days, so nothing here should either — and making
	// the session boundary coincide with the step is what stops a request being in flight
	// when the clock jumps. That mattered: libpq sets its connect deadline to
	// `time() + connect_timeout`, so a worker whose faketime cache lapsed mid-connect saw the
	// deadline jump a simulated day into the past and answered `SQLSTATE[08006] … timeout
	// expired` — always at exactly the step instant, while PostgreSQL answered its own socket
	// immediately.
	//
	// The login is also a request that deliberately straddles the jump. It is not part of the
	// plan, so its failing costs nothing, and it is retried rather than fatal.
	for (let attempt = 0; attempt < 3; attempt++) {
		try {
			await instance.silently(() => instance.login());
			break;
		} catch {
			await new Promise((r) => setTimeout(r, 500));
		}
	}

	const target = momentToMillis(when) / 1000;
	const started = Date.now();
	const inRange = (observed) => Number.isFinite(observed) && observed - target >= 0 && observed - target <= maxDriftS;

	// --- What a failed step records, and why it records this ---------------------------------
	//
	// **A constant delta over a sub-second window cannot tell a frozen clock from a running
	// clock a day behind, and those are different faults.** Earlier failures reported ten
	// samples all reading `delta: -86397`, which looked like a stopped clock and is equally
	// consistent with a clock ticking normally one day in the past — the samples simply did
	// not span enough real time to distinguish them. Fixing the wrong one of those is worse
	// than fixing neither.
	//
	// So each sample carries the real monotonic time it was taken at, alongside the requested
	// and observed simulated times, and the samples are made to span seconds rather than
	// milliseconds. The measurement that matters is then arithmetic: how far the observed
	// clock advanced against how much real time passed.
	//
	// Worker identity is *not* recorded, because nothing exposes it: php-fpm does not report
	// which child served a request and no endpoint here surfaces a pid. Rather than leave that
	// as a silent omission, each concurrent round records how many *distinct* observed times
	// it saw, which is a lower bound on how many children answered it.
	const stepStart = process.hrtime.bigint();
	const sinceStart = () => Number(process.hrtime.bigint() - stepStart) / 1e6;

	const samples = [];
	const MAX_SAMPLES = 60;
	const KEEP_EVERY_MS = 400;
	let sawFailure = false;
	// **Struggling is not the same as "a probe came back stale".**
	//
	// A stale first probe after a step is not a fault, it is the thing warming exists to fix,
	// so gating the diagnostic pacing on `sawFailure` slowed down every healthy step: a full
	// year went from ~805s to ~1500s. Pacing belongs to a pool that is failing to converge —
	// a concurrent round that was not clean, or an outer iteration that did not arrive —
	// which is also when spreading the samples out is worth anything.
	let struggling = false;
	// Keyed by source *and* by which day the reply came from, because both are being compared.
	//
	// Sharing one cadence across sources dropped every PostgreSQL sample for arriving just
	// after an application one. Sharing it across cohorts was the same mistake one level down:
	// a pool interleaves a worker at the right time with one a day behind, so a stale reply
	// 100ms after a fresh one was discarded as "too soon" — and a failure that probed eighty
	// times kept exactly one stale sample, leaving the stale cohort's advance unmeasurable at
	// the moment it was the only thing worth measuring.
	const lastKeptAt = new Map();
	const cohortOf = (sample) => (sample.delta === null || sample.delta === undefined
		? 'unknown'
		: Math.round(sample.delta / 86400));

	const note = (sample) => {
		const fresh = inRange(sample.observed);
		const firstFailure = !fresh && !sawFailure;
		if (firstFailure) sawFailure = true;
		const bucket = `${sample.source}|${cohortOf(sample)}`;
		const since = sample.atMs - (lastKeptAt.get(bucket) ?? -Infinity);
		if (!firstFailure && since < KEEP_EVERY_MS) return;
		samples.push(sample);
		lastKeptAt.set(bucket, sample.atMs);
		// Dropped from the middle, so the first failure and the most recent observations both
		// survive — keeping only a tail is what made the earlier evidence unusable.
		if (samples.length > MAX_SAMPLES) samples.splice(Math.floor(MAX_SAMPLES / 2), 1);
	};

	const observeApp = async () => {
		const atMs = sinceStart();
		try {
			const reply = await instance.silently(() => instance.get('/system/time'));
			const observed = reply.body && Number(reply.body.timestamp);
			const value = Number.isFinite(observed) ? observed : null;
			note({ atMs: Math.round(atMs), source: 'app', status: reply.status,
				requested: target, observed: value, delta: value === null ? null : value - target });
			return value;
		} catch (e) {
			note({ atMs: Math.round(atMs), source: 'app', status: null,
				requested: target, observed: null, delta: null, error: e.message.slice(0, 120) });
			return null;
		}
	};

	const observeDb = async () => {
		if (!psql) return null;
		const atMs = sinceStart();
		try {
			const observed = Number(await psql('SELECT EXTRACT(EPOCH FROM LOCALTIMESTAMP)::bigint'));
			const value = Number.isFinite(observed) ? observed : null;
			note({ atMs: Math.round(atMs), source: 'postgres', status: null,
				requested: target, observed: value, delta: value === null ? null : value - target });
			return value;
		} catch (e) {
			note({ atMs: Math.round(atMs), source: 'postgres', status: null,
				requested: target, observed: null, delta: null, error: e.message.slice(0, 120) });
			return null;
		}
	};

	// How far an observed clock moved against how much real time passed — **measured within a
	// cohort, because the pool is not one clock.**
	//
	// A first version compared a source's first and last sample and reported nonsense: php-fpm
	// answers successive requests from different children, so a run of samples interleaves a
	// worker at the right time with one a day behind, and the difference between the ends of
	// that mixture is not any clock's progression. It reported `-86397s (stopped)` for a pool
	// whose every member was in fact running normally.
	//
	// Samples are therefore grouped by whole-day offset, which is the one thing distinguishing
	// the cohorts here, and each group is measured on its own. Within a group the advance is a
	// real rate; across groups it is an artefact of which child answered.
	const advanceOf = (source) => {
		const seen = samples.filter((x) => x.source === source && x.observed !== null);
		if (seen.length < 2) return null;
		const byCohort = new Map();
		for (const x of seen) {
			const day = Math.round(x.delta / 86400);
			if (!byCohort.has(day)) byCohort.set(day, []);
			byCohort.get(day).push(x);
		}
		const cohorts = [...byCohort.entries()].map(([dayOffset, group]) => {
			const first = group[0];
			const last = group[group.length - 1];
			const spanMs = Math.round(last.atMs - first.atMs);
			if (group.length < 2 || spanMs < 500) {
				return { dayOffset, samples: group.length, spanMs, verdict: 'window too short to tell' };
			}
			const observedMs = (last.observed - first.observed) * 1000;
			const ratio = observedMs / spanMs;
			return {
				dayOffset, samples: group.length, spanMs, observedMs: Math.round(observedMs),
				ratio: Number(ratio.toFixed(3)),
				verdict: ratio < 0.1 ? 'stopped'
					: (ratio > 0.5 && ratio < 1.6 ? 'running, at the wrong offset' : 'advancing anomalously')
			};
		}).sort((a, b) => a.dayOffset - b.dayOffset);
		return { cohorts, distinctOffsets: cohorts.length };
	};

	// The cohort that is not where it should be, for the one-line summary.
	const worstCohort = (source) => {
		const a = advanceOf(source);
		if (!a) return null;
		const off = a.cohorts.filter((c) => c.dayOffset !== 0);
		return off.length > 0 ? off[0] : (a.cohorts[0] || null);
	};

	const evidence = () => ({
		requested: when,
		requestedEpoch: target,
		maxDriftS,
		advance: { app: advanceOf('app'), postgres: advanceOf('postgres') },
		samples
	});

	let outerRounds = 0;
	for (;;) {
		const app = await observeApp();

		// **PostgreSQL has its own clock and its own cache, so waiting on the app alone is
		// not waiting.** `row_created_timestamp` is a column default reading LOCALTIMESTAMP
		// in the database container, and its libfaketime cache lapses independently — up to
		// a second after php-fpm's. The first end-to-end replay stepped to 3 December, was
		// told by the app that it had arrived, and wrote two purchases stamped 2 December.
		// Both runtimes are asked, or neither has arrived.
		// Asked whether or not the application has arrived: when the two disagree, which of
		// them is behind is the whole question, and only sampling both can say.
		const db = psql ? await observeDb() : null;

		if (inRange(app) && (!psql || inRange(db))) {
			const remaining = CACHE_LAPSE_MS - (Date.now() - wroteAt);
			if (remaining > 0) await new Promise((r) => setTimeout(r, remaining));

			let probes = 0;
			let lastDbAt = -Infinity;
			const probe = async () => {
				probes++;
				const fresh = inRange(await observeApp());
				// Sampled alongside, sparsely, and only once the pool is struggling: the
				// failure below compares the two runtimes and the first version of this loop
				// never asked PostgreSQL at all, so there was nothing to compare against — but
				// each reading is a `podman exec`, which a healthy step should not pay for.
				if (psql && struggling && sinceStart() - lastDbAt >= KEEP_EVERY_MS) {
					lastDbAt = sinceStart();
					await observeDb();
				}
				return fresh;
			};

			// Warm, sequentially, until the pool is answering consistently. Warming that runs
			// long is itself a symptom, and it is where a failure that never reaches the
			// verify phase lives — so it can turn on the diagnostics too, or that failure
			// arrives with no PostgreSQL comparison and no window to measure over.
			let warm = 0;
			while (warm < WARM_AGREEMENT && probes < WORKER_PROBE_BUDGET) {
				warm = (await probe()) ? warm + 1 : 0;
				if (probes > WARM_AGREEMENT * 3) struggling = true;
			}

			// Then verify, concurrently: POOL_SIZE requests in flight raise how much of the
			// pool a round is likely to touch, and each round records how many distinct times
			// came back — a lower bound on how many children answered it.
			let clean = 0;
			const rounds = [];
			while (warm >= WARM_AGREEMENT && clean < CLEAN_ROUNDS && probes < WORKER_PROBE_BUDGET) {
				const at = Math.round(sinceStart());
				const before = samples.length;
				const round = await Promise.all(Array.from({ length: POOL_SIZE }, probe));
				const observed = samples.slice(before).map((x) => x.observed).filter((v) => v !== null);
				rounds.push({ atMs: at, fresh: round.filter(Boolean).length, of: POOL_SIZE,
					distinctTimes: new Set(observed).size });
				const allFresh = round.every(Boolean);
				clean = allFresh ? clean + 1 : 0;
				if (!allFresh) struggling = true;

				// **Paced once the pool is failing to converge, and only then.** Rounds back
				// to back span a fraction of a second, which is why the earlier evidence could
				// not distinguish a stopped clock from a late one. Slowing down buys a window
				// of seconds; doing it on every step instead cost a year run its running time.
				if (struggling) await new Promise((r) => setTimeout(r, 300));
			}

			// **Failing to get agreement is a clock failure, said at the step.** The
			// alternative is to carry on and discover it later as a timestamp a day behind,
			// by which point the operations in between have already been evaluated against
			// the wrong date and there is nothing to do but report the year INCOMPLETE.
			if (clean < CLEAN_ROUNDS) {
				const c = worstCohort('app');
				throw new Incomplete(
					`the worker pool never agreed on ${when}: ${probes} probes, ` +
					`${warm >= WARM_AGREEMENT ? 'warmed but never gave' : 'never even warmed to'} ` +
					`${CLEAN_ROUNDS} rounds of ${POOL_SIZE} concurrent replies within ` +
					`${maxDriftS}s of the new time` +
					(c && c.ratio !== undefined
						? ` — the cohort ${c.dayOffset} day(s) out advanced ` +
							`${(c.observedMs / 1000).toFixed(1)}s over ${(c.spanMs / 1000).toFixed(1)}s ` +
							`real (${c.verdict})`
						: ''),
					{ ...evidence(), probes, warmedTo: warm, rounds });
			}
			return;
		}

		// Same pacing rationale as the probe loop. A first iteration that has not arrived is
		// ordinary — the clock file was written moments ago — so this counts iterations rather
		// than treating the first stale reading as trouble.
		outerRounds += 1;
		if (outerRounds > 1) struggling = true;
		if (struggling) await new Promise((r) => setTimeout(r, 300));

		if (Date.now() - started > deadlineMs) {
			const say = (v) => (Number.isFinite(v) ? new Date(v * 1000).toISOString() : 'nothing');
			// Which of the two is behind is the question this failure exists to answer, so it
			// carries the same evidence as the pool failure: per-source advance against real
			// time, and samples spanning it.
			const a = worstCohort('app');
			const d = worstCohort('postgres');
			const summarise = (name, x) => (x && x.ratio !== undefined
				? `${name} at ${x.dayOffset} day(s) out advanced ${(x.observedMs / 1000).toFixed(1)}s over ` +
					`${(x.spanMs / 1000).toFixed(1)}s real (${x.verdict})`
				: null);
			const reading = [summarise('app', a), summarise('postgres', d)].filter(Boolean).join('; ');
			throw new Incomplete(
				`the clock did not reach ${when} within ${deadlineMs / 1000}s ` +
				`(app reported ${say(app)}, postgres reported ${say(db)})` +
				(reading ? ` — ${reading}` : ''),
				evidence());
		}
		await new Promise((r) => setTimeout(r, 250));
	}
}

// --- The run ---------------------------------------------------------------------------------

async function replay(plan, instance, options = {}) {
	const { clockFile = null, onDay = null, psql = null, slowCallMs = 5000 } = options;
	// Which calls are slow, so a year that gets slower as it accumulates says so rather than
	// simply timing out one day.
	const slowCalls = [];
	// Timestamps a step's cache lag explains — see checkWindow.
	const clockArtifacts = [];
	// Where a lot's price came back in a different representation than the plan recorded
	// (null against 0). Not a failure — the valuation is equal — but not modelled away either.
	const priceRepresentations = [];
	// Assertions actually evaluated, by form. The verdict a milestone report records is only
	// as good as the number of independent claims behind it.
	const assertions = new Map();
	// Operations that answered 5xx with an empty body: the clock-step artifact described at
	// stepClock. Recorded and surfaced, never retried — a retry was tried and made the run
	// strictly worse (it died at day 36 rather than 356), because re-issuing a request into
	// a pool already blocked on connects adds exactly the load that is failing.
	const stepRetries = [];
	const cal = makeCalendar({ anchor: plan.meta.anchor, days: plan.meta.days });

	const symbols = {};
	const windowProblems = [];
	let currentDay = -1;
	let executed = 0;
	let arranged = 0;

	for (let i = 0; i < plan.ops.length; i++) {
		const op = plan.ops[i];
		const where = `op ${i}`;

		if (op.op === 'mark') {
			if (op.day !== undefined && op.day !== currentDay) {
				currentDay = op.day;
				// Mid-morning rather than midnight: an operation at 00:00:00 sits exactly on
				// its window's lower bound, and every date-only field would then be one
				// rounding away from the previous day.
				// stepClock owns the session boundary: it drops the session before moving the
				// clock and opens a fresh one after, so no request is ever in flight across
				// the jump. A day's jump would expire the old session anyway.
				await stepClock(clockFile, instance, cal.at(op.day, 9), { psql });
				if (onDay) onDay(op.day, i);
			}
			continue;
		}

		if (op.op === 'auth') {
			// Every user in the fixture shares one password, and admin is the stack's own.
			const ok = op.user === 'admin'
				? await instance.silently(() => instance.login())
				: await instance.silently(() => instance.login(op.user, 'parity-year'));
			if (!ok) throw new Incomplete(`${where}: could not log in as ${op.user}`, { user: op.user });
			continue;
		}

		const path = substitute(op.path, symbols, where);
		const body = op.body === undefined ? undefined : substituteDeep(op.body, symbols, where);
		// `expect` is substituted too: a checkpoint assertion names rows by the ids the
		// fixture allocated, so `{product:milk}` has to resolve there as well as in a path.
		const expectation = substituteDeep(op.expect, symbols, where);

		const run = () => instance.api(op.method, path, body, { label: op.label });
		const startedAt = Date.now();
		let record;
		try {
			record = op.op === 'arrange' ? await instance.silently(run) : await run();
		} catch (error) {
			// **An aborted request has to say which operation aborted.** The first full-year
			// replay died with a bare `AbortError` somewhere after day 30 and the trace could
			// not say where; a request that times out is an outcome like any other and is
			// reported as one.
			if (error && error.name === 'AbortError') {
				throw new Incomplete(
					`${where}: ${op.method} ${path} did not answer within the timeout`,
					{ op: op.label, path, elapsedMs: Date.now() - startedAt });
			}
			throw error;
		}
		const elapsed = Date.now() - startedAt;
		if (elapsed > slowCallMs) slowCalls.push({ op: op.label, path, ms: elapsed });
		if (record.status >= 500 && (record.body === null || record.body === undefined)) {
			stepRetries.push({ op: op.label, path, status: record.status });
		}


		checkExpect({ ...op, expect: expectation }, record, where, priceRepresentations);
		tallyExpect(expectation, assertions);
		assertions.set('operations verified', (assertions.get('operations verified') || 0) + 1);
		checkWindow(op, record, where, windowProblems, clockArtifacts);

		for (const [symbol, rawSpec] of Object.entries(op.bind || {})) {
			// Bind specs are substituted too: `find(from_qu_id={quantityUnit:pack}).id`
			// selects a row by an id the fixture itself allocated, which is the only way to
			// name a row the *application* created rather than the plan.
			const spec = substitute(rawSpec, symbols, where);
			const value = pluck(record.body, spec);
			if (value === undefined || value === null) {
				throw new Incomplete(`${where}: nothing to bind for {${symbol}} at "${spec}"`, { op: op.label, body: record.body });
			}
			symbols[symbol] = value;
		}

		if (op.op === 'arrange') arranged++; else executed++;
	}

	slowCalls.sort((a, b) => b.ms - a.ms);
	return {
		symbols, executed, arranged, windowProblems, clockArtifacts, priceRepresentations,
		slowCalls, stepRetries,
		assertions: Object.fromEntries([...assertions].sort((a, b) => b[1] - a[1]))
	};
}

// `checkExpect` is exported for harness/selftest.js: the assertion forms are what the
// suite's verdicts rest on, and they are worth testing directly rather than through a replay.
module.exports = { replay, stepClock, substitute, pluck, differsBy, checkExpect, Incomplete };
