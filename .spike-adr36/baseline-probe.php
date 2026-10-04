<?php
// Runtime reproduction of today's behaviour (HEAD) through the real StockService, in a
// disposable migrated schema. No production code is changed. Output: JSON on stdout.
require '/app/tests/bootstrap.php';
use Victual\Services\StockService as S;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class Adr36Baseline extends PgsqlSchemaTestCase
{
    private static PDO $db;
    private static S $stock;
    private static int $loc;
    private static int $loc2;
    private static int $serial = 0;
    private static array $out = [];

    private static function product(): int
    {
        $n = ++self::$serial;
        return (int)self::$db->query("INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES ('p$n'," . self::$loc . ",2,2,2,2) RETURNING id")->fetchColumn();
    }
    private static function add(int $p, float $q): array
    {
        $tx = self::$stock->AddProduct($p, $q, '2999-12-31', S::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$loc);
        usleep(1500); // uniqid() is time based; keep stock_id order equal to purchase order
        return self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx'")->fetchAll(PDO::FETCH_COLUMN);
    }
    private static function state(int $p): array
    {
        $rows = self::$db->query("SELECT id, stock_id, amount::float8 AS amount, location_id, open FROM stock WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $log = self::$db->query("SELECT id, stock_id, stock_row_id, transaction_type AS type, amount::float8 AS amount, undone FROM stock_log WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        return ['stock' => $rows, 'stock_log' => $log];
    }
    private static function attempt(string $label, callable $call): array
    {
        try { $call(); return ['step' => $label, 'result' => 'accepted']; }
        catch (Throwable $e) { return ['step' => $label, 'result' => 'refused', 'message' => $e->getMessage()]; }
    }
    private static function scenario(string $name, callable $body): void
    {
        $p = self::product();
        $steps = [];
        $body($p, $steps);
        self::$out[$name] = ['steps' => $steps, 'final' => self::state($p)];
    }
    private static function undo(int $bookingId): callable { return fn() => self::$stock->UndoBooking($bookingId); }

    public static function executeProbe(): void
    {
        parent::setUpBeforeClass();
        try {
            self::$db = self::Pdo();
            self::$stock = S::GetInstance();
            self::$db->exec("INSERT INTO users(id,username,password) VALUES(9000,'adr36','fixture')");
            self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9000,id FROM permission_hierarchy WHERE name='ADMIN'");
            self::$loc = (int)self::$db->query("INSERT INTO locations(name) VALUES('L1') RETURNING id")->fetchColumn();
            self::$loc2 = (int)self::$db->query("INSERT INTO locations(name) VALUES('L2') RETURNING id")->fetchColumn();
            self::$out['environment'] = ['php' => PHP_VERSION, 'postgres' => self::$db->query('SHOW server_version')->fetchColumn()];

            foreach (['3-then-2' => [3, 2], '2-then-3' => [2, 3]] as $name => [$first, $second]) {
                self::scenario("merge_$name", function ($p, &$s) use ($first, $second) {
                    [$a] = self::add($p, $first); [$b] = self::add($p, $second);
                    $s[] = ['step' => 'before merge', 'state' => self::state($p)];
                    self::$stock->CompactStockEntries($p);
                    $s[] = ['step' => 'after CompactStockEntries', 'state' => self::state($p)];
                    $tx = self::$stock->ConsumeProduct($p, 4, false, S::TRANSACTION_TYPE_CONSUME);
                    $c = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
                    $s[] = ['step' => 'after consume 4', 'state' => self::state($p)];
                    $s[] = self::attempt("undo purchase of $first (booking $a)", self::undo((int)$a));
                    $s[] = self::attempt("undo purchase of $second (booking $b)", self::undo((int)$b));
                    $s[] = self::attempt("undo consume (booking $c)", self::undo($c));
                    $s[] = self::attempt("undo purchase of $second (booking $b) after consume undone", self::undo((int)$b));
                    $s[] = ['step' => 'after undo of the second purchase', 'state' => self::state($p)];
                    $s[] = self::attempt("undo purchase of $first (booking $a)", self::undo((int)$a));
                });
            }

            self::scenario('merge_then_undo_without_consumption', function ($p, &$s) {
                [$a] = self::add($p, 3); [$b] = self::add($p, 2);
                self::$stock->CompactStockEntries($p);
                $s[] = self::attempt("undo older purchase (booking $a) first", self::undo((int)$a));
                $s[] = self::attempt("undo newer purchase (booking $b)", self::undo((int)$b));
                $s[] = ['step' => 'after newer undone', 'state' => self::state($p)];
                $s[] = self::attempt("undo older purchase (booking $a)", self::undo((int)$a));
            });

            self::scenario('same_stock_id_after_merge_is_all_the_ledger_keeps', function ($p, &$s) {
                self::add($p, 3); self::add($p, 2);
                self::$stock->CompactStockEntries($p);
                $s[] = ['step' => 'purchases 3 and 2 merged; query: which units belong to which purchase?',
                    'live_additions_per_stock_id' => self::$db->query("SELECT stock_id, count(*) AS bookings, array_agg(amount ORDER BY id) AS amounts FROM stock_log WHERE product_id=$p AND transaction_type='purchase' GROUP BY stock_id")->fetchAll(PDO::FETCH_ASSOC)];
            });

            self::scenario('transfer_split_then_merge', function ($p, &$s) {
                self::add($p, 3); self::add($p, 2);
                self::$stock->CompactStockEntries($p);
                $s[] = self::attempt('transfer 2 of the merged 5 to L2 (split; both rows now share a stock_id)', fn() => self::$stock->TransferProduct($p, 2, self::$loc, self::$loc2));
                self::add($p, 1); self::add($p, 4);
                $s[] = self::attempt('maintenance merge with two new purchases at L1', fn() => self::$stock->CompactStockEntries($p));
                $s[] = ['step' => 'state after second maintenance run', 'state' => self::state($p)];
            });

            self::scenario('open_then_purchase_then_merge_then_undo_open', function ($p, &$s) {
                self::add($p, 2); self::add($p, 3);
                self::$stock->CompactStockEntries($p);
                $tx = self::$stock->OpenProduct($p, 2);
                $o = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
                self::add($p, 4);
                $s[] = ['step' => 'merge 5 -> open 2 (split) -> purchase 4', 'state' => self::state($p)];
                self::$stock->CompactStockEntries($p);
                $s[] = ['step' => 'after maintenance run', 'state' => self::state($p)];
                $s[] = self::attempt("undo the opening (booking $o)", self::undo($o));
            });

            self::scenario('open_whole_rows_then_merge_then_undo_each_open', function ($p, &$s) {
                self::add($p, 2); self::add($p, 3);
                $tx1 = self::$stock->OpenProduct($p, 2);
                $tx2 = self::$stock->OpenProduct($p, 3);
                $o1 = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx1'")->fetchColumn();
                $o2 = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx2'")->fetchColumn();
                $s[] = ['step' => 'purchases 2 and 3, then open 2 and open 3 (two whole-row openings)', 'state' => self::state($p)];
                self::$stock->CompactStockEntries($p);
                $s[] = ['step' => 'after maintenance run', 'state' => self::state($p)];
                $s[] = self::attempt("undo the second opening (booking $o2)", self::undo($o2));
                $s[] = self::attempt("undo the first opening (booking $o1)", self::undo($o1));
            });

            self::scenario('partial_opens_then_merge', function ($p, &$s) {
                self::add($p, 2); self::add($p, 3);
                self::$stock->OpenProduct($p, 2);
                self::$stock->OpenProduct($p, 1);
                $s[] = ['step' => 'purchases 2 and 3; open 2 (whole row), open 1 (splits the 3)', 'state' => self::state($p)];
                self::$stock->CompactStockEntries($p);
                $s[] = ['step' => 'after maintenance run (opened 2 and opened 1 share every grouping column)', 'state' => self::state($p),
                    'origins' => self::$db->query('SELECT stock_id, origin_stock_id FROM stock_entry_origins')->fetchAll(PDO::FETCH_ASSOC)];
            });

            self::scenario('edit_then_merge_then_undo_edit', function ($p, &$s) {
                self::add($p, 3); self::add($p, 2);
                $row = self::$db->query("SELECT * FROM stock WHERE product_id=$p ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $tx = self::$stock->EditStockEntry((int)$row['id'], 2.5, $row['best_before_date'], (int)$row['location_id'], null, (float)$row['price'], (int)$row['open'], $row['purchased_date']);
                $e = (int)self::$db->query("SELECT max(id) FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
                self::$stock->CompactStockEntries($p);
                $s[] = ['step' => 'edit second row 2 -> 2.5, then merge', 'state' => self::state($p)];
                $s[] = self::attempt("undo the edit (booking $e)", self::undo($e));
            });

            self::scenario('full_consume_then_undo', function ($p, &$s) {
                self::add($p, 3);
                $before = self::state($p);
                $tx = self::$stock->ConsumeProduct($p, 3, false, S::TRANSACTION_TYPE_CONSUME);
                $c = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
                $s[] = ['step' => 'before', 'state' => $before];
                $s[] = ['step' => 'after full consume', 'state' => self::state($p)];
                $s[] = self::attempt("undo consume (booking $c)", self::undo($c));
            });

            echo json_encode(self::$out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
        } finally {
            parent::tearDownAfterClass();
        }
    }
}
Adr36Baseline::executeProbe();
