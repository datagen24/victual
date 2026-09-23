<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\RouteInventory;
use Victual\Tests\Support\SfntFixture;

/**
 * The HTTP surface of the label subsystem: the five `/api/labels` controllers.
 *
 * `.devtools/labels/*-tests.php` drives the services directly and already establishes what
 * they compute. Nothing reaches the controllers above them, so this class asserts only what
 * the HTTP layer adds - which route is dispatched, which permission it demands, which status
 * a refusal carries, the shape of the response body, and what is left in the database
 * afterwards. Where a service behaviour is restated here it is because the controller chose
 * the status code or the field name that expresses it.
 *
 * Every request goes through `request-subprocess-helper.php` rather than being handed to a
 * controller directly, for two reasons that are both structural: all five controllers read
 * `RouteContext::fromRequest()` to decide what operation they are performing, so a request
 * with no matched route dispatches nothing; and the worker and renderer routes are
 * authenticated by a typed API key that `BaseAuthMiddleware` resolves into the
 * `label_worker_id` attribute, which is exactly the middleware a direct call skips.
 */
class LabelApiTest extends PgsqlSchemaTestCase
{
	/** The acting users. 9000 is the in-process constant; the rest authenticate by API key. */
	private const ADMIN_USER = 9600;
	private const OPERATOR_USER = 9601;

	/**
	 * What the operator holds for every test. A permission refusal narrows it and never puts
	 * it back: setUp() does that, so a failure inside such a test cannot leave the identity
	 * every later test authenticates with stripped of its grants.
	 */
	private const OPERATOR_GRANTS = [User::PERMISSION_MASTER_DATA_EDIT, User::PERMISSION_STOCK_VIEW,
		User::PERMISSION_RECIPES_VIEW, User::PERMISSION_CHORES_VIEW, User::PERMISSION_BATTERIES];

	/** Fixture dates are pinned: an unpinned best-before flips a JSON type with the calendar. */
	private const BEST_BEFORE = '2099-12-31';
	private const PURCHASED = '2026-01-01';

	private static PDO $db;
	private static string $adminKey;
	private static string $operatorKey;

	/** Fixture target ids, by label kind. */
	private static array $targets = [];

	/** Published template id per entity kind. */
	private static array $templates = [];

	private static int $workerId;
	private static string $workerKey;
	private static int $pairedWorkerId;
	private static string $pairedKey;
	private static int $printerId;
	private static string $rendererKey;

