<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #892 (maintainer decision O1, 2026-10-13): victual.openapi.json declares
 * `type: number` or `type: integer` for request fields the controllers read with a PHP
 * numeric-string test, so a string such as "2.5" or "1e3" is accepted although the schema
 * names no string. This class pins, per field, what each route does with a fixed set of
 * JSON number and JSON string inputs - status and the value left in the database - so the
 * document can state the grammar each field really accepts. No field is assumed to share a
 * rule with another: each row of FIELDS is driven separately.
 *
 * Every case runs at HTTP level through tests/Pgsql/request-subprocess-helper.php with a
 * hand-written raw JSON body, because json_encode() cannot produce 1e309 or a 400-digit
 * number and would normalise the spelling of the rest.
 *
 * EXPECTED holds one "status|guard|observed" cell per field and input. "guard" is 1 when
 * the request got past the field's own numeric test (a refusal whose message is the guard's
 * "must be a number" answer is 0), "observed" is what the database holds afterwards. The
 * pattern loop at the end compares the string inputs the guard passed with the pattern the
 * document gives the field, so the document and the runtime cannot drift apart unnoticed.
 */
class NumericStringAcceptanceTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9892;

	/**
	 * PHP's whitespace class for numeric strings is exactly these six bytes. \s is not used
	 * anywhere in the document's patterns because ECMA 262 \s also matches U+00A0 and the
	 * Unicode spaces, which is_numeric() refuses.
	 */
	private const NUMERIC_PATTERN = '^[ \t\n\r\x0B\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\x0B\f]*$';

	/** @var array<string, array<string, string>> */
	private const EXPECTED = [
		'add.amount' => [
			'2' => '200|1|rows=1 sum=2 price=null',
			'-2' => '400|1|rows=0 sum=- price=-',
			'2.5' => '200|1|rows=1 sum=2.5 price=null',
			'1e3' => '200|1|rows=1 sum=1000 price=null',
			'1E3' => '200|1|rows=1 sum=1000 price=null',
			'1e-3' => '200|1|rows=1 sum=0.001 price=null',
			'1e309' => '400|0|rows=0 sum=- price=-',
			'-0' => '400|1|rows=0 sum=- price=-',
			'0' => '400|1|rows=0 sum=- price=-',
			'400 digits (number)' => '400|0|rows=0 sum=- price=-',
			'"2"' => '200|1|rows=1 sum=2 price=null',
			'"-2"' => '400|1|rows=0 sum=- price=-',
			'"+2"' => '200|1|rows=1 sum=2 price=null',
			'"2.5"' => '200|1|rows=1 sum=2.5 price=null',
			'".5"' => '200|1|rows=1 sum=0.5 price=null',
			'"5."' => '200|1|rows=1 sum=5 price=null',
			'"1e3"' => '200|1|rows=1 sum=1000 price=null',
			'"1E3"' => '200|1|rows=1 sum=1000 price=null',
			'"1e-3"' => '200|1|rows=1 sum=0.001 price=null',
			'" 2"' => '200|1|rows=1 sum=2 price=null',
			'"2 "' => '200|1|rows=1 sum=2 price=null',
			'"\\t2"' => '200|1|rows=1 sum=2 price=null',
			'"\\n2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u00a02"' => '400|0|rows=0 sum=- price=-',
			'"2\\u00a0"' => '400|0|rows=0 sum=- price=-',
			'"0x1A"' => '400|0|rows=0 sum=- price=-',
			'"1_000"' => '400|0|rows=0 sum=- price=-',
			'""' => '400|0|rows=0 sum=- price=-',
			'"abc"' => '400|0|rows=0 sum=- price=-',
			'"2abc"' => '400|0|rows=0 sum=- price=-',
			'"NaN"' => '400|0|rows=0 sum=- price=-',
			'"INF"' => '400|0|rows=0 sum=- price=-',
			'"1e309"' => '400|1|rows=0 sum=- price=-',
			'"-0"' => '400|1|rows=0 sum=- price=-',
			'"0"' => '400|1|rows=0 sum=- price=-',
			'"400 digits"' => '400|1|rows=0 sum=- price=-',
			'"\\r2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u000b2"' => '200|1|rows=1 sum=2 price=null',
			'"\\f2"' => '200|1|rows=1 sum=2 price=null',
			'"1\\u00002"' => '400|0|rows=0 sum=- price=-',
			'"2\\u0001"' => '400|0|rows=0 sum=- price=-',
			'"&#50;"' => '400|0|rows=0 sum=- price=-',
			'"<b>2<\\/b>"' => '400|0|rows=0 sum=- price=-',
			'"\\uff12"' => '400|0|rows=0 sum=- price=-',
			'"1\\u000b2"' => '400|0|rows=0 sum=- price=-',
			'"1\\f2"' => '400|0|rows=0 sum=- price=-',
			'"1\\u00012"' => '400|0|rows=0 sum=- price=-',
			'"1<x>2"' => '400|0|rows=0 sum=- price=-',
			'"1<!-- c -->2"' => '400|0|rows=0 sum=- price=-',
			'"&#49;&#50;"' => '400|0|rows=0 sum=- price=-',
			'"02"' => '200|1|rows=1 sum=2 price=null',
			'"2.0"' => '200|1|rows=1 sum=2 price=null',
			'"2e0"' => '200|1|rows=1 sum=2 price=null',
		],
		'add.price' => [
			'2' => '200|1|rows=1 sum=1 price=2',
			'-2' => '200|1|rows=1 sum=1 price=-2',
			'2.5' => '200|1|rows=1 sum=1 price=2.5',
			'1e3' => '200|1|rows=1 sum=1 price=1000',
			'1E3' => '200|1|rows=1 sum=1 price=1000',
			'1e-3' => '200|1|rows=1 sum=1 price=0.001',
			'1e309' => '400|0|rows=0 sum=- price=-',
			'-0' => '200|1|rows=1 sum=1 price=0',
			'0' => '200|1|rows=1 sum=1 price=0',
			'400 digits (number)' => '400|0|rows=0 sum=- price=-',
			'"2"' => '200|1|rows=1 sum=1 price=2',
			'"-2"' => '200|1|rows=1 sum=1 price=-2',
			'"+2"' => '200|1|rows=1 sum=1 price=2',
			'"2.5"' => '200|1|rows=1 sum=1 price=2.5',
			'".5"' => '200|1|rows=1 sum=1 price=0.5',
			'"5."' => '200|1|rows=1 sum=1 price=5',
			'"1e3"' => '200|1|rows=1 sum=1 price=1000',
			'"1E3"' => '200|1|rows=1 sum=1 price=1000',
			'"1e-3"' => '200|1|rows=1 sum=1 price=0.001',
			'" 2"' => '200|1|rows=1 sum=1 price=2',
			'"2 "' => '200|1|rows=1 sum=1 price=2',
			'"\\t2"' => '200|1|rows=1 sum=1 price=2',
			'"\\n2"' => '200|1|rows=1 sum=1 price=2',
			'"\\u00a02"' => '400|0|rows=0 sum=- price=-',
			'"2\\u00a0"' => '400|0|rows=0 sum=- price=-',
			'"0x1A"' => '400|0|rows=0 sum=- price=-',
			'"1_000"' => '400|0|rows=0 sum=- price=-',
			'""' => '200|1|rows=1 sum=1 price=null',
			'"abc"' => '400|0|rows=0 sum=- price=-',
			'"2abc"' => '400|0|rows=0 sum=- price=-',
			'"NaN"' => '400|0|rows=0 sum=- price=-',
			'"INF"' => '400|0|rows=0 sum=- price=-',
			'"1e309"' => '400|1|rows=0 sum=- price=-',
			'"-0"' => '200|1|rows=1 sum=1 price=-0',
			'"0"' => '200|1|rows=1 sum=1 price=0',
			'"400 digits"' => '400|1|rows=0 sum=- price=-',
			'"\\r2"' => '200|1|rows=1 sum=1 price=2',
			'"\\u000b2"' => '200|1|rows=1 sum=1 price=2',
			'"\\f2"' => '200|1|rows=1 sum=1 price=2',
			'"1\\u00002"' => '400|0|rows=0 sum=- price=-',
			'"2\\u0001"' => '400|0|rows=0 sum=- price=-',
			'"&#50;"' => '400|0|rows=0 sum=- price=-',
			'"<b>2<\\/b>"' => '400|0|rows=0 sum=- price=-',
			'"\\uff12"' => '400|0|rows=0 sum=- price=-',
			'"1\\u000b2"' => '400|0|rows=0 sum=- price=-',
			'"1\\f2"' => '400|0|rows=0 sum=- price=-',
			'"1\\u00012"' => '400|0|rows=0 sum=- price=-',
			'"1<x>2"' => '400|0|rows=0 sum=- price=-',
			'"1<!-- c -->2"' => '400|0|rows=0 sum=- price=-',
			'"&#49;&#50;"' => '400|0|rows=0 sum=- price=-',
			'"02"' => '200|1|rows=1 sum=1 price=2',
			'"2.0"' => '200|1|rows=1 sum=1 price=2',
			'"2e0"' => '200|1|rows=1 sum=1 price=2',
			'null' => '200|1|rows=1 sum=1 price=null',
		],
		'add.stock_label_type' => [
			'2' => '200|1|rows=2 sum=2 price=null,null',
			'-2' => '200|1|rows=1 sum=2 price=null',
			'2.5' => '200|1|rows=1 sum=2 price=null',
			'1e3' => '200|1|rows=1 sum=2 price=null',
			'1E3' => '200|1|rows=1 sum=2 price=null',
			'1e-3' => '200|1|rows=1 sum=2 price=null',
			'1e309' => '200|1|rows=1 sum=2 price=null',
			'-0' => '200|1|rows=1 sum=2 price=null',
			'0' => '200|1|rows=1 sum=2 price=null',
			'400 digits (number)' => '200|1|rows=1 sum=2 price=null',
			'"2"' => '200|1|rows=2 sum=2 price=null,null',
			'"-2"' => '200|1|rows=1 sum=2 price=null',
			'"+2"' => '200|1|rows=2 sum=2 price=null,null',
			'"2.5"' => '200|1|rows=1 sum=2 price=null',
			'".5"' => '200|1|rows=1 sum=2 price=null',
			'"5."' => '200|1|rows=1 sum=2 price=null',
			'"1e3"' => '200|1|rows=1 sum=2 price=null',
			'"1E3"' => '200|1|rows=1 sum=2 price=null',
			'"1e-3"' => '200|1|rows=1 sum=2 price=null',
			'" 2"' => '200|1|rows=2 sum=2 price=null,null',
			'"2 "' => '200|1|rows=2 sum=2 price=null,null',
			'"\\t2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\n2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\u00a02"' => '400|1|rows=0 sum=- price=-',
			'"2\\u00a0"' => '400|1|rows=0 sum=- price=-',
			'"0x1A"' => '400|1|rows=0 sum=- price=-',
			'"1_000"' => '400|1|rows=0 sum=- price=-',
			'""' => '200|1|rows=1 sum=2 price=null',
			'"abc"' => '400|1|rows=0 sum=- price=-',
			'"2abc"' => '400|1|rows=0 sum=- price=-',
			'"NaN"' => '400|1|rows=0 sum=- price=-',
			'"INF"' => '400|1|rows=0 sum=- price=-',
			'"1e309"' => '200|1|rows=1 sum=2 price=null',
			'"-0"' => '200|1|rows=1 sum=2 price=null',
			'"0"' => '200|1|rows=1 sum=2 price=null',
			'"400 digits"' => '200|1|rows=1 sum=2 price=null',
			'"\\r2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\u000b2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\f2"' => '200|1|rows=2 sum=2 price=null,null',
			'"1\\u00002"' => '400|1|rows=0 sum=- price=-',
			'"2\\u0001"' => '400|1|rows=0 sum=- price=-',
			'"&#50;"' => '400|1|rows=0 sum=- price=-',
			'"<b>2<\\/b>"' => '400|1|rows=0 sum=- price=-',
			'"\\uff12"' => '400|1|rows=0 sum=- price=-',
			'"1\\u000b2"' => '400|1|rows=0 sum=- price=-',
			'"1\\f2"' => '400|1|rows=0 sum=- price=-',
			'"1\\u00012"' => '400|1|rows=0 sum=- price=-',
			'"1<x>2"' => '400|1|rows=0 sum=- price=-',
			'"1<!-- c -->2"' => '400|1|rows=0 sum=- price=-',
			'"&#49;&#50;"' => '400|1|rows=0 sum=- price=-',
			'"02"' => '200|1|rows=2 sum=2 price=null,null',
			'"2.0"' => '200|1|rows=2 sum=2 price=null,null',
			'"2e0"' => '200|1|rows=2 sum=2 price=null,null',
		],
		'add.by-barcode.amount' => [
			'2' => '200|1|rows=1 sum=2 price=null',
			'1e309' => '400|0|rows=0 sum=- price=-',
			'"2"' => '200|1|rows=1 sum=2 price=null',
			'"1e3"' => '200|1|rows=1 sum=1000 price=null',
			'" 2"' => '200|1|rows=1 sum=2 price=null',
			'"abc"' => '400|0|rows=0 sum=- price=-',
		],
		'consume.amount' => [
			'2' => '200|1|rows=1 sum=98 price=null',
			'-2' => '400|1|rows=1 sum=100 price=null',
			'2.5' => '200|1|rows=1 sum=97.5 price=null',
			'1e3' => '400|1|rows=1 sum=100 price=null',
			'1E3' => '400|1|rows=1 sum=100 price=null',
			'1e-3' => '200|1|rows=1 sum=99.999 price=null',
			'1e309' => '400|0|rows=1 sum=100 price=null',
			'-0' => '400|1|rows=1 sum=100 price=null',
			'0' => '400|1|rows=1 sum=100 price=null',
			'400 digits (number)' => '400|0|rows=1 sum=100 price=null',
			'"2"' => '200|1|rows=1 sum=98 price=null',
			'"-2"' => '400|1|rows=1 sum=100 price=null',
			'"+2"' => '200|1|rows=1 sum=98 price=null',
			'"2.5"' => '200|1|rows=1 sum=97.5 price=null',
			'".5"' => '200|1|rows=1 sum=99.5 price=null',
			'"5."' => '200|1|rows=1 sum=95 price=null',
			'"1e3"' => '400|1|rows=1 sum=100 price=null',
			'"1E3"' => '400|1|rows=1 sum=100 price=null',
			'"1e-3"' => '200|1|rows=1 sum=99.999 price=null',
			'" 2"' => '200|1|rows=1 sum=98 price=null',
			'"2 "' => '200|1|rows=1 sum=98 price=null',
			'"\\t2"' => '200|1|rows=1 sum=98 price=null',
			'"\\n2"' => '200|1|rows=1 sum=98 price=null',
			'"\\u00a02"' => '400|0|rows=1 sum=100 price=null',
			'"2\\u00a0"' => '400|0|rows=1 sum=100 price=null',
			'"0x1A"' => '400|0|rows=1 sum=100 price=null',
			'"1_000"' => '400|0|rows=1 sum=100 price=null',
			'""' => '400|0|rows=1 sum=100 price=null',
			'"abc"' => '400|0|rows=1 sum=100 price=null',
			'"2abc"' => '400|0|rows=1 sum=100 price=null',
			'"NaN"' => '400|0|rows=1 sum=100 price=null',
			'"INF"' => '400|0|rows=1 sum=100 price=null',
			'"1e309"' => '400|1|rows=1 sum=100 price=null',
			'"-0"' => '400|1|rows=1 sum=100 price=null',
			'"0"' => '400|1|rows=1 sum=100 price=null',
			'"400 digits"' => '400|1|rows=1 sum=100 price=null',
			'"\\r2"' => '200|1|rows=1 sum=98 price=null',
			'"\\u000b2"' => '200|1|rows=1 sum=98 price=null',
			'"\\f2"' => '200|1|rows=1 sum=98 price=null',
			'"1\\u00002"' => '400|0|rows=1 sum=100 price=null',
			'"2\\u0001"' => '400|0|rows=1 sum=100 price=null',
			'"&#50;"' => '400|0|rows=1 sum=100 price=null',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=100 price=null',
			'"\\uff12"' => '400|0|rows=1 sum=100 price=null',
			'"1\\u000b2"' => '400|0|rows=1 sum=100 price=null',
			'"1\\f2"' => '400|0|rows=1 sum=100 price=null',
			'"1\\u00012"' => '400|0|rows=1 sum=100 price=null',
			'"1<x>2"' => '400|0|rows=1 sum=100 price=null',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=100 price=null',
			'"&#49;&#50;"' => '400|0|rows=1 sum=100 price=null',
			'"02"' => '200|1|rows=1 sum=98 price=null',
			'"2.0"' => '200|1|rows=1 sum=98 price=null',
			'"2e0"' => '200|1|rows=1 sum=98 price=null',
		],
		'consume.recipe_id' => [
			'2' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'-2' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'2.5' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'1e3' => '200|1|rows=1 sum=9 price=null recipe_id=1000',
			'1E3' => '200|1|rows=1 sum=9 price=null recipe_id=1000',
			'1e-3' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'1e309' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'-0' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'0' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'400 digits (number)' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"-2"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"+2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"2.5"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'".5"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"5."' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1e3"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1E3"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1e-3"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'" 2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"2 "' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\t2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\n2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\u00a02"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2\\u00a0"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"0x1A"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1_000"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'""' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"abc"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2abc"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"NaN"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"INF"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1e309"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"-0"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"0"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"400 digits"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"\\r2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\u000b2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\f2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"1\\u00002"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2\\u0001"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"&#50;"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"<b>2<\\/b>"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"\\uff12"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1\\u000b2"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1\\f2"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1\\u00012"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1<x>2"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"1<!-- c -->2"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"&#49;&#50;"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"02"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"2.0"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2e0"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'2147483648' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'99999999999' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"99999999999"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'-1e309' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'-2147483648' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			// #987: a positive id that names no recipe is refused like zero and the negatives.
			'3' => '400|1|rows=1 sum=10 price=null recipe_id=-',
		],
		'consume.by-barcode.recipe_id' => [
			'2' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'1e309' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'400 digits (number)' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"2"' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"2 "' => '200|1|rows=1 sum=9 price=null recipe_id=2',
			'"\\u00a02"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'"abc"' => '400|1|rows=1 sum=10 price=null recipe_id=-',
			'-1e309' => '400|1|rows=1 sum=10 price=null recipe_id=-',
		],
		'edit.amount' => [
			'2' => '200|1|rows=1 sum=2 price=null',
			'-2' => '400|1|rows=1 sum=5 price=null',
			'2.5' => '200|1|rows=1 sum=2.5 price=null',
			'1e3' => '200|1|rows=1 sum=1000 price=null',
			'1E3' => '200|1|rows=1 sum=1000 price=null',
			'1e-3' => '200|1|rows=1 sum=0.001 price=null',
			'1e309' => '400|0|rows=1 sum=5 price=null',
			'-0' => '200|1|rows=1 sum=0 price=null',
			'0' => '200|1|rows=1 sum=0 price=null',
			'400 digits (number)' => '400|0|rows=1 sum=5 price=null',
			'"2"' => '200|1|rows=1 sum=2 price=null',
			'"-2"' => '400|1|rows=1 sum=5 price=null',
			'"+2"' => '200|1|rows=1 sum=2 price=null',
			'"2.5"' => '200|1|rows=1 sum=2.5 price=null',
			'".5"' => '200|1|rows=1 sum=0.5 price=null',
			'"5."' => '200|1|rows=1 sum=5 price=null',
			'"1e3"' => '200|1|rows=1 sum=1000 price=null',
			'"1E3"' => '200|1|rows=1 sum=1000 price=null',
			'"1e-3"' => '200|1|rows=1 sum=0.001 price=null',
			'" 2"' => '200|1|rows=1 sum=2 price=null',
			'"2 "' => '200|1|rows=1 sum=2 price=null',
			'"\\t2"' => '200|1|rows=1 sum=2 price=null',
			'"\\n2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u00a02"' => '400|0|rows=1 sum=5 price=null',
			'"2\\u00a0"' => '400|0|rows=1 sum=5 price=null',
			'"0x1A"' => '400|0|rows=1 sum=5 price=null',
			'"1_000"' => '400|0|rows=1 sum=5 price=null',
			'""' => '400|0|rows=1 sum=5 price=null',
			'"abc"' => '400|0|rows=1 sum=5 price=null',
			'"2abc"' => '400|0|rows=1 sum=5 price=null',
			'"NaN"' => '400|0|rows=1 sum=5 price=null',
			'"INF"' => '400|0|rows=1 sum=5 price=null',
			'"1e309"' => '400|1|rows=1 sum=5 price=null',
			'"-0"' => '200|1|rows=1 sum=0 price=null',
			'"0"' => '200|1|rows=1 sum=0 price=null',
			'"400 digits"' => '400|1|rows=1 sum=5 price=null',
			'"\\r2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u000b2"' => '200|1|rows=1 sum=2 price=null',
			'"\\f2"' => '200|1|rows=1 sum=2 price=null',
			'"1\\u00002"' => '400|0|rows=1 sum=5 price=null',
			'"2\\u0001"' => '400|0|rows=1 sum=5 price=null',
			'"&#50;"' => '400|0|rows=1 sum=5 price=null',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=5 price=null',
			'"\\uff12"' => '400|0|rows=1 sum=5 price=null',
			'"1\\u000b2"' => '400|0|rows=1 sum=5 price=null',
			'"1\\f2"' => '400|0|rows=1 sum=5 price=null',
			'"1\\u00012"' => '400|0|rows=1 sum=5 price=null',
			'"1<x>2"' => '400|0|rows=1 sum=5 price=null',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=5 price=null',
			'"&#49;&#50;"' => '400|0|rows=1 sum=5 price=null',
			'"02"' => '200|1|rows=1 sum=2 price=null',
			'"2.0"' => '200|1|rows=1 sum=2 price=null',
			'"2e0"' => '200|1|rows=1 sum=2 price=null',
		],
		'edit.price' => [
			'2' => '200|1|rows=1 sum=5 price=2',
			'-2' => '200|1|rows=1 sum=5 price=-2',
			'2.5' => '200|1|rows=1 sum=5 price=2.5',
			'1e3' => '200|1|rows=1 sum=5 price=1000',
			'1E3' => '200|1|rows=1 sum=5 price=1000',
			'1e-3' => '200|1|rows=1 sum=5 price=0.001',
			'1e309' => '400|0|rows=1 sum=5 price=1.5',
			'-0' => '200|1|rows=1 sum=5 price=0',
			'0' => '200|1|rows=1 sum=5 price=0',
			'400 digits (number)' => '400|0|rows=1 sum=5 price=1.5',
			'"2"' => '200|1|rows=1 sum=5 price=2',
			'"-2"' => '200|1|rows=1 sum=5 price=-2',
			'"+2"' => '200|1|rows=1 sum=5 price=2',
			'"2.5"' => '200|1|rows=1 sum=5 price=2.5',
			'".5"' => '200|1|rows=1 sum=5 price=0.5',
			'"5."' => '200|1|rows=1 sum=5 price=5',
			'"1e3"' => '200|1|rows=1 sum=5 price=1000',
			'"1E3"' => '200|1|rows=1 sum=5 price=1000',
			'"1e-3"' => '200|1|rows=1 sum=5 price=0.001',
			'" 2"' => '200|1|rows=1 sum=5 price=2',
			'"2 "' => '200|1|rows=1 sum=5 price=2',
			'"\\t2"' => '200|1|rows=1 sum=5 price=2',
			'"\\n2"' => '200|1|rows=1 sum=5 price=2',
			'"\\u00a02"' => '400|0|rows=1 sum=5 price=1.5',
			'"2\\u00a0"' => '400|0|rows=1 sum=5 price=1.5',
			'"0x1A"' => '400|0|rows=1 sum=5 price=1.5',
			'"1_000"' => '400|0|rows=1 sum=5 price=1.5',
			'""' => '200|1|rows=1 sum=5 price=null',
			'"abc"' => '400|0|rows=1 sum=5 price=1.5',
			'"2abc"' => '400|0|rows=1 sum=5 price=1.5',
			'"NaN"' => '400|0|rows=1 sum=5 price=1.5',
			'"INF"' => '400|0|rows=1 sum=5 price=1.5',
			'"1e309"' => '400|1|rows=1 sum=5 price=1.5',
			'"-0"' => '200|1|rows=1 sum=5 price=-0',
			'"0"' => '200|1|rows=1 sum=5 price=0',
			'"400 digits"' => '400|1|rows=1 sum=5 price=1.5',
			'"\\r2"' => '200|1|rows=1 sum=5 price=2',
			'"\\u000b2"' => '200|1|rows=1 sum=5 price=2',
			'"\\f2"' => '200|1|rows=1 sum=5 price=2',
			'"1\\u00002"' => '400|0|rows=1 sum=5 price=1.5',
			'"2\\u0001"' => '400|0|rows=1 sum=5 price=1.5',
			'"&#50;"' => '400|0|rows=1 sum=5 price=1.5',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=5 price=1.5',
			'"\\uff12"' => '400|0|rows=1 sum=5 price=1.5',
			'"1\\u000b2"' => '400|0|rows=1 sum=5 price=1.5',
			'"1\\f2"' => '400|0|rows=1 sum=5 price=1.5',
			'"1\\u00012"' => '400|0|rows=1 sum=5 price=1.5',
			'"1<x>2"' => '400|0|rows=1 sum=5 price=1.5',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=5 price=1.5',
			'"&#49;&#50;"' => '400|0|rows=1 sum=5 price=1.5',
			'"02"' => '200|1|rows=1 sum=5 price=2',
			'"2.0"' => '200|1|rows=1 sum=5 price=2',
			'"2e0"' => '200|1|rows=1 sum=5 price=2',
			'null' => '200|1|rows=1 sum=5 price=null',
		],
		'inventory.new_amount' => [
			'2' => '200|1|rows=1 sum=2 price=null',
			'-2' => '400|1|rows=1 sum=5 price=null',
			'2.5' => '200|1|rows=1 sum=2.5 price=null',
			'1e3' => '200|1|rows=2 sum=1000 price=null,null',
			'1E3' => '200|1|rows=2 sum=1000 price=null,null',
			'1e-3' => '200|1|rows=1 sum=0.001000000000000334 price=null',
			'1e309' => '400|0|rows=1 sum=5 price=null',
			'-0' => '200|1|rows=0 sum=- price=-',
			'0' => '200|1|rows=0 sum=- price=-',
			'400 digits (number)' => '400|0|rows=1 sum=5 price=null',
			'"2"' => '200|1|rows=1 sum=2 price=null',
			'"-2"' => '400|1|rows=1 sum=5 price=null',
			'"+2"' => '200|1|rows=1 sum=2 price=null',
			'"2.5"' => '200|1|rows=1 sum=2.5 price=null',
			'".5"' => '200|1|rows=1 sum=0.5 price=null',
			'"5."' => '400|1|rows=1 sum=5 price=null',
			'"1e3"' => '200|1|rows=2 sum=1000 price=null,null',
			'"1E3"' => '200|1|rows=2 sum=1000 price=null,null',
			'"1e-3"' => '200|1|rows=1 sum=0.001000000000000334 price=null',
			'" 2"' => '200|1|rows=1 sum=2 price=null',
			'"2 "' => '200|1|rows=1 sum=2 price=null',
			'"\\t2"' => '200|1|rows=1 sum=2 price=null',
			'"\\n2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u00a02"' => '400|0|rows=1 sum=5 price=null',
			'"2\\u00a0"' => '400|0|rows=1 sum=5 price=null',
			'"0x1A"' => '400|0|rows=1 sum=5 price=null',
			'"1_000"' => '400|0|rows=1 sum=5 price=null',
			'""' => '400|0|rows=1 sum=5 price=null',
			'"abc"' => '400|0|rows=1 sum=5 price=null',
			'"2abc"' => '400|0|rows=1 sum=5 price=null',
			'"NaN"' => '400|0|rows=1 sum=5 price=null',
			'"INF"' => '400|0|rows=1 sum=5 price=null',
			'"1e309"' => '400|1|rows=1 sum=5 price=null',
			'"-0"' => '200|1|rows=0 sum=- price=-',
			'"0"' => '200|1|rows=0 sum=- price=-',
			'"400 digits"' => '400|1|rows=1 sum=5 price=null',
			'"\\r2"' => '200|1|rows=1 sum=2 price=null',
			'"\\u000b2"' => '200|1|rows=1 sum=2 price=null',
			'"\\f2"' => '200|1|rows=1 sum=2 price=null',
			'"1\\u00002"' => '400|0|rows=1 sum=5 price=null',
			'"2\\u0001"' => '400|0|rows=1 sum=5 price=null',
			'"&#50;"' => '400|0|rows=1 sum=5 price=null',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=5 price=null',
			'"\\uff12"' => '400|0|rows=1 sum=5 price=null',
			'"1\\u000b2"' => '400|0|rows=1 sum=5 price=null',
			'"1\\f2"' => '400|0|rows=1 sum=5 price=null',
			'"1\\u00012"' => '400|0|rows=1 sum=5 price=null',
			'"1<x>2"' => '400|0|rows=1 sum=5 price=null',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=5 price=null',
			'"&#49;&#50;"' => '400|0|rows=1 sum=5 price=null',
			'"02"' => '200|1|rows=1 sum=2 price=null',
			'"2.0"' => '200|1|rows=1 sum=2 price=null',
			'"2e0"' => '200|1|rows=1 sum=2 price=null',
		],
		'inventory.price' => [
			'2' => '200|1|rows=1 sum=3 price=2',
			'-2' => '200|1|rows=1 sum=3 price=-2',
			'2.5' => '200|1|rows=1 sum=3 price=2.5',
			'1e3' => '200|1|rows=1 sum=3 price=1000',
			'1E3' => '200|1|rows=1 sum=3 price=1000',
			'1e-3' => '200|1|rows=1 sum=3 price=0.001',
			'1e309' => '400|0|rows=0 sum=- price=-',
			'-0' => '200|1|rows=1 sum=3 price=0',
			'0' => '200|1|rows=1 sum=3 price=0',
			'400 digits (number)' => '400|0|rows=0 sum=- price=-',
			'"2"' => '200|1|rows=1 sum=3 price=2',
			'"-2"' => '200|1|rows=1 sum=3 price=-2',
			'"+2"' => '200|1|rows=1 sum=3 price=2',
			'"2.5"' => '200|1|rows=1 sum=3 price=2.5',
			'".5"' => '200|1|rows=1 sum=3 price=0.5',
			'"5."' => '200|1|rows=1 sum=3 price=5',
			'"1e3"' => '200|1|rows=1 sum=3 price=1000',
			'"1E3"' => '200|1|rows=1 sum=3 price=1000',
			'"1e-3"' => '200|1|rows=1 sum=3 price=0.001',
			'" 2"' => '200|1|rows=1 sum=3 price=2',
			'"2 "' => '200|1|rows=1 sum=3 price=2',
			'"\\t2"' => '200|1|rows=1 sum=3 price=2',
			'"\\n2"' => '200|1|rows=1 sum=3 price=2',
			'"\\u00a02"' => '400|0|rows=0 sum=- price=-',
			'"2\\u00a0"' => '400|0|rows=0 sum=- price=-',
			'"0x1A"' => '400|0|rows=0 sum=- price=-',
			'"1_000"' => '400|0|rows=0 sum=- price=-',
			'""' => '200|1|rows=1 sum=3 price=null',
			'"abc"' => '400|0|rows=0 sum=- price=-',
			'"2abc"' => '400|0|rows=0 sum=- price=-',
			'"NaN"' => '400|0|rows=0 sum=- price=-',
			'"INF"' => '400|0|rows=0 sum=- price=-',
			'"1e309"' => '400|1|rows=0 sum=- price=-',
			'"-0"' => '200|1|rows=1 sum=3 price=-0',
			'"0"' => '200|1|rows=1 sum=3 price=0',
			'"400 digits"' => '400|1|rows=0 sum=- price=-',
			'"\\r2"' => '200|1|rows=1 sum=3 price=2',
			'"\\u000b2"' => '200|1|rows=1 sum=3 price=2',
			'"\\f2"' => '200|1|rows=1 sum=3 price=2',
			'"1\\u00002"' => '400|0|rows=0 sum=- price=-',
			'"2\\u0001"' => '400|0|rows=0 sum=- price=-',
			'"&#50;"' => '400|0|rows=0 sum=- price=-',
			'"<b>2<\\/b>"' => '400|0|rows=0 sum=- price=-',
			'"\\uff12"' => '400|0|rows=0 sum=- price=-',
			'"1\\u000b2"' => '400|0|rows=0 sum=- price=-',
			'"1\\f2"' => '400|0|rows=0 sum=- price=-',
			'"1\\u00012"' => '400|0|rows=0 sum=- price=-',
			'"1<x>2"' => '400|0|rows=0 sum=- price=-',
			'"1<!-- c -->2"' => '400|0|rows=0 sum=- price=-',
			'"&#49;&#50;"' => '400|0|rows=0 sum=- price=-',
			'"02"' => '200|1|rows=1 sum=3 price=2',
			'"2.0"' => '200|1|rows=1 sum=3 price=2',
			'"2e0"' => '200|1|rows=1 sum=3 price=2',
			'null' => '200|1|rows=1 sum=3 price=null',
		],
		'inventory.stock_label_type' => [
			'2' => '200|1|rows=2 sum=2 price=null,null',
			'-2' => '200|1|rows=1 sum=2 price=null',
			'2.5' => '200|1|rows=1 sum=2 price=null',
			'1e3' => '200|1|rows=1 sum=2 price=null',
			'1E3' => '200|1|rows=1 sum=2 price=null',
			'1e-3' => '200|1|rows=1 sum=2 price=null',
			'1e309' => '200|1|rows=1 sum=2 price=null',
			'-0' => '200|1|rows=1 sum=2 price=null',
			'0' => '200|1|rows=1 sum=2 price=null',
			'400 digits (number)' => '200|1|rows=1 sum=2 price=null',
			'"2"' => '200|1|rows=2 sum=2 price=null,null',
			'"-2"' => '200|1|rows=1 sum=2 price=null',
			'"+2"' => '200|1|rows=2 sum=2 price=null,null',
			'"2.5"' => '200|1|rows=1 sum=2 price=null',
			'".5"' => '200|1|rows=1 sum=2 price=null',
			'"5."' => '200|1|rows=1 sum=2 price=null',
			'"1e3"' => '200|1|rows=1 sum=2 price=null',
			'"1E3"' => '200|1|rows=1 sum=2 price=null',
			'"1e-3"' => '200|1|rows=1 sum=2 price=null',
			'" 2"' => '200|1|rows=2 sum=2 price=null,null',
			'"2 "' => '200|1|rows=2 sum=2 price=null,null',
			'"\\t2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\n2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\u00a02"' => '400|1|rows=0 sum=- price=-',
			'"2\\u00a0"' => '400|1|rows=0 sum=- price=-',
			'"0x1A"' => '400|1|rows=0 sum=- price=-',
			'"1_000"' => '400|1|rows=0 sum=- price=-',
			'""' => '200|1|rows=1 sum=2 price=null',
			'"abc"' => '400|1|rows=0 sum=- price=-',
			'"2abc"' => '400|1|rows=0 sum=- price=-',
			'"NaN"' => '400|1|rows=0 sum=- price=-',
			'"INF"' => '400|1|rows=0 sum=- price=-',
			'"1e309"' => '200|1|rows=1 sum=2 price=null',
			'"-0"' => '200|1|rows=1 sum=2 price=null',
			'"0"' => '200|1|rows=1 sum=2 price=null',
			'"400 digits"' => '200|1|rows=1 sum=2 price=null',
			'"\\r2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\u000b2"' => '200|1|rows=2 sum=2 price=null,null',
			'"\\f2"' => '200|1|rows=2 sum=2 price=null,null',
			'"1\\u00002"' => '400|1|rows=0 sum=- price=-',
			'"2\\u0001"' => '400|1|rows=0 sum=- price=-',
			'"&#50;"' => '400|1|rows=0 sum=- price=-',
			'"<b>2<\\/b>"' => '400|1|rows=0 sum=- price=-',
			'"\\uff12"' => '400|1|rows=0 sum=- price=-',
			'"1\\u000b2"' => '400|1|rows=0 sum=- price=-',
			'"1\\f2"' => '400|1|rows=0 sum=- price=-',
			'"1\\u00012"' => '400|1|rows=0 sum=- price=-',
			'"1<x>2"' => '400|1|rows=0 sum=- price=-',
			'"1<!-- c -->2"' => '400|1|rows=0 sum=- price=-',
			'"&#49;&#50;"' => '400|1|rows=0 sum=- price=-',
			'"02"' => '200|1|rows=2 sum=2 price=null,null',
			'"2.0"' => '200|1|rows=2 sum=2 price=null,null',
			'"2e0"' => '200|1|rows=2 sum=2 price=null,null',
		],
		'open.amount' => [
			'2' => '200|1|rows=2 sum=100 price=null,null open=2',
			'-2' => '400|1|rows=1 sum=100 price=null open=-',
			'2.5' => '200|1|rows=2 sum=100 price=null,null open=2.5',
			'1e3' => '400|1|rows=1 sum=100 price=null open=-',
			'1E3' => '400|1|rows=1 sum=100 price=null open=-',
			'1e-3' => '200|1|rows=2 sum=100 price=null,null open=0.001',
			'1e309' => '400|0|rows=1 sum=100 price=null open=-',
			'-0' => '400|1|rows=1 sum=100 price=null open=-',
			'0' => '400|1|rows=1 sum=100 price=null open=-',
			'400 digits (number)' => '400|0|rows=1 sum=100 price=null open=-',
			'"2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"-2"' => '400|1|rows=1 sum=100 price=null open=-',
			'"+2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"2.5"' => '200|1|rows=2 sum=100 price=null,null open=2.5',
			'".5"' => '200|1|rows=2 sum=100 price=null,null open=0.5',
			'"5."' => '200|1|rows=2 sum=100 price=null,null open=5',
			'"1e3"' => '400|1|rows=1 sum=100 price=null open=-',
			'"1E3"' => '400|1|rows=1 sum=100 price=null open=-',
			'"1e-3"' => '200|1|rows=2 sum=100 price=null,null open=0.001',
			'" 2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"2 "' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"\\t2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"\\n2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"\\u00a02"' => '400|0|rows=1 sum=100 price=null open=-',
			'"2\\u00a0"' => '400|0|rows=1 sum=100 price=null open=-',
			'"0x1A"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1_000"' => '400|0|rows=1 sum=100 price=null open=-',
			'""' => '400|0|rows=1 sum=100 price=null open=-',
			'"abc"' => '400|0|rows=1 sum=100 price=null open=-',
			'"2abc"' => '400|0|rows=1 sum=100 price=null open=-',
			'"NaN"' => '400|0|rows=1 sum=100 price=null open=-',
			'"INF"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1e309"' => '400|1|rows=1 sum=100 price=null open=-',
			'"-0"' => '400|1|rows=1 sum=100 price=null open=-',
			'"0"' => '400|1|rows=1 sum=100 price=null open=-',
			'"400 digits"' => '400|1|rows=1 sum=100 price=null open=-',
			'"\\r2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"\\u000b2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"\\f2"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"1\\u00002"' => '400|0|rows=1 sum=100 price=null open=-',
			'"2\\u0001"' => '400|0|rows=1 sum=100 price=null open=-',
			'"&#50;"' => '400|0|rows=1 sum=100 price=null open=-',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=100 price=null open=-',
			'"\\uff12"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1\\u000b2"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1\\f2"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1\\u00012"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1<x>2"' => '400|0|rows=1 sum=100 price=null open=-',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=100 price=null open=-',
			'"&#49;&#50;"' => '400|0|rows=1 sum=100 price=null open=-',
			'"02"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"2.0"' => '200|1|rows=2 sum=100 price=null,null open=2',
			'"2e0"' => '200|1|rows=2 sum=100 price=null,null open=2',
		],
		'transfer.amount' => [
			'2' => '200|1|from=98 to=2',
			'-2' => '400|1|from=100 to=-',
			'2.5' => '200|1|from=97.5 to=2.5',
			'1e3' => '400|1|from=100 to=-',
			'1E3' => '400|1|from=100 to=-',
			'1e-3' => '200|1|from=99.999 to=0.001',
			'1e309' => '400|0|from=100 to=-',
			'-0' => '400|1|from=100 to=-',
			'0' => '400|1|from=100 to=-',
			'400 digits (number)' => '400|0|from=100 to=-',
			'"2"' => '200|1|from=98 to=2',
			'"-2"' => '400|1|from=100 to=-',
			'"+2"' => '200|1|from=98 to=2',
			'"2.5"' => '200|1|from=97.5 to=2.5',
			'".5"' => '200|1|from=99.5 to=0.5',
			'"5."' => '200|1|from=95 to=5',
			'"1e3"' => '400|1|from=100 to=-',
			'"1E3"' => '400|1|from=100 to=-',
			'"1e-3"' => '200|1|from=99.999 to=0.001',
			'" 2"' => '200|1|from=98 to=2',
			'"2 "' => '200|1|from=98 to=2',
			'"\\t2"' => '200|1|from=98 to=2',
			'"\\n2"' => '200|1|from=98 to=2',
			'"\\u00a02"' => '400|0|from=100 to=-',
			'"2\\u00a0"' => '400|0|from=100 to=-',
			'"0x1A"' => '400|0|from=100 to=-',
			'"1_000"' => '400|0|from=100 to=-',
			'""' => '400|0|from=100 to=-',
			'"abc"' => '400|0|from=100 to=-',
			'"2abc"' => '400|0|from=100 to=-',
			'"NaN"' => '400|0|from=100 to=-',
			'"INF"' => '400|0|from=100 to=-',
			'"1e309"' => '400|1|from=100 to=-',
			'"-0"' => '400|1|from=100 to=-',
			'"0"' => '400|1|from=100 to=-',
			'"400 digits"' => '400|1|from=100 to=-',
			'"\\r2"' => '200|1|from=98 to=2',
			'"\\u000b2"' => '200|1|from=98 to=2',
			'"\\f2"' => '200|1|from=98 to=2',
			'"1\\u00002"' => '400|0|from=100 to=-',
			'"2\\u0001"' => '400|0|from=100 to=-',
			'"&#50;"' => '400|0|from=100 to=-',
			'"<b>2<\\/b>"' => '400|0|from=100 to=-',
			'"\\uff12"' => '400|0|from=100 to=-',
			'"1\\u000b2"' => '400|0|from=100 to=-',
			'"1\\f2"' => '400|0|from=100 to=-',
			'"1\\u00012"' => '400|0|from=100 to=-',
			'"1<x>2"' => '400|0|from=100 to=-',
			'"1<!-- c -->2"' => '400|0|from=100 to=-',
			'"&#49;&#50;"' => '400|0|from=100 to=-',
			'"02"' => '200|1|from=98 to=2',
			'"2.0"' => '200|1|from=98 to=2',
			'"2e0"' => '200|1|from=98 to=2',
		],
		'weigh.gross_amount' => [
			'2' => '200|1|rows=1 sum=1 price=null',
			'-2' => '400|1|rows=1 sum=10 price=null',
			'2.5' => '200|1|rows=1 sum=1.5 price=null',
			'1e3' => '200|1|rows=2 sum=999 price=null,null',
			'1E3' => '200|1|rows=2 sum=999 price=null,null',
			'1e-3' => '400|1|rows=1 sum=10 price=null',
			'1e309' => '400|0|rows=1 sum=10 price=null',
			'-0' => '400|1|rows=1 sum=10 price=null',
			'0' => '400|1|rows=1 sum=10 price=null',
			'400 digits (number)' => '400|0|rows=1 sum=10 price=null',
			'"2"' => '200|1|rows=1 sum=1 price=null',
			'"-2"' => '400|1|rows=1 sum=10 price=null',
			'"+2"' => '200|1|rows=1 sum=1 price=null',
			'"2.5"' => '200|1|rows=1 sum=1.5 price=null',
			'".5"' => '400|1|rows=1 sum=10 price=null',
			'"5."' => '200|1|rows=1 sum=4 price=null',
			'"1e3"' => '200|1|rows=2 sum=999 price=null,null',
			'"1E3"' => '200|1|rows=2 sum=999 price=null,null',
			'"1e-3"' => '400|1|rows=1 sum=10 price=null',
			'" 2"' => '200|1|rows=1 sum=1 price=null',
			'"2 "' => '200|1|rows=1 sum=1 price=null',
			'"\\t2"' => '200|1|rows=1 sum=1 price=null',
			'"\\n2"' => '200|1|rows=1 sum=1 price=null',
			'"\\u00a02"' => '400|0|rows=1 sum=10 price=null',
			'"2\\u00a0"' => '400|0|rows=1 sum=10 price=null',
			'"0x1A"' => '400|0|rows=1 sum=10 price=null',
			'"1_000"' => '400|0|rows=1 sum=10 price=null',
			'""' => '400|0|rows=1 sum=10 price=null',
			'"abc"' => '400|0|rows=1 sum=10 price=null',
			'"2abc"' => '400|0|rows=1 sum=10 price=null',
			'"NaN"' => '400|0|rows=1 sum=10 price=null',
			'"INF"' => '400|0|rows=1 sum=10 price=null',
			'"1e309"' => '400|1|rows=1 sum=10 price=null',
			'"-0"' => '400|1|rows=1 sum=10 price=null',
			'"0"' => '400|1|rows=1 sum=10 price=null',
			'"400 digits"' => '400|1|rows=1 sum=10 price=null',
			'"\\r2"' => '200|1|rows=1 sum=1 price=null',
			'"\\u000b2"' => '200|1|rows=1 sum=1 price=null',
			'"\\f2"' => '200|1|rows=1 sum=1 price=null',
			'"1\\u00002"' => '400|0|rows=1 sum=10 price=null',
			'"2\\u0001"' => '400|0|rows=1 sum=10 price=null',
			'"&#50;"' => '400|0|rows=1 sum=10 price=null',
			'"<b>2<\\/b>"' => '400|0|rows=1 sum=10 price=null',
			'"\\uff12"' => '400|0|rows=1 sum=10 price=null',
			'"1\\u000b2"' => '400|0|rows=1 sum=10 price=null',
			'"1\\f2"' => '400|0|rows=1 sum=10 price=null',
			'"1\\u00012"' => '400|0|rows=1 sum=10 price=null',
			'"1<x>2"' => '400|0|rows=1 sum=10 price=null',
			'"1<!-- c -->2"' => '400|0|rows=1 sum=10 price=null',
			'"&#49;&#50;"' => '400|0|rows=1 sum=10 price=null',
			'"02"' => '200|1|rows=1 sum=1 price=null',
			'"2.0"' => '200|1|rows=1 sum=1 price=null',
			'"2e0"' => '200|1|rows=1 sum=1 price=null',
		],
		'measure.amount' => [
			'2' => '200|1|opened_amount=2',
			'-2' => '400|1|opened_amount=-',
			'2.5' => '200|1|opened_amount=2.5',
			'1e3' => '200|1|opened_amount=1000',
			'1E3' => '200|1|opened_amount=1000',
			'1e-3' => '200|1|opened_amount=0.001',
			'1e309' => '400|1|opened_amount=-',
			'-0' => '400|1|opened_amount=-',
			'0' => '400|1|opened_amount=-',
			'400 digits (number)' => '400|1|opened_amount=-',
			'"2"' => '200|1|opened_amount=2',
			'"-2"' => '400|1|opened_amount=-',
			'"+2"' => '200|1|opened_amount=2',
			'"2.5"' => '200|1|opened_amount=2.5',
			'".5"' => '200|1|opened_amount=0.5',
			'"5."' => '200|1|opened_amount=5',
			'"1e3"' => '200|1|opened_amount=1000',
			'"1E3"' => '200|1|opened_amount=1000',
			'"1e-3"' => '200|1|opened_amount=0.001',
			'" 2"' => '200|1|opened_amount=2',
			'"2 "' => '200|1|opened_amount=2',
			'"\\t2"' => '200|1|opened_amount=2',
			'"\\n2"' => '200|1|opened_amount=2',
			'"\\u00a02"' => '400|1|opened_amount=-',
			'"2\\u00a0"' => '400|1|opened_amount=-',
			'"0x1A"' => '400|1|opened_amount=-',
			'"1_000"' => '400|1|opened_amount=-',
			'""' => '400|1|opened_amount=-',
			'"abc"' => '400|1|opened_amount=-',
			'"2abc"' => '400|1|opened_amount=-',
			'"NaN"' => '400|1|opened_amount=-',
			'"INF"' => '400|1|opened_amount=-',
			'"1e309"' => '400|1|opened_amount=-',
			'"-0"' => '400|1|opened_amount=-',
			'"0"' => '400|1|opened_amount=-',
			'"400 digits"' => '400|1|opened_amount=-',
			'"\\r2"' => '200|1|opened_amount=2',
			'"\\u000b2"' => '200|1|opened_amount=2',
			'"\\f2"' => '200|1|opened_amount=2',
			'"1\\u00002"' => '400|1|opened_amount=-',
			'"2\\u0001"' => '400|1|opened_amount=-',
			'"&#50;"' => '400|1|opened_amount=-',
			'"<b>2<\\/b>"' => '400|1|opened_amount=-',
			'"\\uff12"' => '400|1|opened_amount=-',
			'"1\\u000b2"' => '400|1|opened_amount=-',
			'"1\\f2"' => '400|1|opened_amount=-',
			'"1\\u00012"' => '400|1|opened_amount=-',
			'"1<x>2"' => '400|1|opened_amount=-',
			'"1<!-- c -->2"' => '400|1|opened_amount=-',
			'"&#49;&#50;"' => '400|1|opened_amount=-',
			'"02"' => '200|1|opened_amount=2',
			'"2.0"' => '200|1|opened_amount=2',
			'"2e0"' => '200|1|opened_amount=2',
		],
		'shoppinglist.add.product_amount' => [
			'2' => '204|1|rows=2',
			'-2' => '400|1|rows=-',
			'2.5' => '204|1|rows=2.5',
			'1e3' => '204|1|rows=1000',
			'1E3' => '204|1|rows=1000',
			'1e-3' => '204|1|rows=0.001',
			'1e309' => '400|1|rows=-',
			'-0' => '400|1|rows=-',
			'0' => '400|1|rows=-',
			'400 digits (number)' => '400|1|rows=-',
			'"2"' => '204|1|rows=2',
			'"-2"' => '400|1|rows=-',
			'"+2"' => '204|1|rows=2',
			'"2.5"' => '204|1|rows=2.5',
			'".5"' => '204|1|rows=0.5',
			'"5."' => '204|1|rows=5',
			'"1e3"' => '204|1|rows=1000',
			'"1E3"' => '204|1|rows=1000',
			'"1e-3"' => '204|1|rows=0.001',
			'" 2"' => '204|1|rows=2',
			'"2 "' => '204|1|rows=2',
			'"\\t2"' => '204|1|rows=2',
			'"\\n2"' => '204|1|rows=2',
			'"\\u00a02"' => '400|1|rows=-',
			'"2\\u00a0"' => '400|1|rows=-',
			'"0x1A"' => '400|1|rows=-',
			'"1_000"' => '400|1|rows=-',
			'""' => '400|1|rows=-',
			'"abc"' => '400|1|rows=-',
			'"2abc"' => '400|1|rows=-',
			'"NaN"' => '400|1|rows=-',
			'"INF"' => '400|1|rows=-',
			'"1e309"' => '400|1|rows=-',
			'"-0"' => '400|1|rows=-',
			'"0"' => '400|1|rows=-',
			'"400 digits"' => '400|1|rows=-',
			'"\\r2"' => '204|1|rows=2',
			'"\\u000b2"' => '204|1|rows=2',
			'"\\f2"' => '204|1|rows=2',
			'"1\\u00002"' => '400|1|rows=-',
			'"2\\u0001"' => '400|1|rows=-',
			'"&#50;"' => '400|1|rows=-',
			'"<b>2<\\/b>"' => '400|1|rows=-',
			'"\\uff12"' => '400|1|rows=-',
			'"1\\u000b2"' => '400|1|rows=-',
			'"1\\f2"' => '400|1|rows=-',
			'"1\\u00012"' => '400|1|rows=-',
			'"1<x>2"' => '400|1|rows=-',
			'"1<!-- c -->2"' => '400|1|rows=-',
			'"&#49;&#50;"' => '400|1|rows=-',
			'"02"' => '204|1|rows=2',
			'"2.0"' => '204|1|rows=2',
			'"2e0"' => '204|1|rows=2',
		],
		'shoppinglist.add.product_id' => [
			'2' => '204|1|rows=2:3',
			'-2' => '400|1|rows=-',
			'2.5' => '400|1|rows=-',
			'1e3' => '204|1|rows=1000:3',
			'1E3' => '204|1|rows=1000:3',
			'1e-3' => '400|1|rows=-',
			'1e309' => '400|1|rows=-',
			'-0' => '400|1|rows=-',
			'0' => '400|1|rows=-',
			'400 digits (number)' => '400|1|rows=-',
			'"2"' => '204|1|rows=2:3',
			'"-2"' => '400|1|rows=-',
			'"+2"' => '204|1|rows=2:3',
			'"2.5"' => '400|1|rows=-',
			'".5"' => '400|1|rows=-',
			'"5."' => '400|1|rows=-',
			'"1e3"' => '400|1|rows=-',
			'"1E3"' => '400|1|rows=-',
			'"1e-3"' => '400|1|rows=-',
			'" 2"' => '204|1|rows=2:3',
			'"2 "' => '204|1|rows=2:3',
			'"\\t2"' => '204|1|rows=2:3',
			'"\\n2"' => '204|1|rows=2:3',
			'"\\u00a02"' => '400|1|rows=-',
			'"2\\u00a0"' => '400|1|rows=-',
			'"0x1A"' => '400|1|rows=-',
			'"1_000"' => '400|1|rows=-',
			'""' => '400|1|rows=-',
			'"abc"' => '400|1|rows=-',
			'"2abc"' => '400|1|rows=-',
			'"NaN"' => '400|1|rows=-',
			'"INF"' => '400|1|rows=-',
			'"1e309"' => '400|1|rows=-',
			'"-0"' => '400|1|rows=-',
			'"0"' => '400|1|rows=-',
			'"400 digits"' => '400|1|rows=-',
			'"\\r2"' => '204|1|rows=2:3',
			'"\\u000b2"' => '204|1|rows=2:3',
			'"\\f2"' => '204|1|rows=2:3',
			'"1\\u00002"' => '400|1|rows=-',
			'"2\\u0001"' => '400|1|rows=-',
			'"&#50;"' => '400|1|rows=-',
			'"<b>2<\\/b>"' => '400|1|rows=-',
			'"\\uff12"' => '400|1|rows=-',
			'"1\\u000b2"' => '400|1|rows=-',
			'"1\\f2"' => '400|1|rows=-',
			'"1\\u00012"' => '400|1|rows=-',
			'"1<x>2"' => '400|1|rows=-',
			'"1<!-- c -->2"' => '400|1|rows=-',
			'"&#49;&#50;"' => '400|1|rows=-',
			'"02"' => '204|1|rows=2:3',
			'"2.0"' => '400|1|rows=-',
			'"2e0"' => '400|1|rows=-',
			'2147483648' => '400|1|rows=-',
			'99999999999' => '400|1|rows=-',
			'"99999999999"' => '400|1|rows=-',
			'-1e309' => '400|1|rows=-',
			'-2147483648' => '400|1|rows=-',
		],
		'shoppinglist.remove.product_amount' => [
			'2' => '204|1|rows=8',
			'-2' => '400|1|rows=10',
			'2.5' => '204|1|rows=7.5',
			'1e3' => '204|1|rows=-',
			'1E3' => '204|1|rows=-',
			'1e-3' => '204|1|rows=9.999',
			'1e309' => '400|1|rows=10',
			'-0' => '400|1|rows=10',
			'0' => '400|1|rows=10',
			'400 digits (number)' => '400|1|rows=10',
			'"2"' => '204|1|rows=8',
			'"-2"' => '400|1|rows=10',
			'"+2"' => '204|1|rows=8',
			'"2.5"' => '204|1|rows=7.5',
			'".5"' => '204|1|rows=9.5',
			'"5."' => '204|1|rows=5',
			'"1e3"' => '204|1|rows=-',
			'"1E3"' => '204|1|rows=-',
			'"1e-3"' => '204|1|rows=9.999',
			'" 2"' => '204|1|rows=8',
			'"2 "' => '204|1|rows=8',
			'"\\t2"' => '204|1|rows=8',
			'"\\n2"' => '204|1|rows=8',
			'"\\u00a02"' => '400|1|rows=10',
			'"2\\u00a0"' => '400|1|rows=10',
			'"0x1A"' => '400|1|rows=10',
			'"1_000"' => '400|1|rows=10',
			'""' => '400|1|rows=10',
			'"abc"' => '400|1|rows=10',
			'"2abc"' => '400|1|rows=10',
			'"NaN"' => '400|1|rows=10',
			'"INF"' => '400|1|rows=10',
			'"1e309"' => '400|1|rows=10',
			'"-0"' => '400|1|rows=10',
			'"0"' => '400|1|rows=10',
			'"400 digits"' => '400|1|rows=10',
			'"\\r2"' => '204|1|rows=8',
			'"\\u000b2"' => '204|1|rows=8',
			'"\\f2"' => '204|1|rows=8',
			'"1\\u00002"' => '400|1|rows=10',
			'"2\\u0001"' => '400|1|rows=10',
			'"&#50;"' => '400|1|rows=10',
			'"<b>2<\\/b>"' => '400|1|rows=10',
			'"\\uff12"' => '400|1|rows=10',
			'"1\\u000b2"' => '400|1|rows=10',
			'"1\\f2"' => '400|1|rows=10',
			'"1\\u00012"' => '400|1|rows=10',
			'"1<x>2"' => '400|1|rows=10',
			'"1<!-- c -->2"' => '400|1|rows=10',
			'"&#49;&#50;"' => '400|1|rows=10',
			'"02"' => '204|1|rows=8',
			'"2.0"' => '204|1|rows=8',
			'"2e0"' => '204|1|rows=8',
		],
		'shoppinglist.remove.product_id' => [
			'2' => '204|1|rows=2:7,1000:10',
			'-2' => '400|1|rows=2:10,1000:10',
			'2.5' => '400|1|rows=2:10,1000:10',
			'1e3' => '204|1|rows=2:10,1000:7',
			'1E3' => '204|1|rows=2:10,1000:7',
			'1e-3' => '400|1|rows=2:10,1000:10',
			'1e309' => '400|1|rows=2:10,1000:10',
			'-0' => '400|1|rows=2:10,1000:10',
			'0' => '400|1|rows=2:10,1000:10',
			'400 digits (number)' => '400|1|rows=2:10,1000:10',
			'"2"' => '204|1|rows=2:7,1000:10',
			'"-2"' => '400|1|rows=2:10,1000:10',
			'"+2"' => '204|1|rows=2:7,1000:10',
			'"2.5"' => '400|1|rows=2:10,1000:10',
			'".5"' => '400|1|rows=2:10,1000:10',
			'"5."' => '400|1|rows=2:10,1000:10',
			'"1e3"' => '400|1|rows=2:10,1000:10',
			'"1E3"' => '400|1|rows=2:10,1000:10',
			'"1e-3"' => '400|1|rows=2:10,1000:10',
			'" 2"' => '204|1|rows=2:7,1000:10',
			'"2 "' => '204|1|rows=2:7,1000:10',
			'"\\t2"' => '204|1|rows=2:7,1000:10',
			'"\\n2"' => '204|1|rows=2:7,1000:10',
			'"\\u00a02"' => '400|1|rows=2:10,1000:10',
			'"2\\u00a0"' => '400|1|rows=2:10,1000:10',
			'"0x1A"' => '400|1|rows=2:10,1000:10',
			'"1_000"' => '400|1|rows=2:10,1000:10',
			'""' => '400|1|rows=2:10,1000:10',
			'"abc"' => '400|1|rows=2:10,1000:10',
			'"2abc"' => '400|1|rows=2:10,1000:10',
			'"NaN"' => '400|1|rows=2:10,1000:10',
			'"INF"' => '400|1|rows=2:10,1000:10',
			'"1e309"' => '400|1|rows=2:10,1000:10',
			'"-0"' => '400|1|rows=2:10,1000:10',
			'"0"' => '400|1|rows=2:10,1000:10',
			'"400 digits"' => '400|1|rows=2:10,1000:10',
			'"\\r2"' => '204|1|rows=2:7,1000:10',
			'"\\u000b2"' => '204|1|rows=2:7,1000:10',
			'"\\f2"' => '204|1|rows=2:7,1000:10',
			'"1\\u00002"' => '400|1|rows=2:10,1000:10',
			'"2\\u0001"' => '400|1|rows=2:10,1000:10',
			'"&#50;"' => '400|1|rows=2:10,1000:10',
			'"<b>2<\\/b>"' => '400|1|rows=2:10,1000:10',
			'"\\uff12"' => '400|1|rows=2:10,1000:10',
			'"1\\u000b2"' => '400|1|rows=2:10,1000:10',
			'"1\\f2"' => '400|1|rows=2:10,1000:10',
			'"1\\u00012"' => '400|1|rows=2:10,1000:10',
			'"1<x>2"' => '400|1|rows=2:10,1000:10',
			'"1<!-- c -->2"' => '400|1|rows=2:10,1000:10',
			'"&#49;&#50;"' => '400|1|rows=2:10,1000:10',
			'"02"' => '204|1|rows=2:7,1000:10',
			'"2.0"' => '400|1|rows=2:10,1000:10',
			'"2e0"' => '400|1|rows=2:10,1000:10',
			'2147483648' => '400|1|rows=2:10,1000:10',
			'99999999999' => '400|1|rows=2:10,1000:10',
			'"99999999999"' => '400|1|rows=2:10,1000:10',
			'-1e309' => '400|1|rows=2:10,1000:10',
			'-2147483648' => '400|1|rows=2:10,1000:10',
		],
		'users.permission_id' => [
			'2' => '204|1|granted=2',
			'-2' => '400|1|granted=-',
			'2.5' => '400|1|granted=-',
			'1e3' => '400|1|granted=-',
			'1E3' => '400|1|granted=-',
			'1e-3' => '400|1|granted=-',
			'1e309' => '400|1|granted=-',
			'-0' => '400|1|granted=-',
			'0' => '400|1|granted=-',
			'400 digits (number)' => '400|1|granted=-',
			'"2"' => '204|1|granted=2',
			'"-2"' => '400|1|granted=-',
			'"+2"' => '400|1|granted=-',
			'"2.5"' => '400|1|granted=-',
			'".5"' => '400|1|granted=-',
			'"5."' => '400|1|granted=-',
			'"1e3"' => '400|1|granted=-',
			'"1E3"' => '400|1|granted=-',
			'"1e-3"' => '400|1|granted=-',
			'" 2"' => '400|1|granted=-',
			'"2 "' => '400|1|granted=-',
			'"\\t2"' => '400|1|granted=-',
			'"\\n2"' => '400|1|granted=-',
			'"\\u00a02"' => '400|1|granted=-',
			'"2\\u00a0"' => '400|1|granted=-',
			'"0x1A"' => '400|1|granted=-',
			'"1_000"' => '400|1|granted=-',
			'""' => '400|1|granted=-',
			'"abc"' => '400|1|granted=-',
			'"2abc"' => '400|1|granted=-',
			'"NaN"' => '400|1|granted=-',
			'"INF"' => '400|1|granted=-',
			'"1e309"' => '400|1|granted=-',
			'"-0"' => '400|1|granted=-',
			'"0"' => '400|1|granted=-',
			'"400 digits"' => '400|1|granted=-',
			'"\\r2"' => '400|1|granted=-',
			'"\\u000b2"' => '400|1|granted=-',
			'"\\f2"' => '400|1|granted=-',
			'"1\\u00002"' => '400|1|granted=-',
			'"2\\u0001"' => '400|1|granted=-',
			'"&#50;"' => '400|1|granted=-',
			'"<b>2<\\/b>"' => '400|1|granted=-',
			'"\\uff12"' => '400|1|granted=-',
			'"1\\u000b2"' => '400|1|granted=-',
			'"1\\f2"' => '400|1|granted=-',
			'"1\\u00012"' => '400|1|granted=-',
			'"1<x>2"' => '400|1|granted=-',
			'"1<!-- c -->2"' => '400|1|granted=-',
			'"&#49;&#50;"' => '400|1|granted=-',
			'"02"' => '400|1|granted=-',
			'"2.0"' => '400|1|granted=-',
			'"2e0"' => '400|1|granted=-',
			'2147483648' => '400|1|granted=-',
			'99999999999' => '400|1|granted=-',
			'"99999999999"' => '400|1|granted=-',
			'-1e309' => '400|1|granted=-',
			'-2147483648' => '400|1|granted=-',
		],
	];


	private static PDO $db;
	private static string $apiKey;
	private static int $quId;

	/** @var array<string, array<string, string>> field => input => "status|guard|observed" */
	private static array $observed = [];

	/** @var array<string, array<string, string>> field => input => server message, for the failure report only */
	private static array $messages = [];

	/** Creates the admin API key, a quantity unit, and the fixed products and recipes 2 and 1000. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec('INSERT INTO users(id, username, password) VALUES (' . self::USER_ID . ", 'numeric-string-acceptance', 'fixture')");
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . ', id FROM permission_hierarchy WHERE name = ?');
		$statement->execute(['ADMIN']);

		self::$apiKey = bin2hex(random_bytes(25));
		$keyStatement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$keyStatement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), self::USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$quId = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('NumPiece', 'NumPieces') RETURNING id")->fetchColumn();

		// Two products with known ids, so a product_id field read as 2 or as 1000 ("1e3") can
		// land on a real product and the stored value says how the id was read.
		self::$db->exec("SELECT setval(pg_get_serial_sequence('products', 'id'), 5000)");
		$locationId = self::insertRow('locations', ['name' => 'NumLocation Fixed']);
		foreach ([2, 1000] as $id)
		{
			self::$db->prepare('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, ?, ?, ?)')
				->execute([$id, "NumFixedProduct $id", $locationId, self::$quId, self::$quId]);
		}

		// The same two ids as recipes, for consume's recipe_id: it must name an existing recipe
		// (#987), and a value read as 2 or as 1000 still shows which spelling was accepted.
		self::$db->exec("SELECT setval(pg_get_serial_sequence('recipes', 'id'), 5000)");
		foreach ([2, 1000] as $id)
		{
			self::$db->prepare('INSERT INTO recipes (id, name) VALUES (?, ?)')->execute([$id, "NumFixedRecipe $id"]);
		}
	}

	// ------------------------------------------------------------------------------
	// Inputs
	// ------------------------------------------------------------------------------

	/** @return array<string, string> raw JSON literal => raw JSON literal */
	private static function Inputs(): array
	{
		$raw = ['2', '-2', '2.5', '1e3', '1E3', '1e-3', '1e309', '-0', '0', str_repeat('9', 400)];
		$strings = [
			'2', '-2', '+2', '2.5', '.5', '5.', '1e3', '1E3', '1e-3', ' 2', '2 ', "\t2", "\n2", "\u{a0}2", "2\u{a0}",
			'0x1A', '1_000', '', 'abc', '2abc', 'NaN', 'INF', '1e309', '-0', '0', str_repeat('9', 400),
			// Control characters, markup and entities, because the request body goes through
			// HTMLPurifier before any controller reads it: whatever it strips or decodes is
			// part of the grammar the wire accepts.
			"\r2", "\x0B2", "\f2", "1\x002", "2\x01", '&#50;', '<b>2</b>', "\u{ff12}",
			// The rewrites #946's reproduction found granting permission 12: characters and
			// markup the purifier removes from between two digits, and entities it decodes.
			"1\x0B2", "1\f2", "1\x012", '1<x>2', '1<!-- c -->2', '&#49;&#50;',
			'02', '2.0', '2e0',
		];
		$inputs = [];
		foreach ($raw as $literal)
		{
			$inputs[$literal === str_repeat('9', 400) ? '400 digits (number)' : $literal] = $literal;
		}
		foreach ($strings as $string)
		{
			$label = $string === str_repeat('9', 400) ? '"400 digits"' : json_encode($string);
			$inputs[$label] = json_encode($string);
		}

		return $inputs;
	}

	// ------------------------------------------------------------------------------
	// HTTP + fixture helpers (same pattern as StockPurchaseInventoryInputTest.php)
	// ------------------------------------------------------------------------------

	/** Sends one request with the raw JSON body through the subprocess helper and returns its status and body. */
	private static function Request(string $method, string $path, string $rawBody): array
	{
		$spec = [
			'method' => $method,
			'path' => $path,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey, 'Content-Type' => 'application/json'],
			'rawBody' => $rawBody,
		];

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

	/** Inserts one row and returns its id. */
	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/** Returns the first column of the first row as a string, or "-" when there is none. */
	private static function scalar(string $sql, array $params = []): string
	{
		$statement = self::$db->prepare($sql);
		$statement->execute($params);
		$value = $statement->fetchColumn();

		return $value === false || $value === null ? '-' : (string)$value;
	}

	private static int $counter = 0;

	/** @return array{0: int, 1: int} [locationId, productId], both fresh */
	private static function freshProduct(): array
	{
		self::$counter++;
		$locationId = self::insertRow('locations', ['name' => 'NumLocation ' . self::$counter]);
		$productId = self::insertRow('products', [
			'name' => 'NumProduct ' . self::$counter,
			'location_id' => $locationId,
			'qu_id_purchase' => self::$quId,
			'qu_id_stock' => self::$quId,
		]);

		return [$locationId, $productId];
	}

	/** Inserts one stock entry for the product and returns its id. */
	private static function insertStock(int $productId, int $locationId, float $amount, array $overrides = []): int
	{
		self::$counter++;

		return self::insertRow('stock', array_merge([
			'product_id' => $productId,
			'amount' => $amount,
			'stock_id' => 'num-stock-' . self::$counter,
			'best_before_date' => date('Y-m-d', strtotime('+30 days')),
			'purchased_date' => date('Y-m-d'),
			'location_id' => $locationId,
		], $overrides));
	}

	/** Row count, summed amount and prices of a product's stock, as text. */
	private static function stockState(int $productId): string
	{
		return 'rows=' . self::scalar('SELECT count(*) FROM stock WHERE product_id = ?', [$productId])
			. ' sum=' . self::scalar('SELECT sum(amount)::text FROM stock WHERE product_id = ?', [$productId])
			. ' price=' . self::scalar("SELECT string_agg(coalesce(price::text, 'null'), ',' ORDER BY id) FROM stock WHERE product_id = ?", [$productId]);
	}

	// ------------------------------------------------------------------------------
	// One row per field
	// ------------------------------------------------------------------------------

	/**
	 * Each entry: method, path template (placeholders are filled from setup()'s context),
	 * body template (%V% is the raw JSON literal), setup, observe, the message that
	 * identifies a refusal by the field's own numeric test (null where the field has none)
	 * and, where the field is a RequireNumericAmount() caller, the pattern the document
	 * must give its string branch.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function Fields(): array
	{
		$guardMessage = 'must be a number';

		$stockSetup = fn (float $amount) => function () use ($amount): array
		{
			[$locationId, $productId] = self::freshProduct();
			$entryId = self::insertStock($productId, $locationId, $amount);

			return ['product' => $productId, 'location' => $locationId, 'entry' => $entryId];
		};
		$noStockSetup = function (): array
		{
			[$locationId, $productId] = self::freshProduct();

			return ['product' => $productId, 'location' => $locationId];
		};
		$stateObserve = fn (array $c) => self::stockState($c['product']);

		return [
			'add.amount' => [
				'POST', '/api/stock/products/{product}/add', '{"amount":%V%}', $noStockSetup, $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'add.price' => [
				'POST', '/api/stock/products/{product}/add', '{"amount":1,"price":%V%}', $noStockSetup, $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'add.stock_label_type' => [
				'POST', '/api/stock/products/{product}/add', '{"amount":2,"stock_label_type":%V%}', $noStockSetup, $stateObserve, null, null,
			],
			'add.by-barcode.amount' => [
				'POST', '/api/stock/products/by-barcode/{barcode}/add', '{"amount":%V%}',
				function (): array
				{
					[$locationId, $productId] = self::freshProduct();
					$barcode = 'num-barcode-' . $productId;
					self::insertRow('product_barcodes', ['product_id' => $productId, 'barcode' => $barcode]);

					return ['product' => $productId, 'barcode' => $barcode];
				},
				$stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'consume.amount' => [
				'POST', '/api/stock/products/{product}/consume', '{"amount":%V%}', $stockSetup(100.0), $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'consume.recipe_id' => [
				'POST', '/api/stock/products/{product}/consume', '{"amount":1,"recipe_id":%V%}', $stockSetup(10.0),
				fn (array $c) => self::stockState($c['product']) . ' recipe_id=' . self::scalar("SELECT string_agg(coalesce(recipe_id::text, 'null'), ',') FROM stock_log WHERE product_id = ? AND transaction_type = 'consume'", [$c['product']]),
				null, null,
			],
			// #950 observed the by-barcode consume route by probe only; it delegates to
			// ConsumeProduct, and a few inputs pin that it keeps doing so.
			'consume.by-barcode.recipe_id' => [
				'POST', '/api/stock/products/by-barcode/{barcode}/consume', '{"amount":1,"recipe_id":%V%}',
				function () use ($stockSetup): array
				{
					$context = $stockSetup(10.0)();
					$context['barcode'] = 'num-consume-barcode-' . $context['product'];
					self::insertRow('product_barcodes', ['product_id' => $context['product'], 'barcode' => $context['barcode']]);

					return $context;
				},
				fn (array $c) => self::stockState($c['product']) . ' recipe_id=' . self::scalar("SELECT string_agg(coalesce(recipe_id::text, 'null'), ',') FROM stock_log WHERE product_id = ? AND transaction_type = 'consume'", [$c['product']]),
				null, null,
			],
			'edit.amount' => [
				'PUT', '/api/stock/entry/{entry}', '{"amount":%V%}', $stockSetup(5.0), $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'edit.price' => [
				'PUT', '/api/stock/entry/{entry}', '{"amount":5,"price":%V%}',
				function (): array
				{
					[$locationId, $productId] = self::freshProduct();

					return ['product' => $productId, 'entry' => self::insertStock($productId, $locationId, 5.0, ['price' => 1.5])];
				},
				$stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'inventory.new_amount' => [
				'POST', '/api/stock/products/{product}/inventory', '{"new_amount":%V%}', $stockSetup(5.0), $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'inventory.price' => [
				'POST', '/api/stock/products/{product}/inventory', '{"new_amount":3,"price":%V%}', $noStockSetup, $stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'inventory.stock_label_type' => [
				'POST', '/api/stock/products/{product}/inventory', '{"new_amount":2,"stock_label_type":%V%}', $noStockSetup, $stateObserve, null, null,
			],
			'open.amount' => [
				'POST', '/api/stock/products/{product}/open', '{"amount":%V%}', $stockSetup(100.0),
				fn (array $c) => self::stockState($c['product']) . ' open=' . self::scalar('SELECT sum(amount)::text FROM stock WHERE product_id = ? AND open = 1', [$c['product']]),
				$guardMessage, self::NUMERIC_PATTERN,
			],
			'transfer.amount' => [
				'POST', '/api/stock/products/{product}/transfer', '{"amount":%V%,"location_id_from":%FROM%,"location_id_to":%TO%}',
				function (): array
				{
					[$locationId, $productId] = self::freshProduct();
					self::insertStock($productId, $locationId, 100.0);

					return ['product' => $productId, 'location' => $locationId, 'to' => self::insertRow('locations', ['name' => 'NumTarget ' . $productId])];
				},
				fn (array $c) => 'from=' . self::scalar('SELECT sum(amount)::text FROM stock WHERE product_id = ? AND location_id = ?', [$c['product'], $c['location']])
					. ' to=' . self::scalar('SELECT sum(amount)::text FROM stock WHERE product_id = ? AND location_id = ?', [$c['product'], $c['to']]),
				$guardMessage, self::NUMERIC_PATTERN,
			],
			'weigh.gross_amount' => [
				'POST', '/api/stock/locations/{location}/weigh', '{"gross_amount":%V%,"best_before_date":"2999-12-31"}',
				function (): array
				{
					[$unused, $productId] = self::freshProduct();
					$vesselId = self::insertRow('locations', ['name' => 'NumVessel ' . $productId, 'tare_weight' => 1.0, 'tare_qu_id' => self::$quId]);
					self::insertStock($productId, $vesselId, 10.0);

					return ['product' => $productId, 'location' => $vesselId];
				},
				$stateObserve, $guardMessage, self::NUMERIC_PATTERN,
			],
			'measure.amount' => [
				'POST', '/api/stock/entry/{entry}/measure', '{"amount":%V%,"qu_id":%QU%}',
				function (): array
				{
					[$locationId, $productId] = self::freshProduct();

					return ['product' => $productId, 'entry' => self::insertStock($productId, $locationId, 1.0, ['open' => 1, 'opened_date' => date('Y-m-d')])];
				},
				fn (array $c) => 'opened_amount=' . self::scalar('SELECT opened_amount::text FROM stock WHERE id = ?', [$c['entry']]),
				null, self::NUMERIC_PATTERN, true,
			],
			'shoppinglist.add.product_amount' => [
				'POST', '/api/stock/shoppinglist/add-product', '{"product_id":{product},"product_amount":%V%}', $noStockSetup,
				fn (array $c) => 'rows=' . self::scalar('SELECT string_agg(amount::text, \',\') FROM shopping_list WHERE product_id = ?', [$c['product']]),
				null, null,
			],
			'shoppinglist.add.product_id' => [
				'POST', '/api/stock/shoppinglist/add-product', '{"product_id":%V%,"product_amount":3}',
				function (): array
				{
					self::$db->exec('DELETE FROM shopping_list');

					return [];
				},
				fn (array $c) => 'rows=' . self::scalar("SELECT string_agg(product_id::text || ':' || amount::text, ',' ORDER BY id) FROM shopping_list"),
				null, null,
			],
			'shoppinglist.remove.product_amount' => [
				'POST', '/api/stock/shoppinglist/remove-product', '{"product_id":{product},"product_amount":%V%}',
				function (): array
				{
					[$unused, $productId] = self::freshProduct();
					self::insertRow('shopping_list', ['product_id' => $productId, 'amount' => 10.0]);

					return ['product' => $productId];
				},
				fn (array $c) => 'rows=' . self::scalar("SELECT string_agg(amount::text, ',') FROM shopping_list WHERE product_id = ?", [$c['product']]),
				null, null,
			],
			'shoppinglist.remove.product_id' => [
				'POST', '/api/stock/shoppinglist/remove-product', '{"product_id":%V%,"product_amount":3}',
				function (): array
				{
					self::$db->exec('DELETE FROM shopping_list');
					foreach ([2, 1000] as $id)
					{
						self::insertRow('shopping_list', ['product_id' => $id, 'amount' => 10.0]);
					}

					return [];
				},
				fn (array $c) => 'rows=' . self::scalar("SELECT string_agg(product_id::text || ':' || amount::text, ',' ORDER BY id) FROM shopping_list"),
				null, null,
			],
			'users.permission_id' => [
				'POST', '/api/users/{user}/permissions', '{"permission_id":%V%}',
				function (): array
				{
					self::$counter++;
					$userId = 20000 + self::$counter;
					self::$db->exec("INSERT INTO users(id, username, password) VALUES ($userId, 'num-target-" . self::$counter . "', 'fixture')");

					return ['user' => $userId];
				},
				fn (array $c) => 'granted=' . self::scalar("SELECT string_agg(permission_id::text, ',' ORDER BY permission_id) FROM user_permissions WHERE user_id = ?", [$c['user']]),
				null, null,
			],
		];
	}

	/** @return array<string, array{0: string}> */
	public static function fieldProvider(): array
	{
		$names = [];
		foreach (array_keys(self::Fields()) as $name)
		{
			$names[$name] = [$name];
		}

		return $names;
	}

	/** The by-barcode wrapper only delegates, so a few inputs show that it does. */
	private static function InputsFor(string $field): array
	{
		$inputs = self::Inputs();
		if ($field === 'add.by-barcode.amount')
		{
			return array_intersect_key($inputs, array_flip(['2', '"2"', '" 2"', '"abc"', '1e309', '"1e3"']));
		}
		if ($field === 'consume.by-barcode.recipe_id')
		{
			$inputs['-1e309'] = '-1e309';

			return array_intersect_key($inputs, array_flip(['2', '"2"', '"2 "', '"\\u00a02"', '1e309', '-1e309', '400 digits (number)', '"abc"']));
		}

		// Integer-valued fields: a value outside a 32-bit integer is a valid number to PHP and
		// is refused (or not) further down, so the document has to say which.
		if (in_array($field, ['consume.recipe_id', 'shoppinglist.add.product_id', 'shoppinglist.remove.product_id', 'users.permission_id'], true))
		{
			$inputs['2147483648'] = '2147483648';
			$inputs['99999999999'] = '99999999999';
			$inputs['"99999999999"'] = '"99999999999"';
			// Negative extremes #950 probed: an infinite double and the 32-bit minimum.
			$inputs['-1e309'] = '-1e309';
			$inputs['-2147483648'] = '-2147483648';
			if ($field === 'consume.recipe_id')
			{
				$inputs['3'] = '3';
			}
		}

		// A price of null is "no price" (or, on edit, "clear it"), which the document says.
		if (in_array($field, ['add.price', 'edit.price', 'inventory.price'], true))
		{
			$inputs['null'] = 'null';
		}

		return $inputs;
	}

	// ------------------------------------------------------------------------------
	// Characterisation
	// ------------------------------------------------------------------------------

	/** Every input to the field answers its pinned status and leaves its pinned stored state. */
	#[DataProvider('fieldProvider')]
	public function testFieldAcceptance(string $field): void
	{
		[$method, $pathTemplate, $bodyTemplate, $setup, $observe, $guardMessage] = self::Fields()[$field];

		foreach (self::InputsFor($field) as $label => $literal)
		{
			$context = $setup();
			$path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string)$context[$m[1]], $pathTemplate);
			$body = str_replace(
				['%V%', '%FROM%', '%TO%', '%QU%'],
				[$literal, (string)($context['location'] ?? 0), (string)($context['to'] ?? 0), (string)self::$quId],
				preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string)$context[$m[1]], $bodyTemplate)
			);

			$result = self::Request($method, $path, $body);
			$decoded = json_decode((string)$result['body'], true);
			$message = is_array($decoded) ? (string)($decoded['error_message'] ?? '') : (string)$result['body'];
			$guard = ($guardMessage !== null && $result['status'] === 400 && str_contains($message, $guardMessage)) ? 0 : 1;

			self::$observed[$field][$label] = $result['status'] . '|' . $guard . '|' . $observe($context);
			self::$messages[$field][$label] = $message;
		}

		$expected = self::EXPECTED[$field] ?? [];
		if ($expected === [])
		{
			$lines = [];
			foreach (self::$observed[$field] as $label => $cell)
			{
				$lines[] = "\t\t'" . addcslashes($label, "'\\") . "' => '" . addcslashes($cell, "'\\") . "', // " . self::$messages[$field][$label];
			}
			self::fail("No expectation recorded for $field. Observed:\n'$field' => [\n" . implode("\n", $lines) . "\n],");
		}

		foreach (self::$observed[$field] as $label => $cell)
		{
			self::assertSame($expected[$label] ?? '(unpinned)', $cell, "$field with body value $label: " . self::$messages[$field][$label]);
		}
		self::assertSame(array_keys($expected), array_keys(self::$observed[$field]), "$field: the set of inputs changed");
	}

	// ------------------------------------------------------------------------------
	// The document against the runtime
	// ------------------------------------------------------------------------------

	/**
	 * For every field whose string branch the document gives a pattern: a string input the
	 * field's numeric test passed must match that pattern, and one it refused must not.
	 * Number inputs are not part of the comparison, because a JSON number never reaches the
	 * string test. The pattern is read from the document, not from this class, so editing
	 * either side alone fails here.
	 */
	#[DataProvider('fieldProvider')]
	public function testDocumentedPatternMatchesRuntime(string $field): void
	{
		$pattern = self::Fields()[$field][6];
		if ($pattern === null)
		{
			self::markTestSkipped("$field has no numeric-string pattern in the document");
		}

		$expected = self::EXPECTED[$field] ?? [];
		self::assertNotSame([], $expected, "$field has no pinned behaviour");

		// Every route that carries the field, not only the first: the by-barcode and
		// by-label wrappers have their own copy of the schema.
		foreach (self::DocumentedSchemas($field) as $openApiPath => $schema)
		{
			self::assertNotNull($schema, "the document gives $field on $openApiPath no oneOf with a string branch");
			$branches = array_values(array_filter($schema['oneOf'], fn ($b) => ($b['type'] ?? null) === 'string'));
			self::assertCount(1, $branches, "$field on $openApiPath: exactly one string branch expected");
			self::assertSame($pattern, $branches[0]['pattern'] ?? null, "$field on $openApiPath: the document's pattern");

			// A price of null or "" means "no price" (or, on edit, "clear it"), so the schema
			// must name both beside the number and the string.
			if (str_ends_with($field, 'price'))
			{
				self::assertContains(['type' => 'null'], $schema['oneOf'], "$field on $openApiPath: null branch");
				self::assertContains(['const' => ''], $schema['oneOf'], "$field on $openApiPath: empty string branch");
			}
		}

		// measure.amount has one refusal message for "not numeric" and "not positive", so its
		// guard flag is always 1; for it only "accepted implies the pattern matches" and
		// "the pattern does not match implies refused" can be asserted.
		$directional = self::Fields()[$field][7] ?? false;

		foreach ($expected as $label => $cell)
		{
			$label = (string)$label;
			if ($label[0] !== '"')
			{
				continue;
			}
			$string = json_decode($label === '"400 digits"' ? '"' . str_repeat('9', 400) . '"' : $label);
			[$status, $guard] = explode('|', $cell, 3);
			$matches = preg_match('/' . str_replace('/', '\/', $pattern) . '/D', $string) === 1;
			// The schema's const "" branch (asserted above) covers the empty price.
			$matches = $matches || ($string === '' && str_ends_with($field, 'price'));
			if ($directional)
			{
				self::assertTrue($matches || $status !== '200', "$field with $label: accepted, but the documented pattern does not match");
				continue;
			}
			self::assertSame($guard === '1', $matches, "$field with $label: runtime guard=$guard, pattern says otherwise");
		}
	}

	/**
	 * The request-body property schema for $field on every route that carries it, per
	 * victual.openapi.json, keyed by document path.
	 *
	 * @return array<string, array|null>
	 */
	private static function DocumentedSchemas(string $field): array
	{
		[$method, $pathTemplate] = self::Fields()[$field];
		$property = substr($field, strrpos($field, '.') + 1);
		$paths = [substr($pathTemplate, strlen('/api'))];
		if (str_starts_with($pathTemplate, '/api/stock/products/{product}/'))
		{
			$paths[] = '/stock/products/by-barcode/{barcode}/' . substr($pathTemplate, strlen('/api/stock/products/{product}/'));
		}
		if ($pathTemplate === '/api/stock/locations/{location}/weigh')
		{
			$paths[] = '/stock/locations/by-label/{code}/weigh';
		}

		$document = json_decode((string)file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		$schemas = [];
		foreach (array_unique($paths) as $path)
		{
			$openApiPath = preg_replace_callback('/\{(\w+)\}/', fn ($m) => '{' . self::OPENAPI_PARAMETER[$m[1]] . '}', $path);
			$operation = $document['paths'][$openApiPath][strtolower($method)] ?? null;
			$schema = $operation['requestBody']['content']['application/json']['schema']['properties'][$property] ?? null;
			$schemas[$openApiPath] = is_array($schema) && isset($schema['oneOf']) ? $schema : null;
		}

		return $schemas;
	}

	private const OPENAPI_PARAMETER = [
		'product' => 'productId',
		'entry' => 'entryId',
		'location' => 'locationId',
		'barcode' => 'barcode',
		'code' => 'code',
		'user' => 'userId',
	];
}
