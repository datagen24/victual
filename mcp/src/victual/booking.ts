import { randomUUID } from "node:crypto";
import { z } from "zod";
import type { ToolContext } from "../tools/types.js";
import type { StockLogRow } from "./types.js";
import { VictualApiError } from "./client.js";
import { transactionId } from "./transaction.js";

/**
 * Stock bookings with ADR-0046's `Idempotency-Key` (ADR-0052 decision 4). The five bookings
 * are purchase, consume, transfer, open and inventory; undo is not one of them.
 *
 * The sidecar keeps no session (§2), so ADR-0046 obligation 1's "read the capability once
 * per session" becomes once per booking call: `GET /api/system/info` before every booking,
 * and the header only to a Victual that publishes `stock_booking_idempotency`.
 */

/** ADR-0046's key grammar, so a model-supplied `request_id` is refused here rather than by Victual. */
export const requestIdSchema = z
  .string()
  .regex(/^[A-Za-z0-9._:-]{8,128}$/, "8 to 128 characters of A-Z a-z 0-9 . _ : -")
  .optional()
  .describe(
    "Only to retry a booking whose outcome was unknown: the request_id that attempt returned, with exactly the same values. Leave it out otherwise.",
  );

interface IdempotencyCapability {
  header?: unknown;
  retention_seconds?: unknown;
}

/** The capability Victual publishes, or null for a Victual that predates ADR-0046. */
async function capability(ctx: ToolContext): Promise<{ header: string } | null> {
  let info: { stock_booking_idempotency?: IdempotencyCapability } | undefined;
  try {
    info = await ctx.client.get("/api/system/info", ctx.credential);
  } catch (error) {
    // A Victual without the route books unkeyed, as one without the capability does. Any
    // other failure (unreachable, unauthorized) would fail the booking too, and says so first.
    if (error instanceof VictualApiError && error.category === "not_found") return null;
    throw error;
  }
  const published = info?.stock_booking_idempotency;
  if (typeof published !== "object" || published === null) return null;
  return { header: typeof published.header === "string" && published.header !== "" ? published.header : "Idempotency-Key" };
}

export interface BookingResult {
  transaction_id: string;
  /** Present when the booking was keyed: what a retry of this same booking must send. */
  request_id?: string;
  /** Victual answered `Idempotent-Replayed: true`: an earlier attempt booked, this one did not. */
  replayed: boolean;
}

/**
 * POST one booking and read its transaction id. Every error from a keyed attempt carries the
 * `request_id`, so the tool error can tell the model that a retry with it is safe.
 */
export async function book(
  ctx: ToolContext,
  path: string,
  body: Record<string, unknown>,
  requestId: string | undefined,
): Promise<BookingResult> {
  const keyed = await capability(ctx);
  const id = keyed ? (requestId ?? randomUUID()) : undefined;
  try {
    const { status, body: rows, headers } = await ctx.client.postWithStatus<StockLogRow[]>(
      path,
      ctx.credential,
      body,
      keyed && id ? { [keyed.header]: id } : {},
    );
    return {
      transaction_id: transactionId(rows, status),
      ...(id ? { request_id: id } : {}),
      replayed: headers?.get("idempotent-replayed") === "true",
    };
  } catch (error) {
    if (id && error instanceof VictualApiError) error.requestId = id;
    throw error;
  }
}

/** The `text` sentence for a replay, which must never read as a second booking. */
export const REPLAY_NOTE = "Victual replayed an earlier attempt with this request_id: nothing new was booked.";
