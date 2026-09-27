<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression tests for calendar event identity and date bounds (issue #511).
 *
 * Ensures:
 * - Identical /api/calendar/ical reads produce identical event UIDs
 * - Distinct events have distinct UIDs
 * - Never-expiring products and sentinel-dated tasks yield no unbounded events
 * - Normal dated events remain present
 * - TimeZone bounds do not extend to far-future sentinel dates
 */
class CalendarIdentityTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static int $locationId;
	private static string $sessionKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		// Create a test user
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9600, 'calendar-test', 'fixture')");

		// Grant calendar permissions
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9600, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'TASKS_VIEW', 'CHORES_VIEW', 'BATTERIES')");

		// Create a session for the test user
		self::$sessionKey = 'calendar-test-session-' . uniqid();
		$stmt = self::$db->prepare("INSERT INTO sessions (session_key, user_id, expires) VALUES (?, 9600, now() + interval '1 day')");
		$stmt->execute([self::$sessionKey]);

		// Create a test location (required for stock entries)
		self::$db->exec("INSERT INTO locations (name) VALUES ('Test Location')");
		self::$locationId = (int)self::$db->lastInsertId();
	}

	public function testIdenticalReadsYieldIdenticalUIDs()
	{
		// Arrange: Create simple calendar data (a product with due date)
		$this->insertProduct('Test Product 1', 10.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 5.0, '2026-12-25');

		// Act: Get iCal twice (simulating two identical HTTP requests)
		$ical1 = $this->getIcalString();
		$ical2 = $this->getIcalString();

		// Assert: Both iCals contain the same event UIDs
		$uids1 = $this->extractUids($ical1);
		$uids2 = $this->extractUids($ical2);

		$this->assertNotEmpty($uids1, 'First iCal should contain events');
		$this->assertSame($uids1, $uids2, 'Identical reads should yield identical UIDs');
	}

	public function testDistinctEventsHaveDistinctUIDs()
	{
		// Arrange: Create two different events (two products with different due dates)
		$this->insertProduct('Product A', 5.0);
		$productAId = self::$db->lastInsertId();
		$this->insertProduct('Product B', 8.0);
		$productBId = self::$db->lastInsertId();

		$this->insertStockEntry($productAId, 2.0, '2026-12-25');
		$this->insertStockEntry($productBId, 3.0, '2026-12-26');

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: Different events have different UIDs
		$uids = $this->extractUids($ical);
		$this->assertGreaterThanOrEqual(2, count($uids), 'Should have at least 2 events');
		$this->assertCount(count(array_unique($uids)), $uids, 'All UIDs should be unique');
	}

	public function testSentinelDatedProductYieldsNoBoundedEvent()
	{
		// Arrange: Create a never-expiring product (sentinel date 2999-12-31)
		// This represents a product with "never expires" setting
		$this->insertProduct('Never Expires Product', 15.0);
		$productId = self::$db->lastInsertId();

		// Simulate a best_before_date of 2999-12-31 (sentinel value)
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date)
			VALUES (?, ?, ?, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$productId, self::$locationId, '2999-12-31', 5.0, 'cal-sentinel-' . uniqid()]);

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: Sentinel-dated entries should not produce events (or be bounded)
		// For now, we choose to exclude sentinel dates entirely
		$this->assertStringNotContainsString('2999-12-31', $ical, 'Sentinel dates should not appear in iCal');
		// The sentinel entry must not produce a VEVENT at all, not merely one whose date
		// happens to be reformatted away from the literal string checked above.
		$this->assertStringNotContainsString('Never Expires Product', $ical, 'Sentinel-dated product should not produce a calendar event');
		// Also check that no DTSTART (event or VTIMEZONE) spans far into the future.
		// preg_match() returns int (0 or 1) on success and only bool false on a regex
		// engine error, so assertSame(0, ...) is the type-correct way to assert "no match" -
		// assertFalse() requires a literal bool and fails on the int 0 preg_match() actually
		// returns, masking this assertion regardless of the real match result.
		$hasUnboundedEvent = preg_match('/DTSTART.*?2999/', $ical);
		$this->assertSame(0, $hasUnboundedEvent, 'Should not have events spanning to year 2999');
	}

	public function testNormalDatedEventRemains()
	{
		// Arrange: Create a normal product with a reasonable due date
		$this->insertProduct('Normal Product', 12.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 4.0, '2027-06-15');

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: Normal events should be present
		$this->assertNotEmpty($ical);
		$this->assertStringContainsString('Normal Product', $ical);
		// Should have a reasonable date, not too far in the future
		$this->assertStringContainsString('20270615', $ical);
	}

	public function testTimeZoneBoundsDoNotIncludeSentinelDates()
	{
		// Arrange: Create events with both normal and sentinel dates
		$this->insertProduct('Product Normal', 10.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 2.0, '2027-03-15');

		// Also insert a sentinel-dated entry
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date)
			VALUES (?, ?, ?, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$productId, self::$locationId, '2999-12-31', 3.0, 'cal-tz-sentinel-' . uniqid()]);

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: no DTSTART anywhere in the document - VEVENT or VTIMEZONE - reaches
		// the sentinel year. Checking only the first DTSTART match would miss a bad
		// VEVENT DTSTART hiding behind a benign, earlier VTIMEZONE DTSTART, so every
		// occurrence is checked.
		$this->assertStringContainsString('BEGIN:VTIMEZONE', $ical, 'Expected a VTIMEZONE block from the non-sentinel event');
		preg_match_all('/DTSTART[^:\r\n]*:(\d{4})/', $ical, $matches);
		$this->assertNotEmpty($matches[1], 'Expected at least one DTSTART in the iCal output');
		foreach ($matches[1] as $year)
		{
			$this->assertLessThan(2100, (int)$year, 'No DTSTART (event or VTimeZone) should extend to the sentinel year');
		}
	}

	// ===== Helper methods =====

	/**
	 * Insert a product into the database.
	 */
	private function insertProduct(string $name, float $price): void
	{
		$stmt = self::$db->prepare('
			INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock)
			VALUES (?, ?, 2, 2)
		');
		$stmt->execute([$name, self::$locationId]);
	}

	/**
	 * Insert a stock entry for a product.
	 */
	private function insertStockEntry(int $productId, float $amount, string $bestBeforeDate): void
	{
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date)
			VALUES (?, ?, ?, ?, ?, 0, CURRENT_DATE)
		');
		// Generate a unique stock_id
		$stockId = 'cal-test-' . uniqid();
		$stmt->execute([$productId, self::$locationId, $bestBeforeDate, $amount, $stockId]);
	}

	/**
	 * Get the iCal string from GET /api/calendar/ical via subprocess helper.
	 */
	private function getIcalString(): string
	{
		$spec = [
			'method' => 'GET',
			'path' => '/api/calendar/ical',
			'cookie' => self::$sessionKey
		];

		$response = $this->dispatchRequest($spec);
		return $response['body'];
	}

	/**
	 * Execute a request via the subprocess helper and return parsed response.
	 */
	private function dispatchRequest(array $spec): array
	{
		// $_SERVER carries argv, which is an array and cannot be an environment value.
		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$environment = array_merge($inherited, [
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
			'VICTUAL_DATAPATH' => VICTUAL_DATAPATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$environment
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$result = json_decode((string)$output, true);
		$this->assertIsArray($result, "Request helper did not return JSON. stdout: $output\nstderr: $errors");

		return [
			'status' => $result['status'] ?? 0,
			'body' => $result['body'] ?? ''
		];
	}

	/**
	 * Extract all UID values from an iCal string.
	 * iCal format: UID:...
	 */
	private function extractUids(string $ical): array
	{
		$uids = [];
		if (preg_match_all('/UID:([^\r\n]+)/', $ical, $matches)) {
			$uids = $matches[1];
		}
		return $uids;
	}
}
