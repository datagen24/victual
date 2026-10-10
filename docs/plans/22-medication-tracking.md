# 22. Medication inventory and private consumption recipes

**Goal:** Track the medication, vitamin and supplement quantities on hand, record their
consumption and identify when to request a refill. A prescription is a reusable,
user-entered consumption recipe with access restricted to its owner and specifically
authorized members. Dosing schedules, adherence tracking and dose reminders are outside
Victual's scope.

**Status:** in progress for v0.5.0; product scope decided and design records accepted
2026-10-09. Implemented: private consumption recipes and manual consumption (issue 698, merged)
and the external-event schema, service, API and reconciliation inbox (issue 700, in review).
Not implemented: refill tracking (issue 701) and native acceptance on a device (issue 702).

**Release target:** v0.5.0. The maintainer clarified this scope on 2026-10-09. The target
is a schedule; the release record and signed tag follow verified implementation under
[the release procedure](../releases/README.md).

**Dependencies:** [14](landed/14-contract-and-regression-scaffolding.md),
[19](19-rbac.md), [23](landed/23-storage-classes.md), and the existing label subsystem
([25](25-label-infrastructure.md), [27](landed/27-label-templates-and-rendering.md),
[32](landed/32-label-kinds.md)). These capabilities are implemented. Apple Health access
belongs to the native clients in `victual-kit`; Victual owns the receiving API.

**Decisions in force:** [ADR-0011](../adr/0011-label-namespace.md) for labels,
[ADR-0014](../adr/0014-administering-a-user-is-a-subset-question.md) and
[ADR-0018](../adr/0018-role-grants-and-domain-reads.md) for authorization,
[ADR-0032](../adr/0032-stock-amounts-compare-within-one-tolerance.md) for quantities,
and [ADR-0036](../adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)
for booking lineage. [ADR-0012](../adr/0012-observations-are-proposals.md) applies if a
client proposes an inferred booking.

