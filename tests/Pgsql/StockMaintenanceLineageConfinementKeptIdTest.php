<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Victual\Controllers\Api\StockApiController;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0036 (migration 0304) note, read first: the two probes below were written for ADR-0033's
 * lineage-confinement guard, which skipped these groups because the old merge rewrote stock_id
 * in stock, stock_log and stock_entry_origins and so would have repriced one purchase through
 * another. ADR-0036 section 6 removes that rewrite and the guards with it. Both probes now assert
 * the opposite outcome - the group merges - and keep the assertions that mattered: product
 * details avg_price and stock_edited_entries.edited_origin_amount are unchanged, and now also
 * that every stock_log row is byte-for-byte unchanged. The history below is kept because it is
 * why those value assertions exist.
 *
 * ADR-0033 decision 3, round 3b: StockMaintenanceCompactionTest's own
 * testLineageConfinementSkipsAGroupThatWouldCorruptAnOutsideRowsOrigin only covers an OUTSIDE
 * row naming a DISAPPEARING group id as its origin. Round 2's guard checked exactly that
 * direction and excluded the kept id, reasoning that the kept id's own identity never changes.
 * That reasoning missed two other cases, both real application flows (no forced stock_ids
 * needed for the second):
 *
 * - An outside row can name the group's KEPT id as its origin. The kept id's identity does not
 *   change, but the merge still moves a DIFFERENT purchase's history onto it, which the outside
 *   row's lineage never signed up for.
 * - The kept id itself can be the one whose OWN lineage names an outside row as ITS origin (the
 *   kept id is an unopened remainder of an earlier partial open, and the opened, price-corrected
 *   sibling is outside the merge). Merging pulls another purchase's booking under an identity
 *   whose lineage already points elsewhere.
 *
 * Round 3 tried "the kept id counts too, unconditionally" and broke two pre-existing tests that
 * legitimately merge two opened portions descended from the SAME purchase. Round 3b's guard
 * (StockService::CompactStockEntries(), the "Guard 2" block) only runs the broadened,
 * kept-id-inclusive check when the group's members resolve to MORE THAN ONE distinct origin
 * root; both probes below are two-root groups, so both conditions of that guard fire:
 *
 * - Probe 1: root(kp1-a) = kp1-a, root(kp1-z) = kp1-z - two distinct roots (the multi-root
 *   condition). The outside-link condition then finds the remainder's own row
 *   (stock_id = R, origin_stock_id = 'kp1-a'): R is outside the group, 'kp1-a' is a member.
 * - Probe 2: root(remainder) = A (via the remainder's own stock_entry_origins row), root(B) = B
 *   - two distinct roots. The very same row (stock_id = remainder, origin_stock_id = A) is
 *   also what satisfies the outside-link condition: the remainder is a group member (in fact
 *   the kept one) and A is outside.
 *
 * Both are demonstrated with ordinary API flows and asserted against product-details avg_price
 * and stock_edited_entries.edited_origin_amount - the values round 2's independent validator
 * found silently drifting - rather than only the row count.
 */
class StockMaintenanceLineageConfinementKeptIdTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockApiController $stock;
	private static int $locationA;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		$container = new \DI\Container();
		$container->set('view', new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_DATAPATH));
		$container->set('UrlManager', new \Victual\Helpers\UrlManager(''));
		self::$stock = new StockApiController($container);

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (9000, 'stockmaintenance-keptid-caller', 'fixture')");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		$location = self::$db->prepare('INSERT INTO locations (name) VALUES (?) RETURNING id');
		$location->execute(['Maintenance Kept Id A']);
		self::$locationA = (int)$location->fetchColumn();
	}

	private static function request(string $method = 'GET', $body = null)
	{
		$request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost/api');
		if ($body !== null)
		{
			$request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
		}

		return $request;
	}

	private static function call(callable $work): array
	{
		try
		{
			$response = $work();
			$status = $response->getStatusCode();
			$body = (string)$response->getBody();
		}
		catch (HttpException $exception)
		{
			$status = $exception->getCode();
			$body = $exception->getMessage();
		}

		$decoded = json_decode($body, true);
		return [$status, $decoded ?? $body];
	}

	private static function insertProduct(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, 2, 2, 2, 2) RETURNING id');
		$statement->execute([$name, self::$locationA]);

		return (int)$statement->fetchColumn();
	}

	/** A never-expiring (NULL due) purchase, the same way StockMaintenanceCompactionTest::purchase() makes one. */
	private static function purchaseNull(int $productId, float $amount, float $price): array
	{
		[$status, $decoded] = self::call(fn() => self::$stock->AddProduct(
			self::request('POST', ['amount' => $amount, 'best_before_date' => '2222-02-02', 'purchased_date' => '2026-01-01', 'price' => $price, 'location_id' => self::$locationA]),
			new Response(),
			['productId' => $productId]
		));
		self::assertSame(200, $status, 'purchase fixture: ' . json_encode($decoded));
		self::$db->prepare('UPDATE stock SET best_before_date = NULL WHERE stock_id = ?')->execute([$decoded[0]['stock_id']]);

		return $decoded;
	}

	private static function scalar(string $sql, array $params = [])
	{
		$statement = self::$db->prepare($sql);
		$statement->execute($params);
		$value = $statement->fetchColumn();

		return $value === false ? null : $value;
	}

	private static function rowsOf(string $sql, array $params = []): array
	{
		$statement = self::$db->prepare($sql);
		$statement->execute($params);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function avgApi(int $productId)
	{
		[$status, $body] = self::call(fn() => self::$stock->ProductDetails(self::request('GET'), new Response(), ['productId' => $productId]));
		self::assertSame(200, $status, 'product-details fixture: ' . json_encode($body));

		return $body['avg_price'] === null ? null : round((float)$body['avg_price'], 6);
	}

	private static function editedOriginAmount(string $stockId)
	{
		return self::scalar('SELECT edited_origin_amount FROM stock_edited_entries WHERE stock_id = ?', [$stockId]);
	}

	/**
	 * Buy 2 (A) and 1 (B), never-expiring. Open 1 of A, leaving remainder R with lineage R->A.
	 * Open B whole. R (kept id, since it is A's own identity - untouched by the open) is outside
	 * the group that forms from the two now-identical opened one-unit portions; that group's kept
	 * member is A, and R's lineage names A. Correcting R's price must not let A's opened portion
	 * absorb B's purchase through the merge - the merge must be skipped outright.
	 */
	public function testOutsideRemainderNamingTheKeptIdNoLongerBlocksTheMergeAndRepricesNothing(): void
	{
		$product = self::insertProduct('KeptId Probe1 Outside Names Kept');
		$a = self::purchaseNull($product, 2, 1.0);
		$b = self::purchaseNull($product, 1, 1.0);
		$purchaseBBookingId = (int)$b[0]['id'];

		foreach ([[$a[0]['stock_id'], 'kp1-a'], [$b[0]['stock_id'], 'kp1-z']] as [$old, $new])
		{
			self::$db->prepare('UPDATE stock SET stock_id = ? WHERE stock_id = ?')->execute([$new, $old]);
			self::$db->prepare('UPDATE stock_log SET stock_id = ? WHERE stock_id = ?')->execute([$new, $old]);
		}

		[$s1, $b1] = self::call(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => 'kp1-a']), new Response(), ['productId' => $product]));
		self::assertSame(200, $s1, 'open 1 of A: ' . json_encode($b1));
		[$s2, $b2] = self::call(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => 'kp1-z']), new Response(), ['productId' => $product]));
		self::assertSame(200, $s2, 'open all of B: ' . json_encode($b2));

		$remainder = self::rowsOf("SELECT s.* FROM stock s JOIN stock_entry_origins o ON o.stock_id = s.stock_id WHERE o.origin_stock_id = 'kp1-a' AND s.product_id = ?", [$product]);
		self::assertCount(1, $remainder, 'Sanity: A\'s unopened remainder exists with lineage naming kp1-a');
		$remainder = $remainder[0];

		[$s3, $b3] = self::call(fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 1, 'price' => 2.0]), new Response(), ['entryId' => (int)$remainder['id']]));
		self::assertSame(200, $s3, 'price correction on the outside remainder: ' . json_encode($b3));

		$groupsBefore = self::rowsOf('SELECT stock_id_group FROM stock_splits WHERE product_id = ?', [$product]);
		self::assertCount(1, $groupsBefore, 'Sanity: the two opened one-unit portions are a candidate group before the fix runs');

		$avgBefore = self::avgApi($product);
		$editedBefore = self::editedOriginAmount('kp1-z');
		$ledgerBefore = self::rowsOf('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id', [$product]);
		$originsBefore = self::rowsOf('SELECT * FROM stock_entry_origins ORDER BY stock_id', []);

		StockService::GetInstance()->CompactStockEntries($product);

		$opened = self::rowsOf('SELECT * FROM stock WHERE product_id = ? AND open = 1', [$product]);
		self::assertCount(1, $opened, 'ADR-0036: the group merges - nothing it does can reattribute history any more');
		self::assertSame(2.0, (float)$opened[0]['amount']);
		self::assertSame($ledgerBefore, self::rowsOf('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id', [$product]), 'No booking is rewritten');
		self::assertSame($originsBefore, self::rowsOf('SELECT * FROM stock_entry_origins ORDER BY stock_id', []), 'No origin link is rewritten');
		self::assertSame($avgBefore, self::avgApi($product), 'product-details avg_price must be unchanged: the merge must not reprice purchase B through the outside remainder\'s correction');
		self::assertSame($editedBefore, self::editedOriginAmount('kp1-z'), 'stock_edited_entries.edited_origin_amount for purchase B must be unchanged');
		self::assertSame((int)$b[0]['id'], $purchaseBBookingId, 'sanity guard against a fixture typo');
	}

	/**
	 * Buy 2 (A), open 1 (no forced ids). R is the unopened remainder with lineage R->A; A's
	 * opened one-unit portion is then price-corrected, putting it outside any future group. Buy
	 * 1 (B): B and R now match on every stock_splits column, and uniqid() ordering alone makes R
	 * (the earlier-created id) the kept member of that group. R's OWN lineage names A - an
	 * outside row once A is price-corrected out of the picture - so merging B onto R would pull
	 * B's purchase under an identity whose lineage already points at a different, unrelated
	 * purchase.
	 */
	public function testKeptMembersOwnLineageNamingAnOutsideOriginNoLongerBlocksTheMergeAndRepricesNothing(): void
	{
		$product = self::insertProduct('KeptId Probe2 Kept Names Outside');
		$a = self::purchaseNull($product, 2, 1.0);
		$stockIdA = $a[0]['stock_id'];
		$rowIdA = (int)self::scalar('SELECT id FROM stock WHERE stock_id = ?', [$stockIdA]);

		[$s1, $b1] = self::call(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockIdA]), new Response(), ['productId' => $product]));
		self::assertSame(200, $s1, 'open 1 of A: ' . json_encode($b1));
		$remainder = self::rowsOf('SELECT s.* FROM stock s JOIN stock_entry_origins o ON o.stock_id = s.stock_id WHERE o.origin_stock_id = ? AND s.product_id = ?', [$stockIdA, $product]);
		self::assertCount(1, $remainder, 'Sanity: A\'s unopened remainder exists with lineage naming A');
		$remainder = $remainder[0];

		[$s2, $b2] = self::call(fn() => self::$stock->EditStockEntry(self::request('PUT', ['amount' => 1, 'price' => 2.0]), new Response(), ['entryId' => $rowIdA]));
		self::assertSame(200, $s2, 'price correction on A\'s opened unit (outside the future group): ' . json_encode($b2));

		$b = self::purchaseNull($product, 1, 1.0);

		$groups = self::rowsOf('SELECT stock_id_group, stock_id_to_keep FROM stock_splits WHERE product_id = ?', [$product]);
		self::assertCount(1, $groups, 'Sanity: one candidate group - the remainder and B');
		self::assertSame($remainder['stock_id'], $groups[0]['stock_id_to_keep'], 'Sanity: the remainder (lineage -> A) is the kept id');

		$avgBefore = self::avgApi($product);
		$editedBefore = self::editedOriginAmount((string)$b[0]['stock_id']);
		$ledgerBefore = self::rowsOf('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id', [$product]);
		$originsBefore = self::rowsOf('SELECT * FROM stock_entry_origins ORDER BY stock_id', []);

		StockService::GetInstance()->CompactStockEntries($product);

		$unopened = self::rowsOf('SELECT * FROM stock WHERE product_id = ? AND open = 0', [$product]);
		self::assertCount(1, $unopened, 'ADR-0036: the remainder and B merge');
		self::assertSame(2.0, (float)$unopened[0]['amount']);
		self::assertSame((string)$b[0]['stock_id'], $unopened[0]['stock_id'], 'The survivor is the MAX(id) row, B\'s, and keeps its own stock_id');
		self::assertSame($ledgerBefore, self::rowsOf('SELECT * FROM stock_log WHERE product_id = ? ORDER BY id', [$product]), 'No booking is rewritten');
		self::assertSame($originsBefore, self::rowsOf('SELECT * FROM stock_entry_origins ORDER BY stock_id', []), 'No origin link is rewritten');
		self::assertSame($avgBefore, self::avgApi($product), 'product-details avg_price must be unchanged: the merge must not reprice purchase B through the outside opened unit\'s correction');
		self::assertSame($editedBefore, self::editedOriginAmount((string)$b[0]['stock_id']), 'stock_edited_entries.edited_origin_amount for purchase B must be unchanged');
	}

	/**
	 * The positive counterpart: a group whose members all descend from a SINGLE origin root
	 * merges normally, even though Guard 2's multi-root gate would otherwise apply to any group
	 * containing a split remainder. One purchase (K) opened twice in succession - the second
	 * open splits the first opened portion itself, so K's opened portion and the second opened
	 * portion (R1) share root K (R1's own stock_entry_origins row already names K directly, since
	 * RecordSplitOrigin() flattens through R1's own prior origin - there is no intermediate
	 * parent to preserve). root(K) = K, root(R1) = K: one root, so Guard 2 never runs at all,
	 * and Guard 1 (disappearing-id direction) does not fire either, matching
	 * testUndoRefusesProductOpenedAfterExplicitMaintenanceMerge's own second merge. Asserts the
	 * merge actually happens (unlike the two "must skip" tests above) and that avg_price and
	 * stock_edited_entries are unchanged by it - merging same-root portions changes no
	 * purchase's resolved root or total, so there is nothing to reprice.
	 */
	public function testTwiceOpenedSingleRootChainMerges(): void
	{
		$product = self::insertProduct('KeptId Single Root Chain');
		$purchase = self::purchaseNull($product, 6, 1.0);
		$stockIdK = $purchase[0]['stock_id'];

		[$s1, $b1] = self::call(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 2, 'stock_entry_id' => $stockIdK]), new Response(), ['productId' => $product]));
		self::assertSame(200, $s1, 'open 2 of K: ' . json_encode($b1));

		$remainderR1 = self::rowsOf('SELECT s.* FROM stock s JOIN stock_entry_origins o ON o.stock_id = s.stock_id WHERE o.origin_stock_id = ? AND s.product_id = ?', [$stockIdK, $product]);
		self::assertCount(1, $remainderR1, 'Sanity: K\'s unopened remainder (R1) exists with lineage naming K');
		$stockIdR1 = $remainderR1[0]['stock_id'];

		[$s2, $b2] = self::call(fn() => self::$stock->OpenProduct(self::request('POST', ['amount' => 1, 'stock_entry_id' => $stockIdR1]), new Response(), ['productId' => $product]));
		self::assertSame(200, $s2, 'open 1 of R1 (splitting it in turn): ' . json_encode($b2));

		$rootOfR1 = self::scalar('SELECT origin_stock_id FROM stock_entry_origins WHERE stock_id = ?', [$stockIdR1]);
		self::assertSame($stockIdK, $rootOfR1, 'Sanity: R1\'s own lineage is already flattened straight to K, not to some intermediate parent');

		$groups = self::rowsOf('SELECT stock_id_group, stock_id_to_keep FROM stock_splits WHERE product_id = ?', [$product]);
		self::assertCount(1, $groups, 'Sanity: K\'s opened portion and R1\'s opened portion are one candidate group - a single shared root');

		$avgBefore = self::avgApi($product);
		$editedBefore = self::rowsOf('SELECT stock_id, edited_origin_amount FROM stock_edited_entries WHERE stock_id IN (?, ?) ORDER BY stock_id', [$stockIdK, $stockIdR1]);

		StockService::GetInstance()->CompactStockEntries($product);

		$openedRows = self::rowsOf('SELECT id FROM stock WHERE product_id = ? AND open = 1', [$product]);
		self::assertCount(1, $openedRows, 'The single-root group DOES merge: Guard 2 never runs (one root), Guard 1 does not fire either');
		self::assertSame($avgBefore, self::avgApi($product), 'product-details avg_price is unchanged by a same-root merge');
		$editedAfter = self::rowsOf('SELECT stock_id, edited_origin_amount FROM stock_edited_entries WHERE stock_id IN (?, ?) ORDER BY stock_id', [$stockIdK, $stockIdR1]);
		self::assertSame($editedBefore, $editedAfter, 'stock_edited_entries is unchanged by a same-root merge');
	}
}
