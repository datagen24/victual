<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression tests for calendar event identity and date bounds (issue #511).
 *
 * Ensures:
 * - Identical /api/calendar/ical reads produce identical event UIDs
 * - Distinct events have distinct UIDs, and the event_type prefix keeps UIDs distinct
 *   even when different entity types share the same numeric id
 * - The UID stays stable when only the entity's own date changes (a rescheduled item
 *   is an update, not a new event)
 * - Never-expiring products (date-only sentinel) and batteries with no charge interval
 *   configured (datetime sentinel) yield no unbounded events
 * - Normal dated events remain present
 * - VTIMEZONE bounds do not extend to far-future sentinel dates, checked under a zone
 *   with real DST transitions (UTC cannot reveal this half of the defect - see
 *   testTimeZoneBoundsDoNotIncludeSentinelDates)
 * - The UID's domain part (CALENDAR_UID_DOMAIN, config-dist.php) defaults to "victual",
 *   is configurable per installation for RFC 5545 global uniqueness, and sanitizes an
 *   out-of-range value rather than accepting or refusing it, falling back to the
 *   default when nothing usable is left - whether the configured value was empty or
 *   made entirely of characters the sanitizer replaces
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
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9600, id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'TASKS_VIEW', 'CHORES_VIEW', 'BATTERIES', 'MEALPLAN_VIEW')");

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

		// Assert: Sentinel-dated entries should not produce events (or be bounded).
		// iCal renders a DATE VALUE in compact form (YYYYMMDD, no dashes) - the input
		// dashed form '2999-12-31' never appears in the output regardless of whether
		// filtering works, so that literal would make this assertion vacuous.
		$this->assertStringNotContainsString('29991231', $ical, 'Sentinel dates should not appear in iCal');
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
		// Arrange: a normal event (so there is a legitimate min/max date to build a
		// VTIMEZONE from) and a SEPARATE, dedicated sentinel-only product - not the same
		// product as the normal event, because StockService::GetCurrentStock() aggregates
		// to one row per product_id via MIN(best_before_date), which would silently hide a
		// second, later, sentinel-dated row on the same product behind the earlier one and
		// never let it reach CalendarApiController's per-event filter at all.
		$this->insertProduct('Product Normal', 10.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 2.0, '2027-03-15');

		$this->insertProduct('TZ Sentinel Product', 4.0);
		$sentinelProductId = self::$db->lastInsertId();
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date)
			VALUES (?, ?, ?, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$sentinelProductId, self::$locationId, '2999-12-31', 3.0, 'cal-tz-sentinel-' . uniqid()]);

		// Act: read in a zone with real DST transitions. The suite otherwise runs in UTC,
		// where DateTimeZone::getTransitions() returns exactly one fixed transition no
		// matter what range is queried, so a VTIMEZONE block built under UTC looks
		// identical whether $maxDate correctly excludes the sentinel or silently includes
		// it - that half of #511 (M11) is invisible to a UTC-only assertion. Europe/Berlin's
		// DST transitions make the block's content and size sensitive to $maxDate, which is
		// what the #487 audit's own much larger, non-UTC ("205 KB") reproduction caught.
		$ical = $this->getIcalString('Europe/Berlin');

		$this->assertStringContainsString('BEGIN:VTIMEZONE', $ical, 'Expected a VTIMEZONE block from the non-sentinel event');
		$this->assertStringNotContainsString('TZ Sentinel Product', $ical, 'Sentinel-dated product should not produce a calendar event');

		// Assert: every DTSTART inside the VTIMEZONE block specifically - a VEVENT's own
		// DTSTART is already covered by the sentinel-exclusion tests - is before year 2100.
		// A $maxDate that leaked to 2999 would make TimeZone::createFromPhpDateTimeZone()
		// enumerate DST transitions up to that year.
		preg_match('/BEGIN:VTIMEZONE\r?\n(.*?)END:VTIMEZONE/s', $ical, $tzBlock);
		$this->assertNotEmpty($tzBlock, 'Expected to find a VTIMEZONE block to inspect');
		preg_match_all('/DTSTART[^:\r\n]*:(\d{4})/', $tzBlock[1], $matches);
		$this->assertNotEmpty($matches[1], 'Expected at least one DTSTART inside VTIMEZONE');
		foreach ($matches[1] as $year)
		{
			$this->assertLessThan(2100, (int)$year, 'No VTIMEZONE DTSTART should extend to the sentinel year');
		}

		// Assert: the feed stays small. A $maxDate leak to year 2999 would force
		// Europe/Berlin's VTIMEZONE to enumerate roughly a millennium of DST transitions -
		// the audit's original, unbounded Berlin reproduction was 205 KB; a correctly
		// bounded feed for this two-event fixture is well under 10 KB.
		$this->assertLessThan(10 * 1024, strlen($ical), 'iCal feed should stay small for a bounded date range');
	}

	/**
	 * #511 (maintainer decision, 2026-09-27): the UID is {type}-{id}@victual, with no
	 * date component, precisely so that rescheduling the same entity - a chore's next
	 * due date, here a product's best-before date - changes DTSTART without changing
	 * UID. A calendar client keys on UID: same UID with a new DTSTART reads as "this
	 * event moved"; a new UID reads as "the old event vanished, a new one appeared."
	 */
	public function testUidStaysStableWhenEntityDateChanges()
	{
		// Arrange: a product with an initial due date.
		$this->insertProduct('Reschedulable Product', 6.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 3.0, '2028-01-10');

		// Act: read once, then move that same product's best-before date forward -
		// the same kind of update a re-purchase or a manual edit performs - and read
		// again.
		$ical1 = $this->getIcalString();
		self::$db->exec("UPDATE stock SET best_before_date = '2028-02-20' WHERE product_id = $productId");
		$ical2 = $this->getIcalString();

		$uid1 = $this->findUidForSummary($ical1, 'Reschedulable Product');
		$uid2 = $this->findUidForSummary($ical2, 'Reschedulable Product');
		$this->assertNotNull($uid1, 'Expected an event for the product before the date change');
		$this->assertNotNull($uid2, 'Expected an event for the product after the date change');

		// Assert: the UID is stable across the date change...
		$this->assertSame($uid1, $uid2, "UID must stay stable when only the entity's date changes");

		// ...and DTSTART actually moved, so a stable UID here is a meaningful
		// assertion and not an artifact of nothing having changed.
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20280110', $ical1);
		$this->assertStringContainsString('DTSTART;VALUE=DATE:20280220', $ical2);
		$this->assertStringNotContainsString('DTSTART;VALUE=DATE:20280110', $ical2);
	}

	/**
	 * The type prefix in the UID is load-bearing, not decoration: every event type's
	 * entity_id is only unique WITHIN that type's own table, so a product, a task, a
	 * chore, a battery and a meal-plan note can share the exact same numeric primary
	 * key. Without the prefix, all five would collapse onto one UID.
	 */
	public function testSharedNumericIdProducesDistinctUidsPerType()
	{
		// Arrange: the SAME id for all five, chosen well outside this class's natural
		// auto-increment range so it cannot collide with another test method's rows.
		$sharedId = 91000;

		self::$db->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ($sharedId, 'Shared ID Product', " . self::$locationId . ", 2, 2)");
		$stmt = self::$db->prepare('
			INSERT INTO stock (product_id, location_id, best_before_date, amount, stock_id, open, purchased_date)
			VALUES (?, ?, ?, ?, ?, 0, CURRENT_DATE)
		');
		$stmt->execute([$sharedId, self::$locationId, '2028-03-01', 2.0, 'cal-shared-' . uniqid()]);

		self::$db->exec("INSERT INTO tasks (id, name, due_date, done) VALUES ($sharedId, 'Shared ID Task', '2028-03-01', 0)");

		// period_type 'daily' with no chores_log row yet: chores_current falls straight
		// through to next_estimated_execution_time = start_date (no interval math
		// needed to reach a real, non-sentinel date).
		self::$db->exec("INSERT INTO chores (id, name, active, period_type, period_interval, start_date, track_date_only) VALUES ($sharedId, 'Shared ID Chore', 1, 'daily', 1, '2028-03-01 09:00:00', 0)");

		// charge_interval_days > 0 with one prior charge cycle: batteries_current
		// computes next_estimated_charge_time = that cycle's time + the interval.
		self::$db->exec("INSERT INTO batteries (id, name, active, charge_interval_days) VALUES ($sharedId, 'Shared ID Battery', 1, 30)");
		self::$db->exec("INSERT INTO battery_charge_cycles (battery_id, tracked_time, undone) VALUES ($sharedId, '2028-02-01 00:00:00', 0)");

		// meal_plan_sections needs a real row to resolve against - an unresolved
		// section_id makes CalendarService dereference a property on null.
		self::$db->exec("INSERT INTO meal_plan_sections (id, name) VALUES ($sharedId, 'Shared ID Section')");
		self::$db->exec("INSERT INTO meal_plan (id, day, type, note, section_id) VALUES ($sharedId, '2028-03-01', 'note', 'Shared ID Note', $sharedId)");

		// Act
		$ical = $this->getIcalString();

		// Assert: five distinct, type-prefixed UIDs - one per entity. If the type
		// prefix were ever dropped, every one of these entities would instead produce
		// the same "91000@victual" UID, and none of these five exact strings would be
		// found.
		foreach (['stock', 'task', 'chore', 'battery', 'meal_plan_note'] as $type)
		{
			$this->assertStringContainsString("UID:{$type}-{$sharedId}@victual", $ical, "Expected a VEVENT with UID {$type}-{$sharedId}@victual");
		}
	}

	/**
	 * batteries_current substitutes the full datetime '2999-12-31 23:59:59' for
	 * next_estimated_charge_time when charge_interval_days = 0
	 * (db/pgsql/baseline/03_views_group1.sql) - not merely a date. This exercises the
	 * sentinel filter's datetime-formatted path, which the product-only sentinel tests
	 * above never touch (products are always date_format 'date'). Reproduced against
	 * unfixed master: the event leaked as DTSTART:29991231T235959Z.
	 */
	public function testDatetimeSentinelBatteryYieldsNoBoundedEvent()
	{
		// Arrange: a battery with no charge interval configured - "never due" for
		// batteries, the same concept as a product's null best-before date.
		self::$db->exec("INSERT INTO batteries (name, active, charge_interval_days) VALUES ('Never Charged Battery', 1, 0)");

		// Act
		$ical = $this->getIcalString();

		// Assert: no VEVENT for it.
		$this->assertStringNotContainsString('Never Charged Battery', $ical, 'A battery with no charge interval should not produce a calendar event');
		$this->assertStringNotContainsString('29991231', $ical, 'Sentinel dates should not appear in iCal');
	}

	/**
	 * #511, CodeRabbit review comment 4117467865 on an earlier revision: a fixed
	 * "@victual" domain makes two Victual installations subscribed to in the same
	 * calendar client collide on identical UIDs for different items, since RFC 5545
	 * requires a UID to be globally unique. CALENDAR_UID_DOMAIN (maintainer decision,
	 * 2026-09-27) makes the domain part configurable per installation.
	 */
	public function testUidDomainIsConfigurable()
	{
		// Arrange
		$this->insertProduct('Domain Config Product', 9.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 1.0, '2028-04-01');

		// Act: override for this one request only, the same way testTimeZoneBounds...
		// overrides VICTUAL_TEST_TIMEZONE.
		$ical = $this->getIcalString(null, ['VICTUAL_CALENDAR_UID_DOMAIN' => 'home.example']);

		// Assert: every UID in the feed uses the configured domain, not the default -
		// this covers events from every earlier test method still in the shared schema,
		// not only this one's own fixture.
		$uids = $this->extractUids($ical);
		$this->assertNotEmpty($uids, 'Expected at least one event in the feed');
		foreach ($uids as $uid)
		{
			$this->assertStringEndsWith('@home.example', $uid, "UID $uid should use the configured domain");
		}
	}

	/**
	 * A value outside the allowed character set (letters, digits, '.', '-') is
	 * sanitized by replacement, the same way GetNodeId() sanitizes
	 * VICTUAL_MQTT_TOPIC_PREFIX (services/Mqtt/DiscoveryPayloadBuilder.php) - never
	 * accepted verbatim (a literal '@' would corrupt the UID's own "type-id@domain"
	 * shape) and never refused outright.
	 */
	public function testInvalidUidDomainIsSanitized()
	{
		// Arrange
		$this->insertProduct('Invalid Domain Product', 9.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 1.0, '2028-04-02');

		// Act: '@' and a space are both outside the allowed set.
		$ical = $this->getIcalString(null, ['VICTUAL_CALENDAR_UID_DOMAIN' => 'bad@domain example']);

		// Assert: the offending characters were replaced, not passed through - no UID's
		// domain part contains anything outside [A-Za-z0-9.-].
		$uid = $this->findUidForSummary($ical, 'Invalid Domain Product');
		$this->assertNotNull($uid, 'Expected an event for the product');
		$this->assertMatchesRegularExpression('/@[A-Za-z0-9.-]+$/', $uid, "UID $uid's domain part should only contain sanitized characters");
		$this->assertStringNotContainsString('@bad@domain', $ical, 'A literal @ in the configured domain must not reach the UID unsanitized');
	}

	/**
	 * An empty CALENDAR_UID_DOMAIN sanitizes to '' and falls back to the compiled-in
	 * default 'victual' - the same default every UID used before this setting existed,
	 * so an installation that never touches it keeps byte-identical UIDs.
	 */
	public function testEmptyUidDomainFallsBackToDefault()
	{
		// Arrange
		$this->insertProduct('Empty Domain Product', 9.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 1.0, '2028-04-03');

		// Act: '' rather than omitting the key - Setting()'s getenv(...) !== false check
		// treats an explicitly empty environment variable as set, not absent.
		$ical = $this->getIcalString(null, ['VICTUAL_CALENDAR_UID_DOMAIN' => '']);

		// Assert
		$uid = $this->findUidForSummary($ical, 'Empty Domain Product');
		$this->assertNotNull($uid, 'Expected an event for the product');
		$this->assertStringEndsWith('@victual', $uid);
	}

	/**
	 * A value made entirely of disallowed characters - here, three '@' signs - sanitizes
	 * to a non-empty run of '-' ('---'), not to ''. That is exactly as meaningless as an
	 * empty value (no letters or digits at all), so it must fall back to the same
	 * default rather than silently becoming a real, working UID domain of '---'.
	 */
	public function testAllDisallowedCharactersUidDomainFallsBackToDefault()
	{
		// Arrange
		$this->insertProduct('All Disallowed Domain Product', 9.0);
		$productId = self::$db->lastInsertId();
		$this->insertStockEntry($productId, 1.0, '2028-04-04');

		// Act
		$ical = $this->getIcalString(null, ['VICTUAL_CALENDAR_UID_DOMAIN' => '@@@']);

		// Assert
		$uid = $this->findUidForSummary($ical, 'All Disallowed Domain Product');
		$this->assertNotNull($uid, 'Expected an event for the product');
		$this->assertStringEndsWith('@victual', $uid);
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
	 *
	 * $timezone, when given, is the server's default timezone for this one request
	 * only (VICTUAL_TEST_TIMEZONE, honoured by request-subprocess-helper.php and used
	 * the same way by WireContractTest). The suite otherwise runs in UTC, whose
	 * DateTimeZone::getTransitions() returns exactly one fixed transition regardless of
	 * the queried range - so a VTIMEZONE block rendered under UTC cannot reveal whether
	 * $maxDate leaked a sentinel date into it. A zone with real DST transitions
	 * (Europe/Berlin) is sensitive to the queried range and is what the #487 audit's
	 * own reproduction actually used.
	 *
	 * $extraEnv overrides any other Setting()-backed constant for this one request, by
	 * environment variable (VICTUAL_<NAME>, read by Setting() in helpers/extensions.php)
	 * the same way $timezone overrides VICTUAL_TEST_TIMEZONE - e.g.
	 * ['VICTUAL_CALENDAR_UID_DOMAIN' => 'home.example'].
	 */
	private function getIcalString(?string $timezone = null, array $extraEnv = []): string
	{
		$spec = [
			'method' => 'GET',
			'path' => '/api/calendar/ical',
			'cookie' => self::$sessionKey
		];

		$response = $this->dispatchRequest($spec, $timezone, $extraEnv);
		return $response['body'];
	}

	/**
	 * Execute a request via the subprocess helper and return parsed response.
	 */
	private function dispatchRequest(array $spec, ?string $timezone = null, array $extraEnv = []): array
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
			// '' rather than absent: the inherited environment above may already carry a
			// value from this test process's own env, so a request that asks for no
			// override has to explicitly overwrite it, matching WireContractTest::sendAs().
			'VICTUAL_TEST_TIMEZONE' => $timezone ?? '',
			// Same reasoning as VICTUAL_TEST_TIMEZONE above: a developer's own shell may
			// export VICTUAL_CALENDAR_UID_DOMAIN for an unrelated reason, and $inherited
			// would carry it straight into every request unless blanked here. Without this,
			// the assertSame(...'@victual')-style assertions in the tests above would break
			// on a machine that happens to have this variable set, for a reason having
			// nothing to do with the test itself. $extraEnv (merged in after) still wins
			// when a test deliberately wants a different domain.
			'VICTUAL_CALENDAR_UID_DOMAIN' => '',
		], $extraEnv);

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

	/**
	 * Find the UID of the VEVENT block whose SUMMARY contains $needle. Unlike
	 * extractUids(), this correlates a UID to a specific entity's event rather than
	 * returning every UID in the document - needed once the schema accumulates more
	 * than one event across test methods.
	 */
	private function findUidForSummary(string $ical, string $needle): ?string
	{
		if (preg_match_all('/BEGIN:VEVENT\r?\n(.*?)END:VEVENT/s', $ical, $blocks))
		{
			foreach ($blocks[1] as $block)
			{
				if (str_contains($block, $needle) && preg_match('/UID:([^\r\n]+)/', $block, $uidMatch))
				{
					return $uidMatch[1];
				}
			}
		}
		return null;
	}
}
