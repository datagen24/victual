-- Category level minimum stock: a product group can carry a minimum of its own, so that
-- "always have some milk" does not require inventing a parent product to hang it on.
-- See docs/plans/03-category-min-stock.md.
--
-- DOUBLE PRECISION, not INTEGER. products.min_stock_amount is declared INTEGER upstream and
-- demonstrably holds 2.5 (db/pgsql/README.md hazard 2); the new column means the same kind of
-- thing and is typed for what it holds rather than for what its ancestor was declared as.
--
-- WHY A NEW VIEW. stock_missing_products stays exactly as it is. Every row of it is keyed by
-- a product id and StockService::AddMissingProductsToShoppingList() uses that id to add a
-- shopping list entry; a group shortfall has no single product to add, so a third branch
-- there would either carry a null id through code that dereferences it or invent a product
-- nobody chose. Keeping group shortfalls in their own view is also what leaves
-- /stock/volatile's response shape untouched (ADR-0005), which is the point rather than a
-- side effect - plan 03 question 1 chose it for that reason.
--
-- THE AGGREGATION, AND THE TRAP IN IT. The member sum reads the `stock` table directly and
-- never stock_current. stock_current's first branch already rolls sub product stock up into
-- the parent, so summing it across a group that contains both a parent and one of its
-- children counts the child's stock twice - once as its own row and once inside the parent's
-- amount_aggregated. Reading the ledger per product cannot do that, and it stays correct when
-- plan 07 makes the product tree deep. The same choice answers two questions nobody has to
-- ask separately: a child that is not in the group contributes nothing through a parent that
-- is, and a no_own_stock parent contributes nothing at all, because neither has stock rows of
-- its own to sum.
--
-- WHERE THE ACTIVE FILTER GOES, AND WHY IT IS LOAD BEARING. The member filter is inside the
-- derived table, so it removes *members*; the group filter is in the outer WHERE, so it
-- removes *groups*. Written the other way round - one outer
-- "AND COALESCE(p.active, 0) = 1" covering both - a group whose only members are inactive
-- would match no rows at all and vanish from the result, which every reader would take to
-- mean "fully stocked". It means the opposite: nothing active is stocked, so the group is
-- short by its entire minimum. An empty group reaches the same answer down the same path,
-- which is why the join is LEFT and the sum is wrapped in COALESCE.
--
-- OPENED STOCK. Discounted per member product, mirroring all three branches of
-- stock_missing_products, and only for members that set treat_opened_as_out_of_stock. A group
-- has no such setting and is not being given one; the flag belongs to the product and is
-- applied where the product is summed. Plan 03 question 5.
--
-- THE OUTER FILTER. amount_missing > 0, matching stock_missing_products' own trailing filter
-- rather than emitting every group with a minimum and leaving callers to compare. A group at
-- exactly its minimum is not short.
--
-- Hazard 3 does not apply: every CASE WHEN here evaluates to a number, and no comparison,
-- EXISTS or IS NULL is projected into a SELECT list, so nothing arrives at json_encode as a
-- PostgreSQL boolean.
--
-- No ENGINE_EXCLUSIVE_TABLES entry and no @engine-exclusive marker. db/pgsql/README.md's rule
-- that every new table above the freeze has to be named in migratedifftest.php is about
-- tables: that phase enumerates BASE TABLE only and compares the intersection of the two
-- engines' columns, so a PostgreSQL-only column on a shared table and a PostgreSQL-only view
-- are both already invisible to it. The marker is asked for only below the freeze, where a
-- lone engine-specific file could be a missing counterpart rather than the only file that
-- could exist.
--
-- The baseline in db/pgsql/baseline/ is deliberately not edited, for the reason
-- migrations/0261.pgsql.sql gives: it is defined as the state SQLite reaches after migrations
-- 0001-0255, and a fresh PostgreSQL database loads it and then runs 0256 onwards. Changing
-- both would apply this twice.

ALTER TABLE product_groups ADD COLUMN min_stock_amount DOUBLE PRECISION NOT NULL DEFAULT 0;

CREATE VIEW product_groups_missing AS
SELECT *
FROM (
	SELECT
		pg.id,
		pg.name,
		pg.min_stock_amount,
		pg.min_stock_amount - COALESCE(SUM(member.effective_amount), 0) AS amount_missing
	FROM product_groups pg

	-- LEFT, and the member filter lives in here rather than in the outer WHERE. A group with
	-- no active members - or no members - joins to nothing, the SUM is NULL, the COALESCE
	-- makes it 0, and the group is short by its whole minimum.
	LEFT JOIN (
		SELECT
			p.id,
			p.product_group_id,
			COALESCE(SUM(s.amount), 0)
				- CASE WHEN p.treat_opened_as_out_of_stock = 1
					THEN COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount ELSE 0 END), 0)
					ELSE 0 END AS effective_amount
		FROM products p
		LEFT JOIN stock s
			ON s.product_id = p.id
		WHERE COALESCE(p.active, 0) = 1
		GROUP BY p.id, p.product_group_id, p.treat_opened_as_out_of_stock
	) member
		ON member.product_group_id = pg.id

	WHERE pg.min_stock_amount != 0
		AND COALESCE(pg.active, 0) = 1
	GROUP BY pg.id, pg.name, pg.min_stock_amount
) x
WHERE x.amount_missing > 0;
