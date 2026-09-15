-- Fixes issue #148: enfore_product_nesting_level (name/typo preserved from upstream) is
-- declared BEFORE UPDATE ON products only, in both migrations/0121.sql (carried unchanged
-- through 0155.sql, 0207.sql, 0254.sql) and its PostgreSQL port
-- db/pgsql/baseline/06_triggers_a.sql:760-776. A row INSERTed with parent_product_id already
-- set is never checked by either engine. This migration is PostgreSQL-only: those files sit
-- below DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID (0265) and never execute anywhere
-- any more (db/pgsql/README.md), so there is no SQLite counterpart to write and none of
-- migrations/0121.sql, 0130.sql, 0155.sql, 0207.sql or 0254.sql is edited - the baseline
-- itself is deliberately not touched either, for the reason migrations/0261.pgsql.sql gives:
-- it is the state SQLite reaches after migrations 0001-0255, and a fresh PostgreSQL database
-- loads it and then runs 0256 onwards, this file included.
--
-- THE ORIGINAL CHECK IS ALSO ONE-DIRECTIONAL, WHICH INSERT ALONE DOES NOT FIX. The predicate
-- - "EXISTS(SELECT 1 FROM products p WHERE p.parent_product_id = NEW.id) AND NEW.parent_product_id
-- IS NOT NULL" - only rejects a row that is BEING GIVEN a parent while something ALREADY
-- points at it as a parent. That only fires when the *middle* node of a would-be three-level
-- chain is the row being written. Given Protein(3) -> Beef(4) -> seven cuts, the ADR-0023
-- prerequisite-4 backup shows exactly this: inserting a cut with parent_product_id = Beef,
-- where Beef already has parent_product_id = Protein, is never caught by the original
-- predicate even with BEFORE INSERT added, because it only inspects the cut's own children
-- (none - it is new) and never asks whether the parent it names is itself already nested.
-- Simply adding BEFORE INSERT to the unchanged trigger body would therefore leave the exact
-- scenario the issue found still possible via a fresh INSERT in parent-then-child order. The
-- function below checks both directions: NEW.parent_product_id may not itself already have a
-- parent (stops the chain arriving from below), and NEW may not already be somebody else's
-- parent while also being given one (the original check, now evaluated on INSERT too).
--
-- Folded into one trigger firing on both events, as the issue suggests SQLite and PostgreSQL
-- both support - simpler than two near-identical trigger bodies for a single-purpose guard.

-- Null out any existing multi-level chain first, the same way migrations/0130.sql once did,
-- so the trigger below is not immediately handed rows it would refuse to let back through on
-- an unrelated UPDATE. Whichever product is the "middle" of a chain - has both a
-- parent_product_id and something else pointing at it - has that parent link cleared; the
-- children stay attached to the (now root) middle product, which keeps the one level of
-- nesting the schema supports rather than orphaning them too.
UPDATE products
SET parent_product_id = NULL
WHERE id IN (
		SELECT p_middle.id
		FROM products p_middle
		JOIN products p_child
			ON p_child.parent_product_id = p_middle.id
		WHERE p_middle.parent_product_id IS NOT NULL
	)
	AND parent_product_id IS NOT NULL;

DROP TRIGGER IF EXISTS enfore_product_nesting_level ON products;
CREATE OR REPLACE FUNCTION trg_enfore_product_nesting_level() RETURNS TRIGGER AS $$
BEGIN
	-- Currently only 1 level is supported: a product may not both have a parent and be one.
	IF NEW.parent_product_id IS NOT NULL THEN
		-- NEW would arrive as a grandchild: the product it names as its parent is itself
		-- already nested under something else.
		IF EXISTS(
			SELECT 1
			FROM products
			WHERE id = NEW.parent_product_id
				AND parent_product_id IS NOT NULL
		) THEN
			RAISE EXCEPTION 'Unsupported product nesting level detected (currently only 1 level is supported)';
		END IF;

		-- NEW would become a middle node: something already treats NEW itself as its parent.
		IF EXISTS(
			SELECT 1
			FROM products p
			WHERE p.parent_product_id = NEW.id
		) THEN
			RAISE EXCEPTION 'Unsupported product nesting level detected (currently only 1 level is supported)';
		END IF;
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enfore_product_nesting_level BEFORE INSERT OR UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_enfore_product_nesting_level();
