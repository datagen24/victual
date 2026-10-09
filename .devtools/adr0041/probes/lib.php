<?php
// SPIKE ONLY. Shared by the ADR-0041 probes. Nothing here is a candidate implementation.
//
// B41Env gives a probe a migrated throwaway schema (the PgsqlSchemaTestCase path) and lets
// child processes attach to that schema with the real application services, the way
// tests/Pgsql/rbac-subprocess-helper.php does.
require_once '/app/tests/bootstrap.php';

use Victual\Services\DatabaseService;
use Victual\Services\StockService as S;
use Victual\Tests\Support\PgsqlSchemaTestCase;

class B41Env extends PgsqlSchemaTestCase
{
    public static function create(): PDO
    {
        static::setUpBeforeClass();
        $pdo = self::Pdo();
        foreach ([9000, 9001] as $u) {
            $pdo->exec("INSERT INTO users(id,username,password) VALUES($u,'b41-$u','fixture')");
            $pdo->exec("INSERT INTO user_permissions(user_id,permission_id) SELECT $u,id FROM permission_hierarchy WHERE name='ADMIN'");
        }
        $pdo->exec(<<<'SQL'
CREATE TABLE b41_events (
  id BIGSERIAL PRIMARY KEY, user_id INTEGER NOT NULL, source_system TEXT NOT NULL, source_event_id TEXT NOT NULL,
  payload_hash TEXT NOT NULL, source_updated_at TIMESTAMPTZ, state TEXT NOT NULL DEFAULT 'received', reason TEXT,
  transaction_id TEXT, revision INTEGER NOT NULL DEFAULT 1, payload JSONB NOT NULL,
  UNIQUE (user_id, source_system, source_event_id));
CREATE TABLE b41_event_lines (id BIGSERIAL PRIMARY KEY, event_id BIGINT NOT NULL REFERENCES b41_events(id),
  product_id INTEGER NOT NULL, amount DOUBLE PRECISION NOT NULL, location_id INTEGER);
CREATE TABLE b41_recipes (id INTEGER PRIMARY KEY);
INSERT INTO b41_recipes SELECT generate_series(1, 8);
SQL);
        return $pdo;
    }

    public static function schemaName(): string { return self::Schema(); }

    public static function destroy(): void { parent::tearDownAfterClass(); }

