import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.4. Backed by GET /api/objects/products?query[]=name~{query} + GET /api/stock for
// amounts. Substring match server-side; no fuzzy layer in v1.
const inputSchema = z.object({
  query: z.string().min(1),
  limit: z.number().int().min(1).max(25).default(10),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  product_group_id: z.number().nullable(),
  unit: z.string(),
  in_stock_amount: z.number(),
});

const outputSchema = z.object({ rows: z.array(rowSchema) });

export const findProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "find_product",
  permission: "STOCK",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("find_product: not implemented — see docs/mcp-interface-spec.md §5.4");
  },
};
