<?php

// Do nested locations hold their shape, and does nesting change nothing that was not meant
// to change?
//
//   php nested-locations-tests.php
//
// PostgreSQL only, and for the reason the rbac, average-price and group-minimum phases are:
// the subject is migrations/0273.pgsql.sql, above
// DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so a SQLite side would be asked about
// a column, a view and three triggers it does not have. The view phase cannot stand in for it
// either: that phase seeds SQLite and copies the tables into PostgreSQL through the importer's
// common-column logic, so `parent_location_id` would arrive NULL for every row and every
// assertion below about a tree would be an assertion about a flat list.
//
// WHAT IT GUARDS. Six things, four of which produce no error at all when they are wrong:
//
//   1. A tree that is not a tree. There is no foreign key on parent_location_id and nothing
//      but the triggers stops a cycle or a chain deeper than the cap. A cycle makes the
//      recursive view non-terminating, which is not a wrong answer but a hung request, so the
//      view carries its own two stops and the triggers are what keep the data from needing
//      them. Cases 3 and 4, each with a control that has to still be accepted - a guard that
//      refuses everything would pass a refusal-only test.
//   2. Uniqueness at the root. UNIQUE(parent_location_id, name) without NULLS NOT DISTINCT
//      holds everywhere except among root locations, where PostgreSQL treats each NULL parent
//      as distinct - so two "Basement" roots would be accepted and the rule would be silently
//      half a rule. Case 2's third assertion is the one that fails without the spelling.
//   3. The delete that reaches the client as a driver message. A trigger's text never leaves
//      the application: BaseApiController::GenericErrorResponse() replaces anything beginning
//      `SQLSTATE[`. So the refusal is asserted twice - at the database, and through
//      GenericEntityApiController::DeleteObject(), where the body has to carry the sentence a
//      person reads and no SQLSTATE text. Case 5.
//   4. The column that never reaches the wire. /objects/locations projects an explicit column
//      list (plan 25 added it to keep import_epoch off the wire), so a column added to the
//      table and not to that list is absent from the response with nothing to notice. Case 7
//      compares the whole key set, not just the new key.
//   5. is_freezer quietly inheriting. Plan 08 question 3 says the flag is literal, and the
//      layout question 5 confirmed puts the stock at "UprightFreezer / Door" - so the Door row
//      must carry the flag itself and nothing may read it from an ancestor. Getting this wrong
//      changes due dates, which is stock correctness. Case 8 pairs the freezer transfer with a
//      control whose Door has the flag cleared while its parent still has it.
//   6. Roll-up leaking into the content sheet. Question 4 wanted the roll-up for filtering
//      only; stock at a leaf must not be reported under its ancestors. Case 9.
//   7. A cycle built by two transactions neither of which could build one alone. The guard
//      reads the tree and then writes to it, and two re-parentings touch different rows, so
//      nothing makes them wait for each other unless something is made to. Case 10, which is
//      the only case here that needs a second connection.
//
// The fixture tree is the one plan 08 question 5 was confirmed against, with is_freezer on
// both UprightFreezer and Door:
//
//   Basement / StorageRoom / Rack1          / Shelf3
//   Basement / StorageRoom / UprightFreezer / Door
//   Main     / Kitchen     / SinkLeftCab    / Shelf1
//
// It is built here rather than in fixtures/00_base.sql, which the SQLite side of the
// differential suite also loads and which has no parent_location_id column to put it in.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9500);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'nested-locations-caller');
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
 * The message is compared rather than only the fact of refusal, because three different
 * rules refuse a write to this table and a test that only asked "was it refused" would pass
 * when the wrong one fired.
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

function MakeLocation(string $name, ?int $parentId = null, int $isFreezer = 0): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO locations (name, parent_location_id, is_freezer, active) VALUES (?, ?, ?, 1) RETURNING id');
	$statement->execute([$name, $parentId, $isFreezer]);

	return intval($statement->fetchColumn());
}

