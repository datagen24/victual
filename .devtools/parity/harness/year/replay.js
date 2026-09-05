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

function substituteDeep(value, symbols, where) {
	if (typeof value === 'string') return substitute(value, symbols, where);
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

function checkExpect(op, record, where) {
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
		const sum = (Array.isArray(body) ? body : []).reduce((n, r) => n + Number((r && r.amount) || 0), 0);
		if (Math.abs(sum - e.rowsSum) > 1e-6) {
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

	for (const [key, want] of Object.entries(e.rowEquals || {})) {
		const row = Array.isArray(body) ? body[0] : body;
		if (!row || String(row[key]) !== String(want)) {
			throw new Incomplete(`${where}: first row's ${key} is ${row ? row[key] : 'absent'}, expected ${want}`, { op: op.label, row });
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
// The pool is `pm = static` with `pm.max_children = 4` (nix/runtime/fpm-conf.nix), so eight
// consecutive agreements is comfortably more than one pass over it.
const WORKER_AGREEMENT = 8;
const WORKER_PROBE_BUDGET = 80;

// How many extra requests are sent after a step to warm the worker pool.
//
// **Warming, not gating.** Requiring N consecutive in-range replies looked stricter and was
// worse: php-fpm hands each request to whichever child is free, an idle child refreshes its
// faketime cache only when it next runs, and so the *first* reply from each straggler is
// stale by construction. A gate on consecutive freshness therefore restarted itself on the
// very condition it was waiting to clear, and a 365-day run stalled for the full 60s at
// 1 April with both runtimes reporting exactly the target time. Sending a handful of cheap
// requests makes every idle child run once — which is what actually refreshes them — and any
// straggler that still slips through is reported by the window check as a finding rather
// than deadlocking the step.
// **Warming the worker pool was tried and withdrawn.** Each probe is a request, the
// application opens a PostgreSQL connection per request, and a year has enough steps that
// the extra traffic exhausted the pod's ability to make new ones: php-fpm workers piled up
// blocked on `connection to server at "postgres" … timeout expired`, nginx answered 504, and
// the run died around day 190 — while PostgreSQL itself stayed healthy and answered its own
// socket immediately. The run without any warm-up completed all 365 days; every run with it
// wedged. The cure was worse than the disease.
//
// What the disease actually costs: roughly 8 consumes in 1168 recorded a `used_date` one
// simulated day behind, because php-fpm hands a request to whichever child is free and each
// child holds its own one-second faketime cache. Those are *reported* by the window check
// rather than engineered around — a named, counted, sub-1% observation is worth more than a
// mitigation that stops the suite finishing.
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
// healthy: one PostgreSQL backend, 37MB resident, 11% CPU, and PostgreSQL's own log clean.
// A *fresh* connect fails instantly with `SQLSTATE[08006] … timeout expired` against a
// server that is answering, which is what a wall-clock deadline computed before a jump and
// checked after it looks like.
//
// An earlier version of this file removed the warm-up after runs with 8 and 40 probes died
// around day 190, which was read as the extra traffic exhausting the pod. Arm A refutes
// that: 3000 requests at fixed time cost nothing. Those runs were dying of *this*, and the
// warm-up was removed on a wrong diagnosis.
//
// Six is enough to touch every worker: the pool is `pm = static` with `pm.max_children = 4`
// (nix/runtime/fpm-conf.nix). Their outcomes are ignored, because their job is to be the
// requests that meet the discontinuity instead of a planned operation meeting it.

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

	for (;;) {
		const record = await instance.silently(() => instance.get('/system/time'));
		const app = record.body && Number(record.body.timestamp);

		// **PostgreSQL has its own clock and its own cache, so waiting on the app alone is
		// not waiting.** `row_created_timestamp` is a column default reading LOCALTIMESTAMP
		// in the database container, and its libfaketime cache lapses independently — up to
		// a second after php-fpm's. The first end-to-end replay stepped to 3 December, was
		// told by the app that it had arrived, and wrote two purchases stamped 2 December.
		// Both runtimes are asked, or neither has arrived.
		let db = null;
		if (psql && inRange(app)) {
			try {
				db = Number(await psql('SELECT EXTRACT(EPOCH FROM LOCALTIMESTAMP)::bigint'));
			} catch {
				db = null;  // unreadable is not "arrived"
			}
		}

		if (inRange(app) && (!psql || inRange(db))) {
			const remaining = CACHE_LAPSE_MS - (Date.now() - wroteAt);
			if (remaining > 0) await new Promise((r) => setTimeout(r, remaining));

			let agreed = 0;
			let probes = 0;
			while (agreed < WORKER_AGREEMENT && probes < WORKER_PROBE_BUDGET) {
				probes++;
				let fresh = false;
				try {
					const again = await instance.silently(() => instance.get('/system/time'));
					fresh = inRange(again.body && Number(again.body.timestamp));
				} catch {
					fresh = false;   // a request that met the discontinuity; it has now warmed that worker
				}
				agreed = fresh ? agreed + 1 : 0;
			}

			// **Failing to get agreement is a clock failure, said at the step.** The
			// alternative is to carry on and discover it later as a timestamp a day behind,
			// by which point the operations in between have already been evaluated against
			// the wrong date and there is nothing to do but report the year INCOMPLETE.
			if (agreed < WORKER_AGREEMENT) {
				throw new Incomplete(
					`the worker pool never agreed on ${when}: ${probes} probes without ` +
					`${WORKER_AGREEMENT} consecutive replies at the new time`,
					{ when, probes });
			}
			return;
		}

		if (Date.now() - started > deadlineMs) {
			const say = (v) => (Number.isFinite(v) ? new Date(v * 1000).toISOString() : 'nothing');
			throw new Incomplete(
				`the clock did not reach ${when} within ${deadlineMs / 1000}s ` +
				`(app reported ${say(app)}, postgres reported ${say(db)})`,
				{ when });
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


		checkExpect({ ...op, expect: expectation }, record, where);
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
	return { symbols, executed, arranged, windowProblems, clockArtifacts, slowCalls, stepRetries };
}

module.exports = { replay, stepClock, substitute, pluck, Incomplete };
