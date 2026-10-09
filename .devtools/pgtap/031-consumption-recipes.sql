-- migrations/0305.pgsql.sql (ADR-0040 and ADR-0041, issue #698): private consumption recipes,
-- their lines and shares, and the consumption events that record a recipe being consumed.
--
-- Covers the SQL objects the migration creates: the constraints of the five tables, the two
-- triggers that keep a share from naming the owner, and the cascades that delete a recipe with
-- its owner while an event outlives its recipe. Rights, locking and consumption are PHP and are
-- covered by tests/Pgsql (ConsumptionRecipe*Test.php).

SELECT plan(31);

INSERT INTO users (username, password) VALUES ('c31 owner', 'fixture'), ('c31 member', 'fixture'), ('c31 other', 'fixture');
INSERT INTO locations (name) VALUES ('C31 location');
INSERT INTO quantity_units (name) VALUES ('C31 tablet');
INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price)
SELECT 'C31 product', l.id, q.id, q.id, q.id, q.id
FROM locations l CROSS JOIN quantity_units q WHERE l.name = 'C31 location' AND q.name = 'C31 tablet';

CREATE FUNCTION pg_temp.u(n TEXT) RETURNS INTEGER LANGUAGE sql AS $$ SELECT id FROM users WHERE username = 'c31 ' || n $$;
CREATE FUNCTION pg_temp.recipe(n TEXT, owner_name TEXT) RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO consumption_recipes (owner_user_id, name) VALUES (pg_temp.u(owner_name), n) RETURNING id
$$;
CREATE FUNCTION pg_temp.event(recipe INTEGER, id TEXT, st TEXT, tx TEXT) RETURNS INTEGER LANGUAGE sql AS $$
	INSERT INTO consumption_events (user_id, source_system, source_event_id, recipe_id, state, transaction_id, occurred_at)
	VALUES (pg_temp.u('member'), 'manual', id, recipe, st, tx, now()) RETURNING id
$$;

SELECT has_table('consumption_recipes');
SELECT has_table('consumption_recipe_lines');
SELECT has_table('consumption_recipe_shares');
SELECT has_table('consumption_events');
SELECT has_table('consumption_event_lines');

-- Recipes and lines -----------------------------------------------------------------------------

SELECT throws_ok($$INSERT INTO consumption_recipes (owner_user_id, name) VALUES (1, '   ')$$, '23514', NULL, 'a blank recipe name is refused');
SELECT throws_ok($$INSERT INTO consumption_recipes (owner_user_id, name) VALUES (-5, 'orphan')$$, '23503', NULL, 'a recipe needs an existing owner');

SELECT pg_temp.recipe('C31 main', 'owner') AS r \gset
INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
SELECT :r, 1, p.id, 2, p.qu_id_stock FROM products p WHERE p.name = 'C31 product';

SELECT throws_ok(format($$INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
	SELECT %s, 1, p.id, 1, p.qu_id_stock FROM products p WHERE p.name = 'C31 product'$$, :r), '23505', NULL, 'a recipe position is unique');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
	SELECT %s, 2, p.id, 0, p.qu_id_stock FROM products p WHERE p.name = 'C31 product'$$, :r), '23514', NULL, 'a zero amount is refused');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
	SELECT %s, 2, p.id, 'Infinity', p.qu_id_stock FROM products p WHERE p.name = 'C31 product'$$, :r), '23514', NULL, 'an infinite amount is refused');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
	SELECT %s, 0, p.id, 1, p.qu_id_stock FROM products p WHERE p.name = 'C31 product'$$, :r), '23514', NULL, 'position starts at 1');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_lines (recipe_id, position, product_id, amount, qu_id)
	VALUES (%s, 3, -1, 1, 1)$$, :r), '23503', NULL, 'a line needs an existing product');

-- Shares ------------------------------------------------------------------------------------------

INSERT INTO consumption_recipe_shares (recipe_id, user_id, can_consume, granted_by_user_id)
VALUES (:r, pg_temp.u('member'), 1, pg_temp.u('owner'));
SELECT is((SELECT can_edit FROM consumption_recipe_shares WHERE recipe_id = :r AND user_id = pg_temp.u('member')), 0::smallint,
	'a right not granted defaults to 0');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_shares (recipe_id, user_id) VALUES (%s, %s)$$, :r, pg_temp.u('member')),
	'23505', NULL, 'a user holds one share per recipe');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_shares (recipe_id, user_id) VALUES (%s, %s)$$, :r, pg_temp.u('owner')),
	'23514', NULL, 'a share cannot name the owner');
