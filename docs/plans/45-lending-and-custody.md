# 45. Lending and custody: a household library and tool crib

Record who holds a household asset, since when, when it is due back, and its condition
when it returns. The same record serves a home library, where books leave the house for
weeks, and a tool crib, where tools go to a person or a project for hours or days.

Status: draft research, 2026-11-27, [issue 1154](https://github.com/datagen24/victual/issues/1154).
This plan accepts no architectural decision and schedules no implementation. The
[plan index](README.md) owns delivery status. No migration number is reserved.

**Depends on:** [plan 38](38-household-inventory.md), household inventory and the
equipment rebuild, stage A. A loan references a [household asset](../glossary.md#household-asset),
and that record does not exist yet.

## Problem and outcome

Plan 38 lists "loaned" as an asset lifecycle state but defines no record behind it. Without
one, the household cannot answer these questions:

- Who has the second copy of a field guide, and has it been out longer than agreed?
- Which tools are not in the workshop right now, and who took them?
- Was the torque wrench returned damaged, and who had it last?
- Which items went to a renovation project, and are they all back?

Success means a person can check an asset out to a holder by scanning its label, see every
open and overdue loan, check the asset back in with its condition, and read the asset's
loan history afterwards.

## Current behavior

Source inspection used `ce24e247c1de1947ab2d34539f73a3fe321b9ed1`. It did not run the
application.

| Existing capability | Evidence | Consequence for this work |
|---|---|---|
| Equipment stores a name, description and manual file name only | `migrations/0041.sql` | No holder, placement or status exists to extend. Plan 38 replaces this record. |
| No lending, borrowing or checkout code exists | Search of `services/`, `controllers/`, `migrations/` and `db/` for loan, lend, borrow and checkout terms | Loans are new tables, services and endpoints. |
| Label kinds are fixed by a `CHECK` | `migrations/0283.pgsql.php` lists `location`, `product`, `stock_entry`, `recipe`, `chore`, `battery` | Scanning an asset needs a new kind through [ADR-0011](../adr/0011-label-namespace.md)'s `vctl:` namespace. Plan 38 stage C already expects to extend label kinds. |
| Plan 38 treats an ISBN as an edition and each copy as its own asset | [Product metadata lookup](38-household-inventory.md#product-metadata-lookup) | A loan always names a copy. Two copies of one edition can be lent to different people. |
| Plan 38 proposes contacts without logins for heirs and service records | [Requirements](38-household-inventory.md#requirements), the intended-inheritance row | Outside borrowers can reuse that contact record. Plan 38 already states that being named grants no access. |
| Book metadata lookup is researched | [Plan 09](09-barcode-lookup-sources.md), the Inventaire entry | ISBN prefill belongs to plans 09 and 38. This plan adds nothing to lookup. |
| Equipment rights are `EQUIPMENT_*` leaves | [Plan 42](42-permission-catalog.md), equipment row | `CHILD` and `GUEST` hold no equipment leaf today. Loan leaves must be added to the catalog. |
| Due dates reach clients through the calendar | `GET /api/calendar/ical`, [Calendar](../manual/using-victual/calendar.md) | Loan due dates can use the same feed. Push notification delivery is still undecided (plan 38 question 6). |

## Scope

Included:

- Check-out and check-in of a single asset, with holder, issuer, dates, condition and notes.
- Kit check-out, where several assets leave together and may return separately.
- Open-loan, overdue and per-asset history views, plus API reads of the same data.
- Scan-to-check-out through an asset label.
- Due dates in the calendar feed.

Excluded:

- Fines, deposits and payments.
- A public catalogue or any page visible without signing in.
- Messages sent to outside borrowers by email, SMS or similar. Sending them would add
  outbound delivery to addresses users configure. AGENTS.md asks that the tree keep no
  user-configured outbound URLs.
- Lending between separate Victual installations.
- Stock products. A consumable issued with a tool is recorded as an ordinary stock
  consumption booking (see [Consumables issued with tools](#consumables-issued-with-tools)).

## Proposed design

These are design candidates for review, not approved table names or endpoint contracts.

### Loan record

A loan is one asset in the custody of one holder for one period.

| Field | Meaning |
|---|---|
| asset | The household asset lent. Required. |
| holder | A user or a contact. Exactly one of the two. |
| issued by | The signed-in user who recorded the check-out. |
| lent at | Timestamp of check-out. |
| due on | Optional calendar date. A loan without a due date is never overdue. |
| returned at, received by | Set together at check-in. |
| condition out, condition in | Short free text or a small fixed scale (question 6). |
| kit loan | Optional reference grouping loans checked out together. |
| project | Optional reference to a plan 38 project. |
| notes | Free text. |

Rules:

- An asset has at most one open loan. A partial unique index on the asset where
  `returned_at IS NULL` enforces this.
- Returned loans are kept as history. Correcting a wrong entry is an update with its own
  permission, not a delete and re-create.
- The asset's "loaned" state is derived from the existence of an open loan. Storing it as a
  separate column would create two sources for the same fact that could disagree.
- `due on` is a calendar date with no time of day, as [ADR-0042](../adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
  chose for refill dates. Overdue means today's date in the household timezone is after it.

### Custody and placement

Custody and physical placement are separate facts. A lent asset keeps its home placement
from plan 38, which is where it belongs and where it returns to. The loan says who has it now.

Check-in returns the asset to its home placement by default. If it comes back somewhere
else, check-in may record a move through plan 38's placement path in the same transaction.
A loan never writes a placement by itself, and no loan changes stock.

### Holders

A holder is either a user account or a contact. A contact has no login and gains no access
by being a holder, which matches plan 38's rule for named heirs. A contact's name and
contact details are personal data, so reading them needs a permission that an asset read
does not imply.

If question 1 limits holders to users, the contact half of this design drops out and the
loan's dependency on plan 38's contact record goes away.

### Kits

A kit is a set of assets that leave together, such as a socket set or a box of books for a
book club. A kit check-out creates one loan per member asset in a single transaction, linked
by a kit loan reference. Each loan closes independently, so a partial return leaves the
missing members visible as open loans.

What defines a kit's membership depends on plan 38 question 5 (grouped belongings) and its
stage C boxes. Until those are decided, a kit check-out can take an explicit list of assets.

### Library details

A book copy is an asset whose descriptive fields come from its edition, through plan 38's
metadata entry and plan 09's ISBN lookup. Lending works on the copy. A listing can group
copies by edition and show how many are available.

Reading status, series tracking and a wishlist are not loans. They are excluded unless
question 3 adds them. If they are added, they belong to the asset or edition, not to this
plan's loan record.

### Tool crib details

- A check-in may record damage. Damage should be able to open a plan 38 service record, so
  repair history stays with the tool.
- A tool with an overdue service or calibration record may warn at check-out or refuse it.
  Question 6 decides which. Either way, this needs plan 38 stage B's maintenance records.
- A project reference lets a person close out a project by checking that all its loans are
  returned.

### Consumables issued with tools

Drill bits, blades and abrasives are stock products. Issuing them is a consumption booking
through the existing `StockService` paths, with the usual undo behavior. The booking may
carry the loan or project as a reference for reporting. Plan 38 already states that a link
between an asset and a product never changes stock by itself, and this plan keeps that rule.

### Scanning

Scan-to-check-out needs an asset label kind. Under ADR-0011 that is a `vctl:<uid>` label
over the `labels` mapping table. The kind is added by widening the three `CHECK` constraints
that `0283.pgsql.php` widened for the five existing kinds, and it uses the print and reprint
paths that [plan 32](landed/32-label-kinds.md) established. No new Grocycode type is added.

The check-out flow is: scan the asset, choose or scan the holder, confirm. Scanning a user
would need a user label kind as well, which question 5 covers.

Scanner clients retry on poor connections. Check-out and check-in should accept the
optional idempotency key that [ADR-0046](../adr/0046-stock-bookings-accept-an-optional-idempotency-key.md)
defines for stock bookings, so a retried check-out does not fail against the open-loan
constraint or record a second loan.

### Permissions

Loans need leaves in the [ADR-0057](../adr/0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
catalog. Plan 42 lists the catalog, and adding to it is a change to that plan. The candidates
are below. The parent is whichever domain plan 38 settles for assets.

| Candidate leaf | Allows | Read prerequisite |
|---|---|---|
| `ASSET_LOANS_READ` | Read open loans and history for assets the caller may see | Asset read |
| `ASSET_LOANS_CREATE` | Check out | `ASSET_LOANS_READ` |
| `ASSET_LOANS_RETURN` | Check in | `ASSET_LOANS_READ` |
| `ASSET_LOANS_UPDATE` | Correct a recorded loan | `ASSET_LOANS_READ` |
| `CONTACTS_READ` | Read a contact holder's details | None |

Self-service check-out, where a user is both issuer and holder, may need a narrower leaf than
`ASSET_LOANS_CREATE`. Question 5 decides that and whether `CHILD` holds it.

Loan reads must respect plan 38's per-asset visibility. A loan must not reveal a private
asset's name through a shared list, a calendar entry or the API.

### Due dates and reminders

Open loans with a due date appear in the calendar feed for users who may read them. This
covers reminders for the household without a new delivery channel. MQTT publication of an
overdue count through [plan 18](18-mqtt-state-publication.md) is possible later. Push
notifications wait on plan 38 question 6. Nothing is sent to a contact.

### API and wire contract

New endpoints are additive. Under [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md)
existing response shapes do not change. The OpenAPI document and the response-contract
snapshot are regenerated in the same change, per
[ADR-0024](../adr/0024-the-fork-writes-its-own-clients.md) decision 1. New migrations are
PostgreSQL-only per [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md).

There is no existing lending data to migrate. Existing equipment records gain loans only
after plan 38 has migrated them into assets.

## Dependencies

| Requirement | Why | Status on 2026-11-27 |
|---|---|---|
| Plan 38 stage A: household asset | A loan references an asset | Draft; gated on plan 38 questions 2–5 and 11 |
| Plan 38 contacts | Outside holders | Proposed in plan 38; not built. Not needed if question 1 limits holders to users |
| Plan 38 stage B: service records | Damage on return, calibration checks | Draft |
| Plan 38 stage C: boxes, grouped belongings | Kit membership | Draft; plan 38 question 5 open |
| ADR-0011 label namespace and plan 32's label kinds | Asset labels | Accepted and landed |
| ADR-0057 catalog, plan 42 | Loan leaves | ADR accepted 2026-11-27; catalog not yet implemented |
| ADR-0046 idempotency key | Safe scanner retries | Accepted; defined for stock bookings |

## Alternatives

| Option | Assessment |
|---|---|
| Holder and status columns on the asset | Recommended against. It keeps no history, so it cannot show who had a tool last or report repeated late returns. A kit's partial return also needs one record per member. |
| Model a loan as a stock transfer to a "lent out" location | Recommended against. Assets are not stock. Plan 38 forbids an asset link from changing stock, and a borrower is not a place. |
| Use chores or tasks as the loan record | Recommended against. A task can remind someone to return an item, but it has no holder, asset or condition. The calendar feed covers the reminder. |
| Build loans on the current equipment table before plan 38 | Recommended against. Equipment has no placement or per-copy identity, so loans would need migrating twice. Question 9 records this choice. |
| Separate loan record over plan 38's asset | Recommended. It keeps history, gives the open-loan constraint a single place, and leaves placement and stock alone. |

## Verification and success criteria

1. Checking out an asset that already has an open loan is refused. Repeating the same
   check-out with the same idempotency key returns the first loan and creates no second one.
2. Checking in a loan sets `returned_at` and `received_by`. The asset then shows as available,
   and the loan remains in its history.
3. A kit of four assets checked out together and returned three at a time leaves exactly one
   open loan. The overdue view lists that one asset.
4. A loan due yesterday appears in the overdue view and the calendar feed. A loan due today
   does not appear as overdue. A loan without a due date never does.
5. A user who may see a loan but holds no `CONTACTS_READ` sees that a contact holds the
   asset, without the contact's details.
6. A private asset's loan does not appear by name in another user's calendar feed, loan
   list or API response. Compare with a fixture holding a private and a shared asset.
7. Checking in an asset at a location other than its home placement records one move and
   changes no stock. Check the stock journal before and after.
8. Two copies of one ISBN edition can be on loan to different holders at the same time.
9. Scanning a printed asset label opens the check-out flow for that asset. A retired label
   is refused through the existing retirement behavior.
10. The suite's coverage figure is no lower after the change, per the floor in AGENTS.md.

## Open questions

1. Who can be a holder: household users only, or contacts without a login as well?
   Maintainer decision. Users only removes the contact dependency and `CONTACTS_READ`.
2. Is the tool crib for one household, or shared across several, such as a street or a
   makerspace? Recommendation: one installation. Sharing across installations is excluded
   above; a shared crib inside one installation depends on plan 38 question 2.
3. Does the library need reading status, series or a wishlist? Maintainer decision. These
   would attach to the asset or edition, not to loans.
4. Who receives due and overdue reminders, and on which channel: the calendar feed only, or
   also MQTT or a push client? Maintainer decision; push depends on plan 38 question 6.
5. May a user check an asset out to themselves, and does `CHILD` hold that right? Does
   scanning a person need a user label kind? Maintainer decision; shapes the leaves.
6. Should an overdue service or calibration warn at check-out or refuse it? Is condition free
   text or a fixed scale? Maintainer decision; needs plan 38 stage B.
7. Are reservations or holds needed, where someone queues for an asset that is out?
   Maintainer decision. Excluded until answered.
8. How is a kit defined: an explicit list at check-out, a plan 38 box, or a collection?
   Depends on plan 38 question 5.
9. Should a minimal version ship against the current equipment table before plan 38?
   Recommendation: no, because loans would migrate twice. Maintainer decision.
10. What happens to loan history when a contact or user is deleted, or an asset is disposed
    of? Depends on plan 38 question 8's retention rules.
