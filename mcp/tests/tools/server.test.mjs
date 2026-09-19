// The HTTP face: /healthz, the pre-JSON-RPC 401, the capability-filtered tools/list and
// §7's tool-error shape — against a throwaway fake Victual, over real sockets.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { buildServer } from "../../dist/server.js";

let victual, sidecar, base;
let capabilities = { status: 200, body: { key_type: "mcp", read_only: true, permissions: ["STOCK_VIEW"] } };

before(async () => {
  victual = createServer((req, res) => {
    const path = new URL(req.url, "http://x").pathname;
    if (req.headers["victual-api-key"] !== "good") { res.writeHead(401); return res.end("{}"); }
    if (path === "/api/user/capabilities") {
      res.writeHead(capabilities.status, { "content-type": "application/json" });
      return res.end(JSON.stringify(capabilities.body));
    }
    // Every data route is 403, not just /api/stock: a tool fetches several in parallel,
    // and whichever rejects first decides the category - a 404 on the unit lookup raced
    // the 403 this test asserts, and won about one run in four.
    res.writeHead(403); res.end("{}");
  });
  await new Promise((resolve) => victual.listen(0, resolve));
  sidecar = buildServer({
    VICTUAL_BASE_URL: `http://127.0.0.1:${victual.address().port}`,
    MCP_PORT: 0,
    MCP_ENABLED_TOOLS: ["stock_overview", "expiring_soon", "shopping_list", "recipes_i_can_cook"],
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

const rpc = (body, key = "good") =>
  fetch(`${base}/mcp`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json, text/event-stream",
      "mcp-protocol-version": "2025-11-25",
      ...(key ? { authorization: `Bearer ${key}` } : {}),
    },
    body: JSON.stringify(body),
  });

async function result(response) {
  const text = await response.text();
  const data = text.split("\n").find((line) => line.startsWith("data: "));
  return JSON.parse(data ? data.slice(6) : text).result;
}

test("/healthz is 200 with an empty body and no auth", async () => {
  const response = await fetch(`${base}/healthz`);
  assert.equal(response.status, 200);
  assert.equal(await response.text(), "");
});

test("no credential is 401 before any JSON-RPC processing", async () => {
  const response = await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, null);
  assert.equal(response.status, 401);
  assert.match(response.headers.get("www-authenticate"), /^Bearer/);
});

test("tools/list shows only enabled tools the key's user may use, in fixed order", async () => {
  capabilities = { status: 200, body: { key_type: "mcp", read_only: true, permissions: ["STOCK_VIEW", "RECIPES_VIEW"] } };
  const { tools } = await result(await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }));
  assert.deepEqual(tools.map((t) => t.name), ["stock_overview", "expiring_soon", "recipes_i_can_cook"]);
  assert.ok(tools.every((t) => t.annotations.readOnlyHint === true));
});

test("tools/list is served unfiltered while Victual lacks the capabilities endpoint (404)", async () => {
  capabilities = { status: 404, body: {} };
  const { tools } = await result(await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }));
  assert.deepEqual(tools.map((t) => t.name), ["stock_overview", "expiring_soon", "shopping_list", "recipes_i_can_cook"]);
});

test("tools/list with a key Victual rejects is a 401, not an empty list", async () => {
  const response = await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" }, "bad");
  assert.equal(response.status, 401);
});

test("a Victual 403 on tools/call is an isError result categorized forbidden", async () => {
  const res = await result(await rpc({ jsonrpc: "2.0", id: 1, method: "tools/call", params: { name: "stock_overview", arguments: {} } }));
  assert.equal(res.isError, true);
  const payload = JSON.parse(res.content[1].text);
  assert.equal(payload.error, "forbidden");
  assert.equal(payload.victual_status, 403);
  assert.match(res.content[0].text, /retrying will not help/);
});

test("the resolver forwards the key and asks Victual for an MCP-type key", async () => {
  const { resolveCredential, UnauthenticatedError } = await import("../../dist/auth/resolver.js");
  assert.deepEqual(resolveCredential(new Headers({ authorization: "Bearer abc" })).headers, {
    "VICTUAL-API-KEY": "abc",
    "VICTUAL-API-KEY-TYPE": "mcp",
  });
  assert.equal(resolveCredential({ "victual-api-key": "def" }).headers["VICTUAL-API-KEY"], "def");
  assert.throws(() => resolveCredential(new Headers()), UnauthenticatedError);
});
