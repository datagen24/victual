import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { StockLogRow } from "../victual/types.js";
import { transactionId, UNDO_NOTE } from "../victual/transaction.js";

// §6. POST /api/stock/products/{id}/consume. `spoiled` is sent as a real boolean: the
// route refuses the string "false" rather than reading it as true.
const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find it with find_product)"),
  amount: z.number().positive().describe("How much was used, in the product's stock unit"),
  spoiled: z.boolean().default(false).describe("True if the amount was thrown away as spoiled"),
});

const outputSchema = z.object({
  transaction_id: z.string(),
  product_id: z.number(),
  amount: z.number(),
  spoiled: z.boolean(),
});

export const consumeProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "consume_product",
  title: "Consume product",
  description:
    "Book an amount of a product as used up (or spoiled), taking it out of stock. Fails if there is not enough in stock.",
  permission: "STOCK_CONSUME",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  destructive: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const rows = await ctx.client.post<StockLogRow[]>(
      `/api/stock/products/${input.product_id}/consume`,
      ctx.credential,
      { amount: input.amount, spoiled: input.spoiled },
    );
    const id = transactionId(rows);
    return {
      data: { transaction_id: id, product_id: input.product_id, amount: input.amount, spoiled: input.spoiled },
      text: `Consumed ${input.amount} of product ${input.product_id}${input.spoiled ? " as spoiled" : ""} (transaction ${id}). ${UNDO_NOTE}`,
    };
  },
};
