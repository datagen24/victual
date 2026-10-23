import { z } from "zod";
import type { ToolContext, ToolDefinition } from "./types.js";
import type { ExternalBarcodeLookupRow, ProductDetailsRow } from "../victual/types.js";
import { VictualApiError } from "../victual/client.js";
import { num, textOrNull } from "../victual/shape.js";

// ADR-0052 decision 1 and open question 2. GET /api/stock/products/by-barcode/{barcode}; on a
// miss, GET /api/stock/barcodes/external-lookup/{barcode} without `add`, so nothing is ever
// created here. Only named fields leave: the product details answer carries prices, and no
// tool returns a price field (README, issue #86).
const inputSchema = z.object({
  barcode: z.string().trim().min(1).max(128).describe("The barcode as scanned or read off the package"),
});

const outputSchema = z.object({
  found: z.enum(["victual", "external", "none"]),
  product: z
    .object({
      product_id: z.number(),
      name: z.string(),
      unit: z.string().nullable(),
      location: z.string().nullable(),
      in_stock_amount: z.number(),
    })
    .nullable(),
  suggestion: z.object({ name: z.string() }).nullable(),
});

/** by-barcode's documented 400 is "Unknown barcode", so a 400 there is a miss, not a fault. */
async function known(barcode: string, ctx: ToolContext) {
  try {
    return await ctx.client.get<ProductDetailsRow>(`/api/stock/products/by-barcode/${encodeURIComponent(barcode)}`, ctx.credential);
  } catch (error) {
    if (error instanceof VictualApiError && error.victualStatus === 400) return null;
    throw error;
  }
}

/** The configured lookup plugin's guess; a plugin error (400) is treated as no guess. */
async function external(barcode: string, ctx: ToolContext) {
  try {
    return await ctx.client.get<ExternalBarcodeLookupRow | null>(
      `/api/stock/barcodes/external-lookup/${encodeURIComponent(barcode)}`,
      ctx.credential,
    );
  } catch (error) {
    if (error instanceof VictualApiError && error.victualStatus === 400) return null;
    throw error;
  }
}

export const lookupBarcode: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "lookup_barcode",
  title: "Look up barcode",
  description:
    "Find which product a barcode belongs to. If Victual does not know it, ask the configured external lookup for a suggested name, which create_product can then use. Creates nothing.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const details = await known(input.barcode, ctx);
    if (details) {
      const product = {
        product_id: num(details.product?.id, "product id"),
        name: details.product?.name ?? "",
        unit: textOrNull(details.quantity_unit_stock?.name),
        location: textOrNull(details.location?.name),
        in_stock_amount: num(details.stock_amount ?? 0, "stock_amount"),
      };
      return {
        data: { found: "victual" as const, product, suggestion: null },
        text: `Barcode ${input.barcode} is ${product.name} (product ${product.product_id}), ${product.in_stock_amount} in stock.`,
      };
    }
    const guess = await external(input.barcode, ctx);
    const name = textOrNull(guess?.name);
    if (name) {
      return {
        data: { found: "external" as const, product: null, suggestion: { name } },
        text: `Barcode ${input.barcode} is not in Victual; the external lookup suggests "${name}".`,
      };
    }
    return {
      data: { found: "none" as const, product: null, suggestion: null },
      text: `Barcode ${input.barcode} is not in Victual, and the external lookup found nothing.`,
    };
  },
};
