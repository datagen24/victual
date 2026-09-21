<?php

// One request through the whole middleware stack, in production mode, printed as JSON.
//
// root-subprocess-helper.php generalised to any method, path, header set and body: a
// process of its own for the same reason - the authentication middleware define()s the
// acting user's constants, and PHP cannot redefine them, so each request a test wants to
// make needs a fresh process. This is app.php with `$app->handle()` in place of
// `$app->run()`.
//
//   php request-subprocess-helper.php <base64 of a JSON request description>
//
// The description is {"method": "GET", "path": "/api/user", "headers": {...},
// "cookie": "<session key>", "body": {...}}; everything but method and path is optional.
// A body is sent as "application/json" unless "headers" already types the request.
// Output: {"status": <int>, "body": "<response body>"}. Attaches to the schema the calling
// test class migrated (RBAC_TEST_SCHEMA / PHPUNIT_DB_NAME), like the root helper.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

// The server's configured zone, for the one thing that cannot be tested in the suite's own:
// a wall clock a zone skipped at a daylight-saving boundary. The suite runs on UTC, which
// has no such hour, so nothing here could reach that path without saying which zone to be
// in. Read from the environment rather than from config so it stays a property of the
// request under test and not of the installation.
if (getenv('VICTUAL_TEST_TIMEZONE'))
{
	date_default_timezone_set(getenv('VICTUAL_TEST_TIMEZONE'));
}

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

	// Only when the caller did not type the request itself. WireContractTest sends
	// "application/json; charset=utf-8" and a few deliberately wrong types (issue #229),
	// and this used to overwrite whatever it asked for.
	if (!$request->hasHeader('Content-Type'))
	{
		$request = $request->withHeader('Content-Type', 'application/json');
	}
}

$response = $app->handle($request);

// Production writes the deferred changed time from a shutdown handler DatabaseService
// registers when it opens the connection. This helper installs its own connection instead
// (above), so that handler never exists here; flushing by hand is what it would have done.
DatabaseService::GetInstance()->GetDialect()->FlushDbChangedTime($pdo);

echo json_encode([
	'status' => $response->getStatusCode(),
	'body' => (string)$response->getBody()
]);
