-- Nested product groups: the catalogue's tree of kinds -- Spices / Garlic / Fresh, Dairy /
-- Cheese, Drinks / Soda / Coca-Cola -- lives in product_groups, so browsing, reporting and
-- grouping work at every level without any of it touching stock. See
-- docs/plans/landed/30-nested-product-groups.md, docs/adr/0023-taxonomy-is-groups-packaging-is-parent-product.md
-- (accepted 2026-09-14) and issue 124.
--
-- This is migrations/0273.pgsql.sql with the nouns changed, per plan 30's own instruction to
-- read that file first and treat its comments as the specification. Three things are copied
-- rather than rederived, and are worth naming once here instead of repeating that file's
-- reasoning in full:
--
--   1. UNIQUE NULLS NOT DISTINCT (parent_product_group_id, name), for the identical reason:
--      product_groups.name has been globally UNIQUE since the baseline, and the worked spice
--      tree breaks that directly -- "Dried" is a name under both Parsley and Garlic. Without
--      NULLS NOT DISTINCT the rule would hold everywhere except among root groups, where two
--      "Spices" roots would be accepted. This fork's minimum is already PostgreSQL 15 (plan
--      08), so nothing here moves that line.
--   2. hierarchy_depth_limit() is reused, not recreated -- migrations/0273.pgsql.sql wrote it
--      generic in anticipation of plan 07 being its second consumer; ADR-0023 retired that
--      plan in favour of this one, so this is the second consumer the function was written
--      for, still returning 6. The observed tree (the worked spice example) reaches three
--      levels, comfortably inside it.
--   3. The advisory lock before the read, and the guard function staying VOLATILE. Plan 08
--      measured, on PostgreSQL 16.13, that two concurrent re-parentings each read the tree
--      before the other wrote and both committed a cycle that emptied both rows out of the
--      resolved view -- and that the lock alone is half a fix, because a statement blocked on
--      it took its snapshot before the transaction it waited for committed: marked STABLE the
--      guard accepted exactly the cycle the lock exists to prevent. Both fixes carry over
--      unchanged; only the lock's key argument changes, to this migration's own number.
--
-- WHAT IS NOT COPIED. Locations needed parent_location_id to sit beside an existing
-- is_freezer flag and a storage_class_id column that derive one another on write; groups have
-- no such derived column, so there is nothing here matching WithDerivedIsFreezer(). Groups
-- also carry no per-row flag equivalent to is_freezer's inheritance question -- ADR-0023
-- decision 6 says a group may hold both products and subgroups at once, and that needs no
-- special case in this schema: product_group_id and parent_product_group_id are independent
-- foreign columns, so a row can be non-NULL on both counts (a product's own product_group_id
-- pointing at this group, and something else's parent_product_group_id pointing at it too)
-- with nothing here to reconcile.
--
-- WHY THE BASELINE IS NOT EDITED, AND WHY NO ENGINE_EXCLUSIVE_TABLES ENTRY IS NEEDED: the same
-- two reasons migrations/0273.pgsql.sql gives, which restate migrations/0261.pgsql.sql and
-- migrations/0268.pgsql.sql respectively. The baseline is the state SQLite reaches after
-- migrations 0001-0255, and a fresh PostgreSQL database loads it and then runs 0256 onward,
-- this file included -- editing both would apply this twice. And migratedifftest.php enumerates
-- BASE TABLE only and compares the intersection of the two engines' columns, so a
-- PostgreSQL-only column on a shared table and a PostgreSQL-only view are already invisible to
-- it; the marker is for a lone file below the SQLite freeze, which this is not.
--
-- The delete guard is the first BEFORE DELETE trigger product_groups has ever carried --
-- unlike locations, nothing else fires on deleting one -- so there is no ordering concern with
-- a sibling trigger the way guard_location_children has with retire_location_labels.

ALTER TABLE product_groups ADD COLUMN parent_product_group_id INTEGER;

ALTER TABLE product_groups DROP CONSTRAINT product_groups_name_key;

ALTER TABLE product_groups ADD CONSTRAINT product_groups_parent_name_key
	UNIQUE NULLS NOT DISTINCT (parent_product_group_id, name);

-- One row per (ancestor, descendant) pair, including every group paired with itself at depth
-- 0. `path` is the descendant's display path from its own root, the same string on every row
-- for a given descendant; a group's level is MAX(depth) grouped by descendant_product_group_id,
-- so no separate column is added. Built exactly as locations_resolved is, including both stops
-- (the depth cap, and refusing to revisit an id already on the path) that keep the recursion
-- correct against data no trigger ever saw.
CREATE VIEW product_groups_resolved AS

WITH RECURSIVE tree(id, level, path, id_path) AS (

	SELECT
		pg.id,
		0 AS level,
		pg.name AS path,
		'/' || pg.id::text || '/' AS id_path
	FROM product_groups pg
	WHERE pg.parent_product_group_id IS NULL
		OR NOT EXISTS (SELECT 1 FROM product_groups p WHERE p.id = pg.parent_product_group_id)

	UNION ALL

	SELECT
		c.id,
		t.level + 1,
		t.path || ' / ' || c.name,
		t.id_path || c.id::text || '/'
	FROM tree t
	JOIN product_groups c
		ON c.parent_product_group_id = t.id
	WHERE t.level + 1 < hierarchy_depth_limit()
		AND t.id_path NOT LIKE ('%/' || c.id::text || '/%')
),

pairs(ancestor_product_group_id, descendant_product_group_id, depth) AS (

	SELECT
		t.id AS ancestor_product_group_id,
		t.id AS descendant_product_group_id,
		0 AS depth
	FROM tree t

	UNION ALL

	SELECT
		pg.parent_product_group_id,
		p.descendant_product_group_id,
		p.depth + 1
	FROM pairs p
	JOIN product_groups pg
		ON pg.id = p.ancestor_product_group_id
	JOIN product_groups a
		ON a.id = pg.parent_product_group_id
	WHERE p.depth + 1 < hierarchy_depth_limit()
)

SELECT
	p.ancestor_product_group_id,
	p.descendant_product_group_id,
	p.depth,
	t.path,
	1 AS id -- Dummy, LessQL needs an id column
FROM pairs p
JOIN tree t
	ON t.id = p.descendant_product_group_id;

CREATE FUNCTION trg_product_groups_check_parent() RETURNS TRIGGER AS $$
DECLARE
	parent_level INTEGER;
	subtree_height INTEGER;
BEGIN
	PERFORM pg_advisory_xact_lock(278, 1);

	IF NEW.parent_product_group_id IS NULL THEN
		RETURN NEW;
	END IF;

	IF NEW.parent_product_group_id = NEW.id THEN
		RAISE EXCEPTION 'Recursive nested product group detected';
	END IF;

	IF EXISTS (
		SELECT 1
		FROM product_groups_resolved pgr
		WHERE pgr.ancestor_product_group_id = NEW.id
			AND pgr.descendant_product_group_id = NEW.parent_product_group_id
	) THEN
		RAISE EXCEPTION 'Recursive nested product group detected';
	END IF;

	SELECT COALESCE(MAX(pgr.depth), 0) INTO parent_level
	FROM product_groups_resolved pgr
	WHERE pgr.descendant_product_group_id = NEW.parent_product_group_id;

	SELECT COALESCE(MAX(pgr.depth), 0) INTO subtree_height
	FROM product_groups_resolved pgr
	WHERE pgr.ancestor_product_group_id = NEW.id;

	IF parent_level + 1 + subtree_height > hierarchy_depth_limit() - 1 THEN
		RAISE EXCEPTION 'Product group nesting depth limit exceeded';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER check_product_group_parent BEFORE INSERT OR UPDATE OF parent_product_group_id ON product_groups
FOR EACH ROW EXECUTE FUNCTION trg_product_groups_check_parent();

CREATE FUNCTION trg_product_groups_guard_children() RETURNS TRIGGER AS $$
BEGIN
	PERFORM pg_advisory_xact_lock(278, 1);

	IF EXISTS (SELECT 1 FROM product_groups WHERE parent_product_group_id = OLD.id) THEN
		RAISE EXCEPTION 'Product group has child groups';
	END IF;

	RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER guard_product_group_children BEFORE DELETE ON product_groups
FOR EACH ROW EXECUTE FUNCTION trg_product_groups_guard_children();
