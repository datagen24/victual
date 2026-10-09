<?php
// SPIKE ONLY. One long-lived process per caller identity (VICTUAL_USER_ID is a constant, so a
// caller cannot be switched inside a process). On each input line it answers with what the
// real User class says: every user's resolved permissions, MayAdminister() for every target,
// and CheckMayGrant() for each requested permission-id set.
define('VICTUAL_USER_ID', (int)getenv('A40_CALLER'));
require __DIR__ . '/common.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Victual\Controllers\Users\User;

A40::attach(getenv('A40_SCHEMA'));
$request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api');
while (($line = fgets(STDIN)) !== false) {
    $in = json_decode($line, true);
    $out = ['caller' => VICTUAL_USER_ID, 'resolved' => [], 'may_administer' => [], 'check_may_grant' => []];
    foreach ($in['users'] as $uid) {
        $names = User::ResolvedPermissionNames((int)$uid);
        sort($names);
        $out['resolved'][(string)$uid] = $names;
        $out['may_administer'][(string)$uid] = User::MayAdminister((int)$uid);
    }
    foreach ($in['grant_sets'] as $label => $ids) {
        try { User::CheckMayGrant($request, $ids); $out['check_may_grant'][$label] = 'ok'; }
        catch (Throwable $e) { $out['check_may_grant'][$label] = get_class($e) . ': ' . $e->getMessage(); }
    }
    echo json_encode(a40_canon($out)), "\n";
    fflush(STDOUT);
}
