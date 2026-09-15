# Migration numbers above the baseline

Every migration number after `DatabaseMigrationService::BASELINE_MIGRATION_ID` (0255) is
claimed here before it is written, and stays claimed after it lands. This is a record
rather than a courtesy: `.devtools/pgsql/check-migrations.php` parses the table below and
fails when the numbers on disk and the numbers claimed here disagree.

**Why the record exists.** Plans are worked in parallel branches, and each one needs a
migration number before any of them merges. Numbers handed out on a branch collide or leave
holes, and a hole is worse than a collision because nothing complains: a database migrated
through a tree that has 0257 and 0259 but not 0258 records `MAX(migration) = 259`, so
anything that asks "is this database at the latest number?" — `GetLatestMigrationNumber()`,
`DatabaseImporter`'s two-sided comparison, plan 10's boot check — is satisfied by a database
that never ran 0258. The migration *runner* is not fooled (it asks per number whether a row
exists, so a 0258 arriving later is applied), but every gate built on the maximum is, and it
is a gate that decides whether a deployment is allowed to serve.

**So the sequence above the baseline has no holes in a mergeable tree.** A branch that
carries 0259 while 0258 lives in another branch is *not independently mergeable*, and the
check says so by name rather than leaving it to be noticed in review. The branch owning the
lower number merges first; alternatively the higher number is moved down at merge time,
which is only safe while no database anywhere has run it.

