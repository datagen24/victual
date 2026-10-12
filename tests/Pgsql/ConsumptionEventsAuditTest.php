<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ConsumptionEventService;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionMappingService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The 2026-10-12 audit findings on consumption events (#863, #864, #867, #870), asserted as resulting
 * rows rather than as response shapes: the derived-state filter applied before the limit, a link that
 * takes the product locks before it reads the bookings and that the database backs, event-line
 * history surviving a deleted location, and a mapping's location refusing deletion by name.
 */
class ConsumptionEventsAuditTest extends PgsqlSchemaTestCase
{
	private const ME = 9000;
	private const OTHER = 9312;
	private const ADMIN = 9411;

	private static PDO $db;
	private static ConsumptionEventService $events;
	private static ConsumptionMappingService $mappings;
	private static int $tablet;
	private static int $shelf;
	private static int $sequence = 0;
	private static string $adminKey;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$events = ConsumptionEventService::GetInstance();
		self::$mappings = ConsumptionMappingService::GetInstance();

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'phpunit-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$db->exec('INSERT INTO users (id, username, password) VALUES (' . self::OTHER . ", 'cea-other', 'fixture'), (" . self::ADMIN . ", 'cea-admin', 'fixture')");
		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ([self::ME, self::OTHER] as $id)
		{
			foreach (['STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT'] as $permission)
			{
				$grant->execute([$id, $permission]);
			}
		}
		$grant->execute([self::ADMIN, 'MASTER_DATA_EDIT']);

		self::$adminKey = bin2hex(random_bytes(25));
		self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type, read_only) VALUES (?, ?, ?, now() + interval '30 days', ?, 0)")
			->execute([ApiKeyService::HashKey(self::$adminKey), substr(self::$adminKey, -4), self::ADMIN, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CEA tablet') RETURNING id")->fetchColumn();
		self::$shelf = self::location('CEA shelf');
	}

	// --- Fixtures ----------------------------------------------------------------------------

	private static function location(string $name): int
	{
		return (int)self::$db->query('INSERT INTO locations (name) VALUES (' . self::$db->quote($name . ' ' . ++self::$sequence) . ') RETURNING id')->fetchColumn();
	}

	private static function product(string $name, float $onHand): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name . ' ' . ++self::$sequence, self::$shelf, self::$tablet, self::$tablet, self::$tablet, self::$tablet]);
		$id = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($id, $onHand, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$shelf);

		return $id;
	}

	private static function onHand(int $productId): float
	{
		return (float)self::$db->query('SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = ' . $productId)->fetchColumn();
	}

	private static function uid(): string
	{
		return 'cea' . ++self::$sequence;
	}

	private static function map(int $productId): string
	{
		$ref = 'med' . ++self::$sequence;
		self::$mappings->Put(self::ME, 'healthkit', $ref, [
			'product_id' => $productId, 'unit_labels' => ['tablet'], 'default_quantity' => null,
			'location' => ['mode' => 'fixed', 'location_id' => self::$shelf], 'effective_from' => '2026-01-01T00:00:00Z',
		]);

		return $ref;
	}

	private static function body(string $ref): array
	{
		return ['status' => 'taken', 'medication_ref' => $ref, 'quantity' => 1, 'unit_label' => 'tablet', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)];
	}

	/** A manual recipe consumption of one tablet of the product; returns its transaction id. */
	private static function manualConsume(int $productId): string
	{
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipe = $recipes->CreateRecipe('CEA recipe ' . self::uid(), null, [['product_id' => $productId, 'amount' => 1, 'qu_id' => self::$tablet]], self::ME);

		return $recipes->Consume($recipe, self::uid(), null, null, self::ME)['transaction_id'];
	}

	private static function eventRow(string $id, int $user = self::ME, string $system = 'healthkit'): array
	{
		$statement = self::$db->prepare('SELECT * FROM consumption_events WHERE user_id = ? AND source_system = ? AND source_event_id = ?');
		$statement->execute([$user, $system, $id]);

		return $statement->fetch(PDO::FETCH_ASSOC);
	}

	private static function insertEvent(string $system, string $id, string $state, ?string $transactionId, ?string $linked = null, int $user = self::ME, ?string $ref = null): int
	{
		$statement = self::$db->prepare("INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, linked_transaction_id, medication_ref, occurred_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, now()) RETURNING id");
		$statement->execute([$user, $system, $id, $state, $transactionId, $linked, $ref]);

		return (int)$statement->fetchColumn();
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

	/** @return array{status: int, body: mixed} */
	private static function send(string $method, string $path): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$adminKey]];
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$response = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($response, "the request helper printed no JSON. stdout: $output stderr: $errors");
		$decoded = json_decode((string)$response['body'], true);
		$response['body'] = is_array($decoded) ? $decoded : $response['body'];

		return $response;
	}

	// --- #863 ---------------------------------------------------------------------------------

	public function testTheStateFilterIsAppliedBeforeTheLimitAndAgreesWithBulkResolve(): void
	{
		$product = self::product('CEA list', 20);
		$ref = self::map($product);
		$undone = [];
		foreach ([1, 2, 3] as $unused)
		{
			$id = self::uid();
			$booked = self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref));
			StockService::GetInstance()->UndoTransaction($booked['event']['transaction_id']);
			$undone[] = $id;
		}
		// A stock-side undo leaves the event row alone, so these three are the oldest by updated_at.
		self::$db->exec("UPDATE consumption_events SET updated_at = now() - interval '1 day' WHERE source_event_id IN ('" . implode("','", $undone) . "')");

		// A partially undone two-line event, also older than the crowd.
		$a = self::product('CEA partial a', 10);
		$b = self::product('CEA partial b', 10);
		$recipe = ConsumptionRecipeService::GetInstance()->CreateRecipe('CEA two lines ' . self::uid(), null,
			[['product_id' => $a, 'amount' => 2, 'qu_id' => self::$tablet], ['product_id' => $b, 'amount' => 1, 'qu_id' => self::$tablet]], self::ME);
		$partialRef = 'med' . ++self::$sequence;
		self::$mappings->Put(self::ME, 'healthkit', $partialRef, ['recipe_id' => $recipe, 'unit_labels' => ['dose'],
			'location' => ['mode' => 'fixed', 'location_id' => self::$shelf], 'effective_from' => '2026-01-01T00:00:00Z']);
		$partialId = self::uid();
		$partial = self::$events->Submit(self::ME, 'healthkit', $partialId, ['unit_label' => 'dose'] + self::body($partialRef));
		$line = self::$db->query('SELECT id FROM stock_log WHERE undone = 0 AND product_id = ' . $a . ' AND transaction_id = ' . self::$db->quote($partial['event']['transaction_id']))->fetchColumn();
		StockService::GetInstance()->UndoBooking((int)$line, true);
		self::$db->exec("UPDATE consumption_events SET updated_at = now() - interval '1 day' WHERE source_event_id = '$partialId'");

		// 300 newer booked events, whose transactions have no undone booking.
		self::$db->exec("INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, medication_ref, occurred_at)
			SELECT " . self::ME . ", 'healthkit', 'crowd' || n, 'booked', 'cea-crowd-' || n, '$ref', now() FROM generate_series(1, 300) AS n");

		$listed = array_column(self::$events->ListEvents(self::ME, ['undone'], null, 100), 'source_event_id');
		sort($listed);
		$expected = $undone;
		sort($expected);
		self::assertSame($expected, $listed, 'the three older undone events are found although 300 newer booked rows precede them');

		$review = array_column(self::$events->ListEvents(self::ME, ['needs_review'], null, 100), 'source_event_id');
		self::assertSame([$partialId], $review);

		$booked = self::$events->ListEvents(self::ME, ['booked'], null, 100);
		self::assertCount(100, $booked);
		self::assertSame([], array_values(array_intersect(array_column($booked, 'source_event_id'), array_merge($undone, [$partialId]))), 'an undone event is not listed as booked');
		foreach ($booked as $event)
		{
			self::assertSame('booked', self::$events->Get(self::ME, 'healthkit', $event['source_event_id'])['state']);
		}

		$both = array_column(self::$events->ListEvents(self::ME, ['undone', 'needs_review'], null, 100), 'source_event_id');
		self::assertCount(4, $both);

		$bulk = self::$events->BulkResolve(self::ME, 'dismiss', null, ['source_system' => 'healthkit', 'medication_ref' => $ref, 'state' => 'undone']);
		$bulked = array_column($bulk['results'], 'source_event_id');
		sort($bulked);
		self::assertSame($listed, $bulked, 'the bulk filter selects what the list returns');
	}

	// --- #864 ---------------------------------------------------------------------------------

	public function testALinkToATransactionWithAnUndoneBookingIsRefusedAndChangesNothing(): void
	{
		$product = self::product('CEA link undone', 10);
		$ref = self::map($product);
		$manual = self::manualConsume($product);
		StockService::GetInstance()->UndoTransaction($manual);
		$id = self::uid();
		self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref));
		$before = self::eventRow($id);
		$stockBefore = self::onHand($product);
		$linesBefore = self::$db->query('SELECT count(*) FROM consumption_event_lines WHERE event_id = ' . (int)$before['id'])->fetchColumn();

		$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $manual), 422, 'invalid_link');

		$after = self::eventRow($id);
		self::assertSame([$before['state'], $before['transaction_id'], null], [$after['state'], $after['transaction_id'], $after['linked_transaction_id']]);
		self::assertSame($stockBefore, self::onHand($product), 'the refused link undid nothing');
		self::assertSame($linesBefore, self::$db->query('SELECT count(*) FROM consumption_event_lines WHERE event_id = ' . (int)$before['id'])->fetchColumn(), 'the event lines are the ones it had');
		self::assertSame(0, (int)self::$db->query('SELECT count(*) FROM consumption_event_lines l JOIN stock_log s ON s.id = l.stock_log_id WHERE s.undone <> 0 AND l.event_id = ' . (int)$before['id'] . ' AND s.transaction_id = ' . self::$db->quote($manual))->fetchColumn(), 'no event line points at the undone transaction');
	}

	public function testALinkCopiesOnlyLiveBookingsAsLines(): void
	{
		$product = self::product('CEA link lines', 10);
		$ref = self::map($product);
		$manual = self::manualConsume($product);
		$id = self::uid();
		self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref));

		$linked = self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $manual);

		self::assertSame('linked', $linked['state']);
		$lines = self::$db->query('SELECT s.undone, s.transaction_id FROM consumption_event_lines l JOIN stock_log s ON s.id = l.stock_log_id WHERE l.event_id = ' . (int)self::eventRow($id)['id'])->fetchAll(PDO::FETCH_ASSOC);
		self::assertSame([['undone' => 0, 'transaction_id' => $manual]], array_map(fn($row) => ['undone' => (int)$row['undone']] + $row, $lines));
	}

	public function testAUniqueViolationDuringTheLinkIsTheInvalidLinkRefusalAndRollsTheUndoBack(): void
	{
		$product = self::product('CEA link race', 10);
		$ref = self::map($product);
		$manual = self::manualConsume($product);
		$id = self::uid();
		$booked = self::$events->Submit(self::ME, 'healthkit', $id, self::body($ref));
		$stockBefore = self::onHand($product);

		// Stands in for the loser of a race: a concurrent link took the transaction after the checks, so the
		// UPDATE hits the unique index. The trigger raises the same SQLSTATE and is the only way to reach it
		// from one connection.
		self::$db->exec("CREATE FUNCTION cea_unique_violation() RETURNS TRIGGER AS \$\$ BEGIN RAISE EXCEPTION 'duplicate key' USING ERRCODE = '23505'; END; \$\$ LANGUAGE plpgsql");
		self::$db->exec("CREATE TRIGGER cea_unique_violation BEFORE UPDATE ON consumption_events FOR EACH ROW WHEN (NEW.state = 'linked') EXECUTE FUNCTION cea_unique_violation()");

		try
		{
			$this->expectRefusal(fn() => self::$events->Resolve(self::ME, 'healthkit', $id, 'link', $manual), 422, 'invalid_link');
		}
		finally
		{
			self::$db->exec('DROP TRIGGER cea_unique_violation ON consumption_events');
			self::$db->exec('DROP FUNCTION cea_unique_violation()');
		}

		$row = self::eventRow($id);
		self::assertSame(['booked', $booked['event']['transaction_id'], null], [$row['state'], $row['transaction_id'], $row['linked_transaction_id']]);
		self::assertSame($stockBefore, self::onHand($product), 'the event own booking was not undone');
		self::assertSame(0, (int)self::$db->query('SELECT undone FROM stock_log WHERE transaction_id = ' . self::$db->quote($booked['event']['transaction_id']))->fetchColumn());
	}

	public function testTheDatabaseRefusesATransactionUsedAsBothBookingAndLinkExceptTheManualRuleNineCase(): void
	{
		// An imported booked event A holds T1; linking B to T1 would count one deduction for two doses.
		self::insertEvent('healthkit', 'a1', 'booked', 'cea-t1');
		try
		{
			self::insertEvent('healthkit', 'b1', 'linked', null, 'cea-t1');
			self::fail('expected a unique violation');
		}
		catch (\PDOException $exception)
		{
			self::assertSame('23505', $exception->errorInfo[0]);
		}
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = 'b1'")->fetchColumn());

		// The other order: B is linked to T2 first, then an event books under T2.
		self::insertEvent('healthkit', 'b2', 'linked', null, 'cea-t2');
		try
		{
			self::insertEvent('healthkit', 'a2', 'booked', 'cea-t2');
			self::fail('expected a unique violation');
		}
		catch (\PDOException $exception)
		{
			self::assertSame('23505', $exception->errorInfo[0]);
		}
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE source_event_id = 'a2'")->fetchColumn());

		// Rule 9: the caller's own manual event holds T3 and an imported event of the same user links to it.
		self::insertEvent('manual', 'm3', 'booked', 'cea-t3');
		self::insertEvent('healthkit', 'b3', 'linked', null, 'cea-t3');
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE linked_transaction_id = 'cea-t3'")->fetchColumn());

		// Another user's manual event is not the caller's own.
		self::insertEvent('manual', 'm4', 'booked', 'cea-t4', null, self::OTHER);
		try
		{
			self::insertEvent('healthkit', 'b4', 'linked', null, 'cea-t4');
			self::fail('expected a unique violation');
		}
		catch (\PDOException $exception)
		{
			self::assertSame('23505', $exception->errorInfo[0]);
		}
	}

	// --- #867 ---------------------------------------------------------------------------------

	public function testDeletingALocationNamedByEventHistoryNullsTheLineAndKeepsTheEvent(): void
	{
		$location = self::location('CEA history');
		$product = self::product('CEA history', 5);
		$eventId = self::insertEvent('healthkit', $historyId = self::uid(), 'booked', 'cea-history');
		self::$db->exec("INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id) VALUES ($eventId, $product, 1, $location)");

		$constraint = self::$db->query("SELECT confdeltype FROM pg_constraint WHERE conrelid = 'consumption_event_lines'::regclass AND contype = 'f'
			AND confrelid = 'locations'::regclass")->fetchColumn();
		self::assertSame('n', $constraint, 'ON DELETE SET NULL');

		self::$db->exec("DELETE FROM locations WHERE id = $location");

		self::assertSame([null], self::$db->query("SELECT location_id FROM consumption_event_lines WHERE event_id = $eventId")->fetchAll(PDO::FETCH_COLUMN), 'the line survives with no location');
		$presented = self::$events->Get(self::ME, 'healthkit', $historyId);
		self::assertSame('booked', $presented['state']);
	}

	// --- #870 ---------------------------------------------------------------------------------

	public function testDeletingALocationThatAMappingNamesIsRefusedWithTheMappingNamed(): void
	{
		$location = self::location('CEA mapped');
		$product = self::product('CEA mapped', 5);
		$ref = 'med' . ++self::$sequence;
		self::$mappings->Put(self::ME, 'healthkit', $ref, ['product_id' => $product, 'unit_labels' => ['tablet'], 'default_quantity' => null,
			'location' => ['mode' => 'fixed', 'location_id' => $location], 'effective_from' => '2026-01-01T00:00:00Z']);
		$mappingId = (int)self::$db->query('SELECT id FROM consumption_mappings WHERE location_id = ' . $location)->fetchColumn();

		$response = self::send('DELETE', '/api/objects/locations/' . $location);

		self::assertSame(400, $response['status']);
		self::assertStringContainsString('consumption mapping', $response['body']['error_message']);
		self::assertStringContainsString((string)$mappingId, $response['body']['error_message']);
		self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM locations WHERE id = $location")->fetchColumn(), 'the location is still there');
		self::assertSame($location, (int)self::$db->query('SELECT location_id FROM consumption_mappings WHERE id = ' . $mappingId)->fetchColumn(), 'and so is the mapping');
	}

	public function testADeletedLocationIsNotOfferedAsACandidate(): void
	{
		$kept = self::location('CEA candidate kept');
		$gone = self::location('CEA candidate gone');
		$id = self::uid();
		self::$db->exec("INSERT INTO consumption_events (user_id, source_system, source_event_id, state, reason, candidate_location_ids, occurred_at)
			VALUES (" . self::ME . ", 'healthkit', '$id', 'needs_review', 'ambiguous_location', ARRAY[$gone, $kept], now())");

		self::assertSame([$gone, $kept], self::$events->Get(self::ME, 'healthkit', $id)['candidate_location_ids']);

		self::$db->exec("DELETE FROM locations WHERE id = $gone");

		self::assertSame([$kept], self::$events->Get(self::ME, 'healthkit', $id)['candidate_location_ids']);
		$listed = array_values(array_filter(self::$events->ListEvents(self::ME, ['needs_review'], null, 100), fn($event) => $event['source_event_id'] === $id));
		self::assertSame([$kept], $listed[0]['candidate_location_ids'] ?? null);
	}
}
