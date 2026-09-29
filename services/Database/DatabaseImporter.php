<?php

namespace Victual\Services\Database;

use Victual\Services\DatabaseMigrationService;
use Victual\Services\Labels\LabelIdentityService;

/**
 * Copies the contents of an existing SQLite database into another engine, so that an
 * installation can move without losing its data.
 *
 * The schema is expected to already exist in the target - run the migrations there first.
 * This only moves rows, verbatim, and then purifies the five HTML-rendered columns in the
 * target: a source that predates the API's purifier carries payloads no later write path
 * would have accepted, and the target's migrations ran before the copy so migration 0260
 * cannot see them. See StoredHtmlPurifier.
 *
 * **Replacement scope**, precisely, because an import that replaces "most of" a database
 * without saying which parts is not a contract anyone can rely on (issue #496):
 *
 *   - Every table both engines have in common (GetCommonTables()) is truncated and replaced
 *     with the source's rows, verbatim - this is the import.
 *   - DERIVED_STATE_TABLES (`mqtt_product_entities`, the household's per-product MQTT
 *     opt-in; `login_attempts`, per-username throttle counters; `stock_entry_origins`,
 *     split-stock lineage - issue #565 for the latter two) is always cleared when the
 *     source predates the table (always, for `stock_entry_origins`, whose migration is
 *     above the SQLite freeze), even though there is no counterpart to copy back in: each
 *     is keyed to an identity (a product id, a username, a stock_id) this import is about
 *     to replace or renumber wholesale, so a row surviving under a stale key would apply to
 *     *something else* without anyone asking. See AssertDerivedStateIsEmpty().
 *   - `outbox` is neither copied from the source nor blindly cleared. ClearOutbox() deletes
 *     whatever nothing else references and dead-letters the rest: a row `print_jobs` or
 *     `print_attempts` still points at survives (marked as never going to be delivered)
 *     rather than being deleted out from under that history's own foreign key. Issue #496
 *     (H7b) is "do not dispatch events describing replaced data", not "discard print
 *     history to get there."
 *   - `cache__products_average_price` and `cache__products_last_purchased` are copied
 *     verbatim like any other common table, then immediately recomputed from the copy by
 *     RebuildPriceCaches(), reusing migrations/0267.pgsql.sql's own rebuild statements: a
 *     source's own cached values can already be stale relative to what this engine's
 *     current view logic computes from the very rows just copied, because 0267 is
 *     PostgreSQL-only and no source in the supported span ever ran it. Issue #496's
 *     "recompute derived state where necessary."
 *   - NOT_COPIED_TABLES are never touched *directly* by the copy or the truncate - this
 *     class names none of them in a TRUNCATE statement and copies rows into none of them.
 *     One of them, `label_worker_credentials`, is nonetheless emptied *indirectly*: its
 *     `api_key_id` column references `api_keys` (migrations/0270.pgsql.sql:100), which *is*
 *     a common, replaced table, and PostgreSQL's `TRUNCATE ... CASCADE` empties every table
 *     that references a table it truncates regardless of that table's own membership in the
 *     statement. This is correct, not a leak this class should plug: a worker's stored
 *     credential is meaningless once the `api_keys` row it authenticates as is gone, so it
 *     should not survive attached to nothing, or silently attached to a different key that
 *     later reuses the same id. `migrations` is the target's own record of its own schema
 *     history, restored rather than replaced (see AssertSchemaVersionsMatch()). The
 *     remaining label-family tables are guarded instead of cleared: a live (non-retired)
 *     label refuses the whole import outright, --force included, so that nothing here ever
 *     leaves a live label pointing at data the import just replaced; a *retired* label's
 *     dead snapshot is history and survives. `label_import_state`'s epoch is bumped by one
 *     on every import instead, which is what actually invalidates any in-flight label
 *     request composed against the pre-import identities - see ADR-0021 and plan 25's
 *     "import epoch" and LabelIdentityService::Issue(). `mqtt_published_entities`
 *     belongs here too, for a different reason than the label tables: it describes what the
 *     *target's own broker connection* currently believes is published, not anything about
 *     the source's data, so an import - which is entirely about the data - has no correct
 *     answer for it in either direction. Copying it would tell this installation's broker
 *     about some other installation's topics; clearing it would silence every retraction
 *     MqttStatePublicationService owes for whatever was already published, permanently
 *     (PublicationLedger is the only record of what to retract). Left alone, exactly as
 *     everything else in this list, is the only correct answer.
 *   - `label_idempotency_keys`, `label_captures`, `label_render_requests` and
 *     `label_artifacts` are KEPT - never copied, never cleared, exactly as this class
 *     already leaves them today (they are absent from NOT_COPIED_TABLES, TARGET_ONLY_TABLES
 *     and DERIVED_STATE_TABLES alike, so GetCommonTables() simply reports them as missing
 *     from the source and this class touches none of them). Issue #565 raised them
 *     alongside login_attempts and stock_entry_origins as tables a replace-in-place import
 *     might leave keyed to data it just discarded; the maintainer's resolution (issue #565)
 *     is that, for these four specifically, "kept" is the correct classification rather than
 *     an oversight, for reasons the other two do not share:
 *       - None of the four exists in any source this importer accepts - all four are
 *         PostgreSQL-only, above the SQLite freeze (SUPPORTED_SOURCE_MIGRATION_MAX, 0265),
 *         so there is never a source-side value to reconcile against.
 *       - In the documented flow (bin/victual-db-import migrating a fresh target before
 *         copying into it - see this class's own opening paragraph), the target is freshly
 *         migrated and these four tables are already empty; there is nothing to clear.
 *       - On a --force import into a target that has been used, these four are print
 *         history - the exact same class of survivor PR #561 (issue #496, H7b) already
 *         chose to keep for `print_jobs`/`print_attempts`/`print_evidence`, not data this
 *         import is about to replace. `print_jobs.idempotency_key_id`,
 *         `.artifact_id`, `.render_request_id` and `.capture_id` (migrations/0272.pgsql.sql)
 *         reference exactly these four tables with plain `REFERENCES ... (id)` and no
 *         `ON DELETE` clause - PostgreSQL's default, `NO ACTION` - which
 *         `LabelOperationsService::Reprint()` (services/Labels/LabelOperationsService.php)
 *         relies on: a reprint reads a prior job's `artifact_id` straight through to its
 *         `label_captures`/`label_render_requests` rows, which a clear would have to either
 *         refuse (as the live-label guard already does for `labels` itself) or silently
 *         break. Nothing here proposes either; kept is the answer that needs no new
 *         decision.
 *       - `label_idempotency_keys` rows expire within 24 hours
 *         (migrations/0272.pgsql.sql's `expires_at`) regardless of whether an import ever
 *         runs, and the resource ids a stored response names (`resource_kind`/`resource_id`)
 *         are print-job-family ids, which this import does not renumber (`print_jobs` is
 *         NOT_COPIED_TABLES, kept with its existing ids intact) - so a key surviving an
 *         import is short-lived and still names what it always named, unlike
 *         `label_idempotency_keys`' entry in the earlier "STOP" analysis assumed.
 *   - TARGET_ONLY_TABLES (`roles`, `role_permissions`, `user_roles`, `permission_fields`,
 *     `user_settings_defaults`, `system_db_changed_time`) are this engine's own configuration
 *     rather than a household's data, seeded fresh rather than carried from a source that (for
 *     several of them) predates their existence entirely; db/pgsql/roles-seed.sql and
 *     db/pgsql/prices-seed.sql restore what TRUNCATE ... CASCADE clears via a foreign key
 *     into a table that *is* replaced (see the `applyRowMigrations` branch below).
 *   - Products imported with an unsupported multi-level parent chain (root -> middle -> leaf)
 *     are repaired the way migrations/0277.pgsql.sql repairs one on an in-place upgrade: the
 *     middle product's parent link is cleared, and the repair is named in the progress
 *     output. See RepairProductNesting() for why this can arrive at all despite the target's
 *     own guard trigger, and why refusing the import outright was not chosen instead.
 */
class DatabaseImporter
{
	/**
	 * Tables that belong to the target engine alone and have no counterpart in the source.
	 */
	const TARGET_ONLY_TABLES = ['user_settings_defaults', 'system_db_changed_time', 'roles', 'role_permissions', 'user_roles', 'permission_fields'];

	/**
	 * Tables that exist on both sides but are deliberately not copied.
	 *
	 * "migrations" is the target's own record of how its schema was built, and the
	 * target has just been migrated for its own engine. Overwriting that with the
	 * source's history would make a PostgreSQL database claim it had run migrations
	 * 0001-0255, which are SQLite-only and which it correctly replaced with a baseline.
	 * That was harmless only while the two engines happened to number alike: an
	 * engine-exclusive migration such as 0256.sqlite.sql breaks the tie, and a target
	 * carrying the source's numbers would then skip a future migration of its own with
	 * the same number, believing it already ran.
	 *
	 * `outbox` is here too, but is not simply left alone the way the rest of this list is:
	 * ClearOutbox() still surgically deletes or dead-letters its rows every import. It is
	 * excluded from the ordinary copy machinery specifically so that a source's own outbox
	 * rows - which describe *that* installation's history, not this one's - are never
	 * merged in, and so a plain TRUNCATE ... CASCADE (which the common-table path uses) can
	 * never reach it and take `print_jobs`/`print_attempts` down with it through the foreign
	 * keys migrations/0270.pgsql.sql:53,65 declare. See ClearOutbox() and
	 * AssertOutboxIsHandleable().
	 *
	 * `mqtt_published_entities` is here for a third reason again: it is not the source's data
	 * or the target's pre-import data, it is a record of the *target's own broker
	 * connection*'s state, which an import has no business touching in either direction. See
	 * the class docblock's replacement-scope list.
	 *
	 * None of this promises every table below is untouched in every sense:
	 * `label_worker_credentials` is emptied indirectly, by `TRUNCATE ... CASCADE` on
	 * `api_keys` (a common table, not in this list) - see the class docblock's
	 * replacement-scope list for why that is correct rather than an oversight.
	 */
	const NOT_COPIED_TABLES = ['migrations', 'labels', 'label_import_state', 'label_workers', 'label_drivers', 'label_worker_capabilities', 'label_printers', 'label_printer_status', 'print_jobs', 'print_attempts', 'print_evidence', 'label_worker_sessions', 'label_worker_credentials', 'outbox', 'mqtt_published_entities'];

