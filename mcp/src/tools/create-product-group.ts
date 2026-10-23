import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";

// ADR-0052 decision 1. POST /api/objects/product_groups.
const inputSchema = z.object({
  name: z.string().trim().min(1).max(200).describe("The group's name, unique among product groups"),
});

const outputSchema = z.object({ product_group_id: z.number(), name: z.string() });

export const createProductGroup: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "create_product_group",
  title: "Create product group",
  description: "Create a product group (dairy, canned goods...). Check list_product_groups first: names are unique.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const id = await createObject(ctx, "product_groups", { name: input.name });
    return {
      data: { product_group_id: id, name: input.name },
      text: `Created product group "${input.name}" (group ${id}). ${DELETE_IN_UI_NOTE}`,
    };
  },
};
