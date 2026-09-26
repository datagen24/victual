-- Issue #487 remediation (WS-15): three views recreated from their latest definitions,
-- each fixing one confirmed defect. The baseline in db/pgsql/baseline/ is deliberately not
-- edited, for the reason migrations/0261.pgsql.sql gives: it is the state a fresh
-- PostgreSQL database loads before 0256 onward runs, and none of these three views has been
-- touched by a migration since (stock_current was last redefined by 0275.pgsql.sql; the
-- other two are still exactly db/pgsql/baseline/04_views_l1b.sql and
-- db/pgsql/baseline/05_views_l2.sql). Each view below is that latest definition, unchanged
-- except for the lines the referenced issue names.
--
-- 1. stock_current.amount_opened_aggregated (issue #501, audit M1). The parent-rollup
--    branch summed every open child's raw `amount` and multiplied the sum by MIN(factor) -
--    correct only when every child shares one conversion factor. A child at factor 0.25 and
--    another at factor 2, each fully opened, reported 0.5 instead of the 2.25
--    `amount_aggregated` already computes for the same rows. Fixed the way `amount_aggregated`
--    itself is computed: SUM the per-row contribution (amount * that row's own factor,
--    COALESCEd to 1.0 the same way), restricted to open rows, using the qucr join the FROM
--    clause already carries per (parent, sub) pair - no new join or subquery needed. The
--    second branch (sub products not rolled into a parent) is untouched: a single product has
--    no conversion to mix, so its amount_opened_aggregated is deliberately amount_opened.
--
-- 2. uihelper_stock_journal (issue #505, audit M5). `JOIN locations l` hid every stock_log
--    row whose location had since been deleted - two consumed-and-deleted rows read back as
--    zero, although stock_log itself still had them. ADR-0029 decision 3 already leaves
--    `stock_log.location_id` unconstrained specifically so a historical booking may keep
--    referencing a deleted location; this view just never accounted for that. Changed to
--    `LEFT JOIN locations l`, so history stays visible and `location_name` is NULL for a
--    deleted location - which views/stockjournal.blade.php already handles: it falls back to
--    the row's own `location_name` whenever the location is not among the household's current
--    (non-deleted) locations (`$journalLocation === null`), and the same blade template
--    already echoes other nullable stock_log columns (`note`) the same way. No PHP or Blade
--    change was needed. The view is read only by StockController::Journal() - it is not an
--    ExposedEntity and GET /api/objects/uihelper_stock_journal answers 400 by design
--    (tests/Pgsql/WireContractTest.php::testTheDeadJournalSchemasStayDeleted) - so there is no
--    API response shape to preserve here beyond that 400.
--
-- 3. chores_current (issues #497 audit H8, and #506 audit M6's weekly-schedule half only;
--    M6's chore-undo/stock-composition half is a separate change).
--
--    a. The 'yearly' branch built next_estimated_execution_time by concatenating the target
--       year (as text) with h.start_date's own '-MM-DD' (also as text) and casting the
--       result to timestamp. A chore anchored on 29 February reaches a non-leap target year
--       exactly the way any other yearly chore does, but '2025-02-29' has no cast - SQLSTATE
--       22008 on every read of chores_current once that happens, for that one row, which is
--       enough to fail a query that joins or filters across the whole view. Maintainer
--       decision (#497/#487): such a chore is due 28 February in a non-leap year - the
--       start date's month and day, clamped to the last day of that month when the day does
--       not exist that year - so 2028 is 29 February again. Fixed by adding the same
--       year-interval to h.start_date itself instead of gluing text onto it: PostgreSQL's own
--       date/interval arithmetic already clamps a nonexistent day down to the last day of the
--       resulting month (`'2024-02-29'::date + interval '1 year'` is '2025-02-28'), so
--       computing the anniversary this way can never produce a string a cast has to refuse.
--       The time-of-day the branch already carried (from MAX(l.tracked_time) plus the same
--       interval) is untouched.
--
--    b. The 'weekly' branch picked the chore's most recent chores_log row with no `undone`
--       filter, unlike every other branch (and unlike the outer LEFT JOIN's own
--       `l.undone = 0`, which is what feeds MAX(l.tracked_time) everywhere else) - an undone
--       execution still drove the weekly schedule. db/pgsql/baseline/05_views_l2.sql's own
--       header comment (note 3) records this as a deliberate faithful port of upstream
--       SQLite's asymmetry; issue #506 (audit M6) finds it a genuine defect rather than a
--       behaviour worth keeping, and this migration is the maintainer decision to fix it.
--       Fixed by adding `AND undone = 0` to that subquery, matching every other branch.
--
--    Every other branch, column and join is byte-identical to
--    db/pgsql/baseline/05_views_l2.sql.

CREATE OR REPLACE VIEW stock_current AS
SELECT
	pr.parent_product_id AS product_id,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id), 0) AS amount,
	SUM(s.amount * COALESCE(qucr.factor::double precision, 1.0::double precision)) AS amount_aggregated,
	COALESCE(CAST(ROUND(CAST((SELECT SUM(COALESCE(price,0) * amount) FROM stock WHERE product_id = pr.parent_product_id) AS numeric), 2) AS double precision), 0) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id AND open = 1), 0) AS amount_opened,
	-- Fix for issue #501 (audit M1): each row's own opened amount, converted by that row's own
	-- factor - the same conversion amount_aggregated above applies - summed only over open
	-- rows, rather than summing raw amounts first and multiplying by MIN(factor) once.
	COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount * COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 0 END), 0) AS amount_opened_aggregated,
	CASE WHEN COUNT(p_sub.parent_product_id) > 0  THEN 1 ELSE 0 END AS is_aggregated_amount,
	MAX(p_parent.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision * COALESCE(qucr.factor::double precision, 1.0::double precision)), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_parent
	ON pr.parent_product_id = p_parent.id
	AND p_parent.active = 1
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON pr.sub_product_id = qucr.product_id
	AND p_sub.qu_id_stock = qucr.from_qu_id
	AND p_parent.qu_id_stock = qucr.to_qu_id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
