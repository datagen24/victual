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
  "list_locations",
  "list_quantity_units",
  "list_product_groups",
  "lookup_barcode",
  "recent_transactions",
] as const;

// §6 write tools: never part of `all-read`, so enabling one is a deliberate act.
// §6's stock and list writes: the three of 0.5.3 and ADR-0052 decision 1's six (set_due_date
// by its 2026-10-23 amendment).
export const WRITE_TOOLS = [
  "add_to_shopping_list",
  "consume_product",
  "purchase_product",
  "inventory_product",
  "open_product",
  "transfer_product",
  "undo_transaction",
  "check_off_shopping_list_item",
  "set_due_date",
] as const;

// ADR-0052 decision 1's master-data writes; deletion stays in the UI (decision 2).
export const CATALOG_WRITE_TOOLS = [
  "create_product",
  "update_product",
  "create_location",
  "create_quantity_unit",
  "create_product_group",
  "add_barcode",
] as const;

export type ToolName =
  | (typeof ALL_READ_TOOLS)[number]
  | (typeof WRITE_TOOLS)[number]
  | (typeof CATALOG_WRITE_TOOLS)[number];

// Keywords that expand in place inside MCP_ENABLED_TOOLS (ADR-0052 decision 5). Write tools
// are still never part of `all-read`: `stock-write` names them as a group, deliberately.
export const TOOL_KEYWORDS: Readonly<Record<string, readonly ToolName[]>> = {
  "all-read": ALL_READ_TOOLS,
  "stock-write": WRITE_TOOLS,
  "catalog-write": CATALOG_WRITE_TOOLS,
};

const envSchema = z.object({
  VICTUAL_BASE_URL: z.string().url(),
  MCP_PORT: z.coerce.number().int().positive().default(3000),
  MCP_ENABLED_TOOLS: z
    .string()
    .default("all-read")
    // Keywords expand in place, so `all-read,consume_product` is the reads plus one write.
    .transform((value) => [
      ...new Set(
        value.split(",").flatMap((entry) => {
          const name = entry.trim();
          return name in TOOL_KEYWORDS ? [...TOOL_KEYWORDS[name]] : [name];
        }),
      ),
    ]),
  MCP_REQUEST_TIMEOUT_MS: z.coerce.number().int().positive().default(10000),
  LOG_LEVEL: z.enum(["error", "warn", "info", "debug"]).default("info"),
});

export type Config = Omit<z.infer<typeof envSchema>, "MCP_ENABLED_TOOLS"> & {
  MCP_ENABLED_TOOLS: ToolName[];
};

/**
 * Parse and validate the §8 environment, expanding tool keywords. Exits the process with a
 * message on anything invalid, so a bad deployment fails at startup, not on first use.
 */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const parsed = envSchema.safeParse(env);
  if (!parsed.success) {
    console.error("victual-mcp: invalid configuration");
    console.error(parsed.error.format());
    process.exit(1);
  }

  const config = parsed.data;
  const unknown = config.MCP_ENABLED_TOOLS.filter(
    (tool) => ![...ALL_READ_TOOLS, ...WRITE_TOOLS, ...CATALOG_WRITE_TOOLS].includes(tool as ToolName),
  );
  if (unknown.length > 0) {
    console.error(
      `victual-mcp: unknown MCP_ENABLED_TOOLS entr${unknown.length === 1 ? "y" : "ies"}: ${unknown.join(", ")}`,
    );
    process.exit(1);
  }

  return config as Config;
}
