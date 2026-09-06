<?php

// Does a product group below its own minimum report the right shortfall, and does the
// overview page carry the products that would fix it?
//
//   php group-min-stock-tests.php
//
// PostgreSQL only, and for the reason the rbac and average-price phases are: the subject is
// migrations/0268.pgsql.sql, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so a
// SQLite side would be asked about a column and a view it does not have. The view phase
// cannot stand in for this even in principle - it seeds SQLite and copies the result into
// PostgreSQL through the importer's common-column logic, so product_groups.min_stock_amount
// arrives at its DEFAULT 0 for every row and every group is trivially not short. A difftest
// seed here would pass while asserting nothing at all.
//
// WHAT IT GUARDS. Four things that produce no error of any kind when they are wrong:
//
//   1. Double counting. If the member sum is built from stock_current rather than from the
//      stock ledger, a group holding both a parent product and one of its children counts the
//      child's stock twice - once as its own row, once inside the parent's amount_aggregated -
//      and the group silently looks stocked. Case 8.
//   2. The disappearing group. If the member "active" filter is written as an outer WHERE
//      rather than inside the join, a group whose only members are inactive matches no rows
//      and drops out of the view, which every reader takes to mean "fully stocked". It means
//      the exact opposite. Cases 5 and 6.
//   3. The empty page. The stock overview lists is_in_stock_or_below_min_stock = 1, which
//      excludes a product at zero stock with no minimum of its own - precisely the member a
//      short group needs bought. Naming the group on a page whose rows for it were never
//      rendered is an instruction the page cannot carry out. Case 13.
//   4. Silent scope creep. A group shortfall must not reach the shopping list (plan 03
//      question 1 chose that deliberately for v1) and must not appear in
//      stock_missing_products, whose every row is a product id something will try to add.
//      Cases 14 and 15.
//
// Every case that could pass for the wrong reason is paired with a control. The fractional
// minimum is here because products.min_stock_amount is declared INTEGER upstream and holds
// fractions anyway (db/pgsql/README.md hazard 2); a column typed INTEGER would round 2.5 and
// still answer "short", so the assertion is on the amount rather than on the fact.

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
use Victual\Controllers\StockController;
use Victual\Controllers\Api\GenericEntityApiController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$container = new DI\Container();
$container->set('view', new Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
$container->set('UrlManager', new Victual\Helpers\UrlManager(''));

$checks = 0;
$failures = 0;
$nextGroupId = 9400;
$nextProductId = 9400;

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

// The Content-Type matters: BaseApiController::GetParsedAndFilteredRequestBody() rejects a
// write without it before it ever looks at the body, so a request built without the header
// tests the header rather than the entity.
function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')
		->withHeader('Content-Type', 'application/json')
		->withParsedBody($body);
}

/** @return int The new group's id. */
function MakeGroup(float $minimum, int $active = 1): int
{
	global $pdo, $nextGroupId;

	$id = $nextGroupId++;
	$statement = $pdo->prepare('INSERT INTO product_groups (id, name, description, min_stock_amount, active) VALUES (?, ?, ?, ?, ?)');
	$statement->execute([$id, 'Group Min Stock Test ' . $id, 'Created by group-min-stock-tests.php', $minimum, $active]);

	return $id;
}

/**
 * A product with nothing unusual about it, stocked in Pieces at the default location.
 *
 * treat_opened_as_out_of_stock defaults to 1 in the schema, so it is passed explicitly
 * everywhere here rather than left to a default a reader would have to look up.
 */
function MakeProduct(?int $groupId, int $active = 1, int $treatOpenedAsOutOfStock = 0, ?int $parentProductId = null): int
{
	global $pdo, $nextProductId;

	$id = $nextProductId++;
	$statement = $pdo->prepare('INSERT INTO products
		(id, name, description, location_id, qu_id_purchase, qu_id_stock, min_stock_amount,
		 default_best_before_days, product_group_id, active, treat_opened_as_out_of_stock, parent_product_id)
		VALUES (?, ?, ?, 2, 2, 2, 0, 0, ?, ?, ?, ?)');
	$statement->execute([$id, 'Group Min Stock Product ' . $id, 'Created by group-min-stock-tests.php',
		$groupId, $active, $treatOpenedAsOutOfStock, $parentProductId]);

	return $id;
}

/** A stock row written directly: this file is about a view, not about the booking paths. */
function AddStock(int $productId, float $amount, int $open = 0): void
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, open)
		VALUES (?, ?, ?, ?, ?, ?, 2, ?)');
	$statement->execute([$productId, $amount, '2027-06-01', '2026-07-01', 'gms-' . uniqid('', true), 1.00, $open]);
}

/** The view's row for one group, or null when it reports nothing. */
function Shortfall(int $groupId): ?array
{
	global $pdo;

	$statement = $pdo->prepare('SELECT * FROM product_groups_missing WHERE id = ?');
	$statement->execute([$groupId]);
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return $row === false ? null : $row;
}

