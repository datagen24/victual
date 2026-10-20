<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Issue #952 (maintainer decision 2026-10-18, option A), migration 0318: a quantity unit
 * conversion write is refused when it would make a factor the resolver evaluates, or that
 * factor's inverse, anything but a positive, finite, normal double, that is outside
 * 2^-1022..2^1022. The write rolls back, and the API answers 400 with a message that names the
 * range rather than the generic database rejection.
 *
 * Every case builds its own quantity units, so a global conversion one case writes cannot reach
 * another. Factors are sent as %.17g strings cast to float8, which round-trip every double.
 */
class ConversionChainRangeTest extends PgsqlSchemaTestCase
{
	private const REFUSAL_PREFIX = 'This quantity unit conversion would make ';
	private const GENERIC_REJECTION = 'The database rejected this request';

	private static PDO $db;
	private static string $apiKey = '';
	private static int $locationId = 0;

	/** An ADMIN caller with an API key, and a location for lookup-created products. */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9520, 'conversion-chain-range', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9520, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9520, now() + interval '30 days', ?)")
			->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
		self::$locationId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CCR shelf') RETURNING id")->fetchColumn();
	}

	/** A quantity unit with a unique name; returns its id. */
	private static function unit(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), $name . 's ' . bin2hex(random_bytes(3))]);

		return (int)$statement->fetchColumn();
	}

	/** A global conversion written directly; throws the driver's exception on a refusal. */
	private static function conversion(int $from, int $to, float $factor, ?int $productId = null): void
	{
		self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, CAST(? AS DOUBLE PRECISION), ?)')
			->execute([$from, $to, sprintf('%.17g', $factor), $productId]);
	}

	/** The SQLSTATE a direct conversion write raises, or null when it is accepted. */
	private static function refusal(int $from, int $to, float $factor): ?string
	{
		try
		{
			self::conversion($from, $to, $factor);
		}
		catch (\PDOException $ex)
		{
			return (string)($ex->errorInfo[0] ?? $ex->getCode());
		}

		return null;
	}

	/** Rows of quantity_unit_conversions and the resolved cache that touch $unitIds. */
	private static function touching(array $unitIds): array
	{
		$ids = implode(',', array_map('intval', $unitIds));

		return [
			'conversions' => (int)self::$db->query("SELECT count(*) FROM quantity_unit_conversions WHERE from_qu_id IN ($ids) OR to_qu_id IN ($ids)")->fetchColumn(),
			'cache' => (int)self::$db->query("SELECT count(*) FROM cache__quantity_unit_conversions_resolved WHERE from_qu_id IN ($ids) OR to_qu_id IN ($ids)")->fetchColumn(),
		];
	}

	/** One request through the real middleware as the ADMIN caller, in its own process. */
	private static function send(string $method, string $path, ?array $body = null, array $environment = []): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey]];
		if ($body !== null)
		{
			$spec['body'] = $body;
		}
		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		], $environment);
		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		$result['body'] = json_decode((string)$result['body'], true);

		return $result;
	}

	/** Asserts a 400 whose message is the range refusal, not the generic database rejection. */
	private static function assertRangeRefusal(array $response, string $what): void
	{
		self::assertSame(400, $response['status'], "$what is refused: " . json_encode($response['body']));
		self::assertStringStartsWith(self::REFUSAL_PREFIX, $response['body']['error_message'] ?? '', "$what says why");
		self::assertStringNotContainsString(self::GENERIC_REJECTION, $response['body']['error_message']);
	}

	/** Issue #952's case: B→C at 1e10 exists, and A→B at 1e300 would resolve A→C at 1e310. Refused through the API, with nothing written. */
	public function testAChainThatOverflowsIsRefusedWithASpecificMessageAndNothingWritten(): void
	{
		$a = self::unit('CCR a');
		$b = self::unit('CCR b');
		$c = self::unit('CCR c');
		self::conversion($b, $c, 1e10);
		$before = self::touching([$a, $b, $c]);

		$response = self::send('POST', '/api/objects/quantity_unit_conversions', ['from_qu_id' => $a, 'to_qu_id' => $b, 'factor' => 1e300]);

		self::assertRangeRefusal($response, 'a factor whose chain overflows');
		self::assertSame($before, self::touching([$a, $b, $c]), 'neither the conversion, its inverse nor a cache row was written');
		self::assertNull(self::refusal($a, $b, 1e290), 'a chain that stays in range, 1e300, is accepted');
	}

	/** The reciprocal: B→C at 1e-10 and A→B at 1e-300 would resolve A→C at 1e-310, a subnormal. Refused too. */
	public function testAChainThatUnderflowsToASubnormalIsRefused(): void
	{
		$a = self::unit('CCR a');
		$b = self::unit('CCR b');
		$c = self::unit('CCR c');
		self::conversion($b, $c, 1e-10);

		$response = self::send('POST', '/api/objects/quantity_unit_conversions', ['from_qu_id' => $a, 'to_qu_id' => $b, 'factor' => 1e-300]);

		self::assertRangeRefusal($response, 'a factor whose chain is subnormal');
		self::assertSame(0, self::touching([$a])['conversions'], 'nothing was written');
	}

	/** Chained factors of exactly 2^1022 and 2^-1022 are accepted; one step past either is refused. */
	public function testTheBoundariesOfTheNormalRangeHoldForChainedFactors(): void
	{
		foreach ([[511, 511, null], [-511, -511, null], [511, 512, 'VQ952'], [-511, -512, 'VQ952']] as [$first, $second, $expected])
		{
			$a = self::unit('CCR a');
			$b = self::unit('CCR b');
			$c = self::unit('CCR c');
			self::conversion($b, $c, 2.0 ** $first);

			self::$db->beginTransaction();
			try
			{
				self::assertSame($expected, self::refusal($a, $b, 2.0 ** $second), "2^$second after 2^$first resolves to 2^" . ($first + $second));
			}
			finally
			{
				self::$db->rollBack();
			}
		}
	}

	/** A resolved factor at the boundary is stored exactly (the lossless text cache of issue #951). */
	public function testAnAcceptedBoundaryChainIsCachedExactly(): void
	{
		$a = self::unit('CCR a');
		$b = self::unit('CCR b');
		$c = self::unit('CCR c');
		// The cache holds chains per product, so a product stocked in C is what makes the resolver
		// resolve A→C. Every unit of it is C: a purchase unit of A would give it a direct A→C of 1
		// of its own, which wins over the chain
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['CCR boundary product ' . bin2hex(random_bytes(3)), self::$locationId, $c, $c, $c, $c]);
		$product = (int)$statement->fetchColumn();
		self::conversion($b, $c, 2.0 ** 511);
		self::conversion($a, $b, 2.0 ** 511);

		$cached = self::$db->query("SELECT factor FROM cache__quantity_unit_conversions_resolved WHERE product_id = $product AND from_qu_id = $a AND to_qu_id = $c")->fetchColumn();
		self::assertNotFalse($cached, 'the chain A→C is resolved');
		self::assertSame(2.0 ** 1022, (float)$cached, 'at exactly 2^1022');
		self::assertSame(2.0 ** -1022, (float)self::$db->query("SELECT factor FROM cache__quantity_unit_conversions_resolved WHERE product_id = $product AND from_qu_id = $c AND to_qu_id = $a")->fetchColumn(), 'and its inverse at exactly 2^-1022');
	}

	/** Editing an existing conversion into an overflowing chain is refused and leaves the old factor. */
	public function testAnEditThatMakesAChainOverflowIsRefused(): void
	{
		$a = self::unit('CCR a');
		$b = self::unit('CCR b');
		$c = self::unit('CCR c');
		self::conversion($b, $c, 1e10);
		self::conversion($a, $b, 1e200);
		$id = (int)self::$db->query("SELECT id FROM quantity_unit_conversions WHERE from_qu_id = $a AND to_qu_id = $b")->fetchColumn();

		$response = self::send('PUT', "/api/objects/quantity_unit_conversions/$id", ['factor' => 1e300]);

		self::assertRangeRefusal($response, 'an edit whose chain overflows');
		self::assertSame(1e200, (float)self::$db->query("SELECT factor FROM quantity_unit_conversions WHERE id = $id")->fetchColumn(), 'the stored factor is unchanged');
	}

	/**
	 * A barcode lookup that creates a product with a purchase-to-stock factor whose chain
	 * overflows is refused as a whole: the product, its barcode and its conversion are all
	 * rolled back together.
	 */
	public function testALookupWhoseConversionChainOverflowsCreatesNothing(): void
	{
		$purchase = self::unit('CCR purchase');
		$stock = self::unit('CCR stock');
		$far = self::unit('CCR far');
		self::conversion($stock, $far, 1e10);

		$class = 'ConversionChainRangePlugin' . bin2hex(random_bytes(3));
		$directory = getenv('VICTUAL_DATAPATH') . '/plugins';
		@mkdir($directory, 0777, true);
		$location = self::$locationId;
		file_put_contents("$directory/$class.php", <<<PLUGIN
<?php

class {$class} extends \\Victual\\Helpers\\BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'ConversionChainRange';

	/** A product whose purchase-to-stock factor, 1e300, overflows its chain to the far unit. */
	protected function ExecuteLookup(\$barcode)
	{
		return [
			'name' => 'CCR lookup product ' . \$barcode,
			'location_id' => {$location},
			'qu_id_purchase' => {$purchase},
			'qu_id_stock' => {$stock},
			'__qu_factor_purchase_to_stock' => 1e300,
			'__barcode' => \$barcode,
		];
	}
}
PLUGIN);
		$counts = fn () => array_map(fn ($table) => (int)self::$db->query("SELECT count(*) FROM $table")->fetchColumn(), ['products' => 'products', 'product_barcodes' => 'product_barcodes', 'quantity_unit_conversions' => 'quantity_unit_conversions']);
		$before = $counts();

		try
		{
			$response = self::send('GET', '/api/stock/barcodes/external-lookup/952952952?add=true', null, ['VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => $class]);
		}
		finally
		{
			@unlink("$directory/$class.php");
		}

		self::assertRangeRefusal($response, 'a lookup whose conversion chain overflows');
		self::assertSame($before, $counts(), 'no product, barcode or conversion was created');
	}
}
