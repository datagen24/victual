'use strict';

// The year phase.
//
//   node run-year.js --plan-only [--profile smoke|year|dense] [--seed N] [--anchor YYYY-MM-DD]
//
// **Only `--plan-only` is implemented so far**, and that is a stage rather than an
// oversight: generating the year is worth having on its own, because the plan is a readable,
// diffable description of what a run will do that can be reviewed before a container is
// started. Replay against an instance is the next stage; the flags it will need are parsed
// and refused here rather than silently ignored, so a command that cannot work says so.
//
// Exit 0 when the plan generated and validated, 1 when it did not, 2 on an unhandled throw.

const fs = require('fs');
const path = require('path');

const { buildYearPlan, lockKey, GENERATOR_VERSION, CANONICAL_ANCHOR, PROFILES } = require('./year/plan');
const { validate } = require('./year/validate');
const { Instance } = require('./lib/instance');
const { replay, Incomplete } = require('./year/replay');
const invariants = require('./year/invariants');

const LOCK_PATH = path.join(__dirname, 'year', 'plan.lock.json');

function parseArgs(argv) {
	const args = {
		profile: process.env.PARITY_YEAR_PROFILE || 'year',
		seed: Number(process.env.PARITY_YEAR_SEED || 20260905),
		anchor: process.env.PARITY_YEAR_ANCHOR || CANONICAL_ANCHOR,
		out: path.join(__dirname, '..', 'reports'),
		planOnly: false,
		updateLock: false,
		printMeta: false,
		invariantsOnly: false,
		victual: process.env.PARITY_VICTUAL_URL || 'http://127.0.0.1:8080',
		clockFile: process.env.PARITY_CLOCK_FILE || null,
		// The fork-only side. Absent means the delivery checks are skipped and say so,
		// rather than passing by not running.
		influx: process.env.PARITY_INFLUX_URL || null,
		influxToken: process.env.PARITY_INFLUX_TOKEN || 'victual-parity-token',
		influxOrg: process.env.PARITY_INFLUX_ORG || 'victual',
		influxBucket: process.env.PARITY_INFLUX_BUCKET || 'victual',
		mqtt: process.env.PARITY_MQTT || null,
		unimplemented: []
	};
	for (let i = 2; i < argv.length; i++) {
		const flag = argv[i];
		if (flag === '--profile') args.profile = argv[++i];
		else if (flag === '--seed') args.seed = Number(argv[++i]);
		else if (flag === '--anchor') args.anchor = argv[++i];
		else if (flag === '--out') args.out = argv[++i];
		else if (flag === '--plan-only') args.planOnly = true;
		else if (flag === '--update-plan-lock') args.updateLock = true;
		else if (flag === '--print-meta') args.printMeta = true;
		else if (flag === '--invariants-only') args.invariantsOnly = true;
		else if (flag === '--victual') args.victual = argv[++i];
		else if (flag === '--clock-file') args.clockFile = argv[++i];
		else if (flag === '--influx') args.influx = argv[++i];
		else if (flag === '--mqtt') args.mqtt = argv[++i];
		// Parsed and refused rather than ignored: a run that quietly did something other
		// than what was asked is worse than one that stops.
		else if (['--deep', '--against', '--update-baseline', '--no-reset', '--verbose-report'].includes(flag)) {
			args.unimplemented.push(flag);
			if (flag === '--against') i++;
		}
	}
	return args;
}

function readLock() {
	try {
		return JSON.parse(fs.readFileSync(LOCK_PATH, 'utf8'));
	} catch {
		return { generatorVersion: GENERATOR_VERSION, plans: {} };
	}
}

// Read-only SQL, handed down by bin/parity because how to reach this stack's PostgreSQL is
// stack knowledge. Without it the outbox check reports that it could not run, rather than
// silently not running.
function psqlRunner() {
	const command = process.env.PARITY_PSQL_CMD;
	if (!command) return null;
	const { execFileSync } = require('child_process');
	return (sql) => execFileSync('sh', ['-c', `${command} ${JSON.stringify(sql)}`],
		{ encoding: 'utf8', timeout: 30000 }).trim().split('\n').pop();
}

