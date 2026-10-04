<?php

namespace Victual\Services\Database;

/**
 * Migration 0301: every legacy `TIMESTAMP` column becomes `TIMESTAMPTZ` (ADR-0027 decision 2,
 * issue #650).
 *
 * Each stored value is a wall clock in the configured zone - PHP's default zone, which
 * PostgresDialect also gives every database session - and is converted to the instant it
 * names there, by the rule ADR-0028 fixes for a new offset-free write: a wall clock the zone
 * repeated names the earlier instant, and a wall clock the zone skipped is refused.
 * `victual_local_to_instant()` below is that rule in SQL, and it is the same algorithm as
 * Victual\Services\Time\Instant::FromWallClock(). PostgreSQL's own
 * `value AT TIME ZONE zone` is not used for the conversion, because it reads a repeated
 * hour as the *later* instant and moves a skipped one forward without a word.
 *
 * This is an upgrade over live data, so it is preflighted the way ADR-0029 asks: Preflight()
 * is read-only, counts every value per column that cannot be converted - a skipped wall
 * clock, an infinity, a year outside 1-9999 - and Apply() refuses with that report rather
 * than inventing a time. Nothing a refusal names is guessed at; the operator corrects the
 * rows and runs the migration again.
 *
 * What it cannot know: a row's stored wall clock does not say which zone was configured
 * when it was written. Every row is read in the zone configured when this runs. A server
 * whose zone changed after rows were written converts the older ones at the wrong offset,
 * and nothing in the data can detect that (ADR-0027, Consequences).
 */
class TimestampMigration
{
	/**
	 * The conversion function, created by Apply() and kept: the importer verification and
	 * the pgTAP tests read through it, and the views below use its lenient form for the
	 * times they derive.
	 *
	 * Every offset the zone uses within 26 hours either side of the wall clock is a
	 * candidate. A candidate is valid when the zone really is at that offset at the
	 * resulting instant, and the earliest valid one wins. No valid candidate means the zone
	 * skipped the wall clock: strict returns NULL (the caller refuses), lenient reads it at
	 * the offset in force before the transition, which moves it forward by the gap.
	 */
	const FUNCTION_SQL = <<<'SQL'
		CREATE OR REPLACE FUNCTION victual_local_to_instant(wall timestamp without time zone, zone text, strict boolean)
		RETURNS timestamptz
		LANGUAGE plpgsql STABLE PARALLEL SAFE
		AS $fn$
		DECLARE
			naive timestamptz := wall AT TIME ZONE 'UTC';
			off_before interval;
			off interval;
			candidate timestamptz;
			best timestamptz := NULL;
		BEGIN
			IF wall IS NULL THEN
				RETURN NULL;
			END IF;
			IF NOT isfinite(wall) THEN
				RAISE EXCEPTION 'victual_local_to_instant: % is not a finite wall clock', wall;
			END IF;

			off_before := ((naive - interval '26 hours') AT TIME ZONE zone) - ((naive - interval '26 hours') AT TIME ZONE 'UTC');

			FOREACH off IN ARRAY ARRAY[
				off_before,
				(naive AT TIME ZONE zone) - (naive AT TIME ZONE 'UTC'),
				((naive + interval '26 hours') AT TIME ZONE zone) - ((naive + interval '26 hours') AT TIME ZONE 'UTC')
			]
			LOOP
				candidate := naive - off;
				IF (candidate AT TIME ZONE zone) = wall AND (best IS NULL OR candidate < best) THEN
					best := candidate;
				END IF;
			END LOOP;

			IF best IS NOT NULL OR strict THEN
				RETURN best;
			END IF;

			RETURN naive - off_before;
		END
		$fn$;
		SQL;

