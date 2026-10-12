<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Storage\FileSizeLimit;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 802: a declared 4xx/5xx response says what its body is.
 *
 * victual.openapi.json declared 93 error responses with a description and nothing else, so a
 * generated client could not type the body those routes answer with. This class holds two
 * things in place.
 *
 * The document check walks every 4xx and 5xx response in the document, follows a `$ref` to
 * components/responses, and requires a content entry with a schema. The one exception is
 * listed in ALLOWED_DESCRIPTION_ONLY, with the reason, and the class fails if that entry
 * stops being description-only, so the list cannot go stale unnoticed.
 *
 * The runtime checks send a request that provokes each distinct error body and hold the
 * answer to the schema the document gives for that operation and status, with Opis, and to
 * the exact key set and values. Opis alone is not enough: no error schema forbids extra
 * properties, so a body with an undocumented key would pass it.
 *
 * Issue 801 extends the same class from "what is the body" to "which statuses are declared": the
 * last section checks that every operation behind authentication declares 401, every operation
 * with an integer path parameter declares 400, PUT /files declares 413, the label conflicts and the
 * renderer routes' 404/422 are declared, and the consumption gates carry the bodies they answer.
 * Each has a runtime case that holds the real answer to the schema the document declares.
 *
 * Every request goes through labelapi-subprocess-helper.php for the reason LabelApiTest gives:
 * the worker routes are authenticated by a typed key BaseAuthMiddleware resolves, which a
 * direct controller call skips. The transport is copied rather than shared because
 * LabelApiTest keeps it private.
 */
class OpenApiErrorBodiesTest extends PgsqlSchemaTestCase
{
	/**
	 * Declared error responses allowed to stay description-only, by "METHOD /path STATUS".
	 * Each is unreachable, so there is no body to describe; they are kept in the document
	 * (deleting a declared status is a maintainer decision) with a description that says so.
	 *
	 * LabelWorkerApiController maps a `forbidden` refusal to 403, but `forbidden` is raised only
	 * for attempt, printer and artifact-bytes ownership (LabelWorkerAuthorization::Authorize(),
	 * lines 33-54), plus LabelCaptureService and PrintAttemptService. Register, Claim and Rotate
	 * raise none of them. POST /labels/pair is worse: BaseAuthMiddleware returns before any
	 * authorization for labels-pair (lines 100-106) and Pair() raises only 401 and 422.
	 */
	private const ALLOWED_DESCRIPTION_ONLY = [
		'POST /labels/pair 403',
		'POST /labels/register 403',
		'POST /labels/jobs/claim 403',
		'POST /labels/credentials/rotate 403',
	];

	private const ADMIN_USER = 9700;
	private const OPERATOR_USER = 9701;
	private const NOBODY_USER = 9702;

	private static PDO $db;
	private static string $adminKey;
	private static string $operatorKey;
	private static string $nobodyKey;

