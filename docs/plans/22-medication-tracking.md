# 22. Medication inventory and private consumption recipes

**Goal:** Track the medication, vitamin and supplement quantities on hand, record their
consumption and identify when to request a refill. A prescription is a reusable,
user-entered consumption recipe with access restricted to its owner and specifically
authorized members. Dosing schedules, adherence tracking and dose reminders are outside
Victual's scope.

**Status:** in progress for v0.5.0; product scope decided and design records accepted
2026-10-09. Implemented: private consumption recipes and manual consumption (issue 698, merged),
the external-event schema, service, API and reconciliation inbox (issue 700, merged), and the
organizer inventory workflow's test evidence (issue 699, merged in pull requests 738 and 744).
Issues 698 to 700 are closed. Refill tracking (issue 701) is in review in pull requests 748, 749 and
751. Not implemented: native acceptance on a device (issue 702) and integrated verification (issue 703).

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
`.devtools/adr0042/`. Implementation is tracked by issues 698 to 703. Issues 698 to 700 have
merged; issues 701 to 703 have not.

The API contract is developed in this repository. Native HealthKit implementation and
platform-specific notices belong to `victual-kit`. Server verification can use representative
client fixtures, but an end-to-end Apple integration claim requires real client evidence.
The release record must distinguish those outcomes.

[Migrations/RESERVATIONS.md](../../migrations/RESERVATIONS.md) claims 0305 to 0307 for this plan.
As of 2026-10-10, 0305 (private consumption recipes, shares, events) and 0306 (source mappings and
the external-source columns) are on disk in `master`. 0307 is the refill claim of issue 701 and
is unwritten. An earlier reconciliation on 2026-10-09 gave refill 0306; issue 700's schema took
that slot, and refill yielded to 0307. The number can still move:
[ADR-0039](../adr/0039-the-mcp-sidecar-reads-its-configuration-from-victual.md) implementation
may claim a lower slot first. Re-read the table, claim the lowest free slot before writing a
file, and write PostgreSQL-only migrations.

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

17. **Does ADR-0041 row 7 ("create then delete leaves one deduction") hold for two events that
    draw on the same purchase?**
    Row 7 of the verification table lists "Edit as new id without `replaces`, delete then
    create, and create then delete" with the expected result "One deduction after both orders".
    ADR-0036 rule 7, step 3, refuses to undo a booking while a later live booking of the same
    product has an allocation on any lot the first one drew from. Create-then-delete deletes the
    older event after the newer one is booked, so the outcome depends on the lots:

    | Case | Old event draws on | New event draws on | Delete of the old event | Result | Reproduction |
    |---|---|---|---|---|---|
    | Different purchases | lot of purchase 1 (1 tablet) | lot of purchase 2 (9 tablets) | undone | one deduction | fixture `07b-create-then-delete.json` |
    | Same purchase | lot of purchase 1 (10 tablets) | the same lot | `needs_review` / `undo_refused` | two deductions, original booking kept | `ConsumptionEventServiceTest::testAVoidWhoseUndoIsRefusedBecauseALaterBookingDependsOnItNeedsReview`; the fixtures README, "Known behaviour" |

    `ConsumptionEventServiceTest::testDeleteThenCreateAndCreateThenDeleteBothLeaveOneDeduction`
    does not cover the second row. Its create-then-delete half deletes the newer of two events,
    which is always undoable. ADR-0041 section 6 already says a refused undo "is a normal outcome",
    and section 7 says an unmatched delete-and-recreate is two independent requests. Only the table
    row states the outcome without a condition.

    Proposed disposition, for the maintainer to accept, change or reject:

    - **A (recommended).** Treat the row as imprecise and record an erratum in ADR-0041's
      verification table. Row 7 holds "one deduction" for delete-then-create. It holds for
      create-then-delete only when the deleted event is the newest booking on each lot it drew
      from. Otherwise the delete answers `needs_review` / `undo_refused`, and the client sends
      `replaces`. This changes no code and no accepted rule of ADR-0036. The operator page and
      the fixtures already say this.
    - **B.** Change the code so the delete undoes the later booking, undoes the older one and
      rebooks the later event under a new transaction id, all in one transaction. The result is
      one deduction for both cases. This changes the transaction id of an event that the delete
      did not name, which the contract does not describe, and it moves lot allocations between
      events without a person's request.

    The maintainer's settled choice for bulk void (oldest first, refused items stay in
    `needs_review`) is not part of this question. No ADR or code is changed by this entry.

    > **Response:** Pending. Not decided.

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
browser probe, manual and operator pages), which carries this section. All three are merged.
Issue 700 was open until the CI jobs listed under *Not run here* were green on `master`; it is
closed now. Closing it did not by itself show that each gate below was met. The CI addendum under
issue 699 records the logs that were read afterwards.

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
[operator page](../manual/operator/external-consumption.md) and pinned by fixtures.

