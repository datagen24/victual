import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// ADR-0052 decision 1. POST /api/stock/transactions/{id}/undo answers 204. Undo is not one of
// ADR-0046's five bookings, so it carries no key; undoing twice is refused by Victual, and an
// undone transaction stays in the journal, marked undone (decision 2: undo is not deletion).
const inputSchema = z.object({
  transaction_id: z
    .string()
    .trim()
    .regex(/^[A-Za-z0-9._:-]{1,128}$/, "a transaction_id as a booking tool returned it")
    .describe("The transaction_id a booking tool returned, or recent_transactions lists"),
});

const outputSchema = z.object({ transaction_id: z.string(), undone: z.boolean() });

export const undoTransaction: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "undo_transaction",
  title: "Undo stock transaction",
  description:
    "Reverse a stock booking (purchase, consume, inventory, open, move or due-date change) by its transaction_id: the one the booking tool returned, or one recent_transactions lists. Use it to correct a mistake.",
  permission: "STOCK_EDIT",
  write: true,
  destructive: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    await ctx.client.post(`/api/stock/transactions/${encodeURIComponent(input.transaction_id)}/undo`, ctx.credential, {});
    return {
      data: { transaction_id: input.transaction_id, undone: true },
      text: `Undid transaction ${input.transaction_id}; it stays in the stock journal, marked undone.`,
    };
  },
};
