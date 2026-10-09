-- SPIKE ONLY. Scratch tables shaped like ADR-0040's description, created in a disposable
-- schema by the probes. Not a migration, not a design for issue 698.
CREATE TABLE a40_recipes (
    id serial PRIMARY KEY,
    owner_id integer NOT NULL REFERENCES users (id),
    name text NOT NULL
);
CREATE TABLE a40_recipe_lines (
    recipe_id integer NOT NULL REFERENCES a40_recipes (id) ON DELETE CASCADE,
    product_id integer NOT NULL,
    amount numeric NOT NULL,
    PRIMARY KEY (recipe_id, product_id)
);
CREATE TABLE a40_recipe_shares (
    recipe_id integer NOT NULL REFERENCES a40_recipes (id) ON DELETE CASCADE,
    user_id integer NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    right_read boolean NOT NULL DEFAULT true,
    right_consume boolean NOT NULL DEFAULT false,
    right_edit boolean NOT NULL DEFAULT false,
    right_undo boolean NOT NULL DEFAULT false,
    right_share boolean NOT NULL DEFAULT false,
    granted_by integer REFERENCES users (id),
    granted_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (recipe_id, user_id)
);
CREATE TABLE a40_consume_events (
    id serial PRIMARY KEY,
    transaction_id text NOT NULL,
    recipe_id integer REFERENCES a40_recipes (id) ON DELETE SET NULL,
    user_id integer NOT NULL
);
CREATE TABLE a40_log (
    id serial PRIMARY KEY,
    scen text NOT NULL,
    iter integer NOT NULL,
    actor text NOT NULL,
    op text NOT NULL,
    txid xid NOT NULL,
    begin_at timestamptz NOT NULL,
    lock_at timestamptz,
    outcome text NOT NULL,
    detail text
);
