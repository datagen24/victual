<?php

// Does a correction to a stock entry move the average price, whatever produced the entry?
//
//   php average-price-tests.php
//
// Runs against whichever engine VICTUAL_DATAPATH's config.php selects. The runner points it
// at PostgreSQL only, like the rbac phase and unlike the rollback and chores ones: the
// subject is migrations/0267.pgsql.sql, which is above the SQLite freeze, so a SQLite side
// would be asked about a view it does not have.
//
// Like the rollback phase this asks one engine a question rather than comparing two, and it
// enters the application: what is being checked is the answer StockService and the price
// views agree on after a sequence of real bookings, and neither a view comparison nor a
// trigger comparison can see it. The view tests structurally cannot, for two reasons - they
// compare the two engines against each other rather than against an expected value, so both
// agreeing on a wrong answer passes; and their fixtures contain no stock-edit rows at all,
// so stock_edited_entries is only ever exercised empty.
//
// WHY IT EXISTS. products_average_price responded to an edit of a stock entry only when
// that entry carried an origin booking - a purchase, a positive inventory correction, a
// self-production - with its own stock_id. StockService::OpenProduct() splits an entry when
// the amount being opened is smaller than the entry, and the remainder is a `stock` row with
// a fresh stock_id and no booking of its own, so an edit of that remainder was silently
// ignored: buy 500 at 1.74, open 100, correct the remaining 400 to 350, and the average
// still weighted the product at 500. The same correction on an entry that was never split
// weighted it at 450.
//
// So every split case here is paired with an unsplit control that must answer the same
// number. A pair is what makes the assertion mean something: the split case alone would be
// satisfied by any change that moved the average, and the control says which way.
//
// The prices are deliberately not all equal. Two purchases at 1.00 and 2.00 mean a wrong
// weight shows up as a wrong average; with one price the average is that price no matter how
// badly the weights are computed, which is the shape of test that passes through the bug.
//
// Both the view and the cache are asserted. cache__products_average_price is what
// uihelper_product_details and uihelper_stock_current_overview actually read, it is written
// only by the stock_log triggers, and a fix that moved the view while leaving the cache
// stale would look exactly like no fix at all to every reader that matters.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: VICTUAL_ROOT_PATH . '/data');
}

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once VICTUAL_ROOT_PATH . '/config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

// Fixed rather than relative to today, for the same reason the view seeds are: a due date
// computed from the clock makes a failure depend on when it was run.
const DUE_DATE = '2027-06-01';
const PURCHASED_DATE = '2026-07-01';

$db = DatabaseService::GetInstance();
$pdo = $db->GetDbConnectionRaw();
$stock = StockService::GetInstance();

$failures = 0;
$nextProductId = 9300;

