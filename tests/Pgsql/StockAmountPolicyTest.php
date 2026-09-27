<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/** ADR-0032: real stock and ledger results under the shared amount policy. */
class StockAmountPolicyTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;
	private static int $source;
	private static int $destination;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
		self::$stock = StockService::GetInstance();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'amount-policy', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$source = self::insert('locations', ['name' => 'Amount policy source']);
		self::$destination = self::insert('locations', ['name' => 'Amount policy destination']);
	}

	private static function insert(string $table, array $values): int
	{
		$statement = self::$db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ') RETURNING id');
		$statement->execute(array_values($values));
		return (int)$statement->fetchColumn();
	}

	private static function product(): int
	{
		return self::insert('products', ['name' => uniqid('amount-policy-'), 'location_id' => self::$source, 'qu_id_stock' => 2, 'qu_id_purchase' => 2, 'qu_id_consume' => 2, 'qu_id_price' => 2]);
	}

	private static function row(int $product, float $amount, int $open = 0): array
	{
		$id = self::insert('stock', ['product_id' => $product, 'amount' => sprintf('%.17g', $amount), 'stock_id' => uniqid('policy-'), 'location_id' => self::$source, 'open' => $open, 'best_before_date' => '2035-01-01', 'purchased_date' => '2026-09-27']);
		return self::$db->query('SELECT * FROM stock WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
	}

	private static function snapshot(int $product): array
	{
		return [
			self::$db->query('SELECT * FROM stock WHERE product_id = ' . $product . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			self::$db->query('SELECT * FROM stock_log WHERE product_id = ' . $product . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	private static function total(int $product): float
	{
		return (float)self::$db->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $product)->fetchColumn();
	}

	private static function book(string $operation, int $product, float $amount, ?string &$transactionId = null): void
	{
		match ($operation)
		{
			'consume' => self::$stock->ConsumeProduct($product, $amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId),
			'open' => self::$stock->OpenProduct($product, $amount, 'default', $transactionId),
			'transfer' => self::$stock->TransferProduct($product, $amount, self::$source, self::$destination, 'default', $transactionId),
		};
	}

	private static function edit(array $row, float $amount): void
	{
		self::$stock->EditStockEntry((int)$row['id'], $amount, $row['best_before_date'], $row['location_id'], null, null, $row['open'], $row['purchased_date']);
	}

	private static function refuse(int $product, callable $action): string
	{
		$before = self::snapshot($product);
		$caught = null;
		try
		{
			$action();
		}
		catch (\Exception $exception)
		{
			$caught = $exception;
		}
		self::assertNotNull($caught, 'The service must refuse the request');
		self::assertNotInstanceOf(\PDOException::class, $caught, 'Validation must precede SQL constraint failure');
		self::assertSame($before, self::snapshot($product), 'Refusal must preserve every stock and ledger column');
		return $caught->getMessage();
	}

	private static function undo(int $product): void
	{
		$ids = self::$db->query('SELECT id FROM stock_log WHERE product_id = ' . $product . ' AND undone = 0 ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
		foreach ($ids as $id)
		{
			if (self::$db->query('SELECT undone FROM stock_log WHERE id = ' . (int)$id)->fetchColumn() == 0)
			{
				self::$stock->UndoBooking((int)$id);
			}
		}
	}

	public static function operations(): array
	{
		return [['consume'], ['open'], ['transfer']];
	}

	#[DataProvider('operations')]
	public function testFractionalEntriesBookActualAmountsAndUndo(string $operation): void
	{
		$product = self::product();
		self::row($product, 0.1);
		self::row($product, 0.2);
		self::book($operation, $product, 0.3);
		[$rows, $logs] = self::snapshot($product);
		self::assertCount($operation === 'transfer' ? 4 : 2, $logs);
		$amounts = array_map(fn($log) => abs((float)$log['amount']), $logs);
		sort($amounts);
		self::assertSame($operation === 'transfer' ? [0.1, 0.1, 0.2, 0.2] : [0.1, 0.2], $amounts);
		if ($operation === 'consume')
		{
			self::assertSame([], $rows);
		}
		foreach ($rows as $row)
		{
			self::assertSame($operation === 'open' ? 1 : 0, (int)$row['open']);
			self::assertSame($operation === 'transfer' ? self::$destination : self::$source, (int)$row['location_id']);
		}
		self::undo($product);
		self::assertEqualsWithDelta(0.3, self::total($product), 1e-15);
		foreach (self::snapshot($product)[0] as $row)
		{
			self::assertSame(0, (int)$row['open']);
			self::assertSame(self::$source, (int)$row['location_id']);
		}
	}

	#[DataProvider('operations')]
	public function testInitialTinyRequestsAreRefusedBeforeAssigningATransaction(string $operation): void
	{
		$product = self::product();
		self::row($product, 1);
		foreach ([0.0, 0.5e-9, 1e-9] as $amount)
		{
			$transactionId = null;
			$message = self::refuse($product, function () use ($operation, $product, $amount, &$transactionId) {
				self::book($operation, $product, $amount, $transactionId);
			});
			self::assertSame('Amount must be greater than ' . StockService::AMOUNT_TOLERANCE, $message);
			self::assertNull($transactionId);
		}
		self::book($operation, $product, 2e-9);
		$logs = self::snapshot($product)[1];
		self::assertCount($operation === 'transfer' ? 2 : 1, $logs);
		foreach ($logs as $log)
		{
			self::assertSame(2e-9, abs((float)$log['amount']));
		}
	}

	#[DataProvider('operations')]
	public function testTinyApiRequestsReturn400WithAnUntouchedLedger(string $operation): void
	{
		$product = self::product();
		self::row($product, 1);
		$before = self::snapshot($product);
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/products/' . $product . '/' . $operation)
			->withHeader('Content-Type', 'application/json')
			->withParsedBody(['amount' => 1e-9, 'location_id_from' => self::$source, 'location_id_to' => self::$destination]);
		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		$controller = new StockApiController($container);
		$method = ['consume' => 'ConsumeProduct', 'open' => 'OpenProduct', 'transfer' => 'TransferProduct'][$operation];
		try
		{
			$response = $controller->$method($request, new Response(), ['productId' => $product]);
			$status = $response->getStatusCode();
			$error = (string)$response->getBody();
		}
		catch (\Slim\Exception\HttpException $exception)
		{
			$status = $exception->getCode();
			$error = $exception->getMessage();
		}
		self::assertSame(400, $status);
		self::assertStringContainsString('Amount must be greater than', $error);
		self::assertSame($before, self::snapshot($product));
	}

	public static function availability(): iterable
	{
		foreach (['consume', 'open', 'transfer'] as $operation)
		{
			foreach ([1.0, 1e9] as $stock)
			{
				foreach ([0.5, 2.0] as $multiple)
				{
					yield [$operation, $stock, $multiple * ($stock === 1.0 ? 1e-9 : 0.001), $multiple < 1];
				}
			}
		}
	}

	#[DataProvider('availability')]
	public function testAvailabilityUsesOperandScale(string $operation, float $stock, float $extra, bool $accepted): void
	{
		$product = self::product();
		self::row($product, $stock);
		if (!$accepted)
		{
			self::refuse($product, fn() => self::book($operation, $product, $stock + $extra));
			return;
		}
		self::book($operation, $product, $stock + $extra);
		[$rows, $logs] = self::snapshot($product);
		self::assertCount($operation === 'consume' ? 0 : 1, $rows);
		self::assertCount($operation === 'transfer' ? 2 : 1, $logs);
		foreach ($logs as $log)
		{
			self::assertSame($stock, abs((float)$log['amount']));
		}
		self::undo($product);
		self::assertSame($stock, self::total($product));
	}

	#[DataProvider('operations')]
	public function testToleratedWholeEntryDoesNotBookTheNextCandidate(string $operation): void
	{
		foreach ([1.0, 1e9] as $stock)
		{
			foreach ([-0.5, 0.5] as $multiple)
			{
				$product = self::product();
				self::row($product, $stock);
				self::row($product, $stock);
				$amount = $stock + $multiple * ($stock === 1.0 ? 1e-9 : 0.001);
				self::book($operation, $product, $amount);
				self::assertCount($operation === 'transfer' ? 2 : 1, self::snapshot($product)[1]);
				self::assertSame($operation === 'consume' ? $stock : 2 * $stock, self::total($product));
			}
		}
	}

	#[DataProvider('operations')]
	public function testGenuineSmallRemainderIsPreserved(string $operation): void
	{
		$product = self::product();
		self::row($product, 1.001);
		self::book($operation, $product, 1);
		$rows = self::snapshot($product)[0];
		$remaining = array_filter($rows, fn($row) => (int)$row['open'] === 0 && (int)$row['location_id'] === self::$source);
		self::assertCount(1, $remaining);
		self::assertEqualsWithDelta(0.001, (float)array_values($remaining)[0]['amount'], 1e-15);
	}

	public static function invalidAmounts(): iterable
	{
		foreach (['add', 'consume', 'edit', 'inventory', 'open', 'transfer'] as $operation)
		{
			foreach ([-5e-10, NAN, INF, -INF] as $amount)
			{
				yield [$operation, $amount];
			}
		}
	}

	#[DataProvider('invalidAmounts')]
	public function testInvalidAmountsAreRefusedWithoutMutation(string $operation, float $amount): void
	{
		$product = self::product();
		$row = self::row($product, 1);
		self::refuse($product, fn() => match ($operation)
		{
			'add' => self::$stock->AddProduct($product, $amount, '2035-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-27', null, self::$source),
			'edit' => self::edit($row, $amount),
			'inventory' => self::$stock->InventoryProduct($product, $amount, '2035-01-01', self::$source),
			default => self::book($operation, $product, $amount),
		});
	}

	public function testZeroEditRemainsSupported(): void
	{
		$product = self::product();
		$row = self::row($product, 1);
		self::edit($row, 0);
		self::assertCount(1, self::snapshot($product)[0]);
		self::assertSame(0.0, self::total($product));
	}

	public static function coherence(): iterable
	{
		foreach (['edit', 'measure', 'open'] as $operation)
		{
			foreach ([1.0, 1 - 0.5e-9, 1 + 0.5e-9, 0.995, 0.998, 1.002, 1.005] as $amount)
			{
				yield [$operation, $amount];
			}
		}
	}

	#[DataProvider('coherence')]
	public function testMeasuredContainerCoherenceIsExact(string $operation, float $amount): void
	{
		$product = self::product();
		$measurement = ['amount' => 0.5, 'qu_id' => 2];
		$row = self::row($product, $operation === 'edit' ? 1 : $amount, $operation === 'open' ? 0 : 1);
		if ($operation === 'edit')
		{
			self::$stock->MeasureStockEntry($row['id'], $measurement);
			self::edit($row, $amount);
			$after = self::snapshot($product)[0][0];
			foreach (['opened_amount', 'opened_qu_id', 'opened_tare', 'opened_measured_at'] as $column)
			{
				if ($amount !== 1.0)
				{
					self::assertNull($after[$column]);
				}
			}
			self::assertSame($amount === 1.0, $after['opened_amount'] !== null);
			self::assertEqualsWithDelta($amount, (float)$after['amount'], 1e-14);
		}
		elseif ($operation === 'measure')
		{
			$action = fn() => self::$stock->MeasureStockEntry($row['id'], $measurement);
			if ($amount !== 1.0)
			{
				self::refuse($product, $action);
				return;
			}
			$action();
			self::assertSame(0.5, (float)self::snapshot($product)[0][0]['opened_amount']);
		}
		else
		{
			$action = function () use ($product, $row, $measurement) {
				$transactionId = null;
				self::$stock->OpenProduct($product, 1, $row['stock_id'], $transactionId, false, $measurement);
			};
			if ($amount < 1)
			{
				self::refuse($product, $action);
				return;
			}
			$action();
			[$rows, $logs] = self::snapshot($product);
			self::assertCount($amount === 1.0 ? 1 : 2, $rows);
			$measured = array_values(array_filter($rows, fn($entry) => $entry['opened_amount'] !== null));
			self::assertCount(1, $measured);
			self::assertSame(1.0, (float)$measured[0]['amount']);
			self::assertSame(1.0, (float)$logs[0]['amount']);
			if ($amount > 1)
			{
				$remainder = array_values(array_filter($rows, fn($entry) => $entry['opened_amount'] === null));
				self::assertGreaterThan(0, (float)$remainder[0]['amount']);
				self::assertEqualsWithDelta($amount - 1, (float)$remainder[0]['amount'], 1e-15);
			}
		}
	}

	public function testMeasuredRequestsAndNonFiniteReadingsAreRefused(): void
	{
		$product = self::product();
		$row = self::row($product, 2);
		foreach ([1 - 0.5e-9, 1 + 0.5e-9] as $amount)
		{
			self::refuse($product, function () use ($product, $row, $amount) {
				$transactionId = null;
				self::$stock->OpenProduct($product, $amount, $row['stock_id'], $transactionId, false, ['amount' => 0.5, 'qu_id' => 2]);
			});
		}
		$openProduct = self::product();
		$openRow = self::row($openProduct, 1, 1);
		foreach ([NAN, INF, -INF] as $value)
		{
			foreach (['amount', 'tare'] as $column)
			{
				$measurement = ['amount' => 1, 'tare' => 0, 'qu_id' => 2, 'is_gross' => true];
				$measurement[$column] = $value;
				self::refuse($openProduct, fn() => self::$stock->MeasureStockEntry($openRow['id'], $measurement));
			}
		}
	}

	public function testInventoryEqualityAndDirection(): void
	{
		foreach ([1.0, 1e9] as $stock)
		{
			foreach ([-0.5, 0.0, 0.5] as $multiple)
			{
				$product = self::product();
				self::row($product, $stock);
				$count = $stock + $multiple * ($stock === 1.0 ? 1e-9 : 0.001);
				$message = self::refuse($product, fn() => self::$stock->InventoryProduct($product, $count, '2035-01-01', self::$source));
				self::assertSame('The new amount cannot equal the current stock amount', $message);
			}
		}
		foreach ([0.2, 0.5] as $count)
		{
			$product = self::product();
			self::row($product, 0.3);
			self::$stock->InventoryProduct($product, $count, '2035-01-01', self::$source);
			self::assertEqualsWithDelta($count, self::total($product), 1e-15);
			$logs = self::snapshot($product)[1];
			self::assertCount(1, $logs);
			self::assertEqualsWithDelta($count - 0.3, (float)$logs[0]['amount'], 1e-15);
			self::undo($product);
			self::assertEqualsWithDelta(0.3, self::total($product), 1e-15);
		}
	}

	public function testPositiveBookingUndoUsesOriginalOperandScale(): void
	{
		foreach ([StockService::TRANSACTION_TYPE_PURCHASE, StockService::TRANSACTION_TYPE_SELF_PRODUCTION, StockService::TRANSACTION_TYPE_INVENTORY_CORRECTION] as $type)
		{
			foreach ([-0.0005, 0.0005, -0.002, 0.002] as $difference)
			{
				$product = self::product();
				self::$stock->AddProduct($product, 1e9, '2035-01-01', $type, '2026-09-27', null, self::$source);
				self::$db->exec('UPDATE stock SET amount = ' . sprintf('%.17g', 1e9 + $difference) . ' WHERE product_id = ' . $product);
				if ($difference < -0.001)
				{
					self::refuse($product, fn() => self::undo($product));
				}
				else
				{
					self::undo($product);
					self::assertCount($difference > 0.001 ? 1 : 0, self::snapshot($product)[0]);
					self::assertEqualsWithDelta($difference > 0.001 ? (1e9 + $difference) - 1e9 : 0, self::total($product), 1e-12);
				}
			}
		}
	}

	public function testTransferUndoUsesOriginalOperandScale(): void
	{
		$product = self::product();
		self::row($product, 2e9);
		self::book('transfer', $product, 1e9);
		self::$db->exec('UPDATE stock SET amount = 1000000000.0005 WHERE product_id = ' . $product . ' AND location_id = ' . self::$destination);
		self::undo($product);
		self::assertCount(1, self::snapshot($product)[0]);
		self::assertSame(2e9, self::total($product));
	}

	public function testRecipeClampBooksAvailableStock(): void
	{
		foreach ([0.5e-9, 2e-9] as $shortage)
		{
			$product = self::product();
			self::row($product, 1);
			$recipe = self::insert('recipes', ['name' => uniqid('amount-recipe-')]);
			self::insert('recipes_pos', ['recipe_id' => $recipe, 'product_id' => $product, 'amount' => sprintf('%.17g', 1 + $shortage), 'qu_id' => 2]);
			RecipesService::GetInstance()->ConsumeRecipe($recipe);
			self::assertSame(0.0, self::total($product));
			self::assertSame(-1.0, (float)self::snapshot($product)[1][0]['amount']);
		}
	}

	public static function conversions(): iterable
	{
		foreach (['consume', 'open'] as $operation)
		{
			foreach ([4.0, 0.001, 10.0] as $factor)
			{
				yield [$operation, $factor];
			}
		}
	}

	#[DataProvider('conversions')]
	public function testSubstitutionComparesAmountsInTheApplicableUnit(string $operation, float $factor): void
	{
		$parent = self::product();
		$child = self::product();
		$unit = self::insert('quantity_units', ['name' => uniqid('converted-'), 'name_plural' => uniqid('converted-')]);
		self::$db->exec("UPDATE products SET parent_product_id = $parent, qu_id_stock = $unit, qu_id_purchase = $unit, qu_id_consume = $unit, qu_id_price = $unit WHERE id = $child");
		self::insert('quantity_unit_conversions', ['product_id' => $child, 'from_qu_id' => 2, 'to_qu_id' => $unit, 'factor' => $factor]);
		self::row($child, 0.1 * $factor);
		self::row($child, 0.2 * $factor);
		$transactionId = null;
		if ($operation === 'consume')
		{
			self::$stock->ConsumeProduct($parent, 0.3, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId, true);
		}
		else
		{
			self::$stock->OpenProduct($parent, 0.3, 'default', $transactionId, true);
		}
		[$rows, $logs] = self::snapshot($child);
		self::assertCount(2, $logs);
		$amounts = array_map(fn($log) => abs((float)$log['amount']), $logs);
		sort($amounts);
		self::assertEqualsWithDelta(0.1 * $factor, $amounts[0], 1e-15);
		self::assertEqualsWithDelta(0.2 * $factor, $amounts[1], 1e-15);
		if ($operation === 'consume')
		{
			self::assertSame([], $rows);
		}
		foreach ($rows as $row)
		{
			self::assertSame(1, (int)$row['open']);
		}
		self::undo($child);
		self::assertEqualsWithDelta(0.3 * $factor, self::total($child), 1e-14);
	}

	public function testMeasuredSubstitutionRefusesANonUnitConvertedRequest(): void
	{
		foreach ([4.0, 0.001] as $factor)
		{
			$parent = self::product();
			$child = self::product();
			$unit = self::insert('quantity_units', ['name' => uniqid('measured-unit-'), 'name_plural' => uniqid('measured-units-')]);
			self::$db->exec("UPDATE products SET parent_product_id = $parent, qu_id_stock = $unit, qu_id_purchase = $unit, qu_id_consume = $unit, qu_id_price = $unit WHERE id = $child");
			self::insert('quantity_unit_conversions', ['product_id' => $child, 'from_qu_id' => 2, 'to_qu_id' => $unit, 'factor' => $factor]);
			$row = self::row($child, 4);
			$message = self::refuse($child, function () use ($parent, $row) {
				$transactionId = null;
				self::$stock->OpenProduct($parent, 1, $row['stock_id'], $transactionId, true, ['amount' => 0.5, 'qu_id' => 2]);
			});
			self::assertSame('A measurement requires opening exactly one unit', $message);
		}
	}

	public function testComparisonBoundariesAndFiniteOperands(): void
	{
		foreach ([0.0, 0.5e-9, 1e-9, -1e-9] as $amount)
		{
			self::assertSame(0, StockService::CompareAmounts($amount, 0));
		}
		self::assertSame(1, StockService::CompareAmounts(2e-9, 0));
		self::assertSame(-1, StockService::CompareAmounts(-2e-9, 0));
		self::assertSame(0, StockService::CompareAmounts(1e9, 1e9 + 0.0005));
		self::assertSame(-1, StockService::CompareAmounts(1e9, 1e9 + 0.002));
		self::assertSame(1, StockService::CompareAmounts(1e9 + 0.002, 1e9));
		foreach ([NAN, INF, -INF] as $value)
		{
			foreach ([[$value, 0], [0, $value]] as [$a, $b])
			{
				try
				{
					StockService::CompareAmounts($a, $b);
					self::fail('Non-finite operands must be refused');
				}
				catch (\InvalidArgumentException $exception)
				{
					self::assertSame('Stock amounts must be finite', $exception->getMessage());
				}
			}
		}
	}

	public function testRepeatedFractionalBookingsExhaustTheExpectedBalance(): void
	{
		$precision = ini_get('precision');
		ini_set('precision', '17');
		try
		{
			foreach ([1e5, 1e6, 1e7] as $start)
			{
				$product = self::product();
				self::row($product, $start);
				for ($index = 0; $index < 1000; $index++)
				{
					self::book('consume', $product, 0.1);
				}
				$balance = self::total($product);
				self::book('consume', $product, $start - 100);
				[$rows, $logs] = self::snapshot($product);
				self::assertSame([], $rows);
				self::assertCount(1001, $logs);
				self::assertSame(-$balance, (float)$logs[1000]['amount']);
			}
		}
		finally
		{
			ini_set('precision', $precision);
		}
	}

	public function testKnownCarriedResidueLimitationRemainsVisible(): void
	{
		$product = self::product();
		self::row($product, 999999999.9 + 0.1);
		self::book('consume', $product, 999999999.9);
		self::book('consume', $product, 0.1);
		self::assertCount(1, self::snapshot($product)[0]);
		self::assertEqualsWithDelta(2.384186e-8, self::total($product), 1e-13);
	}
}
