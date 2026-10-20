<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Issue #785: every stock write route below books through StockService, which commits its own
 * transaction, and then builds its answer with StockApiController::StockTransactions(). That
 * method checks STOCK_VIEW first. A caller holding the booking permission but not STOCK_VIEW
 * was therefore answered 403 "Permission missing: STOCK_VIEW" for a booking that had
 * committed, and could not tell that it had.
 *
 * Maintainer decision, 2026-10-12 (datagen24, on #785): refuse such a caller up front, before
 * anything is booked. Each route checks STOCK_VIEW next to its own permission.
 *
 * Every seeded role holds STOCK_VIEW, so the callers here hold direct grants only, each
 * exactly what its name says. Every request is its own process
 * (tests/Pgsql/request-subprocess-helper.php), for the reason RecipeRouteAuthzTest gives.
 * Fixture stock is booked in-process as PgsqlSchemaTestCase's default user (9000).
 */
class StockBookingNeedsStockViewTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stock;

	private const BEST_BEFORE = '2035-06-30';
	private const PURCHASED = '2026-04-01';

	/** The booking permission each route checks, by route name. */
	private const ROUTES = [
		'add' => 'STOCK_PURCHASE',
		'add by barcode' => 'STOCK_PURCHASE',
		'consume' => 'STOCK_CONSUME',
		'consume by barcode' => 'STOCK_CONSUME',
		'transfer' => 'STOCK_TRANSFER',
		'transfer by barcode' => 'STOCK_TRANSFER',
		'open' => 'STOCK_OPEN',
		'open by barcode' => 'STOCK_OPEN',
		'inventory' => 'STOCK_INVENTORY',
		'inventory by barcode' => 'STOCK_INVENTORY',
		'edit entry' => 'STOCK_EDIT',
		'measure entry' => 'STOCK_EDIT',
		'weigh location' => 'STOCK_EDIT',
		'weigh location by label' => 'STOCK_EDIT',
	];

	/** @var array<string,string> plaintext API keys: "<PERMISSION>" alone, or "<PERMISSION>+view" with STOCK_VIEW */
	private static array $keys = [];

	private static int $pantry;
	private static int $cellar;
	private static int $sequence = 0;

	/**
	 * Creates two locations and, for each booking permission in ROUTES, one user holding it
	 * alone and one holding it with STOCK_VIEW, plus a STOCK_VIEW-only user, each with an API
	 * key.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$stock = StockService::GetInstance();

		self::$pantry = self::insertRow('locations', ['name' => 'Booking View Pantry']);
		self::$cellar = self::insertRow('locations', ['name' => 'Booking View Cellar']);

		$insertUser = self::$db->prepare('INSERT INTO users(id, username, password) VALUES (?, ?, ?)');
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

		$userId = 9800;
		foreach (array_unique(array_values(self::ROUTES)) as $permission)
		{
			foreach (['' => [$permission], '+view' => [$permission, 'STOCK_VIEW']] as $suffix => $permissions)
			{
				$insertUser->execute([$userId, 'booking-view-' . strtolower($permission) . $suffix, 'fixture']);
				foreach ($permissions as $name)
				{
					$grant->execute([$userId, $name]);
				}
				self::$keys[$permission . $suffix] = self::issueKey($userId);
				$userId++;
			}
		}

		// STOCK_VIEW and no booking permission at all.
		$insertUser->execute([$userId, 'booking-view-view-only', 'fixture']);
		$grant->execute([$userId, 'STOCK_VIEW']);
		self::$keys['STOCK_VIEW'] = self::issueKey($userId);
	}

	/** Data provider: each route name in ROUTES paired with the booking permission it checks. */
	public static function routes(): array
	{
		$cases = [];
		foreach (self::ROUTES as $route => $permission)
		{
			$cases[$route] = [$route, $permission];
		}

		return $cases;
	}

	/** The five by-barcode wrappers, with the booking permission each one checks and a body it would accept. */
	public static function barcodeRoutes(): array
	{
		return [
			'add by barcode' => ['add', 'STOCK_PURCHASE', ['amount' => 1, 'best_before_date' => self::BEST_BEFORE]],
			'consume by barcode' => ['consume', 'STOCK_CONSUME', ['amount' => 1]],
			'transfer by barcode' => ['transfer', 'STOCK_TRANSFER', ['amount' => 1, 'location_id_from' => 1, 'location_id_to' => 2]],
			'open by barcode' => ['open', 'STOCK_OPEN', ['amount' => 1]],
			'inventory by barcode' => ['inventory', 'STOCK_INVENTORY', ['new_amount' => 5, 'best_before_date' => self::BEST_BEFORE]],
		];
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	/** Inserts a key row the way ApiKeyService::CreateApiKey() stores one, and returns its plaintext. */
	private static function issueKey(int $userId): string
	{
		$plaintext = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)");
		$stmt->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $plaintext;
	}

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path, string $apiKey, array $body): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey], 'body' => $body];

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

		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$response = json_decode($output, true);
		self::assertIsArray($response, 'The request helper answered: ' . $output . $errors);
		if (isset($response['body']) && is_string($response['body']))
		{
			$decoded = json_decode($response['body'], true);
			if (is_array($decoded))
			{
				$response['body'] = $decoded;
			}
		}

		return $response;
	}

	/** Inserts one row into $table from the column map and returns the new id. */
	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/**
	 * Inserts a product at the given location with the unit 2 for purchase, stock, consume and
	 * price, and returns its id.
	 */
	private static function insertProduct(string $name, int $locationId): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	/** Books stock through the real purchase path, as user 9000. */
	private static function stockUp(int $productId, float $amount, int $locationId): void
	{
		self::$stock->AddProduct($productId, $amount, self::BEST_BEFORE, StockService::TRANSACTION_TYPE_PURCHASE, self::PURCHASED, 1.0, $locationId);
	}

	/** Returns the lowest stock row id of the product. */
	private static function stockRowId(int $productId): int
	{
		return (int)self::$db->query("SELECT id FROM stock WHERE product_id = $productId ORDER BY id LIMIT 1")->fetchColumn();
	}

	/** Every stock and stock_log row of the product, so a booking of any kind changes it. */
	private static function ledgerDigest(int $productId): string
	{
		return (string)self::$db->query("SELECT md5(coalesce((SELECT string_agg(row_to_json(s)::text, ',' ORDER BY s.id) FROM stock s WHERE s.product_id = $productId), '')"
			. " || '|' || coalesce((SELECT string_agg(row_to_json(l)::text, ',' ORDER BY l.id) FROM stock_log l WHERE l.product_id = $productId), ''))")->fetchColumn();
	}

	/** Returns how many stock_log rows the product has. */
	private static function stockLogRows(int $productId): int
	{
		return (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $productId")->fetchColumn();
	}

	/**
	 * A fresh product with whatever the route needs to book, and the request that books it.
	 *
	 * @return array{0: string, 1: string, 2: array, 3: int} method, path, body, product id
	 */
	private static function fixture(string $route): array
	{
		$n = ++self::$sequence;
		$name = "Booking View $route $n";

		switch ($route)
		{
			case 'add':
			case 'add by barcode':
				$productId = self::insertProduct($name, self::$pantry);
				return ['POST', self::productPath($route, $productId, 'add'), ['amount' => 1, 'best_before_date' => self::BEST_BEFORE], $productId];

			case 'consume':
			case 'consume by barcode':
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 2, self::$pantry);
				return ['POST', self::productPath($route, $productId, 'consume'), ['amount' => 1], $productId];

			case 'transfer':
			case 'transfer by barcode':
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 2, self::$pantry);
				return ['POST', self::productPath($route, $productId, 'transfer'), ['amount' => 1, 'location_id_from' => self::$pantry, 'location_id_to' => self::$cellar], $productId];

			case 'open':
			case 'open by barcode':
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 2, self::$pantry);
				return ['POST', self::productPath($route, $productId, 'open'), ['amount' => 1], $productId];

			case 'inventory':
			case 'inventory by barcode':
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 2, self::$pantry);
				return ['POST', self::productPath($route, $productId, 'inventory'), ['new_amount' => 5, 'best_before_date' => self::BEST_BEFORE], $productId];

			case 'edit entry':
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 2, self::$pantry);
				return ['PUT', '/api/stock/entry/' . self::stockRowId($productId), ['amount' => 3, 'best_before_date' => self::BEST_BEFORE, 'purchased_date' => self::PURCHASED, 'open' => false], $productId];

			case 'measure entry':
				// MeasureStockEntry() needs one opened container: amount 1, open 1.
				$productId = self::insertProduct($name, self::$pantry);
				self::stockUp($productId, 1, self::$pantry);
				self::$stock->OpenProduct($productId, 1);
				return ['POST', '/api/stock/entry/' . self::stockRowId($productId) . '/measure', ['amount' => 0.5, 'qu_id' => 2], $productId];

			case 'weigh location':
			case 'weigh location by label':
				// A vessel of tare 0 in the product's own stock unit, holding 2: a reading of 1
				// books an inventory correction of -1 (ADR-0033 decision 5).
				$vessel = self::insertRow('locations', ['name' => "$name vessel", 'tare_weight' => 0.0, 'tare_qu_id' => 2]);
				$productId = self::insertProduct($name, $vessel);
				self::stockUp($productId, 2, $vessel);
				$path = '/api/stock/locations/' . $vessel . '/weigh';
				if ($route === 'weigh location by label')
				{
					$identity = new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw());
					$epoch = (int)self::$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
					self::$db->beginTransaction();
					$uid = $identity->IssueLocation($vessel, $epoch);
					self::$db->commit();
					$path = '/api/stock/locations/by-label/vctl:' . $uid . '/weigh';
				}
				return ['POST', $path, ['gross_amount' => 1], $productId];
		}

		self::fail("No fixture for route $route");
	}

	/** The plain product route, or its by-barcode wrapper through a barcode added for the product. */
	private static function productPath(string $route, int $productId, string $action): string
	{
		if (!str_ends_with($route, 'by barcode'))
		{
			return "/api/stock/products/$productId/$action";
		}

		$barcode = 'booking-view-' . $productId;
		self::insertRow('product_barcodes', ['product_id' => $productId, 'barcode' => $barcode]);

		return "/api/stock/products/by-barcode/$barcode/$action";
	}

	// ------------------------------------------------------------------------------
	// Tests
	// ------------------------------------------------------------------------------

	/**
	 * Proves that a caller holding the route's booking permission but not STOCK_VIEW gets 403
	 * "Permission missing: STOCK_VIEW" and no stock or stock_log row changes.
	 */
	#[DataProvider('routes')]
	public function testABookingPermissionWithoutStockViewIsRefusedBeforeAnythingIsBooked(string $route, string $permission): void
	{
		[$method, $path, $body, $productId] = self::fixture($route);
		$digest = self::ledgerDigest($productId);
		$logRows = self::stockLogRows($productId);

		$response = self::send($method, $path, self::$keys[$permission], $body);

		self::assertSame(403, $response['status'], "$route: $permission without STOCK_VIEW is refused");
		self::assertSame('Permission missing: STOCK_VIEW', $response['body']['error_message'] ?? null, $route);
		self::assertSame($logRows, self::stockLogRows($productId), "$route: a refused booking writes no stock_log row");
		self::assertSame($digest, self::ledgerDigest($productId), "$route: a refused booking changes no stock");
	}

	/**
	 * Proves that a caller holding the booking permission together with STOCK_VIEW gets 200 and
	 * the booking writes stock_log rows.
	 */
	#[DataProvider('routes')]
	public function testABookingPermissionWithStockViewStillBooks(string $route, string $permission): void
	{
		[$method, $path, $body, $productId] = self::fixture($route);
		$logRows = self::stockLogRows($productId);

		$response = self::send($method, $path, self::$keys[$permission . '+view'], $body);

		self::assertSame(200, $response['status'], "$route: $permission with STOCK_VIEW books: " . json_encode($response['body'] ?? null));
		self::assertIsArray($response['body'], $route);
		self::assertNotEmpty($response['body'], "$route: the answer lists the booking's stock_log rows");
		self::assertGreaterThan($logRows, self::stockLogRows($productId), "$route: the booking wrote stock_log rows");
	}

	/**
	 * Maintainer decision, 2026-10-12 (later the same day, on #785): a by-barcode wrapper checks
	 * the booking permission and STOCK_VIEW before it resolves the barcode. An unknown barcode
	 * used to answer 400 to any caller, so a caller with neither permission could learn
	 * which barcodes exist.
	 */
	#[DataProvider('barcodeRoutes')]
	public function testAByBarcodeRouteRefusesAMissingPermissionBeforeResolvingTheBarcode(string $action, string $permission, array $body): void
	{
		$path = '/api/stock/products/by-barcode/booking-view-no-such-barcode/' . $action;
		$digest = self::wholeLedgerDigest();

		$withoutView = self::send('POST', $path, self::$keys[$permission], $body);
		self::assertSame(403, $withoutView['status'], "$action by barcode: $permission without STOCK_VIEW is refused before the barcode is looked up");
		self::assertSame('Permission missing: STOCK_VIEW', $withoutView['body']['error_message'] ?? null, $action);

		$withoutBooking = self::send('POST', $path, self::$keys['STOCK_VIEW'], $body);
		self::assertSame(403, $withoutBooking['status'], "$action by barcode: STOCK_VIEW without $permission is refused before the barcode is looked up");
		self::assertSame('Permission missing: ' . $permission, $withoutBooking['body']['error_message'] ?? null, $action);

		self::assertSame($digest, self::wholeLedgerDigest(), "$action by barcode: a refused request changes no stock and writes no stock_log row");

		// Control: with both permissions the same request reaches the barcode lookup, which
		// refuses the unknown barcode with 400, so the 403s above came from the permission checks.
		$withBoth = self::send('POST', $path, self::$keys[$permission . '+view'], $body);
		self::assertSame(400, $withBoth['status'], "$action by barcode: an unknown barcode is still a 400 for a caller holding both permissions");
	}

	/**
	 * Proves that a STOCK_VIEW-only caller using a by-barcode route for a known barcode gets
	 * 403 naming the missing booking permission and nothing is booked.
	 */
	#[DataProvider('barcodeRoutes')]
	public function testAByBarcodeRouteRefusesStockViewWithoutTheBookingPermissionForAKnownBarcode(string $action, string $permission, array $body): void
	{
		[$method, $path, $fixtureBody, $productId] = self::fixture($action . ' by barcode');
		$digest = self::ledgerDigest($productId);

		$response = self::send($method, $path, self::$keys['STOCK_VIEW'], $fixtureBody);

		self::assertSame(403, $response['status'], "$action by barcode: STOCK_VIEW without $permission is refused");
		self::assertSame('Permission missing: ' . $permission, $response['body']['error_message'] ?? null, $action);
		self::assertSame($digest, self::ledgerDigest($productId), "$action by barcode: a refused booking changes nothing");
	}

	/** Every stock and stock_log row in the schema. */
	private static function wholeLedgerDigest(): string
	{
		return (string)self::$db->query("SELECT md5(coalesce((SELECT string_agg(row_to_json(s)::text, ',' ORDER BY s.id) FROM stock s), '')"
			. " || '|' || coalesce((SELECT string_agg(row_to_json(l)::text, ',' ORDER BY l.id) FROM stock_log l), ''))")->fetchColumn();
	}
}
