<?php

namespace Victual\Services;

use Victual\Controllers\Users\User;

/**
 * External consumption events (ADR-0041): an event a client reports for the authenticated user, the
 * states it moves through, and the explicit actions a person takes on it.
 *
 * Identity is (authenticated user, source_system, source_event_id) and nothing else. The user is the
 * caller, never a request field, and the API key that carried the request is recorded for audit only.
 * Every query here is scoped to that user, so another user's event is not found and answers 404.
 *
 * Transaction boundaries (ADR-0041 rule 5). A booking request runs three top-level transactions, never
 * one: (1) the receipt, so the identity and any later failure reason are durable; (2) the booking, which
 * locks event, recipe, then the ascending product set and books every line; (3) only when (2) rolled
 * back, the reason. Transaction 2 never catches a stock refusal and continues, because a refusal thrown
 * before any SQL fails would leave the earlier lines booked. That is why these methods must be called
 * with no transaction open: DatabaseService::InTransaction() would join one, and the separation would
 * be lost. A route must not wrap the call in InRequestTransaction().
 *
 * Stock effects go through StockService (ConsumeProduct, UndoTransaction), which writes the booking as
 * the signed-in user. A skipped, unanswered or scheduled event books nothing and, when no row exists,
 * creates none. An undone event is never rebooked by synchronization; only `rebook` returns it.
 *
 * Wording (ADR-0015): this service records what a person's device reported. It never evaluates, warns
 * or advises, and it has no scheduler.
 */
class ConsumptionEventService extends BaseService
{
	public const SOURCE_MANUAL = 'manual';
	public const BATCH_MAX = 50;
	public const BULK_MAX = 50;

	private const STATUSES = ['taken', 'not_logged', 'skipped', 'unanswered', 'scheduled'];
	private const REMOVAL_REASONS = ['entered_in_error', 'history_cleared', 'medication_archived', 'access_revoked', 'unknown'];
	private const ACTIONS = ['retry', 'rebook', 'approve_unit', 'void', 'keep', 'dismiss', 'link'];
	private const BULK_ACTIONS = ['void', 'keep', 'dismiss', 'retry', 'approve_unit', 'rebook'];
	private const SYSTEM_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/';
	private const TOKEN_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';
	private const RFC3339_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';
	private const MAX_UNIT_LABEL = 64;
	private const LIST_MAX = 500;

	private function Db(): \PDO
	{
		return DatabaseService::GetInstance()->GetDbConnectionRaw();
	}

	private static function Wire(string $column, string $alias): string
	{
		return "to_char($column AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS $alias";
	}

	private static function Setting(string $name, int $default): int
	{
		$constant = 'VICTUAL_' . $name;

		return defined($constant) ? max(1, (int)constant($constant)) : $default;
	}

	private function Mappings(): ConsumptionMappingService
	{
		return ConsumptionMappingService::GetInstance();
	}

	// --- Request validation ------------------------------------------------------------------

	private static function Invalid(string $message): ConsumptionException
	{
		return new ConsumptionException(400, 'invalid_request', $message);
	}

	/** @throws ConsumptionException 400 when the identity is not the documented form, or is the reserved `manual` */
	private static function CheckIdentity(string $sourceSystem, string $sourceEventId): void
	{
		if (preg_match(self::SYSTEM_PATTERN, $sourceSystem) !== 1)
		{
			throw self::Invalid('source_system is 1 to 32 lowercase letters, digits and . _ -, starting with a letter or digit');
		}

		if ($sourceSystem === self::SOURCE_MANUAL)
		{
			throw self::Invalid('The source system "manual" is reserved for consumption recorded in Victual');
		}

		if (preg_match(self::TOKEN_PATTERN, $sourceEventId) !== 1)
		{
			throw self::Invalid('source_event_id is 1 to 128 characters of letters, digits and . _ : -');
		}
	}

	private static function ParseTime($value, string $field): \DateTimeImmutable
	{
		if (!is_string($value) || preg_match(self::RFC3339_PATTERN, $value) !== 1)
		{
			throw self::Invalid($field . ' must be an RFC 3339 time with an offset');
		}

		try
		{
			return new \DateTimeImmutable($value);
		}
		catch (\Exception)
		{
			throw self::Invalid($field . ' must be an RFC 3339 time with an offset');
		}
	}

	private static function Utc(\DateTimeImmutable $time): string
	{
		return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
	}

	/**
	 * The request as the service uses it. The payload hash covers status, medication_ref, quantity,
	 * unit_label, occurred_at and location_id, and excludes source_updated_at (ADR-0041 rule 5).
	 *
	 * @return array<string, mixed>
	 */
	private function ParsePayload(array $body): array
	{
		$status = $body['status'] ?? null;
		if (!is_string($status) || !in_array($status, self::STATUSES, true))
		{
			throw self::Invalid('status is one of ' . implode(', ', self::STATUSES));
		}

		$ref = $body['medication_ref'] ?? null;
		if ($ref !== null && (!is_string($ref) || preg_match(self::TOKEN_PATTERN, $ref) !== 1))
		{
			throw self::Invalid('medication_ref is 1 to 128 characters of letters, digits and . _ : -');
		}

		$quantity = $body['quantity'] ?? null;
		if ($quantity !== null && (!(is_int($quantity) || is_float($quantity)) || !is_finite((float)$quantity) || $quantity <= 0))
		{
			throw self::Invalid('quantity is a number greater than zero');
		}

		$unit = $body['unit_label'] ?? null;
		if ($unit !== null && (!is_string($unit) || $unit === '' || mb_strlen($unit) > self::MAX_UNIT_LABEL))
		{
			throw self::Invalid('unit_label is 1 to ' . self::MAX_UNIT_LABEL . ' characters');
		}

		if ($quantity !== null && $unit === null)
		{
			throw self::Invalid('A quantity needs a unit_label');
		}

		$location = $body['location_id'] ?? null;
		if ($location !== null && (!is_int($location) || $location <= 0))
		{
			throw self::Invalid('location_id is a positive integer');
		}

		$replaces = $body['replaces'] ?? null;
		if ($replaces !== null && (!is_string($replaces) || preg_match(self::TOKEN_PATTERN, $replaces) !== 1))
		{
			throw self::Invalid('replaces is a source_event_id');
		}

		$occurred = null;
		$occurredNormalized = null;
		if (isset($body['occurred_at']))
		{
			$occurred = self::ParseTime($body['occurred_at'], 'occurred_at');
			$occurredNormalized = $occurred->format('Y-m-d\TH:i:s.uP');
		}

		if ($status === 'taken')
		{
			if ($ref === null || $occurred === null)
			{
				throw self::Invalid('A taken event needs medication_ref and occurred_at');
			}

			if ($occurred->getTimestamp() > time() + 300)
			{
				throw new ConsumptionException(422, 'future_occurred_at', 'occurred_at cannot be more than five minutes in the future');
			}
		}

		$version = isset($body['source_updated_at']) ? self::ParseTime($body['source_updated_at'], 'source_updated_at') : null;

		return [
			'status' => $status,
			'medication_ref' => $ref,
			'quantity' => $quantity === null ? null : (float)$quantity,
			'unit_label' => $unit,
			'location_id' => $location,
			'replaces' => $replaces,
			'occurred_at' => $occurred === null ? null : self::Utc($occurred),
			'occurred_date' => $occurred === null ? null : $occurred->format('Y-m-d'),
			'source_updated_at' => $version === null ? null : self::Utc($version),
			'hash' => hash('sha256', json_encode([$status, $ref, $quantity === null ? null : sprintf('%.12g', (float)$quantity), $unit, $occurredNormalized, $location], JSON_THROW_ON_ERROR)),
		];
	}

	// --- Rows and presentation ---------------------------------------------------------------

	private const ROW_SELECT = 'SELECT *, __OCCURRED__, __UPDATED__, __REMOVED__, __VOIDED__, to_char(occurred_date, \'YYYY-MM-DD\') AS occurred_date_wire FROM consumption_events';

