<?php

namespace Victual\Tests\Support;

/**
 * Turns a decoded JSON value into its shape: the key set of every object it contains and
 * the scalar type of every leaf, never the values themselves - plan 14 piece 2's own rule,
 * because a value is fixture-dependent (row counts, ids, timestamps) and a snapshot that
 * compared values would fail on every run for reasons that have nothing to do with the
 * wire contract.
 *
 * The shape of a JSON array is the union of its items' shapes collapsed to one
 * representative element, so a list of ten rows and a list of one compare equal as long
 * as the rows agree on their keys and each key's type - which is the property a response
 * contract snapshot actually wants (see MergeShapes). A key that is sometimes null and
 * sometimes typed keeps both: "integer|null" rather than either type alone, because a
 * client written against one observed type is exactly what a silently-nullable column
 * would break.
 */
class JsonShape
{
	/** @param mixed $value A value already run through json_decode(..., true). */
	public static function Of($value, int $depth = 0)
	{
		if ($depth > 10)
		{
			return 'truncated';
		}

		if ($value === null)
		{
			return 'null';
		}

		if (is_bool($value))
		{
			return 'boolean';
		}

		if (is_int($value))
		{
			return 'integer';
		}

		if (is_float($value))
		{
			return 'number';
		}

		if (is_string($value))
		{
			return 'string';
		}

		if (is_array($value))
		{
			if (self::IsList($value))
			{
				if (count($value) === 0)
				{
					return ['__empty_list__' => true];
				}

				$itemShapes = [];
				foreach ($value as $item)
				{
					$itemShapes[] = self::Of($item, $depth + 1);
				}

				return [self::MergeShapes($itemShapes)];
			}

			$out = [];
			foreach ($value as $key => $v)
			{
				$out[$key] = self::Of($v, $depth + 1);
			}
			ksort($out);
			return $out;
		}

		return 'unknown';
	}

	private static function IsList(array $value): bool
	{
		if ($value === [])
		{
			return true;
		}

		return array_keys($value) === range(0, count($value) - 1);
	}

	/**
	 * Merges the shapes of every item of a JSON array into one representative shape.
	 * Scalars merge into a sorted "type1|type2" union string; objects merge key by key,
	 * a key missing from some items keeps only the types the items that had it produced
	 * plus "missing" so an inconsistently-present key is visible rather than silently
	 * dropped; a mix of scalar and object shapes for the same array (which the API never
	 * does deliberately) is reported as a union string naming both kinds, since there is
	 * no better representation for output that is not internally consistent.
	 *
	 * @param array $shapes
	 * @return mixed
	 */
	public static function MergeShapes(array $shapes)
	{
		$allScalar = true;
		$allObject = true;
		foreach ($shapes as $s)
		{
			if (!is_string($s))
			{
				$allScalar = false;
			}
			if (!is_array($s) || (count($s) === 1 && array_key_exists(0, $s)))
			{
				$allObject = false;
			}
		}

		if ($allScalar)
		{
			$types = [];
			foreach ($shapes as $s)
			{
				foreach (explode('|', $s) as $t)
				{
					$types[$t] = true;
				}
			}
			$types = array_keys($types);
			sort($types);
			return implode('|', $types);
		}

		if ($allObject)
		{
			$keys = [];
			foreach ($shapes as $s)
			{
				foreach (array_keys($s) as $k)
				{
					$keys[$k] = true;
				}
			}

			$merged = [];
			foreach (array_keys($keys) as $key)
			{
				$present = [];
				$missing = false;
				foreach ($shapes as $s)
				{
					if (array_key_exists($key, $s))
					{
						$present[] = $s[$key];
					}
					else
					{
						$missing = true;
					}
				}
				$sub = count($present) > 0 ? self::MergeShapes($present) : 'unknown';
				if ($missing && is_string($sub))
				{
					$sub = implode('|', array_unique(array_merge(explode('|', $sub), ['missing'])));
					sort($types = explode('|', $sub));
					$sub = implode('|', $types);
				}
				$merged[$key] = $sub;
			}
			ksort($merged);
			return $merged;
		}

		// Mixed scalar/object items in the same array - report the distinct kinds seen
		// rather than pretending one wins.
		$labels = [];
		foreach ($shapes as $s)
		{
			$labels[] = is_string($s) ? $s : 'object';
		}
		$labels = array_values(array_unique($labels));
		sort($labels);
		return implode('|', $labels);
	}

	/**
	 * A stable, human-diffable JSON string for a shape - what gets written to a golden
	 * file and what a failure message shows either side of.
	 */
	public static function Encode($shape): string
	{
		return json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * Dotted paths present in $admin but not at the same position in $restricted -
	 * the raw-value counterpart to a shape diff, used to find exactly which keys a
	 * restricted identity's response is missing relative to an Admin one. A list
	 * position is written "[]" (e.g. "products[].price"), since a redacted field is
	 * removed the same way from every row and this harness never asserts row order or
	 * count against a restricted identity - only which columns survive.
	 *
	 * @return string[]
	 */
	public static function MissingKeys($admin, $restricted, string $prefix = ''): array
	{
		if (is_array($admin) && self::IsList($admin))
		{
			$restrictedList = is_array($restricted) && self::IsList($restricted) ? $restricted : [];
			$missing = [];
			$n = min(count($admin), count($restrictedList));
			for ($i = 0; $i < $n; $i++)
			{
				$missing = array_merge($missing, self::MissingKeys($admin[$i], $restrictedList[$i], $prefix . '[]'));
			}
			return array_values(array_unique($missing));
		}

		if (is_array($admin))
		{
			$restrictedAssoc = is_array($restricted) && !self::IsList($restricted) ? $restricted : [];
			$missing = [];
			foreach ($admin as $key => $value)
			{
				$path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
				if (!array_key_exists($key, $restrictedAssoc))
				{
					$missing[] = $path;
				}
				else
				{
					$missing = array_merge($missing, self::MissingKeys($value, $restrictedAssoc[$key], $path));
				}
			}
			return $missing;
		}

		return [];
	}

	/** The field name a dotted MissingKeys() path ends in - "products[].price" -> "price". */
	public static function Leaf(string $path): string
	{
		$parts = explode('.', $path);
		return end($parts);
	}
}
