# ADR-0034 acceptance experiment

The roll-up experiment passes sixteen PostgreSQL checks. Nine of those checks fail
against the current view. This establishes the proposed calculation including inactive intermediate
groups; it does not clear all of [ADR-0034's prerequisites](../../docs/adr/0034-product-group-minimum-counts-descendant-groups.md#acceptance-prerequisites).

## Run

Use a disposable PostgreSQL database migrated by this checkout, with pgTAP available.
The database role must be able to replace views and create the pgTAP extension.
Set `PGHOST`, `PGPORT`, `PGUSER`, and `PGPASSWORD` for that database server.

```sh
.devtools/pgsql/run-tests.sh groupminstock
.devtools/adr0034/run.sh victual_group_min_stock current
.devtools/adr0034/run.sh victual_group_min_stock candidate
```

The `current` run is a negative control and must exit 1 with nine failed assertions
against commit `956c5a07`. The `candidate` run must exit 0 with sixteen passing assertions.
Both runs use a transaction and roll back the fixture rows, extension creation, and
view replacement. A SQL error terminates the connection and rolls back the transaction.

`rollup.sql` is an experiment, not a migration. It joins active products to every
ancestor through `product_groups_resolved`, then sums their effective stock once per
ancestor. It does not filter group activity during traversal. The maintainer confirmed this behavior
on 2026-09-28. The fixtures cover inactive intermediate groups, inactive leaf groups,
and inactive products beneath them.

## Evidence

Measured on 2026-09-28 against checkout `5b0d152d` (application base `956c5a07`) in
`/Users/speterson/.codex/worktrees/b031/grocy`, with the expanded fixtures and recorded confirmation
in the working copy. The runtime used PHP 8.4.25 and PostgreSQL 16.15 in a disposable container
built from `localhost/victual-pg:pgtap`.

| Prerequisite | Result |
|---|---|
| 1: database calculation and activity decision | Cleared by the experiment: sixteen assertions pass; current view fails nine. The maintainer confirmed the inactive-subgroup rule on 2026-09-28. |
| 2: controller and browser behavior | Open. `StockController::Overview` still joins direct membership. The browser options, buttons, and hidden row membership still use names. No browser gate is claimed. |
| 3: accurate Manual | Corrected to describe direct membership, effective stock, opened-stock exclusion, and no unit conversion. Vale reports no findings. Descendant wording must accompany delivery. |

The `groupminstock` phase passed all 25 assertions again on 2026-09-28. The
`productgroups` phase passed all 41 assertions on 2026-09-27. These phases guard
current behavior and do not establish descendant behavior.
Application code is unchanged,
so no application coverage change is claimed.

The ADR remains Proposed. Acceptance needs a separate bookkeeping pull request after
the remaining gates are cleared. Its two open questions about shopping-list automation
and mixed units are deferred design work, not additional acceptance gates.
