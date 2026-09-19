import { loadConfig } from "./config.js";
import { buildServer } from "./server.js";

const config = loadConfig();
const server = buildServer(config);

console.log(
  `victual-mcp: ${server.enabledTools.length} tool(s) enabled — scaffold only, not yet listening on ${config.MCP_PORT}`,
);
