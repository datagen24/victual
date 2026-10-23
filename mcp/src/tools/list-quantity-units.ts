import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { QuantityUnitRow } from "../victual/types.js";
import { num, plural, textOrNull } from "../victual/shape.js";

// ADR-0052 decision 1. GET /api/objects/quantity_units, active ones by name: the stock unit
// create_product needs ("can", "piece", "g").
const inputSchema = z.object({});

const outputSchema = z.object({
  rows: z.array(z.object({ unit_id: z.number(), name: z.string(), name_plural: z.string().nullable() })),
});

export const listQuantityUnits: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "list_quantity_units",
  title: "List quantity units",
  description: "List the quantity units (piece, can, pack, gram...) with their ids, for creating products.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (_input, ctx) => {
    const params = new URLSearchParams([["query[]", "active=1"], ["order", "name:asc"]]);
    const units = await ctx.client.get<QuantityUnitRow[]>("/api/objects/quantity_units", ctx.credential, params);
    const rows = units.map((unit) => ({
      unit_id: num(unit.id, "unit id"),
      name: unit.name,
      name_plural: textOrNull(unit.name_plural),
    }));
    return { data: { rows }, text: `${plural(rows.length, "quantity unit")}.` };
  },
};
