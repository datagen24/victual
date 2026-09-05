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
| 0267 | [plan 23](../docs/plans/23-storage-classes.md) — `storage_classes`, `locations.storage_class_id` | **claimed, unwritten** |
| 0268 | [plan 22](../docs/plans/22-medication-tracking.md) — `medication_products`, `medication_stock_attributes`, `subjects` | **claimed, unwritten** |
| 0269 | [plan 22](../docs/plans/22-medication-tracking.md) — `regimens`, `regimen_doses`, `administrations`, `storage_excursions` | **claimed, unwritten** |

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

The next migration takes **0270** and claims it here first.

0263 and 0264 are one change in two numbers on purpose: the column has to exist before the
data migration that fills it runs, and a number selects a file rather than an ordering
within one. 0264 is PHP for the same reason 0260 is — it is PDO doing arithmetic on rows,
which is portable in one file, and [ADR-0004](../docs/adr/0004-engine-specific-migrations.md)
asks for a pair only where the two engines genuinely need different SQL.

**0267 to 0269 are claimed by drafts and no file exists for any of them yet.**
Wave 3a takes 0266, so those unwritten reservations moved together before its migration
was written. The highest number on disk is 0266 and there is no hole or waiver.

Plan 23 still merges before 22: it owns 0267 and supplies `locations.storage_class_id`;
22 owns 0268–0269. The next unclaimed number is 0270.

**These three moved twice before wave 3a without a line of SQL being written**: claimed as 0261–0262
while `master` was landing 0261 for [#46](https://github.com/datagen24/victual/issues/46), then
0262–0264 until wave 2 landed 0262 through 0265. Both times the correction cost one table edit,
because nothing had been written to disk under the old numbers. That is the argument for
claiming here before writing rather than before merging, made twice at the smallest possible
scale — and a reason a long-lived draft should re-check this table at every resync rather than
trusting a number it claimed a week ago.

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
