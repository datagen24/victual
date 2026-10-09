<?php
// SPIKE ONLY (ADR-0037). Worked examples of the proposed revival model. The real StockService
// runs every consumption and undo; proposed.sql adds the proposed table, trigger and revival
// function; this file plays the part of the application hook that the implementation would put
// inside UndoBooking(). Not a candidate implementation. Output: JSON on stdout.
require '/app/tests/bootstrap.php';
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService as S;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class Adr37Model extends PgsqlSchemaTestCase
{
    private static PDO $db;
    private static S $stock;
    private static LabelIdentityService $ident;
    private static int $loc;
    private static int $serial = 0;
    private static array $out = [];

    // ---- fixtures -----------------------------------------------------------------------
    private static function product(): int
    {
        $n = ++self::$serial;
        return (int)self::$db->query("INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES ('p$n'," . self::$loc . ",2,2,2,2) RETURNING id")->fetchColumn();
    }
    private static function add(int $p, float $q): int  // returns the new stock row id
    {
        self::$stock->AddProduct($p, $q, '2999-12-31', S::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$loc);
        usleep(1500);
        return (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id=$p")->fetchColumn();
    }
    private static function label(int $row): string
    {
        self::$db->beginTransaction();
        $epoch = (int)self::$db->query('SELECT epoch FROM label_import_state')->fetchColumn();
        $uid = self::$ident->Issue('stock_entry', $row, $epoch);
        self::$db->commit();
        return $uid;
    }
    /** @return array{0:string,1:array<int>} transaction id and its booking ids */
    private static function consume(int $p, float $q): array
    {
        $tx = self::$stock->ConsumeProduct($p, $q, false, S::TRANSACTION_TYPE_CONSUME);
        return [$tx, array_map('intval', self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN))];
    }
    private static function lockImport(): void { self::$db->query('SELECT pg_advisory_xact_lock(109038)')->fetchColumn(); }

    // ---- the hook the implementation would put in UndoBooking() ---------------------------------
    /** Runs inside an open transaction. Order: product lock, import lock, row rebuild, revival. */
    private static function undoReviveSteps(int $booking, ?string $now = null): string
    {
        $b = self::$db->query("SELECT product_id, stock_id FROM stock_log WHERE id=$booking")->fetch(PDO::FETCH_ASSOC);
        DatabaseService::GetInstance()->LockProductStock((int)$b['product_id']);
        $epoch = (int)self::$db->query('SELECT label_current_import_epoch()')->fetchColumn();
        $has = (bool)self::$db->query("SELECT 1 FROM stock_label_retirements WHERE import_epoch=$epoch AND booking_id=$booking AND outcome IS NULL")->fetchColumn();
        if ($has) { self::lockImport(); }
        self::$stock->UndoBooking($booking);
        // The spike derives the rebuilt row (written by this transaction, carrying the booking's tag). The
        // implementation takes the id from the insert it has just made and never searches for it.
        $row = self::$db->query("SELECT id FROM stock WHERE xmin = pg_current_xact_id()::xid AND product_id=" . (int)$b['product_id'] . " AND stock_id=" . self::$db->quote($b['stock_id']) . " ORDER BY id DESC LIMIT 1")->fetchColumn();
        if (!$has || $row === false) { return 'no_reference'; }
        $st = self::$db->prepare('SELECT spike_revive_stock_entry_label(?, ?, ?::timestamptz)');
        $st->execute([$booking, (int)$row, $now]);
        return (string)$st->fetchColumn();
    }
    /** Same, one transaction; returns [undo result, revival result]. */
    private static function undoRevive(int $booking, ?string $now = null): array
    {
        try {
            return ['accepted', DatabaseService::GetInstance()->InTransaction(fn() => self::undoReviveSteps($booking, $now))];
        } catch (Throwable $e) { return ['refused: ' . $e->getMessage(), 'not attempted']; }
    }

    // ---- observation --------------------------------------------------------------------------
    private static function world(int $p, array $uids): array
    {
        $names = array_flip($uids);
        $labels = [];
        foreach ($uids as $name => $uid) {
            $r = self::$db->query("SELECT target_id, retired_at IS NOT NULL AS retired, (retirement_snapshot->>'id') AS snapshot_row FROM labels WHERE uid='$uid'")->fetch(PDO::FETCH_ASSOC);
            try { $scan = self::$ident->Resolve($uid, fn() => true); $scanText = $scan['status'] . (isset($scan['target']) ? ' -> row ' . $scan['target']['id'] : ''); }
            catch (Throwable $e) { $scanText = 'error: ' . $e->getMessage(); }
            $labels[$name] = ['target' => $r['target_id'] === null ? null : (int)$r['target_id'], 'retired' => (bool)$r['retired'],
                'snapshot_row' => $r['snapshot_row'] === null ? null : (int)$r['snapshot_row'], 'scan' => $scanText];
        }
        $in = $uids ? "'" . implode("','", $uids) . "'" : "''";
        $events = self::$db->query("SELECT id, label_uid, cause, booking_id, outcome, reason, revived_target_id, (snapshot->>'id')::int AS snapshot_row,
            round(extract(epoch FROM revivable_until - retired_at))::int AS window_s FROM stock_label_retirements WHERE label_uid IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$e) { $e['label'] = $names[$e['label_uid']]; unset($e['label_uid']); }
        return [
            'stock' => self::$db->query("SELECT id, amount::float8 AS amount, product_id FROM stock WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'bookings' => self::$db->query("SELECT id, stock_row_id, transaction_type AS type, amount::float8 AS amount, undone FROM stock_log WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            'labels' => $labels, 'events' => $events];
    }
    private static function step(string $name, int $p, array $uids, array $extra = []): array { return ['step' => $name] + $extra + ['state' => self::world($p, $uids)]; }

    // ---- scenarios --------------------------------------------------------------------------------
    public static function executeProbe(): void
    {
        parent::setUpBeforeClass();
        try {
            self::$db = self::Pdo();
            self::$stock = S::GetInstance();
            self::$ident = new LabelIdentityService(self::$db);
            self::$db->exec("INSERT INTO users(id,username,password) VALUES(9000,'adr37','fixture')");
            self::$db->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT 9000,id FROM permission_hierarchy WHERE name='ADMIN'");
            self::$loc = (int)self::$db->query("INSERT INTO locations(name) VALUES('L1') RETURNING id")->fetchColumn();
            self::$db->exec(file_get_contents('/app/.devtools/adr0037/proposed.sql'));
            self::$out['environment'] = ['php' => PHP_VERSION, 'postgres' => self::$db->query('SHOW server_version')->fetchColumn()];

            self::fullCycle();
            self::expiry();
            self::idsAndImport();
            self::replacementAndCycles();
            self::legacyAndPartial();
            self::mismatchAndRollback();
            self::printJobs();
            self::concurrency();
            self::cost();

            echo json_encode(self::$out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
        } finally {
            parent::tearDownAfterClass();
        }
    }

    private static function fullCycle(): void
    {
        // E1. Full consumption and undo inside the window, original id free. Then a print request.
        $p = self::product(); $r = self::add($p, 3); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        $s[] = self::step('purchase 3, label L issued on the row', $p, $u);
        [, [$c]] = self::consume($p, 3);
        $s[] = self::step("consume 3 (booking $c), the whole row", $p, $u);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        $epoch = (int)self::$db->query('SELECT epoch FROM label_import_state')->fetchColumn();
        self::$db->beginTransaction();
        $newRow = (int)self::$db->query("SELECT id FROM stock WHERE product_id=$p")->fetchColumn();
        $again = self::$ident->Issue('stock_entry', $newRow, $epoch);
        self::$db->commit();
        $s[] = ['step' => 'a user prints "a replacement label" for the restored row (issuance)', 'returned_uid_is_L' => $again === $uid,
            'live_labels_on_row' => (int)self::$db->query("SELECT count(*) FROM labels WHERE kind='stock_entry' AND target_id=$newRow AND retired_at IS NULL")->fetchColumn()];
        self::$out['E1_full_consumption_and_undo_in_window'] = $s;

        // E2. Several rows in one consumption: UndoTransaction restores and revives each by its own booking.
        $p = self::product(); $r1 = self::add($p, 2); $r2 = self::add($p, 3); $u = ['L1' => self::label($r1), 'L2' => self::label($r2)]; $s = [];
        [$tx, $bk] = self::consume($p, 5);
        $s[] = self::step('consume 5 across both rows (two bookings, two retirements)', $p, $u, ['bookings' => $bk]);
        $res = [];
        try {
            DatabaseService::GetInstance()->InTransaction(function () use ($bk, &$res) {
                DatabaseService::GetInstance()->LockProductStock((int)self::$db->query("SELECT product_id FROM stock_log WHERE id={$bk[0]}")->fetchColumn());
                self::lockImport();
                foreach (array_reverse($bk) as $b) { $res[$b] = self::undoReviveSteps($b); }
            });
            $undo = 'accepted';
        } catch (Throwable $e) { $undo = 'refused: ' . $e->getMessage(); }
        $s[] = self::step('undo the transaction, newest booking first', $p, $u, ['undo' => $undo, 'revival_by_booking' => $res]);
        self::$out['E2_one_consumption_two_labelled_rows'] = $s;
    }

    private static function expiry(): void
    {
        $mk = function () { $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); [, [$c]] = self::consume($p, 2); return [$p, $uid, $c]; };
        $T = "'2030-06-01 12:00:00+00'";
        // E3. The boundary. The deadline is exclusive: eligible while now < revivable_until.
        $s = [];
        foreach (['one microsecond before the deadline' => "(revivable_until - interval '1 microsecond')::text", 'exactly at the deadline' => 'revivable_until::text'] as $name => $expr) {
            [$p, $uid, $c] = $mk(); $u = ['L' => $uid];
            self::$db->exec("UPDATE stock_label_retirements SET revivable_until = $T WHERE booking_id = $c");
            $now = self::$db->query("SELECT $expr FROM stock_label_retirements WHERE booking_id=$c")->fetchColumn();
            [$undo, $rev] = self::undoRevive($c, $now);
            $s[] = self::step("undo evaluated $name", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        }
        self::$out['E3_expiry_boundary'] = $s;

        // E4. After expiry, with no cleanup job at all: the reference is still stored as pending, and is not honoured.
        [$p, $uid, $c] = $mk(); $u = ['L' => $uid];
        self::$db->exec("UPDATE stock_label_retirements SET retired_at = now() - interval '40 days', revivable_until = now() - interval '10 days' WHERE booking_id = $c");
        $lapsed = fn() => (int)self::$db->query("SELECT count(*) FROM stock_label_retirements WHERE outcome IS NULL AND revivable_until <= clock_timestamp()")->fetchColumn();
        $s = [['step' => 'consumed 40 days ago; nothing has ever cleaned up (no workload exists)', 'pending_rows_past_their_deadline' => $lapsed()]];
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        $s[] = ['pending_rows_past_their_deadline_afterwards' => $lapsed()];
        self::$out['E4_after_expiry_cleanup_never_ran'] = $s;
    }

    private static function idsAndImport(): void
    {
        // E5. A different restored id: an unrelated row holds the consumed row's id when the undo runs.
        $p = self::product(); $q = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        [, [$c]] = self::consume($p, 2);
        self::$db->exec("INSERT INTO stock(id,product_id,amount,best_before_date,purchased_date,stock_id,price,location_id) VALUES ($r,$q,7,'2999-12-31','2026-10-01','unrelated-$r',1.0," . self::$loc . ')');
        $s[] = self::step("consume (booking $c); an unrelated row of product $q now holds id $r", $p, $u);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev,
            'unrelated_row' => self::$db->query("SELECT id, product_id, amount::float8 AS amount, (SELECT count(*) FROM labels WHERE target_id=$r AND retired_at IS NULL) AS live_labels FROM stock WHERE id=$r")->fetch(PDO::FETCH_ASSOC)]);
        self::$out['E5_different_restored_row_id'] = $s;

        // E6. An import between retirement and undo. RESTART IDENTITY reuses every numeric id.
        $p = self::product(); $q = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        [, [$c]] = self::consume($p, 2);
        self::$db->exec('UPDATE label_import_state SET epoch = epoch + 1');   // the importer's own step
        self::$db->exec("INSERT INTO stock(id,product_id,amount,best_before_date,purchased_date,stock_id,price,location_id) VALUES ($r,$q,7,'2999-12-31','2026-10-01','imported-$r',1.0," . self::$loc . ')');
        self::$db->exec("UPDATE stock_log SET product_id=$q WHERE id=$c");     // the imported ledger reuses booking id $c for other stock
        $s[] = self::step("consume (booking $c); then an import: epoch + 1, row id $r and booking id $c now belong to product $q", $p, $u,
            ['a_repoint_by_snapshot_id_would_target' => self::$db->query("SELECT id, product_id, amount::float8 AS amount FROM stock WHERE id=$r")->fetch(PDO::FETCH_ASSOC)]);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c (now a booking of product $q)", $q, $u, ['undo' => $undo, 'revival' => $rev]);
        $s[] = ['label_L_after' => self::world($p, $u)['labels'], 'events' => self::world($p, $u)['events']];
        self::$out['E6_import_between_retirement_and_undo'] = $s;
    }

    private static function replacementAndCycles(): void
    {
        // E7. A live label already names the row the undo restores (white box: not reachable through the API today).
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); $s = [];
        [, [$c]] = self::consume($p, 2);
        $other = '0AAAAAAAAAAAB';
        self::$db->exec("INSERT INTO labels(uid, kind, target_id) VALUES ('$other', 'stock_entry', $r)");
        $u = ['L' => $uid, 'M' => $other];
        $s[] = self::step("consume (booking $c); fixture inserts a live label M on id $r, the id the undo will restore", $p, $u);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        self::$out['E7_restored_target_already_labelled'] = $s;

        // E8. Consume, undo, consume, undo: each retirement is its own event with its own window.
        $p = self::product(); $r = self::add($p, 3); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        [, [$c1]] = self::consume($p, 3);
        $s[] = self::step("consume (booking $c1)", $p, $u);
        [$undo, $rev] = self::undoRevive($c1);
        $s[] = self::step("undo booking $c1", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        usleep(1100000);
        [, [$c2]] = self::consume($p, 3);
        $s[] = self::step("consume again (booking $c2)", $p, $u);
        [$undo, $rev] = self::undoRevive($c2);
        $s[] = self::step("undo booking $c2", $p, $u, ['undo' => $undo, 'revival' => $rev,
            'second_window_starts_at_second_retirement' => (bool)self::$db->query("SELECT (SELECT retired_at FROM stock_label_retirements WHERE booking_id=$c2) > (SELECT retired_at FROM stock_label_retirements WHERE booking_id=$c1)")->fetchColumn()]);
        self::$out['E8_consume_undo_consume_undo'] = $s;
    }

    private static function legacyAndPartial(): void
    {
        // E9. A retirement that predates the table: backfilled as history, never revivable, nothing inferred.
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        self::$db->exec('ALTER TABLE labels DISABLE TRIGGER stock_label_retirement_event');
        [, [$c]] = self::consume($p, 2);
        self::$db->exec('ALTER TABLE labels ENABLE TRIGGER stock_label_retirement_event');
        $s[] = self::step("legacy retirement (written before the trigger existed), booking $c", $p, $u);
        $n = (int)self::$db->query('SELECT spike_backfill_legacy_retirements()')->fetchColumn();
        $s[] = self::step('backfill', $p, $u, ['events_written' => $n,
            'evidence_a_guess_would_use_but_the_design_does_not' => self::$db->query("SELECT l.stock_row_id = (SELECT (retirement_snapshot->>'id')::int FROM labels WHERE uid='$uid') AS snapshot_id_equals_booking_row_id FROM stock_log l WHERE l.id=$c")->fetch(PDO::FETCH_ASSOC)]);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        $again = (int)self::$db->query('SELECT spike_backfill_legacy_retirements()')->fetchColumn();
        $s[] = ['second backfill run writes' => $again];
        self::$out['E9_ambiguous_legacy_retirement'] = $s;

        // E10. Partial consumption never retires the label; its undo adds a separate row and the hook finds nothing.
        $p = self::product(); $r = self::add($p, 5); $uid = self::label($r); $u = ['L' => $uid]; $s = [];
        [, [$c]] = self::consume($p, 2);
        $s[] = self::step("consume 2 of 5 (booking $c)", $p, $u);
        [$undo, $rev] = self::undoRevive($c);
        $s[] = self::step("undo booking $c", $p, $u, ['undo' => $undo, 'revival' => $rev]);
        self::$out['E10_partial_consumption_never_retires'] = $s;

        // E11. A retirement that is not a consumption (undo of a purchase) is recorded and never revivable.
        $p = self::product(); $r = self::add($p, 4); $uid = self::label($r); $u = ['L' => $uid];
        $buy = (int)self::$db->query("SELECT id FROM stock_log WHERE product_id=$p AND transaction_type='purchase'")->fetchColumn();
        [$undo, $rev] = self::undoRevive($buy);
        self::$out['E11_purchase_undo_is_unproven'] = [self::step("undo the purchase (booking $buy)", $p, $u, ['undo' => $undo, 'revival' => $rev])];

        // E16. Other retirement paths, and a stale context: all recorded as unproven, none revivable.
        $ev = fn(string $uid) => self::$db->query("SELECT cause, booking_id, outcome, reason, (snapshot->>'product_name') AS snapshot_product FROM stock_label_retirements WHERE label_uid='$uid' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $s = [];
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r);
        self::$db->exec("DELETE FROM products WHERE id=$p");
        $s['product deleted (cascade)'] = $ev($uid);
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r);
        self::$db->exec("DELETE FROM stock WHERE id=$r");
        $s['direct DELETE FROM stock, no booking'] = $ev($uid);
        $pa = self::product(); self::add($pa, 5); $pb = self::product(); $rb = self::add($pb, 2); $uid = self::label($rb);
        self::$db->beginTransaction();
        self::$stock->ConsumeProduct($pa, 1, false, S::TRANSACTION_TYPE_CONSUME);   // a booking in this transaction leaves the context set
        self::$db->exec("DELETE FROM stock WHERE id=$rb");                         // a different row, deleted by something else
        self::$db->commit();
        $s['stale context: another row deleted after an unrelated consumption in the same transaction'] = $ev($uid);
        self::$out['E16_other_retirement_paths'] = $s;
    }

    private static function mismatchAndRollback(): void
    {
        // E12. The booking's product or amount changed between retirement and undo (a product merge or a unit rescale).
        $s = [];
        foreach (['product changed by a product merge' => fn($c, $q) => "UPDATE stock_log SET product_id=$q WHERE id=$c",
                  'amount rescaled by a unit change' => fn($c, $q) => "UPDATE stock_log SET amount = amount * 1000 WHERE id=$c"] as $name => $sql) {
            $p = self::product(); $q = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid];
            [, [$c]] = self::consume($p, 2);
            self::$db->exec($sql($c, $q));
            [$undo, $rev] = self::undoRevive($c);
            $s[] = self::step("$name, then undo booking $c", (int)self::$db->query("SELECT product_id FROM stock_log WHERE id=$c")->fetchColumn(), $u, ['undo' => $undo, 'revival' => $rev]);
        }
        self::$out['E12_booking_no_longer_matches_the_retirement'] = $s;

        // E13. A failure after the revival step rolls back stock, booking, label and event together.
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid];
        [, [$c]] = self::consume($p, 2);
        $before = self::world($p, $u);
        try {
            DatabaseService::GetInstance()->InTransaction(function () use ($c) { self::undoReviveSteps($c); throw new RuntimeException('forced failure after the revival step'); });
            $res = 'unexpectedly committed';
        } catch (Throwable $e) { $res = 'rolled back: ' . $e->getMessage(); }
        $after = self::world($p, $u);
        self::$out['E13_rollback_after_attempted_revival'] = ['result' => $res, 'state_before' => $before, 'state_after_is_identical' => $before === $after, 'state_after' => $after];
        // The same undo, afterwards, succeeds and revives.
        [$undo, $rev] = self::undoRevive($c);
        self::$out['E13_rollback_after_attempted_revival']['retry'] = ['undo' => $undo, 'revival' => $rev];

        // E14. A refused undo (the booking's location no longer exists) leaves everything as it was, event included.
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid];
        $locB = (int)self::$db->query("INSERT INTO locations(name) VALUES('L-gone') RETURNING id")->fetchColumn();
        self::$db->exec("UPDATE stock SET location_id=$locB WHERE id=$r");
        [, [$c]] = self::consume($p, 2);
        self::$db->exec("DELETE FROM locations WHERE id=$locB");
        $before = self::world($p, $u);
        [$undo, $rev] = self::undoRevive($c);
        self::$out['E14_refused_undo'] = ['undo' => $undo, 'revival' => $rev, 'state_unchanged' => $before === self::world($p, $u), 'state' => self::world($p, $u)];
    }

    private static function printJobs(): void
    {
        // E15. Retirement while jobs exist: queued, claimed and uncertain, and an authorized retry. Then revival.
        $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); $u = ['L' => $uid];
        $worker = (int)self::$db->query("INSERT INTO label_workers(name,configuration_mode) VALUES('w37','declared') RETURNING id")->fetchColumn();
        $mk = function (string $kind) use ($uid, $worker) {
            $ob = (int)self::$db->query("INSERT INTO outbox(event_type,payload) VALUES('label.print_requested','{}') RETURNING id")->fetchColumn();
            $job = (int)self::$db->query("INSERT INTO print_jobs(outbox_id,printer_id,label_uid) VALUES($ob,9701,'$uid') RETURNING id")->fetchColumn();
            if ($kind !== 'queued') {
                $att = (int)self::$db->query("INSERT INTO print_attempts(outbox_id,job_id,attempt_number,worker_id,lease_expires_at,lease_hard_deadline,acknowledged_on,ended_at,outcome) VALUES($ob,$job,1,$worker,now()-interval '1 hour',now()-interval '1 hour','send',now()-interval '1 hour','uncertain') RETURNING id")->fetchColumn();
                self::$db->exec("UPDATE print_jobs SET current_attempt_id=$att, attempts_made=1" . ($kind === 'authorized_retry' ? ', attempts_authorized=2' : '') . " WHERE id=$job");
            }
            return $job;
        };
        $jobs = ['queued' => $mk('queued'), 'uncertain_attempt' => $mk('uncertain_attempt'), 'authorized_retry' => $mk('authorized_retry')];
        $old = "SELECT j.id FROM print_jobs j WHERE j.outcome IS NULL AND j.cancelled_at IS NULL AND j.attempts_made<j.attempts_authorized AND NOT EXISTS (SELECT 1 FROM labels lb WHERE lb.uid=j.label_uid AND lb.retired_at IS NOT NULL) ORDER BY j.id";
        $new = "SELECT j.id FROM print_jobs j WHERE j.outcome IS NULL AND j.cancelled_at IS NULL AND j.attempts_made<j.attempts_authorized AND NOT EXISTS (SELECT 1 FROM labels lb WHERE lb.uid=j.label_uid AND lb.retired_at IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM stock_label_retirements e WHERE e.label_uid=j.label_uid AND j.id <= e.jobs_through_id) ORDER BY j.id";
        $ids = fn(string $q) => array_map('intval', self::$db->query($q)->fetchAll(PDO::FETCH_COLUMN));
        $state = fn() => array_map(fn($id) => self::$db->query("SELECT cancelled_at IS NOT NULL AS cancelled, attempts_authorized, attempts_made FROM print_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC), $jobs);
        [, [$c]] = self::consume($p, 2);
        $s = [['step' => 'retirement', 'jobs' => $state(), 'job_ids' => $jobs, 'event_jobs_through_id' => (int)self::$db->query("SELECT jobs_through_id FROM stock_label_retirements WHERE booking_id=$c")->fetchColumn()]];
        [$undo, $rev] = self::undoRevive($c);
        $s[] = ['step' => "undo booking $c", 'undo' => $undo, 'revival' => $rev, 'jobs_unchanged_by_revival' => $state(),
            'claimable_with_todays_predicate' => $ids($old), 'claimable_with_the_proposed_predicate' => $ids($new)];
        $ob = (int)self::$db->query("INSERT INTO outbox(event_type,payload) VALUES('label.print_requested','{}') RETURNING id")->fetchColumn();
        $fresh = (int)self::$db->query("INSERT INTO print_jobs(outbox_id,printer_id,label_uid) VALUES($ob,9701,'$uid') RETURNING id")->fetchColumn();
        $s[] = ['step' => 'a job requested after the revival', 'job_id' => $fresh, 'claimable_with_the_proposed_predicate' => $ids($new)];
        self::$out['E15_print_jobs_across_retirement_and_revival'] = $s;
    }

    // ---- two real connections ---------------------------------------------------------------------
    private static function child(string $mode, array $args): array
    {
        $env = ['PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'),
            'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'ADR37_SCHEMA' => self::Schema(), 'PATH' => getenv('PATH'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH')];
        $p = proc_open(['php', '/app/.devtools/adr0037/conc-child.php', $mode, json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/app', $env);
        return ['proc' => $p, 'pipe' => $pipes[1], 'err' => $pipes[2], 'start' => microtime(true)];
    }
    private static function finish(array $c): array
    {
        $out = stream_get_contents($c['pipe']); $err = stream_get_contents($c['err']); proc_close($c['proc']);
        return json_decode($out, true) ?? ['raw' => trim($out . $err)];
    }
    private static function alive(array $c): bool { return proc_get_status($c['proc'])['running']; }

    private static function concurrency(): void
    {
        $res = [];
        $fixture = function () { $p = self::product(); $r = self::add($p, 2); $uid = self::label($r); [, [$c]] = self::consume($p, 2); return [$p, $r, $uid, $c]; };

        // C1. Label issuance racing the undo. (a) issuance first: the row does not exist. (b) issuance while the undo holds its locks.
        [$p, $r, $uid, $c] = $fixture(); $u = ['L' => $uid];
        $before = self::finish(self::child('issue', ['row' => $r]));
        self::$db->beginTransaction();
        self::undoReviveSteps($c);
        $child = self::child('issue', ['row' => $r]);
        usleep(1500000);
        $blocked = self::alive($child);
        self::$db->commit();
        $after = self::finish($child);
        $res['C1_issuance_vs_undo'] = ['issuance_before_the_undo' => $before, 'issuance_started_during_the_undo' => $after, 'child_still_blocked_after_1.5s' => $blocked,
            'issued_uid_is_the_revived_L' => ($after['uid'] ?? null) === $uid, 'live_labels_on_restored_row' => (int)self::$db->query("SELECT count(*) FROM labels WHERE kind='stock_entry' AND retired_at IS NULL AND target_id=" . (int)self::$db->query("SELECT target_id FROM labels WHERE uid='$uid'")->fetchColumn())->fetchColumn(),
            'state' => self::world($p, $u)];

        // C2. Two undo requests for the same booking: the second waits on the product lock, then refuses.
        [$p, $r, $uid, $c] = $fixture(); $u = ['L' => $uid];
        self::$db->beginTransaction();
        self::undoReviveSteps($c);
        $child = self::child('undo', ['booking' => $c]);
        usleep(1500000);
        $blocked = self::alive($child);
        self::$db->commit();
        $res['C2_two_undo_requests'] = ['second_request_blocked_after_1.5s' => $blocked, 'second_request' => self::finish($child), 'state' => self::world($p, $u)];

        // C3. An import racing a revival. (a) the undo holds the import lock first. (b) the importer holds it first.
        [$p, $r, $uid, $c] = $fixture(); $u = ['L' => $uid];
        self::$db->beginTransaction();
        self::undoReviveSteps($c);
        $child = self::child('bump', []);
        usleep(1500000);
        $blocked = self::alive($child);
        self::$db->commit();
        $bump = self::finish($child);
        $res['C3a_import_waits_for_the_revival'] = ['importer_blocked_after_1.5s' => $blocked, 'importer' => $bump,
            'live_labels_after' => (int)self::$db->query('SELECT count(*) FROM labels WHERE retired_at IS NULL AND kind=\'stock_entry\' AND uid=\'' . $uid . '\'')->fetchColumn(),
            'note' => 'a live label makes the real importer refuse (DatabaseImporter: live labels name rows this import replaces)'];
        [$p, $r, $uid, $c] = $fixture(); $u = ['L' => $uid];
        $child = self::child('bump_hold', ['hold_s' => 2.0]);
        usleep(700000);
        $t = microtime(true);
        [$undo, $rev] = self::undoRevive($c);
        $res['C3b_revival_waits_for_the_import'] = ['undo' => $undo, 'revival' => $rev, 'undo_waited_s' => round(microtime(true) - $t, 2), 'importer' => self::finish($child), 'state' => self::world($p, $u)];
        self::$out['concurrency'] = $res;
    }

    // ---- size and lookup cost ---------------------------------------------------------------------
    private static function cost(): void
    {
        $n = (int)(getenv('ADR37_N') ?: 100000);
        $t0 = microtime(true);
        self::$db->exec("INSERT INTO labels(uid, kind, retired_at, retirement_snapshot) SELECT '1' || lpad(upper(to_hex(i)), 12, '0'), 'stock_entry', now(),
            jsonb_build_object('id', i, 'product_name', 'Synthetic product name ' || i, 'best_before_date', '2999-12-31', 'amount', 1.0) FROM generate_series(1, $n) i");
        self::$db->exec('ANALYZE labels');   // without it the foreign-key check on this bulk load plans a sequential scan on labels
        $labelsS = round(microtime(true) - $t0, 2);
        self::$db->exec("INSERT INTO stock_label_retirements(label_uid, retired_at, snapshot, cause, import_epoch, booking_id, product_id, amount, jobs_through_id, revivable_until)
            SELECT uid, retired_at, retirement_snapshot, 'consumption', 0, (retirement_snapshot->>'id')::bigint + 1000000, 1, 1.0, 0, now() + interval '30 days' FROM labels WHERE retirement_snapshot->>'product_name' LIKE 'Synthetic%'");
        $insertS = round(microtime(true) - $t0, 2);
        self::$db->exec('ANALYZE stock_label_retirements');
        $plan = self::$db->query('EXPLAIN (ANALYZE, FORMAT JSON) SELECT * FROM stock_label_retirements WHERE import_epoch = 0 AND booking_id = 1050000 AND outcome IS NULL')->fetchColumn();
        $plan = json_decode($plan, true)[0];
        self::$out['cost'] = ['events' => $n, 'insert_labels_s' => $labelsS, 'insert_labels_and_events_s' => $insertS,
            'table_bytes' => (int)self::$db->query("SELECT pg_relation_size('stock_label_retirements')")->fetchColumn(),
            'total_bytes_with_indexes' => (int)self::$db->query("SELECT pg_total_relation_size('stock_label_retirements')")->fetchColumn(),
            'labels_total_bytes_same_rows' => (int)self::$db->query("SELECT pg_total_relation_size('labels')")->fetchColumn(),
            'bytes_per_event_with_indexes' => round((int)self::$db->query("SELECT pg_total_relation_size('stock_label_retirements')")->fetchColumn() / $n),
            'probe_by_epoch_and_booking' => ['execution_ms' => $plan['Execution Time'], 'node' => $plan['Plan']['Node Type'], 'index' => $plan['Plan']['Index Name'] ?? null]];
    }
}
Adr37Model::executeProbe();
