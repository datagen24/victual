<?php

// One `GET /` through the whole middleware stack, in production mode, printed as JSON.
//
// It is a process of its own for the reason rbac-subprocess-helper.php is: the
// authentication middleware defines VICTUAL_USER_ID and VICTUAL_AUTHENTICATED with
// define(), PHP constants cannot be redefined, and PHPUnit runs every test method of a
// class in one process - so the second caller a test tried would be a fatal error rather
// than a different answer. What has to be real here is exactly the part the unit-shaped
// tests skip: routing, the schema check, the configured auth middleware, the error
// handler, and Root() itself. This is app.php with `$app->handle()` in place of
// `$app->run()`, and nothing else added.
//
//   php root-subprocess-helper.php [<session key>]
//
// A session key, when given, goes out as the session cookie. No argument is an anonymous
// browser. Like the rbac helper, it attaches to the schema the calling test class already
// migrated (RBAC_TEST_SCHEMA / PHPUNIT_DB_NAME) rather than a database of its own.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

// public/index.php's definition for a request that is not embedded. Deliberately absent
// from this file: VICTUAL_USER_ID and VICTUAL_AUTHENTICATED - which of them exists after
// authentication is the thing under test.
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
$_SERVER['REQUEST_URI'] = '/';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Victual\Controllers\ExceptionController;
use Victual\Helpers\SlimBladeView;
use Victual\Helpers\StderrLogger;
use Victual\Helpers\UrlManager;
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

$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');

if (isset($argv[1]))
{
	$request = $request->withCookieParams([Victual\Services\SessionService::SESSION_COOKIE_NAME => $argv[1]]);
}

$response = $app->handle($request);

echo json_encode([
	'status' => $response->getStatusCode(),
	'location' => $response->getHeaderLine('Location')
]);
