<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #890 (maintainer decision L): a barcode lookup source's
 * __qu_factor_purchase_to_stock, and the inverse the quantity_unit_conversions insert
 * trigger writes (1 / factor), must both be finite, positive, NORMAL doubles.
 *
 * Below 2^-1022 (PHP_FLOAT_MIN) the factor itself is subnormal; for 5e-324 the inverse
 * overflows and PostgreSQL raises 22003; above 2^1022 (1 / PHP_FLOAT_MIN) the inverse is
 * subnormal and loses precision silently. This pins that the refusal happens on those
 * values and on no others, against a real PostgreSQL. It does not claim exact arithmetic
 * or medically meaningful limits - only that nothing overflows or goes subnormal.
 *
 * Each boundary is built from IEEE-754 bits, not from a decimal literal, so "the next
 * double" is exactly that. Requests run in a process of their own, as
 * BarcodeLookupNonFiniteHttpTest's do, because the user plugin is loaded by name from a
 * VICTUAL_* constant that cannot be redefined in the PHPUnit process.
 */
class BarcodeLookupFactorNormalRangeTest extends PgsqlSchemaTestCase
{
	private const REFUSAL = 'Provided __qu_factor_purchase_to_stock and its inverse must both be finite, normal numbers greater than zero';

	private static PDO $db;
	private static string $apiKey = '';
	private static int $locationId = 0;
	private static int $quPurchase = 0;
	private static int $quStock = 0;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9610, 'factor-normal-lookup-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9610, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9610, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$locationId = (int)self::$db->query('SELECT id FROM locations WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
		$units = self::$db->query('SELECT id FROM quantity_units WHERE active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
		self::$quPurchase = (int)$units[1];
		self::$quStock = (int)$units[0];
	}

	/** The IEEE-754 binary64 bit pattern of $x, as an int (little-endian pack, so host byte order does not matter). */
	private static function bitsOf(float $x): int
	{
		return unpack('P', pack('e', $x))[1];
	}

	private static function fromBits(int $bits): float
	{
		return unpack('e', pack('P', $bits))[1];
	}

	/** The adjacent positive double: $steps = 1 is the next one up, -1 the next one down. */
	private static function neighbour(float $x, int $steps): float
	{
		return self::fromBits(self::bitsOf($x) + $steps);
	}

	/** A data set name carrying the exact value and its bits. */
	private static function label(string $what, float $x): string
	{
		return sprintf('%s = %.17g (0x%016X)', $what, $x, self::bitsOf($x));
	}

	/**
	 * @return array<string, array{0: int, 1: bool, 2: string, 3: string}>
	 *         bits, whether a lookup accepts it, what PostgreSQL does when the factor is inserted
	 *         directly ('ok', or 'overflow' for SQLSTATE 22003), and a barcode of its own.
	 */
	public static function boundaryProvider(): array
	{
		$min = PHP_FLOAT_MIN;
		$invMin = 1.0 / PHP_FLOAT_MIN; // 2^1022, and its inverse is exactly PHP_FLOAT_MIN

		$cases = [
			['PHP_FLOAT_MIN', $min, true, 'ok'],
			['next double below PHP_FLOAT_MIN (subnormal)', self::neighbour($min, -1), false, 'ok'],
			['smallest subnormal (5e-324)', self::fromBits(1), false, 'overflow'],
			['1 / PHP_FLOAT_MIN', $invMin, true, 'ok'],
			['next double above 1 / PHP_FLOAT_MIN (inverse subnormal)', self::neighbour($invMin, 1), false, 'ok'],
			['next double below 1 / PHP_FLOAT_MIN', self::neighbour($invMin, -1), true, 'ok'],
			['PHP_FLOAT_MAX (inverse subnormal)', PHP_FLOAT_MAX, false, 'ok'],
			['one', 1.0, true, 'ok'],
			['0.001', 0.001, true, 'ok'],
			['1000', 1000.0, true, 'ok'],
		];

		$provider = [];
		foreach ($cases as $index => [$what, $value, $accepted, $pg])
		{
			$provider[self::label($what, $value)] = [self::bitsOf($value), $accepted, $pg, sprintf('40004189%05d', $index + 1)];
		}

		return $provider;
	}

