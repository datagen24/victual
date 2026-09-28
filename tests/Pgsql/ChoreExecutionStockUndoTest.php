<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #506 (#487 remediation, M6; maintainer decision D1, 2026-09-28, round 2): undoing a
 * chore execution that consumed stock must undo that consumption too, atomically with the
 * chore undo - refusing the whole undo if the stock half cannot be reversed, and never
 * refusing an execution whose consumption cannot be linked (it undoes the chore alone,
 * exactly as before this decision).
 *
 * ChoresService::UndoChoreExecution() derives the link rather than storing it: chores_log
 * carries no transaction_id column (decision D1 rules out a migration), so
 * FindLinkedStockConsumptionTransactionId() matches a still-undone TRANSACTION_TYPE_CONSUME
 * stock_log row by PostgreSQL's own `xmin` system column - the id of the transaction that
 * wrote it - equalling the chores_log row's own `xmin`. Round 1 matched on (product_id,
 * row_created_timestamp) instead; round 2 replaced that after the Opus validator proved it
 * unsound with three probes (see ChoresService::FindLinkedStockConsumptionTransactionId()'s
 * own docblock for the full reasoning and the subtransaction check this relies on):
 *
 * - (a) A chore with product_id set but consume_product_on_execution = 0 never calls
 *   ConsumeProduct() at all, yet the old code still matched on the chore's *current*
 *   product_id regardless of that flag - so an unrelated stranger's consumption of the same
 *   product, in the same second, was reversed instead of nothing.
 * - (b) After the chore's own consumption was undone independently (through the stock
 *   journal directly), a later stranger's consumption of the same product landing in the
 *   same second matched the same stale (product_id, timestamp) pair and was reversed.
 * - (c) TrackChore() always passes allowSubproductSubstitution = true, so a substitution can
 *   leave stock_log.product_id naming the child product actually consumed rather than the
 *   chore's own (parent) product_id - the old product filter then found nothing at all and
 *   the consumption stayed live forever, issue #506's original defect resurfacing under a
 *   different cause.
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

	/**
	 * Forces $table's row_created_timestamp to $timestamp - reproducing, for (a) and (b)
	 * below, the same-second timestamp collision round 1's (product_id, row_created_
	 * timestamp) link was vulnerable to, without depending on real wall-clock timing. The
	 * fixed xmin-based link does not consult this column at all, so this only matters for
	 * demonstrating the round 1 defect on the pre-round-2 commit.
	 */
	private static function forceRowCreatedTimestamp(string $table, int $id, string $timestamp): void
	{
		$statement = self::$db->prepare("UPDATE $table SET row_created_timestamp = ? WHERE id = ?");
		$statement->execute([$timestamp, $id]);
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
	private static function requestThroughHttp(string $method, string $path): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey]];

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
	// 1. A chore execution that consumed stock: undoing it restores the stock and
	//    marks the consumption's own stock_log row(s) undone.
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

		// A later booking against the same (surviving) stock entry - opening it, rather than
		// another consume, so it cannot itself land inside the (product_id, row_created_
		// timestamp) window FindLinkedStockConsumptionTransactionId() matches on and be
		// mistaken for a second execution's own consumption. StockService::UndoBooking()
		// refuses a booking with a "subsequent dependent booking": undoing the chore's own
		// consume first would leave this later booking referencing stock state that no
		// longer exists.
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
	// 3. A chore without stock consumption still undoes.
	// ------------------------------------------------------------------------------

	public function testChoreWithoutStockConsumptionStillUndoes(): void
	{
		$choreId = self::insertChore('Chore Undo No Consumption', [
			'consume_product_on_execution' => 0,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'A chore that never consumed anything still undoes cleanly');
	}

	// ------------------------------------------------------------------------------
	// 4. An unlinkable execution undoes the chore only, with stock unchanged - the
	//    "legacy executions" case decision D1 requires never to refuse.
	// ------------------------------------------------------------------------------

	public function testUnlinkableExecutionUndoesTheChoreOnlyAndLeavesStockUnchanged(): void
	{
		$product = self::insertProduct('Chore Undo Unlinkable Stock');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Unlinkable', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		// A consumption of the chore's own linked product, but booked outside TrackChore()'s
		// transaction (its own separate ConsumeProduct() call, which opens and commits its own
		// transaction since nothing here nests it) - the same shape a pre-#506 execution's
		// booking has, since chores_log never recorded a transaction_id to begin with. It
		// therefore carries a different xmin than the chores_log row inserted below, which is
		// itself a separate statement/transaction.
		self::$stock->ConsumeProduct($product, 2, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertSame(3.0, self::stockAmount($product));

		// A chores_log row for the same chore, inserted directly (not through TrackChore()) -
		// its own separate transaction, so its xmin cannot coincide with the consumption
		// above's - reproducing an execution the deterministic link cannot resolve.
		$executionId = self::insertRow('chores_log', [
			'chore_id' => $choreId,
			'tracked_time' => '2026-09-28 09:00:00',
			'done_by_user_id' => 9000,
		]);

		$stockLogBefore = self::consumeStockLogRowsForProduct($product);
		self::assertSame(0, (int)$stockLogBefore[0]['undone'], 'The unrelated consumption is live before the undo');

		self::$chores->UndoChoreExecution($executionId);

		$choreLog = self::choreLogRow($executionId);
		self::assertSame(1, (int)$choreLog['undone'], 'The chore execution is undone');

		self::assertSame(3.0, self::stockAmount($product), 'Stock is untouched: the consumption could not be linked to this execution');
		$stockLogAfter = self::consumeStockLogRowsForProduct($product);
		self::assertSame($stockLogBefore, $stockLogAfter, 'The unlinkable consumption itself is not modified at all');
	}

	// ------------------------------------------------------------------------------
	// (a) Opus validator probe 1 (round 2): a chore with product_id set but
	//     consume_product_on_execution = 0 never consumes anything itself. An
	//     unrelated stranger's consumption of the same product, in the same second,
	//     must not be reversed - round 1's (product_id, row_created_timestamp) link
	//     never checked the consume flag, or that the match was this execution's own
	//     transaction, and reversed the stranger's booking instead.
	// ------------------------------------------------------------------------------

	public function testUnrelatedSameSecondConsumptionIsNotReversedWhenChoreNeverConsumed(): void
	{
		$product = self::insertProduct('Chore Undo Probe A Product');
		self::stockUp($product, 5);

		// product_id is still set (e.g. left over from before consumption was turned off) -
		// exactly the shape round 1's bug needed: a product to false-match against.
		$choreId = self::insertChore('Chore Undo Probe A', [
			'consume_product_on_execution' => 0,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(5.0, self::stockAmount($product), 'The chore itself consumes nothing (consume_product_on_execution = 0)');

		// The stranger: an unrelated consumption of the same product, in its own separate
		// transaction/statement, its row_created_timestamp then forced to coincide with the
		// chore execution's - reproducing the same-second collision round 1's link was
		// vulnerable to (the xmin-based link does not consult this column at all).
		self::$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertSame(4.0, self::stockAmount($product));
		$strangerBooking = self::consumeStockLogRowsForProduct($product)[0];
		self::forceRowCreatedTimestamp('stock_log', (int)$strangerBooking['id'], self::choreLogRow($executionId)['row_created_timestamp']);
		$strangerBefore = self::consumeStockLogRowsForProduct($product)[0];

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore execution is undone');
		self::assertSame(4.0, self::stockAmount($product), "The stranger's consumption is untouched");
		self::assertSame($strangerBefore, self::consumeStockLogRowsForProduct($product)[0], "The stranger's booking row is byte-for-byte unchanged");
	}

	// ------------------------------------------------------------------------------
	// (b) Opus validator probe 2 (round 2): the chore's own consumption is undone
	//     independently first (through the stock journal, not through the chore undo).
	//     A stranger's consumption then lands in the same second. Undoing the chore
	//     execution afterwards must not reverse the stranger's booking - round 1's link
	//     matched on the chore's product and the same-second timestamp regardless of
	//     which live booking that was, once the chore's own booking was excluded by
	//     already being undone.
	// ------------------------------------------------------------------------------

	public function testUnrelatedSameSecondConsumptionIsNotReversedAfterTheChoresOwnBookingWasAlreadyUndone(): void
	{
		$product = self::insertProduct('Chore Undo Probe B Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Probe B', [
			'consume_product_on_execution' => 1,
			'product_id' => $product,
			'product_amount' => 2,
		]);

		$executionId = self::$chores->TrackChore($choreId, '2026-09-28 09:00:00');
		self::assertSame(3.0, self::stockAmount($product));

		// The chore's own booking is undone directly, through the stock journal - not
		// through ChoresService::UndoChoreExecution().
		$ownBooking = self::consumeStockLogRowsForProduct($product)[0];
		self::$stock->UndoBooking((int)$ownBooking['id']);
		self::assertSame(5.0, self::stockAmount($product), "The chore's own booking is undone independently");

		// The stranger: an unrelated consumption of the same product, again forced to share
		// the chore execution's row_created_timestamp.
		self::$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::assertSame(4.0, self::stockAmount($product));
		$strangerBooking = array_values(array_filter(
			self::consumeStockLogRowsForProduct($product),
			fn ($row) => (int)$row['id'] !== (int)$ownBooking['id']
		))[0];
		self::forceRowCreatedTimestamp('stock_log', (int)$strangerBooking['id'], self::choreLogRow($executionId)['row_created_timestamp']);
		$strangerBefore = array_values(array_filter(
			self::consumeStockLogRowsForProduct($product),
			fn ($row) => (int)$row['id'] === (int)$strangerBooking['id']
		))[0];

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore execution is undone');
		self::assertSame(4.0, self::stockAmount($product), "The stranger's consumption is untouched");
		$strangerAfter = array_values(array_filter(
			self::consumeStockLogRowsForProduct($product),
			fn ($row) => (int)$row['id'] === (int)$strangerBooking['id']
		))[0];
		self::assertSame($strangerBefore, $strangerAfter, "The stranger's booking row is byte-for-byte unchanged");
	}

	// ------------------------------------------------------------------------------
	// (c) Opus validator probe 3 (round 2), issue #506's original defect resurfacing:
	//     TrackChore() always passes allowSubproductSubstitution = true, so the
	//     consumption can land on a child product's stock_log.product_id rather than
	//     the chore's own (parent) product_id. The link must still find it.
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

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(1, (int)self::choreLogRow($executionId)['undone'], 'The chore execution is undone');
		self::assertSame(5.0, self::stockAmount($child), "The substituted child product's stock is restored");
		self::assertSame(1, (int)self::consumeStockLogRowsForProduct($child)[0]['undone'], "The child product's booking is marked undone");
	}

	// ------------------------------------------------------------------------------
	// HTTP-level: a refused stock undo is observable as a 400 through the real route,
	// not only as a thrown exception at the service level (issue #506 round 2's
	// required check).
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
	// (d) Opus validator probe (round 3): DatabaseImporter::Import() writes an entire
	//     imported database in one transaction, so every imported chores_log row
	//     shares its xmin with every imported stock_log row - including consumptions
	//     that belong to a different chore, or none. Simulated here with a raw PDO
	//     transaction wrapping two chores_log inserts and one unrelated consumption,
	//     the same shape an import produces. Neither execution's xmin is exclusive to
	//     it (condition 1), so undoing either one must fall back to a chore-only undo.
	//
	//     Run as two independent scenarios, each undoing only one of its pair, rather
	//     than undoing both of one pair in sequence: undoing the first of a pair
	//     updates its own chores_log row, which changes that row's own xmin (any
	//     UPDATE does) without touching its sibling's - so undoing the *second* of an
	//     already-partly-undone pair would see only one live chores_log row left
	//     sharing the original xmin and misread that as condition 1's exclusivity,
	//     rather than as what it actually is: one execution of an originally-shared
	//     import batch. Each scenario below undoes exactly one execution while its
	//     sibling chores_log row is still untouched, so condition 1 sees the shared
	//     xmin honestly, in both directions.
	// ------------------------------------------------------------------------------

	public function testImporterStyleSharedTransactionFallsBackToChoreOnlyUndo(): void
	{
		// Scenario 1: undo the first of an imported pair.
		$productFirst = self::insertProduct('Chore Undo Probe D Product First');
		self::stockUp($productFirst, 5);
		$choreFirstA = self::insertChore('Chore Undo Probe D First A', ['consume_product_on_execution' => 0]);
		$choreFirstB = self::insertChore('Chore Undo Probe D First B', ['consume_product_on_execution' => 0]);

		// A single transaction writing two chores_log rows and one unrelated consumption -
		// self::$db is the same raw PDO connection DatabaseService::InTransaction() joins
		// (PgsqlSchemaTestCase injects it by reflection), so ConsumeProduct()'s own nested
		// InTransaction() call below joins this one rather than opening its own, exactly as
		// it would join DatabaseImporter's.
		self::$db->beginTransaction();
		$executionFirstA = self::insertRow('chores_log', ['chore_id' => $choreFirstA, 'tracked_time' => '2026-09-28 09:00:00', 'done_by_user_id' => 9000]);
		self::insertRow('chores_log', ['chore_id' => $choreFirstB, 'tracked_time' => '2026-09-28 09:00:00', 'done_by_user_id' => 9000]);
		self::$stock->ConsumeProduct($productFirst, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::$db->commit();

		self::assertSame(4.0, self::stockAmount($productFirst), 'The unrelated consumption, written alongside both chores_log rows');

		self::$chores->UndoChoreExecution($executionFirstA);
		self::assertSame(4.0, self::stockAmount($productFirst), 'Undoing the first of the pair must not touch the unrelated consumption');
		self::assertSame(1, (int)self::choreLogRow($executionFirstA)['undone']);

		// Scenario 2: undo the second of an (otherwise untouched) imported pair.
		$productSecond = self::insertProduct('Chore Undo Probe D Product Second');
		self::stockUp($productSecond, 5);
		$choreSecondA = self::insertChore('Chore Undo Probe D Second A', ['consume_product_on_execution' => 0]);
		$choreSecondB = self::insertChore('Chore Undo Probe D Second B', ['consume_product_on_execution' => 0]);

		self::$db->beginTransaction();
		self::insertRow('chores_log', ['chore_id' => $choreSecondA, 'tracked_time' => '2026-09-28 09:00:00', 'done_by_user_id' => 9000]);
		$executionSecondB = self::insertRow('chores_log', ['chore_id' => $choreSecondB, 'tracked_time' => '2026-09-28 09:00:00', 'done_by_user_id' => 9000]);
		self::$stock->ConsumeProduct($productSecond, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::$db->commit();

		self::assertSame(4.0, self::stockAmount($productSecond));

		self::$chores->UndoChoreExecution($executionSecondB);
		self::assertSame(4.0, self::stockAmount($productSecond), 'Undoing the second of the pair (sibling still untouched) must not touch the unrelated consumption');
		self::assertSame(1, (int)self::choreLogRow($executionSecondB)['undone']);
	}

	// ------------------------------------------------------------------------------
	// (e) Round 3, condition 2 (SINGLE CONSUMPTION TRANSACTION): one chores_log row
	//     sharing its xmin with two distinct consumption transaction_ids (two separate
	//     ConsumeProduct() calls joined into one explicit transaction, the same shape a
	//     composed operation or an importer could produce) must fall back to a
	//     chore-only undo rather than guess which transaction_id is this execution's
	//     own.
	// ------------------------------------------------------------------------------

	public function testSharedTransactionWithTwoConsumptionTransactionIdsFallsBackToChoreOnlyUndo(): void
	{
		$product = self::insertProduct('Chore Undo Probe E Product');
		self::stockUp($product, 5);

		$choreId = self::insertChore('Chore Undo Probe E', ['consume_product_on_execution' => 0]);

		self::$db->beginTransaction();
		$executionId = self::insertRow('chores_log', ['chore_id' => $choreId, 'tracked_time' => '2026-09-28 09:00:00', 'done_by_user_id' => 9000]);
		// Two separate ConsumeProduct() calls, each generating its own transaction_id
		// (neither passes an existing one in), joined into this one explicit transaction.
		self::$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
		self::$db->commit();

		self::assertSame(3.0, self::stockAmount($product), 'Two separate one-unit consumptions, sharing the one transaction');

		self::$chores->UndoChoreExecution($executionId);

		self::assertSame(3.0, self::stockAmount($product), 'Chore-only undo: neither consumption transaction_id is touched');
		self::assertSame(1, (int)self::choreLogRow($executionId)['undone']);
	}
}
