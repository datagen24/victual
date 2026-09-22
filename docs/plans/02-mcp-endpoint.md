# 02. MCP endpoint

**Goal:** An integrated MCP server that authenticates against Victual's own user system, so
an assistant can answer "what is expiring this week" or "add milk to the shopping list"
without a separate bridge process.
**Depends on:** per the README's Wave 5 — [11](11-api-error-handling.md),
[13](landed/13-write-path-transactions.md) and [15](landed/15-deliberate-cleanup.md) C1, plus
[14](landed/14-contract-and-regression-scaffolding.md)'s snapshot — all of which gate this plan.

**Status:** superseded in part — the Open-question responses below are settled and now
carried by the full [MCP interface specification](../mcp-interface-spec.md)
(2026-08-29), which fixes the protocol baseline (`2026-07-28`, TypeScript SDK v2), the
sidecar architecture, the auth seam, the v1 toolset with schemas, and the verification
plan. This file remains the decision record; the spec is what the implementation
session builds from. The prior-art `mcp-grocy` fork was evaluated the same day — see
the spec's Appendix A (verdict: rebuild the protocol layer, salvage packaging and
selected logic).

## Today

Victual already has everything needed underneath:

- **API keys** — `services/ApiKeyService.php`, with a `key_type` column already used to
  separate general keys from `special-purpose-calendar-ical`. Keys resolve to a user via
  `GetUserByApiKey()`.
- **Pluggable auth** — `middleware/Auth/`, selected by `VICTUAL_AUTH_CLASS`.
  `DefaultAuthMiddleware` accepts a session cookie *or* a `VICTUAL-API-KEY` header, but only
  for paths starting with `/api/`.
- **Permissions** — 30 constants in `controllers/Users/User.php`, checked per route.
- **Services** — `StockService`, `RecipesService`, `ChoresService` etc. already hold the
  business logic the tools would call.

So this is additive plumbing over existing capability, with no compatibility risk to
anything that exists.

## Proposed change

> **Everything from here to the Open questions is the superseded half of this file, and
> the sections that specify *where the code lives* are the ones that were reversed.** This
> plan proposed an MCP server integrated into the application: a route under `/api/mcp`, a
> controller beside the REST ones, a tool registry under `services/`. Q6's response chose a
> **separate sidecar container** instead, and the
> [MCP interface specification](../mcp-interface-spec.md) is built on that choice — no
> state, no credentials at rest, two replicas indistinguishable, none of which an in-process
> mount can offer on a pod that scales to zero. What survives here is the *reasoning*: the
> auth seam, the permission model, the tool shortlist and the transport constraints are all
> still the argument the spec rests on, and Q1–Q6 are still the decision record. What does
> not survive is the mount point, the file layout and the "purely additive routes" framing.
> They are kept rather than deleted because the spec's design reads as over-engineered
> without the integrated version to compare against — but nothing should be *built* from
> the three sections below.

### Mount point — *superseded, see the note above*

~~Mounting at **`/api/mcp`** rather than `/mcp` means the existing auth middleware already
covers it — `IsApiRoute` is a `/api/` prefix test, so API key authentication works with no
middleware change at all. MCP does not care what the path is.~~

The sidecar has no mount point in this application at all. It is a separate service that
speaks HTTP to the same API any other client uses, which is what makes "two replicas
indistinguishable" achievable — there is no per-connection state in the server to make
them differ.

The sidecar pays a cost the superseded version avoided: it authenticates *as* a user over
the network instead of inheriting a session. That is the credential→user seam Q1 settles,
and sweep **S11** constrains it — the seam does not accept a key from the query string, and
sidecar→server trust follows S4's trusted-proxy pattern rather than a shared header alone.

### Authentication

Add `API_KEY_TYPE_MCP` alongside the existing types, so MCP access is granted and revoked
independently of general API keys. Everything downstream — user resolution, permission
checks — then works exactly as it does for the REST API, which is what "auths against the
internal user system" should mean.

**This is the part needing a decision.** The MCP authorization specification for HTTP
transports is built on OAuth 2.1, with the server acting as an OAuth resource server. A
bearer API key is not that. For a household instance behind your own network the practical
question is only what your client will actually accept:

