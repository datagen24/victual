<?php
// Gate 4 rerun — the server half of the revised design.
//
// Two checks, both compulsory, in the order the record now requires: the selection against
// `combinations`, then the settings document against the schema resolved for that selection.
// The form is an editor; this is the gate.
require __DIR__ . '/vendor/autoload.php';

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

$reg = json_decode(file_get_contents(__DIR__ . '/registration.json'));

// Registration-time check, and the spike's reason for it. One combination's schema carried a
// "//" comment key inside `properties`, where a value must itself be a schema. opis threw
// InvalidKeywordException at *write* time, the endpoint had no guard, and the fatal answered
// 200 — an invalid schema became "no validation at all" for that combination, silently. So a
// registration is validated against the metaschema before it is stored, and a write-time
// validation that throws refuses rather than passes.
function assert_registration_valid($reg): array {
    $problems = [];
    $v = new Validator();
    foreach ($reg->combinations as $c) {
        $probe = json_decode('{}');
        try {
            $v->validate($probe, $c->settings_schema);
        } catch (\Throwable $e) {
            $problems[] = sprintf('%s/%s: %s', $c->model, $c->media, $e->getMessage());
        }
    }
    return $problems;
}

function find_combination($reg, $model, $media) {
    foreach ($reg->combinations as $c) {
        if ($c->model === $model && $c->media === $media) { return $c; }
    }
    return null;
}

function error($status, $errors) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error_message' => 'Validation failed', 'errors' => $errors], JSON_PRETTY_PRINT);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: content-type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($path === '/registration-check') {
    $problems = assert_registration_valid($reg);
    header('Content-Type: application/json');
    if ($problems) { http_response_code(422); echo json_encode(['rejected' => $problems], JSON_PRETTY_PRINT); exit; }
    echo json_encode(['accepted' => count($reg->combinations) . ' combinations']);
    exit;
}

if ($path === '/combinations') {
    header('Content-Type: application/json');
    echo json_encode(array_map(fn($c) => [
        'model' => $c->model, 'media' => $c->media,
        'resolution' => $c->resolution_x . 'x' . $c->resolution_y,
        'color_mode' => $c->color_mode,
    ], $reg->combinations));
    exit;
}

if ($path === '/schema') {
    $c = find_combination($reg, $_GET['model'] ?? '', $_GET['media'] ?? '');
    if (!$c) { error(422, [['field' => 'media', 'code' => 'COMBINATION_NOT_SUPPORTED',
        'message' => 'This driver does not support ' . ($_GET['model'] ?? '?') . ' with media ' . ($_GET['media'] ?? '?') . '.']]); }
    header('Content-Type: application/json');
    echo json_encode($c->settings_schema);
    exit;
}

if ($path === '/validate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'));
    $model = $body->model ?? null; $media = $body->media ?? null;

    // Check 1 — the selection against combinations. Mandatory, and first: a settings document
    // cannot be meaningfully checked against a schema chosen by a selection that is not real.
    $c = find_combination($reg, $model, $media);
    if (!$c) {
        error(422, [[
            'field' => 'media', 'code' => 'COMBINATION_NOT_SUPPORTED',
            'message' => sprintf('%s does not support media "%s". Supported for this model: %s.',
                $model ?? '(no model)', $media ?? '(none)',
                implode(', ', array_map(fn($x) => $x->media,
                    array_values(array_filter($reg->combinations, fn($x) => $x->model === $model)))) ?: '(none)'),
        ]]);
    }

    // Check 2 — the settings document against the schema resolved for that combination.
    $settings = $body->settings ?? new stdClass();
    try {
        $r = (new Validator())->validate($settings, $c->settings_schema);
    } catch (\Throwable $e) {
        // A stored schema that cannot be evaluated is a registration defect, and the write is
        // refused. It must never be the path by which a document reaches the database
        // unexamined.
        error(500, [['field' => '(schema)', 'code' => 'SCHEMA_UNUSABLE',
                     'message' => 'The registered schema for ' . $c->model . '/' . $c->media
                                  . ' cannot be evaluated: ' . $e->getMessage()]]);
    }
    if (!$r->isValid()) {
        // ErrorFormatter is the supported way to walk an opis error. The first attempt at this
        // called ValidationError::details(), which does not exist in 2.6 — and because the
        // fatal happened while *shaping* the refusal, after the check had already decided to
        // refuse, the endpoint answered 200 with a stack trace in the body. A validation gate
        // whose error path can fail open is not a gate; the errors are built by the library.
        $formatted = (new ErrorFormatter())->formatKeyed($r->error());
        $errors = [];
        foreach ($formatted as $pointer => $messages) {
            foreach ((array)$messages as $message) {
                $field = trim((string)$pointer, '/');
                $errors[] = [
                    'field'   => $field !== '' ? $field : '(document)',
                    'code'    => 'SETTING_NOT_PERMITTED',
                    'message' => sprintf('%s — not permitted for %s with %s.',
                                         is_string($message) ? $message : json_encode($message),
                                         $c->model, $c->media),
                ];
            }
        }
        error(422, $errors ?: [['field' => '(document)', 'code' => 'INVALID',
                                'message' => 'Settings are not valid for this combination.']]);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'stored' => ['model' => $model, 'media' => $media,
                      'color_mode' => $c->color_mode, 'settings' => $settings]], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404); echo 'no route';
