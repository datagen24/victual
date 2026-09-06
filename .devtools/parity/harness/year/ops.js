'use strict';

// How one operation in the plan is written down.
//
// The shape is deliberately small — `call`, `arrange`, `auth`, `mark` — because replay.js
// has to be able to execute it without ever inspecting a response *value*. It reads exactly
// one thing out of a response, the id named by `bind`, into an opaque slot it never tests.
// That is what makes trace-length equality structural rather than a discipline the emitters
// have to keep.
//
// **`expect` is not optional and is not decoration.** Instance.api() records an HTTP failure
// without throwing, silently() suppresses recording rather than errors, and diffStep finds
// no difference between two matching 400s — so both instances could reject every purchase
// in the year and the suite would report parity over an empty database. Every write says
// what success looks like, and replay.js terminates the run as INCOMPLETE when it does not
// get it.

// A recorded call: it is compared between instances, and it counts as a step.
function call({ method, path, body, expect, window, bind, ledger, label }) {
	if (!expect || expect.status === undefined) {
		throw new Error(`operation "${label}" has no expected status — see ops.js`);
	}
	return { op: 'call', method, path, body, expect, window, bind, ledger, label };
}

// An arranged call: executed and checked, but not recorded, so the trace stays a statement
// of what the year asserts rather than of what it needed in place first. Still carries
// `expect`, because "not compared" must not mean "not checked".
function arrange({ method, path, body, expect, bind, label }) {
	if (!expect || expect.status === undefined) {
		throw new Error(`arrangement "${label}" has no expected status — see ops.js`);
	}
	return { op: 'arrange', method, path, body, expect, bind, label };
}

// Switches the session. Chore executions and bookings attributed to different users are how
// stock_log.user_id and the chore assignment rotation get more than one value in a year.
function auth({ user, label }) {
	return { op: 'auth', user, label };
}

// A zero-call annotation, so the report can say "month 7, week 29" rather than "step 2841".
// It issues no request and so cannot affect the trace.
function mark({ note, day }) {
	return { op: 'mark', note, day };
}

// The two response shapes the application actually uses, said once here so no emitter has
// to remember which is which.
//
// Generic CRUD creates answer with a `created_object_id` envelope. **Stock bookings do
// not**: POST /stock/products/{id}/add returns the stock_log rows of the resulting
// transaction (controllers/Api/StockApiController.php:104), and an operation expecting the
// envelope terminates as INCOMPLETE on its first call.
const CREATED = { status: 200, shape: ['created_object_id'] };

// `rowsSum` is the signed total the booking is *intended* to move, and it is the cheapest
// per-step assertion there is: booking rows carry a signed `amount` (a consume answers -1),
// so the response alone says whether the operation moved what the plan meant it to. Without
// it a wrong amount in March is only caught by an aggregate in December, which reports
// "milk disagrees" and names none of the 1,040 consumes that could have done it.
// `mixedTypes` is for the calls that answer rows of more than one transaction type from a
// single booking: a transfer writes `transfer_from` *and* `transfer_to`, an edit writes
// `stock-edit-old` and `stock-edit-new`. Those can only be checked on the first row. Every
// other booking answers rows that are all the same type, and saying so is stronger — a
// consume that quietly returned one consume row and one of something else used to pass.
function bookingRows({ transactionType, length = 1, rowsSum, extra = {}, mixedTypes = false }) {
	const equals = { transaction_type: transactionType, ...extra };
	const expect = {
		status: 200,
		kind: 'array',
		minLength: length,
		rowShape: ['id', 'product_id', 'amount', 'stock_id', 'transaction_id', 'transaction_type'],
		...(mixedTypes ? { firstRowEquals: equals } : { everyRowEquals: equals })
	};
	if (rowsSum !== undefined) expect.rowsSum = rowsSum;
	return expect;
}

// Read one product's stock and assert it is what the shadow ledger says it should be after
// the operation that just ran.
//
// This is the other half of "what did we intend": `rowsSum` says the booking moved the right
// amount, and this says the resulting *state* is the right one. It costs a request, so it is
// emitted after every operation whose effect is not a plain add or subtract, and at a
// sampled cadence otherwise (see plan.js) — enough to bound how far a divergence can travel
// before something names the product, the day and the operation that caused it.
function verifyStock({ productKey, amount, opened, label, window }) {
	const equals = { stock_amount: amount };
	if (opened !== undefined) equals.stock_amount_opened = opened;
	return call({
		method: 'GET',
		path: `/stock/products/{product:${productKey}}`,
		expect: { status: 200, kind: 'object', equals },
		window,
		label
	});
}

const OK_ARRAY = { status: 200, kind: 'array' };
const OK_OBJECT = { status: 200, kind: 'object' };

// Reads a product's remaining lots and asserts they are exactly the ones the ledger holds.
//
// The fields are chosen for what a wrong lot changes: `amount` and `location_id` a wrong
// *split* would change, and `best_before_date`, `purchased_date` and `price` are what a
// wrong *selection* leaves behind while every total stays correct.
const LOT_FIELDS = ['amount', 'best_before_date', 'purchased_date', 'price', 'open', 'location_id'];

// `exact` are lots whose every field is determined. `groups` are sets of lots the
// application's own ordering cannot tell apart, where the split between them is arbitrary —
// so the group is constrained by everything the tie does not touch (its total, the locations
// it may occupy, the prices it may carry) and by nothing it does.
//
// **Relaxing the selected lot is not relaxing the assertion.** A tie leaves the amount
// removed, the product total, the group's total and the set of valid source lots all exactly
// constrained; only which member of the group shrank is open. Skipping the whole check —
// which an earlier version did — gave all of that up to accommodate one unknown.
function verifyLots({ productKey, exact, groups, label, window }) {
	return call({
		method: 'GET',
		path: `/stock/products/{product:${productKey}}/entries`,
		expect: {
			status: 200,
			kind: 'array',
			lots: {
				fields: LOT_FIELDS,
				exact: exact.map((e) => LOT_FIELDS.map((f) => e[f])),
				groups
			}
		},
		window,
		label
	});
}

module.exports = { call, arrange, auth, mark, CREATED, bookingRows, verifyStock, verifyLots, LOT_FIELDS, OK_ARRAY, OK_OBJECT };