- Some clients support custom headers or a bearer token directly.
- Some expect a full OAuth flow and will not connect without one.
- A local proxy (`mcp-remote` and similar) can bridge a header-authenticated server to a
  client that wants something else.

I would not design this from memory — the transport and auth revisions move, and my
knowledge has a cutoff. **Before building, confirm against the current spec revision and
against the specific client you intend to use.** See Q1.

**Future state, recorded 2026-08-27: the household IdP will eventually front this.**
The longer term direction is to put the existing IdP in front of Victual: forward-auth at
the k3s ingress for the web UI, which is exactly what `ReverseProxyAuthMiddleware` already
supports. For MCP, the IdP plays the authorization server role the MCP auth spec actually
wants — the MCP endpoint validates the IdP's tokens, maps the subject to a Victual user,
and never issues tokens itself. That is a much better fit than Victual growing its own
OAuth.

Consequence for v1: **build the identity seam, not the auth** — keep "credential →
Victual user" as one small replaceable resolver (bearer API key today, IdP token subject
later) and have everything downstream (permissions, tool gating) work off the resolved
user. If that seam exists, the IdP migration is a resolver swap plus ingress config, not a
redesign.

Caveat when the time comes: clients and IdPs vary in what they support of the auth spec
(resource metadata discovery, dynamic client registration), so verify against the actual
client and IdP then, not from memory.

### Transport

Streamable HTTP, which is what remote MCP servers use. Slim can serve it. Server-initiated
messages are not needed for a request/response tool server, which keeps the implementation
to ordinary POST handling.

### Tools

Start read-only. The compelling household cases are questions, not commands:

| Tool | Backed by |
|---|---|
| `stock_overview` | `uihelper_stock_current_overview` |
| `expiring_soon` | `products_volatile_status` |
| `missing_products` | `stock_missing_products` |
| `find_product` | `products_view` |
| `shopping_list` | `uihelper_shopping_list` |
| `recipes_i_can_cook` | `recipes_resolved.need_fulfilled` |

Then a small, deliberately boring set of writes:

| Tool | Notes |
|---|---|
| `add_to_shopping_list` | Lowest risk write — removing the added entry undoes it |
| `consume_product` | Mutates stock. Gate behind a permission |
| `purchase_product` | Same, plus prices |

Each tool checks the acting user's permissions exactly as the equivalent REST route does.
A read-only user gets read-only tools.

**Three of the read tools above already carry prices**, which matters once
[19](19-rbac.md) exists: `stock_overview` reads `uihelper_stock_current_overview`, which
selects `value`, `last_price` and `average_price` (`migrations/0252.sql:38-39`);
`recipes_i_can_cook` reads `recipes_resolved`, which carries `costs`, `costs_per_serving`
and `prices_incomplete`; and `shopping_list` reads `uihelper_shopping_list`, which carries
`last_price_unit`, `last_price_total` and `price` (`migrations/0251.sql:7-9`). The other
three carry none — `products_volatile_status` and `stock_missing_products` have no price
column, and `products_view`'s `qu_factor_price_to_stock` is a unit factor rather than a
price.

