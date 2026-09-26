<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PHPUnit\Framework\Attributes\Depends;
use Victual\Services\StockService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #507 regression (M7): RemoveProductFromShoppingList scope validation.
 * When a product appears on multiple lists, removal from one list must not affect another.
 *
 * The bug: RemoveProductFromShoppingList only filtered by product_id, not product_id + list_id,
 * so it would modify whichever list row fetch() returned first. Adding to list 2, then list 3,
 * then removing from list 3 would incorrectly modify list 2 instead.
 */
class ShoppingListScopeTest extends PgsqlSchemaTestCase
{
	private static PDO $db;
	private static StockService $stockService;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$stockService = new StockService(self::PdoAsDatabase());

		// Reset BaseService cached instances to avoid stale schema references across tests
		// (issue #533: if a cached singleton holds a database connection to the old schema,
		// it will fail with "relation ... does not exist" on the fresh schema)
		$reflection = new \ReflectionClass(\Victual\Services\BaseService::class);
		$property = $reflection->getProperty('instances');
		$property->setAccessible(true);
		$property->setValue(null, []);
	}

	private static function productExists(int $productId): bool
	{
		$stmt = self::$db->prepare('SELECT COUNT(*) FROM products WHERE id = ?');
		$stmt->execute([$productId]);
		return (int)$stmt->fetchColumn() > 0;
	}

	private static function getShoppingListItem(int $listId, int $productId): ?array
	{
		$stmt = self::$db->prepare('SELECT id, shopping_list_id, product_id, amount FROM shopping_list WHERE shopping_list_id = ? AND product_id = ? LIMIT 1');
		$stmt->execute([$listId, $productId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Test that removal from one list does not mutate another list when the same product
	 * appears on both. Verifies the fix for issue #507.
	 */
	public function testRemoveProductFromListDoesNotMutateOtherLists(): void
	{
		// Create a location for the product
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute(['Test Location']);
		$locationId = (int)$stmt->fetchColumn();

		// Create a product
		$stmt = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, 2, 2) RETURNING id');
		$stmt->execute(['Test Product', $locationId]);
		$productId = (int)$stmt->fetchColumn();

		// Create or use lists 2 and 3 (they should already exist in the schema)
		$list2Id = 2;
		$list3Id = 3;

		// Verify the lists exist
		$stmt = self::$db->prepare('SELECT COUNT(*) FROM shopping_lists WHERE id = ?');
		$stmt->execute([$list2Id]);
		$list2Exists = (int)$stmt->fetchColumn() > 0;
		if (!$list2Exists) {
			self::$db->exec("INSERT INTO shopping_lists(id, name) VALUES (2, 'Test List 2')");
		}

		$stmt->execute([$list3Id]);
		$list3Exists = (int)$stmt->fetchColumn() > 0;
		if (!$list3Exists) {
			self::$db->exec("INSERT INTO shopping_lists(id, name) VALUES (3, 'Test List 3')");
		}

		// Add product to list 2 with amount 5
		self::$stockService->AddProductToShoppingList($productId, 5, 2, null, $list2Id);

		// Add product to list 3 with amount 3
		self::$stockService->AddProductToShoppingList($productId, 3, 2, null, $list3Id);

		// Verify both entries exist
		$list2Item = self::getShoppingListItem($list2Id, $productId);
		self::assertNotNull($list2Item, 'Product should be on list 2 with amount 5');
		self::assertSame(5, (int)$list2Item['amount'], 'List 2 item should have amount 5');

		$list3Item = self::getShoppingListItem($list3Id, $productId);
		self::assertNotNull($list3Item, 'Product should be on list 3 with amount 3');
		self::assertSame(3, (int)$list3Item['amount'], 'List 3 item should have amount 3');

		// CRITICAL TEST: Remove 1 unit from list 3 (should result in list 3 having amount 2)
		self::$stockService->RemoveProductFromShoppingList($productId, 1, $list3Id);

		// Assert list 3 was modified correctly (amount reduced from 3 to 2)
		$list3ItemAfter = self::getShoppingListItem($list3Id, $productId);
		self::assertNotNull($list3ItemAfter, 'Product should still be on list 3 after partial removal');
		self::assertSame(2, (int)$list3ItemAfter['amount'], 'List 3 item should have amount 2 (3-1)');

		// CRITICAL ASSERTION: List 2 should be completely unchanged
		$list2ItemAfter = self::getShoppingListItem($list2Id, $productId);
		self::assertNotNull($list2ItemAfter, 'Product should still be on list 2 (unchanged)');
		self::assertSame(5, (int)$list2ItemAfter['amount'], 'List 2 item MUST still have amount 5 (this fails with the bug)');
	}

	/**
	 * Test that when a product is not on a specific list, removal returns gracefully
	 * without modifying any list.
	 */
	public function testRemoveProductNotOnListIsGraceful(): void
	{
		// Create a location for the product
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute(['Test Location 2']);
		$locationId = (int)$stmt->fetchColumn();

		// Create a product
		$stmt = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, 2, 2) RETURNING id');
		$stmt->execute(['Test Product 2', $locationId]);
		$productId = (int)$stmt->fetchColumn();

		// Use list 1
		$listId = 1;

		// Do NOT add the product to any list

		// Try to remove the product from list 1 (it's not there)
		// This should not throw an exception and should not modify any state
		try {
			self::$stockService->RemoveProductFromShoppingList($productId, 1, $listId);
			// Expected: operation completes gracefully
		} catch (\Exception $e) {
			self::fail("RemoveProductFromShoppingList should return gracefully when product is not on the list, but threw: " . $e->getMessage());
		}

		// Verify no entry was created
		$item = self::getShoppingListItem($listId, $productId);
		self::assertNull($item, 'No entry should exist for a product not on the list');
	}

	/**
	 * Control test: verify normal single-list removal works correctly.
	 */
	#[Depends('testRemoveProductFromListDoesNotMutateOtherLists')]
	public function testRemoveProductFromSingleListWorks(): void
	{
		// Create a location for the product
		$stmt = self::$db->prepare('INSERT INTO locations(name) VALUES (?) RETURNING id');
		$stmt->execute(['Test Location 3']);
		$locationId = (int)$stmt->fetchColumn();

		// Create a product
		$stmt = self::$db->prepare('INSERT INTO products(name, location_id, qu_id_purchase, qu_id_stock) VALUES (?, ?, 2, 2) RETURNING id');
		$stmt->execute(['Test Product 3', $locationId]);
		$productId = (int)$stmt->fetchColumn();

		$listId = 1;

		// Add product to list 1 with amount 5
		self::$stockService->AddProductToShoppingList($productId, 5, 2, null, $listId);

		$itemBefore = self::getShoppingListItem($listId, $productId);
		self::assertNotNull($itemBefore, 'Product should be on list 1');
		self::assertSame(5, (int)$itemBefore['amount'], 'Initial amount should be 5');

		// Remove 2 units
		self::$stockService->RemoveProductFromShoppingList($productId, 2, $listId);

		$itemAfter = self::getShoppingListItem($listId, $productId);
		self::assertNotNull($itemAfter, 'Product should still be on list 1');
		self::assertSame(3, (int)$itemAfter['amount'], 'Amount should be 3 (5-2)');

		// Remove all remaining (3 units) - should delete the row
		self::$stockService->RemoveProductFromShoppingList($productId, 3, $listId);

		$itemFinal = self::getShoppingListItem($listId, $productId);
		self::assertNull($itemFinal, 'Product should be removed from list 1 when amount reaches 0');
	}
}
