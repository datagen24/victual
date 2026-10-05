# Migration numbers above the baseline

Every migration number after `DatabaseMigrationService::BASELINE_MIGRATION_ID` (0255) is
claimed here before it is written, and stays claimed after it lands. This is a record
rather than a courtesy: `.devtools/pgsql/check-migrations.php` parses the table below and
fails when the numbers on disk and the numbers claimed here disagree.

**Why the record exists.** Plans are worked in parallel branches, and each one needs a
migration number before any of them merges. Numbers handed out on a branch collide or leave
holes, and a hole is worse than a collision because nothing complains.

A database migrated through a tree that has 0257 and 0259 but not 0258 records
`MAX(migration) = 259`. As a result, anything that asks "is this database at the latest
number?" — `GetLatestMigrationNumber()`, `DatabaseImporter`'s two-sided comparison, plan 10's
boot check — is satisfied by a database that never ran 0258. The migration *runner* is not
fooled (it asks per number whether a row exists, so a 0258 arriving later is applied), but
every gate built on the maximum is, and it is a gate that decides whether a deployment is
allowed to serve.

**So the sequence above the baseline has no holes in a mergeable tree.** A branch that
carries 0259 while 0258 lives in another branch is *not independently mergeable*, and the
check says so by name rather than leaving it to be noticed in review. The branch owning the
lower number merges first; alternatively the higher number is moved down at merge time,
which is only safe while no database anywhere has run it.

**Above 0265 a migration is PostgreSQL-only.** ADR-0008's retirement froze the SQLite line
at `DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID` = 0265, because SQLite is an input
format now and an input format's upper bound has to stop moving. Nothing here migrates a
SQLite database past that number, so a `NNNN.sqlite.sql` above it is a file no engine can run
and no source `bin/victual-db-import` accepts could have applied. Write the `.pgsql.sql` — or
a portable `NNNN.sql`, which now means the same thing — and `check-migrations.php` refuses
the SQLite half. Numbers 0256-0265 keep the two-engine rules they were written under; that
range is history and the differential suite still replays it.

**A number is retired, never reused.** Once a file has existed under a number in `master`,
that number is spent even if the migration is later reverted — some database somewhere may
have recorded it.

## Claimed numbers

