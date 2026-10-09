<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #565: an import replaces the data several target-only
 * tables are keyed to, but survives them unchanged - PR #561 (issue #496, H7b) settled
 * `outbox`, `print_jobs`/`print_attempts` history and `mqtt_published_entities`/
 * `mqtt_product_entities`, and deliberately left these four out:
 *
 *   - `label_idempotency_keys` and the label capture/render tables (`label_captures`,
 *     `label_render_requests`, `label_artifacts`) are NOT covered here: the importer
 *     contract now classifies all four as KEPT (see DatabaseImporter's own class
 *     docblock) - none exists in any supported source, the documented flow imports
 *     into a freshly migrated (so already empty) target, and on a --force import into
 *     a used target they are print history that `print_jobs` (kept, NOT_COPIED_TABLES)
 *     references via NO ACTION foreign keys that reprints rely on. These tests do not
 *     cover them.
 *   - `login_attempts` (0262): a failed-login throttle counter keyed to a *username*, not
 *     a user id, so a row surviving an import from a source that predates the table still
 *     throttles whatever account now holds that username.
 *   - `stock_entry_origins` (0267, PostgreSQL-only, above the SQLite freeze): the lineage
 *     linking a split stock entry back to its origin purchase, keyed to `stock_id` values
 *     the import replaces wholesale along with the rest of `stock`.
 *
 * Both are added to DatabaseImporter::DERIVED_STATE_TABLES, so both are covered by the
 * existing clear-on-truncate mechanism mqtt_product_entities already used (see
 * DatabaseImporter's own docblock and AssertDerivedStateIsEmpty()).
 */
class ImporterTargetOnlyTableRetentionTest extends PgsqlSchemaTestCase
{
	private array $files = [];

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

		self::Pdo()->exec('DELETE FROM login_attempts');
		self::Pdo()->exec('DELETE FROM stock_entry_origins');
	}

	/** A scratch copy of a committed fixture - never the fixture itself. */
	private function sourceCopy(int $version): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'importer-target-retention-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);

		return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	}

	private function importer(PDO $source, ?callable $progress = null): DatabaseImporter
	{
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), $progress ?? static function ($message) {});
	}

	// --- login_attempts: throttle counters keyed to a username, not a user id -------------

	public function testImportClearsAStaleLoginAttemptRowWhenTheSourcePredatesTheTable(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO login_attempts (username) VALUES ('pre-import-victim')");
		self::assertSame(1, (int)$db->query("SELECT count(*) FROM login_attempts WHERE username = 'pre-import-victim'")->fetchColumn());

		// 0255, the minimum supported source, predates login_attempts (0262).
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		self::assertFalse(
			(bool)$source->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='login_attempts'")->fetchColumn(),
			'the minimum-supported fixture must predate `login_attempts` (0262) - that is the scenario this test covers'
		);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM login_attempts')->fetchColumn(),
			'a throttle counter keyed to a username must not survive an import from a source that could not have contributed to it - '
			. 'the username may now belong to a different account entirely');
		self::assertNotEmpty(preg_grep('#login_attempts\s+cleared#', $messages), 'the clear must be reported: ' . implode("\n", $messages));

		// Unrelated target-only handling (mqtt_product_entities) must be undisturbed by this change.
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM mqtt_product_entities')->fetchColumn());
	}

	public function testImportCopiesLoginAttemptsAsAnOrdinaryCommonTableWhenTheSourceCarriesIt(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO login_attempts (username) VALUES ('pre-import-victim')");

		// The maximum supported source (0265) carries login_attempts (0262) as an ordinary
		// common table - so it is truncated and replaced with the source's own rows (none,
		// in this fixture), not merely "cleared" by the DERIVED_STATE_TABLES path.
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		self::assertTrue(
			(bool)$source->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='login_attempts'")->fetchColumn(),
			'the maximum-supported fixture must already carry `login_attempts` - that is the scenario this test covers'
		);

		$this->importer($source)->Import(true);

		$rows = $db->query('SELECT username FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame(['admin'], $rows,
			'the pre-import row must not survive - the ordinary common-table copy replaces it with exactly the source\'s own rows, not a superset');
	}

	// --- mcp_tool_settings (0307, ADR-0039): references users, which an import replaces ------

	public function testImportResetsTheMcpToolSwitchesToTheirDefaults(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO mcp_tool_settings (tool_name, enabled, updated_by) VALUES ('consume_product', true, 1)");

		$this->importer($this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX))->Import(true);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM mcp_tool_settings')->fetchColumn(),
			'TRUNCATE users ... CASCADE empties mcp_tool_settings (updated_by references users), so an import puts every tool back on its default: the write tools off');
	}

	// --- stock_entry_origins: split-stock lineage keyed to a stock_id the import replaces --

	public function testImportClearsAStaleStockEntryOriginRow(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO stock_entry_origins (stock_id, origin_stock_id) VALUES ('pre-import-split', 'pre-import-origin')");
		self::assertSame(1, (int)$db->query("SELECT count(*) FROM stock_entry_origins WHERE stock_id = 'pre-import-split'")->fetchColumn());

		// stock_entry_origins (0267) is PostgreSQL-only, above the SQLite freeze
		// (SUPPORTED_SOURCE_MIGRATION_MAX, 0265) - no source in the supported span can ever
		// carry it, so the maximum-supported fixture is used deliberately: this is not a
		// "the source happens to predate it" gap the way login_attempts and
		// mqtt_product_entities are, it is unconditional.
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		self::assertFalse(
			(bool)$source->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='stock_entry_origins'")->fetchColumn(),
			'even the maximum-supported fixture must predate `stock_entry_origins` (0267) - that is the scenario this test covers'
		);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM stock_entry_origins')->fetchColumn(),
			'split-stock lineage keyed to a stock_id the import just replaced must not survive - '
			. 'the surviving stock_id may point at nothing, or at an unrelated stock row that now reuses it');
		self::assertNotEmpty(preg_grep('#stock_entry_origins\s+cleared#', $messages), 'the clear must be reported: ' . implode("\n", $messages));
	}

	public function testImportClearingTargetOnlyTablesLeavesUnrelatedCommonTablesAlone(): void
	{
		$db = self::Pdo();
		$productId = 8601;

		$db->exec("INSERT INTO login_attempts (username) VALUES ('unrelated-check')");
		$db->exec("INSERT INTO stock_entry_origins (stock_id, origin_stock_id) VALUES ('unrelated-split', 'unrelated-origin')");

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ($productId, 'Retention fixture', 1, 2, 2)");

		$this->importer($source)->Import(true);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM login_attempts')->fetchColumn());
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM stock_entry_origins')->fetchColumn());

		// The source's own product must have been copied normally - clearing the two
		// target-only tables above must not have disturbed the ordinary common-table copy.
		self::assertSame('Retention fixture', $db->query('SELECT name FROM products WHERE id = ' . $productId)->fetchColumn(),
			'an unrelated common table must be copied normally; clearing the target-only tables must not affect it');
	}
}
