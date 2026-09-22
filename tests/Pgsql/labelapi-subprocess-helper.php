<?php

// One request through the whole middleware stack, in production mode, printed as JSON.
//
// request-subprocess-helper.php with two differences this phase needs, and no others: the
// response body is base64-encoded, and the response headers are reported.
//
// Two of the routes under test - GET /api/labels/assets/{id}/bytes and
// GET /api/labels/artifacts/{id}/bytes - answer with PNG bytes rather than with the JSON
// envelope. Those bytes are not valid UTF-8, so json_encode() of the raw body returns false
// and the shared helper prints nothing at all; base64 is what makes a binary response
// reportable. The headers come back because `Content-Type` and `Cache-Control: no-store` are
// part of what those two routes promise, and a test that cannot see them cannot check them.
//
//   php labelapi-subprocess-helper.php @<path to a file holding the JSON request description>
//
// The description arrives in a file rather than in an argument because an asset upload puts
// a whole font in the body, and an argument list has a length limit a font exceeds.
//
// The description is {"method": "GET", "path": "/api/...", "headers": {...}, "body": {...}}
// or, for a body that is not a JSON object, {"raw_body": "<string>"} with a Content-Type of
// its own; everything but method and path is optional. Output:
// {"status": <int>, "body_base64": "<base64>", "headers": {"<name>": "<value>", ...}}.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

$spec = json_decode(file_get_contents(substr($argv[1] ?? '@', 1)), true, flags: JSON_THROW_ON_ERROR);

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

// `raw_body` sends the string as it stands, so a test can put a body on the wire that is not
// a JSON object - which is the only way to reach the controllers' "this is not an object"
// branch, since Slim's JSON parser hands back null rather than a scalar.
if (isset($spec['raw_body']))
{
	$request = $request->withBody((new StreamFactory())->createStream($spec['raw_body']));
}
elseif (isset($spec['body']))
{
	$request = $request->withBody((new StreamFactory())->createStream(json_encode($spec['body'])));

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

$headers = [];

foreach ($response->getHeaders() as $name => $values)
{
	$headers[$name] = implode(', ', $values);
}

echo json_encode([
	'status' => $response->getStatusCode(),
	'body_base64' => base64_encode((string)$response->getBody()),
	'headers' => $headers
]);
