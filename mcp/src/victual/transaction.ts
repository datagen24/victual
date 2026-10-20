import type { StockLogRow } from "./types.js";
import { VictualApiError } from "./client.js";

/**
 * The `transaction_id` of a booking, read off the stock_log rows Victual answers with.
 * Every row of one booking shares it (a consume across several stock entries writes
 * several rows), so the first is enough.
 *
 * No row is `outcome_unknown` (#937), not a Victual fault: the 2xx says the booking was
 * applied, but without a row nobody can confirm what was booked, so the caller has to
 * check the current state before any retry. The status is kept, as it is for a body that
 * could not be read (#935).
 */
export function transactionId(rows: StockLogRow[] | undefined, status: number): string {
  const id = Array.isArray(rows) ? rows[0]?.transaction_id : undefined;
  if (typeof id !== "string" || id === "") {
    throw new VictualApiError(
      "outcome_unknown",
      `Victual answered ${status} but returned no transaction row, so what was booked cannot be confirmed`,
      status,
    );
  }
  return id;
}

export const UNDO_NOTE = "This can be undone in Victual's stock journal.";
