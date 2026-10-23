import { z } from "zod";
import type { ToolContext, ToolDefinition } from "./types.js";
import type { ProductRow } from "../victual/types.js";
import { VictualApiError } from "../victual/client.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";
import { bool, num } from "../victual/shape.js";
import { barcodeSchema } from "./add-barcode.js";
import { serverSideTerm } from "./find-product.js";

// ADR-0052 decisions 1 and 3. POST /api/objects/products, then, with a barcode,
// POST /api/objects/product_barcodes. The one unit becomes all four of the product's units:
// qu_id_stock and qu_id_purchase are required, and consume and price default to the stock
// unit, as the product form does. Nothing else creates a product (spec §6).
//
// products.name is UNIQUE, and Victual reports the violation only as "The database rejected
// this request". So the name is checked first, and again after a refused create in case
// another client took it in between: a model told "name taken, product N" uses product N.
const inputSchema = z.object({
  name: z.string().trim().min(1).max(200).describe("The product's name, unique among products"),
  location_id: z.number().int().positive().describe("Where it is usually stored (list_locations)"),
  unit_id: z.number().int().positive().describe("The unit it is counted in, e.g. Can or Piece (list_quantity_units)"),
  product_group_id: z.number().int().positive().optional().describe("Its product group (list_product_groups)"),
  min_stock_amount: z.number().nonnegative().optional().describe("Below this amount it counts as missing (default 0)"),
  default_best_before_days: z
    .number()
    .int()
    .min(-1)
    .optional()
    .describe(
      "Shelf life: days from purchase or count to its due date; -1 means it never expires. Without it the default is 0, so every count is due the day it is recorded",
    ),
  barcode: barcodeSchema.optional().describe("A barcode to attach to the new product"),
});

const outputSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  barcode_added: z.boolean().nullable(),
});

export const createProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "create_product",
  title: "Create product",
  description:
    "Create a new product in the catalog. Check find_product (or lookup_barcode) first, because names are unique; a taken name is refused with the product that holds it. Ask for its shelf life (default_best_before_days) unless it is obvious: without one, every count of it is due the day it is recorded. This creates the product only: record how much there is with inventory_product.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    await refuseTakenName(ctx, input.name);
    const id = await createObject(ctx, "products", {
      name: input.name,
      location_id: input.location_id,
      qu_id_stock: input.unit_id,
      qu_id_purchase: input.unit_id,
      qu_id_consume: input.unit_id,
      qu_id_price: input.unit_id,
      ...(input.product_group_id !== undefined ? { product_group_id: input.product_group_id } : {}),
      ...(input.min_stock_amount !== undefined ? { min_stock_amount: input.min_stock_amount } : {}),
      ...(input.default_best_before_days !== undefined ? { default_best_before_days: input.default_best_before_days } : {}),
    }).catch(async (error: unknown) => {
      // A refused create is most often the name, taken since the check above.
      if (error instanceof VictualApiError && error.category === "invalid_request") await refuseTakenName(ctx, input.name);
      throw error;
    });

    let barcodeNote = "";
    let barcodeAdded: boolean | null = null;
    if (input.barcode !== undefined) {
      // The product exists whatever happens here, so a failed barcode is reported, not thrown:
      // an error would read as "nothing was created" and invite a second create_product.
      try {
        await createObject(ctx, "product_barcodes", { product_id: id, barcode: input.barcode });
        barcodeAdded = true;
        barcodeNote = ` Barcode ${input.barcode} attached.`;
      } catch (error) {
        barcodeAdded = false;
        const reason = error instanceof VictualApiError ? error.message : String(error);
        barcodeNote = ` The barcode was not attached (${reason}); check with lookup_barcode, then retry with add_barcode.`;
      }
    }
    return {
      data: { product_id: id, name: input.name, barcode_added: barcodeAdded },
      text: `Created product "${input.name}" (product ${id}).${barcodeNote} ${DELETE_IN_UI_NOTE}`,
    };
  },
};

/**
 * The product already named `name`, active or not (an inactive product still holds the name
 * under the UNIQUE constraint), or null. The match is exact and case-sensitive, as the
 * constraint is. The server-side filter is a substring of the name, because Victual's
 * query[] pattern cuts a value at characters such as "&" (see serverSideTerm).
 */
export async function productNamed(ctx: ToolContext, name: string): Promise<ProductRow | null> {
  const params = new URLSearchParams();
  const term = serverSideTerm(name);
  if (term !== "") params.append("query[]", `name~${term}`);
  const products = await ctx.client.get<ProductRow[]>("/api/objects/products", ctx.credential, params);
  return products.find((product) => product.name === name) ?? null;
}

/**
 * Throws `invalid_request` naming the product that holds `name`, and returns when the name
 * is free. A key that may create products but not read them (MASTER_DATA_ADD without
 * STOCK_VIEW) skips the check, and Victual's own refusal stands.
 */
async function refuseTakenName(ctx: ToolContext, name: string): Promise<void> {
  let holder: ProductRow | null;
  try {
    holder = await productNamed(ctx, name);
  } catch (error) {
    if (error instanceof VictualApiError && error.category === "forbidden") return;
    throw error;
  }
  if (holder === null) return;
  const id = num(holder.id, "product id");
  const inactive = holder.active !== undefined && !bool(holder.active) ? ", which is inactive (reactivate it in Victual's UI)" : "";
  throw new VictualApiError(
    "invalid_request",
    `the name "${name}" is taken by product ${id}${inactive}; use product_id ${id}, or create it under a different name`,
  );
}
