<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Victual\Services\DatabaseService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0033 acceptance prerequisite 3 / round 3c item 1/3: bin/victual-compact-stock's own
 * documented privilege list, proved against a real PostgreSQL role holding EXACTLY those
 * grants (see V580R2ProbeTest.php's testProbe3DocumentedPrivilegesSuffice, a round-2
 * throwaway validator probe this test supersedes with the corrected, complete list).
 *
 * Round 2's list was missing SELECT on the views stock_splits, products_average_price and
 * products_last_purchased (stock_splits is CompactStockEntries()'s very first read), and
 * SELECT/INSERT/UPDATE on cache__products_average_price and cache__products_last_purchased
 * (written by the stock_log_UPD trigger, which is SECURITY INVOKER - see
 * db/pgsql/baseline/06_triggers_b.sql - and so runs under this caller's own rights whenever a
 * merge rewrites a stock_log row). Round 3 kept userfield_values/userfields from round 2's
 * list without being able to prove they were needed; round 3b removed them outright -
 * retire_stock_entry_labels() (migrations/0283.pgsql.php) reads only `products`, and
 * stock_splits' own userfield-eligibility check runs through the view, which reads its
 * underlying tables as the view's OWNER, not this caller. Round 3c adds SELECT+UPDATE on
 * system_db_changed_time (the real command's process-exit handler flushes a deferred changed-
 * time write there - PostgresDialect::FlushDbChangedTime(), db/DatabaseService's
 * RegisterShutdownHandler() - and DatabaseService swallows a failure there, so the command
 * exits 0 even though clients never see the merge) and drops INSERT from stock_entry_origins
 * (CompactStockEntries() only ever UPDATEs or DELETEs existing rows there; new rows are
 * written by RecordSplitOrigin(), called only from OpenProduct(), never from the
 * compaction path). Every grant that remains is proved necessary below.
 *
 * Sufficiency: a role holding exactly the corrected list runs a real merge end to end AND the
 * same shutdown changed-time flush the real command's process exit runs afterwards, asserting
 * changed_time actually advanced rather than only that nothing threw.
 * Necessity: dropping any ONE grant from that list makes the merge OR that flush fail -
 * proving every grant on the list is load-bearing, not merely harmless.
 */
class StockMaintenanceCommandPrivilegesTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockApiController $stock;
	private static \DI\Container $container;
	private static int $locationA;

	/**
	 * The corrected, complete grant list bin/victual-compact-stock's own docblock now
	 * documents, as (SQL fragment, human label) pairs so a necessity failure names which
	 * grant was missing.
	 */
	private const GRANTS = [
		['GRANT SELECT, UPDATE, DELETE ON %SCHEMA%.stock TO %ROLE%', 'stock'],
		['GRANT SELECT, UPDATE ON %SCHEMA%.stock_log TO %ROLE%', 'stock_log'],
		['GRANT SELECT, UPDATE, DELETE ON %SCHEMA%.stock_entry_origins TO %ROLE%', 'stock_entry_origins'],
		['GRANT SELECT, UPDATE ON %SCHEMA%.labels TO %ROLE%', 'labels'],
		['GRANT SELECT ON %SCHEMA%.products TO %ROLE%', 'products'],
		['GRANT SELECT ON %SCHEMA%.stock_splits TO %ROLE%', 'stock_splits (view)'],
		['GRANT SELECT ON %SCHEMA%.products_average_price TO %ROLE%', 'products_average_price (view)'],
		['GRANT SELECT ON %SCHEMA%.products_last_purchased TO %ROLE%', 'products_last_purchased (view)'],
		['GRANT SELECT, INSERT, UPDATE ON %SCHEMA%.cache__products_average_price TO %ROLE%', 'cache__products_average_price'],
		['GRANT SELECT, INSERT, UPDATE ON %SCHEMA%.cache__products_last_purchased TO %ROLE%', 'cache__products_last_purchased'],
		['GRANT SELECT, UPDATE ON %SCHEMA%.system_db_changed_time TO %ROLE%', 'system_db_changed_time'],
	];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$container = new \DI\Container();
		self::$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		self::$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController(self::$container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'privileges-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Privileges A']);
		self::$locationA = (int)$location->fetchColumn();
	}

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationA]);

		return (int)$statement->fetchColumn();
	}

	/**
	 * Two never-expiring, otherwise-identical purchases through the real AddProduct() flow -
	 * not a bare INSERT into `stock` - so each one has a genuine stock_log booking row too.
	 * That matters here: CompactStockEntries()'s stock_log rewrite is what fires the
	 * stock_log_UPD trigger that reads products_average_price/products_last_purchased and
	 * writes the two cache__ tables, and a stock row with no matching stock_log row (a bare
	 * INSERT) never fires that trigger at all - which would make this test unable to prove
	 * those grants are actually necessary.
	 */
	private static function seedMergeableProduct(string $name): int
	{
		$product = self::insertProduct($name);

		foreach ([2, 3] as $amount)
		{
			$response = self::$stock->AddProduct(
				self::request('POST', ['amount' => $amount, 'best_before_date' => '2222-02-02', 'purchased_date' => '2026-01-01', 'price' => 1.0, 'location_id' => self::$locationA]),
				new Response(),
				['productId' => $product]
			);
			self::assertSame(200, $response->getStatusCode(), 'purchase fixture');
			$stockId = json_decode((string)$response->getBody(), true)[0]['stock_id'];
			self::$db->prepare('UPDATE stock SET best_before_date = NULL WHERE stock_id = ?')->execute([$stockId]);
		}

		return $product;
	}

	private static function changedTime(): string
	{
		return (string)self::$db->query('SELECT changed_time FROM system_db_changed_time WHERE id = 1')->fetchColumn();
	}

	/**
	 * Grants every entry in self::GRANTS except the ones named in $omit, runs a real merge
	 * under that role, and returns the outcome. Also runs the SAME shutdown step the real
	 * command's process-exit handler runs afterwards - FlushDbChangedTime()
	 * (services/DatabaseService.php's RegisterShutdownHandler(), PostgresDialect.php:425) -
	 * under the same role and the same still-open connection, since that is the one DELETE-me
	 * blocker round 3c reports: DatabaseService swallows a failure there
	 * (RegisterShutdownHandler()'s own try/catch) so the real command exits 0 even when this
	 * step fails, which is exactly why testing CompactStockEntries()'s own return/throw alone
	 * cannot see it.
	 *
	 * @return array{0: bool, 1: ?string, 2: int, 3: bool, 4: ?string} merge succeeded, merge
	 *         error, product id, flush succeeded AND changed_time actually advanced, flush error
	 */
	private static function runUnderRole(array $omit = []): array
	{
		$schema = self::Schema();
		$role = 'v580r3_priv_' . bin2hex(random_bytes(4));
		$product = self::seedMergeableProduct('Privileges Run ' . $role);

		self::$db->exec("CREATE ROLE $role NOLOGIN");
		try
		{
			self::$db->exec("GRANT USAGE ON SCHEMA $schema TO $role");

			foreach (self::GRANTS as [$template, $label])
			{
				if (in_array($label, $omit, true))
				{
					continue;
				}
				$sql = str_replace(['%SCHEMA%', '%ROLE%'], [$schema, $role], $template);
				self::$db->exec($sql);
			}

			$changedTimeBefore = self::changedTime();

			self::$db->exec("SET ROLE $role");
			$error = null;
			try
			{
				StockService::GetInstance()->CompactStockEntries($product);
			}
			catch (\Throwable $ex)
			{
				$error = $ex->getMessage();
			}
			if (self::$db->inTransaction())
			{
				self::$db->rollBack();
			}

			// The real command's shutdown handler, reproduced directly rather than through
			// register_shutdown_function() (nothing in a test can trigger that on demand) -
			// same call, same still-active role, same connection.
			$flushError = null;
			try
			{
				$dbService = DatabaseService::GetInstance();
				$dbService->GetDialect()->FlushDbChangedTime($dbService->GetDbConnectionRaw());
			}
			catch (\Throwable $ex)
			{
				$flushError = $ex->getMessage();
			}

			self::$db->exec('RESET ROLE');

			$changedTimeAfter = self::changedTime();
			$flushSucceeded = $flushError === null && $changedTimeAfter !== $changedTimeBefore;

			return [$error === null, $error, $product, $flushSucceeded, $flushError];
		}
		finally
		{
			if (self::$db->inTransaction())
			{
				self::$db->rollBack();
			}
			self::$db->exec('RESET ROLE');
			self::$db->exec("DROP OWNED BY $role");
			self::$db->exec("DROP ROLE $role");
		}
	}

	public function testTheCorrectedDocumentedGrantListSufficesForARealMerge(): void
	{
		[$success, $error, $product, $flushSucceeded, $flushError] = self::runUnderRole([]);

		self::assertTrue($success, 'The corrected, complete grant list must let a real merge run to completion: ' . ($error ?? ''));

		$rows = self::$db->prepare('SELECT amount FROM stock WHERE product_id = ?');
		$rows->execute([$product]);
		$amounts = $rows->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame([5.0], array_map('floatval', $amounts), 'Sanity: the merge actually happened (one row, amount 5)');

		self::assertTrue($flushSucceeded, 'The real command\'s shutdown handler flushes the deferred changed-time write under this same role - DatabaseService swallows a failure there (exit 0, no visible error), so this asserts the value actually advanced, not merely that nothing threw: ' . ($flushError ?? '(no exception, but changed_time did not advance)'));
	}

	#[DataProvider('grantLabels')]
	public function testRemovingAnySingleGrantFailsTheRun(string $label): void
	{
		[$success, $error, , $flushSucceeded, $flushError] = self::runUnderRole([$label]);

		self::assertTrue($success === false || $flushSucceeded === false, "Omitting the '$label' grant must fail the run OR the shutdown changed-time flush - every grant on the documented list is load-bearing, not merely harmless (merge error: " . ($error ?? 'none') . '; flush error: ' . ($flushError ?? 'none') . ')');
	}

	public static function grantLabels(): array
	{
		return array_map(fn($grant) => [$grant[1]], self::GRANTS);
	}

	/**
	 * Runs the real bin/victual-compact-stock binary as its own OS subprocess (see
	 * StockMaintenanceCompactionTest::testRealCompactStockBinaryRunsAsASubprocessOnAFirstAndARepeatRun's
	 * own runBinary() for the same env/PGOPTIONS shape), with the given extra CLI arguments.
	 *
	 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
	 */
	private static function runBinary(array $extraArgs): array
	{
		$env = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env['VICTUAL_DB_DRIVER'] = 'pgsql';
		$env['VICTUAL_DB_HOST'] = (string)getenv('PGHOST');
		$env['VICTUAL_DB_PORT'] = (string)getenv('PGPORT');
		$env['VICTUAL_DB_NAME'] = (string)getenv('PHPUNIT_DB_NAME');
		$env['VICTUAL_DB_USER'] = (string)getenv('PGUSER');
		$env['VICTUAL_DB_PASSWORD'] = (string)getenv('PGPASSWORD');
		$env['VICTUAL_DATAPATH'] = (string)getenv('VICTUAL_DATAPATH');
		$env['PGOPTIONS'] = '-c search_path=' . self::Schema() . ',public';

		$process = proc_open(
			array_merge([PHP_BINARY, VICTUAL_ROOT_PATH . '/bin/victual-compact-stock'], $extraArgs),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);
		self::assertIsResource($process, 'bin/victual-compact-stock could not be started');

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return [$exitCode, $stdout, $stderr];
	}

	/**
	 * CodeRabbit review comment 4121171258: `(int)substr(...)` on --product-id silently turned
	 * every malformed value into 0 or a truncated prefix instead of refusing - "--product-id="
	 * and "--product-id=abc" both became 0 (the whole-database sweep, reported as "No eligible
	 * stock rows to merge." and exit 0 - the OPPOSITE of a refusal), and "--product-id=12x"
	 * silently became 12 (compacting the wrong, unintended product).
	 *
	 * Extended for CodeRabbit review comment 4121713761: "--product-id 12", with a space
	 * instead of "=", matched nothing at all - str_starts_with($argument, '--product-id=')
	 * is false for the bare token "--product-id" - so $productId silently stayed null and the
	 * real command swept every product instead of the one named. The strict argument walk
	 * this now exercises also refuses a bare --product-id with no value at all, and any other
	 * unrecognised argument (--bogus below).
	 *
	 * Every case seeds one real eligible pair so a silently-accepted bad command line would
	 * visibly merge it; asserts the exit code, that STDERR names the offending token, AND that
	 * `stock` is byte-for-byte unchanged - not only that the process failed, since a refusal
	 * that still touched the database would be worse than this bug, not a fix for it.
	 */
	#[DataProvider('rejectedArgumentSets')]
	public function testRejectedArgumentsAreRefusedWithoutTouchingTheDatabase(array $args, string $expectedInStderr): void
	{
		$product = self::seedMergeableProduct('Privileges CLI ' . bin2hex(random_bytes(4)));
		$before = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$before->execute([$product]);
		$before = $before->fetchAll(PDO::FETCH_ASSOC);
		self::assertCount(2, $before, 'Sanity: two still-separate candidate rows before the binary runs');

		[$exitCode, , $stderr] = self::runBinary(array_merge($args, ['--quiet']));

		$argsDescription = implode(' ', $args);
		self::assertSame(1, $exitCode, "\"$argsDescription\" must exit 1, not silently succeed: stderr=$stderr");
		self::assertStringContainsString($expectedInStderr, $stderr, "The STDERR message must name the offending argument ($expectedInStderr): got \"$stderr\"");

		$after = self::$db->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY id');
		$after->execute([$product]);
		self::assertSame($before, $after->fetchAll(PDO::FETCH_ASSOC), 'A refusal must leave stock byte-for-byte unchanged - it must never compact product 0, a truncated-prefix product, or (worse) every product instead');
	}

	public static function rejectedArgumentSets(): array
	{
		return [
			'non-numeric value' => [['--product-id=abc'], 'abc'],
			'empty value' => [['--product-id='], 'product-id'],
			'numeric prefix with trailing garbage' => [['--product-id=12x'], '12x'],
			'space instead of = (two separate arguments)' => [['--product-id', '12'], '--product-id'],
			'bare --product-id with no value at all' => [['--product-id'], '--product-id'],
			'unrecognised flag' => [['--bogus'], '--bogus'],
		];
	}
}
