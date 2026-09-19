<?php

namespace Victual\Services\Database;

use Victual\Services\LocalizationService;

/**
 * The rows a brand new Victual database needs before anyone can log into it.
 *
 * On SQLite these arrive as a side effect of replaying the migration history: 0027.php
 * creates the admin user (with the fixed admin/admin credential this class no longer
 * uses - see SeedAdminUser()), 0031.php the default quantity units and location, 0062/0063
 * the default shopping list, 0110.sql the permission hierarchy, 0149.sql the internal
 * meal plan section. An engine that starts from a squashed baseline never runs any of
 * those, and the baseline is schema only, so without this class a freshly migrated
 * PostgreSQL database has no admin user, no permissions and no quantity units - it
 * migrates successfully and is unusable.
 *
 * This is therefore the data half of the baseline, and it belongs to the same moment:
 * DatabaseMigrationService runs it once, in the transaction that loads the baseline,
 * so "the state SQLite reaches after migrations 0001-0255" is what the other engine
 * actually reaches too.
 *
 * It is deliberately not idempotent and deliberately not re-runnable. Guarding each
 * insert with an existence check would quietly re-create rows a user had deleted on
 * purpose, which is a different feature. Running once against a database that was
 * empty a statement ago needs no guard.
 */
class InitialDataSeeder
{
	/**
	 * The permission hierarchy, in the order migrations/0110.sql inserts it - which is
	 * what gives each permission its id, and ADMIN id 1. Second element is the parent's
	 * name, or null for the root.
	 *
	 * The USERS -> USERS_CREATE -> USERS_EDIT -> USERS_READ chain is not a typo: 0110.sql
	 * parents the last two with last_insert_rowid() rather than a name lookup, so they
	 * nest inside each other rather than all hanging off USERS. Reproduced as it is,
	 * because permission_tree resolves what a user may do from these rows.
	 */
	const PERMISSION_HIERARCHY = [
		['ADMIN', null],
		['USERS', 'ADMIN'],
		['USERS_CREATE', 'USERS'],
		['USERS_EDIT', 'USERS_CREATE'],
		['USERS_READ', 'USERS_EDIT'],
		['USERS_EDIT_SELF', 'ADMIN'],
		['STOCK', 'ADMIN'],
		['SHOPPINGLIST', 'ADMIN'],
		['RECIPES', 'ADMIN'],
		['CHORES', 'ADMIN'],
		['BATTERIES', 'ADMIN'],
		['TASKS', 'ADMIN'],
		['EQUIPMENT', 'ADMIN'],
		['CALENDAR', 'ADMIN'],
		['STOCK_PURCHASE', 'STOCK'],
		['STOCK_CONSUME', 'STOCK'],
		['STOCK_INVENTORY', 'STOCK'],
		['STOCK_TRANSFER', 'STOCK'],
		['STOCK_OPEN', 'STOCK'],
		['STOCK_EDIT', 'STOCK'],
		['SHOPPINGLIST_ITEMS_ADD', 'SHOPPINGLIST'],
		['SHOPPINGLIST_ITEMS_DELETE', 'SHOPPINGLIST'],
		['RECIPES_MEALPLAN', 'RECIPES'],
		['CHORE_TRACK_EXECUTION', 'CHORES'],
		['CHORE_UNDO_EXECUTION', 'CHORES'],
		['BATTERIES_TRACK_CHARGE_CYCLE', 'BATTERIES'],
		['BATTERIES_UNDO_CHARGE_CYCLE', 'BATTERIES'],
		['TASKS_UNDO_EXECUTION', 'TASKS'],
		['TASKS_MARK_COMPLETED', 'TASKS'],
		['MASTER_DATA_EDIT', 'ADMIN'],
	];

	/**
	 * The id of the internal meal plan section. Negative on purpose and load bearing:
	 * meal_plan.section_id defaults to it and a trigger refuses to let it be deleted.
	 */
	const INTERNAL_MEAL_PLAN_SECTION_ID = -1;

