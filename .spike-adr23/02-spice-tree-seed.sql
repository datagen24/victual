-- The worked tree from docs/plans/30-nested-product-groups.md, supplied 2026-09-13 as the
-- case behind ADR-0023. Groups first, root to leaf; products attach afterwards.

INSERT INTO product_groups (name, parent_product_group_id) VALUES ('Spices', NULL);
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Parsley', (SELECT id FROM product_groups WHERE name = 'Spices'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Pepper', (SELECT id FROM product_groups WHERE name = 'Spices'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Blend', (SELECT id FROM product_groups WHERE name = 'Spices'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Paprika', (SELECT id FROM product_groups WHERE name = 'Spices'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Garlic', (SELECT id FROM product_groups WHERE name = 'Spices'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Fresh', (SELECT id FROM product_groups WHERE name = 'Garlic'));
INSERT INTO product_groups (name, parent_product_group_id)
	VALUES ('Mustard', (SELECT id FROM product_groups WHERE name = 'Spices'));

-- Products attach by product_group_id, independent of the parent_product_group_id column
-- above. `Garlic` is the mixed node: it is the parent of subgroup `Fresh` (above) and, on
-- the next line, the direct product_group of `Dried` — a product, not a group.
INSERT INTO products (name, product_group_id)
	VALUES ('Dried (Parsley)', (SELECT id FROM product_groups WHERE name = 'Parsley'));
INSERT INTO products (name, product_group_id)
	VALUES ('Black', (SELECT id FROM product_groups WHERE name = 'Pepper'));
INSERT INTO products (name, product_group_id)
	VALUES ('Sublime Swine', (SELECT id FROM product_groups WHERE name = 'Blend'));
INSERT INTO products (name, product_group_id)
	VALUES ('Smoked', (SELECT id FROM product_groups WHERE name = 'Paprika'));
INSERT INTO products (name, product_group_id)
	VALUES ('Ground (Paprika)', (SELECT id FROM product_groups WHERE name = 'Paprika'));
INSERT INTO products (name, product_group_id)
	VALUES ('Whole', (SELECT id FROM product_groups WHERE name = 'Fresh'));
INSERT INTO products (name, product_group_id)
	VALUES ('Crushed', (SELECT id FROM product_groups WHERE name = 'Fresh'));
INSERT INTO products (name, product_group_id)
	VALUES ('Dried (Garlic)', (SELECT id FROM product_groups WHERE name = 'Garlic'));
INSERT INTO products (name, product_group_id)
	VALUES ('Ground (Mustard)', (SELECT id FROM product_groups WHERE name = 'Mustard'));