	/**
	 * The three views that compute a time rather than pass one through. Every other view is
	 * recreated from its own catalogue definition; these are rewritten so that each time
	 * they build from a wall clock goes through the lenient conversion rather than through
	 * PostgreSQL's implicit timestamp-to-timestamptz cast, and so that `batteries_current`'s
	 * "never" is one instant on every server.
	 *
	 * Interval arithmetic on a TIMESTAMPTZ follows the session zone's calendar (`+ 1 day`
	 * keeps the wall clock across a DST change), which is the configured zone, so the
	 * schedules come out as they did over wall clocks. `chores_execution_timeline` measures
	 * the gap between executions on the wall clock, as it always did, by casting both ends
	 * back to the session zone's wall clock: the elapsed real time across a DST change
	 * differs by an hour, and the adaptive schedule and the differential suite both expect
	 * the old figure.
	 */
	const VIEW_OVERRIDES = [
		'batteries_current' => <<<'SQL'
			CREATE VIEW batteries_current AS
			SELECT b.id,
				b.id AS battery_id,
				max(l.tracked_time) AS last_tracked_time,
				CASE
					WHEN b.charge_interval_days = 0 THEN '2999-12-31 23:59:59+00'::timestamptz
					ELSE max(l.tracked_time) + ((b.charge_interval_days::text || ' day'::text)::interval)
				END AS next_estimated_charge_time
			FROM batteries b
				LEFT JOIN battery_charge_cycles l ON b.id = l.battery_id AND l.undone = 0
			WHERE b.active = 1
			GROUP BY b.id, b.charge_interval_days
			SQL,
		'chores_execution_timeline' => <<<'SQL'
			CREATE VIEW chores_execution_timeline AS
			SELECT chore_id,
				tracked_time,
				(SELECT chores_log.tracked_time
					FROM chores_log
					WHERE chores_log.chore_id = cl.chore_id AND chores_log.undone = 0 AND chores_log.tracked_time < cl.tracked_time
					ORDER BY chores_log.tracked_time DESC
					LIMIT 1) AS tracked_time_before,
				trunc(EXTRACT(epoch FROM tracked_time::timestamp without time zone - ((SELECT chores_log.tracked_time
					FROM chores_log
					WHERE chores_log.chore_id = cl.chore_id AND chores_log.undone = 0 AND chores_log.tracked_time < cl.tracked_time
					ORDER BY chores_log.tracked_time DESC
					LIMIT 1))::timestamp without time zone) / 3600.0)::integer AS frequency_hours
			FROM chores_log cl
			WHERE undone = 0
			SQL,
		'chores_current' => <<<'SQL'
			CREATE VIEW chores_current AS
			SELECT chore_id AS id,
				chore_id,
				chore_name,
				last_tracked_time,
				CASE
					WHEN rollover = 1 AND date_trunc('second', CURRENT_TIMESTAMP) > next_estimated_execution_time THEN
					CASE
						WHEN COALESCE(track_date_only::integer, 0) = 1 THEN victual_local_to_instant((to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD') || ' 23:59:59')::timestamp without time zone, current_setting('TimeZone'), false)
						ELSE victual_local_to_instant(((to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD') || ' ') || to_char(next_estimated_execution_time, 'HH24:MI:SS'))::timestamp without time zone, current_setting('TimeZone'), false)
					END
					ELSE
					CASE
						WHEN COALESCE(track_date_only::integer, 0) = 1 THEN victual_local_to_instant((to_char(next_estimated_execution_time, 'YYYY-MM-DD') || ' 23:59:59')::timestamp without time zone, current_setting('TimeZone'), false)
						ELSE next_estimated_execution_time
					END
				END AS next_estimated_execution_time,
				track_date_only,
				next_execution_assigned_to_user_id,
				CASE WHEN rescheduled_date IS NOT NULL THEN 1 ELSE 0 END AS is_rescheduled,
				CASE WHEN rescheduled_next_execution_assigned_to_user_id IS NOT NULL THEN 1 ELSE 0 END AS is_reassigned
			FROM (SELECT h.id AS chore_id,
					h.name AS chore_name,
					max(l.tracked_time) AS last_tracked_time,
					CASE
						WHEN h.rescheduled_date IS NOT NULL THEN h.rescheduled_date
						ELSE
						CASE
							WHEN max(l.tracked_time) IS NULL AND h.period_type <> 'manually' THEN h.start_date
							ELSE
							CASE h.period_type
								WHEN 'manually' THEN NULL::timestamptz
								WHEN 'hourly' THEN max(l.tracked_time) + ((h.period_interval::text || ' hour')::interval)
								WHEN 'daily' THEN victual_local_to_instant(((to_char(max(l.tracked_time) + ((h.period_interval::text || ' days')::interval), 'YYYY-MM-DD') || ' ') || to_char(h.start_date, 'HH24:MI:SS'))::timestamp without time zone, current_setting('TimeZone'), false)
								WHEN 'weekly' THEN (SELECT weekly_candidates.next
									FROM (SELECT s.step1 + (((((wd.day_num - EXTRACT(dow FROM s.step1)::integer + 7) % 7)::text) || ' days')::interval) AS next
										FROM (SELECT ((SELECT chores_log.tracked_time
												FROM chores_log
												WHERE chores_log.chore_id = h.id AND chores_log.undone = 0
												ORDER BY chores_log.tracked_time DESC
												LIMIT 1)) + ((((1 + (h.period_interval - 1) * 7)::text) || ' days')::interval) AS step1) s,
											(VALUES ('sunday', 0), ('monday', 1), ('tuesday', 2), ('wednesday', 3), ('thursday', 4), ('friday', 5), ('saturday', 6)) wd(day_name, day_num)
										WHERE POSITION((wd.day_name) IN (h.period_config)) > 0) weekly_candidates
									ORDER BY weekly_candidates.next
									LIMIT 1)
								WHEN 'monthly' THEN date_trunc('month', max(l.tracked_time)) + ((h.period_interval::text || ' month')::interval) + ((((h.period_days - 1)::text) || ' day')::interval)
								WHEN 'yearly' THEN victual_local_to_instant((to_char(h.start_date + ((((EXTRACT(year FROM max(l.tracked_time) + ((h.period_interval::text || ' years')::interval))::integer - EXTRACT(year FROM h.start_date)::integer)::text) || ' years')::interval), 'YYYY-MM-DD') || to_char(max(l.tracked_time) + ((h.period_interval::text || ' years')::interval), ' HH24:MI:SS'))::timestamp without time zone, current_setting('TimeZone'), false)
								WHEN 'adaptive' THEN max(l.tracked_time) + ((COALESCE((SELECT chores_execution_average_frequency.average_frequency_hours
									FROM chores_execution_average_frequency
									WHERE chores_execution_average_frequency.chore_id = h.id), 0::double precision)::text || ' hour')::interval)
								ELSE NULL::timestamptz
							END
						END
					END AS next_estimated_execution_time,
					h.track_date_only,
					h.rollover,
					h.next_execution_assigned_to_user_id,
					h.rescheduled_date,
					h.rescheduled_next_execution_assigned_to_user_id
				FROM chores h
					LEFT JOIN chores_log l ON h.id = l.chore_id AND l.undone = 0
				WHERE h.active = 1
				GROUP BY h.id, h.name, h.period_days) x
			SQL
	];

	/**
	 * Trigger functions that assigned LOCALTIMESTAMP to a column this migration converts.
	 * An implicit timestamp-to-timestamptz cast would read a repeated hour as the later
	 * instant, so they take the instant directly.
	 */
	const FUNCTION_OVERRIDES = <<<'SQL'
		CREATE OR REPLACE FUNCTION trg_default_start_date_when_empty_ins() RETURNS trigger LANGUAGE plpgsql AS $fn$
		BEGIN
			IF NEW.start_date IS NULL THEN
				NEW.start_date := date_trunc('second', CURRENT_TIMESTAMP);
			END IF;

			RETURN NEW;
		END;
		$fn$;

		CREATE OR REPLACE FUNCTION trg_default_start_date_when_empty_upd() RETURNS trigger LANGUAGE plpgsql AS $fn$
		BEGIN
			IF NEW.start_date IS NULL THEN
				NEW.start_date := date_trunc('second', CURRENT_TIMESTAMP);
			END IF;

			RETURN NEW;
		END;
		$fn$;
		SQL;

	/** The report of the last Apply() in this process, for bin/victual-migrate to print. */
	public static ?array $LastReport = null;

	private \PDO $pdo;

	public function __construct(\PDO $pdo)
	{
		$this->pdo = $pdo;
	}

	/**
	 * Every base-table column still typed `timestamp without time zone`, as
	 * [table => [column => default expression or null]].
	 */
	public function TargetColumns(): array
	{
		$rows = $this->pdo->query(<<<'SQL'
			SELECT c.relname AS table_name, a.attname AS column_name, pg_get_expr(d.adbin, d.adrelid) AS column_default
			FROM pg_attribute a
				JOIN pg_class c ON c.oid = a.attrelid
				LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
			WHERE c.relnamespace = current_schema()::regnamespace
				AND c.relkind IN ('r', 'p')
				AND a.attnum > 0 AND NOT a.attisdropped
				AND a.atttypid = 'timestamp without time zone'::regtype
			ORDER BY c.relname, a.attnum
			SQL)->fetchAll(\PDO::FETCH_ASSOC);

		$columns = [];
		foreach ($rows as $row)
		{
			$columns[$row['table_name']][$row['column_name']] = $row['column_default'];
		}

		return $columns;
	}

	/**
	 * The read-only report: per column, how many values there are, how many fall in a
	 * repeated hour (converted to the earlier instant, reported for information), and how
	 * many cannot be converted at all. Refusal reasons carry counts only - never a row's
	 * values - so the report can be pasted into an issue.
	 *
	 * Needs victual_local_to_instant(); PreflightStandalone() creates it in a transaction
	 * it rolls back.
	 *
	 * @return array{zone: string, columns: array, refusals: array, ambiguous_total: int, values_total: int, tzdata: array}
	 */
	public function Preflight(string $zone): array
	{
		$known = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = ?)');
		$known->execute([$zone]);
		if (!$known->fetchColumn())
		{
			return ['zone' => $zone, 'columns' => [], 'refusals' => ["the configured time zone \"$zone\" is not known to this PostgreSQL server"], 'ambiguous_total' => 0, 'values_total' => 0, 'tzdata' => []];
		}

		$report = ['zone' => $zone, 'columns' => [], 'refusals' => [], 'ambiguous_total' => 0, 'values_total' => 0, 'tzdata' => []];
		$minYear = null;
		$maxYear = null;

		foreach ($this->TargetColumns() as $table => $columns)
		{
			foreach (array_keys($columns) as $column)
			{
				$q = $this->QuoteIdentifier($table);
				$c = $this->QuoteIdentifier($column);
				$stmt = $this->pdo->prepare(<<<SQL
					SELECT
						count($c) AS values_present,
						count(*) FILTER (WHERE NOT isfinite($c)) AS infinite,
						count(*) FILTER (WHERE isfinite($c) AND (EXTRACT(year FROM $c) < 1 OR EXTRACT(year FROM $c) > 9999)) AS out_of_range,
						count(*) FILTER (WHERE isfinite($c) AND EXTRACT(year FROM $c) BETWEEN 1 AND 9999 AND victual_local_to_instant($c, :zone, true) IS NULL) AS skipped,
						count(*) FILTER (WHERE isfinite($c) AND EXTRACT(year FROM $c) BETWEEN 1 AND 9999 AND victual_local_to_instant($c, :zone, true) IS DISTINCT FROM ($c AT TIME ZONE :zone)) AS ambiguous,
						min(EXTRACT(year FROM $c)) FILTER (WHERE isfinite($c)) AS min_year,
						max(EXTRACT(year FROM $c)) FILTER (WHERE isfinite($c)) AS max_year
					FROM $q
					SQL);
				$stmt->execute(['zone' => $zone]);
				$r = $stmt->fetch(\PDO::FETCH_ASSOC);

				// `ambiguous` counts every value whose strict, earlier-instant reading differs
				// from PostgreSQL's own reading. The skipped values are counted separately and
				// excluded from it.
				$ambiguous = (int)$r['ambiguous'] - (int)$r['skipped'];
				$entry = [
					'table' => $table,
					'column' => $column,
					'values' => (int)$r['values_present'],
					'repeated_hour' => $ambiguous,
					'skipped_hour' => (int)$r['skipped'],
					'infinite' => (int)$r['infinite'],
					'out_of_range' => (int)$r['out_of_range']
				];
				$report['columns'][] = $entry;
				$report['values_total'] += $entry['values'];
				$report['ambiguous_total'] += $ambiguous;

				foreach (['skipped_hour' => 'name a wall clock the zone skipped', 'infinite' => 'are infinite', 'out_of_range' => 'fall outside years 1-9999'] as $key => $why)
				{
					if ($entry[$key] > 0)
					{
						$report['refusals'][] = "$table.$column: {$entry[$key]} value(s) $why in $zone";
					}
				}

				if ($r['min_year'] !== null)
				{
					$minYear = $minYear === null ? (int)$r['min_year'] : min($minYear, (int)$r['min_year']);
					$maxYear = $maxYear === null ? (int)$r['max_year'] : max($maxYear, (int)$r['max_year']);
				}
			}
		}

		$report['tzdata'] = $this->CompareTimeZoneData($zone, $minYear ?? (int)date('Y'), $maxYear ?? (int)date('Y'));
		foreach ($report['tzdata']['disagreements'] as $disagreement)
		{
			$report['refusals'][] = "PHP and PostgreSQL disagree about $zone: $disagreement";
		}

		return $report;
	}

	/**
	 * Preflight() without leaving anything behind: the conversion function is created inside
	 * a transaction that is rolled back, and the transaction is READ ONLY apart from that
	 * one CREATE. For `bin/victual-timestamp-preflight`.
	 */
	public function PreflightStandalone(string $zone): array
	{
		$this->pdo->beginTransaction();
		try
		{
			$this->pdo->exec(self::FUNCTION_SQL);
			return $this->Preflight($zone);
		}
		finally
		{
			$this->pdo->rollBack();
		}
	}

	/**
	 * PHP's and PostgreSQL's zone data, compared at every transition PHP knows for $zone in
	 * the years the stored values span (plus one either side). The two ship separate copies
	 * of the IANA database - PHP its bundled timezonedb, PostgreSQL usually the system's -
	 * and the import path converts in PHP while this migration converts in SQL, so a
	 * disagreement inside the data's range would convert the same wall clock to two
	 * different instants depending on the path. Outside the range it cannot matter.
	 */
	public function CompareTimeZoneData(string $zone, int $fromYear, int $toYear): array
	{
		$fromYear = max(1, $fromYear - 1);
		$toYear = min(9999, $toYear + 1);
		$tz = new \DateTimeZone($zone);
		$begin = (new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $fromYear), new \DateTimeZone('UTC')))->getTimestamp();
		$end = (new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $toYear), new \DateTimeZone('UTC')))->getTimestamp();

		$checkpoints = [$begin, $end];
		foreach ($tz->getTransitions($begin, $end) ?: [] as $transition)
		{
			$checkpoints[] = $transition['ts'];
			$checkpoints[] = $transition['ts'] - 1;
		}
		$checkpoints = array_values(array_unique($checkpoints));

		$stmt = $this->pdo->prepare("SELECT EXTRACT(epoch FROM (to_timestamp(?) AT TIME ZONE ?) - (to_timestamp(?) AT TIME ZONE 'UTC'))::integer");
		$disagreements = [];
		foreach ($checkpoints as $ts)
		{
			$stmt->execute([$ts, $zone, $ts]);
			$pgOffset = (int)$stmt->fetchColumn();
			$phpOffset = $tz->getOffset(new \DateTimeImmutable('@' . $ts));
			if ($pgOffset !== $phpOffset)
			{
				$disagreements[] = gmdate('Y-m-d\\TH:i:s\\Z', $ts) . " PHP offset $phpOffset s, PostgreSQL offset $pgOffset s";
			}
		}

		return [
			'php_timezonedb' => timezone_version_get(),
			'years' => [$fromYear, $toYear],
			'checkpoints' => count($checkpoints),
			'disagreements' => $disagreements
		];
	}

