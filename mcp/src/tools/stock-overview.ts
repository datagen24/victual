import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.1. Backed by GET /api/stock; rows sorted by due_date ascending, nulls last, so
// truncation by `limit` keeps the urgent entries.
const inputSchema = z.object({
  product_group_id: z.number().int().optional(),
  limit: z.number().int().min(1).max(200).default(100),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  amount: z.number(),
  amount_opened: z.number(),
  unit: z.string(),
  due_date: z.string().nullable(),
  product_group_id: z.number().nullable(),
});

const outputSchema = z.object({ rows: z.array(rowSchema) });

export const stockOverview: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "stock_overview",
  permission: "STOCK",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("stock_overview: not implemented — see docs/mcp-interface-spec.md §5.1");
  },
};
