// ADR-0052 decision 1's stock and list writes: inventory's read-first no-op, keyed bookings
// for open/transfer/inventory, undo's unkeyed 204, and checking a list item off as an edit.
import { test } from "node:test";
import assert from "node:assert/strict";
import { inventoryProduct } from "../../dist/tools/inventory-product.js";
import { openProduct } from "../../dist/tools/open-product.js";
import { transferProduct } from "../../dist/tools/transfer-product.js";
import { undoTransaction } from "../../dist/tools/undo-transaction.js";
import { checkOffShoppingListItem } from "../../dist/tools/check-off-shopping-list-item.js";
import { ALL_TOOLS } from "../../dist/tools/index.js";
import { ALL_READ_TOOLS, WRITE_TOOLS } from "../../dist/config.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);
const KEYED = { stock_booking_idempotency: { header: "Idempotency-Key", retention_seconds: 604800 } };

function ctx({ stockAmount = 0, info = KEYED } = {}) {
  const gets = [];
  const posts = [];
  const puts = [];
  return {
    gets,
    posts,
    puts,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path) {
        gets.push(path);
        if (path === "/api/system/info") return info;
        if (/^\/api\/stock\/products\/\d+$/.test(path)) return { product: { id: 7, name: "Beans" }, stock_amount: stockAmount, last_price: 9.99 };
        throw new Error(`unexpected GET ${path}`);
      },
      async post(path, credential, body) {
        posts.push({ path, body });
      },
      async postWithStatus(path, credential, body, headers = {}) {
        posts.push({ path, body, headers });
        return { status: 200, body: [{ transaction_id: "tx-9" }], headers: new Headers({ "Idempotent-Replayed": "false" }) };
      },
      async put(path, credential, body) {
        puts.push({ path, body });
      },
    },
  };
}

test("inventory_product reads the current amount, then books the absolute new amount with a key", async () => {
  const c = ctx({ stockAmount: "1" });
  const { data, text } = await run(inventoryProduct, { product_id: 7, new_amount: 3, best_before_date: "2027-03-01" }, c);
  assert.deepEqual(c.gets, ["/api/stock/products/7", "/api/system/info"]);
  assert.equal(c.posts[0].path, "/api/stock/products/7/inventory");
  assert.deepEqual(c.posts[0].body, { new_amount: 3, best_before_date: "2027-03-01" });
  assert.ok(c.posts[0].headers["Idempotency-Key"]);
  assert.deepEqual(
    { previous: data.previous_amount, now: data.new_amount, changed: data.changed, tx: data.transaction_id },
    { previous: 1, now: 3, changed: true, tx: "tx-9" },
  );
  assert.match(text, /from 1 to 3/);
});

test("inventory_product with the amount already in stock books nothing (Victual would refuse it)", async () => {
  const c = ctx({ stockAmount: 3 });
  const { data, text } = await run(inventoryProduct, { product_id: 7, new_amount: 3 }, c);
  assert.equal(c.posts.length, 0);
  assert.deepEqual(c.gets, ["/api/stock/products/7"], "no capability probe either: nothing is booked");
  assert.equal(data.changed, false);
  assert.equal(data.transaction_id, null);
  assert.match(text, /nothing was booked/);
  assert.doesNotMatch(JSON.stringify(data), /price/);
});

test("inventory_product to zero is allowed; a negative count is not", async () => {
  const c = ctx({ stockAmount: 2 });
  assert.equal((await run(inventoryProduct, { product_id: 7, new_amount: 0 }, c)).data.changed, true);
  assert.equal(inventoryProduct.inputSchema.safeParse({ product_id: 7, new_amount: -1 }).success, false);
});

test("open_product defaults to one and is keyed; transfer_product needs two different locations", async () => {
  const c = ctx();
  await run(openProduct, { product_id: 7 }, c);
  assert.deepEqual(c.posts[0].body, { amount: 1 });
  assert.ok(c.posts[0].headers["Idempotency-Key"]);
  const { data } = await run(transferProduct, { product_id: 7, amount: 2, location_id_from: 1, location_id_to: 3 }, c);
  assert.deepEqual(c.posts[1].body, { amount: 2, location_id_from: 1, location_id_to: 3 });
  assert.equal(data.location_id_to, 3);
  assert.equal(transferProduct.inputSchema.safeParse({ product_id: 7, amount: 1, location_id_from: 2, location_id_to: 2 }).success, false);
});

test("undo_transaction posts to the undo route with no key and no capability probe", async () => {
  const c = ctx();
  const { data, text } = await run(undoTransaction, { transaction_id: "x6a1b2c3d" }, c);
  assert.deepEqual(c.posts, [{ path: "/api/stock/transactions/x6a1b2c3d/undo", body: {} }]);
  assert.deepEqual(c.gets, []);
  assert.equal(data.undone, true);
  assert.match(text, /marked undone/);
  assert.equal(undoTransaction.inputSchema.safeParse({ transaction_id: "../../objects/products/1" }).success, false);
});

test("check_off_shopping_list_item edits done and never deletes", async () => {
  const c = ctx();
  await run(checkOffShoppingListItem, { item_id: 12 }, c);
  await run(checkOffShoppingListItem, { item_id: 12, done: false }, c);
  assert.deepEqual(c.puts, [
    { path: "/api/objects/shopping_list/12", body: { done: 1 } },
    { path: "/api/objects/shopping_list/12", body: { done: 0 } },
  ]);
});

test("the stock writes are in stock-write, need their booking permission and STOCK_VIEW, and none deletes", () => {
  const expected = {
    inventory_product: ["STOCK_INVENTORY", ["STOCK_VIEW"]],
    open_product: ["STOCK_OPEN", ["STOCK_VIEW"]],
    transfer_product: ["STOCK_TRANSFER", ["STOCK_VIEW"]],
    undo_transaction: ["STOCK_EDIT", []],
    check_off_shopping_list_item: ["SHOPPINGLIST_ITEMS_ADD", []],
  };
  for (const tool of [inventoryProduct, openProduct, transferProduct, undoTransaction, checkOffShoppingListItem]) {
    assert.ok(WRITE_TOOLS.includes(tool.name), tool.name);
    assert.equal(ALL_READ_TOOLS.includes(tool.name), false, tool.name);
    assert.ok(ALL_TOOLS.includes(tool), tool.name);
    assert.equal(tool.write, true, tool.name);
    assert.deepEqual([tool.permission, [...(tool.alsoRequires ?? [])]], expected[tool.name], tool.name);
  }
});

test("no tool calls a delete route or removes a shopping-list item (ADR-0052 decision 2)", async () => {
  const { readFileSync, readdirSync } = await import("node:fs");
  const dir = new URL("../../src/tools/", import.meta.url);
  for (const file of readdirSync(dir)) {
    const source = readFileSync(new URL(file, dir), "utf8");
    assert.doesNotMatch(source, /\.delete\(|method: "DELETE"|remove-product|shoppinglist\/clear/, file);
  }
});
