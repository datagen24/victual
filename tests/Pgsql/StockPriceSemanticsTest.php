<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #774, ADR-0045 (Proposed) P2 and option B, through the API: a price of 0 is a known
 * zero, an unknown price is null (a 0 stored for a price never entered is unknown too), and a
 * caller without STOCK_PRICES_VIEW gets no price field at all. An aggregate value is the known
 * part, with `value_incomplete` / `stock_value_incomplete` saying whether it is the whole.
 *
 * One test per case (a) to (f) of the record's option B table on GET /api/stock and
 * GET /api/stock/products/{id}, plus the volatile lists, the parent baseline (open question 1),
 * raw rows (open question 10), the P2 write paths, and a targeted test for each accepted
 * difference D1 to D4, which the existing differential fixtures do not exercise.
 */
class StockPriceSemanticsTest extends PgsqlSchemaTestCase
{
	private const ADMIN = 9000;
	private const NO_PRICES = 9001;

	private static PDO $db;
	private static array $keys = [];
	private static int $location;
	private static int $unit;

	/** An ADMIN caller, a caller with STOCK_VIEW only (no STOCK_PRICES_VIEW), a location and a unit. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		foreach ([self::ADMIN => ['ADMIN'], self::NO_PRICES => ['STOCK_VIEW']] as $user => $permissions)
		{
			self::$db->exec("INSERT INTO users(id, username, password) VALUES ($user, 'pricesemantics-$user', 'fixture')");
			$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
			foreach ($permissions as $permission)
			{
				$grant->execute([$user, $permission]);
			}
			$key = bin2hex(random_bytes(25));
			self::$keys[$user] = $key;
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)")
				->execute([ApiKeyService::HashKey($key), substr($key, -4), $user, ApiKeyService::API_KEY_TYPE_DEFAULT]);
		}

		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('pricesemantics-location') RETURNING id")->fetchColumn();
		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('pricesemantics-unit', 'pricesemantics-units') RETURNING id")->fetchColumn();
	}

	/** A new product using the fixture unit and location, optionally under $parent; returns its id. */
	private static function product(?int $parent = null): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['pricesemantics ' . uniqid(), self::$location, self::$unit, self::$unit, self::$unit, self::$unit, $parent]);

		return (int)$statement->fetchColumn();
	}

	/** Books a purchase directly; $price null is unknown. Returns the transaction id. */
	private static function purchase(int $productId, float $amount, ?float $price, string $purchased = '2026-09-01', string $due = '2999-12-31'): string
	{
		$transactionId = null;
		StockService::GetInstance()->AddProduct($productId, $amount, $due, StockService::TRANSACTION_TYPE_PURCHASE, $purchased, $price, self::$location, null, $transactionId);

		return $transactionId;
	}

	/** Marks a booking and its entry as a 0 stored for a price never entered, as migration 0315 does. */
	private static function markLegacyZero(string $transactionId): void
	{
		self::$db->prepare('UPDATE stock_log SET legacy_zero_price = 1 WHERE transaction_id = ?')->execute([$transactionId]);
		self::$db->prepare('UPDATE stock SET legacy_zero_price = 1 WHERE stock_id IN (SELECT stock_id FROM stock_log WHERE transaction_id = ?)')->execute([$transactionId]);
		self::rebuildCaches();
	}

	/**
	 * Rebuilds both price caches from the views. stock_log_UPD already rebuilds a product's caches
	 * when its booking is marked; rebuilding them all here keeps each case independent of which
	 * product a trigger last touched.
	 */
	private static function rebuildCaches(): void
	{
		self::$db->exec('SELECT reconcile_stock_log_cache()');
	}

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path, ?array $body = null, int $user = self::ADMIN): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$user]]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}
		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		$result['body'] = json_decode((string)$result['body'], true);

		return $result;
	}

	/** The product's row of GET /api/stock, or null when it has none. */
	private static function stockRow(int $productId, int $user = self::ADMIN): ?array
	{
		$response = self::send('GET', '/api/stock', null, $user);
		self::assertSame(200, $response['status']);
		foreach ($response['body'] as $row)
		{
			if ((int)$row['product_id'] === $productId)
			{
				return $row;
			}
		}

		return null;
	}

	/** The price fields of GET /api/stock/products/{id}. */
	private static function details(int $productId, int $user = self::ADMIN): array
	{
		$response = self::send('GET', "/api/stock/products/$productId", null, $user);
		self::assertSame(200, $response['status']);

		return array_intersect_key($response['body'], array_flip(['stock_value', 'stock_value_incomplete', 'last_price', 'avg_price', 'current_price', 'oldest_price']));
	}

	/** Asserts each expected price field of $actual, comparing floats with a tolerance and naming $case on failure. */
	private static function assertPrices(array $expected, array $actual, string $case): void
	{
		foreach ($expected as $field => $value)
		{
			self::assertArrayHasKey($field, $actual, "$case: $field is present");
			if (is_float($value) && $actual[$field] !== null)
			{
				self::assertEqualsWithDelta($value, $actual[$field], 0.0001, "$case: $field");
			}
			else
			{
				self::assertSame($value, $actual[$field], "$case: $field");
			}
		}
	}

	// ---------------------------------------------------------------------------------
	// Option B's table, cases (a) to (f)
	// ---------------------------------------------------------------------------------

	public function testCaseAEveryEntryUnpriced(): void
	{
		$product = self::product();
		self::purchase($product, 3, null);
		self::purchase($product, 5, null);

		$row = self::stockRow($product);
		self::assertEqualsWithDelta(0.0, $row['value'], 0.0001);
		self::assertTrue($row['value_incomplete'], '(a) the value is the known part, which is nothing');
		self::assertPrices(['stock_value' => 0.0, 'stock_value_incomplete' => true, 'last_price' => null, 'avg_price' => null, 'current_price' => null, 'oldest_price' => null], self::details($product), '(a)');
	}

	/** (b) A priced and an unpriced entry: the value is the priced part and is flagged incomplete. */
	public function testCaseBPricedAndUnpricedEntries(): void
	{
		$product = self::product();
		self::purchase($product, 2, 2.0, '2026-09-01');
		self::purchase($product, 4, null, '2026-09-02');

		$row = self::stockRow($product);
		self::assertEqualsWithDelta(4.0, $row['value'], 0.0001, '(b) the known subtotal');
		self::assertTrue($row['value_incomplete']);
		self::assertPrices(['stock_value' => 4.0, 'stock_value_incomplete' => true, 'last_price' => 2.0, 'avg_price' => 2.0, 'current_price' => 2.0], self::details($product), '(b)');
	}

	/** (c) A product never stocked, and one stocked out: value 0, not incomplete, and no prices. */
	public function testCaseCNeverStockedAndStockedOut(): void
	{
		$never = self::product();
		self::assertNull(self::stockRow($never), '(c1) no stock row');
		self::assertPrices(['stock_value' => 0.0, 'stock_value_incomplete' => false, 'last_price' => null, 'avg_price' => null, 'current_price' => null], self::details($never), '(c1)');

		$out = self::product();
		self::purchase($out, 2, 3.0);
		StockService::GetInstance()->ConsumeProduct($out, 2, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertNull(self::stockRow($out));
		self::assertPrices(['stock_value' => 0.0, 'stock_value_incomplete' => false, 'last_price' => 3.0, 'avg_price' => 3.0, 'current_price' => 3.0], self::details($out), '(c2)');
	}

	/** (d) Every entry priced: the full value, not flagged. */
	public function testCaseDEveryEntryPriced(): void
	{
		$product = self::product();
		self::purchase($product, 2, 1.5);
		self::purchase($product, 1, 3.0, '2026-09-02');

		$row = self::stockRow($product);
		self::assertEqualsWithDelta(6.0, $row['value'], 0.0001);
		self::assertFalse($row['value_incomplete']);
		self::assertPrices(['stock_value' => 6.0, 'stock_value_incomplete' => false, 'last_price' => 3.0, 'current_price' => 1.5], self::details($product), '(d)');
	}

	/** (e) A known 0 is a known value; a legacy-zero entry counts as unknown. */
	public function testCaseEAKnownZeroAndALegacyZero(): void
	{
		$free = self::product();
		self::purchase($free, 2, 0.0);
		$row = self::stockRow($free);
		self::assertEqualsWithDelta(0.0, $row['value'], 0.0001);
		self::assertFalse($row['value_incomplete'], '(e) a free entry is a known price');
		self::assertPrices(['stock_value' => 0.0, 'stock_value_incomplete' => false, 'last_price' => 0.0, 'avg_price' => 0.0, 'current_price' => 0.0], self::details($free), '(e)');

		$legacy = self::product();
		self::markLegacyZero(self::purchase($legacy, 2, 0.0));
		self::assertTrue(self::stockRow($legacy)['value_incomplete'], 'a 0 stored for a price never entered is unknown');
		self::assertPrices(['stock_value' => 0.0, 'stock_value_incomplete' => true, 'last_price' => null, 'avg_price' => null, 'current_price' => null], self::details($legacy), 'legacy zero');
	}

	/** (f) A caller without STOCK_PRICES_VIEW gets neither the value nor its flag. */
	public function testCaseFACallerWithoutStockPricesViewGetsNoPriceFieldAndNoFlag(): void
	{
		$product = self::product();
		self::purchase($product, 2, null);

		$row = self::stockRow($product, self::NO_PRICES);
		self::assertIsArray($row);
		self::assertArrayNotHasKey('value', $row);
		self::assertArrayNotHasKey('value_incomplete', $row, 'the flag says whether prices were recorded, so it is redacted with the value');

		$details = self::send('GET', "/api/stock/products/$product", null, self::NO_PRICES)['body'];
		foreach (['stock_value', 'stock_value_incomplete', 'last_price', 'avg_price', 'current_price', 'oldest_price'] as $field)
		{
			self::assertArrayNotHasKey($field, $details, "(f) $field is absent");
		}
	}

	/** The due, overdue and missing lists of /stock/volatile carry value_incomplete. */
	public function testTheVolatileListsCarryTheFlag(): void
	{
		$product = self::product();
		self::purchase($product, 1, null, '2026-09-01', date('Y-m-d', strtotime('+1 day')));

		$volatile = self::send('GET', '/api/stock/volatile?due_soon_days=5')['body'];
		$rows = array_values(array_filter($volatile['due_products'], fn ($row) => (int)$row['product_id'] === $product));
		self::assertCount(1, $rows);
		self::assertTrue($rows[0]['value_incomplete']);
	}

	/** A parent whose stock is all in sub-products reports only its own (empty) value. */
	public function testAParentWithStockOnlyInItsSubProductsReportsItsOwnValue(): void
	{
		$parent = self::product();
		$child = self::product($parent);
		self::purchase($child, 2, null);

		$row = self::stockRow($parent);
		self::assertIsArray($row, 'the parent is listed through its sub product');
		self::assertGreaterThan(0, (float)$row['amount_aggregated']);
		self::assertEqualsWithDelta(0.0, $row['value'], 0.0001, 'its own stock has no value');
		self::assertFalse($row['value_incomplete'], "and its own stock has no unknown price: the sub product's does not count (open question 1)");
		self::assertTrue(self::stockRow($child)['value_incomplete']);
	}

	// ---------------------------------------------------------------------------------
	// Open question 10: raw rows
	// ---------------------------------------------------------------------------------

	public function testARawRowWithALegacyZeroCarriesNullAndAKnownZeroCarriesZero(): void
	{
		$product = self::product();
		$legacy = self::purchase($product, 1, 0.0);
		self::markLegacyZero($legacy);
		$free = self::purchase($product, 1, 0.0, '2026-09-02');

		$bookings = self::send('GET', '/api/stock/transactions/' . $legacy)['body'];
		self::assertNull($bookings[0]['price'], 'a marked 0 is null on the wire');
		self::assertArrayNotHasKey('legacy_zero_price', $bookings[0], 'and the marker stays internal');
		self::assertSame(0, (int)round((float)self::send('GET', '/api/stock/transactions/' . $free)['body'][0]['price']), 'a known 0 stays 0');

		$entries = self::send('GET', "/api/stock/products/$product/entries")['body'];
		self::assertSame([null, 0.0], array_map(fn ($entry) => $entry['price'] === null ? null : (float)$entry['price'], $entries));
		foreach (self::send('GET', "/api/objects/stock_log?query%5B%5D=product_id%3D$product")['body'] as $log)
		{
			self::assertArrayNotHasKey('legacy_zero_price', $log);
		}
	}

	// ---------------------------------------------------------------------------------
	// The P2 write paths
	// ---------------------------------------------------------------------------------

	public function testAPurchaseWithoutAPriceStoresNull(): void
	{
		$product = self::product();
		foreach ([[], ['price' => null], ['price' => '']] as $price)
		{
			$response = self::send('POST', "/api/stock/products/$product/add", ['amount' => 1] + $price);
			self::assertSame(200, $response['status'], json_encode($response));
			self::assertNull($response['body'][0]['price']);
		}
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $product AND price IS NOT NULL")->fetchColumn());
	}

	/** An inventory correction with no known price books NULL, not 0. */
	public function testAnInventoryCorrectionForAProductWithNoKnownPriceBooksNull(): void
	{
		$product = self::product();
		self::purchase($product, 1, null);

		StockService::GetInstance()->InventoryProduct($product, 4, '2999-12-31', self::$location);

		$price = self::$db->query("SELECT price FROM stock_log WHERE product_id = $product AND transaction_type = 'inventory-correction'")->fetchColumn();
		self::assertNull($price, 'the correction has no known price to book, so not 0');
	}

	/** A self-production from a recipe with incomplete prices books NULL. */
	public function testASelfProductionFromARecipeWithIncompletePricesBooksNull(): void
	{
		$ingredient = self::product();
		self::purchase($ingredient, 5, null);
		$produced = self::product();
		$recipe = (int)self::$db->query("INSERT INTO recipes (name, base_servings, desired_servings, product_id) VALUES ('pricesemantics recipe " . uniqid() . "', 1, 1, $produced) RETURNING id")->fetchColumn();
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 1, ?)')->execute([$recipe, $ingredient, self::$unit]);
		self::assertSame(1, (int)self::$db->query("SELECT prices_incomplete FROM recipes_resolved WHERE recipe_id = $recipe")->fetchColumn());

		RecipesService::GetInstance()->ConsumeRecipe($recipe);

		$price = self::$db->query("SELECT price FROM stock_log WHERE product_id = $produced AND transaction_type = 'self-production'")->fetchColumn();
		self::assertNull($price);
	}

	// ---------------------------------------------------------------------------------
	// The accepted differences D1 to D4 (gate 7)
	// ---------------------------------------------------------------------------------

	public function testD1AKnownZeroEntersThePriceHistoryAndTheAverageAndALegacyZeroDoesNot(): void
	{
		$product = self::product();
		self::purchase($product, 1, 0.0, '2026-09-01');
		self::markLegacyZero(self::purchase($product, 1, 0.0, '2026-09-02'));
		self::purchase($product, 1, 4.0, '2026-09-03');
		self::rebuildCaches();

		$history = self::send('GET', "/api/stock/products/$product/price-history")['body'];
		$prices = array_map(fn ($row) => (float)$row['price'], $history);
		sort($prices);
		self::assertSame([0.0, 4.0], $prices, 'the known 0 and the 4, not the legacy 0');
		self::assertEqualsWithDelta(2.0, self::details($product)['avg_price'], 0.0001, 'the average of 0 and 4');
	}

	/** D2: no known purchase price reads NULL, and an unknown next entry falls back to the last known price. */
	public function testD2NoKnownPurchasePriceIsNullAndAnUnknownNextEntryFallsBackToTheLastKnownPrice(): void
	{
		$none = self::product();
		self::purchase($none, 1, null);
		self::assertNull(self::$db->query("SELECT price FROM products_last_purchased WHERE product_id = $none")->fetchColumn());

		$fallback = self::product();
		self::purchase($fallback, 1, null, '2026-09-01');
		self::purchase($fallback, 1, 5.0, '2026-09-02');
		self::assertPrices(['current_price' => 5.0, 'last_price' => 5.0], self::details($fallback), 'the next entry is unpriced, so the last known price (open question 2)');
	}

	/** D3/D4: recipe costs keep a known 0 and mark an unknown price as incomplete. */
	public function testD3AndD4RecipeCostsKeepAKnownZeroAndMarkAnUnknownPrice(): void
	{
		$free = self::product();
		self::purchase($free, 5, 0.0);
		$unpriced = self::product();
		self::purchase($unpriced, 5, null);

		$zeroRecipe = (int)self::$db->query("INSERT INTO recipes (name, base_servings, desired_servings) VALUES ('pricesemantics zero " . uniqid() . "', 1, 1) RETURNING id")->fetchColumn();
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 1, ?)')->execute([$zeroRecipe, $free, self::$unit]);
		self::assertSame(0.0, (float)self::$db->query("SELECT costs FROM recipes_pos_resolved WHERE recipe_id = $zeroRecipe")->fetchColumn());
		self::assertSame(0, (int)self::$db->query("SELECT prices_incomplete FROM recipes_resolved WHERE recipe_id = $zeroRecipe")->fetchColumn(), 'D4: a free ingredient is a complete price');

		$unknownRecipe = (int)self::$db->query("INSERT INTO recipes (name, base_servings, desired_servings) VALUES ('pricesemantics unknown " . uniqid() . "', 1, 1) RETURNING id")->fetchColumn();
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 1, ?)')->execute([$unknownRecipe, $unpriced, self::$unit]);
		self::assertNull(self::$db->query("SELECT costs FROM recipes_pos_resolved WHERE recipe_id = $unknownRecipe")->fetchColumn(), 'D3: an unknown price is an unknown cost');
		self::assertSame(1, (int)self::$db->query("SELECT prices_incomplete FROM recipes_resolved WHERE recipe_id = $unknownRecipe")->fetchColumn());

		$empty = (int)self::$db->query("INSERT INTO recipes (name, base_servings, desired_servings) VALUES ('pricesemantics empty " . uniqid() . "', 1, 1) RETURNING id")->fetchColumn();
		self::assertSame(1, (int)self::$db->query("SELECT prices_incomplete FROM recipes_resolved WHERE recipe_id = $empty")->fetchColumn(), 'a recipe with no position stays incomplete, as before');
	}

	/**
	 * Issue #990: weighing a vessel books its positive correction at the product's last price,
	 * as an inventory correction does, so a product with no known price, or whose only price
	 * is a marked 0, books NULL rather than 0.
	 */
	public function testWeighingAProductWithNoKnownPriceBooksNull(): void
	{
		$vessel = (int)self::$db->query("INSERT INTO locations (name, tare_weight, tare_qu_id) VALUES ('pricesemantics vessel " . uniqid() . "', 1, " . self::$unit . ') RETURNING id')->fetchColumn();
		foreach (['unpriced' => null, 'marked zero' => 0.0] as $case => $price)
		{
			$product = self::product();
			$transactionId = null;
			StockService::GetInstance()->AddProduct($product, 1, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', $price, $vessel, null, $transactionId);
			if ($price !== null)
			{
				self::markLegacyZero($transactionId);
			}

			StockService::GetInstance()->WeighLocation($vessel, 4.0, null, '2999-12-31');

			$booked = self::$db->query("SELECT amount, price FROM stock_log WHERE product_id = $product AND transaction_type = 'inventory-correction'")->fetch(PDO::FETCH_ASSOC);
			self::assertIsArray($booked, "($case) the weighing booked a correction");
			self::assertEqualsWithDelta(2.0, (float)$booked['amount'], 0.0001, "($case) 4 gross less a tare of 1, over the 1 in stock");
			self::assertNull($booked['price'], "($case) the correction has no known price to book, so not 0");

			StockService::GetInstance()->ConsumeProduct($product, 3, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $vessel);
		}
	}

	/**
	 * Issue #990: a purchase whose 0 is marked as never entered writes no price_paid point to
	 * InfluxDB, the same as an unpriced purchase; a known 0 still writes one.
	 */
	public function testInfluxWritesNoPricePaidPointForAMarkedZero(): void
	{
		$pricePaid = function (string $transactionId): array
		{
			// Through JSON, as the outbox stores and the drain reads it
			$payload = json_decode(json_encode((new BookingEventPublisher())->CaptureEvent($transactionId)), true);

			return array_values(array_filter(BookingEventPublisher::BuildLines([$payload]), fn ($line) => str_starts_with($line, 'price_paid')));
		};

		$known = self::purchase(self::product(), 1, 0.0);
		self::assertCount(1, $pricePaid($known), 'a known 0 is a price paid');

		$marked = self::purchase(self::product(), 1, 0.0);
		self::markLegacyZero($marked);
		self::assertSame([], $pricePaid($marked), 'a marked 0 is not');

		self::assertSame([], $pricePaid(self::purchase(self::product(), 1, null)), 'nor is an unpriced purchase');
	}

	/**
	 * Issue #990: a meal plan day holding a product with no known price is a recipe whose
	 * cost leaves a price out, which is what the meal plan's day marker reads.
	 */
	public function testAMealPlanDayWithAnUnpricedProductReadsAsIncomplete(): void
	{
		$unpriced = self::product();
		self::purchase($unpriced, 2, null);
		$free = self::product();
		self::purchase($free, 2, 0.0);
		self::$db->prepare("INSERT INTO meal_plan (day, type, product_id, product_amount) VALUES ('2031-03-03', 'product', ?, 1), ('2031-03-03', 'product', ?, 1)")->execute([$unpriced, $free]);

		$day = self::$db->query("SELECT rr.* FROM recipes_resolved rr JOIN recipes r ON r.id = rr.recipe_id WHERE r.type = 'mealplan-day' AND r.name = '2031-03-03'")->fetch(PDO::FETCH_ASSOC);
		$positions = self::$db->query("SELECT rpr.product_id, rpr.costs FROM recipes_pos_resolved rpr JOIN recipes r ON r.id = rpr.recipe_id WHERE r.type = 'mealplan-day' AND r.name = '2031-03-03'")->fetchAll(PDO::FETCH_ASSOC);
		self::assertIsArray($day, 'the day has a shadow recipe');
		self::assertSame(1, (int)$day['prices_incomplete'], 'the day leaves a price out: ' . json_encode([$day, $positions]));
		self::assertNotEmpty($day['product_names_comma_separated']);
	}
}

