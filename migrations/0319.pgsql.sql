-- Issue #1104, ADR-0031 (Proposed) acceptance prerequisite 1: victual_barcode_canonicalize, the one
-- function that owns a product barcode's canonical reading (ADR-0031 decision 5), as plan 34's
-- "Canonicalisation function" section specifies it.
--
-- NOTHING CALLS IT. ADR-0031 is Proposed, and a Proposed record constrains nothing, so this
-- migration adds no column, no table, no trigger and no caller: product_barcodes, the write paths,
-- the resolver and helpers/Gtin.php are unchanged. The function is installed so that its pgTAP
-- (.devtools/pgtap/041-barcode-canonicalize.sql) can stand as evidence for the record's acceptance.
-- If the record is rejected, a later migration drops both functions and nothing else changes.
--
-- THE CONTRACT. victual_barcode_canonicalize(code, carrier, patterns) is IMMUTABLE and PARALLEL
-- SAFE, and it reads no table: every input arrives as an argument, including the
-- restricted-circulation patterns. It returns one row per candidate pattern that matched, in the
-- order the caller gave them, and then the non-pattern reading:
--
--   identifier_type  'gtin', 'restricted' or 'opaque'; NULL when status is 'malformed'
--   canonical        14 digits for gtin and restricted, the trimmed text for opaque; NULL when
--                    malformed
--   pattern_id       the matched pattern's id, restricted rows only
--   decoded_value    the price or weight the sticker carries, restricted rows only
--   value_kind       'price' or 'weight', restricted rows only
--   status           'ok', 'invalid_check_digit' or 'malformed'
--
-- `carrier` is the scanner's report (plan 34's carrier column). Only 'upc_e' changes the reading
-- (ADR-0031 decision 4); any other value, or NULL, is no hint.
--
-- `patterns` is a JSON array of objects, or NULL. Each object is a barcode_patterns row as plan 34
-- lists its fields, with the same keys: id, layout, value_kind, decimals, weight_unit, currency,
-- verifier, and optionally supersedes_pattern_id, which the reading does not use. A pattern whose
-- fields disagree with one another is a caller error and raises SQLSTATE 22023
-- (invalid_parameter_value); it is not a status, because a stored pattern can never be in that
-- state once barcode_patterns exists with its constraints.
--
-- THE VERIFIER IS NOT HERE. A pattern whose verifier is 'four_digit' or 'five_digit' is accepted
-- as valid, but when it matches a payload the function raises SQLSTATE 0A000
-- (feature_not_supported). Checking that digit is ADR-0031 acceptance prerequisite 2, which must
-- name the GS1 General Specifications section it implements; the refusal marks the one place it
-- plugs in. A verifier pattern that does not match the payload does not raise.
--
-- Choices plan 34 leaves open, made here and pinned by the pgTAP file:
--   - Spaces around the code are trimmed before anything else, so a typed " 012345678905 " is
--     GTIN-shaped. Every other control character than GS (0x1D) is malformed (plan step 1), and
--     that includes tab, CR and LF.
--   - A failing check digit returns identifier_type 'gtin' with the digits padded to 14 as the
--     canonical value, so the 12- and 13-digit forms of one mistyped code name one identity.
--   - The opaque canonical value is the payload after the AIM identifier is removed. The AIM
--     identifier says which carrier read the code, and the carrier is not part of identity
--     (ADR-0031 decision 2).
--   - With the upc_e hint the input is GTIN-shaped (ADR-0031 decision 3). Twelve, thirteen and
--     fourteen digits read as they would unhinted (a scanner that already expanded the UPC-E);
--     any other hinted input that is not six or eight digits is malformed.
--   - GS1 content whose element string has no AI (01) is not GTIN-shaped and reads as opaque, even
--     when it is all digits. An
--     element string whose AI (01) is not followed by exactly fourteen digits is malformed.
--   - A layout must contain at least one literal digit, because a layout with none would claim
--     every GTIN of its width as restricted.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per ADR-0008's
-- retirement.

SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

-- The GS1 mod-10 check digit of `body`, which is the code without its check digit. Weights run 3,
-- 1, 3, ... from the rightmost digit, so leading zeros do not change the result and one function
-- serves GTIN-8, -12, -13 and -14 and the 14-digit padded form of each.
CREATE OR REPLACE FUNCTION victual_gs1_check_digit(body TEXT) RETURNS INTEGER
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS $$
DECLARE
	n INTEGER := length(body);
	total INTEGER := 0;