Question 5's answer keeps them out of the payload by construction — hand-built
name/amount/due-date responses rather than raw view rows — which is protection by accident
rather than by policy. 19 makes it policy: the tool registry declares its response fields,
and the ones annotated `x-visibility` drop for a key whose user lacks the leaf. Since the
sidecar resolves its key to a user and every REST call is permission-checked as that user
(question 6's response), redaction is inherited and needs no MCP-specific mechanism.

The residual is a key-management question rather than a permissions one, and it is this
plan's: **one shared MCP key means one user's price visibility for every household member the
assistant talks to.** Either the key is per person, or its user holds no
`STOCK_PRICES_VIEW`.

### Where the code goes — *superseded, see the note above*


~~`controllers/Api/McpController.php` for the protocol, `services/Mcp/` for the tool
registry, so tools are declared in one place with their schema, permission and handler
rather than scattered through a switch.~~

Not in this repository. The sidecar is its own deployable; see the spec's Appendix A for
what the `mcp-grocy` prior art contributes to it (verdict: rebuild the protocol layer,
salvage packaging and selected logic). The one idea worth carrying across is the second
half of the struck sentence — tools declared in one place with schema, permission and
handler together, rather than a switch — which the spec adopts.

### API

Additive, and additive in a smaller way than this plan first claimed: **no new routes in
this server at all**. The sidecar consumes the existing REST surface, so what 02 needs
from this repository is the surface being complete and frozen — [14](landed/14-contract-and-regression-scaffolding.md)
piece 2 — rather than new endpoints. One new API key type is the whole server-side change.

**Client impact: none.** No existing endpoint changes. A new `key_type` value is additive
to `/objects/api_keys` consumers, of which there are none outside this fork.

## Open questions

1. **Auth mechanism** — bearer API key, or full OAuth 2.1 as the MCP auth spec describes?
   API key is a fraction of the work and fits a household instance; OAuth is what the spec
   wants and what some clients require. This should be answered by testing the client you
   actually plan to use, not by reading (or remembering) the spec. It is the one thing that
   could invalidate the rest of the plan. *Update:* the future-state note above narrows
   this — API key for v1 behind a replaceable credential→user resolver, IdP-backed OAuth
   later.

   > **Response:** Bearer API key for v1, and let the ingress do the rest — the
   > future-state note above records the full direction. Still test against the
   > actual client before building.
2. **Read-only first, or writes from the start?** I strongly favour read-only for v1. The
   read tools are where most of the value is, they cannot damage anything, and they let the
   transport and auth get proven before an assistant is allowed to consume stock.

   > **Response:** Read-only, strongly.
3. **Should writes need a separate permission**, beyond the user's existing ones? A user
   who may consume via the UI may not want an assistant doing it unprompted. A
   `MCP_WRITE` permission is cheap and makes the boundary explicit.

   > **Response:** A per-key read-only flag, not a new `MCP_WRITE` permission
   > constant. It matches Q1's design — a bearer key behind the credential→user
   > resolver, where scoping lives with the key rather than the user's global
   > permission set — and it avoids threading a new `PERMISSION_*` constant through
   > the 30 already in `controllers/Users/User.php`. Revocation and scoping then live
   > in one place, the key management screen.
4. **Exposure.** Local network only, or reachable externally? That changes the auth answer
   considerably, and interacts with how the k3s ingress is set up. *Update:* external
   exposure, if it ever happens, waits for the IdP future state — the ingress + IdP then
   owns that boundary, not Victual.

   > **Response:** Local network/tailnet only until there is a concrete reason; the
   > future-state note covers what changes if that ever flips.
5. **Tool granularity.** A few broad tools ("query stock") or many narrow ones? Narrow
   tools are easier for a model to use correctly and easier to permission, at the cost of a
   longer tool list.

   > **Response:** Narrow, roughly the table above (6–10 tools). Keep responses
   > deliberately small — return name/amount/due-date fields, not raw view rows. Raw
   > `uihelper_*` rows are wide, and token economy is a real constraint for the
   > consuming model.
6. **Is a built-in server the right shape at all?** A standalone MCP server talking to the
   existing REST API would need no Victual changes and could be updated independently as the
   spec moves. Building it in gets native permission handling and one less moving part.
   Worth being explicit about the tradeoff, because "integrated" was the stated goal but
   the spec churn is a real argument for keeping it separable.

   > **Response:** Separate container — and answer this question first, because it
   > reshapes the rest. The MCP protocol layer (streamable HTTP, JSON-RPC framing,
   > session lifecycle) has mature official SDKs in TypeScript and Python and none
   > in PHP; building and tracking it by hand in Slim is the largest and least
   > durable part of the integrated design. A sidecar calling the REST API with an
   > `API_KEY_TYPE_MCP` key gets independent deploys as the spec moves, an SDK doing
   > the transport, and a second small scale-to-zero service — exactly the pattern
   > being practiced. "Authenticates against Victual's own user system" is still
   > satisfied: the key resolves to a user and every REST call is permission-checked
   > as that user. What is lost is in-process permission granularity, which Q3's
   > answer covers. If it stays integrated anyway, the `/api/mcp` mount trick is
   > elegant and correct.

## Effort

Medium, dominated by Q1 and Q6 rather than by the code. Read-only tools over the existing
services are straightforward once the transport and auth are settled; the tool registry and
permission wiring is a day or two beyond that.
