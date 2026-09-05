<?php

// Wave 3a against a freshly migrated, disposable PostgreSQL database.
// Run through run-tests.sh rbac. No SQLite runtime model is added above the freeze.
define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
if (isset($argv[1])) define('VICTUAL_DEFAULT_ROLES', [$argv[1]]);
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9000);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'rbac-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
use Victual\Services\RolesService;
use Victual\Services\UsersService;
use Victual\Controllers\Users\User;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Controllers\Api\RolesApiController;
use Victual\Controllers\Api\UsersApiController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$roles = RolesService::GetInstance();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));
$roleApi = new RolesApiController($container);
$userApi = new UsersApiController($container);
$checks = 0;
function check(bool $ok, string $message): void
{
	global $checks;
	if (!$ok) throw new RuntimeException($message);
	$checks++;
}
function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')->withParsedBody($body);
}
function status(callable $work, int $expected, string $message)
{
	try { $response = $work(); $actual = $response->getStatusCode(); }
	catch (Slim\Exception\HttpException $e) { $actual = $e->getCode(); $response = null; }
	check($actual === $expected, "$message: expected $expected, got $actual " . ($response === null ? '' : (string)$response->getBody()));
	return $response;
}
function grant(array $names): void
{
	global $pdo;
	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
	$stmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
	foreach ($names as $name) $stmt->execute([$name]);
}
function roleId(string $code): int
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id FROM roles WHERE code = ?'); $stmt->execute([$code]);
	return (int)$stmt->fetchColumn();
}
function permissionId(string $name): int
{
	global $pdo;
	$stmt = $pdo->prepare('SELECT id FROM permission_hierarchy WHERE name = ?'); $stmt->execute([$name]);
	return (int)$stmt->fetchColumn();
}

if (isset($argv[1]))
{
	$code = $argv[1];
	if ($code === 'CHILD')
	{
		$new = UsersService::GetInstance()->CreateUser('default-child', null, null, 'fixture');
		check(in_array('STOCK_VIEW', User::ResolvedPermissionNames((int)$new->id)), 'Configured Child grants reads');
		check(!in_array('STOCK_PURCHASE', User::ResolvedPermissionNames((int)$new->id)), 'Configured Child withholds purchase');
	}
	elseif ($code === 'ADMIN')
	{
		grant(['USERS_CREATE']);
		status(fn() => $userApi->CreateUser(request('POST', ['username' => 'blocked-admin', 'password' => 'fixture']), new Response(), []), 403, 'Default Admin is bounded by creator');
		check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='blocked-admin'")->fetchColumn() === 0, 'Refused default leaves no user');
	}
	else
	{
		try { UsersService::GetInstance()->CreateUser('unknown-default', null, null, 'fixture'); throw new RuntimeException('Unknown default accepted'); }
		catch (Victual\Controllers\Api\EInvalidApiQuery $e) {}
		check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='unknown-default'")->fetchColumn() === 0, 'Unknown default rolls back account');
	}
	echo "DEFAULT ROLES PASSED: $code ($checks assertions)\n";
	exit(0);
}

check((int)$pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() === 0, 'Fresh user_roles is empty');
check((int)$pdo->query('SELECT COUNT(*) FROM roles WHERE builtin = 1')->fetchColumn() === 4, 'Four built-in roles');
check((int)$pdo->query("SELECT COUNT(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id=up.permission_id WHERE up.user_id=1 AND p.name LIKE '%_VIEW'")->fetchColumn() === 6, 'Existing admin receives all six backfill leaves');
$pdo->exec("INSERT INTO users(id, username, password) VALUES (9000, 'rbac-caller', 'fixture'), (9001, 'rbac-target', 'fixture'), (9002, 'rbac-admin', 'fixture')");
$pdo->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9002, ' . roleId('ADMIN') . ')');
$pdo->exec("UPDATE users SET picture_file_name='protected.png' WHERE id=9002");

