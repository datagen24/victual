<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\TestCase;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\LabelWorkerAuthorization;
use Victual\Services\SessionService;
use Victual\Tests\Support\RouteInventory;

/**
 * Issue #803: the authentication the document advertises is the authentication the
 * middleware enforces. The document used to offer a general query-string API key that no
 * middleware reads, and it declared one scheme where the application accepts a session
 * cookie, a calendar secret and three label credential classes.
 *
 * The runtime is the invariant (ADR-0005), so every assertion reads the runtime side from
 * the code - the session cookie constant, the label route table, the route inventory, the
 * header name app.php defines - and compares the document to it. No database is needed.
 */
class OpenApiSecurityContractTest extends TestCase
{
	/** Label route names that are not worker/renderer operations are not in ROUTE_KEY_TYPES. */
	private const LABEL_SCHEMES = [
		ApiKeyService::API_KEY_TYPE_LABEL_WORKER => 'LabelWorkerKeyAuth',
		ApiKeyService::API_KEY_TYPE_LABEL_VERIFIER => 'LabelVerifierKeyAuth',
		ApiKeyService::API_KEY_TYPE_LABEL_RENDERER => 'LabelRendererKeyAuth',
	];

	private static array $document;

	public static function setUpBeforeClass(): void
	{
		self::$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, flags: JSON_THROW_ON_ERROR);
	}

	private static function schemes(): array
	{
		return self::$document['components']['securitySchemes'];
	}

	/** @return array<string,array> "METHOD /path" => operation object */
	private static function operations(): array
	{
		$operations = [];
		foreach (self::$document['paths'] as $path => $item)
		{
			foreach ($item as $method => $operation)
			{
				if (in_array($method, ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'], true))
				{
					$operations[strtoupper($method) . ' ' . $path] = $operation;
				}
			}
		}
		return $operations;
	}

	/** @return string[] scheme names an operation's own security alternatives reference */
	private static function referencedBy(array $operation): array
	{
		$names = [];
		foreach ($operation['security'] ?? [] as $requirement)
		{
			array_push($names, ...array_keys($requirement));
		}
		return $names;
	}

	/**
	 * app.php defines the header name inside a closure, so the only way to read it without
	 * booting the application is to read the definition itself.
	 */
	private static function apiKeyHeaderName(): string
	{
		$source = file_get_contents(VICTUAL_ROOT_PATH . '/app.php');
		self::assertSame(1, preg_match("/set\\('ApiKeyHeaderName',\\s*function\\s*\\([^)]*\\)\\s*\\{\\s*return '([^']+)';/", $source, $m),
			'app.php no longer defines ApiKeyHeaderName as a literal; update this test to read it');
		return $m[1];
	}

	public function testTheSessionCookieSchemeNamesTheCookieTheApplicationSets(): void
	{
		$scheme = self::schemes()['SessionCookieAuth'] ?? null;
		self::assertNotNull($scheme, 'SessionCookieAuth is not declared');
		self::assertSame('apiKey', $scheme['type']);
		self::assertSame('cookie', $scheme['in']);
		self::assertSame(SessionService::SESSION_COOKIE_NAME, $scheme['name']);
	}

	public function testTheApiKeySchemeNamesTheHeaderTheApplicationReads(): void
	{
		$scheme = self::schemes()['ApiKeyAuth'];
		self::assertSame('header', $scheme['in']);
		self::assertSame(self::apiKeyHeaderName(), $scheme['name']);
		foreach (['LabelWorkerKeyAuth', 'LabelVerifierKeyAuth', 'LabelRendererKeyAuth'] as $name)
		{
			self::assertSame('header', self::schemes()[$name]['in'] ?? null, $name);
			self::assertSame(self::apiKeyHeaderName(), self::schemes()[$name]['name'] ?? null, $name);
		}
	}

	public function testTheOnlyQuerySchemeIsTheCalendarSecretOnTheCalendarFeed(): void
	{
		$query = array_keys(array_filter(self::schemes(), fn (array $s) => ($s['in'] ?? null) === 'query'));
		self::assertSame(['CalendarSecretAuth'], $query, 'no credential but the calendar secret is read from the query string');
		self::assertSame('secret', self::schemes()['CalendarSecretAuth']['name']);

		$users = [];
		foreach (self::operations() as $key => $operation)
		{
			if (in_array('CalendarSecretAuth', self::referencedBy($operation), true))
			{
				$users[] = $key;
			}
		}
		self::assertSame(['GET /calendar/ical'], $users);
		self::assertNotContains('CalendarSecretAuth', array_merge(...array_map(array_keys(...), self::$document['security'])), 'the secret is not a document-wide alternative');
	}

	public function testTheDescriptionDoesNotAdvertiseAQueryParameterApiKey(): void
	{
		$description = self::$document['info']['description'];
		self::assertStringNotContainsString('same named query parameter', $description);
		self::assertDoesNotMatchRegularExpression('/VICTUAL-API-KEY\*?\s+(or|as|in)\s+(a|the|same)[^.]*query/i', $description);
		self::assertStringContainsString('never accepted in the query string', $description);
		self::assertStringContainsString(SessionService::SESSION_COOKIE_NAME, $description);
		self::assertStringContainsString('PlaceHolderManageApiKeysUrl', $description, 'OpenApiController substitutes this placeholder');
	}

	public function testTheDocumentWideSecurityOffersTheHeaderKeyAndTheSession(): void
	{
		self::assertSame([['ApiKeyAuth' => []], ['SessionCookieAuth' => []]], self::$document['security']);
	}

	public function testEveryReferencedSchemeIsDeclared(): void
	{
		$referenced = [];
		foreach (self::$document['security'] as $requirement)
		{
			array_push($referenced, ...array_keys($requirement));
		}
		foreach (self::operations() as $operation)
		{
			array_push($referenced, ...self::referencedBy($operation));
		}
		foreach (array_unique($referenced) as $name)
		{
			self::assertArrayHasKey($name, self::schemes(), "$name is referenced but not declared");
		}
	}

	public function testTheCalendarFeedOffersTheSecretTheKeyAndTheSession(): void
	{
		self::assertSame(
			[['CalendarSecretAuth' => []], ['ApiKeyAuth' => []], ['SessionCookieAuth' => []]],
			self::operations()['GET /calendar/ical']['security'] ?? null
		);
		self::assertArrayNotHasKey('security', self::operations()['GET /calendar/ical/sharing-link'], 'the sharing link does not accept the secret, so it inherits the document default');
	}

	public function testLabelSchemesAreOnExactlyTheOperationsTheMiddlewareGuardsWithThem(): void
	{
		$spec = [];
		foreach (RouteInventory::Api() as $operation)
		{
			$spec[$operation->RouteName ?? ''] = strtoupper($operation->Method) . ' ' . $operation->SpecPath();
		}

		$expected = [];
		foreach (LabelWorkerAuthorization::ROUTE_KEY_TYPES as $route => $types)
		{
			if ($route === 'labels-pair')
			{
				continue;
			}
			self::assertArrayHasKey($route, $spec, "route $route is not registered");
			$expected[$spec[$route]] = array_map(fn (string $t) => self::LABEL_SCHEMES[$t], $types);
		}
		self::assertNotEmpty($expected);

		$actual = [];
		foreach (self::operations() as $key => $operation)
		{
			$labelSchemes = array_values(array_filter(self::referencedBy($operation), fn (string $n) => str_starts_with($n, 'Label')));
			if ($labelSchemes !== [])
			{
				$actual[$key] = $labelSchemes;
			}
		}

		ksort($expected);
		ksort($actual);
		self::assertSame($expected, $actual);

		foreach ($expected as $key => $schemes)
		{
			$requirements = self::operations()[$key]['security'];
			self::assertCount(count($schemes), $requirements, "$key: one alternative per accepted key type");
			self::assertSame([], array_diff(self::referencedBy(self::operations()[$key]), $schemes), "$key offers nothing but its label credential");
		}
	}

	public function testPairingStaysPublic(): void
	{
		self::assertSame([], self::operations()['POST /labels/pair']['security'] ?? null);
	}

	public function testTheArtifactImagePreviewIsAnOrdinaryUserOperation(): void
	{
		$image = null;
		foreach (RouteInventory::Api() as $operation)
		{
			if ($operation->RouteName === 'label-artifact-image')
			{
				$image = $operation;
			}
		}
		self::assertNotNull($image);
		self::assertArrayNotHasKey($image->RouteName, LabelWorkerAuthorization::ROUTE_KEY_TYPES);
		self::assertArrayNotHasKey('security', self::operations()['GET ' . $image->SpecPath()]);
	}
}
