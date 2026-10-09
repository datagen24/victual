// ADR-0039 prerequisite 2, spike evidence: the sidecar reads Victual's MCP configuration on
// every request and refuses a disabled tool, driven with the official SDK v2 client.
//
//   MCP_URL=http://localhost:3000/mcp VICTUAL_URL=http://localhost:8080 \
//   ADMIN_KEY=<non-read-only MCP key of an administrator> RO_KEY=<read-only MCP key> \
//   node scripts/adr39-demo.mjs
//
// Throwaway spike code: it prints what the sidecar answered at each step so the transcript can
// be quoted. It creates one location, one product and five units of stock through Victual's
// REST API, as the administrator, so that consume_product has something to book.
import { Client, StreamableHTTPClientTransport } from "@modelcontextprotocol/client";

const { MCP_URL, VICTUAL_URL, ADMIN_KEY, RO_KEY } = process.env;
if (!MCP_URL || !VICTUAL_URL || !ADMIN_KEY || !RO_KEY) {
  console.error("set MCP_URL, VICTUAL_URL, ADMIN_KEY and RO_KEY");
  process.exit(2);
}

async function connect(key) {
  const client = new Client({ name: "adr39-demo", version: "0.0.0" }, { versionNegotiation: { mode: "auto" } });
  await client.connect(new StreamableHTTPClientTransport(new URL(MCP_URL), { requestInit: { headers: { authorization: `Bearer ${key}` } } }));
  return client;
}

const victual = async (method, path, body) => {
  const response = await fetch(new URL(path, VICTUAL_URL), {
    method,
    headers: { "VICTUAL-API-KEY": ADMIN_KEY, "content-type": "application/json", accept: "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  return { status: response.status, body: text ? JSON.parse(text) : null };
};

const names = async (key) => {
  const client = await connect(key);
  const { tools } = await client.listTools();
  await client.close();
  return tools.map((t) => t.name);
};

const callAs = async (key, name, args) => {
  const client = await connect(key);
  const result = await client.callTool({ name, arguments: args });
  await client.close();
  return result;
};

const brief = (result) => {
  if (result.isError) return `isError ${result.content[1]?.text}`;
  return `ok ${JSON.stringify(result.structuredContent)}`;
};

const step = (label, value) => console.log(`${label.padEnd(58)} ${typeof value === "string" ? value : JSON.stringify(value)}`);

// --- setup: something to consume --------------------------------------------------------
const location = await victual("POST", "/api/objects/locations", { name: `adr39 shelf ${Date.now()}` });
const quId = (await victual("GET", "/api/objects/quantity_units?limit=1")).body[0].id;
const product = await victual("POST", "/api/objects/products", {
  name: `adr39 test product ${Date.now()}`,
  location_id: location.body.created_object_id,
  qu_id_purchase: quId,
  qu_id_stock: quId,
  qu_id_consume: quId,
  qu_id_price: quId,
});
const productId = product.body.created_object_id;
const purchase = await victual("POST", `/api/stock/products/${productId}/add`, { amount: 5, transaction_type: "purchase" });
step("setup: product / location / purchase status", { productId, location: location.status, product: product.status, purchase: purchase.status });

// --- 0. defaults ------------------------------------------------------------------------
await victual("PUT", "/api/mcp/config", { tools: { add_to_shopping_list: false, consume_product: false, purchase_product: false } });
step("GET /api/mcp/config as the read-only key (Victual)", await (async () => {
  const r = await fetch(new URL("/api/mcp/config", VICTUAL_URL), { headers: { "VICTUAL-API-KEY": RO_KEY } });
  return { status: r.status, body: await r.json() };
})());
step("1. tools/list as the writable admin key, all writes off", await names(ADMIN_KEY));

// --- 1. a PUT changes tools/list with no restart -----------------------------------------
const put = await victual("PUT", "/api/mcp/config", { tools: { consume_product: true } });
step("2. PUT consume_product=true -> status, enabled_tools", { status: put.status, enabled: put.body.enabled_tools });
step("3. tools/list as the writable admin key", await names(ADMIN_KEY));
step("   tools/list as the READ-ONLY key (write tool still hidden)", await names(RO_KEY));

// --- 2. enabled write works; disabled write is refused; re-enabled works ------------------
step("4. consume_product (enabled) as the admin key", brief(await callAs(ADMIN_KEY, "consume_product", { product_id: productId, amount: 1 })));
const off = await victual("PUT", "/api/mcp/config", { tools: { consume_product: false } });
step("5. PUT consume_product=false -> status", off.status);
step("6. consume_product (just disabled) as the admin key", brief(await callAs(ADMIN_KEY, "consume_product", { product_id: productId, amount: 1 })));
const stockAfterRefusal = (await victual("GET", `/api/stock/products/${productId}`)).body.stock_amount;
step("   stock amount after the refused call (4 expected)", stockAfterRefusal);
step("7. tools/list again", await names(ADMIN_KEY));
await victual("PUT", "/api/mcp/config", { tools: { consume_product: true } });
step("8. PUT consume_product=true again; consume_product works", brief(await callAs(ADMIN_KEY, "consume_product", { product_id: productId, amount: 1 })));
const stockEnd = (await victual("GET", `/api/stock/products/${productId}`)).body.stock_amount;
step("   stock amount at the end (3 expected)", stockEnd);

// --- 3. a read tool can be turned off too ------------------------------------------------
await victual("PUT", "/api/mcp/config", { tools: { expiring_soon: false } });
step("9. expiring_soon=false: tools/list as the read-only key", await names(RO_KEY));
step("   expiring_soon call as the read-only key", brief(await callAs(RO_KEY, "expiring_soon", {})));

// --- restore defaults -------------------------------------------------------------------
await victual("PUT", "/api/mcp/config", { tools: { expiring_soon: true, consume_product: false } });
step("restored defaults: tools/list", await names(ADMIN_KEY));