	/**
	 * Target tables that must not survive under a stale reference once the data they are
	 * keyed to has been wholesale replaced, when a source within the supported span predates
	 * the table and so has no counterpart to copy back in - or, for a table above the SQLite
	 * freeze, when no source in the entire supported span ever will.
	 *
	 * Three tables, none of them "derived" in the sense of "computed from other rows" (this
	 * constant's name is a holdover from the first entry; see that entry's own note):
	 *
	 * - `mqtt_product_entities` (0257), the household's own opt-in list of which products
	 *   publish to MQTT - real, household-authored configuration, not derived state, but
	 *   keyed to product ids this import is about to replace. Left in place, a surviving row
	 *   would opt a *different* product (whichever now happens to hold that id) in without
	 *   anyone having asked for it.
	 * - `login_attempts` (0262), issue #565: failed-login throttle counters keyed to a
	 *   username, not to a user id - so a row survives an import unscathed even though the
	 *   account that username now names (if any) may be a different one than whichever
	 *   account tripped the counter before the import. A source at or above 0262 already
	 *   carries its own counters and copies them as an ordinary common table; this list only
	 *   closes the gap for a source that predates the table, exactly as for
	 *   `mqtt_product_entities` above.
	 * - `stock_entry_origins` (0267, issue #565): the lineage linking a split stock entry
	 *   back to the purchase, correction or self-production it came from (see that
	 *   migration's own docblock, "THE LINK"), keyed to `stock_id` values this import
	 *   replaces wholesale along with the rest of `stock`. Unlike the other two, no source in
	 *   the *entire* supported span can ever carry it - 0267 is PostgreSQL-only, above the
	 *   SQLite freeze (SUPPORTED_SOURCE_MIGRATION_MAX, 0265) - so this entry is always in the
	 *   gap GetCommonTables() leaves, never in the ordinary common-table path. It is cleared,
	 *   not rebuilt: the migration's own docblock says the mapping is "NOT BACKFILLED,
	 *   because the information does not exist" once an entry has been consumed away or
	 *   merged, and nothing else in this class (or the source, which never recorded it)
	 *   knows which surviving stock_id used to be whose split remainder. `stock_id` is a
	 *   uniqid-generated text value, copied verbatim rather than reassigned by this import,
	 *   so a surviving row simply dangles - it points at a stock_id that no longer exists,
	 *   never at an unrelated row that happens to reuse it (nothing here reuses ids at all).
	 *
	 * When the source predates the table (or, for `stock_entry_origins`, always),
	 * GetCommonTables() correctly leaves it out of the common-table set - there is nothing in
	 * the source to copy - but "nothing to copy" must not be read as "leave the target's rows
	 * alone": every other table in the target is truncated and replaced by this import. So
	 * this is always cleared, whether or not the source has a counterpart to repopulate it
	 * from - see ImportSnapshot() and AssertDerivedStateIsEmpty(). When the source *does*
	 * carry the table it is already a common table, copied and truncated the ordinary way;
	 * this list only closes the gap for a source that predates it.
	 */
	const DERIVED_STATE_TABLES = ['mqtt_product_entities', 'login_attempts', 'stock_entry_origins'];

	/**
	 * The SQLite-dialect migration numbers above DatabaseMigrationService::BASELINE_MIGRATION_ID
	 * (0255) and at or below SUPPORTED_SOURCE_MIGRATION_MAX (0265), for MigrationSetMismatch()'s
	 * source-side completeness check (issue #518, M18).
	 *
	 * A fixed list rather than DatabaseMigrationService::GetRequiredMigrationNumbers(new
	 * SqliteDialect()) - which is how the target side still computes its own required set,
	 * two paragraphs below - for two reasons specific to the source. First, the one this
	 * class's own AssertSchemaVersionsMatch() already gives for freezing
	 * SUPPORTED_SOURCE_MIGRATION_MIN/MAX instead of reading the migrations directory: the
	 * span this class promises to understand is a promise about foreign schemas, and a
	 * promise computed from whatever files happen to be in the tree changes when somebody
	 * moves a file - unlike the target's own required set, which is legitimately "whatever
	 * this running code ships now." Second, constructing a SqliteDialect object at all is
	 * exactly what AGENTS.md's SQLITE_TOOLING_ENV convention reserves for the differential
	 * tooling that still legitimately runs SQLite as more than an import format
	 * (DatabaseDialect::SqliteToolingIsPermitted()); this class already speaks SQLite
	 * directly over $this->Source without going through that gate, in the one way ADR-0008
	 * always permitted it to (SQLite as an input format), but a dialect *object* is a
	 * heavier claim this application-code class has no reason to make just to name a set of
	 * numbers.
	 *
	 * 0258 is the one number in this range with no SQLite file - migrations/0258.pgsql.sql
	 * has no .sqlite.sql or generic counterpart (see its own row in
	 * migrations/RESERVATIONS.md) - and is excluded for exactly that reason: it was never
	 * required on SQLite, so a source missing it has not skipped anything, and the committed
	 * victual-265.db fixture's own migrations table proves it, recording exactly the other
	 * nine of these ten numbers.
	 *
	 * Migrations 1-0255 are deliberately *not* listed here - not because that range is
	 * skipped, but because it needs no exception list: every one of those 255 numbers has a
	 * real, portable (pre-dual-engine) migration file, confirmed by enumeration, so it is
	 * checked at the AssertSchemaVersionsMatch() call site as a plain `range(1,
	 * BASELINE_MIGRATION_ID)` - arithmetic, not a directory read - merged with this list. An
	 * earlier version of this fix treated 1-0255 as implicitly satisfied by
	 * BASELINE_MIGRATION_ID alone and left it out of the check entirely, which is a
	 * regression this one number list cannot show by itself: it silently accepted a 0255
	 * source missing an interior migration such as 0200. See AssertSchemaVersionsMatch()'s
	 * call site and MigrationSetMismatch()'s own docblock.
	 *
	 * A number here is retired the moment it is spent, per migrations/RESERVATIONS.md's own
	 * rule, and SUPPORTED_SOURCE_MIGRATION_MAX is frozen - so, like those two constants,
	 * this list does not change after the fact; a new migration extends the *target's* own
	 * required set (computed dynamically, correctly, below) without ever touching this one.
	 */
	const SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE = [256, 257, 259, 260, 261, 262, 263, 264, 265];

	/**
	 * The oldest source schema this importer accepts, as a migration number.
	 *
	 * 0255 is the fork's squashed baseline, and it is also where upstream grocy 4.x stops -
	 * so the honest lower bound costs an adopter one boot of the software they are leaving
	 * rather than costing this fork an import surface across every historical schema delta.
	 * ADR-0008 question 1, answered at acceptance.
	 */
	const SUPPORTED_SOURCE_MIGRATION_MIN = DatabaseMigrationService::BASELINE_MIGRATION_ID;

	/**
	 * The newest source schema this importer accepts, as a migration number.
	 *
	 * The SQLite line's freeze: nothing in this repository produces a SQLite database past
	 * it, so a source claiming a higher number was written by something this fork does not
	 * know about, and guessing at it is worse than declining.
	 */
	const SUPPORTED_SOURCE_MIGRATION_MAX = DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID;

	/**
	 * Rows per multi-row INSERT. 250 keeps the placeholder count well under
	 * PostgreSQL's 65535 bind parameter limit even for wide tables.
	 */
	const BATCH_SIZE = 250;

	private $Source;
	private $Target;
	private $TargetDialect;
	private $Progress;

	/**
	 * @param \PDO $source The SQLite database to copy from
	 * @param \PDO $target The already-migrated database to copy into
	 * @param DatabaseDialect $targetDialect Dialect matching $target (quoting, id counter resync)
	 * @param callable|null $progress Receives one human-readable string per progress line, or null for silence
	 */
	public function __construct(\PDO $source, \PDO $target, DatabaseDialect $targetDialect, ?callable $progress = null)
	{
		$this->Source = $source;
		$this->Target = $target;
		$this->TargetDialect = $targetDialect;
		$this->Progress = $progress ?? function ($message)
		{
		};
	}

	/**
	 * Copies all rows of every table both databases have in common, verbatim, with the
	 * target's user triggers disabled. Refuses to run when the schema versions differ or
	 * (unless $force) when the target already holds data.
	 *
	 * Concurrency: the copy runs inside one transaction that also locks out a concurrent
	 * `PrintAttemptService::Claim()` for its duration (see ImportSnapshot()'s `LOCK TABLE
	 * print_jobs` and ClearOutbox()). That `print_jobs` lock is the only lock this class
	 * adds: the import already takes every copied table ACCESS EXCLUSIVE for the whole
	 * transaction through its own `TRUNCATE` (see ImportSnapshot()), and `print_jobs` is
	 * one of the few tables that statement never reaches - it is in NOT_COPIED_TABLES (see
	 * the class docblock) precisely because ClearOutbox() has to leave its rows alone, so
	 * this class takes the lock on it explicitly instead. An operator still stops the
	 * application and its label workers before importing - see
	 * docs/manual/getting-started.md - because this import replaces the data every other
	 * request reads and writes, not because this class leaves any of that unlocked.
	 *
	 * @param bool $force Skip the target-is-empty check; existing rows are truncated away
	 * @param bool $applyRowMigrations Re-apply the migrations that rewrite rows rather than
	 * schema - the HTML purifier and the API key hashing - to the rows this copy brought in;
	 * see below for why they cannot be left to the target's own migration run. Defaults to
	 * true so an operator's import is protected without asking for it; the differential test
	 * scripts pass false because they compare the two engines row for row and a target the
	 * importer had rewritten would read as a copy it had corrupted.
	 * @return array Row counts per table, keyed by table name
	 */
	public function Import(bool $force = false, bool $applyRowMigrations = true): array
	{
		// A caller's existing read transaction already supplies the snapshot. Own and
		// release one only when necessary; validation and every copy read share it.
		$ownsSnapshot = !$this->Source->inTransaction();
		if ($ownsSnapshot)
		{
			$this->Source->beginTransaction();
		}
		try
		{
			return $this->ImportSnapshot($force, $applyRowMigrations);
		}
		finally
		{
			if ($ownsSnapshot && $this->Source->inTransaction())
			{
				$this->Source->rollBack();
			}
		}
	}

