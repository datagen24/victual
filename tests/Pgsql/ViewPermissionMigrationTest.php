<?php

namespace Victual\Tests\Pgsql;

use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #521 (part of #487): migration 0299 adds BATTERIES_VIEW/CALENDAR_VIEW/EQUIPMENT_VIEW
 * as children of BATTERIES/CALENDAR/EQUIPMENT in permission_hierarchy - the same nesting
 * migration 0281 uses for STOCK_PRICES_VIEW under STOCK_PURCHASE, and deliberately not the
 * unconditional-backfill shape migration 0266 used for the original six *_VIEW leaves (see
 * migrations/0299.pgsql.sql's own comment for why those two upgrade stories differ).
 *
 * The upgrade property that matters: a role or user that already held the parent permission
 * before 0299 ran resolves to the new leaf afterwards, with no explicit grant row - because
 * permission_tree's recursive CTE (migrations/0110.sql) resolves every descendant of a
 * permission a role or user holds, the moment the hierarchy row exists. Modelled on
 * tests/Pgsql/StockLocationMigrationTest.php's pattern of deleting a migration's own effects
 * and its `migrations` row, then re-running DatabaseMigrationService::MigrateDatabase() and
 * checking the after state.
 */
class ViewPermissionMigrationTest extends PgsqlSchemaTestCase
{
	protected function setUp(): void
	{
		$db = self::Pdo();

		// Undo 0299's own effect (every schema migration up to and including 0299 has
		// already run once in this schema, per PgsqlSchemaTestCase::setUpBeforeClass) and
		// its tracking row, to reproduce "a database that has not run 0299 yet".
		$db->exec("DELETE FROM permission_hierarchy WHERE name IN ('BATTERIES_VIEW', 'CALENDAR_VIEW', 'EQUIPMENT_VIEW')");
		$db->exec('DELETE FROM migrations WHERE migration = 299');

		$db->exec("INSERT INTO roles (code, name, builtin) VALUES ('MIGRATION299TESTROLE', 'Migration 0299 test role', 0)");
		$db->exec("INSERT INTO role_permissions (role_id, permission_id)
			SELECT r.id, p.id FROM roles r CROSS JOIN permission_hierarchy p
			WHERE r.code = 'MIGRATION299TESTROLE' AND p.name IN ('BATTERIES', 'EQUIPMENT')");

		$db->exec("INSERT INTO users(id, username, password) VALUES (9500, 'migration299-role-holder', 'fixture'), (9501, 'migration299-direct-holder', 'fixture')");
		$db->exec("INSERT INTO user_roles (user_id, role_id) SELECT 9500, id FROM roles WHERE code = 'MIGRATION299TESTROLE'");
		$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9501, id FROM permission_hierarchy WHERE name = 'CALENDAR'");
	}

	/** Whether the given user's resolved permission set (uihelper_user_permissions) includes $name. */
	private static function resolvesTo(int $userId, string $name): bool
	{
		$db = self::Pdo();
		$stmt = $db->prepare('SELECT has_permission FROM uihelper_user_permissions WHERE user_id = ? AND permission_name = ?');
		$stmt->execute([$userId, $name]);
		return (bool)$stmt->fetchColumn();
	}

	public function testRoleAndDirectHoldersOfTheParentResolveToTheNewLeafOnlyAfterMigrating(): void
	{
		$db = self::Pdo();

		self::assertSame(0, (int)$db->query("SELECT count(*) FROM permission_hierarchy WHERE name IN ('BATTERIES_VIEW', 'CALENDAR_VIEW', 'EQUIPMENT_VIEW')")->fetchColumn(), 'Precondition: the three leaves do not exist yet');

		// Before: a role holding BATTERIES/EQUIPMENT, and a user holding CALENDAR directly,
		// do not resolve to the leaves that do not exist yet.
		self::assertFalse(self::resolvesTo(9500, 'BATTERIES_VIEW'), 'Before 0299: role holder does not resolve BATTERIES_VIEW');
		self::assertFalse(self::resolvesTo(9500, 'EQUIPMENT_VIEW'), 'Before 0299: role holder does not resolve EQUIPMENT_VIEW');
		self::assertFalse(self::resolvesTo(9501, 'CALENDAR_VIEW'), 'Before 0299: direct holder does not resolve CALENDAR_VIEW');

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration = 299')->fetchColumn(), '0299 recorded as applied');
		self::assertSame(3, (int)$db->query("SELECT count(*) FROM permission_hierarchy WHERE name IN ('BATTERIES_VIEW', 'CALENDAR_VIEW', 'EQUIPMENT_VIEW')")->fetchColumn(), 'All three leaves now exist');

		// After: the same role/user, with no new grant row written anywhere, now resolve
		// to the leaf under the permission they already held - the upgrade rule the
		// maintainer decision requires ("grant each new _VIEW leaf to every role or user
		// that currently holds BATTERIES, CALENDAR or EQUIPMENT respectively").
		self::assertTrue(self::resolvesTo(9500, 'BATTERIES_VIEW'), 'After 0299: role holder of BATTERIES now resolves BATTERIES_VIEW');
		self::assertTrue(self::resolvesTo(9500, 'EQUIPMENT_VIEW'), 'After 0299: role holder of EQUIPMENT now resolves EQUIPMENT_VIEW');
		self::assertTrue(self::resolvesTo(9501, 'CALENDAR_VIEW'), 'After 0299: direct holder of CALENDAR now resolves CALENDAR_VIEW');

		// A user holding neither parent gains nothing - the deliberate access loss the
		// maintainer decision calls out ("users who hold no relevant permission lose
		// access, and that loss is the fix").
		self::assertFalse(self::resolvesTo(9500, 'CALENDAR_VIEW'), 'The role holder, who does not hold CALENDAR, does not resolve CALENDAR_VIEW');
		self::assertFalse(self::resolvesTo(9501, 'BATTERIES_VIEW'), 'The direct holder, who does not hold BATTERIES, does not resolve BATTERIES_VIEW');

		// No user_permissions backfill row was written for the new leaves - resolution
		// comes entirely from permission_tree, exactly as 0281 already established for
		// STOCK_PRICES_VIEW under STOCK_PURCHASE.
		self::assertSame(0, (int)$db->query("SELECT count(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id = up.permission_id WHERE p.name IN ('BATTERIES_VIEW', 'CALENDAR_VIEW', 'EQUIPMENT_VIEW')")->fetchColumn(), 'No explicit backfill row for the new leaves');

		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration = 299')->fetchColumn(), 'Re-running the migration is idempotent');
		self::assertSame(3, (int)$db->query("SELECT count(*) FROM permission_hierarchy WHERE name IN ('BATTERIES_VIEW', 'CALENDAR_VIEW', 'EQUIPMENT_VIEW')")->fetchColumn(), 'Re-running does not duplicate the hierarchy rows');
	}
}
