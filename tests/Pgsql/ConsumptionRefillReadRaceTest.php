<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\ConsumptionRefillService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ConsumptionRefillService::ListRefills() and ::Notices() against a revoke, on a fixed schedule.
 *
 * Both reads pick their candidate recipes with ConsumptionRecipeService::ListRecipes(), which takes no
 * lock, and then read each recipe's refill facts. A share revoked between the two steps must leave the
 * reader with nothing of that recipe. ConsumptionRefillRaceTest races two calls with random delays and
 * so cannot hit that window on purpose. This class holds it open.
 *
 * The schedule: the test connection takes what a revoke takes, the recipe row `FOR UPDATE`, and
 * `ACCESS EXCLUSIVE` on consumption_refill_settings, the first table a refill read touches after the
 * candidate list. A reader subprocess is started and the test waits until PostgreSQL reports it blocked
 * on a lock. Then the test deletes the share, records a new fill and commits. Without the fix the reader
 * is blocked on the settings table, after it has chosen its candidates and without any recipe lock, so
 * it resumes with the revoked recipe and the new fill. With the fix it is blocked on the recipe row and
 * resumes to find no share.
 */
class ConsumptionRefillReadRaceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9611;
	private const READER = 9612;
	private const AS_OF = '2026-03-01';
	private const WAIT_SECONDS = 15;

	private static PDO $db;
	private static ?PDO $other = null;
	private static ConsumptionRefillService $service;
	private static ConsumptionRecipeService $recipes;
	private static int $unit;
	private static int $product;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionRefillService::GetInstance();
		self::$recipes = ConsumptionRecipeService::GetInstance();

		// A second connection for the scripted revoke, so it is never the one the in-process services use.
		self::$other = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		self::$other->exec('SET search_path TO ' . self::Schema() . ', public');

		foreach ([self::OWNER => 'owner', self::READER => 'reader'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'rfread-$name', 'fixture')");
			self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT $id, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME', 'STOCK_EDIT')");
		}

		self::$unit = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('RFRead tablet') RETURNING id")->fetchColumn();
		$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('RFRead organizer') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute(['RFRead product', $location, self::$unit, self::$unit, self::$unit, self::$unit]);
		self::$product = (int)$statement->fetchColumn();
		StockService::GetInstance()->AddProduct(self::$product, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, $location);
	}

	public static function tearDownAfterClass(): void
	{
		self::$other = null;
		parent::tearDownAfterClass();
	}

	/** A recipe the owner holds and the reader may read, whose last fill makes it due on AS_OF. */
	private static function fixture(): array
	{
		$name = 'Read race ' . bin2hex(random_bytes(3));
		$recipe = self::$recipes->CreateRecipe($name, null, [['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$unit]], self::OWNER);
		self::$recipes->SetShare($recipe, self::READER, [], self::OWNER);
		self::$service->RecordFill($recipe, ['filled_on' => '2026-01-01', 'supplied_days' => 30], self::AS_OF, self::OWNER);

		return [$recipe, $name];
	}

	/** Starts one service call in its own process and returns once it has been released. */
	private function start(string $method, array $args): array
	{
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(), 'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'), 'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);
		$spec = ['method' => $method, 'args' => $args, 'service' => $method === 'RemoveShare' ? 'recipe' : 'refill', 'delay_us' => 0];
		$process = proc_open([PHP_BINARY, __DIR__ . '/consumption-refill-race-subprocess-helper.php', base64_encode(json_encode($spec))],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

		self::assertSame("ready\n", fgets($pipes[1]), 'the helper connected');
		fwrite($pipes[0], "go\n");
		fclose($pipes[0]);

		return [$process, $pipes];
	}

	private function finish(array $handle): array
	{
		[$process, $pipes] = $handle;
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		$decoded = json_decode($output, true);
		self::assertIsArray($decoded, "the helper printed no JSON. stdout: $output stderr: $errors");

		return $decoded;
	}

	/** Waits until at least $count backends of this database are waiting on a lock, which is the scripted pause. */
	private function awaitBlocked(int $count): void
	{
		$deadline = microtime(true) + self::WAIT_SECONDS;
		$blocked = 0;
		while (microtime(true) < $deadline)
		{
			// Inside a transaction PostgreSQL serves pg_stat_activity from a cached snapshot, so clear it before each look.
			self::$other->query('SELECT pg_stat_clear_snapshot()');
			$blocked = (int)self::$other->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'")->fetchColumn();
			if ($blocked >= $count)
			{
				return;
			}
			usleep(20000);
		}

		self::fail("expected $count backend(s) waiting on a lock within " . self::WAIT_SECONDS . " s, saw $blocked");
	}

	/**
	 * Holds the schedule open, applies $change on the revoking connection while the reader is paused,
	 * commits, and returns the reader's decoded result.
	 */
	private function readAcross(string $method, int $recipe, callable $change): array
	{
		self::$other->beginTransaction();
		self::$other->query("SELECT id FROM consumption_recipes WHERE id = $recipe FOR UPDATE");
		self::$other->exec('LOCK TABLE consumption_refill_settings IN ACCESS EXCLUSIVE MODE');

		try
		{
			$reader = $this->start($method, [self::AS_OF, self::READER]);
			$this->awaitBlocked(1);
			$change(self::$other);
			self::$other->commit();
		}
		catch (\Throwable $exception)
		{
			if (self::$other->inTransaction())
			{
				self::$other->rollBack();
			}
			throw $exception;
		}

		return $this->finish($reader);
	}

	/** Revokes the reader's share and records a fill on $filledOn, the way a revoke and an owner's write interleave. */
	private static function revokeAndRecordFill(int $recipe, string $filledOn, int $days): callable
	{
		return function (PDO $db) use ($recipe, $filledOn, $days): void
		{
			$db->exec('DELETE FROM consumption_recipe_shares WHERE recipe_id = ' . $recipe . ' AND user_id = ' . self::READER);
			$db->exec("INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days, note) VALUES ($recipe, '$filledOn', $days, 'filled while the read was paused')");
		};
	}

	/** @return list<int> the recipe ids a ListRefills or Notices result names */
	private static function recipeIds(array $result, string $key): array
	{
		return array_map(fn(array $row) => (int)$row['recipe_id'], $result[$key]);
	}

	public function testAListRefillsPausedAcrossARevokeReturnsNothingOfThatRecipe(): void
	{
		[$recipe, $name] = self::fixture();
		self::assertContains($recipe, self::recipeIds(self::$service->ListRefills(self::AS_OF, self::READER), 'refills'), 'the reader sees the recipe before the revoke');

		$result = $this->readAcross('ListRefills', $recipe, self::revokeAndRecordFill($recipe, '2026-02-28', 60));

		self::assertTrue($result['ok'], json_encode($result));
		self::assertNotContains($recipe, self::recipeIds($result['result'], 'refills'), 'a recipe revoked before the read took its lock is absent');
		self::assertStringNotContainsString($name, json_encode($result['result']), 'and its name is not disclosed');
		self::assertStringNotContainsString('filled while the read was paused', json_encode($result['result']));
		self::assertSame(['as_of', 'as_of_source', 'refills'], array_keys($result['result']), 'the envelope is unchanged');
	}

	public function testNoticesPausedAcrossARevokeRaiseNothingForThatRecipe(): void
	{
		[$recipe] = self::fixture();
		self::assertContains($recipe, self::recipeIds(self::$service->Notices(self::AS_OF, self::READER), 'notices'), 'the reader has a notice before the revoke');

		$result = $this->readAcross('Notices', $recipe, self::revokeAndRecordFill($recipe, '2026-01-10', 30));

		self::assertTrue($result['ok'], json_encode($result));
		self::assertNotContains($recipe, self::recipeIds($result['result'], 'notices'), 'a recipe revoked before the read took its lock raises no notice, though its new fill keeps it due');
		self::assertSame(['as_of', 'as_of_source', 'notices'], array_keys($result['result']), 'the envelope is unchanged');
	}

	public function testAListRefillsPausedAcrossAnOrderAndFillReadsOneCompleteState(): void
	{
		[$recipe] = self::fixture();

		$result = $this->readAcross('ListRefills', $recipe, function (PDO $db) use ($recipe): void
		{
			$db->exec("INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES ($recipe, '2026-03-01', 90)");
			$db->exec("INSERT INTO consumption_refill_orders (recipe_id, ordered_on) VALUES ($recipe, '2026-02-27')");
		});

		self::assertTrue($result['ok'], json_encode($result));
		$state = array_values(array_filter($result['result']['refills'], fn(array $row) => (int)$row['recipe_id'] === $recipe));
		self::assertCount(1, $state, 'a recipe still shared is listed');
		self::assertSame('2026-03-01', $state[0]['current_fill']['filled_on'], 'the new fill, not the one the candidate list was chosen against');
		self::assertSame('ordered', $state[0]['status']);
		self::assertSame('2026-02-27', $state[0]['open_order']['ordered_on'], 'the order recorded in the same transaction is seen with the fill');
	}

	public function testNoticesPausedAcrossAFillThatClearsTheDueDateRaiseNothing(): void
	{
		[$recipe] = self::fixture();
		self::assertContains($recipe, self::recipeIds(self::$service->Notices(self::AS_OF, self::READER), 'notices'));

		$result = $this->readAcross('Notices', $recipe, function (PDO $db) use ($recipe): void
		{
			$db->exec("INSERT INTO consumption_refill_fills (recipe_id, filled_on, supplied_days) VALUES ($recipe, '2026-03-01', 90)");
		});

		self::assertTrue($result['ok'], json_encode($result));
		self::assertNotContains($recipe, self::recipeIds($result['result'], 'notices'), 'the notice reflects the fill committed while the read was paused');
	}

	public function testARevokeWaitsForAListRefillsThatHoldsTheRecipeLock(): void
	{
		[$recipe] = self::fixture();

		// Pause the reader on the settings table only. It has no recipe-row conflict, so under the fix it
		// takes the recipe lock FOR SHARE and then waits here, still holding it.
		self::$other->beginTransaction();
		self::$other->exec('LOCK TABLE consumption_refill_settings IN ACCESS EXCLUSIVE MODE');

		try
		{
			$reader = $this->start('ListRefills', [self::AS_OF, self::READER]);
			$this->awaitBlocked(1);
			$revoke = $this->start('RemoveShare', [$recipe, self::READER, self::OWNER]);
			// Two waiters: the reader on the settings table and the revoke on the recipe row the reader holds.
			$this->awaitBlocked(2);
			self::$other->commit();
		}
		catch (\Throwable $exception)
		{
			if (self::$other->inTransaction())
			{
				self::$other->rollBack();
			}
			throw $exception;
		}

		$read = $this->finish($reader);
		$revoked = $this->finish($revoke);

		self::assertTrue($read['ok'] && $revoked['ok'], json_encode([$read, $revoked]));
		self::assertContains($recipe, self::recipeIds($read['result'], 'refills'), 'the read that took the lock first completes with the recipe');
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM consumption_recipe_shares WHERE recipe_id = $recipe AND user_id = " . self::READER)->fetchColumn(), 'and the revoke then completes');
	}
}
