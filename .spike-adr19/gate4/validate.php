<?php
// Gate 4, validator half. Named candidate: opis/json-schema, which is the only maintained
// PHP implementation covering draft 2019-09 and 2020-12 — the drafts that have `if`/`then`,
// which the model/media case needs. justinrainbow/json-schema stops at draft-07; draft-07
// also has if/then, so both were candidates and this is the one tried.
require __DIR__ . '/vendor/autoload.php';

use Opis\JsonSchema\Validator;

$validator = new Validator();
$schema = json_decode(file_get_contents(__DIR__ . '/brother-ql.schema.json'));
$cases  = json_decode(file_get_contents(__DIR__ . '/cases.json'));

$fails = 0;
foreach ($cases as $c) {
    $r = $validator->validate($c->doc, $schema);
    $ok = $r->isValid();
    $agree = ($ok === $c->expect);
    if (!$agree) { $fails++; }
    printf("%-6s expect=%-5s got=%-5s  %s%s\n",
        $agree ? 'ok' : 'FAIL',
        $c->expect ? 'valid' : 'invalid',
        $ok ? 'valid' : 'invalid',
        $c->why,
        $ok || !$r->error() ? '' : '  [' . $r->error()->keyword() . ']');
}
printf("\n%d cases, %d disagreements\n", count($cases), $fails);
exit($fails === 0 ? 0 : 1);