function CheckShort(string $label, int $groupId, float $expected): void
{
	$row = Shortfall($groupId);

	if ($row === null)
	{
		check(false, $label . ': expected short by ' . $expected . ', but the group is not in the view at all');

		return;
	}

	check(abs((float)$row['amount_missing'] - $expected) < 0.000001,
		$label . ': short by ' . $row['amount_missing'] . ', expected ' . $expected);
}

function CheckNotShort(string $label, int $groupId): void
{
	$row = Shortfall($groupId);

	check($row === null, $label . ($row === null ? '' : ': present with amount_missing ' . $row['amount_missing']));
}

echo "Product group minimum stock (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

// --- The view ---------------------------------------------------------------------------

// 1. THE CONTROL. Two active members holding 3 and 1 against a minimum of 10.
$groupId = MakeGroup(10);
AddStock(MakeProduct($groupId), 3);
AddStock(MakeProduct($groupId), 1);
CheckShort('short across two members', $groupId, 6);

// 2. The same group stocked past its minimum. Says which way case 1 is being measured.
$groupId = MakeGroup(10);
AddStock(MakeProduct($groupId), 12);
CheckNotShort('over the minimum', $groupId);

// 3. Exactly at the minimum. The boundary of the view's trailing amount_missing > 0, and the
//    off-by-one that would report every fully stocked group as short by zero.
$groupId = MakeGroup(10);
AddStock(MakeProduct($groupId), 10);
CheckNotShort('exactly at the minimum', $groupId);

// 4. A fractional minimum, unreachable by a column typed INTEGER. 2.5 against 1 is 1.5, and
//    an INTEGER column would answer 2 or 1 - both still "short", which is why the assertion
//    is on the amount.
$groupId = MakeGroup(2.5);
AddStock(MakeProduct($groupId), 1);
CheckShort('fractional minimum', $groupId, 1.5);

// 5. A group with no members at all. Short by everything: nothing is stocked.
$groupId = MakeGroup(4);
CheckShort('empty group', $groupId, 4);

// 6. THE DISAPPEARING GROUP. Every member inactive, and one of them holding plenty. If the
//    member filter is an outer WHERE this group matches nothing and vanishes, which reads as
//    fully stocked; it is the opposite.
$groupId = MakeGroup(4);
AddStock(MakeProduct($groupId, 0), 99);
CheckShort('only inactive members', $groupId, 4);

// 7. An inactive group. Filtered as a group, which is a different filter from case 6's and
//    must not be written as the same one.
$groupId = MakeGroup(4, 0);
AddStock(MakeProduct($groupId), 1);
CheckNotShort('inactive group', $groupId);

// 8. THE DOUBLE COUNT. A parent and its own child, both in the group, with the stock on the
//    child. stock_current rolls the child up into the parent, so a sum built from it counts
//    3 twice and answers "not short"; the ledger counts it once and answers 7.
$groupId = MakeGroup(10);
$parentId = MakeProduct($groupId);
$childId = MakeProduct($groupId, 1, 0, $parentId);
AddStock($childId, 3);
CheckShort('parent and child both in the group', $groupId, 7);

// 9. A child OUTSIDE the parent's group, with the stock on the child. Plan 03 question 3:
//    only a direct member's own stock counts, so the parent contributes nothing on the
//    child's behalf and the group is short by its whole minimum.
$groupId = MakeGroup(10);
$parentId = MakeProduct($groupId);
$childId = MakeProduct(null, 1, 0, $parentId);
AddStock($childId, 8);
CheckShort('child outside the parent group', $groupId, 10);

// 10. Opened stock on a member that treats opened as out of stock. 6 held, 4 of it opened,
//     so 2 counts.
$groupId = MakeGroup(10);
$productId = MakeProduct($groupId, 1, 1);
AddStock($productId, 2);
AddStock($productId, 4, 1);
CheckShort('opened stock discounted', $groupId, 8);

// 11. THE CONTROL FOR 10. The same stock on a member that does not treat opened as out of
//     stock, so all 6 counts. Without this pair, case 10 would be satisfied by a view that
//     ignored the opened rows for everybody.
$groupId = MakeGroup(10);
$productId = MakeProduct($groupId, 1, 0);
AddStock($productId, 2);
AddStock($productId, 4, 1);
CheckShort('opened stock kept', $groupId, 4);

// 12. A group with no minimum set is not a group with a minimum of zero to be short of.
$groupId = MakeGroup(0);
CheckNotShort('no minimum set', $groupId);

// --- The overview page ------------------------------------------------------------------

