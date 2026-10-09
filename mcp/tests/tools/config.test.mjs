// ADR-0039: the sidecar asks Victual which tools are enabled on every tools/list and every
// tools/call, keeps nothing between requests, refuses a disabled tool as a tool error, and
// falls back to MCP_ENABLED_TOOLS when Victual answers 404 to GET /api/mcp/config.
// A fake Victual over real sockets, as in server.test.mjs.
import { test, afterEach } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { buildServer } from "../../dist/server.js";

const ALL_PERMS = ["STOCK_VIEW", "SHOPPINGLIST_VIEW", "RECIPES_VIEW", "SHOPPINGLIST_ITEMS_ADD", "STOCK_CONSUME", "STOCK_PURCHASE"];
const stack = [];

afterEach(async () => {
  while (stack.length) await stack.pop()();
});

/** A fake Victual. `state.config` is {status, body}; `state.capabilities` likewise. */
async function start({ envTools = ["stock_overview", "expiring_soon"], envWasSet = true, config, capabilities } = {}) {
  const state = {
    config: config ?? { status: 200, body: { enabled_tools: ["stock_overview"] } },
    capabilities: capabilities ?? { status: 200, body: { key_type: "mcp", read_only: false, permissions: ALL_PERMS } },
    requests: [],
  };
  const victual = createServer((req, res) => {
    const path = new URL(req.url, "http://x").pathname;
    state.requests.push({ method: req.method, path, key: req.headers["victual-api-key"], type: req.headers["victual-api-key-type"] });
    const keyOk = ["good", "ro"].includes(req.headers["victual-api-key"]);
    const send = (status, body) => {
      res.writeHead(status, { "content-type": "application/json" });
      res.end(JSON.stringify(body));
    };
    if (!keyOk) return send(401, {});
    if (path === "/api/mcp/config") return send(state.config.status, state.config.body);
    if (path === "/api/user/capabilities") return send(state.capabilities.status, state.capabilities.body);
    if (path.endsWith("/consume")) return send(200, [{ transaction_id: "tx-config", product_id: 9, amount: -1 }]);
    if (path === "/api/objects/quantity_units") return send(200, []);
    if (path === "/api/stock") return send(200, []);
    return send(403, {});
  });
  await new Promise((resolve) => victual.listen(0, resolve));

  const logs = [];
  const realError = console.error;
  const realLog = console.log;
  console.error = (line) => logs.push(String(line));
  console.log = (line) => logs.push(String(line));

  const sidecar = buildServer({
    VICTUAL_BASE_URL: `http://127.0.0.1:${victual.address().port}`,
    MCP_PORT: 0,
    MCP_ENABLED_TOOLS: envTools,
    mcpEnabledToolsWasSet: envWasSet,
    MCP_REQUEST_TIMEOUT_MS: 2000,
    LOG_LEVEL: "info",
  });
  await sidecar.listen();
  const base = `http://127.0.0.1:${sidecar.httpServer.address().port}`;
  stack.push(async () => {
    console.error = realError;
    console.log = realLog;
    await sidecar.close();
    await new Promise((resolve) => victual.close(resolve));
  });

  const rpc = (body, key = "good") =>
    fetch(`${base}/mcp`, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        accept: "application/json, text/event-stream",
        "mcp-protocol-version": "2025-11-25",
        authorization: `Bearer ${key}`,
      },
      body: JSON.stringify(body),
    });
  const result = async (response) => {
    const text = await response.text();
    const data = text.split("\n").find((line) => line.startsWith("data: "));
    return JSON.parse(data ? data.slice(6) : text).result;
  };
  return {
    state,
    logs,
    rpc,
    list: async (key) => (await result(await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, key))).tools.map((t) => t.name),
    call: async (name, args = {}, key) =>
      result(await rpc({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name, arguments: args } }, key)),
    count: (path) => state.requests.filter((r) => r.path === path).length,
  };
}

test("tools/list shows what Victual's config enables, not what the environment says", async () => {
  const v = await start({ envTools: ["stock_overview", "expiring_soon", "missing_products"], config: { status: 200, body: { enabled_tools: ["shopping_list", "consume_product"] } } });
  assert.deepEqual(await v.list(), ["shopping_list", "consume_product"]);
});

test("a change in Victual shows in the next tools/list with no restart, and nothing is cached", async () => {
  const v = await start();
  assert.deepEqual(await v.list(), ["stock_overview"]);
  v.state.config.body = { enabled_tools: ["stock_overview", "consume_product"] };
  assert.deepEqual(await v.list(), ["stock_overview", "consume_product"]);
  v.state.config.body = { enabled_tools: [] };
  assert.deepEqual(await v.list(), []);
  assert.equal(v.count("/api/mcp/config"), 3, "one config request per tools/list");
});

test("the capability filter still applies on top of the configured set", async () => {
  const v = await start({
    config: { status: 200, body: { enabled_tools: ["stock_overview", "consume_product"] } },
    capabilities: { status: 200, body: { key_type: "mcp", read_only: true, permissions: ALL_PERMS } },
  });
  assert.deepEqual(await v.list(), ["stock_overview"], "a read-only key never lists a write tool, enabled or not");
});

