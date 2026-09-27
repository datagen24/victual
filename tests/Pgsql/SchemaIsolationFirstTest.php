<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Controllers\Api\BaseApiController;
use Victual\Services\FieldPolicy;
use Victual\Services\StockService;
use Victual\Services\Storage\FileStorage;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #533: PgsqlSchemaTestCase's centralized reset must stop a
 * process-global cache from carrying one PHPUnit test class's schema-bound data into the
 * next class sharing this PHPUnit process (see PgsqlSchemaTestCase's own docblock for why
 * classes share a process within one testsuite).
 *
 * This class and SchemaIsolationSecondTest are peers, identical in shape. Each seeds a
 * product, a user_settings row and a permission_fields row that share their *keys* with the
 * other's - the same (user_id, key) pair for the setting, the same (permission_name, entity)
 * pair for the policy row - but different *values*. A cache that merely fails to throw (the
 * row exists in both schemas, so there is no missing-table exception to notice) is exactly
 * what reused keys are for: the wrong, stale value is the only symptom. StockService's own
 * cached-singleton write is included too (issue #533's original reproduction, and the one
 * case a missing-table error *would* have caught), so this pair covers both the loud and the
 * quiet failure modes in one place.
 *
 * This class additionally dirties two more process-global caches issue #533's audit found
 * that can be dirtied across a class boundary this way - BaseApiController's column-type
 * cache and FileStorage's backend singleton - so SchemaIsolationSecondTest can assert each
 * one starts clean; see the bottom of setUpBeforeClass() below and SchemaIsolationSecondTest's
 * corresponding assertions. Four more the same audit found - DatabaseService's dirty-data
 * flag, its before-commit listeners, its cached dialect's own pending-change flag,
 * DatabaseMigrationService's applied-migrations memo, and LocalizationService's per-locale
 * instance map - are not dirtied here: every one of those is unavoidably touched by
 * SchemaIsolationSecondTest's own migration run (MigrateDatabase() itself performs tracked
 * writes, so the dirty-data flag and the dialect's pending-change flag are legitimately true
 * after *every* class's own setup, leak or not; a PHP migration's own transaction clears any
 * pending before-commit listener; migration 0274's own happy path constructs a
 * LocalizationService instance), making a leak unobservable through the class boundary the
 * way UsersService's or FieldPolicy's leaks are. SchemaIsolationSecondTest covers those
 * directly instead.
 *
 * Both possible orderings of this pair matter, and a phpunit.xml testsuite has one fixed
 * file order, so the pair is registered twice: as itself under "dialectpolicy", and reversed
 * - SchemaIsolationSecondTest listed before SchemaIsolationFirstTest - inside
 * "stocklocations" (an existing, unrelated multi-class suite already run for its own
 * coverage). See the pull request description for why that was chosen over adding a new
 * run-tests.sh phase just to reverse two file names: this remediation wave runs many fixers
 * against run-tests.sh concurrently, and reusing an existing phase avoids the merge risk of
 * several of them editing the same shared script at once.
 */
class SchemaIsolationFirstTest extends PgsqlSchemaTestCase
{
	/** This class's marker value, inserted under the same keys SchemaIsolationSecondTest uses for its own. */
	private const MARKER = 'schema-isolation-first';

	/** SchemaIsolationSecondTest's marker value, which must never be visible from here. */
	private const PEER_MARKER = 'schema-isolation-second';

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

		// Reused key (user_id, key): SchemaIsolationSecondTest inserts the same pair with
		// its own value. UsersService::$UserSettingsCache is keyed on exactly this pair, so
		// a leaked cache answers with the peer schema's value instead of an absent row.
		self::insertRow('user_settings', ['user_id' => 9000, 'key' => 'schema_isolation_marker', 'value' => self::MARKER]);

		// Reused key (permission_name, entity): SchemaIsolationSecondTest inserts the same
		// pair, gating a different field. FieldPolicy::$RowsByEntity is keyed on entity
		// alone, so a leaked cache reports the peer's field as redacted, not this one's.
		self::insertRow('permission_fields', ['permission_name' => 'STOCK_PRICES_VIEW', 'entity' => 'schema_isolation_test', 'field' => self::MARKER . '-field']);

		// A real write through the cached BaseService singleton (issue #533's own
		// reproduction): StockService::GetInstance() must reach this class's schema rather
		// than a previous class's dropped one. Done here, in setUpBeforeClass(), rather than
		// in a test method, purely to stay next to the fixture rows above; nothing below
		// depends on it running first.
		$transactionId = null;
		StockService::GetInstance()->AddProduct(self::$productId, 3, null, StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 1.0, null, null, $transactionId);

		// From here down: deliberately dirties every other process-global cache issue #533's
		// audit found that can be dirtied through the class boundary, so
		// SchemaIsolationSecondTest has something to catch if PgsqlSchemaTestCase stopped
		// resetting one of them. Not included: DatabaseService's dirty-data flag, its
		// before-commit listeners and its cached dialect's own pending-change flag,
		// DatabaseMigrationService's applied-migrations memo, and LocalizationService's
		// per-locale instance map - every one of those is unavoidably touched by
		// SchemaIsolationSecondTest's own migration run (MigrateDatabase() itself writes
		// tracked data - a migration record, seeded defaults - which legitimately marks the
		// dirty-data flag and the dialect's pending-change flag, while a PHP migration's own
		// transaction clears any pending before-commit listener and migration 0274's own
		// happy path constructs a LocalizationService), so it is true, or absent, after
		// *every* class's own setup regardless of whether a leak happened - unobservable
		// through the class boundary the way UsersService's or FieldPolicy's leaks are.
		// SchemaIsolationSecondTest covers all four directly instead.

		// BaseApiController::$ColumnTypeCache and FileStorage::$Instance have no public
		// mutator that leaves them dirty without a real (and here, unnecessary) database
		// table or a FILE_STORAGE=database configuration, so they are set directly - the
		// same reflection PgsqlSchemaTestCase itself uses to install a connection.
		(new ReflectionProperty(BaseApiController::class, 'ColumnTypeCache'))->setValue(null, ['schema-isolation-sentinel-table' => ['sentinel_column' => 'sentinel_type']]);
		(new ReflectionProperty(FileStorage::class, 'Instance'))->setValue(null, new \stdClass());
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
		self::assertSame(3.0, (float)$amount, "the booking must land in this class's own schema");
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
}
