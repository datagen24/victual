-- Issue #952 (maintainer decision 2026-10-18, option A): a conversion write is refused when it
-- would make a factor the resolver evaluates, or that factor's inverse, anything but a positive,
-- finite, normal double. The write rolls back, and the refusal says why.
--
-- WHY. Issue #890 (decision L) made a barcode lookup's factor and its inverse normal, but only
-- for that one write path and only for the factor written. quantity_unit_conversions_resolved
-- multiplies factors along every path it explores (`c.factor * s.factor`), so two normal factors
-- such as 1e10 and 1e300 overflow there. PostgreSQL then raises 22003 from inside the cache
-- rebuild the insert trigger runs, and the caller saw the generic "the database rejected this
-- request". A product of two small factors goes the other way: it can underflow to a subnormal,
-- which PostgreSQL stores without complaint and with most of its precision gone, or below that
-- (22003 again).
--
-- THE RULE. A factor f, written or resolved, must satisfy 2^-1022 <= f <= 2^1022. That is exactly
-- "f and 1/f are both normal": the smallest positive normal double is 2^-1022, so f >= 2^-1022
-- makes f normal and 1/f <= 2^1022 finite, and f <= 2^1022 makes 1/f >= 2^-1022 normal. Values are
-- compared as written, with no clamping and no silently dropped path.
--
-- WHERE. The three row triggers on quantity_unit_conversions already rebuild the cache rows for
-- every path through the two units they touch. That rebuild now goes through
-- refresh_quantity_unit_conversion_cache(), which turns a 22003 raised while the resolver runs
-- into the refusal, and then checks every rebuilt row against the rule. The insert and update
-- triggers also check the written factor first, before they compute its inverse. A delete is
-- checked too, because removing a direct conversion can let a longer path win.
--
-- THE REFUSAL. SQLSTATE VQ952 (class V is free for applications), with the client-facing text
-- as the message. Services\Database\ConversionChainRange recognises it and the API answers 400
-- with that text.
--
-- Nothing is rewritten. A database whose stored conversions already break the rule keeps them;
-- the rule applies to the next write that rebuilds an affected path.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

-- The refusal for a factor outside the rule. $1 names the conversion, from the caller's side.
CREATE OR REPLACE FUNCTION raise_quantity_unit_conversion_out_of_range(what TEXT) RETURNS VOID AS $$
BEGIN
	RAISE EXCEPTION USING
		ERRCODE = 'VQ952',
		MESSAGE = 'This quantity unit conversion would make ' || what
			|| ' too large or too small to use: every conversion factor, including one reached through other conversions, and its inverse must be between 2^-1022 and 2^1022';
END;
$$ LANGUAGE plpgsql;

-- Whether f is a factor the rule admits (see the header).
CREATE OR REPLACE FUNCTION quantity_unit_conversion_factor_in_range(f DOUBLE PRECISION) RETURNS BOOLEAN AS $$
	SELECT f >= power(2::double precision, -1022) AND f <= power(2::double precision, 1022);
$$ LANGUAGE sql IMMUTABLE;

-- Rebuilds the cache rows for every path through unit a or unit b and refuses the write when the
-- resolver overflows or underflows, or a rebuilt factor or a chain of global conversions through
-- a or b falls outside the rule.
CREATE OR REPLACE FUNCTION refresh_quantity_unit_conversion_cache(a INTEGER, b INTEGER) RETURNS VOID AS $$
DECLARE
	offender RECORD;
