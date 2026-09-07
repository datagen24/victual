<?php
// Every rejection path is asserted against the *store*, not against the status code.
$BASE = 'http://127.0.0.1:8893';
$PGHOST = getenv('PGHOST');
$db = new PDO("pgsql:host=$PGHOST;port=5432;dbname=spike", 'postgres', 'spike',
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function req($method, $path, $body = null) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $out = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($out, true), $out];
}
function snapshot(PDO $db): array {
    return [
        'drivers'  => $db->query('SELECT driver_id, schema_version FROM label_drivers ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC),
        'printers' => $db->query('SELECT id, model, media, settings::text, updated_at::text FROM label_printers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}
$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  ok   %s\n", $label); }
    else { $fail++; printf("  FAIL %s  %s\n", $label, $detail); }
}

echo "== A. registration refusals leave the driver registry unchanged ==\n";
[$code,] = req('POST', '/register', json_decode(file_get_contents(__DIR__ . '/registrations/good.json')));
check('a good registration is accepted', $code === 200, "got $code");

$before = snapshot($db);
foreach (['duplicate-discriminator' => 'DISCRIMINATOR_NOT_UNIQUE',
          'unsupported-construct'   => 'SCHEMA_OUTSIDE_SUBSET',
          'unevaluable'             => 'SCHEMA_OUTSIDE_SUBSET'] as $file => $expect) {
    [$code, $body] = req('POST', '/register', json_decode(file_get_contents(__DIR__ . "/registrations/$file.json")));
    $codes = array_column($body['errors'] ?? [], 'code');
    check("$file is refused", $code === 422, "got $code");
    check("$file names $expect", in_array($expect, $codes, true), implode(',', $codes));
    check("$file: nothing was written", snapshot($db) == $before,
          'registry changed: ' . json_encode(snapshot($db)['drivers']));
    if ($file === 'duplicate-discriminator' && ($body['errors'][0]['message'] ?? '')) {
        printf("       %s\n", $body['errors'][0]['message']);
    }
    if ($file === 'unsupported-construct') {
        printf("       %s\n", implode(' | ', array_column($body['errors'], 'message')));
    }
    if ($file === 'unevaluable') {
        printf("       %s\n", implode(' | ', array_column($body['errors'], 'message')));
    }
}

echo "\n== B. a rejected settings write leaves the stored configuration unchanged ==\n";
$db->exec("DELETE FROM label_printers");
$db->exec("INSERT INTO label_printers (name, driver_id, driver_schema_version, model, media, settings)
           VALUES ('shelf printer','brother.ql','1.0','QL-820NWB','62','{\"cut_behaviour\":\"end\"}')");
$before = snapshot($db);
printf("  stored before: %s\n", $before['printers'][0]['settings']);

$rejections = [
  ['a property the combination does not have', ['model'=>'QL-820NWB','media'=>'62','settings'=>['two_colour'=>true]], 'PROPERTY_NOT_PERMITTED'],
  ['a value outside the enum',                 ['model'=>'QL-820NWB','media'=>'62','settings'=>['cut_behaviour'=>'sideways']], 'SETTING_NOT_PERMITTED'],
  ['an unknown property',                      ['model'=>'QL-820NWB','media'=>'62','settings'=>['darkness'=>3]], 'PROPERTY_NOT_PERMITTED'],
  ['an unsupported combination',               ['model'=>'QL-820NWB','media'=>'99','settings'=>[]], 'COMBINATION_NOT_SUPPORTED'],
];
foreach ($rejections as [$label, $body, $expect]) {
    [$code, $resp] = req('POST', '/printer/settings', $body);
    $codes = array_column($resp['errors'] ?? [], 'code');
    check("$label is refused", $code >= 400, "got $code");
    check("$label names $expect", in_array($expect, $codes, true), implode(',', $codes));
    check("$label: stored configuration unchanged", snapshot($db) == $before,
          'store changed to ' . (snapshot($db)['printers'][0]['settings'] ?? '?'));
}

echo "\n== C. the lifted property pointer ==\n";
[$code, $resp] = req('POST', '/printer/settings', ['model'=>'QL-820NWB','media'=>'62','settings'=>['darkness'=>3]]);
$e = $resp['errors'][0];
check('field names the offending property, not (document)', ($e['field'] ?? '') === 'darkness', json_encode($e));
check('code distinguishes it from a value error', ($e['code'] ?? '') === 'PROPERTY_NOT_PERMITTED', json_encode($e));
printf("       field=%s code=%s\n       message=%s\n", $e['field'], $e['code'], $e['message']);

echo "\n== D. an unevaluable stored schema refuses the write rather than answering anything else ==\n";
$db->exec("INSERT INTO label_drivers (driver_id, schema_version, document) VALUES
  ('brother.ql','9.9','" . str_replace("'", "''", json_encode(json_decode(file_get_contents(__DIR__.'/registrations/unevaluable.json')))) . "')
  ON CONFLICT (driver_id, schema_version) DO UPDATE SET document = EXCLUDED.document");
$db->exec("UPDATE label_printers SET driver_schema_version = '9.9'");
$before = snapshot($db);
[$code, $resp] = req('POST', '/printer/settings', ['model'=>'QL-820NWB','media'=>'62','settings'=>['cut_behaviour'=>'each']]);
check('an unevaluable schema refuses the write', $code >= 400, "got $code");
check('it says why', (($resp['errors'][0]['code'] ?? '') === 'SCHEMA_UNUSABLE'), json_encode($resp['errors'][0] ?? null));
check('stored configuration unchanged', snapshot($db) == $before, 'store changed');
$db->exec("UPDATE label_printers SET driver_schema_version = '1.0'");

echo "\n== E. a valid write does store ==\n";
[$code, $resp] = req('POST', '/printer/settings', ['model'=>'QL-820NWB','media'=>'62','settings'=>['cut_behaviour'=>'none']]);
check('accepted', $code === 200, "got $code");
check('and the store reflects it', ($db->query("SELECT settings->>'cut_behaviour' FROM label_printers ORDER BY id LIMIT 1")->fetchColumn()) === 'none');

printf("\n%d checks, %d passed, %d failed\n", $pass + $fail, $pass, $fail);
exit($fail === 0 ? 0 : 1);
