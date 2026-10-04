<?php
// SPIKE ONLY. A reference model of the ADR-0036 rules, written as plain SQL against the real
// stock / stock_log tables plus the proposed stock_row_lots / stock_booking_lots tables.
// It is not production code and is not a candidate implementation: its purpose is to make
// the worked examples in the ADR executable, so every quantity quoted there was produced by
// running these rules rather than by hand.

final class Refusal extends RuntimeException {}

final class Ref
{
    const LOCK_CLASS = 0x76696353; // "vicS", STOCK_BOOKING_ADVISORY_LOCK_CLASS
    private int $depth = 0;
    private static int $sid = 0;
    public array $names = []; // lot booking id => display name

    public function __construct(public PDO $db, public int $p, public int $loc) {}

    public static function tol(float $a, float $b): float { return max(1e-9, 1e-12 * max(abs($a), abs($b))); }
    public static function eq(float $a, float $b): bool { return abs($a - $b) <= self::tol($a, $b); }

    public function q(string $sql, array $args = []): PDOStatement { $st = $this->db->prepare($sql); $st->execute($args); return $st; }
    public function one(string $sql, array $args = []): ?array { $r = $this->q($sql, $args)->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
    public function all(string $sql, array $args = []): array { return $this->q($sql, $args)->fetchAll(PDO::FETCH_ASSOC); }

    public function tx(callable $fn)
    {
        $outer = $this->depth === 0;
        if ($outer) {
            $this->db->beginTransaction();
            $this->q('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_CLASS, $this->p]);
        }
        $this->depth++;
        try { $r = $fn(); }
        catch (Throwable $e) { $this->depth--; if ($outer) { $this->db->rollBack(); } throw $e; }
        $this->depth--;
        if ($outer) { $this->db->commit(); }
        return $r;
    }

    // ---- low-level helpers -------------------------------------------------------------
    private function insertRow(array $row, ?int $id = null): int
    {
        $cols = ['product_id', 'amount', 'best_before_date', 'purchased_date', 'stock_id', 'price', 'open', 'opened_date', 'location_id', 'shopping_location_id', 'note'];
        $vals = []; foreach ($cols as $c) { $vals[] = $row[$c] ?? null; }
        if ($id !== null) { array_unshift($cols, 'id'); array_unshift($vals, $id); }
        $sql = 'INSERT INTO stock (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        if ($id !== null) { $sql .= ' ON CONFLICT (id) DO NOTHING'; }
        $sql .= ' RETURNING id';
        $r = $this->q($sql, $vals)->fetchColumn();
        if ($r === false) { return $this->insertRow($row, null); } // id taken: fresh id, as UndoBooking() does
        if ($id !== null) { // advance (never rewind) the identity sequence past an explicitly inserted id
            $seq = $this->q("SELECT pg_get_serial_sequence('stock','id')")->fetchColumn();
            $last = (int)$this->q("SELECT last_value FROM $seq")->fetchColumn();
            $this->q('SELECT setval(?, ?)', [$seq, max($last, (int)$r)]);
        }
        return (int)$r;
    }

    private function log(string $type, float $amount, array $row, string $tx, ?string $corr = null, ?int $rowId = null, array $over = []): int
    {
        $v = array_merge([
            'product_id' => $this->p, 'amount' => $amount, 'best_before_date' => $row['best_before_date'], 'purchased_date' => $row['purchased_date'],
            'stock_id' => $row['stock_id'], 'transaction_type' => $type, 'price' => $row['price'], 'opened_date' => $row['opened_date'],
            'location_id' => $row['location_id'], 'shopping_location_id' => $row['shopping_location_id'], 'note' => $row['note'],
            'transaction_id' => $tx, 'correlation_id' => $corr, 'stock_row_id' => $rowId, 'user_id' => 9000,
        ], $over);
        $cols = array_keys($v);
        return (int)$this->q('INSERT INTO stock_log (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') RETURNING id', array_values($v))->fetchColumn();
    }

    private function alloc(int $booking, array $draws, string $basis = 'recorded'): void
    {
        foreach ($draws as [$lot, $amt, $b]) {
            $this->q('INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis) VALUES (?,?,?,?)', [$booking, $lot, $amt, $b ?? $basis]);
        }
    }

    public function addLot(int $rid, ?int $lot, float $amt, string $basis): void
    {
        $basis = $lot === null ? 'unknown' : $basis;
        $this->q('INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis) VALUES (?,?,?,?)
                  ON CONFLICT (stock_row_id, lot_id) DO UPDATE SET amount = stock_row_lots.amount + EXCLUDED.amount', [$rid, $lot, $amt, $basis]);
    }

    public function rowLots(int $rid): array
    {
        return array_map(fn($r) => [$r['lot_id'] === null ? null : (int)$r['lot_id'], (float)$r['amount'], $r['basis']],
            $this->all('SELECT lot_id, amount, basis FROM stock_row_lots WHERE stock_row_id=? ORDER BY lot_id NULLS FIRST', [$rid]));
    }

    private function setLots(int $rid, array $lots): void
    {
        $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=?', [$rid]);
        foreach ($lots as [$l, $a, $b]) { if ($a > 1e-9) { $this->addLot($rid, $l, $a, $b); } }
    }

    /** Removes $amt of $lot from a row's contributions; the contribution row goes when nothing is left. */
    private function subLot(int $rid, ?int $lot, float $amt): void
    {
        $cur = (float)$this->q('SELECT amount FROM stock_row_lots WHERE stock_row_id=? AND lot_id IS NOT DISTINCT FROM ?', [$rid, $lot])->fetchColumn();
        if (self::eq($cur, $amt) || $cur < $amt) { $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=? AND lot_id IS NOT DISTINCT FROM ?', [$rid, $lot]); }
        else { $this->q('UPDATE stock_row_lots SET amount = amount - ? WHERE stock_row_id=? AND lot_id IS NOT DISTINCT FROM ?', [$amt, $rid, $lot]); }
    }

    /** Sets stock.amount to the sum of its contributions; deletes the row when nothing is left. */
    private function sync(int $rid): void
    {
        $sum = (float)$this->q('SELECT COALESCE(sum(amount),0) FROM stock_row_lots WHERE stock_row_id=?', [$rid])->fetchColumn();
        if ($sum <= 1e-9) { $this->q('DELETE FROM stock WHERE id=?', [$rid]); } else { $this->q('UPDATE stock SET amount=? WHERE id=?', [$sum, $rid]); }
    }

    /** Allocation policy: ascending lot id, the unattributed pool first. Mutates the row's lots. */
    private function fifoTake(int $rid, float $take): array
    {
        $draws = []; $remaining = $take;
        foreach ($this->rowLots($rid) as [$lot, $amt, $basis]) {
            if ($remaining <= self::tol($remaining, 0)) { break; }
            $t = min($amt, $remaining);
            if (self::eq($amt, $remaining)) { $t = $amt; }
            $draws[] = [$lot, $t, $lot === null ? 'recorded' : $basis];
            $this->subLot($rid, $lot, $t);
            $remaining -= $t;
        }
        $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=? AND amount <= 1e-9', [$rid]);
        if (!self::eq($remaining, 0.0)) { throw new Refusal("row $rid holds less than $take"); }
        return $draws;
    }

    /** Makes sure a row has contributions that add up; classifies it lazily otherwise (never invents). */
    private function ensureTracked(int $rid): void
    {
        $row = $this->one('SELECT amount FROM stock WHERE id=?', [$rid]);
        $sum = (float)$this->q('SELECT COALESCE(sum(amount),0) FROM stock_row_lots WHERE stock_row_id=?', [$rid])->fetchColumn();
        if ($row !== null && self::eq($sum, (float)$row['amount'])) { return; }
        $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=?', [$rid]);
        $this->q('SELECT * FROM spike_backfill(?)', [$this->p])->fetchAll();
        $sum = (float)$this->q('SELECT COALESCE(sum(amount),0) FROM stock_row_lots WHERE stock_row_id=?', [$rid])->fetchColumn();
        if (!self::eq($sum, (float)$row['amount'])) { // still not exact: pool, never a guess
            $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=?', [$rid]);
            $this->addLot($rid, null, (float)$row['amount'], 'unknown');
        }
    }

    private function liveRows(string $order): array
    {
        return $this->all("SELECT * FROM stock WHERE product_id=? AND amount > 0 ORDER BY $order", [$this->p]);
    }

    // ---- writers -----------------------------------------------------------------------
    public function purchase(float $q, array $a = [], string $name = ''): array
    {
        return $this->tx(function () use ($q, $a, $name) {
            $row = array_merge(['product_id' => $this->p, 'best_before_date' => '2999-12-31', 'purchased_date' => '2026-10-01', 'stock_id' => 's' . (++self::$sid),
                'price' => 1.0, 'open' => 0, 'opened_date' => null, 'location_id' => $this->loc, 'shopping_location_id' => null, 'note' => null], $a);
            $row['amount'] = $q;
            $tx = 't' . self::$sid;
            $b = $this->log('purchase', $q, $row, $tx);
            $rid = $this->insertRow($row);
            $this->alloc($b, [[$b, $q, 'recorded']]);
            $this->addLot($rid, $b, $q, 'recorded');
            if ($name !== '') { $this->names[$b] = $name; }
            return ['booking' => $b, 'row' => $rid, 'tx' => $tx];
        });
    }

    public function merge(bool $interruptAfterFirstGroup = false): int
    {
        return $this->tx(function () use ($interruptAfterFirstGroup) {
            $groups = $this->all('SELECT * FROM stock_splits WHERE product_id=? ORDER BY id_to_keep', [$this->p]);
            $ids = []; foreach ($groups as $g) { foreach (explode(',', $g['id_group']) as $i) { $ids[] = (int)$i; } }
            sort($ids);
            if ($ids) { $this->q('SELECT id FROM stock WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE')->fetchAll(); }
            $groups = $this->all('SELECT * FROM stock_splits WHERE product_id=? ORDER BY id_to_keep', [$this->p]); // re-read under the locks
            $merged = 0;
            foreach ($groups as $g) {
                $keep = (int)$g['id_to_keep'];
                $members = array_map('intval', explode(',', $g['id_group']));
                foreach ($members as $m) { $this->ensureTracked($m); }
                foreach ($members as $m) {
                    if ($m === $keep) { continue; }
                    $this->q('INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis)
                              SELECT ?, lot_id, amount, basis FROM stock_row_lots WHERE stock_row_id=?
                              ON CONFLICT (stock_row_id, lot_id) DO UPDATE SET amount = stock_row_lots.amount + EXCLUDED.amount', [$keep, $m]);
                    $this->q('DELETE FROM stock WHERE id=?', [$m]); // contributions of $m cascade
                }
                $this->sync($keep);
                $merged++;
                if ($interruptAfterFirstGroup) { throw new RuntimeException('injected interruption after the first group'); }
            }
            return $merged;
        });
    }

    /** Runs merge() inside a transaction the caller already opened (and locked). */
    public function merge2inTx(): int { $this->depth++; try { return $this->merge(); } finally { $this->depth--; } }

    public function consume(float $q, string $type = 'consume', ?int $locationId = null): string
    {
        return $this->tx(function () use ($q, $type, $locationId) {
            $tx = 'c' . (++self::$sid);
            $rows = $this->liveRows('open DESC, best_before_date, purchased_date, id');
            if ($locationId !== null) { $rows = array_values(array_filter($rows, fn($r) => (int)$r['location_id'] === $locationId)); }
            $total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
            if ($q > $total && !self::eq($q, $total)) { throw new Refusal('Amount to be consumed cannot be > current stock amount'); }
            $remaining = $q;
            foreach ($rows as $row) {
                if (self::eq($remaining, 0.0) || $remaining <= 0) { break; }
                $rid = (int)$row['id']; $this->ensureTracked($rid);
                $amt = (float)$row['amount'];
                $take = $amt <= $remaining || self::eq($amt, $remaining) ? $amt : $remaining;
                $draws = $this->fifoTake($rid, $take);
                $b = $this->log($type, -$take, $row, $tx, null, $rid);
                $this->alloc($b, array_map(fn($d) => [$d[0], -$d[1], $d[2]], $draws));
                $this->sync($rid);
                $remaining -= $take;
            }
            return $tx;
        });
    }

    public function open(float $q): string
    {
        return $this->tx(function () use ($q) {
            $tx = 'o' . (++self::$sid);
            $rows = $this->liveRows('best_before_date, purchased_date, id');
            $remaining = $q;
            foreach ($rows as $row) {
                if ((int)$row['open'] === 1) { continue; }
                if (self::eq($remaining, 0.0) || $remaining <= 0) { break; }
                $rid = (int)$row['id']; $this->ensureTracked($rid);
                $amt = (float)$row['amount'];
                $take = $amt <= $remaining || self::eq($amt, $remaining) ? $amt : $remaining;
                $date = '2026-10-02';
                if ($take >= $amt - 1e-9) {
                    $draws = array_map(fn($l) => [$l[0], $l[1], $l[2]], $this->rowLots($rid));
                    $b = $this->log('product-opened', $take, $row, $tx, null, $rid, ['opened_date' => $date]);
                    $this->alloc($b, $draws);
                    $this->q('UPDATE stock SET open=1, opened_date=? WHERE id=?', [$date, $rid]);
                } else {
                    $before = $this->rowLots($rid);
                    $draws = $this->fifoTake($rid, $take);       // leaves the remainder's lots on $rid
                    $rest = $this->rowLots($rid);
                    $b = $this->log('product-opened', $take, $row, $tx, null, $rid, ['opened_date' => $date]);
                    $this->alloc($b, $draws);
                    $newSid = 's' . (++self::$sid);
                    $remRow = array_merge($row, ['stock_id' => $newSid, 'amount' => $amt - $take]);
                    $remId = $this->insertRow($remRow);
                    $this->setLots($remId, $rest);
                    $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=?', [$rid]);
                    $this->setLots($rid, $draws);
                    $this->q('UPDATE stock SET amount=?, open=1, opened_date=? WHERE id=?', [$take, $date, $rid]);
                    $root = $this->one('SELECT origin_stock_id FROM stock_entry_origins WHERE stock_id=?', [$row['stock_id']])['origin_stock_id'] ?? $row['stock_id'];
                    $this->q('INSERT INTO stock_entry_origins (stock_id, origin_stock_id) VALUES (?,?)', [$newSid, $root]);
                }
                $remaining -= $take;
            }
            return $tx;
        });
    }

    public function transfer(float $q, int $to): string
    {
        return $this->tx(function () use ($q, $to) {
            $tx = 'x' . (++self::$sid);
            $rows = array_values(array_filter($this->liveRows('open DESC, best_before_date, purchased_date, id'), fn($r) => (int)$r['location_id'] === $this->loc));
            $remaining = $q;
            foreach ($rows as $row) {
                if (self::eq($remaining, 0.0) || $remaining <= 0) { break; }
                $rid = (int)$row['id']; $this->ensureTracked($rid);
                $amt = (float)$row['amount'];
                $take = $amt <= $remaining || self::eq($amt, $remaining) ? $amt : $remaining;
                $corr = 'k' . (++self::$sid);
                if ($take >= $amt - 1e-9) {
                    $lots = $this->rowLots($rid);
                    $from = $this->log('transfer_from', -$take, $row, $tx, $corr, $rid);
                    $this->alloc($from, array_map(fn($l) => [$l[0], -$l[1], $l[2]], $lots));
                    $toRow = array_merge($row, ['location_id' => $to]);
                    $toB = $this->log('transfer_to', $take, $toRow, $tx, $corr, $rid);
                    $this->alloc($toB, $lots);
                    $this->q('UPDATE stock SET location_id=? WHERE id=?', [$to, $rid]);
                } else {
                    $draws = $this->fifoTake($rid, $take);
                    $from = $this->log('transfer_from', -$take, $row, $tx, $corr, $rid);
                    $this->alloc($from, array_map(fn($d) => [$d[0], -$d[1], $d[2]], $draws));
                    $this->sync($rid);
                    $newRow = array_merge($row, ['location_id' => $to, 'amount' => $take]); // same stock_id, as TransferProduct() does
                    $newId = $this->insertRow($newRow);
                    $this->setLots($newId, $draws);
                    $toB = $this->log('transfer_to', $take, $newRow, $tx, $corr, $newId);
                    $this->alloc($toB, $draws);
                }
                $remaining -= $take;
            }
            return $tx;
        });
    }

    /** $attrs may override price / note / best_before_date. An increase is a new lot: the edit-new booking. */
    public function edit(int $rid, float $newAmount, array $attrs = [], string $name = ''): array
    {
        return $this->tx(function () use ($rid, $newAmount, $attrs, $name) {
            $this->ensureTracked($rid);
            $row = $this->one('SELECT * FROM stock WHERE id=?', [$rid]);
            if ($row === null) { throw new Refusal('Stock does not exist'); }
            $tx = 'e' . (++self::$sid); $corr = 'k' . (++self::$sid);
            $old = (float)$row['amount'];
            $snapshotBefore = $this->rowLots($rid);
            $oldB = $this->log('stock-edit-old', $old, $row, $tx, $corr, $rid);
            $this->alloc($oldB, $snapshotBefore);
            $newRow = array_merge($row, $attrs, ['amount' => $newAmount]);
            $newB = $this->log('stock-edit-new', $newAmount, $newRow, $tx, $corr, $rid);
            if ($name !== '') { $this->names[$newB] = $name; }
            if (self::CompareLess($newAmount, $old)) { $this->fifoTake($rid, $old - $newAmount); }
            elseif (self::CompareLess($old, $newAmount)) { $this->addLot($rid, $newB, $newAmount - $old, 'recorded'); }
            $cols = array_intersect_key($attrs, array_flip(['price', 'note', 'best_before_date', 'purchased_date']));
            $sets = ['amount=?']; $args = [$newAmount];
            foreach ($cols as $c => $v) { $sets[] = "$c=?"; $args[] = $v; }
            $args[] = $rid;
            $this->q('UPDATE stock SET ' . implode(',', $sets) . ' WHERE id=?', $args);
            $this->alloc($newB, $this->rowLots($rid));
            return ['old' => $oldB, 'new' => $newB, 'tx' => $tx];
        });
    }

    private static function CompareLess(float $a, float $b): bool { return $b - $a > self::tol($a, $b); }

    // ---- undo ----------------------------------------------------------------------------
    public function undo(int $id): void
    {
        $this->tx(function () use ($id) {
            $b = $this->one('SELECT * FROM stock_log WHERE id=? AND undone=0', [$id]);
            if ($b === null) { throw new Refusal('Booking does not exist or was already undone'); }
            $set = [$id];
            if ($b['correlation_id']) {
                $set = array_map('intval', array_column($this->all('SELECT id FROM stock_log WHERE correlation_id=? AND undone=0 ORDER BY id DESC', [$b['correlation_id']]), 'id'));
            }
            foreach ($set as $one) { $this->undoOne($one, $set); }
        });
    }

    private function mark(int $id): void { $this->q('UPDATE stock_log SET undone=1, undone_timestamp=now() WHERE id=?', [$id]); }

    private function undoOne(int $id, array $set): void
    {
        $b = $this->one('SELECT * FROM stock_log WHERE id=? AND undone=0', [$id]);
        if ($b === null) { return; } // already undone with its pair
        $allocs = $this->all('SELECT lot_id, amount, basis FROM stock_booking_lots WHERE booking_id=? ORDER BY lot_id NULLS FIRST', [$id]);
        if (!$allocs) { throw new Refusal('Booking has no lineage record: the legacy undo rules apply to it'); }
        foreach ($allocs as $a) {
            if ($a['basis'] === 'unknown') { throw new Refusal('Booking cannot be undone: its units were merged before lineage was tracked and cannot be told apart'); }
        }
        // Dependency: a later live booking that touched one of the same lots.
        foreach ($allocs as $a) {
            $dep = $this->one('SELECT x.id FROM stock_log x JOIN stock_booking_lots bl ON bl.booking_id = x.id
                               WHERE x.undone=0 AND x.id > ? AND NOT (x.id = ANY(?::int[])) AND bl.lot_id IS NOT DISTINCT FROM ? LIMIT 1',
                [$id, '{' . implode(',', $set) . '}', $a['lot_id']]);
            if ($dep) { throw new Refusal('Booking has subsequent dependent bookings (booking ' . $dep['id'] . ' touched the same lot), undo not possible'); }
        }
        $type = $b['transaction_type'];
        $positive = $b['amount'] > 0;
        if ($type === 'purchase' || $type === 'self-production' || ($type === 'inventory-correction' && $positive)) { $this->undoAddition($b, $allocs); }
        elseif ($type === 'consume' || ($type === 'inventory-correction' && !$positive)) { $this->undoConsume($b, $allocs); }
        elseif ($type === 'product-opened') { $this->undoOpen($b, $allocs); }
        elseif ($type === 'transfer_to') { $this->undoTransfer($b, $allocs); }
        elseif ($type === 'transfer_from') { return; } // handled with its pair
        elseif ($type === 'stock-edit-new') { $this->undoEdit($b, $allocs); }
        elseif ($type === 'stock-edit-old') { return; }
        else { throw new Refusal('This booking cannot be undone'); }
    }

    private function undoAddition(array $b, array $allocs): void
    {
        $lot = (int)$b['id'];
        $held = $this->all('SELECT stock_row_id, amount FROM stock_row_lots WHERE lot_id=?', [$lot]);
        $total = array_sum(array_map(fn($r) => (float)$r['amount'], $held));
        if (!self::eq($total, (float)$allocs[0]['amount'])) { throw new Refusal('Booking cannot be undone: the units it added are no longer all in stock'); }
        foreach ($held as $h) {
            $this->q('DELETE FROM stock_row_lots WHERE stock_row_id=? AND lot_id=?', [$h['stock_row_id'], $lot]);
            $this->sync((int)$h['stock_row_id']);
        }
        $this->mark((int)$b['id']);
    }

    private function snapshotRow(array $b, array $over = []): array
    {
        return array_merge(['product_id' => $b['product_id'], 'best_before_date' => $b['best_before_date'], 'purchased_date' => $b['purchased_date'],
            'stock_id' => $b['stock_id'], 'price' => $b['price'], 'open' => $b['opened_date'] !== null ? 1 : 0, 'opened_date' => $b['opened_date'],
            'location_id' => $b['location_id'], 'shopping_location_id' => $b['shopping_location_id'], 'note' => $b['note']], $over);
    }

    private function undoConsume(array $b, array $allocs): void
    {
        $sum = array_sum(array_map(fn($a) => -(float)$a['amount'], $allocs));
        $row = $this->snapshotRow($b, ['amount' => $sum]);
        $want = $b['stock_row_id'] === null ? null : (int)$b['stock_row_id'];
        $exists = $want !== null && $this->one('SELECT 1 FROM stock WHERE id=?', [$want]) !== null;
        $rid = $this->insertRow($row, $exists ? null : $want); // a whole take rebuilds under its own id
        foreach ($allocs as $a) { $this->addLot($rid, $a['lot_id'] === null ? null : (int)$a['lot_id'], -(float)$a['amount'], $a['basis']); }
        $this->mark((int)$b['id']);
    }

    /**
     * Finds where a booking's units are NOW, by lot - never by stock.id or stock.stock_id. Returns
     * [row id => [[lot, amount], ...]] taking each lot's recorded amount from the rows (ascending id)
     * that satisfy the state the booking produced. Refuses when the rows hold less than recorded.
     */
    private function gather(array $allocs, callable $state): array
    {
        $plan = [];
        foreach ($allocs as $a) {
            $lot = $a['lot_id'] === null ? null : (int)$a['lot_id']; $remaining = abs((float)$a['amount']);
            $rows = $this->all('SELECT s.*, rl.amount AS held FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE rl.lot_id IS NOT DISTINCT FROM ? ORDER BY s.id', [$lot]);
            foreach ($rows as $row) {
                if (!$state($row) || $remaining <= 0 || self::eq($remaining, 0.0)) { continue; }
                $t = min($remaining, (float)$row['held']);
                $plan[(int)$row['id']][] = [$lot, $t, $a['basis']];
                $remaining -= $t;
            }
            if ($remaining > 0 && !self::eq($remaining, 0.0)) { throw new Refusal('Booking cannot be undone: the rows no longer hold the units this booking touched in the state it produced'); }
        }
        return $plan;
    }

    /** True when the plan is one row whose contributions are exactly the plan (so the row can change state in place). */
    private function wholeRow(array $plan): ?int
    {
        if (count($plan) !== 1) { return null; }
        $rid = array_key_first($plan); $cur = $this->rowLots($rid);
        if (count($cur) !== count($plan[$rid])) { return null; }
        foreach ($plan[$rid] as [$lot, $amt]) {
            $found = false; foreach ($cur as [$l, $a]) { if ($l === $lot && self::eq($a, $amt)) { $found = true; } }
            if (!$found) { return null; }
        }
        return $rid;
    }

    /** Removes the planned units from their rows and returns the lots taken, ready for a new row. */
    private function detach(array $plan): array
    {
        $lots = [];
        foreach ($plan as $rid => $items) {
            foreach ($items as [$lot, $amt, $basis]) { $this->subLot($rid, $lot, $amt); $lots[] = [$lot, $amt, $basis]; }
            $this->sync($rid);
        }
        return $lots;
    }

    private function undoOpen(array $b, array $allocs): void
    {
        $plan = $this->gather($allocs, fn($r) => (int)$r['open'] === 1);
        $whole = $this->wholeRow($plan);
        if ($whole !== null) {
            $this->q('UPDATE stock SET open=0, opened_date=NULL, best_before_date=? WHERE id=?', [$b['best_before_date'], $whole]);
        } else {
            $first = $this->one('SELECT * FROM stock WHERE id=?', [array_key_first($plan)]);
            $lots = $this->detach($plan);
            $new = $this->insertRow(array_merge($first, ['stock_id' => $b['stock_id'], 'amount' => array_sum(array_column($lots, 1)), 'open' => 0, 'opened_date' => null, 'best_before_date' => $b['best_before_date']]));
            foreach ($lots as [$l, $a, $bs]) { $this->addLot($new, $l, $a, $bs); }
        }
        $this->mark((int)$b['id']);
    }

    private function undoTransfer(array $to, array $allocs): void
    {
        $from = $this->one("SELECT * FROM stock_log WHERE correlation_id=? AND transaction_type='transfer_from'", [$to['correlation_id']]);
        $plan = $this->gather($allocs, fn($r) => (int)$r['location_id'] === (int)$to['location_id']);
        $whole = $this->wholeRow($plan);
        $f = (int)$from['stock_row_id'];
        if ($whole !== null && $whole === $f) { // a whole-row transfer: relocate in place, keeping the row id
            $this->q('UPDATE stock SET location_id=?, best_before_date=? WHERE id=?', [$from['location_id'], $from['best_before_date'], $whole]);
        } else {
            $lots = $this->detach($plan);
            $sum = array_sum(array_column($lots, 1));
            $home = $this->one('SELECT * FROM stock WHERE id=? AND location_id IS NOT DISTINCT FROM ?', [$f, $from['location_id']]);
            if ($home !== null) { $dest = $f; $this->q('UPDATE stock SET amount = amount + ? WHERE id=?', [$sum, $dest]); }
            else { $dest = $this->insertRow($this->snapshotRow($from, ['amount' => $sum]), $f); }
            foreach ($lots as [$l, $a, $bs]) { $this->addLot($dest, $l, $a, $bs); }
        }
        $this->mark((int)$to['id']); $this->mark((int)$from['id']);
    }

    private function undoEdit(array $new, array $newAllocs): void
    {
        $old = $this->one("SELECT * FROM stock_log WHERE correlation_id=? AND transaction_type='stock-edit-old'", [$new['correlation_id']]);
        $oldAllocs = $this->all('SELECT lot_id, amount, basis FROM stock_booking_lots WHERE booking_id=?', [$old['id']]);
        $plan = $this->gather($newAllocs, fn($r) => true);
        $whole = $this->wholeRow($plan);
        $restore = ['best_before_date' => $old['best_before_date'], 'purchased_date' => $old['purchased_date'], 'price' => $old['price'], 'note' => $old['note'],
            'location_id' => $old['location_id'], 'open' => $old['opened_date'] !== null ? 1 : 0, 'opened_date' => $old['opened_date']];
        $oldLots = array_map(fn($a) => [$a['lot_id'] === null ? null : (int)$a['lot_id'], (float)$a['amount'], $a['basis']], $oldAllocs);
        if ($whole !== null) {
            $this->setLots($whole, $oldLots);
            $sets = []; $args = []; foreach ($restore as $c => $v) { $sets[] = "$c=?"; $args[] = $v; }
            $args[] = $whole;
            $this->q('UPDATE stock SET ' . implode(',', $sets) . ' WHERE id=?', $args);
            $this->sync($whole);
        } else { // other lots share the row: the edit's own units leave it, in a row with the restored attributes
            $this->detach($plan);
            $nid = $this->insertRow($this->snapshotRow($old, ['amount' => array_sum(array_column($oldLots, 1))]));
            foreach ($oldLots as [$l, $a, $bs]) { $this->addLot($nid, $l, $a, $bs); }
        }
        $this->mark((int)$new['id']); $this->mark((int)$old['id']);
    }

    // ---- observation -------------------------------------------------------------------
    public function lotName(?int $lot): string { return $lot === null ? 'pool' : ($this->names[$lot] ?? "#$lot"); }

    public function view(): array
    {
        $rows = [];
        foreach ($this->all('SELECT * FROM stock WHERE product_id=? ORDER BY id', [$this->p]) as $r) {
            $lots = []; foreach ($this->rowLots((int)$r['id']) as [$l, $a, $basis]) { $lots[$this->lotName($l)] = $a; }
            $rows[] = ['row' => (int)$r['id'], 'stock_id' => $r['stock_id'], 'loc' => (int)$r['location_id'], 'open' => (int)$r['open'], 'price' => (float)$r['price'], 'amount' => (float)$r['amount'], 'lots' => $lots];
        }
        $log = [];
        foreach ($this->all('SELECT * FROM stock_log WHERE product_id=? ORDER BY id', [$this->p]) as $l) {
            $al = []; foreach ($this->all('SELECT lot_id, amount, basis FROM stock_booking_lots WHERE booking_id=? ORDER BY lot_id NULLS FIRST', [$l['id']]) as $a) {
                $al[$this->lotName($a['lot_id'] === null ? null : (int)$a['lot_id']) . ($a['basis'] === 'recorded' ? '' : '(' . $a['basis'] . ')')] = (float)$a['amount'];
            }
            $log[] = ['id' => (int)$l['id'], 'type' => $l['transaction_type'], 'amount' => (float)$l['amount'], 'stock_id' => $l['stock_id'], 'row' => $l['stock_row_id'] === null ? null : (int)$l['stock_row_id'],
                'undone' => (int)$l['undone'], 'allocs' => $al ?: null];
        }
        return ['stock' => $rows, 'ledger' => $log];
    }

    /** Returns the list of violated invariants (empty = holds). */
    public function invariants(): array
    {
        $v = [];
        foreach ($this->all('SELECT s.id, s.amount, COALESCE(sum(rl.amount),0) AS held FROM stock s LEFT JOIN stock_row_lots rl ON rl.stock_row_id = s.id WHERE s.product_id=? GROUP BY s.id, s.amount', [$this->p]) as $r) {
            if (!self::eq((float)$r['amount'], (float)$r['held'])) { $v[] = "I1 row {$r['id']} amount {$r['amount']} != contributions {$r['held']}"; }
        }
        foreach ($this->all('SELECT l.id, l.amount, sum(bl.amount) AS s FROM stock_log l JOIN stock_booking_lots bl ON bl.booking_id=l.id WHERE l.product_id=? GROUP BY l.id, l.amount', [$this->p]) as $r) {
            if (!self::eq((float)$r['amount'], (float)$r['s'])) { $v[] = "I2 booking {$r['id']} amount {$r['amount']} != allocations {$r['s']}"; }
        }
        $i3 = $this->all("WITH net AS (SELECT bl.lot_id, SUM(CASE l.transaction_type WHEN 'stock-edit-old' THEN -bl.amount WHEN 'product-opened' THEN 0 WHEN 'stock-measured-new' THEN 0 WHEN 'stock-measured-old' THEN 0 ELSE bl.amount END) AS n
                FROM stock_booking_lots bl JOIN stock_log l ON l.id = bl.booking_id WHERE l.undone=0 AND l.product_id=? AND bl.lot_id IS NOT NULL AND bl.basis <> 'unknown' GROUP BY bl.lot_id),
            held AS (SELECT rl.lot_id, SUM(rl.amount) AS h FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id WHERE s.product_id=? AND rl.lot_id IS NOT NULL GROUP BY rl.lot_id)
            SELECT n.lot_id, n.n, COALESCE(h.h,0) AS h FROM net n LEFT JOIN held h ON h.lot_id = n.lot_id", [$this->p, $this->p]);
        foreach ($i3 as $r) { if (!self::eq((float)$r['n'], (float)$r['h'])) { $v[] = "I3 lot {$r['lot_id']} ledger {$r['n']} != held {$r['h']}"; } }
        $bal = (float)$this->q("SELECT COALESCE(sum(CASE transaction_type WHEN 'purchase' THEN amount WHEN 'self-production' THEN amount WHEN 'inventory-correction' THEN amount WHEN 'consume' THEN amount
                WHEN 'stock-edit-new' THEN amount WHEN 'stock-edit-old' THEN -amount ELSE 0 END),0) FROM stock_log WHERE product_id=? AND undone=0", [$this->p])->fetchColumn();
        $stock = (float)$this->q('SELECT COALESCE(sum(amount),0) FROM stock WHERE product_id=?', [$this->p])->fetchColumn();
        if (!self::eq($bal, $stock)) { $v[] = "I4 stock $stock != live ledger $bal"; }
        return $v;
    }

    /** Compact one-line rendering of view() for the ADR tables. */
    public static function render(array $view): array
    {
        $rows = array_map(function ($r) {
            $lots = implode('+', array_map(fn($k, $a) => "$k:" . rtrim(rtrim(sprintf('%.3f', $a), '0'), '.'), array_keys($r['lots']), $r['lots']));
            return "row {$r['row']} [{$r['stock_id']}, loc {$r['loc']}, price " . rtrim(rtrim(sprintf('%.2f', $r['price']), '0'), '.') . ($r['open'] ? ', open' : '') . "] = " . rtrim(rtrim(sprintf('%.3f', $r['amount']), '0'), '.') . " ($lots)";
        }, $view['stock']);
        $log = array_map(function ($l) {
            $al = $l['allocs'] ? implode(',', array_map(fn($k, $a) => "$k:" . rtrim(rtrim(sprintf('%.3f', $a), '0'), '.'), array_keys($l['allocs']), $l['allocs'])) : '-';
            return "#{$l['id']} {$l['type']} " . rtrim(rtrim(sprintf('%.3f', $l['amount']), '0'), '.') . " [{$l['stock_id']}" . ($l['row'] !== null ? ", row {$l['row']}" : '') . "]" . ($l['undone'] ? ' UNDONE' : '') . " allocs {$al}";
        }, $view['ledger']);
        return ['stock' => $rows, 'ledger' => $log];
    }
}
