import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";

// ADR-0052 decision 1. POST /api/objects/product_barcodes, which MASTER_DATA_ADD gates
// (GenericEntityApiController::AddObject). A product can have several barcodes.
export const barcodeSchema = z.string().trim().min(1).max(128);

const inputSchema = z.object({
  product_id: z.number().int().positive().describe("Id of an existing product (find_product)"),
  barcode: barcodeSchema.describe("The barcode as scanned or read off the package"),
  note: z.string().max(500).optional(),
});

const outputSchema = z.object({ barcode_id: z.number(), product_id: z.number(), barcode: z.string() });

export const addBarcode: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "add_barcode",
  title: "Add barcode",
  description:
    "Attach a barcode to an existing product, so scanning it finds the product. Check lookup_barcode first: it may already belong to another product.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const id = await createObject(ctx, "product_barcodes", {
      product_id: input.product_id,
      barcode: input.barcode,
      ...(input.note !== undefined ? { note: input.note } : {}),
    });
    return {
      data: { barcode_id: id, product_id: input.product_id, barcode: input.barcode },
      text: `Added barcode ${input.barcode} to product ${input.product_id}. ${DELETE_IN_UI_NOTE}`,
    };
  },
};
