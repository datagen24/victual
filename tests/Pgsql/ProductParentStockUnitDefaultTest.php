<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #553 (maintainer decision D4): creating a product with a parent_product_id and no
 * qu_id_stock of its own now defaults qu_id_stock to the parent's own stock unit, via
 * GenericEntityApiController::AddObject()'s 'products' branch. The default is not enforced -
 * a caller that supplies its own qu_id_stock alongside parent_product_id keeps it untouched,
 * and the column remains freely editable afterwards (PUT is unaffected: this method only
 * ever creates).
 *
 * Runs at HTTP level through tests/Pgsql/request-subprocess-helper.php, like
 * ApiInputShapesTest.php, because the default lives in AddObject()'s own request-body
 * handling rather than anywhere a direct StockService call would exercise.
 */
class ProductParentStockUnitDefaultTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9200;

	private static PDO $db;
	private static string $apiKey;
	private static int $locationId;
	private static int $parentQuId;
	private static int $otherQuId;
	private static int $parentProductId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::USER_ID . ", 'parent-stock-unit-default-caller', 'fixture')");

		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		foreach (['MASTER_DATA_EDIT', 'STOCK_VIEW'] as $permission)
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

		$locationStatement = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$locationStatement->execute(['Parent Stock Unit Default Location']);
		self::$locationId = (int)$locationStatement->fetchColumn();

		$quStatement = self::$db->prepare('INSERT INTO quantity_units(name, name_plural) VALUES (?, ?) RETURNING id');
		$quStatement->execute(['Parent Stock Unit Default QU', 'Parent Stock Unit Default QUs']);
		self::$parentQuId = (int)$quStatement->fetchColumn();
		$quStatement->execute(['Other Stock Unit Default QU', 'Other Stock Unit Default QUs']);
		self::$otherQuId = (int)$quStatement->fetchColumn();

		$productStatement = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$productStatement->execute(['Parent Stock Unit Default Parent', self::$locationId, self::$parentQuId, self::$parentQuId, self::$parentQuId, self::$parentQuId]);
		self::$parentProductId = (int)$productStatement->fetchColumn();
	}

	private static function Request(string $method, string $path, ?array $body = null): array
	{
		$spec = [
			'method' => $method,
			'path' => $path,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey, 'Content-Type' => 'application/json'],
		];

		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		return self::Send($spec);
	}

	/** @return array{status: int, body: string} */
	private static function Send(array $spec): array
	{
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

		$requestLabel = ($spec['method'] ?? '?') . ' ' . ($spec['path'] ?? '?');

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $requestLabel. stdout: $output\nstderr: $errors");

		return $result;
	}

	public function testCreatingAProductWithAParentAndNoStockUnitDefaultsToTheParentsOwn(): void
	{
		// Given/When: a sub product created with only parent_product_id, no qu_id_stock.
		$result = self::Request('POST', '/api/objects/products', [
			'name' => 'Parent Stock Unit Default Child (inherits)',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$parentQuId,
			'qu_id_consume' => self::$parentQuId,
			'qu_id_price' => self::$parentQuId,
			'parent_product_id' => self::$parentProductId,
		]);

		self::assertSame(200, $result['status'], (string)$result['body']);
		$created = json_decode((string)$result['body'], true);

		// Then: the row lands in the database with the parent's own qu_id_stock, and it
		// remains a freely editable field afterwards.
		$stmt = self::$db->prepare('SELECT qu_id_stock FROM products WHERE id = ?');
		$stmt->execute([$created['created_object_id']]);
		self::assertSame(self::$parentQuId, (int)$stmt->fetchColumn());
	}

	public function testCreatingAProductWithAParentAndAnExplicitStockUnitKeepsTheCallersChoice(): void
	{
		// Given/When: a sub product created with parent_product_id AND its own qu_id_stock,
		// deliberately different from the parent's.
		$result = self::Request('POST', '/api/objects/products', [
			'name' => 'Parent Stock Unit Default Child (explicit)',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$otherQuId,
			'qu_id_stock' => self::$otherQuId,
			'qu_id_consume' => self::$otherQuId,
			'qu_id_price' => self::$otherQuId,
			'parent_product_id' => self::$parentProductId,
		]);

		self::assertSame(200, $result['status'], (string)$result['body']);
		$created = json_decode((string)$result['body'], true);

		// Then: the default never overrides the caller's own explicit choice.
		$stmt = self::$db->prepare('SELECT qu_id_stock FROM products WHERE id = ?');
		$stmt->execute([$created['created_object_id']]);
		self::assertSame(self::$otherQuId, (int)$stmt->fetchColumn());
	}

	public function testCreatingAProductWithNoParentIsUnaffected(): void
	{
		// Given/When: a plain top-level product, no parent_product_id at all.
		$result = self::Request('POST', '/api/objects/products', [
			'name' => 'Parent Stock Unit Default Standalone',
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$otherQuId,
			'qu_id_stock' => self::$otherQuId,
			'qu_id_consume' => self::$otherQuId,
			'qu_id_price' => self::$otherQuId,
		]);

		self::assertSame(200, $result['status'], (string)$result['body']);
		$created = json_decode((string)$result['body'], true);

		$stmt = self::$db->prepare('SELECT qu_id_stock, parent_product_id FROM products WHERE id = ?');
		$stmt->execute([$created['created_object_id']]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		self::assertSame(self::$otherQuId, (int)$row['qu_id_stock']);
		self::assertNull($row['parent_product_id']);
	}
}