	private static function RowSelect(): string
	{
		return strtr(self::ROW_SELECT, [
			'__OCCURRED__' => self::Wire('occurred_at', 'occurred_wire'),
			'__UPDATED__' => self::Wire('source_updated_at', 'version_wire'),
			'__REMOVED__' => self::Wire('source_removed_at', 'removed_wire'),
			'__VOIDED__' => self::Wire('voided_at', 'voided_wire'),
		]);
	}

	private function RowById(int $eventId, bool $forUpdate = false): ?array
	{
		$statement = $this->Db()->prepare(self::RowSelect() . ' WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
		$statement->execute([$eventId]);

		return $statement->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	private function RowByIdentity(int $userId, string $sourceSystem, string $sourceEventId): ?array
	{
		$statement = $this->Db()->prepare(self::RowSelect() . ' WHERE user_id = ? AND source_system = ? AND source_event_id = ?');
		$statement->execute([$userId, $sourceSystem, $sourceEventId]);

		return $statement->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	/** @throws ConsumptionException 404 when the user has no such event */
	private function RequireRow(int $userId, string $sourceSystem, string $sourceEventId): array
	{
		$row = $this->RowByIdentity($userId, $sourceSystem, $sourceEventId);
		if ($row === null)
		{
			throw new ConsumptionException(404, 'not_found', 'Consumption event does not exist');
		}

		return $row;
	}

	private function LiveBookingCount(?string $transactionId): int
	{
		if ($transactionId === null)
		{
			return 0;
		}

		$statement = $this->Db()->prepare('SELECT count(*) FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$statement->execute([$transactionId]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * The state a person sees. A stored `booked` (or `partially_undone`) event reads stock_log.undone for
	 * its bookings and does not copy it: all undone is `undone`, some undone is `needs_review` /
	 * `partially_undone` (ADR-0041 rule 8).
	 *
	 * @return array{0: string, 1: ?string}
	 */
	private function EffectiveState(array $row): array
	{
		$derives = $row['state'] === 'booked' || ($row['state'] === 'needs_review' && $row['reason'] === 'partially_undone');
		if (!$derives || $row['transaction_id'] === null)
		{
			return [$row['state'], $row['reason']];
		}

		$statement = $this->Db()->prepare('SELECT count(*) AS total, count(*) FILTER (WHERE undone = 0) AS remaining FROM stock_log WHERE transaction_id = ?');
		$statement->execute([$row['transaction_id']]);
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

	/** Writes the derived state on the next touch, so the stored row catches up with a direct stock undo. */
	private function PersistDerived(array $row): array
	{
		[$state, $reason] = $this->EffectiveState($row);

		if ($state === $row['state'] && $reason === $row['reason'])
		{
			return $row;
		}

		$this->Db()->prepare('UPDATE consumption_events SET state = ?, reason = ?, updated_at = now() WHERE id = ?')->execute([$state, $reason, $row['id']]);

		return $this->RowById((int)$row['id']);
	}

	/**
	 * The event as the API returns it.
	 *
	 * @param array<string, mixed> $extras fields that describe this response rather than the row
	 */
	private function Present(array $row, array $extras = []): array
	{
		[$state, $reason] = $this->EffectiveState($row);
		$lines = $this->Db()->prepare("SELECT product_id, amount, location_id, to_char(used_date, 'YYYY-MM-DD') AS used_date FROM consumption_event_lines WHERE event_id = ? ORDER BY id");
		$lines->execute([$row['id']]);

		$event = [
			'source_system' => $row['source_system'],
			'source_event_id' => $row['source_event_id'],
			'state' => $state,
			'reason' => $reason,
			'revision' => (int)$row['revision'],
			'transaction_id' => $state === 'linked' ? $row['linked_transaction_id'] : $row['transaction_id'],
			'occurred_at' => $row['occurred_wire'],
			'lines' => array_map(function (array $line)
			{
				$booked = ['product_id' => (int)$line['product_id'], 'amount' => (float)$line['amount']];
				if ($line['location_id'] !== null)
				{
					$booked['location_id'] = (int)$line['location_id'];
				}
				if ($line['used_date'] !== null)
				{
					$booked['used_date'] = $line['used_date'];
				}

				return $booked;
			}, $lines->fetchAll(\PDO::FETCH_ASSOC)),
		];

		if ($row['recipe_id'] !== null)
		{
			$event['recipe_id'] = (int)$row['recipe_id'];
		}

		if ($row['medication_ref'] !== null)
		{
			$event['medication_ref'] = $row['medication_ref'];
		}

		if ($row['version_wire'] !== null)
		{
			$event['source_updated_at'] = $row['version_wire'];
		}

		if ($row['removed_wire'] !== null)
		{
			$event['source_removed_at'] = $row['removed_wire'];
			$event['source_removed_reason'] = $row['source_removed_reason'];
		}

		if ($reason === 'ambiguous_location' && $row['candidate_location_ids'] !== null)
		{
			$event['candidate_location_ids'] = array_map('intval', $this->ParseIntArray($row['candidate_location_ids']));
		}

		if ($reason === 'unit_unconfirmed')
		{
			$event['unit_label_seen'] = $row['unit_label'];
		}

		if ($reason === 'stock_error' && $row['stock_error_message'] !== null)
		{
			$event['message'] = $row['stock_error_message'];
		}

		if (($state === 'booked' || $state === 'linked') && $row['source_system'] !== self::SOURCE_MANUAL)
		{
			$event['possible_duplicates'] = $this->PossibleDuplicates($row);
		}

		return $extras + $event;
	}

	/** @return string[] */
	private function ParseIntArray(string $literal): array
	{
		$inner = trim($literal, '{}');

		return $inner === '' ? [] : explode(',', $inner);
	}

	private static function IntArrayLiteral(array $ids): string
	{
		return '{' . implode(',', array_map('intval', $ids)) . '}';
	}

	/**
	 * Manual or direct stock consumptions by the same user, near this event in time, on a product it
	 * booked, that no event is linked to. A suggestion only: nothing here links, holds or books
	 * (ADR-0041 rule 9 and open question 1).
	 *
	 * @return array<int, array{transaction_id: string, occurred_at: string}>
	 */
	private function PossibleDuplicates(array $row): array
	{
		$own = $row['state'] === 'linked' ? $row['linked_transaction_id'] : $row['transaction_id'];
		$products = $this->Db()->prepare('SELECT DISTINCT product_id FROM consumption_event_lines WHERE event_id = ?');
		$products->execute([$row['id']]);
		$productIds = array_map('intval', $products->fetchAll(\PDO::FETCH_COLUMN));

		if (count($productIds) === 0)
		{
			return [];
		}

		$window = self::Setting('CONSUMPTION_DUPLICATE_WINDOW_MINUTES', 30) * 60;
		$marks = implode(',', array_fill(0, count($productIds), '?'));
		$sql = "SELECT s.transaction_id, to_char(COALESCE(m.occurred_at, MIN(s.row_created_timestamp::timestamptz)) AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS at
			FROM stock_log s
			LEFT JOIN consumption_events m ON m.transaction_id = s.transaction_id
			WHERE s.user_id = ? AND s.transaction_type = 'consume' AND s.undone = 0 AND s.product_id IN ($marks)
				AND (m.id IS NULL OR (m.user_id = ? AND m.source_system = 'manual'))
				AND NOT EXISTS (SELECT 1 FROM consumption_events x WHERE x.linked_transaction_id = s.transaction_id)
				AND s.transaction_id IS NOT NULL AND s.transaction_id <> ?
			GROUP BY s.transaction_id, m.occurred_at
			HAVING abs(extract(epoch FROM (COALESCE(m.occurred_at, MIN(s.row_created_timestamp::timestamptz)) - ?::timestamptz))) <= ?
			ORDER BY at, s.transaction_id";
		$statement = $this->Db()->prepare($sql);
		$statement->execute(array_merge([$row['user_id']], $productIds, [$row['user_id'], $own ?? '', $row['occurred_wire'], $window]));

		return array_map(fn(array $found) => ['transaction_id' => $found['transaction_id'], 'occurred_at' => $found['at']], $statement->fetchAll(\PDO::FETCH_ASSOC));
	}

	// --- Reads -------------------------------------------------------------------------------

	public function Get(int $userId, string $sourceSystem, string $sourceEventId): array
	{
		$this->RequireStockView($userId);

		return $this->Present($this->RequireRow($userId, $sourceSystem, $sourceEventId));
	}

	/**
	 * The caller's own events, newest first.
	 *
	 * @param string[] $states
	 */
	public function ListEvents(int $userId, array $states = [], ?string $since = null, int $limit = 100): array
	{
		$this->RequireStockView($userId);
		$limit = max(1, min(self::LIST_MAX, $limit));
		$known = ['received', 'needs_mapping', 'needs_review', 'booked', 'undone', 'voided', 'linked', 'dismissed'];

		foreach ($states as $state)
		{
			if (!in_array($state, $known, true))
			{
				throw self::Invalid('Unknown state: ' . $state);
			}
		}

		$sql = self::RowSelect() . ' WHERE user_id = ?';
		$parameters = [$userId];

		if (count($states) > 0)
		{
			// `partially_undone` is a reason of needs_review, and `undone` can be derived, so both are matched
			// after the derived state is known; the stored filter only narrows the candidates.
			$sql .= ' AND state IN (' . implode(',', array_fill(0, count($states) + 2, '?')) . ')';
			$parameters = array_merge($parameters, $states, ['booked', 'needs_review']);
		}

		if ($since !== null)
		{
			$sql .= ' AND updated_at >= ?::timestamptz';
			$parameters[] = self::Utc(self::ParseTime($since, 'since'));
		}

		$statement = $this->Db()->prepare($sql . ' ORDER BY updated_at DESC, id DESC LIMIT ' . (int)($limit * 2 + 50));
		$statement->execute($parameters);
		$events = [];

		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			[$state] = $this->EffectiveState($row);

			if (count($states) === 0 || in_array($state, $states, true))
			{
				$events[] = $this->Present($row);
			}

			if (count($events) >= $limit)
			{
				break;
			}
		}

		return $events;
	}

	private function RequireStockView(int $userId): void
	{
		$statement = $this->Db()->prepare('SELECT 1 FROM user_permissions_resolved WHERE user_id = ? AND permission_name = ?');
		$statement->execute([$userId, User::PERMISSION_STOCK_VIEW]);

		if ($statement->fetchColumn() === false)
		{
			throw new ConsumptionException(403, 'permission_missing', 'Permission missing: ' . User::PERMISSION_STOCK_VIEW);
		}
	}

	private function RequireConsume(int $userId): void
	{
		$statement = $this->Db()->prepare('SELECT 1 FROM user_permissions_resolved WHERE user_id = ? AND permission_name = ?');

		foreach ([User::PERMISSION_STOCK_VIEW, User::PERMISSION_STOCK_CONSUME] as $permission)
		{
			$statement->execute([$userId, $permission]);
			if ($statement->fetchColumn() === false)
			{
				throw new ConsumptionException(403, 'permission_missing', 'Permission missing: ' . $permission);
			}
		}
	}

	// --- Submission --------------------------------------------------------------------------

	/**
	 * PUT /consumption/events/{source_system}/{source_event_id}.
	 *
	 * @param int|null $apiKeyId the key that carried the request, recorded for audit only
	 * @return array{event: array, created: bool}
	 */
	public function Submit(int $userId, string $sourceSystem, string $sourceEventId, array $body, ?int $apiKeyId = null): array
	{
		$this->RequireOutsideTransaction();
		self::CheckIdentity($sourceSystem, $sourceEventId);
		$this->RequireConsume($userId);
		$payload = $this->ParsePayload($body);

		if ($payload['replaces'] === $sourceEventId)
		{
			throw self::Invalid('An event cannot replace itself');
		}

		$database = DatabaseService::GetInstance();

		// Transaction 1: the receipt. Committed before any booking, so the identity is durable.
		[$eventId, $created] = $database->InTransaction(fn() => $this->Receive($userId, $sourceSystem, $sourceEventId, $payload, $apiKeyId));

		if ($eventId === null)
		{
			// A skipped, unanswered, scheduled or not-logged status with no existing row: nothing was taken, so
			// no row exists and none is created (ADR-0041 rule 3).
			return ['event' => ['source_system' => $sourceSystem, 'source_event_id' => $sourceEventId, 'state' => 'no_consumption', 'reason' => null], 'created' => false];
		}

		$event = $this->RunBooking($userId, $eventId, $payload, fn() => $this->Apply($userId, $eventId, $payload, $created));

		return ['event' => $event, 'created' => $created];
	}

	private function RequireOutsideTransaction(): void
	{
		if ($this->Db()->inTransaction())
		{
			throw new \LogicException('ConsumptionEventService must be called with no transaction open: its receipt, booking and failure record are separate transactions (ADR-0041 rule 5)');
		}
	}

	/** @return array{0: ?int, 1: bool} the event id, and whether this request created the row */
	private function Receive(int $userId, string $sourceSystem, string $sourceEventId, array $payload, ?int $apiKeyId): array
	{
		if ($payload['status'] !== 'taken')
		{
			$statement = $this->Db()->prepare('SELECT id FROM consumption_events WHERE user_id = ? AND source_system = ? AND source_event_id = ?');
			$statement->execute([$userId, $sourceSystem, $sourceEventId]);
			$id = $statement->fetchColumn();

			return [$id === false ? null : (int)$id, false];
		}

		$statement = $this->Db()->prepare("INSERT INTO consumption_events
			(user_id, source_system, source_event_id, state, medication_ref, submitted_status, quantity, unit_label, requested_location_id, occurred_at, occurred_date, source_updated_at, payload_hash, api_key_id)
			VALUES (?, ?, ?, 'received', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (user_id, source_system, source_event_id) DO NOTHING RETURNING id");
		$statement->execute([$userId, $sourceSystem, $sourceEventId, $payload['medication_ref'], $payload['status'], $payload['quantity'], $payload['unit_label'],
			$this->ExistingLocationOrNull($payload['location_id']), $payload['occurred_at'], $payload['occurred_date'], $payload['source_updated_at'], $payload['hash'], $apiKeyId]);
		$id = $statement->fetchColumn();

		if ($id !== false)
		{
			return [(int)$id, true];
		}

		$statement = $this->Db()->prepare('SELECT id FROM consumption_events WHERE user_id = ? AND source_system = ? AND source_event_id = ?');
		$statement->execute([$userId, $sourceSystem, $sourceEventId]);

		return [(int)$statement->fetchColumn(), false];
	}

	private function ExistingLocationOrNull(?int $locationId): ?int
	{
		if ($locationId === null)
		{
			return null;
		}

		$statement = $this->Db()->prepare('SELECT 1 FROM locations WHERE id = ?');
		$statement->execute([$locationId]);

		return $statement->fetchColumn() === false ? null : $locationId;
	}

	/**
	 * Transaction 2, and transaction 3 when it rolled back. $work runs in one top-level transaction; a
	 * refusal escapes it so everything it wrote is undone, and the reason is then written on its own.
	 *
	 * @param array<string, mixed>|null $payload the request fields to store with a refusal, or null
	 */
	private function RunBooking(int $userId, int $eventId, ?array $payload, callable $work): array
	{
		$database = DatabaseService::GetInstance();

		try
		{
			return $database->InTransaction($work);
		}
		catch (ConsumptionException | \PDOException $exception)
		{
			throw $exception;
		}
		catch (EventRefusal $refusal)
		{
		}
		catch (InsufficientStockException)
		{
			$refusal = new EventRefusal('insufficient_stock');
		}
		catch (\Exception $exception)
		{
			// ConsumeProduct() refuses nine causes with a plain \Exception and no code. Those that can be
			// told in advance (insufficient stock, ambiguous location) are decided before it is called;
			// whatever is left is private to this person and recorded as a stock error.
			$refusal = new EventRefusal('stock_error', [], $exception->getMessage());
		}

		return $database->InTransaction(fn() => $this->RecordRefusal($userId, $eventId, $payload, $refusal));
	}

	private function RecordRefusal(int $userId, int $eventId, ?array $payload, EventRefusal $refusal): array
	{
		$row = $this->RowById($eventId, true);
		if ($row === null || (int)$row['user_id'] !== $userId)
		{
			throw new ConsumptionException(404, 'not_found', 'Consumption event does not exist');
		}

		if ($payload !== null)
		{
			$this->StorePayload($eventId, $payload, null);
		}

		$candidates = isset($refusal->context['candidate_location_ids']) ? self::IntArrayLiteral($refusal->context['candidate_location_ids']) : null;
		$this->Db()->prepare("UPDATE consumption_events SET state = 'needs_review', reason = ?, candidate_location_ids = ?::integer[], stock_error_message = ?, updated_at = now() WHERE id = ?")
			->execute([$refusal->reason, $candidates, $refusal->reason === 'stock_error' ? $refusal->privateMessage : null, $eventId]);

		$extras = [];
		if ($payload !== null && $payload['replaces'] !== null)
		{
			// The old event was not touched: the replacement rolled back with the booking it could not make.
			$extras['replaces'] = ['source_event_id' => $payload['replaces'], 'state' => 'needs_review'];
		}

		return $this->Present($this->RowById($eventId), $extras);
	}

	/**
	 * Stores a request's fields on the row. A `source_updated_at` the request did not carry keeps the
	 * stored one: the version is the source's observation time, and an absent value says nothing new.
	 */
	private function StorePayload(int $eventId, array $payload, ?string $replacesEventId): void
	{
		$this->Db()->prepare("UPDATE consumption_events SET medication_ref = ?, submitted_status = ?, quantity = ?, unit_label = ?, requested_location_id = ?,
				occurred_at = COALESCE(?::timestamptz, occurred_at), occurred_date = COALESCE(?::date, occurred_date),
				source_updated_at = COALESCE(?::timestamptz, source_updated_at), payload_hash = ?, updated_at = now() WHERE id = ?")
			->execute([$payload['medication_ref'], $payload['status'], $payload['quantity'], $payload['unit_label'], $this->ExistingLocationOrNull($payload['location_id']),
				$payload['occurred_at'], $payload['occurred_date'], $payload['source_updated_at'], $payload['hash'], $eventId]);
	}

	/**
	 * The body of transaction 2 for a PUT: decides what the request means for the stored row, then does it.
	 *
	 * @return array the event
	 */
	private function Apply(int $userId, int $eventId, array $payload, bool $created): array
	{
		$replaced = null;
		$ids = [$eventId];

		if ($payload['replaces'] !== null)
		{
			$statement = $this->Db()->prepare('SELECT id FROM consumption_events WHERE user_id = ? AND source_system = (SELECT source_system FROM consumption_events WHERE id = ?) AND source_event_id = ?');
			$statement->execute([$userId, $eventId, $payload['replaces']]);
			$oldId = $statement->fetchColumn();

			if ($oldId !== false && (int)$oldId !== $eventId)
			{
				$ids[] = (int)$oldId;
			}
		}

		sort($ids);

		// Both event rows are locked in ascending id order so that two replacements that name each other's
		// events cannot deadlock.
		$locked = [];
		foreach ($ids as $id)
		{
			$locked[$id] = $this->RowById($id, true);
		}

		$row = $locked[$eventId];
		unset($locked[$eventId]);
		$replaced = count($locked) === 1 ? array_values($locked)[0] : null;

		$row = $this->PersistDerived($row);
		$sameHash = $row['payload_hash'] !== null && hash_equals($row['payload_hash'], $payload['hash']);

		if ($created)
		{
			// The request that inserted the row. A concurrent identical request may have taken the lock first and
			// booked it already; booking again here would undo that booking and make another, so only a row still
			// `received` is booked.
			if ($row['state'] !== 'received')
			{
				return $this->Present($row, ['replayed' => false]);
			}

			return $this->Present($this->Dispatch($row, $payload, $replaced, 'first'), ['replayed' => false] + $this->ReplacesExtra($replaced, $payload));
		}

		if ($sameHash)
		{
			// Same payload, whatever its version: a replay (ADR-0041 rule 5). Two states attempt the booking again.
			$retry = $row['state'] === 'received' || ($row['state'] === 'needs_review' && $row['reason'] === 'insufficient_stock');

			if ($retry && $payload['status'] === 'taken')
			{
				return $this->Present($this->Dispatch($row, $payload, $replaced, 'retry'), ['replayed' => true] + $this->ReplacesExtra($replaced, $payload));
			}

			return $this->Present($this->RowById((int)$row['id']), ['replayed' => true]);
		}

		// A different payload. Versions decide whether it is stale, a conflict, or a correction.
		$stored = $row['version_wire'];
		$incoming = $payload['source_updated_at'];

		if ($stored !== null && $incoming !== null)
		{
			$order = strcmp($incoming, $stored);

			if ($order < 0)
			{
				return $this->Present($row, ['stale' => true]);
			}

			if ($order === 0)
			{
				throw new ConsumptionException(409, 'same_version_different_payload', 'A different payload arrived with the same source_updated_at');
			}
		}

		if ($row['state'] === 'voided' && $row['voided_wire'] !== null && $incoming !== null && strcmp($incoming, $row['voided_wire']) <= 0)
		{
			// A PUT whose version is not after the void is stale and changes nothing (ADR-0041 rule 7).
			return $this->Present($row, ['stale' => true]);
		}

		return $this->Present($this->Dispatch($row, $payload, $replaced, 'correction'), $this->ReplacesExtra($replaced, $payload));
	}

	private function ReplacesExtra(?array $replaced, array $payload): array
	{
		if ($payload['replaces'] === null || $replaced === null)
		{
			return [];
		}

		$fresh = $this->RowById((int)$replaced['id']);

		return ['replaces' => ['source_event_id' => $payload['replaces'], 'state' => $fresh !== null && $fresh['state'] === 'voided' ? 'voided' : 'needs_review']];
	}

	/**
	 * What a stored row does with a request, by the row's state.
	 *
	 * @param string $kind `first` (new row), `retry` (same payload, booking attempted again) or `correction` (changed payload)
	 */
	private function Dispatch(array $row, array $payload, ?array $replaced, string $kind): array
	{
		$eventId = (int)$row['id'];
		[$state, $reason] = $this->EffectiveState($row);
		$live = $this->LiveBookingCount($row['transaction_id']) > 0 && $state !== 'linked';

		if ($payload['status'] !== 'taken')
		{
			// not_logged, skipped, unanswered and scheduled on an existing row are a deletion with the
			// reason entered_in_error (ADR-0041 rule 3). Such a request may omit everything but the status, so
			// it records the status and the version only: replacing medication_ref, quantity or unit_label with
			// nothing would hide the event from a bulk filter and leave a later retry with nothing to book.
			$this->Db()->prepare('UPDATE consumption_events SET submitted_status = ?, source_updated_at = COALESCE(?::timestamptz, source_updated_at), payload_hash = ?, updated_at = now() WHERE id = ?')
				->execute([$payload['status'], $payload['source_updated_at'], $payload['hash'], $eventId]);

			return $this->ApplyRemoval($this->RowById($eventId, true), 'entered_in_error');
		}

		if ($kind === 'first' || $kind === 'retry')
		{
			return $this->BookOrRebook($row, $payload, $replaced);
		}

		if ($state === 'undone' || ($state === 'needs_review' && $reason === 'partially_undone'))
		{
			// A person undid the booking in stock: synchronization never rebooks it, whatever the source now says.
			$this->StorePayload($eventId, $payload, null);
			$this->Db()->prepare("UPDATE consumption_events SET state = 'needs_review', reason = 'changed_after_undo', updated_at = now() WHERE id = ?")->execute([$eventId]);

			return $this->RowById($eventId);
		}

		switch ($state)
		{
			case 'booked':
				if ($live && !$this->IsMaterialChange($row, $payload))
				{
					// Only the time of day changed: the bookings stand (ADR-0041 rule 6).
					$this->StorePayload($eventId, $payload, null);

					return $this->RowById($eventId);
				}

				return $this->BookOrRebook($row, $payload, $replaced);

			case 'linked':
			case 'dismissed':
				// The booking is the person's (linked) or the event was excluded (dismissed): the new payload is
				// kept for the record and nothing is booked.
				$this->StorePayload($eventId, $payload, null);

				return $this->RowById($eventId);

			case 'voided':
				// A later version after the void is a new event (ADR-0041 rule 7).
				$this->StorePayload($eventId, $payload, null);
				$this->Db()->prepare("UPDATE consumption_events SET state = 'received', reason = NULL, revision = revision + 1 WHERE id = ?")->execute([$eventId]);

				return $this->BookOrRebook($this->RowById($eventId), $payload, $replaced);

			default:
				// received, needs_mapping, needs_review: stored, and the booking is attempted again.
				$this->StorePayload($eventId, $payload, null);

				return $this->BookOrRebook($this->RowById($eventId), $payload, $replaced);
		}
	}

	/** Quantity, unit, medication, requested location or the calendar date of occurred_at changed. */
	private function IsMaterialChange(array $row, array $payload): bool
	{
		$quantity = $row['quantity'] === null ? null : (float)$row['quantity'];
		$location = $row['requested_location_id'] === null ? null : (int)$row['requested_location_id'];

		return $row['medication_ref'] !== $payload['medication_ref']
			|| $quantity !== $payload['quantity']
			|| $row['unit_label'] !== $payload['unit_label']
			|| $location !== $payload['location_id']
			|| $row['occurred_date_wire'] !== $payload['occurred_date'];
	}

	// --- Booking -----------------------------------------------------------------------------

	/**
	 * Books the event through its mapping, undoing a booking it already holds in the same transaction
	 * (a correction, or a retry of a failed one). Lock order: event (held), recipe, then the ascending
	 * union of the products of the old and new bookings (ADR-0041 rule 6 and the evidence for it).
	 *
	 * A refusal throws EventRefusal and the caller's transaction rolls back, so the original booking is
	 * still there when a correction fails.
	 */
	private function BookOrRebook(array $row, array $payload, ?array $replaced): array
	{
		$userId = (int)$row['user_id'];
		$eventId = (int)$row['id'];
		$oldTransaction = $this->LiveBookingCount($row['transaction_id']) > 0 ? $row['transaction_id'] : null;
		$replacedTransaction = $replaced !== null && $this->LiveBookingCount($replaced['transaction_id']) > 0 && $replaced['state'] !== 'linked' ? $replaced['transaction_id'] : null;

		$mapping = $this->Mappings()->FindForUser($userId, $row['source_system'], (string)$payload['medication_ref']);

		if ($mapping === null)
		{
			if ($oldTransaction !== null)
			{
				throw new EventRefusal('invalid_mapping', [], null, 'The mapping for this medication no longer exists');
			}

			$this->Db()->prepare("UPDATE consumption_events SET state = 'needs_mapping', reason = NULL, mapping_id = NULL, candidate_location_ids = NULL, stock_error_message = NULL, updated_at = now() WHERE id = ?")->execute([$eventId]);

			return $this->RowById($eventId);
		}

		$occurred = new \DateTimeImmutable($payload['occurred_at']);
		$effectiveFrom = new \DateTimeImmutable($mapping['effective_from']);

		if ($occurred < $effectiveFrom)
		{
			$this->LockAndUndo($oldTransaction, []);
			$this->Db()->prepare("UPDATE consumption_events SET state = 'dismissed', reason = NULL, mapping_id = ?, updated_at = now() WHERE id = ?")->execute([$mapping['id'], $eventId]);

			return $this->RowById($eventId);
		}

		$planned = $this->PlanLines($mapping, $payload, $userId);

		$products = array_column($planned, 0);
		foreach ([$oldTransaction, $replacedTransaction] as $transaction)
		{
			if ($transaction !== null)
			{
				$products = array_merge($products, $this->ProductsOfTransaction($transaction));
			}
		}
		DatabaseService::GetInstance()->LockProductsStock(array_values(array_unique($products)));

		$this->LockAndUndo($oldTransaction, []);
		$this->LockAndUndo($replacedTransaction, []);

		$locations = $this->ResolveLocations($mapping, $payload, $planned);

		$needed = [];
		foreach ($planned as [$productId, $amount])
		{
			$needed[$productId] = ($needed[$productId] ?? 0.0) + $amount;
		}
		foreach ($needed as $productId => $amount)
		{
			StockService::GetInstance()->AssertScopedStockAvailable($productId, $amount, $locations[$productId]);
		}

		$transactionId = null;
		foreach ($planned as [$productId, $amount])
		{
			StockService::GetInstance()->ConsumeProduct($productId, $amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, $locations[$productId], $transactionId, false, false, $payload['occurred_date']);
		}

		$this->Db()->prepare('DELETE FROM consumption_event_lines WHERE event_id = ?')->execute([$eventId]);
		$bookings = $this->Db()->prepare('SELECT id, product_id, amount, location_id FROM stock_log WHERE transaction_id = ? AND undone = 0 ORDER BY id');
		$bookings->execute([$transactionId]);
		$insert = $this->Db()->prepare('INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id, stock_log_id, used_date) VALUES (?, ?, ?, ?, ?, ?)');

		foreach ($bookings->fetchAll(\PDO::FETCH_ASSOC) as $booking)
		{
			$insert->execute([$eventId, $booking['product_id'], abs((float)$booking['amount']), $booking['location_id'], $booking['id'], $payload['occurred_date']]);
		}

		$rebooked = $row['transaction_id'] !== null ? 1 : 0;
		$this->Db()->prepare("UPDATE consumption_events SET state = 'booked', reason = NULL, transaction_id = ?, mapping_id = ?, recipe_id = ?, revision = revision + ?,
				candidate_location_ids = NULL, stock_error_message = NULL, replaces_event_id = COALESCE(?, replaces_event_id), updated_at = now() WHERE id = ?")
			->execute([$transactionId, $mapping['id'], $mapping['target_type'] === 'recipe' ? $mapping['recipe_id'] : null, $rebooked, $replaced === null ? null : $replaced['id'], $eventId]);

		if ($replaced !== null && !in_array($replaced['state'], ['linked', 'dismissed'], true))
		{
			$this->Db()->prepare("UPDATE consumption_events SET state = 'voided', reason = NULL, voided_at = now(), updated_at = now() WHERE id = ?")->execute([$replaced['id']]);
		}

		return $this->RowById($eventId);
	}

	/** @return int[] */
	private function ProductsOfTransaction(string $transactionId): array
	{
		$statement = $this->Db()->prepare('SELECT DISTINCT product_id FROM stock_log WHERE transaction_id = ? AND undone = 0');
		$statement->execute([$transactionId]);

		return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * Undoes every live booking of a transaction under the product locks the caller already holds. The
	 * bookings are read again after the lock, so a concurrent direct undo cannot make this one fail with
	 * "already undone"; a refusal that remains (a later booking depends on the same lot) is `undo_refused`.
	 *
	 * @throws EventRefusal
	 */
	private function LockAndUndo(?string $transactionId, array $unused): void
	{
		if ($transactionId === null)
		{
			return;
		}

		$products = $this->ProductsOfTransaction($transactionId);
		if (count($products) === 0)
		{
			return;
		}

		DatabaseService::GetInstance()->LockProductsStock($products);

		if ($this->LiveBookingCount($transactionId) === 0)
		{
			return;
		}

		try
		{
			StockService::GetInstance()->UndoTransaction($transactionId);
		}
		catch (\Exception $exception)
		{
			throw new EventRefusal('undo_refused', [], null, $exception->getMessage());
		}
	}

	/**
	 * The lines the mapping books for this event, in the product stock unit.
	 *
	 * @return array<int, array{0: int, 1: float}>
	 * @throws EventRefusal
	 */
	private function PlanLines(array $mapping, array $payload, int $userId): array
	{
		$label = $payload['unit_label'];

		if ($label !== null && !in_array($label, $mapping['unit_labels'], true))
		{
			throw new EventRefusal('unit_unconfirmed');
		}

		if ($mapping['target_type'] === 'recipe')
		{
			if ($mapping['recipe_id'] === null)
			{
				throw new EventRefusal('recipe_unavailable');
			}

			try
			{
				$lines = ConsumptionRecipeService::GetInstance()->LinesForExternalConsumption((int)$mapping['recipe_id'], $userId);
			}
			catch (ConsumptionException $exception)
			{
				if ($exception->status === 403)
				{
					throw $exception;
				}

				throw new EventRefusal('invalid_mapping', [], null, $exception->getMessage());
			}

			if ($lines === null)
			{
				throw new EventRefusal('recipe_unavailable');
			}

			if (count($lines) === 0)
			{
				throw new EventRefusal('invalid_mapping', [], null, 'The recipe has no lines');
			}

			return $lines;
		}

		$quantity = $payload['quantity'] ?? ($mapping['default_quantity'] === null ? null : (float)$mapping['default_quantity']);
		if ($quantity === null)
		{
			throw new EventRefusal('quantity_missing');
		}

		try
		{
			return [[(int)$mapping['product_id'], $this->Mappings()->StockAmountFor($mapping, $quantity)]];
		}
		catch (ConsumptionException $exception)
		{
			throw new EventRefusal('invalid_mapping', [], null, $exception->getMessage());
		}
	}

	/**
	 * The location each product is taken from. `fixed` is the exact location, children excluded, with no
	 * fallback; `explicit` is the location the event names; `single` is the one location that holds enough
	 * (ADR-0041 rule 4). A mapping read now applies, so an event that arrives after the fixed location was
	 * switched is charged to the new one; the operator guide says so.
	 *
	 * @return array<int, ?int> product id to location id
	 * @throws EventRefusal
	 */
	private function ResolveLocations(array $mapping, array $payload, array $planned): array
	{
		$needed = [];
		foreach ($planned as [$productId, $amount])
		{
			$needed[$productId] = ($needed[$productId] ?? 0.0) + $amount;
		}

		$locations = [];

		if ($mapping['location_mode'] === 'fixed')
		{
			foreach ($needed as $productId => $unused)
			{
				$locations[$productId] = (int)$mapping['location_id'];
			}

			return $locations;
		}

		if ($mapping['location_mode'] === 'explicit')
		{
			$location = $payload['location_id'];
			$exists = $location !== null && $this->ExistingLocationOrNull($location) !== null;

			if (!$exists)
			{
				throw new EventRefusal('ambiguous_location', ['candidate_location_ids' => []]);
			}

			foreach ($needed as $productId => $unused)
			{
				$locations[$productId] = $location;
			}

			return $locations;
		}

		$stock = StockService::GetInstance();

		foreach ($needed as $productId => $amount)
		{
			$candidates = [];
			$holding = [];
			foreach ($stock->GetProductStockLocations($productId, false) as $location)
			{
				$holding[(int)$location->location_id] = true;
			}

			foreach (array_keys($holding) as $locationId)
			{
				if (StockService::CompareAmounts($amount, $stock->ScopedStockAmount($productId, $locationId)) <= 0)
				{
					$candidates[] = $locationId;
				}
			}

			sort($candidates);

			if (count($candidates) === 0)
			{
				throw new EventRefusal('insufficient_stock');
			}

			if (count($candidates) > 1)
			{
				throw new EventRefusal('ambiguous_location', ['candidate_location_ids' => $candidates]);
			}

			$locations[$productId] = $candidates[0];
		}

		return $locations;
	}

	// --- Deletion ----------------------------------------------------------------------------

	/**
	 * DELETE /consumption/events/{source_system}/{source_event_id}: the source no longer holds the event.
	 * The reason decides whether stock changes (ADR-0041 rule 7). A client that cannot say sends none.
	 */
	public function Delete(int $userId, string $sourceSystem, string $sourceEventId, ?string $reason): array
	{
		$this->RequireOutsideTransaction();
		self::CheckIdentity($sourceSystem, $sourceEventId);
		$this->RequireConsume($userId);
		$reason = $reason ?? 'unknown';

		if (!in_array($reason, self::REMOVAL_REASONS, true))
		{
			throw self::Invalid('reason is one of ' . implode(', ', self::REMOVAL_REASONS));
		}

		$row = $this->RequireRow($userId, $sourceSystem, $sourceEventId);
		$eventId = (int)$row['id'];

		return $this->RunBooking($userId, $eventId, null, function () use ($eventId, $reason)
		{
			$locked = $this->PersistDerived($this->RowById($eventId, true));

			return $this->Present($this->ApplyRemoval($locked, $reason));
		});
	}

	/**
	 * What a removal means for the row. A booked event is voided only for entered_in_error and only within the
	 * automatic-void window; any other reason leaves the stock alone, and a bare or unknown one asks a person.
	 */
	private function ApplyRemoval(array $row, string $reason): array
	{
		$eventId = (int)$row['id'];
		[$state, $stateReason] = $this->EffectiveState($row);
		$live = $this->LiveBookingCount($row['transaction_id']) > 0 && $state !== 'linked';

		$record = function (string $newState, ?string $newReason, bool $voiding) use ($eventId, $reason)
		{
			$this->Db()->prepare("UPDATE consumption_events SET state = ?, reason = ?, source_removed_at = COALESCE(source_removed_at, now()), source_removed_reason = COALESCE(source_removed_reason, ?),
					voided_at = CASE WHEN ? THEN now() ELSE voided_at END, updated_at = now() WHERE id = ?")
				->execute([$newState, $newReason, $reason, $voiding ? 1 : 0, $eventId]);

			return $this->RowById($eventId);
		};

		if ($state === 'linked')
		{
			return $record('linked', null, false);
		}

		if ($state === 'voided' || $state === 'dismissed')
		{
			return $row;
		}

		if (!$live)
		{
			return $record($reason === 'entered_in_error' ? 'voided' : 'dismissed', null, $reason === 'entered_in_error');
		}

		if ($reason === 'entered_in_error')
		{
			if ($this->WithinAutoVoidWindow($row))
			{
				$this->LockAndUndo($row['transaction_id'], []);

				return $record('voided', null, true);
			}

			return $record('needs_review', 'source_deleted', false);
		}

		if ($reason === 'unknown')
		{
			return $record('needs_review', 'source_deleted', false);
		}

		// history_cleared, medication_archived, access_revoked: the source stopped holding the record, which
		// does not un-take the dose. Stock stays; the removal is recorded.
		return $record($state === 'needs_review' ? 'needs_review' : 'booked', $state === 'needs_review' ? $stateReason : null, false);
	}

	private function WithinAutoVoidWindow(array $row): bool
	{
		$statement = $this->Db()->prepare("SELECT occurred_at >= now() - make_interval(days => ?) FROM consumption_events WHERE id = ?");
		$statement->execute([self::Setting('CONSUMPTION_AUTO_VOID_DAYS', 7), $row['id']]);

		return (bool)$statement->fetchColumn();
	}

	// --- Resolution --------------------------------------------------------------------------

	private function Transition(string $action, string $state, ?string $reason): ConsumptionException
	{
		return new ConsumptionException(409, 'invalid_transition', 'The action ' . $action . ' is not allowed on an event that is ' . $state . ($reason !== null ? ' (' . $reason . ')' : ''));
	}

	/**
	 * POST /consumption/events/{source_system}/{source_event_id}/resolve: a person acts on one event.
	 */
	public function Resolve(int $userId, string $sourceSystem, string $sourceEventId, string $action, ?string $transactionId = null): array
	{
		$this->RequireOutsideTransaction();
		self::CheckIdentity($sourceSystem, $sourceEventId);
		$this->RequireConsume($userId);

		if (!in_array($action, self::ACTIONS, true))
		{
			throw self::Invalid('action is one of ' . implode(', ', self::ACTIONS));
		}

		$row = $this->RequireRow($userId, $sourceSystem, $sourceEventId);
		$eventId = (int)$row['id'];

		if ($action === 'approve_unit')
		{
			// The approval is the person's decision and must outlive a booking that then fails for another
			// reason, so it commits first, in its own transaction; the booking below is an ordinary retry.
			DatabaseService::GetInstance()->InTransaction(fn() => $this->ApproveUnitLabel($userId, $eventId));
		}

		return $this->RunBooking($userId, $eventId, null, fn() => $this->Present($this->ResolveLocked($userId, $eventId, $action, $transactionId)));
	}

	private function ApproveUnitLabel(int $userId, int $eventId): void
	{
		$row = $this->PersistDerived($this->RowById($eventId, true));
		[$state, $reason] = $this->EffectiveState($row);
		if ($state !== 'needs_review' || $reason !== 'unit_unconfirmed')
		{
			throw $this->Transition('approve_unit', $state, $reason);
		}

		$mapping = $this->Mappings()->FindForUser($userId, $row['source_system'], (string)$row['medication_ref']);
		if ($mapping === null)
		{
			throw $this->Transition('approve_unit', 'a mapping that no longer exists', null);
		}

		$this->Mappings()->AddUnitLabel((int)$mapping['id'], (string)$row['unit_label']);
	}

	private function ResolveLocked(int $userId, int $eventId, string $action, ?string $transactionId): array
	{
		$row = $this->PersistDerived($this->RowById($eventId, true));
		[$state, $reason] = $this->EffectiveState($row);
		$live = $this->LiveBookingCount($row['transaction_id']) > 0 && $state !== 'linked';

		switch ($action)
		{
			case 'retry':
				if (!in_array($state, ['needs_mapping', 'needs_review'], true))
				{
					throw $this->Transition($action, $state, $reason);
				}

				return $this->BookOrRebook($row, $this->PayloadFromRow($row), null);

			case 'rebook':
				if ($state !== 'undone' && !($state === 'needs_review' && $reason === 'partially_undone'))
				{
					throw $this->Transition($action, $state, $reason);
				}

				return $this->BookOrRebook($row, $this->PayloadFromRow($row), null);

			case 'approve_unit':
				if ($state !== 'needs_review' || $reason !== 'unit_unconfirmed')
				{
					throw $this->Transition($action, $state, $reason);
				}

				return $this->BookOrRebook($row, $this->PayloadFromRow($row), null);

			case 'void':
				if ($state !== 'needs_review' || $reason !== 'source_deleted')
				{
					throw $this->Transition($action, $state, $reason);
				}

				$this->LockAndUndo($live ? $row['transaction_id'] : null, []);
				$this->Db()->prepare("UPDATE consumption_events SET state = 'voided', reason = NULL, voided_at = now(), updated_at = now() WHERE id = ?")->execute([$eventId]);

				return $this->RowById($eventId);

			case 'keep':
				if ($state !== 'needs_review' || $reason !== 'source_deleted')
				{
					throw $this->Transition($action, $state, $reason);
				}

				$this->Db()->prepare("UPDATE consumption_events SET state = 'booked', reason = NULL, updated_at = now() WHERE id = ?")->execute([$eventId]);

				return $this->RowById($eventId);

			case 'dismiss':
				if ($live || !in_array($state, ['received', 'needs_mapping', 'needs_review', 'undone'], true))
				{
					throw $this->Transition($action, $state, $reason);
				}

				$this->Db()->prepare("UPDATE consumption_events SET state = 'dismissed', reason = NULL, candidate_location_ids = NULL, stock_error_message = NULL, updated_at = now() WHERE id = ?")->execute([$eventId]);

				return $this->RowById($eventId);

			case 'link':
				if (!in_array($state, ['needs_review', 'booked', 'received'], true))
				{
					throw $this->Transition($action, $state, $reason);
				}

				return $this->Link($row, $userId, $transactionId, $live);
		}

		throw self::Invalid('Unknown action');
	}

	/** The request fields of a stored row, for an action that books without a new request. */
	private function PayloadFromRow(array $row): array
	{
		if ($row['medication_ref'] === null || $row['occurred_date_wire'] === null)
		{
			throw new ConsumptionException(409, 'invalid_transition', 'This event has no stored request to book from');
		}

		return [
			'status' => 'taken',
			'medication_ref' => $row['medication_ref'],
			'quantity' => $row['quantity'] === null ? null : (float)$row['quantity'],
			'unit_label' => $row['unit_label'],
			'location_id' => $row['requested_location_id'] === null ? null : (int)$row['requested_location_id'],
			'replaces' => null,
			'occurred_at' => $row['occurred_wire'],
			'occurred_date' => $row['occurred_date_wire'],
			'source_updated_at' => null,
			'hash' => $row['payload_hash'],
		];
	}

	/**
	 * Attaches the event to an existing booking transaction (ADR-0041 rule 9). The transaction must hold only
	 * unreversed consume bookings, be linked to nothing else, belong to no other event of this or any
	 * other user, have been recorded by the caller (or the caller holds STOCK_EDIT), and book the products
	 * the event's mapping targets. On a booked event the event's own transaction is undone in the same
	 * transaction, so exactly one deduction remains. Every refusal is `invalid_link`, which does not say
	 * which test failed for a transaction the caller cannot see.
	 */
	private function Link(array $row, int $userId, ?string $transactionId, bool $live): array
	{
		$refuse = fn(string $message) => new ConsumptionException(422, 'invalid_link', $message);

		if ($transactionId === null || $transactionId === '')
		{
			throw self::Invalid('link needs a transaction_id');
		}

		$bookings = $this->Db()->prepare('SELECT id, product_id, transaction_type, undone, user_id FROM stock_log WHERE transaction_id = ?');
		$bookings->execute([$transactionId]);
		$found = $bookings->fetchAll(\PDO::FETCH_ASSOC);

		if (count($found) === 0)
		{
			throw $refuse('That transaction cannot be linked');
		}

		foreach ($found as $booking)
		{
			if ($booking['transaction_type'] !== StockService::TRANSACTION_TYPE_CONSUME || (int)$booking['undone'] !== 0)
			{
				throw $refuse('That transaction cannot be linked');
			}
		}

		$owner = $this->Db()->prepare('SELECT user_id, source_system, linked_transaction_id FROM consumption_events WHERE transaction_id = ? OR linked_transaction_id = ?');
		$owner->execute([$transactionId, $transactionId]);

		foreach ($owner->fetchAll(\PDO::FETCH_ASSOC) as $holder)
		{
			$isOwnManual = (int)$holder['user_id'] === $userId && $holder['source_system'] === self::SOURCE_MANUAL && $holder['linked_transaction_id'] === null;

			if (!$isOwnManual)
			{
				throw $refuse('That transaction cannot be linked');
			}
		}

		$recordedByCaller = true;
		foreach ($found as $booking)
		{
			$recordedByCaller = $recordedByCaller && (int)$booking['user_id'] === $userId;
		}

		if (!$recordedByCaller)
		{
			$edit = $this->Db()->prepare('SELECT 1 FROM user_permissions_resolved WHERE user_id = ? AND permission_name = ?');
			$edit->execute([$userId, User::PERMISSION_STOCK_EDIT]);

			if ($edit->fetchColumn() === false)
			{
				throw $refuse('That transaction cannot be linked');
			}
		}

		$mapping = $row['medication_ref'] === null ? null : $this->Mappings()->FindForUser($userId, $row['source_system'], (string)$row['medication_ref']);
		if ($mapping === null)
		{
			throw $refuse('The event has no mapping to compare the products with');
		}

		$targets = [];
		if ($mapping['target_type'] === 'product')
		{
			$targets = [(int)$mapping['product_id']];
		}
		elseif ($mapping['recipe_id'] !== null)
		{
			$lines = ConsumptionRecipeService::GetInstance()->LinesForExternalConsumption((int)$mapping['recipe_id'], $userId);
			$targets = $lines === null ? [] : array_column($lines, 0);
		}

		$booked = array_values(array_unique(array_map(fn(array $booking) => (int)$booking['product_id'], $found)));
		$targets = array_values(array_unique($targets));
		sort($booked);
		sort($targets);

		if (count($targets) === 0 || $booked !== $targets)
		{
			throw $refuse('The transaction does not book the products this event is mapped to');
		}

		if ($live)
		{
			DatabaseService::GetInstance()->LockProductsStock(array_values(array_unique(array_merge($booked, $this->ProductsOfTransaction($row['transaction_id'])))));
			$this->LockAndUndo($row['transaction_id'], []);
		}

		$eventId = (int)$row['id'];
		$this->Db()->prepare("UPDATE consumption_events SET state = 'linked', reason = NULL, linked_transaction_id = ?, candidate_location_ids = NULL, stock_error_message = NULL, updated_at = now() WHERE id = ?")
			->execute([$transactionId, $eventId]);
		$this->Db()->prepare('DELETE FROM consumption_event_lines WHERE event_id = ?')->execute([$eventId]);
		$insert = $this->Db()->prepare('INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id, stock_log_id, used_date) VALUES (?, ?, ?, ?, ?, ?)');
		$lines = $this->Db()->prepare('SELECT id, product_id, amount, location_id, used_date FROM stock_log WHERE transaction_id = ? ORDER BY id');
		$lines->execute([$transactionId]);

		foreach ($lines->fetchAll(\PDO::FETCH_ASSOC) as $booking)
		{
			$insert->execute([$eventId, $booking['product_id'], abs((float)$booking['amount']), $booking['location_id'], $booking['id'], $booking['used_date']]);
		}

		return $this->RowById($eventId);
	}

	// --- Batch and bulk ----------------------------------------------------------------------

	/**
	 * POST /consumption/events/batch: each item is applied on its own; one failing does not roll back another.
	 *
	 * @return array<int, array> one result per item, in order
	 */
	public function Batch(int $userId, array $items, ?int $apiKeyId = null): array
	{
		if (count($items) === 0 || count($items) > self::BATCH_MAX)
		{
			throw self::Invalid('A batch holds 1 to ' . self::BATCH_MAX . ' events');
		}

		$results = [];

		foreach ($items as $item)
		{
			$system = is_array($item) && is_string($item['source_system'] ?? null) ? $item['source_system'] : '';
			$id = is_array($item) && is_string($item['source_event_id'] ?? null) ? $item['source_event_id'] : '';
			$result = ['source_system' => $system, 'source_event_id' => $id];

			try
			{
				if (!is_array($item))
				{
					throw self::Invalid('Each batch item is an object');
				}

				$submitted = $this->Submit($userId, $system, $id, array_diff_key($item, ['source_system' => 1, 'source_event_id' => 1]), $apiKeyId);
				$result['http_status'] = $submitted['created'] ? 201 : 200;
				$result['event'] = $submitted['event'];
			}
			catch (ConsumptionException $exception)
			{
				if ($exception->status === 403)
				{
					throw $exception;
				}

				$result['http_status'] = $exception->status;
				$result['error'] = ['error_message' => $exception->getMessage(), 'error' => $exception->errorCode];
			}

			$results[] = $result;
		}

		return $results;
	}

	/**
	 * POST /consumption/events/resolve: one action on up to 50 events, by list or by filter. Each item is its
	 * own transaction and reports its own result. With a filter, `remaining` counts the matching events beyond
	 * those handled, oldest first, so a client repeats until it is 0 for an action that moves events out of the
	 * filter (void, keep, dismiss, rebook, approve_unit); retry can leave an event in it, so a client lists them.
	 *
	 * @return array{results: array, remaining?: int}
	 */
	public function BulkResolve(int $userId, string $action, ?array $events, ?array $filter): array
	{
		$this->RequireOutsideTransaction();
		$this->RequireConsume($userId);

		if (!in_array($action, self::BULK_ACTIONS, true))
		{
			throw self::Invalid('action is one of ' . implode(', ', self::BULK_ACTIONS));
		}

		if (($events === null) === ($filter === null))
		{
			throw self::Invalid('Send exactly one of events or filter');
		}

		$remaining = null;

		if ($events !== null)
		{
			if (count($events) === 0 || count($events) > self::BULK_MAX)
			{
				throw self::Invalid('events holds 1 to ' . self::BULK_MAX . ' items');
			}

			$targets = [];
			foreach ($events as $event)
			{
				if (!is_array($event) || !is_string($event['source_system'] ?? null) || !is_string($event['source_event_id'] ?? null))
				{
					throw self::Invalid('Each event names source_system and source_event_id');
				}

				$targets[] = [$event['source_system'], $event['source_event_id']];
			}
		}
		else
		{
			foreach (['source_system', 'medication_ref', 'state'] as $required)
			{
				if (!is_string($filter[$required] ?? null) || $filter[$required] === '')
				{
					throw self::Invalid('The filter needs ' . $required);
				}
			}

			self::CheckIdentity($filter['source_system'], 'x');
			// The state a person sees is derived from stock_log on read, so an event undone in the stock journal
			// is still stored as `booked` until something touches it. The candidates are therefore the stored
			// states that can derive to the requested one, and the derived state is compared here.
			$all = $this->Db()->prepare("SELECT * FROM consumption_events WHERE user_id = ? AND source_system = ? AND medication_ref = ?
				AND state IN (?, 'booked', 'needs_review') ORDER BY occurred_at, id");
			$all->execute([$userId, $filter['source_system'], $filter['medication_ref'], $filter['state']]);
			$matching = [];

			foreach ($all->fetchAll(\PDO::FETCH_ASSOC) as $candidate)
			{
				[$state, $reason] = $this->EffectiveState($candidate);

				if ($state === $filter['state'] && (!isset($filter['reason']) || !is_string($filter['reason']) || $reason === $filter['reason']))
				{
					$matching[] = [$candidate['source_system'], $candidate['source_event_id']];
				}
			}

			$targets = array_slice($matching, 0, self::BULK_MAX);
			$remaining = max(0, count($matching) - count($targets));
		}

		$results = [];

		foreach ($targets as [$system, $id])
		{
			$result = ['source_system' => $system, 'source_event_id' => $id];

			try
			{
				$result['event'] = $this->Resolve($userId, $system, $id, $action);
				$result['http_status'] = 200;
			}
			catch (ConsumptionException $exception)
			{
				if ($exception->status === 403)
				{
					throw $exception;
				}

				$result['http_status'] = $exception->status;
				$result['error'] = ['error_message' => $exception->getMessage(), 'error' => $exception->errorCode];
			}

			$results[] = $result;
		}

		return $remaining === null ? ['results' => $results] : ['results' => $results, 'remaining' => $remaining];
	}
}
