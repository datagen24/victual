<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\FieldPolicy;
use Victual\Services\StockService;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #533. See SchemaIsolationFirstTest's docblock: this class is
 * its peer, identical in shape and asserting the same three things about its own schema, so
 * that whichever of the two runs second in a given registration is the one whose assertions
 * would fail first if a process-global cache leaked the other's data.
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
	 * A real write through the cached BaseService singleton (issue #533's own reproduction):
	 * StockService::GetInstance() must reach this class's schema rather than a previous
	 * class's dropped one - or, just as wrong and much quieter, a previous class's schema if
	 * it were somehow still open.
	 */
	public function testBooksStockThroughCachedSingleton(): void
	{
		$transactionId = null;
		StockService::GetInstance()->AddProduct(self::$productId, 5, null, StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 1.0, null, null, $transactionId);

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
}
