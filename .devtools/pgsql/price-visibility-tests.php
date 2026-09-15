<?php

// Plan 19 piece 2 (issue #84): does price redaction actually work, end to end, for the
// seeded Child and Guest roles - not merely "does the STOCK_PRICES_VIEW constant exist"?
//
//   php price-visibility-tests.php
//
// PostgreSQL only, like the rbac phase this extends: migrations/0281.pgsql.sql is above the
// SQLite freeze, so there is no second engine to compare against. A freshly migrated
// database and nothing else - the phase makes its own product, stock, recipe and shopping
// list rows, real bookings through StockService rather than hand-inserted values, so the
// price/cost figures being hidden are the same ones a household would actually see.
//
// One fixture caller (VICTUAL_USER_ID, a PHP constant, cannot change mid-process) is moved
// between the four seeded roles by rewriting user_roles between blocks, exactly as
// rbac-tests.php moves one caller between direct grants with grant(). ADULT and a bare
// STOCK_VIEW direct grant are asserted alongside CHILD and GUEST: ADULT is the "no user who
// holds STOCK or ADMIN loses a field on upgrade" property the plan states (STOCK resolves to
// STOCK_PURCHASE resolves to the new STOCK_PRICES_VIEW leaf); a bare STOCK_VIEW grant is the
// plan's honestly-named residue - S30/S31 let that account see every price before this
// migration, and it must not after.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9100);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'price-visibility-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;
use Victual\Services\RecipesService;
use Victual\Controllers\Users\User;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// views/layout/default.blade.php reads $_SERVER['REQUEST_URI'] for the manifest link, which
// a CLI process does not have. Set rather than left undefined so the Blade assertions below
// render the real layout instead of six PHP warnings' worth of it.
$_SERVER['REQUEST_URI'] = '/shoppinglist';

$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

$checks = 0;
$failures = [];

function check(bool $ok, string $message): void
{
	global $checks, $failures;
	$checks++;
	if (!$ok)
	{
		$failures[] = $message;
		fwrite(STDERR, "FAIL: $message\n");
	}
}

function request(string $method = 'GET', array $queryParams = [])
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')->withQueryParams($queryParams);
}

function asJson($response): array
{
	$decoded = json_decode((string)$response->getBody(), true);
	return is_array($decoded) ? $decoded : [];
}

function roleId(string $code): int
{
	global $pdo;
	$statement = $pdo->prepare('SELECT id FROM roles WHERE code = ?');
	$statement->execute([$code]);
	return (int)$statement->fetchColumn();
}

/** Moves the one fixture caller to hold exactly the given role (and no direct grants), or no role/grant at all for 'NONE'. */
function assumeRole(?string $roleCode): void
{
	global $pdo;
	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9100; DELETE FROM user_roles WHERE user_id = 9100');
	if ($roleCode !== null)
	{
		$pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (9100, ' . roleId($roleCode) . ')');
	}
}

/** Moves the one fixture caller to hold exactly the given direct permission grants, no roles. */
function assumeDirectGrants(array $permissionNames): void
{
	global $pdo;
	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9100; DELETE FROM user_roles WHERE user_id = 9100');
	$statement = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT 9100, id FROM permission_hierarchy WHERE name = ?');
	foreach ($permissionNames as $name)
	{
		$statement->execute([$name]);
	}
}

// --- Fixtures: a product with priced stock, a recipe using it, and a shopping list entry.
// Built as ADMIN (temporarily) so every write path's own permission checks pass; the
// fixture caller is moved to the role under test immediately afterward.
assumeDirectGrants(['ADMIN']);

$pdo->exec("INSERT INTO users (id, username, password) VALUES (9100, 'price-visibility-caller', 'fixture')");
$pdo->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days) VALUES (9500, 'Price Visibility Test Product', 2, 2, 2, 0, 0)");

// Due soon (not far in the future) so GET /stock/volatile's due_products actually lists it -
// computed from the clock, deliberately, since "due soon" is itself relative to today.
$dueSoonDate = date('Y-m-d', strtotime('+2 days'));

