<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Helpers\BaseBarcodeLookupPlugin;

/**
 * The external barcode lookup surface: the contract BaseBarcodeLookupPlugin enforces on
 * whatever a source returns, and the two sources shipped in plugins/.
 *
 * No database. A lookup plugin is handed its locations, quantity units and user settings
 * by the caller (services/StockService.php::LoadExternalBarcodeLookupPlugin) and touches
 * nothing else, so the rows are fixtures here and the schema adds nothing to the subject.
 *
 * No network either, and that is load bearing. AGENTS.md forbids adding an SSRF surface,
 * and docs/plans/09-barcode-lookup-sources.md exists precisely because more sources means
 * more outbound calls. Open Food Facts is exercised through
 * tests/Pgsql/barcodelookup-subprocess-helper.php, which substitutes the HTTP client -
 * the one genuine external boundary - and leaves the parsing, the mapping and the
 * null-on-miss decisions running for real.
 *
 * Several cases below are marked DEFECT. They pin what the code does today rather than
 * what it should do, because sweep finding S14 (docs/security-sweep.md:82) is still open:
 * plan 09 names the image extension allow-list, the barcode filename class and the refusal
 * of loopback and private hosts as a gate that lands before any new source, and none of
 * the three exists in the tree. A pinning assertion was chosen over markTestIncomplete for
 * each of them deliberately - an incomplete test says nothing when the gap is closed,
 * whereas these fail the moment a check is added, which is the reminder the fix wants.
 */
class BarcodeLookupTest extends TestCase
{
	/** A fixture barcode with a valid EAN-13 check digit, so nothing below fails for being malformed. */
	private const BARCODE = '4001234567890';

	/** @var array<int, object> Two locations, so "the first one" and "the preset one" are distinguishable. */
	private static array $locations;

	/** @var array<int, object> Two quantity units, for the same reason. */
	private static array $quantityUnits;

	public static function setUpBeforeClass(): void
	{
		self::$locations = [
			(object)['id' => 2, 'name' => 'Fridge'],
			(object)['id' => 5, 'name' => 'Pantry']
		];

		self::$quantityUnits = [
			(object)['id' => 2, 'name' => 'Piece'],
			(object)['id' => 3, 'name' => 'Pack']
		];
	}

	/**
	 * A plugin whose ExecuteLookup() returns exactly $canned - the substitution the base
	 * class's own documentation describes, and the only way to reach Lookup() without a
	 * source attached to it.
	 */
	private static function plugin($canned, array $userSettings = ['product_presets_location_id' => -1, 'product_presets_qu_id' => -1]): CannedBarcodeLookupPlugin
	{
		$plugin = new CannedBarcodeLookupPlugin(self::$locations, self::$quantityUnits, $userSettings);
		$plugin->Canned = $canned;

		return $plugin;
	}

	/**
	 * The smallest output the documented contract accepts, with $overrides applied. Stock
	 * and purchase units differ so that the two separate validations are told apart.
	 */
	private static function validOutput(array $overrides = []): array
	{
		return array_replace([
			'name' => 'Canned Beans 400g',
			'location_id' => 2,
			'qu_id_purchase' => 3,
			'qu_id_stock' => 2,
			'__qu_factor_purchase_to_stock' => 6,
			'__barcode' => self::BARCODE
		], $overrides);
	}

	/**
	 * Asserts that Lookup() refuses $canned with exactly $expectedMessage. Written out
	 * rather than expectException() so that one test can walk several inputs and so that
	 * "did not throw at all" reports as itself.
	 */
	private function assertLookupRefuses($canned, string $expectedMessage, string $why): void
	{
		$caught = null;

		try
		{
			self::plugin($canned)->Lookup(self::BARCODE);
		}
		catch (\Exception $ex)
		{
			$caught = $ex;
		}

		self::assertNotNull($caught, "$why - Lookup() returned instead of throwing");
		self::assertSame($expectedMessage, $caught->getMessage(), $why);
	}

	// ---------------------------------------------------------------------------------
	// The base class contract: hit, miss, malformed
	// ---------------------------------------------------------------------------------

	/**
	 * A hit is handed back whole. The base class validates; it is not a mapper, so nothing
	 * a source chose may be rewritten on the way out.
	 */
	public function testLookupReturnsThePluginsProductDataUnchangedForAHit(): void
	{
		$canned = self::validOutput();
		$plugin = self::plugin($canned);

		$result = $plugin->Lookup(self::BARCODE);

		self::assertSame($canned, $result, 'a validated hit must reach the caller exactly as the source built it');
		self::assertSame([self::BARCODE], $plugin->SeenBarcodes, 'the barcode the caller asked for is the barcode the source is asked for');
	}

