# ADR-0052: The MCP sidecar can build the catalog and count stock, and the chart exposes it on the private network

- **Status:** Accepted, 2026-10-23
- **Decider:** datagen24
- **Recorded:** 2026-10-23, against `master` at `7b0660bc` (release 0.5.3). Design only: it
  changes no code, reserves no migration number and amends no accepted record.
- **Referenced by:** [plan 02](../plans/02-mcp-endpoint.md),
  [the MCP interface spec](../mcp-interface-spec.md) §6, §8, §9 and §10,
  [issue 86](https://github.com/datagen24/victual/issues/86)
- **Relationship:** amends the spec's §6 ("product creation stays in the UI until a
  deliberate future decision") and §10 ("any write beyond §6's three"). That text is in
  the spec, not in an accepted ADR, so this record is that deliberate decision. It keeps
  plan 02 Q4 (private network or tailnet only) and spec §10.1 /
  [ADR-0015](0015-medication-records-never-advises.md) unchanged. It sits beside the Proposed
  [ADR-0039](0039-the-mcp-sidecar-reads-its-configuration-from-victual.md): when that one is
  built, the chart value in decision 4 becomes its fallback. Stock-booking tools use the
  `Idempotency-Key` header of accepted
  [ADR-0046](0046-stock-bookings-accept-an-optional-idempotency-key.md). Creating master data
  needs `MASTER_DATA_ADD`, the right accepted
  [ADR-0043](0043-creating-shared-master-data-is-a-right-below-master-data-edit.md) placed
  below `MASTER_DATA_EDIT`. When the Proposed
  [ADR-0044](0044-api-keys-carry-a-subset-of-their-owners-permissions.md) is built, a key
  scoped to fewer permissions hides the matching tools through the same capability filter,
  so a household can issue a key that counts stock but cannot change the catalog.

## Context

The sidecar in 0.5.3 has nine tools: six reads and three writes (`add_to_shopping_list`,
`consume_product`, `purchase_product`). Each of the writes takes an existing `product_id`.
No tool can create a product, a location, a quantity unit or a barcode, and none can set
a stock amount.

The production instance went live on 2026-10-22 with an almost empty database. The
household wants to build its catalog by talking: walk the pantry, say what is on the
shelf, and have the assistant create the products and record the amounts. On an empty
database the 0.5.3 read tools return empty lists, and every write fails because the
product does not exist. The REST API can already do everything this needs. The sidecar
just doesn't expose it.

Two deployment facts block real use as well:

- **The enabled-tool list is fixed in the chart.** `deploy/helm/victual/templates/_helpers.tpl`
  hard-codes `MCP_ENABLED_TOOLS: "all-read"`. No values file can turn a write tool on, so
  the only way to enable one is to edit the rendered ConfigMap by hand, and the next
  `helm upgrade` reverts that edit.
- **The chart gives the sidecar no route in.** The sidecar's Service is ClusterIP only, so
  every client today needs `kubectl port-forward`.

Since 2026-10-23, production's public hostname reaches Victual through Cloudflare's edge
rather than at a private address. The path is the WAF, then Bot Fight Mode, then Cloudflare
Access, then a `cloudflared` tunnel running in the cluster, then Traefik, then Victual.
Checked on 2026-10-23: the hostname resolves to Cloudflare addresses, and a request with no
edge credentials gets a `302` to the Access login.

- **Access** signs browsers in with Entra ID. Native apps present a per-device Access
  service token (`CF-Access-Client-Id` and `CF-Access-Client-Secret`) alongside the Victual
  API key.
- **The WAF** allows only US and Canadian traffic, and only the methods GET, HEAD, POST,
  PUT, PATCH, DELETE and OPTIONS.

The operator's brief for VictualKit draws the layering this record follows. The edge
credential identifies a *device* to Cloudflare. The API key, and later an OIDC token,
identifies a *user* to Victual. Replacing the user credential with OIDC must leave the
edge credential alone. The sidecar is unaffected by the edge, because it calls Victual
inside the cluster (`http://victual:8080`). `mcp/src/auth/resolver.ts` forwards only
`Authorization` or `VICTUAL-API-KEY`, so no edge header or cookie reaches Victual
through it.

Research (2026-10-23) on which Claude clients can reach a server at a private address.
Anthropic's documentation separates servers that run on the device from remote servers
added by URL:

- **Claude Code** connects from the machine it runs on, and `claude mcp add --header`
  sends a static `Authorization: Bearer` header. It works on the LAN or VPN.
- **Local MCP servers in Claude Desktop** (a stdio server in its config, or an MCPB
  extension) run on the device and reach whatever the device can reach. The maintainer
  runs several this way. A small stdio bridge that adds the header reaches the sidecar's
  private-network URL the same way.
- **Remote servers added by URL as custom connectors** (claude.ai, Claude Desktop's
  Connectors settings, the mobile apps) are connected from Anthropic's cloud, so the
  server must be reachable from Anthropic's IP ranges. A private address does not
  connect. Their authentication is OAuth. Sending a fixed `Authorization` value as a
  request header is a beta available only to some organizations.

Research (2026-10-23) on putting an MCP server behind Cloudflare Access. Access can act
as the OAuth 2.0 authorization server for a self-hosted application ("managed OAuth",
opt-in per application). An MCP server portal answers non-browser clients with a `401`
and OAuth discovery metadata rather than a login redirect, and it also accepts service
tokens. Two limits matter here:

- Managed OAuth hands the client an *opaque* access token, which cannot be forwarded to
  a downstream application. The user's identity reaches the origin as the
  `Cf-Access-Jwt-Assertion` JWT instead.
- Access is enforced only on the hostname it protects. Any other route to the same
  service bypasses it.

Sources: [Add a connector that isn't in the directory](https://claude.com/docs/connectors/custom/remote-mcp),
[Get started with custom connectors using remote MCP](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp),
[MCP tunnels](https://claude.com/docs/connectors/mcp-tunnels/overview),
[Managed OAuth for Cloudflare Access](https://developers.cloudflare.com/changelog/post/2026-03-20-managed-oauth/),
[MCP server portals](https://developers.cloudflare.com/cloudflare-one/access-controls/ai-controls/mcp-portals/),
[Build a remote MCP server](https://developers.cloudflare.com/agents/model-context-protocol/guides/remote-mcp-server/),
and the operator's *VictualKit Access Brief* (2026-10-23).

## Decision

1. **Catalog and stock tools are added.** All of them are thin wrappers over existing REST
   routes. Victual enforces the named permission on every forwarded call. The capability
   filter (spec §5) hides a tool from a key that lacks the permission or is `read_only`:

   | Tool | Route | Permission | Kind |
   |---|---|---|---|
   | `list_locations` | `GET /objects/locations` | `STOCK_VIEW` | read |
   | `list_quantity_units` | `GET /objects/quantity_units` | `STOCK_VIEW` | read |
   | `list_product_groups` | `GET /objects/product_groups` | `STOCK_VIEW` | read |
   | `lookup_barcode` | `GET /stock/products/by-barcode/{barcode}`, then on a miss `GET /stock/barcodes/external-lookup/{barcode}` without `add` | `STOCK_VIEW` | read |
   | `recent_transactions` (amended 2026-10-23) | `GET /objects/stock_log` | `STOCK_VIEW` | read |
   | `create_product` | `POST /objects/products` | `MASTER_DATA_ADD` | catalog write |
   | `update_product` | `PUT /objects/products/{id}` | `MASTER_DATA_EDIT` | catalog write |
   | `create_location` | `POST /objects/locations` | `MASTER_DATA_ADD` | catalog write |
   | `create_quantity_unit` | `POST /objects/quantity_units` | `MASTER_DATA_ADD` | catalog write |
   | `create_product_group` | `POST /objects/product_groups` | `MASTER_DATA_ADD` | catalog write |
   | `add_barcode` | `POST /objects/product_barcodes` | `MASTER_DATA_ADD` | catalog write |
   | `inventory_product` | `POST /stock/products/{id}/inventory` | `STOCK_INVENTORY`, `STOCK_VIEW` | stock write |
   | `open_product` | `POST /stock/products/{id}/open` | `STOCK_OPEN`, `STOCK_VIEW` | stock write |
   | `transfer_product` | `POST /stock/products/{id}/transfer` | `STOCK_TRANSFER`, `STOCK_VIEW` | stock write |
   | `undo_transaction` | `POST /stock/transactions/{id}/undo` | `STOCK_EDIT` | stock write |
   | `set_due_date` (amended 2026-10-23) | `GET /stock/products/{id}/entries`, then `PUT /stock/entry/{entryId}` per entry | `STOCK_EDIT`, `STOCK_VIEW` | stock write |
   | `check_off_shopping_list_item` | `PUT /objects/shopping_list/{id}` (`done`) | `SHOPPINGLIST_ITEMS_ADD` | list write |

   `inventory_product` is the main tool for walking the pantry: "there are three cans"
   sets an absolute amount. It does not add three. Victual refuses an inventory booking
   whose new amount equals the current one ("The new amount cannot equal the current
   stock amount"), so the tool reads `GET /stock/products/{id}` first. When the amounts
   match it books nothing and reports the amount as unchanged, so saying the same thing
   twice does no harm.

   > **Amended 2026-10-23.** Two tools join the table, after four headless Claude
   > conversations against the Talos dev app found two gaps in the 0.5.4 tool set
   > ([release record](../releases/0.5.4.md), "Known limitations"). This record is
   > Proposed, so the table is amended in place, with this note saying what changed and why.
   >
   > - **`recent_transactions`** lists a product's newest stock journal rows:
   >   `transaction_id`, type, amount, location, due date, `undone` and the timestamp. It
   >   never returns `price`, which `stock_log` carries. Without it, `undo_transaction`
   >   worked only inside the conversation that booked, because nothing else could supply a
   >   `transaction_id`. A later conversation had to correct with `inventory_product`, which
   >   leaves a second correction in the journal where an undo would have marked the first
   >   booking undone. It is a read and joins `all-read`. `EntityReadPolicy` already maps
   >   `stock_log` to `STOCK_VIEW`.
   > - **`set_due_date`** sets the due date of a product's stock already on hand. A product
   >   created without `default_best_before_days` defaults to 0, and Victual then dates
   >   every count today (`StockService`'s default due date). `update_product` changes the
   >   default for new stock only, so before this tool the only fix for counted stock was the
   >   UI. The tool takes `product_id`, `due_date` (or never expires), and optionally
   >   `location_id` and the entries' `current_due_date`, which select the entries to
   >   change. It reads `GET /stock/products/{id}/entries` and sends
   >   `PUT /stock/entry/{entryId}` for each selected entry whose date differs. The body is
   >   the entry's own `amount`, which the route requires, and the new `best_before_date`.
   >   Each edit is its own transaction, and `undo_transaction` reverses it. The edit is not
   >   one of ADR-0046's five bookings, so it carries no `Idempotency-Key`. Repeating it is
   >   harmless anyway, because an entry that already has the date is skipped. It joins
   >   `stock-write`. It overwrites a value, so it carries `destructiveHint`, as
   >   `update_product` does. No tool edits an entry's amount, price or location: counts
   >   remain `inventory_product`'s job and moves remain `transfer_product`'s.
   >
   > The other two gaps the conversations found change existing tools and need no
   > decision: `list_locations` gains `query` and `limit` (spec §5 already requires a
   > `limit` on every list tool), and `create_product` refuses a taken name by the id of
   > the product that holds it.

2. **Nothing deletes.** No tool calls a `DELETE` route, `POST /stock/shoppinglist/remove-product`
   or `POST /stock/shoppinglist/clear`. Deleting a product, a location, a unit, a barcode,
   a stock entry or a shopping-list item stays in the UI. Undo is not deletion: an undone
   transaction stays in the stock journal, marked undone.

3. **Explicit, never implicit.** Spec §6's rule that no write creates a product as a side
   effect still holds. `purchase_product` and `inventory_product` on an unknown product
   still fail. Only `create_product` creates one, and only when the model calls it.
   Its input names the product, its location and its stock unit by id. Optional fields
   are the product group, the minimum stock amount, the default best-before days and one
   barcode. `update_product` changes only the name, the default location, the product
   group, the minimum stock amount and the default best-before days. Moving stock that
   already exists is `transfer_product`'s job. It cannot change the stock unit, because
   changing it under existing stock changes what every booked amount means. The tool
   sets both `qu_id_stock` and `qu_id_purchase` (both are required) from its one unit
   input. `create_location` takes an optional `parent_location_id` (migration 0273),
   so shelves can sit inside a pantry.

4. **Every stock booking carries an `Idempotency-Key`** (ADR-0046) when Victual
   publishes support for it. The five bookings are purchase, consume, transfer, open and
   inventory; undo is not one of them. The tools take an optional `request_id`. If it is
   absent, the sidecar generates one with `crypto.randomUUID()`. The value is returned in
   `structuredContent` beside `transaction_id`. ADR-0046's client obligations apply, as
   follows for a stateless client:
   - **Discovery (obligation 1)** happens per booking call: `GET /api/system/info`, and
     the header is sent only if the response has `stock_booking_idempotency`. The sidecar
     keeps no session, so it cannot cache the answer (spec §2).
   - **An `outcome_unknown` result that carries a `request_id`** (obligations 3 and 5)
     says a retry with the same `request_id` **and the same values** cannot book twice,
     within the published retention window.
   - **A 409 `idempotency_conflict` (obligation 7)** says to stop and not resend.
   - **`Idempotent-Replayed: true` (obligation 9)** is reported as a replay, not a second
     booking.

5. **The chart sets the enabled tools.** A new value, `mcp.enabledTools` (default
   `"all-read"`), renders into `MCP_ENABLED_TOOLS`. Two new keywords join `all-read`:
   `catalog-write` (the six catalog writes) and `stock-write` (the three 0.5.3 writes, the
   four new stock tools and `check_off_shopping_list_item`). The new read tools join
   `all-read`. Write tools stay off by default. When ADR-0039 is built, Victual's stored
   setting overrides this value, and the value remains the 404 fallback that ADR-0039
   decision 6 describes.

6. **The chart can publish the sidecar on the private network.** This follows the opt-in
   Ingress precedent of [ADR-0038](0038-kubernetes-deployments-ship-as-a-helm-chart.md)
   decision 7. A new `mcp.ingress` block mirrors the existing `ingress` values: `enabled` (default `false`), `className`, `host`,
   `tls.enabled`, `tls.secretName` and `annotations`. It renders a second Ingress to the
   `victual-mcp` Service on its own hostname, not a `/mcp` path on Victual's host, because:
   - its own host gets its own certificate;
   - Traefik middlewares, such as an IP allow-list, attach to the sidecar only;
   - nothing the sidecar serves can collide with a Victual route.

   The repository carries only the template. The hostname, the certificate resolver
   annotations and any middleware go in the operator's values file, as they do for
   Victual's own Ingress. On production that means a second name under the household
   domain, resolvable on the LAN to the private ingress address, plus the Traefik DNS-01
   resolver already in use. That name gets no tunnel route.

7. **Exposure is staged, and this record takes only the first stage.** The stages
   (plan 02 Q4 holds for stage 1):

   | Stage | Route to the sidecar | User credential to Victual | Clients that work |
   |---|---|---|---|
   | 1, this record | Private ingress on the LAN or VPN, outside Cloudflare | Static MCP API key | Claude Code; a local server in Claude Desktop |
   | 2, later | A tunnel hostname behind its own Access application, with a service-token policy | Static MCP API key | Stage 1's clients, which add the two `CF-Access-Client-*` headers |
   | 3, with OIDC | Access managed OAuth or an MCP portal, or Victual's own IdP | An OIDC-derived identity, mapped through the resolver seam (spec §4.1) | Also remote custom connectors, including the mobile apps |

   - **Stage 1** needs no Cloudflare configuration.
   - **Stage 2** changes only the operator's values file and Cloudflare settings. The chart
     template is the same.
   - **Stage 3 is not a configuration step.** A remote connector authenticates with OAuth,
     which takes the `Authorization` header, so it cannot also send the static key. Managed
     OAuth gives it an opaque token that cannot be forwarded to Victual, so Victual has to
     accept an identity it can verify: the Access JWT, or a token from its own OIDC
     issuer. Choosing between them belongs to
     [issue 770](https://github.com/datagen24/victual/issues/770).

   Before stage 2 or 3, Bot Fight Mode needs testing against MCP traffic, because Cloudflare
   documents that it may challenge API traffic. Stage 3 also needs testing against
   Anthropic's connector traffic. The WAF's method list already covers Streamable HTTP,
   which uses POST, GET and DELETE.

8. **The sidecar carries only the user credential.** Edge credentials end at Cloudflare.
   The sidecar never forwards `CF-Access-*` headers, `CF_Authorization` or
   `Cf-Access-Jwt-Assertion` to Victual unless a stage 3 decision makes the JWT the user
   credential. The new tools are credential-agnostic: they reach Victual only through the
   resolver (`mcp/src/auth/resolver.ts`). OIDC then changes the resolver and Victual's
   authentication, and no tool.

## Consequences

- The household can build a catalog and count stock from a conversation, with the same
  permissions and per-key `read_only` boundary the 0.5.3 writes already have.
- `all-read` grows from six tools to ten on every deployment that upgrades, without a
  values change. The four new reads only list master data or look up a barcode.
- The tool list grows from 9 to 24 (26 with the 2026-10-23 amendment to decision 1, and
  `all-read` to eleven). Plan 02 Q5 asked for 6 to 10 narrow tools to keep the
  list small for the model. This record trades some of that budget for coverage. Turning
  off groups the deployment doesn't need (decision 5) keeps the cost down, and the
  sidecar lists only the enabled tools.
- Master-data writes are new territory for an assistant. A wrong `create_product` leaves
  a product that has to be deleted in the UI, because decision 2 keeps deletion there.
  The product edit page, and the admin's ability to revoke the key, are the recovery
  path.
- `undo_transaction` lets the assistant reverse its own mistakes, using the
  `transaction_id` the booking tools already return.
- The spec's §6, §8, §9 and §10, `mcp/README.md`, the chart and `victual.openapi.json`
  are untouched by this record. The implementation changes the spec and README in the
  same pull request as the code. The REST contract does not change, because every tool
  uses an existing route.
- Phone use is not solved here. It arrives with stage 3 (decision 7), and that stage
  depends on issue 770's choice of user credential.
- Stage 1's private route bypasses Cloudflare Access by design, as the LAN path to Victual
  does. It is protected by the MCP key and by being reachable only on the LAN or VPN.
  It never gets a tunnel route.

### Options ruled out

- **One generic `objects` tool over `/objects/{entity}`.** It is fewer tools, but it hands
  the model a schema-free write surface and makes the capability filter meaningless.
- **A `/mcp` path on Victual's own Ingress.** It shares Victual's certificate and
  middlewares, so an IP allow-list for the sidecar would also restrict the app.
- **Public exposure with the static API key alone.** That would let anyone who learns the
  key in from the internet. Stage 2 always puts Access in front, and the maintainer has
  deferred even that until the tool set is stable.
- **The sidecar on Victual's existing tunnel hostname and Access application.** That shares
  the app's Access policy and Bot Fight Mode exposure. It also mixes a device-token policy
  for native apps with a policy for the assistant. A separate Access application can be
  revoked and scoped on its own.

## Open questions

1. **How does Claude Desktop reach the sidecar?** Recommendation: a local stdio entry in
   Claude Desktop's config that bridges to `https://<mcp-host>/mcp` and adds the bearer
   header. Claude Code uses `claude mcp add --transport http --header` directly. The
   choice of bridge (a third-party package, or a small one shipped from `mcp/`) is made
   at implementation.

   > **Response:** datagen24, 2026-10-23: accepted as recommended.

2. **External barcode lookup.** `lookup_barcode`'s fallback sends the barcode to whichever
   lookup plugin Victual has configured (an external service). Recommendation: allow it.
   It sends only the barcode, it is already what the UI's scan flow does, and with no
   plugin configured the fallback is skipped.

   > **Response:** datagen24, 2026-10-23: accepted as recommended.

3. **Checking off a shopping-list item.** This record treats `done` as an edit and keeps
   removal in the UI. Recommendation: keep that split.

   > **Response:** datagen24, 2026-10-23: accepted as recommended.

4. **A question for issue 770, recorded here because stage 3 depends on it: whose token
   is the user credential?** One option is that Victual verifies the Access JWT, which
   gives one sign-in through Entra but ties Victual's authentication to Cloudflare. The
   other is that Victual runs as an OIDC relying party with its own issuer
   configuration, which other deployments can use but which may mean signing in twice
   behind Access. Recommendation: Victual's own OIDC.
   - The VictualKit brief already reads the issuer from the server rather than
     hard-coding Entra.
   - The MCP authorization spec has clients discover the authorization server from the
     resource's `/.well-known/oauth-protected-resource` metadata (RFC 9728).
   - One discovery document that Victual and the sidecar both publish would serve the
     native apps and the assistant alike.

   > **Response:** datagen24, 2026-10-23: deferred to
   > [issue 770](https://github.com/datagen24/victual/issues/770). Stage 3 is outside this
   > record's scope, so this record does not decide whose token is the user credential.
   > The recommendation and its reasons go to issue 770 as input.

## Acceptance prerequisites

1. The maintainer answers the open questions. *Met 2026-10-23:* questions 1 to 3 were
   accepted as recommended, and question 4 was deferred to issue 770.
2. The tools, the chart values and the spec amendments are built and covered by the
   sidecar's handler and HTTP tests and by `helm template` checks for both new values.
3. A real-client run on production: a catalog of at least ten products is built and
   counted from a Claude conversation, and each result is checked against the REST API.
   The transcript and the check go in `mcp/README.md`.