**Design records, all decided 2026-10-09:** [ADR-0015](../adr/0015-medication-records-never-advises.md)
(Accepted) sets the boundary for inventory and refill notices; its UI-wording prerequisite binds the
implementing pull requests. [ADR-0016](../adr/0016-schedule-expansion-in-the-application.md)
(Rejected) had no consumer in this scope.
[ADR-0040](../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) (Accepted)
records the scoped-sharing design.
[ADR-0041](../adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
(Accepted) records the external-event and reconciliation design.
[ADR-0042](../adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
(Accepted) records the refill rules and notice design.

## Current behavior

Source inspection on 2026-10-09 used working copy `e04065d6`. No application tests were run
for this preparation review. The tree provides products, purchase-to-stock unit conversions,
location transfers, consumption, undo, storage classes and stock-entry labels.
`StockService`, `RecipesService`, `StockLineageService` and the permission model are the
implementation references for those capabilities.

Recipe permissions currently gate the recipe domain. They do not establish the per-owner
and explicitly shared access required here. The new consumption recipe may reuse service
behavior without inheriting food-recipe publication, meal planning or calendar exposure.

The stock ledger already attributes quantities to addition bookings under ADR-0036.
`stock_id` is a row-group tag. It must not become a new medication lot identity.

## Scope

The release covers ordinary stock for medication and vitamins, private reusable consumption
recipes, organizer transfers, manual consumption, an authenticated external-consumption API,
and refill tracking. Native Apple Health integration is implemented in `victual-kit`.

There is no Victual dosing scheduler, recurrence expansion, missed-dose classification,
adherence dashboard, dose alert or clinical recommendation. A refill notice is an inventory
notice. No stock booking is generated because a dose was scheduled or a reminder elapsed.

Dedicated manufacturer-batch recall, temperature-excursion ingestion and reconstitution
workflows are deferred from this release. Existing expiry, storage classes and lineage
remain available. The previous proposal for those extensions is not a requirement for
ordinary tablet, liquid or single-use-unit stock.

## Stock and organizer locations

Stock units represent the physical quantity: tablets, capsules, mL or individual single-use
items. Boxes and bottles can be purchase units converted into stock units. A single-use
format uses the same unit-count behavior as a pill. This classification alone does not
require a pierced-container clock, measured remainder or new label kind.

Different products and strengths remain distinct stock products. Conversions express
quantities entered by the household. A client must not infer a conversion from a drug name
or calculate a therapeutic dose from concentration.

Every organizer is a distinct tracked location. A household taking three weekly organizers
on a trip can transfer stock into each separately. Filling an organizer changes location;
it does not reduce total stock. Consumption deducts from the location that supplied it.
Moving an organizer or returning unused contents must not book another consumption.

A consumption request needs a source location or another unambiguous stock selection.
An Apple medication event does not identify which organizer supplied the item. The API
must support explicit selection and a configured source where appropriate, and must refuse
an ambiguous selection rather than silently charge another organizer.

## Private consumption recipes

A recipe records user-entered product quantities that can be consumed repeatedly. The
relationship between the owner and those products is private. Specifically authorized
members may access it; ordinary household membership grants no access by itself.

Shared stock readers can still see products and amounts. Prescription details, recipe
names, ownership, refill rules and associated private events require the scoped policy.
Shared stock references must not expose the private recipe or its owner.

The design must define separate rights for reading, recording consumption, editing recipes,
undoing consumption and managing sharing. Authorization applies to direct routes, generic
objects, filters, counts, exports, labels, calendar and integration surfaces. An inaccessible
private record is absent from lists and returns 404 on direct fetch.

Explicit sharing is a new authorization requirement. It cannot be implemented as a table
that bypasses ADR-0014's effective-grant and account-administration checks. The authorization
ADR must specify how scoped rights participate in those checks, or explicitly propose the
narrow amendment needed. Acceptance remains separate from substantive design changes.

Recipe edits must leave past quantities and product references interpretable. Stock undo
uses the recorded booking, never a recalculation from the recipe's current contents.

## Consumption and the client API

Manual consumption and native-client submissions use the existing stock write paths.
A recipe with multiple lines commits all consumption bookings and its private reference
atomically. Insufficient stock or a permission failure must leave no partial deduction.
`ConsumeProduct()` supplies the transaction identifier through a reference parameter.

The API contract must cover durable request identity, retries, concurrent submissions,
corrections and deletions. A source event maps to at most one effective consumption.
Event identity is scoped to the authenticated source and owner; another member must not
be able to reuse that identity to read or alter the event.

Deduplicating repeated imports does not identify a manually recorded consumption as the
same real-world event. The design needs an explicit link or review flow for that case.
Matching solely on product, quantity and approximate time could erase two separate uses.

Corrections and direct stock undo require one consistent reconciliation policy. An event
that was deliberately undone must not be silently rebooked on the next synchronization.
Conflict handling must preserve the stock ledger and expose unresolved records to an
authorized member. Skipped or unanswered dose events never deduct stock.

### Apple Health boundary

`victual-kit` owns HealthKit authorization, reading, synchronization and native presentation.
Victual exposes a client-neutral API for consumption and refill state. This plan does not
add a HealthKit reader or native notification service to the PHP application.

Apple documents medication objects and dose events with per-medication authorization.
Dose events can arrive late; editing can delete and recreate samples. Those behaviors
require reconciliation beyond a repeated-request check. The documented medication object
exposes whether a schedule exists; complete recurring schedule access is not established
by this research and is not required here.

Research source: [Apple, Meet the HealthKit Medications API](https://developer.apple.com/videos/play/wwdc2025/321/),
reviewed 2026-10-09. Availability and actual payload behavior need a native-client test
before claiming the integration works. A dose event alone does not establish that the
medication came from this household's stock; the user must establish that mapping.

## Refills and reorder notices

Refill tracking is associated with the private prescription. It records the last fill date,
the supplied duration, and any medication-specific reorder rule or explicit next reorder
date. The supplied duration is entered for that fill; a 30-day and a 90-day fill need not
have the same quantity or usage rate.

A medication-specific rule takes precedence. Where no such rule exists, the maintainer's
general fallback is:

```text
estimated reorder date = last fill date + supplied days - 14 days
```

A 30-day fill therefore gives an estimated reorder date 16 days after filling; a 90-day
fill gives 76 days. These examples describe the fallback arithmetic. They do not establish
insurance eligibility or override a medication-specific rule. Missing or invalid inputs
produce an unknown estimate, not an invented date.

The application flags that reordering is approaching and provides a notice on the reorder
date. The proposed default warning lead is seven days, configurable independently of the
14-day fallback. The maintainer confirmed an advance warning; the exact lead and delivery
channel remain implementation recommendations, not recorded maintainer choices.

The displayed date must identify whether it came from an explicit date, a medication-specific
rule or the fallback. Historical fills remain recorded when a new fill resets the calculation.
A reorder request does not add stock or establish a new fill date; receipt is a separate act.

On-hand inventory and the refill estimate are separate values. Organizer transfers do not
change either the fill date or household quantity. Missed or extra consumption changes
inventory but does not silently change the refill rule. Low-stock information can therefore
coexist with a future reorder date without asserting that an insurer will approve a refill.

Native clients consume refill dates and state through the API. Notice delivery must avoid
repeated alerts after retries and recompute when a fill or rule is corrected. Q16 covers
warning defaults, completion state and delivery ownership.

## Labels and storage

Use existing product, stock-entry and location labels. Separate organizers already fit the
location label model. No new payload format or medication label kind is required.
Templates, previews, print captures and scan responses must not disclose private recipe or
refill information to stock readers. Existing stock storage and expiry behavior remains
subject to its current contract.

## Release readiness

The product decisions above replace the former seven-piece regimen proposal. Remaining
technical gates are scoped authorization, the external-consumption reconciliation contract,
and the refill rule and notice schema. Schema work follows those contracts; it is not
constrained to the old two-migration design.

The design records are decided: ADR-0015 revised and accepted, ADR-0016 rejected, and ADR-0040,
ADR-0041 and ADR-0042 accepted, each in its own bookkeeping pull request. ADR-0040 leaves
ADR-0014 and ADR-0018 unamended. The evidence is in `.devtools/adr0040/`, `.devtools/adr0041/` and
`.devtools/adr0042/`. Implementation is tracked by issues 698 to 703 and has not started.

The API contract is developed in this repository. Native HealthKit implementation and
platform-specific notices belong to `victual-kit`. Server verification can use representative
client fixtures, but an end-to-end Apple integration claim requires real client evidence.
The release record must distinguish those outcomes.

[Migrations/RESERVATIONS.md](../../migrations/RESERVATIONS.md) claims 0305 and 0306 for this plan.
Reconciled 2026-10-09: 0305 covers private consumption recipes, shares, consumption events
and source mappings; 0306 covers refill settings, fills, orders and notice acknowledgements.
Both are unwritten, and the table descriptions replace the withdrawn regimen sketch. The
numbers can still move: [ADR-0039](../adr/0039-the-mcp-sidecar-reads-its-configuration-from-victual.md)
implementation may claim a lower slot first. Re-read the table, claim the lowest free slots
before writing a file, and write PostgreSQL-only migrations.

## Verification

- Unit stock, liquid volume and single-use items use entered conversions. Shared quantity
  comparisons use ADR-0032's tolerance. Invalid conversions cannot produce a booking.
- Fill three organizer locations, consume from each and return unused contents. Transfers
  preserve household quantity; consumption and undo preserve ADR-0036 attribution.
- Owner, authorized member, unrelated member and account manager fixtures demonstrate the
  scoped rights, grant limits, revocation and denied indirect reads.
- Manual consumption, duplicate imports, simultaneous requests, deleted/recreated source
  events and explicit manual/import reconciliation deduct each real consumption once.
  Failed multi-product requests roll back completely. Direct stock undo remains effective.
- Explicit reorder dates and medication-specific rules override the fallback. Cover 30-day
  and 90-day fills, missing inputs, corrections, local calendar boundaries, warning dates,
  notice deduplication and refill receipt. Organizer transfers do not reset refill dates.
- PHPUnit against PostgreSQL covers services and APIs; pgTAP covers new SQL behavior;
  contract snapshots cover response shapes; Playwright covers private recipes, organizer
  transfers and refill notices. Run the supported PostgreSQL versions and preserve the
  current aggregate coverage ratchet and per-file gates.
- Release evidence includes an upgrade rehearsal, operator documentation, Vale, image and
  Helm checks, and exact tested commits. A signed v0.5.0 tag follows the release procedure.

## Open questions

Question numbers are retained from the broader draft. Responses below distinguish earlier
answers from the maintainer's inventory scope decision on 2026-10-09.

1. **Should storage classes be their own plan?**

   > **Response:** Yes. Extracted as [23](landed/23-storage-classes.md), now landed.

2. **Where do medication lot numbers live?**

   > **Response:** The earlier answer chose a side table referenced to the root item; the
   > draft interpreted that as `stock_id`. That interpretation is incompatible with using
   > ADR-0036 lineage for attribution. Dedicated manufacturer-batch data is deferred from
   > the narrowed release; existing booking lineage remains authoritative.

3. **Per-subject or household visibility?**

   > **Response, maintainer, 2026-10-09:** Private recipes can be shared with specifically
   > authorized members. Household membership does not itself provide access. This replaces
   > the earlier per-subject default with optional household-wide visibility.

4. **Where are medication attributes copied on a split?**

   > **Response, scope revision, 2026-10-09:** The dedicated attribute table is deferred.
   > Organizer transfers use existing stock lineage. A future batch or container extension
   > must settle its identity model before choosing a trigger or service-level copy.

5. **Does visibility wait on plan 19?**

   > **Response:** The 2026-09-04 answer chose narrow server-side predicates, 404 for hidden
   > direct fetches and no claim to hide shared drug inventory. Plan 19 has since landed.
   > Explicit member sharing now requires an authorization design compatible with ADR-0014;
   > the former owner-link-only predicate is insufficient.

6. **Does this plan build label infrastructure?**

   > **Response:** The 2026-09-06 answer assigned that work to plan 25. Plans 25, 27 and 32
   > now provide the infrastructure and stock-entry kinds. This plan consumes them.

7. **How does a location with changing temperature settings use storage classes?**

   > **Response, scope revision, 2026-10-09:** This remains a storage-class question owned
   > by plan 23. No new temperature model is required for medication inventory.

8. **Does an excursion quarantine automatically or only flag?**

   > **Response, scope revision, 2026-10-09:** Excursion ingestion is deferred. This release
   > adds no automatic quarantine or spoilage inference. ADR-0012 governs future proposals.

9. **Are weight-based and age-based doses calculated?**

   > **Response, maintainer scope, 2026-10-09:** No. Victual tracks physical stock and entered
   > consumption quantities. Dose calculation, scheduling and dose alerting are outside scope.

10. **How do batches, lineage lots and containers relate?**

    > **Response, scope revision, 2026-10-09:** Use ADR-0036's existing booking lineage.
    > A single-use format is ordinary unit stock. The separate manufacturer-batch and
    > container-transition design is deferred.

11. **Who can access private recipes and manage sharing?**

    > **Response, maintainer, 2026-10-09:** Specifically authorized members may access them.
    > The grant authority, individual action rights and account-administration interaction
    > remain technical design gates for a new ADR. Do not introduce an unchecked grant path.
    > [ADR-0040](../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md)
    > (Accepted 2026-10-09) answers these as a design. The maintainer kept ADR-0014's
    > administrator behavior and chose deletion of private recipes with their owner.

12. **What causes stock deduction?**

    > **Response, maintainer, 2026-10-09:** Support manual consumption and Apple Health events
    > submitted by `victual-kit` through the API. Filling an organizer is a transfer to its
    > unique tracked location; multiple weekly organizers remain separate locations.
    > Retry, correction and cross-source reconciliation still need an API contract.

13. **What identifies a scheduled occurrence?**

    > **Response, maintainer, 2026-10-09:** Victual does not schedule doses or provide dose
    > alerts. Native Apple clients live in `victual-kit`. Source-event identity is an import
    > concern; no Victual recurrence expansion or occurrence snapshot table is needed.

14. **How are reorder dates calculated?**

    > **Response, maintainer, 2026-10-09:** Use a medication-specific rule when present.
    > The general last-fill/days-supplied rule with 14 days remaining is an approximate
    > fallback. Support different fill durations, advance reorder warnings and a notice on
    > the reorder date. This is not a dose-schedule forecast or guaranteed eligibility date.

15. **What cold-chain evidence does this release add?**

    > **Response, scope revision, 2026-10-09:** None beyond existing stock and storage behavior.
    > Excursion residence reconstruction and reconstitution workflows are deferred.

16. **What are the refill warning and notification defaults?**
    Recommendation: seven days of advance warning, followed by a due state on the reorder
    date. Define calendar zone, explicit-date precedence, supported medication-specific rule
    forms, repeat suppression, ordered versus received state, and delivery ownership.
    Victual must expose the state through its API; native delivery belongs to `victual-kit`.
    The exact lead and server-side delivery surface remain open.

    > **Design, 2026-10-09:** [ADR-0042](../adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
    > (Accepted 2026-10-09) records a seven-day lead as a per-user setting with a per-prescription
    > override, calendar dates in the server zone with a client `as_of`, no server push, and
    > acknowledged notice keys.
    >
    > **Response, maintainer, 2026-10-09:** seven days as a per-user setting with a
    > per-prescription override; all three rule kinds kept; a short supply gives an unknown
    > estimate. Server push stays out of this release.

## Executed

This section records what shipped. The sections above keep the decisions as they were made.

### Issue 698: private consumption recipes and manual consumption

Merged to `master` in [pull request 735](https://github.com/datagen24/victual/pull/735) and
[pull request 736](https://github.com/datagen24/victual/pull/736); issue 698 is closed.
Migration 0305 created the recipe, line, share, event and event-line tables. The
source-mapping table and the external-source event columns were left to issue 700, as the
migration header says.

### Issue 700: idempotent external consumption and reconciliation

Delivered as a stack of three pull requests, each based on the one before it:
[pull request 739](https://github.com/datagen24/victual/pull/739) (migration 0306),
[pull request 741](https://github.com/datagen24/victual/pull/741) (service, routes, OpenAPI,
fixtures, race tests) and
[pull request 743](https://github.com/datagen24/victual/pull/743) (reconciliation inbox,
browser probe, manual and operator pages), which carries this section. Issue 700 stays open until all three merge and the
CI jobs listed under *Not run here* are green.

**Migration claims.** `migrations/RESERVATIONS.md` gave 0306 to issue 700, the lowest free slot,
and moved the unwritten refill claim of issue 701 to 0307. Migration 0306 adds
`consumption_mappings` and extends the 0305 event tables. There is no second ledger.

**What differs from the design records.**

- A product mapping accepts an optional `qu_id`, the unit the event quantity is in. ADR-0041
  names a unit but its contract fragment has none.
- An explicit link is stored in `linked_transaction_id`, because a manual event already holds
  the same transaction under 0305's unique index.
- Event objects also carry `medication_ref`, `used_date` on lines, `recipe_id` for a manual
  event and, for `stock_error`, a `message` that only the event's user receives. `invalid_link`
  is a new `422` token. `replayed` is `false` on a new row.
- Event routes read the JSON body as sent, because the shared body filter turns numbers into
  strings.
- A `dismissed` event is terminal: a changed payload is stored and never books.
- A filter-based bulk resolve compares the state a person sees, so an event undone in the stock
  journal is found.

**Limits the records leave open.** Bulk void by filter takes the oldest dose first, which is
the wrong order to undo bookings drawn from one purchase (ADR-0036), so a client that booked
history in occurrence order ends with `undo_refused` for all but the last-booked event. ADR-0041
row 7 ("create then delete leaves one deduction") holds only when the two events draw on
different purchases. Both are documented in the
[operator page](../manual/operator/external-consumption.md) and pinned by fixtures, and both
need a decision in ADR-0041 or an issue.

**Evidence.** Environment: PostgreSQL 16.15, PHP 8.3.6 with a local shim for the `PDO\Pgsql` and
`PDO\Sqlite` classes that PHP 8.4 adds (CI runs PHP 8.5). Commands were run from the repository
root.

| Check | Commit | Result |
|---|---|---|
| `phpunit --testsuite consumption` with the `.devtools/pgsql/run-tests.sh` data path and configuration | `b368318` (inbox stage) | 182 tests, 4,212 assertions, OK |
| Same suite, first two stages | `2157bcb` (service stage) | 182 tests, 4,218 assertions, OK |
| `.devtools/pgsql/run-tests.sh rbac` | `b368318` | 54 tests, 574 assertions, OK |
| `.devtools/pgsql/run-tests.sh pgtap` (includes `032-consumption-mappings.sql`, 44 assertions) | `b368318` | Result: PASS, coverage list OK |
| `.devtools/pgsql/check-migrations.php`, `check-cited-jobs.php`, `check-path-id-validation.php` | `b368318` | all OK |
| `node .devtools/frontend/consumption-inbox.js` on a production-mode instance | `b368318` | 6 scenarios passed |
| `python3 .devtools/vale/audit.py --check` | `b368318` plus the frontend README edit in this commit | 0 new findings |
| `mkdocs build --strict` after `.devtools/docs/stage.py --no-api` | `b368318` | exit 0 |
| `ConsumptionEventRaceTest`, 8 consecutive runs | `30704bd` (the working tree it committed) | no failure after the created-row fix |

The concurrency tests cover 12 identical requests at once, the same key from two users, two
corrections over overlapping product sets, a correction racing a direct undo, a deletion racing
a replay, and nine events racing for five tablets. The delays are seeded timing jitter, so a
pass shows the absence of the failures in those rounds and not a proof.

**Issue 700 criteria.**

| Criterion | Evidence |
|---|---|
| Source identity, authorization, mappings, atomic stock writes, contract-defined correction, deletion and conflict behavior | `ConsumptionEventServiceTest`, `ConsumptionMappingServiceTest`, `ConsumptionEventApiTest`, pgTAP 032 |
| Repeated and concurrent submissions deduct once; sources cannot collide into or inspect another owner's event | `ConsumptionEventRaceTest`, `testTwoUsersWithTheSameSourceIdsHave...`, fixture 17 |
| Explicit manual and import reconciliation without fuzzy merging; unresolved records exposed to the owner | `link` and `possible_duplicates` in the service tests and fixtures 12 and 13; the inbox probe |
| Direct stock undo is not reversed by replay; insufficient stock, invalid units and ambiguous location leave no partial booking | fixtures 10, 11, 14, 15; service tests for rollback and `undo_refused` |
| Skipped and unanswered events cause no deduction; a scheduled dose never consumes | fixture 09; `testSkippedUnansweredAndScheduled...` |
| OpenAPI, fixtures, snapshots, migration and permission metadata | `victual.openapi.json`, `tests/fixtures/consumption-events/`, contract snapshot entries, `RESERVATIONS.md`, importer and `migratedifftest.php` lists |
| PHPUnit, pgTAP and concurrency tests; browser coverage of the reconciliation UI | the rows above |

Server fixtures replay Victual's answers. They are not evidence that the Apple client has
synchronized (issue 702).

**CI evidence.** The pull request checks ran on GitHub Actions with PHP 8.4 or later and with
both PostgreSQL versions: `suite-floor` uses PostgreSQL 15, `images` uses PostgreSQL 16 and `suite`
runs the aggregate coverage ratchet. At `0a63015` (pull request 741) and `17657d2` (pull request 743)
every check completed without a failure: `lint`, `prose`, `mcp`, `frontend-security` (which runs
the inbox probe on 743), `images`, `suite-floor` and `suite`. Pull request 739 at `cab577e` passed
the same set and Psalm. Earlier heads of 741 and 743 failed `WireContractTest` (the documented
boolean list and the instant patterns of the new schemas) and the contract snapshot; both were
fixed in `0a63015` and `2157bcb`.

**Not run here.** Local runs did not cover PostgreSQL 15, PHP 8.4 or 8.5, the coverage run,
Psalm, the whole `frontend-security` job or the full test suite; the CI evidence above covers
them. The `contract` phase and six `WireContractTest` tests fail on this host in tests about
numeric typing of `/api/objects/*` fields (`price`, `tare_weight`, `amount`), and they fail the
same way on unmodified `master` at `7287b77`. The new snapshot entries were generated here and
merged into the committed snapshot as additions; CI compared them on PHP 8.4 or later.
