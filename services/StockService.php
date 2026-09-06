<?php

namespace Victual\Services;

use Victual\Helpers\Grocycode;
use Victual\Helpers\WebhookRunner;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Storage\FileStorage;
use GuzzleHttp\Client;

/**
 * Core domain service for all stock operations (purchases, consumption, transfers,
 * inventory corrections, open/freeze handling, shopping lists, barcode lookups and price history).
 *
 * Concepts:
 * - Stock entries: rows in the `stock` table, one per batch/lot of a product currently in stock.
 *   Each carries its own amount (always in the product's *stock* quantity unit), due/best before date,
 *   purchased date, price (per stock quantity unit), location and open state. A batch is identified
 *   by its `stock_id` (a uniqid string, not the row id) - splitting an entry (partial open/transfer)
 *   creates additional rows sharing the same `stock_id`.
 * - Stock log / bookings: every stock mutation additionally writes one row ("booking") to the
 *   `stock_log` table, which is the append-only journal used for undo, price history and statistics.
 *   Negative amounts represent stock removals, positive ones additions.
 * - Transaction ids: one user-level operation (e.g. consuming an amount spread over multiple
 *   stock entries) shares a single `transaction_id` across all its bookings, so it can be undone
 *   as a whole via UndoTransaction. `correlation_id` additionally groups bookings which must be
 *   undone together (e.g. the old/new pair of a stock edit or the from/to pair of a transfer).
 * - Transaction types: the TRANSACTION_TYPE_* constants below classify each booking and determine
 *   how UndoBooking reverses it.
 */
class StockService extends BaseService
{
	/** Stock removal by consuming (eating/using/spoiling) - negative amount booking */
	const TRANSACTION_TYPE_CONSUME = 'consume';

	/** Manual adjustment to a counted total via InventoryProduct - amount sign depends on the direction of the correction */
	const TRANSACTION_TYPE_INVENTORY_CORRECTION = 'inventory-correction';

	/** A stock entry (or part of it) was marked as opened - does not change the stock amount */
	const TRANSACTION_TYPE_PRODUCT_OPENED = 'product-opened';

	/** Stock addition through a purchase - positive amount booking */
	const TRANSACTION_TYPE_PURCHASE = 'purchase';

	/** Stock addition produced by a recipe/self production (booked like a purchase) */
	const TRANSACTION_TYPE_SELF_PRODUCTION = 'self-production';

	/** Snapshot of a stock entry *after* it was edited via EditStockEntry (correlated with the _OLD booking) */
	const TRANSACTION_TYPE_STOCK_EDIT_NEW = 'stock-edit-new';

	/** Snapshot of a stock entry *before* it was edited via EditStockEntry (used to restore it on undo) */
	const TRANSACTION_TYPE_STOCK_EDIT_OLD = 'stock-edit-old';

	/** Transfer between locations: removal side at the source location (negative amount, correlated with _TO) */
	const TRANSACTION_TYPE_TRANSFER_FROM = 'transfer_from';

	/** Transfer between locations: addition side at the destination location (positive amount, correlated with _FROM) */
	const TRANSACTION_TYPE_TRANSFER_TO = 'transfer_to';