	/**
	 * Ids that are an accident of the migration history rather than a choice, and which
	 * are reproduced anyway.
	 *
	 * 0006.sql seeds a placeholder location and quantity unit, 0021.sql deletes them
	 * again, and 0031.php then inserts the real defaults - by which point AUTOINCREMENT
	 * has moved on, so "Fridge" is location 2 and the quantity units are 2 and 3, with
	 * nothing at id 1 on either table.
	 *
	 * That gap matters. migrations/8888.php runs on every migration and creates a
	 * location with the literal id 1 when FEATURE_FLAG_STOCK_LOCATION_TRACKING is off,
	 * because code in that mode assumes one exists. Let PostgreSQL number "Fridge" 1
	 * instead and 8888.php finds id 1 already taken, so the two engines end up with
	 * different locations for the same configuration.
	 */
	const DEFAULT_LOCATION_ID = 2;
	const DEFAULT_QUANTITY_UNIT_ID = 2;
	const DEFAULT_PACK_QUANTITY_UNIT_ID = 3;

	private $Db;
	private $Dialect;
	private $LocalizationService;

	/**
	 * @param \PDO $db An already migrated, empty database
	 * @param DatabaseDialect $dialect Dialect matching $db (quoting, id counter resync)
	 * @param LocalizationService|null $localizationService Supplies the translated default
	 *                                                      names; defaults to VICTUAL_DEFAULT_LOCALE
	 */
	public function __construct(\PDO $db, DatabaseDialect $dialect, ?LocalizationService $localizationService = null)
	{
		$this->Db = $db;
		$this->Dialect = $dialect;
		$this->LocalizationService = $localizationService ?? LocalizationService::GetInstance(VICTUAL_DEFAULT_LOCALE);
	}

	/**
	 * Writes the initial data, in the order the migration history writes it, and leaves
	 * the generated id counters where that history leaves them.
	 */
	public function Seed(): void
	{
		$this->SeedAdminUser();
		$this->SeedQuantityUnits();
		$this->SeedDefaultLocation();
		$this->SeedDefaultShoppingList();
		$this->SeedPermissionHierarchy();
		$this->SeedInternalMealPlanSection();

		// Several of the rows above carry an explicit id, which leaves an identity
		// sequence sitting behind its own table - the next application insert would then
		// collide with a row that is already there.
		$this->Dialect->ResyncGeneratedIdCounters($this->Db);
	}

	/**
	 * The environment variable an operator sets to choose the first administrator's
	 * password. Read here, once, when the account is created, and nowhere else.
	 *
	 * An environment variable read directly rather than a Setting(): Setting() defines a
	 * VICTUAL_* constant every request can see, and a password has no business being one.
	 * It belongs in the migrate container's Secret only, which is the one workload that
	 * seeds; the serving containers never hold it.
	 */
	const BOOTSTRAP_PASSWORD_ENV = 'VICTUAL_BOOTSTRAP_ADMIN_PASSWORD';

	/** @var string|null The password SeedAdminUser() generated, when it had to generate one */
	private $GeneratedAdminPassword = null;

	/** @var string The user name SeedAdminUser() created */
	private $AdminUsername = 'admin';

	/**
	 * The first administrator account. Its password is, in order:
	 *
	 *   1. the pre-0027 config file settings, when those are still present (mirrors
	 *      migrations/0027.php);
	 *   2. VICTUAL_BOOTSTRAP_ADMIN_PASSWORD, when the operator set it;
	 *   3. otherwise 24 random hex characters, which the caller reports once, through
	 *      GetGeneratedAdminPassword(), and flags for a forced change.
	 *
	 * It used to be "admin" / "admin" - migration 0027's credential, publicly known -
	 * which meant anybody who reached a new deployment before its operator did could log in
	 * and, because the forced password change covers rendered pages only, use the whole
	 * API. There is no fixed password left on this path for anybody to know.
	 *
	 * Hashed per install either way, rather than shipping a fixed hash.
	 */
	private function SeedAdminUser(): void
	{
		if (defined('VICTUAL_HTTP_USER'))
		{
			$username = VICTUAL_HTTP_USER;
			$password = VICTUAL_HTTP_PASSWORD;
		}
		else
		{
			$username = 'admin';
			$password = self::BootstrapPasswordFromEnvironment();

			if ($password === null)
			{
				$password = bin2hex(random_bytes(12));
				$this->GeneratedAdminPassword = $password;
			}
		}

		$this->AdminUsername = $username;

		$this->Insert('users', [
			'username' => $username,
			'password' => password_hash($password, PASSWORD_ARGON2ID)
		]);
	}

	/**
	 * VICTUAL_BOOTSTRAP_ADMIN_PASSWORD, or null when it is unset or blank. Blank counts as
	 * unset rather than as a password: an empty Secret key is a mistake, not a choice.
	 */
	public static function BootstrapPasswordFromEnvironment(): ?string
	{
		$value = getenv(self::BOOTSTRAP_PASSWORD_ENV);

		if ($value === false || trim($value) === '')
		{
			return null;
		}

		return $value;
	}

