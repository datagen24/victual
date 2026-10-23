import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// ADR-0052 decision 1 and open question 3: checking an item off is an edit
// (PUT /api/objects/shopping_list/{id}, `done`); removing it stays in the UI.
const inputSchema = z.object({
  item_id: z.number().int().positive().describe("The item_id shopping_list returned"),
  done: z.boolean().default(true).describe("False puts a checked-off item back on the list"),
});

const outputSchema = z.object({ item_id: z.number(), done: z.boolean() });

export const checkOffShoppingListItem: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "check_off_shopping_list_item",
  title: "Check off shopping-list item",
  description: "Mark a shopping-list item as done (or not done). It does not remove the item or book a purchase.",
  permission: "SHOPPINGLIST_ITEMS_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    await ctx.client.put(`/api/objects/shopping_list/${input.item_id}`, ctx.credential, { done: input.done ? 1 : 0 });
    return {
      data: { item_id: input.item_id, done: input.done },
      text: input.done ? `Checked off shopping-list item ${input.item_id}.` : `Put shopping-list item ${input.item_id} back on the list.`,
    };
  },
};