| Number | Owner | State |
|---|---|---|
| 0256 | dual-engine hazard fix (`products_view` `qu_factor_*` cast) | in `master` |
| 0257 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `mqtt_product_entities`, `mqtt_published_entities` | in this tree |
| 0258 | [plan 01](../docs/plans/landed/01-file-storage.md) — the files table | in `master` (PR #39) |
| 0259 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `outbox` | in this tree |
| 0260 | [plan 21](../docs/plans/landed/21-frontend-sink-discipline.md) — purify stored rich text that predates the API purifier | in this tree |
| 0261 | [issue #46](https://github.com/datagen24/victual/issues/46) — a total order for `products_last_purchased.price`, and SQLite's integer division in `products_average_price` | in this tree |
| 0262 | [security sweep S12](../docs/security-sweep.md) via wave 2 — `login_attempts`, the login throttle's out-of-process state | in this tree |
| 0263 | [plan 11](../docs/plans/11-api-error-handling.md) question 4 — `api_keys.key_hint` | in this tree |
| 0264 | [plan 11](../docs/plans/11-api-error-handling.md) question 4 — hash the stored API keys, backfill the hint | in this tree |
| 0265 | [security sweep S12](../docs/security-sweep.md) via wave 2 — `users.must_change_password`, moved out of `user_settings` in review | in this tree |
| 0266 | [plan 19](../docs/plans/19-rbac.md) — roles and read permissions (wave 3a) | in this tree |
| 0267 | the split-entry defect in `products_average_price` — `stock_entry_origins`, and `stock_edited_entries` following it | in this tree |
| 0268 | [plan 03](../docs/plans/landed/03-category-min-stock.md) — `product_groups.min_stock_amount`, `product_groups_missing` (wave 3b) | in this tree |
| 0269 | [plan 25](../docs/plans/25-label-infrastructure.md) — `labels`, the uid-to-target mapping [ADR-0011](../docs/adr/0011-label-namespace.md) requires (wave 3b), plus the import epoch | in this tree |
| 0270 | [plan 25](../docs/plans/25-label-infrastructure.md) group B — ten tables: eight configuration/job/delivery tables plus `label_worker_sessions` and `label_worker_credentials` for durable pairing and pending rotation; templates/artifacts belong to plan 27 | in this tree |
| 0271 | [plan 27](../docs/plans/landed/27-label-templates-and-rendering.md) group A — `label_templates`, `label_template_drafts`, `label_template_versions`, `label_assets`, `label_media_profiles` (wave 3b) | in this tree |
| 0272 | [plan 27](../docs/plans/landed/27-label-templates-and-rendering.md) group B — `label_captures`, `label_render_requests`, `label_artifacts`, `label_idempotency_keys`, and the artifact/operation columns on plan 25's `print_jobs` (wave 3b) | in this tree |
| 0273 | [plan 08](../docs/plans/landed/08-nested-locations.md) — `locations.parent_location_id`, `locations_resolved`, `hierarchy_depth_limit()` and the nesting guards | in this tree |
| 0274 | [plan 23](../docs/plans/landed/23-storage-classes.md) — `storage_classes`, `locations.storage_class_id` | in this tree |
| 0275 | [plan 28](../docs/plans/landed/28-open-container-measurement.md) — the measured-remainder columns on `stock` (`opened_amount`, `opened_qu_id`, `opened_tare`, `opened_measured_at`) and their coherence constraint (wave 4) | in this tree |
| 0276 | [plan 29](../docs/plans/landed/29-working-container-replenishment.md) — the (product, location) minimum table and its shortfall view, and `locations.tare_weight`/`tare_qu_id` (wave 4) | in this tree |
| 0277 | [issue #148](https://github.com/datagen24/victual/issues/148) — `enfore_product_nesting_level` fires on `INSERT` as well as `UPDATE`, checks the nesting relationship in both directions, and nulls out any existing multi-level chain | in this tree |
| 0278 | [plan 30](../docs/plans/landed/30-nested-product-groups.md) — `product_groups.parent_product_group_id`, the `UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT` replacement, `product_groups_resolved` and the nesting guards (wave 4) | in this tree |
| 0279 | [plan 31](../docs/plans/landed/31-directed-substitution.md) — the directed product substitution edges and their view (wave 4) | in this tree |
| 0280 | [issue #130](https://github.com/datagen24/victual/issues/130) — `api_keys.rotated_from_id`, the lineage a regular-key rotation leaves behind (sweep S11's expiry-and-rotation half, plan 11's follow-up) | in `master` |
| 0281 | [plan 19](../docs/plans/19-rbac.md) piece 2, [issue 84](https://github.com/datagen24/victual/issues/84) — `STOCK_PRICES_VIEW`, `permission_fields` and its seed (wave 5) | in `master` |
| 0282 | [issue #176](https://github.com/datagen24/victual/issues/176) items 1 and 3 — the price-visibility policy re-applied from `db/pgsql/prices-seed.sql`, plus the `product_barcodes`/`product_barcodes_view` `last_price` rows 0281 missed | in this tree |
| 0283 | [plan 32](../docs/plans/landed/32-label-kinds.md) — `labels.kind`, `label_templates.entity_kind` and `label_captures.entity_kind` widened to six kinds, one retirement trigger per target table, one seeded default template per kind | in `master` |
| 0284 | **retired**, a no-op (`SELECT 1`) — was plan 22's; see the 2026-09-18 note below | in this tree |
| 0285 | **retired**, a no-op (`SELECT 1`) — was plan 22's; see the 2026-09-18 note below | in this tree |
| 0286 | [plan 05](../docs/plans/05-store-shopping-lists.md) parts A and C, [issue 85](https://github.com/datagen24/victual/issues/85) — `shopping_lists.shopping_location_id`, `products.default_shopping_list_id`, `recipes.default_shopping_list_id` (wave 5) | in `master` |
| 0287 | [issue #208](https://github.com/datagen24/victual/issues/208) (plan 02's Victual-side auth) — `api_keys.read_only` | in this tree |
| 0288 | [ADR-0029](../docs/adr/0029-stock-locations-reference-existing-locations.md), [issue 461](https://github.com/datagen24/victual/issues/461) — stock location foreign key | in this tree |
| 0289 | issue [#487](https://github.com/datagen24/victual/issues/487) remediation (PR #542) — `stock_current`, `uihelper_stock_journal` and `chores_current` recreated from their latest definitions to fix issues [501](https://github.com/datagen24/victual/issues/501), [505](https://github.com/datagen24/victual/issues/505), [497](https://github.com/datagen24/victual/issues/497) and the weekly-schedule half of [506](https://github.com/datagen24/victual/issues/506) | in this tree |
| 0290 | issue [#487](https://github.com/datagen24/victual/issues/487) remediation (PR #580), [ADR-0033](../docs/adr/0033-stock-rows-merge-only-in-maintenance-for-non-expiring-rows.md) decision 3 — `stock_splits` narrowed to never-expiring, unlabelled rows (issues [488](https://github.com/datagen24/victual/issues/488), [491](https://github.com/datagen24/victual/issues/491)) | in this tree |
| 0291 | issue [#487](https://github.com/datagen24/victual/issues/487) remediation, issue [#506](https://github.com/datagen24/victual/issues/506) decision D5 — `chores_log.stock_transaction_id`, the explicit link between a chore execution and the stock consumption it booked | in this tree |
| 0292 | issue [#588](https://github.com/datagen24/victual/issues/588) (#487 remediation) — `trg_stock_log_DEL` fixed to clear price caches by `OLD.product_id`, not `OLD.id` | in this tree |
| 0293 | issue [#508](https://github.com/datagen24/victual/issues/508) (M8, #487 remediation), [ADR-0034](../docs/adr/0034-product-group-minimum-counts-descendant-groups.md) — `product_groups_missing` redefined to roll up through `product_groups_resolved` | in this tree |
| 0294 | issue [#487](https://github.com/datagen24/victual/issues/487) remediation, issues [#543](https://github.com/datagen24/victual/issues/543) and [#546](https://github.com/datagen24/victual/issues/546) — `trg_cascade_change_qu_id_stock` redefined to rescale `product_location_min_stock.min_stock_amount` and to refuse a stock-unit change that would rescale a measured open container or its live consume booking | in this tree |
| 0295 | issue [#552](https://github.com/datagen24/victual/issues/552) (D4, #487 remediation), modelled on [ADR-0029](../docs/adr/0029-stock-locations-reference-existing-locations.md) — plain foreign keys on all six of `products`' upstream reference columns (`location_id`, `qu_id_purchase`, `qu_id_stock`, `qu_id_consume`, `qu_id_price`, `product_group_id`), no repair step (migrations run once, on a fresh install; `DatabaseImporter::AssertProductReferences()` validates a dangling import separately); also issue [#558](https://github.com/datagen24/victual/issues/558) — `trg_cascade_product_removal` retires a deleted product's stock-entry labels with its own name before deleting the stock rows. Merged to `master` via PR [#624](https://github.com/datagen24/victual/pull/624) | in `master` |
| 0296 | issue [#516](https://github.com/datagen24/victual/issues/516) (M16, #487 remediation, maintainer decision D2) — `cancel_queued_label_jobs()`, called from every label retirement trigger (the five single-row ones and `trg_cascade_product_removal`) to cancel a label's queued, unclaimed print jobs when it retires; `trg_cascade_product_removal` also gains a `PERFORM ... FOR UPDATE` on the affected `stock` rows, locking them before `labels` the same way every other retirement site already does. Merged to `master` via PR [#626](https://github.com/datagen24/victual/pull/626) | in `master` |
| 0297 | issue [#492](https://github.com/datagen24/victual/issues/492) (H3, #487 remediation), [ADR-0032](../docs/adr/0032-stock-amounts-compare-within-one-tolerance.md) — `stock_amount_non_negative_check`, a database-level `amount >= 0` backstop for `stock` behind the application refusal commit 2039d5947 already added, plus this migration's own ADR-0029 preflight for an app upgrade over existing negative rows. Merged to `master` via PR [#627](https://github.com/datagen24/victual/pull/627) | in `master` |
| 0298 | issue [#622](https://github.com/datagen24/victual/issues/622) (#487 remediation), maintainer decision D4 (issue #553) — `stock_current` redefined so an unconvertible sub product, or one whose only resolved conversion has a non-positive factor, contributes nothing to `amount_aggregated`, `amount_opened_aggregated` or `amount_measured`, matching the exclusion rule PR #621 already applies on the write side. Merged to `master` via PR [#628](https://github.com/datagen24/victual/pull/628) | in `master` |
| 0299 | issue [#521](https://github.com/datagen24/victual/issues/521) (#487 remediation) — `BATTERIES_VIEW`, `CALENDAR_VIEW`, `EQUIPMENT_VIEW` permission leaves, nested under `BATTERIES`/`CALENDAR`/`EQUIPMENT` the same way `STOCK_PRICES_VIEW` (0281) nests under `STOCK_PURCHASE` | in `master` |
| 0300 | issue [#629](https://github.com/datagen24/victual/issues/629) (#487 remediation, maintainer decision D4, issue #553), [PR #634](https://github.com/datagen24/victual/pull/634) — `products_current_substitutions` never chooses an unconvertible sub product as `product_id_effective`, so recipe cost/calorie views stop counting it 1:1, the same exclusion rule PR #628 already applies to `stock_current`'s own rollup | in this tree |
| 0301 | issue [#650](https://github.com/datagen24/victual/issues/650), [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md) decision 2 — every legacy `TIMESTAMP` column becomes `TIMESTAMPTZ`, read as a wall clock in the configured zone with the earlier instant for a repeated hour; a read-only preflight refuses skipped wall clocks, infinities and out-of-range values; dependent views are recreated | pending PR |
| 0302 | `stock_edited_entries` rewritten without a join between two aggregated CTEs, so the price-cache triggers, `reconcile_stock_log_cache()` and `uihelper_product_details` stop costing time quadratic in `stock_log`; same rows as 0267's definition | pending PR |
| 0303 | [plan 22](../docs/plans/22-medication-tracking.md) — `medication_products`, `medication_stock_attributes`, `subjects` | **claimed, unwritten** |
| 0304 | [plan 22](../docs/plans/22-medication-tracking.md) — `regimens`, `regimen_doses`, `administrations`, `storage_excursions` | **claimed, unwritten** |

Renumbered on 2026-10-05: the `stock_edited_entries` performance fix is written and takes the
lowest free slot, **0302**, under the lowest-free-slot rule. Plan 22's two unwritten claims
yield and move up in turn, from 0302–0303 to **0303–0304**, keeping their own order. The next
unclaimed number is now **0305**.

Renumbered on 2026-10-04: issue #650's `TIMESTAMPTZ` migration is being written and takes the
lowest free slot, **0301**, under the lowest-free-slot rule. Plan 22's two unwritten claims
yield and move up in turn, from 0301–0302 to **0302–0303**, keeping their own order. The next
unclaimed number is now **0304**.

Renumbered again on 2026-09-29, once more: PRs [#624](https://github.com/datagen24/victual/pull/624),
[#626](https://github.com/datagen24/victual/pull/626), [#627](https://github.com/datagen24/victual/pull/627)
and [#628](https://github.com/datagen24/victual/pull/628) have all now merged to `master`.
0295 through 0298 are real, landed migrations rather than forward-looking placeholders — their
status moves to "in `master`" across the board.

A fifth PR, #633 (issue #521's view-permission leaves — `BATTERIES_VIEW`, `CALENDAR_VIEW`,
`EQUIPMENT_VIEW`), had separately claimed and merged 0299 while this branch (issue #629's
recipe cost/calorie fix, PR #634) was open, so 0299's row above also moves from "pending PR"
to "in `master`". This branch's own migration keeps its already-written **0300**, the lowest
free slot below all five now-merged/landed numbers. Plan 22's two unwritten claims stay at
**0301–0302**, unmoved by this merge since nothing landed below them. The next unclaimed
number is now **0303**.

**Master's own history of the same four numbers, preserved from before this branch merged
it.** Renumbered on 2026-09-29, once more: PRs [#624](https://github.com/datagen24/victual/pull/624),
[#626](https://github.com/datagen24/victual/pull/626) and
[#627](https://github.com/datagen24/victual/pull/627) had all merged to `master`. 0295, 0296
and 0297 were real, landed migrations rather than forward-looking placeholders - their status
moved from "in this tree"/"pending" to "in `master`".

Issue [#622](https://github.com/datagen24/victual/issues/622) (#487 remediation)'s
`stock_current` aggregation fix took the next free slot, 0298, and plan 22's two unwritten
claims moved up in turn, from 0295–0296 to 0299–0300, keeping their own order. The
migration-numbering check needed no `--allow-reserved-holes` waiver on `master` any more at
that point, since 0295 through 0298 were all files on disk with no hole below them.

Renumbered on 2026-09-29: issue [#622](https://github.com/datagen24/victual/issues/622) (#487
remediation)'s `stock_current` aggregation fix took 0298. Three still-unmerged branches had
already ordered themselves ahead of it: PR #624 (issue #552/#558) at 0295, PR #626 (issue
#516) at 0296 depending on #624, and PR #627 (issue #492) at 0297. Per the lowest-free-slot
rule, the file that branch was actually writing took the lowest slot still free below all
three, and plan 22's two unwritten claims moved up in turn, from 0295–0296 to 0299–0300.

Renumbered again on 2026-09-29: issue [#516](https://github.com/datagen24/victual/issues/516)
(M16, #487 remediation)'s print-job cancellation fix was written on the
`claude/sonnet_retire-cancel-jobs-r487` branch as `0296.pgsql.sql`. It took the lowest free
slot above PR #624's `0295.pgsql.sql` - that PR's own note had already moved plan 22's claims
from 0295–0296 to 0296–0297, and this one took 0296 out from under plan 22 in turn.

Per the lowest-free-slot rule, plan 22's two unwritten claims moved up once more, from
0296–0297 to 0297–0298 at the time. That branch merged to `master` as PR #626. The branch
that became PR #627's own `0297.pgsql.sql` (issue #492) then took the number PR #626's own
note had projected for plan 22, pushing plan 22's claims one slot further, to 0298–0299.
That is the state this branch's own merge found on `master`, before the renumbering at the
top of this section moved plan 22 again, to its current 0301–0302.

**This branch's own history of the same numbers, before master's four PRs had merged into
it.** Merged 2026-09-29: `master` had merged PR #624 (issue #552/#558's product foreign-key
migration) as `0295.pgsql.sql`, and separately PR #630 (issue #521's route-sweep inventory, no
migration of its own). 0295 was retired, never reused, and recorded as "in this tree" once
this branch first merged `master` and carried that file; #626, #627 and #628 remained open,
still claiming 0296 through 0298 at that point.

Renumbered 2026-09-29: issue [#521](https://github.com/datagen24/victual/issues/521)'s
view-permission migration (`BATTERIES_VIEW`/`CALENDAR_VIEW`/`EQUIPMENT_VIEW`, #487 remediation)
was being written on this branch.

Four open PRs - #624, #626, #627 and #628 - had each independently claimed one of 0295
through 0298 for their own #487 remediation work. This table recorded all four as post-merge
placeholders, in the order their PR numbers were opened, rather than leaving this branch to
guess which of the four would land first. #624 had by then landed as 0295 with different
content than guessed (issue #552/#558, not #521) - exactly the case the retirement rule
covers: the file, not the guess, is what a merged number means from here on.

This branch's own migration took the lowest free slot below the three still-open claims at
the time, 0299, and plan 22's two still-unwritten claims moved up in turn, from 0295–0296 to
0300–0301, keeping their own order. That has since been corrected, by the same rule applied
again, to the current 0301–0302 once #626, #627 and #628 had all merged too and PR #634 had
claimed 0300 ahead of plan 22.

Renumbered again on 2026-09-28, once more: issue [#552](https://github.com/datagen24/victual/issues/552)
(D4, #487 remediation)'s products foreign-key migration, also carrying issue
[#558](https://github.com/datagen24/victual/issues/558)'s label-retirement fix, is being
written on this branch as `0295.pgsql.sql`, above plan 22's still-unwritten claims. Per the
lowest-free-slot rule, the file the branch is actually writing takes the lowest free slot and
plan 22's two unwritten claims move up in turn, from 0295–0296 to **0296–0297**, keeping their
own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this
table. The next unclaimed number is now **0298**.

Renumbered again on 2026-09-29, yet again: three branches claimed the same lowest free slots
in parallel. PR [#624](https://github.com/datagen24/victual/pull/624) (issues #552/#558) writes
`0295.pgsql.sql`. PR [#626](https://github.com/datagen24/victual/pull/626) (issue #516) writes
`0296.pgsql.sql`. Both take the lowest free slots ahead of this branch.

Issue [#492](https://github.com/datagen24/victual/issues/492) (H3, #487 remediation)'s
`stock_amount_non_negative_check` fix is written on this branch as `0297.pgsql.sql`, the next
lowest free slot once 0295–0296 are spoken for, above plan 22's still-unwritten claims. Per the
lowest-free-slot rule, plan 22's two unwritten claims move up in turn, from 0295–0296 to
**0298–0299**, keeping their own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s
numbering note moves with this table.

**Update, same day:** PR #624 has since merged to `master` (18d0389d), so 0295's row above now
reads its real, landed content rather than a forward-looking placeholder, and its status moves
from "in this tree" to "in `master`".

**Update, 2026-09-29, later still:** PR #626 has now also merged to `master` (11783f76). 0296's
row above likewise reads its real, landed content, and its status moves from "claimed" to
"in `master`". Only 0297 (this tree, issue #492) remains unmerged among the three. 0295 and
0296 are both files on disk now, and this branch supplies 0297 itself, so the migration-
numbering check no longer needs `--allow-reserved-holes` waived to pass on this branch. The
next unclaimed number is still **0300**.

**Renumbered 2026-09-29, once more:** PRs [#624](https://github.com/datagen24/victual/pull/624),
[#626](https://github.com/datagen24/victual/pull/626), [#627](https://github.com/datagen24/victual/pull/627)
and [#628](https://github.com/datagen24/victual/pull/628) have all now merged to `master`.
0295 through 0298 are real, landed migrations rather than forward-looking placeholders — their
status moves to "in `master`" across the board.

This branch's own view-permission migration
(`BATTERIES_VIEW`/`CALENDAR_VIEW`/`EQUIPMENT_VIEW`, issue #521) already has a file on disk at
**0299**, the lowest free slot below all four now-merged numbers, so it keeps that number and
does not move again.

Master's own table, at the point this branch merged it, already had plan 22's two claims
sitting at 0299–0300 with no other claim recorded between them. That collides with this
branch's own written `0299.pgsql.sql`.

Separately, open PR [#634](https://github.com/datagen24/victual/pull/634) (issue #629's
recipe cost/calorie follow-up) has since claimed 0300 on its own branch. Per the
lowest-free-slot rule, a number with a file, or a number another still-open branch has
already claimed, both outrank an unscheduled draft's placeholder. Plan 22's two claims yield
to both and move up in turn, from 0299–0300 to **0301–0302**, keeping their own order.
[Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this table.

The migration-numbering check needs no `--allow-reserved-holes` waiver on this branch any
more: 0295 through 0299 are all now files on disk with no hole below them, and 0300's open
claim (PR #634) sits above the highest number this branch itself carries. The next unclaimed
number is now **0303**.

**Master's own history of the same four numbers, preserved from before this branch merged
it.** Renumbered on 2026-09-29, once more: PRs [#624](https://github.com/datagen24/victual/pull/624),
[#626](https://github.com/datagen24/victual/pull/626) and
[#627](https://github.com/datagen24/victual/pull/627) had all merged to `master`. 0295, 0296
and 0297 were real, landed migrations rather than forward-looking placeholders - their status
moved from "in this tree"/"pending" to "in `master`".

Issue [#622](https://github.com/datagen24/victual/issues/622) (#487 remediation)'s
`stock_current` aggregation fix took the next free slot, 0298, and plan 22's two unwritten
claims moved up in turn, from 0295–0296 to 0299–0300, keeping their own order. The
migration-numbering check needed no `--allow-reserved-holes` waiver on `master` any more at
that point, since 0295 through 0298 were all files on disk with no hole below them.

Renumbered on 2026-09-29: issue [#622](https://github.com/datagen24/victual/issues/622) (#487
remediation)'s `stock_current` aggregation fix took 0298. Three still-unmerged branches had
already ordered themselves ahead of it: PR #624 (issue #552/#558) at 0295, PR #626 (issue
#516) at 0296 depending on #624, and PR #627 (issue #492) at 0297. Per the lowest-free-slot
rule, the file that branch was actually writing took the lowest slot still free below all
three, and plan 22's two unwritten claims moved up in turn, from 0295–0296 to 0299–0300.

Renumbered again on 2026-09-29: issue [#516](https://github.com/datagen24/victual/issues/516)
(M16, #487 remediation)'s print-job cancellation fix was written on the
`claude/sonnet_retire-cancel-jobs-r487` branch as `0296.pgsql.sql`. It took the lowest free
slot above PR #624's `0295.pgsql.sql` - that PR's own note had already moved plan 22's claims
from 0295–0296 to 0296–0297, and this one took 0296 out from under plan 22 in turn.

Per the lowest-free-slot rule, plan 22's two unwritten claims moved up once more, from
0296–0297 to 0297–0298 at the time. That branch merged to `master` as PR #626. The branch
that became PR #627's own `0297.pgsql.sql` (issue #492) then took the number PR #626's own
note had projected for plan 22, pushing plan 22's claims one slot further, to 0298–0299.
That is the state this branch's own merge found on `master`, before the renumbering at the
top of this section moved plan 22 again, to its current 0301–0302.

**This branch's own history of the same numbers, before master's four PRs had merged into
it.** Merged 2026-09-29: `master` had merged PR #624 (issue #552/#558's product foreign-key
migration) as `0295.pgsql.sql`, and separately PR #630 (issue #521's route-sweep inventory, no
migration of its own). 0295 was retired, never reused, and recorded as "in this tree" once
this branch first merged `master` and carried that file; #626, #627 and #628 remained open,
still claiming 0296 through 0298 at that point.

Renumbered 2026-09-29: issue [#521](https://github.com/datagen24/victual/issues/521)'s
view-permission migration (`BATTERIES_VIEW`/`CALENDAR_VIEW`/`EQUIPMENT_VIEW`, #487 remediation)
was being written on this branch.

Four open PRs - #624, #626, #627 and #628 - had each independently claimed one of 0295
through 0298 for their own #487 remediation work. This table recorded all four as post-merge
placeholders, in the order their PR numbers were opened, rather than leaving this branch to
guess which of the four would land first. #624 had by then landed as 0295 with different
content than guessed (issue #552/#558, not #521) - exactly the case the retirement rule
covers: the file, not the guess, is what a merged number means from here on.

This branch's own migration took the lowest free slot below the three still-open claims at
the time, 0299, and plan 22's two still-unwritten claims moved up in turn, from 0295–0296 to
0300–0301, keeping their own order. That has since been corrected, by the same rule applied
again, to the current 0301–0302 once #626, #627 and #628 had all merged too and PR #634 had
claimed 0300 ahead of plan 22.

Renumbered again on 2026-09-28, once more: issue [#552](https://github.com/datagen24/victual/issues/552)
(D4, #487 remediation)'s products foreign-key migration, also carrying issue
[#558](https://github.com/datagen24/victual/issues/558)'s label-retirement fix, is being
written on this branch as `0295.pgsql.sql`, above plan 22's still-unwritten claims. Per the
lowest-free-slot rule, the file the branch is actually writing takes the lowest free slot and
plan 22's two unwritten claims move up in turn, from 0295–0296 to **0296–0297**, keeping their
own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this
table. The next unclaimed number is now **0298**.

Renumbered again on 2026-09-29, yet again: three branches claimed the same lowest free slots
in parallel. PR [#624](https://github.com/datagen24/victual/pull/624) (issues #552/#558) writes
`0295.pgsql.sql`. PR [#626](https://github.com/datagen24/victual/pull/626) (issue #516) writes
`0296.pgsql.sql`. Both take the lowest free slots ahead of this branch.

Issue [#492](https://github.com/datagen24/victual/issues/492) (H3, #487 remediation)'s
`stock_amount_non_negative_check` fix is written on this branch as `0297.pgsql.sql`, the next
lowest free slot once 0295–0296 are spoken for, above plan 22's still-unwritten claims. Per the
lowest-free-slot rule, plan 22's two unwritten claims move up in turn, from 0295–0296 to
**0298–0299**, keeping their own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s
numbering note moves with this table.

**Update, same day:** PR #624 has since merged to `master` (18d0389d), so 0295's row above now
reads its real, landed content rather than a forward-looking placeholder, and its status moves
from "in this tree" to "in `master`".

**Update, 2026-09-29, later still:** PR #626 has now also merged to `master` (11783f76). 0296's
row above likewise reads its real, landed content, and its status moves from "claimed" to
"in `master`". Only 0297 (this tree, issue #492) remains unmerged among the three. 0295 and
0296 are both files on disk now, and this branch supplies 0297 itself, so the migration-
numbering check no longer needs `--allow-reserved-holes` waived to pass on this branch. The
next unclaimed number is still **0300**.

Renumbered again on 2026-09-28, yet again: issues [#543](https://github.com/datagen24/victual/issues/543)
and [#546](https://github.com/datagen24/victual/issues/546) (#487 remediation)'s
`trg_cascade_change_qu_id_stock` fix is being written on this branch as `0294.pgsql.sql`, above
plan 22's still-unwritten claims. Per the lowest-free-slot rule, the file the branch is
actually writing takes the lowest free slot and plan 22's two unwritten claims move up in
turn, from 0294–0295 to **0295–0296**, keeping their own order.
[Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this table. The
next unclaimed number is now **0297**.

Renumbered again on 2026-09-28: issue [#508](https://github.com/datagen24/victual/issues/508)
(M8, #487 remediation)'s `product_groups_missing` roll-up migration, deciding
[ADR-0034](../docs/adr/0034-product-group-minimum-counts-descendant-groups.md), is being
written on this branch as `0293.pgsql.sql`, above plan 22's still-unwritten claims. Per the
lowest-free-slot rule, the file the branch is actually writing takes the lowest free slot and
plan 22's two unwritten claims move up in turn, from 0293–0294 to **0294–0295**, keeping their
own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this
table. The next unclaimed number is now **0296**.

The file under 0262 was edited in place during review rather than followed by a migration
that drops a column, because it has never existed in `master`. The retirement rule above is
about numbers that have, and a branch that has not merged is still deciding what its
migration says. What changed is that `login_attempts` lost its `ip_address` column — see that
file for why a per-address count is the proxy's job and not this application's.

Renumbered 2026-09-28, later still again: issue [#588](https://github.com/datagen24/victual/issues/588)
(#487 remediation) fixes `trg_stock_log_DEL`'s id/product_id confusion and is being written on
this branch as `0292.pgsql.sql`, above plan 22's still-unwritten claims. Per the lowest-free-slot
rule, the file the branch is actually writing takes the lowest free slot and plan 22's two
unwritten claims move up in turn, from 0292–0293 to **0293–0294**, keeping their own order.
[Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this table. The
next unclaimed number is now **0295**.

Renumbered 2026-09-28, later still: issue [#506](https://github.com/datagen24/victual/issues/506)'s
maintainer decision D5 is being written on this branch as `0291.pgsql.sql`. It adds an explicit
`chores_log.stock_transaction_id` column. This replaces an earlier round's `xmin`-based link,
after a second validator round showed `xmin` identifies a whole transaction rather than one
execution. 0291 is the lowest free slot now that PR #580's move (below) has settled `0290`.
Plan 22's two unwritten claims move up in turn, from 0291–0292 to **0292–0293**, keeping their
own order. [Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this
table. The next unclaimed number is now **0294**.

Renumbered 2026-09-28: issue [#487](https://github.com/datagen24/victual/issues/487)
remediation's ADR-0033 decision-3 migration (PR #580, narrowing `stock_splits` to
never-expiring, unlabelled rows for issues 488 and 491) was written to disk as
`0292.pgsql.sql`, above plan 22's still-unwritten 0290–0291 — the hole
`check-migrations.php` refuses without `--allow-reserved-holes`, which CI does not set. Per
the lowest-free-slot rule, the file moves down to **0290** and plan 22's two unwritten claims
move up in turn, from 0290–0291 to **0291–0292**, keeping their own order.
[Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this table.
The next unclaimed number is now **0293**.

Renumbered 2026-09-26: issue [#487](https://github.com/datagen24/victual/issues/487)
remediation's view-correction migration (PR #542, fixing issues 501, 505, 497 and the
weekly-schedule half of 506) takes **0289** under the lowest-free-slot rule; plan 22's two
unwritten claims move from 0289–0290 to **0290–0291**, keeping their own order.
[Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this table.
The next unclaimed number is now **0292**.

Renumbered 2026-09-24: issue 461 takes 0288 under the lowest-free-slot rule.
Plan 22's unwritten claims move to 0289–0290; neither had a file on disk.

Renumbered 2026-09-19, the lowest-free-slot rule once more. Issue #208 was written saying
"0289 as of 2026-09-19", counting plan 22's two claims as taken; but a written 0289 above
unwritten 0287 and 0288 is the hole `check-migrations.php` refuses, and plan 22 is still
unscheduled. So #208 takes 0287 and plan 22's claims move up one each, keeping their order.

Renumbered 2026-09-14, the eighth application of the lowest-free-slot rule: plans 28, 29, 30 and 31 were all scheduled into wave 4 while plan 22 stays unscheduled, and a written 0277 above an unwritten 0275 is the hole the second check refuses. Nothing had run under any of these numbers. This move happened on `master` while plan 23's own migration was still landing on this branch; 0274 itself did not move — both branches agree it is plan 23's, and it already has a file on disk.

Renumbered again 2026-09-15, the ninth application of the same rule and the first where the
number displaced is a plan's own rather than a moving pair of drafts. Plan 30 names its own
prerequisite in its header: "Fix issue 148 before writing it: the nesting-level trigger this
plan copies fires only on `UPDATE`." That fix is being written now, on this branch, which
makes it the thing with a real file behind it — the same standing plans 03, 25 and 27 had on
the fifth, sixth and seventh moves — while 0278–0281 remain claims with no file.

So the fix takes the lowest free slot, 0277, and plans 30, 31 and 22 each move up by one: 30
to 0278, 31 to 0279, 22 to 0280–0281. [Plan 30](../docs/plans/landed/30-nested-product-groups.md) and
[31](../docs/plans/landed/31-directed-substitution.md)'s own migration-number lines move with this
table; [22](../docs/plans/22-medication-tracking.md)'s numbering note does too.

## The merge order this implies — discharged

    #33 (boot check)  →  #34/#39 (0258, files)  →  #36 (0257 + 0259, plan 18)

**All of it has happened.** #33 landed first, so the boot check verifies the complete
required migration set rather than the highest recorded number. #34's work reached `master`
through #39 — #34 had merged into a branch that was itself already consumed, which is why
0258 appeared to be in `master` before it was — and `migrations/0258.pgsql.sql` is there now.
#36 merged `master` afterwards, so this tree has 0256 through 0259 with no hole, and
`check-migrations.php` passes without `--allow-reserved-holes`.

0260 is a data migration, not a schema one: it is PHP rather than SQL, it adds and alters
nothing, and it runs `StoredHtmlPurifier` over the five columns in
`BaseApiController::HTML_RENDERED_COLUMNS`. It is portable in one file because PDO is, so it
needs no engine pair under [ADR-0004](../docs/adr/0004-engine-specific-migrations.md).

0277 is a defect fix, not a plan — the same case 0260, 0261 and 0267 are. Per the ninth move
above, it took the lowest free slot rather than the next one after 0276, displacing plan
30 (and, in train, 31 and 22) up by one.

**Renumbered again 2026-09-15, the tenth application of the same rule, and the first time
this branch had to correct its own earlier claim rather than someone else's.** Plan 19
piece 2 (issue 84) first claimed 0282, on the reasoning that 0280-0281 were "already
claimed, so the next free number is the one after them." That reasoning is exactly
the mistake the fifth through ninth moves exist to prevent: it treated 0280-0281 as
occupied because a name was written next to them.

The rule has never been about whether a number carries a claim, only about whether it
carries a *file*. `check-migrations.php` said so directly, on this branch's own CI run: a
file at 0282 with nothing on disk at 0280-0281 is the hole the second check refuses, waiver
or not, and CI does not set `--allow-reserved-holes`. So a branch that leaves itself a hole
under its own migration is not mergeable by its own doing, not by 22's.

Plan 19 piece 2 is scheduled (wave 5) and its file is being written on this branch, while 22
remains an unscheduled draft with no file behind either of its two numbers. Per the rule the
fifth through ninth moves already established, the number about to have a file takes the
lowest free slot and the unscheduled claim moves up.

So plan 19 piece 2 takes **0280** (displacing its own prior 0282 claim down
by two) and plan 22 moves from 0280-0281 to **0281-0282**, keeping its own two-number span
intact. [Plan 22](../docs/plans/22-medication-tracking.md)'s numbering note moves with this
table. The next unclaimed number is now **0283**.

0263 and 0264 are one change in two numbers on purpose: the column has to exist before the
data migration that fills it runs, and a number selects a file rather than an ordering
within one. 0264 is PHP for the same reason 0260 is — it is PDO doing arithmetic on rows,
which is portable in one file, and [ADR-0004](../docs/adr/0004-engine-specific-migrations.md)
asks for a pair only where the two engines genuinely need different SQL.

**0274 landed; 0275 to 0280 are claimed and no file exists for them yet.**
Plan 23 took the lowest free slot when its migration was written, per the same rule. The
highest number on disk is now 0274, and there is still no hole or waiver, because 0275–0280
sit *above* it rather than as a gap below it. That is the case this table's own argument is
about, and the reason no `--allow-reserved-holes` waiver is needed.

Plan 23's number is now fixed — it has a file on disk and does not move again, whatever else
gets renumbered around it. What sits behind it moved once more on `master` while this branch
was landing 0274 (see the renumbering note above the table). 28 owns 0275, 29 owns 0276, 30
owned 0277 and 31 owned 0278, ahead of 22 at 0279–0280, because all four were scheduled into
wave 4 while 22 remained an unscheduled draft.

The ninth move (see the 2026-09-15 renumbering note above) then took
0277 for the fix issue 148 asks plan 30 to depend on, moving 30 to 0278, 31 to 0279 and 22 to
0280–0281. The next unclaimed number is 0282.

**Plan 22 and 23's three numbers have now moved eight times without a line of SQL being written:**

1. Claimed as 0261–0262 while `master` was landing 0261 for [#46](https://github.com/datagen24/victual/issues/46).
2. To 0262–0264, until wave 2 landed 0262 through 0265.
3. To 0267–0269, until wave 3a took 0266.
4. To 0268–0270, to make room for 0267.
5. To 0269–0271, to make room for plan 03.
6. To 0271–0273, to make room for plan 25.
7. To 0273–0275, to make room for plan 27's two.
8. To 0274–0276, to make room for plan 08's one.

Each time the correction cost one table edit, because nothing had been written to disk under
the old numbers.

**A ninth move follows, and it breaks the pair.** Until now, 22's two numbers moved in
lock-step immediately behind 23's one, because 23 always merged first and 22 depended on it.
This move is different: 23's own number is fixed — it has a file on disk — so only 22's two
numbers move, from 0275–0276 to 0279–0280, to make room for 28, 29, 30 and 31 ahead of them.

**A tenth move follows the ninth's own pattern once more, and for once the number displaced
is not 22's.** The ninth move above left 22 at 0280–0281. Plan 19 piece 2 then wrote a file
at 0282 without moving 22 out of the way first, leaving a hole at 0280–0281.
`--allow-reserved-holes` covered that hole locally, but CI, which does not set the waiver,
correctly refused it.

The fix is the same rule stated in reverse. The number with a file being
written now (0282, plan 19 piece 2) takes the lowest free slot, 0280, and 22 - still the
unscheduled draft with no file behind either number - moves up one more time, to 0281–0282.
Same rule as the fifth and sixth moves, applied to four numbers scheduled into wave 4 at
once rather than one or two, while 22 stays the unscheduled draft that keeps yielding.

**A tenth move, 2026-09-15, the same rule again.** Issue [#130](https://github.com/datagen24/victual/issues/130)
— plan 11's own listed follow-up, sweep S11's expiry-and-rotation half — is being written on
this branch now, which makes it the thing with a real file behind it; plan 22 is still an
unscheduled draft with none. So the migration this issue needs takes the lowest free slot,
0280, and plan 22's two numbers move up by one, to 0281–0282. The next unclaimed number is
**0283**.

**An eleventh move, discovered only at merge time rather than by either branch alone.**
Two branches each independently ran the tenth move's own rule against the same starting
state and landed on the same number. This plan's own piece 2 corrected itself onto 0280 (the
paragraph above this table titled "the tenth application of the same rule"), and, separately,
issue #130 also took 0280 (the tenth move immediately above this one). Both were true when
each was written, on branches that had not yet seen each other.

`git merge` surfaced it as an
add/add conflict on `migrations/0280.pgsql.sql` rather than as a silently-overwritten file.
That is the mechanical reason a collision this table exists to prevent still reached a merge
instead of being caught by a claim. Neither branch's claim was wrong when made, and this
table cannot serialize two branches that have not yet talked to each other.

Resolution follows the retirement rule rather than the lowest-free-slot rule, because for the
first time one side of the collision is not a claim but a landed file. Issue #130's 0280 is
already `in master`, and "a number is retired, never reused" (above) means it cannot move,
whichever branch merges second.

Plan 19 piece 2 therefore moves again, off 0280 and onto the
next free slot, 0281; plan 22 — still the unscheduled draft yielding to every scheduled or
already-written thing that needs a number — moves up one more time, from 0281–0282 to
**0282–0283**. The next unclaimed number is now **0284**.

**2026-09-15, later the same day:** plan 32 claims **0284** on the lowest-free-slot rule; it is
gated on ADR-0024's acceptance and yields to nothing scheduled ahead of it. The next unclaimed
number is now **0285**.

**2026-09-15, later again — the tenth application of the rule, and the first where both
displaced claims are drafts.** [Issue #176](https://github.com/datagen24/victual/issues/176)'s
migration is written and on disk as `0282.pgsql.php`, so it takes the lowest free slot by the
rule this table keeps restating: the numbers that get written take the lowest free slots, and
claims without files behind them yield.

Plan 22 is still the unscheduled draft it was on every
previous move, and plan 32 is gated on ADR-0024, which is **Proposed**. Neither has a file,
so both yield and both keep their relative order: 22 moves from 0282–0283 to **0283–0284** and 32
from 0284 to **0285**. Their own numbering lines move with this table. The next unclaimed
number is now **0286**.

That is two collisions in two days, and both have the same mechanical cause the note above
already names. Plan 32's claim and this migration were made hours apart by branches that could
not see each other, and this table cannot serialize branches that have not talked. Neither
claim was wrong when it was made. What the rule does is decide the tie without either branch
having to be at fault — a claim is a placeholder, a file is a fact, and the placeholder moves.

**2026-09-16 — an eleventh move, and the first this branch made against itself rather than
against another branch.** Plan 32's migration was written on this branch under its claimed
number, 0285, while 22's two numbers, 0283–0284, stayed unwritten claims below it — a hole
`--allow-reserved-holes` waived locally throughout implementation, exactly as the waiver is
for.

CI does not set that waiver, and its `suite` job refused the branch on those same two
numbers once 0285 had a file behind it. The rule this table keeps restating cuts the same way
here as it did against issue #176 and issue #130: a number that has a file takes the lowest
free slot, and a claim without one yields, whoever holds each.

Applying it: plan 32 moves from
0285 to **0283**, the lowest free slot below its own written file, and plan 22's two numbers
move up in turn, from 0283–0284 to **0284–0285**. `check-migrations.php` then passes with no
waiver needed. [Plan 32](../docs/plans/landed/32-label-kinds.md)'s own numbering line moves with this
table. The next unclaimed number is still **0286**.

**2026-09-18:** plan 05 parts A and C claim **0286**, the lowest free slot; plan 22's
0284–0285 are unaffected. The next unclaimed number is **0287**.

**2026-09-18, later — the hole this table exists to prevent reached `master`, and the
lowest-free-slot rule could not fix it.** 0286 merged (#201) while plan 22's 0284–0285 were
still unwritten claims below it. That was the tree's first hole in `master`: every earlier
one was closed on a branch, by moving the *file* down to the lowest free slot before anything
had run it.

Here the file is in `master`, so its number is retired by the rule at the top of this
document. "Moved down at merge time" is only safe while no database anywhere has run it,
which cannot be promised for a number that has been on `master` for hours. `check-migrations.php`
failed on `master` itself from that merge on, so the `suite` job was red for every branch
until this.

So the *hole* moved instead of the file. **0284 and 0285 are now written, as `SELECT 1`
migrations**, which is what the rule already allowed ("a 0258 arriving later is applied"): a
database that already ran 0286 applies them on its next start, and one that has not applies all
three in order.

Plan 22's two unwritten claims moved up to **0287–0288** — a fourteenth move,
and the first that costs two numbers rather than nothing, because the numbers were spent on
placeholders rather than merely renamed. They are two of a number space that is not scarce, and
plan 22 is still an unscheduled draft.

**The lesson for the next branch:** a migration
scheduled ahead of an unwritten claim must take that claim's number, or move it up *in the
same pull request*. Once it is in `master`, the only way to close the gap is to write
something into it. The next unclaimed number is **0289**.

The eighth move is the fifth's case for the third time, and the plan it moves for is not a
draft: **[plan 08](../docs/plans/landed/08-nested-locations.md) is scheduled, its questions are
answered, and its migration is being written on this branch**. 22 and 23 still have no
delivery slot. So 08 takes 0273 — the lowest free slot, since 0269–0272 are on disk — and 23
moves to 0274 with 22 behind it at 0275–0276, keeping the one ordering constraint between
them.

Nothing claimed and unwritten now sits below 0273, so the branch carrying
`0273.pgsql.sql` passes `check-migrations.php` without `--allow-reserved-holes`. Plan 23 is
the one to re-read after this: it adds `locations.storage_class_id` to the same table 08
reshapes, and it will now do so on top of `parent_location_id` rather than beside it.

The rule applied on the seventh move is the same one as on the sixth: **scheduled work takes
the lowest free slots**. Plan 27 is scheduled into wave 3b alongside 25 and its first file is
being written now; 22 and 23 remain drafts with no delivery slot. Plan 27's own migration
inventory said this decision belonged to "the branch that writes the first file", and this is
that branch.

The fourth move was the first where the number was taken by a change that
had already been written rather than by one being planned. 0267 is a defect fix — it is
neither a dependency of these three nor dependent on them, and it replaces
`stock_edited_entries` in place with `CREATE OR REPLACE VIEW`, so it drops no view another
migration might be rebuilding. Leaving it at 0270 would have left that tree with a hole at
0267–0269 and unmergeable until two unwritten plans landed, which is a long time for a
one-table edit to save.

The fifth was the ordinary case the rule was written for: plan 03 is
*scheduled* — wave 3b — while 22 and 23 are drafts with no delivery slot. So the number that
is about to have a file behind it takes the lowest free slot, and the drafts move up.

Doing it
the other way round would have put 0271 on disk above a three-number hole that nothing was
working to close, and `check-migrations.php` would have refused the branch until two
unscheduled plans landed. That is the argument for
claiming here before writing rather than before merging, made at the smallest possible
scale — and a reason a long-lived draft should re-check this table at every resync rather than
trusting a number it claimed a week ago.

The sixth is [plan 25](../docs/plans/25-label-infrastructure.md), and it is the fifth's case
again with one number more. Plan 25 is scheduled into wave 3b and needs two numbers; 22 and 23
remain drafts with no delivery slot. So 25 takes 0269–0270 and the drafts move up to 0271–0273,
preserving the one ordering constraint between them — 23 before 22.

Had 25 taken 0272–0273
instead, it would have put the only migrations anyone is about to write on disk above a
three-number hole, and `check-migrations.php` would have refused the wave 3b branch until two
unscheduled plans landed. The rule keeps producing the same answer because the situation keeps
being the same one: the numbers that get written take the lowest free slots, and claims without
files behind them yield.

**The waiver stays.** `--allow-reserved-holes` (and `SUITE_ALLOW_RESERVED_HOLES=1`) is not
scaffolding for this one branch: the situation recurs by construction, because parallel plan
branches each need a number before any of them merges, and the roadmap has several waves of
those left.

Removing it would not make the check any stricter — a tree with a hole still
fails without it. It would only take away the thing that let this branch run its own suite
for the three rounds it spent waiting, which is the difference between an enforcement and a
wall. It is opt-in, it prints what it waived, and CI does not set it.

Note that 0257 and 0259 are both plan 18's while 0258 is not. That is not a mistake, and it is
not fixable by renumbering within one branch. 0258 was claimed by plan 01 while plan 18's
first migration was already written, and moving plan 18's second migration down to 0258
would collide rather than close the hole.

[Plan 01](../docs/plans/landed/01-file-storage.md) was written calling its migration
`0257.pgsql.sql`, before plan 18 took 0257; what it ships is `0258.pgsql.sql`, which is now
in `master` and settles the question. Whether that plan's own body still says otherwise is
for a reader of it to check — this table is the authority on the number either way.
