# ADR-0046: Stock bookings accept an optional idempotency key

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer confirmed retention, discovery and collision handling on
  2026-10-11. Release scheduling remains open.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-11, against `master` at `d4960138` and re-read at `3122b937`, which
  differs from it only in documentation. Revised 2026-10-12, against `master` at `8fd76607`,
  to name the discovery fields and response headers, to record the client obligations and to
  apply the maintainer's transaction-id choices. Design work only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [issue 775](https://github.com/datagen24/victual/issues/775) and
  [victual-kit PR 35](https://github.com/datagen24/victual-kit/pull/35) (client timeouts).
- **Relationship:** applies accepted [ADR-0010](0010-workload-standard.md)'s rule that every
  side effect is idempotent or deduplicated to the five stock bookings. Carries over accepted
  [ADR-0041](0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
  rule 1's identity scope (per user, never per API key) and its rejected option D. Keeps
  accepted [ADR-0036](0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)'s
  meaning of a transaction unchanged. Stores state in PostgreSQL, per accepted
  [ADR-0007](0007-auth-state-outlives-the-process.md). Adds to the API additively; accepted
  [ADR-0024](0024-the-fork-writes-its-own-clients.md) decision 1 requires the OpenAPI
  specification, `victual.openapi.json`, to change in the same pull request.

## Context

### The unknown-outcome problem

A client that sends a stock booking and receives no response cannot tell whether the booking
happened. The connection can drop after PostgreSQL committed the booking and before the
response reached the client. A retry can then book a purchase or a consumption twice. Not
retrying can lose a booking the person believes was made.

The five bookings are purchase, consume, transfer, open and inventory, each with a
by-barcode wrapper. All ten routes are `StockApiController` methods: `AddProduct`,
`ConsumeProduct`, `TransferProduct`, `OpenProduct`, `InventoryProduct` and their
`...ByBarcode` counterparts. None of them reads a client-supplied key or transaction id.
`StockService` mints the transaction id with `uniqid()` when its by-reference
`$transactionId` argument is null.

`GET /api/stock/transactions/{transactionId}` returns a transaction's bookings. A client that
never saw the response does not know the id, so it cannot ask whether its request happened.

### What the client does today

[victual-kit PR 35](https://github.com/datagen24/victual-kit/pull/35) (open on 2026-10-11;
read from its description, its code not reviewed here) sets a 30-second timeout for writes.
It reports a timed-out booking as "outcome unknown", closes the phone form so it cannot be
resubmitted, refreshes stock, and never retries. The person checks the journal by hand. The
PR measured writes at 0.19 to 0.51 seconds on the development instance. Issue 775 expects
more timeouts on a slow path, such as a phone away from home or the planned Tailscale Funnel
behind Cloudflare.

### Existing idempotency in the tree

Three mechanisms exist, and none covers the bookings.

| Mechanism | Identity and scope | Storage and lifetime | Replay answer |
|---|---|---|---|
| Label operations, `LabelsApiController::Operate` with `IdempotencyService` | `Idempotency-Key` header, 8 to 128 characters of `[A-Za-z0-9._:-]`, scoped to (principal user, operation, key) | `label_idempotency_keys` (migration `0272`), 24 hours | The stored response body |
| Manual consumption, `ConsumptionRecipeService::Consume` | Optional body `request_id`, 1 to 128 characters of the same set, scoped to (user, `manual`, id) | A `consumption_events` row, permanent | The stored event with `replayed: true` |
| External consumption events, `ConsumptionEventService::Submit` | Path identity (user, `source_system`, `source_event_id`) | A `consumption_events` row, permanent, with durable failure states by design | The stored event |

The label mechanism is the closest model. `label_idempotency_keys` holds
`UNIQUE (principal_user_id, operation, idempotency_key)`, a 64-hex `request_fingerprint`, a
nullable `resource_kind` and an `INTEGER` `resource_id`, a `JSONB` `response` and
`expires_at`. `print_jobs.idempotency_key_id` references it.

`IdempotencyService::Begin` runs inside the caller's transaction. It deletes expired rows,
reads the key row with `SELECT ... FOR UPDATE`, and otherwise inserts it under a savepoint.
A racing insert blocks
behind the first transaction's uncommitted row. On SQLSTATE 23505 it rolls back to the
savepoint and reads the committed row. A different fingerprint answers 409
`idempotency_conflict`. A committed row with no resource answers 409
`idempotency_in_progress`. A failed operation rolls back with its key row, so failures are
not stored. The fingerprint is `CanonicalJson::Digest` of the route, its arguments and the
body.

Manual consumption showed why a key must be bound to its request. Before
[PR 767](https://github.com/datagen24/victual/pull/767) (merged 2026-10-11), a `request_id`
reused on a different recipe replayed the first recipe's event and booked nothing
([issue 761](https://github.com/datagen24/victual/issues/761)). The fix stores a payload hash
and answers 409 `request_id_conflict` on a mismatch. That path also checks the share right
(`Authorise()`) only on first execution, after the replay return, so a replay does not
re-prove a revoked share.

### Why the transaction id is not a client identity

Issue 775 asks whether a client-supplied `transaction_id` could replace a key. It cannot,
for these reasons:

- Supplying a transaction id does not deduplicate. `StockService` appends a booking to
  whatever id it receives; nested callers depend on that to group bookings. A retry with the
  same id books again and labels the duplicate with the original's id.
- `stock_log.transaction_id` is `TEXT` with no unique constraint and no index (added in
  migration `0095`; the baseline indexes in `db/pgsql/baseline/02_indexes.sql` do not cover
  it). Uniqueness cannot be added per row, because one transaction has many rows.
- Undo and `GET /api/stock/transactions/{transactionId}` select by the id across all users.
  A client-chosen id that equals another user's would merge two people's bookings under one
  undo.
- Nested callers share one id across several bookings: `RecipesService::ConsumeRecipe` across
  its consumption lines, `ConsumptionRecipeService::Consume` and
  `ConsumptionEventService` across an event's lines, and `DemoDataGeneratorService` across
  its seed purchases. ADR-0036 defines a transaction as "the bookings of one request", and
  undo treats it as one unit.
- `consumption_events` has unique partial indexes on `transaction_id` (migration `0305`) and
  on `linked_transaction_id` (migration `0306`). A client-chosen id could collide with or
  pre-empt an event's link.

### Partial protections that do not generalise

`StockService::InventoryProduct` refuses a target equal to the current stock amount. A blind
retry of a successful inventory booking therefore answers 400 and books nothing, unless
another booking moved the stock in between. The client cannot tell that 400 from a real
refusal. No other booking has a comparable guard: a retried open opens another unit, and a
retried transfer moves more stock.

### A committed booking can answer 403

This behaviour is related and is decided elsewhere. Each booking route commits through
`StockService`, then calls `StockTransactions()` to return the rows. `StockTransactions()` calls `User::CheckPermission(STOCK_VIEW)`. A caller
holding the booking permission without `STOCK_VIEW` gets 403 for a booking that committed.
Every seeded role resolves to `STOCK_VIEW` (`db/pgsql/roles-seed.sql`: ADMIN and ADULT
through their parent grants, CHILD and GUEST directly), so only custom roles or direct grants
reach this case. Whether a booking should require `STOCK_VIEW`, or answer without the rows,
is a separate authorization decision. Decision 5 keeps today's ordering, so this record works
under either answer.

## Decision (proposed)

The sections distinguish options from the maintainer's confirmed choices. The record
remains Proposed until its separate acceptance.

### 1. Transport: a request header

| Option | Effect |
|---|---|
| A. `Idempotency-Key` request header | Matches label operations. The body, and so each route's schema, is unchanged. |
| B. Optional `request_id` body field | Matches manual consumption. Changes five request schemas, and the field must be excluded from the fingerprint it would otherwise sit inside. |

Recommendation: option A. The key describes the request, not the booking, and a header keeps
it out of the body the fingerprint covers. The by-barcode wrappers already rewrite the parsed
body, so a body field would have to be handled before that rewrite. The cost is a CORS change
(Consequences) and a second key spelling in the API beside manual consumption's
`request_id`, which stays as it is because it names a permanent event.

The key grammar should be the label grammar, 8 to 128 characters of `[A-Za-z0-9._:-]`, so
that one validator serves both header-keyed mechanisms. Manual consumption's 1-to-128 grammar
stays as it is. A UUID fits both. The maintainer requires cryptographically random keys. A client
generates one key per intended booking before its first request and reuses that key
for every retry. UUIDv4 generated with a cryptographically secure random source is
suitable. Randomness makes accidental collisions negligible; the unique constraint
and fingerprint checks still enforce the contract.

### 2. Storage: a new table holding the transaction id

| Option | Effect |
|---|---|
| A. Generalise `label_idempotency_keys` | One table. A rename touches the `print_jobs.idempotency_key_id` foreign key's documentation, `DatabaseImporter`'s "kept" rationale, the `migratedifftest.php` inventory, `docs/data-model.md` and the label tests. `resource_id` must become `TEXT` for a `uniqid()` id. The "in progress" sentinel (`resource_id IS NULL`) survives the type change. |
| B. A new table with the same shape | Labels are untouched. The importer can classify it on its own terms. Two near-identical services exist unless their common part is extracted. |
| C. ADR-0041-style events | A permanent record with states. Bookings need no state machine and no reconciliation, so it adds rows and states with no consumer. |

Recommendation: option B, a table such as `stock_booking_idempotency_keys`. Its columns would
be the user, the operation, the key, the fingerprint, a nullable `TEXT` `transaction_id`, and
`expires_at`, with `UNIQUE (user_id, operation, idempotency_key)` and an index on
`expires_at`. No response body is stored; decision 7 says why. The names and the choice of
foreign key on the user are for the implementing change. Sharing the key-handling code with
`IdempotencyService` is an implementation choice this record does not require.

### 3. Scope: user, operation and key

A key is scoped to (authenticated user, operation, key). The user is the acting principal
(`VICTUAL_USER_ID`), never a request field and never the API key. ADR-0041 rejected key scope
(its option D) because rotating a key or using a second device would orphan every stored
identity; the same reasoning applies here.

The operation is one of `purchase`, `consume`, `transfer`, `open` and `inventory`. A
by-barcode wrapper uses its base operation, so a retry may switch between the direct and the
by-barcode route and still replay.

An alternative scope is (user, key) alone, which would turn a key reused on a different
operation into a 409. Recommendation: include the operation, as label operations do. A key
reused across operations is a client defect either way; with the operation in scope, each
operation runs once.

### 4. Request fingerprint

The fingerprint is `CanonicalJson::Digest` of three members:

- the operation name;
- the resolved product id, as an integer;
- the body exactly as the base handler reads it from `GetParsedAndFilteredRequestBody()`.

`ConsumeProductByBarcode`, `OpenProductByBarcode` and `TransferProductByBarcode` inject a
Grocycode's stock entry id into the parsed body as `stock_entry_id`. The fingerprint must be
computed in the base handler, after that injection. A Grocycode consume and a direct consume
naming the same product and entry then produce the same fingerprint. The barcode string is
not a member, so a retry through a different barcode for the same product replays.

If a barcode is reassigned to another product between the original and the retry, the
resolved product differs and the retry answers 409. That is a safe refusal, at the cost of a
manual check in a rare case.

The operation member is redundant under decision 3's scope. Keeping it makes the alternative
scope a change to the unique constraint alone.

### 5. Atomic persistence in the booking's transaction

The controller would open the transaction before calling `StockService`, which the booking
routes do not do today; each currently lets `StockService` open and commit its own.
`DatabaseService::InTransaction()` joins an open transaction, so the booking would run inside
the controller's. Inside that transaction, in order:

1. Reserve the key: read the key row `FOR UPDATE`, or insert it under a savepoint.
2. On a new key, call the `StockService` booking.
3. Write the transaction id into the key row.

The transaction then commits, and only after the commit does the controller call
`StockTransactions()` to build the response. The key row and the booking commit together or
not at all. The read stays outside the transaction so that a `STOCK_VIEW` refusal cannot roll
back a booking. A caller without `STOCK_VIEW` therefore still gets 403 for a committed
booking, as today.

The helper must not mark the database changed on its own account.
`BaseApiController::InRequestTransaction()` calls `DatabaseService::MarkDbChanged()` for
every non-GET request unless `$changesData` is `false`, which advances
`GET /api/system/db-changed-time` even for a replay that wrote nothing. The helper should
pass `false`, or call `DatabaseService::InTransaction()` directly. A booking still marks the
change itself: `StockService` writes through LessQL, whose write callback calls both
`MarkDbChanged` on the dialect and `MarkDataChanged()`. The key-table writes go through the
raw connection and mark nothing.

`MarkDbChanged()` does not set the flag the request-end MQTT and InfluxDB publication reads
(`HasDataChanged()`); only `MarkDataChanged()` does. A replay through `InRequestTransaction()`
would therefore move the changed time without republishing MQTT state.

Lock order is the key row, then the product's advisory lock taken inside `StockService`. No
other path takes the key table's locks, and nested callers take no key (decision 12), so the
order cannot invert.

A generic middleware is not recommended. Slim runs middleware before the controller's
`User::CheckPermission()`, so a middleware replay would answer before the booking permission
is checked. It would also have to own the transaction and know each route's fingerprint
rules. A controller helper modelled on `LabelsApiController::Operate` has the transaction,
the parsed body and the resolved product id in hand.

### 6. Concurrent duplicates block, then replay

Two requests with the same user, operation and key race on the unique index. The second
insert waits for the first transaction. If the first commits, the second gets SQLSTATE 23505,
rolls back to its savepoint, reads the committed row and replays. If the first rolls back,
the second's insert succeeds and it books. This is `IdempotencyService::Begin`'s behaviour.

The second request waits as long as the first runs. No request path sets `lock_timeout`;
the only `SET LOCAL lock_timeout` in `services/` is in `TimestampMigration`. A booking takes
well under a second in the victual-kit PR 35 measurements, so no timeout is recommended. If
the maintainer wants one, a `SET LOCAL lock_timeout` before the key insert would answer 409
`idempotency_in_progress` on expiry, and the client would retry later with the same key.

A committed row without a transaction id cannot occur under decision 5, because the key and
the booking commit together. The implementation should still answer 409
`idempotency_in_progress` for such a row and must not book.

### 7. Replay re-reads the transaction under current permissions

A replay books nothing and answers 200 with `StockTransactions(transaction_id)`, read at
replay time. Consequences of reading rather than storing the response:

- The booking permission is checked on every request, replay included, because
  `User::CheckPermission()` runs at the top of each controller method. A person who lost the
  permission gets 403. `LabelsApiController::Operate` does the same: it checks
  `MASTER_DATA_EDIT`, and the domain permission where a label kind applies, before any replay.
- `StockTransactions()` checks `STOCK_VIEW` and applies `FieldPolicy` redaction for the
  caller as they are now. A stored body would return prices the caller may no longer see.
- A booking undone since the original request shows its rows with `undone` set. The client
  learns that the booking happened and was reversed, and nothing is booked again.
- If no `stock_log` row with the transaction id remains, `StockTransactions()` answers 400
  "No transaction was found by the given transaction id", as it does today for an unknown id.
  Nothing is booked. The client must treat that answer as "booked once, rows no longer
  readable", never as a reason to retry with a new key.

`StockTransactions()` selects by `transaction_id` across all users, and `uniqid()` derives
the id from the clock in microseconds. It is not unique across processes or pods, so two
requests in the same microsecond can share an id. A booking response today would then show
the other request's rows too; a replay would add a second reader with the same exposure. The
maintainer selected both protections on 2026-10-11:

- The replay read filters `stock_log` by `user_id` equal to the acting user. The key is per
  user, so the user's own rows are the only ones a replay returns. This narrows the replay
  alone; the existing booking responses and undo read as they do today.
- `StockService` generates every new transaction id with `bin2hex(random_bytes(16))`, which
  manual consumption already uses for request ids. This fixes the collision for every
  reader, including undo. It changes the id's format; the "Transaction id format" section
  below records what that touches. Stored ids stay valid and readable.

The response carries `Idempotent-Replayed: true`. A keyed request that books carries
`Idempotent-Replayed: false`, which tells the client the server honoured the key. A request
without `Idempotency-Key` carries neither value. The 409 answers of decision 6 and the
refusals of decision 8 carry no `Idempotent-Replayed` header, because no booking happened and
none was replayed. The header name and its two values are the whole response-side contract;
the body is the ordinary booking response.

Another user presenting the same key value is in a separate key space. Their request books
normally, and nothing in the response reveals that the key exists for someone else.

### 8. Failures are not stored

A request that fails with a 4xx or 5xx before commit rolls back its key row with the booking.
A retry with the same key runs again. If the cause has gone away (stock was added, a location
was created), the retry books. The client sent the retry intending the booking, so this is
the expected outcome. Storing refusals would instead replay a stale refusal for the whole
retention window.

The 403 a caller without `STOCK_VIEW` gets after a committed booking is not a failure in
this sense: the booking committed, and so did the key. A replay answers the same 403 while
that behaviour stands.

### 9. Retention

| Option | Covers | Cost |
|---|---|---|
| 24 hours | Retries within a session, a browser reload, a scanner reconnecting. Matches label operations. | A phone that queues a write offline across a night or a weekend loses protection. |
| 7 days | The 24-hour cases, plus a phone away from home that retries when it reconnects days later. | More rows. At household scale this is hundreds of small rows a week. |

The maintainer chose 7 days on 2026-10-11, as a constant the server publishes
(decision 11). victual-kit does
not queue writes today, so the longer window matters only once a client retries after a long
offline period. The retention choice is recorded in the response below.

Expired rows are deleted lazily when a keyed request arrives, as `IdempotencyService::Begin`
does, and a lookup ignores an expired row. Without keyed traffic, expired rows remain until
the next keyed request; they affect nothing.

`IdempotencyService::Begin` deletes every expired row, which locks those rows until its
transaction ends. A second keyed request arriving meanwhile, from any user, can wait behind
the whole first booking. Deleting only the matching expired key, or a bounded batch, avoids
that wait; the implementing change chooses.

The client rule is part of the contract. A key older than the published window guarantees
nothing. A client that cannot complete a retry inside the window must treat the booking's
outcome as unknown, as victual-kit does today, and must not retry. An expired key is never a
safe retry.

### 10. Without a key, behaviour is unchanged

A request without the header behaves exactly as it does today, writes no key row and pays no
extra query. Every existing caller, including the Victual UI and the MCP sidecar, keeps
working unchanged.

### 11. Discovery

An older server ignores an unknown header and books on every retry. A client must therefore
know that the server honours keys before it relies on one, and the timed-out request is the
one that cannot tell it.

| Option | Effect |
|---|---|
| A. A field in `GET /api/system/info` (`ApplicationService::GetSystemInfo`) | Server-wide, readable once per session. Additive. |
| B. A field in `GET /api/user/capabilities` (`UsersApiController::CurrentUserCapabilities`) | Already read by the MCP sidecar. Describes the acting credential, which this feature is not. |
| C. A feature list like `ConsumptionEventsApiController::FEATURES` with `contract_version` | A precedent for named features, but scoped to consumption routes. |
| D. The response header alone | Arrives only on a successful response, so a client learns it after the first keyed write and must remember it per server. |

The maintainer selected option A on 2026-10-11: `GET /api/system/info` names the feature
and its retention in seconds. Option D's header confirms every keyed response. Version checks
against `victual_version` are not recommended, because a named feature states the capability
directly and a version number does not.

The existing response is a flat object of snake_case keys: `victual_version` (an object with
`Version` and `ReleaseDate`), `php_version`, `sqlite_version`, `database_engine`,
`db_version`, `os` and `client`. `ApplicationService::GetSystemInfo` builds it, the About
page reads it, and `tests/Pgsql/snapshots/contract-admin.json` records its shape. It carries
no capability fields today. The only published capability precedent is
`GET /api/consumption/capabilities`, which answers `contract_version` and a `features`
array of names. `GET /api/user/capabilities` describes the acting credential and answers a
different question.

**Contract fields (proposed).** `GET /api/system/info` gains one top-level key,
`stock_booking_idempotency`, whose value is an object:

| Field | Type | Meaning |
|---|---|---|
| `header` | string | The request header that carries the key. Always `Idempotency-Key`. |
| `retention_seconds` | integer | How long after the original booking the server replays a key. 604800 (seven days). |
| `operations` | array of strings | The operations a key protects: `purchase`, `consume`, `transfer`, `open`, `inventory`. |

The key is absent on a server that does not implement the feature. A client must treat an
absent `stock_booking_idempotency` as "keys are ignored" and must not retry a booking whose
outcome is unknown. A client must read `retention_seconds` from the response and must not
hard-code seven days, because the maintainer may change the constant. An example fragment:

```json
{
  "victual_version": { "Version": "0.1.0", "ReleaseDate": "2026-10-12" },
  "database_engine": "PostgreSQL 16.10",
  "db_version": 307,
  "stock_booking_idempotency": {
    "header": "Idempotency-Key",
    "retention_seconds": 604800,
    "operations": ["purchase", "consume", "transfer", "open", "inventory"]
  }
}
```

The values come from server constants, not from a setting. The same constant sets each key
row's `expires_at` and the published `retention_seconds`, so the two cannot drift.
`victual.openapi.json` documents the object in the `/system/info` schema, and the contract
snapshot adds `stock_booking_idempotency` with its three members.

**Unit.** `retention_seconds` is recommended over a day count. The server's expiry is a
duration added to a timestamp, so the published value is exact. A client timer and
PostgreSQL `interval` arithmetic both work in seconds. A day count would force a client to
guess whether a day means 24 hours or a calendar day across a clock change. Seconds also let
the maintainer choose a window that is not a whole number of days without a field rename.

**Headers.** `Idempotency-Key` is the request header, matching label operations.
`Idempotent-Replayed` is `true` when the response is a replay and `false` when a keyed
request booked (decision 7). Open question 5 asks whether the capability should sit under a
shared `capabilities` container instead of at the top level.

### 12. Nested callers never take a key

The key belongs to the HTTP request. `RecipesService`, `ChoresService`,
`ConsumptionRecipeService`, `ConsumptionEventService` and `DemoDataGeneratorService` call
`StockService` directly and take no booking key. Manual consumption keeps its `request_id`
and external events keep their path identity. Recipe consumption, chore execution, stock
entry edits, measurements, weighing and undo are outside this record.

## Client obligations

These requirements bind any client of the API, including the Victual UI, victual-kit, the
MCP sidecar and third-party scripts. Each row names the server behaviour that makes the
obligation sound and the test that establishes that server half (Verification lists the
tests in full). A client's own tests establish its half. This record does not require
evidence from one client alone.

| # | A client must | Server half and its test |
|---|---|---|
| 1 | Read `stock_booking_idempotency` from `GET /api/system/info` once per session, and send `Idempotency-Key` only to a server that publishes it. | The object is present with `header`, `retention_seconds` and `operations` (contract snapshot test). A server without it ignores the header, as it does today. |
| 2 | Generate one key per intended booking before the first attempt, from a cryptographically secure random source such as UUIDv4, 8 to 128 characters of `[A-Za-z0-9._:-]`. | A malformed key is refused before any booking (grammar test). Uniqueness rests on the unique constraint, not on the client's randomness (two-user and subprocess race tests). |
| 3 | Resend a byte-identical body, the same route (or the by-barcode route naming the same product and entry) and the same key on every retry. | A retry replays with `Idempotent-Replayed: true` and books once (per-operation replay test). Different contents answer 409 `idempotency_conflict` (conflict test). |
| 4 | Never reuse a key for a different booking. | A reused key with a different body, product or entry answers 409 and books nothing (conflict test). Another user's identical key is a separate key space (two-user test). |
| 5 | Stop automatic retries once `retention_seconds` has passed since it sent the first attempt, counting from that send and not from the last failure. | After expiry the same key books again (retention-boundary test), which is why the client must stop. The server never extends a key on replay. |
| 6 | Treat the outcome as unknown when the key has expired, the server does not publish the capability, or no response arrived inside the window. Resolve it by reading stock or the journal, never by sending a new key. | A new key books again by design, and no server answer can reveal an earlier attempt (retention-boundary test). |
| 7 | Treat 409 `idempotency_conflict` as a client defect: stop, do not resend, and do not assume either request booked. | Nothing is booked on 409 (conflict test). |
| 8 | Treat 409 `idempotency_in_progress` as "wait": retry later with the same key and body, never with a new key. | The subprocess race test and the in-progress test, which covers a committed key row with no transaction id. |
| 9 | Read `Idempotent-Replayed`. `true` means the response repeats an earlier booking and is not a second one. `false` means the server honoured the key. Absence on a keyed request means the server ignored the key. | Replay, first-booking and unkeyed tests assert `true`, `false` and absence. |
| 10 | Treat a replay's rows as current state. `undone` may be set, redaction follows current permissions, and a 400 "No transaction was found" means "booked once, rows unreadable", never "retry". | The undo-then-replay test and the deleted-rows test. |
| 11 | Treat a 403 on a keyed booking as possibly committed, and resend with the same key and body. | The `STOCK_VIEW` test: a committed booking whose re-read is refused replays the same 403 and books once. |

A client that follows these rules does not create a second booking by retrying, and it never
mistakes an unknown outcome for a known one.

## Options considered

- **Do nothing.** victual-kit keeps reporting "outcome unknown". Safe, and leaves a manual
  check after every timeout.
- **A client-supplied transaction id.** Rejected for the reasons in the Context: it does not
  deduplicate, and the id's global scope and nested sharing make a client-chosen value
  unsafe.
- **A generic middleware.** Rejected in decision 5: it would replay before the permission
  check and would need per-route knowledge.
- **ADR-0041-style events for bookings.** Rejected in decision 2: permanent rows and states
  with no consumer.
- **Key scope per API key.** Rejected in decision 3, as ADR-0041 option D was.

## Consequences

- `CorsMiddleware` allows only `Content-Type` and the API key header in
  `Access-Control-Allow-Headers`. A browser client on another origin needs `Idempotency-Key`
  added there, and `Idempotent-Replayed` added to `Access-Control-Expose-Headers`, which today
  lists only the label revival header. Native clients are not subject to CORS.
- `victual.openapi.json` gains an optional `Idempotency-Key` header parameter on the ten
  routes, a 409 response with `idempotency_conflict` and `idempotency_in_progress`, and the
  `Idempotent-Replayed` response header. The label routes read the same header and the
  specification documents it nowhere (no `Idempotency-Key` occurrence on 2026-10-11); that
  gap is separate work.
- The booking routes answer errors through `HandleApiCall`, which maps an unrecognised
  exception to 400. The 409 answers need a typed exception the booking controller catches, as
  `LabelsApiController::Operate` catches `LabelValidationException`. The 409 body carries a
  `code` field, which existing booking errors do not.
- A replay reads `stock_log` by `transaction_id`, which has no index. The migration should add
  a non-unique index on `stock_log.transaction_id`; undo and
  `GET /api/stock/transactions/{transactionId}` read by the same column and benefit too.
- `DatabaseImporter` must classify the new table. It belongs in `DERIVED_STATE_TABLES` beside
  `consumption_events`: an import replaces `stock_log`, so a surviving key would name a
  transaction id that no longer exists. `.devtools/pgsql/migratedifftest.php` must list it as
  PostgreSQL-only, and `docs/data-model.md` must describe it.
- A migration number is claimed in `migrations/RESERVATIONS.md` only when the implementing
  change is written. This record reserves none.
- Each keyed booking costs one key-row insert and one update in its transaction. A replay
  costs one indexed read and the transaction re-read.
- The response to a replay can differ from the original response: `undone` may be set, and
  redaction follows current permissions. A client compares by transaction id, not by bytes.

### Transaction id format

New transaction ids change from `uniqid()` output, 13 hexadecimal characters derived from
the clock, to `bin2hex(random_bytes(16))`, 32 hexadecimal characters. Checked on `master` at
`8fd76607`:

- `uniqid()` mints a transaction id at five `StockService` sites (`AddProduct`,
  `ConsumeProduct`, `EditStockEntry`, `MeasureStockEntry` and `OpenProduct`) and at
  `ChoresService::TrackChore`, which passes its id into `ConsumeProduct`. Transfer and
  inventory reach the column through those methods. The implementing change should replace
  the `ChoresService` site too, or chore consumption keeps the old format. `stock_id` and
  `correlation_id` also use `uniqid()` and are outside this record.
- `stock_log.transaction_id` is `TEXT` with no length constraint (migration `0095`, baseline
  `01_tables.sql`). `consumption_events.transaction_id` and `linked_transaction_id` are
  `TEXT`, as is `chores_log.stock_transaction_id` (migration `0291`).
- `victual.openapi.json` types `transaction_id` as `string` or `null` with no `format`,
  `pattern` or `maxLength`. A search of the PHP, JavaScript and SQL sources for length,
  pattern and substring operations on a transaction id found none, and no test or fixture
  asserts the id's length.
- The Victual UI places the id in a URL path segment and in a single-quoted JavaScript string
  (`consume.js`). Lowercase hexadecimal is safe in both, as 13-character ids are today.
- `ConsumptionEventService` orders duplicate candidates by `at, transaction_id`. The id is
  only the tie-break after `at`, so random ids change which of two same-instant candidates
  lists first and nothing else. No query relies on a transaction id sorting by time.
- `.devtools/parity/harness/lib/normalize.js` masks `transaction_id` as an opaque handle, so
  the parity suite is unaffected. Test code that passes its own id, such as the `a40-`
  prefix in `.devtools/adr0040/race-worker.php`, is unaffected because a supplied id is not
  regenerated.
- Existing ids remain valid. Undo, `GET /api/stock/transactions/{transactionId}` and replay
  select by exact string match, so 13- and 32-character ids coexist.

No documented contract fixes the id's format, so the change is additive for readers. A
client that stored the old length in a column or validation pattern would fail. The
implementing pull request should document the id as an opaque string. ADR-0024 requires
coordination with a maintained client affected by a breaking change; victual-kit should
confirm that it treats the id as opaque before release.

## Open questions and maintainer responses

1. **How long does a key protect a booking from duplicate retries?**

   > **Response:** datagen24, 2026-10-11: Seven days works. Generate the key using
   > cryptographically secure randomness so accidental collisions are negligible.

   The client reuses the original key for retries. After seven days it must stop
   automatic retries and treat an unresolved outcome as unknown. Randomness does not
   extend retention or replace server-side uniqueness and fingerprint checks.

2. **How does a client discover support and retention?** Decision 11 recommends a
   field in `GET /api/system/info` and confirmation headers. This choice is confirmed below.

   > **Response:** datagen24, 2026-10-11: Use `GET /api/system/info` for discovery.
3. **How are transaction-id collisions handled?** Decision 7 lists filtering replay
   rows by user, replacing `uniqid()` with a higher-entropy identifier, or both.
   Random request keys do not change transaction-id generation.

   > **Response:** datagen24, 2026-10-11: Use both protections: filter replays by the
   > acting user and generate transaction ids with cryptographically secure randomness.

4. **Must acceptance depend on a statement from victual-kit?**

   > **Response:** datagen24, 2026-10-11: Define the API contract for any client.
   > victual-kit is one UI and happens to be the first. Require a documented and tested
   > contract, not evidence from that client alone.

   Every client must discover support and retention, generate one random key per intended
   booking, reuse it with unchanged request contents, stop automatic retries after seven
   days, and handle conflicts or unknown outcomes without silently creating another booking.
   ADR-0024 still requires coordination when an existing maintained client is affected
   by a breaking change.

5. **Where does the capability sit in `GET /api/system/info`?** The response has no
   capability fields, so the shape is a new choice.

   | Option | Effect |
   |---|---|
   | A. A top-level `stock_booking_idempotency` object | One additive key, named for the feature, with no nesting. A later feature adds its own key. |
   | B. A `capabilities` object keyed by feature, holding `stock_booking_idempotency` | Groups future capabilities and separates them from the environment fields. The name resembles `GET /api/user/capabilities` and `GET /api/consumption/capabilities`, which answer different questions. |

   Recommendation: option A. It adds one key without inventing a container for features that
   do not exist yet, and it avoids a third meaning of "capabilities" in the API. If the
   maintainer prefers option B, only the JSON path changes; the three members, the unit and
   the headers stay as recorded. This record uses option A until the maintainer answers.

## Acceptance prerequisites

1. Seven-day retention and discovery through `GET /api/system/info` are confirmed.
   Decision 11 names the capability (`stock_booking_idempotency`), the retention field
   (`retention_seconds`, in seconds) and decision 7 names the response header
   (`Idempotent-Replayed`, `true` or `false`). Open question 5 asks the maintainer to
   confirm the JSON path.
2. The OpenAPI and CORS changes in the Consequences are reviewed as additive, with
   `victual.openapi.json` updated in the implementing pull request as ADR-0024 decision 1
   requires.
3. The API documents and tests the obligations in the Client obligations section, including
   unchanged retry contents and the retention boundary. Verification lists the server-side
   test for each obligation's server half. Integration evidence from victual-kit is useful
   but is not the only way to meet this gate.

## Verification

Acceptance of an implementation should be shown by these checks, run against PostgreSQL as
the suite runs (`.devtools/pgsql/run-tests.sh`):

- PHPUnit: a keyed purchase, consume, transfer, open and inventory each replay with the same
  key, book exactly once, and answer `Idempotent-Replayed: true` with the original
  transaction's rows.
- PHPUnit: the same key with a different body, product or `stock_entry_id` answers 409
  `idempotency_conflict` and books nothing.
- PHPUnit with a subprocess race, following `tests/Pgsql/label-idempotency-subprocess-helper.php`
  and `ConsumptionEventRaceTest`: two processes send the same key at once; stock changes once
  and both answer the same transaction id.
- PHPUnit: a user loses the booking permission, then replays; the answer is 403 and nothing is
  booked. The same with `STOCK_VIEW` removed answers 403 from the re-read.
- PHPUnit: two users send the same key value; both book, and neither response mentions the
  other. A fixture with a legacy transaction-id collision proves replay returns only
  the acting user's rows. New transactions use cryptographically random ids, and
  existing transaction ids remain readable and undoable.
- PHPUnit: after the retention window, the same key books again, demonstrating that expiry is
  real and that the published window is the limit. A companion case just inside the window
  replays.
- PHPUnit: a malformed key (fewer than 8 or more than 128 characters, or a character outside
  `[A-Za-z0-9._:-]`) is refused before any booking. A request without the header books as
  today and carries no `Idempotent-Replayed` header.
- PHPUnit: a committed key row with no transaction id answers 409 `idempotency_in_progress`
  and books nothing.
- PHPUnit: delete the transaction's `stock_log` rows, then replay; the answer is 400 and
  nothing is booked.
- PHPUnit: a keyed booking that committed but whose re-read answers 403 (booking permission
  without `STOCK_VIEW`) replays the same 403 and books once.
- PHPUnit: a keyed first booking carries `Idempotent-Replayed: false`, and a 409 carries no
  `Idempotent-Replayed` header.
- PHPUnit: new transaction ids are 32 lowercase hexadecimal characters, and a pre-existing
  13-character id remains readable and undoable.
- PHPUnit: undo the transaction, then replay; the answer shows the rows with `undone` set and
  stock is not rebooked.
- PHPUnit: a Grocycode consume, open and transfer through the by-barcode route, then a direct
  retry naming the same entry, replay; a retry naming another entry answers 409.
- PHPUnit: a failed booking (insufficient stock) with a key leaves no key row; after stock is
  added, the same key books.
- pgTAP: the table's unique constraint, the key and fingerprint checks, and the `expires_at`
  index, listed in `.devtools/pgtap/README.md`'s completeness list.
- Contract snapshot (`tests/Pgsql/ContractTest.php`) updated for the header parameter, the 409
  responses and the discovery object `stock_booking_idempotency` with `header`,
  `retention_seconds` and `operations`. A PHPUnit check asserts that `retention_seconds`
  equals the constant that sets `expires_at`.
- A CORS preflight test, alongside the assertions in `tests/Pgsql/AuthStackTest.php` and
  `HttpBootTest.php`, showing `Idempotency-Key` allowed and `Idempotent-Replayed` exposed.
