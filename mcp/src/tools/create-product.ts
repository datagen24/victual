import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { VictualApiError } from "../victual/client.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";
import { barcodeSchema } from "./add-barcode.js";

// ADR-0052 decisions 1 and 3. POST /api/objects/products, then, with a barcode,
// POST /api/objects/product_barcodes. The one unit becomes all four of the product's units:
// qu_id_stock and qu_id_purchase are required, and consume and price default to the stock
// unit, as the product form does. Nothing else creates a product (spec §6).
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
    .describe("Days from purchase to its best-before date; -1 means it never expires (default 0)"),
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
    "Create a new product in the catalog. Check find_product (or lookup_barcode) first, because names are unique. This creates the product only: record how much there is with inventory_product.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
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
