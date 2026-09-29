<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #552 (D4, #487 remediation): products.product_group_id,
 * qu_id_consume and qu_id_price carried no FOREIGN KEY anywhere in the schema
 * (db/pgsql/baseline/01_tables.sql), so deleting a product group or a quantity unit a product
 * still named succeeded and left the product pointing at a row that no longer existed.
 * migrations/0295.pgsql.sql, modelled on ADR-0029 (docs/adr/0029-stock-locations-reference-
 * existing-locations.md), adds the three foreign keys - see that migration's own header
 * comment for why products.location_id, qu_id_purchase and qu_id_stock (the other three
 * columns issue #552 names) are NOT NULL and left unconstrained pending a maintainer decision
 * on a repair rule for a dangling NOT NULL reference.
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
	// Negative control
	// ------------------------------------------------------------------------------

	/** The fix must not turn every product group / quantity unit delete into a 400. */
	public function testDeletingAnUnreferencedProductGroupOrQuantityUnitStillSucceeds(): void
	{
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
