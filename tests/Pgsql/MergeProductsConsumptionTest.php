<?php

namespace Victual\Tests\Pgsql;

use PDO;
use ReflectionProperty;
use Victual\Services\BaseService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #848: StockService::MergeProducts() repointed most product references to the kept
 * product but not consumption_recipe_lines, consumption_event_lines and consumption_mappings,
 * whose product_id foreign keys are ON DELETE CASCADE (migrations/0305.pgsql.sql,
 * migrations/0306.pgsql.sql), so the final DELETE FROM products silently destroyed them: a
 * recipe whose only line named the removed product became empty (422 empty_recipe on the next
 * Consume), its mappings vanished and booked events lost their lines.
 *
 * Fixture: the removed product is stocked in unit A, the kept product in unit B, and one A is
 * 30 B (an entered conversion on the removed product). Recipe lines carry their own unit and
 * are converted at read time, so a merge only repoints them; event lines hold stock-unit
 * amounts and are rescaled; a mapping with no unit holds a factor against the stock unit and
 * is rescaled, while one with its own unit keeps its factor.
 */
class MergeProductsConsumptionTest extends PgsqlSchemaTestCase
{
	private const USER = 9400;
	private const FACTOR = 30.0;

	private static PDO $db;
	private static int $unitA;
	private static int $unitB;
	private static int $unitC;
	private static int $location;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();

		// Issue #533: reset the per-class service singletons built against an earlier schema.
		(new ReflectionProperty(BaseService::class, 'Instances'))->setValue(null, []);

		self::$db->exec("INSERT INTO users (id, username, password) VALUES (" . self::USER . ", 'mpc-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER . ", id FROM permission_hierarchy WHERE name IN ('STOCK_VIEW', 'STOCK_CONSUME')");

		self::$unitA = self::scalar("INSERT INTO quantity_units (name, name_plural) VALUES ('MPC A', 'MPC As') RETURNING id");
		self::$unitB = self::scalar("INSERT INTO quantity_units (name, name_plural) VALUES ('MPC B', 'MPC Bs') RETURNING id");
		self::$unitC = self::scalar("INSERT INTO quantity_units (name, name_plural) VALUES ('MPC C', 'MPC Cs') RETURNING id");
		self::$location = self::scalar("INSERT INTO locations (name) VALUES ('MPC shelf') RETURNING id");
	}

	private static function scalar(string $sql): int
	{
		return (int)self::$db->query($sql)->fetchColumn();
	}