/** A product with nothing unusual about it: openable, no tare weight, stocked in Pieces. */
function MakeProduct(PDO $pdo, int $id): int
{
	$statement = $pdo->prepare('INSERT INTO products (id, name, description, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
		VALUES (?, ?, ?, 2, 2, 2, 0, 0)');
	$statement->execute([$id, 'Average Price Test Product ' . $id, 'Created by average-price-tests.php']);

	return $id;
}

/**
 * The two purchases every case starts from: 500 at 1.00 and 100 at 2.00.
 *
 * The second one is never touched by any case. It is there so that the average is a
 * weighted one rather than a single price, which is what lets a wrong weight be seen.
 *
 * @return string The stock_id of the 500 entry, so a case can name it when opening
 */
function TwoPurchases(StockService $stock, int $productId): string
{
	$transactionId = null;
	$stock->AddProduct($productId, 500, DUE_DATE, StockService::TRANSACTION_TYPE_PURCHASE, PURCHASED_DATE, 1.00, 2, 1, $transactionId);
	$bigEntryStockId = StockIdOfNewestEntry($productId);

	$transactionId = null;
	$stock->AddProduct($productId, 100, DUE_DATE, StockService::TRANSACTION_TYPE_PURCHASE, PURCHASED_DATE, 2.00, 2, 1, $transactionId);

	return $bigEntryStockId;
}

/** The stock_id of the most recently created stock entry of a product. */
function StockIdOfNewestEntry(int $productId): string
{
	global $pdo;

	$statement = $pdo->prepare('SELECT stock_id FROM stock WHERE product_id = ? ORDER BY id DESC LIMIT 1');
	$statement->execute([$productId]);

	return (string)$statement->fetchColumn();
}

/**
 * Corrects one entry to a new amount, changing nothing else about it.
 *
 * Entries are named by stock_id rather than by amount, and the amount a case expects to
 * find is asserted rather than assumed. Both because the shapes here collide otherwise: the
 * opened half of the 500 entry and the untouched 2.00 purchase are both 100 units, and a
 * case that edited the second while meaning the first would change the price of the row that
 * makes the average a weighted one - which reads as a failure of the code under test.
 *
 * Price, dates and open state are carried over from the row, so a correction is a
 * correction of the amount and not of everything else at once. EditStockEntry() replaces
 * every field it is given, including the open flag, so the opened half has to be handed
 * back its own.
 */
function CorrectTo(StockService $stock, PDO $pdo, string $stockId, float $fromAmount, float $toAmount): string
{
	$statement = $pdo->prepare('SELECT id, amount, price, best_before_date, purchased_date, location_id, shopping_location_id, open FROM stock WHERE stock_id = ?');
	$statement->execute([$stockId]);

	$entry = $statement->fetch(PDO::FETCH_ASSOC);
	if ($entry === false)
	{
		throw new \Exception('No stock entry ' . $stockId . ' - the case did not set up what it thinks it did');
	}

	if (abs((float)$entry['amount'] - $fromAmount) > 0.000001)
	{
		throw new \Exception('Stock entry ' . $stockId . ' holds ' . $entry['amount'] . ', not the ' . $fromAmount . ' the case expected');
	}

	return $stock->EditStockEntry((int)$entry['id'], $toAmount, $entry['best_before_date'], (int)$entry['location_id'],
		(int)$entry['shopping_location_id'], (float)$entry['price'], (bool)$entry['open'], $entry['purchased_date']);
}

/**
 * Asserts the average, from the view and from the cache the API actually reads.
 *
 * Compared with a tolerance because these are floats: the value is a sum of products
 * divided by a sum, and ADR-0005 records that the last bit of it is not something to assert
 * on. The differences this file is about are in the first decimal place.
 */
function Case_(string $label, int $productId, float $expected): void
{
	global $pdo, $failures;

	$fromView = $pdo->query('SELECT price FROM products_average_price WHERE product_id = ' . $productId)->fetchColumn();
	$fromCache = $pdo->query('SELECT price FROM cache__products_average_price WHERE product_id = ' . $productId)->fetchColumn();

	if ($fromView === false)
	{
		printf("  FAIL   %-34s no average at all, expected %.6f\n", $label, $expected);
		$failures++;

		return;
	}

	if (abs((float)$fromView - $expected) > 0.000001)
	{
		printf("  FAIL   %-34s view says %.6f, expected %.6f\n", $label, (float)$fromView, $expected);
		$failures++;

		return;
	}

	if ($fromCache === false || abs((float)$fromCache - $expected) > 0.000001)
	{
		printf("  FAIL   %-34s view is right (%.6f) but the cache says %s\n", $label, (float)$fromView, $fromCache === false ? 'nothing' : sprintf('%.6f', (float)$fromCache));
		$failures++;

		return;
	}

	printf("  ok     %-34s %.6f\n", $label, (float)$fromView);
}

echo 'Average price after a correction (' . $db->GetDialect()->GetName() . ")\n\n";

// The unweighted starting point every case below moves away from: 500 at 1.00 and 100 at
// 2.00 is 700/600. A case that failed to correct anything lands back here, which is what
// tells a wrong answer apart from a missing one.
const UNCORRECTED = 700 / 600;

// Correcting 500 down to 450 leaves 450 at 1.00 and 100 at 2.00.
const CORRECTED = 650 / 550;

// 1. THE CONTROL. An entry that was never split, corrected from 500 to 450. This has always
//    worked, and it is here to say what the split cases below are being measured against.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
CorrectTo($stock, $pdo, $bigEntryStockId, 500, 450);
Case_('unsplit entry, corrected', $productId, CORRECTED);

// 2. THE DEFECT. The same 50 units taken off an entry that a partial open split away from
//    its purchase. Before migrations/0267.pgsql.sql this answered UNCORRECTED.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
CorrectTo($stock, $pdo, StockIdOfNewestEntry($productId), 400, 350);
Case_('split remainder, corrected', $productId, CORRECTED);

// 3. The other half of the same split. The opened entry does carry the purchase booking, so
//    this worked before - but the amount it reconstructs from is now the group's, and a fix
//    that only looked at the remainder would get this one wrong.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
CorrectTo($stock, $pdo, $bigEntryStockId, 100, 50);
Case_('opened half, corrected', $productId, CORRECTED);

// 4. A CHAIN. A remainder split again by a second open, then corrected. The simulated-year
//    parity suite found four generations on one product, so one level of resolution is not
//    enough; this is what makes the recorded link the origin rather than the parent.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
$transactionId = null;
$stock->OpenProduct($productId, 100, StockIdOfNewestEntry($productId), $transactionId);
CorrectTo($stock, $pdo, StockIdOfNewestEntry($productId), 300, 250);
Case_('twice-split remainder, corrected', $productId, CORRECTED);

// 5. THE GUARD. A partial open and no correction at all. Opening moves units between
//    entries and takes none out of the product, so the average must not move - this is what
//    fails if the link is ever read as a correction in itself.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
Case_('split, not corrected', $productId, UNCORRECTED);

// 6. THE SECOND GUARD. Two corrections in the same group, one on each half of the split.
//    Both have to count: 500 less 50 less 25 is 425, so 425 at 1.00 and 100 at 2.00.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
CorrectTo($stock, $pdo, StockIdOfNewestEntry($productId), 400, 350);
CorrectTo($stock, $pdo, $bigEntryStockId, 100, 75);
Case_('both halves corrected', $productId, (425 * 1.00 + 100 * 2.00) / 525);

// --- undone bookings -----------------------------------------------------------------
//
// The three cases below are what the accumulating arithmetic gets right and the
// reconstruction it replaces does not, and they are the region where getting the `undone`
// filter wrong is most expensive: a group whose origin purchase is excluded and whose
// replacement edit is then dropped for being undone loses its units from the average
// altogether. Case 8 answered 2.00 rather than 1.166667 during review, and is here so that
// it cannot do so again quietly.

// 7. A consume marked undone AFTER an edit on the same entry. The reconstruction reads
//    `undone` at query time, so it drops a consume that was real when the edit was made and
//    answers 400 for an origin that is 500 less the 50 the edit removed; accumulating gives
//    450, which is CORRECTED.
//
//    THE UNDO IS DONE IN SQL, and that is the finding rather than a shortcut.
//    UndoBooking() refuses this (StockService.php:2073, "a booking can only be undone when
//    it is the newest not yet undone one of its stock entry"), and undoing the edit first to
//    get past the guard would leave the edit undone too, which takes the group out of the
//    view entirely. So no sequence of service calls reaches this shape: it is what an
//    imported database can hold, not what this application can produce. The case is kept
//    because the migration argues from it and an argument nothing exercises is an argument
//    that rots - but it is labelled for what it is.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->ConsumeProduct($productId, 50, false, StockService::TRANSACTION_TYPE_CONSUME, $bigEntryStockId, null, null, $transactionId);
CorrectTo($stock, $pdo, $bigEntryStockId, 450, 400);
$pdo->prepare('UPDATE stock_log SET undone = 1, undone_timestamp = ? WHERE transaction_id = ? AND transaction_type = ?')
	->execute([date('Y-m-d H:i:s'), $transactionId, StockService::TRANSACTION_TYPE_CONSUME]);
Case_('consume undone after an edit', $productId, CORRECTED);

// 8. An undone edit on a split remainder. Undoing a correction has to put the units back,
//    so the answer is the one the product had before the correction.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$transactionId = null;
$stock->OpenProduct($productId, 100, $bigEntryStockId, $transactionId);
$editTransactionId = CorrectTo($stock, $pdo, StockIdOfNewestEntry($productId), 400, 350);
$stock->UndoTransaction($editTransactionId);
Case_('split remainder, correction undone', $productId, UNCORRECTED);

// 9. The same without a split. This one is not a regression guard - it is a shape the view
//    being replaced gets wrong today, dropping the entry from the average entirely instead
//    of returning it to its purchased weight, and the filter that fixes case 8 fixes it too.
$productId = MakeProduct($pdo, $nextProductId++);
$bigEntryStockId = TwoPurchases($stock, $productId);
$editTransactionId = CorrectTo($stock, $pdo, $bigEntryStockId, 500, 450);
$stock->UndoTransaction($editTransactionId);
Case_('unsplit entry, correction undone', $productId, UNCORRECTED);

echo "\n";

if ($failures === 0)
{
	echo "EVERY CORRECTION REACHED THE AVERAGE\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
