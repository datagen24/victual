<?php
// SPIKE ONLY. Feasibility probe for ADR-0036 in a disposable, fully migrated PostgreSQL schema.
//   1. builds legacy states with the real StockService (old-style merges that rewrite stock_id)
//   2. applies .devtools/adr0036/proposed.sql and runs the backfill classification over them
//   3. runs the worked examples through the reference model (ref-model.php)
//   4. concurrency, interruption, and cost measurements
// Output: JSON on stdout. Nothing under services/, migrations/ or controllers/ is touched.
require '/app/tests/bootstrap.php';
require __DIR__ . '/ref-model.php';
use Victual\Services\StockService as S;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class Adr36Model extends PgsqlSchemaTestCase
{
    private static PDO $db;
    private static int $loc;
    private static int $loc2;
    private static int $serial = 0;
    private static array $out = [];

    private static function product(): int
    {
        $n = ++self::$serial;
        return (int)self::$db->query("INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES ('m$n'," . self::$loc . ",2,2,2,2) RETURNING id")->fetchColumn();
    }
    private static function ref(): Ref { return new Ref(self::$db, self::product(), self::$loc); }
    private static function snap(Ref $r, string $label, array &$steps): void
    {
        $steps[] = ['state' => $label, 'view' => Ref::render($r->view()), 'invariants_violated' => $r->invariants()];
    }
    private static function attempt(Ref $r, string $label, callable $fn, array &$steps): void
    {
        $before = json_encode($r->view());
        try { $fn(); $steps[] = ['step' => $label, 'result' => 'accepted']; }
        catch (Refusal $e) {
            $same = json_encode($r->view()) === $before;
            $steps[] = ['step' => $label, 'result' => 'refused', 'message' => $e->getMessage(), 'state_unchanged' => $same];
        }
    }
    private static function scenario(string $name, callable $body): void
    {
        $steps = [];
        try { $body($steps); } catch (Throwable $e) { $steps[] = ['ERROR' => get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine()]; }
        self::$out['scenarios'][$name] = $steps;
    }
    private static function hash(): string
    {
        return md5(json_encode([
            self::$db->query('SELECT * FROM stock ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            self::$db->query('SELECT * FROM stock_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            self::$db->query('SELECT * FROM stock_entry_origins ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ]));
    }

    public static function executeProbe(): void
    {
        parent::setUpBeforeClass();
        try {
            self::$db = self::Pdo();
            self::$db->exec("INSERT INTO users(id,username,password) VALUES(9000,'adr36','fixture')");
            self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9000,id FROM permission_hierarchy WHERE name='ADMIN'");
            self::$loc = (int)self::$db->query("INSERT INTO locations(name) VALUES('L1') RETURNING id")->fetchColumn();
            self::$loc2 = (int)self::$db->query("INSERT INTO locations(name) VALUES('L2') RETURNING id")->fetchColumn();
            self::$out['environment'] = ['php' => PHP_VERSION, 'postgres' => self::$db->query('SHOW server_version')->fetchColumn(), 'schema' => self::Schema()];

            self::legacyAndBackfill();
            self::applyDdlConstraintChecks();
            self::workedExamples();
            self::concurrency();
            self::cost();

            echo json_encode(self::$out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
        } finally {
            parent::tearDownAfterClass();
        }
    }

    // ------------------------------------------------------------------------------------
    private static function legacyAndBackfill(): void
    {
        $stock = S::GetInstance();
        $mk = function (string $name) { $p = self::product(); self::$db->exec("UPDATE products SET name='$name' WHERE id=$p"); return $p; };
        $add = fn(int $p, float $q) => $stock->AddProduct($p, $q, '2999-12-31', S::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$loc);
        $fixtures = [];
        $p = $mk('L1 single purchase'); $add($p, 5); $fixtures['L1'] = $p;
        $p = $mk('L2 merged 3+2, nothing else'); $add($p, 3); usleep(1500); $add($p, 2); $stock->CompactStockEntries($p); $fixtures['L2'] = $p;
        $p = $mk('L3 merged 3+2, then consume 1'); $add($p, 3); usleep(1500); $add($p, 2); $stock->CompactStockEntries($p);
        $stock->ConsumeProduct($p, 1, false, S::TRANSACTION_TYPE_CONSUME); $fixtures['L3'] = $p;
        $p = $mk('L4 purchase 5, open 2 (split with recorded origin)'); $add($p, 5); $stock->OpenProduct($p, 2); $fixtures['L4'] = $p;
        $p = $mk('L5 as L4 but the origin row is absent (a pre-0267 split)'); $add($p, 5); $stock->OpenProduct($p, 2);
        self::$db->exec("DELETE FROM stock_entry_origins WHERE stock_id IN (SELECT stock_id FROM stock WHERE product_id=$p AND open=0)"); $fixtures['L5'] = $p;
        $p = $mk('L6 purchase 5, transfer 2 (split, shared stock_id)'); $add($p, 5); $stock->TransferProduct($p, 2, self::$loc, self::$loc2); $fixtures['L6'] = $p;
        $p = $mk('L7 merged 3+2, then an amount edit'); $add($p, 3); usleep(1500); $add($p, 2); $stock->CompactStockEntries($p);
        $row = self::$db->query("SELECT * FROM stock WHERE product_id=$p")->fetch(PDO::FETCH_ASSOC);
        $stock->EditStockEntry((int)$row['id'], 4.5, $row['best_before_date'], (int)$row['location_id'], null, (float)$row['price'], (int)$row['open'], $row['purchased_date']); $fixtures['L7'] = $p;
        $p = $mk('L8 purchase 3, fully consumed'); $add($p, 3); $stock->ConsumeProduct($p, 3, false, S::TRANSACTION_TYPE_CONSUME); $fixtures['L8'] = $p;
        $p = $mk('L9 purchase 3 undone'); $tx = $add($p, 3); $stock->UndoTransaction($tx); $fixtures['L9'] = $p;

        $before = ['hash' => self::hash(), 'totals' => self::$db->query('SELECT product_id, sum(amount)::float8 AS total FROM stock GROUP BY product_id ORDER BY product_id')->fetchAll(PDO::FETCH_KEY_PAIR)];
        $sql = file_get_contents(__DIR__ . '/proposed.sql');
        self::$db->exec($sql);
        $t0 = microtime(true);
        $res = self::$db->query('SELECT * FROM spike_backfill()')->fetchAll(PDO::FETCH_ASSOC);
        $ms = round((microtime(true) - $t0) * 1000, 1);
        $count1 = [(int)self::$db->query('SELECT count(*) FROM stock_row_lots')->fetchColumn(), (int)self::$db->query('SELECT count(*) FROM stock_booking_lots')->fetchColumn()];
        self::$db->query('SELECT * FROM spike_backfill()')->fetchAll();
        $count2 = [(int)self::$db->query('SELECT count(*) FROM stock_row_lots')->fetchColumn(), (int)self::$db->query('SELECT count(*) FROM stock_booking_lots')->fetchColumn()];
        $after = ['hash' => self::hash(), 'totals' => self::$db->query('SELECT product_id, sum(amount)::float8 AS total FROM stock GROUP BY product_id ORDER BY product_id')->fetchAll(PDO::FETCH_KEY_PAIR)];

        $detail = [];
        foreach ($fixtures as $k => $pid) {
            $r = new Ref(self::$db, $pid, self::$loc);
            $detail[$k] = ['name' => self::$db->query("SELECT name FROM products WHERE id=$pid")->fetchColumn(),
                'view' => Ref::render($r->view()), 'invariant_violations' => $r->invariants()];
        }
        self::$out['backfill'] = [
            'classes' => $res, 'milliseconds' => $ms,
            'rows_after_first_and_second_run' => ['first' => $count1, 'second' => $count2, 'idempotent' => $count1 === $count2],
            'stock_stock_log_origins_byte_identical_before_and_after' => $before['hash'] === $after['hash'],
            'per_product_totals_unchanged' => $before['totals'] === $after['totals'],
            'fixtures' => $detail,
        ];
        self::$out['_fixtures'] = $fixtures;
    }

    // ------------------------------------------------------------------------------------
    private static function applyDdlConstraintChecks(): void
    {
        $checks = [];
        $try = function (string $label, string $sql) use (&$checks) {
            self::$db->exec('SAVEPOINT s');
            try { self::$db->exec($sql); $checks[] = [$label, 'accepted']; self::$db->exec('ROLLBACK TO s'); }
            catch (PDOException $e) { $checks[] = [$label, 'rejected: ' . substr($e->getMessage(), 0, 160)]; self::$db->exec('ROLLBACK TO s'); }
        };
        self::$db->beginTransaction();
        $p = self::product();
        $rid = (int)self::$db->query("INSERT INTO stock(product_id,amount,stock_id,location_id) VALUES ($p,1,'chk'," . self::$loc . ") RETURNING id")->fetchColumn();
        $lb = (int)self::$db->query("INSERT INTO stock_log(product_id,amount,stock_id,transaction_type,user_id) VALUES ($p,1,'chk','purchase',9000) RETURNING id")->fetchColumn();
        $try('two pool contributions for one row (NULLS NOT DISTINCT)', "INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid,NULL,1,'unknown'),($rid,NULL,1,'unknown')");
        $try('pool with a recorded basis', "INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid,NULL,1,'recorded')");
        $try('contribution <= 0', "INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid,$lb,0,'recorded')");
        $try('contribution naming a missing row', "INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES (999999,$lb,1,'recorded')");
        $try('contribution naming a missing lot', "INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid,999999,1,'recorded')");
        $try('allocation of zero', "INSERT INTO stock_booking_lots(booking_id,lot_id,amount,basis) VALUES ($lb,$lb,0,'recorded')");
        $try('allocation naming a missing booking', "INSERT INTO stock_booking_lots(booking_id,lot_id,amount,basis) VALUES (999999,$lb,1,'recorded')");
        self::$db->exec("INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid,$lb,1,'recorded')");
        self::$db->exec("INSERT INTO stock_booking_lots(booking_id,lot_id,amount,basis) VALUES ($lb,$lb,1,'recorded')");
        self::$db->exec("DELETE FROM stock WHERE id=$rid");
        $checks[] = ['deleting the row removes its contributions (ON DELETE CASCADE)', (int)self::$db->query("SELECT count(*) FROM stock_row_lots WHERE stock_row_id=$rid")->fetchColumn() === 0 ? 'cascaded' : 'LEFT BEHIND'];
        $rid2 = (int)self::$db->query("INSERT INTO stock(product_id,amount,stock_id,location_id) VALUES ($p,1,'chk2'," . self::$loc . ") RETURNING id")->fetchColumn();
        self::$db->exec("INSERT INTO stock_row_lots(stock_row_id,lot_id,amount,basis) VALUES ($rid2,$lb,1,'recorded')");
        self::$db->exec("DELETE FROM stock_log WHERE id=$lb");
        $checks[] = ['deleting a lot booking removes the contributions and allocations that name it (ON DELETE CASCADE)',
            ((int)self::$db->query("SELECT count(*) FROM stock_row_lots WHERE lot_id=$lb")->fetchColumn() + (int)self::$db->query("SELECT count(*) FROM stock_booking_lots WHERE lot_id=$lb OR booking_id=$lb")->fetchColumn()) === 0 ? 'cascaded' : 'LEFT BEHIND'];
        self::$db->rollBack();
        self::$out['ddl_constraint_checks'] = $checks;
    }

    // ------------------------------------------------------------------------------------
    private static function workedExamples(): void
    {
        foreach (['3-then-2' => [3, 2], '2-then-3' => [2, 3]] as $order => [$x, $y]) {
            self::scenario("E1_merge_$order", function (&$s) use ($x, $y) {
                $r = self::ref(); $a = $r->purchase($x, [], 'A'); $b = $r->purchase($y, [], 'B');
                self::snap($r, 'two purchases', $s);
                $ledger = fn() => self::$db->query("SELECT * FROM stock_log WHERE product_id={$r->p} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                $ledgerBefore = $ledger();
                $r->merge();
                self::snap($r, 'after the maintenance merge', $s);
                $s[] = ['stock_log_unchanged_by_merge' => $ledger() === $ledgerBefore];
            });
            foreach ([2, 4] as $c) {
                self::scenario("E2_merge_{$order}_consume_$c", function (&$s) use ($x, $y, $c) {
                    $r = self::ref(); $a = $r->purchase($x, [], 'A'); $b = $r->purchase($y, [], 'B'); $r->merge();
                    $tx = $r->consume($c); $cb = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE product_id={$r->p}")->fetchColumn();
                    self::snap($r, "merged $x+$y then consume $c", $s);
                    self::attempt($r, "undo purchase $x (A, booking {$a['booking']})", fn() => $r->undo($a['booking']), $s);
                    self::attempt($r, "undo purchase $y (B, booking {$b['booking']})", fn() => $r->undo($b['booking']), $s);
                    self::snap($r, 'after those two attempts', $s);
                    self::attempt($r, "undo the consume (booking $cb)", fn() => $r->undo($cb), $s);
                    self::attempt($r, "undo purchase $y again", fn() => $r->undo($b['booking']), $s);
                    self::attempt($r, "undo purchase $x again", fn() => $r->undo($a['booking']), $s);
                    self::snap($r, 'end', $s);
                });
            }
        }
        self::scenario('E3_merge_transfer_merge_reversal', function (&$s) {
            $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B'); $r->merge();
            $tx = $r->transfer(2, self::$loc2);
            $from = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx' AND transaction_type='transfer_to'")->fetchColumn();
            self::snap($r, 'merged 3+2, then transfer 2 to L2 (a split: both rows share one stock_id)', $s);
            $c = $r->purchase(4, [], 'C');
            $merged = $r->merge();
            $s[] = ['step' => 'second maintenance run', 'groups_merged' => $merged];
            self::snap($r, 'after purchase C=4 and a second merge at L1 (the L2 row shares a stock_id with a row in the group; the baseline refuses this group)', $s);
            self::attempt($r, 'undo the transfer', fn() => $r->undo($from), $s);
            self::snap($r, 'after undoing the transfer', $s);
            $r->merge();
            self::snap($r, 'third maintenance run', $s);
            self::attempt($r, 'undo purchase A (3)', fn() => $r->undo($a['booking']), $s);
            self::snap($r, 'end', $s);
        });
        self::scenario('E4a_open_whole_rows_merge_undo', function (&$s) {
            $r = self::ref(); $a = $r->purchase(2, [], 'A'); $b = $r->purchase(3, [], 'B');
            $o1 = $r->open(2); $o2 = $r->open(3);
            $ob1 = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$o1'")->fetchColumn();
            $ob2 = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$o2'")->fetchColumn();
            self::snap($r, 'purchase A=2, B=3, open 2, open 3', $s);
            $r->merge();
            self::snap($r, 'both opened rows merged', $s);
            self::attempt($r, "undo the second opening (booking $ob2)", fn() => $r->undo($ob2), $s);
            self::snap($r, 'after', $s);
            self::attempt($r, "undo the first opening (booking $ob1)", fn() => $r->undo($ob1), $s);
            self::snap($r, 'end', $s);
        });
        self::scenario('E4b_partial_open_purchase_merge_undo', function (&$s) {
            $r = self::ref(); $a = $r->purchase(2, [], 'A'); $b = $r->purchase(3, [], 'B'); $r->merge();
            $o = $r->open(2); $ob = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$o'")->fetchColumn();
            $c = $r->purchase(4, [], 'C');
            self::snap($r, 'merged 2+3, open 2 (split), purchase C=4', $s);
            $r->merge();
            self::snap($r, 'second merge: the unopened remainder (B:3) and C:4 now merge, lineage by contribution', $s);
            self::attempt($r, "undo the opening (booking $ob)", fn() => $r->undo($ob), $s);
            self::snap($r, 'end', $s);
        });
        self::scenario('E5a_edit_price_merge_undo_edit', function (&$s) {
            $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, ['price' => 1.2], 'B');
            $rowB = (int)self::$db->query("SELECT id FROM stock WHERE product_id={$r->p} AND price=1.2")->fetchColumn();
            $e = $r->edit($rowB, 2, ['price' => 1.0]);
            self::snap($r, 'purchase A=3 at 1.0, B=2 at 1.2, then edit B price to 1.0', $s);
            $r->merge();
            self::snap($r, 'merged', $s);
            self::attempt($r, "undo the edit (booking {$e['new']})", fn() => $r->undo($e['new']), $s);
            self::snap($r, 'end: B keeps its original price, A is untouched', $s);
        });
        self::scenario('E5b_edit_amount_merge_undo_edit', function (&$s) {
            $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B');
            $rowB = (int)self::$db->query("SELECT id FROM stock WHERE product_id={$r->p} AND amount=2")->fetchColumn();
            $e = $r->edit($rowB, 2.5, [], 'E');
            $r->merge();
            self::snap($r, 'edit B 2 -> 2.5 (new lot E=0.5), merge', $s);
            self::attempt($r, "undo the edit (booking {$e['new']})", fn() => $r->undo($e['new']), $s);
            self::snap($r, 'end', $s);
        });
        self::scenario('E6_full_consume_undo', function (&$s) {
            $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B'); $r->merge();
            $row = (int)self::$db->query("SELECT id FROM stock WHERE product_id={$r->p}")->fetchColumn();
            $tx = $r->consume(5); $cb = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE product_id={$r->p}")->fetchColumn();
            self::snap($r, 'merged 3+2, then consume 5 (the row is deleted)', $s);
            self::attempt($r, "undo the consume (booking $cb)", fn() => $r->undo($cb), $s);
            self::snap($r, "end: the row returns under id $row with both contributions", $s);
            $s[] = ['row_id_preserved' => (int)self::$db->query("SELECT count(*) FROM stock WHERE id=$row AND product_id={$r->p}")->fetchColumn() === 1];
        });
        self::scenario('E7_legacy_ambiguous_group', function (&$s) {
            $ids = self::$out['_fixtures']; $r = new Ref(self::$db, $ids['L3'], self::$loc);
            $adds = array_column(self::$db->query("SELECT id FROM stock_log WHERE product_id={$r->p} AND transaction_type='purchase' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'id');
            self::snap($r, 'legacy: merged 3+2 then consume 1 (classified U: pool, basis unknown)', $s);
            self::attempt($r, "undo purchase booking {$adds[0]}", fn() => $r->undo((int)$adds[0]), $s);
            self::attempt($r, "undo purchase booking {$adds[1]}", fn() => $r->undo((int)$adds[1]), $s);
            $tx = $r->consume(1); $cb = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE product_id={$r->p}")->fetchColumn();
            self::snap($r, 'a new consume of 1 draws from the pool and records the draw', $s);
            self::attempt($r, "undo that new consume (booking $cb) - exact, back into the pool", fn() => $r->undo($cb), $s);
            $c = $r->purchase(4, [], 'C');
            $r->merge();
            self::snap($r, 'a new purchase C=4 merges with the legacy row; the pool stays a pool', $s);
            self::attempt($r, "undo C (booking {$c['booking']}) - exact", fn() => $r->undo($c['booking']), $s);
            self::snap($r, 'end', $s);
        });
        self::scenario('E7b_legacy_exact_group_X', function (&$s) {
            $ids = self::$out['_fixtures']; $r = new Ref(self::$db, $ids['L2'], self::$loc);
            $adds = array_column(self::$db->query("SELECT id FROM stock_log WHERE product_id={$r->p} AND transaction_type='purchase' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'id');
            self::snap($r, 'legacy: merged 3+2, nothing else (class X: derived by ledger arithmetic)', $s);
            self::attempt($r, "undo the older purchase (booking {$adds[0]})", fn() => $r->undo((int)$adds[0]), $s);
            self::snap($r, 'end: exactly the 3 left the row', $s);
        });
        self::scenario('E8_interrupted_maintenance_and_repeat', function (&$s) {
            $r = self::ref(); $r->purchase(3, [], 'A'); $r->purchase(2, [], 'B'); $r->purchase(1, ['price' => 2.0], 'D'); $r->purchase(1, ['price' => 2.0], 'E');
            $h0 = self::hash();
            $failed = false;
            try { $r->merge(true); } catch (RuntimeException $e) { $failed = true; }
            $s[] = ['step' => 'maintenance interrupted after its first group', 'raised' => $failed, 'state_byte_identical_after_rollback' => self::hash() === $h0];
            $n1 = $r->merge(); $h1 = self::hash();
            $n2 = $r->merge();
            $s[] = ['step' => 'repeat run', 'first_complete_run_merged_groups' => $n1, 'second_run_merged_groups' => $n2, 'second_run_changed_nothing' => self::hash() === $h1];
            self::snap($r, 'end', $s);
        });
        self::scenario('E10_average_price_unchanged_by_attribution', function (&$s) {
            $avg = fn(int $p) => self::$db->query("SELECT price FROM products_average_price WHERE product_id=$p")->fetchColumn();
            $run = function (bool $merged, float $to) use ($avg) {
                $r = self::ref(); $r->purchase(3, ['price' => 2.0], 'A'); $b = $r->purchase(2, ['price' => 2.0], 'B'); $r->purchase(1, ['price' => 3.0], 'C');
                if ($merged) { $r->merge(); }
                // edit one 2.0-priced row so that the 2.0-priced units total $to
                $row = $merged ? $b['row'] : $b['row'];
                $left = $to;
                if ($merged) { $r->edit($b['row'], $to); }
                else { // the control cannot edit two rows as one: reduce A first, then B
                    $rows = self::$db->query("SELECT id, amount FROM stock WHERE product_id={$r->p} AND price=2.0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
                    $left = $to; foreach ($rows as $row) { $new = min((float)$row['amount'], $left); $r->edit((int)$row['id'], $new); $left -= $new; }
                }
                return [round((float)$avg($r->p), 9), $r->invariants()];
            };
            foreach ([4.0, 2.0, 1.0] as $to) { $s[] = ['2.0-priced units edited down to' => $to, 'merged' => $run(true, $to), 'control_unmerged' => $run(false, $to)]; }
        });
        self::scenario('E9_label_exclusion_and_merge_alias', function (&$s) {
            $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B'); $c = $r->purchase(1, [], 'C');
            self::$db->exec("INSERT INTO labels(uid, kind, target_id) VALUES ('0AAAAAAAAAAAA', 'stock_entry', {$b['row']})");
            $r->merge();
            self::snap($r, 'row of B carries a live label: it is not a merge candidate, A and C merge', $s);
            $s[] = ['label_still_live' => (int)self::$db->query("SELECT count(*) FROM labels WHERE uid='0AAAAAAAAAAAA' AND retired_at IS NULL")->fetchColumn() === 1,
                'alias_lookup_lot_A_row' => self::$db->query("SELECT stock_row_id FROM stock_row_lots WHERE lot_id={$a['booking']}")->fetchColumn()];
        });
    }

    // ------------------------------------------------------------------------------------
    private static function child(string $mode, array $args): array
    {
        $env = ['PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'),
            'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'ADR36_SCHEMA' => self::Schema(), 'PATH' => getenv('PATH')];
        $p = proc_open(['php', __DIR__ . '/conc-child.php', $mode, json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/app', $env);
        stream_set_blocking($pipes[1], false);
        return ['proc' => $p, 'pipe' => $pipes[1], 'err' => $pipes[2], 'start' => microtime(true)];
    }
    private static function finish(array $c): array
    {
        stream_set_blocking($c['pipe'], true);
        $out = stream_get_contents($c['pipe']); $err = stream_get_contents($c['err']); proc_close($c['proc']);
        return ['elapsed_s' => round(microtime(true) - $c['start'], 2), 'output' => json_decode($out, true) ?? trim($out . $err)];
    }

    private static function concurrency(): void
    {
        $res = [];
        // (a) a booking waits behind a running maintenance merge, then sees the merged row.
        $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B');
        self::$db->beginTransaction();
        $r->q('SELECT pg_advisory_xact_lock(?, ?)', [Ref::LOCK_CLASS, $r->p]);
        $r->q("SELECT id FROM stock WHERE product_id=? ORDER BY id FOR UPDATE", [$r->p])->fetchAll();
        $child = self::child('consume', ['product' => $r->p, 'amount' => 1]);
        usleep(1500000);
        $stillBlocked = proc_get_status($child['proc'])['running'];
        $r->merge2inTx();
        self::$db->commit();
        $done = self::finish($child);
        $res['a_booking_during_maintenance'] = ['child_still_blocked_after_1.5s' => $stillBlocked, 'child' => $done, 'invariants_violated' => $r->invariants(), 'view' => Ref::render($r->view())];

        // (b) label issuance (import lock, then row lock) against a merge that deletes the row.
        $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B');
        self::$db->beginTransaction();
        $r->q('SELECT pg_advisory_xact_lock(?, ?)', [Ref::LOCK_CLASS, $r->p]);
        $r->q("SELECT id FROM stock WHERE product_id=? ORDER BY id FOR UPDATE", [$r->p])->fetchAll();
        $child = self::child('issue', ['row' => $a['row']]);   // lower id: the row the merge deletes
        usleep(1500000);
        $stillBlocked = proc_get_status($child['proc'])['running'];
        $r->merge2inTx();
        self::$db->commit();
        $done = self::finish($child);
        $res['b_label_issuance_against_merge'] = ['child_still_blocked_after_1.5s' => $stillBlocked, 'child' => $done,
            'live_labels_for_deleted_row' => (int)self::$db->query("SELECT count(*) FROM labels WHERE kind='stock_entry' AND target_id={$a['row']}")->fetchColumn()];

        // (c) a label committed before the merge's eligibility recheck keeps its row out of the group.
        $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B');
        $child = self::child('issue', ['row' => $b['row']]); self::finish($child);
        $r->merge();
        $res['c_label_before_recheck'] = ['rows_after_merge' => count($r->view()['stock']), 'view' => Ref::render($r->view())];

        // (d) the other interleaving: an issuance in flight (import lock, then row lock) when maintenance asks
        // for its product lock and then the row locks. Maintenance waits for the row, then re-reads eligibility.
        $r = self::ref(); $a = $r->purchase(3, [], 'A'); $b = $r->purchase(2, [], 'B');
        $child = self::child('issue_hold', ['row' => $b['row'], 'hold_s' => 2.0]);
        usleep(1000000);
        self::$db->beginTransaction();
        $r->q('SELECT pg_advisory_xact_lock(?, ?)', [Ref::LOCK_CLASS, $r->p]);
        $t = microtime(true);
        $r->q("SELECT id FROM stock WHERE product_id=? ORDER BY id FOR UPDATE", [$r->p])->fetchAll();
        $waited = round(microtime(true) - $t, 2);
        $merged = $r->merge2inTx();
        self::$db->commit();
        $done = self::finish($child);
        $res['d_maintenance_waits_behind_issuance'] = ['maintenance_waited_for_row_locks_s' => $waited, 'groups_merged' => $merged, 'child' => $done,
            'rows_after' => count($r->view()['stock']), 'label_live' => (int)self::$db->query("SELECT count(*) FROM labels WHERE kind='stock_entry' AND target_id={$b['row']} AND retired_at IS NULL")->fetchColumn()];
        self::$out['concurrency'] = $res;
    }

    // ------------------------------------------------------------------------------------
    private static function cost(): void
    {
        $db = self::$db;
        $N = (int)(getenv('ADR36_N') ?: 50000);
        $db->exec('CREATE TEMP TABLE _gen_products AS SELECT i AS pid FROM generate_series(1, 50) i');
        $pids = [];
        for ($i = 0; $i < 50; $i++) { $pids[] = self::product(); }
        $db->exec('CREATE TEMP TABLE _pid(n int, id int)');
        foreach ($pids as $n => $id) { $db->exec("INSERT INTO _pid VALUES ($n, $id)"); }
        $loc = self::$loc;
        // The stock_log triggers rebuild price caches per row; they are disabled for the bulk load only
        // (and re-enabled before measuring), because the cost being measured is that of the new tables.
        $db->exec('ALTER TABLE stock_log DISABLE TRIGGER USER; ALTER TABLE stock DISABLE TRIGGER USER');
        // N families in the legacy shape: one purchase of 5, one consume of 2 (80%), or a full consume of 5 (20%, no row).
        $t = microtime(true);
        $db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, transaction_id, user_id)
            SELECT p.id, 5, '2999-12-31', '2026-10-01', 'g' || i, 'purchase', 1.0, $loc, 'tp' || i, 9000 FROM generate_series(1, $N) i JOIN _pid p ON p.n = i % 50");
        $db->exec("INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, transaction_id, user_id, stock_row_id)
            SELECT p.id, CASE WHEN i % 5 = 0 THEN -5 ELSE -2 END, '2999-12-31', '2026-10-01', 'g' || i, 'consume', 1.0, $loc, 'tc' || i, 9000, NULL FROM generate_series(1, $N) i JOIN _pid p ON p.n = i % 50");
        $db->exec("INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
            SELECT p.id, 3, '2999-12-31', '2026-10-01', 'g' || i, 1.0, $loc FROM generate_series(1, $N) i JOIN _pid p ON p.n = i % 50 WHERE i % 5 <> 0");
        $db->exec('ALTER TABLE stock_log ENABLE TRIGGER USER; ALTER TABLE stock ENABLE TRIGGER USER');
        $gen = round(microtime(true) - $t, 2);
        $size = fn(string $rel) => (int)$db->query("SELECT pg_total_relation_size('$rel')")->fetchColumn();
        $before = ['stock_log' => $size('stock_log'), 'stock' => $size('stock')];
        $rowsLog = (int)$db->query('SELECT count(*) FROM stock_log')->fetchColumn();
        $t = microtime(true);
        $db->query('SELECT * FROM spike_backfill()')->fetchAll();
        $bf = round(microtime(true) - $t, 2);
        $after = ['stock_row_lots' => $size('stock_row_lots'), 'stock_booking_lots' => $size('stock_booking_lots')];
        $counts = ['stock_log' => $rowsLog, 'stock' => (int)$db->query('SELECT count(*) FROM stock')->fetchColumn(),
            'stock_row_lots' => (int)$db->query('SELECT count(*) FROM stock_row_lots')->fetchColumn(), 'stock_booking_lots' => (int)$db->query('SELECT count(*) FROM stock_booking_lots')->fetchColumn()];
        $db->exec('ANALYZE stock_log; ANALYZE stock_booking_lots; ANALYZE stock_row_lots; ANALYZE stock');
        $plan = function (string $sql) use ($db) { $x = $db->query("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) $sql")->fetchColumn(); $j = json_decode($x, true)[0]; return ['execution_ms' => $j['Execution Time'], 'plan' => $j['Plan']['Node Type'] . ' / ' . ($j['Plan']['Plans'][0]['Node Type'] ?? '')]; };
        $lot = (int)$db->query("SELECT lot_id FROM stock_booking_lots WHERE basis='derived' LIMIT 1 OFFSET 1000")->fetchColumn();
        $booking = (int)$db->query("SELECT booking_id FROM stock_booking_lots WHERE lot_id=$lot LIMIT 1")->fetchColumn();
        $q = [
            'dependency_check_one_lot' => $plan("SELECT x.id FROM stock_log x JOIN stock_booking_lots bl ON bl.booking_id = x.id WHERE x.undone = 0 AND x.id > $booking AND bl.lot_id = $lot LIMIT 1"),
            'where_is_lot' => $plan("SELECT stock_row_id, amount FROM stock_row_lots WHERE lot_id = $lot"),
            'lots_of_row' => $plan('SELECT lot_id, amount FROM stock_row_lots WHERE stock_row_id = (SELECT min(id) FROM stock)'),
            'allocations_of_booking' => $plan("SELECT lot_id, amount FROM stock_booking_lots WHERE booking_id = $booking"),
        ];
        // The existing dependency query, for comparison (index ix_stock_log_performance1 starts with stock_id).
        $q['existing_dependency_check_by_stock_id'] = $plan("SELECT count(*) FROM stock_log WHERE stock_id = 'g1000' AND id > 1 AND undone = 0");
        self::$out['cost'] = ['rows' => $counts, 'generated_seconds' => $gen, 'backfill_seconds' => $bf, 'bytes_before' => $before, 'bytes_new_tables' => $after,
            'new_tables_over_stock_log_percent' => round(100 * ($after['stock_row_lots'] + $after['stock_booking_lots']) / $before['stock_log'], 1), 'queries' => $q];
    }
}
Adr36Model::executeProbe();
