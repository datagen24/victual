<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\WireBooleans;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The five wire defects the first-party Swift client (ADR-0024) found, issues #229 to #233.
 * Tier 1 per ADR-0025: the request half runs through the whole middleware stack in
 * production mode, the document half reads victual.openapi.json.
 *
 *   #229 A Content-Type carrying a charset parameter is a JSON body, and is accepted on
 *        every write. The literal string comparison it replaces refused every request from
 *        any client whose HTTP stack appends one.
 *   #230 The eleven properties documented `type: boolean` are true/false on the wire, and
 *        the flags documented `type: integer` beside them - `undone` in the same stock_log
 *        row - are still integers. Checked as (schema, property) pairs on both sides, so
 *        that a documented boolean with nothing converting it and a conversion with no
 *        response behind it are both failures.
 *   #231 No field is typed `format: date-time` unless it really is RFC 3339, and the one
 *        that is stays. The renderings ADR-0027 decision 2 excepts - time_utc's UTC, that
 *        one RFC 3339 field, and the label surface's TIMESTAMPTZ values - are asserted to
 *        still be exceptions.
 *   #232 The GET /objects/{entity} union members are mutually exclusive. All fifty-seven
 *        listable entities are measured against all ten members, and the three that still
 *        match a member that is not theirs are pinned rather than claimed away.
 *   #233 GET /user's 200 is an array of UserDto, which is what it returns.
 *
 * Every request is its own process (tests/Pgsql/request-subprocess-helper.php): the
 * authentication middleware define()s the acting user, and PHP cannot redefine a constant.
 */
class WireContractTest extends PgsqlSchemaTestCase
{
	/** A retired label's code: the uid CHECK is ^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$. */
	private const RETIRED_LABEL_UID = 'ABCDEFGHJKMNP';

	private static PDO $db;
	private static string $key;

	/** A caller with MASTER_DATA_EDIT and not ADMIN, for the one gate that tells them apart. */
	private static string $masterDataKey;

