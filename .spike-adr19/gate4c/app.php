<?php
// Gate 4 round 3. Registration and settings writes against a real store, so every refusal can
// be checked against what is stored rather than against a status code alone.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/subset.php';

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

$db = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=5432;dbname=spike', 'postgres', 'spike',
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function out($code, $payload) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: content-type');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}
function fail($code, array $errors) { out($code, ['error_message' => 'Refused', 'errors' => $errors]); }

/** Registration: three checks, all before anything is stored. */
function register(PDO $db, $doc): array {
    $errors = [];

    // 1. Every combination's schema is inside the version 1 subset — structurally, by walking
    //    it, not by asking whether a validator happened not to throw.
    foreach ($doc->combinations as $i => $c) {
        foreach (Subset::check($c->settings_schema) as $p) {
            $errors[] = ['field' => "combinations[$i].settings_schema", 'code' => 'SCHEMA_OUTSIDE_SUBSET',
                         'message' => $p];
        }
    }

    // 2. Discriminator tuples are unique, so selection is deterministic. Two combinations
    //    sharing one is a registration defect, not a first-match race at write time.
    $seen = [];
    foreach ($doc->combinations as $i => $c) {
        $key = implode('|', array_map(fn($d) => (string)($c->$d ?? ''), $doc->discriminators));
        if (isset($seen[$key])) {
            $errors[] = ['field' => "combinations[$i]", 'code' => 'DISCRIMINATOR_NOT_UNIQUE',
                         'message' => "combinations $seen[$key] and $i share the discriminator tuple ($key); "
                                    . 'selecting a schema by it would not be deterministic'];
        }
        $seen[$key] = $i;
    }

    // 3. And each schema must actually evaluate, which catches what a structural walk cannot.
    foreach ($doc->combinations as $i => $c) {
        try { (new Validator())->validate(json_decode('{}'), $c->settings_schema); }
        catch (\Throwable $e) {
            $errors[] = ['field' => "combinations[$i].settings_schema", 'code' => 'SCHEMA_UNUSABLE',
                         'message' => $e->getMessage()];
        }
    }

    if ($errors) { return $errors; }

    $db->prepare('INSERT INTO label_drivers (driver_id, schema_version, document) VALUES (?,?,?)
                  ON CONFLICT (driver_id, schema_version) DO UPDATE SET document = EXCLUDED.document')
       ->execute([$doc->driver_id, $doc->schema_version, json_encode($doc)]);
    return [];
}

function combination($doc, $model, $media) {
    foreach ($doc->combinations as $c) {
        if ($c->model === $model && $c->media === $media) { return $c; }
    }
    return null;
}

/** The lifted pointer: additionalProperties locates itself at the document, so the offending
 *  property is pulled out of the message into `field` — otherwise a form can only show a
 *  banner, and cannot say which property it was even when it knows. */
function shape_errors($error, $c): array {
    $formatted = (new ErrorFormatter())->formatKeyed($error);
    $errors = [];
    foreach ($formatted as $pointer => $messages) {
        foreach ((array)$messages as $message) {
            $field = trim((string)$pointer, '/');
            $code = 'SETTING_NOT_PERMITTED';
            if (preg_match('/Additional object properties are not allowed: (.+)$/', (string)$message, $m)) {
                $field = trim(explode(',', $m[1])[0]);
                $code = 'PROPERTY_NOT_PERMITTED';
            }
            $errors[] = [
                'field' => $field !== '' ? $field : '(document)',
                'code' => $code,
                'message' => sprintf('%s — not permitted for %s with %s.', $message, $c->model, $c->media),
            ];
        }
    }
    return $errors ?: [['field' => '(document)', 'code' => 'SETTING_NOT_PERMITTED', 'message' => 'invalid']];
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { out(204, []); }

if ($path === '/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $doc = json_decode(file_get_contents('php://input'));
    $errors = register($db, $doc);
    if ($errors) { fail(422, $errors); }
    out(200, ['registered' => $doc->driver_id . '/' . $doc->schema_version,
              'combinations' => count($doc->combinations)]);
}

if ($path === '/printer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $r = $db->query('SELECT * FROM label_printers ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    out(200, $r ?: ['(no printer)' => true]);
}

if ($path === '/printer/settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'));
    $row = $db->query('SELECT * FROM label_printers ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) { fail(404, [['field' => '(printer)', 'code' => 'NO_PRINTER', 'message' => 'seed a printer first']]); }

    $reg = $db->prepare('SELECT document FROM label_drivers WHERE driver_id = ? AND schema_version = ?');
    $reg->execute([$row['driver_id'], $row['driver_schema_version']]);
    $doc = json_decode($reg->fetchColumn() ?: 'null');
    if (!$doc) { fail(500, [['field' => '(driver)', 'code' => 'DRIVER_MISSING', 'message' => 'no registration']]); }

    $model = $body->model ?? $row['model'];
    $media = $body->media ?? $row['media'];
    $c = combination($doc, $model, $media);
    if (!$c) {
        fail(422, [['field' => 'media', 'code' => 'COMBINATION_NOT_SUPPORTED',
                    'message' => sprintf('%s does not support media "%s".', $model, $media)]]);
    }

    try {
        $r = (new Validator())->validate($body->settings ?? new stdClass(), $c->settings_schema);
    } catch (\Throwable $e) {
        // Unevaluable stored schema: refuse, never store. The write path fails closed.
        fail(500, [['field' => '(schema)', 'code' => 'SCHEMA_UNUSABLE', 'message' => $e->getMessage()]]);
    }
    if (!$r->isValid()) { fail(422, shape_errors($r->error(), $c)); }

    $db->prepare('UPDATE label_printers SET model=?, media=?, settings=?, updated_at=now() WHERE id=?')
       ->execute([$model, $media, json_encode($body->settings ?? new stdClass()), $row['id']]);
    out(200, ['stored' => json_decode($db->query('SELECT settings FROM label_printers ORDER BY id LIMIT 1')->fetchColumn())]);
}

if ($path === '/schema' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = $db->query('SELECT * FROM label_printers ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $reg = $db->prepare('SELECT document FROM label_drivers WHERE driver_id = ? AND schema_version = ?');
    $reg->execute([$row['driver_id'], $row['driver_schema_version']]);
    $doc = json_decode($reg->fetchColumn());
    $c = combination($doc, $_GET['model'] ?? $row['model'], $_GET['media'] ?? $row['media']);
    if (!$c) { fail(422, [['field' => 'media', 'code' => 'COMBINATION_NOT_SUPPORTED', 'message' => 'unsupported']]); }
    out(200, $c->settings_schema);
}

out(404, ['error_message' => 'no route']);
