<?php

// Does an opened container's measured remainder behave the way ADR-0022 and
// docs/plans/landed/28-open-container-measurement.md say it must?
//
//   php open-container-measurement-tests.php
//
// PostgreSQL only, and for the reason the locations and group-minimum phases are:
// migrations/0275.pgsql.sql - the four opened_* columns, the coherence CHECK, and the
// rewritten stock_splits/stock_current views - is above
// DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so a SQLite side would be asked
// about columns and views it does not have. The view phase cannot stand in for it either:
// it seeds SQLite and copies rows into PostgreSQL through the importer's common-column
// logic, so opened_amount would arrive NULL on every row and every assertion about a
// measured entry would be an assertion about an unmeasured one.
//
// WHAT IT GUARDS, mapped to the plan's own Verification list:
//
//   1. Coexistence. Three sealed bags plus one measured open bag total four bags, and the
//      open bag's own remainder resolves through quantity_unit_conversions_resolved. Case 1.
//   2. The old tare mechanism, retired rather than merely disabled. A pre-existing
//      tare-enabled product (enable_tare_weight_handling = 1, as a row from before the
//      retirement would be - AddObject/EditObject refuse the 0 -> 1 transition from here on,
//      never an already-1 row) consumes and adds net now, with none of the
//      old gross-reading arithmetic - the spike's negative control run forward past the
//      retirement it argues for. Case 2.
//   3. Container identity. Two opened containers of one product, one measured and one not,
//      keep independent state and correct totals. Case 3.
//   4. A pre-existing open = 1, amount > 1 row (the state OpenProduct() produced before this
//      plan, and the migration's chosen handling of it: left alone, unmeasurable until
//      split) is refused a measurement outright by the coherence CHECK; splitting it first
//      (OpenProduct()'s own partial-open path) leaves a 1-unit entry a measurement attaches
//      to and an unmeasured rest. Case 4.
//   5. Undo. Measure an entry, consume it fully, undo the consumption: remainder, unit,
//      tare and timestamp restored exactly. Case 5.
//   6. Undoing an opening on a measured entry leaves a legal (unmeasured, unopened) state -
//      the spike's sharper finding: clearing all four columns is required for the undo to
//      complete at all, not merely to satisfy style. Case 6.
//   7. Splitting a measured entry carries the measurement once, never twice: opening part of
//      a multi-unit entry with a measurement leaves the opened unit measured and the
//      unopened rest with nothing at all. Case 7.
//   8. An unconvertible measurement unit is refused when it is recorded. Case 8.
//   9. A conversion deleted after measurements exist leaves the derived fraction
//      unavailable, never reinterpreted through an assumed factor. Case 9.
//  10. A volume container measured by weight, resolved through a per-product conversion.
//      Case 10.
//  11. Compaction skips a measured entry while an unmeasured split entry beside it, sharing
//      every stock_splits group-by column, still merges in the same run. Case 11.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9501);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'open-container-measurement-caller');
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

function refusal(string $sql, array $parameters = []): ?string
{
	global $pdo;

	try
	{
		$statement = $pdo->prepare($sql);
		$statement->execute($parameters);

		return null;
	}
	catch (PDOException $exception)
	{
		return $exception->getMessage();
	}
}

function MakeQu(string $name, string $namePlural): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
	$statement->execute([$name, $namePlural]);

	return intval($statement->fetchColumn());
}

function MakeConversion(int $fromQuId, int $toQuId, float $factor, ?int $productId): void
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)');
	$statement->execute([$fromQuId, $toQuId, $factor, $productId]);
}

function MakeProduct(string $name, int $quIdStock, int $locationId, array $extra = []): int
{
	global $pdo;

	$columns = array_merge([
		'name' => $name,
		'description' => 'Created by open-container-measurement-tests.php',
		'location_id' => $locationId,
		'qu_id_purchase' => $quIdStock,
		'qu_id_stock' => $quIdStock,
		'min_stock_amount' => 0,
		'default_best_before_days' => 0,
		'active' => 1,
		'treat_opened_as_out_of_stock' => 0,
	], $extra);

	$cols = array_keys($columns);
	$sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') RETURNING id';
	$statement = $pdo->prepare($sql);
	$statement->execute(array_values($columns));

	return intval($statement->fetchColumn());
}

