import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { StockLogRow } from "../victual/types.js";
import { transactionId, UNDO_NOTE } from "../victual/transaction.js";

// §6. POST /api/stock/products/{id}/add. The tool's due_date is the route's
// best_before_date; shopping_location_id keeps its real name (§6's note).
const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "use YYYY-MM-DD");

const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find it with find_product)"),
  amount: z.number().positive().describe("How much was bought, in the product's stock unit"),
  price: z.number().nonnegative().optional().describe("Price per stock unit"),
  due_date: isoDate.optional().describe("Best-before date, YYYY-MM-DD (default: derived from the product)"),
  shopping_location_id: z.number().int().positive().optional().describe("Id of the store it was bought at"),
});

const outputSchema = z.object({
  transaction_id: z.string(),
  product_id: z.number(),
  amount: z.number(),
});

export const purchaseProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "purchase_product",
  title: "Purchase product",
  description:
    "Book a purchase of an existing product, adding it to stock. Does not create products.",
  permission: "STOCK_PURCHASE",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const rows = await ctx.client.post<StockLogRow[]>(`/api/stock/products/${input.product_id}/add`, ctx.credential, {
      amount: input.amount,
      ...(input.price !== undefined ? { price: input.price } : {}),
      ...(input.due_date !== undefined ? { best_before_date: input.due_date } : {}),
      ...(input.shopping_location_id !== undefined ? { shopping_location_id: input.shopping_location_id } : {}),
    });
    const id = transactionId(rows);
    return {
      data: { transaction_id: id, product_id: input.product_id, amount: input.amount },
      text: `Purchased ${input.amount} of product ${input.product_id} (transaction ${id}). ${UNDO_NOTE}`,
    };
  },
};
