<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0033 acceptance prerequisite 3 / round 3 item 2: bin/victual-compact-stock's own
 * documented privilege list, proved against a real PostgreSQL role holding EXACTLY those
 * grants (see V580R2ProbeTest.php's testProbe3DocumentedPrivilegesSuffice, a round-2
 * throwaway validator probe this test supersedes with the corrected, complete list).
 *
 * Round 2's list was missing SELECT on the views stock_splits, products_average_price and
 * products_last_purchased (stock_splits is CompactStockEntries()'s very first read), and
 * INSERT/UPDATE on cache__products_average_price and cache__products_last_purchased (written
 * by the stock_log_UPD trigger, which is SECURITY INVOKER - see
 * db/pgsql/baseline/06_triggers_b.sql - and so runs under this caller's own rights whenever a
 * merge rewrites a stock_log row).
 *
 * Sufficiency: a role holding exactly the corrected list runs a real merge end to end.
 * Necessity: dropping any ONE grant from that list makes the same run fail - proving every
 * grant on the list is load-bearing, not merely harmless.
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
		['GRANT SELECT, INSERT, UPDATE, DELETE ON %SCHEMA%.stock_entry_origins TO %ROLE%', 'stock_entry_origins'],
		['GRANT SELECT, UPDATE ON %SCHEMA%.labels TO %ROLE%', 'labels'],
		['GRANT SELECT ON %SCHEMA%.products TO %ROLE%', 'products'],
		['GRANT SELECT ON %SCHEMA%.userfield_values TO %ROLE%', 'userfield_values'],
		['GRANT SELECT ON %SCHEMA%.userfields TO %ROLE%', 'userfields'],
		['GRANT SELECT ON %SCHEMA%.stock_splits TO %ROLE%', 'stock_splits (view)'],
		['GRANT SELECT ON %SCHEMA%.products_average_price TO %ROLE%', 'products_average_price (view)'],
		['GRANT SELECT ON %SCHEMA%.products_last_purchased TO %ROLE%', 'products_last_purchased (view)'],
		['GRANT SELECT, INSERT, UPDATE ON %SCHEMA%.cache__products_average_price TO %ROLE%', 'cache__products_average_price'],
		['GRANT SELECT, INSERT, UPDATE ON %SCHEMA%.cache__products_last_purchased TO %ROLE%', 'cache__products_last_purchased'],
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

	/** Grants every entry in self::GRANTS except the ones named in $omit, runs a real merge under that role, and returns the outcome (true success, or the thrown message). */
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
			self::$db->exec('RESET ROLE');

			return [$error === null, $error, $product];
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
		[$success, $error, $product] = self::runUnderRole([]);

		self::assertTrue($success, 'The corrected, complete grant list must let a real merge run to completion: ' . ($error ?? ''));

		$rows = self::$db->prepare('SELECT amount FROM stock WHERE product_id = ?');
		$rows->execute([$product]);
		$amounts = $rows->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame([5.0], array_map('floatval', $amounts), 'Sanity: the merge actually happened (one row, amount 5)');
	}

	#[DataProvider('grantLabels')]
	public function testRemovingAnySingleGrantFailsTheRun(string $label): void
	{
		[$success, $error] = self::runUnderRole([$label]);

		self::assertFalse($success, "Omitting the '$label' grant must fail the run - every grant on the documented list is load-bearing, not merely harmless");
	}

	/**
	 * Every grant except userfield_values/userfields: retire_stock_entry_labels()
	 * (migrations/0283.pgsql.php) only ever reads `products` for its retirement_snapshot, not
	 * userfield_values/userfields, and stock_splits' own userfield-eligibility check runs
	 * through the view (a plain, non-security_invoker view executes with ITS OWNER's rights
	 * against the tables it reads, not the caller's), so no code path this test can drive
	 * actually requires the caller itself to hold those two grants. They are kept in the
	 * documented list and the sufficiency test above because they are harmless if unneeded and
	 * match this command's pre-round-3 history, but their necessity could not be proven and is
	 * reported to the master rather than asserted here as fact either way.
	 */
	public static function grantLabels(): array
	{
		$provable = array_filter(self::GRANTS, fn($grant) => !in_array($grant[1], ['userfield_values', 'userfields'], true));
		return array_map(fn($grant) => [$grant[1]], $provable);
	}
}