	private function AssertStockLocations(): void
	{
		$query = 'SELECT s.id, s.product_id, s.location_id FROM stock s LEFT JOIN locations l ON l.id = s.location_id WHERE s.location_id IS NOT NULL AND l.id IS NULL';
		$count = (int)$this->Source->query('SELECT COUNT(*) FROM (' . $query . ') dangling')->fetchColumn();
		if ($count > 0)
		{
			$sample = $this->Source->query($query . ' ORDER BY s.id LIMIT 10')->fetchAll(\PDO::FETCH_ASSOC);
			throw new \RuntimeException('Import refused: ' . $count . ' source stock rows reference missing locations. '
				. 'Sample (id, product_id, location_id): ' . json_encode($sample) . '. '
				. 'Choose an explicit source repair and retry; --force does not bypass this check. List all references: '
				. $query . ' ORDER BY s.id;');
		}
	}

	/**
	 * The same refusal as AssertStockLocations(), extended to every foreign key
	 * migrations/0295.pgsql.sql adds on products (issue #552): location_id, qu_id_purchase,
	 * qu_id_stock, qu_id_consume, qu_id_price and product_group_id. Following ADR-0029's own
	 * import precedent (docs/adr/0029-stock-locations-reference-existing-locations.md,
	 * decision 8): report and refuse before anything is truncated, never invent a location,
	 * quantity unit or product group nobody chose. This is the only route by which a dangling
	 * one of these six can reach a target database at all - the migration itself adds no
	 * repair step because nothing before it could have created a dangling reference; see that
	 * migration's own header comment.
	 *
	 * Every column is checked before refusing (round 2 finding N3), rather than stopping at
	 * the first: an operator repairing one dangling column at a time, retrying after each,
	 * would otherwise discover the next one only on the next run - up to five more times for
	 * six columns.
	 *
	 * qu_id_consume and qu_id_price treat a stored `0` the same as NULL - "unset" - matching
	 * upstream Grocy itself: migrations 0210 and 0219 (the ones that added these two columns)
	 * each test `IFNULL(column, 0) = 0` in their own AFTER INSERT default-fill trigger, so a
	 * legacy source has always been free to store literal 0 there and have upstream read it
	 * as unset. CopyTable() and CollectValueMismatches() apply the same NULLIF() translation
	 * on the way in (see PRODUCT_ZERO_MEANS_UNSET_COLUMNS/SourceColumnExpression()), so a
	 * dangling 0 here is never actually reached - checked anyway, in case some other value
	 * mapped through a future change ever reintroduces a literal 0 story. product_group_id
	 * was checked against the same upstream migrations for an equivalent sentinel and has
	 * none: migrations/0037.sql declares it a plain nullable INTEGER with no default, and no
	 * later migration tests it against 0 the way 0210/0219 test qu_id_consume/qu_id_price, so
	 * it is read verbatim.
	 *
	 * @param string[] $tables GetCommonTables()'s own list - tables the source and target
	 * both have. A source lacking `products` entirely (.devtools/labels/identity-tests.php's
	 * own minimal fixture is exactly this: `migrations`, `stock`, `locations`, nothing else)
	 * has no product rows to check and no reason to be asked about one; querying `products`
	 * directly against such a source is a driver error ("no such table"), not a dangling
	 * reference, and must not be raised as either. Each column's own referenced table
	 * (locations/quantity_units/product_groups) is checked present the same way, though
	 * every supported source has always carried all three.
	 */
	private function AssertProductReferences(array $tables): void
	{
		if (!in_array('products', $tables, true))
		{
			return;
		}

		$checks = [
			['column' => 'location_id', 'table' => 'locations', 'zeroMeansUnset' => false],
			['column' => 'qu_id_purchase', 'table' => 'quantity_units', 'zeroMeansUnset' => false],
			['column' => 'qu_id_stock', 'table' => 'quantity_units', 'zeroMeansUnset' => false],
			['column' => 'qu_id_consume', 'table' => 'quantity_units', 'zeroMeansUnset' => true],
			['column' => 'qu_id_price', 'table' => 'quantity_units', 'zeroMeansUnset' => true],
			['column' => 'product_group_id', 'table' => 'product_groups', 'zeroMeansUnset' => false],
		];

		$problems = [];

		foreach ($checks as ['column' => $column, 'table' => $table, 'zeroMeansUnset' => $zeroMeansUnset])
		{
			if (!in_array($table, $tables, true))
			{
				continue;
			}

			$unsetCondition = 'p.' . $column . ' IS NOT NULL' . ($zeroMeansUnset ? ' AND p.' . $column . ' != 0' : '');
			$query = 'SELECT p.id, p.name, p.' . $column . ' FROM products p LEFT JOIN ' . $table . ' t ON t.id = p.' . $column
				. ' WHERE ' . $unsetCondition . ' AND t.id IS NULL';
			$count = (int)$this->Source->query('SELECT COUNT(*) FROM (' . $query . ') dangling')->fetchColumn();
			if ($count > 0)
			{
				$sample = $this->Source->query($query . ' ORDER BY p.id LIMIT 10')->fetchAll(\PDO::FETCH_ASSOC);
				$problems[] = $count . ' source product rows reference missing ' . $table
					. ' via ' . $column . '. Sample (id, name, ' . $column . '): ' . json_encode($sample) . '. '
					. 'List all references: ' . $query . ' ORDER BY p.id;';
			}
		}

		if (!empty($problems))
		{
			throw new \RuntimeException('Import refused: ' . count($problems) . ' of products\' six reference columns '
				. 'have at least one dangling value.' . "\n - " . implode("\n - ", $problems) . "\n"
				. 'Choose an explicit source repair for each and retry; --force does not bypass this check.');
		}
	}

	/**
	 * Issue #552 (N3): upstream Grocy migrations 0210 and 0219 test `IFNULL(column, 0) = 0`
	 * to decide whether products.qu_id_consume/qu_id_price are "unset" - 0 has always meant
	 * the same as NULL there, in every source this importer accepts. Both columns now carry
	 * a NOT DEFERRABLE foreign key (migrations/0295.pgsql.sql), and there is no
	 * quantity_units row with id 0, so copying a literal 0 verbatim would violate that
	 * foreign key immediately - not because the source is wrong, but because this schema
	 * spells the same "unset" meaning differently (NULL only, never 0). CopyTable() and
	 * CollectValueMismatches() both read these two columns through NULLIF(column, 0) instead
	 * of verbatim, so the copy (and the assertion that proves it copied faithfully) judge it
	 * against upstream's own meaning of the column, not its literal bytes. No other common
	 * column gets this treatment - see AssertProductReferences() for why product_group_id
	 * does not.
	 */
	private const PRODUCT_ZERO_MEANS_UNSET_COLUMNS = ['qu_id_consume', 'qu_id_price'];

	private function SourceColumnExpression(string $table, string $column): string
	{
		if ($table === 'products' && in_array($column, self::PRODUCT_ZERO_MEANS_UNSET_COLUMNS, true))
		{
			return 'NULLIF("' . $column . '", 0) AS "' . $column . '"';
		}

		return '"' . $column . '"';
	}

