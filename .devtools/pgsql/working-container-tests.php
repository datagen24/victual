<?php

// Does a (product, location) minimum raise the right refill prompt without ever touching the
// shopping list, and does weighing a vessel through the location's tare correct the one stock
// entry it should?
//
//   php working-container-tests.php
//
// PostgreSQL only, and for the reason the group-minimum and nested-locations phases are: the
// subject is migrations/0276.pgsql.sql, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID,
// so a SQLite side would be asked about a table, a view and two columns it does not have. The
// view phase cannot stand in for the shortfall assertions either - it seeds SQLite and copies
// the tables into PostgreSQL through the importer's common-column logic, so
// product_location_min_stock would exist on one side only and every pair would be trivially
// not short. A migrated database and nothing else: the phase makes its own products and
// locations, because what it asserts are exact shortfalls and exact weighed amounts, and the
// base fixture's rows would only be noise in either sum.
//
// WHAT IT GUARDS. Six things, most of which produce no error of any kind when they are wrong:
//
//   1. THE RULE THAT MUST NOT BE GOT WRONG. A location minimum is a refill prompt, never a
//      shopping list entry - docs/plans/landed/29-working-container-replenishment.md says getting
//      this backwards is the failure case the whole plan exists to avoid. Cases 8 and 9 assert
//      a short (product, location) pair puts nothing into stock_missing_products and that
//      running the shopping list top-up leaves the list alone, mirroring how
//      group-min-stock-tests.php asserts the same thing of a short product group.
//   2. Opened stock discounted per product, not per pair - case 4, paired with case 5 as the
//      control so a view that ignored open rows for everybody could not pass by accident.
//   3. An inactive product or an inactive location dropping the pair from the shortfall list
//      rather than reporting a stale amount (cases 6 and 7), the join-not-outer-WHERE lesson
//      migrations/0268.pgsql.sql's product_groups_missing already learned.
//   4. TransferProduct()'s tare refusal is gone. A tare-enabled product transfers today; before
//      this plan it threw "Transferring tare weight enabled products is not yet possible"
//      unconditionally (ADR-0022 context limit 4). Case 10.
//   5. Weighing a vessel corrects the one entry at that location through the location's own
//      tare, dry stores untouched - and the present *product*-scoped tare mechanism gets the
//      same physical scenario wrong, which is the negative control
//      docs/plans/landed/29-working-container-replenishment.md's verification section asks for by
//      name. Cases 11-16.
//   6. The refusals a vessel weighing has to raise rather than silently misweigh: no tare
//      configured, more than one product at the location, a gross reading in the wrong unit,
//      and an unconvertible tare unit (ADR-0022 decision 3's "refused, never assumed"). Cases
//      17-20.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 1);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'admin');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
use Victual\Services\StockService;
use Victual\Controllers\Api\GenericEntityApiController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

$checks = 0;
$failures = 0;
$nextId = 9600;

function check(bool $ok, string $message): void
{
	global $checks, $failures;

	if ($ok)
	{
		$checks++;
		printf("  ok     %s\n", $message);

		return;
	}

	$failures++;
	printf("  FAIL   %s\n", $message);
}

function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')
		->withHeader('Content-Type', 'application/json')
		->withParsedBody($body);
}

/** A weight quantity unit and a piece quantity unit, so conversion cases have both kinds. */
function MakeQuantityUnit(string $name): int
{
	global $pdo, $nextId;

	$id = $nextId++;
	$pdo->prepare('INSERT INTO quantity_units (id, name, name_plural) VALUES (?, ?, ?)')
		->execute([$id, $name, $name]);

	return $id;
}

/** @return int The new location's id. */
function MakeLocation(string $name, ?float $tareWeight = null, ?int $tareQuId = null, int $active = 1): int
{
	global $pdo, $nextId;

	$id = $nextId++;
	$pdo->prepare('INSERT INTO locations (id, name, tare_weight, tare_qu_id, active) VALUES (?, ?, ?, ?, ?)')
		->execute([$id, $name, $tareWeight, $tareQuId, $active]);

	return $id;
}

