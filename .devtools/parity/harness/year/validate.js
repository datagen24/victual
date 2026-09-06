'use strict';

// What has to be true of a plan before it is worth running.
//
// These are checks on the *generator*, not on the fork. Every one of them corresponds to a
// way the year could look busy and assert nothing — an operation with no expected outcome, a
// path naming a symbol nothing ever bound, a transaction type the narrative quietly stopped
// emitting. Each would produce a green run over a suite that had stopped asking, which is
// the failure mode the whole design is arranged against, so they are checked offline where
// the answer is cheap.

const { CONTRIBUTION } = require('./ledger');

// The nine transaction types stock_log can carry. The year is supposed to produce all of
// them; a profile too small to is allowed to say so, but not to drift silently.
const ALL_TRANSACTION_TYPES = Object.keys(CONTRIBUTION);

const SYMBOL_RE = /\{([a-zA-Z]+):([^}]+)\}/g;

function validate(plan) {
	const problems = [];
	const bound = new Set();

	plan.ops.forEach((op, index) => {
		const where = `op ${index} (${op.label || op.op})`;

		if (op.op === 'mark' || op.op === 'auth') return;

		// 1. Every write says what success looks like. Without this the run cannot tell a
		//    booking that happened from one both instances refused identically.
		if (!op.expect || op.expect.status === undefined) {
			problems.push(`${where}: no expected status`);
		} else if (op.method !== 'GET' && op.expect.status === 200 && !op.expect.shape && !op.expect.kind) {
			problems.push(`${where}: expects 200 but describes no response shape`);
		}

		// 2. Recorded operations are placed in simulated time. An operation with no window
		//    cannot have its timestamps checked against anything.
		if (op.op === 'call' && !op.window) {
			problems.push(`${where}: recorded call with no simulated-time window`);
		}

		// 3. Symbols are bound before they are used. A path that substitutes an unbound
		//    symbol becomes a literal `{product:milk}` in a URL, which is a 404 on both
		//    sides — equal, and therefore invisible to a differential suite.
		const text = `${op.path || ''} ${JSON.stringify(op.body || {})}`;
		for (const match of text.matchAll(SYMBOL_RE)) {
			const symbol = `${match[1]}:${match[2]}`;
			if (!bound.has(symbol)) problems.push(`${where}: uses {${symbol}} before anything bound it`);
		}
		for (const key of Object.keys(op.bind || {})) bound.add(key);
	});

	// 4. The narrative still emits every transaction type it claims to.
	const seen = new Set();
	for (const op of plan.ops) {
		const equals = op.expect && (op.expect.everyRowEquals || op.expect.firstRowEquals);
		if (!equals) continue;
		if (equals.transaction_type) seen.add(equals.transaction_type);
	}
	// Two operations write a *pair* of rows from one call, and `firstRowEquals` can only name the
	// first of each. A transfer books transfer_from and transfer_to; editing a stock entry
	// books stock-edit-old and stock-edit-new (services/StockService.php:756,797). Pairing
	// them here rather than loosening rowEquals keeps the assertion on the call specific.
	if (seen.has('transfer_from')) seen.add('transfer_to');
	if (seen.has('stock-edit-old')) seen.add('stock-edit-new');
	const missing = ALL_TRANSACTION_TYPES.filter((t) => !seen.has(t));

	// 5. The shadow ledger agrees with itself.
	for (const p of plan.ledger.problems) problems.push(`ledger: ${p}`);

	return {
		ok: problems.length === 0,
		problems,
		transactionTypes: { seen: [...seen].sort(), missing }
	};
}

module.exports = { validate, ALL_TRANSACTION_TYPES };
