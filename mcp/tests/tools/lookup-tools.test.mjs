// ADR-0052 decision 1's reads: the master-data lists the catalog tools take ids from, and
// lookup_barcode's Victual-then-external fallback. Mocked client, as in handlers.test.mjs.
import { test } from "node:test";
import assert from "node:assert/strict";
import { listLocations } from "../../dist/tools/list-locations.js";
import { listQuantityUnits } from "../../dist/tools/list-quantity-units.js";
import { listProductGroups } from "../../dist/tools/list-product-groups.js";
import { lookupBarcode } from "../../dist/tools/lookup-barcode.js";
import { ALL_TOOLS } from "../../dist/tools/index.js";
import { ALL_READ_TOOLS } from "../../dist/config.js";
import { VictualApiError } from "../../dist/victual/client.js";

function ctx(routes) {
  const calls = [];
  return {
    calls,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path, credential, params) {
        calls.push({ path, params: params?.toString() ?? "" });
        if (!(path in routes)) throw new VictualApiError("not_found", `no fixture for ${path}`, 404);
        const value = routes[path];
        if (value instanceof VictualApiError) throw value;
        return value;
      },
    },
  };
}

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);
const unknownBarcode = new VictualApiError("invalid_request", "Victual answered 400: Unknown barcode", 400);

test("list_locations: active ones by name, nesting and freezer flag shaped, string ids coerced", async () => {
  const c = ctx({
    "/api/objects/locations": [
      { id: "1", name: "Pantry", parent_location_id: null, is_freezer: 0 },
      { id: 2, name: "Pantry top shelf", parent_location_id: "1", is_freezer: "0" },
      { id: 3, name: "Chest freezer", is_freezer: 1 },
    ],
  });
  const { data, text } = await run(listLocations, {}, c);
  assert.equal(c.calls[0].params, "query%5B%5D=active%3D1&order=name%3Aasc");
  assert.deepEqual(data.rows, [
    { location_id: 1, name: "Pantry", parent_location_id: null, is_freezer: false },
    { location_id: 2, name: "Pantry top shelf", parent_location_id: 1, is_freezer: false },
    { location_id: 3, name: "Chest freezer", parent_location_id: null, is_freezer: true },
  ]);
  assert.match(text, /3 locations/);
});

test("list_quantity_units and list_product_groups: ids and names only", async () => {
  const c = ctx({
    "/api/objects/quantity_units": [{ id: 1, name: "Can", name_plural: "Cans", description: "x" }, { id: 2, name: "Gram", name_plural: "" }],
    "/api/objects/product_groups": [{ id: 4, name: "Canned goods", description: "shelf stable" }],
  });
  assert.deepEqual((await run(listQuantityUnits, {}, c)).data.rows, [
    { unit_id: 1, name: "Can", name_plural: "Cans" },
    { unit_id: 2, name: "Gram", name_plural: null },
  ]);
  assert.deepEqual((await run(listProductGroups, {}, c)).data.rows, [{ product_group_id: 4, name: "Canned goods" }]);
});

test("lookup_barcode: a known barcode returns the product, never a price, and skips the external lookup", async () => {
  const c = ctx({
    "/api/stock/products/by-barcode/4006381333931": {
      product: { id: 7, name: "Black beans", qu_id_stock: 1 },
      quantity_unit_stock: { name: "Can" },
      location: { name: "Pantry" },
      stock_amount: "3",
      last_price: 1.29,
      avg_price: 1.19,
      current_price: 1.29,
    },
  });
  const { data, text } = await run(lookupBarcode, { barcode: " 4006381333931 " }, c);
  assert.deepEqual(data, {
    found: "victual",
    product: { product_id: 7, name: "Black beans", unit: "Can", location: "Pantry", in_stock_amount: 3 },
    suggestion: null,
  });
  assert.equal(c.calls.length, 1);
  assert.doesNotMatch(JSON.stringify(data), /price/);
  assert.match(text, /Black beans \(product 7\)/);
});

test("lookup_barcode: an unknown barcode asks the external lookup without add, and offers its name", async () => {
  const c = ctx({
    "/api/stock/products/by-barcode/123": unknownBarcode,
    "/api/stock/barcodes/external-lookup/123": { name: "Oat milk 1L", location_id: 1, qu_id_stock: 2 },
  });
  const { data } = await run(lookupBarcode, { barcode: "123" }, c);
  assert.deepEqual(data, { found: "external", product: null, suggestion: { name: "Oat milk 1L" } });
  assert.equal(c.calls[1].params, "", "never ?add=true: lookup_barcode creates nothing");
});

test("lookup_barcode: no match anywhere, a null external answer and a plugin error are all found: none", async () => {
  for (const externalAnswer of [null, new VictualApiError("invalid_request", "Plugin error", 400)]) {
    const c = ctx({ "/api/stock/products/by-barcode/999": unknownBarcode, "/api/stock/barcodes/external-lookup/999": externalAnswer });
    assert.equal((await run(lookupBarcode, { barcode: "999" }, c)).data.found, "none");
  }
});

test("lookup_barcode: a barcode is path-encoded, and a refusal other than 400 stays an error", async () => {
  const c = ctx({ "/api/stock/products/by-barcode/a%2Fb%3F": new VictualApiError("forbidden", "Victual answered 403", 403) });
  await assert.rejects(run(lookupBarcode, { barcode: "a/b?" }, c), (e) => e.category === "forbidden");
  assert.equal(lookupBarcode.inputSchema.safeParse({ barcode: "   " }).success, false);
});

test("the new reads join all-read, need STOCK_VIEW, and are read-only", () => {
  for (const tool of [listLocations, listQuantityUnits, listProductGroups, lookupBarcode]) {
    assert.ok(ALL_READ_TOOLS.includes(tool.name), tool.name);
    assert.ok(ALL_TOOLS.includes(tool), tool.name);
    assert.equal(tool.permission, "STOCK_VIEW");
    assert.notEqual(tool.write, true);
  }
});
