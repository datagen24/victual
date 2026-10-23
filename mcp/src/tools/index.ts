import { stockOverview } from "./stock-overview.js";
import { expiringSoon } from "./expiring-soon.js";
import { missingProducts } from "./missing-products.js";
import { findProduct } from "./find-product.js";
import { shoppingList } from "./shopping-list.js";
import { recipesICanCook } from "./recipes-i-can-cook.js";
import { listLocations } from "./list-locations.js";
import { listQuantityUnits } from "./list-quantity-units.js";
import { listProductGroups } from "./list-product-groups.js";
import { lookupBarcode } from "./lookup-barcode.js";
import { recentTransactions } from "./recent-transactions.js";
import { addToShoppingList } from "./add-to-shopping-list.js";
import { consumeProduct } from "./consume-product.js";
import { purchaseProduct } from "./purchase-product.js";
import { createProduct } from "./create-product.js";
import { updateProduct } from "./update-product.js";
import { createLocation } from "./create-location.js";
import { createQuantityUnit } from "./create-quantity-unit.js";
import { createProductGroup } from "./create-product-group.js";
import { addBarcode } from "./add-barcode.js";
import { inventoryProduct } from "./inventory-product.js";
import { openProduct } from "./open-product.js";
import { transferProduct } from "./transfer-product.js";
import { undoTransaction } from "./undo-transaction.js";
import { checkOffShoppingListItem } from "./check-off-shopping-list-item.js";

// Fixed, deterministic order — §5's tools/list ordering rule.
export const ALL_TOOLS = [
  stockOverview,
  expiringSoon,
  missingProducts,
  findProduct,
  shoppingList,
  recipesICanCook,
  // ADR-0052 decision 1's reads, which the catalog tools take their ids from.
  listLocations,
  listQuantityUnits,
  listProductGroups,
  lookupBarcode,
  // ADR-0052 decision 1 as amended 2026-10-23: the journal, so an undo outlives its conversation.
  recentTransactions,
  // §6 write tools, after the reads; config and the capability filter decide who sees them.
  addToShoppingList,
  consumeProduct,
  purchaseProduct,
  // ADR-0052 decision 1's stock and list writes, in `stock-write` with the three above.
  inventoryProduct,
  openProduct,
  transferProduct,
  undoTransaction,
  checkOffShoppingListItem,
  // ADR-0052 decision 1's catalog writes: off unless named or `catalog-write`.
  createProduct,
  updateProduct,
  createLocation,
  createQuantityUnit,
  createProductGroup,
  addBarcode,
] as const;
