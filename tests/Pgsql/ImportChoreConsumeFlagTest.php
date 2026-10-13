<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #923: migration 0312's chores_consume_product_on_execution_check would refuse a legacy
 * source chore whose consume_product_on_execution is not 0 or 1 with a bare SQLSTATE 23514
 * from whichever COPY batch holds it (upstream grocy's column has no CHECK).
 * DatabaseImporter::AssertChoreConsumeFlags() refuses first, before anything is truncated,
 * naming the rows. The target is already at 0312 here, so the COPY would fail and roll back
 * even without the assertion; the message text is what proves the assertion ran. Modeled on
 * ImportConversionFactorTest.
 *
 * A SQLite source can hold any integer, a real, text or a blob in this column. NULL cannot be
 * built here (the fixtures' column is NOT NULL); the predicate still covers it.
 */
class ImportChoreConsumeFlagTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	private function source(int $version, array $flags): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'chore-flag-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);
		$source = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$source->exec('DELETE FROM chores_log');
		$source->exec('DELETE FROM chores');
		foreach ($flags as $id => $literal)
		{
			$source->exec("INSERT INTO chores (id, name, period_type, consume_product_on_execution) VALUES ($id, 'source chore $id', 'manually', $literal)");
		}
		return $source;
	}

	protected function tearDown(): void
	{
		foreach ($this->files as $file)
		{
			foreach ([$file, $file . '-wal', $file . '-shm'] as $path)
			{
				if (file_exists($path)) { unlink($path); }
			}
		}
	}

	private function importer(PDO $source): DatabaseImporter
	{
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), static function ($message) {});
	}

	private static function targetState(): array
	{
		return self::Pdo()->query('SELECT * FROM chores ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
	}

	/** A target row the refused import must leave alone; an empty table cannot be told from a truncated one. */
	private static function seedTargetChore(): void
	{
		self::Pdo()->exec("INSERT INTO chores (name, period_type, consume_product_on_execution) VALUES ('target survivor " . uniqid() . "', 'manually', 1)");
		self::assertGreaterThan(0, (int)self::Pdo()->query('SELECT count(*) FROM chores')->fetchColumn());
	}

	public static function versions(): array { return [[255], [265]]; }

	#[DataProvider('versions')]
	public function testOtherValuesAreRefusedBeforeTruncate(int $version): void
	{
		$source = $this->source($version, [9001 => '0', 9002 => '1', 9003 => '2', 9004 => '-1', 9005 => "'abc'", 9006 => '1.5']);
		self::seedTargetChore();
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Other values must refuse'); }
		catch (\RuntimeException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('Import refused: 4 source chores row(s)', $message);
			self::assertStringContainsString('chores_consume_product_on_execution_check', $message);
			self::assertStringContainsString('migration 0312', $message);
			self::assertStringContainsString('--force does not bypass this check', $message);
			self::assertStringContainsString('FROM chores WHERE', $message);
			foreach ([9003, 9004, 9005, 9006] as $id)
			{
				self::assertStringContainsString('"id":' . $id . ',', $message);
			}
			self::assertStringNotContainsString('"id":9001,', $message);
			self::assertStringNotContainsString('"id":9002,', $message);
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	public function testBlobIsRefusedAndSampleSurvivesInvalidUtf8(): void
	{
		$source = $this->source(265, [9001 => '1', 9007 => "X'00FF'"]);
		self::seedTargetChore();
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('A blob must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('Import refused: 1 source chores row(s)', $ex->getMessage());
			self::assertStringContainsString('"id":9007,', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
	}

	public function testSampleIsLimitedToTenRowsButCountIsComplete(): void
	{
		$flags = [];
		for ($id = 9100; $id < 9112; $id++) { $flags[$id] = '2'; }
		$source = $this->source(265, $flags);
		try { $this->importer($source)->Import(true); self::fail('Other values must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('Import refused: 12 source chores row(s)', $ex->getMessage());
			self::assertSame(10, substr_count($ex->getMessage(), '"consume_product_on_execution":"2"'));
		}
	}

	#[DataProvider('versions')]
	public function testSourceWithOnlyZeroAndOneStillImports(int $version): void
	{
		$source = $this->source($version, [9001 => '0', 9002 => '1']);
		$this->importer($source)->Import(true);
		self::assertEquals(
			[['id' => 9001, 'consume_product_on_execution' => 0], ['id' => 9002, 'consume_product_on_execution' => 1]],
			self::Pdo()->query('SELECT id, consume_product_on_execution FROM chores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
		);
	}
}
