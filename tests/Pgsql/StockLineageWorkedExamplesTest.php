<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\StockLineage;

/**
 * ADR-0036 acceptance prerequisite 5: worked examples 1 to 6 of the record, run through the real
 * StockService.
 *
 * After every accepted step each example asserts the product's stock rows (amount, and where it
 * matters open state and location), the lots each row holds, the allocations of the booking
 * just written, the undone flags, and invariants I1 to I3. After every refusal it asserts that
 * stock, stock_log and both lineage tables are byte-identical to before the attempt.
 *
 * Purchases are named A, B, C in booking order, as in the record; lots are shown by those names.
 */
class StockLineageWorkedExamplesTest extends PgsqlSchemaTestCase
{
	private const NEVER = '2999-12-31';
	private const PURCHASED = '2026-10-01';

	private static PDO $db;
	private static StockService $stock;
	private static int $l1;
	private static int $l2;
	private static array $fixture;

	/** @var array<int, string> lot booking id => name */
	private array $names = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'lineage-examples', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$stock = StockService::GetInstance();

		// Example 6's legacy history, loaded first so its fixed ids cannot collide with rows
		// this class creates, then backfilled as migration 0304 would.
		self::$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/lineage-legacy.json'), true, flags: JSON_THROW_ON_ERROR);
		foreach (['locations', 'products', 'stock_log', 'stock', 'stock_entry_origins'] as $table)
		{
			foreach (self::$fixture[$table] as $row)
			{
				$statement = self::$db->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES ('
					. implode(', ', array_fill(0, count($row), '?')) . ')');
				$statement->execute(array_map(static fn($value) => is_bool($value) ? (int)$value : $value, array_values($row)));
			}
		}
		foreach (['locations', 'products', 'stock_log', 'stock', 'stock_entry_origins'] as $table)
		{
			self::$db->query("SELECT setval(pg_get_serial_sequence('$table', 'id'), (SELECT COALESCE(max(id), 1) FROM $table))");
		}
		self::$db->query('SELECT count(*) FROM stock_lineage_backfill()');

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Examples L1']);
		self::$l1 = (int)$location->fetchColumn();
		$location->execute(['Examples L2']);
		self::$l2 = (int)$location->fetchColumn();
	}

	protected function setUp(): void
	{
		$this->names = [];
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function product(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute(['Example ' . $name, self::$l1]);
		return (int)$statement->fetchColumn();
	}

	/** A never-expiring purchase; returns its booking id and remembers its lot's name. */
	private function buy(int $product, float $amount, string $name, float $price = 1.0, ?int $location = null): int
	{
		$transaction = null;
		self::$stock->AddProduct($product, $amount, self::NEVER, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED, $price, $location ?? self::$l1, null, $transaction);
		$booking = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE transaction_id = '$transaction'")->fetchColumn();
		$this->names[$booking] = $name;
		return $booking;
	}

	private static function bookingsOf(string $transactionId): array
	{
		return array_map('intval', self::$db->query("SELECT id FROM stock_log WHERE transaction_id = '$transactionId' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
	}

	private function named(array $lots): array
	{
		$result = [];
		foreach ($lots as $lot => $amount)
		{
			$result[$lot === 'pool' ? 'pool' : ($this->names[$lot] ?? "#$lot")] = $amount;
		}
		ksort($result);
		return $result;
	}

	/**
	 * Asserts the product's rows, in id order, as [amount, [lot name => amount]] pairs, plus
	 * I1 to I3. $extra, when given, asserts more columns per row (same order).
	 */
	private function assertRows(int $product, array $expected, string $step, array $extra = []): void
	{
		$rows = self::$db->query("SELECT * FROM stock WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
		$lots = StockLineage::Lots(self::$db, $product);
		$actual = [];
		foreach ($rows as $i => $row)
		{
			$entry = [(float)$row['amount'], $this->named($lots[(int)$row['id']] ?? [])];
			foreach ($extra[$i] ?? [] as $column => $_)
			{
				$entry[$column] = (string)$row[$column];
			}
			$actual[] = $entry;
		}

		$wanted = [];
		foreach ($expected as $i => [$amount, $rowLots])
		{
			ksort($rowLots);
			$entry = [(float)$amount, array_map('floatval', $rowLots)];
			foreach ($extra[$i] ?? [] as $column => $value)
			{
				$entry[$column] = (string)$value;
			}
			$wanted[] = $entry;
		}

		self::assertSame($wanted, $actual, "$step: stock rows and their lots");
		StockLineage::AssertHolds(self::$db, $product, "($step)");
	}

	private function assertAllocations(int $booking, array $expected, string $step): void
	{
		ksort($expected);
		self::assertSame(array_map('floatval', $expected), $this->named(StockLineage::Allocations(self::$db, $booking)), "$step: allocations of booking $booking");
	}

	private static function assertUndone(array $bookings, int $undone, string $step): void
	{
		foreach ($bookings as $booking)
		{
			self::assertSame($undone, (int)self::$db->query("SELECT undone FROM stock_log WHERE id = $booking")->fetchColumn(), "$step: booking $booking undone = $undone");
		}
	}

	private static function state(int $product): string
	{
		return json_encode([
			self::$db->query("SELECT * FROM stock WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
			self::$db->query("SELECT * FROM stock_log WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
			self::$db->query("SELECT rl.* FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE s.product_id = $product ORDER BY rl.id")->fetchAll(PDO::FETCH_ASSOC),
			self::$db->query("SELECT bl.* FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id WHERE l.product_id = $product ORDER BY bl.id")->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	private static function undo(int $booking): void
	{
		self::$stock->UndoBooking($booking);
	}

	private static function refused(int $product, callable $work, string $step): void
	{
		$before = self::state($product);
		try
		{
			$work();
			self::fail("$step: must be refused");
		}
		catch (\PHPUnit\Framework\AssertionFailedError $failure)
		{
			throw $failure;
		}
		catch (\Exception $exception)
		{
			self::assertNotInstanceOf(\PDOException::class, $exception, "$step: refused by a rule, not by a constraint");
		}
		self::assertSame($before, self::state($product), "$step: a refusal leaves stock, stock_log and both lineage tables byte-identical");
	}

	private static function merge(int $product): void
	{
		self::$stock->CompactStockEntries($product);
	}

	private static function rowIds(int $product): array
	{
		return array_map('intval', self::$db->query("SELECT id FROM stock WHERE product_id = $product ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
	}

	// ------------------------------------------------------------------------------
	// Example 1: purchases of 3 and 2 merged, both orders
	// ------------------------------------------------------------------------------

	public static function orders(): array
	{
		return ['3 then 2' => [3.0, 2.0], '2 then 3' => [2.0, 3.0]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('orders')]
	public function testExample1MergeMovesLotsAndRewritesNoBooking(float $first, float $second): void
	{
		$p = self::product("1 $first/$second");
		$a = $this->buy($p, $first, 'A');
		$b = $this->buy($p, $second, 'B');
		[$rowA, $rowB] = self::rowIds($p);
		$tags = self::$db->query("SELECT id, stock_id FROM stock_log WHERE product_id = $p ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
		$this->assertRows($p, [[$first, ['A' => $first]], [$second, ['B' => $second]]], 'two purchases');
		$this->assertAllocations($a, ['A' => $first], 'purchase A');
		$this->assertAllocations($b, ['B' => $second], 'purchase B');

		self::merge($p);

		$this->assertRows($p, [[$first + $second, ['A' => $first, 'B' => $second]]], 'merged', [['id' => (string)$rowB, 'stock_id' => $tags[$b]]]);
		self::assertSame($tags, self::$db->query("SELECT id, stock_id FROM stock_log WHERE product_id = $p ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),
			'Both bookings keep their own stock_id');
		self::assertNotContains($rowA, self::rowIds($p), 'The survivor is MAX(id)');
		$this->assertAllocations($a, ['A' => $first], 'purchase A after the merge');
		$this->assertAllocations($b, ['B' => $second], 'purchase B after the merge');
	}

	// ------------------------------------------------------------------------------
	// Example 2: partial consumption, then undo of either purchase
	// ------------------------------------------------------------------------------

	public function testExample2ConsumeTwoOfThreeThenTwo(): void
	{
		$p = self::product('2a');
		$a = $this->buy($p, 3, 'A');
		$b = $this->buy($p, 2, 'B');
		self::merge($p);
		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 2, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [[3, ['A' => 1, 'B' => 2]]], 'consume 2');
		$this->assertAllocations($consume, ['A' => -2], 'consume 2');

		self::refused($p, fn() => self::undo($a), 'undo A (the consume touched lot A)');
		self::undo($b);
		$this->assertRows($p, [[1, ['A' => 1]]], 'undo B');
		self::assertUndone([$b], 1, 'undo B');
		self::assertUndone([$a, $consume], 0, 'undo B');
	}

	public function testExample2ConsumeFourOfThreeThenTwo(): void
	{
		$p = self::product('2b');
		$a = $this->buy($p, 3, 'A');
		$b = $this->buy($p, 2, 'B');
		self::merge($p);
		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 4, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [[1, ['B' => 1]]], 'consume 4');
		$this->assertAllocations($consume, ['A' => -3, 'B' => -1], 'consume 4');

		self::refused($p, fn() => self::undo($a), 'undo A');
		self::refused($p, fn() => self::undo($b), 'undo B');

		self::undo($consume);
		$this->assertRows($p, [[1, ['B' => 1]], [4, ['A' => 3, 'B' => 1]]], 'undo the consume');
		self::assertUndone([$consume], 1, 'undo the consume');

		self::undo($b);
		$this->assertRows($p, [[3, ['A' => 3]]], 'then undo B');
		self::undo($a);
		$this->assertRows($p, [], 'then undo A');
		self::assertUndone([$a, $b, $consume], 1, 'everything undone');
	}

	public function testExample2ConsumeTwoOfTwoThenThree(): void
	{
		$p = self::product('2c');
		$a = $this->buy($p, 2, 'A');
		$b = $this->buy($p, 3, 'B');
		self::merge($p);
		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 2, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [[3, ['B' => 3]]], 'consume 2');
		$this->assertAllocations($consume, ['A' => -2], 'consume 2');

		self::refused($p, fn() => self::undo($a), 'undo A');
		self::undo($b);
		$this->assertRows($p, [], 'undo B');
		self::assertUndone([$b], 1, 'undo B');
	}

	public function testExample2ConsumeFourOfTwoThenThree(): void
	{
		$p = self::product('2d');
		$a = $this->buy($p, 2, 'A');
		$b = $this->buy($p, 3, 'B');
		self::merge($p);
		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 4, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [[1, ['B' => 1]]], 'consume 4');
		$this->assertAllocations($consume, ['A' => -2, 'B' => -2], 'consume 4');

		self::refused($p, fn() => self::undo($a), 'undo A');
		self::refused($p, fn() => self::undo($b), 'undo B');
		self::undo($consume);
		self::undo($b);
		self::undo($a);
		$this->assertRows($p, [], 'consume, B and A undone');
		self::assertUndone([$a, $b, $consume], 1, 'everything undone');
	}

	// ------------------------------------------------------------------------------
	// Example 3: merge, transfer, merge again, reversal
	// ------------------------------------------------------------------------------

	public function testExample3MergeTransferMergeAgainAndReverse(): void
	{
		$p = self::product('3');
		$a = $this->buy($p, 3, 'A');
		$this->buy($p, 2, 'B');
		self::merge($p);
		[$source] = self::rowIds($p);

		$transfer = self::$stock->TransferProduct($p, 2, self::$l1, self::$l2);
		[$from, $to] = self::bookingsOf($transfer);
		$this->assertRows($p, [[3, ['A' => 1, 'B' => 2]], [2, ['A' => 2]]], 'transfer 2 to L2',
			[['location_id' => (string)self::$l1], ['location_id' => (string)self::$l2]]);
		$this->assertAllocations($from, ['A' => -2], 'transfer_from');
		$this->assertAllocations($to, ['A' => 2], 'transfer_to');
		$tag = self::$db->query("SELECT stock_id FROM stock WHERE id = $source")->fetchColumn();
		self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM stock WHERE stock_id = '$tag'")->fetchColumn(), 'Both rows carry one stock_id');

		$this->buy($p, 4, 'C');
		self::merge($p);
		$this->assertRows($p, [[2, ['A' => 2]], [7, ['A' => 1, 'B' => 2, 'C' => 4]]], 'purchase C and merge again; the L2 row is untouched',
			[['location_id' => (string)self::$l2], ['location_id' => (string)self::$l1]]);

		self::$stock->UndoTransaction($transfer);
		$this->assertRows($p, [[2, ['A' => 2]], [7, ['A' => 1, 'B' => 2, 'C' => 4]]], 'undo the transfer: the L1 source row returns under its own id',
			[['id' => (string)$source, 'location_id' => (string)self::$l1], ['location_id' => (string)self::$l1]]);
		self::assertUndone([$from, $to], 1, 'undo the transfer');

		self::merge($p);
		$this->assertRows($p, [[9, ['A' => 3, 'B' => 2, 'C' => 4]]], 'a third merge');

		self::undo($a);
		$this->assertRows($p, [[6, ['B' => 2, 'C' => 4]]], 'undo purchase A');
	}

	// ------------------------------------------------------------------------------
	// Example 4: opening and editing, then merge, then reversal
	// ------------------------------------------------------------------------------

	public function testExample4TwoWholeRowOpeningsMergedThenReversed(): void
	{
		$p = self::product('4a');
		$this->buy($p, 2, 'A');
		$this->buy($p, 3, 'B');
		[$rowA, $rowB] = self::rowIds($p);
		$tagA = self::$db->query("SELECT stock_id FROM stock WHERE id = $rowA")->fetchColumn();
		$tagB = self::$db->query("SELECT stock_id FROM stock WHERE id = $rowB")->fetchColumn();
		$openA = self::bookingsOf(self::$stock->OpenProduct($p, 2, $tagA))[0];
		$openB = self::bookingsOf(self::$stock->OpenProduct($p, 3, $tagB))[0];
		$this->assertAllocations($openA, ['A' => 2], 'open A whole');
		$this->assertAllocations($openB, ['B' => 3], 'open B whole');

		self::merge($p);
		$this->assertRows($p, [[5, ['A' => 2, 'B' => 3]]], 'merged opened row', [['open' => 1]]);

		self::undo($openB);
		$this->assertRows($p, [[2, ['A' => 2]], [3, ['B' => 3]]], 'undo the second opening', [['open' => 1], ['open' => 0]]);
		self::undo($openA);
		$this->assertRows($p, [[2, ['A' => 2]], [3, ['B' => 3]]], 'undo the first opening', [['open' => 0], ['open' => 0]]);
		self::assertUndone([$openA, $openB], 1, 'both openings undone');
	}

	public function testExample4SplitOpeningThenMergeOfTheRemainder(): void
	{
		$p = self::product('4b');
		$this->buy($p, 2, 'A');
		$this->buy($p, 3, 'B');
		self::merge($p);
		$open = self::bookingsOf(self::$stock->OpenProduct($p, 2))[0];
		$this->assertAllocations($open, ['A' => 2], 'open 2 (a split)');
		$this->buy($p, 4, 'C');
		self::merge($p);
		$this->assertRows($p, [[2, ['A' => 2]], [7, ['B' => 3, 'C' => 4]]], 'the remainder merged with C', [['open' => 1], ['open' => 0]]);

		self::undo($open);
		$this->assertRows($p, [[2, ['A' => 2]], [7, ['B' => 3, 'C' => 4]]], 'undo the opening in place', [['open' => 0], ['open' => 0]]);
	}

	public function testExample4PriceEditThatEnablesAMergeThenReversed(): void
	{
		$p = self::product('4c');
		$this->buy($p, 3, 'A', 1.0);
		$this->buy($p, 2, 'B', 1.2);
		[, $rowB] = self::rowIds($p);
		$keep = StockService::KeepStoredValue();
		$edit = self::$stock->EditStockEntry($rowB, 2, $keep, $keep, $keep, 1.0, $keep, $keep);
		[$old, $new] = self::bookingsOf($edit);
		$this->assertAllocations($old, ['B' => 2], 'edit: the old snapshot');
		$this->assertAllocations($new, ['B' => 2], 'edit: the new snapshot');

		self::merge($p);
		$this->assertRows($p, [[5, ['A' => 3, 'B' => 2]]], 'merged at price 1.0', [['price' => '1']]);

		self::undo($new);
		$this->assertRows($p, [[3, ['A' => 3]], [2, ['B' => 2]]], 'undo the edit', [['price' => '1'], ['price' => '1.2']]);
		self::assertUndone([$old, $new], 1, 'undo the edit');
	}

	public function testExample4AmountEditCreatesALotThatTheUndoRemoves(): void
	{
		$p = self::product('4d');
		$this->buy($p, 3, 'A');
		$this->buy($p, 2, 'B');
		[, $rowB] = self::rowIds($p);
		$keep = StockService::KeepStoredValue();
		$edit = self::$stock->EditStockEntry($rowB, 2.5, $keep, $keep, $keep, $keep, $keep, $keep);
		[$old, $new] = self::bookingsOf($edit);
		$this->names[$new] = 'E';
		$this->assertAllocations($old, ['B' => 2], 'edit up: the old snapshot');
		$this->assertAllocations($new, ['B' => 2, 'E' => 0.5], 'edit up: the new snapshot, with the new lot E');

		self::merge($p);
		$this->assertRows($p, [[5.5, ['A' => 3, 'B' => 2, 'E' => 0.5]]], 'merged');

		self::undo($old);
		$this->assertRows($p, [[3, ['A' => 3]], [2, ['B' => 2]]], 'undo the edit: B returns on its own row and E is gone');
		self::assertUndone([$old, $new], 1, 'undo the edit');
	}

	// ------------------------------------------------------------------------------
	// Example 5: fully consumed stock
	// ------------------------------------------------------------------------------

	public function testExample5FullyConsumedMergedRowReturnsUnderItsOldId(): void
	{
		$p = self::product('5');
		$this->buy($p, 3, 'A');
		$this->buy($p, 2, 'B');
		self::merge($p);
		[$merged] = self::rowIds($p);
		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 5, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [], 'consume 5');
		$this->assertAllocations($consume, ['A' => -3, 'B' => -2], 'consume 5');

		self::undo($consume);
		$this->assertRows($p, [[5, ['A' => 3, 'B' => 2]]], 'undo the consume', [['id' => (string)$merged]]);
	}

	// ------------------------------------------------------------------------------
	// Example 6: ambiguous legacy history (history written by the pre-0304 service)
	// ------------------------------------------------------------------------------

	public function testExample6UnknownLegacyFamily(): void
	{
		$p = (int)self::$fixture['scenarios']['U_merged_consumed'];
		[$a, $b] = array_map('intval', self::$db->query("SELECT id FROM stock_log WHERE product_id = $p AND transaction_type = 'purchase' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
		$this->names = [$a => 'A', $b => 'B'];
		$this->assertRows($p, [[4, ['pool' => 4]]], 'class U after the backfill');
		$this->assertAllocations($a, ['A' => 3], 'A has an unknown self-allocation');
		self::assertSame('unknown', self::$db->query("SELECT basis FROM stock_booking_lots WHERE booking_id = $a")->fetchColumn());

		self::refused($p, fn() => self::undo($a), 'undo A');
		self::refused($p, fn() => self::undo($b), 'undo B');

		$consume = self::bookingsOf(self::$stock->ConsumeProduct($p, 1, false, StockService::TRANSACTION_TYPE_CONSUME))[0];
		$this->assertRows($p, [[3, ['pool' => 3]]], 'a new consume of 1');
		$this->assertAllocations($consume, ['pool' => -1], 'the consume draws on the pool');
		self::undo($consume);
		$this->assertRows($p, [[3, ['pool' => 3]], [1, ['pool' => 1]]], 'its undo returns the unit to the pool');

		$row = self::$db->query("SELECT * FROM stock WHERE product_id = $p ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		$c = $this->buy($p, 4, 'C', (float)$row['price'], (int)$row['location_id']);
		self::$db->exec("UPDATE stock SET purchased_date = '" . $row['purchased_date'] . "' WHERE id = (SELECT max(id) FROM stock WHERE product_id = $p)");
		self::merge($p);
		$this->assertRows($p, [[8, ['pool' => 4, 'C' => 4]]], 'a new purchase C merges with the legacy row');

		self::undo($c);
		$this->assertRows($p, [[4, ['pool' => 4]]], 'undo C');
	}

	public function testExample6ExactByArithmeticLegacyFamily(): void
	{
		$p = (int)self::$fixture['scenarios']['X_merged'];
		[$a, $b] = array_map('intval', self::$db->query("SELECT id FROM stock_log WHERE product_id = $p AND transaction_type = 'purchase' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
		$this->names = [$a => 'A', $b => 'B'];
		$this->assertRows($p, [[5, ['A' => 3, 'B' => 2]]], 'class X after the backfill');

		self::undo($a);
		$this->assertRows($p, [[2, ['B' => 2]]], 'undo the older purchase');
	}
}
