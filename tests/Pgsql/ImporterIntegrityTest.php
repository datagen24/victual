<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #487's importer findings, #496 (H7) and #518 (M18).
 *
 * H7(a): a source honestly carrying a multi-level product chain (root -> middle -> leaf) -
 * possible because the *original* nesting guard, unlike migrations/0277.pgsql.sql's fix, was
 * declared BEFORE UPDATE only, so no engine's trigger, past or present, ever refused such a
 * chain on INSERT - used to import without repair, so the very next ordinary write to the
 * middle product hit the target's own (correct, present) guard trigger and failed for a
 * reason nobody involved in that write could see.
 *
 * H7(b): a target's own outbox rows, describing consequences of data an import is about to
 * discard wholesale, used to survive a force import whenever the source predated the
 * migration that introduced `outbox` (0259) - which every source at
 * DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN (0255) does, since GetCommonTables()
 * correctly leaves a table the source does not have out of the copy, and nothing filled that
 * gap.
 *
 * M18: AssertSchemaVersionsMatch() used to compare only MAX(migration) on both the source and
 * the target, which a hole below the maximum does not move (migrations/RESERVATIONS.md
 * explains why) - unlike SchemaVersionMiddleware's boot check, which compares the full
 * required and applied sets and catches exactly this.
 */
class ImporterIntegrityTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	/** Restores whatever a test punched into the shared target, so method order cannot matter. */
	private ?int $deletedTargetMigration = null;

	protected function tearDown(): void
	{
		foreach ($this->files as $file)
		{
			foreach ([$file, $file . '-wal', $file . '-shm'] as $path)
			{
				if (file_exists($path))
				{
					unlink($path);
				}
			}
		}
		$this->files = [];

		if ($this->deletedTargetMigration !== null)
		{
			self::Pdo()->exec('INSERT INTO migrations (migration) VALUES (' . $this->deletedTargetMigration . ') ON CONFLICT DO NOTHING');
			$this->deletedTargetMigration = null;
		}

		self::Pdo()->exec('DELETE FROM outbox');
		self::Pdo()->exec('DELETE FROM products WHERE id >= 8000');
	}

	/** A scratch copy of a committed fixture - never the fixture itself, per this workstream's reservation. */
	private function sourceCopy(int $version): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'importer-integrity-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);

		return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	}

	private function importer(PDO $source, ?callable $progress = null): DatabaseImporter
	{
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), $progress ?? static function ($message) {});
	}

	/** The rows a refused import must leave completely alone. */
	private function targetState(): array
	{
		$result = [];

		foreach (['products', 'outbox', 'migrations'] as $table)
		{
			$result[$table] = self::Pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
		}

		return $result;
	}

	// --- H7(a): unsupported product nesting is repaired, and the guard works afterward ----

	public function testImportRepairsAMultiLevelProductChainAndReportsIt(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, parent_product_id)
			VALUES (8001, 'Protein', 1, 2, 2, NULL), (8002, 'Beef', 1, 2, 2, 8001), (8003, 'Chuck roast', 1, 2, 2, 8002)");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		$rows = self::Pdo()->query('SELECT id, parent_product_id FROM products WHERE id >= 8001 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame([8001 => null, 8002 => null, 8003 => 8002], $rows,
			'the middle product (8002) must have its parent link cleared; the leaf (8003) stays under it; the root (8001) is untouched');

		self::assertNotEmpty(preg_grep('/repaired 1 unsupported product nesting chain.*8002/', $messages),
			'the import output must name the repair: ' . implode("\n", $messages));

		// The point of the repair: an ordinary write to product 8002 must now succeed rather
		// than hitting trg_enfore_product_nesting_level (migrations/0277.pgsql.sql), which
		// this fixture's schema still enforces on every other product in the target.
		self::Pdo()->exec("UPDATE products SET name = 'Beef (renamed)' WHERE id = 8002");
		self::assertSame('Beef (renamed)', self::Pdo()->query('SELECT name FROM products WHERE id = 8002')->fetchColumn());
	}

	public function testImportWithNoNestingDefectReportsNothingAndLeavesGuardIntact(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertEmpty(preg_grep('/repaired.*nesting/', $messages), 'nothing to repair means nothing reported: ' . implode("\n", $messages));

		// The guard trigger this fixture's schema already carries (migrations/0277.pgsql.sql)
		// must still refuse an ordinary write that creates the same unsupported chain the
		// repair above corrects on import - proof the fix above is a repair, not a bypass.
		$rootId = (int)self::Pdo()->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Root', 1, 2, 2) RETURNING id")->fetchColumn();
		$middleId = (int)self::Pdo()->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES ('Middle', 1, 2, 2, $rootId) RETURNING id")->fetchColumn();

		$this->expectException(\PDOException::class);
		$this->expectExceptionMessageMatches('/Unsupported product nesting level/');
		// Middle already has a parent (Root); giving it a child of its own is the same
		// grandchild-arrival case the trigger's first check exists for.
		self::Pdo()->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES ('Leaf', 1, 2, 2, $middleId)");
	}

	// --- H7(b): a stale outbox event does not survive a replace --------------------------

	public function testImportClearsAStaleOutboxEventEvenWhenTheSourcePredatesTheTable(): void
	{
		self::Pdo()->exec("INSERT INTO outbox (event_type, payload, attempts) VALUES ('stock.transaction_booked', '{\"marker\":\"pre-import\"}', 0)");
		self::assertSame(1, (int)self::Pdo()->query("SELECT count(*) FROM outbox WHERE payload LIKE '%pre-import%'")->fetchColumn());

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		self::assertFalse(
			(bool)$source->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='outbox'")->fetchColumn(),
			'the minimum-supported fixture must predate `outbox` (0259) - that is the scenario this test covers'
		);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)self::Pdo()->query('SELECT count(*) FROM outbox')->fetchColumn(),
			'no outbox row - stale or otherwise - may survive a replace the source could not have contributed to');
		self::assertNotEmpty(preg_grep('/outbox.*cleared/', $messages), 'the clearing must be reported: ' . implode("\n", $messages));
	}

	public function testNonForceImportRefusesAPreExistingOutboxRowRatherThanSilentlyDiscardingIt(): void
	{
		// Isolate AssertDerivedStateIsEmpty() from the pre-existing AssertTargetIsEmpty():
		// the freshly migrated target's ordinary seeded rows (an admin user, api_keys, the
		// default quantity units) would otherwise trip that broader, unrelated check first,
		// on every real target - which is not the gap this test exists to cover. Only the
		// ordinary common tables are emptied; NOT_COPIED_TABLES/TARGET_ONLY_TABLES (roles,
		// permission_fields, label_import_state's single bootstrap row, ...) are left exactly
		// as the migration run seeded them, the way a real DatabaseImporter run leaves them.
		$excluded = array_merge(['outbox'], DatabaseImporter::NOT_COPIED_TABLES, DatabaseImporter::TARGET_ONLY_TABLES);
		$otherTables = array_diff(self::Pdo()->query("SELECT table_name FROM information_schema.tables
			WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN), $excluded);
		self::Pdo()->exec('TRUNCATE TABLE ' . implode(', ', array_map(fn($t) => '"' . $t . '"', $otherTables)) . ' RESTART IDENTITY CASCADE');

		self::Pdo()->exec("INSERT INTO outbox (event_type, payload, attempts) VALUES ('stock.transaction_booked', '{}', 0)");
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$thrown = null;

		try
		{
			$this->importer($source)->Import(false);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'a non-force import must refuse when the target already holds an outbox row');
		self::assertStringContainsString('outbox', $thrown->getMessage());
		self::assertStringContainsString('--force', $thrown->getMessage());
		self::assertSame(1, (int)self::Pdo()->query('SELECT count(*) FROM outbox')->fetchColumn(), 'refusal must leave the target unchanged');
	}

	// --- M18: a hole below the maximum is refused on both sides ---------------------------

	public function testImportRefusesASourceWithAMigrationHoleBelowItsClaimedMaximum(): void
	{
		$max = DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX;
		$source = $this->sourceCopy($max);

		$holeNumber = (int)$source->query('SELECT migration FROM migrations WHERE migration NOT IN (8888, 9999) AND migration <> ' . $max . ' ORDER BY migration DESC LIMIT 1')->fetchColumn();
		self::assertGreaterThan(0, $holeNumber, 'the fixture must have a migration below its maximum to punch a hole at');
		$source->exec('DELETE FROM migrations WHERE migration = ' . $holeNumber);
		self::assertSame($max, (int)$source->query('SELECT max(migration) FROM migrations')->fetchColumn(),
			'the maximum must be unaffected by the hole - that is exactly the M18 scenario');

		$before = $this->targetState();
		$thrown = null;

		try
		{
			$this->importer($source)->Import(true);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'an import from a source with a recorded hole below its maximum must be refused');
		self::assertStringContainsString((string)$holeNumber, $thrown->getMessage());
		self::assertStringContainsString((string)$max, $thrown->getMessage());
		self::assertSame($before, $this->targetState());
	}

	public function testImportRefusesATargetWithAMigrationHoleBelowItsOwnMaximum(): void
	{
		$maxBefore = (int)self::Pdo()->query('SELECT max(migration) FROM migrations')->fetchColumn();
		$holeNumber = (int)self::Pdo()->query('SELECT migration FROM migrations WHERE migration NOT IN (8888, 9999) AND migration <> ' . $maxBefore . ' ORDER BY migration DESC LIMIT 1')->fetchColumn();
		self::assertGreaterThan(0, $holeNumber, 'the freshly migrated target must have a migration below its maximum to punch a hole at');

		self::Pdo()->exec('DELETE FROM migrations WHERE migration = ' . $holeNumber);
		$this->deletedTargetMigration = $holeNumber; // tearDown() restores this regardless of outcome below
		self::assertSame($maxBefore, (int)self::Pdo()->query('SELECT max(migration) FROM migrations')->fetchColumn(),
			'the maximum must be unaffected by the hole - that is exactly the M18 scenario');

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$before = $this->targetState();
		$thrown = null;

		try
		{
			$this->importer($source)->Import(true);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'an import into a target with a recorded hole below its maximum must be refused');
		self::assertStringContainsString((string)$holeNumber, $thrown->getMessage());
		self::assertSame($before, $this->targetState());
	}
}
