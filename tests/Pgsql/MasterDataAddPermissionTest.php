<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 742, ADR-0043 decisions 2 and 7: the create gates of POST /api/objects/{entity}.
 *
 * Decision 2: products, product_barcodes, locations, quantity_units, product_groups and
 * shopping_locations are created under MASTER_DATA_ADD, which MASTER_DATA_EDIT and ADMIN
 * resolve through the permission tree. Edit and delete of those, creation of
 * quantity_unit_conversions and creation of every other editable entity stay with
 * MASTER_DATA_EDIT (or ADMIN, for userfields and userentities).
 *
 * Decision 7: chores are created and edited under CHORES, tasks under TASKS, by every
 * holder of the permission whoever created the row. Delete stays with MASTER_DATA_EDIT.
 * CHORES and TASKS are siblings of MASTER_DATA_EDIT under ADMIN (InitialDataSeeder
 * PERMISSION_HIERARCHY), so an account holding MASTER_DATA_EDIT without CHORES loses
 * chore and task create and edit; testMasterDataEditAloneNoLongerCreatesChoresOrTasks()
 * records that consequence, which the ADR does not spell out.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php), for the
 * reason MergeProductsPermissionTest gives: the authentication middleware define()s the
 * acting user.
 */
class MasterDataAddPermissionTest extends PgsqlSchemaTestCase
{
	/** The six entities decision 2 moves to MASTER_DATA_ADD. */
	private const ADD_ENTITIES = ['products', 'product_barcodes', 'locations', 'quantity_units', 'product_groups', 'shopping_locations'];

	/**
	 * Editable entities with a gate of their own, left out of the "everything else still needs
	 * MASTER_DATA_EDIT" sweep: the four domain gates AddObject already had (shopping lists,
	 * recipes, meal plan, equipment) and the two entities decision 7 moves to CHORES / TASKS.
	 */
	private const OWN_GATE_ENTITIES = [
		'shopping_list', 'shopping_lists', 'recipes', 'recipes_pos', 'recipes_nestings', 'meal_plan', 'equipment',
		'chores', 'tasks',
	];

	private static PDO $db;

	/** @var array<string,string> plaintext API keys by caller name */
	private static array $keys = [];

	private static int $locationId;
	private static int $unitId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		self::$locationId = self::insertRow('locations', ['name' => 'Create Gate Location']);
		self::$unitId = self::insertRow('quantity_units', ['name' => 'Create Gate Unit']);

