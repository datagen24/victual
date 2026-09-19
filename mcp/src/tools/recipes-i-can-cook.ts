import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { RecipeFulfillmentRow, RecipeRow } from "../victual/types.js";
import { bool, num, plural } from "../victual/shape.js";

// §5.6. Backed by GET /api/recipes/fulfillment + GET /api/objects/recipes for names.
// can_cook = need_fulfilled; missing_little = need_fulfilled_with_shopping_list minus
// the first set. Only type = normal recipes — never meal-plan shadow recipes, and
// selected by the `type` column, never by the shadow recipes' name convention.
const inputSchema = z.object({
  limit: z.number().int().min(1).max(50).default(25).describe("Maximum rows per section (default 25, max 50)"),
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
  title: "Recipes I can cook",
  description:
    "Recipes whose ingredients are all in stock, and recipes that would be once the shopping list is bought.",
  permission: "RECIPES_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const params = new URLSearchParams();
    params.append("query[]", "type=normal");

    const [fulfillment, recipes] = await Promise.all([
      ctx.client.get<RecipeFulfillmentRow[]>("/api/recipes/fulfillment", ctx.credential),
      ctx.client.get<RecipeRow[]>("/api/objects/recipes", ctx.credential, params),
    ]);

    const names = new Map(
      recipes.filter((recipe) => recipe.type === "normal").map((recipe) => [num(recipe.id), recipe.name]),
    );

    const canCook: z.infer<typeof rowSchema>[] = [];
    const missingLittle: z.infer<typeof rowSchema>[] = [];
    for (const row of fulfillment) {
      const name = names.get(num(row.recipe_id));
      if (name === undefined) continue;
      const shaped = { recipe_id: num(row.recipe_id), name, missing_products_count: num(row.missing_products_count) };
      if (bool(row.need_fulfilled)) canCook.push(shaped);
      else if (bool(row.need_fulfilled_with_shopping_list)) missingLittle.push(shaped);
    }

    const byName = (a: { name: string }, b: { name: string }) => a.name.localeCompare(b.name);
    const data = {
      can_cook: canCook.sort(byName).slice(0, input.limit),
      missing_little: missingLittle.sort(byName).slice(0, input.limit),
    };
    const text =
      `${plural(canCook.length, "recipe")} can be cooked from stock; ` +
      `${missingLittle.length} more once the shopping list is bought.`;

    return { data, text };
  },
};
