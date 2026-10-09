<?php
// SPIKE ONLY. Probe 1 for ADR-0040: share writes do not change effective permissions.
//
// Users: owner, grantee, unrelated, account manager (USERS_EDIT), administrator (ADMIN).
// For every user as caller (one long-lived process each, because VICTUAL_USER_ID is a
// constant) the probe records what the real User class answers: the resolved permission set
// of every user, MayAdminister() for every target, CheckMayGrant() for fixed permission-id
// sets. It does that before and after many kinds of writes to scratch share tables, and
// compares the canonical JSON byte for byte. A positive control (a real permission grant)
// shows the comparison can see a change.
require __DIR__ . '/common.php';

$pdo = A40::create();
$schema = A40::schemaName();
$pdo->exec(file_get_contents(__DIR__ . '/scratch.sql'));

$perm = fn(string $n) => (int)$pdo->query("SELECT id FROM permission_hierarchy WHERE name = " . $pdo->quote($n))->fetchColumn();
$users = ['owner' => 9101, 'grantee' => 9102, 'unrelated' => 9103, 'manager' => 9104, 'admin' => 9105];
foreach ($users as $name => $id) {
    $pdo->exec("INSERT INTO users(id, username, password) VALUES ($id, 'a40-$name', 'fixture-only')");
}
$held = ['owner' => ['STOCK_VIEW', 'STOCK_CONSUME'], 'grantee' => ['STOCK_VIEW', 'STOCK_CONSUME'], 'unrelated' => ['STOCK_VIEW'],
    'manager' => ['USERS_EDIT', 'STOCK_VIEW', 'STOCK_CONSUME'], 'admin' => ['ADMIN']];
foreach ($held as $name => $perms) {
    foreach ($perms as $p) { $pdo->exec("INSERT INTO user_permissions(user_id, permission_id) VALUES ({$users[$name]}, " . $perm($p) . ')'); }
}
$grantSets = ['STOCK_VIEW' => [$perm('STOCK_VIEW')], 'STOCK_CONSUME' => [$perm('STOCK_CONSUME')], 'STOCK_EDIT' => [$perm('STOCK_EDIT')],
    'USERS_EDIT' => [$perm('USERS_EDIT')], 'ADMIN' => [$perm('ADMIN')], 'STOCK_VIEW+STOCK_CONSUME' => [$perm('STOCK_VIEW'), $perm('STOCK_CONSUME')]];

// One child per caller.
$children = [];
foreach ($users as $name => $id) {
    $env = ['PGHOST' => getenv('PGHOST'), 'PGPORT' => getenv('PGPORT'), 'PGUSER' => getenv('PGUSER'), 'PGPASSWORD' => getenv('PGPASSWORD'),
        'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'), 'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'), 'A40_SCHEMA' => $schema, 'A40_CALLER' => (string)$id, 'PATH' => getenv('PATH')];
    $p = proc_open(['php', __DIR__ . '/perm-child.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/app', $env);
    $children[$name] = ['proc' => $p, 'in' => $pipes[0], 'out' => $pipes[1], 'err' => $pipes[2]];
}
$snapshot = function () use (&$children, $users, $grantSets): array {
    $all = [];
    foreach ($children as $name => $c) {
        fwrite($c['in'], json_encode(['users' => array_values($users), 'grant_sets' => $grantSets]) . "\n");
        fflush($c['in']);
        $line = fgets($c['out']);
        if ($line === false) { throw new RuntimeException("child $name died: " . stream_get_contents($c['err'])); }
        $all[$name] = json_decode($line, true);
    }
    return a40_canon($all);
};

$steps = [];
$base = $snapshot();
$baseJson = json_encode($base);
$record = function (string $label, int $writes) use (&$steps, $snapshot, $baseJson) {
    $json = json_encode($snapshot());
    $steps[] = ['step' => $label, 'share_table_writes' => $writes, 'sha256' => hash('sha256', $json), 'identical_to_baseline' => $json === $baseJson];
};
$run = function (string $sql) use ($pdo): int { return $pdo->exec($sql); };

$O = $users['owner']; $G = $users['grantee']; $U = $users['unrelated']; $M = $users['manager']; $A = $users['admin'];
$writes = 0;
$w = function (string $sql) use ($run, &$writes) { $writes += $run($sql); };

$w("INSERT INTO a40_recipes(id, owner_id, name) VALUES (1, $O, 'r1')");
$w("INSERT INTO a40_recipe_lines(recipe_id, product_id, amount) VALUES (1, 1, 1), (1, 2, 2)");
$record('create recipe and lines', $writes);
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, granted_by) VALUES (1, $G, $O)");
$record('owner grants read to grantee', $writes);
$w("UPDATE a40_recipe_shares SET right_consume = true, right_edit = true, right_undo = true WHERE recipe_id = 1 AND user_id = $G");
$record('owner widens grantee to consume, edit, undo', $writes);
$w("UPDATE a40_recipe_shares SET right_share = true WHERE recipe_id = 1 AND user_id = $G");
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, granted_by) VALUES (1, $U, $G)");
$record('grantee (share holder) grants read to unrelated', $writes);
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES (1, $M, true, true, true, true, $O), (1, $A, true, true, true, true, $O)");
$record('all five rights shared to account manager and to administrator', $writes);
$w("DELETE FROM a40_recipe_shares WHERE recipe_id = 1 AND user_id = $G");
$record('owner revokes grantee', $writes);
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES (1, $G, true, true, true, true, $O)");
$w("DELETE FROM a40_recipe_shares WHERE recipe_id = 1 AND user_id = $G");
$w("UPDATE a40_recipes SET owner_id = $G WHERE id = 1");
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) VALUES (1, $O, true, true, true, true, $G)");
$record('ownership transfer owner to grantee, previous owner kept as share holder', $writes);
$w("INSERT INTO a40_recipes(id, owner_id, name) VALUES (2, $M, 'r2'), (3, $A, 'r3')");
$w("INSERT INTO a40_recipe_shares(recipe_id, user_id, granted_by) VALUES (2, $O, $M), (2, $U, $M), (3, $G, $A), (3, $M, $A)");
$record('recipes owned by the account manager and by the administrator, shared to others', $writes);
$w("DELETE FROM a40_recipes WHERE id = 1");
$record('recipe deleted, shares cascade', $writes);

