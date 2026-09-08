<?php

namespace Victual\Helpers;

/**
 * RFC 8785 JSON Canonicalization Scheme.
 *
 * Structured digests in the label subsystem cover a *document*, not a serialization, so the
 * bytes a digest is taken over have to be produced by a rule rather than by whatever order
 * an associative array happened to be built in. Plan 27 names RFC 8785 and ADR-0021
 * prerequisite 5 established, by running it, that nothing here can be configured into the
 * job: `composer.json` carries no canonicalization library, and PHP's `json_encode` is not
 * one - it writes `1.0e+30` for `1e+30`, `1.0e-7` for `1e-7`, `a\/b` for `a/b` and `é`
 * for `é`. The number layout and the string escaping are therefore implemented here.
 *
 * Three rules the prerequisite forced out, each of which a plausible implementation gets
 * wrong, and each of which has an assertion in `.devtools/labels/canonical-json-tests.php`:
 *
 * - **Keys sort by UTF-16 code unit, which is not UTF-8 byte order.** An astral character is
 *   a surrogate pair whose units are below U+E000, so `😀` sorts *before* `ﬀ` and a byte-order
 *   sort reverses them - for exactly the keys a household's own language data carries.
 * - **Numbers follow ECMAScript `Number::toString`.** PHP's shortest-round-trip digits are
 *   right and its layout is not; the 1e+21 and 1e-7 boundaries and the smallest subnormal are
 *   where a hand-rolled formatter goes wrong.
 * - **Integers are refused by exact representability, not by the safe-integer range.** 2^53 is
 *   representable and 2^53+1 is not. NaN and Infinity are refused rather than encoded.
 */
class CanonicalJson
{
	/**
	 * The canonical UTF-8 encoding of a document.
	 *
	 * @param mixed $value A value composed of arrays, objects, strings, ints, floats, bools
	 *                     and nulls. An array with sequential integer keys from zero is an
	 *                     array; every other array and every stdClass is an object.
	 * @throws ECanonicalizationFailed When the document holds something RFC 8785 cannot encode
	 */
	public static function Encode($value): string
	{
		return self::EncodeValue($value, 0);
	}

	/**
	 * The SHA-256 of the canonical encoding, lowercase hexadecimal.
	 *
	 * This is the only digest a structured document is ever identified by. Bytes - an
	 * artifact, an asset - are digested over their exact bytes instead and never come
	 * through here.
	 *
	 * @throws ECanonicalizationFailed
	 */
	public static function Digest($value): string
	{
		return hash('sha256', self::Encode($value));
	}

	/** Recursion is bounded so a cyclic or pathological document fails rather than the process. */
	private const MAX_DEPTH = 64;

	private static function EncodeValue($value, int $depth): string
	{
		if ($depth > self::MAX_DEPTH)
		{
			throw new ECanonicalizationFailed('Document nests deeper than ' . self::MAX_DEPTH . ' levels');
		}

		if ($value === null)
		{
			return 'null';
		}

		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		if (is_int($value))
		{
			return self::EncodeInteger($value);
		}

		if (is_float($value))
		{
			return self::EncodeDouble($value);
		}

		if (is_string($value))
		{
			return self::EncodeString($value);
		}

		if (is_array($value))
		{
			return self::IsList($value)
				? self::EncodeList($value, $depth)
				: self::EncodeObject($value, $depth);
		}

		if ($value instanceof \stdClass)
		{
			return self::EncodeObject(get_object_vars($value), $depth);
		}

		throw new ECanonicalizationFailed('Value of type ' . get_debug_type($value) . ' has no canonical form');
	}

	/**
	 * An array is a JSON array only when its keys are 0..n-1 in order. Anything else - a
	 * string-keyed map, a sparse list, a list built by unset() - is an object, because
	 * silently renumbering it would change the document the digest is supposed to identify.
	 */
	private static function IsList(array $value): bool
	{
		return array_is_list($value);
	}

	private static function EncodeList(array $value, int $depth): string
	{
		$parts = [];

		foreach ($value as $item)
		{
			$parts[] = self::EncodeValue($item, $depth + 1);
		}

		return '[' . implode(',', $parts) . ']';
	}

	private static function EncodeObject(array $members, int $depth): string
	{
		$keys = [];

		foreach ($members as $key => $ignored)
		{
			// A PHP array key that looks like an integer arrives as an int. RFC 8785 sorts
			// member names as strings, so it becomes one here rather than at comparison time.
			$keys[] = (string)$key;
		}

		usort($keys, [self::class, 'CompareByCodeUnit']);

		$parts = [];

		foreach ($keys as $key)
		{
			$parts[] = self::EncodeString($key) . ':' . self::EncodeValue($members[$key], $depth + 1);
		}

		return '{' . implode(',', $parts) . '}';
	}

	/**
	 * Orders two member names by UTF-16 code unit, as RFC 8785 requires and as
	 * `Array.prototype.sort` on JavaScript strings already does.
	 *
	 * Converting to UTF-16BE and comparing bytes is exactly that comparison: a code point
	 * above the BMP becomes a surrogate pair whose leading unit is in D800-DBFF, so it sorts
	 * below every BMP character from E000 up. Comparing the UTF-8 bytes instead would put it
	 * above them, which is the defect this function exists to avoid.
	 */
	private static function CompareByCodeUnit(string $left, string $right): int
	{
		return strcmp(self::ToUtf16Be($left), self::ToUtf16Be($right));
	}

	private static function ToUtf16Be(string $value): string
	{
		$converted = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

		if ($converted === false)
		{
			throw new ECanonicalizationFailed('Member name is not valid UTF-8');
		}

		return $converted;
	}

