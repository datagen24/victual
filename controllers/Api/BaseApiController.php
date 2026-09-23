<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Controllers\BaseController;
use Victual\Services\DatabaseService;
use Victual\Services\Database\DatabaseDialect;
use Victual\Services\FieldPolicy;
use Victual\Services\WireBooleans;
use Victual\Services\Storage\FileTooLargeException;
use LessQL\Result;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Exception\HttpSpecializedException;

/**
 * Base class for all REST API controllers (everything below /api).
 *
 * Provides the JSON response helpers, the generic filtering/pagination/ordering
 * applied to list endpoints (query/limit/offset/order query parameters) and the
 * HTMLPurifier-based request body parsing/sanitization.
 */
class BaseApiController extends BaseController
{
	const PATTERN_FIELD = '[A-Za-z_][A-Za-z0-9_]+';
	const PATTERN_OPERATOR = '!?((>=)|(<=)|=|~|<|>|(§))';
	const PATTERN_VALUE = '[A-Za-z\p{L}\p{M}0-9*_.$#^| -\\\]+';

	protected $OpenApiSpec = null;

	/**
	 * Writes $data JSON-encoded to the response body; with $cache = true a 30 day Cache-Control header is added.
	 */
	protected function ApiResponse(Response $response, $data, $cache = false)
	{
		if ($cache)
		{
			$response = $response->withHeader('Cache-Control', 'max-age=2592000');
		}

		$response->getBody()->write(json_encode($data));
		return $response;
	}

	/**
	 * Returns a bodyless response with the given status code (default 204 No Content).
	 */
	protected function EmptyApiResponse(Response $response, $status = 204)
	{
		return $response->withStatus($status);
	}

	/**
	 * Returns a JSON error body of the shape { "error_message": string } with the given status code (default 400).
	 *
	 * A driver message never reaches the caller. Most controller methods are written as
	 * `catch (\Exception $ex) { return $this->GenericErrorResponse($response, $ex->getMessage()); }`,
	 * and `PDOException` is an `\Exception`, so every one of them was a way for the
	 * database's own words - SQLSTATE, column types, the engine, and the failing
	 * statement quoted back with the caller's value in it - to be answered as a 400. That
	 * is the same leak MaterialiseFiltered() refuses one layer down, and issue #48 is
	 * where it was found reaching a household's browser console.
	 *
	 * Sanitising here rather than at the 45 call sites is deliberate: it cannot be
	 * forgotten by the next method written, and it leaves those methods for
	 * docs/plans/11-api-error-handling.md, which owns classifying exceptions properly.
	 * PDO's message format is fixed - it always begins "SQLSTATE[" - so the test is on
	 * the message rather than on a type this function never sees.
	 */
	protected function GenericErrorResponse(Response $response, $errorMessage, $status = 400)
	{
		$response = $response->withStatus($status);

		return $this->ApiResponse($response, [
			'error_message' => self::WithoutDriverText($errorMessage)
		]);
	}

	/**
	 * The message to put on the wire in place of a database driver's own.
	 *
	 * Anything that is not a driver message is returned unchanged.
	 */
	public static function WithoutDriverText($errorMessage)
	{
		if (is_string($errorMessage) && string_starts_with($errorMessage, 'SQLSTATE['))
		{
			return 'The database rejected this request - check that every value it carries suits the field it is for';
		}

		return $errorMessage;
	}

