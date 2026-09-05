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

const LOCK_PATH = path.join(__dirname, 'year', 'plan.lock.json');

function parseArgs(argv) {
	const args = {
		profile: process.env.PARITY_YEAR_PROFILE || 'year',
		seed: Number(process.env.PARITY_YEAR_SEED || 20260905),
		anchor: process.env.PARITY_YEAR_ANCHOR || CANONICAL_ANCHOR,
		out: path.join(__dirname, '..', 'reports'),
		planOnly: false,
		updateLock: false,
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
		// Parsed and refused rather than ignored: a run that quietly did something other
		// than what was asked is worse than one that stops.
		else if (['--deep', '--invariants-only', '--against', '--update-baseline', '--no-reset', '--verbose-report'].includes(flag)) {
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

function main() {
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

	console.log('');
	console.log(result.problems.length === 0
		? `\x1b[32mPASS — the plan generated and validated\x1b[0m`
		: `\x1b[31mFAIL — ${result.problems.length} problems with the plan\x1b[0m`);

	process.exit(result.problems.length === 0 ? 0 : 1);
}

try {
	main();
} catch (error) {
	console.error(error);
	process.exit(2);
}
