<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #574 (found validating PR #528/#530 for the #487 remediation, the same class of
 * defect as #545's stock entry price): mealplansectionform.blade.php and
 * userfieldform.blade.php both prefilled sort_number with
 * `!empty($x->sort_number)`, which is true for a stored 0 the same way it is for NULL.
 * A stored 0 therefore rendered blank, and saving that untouched form sent "" for
 * sort_number - a nullable INTEGER column on both meal_plan_sections and userfields
 * (db/pgsql/baseline/01_tables.sql) - which the generic entity endpoint
 * (GenericEntityApiController::EditObject(), routes.php's PUT /api/objects/{entity}/{id})
 * writes verbatim via LessQL's $row->update(). "" is not a valid integer literal, so
 * PostgreSQL refused the write and BaseApiController::WithoutDriverText() surfaced it as a
 * 400 "The database rejected this request...". A NULL sort_number rendered blank too - that
 * part was already correct - but saving *that* untouched form failed exactly the same way,
 * because neither view's shared form factory (public/js/victual_entity.js) had any
 * empty-string-to-null conversion for this field at all.
 *
 * Fixed at two layers: the view's prefill now checks `$x->sort_number !== null` instead of
 * `!empty($x->sort_number)` (so a stored 0 renders as "0"), and each form's viewjs file
 * (mealplansectionform.js, userfieldform.js) now supplies the shared factory's existing
 * `body` hook (Victual.EntityForm's documented `(jsonData, context) => body actually sent`
 * extension point) to turn an empty sort_number into `null` before the request is sent - the
 * same idiom locationform.js already uses for parent_location_id, storage_class_id and
 * tare_weight. Neither the shared factory itself nor the server's handling of "" for other
 * integer columns was touched.
 *
 * This class covers the view (the render assertions below) and the server accepting the
 * body the fixed JS sends (the PUT assertions). It cannot see the body() hook itself - PHP
 * never runs public/viewjs - so it cannot reproduce the "" -> 400 failure the hook exists to
 * prevent; that half is covered by the browser-driven
 * .devtools/frontend/nullable-integer-forms.js, run in CI's frontend-security job. See each
 * test method's own docblock for which half it covers.
 *
 * Tier 1 per ADR-0025. Every request goes through the real middleware stack - including
 * session authentication and User::CheckPermission() - via
 * tests/Pgsql/request-subprocess-helper.php, each in a process of its own (the
 * authentication middleware define()s the acting user's constants, and PHP cannot
 * redefine one), the way ViewCorrectionsHttpTest and StockEntryFormPriceTest already do.
 *
 * The PUT body each test sends is exactly what serializeJSON() plus the fixed body() hook
 * produce for an *unchanged* save of the fixture row - every field the form carries, not
 * only sort_number - derived by reading the view and the two viewjs files rather than
 * assumed.
 */
class SortNumberFormsHttpTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9750;

	private static PDO $db;
	private static string $sessionKey;

	private static int $mealplanSectionZeroId;
	private static int $mealplanSectionNullId;
	private static int $userfieldZeroId;
	private static int $userfieldNullId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec('INSERT INTO users(id, username, password) VALUES ('
			. self::USER_ID . ", 'sortnumberforms-caller', 'fixture')");
		// ADMIN rather than the two narrower leaves this class actually needs
		// (MEALPLAN_VIEW for the page, MASTER_DATA_EDIT for the generic entity write):
		// same simplification ViewCorrectionsHttpTest and StockEntryFormPriceTest make,
		// since this class is about sort_number, not about permission boundaries.
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT '
			. self::USER_ID . ", id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$sessionKey = bin2hex(random_bytes(25));
		self::$db->exec("INSERT INTO sessions(session_key, user_id, expires) VALUES ('"
			. self::$sessionKey . "', " . self::USER_ID . ", now() + interval '1 day')");

		self::$mealplanSectionZeroId = (int)self::$db->query(
			"INSERT INTO meal_plan_sections (name, sort_number, time_info) "
			. "VALUES ('WS574 mealplansection zero', 0, '08:00') RETURNING id"
		)->fetchColumn();

		self::$mealplanSectionNullId = (int)self::$db->query(
			"INSERT INTO meal_plan_sections (name, sort_number, time_info) "
			. "VALUES ('WS574 mealplansection null', NULL, '08:00') RETURNING id"
		)->fetchColumn();

		self::$userfieldZeroId = (int)self::$db->query(
			"INSERT INTO userfields (entity, name, caption, type, sort_number, show_as_column_in_tables, input_required) "
			. "VALUES ('products', 'ws574_userfield_zero', 'WS574 Zero Sort', 'text-single-line', 0, 0, 0) RETURNING id"
		)->fetchColumn();

		self::$userfieldNullId = (int)self::$db->query(
			"INSERT INTO userfields (entity, name, caption, type, sort_number, show_as_column_in_tables, input_required) "
			. "VALUES ('products', 'ws574_userfield_null', 'WS574 Null Sort', 'text-single-line', NULL, 0, 0) RETURNING id"
		)->fetchColumn();
	}

	/**
	 * One request through the whole middleware stack, in its own process - identical in
	 * shape to ViewCorrectionsHttpTest::send() and StockEntryFormPriceTest::request().
	 *
	 * @return array{status: int, body: string}
	 */
	private static function send(string $method, string $path, array $options = []): array
	{
		$spec = ['method' => $method, 'path' => $path];

		foreach (['headers', 'cookie', 'body'] as $key)
		{
			if (isset($options[$key]))
			{
				$spec[$key] = $options[$key];
			}
		}

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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * The defect half of issue #574: mealplansectionform.blade.php:44 rendered a stored 0
	 * the same as unset. Saving the rendered, unchanged form used to send sort_number: ""
	 * for this nullable INTEGER column, refused by the generic entity endpoint with 400.
	 */
	public function testMealPlanSectionFormRendersStoredZeroSortNumberAsZeroAndSavingUnchangedKeepsIt(): void
	{
		$get = self::send('GET', '/mealplansection/' . self::$mealplanSectionZeroId, ['cookie' => self::$sessionKey]);
		self::assertSame(200, $get['status'], $get['body']);

		self::assertMatchesRegularExpression(
			'/<input[^>]*id="sort_number"[^>]*value="0"/',
			$get['body'],
			'a stored sort_number of exactly 0 renders as "0" in the numberpicker, not blank'
		);

		// public/viewjs/mealplansectionform.js's body() hook: jsonData.sort_number is only
		// null when the rendered value was "" or undefined; the "0" this page renders parses
		// to the integer 0. name and time_info are unchanged from the fixture, matching what
		// serializeJSON() reads back from those two plain inputs.
		$put = self::send('PUT', '/api/objects/meal_plan_sections/' . self::$mealplanSectionZeroId, [
			'cookie' => self::$sessionKey,
			'body' => [
				'name' => 'WS574 mealplansection zero',
				'sort_number' => 0,
				'time_info' => '08:00',
			],
		]);
		self::assertSame(204, $put['status'], 'the unchanged save succeeds: ' . $put['body']);

		$stored = self::$db->query('SELECT sort_number FROM meal_plan_sections WHERE id = '
			. self::$mealplanSectionZeroId)->fetchColumn();
		self::assertNotNull($stored, 'the section keeps its explicit 0 sort_number - it must not become NULL');
		self::assertSame(0, (int)$stored, 'the section keeps sort_number at exactly 0');
	}

	/**
	 * Covers the render (a NULL sort_number renders blank) and the server accepting a
	 * correctly-typed null for the PUT. It does not reproduce the "" -> 400 failure the
	 * missing body() hook caused for this case: the PUT body below sends a real null, not
	 * the "" serializeJSON() reports for a blank field, because this class cannot run the
	 * browser code that makes that conversion. See the class docblock.
	 */
	public function testMealPlanSectionFormRendersNullSortNumberBlankAndSavingUnchangedKeepsItNull(): void
	{
		$get = self::send('GET', '/mealplansection/' . self::$mealplanSectionNullId, ['cookie' => self::$sessionKey]);
		self::assertSame(200, $get['status'], $get['body']);

		self::assertMatchesRegularExpression(
			'/<input[^>]*id="sort_number"[^>]*value=""/',
			$get['body'],
			'a NULL sort_number renders blank'
		);

		// body() hook: the rendered "" is exactly the case it maps to null.
		$put = self::send('PUT', '/api/objects/meal_plan_sections/' . self::$mealplanSectionNullId, [
			'cookie' => self::$sessionKey,
			'body' => [
				'name' => 'WS574 mealplansection null',
				'sort_number' => null,
				'time_info' => '08:00',
			],
		]);
		self::assertSame(204, $put['status'], 'the unchanged save succeeds: ' . $put['body']);

		$stored = self::$db->query('SELECT sort_number FROM meal_plan_sections WHERE id = '
			. self::$mealplanSectionNullId)->fetchColumn();
		self::assertNull($stored, 'the section keeps sort_number at NULL');
	}

	/**
	 * Same defect as the meal plan section form, for userfieldform.blade.php:84 and
	 * userfieldform.js, on the generic userfields entity instead.
	 */
	public function testUserfieldFormRendersStoredZeroSortNumberAsZeroAndSavingUnchangedKeepsIt(): void
	{
		$get = self::send('GET', '/userfield/' . self::$userfieldZeroId, ['cookie' => self::$sessionKey]);
		self::assertSame(200, $get['status'], $get['body']);

		self::assertMatchesRegularExpression(
			'/<input[^>]*id="sort_number"[^>]*value="0"/',
			$get['body'],
			'a stored sort_number of exactly 0 renders as "0" in the numberpicker, not blank'
		);

		// Every other field of the form, unchanged from the fixture: entity/type are
		// selects (serializeJSON reads back the selected option's value), config and
		// default_value are the two hidden-group fields serializeJSON still reads
		// regardless of the "d-none" class, and the two checkboxes read back
		// checkboxUncheckedValue ("0", public/js/victual.js) while unchecked.
		$put = self::send('PUT', '/api/objects/userfields/' . self::$userfieldZeroId, [
			'cookie' => self::$sessionKey,
			'body' => [
				'entity' => 'products',
				'name' => 'ws574_userfield_zero',
				'caption' => 'WS574 Zero Sort',
				'sort_number' => 0,
				'type' => 'text-single-line',
				'config' => '',
				'default_value' => '',
				'show_as_column_in_tables' => '0',
				'input_required' => '0',
			],
		]);
		self::assertSame(204, $put['status'], 'the unchanged save succeeds: ' . $put['body']);

		$stored = self::$db->query('SELECT sort_number FROM userfields WHERE id = '
			. self::$userfieldZeroId)->fetchColumn();
		self::assertNotNull($stored, 'the userfield keeps its explicit 0 sort_number - it must not become NULL');
		self::assertSame(0, (int)$stored, 'the userfield keeps sort_number at exactly 0');
	}

	/**
	 * NULL-fixture counterpart of the userfield test above: covers the render and the server
	 * accepting a correctly-typed null for the PUT, not the "" -> 400 failure - see
	 * testMealPlanSectionFormRendersNullSortNumberBlankAndSavingUnchangedKeepsItNull()'s
	 * docblock.
	 */
	public function testUserfieldFormRendersNullSortNumberBlankAndSavingUnchangedKeepsItNull(): void
	{
		$get = self::send('GET', '/userfield/' . self::$userfieldNullId, ['cookie' => self::$sessionKey]);
		self::assertSame(200, $get['status'], $get['body']);

		self::assertMatchesRegularExpression(
			'/<input[^>]*id="sort_number"[^>]*value=""/',
			$get['body'],
			'a NULL sort_number renders blank'
		);

		$put = self::send('PUT', '/api/objects/userfields/' . self::$userfieldNullId, [
			'cookie' => self::$sessionKey,
			'body' => [
				'entity' => 'products',
				'name' => 'ws574_userfield_null',
				'caption' => 'WS574 Null Sort',
				'sort_number' => null,
				'type' => 'text-single-line',
				'config' => '',
				'default_value' => '',
				'show_as_column_in_tables' => '0',
				'input_required' => '0',
			],
		]);
		self::assertSame(204, $put['status'], 'the unchanged save succeeds: ' . $put['body']);

		$stored = self::$db->query('SELECT sort_number FROM userfields WHERE id = '
			. self::$userfieldNullId)->fetchColumn();
		self::assertNull($stored, 'the userfield keeps sort_number at NULL');
	}
}