	/**
	 * Properties beyond the required six are the source's way of prefilling the product
	 * form (the contract in plugins/DemoBarcodeLookupPlugin.php calls the output "an
	 * associative array of the product model"), so they must survive validation.
	 */
	public function testLookupPassesProductPropertiesBeyondTheRequiredOnesThrough(): void
	{
		$canned = self::validOutput([
			'description' => 'Fixture description',
			'min_stock_amount' => 4,
			'default_best_before_days' => 365
		]);

		$result = self::plugin($canned)->Lookup(self::BARCODE);

		self::assertSame('Fixture description', $result['description']);
		self::assertSame(4, $result['min_stock_amount']);
		self::assertSame(365, $result['default_best_before_days']);
	}

	/** A miss is null, and null is not an error: the caller opens an empty product form. */
	public function testLookupReturnsNullWhenTheSourceFoundNothing(): void
	{
		self::assertNull(self::plugin(null)->Lookup(self::BARCODE), 'nothing found must be null, not an empty array and not an exception');
	}

	/** A source that returns a scalar has not returned a product. */
	public function testLookupRefusesANonArrayResult(): void
	{
		$this->assertLookupRefuses(
			'Canned Beans',
			'Plugin output must be an associative array',
			'a string is not a product model'
		);
	}

	/** An indexed array is the shape a source produces when it forgets to key its output. */
	public function testLookupRefusesAnIndexedArrayResult(): void
	{
		$this->assertLookupRefuses(
			['Canned Beans', 2, 3, 2, 1, self::BARCODE],
			'Plugin output must be an associative array',
			'a positionally indexed array carries no property names and cannot be a product model'
		);
	}

	/**
	 * Boundary: the empty array. It is neither a miss (that is null) nor a product, and it
	 * has no keys to be associative by.
	 */
	public function testLookupRefusesAnEmptyArrayResult(): void
	{
		$this->assertLookupRefuses(
			[],
			'Plugin output must be an associative array',
			'an empty array is not the documented way to report "nothing found"'
		);
	}

	/** @return array<string, array{0: string}> */
	public static function requiredPropertyProvider(): array
	{
		return [
			'name' => ['name'],
			'location_id' => ['location_id'],
			'qu_id_purchase' => ['qu_id_purchase'],
			'qu_id_stock' => ['qu_id_stock'],
			'__qu_factor_purchase_to_stock' => ['__qu_factor_purchase_to_stock'],
			'__barcode' => ['__barcode']
		];
	}

	/**
	 * Each of the six documented properties is individually required, and the refusal names
	 * the missing one - a source author reads that message and nothing else.
	 */
	#[DataProvider('requiredPropertyProvider')]
	public function testLookupRefusesOutputMissingARequiredProperty(string $property): void
	{
		$canned = self::validOutput();
		unset($canned[$property]);

		$this->assertLookupRefuses(
			$canned,
			"Plugin output does not provide needed property $property",
			"$property is documented as required"
		);
	}