function MakeProduct(string $name, int $locationId, int $daysAfterFreezing = 0): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO products
		(name, description, location_id, qu_id_purchase, qu_id_stock, min_stock_amount,
		 default_best_before_days, default_best_before_days_after_freezing, active, treat_opened_as_out_of_stock)
		VALUES (?, ?, ?, 2, 2, 0, 0, ?, 1, 0) RETURNING id');
	$statement->execute([$name, 'Created by nested-locations-tests.php', $locationId, $daysAfterFreezing]);

	return intval($statement->fetchColumn());
}

echo "Nested locations (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

// The base fixture ships four flat locations; this phase owns the tree and nothing else, so
// it starts from a table it built itself. Products and stock reference locations, so they go
// first, and the locations go leaf by leaf: the delete guard added by 0273 refuses a parent
// while a child is still there, and it refuses it inside a single multi-row DELETE too.
$pdo->exec('DELETE FROM stock_log');
$pdo->exec('DELETE FROM stock');
$pdo->exec('DELETE FROM products');

while ($pdo->exec('DELETE FROM locations WHERE id NOT IN (SELECT parent_location_id FROM locations WHERE parent_location_id IS NOT NULL)') > 0)
{
	// Repeated until the table is empty; each pass removes the current leaves.
}

// The acting user. Written with an explicit id the way rbac-tests.php writes its fixture
// users, rather than through UsersService::CreateUser(), because CreateUser() assigns the
// configured default roles and case 7 needs a user that starts out holding nothing at all.
// It carries ADMIN for everything before that case and is stripped inside it.
$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9500');
$pdo->exec('DELETE FROM user_roles WHERE user_id = 9500');
$pdo->exec("DELETE FROM users WHERE id = 9500");
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9500, 'nested-locations-caller', 'fixture')");

function ActAs(?string $roleCode): void
{
	global $pdo;

	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9500');
	$pdo->exec('DELETE FROM user_roles WHERE user_id = 9500');

	if ($roleCode !== null)
	{
		$statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT 9500, id FROM roles WHERE code = ?');
		$statement->execute([$roleCode]);
	}
}

ActAs('ADMIN');

$basement = MakeLocation('Basement');
$main = MakeLocation('Main');
$storageRoom = MakeLocation('StorageRoom', $basement);
$kitchen = MakeLocation('Kitchen', $main);
$rack1 = MakeLocation('Rack1', $storageRoom);
$uprightFreezer = MakeLocation('UprightFreezer', $storageRoom, 1);
$sinkLeftCab = MakeLocation('SinkLeftCab', $kitchen);
$shelf3 = MakeLocation('Shelf3', $rack1);
$door = MakeLocation('Door', $uprightFreezer, 1);
$shelf1 = MakeLocation('Shelf1', $sinkLeftCab);

// --- 1. The view resolves the tree -------------------------------------------------------

echo "1. locations_resolved\n";

check((int)$pdo->query('SELECT COUNT(*) FROM locations_resolved WHERE depth = 0')->fetchColumn() === 10,
	'ten self rows, one per location');

$statement = $pdo->prepare('SELECT ancestor_location_id, depth FROM locations_resolved WHERE descendant_location_id = ? AND depth > 0 ORDER BY depth');
$statement->execute([$door]);
$ancestorsOfDoor = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

check($ancestorsOfDoor == [$uprightFreezer => 1, $storageRoom => 2, $basement => 3],
	"Door's ancestors are UprightFreezer (1), StorageRoom (2), Basement (3)");

$statement = $pdo->prepare('SELECT path FROM locations_resolved WHERE descendant_location_id = ? AND depth = 0');
$statement->execute([$door]);

check($statement->fetchColumn() === 'Basement / StorageRoom / UprightFreezer / Door',
	"Door's path is the whole chain joined by \" / \"");

$statement = $pdo->prepare('SELECT descendant_location_id, MAX(depth) FROM locations_resolved WHERE descendant_location_id IN (?, ?, ?, ?) GROUP BY descendant_location_id');
$statement->execute([$basement, $storageRoom, $uprightFreezer, $door]);
$levels = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

check($levels[$basement] == 0 && $levels[$storageRoom] == 1 && $levels[$uprightFreezer] == 2 && $levels[$door] == 3,
	'levels derived from MAX(depth) are 0, 1, 2, 3 down the freezer branch');

