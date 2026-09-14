-- Prerequisite 3: the UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT change,
-- against PostgreSQL 15+. Three cases, run as separate statements so a refusal does not
-- abort the ones after it (each is its own implicit transaction under autocommit).

\echo '--- case A: two groups named Dried under different parents (Parsley, Garlic) ---'
\echo 'expect: both succeed'
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Dried', (SELECT id FROM product_groups WHERE name = 'Parsley'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Dried', (SELECT id FROM product_groups WHERE name = 'Garlic'));

\echo '--- case B: a second Dried under the SAME parent (Parsley again) ---'
\echo 'expect: refused, duplicate key value violates unique constraint "product_groups_parent_name_key"'
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Dried', (SELECT id FROM product_groups WHERE name = 'Parsley'));

\echo '--- case C: a second root group named Spices (parent NULL) — the NULLS NOT DISTINCT case ---'
\echo 'expect: refused'
INSERT INTO product_groups (name, parent_product_group_id) VALUES ('Spices', NULL);

\echo '--- final state: the two accepted Dried rows, distinguishable only by parent ---'
SELECT g.id, g.name, p.name AS parent_name
FROM product_groups g
LEFT JOIN product_groups p ON p.id = g.parent_product_group_id
WHERE g.name = 'Dried'
ORDER BY g.id;
