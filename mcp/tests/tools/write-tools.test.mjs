// §6 write tools: field mapping to Victual's REST names, the transaction_id contract, the
// read_only / permission list filter, and the POST path (204 with no body) over real
// sockets against a fake Victual.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { buildServer } from "../../dist/server.js";
import { ALL_READ_TOOLS, loadConfig } from "../../dist/config.js";
import { addToShoppingList } from "../../dist/tools/add-to-shopping-list.js";
import { consumeProduct } from "../../dist/tools/consume-product.js";
import { purchaseProduct } from "../../dist/tools/purchase-product.js";
import { VictualApiError, VictualClient } from "../../dist/victual/client.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

// `info` is what GET /api/system/info answers: no stock_booking_idempotency by default, so a
// booking is sent unkeyed, as to a Victual that predates ADR-0046.
function ctx(response, { info = {}, replayed } = {}) {
  const posts = [];
  const gets = [];
  return {
    posts,
    gets,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path) {
        gets.push(path);
        return info;
      },
      async post(path, credential, body, headers = {}) {
        posts.push({ path, body, headers });
        return typeof response === "function" ? response(path, body) : response;
      },
      async postWithStatus(path, credential, body, headers = {}) {
        const answer = await this.post(path, credential, body, headers);
        return { status: 200, body: answer, headers: new Headers(replayed === undefined ? {} : { "Idempotent-Replayed": String(replayed) }) };
      },
    },
  };
}

const KEYED = { stock_booking_idempotency: { header: "Idempotency-Key", retention_seconds: 604800, operations: ["purchase", "consume", "transfer", "open", "inventory"] } };

test("add_to_shopping_list maps to list_id/product_amount and defaults amount to 1", async () => {
  const c = ctx(undefined);
  const { data, text } = await run(addToShoppingList, { product_id: 7, note: "organic" }, c);
  assert.deepEqual(c.posts.map(({ path, body }) => ({ path, body })), [
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
  assert.deepEqual(c.posts.map(({ path, body }) => ({ path, body })), [{ path: "/api/stock/products/3/consume", body: { amount: 3, spoiled: true } }]);
  assert.equal(data.transaction_id, "tx-1");
  assert.equal(c.posts[0].body.spoiled, true);
  assert.match(text, /undone in Victual's stock journal/);
  assert.match(text, /spoiled/);
});

test("a booking asks Victual for the idempotency capability first, and sends no key to a Victual without it", async () => {
  const c = ctx([{ transaction_id: "tx-u" }]);
  const { data } = await run(consumeProduct, { product_id: 3, amount: 1 }, c);
  assert.deepEqual(c.gets, ["/api/system/info"]);
  assert.deepEqual(c.posts[0].headers, {});
  assert.equal(data.request_id, undefined);
  assert.equal(data.replayed, false);
});

test("a booking to a Victual that publishes the capability carries a fresh UUID key, returned as request_id", async () => {
  const c = ctx([{ transaction_id: "tx-k" }], { info: KEYED });
  const { data } = await run(purchaseProduct, { product_id: 4, amount: 1 }, c);
  const key = c.posts[0].headers["Idempotency-Key"];
  assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  assert.equal(data.request_id, key);
  const again = ctx([{ transaction_id: "tx-k2" }], { info: KEYED });
  await run(purchaseProduct, { product_id: 4, amount: 1 }, again);
  assert.notEqual(again.posts[0].headers["Idempotency-Key"], key, "one key per intended booking (ADR-0046 obligation 2)");
});

test("a retry's request_id is sent unchanged, and a replay is reported as nothing new booked", async () => {
  const c = ctx([{ transaction_id: "tx-r" }], { info: KEYED, replayed: true });
  const { data, text } = await run(consumeProduct, { product_id: 3, amount: 1, request_id: "retry-key-0001" }, c);
  assert.equal(c.posts[0].headers["Idempotency-Key"], "retry-key-0001");
  assert.equal(data.replayed, true);
  assert.match(text, /nothing new was booked/);
});

test("a Victual without /api/system/info books unkeyed; an unreachable one fails before booking", async () => {
  const c = ctx([{ transaction_id: "tx-old" }]);
  c.client.get = async () => { throw new VictualApiError("not_found", "Victual answered 404", 404); };
  const { data } = await run(consumeProduct, { product_id: 3, amount: 1 }, c);
  assert.equal(data.request_id, undefined);
  const down = ctx([{ transaction_id: "never" }]);
  down.client.get = async () => { throw new VictualApiError("victual_unavailable", "could not reach Victual"); };
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1 }, down), (e) => e.category === "victual_unavailable");
  assert.equal(down.posts.length, 0);
});

