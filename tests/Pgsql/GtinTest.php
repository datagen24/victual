<?php

namespace Victual\Tests\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Victual\Helpers\Gtin;

/**
 * helpers/Gtin.php: pure functions, no database and no network. Issue #460's plan 09
 * groundwork - the normaliser plan 09 step 3 names for a fuzzy source's verification step,
 * not yet used by any caller (see the class docblock for why the base-class comparison it
 * could support is out of scope here).
 */
class GtinTest extends TestCase
{
	/** @return array<string, array{0: string, 1: string}> Input, expected Normalize() output. */
	public static function normalizeProvider(): array
	{
		return [
			'EAN-8 is padded to GTIN-14' => ['04512345', '00000004512345'],
			'UPC-A (12 digits) is padded to GTIN-14' => ['040123456789', '00040123456789'],
			'EAN-13 is padded to GTIN-14' => ['0040123456789', '00040123456789'],
			'GTIN-14 is already GTIN-14, unchanged' => ['00040123456789', '00040123456789'],
			'a 13-digit code with no leading zero is padded, not treated as UPC-A' => ['4001234567890', '04001234567890'],
			'non-numeric is left unchanged' => ['ABC123456789', 'ABC123456789'],
			'empty string is left unchanged' => ['', ''],
			'7 digits (not one of the four GS1 widths) is left unchanged' => ['4512345', '4512345'],
			'11 digits (not one of the four GS1 widths) is left unchanged' => ['40123456789', '40123456789'],
			'15 digits (not one of the four GS1 widths) is left unchanged' => ['400123456789012', '400123456789012'],
			'a code containing a decimal point is not all-digit, left unchanged' => ['4001234.67890', '4001234.67890'],
			'leading/trailing whitespace makes it not all-digit, left unchanged' => [' 4001234567890', ' 4001234567890'],
		];
	}

	#[DataProvider('normalizeProvider')]
	public function testNormalize(string $input, string $expected): void
	{
		self::assertSame($expected, Gtin::Normalize($input));
	}

	/**
	 * The motivating case: a UPC-A, an EAN-13 and a GTIN-14 that all name the same product
	 * (UPC-A embeds into EAN-13 by a single leading zero, and into GTIN-14 by two, which is
	 * exactly what a barcode scanner or a source's own product record hands back depending
	 * on which symbology it read or which width the source stores).
	 */
	public function testSameGtinAcrossUpcAEan13AndGtin14OfTheSameProduct(): void
	{
		$upcA = '040123456789';
		$ean13 = '0040123456789';
		$gtin14 = '00040123456789';

		self::assertTrue(Gtin::SameGtin($upcA, $ean13), 'UPC-A and its EAN-13 embedding name the same product');
		self::assertTrue(Gtin::SameGtin($upcA, $gtin14), 'UPC-A and its GTIN-14 embedding name the same product');
		self::assertTrue(Gtin::SameGtin($ean13, $gtin14), 'EAN-13 and its GTIN-14 embedding name the same product');
	}

	/** An EAN-8 is its own, unrelated code space - padding it does not coincidentally collide with the UPC-A example above. */
	public function testSameGtinDoesNotConfuseAnEan8WithAnUnrelatedGtin14(): void
	{
		self::assertFalse(Gtin::SameGtin('04512345', '00040123456789'));
	}

	/** A non-numeric code is never mistaken for a match against a real GTIN, and is compared only against itself. */
	public function testSameGtinTreatsNonNumericCodesAsTheIdentityFunction(): void
	{
		self::assertTrue(Gtin::SameGtin('ABC123456789', 'ABC123456789'), 'identical non-numeric codes still compare equal');
		self::assertFalse(Gtin::SameGtin('ABC123456789', '00ABC123456789'), 'non-numeric codes are never padded, so a superficially "padded" variant is a different string');
	}

	/**
	 * Two codes of the same length differing only in their last digit - the position a GS1
	 * check digit occupies - are not the same product. This class does not validate or
	 * compute a check digit at all (see the class docblock: plan 09 asks only for
	 * leading-zero/length normalisation); what this proves is narrower and load-bearing
	 * regardless of that choice - Normalize() must not coincidentally treat two genuinely
	 * different codes as equal.
	 */
	public function testSameGtinTreatsACheckDigitMismatchAsADifferentCode(): void
	{
		$validLooking = '4001234567890';
		$differentLastDigit = '4001234567891';

		self::assertFalse(Gtin::SameGtin($validLooking, $differentLastDigit));

		// Neither call threw, and each still compares equal to itself: Normalize() performs
		// no check-digit validation, so a "wrong" check digit is padded exactly like a
		// "right" one would be, not rejected.
		self::assertTrue(Gtin::SameGtin($differentLastDigit, $differentLastDigit));
		self::assertSame('04001234567891', Gtin::Normalize($differentLastDigit));
	}
}