async function runAgainstInstance(args, plan) {
	// A longer timeout than the api phase's 30s, on purpose: a checkpoint read over a year of
	// accumulated stock is legitimately slower than the same read over a scenario's handful
	// of rows, and the first full-year run aborted on one rather than reporting it.
	const api = new Instance({
		name: 'victual', baseUrl: args.victual, apiKeyHeader: 'VICTUAL-API-KEY', timeoutMs: 180000
	});
	await api.login();

	const started = Date.now();
	let lastDay = -1;
	const result = await replay(plan, api, {
		clockFile: args.clockFile,
		psql: psqlRunner(),
		onDay: (day) => {
			if (day - lastDay >= 30 || day === 0) {
				lastDay = day;
				const mb = (process.memoryUsage().heapUsed / 1048576).toFixed(0);
				process.stdout.write(`    day ${String(day).padStart(3)} of ${plan.meta.days}   ` +
					`${((Date.now() - started) / 1000).toFixed(0)}s   heap ${mb}MB\n`);
			}
		}
	});

	console.log('');
	console.log(`  replayed ${result.executed} recorded and ${result.arranged} arranged operations in ` +
		`${((Date.now() - started) / 1000).toFixed(0)}s`);
	// Measured rather than assumed: both full traces are held at once in parity mode, so the
	// single-instance figure is the number that has to be doubled when the second lands.
	console.log(`  peak heap ${(process.memoryUsage().heapUsed / 1048576).toFixed(0)}MB, trace ${api.trace.length} records`);

	if (result.stepRetries.length > 0) {
		console.log('');
		console.log(`  \x1b[33m${result.stepRetries.length} operations answered 5xx with an empty body\x1b[0m`);
		console.log('    (the clock-step artifact described in year/replay.js — libpq deadlines under a jumping clock)');
		for (const r of result.stepRetries.slice(0, 5)) console.log(`      ${r.status}  ${r.op}`);
	}

	if (result.slowCalls.length > 0) {
		console.log('');
		console.log(`  ${result.slowCalls.length} calls took over 5s — the slowest:`);
		for (const c of result.slowCalls.slice(0, 6)) {
			console.log(`    ${(c.ms / 1000).toFixed(1)}s  ${c.op}`);
		}
	}

	if (result.clockArtifacts.length > 0) {
		console.log('');
		console.log(`  \x1b[31m${result.clockArtifacts.length} timestamps were one simulated day behind — the clock contract was violated\x1b[0m`);
		console.log('    A worker that was a day behind evaluated expiry, due-soon windows and');
		console.log('    scheduling against the wrong date, so what those operations decided is');
		console.log('    not established by this run. See year/replay.js.');
		for (const w of result.clockArtifacts.slice(0, 4)) console.log(`      ${w.op}: ${w.problems.join('; ')}`);
	}

	// Not a verdict. An unknown price and an explicit zero are equivalent for a valuation,
	// which is the only place the comparison treats them alike; that they are *represented*
	// differently is unexplained, so it is reported as an observation and left visible rather
	// than modelled away.
	if (result.priceRepresentations && result.priceRepresentations.length > 0) {
		console.log('');
		console.log(`  \x1b[33mnote\x1b[0m  ${result.priceRepresentations.length} lot prices came back in a different representation than the plan recorded`);
		console.log('          (null against 0). Equal by valuation, so no assertion failed; the rule');
		console.log('          behind the difference is not established. Raw values are in the trace.');
		for (const r of result.priceRepresentations.slice(0, 4)) {
			console.log(`            ${r.op}: read ${JSON.stringify(r.raw)}, planned ${JSON.stringify(r.planned)}`);
		}
	}

	if (result.windowProblems.length > 0) {
		console.log('');
		console.log(`\x1b[31m  ${result.windowProblems.length} operations wrote a timestamp outside their simulated window\x1b[0m`);
		for (const w of result.windowProblems.slice(0, 8)) {
			console.log(`    ${w.op}: ${w.problems.join('; ')}`);
		}
	}

	console.log('');
	console.log('  invariants');
	const results = await invariants.check({
		instance: api, plan, symbols: result.symbols, psql: psqlRunner(),
		influx: args.influx
			? { url: args.influx, token: args.influxToken, org: args.influxOrg, bucket: args.influxBucket }
			: null,
		mqtt: args.mqtt
	});
	if (!args.influx) {
		console.log('    \x1b[33mskipped\x1b[0m  delivered-event checks (no --influx given)');
	}
	for (const r of results) {
		console.log(`    ${r.ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${r.name}`);
		if (r.detail) console.log(`          ${r.detail}`);
	}

	return {
		assertions: result.assertions,
		priceRepresentations: result.priceRepresentations || [],
		slowCalls: result.slowCalls || [],
		failed: results.filter((r) => !r.ok).length + (result.windowProblems.length > 0 ? 1 : 0),
		// Tracked apart from `failed`: a clock violation is not an application finding, and
		// it does not become one by being counted with them — but it does stop the run
		// claiming a verdict about a year it did not correctly simulate.
		clockViolations: result.clockArtifacts.length,
		results,
		windowProblems: result.windowProblems
	};
}