	/**
	 * Escapes exactly what ECMAScript's JSON.stringify escapes: the quote, the reverse
	 * solidus, and the C0 controls - the last as their two-character shortcuts where one
	 * exists and as a lowercase \u00xx escape otherwise. Everything else, `/` and every
	 * non-ASCII character included, is emitted as its own UTF-8 bytes.
	 */
	private static function EncodeString(string $value): string
	{
		if (!mb_check_encoding($value, 'UTF-8'))
		{
			throw new ECanonicalizationFailed('String is not valid UTF-8');
		}

		$out = '"';
		$length = strlen($value);

		for ($i = 0; $i < $length; $i++)
		{
			$byte = $value[$i];
			$code = ord($byte);

			if ($byte === '"')
			{
				$out .= '\\"';
			}
			elseif ($byte === '\\')
			{
				$out .= '\\\\';
			}
			elseif ($code >= 0x20)
			{
				$out .= $byte;
			}
			else
			{
				$out .= match ($code)
				{
					0x08 => '\\b',
					0x09 => '\\t',
					0x0A => '\\n',
					0x0C => '\\f',
					0x0D => '\\r',
					default => sprintf('\\u%04x', $code)
				};
			}
		}

		return $out . '"';
	}

	/**
	 * A PHP integer, refused when it does not survive the round trip through a double.
	 *
	 * RFC 8785's number model is IEEE-754 binary64, so an integer it cannot represent has no
	 * canonical form and is an error rather than something to round. The test is exact
	 * representability rather than the safe-integer range, because 2^53 is representable and
	 * 2^53+1 is not - a range check would reject the first or accept the second.
	 *
	 * It is spelled as a decimal comparison rather than as `(int)(float)$value !== $value`
	 * because that cast is undefined above PHP_INT_MAX: converting PHP_INT_MAX to a double
	 * gives 2^63, and converting *that* back is a warning and an implementation-defined
	 * result rather than the mismatch the check wants to observe.
	 */
	private static function EncodeInteger(int $value): string
	{
		if (sprintf('%.0F', (float)$value) !== (string)$value)
		{
			throw new ECanonicalizationFailed('Integer ' . $value . ' is not exactly representable as a double');
		}

		return (string)$value;
	}

	/**
	 * A double, laid out by ECMAScript's Number::toString.
	 *
	 * PHP already produces the shortest round-tripping digits through `json_encode` under
	 * `serialize_precision = -1`; what it does not produce is the layout, which is why this
	 * takes those digits apart and puts them back together by the specification's own rule.
	 */
	private static function EncodeDouble(float $value): string
	{
		if (is_nan($value) || is_infinite($value))
		{
			throw new ECanonicalizationFailed('NaN and Infinity have no canonical form');
		}

		// RFC 8785 keeps ECMAScript's rule that negative zero serializes as "0".
		if ($value === 0.0)
		{
			return '0';
		}

		$negative = $value < 0;
		[$digits, $exponent] = self::ShortestDigits(abs($value));

		return ($negative ? '-' : '') . self::LayOut($digits, $exponent);
	}

	/**
	 * Decomposes a positive finite double into the shortest digit string `s` and the
	 * exponent `n` for which the value is `0.s * 10^n` - the (s, k, n) triple ECMAScript's
	 * Number::toString is written against, with k implied by strlen($digits).
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function ShortestDigits(float $value): array
	{
		// serialize_precision defaults to -1 (shortest round trip). It is set explicitly
		// rather than trusted, because a php.ini that changed it would change every digest.
		$previous = ini_get('serialize_precision');
		ini_set('serialize_precision', '-1');

		try
		{
			$repr = json_encode($value);
		}
		finally
		{
			ini_set('serialize_precision', (string)$previous);
		}

		if (!is_string($repr))
		{
			throw new ECanonicalizationFailed('Double has no shortest representation');
		}

		$exponent = 0;
		$position = strpbrk($repr, 'eE');

		if ($position !== false)
		{
			$exponent = (int)substr($position, 1);
			$repr = substr($repr, 0, strlen($repr) - strlen($position));
		}

		$point = strpos($repr, '.');

		if ($point === false)
		{
			$integerLength = strlen($repr);
			$digits = $repr;
		}
		else
		{
			$integerLength = $point;
			$digits = substr($repr, 0, $point) . substr($repr, $point + 1);
		}

		// value = 0.<digits> * 10^(integerLength + exponent), before the digit string is
		// normalized. Stripping a leading zero moves the point one place right of where it
		// was, so the exponent follows it down.
		$n = $integerLength + $exponent;

		$leading = strspn($digits, '0');
		$digits = substr($digits, $leading);
		$n -= $leading;

		$digits = rtrim($digits, '0');

		if ($digits === '')
		{
			// Only reachable for a zero, which EncodeDouble() has already answered.
			throw new ECanonicalizationFailed('Double decomposed to no significant digits');
		}

		return [$digits, $n];
	}

	/**
	 * ECMAScript Number::toString steps 6 through 10, over `0.<digits> * 10^n`.
	 */
	private static function LayOut(string $digits, int $n): string
	{
		$k = strlen($digits);

		if ($k <= $n && $n <= 21)
		{
			return $digits . str_repeat('0', $n - $k);
		}

		if (0 < $n && $n <= 21)
		{
			return substr($digits, 0, $n) . '.' . substr($digits, $n);
		}

		if (-6 < $n && $n <= 0)
		{
			return '0.' . str_repeat('0', -$n) . $digits;
		}

		$power = $n - 1;
		$sign = $power >= 0 ? '+' : '-';
		$mantissa = $k === 1 ? $digits : ($digits[0] . '.' . substr($digits, 1));

		return $mantissa . 'e' . $sign . abs($power);
	}
}
