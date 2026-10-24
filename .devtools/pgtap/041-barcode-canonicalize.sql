-- migrations/0319.pgsql.sql (issue #1104): victual_barcode_canonicalize and victual_gs1_check_digit,
-- ADR-0031 (Proposed) acceptance prerequisite 1, against plan 34's canonicalisation steps. Nothing
-- in the application calls either function yet.
--
-- pg_temp.r() renders every row the function returns as
-- identifier_type|canonical|pattern_id|decoded_value|value_kind|status, rows joined by ';' in the
-- order returned, so one assertion pins both the readings and how many there are.
--
-- The whole file runs in one transaction and rolls back. Each refusal runs in its own savepoint
-- (throws_ok does that), so the cases do not abort one another.

BEGIN;

SELECT plan(126);

CREATE FUNCTION pg_temp.r(code TEXT, carrier TEXT DEFAULT NULL, patterns JSONB DEFAULT NULL) RETURNS TEXT
LANGUAGE sql AS $$
	SELECT string_agg(format('%s|%s|%s|%s|%s|%s', identifier_type, canonical, pattern_id, decoded_value, value_kind, status), ';' ORDER BY n)
	FROM victual_barcode_canonicalize(code, carrier, patterns) WITH ORDINALITY AS t(identifier_type, canonical, pattern_id, decoded_value, value_kind, status, n)
$$;

-- The US random-weight price layout (plan 34), a 13-digit weight layout, and the two verifier
-- layouts, which this migration refuses to decode (prerequisite 2).
CREATE FUNCTION pg_temp.us() RETURNS JSONB LANGUAGE sql AS
$$ SELECT '{"id": 1, "layout": "2IIIIIPPPPPC", "value_kind": "price", "decimals": 2, "currency": "USD", "weight_unit": null, "verifier": "none"}'::JSONB $$;
CREATE FUNCTION pg_temp.kg() RETURNS JSONB LANGUAGE sql AS
$$ SELECT '{"id": 2, "layout": "21IIIIIWWWWWC", "value_kind": "weight", "decimals": 3, "weight_unit": "kg", "verifier": "none"}'::JSONB $$;
CREATE FUNCTION pg_temp.v4() RETURNS JSONB LANGUAGE sql AS
$$ SELECT '{"id": 3, "layout": "2IIIIIVPPPPC", "value_kind": "price", "decimals": 2, "currency": "EUR", "verifier": "four_digit"}'::JSONB $$;
CREATE FUNCTION pg_temp.v5() RETURNS JSONB LANGUAGE sql AS
$$ SELECT '{"id": 4, "layout": "2IIIIIVPPPPPC", "value_kind": "price", "decimals": 2, "currency": "EUR", "verifier": "five_digit"}'::JSONB $$;

-- The check digit ---------------------------------------------------------------------------------

SELECT is(victual_gs1_check_digit('01234567890'), 5, 'check digit: UPC-A 01234567890 -> 5');
SELECT is(victual_gs1_check_digit('0001234567890'), 5, 'check digit: leading zeros do not change it');
SELECT is(victual_gs1_check_digit('9638507'), 4, 'check digit: EAN-8 9638507 -> 4');
SELECT throws_ok($$SELECT victual_gs1_check_digit('12a4')$$, '22023', NULL, 'check digit: a non-digit body is refused');

-- Every GTIN width ----------------------------------------------------------------------------------

SELECT is(pg_temp.r('96385074'), 'gtin|00000096385074||||ok', 'GTIN-8 pads to 14 digits');
SELECT is(pg_temp.r('012345678905'), 'gtin|00012345678905||||ok', 'GTIN-12 (UPC-A) pads to 14 digits');
SELECT is(pg_temp.r('0012345678905'), 'gtin|00012345678905||||ok', 'GTIN-13 with a leading zero reads as the same UPC-A (issue 481)');
SELECT is(pg_temp.r('4006381333931'), 'gtin|04006381333931||||ok', 'GTIN-13 pads to 14 digits');
SELECT is(pg_temp.r('10012345678902'), 'gtin|10012345678902||||ok', 'GTIN-14 is kept as it is');
SELECT is(pg_temp.r(' 012345678905 '), 'gtin|00012345678905||||ok', 'surrounding spaces are trimmed before classification');
SELECT is(pg_temp.r('012345678905', 'ean_upc'), 'gtin|00012345678905||||ok', 'a carrier other than upc_e changes nothing');

-- UPC-E: only with the hint, one case per row of the expansion table ---------------------------------

SELECT is(pg_temp.r('06543208', 'upc_e'), 'gtin|00065000004328||||ok', 'UPC-E 8 digits, d6 = 0');
SELECT is(pg_temp.r('01123423', 'upc_e'), 'gtin|00011200002343||||ok', 'UPC-E 8 digits, d6 = 2');
SELECT is(pg_temp.r('01234531', 'upc_e'), 'gtin|00012300000451||||ok', 'UPC-E 8 digits, d6 = 3');
SELECT is(pg_temp.r('12345649', 'upc_e'), 'gtin|00123450000069||||ok', 'UPC-E 8 digits, d6 = 4, number system 1');
SELECT is(pg_temp.r('01256758', 'upc_e'), 'gtin|00012567000058||||ok', 'UPC-E 8 digits, d6 = 8');
SELECT is(pg_temp.r('01234565', 'upc_e'), 'gtin|00012345000065||||ok', 'UPC-E 8 digits, d6 = 6');
SELECT is(pg_temp.r('123456', 'upc_e'), 'gtin|00012345000065||||ok', 'UPC-E 6 digits take number system 0 and a computed check digit');
SELECT is(pg_temp.r('654320', 'upc_e'), 'gtin|00065000004328||||ok', 'UPC-E 6 digits, d6 = 0');
SELECT is(pg_temp.r('01234565'), 'gtin|00000001234565||||ok', 'an unhinted 8-digit value is always GTIN-8 (ADR-0031 decision 4)');
SELECT is(pg_temp.r('123456'), 'opaque|123456||||ok', 'an unhinted 6-digit value is not GTIN-shaped');
SELECT is(pg_temp.r('0123456', 'upc_e'), '|||||malformed', 'a hinted 7-digit value is malformed');
SELECT is(pg_temp.r('21234565', 'upc_e'), '|||||malformed', 'a hinted 8-digit value with number system 2 is malformed');
SELECT is(pg_temp.r('12345', 'upc_e'), '|||||malformed', 'a hinted 5-digit value is malformed');
SELECT is(pg_temp.r('ABCDEF', 'upc_e'), '|||||malformed', 'hinted input is GTIN-shaped, so non-digits are malformed, not opaque');
SELECT is(pg_temp.r('012345678905', 'upc_e'), 'gtin|00012345678905||||ok', 'a hinted 12-digit value (an expanded UPC-E) reads as UPC-A');
SELECT is(pg_temp.r('01234566', 'upc_e'), 'gtin|00012345000066||||invalid_check_digit', 'a hinted UPC-E check digit is validated against the expanded UPC-A');

-- AIM symbology identifiers and AI (01) element strings ------------------------------------------------

SELECT is(pg_temp.r(']E0' || '0012345678905'), 'gtin|00012345678905||||ok', ']E0 is removed');
SELECT is(pg_temp.r(']E4' || '96385074'), 'gtin|00000096385074||||ok', ']E4 is removed');
SELECT is(pg_temp.r(']C1' || '0100012345678905'), 'gtin|00012345678905||||ok', ']C1: the GTIN of AI (01)');
SELECT is(pg_temp.r(']C1' || '0100012345678905' || '17261231' || '10ABC'), 'gtin|00012345678905||||ok', ']C1: the rest of the element string is ignored');
SELECT is(pg_temp.r(']d2' || '0100012345678905' || '10ABC' || chr(29) || '17261231'), 'gtin|00012345678905||||ok', ']d2 with a GS separator');
SELECT is(pg_temp.r(']Q3' || '0100012345678905'), 'gtin|00012345678905||||ok', ']Q3 marks GS1 content');
SELECT is(pg_temp.r(']e0' || '0100012345678905'), 'gtin|00012345678905||||ok', ']e0 marks GS1 content');
SELECT is(pg_temp.r('(01)00012345678905(17)261231'), 'gtin|00012345678905||||ok', '(01)...(17)... in the bracketed form');
SELECT is(pg_temp.r('(01)00012345678905'), 'gtin|00012345678905||||ok', '(01) alone');
SELECT is(pg_temp.r(']C1' || '(17)261231(01)00012345678905'), 'gtin|00012345678905||||ok', 'bracketed GS1 content with (01) after another AI');
SELECT is(pg_temp.r(chr(29) || '0100012345678905' || '17261231'), 'gtin|00012345678905||||ok', 'a leading FNC1 (GS) starts an element string');
SELECT is(pg_temp.r('(01)123'), '|||||malformed', '(01) without fourteen digits is malformed');
SELECT is(pg_temp.r('(01)000123456789050'), '|||||malformed', '(01) with fifteen digits is malformed');
SELECT is(pg_temp.r(']C1' || '0112345'), '|||||malformed', 'raw AI 01 without fourteen digits is malformed');
SELECT is(pg_temp.r(']C1' || '00123456789012345675'), 'opaque|00123456789012345675||||ok', 'GS1 content without AI (01), an SSCC, is opaque');
SELECT is(pg_temp.r(']C1' || '96385074'), 'opaque|96385074||||ok', 'GS1 content is never a bare GTIN, even when it is eight digits');
SELECT is(pg_temp.r(']A0' || 'ABC'), 'opaque|ABC||||ok', 'the AIM identifier is not part of an opaque value');
SELECT is(pg_temp.r(']A0'), '|||||malformed', 'an AIM identifier with nothing after it is malformed');

-- Check-digit failures: gtin with status invalid_check_digit, never opaque ---------------------------

SELECT is(pg_temp.r('96385075'), 'gtin|00000096385075||||invalid_check_digit', 'GTIN-8 with a wrong check digit');
SELECT is(pg_temp.r('012345678906'), 'gtin|00012345678906||||invalid_check_digit', 'GTIN-12 with a wrong check digit');
SELECT is(pg_temp.r('0012345678906'), 'gtin|00012345678906||||invalid_check_digit', 'its 13-digit form names the same identity');
SELECT is(pg_temp.r('4006381333932'), 'gtin|04006381333932||||invalid_check_digit', 'GTIN-13 with a wrong check digit');
SELECT is(pg_temp.r('10012345678903'), 'gtin|10012345678903||||invalid_check_digit', 'GTIN-14 with a wrong check digit');
SELECT is(pg_temp.r('(01)00012345678906'), 'gtin|00012345678906||||invalid_check_digit', 'an AI (01) GTIN with a wrong check digit');
SELECT is(pg_temp.r(']E0' || '0012345678906'), 'gtin|00012345678906||||invalid_check_digit', 'an AIM-prefixed GTIN with a wrong check digit');
SELECT is(
	(SELECT count(*) FROM unnest(ARRAY['96385075', '012345678906', '4006381333932', '10012345678903', '(01)00012345678906', '201234012994']) AS c,
		LATERAL victual_barcode_canonicalize(c, NULL, jsonb_build_array(pg_temp.us())) AS t
	 WHERE t.identifier_type = 'opaque' OR t.status <> 'invalid_check_digit'),
	0::BIGINT, 'no check-digit failure is read as opaque, with or without patterns');

-- Opaque and malformed input -----------------------------------------------------------------------

SELECT is(pg_temp.r('ABC-123'), 'opaque|ABC-123||||ok', 'a Code 39 payload is opaque');
SELECT is(pg_temp.r('abc-123'), 'opaque|abc-123||||ok', 'case is kept');
SELECT is(pg_temp.r('  ABC-123 '), 'opaque|ABC-123||||ok', 'surrounding spaces are trimmed');
SELECT is(pg_temp.r('12345'), 'opaque|12345||||ok', 'digits of a width no GTIN has are opaque');
SELECT is(pg_temp.r('A' || chr(29) || 'B'), 'opaque|A' || chr(29) || 'B||||ok', 'GS is allowed and kept');
SELECT is(pg_temp.r(repeat('A', 256)), 'opaque|' || repeat('A', 256) || '||||ok', '256 bytes is allowed');
SELECT is(pg_temp.r(repeat('A', 257)), '|||||malformed', 'more than 256 bytes is malformed');
SELECT is(pg_temp.r(repeat('é', 129)), '|||||malformed', 'the limit counts bytes, not characters');
SELECT is(pg_temp.r(''), '|||||malformed', 'an empty value is malformed');
SELECT is(pg_temp.r('   '), '|||||malformed', 'a value of spaces is malformed');
SELECT is(pg_temp.r(NULL), '|||||malformed', 'NULL is malformed');
SELECT is(pg_temp.r(E'012345678905\n'), '|||||malformed', 'a trailing LF is a control character');
SELECT is(pg_temp.r(E'\t012345678905'), '|||||malformed', 'a tab is a control character');
SELECT is(pg_temp.r(E'ABC\r'), '|||||malformed', 'a CR is a control character');

-- Restricted-circulation patterns ------------------------------------------------------------------

SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us())),
	'restricted|00201234000000|1|12.99|price|ok;gtin|00201234012993||||ok',
	'a 12-digit sticker: the restricted reading, then the non-pattern reading');
