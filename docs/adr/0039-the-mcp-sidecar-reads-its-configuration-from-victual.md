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

## Prerequisite status

Recorded 2026-10-08 against the spike branch `claude/adr0039-prerequisites`, which is
disposable and not mergeable: it takes migration 0307 above plan 22's unwritten 0305 and
0306, so `check-migrations.php` fails on it without `--allow-reserved-holes`. Prerequisite 1
ran on the code at `dbc83da6`. The kind images for prerequisites 2 to 4 came from `529a6fb3`,
which differs from `dbc83da6` only in tests, evidence scripts and one development-tool
exclusion. They were built from the flake's `image-*` outputs in a Nix builder container
(the method `nix/build-in-podman.sh` uses) and tagged `0.3.2-adr39spike` and
`0.3.2-adr39master`; the second is `592e1b66`, the spike's base. Kind ran Kubernetes v1.37 on
one node, in the scratch namespaces `victual-adr39` and `victual-adr39-base`.

| # | Status | Evidence |
|---|---|---|
| 1 | **Met, within what a Mac can run** | Runner coverage rose from 96.41% to 96.43% with no file lower and none under 75%. The PHP and sidecar checks pass. CI's own ratchet run, a PHP 8.4 leg and the other frontend probes were not run. See "Prerequisite 1" |
| 2 | **Met** | On kind, with the official SDK client, `tools/list` changed after a `PUT` with no restart, a just-disabled write tool was refused, and re-enabling it worked. A read-only key read the config. See "Prerequisite 2" |
| 3 | **Met, with numbers that call for a decision** | `GET /api/mcp/config` has a p50 of about 12 ms. It adds about 2 ms to `tools/list` and about 13 ms to `tools/call`. See "Prerequisite 3" |
| 4 | **Met** | A Victual without the route answered 404. The sidecar served `MCP_ENABLED_TOOLS` and logged the fallback once per process. See "Prerequisite 4" |

### Prerequisite 1

The suite ran as CI's `suite` job's first step does: `SUITE_COVERAGE=1
.devtools/pgsql/run-tests.sh`, in the `victual:dev` image (PHP 8.5.10) against the pgTAP
PostgreSQL 16 image, driven through podman, one suite at a time. The coverage report ran
separately with `php -d memory_limit=2560M .devtools/coverage/report.php`, because the
runner's own report runs out of PHP's default 128 MiB. The base and the spike ran the same
way.

| Tree | Executable lines covered | Per cent | Files | Files under 75% |
|---|---|---|---|---|
| `592e1b66`, the base | 12,124 of 12,575 | 96.41 | 153 | 0 |
| The spike | 12,178 of 12,629 | 96.43 | 156 | 0 |

The spike added 54 executable lines and covered all 54. The three new files and the changed
route file are at 100%: `services/McpConfigService.php` 23 of 23,
`controllers/Api/McpConfigApiController.php` 23 of 23, `controllers/McpSettingsController.php`
5 of 5 and `routes.php` 240 of 240. A per-file comparison of the two clover reports found no
file whose coverage fell.

This is the runner's figure, not CI's. `tests.yml` enforces `--min=96.31198844487241217394`
over the runner plus nine separately measured steps, including one that builds the label
renderer with cargo. Those were not run, so the ratchet itself was not evaluated. The
comparison says the change adds covered lines and removes none, which is what the ratchet
needs.

The full suite found two defects that the spike's targeted tests did not, and both are fixed
on the branch. `RbacTest::testEveryGetRouteControllerIsSweptOrExcepted` wants each new
controller classified. The freshly-migrated comparison in `migratedifftest.php` wants each
PostgreSQL-only table listed. A full run of `dbc83da6` has one failure, the one the base tree
has too: the runner's own coverage report running out of memory. All 29 PHPUnit phases and
the differential phases pass.

Also run on the spike:

| Check | Result |
|---|---|
| `php -l` over every PHP file | no syntax errors |
| `check-runtime-sql.php`, `check-path-id-validation.php` | pass; 117 path parameters typed |
| `node --check public/viewjs/mcpsettings.js` | passes |
| Psalm 6.18.0 with `--taint-analysis`, as `psalm.yml` runs it | no issues reported |
| `cd mcp && npm test` | 43 tests, 43 pass (13 are new) |
| `.devtools/frontend/s29-payload.js` against a demo instance | 28 of 28 probes clean |
| `.devtools/frontend/roles.js` | passes |
| `McpAuthTest` | 19 tests, 10 of them new |
| `ContractTest` | `contract-admin.json` gains 16 lines and `contract-restricted.json` 8, all for the new routes |
| `check-migrations.php` | exit 1; with `--allow-reserved-holes`, exit 0 and two warnings |

