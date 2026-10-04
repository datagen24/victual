<?php

namespace Victual\Services\Database;

use Victual\Services\Time\Instant;

/**
 * The statement class of every application PostgreSQL connection: a TIMESTAMPTZ value
 * leaves the database layer in the wire rendering, `2026-10-04T18:30:00.000000Z`
 * (ADR-0027 decision 2, issue #650).
 *
 * PostgreSQL's own text output for TIMESTAMPTZ is not RFC 3339 - `2026-10-04 14:30:00-04`,
 * in the session zone, with a variable-length fraction - so every value has to be
 * rewritten somewhere. Here is the one place every reader shares: the API's generic entity
 * reads (LessQL), the hand-built service responses, the Blade pages, the MQTT and InfluxDB
 * publishers and the iCal feed all receive the same rendering, so none of them can render
 * a timestamp differently from the others.
 *
 * **What decides that a value is an instant is the column's type, never its name or its
 * look.** pdo_pgsql reports a result column's type through getColumnMeta() - for table
 * columns, view columns, aliases, expressions, joins and unions alike, and for a NULL as
 * well - and a cast to text or JSON reports text or JSON, so a value deliberately sent as
 * text is left alone. A text column whose content happens to look like a timestamp is
 * therefore never rewritten.
 *
 * getColumnMeta() is not free: pdo_pgsql answers it with two catalogue queries per column
 * (the relation name and the type name), measured on PHP 8.4 against PostgreSQL 16. So it
 * is only asked about a column that holds a value in PostgreSQL's TIMESTAMPTZ shape in the
 * rows actually fetched, the answer is kept for the statement's lifetime and, keyed by the
 * SQL text, for the process; a statement with no such value costs nothing.
 *
 * Columns are located by position. Every fetch mode the application uses keeps a row's
 * values in column order - FETCH_ASSOC, FETCH_NUM, FETCH_BOTH (numeric keys are the
 * positions), FETCH_OBJ, FETCH_COLUMN, FETCH_KEY_PAIR, and FETCH_GROUP, which moves the
 * first column into the group key - except when two result columns share a name and
 * FETCH_ASSOC keeps only the last value, which is detected and handled by asking about
 * every column. FETCH_BOUND writes into bound variables this class cannot see and is
 * passed through unconverted; its one caller reads a file's bytes, not a timestamp.
 */
class InstantStatement extends \PDOStatement
{
	/** Per process: SQL text => [column position => bool is TIMESTAMPTZ]. A pure cache. */
	private static array $KnownColumnTypes = [];

	/** Column position => is TIMESTAMPTZ, for this statement. */
	private array $columnIsInstant = [];

	private int $defaultFetchMode = \PDO::FETCH_BOTH;

	protected function __construct()
	{
	}

	public function setFetchMode(int $mode, mixed ...$args): true
	{
		$this->defaultFetchMode = $mode;
		return parent::setFetchMode($mode, ...$args);
	}

	public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
	{
		$row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
		if ($row === false)
		{
			return $row;
		}

		$mode = $this->EffectiveMode($mode);
		if (($mode & 0xFFFF) === \PDO::FETCH_COLUMN)
		{
			return $this->ConvertValue($row, 0);
		}

		return $this->ConvertRow($row, $mode, 0);
	}

	public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
	{
		$rows = parent::fetchAll($mode, ...$args);
		$mode = $this->EffectiveMode($mode);

		if (($mode & 0xFFFF) === \PDO::FETCH_COLUMN && ($mode & \PDO::FETCH_GROUP) === 0)
		{
			$position = (int)($args[0] ?? 0);
			foreach ($rows as $i => $value)
			{
				$rows[$i] = $this->ConvertValue($value, $position);
			}

			return $rows;
		}

		if (($mode & 0xFFFF) === \PDO::FETCH_KEY_PAIR)
		{
			$converted = [];
			foreach ($rows as $key => $value)
			{
				$converted[$this->ConvertValue($key, 0)] = $this->ConvertValue($value, 1);
			}

			return $converted;
		}

		if (($mode & \PDO::FETCH_GROUP) === \PDO::FETCH_GROUP)
		{
			$converted = [];
			foreach ($rows as $key => $group)
			{
				$converted[$this->ConvertValue($key, 0)] = array_map(fn($row) => $this->ConvertRow($row, $mode & ~\PDO::FETCH_GROUP, 1), $group);
			}

			return $converted;
		}

		foreach ($rows as $i => $row)
		{
			$rows[$i] = $this->ConvertRow($row, $mode, 0);
		}

		return $rows;
	}

