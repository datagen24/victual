<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #502 (#487 M2): "Partial measured-container operations lose measurements or
 * violate constraints".
 *
 * A measured open container (migration 0275: `opened_amount` set, which requires
 * `open = 1 AND amount = 1` under `stock_measurement_coherence_check`) can only ever
 * hold a measurement at amount = 1 (ADR-0022 decision 8). A partial consume or
 * transfer of such an entry - one that takes less than its whole amount - leaves a
 * remainder row at an amount other than 1, so no split of a measured entry can carry
 * its measurement forward on either resulting row.
 *
 * Round 1 refused every such partial operation outright. Round 2 (maintainer decision
 * D8) narrowed that: `stock_next_use()` sorts `open DESC`, so an opened, measured
 * container is ordinarily a product's very first candidate, and refusing an ordinary
 * fractional consume/inventory-correction/recipe/chore booking just because one
 * happens to exist - even when sealed stock could cover it - is wrong. Weighed partial
 * containers belong to the tare/working-container flow; the ordinary paths must SKIP a
 * measured entry when choosing a split candidate and take from other stock instead,
 * refusing only when the measured entry is the only remaining source, or was named
 * explicitly via `stock_entry_id`.
 *
 * ConsumeProduct() and TransferProduct()'s split branches now defer a measured
 * candidate rather than splitting or refusing it immediately, and refuse - with the
 * same clean pre-mutation 400 as before, now with a message that directs the caller to
 * weigh the container instead - only once every other candidate has been exhausted and
 * some amount still remains. A whole-unit take of the measured entry (its own amount of
 * 1) is unaffected either way.
 *
 * InventoryProduct()'s downward correction and RecipesService::ConsumeRecipe()/
 * ChoresService::TrackChore() all delegate to ConsumeProduct() and are covered by the
 * same fix without any change of their own.
 */
class MeasuredContainerPartialOperationsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static RecipesApiController $recipes;
	private static int $locationA;
	private static int $locationB;
	private static int $gram;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);
		self::$recipes = new RecipesApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'measuredpartial-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Measured Partial A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Measured Partial B']);
		self::$locationB = (int)$location->fetchColumn();

		$unit = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$unit->execute(['Measured Partial Gram', 'Measured Partial Grams']);
		self::$gram = (int)$unit->fetchColumn();
	}

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private function expectStatus(callable $work, int $expected, string $message): array
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
			$body = (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
			$body = $exception->getMessage();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual ($body)");
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : ['error_message' => $body];
	}

	private static function insertProduct(string $name, int $locationId): int
	{
		$statement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
		);
		$statement->execute([$name, $locationId, self::$gram, self::$gram, self::$gram, self::$gram]);

		return (int)$statement->fetchColumn();
	}

	/** Every column of every `stock` and `stock_log` row for one product, in id order, as JSON. */
	private static function productLedger(int $productId): string
	{
		$stock = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$stock->execute([$productId]);
		$stockLog = self::$db->prepare('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id');
		$stockLog->execute([$productId]);

		return json_encode([
			'stock' => $stock->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => $stockLog->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/** Measures a freshly opened, single-unit stock entry and returns its `stock.id`. */
	private static function buyOpenAndMeasure(int $productId, float $measuredAmount): int
	{
		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$locationA);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => $measuredAmount, 'qu_id' => self::$gram]);

		return $stockRowId;
	}

	/**
	 * Builds the shape maintainer decision D8 names: a sealed (unopened) lot alongside
	 * one opened, measured container. `stock_next_use()` sorts `open DESC`, so the
	 * measured container is always this product's first candidate regardless of due
	 * date - an ordinary partial operation must skip over it and take from the sealed
	 * lot instead.
	 *
	 * @return array{sealedStockId: string, containerStockId: string, containerRowId: int}
	 */
	private static function buySealedAndMeasuredMix(int $productId, float $measuredAmount, float $sealedAmount): array
	{
		$stock = StockService::GetInstance();

		$stock->AddProduct($productId, $sealedAmount, '2031-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$locationA);
		$sealedStockId = self::$db->query('SELECT stock_id FROM stock WHERE product_id = ' . $productId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();

		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$locationA);
		$containerRow = self::$db->query(
			'SELECT id, stock_id FROM stock WHERE product_id = ' . $productId . " AND stock_id <> '" . $sealedStockId . "'"
		)->fetch(PDO::FETCH_ASSOC);
		$containerRowId = (int)$containerRow['id'];
		$containerStockId = $containerRow['stock_id'];

		$stock->OpenProduct($productId, 1, $containerStockId);
		$stock->MeasureStockEntry($containerRowId, ['amount' => $measuredAmount, 'qu_id' => self::$gram]);

		return [
			'sealedStockId' => $sealedStockId,
			'containerStockId' => $containerStockId,
			'containerRowId' => $containerRowId,
		];
	}

	// ------------------------------------------------------------------------------
	// Round 2 (D8): skip a measured entry as a split candidate when other stock exists
	// ------------------------------------------------------------------------------

	/**
	 * The validator's own repro: 3 units on hand (1 opened and measured at 0.6, 2
	 * sealed), consuming 0.5 must succeed from the sealed lot rather than being refused
	 * just because a measured container happens to exist.
	 */
	public function testPartialConsumeSkipsAMeasuredEntryAndSucceedsFromSealedStock(): void
	{
		$productId = self::insertProduct('M2 Skip Consume', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$response = self::$stock->ConsumeProduct(self::request('POST', ['amount' => 0.5]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A fractional consume succeeds by taking from the sealed lot instead of the measured container');

		$container = self::$db->query('SELECT amount, open, opened_amount, opened_qu_id FROM stock WHERE id = ' . $mix['containerRowId'])->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$container['amount'], 'The measured container is completely untouched');
		self::assertSame(1, (int)$container['open'], 'The measured container is still open');
		self::assertEqualsWithDelta(0.6, (float)$container['opened_amount'], 1e-9, 'The measurement itself survives untouched');
		self::assertSame(self::$gram, (int)$container['opened_qu_id'], 'The measurement unit survives untouched');

		$sealed = self::$db->query("SELECT amount FROM stock WHERE stock_id = '" . $mix['sealedStockId'] . "'")->fetch(PDO::FETCH_ASSOC);
		self::assertEqualsWithDelta(1.5, (float)$sealed['amount'], 1e-9, 'The sealed lot absorbed the whole 0.5 shortfall');
	}

	/**
	 * The validator's own repro: an InventoryProduct() downward correction from 3 to
	 * 2.5 (the same 0.5 shortfall as the consume case, via the same ConsumeProduct()
	 * code path) must also succeed from the sealed lot.
	 */
	public function testInventoryProductCorrectionSkipsAMeasuredEntryAndSucceedsFromSealedStock(): void
	{
		$productId = self::insertProduct('M2 Skip Inventory', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$response = self::$stock->InventoryProduct(self::request('POST', ['new_amount' => 2.5]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A downward inventory correction succeeds by taking from the sealed lot instead of the measured container');

		$container = self::$db->query('SELECT amount, opened_amount, opened_qu_id FROM stock WHERE id = ' . $mix['containerRowId'])->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$container['amount'], 'The measured container is completely untouched');
		self::assertEqualsWithDelta(0.6, (float)$container['opened_amount'], 1e-9, 'The measurement itself survives untouched');

		$sealed = self::$db->query("SELECT amount FROM stock WHERE stock_id = '" . $mix['sealedStockId'] . "'")->fetch(PDO::FETCH_ASSOC);
		self::assertEqualsWithDelta(1.5, (float)$sealed['amount'], 1e-9, 'The sealed lot absorbed the whole 0.5 correction');
	}

	/**
	 * A recipe consuming a fractional amount of this ingredient (StockService::
	 * ConsumeProduct() called with substitution allowed, via RecipesService::
	 * ConsumeRecipe()) must also succeed from the sealed lot.
	 */
	public function testRecipeConsumeSkipsAMeasuredEntryAndSucceedsFromSealedStock(): void
	{
		$productId = self::insertProduct('M2 Skip Recipe Ingredient', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$outputProductId = self::insertProduct('M2 Skip Recipe Output', self::$locationA);

		$recipeStatement = self::$db->prepare('INSERT INTO recipes (name, product_id, base_servings, desired_servings) VALUES (?, ?, 1, 1) RETURNING id');
		$recipeStatement->execute(['M2 Skip Recipe', $outputProductId]);
		$recipeId = (int)$recipeStatement->fetchColumn();

		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 0.5, ?)')
			->execute([$recipeId, $productId, self::$gram]);

		$response = self::$recipes->ConsumeRecipe(self::request('POST'), new Response(), ['recipeId' => $recipeId]);
		self::assertSame(204, $response->getStatusCode(), 'Consuming the recipe succeeds by taking its ingredient from the sealed lot instead of the measured container');

		$container = self::$db->query('SELECT amount, opened_amount, opened_qu_id FROM stock WHERE id = ' . $mix['containerRowId'])->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$container['amount'], 'The measured container is completely untouched');
		self::assertEqualsWithDelta(0.6, (float)$container['opened_amount'], 1e-9, 'The measurement itself survives untouched');

		$sealed = self::$db->query("SELECT amount FROM stock WHERE stock_id = '" . $mix['sealedStockId'] . "'")->fetch(PDO::FETCH_ASSOC);
		self::assertEqualsWithDelta(1.5, (float)$sealed['amount'], 1e-9, 'The sealed lot absorbed the whole 0.5 recipe consumption');
	}

	/**
	 * The validator's own repro, transfer side: transferring 0.5 must succeed from the
	 * sealed lot rather than being refused (or, before round 1's fix, hitting the raw
	 * coherence CHECK) just because a measured container happens to exist.
	 */
	public function testPartialTransferSkipsAMeasuredEntryAndSucceedsFromSealedStock(): void
	{
		$productId = self::insertProduct('M2 Skip Transfer', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$response = self::$stock->TransferProduct(self::request('POST', [
			'amount' => 0.5,
			'location_id_from' => self::$locationA,
			'location_id_to' => self::$locationB,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A fractional transfer succeeds by taking from the sealed lot instead of the measured container');

		$container = self::$db->query('SELECT amount, location_id, opened_amount, opened_qu_id FROM stock WHERE id = ' . $mix['containerRowId'])->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$container['amount'], 'The measured container is completely untouched');
		self::assertSame(self::$locationA, (int)$container['location_id'], 'The measured container never left the source location');
		self::assertEqualsWithDelta(0.6, (float)$container['opened_amount'], 1e-9, 'The measurement itself survives untouched');

		$sealedAtSource = self::$db->query("SELECT amount FROM stock WHERE stock_id = '" . $mix['sealedStockId'] . "' AND location_id = " . self::$locationA)->fetch(PDO::FETCH_ASSOC);
		self::assertEqualsWithDelta(1.5, (float)$sealedAtSource['amount'], 1e-9, 'The sealed lot at the source kept only the untransferred remainder');

		$sealedAtDestination = self::$db->query("SELECT amount FROM stock WHERE stock_id = '" . $mix['sealedStockId'] . "' AND location_id = " . self::$locationB)->fetch(PDO::FETCH_ASSOC);
		self::assertEqualsWithDelta(0.5, (float)$sealedAtDestination['amount'], 1e-9, 'The transferred amount landed at the destination, split off the sealed lot');
	}

	// ------------------------------------------------------------------------------
	// Refusal still applies: only remaining source, or explicitly named
	// ------------------------------------------------------------------------------

	/**
	 * The issue's own repro, with no sealed stock at all: measure a one-unit container
	 * at 0.6, consume 0.5. The measured container is the only stock this product has,
	 * so it must still be refused rather than losing its measurement.
	 */
	public function testPartialConsumeOfTheOnlyMeasuredEntryStillRefuses(): void
	{
		$productId = self::insertProduct('M2 Partial Consume', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 0.5]), new Response(), ['productId' => $productId]),
			400,
			'Partially consuming the only measured container must be refused, not silently drop the measurement'
		);

		self::assertStringContainsString(
			'measured container',
			(string)($body['error_message'] ?? json_encode($body)),
			'Then: the refusal names the actual reason'
		);
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');

		$row = self::$db->query('SELECT amount, open, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$row['amount'], 'Then: the container still holds its original whole amount');
		self::assertSame(1, (int)$row['open'], 'Then: the container is still open');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'Then: the measurement itself is untouched');
		self::assertSame(self::$gram, (int)$row['opened_qu_id'], 'Then: the measurement unit is untouched');
	}

	/**
	 * The issue's own repro, with no sealed stock at all: measure a one-unit container
	 * at 0.6, then attempt to transfer 0.5 of it. Still refused rather than hitting the
	 * raw coherence CHECK.
	 */
	public function testPartialTransferOfTheOnlyMeasuredEntryStillRefuses(): void
	{
		$productId = self::insertProduct('M2 Partial Transfer', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', [
				'amount' => 0.5,
				'location_id_from' => self::$locationA,
				'location_id_to' => self::$locationB,
			]), new Response(), ['productId' => $productId]),
			400,
			'Partially transferring the only measured container must be refused, not hit the raw coherence CHECK'
		);

		$message = (string)($body['error_message'] ?? json_encode($body));
		self::assertStringNotContainsStringIgnoringCase('sqlstate', $message, 'Then: this is not a raw database exception message');
		self::assertStringNotContainsStringIgnoringCase('23514', $message, 'Then: the raw constraint name/code never reaches the caller');
		self::assertStringContainsString('measured container', $message, 'Then: the refusal names the actual reason');
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');

		$row = self::$db->query('SELECT amount, location_id, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1.0, (float)$row['amount'], 'Then: the container still holds its original whole amount');
		self::assertSame(self::$locationA, (int)$row['location_id'], 'Then: the container never left the source location');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'Then: the measurement itself is untouched');
	}

	/**
	 * Sealed stock exists, but the caller named the measured container explicitly via
	 * stock_entry_id - still refused, since the caller specifically chose the one entry
	 * that cannot be partially consumed.
	 */
	public function testPartialConsumeExplicitlyNamingAMeasuredEntryStillRefusesEvenWithSealedStock(): void
	{
		$productId = self::insertProduct('M2 Explicit Consume', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', [
				'amount' => 0.5,
				'stock_entry_id' => $mix['containerStockId'],
			]), new Response(), ['productId' => $productId]),
			400,
			'Explicitly naming a measured container for a partial consume must still be refused even though sealed stock exists'
		);

		self::assertStringContainsString('measured container', (string)($body['error_message'] ?? json_encode($body)), 'Then: the refusal names the actual reason');
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');
	}

	/**
	 * Sealed stock exists, but the caller named the measured container explicitly via
	 * stock_entry_id for a transfer - still refused, for the same reason as the consume
	 * case above.
	 */
	public function testPartialTransferExplicitlyNamingAMeasuredEntryStillRefusesEvenWithSealedStock(): void
	{
		$productId = self::insertProduct('M2 Explicit Transfer', self::$locationA);
		$mix = self::buySealedAndMeasuredMix($productId, 0.6, 2);

		$before = self::productLedger($productId);

		$body = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', [
				'amount' => 0.5,
				'location_id_from' => self::$locationA,
				'location_id_to' => self::$locationB,
				'stock_entry_id' => $mix['containerStockId'],
			]), new Response(), ['productId' => $productId]),
			400,
			'Explicitly naming a measured container for a partial transfer must still be refused even though sealed stock exists'
		);

		self::assertStringContainsString('measured container', (string)($body['error_message'] ?? json_encode($body)), 'Then: the refusal names the actual reason');
		self::assertSame($before, self::productLedger($productId), 'Then: the refusal leaves stock and stock_log exactly as it found them');
	}

	// ------------------------------------------------------------------------------
	// Controls: a whole-unit take of a measured entry is unaffected
	// ------------------------------------------------------------------------------

	/**
	 * Control: a whole-container consume of a measured entry is unaffected by this fix
	 * and still succeeds, deleting the row and mirroring its measurement onto the
	 * booking (already covered for undo by UndoMeasuredContainerCoherenceTest.php).
	 */
	public function testWholeContainerConsumeOfAMeasuredContainerStillSucceeds(): void
	{
		$productId = self::insertProduct('M2 Whole Consume', self::$locationA);
		self::buyOpenAndMeasure($productId, 0.6);

		$response = self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A whole-container consume of a measured entry still succeeds');

		self::assertSame([], self::$db->query('SELECT * FROM stock WHERE product_id = ' . $productId)->fetchAll(), 'The container is fully consumed');

		$log = self::$db->query(
			"SELECT amount, opened_amount, opened_qu_id FROM stock_log WHERE product_id = $productId AND transaction_type = 'consume' AND undone = 0"
		)->fetch(PDO::FETCH_ASSOC);
		self::assertSame(-1.0, (float)$log['amount'], 'The booking logs the whole amount taken');
		self::assertEqualsWithDelta(0.6, (float)$log['opened_amount'], 1e-9, 'The booking mirrors the measurement for a later undo');
	}

	/**
	 * Control: a whole-row transfer of a measured entry is unaffected by this fix and
	 * still succeeds, relocating the row in place with its measurement intact.
	 */
	public function testWholeContainerTransferOfAMeasuredContainerStillSucceeds(): void
	{
		$productId = self::insertProduct('M2 Whole Transfer', self::$locationA);
		$stockRowId = self::buyOpenAndMeasure($productId, 0.6);

		$response = self::$stock->TransferProduct(self::request('POST', [
			'amount' => 1,
			'location_id_from' => self::$locationA,
			'location_id_to' => self::$locationB,
		]), new Response(), ['productId' => $productId]);
		self::assertSame(200, $response->getStatusCode(), 'A whole-container transfer of a measured entry still succeeds');

		$row = self::$db->query('SELECT id, amount, location_id, opened_amount, opened_qu_id FROM stock WHERE product_id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame($stockRowId, (int)$row['id'], 'The same physical row is relocated, not replaced');
		self::assertSame(1.0, (float)$row['amount'], 'The whole amount moves with it');
		self::assertSame(self::$locationB, (int)$row['location_id'], 'The row now sits at the destination location');
		self::assertEqualsWithDelta(0.6, (float)$row['opened_amount'], 1e-9, 'The measurement survives the whole-row transfer');
	}
}
