<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #916: migration 0313's finite-number constraints would refuse a legacy source row holding
 * an infinity, or text PostgreSQL reads as NaN or an infinity, with a bare SQLSTATE 23514 from
 * whichever COPY batch holds it. DatabaseImporter::AssertFiniteNumbers() refuses first, before
 * anything is truncated, naming every offending column. The target is already at 0313 here, so
 * the COPY would fail and roll back even without the assertion; the message text is what proves
 * the assertion ran. Modeled on ImportChoreConsumeFlagTest.
 *
 * SQLite stores ±Infinity as a REAL (9e999) and NaN as NULL, so NaN can only arrive as text.
 */
class ImportFiniteNumbersTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	/** @param array<int, array{0: string, 1: string}> $products id => [min_stock_amount literal, calories literal] */
	private function source(int $version, array $products): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'finite-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);
		$source = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$location = (int)$source->query('SELECT MIN(id) FROM locations')->fetchColumn();
		$qu = (int)$source->query('SELECT MIN(id) FROM quantity_units')->fetchColumn();
		foreach ($products as $id => [$minStock, $calories])
		{
			$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, calories) VALUES ($id, 'finite source $id', $location, $qu, $qu, $minStock, $calories)");
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
		return self::Pdo()->query('SELECT id, name FROM products ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
	}

	/** A target row the refused import must leave alone; an empty table cannot be told from a truncated one. */
	private static function seedTargetProduct(): void
	{
		self::Pdo()->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) SELECT 'target survivor " . uniqid() . "', (SELECT MIN(id) FROM locations), MIN(id), MIN(id) FROM quantity_units");
	}

	public static function versions(): array { return [[255], [265]]; }

	#[DataProvider('versions')]
	public function testNonFiniteValuesAreRefusedBeforeTruncateWithOneReport(int $version): void
	{
		$source = $this->source($version, [
			9001 => ['1', 'NULL'],
			9002 => ['9e999', 'NULL'],
			9003 => ['-9e999', 'NULL'],
			9004 => ['0', "'NaN'"],
			9005 => ['0', "' Infinity '"],
			9006 => ['2.5', '120'],
		]);
		self::seedTargetProduct();
		$before = self::targetState();

		try { $this->importer($source)->Import(true); self::fail('Non-finite values must refuse'); }
		catch (\RuntimeException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('Import refused: 4 source value(s) are NaN or infinite', $message);
			self::assertStringContainsString('migration 0313', $message);
			self::assertStringContainsString('products.min_stock_amount: 2 row(s)', $message);
			self::assertStringContainsString('products.calories: 2 row(s)', $message);
			self::assertStringContainsString('--force does not bypass this check', $message);
			foreach ([9002, 9003, 9004, 9005] as $id)
			{
				self::assertStringContainsString('"id":' . $id . ',', $message);
			}
			self::assertStringNotContainsString('"id":9001,', $message);
			self::assertStringNotContainsString('"id":9006,', $message);
		}

		self::assertSame($before, self::targetState());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	public function testFiniteValuesStillImport(): void
	{
		$source = $this->source(265, [9001 => ['1', 'NULL'], 9002 => ['2.5', '120']]);
		$this->importer($source)->Import(true);

		self::assertSame(2.5, (float)self::Pdo()->query('SELECT min_stock_amount FROM products WHERE id = 9002')->fetchColumn());
		self::assertSame(120.0, (float)self::Pdo()->query('SELECT calories FROM products WHERE id = 9002')->fetchColumn());
	}
}
