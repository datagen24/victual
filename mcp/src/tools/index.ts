import { stockOverview } from "./stock-overview.js";
import { expiringSoon } from "./expiring-soon.js";
import { missingProducts } from "./missing-products.js";
import { findProduct } from "./find-product.js";
import { shoppingList } from "./shopping-list.js";
import { recipesICanCook } from "./recipes-i-can-cook.js";
import { addToShoppingList } from "./add-to-shopping-list.js";
import { consumeProduct } from "./consume-product.js";
import { purchaseProduct } from "./purchase-product.js";

// Fixed, deterministic order — §5's tools/list ordering rule.
export const ALL_TOOLS = [
  stockOverview,
  expiringSoon,
  missingProducts,
  findProduct,
  shoppingList,
  recipesICanCook,
  // §6 write tools, after the reads; config and the capability filter decide who sees them.
  addToShoppingList,
  consumeProduct,
  purchaseProduct,
] as const;
