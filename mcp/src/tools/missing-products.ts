import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { VolatileStock } from "../victual/types.js";
import { bool, num, plural } from "../victual/shape.js";

// §5.3. Backed by GET /api/stock/volatile -> missing_products. Plan 03 deliberately
// keeps group shortfalls out of this view; when it lands, group shortfalls become a new
// section here (additive), not a change to this row shape.
const inputSchema = z.object({
  limit: z.number().int().min(1).max(200).default(50).describe("Maximum rows (default 50, max 200)"),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  amount_missing: z.number(),
  is_partly_in_stock: z.boolean(),
});

const outputSchema = z.object({ total: z.number(), rows: z.array(rowSchema) });

export const missingProducts: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "missing_products",
  title: "Missing products",
  description: "Products below their minimum stock amount, with how much is missing.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const volatile = await ctx.client.get<VolatileStock>("/api/stock/volatile", ctx.credential);
    const all = (volatile.missing_products ?? [])
      .map((row) => ({
        product_id: num(row.id),
        name: row.name,
        amount_missing: num(row.amount_missing),
        is_partly_in_stock: bool(row.is_partly_in_stock),
      }))
      .sort((a, b) => a.name.localeCompare(b.name));

    const rows = all.slice(0, input.limit);
    const text =
      `${plural(all.length, "product")} below minimum stock` +
      (rows.length < all.length ? `; showing ${rows.length}.` : ".");

    return { data: { total: all.length, rows }, text };
  },
};
