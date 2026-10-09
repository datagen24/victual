-- SPIKE ONLY. ADR-0042 sections 2 to 4 as SQL expressions over DATE and integer, loaded into
-- a scratch schema by the probes. Not a migration and not a candidate implementation.

-- Estimate: explicit date, then the medication rule, then the fallback (section 2), with the
-- unknown reasons of section 3. Where the ADR is silent the choice is marked in a comment and
-- listed in RESULTS.md.
CREATE FUNCTION adr42_estimate(
	explicit date, rule text, rule_n numeric, filled date, supplied integer,
	OUT reorder date, OUT source text, OUT reason text)
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
	days integer;
BEGIN
	-- An explicit date applies only to a current fill, so no fill means no_fill.
	IF filled IS NULL THEN reason := 'no_fill'; RETURN; END IF;
	IF explicit IS NOT NULL THEN reorder := explicit; source := 'explicit'; RETURN; END IF;
	-- Silent in the ADR: invalid_supply is checked before invalid_rule, and for every rule kind.
	IF supplied IS NULL OR supplied < 1 OR supplied > 730 THEN reason := 'invalid_supply'; RETURN; END IF;
	IF rule IS NULL THEN
		IF supplied <= 14 THEN reason := 'supply_not_longer_than_lead'; RETURN; END IF;
		reorder := filled + (supplied - 14); source := 'fallback'; RETURN;
	ELSIF rule = 'days_before_end' THEN
		IF rule_n IS NULL OR rule_n <> trunc(rule_n) OR rule_n < 0 OR rule_n > 730 THEN reason := 'invalid_rule'; RETURN; END IF;
		IF supplied <= rule_n THEN reason := 'supply_not_longer_than_lead'; RETURN; END IF;
		reorder := filled + (supplied - rule_n::integer); source := 'rule:days_before_end'; RETURN;
	ELSIF rule = 'fixed_interval' THEN
		IF rule_n IS NULL OR rule_n <> trunc(rule_n) OR rule_n < 1 OR rule_n > 730 THEN reason := 'invalid_rule'; RETURN; END IF;
		reorder := filled + rule_n::integer; source := 'rule:fixed_interval'; RETURN;
	ELSIF rule = 'fraction_elapsed' THEN
		IF rule_n IS NULL OR rule_n <= 0 OR rule_n >= 1 THEN reason := 'invalid_rule'; RETURN; END IF;
		-- numeric multiplication is exact; the ADR does not say which type F has.
		days := floor(supplied * rule_n)::integer;
		reorder := filled + days; source := 'rule:fraction_elapsed'; RETURN;
	END IF;
	reason := 'invalid_rule';
END $$;

-- Status (section 4). An open order wins over every date; a null reorder date is unknown.
CREATE FUNCTION adr42_status(reorder date, lead integer, as_of date, has_open_order boolean,
	OUT status text, OUT days_overdue integer, OUT warning_date date)
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
	IF has_open_order THEN status := 'ordered'; RETURN; END IF;
	IF reorder IS NULL THEN status := 'unknown'; RETURN; END IF;
	warning_date := reorder - lead;
	IF as_of >= reorder THEN status := 'due'; days_overdue := as_of - reorder;
	ELSIF as_of >= reorder - lead THEN status := 'approaching';
	ELSE status := 'ok'; END IF;
END $$;
