<?php

namespace Victual\Helpers;

/**
 * Normalises a GTIN/EAN/UPC barcode for comparison across the widths a scanner or a source
 * hands back - UPC-A (12 digits), EAN-8 (8), EAN-13 (13) and GTIN-14 (14) can all name the
 * same product, differing only in how many leading zeros GS1's embedding rule pads them
 * with. docs/plans/09-barcode-lookup-sources.md step 3 names exactly this normalisation
 * (leading zeros and length) as what a fuzzy source's verification step needs before
 * comparing the barcode it was asked about with the one a search result claims.
 *
 * This class does not validate the GS1 check digit (the barcode's last digit). Plan 09 asks
 * only for leading-zero/length normalisation, not for rejecting a malformed code, and
 * padding a code never touches its check digit - the digit a scanner or a source already
 * produced is trusted as given, correct or not. A caller that wants check-digit validation
 * has to add it itself.
 *
 * Issue #460 introduces this as plan 09 groundwork, used by no caller yet: the base class
 * comparison it could support (refusing a plugin result whose __barcode does not match the
 * one asked about) is deliberately not implemented here - it would change
 * OpenFoodFactsBarcodeLookupPlugin's and every custom plugin's behaviour, and plan 09 places
 * that check in the still-unbuilt USDA FoodData Central plugin instead, where a fuzzy full
 * text search actually needs it.
 */
final class Gtin
{
	/** Digit counts GS1's embedding rule zero-pads up to GTIN_LENGTH; any other length is left alone. */
	private const NORMALIZABLE_LENGTHS = [8, 12, 13, 14];

	private const GTIN_LENGTH = 14;

	/**
	 * Left-pads an all-digit 8/12/13/14-digit code with zeros to GTIN-14 width. Anything
	 * else - a non-numeric string, an empty string, or an all-digit code of some other
	 * length - is returned unchanged, since it is not one of the widths GS1 defines a
	 * zero-padding embedding for.
	 */
	public static function Normalize(string $barcode): string
	{
		if ($barcode === '' || !ctype_digit($barcode) || !in_array(strlen($barcode), self::NORMALIZABLE_LENGTHS, true))
		{
			return $barcode;
		}

		return str_pad($barcode, self::GTIN_LENGTH, '0', STR_PAD_LEFT);
	}

	/**
	 * Whether $a and $b name the same product once both are normalised - true for a UPC-A,
	 * an EAN-13 and a GTIN-14 of the same item, and for two identical non-numeric or
	 * odd-length codes (Normalize() is the identity function there, so this degenerates to
	 * an exact string comparison). False for two codes that are only different once
	 * normalised, including two codes of the same length differing solely in their check
	 * digit - Normalize() does not touch that digit, so it is not what makes two codes equal
	 * here.
	 */
	public static function SameGtin(string $a, string $b): bool
	{
		return self::Normalize($a) === self::Normalize($b);
	}
}
