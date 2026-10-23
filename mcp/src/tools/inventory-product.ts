import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { ProductDetailsRow } from "../victual/types.js";
import { UNDO_NOTE } from "../victual/transaction.js";
import { book, REPLAY_NOTE, requestIdSchema } from "../victual/booking.js";
import { num } from "../victual/shape.js";

// ADR-0052 decision 1, the pantry walk's main tool. POST /api/stock/products/{id}/inventory
// sets an absolute amount. Victual refuses a new amount equal to the current one, so the
// current amount is read first (GET /api/stock/products/{id}) and an unchanged amount books
// nothing: saying "there are three" twice does no harm.
const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "use YYYY-MM-DD");

const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find_product, or create_product first)"),
  new_amount: z.number().nonnegative().describe("How much there is now, in the product's stock unit"),
  best_before_date: isoDate.optional().describe("Best-before date of any amount added, YYYY-MM-DD (default: from the product)"),
  location_id: z.number().int().positive().optional().describe("Where any amount added is stored (default: the product's location)"),
  note: z.string().max(500).optional(),
  request_id: requestIdSchema,
});

const outputSchema = z.object({
  product_id: z.number(),
  previous_amount: z.number(),
  new_amount: z.number(),
  changed: z.boolean(),
  transaction_id: z.string().nullable(),
  request_id: z.string().optional(),
  replayed: z.boolean(),
});

export const inventoryProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "inventory_product",
  title: "Set stock amount",
  description:
    "Record how much of a product there is now (an inventory count): sets the amount, it does not add to it. Use it to count a pantry. An unchanged amount books nothing.",
  permission: "STOCK_INVENTORY",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  // A lower count takes stock out.
  destructive: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const details = await ctx.client.get<ProductDetailsRow>(`/api/stock/products/${input.product_id}`, ctx.credential);
    const previous = num(details?.stock_amount ?? 0, "stock_amount");
    if (previous === input.new_amount) {
      return {
        data: { product_id: input.product_id, previous_amount: previous, new_amount: input.new_amount, changed: false, transaction_id: null, replayed: false },
        text: `Product ${input.product_id} already has ${previous} in stock; nothing was booked.`,
      };
    }
    const booked = await book(
      ctx,
      `/api/stock/products/${input.product_id}/inventory`,
      {
        new_amount: input.new_amount,
        ...(input.best_before_date !== undefined ? { best_before_date: input.best_before_date } : {}),
        ...(input.location_id !== undefined ? { location_id: input.location_id } : {}),
        ...(input.note !== undefined ? { note: input.note } : {}),
      },
      input.request_id,
    );
    return {
      data: { ...booked, product_id: input.product_id, previous_amount: previous, new_amount: input.new_amount, changed: true },
      text: `Set product ${input.product_id} from ${previous} to ${input.new_amount} (transaction ${booked.transaction_id}). ${booked.replayed ? `${REPLAY_NOTE} ` : ""}${UNDO_NOTE}`,
    };
  },
};
