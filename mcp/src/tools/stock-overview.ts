import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { CurrentStockRow } from "../victual/types.js";
import { byDueDate, fetchUnitNames, normalizeDueDate, num, numOrNull, plural, unitName } from "../victual/shape.js";

// §5.1. Backed by GET /api/stock; rows sorted by due_date ascending, nulls last, so
// truncation by `limit` keeps the urgent entries.
const inputSchema = z.object({
  product_group_id: z.number().int().optional().describe("Only products in this product group"),
  limit: z.number().int().min(1).max(200).default(100).describe("Maximum rows (default 100, max 200)"),
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

const outputSchema = z.object({ total: z.number(), rows: z.array(rowSchema) });

export const stockOverview: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "stock_overview",
  title: "Stock overview",
  description:
    "What is in stock right now: one row per product with amount, opened amount, unit and next due date, soonest due first.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const [stock, units] = await Promise.all([
      ctx.client.get<CurrentStockRow[]>("/api/stock", ctx.credential),
      fetchUnitNames(ctx),
    ]);

    const all = stock
      .map((row) => ({
        product_id: num(row.product_id),
        name: row.product?.name ?? "",
        amount: num(row.amount),
        amount_opened: num(row.amount_opened),
        unit: unitName(units, row.product?.qu_id_stock),
        due_date: normalizeDueDate(row.best_before_date),
        product_group_id: numOrNull(row.product?.product_group_id),
      }))
      .filter((row) => input.product_group_id === undefined || row.product_group_id === input.product_group_id)
      .sort(byDueDate);

    const rows = all.slice(0, input.limit);
    const soonest = rows.find((row) => row.due_date !== null);
    const text =
      `${plural(all.length, "product")} in stock` +
      (rows.length < all.length ? `; showing ${rows.length}` : "") +
      (soonest ? `, soonest due ${soonest.name} on ${soonest.due_date}.` : ".");

    return { data: { total: all.length, rows }, text };
  },
};
