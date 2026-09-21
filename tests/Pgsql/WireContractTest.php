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

		self::seedFixtures();
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
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => $headers + ['VICTUAL-API-KEY' => self::$key], 'body' => $body],
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
	 *   'unrouted'   - the schema is declared but no path references it, so nothing renders
	 *                  the property at all. testTheUnprovenShapesAreStillUnreachable()
	 *                  is what keeps that claim honest.
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
		'StockJournal.spoiled' => 'unrouted',
		'StockLogEntry.spoiled' => 'stock_log',
		'Userfield.input_required' => 'userfields',
		'Userfield.show_as_column_in_tables' => 'userfields'
	];

	/**
	 * The other direction: every (shape, property) WireBooleans converts, and the route
	 * this class reads the converted value back from.
	 *
	 * `null` means no route reaches the shape at all, and the two that carry it are checked
	 * rather than asserted by hand - see testTheUnprovenShapesAreStillUnreachable(). They
	 * stay in WireBooleans::COLUMNS because the conversion is right if a route ever appears;
	 * what is recorded here is that today nothing proves it.
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
		'uihelper_stock_journal.spoiled' => null,
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
			if ($shape === 'php' || $shape === 'unrouted')
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
	 * The two shapes CONVERSION_COVERAGE records as unproven are unproven because nothing
	 * can reach them, not because nobody wrote the test. Both halves are checked, so this
	 * fails the day a route makes one reachable and the coverage table has to grow.
	 */
	public function testTheUnprovenShapesAreStillUnreachable(): void
	{
		$spec = self::spec();
		$exposed = $spec['components']['schemas']['ExposedEntity']['enum'];

		// uihelper_stock_journal: not an exposed entity, so GET /objects/{entity} refuses
		// it, and the StockJournal schema its rows would answer is referenced by no path.
		self::assertNotContains('uihelper_stock_journal', $exposed);
		self::assertStringNotContainsString(
			'#/components/schemas/StockJournal"',
			json_encode($spec['paths'], JSON_THROW_ON_ERROR),
			'StockJournal is referenced by a path now, so uihelper_stock_journal.spoiled is provable'
		);
		self::assertSame(400, self::send('GET', '/api/objects/uihelper_stock_journal')['status']);

		// userfield_values_resolved: also not exposed, and the one route that reads the view
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
	 * consequences name: unmodelled entities whose rows happen to carry
	 * every property some member declares `required`, so exactly one member is a candidate
	 * - the wrong one - and nothing else in the union's shape rules it out. Required
	 * properties discriminate the ten from each other; they do not discriminate them from
	 * every relation in the database.
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
				$rowsByEntity[$entity] = $rows[0];

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

		// The second measurement, on the rows themselves. See UNION_FULLY_VALID.
		$fullyValid = [];
		foreach ($rowsByEntity as $entity => $row)
		{
			foreach ($members as $member)
			{
				$reason = self::validateAgainstMember($row, $member);

				if ($reason === null)
				{
					$fullyValid[$entity][] = $member;
					continue;
				}

				if (!in_array($member, $measured[$entity] ?? [], true))
				{
					continue;
				}

				// A candidate that does not validate: the reason has to be the nullability
				// gap this class records, not something unexplained.
				$property = self::UNION_NULLABILITY_FAILURES[$entity][$member] ?? null;
				self::assertNotNull($property, "$entity is a candidate for $member and fails it unrecorded: $reason");
				self::assertSame("$property: type", $reason, "$entity against $member");
				self::assertArrayHasKey($property, $row, "$entity carries no $property");
				self::assertNull($row[$property], "$entity.$property is not null, so 'type' is a different failure");
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
	 * Candidate pairings that a strict JSON Schema validator rejects, and the property each
	 * dies on - every one a NULL against a declared scalar. Six of the ten are the union's
	 * own intended pairings, which is why this is a gap in the members' nullability rather
	 * than a defence against the three unintended ones.
	 */
	private const UNION_NULLABILITY_FAILURES = [
		'products' => ['Product' => 'description'],
		'chores' => ['Chore' => 'description'],
		'locations' => ['Location' => 'description'],
		'quantity_units' => ['QuantityUnit' => 'description'],
		'shopping_list' => ['ShoppingListItem' => 'note'],
		'userfields' => ['Userfield' => 'config'],
		'stock' => ['StockEntry' => 'shopping_location_id'],
		'stock_log' => ['StockEntry' => 'shopping_location_id'],
		'product_barcodes_view' => ['ProductBarcode' => 'shopping_location_id'],
		'uihelper_shopping_list' => ['ShoppingListItem' => 'note']
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
