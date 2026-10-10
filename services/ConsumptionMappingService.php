<?php

namespace Victual\Services;

use Victual\Controllers\Users\User;

/**
 * What a person has approved for one (source_system, medication_ref): the consumption mapping of
 * ADR-0041 rule 4. A mapping is owned by one user and never read by another; every method takes
 * the user it acts for, and an absent mapping and another user's mapping answer alike (404).
 *
 * A mapping is created or replaced only by an explicit request. The service checks everything it
 * can without inferring anything: a recipe target needs the `consume` right (a recipe the user
 * cannot consume answers as one that does not exist, ADR-0040 rule 7), a product target needs an
 * active product and, when a unit is named, an entered conversion to the product's stock unit.
 *
 * Deleting a mapping also deletes the user's `voided` and `dismissed` events for its medication
 * (ADR-0041 open question 2); events that booked stock keep their rows.
 */
class ConsumptionMappingService extends BaseService
{
	private const SOURCE_SYSTEM_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/';
	private const MEDICATION_REF_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';
	private const EFFECTIVE_FROM_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?(Z|[+-](\d{2}):(\d{2}))$/';
	private const LOCATION_MODES = ['fixed', 'single', 'explicit'];
	private const MAX_LABELS = 50;
	private const MAX_LABEL_LENGTH = 64;

	/** A TIMESTAMPTZ column as ADR-0027 puts it on the wire: RFC 3339 in UTC with six fractional digits. */
	private static function Wire(string $column, string $alias): string
	{
		return "to_char($column AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS $alias";
	}

	private function Db(): \PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	private static function Invalid(string $message): ConsumptionException
	{
		return new ConsumptionException(400, 'invalid_request', $message);
	}

	private static function InvalidMapping(string $message): ConsumptionException
	{
		return new ConsumptionException(422, 'invalid_mapping', $message);
	}

	private function RequireGlobal(int $userId, string ...$permissions): void
	{
		$statement = $this->Db()->prepare('SELECT 1 FROM user_permissions_resolved WHERE user_id = ? AND permission_name = ?');

		foreach ($permissions as $permission)
		{
			$statement->execute([$userId, $permission]);
			if ($statement->fetchColumn() === false)
			{
				throw new ConsumptionException(403, 'permission_missing', 'Permission missing: ' . $permission);
			}
		}
	}

	// --- Public API --------------------------------------------------------------------------

