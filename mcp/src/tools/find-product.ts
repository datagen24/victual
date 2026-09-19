import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { CurrentStockRow, ProductRow } from "../victual/types.js";
import { fetchUnitNames, num, numOrNull, plural, unitName } from "../victual/shape.js";

// §5.4. Backed by GET /api/objects/products?query[]=name~{query} + GET /api/stock for
// amounts. Substring match server-side; no fuzzy layer in v1.
const inputSchema = z.object({
  query: z.string().min(1).describe("Part of the product name, case-insensitive"),
  limit: z.number().int().min(1).max(25).default(10).describe("Maximum rows (default 10, max 25)"),
});

const rowSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  product_group_id: z.number().nullable(),
  unit: z.string(),
  in_stock_amount: z.number(),
});

const outputSchema = z.object({ rows: z.array(rowSchema) });

/**
 * Victual's query[] value pattern (BaseApiController::PATTERN_VALUE) admits letters,
 * digits, space and a few punctuation marks, and the match is unanchored — a value with
 * anything else in it is silently cut at that character. So the server-side filter gets
 * the longest run the pattern admits, and the full query is applied here, which keeps
 * "Ben & Jerry's" from quietly becoming a search for "Ben ".
 */
export function serverSideTerm(query: string): string {
  const runs = query.match(/[\p{L}\p{M}0-9 ._-]+/gu) ?? [];
  return runs.map((run) => run.trim()).sort((a, b) => b.length - a.length)[0] ?? "";
}

export const findProduct: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "find_product",
  title: "Find product",
  description:
    "Resolve a product name to its id (the other tools take ids), with its unit and how much is in stock. Substring match.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const term = serverSideTerm(input.query);
    const params = new URLSearchParams();
    if (term !== "") params.append("query[]", `name~${term}`);
    params.append("query[]", "active=1");
    params.append("order", "name:asc");

    const [products, stock, units] = await Promise.all([
      ctx.client.get<ProductRow[]>("/api/objects/products", ctx.credential, params),
      ctx.client.get<CurrentStockRow[]>("/api/stock", ctx.credential),
      fetchUnitNames(ctx),
    ]);

    const inStock = new Map(stock.map((row) => [num(row.product_id), num(row.amount)]));
    const needle = input.query.toLocaleLowerCase();
    const rows = products
      .filter((product) => product.name.toLocaleLowerCase().includes(needle))
      .slice(0, input.limit)
      .map((product) => ({
        product_id: num(product.id),
        name: product.name,
        product_group_id: numOrNull(product.product_group_id),
        unit: unitName(units, product.qu_id_stock),
        in_stock_amount: inStock.get(num(product.id)) ?? 0,
      }));

    return { data: { rows }, text: `${plural(rows.length, "product")} matching "${input.query}".` };
  },
};
