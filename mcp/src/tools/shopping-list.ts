import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { ProductRow, ShoppingListRow } from "../victual/types.js";
import { bool, fetchUnitNames, num, numOrNull, plural, textOrNull, unitName } from "../victual/shape.js";

// §5.5. Backed by GET /api/objects/shopping_list (+ products, quantity_units for
// names). shopping_list_id is already plural-ready for plan 05; when it lands, a `list`
// descriptor section is added to the output (additive).
const inputSchema = z.object({
  shopping_list_id: z.number().int().default(1).describe("Which shopping list (default 1)"),
  limit: z.number().int().min(1).max(200).default(100).describe("Maximum rows (default 100, max 200)"),
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

const outputSchema = z.object({ total: z.number(), rows: z.array(rowSchema) });

export const shoppingList: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "shopping_list",
  title: "Shopping list",
  description: "The items on a shopping list, not-yet-done first, with amounts and units.",
  permission: "SHOPPINGLIST_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const params = new URLSearchParams();
    params.append("query[]", `shopping_list_id=${input.shopping_list_id}`);

    const [items, products, units] = await Promise.all([
      ctx.client.get<ShoppingListRow[]>("/api/objects/shopping_list", ctx.credential, params),
      ctx.client.get<ProductRow[]>("/api/objects/products", ctx.credential),
      fetchUnitNames(ctx),
    ]);

    const productsById = new Map(products.map((product) => [num(product.id), product]));
    const all = items
      .map((item) => {
        const productId = numOrNull(item.product_id);
        const product = productId === null ? undefined : productsById.get(productId);
        const note = textOrNull(item.note);
        return {
          item_id: num(item.id),
          product_id: productId,
          // A free-text item (no product) is named by its note — that is what the UI shows.
          name: product?.name ?? note ?? "",
          amount: num(item.amount),
          unit: unitName(units, item.qu_id ?? product?.qu_id_stock),
          done: bool(item.done),
          note,
        };
      })
      .sort((a, b) => Number(a.done) - Number(b.done) || a.name.localeCompare(b.name));

    const rows = all.slice(0, input.limit);
    const open = all.filter((row) => !row.done).length;
    const text =
      `${plural(all.length, "item")} on shopping list ${input.shopping_list_id}, ${open} not yet done` +
      (rows.length < all.length ? `; showing ${rows.length}.` : ".");

    return { data: { total: all.length, rows }, text };
  },
};
