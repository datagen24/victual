<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Plan 22 issue #699 through the whole middleware stack: a household with a pharmacy cabinet and
 * three weekly organizers, driven only with the stock routes a person or a client uses
 * (`add`, `transfer`, `consume`, booking `undo`) and read back through the stock read routes
 * after every step.
 *
 * OrganizerWorkflowTest pins the same behaviour at the service layer and in the recipe path;
 * this class pins what is on the wire. Each request runs in a process of its own
 * (request-subprocess-helper.php) because the acting user is a constant per process.
 *
 * An organizer is an ordinary location, so nothing here uses an organizer-specific route: filling
 * one is `transfer`, which books no consumption, and consuming from one is `consume` with
 * `location_id` (or `stock_entry_id`). A `location_id` that names nothing usable is refused;
 * before issue #699 it was dropped, and the consumption fell back to any location.
 */
class OrganizerApiTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	/** @var array<string,string> plaintext keys by role */
	private static array $keys = [];
	/** @var array<string,int> */
	private static array $location = [];
	private static int $tablet;
	private static int $bottle;
	private static int $millilitre;
	private static int $ampoule;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// "operator" is an ordinary household member who books stock and edits master data.
		// "reader" holds STOCK_VIEW and nothing else: the ordinary stock reader of the issue.
		$grants = [
			'operator' => ['STOCK_VIEW', 'STOCK_PURCHASE', 'STOCK_CONSUME', 'STOCK_TRANSFER', 'STOCK_EDIT', 'MASTER_DATA_EDIT'],
			'reader' => ['STOCK_VIEW'],
		];
		$next = 9501;
		foreach ($grants as $role => $names)
		{
			$id = $next++;
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'organizer-api-$role', 'fixture')");
			foreach ($names as $permission)
			{
				self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?')->execute([$id, $permission]);
			}
			$plaintext = bin2hex(random_bytes(25));
			self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
				->execute([ApiKeyService::HashKey($plaintext), substr($plaintext, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
			self::$keys[$role] = $plaintext;
		}

		foreach (['cabinet' => 'API pharmacy cabinet', 'a' => 'API organizer A', 'b' => 'API organizer B', 'c' => 'API organizer C'] as $key => $name)
		{
			self::$location[$key] = (int)self::$db->query("INSERT INTO locations (name) VALUES ('$name') RETURNING id")->fetchColumn();
		}
		foreach (['tablet' => 'API tablet', 'bottle' => 'API bottle', 'millilitre' => 'API mL', 'ampoule' => 'API ampoule'] as $property => $name)
		{
			self::${$property} = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('$name') RETURNING id")->fetchColumn();
		}
	}

	/** @return array{status: int, body: mixed, headers: array} */
	private static function send(string $method, string $path, string $as, ?array $body = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$keys[$as]]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$response = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($response, "the request helper printed no JSON. stdout: $output stderr: $errors");
		$decoded = json_decode((string)$response['body'], true);
		$response['raw'] = (string)$response['body'];
		$response['body'] = is_array($decoded) ? $decoded : $response['body'];

		return $response;
	}

	private static function product(string $name, int $stockUnit, ?int $purchaseUnit = null, ?float $perPurchaseUnit = null): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$location['cabinet'], $purchaseUnit ?? $stockUnit, $stockUnit, $stockUnit, $stockUnit]);
		$id = (int)$statement->fetchColumn();

		if ($purchaseUnit !== null)
		{
			$update = self::$db->prepare('UPDATE quantity_unit_conversions SET factor = ? WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ?');
			$update->execute([$perPurchaseUnit, $id, $purchaseUnit, $stockUnit]);
			if ($update->rowCount() === 0)
			{
				self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)')->execute([$purchaseUnit, $stockUnit, $perPurchaseUnit, $id]);
			}
		}

		return $id;
	}

	/** Purchases through the add route, in the product's stock unit, into one location. */
	private static function purchase(int $product, float $amount, string $location, string $due = '2999-12-31'): void
	{
		$response = self::send('POST', "/api/stock/products/$product/add", 'operator',
			['amount' => $amount, 'best_before_date' => $due, 'transaction_type' => 'purchase', 'location_id' => self::$location[$location], 'price' => 1]);
		self::assertSame(200, $response['status'], $response['raw']);
	}

	private static function transfer(int $product, float $amount, string $from, string $to): array
	{
		return self::send('POST', "/api/stock/products/$product/transfer", 'operator',
			['amount' => $amount, 'location_id_from' => self::$location[$from], 'location_id_to' => self::$location[$to]]);
	}

	private static function consume(int $product, float $amount, array $extra = []): array
	{
		return self::send('POST', "/api/stock/products/$product/consume", 'operator', ['amount' => $amount, 'transaction_type' => 'consume'] + $extra);
	}

	/**
	 * Household total and the quantity at each of the four places, read through the stock read
	 * routes as the ordinary reader.
	 *
	 * @return array{cabinet: float, a: float, b: float, c: float, total: float}
	 */
	private static function state(int $product): array
	{
		$locations = self::send('GET', "/api/stock/products/$product/locations", 'reader');
		self::assertSame(200, $locations['status'], $locations['raw']);
		$byId = [];
		foreach ($locations['body'] as $row)
		{
			$byId[(int)$row['location_id']] = ($byId[(int)$row['location_id']] ?? 0.0) + (float)$row['amount'];
		}
		$details = self::send('GET', "/api/stock/products/$product", 'reader');
		self::assertSame(200, $details['status'], $details['raw']);

		$state = ['total' => (float)$details['body']['stock_amount']];
		foreach (self::$location as $key => $id)
		{
			$state[$key] = $byId[$id] ?? 0.0;
		}

		return $state;
	}

	private static function assertState(string $step, int $product, float $cabinet, float $a, float $b, float $c): void
	{
		$expected = ['cabinet' => $cabinet, 'a' => $a, 'b' => $b, 'c' => $c, 'total' => $cabinet + $a + $b + $c];
		$actual = self::state($product);
		ksort($expected);
		ksort($actual);
		self::assertEqualsWithDelta($expected, $actual, StockService::AMOUNT_TOLERANCE, "per-location quantities and household total after: $step");
		self::assertEqualsWithDelta($expected['total'], (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn(), StockService::AMOUNT_TOLERANCE,
			"the ledger agrees with the read routes after: $step");
		self::assertSame([], self::$db->query('SELECT * FROM stock_lineage_violations(NULL)')->fetchAll(PDO::FETCH_ASSOC), "ADR-0036 invariants I1 to I3 hold after: $step");
	}

	private static function consumeBookings(int $product): int
	{
		return (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $product AND transaction_type = 'consume' AND undone = 0")->fetchColumn();
	}

	/** @return list<int> the locations a response of stock_log rows booked against */
	private static function bookedLocations(array $response): array
	{
		return array_values(array_unique(array_map(static fn (array $row) => (int)$row['location_id'], $response['body'])));
	}

	public function testEachOrganizerIsAUniqueLocationAndKeepsItsLocationLabel(): void
	{
		$duplicate = self::send('POST', '/api/objects/locations', 'operator', ['name' => 'API organizer A']);
		self::assertGreaterThanOrEqual(400, $duplicate['status'], 'a second top-level location with the same name is refused: ' . $duplicate['raw']);
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM locations WHERE name = 'API organizer A'")->fetchColumn());

		$context = self::send('GET', '/api/labels/locations/' . self::$location['a'] . '/context', 'reader');
		self::assertSame(200, $context['status'], 'the location label context route is the existing one: ' . $context['raw']);
		self::assertSame('API organizer A', $context['body']['name']);
	}

	public function testThreeOrganizersAreFilledConsumedFromUndoneAndEmptiedBackWithTheTotalHeldAtEveryStep(): void
	{
		$product = self::product('API pills 5 mg', self::$tablet, self::$bottle, 90);

		$details = self::send('GET', "/api/stock/products/$product", 'reader')['body'];
		self::assertEqualsWithDelta(90.0, (float)$details['qu_conversion_factor_purchase_to_stock'], 1e-9, 'one bottle is 90 tablets on the wire');
		self::purchase($product, 1 * (float)$details['qu_conversion_factor_purchase_to_stock'], 'cabinet');
		self::assertState('the purchase of one bottle', $product, 90, 0, 0, 0);

		foreach (['a' => [83, 7, 0, 0], 'b' => [76, 7, 7, 0], 'c' => [69, 7, 7, 7]] as $organizer => [$cabinet, $a, $b, $c])
		{
			$fill = self::transfer($product, 7, 'cabinet', $organizer);
			self::assertSame(200, $fill['status'], $fill['raw']);
			self::assertSame(['transfer_from', 'transfer_to'], array_values(array_unique(array_column($fill['body'], 'transaction_type'))), 'filling an organizer books a transfer');
			self::assertState("filling organizer $organizer", $product, $cabinet, $a, $b, $c);
			self::assertSame(0, self::consumeBookings($product), 'moving stock into an organizer is never consumption');
		}

		$first = self::consume($product, 1, ['location_id' => self::$location['b']]);
		self::assertSame(200, $first['status'], $first['raw']);
		self::assertSame([self::$location['b']], self::bookedLocations($first), 'the booking names the selected organizer only');
		self::assertState('one tablet from organizer B', $product, 69, 7, 6, 7);

		$undo = self::send('POST', '/api/stock/bookings/' . (int)$first['body'][0]['id'] . '/undo', 'operator');
		self::assertSame(204, $undo['status'], $undo['raw']);
		self::assertState('undo of the consumption from B', $product, 69, 7, 7, 7);
		self::assertSame(0, self::consumeBookings($product), 'the undone consumption no longer counts');

		$fromA = self::consume($product, 2, ['location_id' => self::$location['a']]);
		$fromC = self::consume($product, 3, ['location_id' => self::$location['c']]);
		self::assertSame([200, 200], [$fromA['status'], $fromC['status']]);
		self::assertSame([[self::$location['a']], [self::$location['c']]], [self::bookedLocations($fromA), self::bookedLocations($fromC)]);
		self::assertState('two tablets from A and three from C', $product, 69, 5, 7, 4);

		foreach (['b', 'a', 'c'] as $organizer)
		{
			$remaining = ['a' => 5, 'b' => 7, 'c' => 4][$organizer];
			$return = self::transfer($product, $remaining, $organizer, 'cabinet');
			self::assertSame(200, $return['status'], $return['raw']);
			self::assertSame(2, self::consumeBookings($product), "returning organizer $organizer books no consumption");
		}
		self::assertState('returning the unused contents of all three organizers', $product, 85, 0, 0, 0);

		$refused = self::send('POST', '/api/stock/bookings/' . (int)$fromA['body'][0]['id'] . '/undo', 'operator');
		self::assertSame(400, $refused['status'], 'ADR-0036: the returns moved units of the same purchase, so the earlier consumption cannot be undone: ' . $refused['raw']);
		self::assertStringContainsString('subsequent dependent bookings', $refused['raw']);
		self::assertState('the refused undo', $product, 85, 0, 0, 0);
	}

	public function testAShortfallAtTheSelectedOrganizerRefusesAndChargesNoOtherOrganizer(): void
	{
		$product = self::product('API pills shortfall', self::$tablet);
		self::purchase($product, 80, 'cabinet');
		foreach ([['a', 3], ['b', 7], ['c', 7]] as [$organizer, $amount])
		{
			self::assertSame(200, self::transfer($product, $amount, 'cabinet', $organizer)['status']);
		}
		self::assertState('the fixture', $product, 63, 3, 7, 7);
		$bookings = (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $product")->fetchColumn();

		$short = self::consume($product, 5, ['location_id' => self::$location['a']]);
		self::assertSame(400, $short['status'], $short['raw']);
		self::assertStringContainsString('at the desired location', $short['raw']);
		self::assertState('a consumption of 5 from an organizer holding 3', $product, 63, 3, 7, 7);
		self::assertSame($bookings, (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $product")->fetchColumn(), 'a refusal writes no booking');

		// A transfer splits a row and keeps its stock_id, so the three organizers hold rows of the
		// one stock entry. Naming that entry together with a location still takes from that
		// location only.
		$entry = (string)self::$db->query('SELECT stock_id FROM stock WHERE product_id = ' . $product . ' AND location_id = ' . self::$location['b'])->fetchColumn();
		$named = self::consume($product, 1, ['location_id' => self::$location['a'], 'stock_entry_id' => $entry]);
		self::assertSame(200, $named['status'], $named['raw']);
		self::assertSame([self::$location['a']], self::bookedLocations($named));
		self::assertState('the stock entry named with location A', $product, 63, 2, 7, 7);

		$exact = self::consume($product, 2, ['location_id' => self::$location['a']]);
		self::assertSame(200, $exact['status'], $exact['raw']);
		self::assertState('emptying organizer A exactly', $product, 63, 0, 7, 7);

		$empty = self::consume($product, 1, ['location_id' => self::$location['a']]);
		self::assertSame(400, $empty['status'], 'an empty organizer refuses and the others keep their contents: ' . $empty['raw']);
		self::assertState('a consumption from the empty organizer', $product, 63, 0, 7, 7);
	}

	public function testALocationIdThatNamesNothingUsableIsRefusedInsteadOfBecomingAnyLocation(): void
	{
		$product = self::product('API pills selection', self::$tablet);
		self::purchase($product, 30, 'cabinet', '2999-12-31');
		self::purchase($product, 10, 'b', '2026-12-31');
		self::assertState('the fixture', $product, 30, 0, 10, 0);

		foreach (['organizer-b' => 'organizer-b', 'zero' => 0, 'a boolean' => true, 'a list' => [self::$location['b']], 'a float' => 1.5] as $label => $value)
		{
			$response = self::consume($product, 1, ['location_id' => $value]);
			self::assertSame(400, $response['status'], "location_id as $label is refused: " . $response['raw']);
			self::assertState("a refused location_id as $label", $product, 30, 0, 10, 0);
		}
		self::assertSame(400, self::consume($product, 1, ['location_id' => 987654])['status'], 'a location that does not exist is refused');
		self::assertState('an unknown location', $product, 30, 0, 10, 0);

		// null and "" are how the consume form says "any location", and keep meaning that.
		foreach ([null, ''] as $absent)
		{
			$response = self::consume($product, 1, ['location_id' => $absent]);
			self::assertSame(200, $response['status'], $response['raw']);
			self::assertSame([self::$location['b']], self::bookedLocations($response), 'with no default, the earliest due date wins wherever it is stored');
		}
		self::assertState('two consumptions with no location', $product, 30, 0, 8, 0);

		// The configured default is #700's territory; the route follows what the product already says.
		self::$db->exec('UPDATE products SET default_consume_location_id = ' . self::$location['cabinet'] . " WHERE id = $product");
		$byDefault = self::consume($product, 1);
		self::assertSame([self::$location['cabinet']], self::bookedLocations($byDefault), 'the product default comes first when no location is sent');
		$explicit = self::consume($product, 1, ['location_id' => self::$location['b']]);
		self::assertSame([self::$location['b']], self::bookedLocations($explicit), 'an explicit location overrides the default');
		self::assertState('a default and an explicit location', $product, 29, 0, 7, 0);
	}

	public function testLiquidSingleUseAndStrengthsStayDistinctThroughTheRoutes(): void
	{
		$syrup = self::product('API syrup', self::$millilitre, self::$bottle, 250);
		self::purchase($syrup, 2 * (float)self::send('GET', "/api/stock/products/$syrup", 'reader')['body']['qu_conversion_factor_purchase_to_stock'], 'cabinet');
		self::assertSame(200, self::transfer($syrup, 125, 'cabinet', 'a')['status']);
		self::assertSame(200, self::consume($syrup, 125, ['location_id' => self::$location['a']])['status'], 'half a bottle is 125 mL');
		self::assertState('a liquid consumed from its organizer', $syrup, 375, 0, 0, 0);

		$ampoules = self::product('API ampoules', self::$ampoule);
		self::purchase($ampoules, 10, 'cabinet');
		self::assertSame(200, self::transfer($ampoules, 3, 'cabinet', 'a')['status']);
		self::assertSame(200, self::consume($ampoules, 1, ['location_id' => self::$location['a']])['status']);
		self::assertState('a single-use unit consumed from its organizer', $ampoules, 7, 2, 0, 0);

		$weak = self::product('API tablet 5 mg', self::$tablet);
		$strong = self::product('API tablet 10 mg', self::$tablet);
		self::purchase($weak, 10, 'a');
		self::purchase($strong, 10, 'a');
		self::assertSame(200, self::consume($weak, 2, ['location_id' => self::$location['a']])['status']);
		self::assertState('5 mg consumed', $weak, 0, 8, 0, 0);
		self::assertState('the 10 mg product is untouched', $strong, 0, 10, 0, 0);
	}

	public function testAnOrdinaryStockReaderSeesNoPrivateRecipeData(): void
	{
		$product = self::product('API pills private', self::$tablet);
		self::purchase($product, 20, 'cabinet');
		self::assertSame(200, self::transfer($product, 10, 'cabinet', 'a')['status']);

		$name = 'Private recipe name ' . bin2hex(random_bytes(4));
		$note = 'Private recipe note ' . bin2hex(random_bytes(4));
		$recipe = self::send('POST', '/api/consumption/recipes', 'operator', ['name' => $name, 'note' => $note, 'lines' => [['product_id' => $product, 'amount' => 2, 'qu_id' => self::$tablet]]]);
		self::assertSame(200, $recipe['status'], $recipe['raw']);
		$consumed = self::send('POST', '/api/consumption/recipes/' . (int)$recipe['body']['created_object_id'] . '/consume', 'operator', ['request_id' => 'organizer-private', 'location_id' => self::$location['a']]);
		self::assertSame(201, $consumed['status'], $consumed['raw']);
		self::assertState('a recipe consumption from organizer A', $product, 10, 8, 0, 0);

		// ADR-0042: the same recipe carries refill records, so the reads below are checked against a refill note
		// and a reorder date that exist.
		$refillNote = 'Private refill note ' . bin2hex(random_bytes(4));
		$recipeId = (int)$recipe['body']['created_object_id'];
		$fill = self::send('POST', "/api/consumption/recipes/$recipeId/refill/fills?as_of=2026-03-01", 'operator', ['filled_on' => '2026-01-01', 'supplied_days' => 90, 'note' => $refillNote]);
		self::assertSame(201, $fill['status'], $fill['raw']);
		self::assertSame('2026-03-18', $fill['body']['estimate']['reorder_date'], 'the refill date is stored and served to the owner');
		self::assertSame(201, self::send('POST', "/api/consumption/recipes/$recipeId/refill/orders?as_of=2026-03-01", 'operator', ['ordered_on' => '2026-03-02'])['status']);
		self::assertState('refill records add no stock and move none', $product, 10, 8, 0, 0);

		$booking = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE product_id = $product")->fetchColumn();
		$entry = (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id = $product AND location_id = " . self::$location['a'])->fetchColumn();
		$reads = [
			'/api/stock', '/api/stock/volatile', "/api/stock/products/$product", "/api/stock/products/$product/entries", "/api/stock/products/$product/locations",
			"/api/stock/entry/$entry", '/api/stock/locations/' . self::$location['a'] . '/entries', "/api/stock/bookings/$booking",
			'/api/stock/transactions/' . $consumed['body']['transaction_id'],
			'/api/objects/stock_log', '/api/objects/stock', "/api/objects/products/$product", '/api/objects/locations', '/api/objects/quantity_unit_conversions',
			'/api/labels/locations/' . self::$location['a'] . '/context', "/api/labels/product/$product/context", "/api/labels/stock_entry/$entry/context",
		];
		foreach ($reads as $path)
		{
			$response = self::send('GET', $path, 'reader');
			self::assertSame(200, $response['status'], "$path: " . $response['raw']);
			self::assertStringNotContainsString($name, $response['raw'], "$path discloses the recipe name");
			self::assertStringNotContainsString($note, $response['raw'], "$path discloses the recipe note");
			self::assertStringNotContainsString($refillNote, $response['raw'], "$path discloses the refill note");
			self::assertDoesNotMatchRegularExpression('/consumption_(recipe|event|refill)|"recipe_id":\s*[1-9]|"reorder_(date|on)"/i', $response['raw'], "$path discloses a consumption recipe, event or refill reference");
		}

		// A scan of each label kind an organizer household prints, over the scan route.
		$identity = new LabelIdentityService(self::$db);
		$epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
		foreach (['location' => self::$location['a'], 'product' => $product, 'stock_entry' => $entry] as $kind => $target)
		{
			self::$db->beginTransaction();
			$uid = $identity->Issue($kind, $target, $epoch);
			self::$db->commit();
			$scan = self::send('GET', "/api/labels/resolve/$uid", 'reader');
			self::assertSame(200, $scan['status'], $scan['raw']);
			self::assertSame(['status' => 'resolved', 'kind' => $kind], ['status' => $scan['body']['status'], 'kind' => $scan['body']['kind']], "the $kind scan resolves for a stock reader");
			self::assertSame(['id', 'name', 'path'], array_keys($scan['body']['target']));
			self::assertStringNotContainsString($name, $scan['raw']);
			self::assertStringNotContainsString($note, $scan['raw']);
			self::assertStringNotContainsString($refillNote, $scan['raw']);
		}

		self::assertSame(400, self::send('GET', '/api/objects/consumption_events', 'reader')['status'], 'the generic reader refuses the private tables');
		self::assertSame(400, self::send('GET', '/api/objects/consumption_refill_fills', 'reader')['status'], 'and the refill tables');
		self::assertSame(404, self::send('GET', "/api/consumption/recipes/$recipeId/refill", 'reader')['status'], 'a stock reader with no share gets 404 from the refill route');
	}
}
