<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #622 (#487 remediation), found while validating #553's fix (PR #621): the same
 * "no resolved conversion means 1:1" defect #553 fixed on the write side
 * (StockService::SubstitutionAwareProductIdWhereClause()) also lived in stock_current
 * itself, which computes amount_aggregated (and amount_opened_aggregated, amount_measured)
 * by summing `s.amount * COALESCE(qucr.factor, 1.0)` per sub product row - a fallback that
 * only applies to a NULL qucr.factor, a missing conversion. A sub product whose stock unit
 * has no resolved conversion to its parent's own was rolled in 1:1 by that fallback; one
 * whose only resolved conversion has a non-positive factor was never NULL, so it was
 * multiplied in as resolved instead - a negative factor subtracted from the aggregate
 * rather than being excluded. Neither contributes anything once fixed (maintainer decision
 * D4, issue #553). This overstated or understated
 * every page and API response that reads a parent product's aggregated amount, GET
 * /api/stock/products/{productId} (StockApiController::ProductDetails(), asserted below)
 * among them - it is one of the read paths issue #622 names, and the stock overview screen
 * is another.
 *
 * Fixed by migrations/0298.pgsql.sql, which recreates stock_current so the fallback is 1.0
 * only for a product's own row (products_resolved's self-row: parent_product_id =
 * sub_product_id) and 0.0 for a genuine sub product with no resolved, positive conversion -
 * the same distinction SubstitutionAwareProductIdWhereClause() draws between its
 * unconditional `product_id = $productId` branch and its conversion-gated `product_id IN
 * (...)` branch. cache__quantity_unit_conversions_resolved already carries a stock->stock
 * identity row at factor 1.0 for every product ("Priority 2",
 * db/pgsql/baseline/03_views_group2.sql), so the self-row case already resolves to 1.0
 * through the ordinary qucr join; the CASE's own 1.0 is a safety net against that identity
 * row being missing, not the only source of the value.
 *
 * This is the API-level counterpart to .devtools/pgtap/025-unconvertible-subproduct-aggregation.sql's
 * direct view assertions, driving the real controller (StockCoverageTest's own pattern) so
 * the fix is proven all the way out to the wire response a client actually reads.
 */
class StockAggregatedSubProductConvertibilityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockApiController $stock;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController($container);

		// VICTUAL_USER_ID (tests/Support/PgsqlSchemaTestCase.php) is defined once per
		// process at 9000 - matching id here, per every other controller-level test's
		// own convention (e.g. StockCoverageTest, StockAmountPolicyTest), is what makes
		// User::CheckPermission() see this user as authenticated at all.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stock-aggregation-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");
	}

	private static function request()
	{
		return (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/stock/products');
	}

	private static function location(string $name): int
	{
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute([$name]);
		return (int)$stmt->fetchColumn();
	}

	private static function quantityUnit(string $name): int
	{
		$stmt = self::$db->prepare('INSERT INTO quantity_units(name, name_plural) VALUES (?, ?) RETURNING id');
		$stmt->execute([$name, $name]);
		return (int)$stmt->fetchColumn();
	}

	private static function product(string $name, int $locationId, int $quId, ?int $parentProductId = null): int
	{
		$stmt = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
		$stmt->execute([$name, $locationId, $quId, $quId, $quId, $quId, $parentProductId]);
		return (int)$stmt->fetchColumn();
	}

	/** Written directly into the cache table stock_current joins against, matching SubProductUnitConvertibilityTest.php's own convention. */
	private static function conversion(int $productId, int $fromQuId, int $toQuId, float $factor): void
	{
		$stmt = self::$db->prepare('INSERT INTO cache__quantity_unit_conversions_resolved(product_id, from_qu_id, to_qu_id, factor) VALUES (?, ?, ?, ?)');
		$stmt->execute([$productId, $fromQuId, $toQuId, (string)$factor]);
	}

	private static function stockRow(int $productId, float $amount, int $locationId): void
	{
		$stmt = self::$db->prepare('INSERT INTO stock(product_id, amount, stock_id, location_id) VALUES (?, ?, ?, ?)');
		$stmt->execute([$productId, $amount, 'aggconv-' . bin2hex(random_bytes(6)), $locationId]);
	}

	/**
	 * Asserts $details carries a genuinely numeric value at $key and returns it as a
	 * float. `(float)` alone would turn a missing key (json_decode's null), a string, or
	 * any other non-numeric value into 0.0 - indistinguishable from a real zero
	 * aggregate - so a wrong or absent response shape would still pass an assertion that
	 * only compared `(float)$details[$key]` against an expected 0.0.
	 */
	private static function numericField(array $details, string $key): float
	{
		self::assertArrayHasKey($key, $details, "Response is missing the '$key' field entirely");
		self::assertTrue(is_numeric($details[$key]), "'$key' must be numeric on the wire, got: " . var_export($details[$key], true));
		return (float)$details[$key];
	}

	public function testProductDetailsAggregatesAParentWithOneConvertibleAndOneUnconvertibleSubProduct(): void
	{
		// Given: a parent with its own stock (1 unit), one sub product whose unit
		// converts to the parent's at factor 2 (holding 2 units), and one sub product
		// with no resolved conversion to the parent's unit at all (holding 5 units).
		$location = self::location('Aggregation API Location');
		$parentUnit = self::quantityUnit('Aggregation API Parent Unit');
		$convertibleUnit = self::quantityUnit('Aggregation API Convertible Unit');
		$unconvertibleUnit = self::quantityUnit('Aggregation API Unconvertible Unit');

		$parentId = self::product('Aggregation API Parent', $location, $parentUnit);
		$convertibleId = self::product('Aggregation API Convertible Child', $location, $convertibleUnit, $parentId);
		$unconvertibleId = self::product('Aggregation API Unconvertible Child', $location, $unconvertibleUnit, $parentId);

		self::conversion($convertibleId, $convertibleUnit, $parentUnit, 2.0);
		// Deliberately no conversion row at all for $unconvertibleId -> $parentUnit.

		self::stockRow($parentId, 1, $location);
		self::stockRow($convertibleId, 2, $location);
		self::stockRow($unconvertibleId, 5, $location);

		// When: reading the parent's product details, the same GET
		// /api/stock/products/{productId} endpoint the stock overview and product detail
		// pages both call.
		$response = self::$stock->ProductDetails(self::request(), new Response(), ['productId' => $parentId]);
		$details = json_decode((string)$response->getBody(), true);

		// Then: amount_aggregated is the parent's own stock (1) plus the convertible
		// child converted by its own factor (2 * 2 = 4); the unconvertible child's 5
		// units contribute nothing. Before this fix it read 1 + 4 + 5 = 10 (the
		// unconvertible child counted 1:1).
		self::assertSame(200, $response->getStatusCode());
		self::assertSame(5.0, self::numericField($details, 'stock_amount_aggregated'), 'An unconvertible sub product must contribute nothing to amount_aggregated, not be counted 1:1');
		self::assertTrue($details['is_aggregated_amount'], 'The parent has genuine sub products, so the amount is reported as aggregated');
	}

	public function testProductDetailsExcludesASubProductWhoseOnlyResolvedConversionHasANonPositiveFactor(): void
	{
		// Given: a parent whose only sub product has a resolved conversion with a
		// NEGATIVE factor - present in the cache, but not admissible per D4 (issue
		// #553), the same way MergeProducts() and SubstitutionAwareProductIdWhereClause()
		// already refuse/exclude a non-positive factor elsewhere.
		$location = self::location('Aggregation API Negative Factor Location');
		$parentUnit = self::quantityUnit('Aggregation API Negative Factor Parent Unit');
		$childUnit = self::quantityUnit('Aggregation API Negative Factor Child Unit');

		$parentId = self::product('Aggregation API Negative Factor Parent', $location, $parentUnit);
		$childId = self::product('Aggregation API Negative Factor Child', $location, $childUnit, $parentId);

		self::conversion($childId, $childUnit, $parentUnit, -3.0);
		self::stockRow($childId, 4, $location);

		$response = self::$stock->ProductDetails(self::request(), new Response(), ['productId' => $parentId]);
		$details = json_decode((string)$response->getBody(), true);

		// Then: amount_aggregated is 0, not -12 (4 * -3) - a non-positive resolved
		// factor must exclude the sub product exactly like no resolved conversion at
		// all, never be multiplied into the aggregate.
		self::assertSame(200, $response->getStatusCode());
		self::assertSame(0.0, self::numericField($details, 'stock_amount_aggregated'), 'A sub product whose only resolved conversion has a non-positive factor must contribute nothing');
	}
}