		$callers = [
			9710 => ['add-only', ['MASTER_DATA_ADD'], []],
			9711 => ['edit-only', ['MASTER_DATA_EDIT'], []],
			9712 => ['admin', ['ADMIN'], []],
			9713 => ['chores', ['CHORES'], []],
			9714 => ['tasks', ['TASKS'], []],
			9715 => ['child', [], ['CHILD']],
			9716 => ['adult', [], ['ADULT']],
		];
		foreach ($callers as $id => [$name, $permissions, $roles])
		{
			self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)')->execute([$id, "create-gate-$name", 'fixture']);
			foreach ($permissions as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			foreach ($roles as $role)
			{
				self::$db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ? AND builtin = 1')->execute([$id, $role]);
			}
			self::$keys[$name] = self::issueKey($id);
		}
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path, string $caller, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$caller]]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}

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
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$response = json_decode($output, true);
		self::assertIsArray($response, 'The request helper answered: ' . $output . $errors);
		if (isset($response['body']) && is_string($response['body']))
		{
			$decoded = json_decode($response['body'], true);
			if (is_array($decoded))
			{
				$response['body'] = $decoded;
			}
		}

		return $response;
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$locationId,
			'qu_id_purchase' => self::$unitId,
			'qu_id_stock' => self::$unitId,
			'qu_id_consume' => self::$unitId,
			'qu_id_price' => self::$unitId,
		]);
	}

	/**
	 * A request body that creates one row of the entity, named $tag.
	 *
	 * @return array<string,mixed>
	 */
	private static function createBody(string $entity, string $tag): array
	{
		return match ($entity)
		{
			'products' => ['name' => $tag, 'location_id' => self::$locationId, 'qu_id_stock' => self::$unitId, 'qu_id_purchase' => self::$unitId, 'qu_id_consume' => self::$unitId, 'qu_id_price' => self::$unitId],
			'product_barcodes' => ['product_id' => self::insertProduct("$tag product"), 'barcode' => $tag],
			'chores' => ['name' => $tag, 'period_type' => 'manually'],
			default => ['name' => $tag],
		};
	}

	/** The column an entity's rows are told apart by in these tests. */
	private static function nameColumn(string $entity): string
	{
		return $entity === 'product_barcodes' ? 'barcode' : 'name';
	}

	private static function rowCount(string $entity, string $tag): int
	{
		$statement = self::$db->prepare("SELECT COUNT(*) FROM $entity WHERE " . self::nameColumn($entity) . ' = ?');
		$statement->execute([$tag]);

		return (int)$statement->fetchColumn();
	}

	/** Creates a row straight in the database, for the edit and delete refusals to target. */
	private static function seedRow(string $entity, string $tag): int
	{
		$body = self::createBody($entity, $tag);
		if ($entity === 'chores')
		{
			$body['period_type'] = 'manually';
		}

		return self::insertRow($entity, $body);
	}

	/** @param array{status:int, body:mixed} $response */
	private static function assertRefused(array $response, string $permission, string $context): void
	{
		self::assertSame(403, $response['status'], "$context: " . json_encode($response['body']));
		self::assertSame("Permission missing: $permission", $response['body']['error_message'] ?? null, $context);
	}

	/** @return list<string> editable, non-labelling entities that are neither add entities nor own-gate entities */
	private static function otherEditableEntities(): array
	{
		$schemas = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true)['components']['schemas'];
		$others = array_values(array_diff(
			$schemas['ExposedEntity']['enum'],
			$schemas['ExposedEntityNoEdit']['enum'],
			self::ADD_ENTITIES,
			self::OWN_GATE_ENTITIES
		));
		self::assertNotEmpty($others);
		foreach (['quantity_unit_conversions', 'batteries', 'task_categories', 'userfields', 'userentities', 'storage_classes'] as $expected)
		{
			self::assertContains($expected, $others, "Sanity: $expected is swept as an entity outside the MASTER_DATA_ADD set");
		}

		return $others;
	}

	// ------------------------------------------------------------------------------
	// Decision 2
	// ------------------------------------------------------------------------------

	public function testAddOnlyAccountCreatesEachOfTheSixEntities(): void
	{
		foreach (self::ADD_ENTITIES as $entity)
		{
			$tag = "add-only $entity";
			$response = self::send('POST', "/api/objects/$entity", 'add-only', self::createBody($entity, $tag));

			self::assertSame(200, $response['status'], "MASTER_DATA_ADD alone creates $entity: " . json_encode($response['body']));
			self::assertGreaterThan(0, (int)($response['body']['created_object_id'] ?? 0), "$entity answers created_object_id");
			self::assertSame(1, self::rowCount($entity, $tag), "The $entity row exists");
		}
	}

	public function testMasterDataEditOnlyAndAdminStillCreateTheSixEntities(): void
	{
		foreach (['edit-only', 'admin'] as $caller)
		{
			foreach (self::ADD_ENTITIES as $entity)
			{
				$tag = "$caller $entity";
				$response = self::send('POST', "/api/objects/$entity", $caller, self::createBody($entity, $tag));

				self::assertSame(200, $response['status'], "$caller creates $entity: " . json_encode($response['body']));
				self::assertSame(1, self::rowCount($entity, $tag), "The $entity row of $caller exists");
			}
		}
	}

	public function testAddOnlyAccountCannotEditOrDeleteTheSixEntities(): void
	{
		foreach (self::ADD_ENTITIES as $entity)
		{
			$tag = "keep $entity";
			$id = self::seedRow($entity, $tag);

			$edit = self::send('PUT', "/api/objects/$entity/$id", 'add-only', [self::nameColumn($entity) => "renamed $entity"]);
			self::assertRefused($edit, 'MASTER_DATA_EDIT', "MASTER_DATA_ADD alone must not edit $entity");

			$delete = self::send('DELETE', "/api/objects/$entity/$id", 'add-only');
			self::assertRefused($delete, 'MASTER_DATA_EDIT', "MASTER_DATA_ADD alone must not delete $entity");

			self::assertSame(1, self::rowCount($entity, $tag), "The $entity row is unchanged and still there");
		}
	}

	public function testAddOnlyAccountCannotCreateAConversion(): void
	{
		$productId = self::insertProduct('conversion target');
		$before = (int)self::$db->query('SELECT COUNT(*) FROM quantity_unit_conversions')->fetchColumn();
		$otherUnit = self::insertRow('quantity_units', ['name' => 'conversion other unit']);

		$response = self::send('POST', '/api/objects/quantity_unit_conversions', 'add-only', ['product_id' => $productId, 'from_qu_id' => $otherUnit, 'to_qu_id' => self::$unitId, 'factor' => 30]);

		self::assertRefused($response, 'MASTER_DATA_EDIT', 'Conversion creation stays with MASTER_DATA_EDIT');
		self::assertSame($before, (int)self::$db->query('SELECT COUNT(*) FROM quantity_unit_conversions')->fetchColumn(), 'No conversion row was written');
	}

	public function testAddOnlyAccountCannotCreateAnyOtherEditableEntity(): void
	{
		foreach (self::otherEditableEntities() as $entity)
		{
			$response = self::send('POST', "/api/objects/$entity", 'add-only', ['name' => "refused $entity"]);

			self::assertRefused($response, 'MASTER_DATA_EDIT', "MASTER_DATA_ADD alone must not create $entity");
		}
	}

	public function testMasterDataEditOnlyAndAdminReachTheBodyOfEveryOtherEntity(): void
	{
		// A permitted caller is not answered 403 by the gate. The body is empty on purpose, so
		// the answer is 400 or 200 depending on the entity, and the userfields / userentities
		// pair additionally needs ADMIN, which only the admin account holds.
		foreach (self::otherEditableEntities() as $entity)
		{
			$requiresAdmin = in_array($entity, ['userfields', 'userentities'], true);
			$editOnly = self::send('POST', "/api/objects/$entity", 'edit-only', ['name' => "edit-only $entity"]);
			if ($requiresAdmin)
			{
				self::assertRefused($editOnly, 'ADMIN', "MASTER_DATA_EDIT alone must not create $entity");
			}
			else
			{
				self::assertNotSame(403, $editOnly['status'], "MASTER_DATA_EDIT passes the gate for $entity: " . json_encode($editOnly['body']));
			}

			$admin = self::send('POST', "/api/objects/$entity", 'admin', ['name' => "admin $entity"]);
			self::assertNotSame(403, $admin['status'], "ADMIN passes the gate for $entity: " . json_encode($admin['body']));
		}
	}

	// ------------------------------------------------------------------------------
	// Decision 7
	// ------------------------------------------------------------------------------

	public function testChoresAndTasksAreCreatedAndEditedUnderTheirOwnPermission(): void
	{
		foreach (['chores' => ['chores', 'CHORES'], 'tasks' => ['tasks', 'TASKS']] as $caller => [$entity, $permission])
		{
			$tag = "$caller holder $entity";
			$create = self::send('POST', "/api/objects/$entity", $caller, self::createBody($entity, $tag));
			self::assertSame(200, $create['status'], "$permission without MASTER_DATA_EDIT creates $entity: " . json_encode($create['body']));
			$id = (int)$create['body']['created_object_id'];
			self::assertSame(1, self::rowCount($entity, $tag));

			// Edit is open to the permission holder whoever created the row (decision 7).
			$foreign = self::seedRow($entity, "$tag foreign");
			foreach ([$id, $foreign] as $target)
			{
				$edit = self::send('PUT', "/api/objects/$entity/$target", $caller, ['name' => "$tag edited $target"]);
				self::assertSame(204, $edit['status'], "$permission without MASTER_DATA_EDIT edits $entity $target: " . json_encode($edit['body']));
				self::assertSame(1, self::rowCount($entity, "$tag edited $target"));
			}

			$delete = self::send('DELETE', "/api/objects/$entity/$id", $caller);
			self::assertRefused($delete, 'MASTER_DATA_EDIT', "$permission alone must not delete $entity");
			self::assertSame(1, self::rowCount($entity, "$tag edited $id"), "The $entity row survived");
		}
	}

	public function testHoldingOnlyTheOtherDomainPermissionIsNotEnough(): void
	{
		self::assertRefused(self::send('POST', '/api/objects/chores', 'tasks', self::createBody('chores', 'tasks creates chore')), 'CHORES', 'TASKS must not create chores');
		self::assertRefused(self::send('POST', '/api/objects/tasks', 'chores', self::createBody('tasks', 'chores creates task')), 'TASKS', 'CHORES must not create tasks');
	}

	public function testTheAdultRoleCreatesAndEditsChoresAndTasks(): void
	{
		foreach (['chores', 'tasks'] as $entity)
		{
			$create = self::send('POST', "/api/objects/$entity", 'adult', self::createBody($entity, "adult $entity"));
			self::assertSame(200, $create['status'], "The Adult role creates $entity: " . json_encode($create['body']));
			$edit = self::send('PUT', "/api/objects/$entity/" . (int)$create['body']['created_object_id'], 'adult', ['name' => "adult edited $entity"]);
			self::assertSame(204, $edit['status'], "The Adult role edits $entity");
			self::assertRefused(self::send('DELETE', "/api/objects/$entity/" . (int)$create['body']['created_object_id'], 'adult'), 'MASTER_DATA_EDIT', "The Adult role must not delete $entity");
		}
	}

	public function testTheChildRoleCannotCreateOrEditChoresAndTasks(): void
	{
		foreach (['chores' => 'CHORES', 'tasks' => 'TASKS'] as $entity => $permission)
		{
			$tag = "child $entity";
			self::assertRefused(self::send('POST', "/api/objects/$entity", 'child', self::createBody($entity, $tag)), $permission, "The Child role must not create $entity");
			self::assertSame(0, self::rowCount($entity, $tag));

			$id = self::seedRow($entity, "$tag target");
			self::assertRefused(self::send('PUT', "/api/objects/$entity/$id", 'child', ['name' => "$tag edited"]), $permission, "The Child role must not edit $entity");
			self::assertSame(1, self::rowCount($entity, "$tag target"));
		}
	}

	public function testMasterDataEditAloneNoLongerCreatesChoresOrTasks(): void
	{
		// CHORES and TASKS are not below MASTER_DATA_EDIT, so decision 7 takes create and edit
		// away from an account that holds MASTER_DATA_EDIT without them. ADMIN resolves both.
		foreach (['chores' => 'CHORES', 'tasks' => 'TASKS'] as $entity => $permission)
		{
			self::assertRefused(self::send('POST', "/api/objects/$entity", 'edit-only', self::createBody($entity, "edit-only $entity")), $permission, "MASTER_DATA_EDIT alone must not create $entity");
			$id = self::seedRow($entity, "edit-only target $entity");
			self::assertRefused(self::send('PUT', "/api/objects/$entity/$id", 'edit-only', ['name' => 'x']), $permission, "MASTER_DATA_EDIT alone must not edit $entity");

			$admin = self::send('POST', "/api/objects/$entity", 'admin', self::createBody($entity, "admin $entity"));
			self::assertSame(200, $admin['status'], "ADMIN creates $entity");
			self::assertSame(204, self::send('PUT', "/api/objects/$entity/$id", 'admin', ['name' => "admin edited $entity"])['status'], "ADMIN edits $entity");
			self::assertSame(204, self::send('DELETE', "/api/objects/$entity/$id", 'admin')['status'], "ADMIN deletes $entity");
		}
	}

	public function testMasterDataEditStillDeletesChoresAndTasks(): void
	{
		foreach (['chores', 'tasks'] as $entity)
		{
			$id = self::seedRow($entity, "delete me $entity");
			self::assertSame(204, self::send('DELETE', "/api/objects/$entity/$id", 'edit-only')['status'], "MASTER_DATA_EDIT deletes $entity");
		}
	}
}
