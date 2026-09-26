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
 * Tier 1 per ADR-0025. Requests go through the real middleware stack - including session
 * authentication and User::CheckPermission() - via tests/Pgsql/request-subprocess-helper.php,
 * each in a process of its own, the way AuthStackTest and StockCoverageTest's price/API
 * scenarios already do; StockPagesTest's own StockEntryEditForm coverage
 * (testStockEntryEditFormCarriesTheEntryBeingEdited) calls the controller directly instead
 * and only ever does so as ADMIN, so it says nothing about what a lower-privileged, really
 * authenticated caller receives.
 */
class StockEntryFormPriceTest extends PgsqlSchemaTestCase
{
	private const LOCATION = 9700;
	private const PRODUCT = 9700;
	private const DUE_DATE_FUTURE = '2099-12-31';
	private const PURCHASED_DATE = '2026-01-01';

	/** The exact fixture value the M12 audit reproduction used. */
	private const PRICE = 9876.5432;

	/** M12's fixture identity: STOCK_VIEW only, nothing else. */
	private const VIEW_ONLY_USER_ID = 9701;

	/** Control identity: STOCK_VIEW plus STOCK_PRICES_VIEW - must keep seeing the price. */
	private const PRICE_VISIBLE_USER_ID = 9702;

	private static PDO $db;
	private static int $stockEntryId = 0;
	private static string $viewOnlySessionKey;
	private static string $priceVisibleSessionKey;

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

		// Quantity unit 2 is 'Piece', seeded by the migrations - the same assumption
		// StockPagesTest makes for its own fixture products.
		self::$db->exec('INSERT INTO products(id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ('
			. self::PRODUCT . ", 'StockEntryFormPrice Product', " . self::LOCATION . ', 2, 2)');

		// A direct row rather than StockService::AddProduct(): BaseService::GetInstance()
		// caches one instance per class for the life of the process
		// (services/BaseService.php), and StockPagesTest.php - which shares this phase's
		// PHP process and runs first per phpunit.xml's file order - already constructs and
		// caches StockService bound to its own schema. A fresh call here would silently
		// keep writing against that other class's (by then torn down) schema. This test
		// only needs the row FieldPolicy/the form read, not a ledger, so the direct insert
		// is also the smaller fixture.
		$statement = self::$db->prepare(
			'INSERT INTO stock(product_id, amount, best_before_date, purchased_date, stock_id, price, open, location_id) '
			. 'VALUES (:product_id, 1, :best_before_date, :purchased_date, :stock_id, :price, 0, :location_id) RETURNING id'
		);
		$statement->execute([
			'product_id' => self::PRODUCT,
			'best_before_date' => self::DUE_DATE_FUTURE,
			'purchased_date' => self::PURCHASED_DATE,
			'stock_id' => 'stockentryformprice-fixture',
			'price' => self::PRICE,
			'location_id' => self::LOCATION,
		]);
		self::$stockEntryId = (int)$statement->fetchColumn();
		self::assertGreaterThan(0, self::$stockEntryId, 'the fixture stock entry was inserted');

		self::createUser(self::VIEW_ONLY_USER_ID, 'stockentryformprice-view-only');
		self::grant(self::VIEW_ONLY_USER_ID, 'STOCK_VIEW');
		self::$viewOnlySessionKey = self::issueSession(self::VIEW_ONLY_USER_ID);

		self::createUser(self::PRICE_VISIBLE_USER_ID, 'stockentryformprice-price-visible');
		self::grant(self::PRICE_VISIBLE_USER_ID, 'STOCK_VIEW');
		self::grant(self::PRICE_VISIBLE_USER_ID, 'STOCK_PRICES_VIEW');
		self::$priceVisibleSessionKey = self::issueSession(self::PRICE_VISIBLE_USER_ID);
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
	 * One GET through the real middleware stack - session authentication included - in a
	 * process of its own, since the authentication middleware define()s the acting user's
	 * constants and PHP cannot redefine one. See request-subprocess-helper.php; this is
	 * AuthStackTest::send() and StockCoverageTest::send() narrowed to an unauthenticated-key,
	 * cookie-only GET.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function get(string $path, string $sessionCookie): array
	{
		$spec = ['method' => 'GET', 'path' => $path, 'cookie' => $sessionCookie];

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
		self::assertIsArray($result, "the request helper printed no JSON for GET $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * Given a stock entry with a stored price and a caller who holds STOCK_VIEW only, when
	 * that caller requests the edit form with a real session cookie, then the page still
	 * renders - reaching it is unchanged, matching every other *EditForm in
	 * StockController.php - but the stored price never reaches the response body: not in
	 * the price input's value, not anywhere else in the HTML.
	 */
	public function testViewOnlyCallerDoesNotReceiveThePrice(): void
	{
		$response = self::get('/stockentry/' . self::$stockEntryId, self::$viewOnlySessionKey);

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

		// Redaction, not an accident of omission: the price input becomes the same hidden
		// zero-value field the form already renders when price tracking is off entirely
		// (views/stockentryform.blade.php's pre-existing @else branch) - a class a reader
		// cannot see (d-none) would still be a price left in the page source, which is
		// exactly what plan 19 piece 2's verification 6 and issue #176 item 4 reject.
		self::assertMatchesRegularExpression(
			'/<input[^>]*name="price"[^>]*value="0"/',
			$response['body'],
			'the price field is present as the hidden zero-value fallback, not simply missing'
		);

		// This is a read-side fix; the underlying row is untouched.
		$stored = self::$db->query('SELECT price FROM stock WHERE id = ' . self::$stockEntryId)->fetchColumn();
		self::assertEqualsWithDelta(self::PRICE, (float)$stored, 0.0001, 'the stored price itself is unchanged - only the response is redacted');

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
		$response = self::get('/stockentry/' . self::$stockEntryId, self::$priceVisibleSessionKey);

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
		self::assertDoesNotMatchRegularExpression(
			'/<input[^>]*name="price"[^>]*value="0"/',
			$response['body'],
			'the hidden zero-value fallback is the redacted/no-tracking case only; it must not also render here'
		);

		self::assertStringContainsString(
			'Victual.PricesVisible = true;',
			$response['body'],
			'the page-wide JS flag agrees prices are visible to this caller'
		);
	}
}