	private static function product(string $name, int $stockUnit): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name . ' ' . bin2hex(random_bytes(3)), self::$location, $stockUnit, $stockUnit, $stockUnit, $stockUnit]);

		return (int)$statement->fetchColumn();
	}

	/** @return array{0: int, 1: int} kept product (unit B, 100 on hand), removed product (unit A) */
	private static function pair(): array
	{
		$keep = self::product('MPC keep', self::$unitB);
		$remove = self::product('MPC remove', self::$unitA);
		self::$db->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (' . self::$unitA . ', ' . self::$unitB . ', ' . self::FACTOR . ", $remove)");
		StockService::GetInstance()->AddProduct($keep, 100, '2999-12-31', StockService::TRANSACTION_TYPE_PURCHASE, '2026-01-01', null, self::$location);

		return [$keep, $remove];
	}

	private static function recipe(array $lines): int
	{
		return ConsumptionRecipeService::GetInstance()->CreateRecipe('MPC recipe ' . bin2hex(random_bytes(3)), null, $lines, self::USER);
	}

	private static function mapping(string $ref, int $productId, ?int $quId, float $factor): int
	{
		$statement = self::$db->prepare("INSERT INTO consumption_mappings (user_id, source_system, medication_ref, target_type, product_id, qu_id, quantity_factor, location_mode, effective_from)
			VALUES (?, 'mpc', ?, 'product', ?, ?, ?, 'single', now()) RETURNING id");
		$statement->execute([self::USER, $ref . bin2hex(random_bytes(3)), $productId, $quId, $factor]);

		return (int)$statement->fetchColumn();
	}

	private static function event(int $productId, float $amount): int
	{
		$statement = self::$db->prepare("INSERT INTO consumption_events (user_id, source_system, source_event_id, state, transaction_id, occurred_at)
			VALUES (?, 'mpc', ?, 'booked', ?, now()) RETURNING id");
		$statement->execute([self::USER, 'e' . bin2hex(random_bytes(4)), 'mpc-tx-' . bin2hex(random_bytes(4))]);
		$eventId = (int)$statement->fetchColumn();

		self::$db->prepare('INSERT INTO consumption_event_lines (event_id, product_id, amount) VALUES (?, ?, ?)')->execute([$eventId, $productId, $amount]);

		return $eventId;
	}

	/** @return array<int, array<string, mixed>> */
	private static function rows(string $sql): array
	{
		return self::$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function productExists(int $productId): bool
	{
		return self::$db->query("SELECT 1 FROM products WHERE id = $productId")->fetchColumn() !== false;
	}

	/** Every row of the three consumption tables, for an unchanged-on-refusal comparison. */
	private static function consumptionSnapshot(): array
	{
		return [
			self::rows('SELECT * FROM consumption_recipe_lines ORDER BY id'),
			self::rows('SELECT * FROM consumption_event_lines ORDER BY id'),
			self::rows('SELECT id, product_id, qu_id, quantity_factor FROM consumption_mappings ORDER BY id'),
		];
	}

	public function testRecipeLinesMappingsAndEventLinesSurviveTheMergeOnTheKeptProduct(): void
	{
		[$keep, $remove] = self::pair();
		$recipe = self::recipe([['product_id' => $remove, 'amount' => 2, 'qu_id' => self::$unitA]]);
		$mappingStockUnit = self::mapping('ref-null-', $remove, null, 2.0);
		$mappingOwnUnit = self::mapping('ref-unit-', $remove, self::$unitA, 2.0);
		$event = self::event($remove, 7.0);

		StockService::GetInstance()->MergeProducts($keep, $remove);

		self::assertFalse(self::productExists($remove), 'Then: the removed product is gone');

		$lines = self::rows("SELECT product_id, amount, qu_id FROM consumption_recipe_lines WHERE recipe_id = $recipe");
		self::assertCount(1, $lines, 'Then: the recipe line survived the merge');
		self::assertSame($keep, (int)$lines[0]['product_id'], 'Then: the line names the kept product');
		self::assertEqualsWithDelta(2.0, (float)$lines[0]['amount'], 1e-9, 'Then: a line keeps the amount in its own unit');
		self::assertSame(self::$unitA, (int)$lines[0]['qu_id'], 'Then: a line keeps its own unit');

		$eventLines = self::rows("SELECT product_id, amount FROM consumption_event_lines WHERE event_id = $event");
		self::assertCount(1, $eventLines, 'Then: the event line survived the merge');
		self::assertSame($keep, (int)$eventLines[0]['product_id'], 'Then: the event line names the kept product');
		self::assertEqualsWithDelta(7.0 * self::FACTOR, (float)$eventLines[0]['amount'], 1e-9, 'Then: the event line amount is rescaled into the kept stock unit');

		$null = self::rows("SELECT product_id, qu_id, quantity_factor FROM consumption_mappings WHERE id = $mappingStockUnit");
		self::assertCount(1, $null, 'Then: the stock-unit mapping survived');
		self::assertSame($keep, (int)$null[0]['product_id']);
		self::assertNull($null[0]['qu_id']);
		self::assertEqualsWithDelta(2.0 * self::FACTOR, (float)$null[0]['quantity_factor'], 1e-9, 'Then: a mapping against the stock unit is rescaled');

		$own = self::rows("SELECT product_id, qu_id, quantity_factor FROM consumption_mappings WHERE id = $mappingOwnUnit");
		self::assertCount(1, $own, 'Then: the own-unit mapping survived');
		self::assertSame($keep, (int)$own[0]['product_id']);
		self::assertSame(self::$unitA, (int)$own[0]['qu_id']);
		self::assertEqualsWithDelta(2.0, (float)$own[0]['quantity_factor'], 1e-9, 'Then: a mapping with its own unit keeps its factor');
	}

	public function testARecipeWhoseOnlyLineNamedTheRemovedProductStillConsumes(): void
	{
		[$keep, $remove] = self::pair();
		$recipe = self::recipe([['product_id' => $remove, 'amount' => 2, 'qu_id' => self::$unitA]]);

		StockService::GetInstance()->MergeProducts($keep, $remove);
		ConsumptionRecipeService::GetInstance()->Consume($recipe, null, null, null, self::USER);

		$onHand = (float)self::$db->query("SELECT COALESCE(sum(amount), 0) FROM stock WHERE product_id = $keep")->fetchColumn();
		self::assertEqualsWithDelta(100.0 - 2 * self::FACTOR, $onHand, 1e-9, 'Then: 2 A is 60 B, taken from the kept product');
	}

	public function testAMappingOrRecipeLineWhoseUnitNoLongerConvertsRefusesTheMergeAndChangesNothing(): void
	{
		foreach (['mapping', 'line'] as $kind)
		{
			[$keep, $remove] = self::pair();
			self::$db->exec('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (' . self::$unitC . ', ' . self::$unitA . ", 5, $remove)");

			if ($kind === 'mapping')
			{
				self::mapping('stale-', $remove, self::$unitC, 1.0);
			}
			else
			{
				self::recipe([['product_id' => $remove, 'amount' => 1, 'qu_id' => self::$unitC]]);
			}

			// The unit's conversion is removed afterwards, which nothing forbids: the row is
			// stale and the merge must not carry it onto the kept product unchecked.
			self::$db->exec("DELETE FROM quantity_unit_conversions WHERE product_id = $remove AND (from_qu_id = " . self::$unitC . ' OR to_qu_id = ' . self::$unitC . ')');
			self::recipe([['product_id' => $remove, 'amount' => 3, 'qu_id' => self::$unitA]]);
			self::event($remove, 4.0);

			$before = self::consumptionSnapshot();
			$stockBefore = self::rows("SELECT product_id, amount FROM stock WHERE product_id IN ($keep, $remove) ORDER BY id");

			$caught = null;
			try
			{
				StockService::GetInstance()->MergeProducts($keep, $remove);
			}
			catch (\Throwable $exception)
			{
				$caught = $exception;
			}

			self::assertNotNull($caught, "A $kind whose unit has no conversion for the kept product refuses the merge");
			self::assertStringContainsString('Cannot merge', $caught->getMessage());
			self::assertTrue(self::productExists($remove), 'Then: the removed product survives a refusal');
			self::assertSame($before, self::consumptionSnapshot(), "Then: every consumption row is unchanged after a $kind refusal");
			self::assertSame($stockBefore, self::rows("SELECT product_id, amount FROM stock WHERE product_id IN ($keep, $remove) ORDER BY id"), 'Then: stock is unchanged after the refusal');
		}
	}
}
