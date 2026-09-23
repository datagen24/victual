<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockReportsService;
use Victual\Services\StockService;
use Victual\Services\UsersService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Plan 33's "Stock bookings" and "Stock reads and lists" rows, for the behaviour the
 * existing stock coverage does not reach: what each booking endpoint *refuses*, and that
 * a refusal leaves the ledger exactly as it found it.
 *
 * The happy paths of these endpoints are already asserted elsewhere and are not repeated
 * here: `.devtools/pgsql/rollback-tests.php` owns "a write failing halfway leaves no
 * partial row", `price-visibility-tests.php` owns price and cost redaction,
 * `average-price-tests.php`, `open-container-measurement-tests.php` and
 * `working-container-tests.php` own the plan 28/29 measurement arithmetic,
 * `tests/Pgsql/ContractTest.php` owns the response shapes and
 * `tests/Pgsql/WireContractTest.php` owns the `spoiled`/`is_aggregated_amount` boolean
 * contract. What none of them do is drive an endpoint with input it must reject, so the
 * validation arms of StockApiController and StockService were the largest uncovered
 * region of both files. Each refusal here is therefore paired with a before/after
 * comparison of every `stock` and `stock_log` row: "it answered 400" and "it wrote
 * nothing" are two different claims and only the second one catches a half-write.
 *
 * Controllers are called directly (RbacTest's pattern), so a permission refusal thrown by
 * User::CheckPermission() above HandleApiCall() arrives as a thrown HttpException rather
 * than a response - see expectStatus().
 *
 * Every date in the fixture graph is pinned. An unpinned best_before_date has already
 * flipped a golden file's JSON type with the calendar day in this repository, and the
 * overdue/expired shopping-list adders below select on exactly that column.
 */
class StockCoverageTest extends PgsqlSchemaTestCase
{
	/** Pinned far in the past, so the overdue and expired selections below are date-independent. */
	private const LONG_PAST_DATE = '2020-01-15';

	/** Pinned far in the future, so an entry carrying it is never due during a run. */
	private const FAR_FUTURE_DATE = '2035-06-30';

	/** Pinned in a month that is always over, so the spendings default range must exclude it. */
	private const CLOSED_MONTH_DATE = '2026-03-05';

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;

	/** Fixture ids, filled by testCreatesFixtures() and read by every method after it. */
	private static array $ids = [];

	/** The API key the subprocess-driven scenarios at the bottom of this class act with. */
	private static string $apiKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockcoverage-caller', 'fixture')");
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function request(string $method = 'GET', $body = null, array $query = [])
	{
		$uri = 'http://localhost/api' . (empty($query) ? '' : ('?' . http_build_query($query)));
		$request = (new ServerRequestFactory())->createServerRequest($method, $uri);

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private static function grant(array $names): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = 9000; DELETE FROM user_roles WHERE user_id = 9000');
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = ?');

		foreach ($names as $name)
		{
			$statement->execute([$name]);
		}
	}

	/** The leaves every method other than the permission-refusal ones acts with. */
	private static function grantStockAndList(): void
	{
		self::grant([
			'STOCK_VIEW', 'STOCK_PURCHASE', 'STOCK_CONSUME', 'STOCK_OPEN', 'STOCK_TRANSFER',
			'STOCK_INVENTORY', 'STOCK_EDIT', 'STOCK_PRICES_VIEW',
			'SHOPPINGLIST_ITEMS_ADD', 'SHOPPINGLIST_ITEMS_DELETE',
		]);
	}

	/**
	 * Every column of every `stock` and `stock_log` row, in id order, as JSON. Compared
	 * before and after a refusal: a booking that half-wrote, wrote a log row without a
	 * stock row, or merely bumped an amount shows up as a different string.
	 */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/**
	 * Calls $work, recovering a thrown HttpException (a permission refusal raised above
	 * HandleApiCall()) into its status, and asserts the status. Returns the decoded body
	 * for a response that carries one.
	 */
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

	/** Asserts $work is refused with $status AND that it wrote nothing to the ledger. */
	private function expectRefusalWithUntouchedLedger(callable $work, int $status, string $message): array
	{
		$before = self::ledger();
		$decoded = $this->expectStatus($work, $status, $message);
		self::assertSame($before, self::ledger(), "$message: the refusal must leave stock and stock_log unchanged");
		return $decoded;
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		return (float)$statement->fetchColumn();
	}

	private static function insertProduct(string $name, array $columns = []): int
	{
		$columns = array_merge([
			'name' => $name,
			'location_id' => self::$ids['pantry'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		], $columns);

		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO products ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

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

	// ------------------------------------------------------------------------------
	// Fixture graph
	// ------------------------------------------------------------------------------

	public function testCreatesFixtures(): void
	{
		self::grantStockAndList();

		self::$ids['pantry'] = self::insertRow('locations', ['name' => 'Coverage Pantry']);
		self::$ids['freezer'] = self::insertRow('locations', ['name' => 'Coverage Freezer', 'is_freezer' => 1]);
		self::$ids['gram'] = self::insertRow('quantity_units', ['name' => 'Coverage Gram', 'name_plural' => 'Coverage Grams']);
		self::$ids['kilogram'] = self::insertRow('quantity_units', ['name' => 'Coverage Kilogram', 'name_plural' => 'Coverage Kilograms']);
		self::$ids['vessel'] = self::insertRow('locations', ['name' => 'Coverage Vessel', 'tare_weight' => 120.0, 'tare_qu_id' => self::$ids['gram']]);
		self::$ids['grocer'] = self::insertRow('shopping_locations', ['name' => 'Coverage Grocer']);
		self::$ids['market'] = self::insertRow('shopping_locations', ['name' => 'Coverage Market']);
		self::$ids['group'] = self::insertRow('product_groups', ['name' => 'Coverage Group']);
		self::$ids['other_group'] = self::insertRow('product_groups', ['name' => 'Coverage Other Group']);
		self::$ids['second_list'] = self::insertRow('shopping_lists', ['name' => 'Coverage Second List']);

		self::$ids['staple'] = self::insertProduct('Coverage Staple', ['product_group_id' => self::$ids['group']]);
		self::$ids['spare'] = self::insertProduct('Coverage Spare', ['product_group_id' => self::$ids['other_group']]);
		self::$ids['zero_stock'] = self::insertProduct('Coverage Never Stocked');
		self::$ids['retired'] = self::insertProduct('Coverage Retired', ['active' => 0]);
		self::$ids['sealed'] = self::insertProduct('Coverage Sealed', ['disable_open' => 1]);
		self::$ids['forever'] = self::insertProduct('Coverage Forever', ['default_best_before_days' => -1]);
		self::$ids['twenty_days'] = self::insertProduct('Coverage Twenty Days', ['default_best_before_days' => 20]);
		self::$ids['freezable'] = self::insertProduct('Coverage Freezable', [
			'default_best_before_days_after_freezing' => -1,
			'default_best_before_days_after_thawing' => 3,
		]);
		self::$ids['jar'] = self::insertProduct('Coverage Jar', [
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		self::$ids['bulk'] = self::insertProduct('Coverage Bulk Rice', [
			'location_id' => self::$ids['vessel'],
			'qu_id_purchase' => self::$ids['kilogram'],
			'qu_id_stock' => self::$ids['kilogram'],
			'qu_id_consume' => self::$ids['kilogram'],
			'qu_id_price' => self::$ids['kilogram'],
		]);
		self::$ids['understocked'] = self::insertProduct('Coverage Understocked', ['min_stock_amount' => 4]);
		self::$ids['overdue'] = self::insertProduct('Coverage Overdue', ['due_type' => 1]);
		self::$ids['expired'] = self::insertProduct('Coverage Expired', ['due_type' => 2]);

		// A gram reading has to reach the kilogram stock unit for these two products, or a
		// measurement of them is refused as unconvertible (ADR-0022 decision 3).
		$conversions = self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)');
		$conversions->execute([self::$ids['gram'], self::$ids['kilogram'], 0.001, self::$ids['jar']]);
		$conversions->execute([self::$ids['gram'], self::$ids['kilogram'], 0.001, self::$ids['bulk']]);

		self::insertRow('product_barcodes', ['product_id' => self::$ids['staple'], 'barcode' => '4006381333931']);
		self::insertRow('product_barcodes', ['product_id' => self::$ids['retired'], 'barcode' => 'COVERAGE-RETIRED']);

		self::assertGreaterThan(0, self::$ids['staple'], 'Fixture products created');
		self::assertSame(0.0, self::stockAmount(self::$ids['staple']), 'The fixture graph books no stock of its own');
	}

	// ------------------------------------------------------------------------------
	// Purchases: POST /api/stock/products/{productId}/add
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger(): void
	{
		$body = [
			'amount' => 4,
			'price' => 1.25,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::CLOSED_MONTH_DATE,
			'location_id' => self::$ids['freezer'],
			'shopping_location_id' => self::$ids['grocer'],
			'transaction_type' => StockService::TRANSACTION_TYPE_PURCHASE,
			'stock_label_type' => 0,
			'note' => 'Coverage purchase note',
		];

		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', $body), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'A purchase naming every documented optional field is accepted'
		);

		self::assertCount(1, $rows, 'An unlabelled purchase books one stock_log row whatever the amount');
		self::assertSame(4.0, (float)$rows[0]['amount'], 'The booking records the purchased amount');
		self::assertSame(StockService::TRANSACTION_TYPE_PURCHASE, $rows[0]['transaction_type']);
		self::assertSame(self::FAR_FUTURE_DATE, $rows[0]['best_before_date'], 'The supplied due date is used, not a derived one');
		self::assertSame(self::CLOSED_MONTH_DATE, $rows[0]['purchased_date']);
		self::assertSame('Coverage purchase note', $rows[0]['note']);
		self::assertSame(self::$ids['freezer'], (int)$rows[0]['location_id'], 'The body location wins over the product default location');
		self::assertSame(self::$ids['grocer'], (int)$rows[0]['shopping_location_id']);
		self::assertSame(1.25, (float)$rows[0]['price']);

		$entry = self::$db->prepare('SELECT * FROM stock WHERE stock_id = ?');
		$entry->execute([$rows[0]['stock_id']]);
		$entry = $entry->fetch(PDO::FETCH_ASSOC);

		self::assertNotFalse($entry, 'The booking has a stock entry behind it, not only a log row');
		self::assertSame(4.0, (float)$entry['amount']);
		self::assertSame(self::$ids['freezer'], (int)$entry['location_id']);
		self::assertSame(1.25, (float)$entry['price']);
		self::assertSame('Coverage purchase note', $entry['note']);
		self::assertSame(self::FAR_FUTURE_DATE, $entry['best_before_date']);
		self::assertSame(4.0, self::stockAmount(self::$ids['staple']), 'Stock of the product is exactly what was purchased');
	}

	/**
	 * stock_label_type 2 is documented as "one label (and stock entry) per unit", which is
	 * the only body field of this endpoint that changes how many rows a single booking
	 * writes - and the reason those entries must survive the compaction that immediately
	 * follows every purchase.
	 */
	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchasePerUnitLabelTypeWritesOneStockEntryPerUnit(): void
	{
		$body = [
			'amount' => 3,
			'price' => 2.0,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'purchased_date' => self::CLOSED_MONTH_DATE,
			'stock_label_type' => 2,
		];

		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', $body), new Response(), ['productId' => self::$ids['spare']]),
			200,
			'A per-unit labelled purchase is accepted'
		);

		self::assertCount(3, $rows, 'Three units, three bookings');
		self::assertSame([1.0, 1.0, 1.0], array_map(fn($row) => (float)$row['amount'], $rows), 'Each booking is one unit');
		self::assertCount(3, array_unique(array_column($rows, 'stock_id')), 'Each unit gets its own stock entry id');

		$entries = self::$db->prepare('SELECT amount FROM stock WHERE product_id = ? ORDER BY id');
		$entries->execute([self::$ids['spare']]);
		self::assertSame([1.0, 1.0, 1.0], array_map('floatval', $entries->fetchAll(PDO::FETCH_COLUMN)), 'Three separate entries of one unit each');

		// Negative control for the split: the same product, the same due date and price,
		// bought again without per-unit labels, is one entry - so the three above are the
		// label type's doing and not something every purchase does.
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', array_merge($body, ['stock_label_type' => 0])), new Response(), ['productId' => self::$ids['spare']]),
			200,
			'The same purchase without per-unit labels is accepted'
		);

		$entries->execute([self::$ids['spare']]);
		$amounts = array_map('floatval', $entries->fetchAll(PDO::FETCH_COLUMN));
		sort($amounts);
		self::assertSame([1.0, 1.0, 1.0, 3.0], $amounts, 'The unlabelled units are one entry; the labelled ones are not merged into it');
		self::assertSame(6.0, self::stockAmount(self::$ids['spare']), 'Six units in stock either way');
	}

	/**
	 * The product's own default_best_before_days is what a purchase that names no due date
	 * derives one from: -1 means "never due", a positive number means that many days out.
	 */
	#[Depends('testCreatesFixtures')]
	public function testPurchaseWithoutDueDateDerivesItFromTheProductDefault(): void
	{
		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['forever']]),
			200,
			'A purchase of a never-expiring product is accepted'
		);
		self::assertSame('2999-12-31', $rows[0]['best_before_date'], 'default_best_before_days = -1 books the never-due date');

		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['twenty_days']]),
			200,
			'A purchase of a product with a shelf life default is accepted'
		);
		$expected = (new \DateTimeImmutable('today'))->modify('+20 days')->format('Y-m-d');
		self::assertSame($expected, $rows[0]['best_before_date'], 'default_best_before_days = 20 books twenty days out');
	}

	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseRefusesInvalidQuantitiesAndWritesNothing(): void
	{
		foreach ([0, -3, '0', -0.5] as $amount)
		{
			$this->expectRefusalWithUntouchedLedger(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => $amount]), new Response(), ['productId' => self::$ids['staple']]),
				400,
				'A purchase of ' . var_export($amount, true) . ' units is refused'
			);
		}

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->AddProduct(self::request('POST', ['price' => 1.0]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'A purchase body with no amount at all is refused'
		);
	}

	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseRefusesMissingProduct(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => 987654]),
			400,
			'A purchase against a product id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['retired']]),
			400,
			'A purchase against an inactive product is refused'
		);
	}

	/**
	 * A location id nothing maps to is refused - but only on the path that reads the
	 * location, which is the one where no due date was supplied. See
	 * testPurchaseAcceptsAMissingLocationWhenADueDateIsSupplied() for the other half.
	 */
	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseRefusesMissingLocation(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => 987654]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'A purchase into a location id that does not exist is refused'
		);
	}

	/**
	 * DEFECT (services/StockService.php:221, controllers/Api/StockApiController.php:175):
	 * `amount` is passed straight into a `float` parameter, so a non-numeric amount raises
	 * a PHP TypeError. TypeError is an Error, not an Exception, so HandleApiCall()
	 * (controllers/Api/BaseApiController.php:189) does not catch it and the client gets a
	 * 500 from the error middleware instead of the stated refusal every other invalid
	 * amount gets. Correct behaviour is the same 400 "amount" refusal.
	 *
	 * Pinned rather than skipped, because the important half of the contract does hold:
	 * nothing is written. If the refusal is ever fixed this test fails and is updated to
	 * expect the 400.
	 */
	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseWithNonNumericAmountFailsWithoutWriting(): void
	{
		$before = self::ledger();

		try
		{
			self::$stock->AddProduct(self::request('POST', ['amount' => 'a handful']), new Response(), ['productId' => self::$ids['staple']]);
			self::fail('A non-numeric amount must not be accepted');
		}
		catch (\TypeError $error)
		{
			self::assertStringContainsString('float', $error->getMessage(), 'The current failure is the uncaught float coercion');
		}

		self::assertSame($before, self::ledger(), 'A non-numeric amount writes nothing either way');
	}

	/**
	 * DEFECT (services/StockService.php:246-251): the "location does not exist" check sits
	 * inside the branch that derives a default due date, so a purchase that supplies a due
	 * date never reaches it and books a stock entry pointing at a location id that does not
	 * exist. `stock.location_id` carries no foreign key, so the row is accepted and the
	 * entry is then invisible to every location-scoped read. Correct behaviour is to
	 * validate the location for every purchase, as ConsumeProduct() and TransferProduct()
	 * already do.
	 *
	 * Contained in a transaction that is rolled back, so the dangling row this pins does
	 * not reach the rest of the class.
	 */
	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseAcceptsAMissingLocationWhenADueDateIsSupplied(): void
	{
		self::$db->beginTransaction();

		try
		{
			$rows = $this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => 987654, 'best_before_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => self::$ids['staple']]),
				200,
				'Current behaviour: a due date skips the location check'
			);
			self::assertSame(987654, (int)$rows[0]['location_id'], 'The booking records a location that does not exist');
		}
		finally
		{
			self::$db->rollBack();
		}
	}

	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPurchaseRefusesATransactionTypeThatIsNotAnAddition(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'transaction_type' => StockService::TRANSACTION_TYPE_CONSUME]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'A purchase declaring itself a consume booking is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Consume: POST /api/stock/products/{productId}/consume
	// ------------------------------------------------------------------------------

	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testConsumeTakesOnlyTheNamedEntryAtTheNamedLocation(): void
	{
		self::$ids['recipe'] = self::insertRow('recipes', ['name' => 'Coverage Recipe']);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'A second purchase into the pantry is accepted'
		);

		$pantryEntry = self::$db->prepare('SELECT stock_id FROM stock WHERE product_id = ? AND location_id = ?');
		$pantryEntry->execute([self::$ids['staple'], self::$ids['pantry']]);
		$pantryStockId = $pantryEntry->fetchColumn();

		$rows = $this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', [
				'amount' => 1,
				'spoiled' => true,
				'transaction_type' => StockService::TRANSACTION_TYPE_CONSUME,
				'stock_entry_id' => $pantryStockId,
				'location_id' => self::$ids['pantry'],
				'recipe_id' => self::$ids['recipe'],
				'exact_amount' => false,
				'allow_subproduct_substitution' => false,
			]), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'A consume naming every documented optional field is accepted'
		);

		self::assertCount(1, $rows, 'One entry touched, one booking');
		self::assertSame(-1.0, (float)$rows[0]['amount'], 'A consume books a negative amount');
		self::assertTrue($rows[0]['spoiled'], 'spoiled is carried onto the booking as the documented boolean');
		self::assertSame(self::$ids['recipe'], (int)$rows[0]['recipe_id']);
		self::assertSame(self::$ids['pantry'], (int)$rows[0]['location_id']);

		$amounts = self::$db->prepare('SELECT location_id, amount FROM stock WHERE product_id = ? ORDER BY location_id');
		$amounts->execute([self::$ids['staple']]);
		$byLocation = [];
		foreach ($amounts->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$byLocation[(int)$row['location_id']] = (float)$row['amount'];
		}

		self::assertSame(1.0, $byLocation[self::$ids['pantry']], 'The named entry lost exactly one unit');
		// Negative control: the freezer entry is the older one and would be consumed first
		// in default order, so "the location was honoured" is only meaningful if it is intact.
		self::assertSame(4.0, $byLocation[self::$ids['freezer']], 'The entry at the other location was not touched');
	}

	#[Depends('testConsumeTakesOnlyTheNamedEntryAtTheNamedLocation')]
	public function testConsumeRefusesInvalidQuantitiesAndWritesNothing(): void
	{
		foreach ([0, -1, '0'] as $amount)
		{
			$this->expectRefusalWithUntouchedLedger(
				fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => $amount]), new Response(), ['productId' => self::$ids['staple']]),
				400,
				'Consuming ' . var_export($amount, true) . ' units is refused'
			);
		}

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['spoiled' => true]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'A consume body with no amount at all is refused'
		);
	}

	#[Depends('testConsumeTakesOnlyTheNamedEntryAtTheNamedLocation')]
	public function testConsumeRefusesMoreThanIsInStockAndWritesNothing(): void
	{
		$inStock = self::stockAmount(self::$ids['staple']);
		self::assertGreaterThan(0.0, $inStock, 'The product is stocked, so the refusal below is about the amount');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => $inStock + 0.5]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'Consuming more than is in stock is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['zero_stock']]),
			400,
			'Consuming from a product with no stock at all is refused'
		);
	}

	#[Depends('testConsumeTakesOnlyTheNamedEntryAtTheNamedLocation')]
	public function testConsumeRefusesMissingEntities(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => 987654]),
			400,
			'Consuming a product id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => 987654]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'Consuming from a location id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'transaction_type' => 'not-a-transaction-type']), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'Consuming under an unknown transaction type is refused'
		);
	}

	/**
	 * A stock entry id that belongs to a different product selects nothing to consume. The
	 * endpoint must not fall back to consuming in default order - that would book against
	 * entries the caller did not name - and must not answer 200 for a booking it did not
	 * make.
	 */
	#[Depends('testPurchasePerUnitLabelTypeWritesOneStockEntryPerUnit')]
	public function testConsumeOfAStockEntryBelongingToAnotherProductBooksNothing(): void
	{
		$foreign = self::$db->prepare('SELECT stock_id FROM stock WHERE product_id = ? LIMIT 1');
		$foreign->execute([self::$ids['spare']]);
		$foreignStockId = $foreign->fetchColumn();
		self::assertNotFalse($foreignStockId, 'The other product has an entry to name');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $foreignStockId]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'Consuming a stock entry that belongs to another product books nothing and is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Inventory corrections: POST /api/stock/products/{productId}/inventory
	// ------------------------------------------------------------------------------

	#[Depends('testConsumeTakesOnlyTheNamedEntryAtTheNamedLocation')]
	public function testInventorySetsStockToTheGivenAbsoluteAmountInBothDirections(): void
	{
		$before = self::stockAmount(self::$ids['staple']);
		$raised = $before + 3;

		$rows = $this->expectStatus(
			fn() => self::$stock->InventoryProduct(self::request('POST', [
				'new_amount' => $raised,
				'best_before_date' => self::FAR_FUTURE_DATE,
				'purchased_date' => self::CLOSED_MONTH_DATE,
				'location_id' => self::$ids['pantry'],
				'price' => 3.5,
				'shopping_location_id' => self::$ids['market'],
				'stock_label_type' => 0,
				'note' => 'Coverage inventory note',
			]), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'Raising the counted amount is accepted'
		);

		self::assertSame(3.0, (float)$rows[0]['amount'], 'Only the difference is booked, not the new total');
		self::assertSame(StockService::TRANSACTION_TYPE_INVENTORY_CORRECTION, $rows[0]['transaction_type']);
		self::assertSame('Coverage inventory note', $rows[0]['note']);
		self::assertSame(self::$ids['market'], (int)$rows[0]['shopping_location_id']);
		self::assertSame($raised, self::stockAmount(self::$ids['staple']), 'Stock now equals the counted amount');

		$rows = $this->expectStatus(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => $before]), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'Lowering the counted amount is accepted'
		);

		self::assertSame(-3.0, (float)$rows[0]['amount'], 'The correction downwards books the difference as a removal');
		self::assertSame($before, self::stockAmount(self::$ids['staple']), 'Stock is back at the counted amount');
	}

	#[Depends('testInventorySetsStockToTheGivenAbsoluteAmountInBothDirections')]
	public function testInventoryRefusesANoOpAndMissingInput(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => self::stockAmount(self::$ids['staple'])]), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'Counting the amount that is already recorded is refused rather than booked as a zero correction'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['note' => 'no amount here']), new Response(), ['productId' => self::$ids['staple']]),
			400,
			'An inventory body with no new_amount is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => 5]), new Response(), ['productId' => 987654]),
			400,
			'An inventory correction of a product id that does not exist is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Opening and measuring: POST /api/stock/products/{productId}/open,
	// POST /api/stock/entry/{entryId}/measure
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testOpenRefusesMissingEntitiesAndImpossibleAmounts(): void
	{
		// Two entries of different sizes, kept apart by different due dates so compaction
		// cannot merge them: the small one is what the measurement coherence check refuses.
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE, 'price' => 4.0]), new Response(), ['productId' => self::$ids['jar']]),
			200,
			'A full jar is purchased'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 0.5, 'best_before_date' => '2034-01-31', 'purchased_date' => self::CLOSED_MONTH_DATE, 'price' => 2.0]), new Response(), ['productId' => self::$ids['jar']]),
			200,
			'A part jar is purchased'
		);

		$entries = self::$db->prepare('SELECT stock_id, amount FROM stock WHERE product_id = ? ORDER BY amount');
		$entries->execute([self::$ids['jar']]);
		$byAmount = [];
		foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$byAmount[(string)(float)$row['amount']] = $row['stock_id'];
		}
		self::$ids['jar_full_entry'] = $byAmount['1'];
		self::$ids['jar_part_entry'] = $byAmount['0.5'];

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => 987654]),
			400,
			'Opening a product id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['spoiled' => true]), new Response(), ['productId' => self::$ids['jar']]),
			400,
			'An open body with no amount is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['sealed']]),
			400,
			'A product whose opening is disabled is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 99]), new Response(), ['productId' => self::$ids['jar']]),
			400,
			'Opening more than is unopened in stock is refused'
		);
	}

	/**
	 * A measurement describes one physical container (ADR-0022 decision 8), so the endpoint
	 * refuses every shape of request that would attach one to something else: no named
	 * entry, more than one unit, or an entry that does not even hold a whole unit.
	 */
	#[Depends('testOpenRefusesMissingEntitiesAndImpossibleAmounts')]
	public function testOpenRefusesAMeasurementThatCannotDescribeOneContainer(): void
	{
		$measurement = ['amount' => 850, 'qu_id' => self::$ids['gram'], 'gross' => true, 'tare' => 50];

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'measurement' => $measurement]), new Response(), ['productId' => self::$ids['jar']]),
			400,
			'A measurement without a named stock entry is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1.5, 'stock_entry_id' => self::$ids['jar_full_entry'], 'measurement' => $measurement]), new Response(), ['productId' => self::$ids['jar']]),
			400,
			'A measurement opening more than one unit is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => self::$ids['jar_part_entry'], 'measurement' => $measurement]), new Response(), ['productId' => self::$ids['jar']]),
			400,
			'A measurement of an entry holding less than one unit is refused'
		);
	}

	#[Depends('testOpenRefusesAMeasurementThatCannotDescribeOneContainer')]
	public function testOpenRefusesAnIncoherentMeasurementReading(): void
	{
		$named = ['amount' => 1, 'stock_entry_id' => self::$ids['jar_full_entry']];

		foreach ([
			'a zero reading' => ['amount' => 0, 'qu_id' => self::$ids['gram']],
			'a negative reading' => ['amount' => -10, 'qu_id' => self::$ids['gram']],
			'a non-numeric reading' => ['amount' => 'heavy', 'qu_id' => self::$ids['gram']],
			'a non-numeric unit' => ['amount' => 850, 'qu_id' => 'grams please'],
			'a gross reading with no tare' => ['amount' => 850, 'qu_id' => self::$ids['gram'], 'gross' => true],
			'a tare at least as large as the reading' => ['amount' => 50, 'qu_id' => self::$ids['gram'], 'gross' => true, 'tare' => 50],
			'a unit that does not convert to the stock unit' => ['amount' => 3, 'qu_id' => 2],
		] as $case => $measurement)
		{
			$this->expectRefusalWithUntouchedLedger(
				fn() => self::$stock->OpenProduct(self::request('POST', array_merge($named, ['measurement' => $measurement])), new Response(), ['productId' => self::$ids['jar']]),
				400,
				"Opening with $case is refused"
			);
		}
	}

	/**
	 * The happy path the refusals above are the boundary of, and the only place in the
	 * suite where opened_measured_at carries a real value: every other fixture leaves it
	 * null, so the rendering the OpenAPI document pins
	 * (StockLogEntry.opened_measured_at, pattern ^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$)
	 * has never been asserted against one.
	 */
	#[Depends('testOpenRefusesAnIncoherentMeasurementReading')]
	public function testOpenWithAGrossMeasurementStoresNetContentsAndWhenTheyWereMeasured(): void
	{
		$before = date('Y-m-d H:i:s');

		$rows = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', [
				'amount' => 1,
				'stock_entry_id' => self::$ids['jar_full_entry'],
				'allow_subproduct_substitution' => false,
				'measurement' => ['amount' => 850, 'qu_id' => self::$ids['gram'], 'gross' => true, 'tare' => 50],
			]), new Response(), ['productId' => self::$ids['jar']]),
			200,
			'Opening one named jar with a gross reading is accepted'
		);

		$after = date('Y-m-d H:i:s');

		$opened = null;
		foreach ($rows as $row)
		{
			if ($row['transaction_type'] === StockService::TRANSACTION_TYPE_PRODUCT_OPENED)
			{
				$opened = $row;
			}
		}
		self::assertNotNull($opened, 'The transaction contains the product-opened booking');

		self::assertSame(800.0, (float)$opened['opened_amount'], 'The gross reading minus the tare is what is stored as contents');
		self::assertSame(self::$ids['gram'], (int)$opened['opened_qu_id'], 'The contents keep the unit they were read in');
		self::assertSame(50.0, (float)$opened['opened_tare'], 'The tare that was subtracted is recorded rather than lost');
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$opened['opened_measured_at'], 'opened_measured_at is rendered as the document says');
		self::assertGreaterThanOrEqual($before, (string)$opened['opened_measured_at'], 'The measurement is stamped no earlier than the call');
		self::assertLessThanOrEqual($after, (string)$opened['opened_measured_at'], 'The measurement is stamped no later than the call');

		$entry = self::$db->prepare('SELECT open, amount, opened_amount, opened_qu_id, opened_tare, opened_measured_at FROM stock WHERE stock_id = ?');
		$entry->execute([self::$ids['jar_full_entry']]);
		$entry = $entry->fetch(PDO::FETCH_ASSOC);

		self::assertSame(1, (int)$entry['open'], 'The entry is now open');
		self::assertSame(1.0, (float)$entry['amount'], 'Opening a container does not change how much of it there is');
		self::assertSame(800.0, (float)$entry['opened_amount'], 'The durable entry carries the same contents as the booking');
		self::assertSame($opened['opened_measured_at'], $entry['opened_measured_at'], 'Entry and booking agree on when it was measured');

		// Negative control: the other entry of the same product was not opened or measured.
		$other = self::$db->prepare('SELECT open, opened_amount, opened_measured_at FROM stock WHERE stock_id = ?');
		$other->execute([self::$ids['jar_part_entry']]);
		$other = $other->fetch(PDO::FETCH_ASSOC);
		self::assertSame(0, (int)$other['open'], 'The entry that was not named stays sealed');
		self::assertNull($other['opened_amount'], 'and carries no contents figure');
		self::assertNull($other['opened_measured_at'], 'and no measurement time');
	}

	#[Depends('testOpenWithAGrossMeasurementStoresNetContentsAndWhenTheyWereMeasured')]
	public function testMeasureRefusesEntriesThatAreNotOneOpenContainer(): void
	{
		$partEntryId = self::$db->prepare('SELECT id FROM stock WHERE stock_id = ?');
		$partEntryId->execute([self::$ids['jar_part_entry']]);
		$partEntryId = (int)$partEntryId->fetchColumn();

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 700, 'qu_id' => self::$ids['gram']]), new Response(), ['entryId' => 987654]),
			400,
			'Measuring a stock entry id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 700, 'qu_id' => self::$ids['gram']]), new Response(), ['entryId' => $partEntryId]),
			400,
			'Measuring an entry that is neither open nor a single unit is refused'
		);

		$openEntryId = self::$db->prepare('SELECT id FROM stock WHERE stock_id = ?');
		$openEntryId->execute([self::$ids['jar_full_entry']]);
		$openEntryId = (int)$openEntryId->fetchColumn();

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['qu_id' => self::$ids['gram']]), new Response(), ['entryId' => $openEntryId]),
			400,
			'A measure body with no amount is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 700]), new Response(), ['entryId' => $openEntryId]),
			400,
			'A measure body with no qu_id is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 700, 'qu_id' => self::$ids['gram'], 'gross' => true]), new Response(), ['entryId' => $openEntryId]),
			400,
			'A gross measure reading with no tare is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Transfers: POST /api/stock/products/{productId}/transfer
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testTransferMovesOnlyTheNamedEntryBetweenLocations(): void
	{
		// Its own product, so the amounts asserted below do not depend on what the purchase,
		// consume and inventory methods above left behind.
		self::$ids['movable'] = self::insertProduct('Coverage Movable');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$ids['freezer'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['movable']]),
			200,
			'Five units are purchased into the freezer'
		);

		$named = self::$db->prepare('SELECT stock_id FROM stock WHERE product_id = ? AND location_id = ?');
		$named->execute([self::$ids['movable'], self::$ids['freezer']]);
		$freezerStockId = $named->fetchColumn();

		$rows = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', [
				'amount' => 2,
				'location_id_from' => self::$ids['freezer'],
				'location_id_to' => self::$ids['pantry'],
				'stock_entry_id' => $freezerStockId,
			]), new Response(), ['productId' => self::$ids['movable']]),
			200,
			'Transferring a named entry between two locations is accepted'
		);

		$types = array_column($rows, 'transaction_type');
		self::assertContains(StockService::TRANSACTION_TYPE_TRANSFER_FROM, $types, 'A transfer books the leaving half');
		self::assertContains(StockService::TRANSACTION_TYPE_TRANSFER_TO, $types, 'and the arriving half');
		self::assertSame(5.0, self::stockAmount(self::$ids['movable']), 'A transfer moves stock, it does not create or destroy it');

		$atLocation = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$atLocation->execute([self::$ids['movable'], self::$ids['pantry']]);
		self::assertSame(2.0, (float)$atLocation->fetchColumn(), 'The destination gained the transferred units');
		$atLocation->execute([self::$ids['movable'], self::$ids['freezer']]);
		self::assertSame(3.0, (float)$atLocation->fetchColumn(), 'The source kept the rest');
	}

	#[Depends('testTransferMovesOnlyTheNamedEntryBetweenLocations')]
	public function testTransferRefusesMissingInputAndMissingEntities(): void
	{
		$complete = ['amount' => 1, 'location_id_from' => self::$ids['freezer'], 'location_id_to' => self::$ids['pantry']];

		foreach (['amount', 'location_id_from', 'location_id_to'] as $omitted)
		{
			$body = $complete;
			unset($body[$omitted]);
			$this->expectRefusalWithUntouchedLedger(
				fn() => self::$stock->TransferProduct(self::request('POST', $body), new Response(), ['productId' => self::$ids['movable']]),
				400,
				"A transfer body with no $omitted is refused"
			);
		}

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->TransferProduct(self::request('POST', $complete), new Response(), ['productId' => 987654]),
			400,
			'Transferring a product id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->TransferProduct(self::request('POST', array_merge($complete, ['location_id_from' => 987654])), new Response(), ['productId' => self::$ids['movable']]),
			400,
			'Transferring from a location id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->TransferProduct(self::request('POST', array_merge($complete, ['location_id_to' => 987654])), new Response(), ['productId' => self::$ids['movable']]),
			400,
			'Transferring to a location id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->TransferProduct(self::request('POST', array_merge($complete, ['amount' => 99])), new Response(), ['productId' => self::$ids['movable']]),
			400,
			'Transferring more than the source location holds is refused'
		);
	}

	/**
	 * Moving stock into and out of a freezer rewrites the entry's due date from the
	 * product's freezing and thawing defaults - the one transfer effect that is not just
	 * a change of location_id.
	 */
	#[Depends('testCreatesFixtures')]
	public function testTransferIntoAndOutOfAFreezerRewritesTheDueDate(): void
	{
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['freezable']]),
			200,
			'A freezable product is purchased into the pantry'
		);

		$dueDate = self::$db->prepare('SELECT best_before_date FROM stock WHERE product_id = ?');
		$dueDate->execute([self::$ids['freezable']]);
		self::assertSame(self::FAR_FUTURE_DATE, $dueDate->fetchColumn(), 'It starts on the purchased due date');

		$this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => self::$ids['freezable']]),
			200,
			'Freezing it is accepted'
		);
		$dueDate->execute([self::$ids['freezable']]);
		self::assertSame('2999-12-31', $dueDate->fetchColumn(), 'default_best_before_days_after_freezing = -1 makes a frozen entry never due');

		$this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['freezer'], 'location_id_to' => self::$ids['pantry']]), new Response(), ['productId' => self::$ids['freezable']]),
			200,
			'Thawing it is accepted'
		);
		$dueDate->execute([self::$ids['freezable']]);
		self::assertSame(
			(new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
			$dueDate->fetchColumn(),
			'default_best_before_days_after_thawing = 3 makes a thawed entry due in three days'
		);
	}

	// ------------------------------------------------------------------------------
	// Weighing a vessel: POST /api/stock/locations/{locationId}/weigh and
	// POST /api/stock/locations/by-label/{code}/weigh
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testWeighingAVesselCorrectsItsOneEntryToTheNetReading(): void
	{
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$ids['vessel'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['bulk']]),
			200,
			'The vessel is filled'
		);

		// 1120 g gross - 120 g of vessel = 1000 g, and a gram is a thousandth of the
		// kilogram this product is stocked in.
		$rows = $this->expectStatus(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 1120, 'gross_qu_id' => self::$ids['gram']]), new Response(), ['locationId' => self::$ids['vessel']]),
			200,
			'Weighing the vessel in its own tare unit is accepted'
		);

		$types = array_column($rows, 'transaction_type');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD, $types, 'The correction records what the entry was');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_EDIT_NEW, $types, 'and what it became');
		self::assertSame(1.0, self::stockAmount(self::$ids['bulk']), 'The entry now holds the weighed net contents in the stock unit');
	}

	#[Depends('testWeighingAVesselCorrectsItsOneEntryToTheNetReading')]
	public function testWeighingRefusesMissingInputAndUnknownVessels(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_qu_id' => self::$ids['gram']]), new Response(), ['locationId' => self::$ids['vessel']]),
			400,
			'A weigh body with no gross_amount is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 500]), new Response(), ['locationId' => 987654]),
			400,
			'Weighing a location id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 500, 'gross_qu_id' => 2]), new Response(), ['locationId' => self::$ids['vessel']]),
			400,
			'A reading declared in a unit other than the vessel tare unit is refused rather than misweighed'
		);
	}

	#[Depends('testWeighingAVesselCorrectsItsOneEntryToTheNetReading')]
	public function testWeighingByLabelResolvesTheVesselAndRefusesAnUnknownCode(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->WeighLocationByLabel(self::request('POST', ['gross_amount' => 620]), new Response(), ['code' => 'vctl:0000000000000']),
			400,
			'A label payload that maps to nothing is refused'
		);

		// Issue() takes the import lock, which requires the caller's transaction so that a
		// rollback also undoes the mapping.
		$identity = new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw());
		$epoch = (int)self::$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		self::$db->beginTransaction();
		$uid = $identity->IssueLocation(self::$ids['vessel'], $epoch);
		self::$db->commit();

		$this->expectStatus(
			fn() => self::$stock->WeighLocationByLabel(self::request('POST', ['gross_amount' => 620]), new Response(), ['code' => 'vctl:' . $uid]),
			200,
			'Weighing the vessel by its own scanned label is accepted'
		);

		self::assertSame(0.5, self::stockAmount(self::$ids['bulk']), 'The scanned vessel is the one that was corrected');
	}

	// ------------------------------------------------------------------------------
	// Undo and merge
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testUndoRefusesBookingsAndTransactionsThatDoNotExist(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => 987654]),
			400,
			'Undoing a booking id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => 'no-such-transaction']),
			400,
			'Undoing a transaction id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->StockBooking(self::request(), new Response(), ['bookingId' => 987654]),
			400,
			'Reading a booking id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->StockTransactions(self::request(), new Response(), ['transactionId' => 'no-such-transaction']),
			400,
			'Reading a transaction id that does not exist is refused'
		);
	}

	#[Depends('testCreatesFixtures')]
	public function testMergeRefusesUnusableProductPairs(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MergeProducts(self::request('POST'), new Response(), ['productIdToKeep' => 'not-an-id', 'productIdToRemove' => self::$ids['spare']]),
			400,
			'A merge naming a product id that is not an integer is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MergeProducts(self::request('POST'), new Response(), ['productIdToKeep' => 987654, 'productIdToRemove' => self::$ids['spare']]),
			400,
			'A merge into a product that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MergeProducts(self::request('POST'), new Response(), ['productIdToKeep' => self::$ids['staple'], 'productIdToRemove' => 987654]),
			400,
			'A merge of a product that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->MergeProducts(self::request('POST'), new Response(), ['productIdToKeep' => self::$ids['staple'], 'productIdToRemove' => self::$ids['staple']]),
			400,
			'A merge of a product into itself is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Barcodes: the by-barcode booking variants and the unresolved cases
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testProductDetailsByBarcodeRefusesBarcodesThatResolveToNothing(): void
	{
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => 'NOTHING-MAPS-HERE']),
			400,
			'A barcode no product carries is refused'
		);

		// The barcode row still exists; the product behind it is inactive. The endpoint
		// must refuse rather than answer with a product the rest of the API treats as gone.
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => 'COVERAGE-RETIRED']),
			400,
			'A barcode mapping to an inactive product is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => 'grcy:r:1']),
			400,
			'A Grocycode naming something that is not a product is refused'
		);

		$details = $this->expectStatus(
			fn() => self::$stock->ProductDetailsByBarcode(self::request(), new Response(), ['barcode' => '4006381333931']),
			200,
			'A barcode a product does carry resolves'
		);
		self::assertSame(self::$ids['staple'], (int)$details['product']['id'], 'and resolves to that product');
	}

	/**
	 * A product Grocycode may carry the stock entry it was printed for, which the by-barcode
	 * booking routes inject as stock_entry_id - the scanner's way of saying "this jar", not
	 * "this product".
	 */
	#[Depends('testCreatesFixtures')]
	public function testByBarcodeBookingsHonourTheStockEntryInTheGrocycode(): void
	{
		// Two purchases rather than a purchase and a transfer: a transfer copies the source
		// entry's stock_id onto the new row, so the two halves of a transferred entry cannot
		// be told apart by the id a Grocycode carries.
		self::$ids['scanned'] = self::insertProduct('Coverage Scanned');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['scanned']]),
			200,
			'The later-due entry is purchased into the pantry'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$ids['freezer'], 'best_before_date' => '2034-01-31', 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['scanned']]),
			200,
			'The sooner-due entry is purchased into the freezer'
		);

		$entryAt = self::$db->prepare('SELECT stock_id FROM stock WHERE product_id = ? AND location_id = ?');
		$entryAt->execute([self::$ids['scanned'], self::$ids['pantry']]);
		$pantryStockId = $entryAt->fetchColumn();
		$grocycode = 'grcy:p:' . self::$ids['scanned'] . ':' . $pantryStockId;

		$amountAt = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');

		// Default consume order would take the sooner-due freezer entry, so the freezer
		// amount below is the negative control for the id having been honoured at all.
		$this->expectStatus(
			fn() => self::$stock->ConsumeProductByBarcode(self::request('POST', ['amount' => 1]), new Response(), ['barcode' => $grocycode]),
			200,
			'Consuming by a Grocycode carrying a stock entry is accepted'
		);
		$amountAt->execute([self::$ids['scanned'], self::$ids['pantry']]);
		self::assertSame(1.0, (float)$amountAt->fetchColumn(), 'The entry the code names lost the unit');
		$amountAt->execute([self::$ids['scanned'], self::$ids['freezer']]);
		self::assertSame(3.0, (float)$amountAt->fetchColumn(), 'The entry that would have been consumed by default was not touched');

		$this->expectStatus(
			fn() => self::$stock->OpenProductByBarcode(self::request('POST', ['amount' => 1]), new Response(), ['barcode' => $grocycode]),
			200,
			'Opening by the same Grocycode is accepted'
		);
		$openState = self::$db->prepare('SELECT open FROM stock WHERE stock_id = ?');
		$openState->execute([$pantryStockId]);
		self::assertSame(1, (int)$openState->fetchColumn(), 'The entry the code names is the one that was opened');

		$entryAt->execute([self::$ids['scanned'], self::$ids['freezer']]);
		$freezerStockId = $entryAt->fetchColumn();

		$this->expectStatus(
			fn() => self::$stock->TransferProductByBarcode(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['freezer'], 'location_id_to' => self::$ids['pantry']]), new Response(), ['barcode' => 'grcy:p:' . self::$ids['scanned'] . ':' . $freezerStockId]),
			200,
			'Transferring by a Grocycode carrying a stock entry is accepted'
		);
		$amountAt->execute([self::$ids['scanned'], self::$ids['freezer']]);
		self::assertSame(2.0, (float)$amountAt->fetchColumn(), 'The named entry gave up one unit');
		self::assertSame(4.0, self::stockAmount(self::$ids['scanned']), 'and the transfer neither created nor destroyed stock');
	}

	// ------------------------------------------------------------------------------
	// Reads: product details, entries, locations, volatile stock, price history
	// ------------------------------------------------------------------------------

	/**
	 * A product with no stock_current row at all: every stock figure reads zero, and
	 * is_aggregated_amount has to reach the wire as the documented `false` rather than the
	 * `0` the fallback object carries (services/WireBooleans.php, issue #230). No other
	 * test drives this route for an unstocked product.
	 */
	#[Depends('testCreatesFixtures')]
	public function testProductDetailsOfAnUnstockedProductReportsZeroAndABooleanFlag(): void
	{
		$details = $this->expectStatus(
			fn() => self::$stock->ProductDetails(self::request(), new Response(), ['productId' => self::$ids['zero_stock']]),
			200,
			'Details of a product that was never stocked are readable'
		);

		self::assertSame(0.0, (float)$details['stock_amount'], 'No stock means an amount of zero, not a missing key');
		self::assertSame(0.0, (float)$details['stock_amount_aggregated']);
		self::assertSame(0.0, (float)$details['stock_amount_opened']);
		self::assertSame(0.0, (float)$details['stock_value']);
		self::assertArrayHasKey('is_aggregated_amount', $details, 'The flag is present even with no stock row to read it from');
		self::assertIsBool($details['is_aggregated_amount'], 'It is a boolean on the wire, not the 0 the fallback object holds');
		self::assertFalse($details['is_aggregated_amount'], 'A product with no stock aggregates nothing');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->ProductDetails(self::request(), new Response(), ['productId' => 987654]),
			400,
			'Details of a product id that does not exist are refused'
		);
	}

	/**
	 * include_sub_products is documented on both entry and location reads; with a real
	 * parent/child pair the difference between the two answers is what proves it was read.
	 */
	#[Depends('testCreatesFixtures')]
	public function testSubProductEntriesAndLocationsAppearOnlyWhenAskedFor(): void
	{
		self::$ids['parent'] = self::insertProduct('Coverage Parent');
		self::$ids['child'] = self::insertProduct('Coverage Child', ['parent_product_id' => self::$ids['parent'], 'location_id' => self::$ids['pantry']]);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['child']]),
			200,
			'The sub product is stocked'
		);

		$plain = $this->expectStatus(
			fn() => self::$stock->ProductStockEntries(self::request(), new Response(), ['productId' => self::$ids['parent']]),
			200,
			'The parent product entry list is readable'
		);
		self::assertSame([], $plain, 'The parent has no stock of its own');

		$withSubs = $this->expectStatus(
			fn() => self::$stock->ProductStockEntries(self::request('GET', null, ['include_sub_products' => 'true']), new Response(), ['productId' => self::$ids['parent']]),
			200,
			'The parent product entry list including sub products is readable'
		);
		self::assertCount(1, $withSubs, 'Asking for sub products returns the child stock entry');
		self::assertSame(self::$ids['child'], (int)$withSubs[0]['product_id']);

		$plainLocations = $this->expectStatus(
			fn() => self::$stock->ProductStockLocations(self::request(), new Response(), ['productId' => self::$ids['parent']]),
			200,
			'The parent product location list is readable'
		);
		self::assertSame([], $plainLocations, 'The parent is stocked nowhere of its own');

		$withSubLocations = $this->expectStatus(
			fn() => self::$stock->ProductStockLocations(self::request('GET', null, ['include_sub_products' => 'true']), new Response(), ['productId' => self::$ids['parent']]),
			200,
			'The parent product location list including sub products is readable'
		);
		self::assertCount(1, $withSubLocations, 'Asking for sub products returns the location the child is stocked at');
		self::assertSame(self::$ids['pantry'], (int)$withSubLocations[0]['location_id']);
	}

	#[Depends('testCreatesFixtures')]
	public function testVolatileStockDueSoonHorizonIsTheQueryParameter(): void
	{
		self::$ids['due_soon'] = self::insertProduct('Coverage Due Soon');
		$dueInThreeDays = (new \DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => $dueInThreeDays, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['due_soon']]),
			200,
			'A product due in three days is stocked'
		);

		$narrow = $this->expectStatus(
			fn() => self::$stock->CurrentVolatileStock(self::request('GET', null, ['due_soon_days' => 1]), new Response(), []),
			200,
			'Volatile stock with a one day horizon is readable'
		);
		self::assertNotContains(self::$ids['due_soon'], array_map('intval', array_column($narrow['due_products'], 'product_id')), 'A one day horizon does not reach three days out');

		$wide = $this->expectStatus(
			fn() => self::$stock->CurrentVolatileStock(self::request('GET', null, ['due_soon_days' => 10]), new Response(), []),
			200,
			'Volatile stock with a ten day horizon is readable'
		);
		self::assertContains(self::$ids['due_soon'], array_map('intval', array_column($wide['due_products'], 'product_id')), 'A ten day horizon does');
	}

	#[Depends('testPurchaseCarriesEveryOptionalBodyFieldOntoTheLedger')]
	public function testPriceHistoryReadsPurchasesAndRefusesAnUnknownProduct(): void
	{
		$history = $this->expectStatus(
			fn() => self::$stock->ProductPriceHistory(self::request(), new Response(), ['productId' => self::$ids['staple']]),
			200,
			'The price history of a purchased product is readable'
		);
		self::assertNotEmpty($history, 'The purchases above are in the history');

		$this->expectStatus(
			fn() => self::$stock->ProductPriceHistory(self::request(), new Response(), ['productId' => 987654]),
			400,
			'The price history of a product id that does not exist is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Editing a single entry: PUT /api/stock/entry/{entryId}
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testEditingAnEntryOpenFlagStampsTheOpenedDateAndRefusesUnknownEntries(): void
	{
		self::$ids['editable'] = self::insertProduct('Coverage Editable');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE, 'price' => 1.0]), new Response(), ['productId' => self::$ids['editable']]),
			200,
			'The editable product is stocked'
		);

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([self::$ids['editable']]);
		$entryId = (int)$entryId->fetchColumn();

		// `open` and `purchased_date` are read unconditionally by this route (see its
		// docblock), so a complete body carries them.
		$edit = [
			'amount' => 2,
			'open' => true,
			'purchased_date' => self::CLOSED_MONTH_DATE,
			'best_before_date' => self::FAR_FUTURE_DATE,
			'price' => 1.5,
			'location_id' => self::$ids['pantry'],
			'shopping_location_id' => self::$ids['grocer'],
			'note' => 'Coverage edit note',
		];

		$rows = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', $edit), new Response(), ['entryId' => $entryId]),
			200,
			'Editing an entry open is accepted'
		);

		$types = array_column($rows, 'transaction_type');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD, $types, 'An edit records what the entry was');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_EDIT_NEW, $types, 'and what it became');

		$entry = self::$db->prepare('SELECT open, opened_date, price, note FROM stock WHERE id = ?');
		$entry->execute([$entryId]);
		$entry = $entry->fetch(PDO::FETCH_ASSOC);
		self::assertSame(1, (int)$entry['open'], 'The entry is open');
		self::assertSame(date('Y-m-d'), $entry['opened_date'], 'Opening it through an edit stamps today as the opened date');
		self::assertSame(1.5, (float)$entry['price'], 'The edited price is stored');
		self::assertSame('Coverage edit note', $entry['note']);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->EditStockEntry(self::request('PUT', $edit), new Response(), ['entryId' => 987654]),
			400,
			'Editing a stock entry id that does not exist is refused'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['open' => false, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['entryId' => $entryId]),
			400,
			'An edit body with no amount is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// Shopping list writes
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testShoppingListAddProductInsertsThenAccumulates(): void
	{
		self::$db->exec('DELETE FROM shopping_list');

		$this->expectStatus(
			fn() => self::$stock->AddProductToShoppingList(self::request('POST', [
				'product_id' => self::$ids['staple'],
				'product_amount' => 2,
				'qu_id' => 2,
				'note' => 'Coverage list note',
				'list_id' => self::$ids['second_list'],
			]), new Response(), []),
			204,
			'Adding a product to a named list is accepted'
		);

		$entryQuery = self::$db->prepare('SELECT amount, qu_id, note, shopping_list_id FROM shopping_list WHERE product_id = ?');
		$entryQuery->execute([self::$ids['staple']]);
		$entry = $entryQuery->fetch(PDO::FETCH_ASSOC);
		self::assertSame(2.0, (float)$entry['amount']);
		self::assertSame(2, (int)$entry['qu_id'], 'The named quantity unit is used, not the product default');
		self::assertSame('Coverage list note', $entry['note']);
		self::assertSame(self::$ids['second_list'], (int)$entry['shopping_list_id'], 'The item is on the named list');

		$this->expectStatus(
			fn() => self::$stock->AddProductToShoppingList(self::request('POST', ['product_id' => self::$ids['staple'], 'product_amount' => 3, 'list_id' => self::$ids['second_list']]), new Response(), []),
			204,
			'Adding the same product again is accepted'
		);

		$entryQuery->execute([self::$ids['staple']]);
		$entry = $entryQuery->fetch(PDO::FETCH_ASSOC);
		self::assertSame(5.0, (float)$entry['amount'], 'A second add accumulates onto the existing entry');
		self::assertSame(1, (int)self::$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn(), 'and does not create a second one');
	}

	#[Depends('testShoppingListAddProductInsertsThenAccumulates')]
	public function testShoppingListAddProductRefusesMissingEntities(): void
	{
		$before = self::$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();

		$this->expectStatus(
			fn() => self::$stock->AddProductToShoppingList(self::request('POST', ['product_amount' => 1]), new Response(), []),
			400,
			'Adding to the shopping list with no product id is refused'
		);

		$this->expectStatus(
			fn() => self::$stock->AddProductToShoppingList(self::request('POST', ['product_id' => self::$ids['staple'], 'list_id' => 987654]), new Response(), []),
			400,
			'Adding to a shopping list that does not exist is refused'
		);

		$this->expectStatus(
			fn() => self::$stock->AddProductToShoppingList(self::request('POST', ['product_id' => 987654]), new Response(), []),
			400,
			'Adding a product that does not exist is refused'
		);

		self::assertSame($before, self::$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn(), 'None of the refusals added a row');
	}

	/**
	 * The entry the removals work on is written here rather than inherited from the adder
	 * above. Several tests in this class clear the shopping list as their own first step,
	 * so an amount left behind by a predecessor is an amount any of them can take away,
	 * and the removal would then be asserted against a row that is not there.
	 */
	#[Depends('testCreatesFixtures')]
	public function testShoppingListRemoveProductDecrementsThenDeletes(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		self::insertRow('shopping_list', [
			'product_id' => self::$ids['staple'],
			'amount' => 5,
			'qu_id' => 2,
			'shopping_list_id' => self::$ids['second_list'],
		]);

		$this->expectStatus(
			fn() => self::$stock->RemoveProductFromShoppingList(self::request('POST', ['product_id' => self::$ids['staple'], 'product_amount' => 2, 'list_id' => self::$ids['second_list']]), new Response(), []),
			204,
			'Removing part of the amount is accepted'
		);

		$amount = self::$db->prepare('SELECT amount FROM shopping_list WHERE product_id = ?');
		$amount->execute([self::$ids['staple']]);
		self::assertSame(3.0, (float)$amount->fetchColumn(), 'The entry keeps the rest of the amount');

		$this->expectStatus(
			fn() => self::$stock->RemoveProductFromShoppingList(self::request('POST', ['product_id' => self::$ids['staple'], 'product_amount' => 3, 'list_id' => self::$ids['second_list']]), new Response(), []),
			204,
			'Removing the rest is accepted'
		);
		$amount->execute([self::$ids['staple']]);
		self::assertFalse($amount->fetchColumn(), 'An entry taken down to nothing is deleted rather than left at zero');

		$this->expectStatus(
			fn() => self::$stock->RemoveProductFromShoppingList(self::request('POST', ['product_amount' => 1]), new Response(), []),
			400,
			'Removing with no product id is refused'
		);

		$this->expectStatus(
			fn() => self::$stock->RemoveProductFromShoppingList(self::request('POST', ['product_id' => self::$ids['staple'], 'list_id' => 987654]), new Response(), []),
			400,
			'Removing from a shopping list that does not exist is refused'
		);
	}

	#[Depends('testShoppingListRemoveProductDecrementsThenDeletes')]
	public function testShoppingListClearRemovesDoneItemsOnlyWhenAsked(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		$list = self::$ids['second_list'];
		self::insertRow('shopping_list', ['product_id' => self::$ids['staple'], 'amount' => 1, 'shopping_list_id' => $list, 'done' => 1]);
		self::insertRow('shopping_list', ['product_id' => self::$ids['spare'], 'amount' => 1, 'shopping_list_id' => $list, 'done' => 0]);

		$this->expectStatus(
			fn() => self::$stock->ClearShoppingList(self::request('POST', ['list_id' => $list, 'done_only' => true]), new Response(), []),
			204,
			'Clearing only the done items is accepted'
		);

		$remaining = self::$db->query('SELECT product_id FROM shopping_list')->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame([self::$ids['spare']], array_map('intval', $remaining), 'The done item went and the undone one stayed');

		$this->expectStatus(
			fn() => self::$stock->ClearShoppingList(self::request('POST', ['list_id' => $list]), new Response(), []),
			204,
			'Clearing the whole list is accepted'
		);
		self::assertSame(0, (int)self::$db->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn(), 'The list is empty');

		$this->expectStatus(
			fn() => self::$stock->ClearShoppingList(self::request('POST', ['list_id' => 987654]), new Response(), []),
			400,
			'Clearing a shopping list that does not exist is refused'
		);
	}

	/**
	 * The three bulk adders each select a different set and each skip products already on
	 * a list, so they are asserted in sequence against one list: expired first, then
	 * overdue (which must not re-add the expired product), then missing.
	 */
	#[Depends('testShoppingListClearRemovesDoneItemsOnlyWhenAsked')]
	public function testShoppingListBulkAddersSelectTheirOwnSets(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		$list = self::$ids['second_list'];

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => self::LONG_PAST_DATE, 'purchased_date' => self::LONG_PAST_DATE]), new Response(), ['productId' => self::$ids['expired']]),
			200,
			'A long expired entry is stocked'
		);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => self::LONG_PAST_DATE, 'purchased_date' => self::LONG_PAST_DATE]), new Response(), ['productId' => self::$ids['overdue']]),
			200,
			'A long overdue entry is stocked'
		);

		$onList = function () use ($list): array
		{
			$rows = self::$db->prepare('SELECT product_id FROM shopping_list WHERE shopping_list_id = ? ORDER BY product_id');
			$rows->execute([$list]);
			return array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
		};

		$this->expectStatus(
			fn() => self::$stock->AddExpiredProductsToShoppingList(self::request('POST', ['list_id' => $list]), new Response(), []),
			204,
			'Adding expired products to a named list is accepted'
		);
		self::assertSame([self::$ids['expired']], $onList(), 'Only the product whose due type is expiration was added');

		$this->expectStatus(
			fn() => self::$stock->AddOverdueProductsToShoppingList(self::request('POST', ['list_id' => $list]), new Response(), []),
			204,
			'Adding overdue products to a named list is accepted'
		);
		$afterOverdue = $onList();
		self::assertContains(self::$ids['overdue'], $afterOverdue, 'The overdue product was added');
		self::assertCount(2, $afterOverdue, 'and the expired one was not added a second time');

		$this->expectStatus(
			fn() => self::$stock->AddMissingProductsToShoppingList(self::request('POST', ['list_id' => $list]), new Response(), []),
			204,
			'Adding products below their minimum stock to a named list is accepted'
		);
		self::assertContains(self::$ids['understocked'], $onList(), 'The product below its minimum stock amount was added');

		$missing = self::$db->prepare('SELECT amount, qu_id FROM shopping_list WHERE product_id = ?');
		$missing->execute([self::$ids['understocked']]);
		$missing = $missing->fetch(PDO::FETCH_ASSOC);
		self::assertSame(4.0, (float)$missing['amount'], 'The amount added is the shortfall to the minimum stock amount');
		self::assertSame(2, (int)$missing['qu_id'], 'in the purchase quantity unit');

		// Negative control: a well stocked product is in none of the three sets.
		self::assertNotContains(self::$ids['staple'], $onList(), 'A stocked product that is not due is added by none of the three');

		foreach (['AddExpiredProductsToShoppingList', 'AddOverdueProductsToShoppingList', 'AddMissingProductsToShoppingList'] as $method)
		{
			$this->expectStatus(
				fn() => self::$stock->{$method}(self::request('POST', ['list_id' => 987654]), new Response(), []),
				400,
				"$method against a shopping list that does not exist is refused"
			);
		}
	}

	// ------------------------------------------------------------------------------
	// Spendings report: StockReportsService
	// ------------------------------------------------------------------------------

	/** name => total, for comparing a report's rows without depending on their order. */
	private static function totalsByName(array $rows): array
	{
		$totals = [];

		foreach ($rows as $row)
		{
			$totals[$row->name ?? '(none)'] = round((float)$row->total, 2);
		}

		return $totals;
	}

	/**
	 * Purchases in a pinned month nothing else in this class books into, so the sums below
	 * are exact rather than "at least".
	 */
	#[Depends('testCreatesFixtures')]
	public function testCreatesSpendingsFixture(): void
	{
		self::$ids['spend_a'] = self::insertProduct('Coverage Spend A', ['product_group_id' => self::$ids['group']]);
		self::$ids['spend_b'] = self::insertProduct('Coverage Spend B', ['product_group_id' => self::$ids['other_group']]);
		self::$ids['spend_c'] = self::insertProduct('Coverage Spend C');

		$purchase = function (int $productId, float $amount, float $price, string $date, int $shoppingLocationId, string $type): void
		{
			$this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', [
					'amount' => $amount,
					'price' => $price,
					'purchased_date' => $date,
					'best_before_date' => self::FAR_FUTURE_DATE,
					'shopping_location_id' => $shoppingLocationId,
					'transaction_type' => $type,
				]), new Response(), ['productId' => $productId]),
				200,
				"A $type of product $productId on $date is accepted"
			);
		};

		$purchase(self::$ids['spend_a'], 4, 2.50, '2026-05-10', self::$ids['grocer'], StockService::TRANSACTION_TYPE_PURCHASE);
		$purchase(self::$ids['spend_b'], 2, 1.25, '2026-05-12', self::$ids['market'], StockService::TRANSACTION_TYPE_PURCHASE);
		$purchase(self::$ids['spend_c'], 1, 7.00, '2026-05-20', self::$ids['grocer'], StockService::TRANSACTION_TYPE_PURCHASE);

		// Excluded from every spendings figure: nothing was spent on it.
		$purchase(self::$ids['spend_a'], 3, 9.00, '2026-05-15', self::$ids['grocer'], StockService::TRANSACTION_TYPE_SELF_PRODUCTION);

		self::assertSame(4.0, self::stockAmount(self::$ids['spend_b']) + self::stockAmount(self::$ids['spend_c']) - 1.0 + 2.0, 'The spendings fixture is stocked');
	}

	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsByProductSumsAmountTimesPriceAndExcludesSelfProduction(): void
	{
		$rows = StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCT);
		$totals = self::totalsByName($rows);

		self::assertSame(10.0, $totals['Coverage Spend A'] ?? null, 'Four at 2.50, and the self-production booking is not spending');
		self::assertSame(2.5, $totals['Coverage Spend B'] ?? null);
		self::assertSame(7.0, $totals['Coverage Spend C'] ?? null);
		self::assertCount(3, $totals, 'Only the three products bought in the pinned month appear');

		$byProduct = [];
		foreach ($rows as $row)
		{
			$byProduct[$row->name] = $row;
		}
		self::assertSame('Coverage Group', $byProduct['Coverage Spend A']->group_name, 'Grouping by product still names each product group');
		self::assertNull($byProduct['Coverage Spend C']->group_id, 'A product in no group carries no group id');
	}

	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsGroupedByProductGroupAndByStore(): void
	{
		$byGroup = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCTGROUP));
		self::assertSame(10.0, $byGroup['Coverage Group'] ?? null, 'The group total is its products summed');
		self::assertSame(2.5, $byGroup['Coverage Other Group'] ?? null);
		self::assertSame(7.0, $byGroup['(none)'] ?? null, 'Products in no group are summed under the null group');

		$byStore = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_STORE));
		self::assertSame(17.0, $byStore['Coverage Grocer'] ?? null, 'Both purchases from one store are one row');
		self::assertSame(2.5, $byStore['Coverage Market'] ?? null);
		self::assertCount(2, $byStore, 'and no third store appears');
	}

	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsProductGroupFilterNarrowsTheProductReport(): void
	{
		$onlyGroup = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCT, self::$ids['group']));
		self::assertSame(['Coverage Spend A' => 10.0], $onlyGroup, 'A group id filters the report down to that group');

		$ungrouped = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCT, 'ungrouped'));
		self::assertSame(['Coverage Spend C' => 7.0], $ungrouped, '"ungrouped" filters down to products in no group');

		$all = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCT, 'all'));
		self::assertCount(3, $all, '"all" applies no group filter');
	}

	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsFallsBackForAnUnknownGroupingAndAnIncompleteRange(): void
	{
		$fallback = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', 'by-the-phase-of-the-moon'));
		$byProduct = self::totalsByName(StockReportsService::GetInstance()->GetSpendings('2026-05-01', '2026-05-31', StockReportsService::GROUP_BY_PRODUCT));
		self::assertSame($byProduct, $fallback, 'An unknown grouping is treated as grouping by product');

		// Both bounds have to be ISO dates for the range to apply; otherwise the report
		// defaults to the current month, which the pinned May fixture is never in.
		foreach ([[null, null], ['2026-05-01', null], ['not a date', '2026-05-31'], ['2026-05-01', 'not a date']] as [$start, $end])
		{
			$defaulted = self::totalsByName(StockReportsService::GetInstance()->GetSpendings($start, $end, StockReportsService::GROUP_BY_PRODUCT));
			self::assertArrayNotHasKey('Coverage Spend A', $defaulted, 'An unusable range falls back to the current month, not to the whole history');
		}
	}

	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsOfAMonthWithNoPurchasesIsEmpty(): void
	{
		foreach ([StockReportsService::GROUP_BY_PRODUCT, StockReportsService::GROUP_BY_PRODUCTGROUP, StockReportsService::GROUP_BY_STORE] as $groupBy)
		{
			self::assertSame([], StockReportsService::GetInstance()->GetSpendings('2026-01-01', '2026-01-31', $groupBy), "A month nothing was bought in reports nothing, grouped by $groupBy");
		}
	}

	/** The default range is the current month, which the pinned fixtures are deliberately outside of. */
	#[Depends('testCreatesSpendingsFixture')]
	public function testSpendingsDefaultRangeIsTheCurrentMonth(): void
	{
		self::$ids['spend_now'] = self::insertProduct('Coverage Spend This Month');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'price' => 3.00, 'purchased_date' => date('Y-m-d'), 'best_before_date' => self::FAR_FUTURE_DATE]), new Response(), ['productId' => self::$ids['spend_now']]),
			200,
			'A purchase made today is accepted'
		);

		$totals = self::totalsByName(StockReportsService::GetInstance()->GetSpendings(null, null));
		self::assertSame(6.0, $totals['Coverage Spend This Month'] ?? null, 'Todays purchase is in the default range');
		self::assertArrayNotHasKey('Coverage Spend A', $totals, 'A purchase from a closed month is not');
	}

	// ------------------------------------------------------------------------------
	// Permission refusal: every booking route, with the acting user holding every stock
	// leaf except the one the route names.
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testEveryBookingRouteRefusesTheCallerWithoutItsOwnLeaf(): void
	{
		$everyLeaf = [
			'STOCK_VIEW', 'STOCK_PURCHASE', 'STOCK_CONSUME', 'STOCK_OPEN', 'STOCK_TRANSFER',
			'STOCK_INVENTORY', 'STOCK_EDIT', 'STOCK_PRICES_VIEW',
			'SHOPPINGLIST_ITEMS_ADD', 'SHOPPINGLIST_ITEMS_DELETE',
		];

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([self::$ids['staple']]);
		$entryId = (int)$entryId->fetchColumn();

		$routes = [
			'STOCK_PURCHASE' => fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['staple']]),
			'STOCK_CONSUME' => fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['staple']]),
			'STOCK_OPEN' => fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['staple']]),
			'STOCK_TRANSFER' => fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => self::$ids['staple']]),
			'STOCK_INVENTORY' => fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => 1]), new Response(), ['productId' => self::$ids['staple']]),
			'STOCK_EDIT' => fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 1, 'open' => false, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['entryId' => $entryId]),
			'SHOPPINGLIST_ITEMS_ADD' => fn() => self::$stock->AddProductToShoppingList(self::request('POST', ['product_id' => self::$ids['staple']]), new Response(), []),
			'SHOPPINGLIST_ITEMS_DELETE' => fn() => self::$stock->ClearShoppingList(self::request('POST', []), new Response(), []),
		];

		try
		{
			foreach ($routes as $leaf => $call)
			{
				self::grant(array_values(array_diff($everyLeaf, [$leaf])));
				$this->expectRefusalWithUntouchedLedger($call, 403, "The route requiring $leaf refuses a caller holding every other stock leaf");
			}

			// The same three STOCK_EDIT routes that share the leaf, so the refusal is not
			// only asserted for the one route that happens to be listed above.
			self::grant(array_values(array_diff($everyLeaf, ['STOCK_EDIT'])));
			$this->expectRefusalWithUntouchedLedger(fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 1, 'qu_id' => 2]), new Response(), ['entryId' => $entryId]), 403, 'Measuring refuses a caller without STOCK_EDIT');
			$this->expectRefusalWithUntouchedLedger(fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 500]), new Response(), ['locationId' => self::$ids['vessel']]), 403, 'Weighing refuses a caller without STOCK_EDIT');
			$this->expectRefusalWithUntouchedLedger(fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => 'whatever']), 403, 'Undoing a transaction refuses a caller without STOCK_EDIT');

			// A read whose whole body is prices: refusal, not an empty array. STOCK_PURCHASE
			// goes too - STOCK_PRICES_VIEW hangs under it in the permission hierarchy, so a
			// caller holding the parent resolves the child (docs/plans/19-rbac.md piece 2).
			self::grant(array_values(array_diff($everyLeaf, ['STOCK_PRICES_VIEW', 'STOCK_PURCHASE'])));
			$this->expectStatus(fn() => self::$stock->ProductPriceHistory(self::request(), new Response(), ['productId' => self::$ids['staple']]), 403, 'The price history refuses a caller without STOCK_PRICES_VIEW');

			// Negative control: with the leaf back, the same call is answered.
			self::grantStockAndList();
			$this->expectStatus(fn() => self::$stock->ProductPriceHistory(self::request(), new Response(), ['productId' => self::$ids['staple']]), 200, 'and answers the same caller once the leaf is granted');
		}
		finally
		{
			self::grantStockAndList();
		}
	}

	#[Depends('testCreatesFixtures')]
	public function testStockReadsRefuseACallerWithoutStockView(): void
	{
		try
		{
			self::grant(['SHOPPINGLIST_ITEMS_ADD']);

			foreach ([
				'the current stock list' => fn() => self::$stock->CurrentStock(self::request(), new Response(), []),
				'the volatile stock list' => fn() => self::$stock->CurrentVolatileStock(self::request(), new Response(), []),
				'product details' => fn() => self::$stock->ProductDetails(self::request(), new Response(), ['productId' => self::$ids['staple']]),
				'product stock entries' => fn() => self::$stock->ProductStockEntries(self::request(), new Response(), ['productId' => self::$ids['staple']]),
				'a single booking' => fn() => self::$stock->StockBooking(self::request(), new Response(), ['bookingId' => 1]),
			] as $what => $call)
			{
				$this->expectStatus($call, 403, "Reading $what refuses a caller without STOCK_VIEW");
			}
		}
		finally
		{
			self::grantStockAndList();
		}
	}

	// ------------------------------------------------------------------------------
	// Undoing bookings: what each transaction type puts back
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testUndoingATransferPutsTheStockBackWhereItCameFrom(): void
	{
		self::$ids['undo_transfer'] = self::insertProduct('Coverage Undo Transfer');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 5, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['undo_transfer']]),
			200,
			'The product is stocked in the pantry'
		);

		$rows = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 2, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => self::$ids['undo_transfer']]),
			200,
			'Two units are moved to the freezer'
		);
		$transactionId = $rows[0]['transaction_id'];

		$atLocation = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$atLocation->execute([self::$ids['undo_transfer'], self::$ids['freezer']]);
		self::assertSame(2.0, (float)$atLocation->fetchColumn(), 'The freezer holds the moved units before the undo');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transactionId]),
			204,
			'Undoing the transfer transaction is accepted'
		);

		$atLocation->execute([self::$ids['undo_transfer'], self::$ids['freezer']]);
		self::assertSame(0.0, (float)$atLocation->fetchColumn(), 'The destination row is gone again');
		$atLocation->execute([self::$ids['undo_transfer'], self::$ids['pantry']]);
		self::assertSame(5.0, (float)$atLocation->fetchColumn(), 'and every unit is back at the source');
		self::assertSame(5.0, self::stockAmount(self::$ids['undo_transfer']), 'with nothing created or destroyed on the way');

		$stillOpen = self::$db->prepare('SELECT COUNT(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$stillOpen->execute([$transactionId]);
		self::assertSame(0, (int)$stillOpen->fetchColumn(), 'Both halves of the transfer are marked undone, not just one');
	}

	#[Depends('testCreatesFixtures')]
	public function testUndoingAStockEditRestoresTheEntryAsItWas(): void
	{
		self::$ids['undo_edit'] = self::insertProduct('Coverage Undo Edit');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'price' => 1.0, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE, 'note' => 'before the edit']), new Response(), ['productId' => self::$ids['undo_edit']]),
			200,
			'The product is stocked'
		);

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([self::$ids['undo_edit']]);
		$entryId = (int)$entryId->fetchColumn();

		$rows = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', [
				'amount' => 1,
				'open' => true,
				'purchased_date' => self::CLOSED_MONTH_DATE,
				'best_before_date' => '2033-03-03',
				'price' => 9.0,
				'location_id' => self::$ids['freezer'],
				'note' => 'after the edit',
			]), new Response(), ['entryId' => $entryId]),
			200,
			'The entry is edited'
		);

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $rows[0]['transaction_id']]),
			204,
			'Undoing the edit transaction is accepted'
		);

		$entry = self::$db->prepare('SELECT amount, best_before_date, price, location_id, open, opened_date, note FROM stock WHERE id = ?');
		$entry->execute([$entryId]);
		$entry = $entry->fetch(PDO::FETCH_ASSOC);

		self::assertSame(3.0, (float)$entry['amount'], 'The amount is back');
		self::assertSame(self::FAR_FUTURE_DATE, $entry['best_before_date'], 'and the due date');
		self::assertSame(1.0, (float)$entry['price'], 'and the price');
		self::assertSame(self::$ids['pantry'], (int)$entry['location_id'], 'and the location');
		self::assertSame(0, (int)$entry['open'], 'and the entry is sealed again');
		self::assertNull($entry['opened_date'], 'with no opened date left behind');
		self::assertSame('before the edit', $entry['note'], 'and the note');
	}

	#[Depends('testOpenWithAGrossMeasurementStoresNetContentsAndWhenTheyWereMeasured')]
	public function testUndoingAMeasurementRestoresThePreviousReading(): void
	{
		$entryId = self::$db->prepare('SELECT id FROM stock WHERE stock_id = ?');
		$entryId->execute([self::$ids['jar_full_entry']]);
		$entryId = (int)$entryId->fetchColumn();

		$before = self::$db->prepare('SELECT opened_amount, opened_qu_id, opened_tare, opened_measured_at FROM stock WHERE id = ?');
		$before->execute([$entryId]);
		$before = $before->fetch(PDO::FETCH_ASSOC);

		$rows = $this->expectStatus(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 300, 'qu_id' => self::$ids['gram']]), new Response(), ['entryId' => $entryId]),
			200,
			'Re-measuring the open jar is accepted'
		);

		$types = array_column($rows, 'transaction_type');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_MEASURED_OLD, $types, 'A measurement records the reading it replaced');
		self::assertContains(StockService::TRANSACTION_TYPE_STOCK_MEASURED_NEW, $types, 'and the new one');

		$after = self::$db->prepare('SELECT opened_amount, opened_tare, amount FROM stock WHERE id = ?');
		$after->execute([$entryId]);
		$after = $after->fetch(PDO::FETCH_ASSOC);
		self::assertSame(300.0, (float)$after['opened_amount'], 'The entry now carries the new reading');
		self::assertNull($after['opened_tare'], 'A net reading records no tare');
		self::assertSame(1.0, (float)$after['amount'], 'and measuring never changes how many containers there are');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $rows[0]['transaction_id']]),
			204,
			'Undoing the measurement is accepted'
		);

		$restored = self::$db->prepare('SELECT opened_amount, opened_qu_id, opened_tare, opened_measured_at FROM stock WHERE id = ?');
		$restored->execute([$entryId]);
		self::assertSame($before, $restored->fetch(PDO::FETCH_ASSOC), 'Every measurement column is back to the reading taken at opening');
	}

	/**
	 * A booking can only be undone while no later booking on the same entry depends on it:
	 * reversing an older one would restore stock a later booking has already spoken for.
	 */
	#[Depends('testCreatesFixtures')]
	public function testUndoRefusesABookingThatACorrelatedLaterBookingDependsOn(): void
	{
		self::$ids['undo_chain'] = self::insertProduct('Coverage Undo Chain');
		$purchase = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 4, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['undo_chain']]),
			200,
			'The product is stocked'
		);

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([self::$ids['undo_chain']]);
		$entryId = (int)$entryId->fetchColumn();

		$this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 4, 'open' => false, 'purchased_date' => self::CLOSED_MONTH_DATE, 'note' => 'edited after the purchase']), new Response(), ['entryId' => $entryId]),
			200,
			'The entry is edited afterwards'
		);

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$purchase[0]['id']]),
			400,
			'Undoing the purchase underneath a later edit of the same entry is refused'
		);
	}

	/**
	 * DEFECT (services/StockService.php:2503): the "has subsequent dependent bookings"
	 * guard is written as
	 * `(correlation_id IS NOT NULL OR correlation_id != :3)`, and for an uncorrelated
	 * later booking - which is what an ordinary consume is - both sides are false or NULL,
	 * so the guard never fires. Undoing the purchase then deletes the whole stock entry by
	 * stock_id, taking the units the later consume did not touch with it, and leaves that
	 * consume standing as a live booking against stock that no longer exists.
	 *
	 * Correct behaviour is the same refusal the correlated case gets (see the test above).
	 * Pinned rather than skipped so the repair has a failing test to turn green; the
	 * damage is contained in a rolled back transaction.
	 */
	#[Depends('testUndoRefusesABookingThatACorrelatedLaterBookingDependsOn')]
	public function testUndoingAPurchaseUnderneathALaterConsumeDestroysTheRemainingStock(): void
	{
		self::$ids['undo_gap'] = self::insertProduct('Coverage Undo Gap');
		self::$db->beginTransaction();

		try
		{
			$purchase = $this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 4, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['undo_gap']]),
				200,
				'Four units are purchased'
			);
			$consume = $this->expectStatus(
				fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['undo_gap']]),
				200,
				'One unit is consumed'
			);
			self::assertSame(3.0, self::stockAmount(self::$ids['undo_gap']), 'Three units are left');

			$this->expectStatus(
				fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$purchase[0]['id']]),
				204,
				'Current behaviour: the purchase is undone although a later consume depends on it'
			);

			self::assertSame(0.0, self::stockAmount(self::$ids['undo_gap']), 'and the three untouched units disappear with the entry');

			$consumeStillLive = self::$db->prepare('SELECT undone FROM stock_log WHERE id = ?');
			$consumeStillLive->execute([(int)$consume[0]['id']]);
			self::assertSame(0, (int)$consumeStillLive->fetchColumn(), 'while the consume booking stays live against stock that is gone');
		}
		finally
		{
			self::$db->rollBack();
		}
	}

	#[Depends('testCreatesFixtures')]
	public function testUndoingAnUpwardInventoryCorrectionRemovesWhatItAdded(): void
	{
		self::$ids['undo_inventory'] = self::insertProduct('Coverage Undo Inventory');
		$rows = $this->expectStatus(
			fn() => self::$stock->InventoryProduct(self::request('POST', ['new_amount' => 3, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['undo_inventory']]),
			200,
			'Counting three of a product that had none is accepted'
		);
		self::assertSame(3.0, self::stockAmount(self::$ids['undo_inventory']), 'The correction added the counted units');

		$this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$rows[0]['id']]),
			204,
			'Undoing an upward inventory correction is accepted'
		);

		self::assertSame(0.0, self::stockAmount(self::$ids['undo_inventory']), 'and takes back exactly what it added');
		$undone = self::$db->prepare('SELECT undone FROM stock_log WHERE id = ?');
		$undone->execute([(int)$rows[0]['id']]);
		self::assertSame(1, (int)$undone->fetchColumn(), 'The booking is marked undone rather than deleted');
	}

	// ------------------------------------------------------------------------------
	// Product group tree reads
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testProductGroupsAreReturnedInTreeOrderWithTheirPaths(): void
	{
		$spices = self::insertRow('product_groups', ['name' => 'Coverage Spices']);
		$garlic = self::insertRow('product_groups', ['name' => 'Coverage Garlic', 'parent_product_group_id' => $spices]);
		$basil = self::insertRow('product_groups', ['name' => 'Coverage Basil', 'parent_product_group_id' => $spices]);
		$fresh = self::insertRow('product_groups', ['name' => 'Coverage Fresh', 'parent_product_group_id' => $garlic]);
		$retired = self::insertRow('product_groups', ['name' => 'Coverage Retired Group', 'active' => 0]);

		$groups = StockService::GetInstance()->GetProductGroupsWithPaths();
		$byId = [];
		$order = [];
		foreach ($groups as $group)
		{
			$byId[(int)$group->id] = $group;
			$order[] = (int)$group->id;
		}

		self::assertSame('Coverage Spices', $byId[$spices]->path, 'A root group is its own path');
		self::assertSame('Coverage Spices / Coverage Garlic', $byId[$garlic]->path, 'A child carries its parent in its path');
		self::assertSame('Coverage Spices / Coverage Garlic / Coverage Fresh', $byId[$fresh]->path, 'and so does a grandchild');
		self::assertSame(0, (int)$byId[$spices]->level, 'A root group is at level zero');
		self::assertSame(1, (int)$byId[$garlic]->level);
		self::assertSame(2, (int)$byId[$fresh]->level);

		$positions = array_flip($order);
		self::assertSame($positions[$garlic] + 1, $positions[$fresh], 'Pre-order: a group is immediately followed by its own subtree');
		self::assertLessThan($positions[$garlic], $positions[$basil], 'Siblings are ordered by name');
		self::assertLessThan($positions[$basil], $positions[$spices], 'and a parent comes before its children');

		self::assertArrayHasKey($retired, $byId, 'An inactive group is listed by default');
		$activeOnly = StockService::GetInstance()->GetProductGroupsWithPaths(true);
		self::assertNotContains($retired, array_map(fn($group) => (int)$group->id, $activeOnly), 'and left out when only active groups are asked for');
		self::assertContains($spices, array_map(fn($group) => (int)$group->id, $activeOnly), 'while the active ones stay');

		$ancestors = StockService::GetInstance()->GetProductGroupAncestorIds();
		self::assertSame([$fresh, $garlic, $spices], $ancestors[$fresh], 'A group lists itself first, then its ancestors outwards');
		self::assertSame([$spices], $ancestors[$spices], 'A root group lists only itself');
	}

	// ------------------------------------------------------------------------------
	// The printable shopping list (thermal printer output)
	// ------------------------------------------------------------------------------

	#[Depends('testCreatesFixtures')]
	public function testPrintableShoppingListRendersAmountsUnitsNotesAndFreeTextRows(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		$list = self::$ids['second_list'];

		// Stocked in kilograms, listed in kilograms: no conversion, plural unit name.
		self::insertRow('shopping_list', ['product_id' => self::$ids['jar'], 'amount' => 3, 'qu_id' => self::$ids['kilogram'], 'shopping_list_id' => $list, 'note' => 'the big one']);
		// A single unit keeps the singular name.
		self::insertRow('shopping_list', ['product_id' => self::$ids['staple'], 'amount' => 1, 'qu_id' => 2, 'shopping_list_id' => $list]);
		// A row with no product at all prints its note instead of a product name.
		self::insertRow('shopping_list', ['amount' => 12, 'shopping_list_id' => $list, 'note' => 'Ask about the cheese']);

		$lines = StockService::GetInstance()->GetShoppinglistInPrintableStrings($list);
		self::assertCount(3, $lines, 'One line per shopping list row');

		// Every amount is right-padded to the width of the longest one ("3 Coverage
		// Kilograms", 20 characters) and separated from the text by two spaces, so the
		// product column lines up on a fixed-width printer.
		$rendered = [];
		foreach ($lines as $line)
		{
			self::assertSame('  ', substr($line, 20, 2), 'The amount column is padded to a common width');
			$rendered[substr($line, 22)] = rtrim(substr($line, 0, 20));
		}

		self::assertSame('3 Coverage Kilograms', $rendered['Coverage Jar (the big one)'] ?? null, 'An amount above one uses the plural unit name, and the note follows the product in brackets');
		self::assertSame('1 Piece', $rendered['Coverage Staple'] ?? null, 'An amount of one uses the singular unit name, and a row with no note prints none');
		self::assertSame('12', $rendered['Ask about the cheese'] ?? null, 'A row with no product prints its note as the line text and no unit name');

		try
		{
			StockService::GetInstance()->GetShoppinglistInPrintableStrings(987654);
			self::fail('Printing a shopping list that does not exist must be refused');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('Shopping list does not exist', $exception->getMessage(), 'and refused by name');
		}
	}

	// ------------------------------------------------------------------------------
	// Opening: derived due dates, moving on open, sub product substitution
	// ------------------------------------------------------------------------------

	/**
	 * "Default due days after opened" shortens an entry's due date when it is opened, but
	 * may never push it past the due date the entry already had.
	 */
	#[Depends('testCreatesFixtures')]
	public function testOpeningShortensTheDueDateButNeverExtendsIt(): void
	{
		self::$ids['opened_soon'] = self::insertProduct('Coverage Opened Soon', ['default_best_before_days_after_open' => 5]);
		$soon = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');

		foreach ([self::FAR_FUTURE_DATE, $soon] as $dueDate)
		{
			$this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => $dueDate, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['opened_soon']]),
				200,
				"An entry due $dueDate is stocked"
			);
		}

		$entries = self::$db->prepare('SELECT stock_id, best_before_date FROM stock WHERE product_id = ?');
		$entries->execute([self::$ids['opened_soon']]);
		$stockIdByDueDate = [];
		foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$stockIdByDueDate[$row['best_before_date']] = $row['stock_id'];
		}

		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockIdByDueDate[self::FAR_FUTURE_DATE]]), new Response(), ['productId' => self::$ids['opened_soon']]),
			200,
			'Opening the long-dated entry is accepted'
		);

		$dueDateOf = self::$db->prepare('SELECT best_before_date FROM stock WHERE stock_id = ?');
		$dueDateOf->execute([$stockIdByDueDate[self::FAR_FUTURE_DATE]]);
		self::assertSame(
			(new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d'),
			$dueDateOf->fetchColumn(),
			'Opening moves the due date to five days from today'
		);

		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockIdByDueDate[$soon]]), new Response(), ['productId' => self::$ids['opened_soon']]),
			200,
			'Opening the short-dated entry is accepted'
		);

		$dueDateOf->execute([$stockIdByDueDate[$soon]]);
		self::assertSame($soon, $dueDateOf->fetchColumn(), 'and leaves a due date that is already sooner than that alone');
	}

	/**
	 * "Move on open" sends the opened entry to the product's default consume location -
	 * the fridge a jar lives in once it is open, rather than the pantry it was bought into.
	 */
	#[Depends('testCreatesFixtures')]
	public function testOpeningMovesTheEntryToTheDefaultConsumeLocation(): void
	{
		self::$ids['moves_on_open'] = self::insertProduct('Coverage Moves On Open', [
			'location_id' => self::$ids['freezer'],
			'move_on_open' => 1,
			'default_consume_location_id' => self::$ids['pantry'],
		]);

		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['freezer'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['moves_on_open']]),
			200,
			'It is stocked at its own location'
		);

		$details = $this->expectStatus(
			fn() => self::$stock->ProductDetails(self::request(), new Response(), ['productId' => self::$ids['moves_on_open']]),
			200,
			'Its details are readable'
		);
		self::assertSame(self::$ids['pantry'], (int)$details['default_consume_location']['id'], 'The details name the default consume location');

		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => self::$ids['moves_on_open']]),
			200,
			'Opening it is accepted'
		);

		$where = self::$db->prepare('SELECT location_id, open FROM stock WHERE product_id = ?');
		$where->execute([self::$ids['moves_on_open']]);
		$row = $where->fetch(PDO::FETCH_ASSOC);
		self::assertSame(self::$ids['pantry'], (int)$row['location_id'], 'The opened entry moved to the default consume location');
		self::assertSame(1, (int)$row['open'], 'and is open');
		self::assertSame(1.0, self::stockAmount(self::$ids['moves_on_open']), 'and the move created no extra stock');
	}

	/**
	 * With substitution allowed, a booking against a parent product may be satisfied from a
	 * sub product's stock, converting the amount through the sub product's own quantity
	 * unit conversion.
	 */
	#[Depends('testCreatesFixtures')]
	public function testConsumingAParentProductMaySubstituteASubProductThroughItsConversion(): void
	{
		[$parent, $child] = self::makeSubstitutablePair('Coverage Bundle', 4);

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'allow_subproduct_substitution' => true]), new Response(), ['productId' => $parent]),
			200,
			'Consuming one parent unit with substitution allowed is accepted'
		);

		self::assertSame(2.0, self::stockAmount($child), 'One parent unit took two sub product units through the conversion factor');
		self::assertSame(2.0, self::stockAmount($parent), 'and the parent own stock was not touched');

		$booking = self::$db->prepare('SELECT amount FROM stock_log WHERE product_id = ? ORDER BY id DESC LIMIT 1');
		$booking->execute([$child]);
		self::assertSame(-2.0, (float)$booking->fetchColumn(), 'The booking is against the sub product, in the sub product unit');
	}

	#[Depends('testCreatesFixtures')]
	public function testOpeningAParentProductMaySubstituteASubProductThroughItsConversion(): void
	{
		[$parent, $child] = self::makeSubstitutablePair('Coverage Open Bundle', 4);

		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'allow_subproduct_substitution' => true]), new Response(), ['productId' => $parent]),
			200,
			'Opening one parent unit with substitution allowed is accepted'
		);

		$opened = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND open = 1');
		$opened->execute([$child]);
		self::assertSame(2.0, (float)$opened->fetchColumn(), 'Two sub product units were opened for the one parent unit asked for');
		$openedParent = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE product_id = ? AND open = 1');
		$openedParent->execute([$parent]);
		self::assertSame(0, (int)$openedParent->fetchColumn(), 'and nothing of the parent own stock was opened');
		self::assertSame(4.0, self::stockAmount($child), 'Opening changes no amounts');
	}

	/**
	 * A parent product with its own stock and a sub product whose stock is due sooner (so
	 * it is reached first in default order) and is counted in a different quantity unit.
	 *
	 * @return array{0: int, 1: int} parent id, sub product id
	 */
	private static function makeSubstitutablePair(string $name, float $childAmount): array
	{
		$parent = self::insertProduct($name);
		$child = self::insertProduct($name . ' Part', ['parent_product_id' => $parent, 'qu_id_purchase' => 3, 'qu_id_stock' => 3, 'qu_id_consume' => 3, 'qu_id_price' => 3]);

		// One parent unit is two sub product units.
		self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (2, 3, 2, ?)')->execute([$child]);

		$stock = self::$stock;
		$response = $stock->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $parent]);
		self::assertSame(200, $response->getStatusCode(), 'The parent own stock is booked: ' . (string)$response->getBody());
		$response = $stock->AddProduct(self::request('POST', ['amount' => $childAmount, 'best_before_date' => '2033-01-01', 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $child]);
		self::assertSame(200, $response->getStatusCode(), 'The sub product stock is booked: ' . (string)$response->getBody());

		return [$parent, $child];
	}

	// ------------------------------------------------------------------------------
	// Derived due dates on purchase, and the auto-add user setting
	// ------------------------------------------------------------------------------

	/**
	 * A purchase straight into a freezer with no due date of its own takes the product's
	 * freezing default rather than its shelf default - the entry is frozen on arrival.
	 */
	#[Depends('testCreatesFixtures')]
	public function testPurchaseIntoAFreezerWithoutADueDateUsesTheFreezingDefault(): void
	{
		$neverDue = self::insertProduct('Coverage Frozen Forever', ['default_best_before_days' => 2, 'default_best_before_days_after_freezing' => -1]);
		$sevenDays = self::insertProduct('Coverage Frozen Seven', ['default_best_before_days' => 2, 'default_best_before_days_after_freezing' => 7]);

		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['freezer']]), new Response(), ['productId' => $neverDue]),
			200,
			'Buying straight into the freezer is accepted'
		);
		self::assertSame('2999-12-31', $rows[0]['best_before_date'], 'default_best_before_days_after_freezing = -1 wins over the two day shelf default');

		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['freezer']]), new Response(), ['productId' => $sevenDays]),
			200,
			'Buying the other product into the freezer is accepted'
		);
		self::assertSame(
			(new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
			$rows[0]['best_before_date'],
			'and a positive freezing default is seven days out, not the two day shelf default'
		);

		// Negative control: the same product bought onto a shelf takes the shelf default.
		$rows = $this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['pantry']]), new Response(), ['productId' => $sevenDays]),
			200,
			'Buying it into the pantry is accepted'
		);
		self::assertSame(
			(new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d'),
			$rows[0]['best_before_date'],
			'A non-freezer location uses the shelf default'
		);
	}

	/**
	 * With "automatically add products below their minimum stock amount to the shopping
	 * list" on, a booking that takes a product below its minimum puts it on the configured
	 * list as a side effect of the booking itself.
	 */
	#[Depends('testCreatesFixtures')]
	public function testConsumingBelowTheMinimumStockAmountAutoAddsToTheConfiguredList(): void
	{
		$product = self::insertProduct('Coverage Auto Add', ['min_stock_amount' => 3]);
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 4, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $product]),
			200,
			'Four units are stocked, one above the minimum'
		);

		self::$db->exec('DELETE FROM shopping_list');
		$onList = self::$db->prepare('SELECT amount, shopping_list_id FROM shopping_list WHERE product_id = ?');

		// Negative control: with the setting off, a booking below the minimum adds nothing.
		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 2]), new Response(), ['productId' => $product]),
			200,
			'Two units are consumed with the setting off'
		);
		$onList->execute([$product]);
		self::assertFalse($onList->fetch(PDO::FETCH_ASSOC), 'Nothing is added while the setting is off');

		$users = UsersService::GetInstance();

		try
		{
			$users->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount', 1);
			$users->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount_list_id', self::$ids['second_list']);

			$this->expectStatus(
				fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
				200,
				'One more unit is consumed with the setting on'
			);

			$onList->execute([$product]);
			$row = $onList->fetch(PDO::FETCH_ASSOC);
			self::assertNotFalse($row, 'The product below its minimum was added to the shopping list');
			self::assertSame(2.0, (float)$row['amount'], 'with the shortfall to its minimum as the amount');
			self::assertSame(self::$ids['second_list'], (int)$row['shopping_list_id'], 'on the configured list');

			// The same side effect on the open path, which has its own copy of the check.
			$opened = self::insertProduct('Coverage Auto Add On Open', ['min_stock_amount' => 5]);
			$this->expectStatus(
				fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $opened]),
				200,
				'One unit of a product well below its minimum is stocked'
			);
			$this->expectStatus(
				fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $opened]),
				200,
				'Opening it is accepted'
			);
			$onList->execute([$opened]);
			self::assertNotFalse($onList->fetch(PDO::FETCH_ASSOC), 'Opening also adds the product below its minimum to the list');
		}
		finally
		{
			$users->SetUserSetting(9000, 'shopping_list_auto_add_below_min_stock_amount', 0);
			self::$db->exec('DELETE FROM shopping_list');
		}
	}

	/**
	 * The missing-products adder raises an entry that is already on a list up to the
	 * shortfall and moves it to the target list, but never lowers one.
	 */
	#[Depends('testCreatesFixtures')]
	public function testAddMissingRaisesAnExistingEntryButNeverLowersIt(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		$plenty = self::insertProduct('Coverage Understocked Two', ['min_stock_amount' => 2]);

		self::insertRow('shopping_list', ['product_id' => self::$ids['understocked'], 'amount' => 1, 'shopping_list_id' => 1]);
		self::insertRow('shopping_list', ['product_id' => $plenty, 'amount' => 9, 'shopping_list_id' => 1]);

		$this->expectStatus(
			fn() => self::$stock->AddMissingProductsToShoppingList(self::request('POST', ['list_id' => self::$ids['second_list']]), new Response(), []),
			204,
			'Adding the missing products to the second list is accepted'
		);

		$entry = self::$db->prepare('SELECT amount, shopping_list_id FROM shopping_list WHERE product_id = ?');
		$entry->execute([self::$ids['understocked']]);
		$row = $entry->fetch(PDO::FETCH_ASSOC);
		self::assertSame(4.0, (float)$row['amount'], 'The too-small entry was raised to the shortfall');
		self::assertSame(self::$ids['second_list'], (int)$row['shopping_list_id'], 'and moved to the named list');

		$entry->execute([$plenty]);
		$row = $entry->fetch(PDO::FETCH_ASSOC);
		self::assertSame(9.0, (float)$row['amount'], 'An entry already larger than the shortfall is left alone');
		self::assertSame(1, (int)$row['shopping_list_id'], 'and not moved');

		self::$db->exec('DELETE FROM shopping_list');
	}

	// ------------------------------------------------------------------------------
	// Location-scoped reads
	// ------------------------------------------------------------------------------

	/**
	 * DEFECT (controllers/Api/StockApiController.php:818-822): unlike every other stock
	 * read that can fail, LocationStockEntries() does not wrap its work in HandleApiCall(),
	 * so StockService::GetLocationStockEntries()'s "Location does not exist" leaves the
	 * controller as a bare \Exception and reaches the client as a 500 rather than the 400
	 * error response the other by-id reads answer with. Correct behaviour is the same 400.
	 * Pinned on the current behaviour; nothing is written either way.
	 */
	// The predecessor is the purchase that puts the spare product's six units in the pantry
	// - its default location - because that is the stock this read has to find. Nothing in
	// this class consumes that product, so the pantry is still not empty however the rest of
	// the class is ordered.
	#[Depends('testPurchasePerUnitLabelTypeWritesOneStockEntryPerUnit')]
	public function testLocationStockEntriesFailsUnhandledForALocationThatDoesNotExist(): void
	{
		$entries = $this->expectStatus(
			fn() => self::$stock->LocationStockEntries(self::request(), new Response(), ['locationId' => self::$ids['pantry']]),
			200,
			'The entries at a real location are readable'
		);
		self::assertNotEmpty($entries, 'The pantry holds stock');

		$before = self::ledger();

		try
		{
			self::$stock->LocationStockEntries(self::request(), new Response(), ['locationId' => 987654]);
			self::fail('A location id that does not exist must not be answered with a list');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Location does not exist', $exception->getMessage(), 'Current behaviour: the refusal escapes the controller unhandled');
		}

		self::assertSame($before, self::ledger(), 'and nothing was written');
	}

	/**
	 * The per-location content read has an "also list products that are out of stock at
	 * their default location" mode, which is what makes an empty shelf visible on the
	 * location overview instead of simply absent.
	 */
	#[Depends('testCreatesFixtures')]
	public function testLocationContentCanIncludeProductsThatAreOutOfStock(): void
	{
		$stocked = StockService::GetInstance()->GetCurrentStockLocationContent();
		self::assertNotContains(self::$ids['zero_stock'], array_map(fn($row) => (int)$row->product_id, $stocked), 'A product with no stock is absent by default');

		$withEmpty = StockService::GetInstance()->GetCurrentStockLocationContent(true);
		$rows = [];
		foreach ($withEmpty as $row)
		{
			$rows[(int)$row->product_id] = $row;
		}

		self::assertArrayHasKey(self::$ids['zero_stock'], $rows, 'and present when out-of-stock products are asked for');
		self::assertSame(0.0, (float)$rows[self::$ids['zero_stock']]->amount, 'with an amount of zero');
		self::assertSame(self::$ids['pantry'], (int)$rows[self::$ids['zero_stock']]->location_id, 'at its own default location');
	}

	// ------------------------------------------------------------------------------
	// Undoing one booking of a correlated pair, and what an undo refuses
	// ------------------------------------------------------------------------------

	/**
	 * A transfer's two halves are only meaningful undone together, so undoing either one
	 * by its own booking id undoes the pair - including rebuilding the source entry when
	 * the transfer emptied it.
	 */
	#[Depends('testCreatesFixtures')]
	public function testUndoingOneHalfOfATransferUndoesBothAndRebuildsTheSourceEntry(): void
	{
		$product = self::insertProduct('Coverage Whole Transfer');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 3, 'location_id' => self::$ids['pantry'], 'price' => 2.0, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE, 'note' => 'whole transfer']), new Response(), ['productId' => $product]),
			200,
			'Three units are stocked in the pantry'
		);

		$rows = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 3, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => $product]),
			200,
			'All three are moved to the freezer'
		);

		$atPantry = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE product_id = ? AND location_id = ?');
		$atPantry->execute([$product, self::$ids['pantry']]);
		self::assertSame(0, (int)$atPantry->fetchColumn(), 'The emptied source entry is gone before the undo');

		$transferTo = null;
		foreach ($rows as $row)
		{
			if ($row['transaction_type'] === StockService::TRANSACTION_TYPE_TRANSFER_TO)
			{
				$transferTo = (int)$row['id'];
			}
		}

		$this->expectStatus(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $transferTo]),
			204,
			'Undoing the arriving half alone is accepted'
		);

		$entry = self::$db->prepare('SELECT amount, best_before_date, price, note FROM stock WHERE product_id = ? AND location_id = ?');
		$entry->execute([$product, self::$ids['pantry']]);
		$entry = $entry->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($entry, 'The source entry was rebuilt');
		self::assertSame(3.0, (float)$entry['amount'], 'with the amount that left it');
		self::assertSame(self::FAR_FUTURE_DATE, $entry['best_before_date'], 'its due date');
		self::assertSame(2.0, (float)$entry['price'], 'its price');
		self::assertSame('whole transfer', $entry['note'], 'and its note');

		$atFreezer = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE product_id = ? AND location_id = ?');
		$atFreezer->execute([$product, self::$ids['freezer']]);
		self::assertSame(0, (int)$atFreezer->fetchColumn(), 'and the destination entry is gone');

		$live = self::$db->prepare('SELECT COUNT(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$live->execute([$rows[0]['transaction_id']]);
		self::assertSame(0, (int)$live->fetchColumn(), 'Undoing one half marked the leaving half undone too');
		self::assertSame(3.0, self::stockAmount($product), 'and nothing was created or destroyed');
	}

	/**
	 * When the destination already holds part of the same stock entry from an earlier
	 * transfer, undoing one of them takes back only what that one moved.
	 */
	#[Depends('testCreatesFixtures')]
	public function testUndoingOneOfTwoTransfersLeavesTheRestAtTheDestination(): void
	{
		$product = self::insertProduct('Coverage Twice Transferred');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 6, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $product]),
			200,
			'Six units are stocked in the pantry'
		);

		$transactions = [];
		foreach ([2, 1] as $amount)
		{
			$rows = $this->expectStatus(
				fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => $amount, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => $product]),
				200,
				"$amount units are moved to the freezer"
			);
			$transactions[] = $rows[0]['transaction_id'];
		}

		$atFreezer = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$atFreezer->execute([$product, self::$ids['freezer']]);
		self::assertSame(3.0, (float)$atFreezer->fetchColumn(), 'Three units arrived in total');

		$this->expectStatus(
			fn() => self::$stock->UndoTransaction(self::request('POST'), new Response(), ['transactionId' => $transactions[1]]),
			204,
			'Undoing the second transfer is accepted'
		);

		$atFreezer->execute([$product, self::$ids['freezer']]);
		self::assertSame(2.0, (float)$atFreezer->fetchColumn(), 'Only the unit that transfer moved came back');
		$atPantry = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$atPantry->execute([$product, self::$ids['pantry']]);
		self::assertSame(4.0, (float)$atPantry->fetchColumn(), 'and it is back at the source');
		self::assertSame(6.0, self::stockAmount($product), 'with the total untouched');
	}

	/**
	 * Each of the three undo branches that restore an entry refuses when that entry has
	 * since been consumed away: reversing the booking would otherwise write a row nothing
	 * points at, or silently do nothing.
	 */
	#[Depends('testCreatesFixtures')]
	public function testUndoRefusesWhenTheEntryItWouldRestoreIsGone(): void
	{
		// 1. A transfer whose arrived units have since been consumed.
		$transferred = self::insertProduct('Coverage Transferred Then Eaten');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $transferred]),
			200,
			'Two units are stocked'
		);
		$transfer = $this->expectStatus(
			fn() => self::$stock->TransferProduct(self::request('POST', ['amount' => 1, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]), new Response(), ['productId' => $transferred]),
			200,
			'One unit is moved to the freezer'
		);
		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['freezer']]), new Response(), ['productId' => $transferred]),
			200,
			'and then eaten out of the freezer'
		);
		$transferTo = array_values(array_filter($transfer, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_TRANSFER_TO))[0];
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$transferTo['id']]),
			400,
			'Undoing a transfer whose arrived units are gone is refused'
		);

		// 2. An edit whose entry has since been consumed.
		$edited = self::insertProduct('Coverage Edited Then Eaten');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 2, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $edited]),
			200,
			'Two units are stocked'
		);
		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$entryId->execute([$edited]);
		$entryId = (int)$entryId->fetchColumn();
		$edit = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 2, 'open' => false, 'purchased_date' => self::CLOSED_MONTH_DATE, 'note' => 'edited']), new Response(), ['entryId' => $entryId]),
			200,
			'The entry is edited'
		);
		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 2]), new Response(), ['productId' => $edited]),
			200,
			'and then eaten entirely'
		);
		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$editOld['id']]),
			400,
			'Undoing an edit of an entry that no longer exists is refused'
		);

		// 3. A measurement whose entry has since been consumed.
		$measured = self::insertProduct('Coverage Measured Then Eaten');
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => $measured]),
			200,
			'One unit is stocked'
		);
		$measuredEntryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ?');
		$measuredEntryId->execute([$measured]);
		$measuredEntryId = (int)$measuredEntryId->fetchColumn();
		$this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $measured]),
			200,
			'It is opened'
		);
		$measurement = $this->expectStatus(
			fn() => self::$stock->MeasureStockEntry(self::request('POST', ['amount' => 0.4, 'qu_id' => 2]), new Response(), ['entryId' => $measuredEntryId]),
			200,
			'and measured in its own stock unit'
		);
		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $measured]),
			200,
			'and then eaten'
		);
		$measuredOld = array_values(array_filter($measurement, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_MEASURED_OLD))[0];
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$measuredOld['id']]),
			400,
			'Undoing a measurement of an entry that no longer exists is refused'
		);
	}

	/**
	 * A booking against a sub product whose whole entry is taken converts the remaining
	 * amount back into the parent's unit before looking at the next entry.
	 */
	#[Depends('testCreatesFixtures')]
	public function testSubstitutionConvertsTheRemainderBackAfterTakingAWholeSubProductEntry(): void
	{
		[$parent, $child] = self::makeSubstitutablePair('Coverage Exact Bundle', 2);

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 1, 'allow_subproduct_substitution' => true]), new Response(), ['productId' => $parent]),
			200,
			'One parent unit is consumed, which is exactly the whole sub product entry'
		);

		self::assertSame(0.0, self::stockAmount($child), 'The sub product entry was taken whole');
		self::assertSame(2.0, self::stockAmount($parent), 'and the parent own stock was left alone, so no second helping was taken');
	}

	// ------------------------------------------------------------------------------
	// Scenarios that need a different value of a VICTUAL_* setting constant, and so a
	// process of their own (tests/Pgsql/request-subprocess-helper.php drives the real
	// middleware stack; Setting() reads a VICTUAL_-prefixed environment variable in
	// preference to config-dist.php, which is how the overrides below take effect).
	// ------------------------------------------------------------------------------

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, array $settingOverrides = [], ?array $body = null, array $headers = []): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => $headers + ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);

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
		], $settingOverrides);

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

		// A PHP diagnostic printed before the response (see the null-body test below) would
		// otherwise make this unparseable; the response object is the last thing written.
		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	#[Depends('testCreatesFixtures')]
	public function testCreatesTheSubprocessApiKey(): void
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9500, 'stockcoverage-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9500, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9500, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);

		$probe = self::send('GET', '/api/stock');
		self::assertSame(200, $probe['status'], 'The subprocess identity can read stock: ' . $probe['body']);
	}

	/**
	 * DEFECT (controllers/Api/BaseApiController.php:705-706): a request that types itself
	 * application/json but carries no body at all parses to null, and
	 * GetParsedAndFilteredRequestBody() then runs `foreach ($requestBody as ...)` over it.
	 * The client still gets the 400 the route's own null check raises, so the refusal
	 * itself is correct - but each one is preceded by a PHP warning, which is a diagnostic
	 * in the response or the log for an input a client can send at any time. Correct
	 * behaviour is to return the null (or an empty array) without iterating it.
	 *
	 * Asserted here on the status, which is the part of the contract that holds; the
	 * warning is recorded in the hand-back rather than pinned, because the suite's own
	 * failOnWarning would turn a fixed warning into a failing test for the wrong reason.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testEveryWriteRouteRefusesAnEmptyJsonBody(): void
	{
		$routes = [
			['POST', '/api/stock/products/' . self::$ids['staple'] . '/add'],
			['POST', '/api/stock/products/' . self::$ids['staple'] . '/consume'],
			['POST', '/api/stock/products/' . self::$ids['staple'] . '/inventory'],
			['POST', '/api/stock/products/' . self::$ids['staple'] . '/open'],
			['POST', '/api/stock/products/' . self::$ids['staple'] . '/transfer'],
			['POST', '/api/stock/locations/' . self::$ids['vessel'] . '/weigh'],
		];

		$before = self::ledger();

		foreach ($routes as [$method, $path])
		{
			$response = self::send($method, $path, [], null, ['Content-Type' => 'application/json']);
			self::assertSame(400, $response['status'], "$method $path with an empty JSON body is refused: " . $response['body']);
			self::assertStringContainsString('could not be parsed', $response['body'], 'and says the body could not be parsed');
		}

		// The entry edit and measure routes take an entry id rather than a product id.
		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? LIMIT 1');
		$entryId->execute([self::$ids['staple']]);
		$entryId = (int)$entryId->fetchColumn();

		foreach ([['PUT', '/api/stock/entry/' . $entryId], ['POST', '/api/stock/entry/' . $entryId . '/measure']] as [$method, $path])
		{
			$response = self::send($method, $path, [], null, ['Content-Type' => 'application/json']);
			self::assertSame(400, $response['status'], "$method $path with an empty JSON body is refused: " . $response['body']);
		}

		self::assertSame($before, self::ledger(), 'None of the eight refusals wrote anything');
	}

	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testExternalBarcodeLookupRefusesWhenNoUsablePluginIsConfigured(): void
	{
		// An empty environment variable is indistinguishable from an unset one, so the
		// "no plugin configured" case is expressed the other way config supports: an empty
		// setting override file in the data directory.
		$overrideDirectory = getenv('VICTUAL_DATAPATH') . '/settingoverrides';
		@mkdir($overrideDirectory, 0777, true);
		file_put_contents($overrideDirectory . '/STOCK_BARCODE_LOOKUP_PLUGIN.txt', '');

		try
		{
			$none = self::send('GET', '/api/stock/barcodes/external-lookup/12345');
			self::assertSame(400, $none['status'], 'With no plugin configured the lookup is refused: ' . $none['body']);
			self::assertStringContainsString('No barcode lookup plugin defined', $none['body']);
		}
		finally
		{
			@unlink($overrideDirectory . '/STOCK_BARCODE_LOOKUP_PLUGIN.txt');
		}

		$missing = self::send('GET', '/api/stock/barcodes/external-lookup/12345', ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'NoSuchLookupPlugin']);
		self::assertSame(400, $missing['status'], 'With a plugin that is not installed the lookup is refused: ' . $missing['body']);
		self::assertStringContainsString('was not found', $missing['body']);
	}

	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testExternalBarcodeLookupReturnsAndOptionallyCreatesTheFoundProduct(): void
	{
		$demo = ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'DemoBarcodeLookupPlugin'];
		$productCount = fn () => (int)self::$db->query('SELECT COUNT(*) FROM products')->fetchColumn();

		$nothing = self::send('GET', '/api/stock/barcodes/external-lookup/nothing', $demo);
		self::assertSame(200, $nothing['status'], 'A barcode the plugin knows nothing about answers 200: ' . $nothing['body']);
		self::assertSame('null', trim($nothing['body']), 'with a null result rather than an error');

		$failed = self::send('GET', '/api/stock/barcodes/external-lookup/error', $demo);
		self::assertSame(400, $failed['status'], 'A plugin error is answered as a refusal');
		self::assertStringContainsString('error message from the plugin', $failed['body'], 'carrying the plugin message');

		$before = $productCount();
		$found = self::send('GET', '/api/stock/barcodes/external-lookup/5901234123457', $demo);
		self::assertSame(200, $found['status'], 'A successful lookup answers 200: ' . $found['body']);
		$data = json_decode($found['body'], true);
		self::assertArrayHasKey('name', $data, 'and returns the product it found');
		self::assertArrayNotHasKey('id', $data, 'which has not been created, because adding was not asked for');
		self::assertSame($before, $productCount(), 'A lookup alone creates no product');

		$added = self::send('GET', '/api/stock/barcodes/external-lookup/5901234123457?add=true', $demo);
		self::assertSame(200, $added['status'], 'A lookup that also adds answers 200: ' . $added['body']);
		$data = json_decode($added['body'], true);
		self::assertArrayHasKey('id', $data, 'and returns the id of the product it created');
		self::assertSame($before + 1, $productCount(), 'and one product was created');

		$created = self::$db->prepare('SELECT name, location_id, qu_id_purchase, qu_id_stock FROM products WHERE id = ?');
		$created->execute([$data['id']]);
		$created = $created->fetch(PDO::FETCH_ASSOC);
		self::assertSame($data['name'], $created['name'], 'The created product carries the looked-up name');

		$barcode = self::$db->prepare('SELECT barcode FROM product_barcodes WHERE product_id = ?');
		$barcode->execute([$data['id']]);
		self::assertSame('5901234123457', $barcode->fetchColumn(), 'and the barcode that was scanned');
	}

	/**
	 * A plugin file in the data directory takes precedence over the bundled one, and a
	 * lookup that carries an inline image gets it stored as the new product's picture.
	 * Both are documented behaviour of this endpoint that no bundled plugin exercises.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testAUserPluginTakesPrecedenceAndItsInlineImageBecomesThePicture(): void
	{
		// A one pixel GIF, inline, so nothing is fetched over the network.
		$pluginFile = self::writeUserLookupPlugin('Coverage Looked Up ', 2, 2, "'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'");

		try
		{
			$response = self::send('GET', '/api/stock/barcodes/external-lookup/4000417025005?add=true', ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'CoverageBarcodeLookupPlugin']);
			self::assertSame(200, $response['status'], 'The data directory plugin answered the lookup: ' . $response['body']);

			$data = json_decode($response['body'], true);
			self::assertSame('Coverage Looked Up 4000417025005', $data['name'], 'The user plugin was used, not the bundled one');

			$created = self::$db->prepare('SELECT picture_file_name FROM products WHERE id = ?');
			$created->execute([$data['id']]);
			self::assertSame('4000417025005.gif', $created->fetchColumn(), 'The inline image was stored under the barcode, with the image type as the extension');

			$picture = getenv('VICTUAL_DATAPATH') . '/storage/productpictures/4000417025005.gif';
			self::assertFileExists($picture, 'and written to the product picture storage');
			self::assertSame("GIF8", substr((string)file_get_contents($picture), 0, 4), 'as the decoded image rather than its base64 text');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/**
	 * DEFECT (services/StockService.php:1047-1063): when a looked-up product's purchase and
	 * stock units differ, the products INSERT lands first and the database's own
	 * products_default_qu_conversions_INS trigger immediately creates the 1:1 conversion for
	 * that unit pair; the explicit insert of the plugin's __qu_factor_purchase_to_stock then
	 * duplicates it and is refused by qu_conversions_custom_constraint_INS. Nothing here runs
	 * in a transaction, so the product and its barcode stay behind while the factor the
	 * lookup found is lost, and the client is told only that "the database rejected this
	 * request".
	 *
	 * Correct behaviour is either to update the conversion the trigger created or to wrap the
	 * three writes in one transaction so the refusal leaves nothing. Pinned on the current
	 * behaviour, including the half-write, which is the part that matters.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testAddingALookedUpProductWhosePurchaseUnitDiffersSucceedsWithOneConversion(): void
	{
		$pluginFile = self::writeUserLookupPlugin('Coverage Differing Units ', 3, 2, 'null');

		try
		{
			$response = self::send('GET', '/api/stock/barcodes/external-lookup/4000417025012?add=true', ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'CoverageBarcodeLookupPlugin']);
			self::assertSame(200, $response['status'], 'the add succeeds instead of refusing after already writing the product');

			$product = self::$db->prepare('SELECT id FROM products WHERE name = ?');
			$product->execute(['Coverage Differing Units 4000417025012']);
			$productId = $product->fetchColumn();
			self::assertNotFalse($productId, 'the product row was written');

			$barcode = self::$db->prepare('SELECT COUNT(*) FROM product_barcodes WHERE product_id = ?');
			$barcode->execute([$productId]);
			self::assertSame(1, (int)$barcode->fetchColumn(), 'together with its barcode');

			$conversions = self::$db->prepare('SELECT factor FROM quantity_unit_conversions WHERE product_id = ? AND from_qu_id = 3 AND to_qu_id = 2');
			$conversions->execute([$productId]);
			$factors = $conversions->fetchAll(\PDO::FETCH_COLUMN);
			self::assertCount(1, $factors, 'exactly one purchase->stock conversion, not the trigger default plus a duplicate');
			self::assertSame(6.0, (float)$factors[0], 'carrying the factor the lookup plugin found, not the trigger default of 1');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/** Writes a lookup plugin into the data directory and returns its path. */
	private static function writeUserLookupPlugin(string $namePrefix, int $quIdPurchase, int $quIdStock, string $imageUrlExpression): string
	{
		$pluginDirectory = getenv('VICTUAL_DATAPATH') . '/plugins';
		$pluginFile = $pluginDirectory . '/CoverageBarcodeLookupPlugin.php';
		@mkdir($pluginDirectory, 0777, true);

		$locationId = (int)self::$db->query('SELECT id FROM locations WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();

		file_put_contents($pluginFile, <<<PLUGIN
<?php

class CoverageBarcodeLookupPlugin extends \\Victual\\Helpers\\BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'Coverage';

	protected function ExecuteLookup(\$barcode)
	{
		return [
			'name' => '{$namePrefix}' . \$barcode,
			'location_id' => {$locationId},
			'qu_id_purchase' => {$quIdPurchase},
			'qu_id_stock' => {$quIdStock},
			'__qu_factor_purchase_to_stock' => 6,
			'__barcode' => \$barcode,
			'__image_url' => {$imageUrlExpression},
		];
	}
}
PLUGIN);

		return $pluginFile;
	}

	/**
	 * With label printing enabled, a due date that a booking rewrites triggers a reprint
	 * for products configured for it - but only for an entry that already carries a live
	 * label. An entry nobody has printed a label for is not pulled into the label
	 * subsystem by a due date shifting under it.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testADueDateChangeDoesNotMintALabelForAnUnlabelledEntry(): void
	{
		$labels = ['VICTUAL_FEATURE_FLAG_LABELS' => 'true'];
		$product = self::insertProduct('Coverage Auto Reprint', [
			'auto_reprint_stock_label' => 1,
			'default_best_before_days_after_open' => 3,
			'default_best_before_days_after_freezing' => 30,
		]);

		$add = self::send('POST', '/api/stock/products/' . $product . '/add', $labels, ['amount' => 2, 'location_id' => self::$ids['pantry'], 'best_before_date' => self::FAR_FUTURE_DATE, 'purchased_date' => self::CLOSED_MONTH_DATE]);
		self::assertSame(200, $add['status'], 'The product is stocked: ' . $add['body']);

		$labelCount = fn () => (int)self::$db->query("SELECT COUNT(*) FROM labels WHERE kind = 'stock_entry'")->fetchColumn();
		$before = $labelCount();

		$open = self::send('POST', '/api/stock/products/' . $product . '/open', $labels, ['amount' => 1]);
		self::assertSame(200, $open['status'], 'Opening it, which shortens its due date, is accepted: ' . $open['body']);

		$transfer = self::send('POST', '/api/stock/products/' . $product . '/transfer', $labels, ['amount' => 1, 'location_id_from' => self::$ids['pantry'], 'location_id_to' => self::$ids['freezer']]);
		self::assertSame(200, $transfer['status'], 'Freezing it, which rewrites its due date, is accepted: ' . $transfer['body']);

		self::assertSame($before, $labelCount(), 'Neither due date change minted a label for an entry that had none');

		$frozen = self::$db->prepare('SELECT best_before_date FROM stock WHERE product_id = ? AND location_id = ?');
		$frozen->execute([$product, self::$ids['freezer']]);
		self::assertSame(
			(new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d'),
			$frozen->fetchColumn(),
			'and the freeze did rewrite the due date, so the reprint check really ran'
		);
	}

	#[Depends('testAUserPluginTakesPrecedenceAndItsInlineImageBecomesThePicture')]
	public function testAddingALookedUpProductTwiceIsRefusedByName(): void
	{
		$pluginFile = self::writeUserLookupPlugin('Coverage Looked Up ', 2, 2, 'null');

		try
		{
			// The same barcode the test above already added, so the name it derives is taken.
			$response = self::send('GET', '/api/stock/barcodes/external-lookup/4000417025005?add=true', ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'CoverageBarcodeLookupPlugin']);
			self::assertSame(400, $response['status'], 'Adding a product whose name already exists is refused');
			self::assertStringContainsString('already exists', $response['body'], 'and says so by name');

			$count = self::$db->prepare('SELECT COUNT(*) FROM products WHERE name = ?');
			$count->execute(['Coverage Looked Up 4000417025005']);
			self::assertSame(1, (int)$count->fetchColumn(), 'and no duplicate was created');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testAnUnusableInlineImageLeavesTheProductWithoutAPicture(): void
	{
		$pluginFile = self::writeUserLookupPlugin('Coverage No Picture ', 2, 2, "'data:image/gif;base64,'");

		try
		{
			$response = self::send('GET', '/api/stock/barcodes/external-lookup/4000417025029?add=true', ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => 'CoverageBarcodeLookupPlugin']);
			self::assertSame(200, $response['status'], 'An image that carries no data does not fail the add: ' . $response['body']);

			$data = json_decode($response['body'], true);
			$created = self::$db->prepare('SELECT picture_file_name FROM products WHERE id = ?');
			$created->execute([$data['id']]);
			self::assertNull($created->fetchColumn(), 'The product is created without a picture rather than with an empty one');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/**
	 * With "print the quantity names" off, the thermal shopping list prints the bare
	 * amount. Driven through the real print endpoint with the printer connector pointed at
	 * a file, so what is asserted is the bytes that would have reached the printer.
	 */
	#[Depends('testCreatesTheSubprocessApiKey')]
	public function testThermalShoppingListOmitsQuantityNamesWhenTheSettingIsOff(): void
	{
		self::$db->exec('DELETE FROM shopping_list');
		$list = self::$ids['second_list'];
		self::insertRow('shopping_list', ['product_id' => self::$ids['staple'], 'amount' => 4, 'qu_id' => 2, 'shopping_list_id' => $list]);

		$spool = getenv('VICTUAL_DATAPATH') . '/coverage-printer.bin';
		@unlink($spool);

		try
		{
			$response = self::send('GET', '/api/print/shoppinglist/thermal?list=' . $list . '&printHeader=false', [
				'VICTUAL_TPRINTER_PRINT_QUANTITY_NAME' => 'false',
				'VICTUAL_TPRINTER_IS_NETWORK_PRINTER' => 'false',
				'VICTUAL_TPRINTER_CONNECTOR' => $spool,
			]);
			self::assertSame(200, $response['status'], 'Printing the list is accepted: ' . $response['body']);

			$printed = (string)file_get_contents($spool);
			self::assertStringContainsString('Coverage Staple', $printed, 'The product name was printed');
			self::assertStringContainsString('4', $printed, 'with its amount');
			self::assertStringNotContainsString('Piece', $printed, 'and no quantity unit name, because the setting is off');
		}
		finally
		{
			@unlink($spool);
			self::$db->exec('DELETE FROM shopping_list');
		}
	}

	/**
	 * A vessel holding two separate stock entries of its product cannot be weighed: one
	 * physical container is one row to correct, and compaction cannot merge entries that
	 * differ (here, in their due date).
	 */
	#[Depends('testWeighingByLabelResolvesTheVesselAndRefusesAnUnknownCode')]
	public function testWeighingRefusesAVesselHoldingMoreThanOneEntry(): void
	{
		$this->expectStatus(
			fn() => self::$stock->AddProduct(self::request('POST', ['amount' => 1, 'location_id' => self::$ids['vessel'], 'best_before_date' => '2032-02-02', 'purchased_date' => self::CLOSED_MONTH_DATE]), new Response(), ['productId' => self::$ids['bulk']]),
			200,
			'A second, differently dated entry arrives in the vessel'
		);

		$entries = self::$db->prepare('SELECT COUNT(*) FROM stock WHERE product_id = ? AND location_id = ?');
		$entries->execute([self::$ids['bulk'], self::$ids['vessel']]);
		self::assertSame(2, (int)$entries->fetchColumn(), 'The vessel now holds two entries that cannot be compacted together');

		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->WeighLocation(self::request('POST', ['gross_amount' => 620]), new Response(), ['locationId' => self::$ids['vessel']]),
			400,
			'Weighing a vessel that holds more than one entry is refused'
		);
	}
}