test("request_id follows ADR-0046's key grammar", () => {
  for (const bad of ["short", "x".repeat(129), "has space-in-it", "slash/not/allowed"]) {
    assert.equal(consumeProduct.inputSchema.safeParse({ product_id: 1, amount: 1, request_id: bad }).success, false, bad);
  }
  assert.equal(purchaseProduct.inputSchema.safeParse({ product_id: 1, amount: 1, request_id: "a1._:-b2c3" }).success, true);
});

test("an error from a keyed booking carries its request_id; an unkeyed one does not", async () => {
  const refuse = () => { throw new VictualApiError("outcome_unknown", "timed out"); };
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1, request_id: "retry-key-0002" }, ctx(refuse, { info: KEYED })), (e) => e.requestId === "retry-key-0002");
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1 }, ctx(refuse)), (e) => e.requestId === undefined);
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

test("a booking answered 2xx without a transaction row is outcome_unknown and keeps the status (#937)", async () => {
  const unknown = (e) => e instanceof VictualApiError && e.category === "outcome_unknown" && e.victualStatus === 200 && /no transaction row/.test(e.message);
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1 }, ctx([])), unknown);
  await assert.rejects(run(consumeProduct, { product_id: 3, amount: 1 }, ctx([{}])), unknown);
  await assert.rejects(run(purchaseProduct, { product_id: 3, amount: 1 }, ctx(undefined)), unknown);
  await assert.rejects(run(purchaseProduct, { product_id: 3, amount: 1 }, ctx([{ transaction_id: "" }])), unknown);
});

test("loadConfig accepts the write tools by name, and all-read does not include them", () => {
  const base = { VICTUAL_BASE_URL: "http://x" };
  assert.deepEqual(loadConfig({ ...base, MCP_ENABLED_TOOLS: "shopping_list,consume_product" }).MCP_ENABLED_TOOLS, ["shopping_list", "consume_product"]);
  assert.equal(loadConfig({ ...base, MCP_ENABLED_TOOLS: "all-read,consume_product" }).MCP_ENABLED_TOOLS.length, ALL_READ_TOOLS.length + 1);
  assert.equal(loadConfig(base).MCP_ENABLED_TOOLS.some((t) => ["add_to_shopping_list", "consume_product", "purchase_product"].includes(t)), false);
});

