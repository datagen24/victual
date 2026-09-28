<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\RecipesApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\Database\PostgresDialect;
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

	/**
	 * ADR-0033's "never expires" sentinel - the only real date value stock_splits admits as
	 * a merge candidate (2026-09-27). A fixture that needs CompactStockEntries() to actually
	 * merge a row must give it this date or NULL; FAR_FUTURE_DATE above is a real, finite
	 * date and is never merge-eligible under the new predicate.
	 */
	private const NEVER_EXPIRES = '2999-12-31';

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static RecipesApiController $recipes;
	private static int $locationA;
	private static int $locationB;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// The BaseService::$Instances reset this testsuite needed (issue #533: a second
		// PgsqlSchemaTestCase class sharing this process with StockCoverageTest.php,
		// which runs first, must not reach that class's already-dropped schema through a
		// cached service singleton) is now PgsqlSchemaTestCase::setUpBeforeClass()'s own
		// job via ResetSchemaBoundState() (PR #537), called just above. The reflection
		// workaround this file carried before that landed is gone.

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);
		self::$recipes = new RecipesApiController(self::$container);

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

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

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
	 * except their due date, edits one of their due dates to match the other, then runs the
	 * maintenance command explicitly to merge them, and returns the product id and the
	 * resulting STOCK_EDIT_OLD booking id. $editWhich selects which of the two purchases (by
	 * insertion order) is edited, so both row-id orders relative to whichever row
	 * CompactStockEntries() keeps can be exercised (#488 C1).
	 *
	 * ADR-0033 (2026-09-27): EditStockEntry() no longer compacts inline, and only rows with
	 * no real due date may merge at all - so $firstDue/$secondDue must resolve, after the
	 * edit, to '2999-12-31' (the "never expires" sentinel) rather than an arbitrary future
	 * date, and this helper now calls CompactStockEntries() itself, explicitly, where the
	 * inline call used to fire.
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
			'Its due date is edited to match the other entry'
		);
		self::assertCount(2, self::rows($product), 'The edit alone no longer merges the two entries (ADR-0033 decision 1)');

		StockService::GetInstance()->CompactStockEntries($product);

		self::assertCount(1, self::rows($product), 'The explicit maintenance run merges the two entries into one');
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
	/**
	 * Uses a split (partial) transfer rather than a whole-row one: a whole-row transfer's
	 * undo now relocates the same physical row in place (#488, second review round) and
	 * does not recompute its amount at all, so it cannot itself develop or clean up a
	 * residue. The split branch still computes `stockRow->amount - logRow->amount`
	 * against the row it created at the destination, which is exactly where a real
	 * SUM()-over-doubles residue (e.g. from CompactStockEntries()) could land.
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
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Three of the five are split off to B'
		);

		self::$db->exec('UPDATE stock SET amount = 3.0000000001 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

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
	 * Whole-row transfer of an opened entry, then undo: TRANSFER_TO's undo now relocates
	 * the same physical row back to the source in place (#488, second review round)
	 * rather than deleting it for TRANSFER_FROM's undo to rebuild - which used to lose
	 * `open` and the opened_date-derived state entirely (M22's own report) until an
	 * earlier round mirrored them onto the rebuild. Relocating in place preserves them
	 * (and the row's own id) automatically, since nothing about `open`/`opened_date` is
	 * ever touched by the relocate.
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
		self::assertSame($before['id'], $rows[0]['id'], 'it is the very same row, not a rebuild under a new id (#488, second review round)');
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
		self::assertSame($before['id'], $rows[0]['id'], 'it is the very same row, not a rebuild under a new id (#488, second review round)');
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

		// set_products_default_location_if_empty_stock(_log) are BEFORE INSERT triggers on
		// both tables, so every NULL location_id above was already replaced with the
		// product's own default location before either row was ever written. Neither fires
		// on UPDATE, so this is the only way to actually persist a NULL location_id on both
		// rows - what this test needs to exercise the null-safe match at all.
		self::$db->prepare('UPDATE stock SET location_id = NULL WHERE stock_id = ?')->execute([$stockId]);
		self::$db->prepare('UPDATE stock_log SET location_id = NULL WHERE id = ?')->execute([$logId]);

		$stockLocation = self::$db->prepare('SELECT location_id FROM stock WHERE stock_id = ?');
		$stockLocation->execute([$stockId]);
		self::assertNull($stockLocation->fetchColumn(), 'Sanity: the stock row genuinely has a NULL location_id');
		$logLocation = self::$db->prepare('SELECT location_id FROM stock_log WHERE id = ?');
		$logLocation->execute([$logId]);
		self::assertNull($logLocation->fetchColumn(), 'Sanity: the booking genuinely has a NULL location_id');

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
		[$product, $editOldId] = $this->purchaseEditAndCompact('Undo Edit Compaction A', 3.0, self::NEVER_EXPIRES, 2.0, '2030-02-02', 'second');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldId]),
			400,
			'Undoing the edit after an explicit maintenance merge is refused, not silently overwriting the merged row'
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
		[$product, $editOldId] = $this->purchaseEditAndCompact('Undo Edit Compaction B', 3.0, '2030-01-01', 2.0, self::NEVER_EXPIRES, 'first');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldId]),
			400,
			'Undoing the edit after an explicit maintenance merge is refused in the reversed row-id order too (#488 C1)'
		);

		self::assertSame(5.0, self::stockAmount($product), 'both purchases\' units are still intact');
	}

	/**
	 * #488 C1's second repro: two purchases compact together, two partial opens follow on
	 * the same day (so the two opened portions also match every CompactStockEntries()
	 * grouping column, including opened_date), then a third matching purchase triggers a
	 * compaction pass that merges not only the unopened remainder but the two opened
	 * portions too. Undoing the second open must never mark its booking undone while
	 * leaving the physically opened amount unchanged (silently stuck at 3) - the interim
	 * #488 decision, matching STOCK_EDIT_OLD's own compaction guard, is to refuse
	 * atomically with the ledger completely untouched, since the merge has destroyed which
	 * physical row corresponds to which opening.
	 */
	public function testUndoingAnOpenAfterAMatchingPurchaseCompactsTheRemainder(): void
	{
		$product = self::insertProduct('Undo Open Compaction');
		$purchasedDate = '2026-01-01';
		// ADR-0033 (2026-09-27): only a never-expiring due date is merge-eligible, and
		// neither EditStockEntry() nor AddProduct() compacts inline any more - every
		// "compacting..." step below is now this test's own explicit maintenance call,
		// placed exactly where the inline call used to fire (removing it a step late would
		// leave the two opened portions' lineage split across groups instead of confined to
		// one, per ADR-0033 decision 3's lineage-confinement guard).
		$due = self::NEVER_EXPIRES;

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
		StockService::GetInstance()->CompactStockEntries($product);
		self::assertSame(5.0, self::stockAmount($product), 'The two purchases are compacted into one entry of five');
		self::assertCount(1, self::rows($product), 'as a single row');

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
			'Four more matching units are purchased'
		);
		StockService::GetInstance()->CompactStockEntries($product);

		self::assertSame(3.0, self::openedAmount($product), 'Three units are opened before the undo');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $secondOpen[0]['transaction_id']]),
			400,
			'Undoing the one-unit opening after the compaction merged it with the other opened portion is refused (#488 interim decision), not marked undone while opened stock silently stays at 3'
		);

		self::assertSame(3.0, self::openedAmount($product), 'opened stock is unchanged by the refusal');
		self::assertSame(9.0, self::stockAmount($product), 'and total stock is untouched');
	}

	// ------------------------------------------------------------------------------
	// #489 M-checklist item 1 - a legitimate zero-amount row must be skipped, not refused
	// ------------------------------------------------------------------------------

	/**
	 * Purchases a positive row at A (earlier due date, so stock_next_use() offers it
	 * first) and a second row reduced to exactly zero by a direct edit, at an earlier due
	 * date still, so the zero row is always the first candidate. ConsumeProduct() must skip
	 * that legitimate zero row rather than refuse the whole consume, and must leave it
	 * untouched.
	 *
	 * The zero row used to come from weighing a vessel down to its own tare weight
	 * (WeighLocation() -> EditStockEntry(..., 0)). ADR-0033 (2026-09-27) changed
	 * WeighLocation() to correct the location's TOTAL: a net-zero reading is now a "lower"
	 * reading like any other, and ConsumeProduct() takes the vessel's row whole rather than
	 * leaving it at amount 0 - there would be no zero row left for this test to set up that
	 * way any more. EditStockEntry(..., 'amount' => 0, ...) is unaffected by ADR-0033 and
	 * remains a legitimate, direct zero-write path (ADR-0032 decision 4 still permits zero,
	 * only negative amounts are refused), so it produces the same precondition this test's
	 * own point - ConsumeProduct() skipping a legitimate zero row - depends on.
	 */
	public function testConsumeSkipsALegitimateZeroRowLeftByADirectZeroEdit(): void
	{
		$product = self::insertProduct('Undo Zero Row Edit');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB, 'best_before_date' => '2026-01-01', 'purchased_date' => '2026-01-01']), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased at B, due first'
		);
		$zeroRowId = (int)self::rows($product)[0]['id'];
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A, due later'
		);

		$this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 0, 'best_before_date' => '2026-01-01', 'open' => false, 'purchased_date' => '2026-01-01', 'location_id' => self::$locationB]), new Response(), ['entryId' => $zeroRowId]),
			200,
			'The B entry is directly edited down to exactly zero'
		);
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'Sanity: the B row is now exactly zero');
		self::assertCount(1, array_filter(self::rows($product), fn($row) => (int)$row['id'] === $zeroRowId), 'Sanity: the zero row itself still exists (an edit to 0 does not delete it)');

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'Consuming 1 succeeds by skipping the zero row and taking from A'
		);

		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'The zero row is untouched, not deleted or made negative');
		self::assertCount(1, array_filter(self::rows($product), fn($row) => (int)$row['id'] === $zeroRowId), 'and it still physically exists');
		self::assertSame(4.0, self::stockAmountAtLocation($product, self::$locationA), 'and the positive row absorbed the consume');
	}

	/** Shared fixture for the remaining three zero-row-skip regressions: a row edited to exactly zero (due first), and a positive row (due later). */
	private function purchaseWithAnEditedZeroRowAndAPositiveRow(string $productName): int
	{
		$product = self::insertProduct($productName);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$locationA, 'best_before_date' => '2026-01-01', 'purchased_date' => '2026-01-01']), new Response(), ['productId' => $product]),
			200,
			'Two units are purchased, due first'
		);
		$zeroEntryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$zeroEntryId->execute([$product]);
		$zeroEntryId = (int)$zeroEntryId->fetchColumn();
		$this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 0, 'open' => false, 'purchased_date' => '2026-01-01', 'best_before_date' => '2026-01-01']), new Response(), ['entryId' => $zeroEntryId]),
			200,
			'That entry is edited down to exactly zero'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five more units are purchased, due later'
		);
		self::assertSame(5.0, self::stockAmount($product), 'Sanity: on-hand is just the positive row, the zero row contributing nothing');

		return $product;
	}

	public function testConsumeProductSkipsAnEditedZeroRow(): void
	{
		$product = $this->purchaseWithAnEditedZeroRowAndAPositiveRow('Undo Zero Row Consume');

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'Consuming 1 succeeds by skipping the zero row'
		);

		self::assertSame(4.0, self::stockAmount($product), 'One unit was taken from the positive row');
		$zeroRows = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE product_id = ? AND amount = 0');
		$zeroRows->execute([$product]);
		self::assertSame(1, (int)$zeroRows->fetchColumn(), 'The zero row still exists, untouched');
	}

	public function testInventoryProductSkipsAnEditedZeroRowWhenCorrectingDownward(): void
	{
		$product = $this->purchaseWithAnEditedZeroRowAndAPositiveRow('Undo Zero Row Inventory');

		$this->expectStatus(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => 4]), new Response(), ['productId' => $product]),
			200,
			'Correcting inventory down from 5 to 4 succeeds by skipping the zero row'
		);

		self::assertSame(4.0, self::stockAmount($product), 'The correction reduced only the positive row');
	}

	public function testConsumeRecipeSkipsAnEditedZeroRow(): void
	{
		$product = $this->purchaseWithAnEditedZeroRowAndAPositiveRow('Undo Zero Row Recipe');

		$recipeId = self::$db->prepare('INSERT INTO recipes (name) VALUES (?) RETURNING id');
		$recipeId->execute(['Zero Row Recipe ' . $product]);
		$recipeId = (int)$recipeId->fetchColumn();
		self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id) VALUES (?, ?, 1, 2)')->execute([$recipeId, $product]);

		$this->expectStatus(
			fn() => self::$recipes->ConsumeRecipe(self::request('POST'), new Response(), ['recipeId' => $recipeId]),
			204,
			'Consuming the recipe succeeds by skipping the zero row'
		);

		self::assertSame(4.0, self::stockAmount($product), 'The recipe consume took its one unit from the positive row');
	}

	// ------------------------------------------------------------------------------
	// AMOUNT_TOLERANCE - a real small remainder (e.g. 0.004) must survive an undo;
	// round(x, 2)'s 0.005-unit threshold treated it as zero (maintainer decision, #487)
	// ------------------------------------------------------------------------------

	/**
	 * TRANSFER_TO undo must keep a real small remainder at the destination rather than
	 * deleting it as if it were a float artifact. round(x, 2) rounded 0.004 to 0.00 and
	 * deleted the row outright, destroying a live contribution. Uses a split (partial)
	 * transfer: a whole-row transfer's undo now relocates the same row in place (#488,
	 * second review round) without recomputing its amount at all, so this comparison is
	 * only reachable through the split branch, which still creates a new destination row
	 * and still computes its amount arithmetically.
	 */
	public function testUndoingASplitTransferKeepsARealSmallRemainderAtTheDestination(): void
	{
		$product = self::insertProduct('Undo Transfer Real Remainder');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Three of the five are split off to B'
		);

		// Simulates a real 0.004-unit lot having compacted onto this same row at B (e.g. a
		// matching purchase there) - not a float artifact, a genuine small remainder.
		self::$db->exec('UPDATE stock SET amount = 3.004 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		self::assertEqualsWithDelta(0.004, self::stockAmountAtLocation($product, self::$locationB), 1e-9, 'The real 0.004 remainder is kept at B, not deleted as if it were zero');
		self::assertSame(5.0, self::stockAmountAtLocation($product, self::$locationA), 'and the other two of the original five are back at A');
	}

	/**
	 * TRANSFER_TO undo must refuse when the destination holds less than this booking
	 * added, not treat a small negative remainder as zero. round(-0.004, 2) is -0.0, which
	 * compares equal to zero (not negative) in PHP, so this used to delete the row outright
	 * and then let TRANSFER_FROM's undo rebuild the full amount at the source -
	 * manufacturing 0.004 units that were never there. Uses a split transfer for the same
	 * reason as the test above: a whole-row transfer's undo no longer computes an amount
	 * delta at all, so there is nothing left for it to manufacture.
	 */
	public function testUndoingASplitTransferRefusesRatherThanManufacturingStockOnAShortfall(): void
	{
		$product = self::insertProduct('Undo Transfer Shortfall Refusal');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Three of the five are split off to B'
		);

		// Simulates the destination entry having been reduced, out of band, to slightly
		// less than what this transfer added - standing in for a defect elsewhere or a
		// direct database edit, the same way StockCoverageTest.php forces its own
		// out-of-band states.
		self::$db->exec('UPDATE stock SET amount = 2.996 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			400,
			'Undoing a transfer onto a destination short by 0.004 is refused, not rounded away to a clean delete-and-rebuild'
		);
	}

	/**
	 * The PURCHASE branch's own round($newAmount, 2) (#469) has the same defect: purchasing
	 * 0.004 then a matching 3 compacts them to 3.004; undoing the 3-unit purchase alone
	 * must leave the 0.004 purchase's units intact, not delete the row because 0.004 rounds
	 * to zero at two decimal places.
	 */
	public function testUndoingAPurchaseKeepsARealSmallRemainderAfterCompaction(): void
	{
		$product = self::insertProduct('Undo Purchase Real Remainder');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 0.004, 'location_id' => self::$locationA, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'A 0.004-unit lot is purchased'
		);
		$large = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationA, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'Three matching units are purchased'
		);
		// ADR-0033 (2026-09-27): neither purchase compacts inline any more - only an
		// explicit maintenance run merges them, and only because both are never-expiring.
		StockService::GetInstance()->CompactStockEntries($product);
		self::assertEqualsWithDelta(3.004, self::stockAmount($product), 1e-9, 'Sanity: the two purchases are compacted');

		$this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$large[0]['id']]),
			204,
			'Undoing the three-unit purchase is accepted'
		);

		self::assertEqualsWithDelta(0.004, self::stockAmount($product), 1e-9, 'The 0.004-unit purchase\'s own units survive, not deleted because they round to zero');
	}

	/**
	 * STOCK_EDIT_OLD's own compaction guard (#488 C1) must detect a merge that adds only
	 * 0.004: round(x, 2) rounds 3.004 and 3 to the same two-decimal value, so the mismatch
	 * went undetected and the undo would overwrite the merged row with the edit's pre-edit
	 * amount, destroying the compacted-in purchase's contribution.
	 */
	public function testUndoingAnEditDetectsACompactionMergeSmallerThanTheOldRoundingThreshold(): void
	{
		$product = self::insertProduct('Undo Edit Small Merge');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 0.004, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => '2026-01-01', 'price' => 1.0, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'A 0.004-unit lot is purchased, never expiring'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'best_before_date' => '2030-02-02', 'purchased_date' => '2026-01-01', 'price' => 1.0, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'Three units are purchased, due 2030-02-02 - kept separate by the differing due date'
		);
		self::assertCount(2, self::rows($product), 'Sanity: the differing due dates keep the two entries apart');

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND best_before_date = ?');
		$entryId->execute([$product, '2030-02-02']);
		$entryId = (int)$entryId->fetchColumn();

		// ADR-0033 (2026-09-27): EditStockEntry() no longer compacts inline. The edit takes
		// the 0.004-unit lot's own never-expiring due date (rather than the other way
		// around), since only that date keeps the merged row eligible for the explicit
		// maintenance run right below.
		$edit = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 3, 'best_before_date' => self::NEVER_EXPIRES, 'open' => false, 'purchased_date' => '2026-01-01', 'price' => 1.0, 'location_id' => self::$locationA]), new Response(), ['entryId' => $entryId]),
			200,
			'Its due date is edited to match the 0.004-unit lot'
		);
		self::assertCount(2, self::rows($product), 'The edit alone does not merge them (ADR-0033 decision 1)');

		StockService::GetInstance()->CompactStockEntries($product);
		self::assertEqualsWithDelta(3.004, self::stockAmount($product), 1e-9, 'Sanity: the explicit maintenance run merged the two entries');

		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$editOld['id']]),
			400,
			'Undoing the edit is refused: the 0.004-unit merge is detected even though it is below the old 0.005 rounding threshold'
		);
	}

	// ------------------------------------------------------------------------------
	// #488, sibling of C1/M22 - PRODUCT_OPENED undo must reverse the opened row, not an
	// unopened twin sharing the same stock_id, amount and purchased_date
	// ------------------------------------------------------------------------------

	/**
	 * Purchase 2 at A, transfer 1 to B: TransferProduct()'s split branch does not mint a
	 * new stock_id for the destination, so both rows now share stock_id, amount (1) and
	 * purchased_date. Before the fix, PRODUCT_OPENED undo matched on exactly those three
	 * columns, which could reverse the untouched twin instead of the row this booking
	 * actually opened, leaving the real one open while marking the booking undone
	 * regardless. Tested opening each of the two rows in turn (via the product's
	 * default_consume_location_id, which stock_next_use() prioritises, to control
	 * deterministically which one OpenProduct() picks), since the bug does not depend on
	 * which one was opened.
	 */
	public function testUndoingAnOpenReversesTheOpenedRowNotItsUnopenedTwin(): void
	{
		foreach ([self::$locationA, self::$locationB] as $index => $openLocation)
		{
			$product = self::insertProduct('Undo Open Correct Twin ' . $index);
			self::$db->prepare('UPDATE products SET default_consume_location_id = ? WHERE id = ?')->execute([$openLocation, $product]);

			$this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
				200,
				"[$index] Two units are purchased at A"
			);
			$this->expectStatus(
				fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
				200,
				"[$index] One unit is split off to B - both rows now share the same stock_id and amount"
			);

			$stockIdStatement = self::$db->prepare('SELECT stock_id FROM stock WHERE product_id = ? LIMIT 1');
			$stockIdStatement->execute([$product]);
			$stockId = $stockIdStatement->fetchColumn();

			$open = $this->expectStatus(
				fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockId]), new Response(), ['productId' => $product]),
				200,
				"[$index] The default-consume-location row is opened (stock_next_use() offers it first)"
			);

			$openedRow = self::$db->prepare('SELECT id, location_id FROM stock WHERE product_id = ? AND open = 1');
			$openedRow->execute([$product]);
			$openedRow = $openedRow->fetch(PDO::FETCH_ASSOC);
			self::assertNotFalse($openedRow, "[$index] Sanity: exactly one row is open");
			self::assertSame($openLocation, (int)$openedRow['location_id'], "[$index] Sanity: the intended row (its own default consume location) is the one that opened");

			$this->expectStatus(
				fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $open[0]['transaction_id']]),
				204,
				"[$index] Undoing the opening is accepted"
			);

			foreach (self::rows($product) as $row)
			{
				self::assertSame(0, (int)$row['open'], "[$index] Row {$row['id']} at location {$row['location_id']} must not be open after the undo (#488): " . json_encode($row));
			}
		}
	}

	// ------------------------------------------------------------------------------
	// #488, second Opus review - PRODUCT_OPENED undo for a move_on_open product
	// ------------------------------------------------------------------------------

	/**
	 * A move_on_open product's OpenProduct() call transfers the just-opened entry to its
	 * default consume location inside the same transaction (OpenProduct()'s own
	 * TransferProduct() call). Undoing that whole transaction processes the newest booking
	 * first: with an earlier fix, the whole-row TRANSFER_TO undo deleted the row at the
	 * destination and TRANSFER_FROM's undo rebuilt it under a *new* id at the source, so
	 * by the time the (oldest, processed last) PRODUCT_OPENED booking's own undo ran, its
	 * stock_row_id no longer resolved to any row - and a same-columns fallback turned out
	 * to be unsafe, since another live booking's row can coincidentally share the exact
	 * same (stock_id, amount, purchased_date, open, location) (#488, second review round;
	 * see the twin-row and compaction tests above). The actual fix instead keeps the row's
	 * id stable through the whole-row transfer undo (TRANSFER_TO/FROM relocate it in
	 * place rather than delete-and-rebuild), so PRODUCT_OPENED's own stock_row_id never
	 * goes stale here in the first place. Covers both a whole-entry open (the entire
	 * purchased row moves) and a split open (only the opened portion moves, leaving an
	 * unopened remainder at A), and asserts the row's id is preserved throughout.
	 */
	private function moveOnOpenProductWithDefaultConsumeAtB(string $name): int
	{
		$product = self::insertProduct($name);
		self::$db->prepare('UPDATE products SET move_on_open = 1, default_consume_location_id = ? WHERE id = ?')->execute([self::$locationB, $product]);
		return $product;
	}

	public function testUndoingAMoveOnOpenWholeEntryOpeningRestoresTheClosedEntryAtItsOriginalLocation(): void
	{
		$product = $this->moveOnOpenProductWithDefaultConsumeAtB('Undo Move On Open Whole');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased at A'
		);
		$originalId = self::rows($product)[0]['id'];
		$open = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'Opening it also moves it to B (move_on_open, default consume location B)'
		);
		self::assertSame(1.0, self::stockAmountAtLocation($product, self::$locationB), 'Sanity: the opened unit is now at B');
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationA), 'Sanity: nothing remains at A');
		self::assertSame($originalId, self::rows($product)[0]['id'], 'Sanity: the whole-row transfer to B kept the same row id (#488, second review round)');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $open[0]['transaction_id']]),
			204,
			'Undoing the whole open-and-move transaction is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives, back at A');
		self::assertSame($originalId, $rows[0]['id'], 'it is the very same row throughout - opened, moved and undone - not a rebuild under a new id');
		self::assertSame(self::$locationA, (int)$rows[0]['location_id'], 'back at its original location');
		self::assertSame(0, (int)$rows[0]['open'], 'closed again');
		self::assertSame(1.0, (float)$rows[0]['amount'], 'holding its original amount');
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'nothing left at B');

		$stillLive = self::$db->prepare('SELECT COUNT(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$stillLive->execute([$open[0]['transaction_id']]);
		self::assertSame(0, (int)$stillLive->fetchColumn(), 'every booking of the transaction (open + both transfer halves) is marked undone');
	}

	public function testUndoingAMoveOnOpenSplitOpeningRestoresTheClosedPortionAtItsOriginalLocation(): void
	{
		$product = $this->moveOnOpenProductWithDefaultConsumeAtB('Undo Move On Open Split');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Three units are purchased at A'
		);
		// OpenProduct()'s split branch updates the original purchased row in place to
		// become the opened portion (a new row is minted for the unopened remainder
		// instead), so this id is the one that should travel to B and back.
		$originalId = self::rows($product)[0]['id'];
		$open = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'Opening one unit splits the entry and moves only the opened unit to B'
		);
		self::assertSame(1.0, self::stockAmountAtLocation($product, self::$locationB), 'Sanity: the opened unit is now at B');
		self::assertSame(2.0, self::stockAmountAtLocation($product, self::$locationA), 'Sanity: the unopened remainder stays at A');
		$atB = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND location_id = ?');
		$atB->execute([$product, self::$locationB]);
		self::assertSame($originalId, $atB->fetchColumn(), 'Sanity: the whole-row transfer to B kept the opened portion\'s original id (#488, second review round)');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $open[0]['transaction_id']]),
			204,
			'Undoing the whole open-and-move transaction is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(2, $rows, 'The unopened remainder and the rebuilt closed portion both exist at A');
		$ids = array_column($rows, 'id');
		self::assertContains($originalId, $ids, 'the opened-and-moved portion is the very same row throughout, not a rebuild under a new id');
		foreach ($rows as $row)
		{
			self::assertSame(self::$locationA, (int)$row['location_id'], "Row {$row['id']} is back at A: " . json_encode($row));
			self::assertSame(0, (int)$row['open'], "Row {$row['id']} is closed: " . json_encode($row));
		}
		self::assertSame(3.0, self::stockAmountAtLocation($product, self::$locationA), 'all three units are back at A');
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'nothing left at B');

		$stillLive = self::$db->prepare('SELECT COUNT(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$stillLive->execute([$open[0]['transaction_id']]);
		self::assertSame(0, (int)$stillLive->fetchColumn(), 'every booking of the transaction (open + both transfer halves) is marked undone');
	}

	// ------------------------------------------------------------------------------
	// #488, third Opus review round - preserving the row id through a whole-row
	// transfer undo, and refusing rather than falling back once stock_row_id is set
	// ------------------------------------------------------------------------------

	/**
	 * An ordinary whole-row transfer's undo must keep the row's id (so a label printed
	 * against it keeps its target - #491/#483, not touched by this PR) and restore every
	 * attribute the transfer itself changed, not just location and amount. A transfer
	 * into a freezer location adjusts best_before_date (ADR-0022 decision 7's freezing
	 * behaviour); undoing it must restore the original due date, which only the
	 * correlated TRANSFER_FROM booking recorded.
	 */
	public function testUndoingAWholeRowTransferKeepsTheRowIdAndRestoresAFreezingTransfersDueDate(): void
	{
		$product = self::insertProduct('Undo Transfer Freezing Due Date');
		self::$db->prepare('UPDATE products SET default_best_before_days_after_freezing = -1 WHERE id = ?')->execute([$product]);
		$freezer = self::insertRow('locations', ['name' => 'Freezing Due Date Freezer ' . $product, 'is_freezer' => 1]);

		$originalDue = '2030-06-15';
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => $originalDue, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased at A (a non-freezer location), due 2030-06-15'
		);
		$originalId = self::rows($product)[0]['id'];

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => $freezer]), new Response(), ['productId' => $product]),
			200,
			'The whole entry is transferred into the freezer, which freezes its due date to 2999-12-31'
		);
		$frozen = self::rows($product)[0];
		self::assertSame($originalId, $frozen['id'], 'Sanity: the whole-row transfer kept the same row id');
		self::assertSame('2999-12-31', $frozen['best_before_date'], 'Sanity: the transfer froze the due date');
		self::assertSame($freezer, (int)$frozen['location_id']);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives');
		self::assertSame($originalId, $rows[0]['id'], 'it is the very same row - the id a label would target (#491/#483) is preserved');
		self::assertSame(self::$locationA, (int)$rows[0]['location_id'], 'back at the non-freezer source location');
		self::assertSame($originalDue, $rows[0]['best_before_date'], 'with its original (unfrozen) due date restored');
		self::assertSame(1.0, (float)$rows[0]['amount']);
	}

	/**
	 * W1/W2 from the third Opus review round: three whole-row-transferred/opened entries
	 * (X, kept at A throughout; Z1 and Z2, sent to B and a third location, then moved
	 * back to A and opened) end up sharing stock_id, amount, purchased_date, open and
	 * location once all three are open at A - X is kept apart only by its note (edited
	 * to "x"), which CompactStockEntries() also groups by, so a later matching purchase
	 * (at a fourth location, to trigger a product-wide compaction pass regardless of
	 * where it lands) merges Z1 and Z2 (whose note is still null) into one row without
	 * touching X. Undoing the newest opening (Z2 in W1; Z1 in W2, opened after Z2 this
	 * time - the reordering that determines whether the compaction happens to keep or
	 * delete that exact row) must never fall back to matching X: a booking that has a
	 * stock_row_id must match that exact row or refuse, not guess from descriptive
	 * columns that a different live booking's row can share by coincidence.
	 */
	/**
	 * Rebuilt for ADR-0033 (2026-09-27): the original fixture here purchased three units,
	 * split two off by whole-row TRANSFER, and opened all three - which shared one stock_id
	 * throughout (a whole-row transfer never mints a new one), so the two-row group this test
	 * needs to merge always shared that id with the third, outside row and ADR-0033's
	 * shared-stock_id guard now correctly, and permanently, skips it. No maintenance run can
	 * reach that precondition any more.
	 *
	 * What W1/W2 actually protect against is still reachable, on a fixture with no shared
	 * stock_id at all: two one-unit never-expiring purchases, each opened WHOLE via its own
	 * stock_entry_id (no split, so no remainder and no lineage row either), then merged by one
	 * explicit maintenance run. $openR2First controls which opening is booked last:
	 *
	 * - false (R1 opened first, R2 second): the newest opening targets R2, the row the merge
	 *   KEEPS (MAX(id) - R2 is always the higher id, having been purchased second). The row
	 *   still exists afterwards, but the merge overwrote its amount with the group's sum - the
	 *   identity check's AMOUNT MISMATCH branch (StockService.php's UndoBooking(), PRODUCT_OPENED
	 *   case).
	 * - true (R2 opened first, R1 second): the newest opening targets R1, the row the merge
	 *   DELETES. Undoing it must reach the identity check's NULL-ROW branch, not the
	 *   subsequent-bookings guard a few lines above it in UndoBooking() - reachable only
	 *   because nothing is undone after the row it named stops existing, since the merge
	 *   itself never inserts a stock_log row, and this is the newest one in the group either
	 *   way (id order, not row survival, decides "newest").
	 *
	 * @return array{0: int, 1: int} product id, the newest PRODUCT_OPENED booking's id
	 */
	private function twoWholeRowOpenedTwinsMerging(string $productName, bool $openR2First): array
	{
		$product = self::insertProduct($productName);
		$purchasedDate = '2026-01-01';
		$price = 1.0;

		$purchaseArgs = ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => $purchasedDate, 'price' => $price];
		$purchase1 = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', $purchaseArgs), new Response(), ['productId' => $product]),
			200,
			'R1 (one never-expiring unit) is purchased'
		);
		$purchase2 = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', $purchaseArgs), new Response(), ['productId' => $product]),
			200,
			'R2 (a second, matching never-expiring unit) is purchased - its row id is always higher than R1\'s'
		);
		$stockIdR1 = $purchase1[0]['stock_id'];
		$stockIdR2 = $purchase2[0]['stock_id'];

		$openWhole = function (string $stockId) use ($product)
		{
			return $this->expectStatus(
				fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockId]), new Response(), ['productId' => $product]),
				200,
				'The whole one-unit row is opened by its own stock_entry_id - no split, no remainder'
			);
		};

		$openWhole($openR2First ? $stockIdR2 : $stockIdR1);
		$newestOpen = $openWhole($openR2First ? $stockIdR1 : $stockIdR2);

		StockService::GetInstance()->CompactStockEntries($product);
		self::assertCount(1, self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product)->fetchAll(), 'Sanity: the explicit maintenance run merged the two opened whole rows into one');

		return [$product, (int)$newestOpen[0]['id']];
	}

	/**
	 * W1 (rebuilt, see twoWholeRowOpenedTwinsMerging()'s own docblock): the newest opening's
	 * row was DELETED by the merge - the identity check's NULL-ROW branch, not the
	 * subsequent-bookings guard a merged StockMaintenanceCompactionTest fixture reaches
	 * instead (its own second merge rewrites both openings onto the kept stock_id before
	 * either is undone, so nothing there is left to exercise this specific branch).
	 */
	public function testUndoingTheNewestOpeningRefusesWhenTheMergeDeletedItsRow(): void
	{
		[$product, $newestOpenId] = $this->twoWholeRowOpenedTwinsMerging('Newest Opening Row Deleted', true);

		$decoded = $this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $newestOpenId]),
			400,
			'Undoing the newest opening is refused - the merge deleted the row it named'
		);
		self::assertStringContainsString('no longer exists', $decoded['error_message'] ?? $decoded['ErrorMessage'] ?? json_encode($decoded), 'via the identity check\'s own message (its null-row branch), not the subsequent-bookings guard\'s "subsequent dependent bookings" one');

		self::assertCount(1, self::rows($product), 'Sanity: the refusal did not resurrect or split the merged row');
	}

	/**
	 * W2 (rebuilt, see twoWholeRowOpenedTwinsMerging()'s own docblock): the newest opening's
	 * row SURVIVED the merge, but the merge overwrote its amount with the group's sum - the
	 * identity check's AMOUNT MISMATCH branch.
	 */
	public function testUndoingTheNewestOpeningRefusesWhenTheMergeKeptButChangedItsRow(): void
	{
		[$product, $newestOpenId] = $this->twoWholeRowOpenedTwinsMerging('Newest Opening Row Kept', false);

		$decoded = $this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $newestOpenId]),
			400,
			'Undoing the newest opening is refused - the row it named still exists, but the merge changed its amount underneath it'
		);
		self::assertStringContainsString('no longer exists', $decoded['error_message'] ?? $decoded['ErrorMessage'] ?? json_encode($decoded), 'via the identity check\'s own message (its amount-mismatch branch, same text as the null-row branch), not the subsequent-bookings guard\'s "subsequent dependent bookings" one');

		self::assertCount(1, self::rows($product), 'Sanity: the refusal did not split the merged row back apart');
	}

	// ------------------------------------------------------------------------------
	// #488, fourth Opus review round - a whole-row transfer's row must be verified
	// clean (still at the destination, amount unchanged since) before it is relocated;
	// a row dirtied by something else in between must fall back to the same
	// subtract-and-rebuild path a split transfer's undo already uses
	// ------------------------------------------------------------------------------

	/**
	 * Buy 2 at B, then buy 3 at A (a higher row id), transfer the whole 3 A->B (a
	 * whole-row transfer, so both correlated bookings name that row's own id), then a
	 * purchase elsewhere triggers CompactStockEntries() for the whole product, merging
	 * the now-identical two rows at B into one survivor - keeping the transferred
	 * row's id, since it is the higher of the two. Relocating that *whole* row back to
	 * A would carry B's own original two units with it, with no booking accounting
	 * for them. Undoing the transfer must instead subtract exactly the three units
	 * this transfer moved - the same arithmetic a split transfer's undo already does -
	 * leaving B's own two units behind.
	 */
	public function testUndoingAWholeRowTransferAfterCompactionSubtractsRatherThanRelocatingTheWholeRow(): void
	{
		$product = self::insertProduct('Undo Whole Transfer After Compaction');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$locationB, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'Two units are purchased directly at B'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationA, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'Three units are purchased at A - a higher row id than the B purchase'
		);

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The whole three-unit entry is transferred to B (a whole-row transfer)'
		);
		self::assertCount(2, array_filter(self::rows($product), fn($row) => (int)$row['location_id'] === self::$locationB), 'Sanity: two separate rows sit at B before compaction');
		self::assertSame(5.0, self::stockAmountAtLocation($product, self::$locationB), 'Sanity: B holds both lots, 2 + 3');

		$locationC = self::insertRow('locations', ['name' => 'After Compaction C ' . $product]);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => $locationC, 'best_before_date' => self::NEVER_EXPIRES, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'A purchase elsewhere (unrelated to this test beyond giving the explicit run below something else to look at too)'
		);
		// ADR-0033 (2026-09-27): no purchase compacts inline any more. An explicit,
		// product-wide maintenance run is what merges the two now-identical rows at B.
		StockService::GetInstance()->CompactStockEntries($product);
		self::assertCount(1, array_filter(self::rows($product), fn($row) => (int)$row['location_id'] === self::$locationB), 'Sanity: B is down to a single compacted row');
		self::assertSame(5.0, self::stockAmountAtLocation($product, self::$locationB), 'Sanity: that row holds all 5 units');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		self::assertSame(3.0, self::stockAmountAtLocation($product, self::$locationA), 'Only the three transferred units come back to A');
		self::assertSame(2.0, self::stockAmountAtLocation($product, self::$locationB), 'B keeps its own original two units, not emptied by relocating the whole (now-merged) row');
	}

	/**
	 * Whole-row counterpart to testUndoingASplitTransferKeepsARealSmallRemainderAtTheDestination()
	 * above: the clean-relocate check must correctly recognise a row dirtied beyond
	 * AMOUNT_TOLERANCE - simulating a genuine small lot compacted onto the destination
	 * row after the transfer, the same way the split version does - and fall back to
	 * the same subtract arithmetic, keeping the real remainder rather than relocating
	 * (and so silently carrying) it away with the rest of the row.
	 */
	public function testUndoingAWholeRowTransferKeepsARealSmallRemainderAtTheDestination(): void
	{
		$product = self::insertProduct('Undo Whole Transfer Real Remainder');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 5, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The whole five-unit entry is transferred to B (a whole-row transfer)'
		);

		// Simulates a real 0.004-unit lot having compacted onto this same row at B after
		// the transfer - not a float artifact, a genuine small remainder, and well beyond
		// AMOUNT_TOLERANCE, so the clean-relocate check must recognise this row is no
		// longer exactly what the transfer moved.
		self::$db->exec('UPDATE stock SET amount = 5.004 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		self::assertEqualsWithDelta(0.004, self::stockAmountAtLocation($product, self::$locationB), 1e-9, 'The real 0.004 remainder is kept at B, not relocated away with the rest of the row');
		self::assertSame(5.0, self::stockAmountAtLocation($product, self::$locationA), 'and the clean five units are rebuilt at A');
	}

	/**
	 * Whole-row counterpart to testUndoingASplitTransferRefusesRatherThanManufacturingStockOnAShortfall()
	 * above: dirtying the row short of what the transfer logged must still refuse,
	 * rather than let the clean-relocate path move a row that quietly no longer holds
	 * enough, or let the fallback delete it and manufacture the shortfall back at the
	 * source.
	 */
	public function testUndoingAWholeRowTransferRefusesRatherThanManufacturingStockOnAShortfall(): void
	{
		$product = self::insertProduct('Undo Whole Transfer Shortfall Refusal');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 5, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The whole five-unit entry is transferred to B (a whole-row transfer)'
		);

		// Simulates the destination entry having been reduced, out of band, to slightly
		// less than what this transfer moved.
		self::$db->exec('UPDATE stock SET amount = 4.996 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			400,
			'Undoing a whole-row transfer onto a destination short by 0.004 is refused, not relocated or rounded away'
		);
	}

	/**
	 * The clean-relocate check compares against AMOUNT_TOLERANCE, the same tolerance
	 * the fallback arithmetic above uses, so a residue too small to be anything but
	 * float noise (well under a hundred-millionth of a unit) does not force an
	 * unnecessary fallback. Relocating in place never recomputes the row's amount at
	 * all, so unlike testUndoingATransferLeavesNoPhantomRowFromFloatResidueAtTheDestination()'s
	 * split path, the noise travels with the relocated row rather than being resolved
	 * away by a delete-if-near-zero decision - there is only ever the one row in a
	 * whole-row pairing, so no separate phantom row can arise from it either way.
	 */
	public function testUndoingAWholeRowTransferRelocatesThroughASubToleranceResidue(): void
	{
		$product = self::insertProduct('Undo Whole Transfer Sub Tolerance Residue');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$originalId = self::rows($product)[0]['id'];
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 5, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The whole five-unit entry is transferred to B (a whole-row transfer)'
		);

		self::$db->exec('UPDATE stock SET amount = 5.0000000001 WHERE product_id = ' . $product . ' AND location_id = ' . self::$locationB);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'No separate phantom row is created either way - a whole-row pairing only ever has the one row');
		self::assertSame($originalId, $rows[0]['id'], 'it is relocated, not rebuilt: the sub-tolerance residue is not enough to force the fallback path');
		self::assertSame(self::$locationA, (int)$rows[0]['location_id'], 'back at the source');
		self::assertEqualsWithDelta(5.0000000001, (float)$rows[0]['amount'], 1e-12, 'the residue travels with the relocated row - relocating never recomputes an amount');
	}

	// ------------------------------------------------------------------------------
	// #488, fourth Opus review round - a strict stock_row_id match must not refuse a
	// legitimate undo just because an intervening consume-and-undo rebuilt the row
	// under a fresh id; CONSUME (and negative INVENTORY_CORRECTION) bookings now
	// record stock_row_id too, and their own undo rebuilds under that same id when the
	// row they took from is gone
	// ------------------------------------------------------------------------------

	/**
	 * X1: transfer 2 of 5 A->B (split - the source row at A keeps its own id, reduced
	 * to 3), consume the 3 left at A (a whole-take consume, deleting that row), undo
	 * the consume, undo the transfer. Before CONSUME bookings carried their own
	 * stock_row_id, undoing the consume rebuilt the A row under a fresh auto-increment
	 * id, so the transfer's own TRANSFER_FROM booking - which named the original id -
	 * could no longer find its source row and refused outright, even though nothing
	 * about the transfer itself was wrong.
	 */
	public function testUndoingATransferFindsARowRebuiltByAnInterveningConsumeUndoByItsOriginalId(): void
	{
		$product = self::insertProduct('Undo Transfer After Consume Undo Split');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$originalId = self::rows($product)[0]['id'];

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 2, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'Two of the five are split off to B, leaving 3 at A under the same original row id'
		);
		$atA = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND location_id = ?');
		$atA->execute([$product, self::$locationA]);
		self::assertSame($originalId, $atA->fetchColumn(), 'Sanity: the split transfer kept the source row\'s own id at A');

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'The 3 units left at A are fully consumed, deleting that row'
		);
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationA), 'Sanity: nothing remains at A');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted'
		);
		$atA->execute([$product, self::$locationA]);
		self::assertSame($originalId, $atA->fetchColumn(), 'Sanity: the consume undo rebuilt the row under its original id, not a fresh one (#488, fourth review round)');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted, finding its source row by that preserved id rather than refusing'
		);

		self::assertSame(5.0, self::stockAmountAtLocation($product, self::$locationA), 'All five units are back at A');
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'nothing left at B');
		$atA->execute([$product, self::$locationA]);
		self::assertSame($originalId, $atA->fetchColumn(), 'still the very same original row id throughout');
	}

	/**
	 * X2: transfer all 5 A->B (whole-row - both correlated bookings name the source
	 * row's own id), consume them at B (a whole-take consume, deleting that row), undo
	 * the consume, undo the transfer. Symmetric to X1 above but through the
	 * clean-relocate path instead of the split/legacy one: before CONSUME bookings
	 * carried their own stock_row_id, the consume-undo rebuild left the row under a
	 * fresh id, so TRANSFER_TO's own relocate lookup (by the original id) found
	 * nothing and refused outright - a regression present since the relocate path was
	 * first introduced.
	 */
	public function testUndoingAWholeRowTransferFindsARowRebuiltByAnInterveningConsumeUndoByItsOriginalId(): void
	{
		$product = self::insertProduct('Undo Whole Transfer After Consume Undo');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Five units are purchased at A'
		);
		$originalId = self::rows($product)[0]['id'];

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 5, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'The whole five-unit entry is transferred to B'
		);
		$atB = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND location_id = ?');
		$atB->execute([$product, self::$locationB]);
		self::assertSame($originalId, $atB->fetchColumn(), 'Sanity: the whole-row transfer kept the same row id at B');

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'All five units are consumed at B, deleting that row'
		);
		self::assertSame(0.0, self::stockAmount($product), 'Sanity: nothing remains anywhere');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted'
		);
		$atB->execute([$product, self::$locationB]);
		self::assertSame($originalId, $atB->fetchColumn(), 'Sanity: the consume undo rebuilt the row under its original id (#488, fourth review round)');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			204,
			'Undoing the transfer is accepted, relocating that same row back to A rather than refusing'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives');
		self::assertSame($originalId, $rows[0]['id'], 'still the very same original row id throughout');
		self::assertSame(self::$locationA, (int)$rows[0]['location_id'], 'back at A');
		self::assertSame(5.0, (float)$rows[0]['amount']);
		self::assertSame(0.0, self::stockAmountAtLocation($product, self::$locationB), 'nothing left at B');
	}

	/**
	 * X3: open 1 unit, consume it (a whole-take consume, deleting that opened row),
	 * undo the consume, undo the opening. Before CONSUME bookings carried their own
	 * stock_row_id, the consume-undo rebuild left the row under a fresh id, so
	 * PRODUCT_OPENED's own strict stock_row_id match (#488, third review round) could
	 * no longer find it and refused outright, even though the rebuilt row was
	 * otherwise identical - including still open, since the CONSUME booking mirrors
	 * the opened state the same way it mirrors a measurement (ADR-0022 decision 9).
	 */
	public function testUndoingAnOpeningFindsARowRebuiltByAnInterveningConsumeUndoByItsOriginalId(): void
	{
		$product = self::insertProduct('Undo Opening After Consume Undo');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased at A'
		);
		$originalId = self::rows($product)[0]['id'];

		$open = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'It is opened'
		);
		self::assertSame($originalId, self::rows($product)[0]['id'], 'Sanity: opening it in place keeps the same row id');

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'The opened unit is consumed, deleting that row'
		);
		self::assertSame(0.0, self::stockAmount($product), 'Sanity: nothing remains');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted'
		);
		$rows = self::rows($product);
		self::assertSame($originalId, $rows[0]['id'], 'Sanity: the consume undo rebuilt the row under its original id (#488, fourth review round)');
		self::assertSame(1, (int)$rows[0]['open'], 'Sanity: the rebuilt row is still open - CONSUME mirrors the opened state, same as a measurement');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $open[0]['transaction_id']]),
			204,
			'Undoing the opening is accepted, finding the rebuilt row by its preserved id rather than refusing'
		);

		$rows = self::rows($product);
		self::assertCount(1, $rows, 'Exactly one entry survives');
		self::assertSame($originalId, $rows[0]['id'], 'still the very same original row id throughout');
		self::assertSame(0, (int)$rows[0]['open'], 'closed again');
		self::assertSame(1.0, (float)$rows[0]['amount']);
	}

	// ------------------------------------------------------------------------------
	// #488/#555, fifth Opus review round - a GENERATED BY DEFAULT AS IDENTITY sequence
	// is not guaranteed forward-only on its own (fixed separately in
	// PostgresDialect::ResyncGeneratedIdCounters(), tested in DialectPolicyTest.php),
	// so a deleted row's id can come back under an unrelated row. I1 exercises the
	// resync defect directly; I2/I3 test this file's own defence in depth against any
	// path to that reuse, this one included, by forcing it with a direct setval() -
	// #555's own fix keeps the resync call earlier in each of these three tests from
	// causing it by itself.
	// ------------------------------------------------------------------------------

	/**
	 * I1: consume all of X, deleting its row, resync, undo the consume. Before #555,
	 * ResyncGeneratedIdCounters() set the `stock.id` sequence to exactly MAX(id) + 1
	 * regardless of where it already stood - so deleting the row holding the current
	 * highest id pulled the sequence back down with it, and this booking's own rebuild
	 * (under that same freed id, so a later-undone TRANSFER_TO/FROM or PRODUCT_OPENED
	 * booking naming it still resolves) collided with the very next ordinary purchase,
	 * which reaches for the same id through the sequence a moment later.
	 */
	public function testUndoingAFullConsumeThenAnOrdinaryPurchaseDoesNotCollideAfterAResync(): void
	{
		$product = self::insertProduct('Undo Consume Resync Collision Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased'
		);
		$originalId = (int)self::rows($product)[0]['id'];
		self::assertSame($originalId, (int)self::$db->query('SELECT MAX(id) FROM stock')->fetchColumn(),
			'Sanity: X currently holds the highest id ever issued to `stock` (this test relies on running late in the suite)');

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'X is fully consumed, deleting its row'
		);

		(new PostgresDialect())->ResyncGeneratedIdCounters(self::$db);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted, rebuilding X under its original id'
		);
		self::assertSame($originalId, (int)self::rows($product)[0]['id'], 'Sanity: X came back under its original id');

		$this->expectStatus(
			// A different due date than X's own keeps CompactStockEntries() (which does
			// not group by stock_id) from merging this into the rebuilt row - the point
			// here is a clean insert, not a merge, so the two must stay distinguishable.
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => '2031-02-02', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'An ordinary purchase right after does not collide with the rebuilt row on its primary key'
		);
		self::assertCount(2, self::rows($product), 'both X and the new purchase exist as separate rows');
	}

	/**
	 * I2: open X, consume it, resync (a no-op here, per #555's fix), then something
	 * else reuses X's freed id anyway - forced directly, since the resync no longer
	 * causes it - for an unrelated matching-amount purchase Y. Undoing the consume
	 * must rebuild X under a fresh id, since its own is now Y's; undoing the opening,
	 * whose booking still names X's original id, must refuse rather than match Y by
	 * that id alone and undo Y's opening instead - overwriting Y's due date, leaving
	 * X's own rebuilt row open, and marking the booking undone regardless.
	 */
	public function testUndoingAnOpeningRefusesRatherThanMatchingAnUnrelatedRowThatReusedItsId(): void
	{
		$product = self::insertProduct('Undo Opening Reused Id Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => '2030-01-01', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased at A, due 2030-01-01'
		);
		$xRow = self::rows($product)[0];
		$xId = (int)$xRow['id'];
		$xStockId = $xRow['stock_id'];
		self::assertSame($xId, (int)self::$db->query('SELECT MAX(id) FROM stock')->fetchColumn(),
			'Sanity: X currently holds the highest id ever issued to `stock`');

		$open = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'X is opened'
		);

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'X is consumed, deleting its row'
		);

		(new PostgresDialect())->ResyncGeneratedIdCounters(self::$db);
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $xId, false)");

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => '2040-12-12', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Y, an unrelated purchase, is bought next and receives X\'s old id'
		);
		$rowById = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($yRow, 'Sanity: Y really did land on X\'s old id');
		self::assertNotSame($xStockId, $yRow['stock_id'], 'Sanity: Y is a genuinely different lot, not X\'s own');
		self::assertSame('2040-12-12', $yRow['best_before_date'], 'Sanity: Y\'s own due date, before the undo');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted, rebuilding X under a fresh id since its own is now Y\'s'
		);
		$xRebuilt = self::$db->prepare('SELECT * FROM stock WHERE stock_id = ? AND id != ?');
		$xRebuilt->execute([$xStockId, $xId]);
		$xRebuilt = $xRebuilt->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($xRebuilt, 'Sanity: X came back under a different id, not colliding with Y');
		self::assertSame(1, (int)$xRebuilt['open'], 'Sanity: the rebuilt X is still open');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$open[0]['id']]),
			400,
			'Undoing the opening is refused: its booking\'s stock_row_id is now Y\'s id, and Y is not the lot this booking opened'
		);

		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertSame('2040-12-12', $yRow['best_before_date'], 'Y\'s own due date is untouched');
		self::assertSame(0, (int)$yRow['open'], 'Y was never opened, and still is not');
	}

	/**
	 * I3: the same defence as I2, for a whole-row transfer instead of an opening.
	 * Transfer X whole A->B, consume it at B, resync (a no-op post-#555), something
	 * else reuses X's freed id for an unrelated matching-amount purchase Y at B, undo
	 * the consume, undo the transfer. The id-only clean-relocate check at
	 * TRANSFER_TO/FROM would relocate Y back to A instead of refusing, since Y happens
	 * to share the destination location and amount the transfer itself recorded.
	 */
	public function testUndoingAWholeRowTransferRefusesRatherThanRelocatingAnUnrelatedRowThatReusedItsId(): void
	{
		$product = self::insertProduct('Undo Transfer Reused Id Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => '2030-01-01', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased at A, due 2030-01-01'
		);
		$xRow = self::rows($product)[0];
		$xId = (int)$xRow['id'];
		$xStockId = $xRow['stock_id'];
		self::assertSame($xId, (int)self::$db->query('SELECT MAX(id) FROM stock')->fetchColumn(),
			'Sanity: X currently holds the highest id ever issued to `stock`');

		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$locationA, 'location_id_to' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'X is transferred whole to B'
		);

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB]), new Response(), ['productId' => $product]),
			200,
			'X is consumed at B, deleting its row'
		);

		(new PostgresDialect())->ResyncGeneratedIdCounters(self::$db);
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $xId, false)");

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB, 'best_before_date' => '2040-12-12', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'Y, an unrelated purchase, is bought next at B and receives X\'s old id'
		);
		$rowById = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($yRow, 'Sanity: Y really did land on X\'s old id');
		self::assertNotSame($xStockId, $yRow['stock_id'], 'Sanity: Y is a genuinely different lot');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted, rebuilding X under a fresh id'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transfer[0]['transaction_id']]),
			400,
			'Undoing the transfer is refused: its bookings\' stock_row_id is now Y\'s id, and Y is not the lot this transfer moved'
		);

		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertSame(self::$locationB, (int)$yRow['location_id'], 'Y was never relocated');
		self::assertSame('2040-12-12', $yRow['best_before_date'], 'Y\'s own due date is untouched');
	}

	// ------------------------------------------------------------------------------
	// #555, sixth Opus review round (validator probe E1) - STOCK_EDIT_OLD and
	// STOCK_MEASURED_OLD undo matched by stock_row_id alone too. After #555, no
	// application path reissues a deleted row's id - but bin/victual-db-import still
	// does (it reissues the ids of source rows deleted above the source's own
	// surviving maximum), and an imported edit or measurement booking carries its own
	// stock_row_id right along with it. Forced here with a raw DELETE plus setval(),
	// the same construction the validator's own probe used.
	// ------------------------------------------------------------------------------

	/**
	 * E1: edit X (recording its pre-edit location/due date/price in the STOCK_EDIT_OLD
	 * booking this test undoes), delete X's row directly, force an unrelated product Y
	 * onto X's freed id, undo the edit. Matching by id alone accepted Y and overwrote
	 * its due date, location and price with X's own pre-edit values - an edit never
	 * changes a row's stock_id, so requiring it here costs nothing on any real edit.
	 */
	public function testUndoingAnEditRefusesRatherThanRewritingAnUnrelatedRowThatReusedItsId(): void
	{
		$product = self::insertProduct('Undo Edit Reused Id Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB, 'best_before_date' => '2044-04-04', 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 5.0]), new Response(), ['productId' => $product]),
			200,
			'X is purchased at B, due 2044-04-04, price 5'
		);
		$xId = (int)self::rows($product)[0]['id'];

		$edit = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 1, 'best_before_date' => '2030-01-01', 'open' => false, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0, 'location_id' => self::$locationA]), new Response(), ['entryId' => $xId]),
			200,
			'X is edited: location B->A, due date 2044-04-04->2030-01-01, price 5->1'
		);
		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];

		self::$db->prepare('DELETE FROM stock WHERE id = ?')->execute([$xId]);
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $xId, false)");

		$otherProduct = self::insertProduct('Undo Edit Reused Id Guard Y');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationB, 'best_before_date' => '2050-05-05', 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 9.0]), new Response(), ['productId' => $otherProduct]),
			200,
			'Y, an unrelated purchase of a different product, is bought next and receives X\'s old id'
		);
		$rowById = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($yRow, 'Sanity: Y really did land on X\'s old id');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$editOld['id']]),
			400,
			'Undoing the edit is refused: its booking\'s stock_row_id is now Y\'s id, and Y is not the lot this edit touched'
		);

		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertSame(self::$locationB, (int)$yRow['location_id'], 'Y\'s own location is untouched, not rewritten to X\'s pre-edit B');
		self::assertSame('2050-05-05', $yRow['best_before_date'], 'Y\'s own due date is untouched, not rewritten to X\'s pre-edit 2044-04-04');
		self::assertSame(9.0, (float)$yRow['price'], 'Y\'s own price is untouched, not rewritten to X\'s pre-edit 5');
	}

	/**
	 * The same defence for STOCK_MEASURED_OLD: measure X, delete its row directly,
	 * force an unrelated product Y (never opened or measured) onto X's freed id, undo
	 * the measurement. Matching by id alone would accept Y and stamp X's own
	 * pre-measurement values onto it - here, all four measurement columns null, since
	 * this was X's first measurement - clobbering whatever Y actually carries. A
	 * measurement never changes a row's stock_id either.
	 */
	public function testUndoingAMeasurementRefusesRatherThanRewritingAnUnrelatedRowThatReusedItsId(): void
	{
		$product = self::insertProduct('Undo Measurement Reused Id Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased'
		);
		$xId = (int)self::rows($product)[0]['id'];
		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'X is opened, becoming a single open unit eligible for measurement'
		);

		$measure = $this->expectStatus(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 0.5, 'qu_id' => 2]), new Response(), ['entryId' => $xId]),
			200,
			'X is measured at 0.5 of its own stock unit'
		);
		$measureOld = array_values(array_filter($measure, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_MEASURED_OLD))[0];

		self::$db->prepare('DELETE FROM stock WHERE id = ?')->execute([$xId]);
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $xId, false)");

		$otherProduct = self::insertProduct('Undo Measurement Reused Id Guard Y');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$locationB, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $otherProduct]),
			200,
			'Y, an unrelated purchase of a different product, is bought next and receives X\'s old id - never opened or measured'
		);
		$rowById = self::$db->prepare('SELECT * FROM stock WHERE id = ?');
		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertNotNull($yRow, 'Sanity: Y really did land on X\'s old id');
		self::assertSame(0, (int)$yRow['open'], 'Sanity: Y is sealed, not open');
		self::assertSame(3.0, (float)$yRow['amount'], 'Sanity: Y holds its own 3 units');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$measureOld['id']]),
			400,
			'Undoing the measurement is refused: its booking\'s stock_row_id is now Y\'s id, and Y is not the entry this measurement touched'
		);

		$rowById->execute([$xId]);
		$yRow = $rowById->fetch(PDO::FETCH_ASSOC);
		self::assertSame(0, (int)$yRow['open'], 'Y is still sealed');
		self::assertSame(3.0, (float)$yRow['amount'], 'Y still holds its own 3 units, not coerced to X\'s amount = 1');
		self::assertNull($yRow['opened_amount'], 'Y still carries no measurement');
	}

	// ------------------------------------------------------------------------------
	// CodeRabbit review of PR #531 (inline comment 4115694730) - a residue too small
	// to be real must never be written to a row at all, in ConsumeProduct(),
	// OpenProduct() or TransferProduct() alike: this PR's own zero-row skip in
	// ConsumeProduct() turned what used to be a one-step-longer-lived row (the next
	// whole-take absorbed and deleted it) into a permanent one.
	// ------------------------------------------------------------------------------

	/**
	 * A row holding 0.30000000000000004 (0.1 + 0.2 in binary floating point - forced
	 * directly here, since a real purchase-and-compact round trip does not reliably
	 * reach the database with those low bits still attached), then consume exactly
	 * 0.3. Before the fix, $stockEntry->amount - $amount (5.5e-17) was written onto
	 * the row instead of taking it whole, and this PR's own zero-row skip just above
	 * the loop left it there forever rather than the next whole-take absorbing and
	 * deleting it, as happened before that skip existed.
	 */
	public function testConsumingAFloatResidueLeavesNoPermanentResidueRow(): void
	{
		$product = self::insertProduct('Consume Float Sum No Residue');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE, 'price' => 1.0]), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased'
		);
		self::$db->exec('UPDATE stock SET amount = 0.30000000000000004 WHERE product_id = ' . $product);
		self::assertSame(0.30000000000000004, self::stockAmount($product), 'Sanity: the row now holds the exact float sum of 0.1 + 0.2, not 0.3');

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 0.3, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'The full 0.3 is consumed'
		);

		self::assertCount(0, self::rows($product), 'No residue row remains - the whole entry was taken, not split into a near-zero remainder');
		self::assertSame(0.0, self::stockAmount($product), 'and the on-hand amount is exactly zero');
	}

	/**
	 * The same scenario as above, but at a tare-configured vessel that is then reused
	 * for a second, later purchase (a different due date, so no maintenance run could ever
	 * merge it with any leftover): before the fix, the stray residue row from consuming the
	 * first purchase survived as a second entry at the vessel, and WeighLocation() (which
	 * used to require exactly one row) refused it, even though nothing a person would call a
	 * real container was left behind by the consume. WeighLocation() no longer requires one
	 * row at all (ADR-0033 decision 5, 2026-09-27; it sums whatever is there and corrects
	 * the total), so this test now also exercises that: the weighed net (2.0) exactly
	 * matches what the sole surviving row already holds, so nothing is booked at all - one
	 * more reason the residue row's absence has to be real rather than merely uncounted.
	 */
	public function testWeighingAVesselStillWorksAfterConsumingAResidueProneAmountThere(): void
	{
		$product = self::insertProduct('Consume Float Sum Weigh After');
		$tareUnit = self::insertRow('quantity_units', ['name' => 'Float Sum Weigh Tare Unit ' . $product]);
		self::insertRow('quantity_unit_conversions', ['from_qu_id' => $tareUnit, 'to_qu_id' => 2, 'factor' => 1, 'product_id' => $product]);
		$vessel = self::insertRow('locations', ['name' => 'Float Sum Weigh Vessel ' . $product, 'tare_weight' => 1.0, 'tare_qu_id' => $tareUnit]);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => $vessel, 'best_before_date' => '2030-01-01', 'purchased_date' => '2030-01-01']), new Response(), ['productId' => $product]),
			200,
			'One unit is purchased into the vessel, due 2030-01-01'
		);
		self::$db->exec('UPDATE stock SET amount = 0.30000000000000004 WHERE product_id = ' . $product);

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 0.3, 'location_id' => $vessel]), new Response(), ['productId' => $product]),
			200,
			'The full 0.3 is consumed'
		);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => $vessel, 'best_before_date' => '2031-02-02', 'purchased_date' => '2031-02-02']), new Response(), ['productId' => $product]),
			200,
			'A second, later container (a different due date) is placed in the same vessel'
		);
		self::assertCount(1, self::rows($product), 'Sanity: only the second container is there - no leftover residue row from the first');

		$this->expectStatus(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 3.0]), new Response(), ['locationId' => $vessel]),
			200,
			'Weighing the vessel is accepted - it holds exactly one stock entry, the second container'
		);
		self::assertSame(2.0, self::stockAmount($product), 'The weighed net (3.0 gross - 1.0 tare) corrects the one entry that is actually there');
	}

	/**
	 * #555 (CodeRabbit review of PR #531, inline comment 4115694734): the consume-undo
	 * rebuild reuses a deleted row's original id without telling the identity sequence
	 * about it. DatabaseImporter::Import()'s own resync (from the target's surviving
	 * row maximum, not from an id a booking merely names) can leave the sequence
	 * sitting at or below an id that is taken again once this undo runs - simulated
	 * directly here with a setval(), standing in for whatever import left the
	 * sequence that low. Before the fix, the very next ordinary purchase collided
	 * with the row this undo just rebuilt.
	 */
	public function testUndoingAFullConsumeAdvancesTheSequencePastTheRestoredIdEvenWhenItWasLeftBehind(): void
	{
		$product = self::insertProduct('Undo Consume Sequence Advance Guard');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased'
		);
		$originalId = (int)self::rows($product)[0]['id'];

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'X is fully consumed, deleting its row'
		);

		// Standing in for DatabaseImporter::Import()'s own resync, which can leave the
		// sequence at exactly a deleted row's id when that id came from the source
		// database's own numbering rather than the target's surviving rows.
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $originalId, false)");

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is accepted, rebuilding X under its original id'
		);
		self::assertSame($originalId, (int)self::rows($product)[0]['id'], 'Sanity: X came back under its original id');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => '2031-02-02', 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'An ordinary purchase right after does not collide with the rebuilt row on its primary key'
		);
		$newRow = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND best_before_date = ?');
		$newRow->execute([$product, '2031-02-02']);
		$newId = (int)$newRow->fetchColumn();
		self::assertGreaterThan($originalId, $newId, 'the new purchase\'s id is above every id that already existed, not a reused one');
	}

	/**
	 * PostgresDialect::MAX_SEQUENCE_ADVANCE_GAP (CodeRabbit review of PR #577, inline
	 * comment 4117658616): the previous test above shows a small gap still being closed and
	 * the id reused, as before this cap existed. This is the other side of the cap - a gap
	 * wide enough that closing it would mean an unbounded number of nextval() calls while
	 * holding this product's own advisory lock. Simulated cheaply with two setval() calls
	 * rather than a real import (the only thing that can actually open a gap this wide,
	 * since stock_log is not otherwise editable through the API): one jumps the sequence far
	 * ahead before the purchase, so the purchase's own ordinary insert naturally lands on a
	 * large id without any explicit id of its own, and one drops the sequence back down to
	 * its own pre-test position (still past every id anything else in this schema has used,
	 * so the fallback insert below cannot collide with an earlier test's own row) before the
	 * undo - standing in for whatever left a real import's sequence resynced from a
	 * surviving maximum this far below an id a booking still names. Past the cap,
	 * AdvanceIdentitySequence() refuses without drawing a single nextval(), and the rebuild
	 * falls back to a fresh, ordinary id instead of the abandoned one - exactly what a
	 * booking with no recorded stock_row_id at all already gets. A later TRANSFER_TO/FROM or
	 * PRODUCT_OPENED undo naming the abandoned id would then refuse safely on its own (#488);
	 * nothing in this test exercises that path, since nothing here is left to undo it.
	 */
	public function testUndoingAFullConsumeFallsBackToAFreshIdWhenTheGapExceedsTheCap(): void
	{
		$product = self::insertProduct('Undo Consume Sequence Gap Above Cap');

		$sequenceName = self::$db->query("SELECT pg_get_serial_sequence('stock', 'id')")->fetchColumn();
		$naturalPosition = (int)self::$db->query('SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM ' . $sequenceName)->fetchColumn();
		$farAhead = $naturalPosition + PostgresDialect::MAX_SEQUENCE_ADVANCE_GAP + 1;
		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $farAhead, false)");

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => $product]),
			200,
			'X is purchased, naturally landing on the far-advanced id'
		);
		$originalId = (int)self::rows($product)[0]['id'];
		self::assertSame($farAhead, $originalId, 'Sanity: the purchase landed exactly on the far-advanced id');

		$consume = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$locationA]), new Response(), ['productId' => $product]),
			200,
			'X is fully consumed, deleting its row'
		);

		self::$db->exec("SELECT setval(pg_get_serial_sequence('stock', 'id'), $naturalPosition, false)");

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $consume[0]['transaction_id']]),
			204,
			'Undoing the consume is still accepted even though the gap to its original id exceeds the cap'
		);

		$rebuilt = self::rows($product)[0];
		self::assertSame($naturalPosition, (int)$rebuilt['id'], 'the row is rebuilt under a fresh, ordinary id - the abandoned far id is above the cap');
		self::assertSame(1.0, (float)$rebuilt['amount'], 'the consumed amount is restored correctly under the fresh id');

		$sequenceAfter = (int)self::$db->query('SELECT last_value + CASE WHEN is_called THEN 1 ELSE 0 END FROM ' . $sequenceName)->fetchColumn();
		self::assertLessThan($originalId, $sequenceAfter, 'no nextval() loop ran to close the refused gap - the sequence advanced only by the fresh insert\'s own ordinary nextval() call, nowhere near the abandoned id');
	}

	// ------------------------------------------------------------------------------
	// STOCK_EDIT_OLD undo restores shopping_location_id (#531 follow-up)
	// ------------------------------------------------------------------------------

	/**
	 * The TRANSACTION_TYPE_STOCK_EDIT_OLD booking records shopping_location_id the same as
	 * every other edited column (see its own creation in EditStockEntry()), but the undo's
	 * restore array omitted it - so undoing an edit that had cleared a store restored the
	 * price and due date but left the store NULL instead of the store the edit had cleared.
	 */
	public function testUndoingAStockEditRestoresTheClearedShoppingLocation(): void
	{
		$store = self::insertRow('shopping_locations', ['name' => 'Undo Restore Store']);
		$product = self::insertProduct('Undo Restore Shopping Location');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', [
				'amount' => 2,
				'location_id' => self::$locationA,
				'shopping_location_id' => $store,
				'best_before_date' => self::FAR_FUTURE_DATE,
				'purchased_date' => '2026-01-01',
				'price' => 1.5,
			]), new Response(), ['productId' => $product]),
			200,
			'Purchased with a store recorded'
		);

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([$product]);
		$entryId = (int)$entryId->fetchColumn();

		self::assertSame($store, (int)self::rows($product)[0]['shopping_location_id'], 'The store is recorded before the edit');

		$edit = $this->expectStatus(
			// shopping_location_id: null clears the store - omitting the key entirely
			// means "keep the current value" (StockService::KeepStoredValue()), so a null
			// is required here to actually clear it.
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 2, 'best_before_date' => self::FAR_FUTURE_DATE, 'open' => false, 'purchased_date' => '2026-01-01', 'price' => 1.5, 'location_id' => self::$locationA, 'shopping_location_id' => null]), new Response(), ['entryId' => $entryId]),
			200,
			'The edit clears the store'
		);

		self::assertNull(self::rows($product)[0]['shopping_location_id'], 'The store is cleared by the edit');

		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];
		self::assertSame($store, (int)$editOld['shopping_location_id'], 'The OLD booking records the store the edit cleared');

		$this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$editOld['id']]),
			204,
			'Undoing the edit is accepted'
		);

		self::assertSame($store, (int)self::rows($product)[0]['shopping_location_id'], 'The undo restores the store the edit had cleared, not NULL');
	}
}