BEGIN
	IF body !~ '^[0-9]+$' THEN
		RAISE EXCEPTION 'victual_gs1_check_digit: % is not a string of digits', quote_literal(body)
			USING ERRCODE = '22023';
	END IF;

	FOR i IN 1..n LOOP
		total := total + substr(body, n - i + 1, 1)::INTEGER * CASE WHEN i % 2 = 1 THEN 3 ELSE 1 END;
	END LOOP;

	RETURN (10 - total % 10) % 10;
END;
$$;

CREATE OR REPLACE FUNCTION victual_barcode_canonicalize(code TEXT, carrier TEXT, patterns JSONB)
RETURNS TABLE (identifier_type TEXT, canonical TEXT, pattern_id INTEGER, decoded_value NUMERIC, value_kind TEXT, status TEXT)
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
	gs CONSTANT TEXT := chr(29);
	upc_e_hint CONSTANT BOOLEAN := coalesce(carrier = 'upc_e', false);
	payload TEXT;
	element_string BOOLEAN := false;
	body TEXT;
	ai01 TEXT[];
	digits TEXT;
	padded TEXT;
	upc_e_data TEXT;
	upc_e_ns TEXT;
	upc_a_body TEXT;
	pattern JSONB;
	layout TEXT;
	verifier TEXT;
	value_count INTEGER;
	matches BOOLEAN;
	value_digits TEXT;
	masked TEXT;
	ch TEXT;
