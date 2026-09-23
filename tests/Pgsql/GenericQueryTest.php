<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Services\DatabaseService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The generic list query layer behind `query[]`, `limit`, `offset` and `order` on
 * GET /api/objects/{entity} - BaseApiController::QueryData(), FilterData(),
 * AssertFieldExists(), AssertCanValidate(), ColumnTypesOf() and
 * AssertWholeObjectReadable().
 *
 * This is a documented client-facing surface (docs/manual, victual.openapi.json) whose
 * refusals are load bearing rather than cosmetic, so almost every case here is paired:
 * the rows an operator returns AND a row it must not return, because "the filter matched
 * something" is not the same claim as "the filter matched the right something". Two
 * refusals carry security weight and are asserted as refusals rather than as statuses
 * that happen to be 4xx - the redacted-field filter hole (docs/plans/19-rbac.md piece 2:
 * without it a caller lacking STOCK_PRICES_VIEW could binary-search a price it may not
 * read) and the whole-object gate.
 *
 * What the existing `filter` phase (.devtools/pgsql/filterdifftest.php) already owns is
 * not repeated: that phase asks each *dialect* for the condition it emits for "~" and
 * "!~", runs it on its own engine and compares rows, which is hazard 16's cross-engine
 * half. It never enters a controller, so it cannot see which operators the controller
 * refuses before SQL is reached. The text-operator guard below is that other half.
 *
 * Controllers are called directly (RbacTest's pattern), so a refusal raised by
 * User::CheckPermission() or by an `HttpException` thrown inside QueryData() arrives as a
 * thrown exception rather than as a response - see expectStatus().
 *
 * Every fixture date is pinned. The timestamp column is one of the things filtered on
 * here, and a `row_created_timestamp` left to default would make the expected rows depend
 * on the calendar day of the run.
 */
class GenericQueryTest extends PgsqlSchemaTestCase
{
	/** Pinned, because row_created_timestamp is one of the columns filtered on below. */
	private const CREATED_AT = '2026-02-17 09:30:00';

	private static PDO $db;
	private static \DI\Container $container;
	private static GenericEntityApiController $generic;

	/** Fixture ids, filled by testCreatesFixtures() and read by every method after it. */
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$generic = new GenericEntityApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'genericquery-caller', 'fixture')");
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function request(array $query = [])
	{
		$uri = 'http://localhost/api' . (empty($query) ? '' : ('?' . http_build_query($query)));
		return (new ServerRequestFactory())->createServerRequest('GET', $uri);
	}

	/** A POST/DELETE request with a parsed JSON body, for the AddObject()/DeleteObject() cases below. */
	private static function requestWithBody(string $method, ?array $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		return $body === null ? $request : $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
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

	/** The leaves every method acts with unless it is about what a missing leaf refuses. */
	private static function grantReaders(): void
	{
		self::grant(['STOCK_VIEW', 'STOCK_PRICES_VIEW', 'TASKS_VIEW']);
	}

	/** GET /api/objects/{entity} with the given query parameters, decoded. */
	private static function listing(string $entity, array $query = []): array
	{
		$response = self::$generic->GetObjects(self::request($query), new Response(), ['entity' => $entity]);
		return json_decode((string)$response->getBody(), true);
	}

	/** The `name` of every row a products listing returned, in the order it returned them. */
	private static function names(array $rows): array
	{
		return array_map(fn ($row) => $row['name'], $rows);
	}

	/**
	 * Calls $work, recovering a thrown HttpException into its status and message, and
	 * asserts the status. Returns ['status' => int, 'message' => string].
	 */
	private function expectStatus(callable $work, int $expected, string $message): array
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
			$decoded = json_decode((string)$response->getBody(), true);
			$body = is_array($decoded) && array_key_exists('error_message', $decoded) ? $decoded['error_message'] : (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
			$body = $exception->getMessage();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual ($body)");
		return ['status' => $actual, 'message' => $body];
	}

	/**
	 * Asserts a products listing with the given query parameters returns exactly these
	 * names, and - the negative control every operator case here carries - that a name it
	 * must not return is absent.
	 */
	private function assertProductsAre(array $query, array $expected, array $mustBeAbsent, string $message): void
	{
		$names = self::names(self::listing('products', $query));
		sort($names);
		sort($expected);

		self::assertSame($expected, $names, $message);

		foreach ($mustBeAbsent as $absent)
		{
			self::assertNotContains($absent, $names, "$message: \"$absent\" must not be in the result");
		}
	}

	// ------------------------------------------------------------------------------
	// Fixture graph
	// ------------------------------------------------------------------------------

	/**
	 * Runs first, and has to: FieldPolicy caches permission_fields for the life of the
	 * process on its first read, so the whole-object row the gate test needs must be in
	 * the table before anything consults the policy.
	 */
	public function testCreatesFixtures(): void
	{
		self::grantReaders();

		// The policy is a table a household adds rows to (docs/plans/19-rbac.md piece 2,
		// Q2's response), and the one seeded '*' row names products_price_history, which is
		// not an exposed generic entity and so never reaches a generic list. A '*' row on an
		// entity that is listable is what makes the gate observable through this route.
		self::$db->exec("INSERT INTO permission_fields (permission_name, entity, field) VALUES ('STOCK_PRICES_VIEW', 'task_categories', '*')");

		self::$ids['kitchen'] = self::insertRow('locations', ['name' => 'Query Kitchen']);

		// Four products spanning what the operators have to tell apart: two share a
		// min_stock_amount so "=" and "!=" have more than one row to be right about, one
		// has a NULL description, and one carries the literal text "null" so the value
		// special case can be shown to be a special case of the *string* "null".
		self::$ids['alpha'] = self::insertProduct('Alpha Query Text', 1, 'From the alpha fixture');
		self::$ids['beta'] = self::insertProduct('Beta Query Text', 5, null);
		self::$ids['gamma'] = self::insertProduct('Gamma Query Text', 10, 'From the gamma fixture');
		self::$ids['delta'] = self::insertProduct('Delta Storage Item', 5, 'null');

		self::$ids['barcode_priced'] = self::insertRow('product_barcodes', [
			'product_id' => self::$ids['alpha'],
			'barcode' => 'QUERY-PRICED',
			'last_price' => 4.5,
		]);
		self::$ids['barcode_free'] = self::insertRow('product_barcodes', [
			'product_id' => self::$ids['beta'],
			'barcode' => 'QUERY-UNPRICED',
			'last_price' => 0.5,
		]);

		self::$ids['category'] = self::insertRow('task_categories', ['name' => 'Query Category']);

		self::$ids['corner_shop'] = self::insertRow('shopping_locations', ['name' => 'Query Corner Shop']);
		self::$ids['market'] = self::insertRow('shopping_locations', ['name' => 'Query Market']);

		self::assertCount(4, self::listing('products'), 'The fixture graph is the whole products table');
	}

	private static function insertProduct(string $name, float $minStock, ?string $description): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'description' => $description,
			'min_stock_amount' => $minStock,
			'location_id' => self::$ids['kitchen'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
			'row_created_timestamp' => self::CREATED_AT,
		]);
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
	// The filter operators - one case per documented operator, each with the row it
	// must exclude as well as the rows it must return.
	// ------------------------------------------------------------------------------

	public function testEqualsReturnsOnlyTheMatchingRows(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount=5']],
			['Beta Query Text', 'Delta Storage Item'],
			['Alpha Query Text', 'Gamma Query Text'],
			'"=" selects every row holding the value and no other'
		);
	}

	public function testNotEqualsIsTheComplementOfEquals(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount!=5']],
			['Alpha Query Text', 'Gamma Query Text'],
			['Beta Query Text', 'Delta Storage Item'],
			'"!=" returns exactly the rows "=" did not'
		);
	}

	public function testLessThanExcludesTheBoundaryValue(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount<5']],
			['Alpha Query Text'],
			['Beta Query Text'],
			'"<" is strict - the row holding exactly 5 is not below 5'
		);
	}

	public function testGreaterThanExcludesTheBoundaryValue(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount>5']],
			['Gamma Query Text'],
			['Beta Query Text'],
			'">" is strict - the row holding exactly 5 is not above 5'
		);
	}

	public function testLessThanOrEqualIncludesTheBoundaryValue(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount<=5']],
			['Alpha Query Text', 'Beta Query Text', 'Delta Storage Item'],
			['Gamma Query Text'],
			'"<=" differs from "<" by exactly the rows on the boundary'
		);
	}

	public function testGreaterThanOrEqualIncludesTheBoundaryValue(): void
	{
		$this->assertProductsAre(
			['query' => ['min_stock_amount>=5']],
			['Beta Query Text', 'Delta Storage Item', 'Gamma Query Text'],
			['Alpha Query Text'],
			'">=" differs from ">" by exactly the rows on the boundary'
		);
	}

	/**
	 * The API documents "~" as case insensitive on both engines - that is the whole
	 * reason the condition is asked of the dialect instead of being spelled LIKE in the
	 * controller (DatabaseDialect::GetLikeCondition, hazard 16). A lower case pattern
	 * matching a capitalised value is what that promise looks like from a client.
	 */
	public function testSubstringMatchIsCaseInsensitive(): void
	{
		$this->assertProductsAre(
			['query' => ['name~query']],
			['Alpha Query Text', 'Beta Query Text', 'Gamma Query Text'],
			['Delta Storage Item'],
			'"~" folds ASCII case, so a lower case pattern matches "Query"'
		);
	}

	/** Over the same fixture and the same pattern, "!~" has to be the complement of "~". */
	public function testNegatedSubstringMatchExcludesWhatTheSubstringMatchIncludes(): void
	{
		$this->assertProductsAre(
			['query' => ['name!~query']],
			['Delta Storage Item'],
			['Alpha Query Text', 'Beta Query Text', 'Gamma Query Text'],
			'"!~" returns exactly the rows "~" did not'
		);
	}

	/**
	 * "§" is a regular expression rather than a substring, so the case that proves it is
	 * one an anchor and an alternation decide - a substring match on the same value would
	 * return nothing at all.
	 */
	public function testRegexOperatorMatchesAnAnchoredAlternation(): void
	{
		$this->assertProductsAre(
			['query' => ['name§^(Alpha|Gamma)']],
			['Alpha Query Text', 'Gamma Query Text'],
			['Beta Query Text'],
			'"§" is a regular expression - "^" anchors and "|" alternates'
		);
	}

	public function testSeveralConditionsAreCombinedWithAnd(): void
	{
		$this->assertProductsAre(
			['query' => ['name~query', 'min_stock_amount>=5']],
			['Beta Query Text', 'Gamma Query Text'],
			['Alpha Query Text', 'Delta Storage Item'],
			'Every query[] entry narrows the result further'
		);
	}

	// ------------------------------------------------------------------------------
	// The "null" value special case
	// ------------------------------------------------------------------------------

	/**
	 * The documented special case: the value "null" additionally matches SQL NULL. It is
	 * a special case of the string, not a type - the row whose description is the literal
	 * text "null" matches through the ordinary comparison and the row whose description is
	 * SQL NULL matches through the added "OR ... IS NULL".
	 */
	public function testTheNullValueAlsoMatchesSqlNull(): void
	{
		$this->assertProductsAre(
			['query' => ['description=null']],
			['Beta Query Text', 'Delta Storage Item'],
			['Alpha Query Text', 'Gamma Query Text'],
			'"=null" matches the SQL NULL row and the literal "null" row'
		);
	}

	/** The negative control: without the special value, the NULL row is not returned. */
	public function testAFilterOnTheSameColumnWithAnotherValueExcludesTheNullRow(): void
	{
		$this->assertProductsAre(
			['query' => ['description~fixture']],
			['Alpha Query Text', 'Gamma Query Text'],
			['Beta Query Text'],
			'A NULL description is only ever returned by the "null" special case'
		);
	}

	/**
	 * The special case is documented for the value rather than for one operator, and "!="
	 * is where that is worth pinning: in plain SQL "description != \'null\'" drops the
	 * NULL row, so the added "OR ... IS NULL" is the only reason it comes back.
	 */
	public function testTheNullValueAppliesToOperatorsOtherThanEquals(): void
	{
		$this->assertProductsAre(
			['query' => ['description!=null']],
			['Alpha Query Text', 'Beta Query Text', 'Gamma Query Text'],
			['Delta Storage Item'],
			'"!=null" excludes the literal "null" row and still returns the SQL NULL one'
		);
	}

	// ------------------------------------------------------------------------------
	// AssertFieldExists(): the two refusals, and whether a caller can tell them apart
	// ------------------------------------------------------------------------------

	public function testAnUnknownFieldIsRefused(): void
	{
		$refusal = $this->expectStatus(
			fn () => self::listing('products', ['query' => ['no_such_column=1']]),
			400,
			'A field the entity does not have is the caller\'s mistake, not a 500'
		);

		self::assertStringNotContainsString('SQLSTATE', $refusal['message'],
			'The refusal happens before SQL, so no driver text can be in it');
	}

	/**
	 * docs/plans/19-rbac.md piece 2's "filter hole". product_barcodes.last_price is
	 * redacted from the response for a caller without STOCK_PRICES_VIEW; without this
	 * refusal that caller could still recover it with "?query[]=last_price>3" and
	 * "?query[]=last_price<5" from which rows come back.
	 */
	public function testAFieldRedactedForTheCallerMayNotBeFilteredOn(): void
	{
		self::grant(['STOCK_VIEW', 'TASKS_VIEW']);

		try
		{
			$readable = self::listing('product_barcodes');
			self::assertArrayNotHasKey('last_price', $readable[0],
				'Without STOCK_PRICES_VIEW the field is not in the response at all');

			$this->expectStatus(
				fn () => self::listing('product_barcodes', ['query' => ['last_price>1']]),
				400,
				'Filtering on a redacted field must be refused, or the redaction is searchable'
			);

			$this->expectStatus(
				fn () => self::listing('product_barcodes', ['order' => 'last_price']),
				400,
				'Ordering on a redacted field leaks it just as filtering on it does'
			);
		}
		finally
		{
			self::grantReaders();
		}
	}

	/** The negative control: with the leaf, the same filter is an ordinary read. */
	public function testTheSameFilterIsServedToACallerHoldingTheLeaf(): void
	{
		self::grantReaders();

		$rows = self::listing('product_barcodes', ['query' => ['last_price>1']]);

		self::assertCount(1, $rows, 'Only the 4.50 barcode is above 1');
		self::assertSame('QUERY-PRICED', $rows[0]['barcode']);
		self::assertEquals(4.5, $rows[0]['last_price'], 'The field is readable, so it is on the wire');
	}

	/**
	 * "last_price" is the same field name in both calls below - unknown to "products"
	 * (which has no such column) and redacted on "product_barcodes" (real, but withheld
	 * from a caller without STOCK_PRICES_VIEW) - so an identical body is not merely two
	 * sentences of the same shape with different input reflected back: it is the literal
	 * same string, which is what a distinct status was already withheld to avoid confirming
	 * (issue #255).
	 */
	public function testTheTwoFieldRefusalsShareAStatusAndAMessage(): void
	{
		self::grant(['STOCK_VIEW', 'TASKS_VIEW']);

		try
		{
			$unknown = $this->expectStatus(
				fn () => self::listing('products', ['query' => ['last_price>1']]),
				400,
				'"last_price" is not a column of "products" at all'
			);
			$redacted = $this->expectStatus(
				fn () => self::listing('product_barcodes', ['query' => ['last_price>1']]),
				400,
				'"last_price" is a real column of "product_barcodes", redacted for this caller'
			);

			self::assertSame($unknown['status'], $redacted['status'],
				'The status must not tell a caller which of the two refusals they hit');
			self::assertSame($unknown['message'], $redacted['message'],
				'Nor must the body - a distinct sentence would confirm the field exists exactly as a distinct status would');
		}
		finally
		{
			self::grantReaders();
		}
	}

	// ------------------------------------------------------------------------------
	// The text-operator guard: "~", "!~" and "§" need a text column, on both engines
	// ------------------------------------------------------------------------------

	/**
	 * Hazard 16's other half. The `filter` phase compares what each dialect's "~" returns
	 * on a text column; it never asks what happens when the column is not text, because it
	 * never goes through a controller. Left to the engines the two disagree - SQLite
	 * coerces and matches, PostgreSQL has no such operator for the type and raises - so the
	 * controller refuses on both rather than letting either answer stand.
	 */
	public function testSubstringMatchOnANumericColumnIsRefused(): void
	{
		$refusal = $this->expectStatus(
			fn () => self::listing('products', ['query' => ['min_stock_amount~5']]),
			400,
			'"~" needs a text column'
		);

		self::assertStringContainsString('double precision', $refusal['message'],
			'The refusal says which type the column actually is, so the caller can fix the request');
	}

	public function testNegatedSubstringMatchOnANumericColumnIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['query' => ['min_stock_amount!~5']]),
			400,
			'"!~" is refused on the same columns as "~"'
		);
	}

	public function testRegexMatchOnANumericColumnIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['query' => ['min_stock_amount§^1']]),
			400,
			'"§" is refused on the same columns as "~"'
		);
	}

	/**
	 * The timestamp case is the one DatabaseDialect::IsTextMatchableType() is explicit
	 * about: SQLite stores a timestamp as text and would happily match "2026-02" against
	 * one, PostgreSQL cannot, and rendering one to text so both could is a feature nobody
	 * has designed (which format, which zone, which precision). Until then the API does
	 * not offer substring matching on a timestamp on either engine - so a pattern that
	 * would match the pinned fixture value if the column were text must still be refused.
	 */
	public function testSubstringMatchOnATimestampColumnIsRefused(): void
	{
		$refusal = $this->expectStatus(
			fn () => self::listing('products', ['query' => ['row_created_timestamp~2026-02']]),
			400,
			'A timestamp is not substring matchable, even though every fixture row would match'
		);

		self::assertStringContainsString('timestamp', $refusal['message']);
	}

	/** The negative control: the same operator on a text column is served. */
	public function testTheGuardDoesNotRefuseATextColumn(): void
	{
		$rows = self::listing('products', ['query' => ['description~alpha']]);

		self::assertSame(['Alpha Query Text'], self::names($rows),
			'description is text, so "~" is allowed on it');
	}

	/** A comparison operator on a timestamp is unaffected - only the text operators are guarded. */
	public function testComparisonOperatorsStillWorkOnATimestampColumn(): void
	{
		$this->assertProductsAre(
			['query' => ['row_created_timestamp>=2026-02-17']],
			['Alpha Query Text', 'Beta Query Text', 'Gamma Query Text', 'Delta Storage Item'],
			[],
			'">=" on a timestamp is not a text operator and is not refused'
		);

		$this->assertProductsAre(
			['query' => ['row_created_timestamp>2026-02-18']],
			[],
			['Alpha Query Text'],
			'The same comparison past the pinned fixture timestamp returns nothing'
		);
	}

	// ------------------------------------------------------------------------------
	// Malformed conditions
	// ------------------------------------------------------------------------------

	public function testAConditionWithNoOperatorIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['query' => ['name']]),
			400,
			'A condition that is not "<field><operator><value>" is refused'
		);
	}

	public function testAConditionWithNoValueIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['query' => ['name=']]),
			400,
			'An operator with nothing after it is refused'
		);
	}

	// ------------------------------------------------------------------------------
	// order
	// ------------------------------------------------------------------------------

	public function testABareFieldOrdersAscending(): void
	{
		self::assertSame(
			['Alpha Query Text', 'Beta Query Text', 'Delta Storage Item', 'Gamma Query Text'],
			self::names(self::listing('products', ['order' => 'name'])),
			'"order=name" sorts ascending'
		);
	}

	public function testAnExplicitAscendingOrderMatchesTheBareField(): void
	{
		self::assertSame(
			self::names(self::listing('products', ['order' => 'name'])),
			self::names(self::listing('products', ['order' => 'name:asc'])),
			'"name:asc" is the bare field spelled out'
		);
	}

	public function testDescendingOrderReversesTheResult(): void
	{
		self::assertSame(
			array_reverse(self::names(self::listing('products', ['order' => 'name:asc']))),
			self::names(self::listing('products', ['order' => 'name:desc'])),
			'"name:desc" is "name:asc" reversed'
		);
	}

	public function testAnInvalidSortDirectionIsRefused(): void
	{
		$refusal = $this->expectStatus(
			fn () => self::listing('products', ['order' => 'name:sideways']),
			400,
			'Only asc and desc are sort orders'
		);

		self::assertStringContainsString('sideways', $refusal['message'],
			'The refusal names the direction that was not understood');
	}

	public function testOrderingByAnUnknownFieldIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['order' => 'no_such_column']),
			400,
			'"order" is validated against the same column catalogue as "query"'
		);
	}

	public function testOrderingByAnUnknownFieldWithADirectionIsRefused(): void
	{
		$this->expectStatus(
			fn () => self::listing('products', ['order' => 'no_such_column:desc']),
			400,
			'The field is validated before the direction is looked at'
		);
	}

	// ------------------------------------------------------------------------------
	// limit / offset
	// ------------------------------------------------------------------------------

	public function testLimitAndOffsetSelectAPage(): void
	{
		self::assertSame(
			['Beta Query Text', 'Delta Storage Item'],
			self::names(self::listing('products', ['order' => 'name', 'limit' => 2, 'offset' => 1])),
			'limit/offset page through the ordered result'
		);
	}

	public function testLimitWithoutOffsetStartsAtTheBeginning(): void
	{
		self::assertSame(
			['Alpha Query Text'],
			self::names(self::listing('products', ['order' => 'name', 'limit' => 1])),
			'A limit with no offset is the first page'
		);
	}

	public function testLimitZeroReturnsNoRows(): void
	{
		self::assertSame([], self::listing('products', ['limit' => 0]),
			'A limit of zero is a request for no rows, not a request for all of them');
	}

	public function testAnOffsetPastTheEndReturnsNoRows(): void
	{
		self::assertSame([], self::listing('products', ['order' => 'name', 'limit' => 10, 'offset' => 99]),
			'Paging past the last row is an empty page, not an error');
	}

	/**
	 * A non-numeric limit goes through intval(), which makes it 0 - so the request is
	 * answered with an empty list rather than refused. Recorded because it is what a client
	 * sending "limit=all" gets, and because it is the boundary the previous case shares.
	 */
	public function testANonNumericLimitIsTreatedAsZero(): void
	{
		self::assertSame([], self::listing('products', ['limit' => 'all']),
			'A limit that is not a number is not refused, it is read as 0');
	}

	/**
	 * "offset" without "limit" used to default the limit to -1, which is SQLite's spelling
	 * of "no limit" and a hard error in PostgreSQL ("LIMIT must not be negative") - issue
	 * #254. QueryData() (BaseApiController.php) now leaves the LIMIT clause out entirely
	 * when no limit was asked for, and MaterialiseFiltered() applies the offset itself with
	 * array_slice() once the unlimited statement has run, so a documented parameter used on
	 * its own is an ordinary page rather than a 500.
	 */
	public function testAnOffsetWithoutALimitReturnsEveryRowAfterIt(): void
	{
		self::assertSame(
			['Beta Query Text', 'Delta Storage Item', 'Gamma Query Text'],
			self::names(self::listing('products', ['order' => 'name', 'offset' => 1])),
			'An offset with no limit is every row after it, not a 500'
		);
	}

	// ------------------------------------------------------------------------------
	// MaterialiseFiltered(): a database rejection of a caller supplied term
	// ------------------------------------------------------------------------------

	/**
	 * The field exists and the operator suits a column of that type, so nothing the
	 * controller validates can catch "abc" not being a number - the engine is the first
	 * thing to notice. Because the failure is provably downstream of a query parameter it
	 * becomes a 400, and it deliberately does not carry the driver's message: that names
	 * types, columns and the engine, which is more than a caller needs and more than this
	 * API says about itself anywhere else. The same property SchemaVersionMiddleware has,
	 * asserted for it by AuthStackTest::testTheDriverMessageIsOnlyInTheBodyInDevMode.
	 */
	public function testADatabaseRejectionOfAFilterValueIsA400WithoutTheDriverMessage(): void
	{
		$refusal = $this->expectStatus(
			fn () => self::listing('products', ['query' => ['min_stock_amount>abc']]),
			400,
			'A value the column\'s type cannot hold is the caller\'s mistake'
		);

		self::assertStringNotContainsString('SQLSTATE', $refusal['message'],
			'The driver\'s own words must not reach the caller');
		self::assertStringNotContainsString('double precision', $refusal['message'],
			'Nor the column type it named');
		self::assertStringContainsString('Invalid query', $refusal['message']);
	}

	/**
	 * The other half of MaterialiseFiltered()'s catch block: a caller who supplied neither
	 * "query[]" nor "order" gets the raw PDOException rethrown rather than the 400 above,
	 * because nothing they sent shaped the statement that failed - see the method's own
	 * docblock. Before issue #254 was fixed, "offset" without "limit" was the only thing in
	 * this suite that reached that arm (it built an invalid LIMIT the caller never asked
	 * for); now that path no longer fails, so this reaches the same arm a different way -
	 * the aborted-transaction idiom testAFilteredListIsRefusedWith500WhenTheColumnCatalogueCannotBeRead()
	 * below uses, but with a request that carries no "query" or "order" at all, so QueryData()
	 * never touches the column catalogue and the only statement that runs is
	 * MaterialiseFiltered()'s own fetchAll().
	 */
	public function testAnUnfilteredListRethrowsAPdoExceptionRatherThanWrappingIt(): void
	{
		self::grantReaders();

		// One ordinary read first, so the fixtures are known-good before the connection is
		// broken.
		self::assertCount(4, self::listing('products'));

		self::$db->beginTransaction();

		try
		{
			self::$db->exec('SELECT 1 FROM a_table_that_does_not_exist');
			self::fail('The probe statement was supposed to fail');
		}
		catch (\PDOException $expected)
		{
			// The connection is now in the aborted state this case is about.
		}

		try
		{
			$this->expectException(\PDOException::class);
			self::listing('products');
		}
		finally
		{
			self::$db->rollBack();
		}
	}

	// ------------------------------------------------------------------------------
	// AssertWholeObjectReadable(): the entity level gate
	// ------------------------------------------------------------------------------

	public function testAnEntityWithAWholeObjectGateIsRefusedWithoutThePermission(): void
	{
		self::grant(['STOCK_VIEW', 'TASKS_VIEW']);

		try
		{
			$this->expectStatus(
				fn () => self::listing('task_categories'),
				403,
				'A "*" policy row means the whole endpoint is the field - refuse, do not empty the objects'
			);

			// The negative control: the gate is per entity, not a blanket refusal. The same
			// caller, holding the same leaves, still reads an entity with no "*" row.
			self::assertNotEmpty(self::listing('products'),
				'An entity without a whole-object row is unaffected by one on another entity');
		}
		finally
		{
			self::grantReaders();
		}
	}

	public function testTheSameEntityIsServedToACallerHoldingThePermission(): void
	{
		self::grantReaders();

		$rows = self::listing('task_categories');

		self::assertSame(['Query Category'], array_map(fn ($row) => $row['name'], $rows),
			'With the permission the gate names, the listing is ordinary');
	}

	// ------------------------------------------------------------------------------
	// AssertCanValidate(): the catalogue cannot be read
	// ------------------------------------------------------------------------------

	/**
	 * "500 and not 400: the caller has done nothing wrong, the server cannot do its job."
	 *
	 * The catalogue is made genuinely unreadable rather than stubbed: an aborted
	 * transaction on the request's connection is a state PostgreSQL really puts a
	 * connection into, and in it every statement - the information_schema read
	 * ColumnTypesOf() issues included - fails until the transaction ends. FilteredApiResponse()
	 * is called directly so that the only statement attempted is the catalogue read.
	 *
	 * shopping_locations is used here and nowhere else in this class: ColumnTypesOf()
	 * caches the unreadable answer for the rest of the process, which is the "for this
	 * request only" scope in a test that is one process.
	 */
	public function testAFilteredListIsRefusedWith500WhenTheColumnCatalogueCannotBeRead(): void
	{
		self::grantReaders();

		// One ordinary read first, so that everything this path consults *besides* the
		// column catalogue - the permission rows, FieldPolicy's own per-request row cache -
		// has already been read. What the broken connection below then reaches is the
		// catalogue read and nothing else.
		self::assertCount(2, self::listing('shopping_locations'));

		// LessQL builds the statement lazily, so taking the Result before the connection is
		// broken costs nothing and asks nothing of the database.
		$result = DatabaseService::GetInstance()->GetDbConnection()->shopping_locations();

		self::$db->beginTransaction();

		try
		{
			self::$db->exec('SELECT 1 FROM a_table_that_does_not_exist');
			self::fail('The probe statement was supposed to fail');
		}
		catch (\PDOException $expected)
		{
			// The connection is now in the aborted state this case is about.
		}

		try
		{
			$refusal = $this->expectStatus(
				fn () => self::$generic->FilteredApiResponse(self::request(), new Response(), $result, ['order' => 'name']),
				500,
				'A sort that cannot be validated is refused rather than run unvalidated'
			);

			self::assertStringContainsString('Cannot validate', $refusal['message']);
			self::assertStringNotContainsString('SQLSTATE', $refusal['message'],
				'The driver text belongs in the log this path writes, not in the response');
		}
		finally
		{
			self::$db->rollBack();
		}
	}

	/**
	 * The other half of the same docblock: "an unfiltered list has nothing to check and is
	 * served normally whatever the catalogue is doing". Runs after the case above, with
	 * ColumnTypesOf()'s cached "unreadable" still in place for this entity, so it is the
	 * real state rather than a fresh one.
	 */
	public function testAnUnfilteredListIsServedWhileTheCatalogueIsStillUnreadable(): void
	{
		self::grantReaders();

		$result = DatabaseService::GetInstance()->GetDbConnection()->shopping_locations();
		$response = self::$generic->FilteredApiResponse(self::request(), new Response(), $result, []);
		$names = array_map(fn ($row) => $row['name'], json_decode((string)$response->getBody(), true));
		sort($names);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame(['Query Corner Shop', 'Query Market'], $names,
			'Nothing needed validating, so the unreadable catalogue never came up');

		// And the refusal is still there for a request that does need validating, which is
		// what makes the previous assertion a statement about the two paths rather than
		// about the connection having recovered.
		$this->expectStatus(
			fn () => self::$generic->FilteredApiResponse(self::request(), new Response(), $result, ['query' => ['name~Market']]),
			500,
			'A filter on the same entity is still refused'
		);
	}

	// ------------------------------------------------------------------------------
	// GenericEntityApiController::AddObject()/DeleteObject() - the write half of the same
	// controller this phase otherwise exercises only through its read/list path
	// ------------------------------------------------------------------------------

	/**
	 * AddObject() (GenericEntityApiController.php:87-94) refuses a POST whose body sets no
	 * column of the entity - here, a body naming only "id", which WithoutServerOwnedColumns()
	 * strips before the emptiness check, along with "row_created_timestamp" and
	 * "import_epoch" (see issue #47, its own docblock above). Without this refusal LessQL
	 * silently skips an insert with no modified columns, and the endpoint used to answer 200
	 * with whatever id the driver happened to report for an insert that never ran.
	 */
	public function testAddObjectRefusesABodyThatSetsNoColumn(): void
	{
		self::grant(['MASTER_DATA_EDIT']);

		try
		{
			$refusal = $this->expectStatus(
				fn () => self::$generic->AddObject(
					self::requestWithBody('POST', ['id' => 999999]),
					new Response(),
					['entity' => 'task_categories']
				),
				400,
				'A body that sets only a server-owned column leaves nothing to create'
			);

			self::assertStringContainsString('nothing to create', $refusal['message']);
		}
		finally
		{
			self::grantReaders();
		}
	}

	/**
	 * DeleteObject() (GenericEntityApiController.php:180-184) answers 404 for an id that
	 * does not exist, before any of the entity-specific checks below it (children, ownership)
	 * that only make sense once a row was actually found.
	 */
	public function testDeleteObjectAnswersNotFoundForAMissingId(): void
	{
		self::grant(['MASTER_DATA_EDIT']);

		try
		{
			$refusal = $this->expectStatus(
				fn () => self::$generic->DeleteObject(
					self::requestWithBody('DELETE'),
					new Response(),
					['entity' => 'task_categories', 'objectId' => 999999]
				),
				404,
				'Deleting an id that does not exist is a 404, not a silent no-op'
			);

			self::assertSame('Object not found', $refusal['message']);
		}
		finally
		{
			self::grantReaders();
		}
	}
}
