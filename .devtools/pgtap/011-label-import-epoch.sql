-- migrations/0269.pgsql.sql: label_current_import_epoch() is the DEFAULT behind
-- locations.import_epoch (widened to products/stock/recipes/chores/batteries by
-- migrations/0283.pgsql.php) - a server-owned generation number the importer bumps so a
-- restore can tell "this row came back with the import" from "this row predates it",
-- per ADR-0021. locations is enough to exercise the function itself; the other five
-- tables reuse the identical DEFAULT expression, not a second definition.

SELECT plan(4);

SELECT results_eq(
	'SELECT label_current_import_epoch()',
	'SELECT epoch FROM label_import_state WHERE id = 1',
	'label_current_import_epoch() reads the same row a direct query would'
);

INSERT INTO locations (name) VALUES ('Spike11 before');
SELECT is(
	(SELECT import_epoch FROM locations WHERE name = 'Spike11 before'),
	(SELECT label_current_import_epoch()),
	'A freshly inserted location defaults import_epoch to the current epoch'
);

UPDATE label_import_state SET epoch = epoch + 1 WHERE id = 1;
INSERT INTO locations (name) VALUES ('Spike11 after');
SELECT is(
	(SELECT import_epoch FROM locations WHERE name = 'Spike11 after'),
	(SELECT label_current_import_epoch()),
	'Bumping label_import_state.epoch changes what the DEFAULT stamps onto the next insert'
);

SELECT isnt(
	(SELECT import_epoch FROM locations WHERE name = 'Spike11 before'),
	(SELECT import_epoch FROM locations WHERE name = 'Spike11 after'),
	'The two locations therefore carry different import_epoch values'
);

SELECT * FROM finish();
