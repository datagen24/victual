<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #869: the generic read of custom-entity rows was open to any authenticated caller.
 *
 * ADR-0035 gates the custom-entity pages on MASTER_DATA_EDIT; GET /api/objects/userobjects
 * returned the same rows to a caller holding no permission. The definitions (userentities,
 * userfields) stay open because ordinary pages need them to render user-field inputs.
 * The userfiles file group also stays open until #905: file and image user fields render
 * through /api/files/userfiles/ on pages that need only STOCK_VIEW or EQUIPMENT_VIEW, which
 * the last test guards against a blunt gate.
 *
 * Every request is its own process (request-subprocess-helper.php): the authentication
 * middleware define()s the acting user, and PHP cannot redefine a constant.
 */
class CustomEntityGenericReadsTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	/** @var array<string,string> plaintext keys by name */
	private static array $keys = [];

	private static int $objectId;
	private static int $entityId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9600, 'ce-zero', 'fixture'), (9601, 'ce-stock-viewer', 'fixture'), (9602, 'ce-master-data', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		$grant->execute([9601, 'STOCK_VIEW']);
		$grant->execute([9602, 'MASTER_DATA_EDIT']);
		foreach ([9600 => 'zero', 9601 => 'stock', 9602 => 'editor'] as $userId => $name)
		{
			self::$keys[$name] = self::issueKey($userId);
		}

		self::$entityId = (int)self::$db->query("INSERT INTO userentities(name, caption) VALUES ('ce-garage', 'Garage') RETURNING id")->fetchColumn();
		self::$objectId = (int)self::$db->query('INSERT INTO userobjects(userentity_id) VALUES (' . self::$entityId . ') RETURNING id')->fetchColumn();
		self::$db->exec("INSERT INTO userfields(entity, name, caption, type) VALUES ('ce-garage', 'ce_note', 'Note', 'text-single-line'), ('products', 'ce_manual', 'Manual', 'file')");
	}

	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path, string $apiKey, ?string $rawBody = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey]];
		if ($rawBody !== null)
		{
			$spec['rawBody'] = $rawBody;
			$spec['headers']['Content-Type'] = 'application/octet-stream';
		}

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
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$response = json_decode($output, true);
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

	public function testCallerWithoutMasterDataEditCannotReadUserobjects(): void
	{
		foreach (['zero', 'stock'] as $who)
		{
			$list = self::send('GET', '/api/objects/userobjects', self::$keys[$who]);
			self::assertSame(403, $list['status'], "$who: the list is refused");
			self::assertStringNotContainsString('userentity_id', is_string($list['body']) ? $list['body'] : json_encode($list['body']), "$who: and carries no row data");

			$one = self::send('GET', '/api/objects/userobjects/' . self::$objectId, self::$keys[$who]);
			self::assertSame(403, $one['status'], "$who: a single row is refused");
			self::assertStringNotContainsString('userentity_id', is_string($one['body']) ? $one['body'] : json_encode($one['body']), "$who: and carries no row data");
		}
	}

	public function testCallerWithMasterDataEditReadsUserobjects(): void
	{
		$list = self::send('GET', '/api/objects/userobjects', self::$keys['editor']);
		self::assertSame(200, $list['status']);
		self::assertContains(self::$objectId, array_map(fn ($row) => (int)$row['id'], $list['body']));

		$one = self::send('GET', '/api/objects/userobjects/' . self::$objectId, self::$keys['editor']);
		self::assertSame(200, $one['status']);
		self::assertSame(self::$entityId, (int)$one['body']['userentity_id']);
	}

	public function testDefinitionsStayReadableByAnyAuthenticatedCaller(): void
	{
		$entities = self::send('GET', '/api/objects/userentities', self::$keys['zero']);
		self::assertSame(200, $entities['status']);
		self::assertContains('ce-garage', array_column($entities['body'], 'name'));

		$fields = self::send('GET', '/api/objects/userfields', self::$keys['zero']);
		self::assertSame(200, $fields['status']);
		self::assertContains('ce_manual', array_column($fields['body'], 'name'));
	}

	public function testStockViewerStillFetchesAFileReferencedFromAProductUserfield(): void
	{
		// Guard for #905: ordinary pages (stock overview, product form) link file and image
		// user fields to /api/files/userfiles/. Gating the group bluntly on MASTER_DATA_EDIT
		// would break them for a STOCK_VIEW caller, so the group stays open until reads follow
		// the owning record.
		$fileName = base64_encode('manual.txt');
		$upload = self::send('PUT', '/api/files/userfiles/' . $fileName, self::$keys['editor'], 'product manual bytes');
		self::assertSame(204, $upload['status'], 'fixture upload: ' . json_encode($upload['body'] ?? null));

		$productId = (int)self::$db->query("INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) SELECT 'ce-product', (SELECT MIN(id) FROM locations), MIN(id), MIN(id) FROM quantity_units RETURNING id")->fetchColumn();
		$fieldId = (int)self::$db->query("SELECT id FROM userfields WHERE entity = 'products' AND name = 'ce_manual'")->fetchColumn();
		self::$db->prepare('INSERT INTO userfield_values(field_id, object_id, value) VALUES (?, ?, ?)')->execute([$fieldId, (string)$productId, $fileName]);

		$products = self::send('GET', '/api/objects/products/' . $productId, self::$keys['stock']);
		self::assertSame(200, $products['status'], 'the STOCK_VIEW caller reads the product');

		$file = self::send('GET', '/api/files/userfiles/' . $fileName, self::$keys['stock']);
		self::assertSame(200, $file['status'], 'and the file its user field references');
		self::assertSame('product manual bytes', $file['body']);
	}
}
