<?php

namespace Victual\Helpers;

/**
 * A class that abstracts Grocycode.
 *
 * Grocycode is a simple, easily serializable format to reference
 * stuff within Grocy. It consists of n (n ≥ 3) double-colon seperated parts:
 *
 *  1. The magic `grcy`
 *  2. A type identifer, must match `[a-z]+` (i.e. only lowercase ascii, minimum length 1 character)
 *  3. An object id
 *  4. Any number of further data fields, double-colon seperated.
 *
 * Example: `grcy:p:13:60bf8b5244b04` references product 13, stock entry 60bf8b5244b04.
 * See docs/grocycode.md for the full format description.
 *
 * @author Katharina Bogad <katharina@hacked.xyz>
 */
class Grocycode
{
	/** Type identifier for products */
	public const PRODUCT = 'p';

	/** Type identifier for batteries */
	public const BATTERY = 'b';

	/** Type identifier for chores */
	public const CHORE = 'c';

	/** Type identifier for recipes */
	public const RECIPE = 'r';

	/** Magic first part of every grocycode */
	public const MAGIC = 'grcy';

	/**
	 * Overloaded constructor:
	 * - 1 argument: parses a serialized grocycode string (e.g. "grcy:p:13")
	 * - 2 or 3 arguments: builds a grocycode from a type identifier (one of self::$Items),
	 *   an object id and optionally an array of extra data fields
	 *
	 * @throws \Exception When the arguments match no overload or are invalid
	 */
	public function __construct(...$args)
	{
		$argc = count($args);
		if ($argc == 1)
		{
			$this->setFromCode($args[0]);
			return;
		}
		elseif ($argc == 2 || $argc == 3)
		{
			if ($argc == 2)
			{
				$args[] = [];
			}
			$this->setFromData($args[0], $args[1], $args[2]);
			return;
		}

		throw new \Exception('No suitable overload found.');
	}

	/** @var string[] All known type identifiers */
	public static $Items = [self::PRODUCT, self::BATTERY, self::CHORE, self::RECIPE];
	private $type;
	private $id;
	private $extra_data = [];

	/**
	 * Returns true when the given string is a parseable grocycode.
	 *
	 * @return bool
	 */
	public static function Validate(string $code)
	{
		try
		{
			$gc = new self($code);
			return true;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * Returns the referenced object id.
	 */
	public function GetId()
	{
		return $this->id;
	}

	/**
	 * Returns the extra data fields (e.g. the stock entry id for product grocycodes).
	 *
	 * @return array
	 */
	public function GetExtraData()
	{
		return $this->extra_data;
	}

	/**
	 * Returns the type identifier (one of the self::PRODUCT/BATTERY/CHORE/RECIPE constants).
	 */
	public function GetType()
	{
		return $this->type;
	}

	/**
	 * Serializes this grocycode to its string form, e.g. "grcy:p:13".
	 */
	public function __toString(): string
	{
		$arr = array_merge([self::MAGIC, $this->type, $this->id], $this->extra_data);

		return implode(':', $arr);
	}

	private function setFromCode($code)
	{
		$parts = array_reverse(explode(':', $code));
		if (array_pop($parts) != self::MAGIC)
		{
			throw new \Exception('Not a Grocycode');
		}

		if (!in_array($this->type = array_pop($parts), self::$Items))
		{
			throw new \Exception('Unknown Grocycode type');
		}

		$this->id = array_pop($parts);
		$this->extra_data = array_reverse($parts);
	}

	private function setFromData($type, $id, $extra_data = [])
	{
		if (!is_array($extra_data))
		{
			throw new \Exception('Extra data must be array of string');
		}
		if (!in_array($type, self::$Items))
		{
			throw new \Exception('Unknown Grocycode type');
		}

		$this->type = $type;
		$this->id = $id;
		$this->extra_data = $extra_data;
	}
}