    public static function attach(string $schema): PDO
    {
        static::Boot();
        $pdo = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
            getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET search_path TO ' . $schema . ', public');
        DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
        (new ReflectionProperty(DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
        (new ReflectionProperty(DatabaseService::class, 'DbConnection'))->setValue(null, null);
        static::ResetSchemaBoundState();
        return $pdo;
    }
}

const B41_BARRIER_CLASS = 41410000;

function b41_log(string $m): void { fwrite(STDERR, '[' . date('H:i:s') . "] $m\n"); }

function b41_location(PDO $pdo, string $name): int
{
    return (int)$pdo->query("INSERT INTO locations(name) VALUES('" . $name . "') RETURNING id")->fetchColumn();
}

function b41_product(PDO $pdo, string $name, int $locationId): int
{
    $st = $pdo->prepare('INSERT INTO products(name,location_id,qu_id_stock,qu_id_purchase,qu_id_consume,qu_id_price) VALUES (?,?,2,2,2,2) RETURNING id');
    $st->execute([$name, $locationId]);
    return (int)$st->fetchColumn();
}

/** Purchase through the real service; returns the transaction id. */
function b41_add(int $product, float $qty, int $locationId, string $best = '2999-12-31'): string
{
    return S::GetInstance()->AddProduct($product, $qty, $best, S::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, $locationId);
}

function b41_stock_amount(PDO $pdo, int $product): float
{
    return (float)$pdo->query("SELECT COALESCE(sum(amount),0) FROM stock WHERE product_id=$product")->fetchColumn();
}

function b41_lineage_violations(PDO $pdo): int
{
    return (int)$pdo->query('SELECT count(*) FROM stock_lineage_violations(NULL)')->fetchColumn();
}

/** Hash of the stock and stock_log content for the given products, for "unchanged" comparisons. */
function b41_ledger_hash(PDO $pdo, array $products): string
{
    $ids = implode(',', array_map('intval', $products));
    $a = $pdo->query("SELECT string_agg(t::text, '|' ORDER BY id) FROM (SELECT id,product_id,amount,location_id,stock_id,open FROM stock WHERE product_id IN ($ids)) t")->fetchColumn();
    $b = $pdo->query("SELECT string_agg(t::text, '|' ORDER BY id) FROM (SELECT id,product_id,amount,transaction_id,transaction_type,undone,used_date FROM stock_log WHERE product_id IN ($ids)) t")->fetchColumn();
    return substr(hash('sha256', $a . '#' . $b), 0, 16);
}

/** The ADR's payload hash: status, medication_ref, quantity, unit_label, occurred_at, location_id. */
function b41_payload_hash(array $p): string
{
    return hash('sha256', json_encode([
        $p['status'] ?? null, $p['medication_ref'] ?? null, $p['quantity'] ?? null,
        $p['unit_label'] ?? null, $p['occurred_at'] ?? null, $p['location_id'] ?? null,
    ]));
}

/** ADR rule 5, second table: what a request does against a stored version. */
function b41_ordering(string $storedHash, ?string $storedSua, string $newHash, ?string $newSua): string
{
    if ($storedHash === $newHash) { return 'replay'; }
    if ($storedSua !== null && $newSua !== null) {
        $a = strtotime($newSua); $b = strtotime($storedSua);
        if ($a < $b) { return 'stale'; }
        if ($a === $b) { return 'conflict_409'; }
    }
    return 'correction';
}

function b41_err(Throwable $e): array
{
    $sqlstate = $e instanceof PDOException ? ($e->errorInfo[0] ?? (string)$e->getCode()) : 'APP';
    if ($sqlstate === 'APP' && stripos($e->getMessage(), 'deadlock detected') !== false) { $sqlstate = '40P01'; }
    return ['class' => get_class($e), 'code' => $e->getCode(), 'sqlstate' => $sqlstate, 'message' => mb_substr($e->getMessage(), 0, 160)];
}

/** Message-based classification, used only to label the needs_review reason in the probe. */
function b41_reason(Throwable $e): string
{
    return str_contains($e->getMessage(), 'cannot be > current stock amount') ? 'insufficient_stock' : 'booking_error';
}

function b41_jit(array $req, string $point): void
{
    $max = (int)($req['jitter_us'][$point] ?? 0);
    if ($max > 0) { usleep(mt_rand(0, $max)); }
}

/**
 * ADR rule 5 for one request: transaction 1 inserts and commits, transaction 2 locks the event
 * row, the recipe row and the products (ascending) and books through ConsumeProduct() with one
 * shared transaction id, transaction 3 records a failure. $req keys: user, sys, eid, payload,
 * sua, lines [[product_id, amount, location_id|null]], recipe (bool), hooks (crash_after_t1,
 * die_in_t2), jitter_us, seed.
 */
function b41_submit(array $req): array
{
    $db = DatabaseService::GetInstance();
    $stock = S::GetInstance();
    $t0 = microtime(true);
    $hash = b41_payload_hash($req['payload']);

    $inserted = $db->InTransaction(function () use ($req, $hash, $db) {
        $st = $db->GetDbConnectionRaw()->prepare('INSERT INTO b41_events(user_id,source_system,source_event_id,payload_hash,source_updated_at,payload)
            VALUES (?,?,?,?,?,?::jsonb) ON CONFLICT (user_id,source_system,source_event_id) DO NOTHING RETURNING id');
        $st->execute([$req['user'], $req['sys'], $req['eid'], $hash, $req['sua'] ?? null, json_encode($req['payload'])]);
        return $st->fetchColumn() !== false;
    });
    if (!empty($req['hooks']['crash_after_t1'])) {
        echo json_encode(['marker' => 'T1_COMMITTED']) . "\n"; fflush(STDOUT);
        posix_kill(getmypid(), 9);
        sleep(60);
    }
    b41_jit($req, 'after_t1');

    try {
        $out = $db->InTransaction(function () use ($req, $db, $stock) {
            $pdo = $db->GetDbConnectionRaw();
            $st = $pdo->prepare('SELECT id,state,reason,transaction_id,revision FROM b41_events WHERE user_id=? AND source_system=? AND source_event_id=? FOR UPDATE');
            $st->execute([$req['user'], $req['sys'], $req['eid']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new RuntimeException('event row not visible after transaction 1'); }
            b41_jit($req, 'after_lock');
            if ($row['state'] === 'booked') {
                return ['booked_now' => false, 'state' => 'booked', 'transaction_id' => $row['transaction_id'], 'event_id' => (int)$row['id']];
            }
            if (!empty($req['recipe'])) { $pdo->query('SELECT id FROM b41_recipes WHERE id=' . (int)($req['recipe_id'] ?? 1) . ' ' . (($req['recipe_lock'] ?? 'share') === 'update' ? 'FOR UPDATE' : 'FOR SHARE')); }
            $lines = $req['lines'];
            usort($lines, fn($a, $b) => $a[0] <=> $b[0]);
            $db->LockProductsStock(array_map(fn($l) => $l[0], $lines));
            $tx = null;
            foreach ($lines as $l) {
                $stock->ConsumeProduct($l[0], (float)$l[1], false, S::TRANSACTION_TYPE_CONSUME, 'default', null, $l[2] ?? null, $tx);
                b41_jit($req, 'between_lines');
            }
            if (!empty($req['hooks']['die_in_t2'])) {
                echo json_encode(['marker' => 'BOOKED_UNCOMMITTED']) . "\n"; fflush(STDOUT);
                posix_kill(getmypid(), 9);
                sleep(60);
            }
            $ins = $pdo->prepare('INSERT INTO b41_event_lines(event_id,product_id,amount,location_id) VALUES (?,?,?,?)');
            foreach ($lines as $l) { $ins->execute([$row['id'], $l[0], $l[1], $l[2] ?? null]); }
            $pdo->prepare("UPDATE b41_events SET state='booked', reason=NULL, transaction_id=? WHERE id=?")->execute([$tx, $row['id']]);
            return ['booked_now' => true, 'state' => 'booked', 'transaction_id' => $tx, 'event_id' => (int)$row['id']];
        });
        return $out + ['inserted' => $inserted, 'ms' => round((microtime(true) - $t0) * 1000, 1)];
    } catch (Throwable $e) {
        $err = b41_err($e);
        try {
            $reason = b41_reason($e);
            $db->InTransaction(function () use ($req, $db, $reason) {
                $db->GetDbConnectionRaw()->prepare("UPDATE b41_events SET state='needs_review', reason=? WHERE user_id=? AND source_system=? AND source_event_id=? AND state<>'booked'")
                    ->execute([$reason, $req['user'], $req['sys'], $req['eid']]);
            });
        } catch (Throwable $e3) { $err['t3_error'] = b41_err($e3); }
        return ['booked_now' => false, 'state' => 'needs_review', 'error' => $err, 'inserted' => $inserted, 'ms' => round((microtime(true) - $t0) * 1000, 1)];
    }
}

/** Spawns persistent workers (probes/worker.php) attached to the schema. */
function b41_spawn_workers(int $n, string $schema, array $users = [9000]): array
{
    $workers = [];
    for ($i = 0; $i < $n; $i++) {
        $user = $users[$i % count($users)];
        $env = array_merge(getenv(), ['B41_SCHEMA' => $schema, 'B41_USER' => (string)$user, 'B41_WORKER' => (string)$i]);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', '/app/.devtools/adr0041/probes/worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/tmp/b41-worker-' . $i . '.err', 'a']], $pipes, '/app', $env);
        $workers[$i] = ['proc' => $p, 'in' => $pipes[0], 'out' => $pipes[1], 'user' => $user, 'id' => $i];
    }
    foreach ($workers as $i => &$w) {
        $line = fgets($w['out']);
        $r = json_decode((string)$line, true);
        if (!is_array($r) || empty($r['ready'])) { throw new RuntimeException("worker $i did not start: " . $line); }
        $w['rss_kb'] = $r['rss_kb'];
    }
    return $workers;
}

function b41_send(array $w, array $cmd): void { fwrite($w['in'], json_encode($cmd) . "\n"); fflush($w['in']); }

function b41_recv(array $w, int $timeoutSeconds = 60): ?array
{
    stream_set_timeout($w['out'], $timeoutSeconds);
    $line = fgets($w['out']);
    if ($line === false) { return null; }
    return json_decode($line, true);
}

function b41_kill_workers(array $workers): void
{
    foreach ($workers as $w) {
        if (is_resource($w['proc'])) {
            @fwrite($w['in'], json_encode(['cmd' => 'quit']) . "\n");
            @fclose($w['in']);
        }
    }
    foreach ($workers as $w) {
        if (is_resource($w['proc'])) { $st = proc_get_status($w['proc']); if ($st['running']) { proc_terminate($w['proc']); } @proc_close($w['proc']); }
    }
}

/**
 * Holds a session advisory lock, tells workers the barrier key, waits until $n of them are blocked
 * on it, then releases them together. $send is called after the lock is held.
 */
function b41_release_together(PDO $ctl, int $key, int $n, callable $send): void
{
    $ctl->query('SELECT pg_advisory_lock(' . B41_BARRIER_CLASS . ", $key)");
    $send();
    $deadline = microtime(true) + 30;
    do {
        $waiting = (int)$ctl->query('SELECT count(*) FROM pg_locks WHERE locktype=\'advisory\' AND NOT granted AND classid=' . B41_BARRIER_CLASS . " AND objid=$key")->fetchColumn();
        if ($waiting >= $n) { break; }
        usleep(2000);
    } while (microtime(true) < $deadline);
    if ($waiting < $n) { $ctl->query('SELECT pg_advisory_unlock(' . B41_BARRIER_CLASS . ", $key)"); throw new RuntimeException("only $waiting of $n workers reached the barrier"); }
    $ctl->query('SELECT pg_advisory_unlock(' . B41_BARRIER_CLASS . ", $key)");
}

function b41_env_info(PDO $pdo): array
{
    return ['php' => PHP_VERSION, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(),
        'deadlock_timeout' => $pdo->query('SHOW deadlock_timeout')->fetchColumn(), 'max_connections' => $pdo->query('SHOW max_connections')->fetchColumn()];
}
