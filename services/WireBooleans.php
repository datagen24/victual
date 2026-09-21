<?php

namespace Victual\Services;

/**
 * Turns the 0/1 flags that reach a response from an integer column into the `true`/`false`
 * the OpenAPI document says they are. Issue #230.
 *
 * Eleven properties are documented `type: boolean` and were shipping as `0`/`1`, because
 * `db/pgsql/baseline/01_tables.sql` stores every flag as `SMALLINT` - deliberately, so that
 * `json_encode` renders the integer the rest of the API's flags document - and nothing
 * converted these eleven on the way out. The one property in the same family that was
 * converted, `ProductDetailsResponse.has_childs`, is a bare `boolval()` one line away from
 * `is_aggregated_amount` in `StockService::GetProductDetails()`, which is what makes this an
 * omission rather than a convention.
 *
 * [ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md) decides which side moves:
 * "where the two engines disagree, the conforming answer is the one the OpenAPI spec
 * documents". The spec says boolean, so the wire moves and the document stays. A client that
 * was tolerating the integer sees a changed value; per
 * [ADR-0024](../docs/adr/0024-the-fork-writes-its-own-clients.md) decision 1 no external
 * client has a compatibility commitment, and the fork's own consumers are listed in this
 * class's commit.
 *
 * **Why not fix it in SQL.** `CAST(x AS BOOLEAN)` in a view would produce a real PHP `bool`
 * through pdo_pgsql - that is exactly the hazard the baseline's own reviewer notes describe
 * (`db/pgsql/baseline/05_views_l2.sql:346`, `05_views_l3.sql:40`, both wrapping a genuine
 * PostgreSQL boolean back into `CASE ... THEN 1 ELSE 0 END`). It is rejected anyway: the
 * differential suite's `views` phase compares this engine's output against the frozen SQLite
 * line, SQLite has no boolean type, and six of the eleven come from views that phase reads.
 * Changing the base columns to `BOOLEAN` is worse still - it is a write-path change, and the
 * API accepts `0`/`1` on input.
 *
 * **Why the map is keyed by entity rather than by column name alone.** The eleven names
 * happen to be globally unambiguous today, so a blanket walk over every response would work
 * and could not miss a site. It would also convert a *userfield value*: userfields are
 * household-defined key/value pairs attached under a `userfields` key by
 * `GenericEntityApiController`, so a household with a userfield named `spoiled` holding
 * `"3"` would have it answered as `true`. Keyed by entity and applied only to a row's own
 * top-level keys, that cannot happen.
 *
 * Applied at the same boundary `FieldPolicy` is applied at, and for the same reason it gives:
 * the services that build responses are not the place, because the same service feeds Blade
 * views that are not the wire. `ContractTest`'s snapshot records the scalar type of every key
 * of 159 routes, so a site missed here shows up as a remaining `"integer"` in
 * `tests/Pgsql/snapshots/contract-admin.json` rather than as a client bug months later.
 */