// --- 2. Uniqueness -----------------------------------------------------------------------

echo "\n2. UNIQUE NULLS NOT DISTINCT (parent_location_id, name)\n";

// The control first: the whole point of respelling the constraint is that this is now legal.
// Shelf1 already exists under SinkLeftCab, and Shelf3 already exists under Rack1.
check(refusal('INSERT INTO locations (name, parent_location_id) VALUES (?, ?)', ['Shelf1', $rack1]) === null,
	'a second Shelf1 in a different room is accepted');

$message = refusal('INSERT INTO locations (name, parent_location_id) VALUES (?, ?)', ['Shelf3', $rack1]);
check($message !== null && str_contains($message, 'locations_parent_name_key'),
	'a second Shelf3 under the same Rack1 is refused');

// Without NULLS NOT DISTINCT this insert succeeds, because PostgreSQL treats each NULL parent
// as distinct from every other. This is the assertion that spelling exists for.
$message = refusal('INSERT INTO locations (name, parent_location_id) VALUES (?, NULL)', ['Basement']);
check($message !== null && str_contains($message, 'locations_parent_name_key'),
	'a second root named Basement is refused');

$pdo->prepare('DELETE FROM locations WHERE name = ? AND parent_location_id = ?')->execute(['Shelf1', $rack1]);

// --- 3. Cycles ---------------------------------------------------------------------------

echo "\n3. cycles\n";

$message = refusal('UPDATE locations SET parent_location_id = id WHERE id = ?', [$rack1]);
check($message !== null && str_contains($message, 'Recursive nested location detected'),
	'a location cannot be its own parent');

$message = refusal('UPDATE locations SET parent_location_id = ? WHERE id = ?', [$door, $basement]);
check($message !== null && str_contains($message, 'Recursive nested location detected'),
	"Basement cannot be moved under its own descendant Door");

// The control: an ordinary move has to still be accepted, or the guard is refusing every
// update rather than the cyclic ones.
check(refusal('UPDATE locations SET parent_location_id = ? WHERE id = ?', [$kitchen, $rack1]) === null,
	'Rack1 can be moved under Kitchen');
$pdo->prepare('UPDATE locations SET parent_location_id = ? WHERE id = ?')->execute([$storageRoom, $rack1]);

// --- 4. Depth --------------------------------------------------------------------------

echo "\n4. hierarchy_depth_limit()\n";

check((int)$pdo->query('SELECT hierarchy_depth_limit()')->fetchColumn() === 6,
	'the depth function returns 6');

$chain = [MakeLocation('Deep1')];
for ($i = 2; $i <= 6; $i++)
{
	$chain[] = MakeLocation('Deep' . $i, $chain[count($chain) - 1]);
}

check(count($chain) === 6, 'a chain of six locations is accepted');

$message = refusal('INSERT INTO locations (name, parent_location_id) VALUES (?, ?)', ['Deep7', $chain[5]]);
check($message !== null && str_contains($message, 'Location nesting depth limit exceeded'),
	'a seventh location in that chain is refused');

// The subtree case: StorageRoom itself only moves one level, but it carries two levels below
// it. A guard that looked at the row alone rather than at its height would accept this.
$message = refusal('UPDATE locations SET parent_location_id = ? WHERE id = ?', [$shelf1, $storageRoom]);
check($message !== null && str_contains($message, 'Location nesting depth limit exceeded'),
	'StorageRoom (height 2) cannot be reparented under Shelf1 (level 3)');

check(refusal('UPDATE locations SET parent_location_id = ? WHERE id = ?', [$kitchen, $storageRoom]) === null,
	'StorageRoom can be reparented under Kitchen (level 1)');
$pdo->prepare('UPDATE locations SET parent_location_id = ? WHERE id = ?')->execute([$basement, $storageRoom]);

foreach (array_reverse($chain) as $deepId)
{
	$pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$deepId]);
}

// --- 5. Deleting a parent ----------------------------------------------------------------

echo "\n5. deleting a location that has children\n";

$message = refusal('DELETE FROM locations WHERE id = ?', [$storageRoom]);
check($message !== null && str_contains($message, 'Location has child locations'),
	'the database refuses to delete StorageRoom');

