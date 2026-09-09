-- Deeply nested locations: `locations` becomes a tree, so that "what is in the basement"
-- has an answer that does not depend on every shelf being named "Basement — ...".
-- See docs/plans/08-nested-locations.md and issue 81.
--
-- The column is `parent_location_id INTEGER NULL` with no foreign key, which is the shape
-- `products.parent_product_id` already has and the shape the rest of this schema uses. Plan
-- 07 will nest products against the same pattern and reuses the depth function below.
--
-- WHY THE UNIQUE CONSTRAINT IS RESPELLED, AND WHAT IT COSTS. `locations.name` has been
-- globally UNIQUE since migrations/0002.sql (the baseline declares it inline, so the
-- constraint is named `locations_name_key`). In a tree that is the wrong rule: two rooms
-- each having a "Top shelf" is the ordinary case, not a mistake. The replacement is
-- UNIQUE(parent_location_id, name) — but PostgreSQL treats NULLs as distinct in a unique
-- index by default, so without NULLS NOT DISTINCT two *root* locations could both be called
-- "Basement" and the rule would hold everywhere except at the top of the tree, which is
-- where a duplicate is most confusing. NULLS NOT DISTINCT is PostgreSQL 15 and later.
--
-- So this migration raises the fork's minimum PostgreSQL version from 13 to 15.
-- db/pgsql/README.md's target line is updated in the same commit. Nothing in the tree pinned
-- 13 other than that line: CI runs postgres:16, deploy/README.md documents 16, and the
-- baseline needs an ICU-enabled build, which every supported release is. Issue 81 records
-- that this fork may require 15+; plan 08 question 1 is where the choice was made.
--
-- The unique constraint's index has parent_location_id as its leading column, so it already
-- serves the recursive view's "children of this id" lookup. A separate index on
-- parent_location_id alone would be a second copy of the same access path on a table with a
-- few dozen rows, so it is deliberately not created; see plan 08's Executed section, which
-- records this as a divergence from the execution plan's contract.
--
-- WHY THE VIEW STOPS AT hierarchy_depth_limit(). locations_resolved is a recursive CTE, and
-- a recursive CTE over a table with no foreign key and a nullable self-reference is one bad
-- row away from not terminating. The triggers below refuse a cycle, but a view has to be
-- correct against data the triggers never saw — rows written before this migration, rows
-- restored from a backup, rows an import wrote. Two independent stops are therefore built
-- in: the descent carries the ids it has already visited and refuses to revisit one (the
-- same guard quantity_unit_conversions_resolved uses on its conversion paths), and it stops
-- at the depth limit regardless. A tree that violates neither is unaffected by both.
--
-- The base case is "has no parent, *or* has a parent id that no row answers to". Without the
-- second half an orphan — a row whose parent was deleted out from under it by some path this
-- migration does not control, since there is no foreign key — would be absent from the view
-- entirely rather than appearing as a root, and everything that reads the tree (the pickers,
-- the filters, the depth guard) would silently stop seeing it.
--
-- WHY THE DELETE GUARD IS ABOUT CHILDREN AND NOTHING ELSE. Plan 08 question 2 chose to
-- refuse the delete rather than reparent or cascade: reparenting silently rewrites where
-- things were, and cascading deletes the location stock rows point at. It refuses on
-- children only. Deleting a childless location that still holds stock is not changed here —
-- today nothing stops it, and making that a second refusal would be a behaviour change this
-- plan did not ask for and issue 81 lists under Unchanged.
--
-- A trigger's message never reaches a client: BaseApiController::GenericErrorResponse()
-- replaces any message beginning `SQLSTATE[` before it is rendered, deliberately, so that a
-- driver's text cannot leak. The clear message question 2 asks for therefore comes from a
-- pre-check in GenericEntityApiController::DeleteObject(); this trigger is the backstop for
-- every other write path, and the two are worded identically on purpose.
--
-- It is the second BEFORE DELETE trigger on this table, beside `retire_location_labels` from
-- migrations/0269.pgsql.sql. PostgreSQL fires triggers of the same kind in name order and a
-- RAISE in either aborts the statement, so the order cannot matter; it is named to sort
-- first anyway, so that a refused delete does not first retire a label it then keeps.
--
-- No ENGINE_EXCLUSIVE_TABLES entry and no @engine-exclusive marker, for the reason
-- migrations/0268.pgsql.sql gives at length: migratedifftest.php enumerates BASE TABLE only
-- and compares the intersection of the two engines' columns, so a PostgreSQL-only column on
-- a shared table and a PostgreSQL-only view are both already invisible to it. The marker is
-- asked for only below the SQLite freeze, where a lone engine-specific file could be a
-- missing counterpart rather than the only file that could exist.
--
-- The baseline in db/pgsql/baseline/ is deliberately not edited, for the reason
-- migrations/0261.pgsql.sql gives: it is defined as the state SQLite reaches after migrations
-- 0001-0255, and a fresh PostgreSQL database loads it and then runs 0256 onwards. Changing
-- both would apply this twice.

