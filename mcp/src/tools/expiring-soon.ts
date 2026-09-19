import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { CurrentStockRow, VolatileStock } from "../victual/types.js";
import { byDueDate, fetchUnitNames, list, normalizeDueDate, num, plural, unitName } from "../victual/shape.js";

// §5.2. Backed by GET /api/stock/volatile?due_soon_days={days}. Three sections, not
// three tools — a model asking about expiry wants all three severities in one answer.
const inputSchema = z.object({
  days: z.number().int().min(1).max(30).default(5).describe("How many days ahead counts as due soon (default 5, max 30)"),
  limit: z.number().int().min(1).max(200).default(50).describe("Maximum rows per section (default 50)"),
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
  title: "Expiring soon",
  description:
    "Products due within the next few days, already overdue (best before passed), or expired (use-by passed), in three sections.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const params = new URLSearchParams({ due_soon_days: String(input.days) });
    const [volatile, units] = await Promise.all([
      ctx.client.get<VolatileStock>("/api/stock/volatile", ctx.credential, params),
      fetchUnitNames(ctx),
    ]);

    // Each section must be present: a missing one is a response this sidecar does not
    // understand, not "nothing is expiring".
    const shape = (rows: CurrentStockRow[] | undefined, section: string) =>
      list(rows, section)
        .map((row) => ({
          product_id: num(row.product_id),
          name: row.product?.name ?? "",
          amount: num(row.amount),
          unit: unitName(units, row.product?.qu_id_stock),
          due_date: normalizeDueDate(row.best_before_date),
        }))
        .sort(byDueDate)
        .slice(0, input.limit);

    const data = {
      due: shape(volatile.due_products, "due_products"),
      overdue: shape(volatile.overdue_products, "overdue_products"),
      expired: shape(volatile.expired_products, "expired_products"),
    };
    const text =
      `${plural(data.due.length, "product")} due within ${plural(input.days, "day")}, ` +
      `${data.overdue.length} overdue, ${data.expired.length} expired.`;

    return { data, text };
  },
};
