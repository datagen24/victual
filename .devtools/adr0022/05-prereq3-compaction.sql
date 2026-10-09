-- Acceptance prerequisite 3: a measured entry is skipped while an unmeasured split entry
-- is merged, in the same run.

\echo '--- before: three rows sharing every stock_splits group-by column, one measured ---'
SELECT stock_id, amount, opened_amount FROM stock
	WHERE stock_id IN ('p1-split-a', 'p1-split-b', 'p1-split-c') ORDER BY stock_id;

\echo '--- stock_splits candidates: only the two UNMEASURED rows form a group (count > 1) ---'
SELECT product_id, total_amount, stock_id_to_keep, id_group, stock_id_group FROM stock_splits;

\echo '--- run the compaction CompactStockEntries() performs (services/StockService.php:2504-2552), for real ---'
-- Same two loops as the PHP method, run for every stock_splits group at once: first rename
-- every row in the group onto stock_id_to_keep, then delete every row but id_to_keep and
-- set that survivor's amount to the group total.
DO $$
DECLARE
	grp RECORD;
	sid TEXT;
	rid INTEGER;
BEGIN
	FOR grp IN SELECT * FROM stock_splits LOOP
		FOREACH sid IN ARRAY string_to_array(grp.stock_id_group, ',') LOOP
			IF sid <> grp.stock_id_to_keep THEN
				UPDATE stock SET stock_id = grp.stock_id_to_keep WHERE stock_id = sid;
			END IF;
		END LOOP;
		FOREACH rid IN ARRAY string_to_array(grp.id_group, ',')::INTEGER[] LOOP
			IF rid <> grp.id_to_keep THEN
				DELETE FROM stock WHERE id = rid;
			ELSE
				UPDATE stock SET amount = grp.total_amount WHERE id = rid;
			END IF;
		END LOOP;
	END LOOP;
END $$;

\echo '--- after: the two unmeasured rows merged into one (amount = 2); the measured row untouched ---'
SELECT stock_id, amount, opened_amount FROM stock
	WHERE stock_id IN ('p1-split-a', 'p1-split-b', 'p1-split-c') ORDER BY stock_id;