final class WireBooleans
{
	/**
	 * The properties documented `type: boolean`, per response shape.
	 *
	 * A key is the name the boundary already calls the shape by - an entity name for the
	 * generic reads and for a `LessQL\Result`'s own table, and for the hand-built responses
	 * the same string `FieldPolicy` is called with at that site, so that the two policies
	 * never disagree about what a row is.
	 *
	 * `undone` is deliberately absent although it sits beside `spoiled` in every stock_log
	 * row and ships on the booking responses: no schema types it `boolean`, so nothing here
	 * has a promise to keep for it and it stays the integer the rest of the API's flags are.
	 * It is in fact documented by no schema at all - `StockLogEntry` omits it - which is a
	 * gap in the document rather than in this map, and a separate question from making the
	 * server keep the promises it has already made.
	 *
	 * `uihelper_stock_journal` was listed here until the `StockJournal` and
	 * `StockJournalSummary` schemas were removed as dead declarations. The view is read only
	 * by the Blade stock-journal pages (`StockController::Journal()`), is not an
	 * `ExposedEntity`, and no schema documents its `spoiled` any more, so there is no
	 * documented boolean left for a conversion to serve. See ADR-0027's `wirecontract`
	 * consequence bullet.
	 */
	const COLUMNS = [
		// StockLogEntry.spoiled
		'stock_log' => ['spoiled'],
		// CurrentStockResponse.is_aggregated_amount
		'stock_current' => ['is_aggregated_amount'],
		// ProductDetailsResponse - see StockService::GetProductDetails(), whose has_childs
		// is already boolval()ed one line above is_aggregated_amount
		'product_details' => ['is_aggregated_amount'],
		// Chore.track_date_only / Chore.rollover
		'chores' => ['track_date_only', 'rollover'],
		// CurrentChoreResponse.track_date_only / is_rescheduled / is_reassigned.
		// `rollover` is deliberately absent although `chores` above lists it: the view reads
		// chores.rollover to compute next_estimated_execution_time and does not project it
		// (db/pgsql/baseline/05_views_l2.sql:25), so no chores_current row carries the key and
		// CurrentChoreResponse does not document it. Naming it here converted nothing and made
		// WireContractTest's coverage check unprovable, since no response can show it converted.
		'chores_current' => ['track_date_only', 'is_rescheduled', 'is_reassigned'],
		// RecipeFulfillmentResponse.need_fulfilled / need_fulfilled_with_shopping_list /
		// prices_incomplete
		'recipes_resolved' => ['need_fulfilled', 'need_fulfilled_with_shopping_list', 'prices_incomplete'],
		'recipes_pos_resolved' => ['need_fulfilled', 'need_fulfilled_with_shopping_list'],
		// Userfield.show_as_column_in_tables / Userfield.input_required
		'userfields' => ['show_as_column_in_tables', 'input_required'],
		'userfield_values_resolved' => ['show_as_column_in_tables']
	];

	/**
	 * The documented-boolean properties of $shape, or an empty array when the shape has
	 * none.
	 *
	 * @return string[]
	 */
	public static function ColumnsOf(string $shape): array
	{
		return self::COLUMNS[$shape] ?? [];
	}

	/**
	 * Converts every documented-boolean property of $shape present on $row to a PHP bool,
	 * and returns the row - an array, a stdClass (raw PDO fetch) or anything implementing
	 * ArrayAccess (a LessQL Row). Objects are mutated in place; the return value only
	 * matters for arrays, which PHP passes by value. The same three-way shape handling
	 * FieldPolicy::RedactRow() does, for the same reason: these rows arrive as all three.
	 *
	 * Only the row's own top-level keys are touched, never a nested object - see the class
	 * docblock on userfields. Where a response nests a row of another shape (the `chore`
	 * inside a ChoreDetailsResponse, say), the caller coerces that row by its own name.
	 *
	 * **A null stays null.** These columns are `NOT NULL` where they are base columns, but
	 * a view's projection of one can be null for a row it did not join, and `boolval(null)`
	 * is `false` - which would answer "no" to a question nobody answered. `JsonShape` keeps
	 * `integer|null` distinct for exactly this reason, and a client written against a
	 * silently-nullable column is what that rule exists to protect.
	 *
	 * @param array|object|null $row
	 * @return array|object|null
	 */
	public static function Coerce(string $shape, $row)
	{
		if ($row === null)
		{
			return $row;
		}

		foreach (self::ColumnsOf($shape) as $column)
		{
			if (is_array($row))
			{
				if (array_key_exists($column, $row) && $row[$column] !== null)
				{
					$row[$column] = boolval($row[$column]);
				}
			}
			elseif ($row instanceof \ArrayAccess)
			{
				if (isset($row[$column]))
				{
					$row[$column] = boolval($row[$column]);
				}
			}
			elseif (is_object($row) && isset($row->$column))
			{
				$row->$column = boolval($row->$column);
			}
		}

		return $row;
	}

	/**
	 * Coerce(), applied to every element of a list of rows.
	 *
	 * @param array $rows
	 * @return array
	 */
	public static function CoerceRows(string $shape, array $rows): array
	{
		if (count(self::ColumnsOf($shape)) === 0)
		{
			return $rows;
		}

		foreach ($rows as $index => $row)
		{
			$rows[$index] = self::Coerce($shape, $row);
		}

		return $rows;
	}
}
