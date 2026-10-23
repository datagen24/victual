import { z } from "zod";
import type { ToolDefinition } from "./types.js";

// ADR-0052 decision 3. PUT /api/objects/products/{id}, which updates only the fields sent.
// The stock unit is deliberately absent: changing it under existing stock changes what
// every booked amount means. Moving stock that exists is transfer_product's job; the
// location here is only where new stock goes by default.
const inputSchema = z
  .object({
    product_id: z.number().int().positive().describe("Id of an existing product (find_product)"),
    name: z.string().trim().min(1).max(200).optional(),
    location_id: z.number().int().positive().optional().describe("Its default location (list_locations)"),
    product_group_id: z.number().int().positive().nullable().optional().describe("Its product group; null removes it from its group"),
    min_stock_amount: z.number().nonnegative().optional(),
    default_best_before_days: z.number().int().min(-1).optional().describe("-1 means it never expires"),
  })
  .refine((input) => Object.keys(input).some((key) => key !== "product_id"), { message: "name at least one field to change" });

const outputSchema = z.object({ product_id: z.number(), changed: z.array(z.string()) });

export const updateProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "update_product",
  title: "Update product",
  description:
    "Change an existing product's name, default location, product group, minimum stock amount or default best-before days. It cannot change the product's unit, and it does not move stock (use transfer_product).",
  permission: "MASTER_DATA_EDIT",
  write: true,
  // It overwrites values that were there before.
  destructive: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const { product_id: productId, ...fields } = input;
    const body = Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== undefined));
    await ctx.client.put(`/api/objects/products/${productId}`, ctx.credential, body);
    const changed = Object.keys(body);
    return { data: { product_id: productId, changed }, text: `Updated product ${productId}: ${changed.join(", ")}.` };
  },
};