function MakeStockRow(int $productId, float $amount, string $stockId, int $locationId, array $extra = []): int
{
	global $pdo;

	$columns = array_merge([
		'product_id' => $productId,
		'amount' => $amount,
		'best_before_date' => '2099-01-01',
		'purchased_date' => '2026-09-01',
		'stock_id' => $stockId,
		'price' => 3.00,
		'open' => 0,
		'location_id' => $locationId,
	], $extra);

	$cols = array_keys($columns);
	$sql = 'INSERT INTO stock (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ') RETURNING id';
	$statement = $pdo->prepare($sql);
	$statement->execute(array_values($columns));

	return intval($statement->fetchColumn());
}

function StockRow(int $stockRowId): object
{
	global $pdo;

	$statement = $pdo->prepare('SELECT * FROM stock WHERE id = ?');
	$statement->execute([$stockRowId]);

	return $statement->fetch(PDO::FETCH_OBJ);
}

function StockAmount(int $productId): float
{
	global $pdo;

	$statement = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM stock WHERE product_id = ?');
	$statement->execute([$productId]);

	return (float)$statement->fetchColumn();
}

echo "Open container measurement (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

$pdo->exec('DELETE FROM stock_log');
$pdo->exec('DELETE FROM stock');
$pdo->exec('DELETE FROM stock_entry_origins');
$pdo->exec('DELETE FROM quantity_unit_conversions');
$pdo->exec('DELETE FROM products');

$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9501');
$pdo->exec('DELETE FROM user_roles WHERE user_id = 9501');
$pdo->exec('DELETE FROM users WHERE id = 9501');
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9501, 'open-container-measurement-caller', 'fixture')");

$statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT 9501, id FROM roles WHERE code = ?');
$statement->execute(['ADMIN']);

$locationId = (int)$pdo->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
if ($locationId === 0)
{
	$statement = $pdo->prepare("INSERT INTO locations (name) VALUES ('Fixture location') RETURNING id");
	$statement->execute();
	$locationId = intval($statement->fetchColumn());
}

$bag = MakeQu('Bag', 'Bags');
$pound = MakeQu('Pound', 'Pounds');
$gallon = MakeQu('Gallon', 'Gallons');

$stockService = StockService::GetInstance();
$api = new GenericEntityApiController($container);

// --- 1. Coexistence ------------------------------------------------------------------

echo "1. coexistence, three sealed bags plus one measured open bag\n";

// "1 bag = 5 lb" - the ADR's own worked example.
$flour = MakeProduct('Baking flour', $bag, $locationId);
MakeConversion($pound, $bag, 0.2, $flour);

MakeStockRow($flour, 1, 'flour-sealed-1', $locationId);
MakeStockRow($flour, 1, 'flour-sealed-2', $locationId);
MakeStockRow($flour, 1, 'flour-sealed-3', $locationId);
$openBagId = MakeStockRow($flour, 1, 'flour-open', $locationId, [
	'open' => 1,
	'opened_date' => '2026-09-10',
	'opened_amount' => 1.2,
	'opened_qu_id' => $pound,
	'opened_measured_at' => '2026-09-10 09:00:00',
]);

check(StockAmount($flour) === 4.0, 'the bag count is unchanged by opening one - still 4 bags');

