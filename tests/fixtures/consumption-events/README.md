# Consumption event fixtures

Server-side fixtures for the external consumption API of
[ADR-0041](../../../docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md)
(issue 700). Each file is one scenario: the requests a client sends, in order, and what Victual
answers, so the `victual-kit` team can read them and replay them against a server of their own.

**What these show and what they do not.** They show Victual's behavior: for this sequence of
requests the server answers this, and stock ends here. They do **not** show that Apple Health
produces these requests. The ids are made up, the timings are chosen, and no device was involved.
Which fields HealthKit really delivers, what identifier a sample keeps when the person edits it
and how late a deletion arrives is device evidence, recorded under
[issue 702](https://github.com/datagen24/victual/issues/702) and not here. A passing fixture is
not evidence that the real Apple client has synchronized successfully.

## Running

```
.devtools/pgsql/run-tests.sh consumption
```

runs the whole `consumption` suite, which includes `tests/Pgsql/ConsumptionEventFixtureTest.php`.
That class reads every `*.json` here, builds a world for it in a throwaway PostgreSQL schema, sends
each step through the whole middleware stack (one PHP process per request, the way the server runs;
the user is the API key's owner) and checks the answer. A failing assertion names the file and the
step, e.g. `09b-deletion-needing-a-decision.json step 6 (An unknown reason is refused)`, with the
request and the response. To run only the fixtures, run PHPUnit with
`--testsuite consumption --filter ConsumptionEventFixtureTest`.

A fixture cannot show two identical requests at the same instant, because steps are sequential.
Row 3 of the table is therefore shown only as a sequential repeat (`03-same-request-twice.json`);
the concurrent half is `tests/Pgsql/ConsumptionEventRaceTest.php`.

## File format

One scenario per file, `NN-slug.json`, UTF-8, tab-indented.

```json
{
	"title": "…", "adr_sequence": "9b", "description": "…",
	"setup": {
		"locations": ["organizer_a", "organizer_b"],
		"products": { "tablet": { "stock": { "organizer_a": 10, "organizer_b": 5 } } },
		"users": ["alice", "bob"]
	},
	"steps": [
		{
			"as": "alice",
			"note": "what this step is for",
			"request": { "method": "PUT", "path": "/api/consumption/events/healthkit/6F1C…", "body": { "…": "…" } },
			"expect": { "status": 201, "body": { "state": "needs_mapping" }, "count": { "": 1 } },
			"save": { "tx": "transaction_id" },
			"stock": { "tablet": 9, "tablet@organizer_a": 9 }
		}
	]
}
```

* `setup.locations` are names; `setup.products` maps a name to its stock per location. A number is
  one purchase; a list is one purchase per element, in order (separate lots, see "Known behaviour").
  `setup.users` defaults to `["alice"]`; `alice` is the default actor (`as`). Every user can view,
  consume, edit and purchase stock. Each fixture gets locations, products and users of its own, so
  two fixtures may use the same `source_event_id`.
* `request.path` may carry a query string. A body is sent as `application/json`.
* `expect.status` is checked exactly. `expect.body` is a **subset** match: an object matches on the
  keys the fixture lists, recursively; a list matches element by element for the elements listed;
  an empty list `[]` means "an empty list"; the string `"{{any}}"` matches any value that is present;
  numbers compare as numbers (`1` equals `1.0`).
* `expect.count` maps a dotted path in the response (`""` is the response itself) to the expected
  number of elements, for lists whose length matters.
* `save` stores values from the response under a name, by dotted path (`lines.0.product_id`), for
  `{{saved.NAME}}` in later steps.
* `stock` is checked after the step: `NAME` is the on-hand total of a product, `NAME@LOCATION` the
  amount at one location. This reads the database, not the API.
* The undo a user performs in the stock journal is an ordinary step
  (`POST /api/stock/transactions/{id}/undo` or `POST /api/stock/bookings/{id}/undo`); no fixture
  needs a service call outside HTTP.

### Placeholders

A JSON string that is exactly one placeholder is replaced by the typed value (an integer id stays
an integer); inside a longer string it is substituted as text.

| Placeholder | Value |
|---|---|
| `{{product.NAME}}`, `{{location.NAME}}` | the id created for the setup entry |
| `{{user.NAME}}`, `{{username.NAME}}` | the id / the username of a setup user |
| `{{unit}}` | the quantity unit id every product uses (for recipe lines) |
| `{{saved.NAME}}` | a value saved by an earlier step |
| `{{time:-90m}}`, `{{time:-2d}}` | an RFC 3339 instant in UTC (`…Z`), relative to when the fixture started; units `m`, `h`, `d`, sign required |
| `{{time:-2d@-05:00}}` | the same kind of instant written in that offset (`2026-10-07T21:30:00-05:00`) |
| `{{date:-2d@-05:00}}` | the calendar date of that instant in that offset, for `used_date` |
| `{{any}}` | in `expect.body` only: any value |

## The fixtures

The criteria of issue 700 are numbered here in the order the issue lists them:

- **C1**: persistent identity, authorization, mappings, atomic stock writes, correction,
  deletion and conflict behavior.
- **C2**: repeated and concurrent submissions deduct once, and sources cannot collide into or
  inspect another owner's event.
- **C3**: manual and imported overlap is reconciled explicitly, and unresolved records are
  exposed.
- **C4**: a direct stock undo is not reversed by replay, and insufficient stock, invalid units
  and ambiguous location leave no partial booking.
- **C5**: skipped and unanswered events cause no deduction, and a scheduled dose never
  consumes.
- **C6**: examples and fixtures for victual-kit. Every fixture supports C6.
- **C7**: tests cover late events, delete and recreate edits, replay after undo, source mapping,
  revocation and multi-line rollback.

| File | ADR-0041 row | What it shows | Criteria |
|---|---|---|---|
| `00-capabilities.json` | appendix, no row | A client reads `GET /api/consumption/capabilities` to gate features | C6 |
| `01-taken-event-without-mapping.json` | 1 | `needs_mapping`, no stock change, listed in the inbox | C1, C3, C7 (source mapping) |
| `02-approve-mapping-and-retry.json` | 2 | Approve mapping, `retry`: `booked`, once, at the mapped location | C1, C7 (source mapping) |
| `03-same-request-twice.json` | 3 (sequential only) | Identical request twice: 200 `replayed`, one booking | C2 |
| `04-event-before-effective-from.json` | 4 | Older than `effective_from`: `dismissed`, nothing booked | C1 |
| `05-late-event-two-days-old.json` | 5 | Late event books; `used_date` is the date in the offset sent | C7 (late events) |
| `06-edit-as-new-id-with-replaces.json` | 6 | `replaces`: old `voided`, new `booked`, net one deduction | C1, C7 (delete/recreate) |
| `07a-delete-then-create.json` | 7 | Edit seen as delete, then create: one deduction | C7 (delete/recreate) |
| `07b-create-then-delete.json` | 7 | Edit seen as create, then delete: one deduction (two lots; see [Known behaviour](#known-behaviour-a-client-should-read-before-relying-on-a-sequence)) | C7 (delete/recreate) |
| `08-status-change-after-booking.json` | 8 | `not_logged` or `skipped` after booking, within 7 days: `voided`, stock restored | C1, C5 |
| `09-skipped-or-unanswered-without-a-row.json` | 9 | `skipped`, `unanswered`, `scheduled`, `not_logged` with no row: `no_consumption`, no row | C5 |
| `09a-source-stopped-holding-the-record.json` | 9a | `access_revoked`, `history_cleared`, `medication_archived`: stays `booked` | C1 |
| `09b-deletion-needing-a-decision.json` | 9b | No reason, or `entered_in_error` older than 7 days: `source_deleted`; `void` restores, `keep` does not | C1, C3 |
| `09c-default-quantity.json` | 9c | `default_quantity` books; without one `quantity_missing` | C1 |
| `09d-unit-label-approval.json` | 9d | `unit_unconfirmed` shows the label; `approve_unit` books, later events book directly | C1, C4 (invalid units) |
| `09e-replay-with-another-source-updated-at.json` | 9e | Same payload with any `source_updated_at` is a replay; plus conflict, stale and correction | C1, C2 |
| `09f-bulk-void-120-source-deleted.json` | 9f | 120 events in 3 batches of 40; bulk void by filter handles 50/50/20, `remaining` 70/20/0 | C3 |
| `09g-bulk-keep-with-an-already-voided-event.json` | 9g | Bulk `keep` with a voided item: that item `invalid_transition`, the rest apply | C3 |
| `10-undo-in-stock-then-replay.json` | 10 | Undo in the stock journal, replay: stays `undone`; `rebook` is explicit | C4, C7 (replay after undo) |
| `11-partial-undo-of-a-two-line-recipe.json` | 11 | One line undone: `partially_undone`, not rebooked; `rebook` books each line once | C4, C7 (replay after undo) |
| `12-manual-then-import-then-link.json` | 12 | Manual, import, `link`: one deduction, `linked` | C3 |
| `13-similar-doses-not-linked.json` | 13 | Not linked: two deductions, `possible_duplicates` offered | C3 |
| `14-two-line-recipe-insufficient-stock.json` | 14 | Line 2 short: nothing deducted, `insufficient_stock`; replays after a top-up | C4, C7 (multi-line rollback) |
| `15-single-mode-ambiguous-location.json` | 15 | `single` with stock in two places: `ambiguous_location`, nothing deducted | C4 |
| `16-recipe-share-revoked.json` | 16 | Share revoked before processing: `recipe_unavailable` | C1, C7 (revocation) |
| `17-same-source-event-id-from-another-user.json` | 17 | Same `source_event_id`, another user: separate events, mutual 404 | C2 |

## Known behaviour a client should read before relying on a sequence

These are observed server behaviors, recorded because a fixture had to be arranged around them. They
are about stock undo order, not about the event contract.

* **A booking can be undone only while no later booking depends on the same purchase lot**
  ([ADR-0036](../../../docs/adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)).
  Anything that undoes an *earlier* booking of a lot while a *later* booking of that lot is live
  answers `needs_review` / `undo_refused`. Fixtures that void or undo therefore arrange for the
  affected event to be the most recent booking from its lot (`09b`, `09g`, `08`, `10`).
* `07b-create-then-delete.json` holds the product as two purchases (1 and 9) so the old event, which
  consumed the first lot, can be undone after the new event consumed the second. With one purchase
  the same requests end with the delete answering `needs_review` / `undo_refused` and two deductions.
* `09f-bulk-void-120-source-deleted.json` gives each later booking an older `occurred_at`, because
  bulk resolution handles the oldest `occurred_at` first. Events booked in the order they occurred
  (the normal order for a client that syncs history oldest first) would be voided oldest first and
  all but the last would answer `undo_refused`.
* A `not_logged`, `skipped`, `unanswered` or `scheduled` request that omits `medication_ref` is
  accepted for an existing row and keeps the stored `medication_ref`, `quantity` and
  `unit_label`, so the event stays findable by bulk resolution by `filter`.
* A first submission answers `replayed: false`; a replay answers `replayed: true`.

## Allocation totals

Event `lines` correspond to stock-log entries. A correction can revive a stock row and allocate
from it and other rows, so line count does not count consumption events or products.
Clients display quantities by summing amounts for each product and location.

`expect.line_totals` is a list of `{product_id, location_id, amount}` objects. A replay runner
must group every response line by product and location, sum the stock-unit amounts, and compare
all groups against this list. It must not ignore this assertion or require a particular split.
Fixtures 06 and 09e use this assertion for corrected quantities.
`19-correction-split-lines.json` corrects across two purchases and checks the persisted GET result.
