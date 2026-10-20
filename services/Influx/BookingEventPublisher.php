<?php

namespace Victual\Services\Influx;

use Victual\Services\Time\Instant;

use Ramsey\Uuid\Uuid;
use Victual\Services\DatabaseService;
use Victual\Services\Outbox\OutboxService;

/**
 * Turns committed stock bookings into InfluxDB events, by draining the outbox.
 *
 * Question 7 of docs/plans/18-mqtt-state-publication.md, answered 2026-08-31: price and
 * valuation *events* go to InfluxDB on the same after-commit seam as the MQTT publish. Two
 * measurements, both per product:
 *
 * - **price_paid** - one point per purchase booking that carried a price, at the booking's
 *   own row_created_timestamp rather than at delivery time, so a backdated purchase lands
 *   where it belongs in the series.
 * - **stock_value** - one point per product the transaction touched, holding that product's
 *   stock value as it stood when the transaction committed, read from the same
 *   `stock_current` view the stock overview page shows the household.
 *
 * **Delivery is at-least-once through the outbox, not best-effort from process memory.**
 * RecordTransaction() writes an outbox row inside the booking's own transaction, so a
 * rollback takes it with it and a crash between the commit and the write does not lose it.
 * A drain reads the undelivered rows, builds one batch, POSTs it, and marks them delivered
 * only on success; a failure increments `attempts`, stores `last_error` and leaves the rows
 * for the next drain, which is any later request or an explicit
 * `bin/victual-publish-state --drain`.
 *
 * **The event is a self-contained immutable record, and that is what makes redelivery
 * safe.** The payload carries a `payload_version`, a `event_id` unique to the event, the
 * moment the transaction committed, the booking rows as they stood at that moment, and the
 * post-commit `stock_current` snapshot for every product touched. BuildLines() derives every
 * line from the payload alone and queries nothing, so rebuilding an event a week later
 * produces byte-identical output.
 *
 * **One event per transaction, captured at the outermost commit.** RecordTransaction()
 * registers rather than captures, keyed by transaction id, and DatabaseService runs the
 * capture inside the outermost transaction just before it commits. That matters because the
 * call graph nests - OpenProduct delegates to TransferProduct, ConsumeRecipe wraps
 * ConsumeProduct, UndoTransaction loops over UndoBooking - so capturing at each entrypoint
 * produced several events for one transaction, each describing a state that was real only
 * part way through the work.
 *
 * **A payload this version cannot read is set aside, never acknowledged.** DescribeUnreadable()
 * decides that before anything is built, and Drain() dead-letters those rows individually
 * with the reason. Skipping them inside a batch that was then marked delivered - the earlier
 * behaviour - discarded committed events silently. "Cannot read" is decided over the whole
 * nested shape, not just the version and the presence of the two arrays: every key
 * BuildLines() reads is required with its type, because a value missing from a booking or a
 * stock entry would otherwise be cast to product 0, amount 0.0 or an empty timestamp and
 * written as a point that looks like data.
 *
 * That property is load-bearing rather than tidy. A point in InfluxDB is identified by its
 * measurement, tag set and timestamp, and writing the same identity again overwrites rather
 * than appends - so a batch delivered twice is indistinguishable from one delivered once,
 * which is exactly what at-least-once delivery needs. Deriving anything at delivery time
 * breaks it twice over: a retry after a POST that succeeded but was never acknowledged
 * would compute a different timestamp and leave a second point, and a backlog drained after
 * an outage would give every queued transaction the *latest* stock snapshot instead of each
 * one's own. Both were real defects in the first version of this class.
 *
 * The identity itself: every point carries `event_id`, so no two events can ever collide
 * however they are timed; `price_paid` additionally carries the `stock_log` row id as
 * `booking_id`, so two purchases of one product in the same second are two points rather
 * than one; and both measurements carry `transaction_id`, which is what a query groups by.
 * `transaction_id` alone was not enough for identity: undoing a transaction writes rows
 * under the same one, so an undo landing in the same second as its purchase overwrote it.
 *
 * Points carry no user id, no note and no location: this is a series about what the
 * household spends, not about who booked it.
 */
class BookingEventPublisher
{
	/**
	 * Set by an explicit CLI drain so the request-end trigger does not run a second one on
	 * the way out of the same process.
	 *
	 * Per-process, gone when the process is - the same category as DatabaseService's dirty
	 * flag rather than the cold-start problem ADR-0007 forbids. It only saves a redundant
	 * pass: a second drain after a successful one finds an empty queue, and after a failed
	 * one would simply fail again and log twice.
	 */
	private static $RequestEndDrainSuppressed = false;

	/**
	 * Prefix of the key RecordTransaction() registers its capture under. One key per
	 * transaction id, so the same transaction reached through nested entrypoints - and it
	 * routinely is - produces exactly one event.
	 */
	const OUTBOX_LISTENER_KEY_PREFIX = 'influx.stock_transaction.';

	/**
	 * Stops the request-end drain firing for the rest of this process.
	 */
	public static function SuppressRequestEndDrain(): void
	{
		self::$RequestEndDrainSuppressed = true;
	}

