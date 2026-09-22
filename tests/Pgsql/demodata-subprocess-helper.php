<?php

// The demo-mode half of DemoDataTest, in its own process.
//
// VICTUAL_MODE decides where DemoDataGeneratorService puts the twelve demo resources:
// straight under storage/ in production mode, and under storage/<locale>/ in demo and
// prerelease mode, so two demo instances in different languages do not share one picture
// folder. It is a constant, and PHP cannot redefine one, so the two layouts cannot both be
// exercised in the process PHPUnit runs the class in. Setting() reads the VICTUAL_MODE
// environment variable ahead of config-dist.php's default (helpers/extensions.php:389),
// which is how the parent asks for the other branch.
//
// Prints one JSON object on stdout and exits 0, or writes to stderr and exits non-zero.
// The same shape as tests/Pgsql/rbac-subprocess-helper.php and its siblings.

$datapath = getenv('VICTUAL_DATAPATH');
$database = getenv('PHPUNIT_DB_NAME');

if ($datapath === false || $database === false)
{
	fwrite(STDERR, "VICTUAL_DATAPATH and PHPUNIT_DB_NAME must be set\n");
	exit(2);
}

define('VICTUAL_ROOT_PATH', dirname(__DIR__, 2));

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

$_SERVER['REQUEST_URI'] ??= '/';

define('VICTUAL_DATAPATH', $datapath);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_ID', 9000);
define('VICTUAL_USER_USERNAME', 'demodata-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

if (VICTUAL_MODE !== 'demo')
{
	fwrite(STDERR, 'expected VICTUAL_MODE=demo, got ' . VICTUAL_MODE . "\n");
	exit(2);
}

use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Services\DemoDataGeneratorService;

// A schema of this process's own, migrated the way PgsqlSchemaTestCase migrates one. The
// parent's schema is not reused: the generator refuses to run twice, and the parent has
// already run it there.
$schema = 'demodata_mode_' . bin2hex(random_bytes(8));

$pdo = new PDO(
	'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . $database,
	getenv('PGUSER'),
	getenv('PGPASSWORD'),
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('CREATE SCHEMA ' . $schema);
$pdo->exec('SET search_path TO ' . $schema . ', public');

DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);

(new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
(new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);

DatabaseMigrationService::GetInstance()->MigrateDatabase();

// The sentinels, in the nested layout this mode is expected to use. Their presence is what
// stops the twelve fetches; the parent checks that they were not overwritten by asking
// whether the folders exist and nothing landed in the production-mode location.
$resources = [
	'productpictures' => ['cookies.jpg', 'cucumber.jpg', 'gummybears.jpg', 'paprika.jpg', 'tomato.jpg'],
	'equipmentmanuals' => ['loremipsum.pdf'],
	'recipepictures' => ['pizza.jpg', 'sandwiches.jpg', 'pancakes.jpg', 'spaghetti.jpg', 'chocolate_sauce.jpg', 'pancakes_chocolate_sauce.jpg'],
];

$nested = VICTUAL_DATAPATH . '/storage/' . VICTUAL_DEFAULT_LOCALE;

foreach ($resources as $folder => $files)
{
	mkdir($nested . '/' . $folder, 0755, true);

	foreach ($files as $file)
	{
		file_put_contents($nested . '/' . $folder . '/' . $file, 'not fetched');
	}
}

try
{
	DemoDataGeneratorService::GetInstance()->PopulateDemoData(false);
}
finally
{
	$pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
}

$nestedFoldersExist = is_dir($nested . '/productpictures')
	&& is_dir($nested . '/equipmentmanuals')
	&& is_dir($nested . '/recipepictures');

echo json_encode([
	'locale_segment' => VICTUAL_DEFAULT_LOCALE,
	'nested_folders_exist' => $nestedFoldersExist,
	'unnested_pictures_exist' => is_dir(VICTUAL_DATAPATH . '/storage/productpictures'),
]);

exit(0);
