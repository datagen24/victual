/**
 * SDK wiring: Streamable HTTP at POST /mcp, GET /healthz (§3, §5).
 *
 * `createMcpHandler` serves the stateless 2026-07-28 revision from a per-request server
 * factory and falls back to stateless serving for 2025-11-25 clients (§1) — so each
 * request gets a fresh McpServer and nothing survives between requests (§2). That is
 * also what makes the §5 capability filter natural: the factory registers only the tools
 * this request's key may see.
 *
 * The factory is handed, through `authInfo` (which the SDK forwards verbatim and never
 * derives itself), the credential and the tools to register. Only a request carrying
 * tools/list pays for the capability probe; tools/call never probes and registers every
 * enabled tool — Victual enforces on the forwarded call, and a race between list and
 * call surfaces as an honest `forbidden` (§7).
 */
import { createServer, type IncomingMessage, type Server as HttpServer } from "node:http";
import { createMcpHandler, McpServer, type AuthInfo } from "@modelcontextprotocol/server";
import type { Config } from "./config.js";
import { ALL_TOOLS } from "./tools/index.js";
import type { ToolContext } from "./tools/types.js";
import { VictualApiError, VictualClient } from "./victual/client.js";
import { probeCapabilities } from "./victual/capabilities.js";
import { resolveCredential, UnauthenticatedError, type ResolvedCredential } from "./auth/resolver.js";
import { createLogger, type Logger } from "./log.js";

export const SERVER_NAME = "victual-mcp";
export const SERVER_VERSION = process.env.VICTUAL_MCP_VERSION ?? "0.1.0";

/** §5: the list varies per key, so it is private; five minutes bounds staleness. */
const TOOLS_LIST_CACHE = { ttlMs: 300_000, cacheScope: "private" as const };

/** Bodies above this are not a request this server has any tool for. */
const MAX_BODY_BYTES = 1 << 20;

interface RequestExtra {
  credential: ResolvedCredential;
  /** The tools this request's server registers: all enabled ones, or the §5-filtered list. */
  visible: readonly AnyTool[];
  [key: string]: unknown;
}

type AnyTool = (typeof ALL_TOOLS)[number];

function toolErrorResult(error: unknown) {
  const apiError =
    error instanceof VictualApiError
      ? error
      : new VictualApiError("victual_error", error instanceof Error ? error.message : String(error));
  const retryable = apiError.category === "victual_unavailable";
  const payload = {
    error: apiError.category,
    message: apiError.message,
    ...(apiError.victualStatus !== undefined ? { victual_status: apiError.victualStatus } : {}),
  };
  return {
    isError: true,
    content: [
      {
        type: "text" as const,
        text: `${apiError.category}: ${apiError.message} — ${retryable ? "retrying may help" : "retrying will not help"}.`,
      },
      { type: "text" as const, text: JSON.stringify(payload) },
    ],
  };
}

