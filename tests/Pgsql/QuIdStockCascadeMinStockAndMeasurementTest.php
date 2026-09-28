<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issues #543 and #546 (#487 remediation): trg_cascade_change_qu_id_stock
 * (db/pgsql/baseline/06_triggers_a.sql, redefined by migrations/0294.pgsql.sql) fires
 * BEFORE UPDATE on `products` whenever a product's qu_id_stock changes and rescales every
 * amount stored in that unit by the resolved conversion factor.
 *
 * #543: product_location_min_stock.min_stock_amount (migrations/0276.pgsql.sql) is stored
 * in the product's stock unit exactly like chores.product_amount, meal_plan.product_amount,
 * recipes_pos.amount and shopping_list.amount, but the trigger never rescaled it - a
 * location minimum silently kept its old numeral in the new unit. This class drives the
 * change directly through `products` (a raw UPDATE, the same event the trigger's WHEN
 * clause fires on) rather than through a controller, since the defect and its fix are both
 * entirely inside the trigger function.
 *
 * #546: MergeProducts() (services/StockService.php, commit 791389623f) already refuses a
 * merge that would rescale a measured open container - live in `stock`, or only a live
 * (undone = 0) consume booking left in `stock_log` after a full consumption deleted the
 * `stock` row - by a factor other than 1, because that would violate
 * stock_measurement_coherence_check (migrations/0275.pgsql.sql). This trigger applies the
 * very same rescale on a single product's own qu_id_stock change and had no equivalent
 * guard; PR #598's own notes and commit 791389623f's comment both name this trigger's
 * rescale as the sibling failure they do not fix. This class reproduces both shapes
 * directly against the real trigger.
 */
