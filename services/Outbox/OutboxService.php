<?php

namespace Victual\Services\Outbox;

use Victual\Services\BaseService;
use Victual\Services\DatabaseService;

/**
 * The transactional outbox: events written inside the transaction that produced them, and
 * acknowledged only once a consumer has actually taken them.
 *
 * This is the first instance of ADR-0010's contract, and the constitution's workload
 * standard is what it exists to satisfy: every consumer is an at-least-once consumer, every
 * side effect is idempotent or deduplicated, and queues are drained rather than fired and
 * forgotten.
 *
 * The mechanism is one sentence. **Enqueue() is called inside the caller's transaction**, so
 * a rollback takes the event with it and the outbox can never describe something that did
 * not happen; **a drain acknowledges only after the consumer succeeded**, so a crash, a
 * timeout or a rejected write leaves the row for the next attempt. What it buys is that
 * nothing committed is lost; what it costs is that a consumer may see an event twice - which
 * is why the consumer has to be idempotent (see BookingEventPublisher::BuildLines(), where
 * every point carries a unique identity so a redelivered batch overwrites rather than
 * duplicates).
 *
 * **A row is in exactly one of three states**, and the third is the one that is easy to
 * leave out. Undelivered rows are retried. Delivered rows are done. Dead-lettered rows are
 * the ones no version of the consumer will ever be able to read - a payload written by an
 * older shape, or a malformed one - and they need a state of their own because the
 * alternatives are both wrong: acknowledging them discards a committed event silently, and
 * retrying them forever blocks every valid row queued behind them. So they are set aside
 * with last_error saying why, excluded from GetUndelivered(), and left for a person.
 *
 * **Every payload carries a payload_version.** Consumers refuse what they cannot read
 * rather than guessing at it, which is what makes the dead-letter state reachable instead
 * of theoretical.
 *
 * One table, discriminated by event_type, because that record's rule is that consumers may
 * multiply and contracts may not. A second consumer attaches by reading its own event type,
 * not by minting a table of its own.
 *
 * Nothing writes to the outbox unless the consumer for that event type is configured on. An
 * outbox nobody drains is a leak - rows accumulate for a delivery that will never be
 * attempted - so the enabled check belongs at the enqueue site, not only at the drain.
 */
class OutboxService extends BaseService
{
	/**
	 * A stock booking was committed. Payload: {"transaction_id": "..."}.
	 *
	 * The payload is deliberately just the identifier. Every fact the consumer needs is in
	 * stock_log and is read at delivery time, because a payload that duplicated the booking
	 * would be a second copy of the ledger that could disagree with the first.
	 */
	const EVENT_STOCK_TRANSACTION_BOOKED = 'stock.transaction_booked';
	const EVENT_LABEL_PRINT_REQUESTED = 'label.print_requested';

	/**
	 * The payload shape consumers in this version understand.
	 *
	 * Version 1 was the transaction id alone, with every fact re-read from the ledger at
	 * delivery time - which made redelivery non-idempotent and is why it was replaced.
	 * Version 2 is the self-contained record BookingEventPublisher::CaptureEvent() writes.
	 * A version 1 row is not upgraded in place and not guessed at: it is dead-lettered,
	 * because the facts it needed no longer exist in a form that would reproduce what the
	 * first delivery attempt would have sent.
	 */
	const PAYLOAD_VERSION = 2;

	/**
	 * How many undelivered rows one drain takes. Bounded so that a long outage does not turn
	 * the first request after it into an unbounded batch; what is left is taken by the next
	 * drain.
	 */
	const DRAIN_BATCH_SIZE = 200;

	/**
	 * Appends an event, to be committed with whatever transaction the caller has open.
	 *
	 * There is no transaction handling here on purpose. The guarantee comes from this INSERT
	 * being part of the caller's transaction, so opening one here would break exactly the
	 * property the outbox exists for.
	 *
	 * @param string $eventType One of the EVENT_* constants
	 * @param array $payload Encoded as JSON; keep it small
	 */
	public function Enqueue(string $eventType, array $payload): int
	{
		$row = $this->DB->outbox()->createRow([
			'event_type' => $eventType,
			'payload' => json_encode($payload),
			'attempts' => 0
		]);
		$row->save();
		return (int)$row->id;
	}

	/** Append on an explicitly supplied transaction, returning the inserted event identity. */
	public static function EnqueueInTransaction(\PDO $db, string $eventType, array $payload): int
	{
		if (!$db->inTransaction()) throw new \LogicException('Outbox enqueue requires a transaction');
		$query = $db->prepare('INSERT INTO outbox(event_type, payload, attempts) VALUES (?, ?, 0) RETURNING id');
		$query->execute([$eventType, json_encode($payload, JSON_THROW_ON_ERROR)]);
		return (int)$query->fetchColumn();
	}

