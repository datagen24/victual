<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\RouteInventory;

/**
 * The web application's real bootstrap, exercised over real HTTP.
 *
 * Everything else in tests/Pgsql/ either calls a controller directly or rebuilds the
 * middleware stack in a subprocess (tests/Pgsql/request-subprocess-helper.php, which is
 * app.php:118-153 copied out because app.php ends in $app->run() and so cannot be
 * included). That copy is the problem this phase exists for: the file that decides what
 * the application *is* - the middleware order, the error handler, the container bindings,
 * the route cache - was never executed by the suite, so a drift between it and its
 * imitation would be invisible. Here the server under test is
 * `php -S ... public/index.php`, which loads app.php itself, so what is asserted is the
 * production bootstrap and not a reproduction of it.
 *
 * The server's worker is an ordinary PHP process: it picks up
 * .devtools/coverage/prepend.php through the same auto_prepend_file wiring every other
 * process in the suite does, and drops its .cov file in the same directory. That is why
 * this can live inside the `suite` job, unlike the Playwright probes
 * (.devtools/coverage/README.md), which need a cross-job merge.
 *
 * It reaches the schema this class migrated through PGOPTIONS rather than through a
 * bootstrap of its own: libpq applies `-c search_path=...` to every connection the server
 * opens, so no copy of app.php's wiring is needed to point the application at the test's
 * tables - which would have reintroduced exactly the duplication this phase is here to
 * remove.
 */
class HttpBootTest extends PgsqlSchemaTestCase
{
	/** The acting administrator: an API key owner and a session owner at once. */
	private const ADMIN_USER_ID = 9600;

	/** A signed-in user holding one unrelated leaf, for the page-permission refusal. */
	private const RESTRICTED_USER_ID = 9601;

	private const ADMIN_SESSION_KEY = 'httpboot-admin-session';
	private const RESTRICTED_SESSION_KEY = 'httpboot-restricted-session';

	/**
	 * The one origin the server under test is configured to allow. CorsMiddleware's
	 * allow-list is empty by default (sweep finding S21), so an installation that has not
	 * configured one emits no CORS headers at all and none of the headers app.php's
	 * comment promises would be observable.
	 */
	private const ALLOWED_ORIGIN = 'http://httpboot-allowed.example';

	private const UNLISTED_ORIGIN = 'http://httpboot-unlisted.example';

	private static PDO $db;
	private static string $apiKey;
	private static int $port = 0;

	/** @var resource|null The `php -S` process, null once it has been stopped. */
	private static $server = null;

