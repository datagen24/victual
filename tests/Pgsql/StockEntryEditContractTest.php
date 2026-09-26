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
			. "SELECT 9700, id FROM permission_hierarchy WHERE name IN ('STOCK_EDIT', 'STOCK_VIEW', 'STOCK_PRICES_VIEW')");

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

	/** Sends $method to $path (with an optional body) through the real middleware stack. @return array{status: int, body: string} */
	private static function send(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$key], 'body' => $body],
			fn ($value) => $value !== null
		);
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
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** PUTs $body to /api/stock/entry/$entryId. @return array{status: int, body: string} */
	private static function put(int $entryId, array $body): array
	{
		return self::send('PUT', "/api/stock/entry/$entryId", $body);
	}

	/** GETs /api/stock/entry/$entryId and returns its decoded body. Asserts 200. */
	private static function getEntry(int $entryId): array
	{
		$response = self::send('GET', "/api/stock/entry/$entryId");
		self::assertSame(200, $response['status'], $response['body']);

		return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
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

	public function testInactiveLocationIsRefused(): void
	{
		$inactiveLocationId = (int)self::$db->query("INSERT INTO locations (name, active) VALUES ('EditInactive', 0) RETURNING id")->fetchColumn();
		$entryId = self::seedStockRow();
		$before = self::stockRow($entryId);

		$response = self::put($entryId, ['amount' => 1, 'location_id' => $inactiveLocationId]);

		self::assertSame(400, $response['status'], 'an inactive location is refused, the same as one that does not exist at all');
		self::assertSame($before['location_id'], self::stockRow($entryId)['location_id'], 'the refused edit changed nothing');
	}

	// ------------------------------------------------------------------------------
	// Clearing a field: null for price/shopping_location_id/note, and "" for the store
	// too, matching the web form's own "no store" option (issue #487 review of #519/#524)
	// ------------------------------------------------------------------------------

	public function testNullClearsPriceStoreAndNote(): void
	{
		$entryId = self::seedStockRow(['price' => 4.5, 'shopping_location_id' => self::$grocerId, 'note' => 'to be cleared']);

		$response = self::put($entryId, ['amount' => 1, 'price' => null, 'shopping_location_id' => null, 'note' => null]);

		self::assertSame(200, $response['status'], "null must clear price/store/note, not be refused: {$response['body']}");
		$after = self::stockRow($entryId);
		self::assertNull($after['price'], 'null clears the price');
		self::assertNull($after['shopping_location_id'], 'null clears the store');
		self::assertNull($after['note'], 'null clears the note');
	}

	public function testEmptyStringAlsoClearsTheStore(): void
	{
		$entryId = self::seedStockRow(['shopping_location_id' => self::$grocerId]);

		// The web form's "no store" combobox option sends "" (public/viewjs/stockentryform.js).
		$response = self::put($entryId, ['amount' => 1, 'shopping_location_id' => '']);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertNull(self::stockRow($entryId)['shopping_location_id'], '"" clears the store the same way null does');
	}

	// ------------------------------------------------------------------------------
	// open accepts every form this API's own callers send (its GET response included),
	// and refuses the two word strings that used to be silently misread
	// ------------------------------------------------------------------------------

	public function testOpenAcceptsIntegerAndDigitStringForms(): void
	{
		$closed = self::seedStockRow(['open' => 0]);
		self::assertSame(200, self::put($closed, ['amount' => 1, 'open' => 1])['status'], 'open: 1 (integer) must be accepted');
		self::assertSame(1, (int)self::stockRow($closed)['open']);

		$closed2 = self::seedStockRow(['open' => 0]);
		self::assertSame(200, self::put($closed2, ['amount' => 1, 'open' => '1'])['status'], 'open: "1" (string) must be accepted');
		self::assertSame(1, (int)self::stockRow($closed2)['open']);

		$open = self::seedStockRow(['open' => 1]);
		self::assertSame(200, self::put($open, ['amount' => 1, 'open' => 0])['status'], 'open: 0 (integer) must be accepted');
		self::assertSame(0, (int)self::stockRow($open)['open']);

		$open2 = self::seedStockRow(['open' => 1]);
		self::assertSame(200, self::put($open2, ['amount' => 1, 'open' => '0'])['status'], 'open: "0" (string) must be accepted');
		self::assertSame(0, (int)self::stockRow($open2)['open']);
	}

	public function testOpenWordStringsAreRefused(): void
	{
		$entryId = self::seedStockRow(['open' => 0]);

		$true = self::put($entryId, ['amount' => 1, 'open' => 'true']);
		self::assertSame(400, $true['status'], 'the word string "true" is not a documented form');

		$false = self::put($entryId, ['amount' => 1, 'open' => 'false']);
		self::assertSame(400, $false['status'], 'the word string "false" is not a documented form');

		self::assertSame(0, (int)self::stockRow($entryId)['open'], 'neither refusal changed the stored flag');
	}

	// ------------------------------------------------------------------------------
	// A round trip: what GET answers is exactly what PUT must accept back, since a
	// generated or hand-written client that reads an entry before editing it does this
	// ------------------------------------------------------------------------------

	public function testGetThenPutRoundTripSucceeds(): void
	{
		$entryId = self::seedStockRow(['price' => 3.25, 'shopping_location_id' => self::$grocerId, 'open' => 1, 'opened_date' => '2026-01-10']);

		$entry = self::getEntry($entryId);

		$response = self::put($entryId, [
			'amount' => $entry['amount'],
			'best_before_date' => $entry['best_before_date'],
			'purchased_date' => $entry['purchased_date'],
			'price' => $entry['price'],
			'open' => $entry['open'],
			'location_id' => $entry['location_id'],
			'shopping_location_id' => $entry['shopping_location_id'],
			'note' => $entry['note'],
		]);

		self::assertSame(200, $response['status'], "a GET response PUT straight back must be accepted, open included (it GETs as an integer): {$response['body']}");
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

	public function testHttpPutRefusesANegativeAmount(): void
	{
		$entryId = self::seedStockRow(['amount' => 2]);
		$before = self::stockRow($entryId);
		$logsBefore = self::stockLogCount();

		$response = self::put($entryId, ['amount' => -5]);

		self::assertSame(400, $response['status'], 'a negative amount is refused over the real HTTP route too');
		self::assertSame($before, self::stockRow($entryId), 'a refused edit leaves the row exactly as it was');
		self::assertSame($logsBefore, self::stockLogCount(), 'a refused edit writes no ledger rows');
	}

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
