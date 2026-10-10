<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Plan 22 issue #699: the weekly organizer workflow on what the tree already does. Nothing here
 * adds behavior; each test pins something the maintainer's scope relies on so that it stays true.
 *
 * An organizer is an ordinary location. Filling one is `TransferProduct()`, which moves stock and
 * books no consumption. Consuming from it is `ConsumeProduct()` with a location, which takes only
 * from that location and refuses a shortfall (pinned from the recipe path as well in
 * ConsumptionRecipeServiceTest::testALocationIsNeverSilentlySwappedForAnother). Quantity
 * comparisons are ADR-0032's tolerance and attribution is ADR-0036's lineage, checked through
 * `stock_lineage_violations()` after every step.
 *
 * A consumption with no location follows the product's default consume location, then the
 * earliest due date, across every location, organizers included.
 * testAConsumptionWithNoLocationFollowsTheProductDefaultThenTheEarliestDueDate pins that order so
 * it is documented behaviour and not an accident. Labels and scans are pinned in
 * OrganizerLabelDisclosureTest, the stock routes in OrganizerApiTest.
 */
class OrganizerWorkflowTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static ConsumptionRecipeService $recipes;
	private static StockService $stock;
	private static int $cabinet;
	private static int $a;
	private static int $b;
	private static int $c;
	private static int $tablet;
	private static int $bottle;
	private static int $millilitre;
	private static int $ampoule;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$recipes = ConsumptionRecipeService::GetInstance();
		self::$stock = StockService::GetInstance();

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (9000, 'organizer-caller', 'fixture') ON CONFLICT DO NOTHING");
		self::$db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT 9000, id FROM permission_hierarchy WHERE name = 'ADMIN'");

		foreach (['cabinet' => 'Pharmacy cabinet', 'a' => 'Organizer A', 'b' => 'Organizer B', 'c' => 'Organizer C'] as $property => $name)
		{
			self::${$property} = (int)self::$db->query("INSERT INTO locations (name) VALUES ('$name') RETURNING id")->fetchColumn();
		}
		foreach (['tablet' => 'OW tablet', 'bottle' => 'OW bottle', 'millilitre' => 'OW mL', 'ampoule' => 'OW ampoule'] as $property => $name)
		{
			self::${$property} = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('$name') RETURNING id")->fetchColumn();
		}
	}

	private static function product(string $name, int $stockUnit, ?int $purchaseUnit = null, ?float $perPurchaseUnit = null): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$cabinet, $purchaseUnit ?? $stockUnit, $stockUnit, $stockUnit, $stockUnit]);
		$id = (int)$statement->fetchColumn();

		if ($purchaseUnit !== null)
		{
			// A product whose purchase unit differs from its stock unit already has the conversion
			// between them, written by the product's own trigger; the household enters its factor.
			$update = self::$db->prepare('UPDATE quantity_unit_conversions SET factor = ? WHERE product_id = ? AND from_qu_id = ? AND to_qu_id = ?');
			$update->execute([$perPurchaseUnit, $id, $purchaseUnit, $stockUnit]);
			if ($update->rowCount() === 0)
			{
				self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)')->execute([$purchaseUnit, $stockUnit, $perPurchaseUnit, $id]);
			}
		}

		return $id;
	}

	private static function at(int $product, int $location): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product AND location_id = $location")->fetchColumn();
	}

	private static function total(int $product): float
	{
		return (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $product")->fetchColumn();
	}

	private static function consumeBookings(int $product): int
	{
		return (int)self::$db->query("SELECT count(*) FROM stock_log WHERE product_id = $product AND transaction_type = 'consume' AND undone = 0")->fetchColumn();
	}

	private static function assertLineageHolds(string $step): void
	{
		self::assertSame([], self::$db->query('SELECT * FROM stock_lineage_violations(NULL)')->fetchAll(PDO::FETCH_ASSOC), "ADR-0036 invariants I1 to I3 hold after: $step");
	}

	private static function fill(int $product, float $amount, int $from, int $to): void
	{
		self::$stock->TransferProduct($product, $amount, $from, $to);
	}

	private static function recipe(int $product, float $amount, ?int $unit = null): int
	{
		return self::$recipes->CreateRecipe('Organizer recipe ' . bin2hex(random_bytes(3)), null, [['product_id' => $product, 'amount' => $amount, 'qu_id' => $unit ?? (int)self::$db->query("SELECT qu_id_stock FROM products WHERE id = $product")->fetchColumn()]]);
	}

	public function testThreeOrganizersFilledConsumedFromAndEmptiedBackKeepTheHouseholdTotal(): void
	{
		$product = self::product('OW pills', self::$tablet, self::$bottle, 90);
		self::$stock->AddProduct($product, 90, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$cabinet);
		self::assertSame(90.0, self::total($product));

		foreach ([self::$a, self::$b, self::$c] as $organizer)
		{
			self::fill($product, 7, self::$cabinet, $organizer);
		}
		self::assertSame([69.0, 7.0, 7.0, 7.0, 90.0], [self::at($product, self::$cabinet), self::at($product, self::$a), self::at($product, self::$b), self::at($product, self::$c), self::total($product)],
			'filling three organizers moves stock and leaves the household total');
		self::assertSame(0, self::consumeBookings($product), 'a transfer books no consumption');
		self::assertLineageHolds('three transfers');

		$recipe = self::recipe($product, 1);
		$event = self::$recipes->Consume($recipe, 'ow-a', self::$a);
		self::assertSame([6.0, 7.0, 7.0, 69.0, 89.0], [self::at($product, self::$a), self::at($product, self::$b), self::at($product, self::$c), self::at($product, self::$cabinet), self::total($product)],
			'consuming from organizer A charges only organizer A');
		self::assertSame([self::$a], array_values(array_unique(array_column($event['lines'], 'location_id'))));
		self::assertLineageHolds('a consumption from A');

		self::$recipes->UndoConsumption($recipe, $event['id']);
		self::assertSame([7.0, 90.0], [self::at($product, self::$a), self::total($product)], 'undo restores what the consumption booked, to organizer A');
		self::assertLineageHolds('the undo');

		$event = self::$recipes->Consume($recipe, 'ow-a-again', self::$a);
		self::fill($product, 7, self::$c, self::$cabinet);
		self::assertSame([0.0, 76.0, 89.0], [self::at($product, self::$c), self::at($product, self::$cabinet), self::total($product)], 'returning an organizer is a transfer');
		self::assertSame(1, self::consumeBookings($product), 'and books no consumption of its own');
		self::assertLineageHolds('the return of C');

		try
		{
			self::$recipes->UndoConsumption($recipe, $event['id']);
			self::fail('a later booking of the same purchase blocks the undo');
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame(['undo_refused', 409], [$exception->errorCode, $exception->status]);
			self::assertStringContainsString('subsequent dependent bookings', $exception->getMessage(), 'ADR-0036: an undo follows the lots, and the return of C moved units of the same purchase');
		}
		self::assertSame([6.0, 89.0], [self::at($product, self::$a), self::total($product)], 'the refused undo changed nothing');
		self::assertLineageHolds('the refused undo');

		try
		{
			self::$recipes->Consume($recipe, 'ow-c-empty', self::$c);
			self::fail('an empty organizer refuses');
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame('stock_refused', $exception->errorCode);
		}
		self::assertSame([76.0, 6.0, 7.0], [self::at($product, self::$cabinet), self::at($product, self::$a), self::at($product, self::$b)], 'and charges no other location');
	}

	public function testAConsumptionWithNoLocationFollowsTheProductDefaultThenTheEarliestDueDate(): void
	{
		$product = self::product('OW order', self::$tablet);
		self::$stock->AddProduct($product, 30, '2027-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$cabinet);
		self::$stock->AddProduct($product, 10, '2026-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-02', null, self::$b);
		self::fill($product, 7, self::$cabinet, self::$a);
		$recipe = self::recipe($product, 1);

		$first = self::$recipes->Consume($recipe, 'ow-order-1');
		self::assertSame([self::$b], array_values(array_unique(array_column($first['lines'], 'location_id'))),
			'with no location and no default, the earliest due date is used wherever it is stored');

		self::$db->exec('UPDATE products SET default_consume_location_id = ' . self::$a . " WHERE id = $product");
		$second = self::$recipes->Consume($recipe, 'ow-order-2');
		self::assertSame([self::$a], array_values(array_unique(array_column($second['lines'], 'location_id'))),
			"the product's default consume location comes first, which is how a household names the organizer in use");

		$third = self::$recipes->Consume($recipe, 'ow-order-3', self::$cabinet);
		self::assertSame([self::$cabinet], array_values(array_unique(array_column($third['lines'], 'location_id'))), 'and an explicit location overrides both');
		self::assertSame([6.0, 9.0, 22.0], [self::at($product, self::$a), self::at($product, self::$b), self::at($product, self::$cabinet)]);
		self::assertLineageHolds('consumptions with and without a location');
	}

	public function testFractionalAmountsAcrossOrganizersCompareWithinTheSharedTolerance(): void
	{
		$product = self::product('OW halves', self::$tablet);
		self::$stock->AddProduct($product, 1.0, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$cabinet);
		self::fill($product, 0.3, self::$cabinet, self::$a);
		self::fill($product, 0.7, self::$cabinet, self::$b);
		self::assertEqualsWithDelta(1.0, self::total($product), 1e-9);

		$recipe = self::recipe($product, 0.1);
		for ($i = 0; $i < 10; $i++)
		{
			self::$recipes->Consume($recipe, "ow-tenth-$i", $i < 3 ? self::$a : self::$b);
		}
		self::assertEqualsWithDelta(0.0, self::total($product), StockService::AMOUNT_TOLERANCE, 'ten tenths empty a row of one, whatever the float arithmetic leaves');
		try
		{
			self::$recipes->Consume($recipe, 'ow-tenth-11', self::$b);
			self::fail('nothing is left');
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame('stock_refused', $exception->errorCode);
		}
		self::assertLineageHolds('ten fractional consumptions');
	}

	public function testLiquidAndSingleUseFormatsBehaveLikePills(): void
	{
		$liquid = self::product('OW syrup', self::$millilitre, self::$bottle, 250);
		self::$stock->AddProduct($liquid, 500, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$cabinet);
		self::fill($liquid, 125, self::$cabinet, self::$a);
		$halfBottle = self::recipe($liquid, 0.5, self::$bottle);
		self::$recipes->Consume($halfBottle, 'ow-syrup', self::$a);
		self::assertSame([0.0, 375.0], [self::at($liquid, self::$a), self::total($liquid)], 'half a bottle is 125 mL, taken from the organizer that held it');
		self::assertLineageHolds('a liquid consumption');

		$single = self::product('OW ampoules', self::$ampoule);
		self::$stock->AddProduct($single, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$cabinet);
		self::fill($single, 3, self::$cabinet, self::$a);
		self::$recipes->Consume(self::recipe($single, 1), 'ow-ampoule', self::$a);
		self::assertSame([2.0, 7.0, 9.0], [self::at($single, self::$a), self::at($single, self::$cabinet), self::total($single)], 'a single-use item is unit stock like a tablet');

		$weak = self::product('OW tablet 5 mg', self::$tablet);
		$strong = self::product('OW tablet 10 mg', self::$tablet);
		foreach ([$weak, $strong] as $product)
		{
			self::$stock->AddProduct($product, 10, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$a);
		}
		self::$recipes->Consume(self::recipe($weak, 2), 'ow-strength', self::$a);
		self::assertSame([8.0, 10.0], [self::at($weak, self::$a), self::at($strong, self::$a)], 'distinct strengths are distinct products and never merge');
		self::assertLineageHolds('single-use and strengths');
	}
}
