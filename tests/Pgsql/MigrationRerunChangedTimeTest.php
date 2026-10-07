<?php

namespace Victual\Tests\Pgsql;

use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * A migration run with nothing to migrate changes nothing, including the changed time that
 * clients poll (GET /api/system/db-changed-time).
 *
 * Found by the issue #650 upgrade rehearsal (.devtools/pgsql/upgrade-rehearsal/): running
 * the upgrade a second time left every row identical except `system_db_changed_time`,
 * because DatabaseMigrationService::SyncUserSettingDefaults() upserted every default on
 * every run, and each upsert counted as a write. bin/victual-migrate runs on every pod
 * start, so each restart told every polling client to refetch. v0.2.0-MVP behaved the same.
 */
class MigrationRerunChangedTimeTest extends PgsqlSchemaTestCase
{
	private const EARLIER = '2024-01-02T03:04:05.000006Z';

	public function testARunWithNothingToMigrateLeavesTheChangedTimeAlone(): void
	{
		$database = DatabaseService::GetInstance();
		$database->SetDbChangedTime(self::EARLIER);

		DatabaseMigrationService::GetInstance()->MigrateDatabase();
		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(self::EARLIER, $database->GetDbChangedTime());
	}

	public function testADefaultTheRunCorrectsStillAdvancesIt(): void
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;

		$key = array_key_first($VICTUAL_DEFAULT_USER_SETTINGS);
		$value = $VICTUAL_DEFAULT_USER_SETTINGS[$key];
		$expected = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		$database = DatabaseService::GetInstance();
		$pdo = self::Pdo();
		$pdo->prepare('UPDATE user_settings_defaults SET value = ? WHERE key = ?')->execute([$expected . '-stale', $key]);
		$database->SetDbChangedTime(self::EARLIER);

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		$stored = $pdo->prepare('SELECT value FROM user_settings_defaults WHERE key = ?');
		$stored->execute([$key]);
		self::assertSame($expected, $stored->fetchColumn());
		self::assertNotSame(self::EARLIER, $database->GetDbChangedTime());
	}

	public function testAMissingDefaultIsWrittenAndAdvancesIt(): void
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;

		$key = array_key_last($VICTUAL_DEFAULT_USER_SETTINGS);
		$database = DatabaseService::GetInstance();
		$pdo = self::Pdo();
		$pdo->prepare('DELETE FROM user_settings_defaults WHERE key = ?')->execute([$key]);
		$database->SetDbChangedTime(self::EARLIER);

		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		$stored = $pdo->prepare('SELECT count(*) FROM user_settings_defaults WHERE key = ?');
		$stored->execute([$key]);
		self::assertSame(1, (int)$stored->fetchColumn());
		self::assertNotSame(self::EARLIER, $database->GetDbChangedTime());
	}
}
