<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionEventService;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionMappingService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0041 as ConsumptionEventService enforces it, against a real PostgreSQL schema: source identity,
 * the receipt/booking/failure transactions, mapping and location rules, corrections, deletions,
 * replacements, direct stock undo, explicit linkage and bulk resolution.
 *
 * The booking user is the ambient VICTUAL_USER_ID (9000), as in the other consumption suites; the
 * events of the second user (9302) are booked as 9000 too, which is why stock attribution is not asserted
 * for them. Isolation between users is asserted on the event and mapping rows.
 */
class ConsumptionEventServiceTest extends PgsqlSchemaTestCase
{
	private const ME = 9000;
	private const OTHER = 9302;
	private const STRANGER = 9303;

	private static PDO $db;
	private static ConsumptionEventService $events;
	private static ConsumptionMappingService $mappings;
	private static int $tablet;
	private static int $organizerA;
	private static int $organizerB;
	private static int $sequence = 0;
	/** One instant for every request body of the class, so a repeated body is the same payload (the hash covers occurred_at). */
	private static string $bodyTime;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$bodyTime = (new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
		self::$events = ConsumptionEventService::GetInstance();
		self::$mappings = ConsumptionMappingService::GetInstance();

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'phpunit-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (" . self::OTHER . ", 'ce-other', 'fixture'), (" . self::STRANGER . ", 'ce-stranger', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ([self::ME, self::OTHER] as $id)
		{
			foreach (['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'] as $permission)
			{
				$grant->execute([$id, $permission]);
			}
		}
		$grant->execute([self::STRANGER, 'STOCK_VIEW']);

		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CE tablet') RETURNING id")->fetchColumn();
		self::$organizerA = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CE organizer A') RETURNING id")->fetchColumn();
		self::$organizerB = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CE organizer B') RETURNING id")->fetchColumn();
	}

	// --- Fixtures ----------------------------------------------------------------------------