> **Response, maintainer, 2026-10-10:** For bulk void, keep the ADR order and leave an event
> that cannot be undone in `needs_review`. Accepted: the optional `qu_id`, the extra fields on
> event objects, the owner-only `message`, `invalid_link` and `replayed: false`, with a case for
> each on the operator page. Accepted: the link stored in `linked_transaction_id`, and `dismissed`
> as terminal. For ADR-0041 row 7 the maintainer asked for a second opinion. The implementation
> reading is that the row holds only when the two events draw on different purchases, which
> would be an erratum to the ADR and not a code change. It is not yet recorded as a decision.

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

Pull request 741 merged at `525c5bff`, where all checks passed, including `suite`. Two review
findings changed code there: the label of an `approve_unit` action now commits before the booking
runs, and a time-of-day test anchors on the previous day. Anchored on the day itself, that test
failed in the first hours after midnight UTC, and it failed in the CI runs of `32561bc`.

**Not run here.** Local runs did not cover PostgreSQL 15, PHP 8.4 or 8.5, the coverage run,
Psalm, the whole `frontend-security` job or the full test suite; the CI evidence above covers
them. The `contract` phase and six `WireContractTest` tests fail on this host in tests about
numeric typing of `/api/objects/*` fields (`price`, `tare_weight`, `amount`), and they fail the
same way on unmodified `master` at `7287b77`. The new snapshot entries were generated here and
merged into the committed snapshot as additions; CI compared them on PHP 8.4 or later.

### Issue 699: organizer inventory workflow

