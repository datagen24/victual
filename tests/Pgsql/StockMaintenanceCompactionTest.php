<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\Database\PostgresDialect;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0033 ("Stock rows are merged only by a maintenance routine, and only when they never
 * expire"): stock-row compaction moves out of AddProduct(), EditStockEntry() and
 * WeighLocation() into the explicit bin/victual-compact-stock maintenance command
 * (StockService::CompactStockEntries(), unchanged in name, changed in caller and
 * eligibility), and WeighLocation() corrects a location's stock TOTAL instead of requiring
 * one merged row. Covers acceptance prerequisites 1, 2 and 4:
 *
 * - Prerequisite 1 (eligibility, shared-stock_id guard, label concurrency):
 *   testNeverExpiringUnlabelledRowsMergeViaMaintenance,
 *   testRowsWithARealDueDateNeverMergeEvenViaExplicitMaintenance,
 *   testLabelledRowNeverMergesButItsUnlabelledSiblingsDo,
 *   testUserfieldBearingRowStaysSeparateFromMerge,
 *   testMeasuredRemainderRowStaysSeparateFromMerge,
 *   testPerUnitLabelPrefixedRowStaysSeparateFromMerge,
 *   testSharedStockIdGuardSkipsTheWholeGroup,
 *   testLabelCommittedBeforeMaintenanceLocksProtectsItsRow,
 *   testLabelIssuanceBlocksBehindAnInProgressMergeAndFailsWithoutAnOrphan.
 * - Prerequisite 2 (PR #531's atomic undo refusal, now exercised after an explicit
 *   maintenance merge rather than an inline one; interrupted/repeat runs):
 *   testUndoRefusesStockEditOldAfterExplicitMaintenanceMergeBothRowOrders,
 *   testUndoRefusesProductOpenedAfterExplicitMaintenanceMerge,
 *   testInterruptedMaintenanceRunRollsBack,
 *   testRepeatMaintenanceRunWithNoNewEligibleRowsChangesNothing,
 *   testFullConsumptionLabelRetirementIsUnrelatedToMergeExclusion.
 * - Prerequisite 4 (WeighLocation corrects a location's total):
 *   testWeighTwoIdenticalDatedRefillsThenALowerReadingConsumesTheDifference,
 *   testWeighAHigherReadingAddsANewRowWithTheSuppliedDueDate,
 *   testWeighAHigherReadingWithNoDueDateIsRefusedWithNoPartialWrite,
 *   testWeighAnUnchangedReadingBooksNothing,
 *   testWeighWithALabelledRowLeavesItUntouchedWhenTheOtherRowCoversTheDifference,
 *   testWeighExactLocationIsolation,
 *   testWeighUndoRestoresThePreWeighTotal,
 *   testWeighBookingDeltaEqualsTheCorrection,
 *   testWeighSurvivingRowsKeepTheirIds,
 *   testWeighMultiProductLocationStillRefused.
 *
 * Follows StockCoverageTest.php's/StockUndoIntegrityTest.php's own pattern: call the
 * controllers directly (no HTTP transport) except where a real second connection is needed,
 * assert on `stock`/`stock_log`/`labels` rows rather than only response shape, and verify a
 * refusal leaves every row byte-for-byte unchanged.
 */
class StockMaintenanceCompactionTest extends PgsqlSchemaTestCase
{
	/** The ADR-0033 "never expires" sentinel - the only real date value stock_splits admits. */
	private const NEVER_EXPIRES = '2999-12-31';

	private static PDO $db;
	private static \DI\Container $container;
	private static StockApiController $stock;
	private static int $locationA;
	private static int $locationB;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		// VICTUAL_USER_ID (the identity direct controller calls act as, per
		// PgsqlSchemaTestCase::Boot()) defaults to 9000 - matching StockCoverageTest's and
		// StockUndoIntegrityTest's own fixture user.
		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockmaintenance-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Maintenance A']);
		self::$locationA = (int)$location->fetchColumn();
		$location->execute(['Maintenance B']);
		self::$locationB = (int)$location->fetchColumn();
	}

	// ------------------------------------------------------------------------------
	// Helpers (mirroring StockCoverageTest.php's/StockUndoIntegrityTest.php's own)
	// ------------------------------------------------------------------------------

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');

		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	/** Calls $work, recovering a thrown HttpException into its status, and asserts the status. */
	private function expectStatus(callable $work, int $expected, string $message): array
	{
		try
		{
			$response = $work();
			$actual = $response->getStatusCode();
			$body = (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$actual = $exception->getCode();
			$body = $exception->getMessage();
		}

		self::assertSame($expected, $actual, "$message: expected $expected, got $actual ($body)");
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : ['error_message' => $body];
	}

	/** Every column of every `stock` and `stock_log` row, in id order. */
	private static function ledger(): string
	{
		return json_encode([
			'stock' => self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
			'stock_log' => self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		]);
	}

	/** Asserts $work is refused with $status AND that it wrote nothing to the ledger. */
	private function expectRefusalWithUntouchedLedger(callable $work, int $status, string $message): array
	{
		$before = self::ledger();
		$decoded = $this->expectStatus($work, $status, $message);
		self::assertSame($before, self::ledger(), "$message: the refusal must leave stock and stock_log unchanged");
		return $decoded;
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationA]);

		return (int)$statement->fetchColumn();
	}

	/** Every `stock` row of a product, in id order. */
	private static function rows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function stockAmount(int $productId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
		$statement->execute([$productId]);
		return (float)$statement->fetchColumn();
	}

	private static function stockAmountAtLocation(int $productId, int $locationId): float
	{
		$statement = self::$db->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ? AND location_id = ?');
		$statement->execute([$productId, $locationId]);
		return (float)$statement->fetchColumn();
	}

	/**
	 * $bestBeforeDate = null means a genuinely NULL best_before_date (one of ADR-0033's two
	 * "never expires" spellings) - NOT "let AddProduct() pick a default", which is what
	 * passing PHP null through to StockService::AddProduct() actually does (it resolves to
	 * the product's own default_best_before_days policy, ordinarily today's date: a REAL,
	 * merge-INeligible value, per StockService.php:332-363). There is no way to make
	 * AddProduct() itself store NULL, so a null request here purchases with a placeholder
	 * real date and then directly nulls the resulting row out - the same way EditStockEntry()
	 * can (it writes a literal null straight through, unlike AddProduct()).
	 */
	private static function purchase(int $productId, float $amount, ?string $bestBeforeDate, int $locationId, float $price = 1.0, string $purchasedDate = '2026-01-01'): array
	{
		$wantsNull = $bestBeforeDate === null;
		$response = self::$stock->AddProduct(
			self::request('POST', ['amount' => $amount, 'best_before_date' => $wantsNull ? '2222-02-02' : $bestBeforeDate, 'purchased_date' => $purchasedDate, 'price' => $price, 'location_id' => $locationId]),
			new Response(),
			['productId' => $productId]
		);
		self::assertSame(200, $response->getStatusCode(), 'Sanity: the purchase fixture itself succeeds');
		$decoded = json_decode((string)$response->getBody(), true);

		if ($wantsNull)
		{
			$stockId = $decoded[0]['stock_id'];
			self::$db->prepare('UPDATE stock SET best_before_date = NULL WHERE stock_id = ?')->execute([$stockId]);
			foreach ($decoded as &$row)
			{
				$row['best_before_date'] = null;
			}
			unset($row);
		}

		return $decoded;
	}

	private static function currentLabelEpoch(): int
	{
		return (int)self::$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
	}

	/** Issues a live stock_entry label directly (no printer/template machinery - see
	 * issue-stock-label-subprocess-helper.php's own docblock for why), on self::$db. */
	private static function issueLabel(int $stockRowId): string
	{
		self::$db->beginTransaction();
		try
		{
			$uid = (new LabelIdentityService(self::$db))->Issue('stock_entry', $stockRowId, self::currentLabelEpoch());
			self::$db->commit();
			return $uid;
		}
		catch (\Throwable $ex)
		{
			self::$db->rollBack();
			throw $ex;
		}
	}

	private static function liveLabelExistsFor(int $stockRowId): bool
	{
		$statement = self::$db->prepare("SELECT 1 FROM labels WHERE kind = 'stock_entry' AND target_id = ? AND retired_at IS NULL");
		$statement->execute([$stockRowId]);
		return $statement->fetchColumn() !== false;
	}

	/** A second, independent PDO connection into the same test schema. */
	private static function secondConnection(): PDO
	{
		$dsn = 'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME');
		$pdo = new PDO($dsn, getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$pdo->exec('SET search_path TO ' . self::Schema() . ', public');

		return $pdo;
	}

	/**
	 * Starts issue-stock-label-subprocess-helper.php without waiting for it, so the calling
	 * test can poll for it to block on the row lock a second connection already holds.
	 *
	 * @return array{0: resource, 1: array}
	 */
	private static function startIssueLabelSubprocess(int $stockRowId, int $expectedEpoch): array
	{
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

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/issue-stock-label-subprocess-helper.php', (string)$stockRowId, (string)$expectedEpoch],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		return [$process, $pipes];
	}

	/** Reads exactly one JSON line from the subprocess's stdout pipe (blocking). */
	private static function readJsonLine($pipe): array
	{
		$line = fgets($pipe);
		self::assertIsString($line, 'The subprocess must print a line before this call reads it');
		$decoded = json_decode($line, true);
		self::assertIsArray($decoded, "Expected a JSON line, got: $line");
		return $decoded;
	}

	/** Blocks until $blockerPid appears in pg_blocking_pids($waiterPid), or fails the test. */
	private static function waitUntilBlockedBy(int $waiterPid, int $blockerPid, float $timeoutSeconds = 10.0): void
	{
		$check = self::$db->prepare('SELECT 1 FROM pg_stat_activity WHERE pid = ? AND ? = ANY(pg_blocking_pids(pid))');
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$check->execute([$waiterPid, $blockerPid]);
			if ($check->fetchColumn() !== false)
			{
				return;
			}
			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail("Timed out waiting for backend $waiterPid to block behind backend $blockerPid");
	}

	private static function finishIssueLabelSubprocess(array $processAndPipes): array
	{
		[$process, $pipes] = $processAndPipes;
		$secondLine = fgets($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$decoded = json_decode((string)$secondLine, true);
		self::assertIsArray($decoded, "the issuance subprocess printed no final JSON line. stderr: $errors");
		return $decoded;
	}

	/**
	 * Starts compact-stock-subprocess-helper.php, which calls
	 * StockService::CompactStockEntries($productId) directly with no ambient lock or
	 * transaction already open - see that helper's own docblock. Not waited for: the caller
	 * polls for it to block on the product's advisory lock first.
	 *
	 * @return array{0: resource, 1: array}
	 */
	private static function startCompactSubprocess(int $productId): array
	{
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

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/compact-stock-subprocess-helper.php', (string)$productId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		return [$process, $pipes];
	}

	/**
	 * Blocks the calling PHP process (not the database) until some other backend is waiting,
	 * specifically, on the stock booking advisory lock for $productId - the same bounded
	 * poll of pg_locks StockConcurrencyTest::waitForAdvisoryWaiter() uses, reimplemented here
	 * since that method is private to its own class.
	 *
	 * @return int The waiting backend's pid
	 */
	private static function waitForAdvisoryWaiter(int $productId, float $timeoutSeconds = 10.0): int
	{
		$waiterCheck = self::$db->prepare(
			'SELECT pid FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted AND classid = ? AND objid = ? LIMIT 1'
		);
		$deadline = microtime(true) + $timeoutSeconds;

		do
		{
			$waiterCheck->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $productId]);
			$pid = $waiterCheck->fetchColumn();

			if ($pid !== false)
			{
				return (int)$pid;
			}

			usleep(20000);
		}
		while (microtime(true) < $deadline);

		self::fail('Timed out waiting for a backend to block on the stock booking advisory lock for product ' . $productId);
	}

	private static function finishCompactSubprocess(array $processAndPipes): array
	{
		[$process, $pipes] = $processAndPipes;
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the compaction subprocess printed no JSON. stdout: $output\nstderr: $errors");
		return $result;
	}

	// ================================================================================
	// Prerequisite 1: eligibility (never-expiring, unlabelled) and the shared-stock_id guard
	// ================================================================================

	/**
	 * The core positive case: two rows agreeing on every stock_splits grouping column, one
	 * with best_before_date NULL and the other with the 2999-12-31 sentinel, do NOT merge
	 * with each other (they disagree on best_before_date, itself a grouping column) but each
	 * DOES merge with a further row sharing its own value. Confirms both spellings of "never
	 * expires" are eligible, and that a real due date is not required to be identical text -
	 * only NULL-safe equality, which is what GROUP BY already gives NULL.
	 */
	public function testNeverExpiringUnlabelledRowsMergeViaMaintenance(): void
	{
		$product = self::insertProduct('Maintenance Never Expires');

		self::purchase($product, 2, null, self::$locationA, 1.0);
		self::purchase($product, 3, null, self::$locationA, 1.0);
		self::purchase($product, 4, self::NEVER_EXPIRES, self::$locationA, 1.0);
		self::purchase($product, 5, self::NEVER_EXPIRES, self::$locationA, 1.0);

		self::assertCount(4, self::rows($product), 'Sanity: nothing merges inline any more (ADR-0033 decision 1)');

		StockService::GetInstance()->CompactStockEntries($product);

		$rows = self::rows($product);
		self::assertCount(2, $rows, 'The NULL-dated pair and the sentinel-dated pair each merge into one row, but not with each other');

		$byDate = [];
		foreach ($rows as $row)
		{
			$byDate[$row['best_before_date'] ?? 'NULL'] = $row;
		}
		self::assertSame(5.0, (float)$byDate['NULL']['amount'], 'The two NULL-dated purchases summed');
		self::assertSame(9.0, (float)$byDate[self::NEVER_EXPIRES]['amount'], 'The two sentinel-dated purchases summed');
		self::assertSame(14.0, self::stockAmount($product), 'The product total is unchanged by merging');
	}

	/**
	 * #488's own closure: two rows with a REAL, finite due date, matching on every other
	 * grouping column, never merge - not inline (already true: no inline call remains) and
	 * not even through an explicit, direct CompactStockEntries() call, because
	 * migrations/0292.pgsql.sql's stock_splits excludes them from candidacy outright.
	 */
	public function testRowsWithARealDueDateNeverMergeEvenViaExplicitMaintenance(): void
	{
		$product = self::insertProduct('Maintenance Real Due Date');

		self::purchase($product, 3, '2030-01-01', self::$locationA, 1.5);
		self::purchase($product, 2, '2030-01-01', self::$locationA, 1.5);
		self::assertCount(2, self::rows($product), 'Two matching, really-dated rows exist');

		StockService::GetInstance()->CompactStockEntries($product);

		$rows = self::rows($product);
		self::assertCount(2, $rows, 'A real due date keeps the rows separate even under an explicit maintenance run');
		self::assertSame(5.0, self::stockAmount($product), 'and neither amount was touched');
	}

	/**
	 * #491's own closure: a live label protects its row from merging, but does not block its
	 * unlabelled siblings from merging with each other. Three rows agree on every grouping
	 * column and never expire; the middle one carries a live label.
	 */
	public function testLabelledRowNeverMergesButItsUnlabelledSiblingsDo(): void
	{
		$product = self::insertProduct('Maintenance Live Label');

		$first = self::purchase($product, 2, null, self::$locationA, 2.0);
		$labelled = self::purchase($product, 3, null, self::$locationA, 2.0);
		$third = self::purchase($product, 4, null, self::$locationA, 2.0);

		$labelledRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . ' AND amount = 3')->fetchColumn();
		self::issueLabel($labelledRowId);
		self::assertTrue(self::liveLabelExistsFor($labelledRowId), 'Sanity: the label is live before compaction runs');

		StockService::GetInstance()->CompactStockEntries($product);

		$rows = self::rows($product);
		self::assertCount(2, $rows, 'The labelled row stays on its own; the other two merge into one');

		$stillThere = array_values(array_filter($rows, fn($row) => (int)$row['id'] === $labelledRowId));
		self::assertCount(1, $stillThere, 'The labelled row itself was never deleted');
		self::assertSame(3.0, (float)$stillThere[0]['amount'], 'and its amount is untouched');
		self::assertTrue(self::liveLabelExistsFor($labelledRowId), 'and its label is still live');

		$merged = array_values(array_filter($rows, fn($row) => (int)$row['id'] !== $labelledRowId));
		self::assertCount(1, $merged, 'The two unlabelled rows merged into exactly one');
		self::assertSame(6.0, (float)$merged[0]['amount'], 'holding both unlabelled purchases');
	}

	/** Regression: the pre-existing userfield exclusion still holds under the new predicate. */
	public function testUserfieldBearingRowStaysSeparateFromMerge(): void
	{
		$product = self::insertProduct('Maintenance Userfield');
		self::purchase($product, 1, null, self::$locationA, 1.0);
		$fielded = self::purchase($product, 1, null, self::$locationA, 1.0);

		$fieldId = (int)self::$db->query("INSERT INTO userfields (entity, name, caption, type, sort_number) VALUES ('stock', 'maint_uf', 'Maint UF', 'text', 1) RETURNING id")->fetchColumn();
		// trg_userfield_values_special_handling_ins() (db/pgsql/baseline/06_triggers_c.sql)
		// expects a stock userfield to be inserted keyed by transaction_id, exactly as the
		// purchase form does it (the stock_id does not exist yet when a person fills in the
		// field): it resolves the transaction_id to the resulting stock_id itself and
		// deletes the transaction_id-keyed staging row unconditionally, whether or not it
		// found one to resolve. Inserting directly under the stock_id skips that staging
		// step entirely, so the trigger's unconditional DELETE removes the row with nothing
		// to replace it - not a real defect, just this test using the field the way the
		// application does rather than writing the target row directly.
		$statement = self::$db->prepare('INSERT INTO userfield_values (field_id, object_id, value) VALUES (?, ?, ?)');
		$statement->execute([$fieldId, $fielded[0]['transaction_id'], 'set']);

		StockService::GetInstance()->CompactStockEntries($product);

		self::assertCount(2, self::rows($product), 'A stock userfield value keeps its row out of the merge, unchanged by ADR-0033');
	}

	/** Regression: the pre-existing measured-remainder exclusion still holds. */
	public function testMeasuredRemainderRowStaysSeparateFromMerge(): void
	{
		$product = self::insertProduct('Maintenance Measured Remainder');
		self::purchase($product, 1, null, self::$locationA, 1.0);
		self::purchase($product, 1, null, self::$locationA, 1.0);

		$anId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . ' ORDER BY id LIMIT 1')->fetchColumn();
		self::$db->exec('UPDATE stock SET open = 1, opened_amount = 0.5, opened_qu_id = 2, opened_measured_at = now() WHERE id = ' . $anId);

		StockService::GetInstance()->CompactStockEntries($product);

		self::assertCount(2, self::rows($product), 'A measured remainder keeps its row out of the merge, unchanged by ADR-0033');
	}

	/** Regression: the pre-existing per-unit-label ("x" prefix) exclusion still holds. */
	public function testPerUnitLabelPrefixedRowStaysSeparateFromMerge(): void
	{
		$product = self::insertProduct('Maintenance Per Unit Prefix');
		self::purchase($product, 1, null, self::$locationA, 1.0);
		self::purchase($product, 1, null, self::$locationA, 1.0);

		$anId = (int)self::$db->query('SELECT id, stock_id FROM stock WHERE product_id = ' . $product . ' ORDER BY id LIMIT 1')->fetchColumn();
		$row = self::$db->query('SELECT id, stock_id FROM stock WHERE product_id = ' . $product . ' ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
		$prefixed = 'x' . $row['stock_id'];
		self::$db->exec('UPDATE stock SET stock_id = ' . self::$db->quote($prefixed) . ' WHERE id = ' . $row['id']);

		StockService::GetInstance()->CompactStockEntries($product);

		self::assertCount(2, self::rows($product), 'An "x"-prefixed stock_id keeps its row out of the merge, unchanged by ADR-0033');
	}

	/**
	 * ADR-0033 decision 3's shared-stock_id guard. A split (partial open/transfer) can leave
	 * two different `stock` rows carrying the same stock_id; if a merge group and a row
	 * outside it share one, the group is skipped entirely rather than merged, because the
	 * only rewrite CompactStockEntries() can issue ("every row with this stock_id") cannot
	 * spare the outside row. The shared id is deliberately NOT the group's stock_id_to_keep
	 * (MIN(stock_id)) - forced to sort after the other candidate's - so the rewrite this
	 * guard exists to prevent would actually fire without it.
	 */
	public function testSharedStockIdGuardSkipsTheWholeGroup(): void
	{
		$product = self::insertProduct('Maintenance Shared Stock Id');

		self::purchase($product, 2, null, self::$locationA, 1.0);
		self::purchase($product, 3, null, self::$locationA, 1.0);
		$groupRows = self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		self::assertCount(2, $groupRows, 'Sanity: two candidate rows for the group');

		// 'guardtest-a' sorts before 'guardtest-z' - MIN(stock_id) keeps 'guardtest-a', so the
		// rewrite direction is 'guardtest-z' -> 'guardtest-a', touching every row that
		// currently carries 'guardtest-z'.
		self::$db->exec('UPDATE stock SET stock_id = \'guardtest-a\' WHERE id = ' . $groupRows[0]);
		self::$db->exec('UPDATE stock SET stock_id = \'guardtest-z\' WHERE id = ' . $groupRows[1]);

		// An outside row: a different location keeps it out of this group's GROUP BY bucket,
		// but it deliberately carries the same 'guardtest-z' stock_id as $groupRows[1].
		$outsideRowId = self::insertRow('stock', [
			'product_id' => $product,
			'amount' => 7,
			'best_before_date' => null,
			'purchased_date' => '2026-01-01',
			'stock_id' => 'guardtest-z',
			'price' => 1.0,
			'location_id' => self::$locationB,
		]);

		StockService::GetInstance()->CompactStockEntries($product);

		$rows = self::rows($product);
		self::assertCount(3, $rows, 'The group is skipped outright: the merge never runs, so all three rows survive');

		$outside = array_values(array_filter($rows, fn($row) => (int)$row['id'] === $outsideRowId))[0];
		self::assertSame('guardtest-z', $outside['stock_id'], 'The outside row keeps its own stock_id - never rewritten to the group\'s kept value');
		self::assertSame(7.0, (float)$outside['amount'], 'and its amount is untouched');
		self::assertSame((float)self::$locationB, (float)$outside['location_id'], 'and its location is untouched');

		$groupSurvivors = array_values(array_filter($rows, fn($row) => in_array((int)$row['id'], $groupRows, true)));
		self::assertCount(2, $groupSurvivors, 'The two group rows also survive untouched, rather than one being deleted');
		$amounts = array_map(fn($row) => (float)$row['amount'], $groupSurvivors);
		sort($amounts);
		self::assertSame([2.0, 3.0], $amounts, 'and neither amount was folded into the other');
	}

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	// ================================================================================
	// Prerequisite 1: two-connection label concurrency
	// ================================================================================

	/**
	 * A label committed before the maintenance run takes its row locks protects the row: the
	 * re-read under the lock (ADR-0033 decision 3) sees it via stock_splits' own NOT EXISTS
	 * check, no concurrency needed to demonstrate this half - only ordering.
	 */
	public function testLabelCommittedBeforeMaintenanceLocksProtectsItsRow(): void
	{
		$product = self::insertProduct('Maintenance Label Before Lock');
		self::purchase($product, 1, null, self::$locationA, 1.0);
		self::purchase($product, 1, null, self::$locationA, 1.0);

		$rowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . ' ORDER BY id LIMIT 1')->fetchColumn();
		self::issueLabel($rowId);

		StockService::GetInstance()->CompactStockEntries($product);

		self::assertCount(2, self::rows($product), 'The label, committed before this run, protected its row - nothing merged');
		self::assertTrue(self::liveLabelExistsFor($rowId), 'and the label is still live');
	}

	/**
	 * The genuine race: a second connection holds the exact row lock
	 * CompactStockEntries() would take on a candidate row, mid-merge, before deleting it.
	 * A concurrent label issuance on that row must block on the same lock (real PostgreSQL
	 * tuple contention, not a sequenced assumption) and, once the row is actually deleted and
	 * the deleting transaction commits, fail cleanly - "not found in the requested import
	 * epoch" - rather than resurrect the row or leave a live label with no target.
	 */
	public function testLabelIssuanceBlocksBehindAnInProgressMergeAndFailsWithoutAnOrphan(): void
	{
		$product = self::insertProduct('Maintenance Label Blocks');
		self::purchase($product, 1, null, self::$locationA, 1.0);
		self::purchase($product, 1, null, self::$locationA, 1.0);
		$rows = self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
		[$keptId, $losingId] = [$rows[1], $rows[0]]; // CompactStockEntries keeps MAX(id)
		$epoch = self::currentLabelEpoch();

		$second = self::secondConnection();
		$secondBackendPid = (int)$second->query('SELECT pg_backend_pid()')->fetchColumn();

		// Simulates exactly the moment CompactStockEntries() has locked its candidate rows
		// (ascending id, ADR-0033 decision 3) but has not yet deleted the losing one.
		$second->beginTransaction();
		$second->prepare('SELECT id FROM stock WHERE id IN (?, ?) ORDER BY id ASC FOR UPDATE')->execute([$losingId, $keptId]);

		$subprocess = self::startIssueLabelSubprocess($losingId, $epoch);
		$subprocessBackendPid = self::readJsonLine($subprocess[1][1])['backend_pid'];

		self::waitUntilBlockedBy((int)$subprocessBackendPid, $secondBackendPid);

		// Now play out the rest of what CompactStockEntries() would do to the losing row -
		// deleting it - and commit, exactly as the real merge would once it reaches this
		// point under its own lock.
		$second->exec('DELETE FROM stock WHERE id = ' . $losingId);
		$second->commit();
		$second = null;

		$result = self::finishIssueLabelSubprocess($subprocess);

		self::assertSame(400, $result['status'], 'Issuance must fail once the row it was waiting to lock has been deleted');
		self::assertStringContainsString('not found', strtolower($result['error_message']), 'with a clean "not found" refusal');
		self::assertFalse(self::liveLabelExistsFor($losingId), 'No live label was left behind for the now-deleted row');
		// Scoped to this test's own two rows, not a bare COUNT(*) - other test methods in
		// this same shared schema issue labels of their own, live for the rest of the run.
		$labelsForTheseRows = self::$db->prepare('SELECT COUNT(*) FROM labels WHERE kind = ? AND target_id IN (?, ?)');
		$labelsForTheseRows->execute(['stock_entry', $losingId, $keptId]);
		self::assertSame(0, (int)$labelsForTheseRows->fetchColumn(), 'No orphan label row (live or retired) was inserted for either row in this race');
	}

	// ================================================================================
	// Prerequisite 2: atomic undo refusal after an EXPLICIT maintenance merge
	// ================================================================================

	/**
	 * Purchases two never-expiring entries differing only by due date, edits one to match
	 * the other, then runs the maintenance command explicitly (no inline call remains) to
	 * merge them - mirroring StockUndoIntegrityTest::purchaseEditAndCompact()'s fixture
	 * shape, adapted for ADR-0033: the due dates used are NULL/2999-12-31 rather than an
	 * arbitrary future date, since only those are still merge-eligible, and the merge itself
	 * is now this method's own explicit call rather than EditStockEntry()'s.
	 *
	 * @return array{0: int, 1: int} product id, the STOCK_EDIT_OLD booking id
	 */
	private function purchaseEditAndExplicitlyMerge(string $productName, float $firstAmount, ?string $firstDue, float $secondAmount, ?string $secondDue, string $editWhich): array
	{
		$product = self::insertProduct($productName);
		$purchasedDate = '2026-01-01';
		$price = 1.5;

		self::purchase($product, $firstAmount, $firstDue, self::$locationA, $price, $purchasedDate);
		self::purchase($product, $secondAmount, $secondDue, self::$locationA, $price, $purchasedDate);
		self::assertCount(2, self::rows($product), 'The differing due dates keep the two entries apart before the edit');

		$targetDue = $editWhich === 'second' ? $secondDue : $firstDue;
		$newDue = $editWhich === 'second' ? $firstDue : $secondDue;
		$targetAmount = $editWhich === 'second' ? $secondAmount : $firstAmount;

		$entryId = self::$db->prepare('SELECT id FROM stock WHERE product_id = ? AND best_before_date IS NOT DISTINCT FROM ?');
		$entryId->execute([$product, $targetDue]);
		$entryId = (int)$entryId->fetchColumn();

		$edit = $this->expectStatus(
			fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => $targetAmount, 'best_before_date' => $newDue, 'open' => false, 'purchased_date' => $purchasedDate, 'price' => $price, 'location_id' => self::$locationA]), new Response(), ['entryId' => $entryId]),
			200,
			'Its due date is edited to match the other entry (no longer triggers compaction itself - ADR-0033 decision 1)'
		);
		self::assertCount(2, self::rows($product), 'The edit alone does not merge the two entries any more');

		StockService::GetInstance()->CompactStockEntries($product);
		self::assertCount(1, self::rows($product), 'The explicit maintenance run merges the two entries into one');
		self::assertSame($firstAmount + $secondAmount, self::stockAmount($product), 'holding both purchases\' units');

		$editOld = array_values(array_filter($edit, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_STOCK_EDIT_OLD))[0];

		return [$product, (int)$editOld['id']];
	}

	/**
	 * #488 C1, PR #531's fix, now exercised behind an explicit maintenance merge instead of
	 * an inline one. Both row-id orders: the edited entry can end up as either the row
	 * CompactStockEntries() keeps or the one it deletes, depending on which purchase's id is
	 * larger, and the refusal must hold either way.
	 */
	public function testUndoRefusesStockEditOldAfterExplicitMaintenanceMergeBothRowOrders(): void
	{
		[$productA, $editOldA] = $this->purchaseEditAndExplicitlyMerge('Maintenance Undo Edit First', 3, self::NEVER_EXPIRES, 2, null, 'second');
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldA]),
			400,
			'Undoing the edit after an explicit maintenance merge is refused (row order: edited entry keeps the lower id)'
		);

		[$productB, $editOldB] = $this->purchaseEditAndExplicitlyMerge('Maintenance Undo Edit Second', 3, null, 2, self::NEVER_EXPIRES, 'first');
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $editOldB]),
			400,
			'Undoing the edit after an explicit maintenance merge is refused (row order: edited entry keeps the higher id)'
		);
	}

	/**
	 * #488 C1's second repro, behind an explicit maintenance merge: two purchases merge (via
	 * the maintenance command, not inline), two partial opens on the merged row also merge on
	 * a matching purchase elsewhere, and undoing one of the two now-indistinguishable openings
	 * is refused rather than silently closing the wrong one.
	 */
	public function testUndoRefusesProductOpenedAfterExplicitMaintenanceMerge(): void
	{
		$product = self::insertProduct('Maintenance Undo Opened');
		self::purchase($product, 2, self::NEVER_EXPIRES, self::$locationA, 1.0);
		self::purchase($product, 3, self::NEVER_EXPIRES, self::$locationA, 1.0);
		StockService::GetInstance()->CompactStockEntries($product);
		self::assertCount(1, self::rows($product), 'The two purchases are merged into one entry of five by the explicit run');

		$openFirst = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'One unit is opened'
		);

		// A further matching purchase, then another explicit maintenance run, gives the
		// opened portion a same-shaped sibling to become indistinguishable from.
		self::purchase($product, 4, self::NEVER_EXPIRES, self::$locationA, 1.0);
		$secondOpenLog = $this->expectStatus(
			fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1]), new Response(), ['productId' => $product]),
			200,
			'A second, separately-booked one-unit opening'
		);
		StockService::GetInstance()->CompactStockEntries($product);

		$openedRows = array_filter(self::rows($product), fn($row) => (int)$row['open'] === 1);
		self::assertCount(1, $openedRows, 'The two one-unit opened portions merged into a single opened row');
		self::assertSame(2.0, (float)array_values($openedRows)[0]['amount'], 'holding both opened units');

		// Both surviving-row orders (ADR-0033 acceptance prerequisite 2): the merge keeps
		// MAX(id) of the two opened portions and deletes the other, so undoing whichever
		// booking named the DELETED row hits the "stock entry no longer exists" branch,
		// and undoing the one that named the SURVIVING row hits the same guard's other
		// branch - the row exists, but the merge overwrote its amount with the group's sum,
		// so it no longer matches what that specific booking recorded. Both must refuse.
		$openLogId = (int)$openFirst[0]['id'];
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $openLogId]),
			400,
			'Undoing the opening whose row the merge deleted is refused, not silently closing the wrong one'
		);

		$secondOpenLogId = (int)$secondOpenLog[0]['id'];
		$this->expectRefusalWithUntouchedLedger(
			fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => $secondOpenLogId]),
			400,
			'Undoing the opening whose row the merge kept is also refused - its amount no longer matches what this booking recorded'
		);
	}

	/**
	 * Deterministically interrupts a maintenance run mid-transaction: connection B holds the
	 * product's advisory lock (LockProductStock()'s own lock, PostgresDialect::
	 * STOCK_BOOKING_ADVISORY_LOCK_CLASS), so CompactStockEntries() - started in a real
	 * subprocess via the existing compact-stock-subprocess-helper.php, with no ambient lock
	 * of its own, exactly the "maintenance sweep" shape that helper documents - blocks on it
	 * before doing anything else. Once confirmed genuinely waiting (polled through
	 * pg_locks, not assumed from timing), pg_cancel_backend() sends it a real
	 * query_canceled (57014) while it is inside InTransaction()'s try block, exercising the
	 * exact rollback path that method already provides. Nothing about the merge may have
	 * partially applied once the exception has propagated - the run never got past waiting
	 * for its own first lock.
	 */
	public function testInterruptedMaintenanceRunRollsBack(): void
	{
		$product = self::insertProduct('Maintenance Interrupted Run');
		self::purchase($product, 2, null, self::$locationA, 1.0);
		self::purchase($product, 3, null, self::$locationA, 1.0);
		self::assertCount(2, self::rows($product), 'Sanity: two separate candidate rows before the interrupted run');

		$second = self::secondConnection();
		$second->beginTransaction();
		$second->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([PostgresDialect::STOCK_BOOKING_ADVISORY_LOCK_CLASS, $product]);

		$subprocess = self::startCompactSubprocess($product);
		$waiterPid = self::waitForAdvisoryWaiter($product);

		$cancelled = (bool)self::$db->query('SELECT pg_cancel_backend(' . $waiterPid . ')')->fetchColumn();
		self::assertTrue($cancelled, 'Sanity: pg_cancel_backend() found the waiting backend');

		$result = self::finishCompactSubprocess($subprocess);
		$second->rollBack();

		self::assertSame(400, $result['status'], 'The cancelled run reports failure rather than silently succeeding: ' . ($result['error_message'] ?? ''));

		$rows = self::rows($product);
		self::assertCount(2, $rows, 'The interrupted run left both rows exactly as they were - it never got past waiting for its own lock');
		$amounts = array_map(fn($row) => (float)$row['amount'], $rows);
		sort($amounts);
		self::assertSame([2.0, 3.0], $amounts, 'and neither amount changed');
	}

	/** Idempotence: a run that finds nothing newly eligible changes nothing, including identity. */
	public function testRepeatMaintenanceRunWithNoNewEligibleRowsChangesNothing(): void
	{
		$product = self::insertProduct('Maintenance Repeat Run');
		self::purchase($product, 2, null, self::$locationA, 1.0);
		self::purchase($product, 3, null, self::$locationA, 1.0);

		StockService::GetInstance()->CompactStockEntries($product);
		$afterFirstRun = self::rows($product);
		self::assertCount(1, $afterFirstRun, 'The first run merges the pair');

		StockService::GetInstance()->CompactStockEntries($product);
		$afterSecondRun = self::rows($product);

		self::assertSame($afterFirstRun, $afterSecondRun, 'A repeat run with nothing newly eligible (only one row remains, so stock_splits has no group for it) changes nothing at all - same id, same stock_id, same amount');
	}

	/**
	 * Full-consumption label retirement is ordinary behaviour, unrelated to the merge
	 * exclusion above: consuming a labelled row to zero deletes it, and
	 * retire_stock_entry_labels (migrations/0283.pgsql.php) retires the label exactly as it
	 * would for any other stock row, merge or no merge.
	 */
	public function testFullConsumptionLabelRetirementIsUnrelatedToMergeExclusion(): void
	{
		$product = self::insertProduct('Maintenance Full Consume Retirement');
		self::purchase($product, 2, null, self::$locationA, 1.0);
		$rowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product)->fetchColumn();
		self::issueLabel($rowId);
		self::assertTrue(self::liveLabelExistsFor($rowId), 'Sanity: the label is live before consumption');

		$this->expectStatus(
			fn() => self::$stock->ConsumeProduct(self::request('POST', ['amount' => 2]), new Response(), ['productId' => $product]),
			200,
			'The full two units are consumed'
		);

		self::assertCount(0, self::rows($product), 'The row is gone - a full consume deletes it, same as always');
		self::assertFalse(self::liveLabelExistsFor($rowId), 'and its label is retired - ordinary DELETE-triggered retirement, not a merge exclusion');
	}

	// ================================================================================
	// Prerequisite 4: WeighLocation() corrects the location's TOTAL (ADR-0033 decision 5)
	// ================================================================================

	/** A vessel location (tare_weight = 0, tare_qu_id = the fixture stock unit 2). */
	private static function insertVesselLocation(): int
	{
		$statement = self::$db->prepare('INSERT INTO locations (name, tare_weight, tare_qu_id) VALUES (?, 0, 2) RETURNING id');
		$statement->execute(['Maintenance Vessel ' . bin2hex(random_bytes(4))]);

		return (int)$statement->fetchColumn();
	}

	private function weigh(int $locationId, float $grossAmount, ?string $bestBeforeDate = null)
	{
		$body = ['gross_amount' => $grossAmount];
		if ($bestBeforeDate !== null)
		{
			$body['best_before_date'] = $bestBeforeDate;
		}

		return self::$stock->WeighLocation(self::request('POST', $body), new Response(), ['locationId' => $locationId]);
	}

	/**
	 * Two refills sharing a real due date (so neither is merge-eligible, and none is merged
	 * here anyway - ADR-0033 removed WeighLocation()'s own compaction call) sit at the same
	 * vessel; a lower reading consumes the difference in ordinary consume order (earliest due
	 * date first - both share one here, so purchased_date breaks the tie) restricted to this
	 * location, leaving the rest of the earlier-inserted row and the later one entirely alone.
	 */
	public function testWeighTwoIdenticalDatedRefillsThenALowerReadingConsumesTheDifference(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Two Refills');

		self::purchase($product, 3, '2028-01-01', $vessel, 2.0, '2026-01-01');
		self::purchase($product, 5, '2028-01-01', $vessel, 2.0, '2026-02-01');
		self::assertCount(2, self::rows($product), 'Two separate dated refills sit at the vessel');
		self::assertSame(8.0, self::stockAmountAtLocation($product, $vessel), 'Sanity: eight units on hand there');

		$result = $this->expectStatus(
			fn() => $this->weigh($vessel, 6),
			200,
			'Weighing to 6 (a lower reading) is accepted'
		);

		self::assertSame(6.0, self::stockAmountAtLocation($product, $vessel), 'The location total now matches the reading');
		self::assertNotEmpty($result, 'A booking was written for the lower reading');
		self::assertCount(2, self::rows($product), 'Both rows still exist - the correction reduced the earlier-purchased one, it did not merge or replace either');
	}

	/**
	 * A higher reading adds one new row with the caller-supplied due date, price/shopping
	 * location/purchased date resolved the same way InventoryProduct()'s own positive
	 * correction resolves them, and leaves the existing row completely alone.
	 */
	public function testWeighAHigherReadingAddsANewRowWithTheSuppliedDueDate(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Higher');

		self::purchase($product, 2, '2028-01-01', $vessel, 3.0, '2026-01-01');
		$existingRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product)->fetchColumn();

		$result = $this->expectStatus(
			fn() => $this->weigh($vessel, 5, '2031-12-31'),
			200,
			'Weighing to 5 (a higher reading) with an explicit due date is accepted'
		);

		self::assertSame(5.0, self::stockAmountAtLocation($product, $vessel), 'The location total matches the higher reading');
		$rows = self::rows($product);
		self::assertCount(2, $rows, 'One new row was added - the existing one was not merged into it');

		$existing = array_values(array_filter($rows, fn($row) => (int)$row['id'] === $existingRowId))[0];
		self::assertSame(2.0, (float)$existing['amount'], 'The existing row keeps its own amount');
		self::assertSame('2028-01-01', $existing['best_before_date'], 'and its own due date');

		$new = array_values(array_filter($rows, fn($row) => (int)$row['id'] !== $existingRowId))[0];
		self::assertSame(3.0, (float)$new['amount'], 'The new row holds exactly the difference');
		self::assertSame('2031-12-31', $new['best_before_date'], 'with the caller-supplied due date');
		self::assertSame(3.0, (float)$new['price'], 'InventoryProduct()\'s own positive-correction default: the product\'s last price');
		self::assertSame(date('Y-m-d'), $new['purchased_date'], 'and purchased today, the same default InventoryProduct() uses');

		$correction = array_values(array_filter($result, fn($row) => $row['transaction_type'] === StockService::TRANSACTION_TYPE_INVENTORY_CORRECTION))[0];
		self::assertSame(3.0, (float)$correction['amount'], 'The booking records exactly the added difference');
	}

	/**
	 * ADR-0033's own requirement: a higher reading with no best_before_date is refused with a
	 * clear message, never guessing a date, and leaves no partial write - the refusal happens
	 * before AddProduct() is even called.
	 */
	public function testWeighAHigherReadingWithNoDueDateIsRefusedWithNoPartialWrite(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Higher No Date');
		self::purchase($product, 2, '2028-01-01', $vessel, 1.0);

		$decoded = $this->expectRefusalWithUntouchedLedger(
			fn() => $this->weigh($vessel, 5),
			400,
			'A higher reading with no best_before_date is refused'
		);
		self::assertStringContainsString('best_before_date', $decoded['error_message'] ?? $decoded['ErrorMessage'] ?? json_encode($decoded));

		self::assertCount(1, self::rows($product), 'No row was added');
		self::assertSame(2.0, self::stockAmountAtLocation($product, $vessel), 'and the total is exactly what it was before the attempt');
	}

	/** A matching reading (within ADR-0032 tolerance) books nothing and still answers 200. */
	public function testWeighAnUnchangedReadingBooksNothing(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Unchanged');
		self::purchase($product, 4, '2028-01-01', $vessel, 1.0);

		$before = self::ledger();
		$result = $this->expectStatus(
			fn() => $this->weigh($vessel, 4),
			200,
			'Weighing exactly what is on record is accepted'
		);

		self::assertSame([], $result, 'An empty array - nothing was booked (ADR-0033 decision 5)');
		self::assertSame($before, self::ledger(), 'and stock/stock_log are byte-for-byte unchanged');
	}

	/**
	 * A labelled row is left completely alone when the other (unlabelled, earlier-due) row
	 * alone covers the difference - existing rows, labelled or not, keep their due dates and
	 * identities either way (ADR-0033 decision 5's own text).
	 */
	public function testWeighWithALabelledRowLeavesItUntouchedWhenTheOtherRowCoversTheDifference(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Labelled Row');

		self::purchase($product, 3, '2027-01-01', $vessel, 1.0, '2026-01-01'); // consumed first (earlier due date)
		self::purchase($product, 5, '2027-06-01', $vessel, 1.0, '2026-01-01'); // to be labelled
		$labelledId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $product . " AND best_before_date = '2027-06-01'")->fetchColumn();
		self::issueLabel($labelledId);

		$this->expectStatus(fn() => $this->weigh($vessel, 6), 200, 'Weighing down by 2, fully covered by the earlier-due row');

		self::assertSame(6.0, self::stockAmountAtLocation($product, $vessel), 'The total matches the reading');
		$labelledRow = self::$db->query('SELECT * FROM stock WHERE id = ' . $labelledId)->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($labelledRow, 'The labelled row still exists');
		self::assertSame(5.0, (float)$labelledRow['amount'], 'with its amount untouched');
		self::assertSame('2027-06-01', $labelledRow['best_before_date'], 'its own due date untouched');
		self::assertTrue(self::liveLabelExistsFor($labelledId), 'and its label still live');
	}

	/** Weighing one location never touches a different location's stock of the same product. */
	public function testWeighExactLocationIsolation(): void
	{
		$vesselA = self::insertVesselLocation();
		$vesselB = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Isolation');

		self::purchase($product, 4, '2028-01-01', $vesselA, 1.0);
		self::purchase($product, 9, '2028-01-01', $vesselB, 1.0);

		$this->expectStatus(fn() => $this->weigh($vesselA, 2), 200, 'Weighing A down');

		self::assertSame(2.0, self::stockAmountAtLocation($product, $vesselA), 'A reflects the correction');
		self::assertSame(9.0, self::stockAmountAtLocation($product, $vesselB), 'B is completely untouched');
		self::assertCount(1, self::rows($product) === null ? [] : array_filter(self::rows($product), fn($row) => (int)$row['location_id'] === $vesselB), 'B still holds exactly its one original row');
	}

	/** Undoing a weighing's booking restores the pre-weigh total, for both directions. */
	public function testWeighUndoRestoresThePreWeighTotal(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Undo');
		self::purchase($product, 6, '2028-01-01', $vessel, 1.0);

		$lower = $this->expectStatus(fn() => $this->weigh($vessel, 2), 200, 'Weigh down to 2');
		self::assertSame(2.0, self::stockAmountAtLocation($product, $vessel));
		foreach (array_reverse($lower) as $booking)
		{
			$this->expectStatus(
				fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$booking['id']]),
				204,
				'Undoing the consume correction'
			);
		}
		self::assertSame(6.0, self::stockAmountAtLocation($product, $vessel), 'The pre-weigh total is restored after undo');

		$higher = $this->expectStatus(fn() => $this->weigh($vessel, 10, '2030-01-01'), 200, 'Weigh up to 10');
		self::assertSame(10.0, self::stockAmountAtLocation($product, $vessel));
		foreach (array_reverse($higher) as $booking)
		{
			$this->expectStatus(
				fn() => self::$stock->UndoBooking(self::request('POST'), new Response(), ['bookingId' => (int)$booking['id']]),
				204,
				'Undoing the positive correction'
			);
		}
		self::assertSame(6.0, self::stockAmountAtLocation($product, $vessel), 'and restored again after undoing the higher correction');
	}

	/** The sum of the booking amounts a weighing writes equals exactly the correction, either direction. */
	public function testWeighBookingDeltaEqualsTheCorrection(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Delta');
		self::purchase($product, 7, '2028-01-01', $vessel, 1.0);

		$lower = $this->expectStatus(fn() => $this->weigh($vessel, 4), 200, 'Weigh down by 3');
		self::assertEqualsWithDelta(-3.0, array_sum(array_map(fn($row) => (float)$row['amount'], $lower)), 1e-9, 'The consume booking(s) sum to exactly -3');

		$higher = $this->expectStatus(fn() => $this->weigh($vessel, 9, '2030-01-01'), 200, 'Weigh up by 5');
		self::assertEqualsWithDelta(5.0, array_sum(array_map(fn($row) => (float)$row['amount'], $higher)), 1e-9, 'The positive-correction booking sums to exactly +5');
	}

	/** Surviving rows keep their own `stock.id` - a correction never deletes-and-recreates them. */
	public function testWeighSurvivingRowsKeepTheirIds(): void
	{
		$vessel = self::insertVesselLocation();
		$product = self::insertProduct('Maintenance Weigh Keeps Ids');
		self::purchase($product, 3, '2027-01-01', $vessel, 1.0, '2026-01-01');
		self::purchase($product, 10, '2029-01-01', $vessel, 1.0, '2026-01-01');
		$idsBefore = array_map(fn($row) => (int)$row['id'], self::rows($product));

		$this->expectStatus(fn() => $this->weigh($vessel, 11), 200, 'Weigh down by 2, covered by the earlier-due row alone');

		$idsAfter = array_map(fn($row) => (int)$row['id'], self::rows($product));
		sort($idsBefore);
		sort($idsAfter);
		self::assertSame($idsBefore, $idsAfter, 'Both original row ids still exist - nothing was deleted and recreated');
	}

	/** Regression: still refused when more than one product sits at the location. */
	public function testWeighMultiProductLocationStillRefused(): void
	{
		$vessel = self::insertVesselLocation();
		$productA = self::insertProduct('Maintenance Weigh Multi A');
		$productB = self::insertProduct('Maintenance Weigh Multi B');
		self::purchase($productA, 2, '2028-01-01', $vessel, 1.0);
		self::purchase($productB, 2, '2028-01-01', $vessel, 1.0);

		$this->expectRefusalWithUntouchedLedger(
			fn() => $this->weigh($vessel, 5),
			400,
			'Weighing a vessel holding two different products is still refused'
		);
	}
}
