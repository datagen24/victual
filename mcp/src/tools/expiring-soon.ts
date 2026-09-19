import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.2. Backed by GET /api/stock/volatile?due_soon_days={days}. Three sections, not
// three tools — a model asking about expiry wants all three severities in one answer.
const inputSchema = z.object({
  days: z.number().int().min(1).max(30).default(5),
  limit: z.number().int().min(1).max(200).default(50),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  amount: z.number(),
  unit: z.string(),
  due_date: z.string().nullable(),
});

const outputSchema = z.object({
  due: z.array(rowSchema),
  overdue: z.array(rowSchema),
  expired: z.array(rowSchema),
});

export const expiringSoon: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "expiring_soon",
  permission: "STOCK",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("expiring_soon: not implemented — see docs/mcp-interface-spec.md §5.2");
  },
};