	/** The job the renderer/worker narrative walks through, and its artifact. */
	private static int $locationJobId;
	private static string $locationUid;
	private static int $locationArtifactId;
	private static int $attemptId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// paintArtifact() renders the module matrix Victual computed at the geometry the
		// profile fixes. Reused rather than reimplemented: a second painter would be a
		// second opinion about the artifact form, and ArtifactService verifies these bytes
		// exactly as it verifies the real renderer's.
		require_once VICTUAL_ROOT_PATH . '/.devtools/labels/test-support.php';

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'labelapi-caller', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ADMIN_USER . ", 'labelapi-admin', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::OPERATOR_USER . ", 'labelapi-operator', 'fixture')");

		self::Grant(self::ADMIN_USER, [User::PERMISSION_ADMIN]);
		self::Grant(self::OPERATOR_USER, self::OPERATOR_GRANTS);

		self::$adminKey = self::IssueUserKey(self::ADMIN_USER);
		self::$operatorKey = self::IssueUserKey(self::OPERATOR_USER);

		self::SeedTargets();
	}

	// ---------------------------------------------------------------- fixtures

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

	/**
	 * The operator starts every test holding its whole grant set. Restoring here rather than
	 * at the end of the test that narrowed it is what keeps a failure inside one refusal test
	 * from turning every later test that authenticates as the operator into a 403.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		self::Grant(self::OPERATOR_USER, self::OPERATOR_GRANTS);
	}

	/**
	 * One target per label kind, plus a second location that must never appear in a
	 * response about the first - the negative control for every "it found the row" check.
	 */
	private static function SeedTargets(): void
	{
		$db = self::$db;
		$db->exec("INSERT INTO locations (name, description) VALUES ('Labelapi shelf', 'The tall one')");
		self::$targets['location'] = (int)$db->lastInsertId();
		$db->exec("INSERT INTO locations (name, description) VALUES ('Labelapi other shelf', 'Not the one under test')");
		self::$targets['other_location'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, description) VALUES ('Labelapi beans', " . self::$targets['location'] . ", 2, 2, 'Canned')");
		self::$targets['product'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id)
			VALUES (" . self::$targets['product'] . ", 3, 'labelapi-stock-1', '" . self::BEST_BEFORE . "', '" . self::PURCHASED . "', " . self::$targets['location'] . ')');
		self::$targets['stock_entry'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO recipes (name) VALUES ('Labelapi chili')");
		self::$targets['recipe'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO chores (name, period_type) VALUES ('Labelapi watering', 'manually')");
		self::$targets['chore'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO batteries (name) VALUES ('Labelapi smoke detector')");
		self::$targets['battery'] = (int)$db->lastInsertId();
	}

	// ---------------------------------------------------------------- transport

	/**
	 * One request through the production middleware stack.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>, json: mixed}
	 */
	private static function Send(string $method, string $path, ?array $body = null, ?string $key = null, array $headers = []): array
	{
		if ($key !== null)
		{
			$headers['VICTUAL-API-KEY'] = $key;
		}

		$spec = ['method' => $method, 'path' => $path];

		if ($headers !== [])
		{
			$spec['headers'] = $headers;
		}

		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		return self::Dispatch($spec, "$method $path");
	}

	/** @return array{status: int, body: string, headers: array<string, string>, json: mixed} */
	private static function Dispatch(array $spec, string $what): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		// The request description goes in a file rather than in an argument: an asset upload
		// carries a whole font, which is longer than an argument list may be.
		$specFile = tempnam(getenv('VICTUAL_DATAPATH') ?: sys_get_temp_dir(), 'labelapi-request-');
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
		self::assertIsArray($result, "the request helper printed no JSON for $what. stdout: $output\nstderr: $errors");
		$result['body'] = base64_decode($result['body_base64'], true);
		$result['json'] = json_decode($result['body'], true);

		return $result;
	}

	/** One request whose body is sent as it stands rather than as JSON of an array. */
	private static function SendRaw(string $method, string $path, string $body, string $contentType, ?string $key = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'raw_body' => $body,
			'headers' => ['Content-Type' => $contentType] + ($key === null ? [] : ['VICTUAL-API-KEY' => $key])];

		return self::Dispatch($spec, "$method $path");
	}

	/** Asserts a refusal's status and the `field`/`code` pair the label controllers answer with. */
	private static function AssertRefusal(array $response, int $status, string $field, string $code): void
	{
		self::assertSame($status, $response['status'], 'refusal status, body: ' . $response['body']);
		self::assertSame($field, $response['json']['field'] ?? null, 'refusal field, body: ' . $response['body']);
		self::assertSame($code, $response['json']['code'] ?? null, 'refusal code, body: ' . $response['body']);
		self::assertNotSame('', (string)($response['json']['error_message'] ?? ''), 'a refusal names the problem');
	}

	/** The rows a write must not leave behind when it is refused. */
	private static function Counts(): array
	{
		$of = static fn (string $sql): int => (int)self::$db->query($sql)->fetchColumn();

		return [
			'labels' => $of('SELECT count(*) FROM labels'),
			'print_jobs' => $of('SELECT count(*) FROM print_jobs'),
			'print_requests' => $of("SELECT count(*) FROM outbox WHERE event_type = 'label.print_requested'"),
			'workers' => $of('SELECT count(*) FROM label_workers'),
			'printers' => $of('SELECT count(*) FROM label_printers'),
			'templates' => $of('SELECT count(*) FROM label_templates'),
			'template_versions' => $of('SELECT count(*) FROM label_template_versions'),
			'assets' => $of('SELECT count(*) FROM label_assets'),
			'attempts' => $of('SELECT count(*) FROM print_attempts'),
		];
	}

	private static function Row(string $sql, array $parameters = []): ?array
	{
		$statement = self::$db->prepare($sql);
		$statement->execute($parameters);

		return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	private static function Driver(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function PrinterBody(int $workerId, array $overrides = []): array
	{
		return $overrides + [
			'name' => 'Labelapi printer',
			'worker_id' => $workerId,
			'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0',
			'connection' => '127.0.0.1:9100',
			'connection_type' => 'tcp',
			'model' => 'QL-820NWBc',
			'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		];
	}

	/** A QR-only document: no font asset is needed for it to be a real published template. */
	private static function Document(string $entityKind): array
	{
		return [
			'schema_version' => 1,
			'entity_kind' => $entityKind,
			'canvas' => ['width_mm' => 62.0, 'height_mm' => 30.0, 'max_height_mm' => null,
				'margins_mm' => ['top' => 1.0, 'right' => 1.0, 'bottom' => 1.0, 'left' => 1.0]],
			'elements' => [[
				'type' => 'qr', 'id' => 'code', 'x_mm' => 2.0, 'y_mm' => 2.0, 'module_mm' => 0.6,
				'ec_level' => 'M', 'quiet_zone_modules' => 4, 'color' => 'black', 'source' => 'label.payload',
			]],
		];
	}

	/** Creates, edits and publishes one template through the API, returning its id. */
	private static function PublishTemplate(string $entityKind, string $name): int
	{
		$created = self::Send('POST', '/api/labels/templates', ['name' => $name, 'entity_kind' => $entityKind], self::$adminKey);
		self::assertSame(200, $created['status'], $created['body']);
		$templateId = (int)$created['json']['id'];

		$draft = self::Send('GET', "/api/labels/templates/$templateId/draft", null, self::$adminKey);
		self::assertSame(200, $draft['status'], $draft['body']);

		$saved = self::Send('PUT', "/api/labels/templates/$templateId/draft",
			['document' => self::Document($entityKind), 'revision_token' => $draft['json']['revision_token']], self::$adminKey);
		self::assertSame(200, $saved['status'], $saved['body']);

		$published = self::Send('POST', "/api/labels/templates/$templateId/publish", [], self::$adminKey);
		self::assertSame(200, $published['status'], $published['body']);

		return $templateId;
	}

	/**
	 * Renders pending requests in the order the queue hands them out until the named one is
	 * done, and answers with that one's result.
	 *
	 * The queue is first in, first out and a renderer cannot ask for a particular request, so
	 * reaching a request queued now means rendering whatever is still ahead of it. Draining
	 * rather than claiming once is what keeps a preview assertion about the preview's own
	 * artifact instead of about whichever production job happened to be next.
	 */
	private static function RenderUpTo(int $requestId): array
	{
		for ($attempt = 0; $attempt < 50; $attempt++)
		{
			$claim = self::Send('POST', '/api/labels/render/claim', [], self::$rendererKey);
			self::assertSame(200, $claim['status'], $claim['body']);
			self::assertNotNull($claim['json']['render_request_id'] ?? null, 'a render request was pending: ' . $claim['body']);

			$claimed = (int)$claim['json']['render_request_id'];
			$result = self::Send('POST', "/api/labels/render/$claimed/result", [
				'generation_token' => $claim['json']['generation_token'],
				'artifact_base64' => base64_encode(paintArtifact($claim['json'])),
				'renderer_id' => 'labelapi-fixture-renderer',
				'renderer_version' => '0.0.1-fixture',
			], self::$rendererKey);
			self::assertSame(200, $result['status'], $result['body']);

			if ($claimed === $requestId)
			{
				return $result['json'] + ['render_request_id' => $claimed];
			}
		}

		self::fail("the render queue never reached request $requestId");
	}

	// ============================================================ route inventory

	/**
	 * Every route dispatching to one of the five controllers, read from Slim's own route
	 * table rather than from a regex over routes.php.
	 *
	 * The list is spelled out so that adding a route without extending this suite fails
	 * here, where the omission is visible, rather than silently lowering what is covered.
	 */
	private const LABEL_ROUTES = [
		'POST /api/labels/pair',
		'POST /api/labels/credentials/rotate',
		'POST /api/labels/register',
		'POST /api/labels/jobs/claim',
		'POST /api/labels/attempts/{attemptId}/heartbeat',
		'POST /api/labels/attempts/{attemptId}/sent',
		'POST /api/labels/attempts/{attemptId}/result',
		'POST /api/labels/attempts/{attemptId}/evidence',
		'POST /api/labels/printers/{printerId}/status',
		'POST /api/labels/printers',
		'PUT /api/labels/printers/{printerId}',
		'POST /api/labels/printers/{printerId}/schema-version',
		'DELETE /api/labels/printers/{printerId}',
		'POST /api/labels/workers',
		'PUT /api/labels/workers/{workerId}',
		'POST /api/labels/workers/{workerId}/pairing-material',
		'POST /api/labels/workers/{workerId}/credentials',
		'DELETE /api/labels/workers/{workerId}/credentials',
		'GET /api/labels/jobs',
		'POST /api/labels/jobs/{jobId}/authorize-attempt',
		'GET /api/labels/drivers/{driverId}/schemas/{schemaVersion}',
		'POST /api/labels/locations/{locationId}/print',
		'POST /api/labels/locations/{locationId}/revised-print',
		'POST /api/labels/{kind:location|product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/print',
		'POST /api/labels/{kind:location|product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/revised-print',
		'POST /api/labels/jobs/{jobId}/reprint',
		'POST /api/labels/jobs/{jobId}/cancel',
		'POST /api/labels/artifacts/{artifactId}/promote',
		'GET /api/labels/templates',
		'POST /api/labels/templates',
		'GET /api/labels/templates/{templateId}/draft',
		'PUT /api/labels/templates/{templateId}/draft',
		'POST /api/labels/templates/{templateId}/publish',
		'GET /api/labels/templates/{templateId}/versions',
		'PUT /api/labels/templates/{templateId}/default-version',
		'POST /api/labels/templates/{templateId}/archive',
		'POST /api/labels/templates/{templateId}/preview',
		'GET /api/labels/assets',
		'POST /api/labels/assets',
		'GET /api/labels/renders/{requestId}',
		'GET /api/labels/artifacts/{artifactId}/image',
		'POST /api/labels/workers/{workerId}/renderer-credentials',
		'POST /api/labels/render/claim',
		'POST /api/labels/render/{requestId}/result',
		'POST /api/labels/render/{requestId}/invalid',
		'POST /api/labels/render/{requestId}/failed',
		'GET /api/labels/assets/{assetId}/bytes',
		'GET /api/labels/artifacts/{artifactId}/bytes',
		'GET /api/labels/resolve/{code}',
		'GET /api/labels/locations/{locationId}/context',
		'GET /api/labels/{kind:product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/context',
	];

	public function testTheLabelRouteTableIsTheOneThisSuiteExercises(): void
	{
		$controllers = [
			\Victual\Controllers\Api\LabelsApiController::class,
			\Victual\Controllers\Api\LabelTemplatesApiController::class,
			\Victual\Controllers\Api\LabelPrintersApiController::class,
			\Victual\Controllers\Api\LabelWorkerApiController::class,
			\Victual\Controllers\Api\LabelRenderApiController::class,
		];

		$registered = [];

		foreach (RouteInventory::Api() as $operation)
		{
			if (in_array($operation->ControllerClass, $controllers, true))
			{
				$registered[] = $operation->Method . ' ' . $operation->Path;
			}
		}

		sort($registered);
		$expected = self::LABEL_ROUTES;
		sort($expected);

		self::assertSame($expected, $registered, 'a label route was added or removed; extend this suite to cover it');
	}

	// ============================================================ workers

	public function testWorkerCreationRefusesACallerWithoutAdmin(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/workers', ['name' => 'Smuggled worker', 'configuration_mode' => 'declared'], self::$operatorKey);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertSame($before['workers'], self::Counts()['workers'], 'a refused worker creation writes no row');
	}

	public function testWorkerCreationRefusesAnUnknownConfigurationMode(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/workers', ['name' => 'Third mode', 'configuration_mode' => 'implicit'], self::$adminKey);

		self::AssertRefusal($response, 422, 'configuration_mode', 'value_out_of_range');
		self::assertSame($before['workers'], self::Counts()['workers'], 'a refused worker creation writes no row');
	}

	public function testWorkerCreationRefusesABlankName(): void
	{
		$response = self::Send('POST', '/api/labels/workers', ['name' => '   ', 'configuration_mode' => 'declared'], self::$adminKey);

		self::AssertRefusal($response, 422, 'name', 'value_out_of_range');
	}

	public function testAdminCreatesADeclaredWorker(): void
	{
		$response = self::Send('POST', '/api/labels/workers',
			['name' => 'Labelapi worker', 'description' => 'The declared one', 'configuration_mode' => 'declared'], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertIsInt($response['json']['id'] ?? null);
		self::$workerId = (int)$response['json']['id'];

		$row = self::Row('SELECT name, description, configuration_mode, active FROM label_workers WHERE id = ?', [self::$workerId]);
		self::assertSame('Labelapi worker', $row['name']);
		self::assertSame('The declared one', $row['description']);
		self::assertSame('declared', $row['configuration_mode']);
		self::assertSame(1, (int)$row['active']);
	}

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testWorkerUpdateRefusesAModeChangeAndLeavesTheRowAlone(): void
	{
		$response = self::Send('PUT', '/api/labels/workers/' . self::$workerId,
			['name' => 'Labelapi worker', 'configuration_mode' => 'paired'], self::$adminKey);

		self::AssertRefusal($response, 422, 'configuration_mode', 'value_out_of_range');
		self::assertSame('declared', self::Row('SELECT configuration_mode FROM label_workers WHERE id = ?', [self::$workerId])['configuration_mode']);
	}

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testWorkerUpdateRenamesTheWorker(): void
	{
		$response = self::Send('PUT', '/api/labels/workers/' . self::$workerId,
			['name' => 'Labelapi worker (renamed)', 'configuration_mode' => 'declared'], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::$workerId, (int)$response['json']['id']);
		self::assertSame('Labelapi worker (renamed)', self::Row('SELECT name FROM label_workers WHERE id = ?', [self::$workerId])['name']);
	}

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testTheWorkerActiveFlagIsZeroOrOneAndNothingElse(): void
	{
		// The column is a 0/1 flag, and a request carrying anything else is refused where the
		// caller can see it rather than reaching a CHECK constraint as a 500.
		foreach ([2, -1, true, '1'] as $active)
		{
			$response = self::Send('PUT', '/api/labels/workers/' . self::$workerId,
				['name' => 'Labelapi worker (renamed)', 'configuration_mode' => 'declared', 'active' => $active], self::$adminKey);

			self::AssertRefusal($response, 422, 'active', 'value_out_of_range');
		}

		self::assertSame(1, (int)self::Row('SELECT active FROM label_workers WHERE id = ?', [self::$workerId])['active'],
			'a refused update leaves the worker as it was');
	}

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testPairingMaterialIsRefusedForADeclaredWorker(): void
	{
		$response = self::Send('POST', '/api/labels/workers/' . self::$workerId . '/pairing-material', [], self::$adminKey);

		self::AssertRefusal($response, 422, 'worker_id', 'wrong_mode');
	}

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testAdminIssuesADeclaredWorkerCredential(): void
	{
		$response = self::Send('POST', '/api/labels/workers/' . self::$workerId . '/credentials', [], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::$workerId, (int)$response['json']['worker_id']);
		self::assertIsString($response['json']['credential'] ?? null);
		self::$workerKey = $response['json']['credential'];

		// The credential is typed, and the type is what decides which routes accept it.
		$row = self::Row('SELECT k.key_type, k.user_id FROM api_keys k WHERE k.id = ?', [(int)$response['json']['credential_id']]);
		self::assertSame(ApiKeyService::API_KEY_TYPE_LABEL_WORKER, $row['key_type']);
		self::assertSame(self::ADMIN_USER, (int)$row['user_id'], 'the credential is bound to the administrator who issued it');
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testAWorkerKeyIsRefusedOnTheHouseholdApi(): void
	{
		// The typed key authorizes the worker protocol and nothing else: it is not a way
		// into the household API the administrator who issued it can reach.
		$response = self::Send('GET', '/api/labels/templates', null, self::$workerKey);

		self::assertSame(401, $response['status'], $response['body']);
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testWorkerRoutesRefuseARequestWithNoCredential(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1]);

		self::assertSame(401, $response['status'], $response['body']);
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testWorkerRoutesRefuseAHouseholdApiKey(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], self::$adminKey);

		self::assertSame(401, $response['status'], 'an administrator key is not a worker credential: ' . $response['body']);
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testAnExpiredWorkerCredentialIsRefused(): void
	{
		$expired = self::Send('POST', '/api/labels/workers/' . self::$workerId . '/credentials', [], self::$adminKey);
		self::assertSame(200, $expired['status'], $expired['body']);

		self::$db->prepare("UPDATE api_keys SET expires = now() - interval '1 second' WHERE id = ?")
			->execute([(int)$expired['json']['credential_id']]);

		$response = self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], $expired['json']['credential']);
		self::assertSame(401, $response['status'], $response['body']);

		// ... and the unexpired sibling still works, so the refusal is about the expiry.
		$live = self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], self::$workerKey);
		self::assertSame(200, $live['status'], $live['body']);
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testWorkerRoutesRefuseARequestWithNoJsonObject(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/claim', null, self::$workerKey);

		self::AssertRefusal($response, 422, 'body', 'invalid_body');
	}

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testClaimLimitOutsideOneThroughFiftyIsRefused(): void
	{
		foreach ([0, -1, 51] as $limit)
		{
			self::AssertRefusal(self::Send('POST', '/api/labels/jobs/claim', ['limit' => $limit], self::$workerKey), 422, 'limit', 'value_out_of_range');
		}

		// 1 and 50 are the ends of the range the route accepts, and both are accepted.
		foreach ([1, 50] as $limit)
		{
			self::assertSame(200, self::Send('POST', '/api/labels/jobs/claim', ['limit' => $limit], self::$workerKey)['status']);
		}

		self::AssertRefusal(self::Send('POST', '/api/labels/jobs/claim', ['limit' => '1'], self::$workerKey), 422, 'limit', 'value_out_of_range');
	}

	// ============================================================ pairing

	public function testAPairedWorkerPairsOverThePublicRouteExactlyOnce(): void
	{
		$created = self::Send('POST', '/api/labels/workers', ['name' => 'Labelapi paired worker', 'configuration_mode' => 'paired'], self::$adminKey);
		self::assertSame(200, $created['status'], $created['body']);
		self::$pairedWorkerId = (int)$created['json']['id'];

		$material = self::Send('POST', '/api/labels/workers/' . self::$pairedWorkerId . '/pairing-material', [], self::$adminKey);
		self::assertSame(200, $material['status'], $material['body']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $material['json']['material']);

		// `labels-pair` is the one route in the subsystem that carries no credential: it is
		// how a worker gets its first one.
		$paired = self::Send('POST', '/api/labels/pair', ['material' => $material['json']['material']]);
		self::assertSame(200, $paired['status'], $paired['body']);
		self::assertSame(self::$pairedWorkerId, (int)$paired['json']['worker_id']);
		self::$pairedKey = $paired['json']['credential'];

		$replayed = self::Send('POST', '/api/labels/pair', ['material' => $material['json']['material']]);
		self::AssertRefusal($replayed, 401, 'material', 'unauthorized');
	}

	public function testPairingRefusesMaterialThatIsNotThirtyTwoHexBytes(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/pair', ['material' => 'not-hex']), 422, 'material', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', '/api/labels/pair', ['material' => 42]), 422, 'material', 'value_out_of_range');
	}

	#[Depends('testAPairedWorkerPairsOverThePublicRouteExactlyOnce')]
	public function testRotationIssuesASuccessorAndReplayRederivesTheSameOne(): void
	{
		$request = bin2hex(random_bytes(32));
		$material = bin2hex(random_bytes(32));

		$predecessor = self::$pairedKey;

		$rotated = self::Send('POST', '/api/labels/credentials/rotate',
			['rotation_request_id' => $request, 'material' => $material], $predecessor);
		self::assertSame(200, $rotated['status'], $rotated['body']);
		self::assertNotSame($predecessor, $rotated['json']['credential']);

		// Adopted the moment it exists rather than at the end of the test: a failure below
		// must not leave the class holding a credential this rotation has already replaced,
		// which would turn every later paired-worker test into a 401.
		self::$pairedKey = $rotated['json']['credential'];

		$replayed = self::Send('POST', '/api/labels/credentials/rotate',
			['rotation_request_id' => $request, 'material' => $material], $predecessor);
		self::assertSame(200, $replayed['status'], $replayed['body']);
		self::assertSame($rotated['json']['credential'], $replayed['json']['credential'], 'an identical rotation rederives the successor rather than minting a second one');
	}

	#[Depends('testRotationIssuesASuccessorAndReplayRederivesTheSameOne')]
	public function testRotationRefusesAMalformedRequestIdentifier(): void
	{
		self::AssertRefusal(
			self::Send('POST', '/api/labels/credentials/rotate', ['rotation_request_id' => 'short', 'material' => bin2hex(random_bytes(32))], self::$pairedKey),
			422, 'rotation_request_id', 'value_out_of_range'
		);
	}

	// ============================================================ driver registry

	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testWorkerRegistersItsDriverVersions(): void
	{
		$response = self::Send('POST', '/api/labels/register', ['drivers' => [self::Driver()]], self::$workerKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(['registered' => true], $response['json']);

		$capability = self::Row('SELECT driver_id, schema_version FROM label_worker_capabilities WHERE worker_id = ?', [self::$workerId]);
		self::assertSame('brother.ql', $capability['driver_id']);
		self::assertSame('1.0', $capability['schema_version']);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testRegistrationRefusesMoreDriverDefinitionsThanTheRouteAccepts(): void
	{
		$before = (int)self::$db->query('SELECT count(*) FROM label_drivers')->fetchColumn();
		$response = self::Send('POST', '/api/labels/register', ['drivers' => array_fill(0, 65, self::Driver())], self::$workerKey);

		self::AssertRefusal($response, 422, 'drivers', 'value_out_of_range');
		self::assertSame($before, (int)self::$db->query('SELECT count(*) FROM label_drivers')->fetchColumn());
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testRegistrationRefusesADriverListThatIsNotAList(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/register', ['drivers' => ['a' => self::Driver()]], self::$workerKey), 422, 'drivers', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', '/api/labels/register', [], self::$workerKey), 422, 'drivers', 'value_out_of_range');
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testTheDriverSchemaIsReadableByAnAdministrator(): void
	{
		$response = self::Send('GET', '/api/labels/drivers/brother.ql/schemas/1.0', null, self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(['settings_schemas', 'discriminator_properties', 'combination_binding', 'capability_document'], array_keys($response['json']));
		self::assertSame(['model', 'media'], $response['json']['discriminator_properties']);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testAnUnknownDriverVersionIsRefusedRatherThanApproximated(): void
	{
		self::AssertRefusal(
			self::Send('GET', '/api/labels/drivers/brother.ql/schemas/9.9', null, self::$adminKey),
			422, 'driver_schema_version', 'unknown_driver_version'
		);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testTheDriverSchemaRouteRefusesANonAdmin(): void
	{
		self::assertSame(403, self::Send('GET', '/api/labels/drivers/brother.ql/schemas/1.0', null, self::$operatorKey)['status']);
	}

	// ============================================================ printers

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testPrinterCreationRefusesAnUnknownDriverVersionAndWritesNothing(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$workerId, ['driver_schema_version' => '9.9']), self::$adminKey);

		self::AssertRefusal($response, 422, 'driver_schema_version', 'unknown_driver_version');
		self::assertSame($before['printers'], self::Counts()['printers']);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testPrinterCreationRefusesAnImpossibleMediaCombinationAndWritesNothing(): void
	{
		$before = self::Counts();
		$body = self::PrinterBody(self::$workerId);
		$body['settings']['resolution_x'] = 600;

		self::AssertRefusal(self::Send('POST', '/api/labels/printers', $body, self::$adminKey), 422, 'settings', 'unsupported_combination');
		self::assertSame($before['printers'], self::Counts()['printers']);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testPrinterCreationRefusesACallerWithoutAdmin(): void
	{
		$before = self::Counts();

		self::assertSame(403, self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$workerId), self::$operatorKey)['status']);
		self::assertSame($before['printers'], self::Counts()['printers']);
	}

	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testAdminCreatesAPrinter(): void
	{
		$response = self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$workerId, ['is_default' => 1]), self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::$printerId = (int)$response['json']['id'];

		$row = self::Row('SELECT name, worker_id, driver_id, driver_schema_version, connection_type, model, active, is_default, settings FROM label_printers WHERE id = ?', [self::$printerId]);
		self::assertSame('Labelapi printer', $row['name']);
		self::assertSame(self::$workerId, (int)$row['worker_id']);
		self::assertSame('brother.ql', $row['driver_id']);
		self::assertSame(1, (int)$row['active'], 'a printer is active unless the request says otherwise');
		self::assertSame(1, (int)$row['is_default']);
		self::assertEqualsCanonicalizing(['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'], json_decode($row['settings'], true));
	}

	#[Depends('testAdminCreatesAPrinter')]
	public function testAnUnknownDriverVersionIsRefusedBeforeAnythingElseAboutTheRequest(): void
	{
		$response = self::Send('PUT', '/api/labels/printers/' . self::$printerId,
			self::PrinterBody(self::$workerId, ['driver_schema_version' => '9.9']), self::$adminKey);

		self::AssertRefusal($response, 422, 'driver_schema_version', 'unknown_driver_version');
		self::assertSame('1.0', self::Row('SELECT driver_schema_version FROM label_printers WHERE id = ?', [self::$printerId])['driver_schema_version']);
	}

	#[Depends('testAdminCreatesAPrinter')]
	public function testPrinterUpdateRenamesTheSameRow(): void
	{
		$response = self::Send('PUT', '/api/labels/printers/' . self::$printerId,
			self::PrinterBody(self::$workerId, ['name' => 'Labelapi printer (renamed)', 'is_default' => 1]), self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::$printerId, (int)$response['json']['id'], 'an update answers with the id it edited rather than a new one');
		self::assertSame('Labelapi printer (renamed)', self::Row('SELECT name FROM label_printers WHERE id = ?', [self::$printerId])['name']);
	}

	/**
	 * Moving a printer between driver schema versions is its own action, and the ordinary
	 * update route refuses to do it silently.
	 */
	#[Depends('testAdminCreatesAPrinter')]
	public function testTheSchemaVersionMoveRouteAcceptsWhatTheUpdateRouteRefuses(): void
	{
		$second = self::Driver();
		$second['schema_version'] = '2.0';
		$registered = self::Send('POST', '/api/labels/register', ['drivers' => [self::Driver(), $second]], self::$workerKey);
		self::assertSame(200, $registered['status'], $registered['body']);

		$body = self::PrinterBody(self::$workerId, ['name' => 'Labelapi printer (renamed)', 'is_default' => 1, 'driver_schema_version' => '2.0']);

		$refused = self::Send('PUT', '/api/labels/printers/' . self::$printerId, $body, self::$adminKey);
		self::AssertRefusal($refused, 422, 'driver_schema_version', 'explicit_move_required');
		self::assertSame('1.0', self::Row('SELECT driver_schema_version FROM label_printers WHERE id = ?', [self::$printerId])['driver_schema_version'],
			'a refused move leaves the printer on the version it was on');

		$moved = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/schema-version', $body, self::$adminKey);
		self::assertSame(200, $moved['status'], $moved['body']);
		self::assertSame(self::$printerId, (int)$moved['json']['id']);
		self::assertSame('2.0', self::Row('SELECT driver_schema_version FROM label_printers WHERE id = ?', [self::$printerId])['driver_schema_version']);

		// Back to the version the rest of this suite prints on.
		$back = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/schema-version',
			self::PrinterBody(self::$workerId, ['name' => 'Labelapi printer (renamed)', 'is_default' => 1]), self::$adminKey);
		self::assertSame(200, $back['status'], $back['body']);
		self::assertSame('1.0', self::Row('SELECT driver_schema_version FROM label_printers WHERE id = ?', [self::$printerId])['driver_schema_version']);
	}

	#[Depends('testAdminCreatesAPrinter')]
	public function testPrinterUpdateOfAnUnknownPrinterIsRefused(): void
	{
		self::AssertRefusal(self::Send('PUT', '/api/labels/printers/999999', self::PrinterBody(self::$workerId), self::$adminKey), 422, 'printer_id', 'not_found');
	}

	// ============================================================ templates

	public function testTemplateRoutesRefuseACallerWithoutAdmin(): void
	{
		$before = self::Counts();

		self::assertSame(403, self::Send('GET', '/api/labels/templates', null, self::$operatorKey)['status']);
		self::assertSame(403, self::Send('POST', '/api/labels/templates', ['name' => 'Smuggled', 'entity_kind' => 'location'], self::$operatorKey)['status']);
		self::assertSame($before['templates'], self::Counts()['templates'], 'a refused template creation writes no row');
	}

	public function testTemplateCreationRefusesAnUnknownEntityKindAndWritesNothing(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/templates', ['name' => 'For a vehicle', 'entity_kind' => 'vehicle'], self::$adminKey);

		self::AssertRefusal($response, 422, 'entity_kind', 'unsupported_entity_kind');
		self::assertSame($before['templates'], self::Counts()['templates']);
	}

	public function testTemplateCreationRefusesABlankName(): void
	{
		$before = self::Counts();

		self::AssertRefusal(self::Send('POST', '/api/labels/templates', ['name' => '  ', 'entity_kind' => 'location'], self::$adminKey), 422, 'name', 'invalid_value');
		self::assertSame($before['templates'], self::Counts()['templates']);
	}

	public function testAdminCreatesATemplateWithAPublishableStartingDraft(): void
	{
		$response = self::Send('POST', '/api/labels/templates',
			['name' => 'Labelapi location label', 'description' => 'For the shelves', 'entity_kind' => 'location'], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		$templateId = (int)$response['json']['id'];
		self::assertSame('location', $response['json']['entity_kind']);
		self::assertNull($response['json']['default_version_id'], 'a new template has published nothing yet');

		$draft = self::Send('GET', "/api/labels/templates/$templateId/draft", null, self::$adminKey);
		self::assertSame(200, $draft['status'], $draft['body']);
		self::assertSame('location', $draft['json']['entity_kind']);
		self::assertSame(1, $draft['json']['document']['schema_version']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $draft['json']['revision_token']);

		self::$templates['location'] = $templateId;
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testAMalformedTemplateDocumentIsRefusedAndTheDraftIsUnchanged(): void
	{
		$templateId = self::$templates['location'];
		$before = self::Row('SELECT document, revision_token FROM label_template_drafts WHERE template_id = ?', [$templateId]);

		$document = self::Document('location');
		$document['elements'][0]['rotation_deg'] = 90;

		$response = self::Send('PUT', "/api/labels/templates/$templateId/draft",
			['document' => $document, 'revision_token' => $before['revision_token']], self::$adminKey);

		self::AssertRefusal($response, 422, 'elements.code.rotation_deg', 'unknown_property');
		self::assertStringContainsString('rotation_deg', $response['json']['error_message'], 'the refusal names the property, not just the document');

		$after = self::Row('SELECT document, revision_token FROM label_template_drafts WHERE template_id = ?', [$templateId]);
		self::assertSame($before['document'], $after['document'], 'a refused draft save writes nothing');
		self::assertSame($before['revision_token'], $after['revision_token'], 'a refused draft save does not burn the revision token');
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testATemplateDocumentForAnotherEntityKindIsRefused(): void
	{
		$templateId = self::$templates['location'];
		$token = self::Row('SELECT revision_token FROM label_template_drafts WHERE template_id = ?', [$templateId])['revision_token'];

		$response = self::Send('PUT', "/api/labels/templates/$templateId/draft",
			['document' => self::Document('product'), 'revision_token' => $token], self::$adminKey);

		self::AssertRefusal($response, 422, 'entity_kind', 'entity_kind_mismatch');
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testATemplateDocumentWithNoElementsIsRefused(): void
	{
		$templateId = self::$templates['location'];
		$token = self::Row('SELECT revision_token FROM label_template_drafts WHERE template_id = ?', [$templateId])['revision_token'];
		$document = self::Document('location');
		$document['elements'] = [];

		self::AssertRefusal(
			self::Send('PUT', "/api/labels/templates/$templateId/draft", ['document' => $document, 'revision_token' => $token], self::$adminKey),
			422, 'elements', 'invalid_value'
		);
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testAStaleRevisionTokenIsAConflictRatherThanASilentOverwrite(): void
	{
		$templateId = self::$templates['location'];

		$response = self::Send('PUT', "/api/labels/templates/$templateId/draft",
			['document' => self::Document('location'), 'revision_token' => str_repeat('0', 32)], self::$adminKey);

		self::AssertRefusal($response, 409, 'revision_token', 'stale_revision');
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testSavingAndPublishingADraftProducesADefaultVersion(): void
	{
		$templateId = self::$templates['location'];
		$token = self::Row('SELECT revision_token FROM label_template_drafts WHERE template_id = ?', [$templateId])['revision_token'];

		$saved = self::Send('PUT', "/api/labels/templates/$templateId/draft",
			['document' => self::Document('location'), 'revision_token' => $token], self::$adminKey);
		self::assertSame(200, $saved['status'], $saved['body']);
		self::assertNotSame($token, $saved['json']['revision_token'], 'a save rotates the token the next writer has to carry');
		self::assertEquals(30.0, $saved['json']['document']['canvas']['height_mm']);

		$published = self::Send('POST', "/api/labels/templates/$templateId/publish", [], self::$adminKey);
		self::assertSame(200, $published['status'], $published['body']);
		self::assertSame(1, (int)$published['json']['version']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $published['json']['document_digest']);

		$template = self::Row('SELECT default_version_id FROM label_templates WHERE id = ?', [$templateId]);
		self::assertSame((int)$published['json']['id'], (int)$template['default_version_id'], 'the first published version becomes the default');

		$versions = self::Send('GET', "/api/labels/templates/$templateId/versions", null, self::$adminKey);
		self::assertSame(200, $versions['status'], $versions['body']);
		self::assertCount(1, $versions['json']);
		self::assertSame(self::ADMIN_USER, (int)$versions['json'][0]['published_by_user_id']);
		self::assertSame(['color_mode' => 'monochrome'], json_decode($versions['json'][0]['required_capabilities'], true),
			'a document that paints no red requires no two-colour media');
	}

	#[Depends('testSavingAndPublishingADraftProducesADefaultVersion')]
	public function testTheDefaultVersionPointerRefusesAVersionOfAnotherTemplate(): void
	{
		$other = self::Send('POST', '/api/labels/templates', ['name' => 'Labelapi throwaway', 'entity_kind' => 'location'], self::$adminKey);
		$otherId = (int)$other['json']['id'];
		$otherVersion = self::Send('POST', "/api/labels/templates/$otherId/publish", [], self::$adminKey);
		self::assertSame(200, $otherVersion['status'], $otherVersion['body']);

		$templateId = self::$templates['location'];
		$before = self::Row('SELECT default_version_id FROM label_templates WHERE id = ?', [$templateId])['default_version_id'];

		self::AssertRefusal(
			self::Send('PUT', "/api/labels/templates/$templateId/default-version", ['version_id' => (int)$otherVersion['json']['id']], self::$adminKey),
			422, 'version_id', 'not_found'
		);
		self::assertSame($before, self::Row('SELECT default_version_id FROM label_templates WHERE id = ?', [$templateId])['default_version_id']);

		// Its own version is accepted, so the refusal above is about ownership.
		$own = self::Send('PUT', "/api/labels/templates/$templateId/default-version", ['version_id' => (int)$before], self::$adminKey);
		self::assertSame(200, $own['status'], $own['body']);

		// The throwaway is archived so it cannot become the default template for locations.
		$archived = self::Send('POST', "/api/labels/templates/$otherId/archive", [], self::$adminKey);
		self::assertSame(200, $archived['status'], $archived['body']);
		self::assertNotNull($archived['json']['archived_at']);

		$republish = self::Send('POST', "/api/labels/templates/$otherId/publish", [], self::$adminKey);
		self::AssertRefusal($republish, 422, 'template_id', 'archived');
	}

	#[Depends('testSavingAndPublishingADraftProducesADefaultVersion')]
	public function testTheTemplateListNamesTheDefaultVersionAndNothingElse(): void
	{
		$response = self::Send('GET', '/api/labels/templates', null, self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		$byId = [];

		foreach ($response['json'] as $row)
		{
			$byId[(int)$row['id']] = $row;
		}

		self::assertArrayHasKey(self::$templates['location'], $byId);
		self::assertSame(1, (int)$byId[self::$templates['location']]['default_version'], 'the list resolves the default version number');
		self::assertSame('Labelapi location label', $byId[self::$templates['location']]['name']);
	}

	#[Depends('testAdminCreatesATemplateWithAPublishableStartingDraft')]
	public function testAnUnknownTemplateIsNotFound(): void
	{
		self::AssertRefusal(self::Send('GET', '/api/labels/templates/999999/draft', null, self::$adminKey), 422, 'template_id', 'not_found');
		self::AssertRefusal(self::Send('POST', '/api/labels/templates/999999/publish', [], self::$adminKey), 422, 'template_id', 'not_found');
	}

	#[Depends('testSavingAndPublishingADraftProducesADefaultVersion')]
	public function testTemplatesExistForEveryKindThePrintRoutesAccept(): void
	{
		foreach (['product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind)
		{
			self::$templates[$kind] = self::PublishTemplate($kind, 'Labelapi ' . $kind . ' label');
		}

		self::assertCount(6, self::$templates);
	}

	// ============================================================ assets

	public function testAnAssetUploadCarriesItsBytesAndIsRefusedWithoutThem(): void
	{
		$before = self::Counts();

		self::AssertRefusal(self::Send('POST', '/api/labels/assets', ['name' => 'Missing', 'asset_kind' => 'image', 'mime_type' => 'image/png', 'licence' => 'CC0'], self::$adminKey),
			422, 'content_base64', 'invalid_value');
		self::AssertRefusal(self::Send('POST', '/api/labels/assets', ['name' => 'Bad', 'asset_kind' => 'image', 'mime_type' => 'image/png', 'licence' => 'CC0', 'content_base64' => '!!not base64!!'], self::$adminKey),
			422, 'content_base64', 'invalid_value');
		self::assertSame($before['assets'], self::Counts()['assets'], 'a refused asset upload stores nothing');
	}

	public function testAdminStoresAnImageAssetAndItAppearsInTheList(): void
	{
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

		$response = self::Send('POST', '/api/labels/assets', [
			'name' => 'Labelapi dot',
			'asset_kind' => 'image',
			'mime_type' => 'image/png',
			'licence' => 'CC0-1.0',
			'licence_notice' => 'Public domain',
			'content_base64' => base64_encode($png),
		], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(1, (int)$response['json']['width_px']);
		self::assertSame(1, (int)$response['json']['height_px']);
		self::assertSame(hash('sha256', $png), $response['json']['content_digest']);

		$list = self::Send('GET', '/api/labels/assets', null, self::$adminKey);
		self::assertSame(200, $list['status'], $list['body']);
		$names = array_column($list['json'], 'name');
		self::assertContains('Labelapi dot', $names);

		// The list is a catalogue, not a download: the bytes have their own authorized route.
		self::assertArrayNotHasKey('content', $list['json'][0]);
		self::assertArrayNotHasKey('content_base64', $list['json'][0]);
	}

	public function testAnAssetDeclaringTheWrongMimeTypeIsRefused(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/assets', [
			'name' => 'Labelapi fake font',
			'asset_kind' => 'font',
			'mime_type' => 'font/ttf',
			'licence' => 'CC0-1.0',
			'content_base64' => base64_encode('not a font at all, but long enough'),
		], self::$adminKey);

		self::AssertRefusal($response, 422, 'content', 'invalid_value');
		self::assertSame($before['assets'], self::Counts()['assets']);
	}

	public function testTheAssetRoutesRefuseACallerWithoutAdmin(): void
	{
		self::assertSame(403, self::Send('GET', '/api/labels/assets', null, self::$operatorKey)['status']);
		self::assertSame(403, self::Send('POST', '/api/labels/assets', ['name' => 'X'], self::$operatorKey)['status']);
	}

	// ============================================================ print operations

	#[Depends('testTemplatesExistForEveryKindThePrintRoutesAccept')]
	#[Depends('testAdminCreatesAPrinter')]
	public function testPrintingRefusesACallerWithoutMasterDataEdit(): void
	{
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);
		$before = self::Counts();

		$response = self::Send('POST', '/api/labels/location/' . self::$targets['location'] . '/print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertSame($before['labels'], self::Counts()['labels'], 'a refused print mints no label');
		self::assertSame($before['print_requests'], self::Counts()['print_requests'], 'a refused print enqueues no outbox event');
	}

	#[Depends('testPrintingRefusesACallerWithoutMasterDataEdit')]
	public function testPrintingRefusesACallerWithoutTheDomainReadGrant(): void
	{
		// MASTER_DATA_EDIT alone is not enough: capturing the value takes the read grant
		// that value's own domain uses everywhere else.
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_MASTER_DATA_EDIT]);
		$before = self::Counts();

		$response = self::Send('POST', '/api/labels/recipe/' . self::$targets['recipe'] . '/print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertSame($before['labels'], self::Counts()['labels']);
		self::assertSame($before['print_requests'], self::Counts()['print_requests']);
	}

	#[Depends('testPrintingRefusesACallerWithoutTheDomainReadGrant')]
	public function testPrintingRefusesARequestWithNoIntegerEpochOrPrinterAndWritesNothing(): void
	{
		$path = '/api/labels/location/' . self::$targets['location'] . '/print';
		$before = self::Counts();

		self::AssertRefusal(self::Send('POST', $path, ['printer_id' => self::$printerId], self::$operatorKey), 422, 'import_epoch', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', $path, ['import_epoch' => 0], self::$operatorKey), 422, 'printer_id', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', $path, ['import_epoch' => '0', 'printer_id' => self::$printerId], self::$operatorKey), 422, 'import_epoch', 'value_out_of_range');

		$after = self::Counts();
		self::assertSame($before['labels'], $after['labels']);
		self::assertSame($before['print_jobs'], $after['print_jobs']);
		self::assertSame($before['print_requests'], $after['print_requests']);
	}

	#[Depends('testPrintingRefusesARequestWithNoIntegerEpochOrPrinterAndWritesNothing')]
	public function testAStaleImportEpochIsRefusedAndMintsNoLabel(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/location/' . self::$targets['location'] . '/print',
			['import_epoch' => 7, 'printer_id' => self::$printerId], self::$operatorKey);

		self::AssertRefusal($response, 422, 'import_epoch', 'stale_target_context');

		$after = self::Counts();
		self::assertSame($before['labels'], $after['labels'], 'a refused print mints no label');
		self::assertSame($before['print_jobs'], $after['print_jobs']);
		self::assertSame($before['print_requests'], $after['print_requests'], 'the label row and the outbox event stand or fall together');
	}

	#[Depends('testPrintingRefusesARequestWithNoIntegerEpochOrPrinterAndWritesNothing')]
	public function testPrintingAnUnknownTargetIsRefusedAndMintsNoLabel(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/product/999999/print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::AssertRefusal($response, 422, 'import_epoch', 'stale_target_context');
		self::assertSame($before['labels'], self::Counts()['labels']);
		self::assertSame($before['print_requests'], self::Counts()['print_requests']);
	}

	#[Depends('testPrintingRefusesARequestWithNoIntegerEpochOrPrinterAndWritesNothing')]
	public function testPrintingAnUnknownPrinterIsRefusedAndMintsNoLabel(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/chore/' . self::$targets['chore'] . '/print',
			['import_epoch' => 0, 'printer_id' => 999999], self::$operatorKey);

		self::AssertRefusal($response, 422, 'printer_id', 'inactive_printer');
		self::assertSame($before['labels'], self::Counts()['labels'], 'the label is not minted before the printer resolves');
		self::assertSame($before['print_requests'], self::Counts()['print_requests']);
	}

	/**
	 * The heart of the subsystem: for every kind, a print request creates the label row and
	 * the `label.print_requested` outbox event in one transaction.
	 */
	#[Depends('testPrintingAnUnknownPrinterIsRefusedAndMintsNoLabel')]
	public function testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether(): void
	{
		foreach (['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind)
		{
			$targetId = self::$targets[$kind];
			$response = self::Send('POST', "/api/labels/$kind/$targetId/print",
				['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

			self::assertSame(202, $response['status'], "$kind print: " . $response['body']);

			$payload = $response['json'];
			self::assertSame(['job_id', 'label_uid', 'operation', 'artifact_id', 'render_request_id', 'state'], array_keys($payload), "$kind response shape");
			self::assertSame('issue', $payload['operation']);
			self::assertNull($payload['artifact_id'], 'a job is not finished when the response is written');
			self::assertSame('awaiting_artifact', $payload['state']);
			self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{13}$/', $payload['label_uid'], 'the payload is 13 Crockford base32 characters');

			$label = self::Row('SELECT kind, target_id, retired_at FROM labels WHERE uid = ?', [$payload['label_uid']]);
			self::assertNotNull($label, "$kind: the label row exists after a 202");
			self::assertSame($kind, $label['kind']);
			self::assertSame($targetId, (int)$label['target_id']);
			self::assertNull($label['retired_at']);

			$job = self::Row('SELECT outbox_id, printer_id, label_uid, operation, artifact_id FROM print_jobs WHERE id = ?', [(int)$payload['job_id']]);
			self::assertNotNull($job, "$kind: the print job row exists after a 202");
			self::assertSame($payload['label_uid'], $job['label_uid']);
			self::assertSame(self::$printerId, (int)$job['printer_id']);
			self::assertNull($job['artifact_id']);

			$event = self::Row('SELECT event_type, payload, delivered_at FROM outbox WHERE id = ?', [(int)$job['outbox_id']]);
			self::assertNotNull($event, "$kind: the outbox event exists after a 202");
			self::assertSame('label.print_requested', $event['event_type']);
			self::assertNull($event['delivered_at'], 'the event is enqueued, not delivered, by the request that created it');
			$enqueued = json_decode($event['payload'], true);
			self::assertSame(2, $enqueued['payload_version'], 'the job payload names the version a consumer has to understand');
			self::assertSame($payload['label_uid'], $enqueued['label_uid']);
			self::assertSame($kind, $enqueued['kind']);
			self::assertSame(self::$printerId, (int)$enqueued['printer_id']);

			if ($kind === 'location')
			{
				self::$locationJobId = (int)$payload['job_id'];
				self::$locationUid = $payload['label_uid'];
			}
		}
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testTheLocationSpecificPrintRouteIssuesTheSameIdentity(): void
	{
		// `/labels/locations/{id}/print` predates the generic pair and is the same operation.
		$response = self::Send('POST', '/api/labels/locations/' . self::$targets['location'] . '/print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(202, $response['status'], $response['body']);
		self::assertSame(self::$locationUid, $response['json']['label_uid'], 'a second print of the same target reuses its identity');
		self::assertNotSame(self::$locationJobId, (int)$response['json']['job_id'], 'and is still its own job');
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testARevisedPrintKeepsTheIdentityAndCapturesAgain(): void
	{
		$response = self::Send('POST', '/api/labels/locations/' . self::$targets['location'] . '/revised-print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(202, $response['status'], $response['body']);
		self::assertSame('revised_print', $response['json']['operation']);
		self::assertSame(self::$locationUid, $response['json']['label_uid']);
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testARevisedPrintRefusesATargetWithNoLiveLabel(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/locations/' . self::$targets['other_location'] . '/revised-print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey);

		self::AssertRefusal($response, 422, 'target_id', 'no_live_label');
		self::assertSame($before['labels'], self::Counts()['labels'], 'a revised print keeps an existing identity rather than minting one');
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testAnIdempotencyKeyReplaysTheFirstJobAndRefusesAChangedRequest(): void
	{
		$key = 'labelapi-idempotency-' . bin2hex(random_bytes(6));
		$path = '/api/labels/battery/' . self::$targets['battery'] . '/print';
		$body = ['import_epoch' => 0, 'printer_id' => self::$printerId];

		$first = self::Send('POST', $path, $body, self::$operatorKey, ['Idempotency-Key' => $key]);
		self::assertSame(202, $first['status'], $first['body']);

		$before = self::Counts();
		$replay = self::Send('POST', $path, $body, self::$operatorKey, ['Idempotency-Key' => $key]);
		self::assertSame(200, $replay['status'], 'a replay is the stored resource, not a new one: ' . $replay['body']);
		self::assertSame($before['print_jobs'], self::Counts()['print_jobs'], 'a replay prints nothing a second time');

		// The replay returns the same JSON object shape as the original 202 response
		// assertEquals rather than assertSame: the stored payload is jsonb, which does not
		// keep the key order the first response was written in.
		self::assertEquals($first['json'], $replay['json'], 'the replayed resource is returned as an object, in the same shape as the first response');

		$changed = self::Send('POST', $path, $body + ['locale' => 'de'], self::$operatorKey, ['Idempotency-Key' => $key]);
		self::AssertRefusal($changed, 409, 'idempotency_key', 'idempotency_conflict');
		self::assertSame($before['print_jobs'], self::Counts()['print_jobs']);
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testAMalformedIdempotencyKeyIsRefused(): void
	{
		$response = self::Send('POST', '/api/labels/battery/' . self::$targets['battery'] . '/print',
			['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey, ['Idempotency-Key' => 'short']);

		self::AssertRefusal($response, 422, 'idempotency_key', 'invalid_value');
	}

	// ============================================================ the renderer

	#[Depends('testAdminCreatesADeclaredWorker')]
	public function testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes(): void
	{
		$response = self::Send('POST', '/api/labels/workers/' . self::$workerId . '/renderer-credentials', [], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::$rendererKey = $response['json']['credential'];
		self::assertSame(ApiKeyService::API_KEY_TYPE_LABEL_RENDERER,
			self::Row('SELECT key_type FROM api_keys WHERE id = ?', [(int)$response['json']['credential_id']])['key_type']);

		// "May not claim a print attempt" is a property of the credential rather than a rule
		// the renderer is trusted to follow.
		self::assertSame(401, self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], self::$rendererKey)['status']);
	}

	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	#[Depends('testAdminIssuesADeclaredWorkerCredential')]
	public function testAWorkerKeyIsRefusedOnTheRendererRoutes(): void
	{
		self::assertSame(401, self::Send('POST', '/api/labels/render/claim', [], self::$workerKey)['status']);
		self::assertSame(401, self::Send('POST', '/api/labels/render/claim', [], self::$adminKey)['status']);
	}

	// The two claim-limit tests take whatever job is claimable, so they are declared here
	// rather than left to the order of the file: once this test attaches an artifact there
	// is a claimable job, and a claim made after that point would take it.
	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	#[Depends('testAnExpiredWorkerCredentialIsRefused')]
	#[Depends('testClaimLimitOutsideOneThroughFiftyIsRefused')]
	public function testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob(): void
	{
		$claim = self::Send('POST', '/api/labels/render/claim', [], self::$rendererKey);
		self::assertSame(200, $claim['status'], $claim['body']);

		$input = $claim['json'];
		self::assertSame('production', $input['purpose']);
		self::assertSame('raster/png-indexed;v=1', $input['expected_form']);
		self::assertArrayHasKey('code', $input['qr'], 'the module matrix Victual computed travels with the claim');
		self::assertArrayNotHasKey('url', $input, 'the renderer is handed identifiers, never a fetch destination');

		$result = self::Send('POST', '/api/labels/render/' . (int)$input['render_request_id'] . '/result', [
			'generation_token' => $input['generation_token'],
			'artifact_base64' => base64_encode(paintArtifact($input)),
			'renderer_id' => 'labelapi-fixture-renderer',
			'renderer_version' => '0.0.1-fixture',
		], self::$rendererKey);

		self::assertSame(200, $result['status'], $result['body']);
		self::assertSame(['artifact_id', 'byte_digest', 'manifest_digest', 'jobs_attached'], array_keys($result['json']));
		self::assertSame(1, (int)$result['json']['jobs_attached']);
		self::$locationArtifactId = (int)$result['json']['artifact_id'];

		$job = self::Row('SELECT artifact_id FROM print_jobs WHERE id = ?', [self::$locationJobId]);
		self::assertSame(self::$locationArtifactId, (int)$job['artifact_id'], 'attaching the artifact is what makes the job claimable');

		$outbox = self::Row('SELECT payload FROM outbox WHERE id = (SELECT outbox_id FROM print_jobs WHERE id = ?)', [self::$locationJobId]);
		self::assertSame(self::$locationArtifactId, (int)json_decode($outbox['payload'], true)['artifact']['id']);
	}

	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	public function testAStaleGenerationTokenCannotReplaceACommittedArtifact(): void
	{
		$requestId = (int)self::Row('SELECT render_request_id FROM print_jobs WHERE id = ?', [self::$locationJobId])['render_request_id'];

		$response = self::Send('POST', "/api/labels/render/$requestId/result", [
			'generation_token' => str_repeat('a', 32),
			'artifact_base64' => base64_encode('anything'),
		], self::$rendererKey);

		self::AssertRefusal($response, 422, 'generation_token', 'already_committed');
	}

	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	public function testARenderResultCarriesItsArtifactBytes(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/render/1/result', ['generation_token' => 'x'], self::$rendererKey), 422, 'artifact_base64', 'invalid_value');
		self::AssertRefusal(self::Send('POST', '/api/labels/render/1/result', ['generation_token' => 'x', 'artifact_base64' => '!!!'], self::$rendererKey), 422, 'artifact_base64', 'invalid_value');
	}

	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	public function testTheRendererReportsAnInvalidDocumentAndTheStatusRouteSaysSo(): void
	{
		$claim = self::Send('POST', '/api/labels/render/claim', [], self::$rendererKey);
		self::assertSame(200, $claim['status'], $claim['body']);
		$requestId = (int)$claim['json']['render_request_id'];

		$reported = self::Send('POST', "/api/labels/render/$requestId/invalid", [
			'generation_token' => $claim['json']['generation_token'],
			'code' => 'TEXT_OVERFLOW',
			'element' => 'code',
			'detail' => 'The fixture renderer refused it on purpose',
		], self::$rendererKey);
		self::assertSame(200, $reported['status'], $reported['body']);

		$status = self::Send('GET', "/api/labels/renders/$requestId", null, self::$adminKey);
		self::assertSame(200, $status['status'], $status['body']);
		self::assertSame('invalid', $status['json']['state']);
		self::assertSame('TEXT_OVERFLOW', $status['json']['error_code']);
		self::assertSame('code', $status['json']['error_element']);
		self::assertNull($status['json']['artifact_id']);
		self::assertFalse($status['json']['retryable'], 'an input error is not something retrying fixes');
	}

	#[Depends('testTheRendererReportsAnInvalidDocumentAndTheStatusRouteSaysSo')]
	public function testTheRendererReportsInfrastructureFailureAndTheRequestGoesBackToPending(): void
	{
		$claim = self::Send('POST', '/api/labels/render/claim', [], self::$rendererKey);
		self::assertSame(200, $claim['status'], $claim['body']);
		$requestId = (int)$claim['json']['render_request_id'];

		$reported = self::Send('POST', "/api/labels/render/$requestId/failed",
			['generation_token' => $claim['json']['generation_token'], 'detail' => 'The fixture renderer lost its socket'], self::$rendererKey);
		self::assertSame(200, $reported['status'], $reported['body']);

		$status = self::Send('GET', "/api/labels/renders/$requestId", null, self::$adminKey);
		self::assertSame('pending', $status['json']['state'], 'infrastructure failure is retried, unlike an invalid document');
		self::assertSame('RENDER_FAILED', $status['json']['error_code']);
		self::assertTrue($status['json']['retryable']);
	}

	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	public function testAnUnknownRenderRequestIsRefused(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/render/999999/failed', ['generation_token' => 'x', 'detail' => 'y'], self::$rendererKey),
			422, 'render_request_id', 'not_found');
		self::AssertRefusal(self::Send('GET', '/api/labels/renders/999999', null, self::$adminKey), 422, 'render_request_id', 'not_found');
	}

	#[Depends('testAdminStoresAnImageAssetAndItAppearsInTheList')]
	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	public function testTheRendererFetchesAssetBytesAndGetsA404ForOneThatIsNotStored(): void
	{
		$assetId = (int)self::Row("SELECT id FROM label_assets WHERE name = 'Labelapi dot'")['id'];

		$response = self::Send('GET', "/api/labels/assets/$assetId/bytes", null, self::$rendererKey);
		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame("\x89PNG", substr($response['body'], 0, 4), 'the bytes are the exact stored ones');

		$missing = self::Send('GET', '/api/labels/assets/999999/bytes', null, self::$rendererKey);
		self::AssertRefusal($missing, 404, 'asset_id', 'not_found');
	}

	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	public function testAnArtifactIsReachableByItsOwnerAndNotByADisinterestedWorker(): void
	{
		// No live attempt carries the artifact yet, so even the owning worker is refused:
		// authorization follows the owning attempt, never the digest.
		$response = self::Send('GET', '/api/labels/artifacts/' . self::$locationArtifactId . '/bytes', null, self::$workerKey);
		self::AssertRefusal($response, 422, 'artifact_id', 'forbidden');

		// The renderer's key is not accepted on the worker's byte route at all.
		self::assertSame(401, self::Send('GET', '/api/labels/artifacts/' . self::$locationArtifactId . '/bytes', null, self::$rendererKey)['status']);
	}

	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	public function testThePreviewImageRouteServesThePngToAnAdministratorAndNobodyElse(): void
	{
		$response = self::Send('GET', '/api/labels/artifacts/' . self::$locationArtifactId . '/image', null, self::$adminKey);

		self::assertSame(200, $response['status'], substr($response['body'], 0, 200));
		self::assertSame("\x89PNG", substr($response['body'], 0, 4));

		self::assertSame(403, self::Send('GET', '/api/labels/artifacts/' . self::$locationArtifactId . '/image', null, self::$operatorKey)['status']);
		self::AssertRefusal(self::Send('GET', '/api/labels/artifacts/999999/image', null, self::$adminKey), 422, 'artifact_id', 'not_found');
	}

	// ============================================================ the worker protocol

	// Declared on the test above as well: that one asserts the artifact is unreachable while
	// no attempt carries it, which is only true until this claim creates the attempt.
	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	#[Depends('testAnArtifactIsReachableByItsOwnerAndNotByADisinterestedWorker')]
	public function testTheWorkerClaimsTheJobWhoseArtifactIsReady(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], self::$workerKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertCount(1, $response['json'], 'exactly the one job with an attached artifact is claimable');

		$claim = $response['json'][0];
		self::assertSame(['attempt', 'payload', 'printer', 'artifact'], array_keys($claim));
		self::assertSame(self::$locationJobId, (int)$claim['attempt']['job_id']);
		self::assertSame('raster/png-indexed;v=1', $claim['artifact']['form'], 'the manifest travels with the claim; the bytes do not');
		self::assertArrayNotHasKey('artifact_base64', $claim);

		self::$attemptId = (int)$claim['attempt']['id'];
	}

	#[Depends('testTheWorkerClaimsTheJobWhoseArtifactIsReady')]
	public function testTheClaimingWorkerCanNowFetchTheArtifactBytes(): void
	{
		$response = self::Send('GET', '/api/labels/artifacts/' . self::$locationArtifactId . '/bytes', null, self::$workerKey);

		self::assertSame(200, $response['status'], substr($response['body'], 0, 200));
		self::assertSame("\x89PNG", substr($response['body'], 0, 4));
	}

	#[Depends('testTheWorkerClaimsTheJobWhoseArtifactIsReady')]
	#[Depends('testAPairedWorkerPairsOverThePublicRouteExactlyOnce')]
	public function testAnotherWorkersAttemptIsForbiddenOnEveryAttemptRoute(): void
	{
		foreach (['heartbeat', 'sent', 'evidence'] as $step)
		{
			$response = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/' . $step, [], self::$pairedKey);
			self::AssertRefusal($response, 403, 'attempt_id', 'forbidden');
		}

		$result = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/result',
			['outcome' => 'printed', 'detail' => ['device' => 'complete']], self::$pairedKey);
		self::AssertRefusal($result, 403, 'attempt_id', 'forbidden');

		self::assertNull(self::Row('SELECT reported_outcome FROM print_attempts WHERE id = ?', [self::$attemptId])['reported_outcome'],
			'a forbidden report writes nothing on the attempt');
	}

	#[Depends('testTheWorkerClaimsTheJobWhoseArtifactIsReady')]
	public function testTheWorkerHeartbeatsAndReportsTheBytesSent(): void
	{
		$heartbeat = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/heartbeat', [], self::$workerKey);
		self::assertSame(200, $heartbeat['status'], $heartbeat['body']);
		self::assertSame(1, (int)$heartbeat['json']['heartbeats']);

		$sent = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/sent', [], self::$workerKey);
		self::assertSame(200, $sent['status'], $sent['body']);
		self::assertNotNull($sent['json']['bytes_sent_at']);

		$again = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/sent', [], self::$workerKey);
		self::assertSame($sent['json']['bytes_sent_at'], $again['json']['bytes_sent_at'], 'reporting the send twice is idempotent');
	}

	#[Depends('testTheWorkerHeartbeatsAndReportsTheBytesSent')]
	public function testEvidenceIsValidatedAndDeduplicated(): void
	{
		$path = '/api/labels/attempts/' . self::$attemptId . '/evidence';
		$evidence = [
			'submission_id' => 'labelapi-evidence-1',
			'evidence_type' => 'device_status',
			'observed_at' => '2026-09-08T00:00:00Z',
			'printer_status' => ['state' => 'idle'],
			'detail' => ['state' => 'idle'],
		];

		self::AssertRefusal(self::Send('POST', $path, [], self::$workerKey), 422, 'submission_id', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', $path, ['submission_id' => 'x', 'evidence_type' => 'guesswork', 'observed_at' => '2026-09-08T00:00:00Z', 'detail' => ['a' => 1]], self::$workerKey),
			422, 'evidence_type', 'value_out_of_range');

		$first = self::Send('POST', $path, $evidence, self::$workerKey);
		self::assertSame(200, $first['status'], $first['body']);

		$second = self::Send('POST', $path, $evidence, self::$workerKey);
		self::assertSame(200, $second['status'], $second['body']);
		self::assertSame((int)$first['json']['id'], (int)$second['json']['id'], 'the same submission id is one observation');
	}

	#[Depends('testTheWorkerHeartbeatsAndReportsTheBytesSent')]
	#[Depends('testAPairedWorkerPairsOverThePublicRouteExactlyOnce')]
	public function testThePrinterStatusRouteRefusesAPrinterThisWorkerDoesNotHold(): void
	{
		$reported = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/status',
			['device_state' => 'idle', 'reported_media' => ['media' => '62red']], self::$workerKey);
		self::assertSame(200, $reported['status'], $reported['body']);
		self::assertSame('idle', $reported['json']['device_state']);

		$foreign = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/status', ['device_state' => 'idle'], self::$pairedKey);
		self::AssertRefusal($foreign, 403, 'printer_id', 'forbidden');

		$wrongType = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/status', ['device_state' => 7], self::$workerKey);
		self::AssertRefusal($wrongType, 422, 'device_state', 'value_out_of_range');
	}

	#[Depends('testEvidenceIsValidatedAndDeduplicated')]
	public function testAResultOutcomeIsPrintedOrFailedAndNothingElse(): void
	{
		$response = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/result',
			['outcome' => 'probably', 'detail' => []], self::$workerKey);

		self::AssertRefusal($response, 422, 'outcome', 'value_out_of_range');
		self::assertNull(self::Row('SELECT reported_outcome FROM print_attempts WHERE id = ?', [self::$attemptId])['reported_outcome']);

		self::AssertRefusal(self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/result', ['outcome' => 'printed'], self::$workerKey),
			422, 'detail', 'value_out_of_range');
	}

	// Reporting the outcome ends the attempt, so the two tests that read it while it is live
	// - the foreign worker's refusal, and the owner's fetch of the bytes - are declared here.
	#[Depends('testAResultOutcomeIsPrintedOrFailedAndNothingElse')]
	#[Depends('testAnotherWorkersAttemptIsForbiddenOnEveryAttemptRoute')]
	#[Depends('testTheClaimingWorkerCanNowFetchTheArtifactBytes')]
	public function testTheWorkerReportsAPrintAndTheJobIsCompleted(): void
	{
		$response = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/result',
			['outcome' => 'printed', 'detail' => ['device' => 'complete']], self::$workerKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('printed', $response['json']['reported_outcome']);

		$job = self::Row('SELECT outcome, outcome_at, outbox_id FROM print_jobs WHERE id = ?', [self::$locationJobId]);
		self::assertSame('printed', $job['outcome'], 'the application is what marks the job done, when the report arrives');
		self::assertNotNull($job['outcome_at']);
		self::assertNotNull(self::Row('SELECT delivered_at FROM outbox WHERE id = ?', [(int)$job['outbox_id']])['delivered_at']);
	}

	#[Depends('testTheWorkerReportsAPrintAndTheJobIsCompleted')]
	public function testAConflictingSecondResultIsRefused(): void
	{
		$response = self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/result',
			['outcome' => 'failed', 'detail' => ['device' => 'complete']], self::$workerKey);

		self::AssertRefusal($response, 422, 'result', 'conflicting_report');
		self::assertSame('printed', self::Row('SELECT reported_outcome FROM print_attempts WHERE id = ?', [self::$attemptId])['reported_outcome']);
	}

	#[Depends('testTheWorkerReportsAPrintAndTheJobIsCompleted')]
	public function testAHeartbeatAfterTheLeaseEndedIsRefused(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/attempts/' . self::$attemptId . '/heartbeat', [], self::$workerKey),
			422, 'attempt_id', 'lease_ended');
	}

	// ============================================================ reprint, cancel, monitor

	#[Depends('testTheWorkerReportsAPrintAndTheJobIsCompleted')]
	public function testAReprintReplaysTheStoredBytesOfTheSameLabel(): void
	{
		$before = self::Counts();
		$response = self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/reprint', [], self::$operatorKey);

		self::assertSame(202, $response['status'], $response['body']);
		self::assertSame('reprint', $response['json']['operation']);
		self::assertSame(self::$locationUid, $response['json']['label_uid']);
		self::assertSame(self::$locationArtifactId, (int)$response['json']['artifact_id'], 'a reprint replays the artifact rather than rerendering');
		self::assertSame('queued', $response['json']['state']);

		$job = self::Row('SELECT source_job_id, artifact_id FROM print_jobs WHERE id = ?', [(int)$response['json']['job_id']]);
		self::assertSame(self::$locationJobId, (int)$job['source_job_id']);
		self::assertSame($before['print_requests'] + 1, self::Counts()['print_requests']);
	}

	#[Depends('testAReprintReplaysTheStoredBytesOfTheSameLabel')]
	public function testAReprintOfAJobThatNeverHadAnArtifactIsRefused(): void
	{
		$jobId = (int)self::Row('SELECT id FROM print_jobs WHERE artifact_id IS NULL ORDER BY id LIMIT 1')['id'];
		$before = self::Counts();

		self::AssertRefusal(self::Send('POST', "/api/labels/jobs/$jobId/reprint", [], self::$operatorKey), 422, 'job_id', 'no_artifact');
		self::assertSame($before['print_jobs'], self::Counts()['print_jobs']);
	}

	#[Depends('testAReprintReplaysTheStoredBytesOfTheSameLabel')]
	public function testAReprintOfAnUnknownJobIsRefused(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/jobs/999999/reprint', [], self::$operatorKey), 422, 'job_id', 'not_found');
	}

	#[Depends('testAReprintReplaysTheStoredBytesOfTheSameLabel')]
	public function testAReprintRefusesACallerWithoutMasterDataEdit(): void
	{
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);
		$before = self::Counts();

		self::assertSame(403, self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/reprint', [], self::$operatorKey)['status']);
		self::assertSame($before['print_jobs'], self::Counts()['print_jobs']);
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testCancellingAnUnclaimedJobDeadLettersItsOutboxRow(): void
	{
		$jobId = (int)self::Row('SELECT id FROM print_jobs WHERE current_attempt_id IS NULL AND cancelled_at IS NULL AND outcome IS NULL ORDER BY id DESC LIMIT 1')['id'];

		$response = self::Send('POST', "/api/labels/jobs/$jobId/cancel", ['reason' => 'The shelf was moved'], self::$operatorKey);

		self::assertSame(200, $response['status'], 'a cancellation is finished when it answers, so it is 200 and not 202: ' . $response['body']);
		self::assertSame('cancelled', $response['json']['state']);

		$job = self::Row('SELECT cancelled_at, cancelled_reason, outbox_id FROM print_jobs WHERE id = ?', [$jobId]);
		self::assertNotNull($job['cancelled_at']);
		self::assertSame('The shelf was moved', $job['cancelled_reason']);

		$outbox = self::Row('SELECT dead_lettered_at, last_error FROM outbox WHERE id = ?', [(int)$job['outbox_id']]);
		self::assertNotNull($outbox['dead_lettered_at'], 'a cancelled job is retired from the outbox visibly');
		self::assertStringContainsString('The shelf was moved', $outbox['last_error']);

		$again = self::Send('POST', "/api/labels/jobs/$jobId/cancel", [], self::$operatorKey);
		self::assertSame(200, $again['status'], 'cancelling twice is not an error');
	}

	#[Depends('testTheWorkerReportsAPrintAndTheJobIsCompleted')]
	public function testCancellingAJobThatHasAlreadyBeenAttemptedIsAConflict(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/cancel', [], self::$operatorKey);

		self::AssertRefusal($response, 409, 'job_id', 'already_claimed');
		self::assertNull(self::Row('SELECT cancelled_at FROM print_jobs WHERE id = ?', [self::$locationJobId])['cancelled_at'],
			'a refused cancellation does not record a print that physically happened as never having happened');
	}

	#[Depends('testCancellingAnUnclaimedJobDeadLettersItsOutboxRow')]
	public function testCancellingAnUnknownJobIsRefused(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/jobs/999999/cancel', [], self::$operatorKey), 422, 'job_id', 'not_found');
	}

	// The three states this view is read for are each put there by a different test: the
	// cancellation, the printed outcome, and the name the printer was given.
	#[Depends('testCancellingAnUnclaimedJobDeadLettersItsOutboxRow')]
	#[Depends('testTheWorkerReportsAPrintAndTheJobIsCompleted')]
	#[Depends('testPrinterUpdateRenamesTheSameRow')]
	public function testTheJobMonitorReportsEveryJobWithItsPrinterAndState(): void
	{
		$response = self::Send('GET', '/api/labels/jobs', null, self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		$byId = [];

		foreach ($response['json'] as $row)
		{
			$byId[(int)$row['id']] = $row;
		}

		self::assertArrayHasKey(self::$locationJobId, $byId);
		self::assertSame('reported', $byId[self::$locationJobId]['state'], 'a printed outcome appears as reported in this view');
		self::assertSame('Labelapi printer (renamed)', $byId[self::$locationJobId]['printer_name']);

		$cancelled = array_values(array_filter($response['json'], static fn (array $row): bool => $row['state'] === 'cancelled'));
		self::assertNotEmpty($cancelled, 'a cancelled job is its own state rather than dead_lettered');

		self::assertSame(403, self::Send('GET', '/api/labels/jobs', null, self::$operatorKey)['status']);
	}

	#[Depends('testTheJobMonitorReportsEveryJobWithItsPrinterAndState')]
	public function testAuthorizingAnotherAttemptRefusesWhenTheJobAlreadySucceeded(): void
	{
		$response = self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/authorize-attempt',
			['attempt_id' => self::$attemptId], self::$adminKey);

		self::AssertRefusal($response, 422, 'attempt_id', 'already_completed');
	}

	#[Depends('testTheJobMonitorReportsEveryJobWithItsPrinterAndState')]
	public function testAuthorizingAnotherAttemptNeedsAPositiveAttemptIdentifier(): void
	{
		self::AssertRefusal(self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/authorize-attempt', ['attempt_id' => 0], self::$adminKey),
			422, 'attempt_id', 'value_out_of_range');
		self::AssertRefusal(self::Send('POST', '/api/labels/jobs/' . self::$locationJobId . '/authorize-attempt', [], self::$adminKey),
			422, 'attempt_id', 'value_out_of_range');
	}

	#[Depends('testTheJobMonitorReportsEveryJobWithItsPrinterAndState')]
	public function testAuthorizingAnotherAttemptOnAJobWithNoCurrentAttemptIsRefused(): void
	{
		$jobId = (int)self::Row('SELECT id FROM print_jobs WHERE current_attempt_id IS NULL ORDER BY id LIMIT 1')['id'];

		self::AssertRefusal(self::Send('POST', "/api/labels/jobs/$jobId/authorize-attempt", ['attempt_id' => self::$attemptId], self::$adminKey),
			422, 'attempt_id', 'not_current');
	}

	// ============================================================ previews

	// RenderUpTo() drains the queue ahead of the preview it is waiting for, which empties it
	// of the production renders the print tests left pending. Every test that needs one of
	// those - the claim, and the two that report on a claimed request - runs first, so the
	// whole preview chain is declared on the last of them.
	#[Depends('testSavingAndPublishingADraftProducesADefaultVersion')]
	#[Depends('testAdminCreatesAPrinter')]
	#[Depends('testTheRendererClaimsRendersAndAttachesAnArtifactToTheWaitingJob')]
	#[Depends('testTheRendererReportsInfrastructureFailureAndTheRequestGoesBackToPending')]
	public function testASamplePreviewInventsItsValuesAndCannotBePromoted(): void
	{
		$templateId = self::$templates['location'];
		$response = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'sample', 'printer_id' => self::$printerId], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertTrue($response['json']['is_sample']);
		self::assertSame('preview_sample', $response['json']['purpose']);

		$artifact = self::RenderUpTo((int)$response['json']['id']);

		$promote = self::Send('POST', '/api/labels/artifacts/' . (int)$artifact['artifact_id'] . '/promote',
			['printer_id' => self::$printerId], self::$operatorKey);
		self::AssertRefusal($promote, 422, 'artifact_id', 'not_promotable');
	}

	#[Depends('testASamplePreviewInventsItsValuesAndCannotBePromoted')]
	public function testADraftPreviewPinsTheDocumentItWasAskedAbout(): void
	{
		$templateId = self::$templates['location'];
		$response = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'draft', 'printer_id' => self::$printerId], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('preview_draft', $response['json']['purpose']);
		self::assertNotNull($response['json']['draft_document'], 'the draft is snapshotted so a later edit cannot change what is being looked at');

		$artifact = self::RenderUpTo((int)$response['json']['id']);

		$promote = self::Send('POST', '/api/labels/artifacts/' . (int)$artifact['artifact_id'] . '/promote',
			['printer_id' => self::$printerId], self::$operatorKey);
		self::AssertRefusal($promote, 422, 'artifact_id', 'not_promotable');
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	#[Depends('testADraftPreviewPinsTheDocumentItWasAskedAbout')]
	public function testALivePreviewOfALabelledTargetCanBePromotedToAPrint(): void
	{
		$templateId = self::$templates['location'];
		$response = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'live', 'printer_id' => self::$printerId, 'target_id' => self::$targets['location']], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('preview_live', $response['json']['purpose']);
		self::assertArrayNotHasKey('is_sample', $response['json']);

		$artifact = self::RenderUpTo((int)$response['json']['id']);
		$before = self::Counts();

		$promote = self::Send('POST', '/api/labels/artifacts/' . (int)$artifact['artifact_id'] . '/promote',
			['printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(202, $promote['status'], $promote['body']);
		self::assertSame('promote_preview', $promote['json']['operation']);
		self::assertSame(self::$locationUid, $promote['json']['label_uid']);
		self::assertSame('queued', $promote['json']['state'], 'a promotion carries bytes already, so it is queued rather than awaiting one');
		self::assertSame($before['print_jobs'] + 1, self::Counts()['print_jobs']);
		self::assertSame($before['print_requests'] + 1, self::Counts()['print_requests']);
	}

	#[Depends('testALivePreviewOfALabelledTargetCanBePromotedToAPrint')]
	public function testALivePreviewOfAnUnlabelledTargetFallsBackToASample(): void
	{
		$templateId = self::$templates['location'];
		$response = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'live', 'printer_id' => self::$printerId, 'target_id' => self::$targets['other_location']], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertTrue($response['json']['is_sample'], 'a target with no label gets a sample, and the response says so');
		self::assertSame('preview_sample', $response['json']['purpose']);
	}

	#[Depends('testASamplePreviewInventsItsValuesAndCannotBePromoted')]
	public function testAPreviewNeedsAResolvablePrinter(): void
	{
		$templateId = self::$templates['location'];

		self::AssertRefusal(self::Send('POST', "/api/labels/templates/$templateId/preview", ['kind' => 'sample'], self::$adminKey),
			422, 'printer_id', 'inactive_printer');
		self::assertSame(403, self::Send('POST', "/api/labels/templates/$templateId/preview", ['kind' => 'sample', 'printer_id' => self::$printerId], self::$operatorKey)['status']);
	}

	#[Depends('testALivePreviewOfALabelledTargetCanBePromotedToAPrint')]
	public function testPromotionRefusesACallerWithoutMasterDataEdit(): void
	{
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);
		$before = self::Counts();

		$response = self::Send('POST', '/api/labels/artifacts/' . self::$locationArtifactId . '/promote',
			['printer_id' => self::$printerId], self::$operatorKey);

		self::assertSame(403, $response['status'], $response['body']);
		self::assertSame($before['print_jobs'], self::Counts()['print_jobs']);
	}

	// ============================================================ request bodies

	/**
	 * The wire contract is JSON. A body that parses into something other than an object is
	 * refused with a code naming the body, on every controller that takes one.
	 */
	#[Depends('testAdminCreatesAPrinter')]
	#[Depends('testTemplatesExistForEveryKindThePrintRoutesAccept')]
	#[Depends('testTheRendererCredentialIsItsOwnTypeAndIsRefusedOnWorkerRoutes')]
	public function testABodyThatIsNotAJsonObjectIsRefusedByEveryLabelController(): void
	{
		$before = self::Counts();
		$xml = '<?xml version="1.0"?><print><printer_id>1</printer_id></print>';

		$requests = [
			['POST', '/api/labels/printers', self::$adminKey],
			['POST', '/api/labels/templates', self::$adminKey],
			['POST', '/api/labels/render/1/failed', self::$rendererKey],
			['POST', '/api/labels/location/' . self::$targets['location'] . '/print', self::$operatorKey],
		];

		foreach ($requests as [$method, $path, $key])
		{
			$response = self::SendRaw($method, $path, $xml, 'application/xml', $key);
			self::AssertRefusal($response, 422, 'body', 'invalid_body');
		}

		$after = self::Counts();
		self::assertSame($before['printers'], $after['printers']);
		self::assertSame($before['templates'], $after['templates']);
		self::assertSame($before['labels'], $after['labels']);
		self::assertSame($before['print_requests'], $after['print_requests']);
	}

	// ============================================================ text templates

	/**
	 * A template that prints a field's value, end to end.
	 *
	 * Text is the case the QR-only templates above do not reach: it pins a font asset by
	 * name, it names a catalogue field, and the preview path has to work out which fields a
	 * capture must read from the document rather than from the request.
	 */
	#[Depends('testALivePreviewOfALabelledTargetCanBePromotedToAPrint')]
	public function testATemplateThatPrintsAFieldPinsItsFontAndCapturesThatField(): void
	{
		$font = SfntFixture::Shipped('Labelapi Text Sans', 'Book');
		$stored = self::Send('POST', '/api/labels/assets', [
			'name' => 'Labelapi text font',
			'asset_kind' => 'font',
			'mime_type' => 'font/ttf',
			'licence' => 'OFL-1.1',
			'content_base64' => base64_encode($font),
		], self::$adminKey);

		self::assertSame(200, $stored['status'], $stored['body']);
		self::assertSame('Labelapi Text Sans', $stored['json']['font_family'], 'a font is pinned by what it calls itself, read from its own name table');
		$assetId = (int)$stored['json']['id'];

		$created = self::Send('POST', '/api/labels/templates', ['name' => 'Labelapi text label', 'entity_kind' => 'location'], self::$adminKey);
		$templateId = (int)$created['json']['id'];
		$token = self::Send('GET', "/api/labels/templates/$templateId/draft", null, self::$adminKey)['json']['revision_token'];

		$document = self::Document('location');
		$document['elements'][] = ['type' => 'text', 'id' => 'name', 'x_mm' => 24.0, 'y_mm' => 4.0,
			'width_mm' => 34.0, 'height_mm' => 8.0, 'field' => 'location.name',
			'font_asset' => 'Labelapi text font', 'size_pt' => 10.0, 'color' => 'black'];

		// A text element that names no font is refused: there is no default and no substitution.
		$noFont = $document;
		unset($noFont['elements'][1]['font_asset']);
		self::AssertRefusal(self::Send('PUT', "/api/labels/templates/$templateId/draft", ['document' => $noFont, 'revision_token' => $token], self::$adminKey),
			422, 'elements.name.font_asset', 'invalid_value');

		// ... and one naming a field the catalogue does not carry for this kind is refused too.
		$wrongField = $document;
		$wrongField['elements'][1]['field'] = 'recipe.name';
		self::AssertRefusal(self::Send('PUT', "/api/labels/templates/$templateId/draft", ['document' => $wrongField, 'revision_token' => $token], self::$adminKey),
			422, 'elements.name.field', 'unknown_field');

		$saved = self::Send('PUT', "/api/labels/templates/$templateId/draft", ['document' => $document, 'revision_token' => $token], self::$adminKey);
		self::assertSame(200, $saved['status'], $saved['body']);

		$published = self::Send('POST', "/api/labels/templates/$templateId/publish", [], self::$adminKey);
		self::assertSame(200, $published['status'], $published['body']);
		self::assertSame(['Labelapi text font' => $assetId], json_decode($published['json']['asset_ids'], true),
			'publishing resolves the font the document names to the bytes that are stored');

		$preview = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'sample', 'printer_id' => self::$printerId], self::$adminKey);
		self::assertSame(200, $preview['status'], $preview['body']);

		$capture = self::Row('SELECT captured_fields, is_sample FROM label_captures WHERE id = ?', [(int)$preview['json']['capture_id']]);
		self::assertSame(1, (int)$capture['is_sample'], 'a sample preview invents its values and says so');
		self::assertArrayHasKey('location.name', json_decode($capture['captured_fields'], true),
			'the field the text element names is what the capture reads');

		$artifact = self::RenderUpTo((int)$preview['json']['id']);
		$manifest = json_decode(self::Row('SELECT manifest FROM label_artifacts WHERE id = ?', [(int)$artifact['artifact_id']])['manifest'], true);
		self::assertSame([$assetId], array_column($manifest['assets'], 'id'), 'the artifact records the font it was produced with');
	}

	/**
	 * A capability document holding a number the digest rule cannot encode is refused with a
	 * code naming the document, rather than becoming a 500 at the moment somebody previews.
	 *
	 * RFC 8785 refuses an integer by exact representability rather than by range: 2^53 is
	 * representable and 2^53+1 is not. The driver registry checks that `printable_width_um`
	 * is a positive integer and nothing more, so such a value registers and is only refused
	 * when a media profile is derived from it - which is what this pins.
	 */
	#[Depends('testWorkerRegistersItsDriverVersions')]
	#[Depends('testSavingAndPublishingADraftProducesADefaultVersion')]
	public function testACapabilityDocumentThatHasNoCanonicalFormIsRefusedWhenAProfileIsDerived(): void
	{
		$driver = self::Driver();
		$driver['driver_id'] = 'labelapi.unencodable';
		$driver['capability_document']['combinations'][0]['printable_width_um'] = 9007199254740993;

		// A registration declares the whole set a worker advertises, so the versions the rest
		// of this suite prints on are sent again alongside the new one.
		$second = self::Driver();
		$second['schema_version'] = '2.0';
		$registered = self::Send('POST', '/api/labels/register', ['drivers' => [self::Driver(), $second, $driver]], self::$workerKey);
		self::assertSame(200, $registered['status'], $registered['body']);
		self::assertSame(3, (int)self::$db->query('SELECT count(*) FROM label_worker_capabilities WHERE worker_id = ' . self::$workerId)->fetchColumn());

		$created = self::Send('POST', '/api/labels/printers',
			self::PrinterBody(self::$workerId, ['name' => 'Labelapi unencodable printer', 'driver_id' => 'labelapi.unencodable']), self::$adminKey);
		self::assertSame(200, $created['status'], $created['body']);
		$printerId = (int)$created['json']['id'];

		$profiles = (int)self::$db->query('SELECT count(*) FROM label_media_profiles')->fetchColumn();
		$templateId = self::$templates['location'];

		$preview = self::Send('POST', "/api/labels/templates/$templateId/preview",
			['kind' => 'sample', 'printer_id' => $printerId], self::$adminKey);
		self::AssertRefusal($preview, 422, 'document', 'not_canonicalizable');

		// The print route reaches the same derivation and refuses too, so the value cannot
		// slip through on the path that would have produced a physical label.
		$print = self::Send('POST', '/api/labels/location/' . self::$targets['location'] . '/print',
			['import_epoch' => 0, 'printer_id' => $printerId], self::$operatorKey);
		self::assertGreaterThanOrEqual(400, $print['status'], $print['body']);

		self::assertSame($profiles, (int)self::$db->query('SELECT count(*) FROM label_media_profiles')->fetchColumn(),
			'a profile that cannot be digested is not stored undigested');

		self::assertSame(200, self::Send('DELETE', "/api/labels/printers/$printerId", null, self::$adminKey)['status']);
	}

	// ============================================================ resolution

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testResolvingALiveLabelNamesItsKindAndTarget(): void
	{
		$response = self::Send('GET', '/api/labels/resolve/' . self::$locationUid, null, self::$operatorKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('resolved', $response['json']['status']);
		self::assertSame('location', $response['json']['kind']);
		self::assertSame(self::$locationUid, $response['json']['uid']);
		self::assertSame(self::$targets['location'], (int)$response['json']['target']['id']);
		self::assertSame('Labelapi shelf', $response['json']['target']['name']);
		self::assertNotSame('Labelapi other shelf', $response['json']['target']['name'], 'the label names its own target');
	}

	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	public function testACanonicalizedVariantResolvesToTheSameLabel(): void
	{
		// Crockford's decode aliases: I and L read as 1, O reads as 0, and case is not
		// meaningful - so a uid read by eye or off a marginal scan resolves to the label it
		// names rather than to nothing.
		$aliased = strtr(self::$locationUid, ['1' => 'I', '0' => 'O']);
		$variants = [strtolower(self::$locationUid), $aliased, strtolower($aliased), 'vctl:' . self::$locationUid, 'VCTL:' . strtolower($aliased)];

		foreach ($variants as $variant)
		{
			$response = self::Send('GET', '/api/labels/resolve/' . rawurlencode($variant), null, self::$operatorKey);
			self::assertSame(200, $response['status'], "$variant: " . $response['body']);
			self::assertSame('resolved', $response['json']['status'], "$variant: " . $response['body']);
			self::assertSame(self::$locationUid, $response['json']['uid'], "$variant canonicalizes to the stored uid");
		}
	}

	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	public function testAMalformedPayloadIsRefusedRatherThanLookedUp(): void
	{
		// 13 characters of the alphabet is the whole format: a wrong length, a letter the
		// alphabet excludes on purpose (U), or a punctuation character is not a uid.
		foreach (['SHORT', str_repeat('A', 14), substr(self::$locationUid, 0, 12) . 'U', 'AAAA-AAAA-AAAA', 'vctl:' . str_repeat('Z', 12)] as $malformed)
		{
			$response = self::Send('GET', '/api/labels/resolve/' . rawurlencode($malformed), null, self::$operatorKey);
			self::assertSame(200, $response['status'], "$malformed: " . $response['body']);
			self::assertSame(['status' => 'unknown'], $response['json'], "$malformed is not a payload this namespace can carry");
		}
	}

	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	public function testAnUnknownUidIsDistinctFromAResolvedOne(): void
	{
		$response = self::Send('GET', '/api/labels/resolve/0000000000000', null, self::$operatorKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(['status' => 'unknown'], $response['json']);
	}

	// The battery fixture does not survive this test, so the two tests that print one are
	// declared here rather than left to the order of the file.
	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	#[Depends('testAnIdempotencyKeyReplaysTheFirstJobAndRefusesAChangedRequest')]
	#[Depends('testAMalformedIdempotencyKeyIsRefused')]
	public function testARetiredLabelResolvesToItsSnapshotRatherThanToNothing(): void
	{
		// Retirement is what deleting the target does; a retired label seen in the world is
		// a discrepancy signal, so the scan reports what the label was.
		$uid = (string)self::Row('SELECT uid FROM labels WHERE kind = ? AND target_id = ?', ['battery', self::$targets['battery']])['uid'];
		self::$db->exec('DELETE FROM batteries WHERE id = ' . self::$targets['battery']);

		$response = self::Send('GET', "/api/labels/resolve/$uid", null, self::$operatorKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('retired', $response['json']['status'], 'retired is its own outcome, not an error and not unknown');
		self::assertSame('battery', $response['json']['kind']);
		self::assertNotNull($response['json']['retired_at']);
		self::assertSame(self::$targets['battery'], (int)$response['json']['snapshot']['id'], 'the snapshot names the row that went away');
		self::assertArrayNotHasKey('target', $response['json'], 'a retired label has no live target to name');
	}

	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	public function testACallerWhoMayNotReadThatKindIsNotToldTheLabelExists(): void
	{
		$recipeUid = (string)self::Row('SELECT uid FROM labels WHERE kind = ? AND target_id = ?', ['recipe', self::$targets['recipe']])['uid'];

		// A scanner holding STOCK_VIEW but not RECIPES_VIEW may resolve a product label and
		// not a recipe one, and the refusal reads as "unknown" rather than as "you may not
		// see this" - which is what keeps the route from being a uid oracle.
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);

		$refused = self::Send('GET', "/api/labels/resolve/$recipeUid", null, self::$operatorKey);
		self::assertSame(200, $refused['status'], $refused['body']);
		self::assertSame(['status' => 'unknown'], $refused['json']);

		// The same request from a caller holding RECIPES_VIEW resolves, so the answer above
		// is about the grant rather than about the label.
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_RECIPES_VIEW]);
		$allowed = self::Send('GET', "/api/labels/resolve/$recipeUid", null, self::$operatorKey);
		self::assertSame('resolved', $allowed['json']['status'], $allowed['body']);
		self::assertSame('recipe', $allowed['json']['kind']);

		// A caller holding none of the six domain grants does no lookup at all.
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_SHOPPINGLIST_VIEW]);
		$denied = self::Send('GET', "/api/labels/resolve/$recipeUid", null, self::$operatorKey);
		self::assertSame(['status' => 'unknown'], $denied['json'], $denied['body']);
	}

	// ============================================================ grocycode

	#[Depends('testResolvingALiveLabelNamesItsKindAndTarget')]
	public function testTheLabelNamespaceNeitherParsesNorEmitsAGrocycode(): void
	{
		// ADR-0011: grcy is an input symbology for the paths that already accepted it, and
		// the label namespace is not one of them.
		foreach (['grcy:p:' . self::$targets['product'], 'grcy:l:1', 'grcy:c:1'] as $code)
		{
			$response = self::Send('GET', '/api/labels/resolve/' . rawurlencode($code), null, self::$operatorKey);
			self::assertSame(['status' => 'unknown'], $response['json'], "$code: " . $response['body']);
		}
	}

	#[Depends('testPrintingEachKindCreatesTheLabelAndTheOutboxEventTogether')]
	public function testNoLabelResponseEverCarriesAGrocycodePayload(): void
	{
		$responses = [
			self::Send('GET', '/api/labels/resolve/' . self::$locationUid, null, self::$operatorKey),
			self::Send('GET', '/api/labels/locations/' . self::$targets['location'] . '/context', null, self::$operatorKey),
			self::Send('GET', '/api/labels/product/' . self::$targets['product'] . '/context', null, self::$operatorKey),
			self::Send('GET', '/api/labels/jobs', null, self::$adminKey),
			self::Send('GET', '/api/labels/templates', null, self::$adminKey),
			self::Send('POST', '/api/labels/stock_entry/' . self::$targets['stock_entry'] . '/print',
				['import_epoch' => 0, 'printer_id' => self::$printerId], self::$operatorKey),
		];

		foreach ($responses as $index => $response)
		{
			self::assertStringNotContainsStringIgnoringCase('grcy:', $response['body'], "response $index emitted a grocycode");
		}

		// Every job payload the outbox carries names a vctl uid and no grocycode either. The
		// print above put one there, so an empty list is the control failing rather than the
		// subsystem passing.
		$payloads = self::$db->query("SELECT payload FROM outbox WHERE event_type = 'label.print_requested'")->fetchAll(PDO::FETCH_COLUMN);
		self::assertNotEmpty($payloads, 'the outbox holds a print_requested payload, or the loop below asserts nothing');

		foreach ($payloads as $payload)
		{
			self::assertStringNotContainsStringIgnoringCase('grcy:', $payload);
			self::assertMatchesRegularExpression('/"label_uid":"[0-9A-HJKMNP-TV-Z]{13}"/', $payload);
		}
	}

	public function testAGrocycodeIsStillAcceptedWhereItIsDocumentedToBe(): void
	{
		// `grcy:*` is parsed indefinitely: the stock barcode routes that accepted one before
		// ADR-0011 still do, which is the half of the decision the label routes above are the
		// other half of.
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);

		$response = self::Send('GET', '/api/stock/products/by-barcode/' . rawurlencode('grcy:p:' . self::$targets['product']), null, self::$operatorKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::$targets['product'], (int)$response['json']['product']['id'], 'the grocycode still names the product it encodes');
	}

	// ============================================================ context

	public function testTheLocationContextRouteCarriesTheEpochAPrintHasToQuote(): void
	{
		$response = self::Send('GET', '/api/labels/locations/' . self::$targets['location'] . '/context', null, self::$operatorKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(self::$targets['location'], (int)$response['json']['id']);
		self::assertSame('Labelapi shelf', $response['json']['name']);
		self::assertSame(0, (int)$response['json']['import_epoch']);
	}

	public function testTheContextRouteAnswersForEveryKindItAccepts(): void
	{
		$expected = [
			'product' => 'Labelapi beans',
			'stock_entry' => 'Labelapi beans',
			'recipe' => 'Labelapi chili',
			'chore' => 'Labelapi watering',
		];

		foreach ($expected as $kind => $name)
		{
			$response = self::Send('GET', "/api/labels/$kind/" . self::$targets[$kind] . '/context', null, self::$operatorKey);

			self::assertSame(200, $response['status'], "$kind: " . $response['body']);
			self::assertSame(self::$targets[$kind], (int)$response['json']['id']);
			self::assertSame($name, $response['json']['name'], "$kind reads a name a person can recognise");
			self::assertSame(0, (int)$response['json']['import_epoch']);
		}
	}

	public function testAContextReadOfAMissingRowIsNotFound(): void
	{
		$location = self::Send('GET', '/api/labels/locations/999999/context', null, self::$operatorKey);
		self::assertSame(404, $location['status'], $location['body']);
		self::assertSame('Location not found', $location['json']['error_message']);

		$stock = self::Send('GET', '/api/labels/stock_entry/999999/context', null, self::$operatorKey);
		self::assertSame(404, $stock['status'], $stock['body']);
		self::assertSame('Stock entry not found', $stock['json']['error_message'], 'the refusal names the kind that was asked for');
	}

	public function testAContextReadTakesTheDomainGrantOfTheKindItNames(): void
	{
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);

		self::assertSame(200, self::Send('GET', '/api/labels/product/' . self::$targets['product'] . '/context', null, self::$operatorKey)['status']);
		self::assertSame(403, self::Send('GET', '/api/labels/recipe/' . self::$targets['recipe'] . '/context', null, self::$operatorKey)['status']);
		self::assertSame(403, self::Send('GET', '/api/labels/chore/' . self::$targets['chore'] . '/context', null, self::$operatorKey)['status']);

		self::Grant(self::OPERATOR_USER, [User::PERMISSION_CHORES_VIEW]);
		self::assertSame(403, self::Send('GET', '/api/labels/locations/' . self::$targets['location'] . '/context', null, self::$operatorKey)['status']);
		self::assertSame(200, self::Send('GET', '/api/labels/chore/' . self::$targets['chore'] . '/context', null, self::$operatorKey)['status']);
	}

	// ============================================================ teardown paths

	#[Depends('testTheJobMonitorReportsEveryJobWithItsPrinterAndState')]
	public function testDeletingAPrinterRetiresItsQueuedWorkVisibly(): void
	{
		$created = self::Send('POST', '/api/labels/printers',
			self::PrinterBody(self::$workerId, ['name' => 'Labelapi second printer']), self::$adminKey);
		self::assertSame(200, $created['status'], $created['body']);
		$printerId = (int)$created['json']['id'];

		$job = self::Send('POST', '/api/labels/recipe/' . self::$targets['recipe'] . '/print',
			['import_epoch' => 0, 'printer_id' => $printerId], self::$operatorKey);
		self::assertSame(202, $job['status'], $job['body']);
		$jobId = (int)$job['json']['job_id'];

		$deleted = self::Send('DELETE', "/api/labels/printers/$printerId", null, self::$adminKey);
		self::assertSame(200, $deleted['status'], $deleted['body']);
		self::assertTrue($deleted['json']['deleted']);

		self::assertNull(self::Row('SELECT id FROM label_printers WHERE id = ?', [$printerId]));

		$retired = self::Row('SELECT outcome, outbox_id FROM print_jobs WHERE id = ?', [$jobId]);
		self::assertSame('dead_lettered', $retired['outcome'], 'history retains the job rather than the deletion erasing it');
		self::assertNotNull(self::Row('SELECT dead_lettered_at FROM outbox WHERE id = ?', [(int)$retired['outbox_id']])['dead_lettered_at']);

		$again = self::Send('DELETE', "/api/labels/printers/$printerId", null, self::$adminKey);
		self::assertFalse($again['json']['deleted'], 'deleting a printer that is gone reports that nothing was deleted');
	}

	/**
	 * Revocation takes every credential the worker holds - the worker's and the renderer's
	 * alike - and nothing reissues one into `$workerKey`. So this test builds the worker it
	 * revokes: doing it to the shared one ends the narrative for whichever test the run
	 * order happens to put next, and reports the 401 as that test's own failure.
	 */
	#[Depends('testWorkerRegistersItsDriverVersions')]
	public function testRevokingAWorkerCredentialRefusesTheKeyAndKeepsThePrinterAssignment(): void
	{
		$worker = self::Send('POST', '/api/labels/workers',
			['name' => 'Labelapi revoked worker', 'configuration_mode' => 'declared'], self::$adminKey);
		self::assertSame(200, $worker['status'], $worker['body']);
		$workerId = (int)$worker['json']['id'];

		$issued = self::Send('POST', "/api/labels/workers/$workerId/credentials", [], self::$adminKey);
		self::assertSame(200, $issued['status'], $issued['body']);
		$credential = $issued['json']['credential'];

		// Registering is what the credential is for, and it is done here so that the 401
		// below is a credential that was working being refused rather than one that never did.
		$registered = self::Send('POST', '/api/labels/register', ['drivers' => [self::Driver()]], $credential);
		self::assertSame(200, $registered['status'], $registered['body']);

		$printer = self::Send('POST', '/api/labels/printers',
			self::PrinterBody($workerId, ['name' => 'Labelapi revoked worker printer']), self::$adminKey);
		self::assertSame(200, $printer['status'], $printer['body']);
		$printerId = (int)$printer['json']['id'];

		$response = self::Send('DELETE', "/api/labels/workers/$workerId/credentials", null, self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertTrue($response['json']['revoked']);

		self::assertSame(401, self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1], $credential)['status'],
			'a revoked credential is refused at the door');
		self::assertSame($workerId, (int)self::Row('SELECT worker_id FROM label_printers WHERE id = ?', [$printerId])['worker_id'],
			'revocation is about the credential, not about the printer it was assigned');
	}

	// The same for the paired worker: deactivating it revokes `$pairedKey`, so the three
	// tests that authenticate with it are declared rather than merely written above.
	#[Depends('testRevokingAWorkerCredentialRefusesTheKeyAndKeepsThePrinterAssignment')]
	#[Depends('testRotationRefusesAMalformedRequestIdentifier')]
	#[Depends('testAnotherWorkersAttemptIsForbiddenOnEveryAttemptRoute')]
	#[Depends('testThePrinterStatusRouteRefusesAPrinterThisWorkerDoesNotHold')]
	public function testDeactivatingAWorkerRevokesItsSessionsToo(): void
	{
		$response = self::Send('PUT', '/api/labels/workers/' . self::$pairedWorkerId,
			['name' => 'Labelapi paired worker', 'configuration_mode' => 'paired', 'active' => 0], self::$adminKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(0, (int)self::Row('SELECT active FROM label_workers WHERE id = ?', [self::$pairedWorkerId])['active']);

		self::assertSame(401, self::Send('POST', '/api/labels/printers/' . self::$printerId . '/status', ['device_state' => 'idle'], self::$pairedKey)['status']);
	}

	#[Depends('testDeactivatingAWorkerRevokesItsSessionsToo')]
	public function testAnInactiveWorkerCannotBeIssuedAFreshCredential(): void
	{
		self::AssertRefusal(
			self::Send('POST', '/api/labels/workers/' . self::$pairedWorkerId . '/credentials', [], self::$adminKey),
			422, 'worker_id', 'inactive_worker'
		);
	}
}