BEGIN
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || a::text || '/%'
		OR path LIKE '%/' || b::text || '/%';

	BEGIN
		INSERT INTO cache__quantity_unit_conversions_resolved
			(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
		SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
		FROM quantity_unit_conversions_resolved
		WHERE path LIKE '%/' || a::text || '/%'
			OR path LIKE '%/' || b::text || '/%';
	EXCEPTION WHEN numeric_value_out_of_range THEN
		PERFORM raise_quantity_unit_conversion_out_of_range('a conversion it is chained with');
	END;

	-- The resolver evaluates a chain of global conversions only for a product that uses its units,
	-- and whether it does so for any other is up to the query plan. So the closure of the global
	-- conversions through a or b is evaluated here on its own, the way the resolver's
	-- default_closure builds it (db/pgsql/baseline/03_views_group2.sql), whether or not a product
	-- uses those units yet.
	BEGIN
		WITH RECURSIVE
		default_conversions(from_qu_id, to_qu_id, factor) AS (
			SELECT from_qu_id, to_qu_id, factor FROM quantity_unit_conversions WHERE product_id IS NULL
		),
		default_closure(depth, from_qu_id, to_qu_id, factor, path) AS (
			SELECT 1, from_qu_id, to_qu_id, factor, '/' || from_qu_id::text || '/' || to_qu_id::text || '/'
			FROM default_conversions
			UNION
			SELECT c.depth + 1, c.from_qu_id, s.to_qu_id, c.factor * s.factor, c.path || s.to_qu_id::text || '/'
			FROM default_closure c
			JOIN default_conversions s ON c.to_qu_id = s.from_qu_id
			WHERE c.path NOT LIKE ('%/' || s.to_qu_id::text || '/%')
				AND NOT EXISTS (SELECT 1 FROM default_conversions ci WHERE ci.from_qu_id = c.from_qu_id AND ci.to_qu_id = s.to_qu_id)
		)
		SELECT f.name AS from_qu_name, t.name AS to_qu_name, NULL::integer AS product_id INTO offender
		FROM default_closure dc
		JOIN quantity_units f ON f.id = dc.from_qu_id
		JOIN quantity_units t ON t.id = dc.to_qu_id
		WHERE (dc.path LIKE '%/' || a::text || '/%' OR dc.path LIKE '%/' || b::text || '/%')
			AND NOT quantity_unit_conversion_factor_in_range(dc.factor)
		ORDER BY dc.depth, dc.from_qu_id, dc.to_qu_id
		LIMIT 1;
	EXCEPTION WHEN numeric_value_out_of_range THEN
		PERFORM raise_quantity_unit_conversion_out_of_range('a conversion it is chained with');
	END;

	IF FOUND THEN
		PERFORM raise_quantity_unit_conversion_out_of_range(
			'the conversion from ' || COALESCE(offender.from_qu_name, '?') || ' to ' || COALESCE(offender.to_qu_name, '?'));
	END IF;

	SELECT from_qu_name, to_qu_name, product_id INTO offender
	FROM cache__quantity_unit_conversions_resolved
	WHERE (path LIKE '%/' || a::text || '/%' OR path LIKE '%/' || b::text || '/%')
		AND NOT quantity_unit_conversion_factor_in_range(factor::double precision)
	ORDER BY product_id NULLS FIRST, from_qu_id, to_qu_id
	LIMIT 1;

	IF FOUND THEN
		PERFORM raise_quantity_unit_conversion_out_of_range(
			'the conversion from ' || COALESCE(offender.from_qu_name, '?') || ' to ' || COALESCE(offender.to_qu_name, '?')
			|| CASE WHEN offender.product_id IS NULL THEN '' ELSE ' for product ' || offender.product_id::text END);
	END IF;
END;
$$ LANGUAGE plpgsql;

-- Baseline definitions (db/pgsql/baseline/06_triggers_a.sql) with the cache rebuild replaced by
-- refresh_quantity_unit_conversion_cache() and, on insert and update, the written factor checked
-- before its inverse is computed.
-- Writes the inverse conversion and rebuilds the affected cache rows, refusing an out-of-range factor
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_INS() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('victual.in_quc_ins', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_ins', '1', true);

	IF NOT quantity_unit_conversion_factor_in_range(COALESCE(NEW.factor, 1)) THEN
		PERFORM raise_quantity_unit_conversion_out_of_range('its own factor');
	END IF;

	-- Create the inverse QU conversion (see the baseline's note: plain INSERT is equivalent to the
	-- original "INSERT OR REPLACE", there being no real unique constraint to trigger on)
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	VALUES
		(NEW.to_qu_id, NEW.from_qu_id, 1 / COALESCE(NEW.factor, 1), NEW.product_id);

	PERFORM refresh_quantity_unit_conversion_cache(NEW.from_qu_id, NEW.to_qu_id);

	PERFORM set_config('victual.in_quc_ins', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Updates the inverse conversion and rebuilds the affected cache rows, refusing an out-of-range factor
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_UPD() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('victual.in_quc_upd', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_upd', '1', true);

	IF NOT quantity_unit_conversion_factor_in_range(COALESCE(NEW.factor, 1)) THEN
		PERFORM raise_quantity_unit_conversion_out_of_range('its own factor');
	END IF;

	-- Update the inverse QU conversion
	UPDATE quantity_unit_conversions
	SET factor = 1 / COALESCE(NEW.factor, 1),
		from_qu_id = NEW.to_qu_id,
		to_qu_id = NEW.from_qu_id
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(NEW.product_id, -1);

	PERFORM refresh_quantity_unit_conversion_cache(NEW.from_qu_id, NEW.to_qu_id);

	PERFORM set_config('victual.in_quc_upd', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Deletes the inverse conversion and rebuilds the affected cache rows, refusing a path that would now resolve out of range
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_DEL() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('victual.in_quc_del', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('victual.in_quc_del', '1', true);

	-- Delete the inverse QU conversion
	DELETE FROM quantity_unit_conversions
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(OLD.product_id, -1);

	PERFORM refresh_quantity_unit_conversion_cache(OLD.from_qu_id, OLD.to_qu_id);

	PERFORM set_config('victual.in_quc_del', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
