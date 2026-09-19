import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// §5.6. Backed by GET /api/recipes/fulfillment + GET /api/objects/recipes for names.
// can_cook = need_fulfilled; missing_little = need_fulfilled_with_shopping_list minus
// the first set. Only type = normal recipes — never meal-plan shadow recipes.
const inputSchema = z.object({
  limit: z.number().int().min(1).max(50).default(25),
});

const rowSchema = z.object({
  recipe_id: z.number(),
  name: z.string(),
  missing_products_count: z.number(),
});

const outputSchema = z.object({
  can_cook: z.array(rowSchema),
  missing_little: z.array(rowSchema),
});

export const recipesICanCook: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "recipes_i_can_cook",
  permission: "RECIPES",
  inputSchema,
  outputSchema,
  handler: async () => {
    throw new Error("recipes_i_can_cook: not implemented — see docs/mcp-interface-spec.md §5.6");
  },
};
