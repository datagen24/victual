<?php

namespace Victual\Tests\Pgsql;

use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue 742, ADR-0043 decision 1: migration 0308 adds MASTER_DATA_ADD as a child of
 * MASTER_DATA_EDIT in permission_hierarchy, with no user_permissions backfill - the same
 * shape as migrations/0281.pgsql.sql and migrations/0299.pgsql.sql, modelled here on
 * tests/Pgsql/ViewPermissionMigrationTest.php.
 *
 * This is the upgrade rehearsal the maintainer approved on issue 742 (decision 7): a
 * populated fixture is put in the state "0308 has not run" and the migration is applied
 * through DatabaseMigrationService::MigrateDatabase(), the call bin/victual-migrate makes.
 * The assertions are made on user_permissions_resolved, the view every permission check
 * reads, so they cover role grants and direct grants alike.
 *
 * Accounts and what they hold before the migration:
 *   9601  role ADULT (seeded)         9602  role CHILD (seeded)     9603  role GUEST (seeded)
 *   9604  direct MASTER_DATA_EDIT     9605  direct ADMIN            9606  direct STOCK_VIEW only
 *   9607  no grants at all            9608  role ADMIN (seeded)
 *
 * PR 2 of ADR-0043 (the decision 7 account listing) extends snapshotResolved() and
 * gainedBetween() below instead of building a second diff.
 */
class MasterDataAddMigrationTest extends PgsqlSchemaTestCase
{
	/** Fixture accounts that hold MASTER_DATA_EDIT or ADMIN, and so must gain MASTER_DATA_ADD. */
	private const HOLDERS = [9604, 9605, 9608];

	/** Fixture accounts that must not. */
	private const NON_HOLDERS = [9601, 9602, 9603, 9606, 9607];

	private static bool $fixtureBuilt = false;

