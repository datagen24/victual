# Project glossary

This is the shared vocabulary for Victual's documentation, reviews, and development.
Entries define project terms and link to the records or guides that explain their use.
Accepted ADRs govern decisions; the [plan index](plans/README.md) governs delivery status.
A definition does not establish that a feature has shipped.

## Maintaining the glossary

Use these names consistently. Add or update an entry in the same change that introduces
or changes a project term. Include relevant code identifiers, distinguish commonly
confused terms, and link to the authoritative source. Keep entries alphabetical within
each section. Link to definitions from other documents instead of maintaining another
glossary; brief explanations where a term first appears remain useful.

Changing a definition does not change an accepted decision. Follow the
[ADR lifecycle](adr/README.md#lifecycle) when a terminology change would alter its meaning.

## Stock and household data

### Allocation

Proposed in [ADR-0036](adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md) (Proposed, not implemented). The signed amount of one lot that one
booking added, removed, or moved. Allocations record which addition booking a quantity is
charged to; they are accounting attribution, not physical provenance.

### Booking

A recorded stock operation, such as a purchase, consumption, transfer, inventory
correction, or opening. The stock journal records booking history and supports undo.
See [Stock](manual/using-victual/stock.md).

### Consumption recipe

Defined in [ADR-0040](adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) (Accepted 2026-10-09, implemented by issue 698).
An owned list of product lines and quantities consumed together, such as a prescription.
It is separate from the food `recipes` table and visible only to its owner and users it is
shared with. See [plan 22](plans/22-medication-tracking.md).

### Consumption event

Defined in [ADR-0041](adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md) (Accepted 2026-10-09, implemented by migration 0306 and the `/api/consumption/events` routes).
A record that one consumption happened, identified by the owning user, a source system and a
source event id. It books stock once through the stock write paths and has a state such as
`booked`, `needs_review` or `undone`. A client such as `victual-kit` submits events for
medication doses; a recipe consumption in Victual creates a `manual` event.

### Contribution

Proposed in [ADR-0036](adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md) (Proposed, not implemented). The amount of one lot currently held by one
stock entry. A merged entry has one contribution per lot. A contribution with no lot is the
unattributed pool of quantity merged before lineage was tracked.

### Directed substitution

A relationship declaring that one product can substitute for another. The reverse
relationship does not follow automatically. Explicit relationships use
`product_substitutions`; packaging relationships also contribute to the resolved view.
See [ADR-0023](adr/0023-taxonomy-is-groups-packaging-is-parent-product.md).

### Inventory

In a stock action, setting the recorded amount to a counted value. Use **stock** for
what the household holds, and **inventory correction** or **stocktaking** for this action
when the context could be ambiguous. See [Stock](manual/using-victual/stock.md).

### Location

A place where stock is stored, represented by `locations`. Locations can nest, such as
a shelf inside a fridge. A location can also represent a refillable vessel.
A shopping location identifies a store. See [Stock](manual/using-victual/stock.md).

### Lot

Proposed in [ADR-0036](adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md) (Proposed, not implemented). The units introduced by one addition booking,
identified by that booking's `stock_log.id`. A lot is not a stock entry: a merge puts several
lots in one entry, and a split puts one lot in several. The manual's **batch** means a stock
entry.

### Master data

Reusable records that describe household items and their configuration, such as products,
locations, quantity units, and product groups. These records are distinct from bookings
that record activity. `MASTER_DATA_EDIT` is the permission used for editing master data;
individual operations can require additional permissions.
See [Roles and permissions](manual/operator/roles-permissions.md).

### Measured remainder

The measured contents left in one opened container, stored on its stock entry with a unit
and timestamp. The container still counts as one stock unit; its remainder is separate
from `stock.amount`. See
[ADR-0022](adr/0022-open-containers-carry-a-measured-remainder.md).

### Parent product

The product referenced by `products.parent_product_id`, expressing a packaging
relationship one level deep. Product groups express taxonomy. Coffee beans and coffee
grounds are separate products, not packaging variants of one parent.
See [ADR-0023](adr/0023-taxonomy-is-groups-packaging-is-parent-product.md).

### Product

A catalogue record in `products` describing an item, its units, defaults, and configuration.
A product can exist with no stock. Its stock entries record the quantities held.
See [Stock](manual/using-victual/stock.md).

### Product group

A category in `product_groups`. Groups can nest to express taxonomy, such as pantry goods
and baking ingredients. Group membership does not express packaging or substitution.
See [ADR-0023](adr/0023-taxonomy-is-groups-packaging-is-parent-product.md).

### Quantity unit

A unit of measure in `quantity_units`, such as a bag or gram. A product's stock unit is
identified by `qu_id_stock`; purchase units can differ through quantity-unit conversions.
See [Stock](manual/using-victual/stock.md).

### Share (consumption recipe)

Defined in [ADR-0040](adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md) (Accepted 2026-10-09, not implemented).
A row granting one user a set of rights (`read`, `consume`, `edit`, `undo`, `share`) on one
consumption recipe. A share narrows which recipes a user sees and confers no permission.

### Refill estimate

Defined in [ADR-0042](adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md) (Accepted 2026-10-09, not implemented).
The calendar date on which a prescription's reorder is estimated to be due, derived from the
last recorded fill and a rule. It is approximate, carries the rule that produced it, and is
separate from stock on hand. It does not state that an insurer will approve a refill.

### Shopping location

A store or other source of purchases, represented by `shopping_locations`. Purchase
records and price history use it. Use **location** for where stock is stored at home.
See [Stock](manual/using-victual/stock.md).

### Stock

The quantities of products held by the household. Product totals and individual stock
entries are different views of that stock. See [Stock](manual/using-victual/stock.md).

### Stock entry

An individual record in `stock`, carrying a product, amount, location, due date, and
opened state. The manual also calls entries **batches**. An entry can contain multiple
stock units; an entry with a measured remainder describes one opened container.
See [Stock](manual/using-victual/stock.md) and
[ADR-0022](adr/0022-open-containers-carry-a-measured-remainder.md).

### Stock journal

The booking history shown at `/stockjournal`, backed by `stock_log`. **Stock ledger**
refers to this recorded history in architectural discussions.
See [Stock](manual/using-victual/stock.md).

### Tare

The empty container's weight, subtracted from gross weight to obtain net contents.
A purchased container carries its tare on the stock entry; a refillable vessel carries
its tare and unit on the location. See
[ADR-0022](adr/0022-open-containers-carry-a-measured-remainder.md).

### Working container

A refillable vessel represented by a location, such as a flour bin. Replenishment transfers
stock into that location. **Backstock** is the stock held elsewhere to replenish it.
See [ADR-0022](adr/0022-open-containers-carry-a-measured-remainder.md).

## Labels and printing

### Artifact

In label printing, retained rendered bytes used for a print job or preview. A reprint
uses those retained bytes. See
[ADR-0021](adr/0021-label-templates-are-application-data.md).

### Grocycode

The legacy `grcy:*` barcode payload format. Victual continues to parse it but does not
emit it. New labels use the `vctl:` namespace. See [grocycode](grocycode.md).

### Label

An opaque identity mapped to an entity, encoded as `vctl:<uid>`. The UID contains 13
uppercase Crockford base32 characters; the payload does not expose a database row ID.
A label identity is distinct from a print job and can survive multiple print requests.
See [ADR-0011](adr/0011-label-namespace.md).

### Label kind

The entity type a label identifies: `location`, `product`, `stock_entry`, `recipe`,
`chore`, or `battery`. See [Label printing](manual/operator/label-printing.md).

### Label template

Application data describing a label's layout and content, managed through the label
designer. See [ADR-0021](adr/0021-label-templates-are-application-data.md).

### Print job

A queued request to deliver a label artifact to a printer. Its delivery state does not
change the label's identity; cancelling a job does not void that identity.
See [Label printing](manual/operator/label-printing.md).

### Reprint

A new print job using retained artifact bytes. It does not repeat the original booking
or capture fresh entity data. See
[ADR-0021](adr/0021-label-templates-are-application-data.md).

### Retirement event

Defined in [ADR-0037](adr/0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md) (Proposed) and implemented by migration 0303. One row in `stock_label_retirements` for each retirement of a
`stock_entry` label. It keeps the retirement snapshot, the cause and, for a whole-row
consumption, the booking and the deadline for revival. It is permanent history.

### Revised print

A print request that captures updated entity data while keeping the label identity.
See [Label printing](manual/operator/label-printing.md).

### Revival

Defined in [ADR-0037](adr/0037-an-undo-of-a-whole-row-consumption-revives-the-stock-entry-label-it-retired.md) (Proposed) and implemented with migration 0303. A retired `stock_entry` label becomes live again on the
row that the undo of its consuming booking rebuilds under its original id, within 30 days of the retirement. The undo's
notice and its `Victual-Label-Revival` response header report it. It is not a reprint
and not a general reassignment of a label to other stock. See [Label printing](manual/operator/label-printing.md).

### Worker

In label printing, a separately deployed process that claims jobs through the authenticated
worker API and delivers them to printers. Worker credentials are separate from household
user accounts. See [Label printing](manual/operator/label-printing.md).

## Permissions and contracts

### Effective permissions

The union of a user's direct grants and assigned role grants, expanded through the
permission hierarchy. There are no deny grants.
See [Roles and permissions](manual/operator/roles-permissions.md).

### Feature flag

An installation setting that enables or disables a feature. Enabling a feature does not
grant a user permission to use it.
See [Roles and permissions](manual/operator/roles-permissions.md).

### Observation proposal

A proposed booking from a client supplying a confidence value. A person must confirm it
through the existing booking permissions; there is no automatic confirmation threshold.
[ADR-0012](adr/0012-observations-are-proposals.md) accepts this model. The
[plan index](plans/README.md) records it as unbuilt and unscheduled.

### Role

A named bundle of permission grants assigned to users. Role grants add to direct grants;
assigning a narrower role does not remove permissions a user already holds.
See [Roles and permissions](manual/operator/roles-permissions.md).

### Wire contract

The API response shapes and values clients depend on, governed by Victual's own OpenAPI
specification and recorded exceptions. See
[ADR-0005](adr/0005-wire-contract-is-the-invariant.md).

## Deployment

### Chart

A Helm package: templates, a default values file and a schema, versioned and installed as a
release. Proposed for Victual's Kubernetes deployment in
[ADR-0038](adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md); none is built.

### Companion add-on

A Home Assistant add-on that runs one Victual workload beside the main add-on, such as the
label renderer, the label delivery worker, or the MCP server. Proposed in
[plan 35](plans/35-home-assistant-target.md); none is built.

### Ingress

Home Assistant's authenticated reverse proxy for add-on web interfaces. It serves each
add-on under an installation-specific path prefix, sends that prefix in `X-Ingress-Path`,
and displays the page in a frame. Not Kubernetes Ingress, which `deploy/` also mentions. See
[plan 35](plans/35-home-assistant-target.md).

### Kubernetes operator

A controller that runs in the cluster and reconciles a custom resource, such as a
database operator managing failover. Not the person running a deployment, whom `deploy/`
and the manual also call the operator. Considered and rejected for Victual in
[ADR-0038](adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md).

### Supervisor

The Home Assistant OS component that installs add-ons from `config.yaml` manifests, writes
their options to `/data/options.json`, and starts, stops, and watches their containers. See
[plan 35](plans/35-home-assistant-target.md).

## Project records

### ADR

An architecture decision record. **Proposed** means under review; **Accepted** means the
maintainer approved the decision through the ADR lifecycle. Acceptance does not establish
implementation or verification. See [Architecture decision records](adr/README.md).

### Plan

A document describing proposed work, its design, dependencies, and verification criteria.
Its **Executed** section records what shipped and any departures. The
[plan index](plans/README.md) owns delivery status and work order.
