// A real-client probe of a running sidecar, using the official SDK v2 client — the
// same negotiation a 2026-07-28 client performs (server/discover), not a hand-built
// envelope. Usage: node scripts/probe.mjs <url> <key> [tool] [json-args]
//
// PROBE_NEGOTIATION selects the client's versionNegotiation mode: `auto` (default —
// server/discover first, falling back to the 2025 handshake), `legacy` (2025 only), or a
// revision to pin (`2026-07-28`), which fails loudly instead of falling back. The SDK
// client's own default is `legacy`, so a probe that does not say gets 2025 silently.
import { Client, StreamableHTTPClientTransport } from "@modelcontextprotocol/client";

const [url, key, tool, args] = process.argv.slice(2);
if (!url || !key) {
  console.error("usage: node scripts/probe.mjs <url> <key> [tool] [json-args]");
  process.exit(2);
}

const negotiation = process.env.PROBE_NEGOTIATION ?? "auto";
const mode = negotiation === "auto" || negotiation === "legacy" ? negotiation : { pin: negotiation };
const client = new Client({ name: "victual-mcp-probe", version: "0.0.0" }, { versionNegotiation: { mode } });
const transport = new StreamableHTTPClientTransport(new URL(url), {
  requestInit: { headers: { authorization: `Bearer ${key}` } },
});
await client.connect(transport);

console.log("server:", JSON.stringify(client.getServerVersion()));
console.log("protocol:", client.getNegotiatedProtocolVersion(), `(${client.getProtocolEra()})`);
const { tools } = await client.listTools();
console.log("tools:", tools.map((t) => t.name).join(", "));

if (tool) {
  const result = await client.callTool({ name: tool, arguments: args ? JSON.parse(args) : {} });
  console.log(JSON.stringify(result, null, 2));
}
await client.close();