SELECT is(pg_temp.r('0201234012993', NULL, jsonb_build_array(pg_temp.us())),
	'restricted|00201234000000|1|12.99|price|ok;gtin|00201234012993||||ok',
	'its 13-digit leading-zero reading matches the same layout and shares the canonical value');
SELECT is(pg_temp.r('201234003496', NULL, jsonb_build_array(pg_temp.us())),
	'restricted|00201234000000|1|3.49|price|ok;gtin|00201234003496||||ok',
	'another sticker for the same item shares the canonical value, with its own price');
SELECT is(pg_temp.r('205678012991', NULL, jsonb_build_array(pg_temp.us())),
	'restricted|00205678000000|1|12.99|price|ok;gtin|00205678012991||||ok',
	'another item reference gives another canonical value');
SELECT is(pg_temp.r('2112345012506', NULL, jsonb_build_array(pg_temp.kg())),
	'restricted|02112345000000|2|1.250|weight|ok;gtin|02112345012506||||ok',
	'a 13-digit weight layout: W positions masked, value_kind weight, three decimals');
SELECT is(pg_temp.r('012345678905', NULL, jsonb_build_array(pg_temp.us())), 'gtin|00012345678905||||ok',
	'layout: a literal prefix mismatch leaves a GTIN');
SELECT is(pg_temp.r('2012340129905', NULL, jsonb_build_array(pg_temp.us())), 'gtin|02012340129905||||ok',
	'layout: a 12-digit layout does not match a 13-digit code that starts with 2');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.kg())), 'gtin|00201234012993||||ok',
	'layout: a 13-digit layout does not match a 12-digit sticker');