BEGIN
	-- Patterns are checked first, whatever the payload, so a caller error is never hidden by an
	-- input that happens not to reach the pattern step.
	IF patterns IS NOT NULL AND jsonb_typeof(patterns) <> 'array' THEN
		RAISE EXCEPTION 'victual_barcode_canonicalize: patterns must be a JSON array, not %', jsonb_typeof(patterns)
			USING ERRCODE = '22023';
	END IF;

	FOR pattern IN SELECT p FROM jsonb_array_elements(coalesce(patterns, '[]'::JSONB)) AS e(p) LOOP
		IF jsonb_typeof(pattern) <> 'object' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: a pattern must be a JSON object: %', pattern USING ERRCODE = '22023';
		END IF;

		IF jsonb_typeof(pattern->'id') IS DISTINCT FROM 'number' OR (pattern->>'id') !~ '^-?[0-9]+$' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: a pattern needs an integer id: %', pattern USING ERRCODE = '22023';
		END IF;

		layout := pattern->>'layout';
		IF jsonb_typeof(pattern->'layout') IS DISTINCT FROM 'string' OR layout !~ '^[0-9IPWVC]{11}C$|^[0-9IPWVC]{12}C$' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: layout must be 12 or 13 of 0-9 I P W V C, ending in its one C: %', pattern
				USING ERRCODE = '22023';
		END IF;
		IF strpos(left(layout, -1), 'C') > 0 OR layout !~ '[0-9]' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: layout needs one C, last, and at least one literal digit: %', pattern
				USING ERRCODE = '22023';
		END IF;

		IF pattern->>'value_kind' = 'price' THEN
			IF strpos(layout, 'P') = 0 OR strpos(layout, 'W') > 0 THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a price layout has P positions and no W: %', pattern USING ERRCODE = '22023';
			END IF;
			IF jsonb_typeof(pattern->'currency') IS DISTINCT FROM 'string' OR (pattern->>'currency') !~ '^[A-Z]{3}$' THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a price pattern needs an ISO 4217 currency: %', pattern USING ERRCODE = '22023';
			END IF;
			IF coalesce(jsonb_typeof(pattern->'weight_unit'), 'null') <> 'null' THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a price pattern has no weight_unit: %', pattern USING ERRCODE = '22023';
			END IF;
		ELSIF pattern->>'value_kind' = 'weight' THEN
			IF strpos(layout, 'W') = 0 OR strpos(layout, 'P') > 0 THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a weight layout has W positions and no P: %', pattern USING ERRCODE = '22023';
			END IF;
			IF (pattern->>'weight_unit') IS NULL OR (pattern->>'weight_unit') NOT IN ('g', 'kg', 'lb', 'oz') THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a weight pattern needs weight_unit g, kg, lb or oz: %', pattern USING ERRCODE = '22023';
			END IF;
			IF coalesce(jsonb_typeof(pattern->'currency'), 'null') <> 'null' THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: a weight pattern has no currency: %', pattern USING ERRCODE = '22023';
			END IF;
		ELSE
			RAISE EXCEPTION 'victual_barcode_canonicalize: value_kind must be price or weight: %', pattern USING ERRCODE = '22023';
		END IF;

		IF jsonb_typeof(pattern->'decimals') IS DISTINCT FROM 'number' OR (pattern->>'decimals') !~ '^[0-9]+$' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: decimals must be a non-negative integer: %', pattern USING ERRCODE = '22023';
		END IF;

		verifier := pattern->>'verifier';
		value_count := length(layout) - length(translate(layout, 'PW', ''));
		IF verifier = 'none' THEN
			IF strpos(layout, 'V') > 0 THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: verifier none has no V position: %', pattern USING ERRCODE = '22023';
			END IF;
		ELSIF verifier IN ('four_digit', 'five_digit') THEN
			IF length(layout) - length(replace(layout, 'V', '')) <> 1
				OR value_count <> (CASE verifier WHEN 'four_digit' THEN 4 ELSE 5 END) THEN
				RAISE EXCEPTION 'victual_barcode_canonicalize: verifier % needs one V and % value positions: %',
					verifier, (CASE verifier WHEN 'four_digit' THEN 4 ELSE 5 END), pattern USING ERRCODE = '22023';
			END IF;
		ELSE
			RAISE EXCEPTION 'victual_barcode_canonicalize: verifier must be none, four_digit or five_digit: %', pattern
				USING ERRCODE = '22023';
		END IF;
	END LOOP;

	-- Step 1: malformed input. Every output column but status stays NULL.
	payload := btrim(code, ' ');
	IF payload IS NULL OR payload = '' OR octet_length(code) > 256 OR replace(code, gs, '') ~ '[[:cntrl:]]' THEN
		status := 'malformed';
		RETURN NEXT;
		RETURN;
	END IF;

	-- Step 2: an AIM symbology identifier, `]` plus two characters. Four of them mark GS1 content.
	IF payload ~ '^\][A-Za-z][0-9A-Za-z]' THEN
		element_string := substr(payload, 1, 3) IN (']C1', ']e0', ']d2', ']Q3');
		payload := btrim(substr(payload, 4), ' ');
		IF payload = '' THEN
			status := 'malformed';
			RETURN NEXT;
			RETURN;
		END IF;
	END IF;

	-- Step 3: the GTIN of a GS1 element string, the fourteen digits after AI (01). A leading GS is
	-- FNC1. In the bracketed human-readable form AI (01) may come anywhere; in the raw form its
	-- length is not delimited, so it is read only where element strings put it, first.
	element_string := element_string OR payload LIKE '(01)%' OR left(payload, 1) = gs;
	IF element_string THEN
		body := ltrim(payload, gs);
		IF left(body, 1) = '(' THEN
			ai01 := regexp_match(body, '\(01\)([^(]*)');
		ELSIF left(body, 2) = '01' THEN
			ai01 := ARRAY[substr(body, 3, 14)];
		END IF;

		IF ai01 IS NOT NULL THEN
			IF ai01[1] !~ '^[0-9]{14}$' THEN
				status := 'malformed';
				RETURN NEXT;
				RETURN;
			END IF;
			digits := ai01[1];
		END IF;
	END IF;

	-- Step 4: UPC-E, only with the hint (ADR-0031 decision 4). The expansion is plan 34's table.
	IF upc_e_hint AND digits IS NULL THEN
		IF payload !~ '^[0-9]+$' OR length(payload) NOT IN (6, 8, 12, 13, 14) THEN
			status := 'malformed';
			RETURN NEXT;
			RETURN;
		END IF;

		IF length(payload) IN (6, 8) THEN
			IF length(payload) = 6 THEN
				upc_e_ns := '0';
				upc_e_data := payload;
			ELSE
				upc_e_ns := left(payload, 1);
				upc_e_data := substr(payload, 2, 6);
				IF upc_e_ns NOT IN ('0', '1') THEN
					status := 'malformed';
					RETURN NEXT;
					RETURN;
				END IF;
			END IF;

			upc_a_body := upc_e_ns || CASE
				WHEN substr(upc_e_data, 6, 1) IN ('0', '1', '2') THEN
					substr(upc_e_data, 1, 2) || substr(upc_e_data, 6, 1) || '0000' || substr(upc_e_data, 3, 3)
				WHEN substr(upc_e_data, 6, 1) = '3' THEN
					substr(upc_e_data, 1, 3) || '00000' || substr(upc_e_data, 4, 2)
				WHEN substr(upc_e_data, 6, 1) = '4' THEN
					substr(upc_e_data, 1, 4) || '00000' || substr(upc_e_data, 5, 1)
				ELSE
					substr(upc_e_data, 1, 5) || '0000' || substr(upc_e_data, 6, 1)
			END;

			-- Six digits carry no check digit, so it is computed; eight carry one, which step 5
			-- validates against the expanded UPC-A.
			digits := upc_a_body || CASE WHEN length(payload) = 6
				THEN victual_gs1_check_digit(upc_a_body)::TEXT
				ELSE right(payload, 1) END;
		END IF;
	END IF;

	-- Step 5: GTIN-shaped input and its check digit. A failure is never reclassified as opaque.
	-- GS1 content is an element string, never a bare GTIN, so only AI (01) makes it GTIN-shaped.
	IF digits IS NULL AND NOT element_string AND payload ~ '^[0-9]+$' AND length(payload) IN (8, 12, 13, 14) THEN
		digits := payload;
	END IF;

	IF digits IS NULL THEN
		-- Step 7, the other half: anything that is not GTIN-shaped. Case is kept.
		identifier_type := 'opaque';
		canonical := payload;
		status := 'ok';
		RETURN NEXT;
		RETURN;
	END IF;

	padded := lpad(digits, 14, '0');
	IF right(padded, 1)::INTEGER <> victual_gs1_check_digit(left(padded, 13)) THEN
		identifier_type := 'gtin';
		canonical := padded;
		status := 'invalid_check_digit';
		RETURN NEXT;
		RETURN;
	END IF;

	-- Step 6: restricted-circulation patterns, matched on the 14-digit form. The layout is padded
	-- with literal zeros the way the code is, so a 12-digit sticker and its 13-digit leading-zero
	-- reading match at the same positions.
	FOR pattern IN SELECT p FROM jsonb_array_elements(coalesce(patterns, '[]'::JSONB)) AS e(p) LOOP
		layout := lpad(pattern->>'layout', 14, '0');
		matches := true;
		value_digits := '';
		masked := '';

		FOR i IN 1..14 LOOP
			ch := substr(layout, i, 1);
			IF ch ~ '[0-9]' THEN
				IF ch <> substr(padded, i, 1) THEN
					matches := false;
					EXIT;
				END IF;
				masked := masked || ch;
			ELSIF ch = 'I' THEN
				masked := masked || substr(padded, i, 1);
			ELSE
				IF ch IN ('P', 'W') THEN
					value_digits := value_digits || substr(padded, i, 1);
				END IF;
				masked := masked || '0';
			END IF;
		END LOOP;

		CONTINUE WHEN NOT matches;

		IF pattern->>'verifier' <> 'none' THEN
			RAISE EXCEPTION 'victual_barcode_canonicalize: the % price/weight verifier of pattern % is not implemented (ADR-0031 acceptance prerequisite 2)',
				pattern->>'verifier', pattern->>'id' USING ERRCODE = '0A000';
		END IF;

		identifier_type := 'restricted';
		canonical := masked;
		pattern_id := (pattern->>'id')::INTEGER;
		decoded_value := round(value_digits::NUMERIC / (10::NUMERIC ^ (pattern->>'decimals')::INTEGER), (pattern->>'decimals')::INTEGER);
		value_kind := pattern->>'value_kind';
		status := 'ok';
		RETURN NEXT;
	END LOOP;

	-- Step 7: the non-pattern reading, always last.
	identifier_type := 'gtin';
	canonical := padded;
	pattern_id := NULL;
	decoded_value := NULL;
	value_kind := NULL;
	status := 'ok';
	RETURN NEXT;
END;
$$;
