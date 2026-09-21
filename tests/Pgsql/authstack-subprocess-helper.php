<?php

// One request through the whole middleware stack, printed as JSON, with the response
// headers and the $_SERVER entries the authentication stack reads.
//
// request-subprocess-helper.php is the general form of this and is used wherever it
// suffices. It does not suffice here for three reasons, all of which are the subject of
// AuthStackTest rather than incidental to it:
//
//   - CorsMiddleware's whole observable output is response headers, which that helper
//     does not return;
//   - ReverseProxyAuthenticator reads $_SERVER['REMOTE_ADDR'] and, in USE_ENV mode,
//     $_SERVER[REVERSE_PROXY_AUTH_HEADER], neither of which a PSR-7 request carries;
//   - SessionCookie::IsHttpsRequest() reads $_SERVER['HTTPS'] / HTTP_X_FORWARDED_PROTO.
//
// Settings are overridden by the caller through VICTUAL_* environment variables, which is
// what Setting() already consults (helpers/extensions.php:389) - so VICTUAL_AUTH_CLASS,
// VICTUAL_CORS_ALLOWED_ORIGINS and the reverse-proxy settings vary per request without
// this file knowing which ones a test cares about.
//
//   php authstack-subprocess-helper.php <base64 of a JSON request description>
//
// The description is {"method": "GET", "path": "/api/user", "headers": {...},
// "cookie": "<session key>", "body": {...}, "server": {...}, "authority": "host[:port]"};
// everything but method and path is optional. A query string on the path is parsed into the
// request's query params.
// Output: {"status": <int>, "headers": {"<name>": ["<value>", ...]}, "body": "..."}.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

// Before config.php, because Setting() freezes a constant the first time it is asked for
// and ReverseProxyAuthenticator reads these out of $_SERVER rather than out of the request
foreach ($spec['server'] ?? [] as $name => $value)
{
	$_SERVER[$name] = $value;
}

require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_IS_EMBEDDED_INSTALL', false);

// app.php:44-57. The modes that fix a user up front do it here rather than in the
// authentication middleware, which is why BaseAuthMiddleware's root branch can say they
// "have already defined VICTUAL_USER_ID".
if ((VICTUAL_MODE === 'dev' || VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease' || VICTUAL_DISABLE_AUTH === true)
	&& !defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

$parts = explode('?', $spec['path'], 2);
$path = $parts[0];
$queryString = $parts[1] ?? '';

// The authority the request is addressed to, which is what BaseAuthMiddleware compares an
// Origin header against. Defaults to the same "localhost" every other helper uses; a test
// that is about the port being part of an origin sets its own.
$authority = $spec['authority'] ?? 'localhost';

$_SERVER['REQUEST_URI'] = $spec['path'];
$_SERVER['HTTP_HOST'] = $authority;

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

if (!empty(VICTUAL_BASE_PATH))
{
	$app->setBasePath(VICTUAL_BASE_PATH);
}

// app.php:118-153, in the same order, with $app->handle() in place of $app->run()
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

$request = (new ServerRequestFactory())->createServerRequest($spec['method'], 'http://' . $authority . $spec['path']);

if ($queryString !== '')
{
	$queryParams = [];
	parse_str($queryString, $queryParams);
	$request = $request->withQueryParams($queryParams);
}

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

// Production writes the deferred changed time from a shutdown handler DatabaseService
// registers when it opens the connection; this helper installs its own connection instead.
DatabaseService::GetInstance()->GetDialect()->FlushDbChangedTime($pdo);

echo json_encode([
	'status' => $response->getStatusCode(),
	'headers' => $response->getHeaders(),
	'body' => (string)$response->getBody()
]);
