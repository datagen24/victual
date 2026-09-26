<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Audit findings M19 (issue #519) and M24 (issue #524), and the EditStockEntry half of H3
 * (issue #492): PUT /api/stock/entry/{entryId}'s partial-update contract.
 *
 *   M19 An invalid best_before_date/location_id used to fall through to null and erase the
 *       stored value while the request answered 200. Refused with 400 now, and nothing
 *       about the entry changes.
 *   M24 Omitting `open` used to reach BoolToInt() as a literal null and raise a TypeError,
 *       answering 500 for the simplest possible partial edit (amount only). Every optional
 *       field now keeps the entry's current value when its key is absent, is validated
 *       when present, and `open` in particular is never read from the string "false" as
 *       true (PHP's own boolval("false") === true).
 *   H3  EditStockEntry() refuses a negative amount atomically, at the service level, so
 *       every caller of that method - not only this HTTP route - gets the same refusal.
 *       Zero is deliberately left able to succeed; that is a separate, undecided question.
 *
 * Every HTTP-level case is its own process (tests/Pgsql/request-subprocess-helper.php): the
 * authentication middleware define()s the acting user, and PHP cannot redefine a constant.
 * The H3 cases call StockService::EditStockEntry() directly instead, because the amount
 * guard protects every caller of that method, not only this route - see
 * services/StockService.php's WeighLocation(), which calls it too.
 */
class StockEntryEditContractTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static string $key;
	private static int $pantryId;
	private static int $shelfId;
	private static int $grocerId;
	private static int $quId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9700, 'stock-entry-edit', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9700, id FROM permission_hierarchy WHERE name IN ('STOCK_EDIT', 'STOCK_VIEW')");

		self::$key = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, 9700, now() + interval '30 days', ?)");
		$stmt->execute([
			ApiKeyService::HashKey(self::$key),
			substr(self::$key, -4),
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		self::$quId = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('EditPiece', 'EditPieces') RETURNING id")->fetchColumn();
		self::$pantryId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('EditPantry') RETURNING id")->fetchColumn();
		self::$shelfId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('EditShelf') RETURNING id")->fetchColumn();
		self::$grocerId = (int)self::$db->query("INSERT INTO shopping_locations (name) VALUES ('EditGrocer') RETURNING id")->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Fixture and request helpers
	// ------------------------------------------------------------------------------

	/**
	 * A fresh product and a single stock row for it, with the given column overrides.
	 * Returns the stock row's id.
	 */
	private static function seedStockRow(array $overrides = []): int
	{
		static $n = 0;
		$n++;

		$productStatement = self::$db->prepare(
			'INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, ?, ?) RETURNING id'
		);
		$productStatement->execute(["EditProduct$n", self::$pantryId, self::$quId, self::$quId]);
		$productId = (int)$productStatement->fetchColumn();

		$row = array_merge([
			'product_id' => $productId,
			'amount' => 3,
			'best_before_date' => '2030-06-01',
			'purchased_date' => '2026-01-15',
			'stock_id' => "edit-stock-$n",
			'price' => 2.5,
			'open' => 0,
			'opened_date' => null,
			'location_id' => self::$pantryId,
			'shopping_location_id' => self::$grocerId,
			'note' => 'original note',
		], $overrides);

		$columns = array_keys($row);
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare('INSERT INTO stock (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ') RETURNING id');
		$statement->execute(array_values($row));

		return (int)$statement->fetchColumn();
	}

	/** The stock row as an associative array, or null. */
	private static function stockRow(int $entryId): ?array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$statement->execute([$entryId]);
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	private static function stockLogCount(): int
	{
		return (int)self::$db->query('SELECT count(*) FROM stock_log')->fetchColumn();
	}

	/** PUTs $body to /api/stock/entry/$entryId through the real middleware stack. @return array{status: int, body: string} */
	private static function put(int $entryId, array $body): array
	{
		$spec = [
			'method' => 'PUT',
			'path' => "/api/stock/entry/$entryId",
			'headers' => ['VICTUAL-API-KEY' => self::$key],
			'body' => $body,
		];
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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for PUT /api/stock/entry/$entryId. stdout: $output\nstderr: $errors");

		return $result;
	}

	// ------------------------------------------------------------------------------
	// M24 (issue #524): amount-only edit is a partial update, not a 500
	// ------------------------------------------------------------------------------

	public function testAmountOnlyEditKeepsEveryOtherFieldAndSucceeds(): void
	{
		$entryId = self::seedStockRow();
		$before = self::stockRow($entryId);
		$logsBefore = self::stockLogCount();

		$response = self::put($entryId, ['amount' => 5]);

		self::assertSame(200, $response['status'], "amount-only edit must not 500: {$response['body']}");

		$after = self::stockRow($entryId);
		self::assertSame(5.0, (float)$after['amount'], 'the supplied amount is applied');
		self::assertSame($before['best_before_date'], $after['best_before_date'], 'an omitted best_before_date keeps its stored value');
		self::assertSame($before['purchased_date'], $after['purchased_date'], 'an omitted purchased_date keeps its stored value');
		self::assertSame($before['price'], $after['price'], 'an omitted price keeps its stored value');
		self::assertSame($before['location_id'], $after['location_id'], 'an omitted location_id keeps its stored value');
		self::assertSame($before['shopping_location_id'], $after['shopping_location_id'], 'an omitted shopping_location_id keeps its stored value');
		self::assertSame($before['open'], $after['open'], 'an omitted open keeps its stored value - never silently closed');
		self::assertSame($before['note'], $after['note'], 'an omitted note keeps its stored value');
		self::assertSame($logsBefore + 2, self::stockLogCount(), 'the edit still records its old/new stock_log pair');
	}

	public function testAmountOnlyEditOnAnOpenEntryDoesNotCloseIt(): void
	{
		$entryId = self::seedStockRow(['open' => 1, 'opened_date' => '2026-01-20']);

		$response = self::put($entryId, ['amount' => 1]);

		self::assertSame(200, $response['status'], $response['body']);
		$after = self::stockRow($entryId);
		self::assertSame(1, (int)$after['open'], 'omitting open must not be read as an observed successful close (issue #524)');
		self::assertSame('2026-01-20', $after['opened_date'], 'the opened date is untouched when open is kept, not just the flag');
	}

	// ------------------------------------------------------------------------------
	// M19 (issue #519): an unreadable supplied value is refused, not silently erased
	// ------------------------------------------------------------------------------

	public function testInvalidBestBeforeDateAndLocationAreRefusedWithoutErasingStoredValues(): void
	{
		$entryId = self::seedStockRow(['best_before_date' => '2030-01-01', 'location_id' => self::$pantryId]);
		$before = self::stockRow($entryId);
		$logsBefore = self::stockLogCount();

		// The audit's own M19 reproduction body (issue #487's api.php, issue #519).
		$response = self::put($entryId, [
			'amount' => 1,
			'open' => false,
			'purchased_date' => '2026-09-01',
			'best_before_date' => 'garbage',
			'location_id' => 'garbage',
		]);

		self::assertSame(400, $response['status'], 'an unreadable best_before_date/location_id is refused, not accepted');

		$after = self::stockRow($entryId);
		self::assertSame($before['best_before_date'], $after['best_before_date'], 'the stored due date survives an invalid supplied value (issue #519)');
		self::assertSame($before['location_id'], $after['location_id'], 'the stored location survives an invalid supplied value (issue #519)');
		self::assertSame($before['amount'], $after['amount'], 'nothing about the entry changed on refusal');
		self::assertSame($logsBefore, self::stockLogCount(), 'a refused edit writes no ledger rows');
	}

	public function testExplicitNullIsRefusedNotTreatedAsKeepOrClear(): void
	{
		$entryId = self::seedStockRow(['open' => 1, 'best_before_date' => '2030-01-01']);
		$before = self::stockRow($entryId);

		$response = self::put($entryId, ['amount' => 1, 'open' => null, 'best_before_date' => null]);

		self::assertSame(400, $response['status'], 'an explicit null is not a documented value for open or best_before_date');

		$after = self::stockRow($entryId);
		self::assertSame($before['open'], $after['open'], 'an explicit null must not close the entry');
		self::assertSame($before['best_before_date'], $after['best_before_date'], 'an explicit null must not clear the due date');
	}

	public function testOpenAsTheStringFalseIsRefusedNotReadAsTrue(): void
	{
		$entryId = self::seedStockRow(['open' => 0]);

		$response = self::put($entryId, ['amount' => 1, 'open' => 'false']);

		self::assertSame(400, $response['status'], 'a string is not a documented boolean');
		self::assertSame(0, (int)self::stockRow($entryId)['open'], 'boolval("false") is true in PHP - "false" must not open the entry (issue #524)');
	}

	public function testNonexistentLocationAndShoppingLocationAreRefused(): void
	{
		$entryId = self::seedStockRow();
		$before = self::stockRow($entryId);

		$badLocation = self::put($entryId, ['amount' => 1, 'location_id' => 999999]);
		self::assertSame(400, $badLocation['status'], 'a location_id naming no row is refused');

		$badShoppingLocation = self::put($entryId, ['amount' => 1, 'shopping_location_id' => 999999]);
		self::assertSame(400, $badShoppingLocation['status'], 'a shopping_location_id naming no row is refused');

		$after = self::stockRow($entryId);
		self::assertSame($before['location_id'], $after['location_id'], 'the refused location edit changed nothing');
		self::assertSame($before['shopping_location_id'], $after['shopping_location_id'], 'the refused shopping location edit changed nothing');
	}

	// ------------------------------------------------------------------------------
	// A fully-specified edit still works end to end
	// ------------------------------------------------------------------------------

	public function testFullySpecifiedEditUpdatesEveryField(): void
	{
		$entryId = self::seedStockRow(['open' => 0, 'opened_date' => null]);

		$response = self::put($entryId, [
			'amount' => 7,
			'best_before_date' => '2031-12-25',
			'purchased_date' => '2026-02-02',
			'price' => 9.99,
			'open' => true,
			'location_id' => self::$shelfId,
			'shopping_location_id' => self::$grocerId,
			'note' => 'edited note',
		]);

		self::assertSame(200, $response['status'], $response['body']);

		$after = self::stockRow($entryId);
		self::assertSame(7.0, (float)$after['amount']);
		self::assertSame('2031-12-25', $after['best_before_date']);
		self::assertSame('2026-02-02', $after['purchased_date']);
		self::assertSame(9.99, (float)$after['price']);
		self::assertSame(1, (int)$after['open']);
		self::assertSame(date('Y-m-d'), $after['opened_date'], 'opening the entry through this edit stamps today');
		self::assertSame(self::$shelfId, (int)$after['location_id']);
		self::assertSame(self::$grocerId, (int)$after['shopping_location_id']);
		self::assertSame('edited note', $after['note']);
	}

	// ------------------------------------------------------------------------------
	// H3 (issue #492): EditStockEntry() refuses a negative amount atomically, at the
	// service level, for every caller - not only this HTTP route.
	// ------------------------------------------------------------------------------

	public function testServiceRefusesANegativeAmountAtomically(): void
	{
		$entryId = self::seedStockRow(['amount' => 2]);
		$before = self::stockRow($entryId);
		$logsBefore = self::stockLogCount();

		$caught = null;
		try
		{
			StockService::GetInstance()->EditStockEntry(
				$entryId,
				-5,
				$before['best_before_date'],
				(int)$before['location_id'],
				(int)$before['shopping_location_id'],
				$before['price'],
				(bool)$before['open'],
				$before['purchased_date'],
				$before['note']
			);
		}
		catch (\Exception $ex)
		{
			$caught = $ex;
		}

		self::assertNotNull($caught, 'a negative amount must be refused, not persisted (issue #492, audit finding H3)');
		self::assertSame($before, self::stockRow($entryId), 'a refused edit leaves the row exactly as it was');
		self::assertSame($logsBefore, self::stockLogCount(), 'a refused edit writes no ledger rows');
	}

	public function testServiceStillAcceptsAZeroAmount(): void
	{
		$entryId = self::seedStockRow(['amount' => 2]);
		$before = self::stockRow($entryId);

		StockService::GetInstance()->EditStockEntry(
			$entryId,
			0,
			$before['best_before_date'],
			(int)$before['location_id'],
			(int)$before['shopping_location_id'],
			$before['price'],
			(bool)$before['open'],
			$before['purchased_date'],
			$before['note']
		);

		self::assertSame(0.0, (float)self::stockRow($entryId)['amount'], 'zero-amount behaviour is unchanged by the H3 fix - a separate, undecided question');
	}
}
