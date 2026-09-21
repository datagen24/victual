<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Services\DemoDataGeneratorService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * The demo database, asserted through what a person opening a demo instance would see.
 *
 * DemoDataGeneratorService is 360 executable lines and the suite reached none of them. It
 * used to be SQLite-flavoured and the demo ran on SQLite alone, so a PostgreSQL demo
 * instance was an empty one (defect 13, docs/architecture-review.md); ADR-0008's retirement
 * made the generator portable and left it with no test on either engine. Running it here at
 * all is the portability check the fix never got.
 *
 * What is asserted is deliberately not the insert list. Restating the SQL would pass for any
 * generator that ran, including one that produced a household nobody could demonstrate
 * anything with. The class comment says what this code is for - "localized sample data ...
 * for the demo/prerelease instances" - so the assertions are about the instance: the stock
 * overview is not empty, something is expiring, something is below its minimum, one recipe
 * can be cooked and one cannot, a chore is overdue and a battery is due. Those are the
 * screens a demo exists to show, they are read through the same views the UI reads, and
 * none of them is enforced by a foreign key.
 *
 * No network. The twelve demo resources are fetched only when the destination file does not
 * already exist (DemoDataGeneratorService::DownloadFileIfNotAlreadyExists), so this puts
 * sentinel files there first - which doubles as the assertion that nothing reached out
 * anyway, since a fetch would overwrite them.
 */
class DemoDataTest extends PgsqlSchemaTestCase
{
	/**
	 * The twelve destination paths, relative to the storage folder, that the generator
	 * would otherwise download. Listed here rather than read off the service so that a
	 * thirteenth resource added without a sentinel makes this phase reach the network and
	 * fail loudly, instead of quietly fetching one file in CI.
	 */
	private const DEMO_RESOURCES = [
		'productpictures/cookies.jpg',
		'productpictures/cucumber.jpg',
		'productpictures/gummybears.jpg',
		'productpictures/paprika.jpg',
		'productpictures/tomato.jpg',
		'equipmentmanuals/loremipsum.pdf',
		'recipepictures/pizza.jpg',
		'recipepictures/sandwiches.jpg',
		'recipepictures/pancakes.jpg',
		'recipepictures/spaghetti.jpg',
		'recipepictures/chocolate_sauce.jpg',
		'recipepictures/pancakes_chocolate_sauce.jpg',
	];

	private const SENTINEL = 'not fetched';

	private static PDO $db;
	private static string $Storage = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// VICTUAL_MODE is "production" in this process (config-dist.php's default, which no
		// environment variable overrides here), so the generator writes straight under
		// storage/ with no locale segment. The demo-mode variant, which adds one, is
		// covered in its own process below.
		self::$Storage = VICTUAL_DATAPATH . '/storage';

