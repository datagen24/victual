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

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();

		// Create a test location (required for stock entries)
		self::$db->exec("INSERT INTO locations (name) VALUES ('Test Location')");
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
			INSERT INTO stock (product_id, location_id, best_before_date, amount, open, purchased_date)
			VALUES (?, 1, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$productId, '2999-12-31', 5.0]);

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: Sentinel-dated entries should not produce events (or be bounded)
		// For now, we choose to exclude sentinel dates entirely
		$this->assertNotContains('2999-12-31', $ical, 'Sentinel dates should not appear in iCal');
		// Also check that the event UID doesn't span far into the future
		$hasUnboundedEvent = preg_match('/DTSTART.*?2999/', $ical);
		$this->assertFalse($hasUnboundedEvent, 'Should not have events spanning to year 2999');
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
			INSERT INTO stock (product_id, location_id, best_before_date, amount, open, purchased_date)
			VALUES (?, 1, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$productId, '2999-12-31', 3.0]);

		// Act: Get iCal
		$ical = $this->getIcalString();

		// Assert: The VTIMEZONE should not extend to year 2999
		preg_match('/DTSTART.*?(\d{4})/s', $ical, $matches);
		if (!empty($matches[1])) {
			$year = (int)$matches[1];
			$this->assertLessThan(2100, $year, 'TimeZone bounds should not extend beyond reasonable future');
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
			VALUES (?, 1, 2, 2)
		');
		$stmt->execute([$name]);
	}

	/**
	 * Insert a stock entry for a product.
	 */
	private function insertStockEntry(int $productId, float $amount, string $bestBeforeDate): void
	{
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, open, purchased_date)
			VALUES (?, 1, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$productId, $bestBeforeDate, $amount]);
	}

	/**
	 * Get the iCal string from GET /api/calendar/ical via subprocess helper.
	 */
	private function getIcalString(): string
	{
		$spec = [
			'method' => 'GET',
			'path' => '/api/calendar/ical'
		];

		$response = $this->dispatchRequest($spec);
		return $response['body'];
	}

	/**
	 * Execute a request via the subprocess helper and return parsed response.
	 */
	private function dispatchRequest(array $spec): array
	{
		$environment = array_merge($_SERVER, [
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
