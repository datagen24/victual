import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.3. Backed by GET /api/stock/volatile -> missing_products. Plan 03 deliberately
// keeps group shortfalls out of this view; when it lands, group shortfalls become a new
// section here (additive), not a change to this row shape.
const inputSchema = z.object({
  limit: z.number().int().min(1).max(200).default(50),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  amount_missing: z.number(),
  is_partly_in_stock: z.boolean(),
});

const outputSchema = z.object({ rows: z.array(rowSchema) });

export const missingProducts: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "missing_products",
  permission: "STOCK",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("missing_products: not implemented — see docs/mcp-interface-spec.md §5.3");
  },
};
