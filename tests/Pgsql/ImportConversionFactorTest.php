<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #903: migration 0309's quantity_unit_conversions_factor_positive CHECK would refuse
 * a legacy source factor that is not positive and finite with a bare SQLSTATE 23514 from
 * whichever COPY batch holds it. DatabaseImporter::AssertConversionFactors() refuses first,
 * before anything is truncated, naming the rows.
 *
 * What a SQLite source can hold in quantity_unit_conversions.factor (REAL NOT NULL): 0, any
 * negative, +/-Infinity (the literal 9e999) and text that is not a number. NaN cannot be
 * stored (SQLite turns it into NULL) and NULL is refused by the NOT NULL, so neither can be
 * built here; the predicate still covers both for sources whose column lacks the NOT NULL.
 * This file rather than .devtools/pgsql/import-tests.php section F because the in-process
 * fixture copy lets a test doctor a SQLite source in a few lines and compare target state,
 * where section F shells out to bin/victual-db-import and parses its output.
 */
class ImportConversionFactorTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	private function source(int $version, array $factors): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'conversion-factor-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);
		$source = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		// The legacy triggers mirror every insert into an inverse row (1 / factor) and a
		// uniqueness guard; they would turn the hostile values into other errors or rows.
		foreach ($source->query("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='quantity_unit_conversions'")->fetchAll(PDO::FETCH_COLUMN) as $trigger)
		{
			$source->exec('DROP TRIGGER "' . $trigger . '"');
		}
		$source->exec('DELETE FROM quantity_unit_conversions');
		$units = $source->query('SELECT id FROM quantity_units ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
		foreach ($factors as $id => $literal)
		{
			$source->exec('INSERT INTO quantity_unit_conversions (id, from_qu_id, to_qu_id, factor) VALUES (' . $id . ', ' . $units[0] . ', ' . $units[1] . ', ' . $literal . ')');
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
		$result = [];
		foreach (['quantity_units', 'quantity_unit_conversions', 'products', 'stock'] as $table)
		{
			$result[$table] = self::Pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
		}
		return $result;
	}

	/**
	 * A target row the refused import must leave alone; the rows compared by targetState()
	 * would otherwise be empty tables, which a truncate cannot be told from.
	 */
	private static function seedTargetConversion(): void
	{
		$units = [];
		$suffix = uniqid('', true);
		foreach (['conv-seed-a-', 'conv-seed-b-'] as $prefix)
		{
			$name = $prefix . $suffix;
			$statement = self::Pdo()->prepare('INSERT INTO quantity_units (name) VALUES (?) RETURNING id');
			$statement->execute([$name]);
			$units[] = (int)$statement->fetchColumn();
		}
		self::Pdo()->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (' . $units[0] . ', ' . $units[1] . ', 3.5)');
		self::assertGreaterThan(0, (int)self::Pdo()->query('SELECT count(*) FROM quantity_unit_conversions')->fetchColumn());
	}

	public static function versions(): array { return [[255], [265]]; }

	#[DataProvider('versions')]
	public function testNonPositiveAndNonFiniteFactorsAreRefusedBeforeTruncate(int $version): void
	{
		$source = $this->source($version, [
			9001 => '2.5',
			9002 => '0',
			9003 => '-1',
			9004 => '9e999',
			9005 => '-9e999',
			9006 => "'abc'",
		]);
		self::seedTargetConversion();
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Bad factors must refuse'); }
		catch (\RuntimeException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('Import refused: 5 source quantity_unit_conversions row(s)', $message);
			self::assertStringContainsString('quantity_unit_conversions_factor_positive', $message);
			self::assertStringContainsString('migration 0309', $message);
			self::assertStringContainsString('--force does not bypass this check', $message);
			self::assertStringContainsString('FROM quantity_unit_conversions WHERE', $message);
			foreach ([9002, 9003, 9004, 9005, 9006] as $id)
			{
				self::assertStringContainsString('"id":' . $id . ',', $message);
			}
			self::assertStringNotContainsString('"id":9001,', $message);
			self::assertStringContainsString('"factor":"Inf"', $message);
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	public function testBlobFactorIsRefusedAndSampleSurvivesInvalidUtf8(): void
	{
		$source = $this->source(265, [9001 => '2.5', 9007 => "X'00FF'"]);
		self::seedTargetConversion();
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('A blob factor must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('Import refused: 1 source quantity_unit_conversions row(s)', $ex->getMessage());
			// json_encode() returns false for invalid UTF-8 without a substitute flag, which
			// would leave the sample empty.
			self::assertStringContainsString('"id":9007,', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
	}

	public function testSampleIsLimitedToTenRowsButCountIsComplete(): void
	{
		$factors = [];
		for ($id = 9100; $id < 9112; $id++) { $factors[$id] = '0'; }
		$source = $this->source(265, $factors);
		try { $this->importer($source)->Import(true); self::fail('Bad factors must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('Import refused: 12 source quantity_unit_conversions row(s)', $ex->getMessage());
			self::assertSame(10, substr_count($ex->getMessage(), '"factor":"0.0"'));
		}
	}

	#[DataProvider('versions')]
	public function testSourceWithOnlyValidFactorsStillImports(int $version): void
	{
		$source = $this->source($version, [9001 => '2.5', 9002 => '4']);
		$this->importer($source)->Import(true);
		self::assertEquals(
			[['id' => 9001, 'factor' => 2.5], ['id' => 9002, 'factor' => 4.0]],
			self::Pdo()->query('SELECT id, factor FROM quantity_unit_conversions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
		);
	}
}
