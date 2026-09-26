<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #512 (audit finding M12): a caller holding only STOCK_VIEW, authenticated with a
 * real session cookie, got 200 on GET /stockentry/{id} with the stored price in the HTML.
 * StockController::StockEntryEditForm gates the page at STOCK_VIEW, the same permission
 * every other *EditForm in that controller gates its page on (LocationEditForm,
 * ProductEditForm, ShoppingLocationEditForm and StockEntryEditForm itself all check only
 * User::PERMISSION_STOCK_VIEW; the write each form posts to is gated separately, at its
 * own, stronger permission - PUT /api/stock/entry/{id} requires STOCK_EDIT). So the page
 * stays reachable on STOCK_VIEW and the fix is redaction, the same treatment
 * productform.blade.php's barcode price already got for issue #176 item 4: the price
 * input is withheld rather than the page refused.
 *
 * A first version of this fix withheld the price by rendering the same hidden
 * value="0" input the form already uses when price tracking is off entirely. Review
 * (a validated Opus pass on PR #528) reproduced a data-integrity regression that
 * introduced: stockentryform.js unconditionally posts whatever is in the price input on
 * save, so a STOCK_VIEW + STOCK_EDIT caller without STOCK_PRICES_VIEW - reachable through
 * the ungated Edit button on stockentries.blade.php, and every pre-existing user held
 * STOCK_VIEW once roles-seed ran - silently zeroed a price it could not see, on both the
 * stock row and the resulting stock_log ledger row. The fix here is instead to render no
 * price field at all when prices are not visible (views/stockentryform.blade.php) and to
 * have stockentryform.js omit the price key from the PUT body when the input is absent,
 * relying on PUT /api/stock/entry/{id} keeping the stored value for any key the body omits
 * - the server-side half landed separately on claude/sonnet_stock-edit-input-r487 (PR
 * #530), which this branch is stacked on. This class does not touch
 * controllers/Api/StockApiController.php or services/StockService.php; that partial-update
 * contract is #530's own StockEntryEditContractTest.php, in the wirecontract testsuite.
 *
 * Tier 1 per ADR-0025. Requests go through the real middleware stack - including session
 * authentication and User::CheckPermission() - via tests/Pgsql/request-subprocess-helper.php,
 * each in a process of its own, the way AuthStackTest and StockCoverageTest's price/API
 * scenarios already do; StockPagesTest's own StockEntryEditForm coverage
 * (testStockEntryEditFormCarriesTheEntryBeingEdited) calls the controller directly instead
 * and only ever does so as ADMIN, so it says nothing about what a lower-privileged, really
 * authenticated caller receives or can do.
 */
class StockEntryFormPriceTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9700;
	private const STORE = 9700;
	private const PRODUCT = 9700;
	private const DUE_DATE_FUTURE = '2099-12-31';
	private const PURCHASED_DATE = '2026-01-01';

	/** The exact fixture value the M12 audit reproduction used. */
	private const PRICE = 9876.5432;

	/** M12's fixture identity: STOCK_VIEW only, nothing else. */
	private const VIEW_ONLY_USER_ID = 9701;

	/** Control identity: STOCK_VIEW plus a direct STOCK_PRICES_VIEW grant. */
	private const PRICE_VISIBLE_USER_ID = 9702;

	/**
	 * The identity the regression needs: reaches the page (STOCK_VIEW) and can save it
	 * (STOCK_EDIT), but cannot see the price (no STOCK_PRICES_VIEW, direct or inherited -
	 * STOCK_EDIT is a sibling of STOCK_PURCHASE under STOCK, not an ancestor of it, so it
	 * does not resolve down to STOCK_PRICES_VIEW).
	 */
	private const VIEW_EDIT_USER_ID = 9703;

	/** Control: price visibility inherited through STOCK_PURCHASE, never granted directly. */
	private const INHERITED_PRICE_VISIBLE_USER_ID = 9704;

	private static PDO $db;

	/** Read by every test in this class except the save/round-trip one; never written to. */
	private static int $stockEntryId = 0;

	/** Written to by the round-trip test only, so a save can never affect a read elsewhere. */
	private static int $stockEntryIdForEdit = 0;

	private static string $viewOnlySessionKey;
	private static string $priceVisibleSessionKey;
	private static string $viewEditSessionKey;
	private static string $inheritedPriceVisibleSessionKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// The main test process's own caller identity (VICTUAL_USER_ID = 9000, per
		// PgsqlSchemaTestCase::Boot()) - defensive, matching AuthStackTest and
		// StockPagesTest, in case anything in this class's own setup ever resolves it.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockentryformprice-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$db->exec('INSERT INTO locations(id, name) VALUES (' . self::LOCATION . ", 'StockEntryFormPrice Pantry')");
		self::$db->exec('INSERT INTO shopping_locations(id, name) VALUES (' . self::STORE . ", 'StockEntryFormPrice Store')");

		// Quantity unit 2 is 'Piece', seeded by the migrations - the same assumption
		// StockPagesTest makes for its own fixture products.
		self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
			. self::PRODUCT . ", 'StockEntryFormPrice Product', " . self::LOCATION . ', 2, 2)');

		// Direct rows rather than StockService::AddProduct(): BaseService::GetInstance()
		// caches one instance per class for the life of the process
		// (services/BaseService.php), and StockPagesTest.php - which shares this phase's
		// PHP process and runs first per phpunit.xml's file order - already constructs and
		// caches StockService bound to its own schema. A fresh call here would silently
		// keep writing against that other class's (by then torn down) schema. These tests
		// only need rows FieldPolicy/the form read and PUT /api/stock/entry edits, not a
		// purchase ledger, so the direct insert is also the smaller fixture.
		self::$stockEntryId = self::insertStockEntry('stockentryformprice-read-fixture', null);

		// A second, independent entry: the round-trip save test writes to this one, so a
		// save can never change what the read-only tests above assert against.
		self::$stockEntryIdForEdit = self::insertStockEntry('stockentryformprice-edit-fixture', self::STORE);

		self::createUser(self::VIEW_ONLY_USER_ID, 'stockentryformprice-view-only');
		self::grant(self::VIEW_ONLY_USER_ID, 'STOCK_VIEW');
		self::$viewOnlySessionKey = self::issueSession(self::VIEW_ONLY_USER_ID);

		self::createUser(self::PRICE_VISIBLE_USER_ID, 'stockentryformprice-price-visible');
		self::grant(self::PRICE_VISIBLE_USER_ID, 'STOCK_VIEW');
		self::grant(self::PRICE_VISIBLE_USER_ID, 'STOCK_PRICES_VIEW');
		self::$priceVisibleSessionKey = self::issueSession(self::PRICE_VISIBLE_USER_ID);

		self::createUser(self::VIEW_EDIT_USER_ID, 'stockentryformprice-view-edit');
		self::grant(self::VIEW_EDIT_USER_ID, 'STOCK_VIEW');
		self::grant(self::VIEW_EDIT_USER_ID, 'STOCK_EDIT');
		self::$viewEditSessionKey = self::issueSession(self::VIEW_EDIT_USER_ID);

		self::createUser(self::INHERITED_PRICE_VISIBLE_USER_ID, 'stockentryformprice-inherited');
		self::grant(self::INHERITED_PRICE_VISIBLE_USER_ID, 'STOCK_VIEW');
		// STOCK_PRICES_VIEW is never granted directly here - it is a child of STOCK_PURCHASE
		// (migrations/0281.pgsql.sql), so this identity only ever sees it resolved through
		// user_permissions_resolved, exactly the way a seeded role would confer it.
		self::grant(self::INHERITED_PRICE_VISIBLE_USER_ID, 'STOCK_PURCHASE');
		self::$inheritedPriceVisibleSessionKey = self::issueSession(self::INHERITED_PRICE_VISIBLE_USER_ID);
	}

	private static function insertStockEntry(string $stockId, ?int $shoppingLocationId): int
	{
		$statement = self::$db->prepare(
			'INSERT INTO stock(product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id, shopping_location_id) '
			. 'VALUES (:product_id, 1, :best_before_date, :purchased_date, :stock_id, :price, 0, :location_id, :shopping_location_id) RETURNING id'
		);
		$statement->execute([
			'product_id' => self::PRODUCT,
			'best_before_date' => self::DUE_DATE_FUTURE,
			'purchased_date' => self::PURCHASED_DATE,
			'stock_id' => $stockId,
			'price' => self::PRICE,
			'location_id' => self::LOCATION,
			'shopping_location_id' => $shoppingLocationId,
		]);
		$id = (int)$statement->fetchColumn();
		self::assertGreaterThan(0, $id, "the fixture stock entry '$stockId' was inserted");

		return $id;
	}

	private static function createUser(int $id, string $username): void
	{
		$statement = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');
		$statement->execute([$id, $username, 'fixture']);
	}

	private static function grant(int $userId, string $permissionName): void
	{
		$statement = self::$db->prepare(
			'INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?'
		);
		$statement->execute([$userId, $permissionName]);
	}

	/** Creates a sessions row directly and returns its key - AuthStackTest's issueSession(). */
	private static function issueSession(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO sessions (session_key, user_id, expires) VALUES (?, ?, ?)');
		$statement->execute([$key, $userId, date('Y-m-d H:i:s', strtotime('+30 days'))]);

		return $key;
	}

	/**
	 * One request through the real middleware stack - session authentication included - in
	 * a process of its own, since the authentication middleware define()s the acting
	 * user's constants and PHP cannot redefine one. See request-subprocess-helper.php; this
	 * is AuthStackTest::send() and StockCoverageTest::send() narrowed to a cookie-only
	 * request.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function request(string $method, string $path, string $sessionCookie, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'cookie' => $sessionCookie];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		// $_SERVER carries non-scalar entries (argv among them) that proc_open's env
		// conversion cannot stringify.
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

		$result = json_decode($output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * Given a stock entry with a stored price and a caller who holds STOCK_VIEW only, when
	 * that caller requests the edit form with a real session cookie, then the page still
	 * renders - reaching it is unchanged, matching every other *EditForm in
	 * StockController.php - but the stored price never reaches the response body, and no
	 * price field of any kind (visible or hidden) is rendered.
	 */
	public function testViewOnlyCallerDoesNotReceiveThePrice(): void
	{
		$response = self::request('GET', '/stockentry/' . self::$stockEntryId, self::$viewOnlySessionKey);

		self::assertSame(
			200,
			$response['status'],
			'STOCK_VIEW reaches the form, the same as every other *EditForm in StockController: ' . $response['body']
		);
		self::assertStringNotContainsString(
			'9876.5432',
			$response['body'],
			'the fixture price is not in the page source, checked literally as the M12 audit reproduced it'
		);

		// No price field at all - visible or hidden - not a value="0" fallback: a save from
		// this session must not be able to post a price it never saw. Both the numberpicker
		// and the hidden-input fallback use id="price"; its absence means the field is
		// entirely missing, not merely styled invisible.
		self::assertStringNotContainsString(
			'id="price"',
			$response['body'],
			'no price field - visible or hidden - is rendered when prices are not visible to this caller'
		);

		// The page is not a refusal: it still identifies the entry being edited.
		self::assertStringContainsString(
			'Victual.EditObjectRowId = ' . self::$stockEntryId . ';',
			$response['body'],
			'the form still renders the entry - this is redaction, not a 403'
		);

		// Defence in depth on the JS side: the page-wide flag every other price-bearing
		// view/script checks (public/viewjs/*.js) agrees prices are not visible here.
		self::assertStringContainsString(
			'Victual.PricesVisible = false;',
			$response['body'],
			'the page-wide JS flag also says prices are not visible to this caller'
		);
	}

	/**
	 * Control: a caller who holds STOCK_PRICES_VIEW alongside STOCK_VIEW still sees the
	 * real price, pre-filled into the same editable input - the behaviour this fix must not
	 * take away from anyone entitled to it.
	 */
	public function testPriceVisibleCallerStillSeesAndCanEditThePrice(): void
	{
		$response = self::request('GET', '/stockentry/' . self::$stockEntryId, self::$priceVisibleSessionKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertStringContainsString(
			'9876.5432',
			$response['body'],
			'the price-visible caller still receives the stored price'
		);
		self::assertMatchesRegularExpression(
			'/<input[^>]*id="price"[^>]*value="9876.5432"/',
			$response['body'],
			'the price is in the editable input (not disabled or read-only), so this caller can still change it'
		);

		self::assertStringContainsString(
			'Victual.PricesVisible = true;',
			$response['body'],
			'the page-wide JS flag agrees prices are visible to this caller'
		);
	}

	/**
	 * Control: price visibility does not have to be a direct STOCK_PRICES_VIEW grant. A
	 * caller holding only STOCK_PURCHASE - which the permission tree resolves down to
	 * STOCK_PRICES_VIEW (docs/plans/19-rbac.md piece 2, Q6's response) - sees the price the
	 * same way the direct-grant caller above does. A seeded role confers permissions
	 * through the identical resolved-permissions view, so this also stands in for "via a
	 * role" without needing to look up a role's id.
	 */
	public function testPriceVisibilityInheritedThroughStockPurchaseStillShowsThePrice(): void
	{
		$response = self::request('GET', '/stockentry/' . self::$stockEntryId, self::$inheritedPriceVisibleSessionKey);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertStringContainsString(
			'9876.5432',
			$response['body'],
			'STOCK_PURCHASE resolves down to STOCK_PRICES_VIEW, so the price is visible without a direct grant'
		);
		self::assertStringContainsString(
			'Victual.PricesVisible = true;',
			$response['body'],
			'the page-wide JS flag agrees, for an inherited grant exactly as for a direct one'
		);
	}

	/**
	 * The blocking regression a validated review pass found in this PR's first version:
	 * rendering the withheld price as a hidden value="0" input meant a caller who could
	 * save the form but not see the price - STOCK_VIEW plus STOCK_EDIT, no
	 * STOCK_PRICES_VIEW, reachable through the ungated Edit button on
	 * stockentries.blade.php - silently zeroed a price it never saw, because
	 * stockentryform.js posts whatever is in that input unconditionally. Given a stock
	 * entry with a stored price and that identity, when it GETs the form (no price field
	 * rendered) and then PUTs exactly the body stockentryform.js builds for this instance's
	 * feature flags - amount, dates, note, open, location_id and shopping_location_id, no
	 * price key - then the request succeeds and the stored price is unchanged, both on the
	 * stock row and on the resulting stock-edit-new ledger row PUT /api/stock/entry/{id}
	 * leaves behind. This exercises the frontend half only; the server keeping an omitted
	 * key's current value is claude/sonnet_stock-edit-input-r487 (PR #530), which this
	 * branch is stacked on.
	 */
	public function testSavingTheRenderedFormAsAPriceBlindEditorDoesNotChangeThePrice(): void
	{
		$get = self::request('GET', '/stockentry/' . self::$stockEntryIdForEdit, self::$viewEditSessionKey);
		self::assertSame(200, $get['status'], $get['body']);
		self::assertStringNotContainsString('id="price"', $get['body'], 'no price field is rendered for this caller either');

		// Exactly the shape stockentryform.js builds for this instance (both feature flags
		// on by default: config-dist.php's FEATURE_FLAG_STOCK_PRICE_TRACKING and
		// FEATURE_FLAG_STOCK_LOCATION_TRACKING) - every field it sends unconditionally, plus
		// location_id and shopping_location_id, which it also sends here - with the price
		// key omitted because the input it would read from does not exist.
		$put = self::request('PUT', '/api/stock/entry/' . self::$stockEntryIdForEdit, self::$viewEditSessionKey, [
			'amount' => 1,
			'best_before_date' => self::DUE_DATE_FUTURE,
			'purchased_date' => self::PURCHASED_DATE,
			'note' => '',
			'open' => false,
			'location_id' => self::LOCATION,
			'shopping_location_id' => self::STORE,
		]);
		self::assertSame(200, $put['status'], 'the edit succeeds without a price key: ' . $put['body']);

		$stored = self::$db->query('SELECT price FROM stock WHERE id = ' . self::$stockEntryIdForEdit)->fetchColumn();
		self::assertEqualsWithDelta(
			self::PRICE,
			(float)$stored,
			0.0001,
			'the stock row keeps its price across an edit whose body never mentioned one'
		);

		$ledgerPrice = self::$db->query(
			'SELECT price FROM stock_log WHERE stock_row_id = ' . self::$stockEntryIdForEdit
			. " AND transaction_type = 'stock-edit-new' ORDER BY id DESC LIMIT 1"
		)->fetchColumn();
		self::assertNotFalse($ledgerPrice, 'the edit left a stock-edit-new ledger row behind');
		self::assertEqualsWithDelta(
			self::PRICE,
			(float)$ledgerPrice,
			0.0001,
			'the ledger row for this edit also carries the preserved price, not null or zero'
		);
	}
}