/**
 * A product stocked in $quIdStock, with tare handling optionally enabled the old
 * (product-scoped) way, for the negative control cases only.
 */
function MakeProduct(int $quIdStock, int $active = 1, int $treatOpenedAsOutOfStock = 0, bool $enableTareWeightHandling = false, float $tareWeight = 0): int
{
	global $pdo, $nextId;

	$id = $nextId++;
	$pdo->prepare('INSERT INTO products
		(id, name, description, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, min_stock_amount,
		 default_best_before_days, active, treat_opened_as_out_of_stock, enable_tare_weight_handling, tare_weight)
		VALUES (?, ?, ?, 2, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?)')
		->execute([$id, 'Working Container Test Product ' . $id, 'Created by working-container-tests.php',
			$quIdStock, $quIdStock, $quIdStock, $quIdStock, $active, $treatOpenedAsOutOfStock, $enableTareWeightHandling ? 1 : 0, $tareWeight]);

	return $id;
}

function AddStock(int $productId, int $locationId, float $amount, int $open = 0): void
{
	global $pdo;

	$pdo->prepare('INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, open)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$productId, $amount, '2027-06-01', '2026-07-01', 'wct-' . uniqid('', true), 1.00, $locationId, $open]);
}

function MakeMinimum(int $productId, int $locationId, float $minimum): int
{
	global $pdo, $nextId;

	$id = $nextId++;
	$pdo->prepare('INSERT INTO product_location_min_stock (id, product_id, location_id, min_stock_amount) VALUES (?, ?, ?, ?)')
		->execute([$id, $productId, $locationId, $minimum]);

	return $id;
}

function Shortfall(int $productId, int $locationId): ?array
{
	global $pdo;

	$statement = $pdo->prepare('SELECT * FROM product_location_missing WHERE product_id = ? AND location_id = ?');
	$statement->execute([$productId, $locationId]);
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return $row === false ? null : $row;
}

function CheckShort(string $label, int $productId, int $locationId, float $expected): void
{
	$row = Shortfall($productId, $locationId);

	if ($row === null)
	{
		check(false, $label . ': expected short by ' . $expected . ', but the pair is not in the view at all');

		return;
	}

	check(abs((float)$row['amount_missing'] - $expected) < 0.000001,
		$label . ': short by ' . $row['amount_missing'] . ', expected ' . $expected);
}

function CheckNotShort(string $label, int $productId, int $locationId): void
{
	$row = Shortfall($productId, $locationId);

	check($row === null, $label . ($row === null ? '' : ': present with amount_missing ' . $row['amount_missing']));
}

echo "Working container replenishment (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

$quWeight = MakeQuantityUnit('Weight Test QU ' . uniqid());
$quOunce = MakeQuantityUnit('Ounce Test QU ' . uniqid());
$pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor) VALUES (?, ?, ?)')
	->execute([$quOunce, $quWeight, 0.0625]); // 16 oz per lb

// --- The shortfall view -----------------------------------------------------------------

// 1. THE CONTROL. A bin with 2 lb against a minimum of 5.
$dryStores = MakeLocation('Dry Stores ' . uniqid());
$bin = MakeLocation('Kitchen Bin ' . uniqid());
$flour = MakeProduct($quWeight);
MakeMinimum($flour, $bin, 5);
AddStock($flour, $bin, 2);
CheckShort('bin below its minimum', $flour, $bin, 3);

// 2. Backstock elsewhere does not satisfy the bin's own minimum - the whole point of a
//    location-scoped minimum rather than a product-scoped one.
AddStock($flour, $dryStores, 75);
CheckShort('backstock at another location does not fill the bin', $flour, $bin, 3);

// 3. Exactly at the minimum is not short.
$sugar = MakeProduct($quWeight);
MakeMinimum($sugar, $bin, 5);
AddStock($sugar, $bin, 5);
CheckNotShort('exactly at the minimum', $sugar, $bin);

// 4. Opened stock discounted for a product that treats it as out of stock: 6 held, 4 of it
//    opened, so 2 counts against a minimum of 5.
$rice = MakeProduct($quWeight, 1, 1);
MakeMinimum($rice, $bin, 5);
AddStock($rice, $bin, 2);
AddStock($rice, $bin, 4, 1);
CheckShort('opened stock discounted', $rice, $bin, 3);

// 5. THE CONTROL FOR 4. The same stock on a product that does not treat opened as out of
//    stock, so all 6 counts and the pair is not short.
$oats = MakeProduct($quWeight, 1, 0);
MakeMinimum($oats, $bin, 5);
AddStock($oats, $bin, 2);
AddStock($oats, $bin, 4, 1);
CheckNotShort('opened stock kept', $oats, $bin);

// 6. An inactive product drops out rather than reporting a stale shortfall.
$retiredProduct = MakeProduct($quWeight, 0);
MakeMinimum($retiredProduct, $bin, 5);
CheckNotShort('inactive product', $retiredProduct, $bin);

// 7. An inactive location drops out the same way.
$retiredLocation = MakeLocation('Retired Bin ' . uniqid(), null, null, 0);
$barley = MakeProduct($quWeight);
MakeMinimum($barley, $retiredLocation, 5);
CheckNotShort('inactive location', $barley, $retiredLocation);

// --- Independence from the shopping list -------------------------------------------------

// 8. A short (product, location) pair is not a product shortfall. stock_missing_products is
//    product-keyed and AddMissingProductsToShoppingList() adds a row per id in it; a bin
//    running low must not put its product on the list when backstock exists elsewhere.
check((int)$pdo->query('SELECT COUNT(*) FROM stock_missing_products WHERE id = ' . $flour)->fetchColumn() === 0,
	'a short bin puts no product into stock_missing_products (backstock exists)');

// 9. Running the shopping list top-up leaves the list alone for a pair that is short only at
//    its location.
$before = (int)$pdo->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();
StockService::GetInstance()->AddMissingProductsToShoppingList(1);
$after = (int)$pdo->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();
check($before === $after, 'a short bin adds nothing to the shopping list');

// --- TransferProduct()'s retired refusal -------------------------------------------------

// 10. A tare-enabled product transfers today. Before this plan, TransferProduct() threw
//     "Transferring tare weight enabled products is not yet possible" unconditionally
//     (ADR-0022 context limit 4) - backstock could never feed a vessel for such a product.
$tareProduct = MakeProduct($quWeight, 1, 0, true, 1.5);
AddStock($tareProduct, $dryStores, 10);
try
{
	StockService::GetInstance()->TransferProduct($tareProduct, 4, $dryStores, $bin);
	check(true, 'a tare-enabled product transfers without being refused');
}
catch (\Exception $e)
{
	check(false, 'a tare-enabled product transfers without being refused: threw "' . $e->getMessage() . '"');
}
check((float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $tareProduct . ' AND location_id = ' . $bin)->fetchColumn() === 4.0,
	'the transferred amount landed at the destination untouched by tare arithmetic');

// --- Weighing a vessel --------------------------------------------------------------------

// 11. THE SCENARIO. Three sealed 5 lb bags of flour in dry stores; a fourth bag's worth (5 lb)
//     transferred into the bin, which has a 1 lb tare in the same weight unit. The scale reads
//     a gross of 3.4 lb, so the bin should end up holding 2.4 lb net.
$weighFlour = MakeProduct($quWeight);
$weighBin = MakeLocation('Weigh Bin ' . uniqid(), 1.0, $quWeight);
$weighDryStores = MakeLocation('Weigh Dry Stores ' . uniqid());
AddStock($weighFlour, $weighDryStores, 5);
AddStock($weighFlour, $weighDryStores, 5);
AddStock($weighFlour, $weighDryStores, 5);
StockService::GetInstance()->TransferProduct($weighFlour, 5, $weighDryStores, $weighBin);
check((float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $weighFlour . ' AND location_id = ' . $weighDryStores)->fetchColumn() === 10.0,
	'dry stores holds the remaining two bags before weighing');

StockService::GetInstance()->WeighLocation($weighBin, 3.4, $quWeight);
$binAmount = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $weighFlour . ' AND location_id = ' . $weighBin)->fetchColumn();
check(abs($binAmount - 2.4) < 0.000001, 'the bin\'s entry is corrected to the tared net amount: got ' . $binAmount . ', expected 2.4');