function registerTool(server: McpServer, tool: AnyTool, context: ToolContext, log: Logger) {
  // One registration shape for all six; the cast is the price of a heterogeneous array
  // of tools whose input types differ.
  const definition = tool as unknown as {
    name: string;
    title: string;
    description: string;
    inputSchema: AnyTool["inputSchema"];
    outputSchema: AnyTool["outputSchema"];
    handler: (input: unknown, ctx: ToolContext) => Promise<{ data: Record<string, unknown>; text: string }>;
  };

  server.registerTool(
    definition.name,
    {
      title: definition.title,
      description: definition.description,
      inputSchema: definition.inputSchema,
      outputSchema: definition.outputSchema,
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async (input: unknown) => {
      try {
        const result = await definition.handler(input, context);
        return {
          content: [{ type: "text" as const, text: result.text }],
          structuredContent: result.data,
        };
      } catch (error) {
        log.warn(`tool ${definition.name} failed: ${error instanceof Error ? error.message : String(error)}`);
        return toolErrorResult(error);
      }
    },
  );
}

export function buildServer(config: Config) {
  const log = createLogger(config.LOG_LEVEL);
  const client = new VictualClient(config);
  const enabled = new Set<string>(config.MCP_ENABLED_TOOLS);
  const enabledTools = ALL_TOOLS.filter((tool) => enabled.has(tool.name));

  const handler = createMcpHandler(
    ({ authInfo }) => {
      const extra = authInfo?.extra as RequestExtra | undefined;
      if (!extra) throw new Error("request reached the MCP handler without a resolved credential");

      const server = new McpServer(
        { name: SERVER_NAME, title: "Victual", version: SERVER_VERSION },
        { capabilities: { tools: {} }, cacheHints: { "tools/list": TOOLS_LIST_CACHE } },
      );
      const context: ToolContext = { client, credential: extra.credential };
      for (const tool of extra.visible) registerTool(server, tool, context, log);
      return server;
    },
    { onerror: (error) => log.warn(`mcp: ${error.message}`) },
  );

  const httpServer = createServer((req, res) => {
    const fail = (error: unknown) => {
      log.error(`unhandled: ${error instanceof Error ? (error.stack ?? error.message) : String(error)}`);
      if (!res.headersSent) {
        res.writeHead(500);
        res.end();
      } else {
        // Mid-stream: the status line is already out, so the only honest signal left is
        // cutting the connection rather than ending a response that looks complete.
        res.destroy();
      }
    };

    // One chain, so a failure while streaming the SDK's response body (a rejected
    // async iterator) is caught too - not only a failure producing the Response. Left
    // unhandled, that rejection terminates Node.
    handle(req)
      .then(async (response) => {
        res.writeHead(response.status, Object.fromEntries(response.headers));
        if (response.body) {
          for await (const chunk of response.body) {
            if (res.destroyed) break;
            res.write(chunk);
          }
        }
        res.end();
      })
      .catch(fail);
  });

  async function handle(req: IncomingMessage): Promise<Response> {
    const url = new URL(req.url ?? "/", "http://localhost");

    // §3: unauthenticated liveness, empty body, reveals nothing about the deployment.
    if (url.pathname === "/healthz") {
      return new Response(null, { status: req.method === "GET" || req.method === "HEAD" ? 200 : 405 });
    }
    if (url.pathname !== "/mcp") return new Response(null, { status: 404 });

    // §4.1: no credential is a 401 before any JSON-RPC processing.
    let credential: ResolvedCredential;
    try {
      credential = resolveCredential(req.headers);
    } catch (error) {
      if (error instanceof UnauthenticatedError) {
        return new Response(JSON.stringify({ error: "unauthorized", message: error.message }), {
          status: 401,
          headers: { "content-type": "application/json", "www-authenticate": 'Bearer realm="victual-mcp"' },
        });
      }
      throw error;
    }

    const body = req.method === "POST" ? await readBody(req) : undefined;
    if (body === null) return new Response(null, { status: 413 });

    const methods = body === undefined ? [] : jsonRpcMethods(body);
    const request = new Request(new URL(url.pathname + url.search, `http://${req.headers.host ?? "localhost"}`), {
      method: req.method,
      headers: Object.entries(req.headers).flatMap(([name, value]) =>
        value === undefined ? [] : (Array.isArray(value) ? value : [value]).map((v) => [name, v] as [string, string]),
      ),
      body,
    });

    // The capability probe runs here rather than in the factory: the SDK answers a
    // factory exception with its own 500, and a key Victual rejects is a 401, not a
    // server fault. It is the only Victual call outside a tool, so its failure has no
    // tool result to become — it becomes the HTTP status it is.
    let visible: readonly AnyTool[] = enabledTools;
    if (methods.includes("tools/list")) {
      try {
        visible = await visibleTools(credential);
      } catch (error) {
        if (!(error instanceof VictualApiError)) throw error;
        const status = error.category === "unauthorized" ? 401 : error.category === "forbidden" ? 403 : 502;
        return new Response(JSON.stringify({ error: error.category, message: error.message }), {
          status,
          headers: { "content-type": "application/json" },
        });
      }
    }

    const authInfo: AuthInfo = {
      token: credential.headers["VICTUAL-API-KEY"] ?? "",
      clientId: SERVER_NAME,
      scopes: [],
      extra: { credential, visible } satisfies RequestExtra,
    };
    return handler.fetch(request, { authInfo });
  }

  async function visibleTools(credential: ResolvedCredential): Promise<readonly AnyTool[]> {
    const capabilities = await probeCapabilities(client, credential);
    if (capabilities === null) {
      log.debug("GET /api/user/capabilities answered 404; serving tools/list unfiltered");
      return enabledTools;
    }
    const held = new Set(capabilities.permissions);
    return enabledTools.filter((tool) => held.has(tool.permission));
  }

  return {
    enabledTools,
    httpServer,
    listen(): Promise<HttpServer> {
      return new Promise((resolve) => httpServer.listen(config.MCP_PORT, () => resolve(httpServer)));
    },
    async close(): Promise<void> {
      await handler.close();
      await new Promise<void>((resolve) => httpServer.close(() => resolve()));
    },
  };
}

async function readBody(req: IncomingMessage): Promise<Uint8Array | null> {
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const chunk of req) {
    size += (chunk as Buffer).length;
    if (size > MAX_BODY_BYTES) return null;
    chunks.push(chunk as Buffer);
  }
  return new Uint8Array(Buffer.concat(chunks));
}

function jsonRpcMethods(body: Uint8Array): string[] {
  try {
    const parsed: unknown = JSON.parse(Buffer.from(body).toString("utf8"));
    const messages = Array.isArray(parsed) ? parsed : [parsed];
    return messages.flatMap((message) =>
      message && typeof message === "object" && typeof (message as { method?: unknown }).method === "string"
        ? [(message as { method: string }).method]
        : [],
    );
  } catch {
    return []; // Not JSON: the SDK answers it with the protocol's parse error.
  }
}