		foreach (self::DEMO_RESOURCES as $resource)
		{
			$path = self::$Storage . '/' . $resource;

			if (!is_dir(dirname($path)))
			{
				mkdir(dirname($path), 0755, true);
			}

			file_put_contents($path, self::SENTINEL);
		}
	}

	private static function rows(string $sql): int
	{
		return (int)self::$db->query($sql)->fetchColumn();
	}

	/**
	 * The "already populated" marker is a row with the magic migration number -1. Asked for
	 * by name rather than by counting the migrations table, which the real migrations also
	 * write to.
	 */
	private static function markerRows(): int
	{
		return self::rows('SELECT COUNT(*) FROM migrations WHERE migration = -1');
	}

	/**
	 * `?nodemodata` is how an operator boots a dev or demo instance and keeps the empty
	 * database. It has to leave the marker behind even so, or the next request generates
	 * the data the operator just declined.
	 */
	public function testTheSkipFlagRecordsTheRunWithoutGeneratingAnything(): void
	{
		self::assertSame(0, self::markerRows(), 'a freshly migrated database has no demo marker');
		$productsBefore = self::rows('SELECT COUNT(*) FROM products');

		DemoDataGeneratorService::GetInstance()->PopulateDemoData(true);

		self::assertSame(1, self::markerRows(), 'skipping still records that the demo step ran');
		self::assertSame($productsBefore, self::rows('SELECT COUNT(*) FROM products'),
			'skipping generated no products');
		self::assertSame(0, self::rows('SELECT COUNT(*) FROM stock'), 'and no stock');
		self::assertSame(0, self::rows('SELECT COUNT(*) FROM chores'), 'and no chores');

		// Removed rather than rolled back: the generator writes through DatabaseService,
		// whose statements are not guaranteed to be inside a transaction this class opened.
		// The next test needs a database that has not yet been marked, and this is the one
		// row standing between it and one.
		self::$db->exec('DELETE FROM migrations WHERE migration = -1');
		self::assertSame(0, self::markerRows(), 'the marker is gone again');
	}

	#[Depends('testTheSkipFlagRecordsTheRunWithoutGeneratingAnything')]
	public function testTheGeneratorFillsAnEmptyDatabaseOnPostgres(): void
	{
		DemoDataGeneratorService::GetInstance()->PopulateDemoData(false);

		self::assertSame(1, self::markerRows(), 'the run is recorded');

		// The household itself. Minimums rather than exact counts: the demo's shopping
		// list and journals are built through the real services and one of them picks a
		// user at random, so an exact row count would be asserting the generator's
		// arithmetic rather than that a household exists.
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM products'), 'there are products');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM stock'), 'some of them are in stock');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM stock_log'), 'and the ledger records how they got there');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM chores'), 'there are chores');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM batteries'), 'there are batteries');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM tasks'), 'there are tasks');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM recipes'), 'there are recipes');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM meal_plan'), 'and a meal plan');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM shopping_list'), 'and a shopping list');

		// More than one household member, because several screens (chore assignment, the
		// user filter on the journal) have nothing to show with one.
		self::assertGreaterThan(1, self::rows('SELECT COUNT(*) FROM users'), 'more than one user');

		// Localized: VICTUAL_LOCALE is "en" in this process, so the English strings are
		// what the localization service returns. Asserting one name rather than all of
		// them - the point is that the values went through __t() at all, not the catalogue.
		self::assertSame(1, self::rows("SELECT COUNT(*) FROM locations WHERE name = 'Pantry'"),
			'location names come through the localization service');
		self::assertSame(1, self::rows('SELECT COUNT(*) FROM locations WHERE is_freezer = 1'),
			'exactly one demo location is a freezer');

		// Pluralization is most of what a quantity unit is for (issue #232 found a client
		// losing name_plural and called that most of the loss), so a demo has to carry it.
		self::assertSame(1, self::rows("SELECT COUNT(*) FROM quantity_units WHERE name = 'Glass' AND name_plural = 'Glasses'"),
			'quantity units carry a distinct plural');
	}

	/**
	 * The screens a demo instance exists to demonstrate. Each of these is a view the UI
	 * reads, and none is enforced by a foreign key - a generator that produced rows but no
	 * interesting state would satisfy every count above and fail here.
	 */
	#[Depends('testTheGeneratorFillsAnEmptyDatabaseOnPostgres')]
	public function testTheDemoInstanceHasSomethingToShowOnEveryScreen(): void
	{
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM uihelper_stock_current_overview'),
			'the stock overview, which is the first page, is not empty');

		self::assertGreaterThan(0, self::rows("SELECT COUNT(*) FROM products_volatile_status WHERE current_due_status <> 'ok'"),
			'something is approaching or past its due date, so the expiry screen shows a row');

		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM stock_missing_products'),
			'something is below its minimum, so the shopping suggestions are not empty');

		// Both halves, because a demo that can cook everything and a demo that can cook
		// nothing each demonstrate half the feature.
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM recipes_resolved WHERE need_fulfilled = 1'),
			'at least one recipe can be cooked from what is in stock');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM recipes_resolved WHERE need_fulfilled = 0'),
			'and at least one cannot, which is the case the screen exists for');

		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM chores_current'),
			'the chores screen has rows');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM batteries_current'),
			'the batteries screen has rows');
		self::assertGreaterThan(0, self::rows('SELECT COUNT(*) FROM tasks_current'),
			'the tasks screen has rows');

		// The ledger and the on-hand totals are two readings of the same history. A demo
		// whose journal disagrees with its stock is one a person notices immediately.
		$disagreeing = self::rows('
			SELECT COUNT(*)
			FROM (
				SELECT product_id, SUM(amount) AS on_hand
				FROM stock
				GROUP BY product_id
			) held
			JOIN stock_current current ON current.product_id = held.product_id
			WHERE current.amount <> held.on_hand
		');
		self::assertSame(0, $disagreeing, 'every product\'s on-hand total agrees with stock_current');
	}

	/**
	 * The generator is invoked on every request in dev, demo and prerelease mode
	 * (SystemController::Root), so "already ran" has to mean "does nothing at all" rather
	 * than "inserts a second copy".
	 */
	#[Depends('testTheDemoInstanceHasSomethingToShowOnEveryScreen')]
	public function testASecondCallChangesNothing(): void
	{
		$before = [
			'products' => self::rows('SELECT COUNT(*) FROM products'),
			'stock' => self::rows('SELECT COUNT(*) FROM stock'),
			'stock_log' => self::rows('SELECT COUNT(*) FROM stock_log'),
			'users' => self::rows('SELECT COUNT(*) FROM users'),
			'chores_log' => self::rows('SELECT COUNT(*) FROM chores_log'),
			'battery_charge_cycles' => self::rows('SELECT COUNT(*) FROM battery_charge_cycles'),
			'recipes' => self::rows('SELECT COUNT(*) FROM recipes'),
			'meal_plan' => self::rows('SELECT COUNT(*) FROM meal_plan'),
		];

		DemoDataGeneratorService::GetInstance()->PopulateDemoData(false);

		foreach ($before as $table => $count)
		{
			self::assertSame($count, self::rows('SELECT COUNT(*) FROM ' . $table),
				$table . ' was not generated a second time');
		}

		self::assertSame(1, self::markerRows(), 'and the marker was not duplicated');
	}

	/**
	 * A test that reaches releases.grocy.info is a test that fails when that host does, and
	 * TLS peer verification is disabled on those fetches
	 * (DemoDataGeneratorService::DownloadFileIfNotAlreadyExists). Neither belongs in CI.
	 * The sentinels above are what keeps the fetch from happening; this is what proves it
	 * did not happen anyway.
	 */
	#[Depends('testTheGeneratorFillsAnEmptyDatabaseOnPostgres')]
	public function testNoDemoResourceWasFetched(): void
	{
		foreach (self::DEMO_RESOURCES as $resource)
		{
			$path = self::$Storage . '/' . $resource;

			self::assertFileExists($path, $resource . ' is still where the fixture put it');
			self::assertSame(self::SENTINEL, file_get_contents($path),
				$resource . ' was overwritten, so the generator reached the network');
		}
	}

	/**
	 * In demo and prerelease mode the resources are nested under a locale segment, so two
	 * demo instances in different languages do not share one picture folder. VICTUAL_MODE
	 * is a constant, so this is the one case that needs its own process - the environment
	 * variable is read by Setting() before config-dist.php's default applies
	 * (helpers/extensions.php:389).
	 */
	public function testDemoModeNestsTheResourcesUnderTheLocale(): void
	{
		$datapath = sys_get_temp_dir() . '/victual-demodata-mode-' . bin2hex(random_bytes(6));
		mkdir($datapath . '/viewcache', 0755, true);
		copy(VICTUAL_DATAPATH . '/config.php', $datapath . '/config.php');

		// The same database, a schema of the helper's own: the generator refuses to run
		// twice and it has already run in this class's schema.
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($inherited, [
			'VICTUAL_MODE' => 'demo',
			'VICTUAL_DATAPATH' => $datapath,
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/demodata-subprocess-helper.php'],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		self::assertSame(0, $status, "the demo-mode helper failed.\nstdout: $output\nstderr: $errors");

		$result = json_decode($output, true);
		self::assertIsArray($result, "the demo-mode helper printed no JSON.\nstdout: $output\nstderr: $errors");

		self::assertSame('en', $result['locale_segment'],
			'the resources are nested under the default locale');
		self::assertTrue($result['nested_folders_exist'],
			'and the three resource folders were created inside it');
		self::assertFalse($result['unnested_pictures_exist'],
			'nothing was written straight under storage/, which is the production-mode layout');
	}
}