	public function testPhpAndTheFixtureAgreeOnWhatIsNormal(): void
	{
		self::assertSame(2.2250738585072014E-308, PHP_FLOAT_MIN, 'PHP_FLOAT_MIN is the smallest positive NORMAL double');
		self::assertSame(PHP_FLOAT_MIN, 1.0 / (1.0 / PHP_FLOAT_MIN), '2^-1022 and 2^1022 are exact inverses');
		self::assertLessThan(PHP_FLOAT_MIN, self::neighbour(PHP_FLOAT_MIN, -1), 'the next double down is subnormal');
		self::assertLessThan(PHP_FLOAT_MIN, 1.0 / self::neighbour(1.0 / PHP_FLOAT_MIN, 1), 'the inverse of the next double above 2^1022 is subnormal');
		self::assertSame(INF, 1.0 / self::fromBits(1), 'PHP gives INF, not an error, for 1 / 5e-324');
		self::assertGreaterThan(0.0, 1.0 / PHP_FLOAT_MAX);
		self::assertLessThan(PHP_FLOAT_MIN, 1.0 / PHP_FLOAT_MAX, 'the inverse of PHP_FLOAT_MAX is subnormal');
	}

	/** @return array{status: int, body: string} */
	private static function send(string $path, string $pluginClass): array
	{
		$spec = ['method' => 'GET', 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey]];

		// $_SERVER carries non-scalar entries (argv among them) that proc_open's env
		// conversion cannot stringify.
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
			'VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN' => $pluginClass,
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

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for GET $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** A user plugin returning a valid product whose factor is the double with the given bits. */
	private static function writePlugin(string $class, int $factorBits): string
	{
		$pluginDirectory = getenv('VICTUAL_DATAPATH') . '/plugins';
		$pluginFile = $pluginDirectory . '/' . $class . '.php';
		@mkdir($pluginDirectory, 0777, true);

		$locationId = self::$locationId;
		$quPurchase = self::$quPurchase;
		$quStock = self::$quStock;

		file_put_contents($pluginFile, <<<PLUGIN
<?php

class {$class} extends \\Victual\\Helpers\\BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'FactorNormal';

	protected function ExecuteLookup(\$barcode)
	{
		return [
			'name' => 'FactorNormal Product ' . \$barcode,
			'location_id' => {$locationId},
			'qu_id_purchase' => {$quPurchase},
			'qu_id_stock' => {$quStock},
			'__qu_factor_purchase_to_stock' => unpack('e', pack('P', {$factorBits}))[1],
			'__barcode' => \$barcode,
		];
	}
}
PLUGIN);

