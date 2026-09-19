import { stockOverview } from "./stock-overview.js";
import { expiringSoon } from "./expiring-soon.js";
import { missingProducts } from "./missing-products.js";
import { findProduct } from "./find-product.js";
import { shoppingList } from "./shopping-list.js";
import { recipesICanCook } from "./recipes-i-can-cook.js";

// Fixed, deterministic order — §5's tools/list ordering rule.
export const ALL_TOOLS = [
  stockOverview,
  expiringSoon,
  missingProducts,
  findProduct,
  shoppingList,
  recipesICanCook,
] as const;
