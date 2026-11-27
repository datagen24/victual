<?php

namespace Victual\Tests\Pgsql;

use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 1111, ADR-0057 piece 1: migration 0320 seeds plan 42's permission catalog and checks
 * its own result (decision 2).
 *
 * The first group of tests reads what the seeded roles resolve on a migrated database and
 * holds each to plan 42's "Household roles" section. The second puts the database in the state
 * "0320 has not run" and applies the migration through DatabaseMigrationService::MigrateDatabase(),
 * the call bin/victual-migrate makes: once where the self-check passes, and once where a
 * tree the catalog does not expect makes the migration refuse and roll back.
 *
 * .devtools/pgtap/042-permission-catalog.sql tests the SQL functions case by case; this file
 * tests the migration as the runner applies it.
 */
class PermissionCatalogMigrationTest extends PgsqlSchemaTestCase
{
	/** The 41 permissions that existed before 0320, in User.php's constants. */
	private static function ExistingPermissions(): array
	{
		$constants = (new \ReflectionClass(\Victual\Controllers\Users\User::class))->getConstants();
		return array_values(array_filter($constants, fn($value, $name) => str_starts_with($name, 'PERMISSION_'), ARRAY_FILTER_USE_BOTH));
	}

	/**
	 * Every permission a role resolves: its grants expanded through permission_tree.
	 *
	 * @return list<string>
	 */
	private static function RoleResolves(string $code): array
	{
		$statement = self::Pdo()->prepare('SELECT DISTINCT pt.name FROM roles r
			JOIN role_permissions rp ON rp.role_id = r.id
			JOIN permission_tree pt ON pt.id = rp.permission_id
			WHERE r.code = ? ORDER BY pt.name');
		$statement->execute([$code]);
		return $statement->fetchAll(\PDO::FETCH_COLUMN);
	}

	/**
	 * Removes what 0320 added and its tracking row, so the next MigrateDatabase() runs it again.
	 */
	private static function UndoMigration(): void
	{
		$db = self::Pdo();
		$catalog = 'SELECT ph.id FROM permission_hierarchy ph JOIN permission_catalog_leaves() c ON c.name = ph.name';
		$db->exec("DELETE FROM role_permissions WHERE permission_id IN ($catalog)");
		$db->exec("DELETE FROM user_permissions WHERE permission_id IN ($catalog)");
		$db->exec("DELETE FROM roles WHERE code = 'HOUSEHOLD_MANAGER'");
		$db->exec("DELETE FROM permission_hierarchy WHERE name IN (SELECT name FROM permission_catalog_leaves() WHERE name <> 'LABELS_ADMIN')");
		$db->exec("DELETE FROM permission_hierarchy WHERE name = 'LABELS_ADMIN'");
		$db->exec('DELETE FROM migrations WHERE migration = 320');
	}

	/**
	 * Proves the tree holds the 41 existing permissions and the 190 rows migration 0320 adds.
	 */
	public function testExistingPermissionsAreTheFortyOneConstants(): void
	{
		self::assertCount(41, self::ExistingPermissions(), 'Precondition: User.php still declares 41 permissions');
		$count = (int)self::Pdo()->query('SELECT COUNT(*) FROM permission_hierarchy')->fetchColumn();
		self::assertSame(41 + 190, $count, 'The tree holds the 41 permissions and the 190 rows 0320 adds');
	}

	/**
	 * Proves the ADMIN role still resolves every row of permission_hierarchy.
	 */
	public function testAdminResolvesEveryLeaf(): void
	{
		$all = self::Pdo()->query('SELECT name FROM permission_hierarchy ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame($all, self::RoleResolves('ADMIN'));
	}

	/**
	 * Proves HOUSEHOLD_MANAGER resolves exactly the Adult closure plus MASTER_DATA_EDIT, and none of what plan 42 says it does not hold.
	 */
	public function testHouseholdManagerIsAdultPlusMasterDataEdit(): void
	{
		$manager = self::RoleResolves('HOUSEHOLD_MANAGER');
		$adult = self::RoleResolves('ADULT');
		$masterData = self::Pdo()->query("SELECT name FROM permission_tree WHERE id = (SELECT id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT')")->fetchAll(\PDO::FETCH_COLUMN);

		$expected = array_values(array_unique(array_merge($adult, $masterData)));
		sort($expected);
		self::assertSame($expected, $manager, 'Household manager: exactly the Adult closure plus the MASTER_DATA_EDIT closure');

		foreach (['CHORES_DELETE', 'PRODUCTS_CREATE', 'PRODUCTS_MERGE', 'CHORES_MERGE', 'LABELS_PRINT', 'LABEL_JOBS_REPRINT', 'LABEL_JOBS_CANCEL', 'USER_ACCOUNTS_READ'] as $leaf)
		{
			self::assertContains($leaf, $manager, "Household manager holds $leaf");
		}
		foreach (['USERS_CREATE', 'USERS_EDIT', 'LABELS_ADMIN', 'LABEL_PRINTERS_CREATE', 'USERFIELDS_CREATE', 'USERENTITIES_UPDATE', 'API_KEYS_READ', 'API_KEYS_DELETE', 'SYSTEM_INFO_READ', 'USER_ACCOUNTS_CREATE', 'ADMIN'] as $leaf)
		{
			self::assertNotContains($leaf, $manager, "Household manager does not hold $leaf");
		}
	}

	/**
	 * Proves Adult keeps its 35 existing permissions, gains task delete (answer 1) and still cannot delete chores or create master data (answer 5).
	 */
	public function testAdultKeepsTodaysThirtyFivePermissionsAndGainsTaskDelete(): void
	{
		$adult = self::RoleResolves('ADULT');
		self::assertCount(35, array_intersect($adult, self::ExistingPermissions()), "Adult: today's closure of 35 permissions");
		self::assertContains('TASKS_DELETE', $adult, 'Adult: task delete (answer 1)');
		self::assertContains('CHORES_UPDATE', $adult, 'Adult: disables a chore through CHORES_UPDATE');
		foreach (['CHORES_DELETE', 'PRODUCTS_CREATE', 'LOCATIONS_CREATE', 'STORES_CREATE', 'MASTER_DATA_EDIT', 'MASTER_DATA_ADD', 'USERS_CREATE', 'USERS_EDIT', 'ADMIN'] as $leaf)
		{
			self::assertNotContains($leaf, $adult, "Adult does not hold $leaf (answers 1 and 5)");
		}
	}

	/**
	 * Proves Child keeps its 14 existing permissions, resolves the reads and list-header writes plan 42 names, and sees no prices.
	 */
	public function testChildKeepsTodaysFourteenPermissions(): void
	{
		$child = self::RoleResolves('CHILD');
		self::assertCount(14, array_intersect($child, self::ExistingPermissions()), "Child: today's closure of 14 permissions");
		foreach (['SHOPPING_LISTS_CREATE', 'SHOPPING_LISTS_UPDATE', 'SHOPPING_LIST_ITEMS_CREATE', 'PRODUCTS_READ', 'STOCK_ENTRIES_READ', 'CHORES_READ', 'TASKS_READ', 'RECIPES_READ', 'MEAL_PLAN_ENTRIES_READ'] as $leaf)
		{
			self::assertContains($leaf, $child, "Child holds $leaf");
		}
		foreach (['STOCK_PRICES_VIEW', 'TASKS_DELETE', 'SHOPPING_LISTS_DELETE', 'STOCK_ENTRIES_UPDATE', 'CHORES_UPDATE'] as $leaf)
		{
			self::assertNotContains($leaf, $child, "Child does not hold $leaf");
		}
	}

	/**
	 * Proves Guest keeps its 3 existing permissions and holds no write leaf beyond the consumption writes gated by STOCK_VIEW alone.
	 */
	public function testGuestKeepsTodaysThreePermissionsAndWritesNothing(): void
	{
		$guest = self::RoleResolves('GUEST');
		self::assertCount(3, array_intersect($guest, self::ExistingPermissions()), "Guest: today's closure of 3 permissions");
		// Plan 42 puts seven medication-consumption writes under STOCK_VIEW, because STOCK_VIEW is
		// their only global gate today and the per-record rights (ADR-0040) do the limiting. A
		// Guest therefore resolves them, as it passes that gate today; every other write is absent.
		$consumptionUnderStockView = ['CONSUMPTION_PRESETS_DELETE', 'CONSUMPTION_PRESETS_UPDATE', 'CONSUMPTION_REFILLS_CREATE', 'CONSUMPTION_REFILLS_UPDATE', 'CONSUMPTION_SHARES_CREATE', 'CONSUMPTION_SHARES_DELETE', 'CONSUMPTION_SHARES_UPDATE'];
		$writes = array_values(array_filter($guest, fn($leaf) => preg_match('/_(CREATE|UPDATE|DELETE)$/', $leaf)));
		self::assertSame($consumptionUnderStockView, $writes, 'Guest holds no write leaf except the consumption writes gated by STOCK_VIEW alone');
		self::assertContains('PRODUCTS_READ', $guest);
		self::assertContains('RECIPES_READ', $guest);
	}

	/**
	 * Proves every built-in role carries USERFIELDS_READ and USERENTITIES_READ (plan 42 answer 4).
	 */
	public function testEveryRoleCarriesTheOpenDefinitionReads(): void
	{
		foreach (['ADMIN', 'HOUSEHOLD_MANAGER', 'ADULT', 'CHILD', 'GUEST'] as $code)
		{
			$resolved = self::RoleResolves($code);
			self::assertContains('USERFIELDS_READ', $resolved, "$code holds USERFIELDS_READ");
			self::assertContains('USERENTITIES_READ', $resolved, "$code holds USERENTITIES_READ");
		}
	}

	/**
	 * Proves an upgrade from 0319 through MigrateDatabase() passes its own check, applies change (a) both ways, grants the open reads, and is idempotent.
	 */
	public function testUpgradeThroughTheRunnerPassesItsOwnCheck(): void
	{
		$db = self::Pdo();
		self::UndoMigration();
		$db->exec("INSERT INTO users (id, username, password) VALUES (9801, 'm320-adult', 'x'), (9802, 'm320-master-data', 'x')");
		$db->exec("INSERT INTO user_roles (user_id, role_id) SELECT 9801, id FROM roles WHERE code = 'ADULT'");
		$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9802, id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'");
		$adultBefore = $db->query('SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9801 ORDER BY 1')->fetchAll(\PDO::FETCH_COLUMN);

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM migrations WHERE migration = 320')->fetchColumn(), '0320 is recorded as applied');
		self::assertSame(231, (int)$db->query('SELECT COUNT(*) FROM permission_hierarchy')->fetchColumn());
		$adultAfter = $db->query('SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9801 ORDER BY 1')->fetchAll(\PDO::FETCH_COLUMN);
		self::assertSame($adultBefore, array_values(array_intersect($adultAfter, $adultBefore)), 'The Adult holder loses nothing');
		self::assertContains('TASKS_DELETE', $adultAfter, 'The Adult holder gains task delete, change (a)');
		$mde = $db->query('SELECT permission_name FROM user_permissions_resolved WHERE user_id = 9802')->fetchAll(\PDO::FETCH_COLUMN);
		self::assertNotContains('TASKS_DELETE', $mde, 'A MASTER_DATA_EDIT holder without TASKS loses task delete, the other half of (a)');
		self::assertContains('CHORES_DELETE', $mde, 'and keeps chore delete');
		foreach ([9801, 9802] as $userId)
		{
			self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id = up.permission_id WHERE up.user_id = $userId AND p.name = 'USERFIELDS_READ'")->fetchColumn(), "User $userId is granted USERFIELDS_READ");
		}

		// A second run of the runner changes nothing.
		$tree = $db->query('SELECT name, parent FROM permission_hierarchy ORDER BY name')->fetchAll(\PDO::FETCH_NUM);
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame($tree, $db->query('SELECT name, parent FROM permission_hierarchy ORDER BY name')->fetchAll(\PDO::FETCH_NUM));
	}

	/**
	 * Proves a tree the catalog does not expect makes the migration refuse, roll back and leave 0320 unrecorded.
	 */
	public function testUpgradeThatFailsItsCheckRollsBack(): void
	{
		$db = self::Pdo();
		self::UndoMigration();
		// A tree the catalog does not expect: PRODUCTS_READ already exists, under the wrong parent.
		$db->exec("INSERT INTO permission_hierarchy (name, parent) SELECT 'PRODUCTS_READ', id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'");
		$before = $db->query('SELECT COUNT(*) FROM permission_hierarchy')->fetchColumn();

		$failure = null;
		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}
		catch (\Throwable $e)
		{
			$failure = $e;
		}

		self::assertNotNull($failure, 'The migration refused');
		self::assertStringContainsString('PRODUCTS_READ (expected under STOCK_VIEW, found under MASTER_DATA_EDIT)', $failure->getMessage());
		self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM migrations WHERE migration = 320')->fetchColumn(), '0320 is not recorded');
		self::assertSame($before, $db->query('SELECT COUNT(*) FROM permission_hierarchy')->fetchColumn(), 'The tree is as it was: the transaction rolled back');
		self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM roles WHERE code = 'HOUSEHOLD_MANAGER'")->fetchColumn(), 'No household manager was left behind');

		// Put it right and let the runner finish, so the class leaves a migrated schema.
		$db->exec("DELETE FROM permission_hierarchy WHERE name = 'PRODUCTS_READ'");
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM migrations WHERE migration = 320')->fetchColumn());
	}
}
