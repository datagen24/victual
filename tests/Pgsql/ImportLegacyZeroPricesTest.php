<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #774, ADR-0045 open questions 6, 8 and 11: an imported price of 0 is read as unknown
 * by default, through the legacy_zero_price marker, with every stored price copied unchanged.
 * The import says so in its progress output and in a price_interpretation_reports row. An
 * operator can choose to read every zero as known instead, and that is recorded the same way.
 */
class ImportLegacyZeroPricesTest extends PgsqlSchemaTestCase
{
	private array $files = [];
	private array $progress = [];

	/** A version 265 source with one product, and stock and ledger rows priced 0, 2.5 and NULL. */
	private function source(): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'legacyzero-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-265.db', $file);
		$source = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$location = (int)$source->query('SELECT MIN(id) FROM locations')->fetchColumn();
		$qu = (int)$source->query('SELECT MIN(id) FROM quantity_units')->fetchColumn();
		$user = (int)$source->query('SELECT MIN(id) FROM users')->fetchColumn();
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (9774, 'legacy zero product', $location, $qu, $qu)");
		foreach ([['zero', '0'], ['priced', '2.5'], ['unpriced', 'NULL']] as $i => [$tag, $price])
		{
			$id = 97740 + $i;
			$source->exec("INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id) VALUES ($id, 9774, 1, '2999-12-31', '2026-09-01', 'legacyzero-$tag', $price, 0, $location)");
			$source->exec("INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, transaction_id, user_id) VALUES ($id, 9774, 1, '2999-12-31', '2026-09-01', 'legacyzero-$tag', 'purchase', $price, $location, 'legacyzero-tx-$tag', $user)");
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
		$this->progress = [];
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), function ($message) { $this->progress[] = $message; });
	}

	/** @return array<string, array{0: ?string, 1: int}> stock_id => [stored price, marker] for one table */
	private static function readings(string $table): array
	{
		$rows = [];
		foreach (self::Pdo()->query("SELECT stock_id, price::text, legacy_zero_price FROM $table WHERE product_id = 9774 ORDER BY stock_id")->fetchAll(PDO::FETCH_NUM) as [$stockId, $price, $marker])
		{
			$rows[$stockId] = [$price, (int)$marker];
		}

		return $rows;
	}

	public function testAnImportedZeroIsReadAsUnknownAndEveryPriceIsCopiedUnchanged(): void
	{
		$reports = (int)self::Pdo()->query('SELECT count(*) FROM price_interpretation_reports')->fetchColumn();

		$this->importer($this->source())->Import(true);

		$expected = ['legacyzero-priced' => ['2.5', 0], 'legacyzero-unpriced' => [null, 0], 'legacyzero-zero' => ['0', 1]];
		self::assertSame($expected, self::readings('stock'));
		self::assertSame($expected, self::readings('stock_log'));

		$report = self::Pdo()->query('SELECT source, policy, rule, stock_rows, stock_log_rows FROM price_interpretation_reports ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
		self::assertSame($reports + 1, (int)self::Pdo()->query('SELECT count(*) FROM price_interpretation_reports')->fetchColumn(), 'the import records its reading');
		self::assertSame(['import', 'unknown'], [$report['source'], $report['policy']]);
		self::assertStringContainsString('read as unknown', $report['rule']);
		self::assertGreaterThanOrEqual(1, (int)$report['stock_rows']);
		self::assertGreaterThanOrEqual(1, (int)$report['stock_log_rows']);
		self::assertNotEmpty(array_filter($this->progress, fn ($line) => str_contains($line, 'priced 0 read as unknown (imported legacy zero); stored prices unchanged')), 'and says so while it runs');
	}

	public function testAnOperatorCanReadEveryImportedZeroAsKnown(): void
	{
		$importer = $this->importer($this->source());
		$importer->SetLegacyZeroPrices(DatabaseImporter::LEGACY_ZERO_PRICES_KNOWN);
		$importer->Import(true);

		self::assertSame(['0', 0], self::readings('stock')['legacyzero-zero'], 'the zero stays a known zero');
		self::assertSame(['0', 0], self::readings('stock_log')['legacyzero-zero']);

		$report = self::Pdo()->query('SELECT source, policy, rule FROM price_interpretation_reports ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
		self::assertSame(['import', 'known'], [$report['source'], $report['policy']]);
		self::assertStringContainsString('including any that were never entered', $report['rule'], 'the report says what the override covers');
		self::assertNotEmpty(array_filter($this->progress, fn ($line) => str_contains($line, 'read as known zeros')));
	}

	public function testAnUnknownPolicyIsRefused(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->importer($this->source())->SetLegacyZeroPrices('free');
	}
}
