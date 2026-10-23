import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";

// ADR-0052 decision 1. POST /api/objects/quantity_units.
const inputSchema = z.object({
  name: z.string().trim().min(1).max(100).describe('The singular name ("Can"), unique among units'),
  name_plural: z.string().trim().min(1).max(100).optional().describe('The plural ("Cans")'),
});

const outputSchema = z.object({ unit_id: z.number(), name: z.string() });

export const createQuantityUnit: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "create_quantity_unit",
  title: "Create quantity unit",
  description: "Create a quantity unit (can, jar, bag...). Check list_quantity_units first: names are unique.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const id = await createObject(ctx, "quantity_units", {
      name: input.name,
      ...(input.name_plural !== undefined ? { name_plural: input.name_plural } : {}),
    });
    return { data: { unit_id: id, name: input.name }, text: `Created quantity unit "${input.name}" (unit ${id}). ${DELETE_IN_UI_NOTE}` };
  },
};