	private static function product(string $name, float $onA = 0, float $onB = 0): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name . ' ' . ++self::$sequence, self::$organizerA, self::$tablet, self::$tablet, self::$tablet, self::$tablet]);
		$id = (int)$statement->fetchColumn();
		$stock = StockService::GetInstance();

		if ($onA > 0)
		{
			$stock->AddProduct($id, $onA, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerA);
		}
		if ($onB > 0)
		{
			$stock->AddProduct($id, $onB, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerB);
		}

		return $id;
	}

	private static function onHand(int $productId, ?int $location = null): float
	{
		return (float)self::$db->query('SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = ' . $productId . ($location === null ? '' : ' AND location_id = ' . $location))->fetchColumn();
	}

	private static function ref(): string
	{
		return 'med' . ++self::$sequence;
	}

	/** A product mapping with a fixed location; returns the medication_ref. */
	private static function map(int $productId, array $override = [], int $user = self::ME, string $system = 'healthkit', ?string $ref = null): string
	{
		$ref ??= self::ref();
		self::$mappings->Put($user, $system, $ref, $override + [
			'product_id' => $productId, 'unit_labels' => ['tablet'], 'default_quantity' => null,
			'location' => ['mode' => 'fixed', 'location_id' => self::$organizerA], 'effective_from' => '2026-01-01T00:00:00Z',
		]);

		return $ref;
	}

	private static function ago(string $interval): string
	{
		return (new \DateTimeImmutable('-' . $interval, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}

	private static function body(string $ref, array $extra = []): array
	{
		return $extra + ['status' => 'taken', 'medication_ref' => $ref, 'quantity' => 1, 'unit_label' => 'tablet', 'occurred_at' => self::$bodyTime];
	}

	private function put(string $id, array $body, int $user = self::ME, string $system = 'healthkit'): array
	{
		return self::$events->Submit($user, $system, $id, $body);
	}

	private function event(string $id, int $user = self::ME, string $system = 'healthkit'): array
	{
		return self::$events->Get($user, $system, $id);
	}

	private function expectRefusal(callable $work, int $status, string $code): void
	{
		try
		{
			$work();
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame([$status, $code], [$exception->status, $exception->errorCode], $exception->getMessage());
			return;
		}

		self::fail("expected a $status $code refusal");
	}

	private static function uid(): string
	{
		return 'ev' . ++self::$sequence;
	}

	// --- Identity and the receipt ------------------------------------------------------------

	public function testTheIdentityIsValidatedAndMannualIsReserved(): void
	{
		$ref = self::map(self::product('CE identity', 5));

		foreach ([['Healthkit', 'x'], ['', 'x'], ['health kit', 'x'], ['healthkit', ''], ['healthkit', 'a b'], ['healthkit', str_repeat('a', 129)], ['manual', 'x']] as [$system, $id])
		{
			$this->expectRefusal(fn() => $this->put($id, self::body($ref), self::ME, $system), 400, 'invalid_request');
		}
	}

	public function testAFirstEventWithNoMappingIsStoredAndBooksNothing(): void
	{
		$product = self::product('CE no mapping', 5);
		$id = self::uid();

		$result = $this->put($id, self::body('hk:unmapped'));

		self::assertTrue($result['created']);
		self::assertSame(['needs_mapping', null, false], [$result['event']['state'], $result['event']['reason'], $result['event']['replayed']]);
		self::assertSame(5.0, self::onHand($product));
	}

	public function testApprovingAMappingThenRetryBooksOnceAtTheFixedLocation(): void
	{
		$product = self::product('CE retry', 10, 10);
		$ref = self::ref();
		$id = self::uid();
		$this->put($id, self::body($ref, ['quantity' => 2]));
		self::map($product, ['unit_labels' => ['tablet'], 'location' => ['mode' => 'fixed', 'location_id' => self::$organizerB]], self::ME, 'healthkit', $ref);

		$booked = self::$events->Resolve(self::ME, 'healthkit', $id, 'retry');

		self::assertSame('booked', $booked['state']);
		self::assertSame([10.0, 8.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)]);
		self::assertSame([['product_id' => $product, 'amount' => 2.0, 'location_id' => self::$organizerB, 'used_date' => substr(self::$bodyTime, 0, 10)]], $booked['lines']);

		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'retry'), 409, 'invalid_transition');
		self::assertSame(8.0, self::onHand($product, self::$organizerB), 'a second retry booked nothing');
	}

	public function testARepeatedSubmissionDeductsOnceAndReportsAReplay(): void
	{
		$product = self::product('CE replay', 10);
		$ref = self::map($product);
		$id = self::uid();
		$body = self::body($ref);

		$first = $this->put($id, $body);
		$second = $this->put($id, $body);
		$third = $this->put($id, $body + ['source_updated_at' => '2026-10-09T12:00:00Z']);

		self::assertTrue($first['created']);
		self::assertSame('booked', $first['event']['state']);
		self::assertFalse($second['created']);
		self::assertTrue($second['event']['replayed']);
		self::assertTrue($third['event']['replayed'], 'a replay need not repeat its original version, or add one');
		self::assertSame($first['event']['transaction_id'], $second['event']['transaction_id']);
		self::assertSame(9.0, self::onHand($product));
	}

	public function testAnInterruptedReceiptContinuesWithTheNextRequest(): void
	{
		$product = self::product('CE interrupted', 10);
		$ref = self::ref();
		$id = self::uid();
		$body = self::body($ref);
		$this->put($id, $body);
		self::map($product, [], self::ME, 'healthkit', $ref);
		self::$db->exec("UPDATE consumption_events SET state = 'received' WHERE source_event_id = '$id'");

		$result = $this->put($id, $body);

		self::assertSame('booked', $result['event']['state'], 'a received row left by a crash is booked by the next identical request');
		self::assertSame(9.0, self::onHand($product));
	}

	public function testAnEventBeforeEffectiveFromIsDismissedAndNeverBooks(): void
	{
		$product = self::product('CE window', 10);
		$ref = self::map($product, ['effective_from' => self::ago('30 minutes')]);

		$result = $this->put(self::uid(), self::body($ref, ['occurred_at' => self::ago('2 hours')]));

		self::assertSame('dismissed', $result['event']['state']);
		self::assertSame(10.0, self::onHand($product));
	}

	public function testALateEventBooksTheDateWrittenInItsOwnOffset(): void
	{
		$product = self::product('CE late', 10);
		$ref = self::map($product);
		$stamp = (new \DateTimeImmutable('-2 days', new \DateTimeZone('-05:00')))->setTime(21, 30)->format('Y-m-d\TH:i:sP');

		$result = $this->put(self::uid(), self::body($ref, ['occurred_at' => $stamp]));

		self::assertSame(substr($stamp, 0, 10), $result['event']['lines'][0]['used_date']);
		self::assertSame(substr($stamp, 0, 10), self::$db->query('SELECT used_date::text FROM stock_log WHERE transaction_id = ' . self::$db->quote($result['event']['transaction_id']))->fetchColumn());
	}

	public function testAFutureOccurredAtIsRefusedAndNothingIsStored(): void
	{
		$ref = self::map(self::product('CE future', 5));
		$id = self::uid();

		$this->expectRefusal(fn() => $this->put($id, self::body($ref, ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)])), 422, 'future_occurred_at');
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = '$id'")->fetchColumn());
	}

	// --- Quantity, units and the statuses that never book -----------------------------------

	public function testADefaultQuantityIsUsedAndTheServerNeverInventsOne(): void
	{
		$product = self::product('CE default', 10);
		$with = self::map($product, ['default_quantity' => 2]);
		$without = self::map($product);

		$a = $this->put(self::uid(), ['status' => 'taken', 'medication_ref' => $with, 'occurred_at' => self::ago('1 hour')]);
		$b = $this->put(self::uid(), ['status' => 'taken', 'medication_ref' => $without, 'occurred_at' => self::ago('1 hour')]);

		self::assertSame('booked', $a['event']['state']);
		self::assertSame(8.0, self::onHand($product));
		self::assertSame(['needs_review', 'quantity_missing'], [$b['event']['state'], $b['event']['reason']]);
	}

	public function testAnUnseenUnitLabelNeedsReviewShowsTheLabelAndApprovingItBooks(): void
	{
		$product = self::product('CE unit', 10);
		$ref = self::map($product, ['unit_labels' => []]);
		$first = self::uid();

		$held = $this->put($first, self::body($ref, ['unit_label' => 'tab']));
		self::assertSame(['needs_review', 'unit_unconfirmed', 'tab'], [$held['event']['state'], $held['event']['reason'], $held['event']['unit_label_seen']]);
		self::assertSame(10.0, self::onHand($product));

		$booked = self::$events->Resolve(self::ME, 'healthkit', $first, 'approve_unit');
		self::assertSame('booked', $booked['state']);
		self::assertSame(['tab'], self::$mappings->Get(self::ME, 'healthkit', $ref)['unit_labels']);

		$later = $this->put(self::uid(), self::body($ref, ['unit_label' => 'tab']));
		self::assertSame('booked', $later['event']['state'], 'later events with the approved label book directly');
		self::assertSame(8.0, self::onHand($product));
	}

	public function testSkippedUnansweredAndScheduledEventsWithNoRowCreateNoRow(): void
	{
		$product = self::product('CE skipped', 10);
		$ref = self::map($product);

		foreach (['skipped', 'unanswered', 'scheduled', 'not_logged'] as $status)
		{
			$id = self::uid();
			$result = $this->put($id, ['status' => $status, 'medication_ref' => $ref]);

			self::assertSame('no_consumption', $result['event']['state']);
			self::assertFalse($result['created']);
			self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = '$id'")->fetchColumn(), "$status leaves no row");
		}

		self::assertSame(10.0, self::onHand($product));
	}

	public function testAStatusOnlyRequestKeepsTheMedicationQuantityAndUnitOfTheStoredEvent(): void
	{
		$product = self::product('CE status only', 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref, ['quantity' => 2]));

		// Outside the automatic-void window, so the event waits for a person and stays findable by medication.
		self::$db->exec("UPDATE consumption_events SET occurred_at = now() - interval '30 days' WHERE source_event_id = '$id'");
		$held = $this->put($id, ['status' => 'not_logged']);

		self::assertSame(['needs_review', 'source_deleted'], [$held['event']['state'], $held['event']['reason']]);
		$row = self::$db->query("SELECT medication_ref, quantity, unit_label, submitted_status FROM consumption_events WHERE source_event_id = '$id'")->fetch(PDO::FETCH_ASSOC);
		self::assertSame([$ref, 2.0, 'tablet', 'not_logged'], [$row['medication_ref'], (float)$row['quantity'], $row['unit_label'], $row['submitted_status']]);

		$bulk = self::$events->BulkResolve(self::ME, 'void', null, ['source_system' => 'healthkit', 'medication_ref' => $ref, 'state' => 'needs_review', 'reason' => 'source_deleted']);
		self::assertCount(1, $bulk['results'], 'the filter still finds the event');
		self::assertSame(10.0, self::onHand($product));
	}

	public function testANewRowAnswersReplayedFalseAndARepeatAnswersTrue(): void
	{
		$ref = self::map(self::product('CE replayed flag', 5));
		$id = self::uid();

		self::assertFalse($this->put($id, self::body($ref))['event']['replayed']);
		self::assertTrue($this->put($id, self::body($ref))['event']['replayed']);
	}

	public function testANotLoggedStatusAfterBookingVoidsWithinTheWindowAndStaysVoided(): void
	{
		$product = self::product('CE notlogged', 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref));
		self::assertSame(9.0, self::onHand($product));

		$voided = $this->put($id, ['status' => 'not_logged', 'medication_ref' => $ref]);

		self::assertSame('voided', $voided['event']['state']);
		self::assertSame(10.0, self::onHand($product));
		self::assertSame('voided', $this->put($id, ['status' => 'not_logged', 'medication_ref' => $ref])['event']['state'], 'the same request again changes nothing');
		self::assertSame(10.0, self::onHand($product));
	}

	public function testAVoidOlderThanTheWindowWaitsForAPersonWhoCanVoidOrKeep(): void
	{
		$product = self::product('CE old void', 10);
		$ref = self::map($product);
		$keep = self::uid();
		$void = self::uid();
		$old = self::ago('10 days');
		// The event to void is booked last: an earlier booking of the same purchase cannot be undone while a
		// later one depends on it (ADR-0036), which is the undo_refused outcome the next test pins.
		$this->put($keep, self::body($ref, ['occurred_at' => $old]));
		$this->put($void, self::body($ref, ['occurred_at' => $old]));
		self::assertSame(8.0, self::onHand($product));

		$held = self::$events->Delete(self::ME, 'healthkit', $void, 'entered_in_error');
		self::$events->Delete(self::ME, 'healthkit', $keep, null);

		self::assertSame(['needs_review', 'source_deleted'], [$held['state'], $held['reason']]);
		self::assertSame(8.0, self::onHand($product), 'nothing was restored automatically');

		self::assertSame('voided', self::$events->Resolve(self::ME, 'healthkit', $void, 'void')['state']);
		self::assertSame(9.0, self::onHand($product));
		self::assertSame('booked', self::$events->Resolve(self::ME, 'healthkit', $keep, 'keep')['state']);
		self::assertSame(9.0, self::onHand($product), 'keep leaves the booking');
	}

	public function testASourceThatStoppedHoldingTheRecordDoesNotRestoreStock(): void
	{
		$product = self::product('CE removed', 10);
		$ref = self::map($product);

		foreach (['history_cleared', 'medication_archived', 'access_revoked'] as $reason)
		{
			$id = self::uid();
			$this->put($id, self::body($ref));
			$result = self::$events->Delete(self::ME, 'healthkit', $id, $reason);

			self::assertSame('booked', $result['state']);
			self::assertSame($reason, $result['source_removed_reason']);
			self::assertNotNull($result['source_removed_at']);
		}

		self::assertSame(7.0, self::onHand($product), 'three doses stay deducted');
		$this->expectRefusal(fn() => self::$events->Delete(self::ME, 'healthkit', self::uid(), 'because'), 400, 'invalid_request');
	}

	public function testDeletingAnEventThatNeverBookedVoidsOrDismisses(): void
	{
		$a = self::uid();
		$b = self::uid();
		$this->put($a, self::body('hk:never-mapped-a'));
		$this->put($b, self::body('hk:never-mapped-b'));

		self::assertSame('voided', self::$events->Delete(self::ME, 'healthkit', $a, 'entered_in_error')['state']);
		self::assertSame('dismissed', self::$events->Delete(self::ME, 'healthkit', $b, 'history_cleared')['state']);
		$this->expectRefusal(fn() => self::$events->Delete(self::ME, 'healthkit', 'ev-none', 'unknown'), 404, 'not_found');
	}

	// --- Versions and corrections ------------------------------------------------------------

	public function testACorrectionOfTheQuantityUndoesAndRebooksNetOneDeduction(): void
	{
		$product = self::product('CE correction', 10);
		$ref = self::map($product);
		$id = self::uid();
		$first = $this->put($id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));

		$second = $this->put($id, self::body($ref, ['quantity' => 3, 'source_updated_at' => '2026-10-09T11:00:00Z']));

		self::assertSame('booked', $second['event']['state']);
		self::assertSame(2, $second['event']['revision']);
		self::assertNotSame($first['event']['transaction_id'], $second['event']['transaction_id']);
		self::assertSame(7.0, self::onHand($product), 'one deduction of 3, not 1 and 3');
		self::assertSame(3.0, array_sum(array_column($second['event']['lines'], 'amount')));
	}

	public function testOnlyTheTimeOfDayChangingLeavesTheBookingAlone(): void
	{
		$product = self::product('CE time of day', 10);
		$ref = self::map($product);
		$id = self::uid();
		$base = (new \DateTimeImmutable('today 08:00:00', new \DateTimeZone('UTC')));
		$this->put($id, self::body($ref, ['occurred_at' => $base->format('Y-m-d\TH:i:s\Z')]));
		$before = $this->event($id);

		$after = $this->put($id, self::body($ref, ['occurred_at' => $base->modify('+1 hour')->format('Y-m-d\TH:i:s\Z')]));

		self::assertSame($before['transaction_id'], $after['event']['transaction_id']);
		self::assertSame(1, $after['event']['revision']);
		self::assertSame(substr($base->modify('+1 hour')->format('c'), 11, 5), substr($after['event']['occurred_at'], 11, 5));
		self::assertSame(9.0, self::onHand($product));
	}

	public function testAnOlderVersionIsStaleAndAnEqualVersionWithAnotherPayloadConflicts(): void
	{
		$product = self::product('CE versions', 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));

		$stale = $this->put($id, self::body($ref, ['quantity' => 5, 'source_updated_at' => '2026-10-09T09:00:00Z']));
		self::assertTrue($stale['event']['stale']);
		self::assertSame(9.0, self::onHand($product));

		$this->expectRefusal(fn() => $this->put($id, self::body($ref, ['quantity' => 5, 'source_updated_at' => '2026-10-09T10:00:00Z'])), 409, 'same_version_different_payload');
		self::assertSame(9.0, self::onHand($product));
	}

	public function testAFailedCorrectionLeavesTheOriginalBookingAndRecordsTheReason(): void
	{
		$product = self::product('CE failed correction', 3);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));
		self::assertSame(2.0, self::onHand($product));
		$original = $this->event($id)['transaction_id'];

		$result = $this->put($id, self::body($ref, ['quantity' => 10, 'source_updated_at' => '2026-10-09T11:00:00Z']));

		self::assertSame(['needs_review', 'insufficient_stock'], [$result['event']['state'], $result['event']['reason']]);
		self::assertSame(2.0, self::onHand($product), 'the original deduction is still in place');
		self::assertSame($original, $result['event']['transaction_id']);
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE undone = 0 AND transaction_id = ' . self::$db->quote($original))->fetchColumn());
	}

	public function testAChangedPayloadAfterADirectUndoNeedsReviewAndNeverRebooks(): void
	{
		$product = self::product('CE changed after undo', 10);
		$ref = self::map($product);
		$id = self::uid();
		$booked = $this->put($id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));
		StockService::GetInstance()->UndoTransaction($booked['event']['transaction_id']);

		$replay = $this->put($id, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));
		self::assertSame('undone', $replay['event']['state'], 'synchronization never rebooks an undone event');
		self::assertSame(10.0, self::onHand($product));

		$changed = $this->put($id, self::body($ref, ['quantity' => 2, 'source_updated_at' => '2026-10-09T12:00:00Z']));
		self::assertSame(['needs_review', 'changed_after_undo'], [$changed['event']['state'], $changed['event']['reason']]);
		self::assertSame(10.0, self::onHand($product));

		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'rebook'), 409, 'invalid_transition');
		self::assertSame('booked', self::$events->Resolve(self::ME, 'healthkit', $id, 'retry')['state'], 'an explicit retry books the corrected payload');
		self::assertSame(8.0, self::onHand($product));
	}

	// --- Replacement -------------------------------------------------------------------------

	public function testReplacesVoidsTheOldEventAndBooksTheNewOneInOneTransaction(): void
	{
		$product = self::product('CE replaces', 10);
		$ref = self::map($product);
		$old = self::uid();
		$new = self::uid();
		$this->put($old, self::body($ref));

		$result = $this->put($new, self::body($ref, ['quantity' => 2, 'replaces' => $old]));

		self::assertSame('booked', $result['event']['state']);
		self::assertSame(['source_event_id' => $old, 'state' => 'voided'], $result['event']['replaces']);
		self::assertSame('voided', $this->event($old)['state']);
		self::assertSame(8.0, self::onHand($product), 'net one deduction of 2');
	}

	public function testAFailedReplacementLeavesTheOldBookingAndBooksNothingNew(): void
	{
		$product = self::product('CE replaces fails', 3);
		$ref = self::map($product);
		$old = self::uid();
		$new = self::uid();
		$this->put($old, self::body($ref));

		$result = $this->put($new, self::body($ref, ['quantity' => 9, 'replaces' => $old]));

		self::assertSame(['needs_review', 'insufficient_stock'], [$result['event']['state'], $result['event']['reason']]);
		self::assertSame('needs_review', $result['event']['replaces']['state']);
		self::assertSame('booked', $this->event($old)['state']);
		self::assertSame(2.0, self::onHand($product));
	}

	public function testDeleteThenCreateAndCreateThenDeleteBothLeaveOneDeduction(): void
	{
		$product = self::product('CE delete recreate', 10);
		$ref = self::map($product);
		$a = self::uid();
		$b = self::uid();
		$this->put($a, self::body($ref));

		self::$events->Delete(self::ME, 'healthkit', $a, 'entered_in_error');
		$this->put($b, self::body($ref));
		self::assertSame(9.0, self::onHand($product), 'delete then create');

		$c = self::uid();
		$d = self::uid();
		$this->put($c, self::body($ref));
		$this->put($d, self::body($ref));
		self::$events->Delete(self::ME, 'healthkit', $d, 'entered_in_error');
		self::assertSame(8.0, self::onHand($product), 'create then delete leaves the one that was not deleted');
	}

	public function testAVoidWhoseUndoIsRefusedBecauseALaterBookingDependsOnItNeedsReview(): void
	{
		$product = self::product('CE undo refused', 10);
		$ref = self::map($product);
		$first = self::uid();
		$second = self::uid();
		$this->put($first, self::body($ref));
		$this->put($second, self::body($ref));

		$result = self::$events->Delete(self::ME, 'healthkit', $first, 'entered_in_error');

		self::assertSame(['needs_review', 'undo_refused'], [$result['state'], $result['reason']]);
		self::assertSame(8.0, self::onHand($product), 'nothing was restored');
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM stock_log WHERE undone = 0 AND transaction_type = 'consume' AND product_id = $product AND transaction_id = " . self::$db->quote($this->event($first)['transaction_id']))->fetchColumn(), 'the original booking is still live');
	}

	// --- Direct stock undo -------------------------------------------------------------------

	public function testAnUndoneEventIsNeverRebookedBySynchronizationAndRebookIsExplicit(): void
	{
		$product = self::product('CE undo', 10);
		$ref = self::map($product);
		$id = self::uid();
		$booked = $this->put($id, self::body($ref));
		StockService::GetInstance()->UndoTransaction($booked['event']['transaction_id']);

		foreach ([1, 2] as $unused)
		{
			self::assertSame('undone', $this->put($id, self::body($ref))['event']['state']);
		}
		self::assertSame(10.0, self::onHand($product));

		$again = self::$events->Resolve(self::ME, 'healthkit', $id, 'rebook');
		self::assertSame(['booked', 2], [$again['state'], $again['revision']]);
		self::assertSame(9.0, self::onHand($product));
	}

	public function testUndoingOneLineOfATwoLineRecipeIsPartiallyUndoneAndRebookBooksEveryLineOnce(): void
	{
		$a = self::product('CE partial a', 10);
		$b = self::product('CE partial b', 10);
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipe = $recipes->CreateRecipe('CE two lines ' . self::uid(), null, [['product_id' => $a, 'amount' => 2, 'qu_id' => self::$tablet], ['product_id' => $b, 'amount' => 1, 'qu_id' => self::$tablet]], self::ME);
		$ref = self::ref();
		self::$mappings->Put(self::ME, 'healthkit', $ref, ['recipe_id' => $recipe, 'unit_labels' => ['dose'], 'location' => ['mode' => 'fixed', 'location_id' => self::$organizerA], 'effective_from' => '2026-01-01T00:00:00Z']);
		$id = self::uid();
		$booked = $this->put($id, self::body($ref, ['unit_label' => 'dose']));
		self::assertSame([8.0, 9.0], [self::onHand($a), self::onHand($b)]);

		$line = self::$db->query('SELECT id FROM stock_log WHERE undone = 0 AND product_id = ' . $a . ' AND transaction_id = ' . self::$db->quote($booked['event']['transaction_id']))->fetchColumn();
		StockService::GetInstance()->UndoBooking((int)$line, true);

		$partial = $this->put($id, self::body($ref, ['unit_label' => 'dose']));
		self::assertSame(['needs_review', 'partially_undone'], [$partial['event']['state'], $partial['event']['reason']]);
		self::assertSame([10.0, 9.0], [self::onHand($a), self::onHand($b)], 'no rebooking');

		$rebooked = self::$events->Resolve(self::ME, 'healthkit', $id, 'rebook');
		self::assertSame('booked', $rebooked['state']);
		self::assertSame([8.0, 9.0], [self::onHand($a), self::onHand($b)], 'the remainder was undone first, so each line is deducted once');
	}

	// --- Recipes and atomic booking ----------------------------------------------------------

	public function testALaterLineWithTooLittleStockLeavesNothingDeducted(): void
	{
		$a = self::product('CE atomic a', 10);
		$b = self::product('CE atomic b', 1);
		$recipe = ConsumptionRecipeService::GetInstance()->CreateRecipe('CE atomic ' . self::uid(), null, [['product_id' => $a, 'amount' => 3, 'qu_id' => self::$tablet], ['product_id' => $b, 'amount' => 2, 'qu_id' => self::$tablet]], self::ME);
		$ref = self::ref();
		self::$mappings->Put(self::ME, 'healthkit', $ref, ['recipe_id' => $recipe, 'unit_labels' => ['dose'], 'location' => ['mode' => 'fixed', 'location_id' => self::$organizerA], 'effective_from' => '2026-01-01T00:00:00Z']);

		$result = $this->put(self::uid(), self::body($ref, ['unit_label' => 'dose']));

		self::assertSame(['needs_review', 'insufficient_stock'], [$result['event']['state'], $result['event']['reason']]);
		self::assertSame([10.0, 1.0], [self::onHand($a), self::onHand($b)]);
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE transaction_type = \'consume\' AND product_id IN (' . $a . ',' . $b . ')')->fetchColumn());
	}

	public function testARevokedRecipeShareNeedsReviewAsRecipeUnavailable(): void
	{
		$product = self::product('CE revoked', 10);
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipe = $recipes->CreateRecipe('CE shared ' . self::uid(), null, [['product_id' => $product, 'amount' => 1, 'qu_id' => self::$tablet]], self::OTHER);
		$recipes->SetShare($recipe, self::ME, ['consume' => true], self::OTHER);
		$ref = self::ref();
		self::$mappings->Put(self::ME, 'healthkit', $ref, ['recipe_id' => $recipe, 'unit_labels' => ['dose'], 'location' => ['mode' => 'fixed', 'location_id' => self::$organizerA], 'effective_from' => '2026-01-01T00:00:00Z']);
		$recipes->RemoveShare($recipe, self::ME, self::OTHER);

		$result = $this->put(self::uid(), self::body($ref, ['unit_label' => 'dose']));

		self::assertSame(['needs_review', 'recipe_unavailable'], [$result['event']['state'], $result['event']['reason']]);
		self::assertSame(10.0, self::onHand($product));
	}

	// --- Locations ---------------------------------------------------------------------------

	public function testAFixedLocationNeverFallsBackToAnotherOrganizer(): void
	{
		$product = self::product('CE fixed', 1, 20);
		$ref = self::map($product);

		$result = $this->put(self::uid(), self::body($ref, ['quantity' => 3]));

		self::assertSame(['needs_review', 'insufficient_stock'], [$result['event']['state'], $result['event']['reason']]);
		self::assertSame([1.0, 20.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)]);
	}

	public function testAFixedLocationExcludesStockHeldInAChildLocation(): void
	{
		$product = self::product('CE child', 0, 0);
		$child = (int)self::$db->query("INSERT INTO locations (name, parent_location_id) VALUES ('CE child organizer', " . self::$organizerA . ") RETURNING id")->fetchColumn();
		StockService::GetInstance()->AddProduct($product, 5, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $child);
		$ref = self::map($product);

		$result = $this->put(self::uid(), self::body($ref));

		self::assertSame(['needs_review', 'insufficient_stock'], [$result['event']['state'], $result['event']['reason']], 'the organizer holds nothing itself');
		self::assertSame(5.0, self::onHand($product));
	}

	public function testSingleModeNeedsExactlyOneLocationThatHoldsEnough(): void
	{
		$product = self::product('CE single', 5, 5);
		$ref = self::map($product, ['location' => ['mode' => 'single']]);

		$ambiguous = $this->put(self::uid(), self::body($ref));
		self::assertSame(['needs_review', 'ambiguous_location', [self::$organizerA, self::$organizerB]], [$ambiguous['event']['state'], $ambiguous['event']['reason'], $ambiguous['event']['candidate_location_ids']]);
		self::assertSame(10.0, self::onHand($product));

		$only = self::product('CE single only', 5, 1);
		$ref2 = self::map($only, ['location' => ['mode' => 'single']]);
		$booked = $this->put(self::uid(), self::body($ref2, ['quantity' => 3]));
		self::assertSame('booked', $booked['event']['state']);
		self::assertSame(self::$organizerA, $booked['event']['lines'][0]['location_id']);
	}

	public function testExplicitModeNeedsTheEventToNameTheLocation(): void
	{
		$product = self::product('CE explicit', 5, 5);
		$ref = self::map($product, ['location' => ['mode' => 'explicit']]);

		$without = $this->put(self::uid(), self::body($ref));
		self::assertSame(['needs_review', 'ambiguous_location'], [$without['event']['state'], $without['event']['reason']]);

		$with = $this->put(self::uid(), self::body($ref, ['location_id' => self::$organizerB]));
		self::assertSame('booked', $with['event']['state']);
		self::assertSame([5.0, 4.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)]);
	}

	public function testALateEventIsChargedToTheMappingsCurrentFixedLocation(): void
	{
		$product = self::product('CE switch', 5, 5);
		$ref = self::map($product);
		self::map($product, ['location' => ['mode' => 'fixed', 'location_id' => self::$organizerB]], self::ME, 'healthkit', $ref);

		$this->put(self::uid(), self::body($ref, ['occurred_at' => self::ago('3 days')]));

		self::assertSame([5.0, 4.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)], 'documented limitation: the mapping read at processing time applies');
	}

	public function testAMappingEditNeverChangesWhatAnEarlierEventBooked(): void
	{
		$product = self::product('CE history', 10, 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref));
		self::map($product, ['location' => ['mode' => 'fixed', 'location_id' => self::$organizerB]], self::ME, 'healthkit', $ref);

		$read = $this->event($id);

		self::assertSame(self::$organizerA, $read['lines'][0]['location_id']);
		self::assertSame([9.0, 10.0], [self::onHand($product, self::$organizerA), self::onHand($product, self::$organizerB)]);
	}

	public function testARefusalThePreCheckCannotClassifyBecomesAPrivateStockError(): void
	{
		$product = self::product('CE measured', 0);
		$stock = StockService::GetInstance();
		$stock->AddProduct($product, 1, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$organizerA);
		$row = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product)->fetchColumn();
		$stock->OpenProduct($product, 1);
		$stock->MeasureStockEntry($row, ['amount' => 0.6, 'qu_id' => self::$tablet]);
		$ref = self::map($product);

		$result = $this->put(self::uid(), self::body($ref, ['quantity' => 0.5]));

		self::assertSame(['needs_review', 'stock_error'], [$result['event']['state'], $result['event']['reason']]);
		self::assertStringContainsString('measured container', $result['event']['message'], 'the text is kept for the person it belongs to');
		self::assertSame(1.0, self::onHand($product), 'the refusal rolled back');
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE transaction_type = \'consume\' AND product_id = ' . $product)->fetchColumn());
	}

	// --- Isolation ---------------------------------------------------------------------------

	public function testTwoUsersWithTheSameSourceIdsHaveSeparateEventsAndCannotReadEachOther(): void
	{
		$product = self::product('CE users', 10);
		$mineRef = self::map($product, [], self::ME, 'healthkit', 'shared-ref');
		self::map($product, [], self::OTHER, 'healthkit', 'shared-ref');
		$body = self::body('shared-ref');

		$mine = $this->put('same-id', $body, self::ME);
		$theirs = $this->put('same-id', $body, self::OTHER);

		self::assertTrue($mine['created']);
		self::assertTrue($theirs['created'], 'the key space is per user');
		self::assertNotSame($mine['event']['transaction_id'], $theirs['event']['transaction_id']);
		self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = 'same-id'")->fetchColumn());

		$this->expectRefusal(fn() => self::$events->Get(self::STRANGER, 'healthkit', 'same-id'), 404, 'not_found');
		$this->expectRefusal(fn() => self::$events->Resolve(self::STRANGER, 'healthkit', 'same-id', 'dismiss'), 403, 'permission_missing');
		$this->expectRefusal(fn() => self::$events->Delete(self::OTHER, 'healthkit', 'only-mine', null), 404, 'not_found');
		self::assertSame([], self::$events->ListEvents(self::STRANGER));
		self::assertCount(1, self::$events->ListEvents(self::OTHER));
		self::assertSame($mineRef, 'shared-ref');
	}

	public function testTheApiKeyIsRecordedForAuditAndIsNotPartOfTheIdentity(): void
	{
		$product = self::product('CE key', 10);
		$ref = self::map($product);
		self::$db->exec("INSERT INTO api_keys (id, api_key, user_id, key_type) VALUES (7001, 'ce-key-a', " . self::ME . ", 'default'), (7002, 'ce-key-b', " . self::ME . ", 'default')");
		$id = self::uid();

		$first = self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref), 7001);
		$second = self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref), 7002);

		self::assertTrue($second['event']['replayed'], 'a rotated key finds the same event');
		self::assertSame($first['event']['transaction_id'], $second['event']['transaction_id']);
		self::assertSame(7001, (int)self::$db->query("SELECT api_key_id FROM consumption_events WHERE source_event_id = '$id'")->fetchColumn(), 'the key that received the event');
		self::assertSame(9.0, self::onHand($product));
	}

	// --- Linking and duplicates --------------------------------------------------------------

	public function testManualThenImportedThenLinkLeavesOneDeductionAndTheEventLinked(): void
	{
		$product = self::product('CE link', 10);
		$ref = self::map($product);
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipe = $recipes->CreateRecipe('CE link recipe ' . self::uid(), null, [['product_id' => $product, 'amount' => 1, 'qu_id' => self::$tablet]], self::ME);
		$manual = $recipes->Consume($recipe, self::uid(), null, null, self::ME);
		$id = self::uid();

		$imported = $this->put($id, self::body($ref, ['occurred_at' => self::ago('5 minutes')]));
		self::assertSame(8.0, self::onHand($product));
		self::assertSame([['transaction_id' => $manual['transaction_id']]], array_map(fn($d) => ['transaction_id' => $d['transaction_id']], $imported['event']['possible_duplicates']), 'suggested, and the import was booked anyway');

		$linked = self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $manual['transaction_id']);

		self::assertSame(['linked', $manual['transaction_id']], [$linked['state'], $linked['transaction_id']]);
		self::assertSame(9.0, self::onHand($product), 'exactly one deduction remains');
		self::assertSame([], $linked['possible_duplicates']);
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM stock_log WHERE undone = 0 AND transaction_type = \'consume\' AND product_id = ' . $product)->fetchColumn());
		self::assertSame('linked', $this->put($id, self::body($ref, ['occurred_at' => self::ago('5 minutes')]))['event']['state'] ?? null, 'a replay of a linked event stays linked');
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $manual['transaction_id']), 409, 'invalid_transition');
	}

	public function testTwoSimilarDosesThatAreNotLinkedStayTwoDeductions(): void
	{
		$product = self::product('CE not linked', 10);
		$ref = self::map($product);
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipe = $recipes->CreateRecipe('CE no link ' . self::uid(), null, [['product_id' => $product, 'amount' => 1, 'qu_id' => self::$tablet]], self::ME);
		$recipes->Consume($recipe, self::uid(), null, null, self::ME);

		$imported = $this->put(self::uid(), self::body($ref, ['occurred_at' => self::ago('2 minutes')]));

		self::assertSame(8.0, self::onHand($product));
		self::assertCount(1, $imported['event']['possible_duplicates']);
	}

	public function testALinkNeedsAnUnreversedConsumeOfTheMappedProductsThatNothingElseHolds(): void
	{
		$product = self::product('CE link refused', 10);
		$other = self::product('CE link other', 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref));
		$stock = StockService::GetInstance();

		$wrongProduct = null;
		$stock->ConsumeProduct($other, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $wrongProduct);
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $wrongProduct), 422, 'invalid_link');

		$taken = self::uid();
		$this->put($taken, self::body($ref));
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $this->event($taken)['transaction_id']), 422, 'invalid_link');
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', 'no-such-transaction'), 422, 'invalid_link');

		$mine = null;
		$stock->ConsumeProduct($product, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $mine);
		$stock->UndoTransaction($mine);
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $mine), 422, 'invalid_link');
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', null), 400, 'invalid_request');
	}

	// --- Resolution, batch and bulk ----------------------------------------------------------

	public function testAnActionNotAllowedInTheCurrentStateIsAnInvalidTransition(): void
	{
		$product = self::product('CE transitions', 10);
		$ref = self::map($product);
		$id = self::uid();
		$this->put($id, self::body($ref));

		foreach (['retry', 'rebook', 'approve_unit', 'void', 'keep', 'dismiss'] as $action)
		{
			$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, $action), 409, 'invalid_transition');
		}
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'obliterate'), 400, 'invalid_request');
	}

	public function testDismissIsTerminalAndAnEventNeverBooksAfterIt(): void
	{
		$product = self::product('CE dismiss', 10);
		$ref = self::ref();
		$id = self::uid();
		$this->put($id, self::body($ref));
		self::assertSame('dismissed', self::$events->Resolve(self::ME, 'healthkit', $id, 'dismiss')['state']);
		self::map($product, [], self::ME, 'healthkit', $ref);

		self::assertSame('dismissed', $this->put($id, self::body($ref))['event']['state']);
		self::assertSame('dismissed', $this->put($id, self::body($ref, ['quantity' => 4, 'source_updated_at' => '2026-10-09T12:00:00Z']))['event']['state']);
		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'retry'), 409, 'invalid_transition');
		self::assertSame(10.0, self::onHand($product));
	}

	public function testBulkResolutionHandlesFiftyAtATimeReportsRemainingAndIsolatesItems(): void
	{
		$product = self::product('CE bulk', 200);
		$ref = self::map($product);
		$ids = [];
		for ($i = 0; $i < 120; $i++)
		{
			$ids[] = $id = self::uid();
			$this->put($id, self::body($ref, ['occurred_at' => self::ago((200 + $i) . ' hours')]));
			self::$events->Delete(self::ME, 'healthkit', $id, null);
		}
		self::assertSame(80.0, self::onHand($product));
		$filter = ['source_system' => 'healthkit', 'medication_ref' => $ref, 'state' => 'needs_review', 'reason' => 'source_deleted'];

		$remaining = [];
		foreach ([50, 50, 20] as $handled)
		{
			$result = self::$events->BulkResolve(self::ME, 'void', null, $filter);
			self::assertCount($handled, $result['results']);
			self::assertSame([200], array_unique(array_column($result['results'], 'http_status')));
			$remaining[] = $result['remaining'];
		}

		self::assertSame([70, 20, 0], $remaining);
		self::assertSame(200.0, self::onHand($product));
		self::assertSame(120, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE medication_ref = '$ref' AND state = 'voided'")->fetchColumn());

		$mixed = self::$events->BulkResolve(self::ME, 'keep', [['source_system' => 'healthkit', 'source_event_id' => $ids[0]], ['source_system' => 'healthkit', 'source_event_id' => $ids[1]]], null);
		self::assertSame([409, 409], array_column($mixed['results'], 'http_status'), 'an event already voided reports invalid_transition');
		self::assertSame('invalid_transition', $mixed['results'][0]['error']['error']);
	}

	public function testBulkRebookFindsEventsUndoneInTheStockJournal(): void
	{
		$product = self::product('CE bulk undone', 10);
		$ref = self::map($product);
		$ids = [];
		for ($i = 0; $i < 3; $i++)
		{
			$ids[] = $id = self::uid();
			$this->put($id, self::body($ref, ['occurred_at' => self::ago((5 + $i) . ' hours')]));
		}
		// Undo newest-first so ADR-0036 allows each, leaving the stored state `booked` until an event is touched.
		foreach (array_reverse($ids) as $id)
		{
			StockService::GetInstance()->UndoTransaction($this->event($id)['transaction_id']);
		}
		self::assertSame(10.0, self::onHand($product));
		self::assertSame('booked', self::$db->query("SELECT state FROM consumption_events WHERE source_event_id = '{$ids[0]}'")->fetchColumn(), 'stored state has not caught up');

		$result = self::$events->BulkResolve(self::ME, 'rebook', null, ['source_system' => 'healthkit', 'medication_ref' => $ref, 'state' => 'undone']);

		self::assertSame([200, 200, 200], array_column($result['results'], 'http_status'));
		self::assertSame(0, $result['remaining']);
		self::assertSame(7.0, self::onHand($product));
	}

	public function testAnEventNamesItsMedicationReference(): void
	{
		$ref = self::map(self::product('CE medication ref', 5));

		self::assertSame($ref, $this->put(self::uid(), self::body($ref))['event']['medication_ref']);
	}

	public function testBulkResolutionOnlyEverMatchesTheCallersOwnEvents(): void
	{
		$product = self::product('CE bulk privacy', 10);
		self::map($product, [], self::OTHER, 'healthkit', 'bulk-private');
		$this->put('theirs', self::body('bulk-private'), self::OTHER);
		self::$events->Delete(self::OTHER, 'healthkit', 'theirs', null);

		$result = self::$events->BulkResolve(self::ME, 'void', null, ['source_system' => 'healthkit', 'medication_ref' => 'bulk-private', 'state' => 'needs_review']);
		self::assertSame([[], 0], [$result['results'], $result['remaining']]);

		$listed = self::$events->BulkResolve(self::ME, 'void', [['source_system' => 'healthkit', 'source_event_id' => 'theirs']], null);
		self::assertSame(404, $listed['results'][0]['http_status']);
		self::assertSame('needs_review', self::$events->Get(self::OTHER, 'healthkit', 'theirs')['state']);
	}

	public function testABatchAppliesEachItemOnItsOwn(): void
	{
		$product = self::product('CE batch', 10);
		$ref = self::map($product);
		$good = self::uid();
		$bad = self::uid();
		$conflict = self::uid();
		$this->put($conflict, self::body($ref, ['source_updated_at' => '2026-10-09T10:00:00Z']));

		$results = self::$events->Batch(self::ME, [
			['source_system' => 'healthkit', 'source_event_id' => $good] + self::body($ref),
			['source_system' => 'healthkit', 'source_event_id' => $bad, 'status' => 'dancing'],
			['source_system' => 'healthkit', 'source_event_id' => $conflict] + self::body($ref, ['quantity' => 4, 'source_updated_at' => '2026-10-09T10:00:00Z']),
			['source_system' => 'manual', 'source_event_id' => 'x'] + self::body($ref),
		]);

		self::assertSame([201, 400, 409, 400], array_column($results, 'http_status'));
		self::assertSame('booked', $results[0]['event']['state']);
		self::assertSame('same_version_different_payload', $results[2]['error']['error']);
		self::assertSame(8.0, self::onHand($product), 'the good item and the earlier event; the failures rolled nothing else back');
		$this->expectRefusal(fn() => self::$events->Batch(self::ME, []), 400, 'invalid_request');
		$this->expectRefusal(fn() => self::$events->Batch(self::ME, array_fill(0, 51, ['source_system' => 'healthkit', 'source_event_id' => 'x'])), 400, 'invalid_request');
	}

	public function testTheServiceRefusesToRunInsideAnOpenTransaction(): void
	{
		$ref = self::map(self::product('CE guard', 5));
		self::$db->beginTransaction();

		try
		{
			self::$events->Submit(self::ME, 'healthkit', self::uid(), self::body($ref));
			self::fail('expected a LogicException');
		}
		catch (\LogicException $exception)
		{
			self::assertStringContainsString('no transaction open', $exception->getMessage());
		}
		finally
		{
			self::$db->rollBack();
		}
	}

	public function testRemovingAMappingRemovesItsTombstonesAndKeepsWhatBooked(): void
	{
		$product = self::product('CE tombstones', 10);
		$ref = self::map($product);
		$booked = self::uid();
		$voided = self::uid();
		$this->put($booked, self::body($ref));
		$this->put($voided, self::body($ref));
		self::$events->Delete(self::ME, 'healthkit', $voided, 'entered_in_error');
		self::assertSame('voided', $this->event($voided)['state']);

		self::$mappings->Delete(self::ME, 'healthkit', $ref);

		$this->expectRefusal(fn() => $this->event($voided), 404, 'not_found');
		self::assertSame('booked', $this->event($booked)['state']);
		self::assertSame(9.0, self::onHand($product));
	}
}