SELECT throws_ok(format($$INSERT INTO consumption_recipe_shares (recipe_id, user_id, can_edit) VALUES (%s, %s, 2)$$, :r, pg_temp.u('other')),
	'23514', NULL, 'a right is 0 or 1');
SELECT throws_ok(format($$UPDATE consumption_recipe_shares SET user_id = %s WHERE recipe_id = %s$$, pg_temp.u('owner'), :r),
	'23514', NULL, 'moving a share onto the owner is refused');
SELECT throws_ok(format($$UPDATE consumption_recipes SET owner_user_id = %s WHERE id = %s$$, pg_temp.u('member'), :r),
	'23514', NULL, 'transferring to a user who still holds a share is refused');
DELETE FROM consumption_recipe_shares WHERE recipe_id = :r AND user_id = pg_temp.u('member');
UPDATE consumption_recipes SET owner_user_id = pg_temp.u('member') WHERE id = :r;
SELECT is((SELECT owner_user_id FROM consumption_recipes WHERE id = :r), pg_temp.u('member'),
	'transfer works once the new owner''s share is removed');
INSERT INTO consumption_recipe_shares (recipe_id, user_id, can_consume, can_edit, can_undo, can_share)
VALUES (:r, pg_temp.u('owner'), 1, 1, 1, 1);
SELECT is((SELECT count(*)::int FROM consumption_recipe_shares WHERE recipe_id = :r), 1,
	'the previous owner can be added as a share holder after a transfer');

-- Events ------------------------------------------------------------------------------------------

SELECT pg_temp.event(:r, 'req-1', 'booked', 'tx-31-1') AS e \gset
INSERT INTO consumption_event_lines (event_id, product_id, amount, location_id)
SELECT :e, p.id, 2, l.id FROM products p CROSS JOIN locations l WHERE p.name = 'C31 product' AND l.name = 'C31 location';

SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	SELECT id, 'manual', 'req-1', 'received', now() FROM users WHERE username = 'c31 member'$$, '23505', NULL,
	'an event identity is unique per user, source system and source event id');
SELECT lives_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	SELECT id, 'manual', 'req-1', 'received', now() FROM users WHERE username = 'c31 other'$$,
	'another user may use the same source event id');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	SELECT id, 'Bad System', 'x', 'received', now() FROM users WHERE username = 'c31 other'$$, '23514', NULL,
	'a source system must be a lowercase token');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	SELECT id, 'manual', 'has space', 'received', now() FROM users WHERE username = 'c31 other'$$, '23514', NULL,
	'a source event id uses the documented characters');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at)
	SELECT id, 'manual', 'no-tx', 'booked', now() FROM users WHERE username = 'c31 other'$$, '23514', NULL,
	'a booked event names its stock transaction');
SELECT throws_ok($$INSERT INTO consumption_events (user_id, source_system, source_event_id, state, reason, occurred_at)
	SELECT id, 'manual', 'bad-reason', 'booked', 'stock_error', now() FROM users WHERE username = 'c31 other'$$, '23514', NULL,
	'only a needs_review event carries a reason');

-- Cascades ----------------------------------------------------------------------------------------

DELETE FROM consumption_recipes WHERE id = :r;
SELECT is((SELECT recipe_id FROM consumption_events WHERE id = :e), NULL::integer, 'deleting a recipe clears the recipe on its events');
SELECT is((SELECT count(*)::int FROM consumption_event_lines WHERE event_id = :e), 1, 'the event keeps its lines');
SELECT is((SELECT count(*)::int FROM consumption_recipe_lines WHERE recipe_id = :r)
	+ (SELECT count(*)::int FROM consumption_recipe_shares WHERE recipe_id = :r), 0, 'deleting a recipe deletes its lines and shares');

SELECT pg_temp.recipe('C31 owned by other', 'other') AS r2 \gset
DELETE FROM users WHERE username = 'c31 other';
SELECT is((SELECT count(*)::int FROM consumption_recipes WHERE id = :r2), 0, 'deleting an account deletes the recipes it owns');

DELETE FROM products WHERE name = 'C31 product';
SELECT is((SELECT count(*)::int FROM consumption_event_lines WHERE event_id = :e), 0, 'deleting a product deletes the event lines naming it');

SELECT * FROM finish();
