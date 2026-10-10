<?php

namespace Victual\Services;

/**
 * Refill history, reorder estimates, orders and notices for a private consumption recipe
 * (ADR-0042, issue 701).
 *
 * Access is the recipe's own (ADR-0040): the `read` right to look, the `edit` right to record or
 * change, and STOCK_VIEW for both (ADR-0042 section 1). Every method locks the recipe row through
 * ConsumptionRecipeService::AuthoriseRefill() inside its own transaction, `FOR UPDATE` for a write
 * and `FOR SHARE` for a read, so a revoke that wins the lock leaves the caller with the same 404 a
 * missing recipe gets, and two writers to one recipe's refill data take turns. No lock on a product is
 * taken: nothing here reads or writes the stock ledger, and no stock operation reads these tables.
 *
 * Dates are calendar dates (`YYYY-MM-DD`). "Today" is the caller's `as_of`, or the UTC date read once
 * in PHP when the caller sent none; it is passed to the arithmetic as a value. No query here uses
 * CURRENT_DATE or now() as a date, so the session zone cannot move a result (ADR-0042 section 4).
 *
 * Wording (ADR-0015): this service records dates a person entered and calculates from them. A notice
 * states a recorded fact and an estimate. It never tells a person to take, stop or change a medicine
 * and never says a refill is allowed.
 */
class ConsumptionRefillService extends BaseService
{
	public const SETTING_LEAD_DAYS = 'refill_warning_lead_days';
	public const AS_OF_CLIENT = 'client';
	public const AS_OF_SERVER_UTC = 'server_utc';

	private const KIND_APPROACHING = 'approaching';
	private const KIND_DUE = 'due';
	private const NOTICE_KEY_PATTERN = '/^(\d{1,18}):(approaching|due):(\d{4}-\d{2}-\d{2})$/D';
	private const MAX_NOTE_LENGTH = 2000;
	private const MAX_REASON_LENGTH = 500;
	private const MIN_YEAR = 1900;
	private const MAX_YEAR = 2200;
	private const MAX_INTEGER_ID = 2147483647;
	private const SETTINGS_FIELDS = ['rule', 'warning_lead_days', 'explicit_reorder_date'];

	/** A TIMESTAMPTZ column as ADR-0027 puts it on the wire: RFC 3339 in UTC with six fractional digits. */
	private static function Wire(string $column, string $alias): string
	{
		return "to_char($column AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS $alias";
	}

	private function Db(): \PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	private static function Actor(?int $userId): int
	{
		return $userId ?? (int)VICTUAL_USER_ID;
	}

	private function Recipes(): ConsumptionRecipeService
	{
		return ConsumptionRecipeService::GetInstance();
	}

	/**
	 * Runs the work in a transaction and names the two uniqueness conflicts the database can still
	 * report after the checks made under the recipe lock (a writer outside this service, or a lock
	 * that was not held).
	 */
	private function Transact(callable $work)
	{
		try
		{
			return DatabaseService::GetInstance()->InTransaction($work);
		}
		catch (\PDOException $exception)
		{
			if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'ux_consumption_refill_orders_open'))
			{
				throw new ConsumptionException(409, 'order_open', 'This prescription already has an open order');
			}