	/**
	 * Converts every target column, recreating the views that depend on them.
	 *
	 * Runs in the migration's transaction; PostgreSQL DDL is transactional, so a refusal or
	 * a failure partway leaves the schema as it was. Returns the preflight report it acted
	 * on. A database with no target column - a second run, or a schema that never had one -
	 * returns without touching anything, which is what makes a second invocation unable to
	 * convert a value twice.
	 */
	public function Apply(string $zone): array
	{
		$this->pdo->exec(self::FUNCTION_SQL);

		$targets = $this->TargetColumns();
		if (empty($targets))
		{
			return ['zone' => $zone, 'columns' => [], 'refusals' => [], 'ambiguous_total' => 0, 'values_total' => 0, 'tzdata' => []];
		}

		$report = $this->Preflight($zone);
		if (!empty($report['refusals']))
		{
			throw new \RuntimeException(
				"Migration 0301 refused to convert timestamps to TIMESTAMPTZ in time zone $zone. Nothing was changed.\n  - "
				. implode("\n  - ", $report['refusals'])
				. "\nCorrect these rows (or the configured zone) and run the migration again. "
				. 'bin/victual-timestamp-preflight prints this report without migrating.'
			);
		}

		// Fail fast rather than queue behind a long transaction: every table below is
		// rewritten under ACCESS EXCLUSIVE, and a migration waiting on a lock blocks every
		// reader that queues behind it.
		$this->pdo->exec("SET LOCAL lock_timeout = '30s'");

		$views = $this->CaptureViews();
		foreach (array_reverse($views) as $view)
		{
			$this->pdo->exec('DROP VIEW ' . $this->QuoteIdentifier($view['name']));
		}

		$quotedZone = $this->pdo->quote($zone);
		foreach ($targets as $table => $columns)
		{
			$clauses = [];
			foreach ($columns as $column => $default)
			{
				$c = $this->QuoteIdentifier($column);
				$clauses[] = "ALTER COLUMN $c TYPE timestamptz USING victual_local_to_instant($c, $quotedZone, true)";
				if ($default !== null)
				{
					$clauses[] = "ALTER COLUMN $c SET DEFAULT " . $this->InstantDefault($default, "$table.$column");
				}
			}

			// One ALTER TABLE per table, so each table is rewritten once however many of its
			// columns change.
			$this->pdo->exec('ALTER TABLE ' . $this->QuoteIdentifier($table) . ' ' . implode(', ', $clauses));
		}

		$this->pdo->exec(self::FUNCTION_OVERRIDES);

		foreach ($views as $view)
		{
			$this->RecreateView($view);
		}

		$this->AssertNoWallClocksRemain();

		return $report;
	}

