/**
 * Row shaping shared across tools: unit-name resolution and the due-date sentinel
 * translation (§5). Victual's "never expires" sentinel becomes `null`, and amounts stay
 * in the product's stock unit with the display name resolved separately rather than
 * embedded in every row.
 */

export const NEVER_EXPIRES_SENTINEL = "2999-12-31";

export function normalizeDueDate(dueDate: string | null | undefined): string | null {
  if (!dueDate || dueDate === NEVER_EXPIRES_SENTINEL) return null;
  return dueDate;
}

export interface QuantityUnit {
  id: number;
  name: string;
}

/** id -> display name, built once per request per §5's "one GET per request" rule. */
export function buildUnitNameMap(units: QuantityUnit[]): Map<number, string> {
  return new Map(units.map((unit) => [unit.id, unit.name]));
}
