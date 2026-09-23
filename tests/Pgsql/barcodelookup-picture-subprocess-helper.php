<?php

// request-subprocess-helper.php, generalised so tests/Pgsql/StockCoverageTest.php's barcode
// picture scenarios can drive the real StockService::ExternalBarcodeLookup() picture-fetch
// path (extension allow-list, OutboundHostPolicy) end to end - through the same middleware
// stack, the same PostgreSQL schema, and a real StockApiController route - without ever
// reaching the network.
//
// GuzzleHttp\Client is declared in the global namespace before packages/autoload.php runs,
// the same substitution tests/Pgsql/barcodelookup-subprocess-helper.php uses for
// OpenFoodFactsBarcodeLookupPlugin: services/StockService.php's picture download does
// `use GuzzleHttp\Client;` and `new Client()`, which binds to this stand-in instead of the
// real class, because the stand-in already exists by the time anything asks for it. It
// records whether request() was ever called and with what, which is how the "no outbound
// request was made" assertions are proved rather than assumed - a refused host could
// otherwise look identical to a host that was fetched and merely timed out.
//
//   php barcodelookup-picture-subprocess-helper.php <base64 of a JSON spec>
//
// Spec: {method, path, headers, cookie, body} - as request-subprocess-helper.php - plus
// an optional "guzzle" object {status, headers, body_base64} describing the canned HTTP
// response Client::request() returns when it is called at all.
//
// Output: request-subprocess-helper.php's {status, body} plus request_made (bool),
// request_uri, request_options (the "curl" and "allow_redirects" entries in particular,
// which is where the resolved-address pin and the redirect refusal are asserted).

namespace GuzzleHttp
{
	class Client
	{
		public static bool $RequestMade = false;
		public static ?string $LastUri = null;
		public static array $LastOptions = [];
		public static int $CannedStatus = 200;
		public static array $CannedHeaders = [];
		public static string $CannedBody = '';

		public function __construct(array $config = [])
		{
		}

		public function request(string $method, $uri = '', array $options = [])
		{
			self::$RequestMade = true;
			self::$LastUri = $method . ' ' . (string)$uri;
			self::$LastOptions = $options;

			return new \GuzzleHttp\Psr7\Response(self::$CannedStatus, self::$CannedHeaders, self::$CannedBody);
		}
	}
}

namespace
{
	use DI\Container;
	use Slim\Factory\AppFactory;
	use Slim\Psr7\Factory\ServerRequestFactory;
	use Slim\Psr7\Factory\StreamFactory;
	use Victual\Controllers\ExceptionController;
	use Victual\Helpers\SlimBladeView;
	use Victual\Helpers\StderrLogger;
	use Victual\Helpers\UrlManager;
	use Victual\Middleware\CorsMiddleware;
	use Victual\Middleware\JsonMiddleware;
	use Victual\Middleware\LocaleMiddleware;
	use Victual\Middleware\SchemaVersionMiddleware;
	use Victual\Services\DatabaseService;

	define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
	require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
	require_once VICTUAL_DATAPATH . '/config.php';
	require_once VICTUAL_ROOT_PATH . '/config-dist.php';

	if (getenv('VICTUAL_TEST_TIMEZONE'))
	{
		date_default_timezone_set(getenv('VICTUAL_TEST_TIMEZONE'));
	}

	$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

	if (isset($spec['guzzle']))
	{
		\GuzzleHttp\Client::$CannedStatus = (int)($spec['guzzle']['status'] ?? 200);
		\GuzzleHttp\Client::$CannedHeaders = $spec['guzzle']['headers'] ?? [];
		\GuzzleHttp\Client::$CannedBody = isset($spec['guzzle']['body_base64']) ? base64_decode($spec['guzzle']['body_base64']) : '';
	}

	define('VICTUAL_IS_EMBEDDED_INSTALL', false);
	$_SERVER['REQUEST_URI'] = $spec['path'];
	$_SERVER['HTTP_HOST'] = 'localhost';

	$pdo = new PDO(
		'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
		getenv('PGUSER'),
		getenv('PGPASSWORD'),
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);
	$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
	DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
	(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
	(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

	AppFactory::setContainer(new Container());
	$app = AppFactory::create();

	$container = $app->getContainer();
	$container->set('view', fn () => new SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_VIEWCACHE_PATH));
	$container->set('UrlManager', fn () => new UrlManager(VICTUAL_BASE_URL));
	$container->set('ApiKeyHeaderName', fn () => 'VICTUAL-API-KEY');

	require_once VICTUAL_ROOT_PATH . '/routes.php';

	$app->add(new LocaleMiddleware($container, $app->getResponseFactory()));
	$authMiddlewareClass = VICTUAL_AUTH_CLASS;
	$app->add(new $authMiddlewareClass($container, $app->getResponseFactory()));
	$app->addBodyParsingMiddleware();
	$app->addRoutingMiddleware();
	$app->add(new SchemaVersionMiddleware($container, $app->getResponseFactory()));
	$errorMiddleware = $app->addErrorMiddleware(false, false, false);
	$errorMiddleware->setDefaultErrorHandler(new ExceptionController($container, $app->getResponseFactory(), new StderrLogger()));
	$app->add(new JsonMiddleware($container, $app->getResponseFactory()));
	$app->add(new CorsMiddleware($container, $app->getResponseFactory()));

	$request = (new ServerRequestFactory())->createServerRequest($spec['method'], 'http://localhost' . $spec['path']);

	foreach ($spec['headers'] ?? [] as $name => $value)
	{
		$request = $request->withHeader($name, $value);
	}

	if (isset($spec['cookie']))
	{
		$request = $request->withCookieParams([Victual\Services\SessionService::SESSION_COOKIE_NAME => $spec['cookie']]);
	}

	if (isset($spec['body']))
	{
		$request = $request->withBody((new StreamFactory())->createStream(json_encode($spec['body'])));

		if (!$request->hasHeader('Content-Type'))
		{
			$request = $request->withHeader('Content-Type', 'application/json');
		}
	}

	$response = $app->handle($request);

	DatabaseService::GetInstance()->GetDialect()->FlushDbChangedTime($pdo);

	echo json_encode([
		'status' => $response->getStatusCode(),
		'body' => (string)$response->getBody(),
		'request_made' => \GuzzleHttp\Client::$RequestMade,
		'request_uri' => \GuzzleHttp\Client::$LastUri,
		'request_options' => [
			'allow_redirects' => \GuzzleHttp\Client::$LastOptions['allow_redirects'] ?? null,
			'curl' => \GuzzleHttp\Client::$LastOptions['curl'] ?? null,
		],
	]);
}
