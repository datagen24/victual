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
- **`/labelprinters`** — the configured printers.
- **`/labelprintjobs`** — the job queue: pending, delivered and dead-lettered jobs, with
  the printer each targeted.
- A print action on each kind's own page: the product form, the stock entries list and
  form, the recipe form and list, the chore form and overview, and the battery form and
  overview — a printer picker, and a button that requests a label for that one record.

**Printing a label** is one of four operations on `POST /api/labels/{kind}/{id}/print` (or
`revised-print`, `jobs/{id}/reprint`, `jobs/{id}/cancel`), where `kind` is `location`,
`product`, `stock_entry`, `recipe`, `chore` or `battery` — reached from the relevant entity's
own page rather than typed by hand (locations keep their own longer-standing
`/api/labels/locations/{locationId}/print` path, which is the same operation). Each requires
`MASTER_DATA_EDIT` *and* the domain read permission for what is being printed — `STOCK_VIEW`
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

**Registering a printer and a worker.** There is no browser form for this yet — it is done
through the administrative API (`ADMIN`), under the routes named `label-admin-printer-*` and
`label-admin-worker-*` in `routes.php`, reachable from the [API browser](rest-api.md) at
`/api`. A worker authenticates with its own credential (issued through
`label-admin-issue`/`label-admin-pairing`, rotated through `label-admin-rotate`, and
revocable independently of any household user account) and claims jobs, submits results and
reports printer status through its own `/api/labels/*` endpoints — a separate protocol from
the one a browser or a `VICTUAL-API-KEY` uses. This manual does not walk through pairing a
specific physical worker step by step: no such operator runbook exists in the repository as
of this writing, and the worker/renderer software is deployed and paired independently of
Victual itself — see [Deployment](../../../deploy/README.md) for where those
workloads sit.