// 13. THE EMPTY PAGE. A zero-stock member with no minimum of its own is
//     is_in_stock_or_below_min_stock = 0, so the overview's default filter drops it - and the
//     short-group list would then name a group whose products are not on the page. Asserted
//     against the rendered page rather than against a repeat of the controller's where
//     clause, because a test that restates the clause cannot notice it changing.
$groupId = MakeGroup(5);
$strandedId = MakeProduct($groupId);
$stockedElsewhereId = MakeProduct(null);
AddStock($stockedElsewhereId, 1);

$body = (string)(new StockController($container))->Overview(request(), new Response(), [])->getBody();

check(str_contains($body, 'id="product-' . $strandedId . '-row"'),
	'the overview carries a short group\'s out-of-stock member');
check(str_contains($body, 'id="product-' . $stockedElsewhereId . '-row"'),
	'the overview still carries a product that has stock');

// The control for the case above: a zero-stock product whose group is NOT short stays off the
// page. Without it, "include everything" would pass case 13 just as well as the fix does.
$fullGroupId = MakeGroup(1);
AddStock(MakeProduct($fullGroupId), 5);
$notStrandedId = MakeProduct($fullGroupId);

$body = (string)(new StockController($container))->Overview(request(), new Response(), [])->getBody();

check(!str_contains($body, 'id="product-' . $notStrandedId . '-row"'),
	'a zero-stock member of a satisfied group stays off the overview');

// --- Independence and side effects --------------------------------------------------------

// 14. A group shortfall is not a product shortfall. stock_missing_products is product-keyed
//     and AddMissingProductsToShoppingList() adds a row per id in it, so a group leaking into
//     it would put something on the shopping list that nobody chose.
$groupId = MakeGroup(10);
$memberId = MakeProduct($groupId);

check((int)$pdo->query('SELECT COUNT(*) FROM stock_missing_products WHERE id = ' . $memberId)->fetchColumn() === 0,
	'a short group puts no product into stock_missing_products');

// 15. And running the shopping list top-up leaves the list alone. Plan 03 question 1 chose
//     "do not auto-add" for v1; this is that decision as an assertion rather than an
//     assumption, so that adding it later is a deliberate act that fails here first.
$before = (int)$pdo->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();
StockService::GetInstance()->AddMissingProductsToShoppingList(1);
$after = (int)$pdo->query('SELECT COUNT(*) FROM shopping_list')->fetchColumn();

check($before === $after, 'a short group adds nothing to the shopping list');

// 16. A product below its OWN minimum is still short, whatever its group is doing. The two
//     minimums are independent (plan 03 question 2), and the group work must not have made
//     one conditional on the other.
$groupId = MakeGroup(1);
$stockedMemberId = MakeProduct($groupId);
AddStock($stockedMemberId, 50);
$pdo->exec('UPDATE products SET min_stock_amount = 5 WHERE id = ' . $stockedMemberId);
$pdo->exec('UPDATE stock SET amount = 1 WHERE product_id = ' . $stockedMemberId);

check((int)$pdo->query('SELECT COUNT(*) FROM stock_missing_products WHERE id = ' . $stockedMemberId)->fetchColumn() === 1,
	'a product below its own minimum is short inside a satisfied group');

// --- The entity -----------------------------------------------------------------------------

// 17. The view is exposed read-only. It is in ExposedEntity so /objects can serve it, and in
//     NoEdit and NoDelete so the write verbs refuse - a derived view that accepted a write
//     would be a 500 from the driver rather than a 400 from the application.
$api = new GenericEntityApiController($container);

$response = $api->GetObjects(request(), new Response(), ['entity' => 'product_groups_missing']);
check($response->getStatusCode() === 200, 'the entity lists');

$rows = json_decode((string)$response->getBody(), true);
check(is_array($rows) && count($rows) > 0, 'the entity lists the short groups');
check(is_array($rows) && array_keys($rows[0]) === ['id', 'name', 'min_stock_amount', 'amount_missing'],
	'the entity has exactly the four documented columns');

foreach ([['AddObject', 'POST'], ['EditObject', 'PUT'], ['DeleteObject', 'DELETE']] as [$method, $verb])
{
	$response = $api->$method(request($verb, ['min_stock_amount' => 1]), new Response(), ['entity' => 'product_groups_missing', 'objectId' => $groupId]);
	check($response->getStatusCode() >= 400, 'the entity refuses ' . $verb);
}

// 18. The additive column round-trips through the generic write path, which is the API half
//     of what the browser check asserts through the form.
$roundTripId = MakeGroup(0);
$api->EditObject(request('PUT', ['min_stock_amount' => 3.5]), new Response(), ['entity' => 'product_groups', 'objectId' => $roundTripId]);
$response = $api->GetObject(request(), new Response(), ['entity' => 'product_groups', 'objectId' => $roundTripId]);
$group = json_decode((string)$response->getBody(), true);

check(abs((float)$group['min_stock_amount'] - 3.5) < 0.000001,
	'min_stock_amount round-trips through /objects/product_groups');

echo "\n";

if ($failures === 0)
{
	echo "EVERY GROUP MINIMUM ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