	/**
	 * Creates or replaces the mapping for a key. A replacement overwrites every field, unit
	 * labels included, keeps the id and sets updated_at.
	 *
	 * @param array $input ConsumptionMappingInput
	 * @return array{mapping: array, created: bool}
	 */
	public function Put(int $userId, string $sourceSystem, string $medicationRef, array $input): array
	{
		return DatabaseService::GetInstance()->InTransaction(function () use ($userId, $sourceSystem, $medicationRef, $input)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_CONSUME);
			$this->ValidateIdentity($sourceSystem, $medicationRef);
			$clean = $this->ValidatedInput($input);

			if ($clean['recipe_id'] !== null && !ConsumptionRecipeService::GetInstance()->MayConsume($clean['recipe_id'], $userId))
			{
				throw new ConsumptionException(404, 'not_found', 'Consumption recipe does not exist');
			}

			if ($clean['product_id'] !== null)
			{
				$product = $this->ActiveProduct($clean['product_id']);
				if ($product === null)
				{
					throw self::InvalidMapping('The product does not exist or is inactive');
				}

				if ($clean['qu_id'] !== null)
				{
					$this->RequireConversion($clean['product_id'], $clean['qu_id'], (int)$product['qu_id_stock']);
				}
			}

			if ($clean['location_mode'] === 'fixed')
			{
				$statement = $this->Db()->prepare('SELECT 1 FROM locations WHERE id = ?');
				$statement->execute([$clean['location_id']]);
				if ($statement->fetchColumn() === false)
				{
					throw self::InvalidMapping('The location does not exist');
				}
			}

			$statement = $this->Db()->prepare('INSERT INTO consumption_mappings
					(user_id, source_system, medication_ref, target_type, recipe_id, product_id, qu_id, quantity_factor, unit_labels, default_quantity, location_mode, location_id, effective_from)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?, ?)
				ON CONFLICT (user_id, source_system, medication_ref) DO UPDATE SET
					target_type = EXCLUDED.target_type, recipe_id = EXCLUDED.recipe_id, product_id = EXCLUDED.product_id, qu_id = EXCLUDED.qu_id,
					quantity_factor = EXCLUDED.quantity_factor, unit_labels = EXCLUDED.unit_labels, default_quantity = EXCLUDED.default_quantity,
					location_mode = EXCLUDED.location_mode, location_id = EXCLUDED.location_id, effective_from = EXCLUDED.effective_from, updated_at = now()
				RETURNING id, (xmax = 0) AS inserted');
			$statement->execute([$userId, $sourceSystem, $medicationRef, $clean['recipe_id'] !== null ? 'recipe' : 'product', $clean['recipe_id'], $clean['product_id'],
				$clean['qu_id'], $clean['quantity_factor'], self::FormatTextArray($clean['unit_labels']), $clean['default_quantity'], $clean['location_mode'],
				$clean['location_id'], $clean['effective_from']]);
			$written = $statement->fetch(\PDO::FETCH_ASSOC);

			$row = $this->FindForUser($userId, $sourceSystem, $medicationRef);

			return ['mapping' => self::ToWire($row), 'created' => (bool)$written['inserted']];
		});
	}

	public function Get(int $userId, string $sourceSystem, string $medicationRef): array
	{
		$row = $this->FindForUser($userId, $sourceSystem, $medicationRef);
		if ($row === null)
		{
			throw new ConsumptionException(404, 'not_found', 'Consumption mapping does not exist');
		}

		return self::ToWire($row);
	}

	/** Every mapping of the user, by source system and medication reference. */
	public function ListMappings(int $userId): array
	{
		$statement = $this->Db()->prepare('SELECT *, ' . self::Wire('effective_from', 'effective_from_wire') . ' FROM consumption_mappings WHERE user_id = ? ORDER BY source_system, medication_ref');
		$statement->execute([$userId]);

		return array_map(fn(array $row) => self::ToWire($this->Decoded($row)), $statement->fetchAll(\PDO::FETCH_ASSOC));
	}

	/**
	 * Deletes the mapping and the user's `voided` and `dismissed` events for its medication, in one
	 * transaction. Events that booked stock keep their rows; the foreign key clears their mapping_id.
	 */
	public function Delete(int $userId, string $sourceSystem, string $medicationRef): void
	{
		DatabaseService::GetInstance()->InTransaction(function () use ($userId, $sourceSystem, $medicationRef)
		{
			$statement = $this->Db()->prepare('SELECT id FROM consumption_mappings WHERE user_id = ? AND source_system = ? AND medication_ref = ? FOR UPDATE');
			$statement->execute([$userId, $sourceSystem, $medicationRef]);
			$mappingId = $statement->fetchColumn();

			if ($mappingId === false)
			{
				throw new ConsumptionException(404, 'not_found', 'Consumption mapping does not exist');
			}

			$this->Db()->prepare("DELETE FROM consumption_events WHERE user_id = ? AND source_system = ? AND medication_ref = ? AND state IN ('voided', 'dismissed')")
				->execute([$userId, $sourceSystem, $medicationRef]);
			$this->Db()->prepare('DELETE FROM consumption_mappings WHERE id = ?')->execute([$mappingId]);
		});
	}

	/** The raw row (unit_labels decoded to a list, effective_from_wire added), or null. A plain read, no lock. */
	public function FindForUser(int $userId, string $sourceSystem, string $medicationRef): ?array
	{
		$statement = $this->Db()->prepare('SELECT *, ' . self::Wire('effective_from', 'effective_from_wire') . ' FROM consumption_mappings WHERE user_id = ? AND source_system = ? AND medication_ref = ?');
		$statement->execute([$userId, $sourceSystem, $medicationRef]);
		$row = $statement->fetch(\PDO::FETCH_ASSOC);

		return $row === false ? null : $this->Decoded($row);
	}

	/** Appends a label the person confirmed, unless it is already there. One statement, so concurrent calls cannot duplicate it. */
	public function AddUnitLabel(int $mappingId, string $label): void
	{
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH)
		{
			throw self::Invalid('A unit label is 1 to ' . self::MAX_LABEL_LENGTH . ' characters');
		}

		$statement = $this->Db()->prepare('UPDATE consumption_mappings SET unit_labels = array_append(unit_labels, ?::text), updated_at = now()
			WHERE id = ? AND NOT (?::text = ANY(unit_labels)) AND cardinality(unit_labels) < ' . self::MAX_LABELS);
		$statement->execute([$label, $mappingId, $label]);

		if ($statement->rowCount() === 0)
		{
			$check = $this->Db()->prepare('SELECT ?::text = ANY(unit_labels) AS present FROM consumption_mappings WHERE id = ?');
			$check->execute([$label, $mappingId]);
			$found = $check->fetch(\PDO::FETCH_ASSOC);

			if ($found === false)
			{
				throw new ConsumptionException(404, 'not_found', 'Consumption mapping does not exist');
			}

			if (!$found['present'])
			{
				throw self::InvalidMapping('A mapping holds at most ' . self::MAX_LABELS . ' unit labels');
			}
		}
	}

	/**
	 * The amount in the product's stock unit for an event quantity: quantity times the factor times
	 * the entered conversion from the mapping's unit, when it names one.
	 *
	 * @throws ConsumptionException 422 invalid_mapping when the target is not a product, the product is
	 *                              missing or inactive, the conversion is missing, or the result is not finite and positive
	 */
	public function StockAmountFor(array $mappingRow, float $quantity): float
	{
		if ($mappingRow['product_id'] === null)
		{
			throw self::InvalidMapping('The mapping does not target a product');
		}

		$product = $this->ActiveProduct((int)$mappingRow['product_id']);
		if ($product === null)
		{
			throw self::InvalidMapping('The product does not exist or is inactive');
		}

		$conversion = 1.0;
		if ($mappingRow['qu_id'] !== null && (int)$mappingRow['qu_id'] !== (int)$product['qu_id_stock'])
		{
			$conversion = $this->RequireConversion((int)$mappingRow['product_id'], (int)$mappingRow['qu_id'], (int)$product['qu_id_stock']);
		}

		$amount = $quantity * (float)$mappingRow['quantity_factor'] * $conversion;
		if (!is_finite($amount) || $amount <= 0)
		{
			throw self::InvalidMapping('The quantity does not convert to a positive stock amount');
		}

		return $amount;
	}

	// --- Validation --------------------------------------------------------------------------

	private function ValidateIdentity(string $sourceSystem, string $medicationRef): void
	{
		if (preg_match(self::SOURCE_SYSTEM_PATTERN, $sourceSystem) !== 1)
		{
			throw self::Invalid('source_system is 1 to 32 characters of lowercase letters, digits and . _ -, starting with a letter or digit');
		}

		if ($sourceSystem === ConsumptionRecipeService::SOURCE_MANUAL)
		{
			throw self::Invalid('source_system "manual" is reserved');
		}

		if (preg_match(self::MEDICATION_REF_PATTERN, $medicationRef) !== 1)
		{
			throw self::Invalid('medication_ref is 1 to 128 characters of letters, digits and . _ : -');
		}
	}

	/** @return array{recipe_id: ?int, product_id: ?int, qu_id: ?int, quantity_factor: float, unit_labels: array, default_quantity: ?float, location_mode: string, location_id: ?int, effective_from: string} */
	private function ValidatedInput(array $input): array
	{
		$recipeId = $this->OptionalId($input, 'recipe_id');
		$productId = $this->OptionalId($input, 'product_id');
		$quId = $this->OptionalId($input, 'qu_id');

		if (($recipeId === null) === ($productId === null))
		{
			throw self::Invalid('Exactly one of recipe_id and product_id is required');
		}

		if ($recipeId !== null && $quId !== null)
		{
			throw self::Invalid('qu_id applies to a product target only');
		}

		$factor = 1.0;
		if (isset($input['quantity_factor']))
		{
			$factor = $this->PositiveNumber($input['quantity_factor'], 'quantity_factor');
		}

		$default = isset($input['default_quantity']) ? $this->PositiveNumber($input['default_quantity'], 'default_quantity') : null;

		$labels = $input['unit_labels'] ?? [];
		if (!is_array($labels) || !array_is_list($labels) || count($labels) > self::MAX_LABELS)
		{
			throw self::Invalid('unit_labels is a list of at most ' . self::MAX_LABELS . ' strings');
		}

		foreach ($labels as $label)
		{
			if (!is_string($label) || $label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH)
			{
				throw self::Invalid('A unit label is a string of 1 to ' . self::MAX_LABEL_LENGTH . ' characters');
			}
		}

		if (count(array_unique($labels)) !== count($labels))
		{
			throw self::Invalid('unit_labels must be unique');
		}

		$location = $input['location'] ?? null;
		if (!is_array($location) || !isset($location['mode']) || !is_string($location['mode']))
		{
			throw self::Invalid('location with a mode is required');
		}

		if (!in_array($location['mode'], self::LOCATION_MODES, true))
		{
			throw self::Invalid('location.mode is one of ' . implode(', ', self::LOCATION_MODES));
		}

		$locationId = $this->OptionalId($location, 'location_id');
		if ($location['mode'] === 'fixed' && $locationId === null)
		{
			throw self::InvalidMapping('A fixed location names a location_id');
		}

		if ($location['mode'] !== 'fixed' && $locationId !== null)
		{
			throw self::InvalidMapping('location_id is accepted only with mode fixed');
		}

		return ['recipe_id' => $recipeId, 'product_id' => $productId, 'qu_id' => $quId, 'quantity_factor' => $factor, 'unit_labels' => $labels,
			'default_quantity' => $default, 'location_mode' => $location['mode'], 'location_id' => $locationId,
			'effective_from' => $this->ValidatedEffectiveFrom($input['effective_from'] ?? null)];
	}

	private function OptionalId(array $source, string $key): ?int
	{
		$value = $source[$key] ?? null;
		if ($value === null)
		{
			return null;
		}

		if (!is_int($value) || $value <= 0)
		{
			throw self::Invalid($key . ' must be a positive integer');
		}

		return $value;
	}

	private function PositiveNumber($value, string $key): float
	{
		if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || (float)$value <= 0)
		{
			throw self::Invalid($key . ' must be a finite number greater than zero');
		}

		return (float)$value;
	}

	private function ValidatedEffectiveFrom($value): string
	{
		if (!is_string($value))
		{
			throw self::Invalid('effective_from is required');
		}

		if (preg_match(self::EFFECTIVE_FROM_PATTERN, $value, $m) !== 1
			|| !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59
			|| (isset($m[9]) && $m[9] !== '' && ((int)$m[9] > 23 || (int)$m[10] > 59)))
		{
			throw self::InvalidMapping('effective_from must be an RFC 3339 time with an offset');
		}

		return $value;
	}

	// --- Products and units ------------------------------------------------------------------

	private function ActiveProduct(int $productId): ?array
	{
		$statement = $this->Db()->prepare('SELECT qu_id_stock, active FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$product = $statement->fetch(\PDO::FETCH_ASSOC);

		return $product === false || (int)$product['active'] !== 1 ? null : $product;
	}

	/** The entered conversion factor from a unit to the stock unit (1 for the stock unit itself). */
	private function RequireConversion(int $productId, int $quId, int $stockQuId): float
	{
		$statement = $this->Db()->prepare('SELECT 1 FROM quantity_units WHERE id = ?');
		$statement->execute([$quId]);
		if ($statement->fetchColumn() === false)
		{
			throw self::InvalidMapping('The quantity unit does not exist');
		}

		if ($quId === $stockQuId)
		{
			return 1.0;
		}

		$statement = $this->Db()->prepare('SELECT factor FROM cache__quantity_unit_conversions_resolved WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ? LIMIT 1');
		$statement->execute([$productId, $quId, $stockQuId]);
		$factor = $statement->fetchColumn();

		if ($factor === false || !is_numeric($factor) || !is_finite((float)$factor) || (float)$factor <= 0)
		{
			throw self::InvalidMapping('The unit has no entered conversion to the stock unit of the product');
		}

		return (float)$factor;
	}

	// --- Rows and the wire -------------------------------------------------------------------

	private function Decoded(array $row): array
	{
		$row['unit_labels'] = self::ParseTextArray((string)$row['unit_labels']);

		return $row;
	}

	private static function ToWire(array $row): array
	{
		return [
			'source_system' => $row['source_system'],
			'medication_ref' => $row['medication_ref'],
			'recipe_id' => $row['recipe_id'] === null ? null : (int)$row['recipe_id'],
			'product_id' => $row['product_id'] === null ? null : (int)$row['product_id'],
			'qu_id' => $row['qu_id'] === null ? null : (int)$row['qu_id'],
			'quantity_factor' => (float)$row['quantity_factor'],
			'unit_labels' => $row['unit_labels'],
			'default_quantity' => $row['default_quantity'] === null ? null : (float)$row['default_quantity'],
			'location' => ['mode' => $row['location_mode'], 'location_id' => $row['location_id'] === null ? null : (int)$row['location_id']],
			'effective_from' => $row['effective_from_wire'],
		];
	}

	/** A PHP list of strings as a PostgreSQL text[] literal; every element is quoted. */
	public static function FormatTextArray(array $values): string
	{
		return '{' . implode(',', array_map(fn($value) => '"' . strtr((string)$value, ['\\' => '\\\\', '"' => '\\"']) . '"', $values)) . '}';
	}

	/**
	 * A one-dimensional PostgreSQL text[] literal ('{a,"b, c","d\"e"}') as a PHP list of strings.
	 * Unit labels are never NULL, so an unquoted NULL is not special-cased.
	 *
	 * @return string[]
	 */
	public static function ParseTextArray(string $literal): array
	{
		if ($literal === '' || $literal[0] !== '{' || substr($literal, -1) !== '}')
		{
			throw new \UnexpectedValueException('Not a PostgreSQL array literal');
		}

		$body = substr($literal, 1, -1);
		$items = [];
		$length = strlen($body);
		$i = 0;

		while ($i < $length)
		{
			$item = '';
			if ($body[$i] === '"')
			{
				$i++;
				while ($i < $length && $body[$i] !== '"')
				{
					if ($body[$i] === '\\')
					{
						$i++;
					}
					$item .= $body[$i] ?? '';
					$i++;
				}
				$i++;
			}
			else
			{
				while ($i < $length && $body[$i] !== ',')
				{
					$item .= $body[$i];
					$i++;
				}
			}

			$items[] = $item;
			if ($i < $length && $body[$i] === ',')
			{
				$i++;
			}
		}

		return $items;
	}
}
