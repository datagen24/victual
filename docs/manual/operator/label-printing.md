# Label printing

Two independent paths exist. They do not share configuration, and enabling one has no
effect on the other.

## The label subsystem (plans 25 and 27)

Set `FEATURE_FLAG_LABELS` to `true` and `FILE_STORAGE` to `database`
([Configuration](../configuration.md#feature-flags) — the second is required by the first
and checked at startup). This is the current path: a label is an opaque identity
(`vctl:<uid>`, resolved by a mapping table Victual owns rather than an encoded row id), a
print request enqueues a job, a delivery worker claims and renders it, and a reprint replays
the retained artifact rather than re-running whatever booking produced it.

**In the browser:**

- **`/labeltemplates`** and **`/labeltemplate/{id}`** — the label designer. Requires
  `ADMIN`, since templates and printers are administration while printing itself is not.
  A template is validated against its own document model on every save; the designer
  cannot produce anything the validator would reject.
- **`/labelprinters`** — the configured printers.
- **`/labelprintjobs`** — the job queue: pending, delivered and dead-lettered jobs, with
  the printer each targeted.

**Printing a label** is one of four operations on
`POST /api/labels/locations/{locationId}/print` (or `revised-print`, `jobs/{id}/reprint`,
`jobs/{id}/cancel`) — reached from the relevant entity's own page rather than typed by hand.
Each requires `MASTER_DATA_EDIT` *and* the domain read permission for what is being printed
(`STOCK_VIEW` for a location), because printing a physical, hard-to-recall label is treated
as editing master data, not as a read. A print call answers 202: the job exists, but it is
not finished until a worker has produced bytes Victual has verified.

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

**What still uses the older webhook.** Five entity types — products, stock entries,
recipes, chores and batteries — still print through the path below rather than through this
subsystem; migrating them needs a wire-contract record of its own and is deliberately
unscheduled (see [ADR-0011](../../adr/0011-label-namespace.md)). Enabling
`FEATURE_FLAG_LABELS` does not change how those five print.

## The webhook (legacy)

Set `FEATURE_FLAG_LABEL_PRINTER` to `true` and configure `LABEL_PRINTER_WEBHOOK`
([Configuration](../configuration.md#label-printer-webhook)) to a URL you control. Whenever
one of the five entity types above is printed, Victual sends that URL one POST request:

```
POST /your/printing/api/endpoint HTTP/1.1

product=<productname>&grocycode=grcy:x:xxx&due_date=DD:%2021-06-09&...
```

— as JSON if `LABEL_PRINTER_HOOK_JSON` is true, or as ordinary form fields otherwise, with
whatever extra parameters `LABEL_PRINTER_PARAMS` adds (e.g. a font family). `LABEL_PRINTER_RUN_SERVER`
chooses whether Victual's own server sends the request or the browser does, by AJAX — use
the browser path only when the server hosting Victual cannot reach the printer target
itself. Your endpoint is responsible for laying the label out and sending it to the printer;
Victual has no opinion on the label's design in this path. It was developed and tested
against a Brother QL-600 on Brother DK-2205 endless 62 mm tape, using
[a fork of brother_ql_web](https://github.com/mistressofjellyfish/brother_ql_web) as the
receiver.

This path is retired under [ADR-0011](../../adr/0011-label-namespace.md); it
prints the `grcy:` Grocycode rather than the opaque `vctl:` identity the subsystem above
uses, and no new work should extend it.
