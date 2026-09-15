<?php

// Do nested product groups hold their shape, and does nesting change nothing that was not
// meant to change?
//
//   php nested-product-groups-tests.php
//
// PostgreSQL only, for the reason the nested locations phase is: the subject is
// migrations/0278.pgsql.sql, above DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID, so a
// SQLite side would be asked about a column, a view and two triggers it does not have. The
// view phase cannot stand in for it either - it seeds SQLite and copies rows into PostgreSQL
// through the importer's common-column logic, so parent_product_group_id would arrive NULL
// for every row and every assertion below about a tree would be an assertion about a flat
// list.
//
// This is .devtools/pgsql/nested-locations-tests.php with the nouns changed, plan 30's own
// instruction applied one file further down. What it guards, five of which produce no error
// at all when they are wrong:
//
//   1. A tree that is not a tree. There is no foreign key on parent_product_group_id and
//      nothing but the triggers stops a cycle or a chain deeper than the cap. Cases 3 and 4,
//      each with a control that has to still be accepted.
//   2. Uniqueness at the root. UNIQUE(parent_product_group_id, name) without
//      NULLS NOT DISTINCT holds everywhere except among root groups, where PostgreSQL treats
//      each NULL parent as distinct - so two "Spices" roots would be accepted and the rule
//      would be silently half a rule. Case 2's third assertion is the one that fails without
//      the spelling. Names follow ADR-0023's worked example and its own acceptance spike,
//      which used "Dried" as a group name under two different parents for this exact case.
//   3. The mixed node, ADR-0023 decision 6 and its acceptance prerequisite 2: a group holding
//      a product and a subgroup at once needs no special case, because product_group_id and
//      parent_product_group_id are independent columns. Case 5.
//   4. The delete that reaches the client as a driver message, exercised the same way the
//      locations phase does: through GenericEntityApiController::DeleteObject(), where the
//      body has to carry the sentence a person reads and no SQLSTATE text. Case 6.
//   5. A cycle built by two transactions neither of which could build one alone, for the same
//      reason and by the same construction the locations phase's case 10 is. Case 7, the only
//      one here that needs a second connection.
//
// Case 8 is a control rather than a guard: product_groups_missing (migration 0268) predates
// this migration and nothing here touches it. Plan 30 question 1 - whether a parent group's
// minimum rolls up to descendant groups - is unanswered, so this asserts only that today's
// unchanged behaviour (a group's own direct members, nothing from its descendants) still
// holds once the table it reads is a tree instead of a flat list.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';
define('VICTUAL_USER_ID', 9600);
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
define('VICTUAL_LOCALE', 'en');
define('VICTUAL_AUTHENTICATED', true);
define('VICTUAL_USER_USERNAME', 'nested-product-groups-caller');
define('VICTUAL_USER_PICTURE_FILE_NAME', null);

use Victual\Services\DatabaseService;
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

function MakeGroup(string $name, ?int $parentId = null, int $active = 1): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO product_groups (name, parent_product_group_id, active) VALUES (?, ?, ?) RETURNING id');
	$statement->execute([$name, $parentId, $active]);

	return intval($statement->fetchColumn());
}

