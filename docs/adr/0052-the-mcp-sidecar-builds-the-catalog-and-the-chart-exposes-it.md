# ADR-0052: The MCP sidecar can build the catalog and count stock, and the chart exposes it on the private network

- **Status:** Proposed
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
  every client today needs `kubectl port-forward`. Production already serves Victual over
  HTTPS: a domain on Cloudflare, Let's Encrypt certificates through Traefik's DNS-01
  resolver, and DNS that points at a private ingress address.

Research (2026-10-23, Anthropic's connector documentation) on which Claude clients can
reach a server at a private address:

- **Claude Code** connects from the machine it runs on, and `claude mcp add --header`
  sends a static `Authorization: Bearer` header. It works on the LAN or VPN.
- **Claude Desktop's local extensions** (MCPB bundles, or a stdio server in its config)
  also run on the device. A small stdio bridge that adds the header reaches a
  private-network URL.
- **Custom connectors in claude.ai, Claude Desktop and the mobile apps** connect from
  Anthropic's cloud, not from the device. Their documentation says the server must be
  reachable from Anthropic's IP ranges over the public internet. Servers on a private
  network or behind a VPN do not connect. The way around that, MCP tunnels, needs an
  Enterprise organization. For authentication these connectors use OAuth. A fixed
  `Authorization` value sent as a request header is a beta available only to a limited
  set of organizations.

## Decision

1. **Catalog and stock tools are added.** All of them are thin wrappers over existing REST
   routes. Victual enforces the named permission on every forwarded call. The capability
   filter (spec §5) hides a tool from a key that lacks the permission or is `read_only`:

   | Tool | Route | Permission | Kind |
   |---|---|---|---|
   | `list_locations` | `GET /objects/locations` | (authenticated) | read |
   | `list_quantity_units` | `GET /objects/quantity_units` | (authenticated) | read |
   | `list_product_groups` | `GET /objects/product_groups` | (authenticated) | read |
   | `lookup_barcode` | `GET /stock/products/by-barcode/{barcode}`, then on a miss `GET /stock/barcodes/external-lookup/{barcode}` without `add` | `STOCK_VIEW` | read |
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
   | `check_off_shopping_list_item` | `PUT /objects/shopping_list/{id}` (`done`) | `SHOPPINGLIST_ITEMS_ADD` | list write |

   `inventory_product` is the main tool for walking the pantry: "there are three cans"
   sets an absolute amount. It does not add three, so saying the same thing twice does no
   harm.

2. **Nothing deletes.** No tool calls a `DELETE` route, `POST /stock/shoppinglist/remove-product`
   or `POST /stock/shoppinglist/clear`. Deleting a product, a location, a unit, a barcode,
   a stock entry or a shopping-list item stays in the UI. Undo is not deletion: an undone
   transaction stays in the stock journal, marked undone.

3. **Explicit, never implicit.** Spec §6's rule that no write creates a product as a side
   effect still holds. `purchase_product` and `inventory_product` on an unknown product
   still fail. Only `create_product` creates one, and only when the model calls it.
   Its input names the product, its location and its stock unit by id. Optional fields
   are the product group, the minimum stock amount, the default best-before days and one
   barcode. `update_product` changes only those fields plus the name. It cannot change
   the stock unit, because changing it under existing stock changes what every booked
   amount means.

4. **Every stock booking carries an `Idempotency-Key`** (ADR-0046). The tools take an
   optional `request_id`. If it is absent, the sidecar generates one. The value is
   returned in `structuredContent` beside `transaction_id`. A timeout's `text` block says
   the booking may have happened and that a retry with the same `request_id` cannot book
   twice.

5. **The chart sets the enabled tools.** A new value, `mcp.enabledTools` (default
   `"all-read"`), renders into `MCP_ENABLED_TOOLS`. Two new keywords join `all-read`:
   `catalog-write` (the six catalog writes) and `stock-write` (the three 0.5.3 writes, the
   four new stock tools and `check_off_shopping_list_item`). The new read tools join
   `all-read`. Write tools stay off by default. When ADR-0039 is built, Victual's stored
   setting overrides this value, and the value remains the 404 fallback that ADR-0039
   decision 6 describes.

6. **The chart can publish the sidecar on the private network.** A new `mcp.ingress` block
   mirrors the existing `ingress` values: `enabled` (default `false`), `className`, `host`,
   `tls.enabled`, `tls.secretName` and `annotations`. It renders a second Ingress to the
   `victual-mcp` Service on its own hostname, not a `/mcp` path on Victual's host, because:
   - its own host gets its own certificate;
   - Traefik middlewares, such as an IP allow-list, attach to the sidecar only;
   - nothing the sidecar serves can collide with a Victual route.

   The repository carries only the template. The hostname, the certificate resolver
   annotations and any middleware go in the operator's values file, as they do for
   Victual's own Ingress. On production that means a second name under the household
   domain, a DNS-only record at the same private ingress address, and the Traefik DNS-01
   resolver already in use.

7. **Exposure stays private (plan 02 Q4).** With decision 6, Claude Code and a Claude
   Desktop local extension on the LAN or VPN reach `https://<mcp-host>/mcp` with a static
   bearer key. Claude's cloud-brokered connectors (web, mobile, Desktop custom
   connectors) cannot reach it, and this record does not change that. Reaching the
   sidecar from a phone needs a public route restricted to Anthropic's published IP
   ranges, plus OAuth
   ([issue 770](https://github.com/datagen24/victual/issues/770), the IdP future state in
   spec §4.1). That is its own decision.

## Consequences

- The household can build a catalog and count stock from a conversation, with the same
  permissions and per-key `read_only` boundary the 0.5.3 writes already have.
- The tool list grows from 9 to 24. Plan 02 Q5 asked for 6 to 10 narrow tools to keep the
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
- Phone use is not solved here (decision 7).

### Options ruled out

- **One generic `objects` tool over `/objects/{entity}`.** It is fewer tools, but it hands
  the model a schema-free write surface and makes the capability filter meaningless.
- **A `/mcp` path on Victual's own Ingress.** It shares Victual's certificate and
  middlewares, so an IP allow-list for the sidecar would also restrict the app.
- **Public exposure with the static API key.** That would let anyone who learns the key
  in from the internet, and the cloud connectors would still need the beta request-header
  feature to send it.

## Open questions

1. **Which client will the household use for the pantry walk?** Recommendation: Claude
   Desktop with a local extension on the Mac, or Claude Code, while decision 7 holds. If
   it has to be the phone, issue 770 comes first.
2. **External barcode lookup.** `lookup_barcode`'s fallback sends the barcode to whichever
   lookup plugin Victual has configured (an external service). Recommendation: allow it.
   It sends only the barcode, it is already what the UI's scan flow does, and with no
   plugin configured the fallback is skipped.
3. **Checking off a shopping-list item.** This record treats `done` as an edit and keeps
   removal in the UI. Recommendation: keep that split.

## Acceptance prerequisites

1. The maintainer answers the open questions.
2. The tools, the chart values and the spec amendments are built and covered by the
   sidecar's handler and HTTP tests and by `helm template` checks for both new values.
3. A real-client run on production: a catalog of at least ten products is built and
   counted from a Claude conversation, and each result is checked against the REST API.
   The transcript and the check go in `mcp/README.md`.
