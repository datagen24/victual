<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #847: ADR-0035 requires BATTERIES_VIEW and EQUIPMENT_VIEW for the battery and
 * equipment pages and APIs, but the generic read paths were left open to any
 * authenticated caller - EntityReadPolicy mapped batteries, battery_charge_cycles and
 * equipment to null ("no leaf of its own"), and FilesApiController mirrored that for the
 * equipmentmanuals file group. The dedicated /api/batteries and /api/equipment routes
 * were gated, so the generic route was a bypass of the same data.
 *
 * Tier 1 per ADR-0025: every request goes through the real middleware stack, including
 * session authentication and User::CheckPermission(), via
 * tests/Pgsql/request-subprocess-helper.php, one process per request.
 */
class Adr0035GenericReadsTest extends PgsqlSchemaTestCase
{
	private const NO_LEAF_USER = 9801;
	private const VIEWER_USER = 9802;
	private const ADMIN_USER = 9803;
	private const BATTERIES_ONLY_USER = 9804;

	private const BATTERY_NAME = 'ADR35 secret battery';
	private const EQUIPMENT_NAME = 'ADR35 secret equipment';
	private const MANUAL_NAME = 'adr35-manual.pdf';

	private static PDO $db;
	/** @var array<int, string> user id => session key */
	private static array $sessions = [];
	private static int $equipmentId;
	private static string $manualBytes;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::createUser(self::NO_LEAF_USER, 'adr35-noleaf', []);
		self::createUser(self::VIEWER_USER, 'adr35-viewer', ['BATTERIES_VIEW', 'EQUIPMENT_VIEW']);
		self::createUser(self::ADMIN_USER, 'adr35-admin', ['ADMIN']);
		self::createUser(self::BATTERIES_ONLY_USER, 'adr35-batteries-only', ['BATTERIES_VIEW']);

		$batteryId = (int)self::$db->query("INSERT INTO batteries (name, description) VALUES ('" . self::BATTERY_NAME . "', 'adr35 battery description') RETURNING id")->fetchColumn();
		self::$db->exec("INSERT INTO battery_charge_cycles (battery_id, tracked_time) VALUES ($batteryId, now())");
		self::$equipmentId = (int)self::$db->query("INSERT INTO equipment (name, description, instruction_manual_file_name) VALUES ('" . self::EQUIPMENT_NAME . "', 'adr35 equipment description', '" . self::MANUAL_NAME . "') RETURNING id")->fetchColumn();

		// ASCII only: the request helper json_encode()s the body, which refuses other bytes.
		self::$manualBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
		$upload = self::send('PUT', '/api/files/equipmentmanuals/' . base64_encode(self::MANUAL_NAME), self::$sessions[self::ADMIN_USER], [
			'headers' => ['Content-Type' => 'application/pdf'],
			'rawBody' => self::$manualBytes,
		]);
		self::assertSame(204, $upload['status'], 'fixture manual uploaded: ' . $upload['body']);
	}

	public static function tearDownAfterClass(): void
	{
		self::send('DELETE', '/api/files/equipmentmanuals/' . base64_encode(self::MANUAL_NAME), self::$sessions[self::ADMIN_USER]);

		parent::tearDownAfterClass();
	}

	private static function createUser(int $id, string $name, array $permissions): void
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES ($id, '$name', 'fixture')");
		foreach ($permissions as $permission)
		{
			self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $id, id FROM permission_hierarchy WHERE name = '$permission'");
		}
		self::$sessions[$id] = bin2hex(random_bytes(25));
		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('" . self::$sessions[$id] . "', $id, now() + interval '1 day')");
	}

	/**
	 * @return array{status: int, body: string}
	 */
	private static function send(string $method, string $path, string $sessionKey, array $options = []): array
	{
		$spec = ['method' => $method, 'path' => $path, 'cookie' => $sessionKey];
		foreach (['headers', 'body', 'rawBody'] as $key)
		{
			if (isset($options[$key]))
			{
				$spec[$key] = $options[$key];
			}
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
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	public static function readPaths(): array
	{
		return [
			'batteries' => ['/api/objects/batteries', self::BATTERY_NAME],
			'battery_charge_cycles' => ['/api/objects/battery_charge_cycles', 'tracked_time'],
			'equipment list' => ['/api/objects/equipment', self::EQUIPMENT_NAME],
		];
	}

	#[DataProvider('readPaths')]
	public function testGenericListIsRefusedWithoutTheLeafAndServedWithIt(string $path, string $marker): void
	{
		$refused = self::send('GET', $path, self::$sessions[self::NO_LEAF_USER]);
		self::assertSame(403, $refused['status'], "GET $path without the view leaf: " . $refused['body']);
		self::assertStringNotContainsString($marker, $refused['body'], 'the refusal carries no row data');
		self::assertStringNotContainsString('adr35', $refused['body'], 'the refusal carries no row data');

		$served = self::send('GET', $path, self::$sessions[self::VIEWER_USER]);
		self::assertSame(200, $served['status'], "GET $path with the view leaf: " . $served['body']);
		self::assertStringContainsString($marker, $served['body']);
	}

	public function testGenericEquipmentRowIsRefusedWithoutEquipmentView(): void
	{
		$path = '/api/objects/equipment/' . self::$equipmentId;

		$refused = self::send('GET', $path, self::$sessions[self::NO_LEAF_USER]);
		self::assertSame(403, $refused['status'], $refused['body']);
		self::assertStringNotContainsString('adr35', $refused['body'], 'the refusal carries no row data');
		self::assertStringNotContainsString(self::MANUAL_NAME, $refused['body']);

		$served = self::send('GET', $path, self::$sessions[self::VIEWER_USER]);
		self::assertSame(200, $served['status'], $served['body']);
		self::assertSame(self::EQUIPMENT_NAME, json_decode($served['body'], true)['name']);
	}

	public function testEquipmentManualIsRefusedWithoutEquipmentViewAndServedWithIt(): void
	{
		$path = '/api/files/equipmentmanuals/' . base64_encode(self::MANUAL_NAME);

		$refused = self::send('GET', $path, self::$sessions[self::NO_LEAF_USER]);
		self::assertSame(403, $refused['status'], $refused['body']);
		self::assertStringNotContainsString('%PDF', $refused['body'], 'the refusal carries no file bytes');

		$served = self::send('GET', $path, self::$sessions[self::VIEWER_USER]);
		self::assertSame(200, $served['status'], $served['body']);
		self::assertSame(self::$manualBytes, $served['body'], 'the caller with EQUIPMENT_VIEW receives the file');
	}

	public function testEachLeafGatesOnlyItsOwnEntity(): void
	{
		$session = self::$sessions[self::BATTERIES_ONLY_USER];

		self::assertSame(200, self::send('GET', '/api/objects/batteries', $session)['status']);
		self::assertSame(403, self::send('GET', '/api/objects/equipment', $session)['status'], 'BATTERIES_VIEW does not open equipment');
		self::assertSame(403, self::send('GET', '/api/files/equipmentmanuals/' . base64_encode(self::MANUAL_NAME), $session)['status'], 'BATTERIES_VIEW does not open the manuals');
	}
}