$stock = StockService::GetInstance();
$transactionId = null;
$stock->AddProduct(9500, 10, $dueSoonDate, StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 3.50, 2, 1, $transactionId);
$stockRow = $pdo->query('SELECT id FROM stock WHERE product_id = 9500 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$stockEntryId = (int)$stockRow['id'];
check($stockEntryId > 0, 'Fixture stock entry was created');

$pdo->exec("INSERT INTO recipes (id, name, base_servings, desired_servings) VALUES (9500, 'Price Visibility Test Recipe', 1, 1)");
$pdo->exec('INSERT INTO recipes_pos (id, recipe_id, product_id, amount, qu_id) VALUES (9500, 9500, 9500, 1, 2)');

$pdo->exec("INSERT INTO shopping_list (id, product_id, amount) VALUES (9500, 9500, 5)");

// A barcode carrying its own purchase price. product_barcodes.last_price is a price channel
// of its own - /objects/product_barcodes, /objects/product_barcodes/{id} and the separately
// exposed /objects/product_barcodes_view all serve it on STOCK_VIEW alone - and it had no
// policy row until db/pgsql/prices-seed.sql. Issue #176 item 3.
$pdo->exec("INSERT INTO product_barcodes (id, product_id, barcode, last_price) VALUES (9500, 9500, 'PRICEVIS9500', 2.75)");

// The booking and the transaction the purchase above left behind, for the two stock_log
// endpoints that return them (issue #176 items 2 and 7). Read back rather than assumed:
// AddProduct() writes the transaction id through its by-reference parameter, but the
// booking's own id is the ledger row's.
$bookingId = (int)$pdo->query('SELECT id FROM stock_log WHERE product_id = 9500 ORDER BY id DESC LIMIT 1')->fetchColumn();
check($bookingId > 0, 'Fixture stock_log booking was created');
check(!empty($transactionId), 'Fixture transaction id was returned by AddProduct');

// Sanity: as Admin, the fixture actually carries a nonzero price/cost - a false "hidden"
// on a value that was already zero would prove nothing.
$productDetailsApi = new Victual\Controllers\Api\StockApiController($container);
$genericApi = new Victual\Controllers\Api\GenericEntityApiController($container);
$recipesApi = new Victual\Controllers\Api\RecipesApiController($container);
$stockReports = new Victual\Controllers\StockReportsController($container);
$stockController = new Victual\Controllers\StockController($container);

assumeDirectGrants(['ADMIN']);
$adminDetails = asJson($productDetailsApi->ProductDetails(request(), new Response(), ['productId' => 9500]));
check(($adminDetails['last_price'] ?? 0) == 3.50, 'Fixture last_price is 3.50 as Admin (sanity)');
check(($adminDetails['stock_value'] ?? 0) == 35.0, 'Fixture stock_value is 35.0 as Admin (sanity)');
$adminFulfillment = asJson($recipesApi->GetRecipeFulfillment(request(), new Response(), ['recipeId' => 9500]));
check(($adminFulfillment['costs'] ?? 0) == 3.50, 'Fixture recipe costs is 3.50 as Admin (sanity)');

// --- The matrix: which identities keep prices, which lose them.
//
// ADMIN and ADULT hold STOCK (or its whole self), so they resolve to STOCK_PRICES_VIEW
// through STOCK_PURCHASE and must see every price field, unchanged from before this plan.
// CHILD and GUEST are leaves-only and hold neither STOCK nor STOCK_PURCHASE, so they must
// not. VIEW_ONLY is the plan's own named residue: a direct STOCK_VIEW-alone grant read
// every price before this migration (S30/S31) and must not after - nobody's role, but the
// exact shape a pre-piece-2 installation's account could already be in.
$matrix = [
	'ADMIN' => ['role' => 'ADMIN', 'sees_prices' => true],
	'ADULT' => ['role' => 'ADULT', 'sees_prices' => true],
	'CHILD' => ['role' => 'CHILD', 'sees_prices' => false],
	'GUEST' => ['role' => 'GUEST', 'sees_prices' => false],
	// RECIPES_VIEW and SHOPPINGLIST_VIEW are granted alongside STOCK_VIEW so this identity
	// reaches the same object-level gates (piece 1) as the seeded roles below and the
	// assertions test price visibility specifically, not an unrelated domain refusal -
	// none of the three view leaves resolve to STOCK_PRICES_VIEW.
	'VIEW_ONLY' => ['direct' => ['STOCK_VIEW', 'RECIPES_VIEW', 'SHOPPINGLIST_VIEW'], 'sees_prices' => false],
];

foreach ($matrix as $label => $spec)
{
	if (isset($spec['role']))
	{
		assumeRole($spec['role']);
	}
	else
	{
		assumeDirectGrants($spec['direct']);
	}

	$sees = $spec['sees_prices'];

	// User::PricesVisible() - the Blade/JS-facing helper (BaseController::Render()'s
	// $pricesVisible, Victual.PricesVisible) - agrees with the API-facing checks below.
	// VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING defaults true (config-dist.php), so this
	// reduces to the permission check alone here.
	check(User::PricesVisible() === $sees, "$label: User::PricesVisible() " . ($sees ? 'true' : 'false'));

	// GET /api/stock (StockApiController::CurrentStock -> stock_current.value, hand-built)
	$current = asJson($productDetailsApi->CurrentStock(request(), new Response(), []));
	$row = null;
	foreach ($current as $entry)
	{
		if (($entry['product_id'] ?? null) == 9500) { $row = $entry; break; }
	}
	check($row !== null, "$label: GET /stock includes the fixture product");
	check(array_key_exists('value', $row ?? []) === $sees, "$label: GET /stock 'value' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/volatile (due/overdue/expired share stock_current rows)
	$volatile = asJson($productDetailsApi->CurrentVolatileStock(request(), new Response(), []));
	$dueRow = null;
	foreach ($volatile['due_products'] ?? [] as $entry)
	{
		if (($entry['product_id'] ?? null) == 9500) { $dueRow = $entry; break; }
	}
	check($dueRow !== null, "$label: GET /stock/volatile lists the fixture product as due");
	check(array_key_exists('value', $dueRow ?? []) === $sees, "$label: GET /stock/volatile due_products 'value' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/products/{id} (StockApiController::ProductDetails -> hand-built array)
	$details = asJson($productDetailsApi->ProductDetails(request(), new Response(), ['productId' => 9500]));
	foreach (['last_price', 'avg_price', 'current_price', 'oldest_price', 'stock_value'] as $field)
	{
		check(array_key_exists($field, $details) === $sees, "$label: GET /stock/products/{id} '$field' " . ($sees ? 'present' : 'absent'));
	}

	// GET /api/stock/entry/{id} (StockApiController::StockEntry -> stock row)
	$entry = asJson($productDetailsApi->StockEntry(request(), new Response(), ['entryId' => $stockEntryId]));
	check(array_key_exists('price', $entry) === $sees, "$label: GET /stock/entry/{id} 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/bookings/{id} (StockApiController::StockBooking -> one stock_log row).
	// The sibling of StockTransactions below, and the one #170 converted without converting:
	// same rows, same entity, one at a time. Issue #176 item 2.
	$booking = asJson($productDetailsApi->StockBooking(request(), new Response(), ['bookingId' => $bookingId]));
	check(($booking['id'] ?? null) == $bookingId, "$label: GET /stock/bookings/{id} returns the fixture booking");
	check(array_key_exists('price', $booking) === $sees, "$label: GET /stock/bookings/{id} 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/transactions/{id} (StockApiController::StockTransactions -> stock_log rows)
	$transactionRows = asJson($productDetailsApi->StockTransactions(request(), new Response(), ['transactionId' => $transactionId]));
	check(count($transactionRows) > 0, "$label: GET /stock/transactions/{id} returns rows");
	check(array_key_exists('price', $transactionRows[0] ?? []) === $sees, "$label: GET /stock/transactions/{id} 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/products/{id}/entries (StockApiController::ProductStockEntries).
	// stock_next_use, not stock: the view is `SELECT s.*, priority FROM stock s ...`, so it
	// carries every stock column under a different LessQL table name, and FilteredApiResponse's
	// redaction is keyed by that name. A missing policy row here would be invisible to every
	// assertion above, which is why it gets its own.
	$entries = asJson($productDetailsApi->ProductStockEntries(request(), new Response(), ['productId' => 9500]));
	check(count($entries) > 0, "$label: GET /stock/products/{id}/entries returns rows");
	check(array_key_exists('price', $entries[0] ?? []) === $sees, "$label: GET /stock/products/{id}/entries 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/stock/products/{id}/price-history - refusal, not redaction
	$historyResponse = null;
	$historyStatus = null;
	try
	{
		$historyResponse = $productDetailsApi->ProductPriceHistory(request(), new Response(), ['productId' => 9500]);
		$historyStatus = $historyResponse->getStatusCode();
	}
	catch (Slim\Exception\HttpException $e)
	{
		$historyStatus = $e->getCode();
	}
	check(($historyStatus === 200) === $sees, "$label: GET /stock/products/{id}/price-history " . ($sees ? '200' : '403') . " (got $historyStatus)");

	// GET /api/objects/stock and /api/objects/stock/{id} (GenericEntityApiController)
	$objectsStock = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'stock']));
	$objectStockRow = null;
	foreach ($objectsStock as $entry)
	{
		if (($entry['id'] ?? null) == $stockEntryId) { $objectStockRow = $entry; break; }
	}
	check($objectStockRow !== null, "$label: GET /objects/stock includes the fixture entry");
	check(array_key_exists('price', $objectStockRow ?? []) === $sees, "$label: GET /objects/stock 'price' " . ($sees ? 'present' : 'absent'));

	$objectStock = asJson($genericApi->GetObject(request(), new Response(), ['entity' => 'stock', 'objectId' => $stockEntryId]));
	check(array_key_exists('price', $objectStock) === $sees, "$label: GET /objects/stock/{id} 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/objects/stock?query[]=price>0 - the filter hole. A caller who cannot see
	// price must not be able to binary-search it through a filter either.
	$filterStatus = null;
	try
	{
		$filtered = $genericApi->GetObjects(request('GET', ['query' => ['price>0']]), new Response(), ['entity' => 'stock']);
		$filterStatus = $filtered->getStatusCode();
	}
	catch (Slim\Exception\HttpException $e)
	{
		$filterStatus = $e->getCode();
	}
	check(($filterStatus === 200) === $sees, "$label: GET /objects/stock?query[]=price>0 " . ($sees ? '200' : '400') . " (got $filterStatus)");

	// GET /api/objects/stock?order=price - the same hole through the other query parameter.
	// AssertFieldExists() is reached from both FilterData() and QueryData()'s order branch, and
	// a sort is as good a read as a filter: ascending then descending brackets the value just
	// as well as "price>3&price<5" does.
	$orderStatus = null;
	try
	{
		$ordered = $genericApi->GetObjects(request('GET', ['order' => 'price']), new Response(), ['entity' => 'stock']);
		$orderStatus = $ordered->getStatusCode();
	}
	catch (Slim\Exception\HttpException $e)
	{
		$orderStatus = $e->getCode();
	}
	check(($orderStatus === 200) === $sees, "$label: GET /objects/stock?order=price " . ($sees ? '200' : '400') . " (got $orderStatus)");

	// GET /api/objects/product_barcodes, /{id} and the separately exposed view. Issue #176 item 3.
	$barcodes = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'product_barcodes']));
	$barcodeRow = null;
	foreach ($barcodes as $entry)
	{
		if (($entry['id'] ?? null) == 9500) { $barcodeRow = $entry; break; }
	}
	check($barcodeRow !== null, "$label: GET /objects/product_barcodes includes the fixture barcode");
	check(array_key_exists('last_price', $barcodeRow ?? []) === $sees, "$label: GET /objects/product_barcodes 'last_price' " . ($sees ? 'present' : 'absent'));

	$barcodeOne = asJson($genericApi->GetObject(request(), new Response(), ['entity' => 'product_barcodes', 'objectId' => 9500]));
	check(array_key_exists('last_price', $barcodeOne) === $sees, "$label: GET /objects/product_barcodes/{id} 'last_price' " . ($sees ? 'present' : 'absent'));

	$barcodesView = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'product_barcodes_view']));
	$barcodeViewRow = null;
	foreach ($barcodesView as $entry)
	{
		if (($entry['barcode'] ?? null) === 'PRICEVIS9500') { $barcodeViewRow = $entry; break; }
	}
	check($barcodeViewRow !== null, "$label: GET /objects/product_barcodes_view includes the fixture barcode");
	check(array_key_exists('last_price', $barcodeViewRow ?? []) === $sees, "$label: GET /objects/product_barcodes_view 'last_price' " . ($sees ? 'present' : 'absent'));

	// The view is exposed under its own name, so its rows have to be filterable-on under its
	// own name too - a policy row for the table alone would leave the view's copy of the same
	// column open to the filter hole.
	$barcodeFilterStatus = null;
	try
	{
		$barcodeFilterStatus = $genericApi->GetObjects(request('GET', ['query' => ['last_price>0']]), new Response(), ['entity' => 'product_barcodes_view'])->getStatusCode();
	}
	catch (Slim\Exception\HttpException $e)
	{
		$barcodeFilterStatus = $e->getCode();
	}
	check(($barcodeFilterStatus === 200) === $sees, "$label: GET /objects/product_barcodes_view?query[]=last_price>0 " . ($sees ? '200' : '400') . " (got $barcodeFilterStatus)");

	// GET /stockreports/spendings - the whole page is SUM(amount * price) over
	// products_price_history, and until issue #176 item 4 it was reachable on STOCK_VIEW with
	// only its menu link hidden. A refusal, like price-history above, not a redaction.
	$spendingsStatus = null;
	try
	{
		$spendingsStatus = $stockReports->Spendings(request(), new Response(), [])->getStatusCode();
	}
	catch (Slim\Exception\HttpException $e)
	{
		$spendingsStatus = $e->getCode();
	}
	check(($spendingsStatus === 200) === $sees, "$label: GET /stockreports/spendings " . ($sees ? '200' : '403') . " (got $spendingsStatus)");

	// The whole-object marker itself, rather than one route's reading of it: FieldPolicy has
	// to name the missing permission for products_price_history and nothing for an entity
	// carrying only ordinary field rows. Issue #176 item 6 - it was consulted by no read path
	// at all, and BaseApiController::AssertWholeObjectReadable is now what enforces it for the
	// generic ones.
	$wholeObject = Victual\Services\FieldPolicy::GetInstance()->WholeObjectPermission('products_price_history');
	check(($wholeObject === null) === $sees, "$label: WholeObjectPermission('products_price_history') " . ($sees ? 'null' : 'STOCK_PRICES_VIEW'));
	check(Victual\Services\FieldPolicy::GetInstance()->WholeObjectPermission('stock') === null, "$label: WholeObjectPermission('stock') is null (field rows are not a whole-object gate)");

	// GET /api/objects/stock_log
	$objectsStockLog = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'stock_log']));
	check(count($objectsStockLog) > 0, "$label: GET /objects/stock_log returns rows");
	check(array_key_exists('price', $objectsStockLog[0] ?? []) === $sees, "$label: GET /objects/stock_log 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/objects/products_average_price and products_last_purchased. The view's own
	// column is 'price' (db/pgsql/baseline/04_views_l1a.sql, confirmed independently by
	// ADR-0005's accepted-exceptions section quoting "products_average_price.price") -
	// not 'average_price', which the plan's FIELD_POLICY table had wrong and this migration
	// corrects; see migrations/0281.pgsql.sql.
	$avgPrice = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'products_average_price']));
	check(count($avgPrice) > 0, "$label: GET /objects/products_average_price returns rows");
	check(array_key_exists('price', $avgPrice[0] ?? []) === $sees, "$label: GET /objects/products_average_price 'price' " . ($sees ? 'present' : 'absent'));

	$lastPurchased = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'products_last_purchased']));
	check(count($lastPurchased) > 0, "$label: GET /objects/products_last_purchased returns rows");
	check(array_key_exists('price', $lastPurchased[0] ?? []) === $sees, "$label: GET /objects/products_last_purchased 'price' " . ($sees ? 'present' : 'absent'));

	// GET /api/objects/uihelper_shopping_list - only for identities that hold
	// SHOPPINGLIST_VIEW at all (piece 1's object-level gate, EntityReadPolicy); Guest and a
	// bare STOCK_VIEW grant hold no shopping list access whatsoever, so the call refuses with
	// 403 before FieldPolicy is ever reached, which is the correct and separate thing to
	// assert, not a price-redaction question.
	if (User::HasPermissions(User::PERMISSION_SHOPPINGLIST_VIEW))
	{
		$shoppingList = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'uihelper_shopping_list']));
		check(count($shoppingList) > 0, "$label: GET /objects/uihelper_shopping_list returns rows");
		foreach (['last_price_unit', 'last_price_total', 'price'] as $field)
		{
			check(array_key_exists($field, $shoppingList[0] ?? []) === $sees, "$label: GET /objects/uihelper_shopping_list '$field' " . ($sees ? 'present' : 'absent'));
		}
	}
	else
	{
		$status = null;
		try { $status = $genericApi->GetObjects(request(), new Response(), ['entity' => 'uihelper_shopping_list'])->getStatusCode(); }
		catch (Slim\Exception\HttpException $e) { $status = $e->getCode(); }
		check($status === 403, "$label: GET /objects/uihelper_shopping_list refused (no SHOPPINGLIST_VIEW)");
	}

	// Plan 19 piece 2's verification 6: a Blade render that emits no currency span. The
	// shopping list page is the one that matters most - a Child holds SHOPPINGLIST_VIEW and
	// reaches it - and the assertion is on the rendered HTML rather than on the view data,
	// because `d-none` was the old idiom and a hidden cell still carries the price in the
	// page source for anyone who reads it. Issue #176 item 4 and item 7's last gap.
	if (User::HasPermissions(User::PERMISSION_SHOPPINGLIST_VIEW))
	{
		$page = (string)$stockController->ShoppingList(request(), new Response(), [])->getBody();
		check(str_contains($page, 'Price Visibility Test Product'), "$label: the shopping list page rendered the fixture item");
		check(str_contains($page, 'locale-number-currency') === $sees, "$label: the shopping list page "
			. ($sees ? 'emits' : 'emits no') . ' currency span');
		check(str_contains($page, '3.5') === $sees, "$label: the shopping list page " . ($sees ? 'carries' : 'does not carry') . ' the price in its source');
	}

	// The same for the two pages verification 6 names alongside it. Both need only
	// STOCK_VIEW, which every identity in this matrix holds, so neither needs the
	// permission branch above. The stock overview is where a price is a derived figure
	// rather than a column - "value" is SUM(price * amount) for the product - and the
	// entries page is where it is the raw stock.price.
	$overview = (string)$stockController->Overview(request(), new Response(), [])->getBody();
	check(str_contains($overview, 'Price Visibility Test Product'), "$label: the stock overview page rendered the fixture product");
	check(str_contains($overview, 'locale-number-currency') === $sees, "$label: the stock overview page "
		. ($sees ? 'emits' : 'emits no') . ' currency span');
	check(str_contains($overview, '>35<') === $sees, "$label: the stock overview page " . ($sees ? 'carries' : 'does not carry') . ' the stock value in its source');

	$entries = (string)$stockController->Stockentries(request(), new Response(), [])->getBody();
	check(str_contains($entries, 'Price Visibility Test Product'), "$label: the stock entries page rendered the fixture product");
	check(str_contains($entries, 'locale-number-currency') === $sees, "$label: the stock entries page "
		. ($sees ? 'emits' : 'emits no') . ' currency span');

	// GET /api/objects/recipes_pos_resolved
	$recipesPos = asJson($genericApi->GetObjects(request(), new Response(), ['entity' => 'recipes_pos_resolved']));
	check(count($recipesPos) > 0, "$label: GET /objects/recipes_pos_resolved returns rows");
	check(array_key_exists('costs', $recipesPos[0] ?? []) === $sees, "$label: GET /objects/recipes_pos_resolved 'costs' " . ($sees ? 'present' : 'absent'));

	// GET /api/recipes/fulfillment and /api/recipes/{id}/fulfillment
	$fulfillmentList = asJson($recipesApi->GetRecipeFulfillment(request(), new Response(), []));
	check(count($fulfillmentList) > 0, "$label: GET /recipes/fulfillment returns rows");
	check(array_key_exists('costs', $fulfillmentList[0] ?? []) === $sees, "$label: GET /recipes/fulfillment 'costs' " . ($sees ? 'present' : 'absent'));

	$fulfillmentOne = asJson($recipesApi->GetRecipeFulfillment(request(), new Response(), ['recipeId' => 9500]));
	check(array_key_exists('costs', $fulfillmentOne) === $sees, "$label: GET /recipes/{id}/fulfillment 'costs' " . ($sees ? 'present' : 'absent'));
}

// --- The migration-safety property, stated precisely. No user who already held STOCK
// (Adult, here) lost a field on upgrade - re-checked as an explicit before/after rather
// than folded into the matrix loop above so its failure reads on its own.
assumeRole('ADULT');
check(User::HasPermissions(User::PERMISSION_STOCK_PRICES_VIEW), 'Adult (holds STOCK) resolves to STOCK_PRICES_VIEW through STOCK_PURCHASE');
assumeRole('CHILD');
check(!User::HasPermissions(User::PERMISSION_STOCK_PURCHASE), 'Child does not hold STOCK_PURCHASE (Q6 precondition)');
check(!User::HasPermissions(User::PERMISSION_STOCK_PRICES_VIEW), 'Child does not resolve to STOCK_PRICES_VIEW');
assumeRole('GUEST');
check(!User::HasPermissions(User::PERMISSION_STOCK_PRICES_VIEW), 'Guest does not resolve to STOCK_PRICES_VIEW');

if (!empty($failures))
{
	fwrite(STDERR, count($failures) . " of $checks assertions failed:\n" . implode("\n", $failures) . "\n");
	exit(1);
}

echo "PRICE VISIBILITY PASSED ($checks assertions)\n";