	/**
	 * A name that is present but null or empty is refused rather than stored - see
	 * StockService::ExternalBarcodeLookup(), which would otherwise write the row as sent.
	 */
	#[DataProvider('emptyNameProvider')]
	public function testLookupRefusesANameThatIsPresentButEmpty($name): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['name' => $name]),
			'Provided name is empty',
			'a name property that array_key_exists() sees but has no value is not a name'
		);
	}

	/** @return array<string, array{0: mixed}> */
	public static function emptyNameProvider(): array
	{
		return [
			'null' => [null],
			'empty string' => ['']
		];
	}

	// ---------------------------------------------------------------------------------
	// Referenced entity ids and the conversion factor
	// ---------------------------------------------------------------------------------

	/**
	 * A location id the caller never handed the source cannot be stored, so it is refused
	 * before anything is written.
	 */
	public function testLookupRefusesALocationIdThatIsNotOneOfTheProvidedLocations(): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['location_id' => 99]),
			'Provided location_id (99) is not a valid location id',
			'a location id outside the provided set would be a dangling reference'
		);
	}

	/** Negative control for the case above: every provided location is accepted, not just the first. */
	public function testLookupAcceptsAnyOfTheProvidedLocations(): void
	{
		foreach ([2, 5] as $locationId)
		{
			$result = self::plugin(self::validOutput(['location_id' => $locationId]))->Lookup(self::BARCODE);
			self::assertSame($locationId, $result['location_id'], "location $locationId was provided and must be accepted");
		}
	}

	/** The purchase unit is validated on its own... */
	public function testLookupRefusesAPurchaseQuantityUnitIdThatWasNotProvided(): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['qu_id_purchase' => 77]),
			'Provided qu_id_purchase (77) is not a valid quantity unit id',
			'the purchase unit must reference a real quantity unit'
		);
	}

	/** ...and so is the stock unit, which is a different property and a different message. */
	public function testLookupRefusesAStockQuantityUnitIdThatWasNotProvided(): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['qu_id_stock' => 78]),
			'Provided qu_id_stock (78) is not a valid quantity unit id',
			'the stock unit must reference a real quantity unit'
		);
	}

	/** @return array<string, array{0: mixed}> */
	public static function unusableFactorProvider(): array
	{
		return [
			'zero' => [0],
			'zero as string' => ['0'],
			'empty string' => [''],
			'null' => [null],
			'not a number' => ['six']
		];
	}

	/**
	 * Boundary: the purchase-to-stock factor is a divisor in every conversion
	 * StockService::ExternalBarcodeLookup() makes with it, so zero, blank and non-numeric
	 * are all refused.
	 */
	#[DataProvider('unusableFactorProvider')]
	public function testLookupRefusesAnUnusableConversionFactor($factor): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['__qu_factor_purchase_to_stock' => $factor]),
			'Provided __qu_factor_purchase_to_stock must be a number greater than zero',
			'a factor that is empty or not a number cannot convert anything'
		);
	}

	/**
	 * Boundary on the other side: a fractional factor arriving as a string is the normal
	 * shape from a JSON source and must be accepted.
	 */
	public function testLookupAcceptsAFractionalConversionFactorGivenAsAString(): void
	{
		$result = self::plugin(self::validOutput(['__qu_factor_purchase_to_stock' => '2.5']))->Lookup(self::BARCODE);

		self::assertSame('2.5', $result['__qu_factor_purchase_to_stock'], 'a numeric string is a number for this purpose');
	}

	/**
	 * A negative factor is numeric and not empty, but has no meaning as a conversion and
	 * is refused, same as zero.
	 */
	public function testLookupRefusesANegativeConversionFactor(): void
	{
		$this->assertLookupRefuses(
			self::validOutput(['__qu_factor_purchase_to_stock' => -6]),
			'Provided __qu_factor_purchase_to_stock must be a number greater than zero',
			'a negative factor would reach quantity_unit_conversions.factor with no meaning'
		);
	}

	// ---------------------------------------------------------------------------------
	// __image_url and __barcode: sweep finding S14
	// ---------------------------------------------------------------------------------

	/**
	 * The happy path for the optional picture: an ordinary https URL naming a jpg survives
	 * validation and reaches the caller, which downloads it.
	 */
	public function testLookupAcceptsAnOrdinaryImageUrl(): void
	{
		$result = self::plugin(self::validOutput(['__image_url' => 'https://images.example.org/products/4001234567890.jpg']))->Lookup(self::BARCODE);

		self::assertSame('https://images.example.org/products/4001234567890.jpg', $result['__image_url']);
	}

	/** @return array<string, array{0: string}> */
	public static function disallowedImageExtensionProvider(): array
	{
		return [
			'php' => ['https://images.example.org/products/payload.php'],
			'phtml' => ['https://images.example.org/products/payload.phtml'],
			'svg' => ['https://images.example.org/products/payload.svg'],
			'html' => ['https://images.example.org/products/payload.html'],
			'no extension at all' => ['https://images.example.org/products/payload']
		];
	}

	/**
	 * S14 asks for an allow-list of image extensions. There is none, at the validation gate
	 * or anywhere after it.
	 */
	#[DataProvider('disallowedImageExtensionProvider')]
	public function testLookupDoesNotRefuseAPictureUrlWithANonImageExtension(string $imageUrl): void
	{
		// DEFECT: nothing in helpers/BaseBarcodeLookupPlugin.php::Lookup() looks at
		// __image_url. The value is fetched at services/StockService.php:1014 and the
		// extension it ends in becomes the stored file's extension
		// (services/StockService.php:1015, :1036), with a Content-Type fallback at :1020
		// that is equally unconstrained. Expected: refuse anything outside an image
		// allow-list before the fetch.
		$result = self::plugin(self::validOutput(['__image_url' => $imageUrl]))->Lookup(self::BARCODE);

		self::assertSame($imageUrl, $result['__image_url'], "current behaviour: $imageUrl passes the validation gate unexamined");
	}

	/** @return array<string, array{0: string}> */
	public static function internalImageHostProvider(): array
	{
		return [
			'loopback v4' => ['http://127.0.0.1:9200/_cluster/health.png'],
			'localhost' => ['http://localhost:5432/x.png'],
			'loopback v6' => ['http://[::1]/x.png'],
			'link local metadata' => ['http://169.254.169.254/latest/meta-data/iam/security-credentials/x.png'],
			'private 10/8' => ['http://10.0.0.5/x.png'],
			'private 192.168/16' => ['http://192.168.1.1/x.png'],
			'private 172.16/12' => ['http://172.16.0.9/x.png']
		];
	}

	/**
	 * S14 asks for loopback and private hosts to be refused before the fetch. They are not.
	 * This is the SSRF half of the finding, and it is what turns a compromised or spoofed
	 * lookup source into a request originating inside the deployment.
	 */
	#[DataProvider('internalImageHostProvider')]
	public function testLookupDoesNotRefuseAPictureUrlNamingALoopbackOrPrivateHost(string $imageUrl): void
	{
		// DEFECT: the validation gate does not inspect the host, and
		// services/StockService.php:1011 admits anything matching ^https?:// before
		// fetching it at :1014. Expected: resolve the host and refuse loopback,
		// link-local and RFC1918 addresses.
		$result = self::plugin(self::validOutput(['__image_url' => $imageUrl]))->Lookup(self::BARCODE);

		self::assertSame($imageUrl, $result['__image_url'], "current behaviour: $imageUrl passes the validation gate unexamined");
	}

	/** @return array<string, array{0: string}> */
	public static function escapingBarcodeProvider(): array
	{
		return [
			'parent directory' => ['../escaped'],
			'deep traversal' => ['../../../../tmp/escaped'],
			'absolute path' => ['/etc/victual-escaped'],
			'leading dot' => ['.htaccess-escaped'],
			'nul byte' => ["4001234567890\0.png"]
		];
	}

	/**
	 * The stored picture's file name is built from __barcode
	 * (services/StockService.php:1036), so __barcode is a file name class as well as an
	 * identifier. S14 asks for it to be filtered to [0-9A-Za-z_-]; the validation gate does
	 * not look at it at all.
	 */
	#[DataProvider('escapingBarcodeProvider')]
	public function testLookupDoesNotRefuseABarcodeThatWouldEscapeThePictureDirectory(string $barcode): void
	{
		// DEFECT: helpers/BaseBarcodeLookupPlugin.php requires __barcode to be present and
		// says nothing about its content. Downstream it is concatenated into a file name at
		// services/StockService.php:1036 and written by
		// services/Storage/FilesystemStorage.php:167, which joins the name onto the group
		// folder with no IsValidFileName() check (helpers/extensions.php:468 exists and is
		// not called here). Expected: refuse a __barcode outside [0-9A-Za-z_-].
		$result = self::plugin(self::validOutput(['__barcode' => $barcode]))->Lookup(self::BARCODE);

		self::assertSame($barcode, $result['__barcode'], 'current behaviour: __barcode is not constrained to a file name class');
	}

	/**
	 * data: image URLs are a documented input (services/StockService.php:1025), so an image
	 * one must pass...
	 */
	public function testLookupAcceptsADataImageUrl(): void
	{
		$dataUrl = 'data:image/png;base64,iVBORw0KGgo=';

		$result = self::plugin(self::validOutput(['__image_url' => $dataUrl]))->Lookup(self::BARCODE);

		self::assertSame($dataUrl, $result['__image_url']);
	}

	/**
	 * ...and an empty picture URL must be as good as none, because that is what a source
	 * with no picture for the product produces.
	 */
	public function testLookupAcceptsAnEmptyPictureUrl(): void
	{
		$result = self::plugin(self::validOutput(['__image_url' => '']))->Lookup(self::BARCODE);

		self::assertSame('', $result['__image_url'], 'an empty picture URL is the documented "no picture" value');
	}

	// ---------------------------------------------------------------------------------
	// Barcode boundaries at the validation gate
	// ---------------------------------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function oddBarcodeProvider(): array
	{
		return [
			'empty' => [''],
			'leading zeros' => ['0004001234567890'],
			'UPC-A width' => ['040123456789'],
			'one digit' => ['4'],
			'wrong check digit' => ['4001234567891'],
			'not digits at all' => ['not-a-barcode']
		];
	}

	/**
	 * Plan 09 calls normalisation for leading zeros and check-digit length the load-bearing
	 * verification step for a fuzzy source. The base class performs none of it: whatever
	 * the caller passed is what the source is asked for, byte for byte, and whatever the
	 * source returned in __barcode is what is stored.
	 */
	#[DataProvider('oddBarcodeProvider')]
	public function testLookupNeitherNormalisesNorValidatesTheBarcode(string $barcode): void
	{
		// DEFECT (shortfall, not a live bug): docs/plans/09-barcode-lookup-sources.md step 3
		// requires the scanned barcode and the source's GTIN to be compared "normalised for
		// leading zeros and check digit length". No such normalisation exists to compare
		// with, so the first fuzzy source added has nothing to build on. Pinned so that
		// adding normalisation is a visible change here.
		$plugin = self::plugin(self::validOutput(['__barcode' => $barcode]));

		$result = $plugin->Lookup($barcode);

		self::assertSame([$barcode], $plugin->SeenBarcodes, 'the barcode reaches the source untouched');
		self::assertSame($barcode, $result['__barcode'], 'and comes back untouched');
	}

	/**
	 * Negative control for the case above: the gate does not compare the barcode it was
	 * asked about with the one the source answered with, so a source may return a product
	 * carrying an entirely different barcode.
	 */
	public function testLookupDoesNotCheckThatTheSourceAnsweredAboutTheBarcodeAsked(): void
	{
		// DEFECT: plan 09's whole argument for verification is that "a wrong barcode is
		// worse than none". Nothing in helpers/BaseBarcodeLookupPlugin.php::Lookup()
		// compares $barcode with $pluginOutput['__barcode'], so a mismatch is stored as a
		// product barcode at services/StockService.php:1052.
		$result = self::plugin(self::validOutput(['__barcode' => '9999999999999']))->Lookup(self::BARCODE);

		self::assertSame('9999999999999', $result['__barcode'], 'current behaviour: the answered barcode is not checked against the asked one');
	}

	// ---------------------------------------------------------------------------------
	// DemoBarcodeLookupPlugin
	// ---------------------------------------------------------------------------------

	private static function demo(array $userSettings): \DemoBarcodeLookupPlugin
	{
		require_once VICTUAL_ROOT_PATH . '/plugins/DemoBarcodeLookupPlugin.php';

		return new \DemoBarcodeLookupPlugin(self::$locations, self::$quantityUnits, $userSettings);
	}

	/** The name is what the product picker shows for the configured source. */
	public function testDemoPluginDeclaresItsName(): void
	{
		self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1]);

		self::assertSame('Demo', \DemoBarcodeLookupPlugin::PLUGIN_NAME);
	}

	/** Its documented "nothing found" barcode returns null through the validation gate. */
	public function testDemoPluginReturnsNullForItsNotFoundBarcode(): void
	{
		self::assertNull(self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1])->Lookup('nothing'));
	}

	/** And its documented failure barcode raises the message it documents. */
	public function testDemoPluginRaisesItsDocumentedErrorMessage(): void
	{
		$caught = null;

		try
		{
			self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1])->Lookup('error');
		}
		catch (\Exception $ex)
		{
			$caught = $ex;
		}

		self::assertNotNull($caught, 'the error barcode must raise');
		self::assertSame('This is the error message from the plugin...', $caught->getMessage());
	}

	/**
	 * With no presets configured (-1 is the "none" value the settings use) the plugin falls
	 * back to the first location and the first quantity unit it was handed.
	 */
	public function testDemoPluginFallsBackToTheFirstLocationAndUnitWithoutPresets(): void
	{
		$result = self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1])->Lookup(self::BARCODE);

		self::assertSame(2, $result['location_id'], 'the first provided location');
		self::assertSame(2, $result['qu_id_purchase'], 'the first provided quantity unit');
		self::assertSame(2, $result['qu_id_stock'], 'stock and purchase unit are the same, so the factor is 1');
		self::assertSame(1, $result['__qu_factor_purchase_to_stock']);
		self::assertSame(self::BARCODE, $result['__barcode'], 'the scanned barcode is passed through');
		self::assertStringStartsWith('LookedUpProduct_', $result['name']);
		self::assertArrayNotHasKey('__image_url', $result, 'the demo source has no pictures, and the picture property is optional');
	}

	/**
	 * Negative control for the fallback: when the user has presets they win, and they are
	 * the second entries here so "first entry" cannot pass by accident.
	 */
	public function testDemoPluginPrefersTheUsersProductPresets(): void
	{
		$result = self::demo(['product_presets_location_id' => 5, 'product_presets_qu_id' => 3])->Lookup(self::BARCODE);

		self::assertSame(5, $result['location_id'], 'the preset location, not the first one');
		self::assertSame(3, $result['qu_id_purchase'], 'the preset quantity unit, not the first one');
		self::assertSame(3, $result['qu_id_stock']);
	}

	/**
	 * Boundary: the demo source has no notion of a malformed barcode, so an empty one is a
	 * hit like any other. Worth pinning because it is the shape a scanner sends when it
	 * reads nothing.
	 */
	public function testDemoPluginTreatsAnEmptyBarcodeAsAHit(): void
	{
		$result = self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1])->Lookup('');

		self::assertSame('', $result['__barcode'], 'current behaviour: an empty barcode produces a product to store under an empty barcode');
	}

	/**
	 * Two lookups of the same barcode invent two different products. That is the point of
	 * the demo source - it proves the wiring without pretending to be a database - and it
	 * is why it must never be the configured default.
	 */
	public function testDemoPluginInventsADifferentProductEachTime(): void
	{
		$plugin = self::demo(['product_presets_location_id' => -1, 'product_presets_qu_id' => -1]);

		$first = $plugin->Lookup(self::BARCODE);
		$second = $plugin->Lookup(self::BARCODE);

		self::assertNotSame($first['name'], $second['name'], 'the demo source invents names rather than resolving them');
		self::assertSame(21, strlen($first['name']), 'LookedUpProduct_ plus the documented five random characters');
	}

	// ---------------------------------------------------------------------------------
	// OpenFoodFactsBarcodeLookupPlugin, through the canned-payload subprocess
	// ---------------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $spec
	 * @return array{outcome: string, product: ?array, message: ?string, request_uri: ?string, request_headers: array, client_config: array, diagnostics: array}
	 */
	private static function openFoodFacts(array $spec): array
	{
		$spec = array_replace([
			'barcode' => self::BARCODE,
			'locale' => 'en',
			'status' => 200,
			'body' => '{}',
			'locations' => [['id' => 2, 'name' => 'Fridge'], ['id' => 5, 'name' => 'Pantry']],
			'quantity_units' => [['id' => 2, 'name' => 'Piece'], ['id' => 3, 'name' => 'Pack']],
			'user_settings' => ['product_presets_location_id' => -1, 'product_presets_qu_id' => -1]
		], $spec);

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($inherited, ['VICTUAL_ROOT' => VICTUAL_ROOT_PATH]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/barcodelookup-subprocess-helper.php', base64_encode(json_encode($spec))],
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

		$result = json_decode((string)$output, true);
		self::assertIsArray($result, "the lookup helper printed no JSON. stdout: $output\nstderr: $errors");

		return $result;
	}

	/** A real-shaped v2 payload for a product that exists. */
	private static function foundPayload(array $product = []): string
	{
		return json_encode([
			'code' => self::BARCODE,
			'status' => 1,
			'status_verbose' => 'product found',
			'product' => array_replace([
				'product_name' => 'Bio Kichererbsen',
				'image_url' => 'https://images.openfoodfacts.org/images/products/400/123/456/7890/front_de.4.400.jpg'
			], $product)
		]);
	}

	/**
	 * The happy path. What matters is the mapped product model, not the payload: the six
	 * required properties plus the picture, with the factor fixed at 1 because Open Food
	 * Facts has no notion of a purchase pack.
	 */
	public function testOpenFoodFactsMapsAFoundProductOntoTheProductModel(): void
	{
		$result = self::openFoodFacts(['body' => self::foundPayload()]);

		self::assertSame('hit', $result['outcome'], 'status 1 is a hit. ' . json_encode($result['diagnostics']));
		self::assertSame('Bio Kichererbsen', $result['product']['name']);
		self::assertSame(2, $result['product']['location_id'], 'no preset configured, so the first provided location');
		self::assertSame(2, $result['product']['qu_id_purchase'], 'no preset configured, so the first provided quantity unit');
		self::assertSame(2, $result['product']['qu_id_stock']);
		self::assertSame(1, $result['product']['__qu_factor_purchase_to_stock'], 'purchase and stock unit are the same, so no conversion');
		self::assertSame(self::BARCODE, $result['product']['__barcode']);
		self::assertSame('https://images.openfoodfacts.org/images/products/400/123/456/7890/front_de.4.400.jpg', $result['product']['__image_url']);
		self::assertSame([], $result['diagnostics'], 'a well formed payload must not raise anything');
	}

	/** Negative control on the presets, as for the demo source. */
	public function testOpenFoodFactsPrefersTheUsersProductPresets(): void
	{
		$result = self::openFoodFacts([
			'body' => self::foundPayload(),
			'user_settings' => ['product_presets_location_id' => 5, 'product_presets_qu_id' => 3]
		]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame(5, $result['product']['location_id'], 'the preset location, not the first one');
		self::assertSame(3, $result['product']['qu_id_purchase'], 'the preset quantity unit, not the first one');
		self::assertSame(3, $result['product']['qu_id_stock']);
	}

	/**
	 * Open Food Facts carries per-language names; the one matching the running locale is
	 * the one a user should see.
	 */
	public function testOpenFoodFactsPrefersTheNameInTheRunningLocale(): void
	{
		$result = self::openFoodFacts([
			'locale' => 'de_DE',
			'body' => self::foundPayload(['product_name_de' => 'Bio-Kichererbsen aus Deutschland'])
		]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame('Bio-Kichererbsen aus Deutschland', $result['product']['name'], 'the de name wins under a de locale');
		self::assertStringContainsString('product_name_de', $result['request_uri'], 'and the localized field is what was asked for');
	}

	/** Negative control: an empty localized name falls back to the generic one rather than blanking it. */
	public function testOpenFoodFactsFallsBackToTheGenericNameWhenTheLocalizedOneIsEmpty(): void
	{
		$result = self::openFoodFacts([
			'locale' => 'de_DE',
			'body' => self::foundPayload(['product_name_de' => ''])
		]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame('Bio Kichererbsen', $result['product']['name']);
	}

	/** A product with no picture yields an empty picture URL, not a missing property. */
	public function testOpenFoodFactsLeavesThePictureUrlEmptyWhenThePayloadHasNone(): void
	{
		$result = self::openFoodFacts(['body' => json_encode([
			'code' => self::BARCODE,
			'status' => 1,
			'product' => ['product_name' => 'Bio Kichererbsen']
		])]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame('', $result['product']['__image_url'], 'no picture is an empty string, which the caller treats as "no picture"');
		self::assertSame([], $result['diagnostics'], 'an absent picture is an ordinary payload, not an error');
	}

	/** status 0 is the API's "no product for this barcode", and it is a miss, not an error. */
	public function testOpenFoodFactsReturnsNullForStatusZero(): void
	{
		$result = self::openFoodFacts(['body' => json_encode([
			'code' => self::BARCODE,
			'status' => 0,
			'status_verbose' => 'product not found'
		])]);

		self::assertSame('miss', $result['outcome'], 'status 0 is a miss. ' . json_encode($result['diagnostics']));
	}

	/** So is a 404, which the v2 API returns for an unknown code. */
	public function testOpenFoodFactsReturnsNullForA404(): void
	{
		$result = self::openFoodFacts([
			'status' => 404,
			'body' => json_encode(['code' => self::BARCODE, 'status' => 0, 'status_verbose' => 'product not found'])
		]);

		self::assertSame('miss', $result['outcome']);
	}

	/**
	 * A server error is a miss even when its body is shaped like a hit: the client is
	 * built with http_errors off, so nothing but the status check stands between a 5xx
	 * body and a written product.
	 */
	public function testOpenFoodFactsTreatsAServerErrorAsAMissWhateverItsBody(): void
	{
		$result = self::openFoodFacts(['status' => 500, 'body' => self::foundPayload()]);

		self::assertSame('miss', $result['outcome'], 'a 500 is a miss. ' . json_encode($result['diagnostics']));
		self::assertSame([], $result['diagnostics']);
	}

	/**
	 * Boundary: a 200 whose body is not JSON at all - which is what a CDN or captive
	 * portal page is. It is a miss by a deliberate check, not by accident.
	 */
	public function testOpenFoodFactsTreatsAnUnparseableBodyAsAMissWithoutDiagnostics(): void
	{
		$result = self::openFoodFacts(['status' => 200, 'body' => '<html><body>Bad Gateway</body></html>']);

		self::assertSame('miss', $result['outcome'], 'an unparseable body is a miss. ' . json_encode($result['diagnostics']));
		self::assertSame([], $result['diagnostics'], 'json_decode() producing a non-object is checked before anything reads a property off it');
	}

	/**
	 * A payload that claims a hit but carries none of the fields the plugin maps.
	 */
	public function testOpenFoodFactsTreatsAPayloadWithNoProductObjectAsAMiss(): void
	{
		$result = self::openFoodFacts(['body' => json_encode(['code' => self::BARCODE, 'status' => 1])]);

		self::assertSame('miss', $result['outcome'], 'status 1 with no product object to map is a miss. ' . json_encode($result['diagnostics']));
		self::assertSame([], $result['diagnostics'], 'the missing product object is checked before anything reads a property off it');
	}

	/**
	 * The v2 product endpoint is addressed by barcode, so the payload's own code should
	 * match what was asked. Plan 09 makes comparing them the load-bearing step for any
	 * source whose lookup is a search rather than an address.
	 */
	public function testOpenFoodFactsDoesNotVerifyThePayloadsCodeAgainstTheBarcode(): void
	{
		// DEFECT (shortfall): the plugin never reads $data->code, and returns __barcode as
		// the barcode it was asked about rather than the one the payload describes. For the
		// v2 endpoint that is harmless; for the fuzzy search plan 09 proposes it is exactly
		// the silent wrong match the plan warns about, and there is no shared verification
		// step to inherit.
		$result = self::openFoodFacts(['body' => json_encode([
			'code' => '9999999999999',
			'status' => 1,
			'product' => ['product_name' => 'A completely different product']
		])]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame('A completely different product', $result['product']['name'], 'current behaviour: a payload about another product is accepted');
		self::assertSame(self::BARCODE, $result['product']['__barcode'], 'and is stored under the scanned barcode');
	}

	/**
	 * The outbound call itself: a fixed host and path, with only the digits of the barcode
	 * interpolated. AGENTS.md forbids a user-configurable outbound URL, so the host being
	 * compiled in is the property worth pinning.
	 */
	public function testOpenFoodFactsQueriesOnlyTheCompiledInOpenFoodFactsEndpoint(): void
	{
		$result = self::openFoodFacts(['body' => self::foundPayload()]);

		self::assertSame(
			'GET https://world.openfoodfacts.org/api/v2/product/4001234567890?fields=product_name,image_url,product_name_en',
			$result['request_uri'],
			'the endpoint is not configurable and must stay that way'
		);
		self::assertStringStartsWith('VictualOpenFoodFactsBarcodeLookupPlugin/', $result['request_headers']['User-Agent'], 'Open Food Facts requires an identifying User-Agent');
		self::assertFalse($result['client_config']['http_errors'], 'a 404 must reach the status check rather than raising');
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function barcodeToQueriedCodeProvider(): array
	{
		return [
			'plain EAN-13' => ['4001234567890', '4001234567890'],
			'leading zeros are kept' => ['0004001234567890', '0004001234567890'],
			'separators are stripped' => ['4-001 234.567890', '4001234567890'],
			'letters are stripped' => ['EAN4001234567890', '4001234567890'],
			'nothing numeric at all' => ['not-a-barcode', '']
		];
	}

	/**
	 * Boundary: the plugin reduces the scanned string to its digits before asking. Leading
	 * zeros are digits, so they are kept - which is the normalisation gap plan 09 names,
	 * visible here as the queried code.
	 */
	#[DataProvider('barcodeToQueriedCodeProvider')]
	public function testOpenFoodFactsAsksAboutTheDigitsOfTheScannedBarcode(string $scanned, string $queried): void
	{
		$result = self::openFoodFacts(['barcode' => $scanned, 'body' => self::foundPayload()]);

		self::assertSame(
			'GET https://world.openfoodfacts.org/api/v2/product/' . $queried . '?fields=product_name,image_url,product_name_en',
			$result['request_uri'],
			"scanning $scanned must ask about $queried"
		);
		self::assertSame($scanned, $result['product']['__barcode'], 'but the product is stored under the barcode as scanned, not as queried');
	}

	/**
	 * Boundary: an empty barcode. There is nothing to ask about, so the plugin makes no
	 * call rather than spending a round trip on a URL with no code in it.
	 */
	public function testOpenFoodFactsMakesNoRequestForAnEmptyBarcode(): void
	{
		$result = self::openFoodFacts(['barcode' => '', 'body' => json_encode(['status' => 0])]);

		self::assertNull($result['request_uri'], 'an empty barcode is a miss without a request');
		self::assertSame('miss', $result['outcome']);
	}

	/**
	 * A picture URL from the source reaches the caller unexamined here too - the same S14
	 * gap as at the validation gate, reached through a real source rather than a fixture.
	 */
	public function testOpenFoodFactsPassesWhateverPictureUrlThePayloadCarries(): void
	{
		// DEFECT: see testLookupDoesNotRefuseAPictureUrlNamingALoopbackOrPrivateHost. The
		// plugin copies $data->product->image_url into __image_url
		// (plugins/OpenFoodFactsBarcodeLookupPlugin.php:53) with only an emptiness check,
		// and nothing between there and the fetch at services/StockService.php:1014 looks
		// at the host or the extension.
		$result = self::openFoodFacts(['body' => self::foundPayload(['image_url' => 'http://169.254.169.254/latest/meta-data/x.php'])]);

		self::assertSame('hit', $result['outcome']);
		self::assertSame('http://169.254.169.254/latest/meta-data/x.php', $result['product']['__image_url'], 'current behaviour: the payload chooses the URL the deployment will fetch');
	}

	/** The name is what the product picker shows for the configured source. */
	public function testOpenFoodFactsDeclaresItsName(): void
	{
		require_once VICTUAL_ROOT_PATH . '/plugins/OpenFoodFactsBarcodeLookupPlugin.php';

		self::assertSame('Open Food Facts', \OpenFoodFactsBarcodeLookupPlugin::PLUGIN_NAME);
	}
}

/**
 * A source whose answer is decided by the test. This is the substitution
 * BaseBarcodeLookupPlugin's own documentation describes - ExecuteLookup() is the abstract
 * method a source implements, and overriding it is how the template method around it is
 * reached without a source attached. Nothing else is stubbed: Lookup() runs for real.
 */
class CannedBarcodeLookupPlugin extends BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'Canned';

	/** @var mixed What ExecuteLookup() hands back. */
	public $Canned = null;

	/** @var array<int, string> Every barcode this source was asked about, in order. */
	public array $SeenBarcodes = [];

	protected function ExecuteLookup($barcode)
	{
		$this->SeenBarcodes[] = $barcode;

		return $this->Canned;
	}
}
