<?php

// The five rbac-tests.php scenarios that need a VICTUAL_* constant fixed before
// config-dist.php's first load (a default-role code, or a caller identity other than
// the main test's 9000) - PHP constants cannot be redefined, and PHPUnit runs every test
// method in RbacTest in one process, so those scenarios still run as their own process,
// spawned by RbacTest via proc_open exactly as rbac-tests.php spawned itself. What
// changed from that script is only how this process finds its database: rather than a
// dedicated migrated database, it attaches to the schema RbacTest already migrated (via
// RBAC_TEST_SCHEMA/PHPUNIT_DB_NAME), by the same reflection injection
// Victual\Tests\Support\PgsqlSchemaTestCase uses.
//
//   php rbac-subprocess-helper.php CHILD|ADMIN|UNKNOWN
//   php rbac-subprocess-helper.php OWNPICTURE <callerId> <claimedPictureFileName> <expectedStatus> <message>
//   php rbac-subprocess-helper.php OWNPICTUREDELETE <callerId> <claimedPictureFileName> <expectedStatus> <message>

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
if (isset($argv[1])) define('VICTUAL_DEFAULT_ROLES', [$argv[1]]);
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

$isOwnPictureSubprocess = isset($argv[1]) && ($argv[1] === 'OWNPICTURE' || $argv[1] === 'OWNPICTUREDELETE');
define('VICTUAL_USER_ID', $isOwnPictureSubprocess ? (int)$argv[2] : 9000);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'rbac-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', $isOwnPictureSubprocess ? $argv[3] : null);

use Victual\Services\DatabaseService;
use Victual\Services\RolesService;
use Victual\Services\UsersService;
use Victual\Controllers\Users\User;
use Victual\Controllers\Api\RolesApiController;
use Victual\Controllers\Api\UsersApiController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// Attaches to the schema RbacTest's setUpBeforeClass() already created and migrated,
// the way DatabaseService::GetDbConnectionRaw() would connect on first use, except
// pointed at that schema instead of the database's own public one.
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

$roles = RolesService::GetInstance();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));
$roleApi = new RolesApiController($container);
$userApi = new UsersApiController($container);
$checks = 0;

/**
 * Counts a passed assertion, or throws with the message when the condition is false.
 */
function check(bool $ok, string $message): void
{
	global $checks;
	if (!$ok) throw new RuntimeException($message);
	$checks++;
}

/**
 * Builds a PSR-7 request with the given method and parsed body.
 */
function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')->withParsedBody($body);
}

/**
 * Runs the call, asserts the status of its response (or of the HttpException it throws) and returns the response.
 */
function status(callable $work, int $expected, string $message)
{
	try { $response = $work(); $actual = $response->getStatusCode(); }
	catch (Slim\Exception\HttpException $e) { $actual = $e->getCode(); $response = null; }
	check($actual === $expected, "$message: expected $expected, got $actual " . ($response === null ? '' : (string)$response->getBody()));
	return $response;
}

/**
 * Replaces the caller's roles and permissions with exactly the named permissions.
 */
function grant(array $names): void
{
	global $pdo;
	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
	$stmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
	foreach ($names as $name) $stmt->execute([$name]);
}

$code = $argv[1];

