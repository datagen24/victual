import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { UNDO_NOTE } from "../victual/transaction.js";
import { book, REPLAY_NOTE, requestIdSchema } from "../victual/booking.js";

// ADR-0052 decision 1. POST /api/stock/products/{id}/transfer: moves existing stock between
// locations. update_product's location only changes where new stock goes.
const inputSchema = z
  .object({
    product_id: z.number().int().positive().describe("Id of an existing product (find_product)"),
    amount: z.number().positive().describe("How much to move, in the product's stock unit"),
    location_id_from: z.number().int().positive().describe("Where it is now (list_locations)"),
    location_id_to: z.number().int().positive().describe("Where it goes (list_locations)"),
    request_id: requestIdSchema,
  })
  .refine((input) => input.location_id_from !== input.location_id_to, { message: "the two locations must differ" });

const outputSchema = z.object({
  transaction_id: z.string(),
  product_id: z.number(),
  amount: z.number(),
  location_id_from: z.number(),
  location_id_to: z.number(),
  request_id: z.string().optional(),
  replayed: z.boolean(),
});

export const transferProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "transfer_product",
  title: "Move product",
  description: "Move an amount of a product that is in stock from one location to another.",
  permission: "STOCK_TRANSFER",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const booked = await book(
      ctx,
      `/api/stock/products/${input.product_id}/transfer`,
      { amount: input.amount, location_id_from: input.location_id_from, location_id_to: input.location_id_to },
      input.request_id,
    );
    return {
      data: {
        ...booked,
        product_id: input.product_id,
        amount: input.amount,
        location_id_from: input.location_id_from,
        location_id_to: input.location_id_to,
      },
      text: `Moved ${input.amount} of product ${input.product_id} from location ${input.location_id_from} to ${input.location_id_to} (transaction ${booked.transaction_id}). ${booked.replayed ? `${REPLAY_NOTE} ` : ""}${UNDO_NOTE}`,
    };
  },
};
