import { z } from "zod";
import type { ToolDefinition } from "./types.js";
import type { StockEntryRow, StockLogRow } from "../victual/types.js";
import { VictualApiError } from "../victual/client.js";
import { NEVER_EXPIRES_SENTINEL, normalizeDueDate, num, numOrNull, plural } from "../victual/shape.js";
import { transactionId, UNDO_NOTE } from "../victual/transaction.js";

// ADR-0052 decision 1, amended 2026-10-23. GET /api/stock/products/{id}/entries, then
// PUT /api/stock/entry/{entryId} for each selected entry whose date differs. The route requires
// `amount` and keeps every field the body omits, so the body is the entry's own amount and the
// new best_before_date: nothing else on the entry changes. Each PUT is its own transaction,
// which undo_transaction reverses. An entry edit is not one of ADR-0046's five bookings, so it
// carries no Idempotency-Key; repeating the call is harmless because an entry that already has
// the date is skipped.
const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "use YYYY-MM-DD");

const inputSchema = z.object({
  product_id: z.number().int().positive().describe("The product whose stock on hand gets the date (find_product)"),
  due_date: isoDate.nullable().describe("The new due date, YYYY-MM-DD, or null for never expires"),
  location_id: z.number().int().positive().optional().describe("Only the stock at this location (default: everywhere)"),
  current_due_date: isoDate
    .nullable()
    .optional()
    .describe("Only the stock currently due on this date, or null for stock that never expires (default: any date)"),
});

const outputSchema = z.object({
  product_id: z.number(),
  due_date: z.string().nullable(),
  changed: z.array(z.object({ entry_id: z.number(), amount: z.number(), transaction_id: z.string() })),
  unchanged: z.number(),
});

export const setDueDate: ToolDefinition<typeof inputSchema, typeof outputSchema> = {
  name: "set_due_date",
  title: "Set due date of stock on hand",
  description:
    "Set the due date of a product's stock already on hand, for example after counting a product created without a shelf life. Only the date changes. Narrow it with location_id or current_due_date; stock bought at different times usually has different dates. To change the date for future stock, use update_product's default_best_before_days.",
  permission: "STOCK_EDIT",
  alsoRequires: ["STOCK_VIEW"],
  write: true,
  destructive: true,
  inputSchema,
  outputSchema,
  handler: async (input, ctx) => {
    const entries = await ctx.client.get<StockEntryRow[]>(`/api/stock/products/${input.product_id}/entries`, ctx.credential);
    const target = input.due_date ?? NEVER_EXPIRES_SENTINEL;

    const selected = entries.filter(
      (entry) =>
        (input.location_id === undefined || numOrNull(entry.location_id, "location_id") === input.location_id) &&
        (input.current_due_date === undefined || normalizeDueDate(entry.best_before_date) === input.current_due_date),
    );
    const toChange = selected.filter((entry) => normalizeDueDate(entry.best_before_date) !== input.due_date);

    const changed: { entry_id: number; amount: number; transaction_id: string }[] = [];
    for (const entry of toChange) {
      const entryId = num(entry.id, "stock entry id");
      const amount = num(entry.amount, "stock entry amount");
      try {
        const rows = await ctx.client.put<StockLogRow[]>(`/api/stock/entry/${entryId}`, ctx.credential, {
          amount,
          best_before_date: target,
        });
        changed.push({ entry_id: entryId, amount, transaction_id: transactionId(rows, 200) });
      } catch (error) {
        if (!(error instanceof VictualApiError) || changed.length === 0) throw error;
        // Earlier entries were changed, each in its own transaction: say which, so the model
        // neither repeats nor forgets them.
        const done = changed.map((c) => `entry ${c.entry_id} (transaction ${c.transaction_id})`).join(", ");
        throw new VictualApiError(error.category, `${error.message}; already changed before entry ${entryId}: ${done}`, error.victualStatus, error.code);
      }
    }

    const unchanged = selected.length - changed.length;
    const when = input.due_date === null ? "never expire" : `be due ${input.due_date}`;
    const text =
      selected.length === 0
        ? `No stock entry of product ${input.product_id} matched; nothing changed.`
        : `Set ${plural(changed.length, "stock entry", "stock entries")} of product ${input.product_id} to ${when}` +
          (unchanged > 0 ? `; ${unchanged} already had that date.` : ".") +
          (changed.length > 0 ? ` Each change is its own transaction. ${UNDO_NOTE}` : "");
    return { data: { product_id: input.product_id, due_date: input.due_date, changed, unchanged }, text };
  },
};
