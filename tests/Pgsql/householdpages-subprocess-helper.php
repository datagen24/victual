<?php

// One Grocycode image render, in a process of its own, printed as JSON metadata.
//
// GROCYCODE_TYPE picks the symbology (DataMatrix when it is '2D', Code128 otherwise -
// docs/grocycode.md calls Code128 the documented alternative), and it is a VICTUAL_*
// constant. PHP cannot redefine a constant and PHPUnit runs a class in one process, so
// the second symbology a test wants needs a fresh process - the same reason
// tests/Pgsql/rbac-subprocess-helper.php exists.
//
// request-subprocess-helper.php cannot serve this: it prints the response body inside a
// JSON document, and a PNG is not valid UTF-8, so json_encode() would refuse the whole
// answer. What is printed instead is what a caller can check about a picture without
// decoding it - its media type, its length and its digest.
//
//   VICTUAL_GROCYCODE_TYPE=1D php householdpages-subprocess-helper.php <chore id>
//
// Output: {"status": <int>, "content_type": "<string>", "length": <int>, "sha256": "<hex>",
// "magic": "<base64 of the first eight bytes>"}. Attaches to the schema the calling test
// class migrated (RBAC_TEST_SCHEMA / PHPUNIT_DB_NAME), like the other helpers here.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_ID', 9000);
define('VICTUAL_USER_USERNAME', 'phpunit-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

$_SERVER['REQUEST_URI'] = '/chore/' . ($argv[1] ?? '1') . '/grocycode';
$_SERVER['HTTP_HOST'] = 'localhost';

use DI\Container;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Http\Factory\DecoratedResponseFactory;
use Victual\Controllers\ChoresController;
use Victual\Helpers\SlimBladeView;
use Victual\Helpers\UrlManager;
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

$container = new Container();
$container->set('view', new SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new UrlManager(VICTUAL_BASE_URL));

$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $_SERVER['REQUEST_URI']);
$response = (new DecoratedResponseFactory(new ResponseFactory(), new StreamFactory()))->createResponse();
$response = (new ChoresController($container))->ChoreGrocycodeImage($request, $response, ['choreId' => $argv[1] ?? '1']);

$png = (string)$response->getBody();

echo json_encode([
	'status' => $response->getStatusCode(),
	'content_type' => $response->getHeaderLine('Content-Type'),
	'length' => strlen($png),
	'sha256' => hash('sha256', $png),
	'magic' => base64_encode(substr($png, 0, 8))
]);
