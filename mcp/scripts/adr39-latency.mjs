// ADR-0039 prerequisite 3, spike evidence: what the extra GET /api/mcp/config costs.
//
// Dependency-free on purpose: it runs from a pod built from the sidecar's own image (which
// carries node and no shell) with this file mounted from a ConfigMap, so the load generator
// sits in the same cluster as the sidecar and Victual and the numbers do not include a
// kubectl port-forward.
//
//   node adr39-latency.mjs <mcp-url> <victual-url> <key> <iterations> <label>
//
// Sequential, one request at a time (latency, not throughput). Per iteration it times
//   list    POST /mcp tools/list                      (stateless 2025-11-25 request)
//   call    POST /mcp tools/call stock_overview
//   config  GET  <victual>/api/mcp/config             (the added request, called directly)
//   caps    GET  <victual>/api/user/capabilities      (the request tools/list already paid for)
// and prints one JSON line of milliseconds: p50, p95, p99, mean, min, max per series.
const [mcpUrl, victualUrl, key, iterationsArg, label] = process.argv.slice(2);
const iterations = Number(iterationsArg);
if (!mcpUrl || !victualUrl || !key || !Number.isInteger(iterations) || iterations < 1) {
  console.error("usage: node adr39-latency.mjs <mcp-url> <victual-url> <key> <iterations> <label>");
  process.exit(2);
}

const rpcHeaders = {
  "content-type": "application/json",
  accept: "application/json, text/event-stream",
  "mcp-protocol-version": "2025-11-25",
  authorization: `Bearer ${key}`,
};
const rpc = (body) => fetch(mcpUrl, { method: "POST", headers: rpcHeaders, body: JSON.stringify(body) });
const restHeaders = { "VICTUAL-API-KEY": key, "VICTUAL-API-KEY-TYPE": "mcp", accept: "application/json" };

const series = {
  list: async () => {
    const response = await rpc({ jsonrpc: "2.0", id: 1, method: "tools/list" });
    const text = await response.text();
    if (response.status !== 200 || !text.includes("stock_overview")) throw new Error(`tools/list ${response.status} ${text.slice(0, 200)}`);
  },
  call: async () => {
    const response = await rpc({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name: "stock_overview", arguments: {} } });
    const text = await response.text();
    if (response.status !== 200 || text.includes('"isError":true')) throw new Error(`tools/call ${response.status} ${text.slice(0, 200)}`);
  },
  config: async () => {
    const response = await fetch(new URL("/api/mcp/config", victualUrl), { headers: restHeaders });
    await response.text();
    if (response.status !== 200 && response.status !== 404) throw new Error(`config ${response.status}`);
  },
  caps: async () => {
    const response = await fetch(new URL("/api/user/capabilities", victualUrl), { headers: restHeaders });
    await response.text();
    if (response.status !== 200) throw new Error(`capabilities ${response.status}`);
  },
};

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)];
const round = (n) => Math.round(n * 100) / 100;

const out = { label, iterations, at: new Date().toISOString() };
for (const [name, run] of Object.entries(series)) {
  for (let i = 0; i < 30; i++) await run(); // warm-up, not recorded
  const samples = [];
  for (let i = 0; i < iterations; i++) {
    const start = performance.now();
    await run();
    samples.push(performance.now() - start);
  }
  const sorted = [...samples].sort((a, b) => a - b);
  out[name] = {
    p50: round(pct(sorted, 50)),
    p95: round(pct(sorted, 95)),
    p99: round(pct(sorted, 99)),
    mean: round(samples.reduce((a, b) => a + b, 0) / samples.length),
    min: round(sorted[0]),
    max: round(sorted[sorted.length - 1]),
  };
}
console.log(JSON.stringify(out));