	/**
	 * A legacy column default rewritten for TIMESTAMPTZ. Every one in the schema is
	 * LOCALTIMESTAMP-based or CURRENT_TIMESTAMP; anything else is refused rather than kept,
	 * because a default this code does not recognise is one it cannot vouch for.
	 */
	private function InstantDefault(string $default, string $where): string
	{
		$normalised = preg_replace('/\s+/', ' ', strtolower($default));
		$known = [
			"date_trunc('second'::text, localtimestamp)" => "date_trunc('second', CURRENT_TIMESTAMP)",
			'localtimestamp' => 'CURRENT_TIMESTAMP',
			'current_timestamp' => 'CURRENT_TIMESTAMP',
			"date_trunc('second'::text, current_timestamp)" => "date_trunc('second', CURRENT_TIMESTAMP)"
		];

		if (!isset($known[$normalised]))
		{
			throw new \RuntimeException("Migration 0301 does not know how to carry the default of $where ($default) over to TIMESTAMPTZ.");
		}

		return $known[$normalised];
	}

	/**
	 * Every view in the schema being migrated, in dependency order (a view after everything it
	 * reads), with what dropping it would lose: its definition, owner, privileges,
	 * comments and INSTEAD OF triggers.
	 */
	private function CaptureViews(): array
	{
		$rows = $this->pdo->query(<<<'SQL'
			SELECT c.oid, c.relname, pg_get_viewdef(c.oid) AS definition, pg_get_userbyid(c.relowner) AS owner,
				c.relacl::text[] AS acl, obj_description(c.oid, 'pg_class') AS comment, c.reloptions
			FROM pg_class c
			WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind = 'v'
			SQL)->fetchAll(\PDO::FETCH_ASSOC);

		$byOid = [];
		foreach ($rows as $row)
		{
			$byOid[(int)$row['oid']] = $row;
		}

		$edges = $this->pdo->query(<<<'SQL'
			SELECT DISTINCT r.ev_class AS view_oid, d.refobjid AS depends_on
			FROM pg_rewrite r
				JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = r.oid
				JOIN pg_class rc ON rc.oid = d.refobjid AND rc.relkind = 'v'
			WHERE d.refobjid <> r.ev_class
			SQL)->fetchAll(\PDO::FETCH_ASSOC);

		$dependsOn = [];
		foreach ($edges as $edge)
		{
			$dependsOn[(int)$edge['view_oid']][] = (int)$edge['depends_on'];
		}

		$ordered = [];
		$state = [];
		$visit = function (int $oid) use (&$visit, &$ordered, &$state, $byOid, $dependsOn)
		{
			// PostgreSQL refuses a view that depends on itself, directly or not, so the walk
			// cannot meet a cycle; a node is visited once.
			if (isset($state[$oid]) || !isset($byOid[$oid]))
			{
				return;
			}

			$state[$oid] = 1;
			foreach ($dependsOn[$oid] ?? [] as $dependency)
			{
				$visit($dependency);
			}
			$ordered[] = $oid;
		};

		$oids = array_keys($byOid);
		sort($oids);
		foreach ($oids as $oid)
		{
			$visit($oid);
		}

		$views = [];
		foreach ($ordered as $oid)
		{
			$row = $byOid[$oid];

			$columnComments = $this->pdo->query('SELECT a.attname, col_description(a.attrelid, a.attnum) AS comment FROM pg_attribute a WHERE a.attrelid = ' . $oid
				. ' AND a.attnum > 0 AND col_description(a.attrelid, a.attnum) IS NOT NULL')->fetchAll(\PDO::FETCH_KEY_PAIR);

			$triggers = $this->pdo->query('SELECT pg_get_triggerdef(t.oid) FROM pg_trigger t WHERE t.tgrelid = ' . $oid . ' AND NOT t.tgisinternal ORDER BY t.tgname')
				->fetchAll(\PDO::FETCH_COLUMN);

			$views[] = [
				'name' => $row['relname'],
				'definition' => $row['definition'],
				'owner' => $row['owner'],
				'acl' => $row['acl'],
				'comment' => $row['comment'],
				'options' => $row['reloptions'],
				'column_comments' => $columnComments,
				'triggers' => $triggers
			];
		}

		return $views;
	}

