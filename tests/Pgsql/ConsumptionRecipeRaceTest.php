<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0040 rule 8 under real concurrency: two connections, two processes, one recipe.
 *
 * Each scenario runs a number of rounds with a seeded random delay on one side, so the order the
 * two take the recipe lock in varies. The invariants are the ones the evidence PR measured
 * (.devtools/adr0040): no deadlock, no consumption committed after a revoke, one grant row, and
 * `edit` serialising on FOR UPDATE rather than deadlocking as two FOR SHARE editors do.
 *
 * Randomisation is timing jitter, not a controlled schedule, so a pass shows the absence of the
 * failures in these rounds and not a proof. The seed is in every failure message.
 */
class ConsumptionRecipeRaceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9201;
	private const MEMBER = 9202;
	private const THIRD = 9203;
	private const ROUNDS = 25;

	private static PDO $db;
	private static ConsumptionRecipeService $service;
	private static int $unit;
	private static int $location;
	private static int $seed;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionRecipeService::GetInstance();
		self::$seed = random_int(1, PHP_INT_MAX);
		mt_srand(self::$seed);

		foreach ([self::OWNER => 'owner', self::MEMBER => 'member', self::THIRD => 'third'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'race-$name', 'fixture')");
			self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $id, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT')");
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('Race tablet') RETURNING id")->fetchColumn();
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('Race organizer') RETURNING id")->fetchColumn();
	}

	private static function product(float $onHand): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['Race product ' . bin2hex(random_bytes(3)), self::$location, self::$unit, self::$unit, self::$unit, self::$unit]);
		$id = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct($id, $onHand, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);

		return $id;
	}

	/** @return array{0: int, 1: int} the recipe id and its product id */
	private static function fixture(array $shares = []): array
	{
		$product = self::product(50);
		$recipe = self::$service->CreateRecipe('Race recipe', null, [['product_id' => $product, 'amount' => 1, 'qu_id' => self::$unit]], self::OWNER);
		foreach ($shares as $user => $rights)
		{
			self::$service->SetShare($recipe, $user, $rights, self::OWNER);
		}

		return [$recipe, $product];
	}

	/**
	 * Runs two service calls in two processes that start together, the second after a random delay.
	 *
	 * @param array{0: string, 1: array} $first  method and arguments
	 * @param array{0: string, 1: array} $second method and arguments
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
			$spec = ['method' => $call[0], 'args' => $call[1], 'delay_us' => $wait];
			$process = proc_open([PHP_BINARY, __DIR__ . '/consumption-race-subprocess-helper.php', base64_encode(json_encode($spec))],
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
			self::assertIsArray($decoded, "the helper printed no JSON (seed " . self::$seed . "). stdout: $output stderr: $errors");
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

	public function testConsumeAgainstRevokeEndsInExactlyOneOfTwoOutcomes(): void
	{
		$consumedFirst = 0;
		$refused = 0;

		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			[$recipe, $product] = self::fixture([self::MEMBER => ['consume' => true]]);
			[$consume, $revoke] = $this->race(['Consume', [$recipe, "race-$round", null, null, self::MEMBER]], ['RemoveShare', [$recipe, self::MEMBER, self::OWNER]]);

			$this->assertNoDeadlock($consume, $revoke);
			self::assertTrue($revoke['ok'], 'the revoke always succeeds (seed ' . self::$seed . ')');
			self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_recipe_shares WHERE recipe_id = $recipe")->fetchColumn(), 'the share is gone');
			$booked = (float)self::$db->query("SELECT 50 - COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn();

			if ($consume['ok'])
			{
				$consumedFirst++;
				self::assertSame(1.0, $booked, 'a consumption that succeeded booked once (seed ' . self::$seed . ')');
			}
			else
			{
				$refused++;
				self::assertSame([404, 'not_found'], [$consume['status'], $consume['code']], 'a consumption after the revoke is absent, not forbidden (seed ' . self::$seed . ')');
				self::assertSame(0.0, $booked, 'a refused consumption booked nothing');
				self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_events WHERE recipe_id = $recipe OR source_event_id = 'race-$round'")->fetchColumn());
			}
		}

		fwrite(STDERR, "consume vs revoke: $consumedFirst consumed first, $refused refused (seed " . self::$seed . ")\n");
	}

	public function testTwoEditorsSerialiseInsteadOfDeadlocking(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			[$recipe, $product] = self::fixture([self::MEMBER => ['edit' => true]]);
			$lines = [['product_id' => $product, 'amount' => 2, 'qu_id' => self::$unit]];
			[$a, $b] = $this->race(['UpdateRecipe', [$recipe, ['name' => "owner $round", 'lines' => $lines], self::OWNER]], ['UpdateRecipe', [$recipe, ['name' => "member $round", 'lines' => $lines], self::MEMBER]]);

			$this->assertNoDeadlock($a, $b);
			self::assertTrue($a['ok'] && $b['ok'], 'both edits complete (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			self::assertContains(self::$service->GetRecipe($recipe, self::OWNER)['name'], ["owner $round", "member $round"]);
			self::assertCount(1, self::$service->GetRecipe($recipe, self::OWNER)['lines']);
		}
	}

	public function testTwoGrantsToTheSameUserLeaveOneRow(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			[$recipe] = self::fixture();
			[$a, $b] = $this->race(['SetShare', [$recipe, self::THIRD, ['consume' => true], self::OWNER]], ['SetShare', [$recipe, self::THIRD, ['edit' => true], self::OWNER]]);

			$this->assertNoDeadlock($a, $b);
			self::assertTrue($a['ok'] && $b['ok'], 'neither grant fails on the unique key (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			self::assertSame(1, (int)self::$db->query("SELECT count(*) FROM consumption_recipe_shares WHERE recipe_id = $recipe AND user_id = " . self::THIRD)->fetchColumn());
		}
	}

	public function testTheSameRequestIdBooksOnceWhateverTheOrder(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			[$recipe, $product] = self::fixture([self::MEMBER => ['consume' => true]]);
			[$a, $b] = $this->race(['Consume', [$recipe, "same-$round", null, null, self::MEMBER]], ['Consume', [$recipe, "same-$round", null, null, self::MEMBER]]);

			$this->assertNoDeadlock($a, $b);
			self::assertTrue($a['ok'] && $b['ok'], 'both answer (seed ' . self::$seed . '): ' . json_encode([$a, $b]));
			self::assertSame(1, ($a['result']['replayed'] ? 1 : 0) + ($b['result']['replayed'] ? 1 : 0), 'exactly one is the replay');
			self::assertSame($a['result']['transaction_id'], $b['result']['transaction_id']);
			self::assertSame(49.0, (float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id = $product")->fetchColumn(), 'one booking');
		}
	}

	public function testTransferAgainstConsumeNeverFailsTheConsume(): void
	{
		for ($round = 0; $round < self::ROUNDS; $round++)
		{
			[$recipe, $product] = self::fixture([self::MEMBER => ['consume' => true]]);
			[$consume, $transfer] = $this->race(['Consume', [$recipe, "xfer-$round", null, null, self::MEMBER]], ['TransferOwnership', [$recipe, self::MEMBER, self::OWNER]]);

			$this->assertNoDeadlock($consume, $transfer);
			self::assertTrue($consume['ok'], 'the consume succeeds before or after the transfer (seed ' . self::$seed . '): ' . json_encode($consume));
			self::assertTrue($transfer['ok'], 'the transfer succeeds (seed ' . self::$seed . '): ' . json_encode($transfer));
			self::assertSame(49.0, (float)self::$db->query("SELECT sum(amount) FROM stock WHERE product_id = $product")->fetchColumn());
		}
	}
}
