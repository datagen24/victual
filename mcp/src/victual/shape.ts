/**
 * Row shaping shared across tools: unit-name resolution and the due-date sentinel
 * translation (§5). Victual's "never expires" sentinel becomes `null`, and amounts stay
 * in the product's stock unit with the display name resolved separately rather than
 * embedded in every row.
 */
import type { ToolContext } from "../tools/types.js";

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
 * strings. Shaping accepts either rather than failing a whole response on one row.
 */
export function num(value: unknown): number {
  if (typeof value === "number") return value;
  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) return parsed;
  }
  return 0;
}

export function numOrNull(value: unknown): number | null {
  if (value === null || value === undefined || value === "") return null;
  return num(value);
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
  return new Map(units.map((unit) => [num(unit.id), unit.name]));
}

export async function fetchUnitNames(ctx: ToolContext): Promise<Map<number, string>> {
  const units = await ctx.client.get<QuantityUnit[]>("/api/objects/quantity_units", ctx.credential);
  return buildUnitNameMap(units);
}

export function unitName(units: Map<number, string>, id: unknown): string {
  return units.get(num(id)) ?? "";
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