	/** @var array<string,mixed>|null victual.openapi.json, decoded once */
	private static ?array $spec = null;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9500, 'wire-contract', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9500, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$key = bin2hex(random_bytes(25));
		$stmt = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, 9500, now() + interval '30 days', ?)");
		$stmt->execute([
			ApiKeyService::HashKey(self::$key),
			substr(self::$key, -4),
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		// The ExposedEntityEditRequiresAdmin gate is the difference between MASTER_DATA_EDIT
		// and ADMIN, so proving it is read needs a caller holding the first and not the second.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9501, 'wire-contract-master-data', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) '
			. "SELECT 9501, id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'");
		self::$masterDataKey = self::issueKey(9501);

		self::seedFixtures();
	}

	/** Issues a default-type API key for the given user and returns its plaintext. */
	private static function issueKey(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey($key),
			substr($key, -4),
			$userId,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);

		return $key;
	}

	/**
	 * One of each thing the eleven documented-boolean properties are reachable through: a
	 * product with a stock entry (stock_current.is_aggregated_amount), a ledger row
	 * (stock_log.spoiled, and undone beside it), a chore (chores.track_date_only/rollover
	 * and chores_current.is_rescheduled/is_reassigned) and a userfield
	 * (userfields.show_as_column_in_tables/input_required).
	 *
	 * Written as SQL rather than through the booking API because what is under test is the
	 * rendering of a row, not the booking that produced it - and a booking would need the
	 * whole purchase path to be exercised to reach one column.
	 */
	private static function seedFixtures(): void
	{
		self::$db->exec("INSERT INTO quantity_units (id, name, name_plural) VALUES (9500, 'WirePiece', 'WirePieces')");
		self::$db->exec("INSERT INTO locations (id, name) VALUES (9500, 'WireShelf')");
		self::$db->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) '
			. "VALUES (9500, 'WireProduct', 9500, 9500, 9500)");
		self::$db->exec("INSERT INTO stock (id, product_id, amount, stock_id, location_id) VALUES (9500, 9500, 3, 'wire-stock-1', 9500)");

		// Two ledger rows of one transaction, one spoiled and one not, so that a coercion
		// that ignored the value and answered a constant would still fail.
		self::$db->exec('INSERT INTO stock_log (id, product_id, amount, spoiled, stock_id, transaction_type, transaction_id, user_id) VALUES '
			. "(9500, 9500, -1, 1, 'wire-stock-1', 'consume', 'wire-transaction', 9500), "
			. "(9501, 9500, -1, 0, 'wire-stock-1', 'consume', 'wire-transaction', 9500)");

		self::$db->exec('INSERT INTO chores (id, name, period_type, period_days, start_date, track_date_only, rollover) '
			. "VALUES (9500, 'WireChore', 'daily', 1, '2026-01-01 08:00:00', 1, 0)");

		self::$db->exec('INSERT INTO userfields (id, entity, name, caption, type, show_as_column_in_tables, input_required) '
			. "VALUES (9500, 'products', 'wire_field', 'Wire field', 'text', 1, 0)");

		// A two-level product group, so product_groups_resolved has rows. The resolved view
		// is empty until something is nested, and an empty relation is measured off its
		// columns rather than off a response - which is the one thing
		// testTheServedSchemasAreAnsweredByTheGenericEntityRoute() must not do.
		self::$db->exec("INSERT INTO product_groups (id, name) VALUES (9500, 'WireSpices')");
		self::$db->exec('INSERT INTO product_groups (id, name, parent_product_group_id) '
			. "VALUES (9501, 'WireGarlic', 9500)");

		// A recipe with one position, so that recipes_resolved and recipes_pos_resolved
		// answer a row each - the two shapes WireBooleans converts that had no response
		// assertion behind them, which CONVERSION_COVERAGE below requires of every shape.
		self::$db->exec('INSERT INTO recipes (id, name, base_servings, desired_servings, type) '
			. "VALUES (9500, 'WireRecipe', 1, 1, 'normal')");
		self::$db->exec('INSERT INTO recipes_pos (id, recipe_id, product_id, amount, qu_id) '
			. 'VALUES (9500, 9500, 9500, 1, 9500)');

		// A shopping list row, so that both shopping_list and uihelper_shopping_list answer
		// one - the pair ADR-0027 records as indistinguishable by required properties.
		self::$db->exec('INSERT INTO shopping_lists (id, name) '
			. "VALUES (9500, 'WireShoppingList')");
		self::$db->exec('INSERT INTO shopping_list (id, product_id, shopping_list_id, amount, qu_id) '
			. 'VALUES (9500, 9500, 9500, 2, 9500)');

		// A retired label, whose retired_at is a TIMESTAMPTZ carrying fractional seconds and a
		// non-UTC offset - the rendering ADR-0027 decision 2 excepts. Written with both so that
		// a response reduced to the local rendering could not pass by accident.
		self::$db->exec('INSERT INTO labels (uid, kind, target_id, retired_at, retirement_snapshot) '
			. "VALUES ('" . self::RETIRED_LABEL_UID . "', 'location', NULL, "
			. "TIMESTAMPTZ '2026-03-04 05:06:07.891011-05', "
			. '\'{"id": 9501, "name": "WireRetiredShelf"}\'::jsonb)');
	}

	// ------------------------------------------------------------------ the request half

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, array $headers = [], ?array $body = null): array
	{
		return self::sendAs(self::$key, $method, $path, $headers, $body);
	}

	/** The same request, made by whoever holds $key. @return array{status: int, body: string} */
	private static function sendAs(string $key, string $method, string $path, array $headers = [], ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => $headers + ['VICTUAL-API-KEY' => $key], 'body' => $body],
			fn ($v) => $v !== null
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
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
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

	/** GETs $path, asserts 200 and returns the decoded body. */
	private static function get(string $path)
	{
		$response = self::send('GET', $path);
		self::assertSame(200, $response['status'], "$path answered {$response['status']}: {$response['body']}");

		return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
	}

	// --------------------------------------------------------------------- issue #229

	public function testAJsonBodyWithACharsetParameterIsAccepted(): void
	{
		// The rendering Apple's swift-openapi-runtime sends and cannot be told not to.
		$response = self::send(
			'POST',
			'/api/objects/tasks',
			['Content-Type' => 'application/json; charset=utf-8'],
			['name' => 'WireTaskWithCharset']
		);

		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame(
			1,
			(int)self::$db->query("SELECT count(*) FROM tasks WHERE name = 'WireTaskWithCharset'")->fetchColumn(),
			'the body of a charset-typed request reached the write'
		);
	}

	public function testAJsonBodyWithoutParametersIsStillAccepted(): void
	{
		$response = self::send('POST', '/api/objects/tasks', ['Content-Type' => 'application/json'], ['name' => 'WireTaskPlain']);

		self::assertSame(200, $response['status'], $response['body']);
	}

	public function testMediaTypeParsingIsCaseInsensitiveAndTolerantOfSpacing(): void
	{
		$response = self::send('POST', '/api/objects/tasks', ['Content-Type' => 'Application/JSON ;charset=UTF-8'], ['name' => 'WireTaskCased']);

		self::assertSame(200, $response['status'], $response['body']);
	}

	public function testANonJsonContentTypeIsStillRefused(): void
	{
		$response = self::send('POST', '/api/objects/tasks', ['Content-Type' => 'text/plain'], ['name' => 'WireTaskRefused']);

		self::assertSame(400, $response['status']);
		self::assertStringContainsString('Bad Content-Type', $response['body']);
		self::assertSame(
			0,
			(int)self::$db->query("SELECT count(*) FROM tasks WHERE name = 'WireTaskRefused'")->fetchColumn()
		);
	}

	public function testAContentTypeThatMerelyStartsWithTheMediaTypeIsRefused(): void
	{
		// "application/jsonx" shares a prefix with the media type and is not it. The parse
		// splits on ';' and compares the whole first component, so this is a different type.
		$response = self::send('POST', '/api/objects/tasks', ['Content-Type' => 'application/jsonx'], ['name' => 'WireTaskPrefix']);

		self::assertSame(400, $response['status']);
	}

	// --------------------------------------------------------------------- issue #230

	private static function assertBool($value, string $what): void
	{
		self::assertIsBool($value, "$what is " . var_export($value, true) . ', not a boolean');
	}

	public function testStockLogSpoiledIsABooleanOnTheBookingResponse(): void
	{
		// The route every one of the stock booking POSTs delegates to for its response body
		// (StockApiController::StockTransactions), so this covers all of them.
		$rows = self::get('/api/stock/transactions/wire-transaction');
		self::assertCount(2, $rows);

		$spoiled = [];
		foreach ($rows as $row)
		{
			self::assertBool($row['spoiled'], 'stock_log.spoiled');
			$spoiled[] = $row['spoiled'];

			// The counter-check: undone sits in the same row and the document types it
			// integer, so it must not have been swept up. See WireBooleans::COLUMNS.
			self::assertIsInt($row['undone'], 'stock_log.undone stayed an integer');
		}

		sort($spoiled);
		self::assertSame([false, true], $spoiled, 'the value was converted, not replaced by a constant');
	}

	public function testStockLogSpoiledIsABooleanOnASingleBooking(): void
	{
		$row = self::get('/api/stock/bookings/9500');
		self::assertBool($row['spoiled'], 'stock_log.spoiled');
		self::assertTrue($row['spoiled']);
	}

	public function testStockLogSpoiledIsABooleanOnTheGenericEntityRead(): void
	{
		$rows = self::get('/api/objects/stock_log');
		self::assertNotEmpty($rows);

		foreach ($rows as $row)
		{
			self::assertBool($row['spoiled'], 'stock_log.spoiled');
		}
	}

	public function testIsAggregatedAmountIsABoolean(): void
	{
		foreach (self::get('/api/stock') as $row)
		{
			self::assertBool($row['is_aggregated_amount'], 'stock_current.is_aggregated_amount');
		}

		$details = self::get('/api/stock/products/9500');
		self::assertBool($details['is_aggregated_amount'], 'product_details.is_aggregated_amount');
		self::assertBool($details['has_childs'], 'product_details.has_childs, which was already a boolean');
	}

	public function testChoreFlagsAreBooleans(): void
	{
		foreach (self::get('/api/objects/chores') as $row)
		{
			self::assertBool($row['track_date_only'], 'chores.track_date_only');
			self::assertBool($row['rollover'], 'chores.rollover');
		}

		$one = self::get('/api/objects/chores/9500');
		self::assertTrue($one['track_date_only'], 'the seeded 1 became true');
		self::assertFalse($one['rollover'], 'the seeded 0 became false');

		$details = self::get('/api/chores/9500');
		self::assertBool($details['chore']['track_date_only'], 'the nested chore of a ChoreDetailsResponse');
		self::assertBool($details['chore']['rollover'], 'the nested chore of a ChoreDetailsResponse');

		foreach (self::get('/api/chores') as $row)
		{
			self::assertBool($row['track_date_only'], 'chores_current.track_date_only');
			self::assertBool($row['is_rescheduled'], 'chores_current.is_rescheduled');
			self::assertBool($row['is_reassigned'], 'chores_current.is_reassigned');
		}
	}

	public function testUserfieldFlagsAreBooleans(): void
	{
		$rows = self::get('/api/objects/userfields');
		self::assertNotEmpty($rows);

		foreach ($rows as $row)
		{
			self::assertBool($row['show_as_column_in_tables'], 'userfields.show_as_column_in_tables');
			self::assertBool($row['input_required'], 'userfields.input_required');
		}
	}

	/**
	 * Every property the OpenAPI document types `boolean`, keyed by the schema that
	 * declares it, and what keeps the promise for that schema:
	 *
	 *   a shape name - the WireBooleans::COLUMNS key whose conversion serves it.
	 *   'php'        - computed as a bool in PHP; never was a 0/1 column.
	 *
	 * There is deliberately no third category for a property whose schema no path
	 * references. `StockJournal.spoiled` was one, and the answer was to delete the schema
	 * rather than to record the exemption: a documented boolean nothing can render is a
	 * promise to nobody, and a category for it is a place for the next one to hide. See
	 * testTheDeadJournalSchemasStayDeleted().
	 *
	 * Keyed by "Schema.property" rather than by property name, which is what makes
	 * ADR-0027's regression guarantee for the booleans true. A flat name list is satisfied by
	 * `spoiled` appearing anywhere in WireBooleans::COLUMNS, so documenting `spoiled` on a
	 * *second* schema - one whose route converts nothing - would pass it. A pair cannot: the
	 * new schema is an unknown key, and the assertion names it.
	 */
	private const DOCUMENTED_BOOLEANS = [
		'Chore.track_date_only' => 'chores',
		'Chore.rollover' => 'chores',
		'CurrentChoreResponse.track_date_only' => 'chores_current',
		'CurrentChoreResponse.is_rescheduled' => 'chores_current',
		'CurrentChoreResponse.is_reassigned' => 'chores_current',
		'CurrentStockResponse.is_aggregated_amount' => 'stock_current',
		'CurrentUserCapabilities.read_only' => 'php',
		'ProductDetailsResponse.has_childs' => 'php',
		'ProductDetailsResponse.is_aggregated_amount' => 'product_details',
		'RecipeFulfillmentResponse.need_fulfilled' => 'recipes_resolved',
		'RecipeFulfillmentResponse.need_fulfilled_with_shopping_list' => 'recipes_resolved',
		'RecipeFulfillmentResponse.prices_incomplete' => 'recipes_resolved',
		'StockLogEntry.spoiled' => 'stock_log',
		'Userfield.input_required' => 'userfields',
		'Userfield.show_as_column_in_tables' => 'userfields'
	];

	/**
	 * The other direction: every (shape, property) WireBooleans converts, and the route
	 * this class reads the converted value back from.
	 *
	 * `null` means no route reaches the shape at all, and the one that carries it is checked
	 * rather than asserted by hand - see testTheUnprovenShapeIsStillUnreachable(). It stays
	 * in WireBooleans::COLUMNS because `Userfield.show_as_column_in_tables` is still a
	 * documented boolean and the conversion is right if a route ever answers the view's rows
	 * whole; what is recorded here is that today nothing proves it.
	 *
	 * `uihelper_stock_journal.spoiled` used to be the other one. It was not kept: the
	 * `StockJournal` schema that documented the property was deleted, so the conversion had
	 * no promise left to serve. The difference between the two is whether some schema still
	 * types the property `boolean` - see testTheDeadJournalSchemasStayDeleted().
	 *
	 * The point of the pairing is that a converted property with no response behind it is
	 * indistinguishable, in a name list, from one converted on every read. "Something converts
	 * it" is only worth asserting if something also reads the converted value back, which is
	 * what the routes named here are for.
	 */
	private const CONVERSION_COVERAGE = [
		'stock_log.spoiled' => '/api/objects/stock_log',
		'stock_current.is_aggregated_amount' => '/api/stock',
		'product_details.is_aggregated_amount' => '/api/stock/products/9500',
		'chores.track_date_only' => '/api/objects/chores',
		'chores.rollover' => '/api/objects/chores',
		'chores_current.track_date_only' => '/api/chores',
		'chores_current.is_rescheduled' => '/api/chores',
		'chores_current.is_reassigned' => '/api/chores',
		'recipes_resolved.need_fulfilled' => '/api/recipes/fulfillment',
		'recipes_resolved.need_fulfilled_with_shopping_list' => '/api/recipes/fulfillment',
		'recipes_resolved.prices_incomplete' => '/api/recipes/fulfillment',
		'recipes_pos_resolved.need_fulfilled' => '/api/objects/recipes_pos_resolved',
		'recipes_pos_resolved.need_fulfilled_with_shopping_list' => '/api/objects/recipes_pos_resolved',
		'userfields.show_as_column_in_tables' => '/api/objects/userfields',
		'userfields.input_required' => '/api/objects/userfields',
		'userfield_values_resolved.show_as_column_in_tables' => null
	];

	/**
	 * Every property the document types `boolean` is declared here, against the schema that
	 * documents it, and whatever is named as keeping the promise really does.
	 */
	public function testEveryDocumentedBooleanIsAccountedFor(): void
	{
		$documented = [];
		foreach (self::spec()['components']['schemas'] as $schema => $body)
		{
			if (is_array($body))
			{
				self::collectBooleanProperties($body, (string)$schema, $documented);
			}
		}
		sort($documented);

		$declared = array_keys(self::DOCUMENTED_BOOLEANS);
		sort($declared);

		self::assertSame(
			$declared,
			$documented,
			'the document and DOCUMENTED_BOOLEANS disagree. Added to the document with nothing '
				. 'converting it: ' . (implode(', ', array_diff($documented, $declared)) ?: 'none')
				. '. Declared here but no longer in the document: '
				. (implode(', ', array_diff($declared, $documented)) ?: 'none')
		);

		foreach (self::DOCUMENTED_BOOLEANS as $pair => $shape)
		{
			if ($shape === 'php')
			{
				continue;
			}

			[, $property] = explode('.', $pair, 2);
			self::assertContains(
				$property,
				WireBooleans::ColumnsOf($shape),
				"$pair names shape '$shape', which does not convert '$property'"
			);
		}
	}

	/**
	 * And the converse: nothing is converted that this class does not read back off a
	 * response, except the two shapes no route reaches.
	 */
	public function testEveryConvertedPropertyIsProvenByAResponse(): void
	{
		$converted = [];
		foreach (WireBooleans::COLUMNS as $shape => $columns)
		{
			foreach ($columns as $column)
			{
				$converted[] = "$shape.$column";
			}
		}
		sort($converted);

		$covered = array_keys(self::CONVERSION_COVERAGE);
		sort($covered);

		self::assertSame(
			$covered,
			$converted,
			'converted with no entry here: ' . (implode(', ', array_diff($converted, $covered)) ?: 'none')
				. '. Listed here but no longer converted: '
				. (implode(', ', array_diff($covered, $converted)) ?: 'none')
		);
	}

	/**
	 * The response assertion the two tables above are only bookkeeping without: each route
	 * is fetched and the property it is named for is a boolean on a row that has it.
	 *
	 * "on a row that has it" is asserted rather than assumed - a route that answered no
	 * rows, or rows without the key, would otherwise pass this vacuously, which is the same
	 * class of hole the name list had.
	 */
	public function testTheConvertedPropertiesAreBooleansOnTheirRoutes(): void
	{
		$byRoute = [];
		foreach (self::CONVERSION_COVERAGE as $pair => $route)
		{
			if ($route !== null)
			{
				[, $property] = explode('.', $pair, 2);
				$byRoute[$route][] = $property;
			}
		}

		foreach ($byRoute as $route => $properties)
		{
			$body = self::get($route);
			$rows = array_is_list($body) ? $body : [$body];
			self::assertNotEmpty($rows, "$route answered no rows, so it proves nothing");

			foreach ($properties as $property)
			{
				$seen = 0;
				foreach ($rows as $row)
				{
					if (array_key_exists($property, $row))
					{
						$seen++;
						self::assertBool($row[$property], "$route: $property");
					}
				}

				self::assertGreaterThan(0, $seen, "no row of $route carries '$property'");
			}
		}
	}

	/**
	 * `StockJournal` and `StockJournalSummary` described `uihelper_stock_journal` and
	 * `uihelper_stock_journal_summary`, which `StockController::Journal()` and
	 * `::JournalSummary()` read for the two Blade pages only. No path referenced either
	 * schema and neither view is an `ExposedEntity`, so `StockJournal.spoiled` was a
	 * documented boolean no response could ever carry. They were removed rather than routed:
	 * exposing the journal is surface growth that plan 14 lists among the gaps it says are
	 * "argued explicitly rather than slipped in", which a documented-boolean cleanup is not
	 * the place to do.
	 *
	 * This pins the removal from both ends. Re-declaring either schema fails here, and so
	 * does the thing that would make re-declaring it legitimate - a route that answers these
	 * rows - because that route would have to name a schema and this test would be the one
	 * to revisit. The Blade pages are untouched and stay the only reader.
	 */
	public function testTheDeadJournalSchemasStayDeleted(): void
	{
		$spec = self::spec();

		foreach (['StockJournal', 'StockJournalSummary'] as $schema)
		{
			self::assertArrayNotHasKey(
				$schema,
				$spec['components']['schemas'],
				"$schema is declared again. If a route now answers these rows, the schema belongs "
					. 'to that route and DOCUMENTED_BOOLEANS/CONVERSION_COVERAGE have to grow with it.'
			);
		}

		foreach (['uihelper_stock_journal', 'uihelper_stock_journal_summary'] as $entity)
		{
			self::assertNotContains($entity, $spec['components']['schemas']['ExposedEntity']['enum']);
			self::assertSame(400, self::send('GET', "/api/objects/$entity")['status'], $entity);
		}

		// And nothing converts the view's spoiled any more, because no schema documents it.
		self::assertSame([], WireBooleans::ColumnsOf('uihelper_stock_journal'));
	}

	/**
	 * The shape CONVERSION_COVERAGE records as unproven is unproven because nothing can
	 * reach it, not because nobody wrote the test. Both halves are checked, so this fails
	 * the day a route makes it reachable and the coverage table has to grow.
	 */
	public function testTheUnprovenShapeIsStillUnreachable(): void
	{
		$spec = self::spec();
		$exposed = $spec['components']['schemas']['ExposedEntity']['enum'];

		// userfield_values_resolved: not exposed, and the one route that reads the view
		// (GET /userfields/{entity}/{objectId}) reduces its rows to name => value pairs, so
		// show_as_column_in_tables never reaches a response from it.
		self::assertNotContains('userfield_values_resolved', $exposed);
		self::assertSame(400, self::send('GET', '/api/objects/userfield_values_resolved')['status']);

		$values = self::get('/api/userfields/products/9500');
		self::assertArrayNotHasKey('show_as_column_in_tables', $values);
		self::assertArrayHasKey('wire_field', $values, 'the seeded userfield is what came back, as name => value');
	}

	/**
	 * Collects "$schema.property" for every property anywhere below $node - the body of one
	 * schema - that is typed `boolean`.
	 *
	 * Keyed off a "properties" map rather than off any object carrying a type, so that a
	 * boolean *parameter* or a boolean inside a request body is not mistaken for a response
	 * property this class is responsible for. The schema name is carried down unchanged: a
	 * boolean nested inside a property of a schema is still that schema's promise, and
	 * there are none today.
	 *
	 * @param array<string,mixed> $node
	 * @param string[] $into
	 */
	private static function collectBooleanProperties(array $node, string $schema, array &$into): void
	{
		foreach ($node['properties'] ?? [] as $name => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			$type = $property['type'] ?? null;

			if ($type === 'boolean' || (is_array($type) && in_array('boolean', $type, true)))
			{
				$into[] = "$schema.$name";
			}
		}

		foreach ($node as $child)
		{
			if (is_array($child))
			{
				self::collectBooleanProperties($child, $schema, $into);
			}
		}
	}

	// --------------------------------------------------------------------- issue #231

	public function testOnlyRfc3339FieldsAreTypedDateTime(): void
	{
		$sites = [];
		self::collectFormat(self::spec(), 'date-time', '', $sites);

		self::assertSame(
			['/paths//labels/attempts/{attemptId}/evidence/post/requestBody/content/application/json/schema/properties/observed_at'],
			$sites,
			'the only remaining format: date-time is the one whose column is a TIMESTAMPTZ and '
				. 'whose value is parsed with new DateTimeImmutable(), so RFC 3339 really is accepted there'
		);
	}

	public function testTheLocalRenderingIsDocumentedWhereverItIsSent(): void
	{
		$rendering = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

		// Three responses whose timestamps come from three different places: a base table
		// column, a view's computed value and PHP's own clock.
		$unit = self::get('/api/objects/quantity_units/9500');
		self::assertMatchesRegularExpression($rendering, $unit['row_created_timestamp']);

		$changed = self::get('/api/system/db-changed-time');
		self::assertMatchesRegularExpression($rendering, $changed['changed_time']);

		$time = self::get('/api/system/time');
		self::assertMatchesRegularExpression($rendering, $time['time_local']);
		self::assertMatchesRegularExpression($rendering, $time['time_utc']);

		// And the document says so, with the same expression, for each of them.
		$schemas = self::spec()['components']['schemas'];
		foreach ([['QuantityUnit', 'row_created_timestamp'], ['DbChangedTimeResponse', 'changed_time'],
			['TimeResponse', 'time_local'], ['TimeResponse', 'time_utc']] as [$schema, $property])
		{
			$documented = $schemas[$schema]['properties'][$property];
			self::assertArrayNotHasKey('format', $documented, "$schema.$property");
			self::assertSame('^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$', $documented['pattern'], "$schema.$property");
		}
	}

	public function testDateBackedColumnsAreTypedDate(): void
	{
		$schemas = self::spec()['components']['schemas'];

		foreach ([['Task', 'due_date'], ['CurrentTaskResponse', 'due_date'], ['ProductPriceHistory', 'date']] as [$schema, $property])
		{
			self::assertSame('date', $schemas[$schema]['properties'][$property]['format'] ?? null, "$schema.$property");
		}
	}

	/**
	 * `time_utc` is UTC, which is the first of the two exceptions ADR-0027 decision 2
	 * enumerates - and the one the document used to get wrong, by carrying `time_local`'s
	 * description verbatim ("the server's configured time zone") onto a value rendered
	 * through `new \DateTimeZone('UTC')`.
	 *
	 * Asserted against the response's own `timestamp`, so it holds whatever zone the server
	 * is configured for - including a host already running in UTC, where the two renderings
	 * coincide and a comparison between them would prove nothing.
	 */
	public function testTimeUtcIsUtcAndTheDocumentSaysSo(): void
	{
		$time = self::get('/api/system/time');

		self::assertSame(
			gmdate('Y-m-d H:i:s', $time['timestamp']),
			$time['time_utc'],
			'time_utc is not the UTC rendering of its own timestamp'
		);
		self::assertSame(
			(new \DateTimeImmutable('@' . $time['timestamp']))
				->setTimezone(new \DateTimeZone($time['timezone']))
				->format('Y-m-d H:i:s'),
			$time['time_local'],
			'time_local is not the configured zone rendering of its own timestamp'
		);

		$documented = self::spec()['components']['schemas']['TimeResponse']['properties']['time_utc']['description'];
		self::assertStringContainsString('UTC', $documented);
		self::assertStringNotContainsString(
			'Local date and time in the server\'s configured time zone',
			$documented,
			'time_utc still carries time_local\'s description, which says the wrong zone'
		);
	}

	/**
	 * The second exception: the label surface stores absolute clocks as `TIMESTAMPTZ` and
	 * renders what PostgreSQL renders, offset and all. `labels.retired_at` is the one such
	 * value in a documented response body, so it is the one asserted here.
	 *
	 * Decision 2 covers the legacy surface, whose columns are `TIMESTAMP`. Stating it over
	 * "every date and time this API renders" would have made this response a violation of
	 * the record the day the record was accepted.
	 */
	public function testTheLabelSurfaceRendersItsTimestamptzValuesWithAnOffset(): void
	{
		$body = self::get('/api/labels/resolve/' . self::RETIRED_LABEL_UID);

		self::assertSame('retired', $body['status']);
		self::assertSame('WireRetiredShelf', $body['snapshot']['name']);

		self::assertDoesNotMatchRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$body['retired_at'],
			'retired_at is the local rendering, so the exception decision 2 names no longer exists'
		);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?[+-]\d{2}(:\d{2})?$/',
			$body['retired_at'],
			'retired_at: ' . $body['retired_at']
		);

		// And the document describes it as that rather than promising the local pattern.
		$documented = self::spec()['paths']['/labels/resolve/{code}']['get']['responses']['200']
			['content']['application/json']['schema']['oneOf'][2]['properties']['retired_at'];
		self::assertArrayNotHasKey('pattern', $documented);
		self::assertArrayNotHasKey('format', $documented);
		self::assertStringContainsString('TIMESTAMPTZ', $documented['description']);
	}

	/** @param array<string,mixed> $node */
	private static function collectFormat(array $node, string $format, string $path, array &$into): void
	{
		if (($node['format'] ?? null) === $format)
		{
			$into[] = $path;
		}

		foreach ($node as $key => $child)
		{
			if (is_array($child))
			{
				self::collectFormat($child, $format, $path . '/' . $key, $into);
			}
		}
	}

	// --------------------------------------------------------------------- issue #232

	public function testTheGenericEntityUnionMembersAreMutuallyExclusive(): void
	{
		$spec = self::spec();
		$members = array_map(
			fn (array $ref) => substr($ref['$ref'], strlen('#/components/schemas/')),
			$spec['paths']['/objects/{entity}']['get']['responses']['200']['content']['application/json']['schema']['items']['oneOf']
		);

		self::assertContains('LocationResolved', $members, 'the listable entity issue #232 found missing from the union');

		$ambiguous = [];
		foreach ($members as $candidate)
		{
			$properties = array_keys($spec['components']['schemas'][$candidate]['properties']);

			foreach ($members as $other)
			{
				$required = $spec['components']['schemas'][$other]['required'] ?? null;
				self::assertNotNull($required, "$other declares no required properties, so it matches any object");

				if ($other !== $candidate && array_diff($required, $properties) === [])
				{
					// Every property $other requires exists on $candidate, so a $candidate
					// row would validate against $other as well - which is how every entity
					// used to decode as a Product.
					$ambiguous[] = "a $candidate row would also match $other";
				}
			}
		}

		self::assertSame([], $ambiguous, implode('; ', $ambiguous));
	}

	public function testTheWriteBodiesDoNotShareTheReadUnion(): void
	{
		$spec = self::spec();

		// The read members now require properties the server strips from a write body
		// (id, row_created_timestamp) and that a partial PUT has no reason to carry, so the
		// two request bodies name their own schema. See GenericEntityWrite.
		foreach ([['/objects/{entity}', 'post'], ['/objects/{entity}/{objectId}', 'put']] as [$path, $method])
		{
			self::assertSame(
				['$ref' => '#/components/schemas/GenericEntityWrite'],
				$spec['paths'][$path][$method]['requestBody']['content']['application/json']['schema'],
				"$method $path"
			);
		}
	}

	public function testQuantityUnitsDoNotDecodeAsProducts(): void
	{
		// The concrete loss issue #232 names: name_plural is on no other union member, so a
		// quantity unit read through the generic route carries it and cannot be mistaken
		// for a Product, whose required qu_id_stock it does not have.
		$rows = self::get('/api/objects/quantity_units');
		self::assertNotEmpty($rows);

		foreach ($rows as $row)
		{
			self::assertArrayHasKey('name_plural', $row);
			self::assertArrayNotHasKey('qu_id_stock', $row);
		}

		foreach (self::get('/api/objects/locations') as $row)
		{
			self::assertArrayHasKey('parent_location_id', $row, 'the other field issue #232 names as dropped');
		}
	}

	/**
	 * Which members of the GET /objects/{entity} union each listable entity's rows validate
	 * against - measured, not intended. Every entity absent from this map validates against
	 * none, which is the loud failure ADR-0027 decision 3 is for.
	 *
	 * The first ten are the intended pairings. The last three are the ones ADR-0027's
	 * consequences name: unmodelled entities whose rows happen to carry
	 * every property some member declares `required`, so `oneOf` matches exactly one member
	 * - the wrong one - and a strict client decodes the row silently under that schema
	 * rather than failing. Required properties discriminate the ten from each other; they do
	 * not discriminate them from every relation in the database.
	 *
	 * uihelper_shopping_list is the one that cannot be fixed by requiring more: it is a
	 * superset of shopping_list, so no property shopping_list has distinguishes them. That
	 * needs `additionalProperties: false` on ShoppingListItem, which needs ShoppingListItem
	 * to declare every column shopping_list has - it declares seven of eight today - and the
	 * same is true of nine of the ten members. See ADR-0027's options E and D.
	 */
	private const UNION_MATCHES = [
		'products' => ['Product'],
		'chores' => ['Chore'],
		'product_barcodes' => ['ProductBarcode'],
		'batteries' => ['Battery'],
		'locations' => ['Location'],
		'quantity_units' => ['QuantityUnit'],
		'shopping_list' => ['ShoppingListItem'],
		'userfields' => ['Userfield'],
		'stock' => ['StockEntry'],
		'locations_resolved' => ['LocationResolved'],
		'stock_log' => ['StockEntry'],
		'product_barcodes_view' => ['ProductBarcode'],
		'uihelper_shopping_list' => ['ShoppingListItem']
	];

	/**
	 * The three above, proven the only way that counts: a real row off a real response,
	 * carrying every property the wrong member requires.
	 *
	 * testEveryListableEntityIsMeasuredAgainstTheUnion() below is exhaustive but falls back
	 * to the relation's columns for an entity the fixture leaves empty. These three do not
	 * fall back, so the claim ADR-0027 makes about them rests on responses.
	 */
	private const AMBIGUOUS_ON_REAL_ROWS = ['stock_log', 'product_barcodes_view', 'uihelper_shopping_list'];

	/**
	 * Every listable entity, against every member of the union - which is the check issue
	 * #232's fix needed and did not get. testTheGenericEntityUnionMembersAreMutuallyExclusive()
	 * above compares the ten members with each other, and that is all it can see; it cannot
	 * see the forty-seven entities that have no member and still match one.
	 *
	 * The key set is what a row actually carries: the response's own keys where the fixture
	 * gives the entity a row, and the relation's columns where it does not. Both are checked
	 * against each other wherever both exist, so the fallback is not taken on trust.
	 */
	public function testEveryListableEntityIsMeasuredAgainstTheUnion(): void
	{
		$spec = self::spec();
		$members = array_map(
			fn (array $ref) => substr($ref['$ref'], strlen('#/components/schemas/')),
			$spec['paths']['/objects/{entity}']['get']['responses']['200']['content']['application/json']['schema']['items']['oneOf']
		);
		$required = [];
		foreach ($members as $member)
		{
			$required[$member] = $spec['components']['schemas'][$member]['required'];
		}

		$columns = self::relationColumns();
		$noListing = $spec['components']['schemas']['ExposedEntityNoListing']['enum'];
		$measured = [];
		$fromResponse = [];

		foreach ($spec['components']['schemas']['ExposedEntity']['enum'] as $entity)
		{
			if (in_array($entity, $noListing, true))
			{
				continue;
			}

			$answer = self::send('GET', "/api/objects/$entity");
			self::assertSame(200, $answer['status'], "/api/objects/$entity answered {$answer['status']}");
			$rows = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);

			self::assertArrayHasKey($entity, $columns, "no relation named $entity");

			if (count($rows) > 0)
			{
				$keys = array_keys($rows[0]);
				$fromResponse[] = $entity;

				// The fallback the empty entities use, checked against the real thing: a
				// row's keys are its relation's columns, plus the userfields map where the
				// entity has userfields. GET /objects/locations narrows its own select.
				$unexpected = array_diff($keys, $columns[$entity], ['userfields']);
				self::assertSame([], array_values($unexpected), "$entity answered keys no column explains");
				if ($entity !== 'locations')
				{
					self::assertSame(
						[],
						array_values(array_diff($columns[$entity], $keys)),
						"$entity has columns its response does not carry, so the column fallback overstates it"
					);
				}
			}
			else
			{
				$keys = $columns[$entity];
			}

			$matches = [];
			foreach ($members as $member)
			{
				if (array_diff($required[$member], $keys) === [])
				{
					$matches[] = $member;
				}
			}

			if ($matches !== [])
			{
				$measured[$entity] = $matches;
			}
		}

		// Both sides by entity name rather than in the enum's order: what is under test is
		// which entities match which members, not where the document happens to list them.
		$expected = self::UNION_MATCHES;
		ksort($expected);
		ksort($measured);
		self::assertSame($expected, $measured);

		self::assertSame(
			[],
			array_values(array_diff(self::AMBIGUOUS_ON_REAL_ROWS, $fromResponse)),
			'an entity ADR-0027 states is ambiguous was measured off its columns rather than off a response'
		);
	}

	/**
	 * The columns of every relation in the test schema, keyed by relation name. Views are
	 * included, which is most of what is listable.
	 *
	 * @return array<string,string[]>
	 */
	private static function relationColumns(): array
	{
		$statement = self::$db->prepare(
			'SELECT table_name, column_name FROM information_schema.columns '
			. 'WHERE table_schema = ? ORDER BY table_name, ordinal_position'
		);
		$statement->execute([self::Schema()]);

		$columns = [];
		foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row)
		{
			$columns[$row['table_name']][] = $row['column_name'];
		}

		return $columns;
	}

	// --------------------------------------------------------------------- issue #233

	public function testCurrentUserIsDocumentedAsTheArrayItReturns(): void
	{
		$documented = self::spec()['paths']['/user']['get']['responses']['200']['content']['application/json']['schema'];

		self::assertSame('array', $documented['type']);
		self::assertSame('#/components/schemas/UserDto', $documented['items']['$ref']);

		$body = self::get('/api/user');
		self::assertIsArray($body);
		self::assertArrayHasKey(0, $body, 'the response is a JSON array, not an object');
		self::assertSame(9500, $body[0]['id']);
		self::assertArrayHasKey('username', $body[0]);
	}

	// --------------------------------------------- the schemas no path references

	/**
	 * Every schema in `components/schemas` that nothing outside `components/schemas`
	 * references, and why each one is there.
	 *
	 * The measurement is reachability, seeded from the whole document except
	 * `components/schemas` - `paths` alone is not the seed, because `components/parameters`
	 * carries `$ref`s of its own and because the paths name *derived* enums
	 * (`ExposedEntity_NotIncludingNotListable`) rather than the base vocabularies those are
	 * computed from. testEveryUnreferencedSchemaIsClassified() runs that walk and requires
	 * the answer to be exactly the keys below.
	 *
	 * A `$ref` is not the only way a schema can be load-bearing, which is the whole reason
	 * this table exists rather than a rule that unreferenced means dead. There are three
	 * honest ways to be here and one dishonest one, and the dishonest one - nothing renders
	 * the rows and no vocabulary reads them - is not a category: `StockJournal` was that,
	 * and it was deleted (testTheDeadJournalSchemasStayDeleted()), as were `ApiKey` and
	 * `Session` (testTheCredentialSchemasStayDeleted()).
	 *
	 *   'runtime'   - PHP reads the schema out of the document at request time, so the
	 *                 reference is a property lookup rather than a `$ref` and no walk over
	 *                 the JSON can see it. Proven by
	 *                 testTheRuntimeVocabulariesAreReadByTheApplication().
	 *   'served:x'  - `GET /objects/x` answers rows of exactly this shape today. The schema
	 *                 is simply not one of the ten members of that route's undiscriminated
	 *                 union, which the union's own description states is deliberate: an
	 *                 entity with no member is "undocumented rather than absent". Proven by
	 *                 testTheServedSchemasAreAnsweredByTheGenericEntityRoute().
	 *   'dev-only'  - the application renders this body, but only in `dev`. Proven by
	 *                 testError500IsTheDevelopmentBodyOnly().
	 *
	 * Adding a row is not a way to keep a schema. Each value is a claim with a test behind
	 * it, and a new schema that no path references has to make one of those claims true.
	 */
	private const UNREFERENCED_SCHEMAS = [
		'Error500' => 'dev-only',
		'ExposedEntity' => 'runtime',
		'ExposedEntityEditRequiresAdmin' => 'runtime',
		'ExposedEntityNoDelete' => 'runtime',
		'ExposedEntityNoEdit' => 'runtime',
		'ExposedEntityNoListing' => 'runtime',
		'ProductGroupResolved' => 'served:product_groups_resolved',
		'StorageClass' => 'served:storage_classes',
		'StringEnumTemplate' => 'runtime',
		'Task' => 'served:tasks'
	];

	/**
	 * The walk itself, so the set cannot grow in silence. A schema that stops being
	 * referenced fails here until someone says which of the three kinds it is, and a
	 * classified schema that is deleted fails here too.
	 */
	public function testEveryUnreferencedSchemaIsClassified(): void
	{
		$spec = self::spec();
		$schemas = $spec['components']['schemas'];

		$seed = array_diff_key($spec, ['components' => null])
			+ ['components' => array_diff_key($spec['components'], ['schemas' => null])];

		$reached = [];
		$pending = self::schemaRefsIn($seed);
		while ($pending !== [])
		{
			$name = array_pop($pending);
			if (isset($reached[$name]) || !isset($schemas[$name]))
			{
				continue;
			}

			$reached[$name] = true;
			$pending = array_merge($pending, self::schemaRefsIn($schemas[$name]));
		}

		$unreferenced = array_values(array_diff(array_keys($schemas), array_keys($reached)));
		sort($unreferenced);
		$classified = array_keys(self::UNREFERENCED_SCHEMAS);
		sort($classified);

		self::assertSame(
			$classified,
			$unreferenced,
			'the set of schemas no path references changed. A new one needs a row in '
				. 'UNREFERENCED_SCHEMAS naming which kind it is, and a removed one needs its row gone.'
		);
	}

	/**
	 * Every "#/components/schemas/X" appearing anywhere in the given node.
	 *
	 * @param mixed $node
	 * @return string[]
	 */
	private static function schemaRefsIn($node): array
	{
		$matches = [];
		preg_match_all(
			'~"#/components/schemas/([A-Za-z0-9_.-]+)"~',
			json_encode($node, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			$matches
		);

		return array_values(array_unique($matches[1]));
	}

	/**
	 * The six vocabularies PHP reads out of the document at request time.
	 *
	 * Five of them - `StringEnumTemplate` and the four `ExposedEntity*` lists -
	 * are read by `OpenApiController::DocumentationSpec()`, which derives the four
	 * `ExposedEntity_*` enums the paths actually reference. Deriving them here from the
	 * committed document and comparing against what the route serves is what makes the
	 * reading observable: each derived enum subtracts a different one of the exclusion
	 * lists, and all four start from the template's own single empty member, so a
	 * vocabulary that had been deleted would show up as a wrong enum rather than as a
	 * missing file.
	 *
	 * The sixth, `ExposedEntityEditRequiresAdmin`, is not part of that derivation. It is
	 * read by `GenericEntityApiController::IsEntityWithEditRequiresAdmin()`, which gates
	 * writes to the two entities it names behind ADMIN, so it is proven by a request that
	 * the gate refuses - and by one beside it that it lets through, since a caller refused
	 * everything would prove nothing about the enum.
	 */
	public function testTheRuntimeVocabulariesAreReadByTheApplication(): void
	{
		$committed = self::spec()['components']['schemas'];
		$answer = self::send('GET', '/api/openapi/specification');
		self::assertSame(200, $answer['status'], "the specification route answered {$answer['status']}");
		$served = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];

		$template = $committed['StringEnumTemplate']['enum'];
		self::assertSame([''], $template, 'the template is the empty seed every derived enum is cloned from');

		foreach ([
			'ExposedEntity_NotIncludingNotEditable' => 'ExposedEntityNoEdit',
			'ExposedEntity_NotIncludingNotDeletable' => 'ExposedEntityNoDelete',
			'ExposedEntity_NotIncludingNotListable' => 'ExposedEntityNoListing'
		] as $derived => $excluded)
		{
			$expected = array_merge(
				$template,
				array_diff($committed['ExposedEntity']['enum'], $committed[$excluded]['enum'])
			);
			sort($expected);

			self::assertSame($expected, $served[$derived]['enum'], "$derived is not $excluded subtracted from ExposedEntity");
		}

		// The user-entity variant reads the template and the userfields service rather than
		// an exclusion list, so it is the one that would survive ExposedEntity going away.
		self::assertSame(
			$template,
			array_values(array_intersect($template, $served['ExposedEntity_IncludingUserEntities']['enum'])),
			'ExposedEntity_IncludingUserEntities was not cloned from StringEnumTemplate'
		);

		// And the sixth, by the gate it drives. The caller here has ADMIN, so the refusal
		// has to come from a caller that does not.
		$editRequiresAdmin = $committed['ExposedEntityEditRequiresAdmin']['enum'];
		self::assertSame(['userfields', 'userentities'], $editRequiresAdmin);

		$refused = self::sendAs(self::$masterDataKey, 'POST', '/api/objects/userfields', [], [
			'entity' => 'products', 'name' => 'gate_probe', 'caption' => 'Gate probe', 'type' => 'text'
		]);
		self::assertSame(403, $refused['status'], "a non-admin writing userfields was answered {$refused['status']}: {$refused['body']}");

		$allowed = self::sendAs(self::$masterDataKey, 'POST', '/api/objects/tasks', [], ['name' => 'GateProbeTask']);
		self::assertSame(200, $allowed['status'], "the same caller writing an ungated entity was answered {$allowed['status']}: {$allowed['body']}");
	}

	/**
	 * The three schemas that describe rows `GET /objects/{entity}` answers today and that
	 * are simply not members of its union.
	 *
	 * Checked against a real response rather than against the relation's columns, and in
	 * both directions: every property the schema declares is a key the row carries, and
	 * every key the row carries is a property the schema declares. That is the claim a
	 * reader of UNREFERENCED_SCHEMAS would otherwise have to take on trust, and it is the
	 * one that would quietly stop being true if a column were added to one of these
	 * relations without the schema following it.
	 *
	 * `userfields` is excused in both forms, for two different reasons. The generic list
	 * does not attach the userfields map at all, so a schema that documents it - `Task` and
	 * `StorageClass` do, being base tables - is not contradicted by a list row without it.
	 * The single-object read attaches it to *every* entity, views included, so
	 * `ProductGroupResolved` carries a key it does not document. That is the convention the
	 * document already follows everywhere - `LocationResolved` and
	 * `ProductSubstitutionResolved` omit `userfields` too, and both are union members - so
	 * it is a question about the single read's shape rather than about this table, and
	 * deciding it here would change two schemas issue #232 pinned.
	 */
	public function testTheServedSchemasAreAnsweredByTheGenericEntityRoute(): void
	{
		$schemas = self::spec()['components']['schemas'];

		foreach (self::UNREFERENCED_SCHEMAS as $schema => $kind)
		{
			if (!str_starts_with($kind, 'served:'))
			{
				continue;
			}

			$entity = substr($kind, strlen('served:'));
			$documented = array_keys($schemas[$schema]['properties']);

			$answer = self::send('GET', "/api/objects/$entity");
			self::assertSame(200, $answer['status'], "/api/objects/$entity answered {$answer['status']}");
			$rows = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);
			self::assertNotSame([], $rows, "the fixture leaves $entity empty, so nothing here is measured off a response");

			$keys = array_keys($rows[0]);
			self::assertSame([], array_values(array_diff($keys, $documented)), "$entity answered keys $schema does not document");
			self::assertSame(
				[],
				array_values(array_diff($documented, $keys, ['userfields'])),
				"$schema documents properties a row of $entity does not carry"
			);

			// The single-object read, where userfields is attached and the schema is exact.
			$single = self::send('GET', "/api/objects/$entity/{$rows[0]['id']}");
			self::assertSame(200, $single['status'], "/api/objects/$entity/{$rows[0]['id']} answered {$single['status']}");
			$row = json_decode($single['body'], true, flags: JSON_THROW_ON_ERROR);

			if (in_array('userfields', $documented, true))
			{
				self::assertArrayHasKey('userfields', $row, "$schema documents userfields and the single read does not carry it");
			}

			self::assertSame(
				[],
				array_values(array_diff(array_keys($row), $documented, ['userfields'])),
				"$entity/{id} answered keys $schema does not document"
			);
		}
	}

	/**
	 * `Error500` is the body the application renders on an uncaught exception when
	 * `VICTUAL_MODE` is `dev`: `error_message` plus an `error_details` object carrying the
	 * stack trace, file and line. It is unreferenced because production does not carry
	 * `error_details` and because no route documents a 500 at all any more - an invalid
	 * filter or sort has been a 400 since the previous release, and the nine list
	 * operations that used to document one lost it along with the field.
	 *
	 * So the schema is kept, unlike the journal pair: the shape is rendered, just never by
	 * the deployment the document describes. Both halves of that are checked, because the
	 * claim is only worth keeping while both hold - a route that documented a 500 would
	 * have to name a schema, and a production body that carried `error_details` would mean
	 * the document is describing the wrong thing.
	 */
	public function testError500IsTheDevelopmentBodyOnly(): void
	{
		$spec = self::spec();

		$documented = [];
		foreach ($spec['paths'] as $path => $operations)
		{
			foreach ($operations as $method => $operation)
			{
				if (is_array($operation) && isset($operation['responses']['500']))
				{
					$documented[] = strtoupper($method) . " $path";
				}
			}
		}

		self::assertSame(
			[],
			$documented,
			'a route documents a 500 now, so Error500 belongs to that route rather than to this table'
		);

		self::assertSame(
			['error_message', 'error_details'],
			array_keys($spec['components']['schemas']['Error500']['properties']),
			'Error500 no longer describes the dev body'
		);

		// The request helper runs in production mode, which is the half that is observable
		// from here: an error body carries error_message and nothing beside it, whichever
		// end of the handler produced it. The 500 itself is exercised where it can be - the
		// errors phase (.devtools/pgsql/error-path-tests.php) invokes ExceptionController
		// directly, because an uncaught fault cannot be provoked through the stack.
		foreach (['/api/objects/tasks/999999999', '/api/objects/not_an_entity'] as $path)
		{
			$failed = self::send('GET', $path);
			self::assertGreaterThanOrEqual(400, $failed['status'], $path);
			$body = json_decode($failed['body'], true, flags: JSON_THROW_ON_ERROR);
			self::assertArrayHasKey('error_message', $body, $path);
			self::assertArrayNotHasKey('error_details', $body, "$path carries error_details in production");
		}
	}

	/**
	 * `ApiKey` and `Session` described `api_keys` and `sessions`, and no route answers
	 * either. `api_keys` is an `ExposedEntity` but is the sole member of
	 * `ExposedEntityNoListing`, so `GenericEntityApiController` refuses both reads of it;
	 * `sessions` is not an `ExposedEntity` at all. Both answer 400, which is what the
	 * schemas were describing a response to.
	 *
	 * Deleted rather than routed, and here the deferral the journal pair got does not
	 * apply: these two relations hold credentials - a key's SHA-256 hash and hint, a live
	 * session key - and `ExposedEntityNoListing` exists to stop the generic route answering
	 * the first of them. Exposing them is not a gap plan 14 lists among the reads it says
	 * are "argued explicitly rather than slipped in"; it is something the design refuses.
	 * The document claiming otherwise was the only thing saying it might happen.
	 *
	 * What still describes a key is `CurrentUserCapabilities`, which answers `key_type` and
	 * `read_only` about the calling credential rather than any stored row - the shape
	 * issue #208 actually gave a route to. `/manageapikeys` renders the rows themselves and
	 * is untouched, as the journal's Blade pages were.
	 */
	public function testTheCredentialSchemasStayDeleted(): void
	{
		$spec = self::spec();

		foreach (['ApiKey' => 'api_keys', 'Session' => 'sessions'] as $schema => $entity)
		{
			self::assertArrayNotHasKey(
				$schema,
				$spec['components']['schemas'],
				"$schema is declared again. If a route now answers $entity rows, that is a widening "
					. 'to argue rather than a schema to restore, and this test is where to record the argument.'
			);

			self::assertSame(400, self::send('GET', "/api/objects/$entity")['status'], $entity);
			self::assertSame(400, self::send('GET', "/api/objects/$entity/1")['status'], "$entity/1");
		}

		// The two reasons those 400s hold, so the day either changes this test is the one
		// that fails rather than the reachability walk above.
		self::assertSame(['api_keys'], $spec['components']['schemas']['ExposedEntityNoListing']['enum']);
		self::assertNotContains('sessions', $spec['components']['schemas']['ExposedEntity']['enum']);
	}

	// ----------------------------------------------------------------------- the document

	/** @return array<string,mixed> */
	private static function spec(): array
	{
		if (self::$spec === null)
		{
			self::$spec = json_decode(
				file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'),
				true,
				flags: JSON_THROW_ON_ERROR
			);
		}

		return self::$spec;
	}
}
