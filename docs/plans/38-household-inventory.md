# 38. Household inventory and the equipment rebuild

Rebuild Equipment around identifiable household belongings. Connect each belonging to
its documents, location, batteries, maintenance and eventual disposal. Reuse Victual's
stock ledger for consumables and its permission system for allowed actions.

Status: draft research, 2026-10-19. This plan captures the requested scope and recommends
a design; it accepts no architectural decision and schedules no implementation.
The [plan index](README.md) owns delivery status. No migration number is reserved.

## Problem and outcome

The current equipment register stores a name, description and manual. A household needs
to answer more questions: what it owns, where each item is, what it cost, what documents
prove ownership, what maintenance is due, and which batteries or supplies it requires.
Those records also support moving, insurance documentation and intended inheritance.

The requested scope covers all household belongings, including furniture, art, tools,
appliances and collections. A serial number is optional. Houses and storage units add a
property level above the existing location hierarchy. Records cover those premises,
their rooms and fixtures. Calling the feature Household inventory distinguishes it from the
stocktaking action already called inventory in Victual; final navigation naming is open.

## Research and current behavior

Source inspection on 2026-10-19 used working copy
`7c81b1b9b99259b12f7e03b59f4db3e13b88d4f3`. Reproduce the findings by inspecting the
named symbols, baseline tables, migrations and linked records at that revision.
This inspection did not measure performance or run the application.

| Existing capability | Evidence | Consequence for this work |
|---|---|---|
| Equipment has a name, description and one manual reference | `equipment` in `db/pgsql/baseline/01_tables.sql`; `EquipmentController::Overview` and `EditForm`; [equipment manual](../manual/using-victual/equipment.md) | The new lifecycle and relationships need structured storage. Userfields remain useful for optional details. |
| Batteries have free-text `used_in` and a separate charge journal | `batteries` and `battery_charge_cycles` baseline tables; `BatteriesService::GetBatteryDetails` | Preserve battery identity and charge history while replacing descriptive placement with an explicit relationship. |
| Chores provide schedules, assignments and execution history | [Chores](../manual/using-victual/chores.md), `ChoresService` | Investigate links to existing chores before introducing another recurrence system. Repairs and contractor projects need additional records. |
| Locations form a hierarchy | [Nested locations](landed/08-nested-locations.md), migration 0273 | Reuse spatial relationships where permission and stock semantics permit. Property ownership is not currently a location attribute. |
| Stock, shopping lists and minimum levels already manage consumables | [Stock](../manual/using-victual/stock.md), `StockService` | Project supplies must use existing bookings, units and undo behavior. |
| Equipment and battery reads require domain permissions | `EntityReadPolicy::PERMISSIONS`, [ADR-0035](../adr/0035-batteries-equipment-calendar-and-custom-entities-require-view-permissions.md) | A domain grant alone does not select which belongings a person may see. |
| Consumption recipes already use private rows and explicit shares | [ADR-0040](../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md), `ConsumptionRecipeService` | Reuse the rule that row rights and global permissions must both permit an action. Its extension to belongings requires a decision. |
| Files have storage and authorization machinery | `FilesService`, `FilesApiController`, [ADR-0048](../adr/0048-a-userfile-is-readable-only-by-callers-every-owning-record-admits.md) | New attachments need explicit owners and authorization on both file reads and reference changes. |
| Labels have durable opaque identity and a print outbox | [Label infrastructure](25-label-infrastructure.md), [label kinds](landed/32-label-kinds.md) | Belongings and boxes can use the label subsystem after their kinds and resolution permissions are defined. |