		return $pluginFile;
	}

	/** @return array<string, int> */
	private static function rowCounts(): array
	{
		$counts = [];
		foreach (['products', 'product_barcodes', 'quantity_unit_conversions'] as $table)
		{
			$counts[$table] = (int)self::$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
			$counts[$table . '_max_id'] = (int)self::$db->query("SELECT COALESCE(MAX(id), 0) FROM $table")->fetchColumn();
		}

		return $counts;
	}

	public function testTheSubprocessCanReadStock(): void
	{
		$probe = self::send('/api/stock', 'DoesNotMatterForThisRequest');
		self::assertSame(200, $probe['status'], 'The subprocess identity can read stock: ' . $probe['body']);
	}

	#[Depends('testTheSubprocessCanReadStock')]
	#[DataProvider('boundaryProvider')]
	public function testALookupFactorIsAcceptedExactlyWhenItAndItsInverseAreNormal(int $factorBits, bool $accepted, string $pgOutcome, string $barcode): void
	{
		$factor = self::fromBits($factorBits);
		$class = 'FactorNormalLookupPlugin' . $barcode;
		$pluginFile = self::writePlugin($class, $factorBits);

		try
		{
			$before = self::rowCounts();

			$response = self::send('/api/stock/barcodes/external-lookup/' . $barcode . '?add=true', $class);

			if (!$accepted)
			{
				self::assertSame(400, $response['status'], "the lookup is refused: {$response['body']}");
				self::assertStringContainsString(self::REFUSAL, $response['body']);
				self::assertSame($before, self::rowCounts(), 'no product, product_barcodes or quantity_unit_conversions row was written');

				$barcodeRows = self::$db->prepare('SELECT COUNT(*) FROM product_barcodes WHERE barcode = ?');
				$barcodeRows->execute([$barcode]);
				self::assertSame(0, (int)$barcodeRows->fetchColumn(), 'and the barcode the plugin was asked about is not stored');

				return;
			}

			self::assertSame(200, $response['status'], "the add succeeds: {$response['body']}");

			$product = self::$db->prepare('SELECT id FROM products WHERE name = ?');
			$product->execute(['FactorNormal Product ' . $barcode]);
			$productId = (int)$product->fetchColumn();
			self::assertGreaterThan(0, $productId, 'the product row was written');

			$conversion = self::$db->prepare('SELECT factor FROM quantity_unit_conversions WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ?');

			$conversion->execute([$productId, self::$quPurchase, self::$quStock]);
			$stored = $conversion->fetchAll(PDO::FETCH_COLUMN);
			self::assertCount(1, $stored, 'one purchase -> stock conversion');
			self::assertGreaterThan(0.0, (float)$stored[0]);

			$conversion->execute([$productId, self::$quStock, self::$quPurchase]);
			$inverse = $conversion->fetchAll(PDO::FETCH_COLUMN);
			self::assertCount(1, $inverse, 'and the trigger wrote one inverse row without error');
			self::assertGreaterThan(0.0, (float)$inverse[0], 'the inverse is positive');
			self::assertLessThan(INF, (float)$inverse[0], 'and finite');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/**
	 * The classification above is only as good as PostgreSQL agreeing with it: inserting
	 * the same factor straight into quantity_unit_conversions either succeeds (and the
	 * trigger's inverse is what PHP computed) or raises 22003 exactly where PHP's
	 * 1 / factor is INF. Fresh quantity units per case, rolled back after.
	 */
	#[DataProvider('boundaryProvider')]
	public function testPostgreSqlInverseAgreesWithPhpAtTheBoundary(int $factorBits, bool $accepted, string $pgOutcome, string $barcode): void
	{
		$factor = self::fromBits($factorBits);
		$expectedInverse = 1.0 / $factor;

		self::$db->beginTransaction();
		try
		{
			$units = [];
			foreach (['From', 'To'] as $side)
			{
				$name = "Cov890 $side $barcode";
				$insert = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
				$insert->execute([$name, $name . 's']);
				$units[$side] = (int)$insert->fetchColumn();
			}

			try
			{
				// A %.17g string cast to float8 round-trips every double exactly; binding the PHP float would go through precision=14.
				$insert = self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, CAST(? AS DOUBLE PRECISION), NULL)');
				$insert->execute([$units['From'], $units['To'], sprintf('%.17g', $factor)]);
			}
			catch (\PDOException $ex)
			{
				self::assertSame('overflow', $pgOutcome, "PostgreSQL refused a factor PHP can invert: {$ex->getMessage()}");
				self::assertSame('22003', $ex->errorInfo[0] ?? $ex->getCode(), 'with a numeric_value_out_of_range error');
				self::assertSame(INF, $expectedInverse, 'exactly where PHP\'s inverse is INF');
				self::assertFalse($accepted);

				return;
			}

			self::assertSame('ok', $pgOutcome, 'PostgreSQL accepted a factor whose inverse overflows');
			self::assertNotSame(INF, $expectedInverse);

			$read = self::$db->prepare('SELECT factor FROM quantity_unit_conversions WHERE from_qu_id = ? AND to_qu_id = ?');
			$read->execute([$units['To'], $units['From']]);
			$inverse = (float)$read->fetchColumn();
			self::assertSame($expectedInverse, $inverse, 'the trigger-written inverse is the inverse PHP computed');
			self::assertSame($accepted, $inverse >= PHP_FLOAT_MIN && $factor >= PHP_FLOAT_MIN, 'and it is normal, together with the factor, exactly for the accepted values');
		}
		finally
		{
			// After a 22003 the transaction is aborted; rollBack() is the way out either way.
			self::$db->rollBack();
		}
	}
}
