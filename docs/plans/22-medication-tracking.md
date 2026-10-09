# 22. Medication inventory and private consumption recipes

**Goal:** Track the medication, vitamin and supplement quantities on hand, record their
consumption and identify when to request a refill. A prescription is a reusable,
user-entered consumption recipe with access restricted to its owner and specifically
authorized members. Dosing schedules, adherence tracking and dose reminders are outside
Victual's scope.

**Status:** preparation for v0.5.0; product scope decided, technical design gates open.

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

**Proposed records:** [ADR-0015](../adr/0015-medication-records-never-advises.md) needs
its boundary aligned with inventory and refill notices. [ADR-0016](../adr/0016-schedule-expansion-in-the-application.md)
has no implementation consumer in this scope. Neither record's lifecycle status changes
through this plan. New design records are needed for scoped sharing and external-event
reconciliation; see [release readiness](#release-readiness).

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

Substantive preparation must revise Proposed ADR-0015 and present the lifecycle disposition
of ADR-0016. Rejecting ADR-0016, if chosen, is its own bookkeeping-only pull request.
New Proposed records must cover scoped recipe sharing and external-event reconciliation.
Their relationship to accepted ADR-0014 must be explicit. No record is accepted by this plan.

The API contract is developed in this repository. Native HealthKit implementation and
platform-specific notices belong to `victual-kit`. Server verification can use representative
client fixtures, but an end-to-end Apple integration claim requires real client evidence.
The release record must distinguish those outcomes.

[Migrations/RESERVATIONS.md](../../migrations/RESERVATIONS.md) currently claims 0305–0306
for the previous schema sketch. Those claims are unwritten. Reconcile their descriptions
and the required count before writing migrations; use the lowest available slots and
PostgreSQL-only migrations. Do not retain obsolete regimen tables to fit old reservations.

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