$disposable = MakeLocation('Disposable', $rack1);
check(refusal('DELETE FROM locations WHERE id = ?', [$disposable]) === null,
	'a childless location is still deletable');

$api = new GenericEntityApiController($container);
$response = $api->DeleteObject(request('DELETE'), new Response(), ['entity' => 'locations', 'objectId' => $storageRoom]);
$body = (string)$response->getBody();

check($response->getStatusCode() === 400, 'the API answers 400 rather than 500 or 204');
check(str_contains($body, 'Location has child locations'), 'the response body carries the sentence a person reads');
check(!str_contains($body, 'SQLSTATE'), 'the response body carries no driver text');
check((int)$pdo->query('SELECT COUNT(*) FROM locations WHERE id = ' . $storageRoom)->fetchColumn() === 1,
	'StorageRoom is still there afterwards');

// --- 6. stock_current_locations is unchanged ---------------------------------------------

echo "\n6. stock_current_locations still answers about exactly one location\n";

$frozenPeas = MakeProduct('Nested Locations Frozen Peas', $shelf3, 90);
StockService::GetInstance()->AddProduct($frozenPeas, 4, date('Y-m-d', strtotime('+300 days')),
	StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.00, $door);

$statement = $pdo->prepare('SELECT location_id, amount FROM stock_current_locations WHERE product_id = ?');
$statement->execute([$frozenPeas]);
$rows = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

check(count($rows) === 1 && array_key_exists($door, $rows), 'the purchase appears at Door and nowhere else');
check(!array_key_exists($basement, $rows), 'and specifically not at Basement');