GROUP BY pr.parent_product_id
HAVING SUM(s.amount) > 0

UNION

-- This is the same as above but sub products not rolled up (no QU conversion and column is_aggregated_amount = 0 here)
SELECT
	pr.sub_product_id AS product_id,
	SUM(s.amount) AS amount,
	SUM(s.amount) AS amount_aggregated,
	CAST(ROUND(CAST(SUM(COALESCE(s.price, 0) * s.amount) AS numeric), 2) AS double precision) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened_aggregated,
	0 AS is_aggregated_amount,
	MAX(p_sub.due_type) AS due_type,
	COALESCE(SUM(s.opened_amount * qucr_measure.factor::double precision), 0) AS amount_measured
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr_measure
	ON s.product_id = qucr_measure.product_id
	AND s.opened_qu_id = qucr_measure.from_qu_id
	AND p_sub.qu_id_stock = qucr_measure.to_qu_id
WHERE pr.parent_product_id != pr.sub_product_id
GROUP BY pr.sub_product_id
HAVING SUM(s.amount) > 0;

CREATE OR REPLACE VIEW uihelper_stock_journal AS
SELECT
	sl.id,
	sl.row_created_timestamp,
	sl.correlation_id,
	sl.undone,
	sl.undone_timestamp,
	sl.transaction_type,
	sl.spoiled,
	sl.amount,
	sl.location_id,
	l.name AS location_name,
	p.name AS product_name,
	qu.name AS qu_name,
	qu.name_plural AS qu_name_plural,
	u.display_name AS user_display_name,
	p.id AS product_id,
	sl.note,
	sl.stock_id
FROM stock_log sl
LEFT JOIN users_dto u
	ON sl.user_id = u.id
JOIN products p
	ON sl.product_id = p.id
-- Fix for issue #505 (audit M5): LEFT JOIN, not JOIN - a deleted location must not hide the
-- stock_log rows that still reference it. location_name is NULL for those rows.
LEFT JOIN locations l
	ON sl.location_id = l.id
JOIN quantity_units qu
	ON p.qu_id_stock = qu.id;

CREATE OR REPLACE VIEW chores_current AS
SELECT
	x.chore_id AS id, -- Dummy, LessQL needs an id column
	x.chore_id,
	x.chore_name,
	x.last_tracked_time,
	CASE WHEN x.rollover = 1 AND date_trunc('second', LOCALTIMESTAMP) > x.next_estimated_execution_time THEN
		CASE WHEN COALESCE(x.track_date_only, 0) = 1 THEN
			(to_char(date_trunc('second', LOCALTIMESTAMP), 'YYYY-MM-DD') || ' 23:59:59')::timestamp
		ELSE
			(to_char(date_trunc('second', LOCALTIMESTAMP), 'YYYY-MM-DD') || ' ' || to_char(x.next_estimated_execution_time, 'HH24:MI:SS'))::timestamp
		END
	ELSE
		CASE WHEN COALESCE(x.track_date_only, 0) = 1 THEN
			(to_char(x.next_estimated_execution_time, 'YYYY-MM-DD') || ' 23:59:59')::timestamp
		ELSE
			x.next_estimated_execution_time
		END
	END AS next_estimated_execution_time,
	x.track_date_only,
	x.next_execution_assigned_to_user_id,
	CASE WHEN x.rescheduled_date IS NOT NULL THEN 1 ELSE 0 END AS is_rescheduled,
	CASE WHEN x.rescheduled_next_execution_assigned_to_user_id IS NOT NULL THEN 1 ELSE 0 END AS is_reassigned
