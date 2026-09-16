<?php

// Plan 32: the five entity types that still printed a Grocycode through the webhook —
// products, stock entries, recipes, chores, batteries — join the label subsystem migrations
// 0269-0272 built for locations. This is piece A: schema only. The catalogue, identity and
// operations generalisation that make the new kinds usable are application code, not this
// file.
//
// A PHP migration rather than a plain .sql one (the way 0266 and 0282 are), for one reason
// that is not negotiable in SQL: the five seeded default templates need a real
// `document_digest` — SHA-256 over the RFC 8785 canonical encoding
// (helpers/CanonicalJson.php) — and the only place that computation exists is
// LabelTemplateService/CanonicalJson. Seeding through the real service also means the seeded
// rows are validated by TemplateDocument::Validate() exactly as a household's own template
// would be, rather than by a second, unverified path that could drift from it.
//
// WHY NO TEXT ELEMENT. The plan bullet this migration answers to asks for "the QR and one
// text line bound to the kind's name field". LabelTemplateService::EmptyDocument() explains
// why that is not buildable at seed time: a text element pins a font asset by name, there is
// no default font and no substitution, and a template that references one that does not
// exist refuses to publish (LabelTemplateService::Publish(), 'asset_unavailable'). A fresh
// install has uploaded no font. So every seeded default here is QR-only, the same shape
// EmptyDocument() already produces for a template a household creates by hand — "prints
// something readable" is met by a scannable code, and a text line is one edit away once a
// font exists. This is a correction to the plan's prose, not a narrowing of what ships: the
// location kind has never had a seeded default template either (searched the tree; there is
// none), so this migration is the first of its kind rather than a repeat of "the way the
// location default is seeded today" — that clause does not describe anything in `master`.
//
// Retirement triggers mirror retire_location_labels (migrations/0269.pgsql.sql) exactly for
// four of the five kinds: BEFORE DELETE, retire the live label with an {id, name} snapshot.
// The fifth, stock entries, carries the richer snapshot the plan names explicitly — product
// name, best_before_date, amount — because a stock entry's own name is not what a person
// holding a retired label needs to recognise it by.

use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelTemplateService;

DatabaseService::GetInstance()->InTransaction(function ()
{
	$db = DatabaseService::GetInstance()->GetDbConnectionRaw();

	$db->exec(<<<'SQL'
		ALTER TABLE labels DROP CONSTRAINT labels_kind_check;
		ALTER TABLE labels ADD CONSTRAINT labels_kind_check
			CHECK (kind IN ('location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'));

		ALTER TABLE label_templates DROP CONSTRAINT label_templates_entity_kind_check;
		ALTER TABLE label_templates ADD CONSTRAINT label_templates_entity_kind_check
			CHECK (entity_kind IN ('location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'));

		ALTER TABLE label_captures DROP CONSTRAINT label_captures_entity_kind_check;
		ALTER TABLE label_captures ADD CONSTRAINT label_captures_entity_kind_check
			CHECK (entity_kind IN ('location', 'product', 'stock_entry', 'recipe', 'chore', 'battery'));
		SQL);

	// Question 1's proposed answer: every target table gains the same server-owned
	// generation `locations` got in 0269, and the importer (services/Database/
	// DatabaseImporter.php) is widened in the same change to bump it on all six rather than
	// restore it from foreign input - the identical exclusion `GetCommonColumns()` already
	// applies to `locations`.
	$db->exec(<<<'SQL'
		ALTER TABLE products ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();
		ALTER TABLE stock ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();
		ALTER TABLE recipes ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();
		ALTER TABLE chores ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();
		ALTER TABLE batteries ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();
		SQL);

	$db->exec(<<<'SQL'
		CREATE FUNCTION retire_product_labels() RETURNS trigger LANGUAGE plpgsql AS $$
		BEGIN
			UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
				retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
			WHERE kind = 'product' AND target_id = OLD.id AND retired_at IS NULL;
			RETURN OLD;
		END
		$$;
		CREATE TRIGGER retire_product_labels BEFORE DELETE ON products
		FOR EACH ROW EXECUTE FUNCTION retire_product_labels();

		-- The one snapshot that is not {id, name}: a stock entry's own row has no name, and
		-- the product name plus best_before_date/amount is what plan 32 says a person holding
		-- a retired label most needs to see (piece A). Consuming an entry to zero does not
		-- delete the row, so this fires only on the actual DELETE a purge or an import
		-- replacement performs - Q2's "empty since" case never reaches this trigger.
		CREATE FUNCTION retire_stock_entry_labels() RETURNS trigger LANGUAGE plpgsql AS $$
		BEGIN
			UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
				retirement_snapshot = jsonb_build_object(
					'id', OLD.id,
					'product_name', (SELECT p.name FROM products p WHERE p.id = OLD.product_id),
					'best_before_date', OLD.best_before_date,
					'amount', OLD.amount)
			WHERE kind = 'stock_entry' AND target_id = OLD.id AND retired_at IS NULL;
			RETURN OLD;
		END
		$$;
		CREATE TRIGGER retire_stock_entry_labels BEFORE DELETE ON stock
		FOR EACH ROW EXECUTE FUNCTION retire_stock_entry_labels();

		CREATE FUNCTION retire_recipe_labels() RETURNS trigger LANGUAGE plpgsql AS $$
		BEGIN
			UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
				retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
			WHERE kind = 'recipe' AND target_id = OLD.id AND retired_at IS NULL;
			RETURN OLD;
		END
		$$;
		CREATE TRIGGER retire_recipe_labels BEFORE DELETE ON recipes
		FOR EACH ROW EXECUTE FUNCTION retire_recipe_labels();

		CREATE FUNCTION retire_chore_labels() RETURNS trigger LANGUAGE plpgsql AS $$
		BEGIN
			UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
				retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
			WHERE kind = 'chore' AND target_id = OLD.id AND retired_at IS NULL;
			RETURN OLD;
		END
		$$;
		CREATE TRIGGER retire_chore_labels BEFORE DELETE ON chores
		FOR EACH ROW EXECUTE FUNCTION retire_chore_labels();

		CREATE FUNCTION retire_battery_labels() RETURNS trigger LANGUAGE plpgsql AS $$
		BEGIN
			UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
				retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
			WHERE kind = 'battery' AND target_id = OLD.id AND retired_at IS NULL;
			RETURN OLD;
		END
		$$;
		CREATE TRIGGER retire_battery_labels BEFORE DELETE ON batteries
		FOR EACH ROW EXECUTE FUNCTION retire_battery_labels();
		SQL);

	// One seeded, published, default-pointed template per new kind - QR-only, see the file
	// header for why. Named distinctly (label_templates.name is UNIQUE) and described as
	// seeded so an operator reading the templates list knows where it came from.
	$templates = new LabelTemplateService($db);
	foreach ([
		'product' => 'Default product label',
		'stock_entry' => 'Default stock entry label',
		'recipe' => 'Default recipe label',
		'chore' => 'Default chore label',
		'battery' => 'Default battery label',
	] as $kind => $name)
	{
		$template = $templates->Create($name, 'Seeded default label for ' . $kind . ' (migration 0283)', $kind, null);
		$templates->Publish((int)$template['id'], null);
	}
});
