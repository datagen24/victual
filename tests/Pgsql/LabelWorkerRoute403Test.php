<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\LabelWorkerCredentialService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 894: POST /labels/pair, /labels/credentials/rotate, /labels/register and
 * /labels/jobs/claim cannot answer 403, so the document does not declare one.
 *
 * Four responses used to be declared with "Not produced by this operation". Deleting a
 * declared status needs more than a reading of the four handlers: BaseAuthMiddleware also
 * answers 403 for a cross-origin session write, a read-only key, a pending password change,
 * and (ReverseProxyAuthenticator) a request from an untrusted proxy address. This class sends
 * each of those, and a worker credential of the wrong key type, to all four operations and
 * holds the answer to the one the middleware gives today: 401, never 403. It pins that the
 * 403 stays unreachable, so a later change that makes it reachable fails here and has to
 * declare the response again.
 *
 * Why they cannot: BaseAuthMiddleware returns for a worker route at its worker-credential
 * branch (401 or straight to the handler) and for labels-pair at its public branch, in both
 * cases before the origin, read-only and password-change checks, which run only after
 * AuthenticateRequest() has identified a user. The proxy-address 403 is thrown from inside
 * AuthenticateRequest(), which neither branch calls.
 *
 * The requests go through authstack-subprocess-helper.php because it can carry a session
 * cookie, an Origin header, $_SERVER entries and a VICTUAL_AUTH_CLASS override, which
 * labelapi-subprocess-helper.php cannot.
 */
class LabelWorkerRoute403Test extends PgsqlSchemaTestCase
{
	private const USER_ID = 9810;
	private const FLAGGED_USER_ID = 9811;
	private const WORKER_ID = 9812;
	private const PROXY_USER = 'route403-proxy';

	/** The four operations by path; each is also the key of the document's paths. */
	private const OPERATIONS = ['/labels/pair', '/labels/credentials/rotate', '/labels/register', '/labels/jobs/claim'];

