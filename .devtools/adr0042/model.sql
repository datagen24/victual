-- SPIKE ONLY. The ADR-0042 section 1 and 7 records as scratch DDL, plus the derived views the
-- probes query. Loaded after estimate.sql into a scratch schema. Not a migration; table and
-- column names are the spike's, since the ADR leaves them to issue 701.

CREATE TABLE recipes (id bigserial PRIMARY KEY);

CREATE TABLE refill_fills (
	id bigserial PRIMARY KEY,
	recipe_id bigint NOT NULL REFERENCES recipes (id) ON DELETE CASCADE,
	filled_on date NOT NULL,
	supplied_days integer NOT NULL CHECK (supplied_days BETWEEN 1 AND 730),
	note text,
	voided_at timestamptz,
	void_reason text,
	CONSTRAINT void_pair CHECK ((voided_at IS NULL) = (void_reason IS NULL)),
	UNIQUE (recipe_id, id)
);

CREATE TABLE refill_orders (
	id bigserial PRIMARY KEY,
	recipe_id bigint NOT NULL REFERENCES recipes (id) ON DELETE CASCADE,
	ordered_on date NOT NULL,
	state text NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'received', 'cancelled')),
	received_fill_id bigint,
	CONSTRAINT received_has_fill CHECK ((state = 'received') = (received_fill_id IS NOT NULL)),
	FOREIGN KEY (recipe_id, received_fill_id) REFERENCES refill_fills (recipe_id, id)
);
-- "A partial unique index allows at most one open order per recipe."
CREATE UNIQUE INDEX refill_one_open_order ON refill_orders (recipe_id) WHERE state = 'open';

CREATE TABLE refill_settings (
	recipe_id bigint PRIMARY KEY REFERENCES recipes (id) ON DELETE CASCADE,
	rule_kind text CHECK (rule_kind IN ('days_before_end', 'fixed_interval', 'fraction_elapsed')),
	rule_param numeric,
	explicit_date date,
	explicit_fill_id bigint,
	lead_days integer CHECK (lead_days BETWEEN 0 AND 60),
	FOREIGN KEY (recipe_id, explicit_fill_id) REFERENCES refill_fills (recipe_id, id),
	CONSTRAINT explicit_pair CHECK ((explicit_date IS NULL) = (explicit_fill_id IS NULL)),
	CONSTRAINT rule_pair CHECK ((rule_kind IS NULL) = (rule_param IS NULL)),
	CONSTRAINT rule_range CHECK (
		rule_kind IS NULL
		OR (rule_kind = 'days_before_end' AND rule_param BETWEEN 0 AND 730 AND rule_param = trunc(rule_param))
		OR (rule_kind = 'fixed_interval' AND rule_param BETWEEN 1 AND 730 AND rule_param = trunc(rule_param))
		OR (rule_kind = 'fraction_elapsed' AND rule_param > 0 AND rule_param < 1))
);

CREATE TABLE notice_acks (
	user_id bigint NOT NULL,
	notice_key text NOT NULL,
	acknowledged_at timestamptz NOT NULL DEFAULT clock_timestamp(),
	PRIMARY KEY (user_id, notice_key)
);

-- "The current fill is the unvoided fill with the greatest filled_on, then the greatest id."
CREATE VIEW refill_current_fill AS
	SELECT DISTINCT ON (recipe_id) *
	FROM refill_fills
	WHERE voided_at IS NULL
	ORDER BY recipe_id, filled_on DESC, id DESC;

-- Section 7's reading of the explicit date: stored with the fill it was set for, and applied only
-- while that fill is the current one. (Section 2 reads as an event that ends it; see RESULTS.md.)
CREATE VIEW refill_state AS
	SELECT r.id AS recipe_id, f.id AS fill_id, f.filled_on, f.supplied_days, s.lead_days,
		s.explicit_fill_id, s.explicit_date,
		(s.explicit_fill_id IS NOT NULL AND s.explicit_fill_id = f.id) AS explicit_applies,
		e.reorder, e.source, e.reason
	FROM recipes r
	LEFT JOIN refill_current_fill f ON f.recipe_id = r.id
	LEFT JOIN refill_settings s ON s.recipe_id = r.id
	CROSS JOIN LATERAL adr42_estimate(
		CASE WHEN s.explicit_fill_id = f.id THEN s.explicit_date END,
		s.rule_kind, s.rule_param, f.filled_on, f.supplied_days) e;

-- Section 6: kinds and keys. "approaching: status is approaching or later and no order is open";
-- "due: status is due and no order is open". Key: <recipe_id>:<fill_id>:<kind>:<reorder_date>.
CREATE FUNCTION adr42_notices(as_of date, default_lead integer)
RETURNS TABLE (recipe_id bigint, kind text, notice_key text, reorder date) LANGUAGE sql STABLE AS $$
	SELECT s.recipe_id, k.kind, s.recipe_id || ':' || s.fill_id || ':' || k.kind || ':' || s.reorder, s.reorder
	FROM refill_state s
	CROSS JOIN LATERAL adr42_status(s.reorder, COALESCE(s.lead_days, default_lead), as_of,
		EXISTS (SELECT 1 FROM refill_orders o WHERE o.recipe_id = s.recipe_id AND o.state = 'open')) st
	CROSS JOIN LATERAL (VALUES ('approaching'), ('due')) k(kind)
	WHERE (k.kind = 'approaching' AND st.status IN ('approaching', 'due'))
	   OR (k.kind = 'due' AND st.status = 'due')
$$;