**Above 0265 a migration is PostgreSQL-only.** ADR-0008's retirement froze the SQLite line
at `DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID` = 0265, because SQLite is an input
format now and an input format's upper bound has to stop moving: nothing here migrates a
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
| 0258 | [plan 01](../docs/plans/01-file-storage.md) — the files table | in `master` (PR #39) |
| 0259 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `outbox` | in this tree |
| 0260 | [plan 21](../docs/plans/21-frontend-sink-discipline.md) — purify stored rich text that predates the API purifier | in this tree |
| 0261 | [issue #46](https://github.com/datagen24/victual/issues/46) — a total order for `products_last_purchased.price`, and SQLite's integer division in `products_average_price` | in this tree |
| 0262 | [security sweep S12](../docs/security-sweep.md) via wave 2 — `login_attempts`, the login throttle's out-of-process state | in this tree |

The file under 0262 was edited in place during review rather than followed by a migration that drops a column, because it has never existed in `master`: the retirement rule above is about numbers that have, and a branch that has not merged is still deciding what its migration says. What changed is that `login_attempts` lost its `ip_address` column — see that file for why a per-address count is the proxy's job and not this application's.

| 0263 | [plan 11](../docs/plans/11-api-error-handling.md) question 4 — `api_keys.key_hint` | in this tree |
| 0264 | [plan 11](../docs/plans/11-api-error-handling.md) question 4 — hash the stored API keys, backfill the hint | in this tree |
| 0265 | [security sweep S12](../docs/security-sweep.md) via wave 2 — `users.must_change_password`, moved out of `user_settings` in review | in this tree |
| 0266 | [plan 19](../docs/plans/19-rbac.md) — roles and read permissions (wave 3a) | in this tree |
| 0267 | the split-entry defect in `products_average_price` — `stock_entry_origins`, and `stock_edited_entries` following it | in this tree |
| 0268 | [plan 03](../docs/plans/03-category-min-stock.md) — `product_groups.min_stock_amount`, `product_groups_missing` (wave 3b) | in this tree |
| 0269 | [plan 25](../docs/plans/25-label-infrastructure.md) — `labels`, the uid-to-target mapping [ADR-0011](../docs/adr/0011-label-namespace.md) requires (wave 3b), plus the import epoch | in this tree |
| 0270 | [plan 25](../docs/plans/25-label-infrastructure.md) group B — ten tables: eight configuration/job/delivery tables plus `label_worker_sessions` and `label_worker_credentials` for durable pairing and pending rotation; templates/artifacts belong to plan 27 | in this tree |
| 0271 | [plan 27](../docs/plans/27-label-templates-and-rendering.md) group A — `label_templates`, `label_template_drafts`, `label_template_versions`, `label_assets`, `label_media_profiles` (wave 3b) | in this tree |
| 0272 | [plan 27](../docs/plans/27-label-templates-and-rendering.md) group B — `label_captures`, `label_render_requests`, `label_artifacts`, `label_idempotency_keys`, and the artifact/operation columns on plan 25's `print_jobs` (wave 3b) | in this tree |
| 0273 | [plan 08](../docs/plans/08-nested-locations.md) — `locations.parent_location_id`, `locations_resolved`, `hierarchy_depth_limit()` and the nesting guards | in this tree |
| 0274 | [plan 23](../docs/plans/23-storage-classes.md) — `storage_classes`, `locations.storage_class_id` | in this tree |
| 0275 | [plan 28](../docs/plans/28-open-container-measurement.md) — the measured-remainder columns on `stock` (`opened_amount`, `opened_qu_id`, `opened_tare`, `opened_measured_at`) and their coherence constraint (wave 4) | in this tree |
| 0276 | [plan 29](../docs/plans/29-working-container-replenishment.md) — the (product, location) minimum table and its shortfall view, and `locations.tare_weight`/`tare_qu_id` (wave 4) | in this tree |
| 0277 | [issue #148](https://github.com/datagen24/victual/issues/148) — `enfore_product_nesting_level` fires on `INSERT` as well as `UPDATE`, checks the nesting relationship in both directions, and nulls out any existing multi-level chain | in this tree |
| 0278 | [plan 30](../docs/plans/30-nested-product-groups.md) — `product_groups.parent_product_group_id`, the `UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT` replacement, `product_groups_resolved` and the nesting guards (wave 4) | in this tree |
| 0279 | [plan 31](../docs/plans/31-directed-substitution.md) — the directed product substitution edges and their view (wave 4) | in this tree |
| 0280 | [plan 22](../docs/plans/22-medication-tracking.md) — `medication_products`, `medication_stock_attributes`, `subjects` | **claimed, unwritten** |
| 0281 | [plan 22](../docs/plans/22-medication-tracking.md) — `regimens`, `regimen_doses`, `administrations`, `storage_excursions` | **claimed, unwritten** |
| 0282 | [plan 19](../docs/plans/19-rbac.md) piece 2, [issue 84](https://github.com/datagen24/victual/issues/84) — `STOCK_PRICES_VIEW`, `permission_fields` and its seed (wave 5) | in this tree |

Renumbered 2026-09-14, the eighth application of the lowest-free-slot rule: plans 28, 29, 30 and 31 were all scheduled into wave 4 while plan 22 stays unscheduled, and a written 0277 above an unwritten 0275 is the hole the second check refuses. Nothing had run under any of these numbers. This move happened on `master` while plan 23's own migration was still landing on this branch; 0274 itself did not move — both branches agree it is plan 23's, and it already has a file on disk.

Renumbered again 2026-09-15, the ninth application of the same rule and the first where the
number displaced is a plan's own rather than a moving pair of drafts. Plan 30 names its own
prerequisite in its header: "Fix issue 148 before writing it: the nesting-level trigger this
plan copies fires only on `UPDATE`." That fix is being written now, on this branch, which
makes it the thing with a real file behind it — the same standing plans 03, 25 and 27 had on
the fifth, sixth and seventh moves — while 0278–0281 remain claims with no file. So the fix
takes the lowest free slot, 0277, and plans 30, 31 and 22 each move up by one: 30 to 0278, 31
to 0279, 22 to 0280–0281. [Plan 30](../docs/plans/30-nested-product-groups.md) and
[31](../docs/plans/31-directed-substitution.md)'s own migration-number lines move with this
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

0277 is a defect fix, not a plan — the same case 0260, 0261 and 0267 are, and per the ninth
move above it took the lowest free slot rather than the next one after 0276, displacing plan
30 (and, in train, 31 and 22) up by one. 0282 is plan 19 piece 2's own number: it is scheduled
(wave 5) and its file is being written on this branch, while 22's 0280-0281 stay claims with
no file, so 0282 is the next free slot rather than a hole above the pair. The next unclaimed
number is now **0283**.

0263 and 0264 are one change in two numbers on purpose: the column has to exist before the
data migration that fills it runs, and a number selects a file rather than an ordering
within one. 0264 is PHP for the same reason 0260 is — it is PDO doing arithmetic on rows,
which is portable in one file, and [ADR-0004](../docs/adr/0004-engine-specific-migrations.md)
asks for a pair only where the two engines genuinely need different SQL.

**0274 landed; 0275 to 0280 are claimed and no file exists for them yet.**
Plan 23 took the lowest free slot when its migration was written, per the same rule: the
highest number on disk is now 0274 and there is still no hole or waiver, because 0275–0280
sit *above* it rather than as a gap below it, which is the case this table's own argument is
about and the reason no `--allow-reserved-holes` waiver is needed.

Plan 23's number is now fixed — it has a file on disk and does not move again, whatever else
gets renumbered around it. What sits behind it moved once more on `master` while this branch
was landing 0274 (see the renumbering note above the table): 28 owns 0275, 29 owns 0276, 30
owned 0277 and 31 owned 0278, ahead of 22 at 0279–0280, because all four were scheduled into
wave 4 while 22 remained an unscheduled draft. The ninth move (see above the table) then took
0277 for the fix issue 148 asks plan 30 to depend on, moving 30 to 0278, 31 to 0279 and 22 to
0280–0281. The next unclaimed number is 0282.

**Plan 22 and 23's three numbers have now moved eight times without a line of SQL being written**:
claimed as 0261–0262
while `master` was landing 0261 for [#46](https://github.com/datagen24/victual/issues/46), then
0262–0264 until wave 2 landed 0262 through 0265, then 0267–0269 until wave 3a took 0266, then
0268–0270 to make room for 0267, then 0269–0271 to make room for plan 03, then 0271–0273 to
make room for plan 25, then 0273–0275 to make room for plan 27's two, and now 0274–0276 to make room for
plan 08's one. Each time the
correction cost one table edit,
because nothing had been written to disk under the old numbers.

**A ninth move follows, and it breaks the pair.** Until now, 22's two numbers moved in
lock-step immediately behind 23's one, because 23 always merged first and 22 depended on it.
This move is different: 23's own number is fixed — it has a file on disk — so only 22's two
numbers move, from 0275–0276 to 0279–0280, to make room for 28, 29, 30 and 31 ahead of them.
Same rule as the fifth and sixth moves, applied to four numbers scheduled into wave 4 at
once rather than one or two, while 22 stays the unscheduled draft that keeps yielding.

The eighth move is the fifth's case for the third time, and the plan it moves for is not a
draft: **[plan 08](../docs/plans/08-nested-locations.md) is scheduled, its questions are
answered, and its migration is being written on this branch**, while 22 and 23 still have no
delivery slot. So 08 takes 0273 — the lowest free slot, since 0269–0272 are on disk — and 23
moves to 0274 with 22 behind it at 0275–0276, keeping the one ordering constraint between
them. Nothing claimed and unwritten now sits below 0273, so the branch carrying
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
*scheduled* — wave 3b — while 22 and 23 are drafts with no delivery slot, so the number that
is about to have a file behind it takes the lowest free slot and the drafts move up. Doing it
the other way round would have put 0271 on disk above a three-number hole that nothing was
working to close, and `check-migrations.php` would have refused the branch until two
unscheduled plans landed. That is the argument for
claiming here before writing rather than before merging, made at the smallest possible
scale — and a reason a long-lived draft should re-check this table at every resync rather than
trusting a number it claimed a week ago.

The sixth is [plan 25](../docs/plans/25-label-infrastructure.md), and it is the fifth's case
again with one number more. Plan 25 is scheduled into wave 3b and needs two numbers; 22 and 23
remain drafts with no delivery slot. So 25 takes 0269–0270 and the drafts move up to 0271–0273,
preserving the one ordering constraint between them — 23 before 22. Had 25 taken 0272–0273
instead, it would have put the only migrations anyone is about to write on disk above a
three-number hole, and `check-migrations.php` would have refused the wave 3b branch until two
unscheduled plans landed. The rule keeps producing the same answer because the situation keeps
being the same one: the numbers that get written take the lowest free slots, and claims without
files behind them yield.

**The waiver stays.** `--allow-reserved-holes` (and `SUITE_ALLOW_RESERVED_HOLES=1`) is not
scaffolding for this one branch: the situation recurs by construction, because parallel plan
branches each need a number before any of them merges, and the roadmap has several waves of
those left. Removing it would not make the check any stricter — a tree with a hole still
fails without it — it would only take away the thing that let this branch run its own suite
for the three rounds it spent waiting, which is the difference between an enforcement and a
wall. It is opt-in, it prints what it waived, and CI does not set it.

Note that 0257 and 0259 are both plan 18's while 0258 is not. That is not a mistake and is
not fixable by renumbering within one branch: 0258 was claimed by plan 01 while plan 18's
first migration was already written, and moving plan 18's second migration down to 0258
would collide rather than close the hole.

[Plan 01](../docs/plans/01-file-storage.md) was written calling its migration
`0257.pgsql.sql`, before plan 18 took 0257; what it ships is `0258.pgsql.sql`, which is now
in `master` and settles the question. Whether that plan's own body still says otherwise is
for a reader of it to check — this table is the authority on the number either way.