if ($code === 'CHILD')
{
	$new = UsersService::GetInstance()->CreateUser('default-child', null, null, 'fixture');
	check(in_array('STOCK_VIEW', User::ResolvedPermissionNames((int)$new->id)), 'Configured Child grants reads');
	check(!in_array('STOCK_PURCHASE', User::ResolvedPermissionNames((int)$new->id)), 'Configured Child withholds purchase');

	// Issue #762. The user form pre-selects the configured default roles and, on save,
	// replaces them with the chosen ones through PUT /users/{id}/roles.
	grant(['ADMIN']);
	$childId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'CHILD'")->fetchColumn();
	$guestId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'GUEST'")->fetchColumn();
	$form = (string)(new Victual\Controllers\UsersController($container))->UserEditForm(request(), (new Slim\Http\Factory\DecoratedResponseFactory(new Slim\Psr7\Factory\ResponseFactory(), new Slim\Psr7\Factory\StreamFactory()))->createResponse(), ['userId' => 'new'])->getBody();
	check(preg_match('/<option value="' . $childId . '"\s+selected\s*>/', $form) === 1, 'The create form pre-selects the default CHILD role');
	check(preg_match('/<option value="' . $guestId . '"\s+selected\s*>/', $form) !== 1, 'and only the default roles');
	status(fn() => $userApi->CreateUser(request('POST', ['username' => 'default-child-form', 'password' => 'fixture'])->withHeader('Content-Type', 'application/json'), new Response(), []), 204, 'Create user');
	$formUserId = (int)$pdo->query("SELECT id FROM users WHERE username = 'default-child-form'")->fetchColumn();
	$held = fn() => array_map('intval', $pdo->query("SELECT role_id FROM user_roles WHERE user_id = $formUserId ORDER BY role_id")->fetchAll(PDO::FETCH_COLUMN));
	check($held() === [$childId], 'CreateUser assigns the default role');
	status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => [$guestId]]), new Response(), ['userId' => (string)$formUserId]), 204, 'Set roles after create');
	check($held() === [$guestId], 'The chosen roles replace the default');
}
elseif ($code === 'ADMIN')
{
	grant(['USERS_CREATE']);
	status(fn() => $userApi->CreateUser(request('POST', ['username' => 'blocked-admin', 'password' => 'fixture']), new Response(), []), 403, 'Default Admin is bounded by creator');
	check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='blocked-admin'")->fetchColumn() === 0, 'Refused default leaves no user');
}
elseif ($code === 'OWNPICTURE')
{
	// Issue #177. This caller (VICTUAL_USER_ID from $argv[2]) holds no permissions
	// at all, so a 404 below only happens because CheckGroupReadPermission's
	// own-picture exception let ServeFile through to the (nonexistent) file lookup,
	// and a 403 only happens because it refused. $argv[3] is what the caller's own
	// row claims as VICTUAL_USER_PICTURE_FILE_NAME - genuinely theirs in one run,
	// spoofed to another user's real picture name (set up by RbacTest) in the other.
	$files = new Victual\Controllers\Api\FilesApiController($container);
	$args = ['group' => 'userpictures', 'fileName' => base64_encode($argv[3])];
	status(fn() => $files->ServeFile(request(), new Response(), $args), (int)$argv[4], $argv[5]);
}
elseif ($code === 'OWNPICTUREDELETE')
{
	// Issue #177's delete-path counterpart. This caller (VICTUAL_USER_ID from
	// $argv[2]) holds USERS_EDIT_SELF but not USERS_EDIT - the Child shape - so a
	// 204 below only happens because CheckUserPictureDeletion's own-picture
	// exception let DeleteFile through with no administer check, and a 403 only
	// happens because it fell through to the USERS_EDIT requirement and refused.
	$files = new Victual\Controllers\Api\FilesApiController($container);
	$args = ['group' => 'userpictures', 'fileName' => base64_encode($argv[3])];
	status(fn() => $files->DeleteFile(request('DELETE'), new Response(), $args), (int)$argv[4], $argv[5]);
}
else
{
	try { UsersService::GetInstance()->CreateUser('unknown-default', null, null, 'fixture'); throw new RuntimeException('Unknown default accepted'); }
	catch (Victual\Controllers\Api\EInvalidApiQuery $e) {}
	check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='unknown-default'")->fetchColumn() === 0, 'Unknown default rolls back account');
}

echo ($isOwnPictureSubprocess ? "$code PASSED: $argv[3]" : "DEFAULT ROLES PASSED: $code") . " ($checks assertions)\n";
exit(0);
