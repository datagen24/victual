# Label printing

One path. Every kind Victual can label — locations, products, stock entries, recipes,
chores and batteries — prints through the same subsystem; there is no separate webhook path
left to configure.

## The label subsystem (plans 25, 27 and 32)

Set `FEATURE_FLAG_LABELS` to `true` and `FILE_STORAGE` to `database`
([Configuration](../configuration.md#feature-flags) — the second is required by the first
and checked at startup). A label is an opaque identity (`vctl:<uid>`, resolved by a mapping
table Victual owns rather than an encoded row id), a print request enqueues a job, a delivery
worker claims and renders it, and a reprint replays the retained artifact rather than
re-running whatever booking produced it.

**In the browser:**

- **`/labeltemplates`** and **`/labeltemplate/{id}`** — the label designer. Requires
  `ADMIN`, since templates and printers are administration while printing itself is not.
  A template is validated against its own document model on every save; the designer
  cannot produce anything the validator would reject. Creating a template asks for the
  entity kind it is for — one of the six above.
- **`/labelprinters`** — register and edit workers and printers, issue credentials or
  pairing material, and revoke worker credentials. Requires `ADMIN`.
- **`/labelprintjobs`** — the job queue, with the printer each job targets. States include
  `awaiting_artifact`, `queued`, `claimed`, `sent`, `reported`, `failed`, `blocked`,
  `uncertain`, `uncertain_but_reported`, `awaiting_authorization`, `cancelled` and
  `dead_lettered`. A `printed` outcome appears as `reported` in this view.
- A print action on each kind's own page: the product form, the stock entries list and
  form, the recipe form and list, the chore form and overview, and the battery form and
  overview — a printer picker, and a button that requests a label for that one record.

**Five operations** are available under `/api/labels`:

| Operation | POST path |
|---|---|
| Print a new label | `/api/labels/{kind}/{id}/print` |
| Print revised data with the same identity | `/api/labels/{kind}/{id}/revised-print` |
| Reprint retained bytes | `/api/labels/jobs/{jobId}/reprint` |
| Cancel a job | `/api/labels/jobs/{jobId}/cancel` |
| Promote a preview to a print job | `/api/labels/artifacts/{artifactId}/promote` |

`kind` is `location`, `product`, `stock_entry`, `recipe`, `chore` or `battery`.
The entity pages expose printing without typing these paths. Locations also retain
`/api/labels/locations/{locationId}/print` and `/revised-print` under the same prefix.
Printing new or revised data requires `MASTER_DATA_EDIT` *and* the domain read permission
for what is being printed — `STOCK_VIEW`
for a location, product or stock entry, `RECIPES_VIEW`, `CHORES_VIEW`, or `BATTERIES` —
because printing a physical, hard-to-recall label is treated as editing master data, not as a
read. A reprint, a promotion and a cancellation check only `MASTER_DATA_EDIT`: none of the
three reads a fresh value, so the domain grant a capture needs does not apply. A print call
answers 202: the job exists, but it is not finished until a worker has produced bytes Victual
has verified.

**Purchasing.** The purchase and inventory-correction forms carry their own "label per
booking" / "label per unit" choice (the `stock_label_type` field); when a booking adds stock
with either option set, the booking issues the label and enqueues its print job itself, in
the same transaction as the stock entry and its booking — a purchase that cannot label
succeeds at neither. No printer picker exists on those forms: the job goes to the default
printer (the first active one) and the `stock_entry` kind's default template. A product
whose `auto_reprint_stock_label` setting is on and whose due date changes because it was
opened, frozen or thawed gets its label reprinted (a revised print, same identity) only if
one was already printed for that entry; opening or moving stock never mints a first label on
its own.

**Registering a printer and a worker.** Use the forms on `/labelprinters` (`ADMIN`).
The worker form registers a worker in declared or paired mode; its buttons issue a
credential or pairing material and revoke credentials. The printer form registers a
printer for a worker. The same operations are available through the administrative API:
`label-admin-printer-*`, `label-admin-worker-*`, `label-admin-issue`,
`label-admin-pairing` and `label-admin-revoke` in `routes.php`.

A worker rotates its own credential through `POST /api/labels/credentials/rotate`
(`labels-rotate`), authenticating with that credential. Worker credentials are independent
of household user accounts and authorize the worker protocol for claiming jobs, submitting
results and reporting printer status. Deploy and pair the worker/renderer software
separately from Victual; see [Deployment](../../../deploy/README.md) for these workloads.