			if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'ux_consumption_refill_dates_live'))
			{
				throw new ConsumptionException(409, 'conflict', 'The explicit reorder date was changed by another request; try again');
			}

			throw $exception;
		}
	}

	// --- Dates and inputs --------------------------------------------------------------------

	/**
	 * The calendar date reads that depend on today use, and where it came from. The date is read from
	 * the request once and passed on; a request without one gets the UTC date, which is read here, in
	 * PHP, and is the only clock this class touches.
	 *
	 * @return array{0: string, 1: string} the date and 'client' or 'server_utc'
	 */
	public static function ResolveAsOf(mixed $asOf): array
	{
		if ($asOf === null)
		{
			return [gmdate('Y-m-d'), self::AS_OF_SERVER_UTC];
		}

		return [self::EntryDate($asOf, 'as_of', 'invalid_as_of'), self::AS_OF_CLIENT];
	}

	/** A real calendar date inside the range PostgreSQL and the arithmetic both handle. */
	private static function EntryDate(mixed $value, string $field, string $code = 'invalid_date'): string
	{
		$date = RefillEstimator::ParseDate($value);
		$year = $date === null ? 0 : (int)$date->format('Y');

		if ($date === null || $year < self::MIN_YEAR || $year > self::MAX_YEAR)
		{
			throw new ConsumptionException(422, $code, $field . ' must be a calendar date written YYYY-MM-DD between ' . self::MIN_YEAR . ' and ' . self::MAX_YEAR);
		}

		return $value;
	}

	/** A date a write records. It is required and never defaulted: the server's clock is UTC, the person's date is local. */
	private static function RequiredDate(array $input, string $field): string
	{
		if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '')
		{
			throw new ConsumptionException(422, 'date_required', $field . ' is required; Victual does not fill in a date');
		}

		return self::EntryDate($input[$field], $field);
	}

	/** A body may carry only the fields its route documents; a misspelled one (supplied_day) is refused, not dropped. */
	private static function OnlyFields(array $input, array $allowed): void
	{
		foreach (array_keys($input) as $field)
		{
			if (!in_array($field, $allowed, true))
			{
				throw new ConsumptionException(422, 'unknown_field', 'Unknown field: ' . (string)$field);
			}
		}
	}

	private static function SuppliedDays(array $input): ?int
	{
		$days = $input['supplied_days'] ?? null;

		if ($days === null)
		{
			return null;
		}

		if (!RefillEstimator::ValidSuppliedDays($days))
		{
			throw new ConsumptionException(422, 'invalid_supplied_days', 'supplied_days must be an integer from ' . RefillEstimator::MIN_SUPPLIED_DAYS . ' to ' . RefillEstimator::MAX_SUPPLIED_DAYS);
		}

		return $days;
	}

	private static function Text(mixed $value, string $field, int $maximum, bool $required): ?string
	{
		if ($value === null || (is_string($value) && trim($value) === ''))
		{
			if ($required)
			{
				throw new ConsumptionException(422, 'reason_required', $field . ' is required');
			}

			return null;
		}

		if (!is_string($value) || mb_strlen($value) > $maximum)
		{
			throw new ConsumptionException(422, 'invalid_' . $field, $field . ' must be text of at most ' . $maximum . ' characters');
		}

		return trim($value);
	}

	// --- Reading state -----------------------------------------------------------------------

	/**
	 * The refill state of one recipe, with its settings and history.
	 *
	 * @return array the state object of ADR-0042, plus `settings`, `explicit_date`, `fills` and `orders`
	 */
	public function GetRefill(int $recipeId, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);

		return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $userId, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, null, false);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	/**
	 * The state of every recipe the user can read, ordered by name.
	 *
	 * @return array{as_of: string, as_of_source: string, refills: list<array>}
	 */
	public function ListRefills(mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);

		return DatabaseService::GetInstance()->InTransaction(function () use ($userId, $date, $source)
		{
			$refills = [];
			foreach ($this->LockedCandidates($userId) as $recipe)
			{
				$refills[] = $this->Summary($recipe['id'], $recipe['name'], $userId, $date, $source);
			}

			return ['as_of' => $date, 'as_of_source' => $source, 'refills' => $refills];
		});
	}

	/**
	 * The recipes a list read may report, each locked and re-judged before any of its facts are read.
	 *
	 * ListRecipes() chooses candidates without a lock, so a share can be revoked, or the recipe deleted,
	 * before the first fact is read. Each candidate therefore goes through AuthoriseRefill() inside the
	 * caller's transaction, `FOR SHARE` on the recipe row, which is the lock a single-recipe read takes.
	 * A candidate that is gone or no longer readable is dropped, exactly as that read answers it with a
	 * 404, and nothing of it is read. The locks are taken in ascending recipe id, whatever order the
	 * list is returned in, so two readers never wait on each other's recipes in opposite orders. The
	 * locks are held to the end of the transaction, which is what makes a revoke wait for the read.
	 * The recipe is returned from the locked row, so its name is the committed name and not the
	 * candidate list's.
	 *
	 * @return list<array> the locked recipes, in the order ListRecipes() returned them
	 */
	private function LockedCandidates(int $userId): array
	{
		$candidates = $this->Recipes()->ListRecipes($userId);

		$ids = array_column($candidates, 'id');
		sort($ids);

		$locked = [];
		foreach ($ids as $id)
		{
			try
			{
				[$recipe] = $this->Recipes()->AuthoriseRefill($id, $userId, null, false);
			}
			catch (ConsumptionException $exception)
			{
				if ($exception->status !== 404)
				{
					throw $exception;
				}

				continue;
			}

			$locked[$id] = $recipe;
		}

		$ordered = [];
		foreach ($candidates as $candidate)
		{
			if (isset($locked[$candidate['id']]))
			{
				$ordered[] = ['id' => (int)$candidate['id'], 'name' => $locked[$candidate['id']]['name']];
			}
		}

		return $ordered;
	}

	/** The facts the state is calculated from. */
	private function Facts(int $recipeId): array
	{
		$db = $this->Db();

		$statement = $db->prepare('SELECT rule_kind, rule_parameter, warning_lead_days FROM consumption_refill_settings WHERE recipe_id = ?');
		$statement->execute([$recipeId]);
		$settings = $statement->fetch(\PDO::FETCH_ASSOC) ?: null;

		$statement = $db->prepare('SELECT id, filled_on, supplied_days FROM consumption_refill_fills
			WHERE recipe_id = ? AND voided_at IS NULL ORDER BY filled_on DESC, id DESC LIMIT 1');
		$statement->execute([$recipeId]);
		$fill = $statement->fetch(\PDO::FETCH_ASSOC) ?: null;

		$statement = $db->prepare('SELECT id, fill_id, reorder_on, ' . self::Wire('created_at', 'created_wire') . ' FROM consumption_refill_dates WHERE recipe_id = ? AND ended_at IS NULL');
		$statement->execute([$recipeId]);
		$explicit = $statement->fetch(\PDO::FETCH_ASSOC) ?: null;

		$statement = $db->prepare('SELECT id, ordered_on FROM consumption_refill_orders WHERE recipe_id = ? AND state = \'open\'');
		$statement->execute([$recipeId]);
		$order = $statement->fetch(\PDO::FETCH_ASSOC) ?: null;

		// A date applies only to the fill it was entered for, and only while that fill is current.
		if ($explicit !== null && ($fill === null || (int)$explicit['fill_id'] !== (int)$fill['id']))
		{
			$explicit = null;
		}

		return ['settings' => $settings, 'fill' => $fill, 'explicit' => $explicit, 'order' => $order];
	}

	/**
	 * The warning lead: the recipe's override, else the user's setting, else 7 (ADR-0042 section 4).
	 * A stored value outside 0 to 60 is not a lead and is skipped, so a bad setting never moves a date.
	 */
	private function LeadDays(?array $settings, int $userId): int
	{
		if ($settings !== null && $settings['warning_lead_days'] !== null)
		{
			return (int)$settings['warning_lead_days'];
		}

		$statement = $this->Db()->prepare('SELECT value FROM user_settings WHERE user_id = ? AND key = ?');
		$statement->execute([$userId, self::SETTING_LEAD_DAYS]);
		$value = $statement->fetchColumn();

		if ($value === false)
		{
			global $VICTUAL_DEFAULT_USER_SETTINGS;
			$value = $VICTUAL_DEFAULT_USER_SETTINGS[self::SETTING_LEAD_DAYS] ?? null;
		}

		$lead = filter_var($value, FILTER_VALIDATE_INT);

		return $lead !== false && RefillEstimator::ValidLeadDays($lead) ? $lead : RefillEstimator::DEFAULT_LEAD_DAYS;
	}

	/** The state object every read and write returns. */
	private function Summary(int $recipeId, string $recipeName, int $userId, string $asOf, string $asOfSource): array
	{
		$facts = $this->Facts($recipeId);
		$settings = $facts['settings'];
		$rule = $settings !== null && $settings['rule_kind'] !== null ? ['kind' => $settings['rule_kind'], 'parameter' => (int)$settings['rule_parameter']] : null;
		$fill = $facts['fill'] === null ? null : ['id' => (int)$facts['fill']['id'], 'filled_on' => $facts['fill']['filled_on'],
			'supplied_days' => $facts['fill']['supplied_days'] === null ? null : (int)$facts['fill']['supplied_days']];

		$estimate = RefillEstimator::Estimate($fill, $rule, $facts['explicit']['reorder_on'] ?? null);
		$lead = $this->LeadDays($settings, $userId);
		$order = $facts['order'];
		$status = RefillEstimator::Status($estimate, $lead, $asOf, $order !== null);

		return [
			'recipe_id' => $recipeId,
			'recipe_name' => $recipeName,
			'as_of' => $asOf,
			'as_of_source' => $asOfSource,
			'status' => $status['status'],
			'days_overdue' => $status['days_overdue'],
			'current_fill' => $fill === null ? null : ['id' => $fill['id'], 'filled_on' => $fill['filled_on'], 'supplied_days' => $fill['supplied_days']],
			'estimate' => ['reorder_date' => $estimate['reorder_date'], 'warning_date' => $status['warning_date'], 'source' => $estimate['source'],
				'lead_days' => $lead, 'reason' => $estimate['reason']],
			'open_order' => $order === null ? null : ['id' => (int)$order['id'], 'ordered_on' => $order['ordered_on'],
				'age_days' => max(0, RefillEstimator::DaysBetween($order['ordered_on'], $asOf))],
		];
	}

	/** The state with the recipe's settings and history, for the one-recipe read and for every write. */
	private function Detail(array $recipe, int $userId, string $asOf, string $asOfSource): array
	{
		$recipeId = (int)$recipe['id'];
		$state = $this->Summary($recipeId, $recipe['name'], $userId, $asOf, $asOfSource);
		$facts = $this->Facts($recipeId);
		$settings = $facts['settings'];
		$db = $this->Db();

		$statement = $db->prepare('SELECT id, filled_on, supplied_days, note, voided_at IS NOT NULL AS voided, void_reason, '
			. self::Wire('created_at', 'created_wire') . ', ' . self::Wire('voided_at', 'voided_wire')
			. ' FROM consumption_refill_fills WHERE recipe_id = ? ORDER BY filled_on DESC, id DESC');
		$statement->execute([$recipeId]);
		$currentId = $state['current_fill']['id'] ?? null;
		$fills = array_map(fn(array $row) => [
			'id' => (int)$row['id'],
			'filled_on' => $row['filled_on'],
			'supplied_days' => $row['supplied_days'] === null ? null : (int)$row['supplied_days'],
			'note' => $row['note'],
			'is_current' => (int)$row['id'] === $currentId,
			'created_at' => $row['created_wire'],
			'voided_at' => $row['voided_wire'],
			'void_reason' => $row['void_reason'],
		], $statement->fetchAll(\PDO::FETCH_ASSOC));

		$statement = $db->prepare('SELECT id, ordered_on, state, received_fill_id, ' . self::Wire('created_at', 'created_wire') . ', ' . self::Wire('closed_at', 'closed_wire')
			. ' FROM consumption_refill_orders WHERE recipe_id = ? ORDER BY ordered_on DESC, id DESC');
		$statement->execute([$recipeId]);
		$orders = array_map(fn(array $row) => [
			'id' => (int)$row['id'],
			'ordered_on' => $row['ordered_on'],
			'state' => $row['state'],
			'received_fill_id' => $row['received_fill_id'] === null ? null : (int)$row['received_fill_id'],
			'age_days' => $row['state'] === 'open' ? max(0, RefillEstimator::DaysBetween($row['ordered_on'], $asOf)) : null,
			'created_at' => $row['created_wire'],
			'closed_at' => $row['closed_wire'],
		], $statement->fetchAll(\PDO::FETCH_ASSOC));

		return $state + [
			'settings' => [
				'rule' => $settings !== null && $settings['rule_kind'] !== null ? ['kind' => $settings['rule_kind'], 'parameter' => (int)$settings['rule_parameter']] : null,
				'warning_lead_days' => $settings === null || $settings['warning_lead_days'] === null ? null : (int)$settings['warning_lead_days'],
			],
			'explicit_date' => $facts['explicit'] === null ? null : ['id' => (int)$facts['explicit']['id'], 'fill_id' => (int)$facts['explicit']['fill_id'],
				'reorder_on' => $facts['explicit']['reorder_on'], 'created_at' => $facts['explicit']['created_wire']],
			'fills' => $fills,
			'orders' => $orders,
		];
	}

	// --- Writing: settings, fills, orders ----------------------------------------------------

	/**
	 * Sets the medication-specific rule, the recipe's warning lead and the explicit reorder date. A key
	 * that is absent stays as it was and a key that is null clears it. The explicit date belongs to the
	 * current fill and ends, permanently, when a newer fill is recorded.
	 *
	 * @param array $changes any of `rule` ({kind, parameter}), `warning_lead_days`, `explicit_reorder_date`
	 */
	public function SetSettings(int $recipeId, array $changes, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);

		foreach (array_keys($changes) as $field)
		{
			if (!in_array($field, self::SETTINGS_FIELDS, true))
			{
				throw new ConsumptionException(422, 'unknown_field', 'Unknown field: ' . (string)$field);
			}
		}

		$rule = null;
		if (array_key_exists('rule', $changes) && $changes['rule'] !== null)
		{
			$given = $changes['rule'];
			if (!is_array($given) || !RefillEstimator::ValidRule($given['kind'] ?? null, $given['parameter'] ?? null))
			{
				throw new ConsumptionException(422, 'invalid_rule', 'rule needs a kind (days_before_end 0 to 730, fixed_interval 1 to 730 or fraction_elapsed 1 to 99 percent) and an integer parameter in that kind\'s range');
			}
			$rule = ['kind' => $given['kind'], 'parameter' => $given['parameter']];
		}

		if (array_key_exists('warning_lead_days', $changes) && $changes['warning_lead_days'] !== null && !RefillEstimator::ValidLeadDays($changes['warning_lead_days']))
		{
			throw new ConsumptionException(422, 'invalid_lead', 'warning_lead_days must be an integer from ' . RefillEstimator::MIN_LEAD_DAYS . ' to ' . RefillEstimator::MAX_LEAD_DAYS);
		}

		$explicitDate = null;
		if (array_key_exists('explicit_reorder_date', $changes) && $changes['explicit_reorder_date'] !== null)
		{
			$explicitDate = self::EntryDate($changes['explicit_reorder_date'], 'explicit_reorder_date');
		}

		return $this->Transact(function () use ($recipeId, $userId, $changes, $rule, $explicitDate, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);
			$db = $this->Db();

			if (array_key_exists('rule', $changes) || array_key_exists('warning_lead_days', $changes))
			{
				$statement = $db->prepare('SELECT rule_kind, rule_parameter, warning_lead_days FROM consumption_refill_settings WHERE recipe_id = ?');
				$statement->execute([$recipeId]);
				$current = $statement->fetch(\PDO::FETCH_ASSOC) ?: ['rule_kind' => null, 'rule_parameter' => null, 'warning_lead_days' => null];

				$kind = array_key_exists('rule', $changes) ? ($rule['kind'] ?? null) : $current['rule_kind'];
				$parameter = array_key_exists('rule', $changes) ? ($rule['parameter'] ?? null) : $current['rule_parameter'];
				$lead = array_key_exists('warning_lead_days', $changes) ? $changes['warning_lead_days'] : $current['warning_lead_days'];

				if ($kind === null && $lead === null)
				{
					$db->prepare('DELETE FROM consumption_refill_settings WHERE recipe_id = ?')->execute([$recipeId]);
				}
				else
				{
					$db->prepare('INSERT INTO consumption_refill_settings (recipe_id, rule_kind, rule_parameter, warning_lead_days, updated_at)
						VALUES (?, ?, ?, ?, now())
						ON CONFLICT (recipe_id) DO UPDATE SET rule_kind = EXCLUDED.rule_kind, rule_parameter = EXCLUDED.rule_parameter,
							warning_lead_days = EXCLUDED.warning_lead_days, updated_at = now()')
						->execute([$recipeId, $kind, $parameter, $lead]);
				}
			}

			if (array_key_exists('explicit_reorder_date', $changes))
			{
				$this->ReplaceExplicitDate($recipeId, $explicitDate);
			}

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	/** Ends the live explicit date and, when `$reorderOn` is set, enters it for the current fill. */
	private function ReplaceExplicitDate(int $recipeId, ?string $reorderOn): void
	{
		$db = $this->Db();
		$statement = $db->prepare('SELECT id FROM consumption_refill_fills WHERE recipe_id = ? AND voided_at IS NULL ORDER BY filled_on DESC, id DESC LIMIT 1');
		$statement->execute([$recipeId]);
		$fillId = $statement->fetchColumn();

		if ($reorderOn !== null)
		{
			if ($fillId === false)
			{
				throw new ConsumptionException(422, 'no_current_fill', 'Record a fill first: an explicit reorder date belongs to the current fill');
			}

			$statement = $db->prepare('SELECT filled_on FROM consumption_refill_fills WHERE id = ?');
			$statement->execute([$fillId]);
			if (RefillEstimator::DaysBetween($statement->fetchColumn(), $reorderOn) < 0)
			{
				throw new ConsumptionException(422, 'date_before_fill', 'The explicit reorder date cannot be earlier than the fill it is entered for');
			}
		}

		$db->prepare('UPDATE consumption_refill_dates SET ended_at = now(), ended_reason = ? WHERE recipe_id = ? AND ended_at IS NULL')
			->execute([$reorderOn === null ? 'cleared' : 'replaced', $recipeId]);

		if ($reorderOn !== null)
		{
			$db->prepare('INSERT INTO consumption_refill_dates (recipe_id, fill_id, reorder_on) VALUES (?, ?, ?)')->execute([$recipeId, $fillId, $reorderOn]);
		}
	}

	/** @param array $input filled_on (required), supplied_days (optional), note (optional) */
	public function RecordFill(int $recipeId, array $input, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);
		self::OnlyFields($input, ['filled_on', 'supplied_days', 'note']);
		$filledOn = self::RequiredDate($input, 'filled_on');
		$days = self::SuppliedDays($input);
		$note = self::Text($input['note'] ?? null, 'note', self::MAX_NOTE_LENGTH, false);

		return $this->Transact(function () use ($recipeId, $userId, $filledOn, $days, $note, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);
			$this->InsertFill($recipeId, $filledOn, $days, $note);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	private function InsertFill(int $recipeId, string $filledOn, ?int $days, ?string $note): int
	{
		$statement = $this->Db()->prepare('INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days, note) VALUES (?, ?, ?, ?) RETURNING id');
		$statement->execute([$recipeId, $filledOn, $days, $note]);

		return (int)$statement->fetchColumn();
	}

	/** Voids a fill with a reason. The fill stays in the history; the previous unvoided fill becomes current. */
	public function VoidFill(int $recipeId, int $fillId, mixed $reason, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);
		$reason = self::Text($reason, 'reason', self::MAX_REASON_LENGTH, true);

		return $this->Transact(function () use ($recipeId, $fillId, $reason, $userId, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);

			$statement = $this->Db()->prepare('SELECT voided_at FROM consumption_refill_fills WHERE id = ? AND recipe_id = ? FOR UPDATE');
			$statement->execute([$fillId, $recipeId]);
			$row = $statement->fetch(\PDO::FETCH_ASSOC);

			if ($row === false)
			{
				throw new ConsumptionException(404, 'fill_not_found', 'This prescription has no such fill');
			}

			if ($row['voided_at'] !== null)
			{
				throw new ConsumptionException(409, 'already_voided', 'This fill is already voided');
			}

			$this->Db()->prepare('UPDATE consumption_refill_fills SET voided_at = now(), void_reason = ? WHERE id = ?')->execute([$reason, $fillId]);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	/** Records an order. It adds no stock and records no fill (ADR-0042 section 5). */
	public function RecordOrder(int $recipeId, array $input, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);
		self::OnlyFields($input, ['ordered_on']);
		$orderedOn = self::RequiredDate($input, 'ordered_on');

		return $this->Transact(function () use ($recipeId, $userId, $orderedOn, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);

			$statement = $this->Db()->prepare('SELECT 1 FROM consumption_refill_orders WHERE recipe_id = ? AND state = \'open\'');
			$statement->execute([$recipeId]);
			if ($statement->fetchColumn() !== false)
			{
				throw new ConsumptionException(409, 'order_open', 'This prescription already has an open order');
			}

			$this->Db()->prepare('INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES (?, ?)')->execute([$recipeId, $orderedOn]);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	/**
	 * Receives an open order by recording the fill that arrived, and closes the order against that fill,
	 * in one transaction. Stock arrives separately, through a normal purchase booking.
	 *
	 * @param array $input the fill: filled_on (required), supplied_days (optional), note (optional)
	 */
	public function ReceiveOrder(int $recipeId, int $orderId, array $input, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);
		self::OnlyFields($input, ['filled_on', 'supplied_days', 'note']);
		$filledOn = self::RequiredDate($input, 'filled_on');
		$days = self::SuppliedDays($input);
		$note = self::Text($input['note'] ?? null, 'note', self::MAX_NOTE_LENGTH, false);

		return $this->Transact(function () use ($recipeId, $orderId, $userId, $filledOn, $days, $note, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);
			$this->OpenOrderForUpdate($recipeId, $orderId);

			$fillId = $this->InsertFill($recipeId, $filledOn, $days, $note);
			$this->Db()->prepare('UPDATE consumption_refill_orders SET state = \'received\', received_fill_id = ?, closed_at = now() WHERE id = ?')->execute([$fillId, $orderId]);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	/** Cancels an open order. The status returns to what the fills and rules say. */
	public function CancelOrder(int $recipeId, int $orderId, mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);

		return $this->Transact(function () use ($recipeId, $orderId, $userId, $date, $source)
		{
			[$recipe] = $this->Recipes()->AuthoriseRefill($recipeId, $userId, 'edit', true);
			$this->OpenOrderForUpdate($recipeId, $orderId);

			$this->Db()->prepare('UPDATE consumption_refill_orders SET state = \'cancelled\', closed_at = now() WHERE id = ?')->execute([$orderId]);

			return $this->Detail($recipe, $userId, $date, $source);
		});
	}

	private function OpenOrderForUpdate(int $recipeId, int $orderId): void
	{
		$statement = $this->Db()->prepare('SELECT state FROM consumption_refill_orders WHERE id = ? AND recipe_id = ? FOR UPDATE');
		$statement->execute([$orderId, $recipeId]);
		$state = $statement->fetchColumn();

		if ($state === false)
		{
			throw new ConsumptionException(404, 'order_not_found', 'This prescription has no such order');
		}

		if ($state !== 'open')
		{
			throw new ConsumptionException(409, 'order_closed', 'This order is already ' . $state);
		}
	}

	// --- Notices -----------------------------------------------------------------------------

	/**
	 * The caller's unacknowledged notices (ADR-0042 section 6). `approaching` is raised from the warning
	 * date until the day before the reorder date and `due` from the reorder date; an open order raises
	 * neither. Acknowledgements are per user, and a notice's key holds its reorder date, so a correction
	 * that moves the date raises a new notice and one that does not leaves the old one acknowledged.
	 *
	 * @return array{as_of: string, as_of_source: string, notices: list<array>}
	 */
	public function Notices(mixed $asOf = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		[$date, $source] = self::ResolveAsOf($asOf);

		return DatabaseService::GetInstance()->InTransaction(function () use ($userId, $date, $source)
		{
			$raised = [];
			foreach ($this->LockedCandidates($userId) as $recipe)
			{
				$state = $this->Summary($recipe['id'], $recipe['name'], $userId, $date, $source);
				if ($state['status'] === RefillEstimator::STATUS_APPROACHING || $state['status'] === RefillEstimator::STATUS_DUE)
				{
					$raised[] = $this->Notice($state);
				}
			}

			$acknowledged = [];
			$statement = $this->Db()->prepare('SELECT recipe_id, kind, reorder_date FROM consumption_refill_acks WHERE user_id = ?');
			$statement->execute([$userId]);
			foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row)
			{
				$acknowledged[$row['recipe_id'] . ':' . $row['kind'] . ':' . $row['reorder_date']] = true;
			}

			$notices = array_values(array_filter($raised, fn(array $notice) => !isset($acknowledged[$notice['key']])));
			usort($notices, fn(array $a, array $b) => [$a['reorder_date'], $a['recipe_id']] <=> [$b['reorder_date'], $b['recipe_id']]);

			return ['as_of' => $date, 'as_of_source' => $source, 'notices' => $notices];
		});
	}

	private function Notice(array $state): array
	{
		$kind = $state['status'] === RefillEstimator::STATUS_DUE ? self::KIND_DUE : self::KIND_APPROACHING;
		$reorder = $state['estimate']['reorder_date'];

		return [
			'key' => $state['recipe_id'] . ':' . $kind . ':' . $reorder,
			'kind' => $kind,
			'recipe_id' => $state['recipe_id'],
			'recipe_name' => $state['recipe_name'],
			'reorder_date' => $reorder,
			'warning_date' => $state['estimate']['warning_date'],
			'days_overdue' => $state['days_overdue'],
			'source' => $state['estimate']['source'],
			'text' => self::NoticeText($kind, $reorder, $state['estimate']['source']),
		];
	}

	/**
	 * The notice sentence (ADR-0042 section 6, ADR-0015). It names no medicine, so a client can show it
	 * where a title would disclose one, and it states a date and where the date came from.
	 */
	public static function NoticeText(string $kind, string $reorderDate, string $source): string
	{
		$from = match (true)
		{
			$source === RefillEstimator::SOURCE_EXPLICIT => 'the date you entered',
			$source === RefillEstimator::SOURCE_FALLBACK => 'your last fill',
			default => 'the rule you set for this prescription',
		};

		return $kind === self::KIND_DUE
			? 'Reorder date reached: estimated ' . $reorderDate . ' (from ' . $from . ')'
			: 'Estimated reorder date ' . $reorderDate . ' (from ' . $from . ')';
	}

	/**
	 * Acknowledges one notice for the caller. The key must have the form `<recipe_id>:<kind>:<date>`
	 * (400 otherwise) and name a recipe the caller can read (404 otherwise). Repeating the call returns
	 * the same answer and stores one row.
	 *
	 * @return array{notice_key: string, acknowledged_at: string}
	 */
	public function Acknowledge(mixed $noticeKey, ?int $userId = null): array
	{
		$userId = self::Actor($userId);

		if (!is_string($noticeKey) || preg_match(self::NOTICE_KEY_PATTERN, $noticeKey, $parts) !== 1 || RefillEstimator::ParseDate($parts[3]) === null)
		{
			throw new ConsumptionException(400, 'invalid_notice_key', 'notice_key must be <recipe_id>:<approaching|due>:<YYYY-MM-DD>');
		}

		[, $recipeText, $kind, $reorderDate] = $parts;
		$year = (int)substr($reorderDate, 0, 4);
		if ($year < self::MIN_YEAR || $year > self::MAX_YEAR)
		{
			throw new ConsumptionException(400, 'invalid_notice_key', 'notice_key must be <recipe_id>:<approaching|due>:<YYYY-MM-DD>');
		}

		$recipeId = (int)$recipeText;

		return DatabaseService::GetInstance()->InTransaction(function () use ($userId, $recipeId, $kind, $reorderDate, $noticeKey)
		{
			if ($recipeId > self::MAX_INTEGER_ID)
			{
				// No such recipe, answered as a recipe the caller cannot read is.
				throw new ConsumptionException(404, 'not_found', 'Consumption recipe does not exist');
			}

			$this->Recipes()->AuthoriseRefill($recipeId, $userId, null, false);

			$this->Db()->prepare('INSERT INTO consumption_refill_acks (user_id, recipe_id, kind, reorder_date) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING')
				->execute([$userId, $recipeId, $kind, $reorderDate]);

			$statement = $this->Db()->prepare('SELECT ' . self::Wire('acknowledged_at', 'acknowledged_wire')
				. ' FROM consumption_refill_acks WHERE user_id = ? AND recipe_id = ? AND kind = ? AND reorder_date = ?');
			$statement->execute([$userId, $recipeId, $kind, $reorderDate]);

			return ['notice_key' => $recipeId . ':' . $kind . ':' . $reorderDate, 'acknowledged_at' => $statement->fetchColumn()];
		});
	}

	// --- Setting validation shared with the user-settings route ------------------------------

	/**
	 * The user setting `refill_warning_lead_days` as an integer from 0 to 60, or a 422. The generic
	 * user-settings route calls this for that key only, so a stored value is always a lead.
	 */
	public static function ValidatedLeadSetting(mixed $value): int
	{
		// An integer or the decimal digits of one. A boolean, a float and a null are not a lead: filter_var()
		// would read true as 1.
		$lead = is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,6}$/D', $value) === 1 ? (int)$value : false);

		if ($lead === false || !RefillEstimator::ValidLeadDays($lead))
		{
			throw new ConsumptionException(422, 'invalid_lead', self::SETTING_LEAD_DAYS . ' must be an integer from ' . RefillEstimator::MIN_LEAD_DAYS . ' to ' . RefillEstimator::MAX_LEAD_DAYS);
		}

		return $lead;
	}
}
