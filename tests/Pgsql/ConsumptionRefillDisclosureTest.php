<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\CalendarService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\ConsumptionRefillService;
use Victual\Services\Mqtt\StateSnapshotAssembler;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0042 section 6: refill data is not published through MQTT, InfluxDB, a webhook or the calendar feed, and
 * ADR-0040 rule 1 keeps it out of every generic surface. The generic object and userfield routes are
 * ConsumptionSchemaTest; the three label kinds an organizer household prints are OrganizerLabelDisclosureTest; this
 * class reads the integration surfaces: the calendar events and the iCal feed, the MQTT state snapshot, the outbox
 * that carries MQTT and InfluxDB events, and the routes an ordinary stock reader and a calendar reader call.
 *
 * Victual has no webhook sender and no data export route in this tree (bin/ holds the migrate, import, publish and
 * maintenance commands), so nothing exists to read for those two; the importer's side is
 * ImporterIntegrityTest. A positive control comes first: the distinctive strings are stored in the refill tables, so
 * their absence elsewhere means the surface left them out and not that the fixture never wrote them.
 */
class ConsumptionRefillDisclosureTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9801;
	private const READER = 9802;
	private const ADMIN = 9803;

	private static PDO $db;
	private static int $recipe;
	private static string $name;
	private static string $note;
	private static string $reason;
	/** @var array<string,string> */
	private static array $keys = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		foreach ([self::OWNER => 'owner', self::READER => 'reader', self::ADMIN => 'admin'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'rfd-$name', 'fixture')");
			self::$keys[$name] = self::key($id);
		}
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9801, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9802, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'CALENDAR_VIEW')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9803, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('rfd tablet') RETURNING id")->fetchColumn();
		$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('rfd cabinet') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['rfd pills', $location, $unit, $unit, $unit, $unit]);
		$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($product, 30, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $location);

		self::$name = 'Private refill recipe ' . bin2hex(random_bytes(4));
		self::$note = 'Private refill note ' . bin2hex(random_bytes(4));
		self::$reason = 'Private void reason ' . bin2hex(random_bytes(4));
		$recipes = ConsumptionRecipeService::GetInstance();
		self::$recipe = $recipes->CreateRecipe(self::$name, null, [['product_id' => $product, 'amount' => 1, 'qu_id' => $unit]], self::OWNER);
		$recipes->SetShare(self::$recipe, self::READER, [], self::OWNER);

		// The reorder date lands near today, where a date window on a feed would include it.
		$today = gmdate('Y-m-d');
		$refill = ConsumptionRefillService::GetInstance();
		$wrong = $refill->RecordFill(self::$recipe, ['filled_on' => gmdate('Y-m-d', time() - 200 * 86400), 'supplied_days' => 30, 'note' => 'an earlier fill'], $today, self::OWNER)['current_fill']['id'];
		$refill->RecordFill(self::$recipe, ['filled_on' => gmdate('Y-m-d', time() - 80 * 86400), 'supplied_days' => 90, 'note' => self::$note], $today, self::OWNER);
		$refill->VoidFill(self::$recipe, $wrong, self::$reason, $today, self::OWNER);
		$refill->SetSettings(self::$recipe, ['rule' => ['kind' => 'fixed_interval', 'parameter' => 76], 'warning_lead_days' => 5], $today, self::OWNER);
		$refill->RecordOrder(self::$recipe, ['ordered_on' => $today], $today, self::OWNER);
		$refill->Acknowledge(self::$recipe . ':due:' . gmdate('Y-m-d', time() - 4 * 86400), self::OWNER);
	}

	private static function key(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
			->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: string} */
	private static function send(string $path, string $as): array
	{
		$spec = ['method' => 'GET', 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$as]]];
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

		return ['status' => $response['status'], 'body' => (string)$response['body']];
	}

	private static function assertNoRefillData(string $text, string $where): void
	{
		foreach ([self::$name, self::$note, self::$reason] as $private)
		{
			self::assertStringNotContainsString($private, $text, "$where carries private text");
		}
		self::assertDoesNotMatchRegularExpression('/consumption_refill|refill_(fill|order|date|ack|setting)|"reorder_(date|on)"|reorder date|warning_lead/i', $text, "$where carries a refill table, column or field");
	}

	/** Positive control: the strings the other tests look for are stored, so their absence means something. */
	public function testThePrivateStringsAreStoredInTheRefillTables(): void
	{
		$stored = (string)self::$db->query('SELECT string_agg(row_to_json(f)::text, \'\') FROM consumption_refill_fills f WHERE f.recipe_id = ' . self::$recipe)->fetchColumn()
			. (string)self::$db->query('SELECT row_to_json(r) FROM consumption_recipes r WHERE r.id = ' . self::$recipe)->fetchColumn();

		self::assertStringContainsString(self::$note, $stored);
		self::assertStringContainsString(self::$reason, $stored);
		self::assertStringContainsString(self::$name, $stored);
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM consumption_refill_orders WHERE recipe_id = ' . self::$recipe)->fetchColumn());
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM consumption_refill_acks WHERE recipe_id = ' . self::$recipe)->fetchColumn());
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM consumption_refill_settings WHERE recipe_id = ' . self::$recipe)->fetchColumn());
	}

	public function testTheCalendarEventsCarryNoRefillData(): void
	{
		self::assertNoRefillData(json_encode(CalendarService::GetInstance()->GetEvents()), 'the calendar events');
	}

	public function testTheIcalFeedCarriesNoRefillData(): void
	{
		foreach (['admin', 'reader'] as $as)
		{
			$feed = self::send('/api/calendar/ical', $as);
			self::assertSame(200, $feed['status'], "the feed answers for $as: " . substr($feed['body'], 0, 200));
			self::assertStringContainsString('BEGIN:VCALENDAR', $feed['body'], 'and it is a calendar');
			self::assertNoRefillData($feed['body'], "the iCal feed read by $as");
		}
	}

	public function testTheMqttStateSnapshotCarriesNoRefillData(): void
	{
		$snapshot = (new StateSnapshotAssembler())->Assemble();

		self::assertNoRefillData(json_encode($snapshot), 'the MQTT state snapshot');
	}

	public function testRefillOperationsQueueNothingOnTheOutboxThatCarriesMqttAndInfluxDbEvents(): void
	{
		$before = (int)self::$db->query('SELECT count(*) FROM outbox')->fetchColumn();
		$refill = ConsumptionRefillService::GetInstance();
		$today = gmdate('Y-m-d');

		$order = $refill->GetRefill(self::$recipe, $today, self::OWNER)['open_order']['id'];
		$refill->ReceiveOrder(self::$recipe, $order, ['filled_on' => $today, 'supplied_days' => 30, 'note' => self::$note . ' received'], $today, self::OWNER);
		$refill->RecordOrder(self::$recipe, ['ordered_on' => $today], $today, self::OWNER);
		$refill->SetSettings(self::$recipe, ['explicit_reorder_date' => $today], $today, self::OWNER);
		$refill->Notices($today, self::OWNER);
		$refill->Acknowledge(self::$recipe . ':approaching:' . $today, self::OWNER);

		self::assertSame($before, (int)self::$db->query('SELECT count(*) FROM outbox')->fetchColumn(), 'no refill operation queued an event');
		self::assertNoRefillData((string)self::$db->query("SELECT COALESCE(string_agg(row_to_json(o)::text, ''), '') FROM outbox o")->fetchColumn(), 'the outbox');
	}

	public function testAnOrdinaryStockReaderAndACalendarReaderSeeNoRefillDataOnTheRoutesThatShareStock(): void
	{
		foreach (['/api/stock', '/api/stock/volatile', '/api/objects/products', '/api/objects/locations', '/api/objects/stock_log', '/api/calendar/ical', '/api/system/config', '/api/user/settings'] as $path)
		{
			$response = self::send($path, 'reader');
			self::assertSame(200, $response['status'], "$path: " . substr($response['body'], 0, 200));
			foreach ([self::$name, self::$note, self::$reason] as $private)
			{
				self::assertStringNotContainsString($private, $response['body'], "$path discloses private refill text");
			}
		}

		$settings = self::send('/api/user/settings', 'reader');
		self::assertStringContainsString('refill_warning_lead_days', $settings['body'], 'the lead preference is a user setting and carries no recipe');
		foreach (['/api/objects/consumption_refill_fills', '/api/objects/consumption_refill_orders', '/api/objects/consumption_refill_dates', '/api/objects/consumption_refill_settings', '/api/objects/consumption_refill_acks'] as $path)
		{
			self::assertSame(400, self::send($path, 'admin')['status'], "$path is refused even for an administrator");
		}
	}

	public function testAnAdministratorWithNoShareGetsNoRefillDataFromTheRefillRoutes(): void
	{
		foreach (['/api/refills', '/api/refills/notices'] as $path)
		{
			$response = self::send($path, 'admin');
			self::assertSame(200, $response['status'], $path);
			self::assertNoRefillData($response['body'], "the $path list read by an administrator who holds no share");
		}

		self::assertSame(404, self::send('/api/consumption/recipes/' . self::$recipe . '/refill', 'admin')['status'], 'ADMIN is not a share');
	}
}