$statement = $pdo->prepare('SELECT s.opened_amount * qucr.factor AS fraction
	FROM stock s
	JOIN quantity_unit_conversions_resolved qucr
		ON qucr.product_id = s.product_id AND qucr.from_qu_id = s.opened_qu_id AND qucr.to_qu_id = ?
	WHERE s.id = ?');
$statement->execute([$bag, $openBagId]);
$fraction = (float)$statement->fetchColumn();

check(abs($fraction - 0.24) < 0.0001, "the open bag's own remainder resolves to 0.24 bag (got $fraction)");

// --- 2. The old tare mechanism, retired -----------------------------------------------

echo "\n2. the old tare mechanism is retired, not merely disabled\n";

// enable_tare_weight_handling = 1 set directly, simulating a row that predates the
// retirement - AddObject/EditObject refuse the 0 -> 1 transition from here on, but never
// touch a row that already carries it.
$weighedFlour = MakeProduct('Weighed flour', $pound, $locationId, [
	'enable_tare_weight_handling' => 1,
	'tare_weight' => 0.2,
]);
MakeStockRow($weighedFlour, 5, 'weighed-1', $locationId);
MakeStockRow($weighedFlour, 5, 'weighed-2', $locationId);
MakeStockRow($weighedFlour, 5, 'weighed-3', $locationId);
MakeStockRow($weighedFlour, 5, 'weighed-open', $locationId, ['open' => 1, 'opened_date' => '2026-09-10']);

// Product total before: 20 lb (four 5 lb containers). The spike's negative control found
// the pre-retirement ConsumeProduct() formula answering 18.8 lb "consumed" for a canister
// that actually gave up 3.8 - reading the whole-product total rather than one entry. With
// the arithmetic removed, $amount is the net amount to consume, full stop.
StockAmount($weighedFlour) === 20.0 or check(false, 'fixture setup: 20 lb before consuming');

$transactionId = null;
$stockService->ConsumeProduct($weighedFlour, 3.8, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $transactionId);

check(abs(StockAmount($weighedFlour) - 16.2) < 0.0001, 'consuming 3.8 lb (net, no tare arithmetic) leaves 16.2 lb, not 20 - 18.8 = 1.2');

// Enabling the flag on a product that does not already carry it answers 400 (ADR-0022
// decisions 4 and 7); this is the product write path, not StockService, and belongs beside
// the retirement it enforces.
$freshProduct = MakeProduct('Not tare enabled', $bag, $locationId);
$response = $api->EditObject(request('PUT', ['enable_tare_weight_handling' => 1]), new Response(), ['entity' => 'products', 'objectId' => $freshProduct]);
check($response->getStatusCode() === 400, 'enabling the flag on a product that does not already have it is refused with 400');

// Re-saving an already-enabled product unchanged must still work - this is a refusal on the
// 0 -> 1 transition, not a constraint on data that predates the retirement.
// EditObject answers 204 on success (EmptyApiResponse), not 200.
$response = $api->EditObject(request('PUT', ['enable_tare_weight_handling' => 1, 'name' => 'Weighed flour']), new Response(), ['entity' => 'products', 'objectId' => $weighedFlour]);
check($response->getStatusCode() === 204, 'an already tare-enabled product can still be saved unchanged');

// --- 3. Container identity -------------------------------------------------------------

echo "\n3. two opened containers, one measured and one not\n";

$flour2 = MakeProduct('Container identity flour', $bag, $locationId);
MakeConversion($pound, $bag, 0.2, $flour2);
$measuredId = MakeStockRow($flour2, 1, 'ci-measured', $locationId, [
	'open' => 1, 'opened_date' => '2026-09-10',
	'opened_amount' => 1.2, 'opened_qu_id' => $pound, 'opened_measured_at' => '2026-09-10 09:00:00',
]);
$unmeasuredId = MakeStockRow($flour2, 1, 'ci-unmeasured', $locationId, ['open' => 1, 'opened_date' => '2026-09-11']);

check(StockRow($measuredId)->opened_amount == 1.2 && StockRow($unmeasuredId)->opened_amount === null,
	'each container keeps its own measurement state');
check(StockAmount($flour2) === 2.0, 'both remain their own row and the total stays correct');

$measurement = ['amount' => 0.9, 'qu_id' => $pound, 'is_gross' => false, 'tare' => null];
$stockService->MeasureStockEntry($measuredId, $measurement);

check(StockRow($unmeasuredId)->opened_amount === null, 'measuring one container never touches the other');
check(StockRow($measuredId)->opened_amount == 0.9, 'the measured container reflects the new reading');

// --- 4. A pre-existing open = 1, amount > 1 row -----------------------------------------

echo "\n4. open = 1, amount > 1 - refused a measurement, or split first\n";

$flour3 = MakeProduct('Multiunit flour', $bag, $locationId);
$multiunitId = MakeStockRow($flour3, 3, 'multiunit', $locationId, ['open' => 1, 'opened_date' => '2026-09-06']);

$message = refusal('UPDATE stock SET opened_amount = ?, opened_qu_id = ? WHERE id = ?', [1.5, $pound, $multiunitId]);
check($message !== null && str_contains($message, 'stock_measurement_coherence_check'),
	'attaching a measurement directly to an open = 1, amount = 3 row is refused');

// The migration's own choice - left alone rather than auto-split - stays queryable and
// simply cannot accept a measurement until it is split. OpenProduct()'s own partial-open
// path is that split, exercised via a fresh multi-unit entry and its own $measurement
// parameter (opening exactly one unit while recording what it holds).
$flour4 = MakeProduct('Splittable flour', $bag, $locationId);
$splittableId = MakeStockRow($flour4, 3, 'splittable', $locationId);

$transactionId = null;
$stockService->OpenProduct($flour4, 1.0, 'splittable', $transactionId, false, [
	'amount' => 0.5, 'qu_id' => $bag, 'is_gross' => false, 'tare' => null,
]);

$statement = $pdo->prepare('SELECT * FROM stock WHERE product_id = ? ORDER BY amount');
$statement->execute([$flour4]);
$rows = $statement->fetchAll(PDO::FETCH_OBJ);

check(count($rows) === 2, 'opening one unit of a three-unit entry with a measurement splits it into two rows');
$openedRow = FindByAmount($rows, 1.0);
$restRow = FindByAmount($rows, 2.0);
check($openedRow !== null && $openedRow->open == 1 && $openedRow->opened_amount == 0.5,
	'the opened unit (amount = 1) carries the measurement');
check($restRow !== null && $restRow->open == 0 && $restRow->opened_amount === null,
	'the unopened rest (amount = 2) carries nothing');

function FindByAmount(array $rows, float $amount): ?object
{
	foreach ($rows as $row)
	{
		if (abs((float)$row->amount - $amount) < 0.0001)
		{
			return $row;
		}
	}

	return null;
}

// --- 5. Undo: measure, consume fully, undo --------------------------------------------

echo "\n5. undo round trip through a full consume\n";

$flour5 = MakeProduct('Undo flour', $bag, $locationId);
$undoId = MakeStockRow($flour5, 1, 'undo-1', $locationId, [
	'open' => 1, 'opened_date' => '2026-09-10',
	'opened_amount' => 1.2, 'opened_qu_id' => $pound, 'opened_tare' => 0.05, 'opened_measured_at' => '2026-09-10 08:00:00',
]);

$before = StockRow($undoId);

$transactionId = null;
$stockService->ConsumeProduct($flour5, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'undo-1', null, null, $transactionId);

check(StockAmount($flour5) === 0.0, 'the entry is fully consumed and deleted');

$logRow = $pdo->query('SELECT id FROM stock_log WHERE stock_id = \'undo-1\' AND transaction_type = \'consume\' ORDER BY id DESC LIMIT 1')->fetchColumn();
$stockService->UndoBooking((int)$logRow);

$rebuilt = $pdo->prepare('SELECT * FROM stock WHERE stock_id = ?');
$rebuilt->execute(['undo-1']);
$after = $rebuilt->fetch(PDO::FETCH_OBJ);

check($after !== false
	&& (float)$after->opened_amount === (float)$before->opened_amount
	&& (int)$after->opened_qu_id === (int)$before->opened_qu_id
	&& (float)$after->opened_tare === (float)$before->opened_tare
	&& $after->opened_measured_at === $before->opened_measured_at,
	'remainder, unit, tare and timestamp are all restored exactly');

// --- 6. Undoing an opening on a measured entry -----------------------------------------

echo "\n6. undoing an opening on a measured entry\n";

$flour6 = MakeProduct('Undo-open flour', $bag, $locationId);
$undoOpenId = MakeStockRow($flour6, 1, 'undo-open-1', $locationId);

$transactionId = null;
$stockService->OpenProduct($flour6, 1.0, 'undo-open-1', $transactionId, false, [
	'amount' => 0.9, 'qu_id' => $bag, 'is_gross' => false, 'tare' => null,
]);

check(StockRow($undoOpenId)->opened_amount == 0.9, 'fixture: the entry is open and measured');

$openLogRow = $pdo->query('SELECT id FROM stock_log WHERE stock_id = \'undo-open-1\' AND transaction_type = \'product-opened\' ORDER BY id DESC LIMIT 1')->fetchColumn();
$stockService->UndoBooking((int)$openLogRow);

$afterUndoOpen = StockRow($undoOpenId);
check($afterUndoOpen->open == 0 && $afterUndoOpen->opened_date === null,
	'the entry is unopened again');
check($afterUndoOpen->opened_amount === null && $afterUndoOpen->opened_qu_id === null
	&& $afterUndoOpen->opened_tare === null && $afterUndoOpen->opened_measured_at === null,
	'all four measurement columns are cleared, which is required for the coherence CHECK to accept this row at all');

// --- 7. A split carries the measurement once --------------------------------------------

echo "\n7. a split carries the measurement once, never twice\n";

$flour7 = MakeProduct('Split-carry flour', $bag, $locationId);
$splitCarryId = MakeStockRow($flour7, 2, 'split-carry', $locationId);

$transactionId = null;
$stockService->OpenProduct($flour7, 1.0, 'split-carry', $transactionId, false, [
	'amount' => 0.6, 'qu_id' => $bag, 'is_gross' => false, 'tare' => null,
]);

$statement = $pdo->prepare('SELECT SUM(CASE WHEN opened_amount IS NOT NULL THEN 1 ELSE 0 END) AS measured_rows,
	SUM(CASE WHEN opened_amount IS NOT NULL THEN opened_amount ELSE 0 END) AS measured_total
	FROM stock WHERE product_id = ?');
$statement->execute([$flour7]);
$summary = $statement->fetch(PDO::FETCH_OBJ);

check((int)$summary->measured_rows === 1, 'exactly one row carries the measurement after the split');
check(abs((float)$summary->measured_total - 0.6) < 0.0001, 'the measurement is not duplicated across both halves');

// --- 8. An unconvertible unit is refused at entry ---------------------------------------

echo "\n8. an unconvertible measurement unit is refused\n";

// Pack (the second baseline-seeded unit) has no conversion path to Bag anywhere in this
// fixture, product-specific or default.
$packId = (int)$pdo->query("SELECT id FROM quantity_units WHERE name = 'Pack'")->fetchColumn();

$flour8 = MakeProduct('Unconvertible flour', $bag, $locationId);
$unconvertibleId = MakeStockRow($flour8, 1, 'unconvertible', $locationId, ['open' => 1, 'opened_date' => '2026-09-01']);

try
{
	$stockService->MeasureStockEntry($unconvertibleId, ['amount' => 1.0, 'qu_id' => $packId, 'is_gross' => false, 'tare' => null]);
	check(false, 'measuring in an unconvertible unit should have thrown');
}
catch (\Exception $exception)
{
	check(true, 'measuring in an unconvertible unit is refused: ' . $exception->getMessage());
}

check(StockRow($unconvertibleId)->opened_amount === null, 'the refused write left nothing stored');

// --- 9. A conversion deleted after measurements exist ------------------------------------

echo "\n9. a conversion deleted after measurements exist\n";

$flour9 = MakeProduct('Deleted-conversion flour', $bag, $locationId);
MakeConversion($pound, $bag, 0.2, $flour9);
$deletedConvId = MakeStockRow($flour9, 1, 'deleted-conv', $locationId, [
	'open' => 1, 'opened_date' => '2026-09-01',
	'opened_amount' => 1.2, 'opened_qu_id' => $pound, 'opened_measured_at' => '2026-09-01 09:00:00',
]);

$statement = $pdo->prepare('SELECT qucr.factor FROM quantity_unit_conversions_resolved qucr WHERE qucr.product_id = ? AND qucr.from_qu_id = ? AND qucr.to_qu_id = ?');
$statement->execute([$flour9, $pound, $bag]);
check($statement->fetchColumn() !== false, 'fixture: the fraction resolves before the conversion is deleted');

$pdo->exec('DELETE FROM quantity_unit_conversions WHERE product_id = ' . $flour9);

$statement->execute([$flour9, $pound, $bag]);
$afterDelete = $statement->fetchColumn();

check($afterDelete === false, 'the derived fraction is unavailable once the conversion is gone');
check(StockRow($deletedConvId)->opened_amount == 1.2, 'the raw measurement itself survives the deletion untouched');

// --- 10. Volume measured by weight -------------------------------------------------------

echo "\n10. a volume container measured by weight\n";

$milk = MakeProduct('Milk', $gallon, $locationId);
MakeConversion($pound, $gallon, 1.0 / 8.6, $milk);
$milkId = MakeStockRow($milk, 1, 'milk-1', $locationId, [
	'open' => 1, 'opened_date' => '2026-09-12',
	'opened_amount' => 4.3, 'opened_qu_id' => $pound, 'opened_measured_at' => '2026-09-12 07:00:00',
]);

$statement = $pdo->prepare('SELECT s.opened_amount * qucr.factor AS gallons_remaining
	FROM stock s
	JOIN quantity_unit_conversions_resolved qucr
		ON qucr.product_id = s.product_id AND qucr.from_qu_id = s.opened_qu_id AND qucr.to_qu_id = ?
	WHERE s.id = ?');
$statement->execute([$gallon, $milkId]);
$gallonsRemaining = (float)$statement->fetchColumn();

check(abs($gallonsRemaining - 0.5) < 0.0001, "4.3 lb resolves to 0.5 gallon through the per-product conversion (got $gallonsRemaining)");

// --- 11. Compaction skips a measured entry -----------------------------------------------

echo "\n11. a measured entry is left alone by CompactStockEntries()\n";

$flour11 = MakeProduct('Compaction flour', $bag, $locationId);
$compactA = MakeStockRow($flour11, 1, 'compact-a', $locationId, ['open' => 1, 'opened_date' => '2026-09-05', 'price' => 2.90, 'purchased_date' => '2026-09-03', 'best_before_date' => '2026-12-10']);
$compactB = MakeStockRow($flour11, 1, 'compact-b', $locationId, ['open' => 1, 'opened_date' => '2026-09-05', 'price' => 2.90, 'purchased_date' => '2026-09-03', 'best_before_date' => '2026-12-10']);
$compactC = MakeStockRow($flour11, 1, 'compact-c', $locationId, [
	'open' => 1, 'opened_date' => '2026-09-05', 'price' => 2.90, 'purchased_date' => '2026-09-03', 'best_before_date' => '2026-12-10',
	'opened_amount' => 0.8, 'opened_qu_id' => $bag, 'opened_measured_at' => '2026-09-05 10:00:00',
]);

$stockService->CompactStockEntries($flour11);

$statement = $pdo->prepare('SELECT stock_id, amount, opened_amount FROM stock WHERE product_id = ? ORDER BY stock_id');
$statement->execute([$flour11]);
$compacted = $statement->fetchAll(PDO::FETCH_OBJ);

check(count($compacted) === 2, 'the two unmeasured rows compacted into one, the measured row untouched - three rows became two');

$measuredSurvivor = null;
$mergedSurvivor = null;
foreach ($compacted as $row)
{
	if ($row->opened_amount !== null)
	{
		$measuredSurvivor = $row;
	}
	else
	{
		$mergedSurvivor = $row;
	}
}

check($measuredSurvivor !== null && (float)$measuredSurvivor->amount === 1.0 && (float)$measuredSurvivor->opened_amount === 0.8,
	'the measured entry kept its own amount and remainder, untouched by compaction');
check($mergedSurvivor !== null && (float)$mergedSurvivor->amount === 2.0,
	'the two unmeasured entries merged into one row of amount 2');

echo "\n";

if ($failures === 0)
{
	echo "EVERY OPEN CONTAINER MEASUREMENT ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
