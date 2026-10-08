// §6 write tools: field mapping to Victual's REST names, the transaction_id contract, the
// read_only / permission list filter, and the POST path (204 with no body) over real
// sockets against a fake Victual.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { buildServer } from "../../dist/server.js";
import { loadConfig } from "../../dist/config.js";
import { addToShoppingList } from "../../dist/tools/add-to-shopping-list.js";
import { consumeProduct } from "../../dist/tools/consume-product.js";
import { purchaseProduct } from "../../dist/tools/purchase-product.js";
import { VictualApiError } from "../../dist/victual/client.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

function ctx(response) {
  const posts = [];
  return {
    posts,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async post(path, credential, body) {
        posts.push({ path, body });
        return typeof response === "function" ? response(path, body) : response;
      },
    },
  };
}

test("add_to_shopping_list maps to list_id/product_amount and defaults amount to 1", async () => {
  const c = ctx(undefined);
  const { data, text } = await run(addToShoppingList, { product_id: 7, note: "organic" }, c);
  assert.deepEqual(c.posts, [
    { path: "/api/stock/shoppinglist/add-product", body: { product_id: 7, product_amount: 1, list_id: 1, note: "organic" } },
  ]);
  assert.deepEqual(data, { product_id: 7, amount: 1, shopping_list_id: 1 });
  assert.match(text, /shopping list 1/);
});

test("add_to_shopping_list refuses amount 0 (the route would silently read it as 1) and ids that are not positive", () => {
  for (const bad of [{ product_id: 7, amount: 0 }, { product_id: 0 }, { product_id: 7, shopping_list_id: 0 }]) {
    assert.equal(addToShoppingList.inputSchema.safeParse(bad).success, false);
  }
});

test("consume_product sends a real boolean and returns the transaction_id with the undo note", async () => {
  const c = ctx([{ transaction_id: "tx-1", product_id: 3, amount: -2 }, { transaction_id: "tx-1", product_id: 3, amount: -1 }]);
  const { data, text } = await run(consumeProduct, { product_id: 3, amount: 3, spoiled: true }, c);
  assert.deepEqual(c.posts, [{ path: "/api/stock/products/3/consume", body: { amount: 3, spoiled: true } }]);
  assert.equal(data.transaction_id, "tx-1");
  assert.equal(c.posts[0].body.spoiled, true);
  assert.match(text, /undone in Victual's stock journal/);
  assert.match(text, /spoiled/);
});

test("consume_product defaults spoiled to false", async () => {
  const c = ctx([{ transaction_id: "tx-2" }]);
  await run(consumeProduct, { product_id: 3, amount: 1 }, c);
  assert.strictEqual(c.posts[0].body.spoiled, false);
});

test("purchase_product maps due_date to best_before_date and omits unset optionals", async () => {
  const c = ctx([{ transaction_id: "tx-3" }]);
  const full = await run(purchaseProduct, { product_id: 4, amount: 2, price: 1.99, due_date: "2026-12-01", shopping_location_id: 5 }, c);
  const bare = await run(purchaseProduct, { product_id: 4, amount: 2 }, c);
  assert.deepEqual(c.posts[0].body, { amount: 2, price: 1.99, best_before_date: "2026-12-01", shopping_location_id: 5 });
  assert.deepEqual(c.posts[1].body, { amount: 2 });
  assert.equal(full.data.transaction_id, "tx-3");
  assert.match(bare.text, /undone in Victual's stock journal/);
  assert.equal(purchaseProduct.inputSchema.safeParse({ product_id: 4, amount: 1, due_date: "next week" }).success, false);
});

test("a booking answered without a transaction row is a victual_error, never success without an id", async () => {
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1 }, ctx([])), (e) => e instanceof VictualApiError && e.category === "victual_error");
  await assert.rejects(run(purchaseProduct, { product_id: 3, amount: 1 }, ctx(undefined)), VictualApiError);
});

test("loadConfig accepts the write tools by name, and all-read does not include them", () => {
  const base = { VICTUAL_BASE_URL: "http://x" };
  assert.deepEqual(loadConfig({ ...base, MCP_ENABLED_TOOLS: "shopping_list,consume_product" }).MCP_ENABLED_TOOLS, ["shopping_list", "consume_product"]);
  assert.equal(loadConfig({ ...base, MCP_ENABLED_TOOLS: "all-read,consume_product" }).MCP_ENABLED_TOOLS.length, 7);
  assert.equal(loadConfig(base).MCP_ENABLED_TOOLS.some((t) => ["add_to_shopping_list", "consume_product", "purchase_product"].includes(t)), false);
});

// --- over HTTP ---------------------------------------------------------------------------

let victual, sidecar, base;
let capabilities;
const seen = [];