// 12. DRY STORES IS UNCHANGED. Weighing the bin must not touch any entry at another location -
//     the whole reason tare has to live on the location rather than the product (ADR-0022
//     decision 4, context limit 2).
check((float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $weighFlour . ' AND location_id = ' . $weighDryStores)->fetchColumn() === 10.0,
	'dry stores is untouched by weighing the bin');

// 13. THE NEGATIVE CONTROL docs/plans/29 asks for by name: the present product-scoped tare
//     formula (ConsumeProduct()'s `abs($amount - $productDetails->stock_amount -
//     $product->tare_weight)`, from ADR-0022's own spike) reads the *whole product* total
//     rather than the one entry, so against this exact scenario (12.4 lb total: 10 in dry
//     stores + 2.4 in the bin, after the correct weighing above) it would answer a
//     consumption of 10.0 lb against the 0 lb that actually changed at any location other
//     than the bin - dry stores was not touched. Reproduced here as arithmetic, not by
//     calling the retired mechanism, since ADR-0022 decision 7 removes it from this codebase
//     entirely; the spike in .spike-adr22/RESULTS.md is where it was last measured against
//     real rows.
$totalNow = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $weighFlour)->fetchColumn();
$productScopedTareWeight = 1.0; // what the old mechanism would have had to borrow from products.tare_weight
$productScopedWouldConsume = abs(3.4 - $totalNow - $productScopedTareWeight);
check(abs($productScopedWouldConsume - 10.0) < 0.000001,
	'control: the retired product-scoped formula would misread this scenario as consuming ' . $productScopedWouldConsume . ' lb (expected the wrong answer, 10.0)');

