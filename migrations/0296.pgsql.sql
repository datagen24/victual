-- Issue #516 (M16, #487 remediation), maintainer decision D2: retiring a label leaves its
-- queued print job uncancelled. Decision D2, verbatim: "cancel queued, unclaimed print jobs
-- when their label is retired. Leave running jobs untouched. Retirement makes those queued
-- jobs permanently ineligible to print. Cancelling them gives the monitor an accurate final
-- state and stays within ADR-0019's stated meaning of 'undeliverable.' Implement it for both
-- retirement paths: application code and the stock retirement trigger."
--
-- WHY EVERY RETIREMENT SITE IS A DATABASE TRIGGER. A label never gets retired from PHP:
-- LabelIdentityService, LabelOperationsService and PrintAttemptService all only ever *read*
-- labels.retired_at (LabelOperationsService::AssertLabelLive(), LabelIdentityService::
-- Resolve()). The six retirement triggers migrations/0269.pgsql.sql and 0283.pgsql.php
-- create (retire_location_labels, retire_product_labels, retire_stock_entry_labels,
-- retire_recipe_labels, retire_chore_labels, retire_battery_labels) are the only code that
-- ever sets retired_at, and every one of them fires from an application-initiated DELETE:
-- GenericEntityApiController::DeleteObject()'s `$row->delete()` for five of the six kinds
-- (locations, products, recipes, chores, batteries — "the application path" D2 names), and
-- StockService's own `DELETE FROM stock` for a fully-consumed entry (StockService.php:903)
-- for the sixth ("the stock retirement trigger" D2 names specifically, because it is the one
-- fired from application code that is not the generic entity-delete controller). Cancelling
-- unclaimed jobs at the trigger level, once, covers both paths uniformly rather than
-- duplicating the check in PHP for five different delete call sites plus StockService, none
-- of which currently open a transaction a PHP-level hook could join
-- (GenericEntityApiController::DeleteObject() runs `$row->delete()` in autocommit, per its own
-- comment at line ~291).
--
-- DEPENDS ON #624 (migration 0295, unmerged as of this writing). #624 redefines
-- trg_cascade_product_removal (migrations/0279.pgsql.sql, AFTER DELETE ON products) so that
-- deleting a product retires its stock entries' labels *there* — with the product's own
-- OLD.name, before the row is gone — rather than relying on retire_stock_entry_labels' own
-- product-name subquery, which by then finds nothing (issue #558). That means a product
-- delete's cascade to its stock entries' labels no longer goes through
-- retire_stock_entry_labels at all (that trigger's own `retired_at IS NULL` guard makes it a
-- no-op once #624's UPDATE has already retired the row) — so cancelling queued jobs only
-- inside retire_stock_entry_labels would miss every stock-entry label retired by a product
-- delete. This migration redefines trg_cascade_product_removal a second time (CREATE OR
-- REPLACE FUNCTION, the same mechanism 0279 and 0295 both already use) to add the identical
-- cancellation call after #624's own retirement UPDATE, reproducing #624's body verbatim
-- otherwise. migrations/RESERVATIONS.md and the PR body both say this migration cannot be
-- merged before #624 lands.
--
-- RACE SAFETY, the same locking discipline PrintAttemptService::Claim() and
-- LabelOperationsService::Cancel() already use. Claim() takes `FOR UPDATE OF j SKIP LOCKED`
-- on the print_jobs rows it is about to claim and then, still inside that same transaction,
-- writes current_attempt_id (PrintAttemptService.php Claim(), line ~99). Cancel() takes a
-- plain `FOR UPDATE` on one job by id and refuses once current_attempt_id is set
-- (LabelOperationsService.php Cancel()). cancel_queued_label_jobs() below does the same
-- thing at the row-set level: its own UPDATE ... WHERE current_attempt_id IS NULL is what
-- takes the row lock, so of the two backends that reach the same row first, one of them
-- necessarily blocks:
--
--   - If this function's UPDATE reaches a job's row first, Claim()'s `FOR UPDATE ... SKIP
--     LOCKED` simply skips that row (SKIP LOCKED never blocks) and does not claim it in that
--     pass; once this function's transaction commits, the job carries cancelled_at and
--     Claim()'s own `AND j.cancelled_at IS NULL` predicate excludes it from then on.
--   - If Claim() reaches the row first (inserts an attempt and sets current_attempt_id, still
--     uncommitted), this function's UPDATE blocks on the same row lock. PostgreSQL's
--     EvalPlanQual re-checks the UPDATE's WHERE clause against the row's post-commit version
--     once the lock is released, so once Claim() commits, current_attempt_id IS NULL is now
--     false for that row and this function's UPDATE leaves it untouched — a job already
--     claimed is never cancelled, exactly as D2 requires. The reverse order (this function's
--     transaction rolling back) leaves Claim() free to proceed against the original,
--     unclaimed row.
--
-- The outbox side effect (dead-lettering the outbox row so nothing keeps trying to deliver a
-- cancelled job) reads the SET of job ids cancel_queued_label_jobs() actually cancelled — via
-- the first UPDATE's own RETURNING — rather than a second, independent WHERE clause: an
-- independent second read could disagree with the first about which rows qualified if it ran
-- against a different snapshot, which is exactly the two-writes-do-not-agree failure mode
-- LabelOperationsService::Cancel() avoids by taking its row lock once, up front, before either
-- of its own two UPDATEs.
CREATE FUNCTION cancel_queued_label_jobs(p_label_uid TEXT) RETURNS void LANGUAGE plpgsql AS $$
DECLARE
	v_outbox_id INTEGER;
BEGIN
	FOR v_outbox_id IN
		UPDATE print_jobs
		SET cancelled_at = CURRENT_TIMESTAMP, cancelled_reason = 'label retired'
		WHERE label_uid = p_label_uid
			AND cancelled_at IS NULL
			AND outcome IS NULL
			AND current_attempt_id IS NULL
		RETURNING outbox_id
	LOOP
		UPDATE outbox SET dead_lettered_at = CURRENT_TIMESTAMP, last_error = 'Cancelled: label retired'
		WHERE id = v_outbox_id AND dead_lettered_at IS NULL AND delivered_at IS NULL;
	END LOOP;
END
$$;

CREATE OR REPLACE FUNCTION retire_location_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'location' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

CREATE OR REPLACE FUNCTION retire_product_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'product' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

-- Unchanged from migration 0283 otherwise: this is still the trigger a direct
-- `DELETE FROM stock` fires (StockService.php:903's full-consumption path, and any other
-- direct deletion of a stock row). A stock entry retired instead by a *product* delete's
-- cascade is covered by trg_cascade_product_removal below, per #624 - this trigger's own
-- `retired_at IS NULL` guard makes it a no-op for that path, so it never double-cancels.
CREATE OR REPLACE FUNCTION retire_stock_entry_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object(
			'id', OLD.id,
			'product_name', (SELECT p.name FROM products p WHERE p.id = OLD.product_id),
			'best_before_date', OLD.best_before_date,
			'amount', OLD.amount)
	WHERE kind = 'stock_entry' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

CREATE OR REPLACE FUNCTION retire_recipe_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'recipe' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

CREATE OR REPLACE FUNCTION retire_chore_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'chore' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

CREATE OR REPLACE FUNCTION retire_battery_labels() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
	v_uid TEXT;
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'battery' AND target_id = OLD.id AND retired_at IS NULL
	RETURNING uid INTO v_uid;
	IF v_uid IS NOT NULL THEN
		PERFORM cancel_queued_label_jobs(v_uid);
	END IF;
	RETURN OLD;
END
$$;

-- Redefines #624's own redefinition of trg_cascade_product_removal (migrations/0279.pgsql.sql,
-- 0295 per PR #624) a second time. Every line below the FOR loop's own body is #624's body
-- verbatim (issue #558's fix: retire a deleted product's stock-entry labels with OLD.name
-- before the cascade deletes the stock rows). Two additions: the PERFORM before the labels
-- UPDATE (see its own comment - PR #626 review, second delta round), and the FOR loop itself,
-- which walks every label that UPDATE actually retired - there can be more than one, one per
-- stock row the product held - and cancels that label's own queued jobs the same way the five
-- single-row triggers above do.
--
-- LOCK ORDER (PR #626 review, second delta round): every other retirement site locks its
-- entity row before it ever touches `labels` - a direct `DELETE FROM stock`, `DELETE FROM
-- locations`, etc. all lock that row as part of the DELETE itself, before their BEFORE DELETE
-- trigger runs. This site did not: `UPDATE labels ... FROM stock s ... WHERE s.product_id =
-- OLD.id` reads `stock` through a join, which takes no lock on the rows it reads, and the
-- `DELETE FROM stock` below - which would have locked them - ran *after* labels were already
-- retired and their jobs already cancelled. That let LabelOperationsService::RevisedPrint()
-- (kind='stock_entry') read a label as still live, queue a new job, and commit *after* this
-- trigger's cancellation had already run over "whatever was queued at that moment" - a job
-- that then sat queued forever, D2 violated, because RevisedPrint()'s own lock order
-- (Issue()'s entity FOR UPDATE, then AssertLabelLive()'s labels FOR SHARE - see
-- LabelOperationsService.php) had nothing on this side to serialise against. The PERFORM
-- below locks every affected stock row first, in a stable order, closing that gap: a
-- concurrent RevisedPrint('stock_entry', S) either gets there first (this PERFORM then waits
-- for it to finish, sees whatever job it created, and cancels it) or gets there second
-- (blocks on this PERFORM until the whole cascade, including cancellation, commits, then
-- finds the label already retired and refuses).
CREATE OR REPLACE FUNCTION trg_cascade_product_removal() RETURNS TRIGGER AS $$
DECLARE
	r RECORD;
BEGIN
	PERFORM 1 FROM stock WHERE product_id = OLD.id ORDER BY id FOR UPDATE;

	FOR r IN
		UPDATE labels
		SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
			retirement_snapshot = jsonb_build_object(
				'id', s.id,
				'product_name', OLD.name,
				'best_before_date', s.best_before_date,
				'amount', s.amount)
		FROM stock s
		WHERE labels.kind = 'stock_entry'
			AND labels.target_id = s.id
			AND labels.retired_at IS NULL
			AND s.product_id = OLD.id
		RETURNING labels.uid
	LOOP
		PERFORM cancel_queued_label_jobs(r.uid);
	END LOOP;

	DELETE FROM stock
	WHERE product_id = OLD.id;

	DELETE FROM stock_log
	WHERE product_id = OLD.id;

	DELETE FROM product_barcodes
	WHERE product_id = OLD.id;

	DELETE FROM quantity_unit_conversions
	WHERE product_id = OLD.id;

	DELETE FROM recipes_pos
	WHERE product_id = OLD.id;

	UPDATE recipes
	SET product_id = NULL
	WHERE product_id = OLD.id;

	DELETE FROM meal_plan
	WHERE product_id = OLD.id
		AND type = 'product';

	DELETE FROM shopping_list
	WHERE product_id = OLD.id;

	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'products');

	DELETE FROM product_substitutions
	WHERE from_product_id = OLD.id
		OR to_product_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
