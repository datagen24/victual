<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #487 workstream 1 (undo integrity): regressions for #489 C2 (transfer undo
 * negative rows / positive consume escalation), #470 (transfer undo float residue),
 * #522 M22 (opened state lost across transfer undo), #504 M4 (null-purchased-date open
 * undo, and TRANSFER null-location matching), and #488 C1 (edit/open undo after
 * CompactStockEntries() merges the entry with another live contribution).
 *
 * Kept in its own file/class rather than appended to StockCoverageTest.php, per this
 * workstream's reservation - that file is large and other agents work near its end.
 *
 * Follows StockCoverageTest.php's own pattern: call the controllers directly (no HTTP
 * transport), assert on `stock`/`stock_log` rows rather than only response shape, and
 * verify a refusal leaves every row byte-for-byte unchanged.
 */
class StockUndoIntegrityTest extends PgsqlSchemaTestCase
{
	private const FAR_FUTURE_DATE = '2035-06-30';

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $locationA;
	private static int $locationB;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Every BaseService subclass (StockService, UsersService, ApiKeyService, ...)
		// caches its GetInstance() singleton - and the LessQL connection wrapper captured
		// at its first construction - for the life of the PHP process
		// (BaseService::$Instances). This testsuite now names two PgsqlSchemaTestCase
		// classes that both touch StockService (this file and StockCoverageTest.php,
		// which runs first): without this reset, StockService::GetInstance() here would
		// return StockCoverageTest's already-constructed singleton, still bound to the
		// schema its own tearDownAfterClass() already dropped ("relation products does
		// not exist"). Clearing the cache forces every service singleton to be
		// reconstructed against this class's own connection instead - the same reflection
		// technique PgsqlSchemaTestCase already applies to DatabaseService's own two
		// connection properties, extended to the per-service singletons it does not reset.
		(new \ReflectionProperty(\Victual\Services\BaseService::class, 'Instances'))->setValue(null, []);

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		// VICTUAL_USER_ID (the identity direct controller calls act as, per
		// PgsqlSchemaTestCase::Boot()) defaults to 9000 - matching StockCoverageTest's
		// and StockConcurrencyTest's own fixture user.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'undointegrity-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Undo Integrity A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Undo Integrity B']);
		self::$locationB = (int)$location->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring StockCoverageTest.php's own)
	// ------------------------------------------------------------------------------

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	/** Calls $work, recovering a thrown HttpException into its status, and asserts the status. */
	private function expectStatus(callable $work, int $expected, string $message): array
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
			$body = (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
			$body = $exception->getMessage();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual ($body)");
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : ['error_message' => $body];
	}