SELECT is(pg_temp.r('96385074', NULL, jsonb_build_array(pg_temp.us(), pg_temp.kg())), 'gtin|00000096385074||||ok',
	'layout: no 12- or 13-digit layout matches a GTIN-8');
SELECT is(pg_temp.r('201234012994', NULL, jsonb_build_array(pg_temp.us())), 'gtin|00201234012994||||invalid_check_digit',
	'a sticker with a wrong check digit is refused before any pattern is matched');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us() || '{"decimals": 0}')),
	'restricted|00201234000000|1|1299|price|ok;gtin|00201234012993||||ok', 'decimals: 0 decimals read the value digits whole');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us() || '{"decimals": 4}')),
	'restricted|00201234000000|1|0.1299|price|ok;gtin|00201234012993||||ok', 'decimals: 4 decimals');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us() || '{"id": 77}')),
	'restricted|00201234000000|77|12.99|price|ok;gtin|00201234012993||||ok', 'id: the matched pattern is named by its id');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us() || '{"currency": "CAD"}')),
	pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us())), 'currency: not part of the reading, so CAD reads as USD does');
SELECT is(pg_temp.r('2112345012506', NULL, jsonb_build_array(pg_temp.kg() || '{"weight_unit": "lb"}')),
	pg_temp.r('2112345012506', NULL, jsonb_build_array(pg_temp.kg())), 'weight_unit: not part of the reading, so lb reads as kg does');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us() || '{"supersedes_pattern_id": 9}')),
	pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.us())), 'supersedes_pattern_id: not part of the reading');