// Every routed read in the six subtrees must refuse before querying/rendering data.
grant([]);
$source = file_get_contents(VICTUAL_ROOT_PATH . '/routes.php');
preg_match_all("/\\\$group->get\\('([^']+)', \\[([A-Za-z]+Controller)::class, '([^']+)'\\]\\)/", $source, $matches, PREG_SET_ORDER);
$protected = ['StockController', 'StockReportsController', 'RecipesController', 'ChoresController', 'TasksController', 'StockApiController', 'RecipesApiController', 'ChoresApiController', 'TasksApiController', 'PrintApiController'];
foreach ($matches as [, $path, $controllerName, $method])
{
	if (!in_array($controllerName, $protected)) continue;
	$class = 'Victual\\Controllers\\' . (str_contains($controllerName, 'Api') ? 'Api\\' : '') . $controllerName;
	$controller = new $class($container);
	preg_match_all('/\{([^}]+)\}/', $path, $params);
	$args = array_fill_keys($params[1], 1);
	status(fn() => $controller->$method(request(), new Response(), $args), 403, "$controllerName::$method refuses no grants");
}
$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
foreach ($spec['components']['schemas']['ExposedEntity']['enum'] as $entity)
{
	check(array_key_exists($entity, EntityReadPolicy::PERMISSIONS), "$entity has an explicit read policy");
	if (EntityReadPolicy::PERMISSIONS[$entity] === null) continue;
	$api = new Victual\Controllers\Api\GenericEntityApiController($container);
	foreach (['GetObjects', 'GetObject', 'GetUserfields'] as $method)
	{
		status(fn() => $api->$method(request(), new Response(), ['entity' => $entity, 'objectId' => 1]), 403, "$entity/$method denied");
	}
}

// Each leaf independently permits the corresponding generic read, without writes.
$generic = new Victual\Controllers\Api\GenericEntityApiController($container);
foreach (['STOCK_VIEW' => 'products', 'SHOPPINGLIST_VIEW' => 'shopping_list', 'CHORES_VIEW' => 'chores', 'TASKS_VIEW' => 'tasks', 'RECIPES_VIEW' => 'recipes', 'MEALPLAN_VIEW' => 'meal_plan'] as $permission => $entity)
{
	grant([$permission]);
	status(fn() => $generic->GetObjects(request(), new Response(), ['entity' => $entity]), 200, "$permission allows $entity");
	check(count(User::ResolvedPermissionNames(9000)) === 1, "$permission confers no writes");
}

grant(['ADMIN']);
$assigned = [roleId('GUEST'), roleId('CHILD')];
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => $assigned]), new Response(), ['userId' => 9001]), 204, 'Assign two roles');
$response = $userApi->ListPermissions(request(), new Response(), ['userId' => 9001]);
$rows = json_decode((string)$response->getBody(), true);
$stock = array_values(array_filter($rows, fn($r) => $r['permission_name'] === 'STOCK_VIEW'))[0];
check($stock['has_permission'] === 1 && $stock['via_roles'] === 'CHILD,GUEST', 'Sorted role provenance on resolved endpoint');
check(!in_array('STOCK_PURCHASE', User::ResolvedPermissionNames(9001)), 'Child cannot purchase');
check(!in_array('RECIPES', User::ResolvedPermissionNames(9001)), 'Child cannot edit recipes');
$pdo->exec('INSERT INTO user_permissions(user_id, permission_id) VALUES (9001, ' . permissionId('STOCK_VIEW') . ')');
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => []]), new Response(), ['userId' => 9001]), 204, 'Remove roles');
check(User::ResolvedPermissionNames(9001) === ['STOCK_VIEW'], 'Overlapping direct grant survives removal');
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => [999999]]), new Response(), ['userId' => 9001]), 400, 'Unknown role refused');
status(fn() => $roleApi->SetPermissions(request('PUT', ['permissions' => [999999]]), new Response(), ['roleId' => roleId('CHILD')]), 400, 'Unknown permission refused');
foreach ([null, ['roles' => '1'], ['roles' => [true]], ['roles' => ['1']], ['roles' => [-1]]] as $body)
	status(fn() => $roleApi->SetUserRoles(request('PUT', $body), new Response(), ['userId' => 9001]), 400, 'Malformed roles refused');
status(fn() => $roleApi->DeleteRole(request('DELETE'), new Response(), ['roleId' => roleId('CHILD')]), 400, 'Built-in role cannot be deleted');
status(fn() => $roleApi->EditRole(request('PUT', ['name' => 'Kids', 'code' => 'KIDS']), new Response(), ['roleId' => roleId('CHILD')]), 400, 'Immutable code');
status(fn() => $roleApi->EditRole(request('PUT', ['name' => 'Kids']), new Response(), ['roleId' => roleId('CHILD')]), 204, 'Built-in display name can change');

