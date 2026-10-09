-- mcp_tool_settings: which MCP sidecar tools an administrator has switched on or off
-- (ADR-0039 decision 2).
--
-- One row per tool name. The application validates tool_name against the known set
-- (McpConfigService::KNOWN_TOOLS); the database does not, so adding a tool needs no
-- migration. A tool with no row takes its default in code: the six read tools on, the three
-- write tools off.
--
-- updated_by is the administrator who last changed the row. ON DELETE SET NULL keeps the
-- setting when that user is deleted; the setting is household configuration, not the user's.
-- Consequence for DatabaseImporter: its TRUNCATE ... CASCADE on `users` also empties this
-- table, so an import resets the tool switches to their defaults (write tools off), which is
-- the safe direction.
--
-- enabled is a real BOOLEAN. No generic REST route or view reads this table, so the
-- SMALLINT-flag convention of the inherited tables (ADR-0027) has no client to protect.
--
-- PostgreSQL only, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, per
-- ADR-0008's retirement. The app role's grants come from deploy/postgres/roles.sql's
-- ALTER DEFAULT PRIVILEGES for tables victual_migrate creates; nothing to add there.

CREATE TABLE mcp_tool_settings (
	tool_name TEXT PRIMARY KEY,
	enabled BOOLEAN NOT NULL,
	updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
	updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);
