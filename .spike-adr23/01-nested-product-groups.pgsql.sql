-- Preview of migrations/0279.pgsql.sql (claimed, unwritten — see migrations/RESERVATIONS.md)
-- against plan 30's proposed change. Scoped to what ADR-0023's acceptance prerequisites 2
-- and 3 ask for: the mixed node and the name-uniqueness change. Plan 30's cycle guard,
-- depth guard and delete guard are NOT reproduced here — they are not what those two
-- prerequisites test, and 0279 itself will copy them from migrations/0273.pgsql.sql the way
-- plan 30 specifies. Do not read this file as the migration; read it as the two schema
-- changes those prerequisites are about.

ALTER TABLE product_groups ADD COLUMN parent_product_group_id INTEGER;

ALTER TABLE product_groups DROP CONSTRAINT product_groups_name_key;

ALTER TABLE product_groups ADD CONSTRAINT product_groups_parent_name_key
	UNIQUE NULLS NOT DISTINCT (parent_product_group_id, name);

-- product_groups_resolved, copied from locations_resolved's shape
-- (migrations/0273.pgsql.sql) with the nouns changed and the hierarchy_depth_limit() bound
-- kept, since that function is shared and plan 30 says so. One row per (ancestor,
-- descendant) pair including self-at-depth-0, plus a display path.
CREATE VIEW product_groups_resolved AS

WITH RECURSIVE tree(id, level, path, id_path) AS (

	SELECT
		g.id,
		0 AS level,
		g.name AS path,
		'/' || g.id::text || '/' AS id_path
	FROM product_groups g
	WHERE g.parent_product_group_id IS NULL
		OR NOT EXISTS (SELECT 1 FROM product_groups p WHERE p.id = g.parent_product_group_id)

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
		g.parent_product_group_id,
		p.descendant_product_group_id,
		p.depth + 1
	FROM pairs p
	JOIN product_groups g
		ON g.id = p.ancestor_product_group_id
	JOIN product_groups a
		ON a.id = g.parent_product_group_id
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
