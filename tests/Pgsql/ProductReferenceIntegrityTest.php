<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #552 (D4, #487 remediation): none of products' six upstream
 * reference columns - location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price,
 * product_group_id - carried a FOREIGN KEY anywhere in the schema
 * (db/pgsql/baseline/01_tables.sql), so deleting a location, quantity unit or product group a
 * product still named succeeded and left the product pointing at a row that no longer
 * existed. migrations/0295.pgsql.sql, modelled on ADR-0029 (docs/adr/0029-stock-locations-
 * reference-existing-locations.md), adds all six foreign keys, with no repair step: see that
 * migration's own header comment for why (the maintainer's own correction - the migration
 * system is one-time and runs on a fresh install, so a dangling reference can only arrive
 * through `bin/victual-db-import`, which DatabaseImporter::AssertProductReferences() now
 * validates separately, per tests/Pgsql/StockLocationImportTest.php).
 *
 * This runs at the httpboot phase, through request-subprocess-helper.php, for the same reason
 * ReferenceRefusalTest.php does: the refusal has to be observed through the real routing/
 * error-middleware pipeline, and DeleteObject()'s existing generic 23503 handling (issue
 * #515/PR #551) is what is expected to answer it - no per-entity code of its own is added by
 * this fix.
 */
class ProductReferenceIntegrityTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9701;

	private static PDO $db;
	private static string $apiKey = '';
	private static int $locationId;
	private static int $quId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::USER_ID . ", 'product-reference-integrity-caller', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . " , id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		self::$locationId = self::insertRow('locations', ['name' => 'PRI shared location']);
		self::$quId = self::insertRow('quantity_units', ['name' => 'PRI shared unit']);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function rowExists(string $table, int $id): bool
	{
		$statement = self::$db->prepare("SELECT count(*) FROM $table WHERE id = ?");
		$statement->execute([$id]);

		return (int)$statement->fetchColumn() > 0;
	}

	/**
	 * @return array{status: int, body?: string, stderr: string}
	 */
	private static function delete(string $entity, int $objectId): array
	{
		$spec = [
			'method' => 'DELETE',
			'path' => '/api/objects/' . $entity . '/' . $objectId,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey],
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

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	/** Mirrors ReferenceRefusalTest::assertOrdinaryReferenceRefusal() - same fixed message. */
	private function assertOrdinaryReferenceRefusal(array $result, string $message): void
	{
		self::assertSame(400, $result['status'], "$message: expected 400, got {$result['status']} (body: {$result['body']}, stderr: {$result['stderr']})");

		$body = json_decode((string)$result['body'], true);
		self::assertIsArray($body, "$message: response body must be JSON");
		self::assertSame(
			'Object is still referenced by other objects; remove those references before deleting it',
			$body['error_message'] ?? null,
			"$message: must carry the fixed, actionable refusal message - not driver text, and not any other wording"
		);
	}

	// ------------------------------------------------------------------------------
	// products.product_group_id REFERENCES product_groups(id)
	// ------------------------------------------------------------------------------

	/**
	 * Given a product names a product group, when that group is deleted, then the request
	 * answers the documented 400 rather than succeeding and leaving a dangling reference, and
	 * both rows are left exactly as they were.
	 */
	public function testDeletingAProductGroupReferencedByAProductIsRefused(): void
	{
		$groupId = self::insertRow('product_groups', ['name' => 'PRI referenced group']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with group',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
			'product_group_id' => $groupId,
		]);

		$result = self::delete('product_groups', $groupId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a product group referenced by a product');
		self::assertTrue(self::rowExists('product_groups', $groupId), 'The product group must survive the refused delete');

		$storedGroupId = self::$db->prepare('SELECT product_group_id FROM products WHERE id = ?');
		$storedGroupId->execute([$productId]);
		self::assertSame($groupId, (int)$storedGroupId->fetchColumn(), 'The referencing product must still name the group');
	}

	// ------------------------------------------------------------------------------
	// products.qu_id_consume REFERENCES quantity_units(id)
	// ------------------------------------------------------------------------------

	/**
	 * The "quantity unit" reference class the issue names, through a column that now enforces
	 * it: a quantity unit still used as a product's consume unit cannot be deleted.
	 */
	public function testDeletingAQuantityUnitReferencedByAProductsConsumeUnitIsRefused(): void
	{
		$quId = self::insertRow('quantity_units', ['name' => 'PRI referenced consume unit']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with consume unit',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
			'qu_id_consume' => $quId,
		]);

		$result = self::delete('quantity_units', $quId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a quantity unit referenced by a product consume unit');
		self::assertTrue(self::rowExists('quantity_units', $quId), 'The quantity unit must survive the refused delete');

		$storedQuId = self::$db->prepare('SELECT qu_id_consume FROM products WHERE id = ?');
		$storedQuId->execute([$productId]);
		self::assertSame($quId, (int)$storedQuId->fetchColumn(), 'The referencing product must still name the unit');
	}

	// ------------------------------------------------------------------------------
	// products.qu_id_price REFERENCES quantity_units(id)
	// ------------------------------------------------------------------------------

	/** Same reference class, the other of the two nullable quantity-unit columns. */
	public function testDeletingAQuantityUnitReferencedByAProductsPriceUnitIsRefused(): void
	{
		$quId = self::insertRow('quantity_units', ['name' => 'PRI referenced price unit']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with price unit',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
			'qu_id_price' => $quId,
		]);

		$result = self::delete('quantity_units', $quId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a quantity unit referenced by a product price unit');
		self::assertTrue(self::rowExists('quantity_units', $quId), 'The quantity unit must survive the refused delete');

		$storedQuId = self::$db->prepare('SELECT qu_id_price FROM products WHERE id = ?');
		$storedQuId->execute([$productId]);
		self::assertSame($quId, (int)$storedQuId->fetchColumn(), 'The referencing product must still name the unit');
	}

	// ------------------------------------------------------------------------------
	// products.location_id REFERENCES locations(id)
	// ------------------------------------------------------------------------------

	/**
	 * The "location" reference class the issue names, through products' own location_id -
	 * NOT NULL, unlike the three columns above, but the maintainer's rework of this migration
	 * (see its header comment) drops all six into the same plain-foreign-key shape, so this
	 * refuses exactly like the nullable cases.
	 */
	public function testDeletingALocationReferencedByAProductIsRefused(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'PRI referenced location']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with its own location',
			'location_id' => $locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
		]);

		$result = self::delete('locations', $locationId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a location referenced by a product');
		self::assertTrue(self::rowExists('locations', $locationId), 'The location must survive the refused delete');

		$storedLocationId = self::$db->prepare('SELECT location_id FROM products WHERE id = ?');
		$storedLocationId->execute([$productId]);
		self::assertSame($locationId, (int)$storedLocationId->fetchColumn(), 'The referencing product must still name the location');
	}

	// ------------------------------------------------------------------------------
	// products.qu_id_purchase REFERENCES quantity_units(id)
	// ------------------------------------------------------------------------------

	/**
	 * The other NOT NULL column: a quantity unit still used as a product's purchase unit.
	 * qu_id_price is set explicitly to self::$quId, not left to default - trg_default_qu_id_
	 * price (db/pgsql/baseline/06_triggers_a.sql) fills a null qu_id_price from qu_id_purchase
	 * on insert, which would otherwise point qu_id_price at $quId too and leave this refusal
	 * ambiguous between qu_id_purchase_fkey and qu_id_price_fkey.
	 */
	public function testDeletingAQuantityUnitReferencedByAProductsPurchaseUnitIsRefused(): void
	{
		$quId = self::insertRow('quantity_units', ['name' => 'PRI referenced purchase unit']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with purchase unit',
			'location_id' => self::$locationId,
			'qu_id_purchase' => $quId,
			'qu_id_stock' => self::$quId,
			'qu_id_price' => self::$quId,
		]);

		$result = self::delete('quantity_units', $quId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a quantity unit referenced by a product purchase unit');
		self::assertTrue(self::rowExists('quantity_units', $quId), 'The quantity unit must survive the refused delete');

		$storedQuId = self::$db->prepare('SELECT qu_id_purchase FROM products WHERE id = ?');
		$storedQuId->execute([$productId]);
		self::assertSame($quId, (int)$storedQuId->fetchColumn(), 'The referencing product must still name the unit');
	}

	// ------------------------------------------------------------------------------
	// products.qu_id_stock REFERENCES quantity_units(id)
	// ------------------------------------------------------------------------------

	/**
	 * The last NOT NULL column: a quantity unit still used as a product's stock unit.
	 * qu_id_consume is set explicitly to self::$quId for the same reason the purchase-unit
	 * test above sets qu_id_price: trg_default_qu_id_consume fills a null qu_id_consume from
	 * qu_id_stock on insert, which would otherwise point it at $quId too.
	 */
	public function testDeletingAQuantityUnitReferencedByAProductsStockUnitIsRefused(): void
	{
		$quId = self::insertRow('quantity_units', ['name' => 'PRI referenced stock unit']);
		$productId = self::insertRow('products', [
			'name' => 'PRI product with stock unit',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => $quId,
			'qu_id_consume' => self::$quId,
		]);

		$result = self::delete('quantity_units', $quId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a quantity unit referenced by a product stock unit');
		self::assertTrue(self::rowExists('quantity_units', $quId), 'The quantity unit must survive the refused delete');

		$storedQuId = self::$db->prepare('SELECT qu_id_stock FROM products WHERE id = ?');
		$storedQuId->execute([$productId]);
		self::assertSame($quId, (int)$storedQuId->fetchColumn(), 'The referencing product must still name the unit');
	}

	// ------------------------------------------------------------------------------
	// Negative control
	// ------------------------------------------------------------------------------

	/** The fix must not turn every location / product group / quantity unit delete into a 400. */
	public function testDeletingAnUnreferencedLocationProductGroupOrQuantityUnitStillSucceeds(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'PRI unreferenced location']);
		$locationResult = self::delete('locations', $locationId);
		self::assertSame(204, $locationResult['status'], "An unreferenced location must still delete: {$locationResult['body']}");
		self::assertFalse(self::rowExists('locations', $locationId));

		$groupId = self::insertRow('product_groups', ['name' => 'PRI unreferenced group']);
		$groupResult = self::delete('product_groups', $groupId);
		self::assertSame(204, $groupResult['status'], "An unreferenced product group must still delete: {$groupResult['body']}");
		self::assertFalse(self::rowExists('product_groups', $groupId));

		$quId = self::insertRow('quantity_units', ['name' => 'PRI unreferenced unit']);
		$quResult = self::delete('quantity_units', $quId);
		self::assertSame(204, $quResult['status'], "An unreferenced quantity unit must still delete: {$quResult['body']}");
		self::assertFalse(self::rowExists('quantity_units', $quId));
	}
}