	/**
	 * Records that a stock booking happened, by appending a self-contained event to the
	 * outbox.
	 *
	 * **Call this inside the transaction that made the booking**, after its writes. Two
	 * things follow from that and both matter:
	 *
	 * - the outbox row and the ledger rows commit together or not at all, so the queue can
	 *   never describe a booking that was rolled back, and a process that dies between the
	 *   commit and the drain loses nothing;
	 * - the ledger reads below see this transaction's own uncommitted writes, so what is
	 *   captured is the post-commit state, which is the state the event is about.
	 *
	 * Everything the consumer will ever need is captured here rather than looked up at
	 * delivery time: the commit moment, the booking rows, and the resulting stock snapshot
	 * for each product touched. See the class docblock for why deriving any of it later is
	 * wrong rather than merely slower.
	 *
	 * **A failure here fails the booking.** It is deliberately not caught. Swallowing it
	 * would make the transactional guarantee conditional on the kind of error: an engine or
	 * error that does not abort the transaction would commit a booking with no event, and
	 * PostgreSQL would instead surface an aborted transaction later at commit() as something
	 * apparently unrelated. A booking whose event cannot be recorded has not fully happened,
	 * and rolling it back is the honest outcome - the household retries a purchase, which is
	 * a better failure than a silent hole in the series.
	 *
	 * Nothing is written when InfluxDB is off. An outbox nobody drains is a leak, and this
	 * is the only consumer of this event type today.
	 *
	 * @param string|null $transactionId
	 * @throws \Throwable When the event cannot be enqueued, aborting the caller's transaction
	 */
	public static function RecordTransaction($transactionId): void
	{
		if (!InfluxEventWriter::IsEnabled() || $transactionId === null || $transactionId === '')
		{
			return;
		}

		$transactionId = (string)$transactionId;

		// Registered rather than captured now, and keyed by transaction id so that a
		// transaction reached through several entrypoints registers once. What runs is one
		// capture at the outermost commit, seeing the transaction's final state.
		DatabaseService::GetInstance()->RegisterBeforeOutermostCommit(
			self::OUTBOX_LISTENER_KEY_PREFIX . $transactionId,
			function () use ($transactionId)
			{
				(new OutboxService())->Enqueue(
					OutboxService::EVENT_STOCK_TRANSACTION_BOOKED,
					(new self())->CaptureEvent($transactionId)
				);
			}
		);
	}

	/**
	 * The immutable record of one committed transaction: when it happened, what was booked,
	 * and what the stock looked like afterwards.
	 *
	 * Bounded by what a transaction touches, which is a handful of products - a purchase is
	 * one, a recipe consumption a few, an undo the size of the transaction it reverses.
	 * Nothing here grows with the size of the catalogue or of the ledger.
	 *
	 * `occurred_at` is the moment the transaction committed rather than any booking's own
	 * timestamp, because it is the moment the stock snapshot is true at. The bookings carry
	 * their own timestamps separately, which is what puts a backdated purchase where it
	 * belongs in the price series.
	 *
	 * @return array The outbox payload
	 */
	public function CaptureEvent(string $transactionId): array
	{
		$bookings = [];
		$productIds = [];

		foreach (DatabaseService::GetInstance()->ExecuteDbQuery(
			'SELECT id, product_id, amount, price, legacy_zero_price, transaction_type, undone, row_created_timestamp'
				. ' FROM stock_log WHERE transaction_id = ? ORDER BY id',
			[$transactionId]
		)->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$productId = (int)$row['product_id'];
			$productIds[$productId] = true;

			$bookings[] = [
				'booking_id' => (int)$row['id'],
				'product_id' => $productId,
				'amount' => (float)$row['amount'],
				// Kept null rather than coerced to 0.0: a booking with no price is not a
				// booking at a price of nothing, and the difference decides whether a
				// price_paid point exists at all. A 0 marked as never entered
				// (legacy_zero_price, ADR-0045) is unknown too, so it writes no point (#990)
				'price' => $row['price'] === null || (int)$row['legacy_zero_price'] === 1 ? null : (float)$row['price'],
				'transaction_type' => (string)$row['transaction_type'],
				'undone' => (int)$row['undone'],
				'row_created_timestamp' => (string)$row['row_created_timestamp']
			];
		}

		$snapshot = [];

		if (count($productIds) > 0)
		{
			// Raw SQL because stock_current has no id column and LessQL wants one. Read
			// whole rather than once per product: the view is a handful of rows at
			// household scale, and one query beats one per product.
			foreach (DatabaseService::GetInstance()
				->ExecuteDbQuery('SELECT product_id, amount, value FROM stock_current')
				->fetchAll(\PDO::FETCH_ASSOC) as $row)
			{
				$productId = (int)$row['product_id'];

				if (isset($productIds[$productId]))
				{
					$snapshot[] = [
						'product_id' => $productId,
						'amount' => (float)$row['amount'],
						'value' => (float)$row['value']
					];
					unset($productIds[$productId]);
				}
			}

			// A product consumed down to nothing drops out of stock_current entirely, and
			// its series has to say zero rather than simply stop - a gap would read as "no
			// data" where the truth is "none left"
			foreach (array_keys($productIds) as $productId)
			{
				$snapshot[] = ['product_id' => $productId, 'amount' => 0.0, 'value' => 0.0];
			}
		}

		return [
			// The version consumers check before reading anything else. A payload they
			// cannot read is dead-lettered rather than guessed at or acknowledged.
			'payload_version' => OutboxService::PAYLOAD_VERSION,
			// Unique to this event rather than to its transaction, which is what makes the
			// points it produces distinct from a later undo's. A transaction id is reused:
			// undoing a purchase writes rows under the same one, so identity built on it
			// would have the undo's stock_value overwrite the purchase's whenever the two
			// landed in the same second - and expose a half-undone state whenever they did
			// not.
			'event_id' => Uuid::uuid4()->toString(),
			'transaction_id' => $transactionId,
			'occurred_at' => Instant::Now(),
			'bookings' => $bookings,
			'stock' => $snapshot
		];
	}