	private static string $serverErrorLog = '';
	private static string $serverOutputLog = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec('INSERT INTO users(id, username, password) VALUES '
			. '(' . self::ADMIN_USER_ID . ", 'httpboot-admin', 'fixture'), "
			. '(' . self::RESTRICTED_USER_ID . ", 'httpboot-restricted', 'fixture')");

		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. 'SELECT ' . self::ADMIN_USER_ID . " , id FROM permission_hierarchy WHERE name = 'ADMIN'");

		// Deliberately not STOCK_VIEW: this identity is the negative control for the
		// rendered page below, and it has to be a real signed-in user rather than an
		// anonymous one so that the refusal under test is authorization and not
		// authentication.
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. 'SELECT ' . self::RESTRICTED_USER_ID . " , id FROM permission_hierarchy WHERE name = 'TASKS_VIEW'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::ADMIN_USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		self::$db->exec('INSERT INTO sessions(session_key, user_id, expires) VALUES '
			. "('" . self::ADMIN_SESSION_KEY . "', " . self::ADMIN_USER_ID . ", now() + interval '1 day'), "
			. "('" . self::RESTRICTED_SESSION_KEY . "', " . self::RESTRICTED_USER_ID . ", now() + interval '1 day')");

		self::StartServer();
	}

	public static function tearDownAfterClass(): void
	{
		// However the class ends, including a fatal in the middle of a test: a `php -S`
		// left holding a port outlives the phpunit process and wedges the job.
		self::StopServer();

		parent::tearDownAfterClass();
	}

	// --- The server ---------------------------------------------------------------

	/**
	 * Starts `php -S` on a port that was free a moment ago, and waits for it to accept.
	 *
	 * "Was free a moment ago" is the honest description: a port can only be reserved by
	 * holding it, and the server cannot bind one this process is holding. The window is
	 * closed by retrying on a different port rather than by hoping, which is also what
	 * makes this safe to run beside the other phases of a suite that does not serialise.
	 */
	private static function StartServer(): void
	{
		$root = VICTUAL_ROOT_PATH;
		self::$serverErrorLog = VICTUAL_DATAPATH . '/httpboot-server.err';
		self::$serverOutputLog = VICTUAL_DATAPATH . '/httpboot-server.out';

		foreach (range(1, 5) as $attempt)
		{
			$port = self::FreePort();

			file_put_contents(self::$serverErrorLog, '');
			file_put_contents(self::$serverOutputLog, '');

			$process = proc_open(
				[PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/index.php'],
				[
					1 => ['file', self::$serverOutputLog, 'a'],
					2 => ['file', self::$serverErrorLog, 'a']
				],
				$pipes,
				$root,
				self::ServerEnvironment($port)
			);

			self::assertIsResource($process, 'could not start the php built-in server');

			if (self::WaitForPort($port, $process))
			{
				self::$server = $process;
				self::$port = $port;

				return;
			}

			proc_terminate($process);
			proc_close($process);
		}

		self::fail('the php built-in server did not start on any of five ports. stderr: '
			. file_get_contents(self::$serverErrorLog));
	}

	/**
	 * The environment the server runs in.
	 *
	 * The coverage variables are passed on when the caller has them and left alone when it
	 * does not, which is the contract .devtools/coverage/prepend.php states about itself:
	 * an ordinary run must be untouched by the measured one. They arrive here through the
	 * inherited environment; they are named in the comment rather than filtered because
	 * losing them is the failure this phase would not otherwise notice - the server would
	 * serve every request happily and contribute nothing.
	 */
	private static function ServerEnvironment(int $port): array
	{
		// $_SERVER carries argv, which is an array and cannot be an environment value.
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');

		return array_merge($inherited, [
			// The application's own connection, pointed at this class's schema. libpq
			// applies it to every connection the server opens, including the one
			// DatabaseService makes on the first request.
			'PGOPTIONS' => '-c search_path=' . self::Schema() . ',public',
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			'VICTUAL_CORS_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN,
			'SERVER_PORT' => (string)$port
		]);
	}

	private static function FreePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
		self::assertIsResource($socket, "could not open a probe socket: $errorMessage");

		$name = stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/** @param resource $process */
	private static function WaitForPort(int $port, $process): bool
	{
		foreach (range(1, 100) as $attempt)
		{
			$status = proc_get_status($process);

			if (!$status['running'])
			{
				return false;
			}

			$socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);

			if ($socket !== false)
			{
				fclose($socket);

				return true;
			}

			usleep(50000);
		}

		return false;
	}

	/**
	 * Stops the server and waits for it to be gone. Idempotent, so the test that proves
	 * the shutdown works can run it and tearDownAfterClass can run it again.
	 */
	private static function StopServer(): bool
	{
		if (self::$server === null)
		{
			return true;
		}

		$process = self::$server;
		self::$server = null;

		proc_terminate($process);

		$stopped = false;

		foreach (range(1, 40) as $attempt)
		{
			if (!proc_get_status($process)['running'])
			{
				$stopped = true;

				break;
			}

			usleep(50000);
		}

		if (!$stopped)
		{
			// SIGKILL rather than giving up: a server that ignored SIGTERM still must not
			// outlive this process.
			proc_terminate($process, 9);
			usleep(200000);
		}

		proc_close($process);

		return $stopped;
	}

	// --- Requests -----------------------------------------------------------------

	/**
	 * One request over a socket, spelled out rather than sent through a stream wrapper:
	 * phpunit.xml sets failOnWarning, and the http wrapper emits a PHP warning for every
	 * status this phase is about.
	 *
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string[]>, body: string}
	 */
	private static function Send(string $method, string $path, array $headers = []): array
	{
		// These methods run in their declared order (PHPUnit's default, as RbacTest also
		// relies on), and the one that stops the server is declared last. Saying so here
		// turns a reordering into a sentence rather than into a connection refused.
		self::assertNotNull(self::$server,
			'the server has already been stopped - testTheServerIsStoppedByTermination must run last');

		$socket = fsockopen('127.0.0.1', self::$port, $errorNumber, $errorMessage, 10);
		self::assertIsResource($socket, "could not reach the server under test: $errorMessage");

		stream_set_timeout($socket, 20);

		// HTTP/1.0: the answer then always ends at end of stream, with no chunked framing
		// to unpick, and the built-in server closes the connection itself.
		$request = $method . ' ' . $path . " HTTP/1.0\r\n"
			. 'Host: 127.0.0.1:' . self::$port . "\r\n";

		foreach ($headers as $name => $value)
		{
			$request .= $name . ': ' . $value . "\r\n";
		}

		fwrite($socket, $request . "\r\n");

		$raw = stream_get_contents($socket);
		$timedOut = stream_get_meta_data($socket)['timed_out'];
		fclose($socket);

		self::assertFalse($timedOut, "the server did not answer $method $path within the timeout");

		$split = strpos($raw, "\r\n\r\n");
		self::assertNotFalse($split, "the server's answer to $method $path had no header block: $raw");

		$lines = explode("\r\n", substr($raw, 0, $split));
		$statusLine = array_shift($lines);

		self::assertSame(1, preg_match('#^HTTP/\d\.\d (\d{3})#', $statusLine, $matches),
			"unreadable status line for $method $path: $statusLine");

		$parsed = [];

		foreach ($lines as $line)
		{
			$colon = strpos($line, ':');

			if ($colon === false)
			{
				continue;
			}

			$parsed[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
		}

		return [
			'status' => (int)$matches[1],
			'headers' => $parsed,
			'body' => substr($raw, $split + 4)
		];
	}

	/** @param array{headers: array<string, string[]>} $response */
	private static function Header(array $response, string $name): ?string
	{
		$values = $response['headers'][strtolower($name)] ?? null;

		return $values === null ? null : implode(', ', $values);
	}

	private static function ApiKeyHeaders(): array
	{
		return ['VICTUAL-API-KEY' => self::$apiKey];
	}

	private static function AllowedOriginHeaders(): array
	{
		return ['Origin' => self::ALLOWED_ORIGIN];
	}

	/**
	 * Asserts the four headers CorsMiddleware promises a request from an allowed origin,
	 * whatever the status of the response carrying them.
	 *
	 * @param array{headers: array<string, string[]>} $response
	 */
	private static function AssertCorsHeaders(array $response, string $what): void
	{
		self::assertSame(self::ALLOWED_ORIGIN, self::Header($response, 'Access-Control-Allow-Origin'),
			"$what must name the requesting origin");
		self::assertSame('GET, POST, PUT, DELETE, OPTIONS', self::Header($response, 'Access-Control-Allow-Methods'),
			"$what must say which methods are allowed");
		// The header name comes from the ApiKeyHeaderName binding app.php:104-107 puts in
		// the container, so this is also how that binding is observable from outside.
		self::assertSame('Content-Type, VICTUAL-API-KEY', self::Header($response, 'Access-Control-Allow-Headers'),
			"$what must allow the API key header");
		self::assertStringContainsString('Origin', (string)self::Header($response, 'Vary'),
			"$what varies by origin and a cache has to be told so");
	}

	/** Everything the server has written to stderr since $offset. */
	private static function ServerLogSince(int $offset): string
	{
		clearstatcache(true, self::$serverErrorLog);

		return (string)file_get_contents(self::$serverErrorLog, offset: $offset);
	}

	private static function ServerLogOffset(): int
	{
		clearstatcache(true, self::$serverErrorLog);

		return (int)filesize(self::$serverErrorLog);
	}

	// --- 1. The stack is in the order app.php says ---------------------------------

	/**
	 * app.php:141-151: JsonMiddleware and CorsMiddleware are outermost so that "an
	 * unauthenticated API call" is no longer "answered with a bodyless, untyped 401
	 * carrying no CORS headers". All three of those claims are one response.
	 */
	public function testUnauthenticatedApiRequestIsATypedJsonUnauthorizedCarryingCorsHeaders(): void
	{
		$response = self::Send('GET', '/api/system/info', self::AllowedOriginHeaders());

		self::assertSame(401, $response['status'], 'an API request with no credential is refused');
		self::assertSame('application/json', self::Header($response, 'Content-Type'),
			'the refusal is typed, which is what JsonMiddleware sitting outside authentication buys');
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($response['body'], true),
			'the refusal has a body a client can read rather than being bodyless');

		self::AssertCorsHeaders($response, 'the 401');
	}

	/**
	 * The other half of the same comment: a preflight "matches no registered route" and
	 * used to be "refused by routing before CorsMiddleware could answer it". CorsMiddleware
	 * answers it, unauthenticated, with 204 and no body.
	 */
	public function testPreflightOnAPathWithNoRouteIsAnsweredByCorsAndNotByRouting(): void
	{
		$response = self::Send('OPTIONS', '/api/there-is-no-route-here', self::AllowedOriginHeaders());

		self::assertSame(204, $response['status'], 'a preflight is answered, not refused');
		self::assertSame('', trim($response['body']), 'a 204 carries no body');
		self::AssertCorsHeaders($response, 'the preflight answer');
	}

	/**
	 * A preflight carries no credentials by construction, so answering it must not depend
	 * on one. Same path, same method, no API key and no cookie anywhere in this class's
	 * requests - this is the case the old stack answered 401.
	 */
	public function testPreflightOnARegisteredApiRouteIsNotAuthenticated(): void
	{
		$response = self::Send('OPTIONS', '/api/system/info', self::AllowedOriginHeaders());

		self::assertSame(204, $response['status'],
			'a preflight on a real route is answered by CORS, not refused for want of a key');
		self::AssertCorsHeaders($response, 'the preflight answer');
	}

	/**
	 * Negative control for the allow-list: an origin that is not configured gets the 204
	 * but none of the headers that would let a browser proceed. CorsMiddleware's docblock
	 * is explicit that this - and not a refusal - is how a disallowed origin is stopped.
	 */
	public function testPreflightFromAnUnlistedOriginGetsNoCorsHeaders(): void
	{
		$response = self::Send('OPTIONS', '/api/there-is-no-route-here', ['Origin' => self::UNLISTED_ORIGIN]);

		self::assertSame(204, $response['status'], 'the preflight is still answered');
		self::assertNull(self::Header($response, 'Access-Control-Allow-Origin'),
			'an origin that is not on the list is not told it is allowed');
		self::assertStringContainsString('Origin', (string)self::Header($response, 'Vary'),
			'the answer still depends on the origin, so it is still not cacheable across origins');
	}

	/**
	 * Negative control for "both decide by path whether a request is theirs" (app.php:150):
	 * the same method on a rendered page's path is left to routing, which refuses it.
	 */
	public function testPreflightOutsideTheApiIsLeftToRouting(): void
	{
		$response = self::Send('OPTIONS', '/stockoverview', self::AllowedOriginHeaders());

		self::assertSame(405, $response['status'],
			'a page path has no OPTIONS route and CorsMiddleware does not claim it');
		self::assertNull(self::Header($response, 'Access-Control-Allow-Origin'),
			'a rendered page is same-origin by construction and gets no CORS headers');
	}

	/**
	 * app.php:146-147 names the workaround this move deleted: a catch-all
	 * `$app->any('/api/{routes:.+}', ...)` that existed only so that preflights and
	 * unauthenticated calls got an answer. With it registered the 405 below could not
	 * happen, because the catch-all accepts every method - so that assertion is the
	 * behavioural half of this, and this is the structural one.
	 */
	public function testNoApiCatchAllRouteIsRegistered(): void
	{
		$catchAlls = [];

		foreach (RouteInventory::All() as $operation)
		{
			if (str_starts_with($operation->Path, '/api') && str_contains($operation->Path, ':.+}'))
			{
				$catchAlls[] = $operation->Key();
			}
		}

		self::assertSame([], $catchAlls,
			'the /api catch-all app.php says it deleted must not have come back');
	}

	// --- 2. 404, 405 and 500 are typed and carry CORS headers ----------------------

	/**
	 * app.php:149-151: out here the two middlewares "wrap the error middleware, so a 404,
	 * a 405 and a 500 are typed and get their CORS headers like any other response".
	 */
	public function testApiNotFoundIsTypedAndCarriesCorsHeaders(): void
	{
		$response = self::Send('GET', '/api/no-such-endpoint',
			self::ApiKeyHeaders() + self::AllowedOriginHeaders());

		self::assertSame(404, $response['status'], 'an unregistered API path is not found');
		self::assertSame('application/json', self::Header($response, 'Content-Type'), 'the 404 is typed');
		self::assertArrayHasKey('error_message', (array)json_decode($response['body'], true),
			'the 404 is the application error shape, not Slim default markup');
		self::AssertCorsHeaders($response, 'the 404');
	}

	public function testApiMethodNotAllowedIsTypedAndCarriesCorsHeaders(): void
	{
		$response = self::Send('DELETE', '/api/system/info',
			self::ApiKeyHeaders() + self::AllowedOriginHeaders());

		self::assertSame(405, $response['status'], 'a registered path answers only the methods it declares');
		self::assertSame('application/json', self::Header($response, 'Content-Type'), 'the 405 is typed');
		self::assertArrayHasKey('error_message', (array)json_decode($response['body'], true),
			'the 405 is the application error shape');
		self::AssertCorsHeaders($response, 'the 405');
	}

	/**
	 * The 500 of the same sentence. The fault is made by taking one table out from under
	 * the request - a server fault the request cannot be blamed for, with the rest of the
	 * database intact, which is deliberately not the unreachable-database case the
	 * `errors` phase of .devtools/pgsql/run-tests.sh already covers.
	 *
	 * The body is also checked for what it must not say: an uncaught database failure stays
	 * a 500 but does not quote the statement that failed (issue #48), and this response
	 * goes out to an unauthenticated-in-principle client.
	 */
	public function testApiServerFaultIsTypedAndCarriesCorsHeaders(): void
	{
		$offset = self::ServerLogOffset();

		$response = self::WithTableHidden('batteries', function ()
		{
			return self::Send('GET', '/api/objects/batteries',
				self::ApiKeyHeaders() + self::AllowedOriginHeaders());
		});

		self::assertSame(500, $response['status'], 'a broken table is the server\'s fault, not the caller\'s');
		self::assertSame('application/json', self::Header($response, 'Content-Type'), 'the 500 is typed');
		self::AssertCorsHeaders($response, 'the 500');

		$decoded = (array)json_decode($response['body'], true);
		self::assertArrayHasKey('error_message', $decoded, 'the 500 is the application error shape');
		self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $response['body'],
			'the answer does not quote the driver text of the statement that failed');
		self::assertStringNotContainsStringIgnoringCase('select', $response['body'],
			'the answer does not quote the statement that failed');
		self::assertArrayNotHasKey('error_details', $decoded,
			'stack traces are for the operator\'s log, not for the client, outside dev mode');

		// The same failure reached the operator, which is the half of plan 11 that a
		// response can never show: before it, "a 500 in production left no trace anywhere".
		$logged = self::ServerLogSince($offset);
		self::assertStringContainsString('ERROR:', $logged, 'a server fault is logged at error level');
		self::assertStringContainsString('"path":"/api/objects/batteries"', $logged,
			'the log record names the request that failed');
		self::assertStringContainsString('"status":500', $logged, 'the log record carries the status');
	}

	/**
	 * The state the previous test disturbed is back, and the endpoint that failed answers
	 * again. A test that breaks a schema owes the next one proof that it put it back.
	 */
	#[Depends('testApiServerFaultIsTypedAndCarriesCorsHeaders')]
	public function testTheHiddenTableWasRestored(): void
	{
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM batteries')->fetchColumn(),
			'the table is back under its own name');

		$response = self::Send('GET', '/api/objects/batteries', self::ApiKeyHeaders());

		self::assertSame(200, $response['status'], 'and the endpoint that failed answers again');
		self::assertSame([], json_decode($response['body'], true), 'with the empty collection it should have');
	}

	// --- 3. The error handler is ExceptionController -------------------------------

	/**
	 * app.php:139 replaces Slim's default error handler with ExceptionController. Slim's
	 * own would answer a page route with its "Slim Application Error" / "Page Not Found"
	 * markup; what comes back is the application's error page, rendered through the real
	 * Blade layout.
	 */
	public function testNotFoundOnAPageRendersTheApplicationsOwnErrorPage(): void
	{
		$response = self::Send('GET', '/no-such-page',
			['Cookie' => 'victual_session=' . self::ADMIN_SESSION_KEY]);

		self::assertSame(404, $response['status'], 'an unknown page is not found');
		self::assertStringStartsWith('text/html', (string)self::Header($response, 'Content-Type'),
			'a page route answers in HTML, which is why JsonMiddleware must decide by path');
		self::assertStringContainsString('| Victual</title>', $response['body'],
			'the application\'s own error page, rendered in the real layout');
		self::assertStringNotContainsString('Slim Application Error', $response['body'],
			'Slim\'s default handler was replaced, and this is what tells the two apart');
	}

	/**
	 * A server fault on a page route: ExceptionController renders errors/500 - which itself
	 * reads the database for the system information - and records the failure for the
	 * operator.
	 */
	public function testServerFaultOnAPageRendersTheApplicationsOwnErrorPageAndReachesTheLogger(): void
	{
		$offset = self::ServerLogOffset();

		$response = self::WithTableHidden('batteries', function ()
		{
			return self::Send('GET', '/batteries', ['Cookie' => 'victual_session=' . self::ADMIN_SESSION_KEY]);
		});

		self::assertSame(500, $response['status'], 'the page could not be produced and says so');
		self::assertStringStartsWith('text/html', (string)self::Header($response, 'Content-Type'),
			'the failure of a page is still answered as a page');
		self::assertStringContainsString('| Victual</title>', $response['body'],
			'the application\'s own 500 page, not Slim\'s and not the no-database fallback');
		self::assertStringNotContainsString('The error page itself could not be rendered', $response['body'],
			'the rest of the database is fine, so the real error page is what should be reachable');
		self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $response['body'],
			'the driver text belongs in the log, not on a page served to a browser');

		$logged = self::ServerLogSince($offset);
		self::assertStringContainsString('ERROR:', $logged, 'a server fault is logged at error level');
		self::assertStringContainsString('"path":"/batteries"', $logged, 'the log record names the failing request');
		self::assertStringContainsString('"stack_trace"', $logged,
			'app.php asks the error middleware for details, so the operator\'s copy has them');
	}

	/**
	 * A client error is logged too, but at warning: app.php passes logErrors = true
	 * unconditionally, and ExceptionController separates the two levels so that the volume
	 * a bad path generates does not drown the faults worth reading.
	 */
	public function testAClientErrorIsLoggedAsAWarningRatherThanAnError(): void
	{
		$offset = self::ServerLogOffset();

		self::Send('GET', '/api/no-such-endpoint', self::ApiKeyHeaders());

		$logged = self::ServerLogSince($offset);

		self::assertStringContainsString('WARNING:', $logged, 'a 404 is the caller\'s error, not a fault');
		self::assertStringNotContainsString('ERROR:', $logged, 'and is not recorded as one');
		self::assertStringContainsString('"status":404', $logged, 'the record carries the status it answered');
	}

	// --- 4. Authentication end to end ----------------------------------------------

	public function testAnApiKeyInTheHeaderAuthenticatesTheCaller(): void
	{
		$response = self::Send('GET', '/api/user', self::ApiKeyHeaders());

		self::assertSame(200, $response['status'], 'a valid key is accepted');

		$decoded = json_decode($response['body'], true);
		self::assertIsArray($decoded, "GET /api/user did not answer JSON: {$response['body']}");
		self::assertSame(self::ADMIN_USER_ID, (int)$decoded[0]['id'],
			'and the request acts as the key\'s owner, not as some default user');
		self::assertSame('httpboot-admin', $decoded[0]['username'], 'named as the key\'s owner');
	}

	/**
	 * Sweep finding S11: a key is read from the configured header and, on the calendar iCal
	 * route alone, from a query parameter. A URL is written to logs, proxies and browser
	 * history, so a key that authenticated from the query string anywhere else would leak
	 * by being used. Both spellings are refused - the header's own name as a parameter, and
	 * the "secret" parameter the calendar route does accept.
	 */
	public function testAnApiKeyInTheQueryStringDoesNotAuthenticate(): void
	{
		$asHeaderName = self::Send('GET', '/api/user?VICTUAL-API-KEY=' . self::$apiKey);

		self::assertSame(401, $asHeaderName['status'],
			'the header\'s name as a query parameter is not a credential');

		$asCalendarSecret = self::Send('GET', '/api/user?secret=' . self::$apiKey);

		self::assertSame(401, $asCalendarSecret['status'],
			'the calendar route\'s parameter is scoped to that route and to its own key type');
		self::assertSame(['error_message' => 'Unauthorized'], json_decode($asCalendarSecret['body'], true),
			'and the refusal is the ordinary typed one');
	}

	/** A key that does not exist is refused the same way a missing one is. */
	public function testAnUnknownApiKeyIsRefused(): void
	{
		$response = self::Send('GET', '/api/user', ['VICTUAL-API-KEY' => str_repeat('0', 50)]);

		self::assertSame(401, $response['status'], 'an unknown key authenticates nobody');
	}

	// --- 5. A rendered page, through the real layout --------------------------------

	/**
	 * A session cookie authenticates a page request, and what comes back is a page: the
	 * real Blade layout, with the route's own title in it.
	 */
	public function testASessionCookieAuthenticatesAPageRequestAndTheLayoutIsRendered(): void
	{
		$response = self::Send('GET', '/stockoverview',
			['Cookie' => 'victual_session=' . self::ADMIN_SESSION_KEY]);

		self::assertSame(200, $response['status'], 'a session cookie is a credential for a page');
		self::assertStringStartsWith('text/html', (string)self::Header($response, 'Content-Type'),
			'a page is HTML; JsonMiddleware leaves it alone because it is not an API path');
		self::assertStringContainsString('<!DOCTYPE html>', $response['body'], 'a whole document, not a fragment');
		self::assertStringContainsString('| Victual</title>', $response['body'],
			'the layout\'s title, so the Blade layout really rendered');
		self::assertStringContainsString('httpboot-admin', $response['body'],
			'rendered as the session\'s user - the layout names whoever is signed in');
		self::assertNull(self::Header($response, 'Access-Control-Allow-Origin'),
			'a rendered page is same-origin and gets no CORS headers');
	}

	/**
	 * Negative control for the page above: with no credential the same URL is a redirect to
	 * the login page and no part of the page is served.
	 */
	public function testAPageRequestWithoutACredentialRedirectsToLogin(): void
	{
		$response = self::Send('GET', '/stockoverview');

		self::assertSame(302, $response['status'], 'a page route redirects rather than answering 401');
		self::assertStringEndsWith('/login', (string)self::Header($response, 'Location'),
			'and it redirects to the login page');
		self::assertStringNotContainsString('| Victual</title>', $response['body'],
			'nothing of the page itself is served with the redirect');
	}

	/**
	 * Permission refusal: signed in, but without the leaf the route requires. The refusal
	 * is the application's own 403 page, which is ExceptionController's HttpForbidden
	 * branch rather than Slim's.
	 */
	public function testAPageRequestWithoutThePermissionIsRefused(): void
	{
		$response = self::Send('GET', '/stockoverview',
			['Cookie' => 'victual_session=' . self::RESTRICTED_SESSION_KEY]);

		self::assertSame(403, $response['status'], 'a signed-in user without the leaf may not have the page');
		self::assertStringStartsWith('text/html', (string)self::Header($response, 'Content-Type'),
			'the refusal is a page, because the request was for one');
		self::assertStringContainsString('| Victual</title>', $response['body'],
			'the application\'s own 403 page');
		self::assertStringNotContainsString('Slim Application Error', $response['body'],
			'not Slim\'s default handler');
	}

	// --- The server stops ------------------------------------------------------------

	/**
	 * Last, and on purpose: a `php -S` left running outlives phpunit and holds the port for
	 * the rest of the job. Proving the shutdown works is worth a test of its own, and
	 * tearDownAfterClass is idempotent so it can still run after this.
	 */
	#[Depends('testAPageRequestWithoutThePermissionIsRefused')]
	public function testTheServerIsStoppedByTermination(): void
	{
		self::assertTrue(self::StopServer(), 'the server stopped on SIGTERM, without needing to be killed');

		$socket = @fsockopen('127.0.0.1', self::$port, $errorNumber, $errorMessage, 1);

		if ($socket !== false)
		{
			fclose($socket);
			self::fail('something is still accepting connections on port ' . self::$port);
		}

		self::assertFalse($socket, 'the port is free again once the server has stopped');
	}

	// --- Fixtures -------------------------------------------------------------------

	/**
	 * Runs $work with $table renamed out of the way, and puts it back however $work ends.
	 *
	 * This is how a server fault is produced without making the database unreachable: one
	 * relation disappears, everything the error page itself needs stays where it is.
	 */
	private static function WithTableHidden(string $table, callable $work)
	{
		self::$db->exec('ALTER TABLE ' . $table . ' RENAME TO ' . $table . '_httpboot_hidden');

		try
		{
			return $work();
		}
		finally
		{
			self::$db->exec('ALTER TABLE ' . $table . '_httpboot_hidden RENAME TO ' . $table);
		}
	}
}
