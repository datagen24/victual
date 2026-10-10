<?php

namespace Victual\Services;

use Victual\Controllers\Users\User;

/**
 * Private consumption recipes: an owned list of product quantities a person consumes together
 * (ADR-0040), the shares that let named users use them, and the `manual` consumption events a
 * consumption creates (ADR-0041 rule 9).
 *
 * Authority is the conjunction of two tests, both made inside the transaction that does the work:
 * the share right the caller holds on the recipe, and the global permission the same act needs
 * elsewhere (ADR-0040 rule 2). A share confers no permission, so nothing here touches
 * User::CheckMayGrant() or User::MayAdminister().
 *
 * Every method takes the acting user as a parameter, defaulting to the signed-in one. Authority
 * is read for that user, not from the ambient VICTUAL_USER_ID, because a test cannot redefine the
 * constant and a share must be judged for the person it names. The one exception is the user a
 * booking is attributed to: StockService writes the ambient VICTUAL_USER_ID, so a route must pass
 * the signed-in user, and only a test passes anyone else.
 *
 * Locks, in this order (ADR-0040 rule 8): the recipe row (`FOR UPDATE` for grant, change, revoke,
 * transfer, delete and edit; `FOR SHARE` for consume, undo and reads), then the ascending product
 * lock set. A refusal raised after a booking has started escapes the transaction, so the whole
 * consumption rolls back (ADR-0041 evidence: catching it inside left earlier lines booked).
 *
 * Event lines are one row per `stock_log` row the consumption wrote, not one per recipe line: a
 * two-tablet line taken first-expired-first-out from two stock rows is two event lines, each with
 * the location and stock amount it booked.
 *
 * Wording (ADR-0015): this service records what a person did. It never evaluates, warns or advises.
 */
class ConsumptionRecipeService extends BaseService
{
	public const SOURCE_MANUAL = 'manual';

	private const MAX_LINES = 50;
	private const MAX_NAME_LENGTH = 200;
	private const MAX_NOTE_LENGTH = 2000;
	private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';
	private const RIGHTS = ['consume', 'edit', 'undo', 'share'];

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

	// --- Authority ---------------------------------------------------------------------------

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

	/** Locks the recipe row and returns it, or null when it does not exist. */
	private function LockRecipe(int $recipeId, bool $forUpdate): ?array
	{
		$statement = $this->Db()->prepare('SELECT *, ' . self::Wire('row_created_timestamp', 'created_wire') . ', ' . self::Wire('row_updated_timestamp', 'updated_wire')
			. ' FROM consumption_recipes WHERE id = ? ' . ($forUpdate ? 'FOR UPDATE' : 'FOR SHARE'));
		$statement->execute([$recipeId]);
		$row = $statement->fetch(\PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/**
	 * The rights a user holds on a locked recipe, or null when they hold none, which the callers
	 * answer exactly as they answer a recipe that does not exist.
	 *
	 * @return array{read: bool, consume: bool, edit: bool, undo: bool, share: bool, owner: bool}|null
	 */
	private function RightsOf(array $recipe, int $userId): ?array
	{
		if ((int)$recipe['owner_user_id'] === $userId)
		{
			return ['read' => true, 'consume' => true, 'edit' => true, 'undo' => true, 'share' => true, 'owner' => true];
		}

		$statement = $this->Db()->prepare('SELECT can_consume, can_edit, can_undo, can_share FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?');
		$statement->execute([$recipe['id'], $userId]);
		$share = $statement->fetch(\PDO::FETCH_ASSOC);

		if ($share === false)
		{
			return null;
		}

		return ['read' => true, 'consume' => (bool)$share['can_consume'], 'edit' => (bool)$share['can_edit'], 'undo' => (bool)$share['can_undo'],
			'share' => (bool)$share['can_share'], 'owner' => false];
	}

	/**
	 * Locks a recipe and requires a right on it. A user with no read right gets the not-found
	 * answer; a user with read but not the right gets 403.
	 *
	 * @return array{0: array, 1: array} the recipe row and the user's rights
	 */
	private function Authorise(int $recipeId, int $userId, ?string $right, bool $forUpdate): array
	{
		$recipe = $this->LockRecipe($recipeId, $forUpdate);
		$rights = $recipe === null ? null : $this->RightsOf($recipe, $userId);

		if ($rights === null)
		{
			throw new ConsumptionException(404, 'not_found', 'Consumption recipe does not exist');
		}

		if ($right !== null && !$rights[$right])
		{
			throw new ConsumptionException(403, 'right_missing', 'You do not hold the ' . $right . ' right on this consumption recipe');
		}

		return [$recipe, $rights];
	}

	/**
	 * Locks a recipe and requires a right on it, for the refill records that belong to it
	 * (ADR-0042 section 1): `read` for reading a recipe's refill data, `edit` for recording or
	 * changing it. The global permission both need is STOCK_VIEW. Called inside the caller's
	 * transaction, so the share is judged under the same recipe lock as every other act on the
	 * recipe (ADR-0040 rule 8), and a revoke that wins the lock leaves the caller with the 404.
	 *
	 * @param string|null $right null for read, or 'edit'
	 * @return array{0: array, 1: array} the recipe row and the user's rights
	 */
	public function AuthoriseRefill(int $recipeId, int $userId, ?string $right, bool $forUpdate): array
	{
		$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);

		return $this->Authorise($recipeId, $userId, $right, $forUpdate);
	}

	// --- Reads -------------------------------------------------------------------------------

	/** Every recipe the user owns or holds a share on, by name. */
	public function ListRecipes(?int $userId = null): array
	{
		$userId = self::Actor($userId);
		$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);

		$statement = $this->Db()->prepare('SELECT r.*, ' . self::Wire('r.row_created_timestamp', 'created_wire') . ', ' . self::Wire('r.row_updated_timestamp', 'updated_wire') . ', s.can_consume, s.can_edit, s.can_undo, s.can_share,
				(SELECT count(*) FROM consumption_recipe_lines l WHERE l.recipe_id = r.id) AS line_count
			FROM consumption_recipes r
			LEFT JOIN consumption_recipe_shares s ON s.recipe_id = r.id AND s.user_id = :user
			WHERE r.owner_user_id = :user OR s.user_id IS NOT NULL
			ORDER BY lower(r.name), r.id');
		$statement->execute(['user' => $userId]);

		$recipes = [];
		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$recipes[] = $this->Summary($row, $this->RightsFromJoin($row, $userId)) + ['line_count' => (int)$row['line_count']];
		}

		return $recipes;
	}

