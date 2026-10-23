import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { ProductRow, StockJournalRow } from "../victual/types.js";
import { bool, fetchUnitNames, normalizeDueDate, num, numOrNull, plural, textOrNull, unitName } from "../victual/shape.js";

// ADR-0052 decision 1, amended 2026-10-23. GET /api/objects/stock_log for one product, newest
// first, so a later conversation can find the transaction_id undo_transaction takes. One
// booking can write several rows (a consume across entries, a transfer's two halves), and they
// share a transaction_id. stock_log carries `price`, and only the named fields below leave.
const inputSchema = z.object({
  product_id: z.number().int().positive().describe("The product (find_product)"),
  limit: z.number().int().min(1).max(100).default(20).describe("Maximum rows, newest first (default 20, max 100)"),
  include_undone: z.boolean().default(true).describe("False leaves out rows already undone"),
});

const outputSchema = z.object({
  product_id: z.number(),
  name: z.string(),
  unit: z.string(),
  rows: z.array(
    z.object({
      transaction_id: z.string(),
      type: z.string(),
      amount: z.number(),
      location_id: z.number().nullable(),
      due_date: z.string().nullable(),
      undone: z.boolean(),
      timestamp: z.string().nullable(),
    }),
  ),
});

export const recentTransactions: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "recent_transactions",
  title: "Recent stock transactions",
  description:
    "List a product's newest stock journal rows: purchases, consumes, counts, opens, moves and edits, with the transaction_id undo_transaction takes. Rows that share a transaction_id are one booking, and an undo reverses them together. A row marked undone cannot be undone again.",
  permission: "STOCK_VIEW",
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const params = new URLSearchParams();
    params.append("query[]", `product_id=${input.product_id}`);
    if (!input.include_undone) params.append("query[]", "undone=0");
    // id, not row_created_timestamp: the timestamp is truncated to the second, and one booking's
    // rows, or two quick bookings, share it.
    params.append("order", "id:desc");
    params.append("limit", String(input.limit));

    const [journal, product, units] = await Promise.all([
      ctx.client.get<StockJournalRow[]>("/api/objects/stock_log", ctx.credential, params),
      ctx.client.get<ProductRow>(`/api/objects/products/${input.product_id}`, ctx.credential),
      fetchUnitNames(ctx),
    ]);

    // A row without a transaction_id predates the column and cannot be undone by id.
    const rows = journal
      .filter((row) => typeof row.transaction_id === "string" && row.transaction_id !== "")
      .map((row) => ({
        transaction_id: row.transaction_id as string,
        type: row.transaction_type,
        amount: num(row.amount, "stock_log amount"),
        location_id: numOrNull(row.location_id, "location_id"),
        due_date: normalizeDueDate(row.best_before_date),
        undone: bool(row.undone),
        timestamp: textOrNull(row.row_created_timestamp),
      }));

    const bookings = new Set(rows.map((row) => row.transaction_id)).size;
    return {
      data: { product_id: input.product_id, name: product.name, unit: unitName(units, product.qu_id_stock), rows },
      text: `${plural(rows.length, "journal row")} for "${product.name}", newest first, from ${plural(bookings, "transaction")}.`,
    };
  },
};