Measured locally 2026-10-10 against working copy `bf902bc60039eff44cda779398e293dfb12f0b6e` (branch
`claude/issue-699-household-organizer-yme490`, with master at `46eb1f7`, the merge of pull request
743, merged in). Master has since moved to `e20b2cc` (pull request 745) and merged again; those
commits change documentation only, so the results below were not repeated. Master already carries the organizer workflow test, browser probe and manual
section from [pull request 738](https://github.com/datagen24/victual/pull/738). This change adds
tests, one controller change and documentation on top of them.

Nothing was added to the schema, the label namespace or the services. An organizer is an
ordinary location, filling it is `TransferProduct()`, and consuming from it is `ConsumeProduct()`
with `location_id`. The recipe path charges the location the same way.

| Criterion | Evidence |
|---|---|
| Each organizer is a unique tracked location with an existing location label | `OrganizerApiTest::testEachOrganizerIsAUniqueLocationAndKeepsItsLocationLabel` (a second top-level location with the same name is refused; the label context route answers for it). `OrganizerLabelDisclosureTest` issues and prints a location label. |
| Filling three organizers keeps totals; consumption charges only the selected source; return is a transfer | `OrganizerApiTest::testThreeOrganizersAreFilledConsumedFromUndoneAndEmptiedBackWithTheTotalHeldAtEveryStep`: 90 tablets, 7 into each of three organizers, consume from B, undo, consume from A and C, return all three. Per-location quantities, the household total, the consume booking count and `stock_lineage_violations()` are asserted after every step. `OrganizerWorkflowTest` pins the same path through the recipe service. |
| Explicit source selection; configured defaults; never another organizer | `location_id` and `stock_entry_id` on the stock consume route; `location_id` on the recipe route. `testAShortfallAtTheSelectedOrganizerRefusesAndChargesNoOtherOrganizer` and `testALocationIdThatNamesNothingUsableIsRefusedInsteadOfBecomingAnyLocation`. Configured sources: [the paragraphs after the table](#issue-699-organizer-inventory-workflow). |
| Conversions for tablets, liquids and single-use units; strengths stay distinct | `testLiquidSingleUseAndStrengthsStayDistinctThroughTheRoutes`: a 250 mL bottle read back as a factor of 250, half a bottle as 125 mL, ampoules as unit stock, 5 mg and 10 mg as separate products. |
| ADR-0036 lineage and ADR-0032 comparisons | `stock_lineage_violations()` is empty after every step of every test above. The earlier consumption of a purchase cannot be undone once a later booking moved units of the same purchase (`400`, "subsequent dependent bookings"), which ADR-0036 requires and the manual documents. `OrganizerWorkflowTest::testFractionalAmountsAcrossOrganizersCompareWithinTheSharedTolerance` empties a row with ten consumptions of 0.1. |
| Labels, previews and scans disclose no private recipe or refill data | `OrganizerLabelDisclosureTest` and `OrganizerApiTest::testAnOrdinaryStockReaderSeesNoPrivateRecipeData`, both as a caller holding `STOCK_VIEW` only. Fields covered: [the paragraph after the table](#issue-699-organizer-inventory-workflow). |
| API and Playwright evidence, operator documentation | API: `OrganizerApiTest`, `OrganizerLabelDisclosureTest`. Browser: `.devtools/frontend/organizers.js`, wired into the `frontend-security` job (not run in this measurement). Operator documentation: the manual's Weekly organizers section in `consumption-recipes.md`, with the source-selection, labels and checking subsections. |

The shortfall test asks for 5 from an organizer holding 3 while the other organizers hold 7 and
the cabinet 63. The request is refused, books nothing and moves nothing.

Two configured sources exist, and neither is new in this change. For a manual consumption with no
location, `stock_next_use` orders the product's `default_consume_location_id` first. For an event
an external client submits, issue 700's consumption mapping chooses the location: `fixed` is that
location with no fallback, `single` is the one location that holds enough, and `explicit` is the
location the event names. Their behavior is pinned by `ConsumptionEventServiceTest`, including
`testAFixedLocationNeverFallsBackToAnotherOrganizer`. This change adds no default policy.

The label test covers the location, product and stock entry kinds. It reads every catalogued field
of a live capture and of a sample preview. It also reads the print job, the outbox event, the
render request and the label row, then the scan, the context read, and the snapshot of a label
retired when its row was emptied.

A positive control shows that the recipe name, note and request id are stored, so their absence
means something. The API test reads 17 stock, generic and label routes over HTTP
and resolves a label of each kind over the scan route.

One gap was found and fixed. `POST /stock/products/{id}/consume` dropped a `location_id` that
was not a non-empty number. A value such as `"organizer-b"`, `0`, `true` or a list became "any
location", and the consumption came from whichever organizer the product default or due date
chose. `OrganizerApiTest::testALocationIdThatNamesNothingUsableIsRefusedInsteadOfBecomingAnyLocation`
failed on that behaviour before the change (a `200` where a `400` was expected) and passes after.
The route now validates the value as the add and edit routes do (issues 519 and 544); `null` and
`""` still mean no location. This changes request validation, not a response shape, and the
OpenAPI description and the changelog say so.

Commands and results, all on PostgreSQL 16.15 and PHP 8.3.6:

- `phpunit --configuration phpunit.xml --testsuite consumption`: 199 tests, 4,773 assertions, OK.
  `rbac`: 54 tests, 574 assertions, OK.
- `stockconcurrency` 23 tests OK; `labelapi` 124 OK; `labelservices` 293 OK; `chores` 20 OK;
  `stocklocations` 59 OK; `recipeoperations` 35 OK.
- `wirecontract`, `contract`, `stockcoverage`, `stockmaintenance`, `stockpages` and `genericquery`
  report failures (6, 1, 3, 1, 1 and 3). The same counts appear on untouched master at `46eb1f7`
  in the same environment, so this change adds none. They appear to come from PHP 8.3 returning
  strings where PHP 8.4 returns numbers; that cause was not confirmed.
- `check-migrations.php` OK (no migration added), `check_vendor_paths.py` 0 errors,
  `check-cited-jobs.php` OK, `mkdocs build --strict` exit 0, Vale 0 new findings on the changed
  pages.

CI run on the pull request head, recorded 2026-10-10. Pull request 744 was merged at `bfb30d1`; its
last head was `78393dba29025975b921e7cae97746a30452fd0b`. All 17 checks on that head passed,
including these from workflow run 38013745516:

- `suite` ran `run-tests.sh` on PostgreSQL 16 with `SUITE_COVERAGE=1` and the aggregate ratchet
  (`--min=96.31198844487241217394`). The default run includes the `consumption` phase, which holds
  the new test classes.
- `suite-floor` ran `run-tests.sh` on PostgreSQL 15, the minimum version the application enforces.
- `frontend-security` ran `.devtools/frontend/organizers.js` against a booted demo instance.
- `lint`, `images`, `prose`, `mcp`, Psalm and CodeQL also passed.

These records are job conclusions. The job logs were not read, so the coverage figure, the
per-file figures and the probe's own output are not quoted here. CI gates the aggregate figure
only. The six suites that failed in the PHP 8.3 sandbox passed inside the `suite` job, which
supports the reading that those failures came from the sandbox.

Not covered:

- Per-file coverage figures for the changed controller. The change is five lines, and the new tests
  reach both its accepting and refusing paths.
- Undo and shortfall steps in `organizers.js`. The API tests cover both.
- Contract snapshots were not regenerated, because no response shape changed.
- A real device or Apple client. This issue involves none.

Remaining dependencies: issue 701 (refill tracking) and the later issues are untouched by this
change. Every criterion of issue 699 now has the evidence listed above. Closing the issue is the
maintainer's decision.

### CI log addendum for issues 699 and 700, read 2026-10-10

The sections above recorded job conclusions for pull request 744 and said the job logs were not
read. The logs of workflow run 38013745516 (head `78393dba29025975b921e7cae97746a30452fd0b`, read
with `gh run view 38013745516 --log` on 2026-10-10) say the following. The earlier text stays as
written.

| Check | Log line or figure |
|---|---|
| `frontend-security`, organizer probe | `ORGANIZER BROWSER CHECKS PASSED` |
| `frontend-security`, inbox probe | `CONSUMPTION INBOX BROWSER CHECKS PASSED (6 scenarios)` |
| `suite`, the runner's own report | 13,502 of 14,018 executable lines (96.32%) from 2,525 processes |
| `suite`, merged with the separately measured steps | 13,551 of 14,018 executable lines (96.67%) from 2,540 processes; the ratchet `--min=96.31198844487241217394` was met |
| `suite`, per-file inventory | 1 of 157 measured files below the 75% floor: `controllers/ConsumptionInboxController.php`, 1 of 2 lines (50.0%); 6 files have no executable lines |

CI gates the aggregate figure only, so the file below the floor did not fail the run. Only the
permission refusal reached the controller; no test rendered the page. [Pull request
747](https://github.com/datagen24/victual/pull/747) added two `HouseholdPagesTest` cases that render
the page and refuse a caller without `STOCK_VIEW`, and merged at `f42ecbbd`.

Master after the merges, read from the logs on 2026-10-10:

| Run | Commit | Result |
|---|---|---|
| `tests` 38058529109 | `bfb30d11` (pull request 744 merged) | All seven jobs passed. `suite`: 13,503 of 14,018 lines from the runner (96.33%), 13,552 merged (96.68%), 1 of 157 files below 75% (`ConsumptionInboxController`). |
| `tests` 38059717293 | `f42ecbbd` (pull request 747 merged) | All seven jobs passed. Inbox and organizer probes passed. `suite`: 13,504 lines from the runner (96.33%), 13,553 merged (96.68%), 0 of 157 files below 75%. |

The run on `0b059a4f` (pull request 746, a documentation change) skipped the heavy jobs through the
`changes` filter.

These runs and the fixtures do not establish HealthKit behavior. Issue 702 owns that evidence.

### Issue 701: refill history, reorder estimates and notices

In review as a stack of three pull requests, each based on the one before:
[748](https://github.com/datagen24/victual/pull/748) (migration 0307, pgTAP, import and migration
metadata), [749](https://github.com/datagen24/victual/pull/749) (estimate, services, ten routes, OpenAPI,
contract snapshots, capabilities, client fixtures, operator page) and
[751](https://github.com/datagen24/victual/pull/751) (page, translations, browser probe, user manual,
glossary). Nothing here is merged, issue 701 stays open, and no part of it is native-client evidence.

**Divergences from ADR-0042** are listed in the description of pull request 749. In short: five tables,
because the explicit date has its own history; `estimate` always carries five keys; extra fields
(`recipe_name`, `age_days`, settings, history, notice `text`); writes answer with the state; the two
list routes are objects so they can carry `as_of_source`; `supplied_days` is optional; dates are limited
to 1900 to 2200.

**Evidence.** Environment: PHP 8.5.10 and PostgreSQL 16 or 15.19 in podman on macOS, plus the CI runs below.

| Check | Result |
|---|---|
| pgTAP `033-consumption-refill.sql` | 84 assertions; the whole `pgtap` phase 25 files, 419 tests, PASS on PostgreSQL 16 and 15 |
| `RefillEstimatorTest` | 63 tests, no database |
| `consumption` phase (service, API, race, fixture, disclosure and organizer classes) | OK on PostgreSQL 15.19: 353 tests, 9,539 assertions, at commit `4a448a23`; later commits re-ran the refill classes (85 tests OK on 16) |
| `contract`, `wirecontract`, `rbac`, `dialectpolicy`, `migrate` | passed on PostgreSQL 16 and 15 at `4a448a23` |
| Browser probe `consumption-refills.js` | `CONSUMPTION REFILLS BROWSER CHECKS PASSED (7 scenarios)` locally and in CI run 38065419551 |
| CI, pull request 748 | every check passed, including `suite` (PostgreSQL 16, coverage ratchet) and `suite-floor` (PostgreSQL 15) |
| CI, pull request 749, run 38065336808, head `76e135a0` | every check passed; merged coverage 14,014 of 14,489 lines (96.72%), 0 of 160 files below 75% |
| CI, pull request 751, run 38065419551, head `0464bec3` | every check passed; merged coverage 14,017 of 14,492 lines (96.72%), 0 of 161 files below 75% |

Coverage against the baseline in the CI log of master `f42ecbbd` (13,553 of 14,018, 96.68%): the aggregate
rose by 0.04 points and the measured file count by 4. Runner figures for the new and touched files in run
38065419551:

| File | Lines covered | Before |
|---|---|---|
| `services/RefillEstimator.php` | 58 of 61 (95.08%) | new |
| `services/ConsumptionRefillService.php` | 328 of 334 (98.20%) | new |
| `controllers/Api/ConsumptionRefillsApiController.php` | 58 of 59 (98.31%) | new |
| `controllers/ConsumptionRefillsController.php` | 2 of 2 (100%) | new |
| `controllers/Api/UsersApiController.php` | 201 of 218 (92.20%) | 197 of 214 (92.06%) |
| `services/ConsumptionRecipeService.php` | 357 of 366 (97.54%) | 355 of 364 (97.53%) |

A local run of the full suite on the master baseline measured 13,500 of 14,018 lines from the runner alone
(96.30%); compare the CI figures. That local run had two failing cases on unmodified master, `StorageFilesTest`
(a host-mounted scratch directory) and `MqttCoverageTest::testABrokerThatHangsUpMidBatchIsNotRecordedAsDelivered`.
Both passed in CI.

**Issue 701 criteria.**

| Criterion | Implementation and evidence |
|---|---|
| Fill history, supplied duration, rules and explicit dates, with the agreed precedence and correction semantics | Migration 0307 and its six triggers; `RefillEstimator::Estimate`; `ConsumptionRefillService` (record, void, settings). pgTAP 033; `RefillEstimatorTest`; `ConsumptionRefillServiceTest`; fixtures 03, 07, 08 |
| Fallback only without a specific rule; unknown for missing inputs; provenance of every date | `estimate.source` and `estimate.reason`. `RefillEstimatorTest` (invalid rule, invalid supply, short supply, fixed interval without a supply); fixtures 01 to 04 |
| Approaching, due, ordered and received; lead; repeat suppression; client-readable dates | Status boundaries in `RefillEstimator::Status`, notices and per-user acknowledgement in the service. Service, API and race tests; fixtures 05 to 10; the browser probe |
| Transfers, consumption and a bare order do not reset fills or add stock | `testTransfersConsumptionAndUndoDoNotChangeAFillAnOrOrderOrAnEstimate`, `testNoRefillOperationWritesTheStockLedgerOrAnEvent`, `testRecordingAnOrderChangesNothingButTheStatus`; the probe reads the stock back after an order and a receipt |
| Private routes, UI and notice payloads honor scoped access and revocation | Recipe lock and rights in `AuthoriseRefill`. Service and API tests (owner, read share, edit share, stranger, account manager, administrator, no `STOCK_VIEW`); `ConsumptionRefillRaceTest` (write and acknowledgement against a revoke); `ConsumptionRefillDisclosureTest`; the probe's revocation scenario |
| API, OpenAPI, snapshots, UI, translations, operator documentation; native delivery stays in victual-kit | Pull requests 749 and 751. `victual.openapi.json`, contract snapshots (additions only), 113 strings in `strings.pot`, the operator and user manual pages, eleven client fixtures. No scheduler, push credential or HealthKit code |
| Tests cover 30- and 90-day fills, overrides, explicit dates, invalid inputs, calendar boundaries, corrections, deduplication and receipt | The cases of ADR-0042's verification table are in `RefillEstimatorTest`, `ConsumptionRefillServiceTest`, the API and race tests and fixtures 01 to 11 |

**Review findings fixed.** Two read-only reviews of the stack found these defects, all fixed:

- A misspelled body field recorded a fill with no supply.
- An `as_of` that was an array or empty was read as absent.
- A path id above the integer range answered 400 instead of 404.
- An acknowledgement echoed a non-canonical key.
- On the page, a slower answer replaced the open prescription, a refused write reset the fields, and text a
  number field could not read was read as empty.
- On the page, a double click posted twice and counts of 1 read "1 days".

**Remaining gates.**

- The maintainer has not approved the user-facing wording (ADR-0015 prerequisite 1). The strings are listed
  in the descriptions of pull requests 749 and 751.
- Open question 17 (ADR-0041 row 7) awaits a decision.
- Native acceptance on a real device (issue 702) and integrated verification (issue 703) have no evidence.
- Pull requests 748, 749 and 751 are not merged. Plan 22 stays in progress and v0.5.0 is not claimed.
