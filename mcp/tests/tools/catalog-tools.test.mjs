// ADR-0052 decisions 1-3: the catalog writes. Field mapping to the generic object routes,
// created_object_id handling, create_product's barcode step, update_product's limits, and the
// capability filter's MASTER_DATA_ADD / MASTER_DATA_EDIT split.
import { test } from "node:test";
import assert from "node:assert/strict";
import { createProduct } from "../../dist/tools/create-product.js";
import { updateProduct } from "../../dist/tools/update-product.js";
import { createLocation } from "../../dist/tools/create-location.js";
import { createQuantityUnit } from "../../dist/tools/create-quantity-unit.js";
import { createProductGroup } from "../../dist/tools/create-product-group.js";
import { addBarcode } from "../../dist/tools/add-barcode.js";
import { ALL_TOOLS } from "../../dist/tools/index.js";
import { ALL_READ_TOOLS, CATALOG_WRITE_TOOLS, loadConfig } from "../../dist/config.js";
import { VictualApiError } from "../../dist/victual/client.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

// Each POST answers the next id, or what `answer(path, body)` returns or throws.
function ctx(answer) {
  const posts = [];
  const puts = [];
  let next = 40;
  return {
    posts,
    puts,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async postWithStatus(path, credential, body) {
        posts.push({ path, body });
        const value = answer ? answer(path, body) : { created_object_id: next++ };
        return { status: 200, body: value, headers: new Headers() };
      },
      async put(path, credential, body) {
        puts.push({ path, body });
      },
    },
  };
}

