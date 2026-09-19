// Handler unit tests against a mocked Victual client (spec §11's "tools/" layer). The
// fixture rows use the shapes recorded in tests/Pgsql/snapshots/contract-admin.json,
// including the ways real rows vary: string-typed decimals, the 2999-12-31 sentinel,
// free-text shopping list items, meal-plan shadow recipes.
import { test } from "node:test";
import assert from "node:assert/strict";
import { stockOverview } from "../../dist/tools/stock-overview.js";
import { expiringSoon } from "../../dist/tools/expiring-soon.js";
import { missingProducts } from "../../dist/tools/missing-products.js";
import { findProduct, serverSideTerm } from "../../dist/tools/find-product.js";
import { shoppingList } from "../../dist/tools/shopping-list.js";
import { recipesICanCook } from "../../dist/tools/recipes-i-can-cook.js";
import { VictualApiError } from "../../dist/victual/client.js";

const units = [{ id: 1, name: "Piece" }, { id: 2, name: "Pack" }];
const product = (id, name, qu = 1, group = null) => ({ id, name, qu_id_stock: qu, product_group_id: group });

function ctx(routes) {
  const calls = [];
  return {
    calls,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path, credential, params) {
        calls.push({ path, params: params?.toString() ?? "", credential });
        if (path === "/api/objects/quantity_units") return units;
        if (!(path in routes)) throw new VictualApiError("not_found", `no fixture for ${path}`, 404);
        const value = routes[path];
        return typeof value === "function" ? value(params) : value;
      },
    },
  };
}

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

test("stock_overview: due date ascending, sentinel -> null and last, units resolved, strings coerced", async () => {
  const c = ctx({
    "/api/stock": [
      { product_id: 1, amount: 3, amount_opened: 0, best_before_date: "2999-12-31", product: product(1, "Rice", 2, 4) },
      { product_id: 2, amount: "1.5", amount_opened: "0.5", best_before_date: "2026-09-20", product: product(2, "Milk") },
      { product_id: 3, amount: 1, amount_opened: 0, best_before_date: "2026-09-19 00:00:00", product: product(3, "Bread") },
    ],
  });
  const { data, text } = await run(stockOverview, {}, c);
  assert.deepEqual(data.rows.map((r) => r.name), ["Bread", "Milk", "Rice"]);
  assert.equal(data.rows[0].due_date, "2026-09-19");
  assert.equal(data.rows[2].due_date, null);
  assert.equal(data.rows[1].amount, 1.5);
  assert.equal(data.rows[1].amount_opened, 0.5);
  assert.equal(data.rows[2].unit, "Pack");
  assert.equal(data.total, 3);
  assert.match(text, /^3 products in stock, soonest due Bread on 2026-09-19\.$/);
  assert.ok(stockOverview.outputSchema.safeParse(data).success);
  // The credential is forwarded on every call — nothing is stored.
  assert.ok(c.calls.every((call) => call.credential.headers["VICTUAL-API-KEY"] === "k"));
});

test("stock_overview: group filter and limit keep the urgent rows", async () => {
  const c = ctx({
    "/api/stock": [
      { product_id: 1, amount: 1, amount_opened: 0, best_before_date: "2026-10-01", product: product(1, "A", 1, 7) },
      { product_id: 2, amount: 1, amount_opened: 0, best_before_date: "2026-09-25", product: product(2, "B", 1, 7) },
      { product_id: 3, amount: 1, amount_opened: 0, best_before_date: "2026-09-20", product: product(3, "C", 1, 8) },
    ],
  });
  const { data, text } = await run(stockOverview, { product_group_id: 7, limit: 1 }, c);
  assert.deepEqual(data.rows.map((r) => r.name), ["B"]);
  assert.equal(data.total, 2);
  assert.match(text, /showing 1/);
});

test("expiring_soon: passes due_soon_days and shapes all three sections", async () => {
  const row = (id, name, date) => ({ product_id: id, amount: 1, amount_opened: 0, best_before_date: date, product: product(id, name) });
  const c = ctx({
    "/api/stock/volatile": {
      due_products: [row(1, "Milk", "2026-09-21")],
      overdue_products: [row(2, "Yoghurt", "2026-09-18")],
      expired_products: [row(3, "Chicken", "2026-09-10")],
      missing_products: [],
    },
  });
  const { data, text } = await run(expiringSoon, { days: 7 }, c);
  assert.equal(c.calls.find((call) => call.path === "/api/stock/volatile").params, "due_soon_days=7");
  assert.equal(data.due[0].name, "Milk");
  assert.equal(data.overdue[0].name, "Yoghurt");
  assert.equal(data.expired[0].name, "Chicken");
  assert.equal(text, "1 product due within 7 days, 1 overdue, 1 expired.");
  assert.ok(expiringSoon.outputSchema.safeParse(data).success);
});

test("expiring_soon: rejects days out of range at the schema", () => {
  assert.equal(expiringSoon.inputSchema.safeParse({ days: 31 }).success, false);
});

