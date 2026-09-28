# ADR-0034 acceptance experiment

The roll-up experiment passes twelve PostgreSQL checks. Six of those checks fail
against the current view. This establishes the proposed calculation for active group
trees; it does not clear all of [ADR-0034's prerequisites](../../docs/adr/0034-product-group-minimum-counts-descendant-groups.md#acceptance-prerequisites).

## Run

Use a disposable PostgreSQL database migrated by this checkout, with pgTAP available.
The database role must be able to replace views and create the pgTAP extension.
Set `PGHOST`, `PGPORT`, `PGUSER`, and `PGPASSWORD` for that database server.

```sh
.devtools/pgsql/run-tests.sh groupminstock
.devtools/adr0034/run.sh victual_group_min_stock current
.devtools/adr0034/run.sh victual_group_min_stock candidate
```

The `current` run is a negative control and must exit 1 with six failed assertions
against commit `956c5a07`. The `candidate` run must exit 0 with twelve passing assertions.
Both runs use a transaction and roll back the fixture rows, extension creation, and
view replacement. A SQL error terminates the connection and rolls back the transaction.

`rollup.sql` is an experiment, not a migration. It joins active products to every
ancestor through `product_groups_resolved`, then sums their effective stock once per
ancestor. It does not filter group activity during traversal. That proposed behavior
for inactive subgroups still requires the maintainer's confirmation and a fixture.

## Evidence

Measured on 2026-09-27 against checkout `956c5a07` in
`/Users/speterson/.codex/worktrees/b031/grocy`, with the experiment and Manual correction
in the working copy. The runtime used PHP 8.4.25 and PostgreSQL 16.15 in a disposable container
built from `localhost/victual-pg:pgtap`.

| Prerequisite | Result |
|---|---|
| 1: database calculation and activity decision | Twelve experimental assertions pass; current view fails six. Inactive-subgroup confirmation and its fixture remain open. |
| 2: controller and browser behavior | Open. `StockController::Overview` still joins direct membership. The browser options, buttons, and hidden row membership still use names. No browser gate is claimed. |
| 3: accurate Manual | Corrected to describe direct membership, effective stock, opened-stock exclusion, and no unit conversion. Vale reports no findings. Descendant wording must accompany delivery. |

The existing `groupminstock` phase passes all 25 assertions; `productgroups` passes
all 41. These phases guard current behavior and do not establish descendant behavior.
Application code is unchanged,
so no application coverage change is claimed.

The ADR remains Proposed. Acceptance needs a separate bookkeeping pull request after
the remaining gates are cleared. Its two open questions about shopping-list automation
and mixed units are deferred design work, not additional acceptance gates.