	/**
	 * The administrator password Seed() generated, or null when it did not generate one
	 * (the operator supplied it, or Seed() has not run). The only copy there is: the
	 * database holds the hash.
	 */
	public function GetGeneratedAdminPassword(): ?string
	{
		return $this->GeneratedAdminPassword;
	}

	/**
	 * The user name of the account Seed() created.
	 */
	public function GetAdminUsername(): string
	{
		return $this->AdminUsername;
	}

	/**
	 * "Piece" and "Pack", translated into the configured locale. Mirrors the quantity
	 * unit half of migrations/0031.php.
	 */
	private function SeedQuantityUnits(): void
	{
		$this->Insert('quantity_units', [
			'id' => self::DEFAULT_QUANTITY_UNIT_ID,
			'name' => $this->LocalizationService->__n(1, 'Piece', 'Pieces'),
			'name_plural' => $this->LocalizationService->__n(2, 'Piece', 'Pieces')
		]);

		$this->Insert('quantity_units', [
			'id' => self::DEFAULT_PACK_QUANTITY_UNIT_ID,
			'name' => $this->LocalizationService->__n(1, 'Pack', 'Packs'),
			'name_plural' => $this->LocalizationService->__n(2, 'Pack', 'Packs')
		]);
	}

	/**
	 * "Fridge", translated. Mirrors the location half of migrations/0031.php.
	 */
	private function SeedDefaultLocation(): void
	{
		$this->Insert('locations', [
			'id' => self::DEFAULT_LOCATION_ID,
			'name' => $this->LocalizationService->__t('Fridge')
		]);
	}

	/**
	 * The shopping list every list item defaults to. migrations/0062.sql creates it as
	 * "Default" and 0063.php immediately renames it to the translated "Shopping list";
	 * only the end state is interesting here. Its id is what shopping_list.shopping_list_id
	 * defaults to, so it has to be 1.
	 */
	private function SeedDefaultShoppingList(): void
	{
		$this->Insert('shopping_lists', [
			'id' => 1,
			'name' => $this->LocalizationService->__t('Shopping list')
		]);
	}

	/**
	 * The permission tree, plus ADMIN for every user that exists - which at this point is
	 * the one seeded above. Mirrors migrations/0110.sql.
	 */
	private function SeedPermissionHierarchy(): void
	{
		$idsByName = [];

		foreach (self::PERMISSION_HIERARCHY as [$name, $parentName])
		{
			$this->Insert('permission_hierarchy', [
				'id' => count($idsByName) + 1,
				'name' => $name,
				'parent' => $parentName === null ? null : $idsByName[$parentName]
			]);

			$idsByName[$name] = count($idsByName) + 1;
		}

		$this->Db->exec('INSERT INTO ' . $this->Dialect->QuoteIdentifier('user_permissions')
			. ' (' . $this->Dialect->QuoteIdentifier('permission_id') . ', ' . $this->Dialect->QuoteIdentifier('user_id') . ') '
			. 'SELECT ' . $idsByName['ADMIN'] . ', ' . $this->Dialect->QuoteIdentifier('id')
			. ' FROM ' . $this->Dialect->QuoteIdentifier('users'));
	}

	/**
	 * The hidden section meal plan entries fall into when the user has not made one.
	 * Mirrors migrations/0149.sql - including the empty name, which is why the importer
	 * has to read values with PDO::NULL_NATURAL.
	 */
	private function SeedInternalMealPlanSection(): void
	{
		$this->Insert('meal_plan_sections', [
			'id' => self::INTERNAL_MEAL_PLAN_SECTION_ID,
			'name' => '',
			'sort_number' => self::INTERNAL_MEAL_PLAN_SECTION_ID
		]);
	}

	/**
	 * One prepared INSERT from a column => value map. Columns left out keep their
	 * default, which is how row_created_timestamp gets its value from the database
	 * clock rather than from PHP's.
	 */
	private function Insert(string $table, array $values): void
	{
		$columns = array_keys($values);

		$statement = $this->Db->prepare(
			'INSERT INTO ' . $this->Dialect->QuoteIdentifier($table)
			. ' (' . implode(', ', array_map(fn($c) => $this->Dialect->QuoteIdentifier($c), $columns)) . ')'
			. ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
		);

		$statement->execute(array_values($values));
	}
}