$statement = $pdo->prepare('SELECT COALESCE(SUM(scl.amount), 0)
	FROM stock_current_locations scl
	JOIN locations_resolved lr ON lr.descendant_location_id = scl.location_id
	WHERE lr.ancestor_location_id = ? AND scl.product_id = ?');
$statement->execute([$basement, $frozenPeas]);

check(abs((float)$statement->fetchColumn() - 4) < 0.000001,
	'joining through locations_resolved from Basement does find it - the roll-up is the join, not the view');

// --- 7. The entities ---------------------------------------------------------------------

echo "\n7. /objects/locations and /objects/locations_resolved\n";

$response = $api->GetObject(request(), new Response(), ['entity' => 'locations', 'objectId' => $door]);
$location = json_decode((string)$response->getBody(), true);

check(array_keys($location) === ['id', 'name', 'description', 'row_created_timestamp', 'is_freezer', 'active', 'parent_location_id', 'userfields'],
	'the key set is exactly what it was plus parent_location_id');
check((int)$location['parent_location_id'] === $uprightFreezer, 'and it carries the right parent');
check(!array_key_exists('import_epoch', $location), 'import_epoch is still off the wire');

$response = $api->GetObjects(request(), new Response(), ['entity' => 'locations_resolved']);
$resolved = json_decode((string)$response->getBody(), true);

check($response->getStatusCode() === 200, 'the resolved view lists');
check(is_array($resolved) && count($resolved) > 0 && array_keys($resolved[0]) === ['ancestor_location_id', 'descendant_location_id', 'depth', 'path', 'id'],
	'with exactly the five documented columns');

foreach ([['AddObject', 'POST'], ['EditObject', 'PUT'], ['DeleteObject', 'DELETE']] as [$method, $verb])
{
	$response = $api->$method(request($verb, ['depth' => 1]), new Response(), ['entity' => 'locations_resolved', 'objectId' => 1]);
	check($response->getStatusCode() >= 400, 'the resolved view refuses ' . $verb);
}

// The read policy, exercised rather than read off the array. EntityReadPolicy::Check() throws
// on an entity with no row at all, so an entity added to the spec and not to that class fails
// closed here rather than in production.
$readStatus = function () use ($api)
{
	try
	{
		return $api->GetObjects(request(), new Response(), ['entity' => 'locations_resolved'])->getStatusCode();
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

// --- 8. is_freezer is literal ------------------------------------------------------------

echo "\n8. is_freezer does not inherit\n";

$stockService = StockService::GetInstance();

// Two products so the freezing case and its control are never the same rows. Both start at
// Shelf3, which is not a freezer, with a due date a long way off.
$peasA = MakeProduct('Nested Locations Peas A', $shelf3, 90);
$peasB = MakeProduct('Nested Locations Peas B', $shelf3, 45);
$farOff = date('Y-m-d', strtotime('+300 days'));
$stockService->AddProduct($peasA, 2, $farOff, StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.00, $shelf3);
$stockService->AddProduct($peasB, 2, $farOff, StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.00, $shelf3);

// Door carries the flag itself, so this is the freezing case.
$stockService->TransferProduct($peasA, 1, $shelf3, $door);

$statement = $pdo->prepare('SELECT best_before_date FROM stock WHERE product_id = ? AND location_id = ?');
$statement->execute([$peasA, $door]);

check($statement->fetchColumn() === date('Y-m-d', strtotime('+90 days')),
	'transferring into Door applies default_best_before_days_after_freezing');

// The control. Same tree, UprightFreezer above Door still flagged, flag cleared on the leaf
// the stock actually points at - and nothing may read the ancestor's.
$pdo->prepare('UPDATE locations SET is_freezer = 0 WHERE id = ?')->execute([$door]);

check((int)$pdo->query('SELECT is_freezer FROM locations WHERE id = ' . $uprightFreezer)->fetchColumn() === 1,
	'UprightFreezer above Door still carries the flag for the control');

$stockService->TransferProduct($peasB, 1, $shelf3, $door);

$statement = $pdo->prepare('SELECT best_before_date FROM stock WHERE product_id = ? AND location_id = ?');
$statement->execute([$peasB, $door]);

check($statement->fetchColumn() === $farOff,
	'with the flag cleared on Door the due date is left alone, though its parent is a freezer');

$pdo->prepare('UPDATE locations SET is_freezer = 1 WHERE id = ?')->execute([$door]);

// --- 9. The location content sheet does not roll up --------------------------------------

echo "\n9. GetCurrentStockLocationContent() groups by the exact location\n";

$content = $stockService->GetCurrentStockLocationContent();
$atDoor = 0;
$atBasement = 0;

foreach ($content as $row)
{
	if ((int)$row->product_id !== $frozenPeas)
	{
		continue;
	}

	if ((int)$row->location_id === $door)
	{
		$atDoor += (float)$row->amount;
	}

	if ((int)$row->location_id === $basement)
	{
		$atBasement += (float)$row->amount;
	}
}

check($atDoor > 0, 'stock at Door is reported at Door');
check($atBasement === 0, 'stock at Door is not reported under Basement (plan 08 question 4)');

// --- 10. Two connections re-parenting at once --------------------------------------------

echo "\n10. concurrent re-parenting cannot build a cycle\n";

// The one case in this file that needs a second *process*, and it needs one for a reason
// worth stating, because the obvious cheaper version proves less than it looks like it does.
//
// What the guard has to survive is this: two re-parentings touch different rows, so nothing
// makes them wait for each other unless something is made to, and each one reads the tree
// before deciding. Without the advisory lock migrations/0273.pgsql.sql takes, both read the
// tree as it was before the other wrote, both find no cycle, and both commit - and because
// the view descends from roots, the resulting cycle is unreachable from one, so both rows
// vanish from locations_resolved and therefore from every picker and list.
//
// The lock alone is not the whole property. A blocked statement took its snapshot when it
// started, which is *before* the transaction it is waiting for committed, so being let
// through the lock is not the same as being told what happened while it waited. What makes
// the guard see the new tree is that the trigger function is VOLATILE - the default, and why
// no volatility is declared on it - so each query inside it takes a fresh snapshot under READ
// COMMITTED. Marked STABLE it would use the calling statement's snapshot instead, and the
// lock would serialise the checks while handing each of them the same stale answer.
//
// So the write that gets refused below has to be the *same statement* that waited, released
// by another process committing while it was blocked. A version that rolls the blocked
// statement back, commits the first writer and then retries would pass with the function
// marked STABLE, because the retry starts after the commit and its snapshot is fresh however
// the function is declared. Measured on PostgreSQL 16.13, this version tells them apart:
// VOLATILE waits 2.49s and refuses, STABLE waits the same 2.49s and accepts, committing the
// cycle and emptying both rows out of the view.
// Asserted directly as well as behaviourally, because the two failures read very differently:
// the scenario below says the guard let a cycle through, and this says why. Without it a
// reader of a failing run has to know that a trigger function's volatility decides which
// snapshot its queries see before the output means anything.
check($pdo->query("SELECT provolatile FROM pg_proc WHERE proname = 'trg_locations_check_parent'")->fetchColumn() === 'v',
	'the parent guard is VOLATILE, so its queries take a fresh snapshot after the wait');

$waitingLeft = MakeLocation('WaitingLeft');
$waitingRight = MakeLocation('WaitingRight');

// The child holds the lock for long enough that the parent below is certainly still blocked
// when it commits. It uses raw PDO rather than this suite's harness because all it has to be
// is a second connection that outlives the parent's statement.
$childCode = '$child = new PDO(getenv("NL_DSN"), getenv("NL_USER"), getenv("NL_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
	. '$child->beginTransaction();'
	. '$child->prepare("UPDATE locations SET parent_location_id = ? WHERE id = ?")->execute([(int)getenv("NL_RIGHT"), (int)getenv("NL_LEFT")]);'
	. 'usleep(2500000);'
	. '$child->commit();';

$childEnvironment = [
	'NL_DSN' => 'pgsql:host=' . VICTUAL_DB_HOST . ';port=' . intval(VICTUAL_DB_PORT) . ';dbname=' . VICTUAL_DB_NAME,
	'NL_USER' => VICTUAL_DB_USER,
	'NL_PASSWORD' => VICTUAL_DB_PASSWORD,
	'NL_LEFT' => (string)$waitingLeft,
	'NL_RIGHT' => (string)$waitingRight,
	'PATH' => getenv('PATH')
];

$childPipes = [];
$child = proc_open([PHP_BINARY, '-r', $childCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes, null, $childEnvironment);

// Waited for rather than slept past: the parent must not start its write until the child
// really holds the lock, or it would not block and the case would pass without proving
// anything. pg_locks is asked directly, so this is the lock itself rather than a guess at
// how long the child needs.
$childHoldsTheLock = false;

for ($attempt = 0; $attempt < 200; $attempt++)
{
	usleep(50000);

	if ((int)$pdo->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 273 AND objid = 1 AND granted")->fetchColumn() > 0)
	{
		$childHoldsTheLock = true;

		break;
	}
}

check($childHoldsTheLock, 'the first re-parenting takes the hierarchy lock');

// Bounded, so that a guard which never releases fails this phase instead of hanging CI.
$pdo->exec("SET lock_timeout = '30s'");

$startedWaiting = microtime(true);
$outcome = null;

try
{
	$pdo->prepare('UPDATE locations SET parent_location_id = ? WHERE id = ?')->execute([$waitingLeft, $waitingRight]);
}
catch (PDOException $exception)
{
	$outcome = $exception->getMessage();
}

$waited = microtime(true) - $startedWaiting;

fclose($childPipes[1]);
fclose($childPipes[2]);
$childStatus = proc_close($child);
$pdo->exec("SET lock_timeout = 0");

check($childStatus === 0, 'the first re-parenting committed');

// One-sided on purpose: the child holds the lock for 2.5s after the parent confirmed it, so
// the only way to get back here quickly is not to have waited at all. A slow machine only
// makes this larger.
check($waited >= 1.0, sprintf('the second re-parenting waits for the first rather than reading round it (waited %.2fs)', $waited));

check($outcome !== null && str_contains($outcome, 'Recursive nested location detected'),
	'and the same waiting statement is then refused, having been told what happened while it waited');

$statement = $pdo->prepare('SELECT COUNT(*) FROM locations_resolved WHERE descendant_location_id IN (?, ?) AND depth = 0');
$statement->execute([$waitingLeft, $waitingRight]);

check((int)$statement->fetchColumn() === 2,
	'both locations are still reachable from a root, so neither has vanished from the pickers');

echo "\n";

if ($failures === 0)
{
	echo "EVERY NESTED LOCATION ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