	/** A worker that owns a printer, and a second worker that does not. */
	private static int $ownerWorkerId;
	private static string $ownerKey;
	private static int $printerId;
	private static string $strangerKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		foreach ([self::ADMIN_USER => 'admin', self::OPERATOR_USER => 'operator', self::NOBODY_USER => 'nobody'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users(id, username, password) VALUES ($id, 'errbodies-$name', 'fixture')");
		}

		self::Grant(self::ADMIN_USER, [User::PERMISSION_ADMIN]);
		self::Grant(self::OPERATOR_USER, [User::PERMISSION_STOCK_VIEW]);
		self::Grant(self::NOBODY_USER, []);

		self::$adminKey = self::IssueUserKey(self::ADMIN_USER);
		self::$operatorKey = self::IssueUserKey(self::OPERATOR_USER);
		self::$nobodyKey = self::IssueUserKey(self::NOBODY_USER);

		// Two declared workers; only the first holds a printer.
		self::$ownerWorkerId = self::CreateWorker('Errbodies owner');
		self::$ownerKey = self::IssueWorkerCredential(self::$ownerWorkerId);
		self::$strangerKey = self::IssueWorkerCredential(self::CreateWorker('Errbodies stranger'));

		$registered = self::Send('POST', '/api/labels/register', ['drivers' => [self::Driver()]], self::$ownerKey);
		self::assertSame(200, $registered['status'], $registered['body']);

		$printer = self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$ownerWorkerId), self::$adminKey);
		self::assertSame(200, $printer['status'], $printer['body']);
		self::$printerId = (int)$printer['json']['id'];
	}

	// ---------------------------------------------------------------- the document

	public function testEveryDeclaredErrorResponseDescribesItsBody(): void
	{
		$missing = [];

		foreach (self::Operations() as [$method, $path, $operation])
		{
			foreach ($operation['responses'] as $status => $response)
			{
				if (!in_array(substr((string)$status, 0, 1), ['4', '5'], true))
				{
					continue;
				}

				$key = "$method $path $status";

				if (!self::HasBodySchema(self::ResolveResponse($response)) && !in_array($key, self::ALLOWED_DESCRIPTION_ONLY, true))
				{
					$missing[] = $key;
				}
			}
		}

		self::assertSame([], $missing, count($missing) . " error responses are description-only:\n" . implode("\n", $missing));
	}

	public function testTheAllowlistNamesOnlyEntriesThatAreStillDescriptionOnly(): void
	{
		foreach (self::ALLOWED_DESCRIPTION_ONLY as $key)
		{
			[$method, $path, $status] = explode(' ', $key);
			$response = self::Spec()['paths'][$path][strtolower($method)]['responses'][$status] ?? null;

			self::assertIsArray($response, "$key is allowlisted but the document no longer declares it");
			self::assertFalse(self::HasBodySchema(self::ResolveResponse($response)), "$key now describes its body: remove it from the allowlist");
		}
	}

	public function testEveryComponentResponseIsUsed(): void
	{
		$document = file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json');
		$declared = array_keys(self::Spec()['components']['responses'] ?? []);

		self::assertNotSame([], $declared, 'components/responses is empty');

		foreach ($declared as $name)
		{
			self::assertStringContainsString('"#/components/responses/' . $name . '"', $document, "components/responses/$name is declared and referenced by nothing");
		}
	}

	public function testTheErrorSchemasRequireWhatTheServerAlwaysSends(): void
	{
		$schemas = self::Spec()['components']['schemas'];

		self::assertSame(['error_message'], $schemas['ApiError']['required'] ?? null);
		self::assertSame(['error_message'], $schemas['ConsumptionError']['required'] ?? null);
		self::assertSame(['field', 'code', 'error_message'], $schemas['LabelRefusal']['required'] ?? null);
		self::assertSame(['revoked', 'code'], $schemas['CredentialRevoked']['required'] ?? null);
		self::assertSame('boolean', $schemas['CredentialRevoked']['properties']['revoked']['type'] ?? null);
		self::assertTrue($schemas['CredentialRevoked']['properties']['revoked']['const'] ?? null);
		self::assertSame('credential_reuse', $schemas['CredentialRevoked']['properties']['code']['const'] ?? null);
	}

	// ---------------------------------------------------------------- the wire: ApiError

	public function testAnAnonymousConsumptionRequestIsAnApiError(): void
	{
		self::AssertBody(self::Send('GET', '/api/consumption/capabilities'), 'GET', '/consumption/capabilities', 401, ['error_message' => 'Unauthorized']);
	}

	public function testAnAnonymousAdminLabelRequestIsAnApiError(): void
	{
		self::AssertBody(self::Send('GET', '/api/labels/jobs'), 'GET', '/labels/jobs', 401, ['error_message' => 'Unauthorized']);
	}

	public function testAnAnonymousWorkerRequestIsAnApiError(): void
	{
		self::AssertBody(self::Send('POST', '/api/labels/jobs/claim', ['limit' => 1]), 'POST', '/labels/jobs/claim', 401, ['error_message' => 'Unauthorized']);
	}

	public function testACallerWithoutAdminOnAnAdminLabelRouteIsAnApiError(): void
	{
		$response = self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$ownerWorkerId), self::$operatorKey);

		self::AssertBody($response, 'POST', '/labels/printers', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_ADMIN]);
	}

	public function testACallerWithoutStockViewOnAConsumptionRouteIsAnApiError(): void
	{
		// The service layer checks the same permission for the same user, but the controller
		// checks first and outside the ConsumptionException catch, so the body has no `error`.
		$response = self::Send('GET', '/api/consumption/capabilities', null, self::$nobodyKey);

		self::AssertBody($response, 'GET', '/consumption/capabilities', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_STOCK_VIEW]);
	}

	public function testACallerWithoutStockConsumeOnAConsumptionWriteIsAnApiError(): void
	{
		$response = self::Send('DELETE', '/api/consumption/events/errbodies/evt-1', null, self::$operatorKey);

		self::AssertBody($response, 'DELETE', '/consumption/events/{source_system}/{source_event_id}', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_STOCK_CONSUME]);
	}

	public function testALocationContextWithoutStockViewIsAnApiError(): void
	{
		$response = self::Send('GET', '/api/labels/locations/1/context', null, self::$nobodyKey);

		self::AssertBody($response, 'GET', '/labels/locations/{locationId}/context', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_STOCK_VIEW]);
	}

	public function testAnUnknownLocationContextIsAnApiError(): void
	{
		$response = self::Send('GET', '/api/labels/locations/999999/context', null, self::$operatorKey);

		self::AssertBody($response, 'GET', '/labels/locations/{locationId}/context', 404, ['error_message' => 'Location not found']);
	}

	public function testAnUnknownTargetContextIsAnApiError(): void
	{
		$response = self::Send('GET', '/api/labels/stock_entry/999999/context', null, self::$operatorKey);

		self::AssertBody($response, 'GET', '/labels/{kind}/{id}/context', 404, ['error_message' => 'Stock entry not found']);
	}

	// ---------------------------------------------------------------- the wire: LabelRefusal

	public function testUnknownPairingMaterialIsALabelRefusal(): void
	{
		$response = self::Send('POST', '/api/labels/pair', ['material' => bin2hex(random_bytes(32))]);

		self::AssertBody($response, 'POST', '/labels/pair', 401, [
			'field' => 'material', 'code' => 'unauthorized', 'error_message' => 'Invalid or expired pairing material',
		]);
	}

	public function testMalformedPairingMaterialIsALabelRefusal(): void
	{
		$response = self::Send('POST', '/api/labels/pair', ['material' => 'not-hex']);

		self::AssertBody($response, 'POST', '/labels/pair', 422, [
			'field' => 'material', 'code' => 'value_out_of_range', 'error_message' => 'Expected 32 bytes as lowercase hexadecimal',
		]);
	}

	public function testAWorkerThatDoesNotOwnTheAttemptIsAForbiddenLabelRefusal(): void
	{
		// No attempt with this id exists, which the authorization cannot tell apart from one
		// another worker holds: both answer `forbidden`, so a worker learns nothing about
		// attempts that are not its own.
		foreach (['heartbeat', 'sent', 'evidence'] as $step)
		{
			$response = self::Send('POST', "/api/labels/attempts/999999/$step", [], self::$ownerKey);

			self::AssertBody($response, 'POST', '/labels/attempts/{attemptId}/' . $step, 403, [
				'field' => 'attempt_id', 'code' => 'forbidden', 'error_message' => 'Attempt is not owned by this worker',
			]);
		}

		$result = self::Send('POST', '/api/labels/attempts/999999/result', ['outcome' => 'printed', 'detail' => []], self::$ownerKey);

		self::AssertBody($result, 'POST', '/labels/attempts/{attemptId}/result', 403, [
			'field' => 'attempt_id', 'code' => 'forbidden', 'error_message' => 'Attempt is not owned by this worker',
		]);
	}

	public function testAWorkerThatDoesNotHoldThePrinterIsAForbiddenLabelRefusal(): void
	{
		$response = self::Send('POST', '/api/labels/printers/' . self::$printerId . '/status', ['device_state' => 'idle'], self::$strangerKey);

		self::AssertBody($response, 'POST', '/labels/printers/{printerId}/status', 403, [
			'field' => 'printer_id', 'code' => 'forbidden', 'error_message' => 'Printer is not assigned to this worker',
		]);
	}

	public function testAnInvalidPrinterBodyIsALabelRefusal(): void
	{
		$response = self::Send('POST', '/api/labels/printers', self::PrinterBody(self::$ownerWorkerId, ['driver_schema_version' => '9.9']), self::$adminKey);

		self::assertSame(422, $response['status'], $response['body']);
		self::AssertBody($response, 'POST', '/labels/printers', 422, [
			'field' => 'driver_schema_version', 'code' => 'unknown_driver_version', 'error_message' => 'Unknown driver version',
		]);
	}

	public function testAnInvalidPrintRequestIsALabelRefusal(): void
	{
		$response = self::Send('POST', '/api/labels/locations/999999/print', ['printer_id' => self::$printerId], self::$adminKey);

		self::AssertBody($response, 'POST', '/labels/locations/{locationId}/print', 422, [
			'field' => 'import_epoch', 'code' => 'value_out_of_range', 'error_message' => 'An integer import_epoch is required',
		]);
	}

	// ---------------------------------------------------------------- the wire: CredentialRevoked

	public function testReusingAConsumedCredentialAnswersCredentialRevoked(): void
	{
		$worker = self::Send('POST', '/api/labels/workers', ['name' => 'Errbodies paired', 'configuration_mode' => 'paired'], self::$adminKey);
		self::assertSame(200, $worker['status'], $worker['body']);

		$material = self::Send('POST', '/api/labels/workers/' . (int)$worker['json']['id'] . '/pairing-material', [], self::$adminKey);
		self::assertSame(200, $material['status'], $material['body']);

		$paired = self::Send('POST', '/api/labels/pair', ['material' => $material['json']['material']]);
		self::assertSame(200, $paired['status'], $paired['body']);
		$original = $paired['json']['credential'];

		// The first rotation consumes the original credential ...
		$first = self::Send('POST', '/api/labels/credentials/rotate',
			['rotation_request_id' => bin2hex(random_bytes(32)), 'material' => bin2hex(random_bytes(32))], $original);
		self::assertSame(200, $first['status'], $first['body']);

		// ... and presenting it again under a different request identifier is reuse, not replay.
		$reused = self::Send('POST', '/api/labels/credentials/rotate',
			['rotation_request_id' => bin2hex(random_bytes(32)), 'material' => bin2hex(random_bytes(32))], $original);

		self::AssertBody($reused, 'POST', '/labels/credentials/rotate', 401, ['revoked' => true, 'code' => 'credential_reuse']);

		// The session is revoked: the successor the first rotation issued no longer works.
		$after = self::Send('POST', '/api/labels/credentials/rotate',
			['rotation_request_id' => bin2hex(random_bytes(32)), 'material' => bin2hex(random_bytes(32))], $first['json']['credential']);
		self::assertSame(401, $after['status'], $after['body']);
	}

	// ---------------------------------------------------------------- assertions

	/**
	 * The answer is a JSON body equal to $expected, key order included, and it validates
	 * against the schema the document declares for this operation and status.
	 */
	private static function AssertBody(array $response, string $method, string $path, int $status, array $expected): void
	{
		self::assertSame($status, $response['status'], "$method $path: " . $response['body']);
		self::assertStringContainsString('application/json', $response['headers']['Content-Type'] ?? '', "$method $path answers JSON");
		self::assertSame($expected, $response['json'], "$method $path: " . $response['body']);
		self::assertSame(array_keys($expected), array_keys($response['json']), "$method $path: the key order of " . $response['body']);

		self::AssertMatchesDocumentedResponse($response['json'], $method, $path, $status);
	}

	private static function AssertMatchesDocumentedResponse(array $body, string $method, string $path, int $status): void
	{
		$declared = self::SpecObject()->paths->$path->{strtolower($method)}->responses->{(string)$status} ?? null;
		self::assertNotNull($declared, "$method $path does not declare a $status response");

		$document = self::SpecObject();

		while (isset($declared->{'$ref'}))
		{
			$declared = $document->components->responses->{substr($declared->{'$ref'}, strlen('#/components/responses/'))};
		}

		$schema = $declared->content->{'application/json'}->schema ?? null;
		self::assertNotNull($schema, "$method $path $status declares no application/json schema");

		// Opis resolves "#/components/..." against the document it is handed, so the
		// components ride along on a root that carries the schema's own keywords.
		$root = (object)['components' => $document->components];
		foreach ($schema as $keyword => $value)
		{
			$root->$keyword = $value;
		}

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);
		$result = $validator->validate(json_decode(json_encode($body), false), $root);

		if ($result->isValid())
		{
			self::assertTrue(true);
			return;
		}

		$error = $result->error();
		while ($error->subErrors())
		{
			$error = $error->subErrors()[0];
		}

		self::fail("$method $path $status does not validate against its documented schema: "
			. (implode('/', $error->data()->fullPath()) ?: '<root>') . ': ' . $error->keyword());
	}

	// ---------------------------------------------------------------- the document, decoded

	private static function Spec(): array
	{
		static $document = null;

		return $document ??= json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
	}

	/** The same document as objects, which is what Opis validates against. */
	private static function SpecObject(): object
	{
		static $document = null;

		return $document ??= json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
	}

	/** @return list<array{0: string, 1: string, 2: array}> method, path, operation */
	private static function Operations(): array
	{
		$operations = [];

		foreach (self::Spec()['paths'] as $path => $item)
		{
			foreach ($item as $method => $operation)
			{
				if (is_array($operation) && isset($operation['responses']))
				{
					$operations[] = [strtoupper($method), $path, $operation];
				}
			}
		}

		return $operations;
	}

	/** Follows a Response Object's $ref into components/responses, as ContractTest::resolveSchema does for schemas. */
	private static function ResolveResponse(array $response): array
	{
		$hops = 0;

		while (isset($response['$ref']))
		{
			self::assertLessThan(16, ++$hops, 'a response $ref chain that does not end');
			self::assertStringStartsWith('#/components/responses/', $response['$ref']);

			$target = self::Spec()['components']['responses'][substr($response['$ref'], strlen('#/components/responses/'))] ?? null;
			self::assertIsArray($target, "{$response['$ref']} does not exist");
			$response = $target;
		}

		return $response;
	}

	private static function HasBodySchema(array $response): bool
	{
		foreach ($response['content'] ?? [] as $media)
		{
			if (isset($media['schema']))
			{
				return true;
			}
		}

		return false;
	}

	// ---------------------------------------------------------------- fixtures and transport

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

	private static function CreateWorker(string $name): int
	{
		$response = self::Send('POST', '/api/labels/workers', ['name' => $name, 'configuration_mode' => 'declared'], self::$adminKey);
		self::assertSame(200, $response['status'], $response['body']);

		return (int)$response['json']['id'];
	}

	private static function IssueWorkerCredential(int $workerId): string
	{
		$response = self::Send('POST', "/api/labels/workers/$workerId/credentials", [], self::$adminKey);
		self::assertSame(200, $response['status'], $response['body']);

		return $response['json']['credential'];
	}

	private static function Driver(): array
	{
		return json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/.devtools/labels/fixtures/brother-ql.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	private static function PrinterBody(int $workerId, array $overrides = []): array
	{
		return $overrides + [
			'name' => 'Errbodies printer',
			'worker_id' => $workerId,
			'driver_id' => 'brother.ql',
			'driver_schema_version' => '1.0',
			'connection' => '127.0.0.1:9100',
			'connection_type' => 'tcp',
			'model' => 'QL-820NWBc',
			'settings' => ['media' => '62red', 'resolution_x' => 300, 'resolution_y' => 300, 'color_mode' => 'black_red'],
		];
	}

	/**
	 * One request through the production middleware stack.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>, json: mixed}
	 */
	private static function Send(string $method, string $path, ?array $body = null, ?string $key = null, ?string $rawBody = null): array
	{
		$spec = ['method' => $method, 'path' => $path];

		if ($rawBody !== null)
		{
			$spec['raw_body'] = $rawBody;
		}

		if ($key !== null)
		{
			$spec['headers'] = ['VICTUAL-API-KEY' => $key];
		}

		if ($body !== null)
		{
			$spec['body'] = $body;
		}

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

		$specFile = tempnam(getenv('VICTUAL_DATAPATH') ?: sys_get_temp_dir(), 'errbodies-request-');
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

	// ================================================================ issue 801: the known statuses
	//
	// #802 gave every declared error response a body. These checks are about which statuses are
	// declared at all: a 401 on every operation behind authentication, a 400 on every operation
	// whose integer path parameter PathParameterMiddleware validates, the 413 of an oversized
	// upload, the 409 of the label routes that map a conflict, and the right body schema on the
	// consumption recipe and refill gates.

	/** Operations whose 409 is a LabelRefusal, by "METHOD /path" and the codes the controller maps to 409. */
	private const LABEL_CONFLICTS = [
		'PUT /labels/templates/{templateId}/draft' => ['stale_revision'],
		'POST /labels/locations/{locationId}/print' => ['idempotency_conflict'],
		'POST /labels/locations/{locationId}/revised-print' => ['idempotency_conflict'],
		'POST /labels/{kind}/{id}/print' => ['idempotency_conflict'],
		'POST /labels/{kind}/{id}/revised-print' => ['idempotency_conflict'],
		'POST /labels/jobs/{jobId}/reprint' => ['idempotency_conflict'],
		'POST /labels/artifacts/{artifactId}/promote' => ['idempotency_conflict'],
		'POST /labels/jobs/{jobId}/cancel' => ['idempotency_conflict', 'already_claimed'],
	];

	public function testEveryOperationBehindAuthenticationDeclaresA401(): void
	{
		$root = self::Spec()['security'] ?? [];
		$missing = [];

		foreach (self::Operations() as [$method, $path, $operation])
		{
			$security = $operation['security'] ?? $root;

			if ($security !== [] && !isset($operation['responses']['401']))
			{
				$missing[] = "$method $path";
			}
		}

		self::assertSame([], $missing, count($missing) . " operations inherit security and declare no 401:\n" . implode("\n", $missing));
	}

	public function testEveryOperationWithAnIntegerPathParameterDeclaresA400(): void
	{
		$missing = [];

		foreach (self::Operations() as [$method, $path, $operation])
		{
			foreach ($operation['parameters'] ?? [] as $parameter)
			{
				if (($parameter['in'] ?? null) === 'path' && ($parameter['schema']['type'] ?? null) === 'integer' && !isset($operation['responses']['400']))
				{
					$missing[] = "$method $path";
					break;
				}
			}
		}

		// The three {id:[0-9]+} routes are included: a non-digit id is a routing 404, but digits
		// beyond PHP's integer range match the route and PathParameterMiddleware refuses them.
		self::assertSame([], $missing, count($missing) . " operations take an integer path parameter and declare no 400:\n" . implode("\n", $missing));
		self::assertSame(['$ref' => '#/components/schemas/Error400'], self::Spec()['components']['responses']['InvalidPathParameter']['content']['application/json']['schema'] ?? null);
	}

	public function testAnUploadDeclaresThe413ItCanAnswer(): void
	{
		$response = self::Spec()['paths']['/files/{group}/{fileName}']['put']['responses']['413'] ?? null;

		self::assertIsArray($response, 'PUT /files/{group}/{fileName} declares no 413');
		self::assertSame(['$ref' => '#/components/schemas/ApiError'], $response['content']['application/json']['schema'] ?? null);
		self::assertStringContainsString('FILE_STORAGE_MAX_SIZE_MB', $response['description']);
	}

	public function testTheConsumptionRecipeAndRefillGatesAreDocumentedWithTheBodiesTheyAnswer(): void
	{
		$gateOnly = [
			'GET /consumption/recipes', 'POST /consumption/recipes', 'GET /consumption/recipes/{recipeId}',
			'GET /consumption/recipes/{recipeId}/events', 'GET /consumption/recipes/{recipeId}/refill',
		];
		$seen = 0;

		foreach (self::Operations() as [$method, $path, $operation])
		{
			if (!str_starts_with($path, '/consumption/recipes'))
			{
				continue;
			}

			$seen++;
			$key = "$method $path";
			$unauthorized = self::ResolveResponse($operation['responses']['401'])['content']['application/json']['schema'] ?? null;
			$forbidden = self::ResolveResponse($operation['responses']['403'])['content']['application/json']['schema'] ?? null;

			// Only the middleware answers 401, and BaseAuthMiddleware sends no `error` member.
			self::assertSame(['$ref' => '#/components/schemas/ApiError'], $unauthorized, "$key 401");

			if (in_array($key, $gateOnly, true))
			{
				// Past the gate nothing on these routes raises a right_missing.
				self::assertSame(['$ref' => '#/components/schemas/ApiError'], $forbidden, "$key 403 is the permission gate only");
			}
			else
			{
				self::assertSame(['anyOf' => [['$ref' => '#/components/schemas/ApiError'], ['$ref' => '#/components/schemas/ConsumptionError']]], $forbidden, "$key 403 is the gate or a missing right");
			}
		}

		self::assertSame(20, $seen, 'the recipe and refill operations');
	}

	public function testTheLabelConflictsAreDocumentedAsLabelRefusals(): void
	{
		$declared = [];

		foreach (self::Operations() as [$method, $path, $operation])
		{
			if (str_starts_with($path, '/labels') && isset($operation['responses']['409']))
			{
				$declared["$method $path"] = $operation['responses']['409'];
			}
		}

		$expected = array_keys(self::LABEL_CONFLICTS);
		$actual = array_keys($declared);
		sort($expected);
		sort($actual);
		self::assertSame($expected, $actual, 'The label operations that declare a 409');

		foreach ($declared as $key => $response)
		{
			self::assertSame(['$ref' => '#/components/schemas/LabelRefusal'], self::ResolveResponse($response)['content']['application/json']['schema'] ?? null, "$key 409");

			foreach (self::LABEL_CONFLICTS[$key] as $code)
			{
				self::assertStringContainsString($code, $response['description'], "$key 409 names $code");
			}
		}
	}

	public function testAnonymousRequestsToTheNewlyDocumentedRoutesAnswerAnApiError(): void
	{
		$cases = [
			['GET', '/api/labels/templates', '/labels/templates'],
			['POST', '/api/labels/templates/1/publish', '/labels/templates/{templateId}/publish'],
			['GET', '/api/labels/assets', '/labels/assets'],
			['GET', '/api/labels/renders/1', '/labels/renders/{requestId}'],
			['GET', '/api/labels/resolve/abc', '/labels/resolve/{code}'],
			['GET', '/api/labels/locations/1/context', '/labels/locations/{locationId}/context'],
			['POST', '/api/labels/locations/1/print', '/labels/locations/{locationId}/print'],
			['POST', '/api/labels/jobs/1/cancel', '/labels/jobs/{jobId}/cancel'],
			['POST', '/api/labels/workers/1/renderer-credentials', '/labels/workers/{workerId}/renderer-credentials'],
			// The renderer's and the worker's own routes: the middleware answers before any handler.
			['POST', '/api/labels/render/claim', '/labels/render/claim'],
			['POST', '/api/labels/render/1/result', '/labels/render/{requestId}/result'],
			['GET', '/api/labels/assets/1/bytes', '/labels/assets/{assetId}/bytes'],
			['GET', '/api/labels/artifacts/1/bytes', '/labels/artifacts/{artifactId}/bytes'],
			['GET', '/api/consumption/recipes', '/consumption/recipes'],
			['GET', '/api/consumption/recipes/1/refill', '/consumption/recipes/{recipeId}/refill'],
		];

		foreach ($cases as [$method, $url, $documented])
		{
			self::AssertBody(self::Send($method, $url, $method === 'POST' ? [] : null), $method, $documented, 401, ['error_message' => 'Unauthorized']);
		}
	}

	public function testARendererKeyThatIsNotValidForTheRouteAnswersAnApiError(): void
	{
		// A worker key on a renderer route is not a key the route accepts: the middleware
		// answers an ApiError, not the LabelRefusal the worker routes use for a deactivated worker.
		self::AssertBody(self::Send('POST', '/api/labels/render/claim', [], self::$ownerKey), 'POST', '/labels/render/claim', 401, ['error_message' => 'Unauthorized']);
	}

	public function testANonIntegerPathParameterIsAnErrorBody(): void
	{
		$renderer = self::Send('POST', '/api/labels/workers/' . self::$ownerWorkerId . '/renderer-credentials', [], self::$adminKey);
		self::assertSame(200, $renderer['status'], $renderer['body']);

		$cases = [
			['GET', '/api/labels/templates/abc/draft', '/labels/templates/{templateId}/draft', 'templateId', self::$adminKey],
			['POST', '/api/labels/jobs/abc/cancel', '/labels/jobs/{jobId}/cancel', 'jobId', self::$adminKey],
			['POST', '/api/labels/locations/abc/print', '/labels/locations/{locationId}/print', 'locationId', self::$adminKey],
			['GET', '/api/labels/locations/abc/context', '/labels/locations/{locationId}/context', 'locationId', self::$adminKey],
			['PUT', '/api/labels/printers/abc', '/labels/printers/{printerId}', 'printerId', self::$adminKey],
			['GET', '/api/consumption/recipes/abc', '/consumption/recipes/{recipeId}', 'recipeId', self::$adminKey],
			['POST', '/api/consumption/recipes/1/events/abc/undo', '/consumption/recipes/{recipeId}/events/{eventId}/undo', 'eventId', self::$adminKey],
			// A worker's and a renderer's routes pass the same middleware after their own authentication.
			['POST', '/api/labels/attempts/abc/heartbeat', '/labels/attempts/{attemptId}/heartbeat', 'attemptId', self::$ownerKey],
			['POST', '/api/labels/render/abc/result', '/labels/render/{requestId}/result', 'requestId', $renderer['json']['credential']],
			['GET', '/api/labels/assets/abc/bytes', '/labels/assets/{assetId}/bytes', 'assetId', $renderer['json']['credential']],
		];

		foreach ($cases as [$method, $url, $documented, $name, $key])
		{
			self::AssertBody(
				self::Send($method, $url, $method === 'GET' ? null : [], $key),
				$method, $documented, 400, ['error_message' => 'Invalid path parameter: {' . $name . '} has to be an integer']
			);
		}
	}

	public function testTheRegexConstrainedLabelRoutesAnswer404ForANonDigitIdAnd400ForOneBeyondTheIntegerRange(): void
	{
		$routes = [
			['GET', '/labels/{kind}/{id}/context', 'product', '/context'],
			['POST', '/labels/{kind}/{id}/print', 'product', '/print'],
			['POST', '/labels/{kind}/{id}/revised-print', 'product', '/revised-print'],
		];

		foreach ($routes as [$method, $documented, $kind, $suffix])
		{
			$body = $method === 'GET' ? null : [];

			// Not digits: no route matches, so the router answers and no handler runs.
			$notFound = self::Send($method, "/api/labels/$kind/abc$suffix", $body, self::$adminKey);
			self::assertSame(404, $notFound['status'], "$method $documented abc: " . $notFound['body']);

			// Digits that do not fit an integer match the route and are refused by the middleware.
			$tooBig = self::Send($method, "/api/labels/$kind/99999999999999999999$suffix", $body, self::$adminKey);
			self::AssertBody($tooBig, $method, $documented, 400, ['error_message' => 'Invalid path parameter: {id} has to be an integer']);
		}
	}

	public function testAMissingAssetIsALabelRefusalNotFound(): void
	{
		$renderer = self::Send('POST', '/api/labels/workers/' . self::$ownerWorkerId . '/renderer-credentials', [], self::$adminKey);
		self::assertSame(200, $renderer['status'], $renderer['body']);

		$response = self::Send('GET', '/api/labels/assets/999999/bytes', null, $renderer['json']['credential']);

		self::AssertBody($response, 'GET', '/labels/assets/{assetId}/bytes', 404, ['field' => 'asset_id', 'code' => 'not_found', 'error_message' => 'No such asset']);
	}

	public function testTheRendererAndByteRoutesDocumentTheir422AsALabelRefusal(): void
	{
		$routes = [
			'POST /labels/render/claim', 'POST /labels/render/{requestId}/result', 'POST /labels/render/{requestId}/invalid',
			'POST /labels/render/{requestId}/failed', 'GET /labels/assets/{assetId}/bytes', 'GET /labels/artifacts/{artifactId}/bytes',
		];

		foreach ($routes as $key)
		{
			[$method, $path] = explode(' ', $key);
			$response = self::Spec()['paths'][$path][strtolower($method)]['responses']['422'];

			self::assertSame(['$ref' => '#/components/schemas/LabelRefusal'], $response['content']['application/json']['schema'] ?? null, "$key 422");
			self::assertStringContainsString('answers every refusal with 422', $response['description'], "$key 422 says unauthorized and forbidden are 422 here");
		}

		$artifact = self::Spec()['paths']['/labels/artifacts/{artifactId}/bytes']['get']['responses'];
		self::assertStringContainsString('forbidden refusal', $artifact['422']['description']);
		self::assertStringContainsString('worker key', $artifact['401']['description']);
		self::assertStringContainsString('renderer key', self::Spec()['paths']['/labels/render/claim']['post']['responses']['401']['description']);
	}

	public function testAnUploadOverTheLimitAnswers413AnApiError(): void
	{
		// The limit is the smallest of FILE_STORAGE_MAX_SIZE_MB and the php.ini upload directives. The suite's PHP
		// (memory_limit 128M) has upload_max_filesize 2M, so the body here is 2 MiB + 1, not 64 MB.
		$response = self::Send('PUT', '/api/files/userfiles/' . base64_encode('oversized.txt'), null, self::$adminKey, str_repeat('a', FileSizeLimit::EffectiveMaxBytes() + 1));

		self::assertSame(413, $response['status'], substr($response['body'], 0, 300));
		self::assertSame(['error_message'], array_keys($response['json']));
		self::assertStringContainsString('FILE_STORAGE_MAX_SIZE_MB', $response['json']['error_message']);
		self::AssertMatchesDocumentedResponse($response['json'], 'PUT', '/files/{group}/{fileName}', 413);
	}

	public function testAStaleDraftRevisionIsALabelRefusalConflict(): void
	{
		$template = self::Send('POST', '/api/labels/templates', ['name' => 'Errbodies draft', 'entity_kind' => 'location'], self::$adminKey);
		self::assertSame(200, $template['status'], $template['body']);

		$response = self::Send('PUT', '/api/labels/templates/' . (int)$template['json']['id'] . '/draft', ['document' => [], 'revision_token' => 'stale'], self::$adminKey);

		self::AssertBody($response, 'PUT', '/labels/templates/{templateId}/draft', 409, [
			'field' => 'revision_token', 'code' => 'stale_revision',
			'error_message' => 'The draft changed since it was read; reload it and reapply the edit',
		]);
	}

	public function testTheConsumptionPermissionGateAnswersAnApiErrorTheDocumentAllows(): void
	{
		$response = self::Send('GET', '/api/consumption/recipes', null, self::$nobodyKey);

		self::AssertBody($response, 'GET', '/consumption/recipes', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_STOCK_VIEW]);

		$refill = self::Send('GET', '/api/consumption/recipes/1/refill', null, self::$nobodyKey);

		self::AssertBody($refill, 'GET', '/consumption/recipes/{recipeId}/refill', 403, ['error_message' => 'Permission missing: ' . User::PERMISSION_STOCK_VIEW]);
	}
}
