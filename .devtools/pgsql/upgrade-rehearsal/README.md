# Upgrade rehearsal from v0.2.0-MVP (issue #650)

This directory builds a household database with the `v0.2.0-MVP` code, upgrades it with this
tree's migrations, and checks the result. It is the reproducible dataset that
[ADR-0027](../../../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)
acceptance prerequisite 5 asks for in place of production data, which does not exist. The
latest recorded run is in [EVIDENCE.md](EVIDENCE.md).

It is a tool to run when migrations change, like the parity suite. It is not a CI phase and
`run-tests.sh` does not call it.

## What it checks

`rehearse.sh` creates three databases, all named `rehearsal650_*`, in a PostgreSQL container
that you name:

1. `rehearsal650_valid`: generated at the source revision, then upgraded twice with
   `bin/victual-migrate` from this tree. `verify.py upgrade` checks the following:
   - Every legacy `TIMESTAMP` column is `TIMESTAMPTZ` after the upgrade.
   - Every converted value equals the instant that Python's `zoneinfo` gives for the wall
     clock with `fold=0`. In a repeated hour, `fold=0` is the earlier instant (PEP 495).
     This oracle does not use the conversion code that it tests.
   - The named values that `generate.php` writes match the UTC instants that are written
     out by hand next to them. These are a repeated hour in four tables, controls on each
     side of both 2024 New York transitions, and two ordinary timestamps.
   - Every existing `TIMESTAMPTZ` value keeps its epoch microseconds.
   - All other values are unchanged: calendar dates, stock quantities, the ledger, labels
     and their targets. There are five exceptions, and `verify.py` names each one: rows
     that migrations 0289 to 0302 add, the price caches that migration 0292 rebuilds, and
     the upgrade's own change time.
   - The second run changes no row in any table.

   `verify.py selftest` then changes copies of the result and requires the checks to fail
   on each change. The changes are a repeated hour read as the later instant, an instant
   moved by one microsecond, a changed date, a lost ledger row, and a second run that writes.
2. `rehearsal650_repeat`: the same generation again. `verify.py reproducible` requires
   identical data, apart from the values that the source code takes from `uniqid()`, a
   random token or a password salt.
3. `rehearsal650_invalid`: the same data plus a chore execution in the hour that New York
   skipped on 2024-03-10, and an `infinity` task completion. The preflight must exit 2 and
   the upgrade must fail. `verify.py refusal` then checks that each legacy column is still
   `TIMESTAMP` and that each wall clock is byte-identical.

## The fixture

`generate.php` runs against the source tree's own code (`--app`), at migration 288. It
writes 90 days of household activity from 2024-09-20, so the data crosses New York's
2024-11-03 fall-back. The data has purchases, consumes, opens, transfers and undone bookings
through `StockService`. It also has chore executions, battery charge cycles and task
completions at explicit wall clocks through their services, meal plan days, and four labels
through `LabelIdentityService`. One label is retired when its location is deleted. The
configured zone is `America/New_York`. The script refuses to run in any other zone, because
the named expectations are for New York.

Values that the code stamps from the clock are then replaced with values derived from row
order, so two runs produce the same data. The script header explains this step.

## Running it

From this tree, with podman. The image tags are examples:

```bash
git worktree add ../v020 v0.2.0-MVP
podman build --target dev -t victual:dev .
podman build -f .devtools/pgtap/postgres.Dockerfile -t victual-pg:16-pgtap .devtools/pgtap
podman network create victual-rehearsal
podman run -d --name victual-pg16-rehearsal --network victual-rehearsal --memory 3g \
  -e POSTGRES_USER=victual -e POSTGRES_PASSWORD=victual -e POSTGRES_DB=victual \
  --tmpfs /var/lib/postgresql/data victual-pg:16-pgtap
REHEARSAL_NETWORK=victual-rehearsal REHEARSAL_IMAGE=victual:dev \
  .devtools/pgsql/upgrade-rehearsal/rehearse.sh victual-pg16-rehearsal ../v020 /tmp/rehearsal-pg16
```

For PostgreSQL 15, build the same Dockerfile with `16` replaced by `15`. The source and target
trees use the same dev image. `composer.lock` and `Dockerfile` did not change between
`v0.2.0-MVP` and the target, so the dependencies are identical. Check this again before you
use a later target.

The output directory holds `rehearsal.log`, the snapshots, the manifests, the preflight and
migrate output, and the `verify-*.txt` reports. It also holds a `pg_dump` of the source
database. The script exits 0 only when every check passes.

`verify.py` needs Python 3.9 or later for `zoneinfo`. On a host without the system zone
database, install the `tzdata` package.
