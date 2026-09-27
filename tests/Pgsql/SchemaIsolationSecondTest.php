<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Controllers\Api\BaseApiController;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Victual\Services\FieldPolicy;
use Victual\Services\LocalizationService;
use Victual\Services\StockService;
use Victual\Services\Storage\FileStorage;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #533. See SchemaIsolationFirstTest's docblock: this class is
 * its peer, identical in shape and asserting the same three things about its own schema, so
 * that whichever of the two runs second in a given registration is the one whose assertions
 * would fail first if a process-global cache leaked the other's data. It also carries the
 * cross-class assertions for the two more caches SchemaIsolationFirstTest deliberately dirties
 * - BaseApiController's column-type cache and FileStorage's backend singleton - plus four
 * direct, single-class tests for DatabaseService's dirty-data flag, its before-commit-listener
 * queue, its cached dialect's own pending-change flag, DatabaseMigrationService's
 * applied-migrations memo, and LocalizationService's per-locale instance map (see each test's
 * own docblock for why those are not exercised through the class boundary the way the others
 * are: every one of them is unavoidably touched by this class's own MigrateDatabase() call).
 */
class SchemaIsolationSecondTest extends PgsqlSchemaTestCase
{
	/** This class's marker value, inserted under the same keys SchemaIsolationFirstTest uses for its own. */
	private const MARKER = 'schema-isolation-second';

	/** SchemaIsolationFirstTest's marker value, which must never be visible from here. */
	private const PEER_MARKER = 'schema-isolation-first';

