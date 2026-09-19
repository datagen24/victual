<?php

// One request through the whole middleware stack, in production mode, printed as JSON.
//
// root-subprocess-helper.php generalised to any method, path, credential and body, for
// tests/Pgsql/BootstrapAdminTest.php. A process of its own for the same reason: the
// authentication middleware define()s the acting user's constants, PHP cannot redefine
// them, so every request a test makes needs a fresh process. This is app.php with
// `$app->handle()` in place of `$app->run()`, JsonMiddleware included because API
// refusals are answered through it.
//
//   php bootstrap-subprocess-helper.php <base64 of a JSON request description>
//
// The description is {"method": "GET", "path": "/api/user", "cookie": "<session key>",
// "apikey": "<key>", "json": {...}, "form": {...}}; everything but method and path is
// optional. Output: {"status": <int>, "location": "<Location header>", "body": "<body>"}.
// Attaches to the schema the calling test class migrated (RBAC_TEST_SCHEMA /
// PHPUNIT_DB_NAME), like the root helper.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Victual\Controllers\ExceptionController;
use Victual\Helpers\SlimBladeView;
use Victual\Helpers\StderrLogger;
use Victual\Helpers\UrlManager;
use Victual\Middleware\JsonMiddleware;
use Victual\Middleware\LocaleMiddleware;
use Victual\Middleware\SchemaVersionMiddleware;
use Victual\Services\DatabaseService;
use Victual\Services\SessionService;

// Stdout is the JSON answer and nothing else. A deprecation notice from code on the request
// path (BaseApiController on PHP 8.5, at the time of writing) goes to stderr, where the
// test reports it, rather than corrupting the answer.
ini_set('display_errors', 'stderr');

$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

// public/index.php's definition for a request that is not embedded. VICTUAL_USER_ID and
// VICTUAL_AUTHENTICATED are deliberately absent: authentication decides them.
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
$_SERVER['REQUEST_URI'] = $spec['path'];
// UrlManager builds redirects from the request's host, which a CLI process does not have
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

$request = (new ServerRequestFactory())->createServerRequest($spec['method'], 'http://localhost' . $spec['path']);

if (isset($spec['cookie']))
{
	$request = $request->withCookieParams([SessionService::SESSION_COOKIE_NAME => $spec['cookie']]);
}

if (isset($spec['apikey']))
{
	$request = $request->withHeader('VICTUAL-API-KEY', $spec['apikey']);
}

if (isset($spec['json']))
{
	$request = $request
		->withHeader('Content-Type', 'application/json')
		->withBody((new StreamFactory())->createStream(json_encode($spec['json'])));
}
elseif (isset($spec['form']))
{
	$request = $request
		->withHeader('Content-Type', 'application/x-www-form-urlencoded')
		->withBody((new StreamFactory())->createStream(http_build_query($spec['form'])));
}

$response = $app->handle($request);

echo json_encode([
	'status' => $response->getStatusCode(),
	'location' => $response->getHeaderLine('Location'),
	'body' => (string)$response->getBody()
]);