test("loadConfig expands stock-write to the write tools, in place and without duplicates", () => {
  const base = { VICTUAL_BASE_URL: "http://x" };
  const writes = ["add_to_shopping_list", "consume_product", "purchase_product"];
  assert.deepEqual(loadConfig({ ...base, MCP_ENABLED_TOOLS: "stock-write" }).MCP_ENABLED_TOOLS, writes);
  const both = loadConfig({ ...base, MCP_ENABLED_TOOLS: "consume_product, all-read ,stock-write" }).MCP_ENABLED_TOOLS;
  assert.equal(both.length, ALL_READ_TOOLS.length + writes.length);
  assert.equal(both[0], "consume_product");
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
    if (path === "/api/system/info") {
      res.writeHead(200, { "content-type": "application/json" });
      return res.end(JSON.stringify(KEYED));
    }
    let raw = "";
    req.on("data", (chunk) => (raw += chunk));
    req.on("end", () => {
      const key = req.headers["idempotency-key"];
      seen.push({ method: req.method, path, type: req.headers["content-type"], key, body: raw ? JSON.parse(raw) : null });
      if (path === "/api/stock/shoppinglist/add-product") { res.writeHead(204); return res.end(); }
      if (path === "/api/stock/products/10/consume") {
        res.writeHead(409, { "content-type": "application/json" });
        return res.end(JSON.stringify({ error_message: "That key was used for a different request", code: "idempotency_conflict" }));
      }
      if (path === "/api/stock/products/11/consume") {
        res.writeHead(400, { "content-type": "application/json" });
        return res.end(JSON.stringify({ error_message: "Amount to be consumed cannot be > current stock amount" }));
      }
      if (path.endsWith("/consume")) {
        res.writeHead(200, { "content-type": "application/json", "Idempotent-Replayed": key === "replayed-key-01" ? "true" : "false" });
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
  assert.equal(seen[0].key, res.structuredContent.request_id);
  assert.equal(res.structuredContent.replayed, false);
});

test("over HTTP: Idempotent-Replayed: true is reported as a replay, not a second booking", async () => {
  const res = await call("consume_product", { product_id: 9, amount: 1, request_id: "replayed-key-01" });
  assert.equal(res.structuredContent.replayed, true);
  assert.match(res.content[0].text, /nothing new was booked/);
});

test("over HTTP: an idempotency_conflict says not to resend, and carries the code and request_id", async () => {
  const res = await call("consume_product", { product_id: 10, amount: 1, request_id: "conflict-key-1" });
  assert.equal(res.isError, true);
  const payload = JSON.parse(res.content[1].text);
  assert.equal(payload.error, "invalid_request");
  assert.equal(payload.code, "idempotency_conflict");
  assert.equal(payload.request_id, "conflict-key-1");
  assert.match(res.content[0].text, /do not resend it/);
});

test("over HTTP: Victual's error_message reaches the tool error; the status alone sets the category", async () => {
  const res = await call("consume_product", { product_id: 11, amount: 5 });
  const payload = JSON.parse(res.content[1].text);
  assert.equal(payload.error, "invalid_request");
  assert.match(payload.message, /cannot be > current stock amount/);
});

test("a Victual 403 on a write call is an isError result categorized forbidden", async () => {
  const res = await call("purchase_product", { product_id: 9, amount: 1 });
  assert.equal(res.isError, true);
  assert.equal(JSON.parse(res.content[1].text).error, "forbidden");
});

// --- retry advice (#850): a failed write may have been applied ------------------------------

const cred = { headers: { "VICTUAL-API-KEY": "k" } };
const clientConfig = { VICTUAL_BASE_URL: "http://victual.invalid", MCP_REQUEST_TIMEOUT_MS: 50 };

async function withFetch(fake, fn) {
  const real = globalThis.fetch;
  globalThis.fetch = fake;
  try { return await fn(); } finally { globalThis.fetch = real; }
}

const answers = (status) => async () => new Response("{}", { status });
const categoryOf = (promise) => promise.then(() => null, (e) => e.category);

test("503 is victual_unavailable for GET and POST: the schema-version middleware refuses before any handler runs", async () => {
  const client = new VictualClient(clientConfig);
  await withFetch(answers(503), async () => {
    assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable");
    assert.equal(await categoryOf(client.post("/api/x", cred, {})), "victual_unavailable");
  });
});

test("502 and 504 are outcome_unknown on POST and victual_unavailable on GET; other 5xx stay victual_error", async () => {
  const client = new VictualClient(clientConfig);
  for (const status of [502, 504]) {
    await withFetch(answers(status), async () => {
      assert.equal(await categoryOf(client.post("/api/x", cred, {})), "outcome_unknown", `POST ${status}`);
      assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable", `GET ${status}`);
    });
  }
  await withFetch(answers(500), async () => {
    assert.equal(await categoryOf(client.post("/api/x", cred, {})), "victual_error");
    assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_error");
  });
});

const hangUntilAborted = (_url, init) =>
  new Promise((_resolve, reject) => init.signal.addEventListener("abort", () => reject(init.signal.reason)));

function connectFailure(code) {
  const cause = Object.assign(new Error(`connect ${code}`), { code });
  return async () => { throw new TypeError("fetch failed", { cause }); };
}

test("client: a timeout on POST is outcome_unknown, on GET victual_unavailable", async () => {
  const client = new VictualClient(clientConfig);
  await withFetch(hangUntilAborted, async () => {
    assert.equal(await categoryOf(client.post("/api/x", cred, {})), "outcome_unknown");
    assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable");
  });
});

test("client: a connection that never opened stays victual_unavailable for POST", async () => {
  const client = new VictualClient(clientConfig);
  for (const code of ["ECONNREFUSED", "ENOTFOUND", "EAI_AGAIN"]) {
    await withFetch(connectFailure(code), async () => {
      assert.equal(await categoryOf(client.post("/api/x", cred, {})), "victual_unavailable", code);
    });
  }
});

test("client: a reset after the request was sent is outcome_unknown on POST, victual_unavailable on GET", async () => {
  const client = new VictualClient(clientConfig);
  await withFetch(connectFailure("ECONNRESET"), async () => {
    assert.equal(await categoryOf(client.post("/api/x", cred, {})), "outcome_unknown");
    assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable");
  });
});

test("tools/list: shopping_list needs STOCK_VIEW as well, because it also reads products and units", async () => {
  const lister = buildServer({
    VICTUAL_BASE_URL: `http://127.0.0.1:${victual.address().port}`,
    MCP_PORT: 0,
    MCP_ENABLED_TOOLS: ["stock_overview", "shopping_list"],
    MCP_REQUEST_TIMEOUT_MS: 2000,
    LOG_LEVEL: "error",
  });
  await lister.listen();
  const listWith = async (permissions) => {
    capabilities = { key_type: "mcp", read_only: true, permissions };
    const response = await fetch(`http://127.0.0.1:${lister.httpServer.address().port}/mcp`, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", "mcp-protocol-version": "2025-11-25", authorization: "Bearer good" },
      body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list" }),
    });
    return (await result(response)).tools.map((t) => t.name);
  };
  try {
    assert.deepEqual(await listWith(["SHOPPINGLIST_VIEW"]), []);
    assert.deepEqual(await listWith(["SHOPPINGLIST_VIEW", "STOCK_VIEW"]), ["stock_overview", "shopping_list"]);
  } finally {
    await lister.close();
  }
});

async function callOn(baseUrl, timeoutMs, name, args) {
  const sidecarUnderTest = buildServer({
    VICTUAL_BASE_URL: baseUrl,
    MCP_PORT: 0,
    MCP_ENABLED_TOOLS: ["stock_overview", "consume_product"],
    MCP_REQUEST_TIMEOUT_MS: timeoutMs,
    LOG_LEVEL: "error",
  });
  await sidecarUnderTest.listen();
  try {
    const response = await fetch(`http://127.0.0.1:${sidecarUnderTest.httpServer.address().port}/mcp`, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", "mcp-protocol-version": "2025-11-25", authorization: "Bearer good" },
      body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name, arguments: args } }),
    });
    return await result(response);
  } finally {
    await sidecarUnderTest.close();
  }
}

