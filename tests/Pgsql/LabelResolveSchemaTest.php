<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\Labels\FieldCatalogue;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Contract verification: the OpenAPI spec's label resolve endpoint must declare
 * all six entity kinds that the server actually resolves.
 *
 * When LabelIdentityService::KINDS changes or the spec's kind enum drifts, this
 * test fails, catching mismatches that client generators and strict validators
 * would reject.
 */
class LabelResolveSchemaTest extends PgsqlSchemaTestCase
{
	private const ADMIN_USER = 9610;
	private const OPERATOR_USER = 9611;

	private const OPERATOR_GRANTS = [User::PERMISSION_MASTER_DATA_EDIT, User::PERMISSION_STOCK_VIEW,
		User::PERMISSION_RECIPES_VIEW, User::PERMISSION_CHORES_VIEW, User::PERMISSION_BATTERIES];

	private const BEST_BEFORE = '2099-12-31';
	private const PURCHASED = '2026-01-01';
	private const RETIRE_STOCK_PRODUCT_NAME = 'Resolve retire test stock product';
	private const ALL_KINDS = ['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'];

	private static PDO $db;
	private static string $operatorKey;
	private static array $targets = [];
	private static array $retireTargets = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::ADMIN_USER . ", 'labelresolve-admin', 'fixture')");
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::OPERATOR_USER . ", 'labelresolve-operator', 'fixture')");

		self::Grant(self::ADMIN_USER, [User::PERMISSION_ADMIN]);
		self::Grant(self::OPERATOR_USER, self::OPERATOR_GRANTS);

		self::$operatorKey = self::IssueUserKey(self::OPERATOR_USER);

		self::SeedTargets();
		self::SeedRetireTargets();
	}

	private static function Grant(int $userId, array $permissions): void
	{
		self::$db->exec('DELETE FROM user_permissions WHERE user_id = ' . $userId);
		$statement = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');

		foreach ($permissions as $name)
		{
			$statement->execute([$userId, $name]);
		}
	}

	private static function IssueUserKey(int $userId): string
	{
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), $userId, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		return $key;
	}

	private static function SeedTargets(): void
	{
		$db = self::$db;
		$db->exec("INSERT INTO locations (name, description) VALUES ('Resolve test shelf', 'Test location')");
		self::$targets['location'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, description) VALUES ('Resolve test product', " . self::$targets['location'] . ", 2, 2, 'Test')");
		self::$targets['product'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id)
			VALUES (" . self::$targets['product'] . ", 3, 'resolve-test-stock', '" . self::BEST_BEFORE . "', '" . self::PURCHASED . "', " . self::$targets['location'] . ')');
		self::$targets['stock_entry'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO recipes (name) VALUES ('Resolve test recipe')");
		self::$targets['recipe'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO chores (name, period_type) VALUES ('Resolve test chore', 'manually')");
		self::$targets['chore'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO batteries (name) VALUES ('Resolve test battery')");
		self::$targets['battery'] = (int)$db->lastInsertId();
	}

	/**
	 * A second set of rows, disjoint from SeedTargets()'s, that testEveryKindsResolveResponseMatchesTheDeclaredSchema()
	 * deletes to retire their labels. Kept separate so retiring one kind never disturbs the
	 * live fixtures the other tests in this class resolve.
	 *
	 * The stock entry's own product is disjoint from self::$targets['product'] too, and from
	 * self::$retireTargets['product']: retirement deletes the stock row directly, never the
	 * product (issue #558 covers a deleted product's resulting null product_name separately),
	 * so nothing here may delete a product any stock row still references.
	 */
	private static function SeedRetireTargets(): void
	{
		$db = self::$db;

		$db->exec("INSERT INTO locations (name, description) VALUES ('Resolve retire test location', 'Retirement test')");
		self::$retireTargets['location'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, description) VALUES ('Resolve retire test product', " . self::$targets['location'] . ", 2, 2, 'Retirement test')");
		self::$retireTargets['product'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, description) VALUES ('" . self::RETIRE_STOCK_PRODUCT_NAME . "', " . self::$targets['location'] . ", 2, 2, 'Retirement test')");
		$stockProductId = (int)$db->lastInsertId();
		$db->exec("INSERT INTO stock (product_id, amount, stock_id, best_before_date, purchased_date, location_id)
			VALUES ($stockProductId, 3, 'resolve-retire-test-stock', '" . self::BEST_BEFORE . "', '" . self::PURCHASED . "', " . self::$targets['location'] . ')');
		self::$retireTargets['stock_entry'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO recipes (name) VALUES ('Resolve retire test recipe')");
		self::$retireTargets['recipe'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO chores (name, period_type) VALUES ('Resolve retire test chore', 'manually')");
		self::$retireTargets['chore'] = (int)$db->lastInsertId();

		$db->exec("INSERT INTO batteries (name) VALUES ('Resolve retire test battery')");
		self::$retireTargets['battery'] = (int)$db->lastInsertId();
	}

	/**
	 * The OpenAPI spec must declare all entity kinds that the server resolves.
	 *
	 * The `resolved` variant declares `kind` as an enum covering all six kinds - their
	 * `target` shape does not differ. `retired` is split into two variants instead,
	 * because stock_entry's snapshot shape does not match the other five kinds' {id, name}
	 * (migrations/0283.pgsql.php's retire_stock_entry_labels() trigger); this test checks
	 * the two retired variants partition LabelIdentityService::KINDS exactly, with no
	 * overlap and no omission, the same guarantee it checks for the resolved variant.
	 */
	public function testOpenApiSpecDeclaresAllLabelKinds(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
		self::assertIsArray($spec, 'OpenAPI spec must be valid JSON');

		$labelResolveOperation = $spec['paths']['/labels/resolve/{code}']['get'];
		self::assertIsArray($labelResolveOperation, 'GET /labels/resolve/{code} must exist in spec');

		$responses = $labelResolveOperation['responses']['200']['content']['application/json']['schema'];
		$variants = $responses['oneOf'];
		self::assertCount(4, $variants, 'Response must have exactly 4 variants: unknown, resolved, retired (five kinds), retired (stock_entry)');

		$resolvedVariant = $variants[1];
		self::assertSame('resolved', $resolvedVariant['properties']['status']['const'], 'Variant 1 must be resolved');
		$resolvedKinds = self::KindsOf($resolvedVariant['properties']['kind']);

		$retiredVariant = $variants[2];
		self::assertSame('retired', $retiredVariant['properties']['status']['const'], 'Variant 2 must be retired (non-stock_entry kinds)');
		$retiredKinds = self::KindsOf($retiredVariant['properties']['kind']);

		$retiredStockEntryVariant = $variants[3];
		self::assertSame('retired', $retiredStockEntryVariant['properties']['status']['const'], 'Variant 3 must be retired (stock_entry)');
		$retiredStockEntryKinds = self::KindsOf($retiredStockEntryVariant['properties']['kind']);

		// Get the server's kinds via reflection to avoid duplicating the list
		$reflection = new \ReflectionClass(LabelIdentityService::class);
		$kindsConstant = $reflection->getConstant('KINDS');
		self::assertIsArray($kindsConstant, 'LabelIdentityService must have KINDS array');

		// assertSame on arrays is order-sensitive; the enum's declaration order carries no
		// meaning, so both sides are sorted before comparing sets.
		$serverKinds = $kindsConstant;
		sort($serverKinds);

		$sortedResolvedKinds = $resolvedKinds;
		sort($sortedResolvedKinds);
		self::assertSame($serverKinds, $sortedResolvedKinds,
			'OpenAPI spec resolved.kind must declare exactly the kinds in LabelIdentityService::KINDS');

		self::assertSame(['stock_entry'], $retiredStockEntryKinds,
			'The stock_entry retired variant must declare kind as exactly stock_entry');

		$nonStockEntryServerKinds = array_values(array_diff($kindsConstant, ['stock_entry']));
		sort($nonStockEntryServerKinds);
		$sortedRetiredKinds = $retiredKinds;
		sort($sortedRetiredKinds);
		self::assertSame($nonStockEntryServerKinds, $sortedRetiredKinds,
			'The non-stock_entry retired variant must declare exactly the five non-stock_entry kinds');

		$combinedRetiredKinds = array_merge($retiredKinds, $retiredStockEntryKinds);
		sort($combinedRetiredKinds);
		self::assertSame($serverKinds, $combinedRetiredKinds,
			'The two retired variants together must declare exactly the kinds in LabelIdentityService::KINDS, with no overlap');
	}

	/**
	 * The kind values a `kind` property schema declares, whether as an `enum` or as a bare
	 * `const`. Fails the test with a clear message rather than reading an undefined array
	 * key - the risk a `kind` schema written as a `$ref` would otherwise carry silently.
	 */
	private static function KindsOf(array $kindSchema): array
	{
		if (isset($kindSchema['enum']))
		{
			return $kindSchema['enum'];
		}

		if (isset($kindSchema['const']))
		{
			return [$kindSchema['const']];
		}

		self::fail('kind property schema is neither enum nor const: ' . json_encode($kindSchema));
	}

	/**
	 * Verify the actual response structure when resolving labels of each kind.
	 */
	public function testResolveLabelReturnsCorrectKindAndTarget(): void
	{
		// Issue labels for each kind
		$labels = [];
		foreach (['location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'] as $kind)
		{
			$targetId = self::$targets[$kind];
			$uid = $this->IssueLabel($kind, $targetId);
			$labels[$kind] = $uid;
		}

		// Resolve each and verify kind and target structure
		foreach ($labels as $kind => $uid)
		{
			$response = $this->ResolveLabel($uid);
			self::assertSame(200, $response['status'], "Resolution of $kind label failed");

			$json = json_decode($response['body'], true);
			self::assertSame('resolved', $json['status'], "$kind label should be resolved");
			self::assertSame($kind, $json['kind'], "$kind label must return correct kind");
			self::assertIsArray($json['target'], "$kind label target must be an object");
			self::assertArrayHasKey('id', $json['target'], "$kind target must have id");
			self::assertArrayHasKey('name', $json['target'], "$kind target must have name");
			self::assertArrayHasKey('path', $json['target'], "$kind target must have path");

			// Verify target.id is the stock row id for stock_entry (not the stock_id string)
			if ($kind === 'stock_entry')
			{
				self::assertIsInt($json['target']['id'], 'stock_entry target.id must be an integer (row id)');
				self::assertSame(self::$targets['stock_entry'], $json['target']['id'],
					'stock_entry target.id must be the stock.id row id');
			}
		}
	}

	/**
	 * Every response the endpoint can actually produce - all six kinds, live and retired -
	 * validates against the operation's declared 200 schema. assertArrayHasKey (the previous
	 * test) confirms a few keys are present; this confirms the whole body is exactly what a
	 * generated client or a strict decoder would accept, which is what caught the stock_entry
	 * retirement snapshot documented as {id, name} while the trigger actually writes
	 * {id, product_name, best_before_date, amount} (migrations/0283.pgsql.php).
	 */
	public function testEveryKindsResolveResponseMatchesTheDeclaredSchema(): void
	{
		foreach (self::ALL_KINDS as $kind)
		{
			$uid = $this->IssueLabel($kind, self::$targets[$kind]);
			$response = $this->ResolveLabel($uid);
			self::assertSame(200, $response['status'], "live $kind: " . $response['body']);

			$json = json_decode($response['body'], true);
			self::assertSame('resolved', $json['status'], "live $kind should resolve: " . $response['body']);

			$failure = self::ValidateAgainstResolveSchema($json);
			self::assertNull($failure, "live $kind response failed schema validation ($failure): " . $response['body']);
		}

		foreach (self::ALL_KINDS as $kind)
		{
			$uid = $this->IssueLabel($kind, self::$retireTargets[$kind]);
			self::$db->exec('DELETE FROM ' . FieldCatalogue::TableFor($kind) . ' WHERE id = ' . self::$retireTargets[$kind]);

			$response = $this->ResolveLabel($uid);
			self::assertSame(200, $response['status'], "retired $kind: " . $response['body']);

			$json = json_decode($response['body'], true);
			self::assertSame('retired', $json['status'], "retired $kind should report retired: " . $response['body']);
			self::assertSame($kind, $json['kind'], "retired $kind must report its own kind");

			if ($kind === 'stock_entry')
			{
				self::assertSame(self::$retireTargets['stock_entry'], $json['snapshot']['id'] ?? null,
					'the snapshot names the stock row that went away');
				self::assertSame(self::RETIRE_STOCK_PRODUCT_NAME, $json['snapshot']['product_name'] ?? null,
					'the snapshot names the product at retirement time');
				self::assertSame(self::BEST_BEFORE, $json['snapshot']['best_before_date'] ?? null,
					"the snapshot carries the retired entry's best_before_date");
				self::assertEqualsWithDelta(3.0, $json['snapshot']['amount'] ?? null, 0.0001,
					"the snapshot carries the retired entry's amount");
			}
			else
			{
				self::assertSame(self::$retireTargets[$kind], $json['snapshot']['id'] ?? null,
					"the snapshot names the $kind row that went away");
			}

			$failure = self::ValidateAgainstResolveSchema($json);
			self::assertNull($failure, "retired $kind response failed schema validation ($failure): " . $response['body']);
		}
	}

	private function IssueLabel(string $kind, int $targetId): string
	{
		self::$db->beginTransaction();
		try
		{
			$statement = self::$db->prepare('SELECT epoch FROM label_import_state WHERE id = 1');
			$statement->execute();
			$epoch = (int)$statement->fetchColumn();

			$service = new LabelIdentityService(self::$db);
			$uid = $service->Issue($kind, $targetId, $epoch);

			self::$db->commit();
			return $uid;
		}
		catch (\Exception $e)
		{
			self::$db->rollBack();
			throw $e;
		}
	}

	private function ResolveLabel(string $uid): array
	{
		$spec = ['method' => 'GET', 'path' => '/api/labels/resolve/' . $uid];
		$spec['headers'] = ['VICTUAL-API-KEY' => self::$operatorKey];

		$specFile = tempnam(getenv('VICTUAL_DATAPATH') ?: sys_get_temp_dir(), 'labelresolve-');
		file_put_contents($specFile, json_encode($spec));

		$environment = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($environment, [
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
			[PHP_BINARY, __DIR__ . '/labelapi-subprocess-helper.php', '@' . $specFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		unlink($specFile);

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "Request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['body'] = base64_decode($result['body_base64'], true);

		return $result;
	}

	/**
	 * Validates $row against this operation's declared 200 response schema (the oneOf covering
	 * unknown/resolved/retired), the way WireContractTest::validateAgainstMember validates a
	 * row against a named component schema - except the schema under test here is inline on
	 * the path rather than a $ref under components/schemas, so it is read off the operation
	 * directly instead of by name.
	 *
	 * allowDefaults is off for the same reason validateAgainstMember turns it off: a generated
	 * client does not have Opis's leniency about a property that declares a default, and
	 * modelling the client means not having it here either.
	 *
	 * @return string|null null when $row validates; otherwise "path: keyword" for the first
	 *         (deepest) failure.
	 */
	private static function ValidateAgainstResolveSchema(array $row): ?string
	{
		static $schema = null;
		if ($schema === null)
		{
			$document = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), false, flags: JSON_THROW_ON_ERROR);
			$schema = $document->paths->{'/labels/resolve/{code}'}->get->responses->{'200'}->content->{'application/json'}->schema;
		}

		$validator = new \Opis\JsonSchema\Validator();
		$validator->parser()->setOption('allowDefaults', false);

		$result = $validator->validate(json_decode(json_encode($row), false), json_decode(json_encode($schema), false));

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
}
