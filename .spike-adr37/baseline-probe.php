<?php
// SPIKE ONLY (ADR-0037). Runtime facts about today's label retirement and undo, produced by
// the real StockService and the real label services in a disposable migrated schema.
// No production code is changed. Output: JSON on stdout.
require '/app/tests/bootstrap.php';
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService as S;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class Adr37Baseline extends PgsqlSchemaTestCase
{
    private static PDO $db;
    private static S $stock;
    private static LabelIdentityService $ident;
    private static int $loc;
    private static int $serial = 0;
    private static array $out = [];

    private static function product(): int
    {
        $n = ++self::$serial;
        return (int)self::$db->query("INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES ('p$n'," . self::$loc . ",2,2,2,2) RETURNING id")->fetchColumn();
    }
    /** Purchase; returns [booking id, stock row id]. */
    private static function add(int $p, float $q): array
    {
        $tx = self::$stock->AddProduct($p, $q, '2999-12-31', S::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, self::$loc);
        usleep(1500);
        $b = (int)self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx'")->fetchColumn();
        $row = (int)self::$db->query("SELECT max(id) FROM stock WHERE product_id=$p")->fetchColumn();
        return [$b, $row];
    }
    private static function label(int $row): string
    {
        self::$db->beginTransaction();
        $epoch = (int)self::$db->query('SELECT epoch FROM label_import_state')->fetchColumn();
        $uid = self::$ident->Issue('stock_entry', $row, $epoch);
        self::$db->commit();
        return $uid;
    }
    private static function bookingOf(string $tx): array
    {
        return self::$db->query("SELECT id FROM stock_log WHERE transaction_id='$tx' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }
    private static function labelState(string $uid): array
    {
        $r = self::$db->query("SELECT target_id, retired_at IS NOT NULL AS retired, retirement_snapshot FROM labels WHERE uid='$uid'")->fetch(PDO::FETCH_ASSOC);
        $r['retired'] = (bool)$r['retired'];
        $r['retirement_snapshot'] = $r['retirement_snapshot'] === null ? null : json_decode($r['retirement_snapshot'], true);
        $res = self::$ident->Resolve($uid, fn() => true);
        $r['resolve_status'] = $res['status'];
        if (isset($res['target'])) { $r['resolve_target_id'] = $res['target']['id']; }
        return $r;
    }
    private static function rows(int $p): array
    {
        return self::$db->query("SELECT id, stock_id, amount::float8 AS amount FROM stock WHERE product_id=$p ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function attempt(callable $call): array
    {
        try { $call(); return ['result' => 'accepted']; }
        catch (Throwable $e) { return ['result' => 'refused', 'message' => $e->getMessage()]; }
    }
    private static function in(callable $f): mixed { return $f(); }

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
            self::$out['environment'] = ['php' => PHP_VERSION, 'postgres' => self::$db->query('SHOW server_version')->fetchColumn(),
                'migrations_applied' => (int)self::$db->query('SELECT count(*) FROM migrations')->fetchColumn(),
                'latest_migration' => self::$db->query('SELECT max(migration) FROM migrations')->fetchColumn()];

            // S0. Schema invariants, read from the catalogue of the migrated schema.
            $o = [];
            $o['labels_columns'] = self::$db->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name='labels' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
            $o['labels_constraints'] = self::$db->query("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid='labels'::regclass ORDER BY conname")->fetchAll(PDO::FETCH_ASSOC);
            $o['labels_indexes'] = self::$db->query("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname=current_schema() AND tablename='labels' ORDER BY indexname")->fetchAll(PDO::FETCH_ASSOC);
            $o['triggers_on_labels'] = self::$db->query("SELECT tgname FROM pg_trigger WHERE tgrelid='labels'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN);
            $o['retirement_triggers'] = self::$db->query("SELECT tgrelid::regclass::text AS on_table, tgname FROM pg_trigger WHERE NOT tgisinternal AND (tgname LIKE 'retire_%_labels' OR tgname LIKE '%cascade_product_removal%') ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
            $o['foreign_keys_into_or_out_of_labels'] = self::$db->query("SELECT conrelid::regclass::text AS from_table, confrelid::regclass::text AS to_table FROM pg_constraint WHERE contype='f' AND (conrelid='labels'::regclass OR confrelid='labels'::regclass)")->fetchAll(PDO::FETCH_ASSOC);
            $p = self::product(); [, $r] = self::add($p, 1);
            $probe = function (string $sql): string {
                self::$db->exec('SAVEPOINT x');
                try { self::$db->exec($sql); $res = 'accepted'; self::$db->exec('ROLLBACK TO x'); }
                catch (Throwable $e) { $res = 'rejected: ' . preg_replace('/\s+/', ' ', explode("\n", $e->getMessage())[0]); self::$db->exec('ROLLBACK TO x'); }
                return $res;
            };
            self::$db->beginTransaction();
            $o['invalid_row_probes'] = [
                'live label carrying a snapshot' => $probe("INSERT INTO labels(uid,kind,target_id,retirement_snapshot) VALUES ('0ZZZZZZZZZZZZ','stock_entry',$r,'{}')"),
                'retired label keeping a target' => $probe("INSERT INTO labels(uid,kind,target_id,retired_at,retirement_snapshot) VALUES ('0ZZZZZZZZZZZY','stock_entry',$r,now(),'{}')"),
                'retired label with no snapshot' => $probe("INSERT INTO labels(uid,kind,retired_at) VALUES ('0ZZZZZZZZZZZX','stock_entry',now())"),
            ];
            self::$db->exec("INSERT INTO labels(uid,kind,target_id) VALUES ('0ZZZZZZZZZZZW','stock_entry',$r)");
            $o['invalid_row_probes']['second live label on one target'] = $probe("INSERT INTO labels(uid,kind,target_id) VALUES ('0ZZZZZZZZZZZV','stock_entry',$r)");
            $o['invalid_row_probes']['revival UPDATE of a retired label (retired_at NULL, new target, snapshot NULL)'] = 'see revival_by_update below';
            self::$db->rollBack();
            self::$out['S0_schema'] = $o;

            // S1. Whole-row consumption of a labelled row, then undo, original id free.
            $p = self::product(); [$buy, $r] = self::add($p, 3); $uid = self::label($r);
            $steps = [];
            $steps[] = ['step' => 'purchase 3, label issued', 'rows' => self::rows($p), 'label' => self::labelState($uid)];
            $tx = self::$stock->ConsumeProduct($p, 3, false, S::TRANSACTION_TYPE_CONSUME); $c = self::bookingOf($tx)[0];
            $steps[] = ['step' => 'consume 3 (whole row)', 'rows' => self::rows($p), 'label' => self::labelState($uid),
                'booking' => self::$db->query("SELECT id, stock_row_id, stock_id, amount::float8 AS amount, transaction_type FROM stock_log WHERE id=$c")->fetch(PDO::FETCH_ASSOC)];
            $steps[] = ['step' => "undo consume (booking $c)"] + self::attempt(fn() => self::$stock->UndoBooking((int)$c));
            $steps[] = ['step' => 'after undo', 'rows' => self::rows($p), 'label' => self::labelState($uid), 'original_row_id' => $r];
            // Revival by plain UPDATE is accepted by the current constraints (shown, then rolled back).
            self::$db->beginTransaction();
            $newRow = (int)self::$db->query("SELECT id FROM stock WHERE product_id=$p")->fetchColumn();
            self::$db->exec("UPDATE labels SET retired_at=NULL, target_id=$newRow, retirement_snapshot=NULL WHERE uid='$uid'");
            $steps[] = ['step' => 'revival by plain UPDATE inside a transaction (rolled back afterwards)', 'label' => self::labelState($uid)];
            self::$db->rollBack();
            self::$out['S1_full_consume_undo_id_free'] = $steps;

            // S2. The original id is occupied by an unrelated row when the undo runs.
            $p = self::product(); $q = self::product(); [, $r] = self::add($p, 2); $uid = self::label($r);
            $steps = [];
            $tx = self::$stock->ConsumeProduct($p, 2, false, S::TRANSACTION_TYPE_CONSUME); $c = self::bookingOf($tx)[0];
            self::$db->exec("INSERT INTO stock(id,product_id,amount,best_before_date,purchased_date,stock_id,price,location_id) VALUES ($r,$q,7,'2999-12-31','2026-10-01','unrelated-$r',1.0," . self::$loc . ')');
            $steps[] = ['step' => "consume 2, then an unrelated row of another product is inserted under the freed id $r (as an import with RESTART IDENTITY would)",
                'unrelated_row' => self::$db->query("SELECT id, product_id, amount::float8 AS amount FROM stock WHERE id=$r")->fetch(PDO::FETCH_ASSOC)];
            $steps[] = ["step" => "undo consume (booking $c)"] + self::attempt(fn() => self::$stock->UndoBooking((int)$c));
            $steps[] = ['step' => 'after undo', 'rows_of_product' => self::rows($p), 'unrelated_row_untouched' => self::$db->query("SELECT id, product_id, amount::float8 AS amount FROM stock WHERE id=$r")->fetch(PDO::FETCH_ASSOC),
                'label' => self::labelState($uid), 'consumed_row_id' => $r];
            self::$out['S2_original_id_taken'] = $steps;

            // S3. Partial consumption never retires the label; its undo adds a separate row.
            $p = self::product(); [, $r] = self::add($p, 5); $uid = self::label($r);
            $steps = [];
            $tx = self::$stock->ConsumeProduct($p, 2, false, S::TRANSACTION_TYPE_CONSUME); $c = self::bookingOf($tx)[0];
            $steps[] = ['step' => 'consume 2 of 5', 'rows' => self::rows($p), 'label' => self::labelState($uid)];
            $steps[] = ["step" => "undo (booking $c)"] + self::attempt(fn() => self::$stock->UndoBooking((int)$c));
            $steps[] = ['step' => 'after undo', 'rows' => self::rows($p), 'label' => self::labelState($uid), 'labelled_row' => $r];
            self::$out['S3_partial_consume'] = $steps;

            // S4. One consume spanning two labelled rows; UndoTransaction restores both rows.
            $p = self::product(); [, $r1] = self::add($p, 2); [, $r2] = self::add($p, 3); $u1 = self::label($r1); $u2 = self::label($r2);
            $steps = [];
            $tx = self::$stock->ConsumeProduct($p, 5, false, S::TRANSACTION_TYPE_CONSUME);
            $steps[] = ['step' => 'consume 5 across both rows (one transaction, two bookings)', 'rows' => self::rows($p),
                'bookings' => self::$db->query("SELECT id, stock_row_id, amount::float8 AS amount FROM stock_log WHERE transaction_id='$tx' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                'label_1' => self::labelState($u1), 'label_2' => self::labelState($u2)];
            $steps[] = ['step' => 'UndoTransaction'] + self::attempt(fn() => self::$stock->UndoTransaction($tx));
            $steps[] = ['step' => 'after undo', 'rows' => self::rows($p), 'label_1' => self::labelState($u1), 'label_2' => self::labelState($u2), 'consumed_row_ids' => [$r1, $r2]];
            self::$out['S4_one_consume_two_labelled_rows'] = $steps;

            // S5. A partial consume then a whole consume: stock_row_id alone names two bookings.
            $p = self::product(); [, $r] = self::add($p, 5); $uid = self::label($r);
            self::$stock->ConsumeProduct($p, 2, false, S::TRANSACTION_TYPE_CONSUME);
            self::$stock->ConsumeProduct($p, 3, false, S::TRANSACTION_TYPE_CONSUME);
            self::$out['S5_row_id_names_two_bookings'] = [
                'label' => self::labelState($uid),
                'bookings_with_stock_row_id_equal_to_snapshot_id' => self::$db->query("SELECT id, amount::float8 AS amount, transaction_type FROM stock_log WHERE stock_row_id=$r ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                'note' => 'the snapshot holds the row id and amount, not the booking; two bookings carry that row id'];

            // S6. Retirement without any consumption: undo of a purchase, and product removal.
            $p = self::product(); [$buy, $r] = self::add($p, 4); $uid = self::label($r);
            $steps = [['step' => "undo the purchase (booking $buy)"] + self::attempt(fn() => self::$stock->UndoBooking((int)$buy)), ['label' => self::labelState($uid)]];
            self::$out['S6a_purchase_undo_retires_label'] = $steps;
            $p = self::product(); [, $r] = self::add($p, 4); $uid = self::label($r);
            self::$db->exec("DELETE FROM products WHERE id=$p");
            self::$out['S6b_product_deletion_retires_label'] = ['label' => self::labelState($uid), 'stock_log_rows_left_for_product' => (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id=$p")->fetchColumn()];

            // S7. Undo that must refuse leaves stock, booking and label as they were.
            $p = self::product(); [, $r] = self::add($p, 2); $uid = self::label($r);
            $locB = (int)self::$db->query("INSERT INTO locations(name) VALUES('L-gone') RETURNING id")->fetchColumn();
            self::$db->exec("UPDATE stock SET location_id=$locB WHERE id=$r");
            $tx = self::$stock->ConsumeProduct($p, 2, false, S::TRANSACTION_TYPE_CONSUME); $c = self::bookingOf($tx)[0];
            self::$db->exec("DELETE FROM locations WHERE id=$locB");
            $steps = [['step' => "consume 2, then the booking's location is deleted; undo booking $c"] + self::attempt(fn() => self::$stock->UndoBooking((int)$c))];
            $steps[] = ['rows' => self::rows($p), 'booking_undone' => (bool)self::$db->query("SELECT undone FROM stock_log WHERE id=$c")->fetchColumn(), 'label' => self::labelState($uid)];
            self::$out['S7_refused_undo_changes_nothing'] = $steps;

            // S8. Retirement with an unclaimed job, a claimed job, and an authorized-but-unclaimed retry.
            $p = self::product(); [, $r] = self::add($p, 2); $uid = self::label($r);
            $worker = (int)self::$db->query("INSERT INTO label_workers(name,configuration_mode) VALUES('w37','declared') RETURNING id")->fetchColumn();
            $mkJob = function (string $state) use ($uid, $worker) {
                $ob = (int)self::$db->query("INSERT INTO outbox(event_type,payload) VALUES('label.print_requested','{}') RETURNING id")->fetchColumn();
                $job = (int)self::$db->query("INSERT INTO print_jobs(outbox_id,printer_id,label_uid) VALUES($ob,9701,'$uid') RETURNING id")->fetchColumn();
                if ($state !== 'queued') {
                    $att = (int)self::$db->query("INSERT INTO print_attempts(outbox_id,job_id,attempt_number,worker_id,lease_expires_at,lease_hard_deadline,acknowledged_on,ended_at,outcome) VALUES($ob,$job,1,$worker,now()-interval '1 hour',now()-interval '1 hour','send',now()-interval '1 hour','uncertain') RETURNING id")->fetchColumn();
                    self::$db->exec("UPDATE print_jobs SET current_attempt_id=$att, attempts_made=1" . ($state === 'authorized_retry' ? ', attempts_authorized=2' : '') . " WHERE id=$job");
                }
                return $job;
            };
            $jobs = ['queued' => $mkJob('queued'), 'claimed_uncertain' => $mkJob('claimed_uncertain'), 'authorized_retry' => $mkJob('authorized_retry')];
            $show = fn() => array_map(fn($id) => self::$db->query("SELECT cancelled_at IS NOT NULL AS cancelled, attempts_authorized, attempts_made, current_attempt_id IS NOT NULL AS has_attempt FROM print_jobs WHERE id=$id")->fetch(PDO::FETCH_ASSOC), $jobs);
            $before = $show();
            self::$stock->ConsumeProduct($p, 2, false, S::TRANSACTION_TYPE_CONSUME);
            $claimable = fn() => self::$db->query("SELECT j.id FROM print_jobs j WHERE j.outcome IS NULL AND j.cancelled_at IS NULL AND j.attempts_made<j.attempts_authorized AND NOT EXISTS (SELECT 1 FROM labels lb WHERE lb.uid=j.label_uid AND lb.retired_at IS NOT NULL) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            self::$out['S8_retirement_and_print_jobs'] = ['jobs_before' => $before, 'jobs_after_retirement' => $show(), 'job_ids' => $jobs,
                'claimable_ids_while_retired_by_existing_claim_predicate' => $claimable(),
                'finding' => 'queued job is cancelled; the claimed job and the authorized retry are left as they were; the retired-label predicate is what keeps the retry unclaimable'];
            self::$db->exec("UPDATE labels SET retired_at=NULL, target_id=$r, retirement_snapshot=NULL WHERE uid='$uid'"); // what a naive revival would do (the row id is not live; the CHECK does not look)
            self::$out['S8_retirement_and_print_jobs']['claimable_ids_after_a_naive_revival_UPDATE'] = $claimable();

            echo json_encode(self::$out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
        } finally {
            parent::tearDownAfterClass();
        }
    }
}
Adr37Baseline::executeProbe();