	private function RightsFromJoin(array $row, int $userId): array
	{
		if ((int)$row['owner_user_id'] === $userId)
		{
			return ['read' => true, 'consume' => true, 'edit' => true, 'undo' => true, 'share' => true, 'owner' => true];
		}

		return ['read' => true, 'consume' => (bool)$row['can_consume'], 'edit' => (bool)$row['can_edit'], 'undo' => (bool)$row['can_undo'],
			'share' => (bool)$row['can_share'], 'owner' => false];
	}

	private function Summary(array $recipe, array $rights): array
	{
		return [
			'id' => (int)$recipe['id'],
			'name' => $recipe['name'],
			'note' => $recipe['note'],
			'owner_user_id' => (int)$recipe['owner_user_id'],
			'is_owner' => $rights['owner'],
			'rights' => ['read' => true, 'consume' => $rights['consume'], 'edit' => $rights['edit'], 'undo' => $rights['undo'], 'share' => $rights['share']],
			'row_created_timestamp' => $recipe['created_wire'],
			'row_updated_timestamp' => $recipe['updated_wire'],
		];
	}

	public function GetRecipe(int $recipeId, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[$recipe, $rights] = $this->Authorise($recipeId, $userId, null, false);

			return $this->Summary($recipe, $rights) + ['lines' => $this->Lines($recipeId)];
		});
	}

	private function Lines(int $recipeId): array
	{
		$statement = $this->Db()->prepare('SELECT l.position, l.product_id, p.name AS product_name, l.amount, l.qu_id
			FROM consumption_recipe_lines l JOIN products p ON p.id = l.product_id WHERE l.recipe_id = ? ORDER BY l.position');
		$statement->execute([$recipeId]);

		return array_map(fn(array $row) => ['position' => (int)$row['position'], 'product_id' => (int)$row['product_id'],
			'product_name' => $row['product_name'], 'amount' => (float)$row['amount'], 'qu_id' => (int)$row['qu_id']], $statement->fetchAll(\PDO::FETCH_ASSOC));
	}

	// --- Create, edit, delete ----------------------------------------------------------------

	/** @param array $lines each {product_id, amount, qu_id} */
	public function CreateRecipe(string $name, ?string $note, array $lines, ?int $userId = null): int
	{
		$userId = self::Actor($userId);
		$name = $this->CleanName($name);
		$note = $this->CleanNote($note);

		return DatabaseService::GetInstance()->InTransaction(function () use ($userId, $name, $note, $lines)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_CONSUME);
			$lines = $this->ValidatedLines($lines);

			$statement = $this->Db()->prepare('INSERT INTO consumption_recipes (owner_user_id, name, note) VALUES (?, ?, ?) RETURNING id');
			$statement->execute([$userId, $name, $note]);
			$recipeId = (int)$statement->fetchColumn();
			$this->WriteLines($recipeId, $lines);

			return $recipeId;
		});
	}

	/** @param array $changes any of name, note, lines; lines replace the whole list */
	public function UpdateRecipe(int $recipeId, array $changes, ?int $userId = null): void
	{
		$userId = self::Actor($userId);

		DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $changes, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[$recipe] = $this->Authorise($recipeId, $userId, 'edit', true);

			$name = array_key_exists('name', $changes) ? $this->CleanName((string)$changes['name']) : $recipe['name'];
			$note = array_key_exists('note', $changes) ? $this->CleanNote($changes['note']) : $recipe['note'];
			$lines = array_key_exists('lines', $changes) ? $this->ValidatedLines($changes['lines']) : null;

			$this->Db()->prepare('UPDATE consumption_recipes SET name = ?, note = ?, row_updated_timestamp = now() WHERE id = ?')
				->execute([$name, $note, $recipeId]);

			if ($lines !== null)
			{
				$this->Db()->prepare('DELETE FROM consumption_recipe_lines WHERE recipe_id = ?')->execute([$recipeId]);
				$this->WriteLines($recipeId, $lines);
			}
		});
	}

	public function DeleteRecipe(int $recipeId, ?int $userId = null): void
	{
		$userId = self::Actor($userId);

		DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[, $rights] = $this->Authorise($recipeId, $userId, null, true);

			if (!$rights['owner'])
			{
				throw new ConsumptionException(403, 'right_missing', 'Only the owner can delete a consumption recipe');
			}

			$this->Db()->prepare('DELETE FROM consumption_recipes WHERE id = ?')->execute([$recipeId]);
		});
	}

	private function CleanName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH)
		{
			throw new ConsumptionException(422, 'invalid_name', 'A name of 1 to ' . self::MAX_NAME_LENGTH . ' characters is required');
		}

		return $name;
	}

	private function CleanNote($note): ?string
	{
		if ($note === null || trim((string)$note) === '')
		{
			return null;
		}

		if (!is_string($note) || mb_strlen($note) > self::MAX_NOTE_LENGTH)
		{
			throw new ConsumptionException(422, 'invalid_note', 'A note is at most ' . self::MAX_NOTE_LENGTH . ' characters');
		}

		return $note;
	}

	/**
	 * Checks every line against the products and units it names and against the conversions the
	 * household entered. Nothing is inferred: a unit with no conversion to the product's stock unit
	 * is refused here and again, with nothing booked, at consumption.
	 */
	private function ValidatedLines($lines): array
	{
		if (!is_array($lines) || count($lines) === 0 || count($lines) > self::MAX_LINES)
		{
			throw new ConsumptionException(422, 'invalid_lines', 'A recipe needs 1 to ' . self::MAX_LINES . ' lines');
		}

		$clean = [];
		foreach (array_values($lines) as $index => $line)
		{
			$productId = is_array($line) ? filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT) : false;
			$quId = is_array($line) ? filter_var($line['qu_id'] ?? null, FILTER_VALIDATE_INT) : false;
			$amount = is_array($line) && is_numeric($line['amount'] ?? null) ? (float)$line['amount'] : null;

			if ($productId === false || $quId === false || $amount === null || !is_finite($amount) || $amount <= 0)
			{
				throw new ConsumptionException(422, 'invalid_line', 'Line ' . ($index + 1) . ' needs a product, a unit and a positive amount');
			}

			$this->StockAmount((int)$productId, (int)$quId, $amount, $index + 1);
			$clean[] = ['product_id' => (int)$productId, 'qu_id' => (int)$quId, 'amount' => $amount];
		}

		return $clean;
	}

	private function WriteLines(int $recipeId, array $lines): void
	{
		$statement = $this->Db()->prepare('INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id) VALUES (?, ?, ?, ?, ?)');
		foreach ($lines as $index => $line)
		{
			$statement->execute([$recipeId, $index + 1, $line['product_id'], $line['amount'], $line['qu_id']]);
		}
	}

	/**
	 * The amount in the product's stock unit, through the product's own entered conversions.
	 *
	 * @throws ConsumptionException 422 when the product is missing or inactive, the unit does not
	 *                              exist, or no conversion reaches the stock unit
	 */
	private function StockAmount(int $productId, int $quId, float $amount, int $lineNumber): float
	{
		$statement = $this->Db()->prepare('SELECT qu_id_stock, active FROM products WHERE id = ?');
		$statement->execute([$productId]);
		$product = $statement->fetch(\PDO::FETCH_ASSOC);

		if ($product === false || (int)$product['active'] !== 1)
		{
			throw new ConsumptionException(422, 'invalid_product', 'Line ' . $lineNumber . ' names a product that does not exist or is inactive');
		}

		$statement = $this->Db()->prepare('SELECT 1 FROM quantity_units WHERE id = ?');
		$statement->execute([$quId]);
		if ($statement->fetchColumn() === false)
		{
			throw new ConsumptionException(422, 'invalid_unit', 'Line ' . $lineNumber . ' names a quantity unit that does not exist');
		}

		if ($quId === (int)$product['qu_id_stock'])
		{
			return $amount;
		}

		$statement = $this->Db()->prepare('SELECT factor FROM cache__quantity_unit_conversions_resolved WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ? LIMIT 1');
		$statement->execute([$productId, $quId, (int)$product['qu_id_stock']]);
		$factor = $statement->fetchColumn();

		if ($factor === false || !is_numeric($factor) || !is_finite((float)$factor) || (float)$factor <= 0)
		{
			throw new ConsumptionException(422, 'no_conversion', 'Line ' . $lineNumber . ' uses a unit with no entered conversion to the stock unit of its product');
		}

		return $amount * (float)$factor;
	}

	/**
	 * Whether the user holds the `consume` right on a recipe. A recipe the user cannot read and one that
	 * does not exist answer alike (false), so a mapping request cannot probe for a hidden recipe
	 * (ADR-0040 rule 7). Takes a FOR SHARE lock, which a caller inside a transaction keeps.
	 */
	public function MayConsume(int $recipeId, int $userId): bool
	{
		$recipe = $this->LockRecipe($recipeId, false);
		$rights = $recipe === null ? null : $this->RightsOf($recipe, $userId);

		return $rights !== null && $rights['consume'];
	}

	/**
	 * The recipe's lines in the product stock unit, for an external event that maps to the recipe
	 * (ADR-0041 rule 4). Takes the recipe lock FOR SHARE before the caller locks products, which is the
	 * order ADR-0040 rule 8 fixes, and requires the global permission the consumption needs.
	 *
	 * @return array<int, array{0: int, 1: float}>|null (product id, stock amount) per line, or null when
	 *         the user holds no `consume` right or the recipe no longer exists (`recipe_unavailable`)
	 * @throws ConsumptionException 422 when a line names a product or unit that no longer converts
	 */
	public function LinesForExternalConsumption(int $recipeId, int $userId): ?array
	{
		$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_CONSUME);

		if (!$this->MayConsume($recipeId, $userId))
		{
			return null;
		}

		$rows = $this->Db()->prepare('SELECT product_id, amount, qu_id FROM consumption_recipe_lines WHERE recipe_id = ? ORDER BY position');
		$rows->execute([$recipeId]);
		$planned = [];

		foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $index => $line)
		{
			$planned[] = [(int)$line['product_id'], $this->StockAmount((int)$line['product_id'], (int)$line['qu_id'], (float)$line['amount'], $index + 1)];
		}

		return $planned;
	}

	// --- Consumption -------------------------------------------------------------------------

	/**
	 * Books every line of the recipe in one transaction and records a `manual` event.
	 *
	 * A refusal at any point, a missing right, a missing conversion or too little stock, rolls the
	 * whole transaction back and leaves no event (ADR-0041 rule 9 clarification: a refused manual
	 * consumption is not a recorded consumption, and the person is present to read the refusal).
	 * The call is idempotent only when the client sends `$requestId`; without one a retry books again.
	 *
	 * @return array the event, with `replayed` true when the request id had already been booked
	 */
	public function Consume(int $recipeId, ?string $requestId = null, ?int $locationId = null, ?string $occurredAt = null, ?int $userId = null): array
	{
		$userId = self::Actor($userId);
		$requestId = $requestId ?? bin2hex(random_bytes(16));
		if (preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1)
		{
			throw new ConsumptionException(422, 'invalid_request_id', 'A request id is 1 to 128 characters of letters, digits and . _ : -');
		}
		[$occurred, $usedDate] = $this->ParseOccurredAt($occurredAt);

		try
		{
			return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $requestId, $locationId, $occurred, $usedDate, $userId)
			{
				$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_CONSUME);

				$statement = $this->Db()->prepare("INSERT INTO consumption_events (user_id, source_system, source_event_id, recipe_id, state, occurred_at)
					VALUES (?, 'manual', ?, NULL, 'received', ?) ON CONFLICT (user_id, source_system, source_event_id) DO NOTHING RETURNING id");
				$statement->execute([$userId, $requestId, $occurred]);
				$eventId = $statement->fetchColumn();

				if ($eventId === false)
				{
					return $this->StoredManualEvent($userId, $requestId) + ['replayed' => true];
				}

				// The recipe id is written only after the caller's right is proved. Inserting it with the
				// event would fail the foreign key for an id that does not exist and so tell the caller
				// something a hidden recipe does not (ADR-0040 rule 7).
				[$recipe] = $this->Authorise($recipeId, $userId, 'consume', false);
				$this->Db()->prepare('UPDATE consumption_events SET recipe_id = ? WHERE id = ?')->execute([$recipe['id'], $eventId]);
				$rows = $this->Db()->prepare('SELECT product_id, amount, qu_id FROM consumption_recipe_lines WHERE recipe_id = ? ORDER BY position');
				$rows->execute([$recipe['id']]);
				$lines = $rows->fetchAll(\PDO::FETCH_ASSOC);

				if (count($lines) === 0)
				{
					throw new ConsumptionException(422, 'empty_recipe', 'This consumption recipe has no lines');
				}

				if ($locationId !== null)
				{
					$exists = $this->Db()->prepare('SELECT 1 FROM locations WHERE id = ?');
					$exists->execute([$locationId]);
					if ($exists->fetchColumn() === false)
					{
						throw new ConsumptionException(422, 'invalid_location', 'The location does not exist');
					}
				}

				$planned = [];
				foreach ($lines as $index => $line)
				{
					$planned[] = [(int)$line['product_id'], $this->StockAmount((int)$line['product_id'], (int)$line['qu_id'], (float)$line['amount'], $index + 1)];
				}

				DatabaseService::GetInstance()->LockProductsStock(array_column($planned, 0));

				$transactionId = null;
				foreach ($planned as [$productId, $stockAmount])
				{
					// null for the recipe id: stock_log.recipe_id is never set for a consumption
					// recipe (ADR-0040 rule 1); the link is the event's transaction_id.
					StockService::GetInstance()->ConsumeProduct($productId, $stockAmount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locationId, $transactionId, false, false, $usedDate);
				}

				$this->RecordBookings((int)$eventId, $transactionId);
				$this->Db()->prepare("UPDATE consumption_events SET state = 'booked', transaction_id = ?, updated_at = now() WHERE id = ?")
					->execute([$transactionId, $eventId]);

				return $this->EventById((int)$eventId) + ['replayed' => false];
			});
		}
		catch (ConsumptionException | \PDOException $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			// StockService refuses with a plain \Exception for nine different causes and the evidence
			// found no typed way to tell them apart; the person is present, so its message is shown.
			throw new ConsumptionException(409, 'stock_refused', $exception->getMessage());
		}
	}

	/** @return array{0: string, 1: string|null} the instant to store and the Y-m-d date to book, in the offset the client sent */
	private function ParseOccurredAt(?string $occurredAt): array
	{
		if ($occurredAt === null)
		{
			return [gmdate('Y-m-d\TH:i:s\Z'), null];
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $occurredAt) !== 1)
		{
			throw new ConsumptionException(422, 'invalid_occurred_at', 'occurred_at must be an RFC 3339 time with an offset');
		}

		try
		{
			$instant = new \DateTimeImmutable($occurredAt);
		}
		catch (\Exception)
		{
			throw new ConsumptionException(422, 'invalid_occurred_at', 'occurred_at must be an RFC 3339 time with an offset');
		}

		if ($instant->getTimestamp() > time() + 300)
		{
			throw new ConsumptionException(422, 'future_occurred_at', 'occurred_at cannot be more than five minutes in the future');
		}

		// The calendar date as the client wrote it: its local date (the server runs in UTC).
		return [$instant->format('Y-m-d\TH:i:sP'), $instant->format('Y-m-d')];
	}

	/** One event line per stock_log row the transaction wrote, with the stock amount and location booked. */
	private function RecordBookings(int $eventId, string $transactionId): void
	{
		$bookings = $this->Db()->prepare('SELECT id, product_id, amount, location_id FROM stock_log WHERE transaction_id = ? AND undone = 0 ORDER BY id');
		$bookings->execute([$transactionId]);
		$insert = $this->Db()->prepare('INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id, stock_log_id) VALUES (?, ?, ?, ?, ?)');

		foreach ($bookings->fetchAll(\PDO::FETCH_ASSOC) as $booking)
		{
			$insert->execute([$eventId, $booking['product_id'], abs((float)$booking['amount']), $booking['location_id'], $booking['id']]);
		}
	}

	private function StoredManualEvent(int $userId, string $requestId): array
	{
		$statement = $this->Db()->prepare("SELECT id FROM consumption_events WHERE user_id = ? AND source_system = 'manual' AND source_event_id = ?");
		$statement->execute([$userId, $requestId]);

		return $this->EventById((int)$statement->fetchColumn());
	}

	private function EventById(int $eventId): array
	{
		$statement = $this->Db()->prepare('SELECT *, ' . self::Wire('occurred_at', 'occurred_wire') . ' FROM consumption_events WHERE id = ?');
		$statement->execute([$eventId]);
		$event = $statement->fetch(\PDO::FETCH_ASSOC);

		$lines = $this->Db()->prepare('SELECT product_id, amount, location_id, stock_log_id FROM consumption_event_lines WHERE event_id = ? ORDER BY id');
		$lines->execute([$eventId]);

		[$state, $reason] = $this->DerivedState($event);

		return [
			'id' => (int)$event['id'],
			'source_system' => $event['source_system'],
			'source_event_id' => $event['source_event_id'],
			'recipe_id' => $event['recipe_id'] === null ? null : (int)$event['recipe_id'],
			'state' => $state,
			'reason' => $reason,
			'transaction_id' => $event['transaction_id'],
			'revision' => (int)$event['revision'],
			'occurred_at' => $event['occurred_wire'],
			'lines' => array_map(fn(array $line) => ['product_id' => (int)$line['product_id'], 'amount' => (float)$line['amount'],
				'location_id' => $line['location_id'] === null ? null : (int)$line['location_id'],
				'stock_log_id' => $line['stock_log_id'] === null ? null : (int)$line['stock_log_id']], $lines->fetchAll(\PDO::FETCH_ASSOC)),
		];
	}

	/**
	 * A stored `booked` event reads `stock_log.undone` for its bookings and does not copy it
	 * (ADR-0040 rule 2, ADR-0041 rule 8): all undone is `undone`, some undone is
	 * `needs_review` / `partially_undone`. UndoConsumption() is the next touch that persists it.
	 *
	 * @return array{0: string, 1: string|null}
	 */
	private function DerivedState(array $event): array
	{
		if ($event['state'] !== 'booked' || $event['transaction_id'] === null)
		{
			return [$event['state'], $event['reason']];
		}

		$statement = $this->Db()->prepare('SELECT count(*) AS total, count(*) FILTER (WHERE undone = 0) AS remaining FROM stock_log WHERE transaction_id = ?');
		$statement->execute([$event['transaction_id']]);
		$counts = $statement->fetch(\PDO::FETCH_ASSOC);

		if ((int)$counts['total'] > 0 && (int)$counts['remaining'] === 0)
		{
			return ['undone', null];
		}

		if ((int)$counts['remaining'] > 0 && (int)$counts['remaining'] < (int)$counts['total'])
		{
			return ['needs_review', 'partially_undone'];
		}

		return ['booked', null];
	}

	/** The caller's own events for a recipe, newest first (ADR-0040 rule 2: a share does not expose another user's events). */
	public function ListEvents(int $recipeId, ?int $userId = null): array
	{
		$userId = self::Actor($userId);

		return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			$this->Authorise($recipeId, $userId, null, false);

			$statement = $this->Db()->prepare('SELECT id FROM consumption_events WHERE user_id = ? AND recipe_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 100');
			$statement->execute([$userId, $recipeId]);

			return array_map(fn($id) => $this->EventById((int)$id), $statement->fetchAll(\PDO::FETCH_COLUMN));
		});
	}

	/**
	 * Undoes the stock transaction of one of the caller's own events through the recipe. A stock
	 * holder with STOCK_EDIT can still undo the same bookings through the stock routes (ADR-0040
	 * rule 2); this route is the recipe's own and needs the `undo` right.
	 */
	public function UndoConsumption(int $recipeId, int $eventId, ?int $userId = null): array
	{
		$userId = self::Actor($userId);

		try
		{
			return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $eventId, $userId)
			{
				$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_EDIT);
				$this->Authorise($recipeId, $userId, 'undo', false);

				$statement = $this->Db()->prepare('SELECT * FROM consumption_events WHERE id = ? AND user_id = ? AND recipe_id = ? FOR UPDATE');
				$statement->execute([$eventId, $userId, $recipeId]);
				$event = $statement->fetch(\PDO::FETCH_ASSOC);

				if ($event === false)
				{
					throw new ConsumptionException(404, 'not_found', 'Consumption event does not exist');
				}

				if ($event['state'] !== 'booked')
				{
					throw new ConsumptionException(409, 'invalid_state', 'Only a booked consumption can be undone');
				}

				$remaining = $this->Db()->prepare('SELECT count(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
				$remaining->execute([$event['transaction_id']]);

				if ((int)$remaining->fetchColumn() > 0)
				{
					StockService::GetInstance()->UndoTransaction($event['transaction_id']);
				}

				$this->Db()->prepare("UPDATE consumption_events SET state = 'undone', updated_at = now() WHERE id = ?")->execute([$eventId]);

				return $this->EventById($eventId);
			});
		}
		catch (ConsumptionException | \PDOException $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			throw new ConsumptionException(409, 'undo_refused', $exception->getMessage());
		}
	}

	// --- Shares ------------------------------------------------------------------------------

	/**
	 * The shares the caller may see: all of them for the owner, and for a holder of the `share`
	 * right only those they could change (ADR-0040 action matrix).
	 */
	public function ListShares(int $recipeId, ?int $userId = null): array
	{
		$userId = self::Actor($userId);

		return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[$recipe, $rights] = $this->Authorise($recipeId, $userId, 'share', false);

			$statement = $this->Db()->prepare('SELECT s.*, ' . self::Wire('s.granted_at', 'granted_wire') . ', u.username FROM consumption_recipe_shares s JOIN users u ON u.id = s.user_id WHERE s.recipe_id = ? ORDER BY lower(u.username), s.user_id');
			$statement->execute([$recipeId]);

			$shares = [];
			foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $share)
			{
				if ($rights['owner'] || $this->MayChangeShare($rights, $share))
				{
					$shares[] = ['user_id' => (int)$share['user_id'], 'username' => $share['username'],
						'rights' => ['read' => true, 'consume' => (bool)$share['can_consume'], 'edit' => (bool)$share['can_edit'],
							'undo' => (bool)$share['can_undo'], 'share' => (bool)$share['can_share']],
						'granted_by_user_id' => $share['granted_by_user_id'] === null ? null : (int)$share['granted_by_user_id'],
						'granted_at' => $share['granted_wire']];
				}
			}

			return $shares;
		});
	}

	/** Whether a non-owner holder of the `share` right may change an existing share (ADR-0040 rule 4). */
	private function MayChangeShare(array $actorRights, array $share): bool
	{
		if ((bool)$share['can_share'])
		{
			return false;
		}

		foreach (['consume', 'edit', 'undo'] as $right)
		{
			if ($share['can_' . $right] && !$actorRights[$right])
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Adds or changes a share for a user id, or for a username when `$target` is a string. The recipient need not hold the global permission a right needs:
	 * the share is inert until they do, and refusing here would tell the grantor what the
	 * recipient holds (ADR-0040 rule 3).
	 *
	 * @param array $rights booleans keyed consume, edit, undo, share; read is implied
	 */
	public function SetShare(int $recipeId, int|string $target, array $rights, ?int $userId = null): void
	{
		$userId = self::Actor($userId);
		$wanted = [];
		foreach (self::RIGHTS as $right)
		{
			$wanted[$right] = !empty($rights[$right]);
		}

		DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $target, $wanted, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[$recipe, $actor] = $this->Authorise($recipeId, $userId, 'share', true);
			$targetUserId = is_int($target) ? $target : $this->UserIdByUsername($target);

			$exists = $this->Db()->prepare('SELECT 1 FROM users WHERE id = ?');
			$exists->execute([$targetUserId]);
			if ($exists->fetchColumn() === false || $targetUserId === (int)$recipe['owner_user_id'])
			{
				throw new ConsumptionException(422, 'invalid_user', 'The recipient must be an existing user other than the owner');
			}

			if (!$actor['owner'])
			{
				if ($targetUserId === $userId)
				{
					throw new ConsumptionException(403, 'right_missing', 'You cannot change your own share');
				}

				foreach (self::RIGHTS as $right)
				{
					if ($wanted[$right] && (!$actor[$right] || $right === 'share'))
					{
						throw new ConsumptionException(403, 'right_missing', 'You can grant only rights you hold, and not the share right');
					}
				}

				$current = $this->Db()->prepare('SELECT * FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?');
				$current->execute([$recipeId, $targetUserId]);
				$existing = $current->fetch(\PDO::FETCH_ASSOC);
				if ($existing !== false && !$this->MayChangeShare($actor, $existing))
				{
					throw new ConsumptionException(403, 'right_missing', 'That share holds a right you do not hold');
				}
			}

			$this->Db()->prepare('INSERT INTO consumption_recipe_shares (recipe_id, user_id, can_consume, can_edit, can_undo, can_share, granted_by_user_id)
				VALUES (?, ?, ?, ?, ?, ?, ?)
				ON CONFLICT (recipe_id, user_id) DO UPDATE SET can_consume = EXCLUDED.can_consume, can_edit = EXCLUDED.can_edit,
					can_undo = EXCLUDED.can_undo, can_share = EXCLUDED.can_share, granted_by_user_id = EXCLUDED.granted_by_user_id, granted_at = now()')
				->execute([$recipeId, $targetUserId, (int)$wanted['consume'], (int)$wanted['edit'], (int)$wanted['undo'], (int)$wanted['share'], $userId]);
		});
	}

	/** Removes a share. Anyone may remove their own; the owner or a `share` holder (within rule 4) may remove another's. */
	public function RemoveShare(int $recipeId, int $targetUserId, ?int $userId = null): void
	{
		$userId = self::Actor($userId);

		DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $targetUserId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[, $actor] = $this->Authorise($recipeId, $userId, null, true);

			$current = $this->Db()->prepare('SELECT * FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?');
			$current->execute([$recipeId, $targetUserId]);
			$share = $current->fetch(\PDO::FETCH_ASSOC);

			if ($share === false)
			{
				// Only someone who can see the share list may learn that a share does not exist.
				if (!$actor['share'])
				{
					throw new ConsumptionException(404, 'not_found', 'Consumption recipe does not exist');
				}

				throw new ConsumptionException(404, 'not_found', 'That user holds no share on this consumption recipe');
			}

			if ($targetUserId !== $userId && !$actor['owner'] && (!$actor['share'] || !$this->MayChangeShare($actor, $share)))
			{
				throw new ConsumptionException(403, 'right_missing', 'You cannot remove that share');
			}

			$this->Db()->prepare('DELETE FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?')->execute([$recipeId, $targetUserId]);
		});
	}

	/**
	 * Transfers ownership to a user who already holds a share. In the order the share-owner triggers
	 * require: the new owner's share is deleted, the owner changes, and the previous owner becomes a
	 * share holder with every right, whom the new owner may then revoke.
	 */
	public function TransferOwnership(int $recipeId, int $newOwnerId, ?int $userId = null): void
	{
		$userId = self::Actor($userId);

		DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $newOwnerId, $userId)
		{
			$this->RequireGlobal($userId, User::PERMISSION_STOCK_VIEW);
			[, $actor] = $this->Authorise($recipeId, $userId, null, true);

			if (!$actor['owner'])
			{
				throw new ConsumptionException(403, 'right_missing', 'Only the owner can transfer a consumption recipe');
			}

			$share = $this->Db()->prepare('SELECT 1 FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?');
			$share->execute([$recipeId, $newOwnerId]);
			if ($share->fetchColumn() === false)
			{
				throw new ConsumptionException(422, 'must_hold_share', 'The new owner must already hold a share on the recipe');
			}

			$this->Db()->prepare('DELETE FROM consumption_recipe_shares WHERE recipe_id = ? AND user_id = ?')->execute([$recipeId, $newOwnerId]);
			$this->Db()->prepare('UPDATE consumption_recipes SET owner_user_id = ?, row_updated_timestamp = now() WHERE id = ?')->execute([$newOwnerId, $recipeId]);
			$this->Db()->prepare('INSERT INTO consumption_recipe_shares (recipe_id, user_id, can_consume, can_edit, can_undo, can_share, granted_by_user_id) VALUES (?, ?, 1, 1, 1, 1, ?)')
				->execute([$recipeId, $userId, $newOwnerId]);
		});
	}

	/** Resolves a username for a share. Called only after the caller's `share` right was checked, and answers an unknown name as it answers the owner. */
	private function UserIdByUsername(string $username): int
	{
		$statement = $this->Db()->prepare('SELECT id FROM users WHERE username = ?');
		$statement->execute([$username]);
		$id = $statement->fetchColumn();

		if ($id === false)
		{
			throw new ConsumptionException(422, 'invalid_user', 'The recipient must be an existing user other than the owner');
		}

		return (int)$id;
	}
}
