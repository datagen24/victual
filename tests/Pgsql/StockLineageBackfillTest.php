<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Services\StockLineageService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0036 acceptance prerequisite 3: the backfill of migration 0304 over legacy history.
 *
 * The history is tests/Pgsql/fixtures/lineage-legacy.json, written by the real StockService of
 * a tree from before 0304 (the revision is in the file; .devtools/pgsql/lineage-legacy/ holds
 * the generator). It holds one product per family class: E (exact), X (exact by arithmetic),
 * U (unknown) and N (nothing live), including the tag-rewriting merge only that older service
 * performed.
 *
 * Each test loads the fixture into an otherwise empty ledger, so the stock, stock_log and
 * stock_entry_origins rows are exactly what the older image left.
 */
class StockLineageBackfillTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static array $fixture;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'lineage-backfill', 'fixture')");
		self::$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/lineage-legacy.json'), true, flags: JSON_THROW_ON_ERROR);
	}

	protected function setUp(): void
	{
		self::$db->exec('TRUNCATE stock_row_lots, stock_booking_lots, stock_entry_origins, stock, stock_log CASCADE');
		$products = implode(',', array_column(self::$fixture['products'], 'id'));
		$locations = implode(',', array_column(self::$fixture['locations'], 'id'));
		self::$db->exec("DELETE FROM products WHERE id IN ($products)");
		self::$db->exec("DELETE FROM locations WHERE id IN ($locations)");

		foreach (['locations', 'products', 'stock_log', 'stock', 'stock_entry_origins'] as $table)
		{
			foreach (self::$fixture[$table] as $row)
			{
				$columns = array_keys($row);
				$statement = self::$db->prepare("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES ('
					. implode(', ', array_fill(0, count($columns), '?')) . ')');
				$statement->execute(array_map(static fn($value) => is_bool($value) ? (int)$value : $value, array_values($row)));
			}
		}

		foreach (['locations', 'products', 'stock_log', 'stock'] as $table)
		{
			self::$db->query("SELECT setval(pg_get_serial_sequence('$table', 'id'), (SELECT max(id) FROM $table))");
		}
	}

	private static function product(string $scenario): int
	{
		return (int)self::$fixture['scenarios'][$scenario];
	}

	/** The rows the backfill must never write, plus labels. */
	private static function untouched(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_entry_origins' => self::$db->query('SELECT * FROM stock_entry_origins ORDER BY stock_id')->fetchAll(PDO::FETCH_ASSOC),
			'labels' => self::$db->query('SELECT * FROM labels ORDER BY uid')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	private static function lineage(): string
	{
		return json_encode([
			self::$db->query('SELECT stock_row_id, lot_id, amount, basis FROM stock_row_lots ORDER BY stock_row_id, lot_id NULLS FIRST')->fetchAll(PDO::FETCH_ASSOC),
			self::$db->query('SELECT booking_id, lot_id, amount, basis FROM stock_booking_lots ORDER BY booking_id, lot_id NULLS FIRST')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/**
	 * The contributions of a product's rows as [row amount => [lot => [amount, basis]]], lots
	 * named by the 1-based position of their booking among the product's bookings ('pool' for
	 * none), so the expectations below read like ADR-0036's worked examples.
	 */
	private static function contributions(string $scenario): array
	{
		$product = self::product($scenario);
		$names = self::bookingNames($product);
		$result = [];
		$rows = self::$db->query("SELECT s.id, s.amount, rl.lot_id, rl.amount AS held, rl.basis
			FROM stock s LEFT JOIN stock_row_lots rl ON rl.stock_row_id = s.id
			WHERE s.product_id = $product ORDER BY s.id, rl.lot_id NULLS FIRST")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row)
		{
			$key = 'row ' . $row['id'] . ' = ' . (float)$row['amount'];
			$result[$key] ??= [];
			if ($row['held'] !== null)
			{
				$result[$key][$row['lot_id'] === null ? 'pool' : $names[(int)$row['lot_id']]] = [(float)$row['held'], $row['basis']];
			}
		}
		return array_values($result);
	}

	/** Allocations per booking, keyed "#<position> <type>", lots named as in contributions(). */
	private static function allocations(string $scenario): array
	{
		$product = self::product($scenario);
		$names = self::bookingNames($product);
		$result = [];
		$bookings = self::$db->query("SELECT l.id, l.transaction_type, bl.lot_id, bl.amount, bl.basis
			FROM stock_log l LEFT JOIN stock_booking_lots bl ON bl.booking_id = l.id
			WHERE l.product_id = $product ORDER BY l.id, bl.lot_id NULLS FIRST")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($bookings as $booking)
		{
			$key = $names[(int)$booking['id']] . ' ' . $booking['transaction_type'];
			$result[$key] ??= [];
			if ($booking['amount'] !== null)
			{
				$result[$key][$booking['lot_id'] === null ? 'pool' : $names[(int)$booking['lot_id']]] = [(float)$booking['amount'], $booking['basis']];
			}
		}
		return $result;
	}

	private static function bookingNames(int $product): array
	{
		$names = [];
		foreach (self::$db->query("SELECT id FROM stock_log WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $i => $id)
		{
			$names[(int)$id] = '#' . ($i + 1);
		}
		return $names;
	}

	private static function violations(): array
	{
		return self::$db->query('SELECT * FROM stock_lineage_violations()')->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Puts the database back to the state before 0304 ran: no tables, no functions, no record.
	 * trg_cascade_change_qu_id_stock keeps 0304's body, which names the dropped tables; no test
	 * in this class changes a product's stock unit, and the migration rerun replaces it.
	 */
	private static function unmigrate(): void
	{
		self::$db->exec('DROP TABLE stock_row_lots, stock_booking_lots;
			DROP FUNCTION stock_lineage_violations(INTEGER), stock_lineage_backfill(INTEGER), stock_lineage_families(INTEGER),
				stock_log_lineage_delta(TEXT, DOUBLE PRECISION), stock_log_is_addition(TEXT, DOUBLE PRECISION);
			DELETE FROM migrations WHERE migration = 304');
	}

	private static function assertMigrated(): void
	{
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM migrations WHERE migration = 304')->fetchColumn());
		self::assertTrue((bool)self::$db->query("SELECT to_regclass('stock_row_lots') IS NOT NULL")->fetchColumn());
	}

	public function testTheBackfillClassifiesEveryFamilyAndWritesOnlyTheLineageTables(): void
	{
		$row = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . self::product('E_plain'))->fetchColumn();
		self::$db->beginTransaction();
		(new LabelIdentityService(self::$db))->Issue('stock_entry', $row, (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn());
		self::$db->commit();
		$before = self::untouched();

		$classes = self::$db->query('SELECT family_class, families FROM stock_lineage_backfill() ORDER BY family_class')->fetchAll(PDO::FETCH_KEY_PAIR);

		self::assertSame($before, self::untouched(), 'The backfill writes nothing to stock, stock_log, stock_entry_origins or labels');
		self::assertSame([], self::violations(), 'I1, I2 and I3 hold after the backfill');
		// E: E_plain, E_consumed, E_open_split, E_transfer_split, E_edit_down and the live purchase
		// of E_with_undone_purchase. N: its undone purchase and N_undone. U: three scenarios, the
		// split without an origin counting twice (the remainder's tag is its own family).
		self::assertSame(['E' => 6, 'N' => 2, 'U' => 4, 'X' => 1], array_map('intval', $classes));

		self::assertSame([['#1' => [3.0, 'derived']]], self::contributions('E_plain'));
		self::assertSame(['#1 purchase' => ['#1' => [3.0, 'derived']]], self::allocations('E_plain'));

		self::assertSame([['#1' => [3.0, 'derived']]], self::contributions('E_consumed'));
		self::assertSame(['#1 purchase' => ['#1' => [5.0, 'derived']], '#2 consume' => ['#1' => [-2.0, 'derived']]],
			self::allocations('E_consumed'));

		self::assertSame([['#1' => [2.0, 'derived']], ['#1' => [3.0, 'derived']]], self::contributions('E_open_split'));
		self::assertSame(['#1 purchase' => ['#1' => [5.0, 'derived']], '#2 product-opened' => ['#1' => [2.0, 'derived']]],
			self::allocations('E_open_split'));

		self::assertSame([['#1' => [3.0, 'derived']], ['#1' => [2.0, 'derived']]], self::contributions('E_transfer_split'));
		self::assertSame(['#1 purchase' => ['#1' => [5.0, 'derived']], '#2 transfer_from' => ['#1' => [-2.0, 'derived']],
			'#3 transfer_to' => ['#1' => [2.0, 'derived']]], self::allocations('E_transfer_split'));

		self::assertSame([['#1' => [3.0, 'derived']]], self::contributions('E_edit_down'));
		self::assertSame(['#1 purchase' => ['#1' => [4.0, 'derived']], '#2 stock-edit-old' => ['#1' => [4.0, 'derived']],
			'#3 stock-edit-new' => ['#1' => [3.0, 'derived']]], self::allocations('E_edit_down'));

		self::assertSame([['#1' => [2.0, 'derived']]], self::contributions('E_with_undone_purchase'));
		self::assertSame(['#1 purchase' => ['#1' => [2.0, 'derived']], '#2 purchase' => []], self::allocations('E_with_undone_purchase'));

		self::assertSame([['#1' => [3.0, 'derived'], '#2' => [2.0, 'derived']]], self::contributions('X_merged'));
		self::assertSame(['#1 purchase' => ['#1' => [3.0, 'derived']], '#2 purchase' => ['#2' => [2.0, 'derived']]],
			self::allocations('X_merged'));

		self::assertSame([['pool' => [4.0, 'unknown']]], self::contributions('U_merged_consumed'));
		self::assertSame(['#1 purchase' => ['#1' => [3.0, 'unknown']], '#2 purchase' => ['#2' => [2.0, 'unknown']], '#3 consume' => []],
			self::allocations('U_merged_consumed'));

		self::assertSame([['pool' => [4.0, 'unknown']]], self::contributions('U_merged_edited'));
		self::assertSame(['#1 purchase' => ['#1' => [3.0, 'unknown']], '#2 purchase' => ['#2' => [2.0, 'unknown']],
			'#3 stock-edit-old' => [], '#4 stock-edit-new' => []], self::allocations('U_merged_edited'));

		self::assertSame([['pool' => [2.0, 'unknown']], ['pool' => [3.0, 'unknown']]], self::contributions('U_split_without_origin'));
		self::assertSame(['#1 purchase' => ['#1' => [5.0, 'unknown']], '#2 product-opened' => []], self::allocations('U_split_without_origin'));

		self::assertSame([], self::contributions('N_undone'));
		self::assertSame(['#1 purchase' => []], self::allocations('N_undone'));

		$lineage = self::lineage();
		$again = self::$db->query('SELECT family_class, families FROM stock_lineage_backfill()')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame(['N' => 2], array_map('intval', $again), 'A second run finds only the N families, which hold nothing to write');
		self::assertSame($lineage, self::lineage(), 'A second run writes nothing');
		self::assertSame($before, self::untouched());
	}

	public function testTheMigrationBackfillsAnUpgradedDatabase(): void
	{
		self::unmigrate();
		$before = self::untouched();

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertMigrated();
		self::assertSame($before, self::untouched());
		self::assertSame([], self::violations());
		self::assertSame([['#1' => [3.0, 'derived'], '#2' => [2.0, 'derived']]], self::contributions('X_merged'));
		self::assertSame([['pool' => [4.0, 'unknown']]], self::contributions('U_merged_consumed'));
	}

	public function testACorruptFamilyFailsTheMigrationAndRollsItBack(): void
	{
		self::unmigrate();
		$row = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . self::product('E_consumed'))->fetchColumn();
		self::$db->exec("UPDATE stock SET amount = 'Infinity' WHERE id = $row");
		$before = self::untouched();

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('A non-finite stock amount must fail the migration');
		}
		catch (\PDOException $exception)
		{
			self::assertStringContainsString("stock row $row has a non-finite amount", $exception->getMessage());
		}

		self::assertFalse(self::$db->inTransaction());
		self::assertSame($before, self::untouched());
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM migrations WHERE migration = 304')->fetchColumn());
		self::assertFalse((bool)self::$db->query("SELECT to_regclass('stock_row_lots') IS NOT NULL")->fetchColumn(), 'The tables were rolled back too');

		self::$db->exec("UPDATE stock SET amount = 3 WHERE id = $row");
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertMigrated();
		self::assertSame([], self::violations());
	}

	public function testTheValidationNamesEachViolatedInvariant(): void
	{
		self::$db->query('SELECT count(*) FROM stock_lineage_backfill()')->fetchColumn();
		$row = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . self::product('E_plain'))->fetchColumn();
		$purchase = (int)self::$db->query('SELECT id FROM stock_log WHERE product_id = ' . self::product('X_merged') . ' ORDER BY id LIMIT 1')->fetchColumn();
		$lot = (int)self::$db->query('SELECT id FROM stock_log WHERE product_id = ' . self::product('E_consumed') . ' ORDER BY id LIMIT 1')->fetchColumn();

		self::$db->exec("UPDATE stock_row_lots SET amount = 1 WHERE stock_row_id = $row");
		self::$db->exec("UPDATE stock_booking_lots SET amount = 2.5 WHERE booking_id = $purchase");
		self::$db->exec("UPDATE stock_booking_lots SET amount = -1 WHERE lot_id = $lot AND booking_id <> $lot");

		$found = array_map(static fn($v) => [$v['invariant'], (int)$v['subject_id'], (float)$v['expected'], (float)$v['actual']], self::violations());
		usort($found, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		self::assertSame([
			['I1', $row, 3.0, 1.0],
			['I2', $lot + 1, -2.0, -1.0],
			['I2', $purchase, 3.0, 2.5],
			['I3', (int)self::$db->query("SELECT lot_id FROM stock_row_lots WHERE stock_row_id = $row")->fetchColumn(), 3.0, 1.0],
			['I3', $lot, 4.0, 3.0],
			['I3', $purchase, 2.5, 3.0],
		], $found);
	}

	/**
	 * StockLineageService on the differential harness's SQLite side, which has no lineage
	 * tables (migration 0304 is PostgreSQL-only): every method does nothing and reads nothing,
	 * so the writers behave there exactly as before ADR-0036.
	 */
	public function testTheLineageServiceDoesNothingOnTheSqliteComparisonSide(): void
	{
		$connection = new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw');
		$postgres = $connection->getValue();
		$connection->setValue(null, new PDO('sqlite::memory:'));
		try
		{
			$lineage = StockLineageService::GetInstance();
			self::assertFalse($lineage->Applies());
			self::assertSame([], $lineage->RowLots(1));
			self::assertSame(0.0, $lineage->RowLotTotal(1));
			self::assertSame([], $lineage->TakeFifo(1, 2));
			self::assertSame([], $lineage->AllocationsOf(1));
			self::assertFalse($lineage->IsTracked(1));
			self::assertNull($lineage->DependentBooking(1, 1, null));
			$lineage->AddLot(1, 1, 1);
			$lineage->SetLots(1, [[1, 1.0, 'recorded']]);
			$lineage->RemoveLot(1, 1, 1);
			$lineage->MoveLots(1, 2);
			$lineage->Allocate(1, [[1, 1.0]]);
			$lineage->RecordAddition(1, 1, 1);
			$lineage->EnsureTracked(1);
			$lineage->ReconcileProduct(1);
			$lineage->Rescale(1, 2);
		}
		finally
		{
			$connection->setValue(null, $postgres);
		}
	}

	/** The two guards a writer should never reach: an over-draw throws, and allocations netting to zero are not written. */
	public function testTheLineageServiceRefusesAnOverdrawAndSkipsANetZeroAllocation(): void
	{
		$row = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . self::product('E_plain'))->fetchColumn();
		$purchase = (int)self::$db->query('SELECT id FROM stock_log WHERE product_id = ' . self::product('E_plain'))->fetchColumn();
		self::$db->query('SELECT count(*) FROM stock_lineage_backfill()');
		$lineage = StockLineageService::GetInstance();

		self::$db->beginTransaction();
		try
		{
			$lineage->TakeFifo($row, 5);
			self::fail('Drawing more than a row holds must throw');
		}
		catch (\LogicException $exception)
		{
			self::assertStringContainsString("Stock row $row holds less than 5", $exception->getMessage());
		}
		finally
		{
			self::$db->rollBack();
		}

		$consume = (int)self::$db->query('SELECT id FROM stock_log WHERE product_id = ' . self::product('E_consumed') . " AND transaction_type = 'consume'")->fetchColumn();
		self::$db->exec("DELETE FROM stock_booking_lots WHERE booking_id = $consume");
		$lineage->Allocate($consume, [[$purchase, 1.0], [$purchase, -1.0]]);
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM stock_booking_lots WHERE booking_id = $consume")->fetchColumn());
	}

}
