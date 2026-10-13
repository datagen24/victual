<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\FieldCatalogue;
use Victual\Services\Labels\LabelCaptureService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #896: battery labels are read through BATTERIES_VIEW (ADR-0035), the same way every
 * other label kind is read through its own *_VIEW leaf.
 *
 * FieldCatalogue used the feature permission BATTERIES for battery.name, battery.id and the
 * kind's domain gate, so a caller holding only BATTERIES_VIEW got a 403 on the context route
 * and could not capture a battery field. These tests pin the gate to the leaf and keep the
 * refusals that must not move: a caller with neither permission, and Operate, which still
 * demands MASTER_DATA_EDIT on top.
 */
class BatteryLabelViewPermissionTest extends PgsqlSchemaTestCase
{
	private const VIEW_ONLY_USER = 9620;
	private const NO_GRANT_USER = 9621;
	private const VIEW_AND_EDIT_USER = 9622;

	private static PDO $db;
	private static array $keys = [];
	private static int $batteryId;
	private static string $batteryUid;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		$users = [
			self::VIEW_ONLY_USER => [User::PERMISSION_BATTERIES_VIEW],
			self::NO_GRANT_USER => [User::PERMISSION_STOCK_VIEW],
			self::VIEW_AND_EDIT_USER => [User::PERMISSION_BATTERIES_VIEW, User::PERMISSION_MASTER_DATA_EDIT],
		];

		foreach ($users as $userId => $grants)
		{
			self::$db->exec("INSERT INTO users(id, username, password) VALUES ($userId, 'batterylabel-$userId', 'fixture')");
			$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

			foreach ($grants as $name)
			{
				$statement->execute([$userId, $name]);
			}

			$key = bin2hex(random_bytes(25));
			$insert = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
			$insert->execute([ApiKeyService::HashKey($key), substr($key, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			self::$keys[$userId] = $key;
		}

		self::$db->exec("INSERT INTO batteries (name) VALUES ('Battery label view test')");
		self::$batteryId = (int)self::$db->lastInsertId();

		self::$db->beginTransaction();
		$epoch = (int)self::$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		self::$batteryUid = (new LabelIdentityService(self::$db))->Issue('battery', self::$batteryId, $epoch);
		self::$db->commit();
	}

	private static function Send(string $method, string $path, int $userId, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$userId]]];

		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		$environment = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$specFile = tempnam(getenv('VICTUAL_DATAPATH') ?: sys_get_temp_dir(), 'batterylabel-request-');
		file_put_contents($specFile, json_encode($spec));

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
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		$result['body'] = base64_decode($result['body_base64'], true);
		$result['json'] = json_decode($result['body'], true);

		return $result;
	}

	private static function PrintRequestCount(): int
	{
		return (int)self::$db->query("SELECT COUNT(*) FROM outbox WHERE event_type = 'label.print_requested'")->fetchColumn();
	}

	private static function LabelCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM labels')->fetchColumn();
	}

	public function testTheCatalogueGatesEveryBatterySiteOnTheViewLeaf(): void
	{
		self::assertSame(User::PERMISSION_BATTERIES_VIEW, FieldCatalogue::DomainPermission('battery'));
		self::assertSame(User::PERMISSION_BATTERIES_VIEW, FieldCatalogue::For('battery')['battery.name']['permission']);
		self::assertSame(User::PERMISSION_BATTERIES_VIEW, FieldCatalogue::For('battery')['battery.id']['permission']);
	}

	public function testAViewOnlyCallerReadsTheBatteryContext(): void
	{
		$response = self::Send('GET', '/api/labels/battery/' . self::$batteryId . '/context', self::VIEW_ONLY_USER);

		self::assertSame(200, $response['status'], $response['body']);
	}

	public function testACallerWithNeitherBatteriesPermissionIsStillRefusedTheContext(): void
	{
		$response = self::Send('GET', '/api/labels/battery/' . self::$batteryId . '/context', self::NO_GRANT_USER);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertStringNotContainsString('Battery label view test', $response['body']);
	}

	public function testAViewOnlyCallerSeesTheBatteryIdentityWhenResolvingItsLabel(): void
	{
		$response = self::Send('GET', '/api/labels/resolve/' . self::$batteryUid, self::VIEW_ONLY_USER);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('resolved', $response['json']['status'] ?? null, $response['body']);
		self::assertSame('battery', $response['json']['kind'] ?? null);
		self::assertSame(self::$batteryId, $response['json']['target']['id'] ?? null);
		self::assertSame('Battery label view test', $response['json']['target']['name'] ?? null);
	}

	public function testACallerWithoutTheLeafDoesNotSeeTheBatteryIdentityWhenResolving(): void
	{
		$response = self::Send('GET', '/api/labels/resolve/' . self::$batteryUid, self::NO_GRANT_USER);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('unknown', $response['json']['status'] ?? null, $response['body']);
		self::assertStringNotContainsString('Battery label view test', $response['body']);
	}

	public function testCapturingBatteryFieldsAsksForTheViewLeaf(): void
	{
		$seen = [];
		self::$db->beginTransaction();
		try
		{
			$capture = (new LabelCaptureService(self::$db, static function (string $permission) use (&$seen)
			{
				$seen[] = $permission;
				return $permission === User::PERMISSION_BATTERIES_VIEW;
			}))->Capture('battery', self::$batteryId, null, ['battery.name', 'battery.id'], 'en', 'UTC', self::VIEW_ONLY_USER);
			self::$db->commit();
		}
		finally
		{
			if (self::$db->inTransaction())
			{
				self::$db->rollBack();
			}
		}

		self::assertSame('Battery label view test', $capture['captured_fields']['battery.name']);
		self::assertSame((string)self::$batteryId, $capture['captured_fields']['battery.id']);
		self::assertSame([User::PERMISSION_BATTERIES_VIEW], array_values(array_unique($seen)));
	}

	public function testOperateStillRequiresMasterDataEditOnTopOfTheViewLeaf(): void
	{
		$labels = self::LabelCount();
		$requests = self::PrintRequestCount();

		$response = self::Send('POST', '/api/labels/battery/' . self::$batteryId . '/print', self::VIEW_ONLY_USER,
			['import_epoch' => 0, 'printer_id' => 0]);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertSame($labels, self::LabelCount(), 'a refused print mints no label');
		self::assertSame($requests, self::PrintRequestCount(), 'a refused print enqueues nothing');
	}

	public function testOperateGetsPastThePermissionGateForAHolderOfBothGrants(): void
	{
		$labels = self::LabelCount();
		$requests = self::PrintRequestCount();

		$response = self::Send('POST', '/api/labels/battery/' . self::$batteryId . '/print', self::VIEW_AND_EDIT_USER,
			['import_epoch' => 0, 'printer_id' => 999999]);

		// The request names no real printer, so it is refused further in, by the printer
		// check: the permission gate is the only thing that differs from the view-only caller.
		self::assertSame(422, $response['status'], $response['body']);
		self::assertSame('printer_id', $response['json']['field'] ?? null, $response['body']);
		self::assertSame('inactive_printer', $response['json']['code'] ?? null, $response['body']);
		self::assertSame($labels, self::LabelCount(), 'the refusal mints no label');
		self::assertSame($requests, self::PrintRequestCount(), 'the refusal enqueues nothing');
	}
}
