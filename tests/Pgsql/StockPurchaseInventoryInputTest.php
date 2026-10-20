<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * #487 remediation regression coverage for issues #535, #544, #582 and #583: input
 * semantics on the purchase/inventory/shopping-list/measurement routes of
 * StockApiController that #530/#576 did not reach.
 *
 *   #535 AddProduct()/InventoryProduct() never checked that a supplied
 *        shopping_location_id names an existing, active shopping location - a purchase or
 *        inventory correction naming a nonexistent store booked anyway and left a dangling
 *        stock/stock_log reference the price history and "last purchased at" views cannot
 *        resolve.
 *   #544 The same two routes read best_before_date, purchased_date, price, location_id and
 *        shopping_location_id the way EditStockEntry() did before #530: a value that could
 *        not be parsed silently fell through to the product's default (or, for
 *        best_before_date, reached IsIsoDate() as an array and 500ed) rather than refusing
 *        the request. Fixed with the same helpers #530 already established for
 *        EditStockEntry() (RequireIsoDate()/RequireNumericAmount()/RequireExistingId()),
 *        with absent, null and "" all kept as "use the default" - unchanged from today's
 *        behaviour and required by the shipped UI, which sends "" for an unset price
 *        (public/viewjs/inventory.js) and best_before_date (public/viewjs/purchase.js
 *        without VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING's date picker).
 *   #582 Six shopping-list routes read list_id the same way: "abc", an array, 0 and a
 *        negative number all fell through to the documented default, list 1, silently -
 *        destructively so for ClearShoppingList(), which can empty list 1 instead of
 *        refusing an unreadable id. Every route now refuses a present, non-null list_id
 *        that is not a positive integer.
 *   #583 MeasureStockEntry() and OpenProduct() read the measurement flag "gross" with
 *        boolval(), for which the string "false" is truthy - the same class of misread
 *        #576 already fixed for "spoiled" and "allow_subproduct_substitution". A
 *        misread "false" subtracts the tare from a reading the caller meant as already net.
 *        Both now go through WireBooleans::RequireBoolean().
 *   #786 AddProduct() passed any non-empty transaction_type through, and
 *        StockService::AddProduct() compared it to 'self-production' with ==, for which
 *        a JSON true is equal - so {"transaction_type": true} booked a stock_log row whose
 *        transaction_type is outside the documented enum. Both routes now refuse a present,
 *        non-empty transaction_type that is not a string, and the service compares strictly.
 *
 * Every case runs at HTTP level through tests/Pgsql/request-subprocess-helper.php, the same
 * harness ApiInputShapesTest.php/StockEntryEditContractTest.php use, so a refusal is proven
 * the way HandleApiCall()'s real catch clause and ExceptionController answer it, not merely
 * how a direct service call would.
 */
class StockPurchaseInventoryInputTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9900;

	private static PDO $db;
	private static string $apiKey;
	private static int $quId;

	/**
	 * Creates a user with the stock and shopping list permissions the tests exercise, an API
	 * key for it, and the quantity unit used by every fixture product.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec('INSERT INTO users(id, username, password) VALUES (' . self::USER_ID . ", 'stock-purchase-inventory-input', 'fixture')");

		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		foreach ([
			'STOCK_PURCHASE',
			'STOCK_CONSUME',
			'STOCK_INVENTORY',
			'STOCK_EDIT',
			'STOCK_OPEN',
			'STOCK_VIEW',
			'STOCK_PRICES_VIEW',
			'SHOPPINGLIST_ITEMS_ADD',
			'SHOPPINGLIST_ITEMS_DELETE',
		] as $permission)
		{
			$statement->execute([$permission]);
		}

		self::$apiKey = bin2hex(random_bytes(25));
		$keyStatement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$keyStatement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT,
		]);

		self::$quId = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('InputPiece', 'InputPieces') RETURNING id")->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// HTTP + fixture helpers (same pattern as ApiInputShapesTest.php/StockEntryEditContractTest.php)
	// ------------------------------------------------------------------------------

	/**
	 * Sends one API request in a child process with the test user's API key and returns the
	 * decoded result (status and body).
	 */
	private static function Request(string $method, string $path, ?array $body = null): array
	{
		$spec = [
			'method' => $method,
			'path' => $path,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey, 'Content-Type' => 'application/json'],
			'body' => $body,
		];

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** Asserts $result is a 400 in the documented Error400 shape. @return array The decoded body */
	private static function AssertRefused(array $result, string $context): array
	{
		self::assertSame(400, $result['status'], "$context: expected 400, got {$result['status']} (body: {$result['body']})");

		$body = json_decode((string)$result['body'], true);
		self::assertIsArray($body, "$context: response body must be JSON");
		self::assertArrayHasKey('error_message', $body, "$context: must carry the documented error_message");
		self::assertNotSame('', $body['error_message'], "$context: error_message must not be empty");

		return $body;
	}

	/** Inserts one row into $table from the column map and returns the new id. */
	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/** Inserts a location with the given name and returns its id. */
	private static function insertLocation(string $name): int
	{
		return self::insertRow('locations', ['name' => $name]);
	}

	/** Inserts a shopping location (store) with the given name and returns its id. */
	private static function insertShoppingLocation(string $name): int
	{
		return self::insertRow('shopping_locations', ['name' => $name]);
	}

	/**
	 * Inserts a product at the given location, using the shared quantity unit for purchase and
	 * stock, and returns its id.
	 */
	private static function insertProduct(string $name, int $locationId): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => $locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
		]);
	}

	/** @return array{0: int, 1: int} [locationId, productId], both fresh */
	private static function freshProduct(string $label): array
	{
		static $n = 0;
		$n++;
		$locationId = self::insertLocation("$label Location $n");
		$productId = self::insertProduct("$label Product $n", $locationId);

		return [$locationId, $productId];
	}

	/**
	 * Inserts a stock row for the product with a unique stock_id, a best-before date 30 days
	 * out and today as the purchased date; $overrides replace those columns. Returns the row
	 * id.
	 */
	private static function insertStock(int $productId, int $locationId, float $amount, array $overrides = []): int
	{
		static $n = 0;
		$n++;

		return self::insertRow('stock', array_merge([
			'product_id' => $productId,
			'amount' => $amount,
			'stock_id' => "input-test-stock-$n",
			'best_before_date' => date('Y-m-d', strtotime('+30 days')),
			'purchased_date' => date('Y-m-d'),
			'location_id' => $locationId,
		], $overrides));
	}

	/** Returns how many stock rows the product has. */
	private static function stockCount(int $productId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (int)$statement->fetchColumn();
	}

	/** Returns how many stock_log rows the product has. */
	private static function stockLogCount(int $productId): int
	{
		$statement = self::$db->prepare('SELECT count(*) FROM stock_log WHERE product_id = ?');
		$statement->execute([$productId]);

		return (int)$statement->fetchColumn();
	}

	/** Returns the stock row with the given id, failing the test if it does not exist. */
	private static function stockRow(int $stockRowId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$statement->execute([$stockRowId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row, "stock row $stockRowId must exist");

		return $row;
	}

	/**
	 * Returns the id, product_id and done columns of the items on the shopping list, ordered by
	 * id.
	 */
	private static function shoppingListRows(int $listId): array
	{
		$statement = self::$db->prepare('SELECT id, product_id, done FROM shopping_list WHERE shopping_list_id = ? ORDER BY id');
		$statement->execute([$listId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Every list_id test below asserts an absolute row count on list 1, and this class's
	 * schema (like every PgsqlSchemaTestCase) is shared across all of this class's test
	 * methods, not reset per method - so each of those tests clears shopping_list first,
	 * to stay correct regardless of run order or of what an earlier test in this file left
	 * behind (or, against unfixed code, failed to leave behind).
	 */
	private static function resetShoppingListRows(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
	}

	// ================================================================================
	// #535: shopping_location_id naming no active shopping location
	// ================================================================================

	/**
	 * Proves that a purchase naming a shopping_location_id that does not exist is refused with
	 * a 400 and writes no stock or stock_log row.
	 */
	public function testAddProductWithNonexistentShoppingLocationIsRefusedWithoutBooking(): void
	{
		[$locationId, $productId] = self::freshProduct('535 Add Nonexistent Store');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 2,
			'shopping_location_id' => 999999,
		]);

		self::AssertRefused($result, 'POST .../add with shopping_location_id naming no store');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have written a stock row');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row');
	}

	/**
	 * Proves that an upward inventory correction naming a nonexistent shopping_location_id is
	 * refused with a 400 and leaves the existing entry and the ledger unchanged.
	 */
	public function testInventoryUpwardCorrectionWithNonexistentShoppingLocationIsRefusedWithoutBooking(): void
	{
		[$locationId, $productId] = self::freshProduct('535 Inventory Nonexistent Store');
		$stockRowId = self::insertStock($productId, $locationId, 1);

		$result = self::Request('POST', "/api/stock/products/$productId/inventory", [
			'new_amount' => 5,
			'shopping_location_id' => 999999,
		]);

		self::AssertRefused($result, 'POST .../inventory with shopping_location_id naming no store');
		self::assertSame(1.0, (float)self::stockRow($stockRowId)['amount'], 'a refused inventory correction must not have changed the existing entry');
		self::assertSame(1, self::stockCount($productId), 'a refused inventory correction must not have added a stock row');
		self::assertSame(0, self::stockLogCount($productId), 'a refused inventory correction must not have written a ledger row');
	}

	/** Positive control: a real, active shopping location still books exactly as before. */
	public function testAddProductWithValidShoppingLocationBooksIt(): void
	{
		[$locationId, $productId] = self::freshProduct('535 Add Valid Store');
		$storeId = self::insertShoppingLocation('535 Valid Store');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 2,
			'shopping_location_id' => $storeId,
		]);

		self::assertSame(200, $result['status'], "a valid shopping_location_id must still be accepted: {$result['body']}");
		self::assertSame(1, self::stockCount($productId));

		$statement = self::$db->prepare('SELECT shopping_location_id FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		self::assertSame($storeId, (int)$statement->fetchColumn(), 'the supplied store must be the one booked');
	}

	// ================================================================================
	// #544: best_before_date / purchased_date / price / location_id silently defaulted
	// ================================================================================

	/**
	 * Proves that a purchase whose best_before_date is an array is refused with a 400 and
	 * writes nothing.
	 */
	public function testAddProductWithArrayBestBeforeDateIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('544 Add Array Date');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'best_before_date' => ['2030-01-01'],
		]);

		self::AssertRefused($result, 'POST .../add with an array best_before_date');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have written a stock row (an array used to reach IsIsoDate() as a 500, not a 400)');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row either');
	}

	/**
	 * Proves that a purchase whose purchased_date is not a date is refused with a 400 and
	 * writes nothing.
	 */
	public function testAddProductWithUnreadablePurchasedDateIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('544 Add Bad Purchased Date');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'purchased_date' => 'not-a-date',
		]);

		self::AssertRefused($result, 'POST .../add with an unreadable purchased_date');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have booked with today\'s date silently substituted');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row either');
	}

	/**
	 * Proves that a purchase with a non-numeric price is refused with a 400 and is not booked
	 * with the price silently dropped.
	 */
	public function testAddProductWithNonNumericPriceIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('544 Add Bad Price');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'price' => 'expensive',
		]);

		self::AssertRefused($result, 'POST .../add with a non-numeric price');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have booked with no price silently substituted');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row either');
	}

	/**
	 * Proves that a purchase with a malformed location_id is refused with a 400 and is not
	 * booked at the product's default location.
	 */
	public function testAddProductWithMalformedLocationIdIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('544 Add Bad Location');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'location_id' => 'abc',
		]);

		self::AssertRefused($result, 'POST .../add with a non-numeric location_id');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have booked at the product\'s default location silently');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row either');
	}

	/** Proves that a purchase at an inactive location is refused with a 400 and writes nothing. */
	public function testAddProductWithInactiveLocationIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('544 Add Inactive Location');
		$inactiveLocationId = self::insertRow('locations', ['name' => '544 Inactive Add Location', 'active' => 0]);

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'location_id' => $inactiveLocationId,
		]);

		self::AssertRefused($result, 'POST .../add with an inactive location_id');
		self::assertSame(0, self::stockCount($productId), 'a refused purchase must not have booked at an inactive location');
		self::assertSame(0, self::stockLogCount($productId), 'a refused purchase must not have written a ledger row either');
	}

	/**
	 * Proves that an inventory correction whose best_before_date is an array is refused with a
	 * 400 and adds no stock or ledger row.
	 */
	public function testInventoryWithArrayBestBeforeDateIsRefusedWithoutBooking(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Inventory Array Date');
		self::insertStock($productId, $locationId, 1);

		$result = self::Request('POST', "/api/stock/products/$productId/inventory", [
			'new_amount' => 5,
			'best_before_date' => ['2030-01-01'],
		]);

		self::AssertRefused($result, 'POST .../inventory with an array best_before_date');
		self::assertSame(1, self::stockCount($productId), 'a refused inventory correction must not have added a stock row');
		self::assertSame(0, self::stockLogCount($productId), 'a refused inventory correction must not have written a ledger row either');
	}

	/**
	 * Proves that an inventory correction naming an inactive shopping location is refused with
	 * a 400 and adds no stock or ledger row.
	 */
	public function testInventoryWithInactiveShoppingLocationIsRefusedWithoutBooking(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Inventory Inactive Store');
		self::insertStock($productId, $locationId, 1);
		$inactiveStoreId = self::insertRow('shopping_locations', ['name' => '544 Inactive Inventory Store', 'active' => 0]);

		$result = self::Request('POST', "/api/stock/products/$productId/inventory", [
			'new_amount' => 5,
			'shopping_location_id' => $inactiveStoreId,
		]);

		self::AssertRefused($result, 'POST .../inventory with an inactive shopping_location_id');
		self::assertSame(1, self::stockCount($productId), 'a refused inventory correction must not have added a stock row');
		self::assertSame(0, self::stockLogCount($productId), 'a refused inventory correction must not have written a ledger row either');
	}

	/**
	 * Positive control across every field #544 lists, on both routes: absent, null and ""
	 * keep today's defaults, and a well-formed supplied value books exactly what was given.
	 */
	public function testAddProductWithValidFieldsBooksExactlyThoseValues(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Add Valid Fields');
		$otherLocationId = self::insertLocation('544 Add Valid Fields Other Location');
		$storeId = self::insertShoppingLocation('544 Valid Fields Store');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 3,
			'best_before_date' => '2031-05-01',
			'purchased_date' => '2026-02-01',
			'price' => 4.5,
			'location_id' => $otherLocationId,
			'shopping_location_id' => $storeId,
		]);

		self::assertSame(200, $result['status'], "well-formed fields must still be accepted: {$result['body']}");

		$statement = self::$db->prepare('SELECT amount, best_before_date, purchased_date, price, location_id, shopping_location_id FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		self::assertSame(3.0, (float)$row['amount']);
		self::assertSame('2031-05-01', $row['best_before_date']);
		self::assertSame('2026-02-01', $row['purchased_date']);
		self::assertSame(4.5, (float)$row['price']);
		self::assertSame($otherLocationId, (int)$row['location_id']);
		self::assertSame($storeId, (int)$row['shopping_location_id']);
	}

	/**
	 * Positive control for the shipped UI's own "" convention: public/viewjs/inventory.js
	 * always sends price:"" when it has not computed one, and
	 * public/viewjs/components/shoppinglocationpicker.js sends "" for its blank "no store"
	 * option - neither must be refused as malformed.
	 */
	public function testInventoryWithEmptyStringPriceAndShoppingLocationStillSucceeds(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Inventory Empty String Fields');
		self::insertStock($productId, $locationId, 1);

		$result = self::Request('POST', "/api/stock/products/$productId/inventory", [
			'new_amount' => 4,
			'price' => '',
			'shopping_location_id' => '',
		]);

		self::assertSame(200, $result['status'], "an empty-string price/shopping_location_id (the shipped UI's own \"unset\" convention) must not be refused: {$result['body']}");
	}

	/**
	 * Positive control (Opus round-2 blocker): the purchase form's LocationPicker is not
	 * always required (views/purchase.blade.php's isRequired=false) and sends "" for its
	 * blank option (public/viewjs/purchase.js:95) exactly like the ShoppingLocationPicker
	 * already does - "" must mean "use the product's default location", not be refused as
	 * malformed. It must reach the service as null, exactly like an omitted location_id:
	 * db/pgsql/baseline/06_triggers_b.sql's set_products_default_location_if_empty_stock
	 * trigger is what actually resolves a null stock.location_id to the product's own
	 * location_id on write, so a booked row's location_id is never itself null - the
	 * product's location, not NULL, is the observable proof "" was treated as absent.
	 */
	public function testAddProductWithEmptyStringLocationIdBooksAtTheDefaultLocation(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Add Empty String Location');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'location_id' => '',
		]);

		self::assertSame(200, $result['status'], "an empty-string location_id (the shipped UI's own \"unset\" convention) must not be refused: {$result['body']}");

		$statement = self::$db->prepare('SELECT location_id FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		self::assertSame($locationId, (int)$statement->fetchColumn(), 'location_id:"" must book at the product\'s own default location, exactly like an omitted location_id');
	}

	/**
	 * Positive control (Opus round-2 blocker): inventory.js drops the picker's `required`
	 * attribute for a downward correction (inventory.js:477) and always sends its value
	 * unchanged (inventory.js:50), so "" reaches this route too. Uses an upward correction
	 * (new_amount > current stock) so the added stock is booked through AddProduct(),
	 * where the same default-location trigger applies - see the test above.
	 */
	public function testInventoryWithEmptyStringLocationIdBooksAtTheDefaultLocation(): void
	{
		[$locationId, $productId] = self::freshProduct('544 Inventory Empty String Location');
		self::insertStock($productId, $locationId, 1);

		$result = self::Request('POST', "/api/stock/products/$productId/inventory", [
			'new_amount' => 5,
			'location_id' => '',
		]);

		self::assertSame(200, $result['status'], "an empty-string location_id must not be refused: {$result['body']}");

		$statement = self::$db->prepare('SELECT location_id FROM stock WHERE product_id = ? ORDER BY id DESC LIMIT 1');
		$statement->execute([$productId]);
		self::assertSame($locationId, (int)$statement->fetchColumn(), 'location_id:"" must book the new entry at the product\'s own default location, exactly like an omitted location_id');
	}

	// ================================================================================
	// #582: list_id on the shopping-list routes
	// ================================================================================

	/**
	 * Proves that clearing the shopping list with a non-numeric list_id is refused and leaves
	 * both the default list and another list untouched.
	 */
	public function testClearShoppingListWithNonNumericListIdIsRefusedAndLeavesListsUntouched(): void
	{
		self::resetShoppingListRows();
		self::insertRow('shopping_list', ['note' => '582 default list item', 'shopping_list_id' => 1, 'done' => 1]);
		$otherListId = self::insertRow('shopping_lists', ['name' => '582 Other List']);
		self::insertRow('shopping_list', ['note' => '582 other list item', 'shopping_list_id' => $otherListId, 'done' => 0]);

		$result = self::Request('POST', '/api/stock/shoppinglist/clear', ['list_id' => 'abc', 'done_only' => true]);

		self::AssertRefused($result, 'POST /stock/shoppinglist/clear with list_id:"abc"');
		self::assertCount(1, self::shoppingListRows(1), 'list 1 must be untouched by a refused request');
		self::assertCount(1, self::shoppingListRows($otherListId), 'the other list must be untouched too');
	}

	/**
	 * Proves that clearing the shopping list with list_id 0 is refused rather than silently
	 * clearing the default list.
	 */
	public function testClearShoppingListWithZeroListIdIsRefusedAndLeavesDefaultListUntouched(): void
	{
		self::resetShoppingListRows();
		self::insertRow('shopping_list', ['note' => '582 zero list_id item', 'shopping_list_id' => 1, 'done' => 1]);

		// 0 is falsy in PHP, so the pre-fix "!empty($requestBody['list_id'])" guard treated
		// it exactly like an absent field and silently cleared list 1 anyway - the most
		// destructive form of this defect (issue #582).
		$result = self::Request('POST', '/api/stock/shoppinglist/clear', ['list_id' => 0, 'done_only' => true]);

		self::AssertRefused($result, 'POST /stock/shoppinglist/clear with list_id:0');
		self::assertCount(1, self::shoppingListRows(1), 'list_id:0 must not silently clear list 1');
	}

	/**
	 * Proves that adding missing products to the shopping list with an array list_id is refused
	 * rather than falling back to the default list.
	 */
	public function testAddMissingProductsToShoppingListWithArrayListIdIsRefused(): void
	{
		self::resetShoppingListRows();
		$locationId = self::insertLocation('582 Missing Location');
		self::insertRow('products', [
			'name' => '582 Missing Product',
			'location_id' => $locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
			'min_stock_amount' => 5,
		]);

		$result = self::Request('POST', '/api/stock/shoppinglist/add-missing-products', ['list_id' => [1]]);

		self::AssertRefused($result, 'POST /stock/shoppinglist/add-missing-products with list_id:[1]');
		self::assertCount(0, self::shoppingListRows(1), 'an array list_id must not silently apply the default list');
	}

	/**
	 * Proves that adding overdue products to the shopping list with a non-numeric list_id is
	 * refused.
	 */
	public function testAddOverdueProductsToShoppingListWithNonNumericListIdIsRefused(): void
	{
		$result = self::Request('POST', '/api/stock/shoppinglist/add-overdue-products', ['list_id' => 'abc']);

		self::AssertRefused($result, 'POST /stock/shoppinglist/add-overdue-products with list_id:"abc"');
	}

	/**
	 * Proves that adding expired products to the shopping list with a non-numeric list_id is
	 * refused.
	 */
	public function testAddExpiredProductsToShoppingListWithNonNumericListIdIsRefused(): void
	{
		$result = self::Request('POST', '/api/stock/shoppinglist/add-expired-products', ['list_id' => 'abc']);

		self::AssertRefused($result, 'POST /stock/shoppinglist/add-expired-products with list_id:"abc"');
	}

	/**
	 * Proves that adding a product to the shopping list with a non-numeric list_id is refused
	 * and creates no row on the default list.
	 */
	public function testAddProductToShoppingListWithNonNumericListIdIsRefusedWithoutCreatingARow(): void
	{
		self::resetShoppingListRows();
		[, $productId] = self::freshProduct('582 Add To List');

		$result = self::Request('POST', '/api/stock/shoppinglist/add-product', ['product_id' => $productId, 'list_id' => 'abc']);

		self::AssertRefused($result, 'POST /stock/shoppinglist/add-product with list_id:"abc"');
		self::assertCount(0, self::shoppingListRows(1), 'a refused add-product must not have created a row on the default list');
	}

	/**
	 * Proves that removing a product from the shopping list with a non-numeric list_id is
	 * refused and does not delete the row on the default list.
	 */
	public function testRemoveProductFromShoppingListWithNonNumericListIdIsRefusedWithoutDeletingARow(): void
	{
		self::resetShoppingListRows();
		[, $productId] = self::freshProduct('582 Remove From List');
		self::insertRow('shopping_list', ['product_id' => $productId, 'amount' => 1, 'shopping_list_id' => 1, 'qu_id' => self::$quId]);

		$result = self::Request('POST', '/api/stock/shoppinglist/remove-product', ['product_id' => $productId, 'list_id' => 'abc']);

		self::AssertRefused($result, 'POST /stock/shoppinglist/remove-product with list_id:"abc"');
		self::assertCount(1, self::shoppingListRows(1), 'a refused remove-product must not have deleted the row from list 1 instead');
	}

	/** Positive control: a valid, non-default list_id still touches only that list. */
	public function testClearShoppingListWithValidListIdStillClearsOnlyThatList(): void
	{
		self::resetShoppingListRows();
		self::insertRow('shopping_list', ['note' => '582 valid list_id default item', 'shopping_list_id' => 1, 'done' => 1]);
		$targetListId = self::insertRow('shopping_lists', ['name' => '582 Valid Target List']);
		self::insertRow('shopping_list', ['note' => '582 valid list_id target item', 'shopping_list_id' => $targetListId, 'done' => 1]);

		$result = self::Request('POST', '/api/stock/shoppinglist/clear', ['list_id' => $targetListId, 'done_only' => true]);

		self::assertSame(204, $result['status'], "a valid list_id must still be accepted: {$result['body']}");
		self::assertCount(0, self::shoppingListRows($targetListId), 'the named list must be cleared');
		self::assertCount(1, self::shoppingListRows(1), 'the default list must be untouched');
	}

	// ================================================================================
	// #583: the measurement flag "gross"
	// ================================================================================

	/**
	 * Inserts an opened stock row of amount 1 with the given opened_amount in the shared unit
	 * and no tare, and returns its id.
	 */
	private static function insertOpenSingleUnitStock(int $productId, int $locationId, float $openedAmount): int
	{
		return self::insertStock($productId, $locationId, 1, [
			'open' => 1,
			'opened_date' => date('Y-m-d'),
			'opened_amount' => $openedAmount,
			'opened_qu_id' => self::$quId,
			'opened_tare' => null,
		]);
	}

	/**
	 * Proves that measuring a stock entry with gross given as the string "false" is refused and
	 * the entry's opened_amount is unchanged.
	 */
	public function testMeasureStockEntryWithStringFalseGrossIsRefusedWithoutMeasuring(): void
	{
		[$locationId, $productId] = self::freshProduct('583 Measure String Gross');
		$stockRowId = self::insertOpenSingleUnitStock($productId, $locationId, 0.5);

		$result = self::Request('POST', "/api/stock/entry/$stockRowId/measure", [
			'amount' => 1.2,
			'qu_id' => self::$quId,
			'gross' => 'false',
			'tare' => 0.1,
		]);

		self::AssertRefused($result, 'POST .../measure with gross:"false"');

		$row = self::stockRow($stockRowId);
		self::assertSame(0.5, (float)$row['opened_amount'], 'a refused measurement must not have subtracted a tare from a reading it never accepted');
	}

	/** Positive control: a real boolean gross:true still subtracts the tare exactly as before. */
	public function testMeasureStockEntryWithRealGrossTrueSubtractsTare(): void
	{
		[$locationId, $productId] = self::freshProduct('583 Measure Real Gross');
		$stockRowId = self::insertOpenSingleUnitStock($productId, $locationId, 0.5);

		$result = self::Request('POST', "/api/stock/entry/$stockRowId/measure", [
			'amount' => 1.2,
			'qu_id' => self::$quId,
			'gross' => true,
			'tare' => 0.2,
		]);

		self::assertSame(200, $result['status'], "a real boolean gross:true must still be accepted: {$result['body']}");

		$row = self::stockRow($stockRowId);
		self::assertEqualsWithDelta(1.0, (float)$row['opened_amount'], 0.0001, 'gross:true must still subtract the tare (1.2 - 0.2)');
	}

	/**
	 * Proves that opening a product with a measurement whose gross is the string "false" is
	 * refused and the entry stays unopened.
	 */
	public function testOpenProductWithStringFalseMeasurementGrossIsRefusedWithoutOpening(): void
	{
		[$locationId, $productId] = self::freshProduct('583 Open String Gross');
		$stockRowId = self::insertStock($productId, $locationId, 1);
		$stockRow = self::stockRow($stockRowId);

		$result = self::Request('POST', "/api/stock/products/$productId/open", [
			'amount' => 1,
			'stock_entry_id' => $stockRow['stock_id'],
			'measurement' => ['amount' => 1, 'qu_id' => self::$quId, 'gross' => 'false', 'tare' => 0.1],
		]);

		self::AssertRefused($result, 'POST .../open with measurement.gross:"false"');
		self::assertSame(0, (int)self::stockRow($stockRowId)['open'], 'a refused open must not have opened the entry');
	}

	/** Positive control: a real boolean measurement.gross:true still opens and subtracts the tare. */
	public function testOpenProductWithRealMeasurementGrossTrueSubtractsTare(): void
	{
		[$locationId, $productId] = self::freshProduct('583 Open Real Gross');
		$stockRowId = self::insertStock($productId, $locationId, 1);
		$stockRow = self::stockRow($stockRowId);

		$result = self::Request('POST', "/api/stock/products/$productId/open", [
			'amount' => 1,
			'stock_entry_id' => $stockRow['stock_id'],
			'measurement' => ['amount' => 1, 'qu_id' => self::$quId, 'gross' => true, 'tare' => 0.3],
		]);

		self::assertSame(200, $result['status'], "a real boolean measurement.gross:true must still be accepted: {$result['body']}");

		$row = self::stockRow($stockRowId);
		self::assertSame(1, (int)$row['open'], 'the entry must be opened');
		self::assertEqualsWithDelta(0.7, (float)$row['opened_amount'], 0.0001, 'gross:true must still subtract the tare (1 - 0.3)');
	}

	// ================================================================================
	// #786: transaction_type that is not a string
	// ================================================================================

	/** Returns the transaction_type of each stock_log row of the product, in id order. */
	private static function stockLogTransactionTypes(int $productId): array
	{
		$statement = self::$db->prepare('SELECT transaction_type FROM stock_log WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_COLUMN);
	}

	/** @return array<string, array{0: mixed}> */
	public static function UnacceptableTransactionTypes(): array
	{
		return [
			'JSON true' => [true],
			'integer 1' => [1],
			'unknown string' => ['gift'],
			'array' => [['purchase']],
		];
	}

	/**
	 * Proves that a purchase with an unacceptable transaction_type is refused with a 400 and
	 * writes no stock_log row and no stock.
	 */
	#[DataProvider('UnacceptableTransactionTypes')]
	public function testAddProductWithUnacceptableTransactionTypeIsRefusedWithoutBooking(mixed $transactionType): void
	{
		[, $productId] = self::freshProduct('786 Add Bad Type');

		$result = self::Request('POST', "/api/stock/products/$productId/add", [
			'amount' => 1,
			'transaction_type' => $transactionType,
		]);

		self::AssertRefused($result, 'add with transaction_type ' . json_encode($transactionType));
		self::assertSame(0, self::stockLogCount($productId), 'a refused add must not write a stock_log row');
		self::assertSame(0, self::stockCount($productId), 'a refused add must not book stock');
	}

	/**
	 * Proves that a purchase by barcode whose transaction_type is the JSON value true is
	 * refused and writes no stock_log row.
	 */
	public function testAddProductByBarcodeWithJsonTrueTransactionTypeIsRefusedWithoutBooking(): void
	{
		[, $productId] = self::freshProduct('786 Add By Barcode Bad Type');
		self::insertRow('product_barcodes', ['product_id' => $productId, 'barcode' => "786-$productId"]);

		$result = self::Request('POST', "/api/stock/products/by-barcode/786-$productId/add", [
			'amount' => 1,
			'transaction_type' => true,
		]);

		self::AssertRefused($result, 'add by barcode with transaction_type true');
		self::assertSame(0, self::stockLogCount($productId), 'a refused add must not write a stock_log row');
	}

	/** @return array<string, array{0: mixed, 1: string}> body value (or absent) => booked type */
	public static function AcceptedAddTransactionTypes(): array
	{
		return [
			'purchase' => ['purchase', 'purchase'],
			'inventory-correction' => ['inventory-correction', 'inventory-correction'],
			'self-production' => ['self-production', 'self-production'],
			'absent' => [null, 'purchase'],
			'empty string' => ['', 'purchase'],
		];
	}

	/**
	 * Proves that a purchase with an accepted transaction_type succeeds and the stock_log row
	 * carries the expected type.
	 */
	#[DataProvider('AcceptedAddTransactionTypes')]
	public function testAddProductWithAcceptedTransactionTypeBooksIt(?string $transactionType, string $expected): void
	{
		[, $productId] = self::freshProduct('786 Add Good Type');

		$body = ['amount' => 1];
		if ($transactionType !== null)
		{
			$body['transaction_type'] = $transactionType;
		}

		$result = self::Request('POST', "/api/stock/products/$productId/add", $body);

		self::assertSame(200, $result['status'], "transaction_type '$transactionType' must still be accepted: {$result['body']}");
		self::assertSame([$expected], self::stockLogTransactionTypes($productId));
	}

	/**
	 * Proves that a consumption with an unacceptable transaction_type is refused with a 400,
	 * writes no stock_log row and leaves the stock amount unchanged.
	 */
	#[DataProvider('UnacceptableTransactionTypes')]
	public function testConsumeProductWithUnacceptableTransactionTypeIsRefusedWithoutBooking(mixed $transactionType): void
	{
		[$locationId, $productId] = self::freshProduct('786 Consume Bad Type');
		$stockRowId = self::insertStock($productId, $locationId, 5);

		$result = self::Request('POST', "/api/stock/products/$productId/consume", [
			'amount' => 1,
			'transaction_type' => $transactionType,
		]);

		self::AssertRefused($result, 'consume with transaction_type ' . json_encode($transactionType));
		self::assertSame(0, self::stockLogCount($productId), 'a refused consume must not write a stock_log row');
		self::assertSame(5.0, (float)self::stockRow($stockRowId)['amount'], 'a refused consume must not touch stock');
	}

	/** @return array<string, array{0: mixed, 1: string}> body value (or absent) => booked type */
	public static function AcceptedConsumeTransactionTypes(): array
	{
		return [
			'consume' => ['consume', 'consume'],
			'inventory-correction' => ['inventory-correction', 'inventory-correction'],
			'absent' => [null, 'consume'],
			'empty string' => ['', 'consume'],
		];
	}

	/**
	 * Proves that a consumption with an accepted transaction_type succeeds and the stock_log
	 * row carries the expected type.
	 */
	#[DataProvider('AcceptedConsumeTransactionTypes')]
	public function testConsumeProductWithAcceptedTransactionTypeBooksIt(?string $transactionType, string $expected): void
	{
		[$locationId, $productId] = self::freshProduct('786 Consume Good Type');
		self::insertStock($productId, $locationId, 5);

		$body = ['amount' => 1];
		if ($transactionType !== null)
		{
			$body['transaction_type'] = $transactionType;
		}

		$result = self::Request('POST', "/api/stock/products/$productId/consume", $body);

		self::assertSame(200, $result['status'], "transaction_type '$transactionType' must still be accepted: {$result['body']}");
		self::assertSame([$expected], self::stockLogTransactionTypes($productId));
	}
}
