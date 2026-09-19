# Victual MCP interface specification

**Status:** v1 specification, written 2026-08-29. Companion to
[plan 02](plans/02-mcp-endpoint.md), whose Open-question responses this document takes as
settled: separate container (02-Q6), read-only v1 (02-Q2), bearer API key behind a
credential→user seam (02-Q1/Q3), local network only (02-Q4), narrow tools with compact
responses (02-Q5).
**Gated by:** per the roadmap's Wave 5 — [11](plans/11-api-error-handling.md),
[13](plans/13-write-path-transactions.md), [15](plans/15-deliberate-cleanup.md) C1, and
[14](plans/14-contract-and-regression-scaffolding.md)'s response-contract snapshot.
**Prior art:** the `datagen24/mcp-grocy` fork was evaluated for reuse on 2026-08-29;
the verdict (rebuild the protocol layer, salvage the packaging and selected handler
logic) is recorded in [Appendix A](#appendix-a-evaluation-of-the-mcp-grocy-fork).

## 1. Protocol baseline

**Target protocol revision `2026-07-28`, with `2025-11-25` served side by side.**

The MCP specification was rewritten between these revisions, and the changes land
directly on this design — almost all of them in its favor:

- **The protocol is now stateless.** The `initialize`/`initialized` handshake and the
  `Mcp-Session-Id` header are gone; every request carries its protocol version and
  client capabilities in `_meta`. A request/response tool server behind a
  scale-to-zero ingress is exactly what this revision was written for: no session
  affinity, no state lost on pod restart, plain POST handling.
- **`server/discover` is mandatory** — a single RPC advertising supported versions,
  capabilities, and identity.
- **Streamable HTTP requires `Mcp-Method` and `Mcp-Name` request headers**, and list
  results must carry `ttlMs`/`cacheScope` caching hints. All results carry a
  `resultType` field.
- **Server-initiated requests are replaced by multi-round-trip results**
  (`resultType: "input_required"`). Not needed here — no tool in this spec asks the
  user anything mid-call.
- **Roots, Sampling, and Logging are deprecated; the HTTP+SSE transport is deprecated;
  SSE resumability is removed.** None are used by this design.

The official TypeScript SDK v2 (released with the spec: `@modelcontextprotocol/server`
with the `@modelcontextprotocol/express` or `/node` adapter, plus
`@modelcontextprotocol/core`) implements all of the above and can serve `2026-07-28`
and `2025-11-25` clients simultaneously. The v1 SDK line (`@modelcontextprotocol/sdk`,
which the prior-art fork pins) tops out at `2025-11-25` and receives only maintenance
fixes for a limited window — new work starts on v2.

> **Amended 2026-09-19, first build (issue #86).** The package names above are right:
> `@modelcontextprotocol/server`, `/node` and `/core`, all `2.0.0`. The scaffold's
> `package.json` had pinned `@modelcontextprotocol/sdk ^2.0.0`, which does not exist —
> that line stops at 1.30. The v2 entry point is `createMcpHandler(factory)`. It builds a
> fresh `McpServer` per request and serves both revisions from one factory. The SDK
> *client* defaults to the 2025 handshake unless it opts into `versionNegotiation`, so a
> client that negotiates `2025-11-25` against this server says nothing about what the
> server supports.

**Implementation language is TypeScript** — settled by 02-Q6: the SDK does the protocol
so the fork never tracks spec churn by hand. Zod v4.2+ for schemas (a v2 SDK
requirement; input and output schemas are written once in Zod and serve as both
validators and the JSON Schema the client sees).

## 2. Architecture

One small stateless HTTP service ("the sidecar"), deployed as its own container next to
Victual, speaking MCP on one side and Victual's REST API on the other.

```
MCP client ──(Streamable HTTP, Authorization: Bearer <mcp key>)──> sidecar
sidecar ──(REST, VICTUAL-API-KEY: <same key>)──> Victual /api
```

- **No state.** No sessions, no database, no disk. Anything the sidecar knows it
  learned from the request or from a REST call it made while serving it. Two replicas
  behind a round-robin ingress must be indistinguishable from one.
- **No credentials at rest.** The sidecar stores no Victual API key. The bearer token the
  client presents *is* the Victual API key, forwarded on every REST call. Victual validates
  it and permission-checks every operation as the key's user — which is what
  "authenticates against Victual's own user system" (fork goal 3) means in the sidecar
  shape.
- **The credential→user seam lives in one module.** `resolveCredential(request) →
  outbound auth headers` is the single place that knows the bearer token is a Victual API
  key. The IdP future state (02's 2026-08-27 note) replaces this module — validate the
  IdP's token, map subject to a Victual user, attach whatever server-to-server credential
  that design lands on — and touches nothing else.

## 3. Transport

- **Streamable HTTP at `POST /mcp`**, via the SDK's transport. The SDK owns the
  `Mcp-Method`/`Mcp-Name` header handling, version negotiation, and
  `server/discover`.
- **stdio** for local development and `npx`-style personal use only; the deployed
  artifact is the HTTP container.
- **Not implemented:** the legacy HTTP+SSE transport (deprecated), `subscriptions/listen`
  (nothing here changes server-side while a client watches; the tool list is fixed per
  deployment), tasks, MRTR.
- `GET /healthz` — unauthenticated liveness for k8s probes; returns `200` with an empty
  body. It does not report name, version, or configuration (the prior art leaks these).

**Identity:** `serverInfo.name = "victual-mcp"`, `title = "Victual"`, version from the
release. Sent in every result's `_meta` per the stateless spec.

## 4. Authentication

### 4.1 Client → sidecar

Requests to `/mcp` MUST carry `Authorization: Bearer <key>` (canonical; the raw
`VICTUAL-API-KEY: <key>` header is also accepted for parity with Victual itself). A request
with neither is answered `401` before any JSON-RPC processing. A key that is present
but invalid is discovered on the first forwarded REST call and surfaces as a tool
error naming the cause (§7) — the sidecar performs no validation of its own, because
that would mean caching validity, which is state.

**Conformance posture, recorded deliberately:** the MCP authorization framework
(OAuth 2.1 resource server, RFC 9728 protected-resource metadata) is *optional* in the
spec — a server that does not implement it is non-conformant with the authorization
spec only, not with MCP. This sidecar does not implement it in v1. Consequences:

- Clients must support a static custom header for HTTP MCP servers (the Claude
  clients do). A client that insists on the OAuth discovery flow gets `404` from
  `/.well-known/oauth-protected-resource` and cannot connect without an `mcp-remote`
  style bridge. Per 02-Q1: test the actual client before building.
- When the household IdP fronts this (02's future-state note), the sidecar becomes a
  real resource server: serve RFC 9728 metadata pointing at the IdP, validate its
  tokens, and swap the resolver module. That is the moment the authorization spec's
  MUSTs start applying — verify against the then-current revision, not this document.

### 4.2 Sidecar → Victual, and the Victual-side changes

The forwarded key must be an **MCP-type** key, so that MCP access is granted and
revoked independently of general API keys. This is the whole of the Victual-side work,
and it is small:

1. **`ApiKeyService::API_KEY_TYPE_MCP = 'mcp'`** alongside the two existing constants
   (`services/ApiKeyService.php:15-16`).
2. **`ApiKeyAuthenticator` accepts MCP-type keys.** Today the header path validates
   against `API_KEY_TYPE_DEFAULT` only (`ApiKeyAuthenticator::Authenticate`, via
   `IsValidApiKey`'s default parameter) — an MCP-type key in `VICTUAL-API-KEY` is
   currently rejected. The authenticator checks the header against both `default` and
   `mcp` types. Everything downstream — `GetUserByApiKey`, the 30 permission constants,
   per-route checks — is untouched and already works.

   **This is smaller than it was when the spec was written, and one part of it is now
   decided rather than free.** Wave 2's 15-C1 replaced `ApiKeyAuthMiddleware` with
   `ApiKeyAuthenticator`, a plain object with one method, so the change is to one class
   that does one job. But wave 2 also made regular keys **stored as a SHA-256 hash**
   (plan 11, question 4): an MCP key is a regular key in that sense, so
   `ApiKeyService::StoredValueOf()` decides what the lookup compares, and the key is
   readable exactly once — at creation. A sidecar that expects to read its key back out
   of the database will not be able to.
3. **A per-key `read_only` flag** (02-Q3's response): one column on `api_keys`, set at
   creation from the key-management screen. Enforcement is one rule in the same
   middleware: a request authenticated by a `read_only` key that is not `GET`, `HEAD`,
   or `OPTIONS` is answered `403`. This is deliberately server-side — the sidecar also
   gates (write tools are not registered in v1 at all), but the boundary that matters
   is enforced where the key is validated, so a compromised or buggy sidecar cannot
   exceed it. The migration is a portable dual-engine `NNNN.sql` per the roadmap's
   ground rules.
4. **Key management UI**: the existing `/manageapikeys` screen grows a type selector
   (default/MCP) and the read-only checkbox for MCP keys.
5. **A self-describing capabilities endpoint**, `GET /api/user/capabilities`: returns
   `{ key_type, read_only, permissions: [...] }` for the *acting* credential, callable
   by any authenticated key about itself. This exists because capability-reflecting
   tool listing (§5, Open question 2) needs it and nothing provides it today —
   `GET /api/users/{id}/permissions` is ADMIN-gated
   (`controllers/Api/UsersApiController.php:210`), so an ordinary key cannot learn its
   own permission set. New endpoint rather than new fields on `GET /api/user`, per the
   roadmap's additive-API ground rule.

> **Amended 2026-09-19, as built (issue #208).**
>
> 1. **`read_only` is migration `0287.pgsql.sql`**, a `SMALLINT` 0/1 column. It is
>    PostgreSQL-only, not the "portable dual-engine" file item 3 describes: ADR-0008
>    froze the SQLite line at 0265.
> 2. **The 403 lives in `BaseAuthMiddleware`**, beside the cross-origin check.
>    - It covers every method but GET, HEAD and OPTIONS.
>    - It also covers the three upstream GET routes that write: the calendar sharing
>      link, which creates a key; the external barcode lookup, which can create a
>      product; and the thermal print.
> 3. **A request header, `VICTUAL-API-KEY-TYPE`, can narrow the key lookup to one
>    type.** The sidecar sends `mcp`. Without it, a regular key forwarded through the
>    sidecar would work exactly as an MCP key does, and §11.3's "valid default-type key
>    → rejected" row would have nothing to hold it. The header only narrows: a type
>    outside regular and MCP matches nothing.
> 4. **`GET /api/user/capabilities`** answers `key_type: null` and `read_only: false` for
>    a session. Its `permissions` are the resolved `*_VIEW`-style names from
>    `controllers/Users/User.php`, sorted.
> 5. **MCP keys otherwise behave like regular keys:** hashed at rest, finite expiry,
>    rotatable. Rotation keeps the type and the read-only flag.

Scoping and revocation therefore live in exactly one place, the key row — no new
`PERMISSION_*` constant, per 02-Q3.

## 5. Tools — v1 (read-only)

Six tools, the plan's table made concrete. Shared conventions first:

- **Naming**: `snake_case`, verb-or-noun phrases an assistant can pick from a list
  without documentation. No namespace prefixes — this server does one thing.
- **Inputs** are minimal; every field optional unless stated. All list tools take
  `limit` (default and maximum noted per tool); everything is capped because token
  economy on the consuming side is a design constraint (02-Q5).
- **Outputs**: every tool returns `structuredContent` conforming to a declared
  `outputSchema`, plus one human-readable `text` block summarizing it in a sentence
  (counts, not rows). Rows are *shaped* — the named fields below, never raw REST/view
  rows. `uihelper_*`-width rows and embedded `product{}` objects stop at the sidecar.
- **Dates** are `YYYY-MM-DD` strings; Victual's sentinel `2999-12-31` ("never expires")
  is translated to `due_date: null`.
- **Amounts** are numbers in the product's stock unit, with the unit's display name
  resolved via one `GET /api/objects/quantity_units` per request (id→name map built
  per call; it is small).
- **`tools/list` reflects what the key can actually do** (Open question 2, adopted).
  Serving the list, the sidecar calls `GET /api/user/capabilities` (§4.2 item 5) with
  the request's credential and filters the config-enabled tools by a static tool →
  permission map: a tool is listed only when the key's user holds its permission, and
  write tools (§6) additionally require the key not be `read_only`. The map for this
  spec's nine tools: `STOCK` for `stock_overview`/`expiring_soon`/`missing_products`/
  `find_product`, `SHOPPINGLIST` for `shopping_list`, `RECIPES` for
  `recipes_i_can_cook`, `SHOPPINGLIST_ITEMS_ADD` for `add_to_shopping_list`,
  `STOCK_CONSUME` for `consume_product`, `STOCK_PURCHASE` for `purchase_product`
  (constants from `controllers/Users/User.php`). One probe per `tools/list` request —
  stateless, nothing cached across requests. `tools/call` does *not* probe: Victual
  enforces on the forwarded call, and a race (permission revoked between list and
  call) surfaces as an honest `forbidden` (§7).
  > **Amended 2026-09-19.** The permission names in that map predate
  > [plan 19](plans/19-rbac.md)'s read/write split. The rule stated first — each tool
  > checks what "the equivalent REST route" checks — is the one that holds, and those
  > routes check the `*_VIEW` leaves. The map as built is therefore `STOCK_VIEW`
  > (`stock_overview`, `expiring_soon`, `missing_products`, `find_product`),
  > `SHOPPINGLIST_VIEW` (`shopping_list`) and `RECIPES_VIEW` (`recipes_i_can_cook`). The
  > write tools' `SHOPPINGLIST_ITEMS_ADD`, `STOCK_CONSUME` and `STOCK_PURCHASE` are already
  > leaves and stand as written.
- **`tools/list` order and caching**: fixed, deterministic order; `ttlMs: 300000`,
  `cacheScope: "private"` — the list now varies per key, so it is private by
  necessity, and five minutes bounds how long a revoked permission or flipped
  read-only flag keeps a stale tool visible in a client. Tool results carry no cache
  hints.

### 5.1 `stock_overview`

What is in stock right now.

| | |
|---|---|
| Input | `product_group_id?: number` · `limit?: number` (default 100, max 200) |
| Backed by | `GET /api/stock` |
| Output row | `product_id, name, amount, amount_opened, unit, due_date, product_group_id` |

Rows sorted by `due_date` ascending, nulls last, so truncation by `limit` keeps the
urgent entries. `text` block: "212 products in stock; showing 100, soonest due …".

### 5.2 `expiring_soon`

The "what is expiring this week" question, answered directly.

| | |
|---|---|
| Input | `days?: number` (default 5, max 30) · `limit?: number` (default 50 per section) |
| Backed by | `GET /api/stock/volatile?due_soon_days={days}` |
| Output | `{ due: Row[], overdue: Row[], expired: Row[] }`, Row = `product_id, name, amount, unit, due_date` |

Three sections, not three tools — a model asking about expiry wants all three
severities in one answer.

### 5.3 `missing_products`

Below minimum stock.

| | |
|---|---|
| Input | `limit?: number` (default 50, max 200) |
| Backed by | `GET /api/stock/volatile` → `missing_products` |
| Output row | `product_id, name, amount_missing, is_partly_in_stock` |

Note for later: [plan 03](plans/03-category-min-stock.md) deliberately keeps group
shortfalls out of `stock_missing_products`; when 03 lands, group shortfalls become a
new section here (additive), not a change to this row shape.

### 5.4 `find_product`

Resolve a human product name to ids the other tools take.

| | |
|---|---|
| Input | `query: string` (required) · `limit?: number` (default 10, max 25) |
| Backed by | `GET /api/objects/products?query[]=name~{query}` + `GET /api/stock` for amounts |
| Output row | `product_id, name, product_group_id, unit, in_stock_amount` |

Substring match server-side; no fuzzy layer in v1 (the prior art's Fuse.js layer is a
candidate later if substring proves insufficient in use — recorded, not built).

### 5.5 `shopping_list`

| | |
|---|---|
| Input | `shopping_list_id?: number` (default 1) · `limit?: number` (default 100, max 200) |
| Backed by | `GET /api/objects/shopping_list` (+ `products`, `quantity_units` for names) |
| Output row | `item_id, product_id, name, amount, unit, done, note` |

`shopping_list_id` is already plural-ready for
[plan 05](plans/05-store-shopping-lists.md); when 05 lands, a `list` descriptor
(`id, name, shopping_location_id`) section is added to the output (additive).

### 5.6 `recipes_i_can_cook`

| | |
|---|---|
| Input | `limit?: number` (default 25, max 50) |
| Backed by | `GET /api/recipes/fulfillment` + `GET /api/objects/recipes` for names |
| Output | `{ can_cook: Row[], missing_little: Row[] }`, Row = `recipe_id, name, missing_products_count` |

`can_cook` = `need_fulfilled`; `missing_little` = `need_fulfilled_with_shopping_list`
minus the first set. Only `type = normal` recipes (not meal-plan shadow recipes — see
Appendix A for why that internal naming convention must never be load-bearing again).

## 6. Tools — deferred writes (specified now, built later)

Per 02-Q2 these ship only after read-only v1 has proven the transport in use, and
after [13](plans/13-write-path-transactions.md) is in place. Specified here so the
write wave is an implementation task, not a design task:

| Tool | Input | Backed by |
|---|---|---|
| `add_to_shopping_list` | `product_id` (req) · `amount?` (default 1) · `shopping_list_id?` · `note?` | `POST /api/stock/shoppinglist/add-product` |
| `consume_product` | `product_id` (req) · `amount` (req) · `spoiled?: boolean` | `POST /api/stock/products/{id}/consume` |
| `purchase_product` | `product_id` (req) · `amount` (req) · `price?` · `due_date?` · `shopping_location_id?` | `POST /api/stock/products/{id}/add` |

**On `shopping_location_id`, which reads worse than `store_id` and is the right name
anyway.** Earlier drafts of this table said `store_id`, and the household vocabulary is
"store". But [05](plans/05-store-shopping-lists.md)'s Q4 and
[15](plans/15-deliberate-cleanup.md)'s Q5 both considered renaming
`shopping_locations` → `stores` and both declined: it is an `ExposedEntity` name, a table,
a column on `stock`, `stock_log` and `shopping_list`, several views, and ~250 references
across 63 files — the largest compatibility break available in the fork, for a nicer noun.
A sidecar parameter named `store_id` mapping to a REST field named `shopping_location_id`
is precisely the two-names-forever cost that decision refused, paid in a second place
where nobody would look for it. The tool takes the field's real name. If 15-B3 is ever
batched, this row moves with everything else.

Write-tool rules, fixed now:

- Listed only when the deployment enables them in config (§8) **and** the presenting
  key clears the §5 capability filter (not `read_only`, user holds the tool's
  permission) — a read-only key never sees them at all. The hard boundary remains the
  per-key `read_only` flag enforced inside Victual (§4.2); the config gate and the list
  filter are UX, not security.
- `consume_product` and `purchase_product` return the stock `transaction_id` in
  `structuredContent`, and the `text` block says how to undo ("this can be undone in
  Victual's stock journal") — the REST undo endpoints exist and an assistant that just
  consumed the wrong thing should be able to say so accurately.
- No write tool ever creates a product implicitly. The prior art's "smart" auto-create
  path is exactly the kind of surprise 02-Q2's caution exists to prevent; product
  creation stays in the UI until a deliberate future decision.

## 7. Error model

Two layers, never mixed:

- **Protocol errors** (JSON-RPC error responses) only for protocol problems: unknown
  tool, invalid params against the input schema, unsupported protocol version. The SDK
  emits these; the sidecar adds none of its own numeric codes (the spec now reserves
  `-32020`…`-32099`; nothing here needs a custom code).
- **Tool errors** (`isError: true` results) for everything that happens while doing the
  work, shaped so a model can act on them:

  ```json
  { "error": "forbidden", "message": "The API key's user lacks STOCK_EDIT.", "victual_status": 403 }
  ```

  `error` is one of `unauthorized` (bad/expired key — the user must fix the client
  config), `forbidden` (valid key, insufficient permission or read-only key on a
  write), `not_found` (id does not exist), `invalid_request` (Victual rejected the
  parameters), `victual_unavailable` (connect/timeout — retryable), `victual_error`
  (5xx — not retryable without human attention). The `text` rendering states the
  category and whether retrying can help.

This mapping is why [plan 11](plans/11-api-error-handling.md) gates this work: today
Victual answers 400 for "not allowed" and 500 for "bad filter" on some routes, which
collapses `forbidden`/`invalid_request`/`victual_error` into mush. The sidecar maps
status codes, it does not parse error prose — so the mapping is only as good as the
codes, and 11 is what makes the codes true.

## 8. Configuration

Environment variables only — the deployment is a container with a ConfigMap, and
nothing here is complex enough for a config file:

| Variable | Meaning | Default |
|---|---|---|
| `VICTUAL_BASE_URL` | Victual origin, e.g. `http://victual.victual.svc:80` | required |
| `MCP_PORT` | listen port | `3000` |
| `MCP_ENABLED_TOOLS` | comma-separated allowlist; `all-read` keyword = the six §5 tools | `all-read` |
| `MCP_REQUEST_TIMEOUT_MS` | per-REST-call timeout | `10000` |
| `LOG_LEVEL` | `error`/`warn`/`info`/`debug`, to stderr/stdout | `info` |

Notably absent, on purpose: `VICTUAL_API_KEY` (credentials pass through, §2 — a stored
key would make every unauthenticated LAN caller into that user), TLS settings (TLS is
the ingress's job; the sidecar speaks plain HTTP inside the cluster and never disables
certificate verification), and the prior art's per-tool YAML with `ack_token`s and
routing defaults (v1 has no per-tool options; if a future smart-workflow layer needs
instance-specific routing maps, that is the one thing that would justify a config
file, and Appendix A records the requirement that it be *validated and read*, not
silently discarded).

Startup validates the whole environment and exits non-zero with a message on any
unknown `MCP_ENABLED_TOOLS` entry or missing required variable.

## 9. Deployment

- One container, distroless-or-alpine Node image, non-root, listening on `MCP_PORT`.
  Scale-to-zero on k3s is the practiced pattern; statelessness (§2, §3) is what makes
  it safe. Cold start is the sidecar's Node boot plus — on the first REST call —
  Victual's own cold start, which is [plan 10](plans/10-cold-start-statelessness.md)'s
  problem and another reason 10 precedes Wave 5.
- Exposure per 02-Q4: cluster/tailnet only. The ingress route for `/mcp` is not
  published externally; if that ever flips, the IdP future state (§4.1) owns the
  boundary first.
- Logs to stdout/stderr only (MCP Logging is deprecated anyway); no metrics endpoint
  in v1.

## 10. Non-goals for v1

Recorded so their absence reads as decided, not forgotten: prompts and resources
(nothing here needs them; the prior art's `prompts` capability bug in Appendix A shows
the cost of declaring what you don't serve), sampling/roots/logging (deprecated in the
current spec), tasks and MRTR, `subscriptions/listen`, response streaming beyond what
the SDK does natively, OAuth (§4.1), fuzzy search (§5.4), localization of names and
`text` blocks (English v1), and any write beyond §6's three.

## 11. Verification

> **Status, 2026-09-19** (issue #86). Run against a production-mode Victual on kind
> (Kubernetes v1.37) through `deploy/kind/up.sh`, using the official SDK v2 client
> (`mcp/scripts/probe.mjs`):
>
> - **(2) Done except the capability filter, with one wording correction.** `server/discover`
>   lists only `"2026-07-28"` in `supportedVersions`, not both revisions as (2) expects.
>   `server/discover` is itself a 2026-era method, so a 2025 client never sends it; that
>   client is served `2025-11-25` through the legacy fallback. Both revisions are served,
>   and all six tools return schema-valid `structuredContent` over each.
> - **(3) Partly done.**
>   - A request with no header is 401 before any JSON-RPC.
>   - A garbage key gets an `unauthorized` tool error.
>   - The default-type-key and read-only rows wait on issue #208.
>   - So does the listing filter. It is built and unit-tested against a fake Victual, but
>     it has no Victual endpoint to call yet.
> - **(5) Done.** Two replicas behind a ClusterIP Service, no affinity: 40 of 40
>   good-key calls answered correctly. A 30-call bad-key run, whose failures each pod
>   logs, showed the split: 13 calls on one pod, 17 on the other.
> - **(1) and (4) are open.**
>
> One shaping field was added beyond the tables above: `total`, on `stock_overview`,
> `missing_products` and `shopping_list`. Each counts the rows before `limit` is applied,
> so a truncated answer says so ("212 products in stock; showing 100").

Per the roadmap's standard — booted-instance checks, not lint:

1. **Contract tests against recorded fixtures** from
   [14](plans/14-contract-and-regression-scaffolding.md)'s response-contract snapshot:
   each tool's REST consumption is replayed against the frozen fixtures, so a Victual
   response-shape change breaks the sidecar's CI, not a household conversation.
2. **MCP Inspector** against a compose stack (sidecar + Victual + PostgreSQL, demo
   dataset): `server/discover` reports both protocol revisions; `tools/list` matches
   `MCP_ENABLED_TOOLS` filtered by the presenting key's capabilities (§5), ordered
   deterministically, with cache hints; each §5 tool
   returns schema-valid `structuredContent`; results carry `resultType` and
   `serverInfo`.
3. **Auth and capability matrix** on the same stack: no header → 401 pre-JSON-RPC;
   garbage key → `unauthorized` tool error; valid default-type key → rejected (only
   MCP-type keys pass, proving §4.2 item 2); MCP-type read-only key + (future) write
   tool → 403 from Victual surfacing as `forbidden`. For the §5 listing filter: two keys
   whose users differ in one permission (e.g. `RECIPES`) get `tools/list` responses
   differing in exactly that tool; revoking a permission is reflected in the next
   `tools/list`, and a `tools/call` on the now-hidden tool still answers `forbidden`
   rather than anything worse.
4. **The actual client** (02-Q1's standing instruction): connect the Claude client in
   real use with the bearer header, ask the two motivating questions — "what is
   expiring this week", "what can I cook tonight" — and read the transcripts for
   token cost of the responses; §5's `limit` defaults are tuned from this, not
   guessed.
5. **Two-replica soak**: run two sidecar pods behind round-robin with no affinity and
   repeat (2) — any failure means state crept in.

## 12. Repository bootstrap

> **Amended 2026-09-19, see Open Question 1's amendment above: this section is kept as
> reference material, not as what was built.** The sidecar lives in this repository at
> `mcp/`, not in a new `victual-mcp` repository, so nothing below about a separate repo,
> its own CI/release pipeline, or an imported `Dockerfile` was carried out. What *did*
> transfer: tools declared one file per concern (`src/tools/<name>.ts`), matching the
> skeleton's shape, and the packaging ideas worth remembering if this decision is ever
> revisited. See `mcp/README.md` for the actual layout and status.

Per Open question 1's response, the sidecar starts as a **new repository**, importing
packaging from `datagen24/mcp-grocy` rather than rebasing inside it. The parent fork's
rename settled on **Victual** ([plan 16](plans/16-project-rename.md)), so the repo is
`victual-mcp` outright rather than under a working name; `serverInfo.name` (§3) is that
name and is frozen from the first tagged release.

**Import from mcp-grocy** (all MIT; keep upstream attribution in LICENSE):

- `.github/workflows/test.yml`, `release.yml`, `publish-docker.yml`,
  `version-checks.yml` and `.releaserc.json` — the semantic-release + GHCR publish
  pipeline, adjusted for the new package name.
- `Dockerfile` — per Open question 3's response the Home Assistant add-on is retired,
  so the multi-target `BUILD_FROM` machinery is *not* imported: the Dockerfile
  collapses to the plain Node/Alpine target, non-root, `tini` entrypoint. The add-on
  files (`config.yaml`, `build.yaml`, `rootfs/`, `scripts/update-ha-addon-version.js`)
  stay behind in mcp-grocy.
- The `inspector` npm script (`npx @modelcontextprotocol/inspector`) and the *idea* of
  `scripts/check-tools.js` — a release gate asserting the tool census matches the
  spec — rewritten against this spec's registry.

**Skeleton** — one file per concern, mirroring the section that specifies it:

```
src/
  main.ts            boot: parse env (§8), wire, listen
  config.ts          zod env schema; exit non-zero on invalid (§8)
  server.ts          SDK v2 server + adapter, /mcp + /healthz (§3)
  auth/resolver.ts   credential→outbound-headers seam (§2, §4) — the IdP swap point
  victual/client.ts    REST client: base URL, timeout, §7 status→category mapping
  victual/shape.ts     row shaping + unit-name resolution + date sentinels (§5)
  tools/<name>.ts    one per tool: zod input/output schema + handler (§5, later §6)
tests/
  fixtures/          imported from plan 14's response-contract snapshot
  contract/          replay tests per tool (§11.1)
  tools/             handler unit tests (mocked client)
compose.yaml         sidecar + Victual + PostgreSQL + demo data, for §11.2–11.5
```

No `build/` artifacts in git, no YAML config file, no stored credentials — the three
recurring hygiene findings from Appendix A, stated as rules.

## Open questions

1. **Where does the sidecar live?** The existing `datagen24/mcp-grocy` repo carries
   working release/CI and Home Assistant add-on packaging worth keeping, but this spec
   shares almost no code with it (Appendix A). Options: a v3 rewrite branch in that
   repo inheriting the packaging, or a fresh `victual-mcp` repo importing the packaging
   files. Either satisfies the spec; the repo choice is about release hygiene.

   > **Response:** New repo (2026-08-29) — the parent fork was itself being renamed,
   > so a rewrite branch inside `mcp-grocy` would have tied the new server to two stale
   > names at once. That rename has since landed on **Victual**
   > ([plan 16](plans/16-project-rename.md)), so the new repo is `victual-mcp`. §12
   > records what gets imported.
   >
   > **Amended 2026-09-19.** Reversed: the sidecar's TypeScript source lives in this
   > repository, at `mcp/`, built by this flake as a fourth Nix image
   > (`nix/mcp.nix`, `.#image-mcp`) alongside `image-app`/`image-web`/`image-migrate`.
   > Two things moved this answer after it was written. First,
   > [ADR-0013](adr/0013-nix-built-container-images.md), accepted 2026-09-04 — five
   > days after this response — names this plan by number in its *Would affect* list
   > and states the general rule directly: "a Go or TypeScript sidecar is a
   > `buildGoModule` or `buildNpmPackage` away from an image with the same uid,
   > labels, empty `/bin` and checks. That is the payoff for deciding before the
   > family exists rather than after." Second, when work on this issue actually tried
   > to create `datagen24/victual-mcp`, the GitHub App installed for the session
   > lacked repository-creation scope (`403 Resource not accessible by integration`),
   > which made the new-repo path a blocker rather than a preference in the moment it
   > mattered. Faced with that, ADR-0013's precedent was the deciding argument, not
   > merely a tiebreaker: this repository already builds two other non-PHP workloads
   > (`labelRenderer`, `labelWorker`) by pinning their *own* repositories as flake
   > inputs and building images from the pin — a shape this decision could have taken
   > too. It did not, because those two exist for a reason specific to them (ADR-0019
   > decision item 1: the driver matrix and device transport are deliberately kept
   > out of this tree), and no equivalent reason applies to the sidecar, which has no
   > device to drive and no logic Victual's own release cadence would need to
   > outrun.
   >
   > **What this costs**, honestly: the independent release cadence, semantic-release
   > pipeline and Home Assistant-adjacent packaging habits `mcp-grocy` would have
   > contributed under §12's original import list do not apply to an in-repo
   > workload, and are not built. §12 below is retained for its packaging ideas
   > (Dockerfile structure, `.releaserc.json` conventions) as reference material, the
   > same way the fork itself is Appendix A's reference material, but the "Repository
   > bootstrap" and "Skeleton" sections describe a layout this plan no longer builds.
   > See `mcp/README.md` for what actually exists.
2. **Should `tools/list` reflect the key's capabilities** once writes exist — i.e.
   hide write tools from read-only keys? Requires the sidecar to learn the key's flag
   (a `GET /api/user`-adjacent probe or a header echo), which is a per-request lookup
   in a stateless server. Cheap enough, but deferred with the writes themselves.

   > **Response:** Adopted (2026-08-29), and promoted from "once writes exist" to a
   > v1 behavior: the design is in §5 (per-request probe of
   > `GET /api/user/capabilities`, static tool→permission map, 5-minute private
   > cache hint) and the enabling Victual endpoint is §4.2 item 5. From day one a key
   > whose user lacks `RECIPES` simply doesn't see `recipes_i_can_cook`; when writes
   > land their filtering costs nothing new.
3. **Does the Home Assistant add-on shape survive?** It is a packaging of the stdio/
   stored-key model this spec removes (§8). If HA is still a consumer, it would need
   the HTTP+bearer shape like every other client — worth deciding before porting the
   add-on config forward.

   > **Response:** Retired (2026-08-29). The sidecar lives alongside the Victual
   > instance in the k3s cluster; anything (Home Assistant included) that wants it
   > speaks HTTP+bearer to that one deployment like every other client. §12's import
   > list drops the add-on scaffolding accordingly.

---

## Appendix A: evaluation of the mcp-grocy fork

Evaluated 2026-08-29 at `/Users/speterson/src/mcp-grocy`, branch
`feature/mvp-smart-workflow-tools` (one commit, `16a3e6c`, atop upstream
`miguelangel-nubla/mcp-grocy` at `fc5f18b`, 2025-09-21; uncommitted SDK bump
`^1.11.4 → ^1.25.2` in the working tree).

**Verdict: rebuild the protocol layer on SDK v2; salvage packaging, test discipline,
and selected handler logic as reference material.** The fork is a useful catalog of
what a Victual MCP server can do, and its release/HA-add-on plumbing is real work worth
keeping. Its protocol core predates two spec rewrites and fights this spec's
architecture at every layer that matters.

### Protocol layer — two generations behind, with hand-rolled parts

- Hand-rolled `initialize` handler (`src/server/mcp-server.ts:104-114`) that
  *overwrites the SDK's own*, echoes whatever version the client sends, falls back to
  hardcoded `2024-11-05`, and never populates client capabilities. The current
  protocol has no `initialize` at all.
- Deprecated `SSEServerTransport` still wired (`src/server/http-server.ts:146,197`);
  the Streamable HTTP path implements POST only and mints per-session UUIDs — the
  session model the current spec removed.
- A shared-instance concurrency bug: `serverFactory` returns the same `Server` object
  for every connection (`mcp-server.ts:197`), and each `connect()` rebinds its one
  transport — concurrent sessions clobber each other. Untested: there are no tests for
  the HTTP server, transports, or sessions at all (the ~284 existing tests are
  axios-mock unit tests of handlers).
- Declares a `prompts` capability with no `prompts/list` handler (`mcp-server.ts:72`)
  — clients that believe the declaration get MethodNotFound.
- No auth of any kind on the HTTP transport: no bearer check, `origin: '*'` CORS, no
  DNS-rebinding protection, and a stored single `GROCY_API_KEY` that makes every
  reachable caller into that Victual user — the exact model §4 exists to replace.
- Hand-written JSON Schema literals, no `outputSchema`, results as
  `JSON.stringify(raw, null, 2)` text blocks — the opposite of §5's shaping, and the
  configured `response_size_limit` is never enforced.

### Product layer — a different design philosophy

- 56 tools against this spec's six-then-nine, including `system_dev_call_api` /
  `system_dev_test_request`: arbitrary-endpoint passthrough (enabled in the MVP
  config) that bypasses tool gating entirely and embeds the live base URL in a tool
  description.
- The user's smart-workflow commit (`16a3e6c`) is the most interesting part — "I
  bought X / used X" resolution is a real UX win — but its implementation hardcodes
  one specific instance: numeric location/unit/group ids
  (`src/tools/inventory/handlers.ts:172-187`), consumption preference orders
  (`:437-456`), English keyword regexes with at least two matching bugs
  (`/stock|sauce|…/` misclassifies "chicken stock"-adjacent names; the `prescription`
  branch is unreachable). The `grocy.defaults` YAML block written to configure all
  this is silently stripped by the config schema and read by nothing.
- Load-bearing Victual internals: meal-plan shadow-recipe lookup by the
  `YYYY-MM-DD#id` *name* convention, hard-failing when absent — precisely the
  coupling a REST-consuming sidecar must not have against a fork whose internals
  drift.
- Per-tool sub-config (`recipes_cooking_complete`) is read under the wrong key and
  never takes effect; its validator is registered but never invoked.
- No Victual version/capability probe anywhere — mismatches surface as raw HTTP errors
  at call time.

### What to carry forward

1. **Packaging**: Dockerfile structure (plain target only — the HA add-on is retired
   per Open question 3), semantic-release setup, the MCP Inspector npm script.
2. **Test discipline**: per-handler unit tests with mocked REST are the right shape
   for §11's fixture-driven contract tests; the coverage habit transfers even though
   the tests themselves mostly don't.
3. **Ideas, re-specified before reuse**: config-gated tool exposure (became §8's
   allowlist), fuzzy product search (§5.4, deferred), smart workflows (deferred until
   they can be config-driven — the `grocy.defaults` block is the germ of that config,
   and the lesson is that config must be schema-validated and provably read).
4. **The endpoint census** (§3 of the survey): its dedup'd list of Victual REST calls is
   a ready-made map of what a fuller tool surface eventually touches.
