<?php

namespace Victual\Tests\Pgsql;

use PDO;
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
		self::$db->exec("INSERT INTO locations(id, name) VALUES (1, 'First Schema Location')");
		self::$db->exec("INSERT INTO quantity_units(id, name, name_plural, description) VALUES (1, 'unit', 'units', 'default unit')");

		// Create a product in the first schema
		self::$db->exec("INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (1, 'first-schema-product', 1, 1, 1)");
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
}
