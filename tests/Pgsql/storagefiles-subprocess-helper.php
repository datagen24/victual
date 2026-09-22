<?php

// The StorageFilesTest scenarios that need a VICTUAL_* constant fixed before the first
// request - PHP constants cannot be redefined, and PHPUnit runs every method of a test
// class in one process:
//
//   php storagefiles-subprocess-helper.php SERVE <callerId> <ownPictureFileName> <requestedFileName>
//       the own-picture read exception, which needs VICTUAL_USER_PICTURE_FILE_NAME to be
//       something other than the null the main process is fixed at, and a caller id of
//       its own. Also reports which backend FILE_STORAGE selected, since that too is read
//       once at boot.
//
//   php storagefiles-subprocess-helper.php DEMOSTORAGE <demoDbSuffix> <group> <name>
//       one Create() through FileStorage::GetInstance() under VICTUAL_MODE=demo, where
//       FilesystemStorage appends a per demo instance suffix to its storage path so that
//       several demo instances can share one filesystem (plan 01 Q4). An empty suffix
//       leaves VICTUAL_DEMO_DB_SUFFIX undefined, the way a demo deployment that has not
//       set one in its config.php does.
//
// Attaches to the schema StorageFilesTest already migrated, the way
// tests/Pgsql/rbac-subprocess-helper.php does. Prints one JSON object on stdout:
//   {"backend": "<FileStorage subclass>", "status": <int|null>, "sha256": "<hex|null>"}
// The parent makes every assertion; this process only reports what happened, so a
// scenario that fails says what the response actually was.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';

// A demo deployment sets this in its own config.php, which is loaded before
// config-dist.php; nothing in the application defines it.
if ($argv[1] === 'DEMOSTORAGE' && $argv[2] !== '')
{
	define('VICTUAL_DEMO_DB_SUFFIX', $argv[2]);
}

require_once VICTUAL_ROOT_PATH . '/config-dist.php';

define('VICTUAL_USER_ID', $argv[1] === 'SERVE' ? (int)$argv[2] : 9000);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'storagefiles-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', $argv[1] === 'SERVE' && $argv[3] !== '' ? $argv[3] : null);

use Victual\Controllers\Api\FilesApiController;
use Victual\Services\DatabaseService;
use Victual\Services\Storage\FileStorage;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET search_path TO ' . getenv('STORAGEFILES_TEST_SCHEMA') . ', public');
DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

$status = null;
$body = null;

if ($argv[1] === 'DEMOSTORAGE')
{
	FileStorage::GetInstance()->Create($argv[3], $argv[4], 'demo instance bytes');
}
else
{
	$container = new DI\Container();
	$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
	$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

	$files = new FilesApiController($container);
	$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api');
	$arguments = ['group' => 'userpictures', 'fileName' => base64_encode($argv[4])];

	try
	{
		$response = $files->ServeFile($request, new Response(), $arguments);
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
	}
	catch (Slim\Exception\HttpException $ex)
	{
		$status = $ex->getCode();
	}
}

echo json_encode([
	// Reported rather than asserted here, because which backend the setting selects is
	// itself one of the things the parent checks.
	'backend' => (new ReflectionClass(FileStorage::GetInstance()))->getShortName(),
	'status' => $status,
	'sha256' => $status === 200 ? hash('sha256', (string)$body) : null
]);