SELECT is(pg_temp.r('201234012993', NULL, jsonb_build_array(pg_temp.kg(), pg_temp.us(), pg_temp.us() || '{"id": 5, "decimals": 0}')),
	'restricted|00201234000000|1|12.99|price|ok;restricted|00201234000000|5|1299|price|ok;gtin|00201234012993||||ok',
	'one row per matching pattern, in the order given, then the non-pattern reading');
SELECT is(pg_temp.r('201234012993', NULL, '[]'), 'gtin|00201234012993||||ok', 'an empty pattern list gives the GTIN reading alone');
SELECT is(pg_temp.r('201234012993'), 'gtin|00201234012993||||ok', 'no patterns gives the GTIN reading alone');
SELECT is(pg_temp.r('ABC', NULL, jsonb_build_array(pg_temp.us())), 'opaque|ABC||||ok', 'patterns do not apply to opaque input');

-- verifier: none decodes (above); four_digit and five_digit are refused where they match (prerequisite 2)
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('201234512998', NULL, jsonb_build_array(pg_temp.v4()))$$,
	'0A000', NULL, 'verifier: a matching four_digit pattern is refused as not implemented');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('2012345129993', NULL, jsonb_build_array(pg_temp.v5()))$$,
	'0A000', NULL, 'verifier: a matching five_digit pattern is refused as not implemented');
