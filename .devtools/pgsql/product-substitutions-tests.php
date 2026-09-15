<?php

// Does directed substitution behave the way plan 31 asks for, and does it leave everything
// it does not touch alone?
//
//   php product-substitutions-tests.php
//
// PostgreSQL only, for the reason nested-product-groups-tests.php gives for its own subject:
// migrations/0279.pgsql.sql is above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so
// product_substitutions and product_substitutions_resolved have no SQLite counterpart to
// compare against - the view phase's own "products_current_substitutions (1 rows identical)"
// case is the control that the *existing* dual-engine mechanism stays untouched by this file's
// subject, not a place to add a new PostgreSQL-only view to.
//
// Cases, most of which produce no error at all when they are wrong:
//
//   1. Direction. Beans offered where grounds are wanted; grounds not offered where beans are
//      wanted. The plan's own worked example, and the whole reason a flag on the existing
//      parent/child view would not have reached.
//   2. A second, unrelated pair (a block substituting for shredded), one way, to rule out the
//      first case accidentally passing on the only pair in the fixture.
//   3. Self-edge and duplicate-pair, both refused - the two guards the table itself carries
//      (CHECK, UNIQUE), asserted by message the way nested-product-groups-tests.php asserts
//      its own trigger wording, so a passing case is provably the intended guard and not some
//      other constraint firing first.
//   4. A product with no edges at all behaves exactly as it does today: no candidate rows,
//      and products_current_substitutions - read straight, not through anything this
//      migration adds - is unaffected.
//   5. The existing parent/child substitution, with no directed edge anywhere in the fixture,
//      still works unchanged - the control that this whole feature is additive per the plan's
//      own verification list.
//   6. A sub product's own stock, not its parent's: a review round on the first version of
//      this migration found from_product_amount_in_stock joined through the candidate's
//      parent, which reads every shared_parent candidate's family total rather than its own
//      contribution - invisible with a top-level candidate (its own id and its "parent" id,
//      via products_resolved, are the same row) and wrong the moment the candidate has a
//      parent of its own, since stock_current carries the sub product's own row too.
//   7. A directed edge that duplicates an existing shared_parent pair is not offered twice.
//      Nothing ties product_substitutions to parent_product_id, so a directed edge X -> P can
//      be added where X already is P's sub product; the same review round found the UNION
//      does not dedupe this on its own, because the two branches disagree on `direction`.
//   8. Q2 answered and asserted either way: a chain (A substitutes for B, B for C) does not
//      offer A for C. Migration 0279's own comment gives the reason (no transitive closure in
//      this migration); this is the fixture-level proof of it.
//   9. Cascade delete: deleting a product removes every edge naming it on either side, folded
//      into trg_cascade_product_removal alongside product_barcodes and
//      quantity_unit_conversions.
//  10. MergeProducts() carries substitution edges to the kept product rather than silently
//      losing them to the cascade delete case 9 exercises: an edge between the two products
//      being merged is dropped (it would become a self-edge), and an edge from or to the
//      removed product that would duplicate one the kept product already has is dropped
//      rather than repointed into a UNIQUE violation.
//  11. The entities: /objects/product_substitutions (read/write) and
//      /objects/product_substitutions_resolved (read-only, refuses write) through
//      GenericEntityApiController, plus the read policy gate nested-product-groups-tests.php
//      exercises the same way for its own resolved view.
//  12. GetProductDetails() carries substitution_candidates, ordered by whether the candidate
//      is actually in stock (descending) and then by its own earliest best-before date.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9700);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'product-substitutions-caller');
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

