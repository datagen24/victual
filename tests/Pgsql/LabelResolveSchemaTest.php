<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Contract verification: the OpenAPI spec's label resolve endpoint must declare
 * all six entity kinds that the server actually resolves.
 *
 * When LabelIdentityService::KINDS changes or the spec's kind enum drifts, this
 * test fails, catching mismatches that client generators and strict validators
 * would reject.
 */
class LabelResolveSchemaTest extends PgsqlSchemaTestCase
{
	private const ADMIN_USER = 9610;
	private const OPERATOR_USER = 9611;

	private const OPERATOR_GRANTS = [User::PERMISSION_MASTER_DATA_EDIT, User::PERMISSION_STOCK_VIEW,
		User::PERMISSION_RECIPES_VIEW, User::PERMISSION_CHORES_VIEW, User::PERMISSION_BATTERIES];

	private const BEST_BEFORE = '2099-12-31';
	private const PURCHASED = '2026-01-01';

	private static PDO $db;
	private static string $operatorKey;
	private static array $targets = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ADMIN_USER . ", 'labelresolve-admin', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::OPERATOR_USER . ", 'labelresolve-operator', 'fixture')");

		self::Grant(self::ADMIN_USER, [User::PERMISSION_ADMIN]);
		self::Grant(self::OPERATOR_USER, self::OPERATOR_GRANTS);

		self::$operatorKey = self::IssueUserKey(self::OPERATOR_USER);

		self::SeedTargets();
	}

	private static function Grant(int $userId, array $permissions): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . $userId);
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

		foreach ($permissions as $name)
		{
			$statement->execute([$userId, $name]);
		}
	}

	private static function IssueUserKey(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $key;
	}

	private static function SeedTargets(): void
	{
		$db = self::$db;
		$db->exec("INSERT INTO locations (name, description) VALUES ('Resolve test shelf', 'Test location')");
		self::$targets['location'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, description) VALUES ('Resolve test product', " . self::$targets['location'] . ", 2, 2, 'Test')");
		self::$targets['product'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id)
			VALUES (" . self::$targets['product'] . ", 3, 'resolve-test-stock', '" . self::BEST_BEFORE . "', '" . self::PURCHASED . "', " . self::$targets['location'] . ')');
		self::$targets['stock_entry'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO recipes (name) VALUES ('Resolve test recipe')");
		self::$targets['recipe'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO chores (name, period_type) VALUES ('Resolve test chore', 'manually')");
		self::$targets['chore'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO batteries (name) VALUES ('Resolve test battery')");
		self::$targets['battery'] = (int)$db->lastInsertId();
	}

	/**
	 * The OpenAPI spec must declare all entity kinds that the server resolves.
	 *
	 * Both the `resolved` and `retired` response variants declare `kind` as a const
	 * or enum. This test ensures they match LabelIdentityService::KINDS exactly.
	 */
	public function testOpenApiSpecDeclaresAllLabelKinds(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		self::assertIsArray($spec, 'OpenAPI spec must be valid JSON');

		$labelResolveOperation = $spec['paths']['/labels/resolve/{code}']['get'];
		self::assertIsArray($labelResolveOperation, 'GET /labels/resolve/{code} must exist in spec');

		$responses = $labelResolveOperation['responses']['200']['content']['application/json']['schema'];
		$variants = $responses['oneOf'];
		self::assertCount(3, $variants, 'Response must have exactly 3 variants: unknown, resolved, retired');

		$resolvedVariant = $variants[1];
		self::assertSame('resolved', $resolvedVariant['properties']['status']['const'], 'Variant 1 must be resolved');
		$resolvedKind = $resolvedVariant['properties']['kind'];

		$retiredVariant = $variants[2];
		self::assertSame('retired', $retiredVariant['properties']['status']['const'], 'Variant 2 must be retired');
		$retiredKind = $retiredVariant['properties']['kind'];

		// Get the server's kinds via reflection to avoid duplicating the list
		$reflection = new \ReflectionClass(LabelIdentityService::class);
		$kindsConstant = $reflection->getConstant('KINDS');
		self::assertIsArray($kindsConstant, 'LabelIdentityService must have KINDS array');

		$serverKinds = array_flip($kindsConstant);

		// Both the resolved and retired response variants must declare the same kinds
		if (isset($resolvedKind['enum']))
		{
			$specKinds = array_flip($resolvedKind['enum']);
		}
		else
		{
			// Fall back to const if enum is not present (this is the buggy state)
			$specKinds = [$resolvedKind['const'] => true];
		}

		self::assertSame($serverKinds, $specKinds,
			'OpenAPI spec resolved.kind must declare exactly the kinds in LabelIdentityService::KINDS');

		if (isset($retiredKind['enum']))
		{
			$specRetiredKinds = array_flip($retiredKind['enum']);
		}
		else
		{
			// Fall back to const if enum is not present (this is the buggy state)
			$specRetiredKinds = [$retiredKind['const'] => true];
		}

		self::assertSame($serverKinds, $specRetiredKinds,
			'OpenAPI spec retired.kind must declare exactly the kinds in LabelIdentityService::KINDS');
	}

	/**
	 * Verify the actual response structure when resolving labels of each kind.
	 */
	public function testResolveLabelReturnsCorrectKindAndTarget(): void
	{
		// Issue labels for each kind
		$labels = [];
		foreach (['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind)
		{
			$targetId = self::$targets[$kind];
			$uid = $this->IssueLabel($kind, $targetId);
			$labels[$kind] = $uid;
		}

		// Resolve each and verify kind and target structure
		foreach ($labels as $kind => $uid)
		{
			$response = $this->ResolveLabel($uid);
			self::assertSame(200, $response['status'], "Resolution of $kind label failed");

			$json = json_decode($response['body'], true);
			self::assertSame('resolved', $json['status'], "$kind label should be resolved");
			self::assertSame($kind, $json['kind'], "$kind label must return correct kind");
			self::assertIsArray($json['target'], "$kind label target must be an object");
			self::assertArrayHasKey('id', $json['target'], "$kind target must have id");
			self::assertArrayHasKey('name', $json['target'], "$kind target must have name");
			self::assertArrayHasKey('path', $json['target'], "$kind target must have path");

			// Verify target.id is the location row id for stock_entry (not the stock_id string)
			if ($kind === 'stock_entry')
			{
				self::assertIsInt($json['target']['id'], 'stock_entry target.id must be an integer (row id)');
				self::assertSame(self::$targets['stock_entry'], $json['target']['id'],
					'stock_entry target.id must be the stock.id row id');
			}
		}
	}

	private function IssueLabel(string $kind, int $targetId): string
	{
		self::$db->beginTransaction();
		try
		{
			$statement = self::$db->prepare('SELECT epoch FROM label_import_state WHERE id = 1');
			$statement->execute();
			$epoch = (int)$statement->fetchColumn();

			$service = new LabelIdentityService(self::$db);
			$uid = $service->Issue($kind, $targetId, $epoch);

			self::$db->commit();
			return $uid;
		}
		catch (\Exception $e)
		{
			self::$db->rollBack();
			throw $e;
		}
	}

	private function ResolveLabel(string $uid): array
	{
		$spec = ['method' => 'GET', 'path' => '/api/labels/resolve/' . $uid];
		$spec['headers'] = ['VICTUAL-API-KEY' => self::$operatorKey];

		$specFile = tempnam(getenv('VICTUAL_DATAPATH') ?: sys_get_temp_dir(), 'labelresolve-');
		file_put_contents($specFile, json_encode($spec));

		$environment = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($environment, [
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
			[PHP_BINARY, __DIR__ . '/labelapi-subprocess-helper.php', '@' . $specFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		unlink($specFile);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "Request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['body'] = base64_decode($result['body_base64'], true);

		return $result;
	}
}