test("over HTTP: a write that times out says it may have been applied and is not retryable; a read timeout still says retry", async () => {
  // Answers the capability probe (no idempotency, so the booking is unkeyed), never the booking.
  const hanging = createServer((req, res) => {
    if (req.url.startsWith("/api/system/info")) { res.writeHead(200, { "content-type": "application/json" }); res.end("{}"); }
  });
  await new Promise((resolve) => hanging.listen(0, resolve));
  const url = `http://127.0.0.1:${hanging.address().port}`;
  try {
    const write = await callOn(url, 150, "consume_product", { product_id: 9, amount: 1 });
    assert.equal(write.isError, true);
    const payload = JSON.parse(write.content[1].text);
    assert.equal(payload.error, "outcome_unknown");
    assert.match(write.content[0].text, /may already have been applied/);
    assert.match(write.content[0].text, /check/i);
    assert.match(write.content[0].text, /retrying will not help/);

    const read = await callOn(url, 150, "stock_overview", {});
    assert.equal(JSON.parse(read.content[1].text).error, "victual_unavailable");
    assert.match(read.content[0].text, /retrying may help/);
  } finally {
    hanging.closeAllConnections();
    await new Promise((resolve) => hanging.close(resolve));
  }
});

test("over HTTP: a keyed write that times out says a retry with the same request_id and values is safe", async () => {
  const hanging = createServer((req, res) => {
    if (req.url.startsWith("/api/system/info")) { res.writeHead(200, { "content-type": "application/json" }); res.end(JSON.stringify(KEYED)); }
  });
  await new Promise((resolve) => hanging.listen(0, resolve));
  try {
    const write = await callOn(`http://127.0.0.1:${hanging.address().port}`, 150, "consume_product", { product_id: 9, amount: 1 });
    const payload = JSON.parse(write.content[1].text);
    assert.equal(payload.error, "outcome_unknown");
    assert.match(payload.request_id, /^[0-9a-f-]{36}$/);
    assert.match(write.content[0].text, new RegExp(`request_id ${payload.request_id} and exactly the same values cannot book it twice`));
  } finally {
    hanging.closeAllConnections();
    await new Promise((resolve) => hanging.close(resolve));
  }
});

test("over HTTP: a write to a port nobody listens on stays victual_unavailable and retryable", async () => {
  const probe = createServer();
  await new Promise((resolve) => probe.listen(0, resolve));
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  const write = await callOn(`http://127.0.0.1:${port}`, 2000, "consume_product", { product_id: 9, amount: 1 });
  assert.equal(JSON.parse(write.content[1].text).error, "victual_unavailable");
  assert.match(write.content[0].text, /retrying may help/);
});
