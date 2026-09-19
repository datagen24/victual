/**
 * The slices of Victual's REST responses the tools read. Only the fields used are
 * declared; everything else in a row is ignored, which is what keeps `uihelper_*`-width
 * rows and embedded `product{}` objects from ever reaching a client (§5). Shapes are
 * those recorded in tests/Pgsql/snapshots/contract-admin.json (plan 14 piece 2).
 */

export type Numeric = number | string;

export interface ProductRow {
  id: Numeric;
  name: string;
  product_group_id: Numeric | null;
  qu_id_stock: Numeric;
  active?: Numeric;
}

/** GET /api/stock, and the due/overdue/expired sections of GET /api/stock/volatile. */
export interface CurrentStockRow {
  product_id: Numeric;
  amount: Numeric;
  amount_opened: Numeric;
  best_before_date: string | null;
  product?: ProductRow;
}

/** GET /api/stock/volatile -> missing_products (the stock_missing_products view). */
export interface MissingProductRow {
  id: Numeric;
  name: string;
  amount_missing: Numeric;
  is_partly_in_stock: Numeric | boolean;
}

export interface VolatileStock {
  due_products: CurrentStockRow[];
  overdue_products: CurrentStockRow[];
  expired_products: CurrentStockRow[];
  missing_products: MissingProductRow[];
}

/** GET /api/objects/shopping_list. */
export interface ShoppingListRow {
  id: Numeric;
  shopping_list_id: Numeric;
  product_id: Numeric | null;
  amount: Numeric;
  qu_id: Numeric | null;
  done: Numeric | boolean;
  note: string | null;
}

/** GET /api/recipes/fulfillment (recipes_resolved). */
export interface RecipeFulfillmentRow {
  recipe_id: Numeric;
  need_fulfilled: Numeric | boolean;
  need_fulfilled_with_shopping_list: Numeric | boolean;
  missing_products_count: Numeric;
}

/** GET /api/objects/recipes. */
export interface RecipeRow {
  id: Numeric;
  name: string;
  type: string;
}
