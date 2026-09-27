<?php

namespace Victual\Tests\Pgsql;

use PDO;
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

		// Reset BaseService cached instances to avoid stale schema references across tests
		// (issue #533: if a cached singleton holds a database connection to the old schema,
		// it will fail with "relation ... does not exist" on the fresh schema)
		\Victual\Services\BaseService::ResetInstancesForTest();

		self::$stockService = new StockService();
	}

	private static function getShoppingListItems(int $listId, int $productId): array
	{
		$stmt = self::$db->prepare('SELECT id, shopping_list_id, product_id, amount FROM shopping_list WHERE shopping_list_id = ? AND product_id = ? ORDER BY id');
		$stmt->execute([$listId, $productId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
		$list2Items = self::getShoppingListItems($list2Id, $productId);
		self::assertCount(1, $list2Items, 'Product should be on list 2 once');
		self::assertEqualsWithDelta(5.0, (float)$list2Items[0]['amount'], 0.001, 'List 2 item should have amount 5');

		$list3Items = self::getShoppingListItems($list3Id, $productId);
		self::assertCount(1, $list3Items, 'Product should be on list 3 once');
		self::assertEqualsWithDelta(3.0, (float)$list3Items[0]['amount'], 0.001, 'List 3 item should have amount 3');

		// CRITICAL TEST: Remove 1 unit from list 3 (should result in list 3 having amount 2)
		self::$stockService->RemoveProductFromShoppingList($productId, 1, $list3Id);

		// Assert list 3 was modified correctly (amount reduced from 3 to 2)
		$list3ItemsAfter = self::getShoppingListItems($list3Id, $productId);
		self::assertCount(1, $list3ItemsAfter, 'Product should still be on list 3 after partial removal');
		self::assertEqualsWithDelta(2.0, (float)$list3ItemsAfter[0]['amount'], 0.001, 'List 3 item should have amount 2 (3-1)');

		// CRITICAL ASSERTION: List 2 should be completely unchanged
		$list2ItemsAfter = self::getShoppingListItems($list2Id, $productId);
		self::assertCount(1, $list2ItemsAfter, 'Product should still be on list 2 (unchanged)');
		self::assertEqualsWithDelta(5.0, (float)$list2ItemsAfter[0]['amount'], 0.001, 'List 2 item MUST still have amount 5 (this fails with the bug)');
	}

	/**
	 * Test that when a product is not on a specific list, removal returns gracefully
	 * without modifying any list. Product is added to list B (list 2), then removal is
	 * attempted on list A (list 1).
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

		// List B: where the product IS
		$listB = 2;
		// List A: where the product is NOT
		$listA = 1;

		// Ensure list B exists
		$stmt = self::$db->prepare('SELECT COUNT(*) FROM shopping_lists WHERE id = ?');
		$stmt->execute([$listB]);
		if ((int)$stmt->fetchColumn() === 0) {
			self::$db->exec("INSERT INTO shopping_lists(id, name) VALUES (2, 'List B')");
		}

		// Add the product to list B only (with amount 3)
		self::$stockService->AddProductToShoppingList($productId, 3, 2, null, $listB);

		// Verify it's on list B
		$listBItemsBefore = self::getShoppingListItems($listB, $productId);
		self::assertCount(1, $listBItemsBefore, 'Product should be on list B');
		self::assertEqualsWithDelta(3.0, (float)$listBItemsBefore[0]['amount'], 0.001, 'List B item should have amount 3');

		// (a) Try to remove the product from list A through the service (it's not there)
		// This should not throw an exception and should not modify any state
		try {
			self::$stockService->RemoveProductFromShoppingList($productId, 1, $listA);
			// Expected: operation completes gracefully
		} catch (\Exception $e) {
			self::fail("RemoveProductFromShoppingList should return gracefully when product is not on the list, but threw: " . $e->getMessage());
		}

		// Verify no entry was created on list A
		$listAItems = self::getShoppingListItems($listA, $productId);
		self::assertCount(0, $listAItems, 'No entry should exist for a product not on list A');

		// Verify list B is completely unchanged
		$listBItemsAfter = self::getShoppingListItems($listB, $productId);
		self::assertCount(1, $listBItemsAfter, 'Product should still be on list B (unchanged)');
		self::assertSame($listBItemsBefore[0]['id'], $listBItemsAfter[0]['id'], 'List B row id must be unchanged');
		self::assertEqualsWithDelta(3.0, (float)$listBItemsAfter[0]['amount'], 0.001, 'List B item amount must be unchanged (still 3)');
	}

	/**
	 * Control test: verify normal single-list removal works correctly.
	 */
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

		$itemsBefore = self::getShoppingListItems($listId, $productId);
		self::assertCount(1, $itemsBefore, 'Product should be on list 1');
		self::assertEqualsWithDelta(5.0, (float)$itemsBefore[0]['amount'], 0.001, 'Initial amount should be 5');

		// Remove 2 units
		self::$stockService->RemoveProductFromShoppingList($productId, 2, $listId);

		$itemsAfter = self::getShoppingListItems($listId, $productId);
		self::assertCount(1, $itemsAfter, 'Product should still be on list 1');
		self::assertEqualsWithDelta(3.0, (float)$itemsAfter[0]['amount'], 0.001, 'Amount should be 3 (5-2)');

		// Remove all remaining (3 units) - should delete the row
		self::$stockService->RemoveProductFromShoppingList($productId, 3, $listId);

		$itemsFinal = self::getShoppingListItems($listId, $productId);
		self::assertCount(0, $itemsFinal, 'Product should be removed from list 1 when amount reaches 0');
	}
}
