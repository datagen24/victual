<?php

namespace Victual\Services;

use Victual\Controllers\Users\User;

/**
 * Answers "which fields of this entity must the current user not see", from the
 * permission_fields table (docs/plans/19-rbac.md piece 2, Q2's response: a table rather
 * than a PHP constant, so a household can widen the policy without a release; the response
 * contract snapshot [14](docs/plans/landed/14-contract-and-regression-scaffolding.md) piece 2 will
 * generate from this migration's seeded rows, never a live database).
 *
 * Enforcement happens at the boundary, not here and not in the services that build
 * responses: BaseApiController::FilteredApiResponse, GenericEntityApiController's two
 * generic reads, and the hand-built responses that already know their own entity name call
 * into this class right before the response is written. A redacted field is removed from
 * the object entirely, never nulled - stock_log.price is legitimately null for a
 * consumption, and that has to stay distinguishable from "you may not see this" (the
 * plan's own rule).
 */
class FieldPolicy extends BaseService
{
	/** permission_fields.field value marking a whole-object gate rather than one field to strip. */
	const WHOLE_OBJECT = '*';

	/** @var array<string, object[]>|null permission_fields rows grouped by entity, cached for this request */
	private static $RowsByEntity = null;

	/**
	 * @return array<string, object[]>
	 */
	private function RowsByEntity(): array
	{
		if (self::$RowsByEntity === null)
		{
			self::$RowsByEntity = [];
			foreach ($this->DB->permission_fields() as $row)
			{
				self::$RowsByEntity[$row->entity][] = $row;
			}
		}

		return self::$RowsByEntity;
	}

	/**
	 * The field names of $entity the current user must not see: every permission_fields
	 * row for the entity whose permission the current user does not (resolved) hold.
	 * Empty when the entity has no policy rows, or the user holds everything they list.
	 * Never includes a WHOLE_OBJECT ('*') marker row - see WholeObjectPermission().
	 *
	 * @return string[]
	 */
	public function RedactedFieldsFor(string $entity): array
	{
		$fields = [];

		foreach ($this->RowsByEntity()[$entity] ?? [] as $row)
		{
			if ($row->field === self::WHOLE_OBJECT)
			{
				continue;
			}

			if (!User::HasPermissions($row->permission_name))
			{
				$fields[] = $row->field;
			}
		}

		return array_values(array_unique($fields));
	}

	/**
	 * The permission the current user is missing to read $entity at all, or null when the
	 * entity carries no whole-object gate (a permission_fields row with field = '*') or the
	 * user already holds the one it names.
	 *
	 * A whole-object gate is meant to be enforced by refusing the call outright (403, via
	 * User::CheckPermission()) rather than by filtering a single-purpose response down to
	 * an empty body - see StockApiController::ProductPriceHistory, which is the one route
	 * this applies to today (products_price_history).
	 */
	public function WholeObjectPermission(string $entity): ?string
	{
		foreach ($this->RowsByEntity()[$entity] ?? [] as $row)
		{
			if ($row->field === self::WHOLE_OBJECT && !User::HasPermissions($row->permission_name))
			{
				return $row->permission_name;
			}
		}

		return null;
	}

	/**
	 * Removes every field of $entity the current user may not see from a single row -
	 * an array, a stdClass (raw PDO fetch), or anything implementing ArrayAccess (a LessQL
	 * Row) - and returns it. Objects are mutated in place; the return value only matters
	 * for arrays, which PHP passes by value.
	 *
	 * @param array|object|null $row
	 * @return array|object|null
	 */
	public function RedactRow(string $entity, $row)
	{
		if ($row === null)
		{
			return $row;
		}

		foreach ($this->RedactedFieldsFor($entity) as $field)
		{
			if (is_array($row))
			{
				unset($row[$field]);
			}
			elseif ($row instanceof \ArrayAccess)
			{
				unset($row[$field]);
			}
			elseif (is_object($row))
			{
				unset($row->$field);
			}
		}

		return $row;
	}

	/**
	 * RedactRow(), applied to every element of a list of rows.
	 *
	 * @param array $rows
	 * @return array
	 */
	public function RedactRows(string $entity, array $rows): array
	{
		if (empty($this->RedactedFieldsFor($entity)))
		{
			return $rows;
		}

		foreach ($rows as $key => $row)
		{
			$rows[$key] = $this->RedactRow($entity, $row);
		}

		return $rows;
	}
}
