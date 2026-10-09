import type { ResolvedCredential } from "../auth/resolver.js";
import { VictualApiError, type VictualClient } from "./client.js";

/** GET /api/mcp/config (ADR-0039 decision 3): the tools an administrator has enabled. */
export interface McpConfig {
  enabled_tools: string[];
}

/**
 * One fetch per tools/list and per tools/call, with the credential the request forwards and
 * nothing cached between requests (ADR-0039 decision 3, ADR-0007).
 *
 * Returns `null` when this Victual does not serve the endpoint (404): an older Victual, for
 * which the caller falls back to MCP_ENABLED_TOOLS (decision 6). Every other failure
 * propagates. A body that is not `{ enabled_tools: string[] }` is a `victual_error`, never an
 * empty list: reading a malformed answer as "nothing enabled" would silently hide every tool.
 */
export async function fetchMcpConfig(
  client: VictualClient,
  credential: ResolvedCredential,
): Promise<McpConfig | null> {
  let body: unknown;
  try {
    body = await client.get<unknown>("/api/mcp/config", credential);
  } catch (error) {
    if (error instanceof VictualApiError && error.category === "not_found") return null;
    throw error;
  }

  const tools = (body as { enabled_tools?: unknown } | null | undefined)?.enabled_tools;
  if (!Array.isArray(tools) || !tools.every((tool) => typeof tool === "string")) {
    throw new VictualApiError("victual_error", "Victual's /api/mcp/config answered an unexpected shape");
  }
  return { enabled_tools: tools as string[] };
}
