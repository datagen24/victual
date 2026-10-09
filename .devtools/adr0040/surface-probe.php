<?php
// SPIKE ONLY. Probe 3 for ADR-0040: generic-surface refusal and the accepted stock_log leak.
//
// The caller is the user named by A40_CALLER (default 9000, ADMIN). Any other id gets
// STOCK_VIEW and MASTER_DATA_EDIT only. The probe calls the real controllers directly
// (GenericEntityApiController, StockApiController) and the real UserfieldsService, the way
// tests/Pgsql/RbacTest.php does; it does not go through Slim routing or HTTP.
define('VICTUAL_USER_ID', (int)(getenv('A40_CALLER') ?: 9000));
require __DIR__ . '/common.php';

use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\GenericEntityApiController;
use Victual\Controllers\Api\StockApiController;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Services\StockService;
use Victual\Services\UserfieldsService;

$pdo = A40::create();
$pdo->exec(file_get_contents(__DIR__ . '/scratch.sql'));
$caller = VICTUAL_USER_ID;
$pdo->exec("INSERT INTO users(id, username, password) VALUES ($caller, 'a40-caller', 'fixture-only'), (9400, 'a40-other', 'fixture-only')");
$grant = fn(int $uid, string $n) => $pdo->exec("INSERT INTO user_permissions(user_id, permission_id) SELECT $uid, id FROM permission_hierarchy WHERE name = '$n'");
if ($caller === 9000) { $grant($caller, 'ADMIN'); } else { $grant($caller, 'STOCK_VIEW'); $grant($caller, 'MASTER_DATA_EDIT'); }
$held = $pdo->query("SELECT DISTINCT permission_name FROM user_permissions_resolved WHERE user_id = $caller ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

$container = new \DI\Container();
$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
$generic = new GenericEntityApiController($container);
$stockApi = new StockApiController($container);
// A body-carrying request needs the JSON content type, or the controllers answer 400 "Bad Content-Type"
// before they look at the entity, which would hide the refusal under test.
$req = fn(string $m = 'GET', $body = null, array $query = []) => (new ServerRequestFactory())->createServerRequest($m, 'http://localhost/api')
    ->withHeader('Content-Type', 'application/json')->withParsedBody($body)->withQueryParams($query);
$call = function (callable $f): array {
    try { $r = $f(); return ['status' => $r->getStatusCode(), 'body' => substr((string)$r->getBody(), 0, 140)]; }
    catch (HttpException $e) { return ['status' => $e->getCode(), 'body' => substr($e->getMessage(), 0, 140)]; }
    catch (Throwable $e) { return ['status' => 'exception', 'body' => get_class($e) . ': ' . substr($e->getMessage(), 0, 140)]; }
};

// ---- Part A: entities that are in neither EntityReadPolicy::PERMISSIONS nor the ExposedEntity enum.
$spec = json_decode(file_get_contents(VICTUAL_ROOT_PATH . '/victual.openapi.json'), true);
$enum = $spec['components']['schemas']['ExposedEntity']['enum'];
$pdo->exec("INSERT INTO a40_recipes(id, owner_id, name) VALUES (1, 9400, 'private recipe')");
$scratch = ['a40_recipes', 'a40_recipe_lines', 'a40_recipe_shares', 'a40_consume_events'];
$plausible = ['consumption_recipes', 'consumption_recipe_lines', 'consumption_recipe_shares', 'consumption_events'];
$membership = [];
foreach (array_merge($scratch, $plausible, ['label_artifacts', 'label_captures']) as $e) {
    $membership[$e] = ['in_EntityReadPolicy_PERMISSIONS' => array_key_exists($e, EntityReadPolicy::PERMISSIONS), 'in_ExposedEntity_enum' => in_array($e, $enum, true), 'EntityReadPolicy_Covers' => EntityReadPolicy::Covers($e)];
}
$refusals = [];
foreach (array_merge($scratch, $plausible) as $e) {
    $refusals[$e] = [
        'GET /objects/{entity} (GetObjects)' => $call(fn() => $generic->GetObjects($req(), new Response(), ['entity' => $e])),
        'GET /objects/{entity}/1 (GetObject)' => $call(fn() => $generic->GetObject($req(), new Response(), ['entity' => $e, 'objectId' => 1])),
        'GET /userfields/{entity}/1 (GetUserfields)' => $call(fn() => $generic->GetUserfields($req(), new Response(), ['entity' => $e, 'objectId' => 1])),
        'PUT /userfields/{entity}/1 (SetUserfields)' => $call(fn() => $generic->SetUserfields($req('PUT', ['x' => 'y']), new Response(), ['entity' => $e, 'objectId' => 1])),
        'POST /objects/{entity} (AddObject)' => $call(fn() => $generic->AddObject($req('POST', ['name' => 'x']), new Response(), ['entity' => $e])),
        'PUT /objects/{entity}/1 (EditObject)' => $call(fn() => $generic->EditObject($req('PUT', ['name' => 'x']), new Response(), ['entity' => $e, 'objectId' => 1])),
        'DELETE /objects/{entity}/1 (DeleteObject)' => $call(fn() => $generic->DeleteObject($req('DELETE'), new Response(), ['entity' => $e, 'objectId' => 1])),
    ];
}
$userfields = [];
foreach (array_merge($scratch, $plausible) as $e) {
    try { UserfieldsService::GetInstance()->SetValues($e, 1, ['x' => 'y']); $userfields[$e]['SetValues'] = 'accepted'; }
    catch (Throwable $t) { $userfields[$e]['SetValues'] = get_class($t) . ': ' . $t->getMessage(); }
    try { UserfieldsService::GetInstance()->GetValues($e, 1); $userfields[$e]['GetValues'] = 'accepted'; }
    catch (Throwable $t) { $userfields[$e]['GetValues'] = get_class($t) . ': ' . $t->getMessage(); }
}
$rowsLeftInScratch = (int)$pdo->query('SELECT count(*) FROM a40_recipes')->fetchColumn();
$controls = [
    'locations (STOCK_VIEW) GetObjects' => $call(fn() => $generic->GetObjects($req(), new Response(), ['entity' => 'locations'])),
    'recipes (RECIPES_VIEW) GetObjects' => $call(fn() => $generic->GetObjects($req(), new Response(), ['entity' => 'recipes'])),
    // An exposed entity gets past the entity check: the refusal is about the unknown field, not the entity.
    'locations SetUserfields with an unknown field' => $call(fn() => $generic->SetUserfields($req('PUT', ['x' => 'y']), new Response(), ['entity' => 'locations', 'objectId' => 1])),
    'locations SetValues with an unknown field' => (function () { try { UserfieldsService::GetInstance()->SetValues('locations', 1, ['x' => 'y']); return 'accepted'; } catch (Throwable $t) { return $t->getMessage(); } })(),
];
$reason = 'Entity does not exist or is not exposed';
// DeleteObject answers 400 "Invalid entity" for an entity outside the enum; every other path uses $reason.
$refusedForTheEntity = [];
$messages = [];
foreach ($refusals as $e => $calls) {
    foreach ($calls as $label => $r) {
        $ok = $r['status'] === 400 && (str_contains($r['body'], $reason) || str_contains($r['body'], 'Invalid entity'));
        $refusedForTheEntity[] = $ok;
        $m = $r['status'] . ' ' . (json_decode($r['body'], true)['error_message'] ?? $r['body']);
        $messages[$label][$m] = ($messages[$label][$m] ?? 0) + 1;
    }
}
foreach ($userfields as $e => $calls) { foreach ($calls as $label => $r) { $refusedForTheEntity[] = str_contains($r, $reason); $messages["UserfieldsService::$label"][$r] = ($messages["UserfieldsService::$label"][$r] ?? 0) + 1; } }

// ---- Part B: the residual leak ADR-0040 rule 11 accepts.
$loc = (int)$pdo->query("INSERT INTO locations(name) VALUES ('a40') RETURNING id")->fetchColumn();
$stock = StockService::GetInstance();
$pid = [];
foreach (['a40-pill-a', 'a40-pill-b'] as $n) {
    $p = (int)$pdo->query("INSERT INTO products(name, location_id, qu_id_stock, qu_id_purchase, qu_id_consume, qu_id_price) VALUES ('$n', $loc, 2, 2, 2, 2) RETURNING id")->fetchColumn();
    $stock->AddProduct($p, 100, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 1.0, $loc);
    $pid[$n] = $p;
}
$foodRecipe = (int)$pdo->query("INSERT INTO recipes(name) VALUES ('food recipe, for contrast') RETURNING id")->fetchColumn();
// A consumption "through a private recipe": two products in one transaction, recipe_id left null (ADR-0040 rule 1).
$tidPrivate = 'a40-private-recipe-tx';
\Victual\Services\DatabaseService::GetInstance()->InTransaction(function () use ($stock, $pid, $tidPrivate) {
    foreach ($pid as $p) { $t = $tidPrivate; $stock->ConsumeProduct($p, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t); }
});
// Contrast: today's food-recipe consumption stamps recipe_id.
$tidFood = 'a40-food-recipe-tx';
\Victual\Services\DatabaseService::GetInstance()->InTransaction(function () use ($stock, $pid, $tidFood, $foodRecipe) {
    foreach ($pid as $p) { $t = $tidFood; $stock->ConsumeProduct($p, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', $foodRecipe, null, $t); }
});
$rows = fn(string $tid) => array_map(fn($r) => ['id' => $r['id'], 'product_id' => $r['product_id'], 'amount' => $r['amount'], 'transaction_id' => $r['transaction_id'], 'recipe_id' => $r['recipe_id'], 'row_created_timestamp' => $r['row_created_timestamp'] ?? null],
    json_decode((string)$generic->GetObjects($req('GET', null, ['query' => ["transaction_id=$tid"]]), new Response(), ['entity' => 'stock_log'])->getBody(), true));
$viaTransactions = fn(string $tid) => array_map(fn($r) => ['product_id' => $r['product_id'], 'amount' => $r['amount'], 'recipe_id' => $r['recipe_id']],
    json_decode((string)$stockApi->StockTransactions($req(), new Response(), ['transactionId' => $tid])->getBody(), true));
$bookingId = (int)$pdo->query("SELECT min(id) FROM stock_log WHERE transaction_id = " . $pdo->quote($tidPrivate))->fetchColumn();
$booking = json_decode((string)$stockApi->StockBooking($req(), new Response(), ['bookingId' => $bookingId])->getBody(), true);
$leak = [
    'caller_permissions' => $held,
    'private_recipe_two_product_consume' => [
        'stock_log_via_GET_objects_stock_log' => $rows($tidPrivate),
        'GET_stock_transactions' => $viaTransactions($tidPrivate),
        'GET_stock_bookings_one' => ['id' => $booking['id'] ?? null, 'transaction_id' => $booking['transaction_id'] ?? null, 'recipe_id' => array_key_exists('recipe_id', $booking ?? []) ? $booking['recipe_id'] : 'key absent'],
    ],
    'food_recipe_consume_for_contrast' => ['stock_log_via_GET_objects_stock_log' => $rows($tidFood), 'GET_stock_transactions' => $viaTransactions($tidFood)],
];

echo json_encode([
    'environment' => ['php' => PHP_VERSION, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(), 'caller' => $caller, 'caller_permissions' => $held,
        'exposed_entity_enum_size' => count($enum), 'entity_read_policy_entries' => count(EntityReadPolicy::PERMISSIONS)],
    'entity_membership' => $membership,
    'generic_surface_refusals' => $refusals,
    'userfields_service_direct' => $userfields,
    'scratch_rows_untouched_after_all_refusals' => $rowsLeftInScratch === 1,
    'refusal_calls_total' => count($refusedForTheEntity),
    'refusal_calls_refused_for_the_entity' => count(array_filter($refusedForTheEntity)),
    'refusal_messages_by_call' => $messages,
    'positive_controls' => $controls,
    'stock_log_residual_leak' => $leak,
], JSON_PRETTY_PRINT), "\n";
A40::drop();