	/**
	 * Applies migrations/0277.pgsql.sql's own repair rule to whatever products the copy just
	 * brought in - reusing its rule rather than inventing a second one, per issue #496 (H7a):
	 * a product may not both have a parent and be one, so whichever product is the "middle"
	 * of a three-or-more-level chain (it has a parent_product_id of its own *and* something
	 * else points at it as a parent) has that parent link cleared, the same way 0277 repairs
	 * one on an in-place upgrade. Its children stay attached to it as a (now root) product,
	 * which keeps the one level of nesting the schema supports rather than orphaning them.
	 *
	 * Why this can arrive at all despite `enfore_product_nesting_level`/0277's own trigger
	 * still being installed on the target: `parent_product_id` predates the SQLite freeze
	 * (upstream grocy has always had it) and the ORIGINAL trigger — carried unchanged through
	 * every migration up to 0277 on both engines — was declared BEFORE UPDATE only, never
	 * BEFORE INSERT (0277's own comment explains this at length). A source honestly written
	 * by an installation older than 0277 can therefore carry a multi-level chain that no
	 * engine's trigger, past or present, ever caught on INSERT. The target's copy of that
	 * trigger cannot catch it either: SetTriggersEnabled() disables every user trigger for
	 * the whole copy, on purpose, so that rows already shaped by the source arrive unchanged
	 * rather than being recomputed a second time. Nothing else in this class re-validates the
	 * rows it copies, so without this repair they sit in the target exactly as the source had
	 * them, and the very next ordinary UPDATE to the middle product hits the guard trigger and
	 * fails - a write nobody involved in that request could explain from the request alone.
	 *
	 * Repair, not refusal: 0277 already established this fork's answer to a chain found among
	 * existing rows - correct it in place and say so, rather than block the operation the
	 * chain has nothing to do with. An import is exactly that case again, so it reuses the
	 * same answer instead of choosing a stricter one just because the rows arrived a different
	 * way.
	 *
	 * Called after AssertValuesMatch(), never before it - see ImportSnapshot()'s own comment
	 * on why the purifier and the key hasher are placed there, which applies here identically:
	 * this rewrites rows the verbatim-copy assertions just finished proving were an exact
	 * copy, so it must run after them.
	 *
	 * **This runs with triggers enabled** - the commit above already re-enabled them, and
	 * unlike the copy itself, this UPDATE is not replaying something the source already did;
	 * it is a fresh write this importer is making, so the target's own triggers are supposed
	 * to see it, the same as any other ordinary write. That includes
	 * enforce_min_stock_amount_for_cumulated_childs_UPD (db/pgsql/baseline/06_triggers_a.sql):
	 * if the repaired product has cumulate_min_stock_amount_of_sub_products set, its children
	 * have their own min_stock_amount zeroed exactly as this trigger already does for any
	 * other UPDATE to a cumulating parent. This is not a new side effect this class
	 * introduces - migrations/0277.pgsql.sql's own repair UPDATE runs through
	 * ExecuteSqlMigrationWhenNeeded() with no trigger suppression either, so an in-place
	 * upgrade that found and repaired the same chain would cascade identically. Suppressing
	 * it here would make this repair behave *differently* from 0277's own precedent, not more
	 * correctly - so it is left to fire, and named in the report below alongside the repair
	 * itself so it is not a silent surprise.
	 */
	private function RepairProductNesting(): void
	{
		// DISTINCT: a middle product with more than one child joins to p_child once per
		// child, so without it the same id (and name, and parent) repeats once per child -
		// inflating count($affected) and the progress line's list below well past the
		// number of products actually repaired.
		$affected = $this->Target->query(
			'SELECT DISTINCT p_middle.id, p_middle.name, p_middle.parent_product_id
			FROM products p_middle
			JOIN products p_child ON p_child.parent_product_id = p_middle.id
			WHERE p_middle.parent_product_id IS NOT NULL'
		)->fetchAll(\PDO::FETCH_ASSOC);

		if (empty($affected))
		{
			return;
		}

		// The identical predicate, so what was just selected is exactly what gets repaired.
		$this->Target->exec(
			'UPDATE products
			SET parent_product_id = NULL
			WHERE id IN (
				SELECT p_middle.id
				FROM products p_middle
				JOIN products p_child ON p_child.parent_product_id = p_middle.id
				WHERE p_middle.parent_product_id IS NOT NULL
			)
			AND parent_product_id IS NOT NULL'
		);

		// Named per row (id, name, and the parent link that was cleared) rather than just a
		// count, so an operator reading the output can go look at exactly what changed -
		// including, per the docblock above, that a cumulating parent's children may have
		// just had their own min_stock_amount zeroed by the trigger this UPDATE ran through.
		($this->Progress)('  repaired ' . count($affected) . ' unsupported product nesting '
			. (count($affected) === 1 ? 'chain' : 'chains') . ': unset parent_product_id on product '
			. implode(', ', array_map(
				fn($row) => $row['id'] . ' (' . $row['name'] . '), was parented under ' . $row['parent_product_id'],
				$affected
			))
			. ' - dependent fields a trigger recomputes from this relationship (e.g. cumulated min_stock_amount) may have changed too');
	}

	private function ImportSnapshot(bool $force, bool $applyRowMigrations): array
	{
		$tables = $this->GetCommonTables();

		$this->AssertSchemaVersionsMatch($applyRowMigrations);
		$this->AssertStockLocations();
		$this->AssertProductReferences($tables);

		$report = [];

		// One transaction around the truncate, the trigger toggling and the copy, so a
		// failure leaves the target exactly as it was. Without it, the truncate has
		// already happened by the time anything can go wrong, and there is nothing to go
		// back to: the target is emptied and half-repopulated, which is worse than either
		// end state. PostgreSQL — the only target engine — handles TRUNCATE and ALTER
		// TABLE ... DISABLE TRIGGER transactionally, so this genuinely rolls back rather
		// than merely appearing to.
		//
		// The trigger toggling has to be inside it for the same reason. A failure between
		// disabling and re-enabling would otherwise leave the target with its triggers
		// off, which is a database that looks fine and quietly stops maintaining itself.
		$this->Target->beginTransaction();

		try
		{
			LabelIdentityService::LockImport($this->Target);

			// LockImport() is an advisory lock that only serialises against
			// LabelIdentityService::Issue(); PrintAttemptService::Claim() never takes it, and
			// claims print_jobs rows with `FOR UPDATE OF j` of its own (PrintAttemptService.php:50)
			// instead. Without a table lock here, a worker could claim a job and be handed its
			// payload between this line and ClearOutbox() dead-lettering the same job below -
			// printing what this transaction is about to mark undeliverable. ACCESS EXCLUSIVE
			// conflicts with the ROW SHARE table lock that FOR UPDATE needs before it can even
			// start: a claim already in flight finishes first (this waits for it to commit or
			// roll back), and a claim that has not started yet waits for this transaction instead
			// - after which its own `o.dead_lettered_at IS NULL` check excludes the row
			// ClearOutbox() just dead-lettered. Held until this transaction ends (commit or
			// rollback), which covers ClearOutbox() below - see that method's docblock for the
			// rest. Guarded on to_regclass() the same way every other optional-table check in
			// this method is, for the synthetic single-migration-number fixtures
			// SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE's docblock names.
			if ($this->Target->query("SELECT to_regclass('print_jobs')")->fetchColumn() !== null)
			{
				$this->Target->exec('LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE');
				($this->Progress)('  print_jobs locked against concurrent print job claims for the rest of the import');
			}

			$hasLabels = $this->Target->query("SELECT to_regclass('labels')")->fetchColumn() !== null;
			if ($hasLabels)
			{
				$count = (int)$this->Target->query('SELECT COUNT(*) FROM labels WHERE retired_at IS NULL')->fetchColumn();
				if ($count > 0)
				{
					throw new \RuntimeException("Import refused: $count live label(s) name rows this import replaces; retire them deliberately first (--force does not bypass this guard)");
				}
				$this->Target->exec('UPDATE label_import_state SET epoch = epoch + 1 WHERE id = 1');
			}
			$this->AssertTargetIsEmpty($tables, $force);
			$this->AssertDerivedStateIsEmpty($tables, $force);
			$this->AssertOutboxIsHandleable($force);
			// A pairing session binds the creating user's id. Imports replace those users;
			// retained pairing material must not mint a credential for a reused account id.
			if ($this->Target->query("SELECT to_regclass('label_worker_sessions')")->fetchColumn() !== null)
			{
				$this->Target->exec("UPDATE label_worker_sessions SET revoked_at=CURRENT_TIMESTAMP, revoked_reason='admin' WHERE revoked_at IS NULL");
			}


			// Triggers exist to maintain data as the application changes it. Replaying
			// rows that were already shaped by the source's triggers has to leave them
			// alone, otherwise cascades fire and derived values get computed a second
			// time.
			$this->SetTriggersEnabled($tables, false);

			// DERIVED_STATE_TABLES entries the source predates (always, for
			// stock_entry_origins - see that constant's own docblock) are never in $tables
			// (see GetCommonTables()) and so would otherwise never appear in this statement
			// at all - which would leave a stale mqtt_product_entities opt-in pointing at
			// whatever product now holds its old id, a stale login_attempts row throttling
			// whatever account now holds its username, or a stale stock_entry_origins row
			// pointing at whatever stock_id now holds its old value (issue #496, H7b, and
			// issue #565's own class of defect, applied here). Truncated in the same
			// statement and the same transaction as every copied table, whether or not the
			// source has rows to put back into them.
			$derivedTablesToClear = array_values(array_filter(
				array_diff(self::DERIVED_STATE_TABLES, $tables),
				fn($table) => $this->Target->query("SELECT to_regclass('" . $table . "')")->fetchColumn() !== null
			));

			$this->Target->exec('TRUNCATE TABLE '
				. implode(', ', array_map(fn($t) => $this->TargetDialect->QuoteIdentifier($t), array_merge($tables, $derivedTablesToClear)))
				. ' RESTART IDENTITY CASCADE');

			foreach ($this->OrderTablesForCopy($tables) as $table)
			{
				$report[$table] = $this->CopyTable($table);
			}

			foreach ($derivedTablesToClear as $table)
			{
				($this->Progress)('  ' . str_pad($table, 46) . ' cleared (keyed to data this import replaces; the source predates or never carries this table)');
			}

			// outbox is never in $tables or $derivedTablesToClear (see NOT_COPIED_TABLES) -
			// TRUNCATE ... CASCADE would take print_jobs/print_attempts/print_evidence down
			// with it through their foreign keys (migrations/0270.pgsql.sql:53,65). This
			// deletes or dead-letters instead, so print history survives. Issue #496 (H7b).
			$this->ClearOutbox();

			$this->SetTriggersEnabled($tables, true);
			$this->TargetDialect->ResyncGeneratedIdCounters($this->Target);
		}
		catch (\Throwable $ex)
		{
			if ($this->Target->inTransaction())
			{
				$this->Target->rollBack();
			}

			throw $ex;
		}

		$this->Target->commit();

		// Generated id counters were resynchronized before releasing the import lock.

		$this->AssertRowCountsMatch($report);
		$this->AssertValuesMatch($tables);

		// After the assertions, never before them. The copy's job is to be verbatim and
		// AssertValuesMatch is what proves it was; rewriting rows mid-copy would make every
		// changed value read as one the importer had corrupted. So the target is first shown
		// to be an exact copy, and only then brought up to date.
		//
		// Both of these have to happen here rather than being left to a migration, and for
		// the same reason: bin/victual-db-import migrates the target *before* copying into
		// it, so migrations 0260 and 0264 run against an empty database and find nothing to
		// rewrite. What arrives afterwards has met neither.
		//
		// - The purifier, because a source predating the API's purifier (upstream grocy, or
		//   this fork before sweep finding S1) otherwise lands its stored payloads in the
		//   target untouched. Review finding P1 on #41.
		// - The key hashing, because the supported import span now reaches back to 0255 and
		//   a source at that number stores its API keys in plaintext. See
		//   StoredApiKeyHasher, which also explains why this could not happen before the
		//   span existed.
		if ($applyRowMigrations)
		{
			$purified = StoredHtmlPurifier::Purify($this->Target, $this->TargetDialect, $this->Progress);

			if (!empty($purified))
			{
				($this->Progress)('  purified ' . array_sum($purified) . ' stored description(s) that predate the API purifier');
			}

			StoredApiKeyHasher::HashPlaintextKeys($this->Target, $this->Progress);

			// A source may honestly carry a product that is both a parent and a child - see
			// RepairProductNesting() for why the target's own guard trigger never catches
			// this on the way in. Same placement as the purifier and the key hasher above,
			// and for the same reason: this rewrites rows the verbatim-copy assertions just
			// finished proving were an exact copy, so it must run after them, never before.
			if (in_array('products', $tables, true))
			{
				$this->RepairProductNesting();
			}

			// The price caches are copied verbatim like any other common table, then
			// immediately recomputed - see RebuildPriceCaches() for why a verbatim copy is
			// not enough here specifically. Same placement and the same reason as the repair
			// immediately above: this diverges the target from a byte-for-byte copy on
			// purpose, so it runs after the assertions that prove the copy itself was exact.
			$this->RebuildPriceCaches();

			// The frozen source replaces permission_hierarchy, and TRUNCATE CASCADE
			// clears role grants. Restore the target's read leaves and built-in grants
			// only after the verbatim-copy assertions have succeeded.
			if ($this->Target->query("SELECT to_regclass('roles')")->fetchColumn() !== null)
			{
				$this->Target->exec(file_get_contents(__DIR__ . '/../../db/pgsql/roles-seed.sql'));
			}

			// And the same again for plan 19 piece 2's half of the permission tree, which
			// roles-seed.sql does not carry. permission_fields references
			// permission_hierarchy(name), so the TRUNCATE ... CASCADE above empties it
			// whether or not it is a table this importer copies - and it is not, the SQLite
			// line being frozen below the migration that created it. Without this every
			// price channel is unredacted after an import and PricesVisible() is false for
			// every user including ADMIN, because STOCK_PRICES_VIEW is gone from the
			// hierarchy too. Issue #176 item 1.
			if ($this->Target->query("SELECT to_regclass('permission_fields')")->fetchColumn() !== null)
			{
				$this->Target->exec(file_get_contents(__DIR__ . '/../../db/pgsql/prices-seed.sql'));
			}
		}

		return $report;
	}

