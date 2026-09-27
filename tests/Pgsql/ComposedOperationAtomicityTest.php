<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Services\ChoresService;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #494 (audit finding H5): a composed business operation must
 * commit or roll back as one unit, including its non-stock effects, rather than leaving the
 * part that ran before a later step refused.
 *
 * Three operations, each with a refusal case and a control case:
 *
 * - ChoresService::TrackChore() saves the chores_log entry, then - when the chore is
 *   configured to consume its linked product - calls StockService::ConsumeProduct(). Before
 *   the fix, the log entry was saved outside any transaction, so a refused consumption left it
 *   committed on its own (issue #494/H5's confirmed chore defect).
 * - RecipesService::ConsumeRecipe() consumed every ingredient inside one transaction, then
 *   committed it, and only afterwards - in a second, separate transaction - booked the
 *   recipe's own "produces product" as self-production. A refused self-production (an
 *   inactive output product, here) left the ingredient consumption of the first transaction
 *   committed (issue #494/H5's confirmed recipe defect).
 * - RecipesService::CopyRecipe() ran three INSERT ... SELECT statements with no transaction at
 *   all, so a failure on the second or third left the earlier ones committed
 *   (source-confirmed only in issue #494/H5; the case below is the fault injection the issue
 *   asked for where practical, forcing the third insert to hit recipes_nestings'
 *   UNIQUE(recipe_id, includes_recipe_id) constraint).
 *
 * Every case is Given/When/Then on the rows a caller can query afterwards, through the real
 * services rather than HTTP - matching RecipeOperationsTest.php, the existing test of the
 * recipeoperations suite this file is registered in.
 *
 * Outbox coverage is separate from the direct-call cases above, and runs through the HTTP
 * subprocess harness instead (request-subprocess-helper.php, the same one
 * StockConcurrencyTest.php uses), because BookingEventPublisher::RecordTransaction() only ever
 * enqueues an outbox row when the VICTUAL_INFLUXDB_ENABLED constant is true, config-dist.php
 * defaults INFLUXDB_ENABLED to false, and a PHP constant cannot be redefined once set - so it
 * can only be turned on for a fresh process, never for one test method sharing this class's
 * process with every other. A direct-call assertion against the outbox table in this class
 * would therefore see an empty table before and after every case regardless of atomicity, which
 * is not a regression test; the testChoreExecution.../testRecipeConsume...OutboxRow...() cases
 * below set VICTUAL_INFLUXDB_ENABLED=true on a subprocess instead, so the enqueue this fix's
 * transaction wrapping protects can actually be observed succeeding or (correctly) not
 * happening. RecipesService::CopyRecipe() never calls StockService, so it has no outbox
 * behaviour to cover here.
 */
class ComposedOperationAtomicityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ChoresService $chores;
	private static RecipesService $recipes;
	private static StockService $stock;
	private static string $apiKey = '';

	/** Fixture ids shared by setUp, filled by testCreatesFixtures(). */
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'composed-atomicity-caller', 'fixture')");

		self::$chores = ChoresService::GetInstance();
		self::$recipes = RecipesService::GetInstance();
		self::$stock = StockService::GetInstance();

		// For the outbox subprocess cases only (see class docblock) - matching
		// StockConcurrencyTest.php's own subprocess-driven API user, admin for simplicity.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9601, 'composed-atomicity-api', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9601, id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, 9601, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), ApiKeyService::API_KEY_TYPE_DEFAULT]);
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirrors tests/Pgsql/RecipeOperationsTest.php, the suite's existing test)
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	private static function insertProduct(string $name, array $columns = []): int
	{
		return self::insertRow('products', array_merge([
			'name' => $name,
			'location_id' => self::$ids['pantry'],
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
			'qu_id_consume' => 2,
			'qu_id_price' => 2,
		], $columns));
	}

	/** See RecipeOperationsTest::insertRecipe() for why desired_servings is set by an UPDATE. */
	private static function insertRecipe(string $name, array $columns = []): int
	{
		$columns = array_merge(['name' => $name, 'base_servings' => 1, 'desired_servings' => 1], $columns);
		$desired = $columns['desired_servings'];
		unset($columns['desired_servings']);

		$recipeId = self::insertRow('recipes', $columns);

		$statement = self::$db->prepare('UPDATE recipes SET desired_servings = ? WHERE id = ?');
		$statement->execute([$desired, $recipeId]);

		return $recipeId;
	}

	private static function addIngredient(int $recipeId, int $productId, float $amount): int
	{
		return self::insertRow('recipes_pos', [
			'recipe_id' => $recipeId,
			'product_id' => $productId,
			'amount' => $amount,
			'qu_id' => 2,
		]);
	}

	/** Books stock through the real purchase path. */
	private static function stockUp(int $productId, float $amount): void
	{
		self::$stock->AddProduct($productId, $amount, '2035-06-30', StockService::TRANSACTION_TYPE_PURCHASE, '2026-04-01', 1.0);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);

		return (float)$statement->fetchColumn();
	}

	private static function highestStockLogId(): int
	{
		return (int)self::$db->query('SELECT COALESCE(MAX(id), 0) FROM stock_log')->fetchColumn();
	}

	/** Every stock_log row written after $afterId, which is how one call's booking is isolated. */
	private static function stockLogSince(int $afterId): array
	{
		$statement = self::$db->prepare('SELECT product_id, amount, transaction_type FROM stock_log WHERE id > ? ORDER BY id');
		$statement->execute([$afterId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function choreLogCount(int $choreId): int
	{
		$statement = self::$db->prepare('SELECT COUNT(*) FROM chores_log WHERE chore_id = ?');
		$statement->execute([$choreId]);

		return (int)$statement->fetchColumn();
	}

	private static function outboxCount(): int
	{
		return (int)self::$db->query('SELECT COUNT(*) FROM outbox')->fetchColumn();
	}

	/**
	 * Starts request-subprocess-helper.php with VICTUAL_INFLUXDB_ENABLED=true, so
	 * BookingEventPublisher::RecordTransaction() actually enqueues an outbox row for whatever
	 * this call books - see the class docblock for why this needs a subprocess rather than a
	 * direct call. No INFLUXDB_URL/ORG/BUCKET: this harness builds its own minimal app rather
	 * than requiring app.php (see request-subprocess-helper.php), so ConfigurationValidator's
	 * check that those are set when INFLUXDB_ENABLED is true never runs here, and the request
	 * ends (this method only asserts the enqueue, synchronous inside the booking's own
	 * transaction) before DatabaseService's shutdown-handler drain - which this harness's
	 * injected connection never registers in the first place - would ever try to reach one.
	 *
	 * Copies StockConcurrencyTest.php's own startSubprocess()/finishSubprocess(), which this
	 * fix's reservation does not permit editing directly - see this class's docblock.
	 *
	 * @return array{status: int, stderr: string, body?: string}
	 */
	private static function requestWithInfluxEnabled(string $method, string $path, ?array $body = null): array
	{
		$spec = array_filter(
			['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey], 'body' => $body],
			fn ($value) => $value !== null
		);

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
			'VICTUAL_INFLUXDB_ENABLED' => 'true',
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

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

	// ------------------------------------------------------------------------------
	// Fixture graph
	// ------------------------------------------------------------------------------

	public function testCreatesFixtures(): void
	{
		self::$ids['pantry'] = self::insertRow('locations', ['name' => 'Composed Atomicity Pantry']);

		self::assertGreaterThan(0, self::$ids['pantry']);
	}

	// ------------------------------------------------------------------------------
	// ChoresService::TrackChore()
	// ------------------------------------------------------------------------------

	/**
	 * Given a chore configured to consume 2 units of its linked product on execution, with
	 * only 1 unit in stock,
	 * When the chore is tracked,
	 * Then ConsumeProduct() refuses ("cannot be > current stock amount") and the execution
	 * log entry TrackChore() had already saved before calling it must not survive either.
	 * Issue #494/H5's confirmed defect was exactly one live chores_log row surviving this
	 * refusal.
	 */
	public function testChoreExecutionWithInsufficientStockLeavesNoLogEntry(): void
	{
		$productId = self::insertProduct('Composed Chore Insufficient Stock');
		self::stockUp($productId, 1);

		$choreId = self::insertRow('chores', [
			'name' => 'Composed Atomicity Chore Refused',
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1,
			'product_id' => $productId,
			'product_amount' => 2,
		]);

		$watermark = self::highestStockLogId();

		try
		{
			self::$chores->TrackChore($choreId, '2026-09-26 12:00:00');
			self::fail('Tracking a chore that cannot consume its linked product must throw');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('cannot be > current stock amount', $exception->getMessage());
		}

		self::assertSame(0, self::choreLogCount($choreId),
			'The refused consumption leaves no execution log entry behind - issue #494/H5');
		self::assertSame(1.0, self::stockAmount($productId), 'Stock is untouched by the refusal');
		self::assertSame([], self::stockLogSince($watermark), 'No stock_log row was written for the refused consumption');
	}

	/**
	 * The control case for the above: the same shape, with stock enough to cover the
	 * consumption, commits the log entry and the consumption together.
	 */
	public function testChoreExecutionWithSufficientStockCommitsBoth(): void
	{
		$productId = self::insertProduct('Composed Chore Sufficient Stock');
		self::stockUp($productId, 5);

		$choreId = self::insertRow('chores', [
			'name' => 'Composed Atomicity Chore Succeeds',
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1,
			'product_id' => $productId,
			'product_amount' => 1,
		]);

		$watermark = self::highestStockLogId();

		$executionId = self::$chores->TrackChore($choreId, '2026-09-26 12:00:00');

		self::assertGreaterThan(0, $executionId, 'The execution id is returned as before');
		self::assertSame(1, self::choreLogCount($choreId), 'One execution is logged');
		self::assertSame(4.0, self::stockAmount($productId), 'The linked product was consumed once');

		$booked = self::stockLogSince($watermark);
		self::assertCount(1, $booked, 'Exactly one stock_log row was written');
		self::assertSame(-1.0, (float)$booked[0]['amount']);
		self::assertSame(StockService::TRANSACTION_TYPE_CONSUME, $booked[0]['transaction_type']);
	}

	/**
	 * The outbox half of testChoreExecutionWithInsufficientStockLeavesNoLogEntry(), run with
	 * InfluxDB event writing turned on (see class docblock) so that
	 * BookingEventPublisher::RecordTransaction() actually has something to enqueue if the
	 * refused consumption were to reach it.
	 */
	public function testChoreExecutionRefusalEnqueuesNoOutboxRowWithInfluxEnabled(): void
	{
		$productId = self::insertProduct('Outbox Chore Insufficient Stock');
		self::stockUp($productId, 1);

		$choreId = self::insertRow('chores', [
			'name' => 'Outbox Atomicity Chore Refused',
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1,
			'product_id' => $productId,
			'product_amount' => 2,
		]);

		$outboxBefore = self::outboxCount();

		// An empty array body, not null: TrackChoreExecution() parses the body unconditionally
		// (every field in it is optional, but GetParsedAndFilteredRequestBody() still requires
		// a Content-Type: application/json), which request-subprocess-helper.php only sets
		// when a body is present.
		$result = self::requestWithInfluxEnabled('POST', '/api/chores/' . $choreId . '/execute', []);

		self::assertSame(400, $result['status'], 'The chore execution is refused over the subprocess too: ' . $result['body']);
		self::assertStringContainsString('cannot be > current stock amount', $result['body'],
			'refused for the intended reason, not some other 400');
		self::assertSame($outboxBefore, self::outboxCount(),
			'No outbox row was enqueued for the refused consumption, with InfluxDB event writing turned on');
	}

	/**
	 * The outbox half of testChoreExecutionWithSufficientStockCommitsBoth(): with InfluxDB
	 * event writing turned on, the committed consumption does enqueue an outbox row - proving
	 * the case above is a real refusal to enqueue, not an inability to enqueue at all.
	 */
	public function testChoreExecutionSuccessEnqueuesAnOutboxRowWithInfluxEnabled(): void
	{
		$productId = self::insertProduct('Outbox Chore Sufficient Stock');
		self::stockUp($productId, 5);

		$choreId = self::insertRow('chores', [
			'name' => 'Outbox Atomicity Chore Succeeds',
			'period_type' => ChoresService::CHORE_PERIOD_TYPE_MANUALLY,
			'consume_product_on_execution' => 1,
			'product_id' => $productId,
			'product_amount' => 1,
		]);

		$outboxBefore = self::outboxCount();

		$result = self::requestWithInfluxEnabled('POST', '/api/chores/' . $choreId . '/execute', []);

		self::assertSame(200, $result['status'], 'The chore execution succeeds over the subprocess too: ' . $result['body']);

		$newRows = self::$db->query('SELECT event_type FROM outbox ORDER BY id OFFSET ' . $outboxBefore)->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(1, $newRows, 'Exactly one outbox row was enqueued for the committed consumption');
		self::assertSame('stock.transaction_booked', $newRows[0]);
	}

	// ------------------------------------------------------------------------------
	// RecipesService::ConsumeRecipe()
	// ------------------------------------------------------------------------------

	/**
	 * Given a recipe whose one ingredient is in stock but whose "produces product" is
	 * inactive,
	 * When the recipe is consumed,
	 * Then AddProduct() refuses the self-production ("does not exist or is inactive") and the
	 * ingredient consumption already booked by the same call must not survive either. Issue
	 * #494/H5's confirmed defect was exactly the ingredient stock left reduced.
	 */
	public function testRecipeConsumeWithInactiveOutputLeavesIngredientUntouched(): void
	{
		$ingredientId = self::insertProduct('Composed Recipe Ingredient Refused');
		$outputId = self::insertProduct('Composed Recipe Inactive Output', ['active' => 0]);
		self::stockUp($ingredientId, 2);

		$recipeId = self::insertRecipe('Composed Atomicity Recipe Refused', [
			'product_id' => $outputId,
			'base_servings' => 1,
			'desired_servings' => 1,
		]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();

		try
		{
			self::$recipes->ConsumeRecipe($recipeId);
			self::fail('Consuming a recipe whose output cannot be booked must throw');
		}
		catch (\Exception $exception)
		{
			self::assertSame('Product does not exist or is inactive', $exception->getMessage());
		}

		self::assertSame(2.0, self::stockAmount($ingredientId),
			'The refused self-production leaves the ingredient stock exactly as it was - issue #494/H5');
		self::assertSame([], self::stockLogSince($watermark),
			'Neither the ingredient consumption nor a self-production booking survives');

		$recipeRow = self::$db->query('SELECT product_id FROM recipes WHERE id = ' . $recipeId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame($outputId, (int)$recipeRow['product_id'], 'The recipe row itself is unaffected by the refusal');
	}

	/**
	 * The control case for the above: the same shape, with an active output, commits the
	 * ingredient consumption and the self-production together.
	 */
	public function testRecipeConsumeWithActiveOutputCommitsBoth(): void
	{
		$ingredientId = self::insertProduct('Composed Recipe Ingredient Succeeds');
		$outputId = self::insertProduct('Composed Recipe Active Output');
		self::stockUp($ingredientId, 2);

		$recipeId = self::insertRecipe('Composed Atomicity Recipe Succeeds', [
			'product_id' => $outputId,
			'base_servings' => 1,
			'desired_servings' => 1,
		]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$watermark = self::highestStockLogId();

		self::$recipes->ConsumeRecipe($recipeId);

		self::assertSame(1.0, self::stockAmount($ingredientId), 'The ingredient was consumed once');
		self::assertSame(1.0, self::stockAmount($outputId), 'The output was produced once');

		$booked = self::stockLogSince($watermark);
		self::assertCount(2, $booked, 'One consume row and one self-production row were written');

		$types = array_column($booked, 'transaction_type');
		sort($types);
		self::assertSame([StockService::TRANSACTION_TYPE_CONSUME, StockService::TRANSACTION_TYPE_SELF_PRODUCTION], $types);
	}

	/**
	 * The outbox half of testRecipeConsumeWithInactiveOutputLeavesIngredientUntouched(), run
	 * with InfluxDB event writing turned on (see class docblock) so that
	 * BookingEventPublisher::RecordTransaction() actually has something to enqueue if the
	 * refused ingredient consumption were to reach it.
	 */
	public function testRecipeConsumeRefusalEnqueuesNoOutboxRowWithInfluxEnabled(): void
	{
		$ingredientId = self::insertProduct('Outbox Recipe Ingredient Refused');
		$outputId = self::insertProduct('Outbox Recipe Inactive Output', ['active' => 0]);
		self::stockUp($ingredientId, 2);

		$recipeId = self::insertRecipe('Outbox Atomicity Recipe Refused', [
			'product_id' => $outputId,
			'base_servings' => 1,
			'desired_servings' => 1,
		]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$outboxBefore = self::outboxCount();

		$result = self::requestWithInfluxEnabled('POST', '/api/recipes/' . $recipeId . '/consume');

		self::assertSame(400, $result['status'], 'The recipe consume is refused over the subprocess too: ' . $result['body']);
		self::assertStringContainsString('does not exist or is inactive', $result['body'],
			'refused for the intended reason, not some other 400');
		self::assertSame($outboxBefore, self::outboxCount(),
			'No outbox row was enqueued for the refused ingredient consumption, with InfluxDB event writing turned on');
	}

	/**
	 * The outbox half of testRecipeConsumeWithActiveOutputCommitsBoth(): with InfluxDB event
	 * writing turned on, the committed consumption and self-production do enqueue an outbox
	 * row - proving the case above is a real refusal to enqueue, not an inability to enqueue
	 * at all.
	 */
	public function testRecipeConsumeSuccessEnqueuesAnOutboxRowWithInfluxEnabled(): void
	{
		$ingredientId = self::insertProduct('Outbox Recipe Ingredient Succeeds');
		$outputId = self::insertProduct('Outbox Recipe Active Output');
		self::stockUp($ingredientId, 2);

		$recipeId = self::insertRecipe('Outbox Atomicity Recipe Succeeds', [
			'product_id' => $outputId,
			'base_servings' => 1,
			'desired_servings' => 1,
		]);
		self::addIngredient($recipeId, $ingredientId, 1);

		$outboxBefore = self::outboxCount();

		$result = self::requestWithInfluxEnabled('POST', '/api/recipes/' . $recipeId . '/consume');

		self::assertSame(204, $result['status'], 'The recipe consume succeeds over the subprocess too: ' . $result['body']);

		// Two rows, not one: the ingredient consumption and the self-production book under
		// their own separate transaction ids (RecipesService.php's own long-standing
		// "$dummyTransactionId" for AddProduct(), unrelated to this fix), and
		// BookingEventPublisher enqueues one outbox row per transaction id.
		$newRows = self::$db->query('SELECT event_type FROM outbox ORDER BY id OFFSET ' . $outboxBefore)->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(2, $newRows, 'One outbox row for the ingredient consumption and one for the self-production');
		self::assertSame(['stock.transaction_booked', 'stock.transaction_booked'], $newRows);
	}

	// ------------------------------------------------------------------------------
	// RecipesService::CopyRecipe()
	// ------------------------------------------------------------------------------

	/**
	 * Given a recipe with one ingredient and one nested recipe, and a recipes_nestings row
	 * pre-seeded at the exact (recipe_id, includes_recipe_id) pair CopyRecipe()'s own third
	 * insert will try to write for the new copy,
	 * When the recipe is copied,
	 * Then the third insert hits recipes_nestings' UNIQUE(recipe_id, includes_recipe_id)
	 * constraint and refuses - and the two inserts CopyRecipe() already made before that
	 * point (the new recipes row and its recipes_pos copy) must not survive either.
	 *
	 * The new row's id is learned by reserving it from the recipes identity sequence
	 * directly: CopyRecipe()'s own INSERT never names an id, so the next value the sequence
	 * hands out is deterministically the one it will get.
	 */
	public function testCopyingARecipeWhoseNestingCollidesLeavesNoPartialCopy(): void
	{
		$nestedId = self::insertRecipe('Composed Atomicity Nested Recipe');
		$sourceId = self::insertRecipe('Composed Atomicity Recipe To Copy');
		$ingredientId = self::insertProduct('Composed Copy Ingredient');
		self::addIngredient($sourceId, $ingredientId, 3);
		self::insertRow('recipes_nestings', ['recipe_id' => $sourceId, 'includes_recipe_id' => $nestedId, 'servings' => 1]);

		$reservedId = (int)self::$db->query("SELECT nextval(pg_get_serial_sequence('recipes', 'id'))")->fetchColumn();
		$expectedCopyId = $reservedId + 1;

		// The collision: CopyRecipe()'s own third insert will try to write this exact pair.
		self::insertRow('recipes_nestings', ['recipe_id' => $expectedCopyId, 'includes_recipe_id' => $nestedId, 'servings' => 1]);

		$recipesBefore = (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
		$posBefore = (int)self::$db->query('SELECT COUNT(*) FROM recipes_pos')->fetchColumn();
		$nestingsBefore = (int)self::$db->query('SELECT COUNT(*) FROM recipes_nestings')->fetchColumn();

		try
		{
			self::$recipes->CopyRecipe($sourceId);
			self::fail('Copying into a colliding nesting pair must throw');
		}
		catch (\Throwable $exception)
		{
			// A raw PDOException (SQLSTATE 23505, unique_violation) propagating out of
			// ExecuteDbStatement() through InTransaction() - not a message this service
			// composes itself, so only the class and SQLSTATE are asserted.
			self::assertInstanceOf(\PDOException::class, $exception);
			self::assertSame('23505', $exception->getCode());
		}

		self::assertSame($recipesBefore, (int)self::$db->query('SELECT COUNT(*) FROM recipes')->fetchColumn(),
			'The new recipes row the first insert made does not survive the third insert\'s refusal - issue #494/H5');
		self::assertSame($posBefore, (int)self::$db->query('SELECT COUNT(*) FROM recipes_pos')->fetchColumn(),
			'The recipes_pos copy the second insert made does not survive either');
		self::assertSame($nestingsBefore, (int)self::$db->query('SELECT COUNT(*) FROM recipes_nestings')->fetchColumn(),
			'recipes_nestings holds only the two rows this test seeded, not a third partial one');
		self::assertFalse(self::$db->query('SELECT 1 FROM recipes WHERE id = ' . $expectedCopyId)->fetchColumn(),
			'The specific id the copy would have used was never created');
	}

	/**
	 * The control case for the above: an uncontested copy commits all three inserts
	 * together.
	 */
	public function testCopyingARecipeCommitsAllThreeTables(): void
	{
		$nestedId = self::insertRecipe('Composed Atomicity Control Nested Recipe');
		$sourceId = self::insertRecipe('Composed Atomicity Control Recipe To Copy');
		$ingredientId = self::insertProduct('Composed Atomicity Control Copy Ingredient');
		self::addIngredient($sourceId, $ingredientId, 2);
		self::insertRow('recipes_nestings', ['recipe_id' => $sourceId, 'includes_recipe_id' => $nestedId, 'servings' => 1]);

		$newId = (int)self::$recipes->CopyRecipe($sourceId);

		self::assertGreaterThan(0, $newId, 'The new recipe id is returned as before');

		$newRecipe = self::$db->query('SELECT name, product_id FROM recipes WHERE id = ' . $newId)->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($newRecipe, 'The new recipe row exists');
		self::assertStringStartsWith('Copy of', $newRecipe['name']);

		$newPos = self::$db->query('SELECT product_id, amount FROM recipes_pos WHERE recipe_id = ' . $newId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame($ingredientId, (int)$newPos['product_id'], 'The ingredient was copied');
		self::assertSame(2.0, (float)$newPos['amount']);

		$newNesting = self::$db->query('SELECT includes_recipe_id FROM recipes_nestings WHERE recipe_id = ' . $newId)->fetch(PDO::FETCH_ASSOC);
		self::assertSame($nestedId, (int)$newNesting['includes_recipe_id'], 'The nesting was copied');
	}
}
