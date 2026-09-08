-- Label history deliberately has no foreign keys into imported tables (ADR-0021).
CREATE TABLE label_import_state (
	id INTEGER PRIMARY KEY CHECK (id = 1),
	epoch BIGINT NOT NULL CHECK (epoch >= 0)
);
INSERT INTO label_import_state VALUES (1, 0);

CREATE FUNCTION label_current_import_epoch() RETURNS BIGINT
LANGUAGE sql STABLE AS $$ SELECT epoch FROM label_import_state WHERE id = 1 $$;

ALTER TABLE locations ADD COLUMN import_epoch BIGINT NOT NULL DEFAULT label_current_import_epoch();

CREATE TABLE labels (
	uid TEXT PRIMARY KEY CHECK (uid ~ '^[0-9A-F][0-9A-HJKMNP-TV-Z]{12}$'),
	kind TEXT NOT NULL CHECK (kind IN ('location', 'product', 'stock_entry')),
	target_id BIGINT,
	row_created_timestamp TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
	retired_at TIMESTAMPTZ,
	retirement_snapshot JSONB,
	CHECK ((retired_at IS NULL AND target_id IS NOT NULL AND retirement_snapshot IS NULL)
		OR (retired_at IS NOT NULL AND target_id IS NULL AND retirement_snapshot IS NOT NULL))
);
CREATE UNIQUE INDEX labels_one_live_per_target ON labels (kind, target_id) WHERE retired_at IS NULL;

-- Issuance locks the location row before inserting. DELETE holds that same row lock,
-- so its retirement and the deletion commit together even outside the generic API.
CREATE FUNCTION retire_location_labels() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
	UPDATE labels SET retired_at = CURRENT_TIMESTAMP, target_id = NULL,
		retirement_snapshot = jsonb_build_object('id', OLD.id, 'name', OLD.name)
	WHERE kind = 'location' AND target_id = OLD.id AND retired_at IS NULL;
	RETURN OLD;
END
$$;
CREATE TRIGGER retire_location_labels BEFORE DELETE ON locations
FOR EACH ROW EXECUTE FUNCTION retire_location_labels();
