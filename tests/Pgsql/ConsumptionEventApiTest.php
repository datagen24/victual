<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The external consumption routes through the whole middleware stack, one process per request
 * (tests/Pgsql/request-subprocess-helper.php), so the acting user is the key's user: status codes and
 * error tokens on the wire, strict types in the body, per-user isolation, key rotation, stock attribution
 * and the generic surfaces staying closed (ADR-0041 rules 1, 10 and 11).
 */
class ConsumptionEventApiTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	/** @var array<string,string> plaintext keys */
	private static array $keys = [];
	/** @var array<string,int> */
	private static array $users = [];
	private static int $unit;
	private static int $location;
	private static int $sequence = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$accounts = ['alice' => ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'], 'alice2' => null, 'bob' => ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'], 'viewer' => ['STOCK_VIEW'], 'nobody' => []];
		$next = 9501;
		foreach ($accounts as $name => $grants)
		{
			if ($grants === null)
			{
				// A second key for alice: a rotated key must find the same events (ADR-0041 rule 1).
				self::$users[$name] = self::$users['alice'];
				self::$keys[$name] = self::key(self::$users['alice']);
				continue;
			}

			$id = $next++;
			self::$users[$name] = $id;
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'ev-$name', 'fixture')");
			foreach ($grants as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			self::$keys[$name] = self::key($id);
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('EV tablet') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('EV organizer') RETURNING id")->fetchColumn();
	}

	private static function key(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
			->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	private static function product(float $onHand): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['EV product ' . ++self::$sequence, self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		$id = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($id, $onHand, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);

		return $id;
	}

	private static function onHand(int $product): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn();
	}

	/** @return array{status: int, body: mixed, headers: array} */
	private static function send(string $method, string $path, ?string $as, $body = null, ?string $rawBody = null, string $contentType = 'application/json'): array
	{
		$spec = ['method' => $method, 'path' => $path];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}
		if ($rawBody !== null)
		{
			$spec['rawBody'] = $rawBody;
		}
		$spec['headers'] = ($as !== null ? ['VICTUAL-API-KEY' => self::$keys[$as]] : []) + ($rawBody !== null ? ['Content-Type' => $contentType] : []);

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

	private static function mapping(int $product, array $override = []): array
	{
		return $override + ['product_id' => $product, 'unit_labels' => ['tablet'], 'location' => ['mode' => 'fixed', 'location_id' => self::$location], 'effective_from' => '2026-01-01T00:00:00Z'];
	}

	private static function event(string $ref, array $extra = []): array
	{
		return $extra + ['status' => 'taken', 'medication_ref' => $ref, 'quantity' => 1, 'unit_label' => 'tablet', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)];
	}

	public function testEveryRouteNeedsAuthenticationAndTheRightPermissions(): void
	{
		$routes = [['GET', '/api/consumption/capabilities', false], ['GET', '/api/consumption/events', false], ['GET', '/api/consumption/events/healthkit/x', false],
			['GET', '/api/consumption/mappings', false], ['GET', '/api/consumption/mappings/healthkit/x', false],
			['PUT', '/api/consumption/events/healthkit/x', true], ['DELETE', '/api/consumption/events/healthkit/x', true], ['POST', '/api/consumption/events/healthkit/x/resolve', true],
			['POST', '/api/consumption/events/batch', true], ['POST', '/api/consumption/events/resolve', true],
			['PUT', '/api/consumption/mappings/healthkit/x', true], ['DELETE', '/api/consumption/mappings/healthkit/x', true]];

		foreach ($routes as [$method, $path, $writes])
		{
			self::assertSame(401, self::send($method, $path, null, $writes && $method !== 'DELETE' ? [] : null)['status'], "$method $path unauthenticated");
			self::assertSame(403, self::send($method, $path, 'nobody', $writes && $method !== 'DELETE' ? [] : null)['status'], "$method $path with no grants");

			if ($writes)
			{
				self::assertSame(403, self::send($method, $path, 'viewer', $method === 'DELETE' ? null : [])['status'], "$method $path needs STOCK_CONSUME");
			}
		}

		self::assertSame(200, self::send('GET', '/api/consumption/events', 'viewer')['status'], 'a viewer reads their own list');
	}

	public function testCapabilitiesAdvertiseOnlyWhatTheServerDoes(): void
	{
		$response = self::send('GET', '/api/consumption/capabilities', 'alice');

		self::assertSame(200, $response['status']);
		self::assertSame(1, $response['body']['contract_version']);
		self::assertSame(['events', 'mappings', 'batch', 'bulk_resolve', 'manual_consume', 'deletion_reasons', 'not_logged', 'default_quantity', 'unit_labels', 'replaces'], $response['body']['features']);
	}

	public function testMappingThenEventThroughTheRoutesBooksOnceAndAttributesTheCaller(): void
	{
		$product = self::product(10);
		$put = self::send('PUT', '/api/consumption/mappings/healthkit/api-med-1', 'alice', self::mapping($product));
		$again = self::send('PUT', '/api/consumption/mappings/healthkit/api-med-1', 'alice', self::mapping($product, ['default_quantity' => 2]));

		self::assertSame([201, 200], [$put['status'], $again['status']], json_encode($put['body']));
		self::assertSame(2, $again['body']['default_quantity']);

		$first = self::send('PUT', '/api/consumption/events/healthkit/api-ev-1', 'alice', self::event('api-med-1'));
		$replay = self::send('PUT', '/api/consumption/events/healthkit/api-ev-1', 'alice', self::event('api-med-1'));

		self::assertSame([201, 'booked'], [$first['status'], $first['body']['state']], json_encode($first['body']));
		self::assertSame([200, true], [$replay['status'], $replay['body']['replayed']]);
		self::assertSame(9.0, self::onHand($product));
		self::assertSame(self::$users['alice'], (int)self::$db->query('SELECT user_id FROM stock_log WHERE transaction_id = ' . self::$db->quote($first['body']['transaction_id']) . ' LIMIT 1')->fetchColumn(), 'the booking is attributed to the caller');
		self::assertNull(self::$db->query('SELECT recipe_id FROM stock_log WHERE transaction_id = ' . self::$db->quote($first['body']['transaction_id']) . ' LIMIT 1')->fetchColumn());
		self::assertArrayNotHasKey('user_id', $first['body']);
		self::assertArrayNotHasKey('id', $first['body']);
	}

	public function testARotatedKeyFindsTheSameEventAndTheKeyIsOnlyAudit(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-2', 'alice', self::mapping($product));

		$first = self::send('PUT', '/api/consumption/events/healthkit/api-ev-2', 'alice', self::event('api-med-2'));
		$second = self::send('PUT', '/api/consumption/events/healthkit/api-ev-2', 'alice2', self::event('api-med-2'));

		self::assertSame(201, $first['status']);
		self::assertSame([200, true], [$second['status'], $second['body']['replayed']], 'another key of the same user is the same identity');
		self::assertSame($first['body']['transaction_id'], $second['body']['transaction_id']);
		self::assertSame(9.0, self::onHand($product));
		$audit = self::$db->query("SELECT k.key_hint FROM consumption_events e JOIN api_keys k ON k.id = e.api_key_id WHERE e.source_event_id = 'api-ev-2'")->fetchColumn();
		self::assertSame(substr(self::$keys['alice'], -4), $audit, 'the key that received the event is recorded');
	}

	public function testAnotherUsersEventAndMappingAreNotFoundAndTheirOwnIdsAreIndependent(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-3', 'alice', self::mapping($product));
		self::send('PUT', '/api/consumption/events/healthkit/api-ev-3', 'alice', self::event('api-med-3'));

		foreach ([['GET', '/api/consumption/events/healthkit/api-ev-3', null], ['DELETE', '/api/consumption/events/healthkit/api-ev-3', null],
			['POST', '/api/consumption/events/healthkit/api-ev-3/resolve', ['action' => 'dismiss']], ['GET', '/api/consumption/mappings/healthkit/api-med-3', null],
			['DELETE', '/api/consumption/mappings/healthkit/api-med-3', null]] as [$method, $path, $body])
		{
			$hidden = self::send($method, $path, 'bob', $body);
			self::assertSame(404, $hidden['status'], "$method $path");
			self::assertSame('not_found', $hidden['body']['error']);
		}

		self::assertSame([], self::send('GET', '/api/consumption/events', 'bob')['body']);
		self::assertSame([], self::send('GET', '/api/consumption/mappings', 'bob')['body']);

		$theirs = self::send('PUT', '/api/consumption/events/healthkit/api-ev-3', 'bob', self::event('api-med-3'));
		self::assertSame([201, 'needs_mapping'], [$theirs['status'], $theirs['body']['state']], 'the same ids for another user are a separate event, with a mapping of their own to approve');
		self::assertSame('booked', self::send('GET', '/api/consumption/events/healthkit/api-ev-3', 'alice')['body']['state']);
	}

	public function testRefusalsCarryTheirStatusAndToken(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-4', 'alice', self::mapping($product));
		self::send('PUT', '/api/consumption/events/healthkit/api-ev-4', 'alice', self::event('api-med-4', ['source_updated_at' => '2026-10-09T10:00:00Z']));

		$cases = [
			['same version', 'PUT', '/api/consumption/events/healthkit/api-ev-4', self::event('api-med-4', ['quantity' => 3, 'source_updated_at' => '2026-10-09T10:00:00Z']), 409, 'same_version_different_payload'],
			['future', 'PUT', '/api/consumption/events/healthkit/api-ev-5', self::event('api-med-4', ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]), 422, 'future_occurred_at'],
			['manual reserved', 'PUT', '/api/consumption/events/manual/api-ev-6', self::event('api-med-4'), 400, 'invalid_request'],
			['bad id', 'PUT', '/api/consumption/events/healthkit/has%20space', self::event('api-med-4'), 400, 'invalid_request'],
			['bad status', 'PUT', '/api/consumption/events/healthkit/api-ev-7', ['status' => 'maybe'], 400, 'invalid_request'],
			['transition', 'POST', '/api/consumption/events/healthkit/api-ev-4/resolve', ['action' => 'void'], 409, 'invalid_transition'],
			['unknown action', 'POST', '/api/consumption/events/healthkit/api-ev-4/resolve', ['action' => 'erase'], 400, 'invalid_request'],
			['bad reason', 'DELETE', '/api/consumption/events/healthkit/api-ev-4?reason=bored', null, 400, 'invalid_request'],
			['mapping both targets', 'PUT', '/api/consumption/mappings/healthkit/api-med-5', self::mapping($product, ['recipe_id' => 1]), 400, 'invalid_request'],
			['mapping manual', 'PUT', '/api/consumption/mappings/manual/api-med-5', self::mapping($product), 400, 'invalid_request'],
			['mapping unknown product', 'PUT', '/api/consumption/mappings/healthkit/api-med-5', self::mapping(987654), 422, 'invalid_mapping'],
			['missing event', 'GET', '/api/consumption/events/healthkit/nope', null, 404, 'not_found'],
		];

		foreach ($cases as [$label, $method, $path, $body, $status, $token])
		{
			$response = self::send($method, $path, 'alice', $body);
			self::assertSame([$status, $token], [$response['status'], $response['body']['error'] ?? null], "$label: " . json_encode($response['body']));
		}

		self::assertSame(9.0, self::onHand($product), 'none of the refusals changed stock');
	}

	public function testTheBodyIsStrictJsonWithRealTypes(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-6', 'alice', self::mapping($product));

		$stringQuantity = self::send('PUT', '/api/consumption/events/healthkit/api-ev-8', 'alice', self::event('api-med-6', ['quantity' => '1']));
		self::assertSame([400, 'invalid_request'], [$stringQuantity['status'], $stringQuantity['body']['error']], 'a quantity is a number, not a numeric string');

		$truncated = self::send('PUT', '/api/consumption/events/healthkit/api-ev-8', 'alice', null, '{"status": "taken"', 'application/json');
		self::assertSame(400, $truncated['status']);

		$wrongType = self::send('PUT', '/api/consumption/events/healthkit/api-ev-8', 'alice', null, 'status=taken', 'text/plain');
		self::assertSame(400, $wrongType['status']);

		$list = self::send('PUT', '/api/consumption/events/healthkit/api-ev-8', 'alice', null, '[1,2]', 'application/json');
		self::assertSame(400, $list['status'], 'a JSON array is not an event');
		self::assertSame(10.0, self::onHand($product));
	}

	public function testBatchReportsEachItemAndABatchOfZeroOrOver50IsRefused(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-7', 'alice', self::mapping($product));

		$batch = self::send('POST', '/api/consumption/events/batch', 'alice', ['events' => [
			['source_system' => 'healthkit', 'source_event_id' => 'api-b-1'] + self::event('api-med-7'),
			['source_system' => 'healthkit', 'source_event_id' => 'api-b-2', 'status' => 'dancing'],
		]]);

		self::assertSame(200, $batch['status']);
		self::assertSame([201, 400], array_column($batch['body'], 'http_status'));
		self::assertSame('booked', $batch['body'][0]['event']['state']);
		self::assertSame('invalid_request', $batch['body'][1]['error']['error']);
		self::assertSame(400, self::send('POST', '/api/consumption/events/batch', 'alice', ['events' => []])['status']);
		self::assertSame(400, self::send('POST', '/api/consumption/events/batch', 'alice', ['events' => array_fill(0, 51, ['source_system' => 'healthkit', 'source_event_id' => 'x'])])['status']);
	}

	public function testTheListFiltersByStateAndMappingDeletionAnswers204(): void
	{
		$product = self::product(10);
		self::send('PUT', '/api/consumption/mappings/healthkit/api-med-8', 'alice', self::mapping($product));
		self::send('PUT', '/api/consumption/events/healthkit/api-l-1', 'alice', self::event('api-med-8'));
		self::send('PUT', '/api/consumption/events/healthkit/api-l-2', 'alice', self::event('api-med-unmapped'));

		$review = self::send('GET', '/api/consumption/events?state=needs_review,needs_mapping', 'alice');
		self::assertSame(['api-l-2'], array_column($review['body'], 'source_event_id'));
		self::assertSame(400, self::send('GET', '/api/consumption/events?state=imaginary', 'alice')['status']);

		self::assertSame(204, self::send('DELETE', '/api/consumption/mappings/healthkit/api-med-8', 'alice')['status']);
		self::assertSame(404, self::send('GET', '/api/consumption/mappings/healthkit/api-med-8', 'alice')['status']);
		self::assertSame('booked', self::send('GET', '/api/consumption/events/healthkit/api-l-1', 'alice')['body']['state'], 'an event that booked stock outlives its mapping');
	}

	public function testGenericRoutesRefuseTheMappingTableToo(): void
	{
		foreach (['consumption_mappings', 'consumption_events', 'consumption_event_lines'] as $table)
		{
			self::assertSame(400, self::send('GET', "/api/objects/$table", 'alice')['status'], $table);
			self::assertSame(400, self::send('GET', "/api/objects/$table/1", 'alice')['status'], $table);
			self::assertSame(400, self::send('GET', "/api/userfields/$table/1", 'alice')['status'], $table);
		}
	}
}
