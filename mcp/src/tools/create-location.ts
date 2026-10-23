import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import { createObject, DELETE_IN_UI_NOTE } from "../victual/objects.js";

// ADR-0052 decision 1. POST /api/objects/locations. parent_location_id (migration 0273)
// lets a shelf sit inside a pantry.
const inputSchema = z.object({
  name: z.string().trim().min(1).max(200).describe("The location's name, unique among locations"),
  parent_location_id: z.number().int().positive().optional().describe("Id of the location this one is inside (list_locations)"),
  is_freezer: z.boolean().default(false).describe("True for a freezer: products stored here get their frozen due dates"),
  description: z.string().max(500).optional(),
});

const outputSchema = z.object({ location_id: z.number(), name: z.string() });

export const createLocation: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "create_location",
  title: "Create location",
  description:
    "Create a storage location (a pantry, a fridge, a shelf inside one). Check list_locations first: names are unique.",
  permission: "MASTER_DATA_ADD",
  write: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const id = await createObject(ctx, "locations", {
      name: input.name,
      is_freezer: input.is_freezer ? 1 : 0,
      ...(input.parent_location_id !== undefined ? { parent_location_id: input.parent_location_id } : {}),
      ...(input.description !== undefined ? { description: input.description } : {}),
    });
    return {
      data: { location_id: id, name: input.name },
      text: `Created location "${input.name}" (location ${id}). ${DELETE_IN_UI_NOTE}`,
    };
  },
};
