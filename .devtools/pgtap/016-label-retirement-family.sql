-- migrations/0283.pgsql.php (plan 32): the four kinds that mirror
-- retire_location_labels (migrations/0269.pgsql.sql) exactly - product, recipe, chore,
-- battery, each a BEFORE DELETE trigger snapshotting {id, name} - plus the one kind
-- that does not, stock_entry, whose snapshot is {id, product_name, best_before_date,
-- amount} because a stock entry has no name of its own for a person holding a retired
-- label to recognise it by. Same retirement shape 010-locations-trigger-family.sql
-- already proved for locations: retired_at set, target_id cleared, one row per kind.

SELECT plan(10);

INSERT INTO locations (name) VALUES ('Spike16 location');
INSERT INTO quantity_units (name) VALUES ('Spike16 qu');

-- product
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike16 product', (SELECT id FROM locations WHERE name = 'Spike16 location'), (SELECT id FROM quantity_units WHERE name = 'Spike16 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike16 qu'));
INSERT INTO labels (uid, kind, target_id) VALUES (
	'A' || upper(substr(md5(random()::text), 1, 12)), 'product', (SELECT id FROM products WHERE name = 'Spike16 product')
);
DELETE FROM products WHERE name = 'Spike16 product';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL AND (retirement_snapshot ->> 'id') IS NOT NULL
		FROM labels WHERE kind = 'product' AND retirement_snapshot ->> 'name' = 'Spike16 product'),
	'Deleting a product retires its live label, clears its target and snapshots its id (retire_product_labels)'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE kind = 'product' AND retirement_snapshot ->> 'name' = 'Spike16 product'),
	jsonb_build_object('name', 'Spike16 product'),
	'Past the id, the product retirement snapshot is exactly {name}, as it stood at delete time'
);

-- recipe
INSERT INTO recipes (name) VALUES ('Spike16 recipe');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'B' || upper(substr(md5(random()::text), 1, 12)), 'recipe', (SELECT id FROM recipes WHERE name = 'Spike16 recipe')
);
DELETE FROM recipes WHERE name = 'Spike16 recipe';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL AND (retirement_snapshot ->> 'id') IS NOT NULL
		FROM labels WHERE kind = 'recipe' AND retirement_snapshot ->> 'name' = 'Spike16 recipe'),
	'Deleting a recipe retires its live label, clears its target and snapshots its id (retire_recipe_labels)'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE kind = 'recipe' AND retirement_snapshot ->> 'name' = 'Spike16 recipe'),
	jsonb_build_object('name', 'Spike16 recipe'),
	'Past the id, the recipe retirement snapshot is exactly {name}'
);

-- chore
INSERT INTO chores (name, period_type) VALUES ('Spike16 chore', 'manually');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'C' || upper(substr(md5(random()::text), 1, 12)), 'chore', (SELECT id FROM chores WHERE name = 'Spike16 chore')
);
DELETE FROM chores WHERE name = 'Spike16 chore';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL AND (retirement_snapshot ->> 'id') IS NOT NULL
		FROM labels WHERE kind = 'chore' AND retirement_snapshot ->> 'name' = 'Spike16 chore'),
	'Deleting a chore retires its live label, clears its target and snapshots its id (retire_chore_labels)'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE kind = 'chore' AND retirement_snapshot ->> 'name' = 'Spike16 chore'),
	jsonb_build_object('name', 'Spike16 chore'),
	'Past the id, the chore retirement snapshot is exactly {name}'
);

-- battery
INSERT INTO batteries (name) VALUES ('Spike16 battery');
INSERT INTO labels (uid, kind, target_id) VALUES (
	'D' || upper(substr(md5(random()::text), 1, 12)), 'battery', (SELECT id FROM batteries WHERE name = 'Spike16 battery')
);
DELETE FROM batteries WHERE name = 'Spike16 battery';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL AND (retirement_snapshot ->> 'id') IS NOT NULL
		FROM labels WHERE kind = 'battery' AND retirement_snapshot ->> 'name' = 'Spike16 battery'),
	'Deleting a battery retires its live label, clears its target and snapshots its id (retire_battery_labels)'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE kind = 'battery' AND retirement_snapshot ->> 'name' = 'Spike16 battery'),
	jsonb_build_object('name', 'Spike16 battery'),
	'Past the id, the battery retirement snapshot is exactly {name}'
);

-- stock_entry - the one snapshot that is not {id, name}. A second, still-live product:
-- 'Spike16 product' above was already deleted along with all of its stock by
-- trg_cascade_product_removal, so the stock row here needs a product of its own.
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES
	('Spike16 product2', (SELECT id FROM locations WHERE name = 'Spike16 location'), (SELECT id FROM quantity_units WHERE name = 'Spike16 qu'), (SELECT id FROM quantity_units WHERE name = 'Spike16 qu'));
INSERT INTO stock (product_id, amount, stock_id, best_before_date) VALUES (
	(SELECT id FROM products WHERE name = 'Spike16 product2'), 3, 'spike16-stock', '2027-01-01'
);
INSERT INTO labels (uid, kind, target_id) VALUES (
	'E' || upper(substr(md5(random()::text), 1, 12)), 'stock_entry',
	(SELECT id FROM stock WHERE stock_id = 'spike16-stock')
);
DELETE FROM stock WHERE stock_id = 'spike16-stock';
SELECT ok(
	(SELECT retired_at IS NOT NULL AND target_id IS NULL AND (retirement_snapshot ->> 'id') IS NOT NULL
		FROM labels WHERE kind = 'stock_entry' AND retirement_snapshot ->> 'product_name' = 'Spike16 product2'),
	'Deleting a stock entry retires its live label, clears its target and snapshots its id (retire_stock_entry_labels)'
);
SELECT is(
	(SELECT retirement_snapshot - 'id' FROM labels WHERE kind = 'stock_entry' AND retirement_snapshot ->> 'product_name' = 'Spike16 product2'),
	jsonb_build_object(
		'product_name', 'Spike16 product2',
		'best_before_date', '2027-01-01',
		'amount', 3
	),
	'The stock entry snapshot carries product_name, best_before_date and amount as they stood at delete time (retire_stock_entry_labels), not {id, name}'
);