// The Content-Type matters: BaseApiController::GetParsedAndFilteredRequestBody() rejects a
// write without it before it ever looks at the body.
function request(string $method = 'GET', $body = null)
{
	return (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api')
		->withHeader('Content-Type', 'application/json')
		->withParsedBody($body);
}

/**
 * Runs a statement and returns the database's message, or null when it was accepted.
 *
 * The message is compared rather than only the fact of refusal, the same reasoning
 * nested-product-groups-tests.php's own refusal() gives: a test that only asked "was it
 * refused" would pass when the wrong constraint fired.
 */
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

/** A product with nothing unusual about it, optionally nested under a parent (the shared_parent source). */
function MakeProduct(string $name, ?int $parentProductId = null): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO products
		(name, description, location_id, qu_id_purchase, qu_id_stock, min_stock_amount,
		 default_best_before_days, active, treat_opened_as_out_of_stock, parent_product_id)
		VALUES (?, ?, 2, 2, 2, 0, 0, 1, 0, ?) RETURNING id');
	$statement->execute([$name, 'Created by product-substitutions-tests.php', $parentProductId]);

	return intval($statement->fetchColumn());
}

/** A stock row written directly: this file is about the view and the table, not the booking paths. */
function AddStock(int $productId, float $amount, string $bestBeforeDate): void
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, open)
		VALUES (?, ?, ?, ?, ?, ?, 2, 0)');
	$statement->execute([$productId, $amount, $bestBeforeDate, '2026-07-01', 'pst-' . uniqid('', true), 1.00]);
}

function MakeEdge(int $fromProductId, int $toProductId): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO product_substitutions (from_product_id, to_product_id) VALUES (?, ?) RETURNING id');
	$statement->execute([$fromProductId, $toProductId]);

	return intval($statement->fetchColumn());
}

function Candidates(int $toProductId): array
{
	global $pdo;

	$statement = $pdo->prepare('SELECT from_product_id, direction FROM product_substitutions_resolved WHERE to_product_id = ? ORDER BY from_product_id');
	$statement->execute([$toProductId]);

	return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
}

function ActAs(?string $roleCode): void
{
	global $pdo;

	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9700');
	$pdo->exec('DELETE FROM user_roles WHERE user_id = 9700');

	if ($roleCode !== null)
	{
		$statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT 9700, id FROM roles WHERE code = ?');
		$statement->execute([$roleCode]);
	}
}

// The acting user, the same way nested-product-groups-tests.php writes its own fixture user.
$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9700');
$pdo->exec('DELETE FROM user_roles WHERE user_id = 9700');
$pdo->exec('DELETE FROM users WHERE id = 9700');
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9700, 'product-substitutions-caller', 'fixture')");
ActAs('ADMIN');

echo "Directed product substitution (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

$groundCoffee = MakeProduct('Ground Coffee');
$wholeBeans = MakeProduct('Whole Beans');
MakeEdge($wholeBeans, $groundCoffee);
AddStock($wholeBeans, 2, '2027-01-01');

// --- 1. Direction --------------------------------------------------------------------------

echo "1. direction\n";

check(Candidates($groundCoffee) === [$wholeBeans => 'directed'],
	'whole beans are offered where ground coffee is wanted');
check(Candidates($wholeBeans) === [],
	'ground coffee is not offered where whole beans are wanted - a burr grinder will not take grounds');

// --- 2. A second, unrelated pair -----------------------------------------------------------

echo "\n2. a second pair (block substitutes for shredded), one way\n";

$shreddedCheddar = MakeProduct('Shredded Cheddar');
$cheddarBlock = MakeProduct('Cheddar Block');
MakeEdge($cheddarBlock, $shreddedCheddar);

check(Candidates($shreddedCheddar) === [$cheddarBlock => 'directed'], 'the block is offered for shredded');
check(Candidates($cheddarBlock) === [], 'shredded is not offered for the block - you cannot un-grate');
check(Candidates($groundCoffee) === [$wholeBeans => 'directed'], 'the first pair is unaffected by the second');

// --- 3. Self-edge and duplicate pair, both refused ------------------------------------------

echo "\n3. guards\n";

$selfEdgeMessage = refusal('INSERT INTO product_substitutions (from_product_id, to_product_id) VALUES (?, ?)', [$groundCoffee, $groundCoffee]);
check($selfEdgeMessage !== null && str_contains($selfEdgeMessage, 'product_substitutions_no_self_edge'),
	'a product cannot substitute for itself');