// Seeded random phase over shares, ownership and recipe deletion.
$seed = 40100909;
mt_srand($seed);
$ids = array_values($users);
$recipeIds = [2, 3];
$next = 10;
for ($i = 1; $i <= 400; $i++) {
    $r = $recipeIds[mt_rand(0, count($recipeIds) - 1)] ?? null;
    $u = $ids[mt_rand(0, 4)];
    $b = fn() => mt_rand(0, 1) ? 'true' : 'false';
    switch (mt_rand(0, 6)) {
        case 0: $w("INSERT INTO a40_recipe_shares(recipe_id, user_id, right_consume, right_edit, right_undo, right_share, granted_by) SELECT $r, $u, {$b()}, {$b()}, {$b()}, {$b()}, " . $ids[mt_rand(0, 4)] . " WHERE EXISTS (SELECT 1 FROM a40_recipes WHERE id = $r) ON CONFLICT (recipe_id, user_id) DO UPDATE SET right_consume = EXCLUDED.right_consume, right_share = EXCLUDED.right_share"); break;
        case 1: $w("UPDATE a40_recipe_shares SET right_consume = {$b()}, right_edit = {$b()}, right_undo = {$b()}, right_share = {$b()} WHERE recipe_id = $r AND user_id = $u"); break;
        case 2: $w("DELETE FROM a40_recipe_shares WHERE recipe_id = $r AND user_id = $u"); break;
        case 3: $w("UPDATE a40_recipes SET owner_id = $u WHERE id = $r"); break;
        case 4: $w("DELETE FROM a40_recipes WHERE id = $r"); $recipeIds = array_values(array_diff($recipeIds, [$r])); break;
        case 5: $w("INSERT INTO a40_recipes(id, owner_id, name) VALUES ($next, $u, 'r$next')"); $recipeIds[] = $next++; break;
        case 6: $w("INSERT INTO a40_recipe_lines(recipe_id, product_id, amount) SELECT $r, " . mt_rand(1, 9) . ", 1 WHERE EXISTS (SELECT 1 FROM a40_recipes WHERE id = $r) ON CONFLICT DO NOTHING"); break;
    }
    if ($recipeIds === []) { $w("INSERT INTO a40_recipes(id, owner_id, name) VALUES ($next, $O, 'r$next')"); $recipeIds[] = $next++; }
    if ($i % 50 === 0) { $record("random phase (seed $seed), after $i operations", $writes); }
}
$w('DELETE FROM a40_recipes');
$record('all recipes deleted', $writes);

// Positive control: a real permission grant must change the snapshot, and reverting it must restore it.
$pdo->exec("INSERT INTO user_permissions(user_id, permission_id) VALUES ($G, " . $perm('USERS_EDIT') . ')');
$changed = $snapshot();
$diffs = [];
foreach ($changed as $caller => $answers) {
    foreach (['resolved', 'may_administer', 'check_may_grant'] as $section) {
        if (json_encode($answers[$section]) !== json_encode($base[$caller][$section])) { $diffs[] = "$caller.$section"; }
    }
}
$control = ['action' => 'INSERT user_permissions(grantee, USERS_EDIT)', 'snapshot_differs_from_baseline' => json_encode($changed) !== $baseJson, 'sections_that_differ' => $diffs];
$pdo->exec("DELETE FROM user_permissions WHERE user_id = $G AND permission_id = " . $perm('USERS_EDIT'));
$control['restored_after_revert'] = json_encode($snapshot()) === $baseJson;

foreach ($children as $c) { fclose($c['in']); proc_close($c['proc']); }

echo json_encode([
    'environment' => ['php' => PHP_VERSION, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(), 'schema' => $schema],
    'users' => $users, 'permissions_held' => $held, 'grant_sets_checked' => $grantSets,
    'snapshots_per_step' => '5 callers x (resolved set of 5 users, MayAdminister x5 targets, CheckMayGrant x6 sets)',
    'baseline_sha256' => hash('sha256', $baseJson), 'baseline' => $base,
    'steps' => $steps,
    'all_steps_identical_to_baseline' => count(array_filter($steps, fn($s) => !$s['identical_to_baseline'])) === 0,
    'total_share_table_rows_written' => $writes,
    'positive_control' => $control,
], JSON_PRETTY_PRINT), "\n";
A40::drop();
