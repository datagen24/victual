<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The refill routes (ADR-0042) through the whole middleware stack, one process per request
 * (tests/Pgsql/request-subprocess-helper.php), because the acting user is a constant per process.
 * What this adds to ConsumptionRefillServiceTest: the status codes and the error token on the wire,
 * strict JSON types, the shapes and formats a native client decodes, the user setting's range, and
 * that a hidden recipe and a missing one answer the same.
 */
class ConsumptionRefillApiTest extends PgsqlSchemaTestCase
{
	private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';
	private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

	private static PDO $db;
	/** @var array<string,string> plaintext keys */
	private static array $keys = [];
	/** @var array<string,int> */
	private static array $users = [];
	private static int $unit;
	private static int $location;
	private static int $product;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$grants = ['owner' => ['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'], 'editor' => ['STOCK_VIEW'], 'reader' => ['STOCK_VIEW'],
			'stranger' => ['STOCK_VIEW', 'STOCK_CONSUME'], 'nobody' => []];
		$next = 9501;
		foreach ($grants as $name => $names)
		{
			$id = $next++;
			self::$users[$name] = $id;
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'rfapi-$name', 'fixture')");
			foreach ($names as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			self::$keys[$name] = self::key($id, 0);
		}
		self::$keys['readonly'] = self::key(self::$users['owner'], 1);

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('RFAPI tablet') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('RFAPI organizer') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['RFAPI product', self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		self::$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct(self::$product, 30, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
	}

	private static function key(int $userId, int $readOnly): string
	{
		$plaintext = bin2hex(random_bytes(25));
		self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, ?)")
			->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT, $readOnly]);

		return $plaintext;
	}

	/** @return array{status: int, body: mixed, headers: array} */
	private static function send(string $method, string $path, ?string $as, mixed $body = null, ?string $rawBody = null, string $contentType = 'application/json'): array
	{
		$spec = ['method' => $method, 'path' => $path];
		if ($rawBody !== null)
		{
			$spec['rawBody'] = $rawBody;
			$spec['headers']['Content-Type'] = $contentType;
		}
		elseif ($body !== null)
		{
			$spec['body'] = $body;
		}
		if ($as !== null)
		{
			$spec['headers']['VICTUAL-API-KEY'] = self::$keys[$as];
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

	private static function recipe(string $as = 'owner'): int
	{
		$response = self::send('POST', '/api/consumption/recipes', $as, ['name' => 'RFAPI recipe', 'lines' => [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$unit]]]);
		self::assertSame(200, $response['status'], json_encode($response['body']));
		$id = (int)$response['body']['created_object_id'];
		foreach (['editor' => ['edit' => true], 'reader' => []] as $name => $rights)
		{
			$share = self::send('POST', "/api/consumption/recipes/$id/shares", $as, ['user_id' => self::$users[$name]] + $rights);
			self::assertSame(204, $share['status'], json_encode($share['body']));
		}

		return $id;
	}

	private static function fill(int $id, string $date = '2026-01-01', ?int $days = 90, string $as = 'owner'): array
	{
		$response = self::send('POST', "/api/consumption/recipes/$id/refill/fills?as_of=2026-03-01", $as, ['filled_on' => $date, 'supplied_days' => $days]);
		self::assertSame(201, $response['status'], json_encode($response['body']));

		return $response['body'];
	}

	private function allRoutes(int $recipe = 1, int $other = 1): array
	{
		return [
			['GET', "/api/consumption/recipes/$recipe/refill", null],
			['PUT', "/api/consumption/recipes/$recipe/refill", []],
			['POST', "/api/consumption/recipes/$recipe/refill/fills", ['filled_on' => '2026-01-01']],
			['POST', "/api/consumption/recipes/$recipe/refill/fills/$other/void", ['reason' => 'x']],
			['POST', "/api/consumption/recipes/$recipe/refill/orders", ['ordered_on' => '2026-01-01']],
			['POST', "/api/consumption/recipes/$recipe/refill/orders/$other/receive", ['filled_on' => '2026-01-01']],
			['POST', "/api/consumption/recipes/$recipe/refill/orders/$other/cancel", []],
			['GET', '/api/refills', null],
			['GET', '/api/refills/notices', null],
			['POST', '/api/refills/notices/ack', ['notice_key' => "$recipe:due:2026-01-01"]],
		];
	}

	public function testAnUnauthenticatedCallIsRefusedAndAnAccountWithNoGrantsGets403OnEveryRoute(): void
	{
		foreach ($this->allRoutes() as [$method, $path, $body])
		{
			self::assertSame(401, self::send($method, $path, null, $body)['status'], "$method $path unauthenticated");
			self::assertSame(403, self::send($method, $path, 'nobody', $body)['status'], "$method $path with no grants");
		}
	}

	public function testAHiddenRecipeAndAMissingOneAnswerTheSameOnEveryRecipeRoute(): void
	{
		$id = self::recipe();
		self::fill($id);
		$order = self::send('POST', "/api/consumption/recipes/$id/refill/orders?as_of=2026-03-01", 'owner', ['ordered_on' => '2026-03-01'])['body']['open_order']['id'];

		foreach ($this->allRoutes($id, $order) as $index => [$method, $path, $body])
		{
			if (!str_contains($path, '/recipes/'))
			{
				continue;
			}

			$missingPath = str_replace("/recipes/$id/", '/recipes/987654/', $path);
			$hidden = self::send($method, $path, 'stranger', $body);
			$missing = self::send($method, $missingPath, 'stranger', $body);

			self::assertSame(404, $hidden['status'], "$method $path");
			self::assertSame($missing['status'], $hidden['status']);
			self::assertSame($missing['body'], $hidden['body'], "$method $path: the same status and the same body");
			self::assertSame('not_found', $hidden['body']['error']);
		}

		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_refill_fills WHERE recipe_id = $id")->fetchColumn(), 'no refused request wrote');
		self::assertSame('open', self::$db->query("SELECT state FROM consumption_refill_orders WHERE recipe_id = $id")->fetchColumn());
	}

	public function testTheWorkflowOverHttpWithTheWireFormatsAClientDecodes(): void
	{
		$id = self::recipe();

		$empty = self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-12", 'owner');
		self::assertSame(200, $empty['status']);
		self::assertSame(['unknown', 'no_fill', 'client'], [$empty['body']['status'], $empty['body']['estimate']['reason'], $empty['body']['as_of_source']]);

		$state = self::fill($id, '2026-01-01', 90);
		self::assertSame(['recipe_id', 'recipe_name', 'as_of', 'as_of_source', 'status', 'days_overdue', 'current_fill', 'estimate', 'open_order', 'settings', 'explicit_date', 'fills', 'orders'], array_keys($state));
		self::assertSame(['2026-03-18', '2026-03-11', 'fallback', 7, null], array_values($state['estimate']));
		self::assertSame(1, preg_match(self::DATE, $state['current_fill']['filled_on']), 'a fill date is YYYY-MM-DD');
		self::assertSame(1, preg_match(self::INSTANT, $state['fills'][0]['created_at']), 'an instant is RFC 3339 UTC');
		self::assertIsInt($state['current_fill']['supplied_days']);
		self::assertIsInt($state['estimate']['lead_days']);
		self::assertSame(1, preg_match(self::DATE, $state['as_of']));

		$approaching = self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-12", 'owner')['body'];
		self::assertSame(['approaching', null], [$approaching['status'], $approaching['days_overdue']]);

		$settings = self::send('PUT', "/api/consumption/recipes/$id/refill?as_of=2026-03-12", 'owner', ['rule' => ['kind' => 'fixed_interval', 'parameter' => 60], 'warning_lead_days' => 3, 'explicit_reorder_date' => '2026-03-01']);
		self::assertSame(200, $settings['status'], json_encode($settings['body']));
		self::assertSame(['due', 11, 'explicit', '2026-03-01'], [$settings['body']['status'], $settings['body']['days_overdue'], $settings['body']['estimate']['source'], $settings['body']['explicit_date']['reorder_on']]);
		self::assertSame(['rule' => ['kind' => 'fixed_interval', 'parameter' => 60], 'warning_lead_days' => 3], $settings['body']['settings']);

		$order = self::send('POST', "/api/consumption/recipes/$id/refill/orders?as_of=2026-03-12", 'owner', ['ordered_on' => '2026-03-12']);
		self::assertSame(201, $order['status']);
		self::assertSame(['ordered', null], [$order['body']['status'], $order['body']['days_overdue']]);
		self::assertSame(['id', 'ordered_on', 'age_days'], array_keys($order['body']['open_order']));

		self::assertSame(409, self::send('POST', "/api/consumption/recipes/$id/refill/orders", 'owner', ['ordered_on' => '2026-03-13'])['status']);
		self::assertSame('order_open', self::send('POST', "/api/consumption/recipes/$id/refill/orders", 'owner', ['ordered_on' => '2026-03-13'])['body']['error']);

		$orderId = $order['body']['open_order']['id'];
		$received = self::send('POST', "/api/consumption/recipes/$id/refill/orders/$orderId/receive?as_of=2026-03-14", 'owner', ['filled_on' => '2026-03-14', 'supplied_days' => 30]);
		self::assertSame(200, $received['status'], json_encode($received['body']));
		self::assertSame(['ok', '2026-03-14', '2026-05-13', 'rule:fixed_interval', null], [$received['body']['status'], $received['body']['current_fill']['filled_on'], $received['body']['estimate']['reorder_date'], $received['body']['estimate']['source'], $received['body']['open_order']],
			'the rule outlives the fill and is applied to the new one: 2026-03-14 plus 60 days');
		self::assertSame('fixed_interval', $received['body']['settings']['rule']['kind']);
		self::assertNull($received['body']['explicit_date'], 'and the explicit date does not');

		$fillId = $received['body']['current_fill']['id'];
		$voided = self::send('POST', "/api/consumption/recipes/$id/refill/fills/$fillId/void?as_of=2026-03-14", 'owner', ['reason' => 'wrong supplier']);
		self::assertSame(200, $voided['status']);
		self::assertSame('2026-01-01', $voided['body']['current_fill']['filled_on']);
		self::assertSame(1, preg_match(self::INSTANT, $voided['body']['fills'][0]['voided_at']));

		$cancel = self::send('POST', "/api/consumption/recipes/$id/refill/orders", 'owner', ['ordered_on' => '2026-03-15']);
		self::assertSame(201, $cancel['status']);
		$cancelled = self::send('POST', "/api/consumption/recipes/$id/refill/orders/" . $cancel['body']['open_order']['id'] . '/cancel', 'owner');
		self::assertSame([200, 'cancelled'], [$cancelled['status'], $cancelled['body']['orders'][0]['state']]);
	}

	public function testListsNoticesAndAcknowledgementOverHttp(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		$list = self::send('GET', '/api/refills?as_of=2026-03-12', 'owner');
		self::assertSame(200, $list['status']);
		self::assertSame(['as_of', 'as_of_source', 'refills'], array_keys($list['body']));
		$mine = array_values(array_filter($list['body']['refills'], fn($refill) => $refill['recipe_id'] === $id))[0];
		self::assertSame(['recipe_id', 'recipe_name', 'as_of', 'as_of_source', 'status', 'days_overdue', 'current_fill', 'estimate', 'open_order'], array_keys($mine), 'the list carries the summary, not the history');
		self::assertSame('approaching', $mine['status']);

		$notices = self::send('GET', '/api/refills/notices?as_of=2026-03-12', 'owner');
		self::assertSame(['as_of', 'as_of_source', 'notices'], array_keys($notices['body']));
		$notice = array_values(array_filter($notices['body']['notices'], fn($n) => $n['recipe_id'] === $id))[0];
		self::assertSame(['key', 'kind', 'recipe_id', 'recipe_name', 'reorder_date', 'warning_date', 'days_overdue', 'source', 'text'], array_keys($notice));
		self::assertSame([$id . ':approaching:2026-03-18', 'approaching', 'fallback'], [$notice['key'], $notice['kind'], $notice['source']]);

		$ack = self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => $notice['key']]);
		$again = self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => $notice['key']]);
		self::assertSame([200, 200], [$ack['status'], $again['status']]);
		self::assertSame($ack['body'], $again['body']);
		self::assertSame(1, preg_match(self::INSTANT, $ack['body']['acknowledged_at']));
		self::assertSame([], array_values(array_filter(self::send('GET', '/api/refills/notices?as_of=2026-03-12', 'owner')['body']['notices'], fn($n) => $n['recipe_id'] === $id)));

		$other = array_values(array_filter(self::send('GET', '/api/refills/notices?as_of=2026-03-12', 'editor')['body']['notices'], fn($n) => $n['recipe_id'] === $id));
		self::assertCount(1, $other, "the editor's notice is not acknowledged by the owner");

		self::assertSame(400, self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => 'not-a-real-key'])['status']);
		self::assertSame('invalid_notice_key', self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => 'not-a-real-key'])['body']['error']);
		self::assertSame(400, self::send('POST', '/api/refills/notices/ack', 'owner', [])['status']);
		self::assertSame(404, self::send('POST', '/api/refills/notices/ack', 'stranger', ['notice_key' => $notice['key']])['status']);
		self::assertSame(404, self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => '987654:due:2026-03-18'])['status']);
	}

	public function testStatusCodesForRightsValidationAndConflicts(): void
	{
		$id = self::recipe();
		self::fill($id);

		self::assertSame(200, self::send('GET', "/api/consumption/recipes/$id/refill", 'reader')['status'], 'a read share reads');
		self::assertSame(403, self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'reader', ['filled_on' => '2026-02-01'])['status']);
		self::assertSame('right_missing', self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'reader', ['filled_on' => '2026-02-01'])['body']['error']);
		self::assertSame(201, self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'editor', ['filled_on' => '2026-02-01', 'supplied_days' => 30])['status'], 'an edit share writes');

		foreach ([
			['POST', "/api/consumption/recipes/$id/refill/fills", ['supplied_days' => 30], 'date_required'],
			['POST', "/api/consumption/recipes/$id/refill/fills", ['filled_on' => '2026-02-01', 'supplied_days' => '30'], 'invalid_supplied_days'],
			['POST', "/api/consumption/recipes/$id/refill/fills", ['filled_on' => '2026-02-01', 'supplied_days' => 731], 'invalid_supplied_days'],
			['POST', "/api/consumption/recipes/$id/refill/fills", ['filled_on' => '2026-02-30', 'supplied_days' => 30], 'invalid_date'],
			['PUT', "/api/consumption/recipes/$id/refill", ['warning_lead_days' => 61], 'invalid_lead'],
			['PUT', "/api/consumption/recipes/$id/refill", ['warning_lead_days' => -1], 'invalid_lead'],
			['PUT', "/api/consumption/recipes/$id/refill", ['warning_lead_days' => '7'], 'invalid_lead'],
			['PUT', "/api/consumption/recipes/$id/refill", ['rule' => ['kind' => 'fraction_elapsed', 'parameter' => 100]], 'invalid_rule'],
			['PUT', "/api/consumption/recipes/$id/refill", ['explicit_reorder_date' => '2025-12-31'], 'date_before_fill'],
			['PUT', "/api/consumption/recipes/$id/refill", ['colour' => 'red'], 'unknown_field'],
			['POST', "/api/consumption/recipes/$id/refill/orders", [], 'date_required'],
			['POST', "/api/consumption/recipes/$id/refill/fills/987654/void", ['reason' => 'x'], 'fill_not_found'],
			['POST', "/api/consumption/recipes/$id/refill/orders/987654/cancel", [], 'order_not_found'],
		] as [$method, $path, $body, $error])
		{
			$response = self::send($method, $path, 'owner', $body);
			self::assertContains($response['status'], [404, 422], "$method $path");
			self::assertSame($error, $response['body']['error'], "$method $path " . json_encode($body));
			self::assertArrayHasKey('error_message', $response['body']);
		}

		self::assertSame(422, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-02-30", 'owner')['status']);
		self::assertSame('invalid_as_of', self::send('GET', "/api/consumption/recipes/$id/refill?as_of=tomorrow", 'owner')['body']['error']);
		self::assertCount(2, self::send('GET', "/api/consumption/recipes/$id/refill", 'owner')['body']['fills'], 'no refused request recorded a fill');
	}

	public function testTheBodyMustBeAJsonObjectAndNumbersStayNumbers(): void
	{
		$id = self::recipe();

		$list = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', null, '[1,2]');
		self::assertSame([400, 'invalid_request'], [$list['status'], $list['body']['error']]);
		$form = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', null, 'filled_on=2026-01-01', 'application/x-www-form-urlencoded');
		self::assertSame([400, 'invalid_request'], [$form['status'], $form['body']['error']]);

		$typed = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', null, '{"filled_on":"2026-01-01","supplied_days":90}');
		self::assertSame(201, $typed['status'], json_encode($typed['body']));
		self::assertSame(90, $typed['body']['current_fill']['supplied_days']);
		$float = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', null, '{"filled_on":"2026-01-02","supplied_days":90.0}');
		self::assertSame(422, $float['status'], 'a JSON number with a fraction part is not an integer');
	}

	public function testAHostileNoteAndReasonComeBackAsInertJsonText(): void
	{
		$id = self::recipe();
		$hostile = '</script><script>alert(1)</script> \u0000 "quote" \'single\' <img src=x onerror=alert(1)>';
		$state = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', ['filled_on' => '2026-01-01', 'supplied_days' => 30, 'note' => $hostile]);

		self::assertSame(201, $state['status']);
		self::assertSame($hostile, $state['body']['fills'][0]['note']);
		$void = self::send('POST', "/api/consumption/recipes/$id/refill/fills/" . $state['body']['current_fill']['id'] . '/void', 'owner', ['reason' => $hostile]);
		self::assertSame($hostile, $void['body']['fills'][0]['void_reason']);
		$types = array_change_key_case($void['headers'], CASE_LOWER)['content-type'] ?? [];
		self::assertStringContainsString('application/json', implode(',', $types), 'the answer is typed JSON, so no browser renders the stored text as markup');
	}

	public function testAReadOnlyKeyReadsAndCannotWrite(): void
	{
		$id = self::recipe();
		self::fill($id);

		self::assertSame(200, self::send('GET', "/api/consumption/recipes/$id/refill", 'readonly')['status']);
		self::assertSame(200, self::send('GET', '/api/refills/notices', 'readonly')['status']);
		foreach ($this->allRoutes($id, 1) as [$method, $path, $body])
		{
			if ($method === 'GET')
			{
				continue;
			}
			self::assertSame(403, self::send($method, $path, 'readonly', $body)['status'], "$method $path with a read-only key");
		}
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_refill_fills WHERE recipe_id = $id")->fetchColumn());
	}

	public function testTheWarningLeadSettingIsRefusedOutsideZeroToSixtyAndMovesTheLead(): void
	{
		$id = self::recipe();
		self::fill($id, '2026-01-01', 90);

		foreach ([61, -1, 'seven', '7.5', ''] as $value)
		{
			$response = self::send('PUT', '/api/user/settings/refill_warning_lead_days', 'owner', ['value' => $value]);
			self::assertSame(422, $response['status'], json_encode($value));
			self::assertSame('invalid_lead', $response['body']['error']);
		}
		self::assertSame(7, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'owner')['body']['estimate']['lead_days'], 'a refused value stored nothing');

		self::assertSame(204, self::send('PUT', '/api/user/settings/refill_warning_lead_days', 'owner', ['value' => 14])['status']);
		self::assertSame(14, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'owner')['body']['estimate']['lead_days']);
		self::assertSame(7, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'reader')['body']['estimate']['lead_days'], "another user's lead is their own");
		self::assertSame(204, self::send('PUT', '/api/user/settings/refill_warning_lead_days', 'owner', ['value' => '0'])['status']);
		self::assertSame(0, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'owner')['body']['estimate']['lead_days']);

		self::assertSame(204, self::send('DELETE', '/api/user/settings/refill_warning_lead_days', 'owner')['status']);
		self::assertSame(7, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'owner')['body']['estimate']['lead_days']);
		self::assertSame(7, self::send('GET', '/api/user/settings/refill_warning_lead_days', 'owner')['body']['value'], 'the configured default is listed');
	}

	public function testPathIdsBodyFieldsAndAsOfThatNameNothingAreRefusedInsteadOfBeingDropped(): void
	{
		$id = self::recipe();
		self::fill($id);

		foreach (["/api/consumption/recipes/99999999999/refill"] as $path)
		{
			$response = self::send('GET', $path, 'owner');
			self::assertSame([404, 'not_found'], [$response['status'], $response['body']['error']], $path);
		}
		self::assertSame(404, self::send('POST', "/api/consumption/recipes/$id/refill/fills/99999999999/void", 'owner', ['reason' => 'x'])['status']);
		self::assertSame(404, self::send('POST', "/api/consumption/recipes/$id/refill/orders/99999999999/cancel", 'owner')['status']);

		$typo = self::send('POST', "/api/consumption/recipes/$id/refill/fills", 'owner', ['filled_on' => '2026-02-01', 'supplied_day' => 30]);
		self::assertSame([422, 'unknown_field'], [$typo['status'], $typo['body']['error']], 'a misspelled field is not recorded as a fill with no supply');
		self::assertSame(422, self::send('POST', "/api/consumption/recipes/$id/refill/orders", 'owner', ['ordered_on' => '2026-02-01', 'x' => 1])['status']);
		self::assertSame(422, self::send('POST', "/api/consumption/recipes/$id/refill/fills/1/void", 'owner', ['reason' => 'x', 'y' => 1])['status']);
		self::assertSame(422, self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => "$id:due:2026-03-18", 'z' => 1])['status']);
		self::assertCount(1, self::send('GET', "/api/consumption/recipes/$id/refill?as_of=2026-03-01", 'owner')['body']['fills'], 'none of them recorded anything');

		foreach (['as_of=', 'as_of[]=2026-03-01'] as $query)
		{
			$response = self::send('GET', "/api/consumption/recipes/$id/refill?$query", 'owner');
			self::assertSame([422, 'invalid_as_of'], [$response['status'], $response['body']['error']], $query);
		}

		$ack = self::send('POST', '/api/refills/notices/ack', 'owner', ['notice_key' => "000$id:due:2026-03-18"]);
		self::assertSame("$id:due:2026-03-18", $ack['body']['notice_key'], 'the answer is the canonical key');
	}

	public function testWithoutAsOfTheUtcDateIsUsedAndSaid(): void
	{
		$id = self::recipe();
		self::fill($id);

		$before = gmdate('Y-m-d');
		$state = self::send('GET', "/api/consumption/recipes/$id/refill", 'owner')['body'];
		$after = gmdate('Y-m-d');

		self::assertSame('server_utc', $state['as_of_source']);
		self::assertContains($state['as_of'], [$before, $after]);
		self::assertSame('server_utc', self::send('GET', '/api/refills', 'owner')['body']['as_of_source']);
		self::assertSame('server_utc', self::send('GET', '/api/refills/notices', 'owner')['body']['as_of_source']);
	}
}