	protected function setUp(): void
	{
		$db = self::Pdo();

		// Undo 0308's effect and its tracking row to reproduce a database that has not run it.
		$db->exec("DELETE FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'");
		$db->exec('DELETE FROM migrations WHERE migration = 308');

		if (self::$fixtureBuilt)
		{
			return;
		}
		self::$fixtureBuilt = true;

		$db->exec("INSERT INTO users(id, username, password) VALUES
			(9601, 'm308-adult', 'fixture'), (9602, 'm308-child', 'fixture'), (9603, 'm308-guest', 'fixture'),
			(9604, 'm308-master-data-edit', 'fixture'), (9605, 'm308-admin-direct', 'fixture'),
			(9606, 'm308-stock-view-only', 'fixture'), (9607, 'm308-no-grants', 'fixture'),
			(9608, 'm308-admin-role', 'fixture')");
		foreach ([9601 => 'ADULT', 9602 => 'CHILD', 9603 => 'GUEST', 9608 => 'ADMIN'] as $userId => $code)
		{
			$db->exec("INSERT INTO user_roles (user_id, role_id) SELECT $userId, id FROM roles WHERE code = '$code' AND builtin = 1");
		}
		foreach ([9604 => 'MASTER_DATA_EDIT', 9605 => 'ADMIN', 9606 => 'STOCK_VIEW'] as $userId => $name)
		{
			$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $userId, id FROM permission_hierarchy WHERE name = '$name'");
		}
	}

	/**
	 * Every (user, permission) pair user_permissions_resolved reports, keyed "userId:NAME".
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	protected static function snapshotResolved(): array
	{
		$snapshot = [];
		foreach (self::Pdo()->query('SELECT user_id, permission_name FROM user_permissions_resolved') as $row)
		{
			$snapshot[$row['user_id'] . ':' . $row['permission_name']] = [(int)$row['user_id'], $row['permission_name']];
		}
		ksort($snapshot);
		return $snapshot;
	}

	/**
	 * Pairs present in $after and absent from $before.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	protected static function gainedBetween(array $before, array $after): array
	{
		return array_diff_key($after, $before);
	}

	public function testOnlyMasterDataEditAndAdminHoldersGainTheNewPermission(): void
	{
		$db = self::Pdo();

		self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'")->fetchColumn(), 'Precondition: MASTER_DATA_ADD does not exist');
		$before = self::snapshotResolved();
		self::assertContains('MASTER_DATA_EDIT', array_column($before, 1), 'Precondition: the snapshot sees resolved permissions');
		self::assertArrayHasKey('9601:STOCK', $before, 'Precondition: the Adult role resolves through user_roles');
		self::assertArrayNotHasKey('9604:MASTER_DATA_ADD', $before);
		$grantRowsBefore = (int)$db->query('SELECT COUNT(*) FROM user_permissions')->fetchColumn();

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM migrations WHERE migration = 308')->fetchColumn(), '0308 is recorded as applied');
		$parent = $db->query("SELECT c.name FROM permission_hierarchy p JOIN permission_hierarchy c ON c.id = p.parent WHERE p.name = 'MASTER_DATA_ADD'")->fetchColumn();
		self::assertSame('MASTER_DATA_EDIT', $parent, 'MASTER_DATA_ADD is a child of MASTER_DATA_EDIT');

		$after = self::snapshotResolved();
		$gained = self::gainedBetween($before, $after);

		// Nobody loses anything.
		self::assertSame([], array_keys(array_diff_key($before, $after)), 'No account lost a resolved permission');

		// Everything gained is the one new permission.
		foreach ($gained as $key => [$userId, $name])
		{
			self::assertSame('MASTER_DATA_ADD', $name, "Account $userId gained only MASTER_DATA_ADD, not $name");
		}

		// The fixture: exactly the MASTER_DATA_EDIT and ADMIN holders gain it.
		$gainedFixture = array_values(array_filter(array_map(fn($pair) => $pair[0], $gained), fn($id) => $id >= 9600));
		sort($gainedFixture);
		self::assertSame(self::HOLDERS, $gainedFixture, 'Only the MASTER_DATA_EDIT and ADMIN holders gained MASTER_DATA_ADD');
		foreach (self::NON_HOLDERS as $userId)
		{
			self::assertArrayNotHasKey("$userId:MASTER_DATA_ADD", $after, "Account $userId (no MASTER_DATA_EDIT, no ADMIN) does not resolve MASTER_DATA_ADD");
		}

		// Any account outside the fixture that gained it held MASTER_DATA_EDIT beforehand.
		foreach ($gained as [$userId, $name])
		{
			self::assertArrayHasKey("$userId:MASTER_DATA_EDIT", $before, "Account $userId held MASTER_DATA_EDIT before it gained MASTER_DATA_ADD");
		}

		// Resolution came from the hierarchy: no grant row was written for anyone.
		self::assertSame($grantRowsBefore, (int)$db->query('SELECT COUNT(*) FROM user_permissions')->fetchColumn(), 'The migration wrote no user_permissions row');
		self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM user_permissions up JOIN permission_hierarchy p ON p.id = up.permission_id WHERE p.name = 'MASTER_DATA_ADD'")->fetchColumn(), 'No explicit MASTER_DATA_ADD grant exists');
		self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permission_hierarchy p ON p.id = rp.permission_id WHERE p.name = 'MASTER_DATA_ADD'")->fetchColumn(), 'No role was given MASTER_DATA_ADD directly');

		// A second run changes nothing.
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		self::assertSame($after, self::snapshotResolved(), 'Re-running the migration changes no resolved permission');
		self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'")->fetchColumn(), 'Re-running does not duplicate the hierarchy row');
		self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM migrations WHERE migration = 308')->fetchColumn());
	}

	public function testSeedFileIsIdempotentOnItsOwn(): void
	{
		// The importer replays the same file after every legacy import, onto a database
		// whose hierarchy may or may not carry the row already.
		$db = self::Pdo();
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		$id = (int)$db->query("SELECT id FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'")->fetchColumn();
		self::assertGreaterThan(0, $id);

		$db->exec(file_get_contents(__DIR__ . '/../../db/pgsql/master-data-add-seed.sql'));

		self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'")->fetchColumn(), 'The seed adds no second row');
		self::assertSame($id, (int)$db->query("SELECT id FROM permission_hierarchy WHERE name = 'MASTER_DATA_ADD'")->fetchColumn(), 'The seed leaves the existing row alone');
	}
}