test("a disabled tool is refused at tools/call as a forbidden tool error, and Victual's data route is never called", async () => {
  const v = await start({ config: { status: 200, body: { enabled_tools: ["stock_overview"] } } });
  const res = await v.call("consume_product", { product_id: 9, amount: 1 });
  assert.equal(res.isError, true);
  const payload = JSON.parse(res.content[1].text);
  assert.equal(payload.error, "forbidden");
  assert.match(payload.message, /turned off in Victual's MCP settings/);
  assert.equal(payload.victual_status, undefined, "the refusal is the sidecar's, not a Victual status");
  assert.match(res.content[0].text, /retrying will not help/);
  assert.equal(v.state.requests.filter((r) => r.path.endsWith("/consume")).length, 0);
});

test("re-enabling a tool makes the next call work", async () => {
  const v = await start({ config: { status: 200, body: { enabled_tools: [] } } });
  assert.equal((await v.call("consume_product", { product_id: 9, amount: 1 })).isError, true);
  v.state.config.body = { enabled_tools: ["consume_product"] };
  const res = await v.call("consume_product", { product_id: 9, amount: 1 });
  assert.notEqual(res.isError, true);
  assert.equal(res.structuredContent.transaction_id, "tx-config");
});

test("tools/call fetches the config once per call and never probes capabilities", async () => {
  const v = await start({ config: { status: 200, body: { enabled_tools: ["consume_product"] } } });
  await v.call("consume_product", { product_id: 9, amount: 1 });
  await v.call("consume_product", { product_id: 9, amount: 1 });
  assert.equal(v.count("/api/mcp/config"), 2);
  assert.equal(v.count("/api/user/capabilities"), 0);
});

test("the config is fetched with the forwarded credential, and a read-only key can read it", async () => {
  const v = await start({
    config: { status: 200, body: { enabled_tools: ["stock_overview"] } },
    capabilities: { status: 200, body: { key_type: "mcp", read_only: true, permissions: ["STOCK_VIEW"] } },
  });
  assert.deepEqual(await v.list("ro"), ["stock_overview"]);
  const fetched = v.state.requests.find((r) => r.path === "/api/mcp/config");
  assert.equal(fetched.method, "GET");
  assert.equal(fetched.key, "ro");
  assert.equal(fetched.type, "mcp");
});

test("a 404 from /api/mcp/config falls back to MCP_ENABLED_TOOLS and logs that once", async () => {
  const v = await start({ envTools: ["stock_overview", "expiring_soon"], config: { status: 404, body: {} } });
  assert.deepEqual(await v.list(), ["stock_overview", "expiring_soon"]);
  assert.deepEqual(await v.list(), ["stock_overview", "expiring_soon"]);
  const ok = await v.call("stock_overview", {});
  assert.notEqual(ok.isError, true);
  assert.equal(v.logs.filter((line) => line.includes("/api/mcp/config answered 404")).length, 1);
});

test("on the fallback path a tool outside MCP_ENABLED_TOOLS is still an unknown tool", async () => {
  const v = await start({ envTools: ["stock_overview"], config: { status: 404, body: {} } });
  const response = await v.rpc({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name: "consume_product", arguments: { product_id: 9, amount: 1 } } });
  const text = await response.text();
  assert.match(text, /error/i);
  assert.equal(v.state.requests.filter((r) => r.path.endsWith("/consume")).length, 0);
});

test("when Victual serves the config, a set MCP_ENABLED_TOOLS is ignored and the sidecar says so once", async () => {
  const v = await start({ envWasSet: true });
  await v.list();
  await v.list();
  assert.equal(v.logs.filter((line) => line.includes("MCP_ENABLED_TOOLS is ignored")).length, 1);
  const quiet = await start({ envWasSet: false });
  await quiet.list();
  assert.equal(quiet.logs.filter((line) => line.includes("MCP_ENABLED_TOOLS is ignored")).length, 0);
});

test("a key Victual rejects is a 401 on tools/list and an unauthorized tool error on tools/call", async () => {
  const v = await start({ config: { status: 200, body: { enabled_tools: ["stock_overview"] } } });
  assert.equal((await v.rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, "bad")).status, 401);
  const res = await v.call("stock_overview", {}, "bad");
  assert.equal(res.isError, true);
  assert.equal(JSON.parse(res.content[1].text).error, "unauthorized");
});

test("a config body of the wrong shape is a victual_error, never an empty list", async () => {
  const v = await start({ config: { status: 200, body: { enabled: ["stock_overview"] } } });
  assert.equal((await v.rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" })).status, 502);
  const res = await v.call("stock_overview", {});
  assert.equal(res.isError, true);
  assert.equal(JSON.parse(res.content[1].text).error, "victual_error");
});

test("a tool name Victual lists that this build does not know is ignored", async () => {
  const v = await start({ config: { status: 200, body: { enabled_tools: ["stock_overview", "from_the_future"] } } });
  assert.deepEqual(await v.list(), ["stock_overview"]);
});
