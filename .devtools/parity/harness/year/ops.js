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

function bookingRows({ transactionType, length = 1, extra = {} }) {
	return {
		status: 200,
		kind: 'array',
		minLength: length,
		rowShape: ['id', 'product_id', 'amount', 'stock_id', 'transaction_id', 'transaction_type'],
		rowEquals: { transaction_type: transactionType, ...extra }
	};
}

const OK_ARRAY = { status: 200, kind: 'array' };
const OK_OBJECT = { status: 200, kind: 'object' };

module.exports = { call, arrange, auth, mark, CREATED, bookingRows, OK_ARRAY, OK_OBJECT };
