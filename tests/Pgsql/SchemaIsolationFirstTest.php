<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * First test class in the schema isolation suite. Creates a product so that the second
 * test class can verify it does not see this product through a cached service singleton.
 */
class SchemaIsolationFirstTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Create required fixtures: location and quantity unit for product
		self::$db->exec("INSERT INTO locations(name) VALUES ('First Schema Location')");
		self::$db->exec("INSERT INTO quantity_units(name, name_plural, description) VALUES ('unit', 'units', 'default unit')");

		// Get the IDs that were auto-generated
		$loc = self::$db->query("SELECT id FROM locations WHERE name = 'First Schema Location'")->fetch(PDO::FETCH_ASSOC);
		$qu = self::$db->query("SELECT id FROM quantity_units WHERE name = 'unit'")->fetch(PDO::FETCH_ASSOC);
		$locId = (int)$loc['id'];
		$quId = (int)$qu['id'];

		// Create a product in the first schema
		self::$db->exec("INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) VALUES ('first-schema-product', $locId, $quId, $quId)");
	}

	/**
	 * Verify the product exists in the first schema.
	 */
	public function testProductExistsInFirstSchema()
	{
		$result = self::$db->query("SELECT COUNT(*) as count FROM products WHERE name = 'first-schema-product'");
		$row = $result->fetch(PDO::FETCH_ASSOC);

		self::assertSame(1, (int)$row['count'], 'Product should exist in first schema');
	}

	/**
	 * Cache a service singleton by instantiating it.
	 *
	 * This test deliberately instantiates StockService so it gets cached in BaseService::$Instances
	 * with the first schema's connection. When SchemaIsolationSecondTest runs, if the reset is not
	 * performed, it will receive this cached instance bound to the first schema (now dropped) and fail.
	 */
	public function testCacheServiceSingleton()
	{
		// Instantiate the service - this caches it for the process
		$stock = StockService::GetInstance();
		self::assertNotNull($stock, 'Service should be instantiable in first schema');
	}
}