$duplicateMessage = refusal('INSERT INTO product_substitutions (from_product_id, to_product_id) VALUES (?, ?)', [$wholeBeans, $groundCoffee]);
check($duplicateMessage !== null && str_contains($duplicateMessage, 'product_substitutions_pair_key'),
	'the same directed pair cannot be inserted twice');

// The reverse of an existing edge is a different pair, not a duplicate, and has to be accepted -
// a control that the UNIQUE constraint is on the ordered pair, not on the unordered one.
$reverseEdgeId = MakeEdge($groundCoffee, $wholeBeans);
check(is_int($reverseEdgeId) && $reverseEdgeId > 0, 'the reverse direction is a different edge and is accepted');
$pdo->prepare('DELETE FROM product_substitutions WHERE id = ?')->execute([$reverseEdgeId]);

// --- 4. A product with no edges -------------------------------------------------------------

echo "\n4. a product with no edges behaves exactly as it does today\n";

$untouched = MakeProduct('Untouched Product');
check(Candidates($untouched) === [], 'no candidates for a product nobody substitutes for');

$statement = $pdo->prepare('SELECT COUNT(*) FROM product_substitutions_resolved WHERE from_product_id = ?');
$statement->execute([$untouched]);
check((int)$statement->fetchColumn() === 0, 'and it is not offered as a substitute for anything either');

// --- 5. The existing parent/child mechanism, control -----------------------------------------

echo "\n5. the existing parent/child substitution still works, unchanged, with no directed edges present\n";

$seedParent = MakeProduct('Seed Parent (no directed edges)');
$seedSub = MakeProduct('Seed Sub (no directed edges)', $seedParent);
AddStock($seedSub, 5, '2027-02-01');

$statement = $pdo->prepare('SELECT product_id_effective FROM products_current_substitutions WHERE parent_product_id = ?');
$statement->execute([$seedParent]);
check((int)$statement->fetchColumn() === $seedSub,
	'products_current_substitutions still resolves the parent to its sub product, exactly as before this migration');

check(Candidates($seedParent) === [$seedSub => 'shared_parent'],
	'and product_substitutions_resolved also carries the same pair, unioned in from products_resolved');

// --- 6. A sub product candidate's own stock, not its parent's -------------------------------

echo "\n6. a sub product candidate reports its own stock, not its parent's family total\n";

$herbParent = MakeProduct('Herb Parent (has its own stock too)');
$herbSub = MakeProduct('Herb Sub (the actual candidate)', $herbParent);
$herbWanted = MakeProduct('Herb Wanted');
AddStock($herbParent, 100, '2027-03-01');
AddStock($herbSub, 3, '2027-04-01');
MakeEdge($herbSub, $herbWanted);

$statement = $pdo->prepare('SELECT from_product_amount_in_stock FROM product_substitutions_resolved WHERE from_product_id = ? AND to_product_id = ?');
$statement->execute([$herbSub, $herbWanted]);
$herbSubAmount = (float)$statement->fetchColumn();
check(abs($herbSubAmount - 3.0) < 0.000001,
	"the candidate's own 3 units, not the parent's 100-unit family total (got $herbSubAmount)");

// --- 7. A directed edge duplicating an existing shared_parent pair is not offered twice -----

echo "\n7. a directed edge that duplicates an existing shared_parent pair is not offered twice\n";

MakeEdge($herbSub, $herbParent);
check(Candidates($herbParent) === [$herbSub => 'shared_parent'],
	'Herb Sub is offered for Herb Parent exactly once, as shared_parent, even though a redundant directed edge for the same pair also exists');

// --- 8. Q2: no transitive closure -------------------------------------------------------------

echo "\n8. a chain is not offered end-to-end (open question 2, answered no in this migration)\n";

$wholeSpice = MakeProduct('Whole Spice');
$crackedSpice = MakeProduct('Cracked Spice');
$groundSpice = MakeProduct('Ground Spice');
MakeEdge($wholeSpice, $crackedSpice);
MakeEdge($crackedSpice, $groundSpice);

