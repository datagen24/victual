<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ApiKeyService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #515 (audit finding M15): DELETE /api/objects/{entity}/{id}
 * for an object another row still references answered 500, because
 * GenericEntityApiController::DeleteObject() only special-cased one specific foreign key
 * (locations.id via stock.location_id) and rethrew every other \PDOException uncaught,
 * reaching ExceptionController as an unclassified server fault.
 *
 * This runs at the httpboot phase (through request-subprocess-helper.php, the same harness
 * StockConcurrencyTest.php and #527's ComposedOperationAtomicityTest.php use) rather than by
 * calling GenericEntityApiController::DeleteObject() directly, because the defect is
 * specifically about what happens to an *uncaught* exception - a direct controller call
 * would just let a \PDOException escape the test method itself rather than exercising the
 * real routing/error-middleware pipeline (Slim's error middleware -> ExceptionController)
 * that turns an uncaught throwable into the 500 this issue is about. Only a real request
 * through the application's own bootstrap can show that symptom, and the fix, at all.
 *
 * Every case covers a distinct, *actually enforced* foreign key - confirmed by reading
 * db/pgsql/baseline/01_tables.sql and every migration, not assumed - so this exercises the
 * fix's "any entity" generality:
 *
 * - product_location_min_stock.product_id REFERENCES products(id) (migrations/0276.pgsql.sql)
 * - product_location_min_stock.location_id REFERENCES locations(id) (migrations/0276.pgsql.sql)
 * - locations.storage_class_id REFERENCES storage_classes(id) (migrations/0274.pgsql.php)
 * - locations.tare_qu_id REFERENCES quantity_units(id) (migrations/0276.pgsql.sql)
 *
 * What is deliberately not covered here: the audit's own list also named "quantity unit /
 * product group referenced by products" (products.qu_id_purchase/qu_id_stock/qu_id_consume/
 * qu_id_price, products.product_group_id). None of those columns carries a FOREIGN KEY
 * anywhere in the schema - confirmed by the same reading - so deleting a quantity unit or
 * product group a product depends on does not raise a \PDOException at all today; it
 * succeeds and leaves a dangling reference. That is a different, deeper defect (missing
 * referential integrity, not a mishandled violation) than this issue's "500 instead of 400",
 * and fixing it needs a maintainer decision - a new FOREIGN KEY migration, or new
 * application-level pre-delete checks - rather than a code-only change to this refusal path.
 * Reported to the master rather than decided here.
 */
class ReferenceRefusalTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9700;

	private static PDO $db;
	private static string $apiKey = '';

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		self::$db->exec("INSERT INTO users(id, username, password) VALUES (" . self::USER_ID . ", 'reference-refusal-caller', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . " , id FROM permission_hierarchy WHERE name = 'MASTER_DATA_EDIT'");

		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare('INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) '
			. "VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([
			ApiKeyService::HashKey(self::$apiKey),
			substr(self::$apiKey, -4),
			self::USER_ID,
			ApiKeyService::API_KEY_TYPE_DEFAULT
		]);
	}

	// ------------------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------------------

	private static function insertRow(string $table, array $columns): int
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO $table ($names) VALUES ($placeholders) RETURNING id");
		$statement->execute(array_values($columns));

		return (int)$statement->fetchColumn();
	}

	/** labels has no id column - migrations/0269.pgsql.sql's primary key is uid, supplied by the caller. */
	private static function insertLabel(array $columns): void
	{
		$names = implode(', ', array_keys($columns));
		$placeholders = implode(', ', array_fill(0, count($columns), '?'));
		$statement = self::$db->prepare("INSERT INTO labels ($names) VALUES ($placeholders)");
		$statement->execute(array_values($columns));
	}

	private static function insertProduct(string $name, int $locationId): int
	{
		return self::insertRow('products', [
			'name' => $name,
			'location_id' => $locationId,
			'qu_id_purchase' => 2,
			'qu_id_stock' => 2,
		]);
	}

	private static function rowExists(string $table, int $id): bool
	{
		$statement = self::$db->prepare("SELECT count(*) FROM $table WHERE id = ?");
		$statement->execute([$id]);

		return (int)$statement->fetchColumn() > 0;
	}

	/**
	 * DELETE /api/objects/{entity}/{id} through the real application bootstrap - see class
	 * docblock for why this cannot be a direct controller call. Mirrors
	 * ComposedOperationAtomicityTest::requestWithInfluxEnabled(), minus the InfluxDB flag this
	 * fix has no use for.
	 *
	 * @return array{status: int, body?: string, stderr: string}
	 */
	private static function delete(string $entity, int $objectId): array
	{
		$spec = [
			'method' => 'DELETE',
			'path' => '/api/objects/' . $entity . '/' . $objectId,
			'headers' => ['VICTUAL-API-KEY' => self::$apiKey],
		];

		$inherited = array_filter(array_merge($_SERVER, $_ENV), 'is_scalar');
		$env = array_merge($inherited, [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
		]);

		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			$env
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON. stdout: $output\nstderr: $errors");
		$result['stderr'] = $errors;

		return $result;
	}

	/**
	 * Review round 2's own reproduction: an unreferenced DELETE carries no values, so
	 * GenericErrorResponse()'s usual WithoutDriverText() fallback ("check that every value it
	 * carries suits the field it is for") sent an operator looking for a problem that did not
	 * exist - and ShowApiError() (public/js/victual.js) shows error_message for every 4xx, so
	 * that text reached the entity-list delete dialog verbatim. The exact string here has to
	 * match GenericEntityApiController::REFERENCE_REFUSAL_MESSAGE literally (that constant is
	 * private, so there is nothing to import instead) - a drift between the two is exactly the
	 * regression this assertSame() exists to catch, which assertStringNotContainsString() did
	 * not.
	 */
	private function assertOrdinaryReferenceRefusal(array $result, string $message): void
	{
		self::assertSame(400, $result['status'], "$message: expected 400, got {$result['status']} (body: {$result['body']}, stderr: {$result['stderr']})");

		$body = json_decode((string)$result['body'], true);
		self::assertIsArray($body, "$message: response body must be JSON");
		self::assertSame(
			'Object is still referenced by other objects; remove those references before deleting it',
			$body['error_message'] ?? null,
			"$message: must carry the fixed, actionable refusal message - not driver text, and not any other wording"
		);
	}

	// ------------------------------------------------------------------------------
	// product_location_min_stock.product_id REFERENCES products(id)
	// ------------------------------------------------------------------------------

	/**
	 * Given a product is named by a location's minimum stock, when it is deleted, then the
	 * request answers 400 rather than 500, and both the product and the minimum-stock row are
	 * left exactly as they were. This is the audit's own reproduction (M15 / issue #515).
	 */
	public function testDeletingAProductReferencedByALocationMinimumIsRefused(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'M15 Min Stock Location']);
		$productId = self::insertProduct('M15 Referenced Product', $locationId);
		$minStockId = self::insertRow('product_location_min_stock', [
			'product_id' => $productId,
			'location_id' => $locationId,
			'min_stock_amount' => 1,
		]);

		$result = self::delete('products', $productId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a product referenced by product_location_min_stock');
		self::assertTrue(self::rowExists('products', $productId), 'The product must survive the refused delete');
		self::assertTrue(self::rowExists('product_location_min_stock', $minStockId), 'The referencing row must be untouched');
	}

	// ------------------------------------------------------------------------------
	// product_location_min_stock.location_id REFERENCES locations(id)
	// ------------------------------------------------------------------------------

	/**
	 * The same table's other foreign key, in the other direction: a location named by a
	 * minimum-stock row cannot be deleted either, and answers the same documented refusal
	 * rather than 500 - "location (referenced by) products", per the issue's own reference
	 * class list, through the table that actually links the two.
	 */
	public function testDeletingALocationReferencedByAProductLocationMinimumIsRefused(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'M15 Min Stock Location Itself']);
		$productId = self::insertProduct('M15 Product For Location Case', $locationId);
		$minStockId = self::insertRow('product_location_min_stock', [
			'product_id' => $productId,
			'location_id' => $locationId,
			'min_stock_amount' => 1,
		]);

		$result = self::delete('locations', $locationId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a location referenced by product_location_min_stock');
		self::assertTrue(self::rowExists('locations', $locationId), 'The location must survive the refused delete');
		self::assertTrue(self::rowExists('product_location_min_stock', $minStockId), 'The referencing row must be untouched');
	}

	// ------------------------------------------------------------------------------
	// locations.storage_class_id REFERENCES storage_classes(id)
	// ------------------------------------------------------------------------------

	/**
	 * A storage class still assigned to a location cannot be deleted either - a second,
	 * unrelated entity pair covered by the same generic fix, with no per-entity code of its
	 * own.
	 */
	public function testDeletingAStorageClassReferencedByALocationIsRefused(): void
	{
		$classId = self::insertRow('storage_classes', [
			'name' => 'M15 Referenced Class',
			'treats_as_freezer' => 0,
			'sort_order' => 999,
		]);
		$locationId = self::insertRow('locations', [
			'name' => 'M15 Classified Location',
			'storage_class_id' => $classId,
		]);

		$result = self::delete('storage_classes', $classId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a storage class referenced by a location');
		self::assertTrue(self::rowExists('storage_classes', $classId), 'The storage class must survive the refused delete');

		$storedClassId = self::$db->prepare('SELECT storage_class_id FROM locations WHERE id = ?');
		$storedClassId->execute([$locationId]);
		self::assertSame($classId, (int)$storedClassId->fetchColumn(), 'The referencing location must still name the class');
	}

	// ------------------------------------------------------------------------------
	// locations.tare_qu_id REFERENCES quantity_units(id)
	// ------------------------------------------------------------------------------

	/**
	 * A quantity unit still used as a location's tare unit cannot be deleted either. This is
	 * the "quantity unit" reference class the issue names, through the one column that
	 * actually enforces it - see the class docblock for why products.qu_id_purchase/stock/
	 * consume/price do not (no FOREIGN KEY at all) and are out of this fix's scope.
	 */
	public function testDeletingAQuantityUnitReferencedByALocationsTareIsRefused(): void
	{
		$quId = self::insertRow('quantity_units', ['name' => 'M15 Referenced Unit']);
		$locationId = self::insertRow('locations', [
			'name' => 'M15 Tare Location',
			'tare_qu_id' => $quId,
			'tare_weight' => 100,
		]);

		$result = self::delete('quantity_units', $quId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a quantity unit referenced by a location tare');
		self::assertTrue(self::rowExists('quantity_units', $quId), 'The quantity unit must survive the refused delete');

		$storedQuId = self::$db->prepare('SELECT tare_qu_id FROM locations WHERE id = ?');
		$storedQuId->execute([$locationId]);
		self::assertSame($quId, (int)$storedQuId->fetchColumn(), 'The referencing location must still name the unit');
	}

	// ------------------------------------------------------------------------------
	// migrations/0269.pgsql.sql's retire_location_labels: a BEFORE DELETE trigger, so its own
	// write has to roll back with a refused delete too, not only the row DeleteObject() itself
	// targeted.
	// ------------------------------------------------------------------------------

	/**
	 * Given a location carries a live label and is also referenced by a product's minimum
	 * stock, when it is deleted, then the request is refused (400) and the label is left
	 * exactly as it was - not retired. retire_location_labels() runs BEFORE the DELETE it is
	 * attached to, inside the same statement; PostgreSQL's statement-level atomicity means a
	 * statement that ultimately fails undoes everything it - and anything it fired - already
	 * did, not only the row named in its own FROM/WHERE. Probed in review round 2; pinned
	 * here so a future change to this trigger, or to how DeleteObject() catches the failure,
	 * cannot silently retire a label out from under a delete that never actually happened.
	 */
	public function testDeletingAReferencedLocationLeavesItsLabelUnretired(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'M15 Labeled Location']);
		$productId = self::insertProduct('M15 Product For Label Case', $locationId);
		self::insertRow('product_location_min_stock', [
			'product_id' => $productId,
			'location_id' => $locationId,
			'min_stock_amount' => 1,
		]);
		self::insertLabel([
			'uid' => '0ABCDEFGHJKMN',
			'kind' => 'location',
			'target_id' => $locationId,
		]);

		$result = self::delete('locations', $locationId);

		$this->assertOrdinaryReferenceRefusal($result, 'Deleting a labeled location referenced by product_location_min_stock');

		$label = self::$db->prepare('SELECT target_id, retired_at, retirement_snapshot FROM labels WHERE uid = ?');
		$label->execute(['0ABCDEFGHJKMN']);
		$stored = $label->fetch(PDO::FETCH_ASSOC);

		self::assertSame($locationId, (int)$stored['target_id'], 'The label must still point at the location - not retired to a null target');
		self::assertNull($stored['retired_at'], 'The refused delete must not have retired the label');
		self::assertNull($stored['retirement_snapshot'], 'A live label carries no retirement snapshot');
	}

	// ------------------------------------------------------------------------------
	// Negative control: an unreferenced row of the same entities still deletes normally
	// ------------------------------------------------------------------------------

	/**
	 * The fix must not turn every delete into a 400 - an object nothing references still
	 * deletes normally. Exercises the same four entities as the refusal cases above, each
	 * with no referencing row.
	 */
	public function testDeletingAnUnreferencedObjectOfEachEntityStillSucceeds(): void
	{
		$locationId = self::insertRow('locations', ['name' => 'M15 Unreferenced Location']);
		$productId = self::insertProduct('M15 Unreferenced Product', $locationId);
		$classId = self::insertRow('storage_classes', ['name' => 'M15 Unreferenced Class', 'treats_as_freezer' => 0, 'sort_order' => 998]);
		$quId = self::insertRow('quantity_units', ['name' => 'M15 Unreferenced Unit']);

		$productResult = self::delete('products', $productId);
		self::assertSame(204, $productResult['status'], "An unreferenced product must still delete: {$productResult['body']}");
		self::assertFalse(self::rowExists('products', $productId));

		$classResult = self::delete('storage_classes', $classId);
		self::assertSame(204, $classResult['status'], "An unreferenced storage class must still delete: {$classResult['body']}");
		self::assertFalse(self::rowExists('storage_classes', $classId));

		$quResult = self::delete('quantity_units', $quId);
		self::assertSame(204, $quResult['status'], "An unreferenced quantity unit must still delete: {$quResult['body']}");
		self::assertFalse(self::rowExists('quantity_units', $quId));

		// Last: the location itself, once nothing above references it any more.
		$locationResult = self::delete('locations', $locationId);
		self::assertSame(204, $locationResult['status'], "An unreferenced location must still delete: {$locationResult['body']}");
		self::assertFalse(self::rowExists('locations', $locationId));
	}
}
