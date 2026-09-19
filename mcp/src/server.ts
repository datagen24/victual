/**
 * SDK wiring: Streamable HTTP at POST /mcp, GET /healthz (§3, §5).
 *
 * This is the piece most tied to the TypeScript SDK v2's actual API, which the spec
 * (written 2026-08-29) describes from a reading rather than a build. Wiring it up
 * against the real package is the local session's first job — see mcp/README.md.
 *
 * What is fixed here, independent of the SDK's exact surface: server/discover,
 * tools/list filtered by GET /api/user/capabilities (§5, not yet built on the Victual
 * side either), and tools/call never re-probing capabilities itself — a race between
 * list and call surfaces as an honest `forbidden` from the forwarded REST call.
 */
import type { Config } from "./config.js";
import { ALL_TOOLS } from "./tools/index.js";
import { VictualClient } from "./victual/client.js";
import { resolveCredential, UnauthenticatedError } from "./auth/resolver.js";

export function buildServer(config: Config) {
  const client = new VictualClient(config);
  const enabled = new Set<string>(config.MCP_ENABLED_TOOLS);
  const enabledTools = ALL_TOOLS.filter((tool) => enabled.has(tool.name));

  // TODO(local session): wire `enabledTools` and `client` into the real
  // @modelcontextprotocol SDK v2 server + Streamable HTTP transport.
  void client;
  void resolveCredential;
  void UnauthenticatedError;

  return { enabledTools };
}
