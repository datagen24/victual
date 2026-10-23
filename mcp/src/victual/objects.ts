import type { ToolContext } from "../tools/types.js";
import { VictualApiError } from "./client.js";
import { num } from "./shape.js";

/**
 * POST /api/objects/{entity}: the generic create route the catalog tools use (ADR-0052
 * decision 1). Victual answers `{created_object_id}`; a 2xx without one cannot say what was
 * created, so it is `outcome_unknown`, as a booking without a transaction row is (#937).
 */
export async function createObject(ctx: ToolContext, entity: string, body: Record<string, unknown>): Promise<number> {
  const { status, body: answer } = await ctx.client.postWithStatus<{ created_object_id?: unknown } | undefined>(
    `/api/objects/${entity}`,
    ctx.credential,
    body,
  );
  if (answer?.created_object_id === undefined || answer.created_object_id === null) {
    throw new VictualApiError(
      "outcome_unknown",
      `Victual answered ${status} but returned no created_object_id, so whether the ${entity} row exists cannot be confirmed`,
      status,
    );
  }
  return num(answer.created_object_id, "created_object_id");
}

/** The note every catalog write ends with: decision 2 keeps deletion in the UI. */
export const DELETE_IN_UI_NOTE = "Deleting it, if that is ever needed, is done in Victual's UI.";
