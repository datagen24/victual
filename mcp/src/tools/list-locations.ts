import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { LocationRow } from "../victual/types.js";
import { bool, num, numOrNull, plural } from "../victual/shape.js";
import { serverSideTerm } from "./find-product.js";

// ADR-0052 decision 1. GET /api/objects/locations, active ones by name. The ids are what
// create_product, create_location and transfer_product take. `query` and `limit` follow
// find_product (§5.4): an instance can hold hundreds of locations, and §5 caps every list.
const inputSchema = z.object({
  query: z.string().min(1).optional().describe("Part of the location name, case-insensitive"),
  limit: z.number().int().min(1).max(200).default(50).describe("Maximum rows (default 50, max 200)"),
});

const outputSchema = z.object({
  rows: z.array(
    z.object({
      location_id: z.number(),
      name: z.string(),
      parent_location_id: z.number().nullable(),
      is_freezer: z.boolean(),
    }),
  ),
  total: z.number(),
});

export const listLocations: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "list_locations",
  title: "List locations",
  description:
    "List the storage locations (pantry, fridge, a shelf inside one) with their ids. A location can sit inside another (parent_location_id). Pass query to find one by name; a filtered list can leave out a row's parent.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const params = new URLSearchParams();
    // The same split as find_product: the longest run Victual's query[] pattern admits goes
    // to the server, and the whole query is matched here.
    const term = input.query === undefined ? "" : serverSideTerm(input.query);
    if (term !== "") params.append("query[]", `name~${term}`);
    params.append("query[]", "active=1");
    params.append("order", "name:asc");
    const locations = await ctx.client.get<LocationRow[]>("/api/objects/locations", ctx.credential, params);

    const needle = input.query?.toLocaleLowerCase();
    const matching = needle === undefined ? locations : locations.filter((location) => location.name.toLocaleLowerCase().includes(needle));
    const rows = matching.slice(0, input.limit).map((location) => ({
      location_id: num(location.id, "location id"),
      name: location.name,
      parent_location_id: numOrNull(location.parent_location_id, "parent_location_id"),
      is_freezer: bool(location.is_freezer),
    }));

    const what = input.query === undefined ? plural(matching.length, "location") : `${plural(matching.length, "location")} matching "${input.query}"`;
    const shown = rows.length < matching.length ? `; showing ${rows.length}, narrow it with query` : "";
    return { data: { rows, total: matching.length }, text: `${what}${shown}.` };
  },
};