before(async () => {
  victual = createServer((req, res) => {
    const path = new URL(req.url, "http://x").pathname;
    if (path === "/api/user/capabilities") {
      res.writeHead(200, { "content-type": "application/json" });
      return res.end(JSON.stringify(capabilities));
    }
    let raw = "";
    req.on("data", (chunk) => (raw += chunk));
    req.on("end", () => {
      seen.push({ method: req.method, path, type: req.headers["content-type"], body: raw ? JSON.parse(raw) : null });
      if (path === "/api/stock/shoppinglist/add-product") { res.writeHead(204); return res.end(); }
      if (path.endsWith("/consume")) {
        res.writeHead(200, { "content-type": "application/json" });
        return res.end(JSON.stringify([{ transaction_id: "tx-http", product_id: 9, amount: -1 }]));
      }
      res.writeHead(403); res.end("{}");
    });
  });
  await new Promise((resolve) => victual.listen(0, resolve));
  sidecar = buildServer({
    VICTUAL_BASE_URL: `http://127.0.0.1:${victual.address().port}`,
    MCP_PORT: 0,
    MCP_ENABLED_TOOLS: ["stock_overview", "add_to_shopping_list", "consume_product", "purchase_product"],
    MCP_REQUEST_TIMEOUT_MS: 2000,
    LOG_LEVEL: "error",
  });
  await sidecar.listen();
  base = `http://127.0.0.1:${sidecar.httpServer.address().port}`;
});

after(async () => {
  await sidecar.close();
  await new Promise((resolve) => victual.close(resolve));
});

const rpc = (body) =>
  fetch(`${base}/mcp`, {
    method: "POST",
    headers: { "content-type": "application/json", accept: "application/json, text/event-stream", "mcp-protocol-version": "2025-11-25", authorization: "Bearer good" },
    body: JSON.stringify(body),
  });

async function result(response) {
  const text = await response.text();
  const data = text.split("\n").find((line) => line.startsWith("data: "));
  return JSON.parse(data ? data.slice(6) : text).result;
}

const list = async () => (await result(await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }))).tools;
const call = async (name, args) => result(await rpc({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name, arguments: args } }));

test("a read_only key never sees a write tool, even holding the permissions", async () => {
  capabilities = { key_type: "mcp", read_only: true, permissions: ["STOCK_VIEW", "STOCK_CONSUME", "STOCK_PURCHASE", "SHOPPINGLIST_ITEMS_ADD"] };
  assert.deepEqual((await list()).map((t) => t.name), ["stock_overview"]);
});

test("a writable key sees exactly the write tools its user may use; consume/purchase also need STOCK_VIEW", async () => {
  capabilities = { key_type: "mcp", read_only: false, permissions: ["SHOPPINGLIST_ITEMS_ADD", "STOCK_CONSUME", "STOCK_PURCHASE"] };
  assert.deepEqual((await list()).map((t) => t.name), ["add_to_shopping_list"]);
  capabilities = { key_type: "mcp", read_only: false, permissions: ["STOCK_VIEW", "STOCK_CONSUME", "STOCK_PURCHASE", "SHOPPINGLIST_ITEMS_ADD"] };
  const tools = await list();
  assert.deepEqual(tools.map((t) => t.name), ["stock_overview", "add_to_shopping_list", "consume_product", "purchase_product"]);
  const byName = Object.fromEntries(tools.map((t) => [t.name, t.annotations]));
  assert.equal(byName.stock_overview.readOnlyHint, true);
  assert.equal(byName.add_to_shopping_list.readOnlyHint, false);
  assert.equal(byName.consume_product.destructiveHint, true);
  assert.equal(byName.purchase_product.destructiveHint, false);
});

test("add_to_shopping_list over HTTP: JSON body forwarded, the 204 becomes a success result", async () => {
  seen.length = 0;
  const res = await call("add_to_shopping_list", { product_id: 9, amount: 2 });
  assert.notEqual(res.isError, true);
  assert.deepEqual(res.structuredContent, { product_id: 9, amount: 2, shopping_list_id: 1 });
  assert.equal(seen[0].method, "POST");
  assert.equal(seen[0].type, "application/json");
  assert.deepEqual(seen[0].body, { product_id: 9, product_amount: 2, list_id: 1 });
});

test("consume_product over HTTP returns transaction_id in structuredContent", async () => {
  seen.length = 0;
  const res = await call("consume_product", { product_id: 9, amount: 1 });
  assert.equal(res.structuredContent.transaction_id, "tx-http");
  assert.match(res.content[0].text, /undone in Victual's stock journal/);
  assert.deepEqual(seen[0].body, { amount: 1, spoiled: false });
});

test("a Victual 403 on a write call is an isError result categorized forbidden", async () => {
  const res = await call("purchase_product", { product_id: 9, amount: 1 });
  assert.equal(res.isError, true);
  assert.equal(JSON.parse(res.content[1].text).error, "forbidden");
});
