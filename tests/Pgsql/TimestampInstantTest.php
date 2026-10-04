<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\Database\InstantStatement;
use Victual\Services\Database\TimestampMigration;
use Victual\Services\Database\ValueComparison;
use Victual\Services\ApplicationService;
use Victual\Services\BatteriesService;
use Victual\Services\ChoresService;
use Victual\Services\DatabaseService;
use Victual\Services\Influx\InfluxEventWriter;
use Victual\Services\Mqtt\StateSnapshotAssembler;
use Victual\Services\Time\Instant;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #650, ADR-0027 decision 2: the pieces that turn wall clocks into instants and
 * instants into the wire rendering, tested below the HTTP layer WireContractTest drives.
 *
 * The conversion rule exists twice - Instant::FromWallClock() in PHP (API writes, the SQLite
 * import) and victual_local_to_instant() in SQL (migration 0301, the views) - so the first
 * test asks both the same questions over every DST transition in eight zones and requires the
 * same answers. Neither engine's built-in reading is the rule (see Instant's docblock), which
 * is why the agreement has to be measured rather than assumed.
 */
class TimestampInstantTest extends PgsqlSchemaTestCase
{
	private static PDO $db;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$db = self::Pdo();
	}

	// ------------------------------------------------------------------ the conversion rule

	public function testPhpAndSqlConvertEveryWallClockAroundEveryTransitionAlike(): void
	{
		$zones = ['America/New_York', 'Europe/London', 'Australia/Lord_Howe', 'America/Santiago',
			'Asia/Kathmandu', 'Pacific/Chatham', 'America/St_Johns', 'UTC'];
		$from = (new \DateTimeImmutable('2020-01-01T00:00:00Z'))->getTimestamp();
		$to = (new \DateTimeImmutable('2027-12-31T00:00:00Z'))->getTimestamp();
		$sql = self::$db->prepare("SELECT to_char(victual_local_to_instant(?::timestamp, ?, ?) AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')");

		$checked = 0;
		$disagreements = [];
		foreach ($zones as $zoneName)
		{
			$zone = new \DateTimeZone($zoneName);
			$wallClocks = ['2026-06-15 12:00:00', '2026-01-15 00:00:00.000001'];
			foreach ($zone->getTransitions($from, $to) ?: [] as $transition)
			{
				foreach ([-1, 0] as $side)
				{
					$local = (new \DateTimeImmutable('@' . ($transition['ts'] + $side)))->setTimezone($zone);
					foreach (range(-75, 75, 15) as $minutes)
					{
						$wallClocks[] = $local->modify("$minutes minutes")->format('Y-m-d H:i:s');
					}
					$wallClocks[] = $local->format('Y-m-d H:i:s') . '.999999';
				}
			}

			foreach (array_unique($wallClocks) as $wallClock)
			{
				foreach ([true, false] as $strict)
				{
					$php = Instant::FromWallClock($wallClock, $zone, $strict);
					$sql->execute([$wallClock, $zoneName, $strict ? 'true' : 'false']);
					$fromSql = $sql->fetchColumn();
					$fromPhp = $php === null ? null : Instant::ToWire($php);
					$checked++;

					if ($fromPhp !== ($fromSql === false ? null : $fromSql))
					{
						$disagreements[] = "$zoneName $wallClock " . ($strict ? 'strict' : 'lenient') . ": PHP $fromPhp, SQL $fromSql";
					}
				}
			}
		}

		self::assertGreaterThan(1500, $checked, 'every transition in eight zones over eight years');
		self::assertSame([], array_slice($disagreements, 0, 20), count($disagreements) . ' disagreement(s)');
	}

	public function testTheFixturesIssue650Names(): void
	{
		$ny = new \DateTimeZone('America/New_York');
		self::assertSame('2026-11-01T05:30:00.000000Z', Instant::ToWire(Instant::FromWallClock('2026-11-01 01:30:00', $ny, true)));
		self::assertNull(Instant::FromWallClock('2026-03-08 02:30:00', $ny, true));
		self::assertSame('2026-03-08T07:30:00.000000Z', Instant::ToWire(Instant::FromWallClock('2026-03-08 02:30:00', $ny, false)));

		// The explicit offsets choose either instant of the repeated hour.
		self::assertSame('2026-11-01T05:30:00.000000Z', Instant::ToWire(Instant::Parse('2026-11-01T01:30:00-04:00')));
		self::assertSame('2026-11-01T06:30:00.000000Z', Instant::ToWire(Instant::Parse('2026-11-01T01:30:00-05:00')));
	}

	public function testTheWireRenderingSortsAsItsInstants(): void
	{
		$values = ['2026-10-04 18:30:07+00', '2026-10-04 18:30:07.5+00', '2026-10-04 14:30:06.999999-04', '2026-10-05 00:00:00+05:30'];
		$wire = array_map(fn($v) => Instant::FromDatabase($v), $values);
		$byText = $wire;
		sort($byText, SORT_STRING);
		$byInstant = $wire;
		usort($byInstant, fn($a, $b) => Instant::Parse($a) <=> Instant::Parse($b));

		self::assertSame($byInstant, $byText, 'fixed-width UTC text order is chronological order');
		self::assertSame('2026-10-04T18:30:07.500000Z', $wire[1], 'a fraction is padded to six digits, not dropped');
	}

	public function testInfluxNanosecondsKeepTheMicroseconds(): void
	{
		self::assertSame(1791138600123456000, InfluxEventWriter::ToNanoseconds('2026-10-04T18:30:00.123456Z'));

		// A payload queued before migration 0301 carries a wall clock in the configured zone,
		// and lands on the point it always did.
		self::assertSame(
			(new \DateTimeImmutable('2026-10-04 18:30:00', Instant::ServerZone()))->getTimestamp() * 1000000000,
			InfluxEventWriter::ToNanoseconds('2026-10-04 18:30:00')
		);
	}

	// ----------------------------------------------------------- the statement class

	public function testEveryFetchModeConvertsInstantsAndOnlyInstants(): void
	{
		self::$db->exec("CREATE TEMP TABLE tz650 (id int, at timestamptz, note text, day date, nothing timestamptz)");
		self::$db->exec("INSERT INTO tz650 VALUES (1, '2026-10-04 18:30:00.5+00', '2026-10-04 14:30:00-04', '2026-10-04', NULL), (2, '2026-10-05 01:00:00+00', 'plain', '2026-10-05', NULL)");
		$select = 'SELECT id, at, note, day, nothing FROM tz650 ORDER BY id';
		$at = '2026-10-04T18:30:00.500000Z';

		self::assertInstanceOf(InstantStatement::class, self::$db->query($select));

		$assoc = self::$db->query($select)->fetchAll(PDO::FETCH_ASSOC)[0];
		self::assertSame($at, $assoc['at']);
		self::assertSame('2026-10-04 14:30:00-04', $assoc['note'], 'text shaped like a timestamp is text');
		self::assertSame('2026-10-04', $assoc['day'], 'a date is a date');
		self::assertNull($assoc['nothing']);

		self::assertSame($at, self::$db->query($select)->fetch(PDO::FETCH_NUM)[1]);
		$both = self::$db->query($select)->fetch(PDO::FETCH_BOTH);
		self::assertSame([$at, $at], [$both['at'], $both[1]]);
		self::assertSame($at, self::$db->query($select)->fetch(PDO::FETCH_OBJ)->at);
		self::assertSame([$at, '2026-10-05T01:00:00.000000Z'], self::$db->query($select)->fetchAll(PDO::FETCH_COLUMN, 1));
		self::assertSame($at, self::$db->query('SELECT at FROM tz650 WHERE id = 1')->fetchColumn());
		self::assertSame([1 => $at, 2 => '2026-10-05T01:00:00.000000Z'], self::$db->query('SELECT id, at FROM tz650 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));

		$grouped = self::$db->query('SELECT id, at, note FROM tz650')->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_OBJ);
		self::assertSame($at, $grouped[1][0]->at);

		$statement = self::$db->query($select);
		$statement->setFetchMode(PDO::FETCH_ASSOC);
		self::assertSame($at, $statement->fetchAll()[0]['at'], 'the default mode a statement was given');

		// Two columns with one name: FETCH_ASSOC keeps the last, and that column decides.
		$duplicate = self::$db->query("SELECT at AS x, note AS x FROM tz650 WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
		self::assertSame('2026-10-04 14:30:00-04', $duplicate['x']);
		$duplicate = self::$db->query("SELECT note AS x, at AS x FROM tz650 WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
		self::assertSame($at, $duplicate['x']);

		// A cast to text is deliberately text.
		self::assertSame('2026-10-04 18:30:00.5+00', self::$db->query("SELECT at::text FROM tz650 WHERE id = 1")->fetchColumn());
	}

	// ------------------------------------------------------------------ migration 0301

	/**
	 * A scratch schema with legacy TIMESTAMP columns, a dependent view with a grant and a
	 * comment, and rows in New York's repeated and skipped hours.
	 */
	private static function LegacySchema(string $name, array $extraRows = []): PDO
	{
		$pdo = self::Pdo();
		$pdo->exec("DROP SCHEMA IF EXISTS $name CASCADE; CREATE SCHEMA $name");
		$pdo->exec("SET search_path TO $name, public");
		$pdo->exec(<<<'SQL'
			CREATE TABLE things (
				id int PRIMARY KEY,
				happened TIMESTAMP,
				row_created_timestamp TIMESTAMP DEFAULT date_trunc('second', LOCALTIMESTAMP),
				due DATE
			);
			CREATE INDEX things_happened ON things (happened);
			CREATE VIEW things_view AS SELECT id, happened, due FROM things;
			CREATE VIEW things_latest AS SELECT max(happened) AS latest FROM things_view;
			COMMENT ON VIEW things_view IS 'a view with a comment';
			GRANT SELECT ON things_view TO PUBLIC;
			INSERT INTO things (id, happened, due) VALUES
				(1, '2026-10-04 14:30:00', '2026-10-04'),
				(2, '2026-11-01 01:30:00', '2026-11-01'),
				(3, '2026-11-01 01:30:00.123456', NULL),
				(4, NULL, NULL);
			SQL);

		foreach ($extraRows as $row)
		{
			$pdo->exec($row);
		}

		return $pdo;
	}

	public function testThePreflightCountsAndRefusesWhatItCannotConvert(): void
	{
		$pdo = self::LegacySchema('tz650_refuse', [
			"INSERT INTO things (id, happened) VALUES (5, '2026-03-08 02:30:00'), (6, 'infinity')"
		]);
		$migration = new TimestampMigration($pdo);

		$report = $migration->PreflightStandalone('America/New_York');
		$happened = array_values(array_filter($report['columns'], fn($c) => $c['column'] === 'happened'))[0];
		self::assertSame(5, $happened['values']);
		self::assertSame(2, $happened['repeated_hour'], 'both 01:30 rows are in the repeated hour');
		self::assertSame(1, $happened['skipped_hour']);
		self::assertSame(1, $happened['infinite']);
		self::assertCount(2, $report['refusals']);
		foreach ($report['refusals'] as $refusal)
		{
			self::assertStringNotContainsString('2026-03-08', $refusal, 'a refusal counts rows and names no value');
		}

		$pdo->beginTransaction();
		try
		{
			$migration->Apply('America/New_York');
			self::fail('the migration converted values it cannot convert');
		}
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('things.happened: 1 value(s) name a wall clock the zone skipped', $ex->getMessage());
			self::assertStringContainsString('Nothing was changed', $ex->getMessage());
		}
		finally
		{
			$pdo->rollBack();
		}

		$type = $pdo->query("SELECT data_type FROM information_schema.columns WHERE table_schema = 'tz650_refuse' AND table_name = 'things' AND column_name = 'happened'")->fetchColumn();
		self::assertSame('timestamp without time zone', $type, 'a refusal leaves the schema as it was');
		$pdo->exec('DROP SCHEMA tz650_refuse CASCADE; SET search_path TO ' . self::Schema() . ', public');
	}

	public function testTheMigrationConvertsOnceAndKeepsWhatDependsOnTheColumns(): void
	{
		$pdo = self::LegacySchema('tz650_apply');
		$migration = new TimestampMigration($pdo);

		$pdo->beginTransaction();
		$report = $migration->Apply('America/New_York');
		$pdo->commit();

		self::assertSame(2, $report['ambiguous_total']);
		$rows = $pdo->query('SELECT id, happened, due FROM things ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		self::assertSame('2026-10-04T18:30:00.000000Z', $rows[0]['happened']);
		self::assertSame('2026-11-01T05:30:00.000000Z', $rows[1]['happened'], 'the earlier instant');
		self::assertSame('2026-11-01T05:30:00.123456Z', $rows[2]['happened'], 'microseconds kept');
		self::assertNull($rows[3]['happened']);
		self::assertSame('2026-10-04', $rows[0]['due'], 'a DATE is not converted');

		self::assertSame('a view with a comment', $pdo->query("SELECT obj_description('things_view'::regclass, 'pg_class')")->fetchColumn());
		self::assertTrue((bool)$pdo->query("SELECT has_table_privilege('public', 'things_view', 'SELECT')")->fetchColumn(), 'the grant survived');
		self::assertSame('2026-11-01T05:30:00.123456Z', $pdo->query('SELECT latest FROM things_latest')->fetchColumn(), 'a view on a view');
		self::assertSame(1, (int)$pdo->query("SELECT count(*) FROM pg_indexes WHERE schemaname = 'tz650_apply' AND indexname = 'things_happened'")->fetchColumn());
		self::assertSame("date_trunc('second'::text, CURRENT_TIMESTAMP)", $pdo->query("SELECT column_default FROM information_schema.columns WHERE table_schema = 'tz650_apply' AND table_name = 'things' AND column_name = 'row_created_timestamp'")->fetchColumn());

		// Running it again finds nothing to convert and changes nothing: no value is read as
		// a wall clock a second time.
		$pdo->beginTransaction();
		$second = $migration->Apply('America/New_York');
		$pdo->commit();
		self::assertSame([], $second['columns']);
		self::assertSame('2026-11-01T05:30:00.000000Z', $pdo->query('SELECT happened FROM things WHERE id = 2')->fetchColumn());

		$pdo->exec('DROP SCHEMA tz650_apply CASCADE; SET search_path TO ' . self::Schema() . ', public');
	}

	public function testTheTimeZoneDataComparisonFindsNoDisagreementForTheSuitesZones(): void
	{
		$migration = new TimestampMigration(self::$db);
		foreach (['UTC', 'America/New_York', 'Europe/London', 'Australia/Lord_Howe'] as $zone)
		{
			$result = $migration->CompareTimeZoneData($zone, 2000, 2030);
			self::assertSame([], $result['disagreements'], $zone);
		}
	}

	// ------------------------------------------------------------ the SQLite import

	public function testTheImportReadsWallClocksByTheSameRule(): void
	{
		$zone = date_default_timezone_get();
		date_default_timezone_set('America/New_York');
		try
		{
			self::assertSame('2026-11-01T05:30:00.000000Z', DatabaseImporter::SourceInstant('2026-11-01 01:30:00'));
			self::assertSame('2026-10-04T04:00:00.000000Z', DatabaseImporter::SourceInstant('2026-10-04'));
			self::assertNull(DatabaseImporter::SourceInstant('2026-03-08 02:30:00'));
			// A value that already carries an offset is an instant and is not read again.
			self::assertSame('2026-10-04T18:30:00.000000Z', DatabaseImporter::SourceInstant('2026-10-04T18:30:00Z'));
			self::assertSame('2026-10-04T18:30:00.000000Z', DatabaseImporter::SourceInstant('2026-10-04T14:30:00-04:00'));
		}
		finally
		{
			date_default_timezone_set($zone);
		}
	}

	// ------------------------------------------------ the views phase's comparison

	/**
	 * The negative controls ADR-0027 open question 3 asks for: the instant comparison still
	 * fails on a wrong offset, a lost fraction, a DATE turned into an instant, and a value
	 * with a malformed or missing zone.
	 */
	public function testTheInstantComparisonStillSeesEveryDifferenceThatMatters(): void
	{
		$utc = new \DateTimeZone('UTC');
		$same = fn($a, $b) => ValueComparison::NormaliseInstant($a, $utc) === ValueComparison::NormaliseInstant($b, $utc);

		self::assertTrue($same('2026-10-04 18:30:00', '2026-10-04 18:30:00+00'), 'control: the same instant in two renderings');
		self::assertTrue($same('2026-10-04 18:30:00', '2026-10-04 14:30:00-04'), 'control: the same instant at another offset');
		self::assertFalse($same('2026-10-04 18:30:00', '2026-10-04 18:30:00-04'), 'a wrong offset is a different instant');
		self::assertFalse($same('2026-10-04 18:30:00.123456', '2026-10-04 18:30:00+00'), 'a lost fraction is a difference');
		self::assertFalse($same('2026-10-04', '2026-10-04 00:00:00+00'), 'a DATE turned into an instant is a difference');
		self::assertFalse($same('2026-10-04 18:30:00', '2026-10-04 18:30:00 UTC'), 'a malformed zone is a difference');
		self::assertFalse($same('2026-10-04T18:30:00', '2026-10-04 18:30:00+00'), 'a T-form wall clock without a zone does not pass as an instant');

		$ny = new \DateTimeZone('America/New_York');
		self::assertSame('2026-11-01T05:30:00.000000Z', ValueComparison::NormaliseInstant('2026-11-01 01:30:00', $ny), 'the named source zone, earlier instant');
	}

	// ------------------------------------------------------------ edges and refusals

	public function testTheRemainingFetchShapesAndTheTypeCache(): void
	{
		self::$db->exec("CREATE TEMP TABLE IF NOT EXISTS tz650b (at timestamptz)");
		self::$db->exec("TRUNCATE tz650b; INSERT INTO tz650b VALUES ('2026-10-04 18:30:00+00')");

		self::assertSame('2026-10-04T18:30:00.000000Z', self::$db->query('SELECT at FROM tz650b')->fetch(PDO::FETCH_COLUMN));

		// FETCH_BOUND writes into variables the statement class cannot see, so it is left
		// as PDO returns it.
		$statement = self::$db->query('SELECT at FROM tz650b');
		$statement->bindColumn(1, $bound);
		self::assertTrue($statement->fetch(PDO::FETCH_BOUND));
		self::assertSame('2026-10-04 18:30:00+00', $bound);

		// The per-process cache of column types is bounded: past its limit it starts again
		// rather than growing for the life of a long-running process.
		$cache = new \ReflectionProperty(InstantStatement::class, 'KnownColumnTypes');
		$cache->setValue(null, array_fill_keys(array_map(fn($i) => "q$i", range(1, 2049)), [0 => false]));
		self::assertSame('2026-10-04T18:30:00.000000Z', self::$db->query('SELECT at AS again FROM tz650b')->fetchColumn());
		self::assertCount(1, $cache->getValue());
	}

	public function testTheChangedTimeRoundTripsAnInstantAndRefusesAnythingElse(): void
	{
		$dialect = DatabaseService::GetInstance()->GetDialect();
		$dialect->SetDbChangedTime(self::$db, '2026-10-04T18:30:00.123456Z');
		self::assertSame('2026-10-04T18:30:00.123456Z', $dialect->GetDbChangedTime(self::$db), 'microseconds are kept both ways');

		self::$db->exec('DELETE FROM system_db_changed_time');
		self::assertMatchesRegularExpression('/' . Instant::WIRE_PATTERN . '/D', $dialect->GetDbChangedTime(self::$db), 'a missing row answers now');
		self::$db->exec('INSERT INTO system_db_changed_time (id) VALUES (1)');

		$this->expectException(\InvalidArgumentException::class);
		$dialect->SetDbChangedTime(self::$db, 'not a time');
	}

	public function testTheReadersRefuseAValueThatIsNotATime(): void
	{
		self::assertNull(Instant::FromWallClock('2026-02-30 00:00:00', new \DateTimeZone('UTC'), true), 'a day the month does not have');
		self::assertNull(Instant::FromWallClock('yesterday', new \DateTimeZone('UTC'), false));
		self::assertNull(Instant::Parse('2026-02-30T00:00:00Z'));

		foreach ([fn() => InfluxEventWriter::ToNanoseconds('yesterday'),
			fn() => (new \ReflectionMethod(StateSnapshotAssembler::class, 'ToIso8601'))->invoke(null, 'yesterday')] as $read)
		{
			try
			{
				$read();
				self::fail('a value that is not a time was read as one');
			}
			catch (\Exception $ex)
			{
				self::assertStringContainsString('Not a timestamp', $ex->getMessage());
			}
		}
	}

	public function testTheServicesRefuseATrackedTimeThatIsNotATime(): void
	{
		self::$db->exec("INSERT INTO batteries (id, name, charge_interval_days, active) VALUES (9650, 'Tz650 battery', 0, 1)");
		self::$db->exec("INSERT INTO chores (id, name, period_type, period_interval, active) VALUES (9650, 'Tz650 chore', 'manually', 1, 1)");
		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'phpunit-caller', 'fixture') ON CONFLICT (id) DO NOTHING");

		foreach ([fn() => BatteriesService::GetInstance()->TrackChargeCycle(9650, 'yesterday'),
			fn() => ChoresService::GetInstance()->TrackChore(9650, 'yesterday')] as $track)
		{
			try
			{
				$track();
				self::fail('a tracked time that is not a time was booked');
			}
			catch (\Exception $ex)
			{
				self::assertSame('Invalid tracked time', $ex->getMessage());
			}
		}
	}

	public function testTheMigrationRefusesWhatItCannotCarryOver(): void
	{
		// An unknown zone is a refusal, not a guess.
		$report = (new TimestampMigration(self::$db))->PreflightStandalone('Not/AZone');
		self::assertStringContainsString('not known to this PostgreSQL server', $report['refusals'][0]);

		// A default it does not recognise.
		$pdo = self::LegacySchema('tz650_default', ["ALTER TABLE things ADD COLUMN fixed TIMESTAMP DEFAULT '2020-01-01 00:00:00'"]);
		$pdo->beginTransaction();
		try
		{
			(new TimestampMigration($pdo))->Apply('UTC');
			self::fail('an unknown default was carried over');
		}
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('does not know how to carry the default of things.fixed', $ex->getMessage());
		}
		finally
		{
			$pdo->rollBack();
		}

		// A view that derives a wall clock from nothing it converts would still send one.
		$pdo->exec('CREATE VIEW wall_clock_view AS SELECT LOCALTIMESTAMP AS at');
		$pdo->exec('ALTER TABLE things DROP COLUMN fixed');
		$pdo->beginTransaction();
		try
		{
			(new TimestampMigration($pdo))->Apply('UTC');
			self::fail('a wall-clock view column was left behind silently');
		}
		catch (\RuntimeException $ex)
		{
			self::assertStringContainsString('left wall-clock columns behind: wall_clock_view.at', $ex->getMessage());
		}
		finally
		{
			$pdo->rollBack();
			$pdo->exec('DROP SCHEMA tz650_default CASCADE; SET search_path TO ' . self::Schema() . ', public');
		}
	}

	public function testTheMigrationKeepsAViewsOwnerAndColumnComments(): void
	{
		$pdo = self::LegacySchema('tz650_owner', [
			"DO $$ BEGIN CREATE ROLE tz650_owner_role; EXCEPTION WHEN duplicate_object THEN NULL; END $$",
			'GRANT USAGE, CREATE ON SCHEMA tz650_owner TO tz650_owner_role',
			'GRANT SELECT ON things TO tz650_owner_role',
			'CREATE VIEW owned_view AS SELECT id, happened FROM things',
			'ALTER VIEW owned_view OWNER TO tz650_owner_role',
			"COMMENT ON COLUMN things_view.happened IS 'when it happened'"
		]);

		$pdo->beginTransaction();
		(new TimestampMigration($pdo))->Apply('UTC');
		$pdo->commit();

		self::assertSame('tz650_owner_role', $pdo->query("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'owned_view'::regclass")->fetchColumn());
		self::assertSame('when it happened', $pdo->query("SELECT col_description('things_view'::regclass, 2)")->fetchColumn());

		$pdo->exec('DROP SCHEMA tz650_owner CASCADE; SET search_path TO ' . self::Schema() . ', public');
		$pdo->exec('DROP OWNED BY tz650_owner_role; DROP ROLE tz650_owner_role');
	}

	/**
	 * time_local_sqlite3 is the empty string on every serving image (no pdo_sqlite) and keeps
	 * it; a value it does have gets time_local's offset rendering (ADR-0027 open question 1).
	 */
	public function testTheVestigialSqliteTimeKeepsItsEmptyString(): void
	{
		$render = new \ReflectionMethod(ApplicationService::class, 'WithServerOffset');
		self::assertSame('', $render->invoke(null, ''));
		self::assertMatchesRegularExpression('/^2026-10-04T14:30:00\.000000[+-]\d{2}:\d{2}$/', $render->invoke(null, '2026-10-04 14:30:00'));
	}
}
