<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\ConsumptionRefillService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0042 under real concurrency: two connections, two processes, one recipe.
 *
 * Each scenario runs a number of rounds with a seeded random delay on one side, so the order the two
 * take the recipe lock in varies. The invariants are the ones the design needs: no deadlock, one open
 * order, an order closes once with at most one fill, a revoke leaves nothing written after it, one
 * acknowledgement row per user and notice, and at most one live explicit date, always on the current fill.
 *
 * Randomisation is timing jitter, not a controlled schedule, so a pass shows the absence of the
 * failures in these rounds and not a proof. The seed is in every failure message and
 * CONSUMPTION_REFILL_RACE_SEED replays it.
 */
class ConsumptionRefillRaceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9601;
	private const EDITOR = 9602;
	private const ROUNDS = 20;
	private const AS_OF = '2026-03-01';

	private static PDO $db;
	private static ConsumptionRefillService $service;
	private static ConsumptionRecipeService $recipes;
	private static int $unit;
	private static int $product;
	private static int $seed;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionRefillService::GetInstance();
		self::$recipes = ConsumptionRecipeService::GetInstance();
		self::$seed = (int)(getenv('CONSUMPTION_REFILL_RACE_SEED') ?: random_int(1, PHP_INT_MAX));
		mt_srand(self::$seed);

		foreach ([self::OWNER => 'owner', self::EDITOR => 'editor'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'rfrace-$name', 'fixture')");
			self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $id, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT')");
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('RFRace tablet') RETURNING id")->fetchColumn();
		$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('RFRace organizer') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['RFRace product', $location, self::$unit, self::$unit, self::$unit, self::$unit]);
		self::$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct(self::$product, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $location);
	}

	/** A recipe the owner holds and the editor may edit. */
	private static function fixture(): int
	{
		$recipe = self::$recipes->CreateRecipe('Race refill ' . bin2hex(random_bytes(3)), null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$unit]], self::OWNER);
		self::$recipes->SetShare($recipe, self::EDITOR, ['edit' => true], self::OWNER);

		return $recipe;
	}

	/**
	 * Runs two calls in two processes that start together, the second after a random delay.
	 *
	 * @param array{0: string, 1: array}|array{0: string, 1: array, 2: string} $first  method, arguments and optionally the service ('refill' or 'recipe')
	 * @return array{0: array, 1: array} the two decoded results
	 */
	private function race(array $first, array $second): array
	{
		$delay = mt_rand(0, 4000);
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$processes = [];
		foreach ([[$first, 0], [$second, $delay]] as [$call, $wait])
		{
			$spec = ['method' => $call[0], 'args' => $call[1], 'service' => $call[2] ?? 'refill', 'delay_us' => $wait, 'then' => $call[3] ?? []];
			$process = proc_open([PHP_BINARY, __DIR__ . '/consumption-refill-race-subprocess-helper.php', base64_encode(json_encode($spec))],
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
			$processes[] = [$process, $pipes];
		}

		foreach ($processes as [, $pipes])
		{
			self::assertSame("ready\n", fgets($pipes[1]), 'the helper connected');
		}
		foreach ($processes as [, $pipes])
		{
			fwrite($pipes[0], "go\n");
			fclose($pipes[0]);
		}

		$results = [];
		foreach ($processes as [$process, $pipes])
		{
			$output = stream_get_contents($pipes[1]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
			$decoded = json_decode($output, true);
			self::assertIsArray($decoded, 'the helper printed no JSON (seed ' . self::$seed . "). stdout: $output stderr: $errors");
			$results[] = $decoded;
		}

		return $results;
	}

	private function assertNoDeadlock(array ...$results): void
	{
		foreach ($results as $result)
		{
			self::assertStringNotContainsString('40P01', (string)($result['message'] ?? ''), 'deadlock detected (seed ' . self::$seed . '): ' . ($result['message'] ?? ''));
			self::assertStringNotContainsString('deadlock', strtolower((string)($result['message'] ?? '')), 'deadlock detected (seed ' . self::$seed . ')');
		}
	}

	private static function rows(string $table, int $recipe, string $where = 'TRUE'): int
	{
		return (int)self::$db->query("SELECT count(*) FROM $table WHERE recipe_id = $recipe AND $where")->fetchColumn();
	}

	/** At most one live explicit date, on the current fill; at most one open order. */
	private function assertInvariants(int $recipe): void
	{
		$seed = self::$seed;
		self::assertLessThanOrEqual(1, self::rows('consumption_refill_dates', $recipe, 'ended_at IS NULL'), "one live explicit date (seed $seed)");
		self::assertLessThanOrEqual(1, self::rows('consumption_refill_orders', $recipe, "state = 'open'"), "one open order (seed $seed)");

		$live = self::$db->query("SELECT fill_id FROM consumption_refill_dates WHERE recipe_id = $recipe AND ended_at IS NULL")->fetchColumn();
		if ($live !== false)
		{
			$current = self::$db->query("SELECT id FROM consumption_refill_fills WHERE recipe_id = $recipe AND voided_at IS NULL ORDER BY filled_on DESC, id DESC LIMIT 1")->fetchColumn();
			self::assertSame((int)$current, (int)$live, "a live explicit date belongs to the current fill (seed $seed)");
		}
		self::assertSame(0, self::rows('consumption_refill_orders', $recipe, "state = 'received' AND received_fill_id IS NULL"), "a received order names its fill (seed $seed)");
	}

	public function testTwoOrdersForOneRecipeLeaveOneOpenAndTheOtherGets409(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			[$a, $b] = $this->race(['RecordOrder', [$recipe, ['ordered_on' => '2026-03-01'], self::AS_OF, self::OWNER]], ['RecordOrder', [$recipe, ['ordered_on' => '2026-03-02'], self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($a, $b);
			self::assertSame(1, (int)$a['ok'] + (int)$b['ok'], 'exactly one order succeeds (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			$loser = $a['ok'] ? $b : $a;
			self::assertSame([409, 'order_open'], [$loser['status'], $loser['code']], 'the other is a conflict (seed ' . self::$seed . ')');
			self::assertSame(1, self::rows('consumption_refill_orders', $recipe), 'one order row');
			$this->assertInvariants($recipe);
		}
	}

	public function testReceivingAgainstCancellingTheSameOrderEndsInOneOutcome(): void
	{
		$received = 0;
		$cancelled = 0;

		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$order = self::$service->RecordOrder($recipe, ['ordered_on' => '2026-02-20'], self::AS_OF, self::OWNER)['open_order']['id'];
			[$receive, $cancel] = $this->race(['ReceiveOrder', [$recipe, $order, ['filled_on' => '2026-03-01', 'supplied_days' => 30], self::AS_OF, self::OWNER]], ['CancelOrder', [$recipe, $order, self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($receive, $cancel);
			self::assertSame(1, (int)$receive['ok'] + (int)$cancel['ok'], 'exactly one of receive and cancel wins (seed ' . self::$seed . '): ' . json_encode([$receive, $cancel]));
			$state = self::$db->query("SELECT state FROM consumption_refill_orders WHERE id = $order")->fetchColumn();

			if ($receive['ok'])
			{
				$received++;
				self::assertSame('received', $state);
				self::assertSame(1, self::rows('consumption_refill_fills', $recipe), 'a received order recorded one fill');
				self::assertSame([409, 'order_closed'], [$cancel['status'], $cancel['code']]);
			}
			else
			{
				$cancelled++;
				self::assertSame('cancelled', $state);
				self::assertSame(0, self::rows('consumption_refill_fills', $recipe), 'a cancelled order recorded no fill (seed ' . self::$seed . ')');
				self::assertSame([409, 'order_closed'], [$receive['status'], $receive['code']]);
			}
			$this->assertInvariants($recipe);
		}

		fwrite(STDERR, "receive vs cancel: $received received first, $cancelled cancelled first (seed " . self::$seed . ")\n");
	}

	public function testTwoReceivesOfOneOrderRecordOneFill(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$order = self::$service->RecordOrder($recipe, ['ordered_on' => '2026-02-20'], self::AS_OF, self::OWNER)['open_order']['id'];
			[$a, $b] = $this->race(['ReceiveOrder', [$recipe, $order, ['filled_on' => '2026-03-01', 'supplied_days' => 30], self::AS_OF, self::OWNER]], ['ReceiveOrder', [$recipe, $order, ['filled_on' => '2026-03-01', 'supplied_days' => 30], self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($a, $b);
			self::assertSame(1, (int)$a['ok'] + (int)$b['ok'], 'exactly one receive succeeds (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			self::assertSame(1, self::rows('consumption_refill_fills', $recipe), 'one fill, not two (seed ' . self::$seed . ')');
			$this->assertInvariants($recipe);
		}
	}

	public function testACorrectionRacingANewFillKeepsOneCurrentFillAndAllHistory(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$old = self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 90], self::AS_OF, self::OWNER)['current_fill']['id'];
			self::$service->SetSettings($recipe, ['explicit_reorder_date' => '2026-02-10'], self::AS_OF, self::OWNER);
			[$void, $record] = $this->race(['VoidFill', [$recipe, $old, 'wrong fill', self::AS_OF, self::OWNER]], ['RecordFill', [$recipe, ['filled_on' => '2026-02-12', 'supplied_days' => 30], self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($void, $record);
			self::assertTrue($void['ok'] && $record['ok'], 'both complete (seed ' . self::$seed . '): ' . json_encode([$void, $record]));
			self::assertSame(2, self::rows('consumption_refill_fills', $recipe), 'nothing is deleted');
			self::assertSame(1, self::rows('consumption_refill_fills', $recipe, 'voided_at IS NOT NULL'));
			self::assertSame(0, self::rows('consumption_refill_dates', $recipe, 'ended_at IS NULL'), 'the explicit date ended whichever way the two interleaved (seed ' . self::$seed . ')');
			$this->assertInvariants($recipe);
		}
	}

	public function testAnExplicitDateRacingANewerFillNeverLeavesALiveDateOnTheOlderFill(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 90], self::AS_OF, self::OWNER);
			[$date, $fill] = $this->race(['SetSettings', [$recipe, ['explicit_reorder_date' => '2026-03-10'], self::AS_OF, self::OWNER]], ['RecordFill', [$recipe, ['filled_on' => '2026-02-20', 'supplied_days' => 30], self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($date, $fill);
			self::assertTrue($date['ok'] && $fill['ok'], 'both complete (seed ' . self::$seed . '): ' . json_encode([$date, $fill]));
			// Date first: the newer fill ends it. Fill first: the date is then entered for the new current fill.
			// Either is correct; what is never correct is a live date on the older fill, which assertInvariants() reads.
			$live = self::$db->query("SELECT f.filled_on FROM consumption_refill_dates d JOIN consumption_refill_fills f ON f.id = d.fill_id WHERE d.recipe_id = $recipe AND d.ended_at IS NULL")->fetchColumn();
			self::assertContains($live, [false, '2026-02-20'], 'a live date is on the newer fill (seed ' . self::$seed . ')');
			$this->assertInvariants($recipe);
		}
	}

	public function testTwoAcknowledgementsOfOneNoticeStoreOneRowAndBothSucceed(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$key = $recipe . ':due:2026-03-18';
			[$a, $b] = $this->race(['Acknowledge', [$key, self::OWNER]], ['Acknowledge', [$key, self::OWNER]]);

			$this->assertNoDeadlock($a, $b);
			self::assertTrue($a['ok'] && $b['ok'], 'neither fails on the primary key (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			self::assertSame($a['result'], $b['result'], 'and both answer the same');
			self::assertSame(1, self::rows('consumption_refill_acks', $recipe), 'one row');
		}
	}

	public function testTwoUsersAcknowledgingTheSameNoticeKeepIndependentRows(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$key = $recipe . ':approaching:2026-03-18';
			[$a, $b] = $this->race(['Acknowledge', [$key, self::OWNER]], ['Acknowledge', [$key, self::EDITOR]]);

			$this->assertNoDeadlock($a, $b);
			self::assertTrue($a['ok'] && $b['ok'], 'seed ' . self::$seed . ': ' . json_encode([$a, $b]));
			self::assertSame(2, self::rows('consumption_refill_acks', $recipe), 'two rows, one per user');
		}
	}

	public function testAWriteRacingARevokeEndsInExactlyOneOfTwoOutcomes(): void
	{
		$wroteFirst = 0;
		$refused = 0;

		foreach (['RecordFill' => [['filled_on' => '2026-03-01', 'supplied_days' => 30]], 'RecordOrder' => [['ordered_on' => '2026-03-01']]] as $method => $input)
		{
			for ($round = 0; $round < self::ROUNDS; $round++)
			{
				$recipe = self::fixture();
				[$write, $revoke] = $this->race([$method, [$recipe, $input[0], self::AS_OF, self::EDITOR]], ['RemoveShare', [$recipe, self::EDITOR, self::OWNER], 'recipe']);

				$this->assertNoDeadlock($write, $revoke);
				self::assertTrue($revoke['ok'], 'the revoke always succeeds (seed ' . self::$seed . '): ' . json_encode($revoke));
				self::assertSame(0, self::rows('consumption_recipe_shares', $recipe) , 'the share is gone');
				$table = $method === 'RecordFill' ? 'consumption_refill_fills' : 'consumption_refill_orders';

				if ($write['ok'])
				{
					$wroteFirst++;
					self::assertSame(1, self::rows($table, $recipe), "$method that won the lock wrote once (seed " . self::$seed . ')');
				}
				else
				{
					$refused++;
					self::assertSame([404, 'not_found'], [$write['status'], $write['code']], "$method after the revoke is absent, not forbidden (seed " . self::$seed . ')');
					self::assertSame(0, self::rows($table, $recipe), "a refused $method wrote nothing (seed " . self::$seed . ')');
				}
				$this->assertInvariants($recipe);
			}
		}

		fwrite(STDERR, "write vs revoke: $wroteFirst wrote first, $refused refused (seed " . self::$seed . ")\n");
	}

	/**
	 * A list or notice read races "revoke, then a new fill" by the owner. Whatever the interleaving, the former share
	 * holder's answer holds either the recipe as it was before the revoke or nothing: the list re-authorises each
	 * recipe under its lock, so it cannot see the fill recorded after the revoke.
	 */
	public function testAListAndNoticesRacingARevokeThenANewFillNeverShowTheLaterFillToTheFormerHolder(): void
	{
		foreach (['ListRefills', 'Notices'] as $method)
		{
			for ($round = 0; $round < self::ROUNDS; $round++)
			{
				$recipe = self::fixture();
				self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 90], self::AS_OF, self::OWNER);
				$revokeThenFill = ['RemoveShare', [$recipe, self::EDITOR, self::OWNER], 'recipe', [['service' => 'refill', 'method' => 'RecordFill', 'args' => [$recipe, ['filled_on' => '2026-03-01', 'supplied_days' => 30], self::AS_OF, self::OWNER]]]];
				[$read, $write] = $this->race([$method, ['2026-03-12', self::EDITOR]], $revokeThenFill);

				$this->assertNoDeadlock($read, $write);
				self::assertTrue($write['ok'], 'seed ' . self::$seed . ': ' . json_encode($write));
				self::assertTrue($read['ok'], 'seed ' . self::$seed . ': ' . json_encode($read));
				$items = $method === 'ListRefills' ? $read['result']['refills'] : $read['result']['notices'];
				foreach ($items as $item)
				{
					if ($item['recipe_id'] !== $recipe)
					{
						continue;
					}
					if ($method === 'ListRefills')
					{
						self::assertSame('2026-01-01', $item['current_fill']['filled_on'], "$method showed the former holder the fill recorded after the revoke (seed " . self::$seed . ')');
					}
					else
					{
						self::assertSame('2026-03-18', $item['reorder_date'], "$method showed the former holder the later fill (seed " . self::$seed . ')');
					}
				}
			}
		}
	}

	/**
	 * Deterministic form of the same property: a list or notice read takes the recipe lock, so it waits for a writer
	 * that holds it. A second connection locks the recipe, a helper process starts the read, and the test waits until
	 * PostgreSQL reports the helper blocked on a lock; only then does the writer revoke the share, record a fill and
	 * commit. The read must then leave the recipe out. A read that took no lock would not block at all.
	 */
	public function testAListAndNoticesWaitForTheRecipeLockAndThenLeaveOutARevokedRecipe(): void
	{
		foreach (['ListRefills', 'Notices'] as $method)
		{
			$recipe = self::fixture();
			self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 90], self::AS_OF, self::OWNER);

			$writer = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'), getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			$writer->exec('SET search_path TO ' . self::Schema() . ', public');
			$writer->beginTransaction();
			$writer->exec("SELECT 1 FROM consumption_recipes WHERE id = $recipe FOR UPDATE");

			$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
				'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
				'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			]);
			$spec = ['method' => $method, 'args' => ['2026-03-12', self::EDITOR], 'service' => 'refill', 'delay_us' => 0, 'then' => []];
			$process = proc_open([PHP_BINARY, __DIR__ . '/consumption-refill-race-subprocess-helper.php', base64_encode(json_encode($spec))],
				[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
			self::assertSame("ready\n", fgets($pipes[1]));
			fwrite($pipes[0], "go\n");
			fclose($pipes[0]);

			$blocked = false;
			for ($i = 0; $i < 200 && !$blocked; $i++)
			{
				$blocked = (int)self::$db->query("SELECT count(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND datname = current_database() AND query ILIKE '%consumption_recipes%'")->fetchColumn() > 0;
				if (!$blocked)
				{
					usleep(50000);
				}
			}
			self::assertTrue($blocked, "$method waits for the recipe lock (a read that takes none would not block)");

			$writer->exec("DELETE FROM consumption_recipe_shares WHERE recipe_id = $recipe AND user_id = " . self::EDITOR);
			$writer->exec("INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES ($recipe, '2026-03-01', 30)");
			$writer->commit();

			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
			$result = json_decode($output, true);
			self::assertTrue($result['ok'] ?? false, $output);
			$items = $method === 'ListRefills' ? $result['result']['refills'] : $result['result']['notices'];
			self::assertNotContains($recipe, array_column($items, 'recipe_id'), "$method left out the recipe whose share was revoked while it waited");
		}
	}

	public function testAnAcknowledgementRacingARevokeStoresNothingAfterTheRevoke(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$key = $recipe . ':due:2026-03-18';
			[$ack, $revoke] = $this->race(['Acknowledge', [$key, self::EDITOR]], ['RemoveShare', [$recipe, self::EDITOR, self::OWNER], 'recipe']);

			$this->assertNoDeadlock($ack, $revoke);
			self::assertTrue($revoke['ok'], 'the revoke always succeeds (seed ' . self::$seed . ')');
			if (!$ack['ok'])
			{
				self::assertSame([404, 'not_found'], [$ack['status'], $ack['code']]);
				self::assertSame(0, self::rows('consumption_refill_acks', $recipe), 'a refused acknowledgement stored nothing (seed ' . self::$seed . ')');
			}
		}
	}

	public function testAVoidRacingAnOrderRecordsBothAndLeavesTheOrderedStatus(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			$recipe = self::fixture();
			$fill = self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 90], self::AS_OF, self::OWNER)['current_fill']['id'];
			[$void, $order] = $this->race(['VoidFill', [$recipe, $fill, 'wrong', self::AS_OF, self::OWNER]], ['RecordOrder', [$recipe, ['ordered_on' => '2026-03-01'], self::AS_OF, self::EDITOR]]);

			$this->assertNoDeadlock($void, $order);
			self::assertTrue($void['ok'] && $order['ok'], 'seed ' . self::$seed . ': ' . json_encode([$void, $order]));
			$state = self::$service->GetRefill($recipe, self::AS_OF, self::OWNER);
			self::assertSame(['ordered', null], [$state['status'], $state['current_fill']]);
			self::assertCount(1, $state['fills']);
		}
	}
}
