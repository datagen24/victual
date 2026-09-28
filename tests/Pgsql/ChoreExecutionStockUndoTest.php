<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #506 (#487 remediation, M6; maintainer decision D1, 2026-09-28): undoing a chore
 * execution that consumed stock must undo that consumption too, atomically with the chore
 * undo - refusing the whole undo if the stock half cannot be reversed, and never refusing an
 * execution whose consumption cannot be linked (it undoes the chore alone, exactly as before
 * this decision).
 *
 * The link itself is chores_log.stock_transaction_id (migrations/0291.pgsql.sql, maintainer
 * decision D5, round 4): ChoresService::TrackChore() records the transaction_id its own
 * consumption booked, in the same database transaction, directly on the row it just inserted.
 * Two earlier rounds tried to derive the link after the fact instead of storing it, and a
 * validator broke each one:
 *
 * - Round 2 matched on (product_id, row_created_timestamp): a chore with product_id set but
 *   consume_product_on_execution = 0 never calls ConsumeProduct() at all, yet the match still
 *   fired on an unrelated stranger's same-second consumption of that product; after the
 *   chore's own booking was undone independently, a later stranger's consumption could match
 *   the same stale pair too; and TrackChore()'s allowSubproductSubstitution = true can leave
 *   stock_log.product_id naming a child product, which a product filter never finds.
 * - Round 3 matched on PostgreSQL's `xmin` system column instead, with three added
 *   conditions (exclusive writer, single consumption transaction, same second). A further
 *   probe showed `xmin` identifies a whole database transaction, not one business operation
 *   within it: DatabaseImporter::Import() writes an entire imported database in one
 *   transaction, so every imported chores_log row shared an `xmin` with every imported
 *   stock_log row, and no combination of conditions on top of `xmin` closed that for good.
 *
 * An explicit column has neither failure mode. See ChoresService::UndoChoreExecution()'s own
 * docblock for the production-side reasoning.
 *
 * Follows ComposedOperationAtomicityTest.php's own pattern for this same service: call
 * ChoresService/StockService directly (no HTTP transport) for the row-level assertions, and
 * request-subprocess-helper.php (the same harness) for the one case that needs to observe an
 * HTTP status. Assert on `stock`/`stock_log`/`chores_log` rows rather than only a return
 * value or response shape, and verify a refusal leaves every row byte-for-byte unchanged.
 */
class ChoreExecutionStockUndoTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static StockService $stock;
	private static int $location;
	private static string $apiKey = '';
	private static string $limitedApiKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'chore-stock-undo-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$chores = ChoresService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$location = self::insertRow('locations', ['name' => 'Chore Stock Undo Location']);

		// For the HTTP-level refusal case only (see class docblock) - matching
		// ComposedOperationAtomicityTest.php's own subprocess-driven API user, admin for
		// simplicity.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9602, 'chore-stock-undo-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9602, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9602, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);

		// A second HTTP-level user granted CHORE_UNDO_EXECUTION only, never STOCK_EDIT -
		// for the authorization case (issue #506, CWE-863): CHORE_UNDO_EXECUTION alone
		// authorizes undoing the chore itself, not reversing stock.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9603, 'chore-stock-undo-limited-api', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9603, id FROM permission_hierarchy WHERE name = ?');
		$grant->execute(['CHORE_UNDO_EXECUTION']);
		self::$limitedApiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9603, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$limitedApiKey), substr(self::$limitedApiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring ComposedOperationAtomicityTest.php's own)
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$location,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	private static function insertChore(string $name, array $columns = []): int
	{
		return self::insertRow('chores', array_merge([
			'name' => $name,
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
		], $columns));
	}

	/** A sub product of $parentId (products.parent_product_id) - see products_resolved. */
	private static function insertChildProduct(string $name, int $parentId): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => self::$location,
			'parent_product_id' => $parentId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, '2035-06-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-04-01', 1.0);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	/** Every column of every `stock`, `stock_log` and `chores_log` row, in id order. */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'chores_log' => self::$db->query('SELECT * FROM chores_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/** Only the CONSUME-type stock_log rows of a product - excludes the purchase booking stockUp() writes. */
	private static function consumeStockLogRowsForProduct(int $productId): array
	{
		$statement = self::$db->prepare("SELECT * FROM stock_log WHERE product_id = ? AND transaction_type = 'consume' ORDER BY id");
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function choreLogRow(int $executionId): array
	{
		$statement = self::$db->prepare('SELECT * FROM chores_log WHERE id = ?');
		$statement->execute([$executionId]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * One request through request-subprocess-helper.php's whole middleware stack - the only
	 * way to observe an HTTP status this class needs (issue #506 round 2's required check).
	 * Copies ComposedOperationAtomicityTest::requestWithInfluxEnabled()'s own shape, minus
	 * the INFLUXDB_ENABLED env var this case has no use for.
	 *
	 * @return array{status: int, body: mixed, stderr: string}
	 */
	private static function requestThroughHttp(string $method, string $path, ?string $apiKey = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey ?? self::$apiKey]];

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

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	// ------------------------------------------------------------------------------
	// 1. A chore execution that consumed stock: TrackChore() records the booking's own
	//    transaction_id on the chores_log row, and undoing the execution restores the
	//    stock and marks that booking undone.
	// ------------------------------------------------------------------------------

	public function testUndoingChoreExecutionThatConsumedStockRestoresStockAndMarksBookingUndone(): void
	{
		$product = self::insertProduct('Chore Undo Consumed Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Restores Stock', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::assertSame(3.0, self::stockAmount($product), 'The chore consumed 2 of the 5 units on execution');
		$bookedRows = self::consumeStockLogRowsForProduct($product);
		self::assertCount(1, $bookedRows, 'Exactly one consume booking was written');
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $bookedRows[0]['transaction_type']);
		self::assertSame(0, (int)$bookedRows[0]['undone'], 'The booking is live before the undo');

		$choreLogBeforeUndo = self::choreLogRow($executionId);
		self::assertNotEmpty($choreLogBeforeUndo['stock_transaction_id'], 'TrackChore() recorded the link (decision D5)');
		self::assertSame($bookedRows[0]['transaction_id'], $choreLogBeforeUndo['stock_transaction_id'], "The recorded link names this execution's own booking");

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(5.0, self::stockAmount($product), 'Undoing the execution restores the consumed stock');

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'The chore execution itself is marked undone');

		$rowsAfter = self::consumeStockLogRowsForProduct($product);
		self::assertCount(1, $rowsAfter, 'The undo reverses the existing booking rather than adding a new row for this whole-row case');
		self::assertSame(1, (int)$rowsAfter[0]['undone'], 'The original consume booking is now marked undone');
	}

	// ------------------------------------------------------------------------------
	// 2. A refused stock undo leaves both the chore and the stock unchanged.
	// ------------------------------------------------------------------------------

	public function testARefusedStockUndoLeavesBothTheChoreAndTheStockUnchanged(): void
	{
		$product = self::insertProduct('Chore Undo Refused Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Refused', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(3.0, self::stockAmount($product), 'The chore consumed 2 of the 5 units on execution (partial take, entry survives)');

		// A later booking against the same (surviving) stock entry. StockService::
		// UndoBooking() refuses a booking with a "subsequent dependent booking": undoing
		// the chore's own consume first would leave this later booking referencing stock
		// state that no longer exists.
		self::$stock->OpenProduct($product, 3);
		self::assertSame(3.0, self::stockAmount($product), 'Opening the remaining entry does not itself change the on-hand amount');

		$before = self::ledger();

		try
		{
			self::$chores->UndoChoreExecution($executionId);
			self::fail('Undoing an execution whose booking has a later dependent booking must refuse');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('subsequent dependent bookings', $exception->getMessage());
		}

		self::assertSame($before, self::ledger(), 'The refusal leaves the chore log, stock and stock_log byte-for-byte unchanged');
	}

	// ------------------------------------------------------------------------------
	// 3. A chore without stock consumption still undoes, and TrackChore() leaves
	//    stock_transaction_id NULL for it (nothing was ever booked to link).
	// ------------------------------------------------------------------------------

	public function testChoreWithoutStockConsumptionStillUndoes(): void
	{
		$choreId = self::insertChore('Chore Undo No Consumption', [
			'consume_product_on_execution' => 0,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::assertNull(self::choreLogRow($executionId)['stock_transaction_id'], 'Nothing was consumed, so nothing is linked');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'A chore that never consumed anything still undoes cleanly');
	}

	// ------------------------------------------------------------------------------
	// (a) A chore with consume_product_on_execution = 0 (product_id still set, e.g.
	//     left over from before consumption was turned off) tracked in the very same
	//     database transaction as an unrelated stranger's consumption of that same
	//     product. Undoing the chore must not touch the stranger's booking.
	//
	//     This is exactly the shape that defeated both derived-link designs in rounds 2
	//     and 3 (a shared transaction with no consumption of the chore's own): an
	//     explicit column sidesteps it entirely, because TrackChore() only ever writes
	//     stock_transaction_id when *it itself* calls ConsumeProduct() - never as a side
	//     effect of anything else that happens to share its transaction.
	// ------------------------------------------------------------------------------

	public function testStrangerConsumptionInTheSameTransactionIsNotReversedWhenTheChoreNeverConsumes(): void
	{
		$product = self::insertProduct('Chore Undo Probe A Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Probe A', [
			'consume_product_on_execution' => 0,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		// self::$db is the same raw PDO connection DatabaseService::InTransaction() joins
		// (PgsqlSchemaTestCase injects it by reflection): TrackChore()'s own InTransaction()
		// call below joins this one rather than opening its own, and so does the stranger's
		// ConsumeProduct() - both land inside one shared transaction, deliberately.
		self::$db->beginTransaction();
		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::$db->commit();

		self::assertSame(4.0, self::stockAmount($product), "The stranger's consumption, in the same transaction as the chore's own tracking");
		self::assertNull(self::choreLogRow($executionId)['stock_transaction_id'], 'consume_product_on_execution = 0: nothing is linked, whatever else shared the transaction');

		$strangerBefore = self::consumeStockLogRowsForProduct($product)[0];

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore execution is undone');
		self::assertSame(4.0, self::stockAmount($product), "The stranger's consumption is untouched");
		self::assertSame($strangerBefore, self::consumeStockLogRowsForProduct($product)[0], "The stranger's booking row is byte-for-byte unchanged");
	}

	// ------------------------------------------------------------------------------
	// (b) An execution with a NULL stock_transaction_id - a chores_log row inserted
	//     directly (not through TrackChore()), the shape a legacy row predating this
	//     column, or one an import brought in from another database, both have -
	//     undoes the chore only, leaving an unrelated live consumption of the same
	//     product untouched.
	// ------------------------------------------------------------------------------

	public function testNullStockTransactionIdUndoesTheChoreOnlyAndLeavesStockUnchanged(): void
	{
		$product = self::insertProduct('Chore Undo Probe B Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Probe B', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		// An unrelated, live consumption of the chore's own product - present so the test
		// proves the NULL link leaves it alone, not merely that nothing else exists to
		// touch.
		self::$stock->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertSame(3.0, self::stockAmount($product));

		// A chores_log row for the same chore, inserted directly - stock_transaction_id
		// defaults to NULL, exactly like a row written before this column existed or one
		// carried in by an import.
		$executionId = self::insertRow('chores_log', [
			'chore_id' => $choreId,
			'tracked_time' => '2026-09-28 09:00:00',
			'done_by_user_id' => 9000,
		]);
		self::assertNull(self::choreLogRow($executionId)['stock_transaction_id']);

		$stockLogBefore = self::consumeStockLogRowsForProduct($product);
		self::assertSame(0, (int)$stockLogBefore[0]['undone'], 'The unrelated consumption is live before the undo');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'The chore execution is undone');

		self::assertSame(3.0, self::stockAmount($product), 'Stock is untouched: nothing was linked to this execution');
		$stockLogAfter = self::consumeStockLogRowsForProduct($product);
		self::assertSame($stockLogBefore, $stockLogAfter, 'The unrelated consumption itself is not modified at all');
	}

	// ------------------------------------------------------------------------------
	// (b, round 5) A stock_transaction_id that is set, but whose booking(s) were
	// already undone independently through the stock journal - not through
	// ChoresService::UndoChoreExecution() - before the chore itself was undone. Calling
	// StockService::UndoTransaction() again refuses ("This transaction was not found or
	// already undone"), which without this fix left the chore permanently stuck at
	// undone = 0: every retry hit the same refusal over stock that was already back the
	// way it was. The chore undo must instead succeed as chore-only.
	// ------------------------------------------------------------------------------

	public function testUndoingAnExecutionWhoseStockTransactionWasAlreadyFullyUndoneStillUndoesTheChore(): void
	{
		$product = self::insertProduct('Chore Undo Probe B2 Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Probe B2', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(3.0, self::stockAmount($product));

		$transactionId = self::choreLogRow($executionId)['stock_transaction_id'];
		self::assertNotEmpty($transactionId);

		// The stock side is undone directly, through the stock journal - not through
		// ChoresService::UndoChoreExecution() - exactly the sequence issue #506 round 5
		// found: StockService::UndoTransaction() leaves no live booking behind for
		// $transactionId once this returns.
		self::$stock->UndoTransaction($transactionId);
		self::assertSame(5.0, self::stockAmount($product), 'The stock is already restored, independently of the chore');

		$stockLogBefore = self::consumeStockLogRowsForProduct($product);
		self::assertSame(1, (int)$stockLogBefore[0]['undone'], 'The booking is already undone before the chore undo runs');

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore itself is undone - not stuck refusing forever');
		self::assertSame(5.0, self::stockAmount($product), "The chore's own undo does not change stock a second time");
		self::assertSame($stockLogBefore, self::consumeStockLogRowsForProduct($product), 'The already-undone booking is not touched again');
	}

	// ------------------------------------------------------------------------------
	// (c) Sub-product substitution: TrackChore() always passes
	//     allowSubproductSubstitution = true, so the consumption can land on a child
	//     product's stock_log.product_id rather than the chore's own (parent)
	//     product_id. The explicit link does not care which product was actually
	//     booked - it names the transaction_id ConsumeProduct() returned, whatever
	//     product ended up in stock_log.
	// ------------------------------------------------------------------------------

	public function testSubProductSubstitutionConsumptionIsStillLinkedAndRestored(): void
	{
		$parent = self::insertProduct('Chore Undo Probe C Parent');
		$child = self::insertChildProduct('Chore Undo Probe C Child', $parent);
		self::stockUp($child, 5);
		self::assertSame(0.0, self::stockAmount($parent), 'The parent product itself holds no stock');

		$choreId = self::insertChore('Chore Undo Probe C', [
			'consume_product_on_execution' => 1,
			'product_id' => $parent,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::assertSame(3.0, self::stockAmount($child), 'Substitution consumed the child product, the only one holding stock');
		$booking = self::consumeStockLogRowsForProduct($child)[0];
		self::assertSame($child, (int)$booking['product_id'], "The booking's product_id is the child, not the chore's own product_id");
		self::assertSame(0, (int)$booking['undone']);
		self::assertSame($booking['transaction_id'], self::choreLogRow($executionId)['stock_transaction_id'], 'The link names the booked transaction_id regardless of which product it consumed');

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore execution is undone');
		self::assertSame(5.0, self::stockAmount($child), "The substituted child product's stock is restored");
		self::assertSame(1, (int)self::consumeStockLogRowsForProduct($child)[0]['undone'], "The child product's booking is marked undone");
	}

	// ------------------------------------------------------------------------------
	// HTTP-level: a refused stock undo is observable as a 400 through the real route,
	// not only as a thrown exception at the service level.
	// ------------------------------------------------------------------------------

	public function testUndoingAnExecutionWithARefusedStockUndoRespondsWithHttp400(): void
	{
		$product = self::insertProduct('Chore Undo HTTP Refusal Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo HTTP Refusal', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::$stock->OpenProduct($product, 3);

		$before = self::ledger();

		$result = self::requestThroughHttp('POST', "/api/chores/executions/$executionId/undo");

		self::assertSame(400, $result['status'], "the undo route should refuse: {$result['stderr']}");
		self::assertSame($before, self::ledger(), 'The refused HTTP request leaves the chore log, stock and stock_log unchanged');
	}

	// ------------------------------------------------------------------------------
	// Authorization (CWE-863, CodeRabbit finding on this PR): CHORE_UNDO_EXECUTION
	// alone authorizes undoing the chore, not reversing stock. A caller who holds only
	// CHORE_UNDO_EXECUTION must be refused (403) on an execution whose undo would also
	// reverse a live linked stock booking - with nothing changed - but must still be
	// able to undo a chore-only execution, the same permission split the recipe consume
	// route already applies (STOCK_PURCHASE on top of STOCK_CONSUME, issue #532/#581).
	// ------------------------------------------------------------------------------

	public function testUndoingALinkedExecutionOverHttpWithoutStockEditIsRefusedButChoreOnlyIsNotRequired(): void
	{
		$product = self::insertProduct('Chore Undo Authz Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Authz Linked', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(3.0, self::stockAmount($product));

		$before = self::ledger();

		$result = self::requestThroughHttp('POST', "/api/chores/executions/$executionId/undo", self::$limitedApiKey);

		self::assertSame(403, $result['status'], "CHORE_UNDO_EXECUTION alone must not reverse stock: {$result['stderr']}");
		self::assertSame($before, self::ledger(), 'The refused request leaves the chore log, stock and stock_log unchanged');

		// The same limited caller can still undo a chore-only execution (no live linked
		// booking) - CHORE_UNDO_EXECUTION alone remains sufficient for that, exactly as
		// before this authorization check existed.
		$plainChoreId = self::insertChore('Chore Undo Authz Plain', [
			'consume_product_on_execution' => 0,
		]);
		$plainExecutionId = self::$chores->TrackChore($plainChoreId, '2026-09-28 09:00:00');

		$plainResult = self::requestThroughHttp('POST', "/api/chores/executions/$plainExecutionId/undo", self::$limitedApiKey);

		self::assertSame(204, $plainResult['status'], "a chore-only undo needs no STOCK_EDIT: {$plainResult['stderr']}");
		self::assertSame(1, (int)self::choreLogRow($plainExecutionId)['undone'], 'The chore-only execution is undone');
	}
}
