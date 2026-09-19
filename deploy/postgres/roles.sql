-- The two database roles a Victual deployment needs, and what each may do.
--
--   psql -v ON_ERROR_STOP=1 \
--        -v db=victual \
--        -v migrate_password="$MIGRATE_PASSWORD" \
--        -v app_password="$APP_PASSWORD" \
--        -f deploy/postgres/roles.sql "postgresql://<superuser>@<host>/victual"
--
-- Run it once, against the database Victual will use, as a superuser. That is the only way it
-- has been run. A lesser role would need at least CREATEROLE, ownership of the database, and
-- membership in victual_migrate (ALTER SCHEMA ... OWNER TO and ALTER DEFAULT PRIVILEGES FOR
-- ROLE both require it, and CREATEROLE does not confer it automatically on every version);
-- this script does not grant that membership.
-- It is safe to run again: roles are created only when missing, their restricted attributes
-- and passwords are reset to what is written here and given, and every grant is repeatable. Run it *after* the first migration as
-- well as before — `GRANT ... ON ALL TABLES` covers what exists, and the default
-- privileges below cover what victual_migrate creates from then on.
--
-- ADR-0010 property 3 asks that a workload holding a database connection hold its own
-- role, least privilege for the one job it does. There are two jobs:
--
--   victual_migrate   Owns the schema and everything in it. Held by the `migrate`
--                     initContainer alone (bin/victual-migrate, bin/victual-db-import).
--                     This is the only role in the deployment that can run DDL.
--   victual_app       Reads and writes rows. Held by the php-fpm container alone. It
--                     cannot create, alter or drop anything, and it cannot TRUNCATE.
--
-- The web tier holds no database credential at all.
--
-- What this deliberately does not do: it does not grant the app role anything on tables
-- it does not need to write (a tighter split — `migrations` read-only, say — would have to
-- be re-applied after every migration, since the default privileges below are the only
-- thing that reaches a table created later). If a deployment wants that, it belongs in a
-- follow-up that names the tables.

\if :{?db}
\else
  \echo 'set -v db=<database name>'
  \quit 1
\endif
\if :{?migrate_password}
\else
  \echo 'set -v migrate_password=<password for victual_migrate>'
  \quit 1
\endif
\if :{?app_password}
\else
  \echo 'set -v app_password=<password for victual_app>'
  \quit 1
\endif

-- Roles. CREATE ROLE has no IF NOT EXISTS, and psql variables are not interpolated inside
-- a DO block's dollar quotes, so the existence test is a query whose result \gexec runs.
-- NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT and NOREPLICATION are spelled out
-- rather than relied on: they are the defaults, and a script whose whole point is what a
-- role cannot do should say so where a reader looks.
-- Names resolve from the system catalogs only, so a schema an earlier role left on the
-- search path cannot shadow format() or pg_roles while this runs as a role that can create roles.
SET search_path = pg_catalog;

SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION', r)
FROM (VALUES ('victual_migrate'), ('victual_app')) AS wanted(r)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = wanted.r)
\gexec

RESET search_path;

-- Every run, not only when the role is created: a role that already existed with SUPERUSER,
-- CREATEDB, CREATEROLE or REPLICATION would otherwise keep it while this script claimed
-- "repeatable".
ALTER ROLE victual_migrate NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
ALTER ROLE victual_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION;
ALTER ROLE victual_migrate PASSWORD :'migrate_password';
ALTER ROLE victual_app PASSWORD :'app_password';

-- Nobody but the two roles connects. The public schema stops being world-creatable on
-- PostgreSQL 15; on 14 and earlier it still is, and this line is what makes the app
-- role's inability to CREATE a fact about this script rather than about the server's
-- version.
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO victual_migrate, victual_app;

-- The migrating role owns the schema. Ownership is what lets it CREATE in it and run
-- DDL against objects it made (victual-db-import also TRUNCATEs and disables triggers,
-- which are owner operations), and it is what makes ALTER DEFAULT PRIVILEGES below apply:
-- default privileges attach to the role that creates an object.
ALTER SCHEMA public OWNER TO victual_migrate;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO victual_app;

-- Rows, not structure. No TRUNCATE (only the importer truncates), no REFERENCES, no
-- TRIGGER — the last would let the role attach code that runs as somebody else.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO victual_app;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO victual_app;

-- The same two grants for everything victual_migrate creates from now on. Without these a
-- migration that adds a table leaves the app role unable to read it, and the failure is a
-- 500 on whichever page first touches the table.
ALTER DEFAULT PRIVILEGES FOR ROLE victual_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO victual_app;
ALTER DEFAULT PRIVILEGES FOR ROLE victual_migrate IN SCHEMA public
  GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO victual_app;