	/**
	 * The undelivered events of one type, oldest first.
	 *
	 * @return array<int, array{id: int, payload: array}>
	 */
	public function GetUndelivered(string $eventType, int $limit = self::DRAIN_BATCH_SIZE): array
	{
		$rows = [];

		foreach ($this->DB->outbox()->where('event_type = :1 AND delivered_at IS NULL AND dead_lettered_at IS NULL', $eventType)->orderBy('id')->limit($limit) as $row)
		{
			// Anything that is not a JSON object becomes an empty array rather than a scalar
			// or null, so the consumer's validation - which takes an array - is what decides
			// what happens to it, and a corrupt row is dead-lettered rather than raising a
			// TypeError that would take the whole drain with it.
			$decoded = json_decode((string)$row['payload'], true);

			$rows[] = [
				'id' => (int)$row['id'],
				'payload' => is_array($decoded) ? $decoded : []
			];
		}

		return $rows;
	}

	/**
	 * How many events of a type are still waiting, excluding dead-lettered ones.
	 *
	 * Deliberately allowed to throw. A caller that has to *prove* the queue is empty - the
	 * CLI drain, which exits 0 on the answer - must not be handed a zero that actually means
	 * "the database did not answer". BookingEventPublisher::HasBookings() is the swallowing
	 * counterpart, for the shutdown handler, which must not throw.
	 *
	 * @throws \Throwable Whatever the database raises
	 */
	public function CountUndelivered(string $eventType): int
	{
		return (int)$this->DB->outbox()->where('event_type = :1 AND delivered_at IS NULL AND dead_lettered_at IS NULL', $eventType)->count();
	}

	/**
	 * How many events of a type have been set aside as undeliverable.
	 *
	 * Reported by the CLI, because a drain that leaves rows behind should say so rather than
	 * exiting 0 on a queue that is empty only because the unreadable rows stopped counting.
	 */
	public function CountDeadLettered(string $eventType): int
	{
		return (int)$this->DB->outbox()->where('event_type = :1 AND dead_lettered_at IS NOT NULL', $eventType)->count();
	}

	/**
	 * Marks rows delivered. Called only after the consumer has confirmed it took them.
	 *
	 * @param int[] $ids
	 */
	public function MarkDelivered(array $ids): void
	{
		if (count($ids) === 0)
		{
			return;
		}

		$this->AsBookkeeping(function () use ($ids)
		{
			$now = date('Y-m-d H:i:s');

			foreach ($ids as $id)
			{
				$row = $this->DB->outbox()->where('id = :1', (int)$id)->fetch();

				if ($row !== null)
				{
					$row->update(['delivered_at' => $now]);
				}
			}
		});
	}

	/**
	 * Records a failed delivery attempt against rows that stay in the queue.
	 *
	 * attempts and last_error are what make a permanently failing event visible rather than
	 * merely slow - without them a poison event is indistinguishable from an idle queue.
	 *
	 * @param int[] $ids
	 */
	public function RecordFailure(array $ids, string $error): void
	{
		if (count($ids) === 0)
		{
			return;
		}

		$this->AsBookkeeping(function () use ($ids, $error)
		{
			foreach ($ids as $id)
			{
				$row = $this->DB->outbox()->where('id = :1', (int)$id)->fetch();

				if ($row !== null)
				{
					$row->update([
						'attempts' => (int)$row['attempts'] + 1,
						// Bounded: a driver can return a very long message and this column is
						// for a human glancing at why a queue stopped moving
						'last_error' => substr($error, 0, 1000)
					]);
				}
			}
		});
	}

	/**
	 * Sets rows aside as undeliverable, recording why.
	 *
	 * Not a deletion and not an acknowledgement. The event still happened and the row is
	 * still the evidence of it; what has changed is that nothing will try to deliver it
	 * again, so it stops blocking the queue behind it. attempts is advanced too, so the row
	 * reads as something that was tried rather than something that was skipped.
	 *
	 * @param int[] $ids
	 */
	public function DeadLetter(array $ids, string $reason): void
	{
		if (count($ids) === 0)
		{
			return;
		}

		$this->AsBookkeeping(function () use ($ids, $reason)
		{
			$now = date('Y-m-d H:i:s');

			foreach ($ids as $id)
			{
				$row = $this->DB->outbox()->where('id = :1', (int)$id)->fetch();

				if ($row !== null)
				{
					$row->update([
						'dead_lettered_at' => $now,
						'attempts' => (int)$row['attempts'] + 1,
						'last_error' => substr($reason, 0, 1000)
					]);
				}
			}
		});
	}

	/**
	 * Runs a write without letting it count as a data change.
	 *
	 * Draining is bookkeeping: it records what was delivered, not what the household did.
	 * Without this, acknowledging a delivery would mark the database changed, which would
	 * mark the request dirty, which is the condition that triggered the drain - so every
	 * drain would schedule another one.
	 *
	 * Suppression, not the read-write-restore idiom SessionService and ApiKeyService use for
	 * their last-used stamps. A drain runs at request end with no lock of its own and can be
	 * running while another request commits; restoring a snapshot of the changed time would
	 * then discard that request's newer value, and a client polling
	 * `GET /api/system/db-changed-time` would never learn of the committed change. See
	 * DatabaseService::RunAsBookkeeping().
	 */
	private function AsBookkeeping(callable $work): void
	{
		DatabaseService::GetInstance()->RunAsBookkeeping($work);
	}
}
