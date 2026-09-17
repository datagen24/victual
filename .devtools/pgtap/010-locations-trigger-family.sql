-- ADR-0025 spike 4: the location trigger family from migrations/0269.pgsql.sql and
-- 0273.pgsql.sql - recursion refusal, the depth limit, the child guard and the
-- retirement snapshot. Run against a database run-tests.sh has already migrated in
-- full, so locations_resolved and every guard below are the real ones, not a reduced
-- fixture schema.
--
-- hierarchy_depth_limit() counts nodes in a chain, not edges: 6 means a root and five
-- generations below it (see migrations/0273.pgsql.sql's own comment), so a six-node
-- chain is the positive control for the depth checks and a seventh node is the refusal.

SELECT plan(8);

-- A location cannot be its own parent - refused before the recursive check even runs.
INSERT INTO locations (name) VALUES ('Spike4 self');
SELECT throws_ok(
	format('UPDATE locations SET parent_location_id = id WHERE name = %L', 'Spike4 self'),
	'Recursive nested location detected',
	'A location cannot be its own parent'
);

-- A genuine cycle: b's parent is already a, so a cannot become a child of b.
INSERT INTO locations (name) VALUES ('Spike4 cycle a'), ('Spike4 cycle b');
UPDATE locations SET parent_location_id = (SELECT id FROM locations WHERE name = 'Spike4 cycle a')
	WHERE name = 'Spike4 cycle b';
SELECT throws_ok(
	format(
		'UPDATE locations SET parent_location_id = (SELECT id FROM locations WHERE name = %L) WHERE name = %L',
		'Spike4 cycle b', 'Spike4 cycle a'
	),
	'Recursive nested location detected',
	'A location cannot become the child of its own descendant'
);

-- The depth limit. A chain of six (a root and five generations) is accepted; a seventh
-- generation is refused.
INSERT INTO locations (name, parent_location_id) VALUES ('Spike4 depth 0', NULL);
DO $$
DECLARE
	parent_id INTEGER;
	i INTEGER;
BEGIN
	SELECT id INTO parent_id FROM locations WHERE name = 'Spike4 depth 0';

	FOR i IN 1..5 LOOP
		INSERT INTO locations (name, parent_location_id)
			VALUES ('Spike4 depth ' || i, parent_id)
			RETURNING id INTO parent_id;
	END LOOP;
END $$;
SELECT ok(
	(SELECT count(*) FROM locations WHERE name LIKE 'Spike4 depth %') = 6,
	'A six-node chain (a root and five generations) is accepted'
);
SELECT throws_ok(
	format(
		'INSERT INTO locations (name, parent_location_id) VALUES (%L, (SELECT id FROM locations WHERE name = %L))',
		'Spike4 depth 6', 'Spike4 depth 5'
	),
	'Location nesting depth limit exceeded',
	'A seventh generation is refused'
);

-- The child guard: a location with children refuses deletion; a childless one does not.
SELECT throws_ok(
	format('DELETE FROM locations WHERE name = %L', 'Spike4 depth 0'),
	'Location has child locations',
	'A location with children refuses deletion'
);
SELECT lives_ok(
	format('DELETE FROM locations WHERE name = %L', 'Spike4 depth 5'),
	'The deepest, childless leaf can be deleted'
);

-- The retirement snapshot: deleting a location retires its live label rather than
-- leaving a dangling target_id, and the snapshot is taken from the row as it stood.
INSERT INTO locations (name) VALUES ('Spike4 retire');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'A' || upper(substr(md5(random()::text), 1, 12)),
	'location',
	(SELECT id FROM locations WHERE name = 'Spike4 retire')
);
DELETE FROM locations WHERE name = 'Spike4 retire';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL
		FROM labels WHERE kind = 'location' AND retirement_snapshot ->> 'name' = 'Spike4 retire'),
	'Deleting a location retires its live label and clears its target'
);
SELECT is(
	(SELECT retirement_snapshot ->> 'name'
		FROM labels WHERE kind = 'location' AND retirement_snapshot ->> 'name' = 'Spike4 retire'),
	'Spike4 retire',
	'The retirement snapshot carries the name as it stood at delete time'
);

SELECT * FROM finish();