	public function fetchColumn(int $column = 0): mixed
	{
		return $this->ConvertValue(parent::fetchColumn($column), $column);
	}

	private function EffectiveMode(int $mode): int
	{
		return $mode === \PDO::FETCH_DEFAULT ? $this->defaultFetchMode : $mode;
	}

	/**
	 * @param int $firstPosition The column position of the row's first value - 1 under
	 *                           FETCH_GROUP, which took column 0 for the key
	 */
	private function ConvertRow($row, int $mode, int $firstPosition)
	{
		if (($mode & 0xFFFF) === \PDO::FETCH_BOUND)
		{
			return $row;
		}

		if (is_object($row))
		{
			$values = get_object_vars($row);
			$converted = $this->ConvertNamed($values, $firstPosition);
			foreach ($converted as $name => $value)
			{
				$row->$name = $value;
			}

			return $row;
		}

		if (!is_array($row))
		{
			return $row;
		}

		if (($mode & 0xFFFF) === \PDO::FETCH_NUM)
		{
			foreach ($row as $position => $value)
			{
				$row[$position] = $this->ConvertValue($value, $position + $firstPosition);
			}

			return $row;
		}

		if (($mode & 0xFFFF) === \PDO::FETCH_BOTH)
		{
			$names = [];
			foreach ($row as $key => $value)
			{
				if (is_int($key))
				{
					$row[$key] = $this->ConvertValue($value, $key + $firstPosition);
				}
				else
				{
					$names[$key] = $value;
				}
			}

			return array_replace($row, $this->ConvertNamed($names, $firstPosition));
		}

		return $this->ConvertNamed($row, $firstPosition);
	}

	/**
	 * A name-keyed row. Its keys are in column order unless two columns shared a name, in
	 * which case there are fewer keys than columns and every column's name is looked up.
	 */
	private function ConvertNamed(array $row, int $firstPosition): array
	{
		$expected = $this->columnCount() - $firstPosition;
		if (count($row) === $expected)
		{
			$position = $firstPosition;
			foreach ($row as $name => $value)
			{
				$row[$name] = $this->ConvertValue($value, $position);
				$position++;
			}

			return $row;
		}

		$positionOf = [];
		for ($i = $firstPosition; $i < $this->columnCount(); $i++)
		{
			// The last column with a name is the one whose value FETCH_ASSOC kept.
			$positionOf[$this->getColumnMeta($i)['name']] = $i;
		}

		foreach ($row as $name => $value)
		{
			if (isset($positionOf[$name]))
			{
				$row[$name] = $this->ConvertValue($value, $positionOf[$name]);
			}
		}

		return $row;
	}

	private function ConvertValue($value, int $position)
	{
		if (!is_string($value) || preg_match(Instant::DATABASE_PATTERN, $value) !== 1)
		{
			return $value;
		}

		if (!$this->IsInstantColumn($position))
		{
			return $value;
		}

		return Instant::FromDatabase($value) ?? $value;
	}

	private function IsInstantColumn(int $position): bool
	{
		if (isset($this->columnIsInstant[$position]))
		{
			return $this->columnIsInstant[$position];
		}

		$key = $this->queryString;
		if (isset(self::$KnownColumnTypes[$key][$position]))
		{
			return $this->columnIsInstant[$position] = self::$KnownColumnTypes[$key][$position];
		}

		$meta = $position < $this->columnCount() ? $this->getColumnMeta($position) : false;
		$isInstant = is_array($meta) && (($meta['pgsql:oid'] ?? null) === 1184 || ($meta['native_type'] ?? null) === 'timestamptz');

		if (count(self::$KnownColumnTypes) > 2048)
		{
			self::$KnownColumnTypes = [];
		}
		self::$KnownColumnTypes[$key][$position] = $isInstant;

		return $this->columnIsInstant[$position] = $isInstant;
	}
}
