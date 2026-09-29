<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #487 M20 (#520): "Ledger assertions exist ... The missing general invariant
 * runner is real" (#487's own correction 1, citing StockCoverageTest.php:2217-2219's
 * live-booking-sum-equals-surviving-amount check for one undo scenario). This file is
 * that general runner: a fixed-seed, deterministic sequence of real StockService
 * operations - purchase, consume (default, partial and named cross-entry), open (plain
 * and measured), transfer, inventory correction, edit and undo - across two products
 * (one plain, one exercised through ADR-0022 measured/opened entries) and two
 * locations, asserting ledger invariants after every single step rather than only at
 * the end.
 *
 * Scope decision on "undo restores prior state": C1 (#488) and M22 (#522) are open
 * findings that an undo does not always reconstruct byte-identical rows once
 * CompactStockEntries() or a whole-row transfer optimisation has touched the entry -
 * that is not a settled universal contract, so this file does not assert it as one.
 * What IS a settled contract, and what this file asserts after every accepted or
 * refused undo in the seeded sequence, is the ledger-balance invariant below (the
 * generalised form of the existing targeted assertion). A separate, narrow,
 * non-randomised test (testUndoOfAnIsolatedPurchaseRestoresExactPriorLedgerState)
 * demonstrates the strict byte-identical case: an undo of a purchase that never merged
 * with anything else does reconstruct the prior ledger exactly, column for column.
 *
 * Ledger-balance invariant (derived from services/StockService.php's own booking code,
 * not asserted by assumption):
 * - Every accepted booking either inserts/updates a `stock` row by exactly the amount
 *   it logs to `stock_log` (PURCHASE, SELF_PRODUCTION positive INVENTORY_CORRECTION,
 *   CONSUME, negative INVENTORY_CORRECTION), or moves an amount between two `stock`
 *   rows under a correlated TRANSFER_FROM/TRANSFER_TO pair that nets to zero at the
 *   product level, or leaves `stock.amount` untouched entirely (PRODUCT_OPENED, whose
 *   booking amount records what was opened without moving stock; a plain due-date/price
 *   EDIT that does not change amount).
 * - UndoBooking() (services/StockService.php:3290) reverses exactly the effect its
 *   forward booking had and then marks that stock_log row `undone = 1`; it refuses
 *   (leaving every row byte-for-byte unchanged) rather than guess when the reversal
 *   would be ambiguous (StockUndoIntegrityTest.php already covers those refusal
 *   scenarios individually).
 * - Consequently, for every `stock_id` that has ever appeared in `stock_log`, at every
 *   point in time: SUM(stock.amount WHERE stock_id = ?) equals
 *   SUM(stock_log.amount WHERE stock_id = ? AND undone = 0), compared within
 *   StockService::CompareAmounts()'s ADR-0032 tolerance. That is the invariant this
 *   file checks after every step, generalised from the one instance
 *   StockCoverageTest.php's testUndoingTheLaterOfTwoCompactedPurchasesLeavesTheEarlierOnesUnits()
 *   already pins for a single scripted scenario.
 */
class CrossOperationLedgerInvariantTest extends PgsqlSchemaTestCase
{
	/** Deterministic seed for the whole sequence - printed on every failure. */
	private const SEED = 20260929;

	/** Number of scripted operations the seeded sequence runs. Kept small so the phase stays well under a minute. */
	private const SCRIPT = [
		'purchase', 'purchase', 'purchase',
		'consume', 'consume_partial', 'consume_cross_entry',
		'open', 'open_measured',
		'transfer', 'transfer',
		'inventory_up', 'inventory_down',
		'edit',
		'undo', 'undo', 'undo', 'undo',
	];

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $locationA;
	private static int $locationB;
	private static int $plainProduct;
	private static int $measuredProduct;

	/** ADR-0032's tolerance comparator, exposed as StockService::CompareAmounts(). */
	private static function amountsEqual(float $a, float $b): bool
	{
		return StockService::CompareAmounts($a, $b) === 0;
	}

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'ledgerinvariant-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Ledger Invariant A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Ledger Invariant B']);
		self::$locationB = (int)$location->fetchColumn();

		// qu_id 2 mirrors StockUndoIntegrityTest.php's own fixtures: a seeded quantity
		// unit (migrations/0006.sql) used identically as purchase/stock/consume/price and
		// as the measurement unit, so no quantity-unit-conversion arithmetic complicates
		// the invariant math this file is checking.
		$product = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$product->execute(['Ledger Invariant Plain', self::$locationA]);
		self::$plainProduct = (int)$product->fetchColumn();
		$product->execute(['Ledger Invariant Measured', self::$locationA]);
		self::$measuredProduct = (int)$product->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	/** Calls $work, recovering a thrown HttpException into a status/body pair, without asserting either. */
	private function attempt(callable $work): array
	{
		try
		{
			$response = $work();
			return ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true)];
		}
		catch (HttpException $exception)
		{
			return ['status' => $exception->getCode(), 'body' => $exception->getMessage()];
		}
	}

	/** Every column of every `stock` and `stock_log` row, in id order, as JSON. */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	private static function stockRows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function liveStockIds(): array
	{
		return self::$db->query('SELECT DISTINCT stock_id FROM stock_log UNION SELECT DISTINCT stock_id FROM stock')->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * The ledger-balance invariant documented in the class docblock, checked for every
	 * `stock_id` that exists anywhere in the fixture, not only the ones the current step
	 * touched - a defect that corrupts an *unrelated* row would otherwise slip past.
	 */
	private function assertLedgerBalance(string $context): void
	{
		$liveSum = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock_log WHERE stock_id = ? AND undone = 0');
		$stockSum = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE stock_id = ?');

		foreach (self::liveStockIds() as $stockId)
		{
			$liveSum->execute([$stockId]);
			$expected = (float)$liveSum->fetchColumn();
			$stockSum->execute([$stockId]);
			$actual = (float)$stockSum->fetchColumn();

			self::assertTrue(
				self::amountsEqual($expected, $actual),
				"$context: stock_id $stockId - live stock_log amount sum ($expected) must equal the surviving stock row amount sum ($actual)"
			);
		}
	}

	/** No `stock` row is ever negative, even transiently, within ADR-0032's tolerance. */
	private function assertNoNegativeStock(string $context): void
	{
		$rows = self::$db->query('SELECT id, product_id, amount FROM stock')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row)
		{
			self::assertTrue(
				StockService::CompareAmounts((float)$row['amount'], 0) >= 0,
				"$context: stock row {$row['id']} (product {$row['product_id']}) is negative: {$row['amount']}"
			);
		}
	}

	/**
	 * `stock_current` (db/pgsql/baseline/04_views_l1a.sql) is a UNION over `stock`,
	 * HAVING SUM(amount) > 0 - so a product with zero or negative net stock has no row
	 * there at all, which this asserts as the expected shape rather than treating the
	 * row's absence as a failure.
	 */
	private function assertStockCurrentAgreesWithStock(string $context, int $productId): void
	{
		$expected = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$expected->execute([$productId]);
		$expectedAmount = (float)$expected->fetchColumn();

		$current = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock_current WHERE product_id = ?');
		$current->execute([$productId]);
		$hasRow = $current->rowCount() > 0;
		$currentAmount = (float)$current->fetchColumn();

		if (StockService::CompareAmounts($expectedAmount, 0) <= 0)
		{
			self::assertTrue(
				!$hasRow || self::amountsEqual($currentAmount, 0),
				"$context: product $productId has no positive net stock ($expectedAmount) so stock_current must carry no positive amount for it"
			);
		}
		else
		{
			self::assertTrue(
				self::amountsEqual($currentAmount, $expectedAmount),
				"$context: product $productId - stock_current.amount ($currentAmount) must equal SUM(stock.amount) ($expectedAmount)"
			);
		}
	}

	/**
	 * cache__products_average_price and cache__products_last_purchased
	 * (db/pgsql/baseline/06_triggers_b.sql) are maintained by AFTER INSERT/UPDATE
	 * triggers on stock_log that copy from the products_average_price /
	 * products_last_purchased views for the affected product. The "recomputation" this
	 * asserts is exactly that source view, queried fresh, compared to the cache row a
	 * trigger already wrote - if a booking path ever bypasses stock_log (or the trigger
	 * wiring breaks), the two diverge.
	 */
	private function assertPriceAndLastPurchasedCachesAgreeWithViews(string $context, int $productId): void
	{
		$viewPrice = self::$db->prepare('SELECT price FROM products_average_price WHERE product_id = ?');
		$viewPrice->execute([$productId]);
		$expectedPrice = $viewPrice->fetchColumn();

		$cachePrice = self::$db->prepare('SELECT price FROM cache__products_average_price WHERE product_id = ?');
		$cachePrice->execute([$productId]);
		$actualPrice = $cachePrice->fetchColumn();

		self::assertSame(
			$expectedPrice === false ? null : $expectedPrice,
			$actualPrice === false ? null : $actualPrice,
			"$context: product $productId - cache__products_average_price.price must agree with products_average_price"
		);

		$viewLast = self::$db->prepare('SELECT amount, best_before_date, purchased_date, price, location_id, shopping_location_id FROM products_last_purchased WHERE product_id = ?');
		$viewLast->execute([$productId]);
		$expectedLast = $viewLast->fetch(PDO::FETCH_ASSOC);

		$cacheLast = self::$db->prepare('SELECT amount, best_before_date, purchased_date, price, location_id, shopping_location_id FROM cache__products_last_purchased WHERE product_id = ?');
		$cacheLast->execute([$productId]);
		$actualLast = $cacheLast->fetch(PDO::FETCH_ASSOC);

		self::assertSame(
			$expectedLast === false ? null : $expectedLast,
			$actualLast === false ? null : $actualLast,
			"$context: product $productId - cache__products_last_purchased must agree with products_last_purchased"
		);
	}

	/**
	 * ADR-0022 / migrations/0275.pgsql.sql's stock_measurement_coherence_check: any row
	 * carrying a measurement (opened_amount IS NOT NULL) must have amount = 1 and
	 * open = 1. The database CHECK constraint already enforces this on write, so this is
	 * a property assertion confirming the constraint is doing its job across the whole
	 * sequence, not a substitute for it.
	 */
	private function assertMeasuredCoherence(string $context): void
	{
		$rows = self::$db->query('SELECT id, amount, open FROM stock WHERE opened_amount IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row)
		{
			self::assertTrue(self::amountsEqual((float)$row['amount'], 1.0), "$context: measured stock row {$row['id']} must hold amount = 1, holds {$row['amount']}");
			self::assertSame(1, (int)$row['open'], "$context: measured stock row {$row['id']} must be open");
		}
	}

	private function assertAllInvariants(string $context): void
	{
		$this->assertLedgerBalance($context);
		$this->assertNoNegativeStock($context);
		$this->assertStockCurrentAgreesWithStock($context, self::$plainProduct);
		$this->assertStockCurrentAgreesWithStock($context, self::$measuredProduct);
		$this->assertPriceAndLastPurchasedCachesAgreeWithViews($context, self::$plainProduct);
		$this->assertPriceAndLastPurchasedCachesAgreeWithViews($context, self::$measuredProduct);
		$this->assertMeasuredCoherence($context);
	}

	// ------------------------------------------------------------------------------
	// The seeded sequence
	// ------------------------------------------------------------------------------

	/**
	 * Fisher-Yates over self::SCRIPT, seeded with mt_srand(self::SEED) so the exact
	 * operation order (and every mt_rand()-derived amount/location/target picked while
	 * running it) is reproducible from the seed alone - a failure reports "seed=... step="
	 * and the same seed replays the identical sequence.
	 */
	private static function shuffledScript(): array
	{
		$script = self::SCRIPT;
		for ($i = count($script) - 1; $i > 0; $i--)
		{
			$j = mt_rand(0, $i);
			[$script[$i], $script[$j]] = [$script[$j], $script[$i]];
		}
		return $script;
	}

	public function testSeededCrossOperationSequenceMaintainsLedgerInvariants(): void
	{
		mt_srand(self::SEED);
		$script = self::shuffledScript();

		$this->assertAllInvariants('seed=' . self::SEED . ' step=0 (fixtures created, before any operation)');

		foreach ($script as $index => $op)
		{
			$step = $index + 1;
			$context = 'seed=' . self::SEED . " step=$step op=$op";

			$before = self::ledger();
			$result = $this->runScriptedOperation($op, $context);

			if ($result['status'] >= 400)
			{
				self::assertSame(
					$before,
					self::ledger(),
					"$context: a refused operation (status {$result['status']}) must leave stock and stock_log unchanged"
				);
			}

			$this->assertAllInvariants($context);
		}
	}

	/**
	 * Runs one scripted operation, picking its concrete parameters from mt_rand() (so
	 * they are reproducible from self::SEED too) and returns ['status' => ..., 'body' => ...].
	 * A refusal (status >= 400, including a thrown HttpException recovered by attempt())
	 * is an accepted outcome for every op here except the fixture-independent ones -
	 * the caller checks the ledger stayed untouched, not that the call always succeeds.
	 */
	private function runScriptedOperation(string $op, string $context): array
	{
		$locations = [self::$locationA, self::$locationB];
		$location = $locations[mt_rand(0, 1)];
		$otherLocation = $location === self::$locationA ? self::$locationB : self::$locationA;

		switch ($op)
		{
			case 'purchase':
				$product = mt_rand(0, 1) === 0 ? self::$plainProduct : self::$measuredProduct;
				$amount = self::$plainProduct === $product ? (float)mt_rand(1, 5) : 1.0;
				$due = sprintf('20%02d-%02d-%02d', mt_rand(30, 39), mt_rand(1, 12), mt_rand(1, 28));
				return $this->attempt(fn() => self::$stock->AddProduct(self::request('POST', [
					'amount' => $amount,
					'best_before_date' => $due,
					'purchased_date' => '2026-01-01',
					'price' => round(mt_rand(50, 500) / 100, 2),
					'location_id' => $location,
				]), new Response(), ['productId' => $product]));

			case 'consume':
				$product = self::pickProductWithStock();
				$available = $this->productStockAmount($product);
				$amount = $available > 0 ? max(0.1, round($available * (mt_rand(10, 60) / 100), 2)) : (float)mt_rand(1, 3);
				return $this->attempt(fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => $amount]), new Response(), ['productId' => $product]));

			case 'consume_partial':
				// A cross-entry partial consume: name one specific stock entry (rather
				// than 'default' FIFO order) and ask for less than that entry holds, so
				// ConsumeProduct() must split it rather than take it whole.
				$product = self::pickProductWithStock();
				$rows = self::stockRows($product);
				if (empty($rows))
				{
					return ['status' => 200, 'body' => []];
				}
				$row = $rows[mt_rand(0, count($rows) - 1)];
				$amount = max(0.1, round(((float)$row['amount']) * 0.4, 2));
				return $this->attempt(fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => $amount, 'stock_entry_id' => $row['stock_id']]), new Response(), ['productId' => $product]));

			case 'consume_cross_entry':
				// Names a specific stock entry and asks to consume more than that one
				// entry alone holds is refused by design (ConsumeProduct() scopes
				// availability to the named entry) - exercised here as "cross entry" in
				// the sense of naming one entry among several live ones for the product,
				// which is exactly the wire-level `stock_entry_id` field's job.
				$product = self::pickProductWithStock();
				$rows = self::stockRows($product);
				if (empty($rows))
				{
					return ['status' => 200, 'body' => []];
				}
				$row = $rows[mt_rand(0, count($rows) - 1)];
				$amount = max(0.1, round(((float)$row['amount']) * 0.9, 2));
				return $this->attempt(fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => $amount, 'stock_entry_id' => $row['stock_id'], 'exact_amount' => true]), new Response(), ['productId' => $product]));

			case 'open':
				$product = self::$plainProduct;
				return $this->attempt(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => (float)mt_rand(1, 2)]), new Response(), ['productId' => $product]));

			case 'open_measured':
				// ADR-0022: a measurement requires naming one single-unit entry and
				// opening exactly amount = 1 of it.
				$rows = self::stockRows(self::$measuredProduct);
				$candidates = array_values(array_filter($rows, fn($row) => (int)$row['open'] === 0 && StockService::CompareAmounts((float)$row['amount'], 1.0) >= 0));
				if (empty($candidates))
				{
					return ['status' => 200, 'body' => []];
				}
				$row = $candidates[mt_rand(0, count($candidates) - 1)];
				return $this->attempt(fn() => self::$stock->OpenProduct(self::request('POST', [
					'amount' => 1,
					'stock_entry_id' => $row['stock_id'],
					'measurement' => ['amount' => round(mt_rand(20, 80) / 100, 2), 'qu_id' => 2],
				]), new Response(), ['productId' => self::$measuredProduct]));

			case 'transfer':
				$product = self::pickProductWithStock();
				$amount = max(0.1, round($this->productStockAmount($product) * 0.3, 2));
				return $this->attempt(fn() => self::$stock->TransferProduct(self::request('POST', [
					'amount' => $amount,
					'location_id_from' => $location,
					'location_id_to' => $otherLocation,
				]), new Response(), ['productId' => $product]));

			case 'inventory_up':
				$product = self::pickProductWithStock();
				$newAmount = $this->productStockAmount($product) + mt_rand(1, 4);
				return $this->attempt(fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => $newAmount, 'location_id' => $location]), new Response(), ['productId' => $product]));

			case 'inventory_down':
				$product = self::pickProductWithStock();
				$newAmount = max(0.0, $this->productStockAmount($product) - mt_rand(1, 3));
				return $this->attempt(fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => $newAmount]), new Response(), ['productId' => $product]));

			case 'edit':
				$product = self::pickProductWithStock();
				$rows = self::stockRows($product);
				if (empty($rows))
				{
					return ['status' => 200, 'body' => []];
				}
				$row = $rows[mt_rand(0, count($rows) - 1)];
				return $this->attempt(fn() => self::$stock->EditStockEntry(self::request('PUT', [
					'amount' => $row['amount'],
					'best_before_date' => $row['best_before_date'] ?? '2030-01-01',
					'open' => (bool)$row['open'],
					'purchased_date' => $row['purchased_date'] ?? '2026-01-01',
					'price' => round(mt_rand(50, 500) / 100, 2),
					'location_id' => $row['location_id'] ?? $location,
					'note' => 'seeded edit ' . $context,
				]), new Response(), ['entryId' => $row['id']]));

			case 'undo':
				$live = self::$db->query('SELECT id FROM stock_log WHERE undone = 0 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
				if (empty($live))
				{
					return ['status' => 200, 'body' => []];
				}
				$bookingId = $live[mt_rand(0, count($live) - 1)];
				return $this->attempt(fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $bookingId]));

			default:
				self::fail("$context: unknown scripted operation $op");
		}
	}

	private static function pickProductWithStock(): int
	{
		$plainHasStock = self::stockRows(self::$plainProduct) !== [];
		$measuredHasStock = self::stockRows(self::$measuredProduct) !== [];

		if ($plainHasStock && $measuredHasStock)
		{
			return mt_rand(0, 1) === 0 ? self::$plainProduct : self::$measuredProduct;
		}
		if ($plainHasStock)
		{
			return self::$plainProduct;
		}
		if ($measuredHasStock)
		{
			return self::$measuredProduct;
		}
		return self::$plainProduct;
	}

	private function productStockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		return (float)$statement->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Strict "undo restores the exact prior state" case
	// ------------------------------------------------------------------------------

	/**
	 * The strict, byte-identical form of "undo restores prior state", isolated from the
	 * seeded sequence above (its own product/location, so nothing else can compact or
	 * touch the same stock_id) precisely because that strict form is NOT a general
	 * contract - see the class docblock. A purchase that never merges with anything else
	 * is the one case the codebase does guarantee reverses exactly: UndoBooking()'s
	 * PURCHASE branch (services/StockService.php:3362) finds exactly one matching stock
	 * row, computes newAmount = totalAmount - logRow.amount, and - here, since
	 * totalAmount == logRow.amount for an unmerged single purchase - deletes it, per
	 * CompareAmounts(totalAmount, logRow.amount) == 0.
	 */
	public function testUndoOfAnIsolatedPurchaseRestoresExactPriorLedgerState(): void
	{
		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Ledger Invariant Isolated']);
		$isolatedLocation = (int)$location->fetchColumn();

		$product = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$product->execute(['Ledger Invariant Isolated Purchase', $isolatedLocation]);
		$isolatedProduct = (int)$product->fetchColumn();

		$beforePurchase = self::ledger();

		$booking = $this->attempt(fn() => self::$stock->AddProduct(self::request('POST', [
			'amount' => 3,
			'best_before_date' => '2031-05-17',
			'purchased_date' => '2026-01-01',
			'price' => 2.5,
			'location_id' => $isolatedLocation,
		]), new Response(), ['productId' => $isolatedProduct]));
		self::assertSame(200, $booking['status'], 'Sanity: the isolated purchase is accepted');
		$bookingId = (int)$booking['body'][0]['id'];

		$rowsAfterPurchase = self::stockRows($isolatedProduct);
		self::assertCount(1, $rowsAfterPurchase, 'Sanity: exactly one stock row from the purchase, nothing to merge with');

		$undo = $this->attempt(fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $bookingId]));
		self::assertSame(204, $undo['status'], 'The undo of an unmerged purchase is accepted');

		self::assertSame([], self::stockRows($isolatedProduct), 'The stock row the purchase created is gone - stock is back to its pre-purchase state');

		$logRow = self::$db->prepare('SELECT * FROM stock_log WHERE id = ?');
		$logRow->execute([$bookingId]);
		$logRow = $logRow->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1, (int)$logRow['undone'], 'The booking itself is marked undone');
		self::assertNotNull($logRow['undone_timestamp']);

		// Byte-identical prior state: every OTHER row anywhere in the ledger (not
		// created by this purchase) must be exactly what it was before the purchase ran.
		$afterUndo = json_decode(self::ledger(), true);
		$afterUndo['stock_log'] = array_values(array_filter($afterUndo['stock_log'], fn($row) => (int)$row['id'] !== $bookingId));
		self::assertSame(json_decode($beforePurchase, true), $afterUndo, 'Every row outside the undone booking itself is untouched by the purchase/undo round trip');
	}
}