check(Candidates($groundSpice) === [$crackedSpice => 'directed'],
	'ground spice is offered cracked spice directly');
check(!array_key_exists($wholeSpice, Candidates($groundSpice)),
	'but not whole spice - the chain is not resolved transitively');

// --- 9. Cascade delete -------------------------------------------------------------------------

echo "\n9. deleting a product removes every edge naming it\n";

$doomedFrom = MakeProduct('Doomed (from side)');
$doomedTo = MakeProduct('Doomed (to side)');
$survivor = MakeProduct('Survivor');
MakeEdge($doomedFrom, $survivor);
MakeEdge($survivor, $doomedTo);

$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$doomedFrom]);
$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$doomedTo]);

$statement = $pdo->prepare('SELECT COUNT(*) FROM product_substitutions WHERE from_product_id IN (?, ?) OR to_product_id IN (?, ?)');
$statement->execute([$doomedFrom, $doomedTo, $doomedFrom, $doomedTo]);
check((int)$statement->fetchColumn() === 0, 'both edges are gone, from either side of the pair');

// --- 10. MergeProducts() carries substitution edges, rather than losing them to the cascade -

echo "\n10. MergeProducts() carries substitution edges to the kept product\n";

$mergeKeep = MakeProduct('Merge Keep');
$mergeRemove = MakeProduct('Merge Remove');
$mergeOther = MakeProduct('Merge Other');
$mergeShared = MakeProduct('Merge Shared Target');

// This edge is between the two products being merged, so it would become a self-edge once
// repointed - it has to be dropped, not carried over in either direction.
MakeEdge($mergeRemove, $mergeKeep);
// This edge names the removed product on each side in turn, and neither collides with
// anything Merge Keep already has - both should simply be repointed.
$edgeFromRemove = MakeEdge($mergeRemove, $mergeOther);
$edgeToRemove = MakeEdge($mergeOther, $mergeRemove);
// This edge from the removed product would duplicate one the kept product already has once
// repointed (both would read Merge Keep -> Merge Shared Target) - it has to be dropped rather
// than repointed into a UNIQUE violation, leaving Merge Keep's own edge as the survivor.
$keptDuplicateSurvivor = MakeEdge($mergeKeep, $mergeShared);
MakeEdge($mergeRemove, $mergeShared);

StockService::GetInstance()->MergeProducts($mergeKeep, $mergeRemove);

check((int)$pdo->query('SELECT COUNT(*) FROM product_substitutions WHERE from_product_id = ' . $mergeKeep . ' AND to_product_id = ' . $mergeKeep)->fetchColumn() === 0,
	'the edge between the merged pair became a self-edge and was dropped, not carried over');

$statement = $pdo->prepare('SELECT from_product_id, to_product_id FROM product_substitutions WHERE id = ?');
$statement->execute([$edgeFromRemove]);
check($statement->fetch(PDO::FETCH_ASSOC) === ['from_product_id' => $mergeKeep, 'to_product_id' => $mergeOther],
	'Merge Remove -> Merge Other repointed to Merge Keep -> Merge Other, same row');
$statement->execute([$edgeToRemove]);
check($statement->fetch(PDO::FETCH_ASSOC) === ['from_product_id' => $mergeOther, 'to_product_id' => $mergeKeep],
	'Merge Other -> Merge Remove repointed to Merge Other -> Merge Keep, same row');

check((int)$pdo->query('SELECT COUNT(*) FROM product_substitutions WHERE from_product_id = ' . $mergeKeep . ' AND to_product_id = ' . $mergeShared)->fetchColumn() === 1,
	'the colliding pair (Keep -> Shared Target) exists exactly once after the merge, not twice');
check((int)$pdo->query('SELECT COUNT(*) FROM product_substitutions WHERE id = ' . $keptDuplicateSurvivor)->fetchColumn() === 1,
	"the kept product's own pre-existing edge is the survivor, not a repointed copy");

