-- Acceptance experiment only; run inside a transaction and roll back.
CREATE OR REPLACE VIEW product_groups_missing AS
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
			resolved.ancestor_product_group_id AS product_group_id,
			COALESCE(SUM(s.amount), 0)
				- CASE WHEN p.treat_opened_as_out_of_stock = 1
					THEN COALESCE(SUM(CASE WHEN s.open = 1 THEN s.amount ELSE 0 END), 0)
					ELSE 0 END AS effective_amount
		FROM products p
		JOIN product_groups_resolved resolved
			ON resolved.descendant_product_group_id = p.product_group_id
		LEFT JOIN stock s
			ON s.product_id = p.id
		WHERE COALESCE(p.active, 0) = 1
		GROUP BY p.id, resolved.ancestor_product_group_id, p.treat_opened_as_out_of_stock
	) member
		ON member.product_group_id = pg.id

	WHERE pg.min_stock_amount != 0
		AND COALESCE(pg.active, 0) = 1
	GROUP BY pg.id, pg.name, pg.min_stock_amount
) x
WHERE x.amount_missing > 0;
