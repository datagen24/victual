/**
 * Row shaping shared across tools: unit-name resolution and the due-date sentinel
 * translation (§5). Victual's "never expires" sentinel becomes `null`, and amounts stay
 * in the product's stock unit with the display name resolved separately rather than
 * embedded in every row.
 */
import type { ToolContext } from "../tools/types.js";
import { VictualApiError } from "./client.js";

export const NEVER_EXPIRES_SENTINEL = "2999-12-31";

export function normalizeDueDate(dueDate: string | null | undefined): string | null {
  if (!dueDate) return null;
  // Victual returns dates, but a DATETIME column read through a view can carry a time;
  // the spec's contract is YYYY-MM-DD.
  const date = dueDate.slice(0, 10);
  return date === NEVER_EXPIRES_SENTINEL ? null : date;
}

/**
 * Victual's JSON carries numbers as numbers on PostgreSQL, but a DECIMAL column or an
 * aggregate can arrive as a string ("1.5"), and SQLite-era clients sent everything as
 * strings. So a finite number or a numeric string is accepted; anything else - missing,
 * blank, non-numeric, NaN, Infinity - is a response this sidecar does not understand, and
 * says so as a `victual_error` rather than shaping it into a plausible 0.
 */
export function num(value: unknown, field = "value"): number {
  const parsed = typeof value === "number" ? value : typeof value === "string" && value.trim() !== "" ? Number(value) : NaN;
  if (!Number.isFinite(parsed)) {
    throw new VictualApiError("victual_error", `Victual returned an unexpected ${field}: ${JSON.stringify(value) ?? "undefined"}`);
  }
  return parsed;
}

/** `num`, except that null and a missing value mean null. Nothing else does. */
export function numOrNull(value: unknown, field = "value"): number | null {
  if (value === null || value === undefined) return null;
  return num(value, field);
}

/** A list-shaped section of a Victual response, which must be there and be a list. */
export function list<T>(value: T[] | undefined | null, field: string): T[] {
  if (!Array.isArray(value)) {
    throw new VictualApiError("victual_error", `Victual's response has no ${field} list`);
  }
  return value;
}

/** Victual's integer booleans (0/1, "0"/"1") and real booleans alike. */
export function bool(value: unknown): boolean {
  return value === true || value === 1 || value === "1";
}

export function textOrNull(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

export interface QuantityUnit {
  id: number | string;
  name: string;
}

/** id -> display name, built once per request per §5's "one GET per request" rule. */
export function buildUnitNameMap(units: QuantityUnit[]): Map<number, string> {
  return new Map(units.map((unit) => [num(unit.id, "quantity unit id"), unit.name]));
}

export async function fetchUnitNames(ctx: ToolContext): Promise<Map<number, string>> {
  const units = await ctx.client.get<QuantityUnit[]>("/api/objects/quantity_units", ctx.credential);
  return buildUnitNameMap(units);
}

/**
 * A unit's display name, or "" when there is no unit: a free-text shopping list item has
 * none, and that is not an error. Lenient on purpose, unlike `num`.
 */
export function unitName(units: Map<number, string>, id: unknown): string {
  if (id === null || id === undefined || id === "") return "";
  return units.get(Number(id)) ?? "";
}

/** Ascending by due date, nulls (never expires) last — §5.1's truncation rule. */
export function byDueDate<T extends { due_date: string | null }>(a: T, b: T): number {
  if (a.due_date === b.due_date) return 0;
  if (a.due_date === null) return 1;
  if (b.due_date === null) return -1;
  return a.due_date < b.due_date ? -1 : 1;
}

export function plural(count: number, one: string, many = `${one}s`): string {
  return `${count} ${count === 1 ? one : many}`;
}