// **A milestone that lives only in a terminal is not preserved.** The verdict, what was
// asserted, how long it took, whether the clock held, and which commit produced it are the
// things a later run has to be compared against; a scrollback buffer carries none of them.
//
// The commit identity includes whether the tree was dirty, because a report from an
// uncommitted working copy names a commit that does not contain the code that ran — which is
// exactly the confusion this file exists to prevent.
function writeRunReport(args, plan, run, { verdict, elapsedS }) {
	const { execFileSync } = require('child_process');
	const git = (cmdArgs) => {
		try {
			return execFileSync('git', cmdArgs, { cwd: __dirname, encoding: 'utf8' }).trim();
		} catch { return null; }
	};
	const dirty = git(['status', '--porcelain']);

	const report = {
		verdict,
		recordedAt: new Date().toISOString(),
		commit: {
			sha: git(['rev-parse', 'HEAD']),
			describe: git(['log', '-1', '--format=%h %s']),
			dirty: dirty === null ? null : dirty.length > 0,
			// Named rather than counted: "3 files dirty" does not say whether they are the
			// ones under test.
			dirtyPaths: dirty ? dirty.split('\n').map((l) => l.trim()).slice(0, 40) : []
		},
		plan: {
			profile: plan.meta.profile,
			seed: plan.meta.seed,
			anchor: plan.meta.anchor,
			days: plan.meta.days,
			startDate: plan.meta.startDate,
			endDate: plan.meta.endDate,
			hash: plan.meta.hash,
			recordedCalls: plan.counts.recorded,
			arrangedCalls: plan.counts.arranged,
			bookings: plan.ledger.bookings
		},
		timing: { replaySeconds: elapsedS },
		clock: {
			violations: run.clockViolations,
			// A run on the real clock is not a year; say so rather than reporting zero
			// violations of a contract that was never in force.
			enforced: Boolean(args.clockFile)
		},
		assertions: run.assertions || {},
		invariants: (run.results || []).map((r) => ({ name: r.name, ok: r.ok, detail: r.detail || null })),
		windowProblems: (run.windowProblems || []).length,
		// Observations, not findings. Kept in the report so a later run can see whether the
		// unexplained representation difference changed.
		priceRepresentations: run.priceRepresentations || [],
		slowestCalls: (run.slowCalls || []).slice(0, 5)
	};

	fs.mkdirSync(args.out, { recursive: true });
	const file = path.join(args.out, `year-run-${plan.meta.profile}.json`);
	fs.writeFileSync(file, `${JSON.stringify(report, null, '\t')}\n`);
	console.log('');
	console.log(`  run report: ${file}`);
	if (report.commit.dirty) {
		console.log('    \x1b[33mthe working tree was dirty\x1b[0m — this report does not describe the named commit');
	}
}

