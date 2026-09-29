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
	 * products. product_group_id stands in for the four nullable/side-effect-free columns
	 * here (location_id is the other, exercised as a NOT NULL column below) - none of the
	 * four qu_id_* columns can stand in for this case: all four feed
	 * products_default_qu_conversions_INS/UPD, which would insert its own
	 * quantity_unit_conversions row reacting to the dangling value and can fail on an
	 * unrelated unique constraint before AssertProductReferences() is ever reached. Each
	 * column has its own message built from the same template (AssertProductReferences()'s
	 * own $checks array), so one nullable and one NOT NULL case together prove the mechanism
	 * without repeating it six times.
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

	/**
	 * The NOT NULL half of the same mechanism (round 2 finding N2): location_id carries no
	 * legacy trigger either (confirmed by the same reading as product_group_id above), so a
	 * dangling value on it is refused exactly the same way, even though the column itself
	 * can never be null.
	 */
	#[DataProvider('versions')]
	public function testDanglingLocationReferenceIsActionableAndForceCannotTruncateTarget(int $version): void
	{
		$source = $this->source($version);
		$source->exec('UPDATE products SET location_id=999999 WHERE id=(SELECT MIN(id) FROM products)');
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Dangling source must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('1 source product rows reference missing locations via location_id', $ex->getMessage());
			self::assertStringContainsString('"location_id":999999', $ex->getMessage());
			self::assertStringContainsString('Choose an explicit source repair', $ex->getMessage());
			self::assertStringContainsString('SELECT p.id, p.name, p.location_id', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	/**
	 * Round 2 finding N3: AssertProductReferences() used to stop at the first dangling
	 * column, so an operator repairing one at a time discovered the next only on the next
	 * run - up to five more times for six columns. Two independently dangling columns on two
	 * different products must both be named in the one refusal.
	 */
	public function testMultipleDanglingProductReferencesAreAllReportedTogether(): void
	{
		$source = $this->source(265);
		$source->exec('UPDATE products SET product_group_id=999999 WHERE id=(SELECT MIN(id) FROM products)');
		$source->exec('UPDATE products SET location_id=888888 WHERE id=(SELECT MAX(id) FROM products)');
		$before = self::targetState();
		try { $this->importer($source)->Import(true); self::fail('Dangling source must refuse'); }
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('2 of products\' six reference columns', $ex->getMessage());
			self::assertStringContainsString('1 source product rows reference missing product_groups via product_group_id', $ex->getMessage());
			self::assertStringContainsString('"product_group_id":999999', $ex->getMessage());
			self::assertStringContainsString('1 source product rows reference missing locations via location_id', $ex->getMessage());
			self::assertStringContainsString('"location_id":888888', $ex->getMessage());
		}
		self::assertSame($before, self::targetState());
		self::assertFalse($source->inTransaction());
		self::assertFalse(self::Pdo()->inTransaction());
	}

	/**
	 * Round 2 finding N3: upstream Grocy's own migrations 0210 and 0219 (the ones that added
	 * qu_id_consume and qu_id_price) each test IFNULL(column, 0) = 0 in their AFTER INSERT
	 * default-fill trigger, so a legacy source has always been free to store literal 0 there
	 * meaning "unset" - the same meaning this schema gives NULL. Both columns now carry a
	 * foreign key (migrations/0295.pgsql.sql); without this translation a literal 0 would be
	 * refused as a dangling reference (or, worse, violate the foreign key mid-copy) for data
	 * that was never actually wrong.
	 *
	 * A minimal, purpose-built source rather than one of the committed fixtures: every
	 * committed fixture still carries its own copy of products_default_qu_conversions_UPD
	 * (db/pgsql/baseline/06_triggers_a.sql's SQLite counterpart), which reacts to *any*
	 * UPDATE touching qu_id_purchase/qu_id_stock/qu_id_consume/qu_id_price - regardless of
	 * the new value, zero or otherwise - by attempting to insert its own product-specific
	 * quantity_unit_conversions row, and collides with itself on this fixture's existing data
	 * for a reason unrelated to this fix (confirmed by reproducing the same failure with an
	 * ordinary non-zero value). A fresh in-memory source with one product row sidesteps that
	 * trigger family entirely - there are no triggers on it - and isolates exactly what this
	 * fix changes: the importer's own reading of a stored 0.
	 */
	public function testLegacyZeroInConsumeAndPriceUnitsImportsAsNull(): void
	{
		$source = new PDO('sqlite::memory:');
		$source->exec("CREATE TABLE migrations (migration INTEGER); INSERT INTO migrations VALUES (265);
			CREATE TABLE stock (id INTEGER, product_id INTEGER, location_id INTEGER);
			CREATE TABLE locations (id INTEGER, name TEXT); INSERT INTO locations VALUES (1, 'Fixture location');
			CREATE TABLE quantity_units (id INTEGER, name TEXT); INSERT INTO quantity_units VALUES (2, 'Fixture unit');
			CREATE TABLE product_groups (id INTEGER, name TEXT);
			CREATE TABLE products (id INTEGER, name TEXT, location_id INTEGER, qu_id_purchase INTEGER,
				qu_id_stock INTEGER, qu_id_consume INTEGER, qu_id_price INTEGER, product_group_id INTEGER);
			INSERT INTO products VALUES (1, 'Zero sentinel product', 1, 2, 2, 0, 0, NULL)");

		$this->importer($source)->Import(true, false);

		$row = self::Pdo()->query('SELECT qu_id_consume, qu_id_price FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
		self::assertNull($row['qu_id_consume'], 'a legacy 0 must land NULL, not a dangling literal 0');
		self::assertNull($row['qu_id_price'], 'a legacy 0 must land NULL, not a dangling literal 0');
	}

	/**
	 * Round 2 finding: .devtools/labels/identity-tests.php's own minimal source (migrations,
	 * stock, locations only - no products table at all) crashed AssertProductReferences()
	 * with a raw "no such table: products" driver error, because it queried `products`
	 * unconditionally. AssertProductReferences() now skips entirely when `products` is not
	 * among the tables the source and target have in common.
	 */
	public function testASourceWithNoProductsTableImportsWithoutError(): void
	{
		$source = new PDO('sqlite::memory:');
		$source->exec("CREATE TABLE migrations (migration INTEGER); INSERT INTO migrations VALUES (265);
			CREATE TABLE stock (id INTEGER, product_id INTEGER, location_id INTEGER);
			CREATE TABLE locations (id INTEGER, name TEXT); INSERT INTO locations VALUES (1, 'Replacement')");

		$this->importer($source)->Import(true, false);

		self::assertSame(0, (int)self::Pdo()->query('SELECT count(*) FROM products')->fetchColumn());
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
