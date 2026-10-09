# ADR-0039: The MCP sidecar reads its configuration from Victual, and an administrator edits it on a settings page

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-10-08
- **Referenced by:** [plan 02](../plans/02-mcp-endpoint.md), [the MCP interface spec](../mcp-interface-spec.md) §8; would supersede §8's "environment variables only" and the `MCP_ENABLED_TOOLS` ConfigMap entry in `deploy/helm/victual/`

## Context

The MCP sidecar is a stateless container ([spec §2](../mcp-interface-spec.md)). Spec §8
makes environment variables its only configuration: "nothing here is complex enough for a
config file". The maintainer did not make that choice. An agent wrote it into the spec and
the maintainer was not asked. What the maintainer did decide is that the container is
stateless.

Env-only configuration has a cost the write tools made visible. `MCP_ENABLED_TOOLS` is the
switch that turns `consume_product` on for a household, and changing it means editing a
ConfigMap and restarting the pods. Victual already holds every other setting an administrator
changes, in PostgreSQL, behind pages and the `ADMIN` permission.

## Decision

1. **Configuration lives in Victual's database.** The sidecar keeps no state and no
   credential of its own. A stateless container is unchanged by this: it reads its
   configuration the way it already reads stock, from Victual's REST API.
2. **Storage.** One table, `mcp_tool_settings`, one row per tool name: `tool_name` (primary
   key), `enabled` (boolean), `updated_at`, `updated_by` (user id). The application validates
   `tool_name` against the known set; the database does not, so adding a tool needs no
   migration. A tool with no row takes its default: the six read tools enabled, the three
   write tools disabled, which is today's behaviour.
3. **Read path.** `GET /api/mcp/config` returns `{ "enabled_tools": [...] }`. Any
   authenticated key may call it, including a `read_only` MCP key, because the answer is a
   list of tool names. The sidecar calls it with the credential the request forwards, so
   "credentials pass through, never stored" ([spec §8](../mcp-interface-spec.md)) still
   holds. It calls it on every `tools/list` and every `tools/call`, with no cache: a disabled
   tool is refused at call time, and a cache would be state that outlives the request
   ([ADR-0007](0007-auth-state-outlives-the-process.md)). The extra request per call is
   measured before acceptance (prerequisite 3).
4. **Write path and page.** `PUT /api/mcp/config` and a settings page at `/mcpsettings`,
   both requiring `ADMIN`. The page lists the nine tools with a toggle each, says which are
   writes, and links to the API key page where `read_only` is set. The page sits beside
   `/manageapikeys` in the same navigation group.
5. **What stays in the environment.** Only what the sidecar needs before it can ask Victual:
   `VICTUAL_BASE_URL` and `MCP_PORT`. `MCP_REQUEST_TIMEOUT_MS` and `LOG_LEVEL` also stay,
   because they are per-deployment operations settings rather than household choices.
6. **Compatibility.** If `GET /api/mcp/config` answers 404 (an older Victual), the sidecar
   falls back to `MCP_ENABLED_TOOLS`, as it falls back when `GET /api/user/capabilities` is
   404 today. When Victual serves the endpoint the variable is ignored and the sidecar logs
   that once at startup. A later change removes the variable.
7. **Security boundary unchanged.** Enabling a tool is UX. The hard boundary for writes is
   still the per-key `read_only` flag and the user's permissions, enforced inside Victual on
   the forwarded call ([ADR-0006](0006-authenticated-issues-in-scope.md)).

## Consequences

- Turning a write tool on or off is a click, takes effect on the next request, and needs no
  restart. `deploy/helm/victual/` loses the `MCP_ENABLED_TOOLS` value in the follow-up change.
- A new migration, a new table, two API routes in `victual.openapi.json`, one page, and
  strings in `localization/`. The wire contract gains routes and changes none
  ([ADR-0005](0005-wire-contract-is-the-invariant.md)).
- Each tool call costs one more request to Victual. Cold start now needs Victual reachable
  to answer `tools/list`, which it already needs for the capability probe.
- Spec §8 and `mcp/README.md` are amended by the implementation, and the spec records that
  the env-only rule was an agent's, not a decision.

## Open questions

1. **Should timeout and log level move too?** Recommendation: no, per decision 5.
2. **Per-key tool lists.** A household may want one key to see `consume_product` and another
   not. `read_only` already covers the common case. Recommendation: defer.

## Acceptance prerequisites

1. The table, both routes and the page built and covered by the suite, with the coverage
   ratchet held.
2. The sidecar reads the config per request and refuses a disabled tool at `tools/call`,
   demonstrated against a real Victual.
3. The per-request cost measured: p50 added latency of `GET /api/mcp/config` on the kind
   deployment, recorded here.
4. The 404 fallback demonstrated against a Victual without the route.
