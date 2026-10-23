import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { UNDO_NOTE } from "../victual/transaction.js";
import { book, REPLAY_NOTE, requestIdSchema } from "../victual/booking.js";

// ADR-0052 decision 1. POST /api/stock/products/{id}/open: marks an amount as opened, which
// moves its due date to the product's after-opening days.
const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find_product)"),
  amount: z.number().positive().default(1).describe("How much was opened, in the product's stock unit (default 1)"),
  request_id: requestIdSchema,
});

const outputSchema = z.object({
  transaction_id: z.string(),
  product_id: z.number(),
  amount: z.number(),
  request_id: z.string().optional(),
  replayed: z.boolean(),
});

export const openProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "open_product",
  title: "Open product",
  description: "Mark an amount of a product as opened (a jar, a carton), which shortens how long it keeps.",
  permission: "STOCK_OPEN",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const booked = await book(ctx, `/api/stock/products/${input.product_id}/open`, { amount: input.amount }, input.request_id);
    return {
      data: { ...booked, product_id: input.product_id, amount: input.amount },
      text: `Opened ${input.amount} of product ${input.product_id} (transaction ${booked.transaction_id}). ${booked.replayed ? `${REPLAY_NOTE} ` : ""}${UNDO_NOTE}`,
    };
  },
};