	private function RecreateView(array $view): void
	{
		$name = $this->QuoteIdentifier($view['name']);

		if (isset(self::VIEW_OVERRIDES[$view['name']]))
		{
			$this->pdo->exec(self::VIEW_OVERRIDES[$view['name']]);
		}
		else
		{
			$options = $view['options'] !== null ? ' WITH (' . trim($view['options'], '{}') . ')' : '';
			$this->pdo->exec("CREATE VIEW $name$options AS " . $view['definition']);
		}

		$currentUser = $this->pdo->query('SELECT current_user')->fetchColumn();
		if ($view['owner'] !== $currentUser)
		{
			$this->pdo->exec("ALTER VIEW $name OWNER TO " . $this->QuoteIdentifier($view['owner']));
		}

		if ($view['acl'] !== null)
		{
			// Reapplied from the captured ACL rather than left to default privileges, so a
			// grant someone made by hand on one view survives too. aclexplode() over the
			// captured text array gives one row per grantee and privilege.
			$grants = $this->pdo->prepare(<<<'SQL'
				SELECT CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee,
					a.privilege_type, a.is_grantable
				FROM aclexplode(?::aclitem[]) a
				SQL);
			$grants->execute([$this->ArrayLiteral($view['acl'])]);
			foreach ($grants->fetchAll(\PDO::FETCH_ASSOC) as $grant)
			{
				$this->pdo->exec('GRANT ' . $grant['privilege_type'] . " ON $name TO " . $grant['grantee'] . ($grant['is_grantable'] ? ' WITH GRANT OPTION' : ''));
			}
		}

		if ($view['comment'] !== null)
		{
			$this->pdo->exec("COMMENT ON VIEW $name IS " . $this->pdo->quote($view['comment']));
		}

		foreach ($view['column_comments'] as $column => $comment)
		{
			$this->pdo->exec("COMMENT ON COLUMN $name." . $this->QuoteIdentifier($column) . ' IS ' . $this->pdo->quote($comment));
		}

		foreach ($view['triggers'] as $trigger)
		{
			$this->pdo->exec($trigger);
		}
	}

	/**
	 * The postcondition: no table column and no view column in the schema being migrated is still a
	 * wall clock. A view that derived one would send it unconverted, so this is checked
	 * rather than assumed.
	 */
	private function AssertNoWallClocksRemain(): void
	{
		$left = $this->pdo->query(<<<'SQL'
			SELECT c.relname || '.' || a.attname
			FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
			WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind IN ('r', 'p', 'v')
				AND a.attnum > 0 AND NOT a.attisdropped
				AND a.atttypid = 'timestamp without time zone'::regtype
			ORDER BY 1
			SQL)->fetchAll(\PDO::FETCH_COLUMN);

		if (!empty($left))
		{
			throw new \RuntimeException('Migration 0301 left wall-clock columns behind: ' . implode(', ', $left));
		}
	}

	private function ArrayLiteral($value): string
	{
		return is_array($value) ? '{' . implode(',', array_map(fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"', $value)) . '}' : (string)$value;
	}

	private function QuoteIdentifier(string $identifier): string
	{
		return '"' . str_replace('"', '""', $identifier) . '"';
	}
}