async function main() {
	const args = parseArgs(process.argv);

	if (args.unimplemented.length > 0) {
		console.error(
			`  ${args.unimplemented.join(', ')} is not implemented yet — run-year.js currently ` +
			'only builds and validates a plan (--plan-only). Replay lands in the next stage.');
		process.exit(1);
	}
	if (!PROFILES[args.profile]) {
		console.error(`  unknown profile "${args.profile}" — one of ${Object.keys(PROFILES).join(', ')}`);
		process.exit(1);
	}

	const plan = buildYearPlan({ profile: args.profile, seed: args.seed, anchor: args.anchor });
	const result = validate(plan);
	const key = lockKey(plan.meta);

	// Shell-readable, so bin/parity can set the clock to the plan's first day *before*
	// booting PostgreSQL — the clock is a boot-time property and a backwards step past a
	// running server is a corrupted run rather than a failed one.
	if (args.printMeta) {
		for (const [k, v] of Object.entries(plan.meta)) console.log(`${k}=${v}`);
		process.exit(0);
	}

	console.log('');
	console.log(`\x1b[1mA simulated year — profile ${plan.meta.profile}\x1b[0m`);
	console.log(`  ${plan.meta.startDate} … ${plan.meta.endDate}  (${plan.meta.days} days, anchor ${plan.meta.anchor}, seed ${plan.meta.seed})`);
	console.log(`  ${plan.counts.recorded} recorded calls, ${plan.counts.arranged} arranged, ${plan.counts.byOp.auth || 0} session changes`);
	console.log(`  ${plan.ledger.bookings} stock bookings modelled`);
	console.log('');
	console.log('  operations by kind');
	for (const [kind, n] of Object.entries(plan.counts.byLedgerKind).sort((a, b) => b[1] - a[1])) {
		console.log(`    ${kind.padEnd(18)} ${String(n).padStart(5)}`);
	}

	console.log('');
	const missing = result.transactionTypes.missing;
	console.log(`  transaction types: ${result.transactionTypes.seen.length}/9 covered` +
		(missing.length ? `  \x1b[33mnot covered: ${missing.join(', ')}\x1b[0m` : '  \x1b[32mall nine\x1b[0m'));

	// The lock. A profile the lock does not carry is reported as unpinned rather than
	// treated as a mismatch — the hash cannot match a key that was never recorded.
	const lock = readLock();
	const recorded = (lock.plans || {})[key];
	if (args.updateLock) {
		lock.generatorVersion = GENERATOR_VERSION;
		lock.plans = lock.plans || {};
		lock.plans[key] = {
			hash: plan.meta.hash,
			recorded: plan.counts.recorded,
			arranged: plan.counts.arranged,
			bookings: plan.ledger.bookings,
			byLedgerKind: plan.counts.byLedgerKind
		};
		fs.writeFileSync(LOCK_PATH, `${JSON.stringify(lock, null, '\t')}\n`);
		console.log(`  \x1b[33mplan lock updated\x1b[0m for ${key}`);
	} else if (!recorded) {
		console.log(`  \x1b[33munpinned\x1b[0m — plan.lock.json has no entry for ${key}`);
	} else if (recorded.hash !== plan.meta.hash) {
		console.log(`  \x1b[31mplan drift\x1b[0m for ${key}`);
		console.log(`      locked ${recorded.hash}`);
		console.log(`      built  ${plan.meta.hash}`);
		console.log('      The generator changed. If that was deliberate, re-run with --update-plan-lock');
		console.log('      so the change arrives as a reviewable diff rather than as a silent one.');
		result.problems.push('plan hash does not match plan.lock.json');
	} else {
		console.log(`  \x1b[32mplan matches the lock\x1b[0m (${plan.meta.hash.slice(0, 16)}…)`);
	}

	if (result.problems.length > 0) {
		console.log('');
		console.log('\x1b[31m  problems\x1b[0m');
		for (const p of result.problems.slice(0, 20)) console.log(`    ${p}`);
		if (result.problems.length > 20) console.log(`    … and ${result.problems.length - 20} more`);
	}

	if (args.planOnly) {
		fs.mkdirSync(args.out, { recursive: true });
		const file = path.join(args.out, 'year-plan.json');
		fs.writeFileSync(file, JSON.stringify(plan, null, '\t'));
		console.log('');
		console.log(`  plan: ${file}`);
	}

	if (result.problems.length > 0) {
		console.log('');
		console.log(`\x1b[31mFAIL — ${result.problems.length} problems with the plan\x1b[0m`);
		process.exit(1);
	}

	if (!args.invariantsOnly) {
		console.log('');
		console.log('\x1b[32mPASS — the plan generated and validated\x1b[0m');
		process.exit(0);
	}

	console.log('');
	console.log(`  replaying against ${args.victual}${args.clockFile ? '' : '  (no clock file — running on the real clock)'}`);
	const startedAt = Date.now();
	const run = await runAgainstInstance(args, plan);
	const elapsedS = Math.round((Date.now() - startedAt) / 1000);

	const verdict = run.failed > 0 ? 'FAIL' : (run.clockViolations > 0 ? 'INCOMPLETE' : 'PASS');
	writeRunReport(args, plan, run, { verdict, elapsedS });

	console.log('');
	if (run.failed > 0) {
		console.log(`\x1b[31mFAIL — ${run.failed} invariants or window checks failed\x1b[0m`);
		process.exit(1);
	}
	if (run.clockViolations > 0) {
		// The inventory checks above stay visible on purpose: they are still evidence, and
		// hiding them would trade one kind of silence for another.
		console.log(`\x1b[31mINCOMPLETE — clock contract violated (${run.clockViolations} timestamps a day behind)\x1b[0m`);
		console.log('  Every invariant above held, but the year was not correctly simulated,');
		console.log('  so this is not a regression verdict.');
		process.exit(3);
	}
	console.log('\x1b[32mPASS — the year replayed and every invariant held\x1b[0m');
	process.exit(0);
}

main().catch((error) => {
	if (error instanceof Incomplete) {
		// **INCOMPLETE, not FAIL.** The suite could not ask its question: an operation did
		// not do what it said it would, so everything after it would have been measured
		// against a database in a state nobody planned. The failing operation is preserved
		// rather than summarised.
		console.error('');
		console.error(`\x1b[31mINCOMPLETE — ${error.message}\x1b[0m`);
		if (error.detail) console.error(`  ${JSON.stringify(error.detail).slice(0, 800)}`);
		process.exit(3);
	}
	console.error(error);
	process.exit(2);
});