SELECT is(pg_temp.r('012345678905', NULL, jsonb_build_array(pg_temp.v4(), pg_temp.v5())), 'gtin|00012345678905||||ok',
	'verifier: verifier patterns that do not match are not refused');

-- Patterns whose fields disagree are a caller error (22023), whatever the code
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, '{}')$$, '22023', NULL, 'patterns must be an array');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, '[1]')$$, '22023', NULL, 'a pattern must be an object');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('ABC', NULL, jsonb_build_array(pg_temp.us() - 'id'))$$, '22023', NULL,
	'id: required, even when the code is opaque');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize(NULL, NULL, jsonb_build_array(pg_temp.us() || '{"id": "1"}'))$$, '22023', NULL,
	'id: a string is refused, even when the code is malformed');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"id": 1.5}'))$$, '22023', NULL,
	'id: a fraction is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIPPPPPC"}'))$$, '22023', NULL,
	'layout: 11 positions are refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIIPPPPPPPC"}'))$$, '22023', NULL,
	'layout: 14 positions are refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIXPPPPPC"}'))$$, '22023', NULL,
	'layout: an unknown position letter is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIIPPPPP1"}'))$$, '22023', NULL,
	'layout: a layout must end in C');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIICPPPPPC"}'))$$, '22023', NULL,
	'layout: a second C is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "IIIIIIPPPPPC"}'))$$, '22023', NULL,
	'layout: a layout with no literal digit is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": 2}'))$$, '22023', NULL,
	'layout: a number is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"value_kind": "count"}'))$$, '22023', NULL,
	'value_kind: only price or weight');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIIWWWWWC"}'))$$, '22023', NULL,
	'value_kind: a price layout with W positions is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"layout": "2IIIIPPPWWWC"}'))$$, '22023', NULL,
	'value_kind: a layout with both P and W is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.kg() || '{"layout": "21IIIIIPPPPPC"}'))$$, '22023', NULL,
	'value_kind: a weight layout with P positions is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() - 'currency'))$$, '22023', NULL,
	'currency: required for price');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"currency": "usd"}'))$$, '22023', NULL,
	'currency: three upper-case letters');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"weight_unit": "kg"}'))$$, '22023', NULL,
	'weight_unit: a price pattern has none');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.kg() - 'weight_unit'))$$, '22023', NULL,
	'weight_unit: required for weight');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.kg() || '{"weight_unit": "stone"}'))$$, '22023', NULL,
	'weight_unit: only g, kg, lb or oz');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.kg() || '{"currency": "EUR"}'))$$, '22023', NULL,
	'currency: a weight pattern has none');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() - 'decimals'))$$, '22023', NULL,
	'decimals: required');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"decimals": -1}'))$$, '22023', NULL,
	'decimals: not negative');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"decimals": 1.5}'))$$, '22023', NULL,
	'decimals: an integer');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"decimals": "2"}'))$$, '22023', NULL,
	'decimals: a number, not a string');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() - 'verifier'))$$, '22023', NULL,
	'verifier: required');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"verifier": "luhn"}'))$$, '22023', NULL,
	'verifier: only none, four_digit or five_digit');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.v4() || '{"verifier": "none"}'))$$, '22023', NULL,
	'verifier: none with a V position is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.us() || '{"verifier": "five_digit"}'))$$, '22023', NULL,
	'verifier: five_digit without a V position is refused');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.v4() || '{"verifier": "five_digit"}'))$$, '22023', NULL,
	'verifier: five_digit needs five value positions');