function MakeProduct(string $name, ?int $groupId): int
{
	global $pdo;

	$statement = $pdo->prepare('INSERT INTO products
		(name, description, location_id, qu_id_purchase, qu_id_stock, min_stock_amount,
		 default_best_before_days, product_group_id, active, treat_opened_as_out_of_stock)
		VALUES (?, ?, 2, 2, 2, 0, 0, ?, 1, 0) RETURNING id');
	$statement->execute([$name, 'Created by nested-product-groups-tests.php', $groupId]);

	return intval($statement->fetchColumn());
}

function ActAs(?string $roleCode): void
{
	global $pdo;

	$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9600');
	$pdo->exec('DELETE FROM user_roles WHERE user_id = 9600');

	if ($roleCode !== null)
	{
		$statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT 9600, id FROM roles WHERE code = ?');
		$statement->execute([$roleCode]);
	}
}

// The acting user, the same way nested-locations-tests.php writes its fixture user: an
// explicit id, ADMIN for everything up to the read-policy case near the end, which strips it.
$pdo->exec('DELETE FROM user_permissions WHERE user_id = 9600');
$pdo->exec('DELETE FROM user_roles WHERE user_id = 9600');
$pdo->exec('DELETE FROM users WHERE id = 9600');
$pdo->exec("INSERT INTO users (id, username, password) VALUES (9600, 'nested-product-groups-caller', 'fixture')");
ActAs('ADMIN');

echo "Nested product groups (" . DatabaseService::GetInstance()->GetDialect()->GetName() . ")\n\n";

// product_groups is empty after a fresh migrate - InitialDataSeeder seeds quantity units and
// locations but not product groups - so this phase owns the whole table and needs no cleanup
// pass the way the nested locations phase's does.
$spices = MakeGroup('Spices');
$parsley = MakeGroup('Parsley', $spices);
$garlic = MakeGroup('Garlic', $spices);
$pepper = MakeGroup('Pepper', $spices);
$fresh = MakeGroup('Fresh', $garlic);

// --- 1. The view resolves the tree -------------------------------------------------------

echo "1. product_groups_resolved\n";

check((int)$pdo->query('SELECT COUNT(*) FROM product_groups_resolved WHERE depth = 0')->fetchColumn() === 5,
	'five self rows, one per group');

$statement = $pdo->prepare('SELECT ancestor_product_group_id, depth FROM product_groups_resolved WHERE descendant_product_group_id = ? AND depth > 0 ORDER BY depth');
$statement->execute([$fresh]);
$ancestorsOfFresh = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

check($ancestorsOfFresh == [$garlic => 1, $spices => 2],
	"Fresh's ancestors are Garlic (1), Spices (2)");

$statement = $pdo->prepare('SELECT path FROM product_groups_resolved WHERE descendant_product_group_id = ? AND depth = 0');
$statement->execute([$fresh]);

check($statement->fetchColumn() === 'Spices / Garlic / Fresh',
	"Fresh's path is the whole chain joined by \" / \"");

// --- 2. Uniqueness -----------------------------------------------------------------------

echo "\n2. UNIQUE NULLS NOT DISTINCT (parent_product_group_id, name)\n";

// The worked tree in plan 30 and ADR-0023's own acceptance spike both use "Dried" as a group
// name under two different parents for this case.
check(refusal('INSERT INTO product_groups (name, parent_product_group_id) VALUES (?, ?)', ['Dried', $parsley]) === null,
	'"Dried" under Parsley is accepted');
check(refusal('INSERT INTO product_groups (name, parent_product_group_id) VALUES (?, ?)', ['Dried', $garlic]) === null,
	'a second "Dried" under a different parent, Garlic, is accepted');

$message = refusal('INSERT INTO product_groups (name, parent_product_group_id) VALUES (?, ?)', ['Dried', $garlic]);
check($message !== null && str_contains($message, 'product_groups_parent_name_key'),
	'a second "Dried" under the same parent, Garlic, is refused');

// Without NULLS NOT DISTINCT this insert succeeds, because PostgreSQL treats each NULL parent
// as distinct from every other. This is the assertion that spelling exists for.
$message = refusal('INSERT INTO product_groups (name, parent_product_group_id) VALUES (?, NULL)', ['Spices']);
check($message !== null && str_contains($message, 'product_groups_parent_name_key'),
	'a second root named Spices is refused');

// Cleared, the same way nested-locations-tests.php clears its own duplicate "Shelf1" after
// its own case 2: the two "Dried" groups this case exists to test were never part of the
// worked tree, and leaving them under Parsley and Garlic would make every later assertion
// about those groups' children have to account for them.
$pdo->exec("DELETE FROM product_groups WHERE name = 'Dried'");

// --- 3. Cycles ---------------------------------------------------------------------------

echo "\n3. cycles\n";

$message = refusal('UPDATE product_groups SET parent_product_group_id = id WHERE id = ?', [$garlic]);
check($message !== null && str_contains($message, 'Recursive nested product group detected'),
	'a group cannot be its own parent');

$message = refusal('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?', [$fresh, $spices]);
check($message !== null && str_contains($message, 'Recursive nested product group detected'),
	"Spices cannot be moved under its own descendant Fresh");

// The control: an ordinary move has to still be accepted, or the guard is refusing every
// update rather than the cyclic ones.
check(refusal('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?', [$pepper, $parsley]) === null,
	'Parsley can be moved under Pepper');
$pdo->prepare('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?')->execute([$spices, $parsley]);

// --- 4. Depth --------------------------------------------------------------------------

echo "\n4. hierarchy_depth_limit()\n";

check((int)$pdo->query('SELECT hierarchy_depth_limit()')->fetchColumn() === 6,
	'the depth function is the same one plan 08 shared, still returning 6');

$chain = [MakeGroup('Deep1')];
for ($i = 2; $i <= 6; $i++)
{
	$chain[] = MakeGroup('Deep' . $i, $chain[count($chain) - 1]);
}

check(count($chain) === 6, 'a chain of six groups is accepted');

$message = refusal('INSERT INTO product_groups (name, parent_product_group_id) VALUES (?, ?)', ['Deep7', $chain[5]]);
check($message !== null && str_contains($message, 'Product group nesting depth limit exceeded'),
	'a seventh group in that chain is refused');

foreach (array_reverse($chain) as $deepId)
{
	$pdo->prepare('DELETE FROM product_groups WHERE id = ?')->execute([$deepId]);
}

// The subtree case: a group being reparented only moves one level itself, but the depth
// arithmetic is about the whole subtree underneath it, not the row being written - two
// isolated trees, so the target is provably not a descendant of the one being moved and the
// case is not also a cycle in disguise. Deep3Root carries a subtree two levels tall
// (Deep3Root / Deep3Mid / Deep3Leaf); WideRoot is a separate four-level chain.
$deep3Root = MakeGroup('Deep3Root');
$deep3Mid = MakeGroup('Deep3Mid', $deep3Root);
$deep3Leaf = MakeGroup('Deep3Leaf', $deep3Mid);
$wideRoot = MakeGroup('WideRoot');
$wideChain = [$wideRoot];
for ($i = 2; $i <= 4; $i++)
{
	$wideChain[] = MakeGroup('Wide' . $i, $wideChain[count($wideChain) - 1]);
}

// Deep3Root (subtree height 2) under Wide4 (level 3): 3 + 1 + 2 = 6, over the limit.
$message = refusal('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?', [$wideChain[3], $deep3Root]);
check($message !== null && str_contains($message, 'Product group nesting depth limit exceeded'),
	'Deep3Root (height 2) cannot be reparented under Wide4 (level 3)');

// The control: the same subtree under the shallow root of the other tree has to still be
// accepted, or the guard is refusing every reparenting rather than the ones that overflow.
check(refusal('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?', [$wideRoot, $deep3Root]) === null,
	'Deep3Root can be reparented under WideRoot (level 0)');

// Leaves before parents: the delete guard refuses a parent whose child still exists, and it
// refuses that inside a single multi-row DELETE too, so a bulk delete-by-pattern here would
// hit exactly the guard case 6 exercises deliberately.
foreach ([$deep3Leaf, $deep3Mid, $deep3Root] as $id)
{
	$pdo->prepare('DELETE FROM product_groups WHERE id = ?')->execute([$id]);
}
foreach (array_reverse($wideChain) as $id)
{
	$pdo->prepare('DELETE FROM product_groups WHERE id = ?')->execute([$id]);
}

// --- 5. The mixed node: a group holding a product and a subgroup at once -----------------

echo "\n5. Garlic holds the product \"Dried (Garlic)\" and the subgroup Fresh at once\n";

$driedGarlic = MakeProduct('Dried (Garlic)', $garlic);
$freshWhole = MakeProduct('Whole (Fresh Garlic)', $fresh);

check((int)$pdo->query('SELECT COUNT(*) FROM products WHERE product_group_id = ' . $garlic)->fetchColumn() === 1,
	'Garlic is a product\'s own group');
check((int)$pdo->query('SELECT COUNT(*) FROM product_groups WHERE parent_product_group_id = ' . $garlic)->fetchColumn() === 1,
	'and Garlic is a subgroup\'s parent, in the same row, with no special case anywhere in the schema');

$statement = $pdo->prepare('SELECT path FROM product_groups_resolved WHERE descendant_product_group_id = ? AND depth = 0');
$statement->execute([$fresh]);
check($statement->fetchColumn() === 'Spices / Garlic / Fresh',
	'product_groups_resolved still reaches Spices / Garlic / Fresh through Garlic');

check((int)$pdo->query('SELECT COUNT(*) FROM products WHERE product_group_id = ' . $fresh)->fetchColumn() === 1,
	'Fresh resolves its own product membership independently of what its parent Garlic holds');

// --- 6. Deleting a group that has children ------------------------------------------------

echo "\n6. deleting a product group that has children\n";

$message = refusal('DELETE FROM product_groups WHERE id = ?', [$garlic]);
check($message !== null && str_contains($message, 'Product group has child groups'),
	'the database refuses to delete Garlic');

$disposable = MakeGroup('Disposable', $pepper);
check(refusal('DELETE FROM product_groups WHERE id = ?', [$disposable]) === null,
	'a childless group is still deletable');

$api = new GenericEntityApiController($container);
$response = $api->DeleteObject(request('DELETE'), new Response(), ['entity' => 'product_groups', 'objectId' => $garlic]);
$body = (string)$response->getBody();

check($response->getStatusCode() === 400, 'the API answers 400 rather than 500 or 204');
check(str_contains($body, 'Product group has child groups'), 'the response body carries the sentence a person reads');
check(!str_contains($body, 'SQLSTATE'), 'the response body carries no driver text');
check((int)$pdo->query('SELECT COUNT(*) FROM product_groups WHERE id = ' . $garlic)->fetchColumn() === 1,
	'Garlic is still there afterwards');

// --- 7. Two connections re-parenting at once ----------------------------------------------

echo "\n7. concurrent re-parenting cannot build a cycle\n";

// Same construction as .devtools/pgsql/nested-locations-tests.php case 10, and for the same
// reason: the write that gets refused has to be the same statement that waited, released by
// another process committing while it was blocked, or the case proves nothing about
// volatility. See that file's own comment for the full argument.
check($pdo->query("SELECT provolatile FROM pg_proc WHERE proname = 'trg_product_groups_check_parent'")->fetchColumn() === 'v',
	'the parent guard is VOLATILE, so its queries take a fresh snapshot after the wait');

$waitingLeft = MakeGroup('WaitingLeft');
$waitingRight = MakeGroup('WaitingRight');

$childCode = '$child = new PDO(getenv("NPG_DSN"), getenv("NPG_USER"), getenv("NPG_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
	. '$child->beginTransaction();'
	. '$child->prepare("UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?")->execute([(int)getenv("NPG_RIGHT"), (int)getenv("NPG_LEFT")]);'
	. 'usleep(2500000);'
	. '$child->commit();';

$childEnvironment = [
	'NPG_DSN' => 'pgsql:host=' . VICTUAL_DB_HOST . ';port=' . intval(VICTUAL_DB_PORT) . ';dbname=' . VICTUAL_DB_NAME,
	'NPG_USER' => VICTUAL_DB_USER,
	'NPG_PASSWORD' => VICTUAL_DB_PASSWORD,
	'NPG_LEFT' => (string)$waitingLeft,
	'NPG_RIGHT' => (string)$waitingRight,
	'PATH' => getenv('PATH')
];

$childPipes = [];
$child = proc_open([PHP_BINARY, '-r', $childCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $childPipes, null, $childEnvironment);

$childHoldsTheLock = false;

for ($attempt = 0; $attempt < 200; $attempt++)
{
	usleep(50000);

	if ((int)$pdo->query("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 278 AND objid = 1 AND granted")->fetchColumn() > 0)
	{
		$childHoldsTheLock = true;

		break;
	}
}

check($childHoldsTheLock, 'the first re-parenting takes the hierarchy lock');

$pdo->exec("SET lock_timeout = '30s'");

$startedWaiting = microtime(true);
$outcome = null;

try
{
	$pdo->prepare('UPDATE product_groups SET parent_product_group_id = ? WHERE id = ?')->execute([$waitingLeft, $waitingRight]);
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
check($waited >= 1.0, sprintf('the second re-parenting waits for the first rather than reading round it (waited %.2fs)', $waited));
check($outcome !== null && str_contains($outcome, 'Recursive nested product group detected'),
	'and the same waiting statement is then refused, having been told what happened while it waited');

$statement = $pdo->prepare('SELECT COUNT(*) FROM product_groups_resolved WHERE descendant_product_group_id IN (?, ?) AND depth = 0');
$statement->execute([$waitingLeft, $waitingRight]);

check((int)$statement->fetchColumn() === 2,
	'both groups are still reachable from a root, so neither has vanished from the pickers');

// --- 8. product_groups_missing is unaffected (plan 30 question 1 is unanswered) ----------

echo "\n8. product_groups_missing still counts a group's own direct members only\n";

$pdo->prepare('UPDATE product_groups SET min_stock_amount = 3 WHERE id = ?')->execute([$spices]);

// Spices carries no product of its own - only its descendants Parsley and Garlic do - so it
// must be reported short by its whole minimum, taking no credit from members several levels
// below it. This is today's behaviour, unchanged by migration 0278; it is not an answer to
// question 1, only a control that the question is still open rather than settled by accident.
$statement = $pdo->prepare('SELECT amount_missing FROM product_groups_missing WHERE id = ?');
$statement->execute([$spices]);
$missing = $statement->fetchColumn();

check($missing !== false && abs((float)$missing - 3) < 0.000001,
	'Spices is reported short by its full minimum, with no roll-up from Parsley or Garlic\'s members');

// --- 9. The entities ---------------------------------------------------------------------

echo "\n9. /objects/product_groups and /objects/product_groups_resolved\n";

$response = $api->GetObject(request(), new Response(), ['entity' => 'product_groups', 'objectId' => $fresh]);
$group = json_decode((string)$response->getBody(), true);

check(array_keys($group) === ['id', 'name', 'description', 'row_created_timestamp', 'active', 'min_stock_amount', 'parent_product_group_id', 'userfields'],
	'the key set is exactly what it was plus parent_product_group_id');
check((int)$group['parent_product_group_id'] === $garlic, 'and it carries the right parent');

$response = $api->GetObjects(request(), new Response(), ['entity' => 'product_groups_resolved']);
$resolved = json_decode((string)$response->getBody(), true);

check($response->getStatusCode() === 200, 'the resolved view lists');
check(is_array($resolved) && count($resolved) > 0 && array_keys($resolved[0]) === ['ancestor_product_group_id', 'descendant_product_group_id', 'depth', 'path', 'id'],
	'with exactly the five documented columns');

foreach ([['AddObject', 'POST'], ['EditObject', 'PUT'], ['DeleteObject', 'DELETE']] as [$method, $verb])
{
	$response = $api->$method(request($verb, ['depth' => 1]), new Response(), ['entity' => 'product_groups_resolved', 'objectId' => 1]);
	check($response->getStatusCode() >= 400, 'the resolved view refuses ' . $verb);
}

// The read policy, exercised rather than read off the array. EntityReadPolicy::Check() throws
// on an entity with no row at all, so an entity added to the spec and not to that class fails
// closed here rather than in production.
$readStatus = function () use ($api)
{
	try
	{
		return $api->GetObjects(request(), new Response(), ['entity' => 'product_groups_resolved'])->getStatusCode();
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

echo "\n";

if ($failures === 0)
{
	echo "EVERY NESTED PRODUCT GROUP ANSWERED AS EXPECTED ($checks assertions)\n";
	exit(0);
}

echo $failures . " case(s) did not answer as expected\n";
exit(1);