FROM (

SELECT
	h.id AS chore_id,
	h.name AS chore_name,
	MAX(l.tracked_time) AS last_tracked_time,
	CASE WHEN h.rescheduled_date IS NOT NULL THEN
		h.rescheduled_date
	ELSE
		CASE WHEN MAX(l.tracked_time) IS NULL AND h.period_type != 'manually' THEN
			h.start_date
		ELSE
			CASE h.period_type
				WHEN 'manually' THEN NULL::timestamp
				WHEN 'hourly' THEN (MAX(l.tracked_time) + (h.period_interval::text || ' hour')::interval)
				WHEN 'daily' THEN (to_char(MAX(l.tracked_time) + (h.period_interval::text || ' days')::interval, 'YYYY-MM-DD') || ' ' || to_char(h.start_date, 'HH24:MI:SS'))::timestamp
				WHEN 'weekly' THEN (
					SELECT next
					FROM (
						SELECT
							s.step1 + (((wd.day_num - EXTRACT(DOW FROM s.step1)::integer + 7) % 7)::text || ' days')::interval AS next
						FROM (
							SELECT
								-- Fix for issue #506 (audit M6, weekly-schedule half): filter out
								-- undone executions, like every other branch (they read
								-- MAX(l.tracked_time), fed by the outer LEFT JOIN's own
								-- l.undone = 0) and unlike the rest of this file's usual
								-- practice of preserving upstream SQLite behaviour verbatim -
								-- db/pgsql/baseline/05_views_l2.sql's own header comment (note 3)
								-- records the un-filtered read as a deliberate port of that
								-- upstream asymmetry; the audit found it a genuine defect, and an
								-- undone execution must not still drive the schedule.
								(SELECT tracked_time FROM chores_log WHERE chore_id = h.id AND undone = 0 ORDER BY tracked_time DESC LIMIT 1)
								+ ((1 + (h.period_interval - 1) * 7)::text || ' days')::interval AS step1
						) s,
						(VALUES ('sunday', 0), ('monday', 1), ('tuesday', 2), ('wednesday', 3), ('thursday', 4), ('friday', 5), ('saturday', 6)) AS wd(day_name, day_num)
						WHERE position(wd.day_name IN h.period_config) > 0
					) weekly_candidates
					ORDER BY next
					LIMIT 1
				)
				WHEN 'monthly' THEN (date_trunc('month', MAX(l.tracked_time)) + (h.period_interval::text || ' month')::interval + ((h.period_days - 1)::text || ' day')::interval)
				-- Fix for issue #497 (audit H8): the month and day still come from
				-- h.start_date, but by adding a year-interval to h.start_date itself rather
				-- than concatenating '-MM-DD' text onto the target year. PostgreSQL's date
				-- arithmetic clamps a nonexistent day to the last day of the resulting month
				-- (`'2024-02-29'::date + interval '1 year'` is '2025-02-28'), so a 29 February
				-- anchor is due 28 February in a non-leap target year and 29 February again
				-- once the target year is itself a leap year - and the result is always a
				-- constructible date, unlike the string this view used to build and cast.
				-- The time-of-day (from MAX(l.tracked_time) plus the same interval) is
				-- unchanged.
				WHEN 'yearly' THEN (
					to_char(
						h.start_date + ((
							EXTRACT(YEAR FROM (MAX(l.tracked_time) + (h.period_interval::text || ' years')::interval))::integer
							- EXTRACT(YEAR FROM h.start_date)::integer
						)::text || ' years')::interval,
						'YYYY-MM-DD'
					)
					|| to_char(MAX(l.tracked_time) + (h.period_interval::text || ' years')::interval, ' HH24:MI:SS')
				)::timestamp
				WHEN 'adaptive' THEN (MAX(l.tracked_time) + (COALESCE((SELECT average_frequency_hours FROM chores_execution_average_frequency WHERE chore_id = h.id), 0)::text || ' hour')::interval)
			END
		END
	END AS next_estimated_execution_time,
	h.track_date_only,
	h.rollover,
	h.next_execution_assigned_to_user_id,
	h.rescheduled_date,
	h.rescheduled_next_execution_assigned_to_user_id
FROM chores h
LEFT JOIN chores_log l
	ON h.id = l.chore_id
	AND l.undone = 0
WHERE h.active = 1
GROUP BY h.id, h.name, h.period_days
) x;
