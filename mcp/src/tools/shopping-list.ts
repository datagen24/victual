import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.5. Backed by GET /api/objects/shopping_list (+ products, quantity_units for
// names). shopping_list_id is already plural-ready for plan 05; when it lands, a `list`
// descriptor section is added to the output (additive).
const inputSchema = z.object({
  shopping_list_id: z.number().int().default(1),
  limit: z.number().int().min(1).max(200).default(100),
});

const rowSchema = z.object({
  item_id: z.number(),
  product_id: z.number().nullable(),
  name: z.string(),
  amount: z.number(),
  unit: z.string(),
  done: z.boolean(),
  note: z.string().nullable(),
});

const outputSchema = z.object({ rows: z.array(rowSchema) });

export const shoppingList: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "shopping_list",
  permission: "SHOPPINGLIST",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("shopping_list: not implemented — see docs/mcp-interface-spec.md §5.5");
  },
};
