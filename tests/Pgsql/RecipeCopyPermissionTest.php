<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\RecipesService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #514 (part of #487 audit M14): Recipe copy endpoint requires permission checks.
 *
 * The POST /api/recipes/{id}/copy endpoint must check:
 * - PERMISSION_RECIPES_VIEW to read the source recipe
 * - PERMISSION_RECIPES to create the copy
 *
 * Without these checks, a zero-grant API key can copy recipes (200) while reading them
 * returns 403 (the intended behaviour). This test validates the fix.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php): the
 * authentication middleware define()s the acting user, and PHP cannot redefine a constant.
 */
class RecipeCopyPermissionTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static RecipesService $recipes;

	/** @var array<string,string> plaintext keys by name */
	private static array $keys = [];

	/** @var array<string,int> fixture recipe ids */
	private static array $recipeIds = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$recipes = RecipesService::GetInstance();

		// Create fixture users with different permission levels
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9500, 'zero-grants', 'fixture'), (9501, 'recipe-reader', 'fixture'), (9502, 'recipe-editor', 'fixture')");

		// User 9501: RECIPES_VIEW only (can read, cannot create)
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		$grant->execute([9501, 'RECIPES_VIEW']);

		// User 9502: RECIPES (can create, implies RECIPES_VIEW via hierarchy)
		$grant->execute([9502, 'RECIPES']);

		// Create API keys
		self::$keys['zero-grants'] = self::issueKey(9500);
		self::$keys['recipe-reader'] = self::issueKey(9501);
		self::$keys['recipe-editor'] = self::issueKey(9502);

		// Create test recipes
		self::$recipeIds['source'] = self::$db->query('INSERT INTO recipes(name) VALUES (\'source-recipe\') RETURNING id')->fetchColumn();
	}

	/** Inserts a key row the way ApiKeyService::CreateApiKey() stores one, and returns its plaintext. */
	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, ?array $body = null, ?string $apiKey = null): array
	{
		$spec = ['method' => $method, 'path' => $path];
		if ($body !== null) $spec['body'] = $body;
		if ($apiKey !== null) $spec['headers'] = ['VICTUAL-API-KEY' => $apiKey];

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

	public function testZeroGrantUserCannotRead(): void
	{
		$response = self::send('GET', '/api/objects/recipes/' . self::$recipeIds['source'], null, self::$keys['zero-grants']);
		self::assertSame(403, $response['status'], 'Zero-grant user should not read recipes (control test)');
	}

	public function testZeroGrantUserCannotCopy(): void
	{
		$beforeCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();

		$response = self::send('POST', '/api/recipes/' . self::$recipeIds['source'] . '/copy', null, self::$keys['zero-grants']);
		self::assertSame(403, $response['status'], 'Zero-grant user should get 403 on copy');

		$afterCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
		self::assertSame($beforeCount, $afterCount, 'No recipe rows created on permission refusal');

		// Also verify no related rows were created
		$posCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes_pos')->fetchColumn();
		self::assertSame(0, $posCount, 'No recipes_pos rows created');

		$nestingCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes_nestings')->fetchColumn();
		self::assertSame(0, $nestingCount, 'No recipes_nestings rows created');
	}

	public function testReaderCannotCopy(): void
	{
		$beforeCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();

		// User 9501 can read but not create
		$response = self::send('POST', '/api/recipes/' . self::$recipeIds['source'] . '/copy', null, self::$keys['recipe-reader']);
		self::assertSame(403, $response['status'], 'Recipe reader without write permission should get 403 on copy');

		$afterCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
		self::assertSame($beforeCount, $afterCount, 'No recipe rows created on permission refusal');
	}

	public function testEditorCanCopy(): void
	{
		$beforeCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();

		// User 9502 has RECIPES permission (can create and read)
		$response = self::send('POST', '/api/recipes/' . self::$recipeIds['source'] . '/copy', null, self::$keys['recipe-editor']);
		self::assertSame(200, $response['status'], 'Recipe editor should get 200 on copy: ' . json_encode($response['body']));

		$afterCount = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
		self::assertSame($beforeCount + 1, $afterCount, 'One recipe row created by authorized copy');

		// Verify the response has created_object_id
		self::assertIsArray($response['body'], 'Response body should be JSON object');
		self::assertArrayHasKey('created_object_id', $response['body'], 'Response should include created_object_id');
		self::assertTrue(is_int($response['body']['created_object_id']) || is_numeric($response['body']['created_object_id']), 'created_object_id should be integer or numeric');
	}
}
