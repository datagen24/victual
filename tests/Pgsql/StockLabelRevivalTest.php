<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\Labels\StockLabelRevivalService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0037 (issue #612): an undo of the whole-row consumption that retired a stock-entry label
 * revives that label, and no other, on the row the undo rebuilds under its original id - inside
 * a window of StockService::LABEL_REVIVAL_WINDOW_SECONDS - and tells the caller what happened
 * through the Victual-Label-Revival response header (section 12a).
 *
 * The worked examples of the record, against the real StockService with the hook in place.
 * Every example asserts stock, bookings, labels and events after its steps, and the refusal
 * and rollback examples assert that the four tables are byte-identical to their state before.
 *
 * Fixture writes that the application cannot make - a later deadline moved into the past, a
 * live label on a row that does not exist - say so where they happen. The event table's
 * history guard is disabled around exactly those writes and nowhere else.
 */
class StockLabelRevivalTest extends PgsqlSchemaTestCase
{
	private const ADMIN_API_USER = 9611;
	private const STOCK_EDIT_ONLY_USER = 9612;

	private static PDO $db;
	private static int $location;
	private static int $otherLocation;
	private static string $adminKey;
	private static string $stockEditOnlyKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'label-revival-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		self::$location = self::insertRow('locations', ['name' => 'Label revival pantry']);
		self::$otherLocation = self::insertRow('locations', ['name' => 'Label revival cellar']);

		self::$adminKey = self::apiUser(self::ADMIN_API_USER, 'label-revival-admin', ['ADMIN']);
		// ADR-0037 section 8: STOCK_EDIT is sufficient to revive, and nothing else is needed.
		self::$stockEditOnlyKey = self::apiUser(self::STOCK_EDIT_ONLY_USER, 'label-revival-stock-edit', ['STOCK_EDIT']);
	}

	// --- fixtures ------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));
		return (int)$statement->fetchColumn();
	}

	private static function apiUser(int $id, string $name, array $permissions): string
	{
		self::$db->exec("INSERT INTO users(id, username, password) VALUES ($id, '$name', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ($permissions as $permission)
		{
			$grant->execute([$id, $permission]);
		}
		$key = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey($key), substr($key, -4), $id, ApiKeyService::API_KEY_TYPE_DEFAULT]);
		return $key;
	}

	private static function product(string $name): int
	{
		return self::insertRow('products', [
			'name' => $name . ' ' . bin2hex(random_bytes(3)),
			'location_id' => self::$location,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		]);
	}

	/** Purchases one stock row and returns its id. */
	private static function purchase(int $product, float $amount, string $due = '2999-12-31', ?int $location = null): int
	{
		StockService::GetInstance()->AddProduct($product, $amount, $due, StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $location ?? self::$location);
		return (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id = $product")->fetchColumn();
	}

	/** Issues a stock-entry label the way LabelOperationsService does, in its own transaction. */
	private static function label(int $stockRowId): string
	{
		$identity = new LabelIdentityService(self::$db);
		self::$db->beginTransaction();
		try
		{
			$uid = $identity->Issue('stock_entry', $stockRowId, self::epoch());
			self::$db->commit();
			return $uid;
		}
		catch (\Throwable $error)
		{
			self::$db->rollBack();
			throw $error;
		}
	}

	private static function epoch(): int
	{
		return (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
	}

	/** Consumes and returns [transaction id, booking ids in id order]. */
	private static function consume(int $product, float $amount): array
	{
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, $amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
		$bookings = self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		return [$transactionId, array_map('intval', $bookings)];
	}

	private static function labelRow(string $uid): array
	{
		return self::$db->query('SELECT * FROM labels WHERE uid = ' . self::$db->quote($uid))->fetch(PDO::FETCH_ASSOC);
	}

	/** @return array<int, array> every event of the label, oldest first */
	private static function events(string $uid): array
	{
		return self::$db->query('SELECT * FROM stock_label_retirements WHERE label_uid = ' . self::$db->quote($uid) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function lastEvent(string $uid): array
	{
		$events = self::events($uid);
		self::assertNotEmpty($events, "label $uid has no retirement event");
		return end($events);
	}

	private static function stockRow(int $id): array|false
	{
		return self::$db->query("SELECT * FROM stock WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
	}

	private static function scan(string $uid, bool $mayReadStock = true): array
	{
		return (new LabelIdentityService(self::$db))->Resolve('vctl:' . $uid, static fn (string $kind): bool => $mayReadStock);
	}

	/** Stock, bookings, labels and events, for the byte-identical refusal and rollback checks. */
	private static function state(): string
	{
		$tables = [];
		foreach (['stock' => 'id', 'stock_log' => 'id', 'labels' => 'uid', 'stock_label_retirements' => 'id'] as $table => $key)
		{
			$tables[$table] = self::$db->query("SELECT * FROM $table ORDER BY $key")->fetchAll(PDO::FETCH_ASSOC);
		}
		return json_encode($tables, JSON_THROW_ON_ERROR);
	}

	/**
	 * A fixture write to an event, for a state the application reaches only with time or with
	 * data it never writes. The history guard is lifted for this one statement.
	 */
	private static function rewriteEvent(int $eventId, string $assignments): void
	{
		self::$db->exec('ALTER TABLE stock_label_retirements DISABLE TRIGGER guard_stock_label_retirement_history');
		try
		{
			self::$db->exec("UPDATE stock_label_retirements SET $assignments WHERE id = $eventId");
		}
		finally
		{
			self::$db->exec('ALTER TABLE stock_label_retirements ENABLE TRIGGER guard_stock_label_retirement_history');
		}
	}

	private static function http(string $method, string $path, string $apiKey): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => $apiKey]];
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;
		return $result;
	}

	private static function revivalHeader(array $response): ?string
	{
		foreach ($response['headers'] ?? [] as $name => $values)
		{
			if (strcasecmp($name, 'Victual-Label-Revival') === 0)
			{
				return implode(', ', $values);
			}
		}
		return null;
	}

	/** A labelled row of $amount, consumed whole: [product, row id, uid, transaction, booking]. */
	private static function consumedLabelledRow(string $name, float $amount = 3): array
	{
		$product = self::product($name);
		$row = self::purchase($product, $amount);
		$uid = self::label($row);
		[$transaction, $bookings] = self::consume($product, $amount);
		self::assertCount(1, $bookings, 'Precondition: one whole-row booking');
		return [$product, $row, $uid, $transaction, $bookings[0]];
	}

	// --- E1: full consumption and undo inside the window ---------------------------------

	public function testE1UndoInsideTheWindowRevivesTheLabelOnTheOriginalRow(): void
	{
		$product = self::product('E1');
		$row = self::purchase($product, 3);
		$uid = self::label($row);
		self::assertSame('resolved', self::scan($uid)['status'], 'Precondition: the label is live');

		[, $bookings] = self::consume($product, 3);
		self::assertCount(1, $bookings);
		$booking = $bookings[0];

		self::assertFalse(self::stockRow($row), 'The whole-row take deletes the row');
		$retired = self::labelRow($uid);
		self::assertNotNull($retired['retired_at']);
		self::assertSame('retired', self::scan($uid)['status']);

		$event = self::lastEvent($uid);
		self::assertSame('consumption', $event['cause']);
		self::assertSame($booking, (int)$event['booking_id']);
		self::assertSame($product, (int)$event['product_id']);
		self::assertSame(3.0, (float)$event['amount']);
		self::assertSame(self::epoch(), (int)$event['import_epoch']);
		self::assertNull($event['outcome'], 'The event is pending until the undo');
		self::assertSame($retired['retirement_snapshot'], $event['snapshot'], 'R6: the event holds the retirement snapshot');
		self::assertSame(StockService::LABEL_REVIVAL_WINDOW_SECONDS, (int)self::$db->query(
			"SELECT EXTRACT(EPOCH FROM revivable_until - retired_at) FROM stock_label_retirements WHERE id = {$event['id']}")->fetchColumn(),
			'The deadline is 30 days after the retirement');
		self::assertSame($retired['retired_at'], self::$db->query("SELECT retired_at FROM stock_label_retirements WHERE id = {$event['id']}")->fetchColumn());

		$summary = StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['restored' => 1, 'retired' => 0], $summary);
		$restored = self::stockRow($row);
		self::assertNotFalse($restored, 'The undo rebuilds the row under its original id');
		self::assertSame($product, (int)$restored['product_id']);
		self::assertSame(3.0, (float)$restored['amount']);
		self::assertSame(1, (int)self::$db->query("SELECT undone FROM stock_log WHERE id = $booking")->fetchColumn());

		$revived = self::labelRow($uid);
		self::assertNull($revived['retired_at']);
		self::assertSame($row, (int)$revived['target_id']);
		self::assertNull($revived['retirement_snapshot'], 'R6: a revived label has no snapshot');
		$scan = self::scan($uid);
		self::assertSame('resolved', $scan['status'], 'The old sticker scans as the restored stock entry');

		$closed = self::lastEvent($uid);
		self::assertSame('revived', $closed['outcome']);
		self::assertSame($row, (int)$closed['revived_target_id']);
		self::assertNotNull($closed['closed_at']);
		self::assertNotNull($closed['snapshot'], 'R6: the event keeps the snapshot after revival');

		self::assertSame($uid, self::label($row), 'Issuing "a replacement" returns the same uid; no second label exists');
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM labels WHERE kind = 'stock_entry' AND target_id = $row")->fetchColumn());
	}

	// --- E2: several rows in one consumption, mixed outcomes ----------------------------

	public function testE2OneTransactionRevivesEachLabelOnItsOwnRow(): void
	{
		$product = self::product('E2');
		$first = self::purchase($product, 2, '2999-01-01');
		$second = self::purchase($product, 3, '2999-06-01');
		$firstUid = self::label($first);
		$secondUid = self::label($second);

		[$transaction, $bookings] = self::consume($product, 5);
		self::assertCount(2, $bookings, 'One booking per row');
		self::assertNotSame(self::lastEvent($firstUid)['booking_id'], self::lastEvent($secondUid)['booking_id'], 'Each row has its own event and booking');

		$summary = StockService::GetInstance()->UndoTransaction($transaction);

		self::assertSame(['restored' => 2, 'retired' => 0], $summary);
		self::assertSame($first, (int)self::labelRow($firstUid)['target_id']);
		self::assertSame($second, (int)self::labelRow($secondUid)['target_id']);
		self::assertSame(2.0, (float)self::stockRow($first)['amount']);
		self::assertSame(3.0, (float)self::stockRow($second)['amount']);
	}

	public function testMixedOutcomesAreCountedSeparately(): void
	{
		$product = self::product('Mixed');
		$first = self::purchase($product, 2, '2999-01-01');
		$second = self::purchase($product, 3, '2999-06-01');
		$firstUid = self::label($first);
		$secondUid = self::label($second);
		[$transaction] = self::consume($product, 5);

		// Fixture: forty days pass for the first label's event only.
		self::rewriteEvent((int)self::lastEvent($firstUid)['id'], "revivable_until = clock_timestamp() - interval '10 days'");

		$summary = StockService::GetInstance()->UndoTransaction($transaction);

		self::assertSame(['restored' => 1, 'retired' => 1], $summary, 'A mixed result must never read as if every label was restored');
		self::assertNotNull(self::labelRow($firstUid)['retired_at']);
		self::assertSame('expired', self::lastEvent($firstUid)['reason']);
		self::assertNull(self::labelRow($secondUid)['retired_at']);
		self::assertNotFalse(self::stockRow($first), 'The undo itself succeeds for both rows');
	}

	// --- E3 and E4: the window ------------------------------------------------------------

	/** @return array{0: StockLabelRevivalService, 1: string} a service whose clock reads $offset relative to the deadline */
	private static function clockAt(int $eventId, string $offset): StockLabelRevivalService
	{
		$now = (string)self::$db->query("SELECT (revivable_until + interval '$offset')::TEXT FROM stock_label_retirements WHERE id = $eventId")->fetchColumn();
		return new class(self::$db, $now) extends StockLabelRevivalService
		{
			public function __construct(\PDO $db, private string $now)
			{
				parent::__construct($db);
			}

			protected function Clock(): string
			{
				return $this->now;
			}
		};
	}

	/** Rebuilds the consumed row as UndoBooking() does, inside the caller's transaction. */
	private static function rebuild(int $row, int $booking): void
	{
		$log = self::$db->query("SELECT * FROM stock_log WHERE id = $booking")->fetch(PDO::FETCH_ASSOC);
		$statement = self::$db->prepare('INSERT INTO stock (id, product_id, amount, stock_id, location_id, best_before_date, purchased_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$statement->execute([$row, $log['product_id'], -$log['amount'], $log['stock_id'], $log['location_id'], $log['best_before_date'], $log['purchased_date']]);
	}

	public function testE3TheDeadlineIsExclusive(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E3');
		$eventId = (int)self::lastEvent($uid)['id'];

		self::$db->beginTransaction();
		self::rebuild($row, $booking);
		self::assertSame(StockLabelRevivalService::REVIVED, self::clockAt($eventId, '-1 microsecond')->Revive($eventId, $booking, $row),
			'One microsecond before the deadline revives');
		self::$db->rollBack();

		self::$db->beginTransaction();
		self::rebuild($row, $booking);
		self::assertSame('expired', self::clockAt($eventId, '0 seconds')->Revive($eventId, $booking, $row),
			'Exactly at the deadline declines');
		self::assertNotNull(self::labelRow($uid)['retired_at']);
		self::$db->rollBack();

		self::assertNull(self::lastEvent($uid)['outcome'], 'Both probes rolled back');
	}

	public function testE4AnUndoFortyDaysLaterSucceedsAndLeavesTheLabelRetired(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E4');
		// Fixture: forty days pass. Nothing ever cleaned the pending event up.
		self::rewriteEvent((int)self::lastEvent($uid)['id'], "retired_at = retired_at - interval '40 days', revivable_until = revivable_until - interval '40 days'");

		$summary = StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['restored' => 0, 'retired' => 1], $summary);
		self::assertNotFalse(self::stockRow($row), 'The undo itself succeeds');
		self::assertSame('retired', self::scan($uid)['status']);
		$event = self::lastEvent($uid);
		self::assertSame(['declined', 'expired'], [$event['outcome'], $event['reason']]);
	}

	// --- E5, E6: row ids and imports -------------------------------------------------------

	public function testE5ARowRestoredUnderANewIdLeavesTheLabelRetired(): void
	{
		[$product, $row, $uid, , $booking] = self::consumedLabelledRow('E5');
		$other = self::product('E5 unrelated');
		// An unrelated row of another product holds the consumed row's id.
		self::$db->exec("INSERT INTO stock (id, product_id, amount, stock_id, location_id, best_before_date, purchased_date) VALUES ($row, $other, 7, 'e5-unrelated', " . self::$location . ", '2999-12-31', '2026-01-01')");

		$summary = StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['restored' => 0, 'retired' => 1], $summary);
		self::assertSame($other, (int)self::stockRow($row)['product_id'], 'The unrelated row is untouched');
		self::assertSame(7.0, (float)self::stockRow($row)['amount']);
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM stock WHERE product_id = $product")->fetchColumn(), 'The stock came back under a fresh id');
		self::assertSame('id_changed', self::lastEvent($uid)['reason']);
		self::assertFalse(self::$db->query("SELECT 1 FROM labels WHERE kind = 'stock_entry' AND target_id = $row AND retired_at IS NULL")->fetchColumn(),
			'The unrelated row gains no label');
	}

	public function testE6AnEventOfAnEarlierImportEpochNeverMatches(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E6');
		// The import's own epoch bump (DatabaseImporter::Import()); the real importer is
		// StockLabelRevivalImportTest's subject.
		self::$db->exec('UPDATE label_import_state SET epoch = epoch + 1 WHERE id = 1');
		try
		{
			$summary = StockService::GetInstance()->UndoBooking($booking);

			self::assertSame(['restored' => 0, 'retired' => 0], $summary, 'Not attempted, so not reported');
			self::assertNotFalse(self::stockRow($row));
			self::assertNotNull(self::labelRow($uid)['retired_at']);
			self::assertNull(self::lastEvent($uid)['outcome'], 'The event of the earlier epoch stays as it was');
		}
		finally
		{
			// Later examples issue labels in the epoch they read, so leaving it raised is
			// harmless; restoring it keeps the examples independent of their order.
			self::$db->exec('UPDATE label_import_state SET epoch = epoch - 1 WHERE id = 1');
		}
	}

	// --- E7, E8, E10: a labelled target, repeated cycles, partial consumption -------------

	public function testE7ALiveLabelOnTheRestoredIdDeclinesAndIsUntouched(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E7');
		// Fixture the application cannot reach: a live label M naming a row that does not exist.
		$other = LabelIdentityService::GenerateUid();
		self::$db->exec("INSERT INTO labels (uid, kind, target_id) VALUES ('$other', 'stock_entry', $row)");

		$summary = StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['restored' => 0, 'retired' => 1], $summary);
		self::assertSame('target_labelled', self::lastEvent($uid)['reason']);
		self::assertNotNull(self::labelRow($uid)['retired_at']);
		self::assertSame($row, (int)self::labelRow($other)['target_id']);
	}

	public function testE8RepeatedCyclesWriteOneEventPerRetirement(): void
	{
		$product = self::product('E8');
		$row = self::purchase($product, 2);
		$uid = self::label($row);

		[, $first] = self::consume($product, 2);
		self::assertSame(['restored' => 1, 'retired' => 0], StockService::GetInstance()->UndoBooking($first[0]));
		[, $second] = self::consume($product, 2);
		self::assertSame(['restored' => 1, 'retired' => 0], StockService::GetInstance()->UndoBooking($second[0]));

		$events = self::events($uid);
		self::assertCount(2, $events);
		self::assertSame(['revived', 'revived'], array_column($events, 'outcome'));
		self::assertSame([$first[0], $second[0]], array_map('intval', array_column($events, 'booking_id')));
		self::assertGreaterThan($events[0]['retired_at'], $events[1]['retired_at'], 'The second window starts at the second retirement');
		self::assertNull(self::labelRow($uid)['retired_at']);
	}

	public function testE10APartialConsumptionRetiresNothingAndRevivesNothing(): void
	{
		$product = self::product('E10');
		$row = self::purchase($product, 5);
		$uid = self::label($row);
		[, $bookings] = self::consume($product, 2);

		self::assertSame([], self::events($uid), 'No retirement, no event');
		self::assertSame(['restored' => 0, 'retired' => 0], StockService::GetInstance()->UndoBooking($bookings[0]));
		self::assertSame($row, (int)self::labelRow($uid)['target_id']);
		self::assertSame(2, (int)self::$db->query("SELECT count(*) FROM stock WHERE product_id = $product")->fetchColumn(), 'The undo adds a separate row');
	}

	// --- E9, E11, E16: legacy and unproven retirements ------------------------------------

	public function testE11AnUndoOfAPurchaseRecordsAnUnprovenEvent(): void
	{
		$product = self::product('E11');
		$row = self::purchase($product, 3);
		$uid = self::label($row);
		$booking = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE product_id = $product")->fetchColumn();

		self::assertSame(['restored' => 0, 'retired' => 0], StockService::GetInstance()->UndoBooking($booking));

		$event = self::lastEvent($uid);
		self::assertSame(['unproven', 'declined', 'unproven'], [$event['cause'], $event['outcome'], $event['reason']]);
		self::assertNull($event['booking_id']);
	}

	public function testE16ProductDeletionAndADirectDeleteRecordUnprovenEvents(): void
	{
		$product = self::product('E16 product deletion');
		$uid = self::label(self::purchase($product, 1));
		self::$db->exec("DELETE FROM products WHERE id = $product");
		self::assertSame('unproven', self::lastEvent($uid)['cause']);

		$product = self::product('E16 direct delete');
		$row = self::purchase($product, 1);
		$uid = self::label($row);
		self::$db->exec("DELETE FROM stock WHERE id = $row");
		self::assertSame('unproven', self::lastEvent($uid)['cause']);
	}

	public function testE16AStaleContextInTheSameTransactionIsUnproven(): void
	{
		[, , $consumedUid, , $booking] = self::consumedLabelledRow('E16 stale');
		self::assertSame('consumption', self::lastEvent($consumedUid)['cause']);

		$product = self::product('E16 stale other');
		$row = self::purchase($product, 1);
		$uid = self::label($row);
		self::$db->beginTransaction();
		// The context ConsumeProduct() set for $booking, still present when a different row goes.
		StockLabelRevivalService::SetRetirementContext(self::$db, $booking, StockService::LABEL_REVIVAL_WINDOW_SECONDS);
		self::$db->exec("DELETE FROM stock WHERE id = $row");
		self::$db->commit();

		self::assertSame('unproven', self::lastEvent($uid)['cause'], 'A booking that does not name the deleted row proves nothing');
	}

	// --- E12: a booking that no longer matches its event ----------------------------------

	public function testE12ARescaledBookingDeclinesAsMismatch(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E12');
		// What a stock-unit rescale of the ledger writes (trg_cascade_change_qu_id_stock).
		self::$db->exec("UPDATE stock_log SET amount = amount * 2 WHERE id = $booking");

		$summary = StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['restored' => 0, 'retired' => 1], $summary);
		self::assertSame('mismatch', self::lastEvent($uid)['reason']);
		self::assertSame(6.0, (float)self::stockRow($row)['amount'], 'The undo restores what the booking says');
		self::assertNotNull(self::labelRow($uid)['retired_at']);
	}

	// --- E13, E14: rollback and refusal ---------------------------------------------------

	public function testE13ARollbackAfterTheRevivalLeavesEverythingAsItWasAndARetryRevives(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('E13');
		$before = self::state();

		try
		{
			DatabaseService::GetInstance()->InTransaction(function () use ($booking)
			{
				StockService::GetInstance()->UndoBooking($booking);
				throw new \RuntimeException('forced failure after the revival step');
			});
			self::fail('The forced failure must propagate');
		}
		catch (\RuntimeException $expected)
		{
			self::assertSame('forced failure after the revival step', $expected->getMessage());
		}

		self::assertSame($before, self::state(), 'Stock, bookings, labels and events are byte-identical after the rollback');
		self::assertSame(['restored' => 1, 'retired' => 0], StockService::GetInstance()->UndoBooking($booking), 'A retry revives');
		self::assertSame($row, (int)self::labelRow($uid)['target_id']);
	}

	public function testE14ARefusedUndoChangesNothing(): void
	{
		$product = self::product('E14');
		$cellar = self::insertRow('locations', ['name' => 'Label revival doomed shelf ' . bin2hex(random_bytes(3))]);
		$row = self::purchase($product, 3, '2999-12-31', $cellar);
		self::label($row);
		[, $bookings] = self::consume($product, 3);
		self::$db->exec("DELETE FROM locations WHERE id = $cellar");
		$before = self::state();

		try
		{
			StockService::GetInstance()->UndoBooking($bookings[0]);
			self::fail('The undo must refuse');
		}
		catch (\Exception $refusal)
		{
			self::assertStringContainsString('original location no longer exists', $refusal->getMessage());
		}

		self::assertSame($before, self::state(), 'A refused undo changes stock, bookings, labels and events not at all');
	}

	// --- Section 12a: the notice over HTTP --------------------------------------------------

	public function testTheBookingUndoReportsARevivalInTheHeaderAndKeepsTheBody(): void
	{
		[, , $uid, , $booking] = self::consumedLabelledRow('Header booking');

		$response = self::http('POST', "/api/stock/bookings/$booking/undo", self::$adminKey);

		self::assertSame(204, $response['status'], $response['body'] . $response['stderr']);
		self::assertSame('restored=1, retired=0', self::revivalHeader($response));
		self::assertSame('null', $response['body'], 'The body is the JSON null it always carried (contract snapshot shape "null")');
		self::assertNull(self::labelRow($uid)['retired_at']);
	}

	public function testTheTransactionUndoReportsAMixedResult(): void
	{
		$product = self::product('Header mixed');
		$first = self::purchase($product, 2, '2999-01-01');
		$second = self::purchase($product, 3, '2999-06-01');
		$firstUid = self::label($first);
		self::label($second);
		[$transaction] = self::consume($product, 5);
		self::rewriteEvent((int)self::lastEvent($firstUid)['id'], "revivable_until = clock_timestamp() - interval '1 day'");

		$response = self::http('POST', "/api/stock/transactions/$transaction/undo", self::$adminKey);

		self::assertSame(204, $response['status'], $response['body'] . $response['stderr']);
		self::assertSame('restored=1, retired=1', self::revivalHeader($response));
	}

	public function testAnUndoThatTouchesNoLabelAnswersExactlyAsBefore(): void
	{
		$product = self::product('Header none');
		self::purchase($product, 3);
		[, $bookings] = self::consume($product, 3);

		$response = self::http('POST', "/api/stock/bookings/{$bookings[0]}/undo", self::$adminKey);

		self::assertSame(204, $response['status'], $response['body'] . $response['stderr']);
		self::assertNull(self::revivalHeader($response), 'No label affected: no header');
	}

	public function testARefusedUndoNeverReportsARevival(): void
	{
		$product = self::product('Header refused');
		$cellar = self::insertRow('locations', ['name' => 'Label revival header doomed shelf ' . bin2hex(random_bytes(3))]);
		$row = self::purchase($product, 3, '2999-12-31', $cellar);
		$uid = self::label($row);
		[, $bookings] = self::consume($product, 3);
		self::$db->exec("DELETE FROM locations WHERE id = $cellar");

		$response = self::http('POST', "/api/stock/bookings/{$bookings[0]}/undo", self::$adminKey);

		self::assertSame(400, $response['status']);
		self::assertNull(self::revivalHeader($response));
		self::assertNull(self::lastEvent($uid)['outcome'], 'The event is still pending');
	}

	public function testAChoreExecutionUndoReportsTheRevivalOfItsStockUndo(): void
	{
		$product = self::product('Chore');
		$row = self::purchase($product, 1);
		$uid = self::label($row);
		$chore = self::insertRow('chores', ['name' => 'Label revival chore ' . bin2hex(random_bytes(3)), 'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1, 'product_id' => $product, 'product_amount' => 1]);
		$execution = ChoresService::GetInstance()->TrackChore($chore, '2026-10-06 09:00:00');
		self::assertNotNull(self::labelRow($uid)['retired_at'], 'Precondition: the chore consumed the labelled row whole');

		$response = self::http('POST', "/api/chores/executions/$execution/undo", self::$adminKey);

		self::assertSame(204, $response['status'], $response['body'] . $response['stderr']);
		self::assertSame('restored=1, retired=0', self::revivalHeader($response));
		self::assertSame($row, (int)self::labelRow($uid)['target_id']);
	}

	// --- Section 8: permissions -------------------------------------------------------------

	public function testStockEditAloneRevivesAndTheNoticeCarriesOnlyCounts(): void
	{
		[, $row, $uid, , $booking] = self::consumedLabelledRow('Permission');

		$response = self::http('POST', "/api/stock/bookings/$booking/undo", self::$stockEditOnlyKey);

		self::assertSame(204, $response['status'], $response['body'] . $response['stderr']);
		$header = self::revivalHeader($response);
		self::assertSame('restored=1, retired=0', $header);
		self::assertMatchesRegularExpression('/^restored=\d+, retired=\d+$/D', $header, 'Two counts, nothing else');
		foreach ($response['headers'] as $values)
		{
			foreach ($values as $value)
			{
				self::assertStringNotContainsString($uid, $value, 'No header carries the label uid');
			}
		}
		self::assertSame($row, (int)self::labelRow($uid)['target_id']);
	}

	public function testACallerWithoutStockViewStillScansTheRevivedLabelAsUnknown(): void
	{
		[, , $uid, , $booking] = self::consumedLabelledRow('Scan permission');
		StockService::GetInstance()->UndoBooking($booking);

		self::assertSame(['status' => 'unknown'], self::scan($uid, false));
		$response = self::http('GET', "/api/labels/resolve/vctl:$uid", self::$stockEditOnlyKey);
		self::assertSame(200, $response['status'], $response['body'] . $response['stderr']);
		self::assertSame(['status' => 'unknown'], json_decode($response['body'], true), 'STOCK_EDIT without STOCK_VIEW cannot read the revived label');
	}

	public function testNoEndpointReturnsARetirementEvent(): void
	{
		$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertNotContains('stock_label_retirements', $spec['components']['schemas']['ExposedEntity']['enum']);
		self::assertStringNotContainsString('stock_label_retirements', json_encode($spec['paths']));
		self::assertStringNotContainsString('stock_label_retirements', file_get_contents(VICTUAL_ROOT_PATH . '/routes.php'));

		$response = self::http('GET', '/api/objects/stock_label_retirements', self::$adminKey);
		self::assertNotSame(200, $response['status'], 'The generic entity API refuses the table');

		foreach (['/stock/bookings/{bookingId}/undo', '/stock/transactions/{transactionId}/undo', '/chores/executions/{executionId}/undo'] as $path)
		{
			self::assertArrayHasKey('Victual-Label-Revival', $spec['paths'][$path]['post']['responses']['204']['headers'] ?? [],
				"victual.openapi.json documents the header on $path");
		}
	}

	// --- Prerequisite 10: the hot path ------------------------------------------------------

	/** @return array{reads: int, inserts: int} this transaction's activity on the event table so far */
	private static function eventTableActivity(): array
	{
		$row = self::$db->query("SELECT COALESCE(seq_scan, 0) + COALESCE(idx_scan, 0) AS reads, n_tup_ins AS inserts
			FROM pg_stat_xact_user_tables WHERE relname = 'stock_label_retirements' AND schemaname = current_schema()")->fetch(PDO::FETCH_ASSOC);
		return ['reads' => (int)($row['reads'] ?? 0), 'inserts' => (int)($row['inserts'] ?? 0)];
	}

	private static function holdsImportLock(): bool
	{
		return (bool)self::$db->query("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()
			AND classid = 0 AND objid = " . LabelIdentityService::IMPORT_LOCK . ' AND objsubid = 1')->fetchColumn();
	}

	public function testAnUndoWithNoLabelAddsOneReadAndTakesNoImportLock(): void
	{
		$product = self::product('Hot path undo');
		self::purchase($product, 3);
		[, $bookings] = self::consume($product, 3);

		[$reads, $importLock] = DatabaseService::GetInstance()->InTransaction(function () use ($bookings)
		{
			$before = self::eventTableActivity();
			StockService::GetInstance()->UndoBooking($bookings[0]);
			return [self::eventTableActivity()['reads'] - $before['reads'], self::holdsImportLock()];
		});

		self::assertSame(1, $reads, 'One indexed read of the event table');
		self::assertFalse($importLock, 'No import lock without a pending event');
	}

	public function testATransactionUndoProbesAllItsBookingsInOneRead(): void
	{
		$product = self::product('Hot path transaction');
		self::purchase($product, 2, '2999-01-01');
		self::purchase($product, 3, '2999-06-01');
		[$transaction] = self::consume($product, 5);

		$reads = DatabaseService::GetInstance()->InTransaction(function () use ($transaction)
		{
			$before = self::eventTableActivity();
			StockService::GetInstance()->UndoTransaction($transaction);
			return self::eventTableActivity()['reads'] - $before['reads'];
		});

		self::assertSame(1, $reads);
	}

	public function testAnUndoWithAPendingEventTakesTheImportLock(): void
	{
		[, , , , $booking] = self::consumedLabelledRow('Hot path lock');

		$importLock = DatabaseService::GetInstance()->InTransaction(function () use ($booking)
		{
			StockService::GetInstance()->UndoBooking($booking);
			return self::holdsImportLock();
		});

		self::assertTrue($importLock);
	}

	public function testAConsumptionWithNoLabelSetsTheContextAndWritesNoEvent(): void
	{
		$product = self::product('Hot path consume');
		self::purchase($product, 3);

		[$activity, $context, $booking] = DatabaseService::GetInstance()->InTransaction(function () use ($product)
		{
			$before = self::eventTableActivity();
			$transactionId = null;
			StockService::GetInstance()->ConsumeProduct($product, 3, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);
			$after = self::eventTableActivity();
			return [
				['reads' => $after['reads'] - $before['reads'], 'inserts' => $after['inserts'] - $before['inserts']],
				[self::$db->query("SELECT current_setting('victual.retiring_booking_id', true)")->fetchColumn(),
					self::$db->query("SELECT current_setting('victual.label_revival_window_seconds', true)")->fetchColumn()],
				(int)self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId))->fetchColumn(),
			];
		});

		self::assertSame(['reads' => 0, 'inserts' => 0], $activity, 'No label work for an unlabelled row');
		self::assertSame([(string)$booking, (string)StockService::LABEL_REVIVAL_WINDOW_SECONDS], $context);
	}

	// --- Prerequisite 12: the ADR-0036 interface ----------------------------------------

	public function testAWholeRowUndoInsertsOneUnmergedRowHoldingTheBookingsAmount(): void
	{
		$product = self::product('ADR-0036 interface');
		$kept = self::purchase($product, 4, '2999-01-01');
		$consumed = self::purchase($product, 3, '2999-01-01');
		$uid = self::label($consumed);
		// Consume the second row only: take the first row's amount from somewhere else first.
		$transactionId = null;
		StockService::GetInstance()->ConsumeProduct($product, 3, false, StockService::TRANSACTION_TYPE_CONSUME, (string)self::stockRow($consumed)['stock_id'], null, null, $transactionId);
		$booking = (int)self::$db->query('SELECT id FROM stock_log WHERE transaction_id = ' . self::$db->quote($transactionId))->fetchColumn();
		$rowsBefore = (int)self::$db->query("SELECT count(*) FROM stock WHERE product_id = $product")->fetchColumn();

		StockService::GetInstance()->UndoBooking($booking);

		self::assertSame($rowsBefore + 1, (int)self::$db->query("SELECT count(*) FROM stock WHERE product_id = $product")->fetchColumn(), 'One new row');
		self::assertSame(4.0, (float)self::stockRow($kept)['amount'], 'Nothing merged into the surviving row');
		self::assertSame(3.0, (float)self::stockRow($consumed)['amount'], "The new row holds exactly the booking's amount");
		self::assertSame($consumed, (int)self::labelRow($uid)['target_id'], 'and revival holds');
	}
}