test("create_product sets all four units from unit_id and sends only the optionals given", async () => {
  const c = ctx();
  const { data, text } = await run(createProduct, { name: " Black beans ", location_id: 2, unit_id: 3, min_stock_amount: 2 }, c);
  assert.deepEqual(c.posts, [
    {
      path: "/api/objects/products",
      body: { name: "Black beans", location_id: 2, qu_id_stock: 3, qu_id_purchase: 3, qu_id_consume: 3, qu_id_price: 3, min_stock_amount: 2 },
    },
  ]);
  assert.deepEqual(data, { product_id: 40, name: "Black beans", barcode_added: null });
  assert.match(text, /Created product "Black beans" \(product 40\)/);
  assert.match(text, /Deleting it.*Victual's UI/);
});

test("create_product with a barcode attaches it to the new product's id", async () => {
  const c = ctx();
  const { data, text } = await run(createProduct, { name: "Oat milk", location_id: 1, unit_id: 1, barcode: "7394376616501" }, c);
  assert.deepEqual(c.posts[1], { path: "/api/objects/product_barcodes", body: { product_id: 40, barcode: "7394376616501" } });
  assert.equal(data.barcode_added, true);
  assert.match(text, /Barcode 7394376616501 attached/);
});

test("create_product: a failed barcode is reported on a successful result, never as nothing created", async () => {
  const c = ctx((path) => {
    if (path.endsWith("/product_barcodes")) throw new VictualApiError("invalid_request", "Victual answered 400: barcode already exists", 400);
    return { created_object_id: 41 };
  });
  const { data, text } = await run(createProduct, { name: "Oat milk", location_id: 1, unit_id: 1, barcode: "123" }, c);
  assert.equal(data.product_id, 41);
  assert.equal(data.barcode_added, false);
  assert.match(text, /not attached \(Victual answered 400: barcode already exists\)/);
  assert.match(text, /add_barcode/);
});

test("create_product: a refused product (a taken name) is an error, and no barcode is tried", async () => {
  const c = ctx(() => { throw new VictualApiError("invalid_request", "Victual answered 400: UNIQUE constraint failed: products.name", 400); });
  await assert.rejects(run(createProduct, { name: "Rice", location_id: 1, unit_id: 1, barcode: "1" }, c), (e) => /UNIQUE/.test(e.message));
  assert.equal(c.posts.length, 1);
});

test("a 2xx without created_object_id is outcome_unknown and keeps the status", async () => {
  const c = ctx(() => ({}));
  await assert.rejects(run(createLocation, { name: "Garage" }, c), (e) => e.category === "outcome_unknown" && e.victualStatus === 200);
});

test("create_product refuses what Victual would: no name, ids that are not positive, a negative minimum", () => {
  for (const bad of [
    { name: "", location_id: 1, unit_id: 1 },
    { name: "x", location_id: 0, unit_id: 1 },
    { name: "x", location_id: 1 },
    { name: "x", location_id: 1, unit_id: 1, min_stock_amount: -1 },
    { name: "x", location_id: 1, unit_id: 1, default_best_before_days: -2 },
  ]) {
    assert.equal(createProduct.inputSchema.safeParse(bad).success, false, JSON.stringify(bad));
  }
  assert.equal(createProduct.inputSchema.safeParse({ name: "Honey", location_id: 1, unit_id: 1, default_best_before_days: -1 }).success, true);
});

test("update_product PUTs only the fields given, can clear the group, and cannot touch the unit", async () => {
  const c = ctx();
  const { data } = await run(updateProduct, { product_id: 7, name: "Black beans (can)", product_group_id: null }, c);
  assert.deepEqual(c.puts, [{ path: "/api/objects/products/7", body: { name: "Black beans (can)", product_group_id: null } }]);
  assert.deepEqual(data.changed, ["name", "product_group_id"]);
  assert.equal(updateProduct.inputSchema.safeParse({ product_id: 7 }).success, false, "nothing to change");
  const parsed = updateProduct.inputSchema.parse({ product_id: 7, min_stock_amount: 1, qu_id_stock: 9, unit_id: 9 });
  assert.equal("qu_id_stock" in parsed || "unit_id" in parsed, false, "the stock unit is not an input");
  assert.equal(updateProduct.destructive, true);
});

test("create_location sends the parent and the freezer flag as Victual stores them", async () => {
  const c = ctx();
  await run(createLocation, { name: "Top shelf", parent_location_id: 2 }, c);
  await run(createLocation, { name: "Chest freezer", is_freezer: true }, c);
  assert.deepEqual(c.posts.map((p) => p.body), [
    { name: "Top shelf", is_freezer: 0, parent_location_id: 2 },
    { name: "Chest freezer", is_freezer: 1 },
  ]);
});

test("create_quantity_unit, create_product_group and add_barcode map to their entities", async () => {
  const c = ctx();
  const unit = await run(createQuantityUnit, { name: "Can", name_plural: "Cans" }, c);
  const group = await run(createProductGroup, { name: "Canned goods" }, c);
  const barcode = await run(addBarcode, { product_id: 7, barcode: "4006381333931" }, c);
  assert.deepEqual(c.posts.map((p) => p.path), ["/api/objects/quantity_units", "/api/objects/product_groups", "/api/objects/product_barcodes"]);
  assert.deepEqual([unit.data.unit_id, group.data.product_group_id, barcode.data.barcode_id], [40, 41, 42]);
  assert.deepEqual(c.posts[2].body, { product_id: 7, barcode: "4006381333931" });
});

test("the catalog writes are write tools gated on MASTER_DATA_ADD, except the edit; none is in all-read", () => {
  const tools = [createProduct, updateProduct, createLocation, createQuantityUnit, createProductGroup, addBarcode];
  assert.deepEqual(tools.map((t) => t.name), [...CATALOG_WRITE_TOOLS]);
  for (const tool of tools) {
    assert.equal(tool.write, true, tool.name);
    assert.ok(ALL_TOOLS.includes(tool), tool.name);
    assert.equal(ALL_READ_TOOLS.includes(tool.name), false, tool.name);
    assert.equal(tool.permission, tool === updateProduct ? "MASTER_DATA_EDIT" : "MASTER_DATA_ADD", tool.name);
  }
  assert.deepEqual(loadConfig({ VICTUAL_BASE_URL: "http://x", MCP_ENABLED_TOOLS: "catalog-write" }).MCP_ENABLED_TOOLS, [...CATALOG_WRITE_TOOLS]);
});
