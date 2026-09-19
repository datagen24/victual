import { z } from "zod";

// Environment schema, per docs/mcp-interface-spec.md §8. Startup validates the whole
// environment and exits non-zero with a message on any unknown MCP_ENABLED_TOOLS entry
// or missing required variable — this schema is what makes that possible in one place.
//
// Notably absent, on purpose (§8): VICTUAL_API_KEY (credentials pass through, never
// stored — see src/auth/resolver.ts) and any TLS setting (the ingress's job).
export const ALL_READ_TOOLS = [
  "stock_overview",
  "expiring_soon",
  "missing_products",
  "find_product",
  "shopping_list",
  "recipes_i_can_cook",
] as const;

export type ToolName = (typeof ALL_READ_TOOLS)[number];

const envSchema = z.object({
  VICTUAL_BASE_URL: z.string().url(),
  MCP_PORT: z.coerce.number().int().positive().default(3000),
  MCP_ENABLED_TOOLS: z
    .string()
    .default("all-read")
    .transform((value) => (value === "all-read" ? [...ALL_READ_TOOLS] : value.split(","))),
  MCP_REQUEST_TIMEOUT_MS: z.coerce.number().int().positive().default(10000),
  LOG_LEVEL: z.enum(["error", "warn", "info", "debug"]).default("info"),
});

export type Config = Omit<z.infer<typeof envSchema>, "MCP_ENABLED_TOOLS"> & {
  MCP_ENABLED_TOOLS: ToolName[];
};

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = envSchema.safeParse(env);
  if (!parsed.success) {
    console.error("victual-mcp: invalid configuration");
    console.error(parsed.error.format());
    process.exit(1);
  }

  const config = parsed.data;
  const unknown = config.MCP_ENABLED_TOOLS.filter(
    (tool) => !(ALL_READ_TOOLS as readonly string[]).includes(tool),
  );
  if (unknown.length > 0) {
    console.error(
      `victual-mcp: unknown MCP_ENABLED_TOOLS entr${unknown.length === 1 ? "y" : "ies"}: ${unknown.join(", ")}`,
    );
    process.exit(1);
  }

  return config as Config;
}
