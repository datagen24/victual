<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #782: POST /api/stock/products/{keep}/merge/{remove} deletes the second product
 * (StockService::MergeProducts() ends in DELETE FROM products) but checked only STOCK_EDIT,
 * which the seeded Adult role holds through STOCK. Deleting a product through
 * DELETE /api/objects/products/{id} needs MASTER_DATA_EDIT, and so does chore merge.
 *
 * Maintainer decision, 2026-10-12 (datagen24, on #782): product merge requires
 * MASTER_DATA_EDIT, as chore merge does.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php), for the
 * reason RecipeRouteAuthzTest gives: the authentication middleware define()s the acting
 * user, and PHP cannot redefine a constant.
 */
class MergeProductsPermissionTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** @var array<string,string> plaintext API keys by caller name */
	private static array $keys = [];

	private static int $locationId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$locationId = self::insertRow('locations', ['name' => 'Merge Authz Location']);

		$insertUser = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');

		// The built-in ADULT role (STOCK and more, never MASTER_DATA_EDIT -
		// db/pgsql/roles-seed.sql), granted through user_roles the way a household assigns it.
		$insertUser->execute([9700, 'merge-authz-adult', 'fixture']);
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) SELECT 9700, id FROM roles WHERE code = \'ADULT\'');
		self::$keys['adult-role'] = self::issueKey(9700);

		// MASTER_DATA_EDIT and nothing else: the decision names MASTER_DATA_EDIT alone, so
		// this caller merges without holding any stock permission.
		$insertUser->execute([9701, 'merge-authz-master-data', 'fixture']);
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT 9701, id FROM permission_hierarchy WHERE name = \'MASTER_DATA_EDIT\'');
		self::$keys['master-data-edit'] = self::issueKey(9701);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	/** Inserts a key row the way ApiKeyService::CreateApiKey() stores one, and returns its plaintext. */
	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path, string $apiKey): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey]];

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

		$response = json_decode($output, true);
		self::assertIsArray($response, 'The request helper answered: ' . $output . $errors);
		if (isset($response['body']) && is_string($response['body']))
		{
			$decoded = json_decode($response['body'], true);
			if (is_array($decoded))
			{
				$response['body'] = $decoded;
			}
		}

		return $response;
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	/** @param int[] $ids */
	private static function existingProducts(array $ids): array
	{
		$rows = self::$db->query('SELECT id FROM products WHERE id IN (' . implode(', ', array_map('intval', $ids)) . ') ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

		return array_map('intval', $rows);
	}

	// ------------------------------------------------------------------------------
	// Tests
	// ------------------------------------------------------------------------------

	public function testTheAdultRoleCannotMergeProducts(): void
	{
		$keep = self::insertProduct('Merge Authz Keep (adult)');
		$remove = self::insertProduct('Merge Authz Remove (adult)');

		$response = self::send('POST', "/api/stock/products/$keep/merge/$remove", self::$keys['adult-role']);

		self::assertSame(403, $response['status'], 'STOCK (through the Adult role) must not delete a product by merging it');
		self::assertSame('Permission missing: MASTER_DATA_EDIT', $response['body']['error_message'] ?? null);
		self::assertSame([$keep, $remove], self::existingProducts([$keep, $remove]), 'A refused merge leaves both products in place');
	}

	public function testMasterDataEditMergesProducts(): void
	{
		$keep = self::insertProduct('Merge Authz Keep (master data)');
		$remove = self::insertProduct('Merge Authz Remove (master data)');

		$response = self::send('POST', "/api/stock/products/$keep/merge/$remove", self::$keys['master-data-edit']);

		self::assertSame(204, $response['status'], 'MASTER_DATA_EDIT is the permission the merge requires: ' . json_encode($response['body'] ?? null));
		self::assertSame([$keep], self::existingProducts([$keep, $remove]), 'The merged-away product is deleted');
	}
}