test("missing_products: integer booleans become booleans", async () => {
  const c = ctx({
    "/api/stock/volatile": {
      due_products: [], overdue_products: [], expired_products: [],
      missing_products: [
        { id: 4, name: "Eggs", amount_missing: "6", is_partly_in_stock: 1 },
        { id: 5, name: "Butter", amount_missing: 1, is_partly_in_stock: 0 },
      ],
    },
  });
  const { data } = await run(missingProducts, {}, c);
  assert.deepEqual(data.rows, [
    { product_id: 5, name: "Butter", amount_missing: 1, is_partly_in_stock: false },
    { product_id: 4, name: "Eggs", amount_missing: 6, is_partly_in_stock: true },
  ]);
});

test("find_product: server-side term is the longest admissible run; full query applied locally", async () => {
  assert.equal(serverSideTerm("Ben & Jerry's"), "Jerry");
  assert.equal(serverSideTerm("oat milk"), "oat milk");
  const c = ctx({
    "/api/objects/products": [product(1, "Ben & Jerry's Cookie Dough"), product(2, "Jerry cans")],
    "/api/stock": [{ product_id: 1, amount: 2, amount_opened: 0, best_before_date: null }],
  });
  const { data } = await run(findProduct, { query: "Ben & Jerry's" }, c);
  const params = new URLSearchParams(c.calls.find((call) => call.path === "/api/objects/products").params);
  assert.deepEqual(params.getAll("query[]"), ["name~Jerry", "active=1"]);
  assert.deepEqual(data.rows, [
    { product_id: 1, name: "Ben & Jerry's Cookie Dough", product_group_id: null, unit: "Piece", in_stock_amount: 2 },
  ]);
});

test("shopping_list: free-text items named by note, open items first, list id filtered server-side", async () => {
  const c = ctx({
    "/api/objects/shopping_list": [
      { id: 1, shopping_list_id: 2, product_id: 4, amount: 6, qu_id: 1, done: 1, note: null },
      { id: 2, shopping_list_id: 2, product_id: null, amount: 1, qu_id: null, done: 0, note: "Birthday card" },
    ],
    "/api/objects/products": [product(4, "Eggs")],
  });
  const { data, text } = await run(shoppingList, { shopping_list_id: 2 }, c);
  assert.equal(c.calls.find((call) => call.path === "/api/objects/shopping_list").params, "query%5B%5D=shopping_list_id%3D2");
  assert.deepEqual(data.rows.map((r) => [r.name, r.done]), [["Birthday card", false], ["Eggs", true]]);
  assert.equal(data.rows[1].unit, "Piece");
  assert.equal(text, "2 items on shopping list 2, 1 not yet done.");
  assert.ok(shoppingList.outputSchema.safeParse(data).success);
});

test("recipes_i_can_cook: only type=normal recipes; missing_little excludes can_cook", async () => {
  const c = ctx({
    "/api/recipes/fulfillment": [
      { recipe_id: 1, need_fulfilled: 1, need_fulfilled_with_shopping_list: 1, missing_products_count: 0 },
      { recipe_id: 2, need_fulfilled: 0, need_fulfilled_with_shopping_list: 1, missing_products_count: 2 },
      { recipe_id: 3, need_fulfilled: 0, need_fulfilled_with_shopping_list: 0, missing_products_count: 5 },
      { recipe_id: -9, need_fulfilled: 1, need_fulfilled_with_shopping_list: 1, missing_products_count: 0 },
    ],
    "/api/objects/recipes": [
      { id: 1, name: "Pancakes", type: "normal" },
      { id: 2, name: "Omelette", type: "normal" },
      { id: 3, name: "Lasagne", type: "normal" },
      { id: -9, name: "2026-09-19#1", type: "mealplan-day" },
    ],
  });
  const { data } = await run(recipesICanCook, {}, c);
  assert.deepEqual(data.can_cook.map((r) => r.name), ["Pancakes"]);
  assert.deepEqual(data.missing_little, [{ recipe_id: 2, name: "Omelette", missing_products_count: 2 }]);
});

test("REST failures propagate as VictualApiError for the server's §7 mapping", async () => {
  const c = ctx({ "/api/stock": () => { throw new VictualApiError("forbidden", "Victual answered 403", 403); } });
  await assert.rejects(run(stockOverview, {}, c), (error) => error.category === "forbidden" && error.victualStatus === 403);
});

test("a volatile-stock response missing a section is a victual_error, not an empty answer", async () => {
  const c = ctx({ "/api/stock/volatile": { due_products: [], overdue_products: [], missing_products: [] } });
  await assert.rejects(run(expiringSoon, {}, c), (error) => error.category === "victual_error" && /expired_products/.test(error.message));

  const m = ctx({ "/api/stock/volatile": { due_products: [], overdue_products: [], expired_products: [] } });
  await assert.rejects(run(missingProducts, {}, m), (error) => error.category === "victual_error" && /missing_products/.test(error.message));
});

test("a required number Victual did not send is a victual_error, not a plausible 0", async () => {
  for (const bad of [undefined, "", "abc", Number.NaN, Number.POSITIVE_INFINITY]) {
    const c = ctx({
      "/api/stock": [{ product_id: 1, amount: bad, amount_opened: 0, best_before_date: null, product: product(1, "Rice") }],
    });
    await assert.rejects(run(stockOverview, {}, c), (error) => error.category === "victual_error", `amount ${String(bad)}`);
  }
});
