<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Second test class in the schema isolation suite. Verifies that service singletons
 * were reset and now use the second schema's connection, not the first schema's (which
 * no longer exists).
 *
 * Without the singleton reset fix, this test fails with "relation "products" does not exist"
 * because StockService was instantiated by the first test class and still holds a
 * connection to the first schema, which was dropped after that class ran.
 */
class SchemaIsolationSecondTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Create required fixtures: location and quantity unit for product
		self::$db->exec("INSERT INTO locations(name) VALUES ('Second Schema Location')");
		self::$db->exec("INSERT INTO quantity_units(name, name_plural, description) VALUES ('unit', 'units', 'default unit')");

		// Get the IDs that were auto-generated
		$loc = self::$db->query("SELECT id FROM locations WHERE name = 'Second Schema Location'")->fetch(PDO::FETCH_ASSOC);
		$qu = self::$db->query("SELECT id FROM quantity_units WHERE name = 'unit'")->fetch(PDO::FETCH_ASSOC);
		$locId = (int)$loc['id'];
		$quId = (int)$qu['id'];

		// Create a product in the second schema
		self::$db->exec("INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) VALUES ('second-schema-product', $locId, $quId, $quId)");
	}

	/**
	 * Verify the product exists in the second schema.
	 */
	public function testProductExistsInSecondSchema()
	{
		$result = self::$db->query("SELECT COUNT(*) as count FROM products WHERE name = 'second-schema-product'");
		$row = $result->fetch(PDO::FETCH_ASSOC);

		self::assertSame(1, (int)$row['count'], 'Product should exist in second schema');
	}

	/**
	 * Verify that a service singleton reaches the second schema, not the first.
	 *
	 * This is the key test: without the singleton reset in PgsqlSchemaTestCase::setUpBeforeClass(),
	 * StockService::GetInstance() would return an instance created by the first test class,
	 * still holding the first schema's connection (now dropped), causing a "relation does not exist" error.
	 */
	public function testServiceSingletonUsesSecondSchema()
	{
		$stock = StockService::GetInstance();

		// Use reflection to access the protected $DB property
		$reflection = new \ReflectionClass($stock);
		$dbProperty = $reflection->getProperty('DB');
		$dbProperty->setAccessible(true);
		$db = $dbProperty->getValue($stock);

		// Query for the product added in this (second) test class
		$result = $db->products()->where('name = ?', 'second-schema-product')->fetch();

		self::assertNotNull($result, 'Service should reach second schema and find the product');
		self::assertSame('second-schema-product', $result['name']);

		// Verify the first schema's product is not visible
		$result = $db->products()->where('name = ?', 'first-schema-product')->fetch();

		self::assertNull($result, 'Service should not see first schema product');
	}
}
