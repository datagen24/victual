-- ADR-0029: bounded blocking, validated atomically with the migration record.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE locations, stock IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    dangling_count bigint;
    sample text;
BEGIN
    SELECT count(*) INTO dangling_count
    FROM stock s LEFT JOIN locations l ON l.id = s.location_id
    WHERE s.location_id IS NOT NULL AND l.id IS NULL;

    IF dangling_count > 0 THEN
        SELECT string_agg(format('(%s, %s, %s)', id, product_id, location_id), ', ' ORDER BY id)
        INTO sample FROM (
            SELECT s.id, s.product_id, s.location_id
            FROM stock s LEFT JOIN locations l ON l.id = s.location_id
            WHERE s.location_id IS NOT NULL AND l.id IS NULL
            ORDER BY s.id LIMIT 10
        ) dangling;
        RAISE EXCEPTION 'Migration refused: % stock rows reference missing locations. Sample (stock id, product id, location id): %. Choose an explicit repair and rerun migration. No stock has been deleted or relocated.', dangling_count, sample
            USING HINT = 'List all references: SELECT s.id, s.product_id, s.location_id FROM stock s LEFT JOIN locations l ON l.id = s.location_id WHERE s.location_id IS NOT NULL AND l.id IS NULL ORDER BY s.id;';
    END IF;
END $$;

CREATE INDEX stock_location_id_idx ON stock (location_id);
ALTER TABLE stock ADD CONSTRAINT stock_location_id_fkey
    FOREIGN KEY (location_id) REFERENCES locations (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;
