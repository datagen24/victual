import type { StockLogRow } from "./types.js";
import { VictualApiError } from "./client.js";

/**
 * The `transaction_id` of a booking, read off the stock_log rows Victual answers with.
 * Every row of one booking shares it (a consume across several stock entries writes
 * several rows), so the first is enough. No row means the booking's outcome is unknown,
 * which is reported as a Victual fault rather than as success without an id.
 */
export function transactionId(rows: StockLogRow[] | undefined): string {
  const id = Array.isArray(rows) ? rows[0]?.transaction_id : undefined;
  if (typeof id !== "string" || id === "") {
    throw new VictualApiError("victual_error", "Victual booked the change but returned no transaction id");
  }
  return id;
}

export const UNDO_NOTE = "This can be undone in Victual's stock journal.";