-- The one place the cap is written down. Plan 08 question 5 asked for a constant shared with
-- plan 07, and it has to live in the database rather than in PHP: /objects/locations writes
-- reach the row through GenericEntityApiController with no service in between, so a PHP
-- constant would be a second copy the API never consults. Plan 07's product nesting guard
-- calls this same function when it is written.
--
-- It counts *nodes in a chain*, not edges: 6 means a root and five generations below it, so
-- the deepest level (0 at a root) is 5. The real layout plan 08 question 5 was confirmed
-- against is Floor / Room / SubSpace / Shelf — four — so this leaves two spare, and depth
-- carries no meaning of its own: level 3 is a freezer in one branch and a cabinet in another.
CREATE FUNCTION hierarchy_depth_limit() RETURNS INTEGER
	LANGUAGE sql IMMUTABLE PARALLEL SAFE
	AS $$ SELECT 6 $$;

ALTER TABLE locations ADD COLUMN parent_location_id INTEGER;

ALTER TABLE locations DROP CONSTRAINT locations_name_key;

ALTER TABLE locations ADD CONSTRAINT locations_parent_name_key
	UNIQUE NULLS NOT DISTINCT (parent_location_id, name);

-- One row per (ancestor, descendant) pair, including every location paired with itself at
-- depth 0, which is the shape recipes_nestings_resolved uses and what lets a caller join
-- "everything at or below X" without a UNION.
--
-- `path` is the *descendant's* display path from its own root, not from the ancestor: it is
-- the same string on every row for a given descendant, and it is what the pickers and the
-- locations list render. The self row is therefore also the cheapest way to ask for one
-- location's path. A location's level — its distance from its root — is MAX(depth) grouped by
-- descendant_location_id, so no separate column is added.
--
-- The separator is " / " and the string is built the way quantity_unit_conversions_resolved
-- builds its path, by concatenation down the recursion.
CREATE VIEW locations_resolved AS

WITH RECURSIVE tree(id, level, path, id_path) AS (

	-- Roots: no parent, or a parent id nothing answers to.
	SELECT
		l.id,
		0 AS level,
		l.name AS path,
		'/' || l.id::text || '/' AS id_path
	FROM locations l
	WHERE l.parent_location_id IS NULL
		OR NOT EXISTS (SELECT 1 FROM locations p WHERE p.id = l.parent_location_id)

	UNION ALL

	SELECT
		c.id,
		t.level + 1,
		t.path || ' / ' || c.name,
		t.id_path || c.id::text || '/'
	FROM tree t
	JOIN locations c
		ON c.parent_location_id = t.id
	WHERE t.level + 1 < hierarchy_depth_limit()
		AND t.id_path NOT LIKE ('%/' || c.id::text || '/%')
),

-- The ancestor side, walked back up from each node. Bounded by the same two stops, and by
-- the fact that `tree` above already refused to descend past them.
pairs(ancestor_location_id, descendant_location_id, depth) AS (

	SELECT
		t.id AS ancestor_location_id,
		t.id AS descendant_location_id,
		0 AS depth
	FROM tree t

	UNION ALL

	SELECT
		l.parent_location_id,
		p.descendant_location_id,
		p.depth + 1
	FROM pairs p
	JOIN locations l
		ON l.id = p.ancestor_location_id
	JOIN locations a
		ON a.id = l.parent_location_id
	WHERE p.depth + 1 < hierarchy_depth_limit()
)

SELECT
	p.ancestor_location_id,
	p.descendant_location_id,
	p.depth,
	t.path,
	1 AS id -- Dummy, LessQL needs an id column
FROM pairs p
JOIN tree t
	ON t.id = p.descendant_location_id;

