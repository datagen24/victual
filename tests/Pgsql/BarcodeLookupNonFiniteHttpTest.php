<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;
use Victual\Tests\Support\Subprocess;

/**
 * Issue #844 end to end: GET /api/stock/barcodes/external-lookup/{barcode}?add=true with a
 * source that returns a non-finite number or a non-string name answers 400 and writes no
 * product, product_barcodes or quantity_unit_conversions row.
 *
 * Kept apart from BarcodeLookupNonFiniteTest (which needs no database) because the add
 * path only exists behind the real middleware stack: the user plugin is loaded from
 * VICTUAL_DATAPATH by name through a VICTUAL_* setting, and that constant cannot be
 * redefined in the PHPUnit process, so each request runs in a process of its own
 * (tests/Pgsql/request-subprocess-helper.php), as StockCoverageTest's lookup cases do.
 */
class BarcodeLookupNonFiniteHttpTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static string $apiKey = '';
	private static int $locationId = 0;
	private static int $quPurchase = 0;
	private static int $quStock = 0;

	/**
	 * Creates an ADMIN user with an API key and picks the first active location and two active
	 * quantity units for the generated plugin to use.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9600, 'nonfinite-lookup-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9600, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9600, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$locationId = (int)self::$db->query('SELECT id FROM locations WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();
		$units = self::$db->query('SELECT id FROM quantity_units WHERE active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
		self::$quPurchase = (int)$units[1];
		self::$quStock = (int)$units[0];
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

		$finished = Subprocess::Completed(Subprocess::run([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], $env));
		$output = $finished['stdout'];
		$errors = $finished['stderr'];

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for GET $path. stdout: $output\nstderr: $errors");

		return $result;
	}

	/**
	 * Writes a user plugin whose ExecuteLookup() returns a valid product with
	 * $overrideFields (PHP source for array entries) replacing or adding to it.
	 */
	private static function writePlugin(string $class, string $overrideFields): string
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
	public const PLUGIN_NAME = 'NonFinite';

	protected function ExecuteLookup(\$barcode)
	{
		return array_replace([
			'name' => 'NonFinite Product ' . \$barcode,
			'location_id' => {$locationId},
			'qu_id_purchase' => {$quPurchase},
			'qu_id_stock' => {$quStock},
			'__qu_factor_purchase_to_stock' => 6,
			'__barcode' => \$barcode,
		], [{$overrideFields}]);
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

	/** @return array<string, array{0: string, 1: string, 2: string}> Override source, expected message, barcode. */
	public static function refusedOutputProvider(): array
	{
		return [
			'INF name' => ["'name' => json_decode('1e400')", 'Provided name must be a string', '4000417844001'],
			'NAN numeric product field' => ["'min_stock_amount' => NAN", 'Plugin output contains a non-finite number at min_stock_amount', '4000417844002'],
			'INF numeric product field' => ["'min_stock_amount' => INF", 'Plugin output contains a non-finite number at min_stock_amount', '4000417844003'],
			'NAN nested one level' => ["'extra' => ['calories' => NAN]", 'Plugin output contains a non-finite number at extra.calories', '4000417844004'],
			'NAN in a nested object, add=true' => ["'extra' => (object)['calories' => NAN]", 'Plugin output contains a non-finite number at extra.calories', '4000417844007'],
			'object that contains itself, add=true' => ["'extra' => (function () { \$o = new stdClass(); \$o->self = \$o; return \$o; })()", 'Plugin output contains a circular reference at extra.self', '4000417844008'],
			// Issue #916: a string the float-only walk passes, which PostgreSQL reads as the special value
			"'Infinity' string, add=true" => ["'calories' => 'Infinity'", 'The calories must be a finite number', '4000417844009'],
			"'NaN' string, add=true" => ["'min_stock_amount' => 'NaN'", 'The min stock amount must be a finite number', '4000417844010'],
			'int name' => ["'name' => 123", 'Provided name must be a string', '4000417844005'],
			'array name' => ["'name' => ['Beans']", 'Provided name must be a string', '4000417844006'],
		];
	}

	/**
	 * With add=true, a plugin output holding a non-finite or otherwise unusable value answers 400
	 * with the expected message and writes no product, barcode or conversion row.
	 */
	#[Depends('testTheSubprocessCanReadStock')]
	#[DataProvider('refusedOutputProvider')]
	public function testAddingAProductWhoseSourceOutputIsUnusableIsRefusedWith400AndWritesNothing(string $overrideFields, string $expectedMessage, string $barcode): void
	{
		$class = 'NonFiniteLookupPlugin' . $barcode;
		$pluginFile = self::writePlugin($class, $overrideFields);

		try
		{
			$before = self::rowCounts();

			$response = self::send('/api/stock/barcodes/external-lookup/' . $barcode . '?add=true', $class);

			self::assertSame(400, $response['status'], "the lookup is refused: {$response['body']}");
			self::assertStringContainsString($expectedMessage, $response['body']);
			self::assertSame($before, self::rowCounts(), 'no product, product_barcodes or quantity_unit_conversions row was written');

			$barcodeRows = self::$db->prepare('SELECT COUNT(*) FROM product_barcodes WHERE barcode = ?');
			$barcodeRows->execute([$barcode]);
			self::assertSame(0, (int)$barcodeRows->fetchColumn(), 'and the barcode the plugin was asked about is not stored');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/** A nested object is walked too; without add=true nothing is written either way, so the status is the point. */
	#[Depends('testTheSubprocessCanReadStock')]
	public function testALookupWithoutAddWhoseSourceOutputHoldsANestedNonFiniteObjectIsRefusedWith400(): void
	{
		$barcode = '4000417844021';
		$class = 'NonFiniteLookupPlugin' . $barcode;
		$pluginFile = self::writePlugin($class, "'extra' => (object)['calories' => NAN]");

		try
		{
			$before = self::rowCounts();

			$response = self::send('/api/stock/barcodes/external-lookup/' . $barcode, $class);

			self::assertSame(400, $response['status'], "the lookup is refused, not answered 500: {$response['body']}");
			self::assertStringContainsString('Plugin output contains a non-finite number at extra.calories', $response['body']);
			self::assertSame($before, self::rowCounts(), 'nothing was written');
		}
		finally
		{
			@unlink($pluginFile);
		}
	}

	/**
	 * The subprocess API key can read stock and get 200, so later failures are not an
	 * authentication problem.
	 */
	public function testTheSubprocessCanReadStock(): void
	{
		$probe = self::send('/api/stock', 'DoesNotMatterForThisRequest');
		self::assertSame(200, $probe['status'], 'The subprocess identity can read stock: ' . $probe['body']);
	}

	/** Negative control: the same plugin shape with finite numbers still imports. */
	#[Depends('testTheSubprocessCanReadStock')]
	public function testAValidOutputWithFiniteNumbersStillImports(): void
	{
		$barcode = '4000417844011';
		$class = 'NonFiniteLookupPlugin' . $barcode;
		$pluginFile = self::writePlugin($class, "'min_stock_amount' => 2.5");

		try
		{
			$response = self::send('/api/stock/barcodes/external-lookup/' . $barcode . '?add=true', $class);
			self::assertSame(200, $response['status'], "the add succeeds: {$response['body']}");

			$product = self::$db->prepare('SELECT min_stock_amount FROM products WHERE name = ?');
			$product->execute(['NonFinite Product ' . $barcode]);
			self::assertSame(2.5, (float)$product->fetchColumn(), 'the product was written with the finite value');

			$barcodes = self::$db->prepare('SELECT COUNT(*) FROM product_barcodes WHERE barcode = ?');
			$barcodes->execute([$barcode]);
			self::assertSame(1, (int)$barcodes->fetchColumn());
		}
		finally
		{
			@unlink($pluginFile);
		}
	}
}
