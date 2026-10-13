<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Helpers\BaseBarcodeLookupPlugin;

/**
 * Issue #844: what BaseBarcodeLookupPlugin::Lookup() does with a source's non-finite
 * numbers and its non-string names. No database, like BarcodeLookupTest; the add=true
 * end of the same issue (nothing written, a 400 on the wire) is in
 * tests/Pgsql/BarcodeLookupNonFiniteHttpTest.php.
 *
 * Every refusal message is asserted whole: it names the key path and nothing of the value.
 */
class BarcodeLookupNonFiniteTest extends TestCase
{
	private const BARCODE = '4001234567890';

	private static function plugin($canned): NonFiniteCannedBarcodeLookupPlugin
	{
		$plugin = new NonFiniteCannedBarcodeLookupPlugin(
			[(object)['id' => 2, 'name' => 'Fridge']],
			[(object)['id' => 2, 'name' => 'Piece'], (object)['id' => 3, 'name' => 'Pack']],
			['product_presets_location_id' => -1, 'product_presets_qu_id' => -1]
		);
		$plugin->Canned = $canned;

		return $plugin;
	}

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

	private function assertRefused(array $canned, string $expectedMessage): void
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

		self::assertNotNull($caught, 'Lookup() returned instead of throwing');
		self::assertSame($expectedMessage, $caught->getMessage());
	}

	/** @return array<string, array{0: float}> */
	public static function nonFiniteProvider(): array
	{
		return [
			'INF' => [INF],
			'-INF' => [-INF],
			'NAN' => [NAN],
			'1e400 decoded from JSON' => [json_decode('1e400')],
		];
	}

	/** A non-finite float is not a string, so the name check refuses it first. */
	#[DataProvider('nonFiniteProvider')]
	public function testANonFiniteNameIsRefused(float $value): void
	{
		$this->assertRefused(self::validOutput(['name' => $value]), 'Provided name must be a string');
	}

	#[DataProvider('nonFiniteProvider')]
	public function testANonFiniteTopLevelFieldIsRefusedAndNamesItsKey(float $value): void
	{
		$this->assertRefused(self::validOutput(['min_stock_amount' => $value]), 'Plugin output contains a non-finite number at min_stock_amount');
	}

	public function testANonFiniteNumberNestedAtAnyDepthIsRefusedAndNamesItsPath(): void
	{
		$this->assertRefused(
			self::validOutput(['product' => ['nutrition' => ['calories' => NAN]]]),
			'Plugin output contains a non-finite number at product.nutrition.calories'
		);

		$this->assertRefused(
			self::validOutput(['tags' => ['ok', INF]]),
			'Plugin output contains a non-finite number at tags.1'
		);
	}

	public function testANonFiniteNumberInsideANestedObjectIsRefusedAndNamesItsPath(): void
	{
		$this->assertRefused(
			self::validOutput(['extra' => (object)['calories' => NAN]]),
			'Plugin output contains a non-finite number at extra.calories'
		);

		$this->assertRefused(
			self::validOutput(['extra' => ['inner' => (object)['list' => [1.0, INF]]]]),
			'Plugin output contains a non-finite number at extra.inner.list.1'
		);
	}

	/** An id's own refusal message embeds the value, so the walk has to come first. */
	public function testANonFiniteIdIsRefusedWithThePathAndNotTheValue(): void
	{
		$this->assertRefused(
			self::validOutput(['location_id' => INF]),
			'Plugin output contains a non-finite number at location_id'
		);
	}

	/** @return array<string, array{0: mixed}> */
	public static function nonStringNameProvider(): array
	{
		return [
			'int' => [123],
			'float' => [1.5],
			'bool' => [true],
			'array' => [['Beans']],
			'object' => [new \stdClass()],
		];
	}

	#[DataProvider('nonStringNameProvider')]
	public function testANameThatIsNotAStringIsRefused($name): void
	{
		$this->assertRefused(self::validOutput(['name' => $name]), 'Provided name must be a string');
	}

	/** @return array<string, array{0: string}> */
	public static function blankNameProvider(): array
	{
		return [
			'spaces' => ['   '],
			'tab and newline' => ["\t\n"],
		];
	}

	#[DataProvider('blankNameProvider')]
	public function testANameOfOnlyWhitespaceIsRefusedAsEmpty(string $name): void
	{
		$this->assertRefused(self::validOutput(['name' => $name]), 'Provided name is empty');
	}

	/** Negative control: finite numbers at every depth, including zero and negatives, still pass untouched. */
	public function testAValidOutputWithFiniteNumbersAtAnyDepthIsReturnedUnchanged(): void
	{
		$canned = self::validOutput([
			'min_stock_amount' => 0.0,
			'product' => ['calories' => -1.5, 'nutrition' => ['fat' => 1.0e300]],
		]);

		self::assertSame($canned, self::plugin($canned)->Lookup(self::BARCODE));
	}

	/** "0" is a legal product name; empty() used to refuse it. */
	public function testANameOfZeroIsAName(): void
	{
		$canned = self::validOutput(['name' => '0']);

		self::assertSame($canned, self::plugin($canned)->Lookup(self::BARCODE));
	}

	public function testAMissLeavesTheLookupNull(): void
	{
		self::assertNull(self::plugin(null)->Lookup(self::BARCODE));
	}
}

class NonFiniteCannedBarcodeLookupPlugin extends BaseBarcodeLookupPlugin
{
	public const PLUGIN_NAME = 'NonFiniteCanned';

	/** @var mixed What ExecuteLookup() hands back. */
	public $Canned = null;

	protected function ExecuteLookup($barcode)
	{
		return $this->Canned;
	}
}