Not run: a PHP 8.4 leg (the image is 8.5), the other `frontend-security` probes, and
`python3 -m unittest discover -s .devtools/ci`. `s29-payload.js` does not visit
`/mcpsettings`, so the new page was driven separately with Playwright against a demo
instance. It showed nine rows with the three write tools labelled. A toggle sent `PUT
/api/mcp/config` with `{"tools":{"consume_product":true}}` and got 200, and the state
survived a reload. A refused save put the switch back and showed the server's message as
text. The "MCP settings" entry sits in the navigation after "Manage API keys".

Design points the build settled:

- `GET /api/mcp/config` needs no permission beyond a credential. A read-only MCP key reads it
  (200). `PUT` needs ADMIN. A non-administrator's writable key gets 403, and an
  administrator's read-only key gets 403 from `BaseAuthMiddleware`'s read-only refusal
  before the controller runs.
- A session `PUT` with a foreign `Origin` header is refused with 403 and one from this origin
  succeeds. An API key's `PUT` needs no CSRF token, because that check applies to
  session-authenticated writes only.
- The switch values go through `WireBooleans::RequireBoolean()`. It accepts `true`, `false`,
  `1`, `0`, `"1"` and `"0"` (a JSON number reaches it as a string after HTMLPurifier) and
  refuses `"true"`, `"yes"`, `2`, `null`, arrays and the rest. An unknown tool name is a 400
  and the whole request changes nothing.
