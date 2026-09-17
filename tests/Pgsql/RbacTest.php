<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\FilesApiController;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\RolesApiController;
use Victual\Controllers\Api\UsersApiController;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Controllers\Users\User;
use Victual\Controllers\UsersController;
use Victual\Services\RolesService;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0025 spike 2: the largest bespoke phase (its own check()/status() helpers, session
 * and permission fixtures) ported to PHPUnit with the same assertions, run under
 * "run-tests.sh rbac". This is a port of .devtools/pgsql/rbac-tests.php, not a rewrite
 * into independent unit tests: the original script is one long scenario against a
 * database it mutates as it goes (a role assigned in one section is read back in the
 * next, a user created in one section is reseeded in a later one), and that is also what
 * a real household's history looks like - so these test methods keep that shape, run in
 * their declared order (PHPUnit's default, unchanged here) and share state through
 * private static properties the way the original shared it through local variables.
 * Five scenarios (the three default-role cases and the two own-picture exceptions)
 * still run as their own process for the reason given in
 * tests/Pgsql/rbac-subprocess-helper.php's header, exactly as they did before the port.
 */
class RbacTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static \DI\Container $container;
	private static RolesApiController $roleApi;
	private static UsersApiController $userApi;
	private static FilesApiController $files;
	private static RolesService $roles;
	private static int $editor;
	private static int $newUserId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$roles = RolesService::GetInstance();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$roleApi = new RolesApiController(self::$container);
		self::$userApi = new UsersApiController(self::$container);
		self::$files = new FilesApiController(self::$container);
	}

	private static function request(string $method = 'GET', $body = null)
	{
		return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')->withParsedBody($body);
	}

	private function expectStatus(callable $work, int $expected, string $message)
	{
		try { $response = $work(); $actual = $response->getStatusCode(); }
		catch (HttpException $e) { $actual = $e->getCode(); $response = null; }
		self::assertSame($expected, $actual, "$message: expected $expected, got $actual " . ($response === null ? '' : (string)$response->getBody()));
		return $response;
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$stmt = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');
		foreach ($names as $name) $stmt->execute([$name]);
	}

	private static function roleId(string $code): int
	{
		$stmt = self::$db->prepare('SELECT id FROM roles WHERE code = ?'); $stmt->execute([$code]);
		return (int)$stmt->fetchColumn();
	}

	private static function permissionId(string $name): int
	{
		$stmt = self::$db->prepare('SELECT id FROM permission_hierarchy WHERE name = ?'); $stmt->execute([$name]);
		return (int)$stmt->fetchColumn();
	}

	/** Runs the given rbac-subprocess-helper.php scenario against this class's own schema. */
	private static function runSubprocess(array $args): array
	{
		// $_SERVER carries non-scalar entries (argv among them) that proc_open's env
		// conversion cannot stringify, so only scalar inherited values are passed through.
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open(
			array_merge([PHP_BINARY, __DIR__ . '/rbac-subprocess-helper.php'], $args),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		return [$exit, $output, $errors];
	}

	public function testFreshDatabaseState(): void
	{
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM user_roles')->fetchColumn(), 'Fresh user_roles is empty');
		self::assertSame(4, (int)self::$db->query('SELECT COUNT(*) FROM roles WHERE builtin = 1')->fetchColumn(), 'Four built-in roles');
		self::assertSame(6, (int)self::$db->query("SELECT COUNT(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id=up.permission_id WHERE up.user_id=1 AND p.name LIKE '%_VIEW'")->fetchColumn(), 'Existing admin receives all six backfill leaves');
	}

	public function testSeedFixtureUsers(): void
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'rbac-caller', 'fixture'), (9001, 'rbac-target', 'fixture'), (9002, 'rbac-admin', 'fixture')");
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9002, ' . self::roleId('ADMIN') . ')');
		self::$db->exec("UPDATE users SET picture_file_name='protected.png' WHERE id=9002");
		self::assertSame(3, (int)self::$db->query('SELECT COUNT(*) FROM users WHERE id IN (9000, 9001, 9002)')->fetchColumn(), 'Fixture users seeded');
	}

	#[Depends('testSeedFixtureUsers')]
	public function testRoutedReadsRefuseWithoutGrants(): void
	{
		self::grant([]);
		$source = file_get_contents(VICTUAL_ROOT_PATH . '/routes.php');
		preg_match_all("/\\\$group->get\\('([^']+)', \\[([A-Za-z]+Controller)::class, '([^']+)'\\]\\)/", $source, $matches, PREG_SET_ORDER);
		$protected = ['StockController', 'StockReportsController', 'RecipesController', 'ChoresController', 'TasksController', 'StockApiController', 'RecipesApiController', 'ChoresApiController', 'TasksApiController', 'PrintApiController'];
		foreach ($matches as [, $path, $controllerName, $method])
		{
			if (!in_array($controllerName, $protected)) continue;
			$class = 'Victual\\Controllers\\' . (str_contains($controllerName, 'Api') ? 'Api\\' : '') . $controllerName;
			$controller = new $class(self::$container);
			preg_match_all('/\{([^}]+)\}/', $path, $params);
			$args = array_fill_keys($params[1], 1);
			$this->expectStatus(fn() => $controller->$method(self::request(), new Response(), $args), 403, "$controllerName::$method refuses no grants");
		}
	}

	#[Depends('testRoutedReadsRefuseWithoutGrants')]
	public function testExposedEntityReadPoliciesRequireGrant(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		foreach ($spec['components']['schemas']['ExposedEntity']['enum'] as $entity)
		{
			self::assertArrayHasKey($entity, EntityReadPolicy::PERMISSIONS, "$entity has an explicit read policy");
			if (EntityReadPolicy::PERMISSIONS[$entity] === null) continue;
			$api = new GenericEntityApiController(self::$container);
			foreach (['GetObjects', 'GetObject', 'GetUserfields'] as $method)
			{
				$this->expectStatus(fn() => $api->$method(self::request(), new Response(), ['entity' => $entity, 'objectId' => 1]), 403, "$entity/$method denied");
			}
		}
	}

	#[Depends('testExposedEntityReadPoliciesRequireGrant')]
	public function testFileGroupReadPolicies(): void
	{
		// Sweep S32: FilesApiController's own fail-closed read-permission table, checked the
		// same way as ExposedEntity/EntityReadPolicy above - every FileGroups enum member has
		// an explicit row, and a group without one is refused rather than served.
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		foreach ($spec['components']['schemas']['FileGroups']['enum'] as $group)
		{
			self::assertArrayHasKey($group, FilesApiController::GROUP_READ_PERMISSIONS, "$group has an explicit read policy");
		}

		self::grant([]);
		foreach (FilesApiController::GROUP_READ_PERMISSIONS as $group => $permission)
		{
			$args = ['group' => $group, 'fileName' => base64_encode('nonexistent.png')];
			if ($permission === null)
			{
				$this->expectStatus(fn() => self::$files->ServeFile(self::request(), new Response(), $args), 404, "$group is deliberately open and falls through to the file lookup");
			}
			else
			{
				$this->expectStatus(fn() => self::$files->ServeFile(self::request(), new Response(), $args), 403, "$group denied without $permission");
				self::grant([$permission]);
				$this->expectStatus(fn() => self::$files->ServeFile(self::request(), new Response(), $args), 404, "$group allowed with $permission, falls through to the file lookup");
				self::grant([]);
			}
		}

		// The property the finding actually asks for: a group with no row at all, not merely
		// one of the enum's own mapped-but-unpermitted members, is refused rather than served.
		$this->expectStatus(fn() => self::$files->ServeFile(self::request(), new Response(), ['group' => 'unmapped-file-group', 'fileName' => base64_encode('nonexistent.png')]), 400, 'A file group with no row in GROUP_READ_PERMISSIONS is refused, not served');
		$this->expectStatus(fn() => self::$files->DeleteFile(self::request(), new Response(), ['group' => 'unmapped-file-group', 'fileName' => base64_encode('nonexistent.png')]), 400, 'DeleteFile refuses the same unmapped group');
		$this->expectStatus(fn() => self::$files->UploadFile(self::request(), new Response(), ['group' => 'unmapped-file-group', 'fileName' => base64_encode('nonexistent.png')]), 400, 'UploadFile refuses the same unmapped group');
	}

	#[Depends('testFileGroupReadPolicies')]
	public function testGenericLeafPermissionsGrantReadOnly(): void
	{
		$generic = new GenericEntityApiController(self::$container);
		foreach (['STOCK_VIEW' => 'products', 'SHOPPINGLIST_VIEW' => 'shopping_list', 'CHORES_VIEW' => 'chores', 'TASKS_VIEW' => 'tasks', 'RECIPES_VIEW' => 'recipes', 'MEALPLAN_VIEW' => 'meal_plan'] as $permission => $entity)
		{
			self::grant([$permission]);
			$this->expectStatus(fn() => $generic->GetObjects(self::request(), new Response(), ['entity' => $entity]), 200, "$permission allows $entity");
			self::assertCount(1, User::ResolvedPermissionNames(9000), "$permission confers no writes");
		}
	}

	#[Depends('testGenericLeafPermissionsGrantReadOnly')]
	public function testRoleAssignmentAndPermissionResolution(): void
	{
		self::grant(['ADMIN']);
		$assigned = [self::roleId('GUEST'), self::roleId('CHILD')];
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => $assigned]), new Response(), ['userId' => 9001]), 204, 'Assign two roles');
		$response = self::$userApi->ListPermissions(self::request(), new Response(), ['userId' => 9001]);
		$rows = json_decode((string)$response->getBody(), true);
		$stock = array_values(array_filter($rows, fn($r) => $r['permission_name'] === 'STOCK_VIEW'))[0];
		self::assertTrue($stock['has_permission'] === 1 && $stock['via_roles'] === 'CHILD,GUEST', 'Sorted role provenance on resolved endpoint');
		self::assertNotContains('STOCK_PURCHASE', User::ResolvedPermissionNames(9001), 'Child cannot purchase');
		self::assertNotContains('RECIPES', User::ResolvedPermissionNames(9001), 'Child cannot edit recipes');
		self::$db->exec('INSERT INTO user_permissions(user_id, permission_id) VALUES (9001, ' . self::permissionId('STOCK_VIEW') . ')');
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => []]), new Response(), ['userId' => 9001]), 204, 'Remove roles');
		self::assertSame(['STOCK_VIEW'], User::ResolvedPermissionNames(9001), 'Overlapping direct grant survives removal');
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => [999999]]), new Response(), ['userId' => 9001]), 400, 'Unknown role refused');
		$this->expectStatus(fn() => self::$roleApi->SetPermissions(self::request('PUT', ['permissions' => [999999]]), new Response(), ['roleId' => self::roleId('CHILD')]), 400, 'Unknown permission refused');
		foreach ([null, ['roles' => '1'], ['roles' => [true]], ['roles' => ['1']], ['roles' => [-1]]] as $body)
			$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', $body), new Response(), ['userId' => 9001]), 400, 'Malformed roles refused');
		$this->expectStatus(fn() => self::$roleApi->DeleteRole(self::request('DELETE'), new Response(), ['roleId' => self::roleId('CHILD')]), 400, 'Built-in role cannot be deleted');
		$this->expectStatus(fn() => self::$roleApi->EditRole(self::request('PUT', ['name' => 'Kids', 'code' => 'KIDS']), new Response(), ['roleId' => self::roleId('CHILD')]), 400, 'Immutable code');
		$this->expectStatus(fn() => self::$roleApi->EditRole(self::request('PUT', ['name' => 'Kids']), new Response(), ['roleId' => self::roleId('CHILD')]), 204, 'Built-in display name can change');
	}

	#[Depends('testRoleAssignmentAndPermissionResolution')]
	public function testPrivilegeEscalationRefusedForWeakerGrants(): void
	{
		// Validate the whole proposed grant, not only newly added leaves.
		self::grant([]); self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9000, ' . self::roleId('ADULT') . ')');
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => [self::roleId('ADMIN')]]), new Response(), ['userId' => 9001]), 403, 'Adult cannot assign Admin');
		$this->expectStatus(fn() => self::$roleApi->SetPermissions(self::request('PUT', ['permissions' => [self::permissionId('MASTER_DATA_EDIT')]]), new Response(), ['roleId' => self::roleId('CHILD')]), 403, 'Adult cannot widen Child');
		self::grant(['USERS_EDIT', 'STOCK_VIEW']);
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => [self::roleId('ADMIN')]]), new Response(), ['userId' => 9001]), 403, 'Delegated editor cannot grant Admin');
		$this->expectStatus(fn() => self::$roleApi->SetUserRoles(self::request('PUT', ['roles' => []]), new Response(), ['userId' => 9002]), 403, 'Cannot strip stronger target');
		$this->expectStatus(fn() => self::$userApi->SetPermissions(self::request('PUT', ['permissions' => [self::permissionId('STOCK_VIEW')]]), new Response(), ['userId' => 9001]), 204, 'USERS_EDIT can grant held direct permission');
		$this->expectStatus(fn() => self::$userApi->SetPermissions(self::request('PUT', ['permissions' => [1]]), new Response(), ['userId' => 9001]), 403, 'Direct grant subset enforced');
	}

	#[Depends('testPrivilegeEscalationRefusedForWeakerGrants')]
	public function testRoleOnlyEditorInheritsPictureOwnershipRule(): void
	{
		self::grant(['ADMIN']);
		$response = self::$roleApi->CreateRole(self::request('POST', ['code' => 'EDITOR', 'name' => '<img src=x onerror=alert(1)>']), new Response(), []);
		self::$editor = json_decode((string)$response->getBody(), true)['created_object_id'];
		self::$roles->SetPermissions(self::request(), self::$editor, [self::permissionId('USERS_EDIT'), self::permissionId('USERS_EDIT_SELF')]);
		self::grant([]); self::$db->exec('INSERT INTO user_roles(user_id,role_id) VALUES (9000, ' . self::$editor . ')');
		$this->expectStatus(fn() => self::$files->DeleteFile(self::request('DELETE'), new Response(), ['group' => 'userpictures', 'fileName' => base64_encode('protected.png')]), 403, 'Role-only editor cannot delete stronger user picture');
	}

	#[Depends('testRoleOnlyEditorInheritsPictureOwnershipRule')]
	public function testOwnPictureReadException(): void
	{
		// Issue #177: CheckGroupReadPermission's own-picture exception used to key on
		// VICTUAL_USER_PICTURE_FILE_NAME alone, and USERS_EDIT_SELF (which the Child role
		// holds) lets a caller's own users row claim any name via PUT /api/users/{self} - so
		// a caller who learned rbac-admin's real picture name could set it as their own and
		// read it back with no USERS_READ. Both branches need a caller whose id and claimed
		// picture name are fixed for a whole process, so each runs in its own subprocess.
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9003, 'rbac-picture-caller', 'fixture', 'caller-own.png')");
		// picture_file_name carries no uniqueness constraint, so a naive "fetch one matching
		// row and compare its id" is only as safe as fetch()'s row order - undefined once two
		// real users share a filename. This caller is inserted before the user whose picture
		// it does not own, the ordering a plain fetch() is likeliest to return first.
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9004, 'rbac-picture-caller-dup', 'fixture', 'shared.png')");
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9005, 'rbac-picture-other-dup', 'fixture', 'shared.png')");
		foreach ([
			['9003', 'caller-own.png', '404', 'No other user claims the caller\'s own picture name, so it still falls through to the file lookup'],
			['9003', 'protected.png', '403', 'The caller\'s row claims rbac-admin\'s real picture name, and USERS_READ was never granted - the loosening no longer applies'],
			['9004', 'shared.png', '403', 'A second real user also owns the exact same picture_file_name - the caller\'s own matching row does not excuse the other owner'],
		] as [$callerId, $claimedPictureFileName, $expectedStatus, $message])
		{
			[$exit, $output, $errors] = self::runSubprocess(['OWNPICTURE', $callerId, $claimedPictureFileName, $expectedStatus, $message]);
			self::assertSame(0, $exit, "Own-picture exception ($callerId, $claimedPictureFileName): $output $errors");
		}
	}

	#[Depends('testOwnPictureReadException')]
	public function testOwnPictureDeleteException(): void
	{
		// Issue #177's delete-path counterpart: CheckUserPictureDeletion's own-picture early
		// return had exactly the same gap - USERS_EDIT_SELF (Child) is enough to claim
		// rbac-admin's real picture name as one's own and have DeleteFile take it out with no
		// USERS_EDIT/administer check at all.
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9006, 'rbac-picture-delete-caller', 'fixture', 'delete-caller-own.png')");
		self::$db->exec('INSERT INTO user_permissions(user_id, permission_id) VALUES (9006, ' . self::permissionId('USERS_EDIT_SELF') . ')');
		foreach ([
			['delete-caller-own.png', '204', 'No other user claims the caller\'s own picture name, so USERS_EDIT_SELF alone deletes it'],
			['protected.png', '403', 'The caller\'s row claims rbac-admin\'s real picture name, and USERS_EDIT was never granted - the loosening no longer applies'],
		] as [$claimedPictureFileName, $expectedStatus, $message])
		{
			[$exit, $output, $errors] = self::runSubprocess(['OWNPICTUREDELETE', '9006', $claimedPictureFileName, $expectedStatus, $message]);
			self::assertSame(0, $exit, "Own-picture deletion exception ($claimedPictureFileName): $output $errors");
		}

		// The administer check must weigh the actual other owner, not an arbitrary row a
		// second unconstrained fetch() happens to return. This caller holds both USERS_EDIT
		// and USERS_EDIT_SELF - strong enough to pass CheckPermission - and genuinely shares
		// one filename with an ADMIN user it cannot administer.
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9007, 'rbac-picture-delete-caller-strong', 'fixture', 'strongshared.png')");
		self::$db->exec('INSERT INTO user_permissions(user_id, permission_id) VALUES (9007, ' . self::permissionId('USERS_EDIT') . '), (9007, ' . self::permissionId('USERS_EDIT_SELF') . ')');
		self::$db->exec("INSERT INTO users(id, username, password, picture_file_name) VALUES (9008, 'rbac-picture-delete-target-strong', 'fixture', 'strongshared.png')");
		self::$db->exec('INSERT INTO user_roles(user_id, role_id) VALUES (9008, ' . self::roleId('ADMIN') . ')');
		[$exit, $output, $errors] = self::runSubprocess(['OWNPICTUREDELETE', '9007', 'strongshared.png', '403', 'USERS_EDIT does not excuse administering an ADMIN who shares the filename']);
		self::assertSame(0, $exit, "Own-picture deletion exception (strongshared.png, stronger duplicate owner): $output $errors");
	}

	#[Depends('testOwnPictureDeleteException')]
	public function testFailedPermissionReplacementRollsBack(): void
	{
		self::grant(['ADMIN']);
		$before = self::$roles->GetPermissionIds(self::$editor);
		self::$db->exec("CREATE FUNCTION rbac_fail_grant() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.permission_id = " . self::permissionId('STOCK_VIEW') . " THEN RAISE EXCEPTION 'injected'; END IF; RETURN NEW; END \$\$;
CREATE TRIGGER rbac_fail_grant BEFORE INSERT ON role_permissions FOR EACH ROW EXECUTE FUNCTION rbac_fail_grant()");
		$this->expectStatus(fn() => self::$roleApi->SetPermissions(self::request('PUT', ['permissions' => [self::permissionId('USERS_READ'), self::permissionId('STOCK_VIEW')]]), new Response(), ['roleId' => self::$editor]), 400, 'Injected failure reported');
		self::assertSame($before, self::$roles->GetPermissionIds(self::$editor), 'Permission replacement rolled back');
		self::$db->exec('DROP TRIGGER rbac_fail_grant ON role_permissions; DROP FUNCTION rbac_fail_grant()');
		$new = UsersService::GetInstance()->CreateUser('rbac-new', null, null, 'fixture password');
		self::$newUserId = (int)$new->id;
		self::assertSame([], User::ResolvedPermissionNames(self::$newUserId), 'New user does not receive upgrade backfill');
	}

	#[Depends('testFailedPermissionReplacementRollsBack')]
	public function testSeedIsIdempotentAndPreservesRenamedBuiltins(): void
	{
		// The same seed used by the migration/importer backfills a pre-existing empty user,
		// is idempotent, and preserves the household's edited role display names.
		self::$db->beginTransaction();
		$seed = file_get_contents(VICTUAL_ROOT_PATH . '/db/pgsql/roles-seed.sql');
		self::$db->exec($seed);
		self::$db->exec($seed);
		self::assertCount(6, User::ResolvedPermissionNames(self::$newUserId), 'Upgrade backfills a previously unprivileged user');
		self::assertSame('Kids', self::$roles->RequireRole(self::roleId('CHILD'))->name, 'Reseeding preserves renamed built-in');
		self::$db->rollBack();
	}

	#[Depends('testSeedIsIdempotentAndPreservesRenamedBuiltins')]
	public function testRoleUiRendersAndEscapesNames(): void
	{
		$controller = new UsersController(self::$container);
		foreach (['RolesList' => [], 'RoleEditForm' => ['roleId' => self::$editor], 'PermissionList' => ['userId' => 9001], 'UsersList' => []] as $method => $args)
		{
			$response = $controller->$method(self::request(), new Response(), $args);
			$html = (string)$response->getBody();
			self::assertTrue($response->getStatusCode() === 200 && strlen($html) > 100, "$method renders");
			self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html, "$method escapes role names");
		}
	}

	#[Depends('testRoleUiRendersAndEscapesNames')]
	public function testDefaultRoleChild(): void
	{
		[$exit, $output, $errors] = self::runSubprocess(['CHILD']);
		self::assertSame(0, $exit, "Default roles CHILD: $output $errors");
	}

	#[Depends('testDefaultRoleChild')]
	public function testDefaultRoleAdmin(): void
	{
		[$exit, $output, $errors] = self::runSubprocess(['ADMIN']);
		self::assertSame(0, $exit, "Default roles ADMIN: $output $errors");
	}

	#[Depends('testDefaultRoleAdmin')]
	public function testDefaultRoleUnknown(): void
	{
		[$exit, $output, $errors] = self::runSubprocess(['UNKNOWN']);
		self::assertSame(0, $exit, "Default roles UNKNOWN: $output $errors");
	}
}
