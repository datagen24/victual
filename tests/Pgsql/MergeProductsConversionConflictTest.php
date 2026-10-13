<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #901: StockService::MergeProducts() rescales the removed product's stock-unit amounts
 * by its own stock-unit conversion, but a row that carries its OWN unit and is converted at
 * read time (a consumption recipe line, a consumption mapping with a unit, a recipes_pos row
 * that only checks a single unit, a barcode's package amount) then converts through the KEPT
 * product's conversions. Where the two products' effective factors for that unit differ, the
 * same row means a different physical quantity after the merge. Maintainer decision (option c,
 * 2026-10-13): refuse, before any row changes, when an affected row would convert to a different
 * stock-unit quantity; compare the effective (resolved) conversions, global fallbacks included,
 * with the repository's StockService::CompareAmounts rule.
 *
 * Every test builds its own quantity units, so a global conversion written by one cannot leak
 * into another.
 */
class MergeProductsConversionConflictTest extends PgsqlSchemaTestCase
{
	private const USER = 9410;

	private static PDO $db;
	private static int $location;

	/** Tables a refused merge must leave byte-for-byte as they were. */
	private const SNAPSHOT_TABLES = [
		'products', 'stock', 'stock_log', 'stock_row_lots', 'stock_booking_lots',
		'consumption_recipe_lines', 'consumption_event_lines', 'consumption_mappings',
		'recipes', 'recipes_pos', 'shopping_list', 'meal_plan', 'product_barcodes',
		'quantity_unit_conversions', 'cache__quantity_unit_conversions_resolved',
		'product_location_min_stock', 'chores',
	];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533: reset the per-class service singletons built against an earlier schema.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (" . self::USER . ", 'mpcc-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER . ", id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME')");
		self::$location = (int)self::$db->query("INSERT INTO locations (name) VALUES ('MPCC shelf') RETURNING id")->fetchColumn();
	}

	private static function unit(string $name): int
	{
		$statement = self::$db->prepare('INSERT INTO quantity_units (name, name_plural) VALUES (?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), $name . 's ' . bin2hex(random_bytes(3))]);

		return (int)$statement->fetchColumn();
	}

	private static function product(string $name, int $stockUnit, ?int $parent = null): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price, parent_product_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), self::$location, $stockUnit, $stockUnit, $stockUnit, $stockUnit, $parent]);

		return (int)$statement->fetchColumn();
	}

	/** A product-specific conversion, or a global one when $productId is null. */
	private static function conversion(int $from, int $to, float $factor, ?int $productId): void
	{
		$statement = self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, ?, ?)');
		$statement->execute([$from, $to, $factor, $productId]);
	}

	private static function stock(int $productId, float $amount): void
	{
		StockService::GetInstance()->AddProduct($productId, $amount, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);
	}

	private static function recipeLine(int $productId, float $amount, int $quId): int
	{
		return ConsumptionRecipeService::GetInstance()->CreateRecipe('MPCC recipe ' . bin2hex(random_bytes(3)), null, [['product_id' => $productId, 'amount' => $amount, 'qu_id' => $quId]], self::USER);
	}

	private static function mapping(int $productId, ?int $quId): void
	{
		$statement = self::$db->prepare("INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, qu_id, quantity_factor, location_mode, effective_from)
			VALUES (?, 'mpcc', ?, 'product', ?, ?, 1, 'single', now())");
		$statement->execute([self::USER, 'ref-' . bin2hex(random_bytes(4)), $productId, $quId]);
	}

	private static function recipesPos(int $productId, float $amount, ?int $quId, int $onlyCheckSingleUnit): int
	{
		$recipeId = (int)self::$db->query("INSERT INTO recipes (name) VALUES ('MPCC pos " . bin2hex(random_bytes(3)) . "') RETURNING id")->fetchColumn();
		$statement = self::$db->prepare('INSERT INTO recipes_pos (recipe_id, product_id, amount, qu_id, only_check_single_unit_in_stock) VALUES (?, ?, ?, ?, ?)');
		$statement->execute([$recipeId, $productId, $amount, $quId, $onlyCheckSingleUnit]);

		return (int)self::$db->query("SELECT id FROM recipes_pos WHERE recipe_id = $recipeId")->fetchColumn();
	}

	/** What the stored amount and the recipes_pos view's calories are for one row. */
	private static function recipesPosReading(int $recipePosId): array
	{
		$calories = (float)self::$db->query("SELECT calories FROM recipes_pos_resolved WHERE recipe_pos_id = $recipePosId")->fetchColumn();

		return [(float)self::$db->query("SELECT amount FROM recipes_pos WHERE id = $recipePosId")->fetchColumn(), $calories];
	}

	/** A conversion the cache holds for a product that the kept product will not have. */
	private static function phantomCacheRow(int $productId, int $fromQuId, int $toQuId, string $factor): void
	{
		self::$db->prepare('INSERT INTO cache__quantity_unit_conversions_resolved (product_id, from_qu_id, to_qu_id, factor, path) VALUES (?, ?, ?, ?, ?)')
			->execute([$productId, $fromQuId, $toQuId, $factor, '/' . $fromQuId . '/' . $toQuId . '/']);
	}

	private static function barcode(int $productId, ?float $amount, ?int $quId): void
	{
		$statement = self::$db->prepare('INSERT INTO product_barcodes (product_id, barcode, amount, qu_id) VALUES (?, ?, ?, ?)');
		$statement->execute([$productId, 'mpcc-' . bin2hex(random_bytes(5)), $amount, $quId]);
	}

	/** @return array<string, array<int, string>> */
	private static function snapshot(): array
	{
		$state = [];
		foreach (self::SNAPSHOT_TABLES as $table)
		{
			$state[$table] = self::$db->query("SELECT to_jsonb(t)::text FROM $table t ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
		}

		return $state;
	}

	private static function productExists(int $productId): bool
	{
		return self::$db->query("SELECT 1 FROM products WHERE id = $productId")->fetchColumn() !== false;
	}

	/** Merges and returns null on success, or the refusal message. */
	private static function merge(int $keep, int $remove): ?string
	{
		try
		{
			StockService::GetInstance()->MergeProducts($keep, $remove);
		}
		catch (\Exception $ex)
		{
			return $ex->getMessage();
		}

		return null;
	}

	/** The whole number as a token: a digit or decimal point of a longer number does not count. */
	private static function assertToken(string $token, string $message, string $what): void
	{
		self::assertMatchesRegularExpression('/(?<![\d.])' . preg_quote($token, '/') . '(?!\d|\.\d)/', $message, $what);
	}

	/** Asserts the conflict refusal, names, and that nothing at all changed. */
	private function assertConflictRefusal(int $keep, int $remove, array $unitIds, string $expectFactorB, string $expectFactorA): void
	{
		$before = self::snapshot();

		$message = self::merge($keep, $remove);

		self::assertNotNull($message, 'Then: the merge is refused');
		self::assertStringContainsString('Cannot merge', $message);
		self::assertToken((string)$keep, $message, 'Then: the message names the kept product id');
		self::assertToken((string)$remove, $message, 'Then: the message names the removed product id');
		foreach ($unitIds as $unitId)
		{
			self::assertToken((string)$unitId, $message, 'Then: the message names the unit id');
		}
		self::assertToken($expectFactorB, $message, 'Then: the message gives the removed product\'s factor');
		self::assertToken($expectFactorA, $message, 'Then: the message gives the kept product\'s factor');
		self::assertDoesNotMatchRegularExpression('/\b(change|edit|adjust|update|set)\b/i', $message, 'Then: the message does not tell the operator to alter a conversion');
		self::assertTrue(self::productExists($remove), 'Then: the removed product still exists');
		self::assertSame($before, self::snapshot(), 'Then: no row of any table changed');
	}

	public function testTheIssueExampleIsRefusedWithNothingChanged(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::stock($keep, 100);
		self::stock($remove, 60);
		self::recipeLine($remove, 1, $box);

		$this->assertConflictRefusal($keep, $remove, [$box], '30', '25');
	}

	public function testEqualFactorsMergeAndAConsumptionAfterwardsRemovesTheAmountTheLedgerImplies(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		// 1 box = 30 tablets, 1 tablet = 0.1 strip: the removed product's 1 box is 3 strips, which
		// is what the kept product's own box -> strip conversion says too.
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($tablet, $strip, 0.1, $remove);
		self::conversion($box, $strip, 3, $keep);
		self::stock($keep, 100);
		self::stock($remove, 60);
		$recipe = self::recipeLine($remove, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');

		$onHand = (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $keep")->fetchColumn();
		self::assertEqualsWithDelta(106.0, $onHand, 1e-9, 'Then: 60 tablets became 6 strips beside the kept 100');

		ConsumptionRecipeService::GetInstance()->Consume($recipe, null, null, null, self::USER);

		$after = (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $keep")->fetchColumn();
		self::assertEqualsWithDelta(103.0, $after, 1e-9, 'Then: 1 box takes 3 strips, the 30 tablets it was before the merge');
	}

	public function testAConflictThroughAGlobalConversionIsRefused(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		self::conversion($box, $tablet, 25, null);
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $keep);
		self::stock($keep, 100);
		self::stock($remove, 60);
		// The removed product has no row of its own: it reads the global 25.
		self::recipeLine($remove, 1, $box);

		$this->assertConflictRefusal($keep, $remove, [$box], '25', '30');
	}

	public function testTheRemovedProductsOwnFactorMovingOverAGlobalOneIsAllowed(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		self::conversion($box, $tablet, 25, null);
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		// The kept product has no pair of its own, so the removed product's 30 moves over and
		// wins against the global 25: the removed product's line still means 30 tablets.
		self::conversion($box, $tablet, 30, $remove);
		self::stock($keep, 100);
		self::stock($remove, 60);
		$recipe = self::recipeLine($remove, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');

		ConsumptionRecipeService::GetInstance()->Consume($recipe, null, null, null, self::USER);
		$onHand = (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $keep")->fetchColumn();
		self::assertEqualsWithDelta(130.0, $onHand, 1e-9, 'Then: 1 box still takes 30 tablets');
	}

	public function testAChildWhoseOwnConversionToTheKeptStockUnitDisagreesIsRefused(): void
	{
		$tablet = self::unit('MPCC tablet');
		$pack = self::unit('MPCC pack');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $pack);
		$remove = self::product('MPCC remove', $tablet);
		// The removed product's stock converts to the kept one's at 0.5 pack per tablet.
		self::conversion($tablet, $pack, 0.5, $remove);
		$child = self::product('MPCC child', $strip, $remove);
		self::conversion($strip, $tablet, 10, $child);
		// 10 tablets are 5 packs through the removed product; the child says 4.
		self::conversion($strip, $pack, 4, $child);
		self::stock($child, 3);

		$this->assertConflictRefusal($keep, $remove, [$strip], '10', '4');
		self::assertSame($remove, (int)self::$db->query("SELECT parent_product_id FROM products WHERE id = $child")->fetchColumn(), 'Then: the child still names the removed product');
	}

	public function testAChildWithNoPathToTheKeptStockUnitIsRefused(): void
	{
		$tablet = self::unit('MPCC tablet');
		$pack = self::unit('MPCC pack');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $pack);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $pack, 0.5, $remove);
		$child = self::product('MPCC child', $strip, $remove);
		self::conversion($strip, $tablet, 10, $child);

		$before = self::snapshot();
		$message = self::merge($keep, $remove);

		self::assertNotNull($message, 'Then: the merge is refused');
		self::assertStringContainsString((string)$child, $message, 'Then: the message names the child');
		self::assertSame($before, self::snapshot(), 'Then: no row changed');
	}

	public function testAChildWhoseConversionAgreesDoesNotBlockTheMerge(): void
	{
		$tablet = self::unit('MPCC tablet');
		$pack = self::unit('MPCC pack');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $pack);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $pack, 0.5, $remove);
		$child = self::product('MPCC child', $strip, $remove);
		self::conversion($strip, $tablet, 10, $child);
		self::conversion($strip, $pack, 5, $child);

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');
		self::assertSame($keep, (int)self::$db->query("SELECT parent_product_id FROM products WHERE id = $child")->fetchColumn(), 'Then: the child now names the kept product');
	}

	public function testARecipesPosRowThatOnlyChecksASingleUnitIsAffectedInItsOwnUnit(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::recipesPos($remove, 1, $box, 1);

		$this->assertConflictRefusal($keep, $remove, [$box], '30', '25');
	}

	public function testARecipesPosRowWithoutTheFlagHoldsAStockUnitAmountAndIsNotAffected(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::recipesPos($remove, 60, $box, 0);

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');
	}

	public function testAConsumptionMappingWithAUnitIsAffectedAndOneWithoutIsNot(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::mapping($remove, null);
		self::mapping($remove, $tablet);

		self::assertNull(self::merge($keep, $remove), 'Then: stock-unit mappings do not block the merge');

		$keep2 = self::product('MPCC keep', $tablet);
		$remove2 = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove2);
		self::conversion($box, $tablet, 25, $keep2);
		self::mapping($remove2, $box);

		$this->assertConflictRefusal($keep2, $remove2, [$box], '30', '25');
	}

	public function testABarcodeAmountInAConflictingUnitIsAffectedButOneWithoutAnAmountIsNot(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::barcode($remove, null, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: a unit with no amount carries no quantity');

		$keep2 = self::product('MPCC keep', $tablet);
		$remove2 = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove2);
		self::conversion($box, $tablet, 25, $keep2);
		self::barcode($remove2, 2, $box);

		$this->assertConflictRefusal($keep2, $remove2, [$box], '30', '25');
	}

	public function testABarcodeWithNoUnitIsStoredInTheStockUnitAndSoCarriesNoConflictingUnit(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::barcode($remove, 2, null);

		// default_qu_INS fills a missing unit with the stock unit, so a stored barcode amount
		// always names a unit; here it is the unit both products share.
		self::assertSame($tablet, (int)self::$db->query("SELECT qu_id FROM product_barcodes WHERE product_id = $remove")->fetchColumn());
		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');
	}

	public function testAConflictNoAffectedRowUsesDoesNotBlockTheMerge(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 25, $keep);
		self::stock($keep, 10);
		self::stock($remove, 10);
		// Own-unit rows exist, but in the unit both products agree on (their shared stock unit);
		// stock-unit amounts in a shopping list and a meal plan are rescaled, not converted.
		self::recipeLine($remove, 2, $tablet);
		self::$db->exec("INSERT INTO shopping_list (product_id, amount, qu_id) VALUES ($remove, 60, $box)");
		self::$db->exec("INSERT INTO meal_plan (day, type, product_id, product_amount, product_qu_id) VALUES (CURRENT_DATE, 'product', $remove, 60, $box)");

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');
		self::assertFalse(self::productExists($remove));
	}

	public function testFactorsEqualWithinTheCompareAmountsToleranceMerge(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 30 * (1 + 1e-13), $keep);
		self::recipeLine($remove, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: 30 and 30 * (1 + 1e-13) are the same under ADR-0032');
	}

	public function testFactorsThatDifferMateriallyAreRefused(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($box, $tablet, 30.0001, $keep);
		self::recipeLine($remove, 1, $box);

		$this->assertConflictRefusal($keep, $remove, [$box], '30', '30.0001');
	}

	/**
	 * Known limit, documented rather than fixed: CompareAmounts() has an absolute floor of 1e-9
	 * (AMOUNT_TOLERANCE), so two factors that are both below about 1e-8 compare equal however
	 * different they are in relative terms.
	 */
	public function testFactorsBelowTheAbsoluteFloorCompareEqualEvenWhenTheyDifferTwofold(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 1e-10, $remove);
		self::conversion($box, $tablet, 2e-10, $keep);
		self::recipeLine($remove, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: the 1e-10 against 2e-10 conflict is not detected');
	}

	public function testTheEffectiveFactorOfATransitivePathIsWhatIsCompared(): void
	{
		$box = self::unit('MPCC box');
		$strip = self::unit('MPCC strip');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		// The removed product reaches tablets from boxes only through strips: 6 * 5 = 30.
		self::conversion($box, $strip, 6, $remove);
		self::conversion($strip, $tablet, 5, $remove);
		self::conversion($box, $tablet, 30, $keep);
		self::recipeLine($remove, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: a transitive 30 agrees with a direct 30');

		$keep2 = self::product('MPCC keep', $tablet);
		$remove2 = self::product('MPCC remove', $tablet);
		self::conversion($box, $strip, 6, $remove2);
		self::conversion($strip, $tablet, 5, $remove2);
		self::conversion($box, $tablet, 25, $keep2);
		self::recipeLine($remove2, 1, $box);

		$this->assertConflictRefusal($keep2, $remove2, [$box], '30', '25');
	}

	public function testAKeptProductsDirectFactorBeatsTheTransitiveOneItWouldAlsoGainFromTheMove(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		// Through the removed product's rows, box -> tablet -> strip is 3 strips; the kept
		// product's own direct box -> strip says 4, and a direct row outranks a longer path.
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($tablet, $strip, 0.1, $remove);
		self::conversion($box, $strip, 4, $keep);
		self::recipeLine($remove, 1, $box);

		$this->assertConflictRefusal($keep, $remove, [$box], '3', '4');
	}

	public function testALineWhoseUnitIsTheRemovedProductsStockUnitMustAgreeWithTheKeptConversion(): void
	{
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $strip, 0.1, $remove);
		// The kept product defines its own tablet -> strip pair and it wins the dedupe.
		self::conversion($tablet, $strip, 0.2, $keep);
		self::recipeLine($remove, 1, $tablet);

		$this->assertConflictRefusal($keep, $remove, [$tablet], '0.1', '0.2');
	}

	public function testASingleUnitRecipesPosRowWithNoConversionForTheRemovedProductIsReadAtOne(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		// The removed product has no box conversion, so the view reads the row at COALESCE 1.0;
		// the kept product would read 25.
		self::conversion($box, $tablet, 25, $keep);
		self::recipesPos($remove, 1, $box, 1);

		$this->assertConflictRefusal($keep, $remove, [$box], '1', '25');
	}

	public function testASingleUnitRecipesPosAmountIsNotRescaledAndItsCaloriesAreKept(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		// 1 box = 30 tablets = 3 strips; a tablet carries 2, a strip 20.
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($tablet, $strip, 0.1, $remove);
		self::conversion($box, $strip, 3, $keep);
		self::$db->exec("UPDATE products SET calories = 2 WHERE id = $remove");
		self::$db->exec("UPDATE products SET calories = 20 WHERE id = $keep");
		$pos = self::recipesPos($remove, 1, $box, 1);
		$stockPos = self::recipesPos($remove, 60, $tablet, 0);
		[, $caloriesBefore] = self::recipesPosReading($pos);
		self::assertEqualsWithDelta(60.0, $caloriesBefore, 1e-9, 'Given: 1 box is 30 tablets of 2');

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');

		[$amount, $caloriesAfter] = self::recipesPosReading($pos);
		self::assertEqualsWithDelta(1.0, $amount, 1e-9, 'Then: an amount in the row\'s own unit is not rescaled');
		self::assertEqualsWithDelta($caloriesBefore, $caloriesAfter, 1e-9, 'Then: the calories are the same before and after');
		self::assertEqualsWithDelta(6.0, self::recipesPosReading($stockPos)[0], 1e-9, 'Then: a stock-unit amount is rescaled into strips');
	}

	/** The recipes_pos_resolved columns a flag=1 row is priced, calorie-counted and shopped by. */
	private static function recipesPosResolvedReading(int $recipePosId): array
	{
		$row = self::$db->query("SELECT costs, calories, missing_amount, amount_on_shopping_list, stock_amount FROM recipes_pos_resolved WHERE recipe_pos_id = $recipePosId")->fetch(PDO::FETCH_ASSOC);

		return array_map('floatval', $row);
	}

	public function testASingleUnitRowsCostsCaloriesAndShoppingListQuantityAreUnchangedByAMergeWithEqualEffectiveFactors(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		// 1 box = 30 tablets = 3 strips; a tablet carries 2 and costs 1, a strip carries 20 and costs 10.
		self::conversion($box, $tablet, 30, $remove);
		self::conversion($tablet, $strip, 0.1, $remove);
		self::conversion($box, $strip, 3, $keep);
		self::$db->exec("UPDATE products SET calories = 2 WHERE id = $remove");
		self::$db->exec("UPDATE products SET calories = 20 WHERE id = $keep");
		// The product is out of stock, so the row is short by its whole amount; the last purchase price still prices it.
		StockService::GetInstance()->AddProduct($remove, 60, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', 1.0, self::$location);
		StockService::GetInstance()->ConsumeProduct($remove, 60, false, StockService::TRANSACTION_TYPE_CONSUME);
		$pos = self::recipesPos($remove, 1, $box, 1);
		$before = self::recipesPosResolvedReading($pos);
		self::assertEqualsWithDelta(30.0, $before['costs'], 1e-6, 'Given: 1 box is 30 tablets at 1 each');

		self::assertNull(self::merge($keep, $remove), 'Then: the merge goes through');

		$after = self::recipesPosResolvedReading($pos);
		foreach (['costs', 'calories', 'missing_amount', 'amount_on_shopping_list'] as $column)
		{
			self::assertEqualsWithDelta($before[$column], $after[$column], 1e-6, "Then: $column is the same before and after (before " . $before[$column] . ', after ' . $after[$column] . ')');
		}
		// missing_amount is what RecipesService adds to the shopping list; with no stock to offset it, it is the row's own amount.
		self::assertEqualsWithDelta(1.0, $after['missing_amount'], 1e-6, 'Then: the shopping-list quantity is the unrescaled 1');
	}

	public function testASingleUnitRowWithNoConversionOnEitherSideAndTheSameStockUnitMerges(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::recipesPos($remove, 1, $box, 1);

		self::assertNull(self::merge($keep, $remove), 'Then: both read 1.0, which agrees');
	}

	public function testASingleUnitRowWithNoConversionOnEitherSideRefusesWhenTheStockUnitsScaleDiffers(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$strip = self::unit('MPCC strip');
		$keep = self::product('MPCC keep', $strip);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $strip, 0.1, $remove);
		self::recipesPos($remove, 1, $box, 1);

		$this->assertConflictRefusal($keep, $remove, [$box], '1', '1');
	}

	public function testASingleUnitRowReadableBeforeAndNotAfterRefusesWhenItReadsOtherThanOne(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::phantomCacheRow($remove, $box, $tablet, '30');
		self::recipesPos($remove, 1, $box, 1);

		$this->assertConflictRefusal($keep, $remove, [$box], '30', '1');
	}

	public function testASingleUnitRowReadableBeforeAndNotAfterMergesWhenItReadsOne(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::phantomCacheRow($remove, $box, $tablet, '1');
		self::recipesPos($remove, 1, $box, 1);

		self::assertNull(self::merge($keep, $remove), 'Then: 1.0 before and 1.0 after agree');
	}

	public function testABarcodeOnTheSameUnitStillRefusesWhenTheSingleUnitRowAgrees(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::phantomCacheRow($remove, $box, $tablet, '1');
		self::recipesPos($remove, 1, $box, 1);
		self::barcode($remove, 2, $box);

		$before = self::snapshot();
		$message = self::merge($keep, $remove);

		self::assertNotNull($message, 'Then: the barcode, readable before and not after, refuses');
		self::assertStringContainsString('no quantity unit conversion', $message);
		self::assertSame($before, self::snapshot(), 'Then: no row changed');
	}

	/** Asserts a refusal that names the given tokens and leaves every table (conversion cache included) as it was. */
	private function assertRefusalNaming(int $keep, int $remove, array $tokens): void
	{
		$before = self::snapshot();

		$message = self::merge($keep, $remove);

		self::assertNotNull($message, 'Then: the merge is refused');
		self::assertToken((string)$keep, $message, 'Then: the message names the kept product id');
		self::assertToken((string)$remove, $message, 'Then: the message names the removed product id');
		foreach ($tokens as $token)
		{
			self::assertToken((string)$token, $message, 'Then: the message names ' . $token);
		}
		self::assertDoesNotMatchRegularExpression('/\b(change|edit|adjust|update|set)\b/i', $message, 'Then: the message does not tell the operator to alter a conversion');
		self::assertTrue(self::productExists($remove), 'Then: the removed product still exists');
		self::assertSame($before, self::snapshot(), 'Then: no row of any table changed');
	}

	public function testTheKeptProductsLineOnAGlobalFactorIsRefusedWhenTheRemovedProductsOwnFactorWouldShadowIt(): void
	{
		$box = self::unit('MPCC box');
		$tablet = self::unit('MPCC tablet');
		self::conversion($box, $tablet, 25, null);
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $tablet, 30, $remove);
		$recipe = self::recipeLine($keep, 1, $box);
		$line = (int)self::$db->query("SELECT id FROM consumption_recipe_lines WHERE recipe_id = $recipe")->fetchColumn();

		$this->assertRefusalNaming($keep, $remove, [$box, $line, 25, 30]);
	}

	public function testTheKeptProductsTransitivePathIsRefusedWhenTheRemovedProductsDirectFactorWouldWin(): void
	{
		$box = self::unit('MPCC box');
		$pack = self::unit('MPCC pack');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		// 6 x 4 = 24 through packs; the removed product's direct 30 moves over and outranks it.
		self::conversion($box, $pack, 6, $keep);
		self::conversion($pack, $tablet, 4, $keep);
		self::conversion($box, $tablet, 30, $remove);
		self::recipeLine($keep, 1, $box);

		$this->assertRefusalNaming($keep, $remove, [$box, 24, 30]);
	}

	public function testAStructuralChangeThatKeepsTheKeptProductsFactorMerges(): void
	{
		$box = self::unit('MPCC box');
		$pack = self::unit('MPCC pack');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($box, $pack, 6, $keep);
		self::conversion($pack, $tablet, 5, $keep);
		self::conversion($box, $tablet, 30, $remove);
		self::recipeLine($keep, 1, $box);

		self::assertNull(self::merge($keep, $remove), 'Then: 6 x 5 through packs and a direct 30 are the same factor');
	}

	public function testTheKeptProductsBarcodeIsRefusedWhenItsConversionWouldBeGone(): void
	{
		$box = self::unit('MPCC box');
		$pack = self::unit('MPCC pack');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		// The kept product's cache holds a box -> tablet conversion that the view does not back;
		// the removed product's pack -> tablet row moving over rebuilds the cache rows on its paths.
		// (Written last: inserting a conversion rebuilds the cache rows on its paths.)
		self::conversion($pack, $tablet, 5, $remove);
		self::phantomCacheRow($keep, $box, $tablet, '25');
		self::barcode($keep, 2, $box);
		$barcode = (int)self::$db->query("SELECT id FROM product_barcodes WHERE product_id = $keep")->fetchColumn();

		$this->assertRefusalNaming($keep, $remove, [$box, $barcode, 25]);
	}

	public function testTheKeptProductsChildIsRefusedWhenItsConversionDiffersAfterTheMerge(): void
	{
		$pack = self::unit('MPCC pack');
		$strip = self::unit('MPCC strip');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($strip, $tablet, 10, null);
		$child = self::product('MPCC child', $strip, $keep);
		self::conversion($pack, $tablet, 5, $remove);
		self::phantomCacheRow($child, $strip, $tablet, '12');

		$this->assertRefusalNaming($keep, $remove, [$child, $strip, 12, 10]);
	}

	public function testAChildWithNoConversionOnEitherSideMergesWhenTheFactorIsOne(): void
	{
		$strip = self::unit('MPCC strip');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $tablet);
		$remove = self::product('MPCC remove', $tablet);
		$child = self::product('MPCC child', $strip, $remove);

		self::assertNull(self::merge($keep, $remove), 'Then: the view reads 1.0 on both sides');
		self::assertSame($keep, (int)self::$db->query("SELECT parent_product_id FROM products WHERE id = $child")->fetchColumn());
	}

	public function testAChildWithNoConversionOnEitherSideIsRefusedWhenTheFactorIsNotOne(): void
	{
		$strip = self::unit('MPCC strip');
		$pack = self::unit('MPCC pack');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $pack);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $pack, 0.5, $remove);
		$child = self::product('MPCC child', $strip, $remove);

		$this->assertRefusalNaming($keep, $remove, [$child, $strip, 0.5]);
	}

	public function testAChildWithNoConversionBeforeThatGainsOneAfterIsRefused(): void
	{
		$strip = self::unit('MPCC strip');
		$pack = self::unit('MPCC pack');
		$tablet = self::unit('MPCC tablet');
		$keep = self::product('MPCC keep', $pack);
		$remove = self::product('MPCC remove', $tablet);
		self::conversion($tablet, $pack, 0.5, $remove);
		$child = self::product('MPCC child', $strip, $remove);
		// The child reaches the kept product's stock unit but not the removed product's.
		self::conversion($strip, $pack, 4, $child);

		$this->assertRefusalNaming($keep, $remove, [$child, $strip, 4]);
	}

	private static function aggregatedAmount(int $productId): float
	{
		// The view has a rolled-up row and a plain one per parent; the rolled-up one is the aggregate.
		return (float)self::$db->query("SELECT amount_aggregated FROM stock_current WHERE product_id = $productId ORDER BY is_aggregated_amount DESC LIMIT 1")->fetchColumn();
	}

	/*
	 * Maintainer decision on #921 (2026-10-13): a child with no conversion to its parent's stock unit
	 * before or after the merge is allowed when the two stock units are the same effective unit
	 * relationship - identical, or joined by a resolved conversion whose factor is 1 - not only
	 * when the unit ids are identical. A missing conversion's fallback of 1 is no evidence of it.
	 * Identical units: testAChildWithNoConversionOnEitherSideMergesWhenTheFactorIsOne.
	 */
	public function testAChildWithNoConversionIsAllowedWhenAGlobalOneToOneFactorJoinsTheStockUnits(): void
	{
		$strip = self::unit('MPCC strip');
		$piece = self::unit('MPCC piece');
		$each = self::unit('MPCC each');
		self::conversion($piece, $each, 1, null);
		$keep = self::product('MPCC keep', $each);
		$remove = self::product('MPCC remove', $piece);
		$child = self::product('MPCC child', $strip, $remove);
		self::stock($remove, 2);
		self::stock($child, 3);
		// Migration 0298 (#553): a sub product with no conversion to its parent's stock unit contributes 0.
		self::assertSame(2.0, self::aggregatedAmount($remove), 'Given: the unconvertible child adds nothing to its parent');

		$message = self::merge($keep, $remove);

		self::assertNull($message, 'Then: the merge is allowed');
		self::assertSame($keep, (int)self::$db->query("SELECT parent_product_id FROM products WHERE id = $child")->fetchColumn());
		self::assertFalse(self::productExists($remove));
		self::assertSame(2.0, self::aggregatedAmount($keep), 'Then: the moved stock counts 2 in the kept unit and the child still adds nothing');
		self::assertSame(3.0, (float)self::$db->query("SELECT amount FROM stock WHERE product_id = $child")->fetchColumn(), 'Then: the child\'s own stock is untouched');
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM quantity_unit_conversions WHERE product_id IN ($keep, $child)")->fetchColumn(), 'Then: neither product has a conversion of its own, so the child still has none');
	}

	public function testAChildWithNoConversionIsRefusedWhenTheStockUnitsHaveNoConnectingConversion(): void
	{
		$strip = self::unit('MPCC strip');
		$piece = self::unit('MPCC piece');
		$each = self::unit('MPCC each');
		$keep = self::product('MPCC keep', $each);
		$remove = self::product('MPCC remove', $piece);
		self::product('MPCC child', $strip, $remove);

		$before = self::snapshot();
		$message = self::merge($keep, $remove);

		self::assertNotNull($message, 'Then: a missing conversion is not read as 1');
		self::assertStringContainsString('no quantity unit conversion exists', $message);
		self::assertTrue(self::productExists($remove));
		self::assertSame($before, self::snapshot(), 'Then: no row of any table changed');
	}

	public function testAChildWithNoConversionIsRefusedWhenOnlyAGlobalNonOneFactorJoinsTheStockUnits(): void
	{
		$strip = self::unit('MPCC strip');
		$piece = self::unit('MPCC piece');
		$each = self::unit('MPCC each');
		self::conversion($piece, $each, 2, null);
		$keep = self::product('MPCC keep', $each);
		$remove = self::product('MPCC remove', $piece);
		$child = self::product('MPCC child', $strip, $remove);

		$this->assertRefusalNaming($keep, $remove, [$child, $strip, 2]);
	}

	/*
	 * A child that gains or loses a conversion while the stock-unit factor is 1 cannot be built: a 1:1
	 * conversion between the stock units is symmetric and transitive, so a child that reaches one unit
	 * reaches the other at the same factor and a child that reaches neither still reaches neither. A
	 * child that does reach them is the next test; the gain/loss refusals stay covered by the tests
	 * above with a factor that is not 1.
	 */
	public function testAChildWhoseConversionSurvivesThroughTheEquivalenceIsNotAffected(): void
	{
		$strip = self::unit('MPCC strip');
		$piece = self::unit('MPCC piece');
		$each = self::unit('MPCC each');
		self::conversion($piece, $each, 1, null);
		$keep = self::product('MPCC keep', $each);
		$remove = self::product('MPCC remove', $piece);
		$child = self::product('MPCC child', $strip, $remove);
		// Readable before through piece; after the merge the same factor is reached through the global 1:1 to each.
		self::conversion($strip, $piece, 4, $child);
		self::stock($child, 3);

		self::assertNull(self::merge($keep, $remove), 'Then: the child converts at 4 on both sides');
		self::assertSame(12.0, self::aggregatedAmount($keep));
	}

	/**
	 * Open question (reported in the pull request): a 1:1 conversion that belongs to the removed product only is
	 * a resolved conversion path between the two stock units, so (a)-(c) of the decision allow it.
	 */
	public function testAChildWithNoConversionIsAllowedWhenARemovedProductOneToOneFactorJoinsTheStockUnits(): void
	{
		$strip = self::unit('MPCC strip');
		$piece = self::unit('MPCC piece');
		$each = self::unit('MPCC each');
		$keep = self::product('MPCC keep', $each);
		$remove = self::product('MPCC remove', $piece);
		self::conversion($piece, $each, 1, $remove);
		$child = self::product('MPCC child', $strip, $remove);

		self::assertNull(self::merge($keep, $remove), 'Then: a resolved 1:1 path between the stock units establishes equivalence');
		self::assertSame($keep, (int)self::$db->query("SELECT parent_product_id FROM products WHERE id = $child")->fetchColumn());
	}
}