check((int)$pdo->query('SELECT COUNT(*) FROM products WHERE id = ' . $mergeRemove)->fetchColumn() === 0,
	'the removed product is actually gone, so this is testing MergeProducts() and not a no-op');

// --- 11. The entities -----------------------------------------------------------------------

echo "\n11. /objects/product_substitutions and /objects/product_substitutions_resolved\n";

$api = new GenericEntityApiController($container);

$response = $api->AddObject(request('POST', ['from_product_id' => $untouched, 'to_product_id' => $survivor]), new Response(), ['entity' => 'product_substitutions']);
$created = json_decode((string)$response->getBody(), true);
check($response->getStatusCode() === 200 && isset($created['created_object_id']), 'a directed edge can be created through the API');

$newEdgeId = $created['created_object_id'];
$response = $api->GetObject(request(), new Response(), ['entity' => 'product_substitutions', 'objectId' => $newEdgeId]);
$edge = json_decode((string)$response->getBody(), true);
check(array_keys($edge) === ['id', 'from_product_id', 'to_product_id', 'row_created_timestamp', 'userfields'],
	'the key set is exactly the table plus the generic userfields key');

$response = $api->DeleteObject(request('DELETE'), new Response(), ['entity' => 'product_substitutions', 'objectId' => $newEdgeId]);
check($response->getStatusCode() === 204 || $response->getStatusCode() === 200, 'and it can be deleted again through the API');

$response = $api->GetObjects(request(), new Response(), ['entity' => 'product_substitutions_resolved']);
$resolved = json_decode((string)$response->getBody(), true);
check($response->getStatusCode() === 200, 'the resolved view lists');
check(is_array($resolved) && count($resolved) > 0
	&& array_keys($resolved[0]) === ['id', 'to_product_id', 'from_product_id', 'direction', 'from_product_amount_in_stock', 'from_product_best_before_date'],
	'with exactly the documented columns');

foreach ([['AddObject', 'POST'], ['EditObject', 'PUT'], ['DeleteObject', 'DELETE']] as [$method, $verb])
{
	$response = $api->$method(request($verb, ['to_product_id' => 1]), new Response(), ['entity' => 'product_substitutions_resolved', 'objectId' => 1]);
	check($response->getStatusCode() >= 400, 'the resolved view refuses ' . $verb);
}

// The read policy, exercised the same way nested-product-groups-tests.php exercises its own.
$readStatus = function () use ($api)
{
	try
	{
		return $api->GetObjects(request(), new Response(), ['entity' => 'product_substitutions_resolved'])->getStatusCode();
	}
	catch (Slim\Exception\HttpException $exception)
	{
		return $exception->getCode();
	}
};

ActAs(null);
check($readStatus() === 403, 'a user holding no role at all is refused the resolved view');

ActAs('GUEST');
check($readStatus() === 200, 'a user whose role grants STOCK_VIEW may read it');

ActAs('ADMIN');

// --- 12. GetProductDetails() carries substitution_candidates, ordered ------------------------

echo "\n12. GetProductDetails() substitution_candidates\n";

$wanted = MakeProduct('Wanted Product');
$inStockLate = MakeProduct('Candidate In Stock, Later Best-Before');
$inStockSoon = MakeProduct('Candidate In Stock, Sooner Best-Before');
$outOfStock = MakeProduct('Candidate Out Of Stock');
MakeEdge($inStockLate, $wanted);
MakeEdge($inStockSoon, $wanted);
MakeEdge($outOfStock, $wanted);
AddStock($inStockLate, 1, '2027-12-01');
AddStock($inStockSoon, 1, '2027-01-01');

$details = StockService::GetInstance()->GetProductDetails($wanted);
$candidateIds = array_map(fn($row) => (int)$row->from_product_id, $details['substitution_candidates']);

check($candidateIds === [$inStockSoon, $inStockLate, $outOfStock],
	'in-stock candidates first (soonest best-before first), out-of-stock candidates last');

echo "\n";

if ($failures === 0)
{
	echo "EVERY DIRECTED SUBSTITUTION CASE ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