// Validate the whole proposed grant, not only newly added leaves.
grant([]); $pdo->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . roleId('ADULT') . ')');
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => [roleId('ADMIN')]]), new Response(), ['userId' => 9001]), 403, 'Adult cannot assign Admin');
status(fn() => $roleApi->SetPermissions(request('PUT', ['permissions' => [permissionId('MASTER_DATA_EDIT')]]), new Response(), ['roleId' => roleId('CHILD')]), 403, 'Adult cannot widen Child');
grant(['USERS_EDIT', 'STOCK_VIEW']);
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => [roleId('ADMIN')]]), new Response(), ['userId' => 9001]), 403, 'Delegated editor cannot grant Admin');
status(fn() => $roleApi->SetUserRoles(request('PUT', ['roles' => []]), new Response(), ['userId' => 9002]), 403, 'Cannot strip stronger target');
status(fn() => $userApi->SetPermissions(request('PUT', ['permissions' => [permissionId('STOCK_VIEW')]]), new Response(), ['userId' => 9001]), 204, 'USERS_EDIT can grant held direct permission');
status(fn() => $userApi->SetPermissions(request('PUT', ['permissions' => [1]]), new Response(), ['userId' => 9001]), 403, 'Direct grant subset enforced');

// A role-only editor inherits exactly the user-picture ownership rule.
grant(['ADMIN']);
$response = $roleApi->CreateRole(request('POST', ['code' => 'EDITOR', 'name' => '<img src=x onerror=alert(1)>']), new Response(), []);
$editor = json_decode((string)$response->getBody(), true)['created_object_id'];
$roles->SetPermissions(request(), $editor, [permissionId('USERS_EDIT'), permissionId('USERS_EDIT_SELF')]);
grant([]); $pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES (9000, ' . $editor . ')');
$files = new Victual\Controllers\Api\FilesApiController($container);
status(fn() => $files->DeleteFile(request('DELETE'), new Response(), ['group' => 'userpictures', 'fileName' => base64_encode('protected.png')]), 403, 'Role-only editor cannot delete stronger user picture');

// Failure on the second insert must restore the entire previous bundle.
grant(['ADMIN']);
$before = $roles->GetPermissionIds($editor);
$pdo->exec("CREATE FUNCTION rbac_fail_grant() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.permission_id = " . permissionId('STOCK_VIEW') . " THEN RAISE EXCEPTION 'injected'; END IF; RETURN NEW; END \$\$;
CREATE TRIGGER rbac_fail_grant BEFORE INSERT ON role_permissions FOR EACH ROW EXECUTE FUNCTION rbac_fail_grant()");
status(fn() => $roleApi->SetPermissions(request('PUT', ['permissions' => [permissionId('USERS_READ'), permissionId('STOCK_VIEW')]]), new Response(), ['roleId' => $editor]), 400, 'Injected failure reported');
check($roles->GetPermissionIds($editor) === $before, 'Permission replacement rolled back');
$pdo->exec('DROP TRIGGER rbac_fail_grant ON role_permissions; DROP FUNCTION rbac_fail_grant()');
$new = UsersService::GetInstance()->CreateUser('rbac-new', null, null, 'fixture password');
check(User::ResolvedPermissionNames((int)$new->id) === [], 'New user does not receive upgrade backfill');

// The same seed used by the migration/importer backfills a pre-existing empty user,
// is idempotent, and preserves the household's edited role display names.
$pdo->beginTransaction();
$seed = file_get_contents(VICTUAL_ROOT_PATH . '/db/pgsql/roles-seed.sql');
$pdo->exec($seed);
$pdo->exec($seed);
check(count(User::ResolvedPermissionNames((int)$new->id)) === 6, 'Upgrade backfills a previously unprivileged user');
check($roles->RequireRole(roleId('CHILD'))->name === 'Kids', 'Reseeding preserves renamed built-in');
$pdo->rollBack();

// Compile and render the actual role UI; names must remain escaped text.
$controller = new Victual\Controllers\UsersController($container);
foreach (['RolesList' => [], 'RoleEditForm' => ['roleId' => $editor], 'PermissionList' => ['userId' => 9001], 'UsersList' => []] as $method => $args)
{
	$response = $controller->$method(request(), new Response(), $args);
	$html = (string)$response->getBody();
	check($response->getStatusCode() === 200 && strlen($html) > 100, "$method renders");
	check(!str_contains($html, '<img src=x onerror=alert(1)>'), "$method escapes role names");
}
foreach (['CHILD', 'ADMIN', 'UNKNOWN'] as $code)
{
	$process = proc_open([PHP_BINARY, __FILE__, $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	check(proc_close($process) === 0, "Default roles $code: $output $errors");
	echo $output;
}
echo "RBAC PASSED ($checks assertions)\n";
