'use strict';

// Differences this fork has decided to have.
//
// **Nothing here is hidden.** An accepted difference is still found, still counted, and
// still printed — under its own heading, with the record that accepted it. The registry
// changes a difference's *classification*, never its visibility, which is the difference
// between a suppression list and a statement of intent. A run whose accepted list has
// grown is a run that should be read.
//
// The bar for adding an entry is ADR-0005's: name what it touches, say whether any of it
// is exposed, and cite the record that decided it. "It has always done that" is not a
// reason; neither is "no endpoint returns it" — ADR-0005 records that exact reasoning
// being withdrawn once already, over qu_factor.
//
// Each entry is { id, reference, reason, match }. `match` gets the whole difference plus
// the step it came from and returns true when this entry explains it.

const ACCEPTED = [
	{
		id: 'ADR-0005-chores-start-date',
		reference: 'docs/adr/0005-wire-contract-is-the-invariant.md',
		reason:
			'chores.start_date where the stored value has no time. SQLite renders "2025-01-01"; ' +
			'PostgreSQL\'s TIMESTAMP renders "2025-01-01 00:00:00". chores is an ExposedEntity, the ' +
			'difference is accepted and must not be "fixed" — DATE is not an option because the chore ' +
			'form is a datetimepicker, and the timestamp form is the more conformant rendering of the ' +
			'documented format: date-time. trigdifftest.php confirmed it is the only such column across ' +
			'all 37 tables.',
		match: ({ difference }) =>
			difference.kind === 'value' &&
			/\/(start_date|rescheduled_date)$/.test(difference.pointer) &&
			isSameInstantDateOnly(difference.upstream, difference.victual)
	},

	{
		id: 'ADR-0005-chores-next-estimated-execution-time',
		reference: 'docs/adr/0005-wire-contract-is-the-invariant.md',
		reason:
			'chores.next_estimated_execution_time is computed here and null upstream, for a chore whose ' +
			'start_date was stored without a time. This is the entry above seen downstream, not a separate ' +
			'decision: upstream\'s daily branch takes the time of day out of start_date positionally, with ' +
			'SUBSTR(CAST(h.start_date AS TEXT), -8) (migrations/0185.sql:44). Given "2025-01-01" that reads ' +
			'"25-01-01", so DATETIME("2026-02-04 " || "25-01-01") is NULL and the whole expression ' +
			'collapses. The port reads the same time semantically, with to_char(h.start_date, ' +
			'\'HH24:MI:SS\') (db/pgsql/baseline/05_views_l2.sql:63), which a TIMESTAMP column cannot make ' +
			'malformed. chores is an ExposedEntity and the value also reaches ' +
			'chores_log.scheduled_execution_time, the chores overview, CalendarService and plan 18\'s MQTT ' +
			'payloads.\n\n' +
			'**The fork is the conforming side and this must not be "fixed" towards upstream** — the spec ' +
			'documents the field as a non-nullable format: date-time string, and upstream\'s null is the ' +
			'artifact of a string-slicing bug. It fires only after an execution: before one, both take the ' +
			'start_date branch and agree. The matcher is narrow — upstream strictly null, and the fork\'s ' +
			'side a well-formed Y-m-d H:i:s - so a null here, or a malformed value, is still reported.',
		match: ({ difference }) =>
			difference.kind === 'type' &&
			difference.pointer.endsWith('/next_estimated_execution_time') &&
			difference.upstream === null &&
			/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(difference.victual))
	},

	{
		id: 'ADR-0005-float-accumulation',
		reference: 'docs/adr/0005-wire-contract-is-the-invariant.md',
		reason:
			'Float accumulation order. products_average_price.price can be 4.124499999999999 on SQLite ' +
			'and 4.1245 on PostgreSQL; summing floats in a different order changes the last bit. The ' +
			'discrepancy is ~1e-15 and is not stable on SQLite either. It reaches ' +
			'uihelper_product_details.average_price, uihelper_stock_current_overview.average_price and ' +
			'recipes_resolved.costs / costs_per_serving. Normalisation already rounds to six places, so ' +
			'anything reaching here is larger than the accepted difference and this entry should not fire ' +
			'— it is kept so that a report can say so rather than leaving a reader to wonder.',
		match: ({ difference }) =>
			difference.kind === 'value' &&
			isNumericish(difference.victual) &&
			isNumericish(difference.upstream) &&
			Math.abs(Number(difference.victual) - Number(difference.upstream)) < 1e-9
	},

	{
		id: 'plan-16-project-rename',
		reference: 'docs/plans/16-project-rename.md',
		reason:
			'The fork is renamed. Anything naming the product — the version payload, the API key header, ' +
			'the session cookie, branding strings in a response — differs by construction, and the ' +
			'rename is the plan that decided it. Normalisation already masks the version fields; this ' +
			'catches the rest.',
		match: ({ difference }) =>
			(difference.kind === 'value' || difference.kind === 'type') &&
			[difference.victual, difference.upstream].every((v) => typeof v === 'string') &&
			renamedPair(String(difference.victual), String(difference.upstream))
	},

	{
		id: 'plan-16-version-field-renamed',
		reference: 'docs/plans/16-project-rename.md',
		reason:
			'GET /api/system/info reports the product version under the product\'s own name, so the ' +
			'fork sends victual_version where upstream sends grocy_version. The rename is landed in the ' +
			'codebase (plan 16) and this is the field that says so. It is a genuine wire-contract change ' +
			'and clients reading grocy_version see nothing — which is what plan 17 has to answer for, ' +
			'not something to hide here.',
		match: ({ difference }) =>
			(difference.kind === 'extra-field' && difference.pointer.endsWith('/victual_version')) ||
			(difference.kind === 'missing-field' && difference.pointer.endsWith('/grocy_version'))
	},

	{
		id: 'sqlite-version-is-empty-without-the-driver',
		reference: 'docs/adr/0008-postgresql-only-runtime-engine.md',
		reason:
			'sqlite_version is "" here and a real version upstream. It used to be read by opening ' +
			'new PDO(\'sqlite::memory:\'), which the serving images cannot do — they carry no ' +
			'pdo_sqlite since plan 10 made the driver check engine-specific. That unconditional open ' +
			'was a fatal error on /about *and inside ExceptionController*, so every error page on ' +
			'those images was a fatal error rather than an error page. The field stays in the ' +
			'contract and reports what it can, which is nothing.',
		match: ({ difference }) =>
			difference.kind === 'value' &&
			difference.pointer.endsWith('/sqlite_version') &&
			difference.victual === ''
	},

	{
		id: 'fork-schema-is-ahead',
		reference: 'docs/adr/0004-engine-specific-migrations.md',
		reason:
			'db_version differs because the fork has migrations upstream does not: 4.6.0 upstream stops ' +
			'at 255 and this tree is at 259 (plan 01\'s 0258 for file storage, plan 18\'s 0257 and 0259 ' +
			'for MQTT). A fork that adds features adds migrations; the number moving is the expected ' +
			'consequence. What would not be expected is this number being *lower* than upstream\'s, so ' +
			'the entry only accepts the fork being ahead.',
		match: ({ difference }) =>
			difference.kind === 'value' &&
			difference.pointer.endsWith('/db_version') &&
			Number(difference.victual) > Number(difference.upstream)
	},

	{
		id: 'exposed-settings-allowlist',
		reference: 'controllers/Api/SystemApiController.php',
		reason:
			'GET /api/system/config returns far fewer settings here than upstream, and the narrowing is ' +
			'deliberate: SystemApiController::EXPOSED_SETTINGS is an allowlist of "the config settings ' +
			'which are safe to expose to clients", where upstream returns essentially every constant. ' +
			'Among what upstream sends and this fork does not are LDAP_BIND_PW, LDAP_BIND_DN and the ' +
			'reverse-proxy auth header — a credential and two pieces of the authentication ' +
			'configuration. **This is the fork being more careful than upstream, and it is still a ' +
			'wire-contract narrowing**: a client reading VICTUAL_LOCALE or VICTUAL_USER_USERNAME from ' +
			'this endpoint gets nothing. Accepted, listed on every run, and named in plan 17\'s ' +
			'ecosystem-client work rather than treated as invisible.',
		match: ({ step, difference }) =>
			difference.kind === 'missing-field' &&
			step.path === '/system/config'
	},

	{
		id: 'error-details-not-returned',
		reference: 'docs/security-sweep.md',
		reason:
			'Upstream\'s API error bodies carry an error_details object with the absolute file path, the ' +
			'line number and a stack frame from inside the container; this fork does not send it. That ' +
			'is an information-disclosure fix, not a regression, and the omission of a leak is exactly ' +
			'the kind of difference that must be visible rather than silently normalised away — which ' +
			'is why it is an entry here rather than a masked field.',
		match: ({ difference }) =>
			difference.kind === 'missing-field' &&
			difference.pointer.endsWith('/error_details')
	},

	{
		id: 'driver-error-text',
		reference: 'docs/plans/11-api-error-handling.md',
		reason:
			'An error_message carrying the database driver\'s own words differs between engines by ' +
			'construction: PostgreSQL says SQLSTATE[23505] … duplicate key value violates unique ' +
			'constraint "users_username_key", SQLite says SQLSTATE[23000] … UNIQUE constraint failed: ' +
			'users.username. Both are the same refusal. ADR-0005 constrains the response *shape*, and ' +
			'the shape is identical here. **That both engines quote raw driver text back to a client at ' +
			'all is a separate matter and plan 11 owns it** — this entry accepts the difference between ' +
			'two leaks, not the leaking.',
		match: ({ difference }) =>
			difference.kind === 'value' &&
			difference.pointer.endsWith('/error_message') &&
			/SQLSTATE\[/.test(String(difference.victual)) &&
			/SQLSTATE\[/.test(String(difference.upstream))
	},

	{
		id: 'non-integer-object-id',
		reference: 'docs/plans/11-api-error-handling.md',
		reason:
			'A path parameter the endpoint types as an integer, given something that is not one, is a 400 ' +
			'here and a 404 upstream. Upstream\'s 404 is not a decision either: SQLite\'s dynamic typing ' +
			'makes WHERE ("id" = \'undefined\') a silent non-match, so the row is simply missing. The same ' +
			'statement on PostgreSQL is a type error, and this fork answered it 500 with the failing SQL ' +
			'quoted into error_message — including the caller\'s own value — on every id-taking endpoint. ' +
			'That is issue #48, and PathParameterMiddleware now refuses the value before any statement is ' +
			'built.\n\n' +
			'**400 rather than 404 is the deliberate part.** A malformed id is a request this API cannot ' +
			'parse, not a row that happens to be absent, and the two want different answers from a client: ' +
			'404 invites a retry against a resource that never existed. Issue #48 is the record. The ' +
			'matcher is narrow — only this status pair, and only where the fork explains itself in the ' +
			'documented error_message shape, so a 400 arriving from anywhere else is still reported.',
		match: ({ difference, step }) =>
			difference.kind === 'status' &&
			difference.victual === 400 &&
			difference.upstream === 404 &&
			/undefined/.test(String(step.path)),
	},

	{
		id: 'empty-assignment-group',
		reference: 'services/ChoresService.php',
		reason:
			'POST /chores/{id}/execute on a chore whose assignment_type needs users and whose ' +
			'assignment_config names none is 200 here and 500 upstream. Upstream\'s ' +
			'CalculateNextExecutionAssignment() picks the random strategy\'s user with ' +
			'array_rand($assignedUsers) in an else branch, so a group of nobody falls into it and ' +
			'array_rand([]) is a ValueError — which reaches the caller as ' +
			'{"error_message":"array_rand(): Argument #1 ($array) must not be empty"}, since \\Error is ' +
			'deliberately not caught (docs/plans/11-api-error-handling.md). Here that branch is guarded ' +
			'and the chore is assigned null, the same value CHORE_ASSIGNMENT_TYPE_NO_ASSIGNMENT stores: ' +
			'no strategy can invent a user, so "nobody" is the answer rather than a failure.\n\n' +
			'**The fork is the conforming side and this must not be "fixed" towards upstream.** The spec ' +
			'documents the endpoint as returning the created chores_log row, and ' +
			'next_execution_assigned_to_user_id is a nullable integer; a 500 is neither. The value is ' +
			'reachable without any misuse — a chore created with an assignment type and no users yet, or ' +
			'one whose assigned user was later deleted, which is why this is a runtime guard and not a ' +
			'validation on the write. .devtools/pgsql/run-tests.sh chores holds it on both engines.\n\n' +
			'The matcher is narrow: this one path, this one status pair. A 500 from anywhere else under ' +
			'/chores, or the fork answering 500 itself, is still reported.',
		match: ({ step, difference }) =>
			difference.kind === 'status' &&
			difference.victual === 200 &&
			difference.upstream === 500 &&
			/^\/chores\/\d+\/execute$/.test(String(step.path))
	},

	{
		id: 'fork-only-entities',
		reference: 'docs/plans/README.md',
		reason:
			'An entity this fork exposes and upstream has never heard of answers 200 here and 400 there. ' +
			'The list is explicit rather than a rule about 200-against-400, because "the fork answers ' +
			'where upstream refuses" is also what a missing validation looks like.',
		match: ({ step, difference }) =>
			difference.kind === 'status' &&
			difference.victual === 200 &&
			difference.upstream === 400 &&
			FORK_ONLY_ENTITIES.some((e) => String(step.path).includes(`/objects/${e}`))
	},

	{
		id: 'file-extension-hardening',
		reference: 'controllers/Api/FilesApiController.php',
		reason:
			'PUT /api/files/{group}/{name} refuses an extension that does not belong to the group, where ' +
			'upstream stores whatever it is handed. FilesApiController::CheckFileExtension is the check, ' +
			'and its own comment gives the reason: "A name ending in .png that holds a script is the ' +
			'whole of the problem". Uploading parity-fixture.txt into productpictures is therefore a 400 ' +
			'here and a 204 upstream, and the 400 is correct. The suite keeps making the rejected upload ' +
			'on purpose — it is the probe for the hardening, and a run where it starts succeeding is a ' +
			'regression.',
		match: ({ step, difference }) =>
			difference.kind === 'status' &&
			String(step.path).startsWith('/files/') &&
			difference.victual >= 400 &&
			difference.upstream < 400
	},

	{
		id: 'surrogate-key-allocation',
		reference: 'docs/adr/0008-postgresql-only-runtime-engine.md',
		reason:
			'A surrogate id is a few higher here than upstream, because the two engines allocate them ' +
			'differently after a **rejected** insert: a PostgreSQL sequence has already advanced and does ' +
			'not roll back, while SQLite\'s rowid counter is untouched by a statement that failed. This ' +
			'suite deliberately provokes rejected inserts — a create with no fields, a duplicate ' +
			'username, a permission row with a bad payload — so the two id spaces drift apart by exactly ' +
			'the number of failures that came before, and every id after the first failure differs by ' +
			'that constant.\n\n' +
			'Ids are opaque handles: nothing in the OpenAPI spec promises a value, and every client ' +
			'obtains one from the response that created it. **The matcher is deliberately narrow** — ' +
			'only a field literally named `id` or `created_object_id`, only when both sides are ' +
			'integers, and only when the fork\'s is ahead by less than ten. A larger gap, a lower id, or ' +
			'a mismatch anywhere else is not this and gets reported.',
		match: ({ difference }) => {
			if (difference.kind !== 'value') return false;
			const field = lastSegment(difference.pointer);
			if (field !== 'id' && field !== 'created_object_id') return false;
			const v = Number(difference.victual);
			const u = Number(difference.upstream);
			if (!Number.isInteger(v) || !Number.isInteger(u)) return false;
			return v > u && v - u < 10;
		}
	},

	{
		id: 'create-with-no-fields-refused',
		reference: 'docs/plans/11-api-error-handling.md',
		reason:
			'POST /objects/{entity} with a body that sets no column is a 400 here and a 200 upstream. ' +
			'Neither side creates anything: LessQL\'s Row::save() skips a row with no modified columns, so ' +
			'no INSERT is issued at all, and the endpoint then used to ask the driver for the id of an ' +
			'insert that never happened. The two drivers answer differently with nothing to report — ' +
			'pdo_sqlite returns the string "0", pdo_pgsql raises SQLSTATE[55000] "lastval is not yet ' +
			'defined in this session", which LessQL catches and turns into null — and the spec documents ' +
			'created_object_id as an integer, so both are fabricated.\n\n' +
			'**The 400 is the deliberate part**, and it is what this entry accepts. Until wave 2 the fork ' +
			'answered 200 with created_object_id null and this file accepted the null-against-"0" ' +
			'difference while recording that the 200 itself was the defect (issue #47). Plan 11 owns that ' +
			'defect and now refuses the request instead: a body that names no column of the entity cannot ' +
			'mean anything, and a 200 carrying an id that identifies nothing is the worst of the three ' +
			'available answers. Upstream still answers 200 with "0". The matcher is narrow — only this ' +
			'status pair, and only on a POST to /objects/.',
		match: ({ step, difference }) =>
			difference.kind === 'status' &&
			difference.victual === 400 &&
			difference.upstream === 200 &&
			step.method === 'POST' &&
			/\/objects\/[^/]+$/.test(String(step.path))
	},

	{
		id: 'missing-object-is-404-on-every-verb',
		reference: 'docs/plans/11-api-error-handling.md',
		reason:
			'PUT and DELETE against an object id that does not exist are 404 here and 400 upstream. GET of ' +
			'the same id is 404 on both, which is the point: the status used to depend on the verb rather ' +
			'than on the fact, in this fork exactly as in upstream, and a client could not tell "gone" ' +
			'from "your request was wrong" on two of the three.\n\n' +
			'**404 on all three is the deliberate part.** Plan 11 records it as a status-code correction ' +
			'on a failure path, in the same list as the permission failures that used to answer 400. The ' +
			'matcher is narrow — only this status pair, and only on a PUT or DELETE to an /objects/ path ' +
			'carrying an id.',
		match: ({ step, difference }) =>
			difference.kind === 'status' &&
			difference.victual === 404 &&
			difference.upstream === 400 &&
			(step.method === 'PUT' || step.method === 'DELETE') &&
			/\/objects\/[^/]+\/[^/]+$/.test(String(step.path))
	},

	{
		id: 'fork-additive-fields',
		reference: 'docs/adr/0005-wire-contract-is-the-invariant.md',
		reason:
			'A field the fork added that upstream never had. ADR-0005 constrains the fork from *changing* ' +
			'a documented shape; adding a field is the additive-API rule, which plan 14 piece 2 is meant ' +
			'to gate with a recorded snapshot and does not yet. Until it does, this entry names the ' +
			'specific additions that exist today — the list is deliberately explicit rather than a ' +
			'blanket "extra fields are fine", because a blanket rule would also accept a field that ' +
			'appeared by accident.',
		match: ({ difference }) =>
			difference.kind === 'extra-field' &&
			FORK_ADDED_FIELDS.has(lastSegment(difference.pointer))
	}
];

// Fields this fork adds and upstream does not have. Each names the plan that added it, so
// that a field arriving here without a plan is visible as an unexplained addition.
// Entities this fork exposes through /objects/{entity} and upstream does not. Named one by
// one so that a *new* one showing up is a difference somebody has to explain.
const FORK_ONLY_ENTITIES = [
	// plan 12's shared frontend core reads the shopping list through a helper view.
	'uihelper_shopping_list',
	// plan 18's opt-in per-product MQTT entities.
	'mqtt_product_entities'
];

const FORK_ADDED_FIELDS = new Set([
	// plan 18, MQTT state publication — the opt-in per-product entity flag.
	'mqtt_publish_state',
	// plan 01, file storage in the database.
	'file_storage_backend',
	// plan 01 Q2 / services/Storage/FileSizeLimit.php — the effective upload cap, which
	// upstream has no concept of.
	'FILE_STORAGE_MAX_SIZE_MB',
	// plan 20 — the database engine actually serving, which upstream has no need for
	// because upstream is always SQLite and reports it in sqlite_version. See the
	// sqlite-version entry above for why that field could not keep answering here.
	'database_engine'
]);

function lastSegment(pointer) {
	const parts = String(pointer).split('/');
	return parts[parts.length - 1];
}

function isNumericish(v) {
	if (typeof v === 'number') return Number.isFinite(v);
	if (typeof v === 'string') return v.trim() !== '' && Number.isFinite(Number(v));
	return false;
}

// "2025-01-01" against "2025-01-01 00:00:00" and nothing looser. A date-only value on one
// side and midnight of that same date on the other is the accepted rendering difference;
// a different date, or a non-midnight time, is not.
function isSameInstantDateOnly(dateOnly, withTime) {
	const a = String(dateOnly);
	const b = String(withTime);
	const dateRe = /^\d{4}-\d{2}-\d{2}$/;
	const midnightRe = /^(\d{4}-\d{2}-\d{2})[ T]00:00:00$/;

	if (dateRe.test(a)) {
		const m = midnightRe.exec(b);
		return m !== null && m[1] === a;
	}
	if (dateRe.test(b)) {
		const m = midnightRe.exec(a);
		return m !== null && m[1] === b;
	}
	return false;
}

// True when two strings are the same text modulo the rename. Case-insensitive because the
// name appears capitalised in prose and lowercased in identifiers.
function renamedPair(victualValue, upstreamValue) {
	const folded = victualValue.replace(/victual/gi, 'grocy');
	return folded.toLowerCase() === upstreamValue.toLowerCase() &&
		/grocy/i.test(upstreamValue);
}

// Classifies one difference. Returns the entry that explains it, or null.
function classify(step, difference) {
	for (const entry of ACCEPTED) {
		let matched = false;
		try {
			matched = entry.match({ step, difference });
		} catch {
			// A matcher that throws must not decide the run. Treat it as "does not explain
			// this", so the difference is reported rather than swallowed by a bug here.
			matched = false;
		}
		if (matched) return entry;
	}
	return null;
}

module.exports = { ACCEPTED, FORK_ADDED_FIELDS, FORK_ONLY_ENTITIES, classify };