-- Cycles and depth, on the one column that can create either.
--
-- The depth arithmetic is about the whole subtree, not about the row being written: moving a
-- three-deep branch under a node that is already three deep has to be refused even though the
-- row itself only moves one level. `subtree_height` is 0 for a leaf and for a row that does
-- not exist yet, which is why an INSERT needs no separate branch.
--
-- WHY IT TAKES A LOCK BEFORE IT LOOKS. A guard that reads the tree and then writes to it is
-- only correct if no other transaction is doing the same thing at the same time, and two
-- concurrent re-parentings touch different rows, so nothing in the engine makes them wait for
-- each other. Measured on PostgreSQL 16.13 before this lock existed: two connections, one
-- setting A's parent to B and the other setting B's parent to A, each read the tree as it was
-- before the other wrote, each found no cycle, and both committed. The result is a two-node
-- cycle -- and because the view descends from roots, neither row is reachable from one any
-- more, so A and B vanish from locations_resolved entirely. They disappear from every picker,
-- from the locations list, and from the parent select that would let someone undo it.
--
-- The lock is advisory rather than a row lock because what has to be serialised is the *shape
-- of the tree*, which is not any one row: the rows a check reads are not the rows it writes.
-- It is held to the end of the transaction and taken on every parent write, so the checks
-- below run one at a time. Locations are a few dozen rows edited by hand, so serialising every
-- hierarchy write costs nothing worth measuring. The two-argument form is keyed on this
-- migration's number, leaving the rest of the keyspace to whatever needs it next.
--
-- This function must stay VOLATILE (which is the default, and why no volatility is declared),
-- and the lock is only half the fix without it. A statement that blocks on the lock took its
-- snapshot when it started, which is before the transaction it waits for committed -- so
-- being let through is not the same as being told what happened meanwhile. What closes that
-- gap is volatility: a VOLATILE function takes a fresh snapshot for each query it runs, so
-- the reads below see the write this statement waited for. Marked STABLE they would use the
-- calling statement's snapshot instead and the lock would serialise the checks while handing
-- each of them the same stale answer. Measured on PostgreSQL 16.13 with the lock in place and
-- this function marked STABLE: the second re-parenting waits the full 2.5 seconds and is then
-- accepted, committing exactly the cycle the lock was added to prevent.
--
-- .devtools/pgsql/nested-locations-tests.php case 10 holds both halves: it blocks a second
-- connection's UPDATE, commits the first from another process while that statement is still
-- waiting, and requires the same waiting statement to come back refused.
CREATE FUNCTION trg_locations_check_parent() RETURNS TRIGGER AS $$
DECLARE
	parent_level INTEGER;
	subtree_height INTEGER;
BEGIN
	PERFORM pg_advisory_xact_lock(273, 1);

	IF NEW.parent_location_id IS NULL THEN
		RETURN NEW;
	END IF;

	IF NEW.parent_location_id = NEW.id THEN
		RAISE EXCEPTION 'Recursive nested location detected';
	END IF;

	IF EXISTS (
		SELECT 1
		FROM locations_resolved lr
		WHERE lr.ancestor_location_id = NEW.id
			AND lr.descendant_location_id = NEW.parent_location_id
	) THEN
		RAISE EXCEPTION 'Recursive nested location detected';
	END IF;

	-- NULL when the parent id answers to no row. There is no foreign key here, by design, so
	-- that stays possible; treating the missing parent as a root is the conservative reading
	-- and still bounds the depth.
	SELECT COALESCE(MAX(lr.depth), 0) INTO parent_level
	FROM locations_resolved lr
	WHERE lr.descendant_location_id = NEW.parent_location_id;

	SELECT COALESCE(MAX(lr.depth), 0) INTO subtree_height
	FROM locations_resolved lr
	WHERE lr.ancestor_location_id = NEW.id;

	IF parent_level + 1 + subtree_height > hierarchy_depth_limit() - 1 THEN
		RAISE EXCEPTION 'Location nesting depth limit exceeded';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER check_location_parent BEFORE INSERT OR UPDATE OF parent_location_id ON locations
FOR EACH ROW EXECUTE FUNCTION trg_locations_check_parent();

-- The same lock, for the same reason one turn further round: this guard reads the rows that
-- the other trigger writes. Without it a delete and a concurrent insert of a child under the
-- row being deleted both see a tree the other has not changed yet, the delete finds no
-- children and the insert finds a parent, and the child outlives its parent.
CREATE FUNCTION trg_locations_guard_children() RETURNS TRIGGER AS $$
BEGIN
	PERFORM pg_advisory_xact_lock(273, 1);

	IF EXISTS (SELECT 1 FROM locations WHERE parent_location_id = OLD.id) THEN
		RAISE EXCEPTION 'Location has child locations';
	END IF;

	RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER guard_location_children BEFORE DELETE ON locations
FOR EACH ROW EXECUTE FUNCTION trg_locations_guard_children();
