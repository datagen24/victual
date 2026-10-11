# ADR-0046: Stock bookings accept an optional idempotency key

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer has not yet decided that the feature is needed, its
  retention window or its release.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-11, against `master` at `d4960138` and re-read at `3122b937`, which
  differs from it only in documentation. Design work only: it changes no code, reserves no
  migration number and amends no accepted record.
- **Referenced by:** [issue 775](https://github.com/datagen24/victual/issues/775) and
  [victual-kit PR 35](https://github.com/datagen24/victual-kit/pull/35) (client timeouts).
- **Relationship:** applies accepted [ADR-0010](0010-workload-standard.md)'s rule that every
  side effect is idempotent or deduplicated to the five stock bookings. Carries over accepted
  [ADR-0041](0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
  rule 1's identity scope (per user, never per API key) and its rejected option D. Keeps
  accepted [ADR-0036](0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)'s
  meaning of a transaction unchanged. Stores state in PostgreSQL, per accepted
  [ADR-0007](0007-auth-state-outlives-the-process.md). Changes the wire contract additively
  under [ADR-0005](0005-wire-contract-is-the-invariant.md).

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

### Related finding F4, decided elsewhere

Each booking route commits through `StockService`, then calls `StockTransactions()` to
return the rows. `StockTransactions()` calls `User::CheckPermission(STOCK_VIEW)`. A caller
holding the booking permission without `STOCK_VIEW` gets 403 for a booking that committed.
Every seeded role resolves to `STOCK_VIEW` (`db/pgsql/roles-seed.sql`: ADMIN and ADULT
through their parent grants, CHILD and GUEST directly), so only custom roles or direct grants
reach this case. Whether a booking should require `STOCK_VIEW`, or answer without the rows,
is a separate authorization decision. Decision 5 keeps today's ordering, so this record works
under either answer.

## Decision (proposed)

Each decision lists the options considered and a recommendation. The recommendations are
proposals for the maintainer and decide nothing until acceptance.

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
stays as it is. A UUID fits both.

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

The controller opens the transaction with `InRequestTransaction()` before calling
`StockService`. `DatabaseService::InTransaction()` joins an open transaction, so the booking
runs inside it. Inside the transaction, in order:

1. Reserve the key: read the key row `FOR UPDATE`, or insert it under a savepoint.
2. On a new key, call the `StockService` booking.
3. Write the transaction id into the key row.

The transaction then commits, and only after the commit does the controller call
`StockTransactions()` to build the response. The key row and the booking commit together or
not at all. The read stays outside the transaction so that a `STOCK_VIEW` refusal cannot roll
back a booking, which keeps finding F4's behaviour unchanged.

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

The response carries `Idempotent-Replayed: true`. A keyed request that books carries
`Idempotent-Replayed: false`, which tells the client the server honoured the key.

Another user presenting the same key value is in a separate key space. Their request books
normally, and nothing in the response reveals that the key exists for someone else.

### 8. Failures are not stored

A request that fails with a 4xx or 5xx before commit rolls back its key row with the booking.
A retry with the same key runs again. If the cause has gone away (stock was added, a location
was created), the retry books. The client sent the retry intending the booking, so this is
the expected outcome. Storing refusals would instead replay a stale refusal for the whole
retention window.

A 403 from finding F4 is not a failure in this sense: the booking committed, and so did the
key. A replay answers the same 403 while that finding stands.

### 9. Retention

| Option | Covers | Cost |
|---|---|---|
| 24 hours | Retries within a session, a browser reload, a scanner reconnecting. Matches label operations. | A phone that queues a write offline across a night or a weekend loses protection. |
| 7 days | The 24-hour cases, plus a phone away from home that retries when it reconnects days later. | More rows. At household scale this is hundreds of small rows a week. |

Recommendation: 7 days, as a constant the server publishes (decision 11). victual-kit does
not queue writes today, so the longer window matters only once a client retries after a long
offline period. The record leaves the choice to the maintainer.

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

Recommendation: option A, naming the feature and the retention in seconds, plus option D's
header as confirmation on every keyed response. Version checks against `victual_version` are
not recommended, because a named feature states the capability directly and a version number does not.

### 12. Nested callers never take a key

The key belongs to the HTTP request. `RecipesService`, `ChoresService`,
`ConsumptionRecipeService`, `ConsumptionEventService` and `DemoDataGeneratorService` call
`StockService` directly and take no booking key. Manual consumption keeps its `request_id`
and external events keep their path identity. Recipe consumption, chore execution, stock
entry edits, measurements, weighing and undo are outside this record.

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

## Acceptance prerequisites

1. The maintainer chooses the retention window (decision 9) and the discovery field
   (decision 11), and those choices are written into this record's Decision before acceptance.
2. The OpenAPI and CORS changes in the Consequences are reviewed as additive under ADR-0005.
3. victual-kit states how it will use the key: which bookings send one, how it generates it,
   and that it stops retrying at the published retention window.

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
  other.
- PHPUnit: after the retention window, the same key books again, demonstrating that expiry is
  real and that the published window is the limit.
- PHPUnit: undo the transaction, then replay; the answer shows the rows with `undone` set and
  stock is not rebooked.
- PHPUnit: a Grocycode consume, open and transfer through the by-barcode route, then a direct
  retry naming the same entry, replay; a retry naming another entry answers 409.
- PHPUnit: a failed booking (insufficient stock) with a key leaves no key row; after stock is
  added, the same key books.
- pgTAP: the table's unique constraint, the key and fingerprint checks, and the `expires_at`
  index, listed in `.devtools/pgtap/README.md`'s completeness list.
- Contract snapshot (`tests/Pgsql/ContractTest.php`) updated for the header parameter, the 409
  responses and the discovery field.
- A CORS preflight test, alongside the assertions in `tests/Pgsql/AuthStackTest.php` and
  `HttpBootTest.php`, showing `Idempotency-Key` allowed and `Idempotent-Replayed` exposed.
