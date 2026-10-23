// ADR-0052 decision 1 as amended 2026-10-23: set_due_date, which changes only the due date of
// stock already on hand, one PUT /api/stock/entry/{id} transaction per entry. Mocked client.
import { test } from "node:test";
import assert from "node:assert/strict";
import { setDueDate } from "../../dist/tools/set-due-date.js";
import { undoTransaction } from "../../dist/tools/undo-transaction.js";
import { ALL_TOOLS } from "../../dist/tools/index.js";
import { ALL_READ_TOOLS, WRITE_TOOLS } from "../../dist/config.js";
import { VictualApiError } from "../../dist/victual/client.js";

const run = (tool, input, context) => tool.handler(tool.inputSchema.parse(input), context);

const entries = [
  { id: 11, product_id: 7, amount: 3, best_before_date: "2026-10-23", location_id: 1, price: 1.29 },
  { id: "12", product_id: 7, amount: "0.5", best_before_date: "2026-10-23", location_id: "2", price: 1.29 },
  { id: 13, product_id: 7, amount: 1, best_before_date: "2027-03-01", location_id: 1, price: null },
  { id: 14, product_id: 7, amount: 2, best_before_date: "2999-12-31", location_id: 1, price: null },
];

// Each PUT answers one stock_log row with a fresh transaction id, or what `fail(id)` throws.
function ctx(rows = entries, fail = () => undefined) {
  const gets = [];
  const puts = [];
  let next = 1;
  return {
    gets,
    puts,
    credential: { headers: { "VICTUAL-API-KEY": "k" } },
    client: {
      async get(path) {
        gets.push(path);
        return rows;
      },
      async put(path, credential, body) {
        const id = Number(path.split("/").pop());
        fail(id);
        puts.push({ path, body });
        return [{ id: 100 + next, transaction_id: `t${next++}`, product_id: 7, amount: body.amount }];
      },
    },
  };
}

test("set_due_date: the counted-today entries get the date, the body is their own amount, other entries are left alone", async () => {
  const c = ctx();
  const { data, text } = await run(setDueDate, { product_id: 7, due_date: "2027-04-30", current_due_date: "2026-10-23" }, c);
  assert.deepEqual(c.gets, ["/api/stock/products/7/entries"]);
  assert.deepEqual(c.puts, [
    { path: "/api/stock/entry/11", body: { amount: 3, best_before_date: "2027-04-30" } },
    { path: "/api/stock/entry/12", body: { amount: 0.5, best_before_date: "2027-04-30" } },
  ]);
  assert.deepEqual(data, {
    product_id: 7,
    due_date: "2027-04-30",
    changed: [
      { entry_id: 11, amount: 3, transaction_id: "t1" },
      { entry_id: 12, amount: 0.5, transaction_id: "t2" },
    ],
    unchanged: 0,
  });
  assert.match(text, /^Set 2 stock entries of product 7 to be due 2027-04-30\. Each change is its own transaction\. This can be undone/);
});

test("set_due_date: null means never expires, sent as Victual's sentinel; an entry that already has it is skipped", async () => {
  const c = ctx();
  const { data, text } = await run(setDueDate, { product_id: 7, due_date: null, location_id: 1 }, c);
  assert.deepEqual(c.puts.map((p) => [p.path, p.body.best_before_date]), [
    ["/api/stock/entry/11", "2999-12-31"],
    ["/api/stock/entry/13", "2999-12-31"],
  ]);
  assert.equal(data.unchanged, 1);
  assert.match(text, /^Set 2 stock entries of product 7 to never expire; 1 already had that date\./);
});

test("set_due_date: current_due_date null selects the never-expiring stock", async () => {
  const c = ctx();
  await run(setDueDate, { product_id: 7, due_date: "2027-01-01", current_due_date: null }, c);
  assert.deepEqual(c.puts.map((p) => p.path), ["/api/stock/entry/14"]);
});

test("set_due_date: repeating the call changes nothing, and no match says so", async () => {
  const c = ctx(entries.map((e) => ({ ...e, best_before_date: "2027-04-30" })));
  const { data, text } = await run(setDueDate, { product_id: 7, due_date: "2027-04-30" }, c);
  assert.equal(c.puts.length, 0);
  assert.deepEqual(data.changed, []);
  assert.equal(data.unchanged, 4);
  assert.match(text, /^Set 0 stock entries .*; 4 already had that date\.$/);
  assert.doesNotMatch(text, /undone/);
  const none = await run(setDueDate, { product_id: 7, due_date: "2027-04-30", location_id: 9 }, ctx());
  assert.match(none.text, /^No stock entry of product 7 matched; nothing changed\.$/);
});

test("set_due_date: a failure after some entries changed names those entries and their transactions", async () => {
  const c = ctx(entries, (id) => {
    if (id === 12) throw new VictualApiError("invalid_request", "Victual answered 400: Stock does not exist", 400);
  });
  await assert.rejects(
    run(setDueDate, { product_id: 7, due_date: "2027-04-30", current_due_date: "2026-10-23" }, c),
    (e) => e.category === "invalid_request" && e.victualStatus === 400 && /already changed before entry 12: entry 11 \(transaction t1\)/.test(e.message),
  );
  // The first failure, with nothing changed yet, is Victual's error unchanged.
  const first = ctx(entries, () => { throw new VictualApiError("forbidden", "Victual answered 403", 403); });
  await assert.rejects(run(setDueDate, { product_id: 7, due_date: "2027-04-30" }, first), (e) => e.message === "Victual answered 403");
});

test("set_due_date: a PUT answered without a transaction row is outcome_unknown", async () => {
  const c = ctx();
  c.client.put = async () => [];
  await assert.rejects(run(setDueDate, { product_id: 7, due_date: "2027-04-30" }, c), (e) => e.category === "outcome_unknown");
});

test("set_due_date never returns a price, refuses a malformed date, and is a STOCK_EDIT write in stock-write", async () => {
  const { data } = await run(setDueDate, { product_id: 7, due_date: "2027-04-30" }, ctx());
  assert.doesNotMatch(JSON.stringify(data), /price/);
  for (const bad of [{ product_id: 7 }, { product_id: 7, due_date: "30/04/2027" }, { product_id: 0, due_date: null }, { product_id: 7, due_date: null, current_due_date: "soon" }]) {
    assert.equal(setDueDate.inputSchema.safeParse(bad).success, false, JSON.stringify(bad));
  }
  assert.equal(setDueDate.permission, "STOCK_EDIT");
  assert.deepEqual(setDueDate.alsoRequires, ["STOCK_VIEW"]);
  assert.equal(setDueDate.write, true);
  assert.equal(setDueDate.destructive, true);
  assert.ok(WRITE_TOOLS.includes("set_due_date"));
  assert.equal(ALL_READ_TOOLS.includes("set_due_date"), false);
  assert.ok(ALL_TOOLS.includes(setDueDate));
  assert.match(undoTransaction.description, /due-date change/);
});