SELECT throws_ok($$SELECT * FROM victual_barcode_canonicalize('012345678905', NULL, jsonb_build_array(pg_temp.v5() || '{"verifier": "four_digit"}'))$$, '22023', NULL,
	'verifier: four_digit needs four value positions');

-- IMMUTABLE, PARALLEL SAFE, and no table read ---------------------------------------------------------

SELECT is(
	(SELECT string_agg(format('%s:%s:%s:%s', proname, provolatile, proparallel, prosecdef), ',' ORDER BY proname)
	 FROM pg_proc WHERE proname IN ('victual_barcode_canonicalize', 'victual_gs1_check_digit')),
	'victual_barcode_canonicalize:i:s:f,victual_gs1_check_digit:i:s:f',
	'both functions are IMMUTABLE, PARALLEL SAFE and SECURITY INVOKER, and each exists once');

-- pg_stat_xact_user_tables counts the scans this transaction has made of every table outside the
-- system schemas. Temporary tables are left out: pgTAP keeps its own state in them.
CREATE FUNCTION pg_temp.scans() RETURNS BIGINT LANGUAGE sql AS $$
	SELECT coalesce(sum(seq_scan + coalesce(idx_scan, 0)), 0)::BIGINT FROM pg_stat_xact_user_tables WHERE schemaname NOT LIKE 'pg_temp%'
$$;

SELECT pg_temp.scans() AS scans_start \gset
SELECT count(*) AS products_read FROM products \gset
SELECT pg_temp.scans() AS scans_control \gset
SELECT cmp_ok(:scans_control::BIGINT, '>', :scans_start::BIGINT, 'the scan counter sees a table read made in this transaction (control)');

SELECT count(*) AS readings FROM (VALUES
	('96385074', NULL), ('0012345678905', NULL), ('10012345678902', NULL), ('012345678906', NULL),
	('06543208', 'upc_e'), ('123456', 'upc_e'), ('0123456', 'upc_e'), (']C1' || '0100012345678905', NULL),
	('(01)00012345678905(17)261231', NULL), ('ABC-123', NULL), ('', NULL), ('201234012993', NULL),
	('0201234012993', NULL), ('2112345012506', NULL), ('201234012994', NULL)
) AS v(code, carrier), LATERAL victual_barcode_canonicalize(v.code, v.carrier, jsonb_build_array(pg_temp.us(), pg_temp.kg())) \gset
SELECT pg_temp.scans() AS scans_after \gset
SELECT is(:readings::INTEGER, 18, 'the no-table-read sample ran through every step');
SELECT is(:scans_after::BIGINT - :scans_control::BIGINT, 0::BIGINT, 'canonicalising every kind of input read no table');

SELECT * FROM finish();

ROLLBACK;
