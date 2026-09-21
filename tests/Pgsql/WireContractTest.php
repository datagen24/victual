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
 * And, from the same family, ADR-0028: the three write routes that take a timestamp
 * (tracked_time on chore execution and battery charge, done_time on task completion)
 * accept the renderings their schema documents, refuse what they cannot read, and never
 * answer a value they could not use by booking the current time instead.
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

	/** A zone with a one-hour spring-forward gap, for the cases UTC structurally cannot reach. */
	private const DST_ZONE = 'America/New_York';

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
			. "VALUES (9500, 'WireChore', 'daily', 1, '2026-01-01 08:00:00', 1, 0), "
			// 9500 is track_date_only, and ChoresService::TrackChore() truncates an execution
			// of one of those to midnight - so the ADR-0028 cases, which are about what the
			// route did with the caller's time of day, need a chore that keeps one.
			. "(9501, 'WireChoreTimed', 'daily', 1, '2026-01-01 08:00:00', 0, 0)");

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

		// One battery for the charge route. Tasks that a test *completes* are made per test
		// (freshTask()), because "was this one marked done?" has no answer on a task an
		// earlier test completed - but one standing task is seeded here so that a test
		// merely *reading* tasks has a row of its own. Without it
		// testTheServedSchemasAreAnsweredByTheGenericEntityRoute() passed only on rows the
		// #229 cases happened to leave behind, and failed when run alone under --filter.
		self::$db->exec("INSERT INTO batteries (id, name) VALUES (9500, 'WireBattery')");
		self::$db->exec("INSERT INTO tasks (id, name, due_date) VALUES (9500, 'WireServedTask', DATE '2026-10-01')");
	}

	/** A task nothing else has touched. */
	private static function freshTask(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO tasks (name) VALUES (?) RETURNING id');
		$statement->execute([$name]);

		return (int)$statement->fetchColumn();
	}

	// ------------------------------------------------------------------ the request half

	/** @return array{status: int, body: string} */
	private static function send(string $method, string $path, array $headers = [], ?array $body = null, ?string $timezone = null): array
	{
		return self::sendAs(self::$key, $method, $path, $headers, $body, $timezone);
	}

	/** The same request, made by whoever holds $key. @return array{status: int, body: string} */
	private static function sendAs(string $key, string $method, string $path, array $headers = [], ?array $body = null, ?string $timezone = null): array
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
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			// '' rather than absent: the inherited environment is merged in above, so a
			// request that asked for no zone has to overwrite one an earlier one set.
			'VICTUAL_TEST_TIMEZONE' => $timezone ?? ''
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

	// ------------------------------------------------- ADR-0028, the three write times

	/**
	 * The renderings the three fields accept, and what each one has to be stored as.
	 *
	 * The offset-bearing expectations are written as the *instant* rather than as a string,
	 * because what they render to depends on the server's zone and the suite does not fix
	 * one. inServerZone() asks PostgreSQL, which is an oracle independent of the PHP the
	 * code under test uses - and the question "do the application and its database agree
	 * about the zone?" is one this happens to answer too.
	 *
	 * @return array<string, array{0: string, 1: string|null}> value => [literal, instant]
	 */
	private static function acceptedRenderings(): array
	{
		return [
			'the storage rendering' => ['2026-03-04 05:06:07', null],
			'a bare date' => ['2026-03-04', null],
			// "RFC 3339 shaped", not RFC 3339: that grammar requires the offset the first of
			// these omits, and permits a leap second wrongShape() refuses. ADR-0028 decision 2.
			'the T form without an offset, which RFC 3339 does not allow' => ['2026-03-04T05:06:07', null],
			'the T form in UTC' => ['2026-03-04T05:06:07Z', '2026-03-04T05:06:07Z'],
			'the T form with an offset' => ['2026-03-04T05:06:07+02:00', '2026-03-04T05:06:07+02:00'],
			'the T form with fractional seconds' => ['2026-03-04T05:06:07.123Z', '2026-03-04T05:06:07Z'],
			// Seven digits is .NET's round-trip format and nine is Go's RFC3339Nano; PHP's
			// "u" parses at most six, so both were refused while the document said they were
			// fine. CodeRabbit found the seven-digit case on pull request 235 and a sweep of
			// the shape space found the rest.
			'more fractional digits than PHP parses' => ['2026-03-04T05:06:07.1234567Z', '2026-03-04T05:06:07Z'],
			'fractional nanoseconds' => ['2026-03-04T05:06:07.123456789+02:00', '2026-03-04T05:06:07+02:00']
		];
	}

	/** What each accepted rendering must be stored as. */
	private static function expectedFor(string $literal, ?string $instant): string
	{
		if ($instant !== null)
		{
			return self::inServerZone($instant);
		}

		// No offset in the value, so it names a wall clock and the wall clock is kept. A
		// bare date is its midnight.
		return strlen($literal) === 10 ? $literal . ' 00:00:00' : str_replace('T', ' ', $literal);
	}

	/** $instant rendered in the time zone the application's own connection is set to. */
	private static function inServerZone(string $instant): string
	{
		$statement = self::$db->prepare("SELECT to_char(?::timestamptz AT TIME ZONE current_setting('TimeZone'), 'YYYY-MM-DD HH24:MI:SS')");
		$statement->execute([$instant]);

		return (string)$statement->fetchColumn();
	}

	/**
	 * Values in none of the accepted renderings, so the schema's `pattern` refuses them too.
	 */
	private static function wrongShape(): array
	{
		return [
			'an empty string' => '',
			'prose' => 'yesterday afternoon',
			'a relative expression new DateTimeImmutable() would have taken' => 'tomorrow',
			'the word now, likewise' => 'now',
			'a Unix timestamp' => '1772600767',
			'a truncated time' => '2026-03-04 05:06',
			'basic-format ISO 8601' => '20260304T050607Z',
			'a space separator with an offset, which this API never renders' => '2026-03-04 05:06:07+02:00',
			'an hour no day has' => '2026-03-04 25:06:07',
			'a minute no hour has' => '2026-03-04T05:60:07Z',
			'a second no minute has, leap seconds included' => '2026-03-04T05:06:60Z',
			'an offset with sixty minutes in it' => '2026-03-04T05:06:07+02:60',
			'an offset no zone has' => '2026-03-04T05:06:07+24:00',
			// The one that was booking a date four days off the one it named: as plain
			// \d{2} the pattern took it and createFromFormat() read it as a hundred-hour
			// offset without a warning. CodeRabbit, pull request 235.
			'an offset that is not a time at all' => '2026-03-04T05:06:07+99:99',
			// Valid RFC 3339 and refused on purpose: createFromFormat() reads it as
			// 2017-01-01 00:00:00, so taking it would book a different day without a word,
			// and a TIMESTAMP column cannot hold a leap second anyway. ADR-0028 decision 2.
			'a leap second, which RFC 3339 allows and nothing here can hold' => '2016-12-31T23:59:60Z',
			'a month there is no thirteenth of' => '2026-13-04 00:00:00',
			'a thirty-second of the month' => '2026-03-32 00:00:00'
		];
	}

	/**
	 * Values the server refuses that the schema's `pattern` cannot, and is not expected to.
	 *
	 * Every component of the pattern is range-bounded, so an impossible hour, minute, second
	 * or offset is refused by the document as well. What a regular expression cannot say is
	 * **how many days a month has** - and that is all that is left here.
	 *
	 * That is the one place the document is deliberately looser than the server, and
	 * testNothingTheDocumentedPatternRefusesIsAccepted() proves it is the *only* one rather
	 * than leaving it asserted here and hoped for elsewhere.
	 */
	private static function rightShapeNotATime(): array
	{
		return [
			'a day February does not have' => '2026-02-30 00:00:00',
			'a thirty-first of April' => '2026-04-31T00:00:00Z'
		];
	}

	/** Values none of the three routes may read as a time, by any route. */
	private static function unreadableValues(): array
	{
		return self::wrongShape() + self::rightShapeNotATime() + [
			'null' => null,
			'a number' => 1772600767,
			'an object' => ['at' => '2026-03-04 05:06:07']
		];
	}

	public function testAnAbsentTimestampBooksTheCurrentTime(): void
	{
		$before = date('Y-m-d H:i:s');

		$chore = self::send('POST', '/api/chores/9501/execute', [], []);
		self::assertSame(200, $chore['status'], $chore['body']);
		$choreRow = json_decode($chore['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertGreaterThanOrEqual($before, $choreRow['tracked_time'], 'chores_log.tracked_time');

		$battery = self::send('POST', '/api/batteries/9500/charge', [], []);
		self::assertSame(200, $battery['status'], $battery['body']);
		$batteryRow = json_decode($battery['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertGreaterThanOrEqual($before, $batteryRow['tracked_time'], 'battery_charge_cycles.tracked_time');

		$taskId = self::freshTask('WireTaskDefaultNow');
		$task = self::send('POST', '/api/tasks/' . $taskId . '/complete', [], []);
		self::assertSame(204, $task['status'], $task['body']);
		self::assertGreaterThanOrEqual($before, self::taskDoneTimestamp($taskId), 'tasks.done_timestamp');
	}

	public function testAnAcceptedRenderingIsStoredAsTheDocumentedOne(): void
	{
		foreach (self::acceptedRenderings() as $what => [$literal, $instant])
		{
			$expected = self::expectedFor($literal, $instant);

			$chore = self::send('POST', '/api/chores/9501/execute', [], ['tracked_time' => $literal]);
			self::assertSame(200, $chore['status'], "chore execution, $what: {$chore['body']}");
			self::assertSame(
				$expected,
				json_decode($chore['body'], true, flags: JSON_THROW_ON_ERROR)['tracked_time'],
				"chore execution, $what ($literal)"
			);

			$battery = self::send('POST', '/api/batteries/9500/charge', [], ['tracked_time' => $literal]);
			self::assertSame(200, $battery['status'], "battery charge, $what: {$battery['body']}");
			self::assertSame(
				$expected,
				json_decode($battery['body'], true, flags: JSON_THROW_ON_ERROR)['tracked_time'],
				"battery charge, $what ($literal)"
			);

			$taskId = self::freshTask('WireTaskAccepts ' . $literal);
			$task = self::send('POST', '/api/tasks/' . $taskId . '/complete', [], ['done_time' => $literal]);
			self::assertSame(204, $task['status'], "task completion, $what: {$task['body']}");
			self::assertSame($expected, self::taskDoneTimestamp($taskId), "task completion, $what ($literal)");
		}
	}

	/**
	 * The regression this whole section exists for. Every one of these used to be answered
	 * 200 with the current time booked in place of the caller's value, so an assertion on
	 * the status alone would have passed against the defect; what makes it a test of the
	 * defect is that nothing was written at all.
	 */
	public function testAValueTheServerCannotUseIsRefusedAndNothingIsBooked(): void
	{
		foreach (self::unreadableValues() as $what => $value)
		{
			$chores = self::rowCount('chores_log');
			$response = self::send('POST', '/api/chores/9501/execute', [], ['tracked_time' => $value]);
			self::assertSame(400, $response['status'], "chore execution, $what: {$response['body']}");
			self::assertStringContainsString('tracked_time', $response['body'], "chore execution, $what");
			self::assertSame($chores, self::rowCount('chores_log'), "chore execution, $what: nothing was booked");

			$cycles = self::rowCount('battery_charge_cycles');
			$response = self::send('POST', '/api/batteries/9500/charge', [], ['tracked_time' => $value]);
			self::assertSame(400, $response['status'], "battery charge, $what: {$response['body']}");
			self::assertStringContainsString('tracked_time', $response['body'], "battery charge, $what");
			self::assertSame($cycles, self::rowCount('battery_charge_cycles'), "battery charge, $what: nothing was booked");

			$taskId = self::freshTask('WireTaskRefuses: ' . $what);
			$response = self::send('POST', '/api/tasks/' . $taskId . '/complete', [], ['done_time' => $value]);
			self::assertSame(400, $response['status'], "task completion, $what: {$response['body']}");
			self::assertStringContainsString('done_time', $response['body'], "task completion, $what");
			self::assertNull(self::taskDoneTimestamp($taskId), "task completion, $what: the task was not completed");
		}
	}

	/**
	 * A wall clock the server's zone skipped is refused, on all three routes.
	 *
	 * `2026-03-08 02:30:00` does not happen in `America/New_York`: the clock goes from
	 * 01:59:59 to 03:00:00. PHP moves such a value forward to 03:30 and reports no warning
	 * for it, so before this the routes answered 200 and booked an hour later than the one
	 * the caller wrote - the defect ADR-0028 exists to remove, arriving by a different door.
	 * Found in review of pull request 235.
	 *
	 * The suite runs on UTC, which has no skipped hour, so this is the one case that has to
	 * say which zone the server is in (VICTUAL_TEST_TIMEZONE, read by
	 * tests/Pgsql/request-subprocess-helper.php).
	 */
	public function testAWallClockTheServersZoneSkippedIsRefused(): void
	{
		foreach (['2026-03-08 02:30:00', '2026-03-08T02:30:00'] as $skipped)
		{
			$chores = self::rowCount('chores_log');
			$response = self::send('POST', '/api/chores/9501/execute', [], ['tracked_time' => $skipped], self::DST_ZONE);
			self::assertSame(400, $response['status'], "chore execution, $skipped: {$response['body']}");
			self::assertStringContainsString('tracked_time', $response['body']);
			self::assertStringContainsString('daylight saving', $response['body'], 'the refusal says why, rather than reciting the shape the value already has');
			self::assertSame($chores, self::rowCount('chores_log'), "chore execution, $skipped: nothing was booked");

			$cycles = self::rowCount('battery_charge_cycles');
			$response = self::send('POST', '/api/batteries/9500/charge', [], ['tracked_time' => $skipped], self::DST_ZONE);
			self::assertSame(400, $response['status'], "battery charge, $skipped: {$response['body']}");
			self::assertSame($cycles, self::rowCount('battery_charge_cycles'), "battery charge, $skipped: nothing was booked");

			$taskId = self::freshTask('WireTaskSkippedHour ' . $skipped);
			$response = self::send('POST', '/api/tasks/' . $taskId . '/complete', [], ['done_time' => $skipped], self::DST_ZONE);
			self::assertSame(400, $response['status'], "task completion, $skipped: {$response['body']}");
			self::assertNull(self::taskDoneTimestamp($taskId), "task completion, $skipped: the task was not completed");
		}
	}

	/**
	 * What the refusal above must not swallow. The hour either side of the gap is ordinary,
	 * the repeated hour at the other end of the year is expressible as a wall clock and is
	 * kept, and a value carrying an offset names an instant - every instant has a wall clock
	 * in every zone, including one inside the gap window.
	 */
	public function testOnlyTheSkippedHourIsRefusedInADstZone(): void
	{
		$cases = [
			'the hour before the gap' => ['2026-03-08 01:30:00', '2026-03-08 01:30:00'],
			'the hour after it' => ['2026-03-08 03:30:00', '2026-03-08 03:30:00'],
			// 01:30 happens twice on this date. PHP takes the first and the wall clock
			// survives, which is all this API stores; which instant was meant is a question
			// a wall-clock string cannot ask (ADR-0027 decision 2), and refusing it would
			// lose a booking that is perfectly expressible.
			'the hour that happens twice' => ['2026-11-01 01:30:00', '2026-11-01 01:30:00'],
			// 02:30 UTC is 21:30 the previous evening in New York - a real moment, named as
			// one, so the gap never enters into it.
			'an instant whose UTC rendering sits in the gap' => ['2026-03-08T02:30:00Z', '2026-03-07 21:30:00']
		];

		foreach ($cases as $what => [$sent, $stored])
		{
			$response = self::send('POST', '/api/chores/9501/execute', [], ['tracked_time' => $sent], self::DST_ZONE);
			self::assertSame(200, $response['status'], "$what ($sent): {$response['body']}");
			self::assertSame(
				$stored,
				json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)['tracked_time'],
				"$what ($sent)"
			);
		}
	}

	/**
	 * The same rule across zones, against the function rather than over HTTP, because the
	 * interesting zones are more numerous than the routes and every one of them would
	 * otherwise be three subprocesses.
	 *
	 * `America/Santiago` is here because its clock jumps at **midnight**, which the HTTP
	 * cases above cannot reach: it makes a *bare date* unbookable, a third rendering the
	 * original report did not name.
	 */
	public function testTheSkippedWallClockRuleHoldsAcrossZones(): void
	{
		$zone = date_default_timezone_get();

		try
		{
			$cases = [
				// zone, value, expected ('' = refused)
				['America/New_York', '2026-03-08 02:30:00', ''],
				['America/New_York', '2026-03-08T02:30:00', ''],
				['America/New_York', '2026-03-08T02:30:00Z', '2026-03-07 21:30:00'],
				['America/New_York', '2026-03-08 01:30:00', '2026-03-08 01:30:00'],
				['America/New_York', '2026-11-01 01:30:00', '2026-11-01 01:30:00'],
				// Midnight does not exist on this date here, so neither does the bare date.
				['America/Santiago', '2026-09-06', ''],
				['America/Santiago', '2026-09-06 00:00:00', ''],
				['America/Santiago', '2026-09-06 01:00:00', '2026-09-06 01:00:00'],
				// Australia/Lord_Howe shifts by thirty minutes rather than an hour.
				['Australia/Lord_Howe', '2026-10-04 02:15:00', ''],
				// UTC never skips anything, which is why the suite's own zone could not have
				// found this.
				['UTC', '2026-03-08 02:30:00', '2026-03-08 02:30:00'],
				['UTC', '2026-09-06', '2026-09-06 00:00:00']
			];

			foreach ($cases as [$in, $value, $expected])
			{
				date_default_timezone_set($in);
				$actual = ParseApiDateTime($value) ?? '';
				self::assertSame($expected, $actual, "$in: $value");
			}
		}
		finally
		{
			// Every later test in this process reads the clock through this.
			date_default_timezone_set($zone);
		}
	}

	/**
	 * All three fields document the same `pattern`, and it is the very string the parser
	 * gates on - `API_DATE_TIME_PATTERN` in helpers/extensions.php, which ParseApiDateTime()
	 * matches a value against before DateTimeImmutable ever sees it.
	 *
	 * That identity is the point. Written as two independent expressions they drift, and
	 * they did: before this was structural, `createFromFormat()` quietly accepted `+0200`,
	 * `+02`, `GMT`, a single-digit hour and a doubled separator space, none of which the
	 * document promised, while the document promised fractional seconds of any length that
	 * PHP's `u` would not parse past six digits.
	 */
	public function testTheDocumentedPatternIsTheOneTheParserGatesOn(): void
	{
		$spec = self::spec();

		foreach (self::timestampFields() as [$path, $field])
		{
			$documented = $spec['paths'][$path]['post']['requestBody']['content']['application/json']['schema']['properties'][$field];

			self::assertSame('string', $documented['type'], "$path $field");
			self::assertArrayNotHasKey('format', $documented, "$path $field is not RFC 3339, so it carries no format");
			self::assertSame(\API_DATE_TIME_PATTERN, $documented['pattern'] ?? null, "$path $field");
		}
	}

	/**
	 * Over every shape the parts below can spell, **nothing the document refuses is
	 * accepted**, and everything it accepts is accepted unless the date or the hour does not
	 * exist.
	 *
	 * The first half is the direction that hurts a caller: a value the server takes but the
	 * document does not describe is a promise nobody made, and a generated client will never
	 * send it. The second half is the gap named in `rightShapeNotATime()` - a regular
	 * expression cannot know February has 28 days - and the assertion is that it is the
	 * *only* gap, rather than a sampled list hoping it is.
	 *
	 * The boundary values are the point of the corpus rather than decoration. The first
	 * version of this test carried no impossible minute, second or offset, and so did not
	 * see that `+99:99` was accepted and read as a hundred-hour offset.
	 *
	 * Against ParseApiDateTime() directly rather than over HTTP: tens of thousands of
	 * requests would be hours of subprocesses to test a pure function. The routes are
	 * covered by the cases above, which do go through the whole stack.
	 */
	public function testNothingTheDocumentedPatternRefusesIsAccepted(): void
	{
		$pattern = '/' . \API_DATE_TIME_PATTERN . '/D';

		// The only values the document accepts and the server does not: a day the month
		// does not have. Every other component is range-bounded in the pattern itself.
		$monthIsShorter = '/^(2026-02-30|2026-02-31|2026-04-31|2026-06-31|2026-09-31|2026-11-31)/';

		$acceptedButUndocumented = [];
		$refusedForAnotherReason = [];
		$total = 0;

		$dates = ['2026-03-04', '2026-12-31', '2026-02-30', '2026-04-31', '2026-13-04', '2026-03-32', '2026-00-04', '2026-03-00'];
		$separators = ['', ' ', 'T', 't', '  '];
		$times = ['05:06:07', '00:00:00', '23:59:59', '24:00:00', '05:60:07', '05:06:60', '05:06', '5:06:07', '050607'];
		$fractions = ['', '.1', '.123456', '.1234567', '.123456789', '.', '.abc'];
		$zones = ['', 'Z', 'z', '+00:00', '+02:00', '-05:30', '+23:59', '-23:59', '+24:00', '+02:60', '+99:99', '+0200', '+02', ' UTC', 'GMT'];

		foreach ($dates as $date)
		{
			foreach ($separators as $separator)
			{
				foreach ($times as $time)
				{
					foreach ($fractions as $fraction)
					{
						foreach ($zones as $zone)
						{
							$value = $separator === '' ? $date : $date . $separator . $time . $fraction . $zone;
							$total++;

							$documented = preg_match($pattern, $value) === 1;
							$accepted = ParseApiDateTime($value) !== null;

							if ($accepted && !$documented)
							{
								$acceptedButUndocumented[] = $value;
							}

							if ($documented && !$accepted && !preg_match($monthIsShorter, $value))
							{
								$refusedForAnotherReason[] = $value;
							}
						}
					}
				}
			}
		}

		self::assertGreaterThan(30000, $total, 'the corpus is the whole cross product, not a subset');
		self::assertSame([], $acceptedButUndocumented, 'accepted without being documented: ' . implode(', ', array_slice($acceptedButUndocumented, 0, 10)));
		self::assertSame([], $refusedForAnotherReason, 'documented and refused for a reason other than the month being shorter than the day given: ' . implode(', ', array_slice($refusedForAnotherReason, 0, 10)));
	}

	/** @return array<array{0: string, 1: string}> */
	private static function timestampFields(): array
	{
		return [
			['/chores/{choreId}/execute', 'tracked_time'],
			['/batteries/{batteryId}/charge', 'tracked_time'],
			['/tasks/{taskId}/complete', 'done_time']
		];
	}

	/**
	 * The browser is unaffected, and this is the half of that claim a test can hold.
	 *
	 * `choretracking.js` and `choresoverview.js` send a bare `YYYY-MM-DD` for a chore whose
	 * track_date_only is set, which is why ChoresApiController accepted IsIsoDate() as well
	 * as IsIsoDateTime() and why refusing everything but the storage rendering was not an
	 * option. The other four senders - `batterytracking.js`, `batteriesoverview.js`,
	 * `tasks.js` and the non-date-only branch of the two chore files - send
	 * moment().format('YYYY-MM-DD HH:mm:ss'). Both are here.
	 */
	public function testTheTwoRenderingsTheBrowserSendsAreAccepted(): void
	{
		$dateOnly = self::send('POST', '/api/chores/9500/execute', [], ['tracked_time' => '2026-03-04', 'skipped' => false]);
		self::assertSame(200, $dateOnly['status'], $dateOnly['body']);
		self::assertSame(
			'2026-03-04 00:00:00',
			json_decode($dateOnly['body'], true, flags: JSON_THROW_ON_ERROR)['tracked_time'],
			'the rendering choretracking.js sends for a track_date_only chore'
		);

		$full = self::send('POST', '/api/batteries/9500/charge', [], ['tracked_time' => '2026-03-04 05:06:07']);
		self::assertSame(200, $full['status'], $full['body']);
		self::assertSame(
			'2026-03-04 05:06:07',
			json_decode($full['body'], true, flags: JSON_THROW_ON_ERROR)['tracked_time'],
			'the rendering every other sender in public/viewjs uses'
		);
	}

	private static function rowCount(string $table): int
	{
		return (int)self::$db->query('SELECT count(*) FROM ' . $table)->fetchColumn();
	}

	private static function taskDoneTimestamp(int $taskId): ?string
	{
		$statement = self::$db->prepare('SELECT done_timestamp FROM tasks WHERE id = ?');
		$statement->execute([$taskId]);
		$value = $statement->fetchColumn();

		return $value === false || $value === null ? null : (string)$value;
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
	 * Which members of the GET /objects/{entity} union each listable entity's rows are a
	 * *candidate* for - that is, whose `required` properties the row carries, which is what
	 * decides the member `oneOf` can select. Measured, not intended. Every entity absent
	 * from this map is a candidate for none, which is the loud failure ADR-0027 decision 3
	 * is for.
	 *
	 * Candidacy is not a decode: UNION_FULLY_VALID below is the second measurement, and it
	 * says how far this one goes.
	 *
	 * The first ten are the intended pairings. The last three are the ones ADR-0027's
	 * consequences name: unmodelled entities whose rows happen to carry every property some
	 * member declares `required`, so exactly one member is a candidate - the wrong one - and
	 * nothing else in the union's shape rules it out. Whether that becomes a wrong decode is
	 * the reader's: a strict JSON Schema validator rejects these rows on the member's
	 * nullability rather than selecting the member, while swift-openapi-generator accepts an
	 * explicit null for an optional property through decodeIfPresent, so for that client
	 * candidacy decides it. Required properties discriminate the ten from each other; they
	 * do not discriminate them from every relation in the database.
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
	 * The three above, measured the only way that counts: a real row off a real response,
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
		$rowsByEntity = [];

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
				$rowsByEntity[$entity] = $rows;

				// Candidacy is read off the first row, so the others have to agree about
				// what keys they carry - a generic read is SELECT *, and a row that
				// disagreed would mean candidacy is a per-row question too.
				foreach ($rows as $index => $other)
				{
					self::assertSame($keys, array_keys($other), "$entity row $index carries different keys");
				}

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

		// The second measurement, on the rows themselves - every row of every entity, not
		// one representative: validity turns on values, so a later row with a non-null
		// column can validate where the first row does not. See UNION_FULLY_VALID.
		$fullyValid = [];
		foreach ($rowsByEntity as $entity => $rows)
		{
			foreach ($members as $member)
			{
				foreach ($rows as $index => $row)
				{
					$reason = self::validateAgainstMember($row, $member);

					if ($reason === null)
					{
						// Recorded once per pairing however many rows reach it.
						if (!in_array($member, $fullyValid[$entity] ?? [], true))
						{
							$fullyValid[$entity][] = $member;
						}

						continue;
					}

					if (!in_array($member, $measured[$entity] ?? [], true))
					{
						continue;
					}

					// A candidate row that does not validate: the reason has to be the
					// nullability gap this class records, not something unexplained.
					$allowed = self::UNION_NULLABILITY_FAILURES[$entity][$member] ?? null;
					self::assertNotNull($allowed, "$entity is a candidate for $member and row $index fails it unrecorded: $reason");

					[$property, $keyword] = explode(': ', $reason, 2);
					self::assertSame('type', $keyword, "$entity row $index against $member: $reason");
					self::assertContains($property, $allowed, "$entity row $index against $member fails on an unrecorded property");
					self::assertArrayHasKey($property, $row, "$entity carries no $property");
					self::assertNull($row[$property], "$entity.$property is not null on row $index, so 'type' is a different failure");
				}
			}
		}

		ksort($fullyValid);
		$expectedValid = self::UNION_FULLY_VALID;
		ksort($expectedValid);
		self::assertSame($expectedValid, $fullyValid, 'the set of rows that fully validate against a member changed');
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

	/**
	 * The second measurement, and the one that says how far the first one goes. A member's
	 * `required` decides which member `oneOf` can select; it does not decide that the row
	 * then satisfies the rest of that member's schema. This validates each entity's real row
	 * against every member with a JSON Schema validator, and records what survives.
	 *
	 * Exactly one pairing does. Every other candidate - intended and unintended alike -
	 * fails on the same defect, and it is not discrimination: a column that is NULL in the
	 * row is declared as a non-nullable scalar by the member. UNION_NULLABILITY_FAILURES
	 * names the property each one dies on.
	 *
	 * This does not make the union safe. The client ADR-0027 was written for is
	 * `swift-openapi-generator`, whose optional properties decode through
	 * `decodeIfPresent`, which accepts an explicit `null` where this validator rejects it -
	 * so the nullability failures below do not stop that client selecting the wrong member,
	 * and the required-property measurement above remains the operative one. What this
	 * bounds is the word: a candidate is a candidate, not a proven decode.
	 */
	private const UNION_FULLY_VALID = ['locations_resolved' => ['LocationResolved']];

	/**
	 * Candidate pairings that a strict JSON Schema validator rejects, and the properties a
	 * row is allowed to die on - every one a NULL against a declared scalar. A list per
	 * pairing because the validator reports the first failure only and rows differ in which
	 * of their nullable columns are set; the assertion also checks the reported value really
	 * is null on that row, so the list cannot be used to wave a real failure through.
	 *
	 * Six of the ten are the union's own intended pairings, which is why this is a gap in
	 * the members' nullability rather than a defence against the three unintended ones.
	 */
	private const UNION_NULLABILITY_FAILURES = [
		'products' => ['Product' => ['description']],
		// batteries had no row until ADR-0028's cases needed one to charge, so this pairing
		// was measured off the relation's columns only. The row behaves exactly like its
		// five siblings above and below: a NULL description against a member that declares
		// it a non-nullable scalar. It does not join UNION_FULLY_VALID.
		'batteries' => ['Battery' => ['description']],
		'chores' => ['Chore' => ['description']],
		'locations' => ['Location' => ['description']],
		'quantity_units' => ['QuantityUnit' => ['description']],
		'shopping_list' => ['ShoppingListItem' => ['note']],
		'userfields' => ['Userfield' => ['config']],
		'stock' => ['StockEntry' => ['shopping_location_id']],
		'stock_log' => ['StockEntry' => ['shopping_location_id']],
		'product_barcodes_view' => ['ProductBarcode' => ['shopping_location_id']],
		'uihelper_shopping_list' => ['ShoppingListItem' => ['note']]
	];

	/**
	 * Validates $row against the member schema $member, returning null when it is valid and
	 * "property: keyword" for the first failure otherwise.
	 *
	 * `allowDefaults` is turned off deliberately. Opis, left alone, drops a property from
	 * `required` when that property declares a `default` and then writes the default into
	 * the caller's data - so `Battery.charge_interval_days` would be treated as optional and
	 * a row without it would validate. A generated client does neither, and a measurement
	 * that models the client has to say so.
	 */
	private static function validateAgainstMember(array $row, string $member): ?string
	{
		static $document = null;
		if ($document === null)
		{
			$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
		}

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);

		// Both sides re-encoded per call: Opis mutates neither with defaults off, but the
		// schema objects are shared across members and the data is ours to keep clean.
		$result = $validator->validate(
			json_decode(json_encode($row), false),
			json_decode(json_encode($document->components->schemas->$member), false)
		);

		if ($result->isValid())
		{
			return null;
		}

		$error = $result->error();
		while ($error->subErrors())
		{
			$error = $error->subErrors()[0];
		}

		return (implode('/', $error->data()->fullPath()) ?: '<root>') . ': ' . $error->keyword();
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
