# Consumption refill fixtures

Server-side fixtures for the refill API of
[ADR-0042](../../../docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)
(issue 701). Each file is one scenario: the requests a client sends, in order, and what Victual
answers, so the `victual-kit` team can read them and replay them against a server of their own. The
routes, fields and wording rules are in the
[operator page](../../../docs/manual/operator/prescription-refills.md).

**What these show and what they do not.** They show Victual's behavior: for this sequence of
requests the server answers this. They do **not** show that a native client reads the notices,
schedules a local notification, shows it or opens the right screen when a person taps it. No device
was involved. That is native evidence, recorded under
[issue 702](https://github.com/datagen24/victual/issues/702) and not here. A passing fixture is not
evidence that an Apple client works.

## Running

```
.devtools/pgsql/run-tests.sh consumption
```

runs the whole `consumption` suite, which includes `tests/Pgsql/ConsumptionRefillFixtureTest.php`.
That class reads every `*.json` here, builds a world for it in a throwaway PostgreSQL schema, sends
each step through the whole middleware stack (one PHP process per request, the way the server runs;
the user is the API key's owner) and checks the answer. A failing assertion names the file and the
step, with the request and the response. To run only the fixtures, run PHPUnit with
`--testsuite consumption --filter ConsumptionRefillFixtureTest`.

Every date in a fixture is a literal calendar date, and every read sends its own `as_of`, so a
fixture gives the same answer on any day.

## File format

One scenario per file, `NN-slug.json`, UTF-8, tab-indented. It uses the format of the
[consumption event fixtures](../consumption-events/README.md), with these differences.

```json
{
	"title": "…", "adr_sequence": "section 6", "description": "…",
	"setup": {
		"users": ["alice", "bob"],
		"recipes": { "main": { "owner": "alice", "shares": { "bob": { "edit": true } } } }
	},
	"steps": [
		{
			"as": "alice",
			"note": "what this step is for",
			"request": { "method": "POST", "path": "/api/consumption/recipes/{{recipe.main}}/refill/fills?as_of=2026-01-01", "body": { "filled_on": "2026-01-01", "supplied_days": 30 } },
			"expect": { "status": 201, "body": { "status": "ok" } },
			"save": { "fill": "current_fill.id" }
		}
	]
}
```

* `setup.users` are names; `alice` holds `STOCK_VIEW`, `STOCK_CONSUME` and `STOCK_EDIT` and every
  other user holds `STOCK_VIEW`. `setup.recipes` maps a name to its owner and its shares; a share is
  the object of rights the share route takes (an empty object is a read share).
* `expect.body` is a **subset** match. An object matches on the keys the fixture lists. A list
  matches element by element for the elements listed, and an object with the key `"0"` stands for
  the first element. An empty list `[]` means "an empty list". `"{{any}}"` matches any value that is
  present. Numbers compare as numbers.
* There is no `stock` check. A4 (no stock written, no fill reset by a transfer or a consumption) is
  asserted on the ledger by `ConsumptionRefillServiceTest`, not here.

| Placeholder | Value |
|---|---|
| `{{recipe.NAME}}` | the id of a recipe created by the setup |
| `{{user.NAME}}` | the id of a setup user |
| `{{saved.NAME}}` | a value saved by an earlier step |
| `{{any}}` | in `expect.body` only: any value |

## The fixtures

The acceptance criteria of issue 701 that a client can observe through the API:

- **A1**: fill history with the supplied duration, medication-specific rules and explicit dates,
  under the agreed precedence and correction semantics.
- **A2**: the 14-day fallback only when no specific rule applies, unknown for missing inputs, and
  the provenance of every calculated date.
- **A3**: approaching, due, ordered and received behavior, a configurable lead, repeat suppression
  and client-readable dates and state.
- **A4**: organizer transfers, consumption and a bare order do not reset the fill history or add
  stock.
- **A5**: private routes honor scoped access and revocation.
- **A6**: tests cover 30-day and 90-day fills, rule overrides, explicit dates, invalid inputs,
  calendar boundaries, corrections, deduplication and receipt.

| File | What it shows | Criteria |
|---|---|---|
| `01-30-day-fill.json` | A 30-day fill: 16 days after the fill, the warning date, the due day and the days overdue | A1, A2, A3, A6 |
| `02-90-day-fill-and-boundaries.json` | A 90-day fill: 76 days after the fill; R-8, R-7, R-1, R and R+3; leads of 0 and 61 | A2, A3, A6 |
| `03-rule-and-explicit-date-precedence.json` | Explicit date, then a rule of each kind, then the fallback; removing each reveals the next | A1, A2, A6 |
| `04-short-supply-and-missing-inputs.json` | A short or missing supply is unknown with a reason, never clamped; `fixed_interval` fixes it | A2, A6 |
| `05-order-and-receive.json` | An order only changes the status; receiving records the fill and closes the order | A3, A6 |
| `06-order-and-cancel.json` | Cancelling restores the status the fills say | A3, A6 |
| `07-correction-and-notice-keys.json` | Void and re-record: an identical fill keeps the notice key, a changed date makes a new one | A1, A3, A6 |
| `08-explicit-date-expiry.json` | A newer fill ends an explicit date; voiding the newer fill does not bring it back | A1, A6 |
| `09-notices-and-acknowledgement.json` | approaching then due, per-user acknowledgement, an open order raises none | A3, A5, A6 |
| `10-client-date-and-server-date.json` | The client's `as_of` decides the status; without it `server_utc` is reported | A3, A6 |
| `11-access-follows-the-recipe.json` | Read share reads, edit share writes, no share is 404, a removed share loses the data | A5 |
