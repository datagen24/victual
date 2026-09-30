<?php

// One HTTP request through the whole middleware stack, in production mode, followed by the
// same request-end work DatabaseService's real shutdown handler would have done - printed as
// JSON. Used by RollbackChangeSignalsTest.php (issue #534).
//
//   php rollback-change-signals-subprocess-helper.php <base64 of a JSON request description>
//
// Request description and result shape match tests/Pgsql/request-subprocess-helper.php
// exactly: in, {"method": "GET", "path": "/api/user", "headers": {...}, "cookie": "<session
// key>", "body": {...}}; out, {"status": <int>, "body": "<response body>"}. MQTT settings
// (VICTUAL_MQTT_ENABLED/HOST/PORT) arrive as environment variables - Setting() already reads
// them ahead of config-dist.php's own defaults, the same mechanism
// tests/Pgsql/mqttcoverage-subprocess-helper.php's scenarios rely on.
//
// This file exists separately from request-subprocess-helper.php, rather than adding to it,
// for two reasons. First, reservation: FIXER_RULES scopes issue #534's fix to DatabaseService
// and its dialect, not to shared test infrastructure other suites (StockConcurrencyTest,
// ComposedOperationAtomicityTest, HttpBootTest, ...) depend on. Second, correctness of what
// each helper promises: request-subprocess-helper.php's own docblock explains that installing
// the database connection by reflection (both helpers do this) means DatabaseService's real
// shutdown handler is never registered, and that helper deliberately replicates only the
// FlushDbChangedTime() half of it by hand - none of its callers need the request-end MQTT or
// InfluxDB publish. RollbackChangeSignalsTest.php is the one caller that does: it has to
// observe whether a refused write's spurious change signal reaches an actual MQTT broker, so
// this helper also replicates DatabaseService::RunRequestEndPublishes(), invoked the same way
// mqttcoverage-subprocess-helper.php's "shutdownisolation" scenario already does - by
// reflection, since register_shutdown_function() only ever runs a closure at the real end of
// the PHP process, which nothing in a test can trigger on demand.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

define('VICTUAL_IS_EMBEDDED_INSTALL', false);
$_SERVER['REQUEST_URI'] = $spec['path'];
$_SERVER['HTTP_HOST'] = 'localhost';

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

// Stdout is the JSON answer and nothing else: a diagnostic goes to stderr, where the test
// reports it, rather than arriving in front of the answer and reducing json_decode() to null.
ini_set('display_errors', 'stderr');

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

// Replicates DatabaseService::RegisterShutdownHandler()'s closure, in the same order, since
// installing the connection by reflection above means that handler was never registered in
// this process. FlushDbChangedTime() first, then the request-end MQTT/InfluxDB publishes -
// exactly production's order - so a change the request wrote (and, for a refused request, then
// rolled back) is reflected in the changed-time table before the publish step decides whether
// there is anything to publish.
DatabaseService::GetInstance()->GetDialect()->FlushDbChangedTime($pdo);

$runRequestEndPublishes = new ReflectionMethod(DatabaseService::class, 'RunRequestEndPublishes');
// A ReflectionMethod needs no setAccessible(): the call has had no effect since PHP 8.1, and 8.5 deprecates making it.
$runRequestEndPublishes->invoke(DatabaseService::GetInstance());

echo json_encode([
	'status' => $response->getStatusCode(),
	'body' => (string)$response->getBody()
]);
