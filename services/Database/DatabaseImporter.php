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
 *   - DERIVED_STATE_TABLES (`outbox`, the two `mqtt_*` publication tables) are always
 *     cleared, even when the source predates them and so has no counterpart to copy back in.
 *     They describe consequences of data this import discards - a queued event, a cached
 *     "already published" fact - and must not outlive it. See AssertDerivedStateIsEmpty().
 *   - NOT_COPIED_TABLES are never touched by the copy or the truncate. `migrations` is the
 *     target's own record of its own schema history, restored rather than replaced (see
 *     AssertSchemaVersionsMatch()). The label-family tables are guarded instead of cleared:
 *     a live (non-retired) label refuses the whole import outright, --force included, so
 *     that nothing here ever leaves a live label pointing at data the import just replaced;
 *     a *retired* label's dead snapshot is history and survives. `label_import_state`'s
 *     epoch is bumped by one on every import instead, which is what actually invalidates any
 *     in-flight label request composed against the pre-import identities - see ADR-0021 and
 *     plan 25's "import epoch" and LabelIdentityService::Issue().
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
	 */
	const NOT_COPIED_TABLES = ['migrations', 'labels', 'label_import_state', 'label_workers', 'label_drivers', 'label_worker_capabilities', 'label_printers', 'label_printer_status', 'print_jobs', 'print_attempts', 'print_evidence', 'label_worker_sessions', 'label_worker_credentials'];

	/**
	 * Target tables holding derived or ephemeral state - never something a household
	 * authored - that a source within the supported span may or may not carry, depending on
	 * exactly which migration introduced it: `outbox` arrived at 0259, `mqtt_product_entities`
	 * and `mqtt_published_entities` at 0257, while SUPPORTED_SOURCE_MIGRATION_MIN is 0255.
	 *
	 * When the source predates one of these tables, GetCommonTables() correctly leaves it out
	 * of the common-table set - there is nothing in the source to copy - but "nothing to
	 * copy" must not be read as "leave the target's rows alone": every other table in the
	 * target is truncated and replaced by this import, and a queued outbox event or a cached
	 * "MQTT already knows this" fact describes exactly the data being discarded. Left alone,
	 * it survives an operation that is supposed to replace everything, which is issue #496's
	 * H7(b) finding for `outbox` specifically. So these are always cleared, whether or not the
	 * source has a counterpart to repopulate them from - see ImportSnapshot() and
	 * AssertDerivedStateIsEmpty(). When the source *does* carry one of them it is already a
	 * common table, copied and truncated the ordinary way; this list only closes the gap for
	 * a source that predates it.
	 */
	const DERIVED_STATE_TABLES = ['outbox', 'mqtt_product_entities', 'mqtt_published_entities'];

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
	 */
	private function RepairProductNesting(): void
	{
		$affected = $this->Target->query(
			'SELECT p_middle.id, p_middle.name
			FROM products p_middle
			JOIN products p_child ON p_child.parent_product_id = p_middle.id
			WHERE p_middle.parent_product_id IS NOT NULL'
		)->fetchAll(\PDO::FETCH_KEY_PAIR);

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

		($this->Progress)('  repaired ' . count($affected) . ' unsupported product nesting '
			. (count($affected) === 1 ? 'chain' : 'chains') . ': unset parent_product_id on product '
			. implode(', ', array_map(
				fn($id, $name) => $id . ' (' . $name . ')',
				array_keys($affected),
				array_values($affected)
			)));
	}

	private function ImportSnapshot(bool $force, bool $applyRowMigrations): array
	{
		$tables = $this->GetCommonTables();

		$this->AssertSchemaVersionsMatch($applyRowMigrations);
		$this->AssertStockLocations();

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

			// DERIVED_STATE_TABLES the source happens to predate are never in $tables (see
			// GetCommonTables()) and so would otherwise never appear in this statement at
			// all - which is exactly how a stale outbox event survives a force import today
			// (issue #496, H7b). Truncated in the same statement and the same transaction as
			// every copied table, whether or not the source has rows to put back into them.
			$derivedTablesToClear = array_values(array_filter(
				array_diff(self::DERIVED_STATE_TABLES, $tables),
				fn($table) => $this->Target->query("SELECT to_regclass('" . $table . "')")->fetchColumn() !== null
			));

			$this->Target->exec('TRUNCATE TABLE '
				. implode(', ', array_map(fn($t) => $this->TargetDialect->QuoteIdentifier($t), array_merge($tables, $derivedTablesToClear)))
				. ' RESTART IDENTITY CASCADE');

			foreach ($tables as $table)
			{
				$report[$table] = $this->CopyTable($table);
			}

			foreach ($derivedTablesToClear as $table)
			{
				($this->Progress)('  ' . str_pad($table, 46) . ' cleared (derived state; the source predates it)');
			}

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

		$select = $this->Source->query('SELECT ' . implode(', ', array_map(fn($c) => '"' . $c . '"', $columns)) . ' FROM "' . $table . '"');

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
			[$sourceMissing, $sourceUnknown] = $this->MigrationSetMismatch($this->Source, new SqliteDialect(), intval($sourceVersion));

			if (!empty($sourceMissing) || !empty($sourceUnknown))
			{
				throw new \Exception(
					'The source database claims migration ' . $sourceVersion . ' but its recorded '
					. 'migration history does not actually support that claim' . $this->DescribeMigrationSetMismatch($sourceMissing, $sourceUnknown) . '. '
					. 'The highest recorded number is not proof of completeness (see migrations/RESERVATIONS.md). '
					. 'Start the source installation once with the software that wrote it, so it finishes migrating, then import again.'
				);
			}

			[$targetMissing, $targetUnknown] = $this->MigrationSetMismatch($this->Target, $this->TargetDialect, $expectedTarget);

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
	 * a maximum - applied to whichever connection and dialect the caller names, rather than to
	 * those two instance methods' own hard-wired DatabaseService::GetInstance() connection.
	 *
	 * That connection is the configured database, which for $this->Source (SQLite) it can
	 * never be, and which this class does not assume for $this->Target either, for the reason
	 * AssertSchemaVersionsMatch() already gives for using a static method there: going through
	 * GetInstance() would drag in BaseService's constructor and a connection this class has no
	 * use for, since it is handed both of its own. GetRequiredMigrationNumbers() is the
	 * static, connection-free half of the same predicate; reading "applied" straight from $db
	 * and comparing gives the identical answer for that connection, without the assumption.
	 *
	 * @param int $ceiling Bounds the required set at the claimed version rather than at the
	 * dialect's full range - a database legitimately has not yet run migrations past the one
	 * it is at, and only a hole *below* that is issue #518's (M18) finding.
	 * @return array{0: int[], 1: int[]} [missing, unknown], both ascending
	 */
	private function MigrationSetMismatch(\PDO $db, DatabaseDialect $dialect, int $ceiling): array
	{
		$required = array_values(array_filter(
			DatabaseMigrationService::GetRequiredMigrationNumbers($dialect),
			fn($number) => $number <= $ceiling
		));

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
	 * predate. GetCommonTables() correctly leaves such a table out of $tables - there is
	 * nothing there to copy - which also means AssertTargetIsEmpty() never sees it; without
	 * this, a non-force import would go on to silently discard whatever rows are in it (see
	 * the TRUNCATE this method's caller performs) without ever having been refused the way
	 * every other table's pre-existing data already is. Issue #496 (H7b).
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
			$sourceList = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

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
