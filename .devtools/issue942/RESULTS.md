# Partial-undo fixture ordering

Observed 2026-10-16 in the working copy `codex/gpt6_0.5.0-partial-undo-fixture-20261016`,
based on `631c6b27cf0d743b6068b5f503940ff9dc97e496`.

Fixture 11 saved `0.id` from `GET /api/stock/transactions/{id}` and assumed that row
belonged to `tablet_a`. The controller fetches the transaction rows without `ORDER BY`.
The fixture therefore could undo `tablet_b` and then expect `tablet_a` to have returned
to 10. This is a test selection defect; transaction response order is not a wire guarantee.

A controlled reproduction sorts the real HTTP transaction response by descending product
id before the fixture selects its booking. With the original `0.id` selector, the new
regression failed at step 5 with stock 8 versus expected 10, matching issue #942.
The production booking and undo implementations were unchanged.

The fixture now matches the booking's `product_id` and requires exactly one match.
The same reordered-response regression and all existing consumption-event fixtures passed:
30 tests, 1,419 assertions, PHP 8.5.10 and the existing isolated PostgreSQL test server.
The run used PHPUnit with `--filter ConsumptionEventFixtureTest` against a disposable
database and the normal `tests/bootstrap.php` configuration. The full suite entry point is:

```sh
.devtools/pgsql/run-tests.sh consumption
```

The original CI query plan and seed were not recovered. The controlled reproduction proves
the fixture's ordering defect and the correction's independence from response ordering;
it does not establish a stock concurrency defect or waive the separate issue #930 gate.
Application coverage was not measured locally because this change touches fixtures and
fixture execution only. CI must retain the application coverage ratchet.
