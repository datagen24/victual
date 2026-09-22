<?php

namespace Victual\Tests\Support;

/**
 * Font bytes for the tests that ask what a font calls itself.
 *
 * `LabelAssetService` reads the family and subfamily out of the sfnt `name` table and
 * records them beside the stored bytes, so the tests that pin that behaviour need fonts to
 * hand it. Two of them reached for a TrueType file sitting in a dependency's documentation
 * directory. php-di marks that directory `export-ignore`, so the file is there in a source
 * install and absent from every dist install: the tests passed in a working copy and could
 * not pass in CI.
 *
 * This builds a font instead, to the OpenType specification rather than to the part the
 * decoder reads. The table directory carries five tables sorted by tag with computed binary
 * search fields, each table is padded to a four-byte boundary and checksummed, and
 * `head.checkSumAdjustment` is written from the whole assembled font. A decoder that stopped
 * reading real fonts fails against these bytes too, and nothing outside this repository has
 * to exist for the test to run.
 *
 * `name` sorts fourth of the five tags, so finding it means walking the directory rather
 * than reading the first entry.
 */
final class SfntFixture
{
	private const MACINTOSH = 1;
	private const WINDOWS = 3;

	/**
	 * A font named the way a shipped font is named: every one of nameIDs 0 to 6, on both the
	 * Macintosh and the Windows platform, so the family and subfamily are two records among
	 * fourteen rather than the only two present.
	 */
	public static function Shipped(string $family, string $subfamily, string $signature = "\x00\x01\x00\x00"): string
	{
		$compact = str_replace(' ', '', $family) . '-' . str_replace(' ', '', $subfamily);

		$names = [
			0 => 'Copyright (c) the Victual test suite',
			1 => $family,
			2 => $subfamily,
			3 => 'Victual;' . $compact,
			4 => $family . ' ' . $subfamily,
			5 => 'Version 1.000',
			6 => $compact,
		];

		return self::Font($names, $names, $signature);
	}

	/**
	 * @param array<int, string> $macintosh nameID to value, stored as MacRoman (platform 1)
	 * @param array<int, string> $windows   nameID to value, stored as UTF-16BE (platform 3)
	 */
	public static function Font(array $macintosh, array $windows, string $signature = "\x00\x01\x00\x00"): string
	{
		$tables = [
			'head' => self::Head(),
			'hhea' => self::Hhea(),
			'maxp' => self::Maxp(),
			'name' => self::NameTable($macintosh, $windows),
			'post' => self::Post(),
		];

		ksort($tables);

		return self::Assemble($signature, $tables);
	}

	/** @param array<string, string> $tables Tag to table bytes, already in tag order. */
	private static function Assemble(string $signature, array $tables): string
	{
		$count = count($tables);

		// The offset table's binary search fields: the largest power of two no greater than
		// the table count, times the sixteen bytes a record takes, and the log of that power.
		$entrySelector = (int)floor(log($count, 2));
		$searchRange = (2 ** $entrySelector) * 16;

		$offset = 12 + $count * 16;
		$records = '';
		$body = '';
		$headOffset = null;

		foreach ($tables as $tag => $data)
		{
			$padded = $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);

			if ($tag === 'head')
			{
				$headOffset = $offset;
			}

			// The length recorded is the table's own, not the padded one: the padding exists
			// to align what follows and is not part of the table.
			$records .= $tag . pack('N', self::Checksum($padded)) . pack('N', $offset) . pack('N', strlen($data));
			$body .= $padded;
			$offset += strlen($padded);
		}

		$font = $signature . pack('nnnn', $count, $searchRange, $entrySelector, $count * 16 - $searchRange)
			. $records . $body;

		// `head.checkSumAdjustment` is 0xB1B0AFBA less the checksum of the whole font taken
		// with that field zero - which is what it is until this line, both here and in the
		// directory entry's own checksum, as the specification requires. It cannot be known
		// before the font is assembled, so it is written back into the assembled bytes.
		$adjustment = (0xB1B0AFBA - self::Checksum($font)) & 0xFFFFFFFF;

		return substr_replace($font, pack('N', $adjustment), $headOffset + 8, 4);
	}

	/** The sum of the bytes read as big-endian 32-bit words, zero-padded to a word boundary. */
	private static function Checksum(string $bytes): int
	{
		$bytes .= str_repeat("\x00", (4 - strlen($bytes) % 4) % 4);
		$sum = 0;

		foreach (unpack('N*', $bytes) as $word)
		{
			$sum = ($sum + $word) & 0xFFFFFFFF;
		}

		return $sum;
	}

	/**
	 * Format 0, with the records in the order the specification requires: platform, then
	 * encoding, then language, then nameID.
	 *
	 * @param array<int, string> $macintosh
	 * @param array<int, string> $windows
	 */
	private static function NameTable(array $macintosh, array $windows): string
	{
		$entries = [];

		foreach ($macintosh as $id => $value)
		{
			$entries[] = [self::MACINTOSH, 0, 0, (int)$id, $value];
		}

		foreach ($windows as $id => $value)
		{
			$entries[] = [self::WINDOWS, 1, 0x409, (int)$id, mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')];
		}

		usort($entries, static function (array $a, array $b): int
		{
			return [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]];
		});

		$records = '';
		$storage = '';

		foreach ($entries as [$platform, $encoding, $language, $id, $encoded])
		{
			$records .= pack('nnnnnn', $platform, $encoding, $language, $id, strlen($encoded), strlen($storage));
			$storage .= $encoded;
		}

		return pack('nnn', 0, count($entries), 6 + count($entries) * 12) . $records . $storage;
	}

	/** 54 bytes. `checkSumAdjustment` stays zero here; Assemble() writes the real one. */
	private static function Head(): string
	{
		return pack('NNNNnnJJnnnnnnnnn',
			0x00010000, 0x00010000, 0, 0x5F0F3CF5,
			0, 1000,
			0, 0,
			0, 0, 1000, 1000,
			0, 8, 2, 0, 0);
	}

	/** 36 bytes. 0xFF38 is -200 as a signed 16-bit descender. */
	private static function Hhea(): string
	{
		return pack('Nnnnnnnnnnnnnnnnn',
			0x00010000, 800, 0xFF38, 0, 600, 0, 0, 600, 1, 0, 0,
			0, 0, 0, 0,
			0, 1);
	}

	/** 32 bytes, version 1.0. */
	private static function Maxp(): string
	{
		return pack('Nnnnnnnnnnnnnnn', 0x00010000, 1, 0, 0, 0, 0, 2, 0, 0, 0, 0, 0, 0, 0, 0);
	}

	/** 32 bytes, version 3.0: no glyph names, which is what an outline font without them says. */
	private static function Post(): string
	{
		return pack('NNnnNNNNN', 0x00030000, 0, 0xFF9C, 50, 0, 0, 0, 0, 0);
	}
}
