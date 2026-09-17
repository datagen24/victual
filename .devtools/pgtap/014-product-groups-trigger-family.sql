-- migrations/0278.pgsql.sql (plan 30, ADR-0023 decision 4): product_groups' own nested
-- hierarchy - "this is migrations/0273.pgsql.sql with the nouns changed" per that
-- migration's own header. This file mirrors 010-locations-trigger-family.sql the same
-- way: recursion refusal, the depth limit (hierarchy_depth_limit() reused, not
-- recreated - still 6), and the child guard. There is no retirement snapshot here to
-- mirror - unlike locations, no label kind is bound to product_groups - so this file
-- has six assertions where the location family's had eight.

SELECT plan(6);

-- A group cannot be its own parent - refused before the recursive check even runs.
INSERT INTO product_groups (name) VALUES ('Spike14 self');
SELECT throws_ok(
	format('UPDATE product_groups SET parent_product_group_id = id WHERE name = %L', 'Spike14 self'),
	'Recursive nested product group detected',
	'A product group cannot be its own parent'
);

-- A genuine cycle: b's parent is already a, so a cannot become a child of b.
INSERT INTO product_groups (name) VALUES ('Spike14 cycle a'), ('Spike14 cycle b');
UPDATE product_groups SET parent_product_group_id = (SELECT id FROM product_groups WHERE name = 'Spike14 cycle a')
	WHERE name = 'Spike14 cycle b';
SELECT throws_ok(
	format(
		'UPDATE product_groups SET parent_product_group_id = (SELECT id FROM product_groups WHERE name = %L) WHERE name = %L',
		'Spike14 cycle b', 'Spike14 cycle a'
	),
	'Recursive nested product group detected',
	'A product group cannot become the child of its own descendant'
);

-- The depth limit, reusing hierarchy_depth_limit() = 6: a chain of six (a root and five
-- generations) is accepted; a seventh generation is refused.
INSERT INTO product_groups (name, parent_product_group_id) VALUES ('Spike14 depth 0', NULL);
DO $$
DECLARE
	parent_id INTEGER;
	i INTEGER;
BEGIN
	SELECT id INTO parent_id FROM product_groups WHERE name = 'Spike14 depth 0';

	FOR i IN 1..5 LOOP
		INSERT INTO product_groups (name, parent_product_group_id)
			VALUES ('Spike14 depth ' || i, parent_id)
			RETURNING id INTO parent_id;
	END LOOP;
END $$;
SELECT ok(
	(SELECT count(*) FROM product_groups WHERE name LIKE 'Spike14 depth %') = 6,
	'A six-node chain (a root and five generations) is accepted'
);
SELECT throws_ok(
	format(
		'INSERT INTO product_groups (name, parent_product_group_id) VALUES (%L, (SELECT id FROM product_groups WHERE name = %L))',
		'Spike14 depth 6', 'Spike14 depth 5'
	),
	'Product group nesting depth limit exceeded',
	'A seventh generation is refused'
);

-- The child guard: a group with children refuses deletion; a childless one does not.
SELECT throws_ok(
	format('DELETE FROM product_groups WHERE name = %L', 'Spike14 depth 0'),
	'Product group has child groups',
	'A product group with children refuses deletion'
);
SELECT lives_ok(
	format('DELETE FROM product_groups WHERE name = %L', 'Spike14 depth 5'),
	'The deepest, childless leaf can be deleted'
);

SELECT * FROM finish();