class QuIdStockCascadeMinStockAndMeasurementTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static array $ids = [];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533: BaseService::GetInstance() caches one instance per class for the
		// whole PHPUnit process; other classes earlier in this same "stockcoverage" phase
		// already constructed StockService against their own (by now dropped) schemas.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$ids['pantry'] = self::insertRow('locations', ['name' => 'QuCascade Pantry']);
		self::$ids['gram'] = self::insertRow('quantity_units', ['name' => 'QuCascade Gram', 'name_plural' => 'QuCascade Grams']);
		self::$ids['kilogram'] = self::insertRow('quantity_units', ['name' => 'QuCascade Kilogram', 'name_plural' => 'QuCascade Kilograms']);
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

	private static function insertProduct(string $name, array $columns = []): int
	{
		$columns = array_merge([
			'name' => $name,
			'location_id' => self::$ids['pantry'],
			'qu_id_purchase' => self::$ids['gram'],
			'qu_id_stock' => self::$ids['gram'],
			'qu_id_consume' => self::$ids['gram'],
			'qu_id_price' => self::$ids['gram'],
		], $columns);

		return self::insertRow('products', $columns);
	}

	private static function changeStockUnit(int $productId, int $newQuId): void
	{
		$statement = self::$db->prepare('UPDATE products SET qu_id_stock = ? WHERE id = ?');
		$statement->execute([$newQuId, $productId]);
	}

	private static function minStockAmount(int $productId, int $locationId): ?float
	{
		$statement = self::$db->prepare('SELECT min_stock_amount FROM product_location_min_stock WHERE product_id = ? AND location_id = ?');
		$statement->execute([$productId, $locationId]);
		$value = $statement->fetchColumn();

		return $value === false ? null : (float)$value;
	}

	private static function shortfallAmountMissing(int $productId, int $locationId): ?float
	{
		$statement = self::$db->prepare('SELECT amount_missing FROM product_location_missing WHERE product_id = ? AND location_id = ?');
		$statement->execute([$productId, $locationId]);
		$value = $statement->fetchColumn();

		return $value === false ? null : (float)$value;
	}

	/** @return array<int, array<string, mixed>> */
	private static function stockRows(int $productId): array
	{
		$statement = self::$db->prepare('SELECT amount, open, opened_amount, opened_qu_id FROM stock WHERE product_id = ? ORDER BY id');
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/** @return array<int, array<string, mixed>> */
	private static function ledgerRows(int $productId): array
	{
		$statement = self::$db->prepare("SELECT amount, undone, opened_amount FROM stock_log WHERE product_id = ? AND transaction_type = 'consume' ORDER BY id");
		$statement->execute([$productId]);

		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Attempts the qu_id_stock change, asserting it throws, and returns the exception's
	 * message. Deliberately not "catch (\Exception)" for the same reason
	 * MergeProductsTest::expectMergeRefused() gives: PHPUnit's own assertion-failure
	 * exception is itself an \Exception, and swallowing it here would hide a real failure
	 * of this helper rather than reporting it.
	 */
	private function expectStockUnitChangeRefused(int $productId, int $newQuId, string $message): string
	{
		$caught = null;

		try
		{
			self::changeStockUnit($productId, $newQuId);
		}
		catch (\Throwable $exception)
		{
			$caught = $exception;
		}

		self::assertNotNull($caught, $message);

		return $caught->getMessage();
	}

	// ------------------------------------------------------------------------------
	// Issue #543: product_location_min_stock rescale
	// ------------------------------------------------------------------------------

	public function testChangingStockUnitRescalesTheLocationMinimumByTheConversionFactor(): void
	{
		$productId = self::insertProduct('QuCascade Min Stock Product');
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $productId,
		]);
		$location = self::insertRow('locations', ['name' => 'QuCascade Min Stock Location']);
		self::insertRow('product_location_min_stock', ['product_id' => $productId, 'location_id' => $location, 'min_stock_amount' => 500]);

		// Given: 300 g of actual stock at this location, so the shortfall view reports a
		// real (non-zero) amount_missing before the unit change - proving the assertion
		// below is not vacuously true of an empty shortfall.
		StockService::GetInstance()->AddProduct($productId, 300, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, $location);
		self::assertEqualsWithDelta(200.0, self::shortfallAmountMissing($productId, $location), 1e-9, 'Given: the shortfall view reports 500 - 300 = 200 g missing before the unit change');

		// When: the product's stock unit changes from gram to kilogram (factor 0.001).
		self::changeStockUnit($productId, self::$ids['kilogram']);

		// Then: the location minimum is rescaled by the same factor as every other
		// per-product amount this trigger already converts (issue #543's defect: before
		// the fix, min_stock_amount stayed 500 - now 500 "kg" instead of 0.5 kg).
		self::assertEqualsWithDelta(0.5, self::minStockAmount($productId, $location), 1e-9, 'Then: min_stock_amount is converted by the g->kg factor (500 * 0.001)');

		// ...and the shortfall view, which reads min_stock_amount alongside the (also
		// rescaled) stock amount, reports a correctly converted shortfall: 0.5 kg minimum
		// less 0.3 kg actual stock = 0.2 kg missing, not 200 (the un-rescaled minimum
		// compared against 0.3 kg of genuinely converted stock, which is what issue #543
		// describes going wrong).
		self::assertEqualsWithDelta(0.2, self::shortfallAmountMissing($productId, $location), 1e-9, 'Then: the shortfall view reports the correctly converted amount missing (0.5 - 0.3 = 0.2 kg)');
	}

	// ------------------------------------------------------------------------------
	// Issue #546: refusing a rescale of a measured open container or its ledger booking
	// ------------------------------------------------------------------------------

	public function testChangingStockUnitRefusesWhenALiveMeasuredOpenContainerWouldBeRescaled(): void
	{
		$productId = self::insertProduct('QuCascade Live Measured Product');
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $productId,
		]);

		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => 0.5, 'qu_id' => self::$ids['gram']]);

		$given = self::stockRows($productId);
		self::assertCount(1, $given, 'Given: fixture sanity check');
		self::assertEqualsWithDelta(1.0, (float)$given[0]['amount'], 1e-9, 'Given: a coherent single container (amount = 1)');
		self::assertEqualsWithDelta(0.5, (float)$given[0]['opened_amount'], 1e-9, 'Given: the container has been measured, 0.5 g remaining');

		// When: the stock unit changes by a non-1 factor while the measured container is
		// still live in `stock`.
		$message = $this->expectStockUnitChangeRefused($productId, self::$ids['kilogram'], 'Expected the qu_id_stock change to be refused: rescaling a live measured container\'s amount away from 1 violates stock_measurement_coherence_check');

		// Then: the refusal names the actual reason, and the container is untouched -
		// before the fix, the trigger's own "UPDATE stock SET amount = amount * v_factor"
		// would have hit the raw CHECK violation instead (a SQLSTATE 23514, not a
		// meaningful application message).
		self::assertStringContainsString('measured open container', $message, 'Then: the refusal explains why, rather than surfacing a raw database error');
		self::assertSame($given, self::stockRows($productId), 'Then: the measured entry is left exactly as it was');
	}

	public function testChangingStockUnitRefusesWhenALiveMeasuredConsumeBookingWouldBeRescaled(): void
	{
		$productId = self::insertProduct('QuCascade Consumed Measured Product');
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $productId,
		]);

		$stock = StockService::GetInstance();
		$stock->AddProduct($productId, 1, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);
		$stockRowId = (int)self::$db->query('SELECT id FROM stock WHERE product_id = ' . $productId)->fetchColumn();
		$stock->OpenProduct($productId, 1);
		$stock->MeasureStockEntry($stockRowId, ['amount' => 0.5, 'qu_id' => self::$ids['gram']]);
		$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME);

		self::assertSame([], self::stockRows($productId), 'Given: the fully consumed container leaves no live stock row');
		$given = self::ledgerRows($productId);
		self::assertCount(1, $given, 'Given: exactly one consume booking');
		self::assertEqualsWithDelta(-1.0, (float)$given[0]['amount'], 1e-9, 'Given: an ordinary whole-container consume logs amount -1');
		self::assertSame(0, (int)$given[0]['undone'], 'Given: the booking is live (not undone)');
		self::assertEqualsWithDelta(0.5, (float)$given[0]['opened_amount'], 1e-9, 'Given: the booking mirrors the container\'s own measurement');

		// When: the stock unit changes by a non-1 factor while the only trace of the
		// container is this live, undoable consume booking in `stock_log`.
		$message = $this->expectStockUnitChangeRefused($productId, self::$ids['kilogram'], 'Expected the qu_id_stock change to be refused: rescaling the live consume booking\'s amount away from -1 would make an eventual undo violate stock_measurement_coherence_check');

		self::assertStringContainsString('measured', $message, 'Then: the refusal explains why, rather than surfacing a raw database error');
		self::assertSame($given, self::ledgerRows($productId), 'Then: the ledger booking is left exactly as it was');
	}

	public function testChangingStockUnitStillRescalesAnUnmeasuredProductsStockAndLedger(): void
	{
		$productId = self::insertProduct('QuCascade Unmeasured Product');
		self::insertRow('quantity_unit_conversions', [
			'from_qu_id' => self::$ids['gram'],
			'to_qu_id' => self::$ids['kilogram'],
			'factor' => 0.001,
			'product_id' => $productId,
		]);

		StockService::GetInstance()->AddProduct($productId, 500, '2030-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-09-01', 0.01, self::$ids['pantry']);

		// When: the stock unit changes with no measured container anywhere in this
		// product's stock or ledger - the ordinary case issue #546's guard must not
		// regress into refusing.
		self::changeStockUnit($productId, self::$ids['kilogram']);

		$rows = self::stockRows($productId);
		self::assertCount(1, $rows, 'Then: the rescale still runs to completion');
		self::assertEqualsWithDelta(0.5, (float)$rows[0]['amount'], 1e-9, 'Then: amount is converted by the g->kg factor (500 * 0.001) exactly as before this migration');
	}
}
