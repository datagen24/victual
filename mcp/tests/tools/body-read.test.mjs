// #895: the request timeout covers the body read, and a failure while reading a 2xx body
// is classified like a failed request: outcome_unknown for a write (it was probably applied),
// victual_unavailable for a read.
import { test } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { VictualClient } from "../../dist/victual/client.js";

const cred = { headers: { "VICTUAL-API-KEY": "k" } };
const config = { VICTUAL_BASE_URL: "http://victual.invalid", MCP_REQUEST_TIMEOUT_MS: 50 };
const opts = { timeout: 5000 };

async function withFetch(fake, fn) {
  const real = globalThis.fetch;
  globalThis.fetch = fake;
  try { return await fn(); } finally { globalThis.fetch = real; }
}

const categoryOf = (promise) => promise.then(() => null, (e) => e.category);

// Headers arrive at once; the body then behaves as `start` says. Like undici, an abort of the
// request signal errors the body stream.
const withBody = (start, status = 200) => async (_url, init) => {
  const body = new ReadableStream({
    start(controller) {
      init.signal.addEventListener("abort", () => controller.error(init.signal.reason));
      start(controller);
    },
  });
  return new Response(body, { status });
};

const cases = {
  stall: withBody(() => {}),
  truncation: withBody((c) => { c.enqueue(new TextEncoder().encode('{"transact')); c.error(new TypeError("terminated")); }),
  "invalid JSON": withBody((c) => { c.enqueue(new TextEncoder().encode("not json")); c.close(); }),
};

for (const [name, fake] of Object.entries(cases)) {
  test(`a 2xx POST whose body ends in ${name} is outcome_unknown`, opts, async () => {
    const client = new VictualClient(config);
    await withFetch(fake, async () => {
      assert.equal(await categoryOf(client.post("/api/x", cred, {})), "outcome_unknown");
    });
  });

  test(`a GET whose body ends in ${name} is victual_unavailable`, opts, async () => {
    const client = new VictualClient(config);
    await withFetch(fake, async () => {
      assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable");
    });
  });
}

test("a 204 POST with an empty body still succeeds", opts, async () => {
  const client = new VictualClient(config);
  await withFetch(async () => new Response(null, { status: 204 }), async () => {
    assert.equal(await client.post("/api/x", cred, {}), undefined);
  });
});

test("a non-2xx answer keeps its status category even if its body would stall", opts, async () => {
  const client = new VictualClient(config);
  await withFetch(withBody(() => {}, 403), async () => {
    const error = await client.post("/api/x", cred, {}).catch((e) => e);
    assert.equal(error.category, "forbidden");
    assert.equal(error.victualStatus, 403);
  });
});

test("over a real socket, a body that stops after the headers times out as outcome_unknown", opts, async (t) => {
  const sockets = new Set();
  const stalled = createServer((_req, res) => {
    res.writeHead(200, { "content-type": "application/json", "content-length": "100" });
    res.write('{"transaction_id":');
  });
  stalled.on("connection", (s) => sockets.add(s));
  await new Promise((resolve) => stalled.listen(0, "127.0.0.1", resolve));
  // t.after, not try/finally: on a regression the awaits below never settle, and only a
  // registered hook still runs when node:test times the test out.
  t.after(async () => {
    for (const s of sockets) s.destroy();
    await new Promise((resolve) => stalled.close(resolve));
  });
  const client = new VictualClient({ ...config, VICTUAL_BASE_URL: `http://127.0.0.1:${stalled.address().port}` });
  assert.equal(await categoryOf(client.post("/api/x", cred, {})), "outcome_unknown");
  assert.equal(await categoryOf(client.get("/api/x", cred)), "victual_unavailable");
});