- `mcp_tool_settings.updated_by` references `users`, so an import's `TRUNCATE users ...
  CASCADE` empties the table and puts every tool back on its default, the write tools off.
  `ImporterTargetOnlyTableRetentionTest` asserts it. The reset goes in the safe direction,
  but the manual should say it.
- `deploy/postgres/roles.sql` needs no change: its `ALTER DEFAULT PRIVILEGES` already grants
  the app role the new table.

### Prerequisite 2

Ran on kind in `victual-adr39`, with Victual and the MCP sidecar (two replicas, no affinity)
from the spike images, an administrator's writable MCP key and the same user's read-only MCP
key. The client was the official SDK v2 client, driven by `mcp/scripts/adr39-demo.mjs`, which
follows `probe.mjs` and adds the REST calls. No pod restarted during the run.

| Step | Result |
|---|---|
| `GET /api/mcp/config` with the read-only key | 200, the six read tools |
| 1. `tools/list`, writable key, all writes off | six tools |
| 2. `PUT consume_product=true` | 200, seven tools in the answer |
| 3. `tools/list`, writable key | seven tools, ending in `consume_product` |
| `tools/list`, read-only key | six tools: the write tool stays hidden |
| 4. `consume_product` (enabled) | success, `transaction_id` returned |
| 5. `PUT consume_product=false` | 200 |
| 6. `consume_product` (just disabled) | `isError`, `{"error":"forbidden","message":"The tool consume_product is turned off in Victual's MCP settings. An administrator can turn it on there."}` |
| stock after the refused call | 4 of 5, so only step 4 booked |
| 7. `tools/list` | six tools |
| 8. `PUT consume_product=true`, then `consume_product` | success, stock 3 |
| 9. `expiring_soon=false` | the read-only key's `tools/list` drops it, and a call is refused as in step 6 |

Decision made in the spike: the refusal is a tool error of category `forbidden`. It is not a
protocol error and not a new category. Spec §7 reserves protocol errors for protocol problems
and lists a closed set of tool-error categories, and `forbidden` ("valid key, insufficient
permission") is the nearest. The message names the cause.

To make it a tool error, the sidecar registers every tool on a `tools/call` request and
gives a disabled one a handler that returns the error. Leaving it unregistered would make the SDK answer "tool not found"
(JSON-RPC -32602), which is what the fallback path still gives for a tool outside
`MCP_ENABLED_TOOLS`.

Decision 6 says the sidecar logs once at startup that it ignores `MCP_ENABLED_TOOLS`. At
startup it has no credential to ask Victual with, so it logs `Victual serves
/api/mcp/config: MCP_ENABLED_TOOLS is ignored` once per process, at the first request. Each
pod logged it once.

To reproduce: `deploy/kind/up.sh` with `VICTUAL_NAMESPACE=victual-adr39` and
`VICTUAL_IMAGE_TAG=0.3.2-adr39spike`; two rows inserted into `api_keys` the way
`McpAuthTest::issueKey()` does; `kubectl port-forward` for `svc/victual` and
`svc/victual-mcp`; then `MCP_URL=… VICTUAL_URL=… ADMIN_KEY=… RO_KEY=… node
mcp/scripts/adr39-demo.mjs`.

### Prerequisite 3

`mcp/scripts/adr39-latency.mjs` measured the cost on the same deployment. It ran in a pod
built from the sidecar's own image, so no `kubectl port-forward` is in the path. The pod sent
requests one at a time: 30 warm-up requests, then 300 timed. For `tools/list` and for
`tools/call stock_overview` it sent JSON-RPC to the sidecar (`2025-11-25`, stateless). It also
timed `GET /api/mcp/config` and `GET /api/user/capabilities` directly against Victual, which
held 61 products in stock.

"Without the fetch" is the sidecar image built from `592e1b66`, swapped in with `kubectl set
image` on the same Victual and database. Three rounds alternated the two images. The first attempt at round 3 with the fetch aborted on a request error whose
message was not kept, and it was rerun; the table has the rerun.

Milliseconds, per round:

| Series | With the fetch, r1 / r2 / r3 | Without, r1 / r2 / r3 |
|---|---|---|
| `tools/list` p50 | 18.31 / 18.01 / 17.90 | 16.22 / 16.12 / 15.65 |
| `tools/list` p95 | 21.78 / 24.18 / 21.56 | 20.41 / 19.66 / 19.01 |
| `tools/call` p50 | 39.99 / 39.60 / 39.80 | 27.09 / 26.87 / 26.29 |
| `tools/call` p95 | 48.37 / 45.60 / 45.44 | 32.94 / 32.97 / 33.15 |

Direct requests to Victual, all six rounds: `GET /api/mcp/config` p50 11.88 to 12.23 and p95
14.06 to 16.70; `GET /api/user/capabilities` p50 12.55 to 12.79 and p95 15.03 to 17.16.

The added cost per round, with minus without:

| | p50 | p95 |
|---|---|---|
| `tools/list` | +2.09, +1.89, +2.25 | +1.37, +4.52, +2.55 |
| `tools/call` | +12.90, +12.73, +13.51 | +15.43, +12.63, +12.29 |

The config request costs what any Victual request costs here, about 12 ms at p50, because
each one is a fresh PHP request. In `tools/list` it runs beside the capability probe that
the list already makes, so it adds about 2 ms. In `tools/call` it has to finish before the
tool's own requests start, so it adds one full request, about 13 ms. That takes a
`stock_overview` call from a p50 of 27 ms to 40 ms, 48% more.

The numbers come from one Apple Silicon laptop running a single-node kind cluster in a podman VM with 4 CPUs and 8 GiB,
with other containers present and PostgreSQL in the same cluster. They show the shape of the
cost. They do not show what a production network adds.

The numbers do not show the design wrong, since a household assistant call at 40 ms is not a
delay anyone sees. They do show that on `tools/call` the cost is a whole extra Victual
request. Two changes would shrink it and neither was built. For a read tool, start the tool's
requests and the config request together and discard the answer if the tool is disabled;
that is unsafe for a write tool, which has to be checked first. Or serve the config and the
capabilities in one request.

### Prerequisite 4

Ran in the scratch namespace `victual-adr39-base`: Victual, web and migrate images from
`592e1b66` (no route), the spike's sidecar swapped in, and the ConfigMap's
`MCP_ENABLED_TOOLS` set to `stock_overview,expiring_soon,consume_product`.

- `GET /api/mcp/config` on that Victual answered `404 {"error_message":"Not found."}`, and
  `GET /api/user/capabilities` answered 200.
- Three `tools/list` calls through the SDK client each returned `stock_overview,
  expiring_soon, consume_product`: the environment's list, filtered by capabilities.
- `tools/call stock_overview` succeeded. `tools/call missing_products`, which is not in the
  environment's list, answered `ProtocolError: Tool missing_products not found` (-32602), as
  it did before this change.
- The pod that served the requests logged `GET /api/mcp/config answered 404 (an older
  Victual): serving the tools in MCP_ENABLED_TOOLS instead` once. The other pod served no
  request and logged nothing.

### What the real change still has to do

- Take the lowest free migration slot (0305 under `migrations/RESERVATIONS.md`'s rule), or
  wait for plan 22, and rename `0307.pgsql.sql` and the reservation row to match.
- Run the full CI, including the ratchet, the label steps and a PHP 8.4 leg.
- Change the sidecar's startup line (`N tool(s) enabled`). It reports the environment's list,
  which is wrong when Victual serves the config.
- Amend spec §8 and `mcp/README.md`, remove `MCP_ENABLED_TOOLS` from the chart in the
  follow-up, and decide whether to build either latency reduction.
- Decide whether an administrator's own writable MCP key may call `PUT /api/mcp/config`. It
  can today, because it is that administrator's credential, and decision 7 already says that
  enabling a tool is not a security boundary.