Under My Roof provides a relevant product comparison. Its
[official overview](https://undermyroof.app/) describes item and property records,
documents, maintenance, renovations, valuations, policies, claims and sharing.
The [developer's App Store listing](https://apps.apple.com/us/app/under-my-roof-home-inventory/id1524335878)
also describes moving boxes, heirs, custom fields, capture tools and CSV exchange.
These are vendor descriptions checked on 2026-10-19, not hands-on verification.

Its [FAQ](https://binaryformations.com/support/under-my-roof-faq/) notes that CSV does
not include photos and receipts. Victual should distinguish a tabular inventory export
from a complete backup containing attachments. The UI-based native backup file is
separate work in [plan 39](39-native-backup-file.md). Compatibility with an Under My Roof
export needs a real sample; this plan does not assume a documented interchange schema.

### Comparison applications

The comparison set includes Under My Roof, Encircle, Itemtopia, Sortly, Nest Egg and
BluePlum Home Inventory. Each supplies a different workflow to evaluate. The following
capabilities come from vendor documentation checked on 2026-10-19. They are not measured
rankings of usability, adoption or report quality. The proposed Victual behavior is our
design assessment, not a claim that each source implements the same rules.

| Application and source | Documented capability | Proposed Victual benchmark |
|---|---|---|
| [Under My Roof](https://undermyroof.app/) | Connects belongings and property records with documents, maintenance, renovations, valuations and insurance records. | Follow one belonging from acquisition through maintenance, a move and disposal without losing its history. |
| [Encircle contents training](https://www.getencircle.com/learning/contents-packouts/) | Documents rooms, item condition/disposition and boxed contents. Produces inventory spreadsheets, PDF photo reports and schedules of loss. | Keep a dated incident record with room context and item evidence. Generate a readable damage report and a corresponding item schedule from the same selection. |
| [Itemtopia home inventory](https://www.itemtopia.com/home-inventory-app/) and [document records](https://www.itemtopia.com/inventory-app-for-receipts-and-warranties/) | Connects items and spaces with receipts, warranties, manuals, notes and service history. Describes sharing selected information with family and others. | A family member can find an item and its permitted documents from its room or item page. Sharing must preserve the chosen record and document restrictions. |
| [Sortly features](https://www.sortly.com/features/) and [moving items](https://help.sortly.com/how-to-check-out-or-move-items-using-sortly) | Provides photos, folders, custom fields, QR/barcode labels and manual or scanned moves, including bulk moves. | Find belongings visually, scan an asset or box, and move the selected contents between a house and storage unit with a visible destination and recorded history. |
| [Nest Egg home inventory](https://nestegg.cloud/home-inventory/) and [its iOS listing](https://apps.apple.com/ca/app/nest-egg-inventory/id431188993) | Offers barcode-assisted product entry, photos, serial numbers, warranty alerts and product-expiry alerts. | Capture several items in one room with little repeated entry. Recover from an unknown barcode, and distinguish warranty reminders from stock expiry. |
| [BluePlum Home Inventory](https://theblueplum.com/mac/homeinventory/) and [backup support](https://theblueplum.com/mac/homeinventory/support.html) | Documents collections, tags, multiple properties, an iOS helper, filtered PDF reports and backups containing inventory information and files. | Maintain collection-specific details across properties and recover records and attachments from an archive under the operator's control. |

Product editions matter when testing these workflows. Nest Egg's
[pricing documentation](https://nestegg.cloud/pricing/) distinguishes the standalone
iPhone/iPad home inventory apps from Nest Egg Cloud. BluePlum's current site calls the
desktop product Home Inventory 4 and describes an iOS helper; that does not establish
equal functionality or independent editing across all Apple devices.

### Design consequences of the comparison

Evaluate the web and mobile designs with the same household fixture: two houses, a storage
unit, a collection, a box, repeated product models and documents with different visibility.
Record entry time, corrections, successful retrieval and report completeness. Vendor
feature lists alone do not establish which interaction is faster or easier.

1. Separate a general inventory report from an incident report. The incident report needs
   a loss date, property/room context, condition observations, captions and linked evidence.
   Preserve the captured state when an asset later moves or is repaired. These records
   support stage D; stage A's inventory PDF does not promise a complete claim workflow.
2. Attach records to the subject they describe: an item, room or property. A room's paint
   receipt and a storage unit's rental agreement must be discoverable without inventing
   equipment records. Parent access must not automatically expose a restricted attachment.
3. Provide photo browsing alongside searchable lists and full location paths. A bulk or
   scanned move must show the destination and the items affected. Knowing a printed code
   does not confer access to the asset or its box contents.
4. Support a short capture form, remembered room context and later enrichment. Unknown
   barcodes must allow manual completion. A repeated product barcode may describe another
   owned instance; it is not grounds for merging assets. Warranty dates belong to the
   individual asset, while consumable expiry remains in stock.
5. Keep PDF/CSV reporting separate from backup and restore. Database dumps remain the
   operational backup approach. [Native backup file design](39-native-backup-file.md)
   owns a future UI-based export/import workflow and does not gate this plan.

Public item webpages, contractor billing and commercial warehouse workflows are not
added to this scope merely because a comparison application offers them. Family access
continues to use Victual authentication and the scoped-sharing design. Existing stock,
label and permission contracts constrain the borrowed interaction ideas.

## Requirements

All nine requested areas remain in scope. The stages below propose delivery order,
not removal of later requirements.

| Area | Required behavior | Existing foundation and new work |
|---|---|---|
| Belongings | Record photos, receipts, purchase date and price, manufacturer, model, serial number, warranties, condition, category, tags and custom fields. Search by identity, description and placement. | Equipment, userfields and files; add identity, lifecycle, financial records and multiple attachments. |
| Mobile entry | Capture photos and receipts, scan identifiers, support voice entry and associate room scans with property records. Let the person review extracted information. | Native work belongs to `victual-kit`; server contracts support drafts, attachments, confirmation and retry. |
| Property records | Track houses and storage units above their locations. Record area with units, assessment history, room paint details, manuals, warranties and mortgage or rental documents where applicable. | Locations and files; add the property level and dated history. Multiple premises are required; see question 1. |
| Maintenance and repairs | Schedule recurring work, show due notices, record completion, costs, parts, photos and contractors. Track repairs from opening through completion. | Chores, tasks and calendar; add equipment/property links and service records. Notification delivery needs a defined client or worker. |
| Renovations and supplies | Track project status, budget, actual costs, contractors, before/after photos and supplies. Show low quantities through existing stock and shopping behavior. | Stock ledger and shopping lists; add project attribution without another quantity balance. |
| Insurance and claims | Record policies, effective dates, limits, deductibles, item schedules and evidence. Produce replacement-value summaries and explain estimates against entered policy terms. | New domain records and calculation rules; inventory reports are useful before a claims calculator exists. |
| Intended inheritance | Assign a person to a belonging or collection, with notes and explicit exceptions. | New contacts and assignments; a named heir need not have a login and gains no access merely by being named. |
| Moving | Record moves, boxes, contents, destination rooms and packing/unpacking state. Scan a box to list its permitted contents. | Locations and labels; add move and containment history. |
| Exchange and sharing | Preview CSV imports, export chosen records, generate PDF reports with evidence, and share with named household users. | RBAC and scoped-share precedent; add formats, row filtering and protected report artifacts. UI-based native backup export/import belongs to plan 39. |

Typical acceptance journeys are concrete. A person records a cordless drill, attaches its
receipt and manual, installs a tracked battery, and later moves that battery to a saw.
Another person packs the drill, scans its box after moving, and sees its destination room.
A report includes the drill's replacement estimate only when the caller may see its value.

## Proposed domain model

These are design candidates for review, not approved table names or endpoint contracts.
The recommended foundation is a household asset: one identifiable belonging with a
durable record. Stock products continue to describe quantities that can be purchased,
consumed and transferred. A link between an asset and a product never changes stock by itself.

| Record | Responsibility and relationships |
|---|---|
| Property | A top-level house or storage unit, its type, address, measurements and dated records. Contains the location hierarchy without exposing private documents through stock reads. The term includes rented premises. |
| Household asset | An identifiable belonging, its descriptive fields, lifecycle, current placement and acquisition information. Existing equipment records migrate into this model. |
| Category, tag and collection | Categories classify belongings; tags provide cross-cutting labels; collections group belongings for a purpose such as reporting or inheritance. Product-group taxonomy remains independent. |
| Attachment | A photo, receipt, manual, warranty or other file with explicit record references and access rules. A receipt may cover several items. |
| Valuation | A dated amount, currency, valuation basis and source. Purchase price, estimated replacement cost and assessed value remain distinct. |
| Battery installation | A tracked battery, asset, optional compartment, installation time and removal time. The battery retains its charge journal. |
| Service record and project | Work against an asset or property, linked to tasks or chores, documents, contacts and stock transactions where applicable. |
| Move and box | A relocation and its containers, with content and placement history. Packing changes containment; unpacking establishes the next placement. |
| Policy, claim and intended-heir assignment | Separate records referencing assets, collections or properties without changing their identity or automatically granting access. |

### Houses, storage units and locations

Houses and storage units are separate premises at the same level above rooms, shelves
and other nested locations. A storage unit does not have to belong to a house.
Multiple houses and storage units are part of the foundation scope, following the
maintainer's clarification in question 1.

Example paths are:

```text
Main house > Garage > Shelf A > Drill
Second house > Kitchen > Cupboard > Mixer
Storage unit 42 > Rack B > Box 7 > Winter decorations
```

Intermediate locations are optional. An item can be placed directly in a storage unit
without creating a fictitious room. Boxes retain the containment behavior described
below; rooms and racks use the location hierarchy.

Each location tree belongs to one property. A descendant inherits that property from
its tree, so it cannot identify a different property from its parent. The same location
name can occur in different properties. Search, selectors, labels and reports must show
enough of the path to distinguish them and support filtering by property.

Property records should hold a name, type and optional address. A storage unit can also
record its unit identifier, facility contact and rental agreement. House-specific fields,
such as assessment history, must not be mandatory for storage units. Physical placement
does not establish legal ownership of either the premises or its contents.

The property level must serve both assets and existing stock locations. Moving an asset
or box between properties preserves identity and history. Moving stock uses the existing
stock transfer path. Reparenting an occupied location across properties needs explicit
transfer semantics; it must not silently rewrite the recorded whereabouts of its contents.

The storage representation remains an architectural question: a separate property record
linked to location roots, or a typed root within the location tree. Migration must preserve
existing location identifiers, nesting, stock references and labels. Existing roots require
an explicit property assignment; their names are not evidence of a house or storage unit.

### Identity, placement and lifecycle

An asset can be acquired, used, loaned, packed, moved, sold, donated, lost or retired.
Archiving a disposed item preserves receipts, maintenance history and claim evidence.
Deletion and retention rules need explicit choices; they cannot be inferred from the
current equipment delete operation.

Model one current physical placement per asset. A box can contain several assets; moving
the box changes their effective location without copying or recreating those assets.
If nested boxes or asset components are supported, reject cycles and bound depth.
Collections do not establish physical containment.

Two identical televisions are separate assets because they can have different serial
numbers, warranties and destinations. Grouped quantities such as a set of twelve chairs
remain an open modeling choice: partial disposal and packing require split semantics.

### Batteries

An asset can have several installed batteries. A tracked battery can have at most one
active installation. Installing it elsewhere closes the previous installation in the
same transaction, or refuses the operation until the person confirms the transfer.
Concurrent writes must not install the same battery in two assets.

Removing or retiring an asset preserves installation history and charge cycles. Existing
`used_in` text remains available during migration; no automatic name match establishes
a relationship. Battery labels continue to resolve the same battery.

Record required size, chemistry and count separately from installed battery identities.
That supports a smoke detector needing two disposable cells without pretending that
each cell belongs in the rechargeable-battery journal. Replacing disposable cells can
consume stock and complete maintenance through the existing write paths.

### Maintenance, projects and stock

Prefer linking recurring maintenance to chores and one-off work to tasks where those
models fit. Service records add equipment context, labor, contractors, receipts and
actual completion details. A combined timeline can show these records without merging
their underlying histories.

Project plans may name expected supplies without booking anything. Recording actual use
requires the same stock permission and unit validation as a direct consumption.
Keep transaction references so undo reverses the relevant bookings once. Prevent receipt
totals, purchased supplies and consumed supplies from counting the same expense twice.

Linking a private asset to a shared chore or stock booking must not publish its name or
identifier through that record. Either filter the reference or keep the attribution in
a separate protected relation, following the consumption-recipe precedent.

### Money, insurance and inheritance

Unknown prices remain distinct from zero. Monetary records carry a currency; reports
must not sum mixed currencies without a stated conversion rate, source and date.
Manual conversion is a possible initial scope. No arbitrary outbound exchange-rate URL
is introduced.

Insurance estimates need dated item values, coverage categories, exclusions, scheduled
items, sublimits and deductibles. Each result must expose its inputs, valuation basis,
unsupported terms and calculation steps. Missing values produce an incomplete estimate.
A replacement-value total is not automatically an estimated claim payment.

Preserve the evidence used for a claim so later price or ownership changes do not rewrite
it. Intended-heir assignments record the household's wishes; they do not execute a legal
transfer. The treatment of collection changes and conflicting item assignments is open.

## Permissions and documents

Reuse RBAC for the actions a person may perform. Evaluate record visibility separately.
[ADR-0040](../adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) supplies
a precedent: effective authority requires both a global permission and a record right.
It does not already authorize household-wide sharing semantics for this new domain.

The permission design must distinguish reading descriptive inventory, reading financial
values or sensitive documents, editing, recording maintenance, sharing and exporting.
The exact leaves and their hierarchy remain open. `STOCK_PRICES_VIEW` must not silently
become permission to read mortgages, insurance terms or asset values.

Choose ownership and inheritance rules before implementation. Options include explicit
asset shares and a property-level grant inherited by its belongings. Moving an asset to
another property must not silently broaden access. Changing a location or box must not
change ownership as an accidental side effect.

Apply visibility before filtering, ordering, pagination and counts. Review direct reads,
generic object APIs, userfields, thumbnails, labels, calendars, search, reports, exports
and cross-domain references. A report must not disclose hidden items through subtotals.
Revoking a share must also prevent later downloads of retained report artifacts; a copy
already downloaded cannot be recalled.

Reuse file storage, with explicit attachment ownership. For existing `userfiles`,
[ADR-0048](../adr/0048-a-userfile-is-readable-only-by-callers-every-owning-record-admits.md)
requires every owning record to admit the reader and protects ownership-changing writes.
New attachment design must address shared receipts, inherited row restrictions, orphan
files, derivatives and atomic authorization. A new file group is not an exemption from
record visibility. Any changed accepted file policy needs its own superseding ADR.

## Product metadata lookup

Household inventory needs metadata for several product categories. Open Food Facts,
Inventaire and Brocade.io are candidates documented in
[plan 09's household lookup research](09-barcode-lookup-sources.md#household-inventory-lookup-candidates-2026-10-19).
That research records access limits, source status and the coverage experiment still
needed. It does not establish universal lookup coverage or choose a provider.

Use local inventory and previously retrieved metadata before an external lookup when
appropriate. A known product can prefill another asset's descriptive fields, but its
purchase date, serial number, warranty, condition and ownership remain instance-specific.
An ISBN identifies a book edition; several owned copies remain separate assets.

Lookup provides reviewable suggestions with source information. Preserve user corrections,
handle conflicting results, and allow manual entry for misses or unavailable providers.
Do not silently replace asset data when a provider changes its catalogue. Attribution
and image-use requirements must be retained through display and export.

Plan 09 owns provider evaluation. This plan owns the asset-field mapping and entry
experience; the current stock plugin's return fields are not already an asset metadata
contract. Provider selection does not gate manual entry in stage A. The household sample
must include books and non-food goods as well as pantry items before lookup is promised.

## Mobile and other clients

Apple Intelligence, camera capture, voice entry and room scanning belong in the native
client, as requested. The web application supports ordinary manual entry and review.
The server owns validation, access checks, persisted records and audit history.

Recognition produces suggestions for a person to review. A scan identifying an existing
asset differs from recognizing a product barcode: a GTIN identifies a product type and
cannot identify which of two identical televisions the person owns.
No recognized receipt or room scan books stock automatically.

[ADR-0012](../adr/0012-observations-are-proposals.md) governs probabilistic stock
observations. Its general proposal table and API remain unbuilt. Any feature submitting
such observations needs an owning implementation plan; a private asset-entry draft is
not a substitute for that contract.

Mobile writes need stable request identities, retry handling, revision checks and a
visible conflict path. Offline capture must not require destructive last-write-wins
updates. Define the supported offline scope with the client repository.

Update `victual.openapi.json`, response snapshots and `victual-kit` together for each
delivered stage. Review MCP and Home Assistant exposure separately: a relationship to
shared stock or a calendar does not authorize publication of private household records.
Notification delivery needs documented scheduling, cancellation and duplicate suppression;
existing due dates alone do not establish push delivery.

## Migration and exchange

Preserve equipment identifiers or retain an explicit mapping, including userfield
references and manual files. Keep existing routes and response shapes until any replacement
has explicit authorization under [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md).
The decision to rebuild Equipment does not itself authorize breaking every client route.

Migration must not invent owners, prices, purchase dates or battery links. Existing
equipment is shared within its domain; select its initial access policy explicitly.
Rehearse upgrades with empty records, missing manual files, custom fields and battery
descriptions that resemble several equipment names. New migrations are PostgreSQL-only.
The grocy SQLite importer must continue translating supported source fixtures into the
current model.

CSV import provides mapping, validation, duplicate candidates, a preview and a row-level
error report before applying changes. Stable import identities prevent a retried batch
from duplicating records. Define whether each import is atomic or reports partial
completion; silently skipping invalid rows is unacceptable.

CSV export carries the chosen scalar data and stable references. PDF reports carry the report date, selected
scope, valuation basis and available evidence. Export filters follow the same permissions
as interactive reads. Spreadsheet-oriented CSV output must handle formula-like values.

An authorized user's partial export and an operator's complete backup serve different
purposes. A partial export must not reveal hidden records, account data or sharing metadata.
The existing [backup and restore procedure](../manual/operator/backup-restore.md) uses
PostgreSQL dumps, accompanied by the storage directory when files reside on disk.
New household records must remain recoverable through that procedure. A UI for an
exportable and importable native backup file is separately schedulable work in
[plan 39](39-native-backup-file.md); this plan neither builds it nor waits for it.

## Delivery stages and decision gates

| Stage | Outcome | Gate |
|---|---|---|
| A: foundation | Belongings; houses and storage units above locations; documents, purchase details, warranties, categories/tags, battery links and scoped access; CSV exchange and a basic inventory PDF | Question 1 establishes property scope. Resolve questions 2–5 and 11 and record the resulting architectural decisions in Proposed ADRs. Obtain separate acceptance before implementation. Rehearse legacy migration and permission boundaries. |
| B: care and projects | Maintenance schedules, service history, contractors, repairs, renovation costs, stock-linked supplies and notifications | Decide chore/task integration, stock undo attribution and notification delivery. Foundation records and access checks must exist. |
| C: moving and household records | Boxes and moves, detailed property history, collections and intended-heir assignments | Decide containment, grouped-quantity behavior, retention and assignment precedence. Extend label kinds through existing label contracts. |
| D: insurance | Policy records, claims evidence and explainable coverage/replacement estimates | Agree supported policy terms and calculation examples, including incomplete data and overlapping limits. Foundation reports can ship earlier. |
| E: native capture | Receipt/photo recognition, voice entry and room scans | Coordinate server contracts and mobile capabilities. Ordinary scanning/manual capture can follow A; probabilistic stock observations require ADR-0012 infrastructure. |

Stages describe dependencies, not dates. Native work can overlap later server stages once
the relevant contracts exist. No existing plan or Proposed ADR is promoted to Accepted
by this work. In particular, database-logic and persistence proposals are not prerequisites
merely because they concern the same tables.

## Alternatives

Adding everything as equipment userfields would preserve the present form but would not
provide enforced battery relationships, placement history or transactional sharing rules.
Userfields remain appropriate for details that do not control behavior.

Representing every belonging as stock would reuse quantity operations but would couple
individual warranties, serial numbers and disposal history to stock merge and undo rules.
The proposed asset record avoids that coupling while allowing explicit product links.

A separate inventory application could provide some requested functions sooner, but would
need synchronization for users, batteries, locations and supplies. Rebuilding Equipment
keeps those relationships in Victual at the cost of new domain services and migration work.

## Verification and success criteria

The implementation's acceptance evidence must demonstrate these outcomes:

1. Every equipment row in an upgrade fixture remains identifiable with its description,
   userfields and manual reference. No price or battery relationship is invented.
2. Two batteries can serve one asset. A concurrent attempt to install one battery in
   different assets produces only one active installation. Transfers preserve charge history.
3. A named family member sees only shared records. Removing a share prevents subsequent
   access through files, labels, generic APIs, calendar output and report downloads.
4. A private mortgage document stays hidden from an inventory reader. A shared receipt
   cannot become readable by removing a protected reference without authorization.
5. A maintenance completion consumes the selected supplies once. Retry and undo preserve
   the stock ledger and project cost attribution without duplicate expense totals.
6. Packing, moving and unpacking preserve asset identity and evidence. Invalid containment
   cycles and ambiguous grouped-quantity splits are refused before any partial write.
7. Insurance fixtures distinguish purchase price from replacement value, missing amounts
   from zero, and supported calculations from unsupported policy terms.
8. CSV preview catches invalid references and duplicate candidates. Repeating an import
   is safe under its documented policy. Exports include only authorized records and fields.
9. Mobile retry creates one record. A stale edit gives a visible conflict. Recognition
   requires review, and no uncertain observation changes stock directly.
10. Application tests use PostgreSQL, SQL invariants use pgTAP, and browser probes cover
    the new workflows under [ADR-0025](../adr/0025-three-test-tiers.md). Each implementation
    preserves the current aggregate coverage ratchet and the per-file floor.
11. A fixture contains two houses and a storage unit with repeated location names.
    Paths and property filters distinguish them. Direct placement in the storage unit
    works without a room, and a descendant cannot belong to a different property from
    its parent. Migration preserves existing location identifiers, labels and stock links.
12. Moving an asset or packed box from a house to a storage unit preserves identity,
    contents and history without broadening access. Stock transfers preserve ledger
    history. Reparenting an occupied location cannot bypass those transfer rules.
13. An incident report includes the selected property's rooms, item condition, evidence
    captions and an item schedule. Later repairs or moves do not rewrite the incident
    record. Hidden items and restricted documents do not appear in the output.
14. Capture works for an unknown barcode and two assets sharing a product barcode.
    Room context persists between entries. Editing one asset's warranty does not change
    the other asset's warranty or any stock expiry date.
15. A permitted user finds item, room and property documents through their respective
    pages. Restricting a document prevents disclosure through parent pages and reports.
16. Restore a database dump and any filesystem-backed uploads through the existing
    operator procedure. Verify the new household records, relationships and attachments.
    Native backup-file export/import verification belongs to plan 39.
17. Metadata lookup rejects a wrong returned identifier and keeps separate copies of the
    same product or book edition. Provider misses, timeouts and rate limits leave manual
    entry available. Refresh preserves user edits, provenance and attribution.

Use an agreed representative household fixture to measure entry time, item retrieval,
report generation and migration completeness before and after implementation. Record its
size, revision and procedure with the results. No latency or adoption baseline is claimed
by this draft, and no usage telemetry is required to establish these acceptance results.

## Open questions

1. What is the initial property scope: several properties in one household, or one property
   initially?

   > **Response:** The maintainer specified houses and storage units as another level in
   > the location hierarchy on 2026-10-19. The foundation must support multiple premises
   > above the existing locations. Storage representation and migration remain question 11.

2. Does sharing apply to individual assets, whole properties, collections, or a combination?
   Who owns legacy shared equipment, and does administration grant access to private records?
   Maintainer decision; blocks the access-policy ADR and migration.
3. Which information can be shared separately: values, receipts, mortgages, insurance and
   intended-heir assignments? Recommendation: a basic inventory share must not imply all of
   these. Maintainer decision; blocks the permission hierarchy and document policy.
4. Does a tracked battery identify one cell, one pack, or sometimes a set? Should transferring
   an installed battery require explicit confirmation, and should compartment occupancy be
   enforced? Maintainer decision; blocks installation constraints.
5. Should grouped belongings carry a quantity, or should each independently movable object
   have its own record? How are fixtures and replaceable components represented?
   Maintainer decision; blocks identity, containment and migration choices.
6. Which maintenance patterns exceed existing chores/tasks, and which clients deliver
   notifications? Engineering and maintainer decision; blocks stage B's integration design.
7. What initial insurance calculations and currencies are required? Supply representative
   policy terms and expected results before committing to a claims calculator.
   Maintainer input; blocks stage D, not the basic inventory report.
8. Do intended-heir assignments follow later collection membership changes, and does an
   individual assignment override a collection assignment? What happens to records after
   disposal or account deletion? Maintainer decision; blocks the relevant lifecycle rules.
9. Is importing an existing Under My Roof inventory required for the first release?
   A representative export is needed to assess field mappings and attachment recovery.
   Maintainer input; generic CSV work does not promise source-specific compatibility.
10. Which offline and recognition functions belong in the first mobile release, and what
    device capabilities must it support? Client-maintainer decision; blocks stage E scope.
11. Should the property level use a separate record linked to location roots or a typed
    root in the location tree? How should existing roots be assigned, and how should an
    occupied location move between properties? Engineering and maintainer decision;
    blocks the hierarchy ADR and migration. Houses and storage units are required
    regardless of the storage representation.
12. Do existing operator backups meet complete household-inventory recovery needs, or is
    a portable domain archive also required?

    > **Response:** The maintainer confirmed on 2026-10-19 that backup and restore are
    > based on database dumps. A UI-based version is a separate item that can be scheduled.
    > Add an exportable and importable native backup file as a design. Plan 39 owns that
    > design; it does not gate the equipment rebuild.