	/**
	 * Adds all products which are below their minimum stock amount to the given shopping list.
	 *
	 * Amounts are in the product's stock quantity unit (rounded to 2 decimals); an already existing
	 * list entry for the product (regardless of which list it is on) is raised to the missing amount
	 * (never lowered) and moved to $listId, otherwise a new entry with the product's purchase
	 * quantity unit is created.
	 *
	 * @param int $listId Target shopping list id
	 * @return void
	 * @throws \Exception When the shopping list does not exist
	 */
	public function AddMissingProductsToShoppingList($listId = 1)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		$missingProducts = $this->GetMissingProducts();
		foreach ($missingProducts as $missingProduct)
		{
			$product = $this->DB->products()->where('id', $missingProduct->id)->fetch();
			$amountToAdd = round($missingProduct->amount_missing, 2);

			$alreadyExistingEntry = $this->DB->shopping_list()->where('product_id', $missingProduct->id)->fetch();
			if ($alreadyExistingEntry)
			{
				// Update
				if ($alreadyExistingEntry->amount < $amountToAdd)
				{
					$alreadyExistingEntry->update([
						'amount' => $amountToAdd,
						'shopping_list_id' => $listId
					]);
				}
			}
			else
			{
				// Insert
				$shoppinglistRow = $this->DB->shopping_list()->createRow([
					'product_id' => $missingProduct->id,
					'amount' => $amountToAdd,
					'shopping_list_id' => $listId,
					'qu_id' => $product->qu_id_purchase
				]);
				$shoppinglistRow->save();
			}
		}
	}

	/**
	 * Adds all currently overdue products (due date before today) to the given shopping list,
	 * with a fixed amount of 1 in the product's purchase quantity unit.
	 * Products which already have an entry on any shopping list are skipped.
	 *
	 * @param int $listId Target shopping list id
	 * @return void
	 * @throws \Exception When the shopping list does not exist
	 */
	public function AddOverdueProductsToShoppingList($listId = 1)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		$overdueProducts = $this->GetDueProducts(-1);
		foreach ($overdueProducts as $overdueProduct)
		{
			$product = $this->DB->products()->where('id', $overdueProduct->product_id)->fetch();

			$alreadyExistingEntry = $this->DB->shopping_list()->where('product_id', $overdueProduct->product_id)->fetch();
			if (!$alreadyExistingEntry)
			{
				$shoppinglistRow = $this->DB->shopping_list()->createRow([
					'product_id' => $overdueProduct->product_id,
					'amount' => 1,
					'shopping_list_id' => $listId,
					'qu_id' => $product->qu_id_purchase
				]);
				$shoppinglistRow->save();
			}
		}
	}

	/**
	 * Adds all currently expired products (due date before today and due type "expiration") to the
	 * given shopping list, with a fixed amount of 1 in the product's purchase quantity unit.
	 * Products which already have an entry on any shopping list are skipped.
	 *
	 * @param int $listId Target shopping list id
	 * @return void
	 * @throws \Exception When the shopping list does not exist
	 */
	public function AddExpiredProductsToShoppingList($listId = 1)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		$expiredProducts = $this->GetExpiredProducts();
		foreach ($expiredProducts as $expiredProduct)
		{
			$product = $this->DB->products()->where('id', $expiredProduct->product_id)->fetch();

			$alreadyExistingEntry = $this->DB->shopping_list()->where('product_id', $expiredProduct->product_id)->fetch();
			if (!$alreadyExistingEntry)
			{
				$shoppinglistRow = $this->DB->shopping_list()->createRow([
					'product_id' => $expiredProduct->product_id,
					'amount' => 1,
					'shopping_list_id' => $listId,
					'qu_id' => $product->qu_id_purchase
				]);
				$shoppinglistRow->save();
			}
		}
	}

	/**
	 * Adds the given amount of a product to stock (purchase, positive inventory correction or self production).
	 *
	 * Writes one new stock entry plus one corresponding stock_log booking - or, with $stockLabelType = 2,
	 * one entry/booking pair with amount 1 per unit (each with its own stock_id) so every unit gets its own label.
	 * Depending on the label type and the label printer feature flags, label printing webhooks are triggered.
	 * Afterwards CompactStockEntries() merges equal stock entries of this product.
	 *
	 * For tare weight handled products $amount is the new gross total (scale reading incl. container weight);
	 * the actually booked amount is $amount - current stock amount - tare weight. With $addExactAmount = true
	 * the given amount is booked as-is instead.
	 *
	 * @param int $productId
	 * @param float $amount Amount in the product's stock quantity unit (gross total for tare weight handled products, see above)
	 * @param string|null $bestBeforeDate Due date as Y-m-d; null derives it from the product's default due days
	 *                                    (or the after-freezing default when added to a freezer location);
	 *                                    -1 default days map to the "never expires" date 2999-12-31
	 * @param string $transactionType One of TRANSACTION_TYPE_PURCHASE, _INVENTORY_CORRECTION or _SELF_PRODUCTION
	 * @param string|null $purchasedDate Purchased date as Y-m-d
	 * @param float|null $price Price per stock quantity unit
	 * @param int|null $locationId Destination location; null means the product's default location
	 * @param int|null $shoppingLocationId Store where the product was bought
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @param int $stockLabelType 0 = no label, 1 = one label for the whole booking, 2 = one label (and stock entry) per unit
	 * @param bool $addExactAmount Only relevant for tare weight handled products, see above
	 * @param string|null $note Free text note stored on the stock entry and booking
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product or location does not exist, $amount <= 0, the gross amount is
	 *                    not above tare weight + current stock, or $transactionType is not valid here
	 */
	public function AddProduct(int $productId, float $amount, $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId = null, $shoppingLocationId = null, &$transactionId = null, $stockLabelType = 0, $addExactAmount = false, $note = null)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}


		if ($amount <= 0)
		{
			throw new \Exception('Amount can\'t be <= 0');
		}

		$productDetails = (object)$this->GetProductDetails($productId);

		// Tare weight handling
		// The given amount is the new total amount including the container weight (gross)
		// The amount to be posted needs to be the given amount - stock amount - tare weight
		if ($productDetails->product->enable_tare_weight_handling == 1)
		{
			if ($addExactAmount)
			{
				$amount = $productDetails->stock_amount + $productDetails->product->tare_weight + $amount;
			}

			if ($amount <= $productDetails->product->tare_weight + $productDetails->stock_amount)
			{
				throw new \Exception('The amount cannot be lower or equal than the defined tare weight + current stock amount');
			}

			$amount = $amount - $productDetails->stock_amount - $productDetails->product->tare_weight;
		}

		//Set the default due date, if none is supplied
		if ($bestBeforeDate == null)
		{
			if ($locationId !== null && !$this->LocationExists($locationId))
			{
				throw new \Exception('Location does not exist');
			}
			else
			{
				$location = $this->DB->locations()->where('id', $locationId)->fetch();
			}

			if (VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING && $locationId !== null && $location->is_freezer == 1 && $productDetails->product->default_best_before_days_after_freezing >= -1)
			{
				if ($productDetails->product->default_best_before_days_after_freezing == -1)
				{
					$bestBeforeDate = date('2999-12-31');
				}
				else
				{
					$bestBeforeDate = date('Y-m-d', strtotime('+' . $productDetails->product->default_best_before_days_after_freezing . ' days'));
				}
			}
			elseif ($productDetails->product->default_best_before_days == -1)
			{
				$bestBeforeDate = date('2999-12-31');
			}
			elseif ($productDetails->product->default_best_before_days > 0)
			{
				$bestBeforeDate = date('Y-m-d', strtotime(date('Y-m-d') . ' + ' . $productDetails->product->default_best_before_days . ' days'));
			}
			else
			{
				$bestBeforeDate = date('Y-m-d');
			}
		}

		if ($transactionType === self::TRANSACTION_TYPE_PURCHASE || $transactionType === self::TRANSACTION_TYPE_INVENTORY_CORRECTION || $transactionType == self::TRANSACTION_TYPE_SELF_PRODUCTION)
		{
			if ($transactionId === null)
			{
				$transactionId = uniqid();
			}

			$labelWebhookPayloads = [];

			// The booking, the stock entry it describes and the compacting that may immediately
			// rewrite both belong to one addition and have to land as one.
			DatabaseService::GetInstance()->InTransaction(function () use ($productId, $amount, $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId, $shoppingLocationId, $stockLabelType, $note, $productDetails, &$transactionId, &$labelWebhookPayloads)
			{
				if ($stockLabelType == 2)
				{
					// Label per unit => single stock entry per unit

					for ($i = 1; $i <= $amount; $i++)
					{
						// The "x" prefix marks per-unit labeled entries - the stock_splits view excludes
						// them, so CompactStockEntries() will never merge these entries back together
						$stockId = uniqid('x');
						$logRow = $this->DB->stock_log()->createRow([
							'product_id' => $productId,
							'amount' => 1,
							'best_before_date' => $bestBeforeDate,
							'purchased_date' => $purchasedDate,
							'stock_id' => $stockId,
							'transaction_type' => $transactionType,
							'price' => $price,
							'location_id' => $locationId,
							'transaction_id' => $transactionId,
							'shopping_location_id' => $shoppingLocationId,
							'user_id' => VICTUAL_USER_ID,
							'note' => $note
						]);
						$logRow->save();

						$stockRow = $this->DB->stock()->createRow([
							'product_id' => $productId,
							'amount' => 1,
							'best_before_date' => $bestBeforeDate,
							'purchased_date' => $purchasedDate,
							'stock_id' => $stockId,
							'price' => $price,
							'location_id' => $locationId,
							'shopping_location_id' => $shoppingLocationId,
							'note' => $note
						]);
						$stockRow->save();

						if (VICTUAL_FEATURE_FLAG_LABEL_PRINTER && VICTUAL_LABEL_PRINTER_RUN_SERVER)
						{
							$webhookData = array_merge([
								'product' => $productDetails->product->name,
								'grocycode' => (string)(new Grocycode(Grocycode::PRODUCT, $productId, [$stockId])),
								'details' => $productDetails,
								'stock_entry' => $stockRow,
							], VICTUAL_LABEL_PRINTER_PARAMS);

							if (VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
							{
								$webhookData['due_date'] = LocalizationService::GetInstance()->__t('DD') . ': ' . $bestBeforeDate;
							}

							// Built here from the values in hand so the label describes the entry as it
							// was booked; only the firing waits until after the commit.
							$labelWebhookPayloads[] = $webhookData;
						}
					}
				}
				else
				{
					// No or single label => one stock entry

					$stockId = uniqid();
					$logRow = $this->DB->stock_log()->createRow([
						'product_id' => $productId,
						'amount' => $amount,
						'best_before_date' => $bestBeforeDate,
						'purchased_date' => $purchasedDate,
						'stock_id' => $stockId,
						'transaction_type' => $transactionType,
						'price' => $price,
						'location_id' => $locationId,
						'transaction_id' => $transactionId,
						'shopping_location_id' => $shoppingLocationId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $note
					]);
					$logRow->save();

					$stockRow = $this->DB->stock()->createRow([
						'product_id' => $productId,
						'amount' => $amount,
						'best_before_date' => $bestBeforeDate,
						'purchased_date' => $purchasedDate,
						'stock_id' => $stockId,
						'price' => $price,
						'location_id' => $locationId,
						'shopping_location_id' => $shoppingLocationId,
						'note' => $note
					]);
					$stockRow->save();

					if ($stockLabelType == 1 && VICTUAL_FEATURE_FLAG_LABEL_PRINTER && VICTUAL_LABEL_PRINTER_RUN_SERVER)
					{
						$webhookData = array_merge([
							'product' => $productDetails->product->name,
							'grocycode' => (string)(new Grocycode(Grocycode::PRODUCT, $productId, [$stockId])),
							'details' => $productDetails,
							'stock_entry' => $stockRow,
						], VICTUAL_LABEL_PRINTER_PARAMS);

						if (VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
						{
							$webhookData['due_date'] = LocalizationService::GetInstance()->__t('DD') . ': ' . $bestBeforeDate;
						}

						// Built here from the values in hand so the label describes the entry as it was
						// booked; only the firing waits until after the commit.
						$labelWebhookPayloads[] = $webhookData;
					}
				}

				$this->CompactStockEntries($productId);

				// Inside the transaction on purpose: the outbox row and the ledger rows
				// commit together or not at all, so a rolled back booking leaves no event
				// behind and a crash after the commit still delivers one.
				BookingEventPublisher::RecordTransaction($transactionId);
			});

			// After the commit: a printed label should mean the stock entry exists, and a printer
			// call with a 2 s timeout has no business holding a write lock open.
			foreach ($labelWebhookPayloads as $webhookData)
			{
				$runner = new WebhookRunner();
				$runner->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
			}

			return $transactionId;
		}
		else
		{
			throw new \Exception("Transaction type $transactionType is not valid (StockService.AddProduct)");
		}
	}

	/**
	 * Adds a product to a shopping list, or increases the amount of the product's
	 * already existing entry on that list (replacing its note).
	 *
	 * @param int $productId
	 * @param float $amount Amount in the quantity unit given by $quId
	 * @param int $quId Quantity unit id; -1 means the product's default purchase quantity unit
	 * @param string|null $note
	 * @param int $listId Target shopping list id
	 * @return void
	 * @throws \Exception When the shopping list or product does not exist
	 */
	public function AddProductToShoppingList($productId, $amount = 1, $quId = -1, $note = null, $listId = 1)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		if ($quId == -1)
		{
			$quId = $this->DB->products($productId)->qu_id_purchase;
		}

		$alreadyExistingEntry = $this->DB->shopping_list()->where('product_id = :1 AND shopping_list_id = :2', $productId, $listId)->fetch();
		if ($alreadyExistingEntry)
		{
			// Update
			$alreadyExistingEntry->update([
				'amount' => ($alreadyExistingEntry->amount + $amount),
				'shopping_list_id' => $listId,
				'note' => $note
			]);
		}
		else
		{
			// Insert
			$shoppinglistRow = $this->DB->shopping_list()->createRow([
				'product_id' => $productId,
				'amount' => $amount,
				'qu_id' => $quId,
				'shopping_list_id' => $listId,
				'note' => $note
			]);
			$shoppinglistRow->save();
		}
	}

	/**
	 * Deletes all entries of a shopping list, or only the ones marked as done.
	 *
	 * @param int $listId
	 * @param bool $doneOnly When true, only entries with done = 1 are removed
	 * @return void
	 * @throws \Exception When the shopping list does not exist
	 */
	public function ClearShoppingList($listId = 1, $doneOnly = false)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		if ($doneOnly)
		{
			$this->DB->shopping_list()->where('shopping_list_id = :1 AND COALESCE(done, 0) = 1', $listId)->delete();
		}
		else
		{
			$this->DB->shopping_list()->where('shopping_list_id = :1', $listId)->delete();
		}
	}

	/**
	 * Removes the given amount of a product from stock (consume or negative inventory correction).
	 *
	 * The amount is taken from the stock entries in default consume order (entries at the default
	 * consume location first, then opened first, then first due first, then first in first out);
	 * fully used entries are deleted, a partially used entry keeps the rest amount. One negative
	 * stock_log booking is written per touched stock entry, all sharing the same transaction id.
	 * When the user setting "shopping_list_auto_add_below_min_stock_amount" is enabled, missing
	 * products are added to the configured shopping list afterwards.
	 *
	 * For tare weight handled products $amount is the new gross total (scale reading incl. container
	 * weight); the actually booked amount is |$amount - stock amount - tare weight|. With
	 * $consumeExactAmount = true the given amount is consumed as-is instead.
	 *
	 * With $allowSubproductSubstitution, stock of sub products (products_resolved) may be used;
	 * amounts are then converted to the sub product's stock quantity unit via QU conversions and
	 * back for any remainder.
	 *
	 * @param int $productId
	 * @param float $amount Amount in the product's stock quantity unit (gross total for tare weight handled products, see above)
	 * @param bool $spoiled Whether the consumed amount was spoiled (tracked in the booking for the spoil rate statistic)
	 * @param string $transactionType One of TRANSACTION_TYPE_CONSUME or _INVENTORY_CORRECTION
	 * @param string $specificStockEntryId 'default' consumes in default order; otherwise a stock_id restricting consumption to that single stock entry
	 * @param int|null $recipeId When consuming due to a recipe, its id (stored in the bookings)
	 * @param int|null $locationId When given, only stock at this location is consumed
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @param bool $allowSubproductSubstitution See above
	 * @param bool $consumeExactAmount Only relevant for tare weight handled products, see above
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product or location does not exist, $amount <= 0, the amount exceeds
	 *                    the current (aggregated) stock amount, or $transactionType is not valid here
	 */
	public function ConsumeProduct(int $productId, float $amount, bool $spoiled, $transactionType, $specificStockEntryId = 'default', $recipeId = null, $locationId = null, &$transactionId = null, $allowSubproductSubstitution = false, $consumeExactAmount = false)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		if ($amount <= 0)
		{
			throw new \Exception('Amount can\'t be <= 0');
		}

		if ($locationId !== null && !$this->LocationExists($locationId))
		{
			throw new \Exception('Location does not exist');
		}

		$productDetails = (object)$this->GetProductDetails($productId);

		// Tare weight handling
		// The given amount is the new total amount including the container weight (gross)
		// The amount to be posted needs to be the absolute value of the given amount - stock amount - tare weight
		if ($productDetails->product->enable_tare_weight_handling == 1)
		{
			if ($consumeExactAmount)
			{
				$amount = $productDetails->stock_amount + $productDetails->product->tare_weight - $amount;
			}
			if ($amount < $productDetails->product->tare_weight)
			{
				throw new \Exception('The amount cannot be lower than the defined tare weight');
			}

			$amount = abs($amount - $productDetails->stock_amount - $productDetails->product->tare_weight);
		}

		if ($transactionType === self::TRANSACTION_TYPE_CONSUME || $transactionType === self::TRANSACTION_TYPE_INVENTORY_CORRECTION)
		{
			if ($locationId === null)
			{
				// Consume from any location
				$potentialStockEntries = $this->GetProductStockEntries($productId, false, $allowSubproductSubstitution);
			}
			else
			{
				// Consume only from the supplied location
				$potentialStockEntries = $this->GetProductStockEntriesForLocation($productId, $locationId, false, $allowSubproductSubstitution);
			}

			if ($specificStockEntryId !== 'default')
			{
				$potentialStockEntries = FindAllObjectsInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
			}

			$productStockAmount = $productDetails->stock_amount_aggregated;
			if (round($amount, 2) > round($productStockAmount, 2))
			{
				throw new \Exception('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)');
			}

			if ($transactionId === null)
			{
				$transactionId = uniqid();
			}

			// One booking per touched stock entry, each paired with a delete or an amount
			// update - so `stock` and `stock_log` can only ever agree if all of them land.
			DatabaseService::GetInstance()->InTransaction(function () use ($potentialStockEntries, $amount, $productId, $spoiled, $transactionType, $recipeId, $allowSubproductSubstitution, $productDetails, &$transactionId)
			{
				foreach ($potentialStockEntries as $stockEntry)
				{
					if ($amount == 0)
					{
						break;
					}

					if ($allowSubproductSubstitution && $stockEntry->product_id != $productId)
					{
						// A sub product will be used -> use QU conversions
						$subProduct = $this->DB->products($stockEntry->product_id);
						$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $stockEntry->product_id, $productDetails->product->qu_id_stock, $subProduct->qu_id_stock)->fetch();
						if ($conversion != null)
						{
							$amount = $amount * $conversion->factor;
						}
					}

					if ($amount >= $stockEntry->amount)
					{
						// Take the whole stock entry
						$logRow = $this->DB->stock_log()->createRow([
							'product_id' => $stockEntry->product_id,
							'amount' => $stockEntry->amount * -1,
							'best_before_date' => $stockEntry->best_before_date,
							'purchased_date' => $stockEntry->purchased_date,
							'used_date' => date('Y-m-d'),
							'spoiled' => $spoiled,
							'stock_id' => $stockEntry->stock_id,
							'transaction_type' => $transactionType,
							'price' => $stockEntry->price,
							'opened_date' => $stockEntry->opened_date,
							'recipe_id' => $recipeId,
							'transaction_id' => $transactionId,
							'user_id' => VICTUAL_USER_ID,
							'location_id' => $stockEntry->location_id,
							'note' => $stockEntry->note,
							'shopping_location_id' => $stockEntry->shopping_location_id
						]);
						$logRow->save();

						$stockEntry->delete();

						$amount -= $stockEntry->amount;

						if ($allowSubproductSubstitution && $stockEntry->product_id != $productId && $conversion != null)
						{
							// A sub product with QU conversions was used
							// => Convert the rest amount back to be based on the original (parent) product for the next round
							$amount = $amount / $conversion->factor;
						}
					}
					else
					{
						// Stock entry amount is > than needed amount -> split the stock entry resp. update the amount
						$restStockAmount = $stockEntry->amount - $amount;

						$logRow = $this->DB->stock_log()->createRow([
							'product_id' => $stockEntry->product_id,
							'amount' => $amount * -1,
							'best_before_date' => $stockEntry->best_before_date,
							'purchased_date' => $stockEntry->purchased_date,
							'used_date' => date('Y-m-d'),
							'spoiled' => $spoiled,
							'stock_id' => $stockEntry->stock_id,
							'transaction_type' => $transactionType,
							'price' => $stockEntry->price,
							'opened_date' => $stockEntry->opened_date,
							'recipe_id' => $recipeId,
							'transaction_id' => $transactionId,
							'user_id' => VICTUAL_USER_ID,
							'location_id' => $stockEntry->location_id,
							'note' => $stockEntry->note,
							'shopping_location_id' => $stockEntry->shopping_location_id
						]);
						$logRow->save();

						$stockEntry->update([
							'amount' => $restStockAmount
						]);

						$amount = 0;
					}
				}

				if (boolval(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
				{
					$this->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
				}

				// Inside the transaction on purpose: the outbox row and the ledger rows
				// commit together or not at all, so a rolled back booking leaves no event
				// behind and a crash after the commit still delivers one.
				BookingEventPublisher::RecordTransaction($transactionId);
			});

			return $transactionId;
		}
		else
		{
			throw new \Exception("Transaction type $transactionType is not valid (StockService.ConsumeProduct)");
		}
	}

	/**
	 * Edits a single stock entry in place (amount, dates, price, location, open state, note).
	 *
	 * Writes a correlated pair of stock_log bookings: a TRANSACTION_TYPE_STOCK_EDIT_OLD snapshot of
	 * the entry before the change and a TRANSACTION_TYPE_STOCK_EDIT_NEW snapshot after it (linked by
	 * a shared correlation id, so an undo restores the old state). Afterwards CompactStockEntries()
	 * merges equal stock entries of the product.
	 *
	 * @param int $stockRowId The `stock` table row id (not the stock_id)
	 * @param float $amount New amount in the product's stock quantity unit
	 * @param string|null $bestBeforeDate New due date as Y-m-d
	 * @param int|null $locationId
	 * @param int|null $shoppingLocationId
	 * @param float|null $price Price per stock quantity unit
	 * @param bool $open New open state; opening sets opened_date to today (kept when already opened), un-opening clears it
	 * @param string|null $purchasedDate New purchased date as Y-m-d
	 * @param string|null $note
	 * @return string The transaction id of the booking pair
	 * @throws \Exception When the stock entry does not exist
	 */
	public function EditStockEntry(int $stockRowId, float $amount, $bestBeforeDate, $locationId, $shoppingLocationId, $price, $open, $purchasedDate, $note = null)
	{
		$stockRow = $this->DB->stock()->where('id = :1', $stockRowId)->fetch();
		if ($stockRow === null)
		{
			throw new \Exception('Stock does not exist');
		}

		$correlationId = uniqid();
		$transactionId = uniqid();

		// An eighth transactional entrypoint, added by plan 18's review rather than by
		// plan 13, which wrapped the seven stock *booking* paths and left this one out.
		// The reason it belongs here is the same one 13 gives for those: this method writes
		// a correlated pair of stock_log rows and mutates the stock row between them, so a
		// failure part way leaves a booking pair whose halves disagree. Plan 18 forced the
		// question by adding a ninth write - the outbox event - that has to commit with the
		// rest or not at all.
		DatabaseService::GetInstance()->InTransaction(function () use (
			$stockRow, $correlationId, $transactionId, $amount, $bestBeforeDate, $locationId,
			$shoppingLocationId, $price, $open, $purchasedDate, $note
		)
		{
			$logOldRowForStockUpdate = $this->DB->stock_log()->createRow([
				'product_id' => $stockRow->product_id,
				'amount' => $stockRow->amount,
				'best_before_date' => $stockRow->best_before_date,
				'purchased_date' => $stockRow->purchased_date,
				'stock_id' => $stockRow->stock_id,
				'transaction_type' => self::TRANSACTION_TYPE_STOCK_EDIT_OLD,
				'price' => $stockRow->price,
				'opened_date' => $stockRow->opened_date,
				'location_id' => $stockRow->location_id,
				'shopping_location_id' => $stockRow->shopping_location_id,
				'correlation_id' => $correlationId,
				'transaction_id' => $transactionId,
				'stock_row_id' => $stockRow->id,
				'user_id' => VICTUAL_USER_ID,
				'note' => $stockRow->note
			]);
			$logOldRowForStockUpdate->save();

			$openedDate = $stockRow->opened_date;
			if (boolval($open) && $openedDate == null)
			{
				$openedDate = date('Y-m-d');
			}
			elseif (!boolval($open))
			{
				$openedDate = null;
			}

			$stockRow->update([
				'amount' => $amount,
				'price' => $price,
				'best_before_date' => $bestBeforeDate,
				'location_id' => $locationId,
				'shopping_location_id' => $shoppingLocationId,
				'opened_date' => $openedDate,
				'open' => BoolToInt($open),
				'purchased_date' => $purchasedDate,
				'note' => $note
			]);

			$logNewRowForStockUpdate = $this->DB->stock_log()->createRow([
				'product_id' => $stockRow->product_id,
				'amount' => $amount,
				'best_before_date' => $bestBeforeDate,
				'purchased_date' => $stockRow->purchased_date,
				'stock_id' => $stockRow->stock_id,
				'transaction_type' => self::TRANSACTION_TYPE_STOCK_EDIT_NEW,
				'price' => $price,
				'opened_date' => $stockRow->opened_date,
				'location_id' => $locationId,
				'shopping_location_id' => $shoppingLocationId,
				'correlation_id' => $correlationId,
				'transaction_id' => $transactionId,
				'stock_row_id' => $stockRow->id,
				'user_id' => VICTUAL_USER_ID,
				'note' => $stockRow->note
			]);
			$logNewRowForStockUpdate->save();

			$this->CompactStockEntries($stockRow->product_id);

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back edit leaves no event behind and a
			// crash after the commit still delivers one.
			BookingEventPublisher::RecordTransaction($transactionId);
		});

		return $transactionId;
	}

	/**
	 * Returns the PLUGIN_NAME of the configured external barcode lookup plugin.
	 *
	 * @return string
	 * @throws \Exception When no plugin is configured or it cannot be loaded
	 */
	public function GetExternalBarcodeLookupPluginName()
	{
		$plugin = $this->LoadExternalBarcodeLookupPlugin();
		return $plugin::PLUGIN_NAME;
	}

	/**
	 * Looks up a barcode via the configured external barcode lookup plugin.
	 *
	 * With $addFoundProduct = true the found product is also created in the database, including
	 * its barcode, an optional purchase-to-stock quantity unit conversion and an optionally
	 * downloaded product picture (from a http(s) or data: image URL; download errors are ignored);
	 * the new product id is then included in the returned data as 'id'.
	 *
	 * @param string $barcode
	 * @param bool $addFoundProduct
	 * @return array|null The plugin's product data array, or null when the lookup found nothing
	 * @throws \Exception When no plugin is configured, it cannot be loaded, or (when adding)
	 *                    a product with the found name already exists
	 */
	public function ExternalBarcodeLookup($barcode, $addFoundProduct)
	{
		$plugin = $this->LoadExternalBarcodeLookupPlugin();
		$pluginOutput = $plugin->Lookup($barcode);

		if ($pluginOutput !== null)
		{
			// Lookup was successful
			if ($addFoundProduct === true)
			{
				if ($this->DB->products()->where('name = :1', $pluginOutput['name'])->fetch() !== null)
				{
					throw new \Exception('Product "' . $pluginOutput['name'] . '" already exists');
				}

				// Add product to database and include new product id in output
				$productData = $pluginOutput;
				unset($productData['__barcode'], $productData['__qu_factor_purchase_to_stock'], $productData['__image_url']); // Virtual lookup plugin properties

				// Download and save image if provided
				if (isset($pluginOutput['__image_url']) && !empty($pluginOutput['__image_url']))
				{
					try
					{
						if (preg_match('/^https?:\/\//', $pluginOutput['__image_url']))
						{
							$webClient = new Client();
							$response = $webClient->request('GET', $pluginOutput['__image_url'], ['headers' => ['User-Agent' => 'Victual/' . ApplicationService::GetInstance()->GetInstalledVersion()->Version . ' (https://github.com/datagen24/victual)']]);
							$fileExtension = pathinfo(parse_url($pluginOutput['__image_url'], PHP_URL_PATH), PATHINFO_EXTENSION);

							// Fallback to Content-Type header if file extension is missing
							if (strlen($fileExtension) == 0 && $response->hasHeader('Content-Type'))
							{
								$fileExtension = explode('+', explode('/', $response->getHeader('Content-Type')[0])[1])[0];
							}

							$imageData = $response->getBody();
						}
						elseif (preg_match('/data:image\/(\w+?);base64,([A-Za-z0-9+\/]*={0,2})$/', $pluginOutput['__image_url'], $matches))
						{
							$fileExtension = $matches[1];
							if (!($imageData = base64_decode($matches[2])))
							{
								unset($imageData);
							}
						}

						if (!empty($fileExtension) && !empty($imageData))
						{
							$fileName = $pluginOutput['__barcode'] . '.' . $fileExtension;
							FileStorage::GetInstance()->Write('productpictures', $fileName, (string)$imageData);
							$productData['picture_file_name'] = $fileName;
						}
					}
					catch (\Exception)
					{
						// Ignore
					}
				}

				$newProductRow = $this->DB->products()->createRow($productData);
				$newProductRow->save();

				$this->DB->product_barcodes()->createRow([
					'product_id' => $newProductRow->id,
					'barcode' => $pluginOutput['__barcode']
				])->save();

				if ($pluginOutput['qu_id_stock'] != $pluginOutput['qu_id_purchase'])
				{
					$this->DB->quantity_unit_conversions()->createRow([
						'product_id' => $newProductRow->id,
						'from_qu_id' => $pluginOutput['qu_id_purchase'],
						'to_qu_id' => $pluginOutput['qu_id_stock'],
						'factor' => $pluginOutput['__qu_factor_purchase_to_stock'],
					])->save();
				}

				$pluginOutput['id'] = $newProductRow->id;
			}
		}

		return $pluginOutput;
	}

	/**
	 * Returns the current stock overview (one row per product in stock) from the
	 * stock_current view, with the full product row attached as ->product.
	 *
	 * Amounts are in stock quantity units; aggregated columns (amount_aggregated etc.)
	 * include the stock of resolved sub products.
	 *
	 * @param string $customWhere Optional raw SQL appended to the query (e.g. a WHERE clause) - must be trusted input
	 * @param array $customWhereParams Values for the positional "?" placeholders in $customWhere.
	 *              Anything engine specific (date arithmetic above all) belongs here rather than
	 *              in $customWhere, which has to stay portable across SQLite and PostgreSQL.
	 * @return array Array of stock_current row objects
	 */
	public function GetCurrentStock($customWhere = '', array $customWhereParams = [])
	{
		$sql = 'SELECT * FROM stock_current ' . $customWhere;
		$currentStockMapped = DatabaseService::GetInstance()->ExecuteDbQuery($sql, $customWhereParams)->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_OBJ);
		$relevantProducts = $this->DB->products()->where('id IN (SELECT product_id FROM (' . $sql . ') x)', $customWhereParams);

		foreach ($relevantProducts as $product)
		{
			$currentStockMapped[$product->id][0]->product_id = $product->id;
			$currentStockMapped[$product->id][0]->product = $product;
		}

		return array_column($currentStockMapped, 0);
	}

	/**
	 * Returns the per-location stock content (location_id, product_id, amount, amount_opened)
	 * for all active products, ordered by product name. Amounts are in stock quantity units.
	 *
	 * @param bool $includeOutOfStockProductsAtTheDefaultLocation When true, products without any stock
	 *             are also included with amount 0 at their default location (LEFT JOIN)
	 * @return array Array of row objects
	 */
	public function GetCurrentStockLocationContent($includeOutOfStockProductsAtTheDefaultLocation = false)
	{
		$leftJoin = '';
		if ($includeOutOfStockProductsAtTheDefaultLocation)
		{
			$leftJoin = 'LEFT';
		}

		$sql = 'SELECT COALESCE(sclc.location_id, p.location_id) AS location_id, p.id AS product_id, COALESCE(sclc.amount, 0) AS amount, COALESCE(sclc.amount_opened, 0) AS amount_opened FROM products p ' . $leftJoin . ' JOIN stock_current_location_content sclc ON sclc.product_id = p.id WHERE p.active = 1 ORDER BY p.name';
		return DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);
	}

	/**
	 * Returns all product/location pairs which currently have stock
	 * (rows of the stock_current_locations view).
	 *
	 * @return array Array of row objects
	 */
	public function GetCurrentStockLocations()
	{
		$sql = 'SELECT * FROM stock_current_locations';
		return DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);
	}

	/**
	 * Returns all products in stock which are due within the next $days days.
	 *
	 * @param int $days Look-ahead window in days; negative values shift the cut-off into the past
	 *                  (e.g. -1 = only products already overdue as of yesterday)
	 * @param bool $excludeOverdue When true, products with a due date before today are excluded
	 * @return array Array of stock_current row objects (see GetCurrentStock())
	 */
	public function GetDueProducts(int $days = 5, bool $excludeOverdue = false)
	{
		// The cut-off dates are computed here and bound as parameters instead of being
		// expressed in SQL: SQLite's date('now', 'N days') and its zero argument date()
		// have no PostgreSQL equivalent, so date arithmetic must not leak into the query
		// (see DatabaseDialect). Note this also makes "today" the local date on both
		// engines - SQLite's bare date() was UTC, unlike every other date in the app.
		$dueDate = date('Y-m-d', strtotime($days . ' days'));

		if ($excludeOverdue)
		{
			return $this->GetCurrentStock('WHERE best_before_date <= ? AND best_before_date >= ?', [$dueDate, date('Y-m-d')]);
		}
		else
		{
			return $this->GetCurrentStock('WHERE best_before_date <= ?', [$dueDate]);
		}
	}

	/**
	 * Returns all products in stock which are expired (due date before today
	 * and due type 2 = "expiration date", as opposed to a mere best before date).
	 *
	 * @return array Array of stock_current row objects (see GetCurrentStock())
	 */
	public function GetExpiredProducts()
	{
		// See GetDueProducts() for why the date is computed here rather than in SQL
		return $this->GetCurrentStock('WHERE best_before_date < ? AND due_type = 2', [date('Y-m-d')]);
	}

	/**
	 * Returns all products which are below their minimum stock amount
	 * (rows of the stock_missing_products view, amount_missing in stock quantity units),
	 * with the full product row attached as ->product.
	 *
	 * @return array Array of row objects
	 */
	public function GetMissingProducts()
	{
		$missingProductsResponse = DatabaseService::GetInstance()->ExecuteDbQuery('SELECT * FROM stock_missing_products')->fetchAll(\PDO::FETCH_OBJ);

		$relevantProducts = $this->DB->products()->where('id IN (SELECT id FROM stock_missing_products)');
		foreach ($relevantProducts as $product)
		{
			FindObjectInArrayByPropertyValue($missingProductsResponse, 'id', $product->id)->product = $product;
		}

		return $missingProductsResponse;
	}

	/**
	 * Returns an aggregated details array for a product: the product row itself, its barcodes,
	 * current stock amounts/value (plain and aggregated incl. sub products, all in stock quantity
	 * units), the involved quantity units, price figures (last/average/current, per stock quantity
	 * unit), last purchased/used dates, next due date, locations, shelf life statistics and
	 * QU conversion factors. Products without stock get zeroed stock figures.
	 *
	 * @param int $productId
	 * @return array See the returned array keys for the exact shape
	 * @throws \Exception When the product does not exist or is inactive
	 */
	public function GetProductDetails(int $productId)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		$stockCurrentRow = FindObjectInArrayByPropertyValue($this->GetCurrentStock(), 'product_id', $productId);
		if ($stockCurrentRow == null)
		{
			$stockCurrentRow = new \stdClass();
			$stockCurrentRow->amount = 0;
			$stockCurrentRow->value = 0;
			$stockCurrentRow->amount_opened = 0;
			$stockCurrentRow->amount_aggregated = 0;
			$stockCurrentRow->amount_opened_aggregated = 0;
			$stockCurrentRow->is_aggregated_amount = 0;
		}

		$detailsRow = $this->DB->uihelper_product_details()->where('id', $productId)->fetch();
		$product = $this->DB->products($productId);
		$productBarcodes = $this->DB->product_barcodes()->where('product_id', $productId)->fetchAll();
		$quPurchase = $this->DB->quantity_units($product->qu_id_purchase);
		$quStock = $this->DB->quantity_units($product->qu_id_stock);
		$quConsume = $this->DB->quantity_units($product->qu_id_consume);
		$quPrice = $this->DB->quantity_units($product->qu_id_price);
		$location = $this->DB->locations($product->location_id);

		$defaultConsumeLocation = null;
		if (!empty($product->default_consume_location_id))
		{
			$defaultConsumeLocation = $this->DB->locations($product->default_consume_location_id);
		}

		return [
			'product' => $product,
			'product_barcodes' => $productBarcodes,
			'last_purchased' => $detailsRow->last_purchased_date,
			'last_used' => $detailsRow->last_used_date,
			'stock_amount' => $stockCurrentRow->amount,
			'stock_value' => $stockCurrentRow->value,
			'stock_amount_opened' => $stockCurrentRow->amount_opened,
			'stock_amount_aggregated' => $stockCurrentRow->amount_aggregated,
			'stock_amount_opened_aggregated' => $stockCurrentRow->amount_opened_aggregated,
			'quantity_unit_stock' => $quStock,
			'default_quantity_unit_purchase' => $quPurchase,
			'default_quantity_unit_consume' => $quConsume,
			'quantity_unit_price' => $quPrice,
			'last_price' => $detailsRow->last_purchased_price,
			'avg_price' => $detailsRow->average_price,
			'oldest_price' => $detailsRow->current_price, // Deprecated
			'current_price' => $detailsRow->current_price,
			'last_shopping_location_id' => $detailsRow->last_purchased_shopping_location_id,
			'default_shopping_location_id' => $product->shopping_location_id,
			'next_due_date' => $detailsRow->next_due_date,
			'location' => $location,
			'average_shelf_life_days' => $detailsRow->average_shelf_life_days,
			'spoil_rate_percent' => $detailsRow->spoil_rate,
			'is_aggregated_amount' => $stockCurrentRow->is_aggregated_amount,
			'has_childs' => boolval($detailsRow->has_childs),
			'default_consume_location' => $defaultConsumeLocation,
			'qu_conversion_factor_purchase_to_stock' => $detailsRow->qu_factor_purchase_to_stock,
			'qu_conversion_factor_price_to_stock' => $detailsRow->qu_factor_price_to_stock
		];
	}

	/**
	 * Resolves a scanned barcode to a product id - either a product Grocycode
	 * or a regular barcode from product_barcodes (matched case-insensitively).
	 *
	 * @param string $barcode
	 * @return int The product id
	 * @throws \Exception When the barcode is a non-product Grocycode or no product with this barcode exists
	 */
	public function GetProductIdFromBarcode(string $barcode)
	{
		// first, try to parse this as a product Grocycode
		if (Grocycode::Validate($barcode))
		{
			$gc = new Grocycode($barcode);
			if ($gc->GetType() != Grocycode::PRODUCT)
			{
				throw new \Exception('Invalid Grocycode');
			}
			return $gc->GetId();
		}

		$potentialProduct = $this->DB->product_barcodes()->where('barcode = :1 COLLATE NOCASE', $barcode)->fetch();
		if ($potentialProduct === null)
		{
			throw new \Exception("No product with barcode $barcode found");
		}

		return $potentialProduct->product_id;
	}

	/**
	 * Returns the purchase price history of a product, newest first.
	 *
	 * @param int $productId
	 * @return array Array of ['date' => Y-m-d purchased date, 'price' => price per stock quantity unit,
	 *               'shopping_location' => shopping location row or null]
	 * @throws \Exception When the product does not exist or is inactive
	 */
	public function GetProductPriceHistory(int $productId)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		$returnData = [];
		$shoppingLocations = $this->DB->shopping_locations();

		// Ordered by the ledger row id as well as the date, for the reason migration 0261
		// gives: several bookings for one product commonly share a purchased_date, so
		// ordering by the date alone is not a total order and the rows come back in
		// whatever sequence the plan produces. That decided nothing here - this endpoint
		// returns all of them - but it made the *array order* engine-dependent on exactly
		// the days a household buys twice, which is a documented response differing for
		// no reason anybody chose.
		$rows = $this->DB->products_price_history()
			->where('product_id = :1', $productId)
			->orderBy('purchased_date', 'DESC')
			->orderBy('stock_log_id', 'DESC');
		foreach ($rows as $row)
		{
			$returnData[] = [
				'date' => $row->purchased_date,
				'price' => $row->price,
				'shopping_location' => FindObjectInArrayByPropertyValue($shoppingLocations, 'id', $row->shopping_location_id)
			];
		}

		return $returnData;
	}

	/**
	 * Returns the stock entries of a product in default consume order (stock_next_use view:
	 * default consume location first, then opened first, then first due first, then first in first out) -
	 * the first entry is the one to use next.
	 *
	 * @param int $productId
	 * @param bool $excludeOpened When true, only unopened entries are returned
	 * @param bool $allowSubproductSubstitution When true, entries of resolved sub products are included
	 * @return \LessQL\Result Iterable stock entry rows (amounts in the entry's product's stock quantity unit)
	 */
	public function GetProductStockEntries(int $productId, $excludeOpened = false, $allowSubproductSubstitution = false)
	{
		$sqlWhereProductId = 'product_id = ' . $productId;
		if ($allowSubproductSubstitution)
		{
			$sqlWhereProductId = '(product_id IN (SELECT sub_product_id FROM products_resolved WHERE parent_product_id = ' . $productId . ') OR product_id = ' . $productId . ')';
		}

		$sqlWhereAndOpen = 'AND open IN (0, 1)';
		if ($excludeOpened)
		{
			$sqlWhereAndOpen = 'AND open = 0';
		}

		return $this->DB->stock_next_use()->where($sqlWhereProductId . ' ' . $sqlWhereAndOpen);
	}

	/**
	 * Returns all stock entries at the given location.
	 *
	 * @param int $locationId
	 * @return \LessQL\Result Iterable stock entry rows
	 * @throws \Exception When the location does not exist
	 */
	public function GetLocationStockEntries($locationId)
	{
		if (!$this->LocationExists($locationId))
		{
			throw new \Exception('Location does not exist');
		}

		return $this->DB->stock()->where('location_id', $locationId);
	}

	/**
	 * Returns the stock entries of a product at one specific location,
	 * in default consume order (see GetProductStockEntries()).
	 *
	 * @param int $productId
	 * @param int $locationId
	 * @param bool $excludeOpened When true, only unopened entries are returned
	 * @param bool $allowSubproductSubstitution When true, entries of resolved sub products are included
	 * @return array Array of stock entry rows
	 */
	public function GetProductStockEntriesForLocation($productId, $locationId, $excludeOpened = false, $allowSubproductSubstitution = false)
	{
		$stockEntries = $this->GetProductStockEntries($productId, $excludeOpened, $allowSubproductSubstitution);
		return FindAllObjectsInArrayByPropertyValue($stockEntries, 'location_id', $locationId);
	}

	/**
	 * Returns the locations at which a product currently has stock
	 * (rows of the stock_current_locations view).
	 *
	 * @param int $productId
	 * @param bool $allowSubproductSubstitution When true, locations of resolved sub products are included
	 * @return \LessQL\Result Iterable row objects
	 */
	public function GetProductStockLocations(int $productId, $allowSubproductSubstitution = false)
	{
		$sqlWhereProductId = 'product_id = ' . $productId;
		if ($allowSubproductSubstitution)
		{
			$sqlWhereProductId = '(product_id IN (SELECT sub_product_id FROM products_resolved WHERE parent_product_id = ' . $productId . ') OR product_id = ' . $productId . ')';
		}

		return $this->DB->stock_current_locations()->where($sqlWhereProductId);
	}

	/**
	 * Returns a single stock entry by its `stock` table row id (not the stock_id).
	 *
	 * @param int $entryId
	 * @return \LessQL\Row|null The stock entry row, or null when not found
	 */
	public function GetStockEntry($entryId)
	{
		return $this->DB->stock()->where('id', $entryId)->fetch();
	}

	/**
	 * Sets the stock amount of a product to a counted new total (inventory correction).
	 *
	 * The difference to the current stock amount is booked as a TRANSACTION_TYPE_INVENTORY_CORRECTION
	 * via AddProduct() (new amount higher) or ConsumeProduct() (new amount lower), with all their
	 * side effects (stock_log bookings, label printing, stock entry compacting etc.).
	 * For tare weight handled products $newAmount is the gross scale reading (incl. container weight)
	 * and is passed through unchanged, since AddProduct/ConsumeProduct do the tare weight math themselves.
	 *
	 * @param int $productId
	 * @param float $newAmount New total amount in the product's stock quantity unit (gross for tare weight handled products)
	 * @param string|null $bestBeforeDate Due date (Y-m-d) for newly added stock; null derives the product default (see AddProduct())
	 * @param int|null $locationId Location for newly added stock; null means the product's default location
	 * @param float|null $price Price per stock quantity unit for newly added stock; null uses the product's last price
	 * @param int|null $shoppingLocationId null uses the product's last shopping location
	 * @param string|null $purchasedDate Purchased date (Y-m-d) for newly added stock; null means today
	 * @param int $stockLabelType Label printing mode for newly added stock (see AddProduct())
	 * @param string|null $note Note for newly added stock
	 * @return string|null The transaction id of the correction booking(s), or null (unreachable in practice)
	 * @throws \Exception When the product does not exist or the new amount equals the current stock amount
	 */
	public function InventoryProduct(int $productId, float $newAmount, $bestBeforeDate, $locationId = null, $price = null, $shoppingLocationId = null, $purchasedDate = null, $stockLabelType = 0, $note = null)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		$productDetails = (object)$this->GetProductDetails($productId);

		if ($price === null)
		{
			$price = $productDetails->last_price;
		}

		if ($shoppingLocationId === null)
		{
			$shoppingLocationId = $productDetails->last_shopping_location_id;
		}

		if ($purchasedDate == null)
		{
			$purchasedDate = date('Y-m-d');
		}

		// Tare weight handling
		// The given amount is the new total amount including the container weight (gross)
		// So assume that the amount in stock is the amount also including the container weight
		$containerWeight = 0;

		if ($productDetails->product->enable_tare_weight_handling == 1)
		{
			$containerWeight = $productDetails->product->tare_weight;
		}

		if ($newAmount == $productDetails->stock_amount + $containerWeight)
		{
			throw new \Exception('The new amount cannot equal the current stock amount');
		}
		elseif ($newAmount > $productDetails->stock_amount + $containerWeight)
		{
			$bookingAmount = $newAmount - $productDetails->stock_amount;

			if ($productDetails->product->enable_tare_weight_handling == 1)
			{
				// Pass the gross amount through unchanged - AddProduct does the tare weight math itself
				$bookingAmount = $newAmount;
			}

			// The correction is one delegated booking today, but the boundary belongs to the
			// entrypoint: "an inventory correction is atomic" should not depend on what it delegates to.
			return DatabaseService::GetInstance()->InTransaction(function () use ($productId, $bookingAmount, $bestBeforeDate, $purchasedDate, $price, $locationId, $shoppingLocationId, $stockLabelType, $note)
			{
				return $this->AddProduct($productId, $bookingAmount, $bestBeforeDate, self::TRANSACTION_TYPE_INVENTORY_CORRECTION, $purchasedDate, $price, $locationId, $shoppingLocationId, $unusedTransactionId, $stockLabelType, false, $note);
			});
		}
		elseif ($newAmount < $productDetails->stock_amount + $containerWeight)
		{
			$bookingAmount = $productDetails->stock_amount - $newAmount;

			if ($productDetails->product->enable_tare_weight_handling == 1)
			{
				// Pass the gross amount through unchanged - ConsumeProduct does the tare weight math itself
				$bookingAmount = $newAmount;
			}

			// See above.
			return DatabaseService::GetInstance()->InTransaction(function () use ($productId, $bookingAmount)
			{
				return $this->ConsumeProduct($productId, $bookingAmount, false, self::TRANSACTION_TYPE_INVENTORY_CORRECTION);
			});
		}

		return null;
	}

	/**
	 * Marks the given amount of a product as opened.
	 *
	 * Unopened stock entries are processed in default consume order; an entry covering more than the
	 * remaining amount is split (the unopened rest gets a new stock entry with a new stock_id). Each
	 * touched entry gets open = 1, opened_date = today and - when the product has "default due days
	 * after opened" - a shortened due date (never later than the original one; a label reprint webhook
	 * may be triggered on a date change). One TRANSACTION_TYPE_PRODUCT_OPENED booking is written per
	 * touched entry; the stock amount itself is unchanged. When the product has "move on open" set,
	 * the opened entry is additionally transferred to its default consume location. When the user
	 * setting "shopping_list_auto_add_below_min_stock_amount" is enabled, missing products are added
	 * to the configured shopping list afterwards.
	 *
	 * Sub product substitution works as in ConsumeProduct() (amounts converted via QU conversions).
	 *
	 * @param int $productId
	 * @param float $amount Amount to open, in the product's stock quantity unit
	 * @param string $specificStockEntryId 'default' opens in default order; otherwise a stock_id restricting opening to that single stock entry
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @param bool $allowSubproductSubstitution When true, unopened stock of resolved sub products may be opened
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product does not exist, has opening disabled, is tare weight handled,
	 *                    or the amount exceeds the current unopened (aggregated) stock amount
	 */
	public function OpenProduct(int $productId, float $amount, $specificStockEntryId = 'default', &$transactionId = null, $allowSubproductSubstitution = false)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		$product = $this->DB->products($productId);

		if ($product->disable_open == 1)
		{
			throw new \Exception('Product can\'t be opened');
		}

		$productDetails = (object)$this->GetProductDetails($productId);
		$productStockAmountUnopened = $productDetails->stock_amount_aggregated - $productDetails->stock_amount_opened_aggregated;
		$potentialStockEntries = $this->GetProductStockEntries($productId, true, $allowSubproductSubstitution);

		if ($product->enable_tare_weight_handling == 1)
		{
			throw new \Exception('Opening tare weight handling enabled products is not supported');
		}

		if ($amount > $productStockAmountUnopened)
		{
			throw new \Exception('Amount to be opened cannot be > current unopened stock amount');
		}

		if ($specificStockEntryId !== 'default')
		{
			$potentialStockEntries = FindAllObjectsInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
		}

		if ($transactionId === null)
		{
			$transactionId = uniqid();
		}

		$labelWebhookPayloads = [];

		// The booking and the stock entry it describes (and the split-off rest entry) have to
		// land together, or the ledger records an opening that stock does not show.
		DatabaseService::GetInstance()->InTransaction(function () use ($potentialStockEntries, $amount, $product, $productDetails, $productId, $allowSubproductSubstitution, &$transactionId, &$labelWebhookPayloads)
		{
			foreach ($potentialStockEntries as $stockEntry)
			{
				if ($amount == 0)
				{
					break;
				}

				$newBestBeforeDate = $stockEntry->best_before_date;
				if ($product->default_best_before_days_after_open > 0)
				{
					$newBestBeforeDate = date('Y-m-d', strtotime('+' . $product->default_best_before_days_after_open . ' days'));

					// The new due date should be never > the original due date
					if (strtotime($newBestBeforeDate) > strtotime($stockEntry->best_before_date))
					{
						$newBestBeforeDate = $stockEntry->best_before_date;
					}

					if (VICTUAL_FEATURE_FLAG_LABEL_PRINTER && VICTUAL_LABEL_PRINTER_RUN_SERVER && $productDetails->product->auto_reprint_stock_label == 1 && $newBestBeforeDate != $stockEntry->best_before_date)
					{
						$webhookData = array_merge([
							'product' => $productDetails->product->name,
							'grocycode' => (string)(new Grocycode(Grocycode::PRODUCT, $productId, [$stockEntry->stock_id])),
							'details' => $productDetails,
							'stock_entry' => $stockEntry,
						], VICTUAL_LABEL_PRINTER_PARAMS);

						if (VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
						{
							$webhookData['due_date'] = LocalizationService::GetInstance()->__t('DD') . ': ' . $newBestBeforeDate;
						}

						// Built here from the values in hand so the label describes the entry as it was
						// booked; only the firing waits until after the commit.
						$labelWebhookPayloads[] = $webhookData;
					}
				}

				if ($allowSubproductSubstitution && $stockEntry->product_id != $productId)
				{
					// A sub product will be used -> use QU conversions
					$subProduct = $this->DB->products($stockEntry->product_id);
					$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $stockEntry->product_id, $product->qu_id_stock, $subProduct->qu_id_stock)->fetch();
					if ($conversion != null)
					{
						$amount = $amount * $conversion->factor;
					}
				}

				if ($amount >= $stockEntry->amount)
				{
					// Mark the whole stock entry as opened
					$logRow = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'transaction_type' => self::TRANSACTION_TYPE_PRODUCT_OPENED,
						'price' => $stockEntry->price,
						'opened_date' => date('Y-m-d'),
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRow->save();

					$stockEntry->update([
						'open' => 1,
						'opened_date' => date('Y-m-d'),
						'best_before_date' => $newBestBeforeDate
					]);

					$amount -= $stockEntry->amount;
				}
				else
				{
					// Stock entry amount is > than needed amount -> split the stock entry
					$restStockAmount = $stockEntry->amount - $amount;
					$restStockId = uniqid();

					$newStockRow = $this->DB->stock()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $restStockAmount,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'stock_id' => $restStockId,
						'price' => $stockEntry->price,
						'note' => $stockEntry->note
					]);
					$newStockRow->save();

					// The remainder gets a stock_id of its own and no booking of its own -
					// the "product-opened" row below keeps the original one - so nothing in
					// stock_log would tie it to the purchase its units arrived by. Without
					// that tie an edit of this entry corrects nothing in
					// products_average_price, while the same edit on an entry that was
					// never split does. See migrations/0267.pgsql.sql.
					$this->RecordSplitOrigin($stockEntry->stock_id, $restStockId);

					$logRow = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'transaction_type' => self::TRANSACTION_TYPE_PRODUCT_OPENED,
						'price' => $stockEntry->price,
						'opened_date' => date('Y-m-d'),
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRow->save();

					$stockEntry->update([
						'amount' => $amount,
						'open' => 1,
						'opened_date' => date('Y-m-d'),
						'best_before_date' => $newBestBeforeDate
					]);

					$amount = 0;
				}

				if ($product->move_on_open == 1)
				{
					$locationIdTo = $product->default_consume_location_id;
					if (!empty($locationIdTo) && $locationIdTo != $stockEntry->location_id)
					{
						$this->TransferProduct($stockEntry->product_id, $stockEntry->amount, $stockEntry->location_id, $locationIdTo, $stockEntry->stock_id, $transactionId);
					}
				}
			}

			if (boolval(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
			{
				$this->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
			}

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back booking leaves no event behind and a
			// crash after the commit still delivers one.
			BookingEventPublisher::RecordTransaction($transactionId);
		});

		// After the commit: a reprinted label should mean the new due date was actually
		// stored, and a printer call with a 2 s timeout has no business holding a write lock.
		foreach ($labelWebhookPayloads as $webhookData)
		{
			$runner = new WebhookRunner();
			$runner->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
		}

		return $transactionId;
	}

	/**
	 * Decreases the amount of a product on the shopping list; the entry is deleted when the
	 * remaining amount falls below the smallest displayable value for the user's configured
	 * amount decimal places. Returns gracefully when the product has no list entry.
	 *
	 * @param int $productId
	 * @param float $amount Amount to subtract (in the quantity unit of the list entry)
	 * @param int $listId Shopping list id (only validated for existence)
	 * @return void
	 * @throws \Exception When the shopping list does not exist
	 */
	public function RemoveProductFromShoppingList($productId, $amount = 1, $listId = 1)
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		$productRow = $this->DB->shopping_list()->where('product_id = :1', $productId)->fetch();

		// If no entry was found with for this product, we return gracefully
		if ($productRow != null && !empty($productRow))
		{
			$decimals = UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'stock_decimal_places_amounts');
			$newAmount = $productRow->amount - $amount;

			// Delete the entry when the rest amount is below the smallest value representable
			// with the user's configured amount decimal places (e.g. 0.01 for 2 decimals)
			if ($newAmount < floatval('0.' . str_repeat('0', $decimals - ($decimals <= 0 ? 0 : 1)) . '1'))
			{
				$productRow->delete();
			}
			else
			{
				$productRow->update(['amount' => $newAmount]);
			}
		}
	}

	/**
	 * Renders a shopping list as plain text lines for thermal printer output, one entry per line
	 * ("<amount> <product name>"), with amounts right-padded to a common width. Product amounts are
	 * converted from stock quantity units to the entry's quantity unit and rounded; quantity unit
	 * names and notes are appended depending on the VICTUAL_TPRINTER_* settings. Entries without a
	 * product print their note instead.
	 *
	 * @param int $listId
	 * @return array Array of printable strings
	 * @throws \Exception When the shopping list does not exist
	 */
	public function GetShoppinglistInPrintableStrings($listId = 1): array
	{
		if (!$this->ShoppingListExists($listId))
		{
			throw new \Exception('Shopping list does not exist');
		}

		$result_product = [];
		$result_quantity = [];
		$rowsShoppingListProducts = $this->DB->uihelper_shopping_list()->where('shopping_list_id = :1', $listId)->fetchAll();
		foreach ($rowsShoppingListProducts as $row)
		{
			$isValidProduct = ($row->product_id != null && $row->product_id != '');
			if ($isValidProduct)
			{
				$product = $this->DB->products()->where('id = :1', $row->product_id)->fetch();
				$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $product->id, $product->qu_id_stock, $row->qu_id)->fetch();

				$factor = 1.0;
				if ($conversion != null)
				{
					$factor = $conversion->factor;
				}

				$amount = round($row->amount * $factor);
				$note = '';

				if (VICTUAL_TPRINTER_PRINT_NOTES)
				{
					if ($row->note != '')
					{
						$note = ' (' . $row->note . ')';
					}
				}
			}

			if (VICTUAL_TPRINTER_PRINT_QUANTITY_NAME && $isValidProduct)
			{
				$quantityname = $row->qu_name;
				if ($amount > 1)
				{
					$quantityname = $row->qu_name_plural;
				}

				array_push($result_quantity, $amount . ' ' . $quantityname);
				array_push($result_product, $row->product_name . $note);
			}
			else
			{
				if ($isValidProduct)
				{
					array_push($result_quantity, $amount);
					array_push($result_product, $row->product_name . $note);
				}
				else
				{
					array_push($result_quantity, round($row->amount));
					array_push($result_product, $row->note);
				}
			}
		}

		//Add padding to look nicer
		$maxlength = 1;
		foreach ($result_quantity as $quantity)
		{
			if (strlen($quantity) > $maxlength)
			{
				$maxlength = strlen($quantity);
			}
		}

		$result = [];
		$length = count($result_quantity);
		for ($i = 0; $i < $length; $i++)
		{
			$quantity = str_pad($result_quantity[$i], $maxlength);
			array_push($result, $quantity . '  ' . $result_product[$i]);
		}

		return $result;
	}

	/**
	 * Transfers the given amount of a product from one location to another.
	 *
	 * Stock entries at the source location are processed in default consume order; a fully
	 * transferred entry just gets its location updated, a partially transferred one is split
	 * (the rest stays at the source, the transferred amount becomes a new stock row sharing the
	 * same stock_id). Each touched entry writes a correlated pair of bookings:
	 * TRANSACTION_TYPE_TRANSFER_FROM (negative amount, source location) and
	 * TRANSACTION_TYPE_TRANSFER_TO (positive amount, destination location).
	 * With the product freezing feature enabled, moving into a freezer re-dates the entry using
	 * "default due days after freezing" (-1 = never expires) and moving out of a freezer using
	 * "default due days after thawing"; a label reprint webhook may be triggered on a date change.
	 *
	 * @param int $productId
	 * @param float $amount Amount in the product's stock quantity unit
	 * @param int $locationIdFrom Source location id
	 * @param int $locationIdTo Destination location id
	 * @param string $specificStockEntryId 'default' transfers in default order; otherwise a stock_id restricting the transfer to that single stock entry
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product or a location does not exist, the product is tare weight handled
	 *                    (not supported), or the amount exceeds the stock amount at the source location
	 */
	public function TransferProduct(int $productId, float $amount, int $locationIdFrom, int $locationIdTo, $specificStockEntryId = 'default', &$transactionId = null)
	{
		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}

		if (!$this->LocationExists($locationIdFrom))
		{
			throw new \Exception('Source location does not exist');
		}

		if (!$this->LocationExists($locationIdTo))
		{
			throw new \Exception('Destination location does not exist');
		}

		// Tare weight handling
		// The given amount is the new total amount including the container weight (gross)
		// The amount to be posted needs to be the absolute value of the given amount - stock amount - tare weight
		$productDetails = (object)$this->GetProductDetails($productId);

		if ($productDetails->product->enable_tare_weight_handling == 1)
		{
			// Hard fail for now, as we not yet support transferring tare weight enabled products
			throw new \Exception('Transferring tare weight enabled products is not yet possible');
			if ($amount < $productDetails->product->tare_weight)
			{
				throw new \Exception('The amount cannot be lower than the defined tare weight');
			}

			$amount = abs($amount - $productDetails->stock_amount - $productDetails->product->tare_weight);
		}

		$productStockAmountAtFromLocation = $this->DB->stock()->where('product_id = :1 AND location_id = :2', $productId, $locationIdFrom)->sum('amount');
		$potentialStockEntriesAtFromLocation = $this->GetProductStockEntriesForLocation($productId, $locationIdFrom);

		if ($amount > $productStockAmountAtFromLocation)
		{
			throw new \Exception('Amount to be transferred cannot be > current stock amount at the source location');
		}

		if ($specificStockEntryId !== 'default')
		{
			$potentialStockEntriesAtFromLocation = FindAllObjectsInArrayByPropertyValue($potentialStockEntriesAtFromLocation, 'stock_id', $specificStockEntryId);
		}

		if ($transactionId === null)
		{
			$transactionId = uniqid();
		}

		$labelWebhookPayloads = [];

		// Both bookings of an entry plus the stock row itself have to land together, or the
		// stock ends up split across the two locations.
		DatabaseService::GetInstance()->InTransaction(function () use ($potentialStockEntriesAtFromLocation, $amount, $productDetails, $productId, $locationIdFrom, $locationIdTo, &$transactionId, &$labelWebhookPayloads)
		{
			foreach ($potentialStockEntriesAtFromLocation as $stockEntry)
			{
				if ($amount == 0)
				{
					break;
				}

				$newBestBeforeDate = $stockEntry->best_before_date;
				if (VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_FREEZING)
				{
					$locationFrom = $this->DB->locations()->where('id', $locationIdFrom)->fetch();
					$locationTo = $this->DB->locations()->where('id', $locationIdTo)->fetch();

					// Product was moved from a non-freezer to freezer location -> freeze
					if ($locationFrom->is_freezer == 0 && $locationTo->is_freezer == 1 && ($productDetails->product->default_best_before_days_after_freezing > 0 || $productDetails->product->default_best_before_days_after_freezing == -1))
					{
						if ($productDetails->product->default_best_before_days_after_freezing == -1)
						{
							$newBestBeforeDate = date('2999-12-31');
						}
						else
						{
							$newBestBeforeDate = date('Y-m-d', strtotime('+' . $productDetails->product->default_best_before_days_after_freezing . ' days'));
						}
					}

					// Product was moved from a freezer to non-freezer location -> thaw
					if ($locationFrom->is_freezer == 1 && $locationTo->is_freezer == 0 && $productDetails->product->default_best_before_days_after_thawing > 0)
					{
						$newBestBeforeDate = date('Y-m-d', strtotime('+' . $productDetails->product->default_best_before_days_after_thawing . ' days'));
					}

					if (VICTUAL_FEATURE_FLAG_LABEL_PRINTER && VICTUAL_LABEL_PRINTER_RUN_SERVER && $productDetails->product->auto_reprint_stock_label == 1 && $stockEntry->best_before_date != $newBestBeforeDate)
					{
						$webhookData = array_merge([
							'product' => $productDetails->product->name,
							'grocycode' => (string)(new Grocycode(Grocycode::PRODUCT, $productId, [$stockEntry->stock_id])),
							'details' => $productDetails,
							'stock_entry' => $stockEntry,
						], VICTUAL_LABEL_PRINTER_PARAMS);

						if (VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING)
						{
							$webhookData['due_date'] = LocalizationService::GetInstance()->__t('DD') . ': ' . $newBestBeforeDate;
						}

						// Built here from the values in hand so the label describes the entry as it was
						// booked; only the firing waits until after the commit.
						$labelWebhookPayloads[] = $webhookData;
					}
				}

				$correlationId = uniqid();
				if ($amount >= $stockEntry->amount)
				{
					// Take the whole stock entry
					$logRowForLocationFrom = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_FROM,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRowForLocationFrom->save();

					$logRowForLocationTo = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount,
						'best_before_date' => $newBestBeforeDate,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_TO,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $locationIdTo,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRowForLocationTo->save();

					$stockEntry->update([
						'location_id' => $locationIdTo,
						'best_before_date' => $newBestBeforeDate
					]);

					$amount -= $stockEntry->amount;
				}
				else
				{
					// Stock entry amount is > than needed amount -> split the stock entry resp. update the amount
					$restStockAmount = $stockEntry->amount - $amount;

					$logRowForLocationFrom = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_FROM,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRowForLocationFrom->save();

					$logRowForLocationTo = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount,
						'best_before_date' => $newBestBeforeDate,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_TO,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $locationIdTo,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note
					]);
					$logRowForLocationTo->save();

					// This is the existing stock entry -> remains at the source location with the rest amount
					$stockEntry->update([
						'amount' => $restStockAmount
					]);

					// The transferred amount gets into a new stock entry
					$stockEntryNew = $this->DB->stock()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount,
						'best_before_date' => $newBestBeforeDate,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'price' => $stockEntry->price,
						'location_id' => $locationIdTo,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'open' => $stockEntry->open,
						'opened_date' => $stockEntry->opened_date,
						'note' => $stockEntry->note
					]);
					$stockEntryNew->save();

					$amount = 0;
				}
			}

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back booking leaves no event behind and a
			// crash after the commit still delivers one.
			BookingEventPublisher::RecordTransaction($transactionId);
		});

		// After the commit: a printed label should mean the transfer happened, and a printer
		// call with a 2 s timeout has no business holding a write lock open.
		foreach ($labelWebhookPayloads as $webhookData)
		{
			$runner = new WebhookRunner();
			$runner->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
		}

		return $transactionId;
	}

	/**
	 * Undoes a single stock_log booking by reversing its effect on the `stock` table,
	 * then marks it undone = 1 with an undone_timestamp (bookings are never deleted).
	 *
	 * Reversal per transaction type:
	 * - PURCHASE / positive INVENTORY_CORRECTION: the corresponding stock entry is deleted
	 * - CONSUME / negative INVENTORY_CORRECTION: the consumed amount is re-added as a stock entry
	 * - TRANSFER_TO / TRANSFER_FROM: the amount is moved back (entries re-created/deleted as needed)
	 * - PRODUCT_OPENED: the open flag/opened date are cleared and the original due date restored
	 * - STOCK_EDIT_OLD: the stock entry is restored to the logged pre-edit state
	 * - STOCK_EDIT_NEW: only the booking is marked undone (the _OLD counterpart does the restore)
	 *
	 * When the booking has a correlation id (stock edits, transfers) and $skipCorrelatedBookings
	 * is false, all not yet undone bookings of that correlation are undone instead, newest first,
	 * and this call returns without further processing the given booking itself.
	 *
	 * @param int $bookingId stock_log row id
	 * @param bool $skipCorrelatedBookings Internal flag used when recursing / undoing whole transactions
	 * @return void
	 * @throws \Exception When the booking does not exist or was already undone, has newer dependent
	 *                    bookings on the same stock_id, or its transaction type cannot be undone
	 */
	public function UndoBooking($bookingId, $skipCorrelatedBookings = false)
	{
		$logRow = $this->DB->stock_log()->where('id = :1 AND undone = 0', $bookingId)->fetch();
		if ($logRow == null)
		{
			throw new \Exception('Booking does not exist or was already undone');
		}

		// Undo all correlated bookings first, in order from newest first to the oldest
		if (!$skipCorrelatedBookings && !empty($logRow->correlation_id))
		{
			$correlatedBookings = $this->DB->stock_log()->where('undone = 0 AND correlation_id = :1', $logRow->correlation_id)->orderBy('id', 'DESC')->fetchAll();

			// The correlated bookings (a stock edit's old/new pair, a transfer's from/to pair)
			// are only meaningful undone as a set.
			DatabaseService::GetInstance()->InTransaction(function () use ($correlatedBookings, $logRow)
			{
				foreach ($correlatedBookings as $correlatedBooking)
				{
					$this->UndoBooking($correlatedBooking->id, true);
				}

				// Inside the transaction on purpose: the outbox row and the ledger rows
				// commit together or not at all, so a rolled back undo leaves no event
				// behind and a crash after the commit still delivers one.
				BookingEventPublisher::RecordTransaction($logRow->transaction_id);
			});

			return;
		}

		// A booking can only be undone when it is the newest (not yet undone) one of its stock entry -
		// otherwise later bookings would reference stock state this undo would remove
		$hasSubsequentBookings = $this->DB->stock_log()->where('stock_id = :1 AND id != :2 AND (correlation_id IS NOT NULL OR correlation_id != :3) AND id > :2 AND undone = 0', $logRow->stock_id, $logRow->id, $logRow->correlation_id)->count() > 0;
		if ($hasSubsequentBookings)
		{
			throw new \Exception('Booking has subsequent dependent bookings, undo not possible');
		}

		// Every branch below reverses the booking's effect on `stock` and only then marks the
		// booking undone - a failure between those two writes would leave a booking whose
		// undone flag disagrees with the stock it was supposed to restore.
		DatabaseService::GetInstance()->InTransaction(function () use ($logRow, $skipCorrelatedBookings)
		{
			if ($logRow->transaction_type === self::TRANSACTION_TYPE_PURCHASE || ($logRow->transaction_type === self::TRANSACTION_TYPE_INVENTORY_CORRECTION && $logRow->amount > 0))
			{
				// Remove corresponding stock entry
				$stockRows = $this->DB->stock()->where('stock_id', $logRow->stock_id);
				$stockRows->delete();

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_CONSUME || ($logRow->transaction_type === self::TRANSACTION_TYPE_INVENTORY_CORRECTION && $logRow->amount < 0))
			{
				// Add corresponding amount back to stock
				$stockRow = $this->DB->stock()->createRow([
					'product_id' => $logRow->product_id,
					'amount' => $logRow->amount * -1,
					'best_before_date' => $logRow->best_before_date,
					'purchased_date' => $logRow->purchased_date,
					'stock_id' => $logRow->stock_id,
					'price' => $logRow->price,
					'opened_date' => $logRow->opened_date,
					'open' => $logRow->opened_date !== null, // The open flag itself is not logged, so it is derived from the logged opened date
					'location_id' => $logRow->location_id,
					'note' => $logRow->note,
					'shopping_location_id' => $logRow->shopping_location_id
				]);
				$stockRow->save();

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_TRANSFER_TO)
			{
				$stockRow = $this->DB->stock()->where('stock_id = :1 AND location_id = :2', $logRow->stock_id, $logRow->location_id)->fetch();
				if ($stockRow === null)
				{
					throw new \Exception('Booking does not exist or was already undone');
				}

				$newAmount = $stockRow->amount - $logRow->amount;
				if ($newAmount == 0)
				{
					$stockRow->delete();
				}
				else
				{
					// Remove corresponding amount back to stock
					$stockRow->update([
						'amount' => $newAmount
					]);
				}

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_TRANSFER_FROM)
			{
				// Add corresponding amount back to stock
				$stockRow = $this->DB->stock()->where('stock_id = :1 AND location_id = :2', $logRow->stock_id, $logRow->location_id)->fetch();
				if ($stockRow === null)
				{
					$stockRow = $this->DB->stock()->createRow([
						'product_id' => $logRow->product_id,
						'amount' => $logRow->amount * -1,
						'best_before_date' => $logRow->best_before_date,
						'purchased_date' => $logRow->purchased_date,
						'stock_id' => $logRow->stock_id,
						'price' => $logRow->price,
						'opened_date' => $logRow->opened_date,
						'note' => $logRow->note,
						'shopping_location_id' => $logRow->shopping_location_id
					]);
					$stockRow->save();
				}
				else
				{
					$stockRow->update([
						'amount' => $stockRow->amount - $logRow->amount
					]);
				}

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_PRODUCT_OPENED)
			{
				// Remove opened flag from corresponding stock entry
				$stockRows = $this->DB->stock()->where('stock_id = :1 AND amount = :2 AND purchased_date = :3', $logRow->stock_id, $logRow->amount, $logRow->purchased_date)->limit(1);
				$stockRows->update([
					'open' => 0,
					'opened_date' => null,
					'best_before_date' => $logRow->best_before_date // Is only relevant when the product has "Default due days after opened", but also doesn't hurt for other products
				]);

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_EDIT_NEW)
			{
				// Update log entry, no action needed
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_EDIT_OLD)
			{
				// Make sure there is a stock row still
				$stockRow = $this->DB->stock()->where('id = :1', $logRow->stock_row_id)->fetch();

				if ($stockRow == null)
				{
					throw new \Exception('Booking does not exist or was already undone');
				}

				$openedDate = $logRow->opened_date;
				$open = true;
				if ($openedDate == null)
				{
					$open = false;
				}

				$stockRow->update([
					'amount' => $logRow->amount,
					'best_before_date' => $logRow->best_before_date,
					'purchased_date' => $logRow->purchased_date,
					'price' => $logRow->price,
					'location_id' => $logRow->location_id,
					'open' => $open,
					'opened_date' => $openedDate,
					'note' => $logRow->note
				]);

				// Update log entry
				$logRow->update([
					'undone' => 1,
					'undone_timestamp' => date('Y-m-d H:i:s')
				]);
			}
			else
			{
				throw new \Exception('This booking cannot be undone');
			}

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back booking leaves no event behind and a
			// crash after the commit still delivers one.
			//
			// Only the outermost call records. UndoTransaction and the correlated loop above
			// both drive this method with $skipCorrelatedBookings set and record the whole
			// set themselves, so recording here as well would enqueue a second event for the
			// same transaction describing a half-undone state.
			if (!$skipCorrelatedBookings)
			{
				BookingEventPublisher::RecordTransaction($logRow->transaction_id);
			}
		});
	}

	/**
	 * Undoes all not yet undone bookings of a transaction (see UndoBooking()),
	 * newest booking first so dependent bookings are reversed in the right order.
	 *
	 * @param string $transactionId
	 * @return void
	 * @throws \Exception When no (not yet undone) booking with this transaction id exists,
	 *                    or any contained booking cannot be undone
	 */
	public function UndoTransaction($transactionId)
	{
		$transactionBookings = $this->DB->stock_log()->where('undone = 0 AND transaction_id = :1', $transactionId)->orderBy('id', 'DESC')->fetchAll();

		if (count($transactionBookings) === 0)
		{
			throw new \Exception('This transaction was not found or already undone');
		}

		// A partially undone transaction is a state the ledger cannot represent, so the
		// bookings are undone all together or not at all.
		DatabaseService::GetInstance()->InTransaction(function () use ($transactionBookings, $transactionId)
		{
			foreach ($transactionBookings as $transactionBooking)
			{
				$this->UndoBooking($transactionBooking->id, true);
			}

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back booking leaves no event behind and a
			// crash after the commit still delivers one.
			BookingEventPublisher::RecordTransaction($transactionId);
		});
	}

	/**
	 * Merges one product into another and deletes the removed product.
	 *
	 * Re-assigns stock, stock_log, barcodes, QU conversions, recipe positions/recipes, meal plan
	 * entries and shopping list entries to the kept product inside a single database transaction
	 * (rolled back on any error). Amounts are multiplied by the stock QU conversion factor from
	 * the removed product's stock unit to the kept product's stock unit (factor 1 when no
	 * conversion is defined).
	 *
	 * @param int $productIdToKeep
	 * @param int $productIdToRemove
	 * @return void
	 * @throws \Exception When either product does not exist / is inactive, both ids are equal,
	 *                    or any of the update statements fails
	 */
	public function MergeProducts(int $productIdToKeep, int $productIdToRemove)
	{
		if (!$this->ProductExists($productIdToKeep))
		{
			throw new \Exception('$productIdToKeep does not exist or is inactive');
		}

		if (!$this->ProductExists($productIdToRemove))
		{
			throw new \Exception('$productIdToRemove does not exist or is inactive');
		}

		if ($productIdToKeep == $productIdToRemove)
		{
			throw new \Exception('$productIdToKeep cannot equal $productIdToRemove');
		}

		DatabaseService::GetInstance()->InTransaction(function () use ($productIdToKeep, $productIdToRemove)
		{
			$productToKeep = $this->DB->products($productIdToKeep);
			$productToRemove = $this->DB->products($productIdToRemove);
			$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $productToRemove->id, $productToRemove->qu_id_stock, $productToKeep->qu_id_stock)->fetch();
			$factor = 1.0;
			if ($conversion != null)
			{
				$factor = $conversion->factor;
			}

			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_log SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE product_barcodes SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE quantity_unit_conversions SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE recipes_pos SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE recipes SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE meal_plan SET product_id = ' . $productIdToKeep . ', product_amount = product_amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE shopping_list SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM products WHERE id = ' . $productIdToRemove);
		});
	}

	/**
	 * Records that a stock entry was split off another one, so that the views which weight
	 * the average price by what was booked can find the booking this entry's units arrived by.
	 *
	 * The stock_id that carries the ORIGIN booking is stored, not the immediate parent. A
	 * remainder can itself be split by a later partial open, and chains of four are ordinary;
	 * storing the origin keeps stock_edited_entries a join rather than a recursion, and means
	 * no rewrite of stock_ids can turn the table into a cycle.
	 *
	 * @param string $parentStockId The entry that was split
	 * @param string $childStockId  The remainder, which has no booking of its own
	 * @return void
	 */
	private function RecordSplitOrigin(string $parentStockId, string $childStockId)
	{
		$parentOrigin = $this->DB->stock_entry_origins()->where('stock_id = :1', $parentStockId)->fetch();
		$originStockId = $parentOrigin === null ? $parentStockId : $parentOrigin->origin_stock_id;

		if ($originStockId === $childStockId)
		{
			return;
		}

		$originRow = $this->DB->stock_entry_origins()->createRow([
			'stock_id' => $childStockId,
			'origin_stock_id' => $originStockId
		]);
		$originRow->save();
	}

	/**
	 * Merges stock entries which are equal in every relevant attribute (product, due date,
	 * purchased date, price, open state/date, location, shopping location and note) into a
	 * single entry holding the summed amount.
	 *
	 * Candidate groups come from the stock_splits view (which excludes entries with per-unit
	 * labels - stock_id starting with "x" - and entries with userfield values). For each group,
	 * inside its own database transaction, all stock and stock_log rows are rewritten to the
	 * surviving stock_id, the redundant stock rows are deleted and the kept row is set to the
	 * group's total amount. The split lineage in stock_entry_origins (see RecordSplitOrigin())
	 * is rewritten with them.
	 *
	 * @param int|null $productId Limit compacting to this product; null compacts all products
	 * @return void
	 * @throws \Exception When one of the statements fails (that group's transaction is rolled back)
	 */
	public function CompactStockEntries($productId = null)
	{
		if ($productId == null)
		{
			$splittedStockEntries = $this->DB->stock_splits();
		}
		else
		{
			$splittedStockEntries = $this->DB->stock_splits()->where('product_id = :1', $productId);
		}

		foreach ($splittedStockEntries as $splittedStockEntry)
		{
			DatabaseService::GetInstance()->InTransaction(function () use ($splittedStockEntry)
			{
				$stockIds = explode(',', $splittedStockEntry->stock_id_group);
				foreach ($stockIds as $stockId)
				{
					if ($stockId != $splittedStockEntry->stock_id_to_keep)
					{
						DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock SET stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE stock_id = \'' . $stockId . '\'');
						DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_log SET stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE stock_id = \'' . $stockId . '\'');

						// The split lineage moves with the stock_ids above, or it would point
						// at an entry that no longer exists. The disappearing entry's own row
						// goes rather than being rewritten: what survives the merge is one
						// entry, and it keeps the origin it already had. Anything that was
						// split off the disappearing entry now descends from the surviving
						// one, because that is where its bookings just went. A row left
						// pointing at itself is meaningless and is dropped.
						DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM stock_entry_origins WHERE stock_id = \'' . $stockId . '\'');
						DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_entry_origins SET origin_stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE origin_stock_id = \'' . $stockId . '\'');
						DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM stock_entry_origins WHERE stock_id = origin_stock_id');
					}
				}

				$stockEntryIds = explode(',', $splittedStockEntry->id_group);
				foreach ($stockEntryIds as $stockEntryId)
				{
					if ($stockEntryId != $splittedStockEntry->id_to_keep)
					{
						DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM stock WHERE id = ' . $stockEntryId);
					}
					else
					{
						DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock SET amount = ' . $splittedStockEntry->total_amount . ' WHERE id = ' . $splittedStockEntry->id_to_keep);
					}
				}
			});
		}
	}

	/**
	 * Instantiates the barcode lookup plugin configured via VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN,
	 * passing it the active locations, active quantity units and the current user's settings.
	 * A plugin file in the data dir (user plugin) takes precedence over the bundled one.
	 *
	 * @return object The plugin instance
	 * @throws \Exception When no plugin is configured or the plugin file was not found
	 */
	private function LoadExternalBarcodeLookupPlugin()
	{
		$pluginName = defined('VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN') ? VICTUAL_STOCK_BARCODE_LOOKUP_PLUGIN : '';
		if (empty($pluginName))
		{
			throw new \Exception('No barcode lookup plugin defined');
		}

		// User plugins take precedence
		$standardPluginPath = __DIR__ . "/../plugins/$pluginName.php";
		$userPluginPath = VICTUAL_DATAPATH . "/plugins/$pluginName.php";
		if (file_exists($userPluginPath))
		{
			require_once $userPluginPath;
			return new $pluginName($this->DB->locations()->where('active = 1')->fetchAll(), $this->DB->quantity_units()->where('active = 1')->fetchAll(), UsersService::GetInstance()->GetUserSettings(VICTUAL_USER_ID));
		}
		elseif (file_exists($standardPluginPath))
		{
			require_once $standardPluginPath;
			return new $pluginName($this->DB->locations()->where('active = 1')->fetchAll(), $this->DB->quantity_units()->where('active = 1')->fetchAll(), UsersService::GetInstance()->GetUserSettings(VICTUAL_USER_ID));
		}
		else
		{
			throw new \Exception("Plugin $pluginName was not found");
		}
	}

	/**
	 * Checks whether an active location with the given id exists.
	 *
	 * @param int $locationId
	 * @return bool
	 */
	private function LocationExists($locationId)
	{
		$locationRow = $this->DB->locations()->where('id = :1', $locationId)->where('active = 1')->fetch();
		return $locationRow !== null;
	}

	/**
	 * Checks whether an active product with the given id exists.
	 *
	 * @param int $productId
	 * @return bool
	 */
	private function ProductExists($productId)
	{
		$productRow = $this->DB->products()->where('id = :1 and active = 1', $productId)->fetch();
		return $productRow !== null;
	}

	/**
	 * Checks whether a shopping list with the given id exists.
	 *
	 * @param int $listId
	 * @return bool
	 */
	private function ShoppingListExists($listId)
	{
		$shoppingListRow = $this->DB->shopping_lists()->where('id = :1', $listId)->fetch();
		return $shoppingListRow !== null;
	}
}