	/**
	 * Whether this request has anything queued to deliver.
	 *
	 * Asked of the outbox rather than of process memory, so a request that books nothing
	 * still drains what an earlier failed attempt left behind - which is what makes a
	 * recovery happen on its own rather than needing somebody to notice.
	 *
	 * **Returns false on a database error**, because its caller is a shutdown handler and a
	 * shutdown handler must not throw. That makes it the wrong method for anyone who treats
	 * false as proof of an empty queue - use CountUndelivered(), which throws.
	 */
	public static function HasBookings(): bool
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return false;
		}

		try
		{
			return (new OutboxService())->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED) > 0;
		}
		catch (\Throwable $ex)
		{
			// Swallowed because the only caller is a shutdown handler, which must not throw.
			// The cost is that a database problem looks like an empty queue here - which is
			// why the CLI does not use this method: see CountUndelivered(), which throws.
			error_log('Victual: could not check the outbox for undelivered InfluxDB events: ' . $ex->getMessage());

			return false;
		}
	}

	/**
	 * How many events are waiting, propagating any database error.
	 *
	 * The counterpart to HasBookings(), for callers that have to *prove* the queue is empty
	 * rather than merely fail quietly. bin/victual-publish-state --drain exits 0 on this
	 * answer, so a swallowed error there would report success on a queue nobody managed to
	 * look at.
	 *
	 * @throws \Throwable Whatever the database raises
	 */
	public static function CountUndelivered(): int
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return 0;
		}

		return (new OutboxService())->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);
	}

	/**
	 * How many events have been set aside as undeliverable.
	 *
	 * @throws \Throwable Whatever the database raises
	 */
	public static function CountDeadLettered(): int
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return 0;
		}

		return (new OutboxService())->CountDeadLettered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);
	}

	/**
	 * The request-end trigger: drains the outbox.
	 *
	 * Called from DatabaseService's shutdown handler once every transaction is closed, for
	 * the same reason the MQTT publish is: a point written for a booking that then rolled
	 * back is a number in a time series that nothing will ever correct.
	 *
	 * **One batch per request, deliberately.** A request that finds a long backlog should
	 * not turn into an unbounded write on somebody's purchase; the next request takes the
	 * next batch, and the queue empties over a handful of them. An operator who wants the
	 * whole backlog gone now runs `bin/victual-publish-state --drain`, which loops.
	 *
	 * @return bool True when the drain made progress, as Drain() defines it
	 */
	public static function WriteForRequestEnd(): bool
	{
		if (self::$RequestEndDrainSuppressed)
		{
			return false;
		}

		return self::Drain();
	}

	/**
	 * Claims the undelivered events, builds one batch, sends it, and acknowledges only on
	 * success.
	 *
	 * **Everything from the claim to the acknowledgement runs inside one transaction**
	 * (issue #510, M10). `OutboxService::ClaimUndelivered()` takes its rows with
	 * `FOR UPDATE SKIP LOCKED`, and that claim only means anything for as long as the
	 * transaction holding it stays open: a second drain running at the same moment - another
	 * request's request-end trigger, or `bin/victual-publish-state --drain` - skips straight
	 * past whatever this one has locked rather than reading it too, and only sees those rows
	 * again once this transaction commits or rolls back. Wrapping the InfluxDB write itself
	 * inside that transaction is what makes the claim answer the right question: a drain
	 * that crashed, timed out, or got a rejection *after* claiming rows but before this
	 * commits releases the lock without having marked anything delivered, so the next drain
	 * claims and retries them exactly as before - the same at-least-once guarantee the class
	 * docblock describes, now safe against two drains running at once as well as against one
	 * drain failing outright.
	 *
	 * The order within the transaction matters and is unchanged: nothing is marked delivered
	 * before the consumer has taken it, so every failure mode - a timeout, a rejected write,
	 * the process dying mid-drain - leaves the rows exactly where they were, still claimed by
	 * nobody once this transaction is gone.
	 *
	 * **A rejected write is told apart by its status** (issue #854). A failure that says
	 * nothing about the data - a timeout, an unreachable server, a 401 or 403 for the token, a
	 * 404 for the bucket, a 429, a 5xx, a redirect - fails the whole batch with
	 * RecordFailure() as before, and nothing is ever dead-lettered on its account, because a
	 * misconfigured token must not discard committed events. A 400, 413 or 422 is InfluxDB
	 * refusing the body, so ResolveRefusedBatch() works out within this call which rows are at
	 * fault, in a bounded number of writes (at most 2 + ceil(log2(n)) when the endpoint refuses everything, about
	 * twice log2(n) when one row among many is bad): rows InfluxDB accepts are delivered, and
	 * a row it refuses on its own is collected.
	 *
	 * **A refused row is dead-lettered only if another row was accepted in the same drain.**
	 * That is the proof that the endpoint takes Victual's lines, so the refusal is about the
	 * row. Without it - every row refused, or a one-row batch refused - the same answer comes
	 * from a proxy, a bucket with an explicit schema, a field-type conflict with another
	 * writer or a series limit, and dead-lettering would discard the whole backlog with no
	 * way back. Those rows stay queued with attempts advanced and the status in last_error;
	 * there is no attempts cap, since a queue nothing can be written to loses nothing by
	 * waiting. A bad row is isolated by a later drain that claims it beside a good one. If a
	 * retryable failure turns up during the bisection the walk stops, what was resolved stays
	 * resolved (the same proof rule applies to rows refused before it), and the rest are
	 * recorded as failed.
	 *
	 * Lines are built per row, so a row that cannot be built is dead-lettered on its own
	 * instead of failing the whole batch.
	 *
	 * **Setting an unreadable or unbuildable row aside is progress** (issue #928). It leaves
	 * the undelivered count smaller, so a batch made only of such rows returns true and a
	 * caller draining the whole backlog goes on to the rows behind it instead of stopping
	 * with a failure. False is kept for a write that delivered nothing.
	 *
	 * @return bool True when the call made progress: an event was delivered, an unreadable
	 *              or unbuildable one was set aside, or a refused one was set aside because
	 *              another was delivered (always false when nothing could be written)
	 */
	public static function Drain(): bool
	{
		if (!InfluxEventWriter::IsEnabled())
		{
			return false;
		}

		$outbox = new OutboxService();

		try
		{
			return DatabaseService::GetInstance()->InTransaction(function () use ($outbox)
			{
				$events = $outbox->ClaimUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);

				if (count($events) === 0)
				{
					return false;
				}

				// Unreadable rows are separated before anything is built. Skipping them
				// inside the batch and then acknowledging the batch whole - the earlier
				// behaviour - discarded committed events with no trace and no attempts
				// recorded. They cannot be retried forever either, or they block every valid
				// row behind them, so they get a state of their own.
				$deliverable = [];
				$undeliverable = [];

				foreach ($events as $event)
				{
					$reason = self::DescribeUnreadable($event['payload']);

					if ($reason === null)
					{
						$deliverable[] = $event;
					}
					else
					{
						$undeliverable[$event['id']] = $reason;
					}
				}

				foreach ($undeliverable as $id => $reason)
				{
					error_log('Victual: outbox row ' . $id . ' cannot be delivered to InfluxDB and has been set aside: ' . $reason);
					$outbox->DeadLetter([$id], $reason);
				}

				if (count($deliverable) === 0)
				{
					// Nothing readable in this batch. The dead-lettered rows are out of the way,
					// which is progress: the next drain sees past them (#928).
					return true;
				}

				// Lines are built per row, not per batch, so that a row which cannot be built
				// is a row-sized problem and so that a rejected batch can be split along row
				// boundaries below.
				$rows = [];

				foreach ($deliverable as $event)
				{
					try
					{
						$rows[] = ['id' => $event['id'], 'attempts' => (int)($event['attempts'] ?? 0), 'lines' => self::BuildLines([$event['payload']])];
					}
					catch (\Throwable $ex)
					{
						// DescribeUnreadable() passed it, so this is something it did not
						// foresee. Rebuilding it will throw the same way, so it is set aside
						// like an unreadable row instead of being retried in front of every
						// row behind it.
						$reason = 'could not build the InfluxDB lines: ' . $ex->getMessage();
						error_log('Victual: outbox row ' . $event['id'] . ' cannot be delivered to InfluxDB and has been set aside: ' . $reason);
						$outbox->DeadLetter([$event['id']], $reason);
					}
				}

				// A readable event can still produce no lines - one whose bookings were all
				// undone and whose products left stock entirely. It is delivered rather than
				// retried forever: there is nothing to send and nothing wrong with it.
				$empty = [];
				$withLines = [];

				foreach ($rows as $row)
				{
					if (count($row['lines']) === 0)
					{
						$empty[] = $row['id'];
					}
					else
					{
						$withLines[] = $row;
					}
				}

				$outbox->MarkDelivered($empty);

				if (count($withLines) === 0)
				{
					// Delivered empty events, or rows set aside because they could not be built
					return count($empty) > 0 || count($rows) < count($deliverable);
				}

				$writer = new InfluxEventWriter();
				$lines = [];

				foreach ($withLines as $row)
				{
					array_push($lines, ...$row['lines']);
				}

				if ($writer->Write($lines))
				{
					$outbox->MarkDelivered(array_column($withLines, 'id'));

					return true;
				}

				if (!$writer->WasRowLevelRejection())
				{
					// A timeout, an unreachable server, a wrong token or bucket, a 5xx, a
					// redirect: nothing about the rows, so all of them stay in the queue.
					$outbox->RecordFailure(array_column($withLines, 'id'), $writer->GetLastError() ?? 'the write was rejected');

					return false;
				}

				// InfluxDB refused the body. Whether that is the fault of a row or of every
				// row alike cannot be told from this one answer, so find out below.
				// What the endpoint said to the whole batch, kept for the log and for the
				// reasons recorded below: the walk's own writes overwrite the writer's state.
				$refusalStatus = $writer->GetLastStatus();
				$refusalError = $writer->GetLastError() ?? 'no reason given';
				$state = [
					'delivered' => false, 'deliveredRows' => 0, 'rejected' => [], 'failed' => [], 'unresolved' => [],
					'error' => null, 'untriedReason' => null, 'refusalStatus' => $refusalStatus
				];

				self::ResolveRefusedBatch($withLines, $writer, $outbox, $state);

				// A row refused on its own is dead-lettered only when some other row of this
				// drain was accepted, which is what shows the endpoint takes Victual's lines
				// and so that the refusal is about that row. With no accepted row the same
				// answer comes just as readily from a proxy answering 400, a bucket with an
				// explicit schema, a field-type conflict with another writer or a series
				// limit - all of which would dead-letter the whole backlog, irrecoverably.
				// Those rows stay queued with the failure recorded; a genuinely bad row is
				// isolated by a later drain that claims it beside a good one.
				foreach ($state['rejected'] as $id => $reason)
				{
					if ($state['delivered'])
					{
						error_log('Victual: outbox row ' . $id . ' cannot be delivered to InfluxDB and has been set aside: ' . $reason);
						$outbox->DeadLetter([$id], $reason);
					}
					else
					{
						$outbox->RecordFailure([$id], $reason);
					}
				}

				// Rows that met a failure that was not about them, and rows the walk never got to
				if (count($state['failed']) > 0)
				{
					$outbox->RecordFailure($state['failed'], $state['error'] ?? 'the write was rejected');
				}

				if (count($state['unresolved']) > 0)
				{
					$outbox->RecordFailure($state['unresolved'], $state['untriedReason'] ?? $state['error'] ?? 'the write was rejected');
				}

				// One line for the whole walk: its writes were not logged one by one
				error_log('Victual: InfluxDB refused a batch of ' . count($withLines) . ' events (HTTP ' . $refusalStatus . ': ' . $refusalError . '): '
					. $state['deliveredRows'] . ' delivered, '
					. ($state['delivered'] ? count($state['rejected']) : 0) . ' set aside, '
					. ($state['delivered'] ? 0 : count($state['rejected'])) . ' kept after refusal, '
					. count($state['failed']) . ' kept after a retryable failure'
					. ($state['error'] !== null && count($state['failed']) > 0 ? ' (' . $state['error'] . ')' : '') . ', '
					. count($state['unresolved']) . ' not tried');

				// Progress is a row delivered, or a row dead-lettered because one was
				// delivered, which is the same condition. --drain stops on false.
				return $state['delivered'] || count($empty) > 0;
			});
		}
		catch (\Throwable $ex)
		{
			error_log('Victual: could not claim the outbox to deliver InfluxDB events: ' . $ex->getMessage());

			return false;
		}
	}

	/**
	 * Decides what a batch refused with a row-level status means, in a bounded number of writes.
	 *
	 * The whole batch was refused, and one answer cannot say whether a row or the endpoint is
	 * at fault: a proxy answering 400, a bucket with an explicit schema, a field-type conflict
	 * with another writer or a series limit refuse every row alike. A bisection that walked on
	 * regardless would make 2n-1 writes - 399 for a full batch of 200, inside the claim
	 * transaction, on every request, for ever. So the first row is tried alone:
	 *
	 * - **Accepted**: the endpoint takes Victual's lines, so the refusal is about some row of
	 *   the rest. They are bisected (IsolateRejection()), which isolates one bad row among
	 *   many in about twice log2(n) writes.
	 * - **Refused**: it may be the only bad row, or one of several, or the endpoint may be
	 *   refusing everything. Up to ceil(log2(n)) other rows are tried alone, at evenly spaced
	 *   positions across the batch. The first one accepted is the proof: the refused rows seen
	 *   so far are collected, and the rows still untried are bisected (IsolateRejection()), so
	 *   a few bad rows at the head of the queue are isolated and the good rows behind them
	 *   delivered. If every sampled row is refused too, nothing has been shown about any row
	 *   and the walk stops: nothing is dead-lettered and every row is recorded as failed and
	 *   stays queued. That is at most 2 + ceil(log2(n)) writes per drain, counting the
	 *   whole-batch write - 10 for 200 rows - when no proof is found. Once a row is accepted
	 *   the bisection of the rest costs about twice log2(n) writes per bad row left in it, so
	 *   it grows with the number of bad rows. The sample positions are rotated by the first
	 *   row's attempts, so a batch whose samples all happened to be bad is sampled differently
	 *   by the next drain. What stays blocked is a batch in which the first
	 *   row and every sampled row are all refused, which in practice is an endpoint-wide
	 *   refusal.
	 *
	 * Trying the first row, rather than descending to it, is the same test for one write
	 * instead of log2(n). The oldest row is the one a bad row blocks the queue as, so it is the
	 * row that has to be able to be isolated: a walk that stopped at the first refusal without
	 * looking past it would leave a bad row at the head of the queue there for ever.
	 *
	 * A failure that is not row-level (a timeout, a 500, a 401) stops the walk wherever it
	 * happens: rows resolved so far stay resolved and the rest go to $state['unresolved'].
	 *
	 * @param array<int, array{id: int, attempts: int, lines: string[]}> $rows One or more rows, every one with lines
	 * @param array{delivered: bool, deliveredRows: int, rejected: array<int, string>, unresolved: int[], error: string|null} $state
	 */
	private static function ResolveRefusedBatch(array $rows, InfluxEventWriter $writer, OutboxService $outbox, array &$state): void
	{
		$first = array_slice($rows, 0, 1);
		$rest = array_slice($rows, 1);

		if (count($rest) === 0)
		{
			// The batch that was refused is this one row
			$state['rejected'][$first[0]['id']] = self::DescribeRejection($writer);

			return;
		}

		$outcome = self::WriteQuietly($first, $writer, $outbox, $state);

		if ($outcome === 'failed')
		{
			array_push($state['unresolved'], ...array_column($rest, 'id'));

			return;
		}

		if ($outcome === 'accepted')
		{
			// The whole was refused and the first row was not, so the rest hold the bad row
			self::ResolveRemainder($rest, $writer, $outbox, $state);

			return;
		}

		$state['rejected'][$first[0]['id']] = self::DescribeRejection($writer);

		// Look for proof among the rest: ceil(log2(n)) single rows, evenly spaced across the
		// batch. The positions are rotated by the first row's attempts: the claim is
		// ORDER BY id LIMIT, so an unchanged batch comes back identical after a failed drain,
		// and fixed positions would sample the same rows - all bad, say - for ever. Each failed
		// drain advances the first row's attempts, so the next one samples different rows.
		$count = count($rows);
		$samples = (int)ceil(log($count, 2));
		$positions = [];

		for ($i = 1; $i <= $samples; $i++)
		{
			$base = min($count - 1, intdiv($i * $count, $samples));
			$positions[1 + (($base - 1 + $first[0]['attempts']) % ($count - 1))] = true;
		}

		$refused = [];

		foreach (array_keys($positions) as $position)
		{
			$outcome = self::WriteQuietly([$rows[$position]], $writer, $outbox, $state);

			if ($outcome === 'refused')
			{
				$refused[$rows[$position]['id']] = self::DescribeRejection($writer);

				continue;
			}

			$tried = array_keys($refused);
			$tried[] = $rows[$position]['id'];
			$untried = array_values(array_filter($rest, function ($row) use ($tried)
			{
				return !in_array($row['id'], $tried, true);
			}));

			if ($outcome === 'failed')
			{
				array_push($state['unresolved'], ...array_column($untried, 'id'));
				// The sampled rows refused before it are rows nothing was proven about
				foreach ($refused as $id => $reason)
				{
					$state['rejected'][$id] = $reason;
				}

				return;
			}

			// Proof. The sampled rows refused on their own are refused rows like any other
			$state['rejected'] += $refused;
			self::ResolveRemainder($untried, $writer, $outbox, $state);

			return;
		}

		// Every sampled row was refused as well: stop, and keep everything
		$state['rejected'] += $refused;
		$state['error'] = self::DescribeRejection($writer);
		$state['untriedReason'] = 'not tried: the batch was refused (HTTP ' . $state['refusalStatus']
			. ') and no row was accepted to prove the endpoint takes Victual\'s lines';
		array_push($state['unresolved'], ...array_column(array_filter($rest, function ($row) use ($refused)
		{
			return !array_key_exists($row['id'], $refused);
		}), 'id'));
	}

	/**
	 * Resolves rows that are known to hold the refused one(s), once a row has been delivered.
	 *
	 * @param array<int, array{id: int, lines: string[]}> $rows Zero or more rows
	 * @param array{delivered: bool, deliveredRows: int, rejected: array<int, string>, unresolved: int[], error: string|null} $state
	 */
	private static function ResolveRemainder(array $rows, InfluxEventWriter $writer, OutboxService $outbox, array &$state): void
	{
		if (count($rows) === 0)
		{
			return;
		}

		if (count($rows) === 1)
		{
			if (self::WriteQuietly($rows, $writer, $outbox, $state) === 'refused')
			{
				$state['rejected'][$rows[0]['id']] = self::DescribeRejection($writer);
			}

			return;
		}

		self::IsolateRejection($rows, $writer, $outbox, $state);
	}

	/**
	 * One write of the walk, not logged by the writer: delivers the rows on success and records
	 * a failure that is not about the rows. Row-level refusals are left to the caller.
	 *
	 * @param array<int, array{id: int, lines: string[]}> $rows
	 * @param array{delivered: bool, deliveredRows: int, rejected: array<int, string>, unresolved: int[], error: string|null} $state
	 * @return string 'accepted', 'refused' (row-level status) or 'failed' (anything else)
	 */
	private static function WriteQuietly(array $rows, InfluxEventWriter $writer, OutboxService $outbox, array &$state): string
	{
		$lines = [];

		foreach ($rows as $row)
		{
			array_push($lines, ...$row['lines']);
		}

		if ($writer->Write($lines, false))
		{
			$outbox->MarkDelivered(array_column($rows, 'id'));
			$state['delivered'] = true;
			$state['deliveredRows'] += count($rows);

			return 'accepted';
		}

		if ($writer->WasRowLevelRejection())
		{
			return 'refused';
		}

		$state['error'] = $writer->GetLastError() ?? 'the write was rejected';
		$state['untriedReason'] = $state['error'];
		array_push($state['failed'], ...array_column($rows, 'id'));

		return 'failed';
	}

	/**
	 * Finds the row(s) InfluxDB refused in a batch it refused as a whole, by bisection.
	 *
	 * The caller has just had the whole of $rows rejected with a row-level status (see
	 * InfluxEventWriter::ROW_LEVEL_REJECTION_STATUSES). Each half is written on its own: a
	 * half InfluxDB accepts is marked delivered, a half it rejects the same way is split
	 * again, and a single row rejected that way is collected in $state['rejected'] with the
	 * status and InfluxDB's error text. Bisection rather than one request per row because the
	 * usual case is one bad row among up to DRAIN_BATCH_SIZE, which this resolves in about
	 * twice log2(n) writes instead of n.
	 *
	 * Only reached once a row has been delivered in this drain (ResolveRefusedBatch() sees to
	 * that), so a refusal here is evidence about the row and the walk may go the whole way.
	 *
	 * The writes of the walk are not logged one by one (InfluxEventWriter::Write()'s
	 * $logRejection); Drain() logs one summary line.
	 *
	 * Nothing is dead-lettered here: whether a rejected row is dead-lettered or kept depends
	 * on whether any row was accepted anywhere in the drain, which is only known once the
	 * walk is over (see Drain()).
	 *
	 * A half failing in any other way - a timeout, a 500, a 401 - stops the walk. Rows
	 * resolved so far stay resolved, and every row not yet tried goes to $state['unresolved']
	 * to be recorded as failed and retried by the next drain. Such a failure says nothing
	 * about the rows.
	 *
	 * Re-sending rows that a partly applied 400 already stored is harmless: a point with the
	 * same measurement, tag set and timestamp overwrites the one in the series, and every
	 * point's identity (event_id, transaction_id, product_id, booking_id) comes from the
	 * payload, so the second write replaces the first rather than adding to it.
	 *
	 * @param array<int, array{id: int, lines: string[]}> $rows Two or more rows, every one with lines
	 * @param array{delivered: bool, deliveredRows: int, rejected: array<int, string>, unresolved: int[], error: string|null} $state
	 */
	private static function IsolateRejection(array $rows, InfluxEventWriter $writer, OutboxService $outbox, array &$state): void
	{
		foreach (array_chunk($rows, (int)ceil(count($rows) / 2)) as $half)
		{
			if ($state['error'] !== null)
			{
				// Stopped by a failure that is not about the rows: not tried
				array_push($state['unresolved'], ...array_column($half, 'id'));

				continue;
			}

			$lines = [];

			foreach ($half as $row)
			{
				array_push($lines, ...$row['lines']);
			}

			if ($writer->Write($lines, false))
			{
				$outbox->MarkDelivered(array_column($half, 'id'));
				$state['delivered'] = true;
				$state['deliveredRows'] += count($half);
			}
			elseif (!$writer->WasRowLevelRejection())
			{
				$state['error'] = $writer->GetLastError() ?? 'the write was rejected';
				$state['untriedReason'] = $state['error'];
				array_push($state['failed'], ...array_column($half, 'id'));
			}
			elseif (count($half) === 1)
			{
				$state['rejected'][$half[0]['id']] = self::DescribeRejection($writer);
			}
			else
			{
				self::IsolateRejection($half, $writer, $outbox, $state);
			}
		}
	}

	/**
	 * What a row refused on its own is recorded as: the status and InfluxDB's error text.
	 */
	private static function DescribeRejection(InfluxEventWriter $writer): string
	{
		return 'InfluxDB rejected this event (HTTP ' . $writer->GetLastStatus() . '): ' . ($writer->GetLastError() ?? 'no reason given');
	}

	/**
	 * Why a payload cannot be delivered, or null when it can.
	 *
	 * Separate from BuildLines() on purpose: the drain has to decide what to do with an
	 * unreadable row *before* it builds anything, because the answer is to set that row
	 * aside individually rather than to skip it silently inside a batch that is then
	 * acknowledged whole. Skipping was the earlier behaviour and it discarded committed
	 * events without a trace.
	 *
	 * **The nested shape is checked, not only the top level.** The version check and a
	 * present-and-an-array test for `bookings` and `stock` used to be the whole of it, which
	 * made a payload "readable" whenever the two arrays merely existed. BuildLines() then
	 * cast whatever was inside them: a missing product_id became product 0, a missing amount
	 * or value became 0.0, a missing timestamp became `ToNanoseconds('')`. That is a corrupt
	 * point written into a series nothing will ever correct, on a row then marked delivered -
	 * the exact failure the dead-letter state exists to prevent, arriving through the gap
	 * between "the arrays are there" and "the arrays say something".
	 *
	 * So every key BuildLines() reads is required here, with its type, and the answer names
	 * the element and the field that failed. Delivery is at-least-once and every point's
	 * identity is derived from the payload, so a value this method lets through is a value
	 * the series is stuck with.
	 *
	 * The first element that fails ends the walk: the row is dead-lettered either way, and
	 * one precise reason is more use to a person reading `last_error` than a list.
	 */
	public static function DescribeUnreadable(array $payload): ?string
	{
		if (count($payload) === 0)
		{
			// OutboxService::GetUndelivered() hands back an empty array for a row whose
			// payload is not a JSON object at all, so this is the corrupt-row case rather
			// than a shape question
			return 'payload is empty or is not a JSON object';
		}

		$version = $payload['payload_version'] ?? null;

		if ($version === null)
		{
			// The version 1 shape: a transaction id, with every fact re-read from the ledger
			// at delivery time. Not upgraded in place, because the ledger has moved on and
			// what it would produce now is not what the first attempt would have sent.
			return 'payload has no payload_version (the version 1 shape, which this version cannot deliver)';
		}

		if (!is_scalar($version) || (int)$version !== OutboxService::PAYLOAD_VERSION)
		{
			return 'payload_version ' . self::Describe($version) . ' is not ' . OutboxService::PAYLOAD_VERSION;
		}

		// The event's identity, which is what makes redelivery overwrite rather than
		// duplicate. A blank or malformed one would tag every point of the event with the
		// same nothing, so the collision the UUID was added to prevent comes straight back.
		$eventId = $payload['event_id'] ?? null;

		if (!is_string($eventId) || !Uuid::isValid($eventId))
		{
			return 'event_id is not a UUID (' . self::Describe($eventId) . ')';
		}

		if (!self::IsNonEmptyString($payload['transaction_id'] ?? null))
		{
			return 'transaction_id is missing or is not a non-empty string ('
				. self::Describe($payload['transaction_id'] ?? null) . ')';
		}

		$reason = self::DescribeBadTimestamp('occurred_at', $payload['occurred_at'] ?? null);

		if ($reason !== null)
		{
			return $reason;
		}

		foreach (['bookings', 'stock'] as $collection)
		{
			if (!is_array($payload[$collection] ?? null))
			{
				return $collection . ' is missing or is not an array ('
					. self::Describe($payload[$collection] ?? null) . ')';
			}
		}

		foreach ($payload['bookings'] as $index => $booking)
		{
			$reason = self::DescribeUnreadableBooking('bookings[' . $index . ']', $booking);

			if ($reason !== null)
			{
				return $reason;
			}
		}

		foreach ($payload['stock'] as $index => $entry)
		{
			$reason = self::DescribeUnreadableStock('stock[' . $index . ']', $entry);

			if ($reason !== null)
			{
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Why one booking element cannot be read, or null when it can.
	 *
	 * Every field BuildLines() touches, including the ones it only reads for *some*
	 * bookings: `transaction_type`, `undone` and `price` decide whether a price_paid point
	 * exists at all, so a malformed one does not merely produce a wrong number, it silently
	 * changes how many points the event has.
	 *
	 * `price` is the one field allowed to be absent, because null is a fact there rather
	 * than a gap: a booking with no price is not a booking at a price of nothing, and
	 * CaptureEvent() preserves that distinction deliberately.
	 *
	 * @param mixed $booking
	 */
	private static function DescribeUnreadableBooking(string $where, $booking): ?string
	{
		if (!is_array($booking))
		{
			return $where . ' is not an object (' . self::Describe($booking) . ')';
		}

		foreach (['booking_id', 'product_id', 'undone'] as $field)
		{
			if (!self::IsIntegerLike($booking[$field] ?? null))
			{
				return $where . '.' . $field . ' is missing or is not an integer ('
					. self::Describe($booking[$field] ?? null) . ')';
			}
		}

		if (!self::IsNumber($booking['amount'] ?? null))
		{
			return $where . '.amount is missing or is not a number ('
				. self::Describe($booking['amount'] ?? null) . ')';
		}

		// Absent and null are the same thing here and both are legal; anything else present
		// has to be a number, because it becomes a field value in the series
		$price = $booking['price'] ?? null;

		if ($price !== null && !self::IsNumber($price))
		{
			return $where . '.price is neither null nor a number (' . self::Describe($price) . ')';
		}

		if (!self::IsNonEmptyString($booking['transaction_type'] ?? null))
		{
			return $where . '.transaction_type is missing or is not a non-empty string ('
				. self::Describe($booking['transaction_type'] ?? null) . ')';
		}

		return self::DescribeBadTimestamp($where . '.row_created_timestamp',
			$booking['row_created_timestamp'] ?? null);
	}

	/**
	 * Why one stock snapshot element cannot be read, or null when it can.
	 *
	 * `amount` and `value` are required rather than defaulted, because a product consumed to
	 * nothing is recorded as an explicit zero by CaptureEvent() - so an absent one is a
	 * payload that lost something, not a payload saying "none left", and the two must not
	 * produce the same point.
	 *
	 * @param mixed $entry
	 */
	private static function DescribeUnreadableStock(string $where, $entry): ?string
	{
		if (!is_array($entry))
		{
			return $where . ' is not an object (' . self::Describe($entry) . ')';
		}

		if (!self::IsIntegerLike($entry['product_id'] ?? null))
		{
			return $where . '.product_id is missing or is not an integer ('
				. self::Describe($entry['product_id'] ?? null) . ')';
		}

		foreach (['amount', 'value'] as $field)
		{
			if (!self::IsNumber($entry[$field] ?? null))
			{
				return $where . '.' . $field . ' is missing or is not a number ('
					. self::Describe($entry[$field] ?? null) . ')';
			}
		}

		return null;
	}

	/**
	 * Why a timestamp field cannot be read, or null when it can.
	 *
	 * The database hands these back as instants in the wire rendering, and an event queued
	 * before migration 0301 still carries a local "Y-m-d H:i:s"; InfluxEventWriter's
	 * ToNanoseconds() turns either into the epoch a point is identified by - so anything else
	 * is either an exception mid-batch or, worse, a point at a time that is not when the
	 * thing happened. The shape is checked before DateTimeImmutable sees it, because that
	 * constructor accepts a great deal that is not a timestamp ("now", "+1 day") and none of
	 * it belongs in a series.
	 *
	 * @param mixed $value
	 */
	private static function DescribeBadTimestamp(string $where, $value): ?string
	{
		if (!is_string($value) || $value === '')
		{
			return $where . ' is missing or is not a string (' . self::Describe($value) . ')';
		}

		if (Instant::ParseStored($value) === null)
		{
			return $where . ' is not an instant or a "Y-m-d H:i:s" wall clock (' . self::Describe($value) . ')';
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	private static function IsNonEmptyString($value): bool
	{
		return is_string($value) && trim($value) !== '';
	}

	/**
	 * A JSON number or a string that is one. Strings are accepted because a driver may hand
	 * a numeric column back as one, and rejecting that would dead-letter valid events.
	 *
	 * @param mixed $value
	 */
	private static function IsNumber($value): bool
	{
		return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value);
	}

	/**
	 * @param mixed $value
	 */
	private static function IsIntegerLike($value): bool
	{
		return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
	}

	/**
	 * A short rendering of a value for a last_error a person reads.
	 *
	 * Bounded, because the value came out of a payload and the column is for a glance at why
	 * a queue stopped moving rather than for the payload itself.
	 *
	 * @param mixed $value
	 */
	private static function Describe($value): string
	{
		if ($value === null)
		{
			return 'null';
		}

		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		if (is_array($value))
		{
			return 'an array of ' . count($value);
		}

		if (is_string($value))
		{
			return '"' . (strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value) . '"';
		}

		return is_object($value) ? 'an object' : (string)$value;
	}

	/**
	 * The line-protocol batch for a set of captured events.
	 *
	 * **Nothing here reads the database.** Every value comes from the payload
	 * CaptureEvent() wrote inside the booking's transaction, which is what makes rebuilding
	 * an event produce byte-identical output however long afterwards it happens, and
	 * therefore what makes the outbox's at-least-once delivery safe. A version of this that
	 * re-read the ledger produced a different timestamp on every retry and gave a whole
	 * drained backlog the same latest stock snapshot; both are recorded in plan 18's
	 * Executed section.
	 *
	 * Every point's identity - measurement, tag set, timestamp - is unique to the thing it
	 * describes, so writing it again overwrites the point rather than adding a second one.
	 * `event_id` is the tag that makes that true across events: a transaction id is reused
	 * by the undo of the transaction it names, and nested entrypoints once produced several
	 * events under one, so identity built on the transaction alone let a later event
	 * overwrite an earlier one whenever they shared a second.
	 *
	 * Callers must reject unreadable payloads first - see DescribeUnreadable(). This method
	 * assumes what it is given is readable and produces nothing for a payload that is not.
	 *
	 * @param array[] $payloads Outbox payloads as CaptureEvent() built them
	 * @return string[]
	 */
	public static function BuildLines(array $payloads): array
	{
		$lines = [];

		foreach ($payloads as $payload)
		{
			if (self::DescribeUnreadable($payload) !== null)
			{
				continue;
			}

			$transactionId = (string)$payload['transaction_id'];
			$eventId = (string)$payload['event_id'];
			$occurredAt = InfluxEventWriter::ToNanoseconds((string)$payload['occurred_at']);

			foreach ($payload['bookings'] as $booking)
			{
				// Undone bookings still counted as touching the product when the snapshot was
				// taken - undoing a purchase changes what the stock is worth - but they are
				// not a price the household paid, so they contribute only to stock_value.
				//
				// Only a purchase carries a price paid at all. A consume booking also has a
				// price column, the value of what left stock, and writing it here would make
				// "price paid" mean two different things in one series.
				if ((int)($booking['undone'] ?? 0) === 1
					|| ($booking['transaction_type'] ?? '') !== 'purchase'
					|| ($booking['price'] ?? null) === null)
				{
					continue;
				}

				// booking_id distinguishes two purchases of one product in the same second;
				// event_id distinguishes this event from any later one describing the same
				// booking, such as its undo.
				$lines[] = InfluxEventWriter::BuildLine('price_paid', [
					'product_id' => (int)$booking['product_id'],
					'booking_id' => (int)$booking['booking_id'],
					'transaction_id' => $transactionId,
					'event_id' => $eventId
				], [
					'price' => (float)$booking['price'],
					'amount' => (float)$booking['amount']
				], InfluxEventWriter::ToNanoseconds((string)$booking['row_created_timestamp']));
			}

			foreach ($payload['stock'] as $stock)
			{
				$lines[] = InfluxEventWriter::BuildLine('stock_value', [
					'product_id' => (int)$stock['product_id'],
					'transaction_id' => $transactionId,
					'event_id' => $eventId
				], [
					'value' => (float)$stock['value'],
					'amount' => (float)$stock['amount']
				], $occurredAt);
			}
		}

		return $lines;
	}
}
