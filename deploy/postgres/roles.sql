-- The two database roles a Victual deployment needs, and what each may do.
--
--   read -rs MIGRATE_PASSWORD; read -rs APP_PASSWORD     # or $(op read …): never typed inline
--   export MIGRATE_PASSWORD APP_PASSWORD
--   psql -v ON_ERROR_STOP=1 -v db=victual \
--        -f deploy/postgres/roles.sql "postgresql://<superuser>@<host>/victual"
--
-- The two passwords come from psql's environment, MIGRATE_PASSWORD and APP_PASSWORD, and
-- from nowhere else. A `-v migrate_password=…` argument is discarded, and without the
-- environment variable the run is refused. A process's arguments are visible to every user
-- of the machine (`ps`), and on a Kubernetes node to anything that can list its processes;
-- its environment is readable only by the same user and root. Reading the environment
-- needs psql 15 or later (`\getenv`); the server's version does not matter.
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

-- A refusal is a failed statement, so psql exits non-zero. `\quit` cannot do that: it
-- takes no exit status and leaves with 0, which a calling script reads as success.
-- ON_ERROR_STOP is set here as well as on the command line, so a caller that forgets it
-- still stops at the first refusal or error.
\set ON_ERROR_STOP on
\if :{?db}
\else
  DO $$ BEGIN RAISE EXCEPTION 'roles.sql: set -v db=<database name>'; END $$;
\endif
-- psql sets VERSION_NUM to its own version. \getenv is psql 15's; an older client would
-- stop at it with "invalid command", which names the symptom rather than the cause.
SELECT :VERSION_NUM >= 150000 AS psql_has_getenv \gset
\if :psql_has_getenv
\else
  DO $$ BEGIN RAISE EXCEPTION 'roles.sql needs psql 15 or later, to read the passwords from the environment'; END $$;
\endif
-- Unset first: \getenv leaves a variable alone when the environment lacks it, so a value
-- given with -v would otherwise survive and the argument form would keep working.
\unset migrate_password
\unset app_password
\getenv migrate_password MIGRATE_PASSWORD
\getenv app_password APP_PASSWORD
\if :{?migrate_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'roles.sql: put the victual_migrate password in psql''s environment as MIGRATE_PASSWORD, not -v'; END $$;
\endif
\if :{?app_password}
\else
  DO $$ BEGIN RAISE EXCEPTION 'roles.sql: put the victual_app password in psql''s environment as APP_PASSWORD, not -v'; END $$;
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