// 14. NO TARE CONFIGURED. A location with neither tare_weight nor tare_qu_id refuses to be
//     weighed rather than silently treating the gross reading as net.
$noTareLocation = MakeLocation('No Tare Location ' . uniqid());
$noTareProduct = MakeProduct($quWeight);
AddStock($noTareProduct, $noTareLocation, 3);
try
{
	StockService::GetInstance()->WeighLocation($noTareLocation, 5, $quWeight);
	check(false, 'weighing a location with no tare configured is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), 'no tare configured'), 'weighing a location with no tare configured is refused: "' . $e->getMessage() . '"');
}

// 15. MORE THAN ONE PRODUCT. A shared shelf cannot be weighed as a single vessel.
$sharedShelf = MakeLocation('Shared Shelf ' . uniqid(), 0.5, $quWeight);
$productA = MakeProduct($quWeight);
$productB = MakeProduct($quWeight);
AddStock($productA, $sharedShelf, 2);
AddStock($productB, $sharedShelf, 2);
try
{
	StockService::GetInstance()->WeighLocation($sharedShelf, 5, $quWeight);
	check(false, 'weighing a location holding two products is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), 'More than one product'), 'weighing a location holding two products is refused: "' . $e->getMessage() . '"');
}

// 16. NOTHING STOCKED THERE AT ALL.
$emptyVessel = MakeLocation('Empty Vessel ' . uniqid(), 0.5, $quWeight);
try
{
	StockService::GetInstance()->WeighLocation($emptyVessel, 5, $quWeight);
	check(false, 'weighing a location with nothing stocked is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), 'No product is stocked'), 'weighing a location with nothing stocked is refused: "' . $e->getMessage() . '"');
}

// 17. THE WRONG UNIT. gross_qu_id, when given, must equal the location's own tare unit -
//     ADR-0022 question 5's "gross" contract, so a client's unit mismatch is refused rather
//     than misweighed.
$wrongUnitProduct = MakeProduct($quWeight);
$wrongUnitLocation = MakeLocation('Wrong Unit Location ' . uniqid(), 1.0, $quWeight);
AddStock($wrongUnitProduct, $wrongUnitLocation, 2);
try
{
	StockService::GetInstance()->WeighLocation($wrongUnitLocation, 5, $quOunce);
	check(false, 'a gross reading in the wrong unit is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), "location's own tare unit"), 'a gross reading in the wrong unit is refused: "' . $e->getMessage() . '"');
}

// 18. GROSS BELOW TARE.
try
{
	StockService::GetInstance()->WeighLocation($wrongUnitLocation, 0.5, $quWeight);
	check(false, 'a gross reading below the tare weight is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), 'less than'), 'a gross reading below the tare weight is refused: "' . $e->getMessage() . '"');
}

// 19. UNCONVERTIBLE TARE UNIT. The location's tare unit cannot be converted to the stocked
//     product's stock unit - refused, never assumed (ADR-0022 decision 3).
$unconvertibleQu = MakeQuantityUnit('Unconvertible Test QU ' . uniqid());
$unconvertibleLocation = MakeLocation('Unconvertible Location ' . uniqid(), 1.0, $unconvertibleQu);
$unconvertibleProduct = MakeProduct($quWeight);
AddStock($unconvertibleProduct, $unconvertibleLocation, 2);
try
{
	StockService::GetInstance()->WeighLocation($unconvertibleLocation, 5, $unconvertibleQu);
	check(false, 'an unconvertible tare unit is refused');
}
catch (\Exception $e)
{
	check(str_contains($e->getMessage(), 'cannot be converted'), 'an unconvertible tare unit is refused: "' . $e->getMessage() . '"');
}

// 20. A CONVERSION THAT DOES EXIST. The ounce-to-weight-unit conversion set up at the top:
//     a location tared in ounces, weighed gross at 20 oz against a 4 oz tare, nets 16 oz =
//     1 lb in the product's own stock unit.
$ounceLocation = MakeLocation('Ounce Location ' . uniqid(), 4.0, $quOunce);
$ounceProduct = MakeProduct($quWeight);
AddStock($ounceProduct, $ounceLocation, 0.5);
StockService::GetInstance()->WeighLocation($ounceLocation, 20, $quOunce);
$ounceResult = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ' . $ounceProduct . ' AND location_id = ' . $ounceLocation)->fetchColumn();
check(abs($ounceResult - 1.0) < 0.000001, 'a tare unit that differs from the stock unit converts correctly: got ' . $ounceResult . ', expected 1.0');

// --- The entities -----------------------------------------------------------------------

// 21. product_location_missing is exposed read-only.
$api = new GenericEntityApiController($container);

$response = $api->GetObjects(request(), new Response(), ['entity' => 'product_location_missing']);
check($response->getStatusCode() === 200, 'the shortfall entity lists');

foreach ([['AddObject', 'POST'], ['EditObject', 'PUT'], ['DeleteObject', 'DELETE']] as [$method, $verb])
{
	$response = $api->$method(request($verb, ['min_stock_amount' => 1]), new Response(), ['entity' => 'product_location_missing', 'objectId' => 1]);
	check($response->getStatusCode() >= 400, 'the shortfall entity refuses ' . $verb);
}

// 22. product_location_min_stock round-trips through the generic write path.
$roundTripProduct = MakeProduct($quWeight);
$response = $api->AddObject(request('POST', ['product_id' => $roundTripProduct, 'location_id' => $bin, 'min_stock_amount' => 2.5]), new Response(), ['entity' => 'product_location_min_stock']);
check($response->getStatusCode() === 200, 'a location minimum is created through /objects/product_location_min_stock');

$created = json_decode((string)$response->getBody(), true);
$getResponse = $api->GetObject(request(), new Response(), ['entity' => 'product_location_min_stock', 'objectId' => $created['created_object_id']]);
$row = json_decode((string)$getResponse->getBody(), true);
check(abs((float)$row['min_stock_amount'] - 2.5) < 0.000001, 'min_stock_amount round-trips through /objects/product_location_min_stock');

echo "\n";

if ($failures === 0)
{
	echo "EVERY WORKING CONTAINER CASE ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