	/**
	 * Streams one table from source to target in multi-row INSERT batches,
	 * copying only the columns both sides share. Returns the number of rows copied.
	 */
	private function CopyTable(string $table): int
	{
		$columns = $this->GetCommonColumns($table);

		if (empty($columns))
		{
			throw new \Exception('Table "' . $table . '" has no columns in common between source and target');
		}

		$quotedTable = $this->TargetDialect->QuoteIdentifier($table);
		$quotedColumns = implode(', ', array_map(fn($c) => $this->TargetDialect->QuoteIdentifier($c), $columns));
		$rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

		$select = $this->Source->query('SELECT ' . implode(', ', array_map(fn($c) => $this->SourceColumnExpression($table, $c), $columns)) . ' FROM "' . $table . '"');

		$copied = 0;
		$batch = [];

		while ($row = $select->fetch(\PDO::FETCH_ASSOC))
		{
			$batch[] = $row;

			if (count($batch) >= self::BATCH_SIZE)
			{
				$copied += $this->InsertBatch($quotedTable, $quotedColumns, $rowPlaceholder, $columns, $batch);
				$batch = [];
			}
		}

		if (!empty($batch))
		{
			$copied += $this->InsertBatch($quotedTable, $quotedColumns, $rowPlaceholder, $columns, $batch);
		}

		($this->Progress)(sprintf('  %-46s %7d rows', $table, $copied));

		return $copied;
	}

	/**
	 * Executes one multi-row INSERT for the batch and returns the row count.
	 *
	 * @param string $rowPlaceholder Placeholder group for a single row, e.g. "(?, ?, ?)"
	 * @param array $batch Associative source rows, all sharing the keys in $columns
	 */
	private function InsertBatch(string $quotedTable, string $quotedColumns, string $rowPlaceholder, array $columns, array $batch): int
	{
		$sql = 'INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES '
			. implode(', ', array_fill(0, count($batch), $rowPlaceholder));

		$values = [];
		foreach ($batch as $row)
		{
			foreach ($columns as $column)
			{
				$values[] = $row[$column];
			}
		}

		$statement = $this->Target->prepare($sql);
		$statement->execute($values);

		return count($batch);
	}

	/**
	 * Tables present in both databases; tables existing on only one side are
	 * reported through the progress callback and skipped.
	 *
	 * @return string[]
	 */
	private function GetCommonTables(): array
	{
		$targetTables = $this->Target->query("SELECT table_name FROM information_schema.tables
			WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
			ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN);

		$sourceTables = $this->Source->query("SELECT name FROM sqlite_master
			WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);

		$common = array_values(array_diff(
			array_intersect($targetTables, $sourceTables),
			self::NOT_COPIED_TABLES
		));

		$missing = array_diff($targetTables, $sourceTables, self::TARGET_ONLY_TABLES);
		foreach ($missing as $table)
		{
			($this->Progress)('  note: target table "' . $table . '" does not exist in the source and is not copied');
		}

		$extra = array_diff($sourceTables, $targetTables);
		foreach ($extra as $table)
		{
			($this->Progress)('  warning: source table "' . $table . '" has no counterpart in the target and is NOT copied');
		}

		return $common;
	}

	/**
	 * GetCommonTables() lists common tables in alphabetical order (`ORDER BY table_name`
	 * above), and the copy loop in ImportSnapshot() used that order directly until this was
	 * added. That was safe only by coincidence, as long as every foreign key among common
	 * tables happened to point at a table earlier in alphabetical order - e.g. "locations"
	 * sorts before "stock", so migrations/0288.pgsql.sql's stock_location_id_fkey never saw a
	 * row copied before the location it names.
	 *
	 * migrations/0295.pgsql.sql's foreign keys on products.qu_id_purchase/qu_id_stock/
	 * qu_id_consume/qu_id_price break that coincidence: "quantity_units" sorts *after*
	 * "products" alphabetically, so copying products before quantity_units violates those
	 * foreign keys on the first product row that names one - not because any row is invalid,
	 * but because the referenced quantity_units row has not been copied back into the target
	 * yet at that point in the same transaction. "locations" and "product_groups" need no
	 * entry here: both already sort before "products".
	 *
	 * TABLES_COPIED_FIRST names every common table that some other common table's foreign key
	 * references, moving it to the front of the copy order (stable otherwise) so it is always
	 * fully repopulated before anything that might reference it. This is an ordering
	 * correction for the copy loop only - GetCommonTables()'s own alphabetical list is still
	 * used unchanged for the TRUNCATE statement, which names every table in one statement and
	 * has no order dependency.
	 */
	private const TABLES_COPIED_FIRST = ['quantity_units'];

	private function OrderTablesForCopy(array $tables): array
	{
		$first = array_values(array_intersect(self::TABLES_COPIED_FIRST, $tables));
		$rest = array_values(array_diff($tables, self::TABLES_COPIED_FIRST));

		return array_merge($first, $rest);
	}

