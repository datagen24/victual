// ADR-0052 decision 1 as amended 2026-10-23: recent_transactions, the stock journal read that
// lets a later conversation find the transaction_id undo_transaction takes. Mocked client.
import { test } from "node:test";
import assert from "node:assert/strict";
import { recentTransactions } from "../../dist/tools/recent-transactions.js";
import { undoTransaction } from "../../dist/tools/undo-transaction.js";
import { ALL_TOOLS } from "../../dist/tools/index.js";
import { ALL_READ_TOOLS, WRITE_TOOLS } from "../../dist/config.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

function ctx(journal) {
  const calls = [];
  return {
    calls,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path, credential, params) {
        calls.push({ path, params: params?.toString() ?? "" });
        if (path === "/api/objects/stock_log") return journal;
        if (path === "/api/objects/products/7") return { id: 7, name: "Black beans", qu_id_stock: 2, product_group_id: null };
        if (path === "/api/objects/quantity_units") return [{ id: 2, name: "Can" }];
        throw new Error(`unexpected ${path}`);
      },
    },
  };
}

const journal = [
  // A transfer: two rows, one transaction.
  { id: 31, transaction_id: "x7", transaction_type: "transfer_to", product_id: 7, amount: 2, location_id: 4, best_before_date: "2027-01-01", undone: 0, price: 1.29, row_created_timestamp: "2026-10-23T14:02:11Z" },
  { id: 30, transaction_id: "x7", transaction_type: "transfer_from", product_id: "7", amount: "-2", location_id: "1", best_before_date: "2027-01-01", undone: "0", price: 1.29, row_created_timestamp: "2026-10-23T14:02:11Z" },
  { id: 29, transaction_id: "x6", transaction_type: "inventory-correction", product_id: 7, amount: 3, location_id: 1, best_before_date: "2999-12-31", undone: 1, price: null, row_created_timestamp: "2026-10-23T13:59:00Z" },
  // Predates the transaction_id column: nothing to undo it by.
  { id: 2, transaction_id: null, transaction_type: "purchase", product_id: 7, amount: 1, location_id: 1, best_before_date: null, undone: 0, price: 0.99 },
];

test("recent_transactions: one product's journal, newest first by id, limited server-side", async () => {
  const c = ctx(journal);
  const { data, text } = await run(recentTransactions, { product_id: 7 }, c);
  assert.equal(c.calls[0].path, "/api/objects/stock_log");
  assert.equal(c.calls[0].params, "query%5B%5D=product_id%3D7&order=id%3Adesc&limit=20");
  assert.deepEqual(data, {
    product_id: 7,
    name: "Black beans",
    unit: "Can",
    rows: [
      { transaction_id: "x7", type: "transfer_to", amount: 2, location_id: 4, due_date: "2027-01-01", undone: false, timestamp: "2026-10-23T14:02:11Z" },
      { transaction_id: "x7", type: "transfer_from", amount: -2, location_id: 1, due_date: "2027-01-01", undone: false, timestamp: "2026-10-23T14:02:11Z" },
      { transaction_id: "x6", type: "inventory-correction", amount: 3, location_id: 1, due_date: null, undone: true, timestamp: "2026-10-23T13:59:00Z" },
    ],
  });
  assert.equal(text, '3 journal rows for "Black beans", newest first, from 2 transactions.');
});

test("recent_transactions never returns a price, though stock_log carries one", async () => {
  const { data } = await run(recentTransactions, { product_id: 7 }, ctx(journal));
  assert.doesNotMatch(JSON.stringify(data), /price/);
  assert.equal("price" in recentTransactions.outputSchema.shape.rows.element.shape, false);
});

test("recent_transactions: include_undone false filters server-side, and the limit is bounded", async () => {
  const c = ctx([]);
  const { text } = await run(recentTransactions, { product_id: 7, include_undone: false, limit: 5 }, c);
  assert.equal(c.calls[0].params, "query%5B%5D=product_id%3D7&query%5B%5D=undone%3D0&order=id%3Adesc&limit=5");
  assert.match(text, /^0 journal rows/);
  for (const bad of [{}, { product_id: 0 }, { product_id: 7, limit: 101 }]) {
    assert.equal(recentTransactions.inputSchema.safeParse(bad).success, false, JSON.stringify(bad));
  }
});

test("recent_transactions is a STOCK_VIEW read in all-read, and undo_transaction points to it", () => {
  assert.equal(recentTransactions.permission, "STOCK_VIEW");
  assert.equal(recentTransactions.write, undefined);
  assert.ok(ALL_READ_TOOLS.includes("recent_transactions"));
  assert.equal(WRITE_TOOLS.includes("recent_transactions"), false);
  assert.ok(ALL_TOOLS.includes(recentTransactions));
  assert.match(undoTransaction.description, /recent_transactions/);
});
