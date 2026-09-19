#!/usr/bin/env node
import { loadConfig } from "./config.js";
import { buildServer } from "./server.js";

const config = loadConfig();
const server = buildServer(config);
await server.listen();

console.log(
  `victual-mcp: listening on :${config.MCP_PORT}, ${server.enabledTools.length} tool(s) enabled, Victual at ${config.VICTUAL_BASE_URL}`,
);

// SIGTERM is what Kubernetes sends; there is no state to flush, only in-flight requests.
for (const signal of ["SIGTERM", "SIGINT"] as const) {
  process.once(signal, () => {
    void server.close().finally(() => process.exit(0));
  });
}