	/**
	 * Columns of the given table present in both databases; source-only columns are
	 * reported through the progress callback and not copied.
	 *
	 * @return string[]
	 */
	private function GetCommonColumns(string $table): array
	{
		$targetColumns = $this->Target->query("SELECT column_name FROM information_schema.columns
			WHERE table_schema = current_schema() AND table_name = " . $this->Target->quote($table))->fetchAll(\PDO::FETCH_COLUMN);

		$sourceColumns = array_map(
			fn($c) => $c['name'],
			$this->Source->query('PRAGMA table_info("' . $table . '")')->fetchAll(\PDO::FETCH_ASSOC)
		);

		$common = array_values(array_intersect($sourceColumns, $targetColumns));
		if (in_array($table, ['locations', 'products', 'stock', 'recipes', 'chores', 'batteries'], true))
		{
			// This target-owned generation (plan 32's widening of migration 0269's rule to
			// the five kinds that joined the label subsystem) must never be restored from
			// foreign input.
			$common = array_values(array_diff($common, ['import_epoch']));
		}

		foreach (array_diff($sourceColumns, $targetColumns) as $column)
		{
			($this->Progress)('  warning: ' . $table . '.' . $column . ' exists only in the source and is NOT copied');
		}

		return $common;
	}

	/**
	 * The source has to be a schema this importer understands, and the target has to be
	 * fully migrated. Refuse rather than guess: rows put into columns that mean something
	 * else are not a failure anyone notices at the time.
	 *
	 * The two sides are asked different questions, and that asymmetry is the retirement.
	 * The target is the engine this fork runs, so "fully migrated" is a single number and
	 * anything else is an operator error with an obvious fix. The source is a format now -
	 * a file some other installation wrote, on its own schedule - so what it has to satisfy
	 * is a *span*: SUPPORTED_SOURCE_MIGRATION_MIN through SUPPORTED_SOURCE_MIGRATION_MAX,
	 * frozen, with both numbers named on refusal so the message says what would be accepted
	 * rather than only that this was not.
	 *
	 * @param bool $applyRowMigrations Whether this is a real import rather than the
	 * mechanical, unhelped copy the differential/label-identity test harnesses ask for by
	 * passing false to Import() - see MigrationSetMismatch()'s docblock for why the stronger
	 * completeness check below is skipped for them.
	 */
	private function AssertSchemaVersionsMatch(bool $applyRowMigrations)
	{
		$sourceVersion = $this->Source->query('SELECT MAX(migration) FROM migrations')->fetchColumn();
		$targetVersion = $this->Target->query('SELECT MAX(migration) FROM migrations')->fetchColumn();

		if ($sourceVersion === false || $sourceVersion === null)
		{
			throw new \Exception('The source database has no migrations recorded, so it is not a grocy or Victual database');
		}

		if ($targetVersion === false || $targetVersion === null)
		{
			throw new \Exception(
				'The target database has no schema yet. Run the migrations against it first '
				. '(bin/victual-db-import does that for you when it is the configured database).'
			);
		}

		// The source's number is compared against the frozen span rather than against the
		// migrations directory. Reading the directory was right while both engines were
		// maintained here — 0256.sqlite.sql fixes a SQLite-only defect PostgreSQL correctly
		// never runs, so the two engines legitimately sit at different numbers and each side
		// had to be measured against its own. It is wrong now: the span is a promise about
		// which foreign schemas this importer understands, and a promise computed from
		// whatever files happen to be in the tree is one that changes when somebody moves a
		// file.
		//
		// The target is still measured against the directory, because the target is this
		// fork's own engine and "fully migrated" is exactly that question. Static, and
		// deliberately so: it reads the migrations directory and nothing else. Going through
		// GetInstance() would drag in BaseService's constructor, which opens the
		// *configured* database — a connection this class has no use for and no reason to
		// require, since it is handed both of its connections.
		$expectedTarget = DatabaseMigrationService::GetLatestMigrationNumber($this->TargetDialect);

		if (intval($sourceVersion) < self::SUPPORTED_SOURCE_MIGRATION_MIN
			|| intval($sourceVersion) > self::SUPPORTED_SOURCE_MIGRATION_MAX)
		{
			throw new \Exception(
				'The source database is at migration ' . $sourceVersion . ', and this importer '
				. 'reads SQLite databases from migration ' . self::SUPPORTED_SOURCE_MIGRATION_MIN
				. ' through ' . self::SUPPORTED_SOURCE_MIGRATION_MAX . '. '
				. (intval($sourceVersion) < self::SUPPORTED_SOURCE_MIGRATION_MIN
					? 'Start the source installation once with the software that wrote it, so it '
						. 'migrates itself up to ' . self::SUPPORTED_SOURCE_MIGRATION_MIN
						. ' or beyond, then import again.'
					: 'That is newer than anything this version knows about, and importing it '
						. 'would put rows into columns that may mean something else.')
			);
		}

		if (intval($targetVersion) !== $expectedTarget)
		{
			throw new \Exception(
				'The target database is at migration ' . $targetVersion . ' but '
				. $this->TargetDialect->GetName() . ' is now at ' . $expectedTarget . '. '
				. 'Run bin/victual-migrate against it first.'
			);
		}

		// Issue #518 (M18): a maximum agreeing is not proof of completeness.
		// migrations/RESERVATIONS.md records exactly how a tree merging 0257 and 0259
		// without 0258 reports MAX(migration) = 259 on either engine - indistinguishable, by
		// that number alone, from a database that ran every one of them. Both MAX checks
		// above (source and target) can be satisfied by exactly that hole, which is what let
		// this finding through. Skipped when $applyRowMigrations is false - see
		// MigrationSetMismatch()'s docblock.
		if ($applyRowMigrations)
		{
			// The source's required set is 1-BASELINE_MIGRATION_ID (0255) - a plain,
			// gap-free numeric range, not a directory read: every one of those 255 numbers
			// has a real, portable migration file (verified; there is no exception list to
			// maintain the way 0256-0265 needs one) - plus the fixed
			// SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE list for what comes after it,
			// bounded at the source's own claimed version.
			[$sourceMissing, $sourceUnknown] = $this->MigrationSetMismatch(
				$this->Source,
				array_merge(range(1, DatabaseMigrationService::BASELINE_MIGRATION_ID), self::SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE),
				intval($sourceVersion)
			);

			if (!empty($sourceMissing) || !empty($sourceUnknown))
			{
				throw new \Exception(
					'The source database claims migration ' . $sourceVersion . ' but its recorded '
					. 'migration history does not actually support that claim' . $this->DescribeMigrationSetMismatch($sourceMissing, $sourceUnknown) . '. '
					. 'The highest recorded number is not proof of completeness (see migrations/RESERVATIONS.md). '
					. 'Start the source installation once with the software that wrote it, so it finishes migrating, then import again.'
				);
			}

			// The target's required set is computed dynamically - it is legitimately
			// "whatever this running code ships now" rather than a span promised about a
			// foreign schema, which is exactly the distinction
			// SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE's own docblock draws.
			[$targetMissing, $targetUnknown] = $this->MigrationSetMismatch(
				$this->Target,
				DatabaseMigrationService::GetRequiredMigrationNumbers($this->TargetDialect),
				$expectedTarget
			);

			if (!empty($targetMissing) || !empty($targetUnknown))
			{
				throw new \Exception(
					'The target database is not actually fully migrated' . $this->DescribeMigrationSetMismatch($targetMissing, $targetUnknown) . ', '
					. 'even though its highest recorded migration number alone does not show that (see migrations/RESERVATIONS.md). '
					. 'Run bin/victual-migrate against it, or otherwise reconcile the mismatch, before importing.'
				);
			}
		}
	}

	/**
	 * The predicate SchemaVersionMiddleware uses to decide whether to serve a request -
	 * GetMissingMigrationNumbers()/GetUnknownMigrationNumbers(), a set comparison rather than
	 * a maximum - applied to whichever connection the caller names against whichever required
	 * set the caller names, rather than to those two instance methods' own hard-wired
	 * DatabaseService::GetInstance() connection and DatabaseMigrationService's own
	 * directory-scanning idea of "required."
	 *
	 * That connection is the configured database, which for $this->Source (SQLite) it can
	 * never be, and which this class does not assume for $this->Target either, for the reason
	 * AssertSchemaVersionsMatch() already gives for using a static method there: going through
	 * GetInstance() would drag in BaseService's constructor and a connection this class has no
	 * use for, since it is handed both of its own. Reading "applied" straight from $db and
	 * comparing against a $required the caller already computed gives the identical answer for
	 * that connection, without the assumption - and without this method itself needing to know
	 * whether $required came from a dialect's directory scan (the target) or a fixed list (the
	 * source; see SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE).
	 *
	 * @param int[] $required Every migration number that connection must have recorded,
	 * before $ceiling narrows it further. Callers pass a *complete* set - there is no floor
	 * parameter here, on purpose: an earlier version of this method took one, to let the
	 * source's required set stop at 0256 and treat 1-0255 as implicitly satisfied, and that
	 * silently dropped the "missing" check for that whole range along with the "unknown"
	 * one - a real regression (a 0255 source missing an interior migration such as 0200
	 * was wrongly accepted). Passing the complete set, as both call sites now do, checks
	 * "missing" and "unknown" symmetrically with no such gap.
	 * @param int $ceiling Bounds $required at the claimed version rather than its own full
	 * range - a database legitimately has not yet run migrations past the one it is at, and
	 * only a hole *below* that is issue #518's (M18) finding.
	 * @return array{0: int[], 1: int[]} [missing, unknown], both ascending
	 */
	private function MigrationSetMismatch(\PDO $db, array $required, int $ceiling): array
	{
		$required = array_values(array_filter($required, fn($number) => $number <= $ceiling));

		// The same negative-number filter GetAppliedMigrationNumbers() applies, for the same
		// reason: DemoDataGeneratorService records "the demo data already ran" as migration
		// -1, which answers to no migration file on either engine and is not a hole.
		$applied = array_values(array_filter(
			array_map('intval', $db->query('SELECT migration FROM migrations')->fetchAll(\PDO::FETCH_COLUMN)),
			fn($number) => $number >= 0
		));

		return [
			array_values(array_diff($required, $applied)),
			array_values(array_diff($applied, $required)),
		];
	}

	/** The parenthesised "(missing ...; recorded but unknown to this code: ...)" detail shared by both refusals above. */
	private function DescribeMigrationSetMismatch(array $missing, array $unknown): string
	{
		$parts = [];

		if (!empty($missing))
		{
			$parts[] = (count($missing) === 1 ? 'missing migration ' : 'missing migrations ') . implode(', ', $missing);
		}

		if (!empty($unknown))
		{
			$parts[] = 'recorded but unknown to this code: ' . implode(', ', $unknown);
		}

		return ' (' . implode('; ', $parts) . ')';
	}

	/**
	 * Refuses to overwrite a target that already holds data, unless $force. The
	 * migrations table is exempt - a freshly migrated target always has rows there.
	 *
	 * A target migrated by bin/victual-migrate rather than by this command is not empty
	 * either: that path seeds the initial data of a fresh installation, which is the
	 * whole point of it. Hence the second half of the message - the rows are safe to
	 * lose, but only the operator can say so.
	 */
	private function AssertTargetIsEmpty(array $tables, bool $force)
	{
		if ($force)
		{
			return;
		}

		foreach ($tables as $table)
		{
			if ($table === 'migrations')
			{
				continue;
			}

			$count = $this->Target->query('SELECT COUNT(*) FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchColumn();

			if ($count > 0)
			{
				throw new \Exception(
					'The target database already contains data (' . $table . ' has ' . $count . ' rows). '
					. 'Importing replaces everything in it. Pass --force if that is what you want - '
					. 'which is also the answer when the only thing in there is the initial data '
					. 'bin/victual-migrate seeds into a fresh database.'
				);
			}
		}
	}

	/**
	 * The same "already contains data, pass --force" rule AssertTargetIsEmpty() enforces,
	 * extended to DERIVED_STATE_TABLES entries a source within the supported span may
	 * predate (or, for stock_entry_origins, always does). GetCommonTables() correctly
	 * leaves such a table out of $tables - there is nothing there to copy - which also means
	 * AssertTargetIsEmpty() never sees it; without this, a non-force import would go on to
	 * silently discard whatever rows are in it (see the TRUNCATE this method's caller
	 * performs) without ever having been refused the way every other table's pre-existing
	 * data already is. Issue #496 (H7b) for mqtt_product_entities; issue #565 for
	 * login_attempts and stock_entry_origins.
	 */
	private function AssertDerivedStateIsEmpty(array $tables, bool $force): void
	{
		if ($force)
		{
			return;
		}

		foreach (self::DERIVED_STATE_TABLES as $table)
		{
			if (in_array($table, $tables, true)
				|| $this->Target->query("SELECT to_regclass('" . $table . "')")->fetchColumn() === null)
			{
				continue;
			}

			$count = $this->Target->query('SELECT COUNT(*) FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchColumn();

			if ($count > 0)
			{
				throw new \Exception(
					'The target database already contains data (' . $table . ' has ' . $count . ' rows). '
					. 'Importing replaces everything in it. Pass --force if that is what you want.'
				);
			}
		}
	}

	/**
	 * The same "already contains data, pass --force" rule, narrowed for `outbox`: only
	 * *undelivered, not yet dead-lettered* rows count. A delivered row is already done and
	 * an already dead-lettered one is already terminal - ClearOutbox() leaves both exactly
	 * as they are (deleting the ones nothing references, changing nothing about the ones
	 * that survive as history) regardless of --force, so neither represents work a
	 * non-force import would silently discard. A row still waiting to be delivered is the
	 * one case where ClearOutbox() itself will change its fate (dead-letter it, if
	 * something still references it, or delete it outright otherwise) - the case this
	 * check exists to ask permission for first. Issue #496 (H7b).
	 */
	private function AssertOutboxIsHandleable(bool $force): void
	{
		if ($force || $this->Target->query("SELECT to_regclass('outbox')")->fetchColumn() === null)
		{
			return;
		}

		$count = (int)$this->Target->query('SELECT COUNT(*) FROM outbox WHERE delivered_at IS NULL AND dead_lettered_at IS NULL')->fetchColumn();

		if ($count > 0)
		{
			throw new \Exception(
				'The target database already contains data (outbox has ' . $count . ' undelivered row(s)). '
				. 'Importing replaces everything in it. Pass --force if that is what you want.'
			);
		}
	}

	/**
	 * Clears whatever this import must discard from `outbox` without disturbing print
	 * history. Issue #496 (H7b): a stale outbox event must not survive a replace, but
	 * `print_jobs.outbox_id` and `print_attempts.outbox_id`
	 * (migrations/0270.pgsql.sql:53,65) are `NOT NULL REFERENCES outbox(id)` with no
	 * `ON DELETE` clause of their own - the default is RESTRICT, which a plain DELETE
	 * would hit, and which the old TRUNCATE ... CASCADE this replaced silently overrode by
	 * taking print_jobs/print_attempts/print_evidence down with it. NOT_COPIED_TABLES
	 * already keeps outbox out of the ordinary TRUNCATE this runs alongside (see
	 * ImportSnapshot()), so this is the only place outbox rows are removed.
	 *
	 * A row nothing references is simply deleted: it describes consequences of data this
	 * import is about to discard, and there is no print history depending on its
	 * continued existence. A row `print_jobs` or `print_attempts` still points at is
	 * dead-lettered instead (OutboxService::DeadLetter()'s own state, reused rather than
	 * reinvented) when it is not already delivered or already dead-lettered: the print job
	 * or attempt survives with its foreign key intact, and the event itself is marked as
	 * never going to be delivered, so nothing stale reaches a consumer. An already
	 * delivered or already dead-lettered referenced row needs neither treatment and is
	 * left exactly as it is.
	 *
	 * **Dead-lettering the outbox row is not enough by itself.** `print_jobs.outcome` and
	 * `.outcome_at` are what every other consumer of a job's state actually reads -
	 * `LabelPrintJobService::Monitor()`'s `state`/`authorization_state` columns,
	 * `LabelOperationsService::Cancel()`'s claimed/completed refusals and
	 * `LabelPrintJobService::AuthorizeAnotherAttempt()`'s completion check all read the
	 * job's own outcome, never the outbox row it points at. Left unfinished, such a job
	 * kept reporting a non-terminal `Monitor()` state (`awaiting_artifact` or `failed`),
	 * `Cancel()` refused a job whose one attempt had already ended as `already_claimed`
	 * rather than letting it be cancelled, and `AuthorizeAnotherAttempt()` would bump
	 * `attempts_authorized` for a job that could never be delivered.
	 * `PrintAttemptService::Claim()`'s own `WHERE ... AND o.dead_lettered_at IS NULL`
	 * (PrintAttemptService.php:48) excludes this exact row once it is dead-lettered - but
	 * only once it is. `LockImport()` alone does not stop a `Claim()` already running, or one
	 * that starts right after: it is an advisory lock scoped to
	 * `LabelIdentityService::Issue()`, and `Claim()`'s own `FOR UPDATE OF j`
	 * (PrintAttemptService.php:50) never waits on it. ImportSnapshot() closes that gap with
	 * `LOCK TABLE print_jobs IN ACCESS EXCLUSIVE MODE` immediately after `LockImport()` (see
	 * that call site for how the lock mode forces a `Claim()` transaction already in flight
	 * to finish first, without handing out this job, and blocks a new one from starting
	 * until this transaction ends). That stops any new claim, and any claim still in
	 * flight, from ever handing out this job's payload - but not an attempt that was
	 * already leased before this transaction began: `Claim()` had already returned that job
	 * to its worker, and no lock taken here reaches back into a transaction that already
	 * committed. That attempt can still print while this one runs; by the time this method
	 * dead-letters its outbox row, `api_keys` (a common, replaced table) has already been
	 * truncated too, cascading onto `label_worker_credentials` (NOT_COPIED_TABLES; see the
	 * class docblock), so at least the credential that attempt authenticated with is
	 * invalidated once the import commits. This is exactly why
	 * docs/manual/getting-started.md tells an operator to stop the label workers before
	 * importing rather than relying on this lock alone: it closes the claim race, not a
	 * worker that already holds a leased job.
	 * Reusing `PrintAttemptService.php:98`'s own outcome value and columns, every job whose
	 * outbox row was just dead-lettered and which was not already finished (`outcome IS NULL
	 * AND cancelled_at IS NULL` - a cancelled job stays cancelled, and a job already
	 * `printed`/`sent` stays that) is finished the same way.
	 *
	 * Never gated on $force: by the time this runs, AssertOutboxIsHandleable() has already
	 * either refused (no --force, undelivered rows present) or been skipped (--force, or
	 * nothing undelivered to protect), so every row this method touches was already
	 * cleared to touch.
	 */
	private function ClearOutbox(): void
	{
		if ($this->Target->query("SELECT to_regclass('outbox')")->fetchColumn() === null)
		{
			return;
		}

		$referencing = array_values(array_filter(
			['print_jobs', 'print_attempts'],
			fn($table) => $this->Target->query("SELECT to_regclass('" . $table . "')")->fetchColumn() !== null
		));

		if (empty($referencing))
		{
			$deleted = $this->Target->exec('DELETE FROM outbox');

			if ($deleted > 0)
			{
				($this->Progress)('  outbox: deleted ' . $deleted . ' row(s) (no print history subsystem present to check against)');
			}

			return;
		}

		$referencedIds = 'SELECT outbox_id FROM ' . implode(' UNION SELECT outbox_id FROM ', $referencing);

		$deleted = $this->Target->exec('DELETE FROM outbox WHERE id NOT IN (' . $referencedIds . ')');

		$deadLetteredIds = $this->Target->query(
			"UPDATE outbox SET dead_lettered_at = CURRENT_TIMESTAMP, attempts = attempts + 1, "
			. "last_error = 'Replaced by an import: the print job or attempt referencing this event is kept for history, "
			. "but the event itself described data the import discarded and will never be delivered' "
			. 'WHERE delivered_at IS NULL AND dead_lettered_at IS NULL AND id IN (' . $referencedIds . ') RETURNING id'
		)->fetchAll(\PDO::FETCH_COLUMN);

		if (!empty($deadLetteredIds) && in_array('print_jobs', $referencing, true))
		{
			$this->Target->exec(
				"UPDATE print_jobs SET outcome = 'dead_lettered', outcome_at = CURRENT_TIMESTAMP "
				. 'WHERE outcome IS NULL AND cancelled_at IS NULL AND outbox_id IN (' . implode(',', $deadLetteredIds) . ')'
			);
		}

		if ($deleted > 0 || !empty($deadLetteredIds))
		{
			($this->Progress)('  outbox: deleted ' . $deleted . ' unreferenced row(s), dead-lettered '
				. count($deadLetteredIds) . ' row(s) still referenced by print history');
		}
	}

	/**
	 * Recomputes cache__products_average_price and cache__products_last_purchased from
	 * the copy, reusing migrations/0267.pgsql.sql's own rebuild statements verbatim (see
	 * that migration, and 0261.pgsql.sql before it, which the two `INSERT ... ON CONFLICT`
	 * statements are unchanged since) rather than inventing a second version of them.
	 * Issue #496's "recompute derived state where necessary."
	 *
	 * Why a verbatim copy is not enough here, unlike cache__quantity_unit_conversions_resolved
	 * (whose maintaining triggers and format have never changed since before the SQLite
	 * freeze) - two independent reasons, from two different migrations:
	 *
	 * - migrations/0261.pgsql.sql fixes SQLite's own integer division over these caches'
	 *   NUMERIC-affinity columns (see that migration's SQLite half for the full mechanism).
	 *   It is dual-engine, so a source at 0261 or later already carries the fix in its own
	 *   cached values - but a source between SUPPORTED_SOURCE_MIGRATION_MIN (0255) and 0260
	 *   does not yet, and this is where the confirmed example comes from: a source at 0255
	 *   with purchases 4@2, 3@2 and 2@3 carries a cached average_price of 2.0, where the
	 *   view - reading the same, correctly copied stock_log - computes 2.2222.
	 * - migrations/0267.pgsql.sql (PostgreSQL-only, above the freeze) separately fixes a
	 *   split-entry defect in the same two caches. No source in the supported span ever ran
	 *   it - the SQLite line is frozen below it - so this half of the staleness risk applies
	 *   across the *entire* span (0255-0265), not only below 0261.
	 *
	 * Either way, a source's own cached values can already be stale relative to what this
	 * engine's current, corrected view logic (`products_average_price` /
	 * `products_last_purchased`) computes from the very rows this import just copied.
	 *
	 * A third way a cached row can be wrong, and the reason this method clears each cache
	 * table before it rebuilds them: a row can survive for a product neither view returns
	 * a row for any more - the source undid every purchase of a product, say. The source's
	 * own trg_stock_log_UPD (db/pgsql/baseline/06_triggers_b.sql) only ever upserts
	 * `WHERE product_id = NEW.product_id`, so when the view it selects from stops naming
	 * that product, the INSERT finds nothing to insert and the stale row is never removed.
	 * A verbatim copy carries that row into the target exactly as the source had it.
	 * 0261.pgsql.sql's and 0267.pgsql.sql's own statements, reused verbatim below, are
	 * upsert-only for the same structural reason a migration has to be: it runs once,
	 * against a database that already holds rows in the table it is touching, and an
	 * `INSERT ... ON CONFLICT DO UPDATE` has no way to remove a row the view it just
	 * changed no longer accounts for. So rebuilding by upsert alone would only be *the
	 * same answer bin/victual-migrate would give re-running 0261 and 0267 today* - not
	 * exact, because those migrations were never able to be exact either. Clearing first
	 * is what an importer can promise that a migration cannot: nothing else has written to
	 * the target's copy of these two tables before this method runs, so after it, each
	 * holds precisely the rows its view returns right now, and no others.
	 *
	 * Rebuilding from the views (after RepairProductNesting(), so a repaired chain's own
	 * products are already correct too) is guarded on the cache table's existence rather
	 * than on `$tables` containing `stock` or `products`: the views these statements select
	 * from read every table they need directly, so there is nothing else to gate on.
	 *
	 * Each table's DELETE and INSERT run inside one transaction. This method runs in
	 * autocommit - ImportSnapshot()'s own transaction around the copy has already
	 * committed by the time it is called - so without one, a crash between the DELETE and
	 * the INSERT would leave that cache table empty rather than merely stale, which is
	 * worse than anything this method exists to fix.
	 */
	private function RebuildPriceCaches(): void
	{
		if ($this->Target->query("SELECT to_regclass('cache__products_average_price')")->fetchColumn() === null)
		{
			return;
		}

		$this->Target->beginTransaction();

		try
		{
			// Cleared, not just upserted into - see the docblock above for why an upsert
			// alone leaves behind a row for a product the view no longer returns.
			$this->Target->exec('DELETE FROM cache__products_average_price');

			$averagePrice = $this->Target->exec(
				'INSERT INTO cache__products_average_price (product_id, price)
				SELECT product_id, price
				FROM products_average_price
				ON CONFLICT (product_id) DO UPDATE SET
					price = EXCLUDED.price'
			);

			$this->Target->exec('DELETE FROM cache__products_last_purchased');

			$lastPurchased = $this->Target->exec(
				'INSERT INTO cache__products_last_purchased
					(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
				SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
				FROM products_last_purchased
				ON CONFLICT (product_id) DO UPDATE SET
					amount = EXCLUDED.amount,
					best_before_date = EXCLUDED.best_before_date,
					purchased_date = EXCLUDED.purchased_date,
					price = EXCLUDED.price,
					location_id = EXCLUDED.location_id,
					shopping_location_id = EXCLUDED.shopping_location_id'
			);
		}
		catch (\Throwable $ex)
		{
			if ($this->Target->inTransaction())
			{
				$this->Target->rollBack();
			}

			throw $ex;
		}

		$this->Target->commit();

		// Rows inserted, per exec()'s own return value - and, since each table was cleared
		// immediately before its INSERT ran, exactly the rows now in each cache, not merely
		// the rows the upsert happened to change.
		($this->Progress)('  rebuilt price cache(s): ' . $averagePrice . ' average-price row(s), '
			. $lastPurchased . ' last-purchased row(s)');
	}

	/**
	 * Post-import sanity check: every table in the target must hold exactly the number
	 * of rows that were copied into it.
	 *
	 * @param array $report Row counts per table, keyed by table name (as returned by Import())
	 */
	private function AssertRowCountsMatch(array $report)
	{
		$mismatches = [];

		foreach ($report as $table => $expected)
		{
			$actual = $this->Target->query('SELECT COUNT(*) FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchColumn();

			if (intval($actual) !== intval($expected))
			{
				$mismatches[] = $table . ' (copied ' . $expected . ', target now holds ' . $actual . ')';
			}
		}

		if (!empty($mismatches))
		{
			throw new \Exception('Row counts do not match after import: ' . implode('; ', $mismatches));
		}
	}

	/**
	 * Compares every copied row, column by column, between source and target.
	 *
	 * AssertRowCountsMatch() answers "did everything arrive?" and nothing else. The
	 * failure this guards against arrives with the right number of rows and the wrong
	 * values in them: every one of the fifteen type-coercion hazards in
	 * db/pgsql/README.md — a TINYINT id read back as a boolean, an INTEGER that lost its
	 * fraction — passes a count check untouched.
	 *
	 * Everything is compared rather than a sample. A sample is cheaper and can miss the
	 * single coerced value, which is the only thing this is looking for; the import runs
	 * once in a deployment's lifetime, so the runtime is affordable in a way it would not
	 * be on a hot path.
	 *
	 * Rows are compared through ValueComparison, the same normalisation the differential
	 * test suite uses, so "equal" means one thing across this fork rather than two.
	 * Ordering is by normalised content rather than by id, because a table need not have
	 * one and the question here is whether the same multiset of rows arrived.
	 */
	private function AssertValuesMatch(array $tables)
	{
		// Both sides have to report what is stored, not what their connection makes of
		// it. The application's connections set PDO::NULL_EMPTY_STRING, so a stored empty
		// string reads back as null, while the importer's source connection deliberately
		// does not (see bin/victual-db-import — grocy really does store empty strings, the
		// internal meal plan section being one). Comparing a NULL_NATURAL read against a
		// NULL_EMPTY_STRING read reports a difference for every empty string in the
		// database and means nothing. Setting both to NULL_NATURAL keeps the empty
		// string-versus-null distinction visible, which is a coercion worth catching.
		$sourceNulls = $this->Source->getAttribute(\PDO::ATTR_ORACLE_NULLS);
		$targetNulls = $this->Target->getAttribute(\PDO::ATTR_ORACLE_NULLS);

		$this->Source->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);
		$this->Target->setAttribute(\PDO::ATTR_ORACLE_NULLS, \PDO::NULL_NATURAL);

		try
		{
			$mismatches = $this->CollectValueMismatches($tables);
		}
		finally
		{
			$this->Source->setAttribute(\PDO::ATTR_ORACLE_NULLS, $sourceNulls);
			$this->Target->setAttribute(\PDO::ATTR_ORACLE_NULLS, $targetNulls);
		}

		if (!empty($mismatches))
		{
			throw new \Exception('Values differ after import: ' . implode('; ', $mismatches));
		}
	}

	/**
	 * The per-table comparison behind AssertValuesMatch, split out so the null-handling
	 * override around it has a single exit.
	 *
	 * @return string[] One human-readable description per differing table
	 */
	private function CollectValueMismatches(array $tables): array
	{
		$mismatches = [];

		foreach ($tables as $table)
		{
			$columns = $this->GetCommonColumns($table);

			if (empty($columns))
			{
				continue;
			}

			$list = implode(', ', array_map(fn($c) => $this->TargetDialect->QuoteIdentifier($c), $columns));
			$sourceList = implode(', ', array_map(fn($c) => $this->SourceColumnExpression($table, $c), $columns));

			$sourceRows = array_map(
				[ValueComparison::class, 'NormaliseRow'],
				$this->Source->query('SELECT ' . $sourceList . ' FROM "' . $table . '"')->fetchAll(\PDO::FETCH_ASSOC)
			);
			$targetRows = array_map(
				[ValueComparison::class, 'NormaliseRow'],
				$this->Target->query('SELECT ' . $list . ' FROM ' . $this->TargetDialect->QuoteIdentifier($table))->fetchAll(\PDO::FETCH_ASSOC)
			);

			sort($sourceRows);
			sort($targetRows);

			if ($sourceRows === $targetRows)
			{
				continue;
			}

			$onlySource = array_slice(array_diff($sourceRows, $targetRows), 0, 3);
			$onlyTarget = array_slice(array_diff($targetRows, $sourceRows), 0, 3);

			$detail = $table;

			foreach ($onlySource as $row)
			{
				$detail .= "\n    only in the source: " . $row;
			}

			foreach ($onlyTarget as $row)
			{
				$detail .= "\n    only in the target: " . $row;
			}

			$mismatches[] = $detail;
		}

		return $mismatches;
	}

	/**
	 * Toggles user-defined triggers on the given target tables. Only implemented for
	 * PostgreSQL - it is currently the only non-SQLite target, and rows never need
	 * trigger suppression on the way out of the SQLite source.
	 */
	private function SetTriggersEnabled(array $tables, bool $enabled)
	{
		if ($this->TargetDialect->GetName() !== 'pgsql')
		{
			return;
		}

		// ALTER TABLE rather than session_replication_role, which needs superuser -
		// a Victual database user normally owns its tables but is not a superuser
		foreach ($tables as $table)
		{
			$this->Target->exec('ALTER TABLE ' . $this->TargetDialect->QuoteIdentifier($table)
				. ($enabled ? ' ENABLE' : ' DISABLE') . ' TRIGGER USER');
		}
	}
}