	/**
	 * Runs $work in a transaction opened through DatabaseService::InTransaction(), for
	 * handlers that prepare their own statements on the raw connection.
	 *
	 * Going through InTransaction() rather than PDO::beginTransaction() is what keeps such a
	 * handler inside the application's transaction rules: it joins a transaction that is
	 * already open instead of throwing, and anything registered with
	 * RegisterBeforeOutermostCommit() runs before it commits. A throw rolls back and is
	 * rethrown, so a handler that turns an exception into a 4xx response catches it outside
	 * this call.
	 *
	 * Statements on the raw connection never reach LessQL's change-tracking callback, so a
	 * request that commits and is not a read advances the changed time here. $changesData
	 * overrides the method-based default for the one caller, the label worker, whose POST
	 * routes include polling that is not a change to anything a person sees.
	 *
	 * @return mixed Whatever $work returns
	 */
	protected function InRequestTransaction(Request $request, callable $work, ?bool $changesData = null)
	{
		$database = DatabaseService::GetInstance();
		$result = $database->InTransaction($work);

		if ($changesData ?? !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true))
		{
			$database->MarkDbChanged();
		}

		return $result;
	}

	/**
	 * Runs a controller method's body and turns whatever it throws into the right status
	 * code, so that no controller writes its own `try` again.
	 *
	 * Before this existed every method carried the same
	 * `catch (\Exception $ex) { return $this->GenericErrorResponse(...); }`, which answers
	 * 400 to everything. That is why a permission failure was a 403 or a 400 depending on
	 * whether the check happened to sit above or below the `try` - the same failure, two
	 * status codes, decided by indentation.
	 *
	 * | Caught | Answer |
	 * |---|---|
	 * | HttpSpecializedException (403, 404, 405, ...) | its own code - this is how PermissionMissingException becomes a 403 |
	 * | HttpException | its own code, clamped to 4xx/5xx |
	 * | EObjectNotFound | 404 |
	 * | EInvalidApiQuery | 400 |
	 * | FileTooLargeException | 413 |
	 * | PDOException | 400, with the driver's own words replaced |
	 * | \Exception | 400, exactly as before |
	 *
	 * The PDOException row is the one plan 11 left open when it noted that its drafted
	 * `\Exception` fallback "would have re-opened the leak". It stays a 400 rather than
	 * becoming a 500: the common cause is a request body naming a column that does not
	 * exist, which is the caller's error and not the server's. What closes the leak is not
	 * the status but the message, and GenericErrorResponse() replaces a driver message
	 * whatever route it arrives by (see WithoutDriverText). It therefore needs no catch
	 * clause of its own, and has none.
	 *
	 * \Error is deliberately not caught either, for the opposite reason. A TypeError is
	 * this application being wrong about its own types, and answering 400 to it would file
	 * a bug as a client mistake.
	 */
	protected function HandleApiCall(Response $response, callable $work): Response
	{
		try
		{
			return $work();
		}
		catch (HttpSpecializedException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (HttpException $ex)
		{
			$status = $ex->getCode();

			return $this->GenericErrorResponse($response, $ex->getMessage(), ($status >= 400 && $status <= 599) ? $status : 500);
		}
		catch (EObjectNotFound $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 404);
		}
		catch (EInvalidApiQuery $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), 400);
		}
		catch (FileTooLargeException $ex)
		{
			// 413 rather than the 400 every other failure gets, because "this one was too
			// big" is the one refusal a client can act on by sending less
			return $this->GenericErrorResponse($response, $ex->getMessage(), 413);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * Applies the generic list query parameters (see QueryData) to $data and returns the
	 * result JSON-encoded, with every field the current user may not see (FieldPolicy,
	 * docs/plans/19-rbac.md piece 2) removed from each row first and every property the
	 * OpenAPI document types `boolean` converted from its 0/1 column value (WireBooleans,
	 * issue #230).
	 *
	 * The entity name is read off $data before QueryData()/MaterialiseFiltered() run - a
	 * LessQL Result still names its own table after where()/limit()/orderBy() are chained
	 * onto it, and this is the one place in the generic list path that still has the
	 * Result rather than the bare rows FilterData() and MaterialiseFiltered() work with.
	 */
	public function FilteredApiResponse(Request $request, Response $response, Result $data, array $query)
	{
		$entity = $data->getTable();
		$this->AssertWholeObjectReadable($request, $entity);
		$data = $this->QueryData($request, $data, $query);
		$rows = $this->MaterialiseFiltered($request, $data, $query);
		$rows = FieldPolicy::GetInstance()->RedactRows($entity, $rows);
		$rows = WireBooleans::CoerceRows($entity, $rows);
		return $this->ApiResponse($response, $rows);
	}

	/**
	 * Refuses the whole read with 403 when permission_fields carries a '*' row for $entity
	 * whose permission the current user does not hold (FieldPolicy::WholeObjectPermission).
	 *
	 * The marker means "the whole endpoint is the field" - an entity that carries nothing
	 * but the thing being gated, where filtering the response down to empty objects would
	 * answer 200 to a question the caller may not ask. Enforced here rather than only in
	 * the one controller that has such an entity today, because the policy is a table a
	 * household can add rows to (plan 19 piece 2, Q2's response) and a marker only some of
	 * the read paths consult is a marker that means different things per route. Issue #176
	 * item 6, which found it consulted by none of them.
	 *
	 * StockApiController::ProductPriceHistory keeps its own explicit
	 * CheckPermission(STOCK_PRICES_VIEW) rather than deferring to this: products_price_history
	 * is not an exposed generic entity, so it never reaches this method, and that route's
	 * whole purpose is to serve prices - it should refuse even on a database whose policy
	 * table was emptied.
	 */
	protected function AssertWholeObjectReadable(Request $request, string $entity): void
	{
		$permission = FieldPolicy::GetInstance()->WholeObjectPermission($entity);

		if ($permission !== null)
		{
			User::CheckPermission($request, $permission);
		}
	}

	/**
	 * Runs a filtered/sorted query now rather than leaving it to be executed when the
	 * response is serialised, so that a database rejection of a *client supplied* term
	 * can be answered 400 instead of escaping as an unclassified 500.
	 *
	 * The rule is deliberately about provenance rather than about SQLSTATEs. "?order=nope"
	 * is rejected by SQLite as an unknown column and "?query[]=id~2" by PostgreSQL as an
	 * operator that does not exist for that type, and the list of codes an engine might
	 * choose for "your term made this statement invalid" is not something worth keeping in
	 * sync with two engines. What is knowable here is that the statement was fine until a
	 * query parameter was appended to it: if the caller supplied no "query[]" and no
	 * "order", a PDO failure is the server's and stays a 500.
	 *
	 * Returns the rows rather than the Result: LessQL's Result::jsonSerialize() is itself
	 * fetchAll(), so this changes nothing about the response body.
	 *
	 * When QueryData() left an "offset" unapplied because no "limit" came with it (see its
	 * docblock), it is applied here instead with array_slice() on the materialised rows:
	 * LessQL's own getSuffix() (packages/morris/lessql/src/LessQL/Database.php) only emits
	 * "OFFSET" as a suffix to a "LIMIT" it also emitted, so there is no way to ask it for
	 * one without the other.
	 *
	 * @return \LessQL\Row[]
	 */
	protected function MaterialiseFiltered(Request $request, Result $data, array $query): array
	{
		try
		{
			$rows = $data->fetchAll();
		}
		catch (\PDOException $ex)
		{
			if (!isset($query['query']) && !isset($query['order']))
			{
				throw $ex;
			}

			// Deliberately does not carry the driver's message. It names types, columns and
			// the engine, which is more than a caller needs to fix their request and more
			// than this API says about itself anywhere else.
			throw new HttpException(
				$request,
				'Invalid query: the database rejected the resulting statement - check that every field named in "query" and "order" exists on this entity and that the operator suits its type',
				400,
				$ex
			);
		}

		if (isset($query['offset']) && !isset($query['limit']))
		{
			$rows = array_slice($rows, intval($query['offset']));
		}

		return $rows;
	}

	/** @var array<string, array<string, string>|null> Column types per table, for this request only; null = unreadable */
	private static $ColumnTypeCache = [];

	/**
	 * The column types to validate a caller's fields against, keyed by column name.
	 *
	 * Returns null when the catalogue could not be read. That is deliberately distinct from
	 * an empty array: "I do not know this entity's columns" is not "this entity has no
	 * columns", and it must not be quietly treated as "validated fine". Failing open here
	 * would restore exactly the divergence this validation exists to remove - an invalid
	 * operator answered 200 on SQLite and 500 on PostgreSQL - only now intermittently and
	 * without anything saying so. Callers refuse the request instead; see AssertCanValidate().
	 *
	 * @return array<string, string>|null
	 */
	private function ColumnTypesOf(Result $data): ?array
	{
		$table = $data->getTable();

		if (!array_key_exists($table, self::$ColumnTypeCache))
		{
			$database = DatabaseService::GetInstance();

			try
			{
				self::$ColumnTypeCache[$table] = $database->GetDialect()
					->GetValidationColumnTypes($database->GetDbConnectionRaw(), $table);
			}
			catch (\PDOException $ex)
			{
				// Loud, because this should not happen and silence is what made the
				// original defect survive. error_log rather than a logger because the fork
				// has none yet - that is plan 11's "error logging" half, and this line is
				// one of the things that wants it.
				error_log('Victual: could not read the column types of "' . $table . '" to validate a query filter: ' . $ex->getMessage());

				self::$ColumnTypeCache[$table] = null;
			}
		}

		return self::$ColumnTypeCache[$table];
	}

	/**
	 * Refuses a request whose filter or sort cannot be validated, rather than running it
	 * unvalidated.
	 *
	 * 500 and not 400: the caller has done nothing wrong, the server cannot do its job.
	 * Only reached when the caller actually supplied something needing validation - an
	 * unfiltered list has nothing to check and is served normally whatever the catalogue
	 * is doing.
	 */
	private function AssertCanValidate(Request $request, ?array $columnTypes): array
	{
		if ($columnTypes === null)
		{
			throw new HttpException($request, 'Cannot validate the query: the entity\'s columns are unavailable', 500);
		}

		return $columnTypes;
	}

	/**
	 * Rejects a field a caller named in "query[]" or "order" that the entity does not have,
	 * with 400 rather than the 500 the engine's own complaint would otherwise become - and,
	 * per docs/plans/19-rbac.md piece 2's "filter hole", a field that exists but is redacted
	 * for the current user (FieldPolicy). Without this a caller lacking STOCK_PRICES_VIEW
	 * could binary-search stock.price with "?query[]=price>3&query[]=price<5" even though
	 * the field itself never appears in a response.
	 *
	 * The message distinguishes the two refusals so a caller can tell "this field does not
	 * exist" from "you may not query on this field"; the status code deliberately does not,
	 * both are 400, since a distinct code would itself confirm the field exists.
	 */
	private function AssertFieldExists(Request $request, array $columnTypes, string $field, string $entity): void
	{
		if (!array_key_exists($field, $columnTypes))
		{
			throw new HttpException($request, 'Invalid query: unknown field "' . $field . '"', 400);
		}

		if (in_array($field, FieldPolicy::GetInstance()->RedactedFieldsFor($entity), true))
		{
			throw new HttpException($request, 'Invalid query: field "' . $field . '" may not be used in "query" or "order"', 400);
		}
	}

	/**
	 * Applies the generic list query parameters to a LessQL result:
	 * query[] (filter conditions, see FilterData), limit/offset (pagination)
	 * and order ("field" or "field:asc|desc"; throws on any other sort order).
	 *
	 * "limit" alone, or "limit" with "offset", becomes a LessQL limit()/OFFSET clause as
	 * usual. "offset" without "limit" is left unapplied here - LessQL's own SQL builder
	 * (packages/morris/lessql/src/LessQL/Database.php's getSuffix()) only emits "OFFSET" as
	 * a suffix to a "LIMIT" it also emitted, so there is no sentinel count that means "no
	 * limit" on every engine: -1 is SQLite's spelling and PostgreSQL refuses it outright
	 * ("LIMIT must not be negative"). MaterialiseFiltered() applies that offset instead,
	 * with array_slice() on the fetched rows, once the statement without a LIMIT has run.
	 */
	protected function QueryData(Request $request, Result $data, array $query)
	{
		if (isset($query['query']))
		{
			$data = $this->FilterData($request, $data, $query['query']);
		}

		if (isset($query['limit']))
		{
			$data = $data->limit(intval($query['limit']), intval($query['offset'] ?? 0));
		}

		if (isset($query['order']))
		{
			$parts = explode(':', $query['order']);
			$this->AssertFieldExists($request, $this->AssertCanValidate($request, $this->ColumnTypesOf($data)), $parts[0], $data->getTable());

			if (count($parts) == 1)
			{
				$data = $data->orderBy($parts[0]);
			}
			else
			{
				if ($parts[1] != 'asc' && $parts[1] != 'desc')
				{
					throw new HttpException($request, 'Invalid sort order ' . $parts[1], 400);
				}

				$data = $data->orderBy($parts[0], $parts[1]);
			}
		}

		return $data;
	}

	/**
	 * Applies each query[] filter condition of the form "<field><operator><value>"
	 * (operators =, !=, ~, !~, <, >, <=, >= and § for regex matching; the value
	 * "null" additionally matches SQL NULL) as a WHERE clause to $data.
	 * Throws when a condition does not match the expected pattern.
	 */
	protected function FilterData(Request $request, Result $data, array $query): Result
	{
		$columnTypes = $this->AssertCanValidate($request, $this->ColumnTypesOf($data));
		$entity = $data->getTable();

		foreach ($query as $q)
		{
			$matches = [];
			preg_match(
				'/(?P<field>' . self::PATTERN_FIELD . ')'
				. '(?P<op>' . self::PATTERN_OPERATOR . ')'
				. '(?P<value>' . self::PATTERN_VALUE . ')/u',
				$q,
				$matches
			);

			if (!array_key_exists('field', $matches) || !array_key_exists('op', $matches) || !array_key_exists('value', $matches))
			{
				throw new HttpException($request, 'Invalid query', 400);
			}

			$this->AssertFieldExists($request, $columnTypes, $matches['field'], $entity);

			// The substring and regex operators are the ones that need a string to work on.
			// Rejecting them here, on both engines, is what stops the two disagreeing: left
			// to the engines, SQLite coerces the value to text and matches while PostgreSQL
			// has no such operator for the type and raises. Neither answer is better than
			// the other, but one of them has to be given to both callers, and a filter that
			// silently means different things per engine is the worse outcome of the two.
			// See DatabaseDialect::IsTextMatchableType() for why timestamps are in here too.
			if (in_array($matches['op'], ['~', '!~', '§'], true)
				&& !DatabaseDialect::IsTextMatchableType($columnTypes[$matches['field']]))
			{
				throw new HttpException(
					$request,
					'Invalid query: the "' . $matches['op'] . '" operator needs a text field, and "'
						. $matches['field'] . '" is ' . $columnTypes[$matches['field']],
					400
				);
			}

			$sqlOrNull = '';
			if (strtolower($matches['value']) == 'null')
			{
				$sqlOrNull = ' OR ' . $matches['field'] . ' IS NULL';
			}

			switch ($matches['op'])
			{
				case '=':
					$data = $data->where($matches['field'] . ' = ?' . $sqlOrNull, $matches['value']);
					break;
				case '!=':
					$data = $data->where($matches['field'] . ' != ?' . $sqlOrNull, $matches['value']);
					break;
				case '~':
					// Spelled differently per engine (SQLite's LIKE is case insensitive,
					// PostgreSQL's is not and needs ILIKE), but the API contract is the same
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetLikeCondition($matches['field'], false), '%' . $matches['value'] . '%');
					break;
				case '!~':
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetLikeCondition($matches['field'], true), '%' . $matches['value'] . '%');
					break;
				case '<':
					$data = $data->where($matches['field'] . ' < ?', $matches['value']);
					break;
				case '>':
					$data = $data->where($matches['field'] . ' > ?', $matches['value']);
					break;
				case '>=':
					$data = $data->where($matches['field'] . ' >= ?', $matches['value']);
					break;
				case '<=':
					$data = $data->where($matches['field'] . ' <= ?', $matches['value']);
					break;
				case '§':
					// Spelled differently per engine (SQLite has a REGEXP operator backed by a
					// user defined function, PostgreSQL has "~"), but the API contract is the same
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetRegexpCondition($matches['field']), $matches['value']);
					break;
			}
		}

		return $data;
	}

	/**
	 * Lazily loads and returns victual.openapi.json as an object (also used for entity/file group validation).
	 */
	protected function GetOpenApispec()
	{
		if ($this->OpenApiSpec == null)
		{
			$this->OpenApiSpec = json_decode(file_get_contents(__DIR__ . '/../../victual.openapi.json'));
		}

		return $this->OpenApiSpec;
	}

	private static $htmlPurifierInstance = null;

	/**
	 * Builds the HTMLPurifier every write request runs its body through.
	 *
	 * Public and static because bin/victual-warm-cache builds one too: HTMLPurifier
	 * serialises its HTML, CSS and URI definitions into Cache.SerializerPath the first
	 * time it needs them, and on a read-only baked cache directory that first time has
	 * to happen at build time. The definitions are keyed by the configuration, so the
	 * warmer generating them with a different one would produce a cache the application
	 * then ignores and tries to rewrite - which is why there is one construction site
	 * rather than two that look alike.
	 */
	public static function CreateHtmlPurifier(): \HTMLPurifier
	{
		$htmlPurifierConfig = \HTMLPurifier_Config::createDefault();
		$htmlPurifierConfig->set('Cache.SerializerPath', VICTUAL_VIEWCACHE_PATH);
		// No iframe: HTML.SafeIframe with a "match anything" regexp let a master data
		// editor embed an arbitrary external page in every other user's stock overview.
		// No id: it is not needed by anything that writes these columns and DOM
		// clobbering an element id is a way to confuse the front end. Sweep finding S7.
		$htmlPurifierConfig->set('HTML.Allowed', 'div,b,strong,i,em,u,a[href|title|target],ul,ol,li,p[style],br,span[style],img[style|width|height|alt|src],table[border|width|style],tbody,tr,td,th,blockquote,*[style|class],h1,h2,h3,h4,h5,h6');
		$htmlPurifierConfig->set('CSS.AllowedProperties', 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align,width,height');
		// "data" stays: the editor stores a pasted image as a data URI, and HTMLPurifier
		// only accepts one that really decodes to a JPEG, GIF or PNG.
		$htmlPurifierConfig->set('URI.AllowedSchemes', ['data' => true, 'http' => true, 'https' => true]);
		$htmlPurifierConfig->set('CSS.MaxImgLength', null);

		return new \HTMLPurifier($htmlPurifierConfig);
	}

	/**
	 * Columns whose stored value is rendered as HTML rather than as text, per entity.
	 *
	 * These are the only columns a rich text editor writes to, or that a view or a
	 * viewjs handler passes to `{!! !!}` / `.html()`. Their purified output is stored
	 * exactly as HTMLPurifier produced it. Every other column is text: it is purified
	 * too, but the entity encoding the purifier applies to text is undone again
	 * afterwards, so a name containing "&" stays "&" rather than becoming "&amp;"
	 * everywhere it is displayed.
	 *
	 * Undoing that encoding on an HTML rendered column is what security sweep finding
	 * S1 was: `&lt;script&gt;` arrives as text, survives the purifier as harmless entity
	 * text, and the un-escaping turns it into a live tag that the view then emits raw.
	 */
	const HTML_RENDERED_COLUMNS = [
		'products' => ['description'],
		'recipes' => ['description'],
		'equipment' => ['description'],
		'chores' => ['description'],
		'shopping_lists' => ['description']
	];

	/**
	 * The media type of a request's Content-Type, lowercased and without its parameters, or
	 * the empty string when the header is absent.
	 *
	 * RFC 9110 section 8.3 defines Content-Type as a media type *with optional parameters*,
	 * so "application/json" and "application/json; charset=utf-8" name the same type and a
	 * recipient is expected to parse the field rather than compare it as a string. This
	 * compared it as a string, which refused every write from any client whose HTTP stack
	 * appends a charset - Apple's swift-openapi-runtime does, and cannot be told not to per
	 * request, so the first-party Swift client (ADR-0024) could not book stock at all.
	 * Issue #229.
	 *
	 * The parse is deliberately the same one Slim's own BodyParsingMiddleware does
	 * (getMediaType(): explode on ';', trim, strtolower), because that middleware is what
	 * decides whether the body was parsed as JSON in the first place. Two different answers
	 * to "what type is this?" in one request is how a body arrives parsed and is then
	 * refused for the type it was parsed as.
	 */
	public static function MediaTypeOf(Request $request): string
	{
		return strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
	}

	/**
	 * The instant a booking route was asked to record: $field read out of $requestBody and
	 * normalised to the rendering this API stores, or the current time when the field was
	 * not sent at all.
	 *
	 * The three routes that take one - POST /chores/{id}/execute and
	 * POST /batteries/{id}/charge (tracked_time), POST /tasks/{id}/complete (done_time) -
	 * each used to inline
	 *
	 *     if (array_key_exists('tracked_time', $requestBody) && IsIsoDateTime($value)) { ... }
	 *
	 * where IsIsoDateTime() accepted exactly `Y-m-d H:i:s`. A value in any other rendering
	 * fell through the `if` and the route booked
	 * the current time instead, answering 200 with nothing said about the timestamp being
	 * discarded. An RFC 3339 string - what a client generated from this API's own document
	 * naturally sends, and what victual.openapi.json invited by typing these fields
	 * `format: date-time` until issue #231 - was exactly such a value. See ADR-0028.
	 *
	 * Two rules, and the boundary between them is the presence of the key rather than the
	 * usefulness of the value:
	 *
	 * - **The field is absent**: the current time. This is the documented default and what
	 *   the browser relies on for every "do this now" button.
	 * - **The field is present**: it must parse (ParseApiDateTime names the accepted
	 *   renderings), or the request is refused with 400 naming the field. `null` and the
	 *   empty string are values that are present, so they are refused too - "I sent you
	 *   something you could not use" is never answered by booking a different time.
	 *
	 * @param array $requestBody The parsed request body
	 * @param string $field The body field to read - 'tracked_time' or 'done_time'
	 * @return string The instant to book, as 'Y-m-d H:i:s'
	 */
	protected function RequestedTimestamp(Request $request, array $requestBody, string $field): string
	{
		if (!array_key_exists($field, $requestBody))
		{
			return date('Y-m-d H:i:s');
		}

		$parsed = ParseApiDateTime($requestBody[$field]);

		if ($parsed === null)
		{
			throw new HttpException($request, $this->WhyNotATimestamp($field, $requestBody[$field]), 400);
		}

		return $parsed;
	}

	/**
	 * Why a value was refused, in terms a caller can act on.
	 *
	 * Two refusals reach here and they want different answers. A value of the wrong shape
	 * needs the accepted shapes listed. A value of the *right* shape that is still not a
	 * time - the 30th of February, or an hour the server's zone skipped when daylight saving
	 * began - would be told "expected YYYY-MM-DD HH:MM:SS" about a value that is already
	 * exactly that, which reads as the server being broken rather than as the value being
	 * impossible. API_DATE_TIME_PATTERN is what tells the two apart, and it is the same
	 * expression ParseApiDateTime() gates on and the schemas document.
	 */
	private function WhyNotATimestamp(string $field, $value): string
	{
		if (is_string($value) && preg_match('/' . API_DATE_TIME_PATTERN . '/D', $value) === 1)
		{
			return 'Invalid ' . $field . ': "' . $value . '" has an accepted shape but is not a time in the server\'s '
				. 'time zone - either that date does not exist, or the clock skipped that hour when daylight saving began. '
				. 'Send an instant instead ("2026-09-21T14:30:00Z") to say which moment you mean, or omit the field '
				. 'entirely to record the current time.';
		}

		return 'Invalid ' . $field . ': expected "YYYY-MM-DD HH:MM:SS" (the rendering this API stores, in the server\'s time zone), '
			. '"YYYY-MM-DD" for midnight of that date, or an RFC 3339-shaped date and time such as "2026-09-21T14:30:00Z". '
			. 'Omit the field entirely to record the current time.';
	}

	/**
	 * Returns the parsed JSON request body with all scalar string values run through HTMLPurifier.
	 * Throws a Slim HttpException (status 400) when the Content-Type is not application/json.
	 *
	 * @param string|null $entity Name of the entity the body is written to, when the caller
	 * knows it. Decides which columns are treated as HTML - see HTML_RENDERED_COLUMNS. A
	 * caller that passes nothing gets every column treated as text, which is correct for
	 * every controller other than the generic entity one: none of them writes an HTML column.
	 * @return array|null Null when the body could not be parsed as JSON
	 */
	protected function GetParsedAndFilteredRequestBody($request, ?string $entity = null)
	{
		if (self::MediaTypeOf($request) !== 'application/json')
		{
			throw new HttpException($request, 'Bad Content-Type', 400);
		}

		if (self::$htmlPurifierInstance == null)
		{
			self::$htmlPurifierInstance = self::CreateHtmlPurifier();
		}

		// Indexed only when the caller named an entity: PHP 8.1 deprecates a null array
		// offset, and every controller other than the generic entity one passes null. The
		// notice reached a response body rather than a log the first time a write route
		// taking no entity was driven through tests/Pgsql/request-subprocess-helper.php.
		$htmlColumns = $entity === null ? [] : (self::HTML_RENDERED_COLUMNS[$entity] ?? []);

		$requestBody = $request->getParsedBody();
		foreach ($requestBody as $key => &$value)
		{
			// HTMLPurifier removes boolean values (true/false) and arrays, so explicitly keep them
			// Maybe also possible through HTMLPurifier config (http://htmlpurifier.org/live/configdoc/plain.html)
			//
			// And null, which HTMLPurifier turns into the empty string. That is how a client
			// says "clear this column", and it is an idiom this tree already uses -
			// public/viewjs/productform.js sends picture_file_name: null to remove a picture.
			// On a text column the empty string passed for it, because every reader treats ""
			// and NULL alike; on a nullable *integer* column it does not pass at all, and the
			// insert is refused by the database with a message the client is deliberately not
			// shown. Found writing plan 08's location form, whose parent picker has to be able
			// to say "this location has no parent" - see that plan's Executed section.
			if (!is_bool($value) && !is_array($value) && $value !== null)
			{
				$value = self::$htmlPurifierInstance->purify($value);

				if (!in_array($key, $htmlColumns))
				{
					// A text column is stored as the text that was typed, so that "&" is "&"
					// wherever it is displayed. Every Blade render of these escapes, so text
					// that looks like markup is displayed as text. Never do this to an HTML
					// column (see the constant above).
					//
					// Not a complete defence, and knowingly so: `public/viewjs/mealplan.js`
					// concatenates product and recipe names and meal plan notes into markup
					// it hands to `.html()`, so those text columns still reach an HTML
					// context. Escaping at that sink is plan 12's, which owns those files -
					// see sweep finding S1's "What is still open".
					$value = str_replace('&amp;', '&', $value);
					$value = str_replace('&gt;', '>', $value);
					$value = str_replace('&lt;', '<', $value);
				}
			}
		}

		return $requestBody;
	}
}