	/** Calls $work without asserting a specific status - for scenarios accepting more than one truthful outcome. */
	private function respond(callable $work): array
	{
		try
		{
			$response = $work();
			return ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true)];
		}
		catch (HttpException $exception)
		{
			return ['status' => $exception->getCode(), 'body' => $exception->getMessage()];
		}
	}

	/** Asserts $work is refused with $status AND that it wrote nothing to the ledger. */
	private function expectRefusalWithUntouchedLedger(callable $work, int $status, string $message): array
	{
		$before = self::ledger();
		$decoded = $this->expectStatus($work, $status, $message);
		self::assertSame($before, self::ledger(), "$message: the refusal must leave stock and stock_log unchanged");
		return $decoded;
	}

	/** Every column of every `stock` and `stock_log` row, in id order, as JSON. */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationA]);

		return (int)$statement->fetchColumn();
	}

	/** Every `stock` row of a product, in id order. */
	private static function rows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		return (float)$statement->fetchColumn();
	}

	private static function stockAmountAtLocation(int $productId, int $locationId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$statement->execute([$productId, $locationId]);
		return (float)$statement->fetchColumn();
	}

	private static function openedAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND open = 1');
		$statement->execute([$productId]);
		return (float)$statement->fetchColumn();
	}

	/**
	 * Purchases two entries that match on every CompactStockEntries() grouping column
	 * except their due date, edits one of their due dates to match the other (triggering
	 * EditStockEntry()'s compaction call), and returns the product id and the resulting
	 * STOCK_EDIT_OLD booking id. $editWhich selects which of the two purchases (by
	 * insertion order) is edited, so both row-id orders relative to whichever row
	 * CompactStockEntries() keeps can be exercised (#488 C1).
	 */
	private function purchaseEditAndCompact(string $productName, float $firstAmount, string $firstDue, float $secondAmount, string $secondDue, string $editWhich): array
	{
		$product = self::insertProduct($productName);
		$purchasedDate = '2026-01-01';
		$price = 1.5;

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => $firstAmount, 'best_before_date' => $firstDue, 'purchased_date' => $purchasedDate, 'price' => $price, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'The first entry is purchased'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => $secondAmount, 'best_before_date' => $secondDue, 'purchased_date' => $purchasedDate, 'price' => $price, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'A second entry, matching except for its due date, is purchased'
		);

		self::assertCount(2, self::rows($product), 'The differing due dates keep the two entries apart before the edit');

		$targetDue = $editWhich === 'second' ? $secondDue : $firstDue;
		$newDue = $editWhich === 'second' ? $firstDue : $secondDue;
		$targetAmount = $editWhich === 'second' ? $secondAmount : $firstAmount;

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND best_before_date = ?');
		$entryId->execute([$product, $targetDue]);
		$entryId = (int)$entryId->fetchColumn();

		$edit = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => $targetAmount, 'best_before_date' => $newDue, 'open' => false, 'purchased_date' => $purchasedDate, 'price' => $price, 'location_id' => self::$locationA]), new Response(), ['entryId' => $entryId]),
			200,
			'Its due date is edited to match the other entry, triggering compaction'
		);

		self::assertCount(1, self::rows($product), 'The edit\'s compaction call merges the two entries into one');
		self::assertSame($firstAmount + $secondAmount, self::stockAmount($product), 'holding both purchases\' units');

		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];

		return [$product, (int)$editOld['id']];
	}

	// ------------------------------------------------------------------------------
	// #489 C2 - repeated partial-transfer undo creates negative stock
	// ------------------------------------------------------------------------------

	/**
	 * The issue's own reproduction: purchase 5 at A, transfer 1 then 3 to B, undo the
	 * second transfer. Before the fix, TRANSFER_TO undo matched the destination by
	 * (stock_id, location) alone, which is ambiguous once a second split transfer has
	 * landed a second row under the same stock_id at B - it picked whichever row came
	 * back first and subtracted this booking's amount from it regardless, leaving A=4
	 * and B holding -2 and 3 (summing to 5, so a sum-only assertion misses it). The fix
	 * has TransferProduct() record the exact destination row's id (stock_row_id) on the
	 * TRANSFER_TO/FROM bookings it writes, so undo can reverse precisely that row.
	 */
	public function testUndoingASplitTransferReversesExactlyThatTransfersContribution(): void
	{
		$product = self::insertProduct('Undo Split Transfer Contribution');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);

		$this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'One unit is transferred to B first'
		);
		$second = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Three more units are transferred to B'
		);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $second[0]['transaction_id']]),
			204,
			'Undoing the second (three-unit) transfer is accepted'
		);

		$rows = self::rows($product);
		foreach ($rows as $row)
		{
			self::assertGreaterThan(0.0, (float)$row['amount'], 'No row may be non-positive (#489 C2): ' . json_encode($row));
		}

		self::assertSame(4.0, self::stockAmountAtLocation($product, self::$locationA), 'A holds back the three units the undone transfer took');

		$atB = array_values(array_filter($rows, fn($row) => (int)$row['location_id'] === self::$locationB));
		self::assertCount(1, $atB, 'B holds exactly the one row the still-live first transfer created, not a leftover pair');
		self::assertSame(1.0, (float)$atB[0]['amount'], 'holding exactly the first transfer\'s single unit');

		self::assertSame(5.0, self::stockAmount($product), 'nothing was created or destroyed');
	}

	/**
	 * #489 C2's full escalation sequence: purchase 10, transfer 1 then 3, undo the
	 * latter, then try to consume more than the destination actually holds. Before the
	 * fix this reached a phantom negative row left by the transfer-undo defect above,
	 * and consuming past the positive row consumed that negative row as a *positive*
	 * consume booking, raising on-hand stock from 7 to 9. The row-matching fix above
	 * removes the phantom row itself, so this sequence can no longer reach that state -
	 * this test instead pins the invariant the escalation protected: after the undo, B
	 * holds exactly what the surviving transfer moved, consuming it empties B correctly,
	 * and consuming more than that is refused rather than inflating on-hand stock.
	 * testConsumeRefusesWhenACandidateStockRowIsNonPositive below exercises
	 * ConsumeProduct's own guard against a non-positive row directly, for whenever such
	 * a row reaches it by some other means.
	 */
	public function testTransferUndoConsumeEscalationNeverInflatesOnHandStock(): void
	{
		$product = self::insertProduct('Undo Transfer Escalation');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 10, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Ten units are purchased at A'
		);
		$this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'One unit is transferred to B first'
		);
		$second = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Three more units are transferred to B'
		);
		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $second[0]['transaction_id']]),
			204,
			'Undoing the second (three-unit) transfer is accepted'
		);
		self::assertSame(1.0, self::stockAmountAtLocation($product, self::$locationB), 'B holds exactly the surviving one-unit transfer');

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Consuming exactly what is left at B succeeds'
		);
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'B is now empty');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB]), new Response(), ['productId' => $product]),
			400,
			'Consuming more than B holds is refused, not satisfied from a phantom row'
		);

		self::assertSame(9.0, self::stockAmount($product), 'On-hand stock reflects exactly the one unit consumed - never inflated by the undo/consume sequence');
		foreach (self::rows($product) as $row)
		{
			self::assertGreaterThan(0.0, (float)$row['amount'], 'No row may be non-positive: ' . json_encode($row));
		}
	}

	/**
	 * #489 C2 checklist item 2, in isolation: even if a non-positive stock row reaches
	 * ConsumeProduct's candidate loop by some means other than the transfer defect above
	 * (a future defect, a direct database edit), it must never generate a positive
	 * 'consume' booking - which would record stock arriving, not leaving, and increase
	 * on-hand amount. The bad row is forced directly via raw SQL, the same way
	 * StockCoverageTest.php's own purchase-undo refusal tests force their out-of-band
	 * states, since it is not reachable through any documented API path once the
	 * transfer fix above is applied. Its due date is earlier than the valid row's, so
	 * stock_next_use's "first due first" order presents it to the candidate loop first.
	 */
	public function testConsumeRefusesWhenACandidateStockRowIsNonPositive(): void
	{
		$product = self::insertProduct('Undo Consume Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Three valid units are purchased'
		);

		// An out-of-band row that should never exist (amount <= 0), standing in for a
		// defect elsewhere in the ledger. Its aggregate with the valid row (3 - 1 = 2)
		// still covers the amount requested below, so only the per-row guard - not the
		// aggregate availability check earlier in ConsumeProduct() - can refuse this.
		self::$db->prepare('INSERT INTO stock (product_id, amount, stock_id, best_before_date, location_id) VALUES (?, -1, ?, ?, ?)')
			->execute([$product, 'corrupt-' . $product, '2026-01-01', self::$locationA]);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			400,
			'Consuming against a non-positive persisted row is refused rather than booked as a positive consume'
		);
	}

	// ------------------------------------------------------------------------------
	// #470 - transfer undo float residue
	// ------------------------------------------------------------------------------

	/**
	 * Mirrors StockCoverageTest::testUndoingBothCompactedPurchasesLeavesNoPhantomRowFromFloatResidue
	 * for the TRANSFER_TO branch: stock.amount is DOUBLE PRECISION and a bound parameter
	 * launders a naive float sum back to a clean decimal on the way into PostgreSQL, so a
	 * literal SQL float simulates the residue a real SUM() (CompactStockEntries()) can
	 * leave. Before the fix, TRANSFER_TO undo's `$newAmount == 0` exact comparison missed
	 * a residue by a few ULPs and left a near-zero phantom row at the destination; #469
	 * already applied the round()-before-compare fix to the PURCHASE branch, and this is
	 * the same treatment for TRANSFER_TO.
	 */
	public function testUndoingATransferLeavesNoPhantomRowFromFloatResidueAtTheDestination(): void
	{
		$product = self::insertProduct('Undo Transfer Float Residue');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 5, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'All five are moved to B (whole-row transfer)'
		);

		self::$db->exec('UPDATE stock SET amount = 5.0000000001 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'No phantom near-zero row is left at the destination (#470)');
		self::assertSame(5.0, self::stockAmount($product), 'the clean logged amount is restored at the source, not the residue');
	}

	// ------------------------------------------------------------------------------
	// #522 M22 - transfer undo loses opened state
	// ------------------------------------------------------------------------------

	/**
	 * Whole-row transfer of an opened entry, then undo: before the fix, TRANSFER_TO
	 * undo deleted the (relocated) row outright and TRANSFER_FROM undo rebuilt it at the
	 * source without setting `open` or the opened_date-derived state, so the rebuilt row
	 * came back closed while keeping opened_date - M22's own report. The fix mirrors
	 * `open` (derived from opened_date, matching the CONSUME-undo reconstruction just
	 * above in the same method) and the four measurement columns onto the TRANSFER_FROM
	 * booking TransferProduct() writes.
	 */
	public function testUndoingAWholeRowTransferPreservesOpenState(): void
	{
		$product = self::insertProduct('Undo Transfer Open State');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased'
		);
		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'It is opened'
		);
		$before = self::rows($product)[0];
		self::assertSame(1, (int)$before['open'], 'Sanity: the entry is open before the transfer');
		self::assertNotNull($before['opened_date']);

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The opened unit is moved to B (whole-row transfer)'
		);
		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives the round trip');
		self::assertSame(1, (int)$rows[0]['open'], 'The entry is still open (#522 M22), not silently closed by the rebuild');
		self::assertSame($before['opened_date'], $rows[0]['opened_date'], 'with its original opened date');
		self::assertSame(self::$locationA, (int)$rows[0]['location_id'], 'back at the source location');
		self::assertSame(1.0, (float)$rows[0]['amount']);
	}

	/**
	 * Same as above for a *measured* entry (ADR-0022): the four opened_* measurement
	 * columns must survive a whole-row transfer and its undo too, and the coherence
	 * invariant (amount = 1 while measured) must still hold on the rebuilt row.
	 */
	public function testUndoingAWholeRowTransferPreservesMeasurement(): void
	{
		$product = self::insertProduct('Undo Transfer Measurement');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased'
		);
		$stockId = self::rows($product)[0]['stock_id'];
		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockId, 'measurement' => ['amount' => 0.5, 'qu_id' => 2]]), new Response(), ['productId' => $product]),
			200,
			'It is opened and measured at 0.5 of its own stock unit'
		);
		$before = self::rows($product)[0];
		self::assertNotNull($before['opened_amount'], 'Sanity: the entry carries a measurement before the transfer');

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The measured unit is moved to B'
		);
		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives the round trip');
		self::assertSame(0.5, (float)$rows[0]['opened_amount'], 'The measured remainder survives (#522 M22 / ADR-0022 decision 9)');
		self::assertSame((int)$before['opened_qu_id'], (int)$rows[0]['opened_qu_id']);
		self::assertSame(1.0, (float)$rows[0]['amount'], 'the coherence invariant (amount = 1 while measured) still holds');
		self::assertSame(1, (int)$rows[0]['open']);
	}

	// ------------------------------------------------------------------------------
	// #504 M4 - PRODUCT_OPENED undo with a null purchased_date, and null-safe
	// TRANSFER location matching
	// ------------------------------------------------------------------------------

	/**
	 * M4's own report: the PRODUCT_OPENED undo branch matched `purchased_date = :3`,
	 * which SQL never satisfies for a NULL purchased_date, and never checked whether it
	 * actually found (and updated) a row before marking the booking undone regardless.
	 * The fix matches null-safely and refuses when no row is found instead.
	 */
	public function testUndoOfOpeningWithNullPurchasedDateActuallyReversesStock(): void
	{
		$product = self::insertProduct('Undo Open Null Purchased Date');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased'
		);
		self::$db->prepare('UPDATE stock SET purchased_date = NULL WHERE product_id = ?')->execute([$product]);

		$open = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'The entry (with no purchased date) is opened'
		);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $open[0]['transaction_id']]),
			204,
			'Undoing the opening is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'The entry still exists');
		self::assertSame(0, (int)$rows[0]['open'], 'and is actually closed again (#504 M4), not just marked undone while staying open');
		self::assertNull($rows[0]['opened_date']);

		$booking = self::$db->prepare("SELECT undone FROM stock_log WHERE product_id = ? AND transaction_type = 'product-opened'");
		$booking->execute([$product]);
		self::assertSame(1, (int)$booking->fetchColumn(), 'and the booking is marked undone, consistently with the reversal that actually happened');
	}

	/**
	 * ADR-0029 keeps both stock.location_id and stock_log.location_id nullable. A
	 * TRANSFER_TO booking recorded before this fix (no stock_row_id) falls back to
	 * matching by (stock_id, location_id) alone; that match used to compare
	 * `location_id = :2` with plain SQL equality, which - like purchased_date above -
	 * never matches NULL to NULL, so a legacy NULL-location transfer booking could never
	 * be undone at all. The fallback match is null-safe for exactly this reason.
	 */
	public function testTransferUndoNullSafelyMatchesALegacyRowWithNoLocation(): void
	{
		$product = self::insertProduct('Undo Transfer Legacy Null Location');
		$stockId = 'legacy-' . $product;
		self::$db->prepare('INSERT INTO stock (product_id, amount, stock_id, location_id, best_before_date) VALUES (?, 2, ?, NULL, ?)')
			->execute([$product, $stockId, self::FAR_FUTURE_DATE]);

		$logId = self::$db->prepare('INSERT INTO stock_log (product_id, amount, stock_id, location_id, transaction_type, best_before_date, transaction_id, user_id) VALUES (?, 2, ?, NULL, ?, ?, ?, 9000) RETURNING id');
		$logId->execute([$product, $stockId, StockService::TRANSACTION_TYPE_TRANSFER_TO, self::FAR_FUTURE_DATE, 'legacy-tx-' . $product]);
		$logId = (int)$logId->fetchColumn();

		$this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $logId]),
			204,
			'A legacy TRANSFER_TO booking with no stock_row_id and a NULL location is still matched and undone'
		);

		$remaining = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE stock_id = ?');
		$remaining->execute([$stockId]);
		self::assertSame(0, (int)$remaining->fetchColumn(), 'The whole (matched) amount was reversed and the row deleted');
	}

	/**
	 * Symmetric with the PURCHASE branch's own negative-remainder refusal
	 * (StockCoverageTest::testUndoRefusesAPurchaseWhoseEntryHoldsLessThanItAdded):
	 * TRANSFER_FROM undo gains the same round()-and-refuse-if-negative guard as
	 * TRANSFER_TO, reviewed for the same class of defect. Not reachable through any
	 * documented API path (the "no later dependent booking" guard already refuses
	 * whenever something else could have reduced the source entry), so forced directly
	 * with a raw UPDATE, the same way StockCoverageTest.php forces its own out-of-band
	 * states.
	 */
	public function testUndoRefusesATransferFromWhenTheSourceEntryHasBeenCorruptedNegative(): void
	{
		$product = self::insertProduct('Undo Transfer From Negative');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 2, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Two units are moved to B (three remain at A)'
		);

		self::$db->prepare('UPDATE stock SET amount = -5 WHERE product_id = ? AND location_id = ?')->execute([$product, self::$locationA]);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			400,
			'Undoing a transfer back onto an already-corrupted negative source entry is refused, not compounded'
		);
	}

	// ------------------------------------------------------------------------------
	// #488 C1 - edit/open undo after CompactStockEntries() merges the entry
	// ------------------------------------------------------------------------------

	/**
	 * #488 C1's first repro, row-id order A: the edited (second-purchased) entry is the
	 * one CompactStockEntries() may keep, ending up with the OTHER purchase's units
	 * folded into it. Undoing the edit must not silently overwrite that merged row with
	 * the edit's own pre-edit amount - doing so would destroy the other, still-live
	 * purchase's contribution while leaving its booking marked live. The fix compares
	 * the target row's current amount against the correlated STOCK_EDIT_NEW booking's
	 * recorded post-edit amount and refuses atomically on a mismatch, rather than
	 * attempting the lot/lineage redesign #488 explicitly defers to the maintainer.
	 */
	public function testUndoingAnEditAfterCompactionRefusesRatherThanDestroyingTheOtherContribution(): void
	{
		[$product, $editOldId] = $this->purchaseEditAndCompact('Undo Edit Compaction A', 3.0, '2030-01-01', 2.0, '2030-02-02', 'second');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldId]),
			400,
			'Undoing the edit after compaction merged it with the other purchase is refused, not silently overwriting the merged row'
		);

		self::assertSame(5.0, self::stockAmount($product), 'both purchases\' units are still intact');
	}

	/**
	 * #488 C1's first repro, the reversed row-id order: the edited entry is the one
	 * CompactStockEntries() deletes (its units folded into the other, unedited row).
	 * Before the fix this threw the untruthful "Booking does not exist or was already
	 * undone" - the booking does exist, and its stock_id's units are still live, just
	 * under a different row id. The refusal message need not name the merge, but the
	 * ledger must stay untouched and no live contribution may be lost either way.
	 */
	public function testUndoingAnEditAfterCompactionRefusesInTheReversedRowIdOrderToo(): void
	{
		[$product, $editOldId] = $this->purchaseEditAndCompact('Undo Edit Compaction B', 3.0, '2030-01-01', 2.0, '2030-02-02', 'first');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldId]),
			400,
			'Undoing the edit after compaction is refused in the reversed row-id order too (#488 C1)'
		);

		self::assertSame(5.0, self::stockAmount($product), 'both purchases\' units are still intact');
	}

	/**
	 * #488 C1's second repro: two purchases compact together, two partial opens follow,
	 * then a third matching purchase compacts the unopened remainder. Undoing the second
	 * open must not mark its booking undone while leaving the physically opened amount
	 * unchanged - either it actually reverses that one unit (opened amount 3 -> 2), or it
	 * refuses atomically with the ledger completely untouched. Both outcomes are accepted
	 * here because which one is correct depends on whether the compaction this sequence
	 * triggers actually touches the opened row's own identity - what must never happen is
	 * the booking being marked undone while opened stock silently stays at 3, which is
	 * the defect M22's sibling report in #488 describes.
	 */
	public function testUndoingAnOpenAfterAMatchingPurchaseCompactsTheRemainder(): void
	{
		$product = self::insertProduct('Undo Open Compaction');
		$purchasedDate = '2026-01-01';
		$due = '2030-01-01';

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => $due, 'purchased_date' => $purchasedDate, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'Two units purchased'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'best_before_date' => $due, 'purchased_date' => $purchasedDate, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'Three more, matching, purchased the same day'
		);
		self::assertSame(5.0, self::stockAmount($product), 'The two purchases are compacted into one entry of five');

		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 2]), new Response(), ['productId' => $product]),
			200,
			'Two units are opened'
		);
		$secondOpen = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'One more unit is opened'
		);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 4, 'best_before_date' => $due, 'purchased_date' => $purchasedDate, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'Four more matching units are purchased, compacting the unopened remainder'
		);

		self::assertSame(3.0, self::openedAmount($product), 'Three units are opened before the undo');

		$before = self::ledger();
		$response = $this->respond(fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $secondOpen[0]['transaction_id']]));

		if ($response['status'] === 204)
		{
			self::assertSame(2.0, self::openedAmount($product), 'Undoing the one-unit opening actually reduced opened stock (#488 C1), not left it at 3');
		}
		else
		{
			self::assertSame(400, $response['status'], 'Any refusal must be an ordinary 400, not a crash: ' . json_encode($response['body']));
			self::assertSame($before, self::ledger(), 'and must leave the ledger completely untouched');
			self::assertSame(3.0, self::openedAmount($product), 'so opened stock stays consistent with the booking still being live');
		}

		self::assertSame(9.0, self::stockAmount($product), 'total stock is never destroyed either way');
	}
}
