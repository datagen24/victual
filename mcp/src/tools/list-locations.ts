import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { LocationRow } from "../victual/types.js";
import { bool, num, numOrNull, plural } from "../victual/shape.js";

// ADR-0052 decision 1. GET /api/objects/locations, active ones by name. The ids are what
// create_product, create_location and transfer_product take.
const inputSchema = z.object({});

const outputSchema = z.object({
  rows: z.array(
    z.object({
      location_id: z.number(),
      name: z.string(),
      parent_location_id: z.number().nullable(),
      is_freezer: z.boolean(),
    }),
  ),
});

export const listLocations: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "list_locations",
  title: "List locations",
  description:
    "List the storage locations (pantry, fridge, a shelf inside one) with their ids. A location can sit inside another (parent_location_id).",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (_input, ctx) => {
    const params = new URLSearchParams([["query[]", "active=1"], ["order", "name:asc"]]);
    const locations = await ctx.client.get<LocationRow[]>("/api/objects/locations", ctx.credential, params);
    const rows = locations.map((location) => ({
      location_id: num(location.id, "location id"),
      name: location.name,
      parent_location_id: numOrNull(location.parent_location_id, "parent_location_id"),
      is_freezer: bool(location.is_freezer),
    }));
    return { data: { rows }, text: `${plural(rows.length, "location")}.` };
  },
};
