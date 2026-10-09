<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The consumption recipe routes through the whole middleware stack, one process per request
 * (tests/Pgsql/request-subprocess-helper.php), because the acting user is a constant per process.
 * What this adds to ConsumptionRecipeServiceTest: the status codes on the wire, the error token
 * body, who stock_log attributes a booking to, and that a hidden recipe and a missing one are
 * indistinguishable (ADR-0040 rule 7).
 */
class ConsumptionRecipeApiTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	/** @var array<string,string> plaintext keys */
	private static array $keys = [];
	/** @var array<string,int> */
	private static array $users = [];
	private static int $unit;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$grants = ['owner' => ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'], 'member' => ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'],
			'stranger' => ['STOCK_VIEW', 'STOCK_CONSUME'], 'viewer' => ['STOCK_VIEW'], 'nobody' => []];
		$next = 9301;
		foreach ($grants as $name => $names)
		{
			$id = $next++;
			self::$users[$name] = $id;
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'api-$name', 'fixture')");
			foreach ($names as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			$plaintext = bin2hex(random_bytes(25));
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
				->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			self::$keys[$name] = $plaintext;
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('API tablet') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('API organizer') RETURNING id")->fetchColumn();
	}

	private static function product(float $onHand): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['API product ' . bin2hex(random_bytes(3)), self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		$id = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($id, $onHand, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);

		return $id;
	}

	private static function onHand(int $product): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn();
	}

	/** @return array{status: int, body: mixed, headers: array} */
	private static function send(string $method, string $path, ?string $as, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}
		if ($as !== null)
		{
			$spec['headers'] = ['VICTUAL-API-KEY' => self::$keys[$as]];
		}

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$response = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($response, "the request helper printed no JSON. stdout: $output stderr: $errors");
		$decoded = json_decode((string)$response['body'], true);
		$response['body'] = is_array($decoded) ? $decoded : $response['body'];

		return $response;
	}

	private static function createRecipe(string $as, int $product, float $amount = 2): int
	{
		$response = self::send('POST', '/api/consumption/recipes', $as, ['name' => 'API recipe', 'lines' => [['product_id' => $product, 'amount' => $amount, 'qu_id' => self::$unit]]]);
		self::assertSame(200, $response['status'], json_encode($response['body']));

		return (int)$response['body']['created_object_id'];
	}

	public function testAnUnauthenticatedCallIsRefusedAndAnAccountWithNoGrantsGets403OnEveryRoute(): void
	{
		self::assertSame(401, self::send('GET', '/api/consumption/recipes', null)['status']);

		foreach ([['GET', '/api/consumption/recipes'], ['POST', '/api/consumption/recipes'], ['GET', '/api/consumption/recipes/1'], ['PUT', '/api/consumption/recipes/1'],
			['DELETE', '/api/consumption/recipes/1'], ['POST', '/api/consumption/recipes/1/consume'], ['GET', '/api/consumption/recipes/1/events'],
			['POST', '/api/consumption/recipes/1/events/1/undo'], ['GET', '/api/consumption/recipes/1/shares'], ['POST', '/api/consumption/recipes/1/shares'],
			['PUT', '/api/consumption/recipes/1/shares/1'], ['DELETE', '/api/consumption/recipes/1/shares/1'], ['POST', '/api/consumption/recipes/1/transfer']] as [$method, $path])
		{
			self::assertSame(403, self::send($method, $path, 'nobody', $method === 'GET' || $method === 'DELETE' ? null : [])['status'], "$method $path with no grants");
		}
	}

	public function testAHiddenRecipeAndAMissingOneAnswerTheSame(): void
	{
		$id = self::createRecipe('owner', self::product(10));

		$hidden = self::send('GET', "/api/consumption/recipes/$id", 'stranger');
		$missing = self::send('GET', '/api/consumption/recipes/987654', 'stranger');

		self::assertSame(404, $hidden['status']);
		self::assertSame($missing['status'], $hidden['status']);
		self::assertSame($missing['body'], $hidden['body'], 'the same status and the same body');
		self::assertSame('not_found', $hidden['body']['error']);
		self::assertSame([], self::send('GET', '/api/consumption/recipes', 'stranger')['body'], "a stranger's list is empty");
		self::assertSame(404, self::send('POST', "/api/consumption/recipes/$id/consume", 'stranger', [])['status']);
		self::assertSame(404, self::send('POST', '/api/consumption/recipes/987654/consume', 'stranger', [])['status']);
	}

	public function testGenericRoutesRefuseThePrivateTables(): void
	{
		foreach (['consumption_recipes', 'consumption_recipe_lines', 'consumption_recipe_shares', 'consumption_events', 'consumption_event_lines'] as $table)
		{
			self::assertSame(400, self::send('GET', "/api/objects/$table", 'owner')['status'], $table);
			self::assertSame(400, self::send('GET', "/api/objects/$table/1", 'owner')['status'], $table);
		}
	}

	public function testConsumeThroughTheRouteBooksOnceAttributesTheCallerAndKeepsRecipeIdOutOfTheLedger(): void
	{
		$product = self::product(10);
		$id = self::createRecipe('owner', $product, 2);
		self::assertSame(204, self::send('POST', "/api/consumption/recipes/$id/shares", 'owner', ['username' => 'api-member', 'consume' => true])['status']);

		$first = self::send('POST', "/api/consumption/recipes/$id/consume", 'member', ['request_id' => 'http-1']);
		$again = self::send('POST', "/api/consumption/recipes/$id/consume", 'member', ['request_id' => 'http-1']);

		self::assertSame(201, $first['status'], json_encode($first['body']));
		self::assertSame(200, $again['status']);
		self::assertTrue($again['body']['replayed']);
		self::assertSame(8.0, self::onHand($product));
		$row = self::$db->query('SELECT user_id, recipe_id FROM stock_log WHERE transaction_id = ' . self::$db->quote($first['body']['transaction_id']))->fetch(PDO::FETCH_ASSOC);
		self::assertSame(self::$users['member'], (int)$row['user_id'], 'the booking is attributed to the caller');
		self::assertNull($row['recipe_id'], 'stock_log.recipe_id stays null');
	}

	public function testRefusalsCarryTheirStatusAndToken(): void
	{
		$product = self::product(1);
		$id = self::createRecipe('owner', $product, 5);

		$short = self::send('POST', "/api/consumption/recipes/$id/consume", 'owner', ['request_id' => 'short']);
		self::assertSame([409, 'stock_refused'], [$short['status'], $short['body']['error']]);
		self::assertSame(1.0, self::onHand($product));

		self::assertSame([403, 'permission_missing'], (function ()
		{
			$r = self::send('POST', '/api/consumption/recipes', 'viewer', ['name' => 'x', 'lines' => []]);
			return [$r['status'], $r['body']['error_message'] === 'Permission missing: STOCK_CONSUME' ? 'permission_missing' : $r['body']['error_message']];
		})());

		$invalid = self::send('POST', '/api/consumption/recipes', 'owner', ['name' => ' ', 'lines' => [['product_id' => $product, 'amount' => 1, 'qu_id' => self::$unit]]]);
		self::assertSame([422, 'invalid_name'], [$invalid['status'], $invalid['body']['error']]);

		self::assertSame(204, self::send('POST', "/api/consumption/recipes/$id/shares", 'owner', ['user_id' => self::$users['member']])['status']);
		self::assertSame([403, 'right_missing'], (function () use ($id)
		{
			$r = self::send('POST', "/api/consumption/recipes/$id/consume", 'member', ['request_id' => 'norights']);
			return [$r['status'], $r['body']['error']];
		})());
	}

	public function testEditShareRevokeAndTransferThroughTheRoutes(): void
	{
		$product = self::product(10);
		$id = self::createRecipe('owner', $product);

		self::assertSame(204, self::send('POST', "/api/consumption/recipes/$id/shares", 'owner', ['username' => 'api-member', 'consume' => true, 'edit' => true])['status']);
		self::assertSame(204, self::send('PUT', "/api/consumption/recipes/$id", 'member', ['note' => 'by member'])['status']);
		self::assertSame('by member', self::send('GET', "/api/consumption/recipes/$id", 'owner')['body']['note']);
		self::assertSame(['consume' => true, 'edit' => true], array_intersect_key(array_filter(self::send('GET', "/api/consumption/recipes/$id/shares", 'owner')['body'][0]['rights']), ['consume' => 1, 'edit' => 1]));

		self::assertSame(204, self::send('PUT', "/api/consumption/recipes/$id/shares/" . self::$users['member'], 'owner', ['consume' => true])['status']);
		self::assertSame(403, self::send('PUT', "/api/consumption/recipes/$id", 'member', ['note' => 'again'])['status'], 'the edit right was withdrawn');

		self::assertSame(204, self::send('POST', "/api/consumption/recipes/$id/transfer", 'owner', ['user_id' => self::$users['member']])['status']);
		self::assertTrue(self::send('GET', "/api/consumption/recipes/$id", 'member')['body']['is_owner']);
		self::assertSame(204, self::send('DELETE', "/api/consumption/recipes/$id/shares/" . self::$users['owner'], 'member')['status']);
		self::assertSame(404, self::send('GET', "/api/consumption/recipes/$id", 'owner')['status'], 'the former owner is out once the new owner removes them');
		self::assertSame(204, self::send('DELETE', "/api/consumption/recipes/$id", 'member')['status']);
		self::assertSame(404, self::send('GET', "/api/consumption/recipes/$id", 'member')['status']);
	}

	public function testUndoThroughTheRouteRestoresTheRecordedBookings(): void
	{
		$product = self::product(10);
		$id = self::createRecipe('owner', $product, 3);
		$event = self::send('POST', "/api/consumption/recipes/$id/consume", 'owner', ['request_id' => 'undo-http']);
		self::assertSame(7.0, self::onHand($product));

		$undone = self::send('POST', "/api/consumption/recipes/$id/events/" . $event['body']['id'] . '/undo', 'owner');

		self::assertSame(200, $undone['status'], json_encode($undone['body']));
		self::assertSame('undone', $undone['body']['state']);
		self::assertSame(10.0, self::onHand($product));
		self::assertSame(409, self::send('POST', "/api/consumption/recipes/$id/events/" . $event['body']['id'] . '/undo', 'owner')['status']);
	}
}