	private static PDO $db;
	private static string $userKey;
	private static string $readOnlyKey;
	private static string $flaggedKey;
	private static string $session;
	private static string $flaggedSession;
	private static string $workerKey;
	private static string $rendererKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::USER_ID . ", 'route403-user', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::FLAGGED_USER_ID . ", 'route403-flagged', 'fixture')");
		self::$db->exec("INSERT INTO users(username, password) VALUES ('" . self::PROXY_USER . "', 'fixture')");

		self::$userKey = self::IssueKey(self::USER_ID, false);
		self::$readOnlyKey = self::IssueKey(self::USER_ID, true);
		self::$flaggedKey = self::IssueKey(self::FLAGGED_USER_ID, false);
		self::$session = self::IssueSession(self::USER_ID);
		self::$flaggedSession = self::IssueSession(self::FLAGGED_USER_ID);

		self::$db->exec("INSERT INTO label_workers (id, name, configuration_mode, active) VALUES (" . self::WORKER_ID . ", 'route403-worker', 'declared', 1)");
		$service = new LabelWorkerCredentialService(self::$db);
		self::$db->beginTransaction();
		self::$workerKey = $service->IssueDeclared(self::WORKER_ID, self::USER_ID)['credential'];
		self::$rendererKey = $service->IssueRenderer(self::WORKER_ID, self::USER_ID)['credential'];
		self::$db->commit();

		// The flag is set after the keys exist: a flagged account's keys are refused on
		// every route that reaches PasswordChangeRequired(), which is the point.
		self::$db->exec('UPDATE users SET must_change_password = 1 WHERE id = ' . self::FLAGGED_USER_ID);
	}

	// ---------------------------------------------------------------- the document

	public function testTheFourOperationsDeclareNo403(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, 512, JSON_THROW_ON_ERROR);

		foreach (self::OPERATIONS as $path)
		{
			// PHP turns the numeric string keys into integers
			$statuses = array_keys($spec['paths'][$path]['post']['responses']);
			sort($statuses);

			self::assertSame([200, 401, 422], $statuses, "POST $path declares exactly the statuses it can answer");
		}
	}

	// ---------------------------------------------------------------- what is not a worker credential

	public function testNothingThatIsNotAWorkerCredentialAnswers403(): void
	{
		$before = self::State();

		foreach (self::Callers() as $label => $caller)
		{
			foreach (self::OPERATIONS as $path)
			{
				$response = self::Send($path, $caller);

				self::AssertRefused($response, $path, "$label on POST $path");
			}
		}

		self::assertSame($before, self::State(), 'a refused request wrote nothing');
	}

	public function testAUntrustedProxyAddressIsNotAnswered403OnTheseRoutes(): void
	{
		// ReverseProxyAuthenticator throws HttpForbiddenException from inside
		// AuthenticateRequest(), which neither the worker branch nor the labels-pair branch
		// calls. The control is the same request on a route that does authenticate.
		$settings = ['AUTH_CLASS' => 'Victual\\Middleware\\Auth\\ReverseProxyAuthMiddleware', 'REVERSE_PROXY_AUTH_TRUSTED_PROXIES' => '10.0.0.0/24'];
		$request = [
			'headers' => ['REMOTE_USER' => self::PROXY_USER],
			'server' => ['REMOTE_ADDR' => '203.0.113.9'],
			'settings' => $settings,
		];

		$control = self::Dispatch('GET', '/api/user', $request);
		self::assertSame(403, $control['status'], 'the control reaches the untrusted-proxy refusal: ' . $control['body']);

		foreach (self::OPERATIONS as $path)
		{
			$response = self::Send($path, $request);

			self::AssertRefused($response, $path, "untrusted proxy address on POST $path");
		}
	}

	public function testAWorkerCredentialIsNotRefusedByTheCheapChecksThatRefuseAUser(): void
	{
		// The positive control for the matrix above: a valid worker credential passes the
		// same Origin, cookie and flagged-owner conditions and reaches the handler, so the
		// 401s above are the credential's absence and not the middleware refusing everything.
		$owner = ['headers' => ['VICTUAL-API-KEY' => self::$workerKey, 'Origin' => 'https://evil.example'], 'cookie' => self::$flaggedSession];
		self::$db->exec('UPDATE users SET must_change_password = 1 WHERE id = ' . self::USER_ID);

		try
		{
			$claim = self::Send('/labels/jobs/claim', $owner);
			self::assertSame(200, $claim['status'], $claim['body']);

			$register = self::Send('/labels/register', $owner);
			self::assertSame(200, $register['status'], $register['body']);

			// A declared credential has no pairing session, so rotation refuses it in the
			// service, as a 401 label refusal rather than a 403.
			$rotate = self::Send('/labels/credentials/rotate', $owner);
			self::assertSame(401, $rotate['status'], $rotate['body']);
			self::assertSame('unauthorized', json_decode($rotate['body'], true)['code'] ?? null, $rotate['body']);

			// Pairing ignores the credential entirely.
			$pair = self::Send('/labels/pair', $owner);
			self::assertSame(401, $pair['status'], $pair['body']);
			self::assertSame('unauthorized', json_decode($pair['body'], true)['code'] ?? null, $pair['body']);
		}
		finally
		{
			self::$db->exec('UPDATE users SET must_change_password = 0 WHERE id = ' . self::USER_ID);
		}
	}

	// ---------------------------------------------------------------- fixtures and transport

	/** @return array<string, array> request options, by what the caller presents */
	private static function Callers(): array
	{
		$evil = 'https://evil.example';

		return [
			'a user session cookie' => ['cookie' => self::$session],
			'a user session cookie from another origin' => ['cookie' => self::$session, 'headers' => ['Origin' => $evil]],
			'a user session cookie, Origin null' => ['cookie' => self::$session, 'headers' => ['Origin' => 'null']],
			'a user API key' => ['headers' => ['VICTUAL-API-KEY' => self::$userKey]],
			'a read-only user API key' => ['headers' => ['VICTUAL-API-KEY' => self::$readOnlyKey]],
			'an account that must change its password, by key' => ['headers' => ['VICTUAL-API-KEY' => self::$flaggedKey]],
			'an account that must change its password, by session' => ['cookie' => self::$flaggedSession],
			'a renderer credential' => ['headers' => ['VICTUAL-API-KEY' => self::$rendererKey]],
			'no credential at all' => [],
		];
	}

	/** Rows a refused request must not have written, and the sessions it must not have touched. */
	private static function State(): array
	{
		$counts = [];

		foreach (['print_attempts', 'print_jobs', 'label_worker_capabilities', 'label_worker_sessions', 'label_worker_credentials', 'api_keys', 'sessions', 'users'] as $table)
		{
			$counts[$table] = (int)self::$db->query("SELECT count(*) FROM $table")->fetchColumn();
		}

		$counts['flagged'] = (int)self::$db->query('SELECT count(*) FROM users WHERE must_change_password = 1')->fetchColumn();

		return $counts;
	}

	private static function AssertRefused(array $response, string $path, string $why): void
	{
		self::assertNotSame(403, $response['status'], "$why answered 403: " . $response['body']);
		self::assertSame(401, $response['status'], "$why: " . $response['body']);

		$body = json_decode($response['body'], true);

		if ($path === '/labels/pair')
		{
			// Pairing is public; the service weighs the material and refuses it
			self::assertSame(['field' => 'material', 'code' => 'unauthorized', 'error_message' => 'Invalid or expired pairing material'], $body, "$why: " . $response['body']);
		}
		else
		{
			self::assertSame(['error_message' => 'Unauthorized'], $body, "$why: " . $response['body']);
		}
	}

	private static function Bodies(): array
	{
		return [
			'/labels/pair' => ['material' => str_repeat('0', 64)],
			'/labels/credentials/rotate' => ['rotation_request_id' => str_repeat('1', 64), 'material' => str_repeat('2', 64)],
			'/labels/register' => ['drivers' => []],
			'/labels/jobs/claim' => ['limit' => 1],
		];
	}

	private static function IssueKey(int $userId, bool $readOnly): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT, $readOnly ? 1 : 0]);

		return $key;
	}

	private static function IssueSession(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO sessions (session_key, user_id, expires) VALUES (?, ?, now() + interval '1 day')");
		$statement->execute([$key, $userId]);

		return $key;
	}

	/** POST to one of the four operations with the caller's credentials and that operation's body. */
	private static function Send(string $path, array $options): array
	{
		return self::Dispatch('POST', '/api' . $path, $options + ['body' => self::Bodies()[$path]]);
	}

	/**
	 * One request through the production middleware stack, in a process of its own.
	 *
	 * @param array $options headers, cookie, body, server and settings (VICTUAL_* overrides)
	 * @return array{status: int, headers: array, body: string}
	 */
	private static function Dispatch(string $method, string $path, array $options): array
	{
		$spec = ['method' => $method, 'path' => $path];

		foreach (['headers', 'cookie', 'body', 'server'] as $key)
		{
			if (isset($options[$key]))
			{
				$spec[$key] = $options[$key];
			}
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

		foreach ($options['settings'] ?? [] as $name => $value)
		{
			$environment['VICTUAL_' . $name] = $value;
		}

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/authstack-subprocess-helper.php', base64_encode(json_encode($spec))],
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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}
}
