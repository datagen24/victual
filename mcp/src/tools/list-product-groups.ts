import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { ProductGroupRow } from "../victual/types.js";
import { num, plural } from "../victual/shape.js";

// ADR-0052 decision 1. GET /api/objects/product_groups, active ones by name.
const inputSchema = z.object({});

const outputSchema = z.object({
  rows: z.array(z.object({ product_group_id: z.number(), name: z.string() })),
});

export const listProductGroups: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "list_product_groups",
  title: "List product groups",
  description: "List the product groups (dairy, canned goods...) with their ids.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (_input, ctx) => {
    const params = new URLSearchParams([["query[]", "active=1"], ["order", "name:asc"]]);
    const groups = await ctx.client.get<ProductGroupRow[]>("/api/objects/product_groups", ctx.credential, params);
    const rows = groups.map((group) => ({ product_group_id: num(group.id, "product group id"), name: group.name }));
    return { data: { rows }, text: `${plural(rows.length, "product group")}.` };
  },
};
