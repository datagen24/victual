<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class StockLocationImportTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	private function source(int $version): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'stock-location-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);
		return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

	private function importer(PDO $source, ?callable $progress = null): DatabaseImporter
	{
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), $progress ?? static function ($message) {});
	}

	private static function targetState(): array
	{
		$result = [];
		foreach (['stock', 'stock_log', 'locations', 'products', 'label_import_state'] as $table)
		{
			$result[$table] = self::Pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
		}
		$result['triggers'] = self::Pdo()->query("SELECT tgname, tgenabled FROM pg_trigger WHERE tgrelid='stock'::regclass ORDER BY tgname")->fetchAll(PDO::FETCH_ASSOC);
		return $result;
	}

	public static function versions(): array { return [[255], [265]]; }

	#[DataProvider('versions')]
	public function testValidNullAndHistoricalReferencesArePreserved(int $version): void
	{
		$source = $this->source($version);
		$source->exec('UPDATE stock SET location_id=NULL WHERE id=(SELECT MIN(id) FROM stock); UPDATE stock_log SET location_id=999');
		$expected = $source->query('SELECT id, product_id, amount, location_id FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$this->importer($source)->Import(true);
		self::assertFalse($source->inTransaction());
		self::assertEquals($expected, self::Pdo()->query('SELECT id, product_id, amount, location_id FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertGreaterThan(0, (int)self::Pdo()->query('SELECT count(*) FROM stock_log WHERE location_id=999')->fetchColumn());
	}

	#[DataProvider('versions')]
	public function testDanglingSourceIsActionableAndForceCannotTruncateTarget(int $version): void
	{
		$source = $this->source($version);
		$source->exec('UPDATE stock SET location_id=999 WHERE id=(SELECT MIN(id) FROM stock)');
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Dangling source must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('1 source stock rows reference missing locations', $ex->getMessage());
			self::assertStringContainsString('"location_id":999', $ex->getMessage());
			self::assertStringContainsString('Choose an explicit source repair', $ex->getMessage());
			self::assertStringContainsString('SELECT s.id, s.product_id, s.location_id', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	/**
	 * DatabaseImporter::AssertProductReferences() (issue #552, following ADR-0029's own
	 * import precedent): the same "report and refuse before anything is truncated" shape
	 * as testDanglingSourceIsActionableAndForceCannotTruncateTarget() above, extended from
	 * stock.location_id to one of the six foreign keys migrations/0295.pgsql.sql added on
	 * products. product_group_id stands in for all six here - it is the one of the six no
	 * legacy Grocy trigger reads (qu_id_purchase/qu_id_stock/qu_id_consume/qu_id_price all
	 * feed products_default_qu_conversions_*, which would insert its own
	 * quantity_unit_conversions row for a dangling value and fail on an unrelated unique
	 * constraint before AssertProductReferences() is ever reached; location_id has a
	 * default-fill trigger of its own). Each column has its own message built from the same
	 * template (AssertProductReferences()'s own $checks array), so one case proves the
	 * mechanism without repeating it six times.
	 */
	#[DataProvider('versions')]
	public function testDanglingProductReferenceIsActionableAndForceCannotTruncateTarget(int $version): void
	{
		$source = $this->source($version);
		$source->exec('UPDATE products SET product_group_id=999999 WHERE id=(SELECT MIN(id) FROM products)');
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Dangling source must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('1 source product rows reference missing product_groups via product_group_id', $ex->getMessage());
			self::assertStringContainsString('"product_group_id":999999', $ex->getMessage());
			self::assertStringContainsString('Choose an explicit source repair', $ex->getMessage());
			self::assertStringContainsString('SELECT p.id, p.name, p.product_group_id', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	public function testSourcePreflightAndCopyUseOneSnapshot(): void
	{
		$source = $this->source(265);
		$source->exec('PRAGMA journal_mode=WAL');
		$writer = new PDO('sqlite:' . end($this->files));
		$expected = $source->query('SELECT id, location_id FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$changed = false;
		$this->importer($source, static function ($message) use ($writer, &$changed)
		{
			if (!$changed && preg_match('/^\s+api_keys\s/', $message))
			{
				$writer->exec('UPDATE stock SET location_id=999');
				$changed = true;
			}
		})->Import(true);
		self::assertTrue($changed, 'A writer changed the source after validation and before stock copy');
		self::assertEquals($expected, self::Pdo()->query('SELECT id, location_id FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
		self::assertSame(999, (int)$source->query('SELECT location_id FROM stock LIMIT 1')->fetchColumn());
	}

	public function testCopyFailureRestoresTargetAndRetainsCallerSourceTransaction(): void
	{
		$source = $this->source(255);
		$source->beginTransaction();
		$before = self::targetState();
		try
		{
			$this->importer($source, static function ($message)
			{
				if (preg_match('/^\s+stock\s/', $message)) { throw new \RuntimeException('Injected copy failure'); }
			})->Import(true);
			self::fail('Copy must fail');
		}
		catch (\RuntimeException $ex) { self::assertSame('Injected copy failure', $ex->getMessage()); }
		self::assertTrue($source->inTransaction());
		self::assertSame($before, self::targetState());
		self::assertFalse(self::Pdo()->inTransaction());
		$source->rollBack();
	}
}
