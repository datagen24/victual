<?php

// The two StockPagesTest scenarios that need a VICTUAL_* constant set to something other
// than its default before config-dist.php's first load. PHP constants cannot be
// redefined and PHPUnit runs the whole class in one process, so these run as their own
// process, attaching to the schema StockPagesTest already migrated and populated
// (STOCKPAGES_TEST_SCHEMA/PHPUNIT_DB_NAME) by the same reflection injection
// Victual\Tests\Support\PgsqlSchemaTestCase uses - exactly as
// tests/Pgsql/rbac-subprocess-helper.php does.
//
//   php stockpages-subprocess-helper.php <base64 json spec>
//
// Spec keys:
//   labels         bool    value for VICTUAL_FEATURE_FLAG_LABELS (default: left alone)
//   barcodePlugin  string  value for VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN (default: left alone)
//   pages          list    {controller: stock|recipes, method, args, query}
//   needles        list    strings each rendered page is searched for
//
// Prints one JSON object: {"pages": [{"method": ..., "status": ..., "hits": {needle: bool}}]}

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$spec = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);

// Before config.php/config-dist.php, which is the whole reason this is a separate process.
if (array_key_exists('labels', $spec))
{
	define('VICTUAL_FEATURE_FLAG_LABELS', (bool)$spec['labels']);
}

if (array_key_exists('barcodePlugin', $spec))
{
	define('VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN', $spec['barcodePlugin']);
}

require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_USER_ID', 9000);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'stockpages-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// views/layout/default.blade.php reads $_SERVER['REQUEST_URI'] for the manifest link,
// which a CLI process does not have - the same shim tests/bootstrap.php applies.
$_SERVER['REQUEST_URI'] = '/';

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('STOCKPAGES_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

$controllers = [
	'stock' => new Victual\Controllers\StockController($container),
	'recipes' => new Victual\Controllers\RecipesController($container),
];

$results = [];
foreach ($spec['pages'] as $page)
{
	$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/page')->withQueryParams($page['query'] ?? []);
	$response = $controllers[$page['controller']]->{$page['method']}($request, new Response(), $page['args'] ?? []);
	$html = (string)$response->getBody();

	$hits = [];
	foreach ($spec['needles'] ?? [] as $needle)
	{
		$hits[$needle] = str_contains($html, $needle);
	}

	$results[] = [
		'method' => $page['controller'] . '::' . $page['method'],
		'status' => $response->getStatusCode(),
		'hits' => $hits,
	];
}

echo json_encode(['pages' => $results]);
