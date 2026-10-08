import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §6. POST /api/stock/shoppinglist/add-product. The REST fields are product_amount and
// list_id; the tool takes the spec's names. The route answers 204, and refuses an
// unknown product, so no product is ever created here.
const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find it with find_product)"),
  amount: z.number().positive().default(1).describe("How much to add, in the product's purchase unit (default 1)"),
  shopping_list_id: z.number().int().positive().optional().describe("Which shopping list (default: the first)"),
  note: z.string().max(500).optional().describe("A note shown with the item"),
});

const outputSchema = z.object({
  product_id: z.number(),
  amount: z.number(),
  shopping_list_id: z.number(),
});

export const addToShoppingList: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "add_to_shopping_list",
  title: "Add to shopping list",
  description:
    "Add an existing product to a shopping list. If it is already on the list, the amount is added to the existing item. Does not create products.",
  permission: "SHOPPINGLIST_ITEMS_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const listId = input.shopping_list_id ?? 1;
    await ctx.client.post("/api/stock/shoppinglist/add-product", ctx.credential, {
      product_id: input.product_id,
      product_amount: input.amount,
      list_id: listId,
      ...(input.note !== undefined ? { note: input.note } : {}),
    });
    return {
      data: { product_id: input.product_id, amount: input.amount, shopping_list_id: listId },
      text: `Added ${input.amount} of product ${input.product_id} to shopping list ${listId}.`,
    };
  },
};