	private static PDO $db;
	private static int $productId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'schema-isolation-caller', 'fixture')");

		$locationId = self::insertRow('locations', ['name' => self::MARKER . '-location']);
		$quId = self::insertRow('quantity_units', ['name' => self::MARKER . '-unit']);

		self::$productId = self::insertRow('products', [
			'name' => self::MARKER . '-product',
			'location_id' => $locationId,
			'qu_id_purchase' => $quId,
			'qu_id_stock' => $quId,
		]);

		// Reused key (user_id, key): SchemaIsolationFirstTest inserts the same pair with its
		// own value. UsersService::$UserSettingsCache is keyed on exactly this pair, so a
		// leaked cache answers with the peer schema's value instead of an absent row.
		self::insertRow('user_settings', ['user_id' => 9000, 'key' => 'schema_isolation_marker', 'value' => self::MARKER]);

		// Reused key (permission_name, entity): SchemaIsolationFirstTest inserts the same
		// pair, gating a different field. FieldPolicy::$RowsByEntity is keyed on entity
		// alone, so a leaked cache reports the peer's field as redacted, not this one's.
		self::insertRow('permission_fields', ['permission_name' => 'STOCK_PRICES_VIEW', 'entity' => 'schema_isolation_test', 'field' => self::MARKER . '-field']);

		// A real write through the cached BaseService singleton (issue #533's own
		// reproduction): StockService::GetInstance() must reach this class's schema rather
		// than a previous class's dropped one. Done here rather than in a test method to
		// stay structurally identical to SchemaIsolationFirstTest, where the ordering is
		// load-bearing (see that class's own comment).
		$transactionId = null;
		StockService::GetInstance()->AddProduct(self::$productId, 5, null, StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 1.0, null, null, $transactionId);
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/**
	 * Confirms the booking made in setUpBeforeClass() landed in this class's own schema.
	 */
	public function testStockWasBookedInOwnSchema(): void
	{
		$amount = self::$db->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . self::$productId)->fetchColumn();
		self::assertSame(5.0, (float)$amount, "the booking must land in this class's own schema");
	}

	/**
	 * UsersService::$UserSettingsCache is keyed by (user id, setting key) alone, so a stale
	 * entry for the same pair is indistinguishable from a fresh one except by its value.
	 */
	public function testUserSettingIsOwnValue(): void
	{
		$value = UsersService::GetInstance()->GetUserSetting(9000, 'schema_isolation_marker');

		self::assertNotSame(self::PEER_MARKER, $value, "a leaked UsersService::\$UserSettingsCache entry would answer with the peer schema's value");
		self::assertSame(self::MARKER, $value);
	}

	/**
	 * FieldPolicy::$RowsByEntity is keyed by entity alone, so a stale entry for the same
	 * entity is indistinguishable from a fresh one except by which field it names.
	 */
	public function testFieldPolicyIsOwnRow(): void
	{
		$redacted = FieldPolicy::GetInstance()->RedactedFieldsFor('schema_isolation_test');

		self::assertSame([self::MARKER . '-field'], $redacted, "a leaked FieldPolicy::\$RowsByEntity entry would name the peer schema's field instead");
	}

	/**
	 * BaseApiController::$ColumnTypeCache has no public reader (ColumnTypesOf() is
	 * private), so this reads it directly - the same reflection SchemaIsolationFirstTest
	 * uses to dirty it in the first place.
	 */
	public function testBaseApiControllerColumnTypeCacheStartsClean(): void
	{
		$cache = (new ReflectionProperty(BaseApiController::class, 'ColumnTypeCache'))->getValue();

		self::assertSame([], $cache, 'BaseApiController::$ColumnTypeCache must not survive from a previous test class');
	}

	/**
	 * FileStorage::$Instance has no public reader, so this reads it directly - the same
	 * reflection SchemaIsolationFirstTest uses to dirty it in the first place.
	 */
	public function testFileStorageInstanceStartsClean(): void
	{
		$instance = (new ReflectionProperty(FileStorage::class, 'Instance'))->getValue();

		self::assertNull($instance, 'FileStorage::$Instance must not survive from a previous test class');
	}

	/**
	 * Direct coverage of DatabaseService::ResetForTest()'s dirty-data-flag clear, rather
	 * than through the SchemaIsolationFirstTest/SecondTest class boundary the checks above
	 * use.
	 *
	 * Not observable that way: MigrateDatabase() performs at least one tracked write of its
	 * own (recording a migration's version, syncing a user-setting default) via
	 * ExecuteDbStatement(), which marks the flag *set* as a correct, unavoidable side effect
	 * of migrating a schema at all - true after every class's own setUpBeforeClass()
	 * regardless of whether a leak happened, which makes "does this class start clean"
	 * unanswerable through the class boundary. Testing the method directly is the reliable
	 * alternative: mark it, call the reset, confirm it clears.
	 */
	public function testDatabaseServiceDataChangedReset(): void
	{
		DatabaseService::GetInstance()->MarkDataChanged();

		DatabaseService::ResetForTest();

		self::assertFalse(DatabaseService::GetInstance()->HasDataChanged(), 'ResetForTest() must clear the dirty-data flag');
	}

	/**
	 * Direct coverage of DatabaseService::ResetForTest()'s dialect recreation, which is what
	 * clears PostgresDialect's own pending-change flag (DbChangedPending) - instance state
	 * on the one dialect object DatabaseService caches, cleared only by recreating it. Not
	 * observable through the class boundary for the same reason as the test above:
	 * MigrateDatabase()'s own tracked writes mark it too, on every class, leak or not.
	 */
	public function testDatabaseServiceDialectPendingChangeReset(): void
	{
		DatabaseService::GetInstance()->MarkDbChanged();
		$dialectBefore = DatabaseService::GetInstance()->GetDialect();
		self::assertTrue(
			(new ReflectionProperty($dialectBefore, 'DbChangedPending'))->getValue($dialectBefore),
			'sanity check: MarkDbChanged() should have set the flag on the current dialect'
		);

		DatabaseService::ResetForTest();

		$dialectAfter = DatabaseService::GetInstance()->GetDialect();
		$pending = (new ReflectionProperty($dialectAfter, 'DbChangedPending'))->getValue($dialectAfter);
		self::assertFalse($pending, "ResetForTest() must clear the dialect's own pending-change flag");
	}

	/**
	 * Direct coverage of DatabaseService::ResetForTest()'s before-commit-listener clear,
	 * rather than through the class boundary the two tests above also could not use it for.
	 *
	 * Every class's own MigrateDatabase() call runs at least one PHP migration through
	 * DatabaseService::InTransaction(), whose commit runs and clears whatever the listener
	 * queue held at that moment (see DatabaseService::RunBeforeOutermostCommit()) - a side
	 * effect that self-heals a leaked listener before this class's own tests could observe
	 * one through the class boundary. Testing the method directly is the reliable
	 * alternative: register a listener, call the reset, confirm it clears.
	 */
	public function testDatabaseServiceBeforeCommitListenerReset(): void
	{
		(new ReflectionProperty(DatabaseService::class, 'BeforeOutermostCommitListeners'))->setValue(null, ['sentinel' => function ()
		{
		}]);

		DatabaseService::ResetForTest();

		$listeners = (new ReflectionProperty(DatabaseService::class, 'BeforeOutermostCommitListeners'))->getValue();
		self::assertSame([], $listeners, 'ResetForTest() must clear any pending before-commit listener');
	}

	/**
	 * Direct coverage of LocalizationService::ResetInstancesForTest() itself, rather than
	 * through the class boundary.
	 *
	 * Migration 0274's own happy path constructs a LocalizationService instance (see
	 * MigrationRunnerAtomicityTest's docblock), so every class's own MigrateDatabase() call
	 * incidentally repopulates $InstanceMap['en'] with one bound to *that* class's own schema
	 * before this class's tests could observe a leaked entry through the class boundary - the
	 * same self-healing shape as the two DatabaseService tests above. Testing the method
	 * directly is the reliable alternative: populate the map, call the reset, confirm it
	 * clears.
	 */
	public function testLocalizationServiceInstanceMapReset(): void
	{
		(new ReflectionProperty(LocalizationService::class, 'InstanceMap'))->setValue(null, ['en' => new \stdClass()]);

		LocalizationService::ResetInstancesForTest();

		$instanceMap = (new ReflectionProperty(LocalizationService::class, 'InstanceMap'))->getValue();
		self::assertSame([], $instanceMap, 'ResetInstancesForTest() must clear the per-locale instance map');
	}

	/**
	 * Direct coverage of DatabaseMigrationService::ResetCachesForTest() itself, rather than
	 * through the class boundary.
	 *
	 * MigrateDatabase() already nulls $AppliedMigrationNumbers itself once it finishes (see
	 * the comment on that assignment), and every class here calls it successfully during
	 * its own setUpBeforeClass() - so a class that completes its setup normally never
	 * observes a stale value regardless of whether PgsqlSchemaTestCase's own reset runs;
	 * only a *previous* class whose setup threw before MigrateDatabase() reached that line
	 * would leave one behind, which is what this reset guards against and which the normal
	 * two-class lifecycle this file's other tests use cannot exercise. Testing the method
	 * directly is the reliable alternative: dirty the memo, call the reset, confirm it clears.
	 */
	public function testDatabaseMigrationServiceCacheResets(): void
	{
		(new ReflectionProperty(DatabaseMigrationService::class, 'AppliedMigrationNumbers'))->setValue(null, [999999999]);

		DatabaseMigrationService::ResetCachesForTest();

		$value = (new ReflectionProperty(DatabaseMigrationService::class, 'AppliedMigrationNumbers'))->getValue();
		self::assertNull($value, 'ResetCachesForTest() must clear the memoized applied-migrations list');
	}
}
