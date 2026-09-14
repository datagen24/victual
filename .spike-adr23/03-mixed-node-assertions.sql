-- Prerequisite 2: Garlic holds the product `Dried (Garlic)` and the subgroup `Fresh` at
-- once, and both are reachable — no special case needed anywhere in this query.

\echo '--- Garlic: its own row, its subgroup, and the product filed directly in it ---'
SELECT
	g.name AS garlic_group,
	(SELECT name FROM product_groups WHERE parent_product_group_id = g.id) AS subgroup,
	(SELECT name FROM products WHERE product_group_id = g.id) AS product_in_garlic
FROM product_groups g
WHERE g.name = 'Garlic';

\echo '--- product_groups_resolved: full path for every group, Garlic/Fresh included ---'
SELECT DISTINCT descendant_product_group_id, path
FROM product_groups_resolved
WHERE depth = 0
ORDER BY path;

\echo '--- Fresh (the subgroup) still resolves its own products (Whole, Crushed) ---'
SELECT p.name
FROM products p
JOIN product_groups g ON g.id = p.product_group_id
WHERE g.name = 'Fresh'
ORDER BY p.name;
