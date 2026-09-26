<?php

namespace Victual\Services;

use Victual\Helpers\Grocycode;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Storage\FileStorage;

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

	/**
	 * Snapshot of a stock entry's measurement *after* it was re-measured via
	 * MeasureStockEntry() (correlated with the _OLD booking). A reversible, separately
	 * recorded measurement - ADR-0022 open question 2's review recommendation - does not
	 * change stock.amount and is not a consumption booking.
	 */
	const TRANSACTION_TYPE_STOCK_MEASURED_NEW = 'stock-measured-new';

	/** Snapshot of a stock entry's measurement *before* it was re-measured via MeasureStockEntry() (used to restore it on undo) */
	const TRANSACTION_TYPE_STOCK_MEASURED_OLD = 'stock-measured-old';

	/** Transfer between locations: removal side at the source location (negative amount, correlated with _TO) */
	const TRANSACTION_TYPE_TRANSFER_FROM = 'transfer_from';

	/** Transfer between locations: addition side at the destination location (positive amount, correlated with _FROM) */
	const TRANSACTION_TYPE_TRANSFER_TO = 'transfer_to';

	/**
	 * Extensions ExternalBarcodeLookup() will store a downloaded or inline barcode picture
	 * under, matched case-insensitively against the URL path, the data: URI's declared type,
	 * or (when neither names one) the response's Content-Type - sweep finding S14
	 * (docs/security-sweep.md). "jpeg" is kept as its own entry rather than normalised to
	 * "jpg": both already occur today (a URL path commonly ends ".jpg", a Content-Type of
	 * image/jpeg produces the subtype "jpeg") and either is a safe, unambiguous file
	 * extension, so there is no reason to rewrite one into the other.
	 */
	const ALLOWED_PICTURE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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
	 * $amount is always the net amount to add. Product-level tare weight handling (a gross
	 * reading with the container weight subtracted) was removed under ADR-0022 decisions 4
	 * and 7 (2026-09-14); this signature dropped the $addExactAmount parameter that only ever
	 * had an effect for tare-enabled products, since no API caller ever set it true (see
	 * docs/plans/landed/28-open-container-measurement.md).
	 *
	 * @param int $productId
	 * @param float $amount Amount in the product's stock quantity unit
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
	 * @param string|null $note Free text note stored on the stock entry and booking
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product or location does not exist, $amount <= 0, or $transactionType is not valid here
	 */
	public function AddProduct(int $productId, float $amount, $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId = null, $shoppingLocationId = null, &$transactionId = null, $stockLabelType = 0, $note = null)
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

		// Product-level tare weight arithmetic against the product's whole stock total was
		// removed here under ADR-0022 decisions 4 and 7 (2026-09-14): weighing one container
		// subtracted the stock amount of every entry of the product, sealed ones included.
		// See docs/plans/landed/28-open-container-measurement.md and the spike's negative control
		// (.spike-adr22/RESULTS.md#prerequisite-1-coexistence-with-a-negative-control) for the
		// demonstrated defect. The fields stay on the wire at their current values per decision
		// 7; only the arithmetic goes. Per-entry measurement (OpenProduct(), MeasureStockEntry())
		// is the replacement mechanism.

		// Check that the location exists if one was supplied
		if ($locationId !== null && !$this->LocationExists($locationId))
		{
			throw new \Exception('Location does not exist');
		}

		//Set the default due date, if none is supplied
		if ($bestBeforeDate == null)
		{
			if ($locationId !== null)
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

			// The booking, the stock entry it describes, the label it may print and the
			// compacting that may immediately rewrite both belong to one addition and have to
			// land as one.
			DatabaseService::GetInstance()->InTransaction(function () use ($productId, $amount, $bestBeforeDate, $transactionType, $purchasedDate, $price, $locationId, $shoppingLocationId, $stockLabelType, $note, $productDetails, &$transactionId)
			{
				// Serialises against every other booking of this product (issue #458) -
				// including the CompactStockEntries() call below, which reads and rewrites
				// this product's stock rows.
				DatabaseService::GetInstance()->LockProductStock($productId);

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

						if (VICTUAL_FEATURE_FLAG_LABELS)
						{
							// Enqueued inside this same transaction: the label mapping, its
							// capture and the print job commit with the entry or not at all
							// (plan 32 piece D), unlike the webhook this replaces, which fired
							// only after commit and could not roll back with a failed purchase.
							$this->IssueStockEntryLabel((int)$stockRow->id);
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

					if ($stockLabelType == 1 && VICTUAL_FEATURE_FLAG_LABELS)
					{
						$this->IssueStockEntryLabel((int)$stockRow->id);
					}
				}

				$this->CompactStockEntries($productId);

				// Inside the transaction on purpose: the outbox row and the ledger rows
				// commit together or not at all, so a rolled back booking leaves no event
				// behind and a crash after the commit still delivers one.
				BookingEventPublisher::RecordTransaction($transactionId);
			});

			return $transactionId;
		}
		else
		{
			throw new \Exception("Transaction type $transactionType is not valid (StockService.AddProduct)");
		}
	}

	/**
	 * Issues a stock_entry label for a just-booked entry and enqueues its print job, in the
	 * caller's own transaction - the print job outbox is transactional by design (ADR-0011
	 * decision 4), unlike the webhook this replaces which fired only after commit.
	 *
	 * Question 3's proposed answer: the purchase form names no printer, so this resolves to
	 * the default printer (the first active one) and the kind's default template, the same
	 * way LabelOperationsService::ResolvePrinter() and ResolveTemplate() already do when a
	 * caller names neither.
	 */
	private function IssueStockEntryLabel(int $stockEntryId): void
	{
		$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$epoch = (int)$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		(new \Victual\Services\Labels\LabelOperationsService($db))
			->IssueLocation('stock_entry', $stockEntryId, $epoch, null, null, null, VICTUAL_LOCALE, date_default_timezone_get());
	}

	/**
	 * Reprints a stock entry's label to reflect a due date auto_reprint_stock_label just
	 * changed (opening or a freeze/thaw transfer), if - and only if - the entry already
	 * carries a live label.
	 *
	 * The old webhook fired unconditionally, because it had no notion of a label's identity
	 * or history to consult. This one does, and "reprint" is what the setting is named for: an
	 * entry nobody has printed a label for is not brought into the label subsystem by a due
	 * date shifting under it. Only an entry someone already labeled gets kept current.
	 */
	private function ReviseStockEntryLabelIfLive(int $stockEntryId): void
	{
		$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
		$query = $db->prepare("SELECT 1 FROM labels WHERE kind='stock_entry' AND target_id=? AND retired_at IS NULL");
		$query->execute([$stockEntryId]);
		if ($query->fetchColumn() === false) {
			return;
		}
		$epoch = (int)$db->query('SELECT epoch FROM label_import_state WHERE id = 1')->fetchColumn();
		(new \Victual\Services\Labels\LabelOperationsService($db))
			->RevisedPrint('stock_entry', $stockEntryId, $epoch, null, null, null, VICTUAL_LOCALE, date_default_timezone_get());
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
	 * The product-level tare weight mechanism this paragraph used to describe is retired under
	 * ADR-0022 decisions 4 and 7 (2026-09-14): $amount is always the net amount to consume now,
	 * and $consumeExactAmount has no effect (kept on the signature for wire compatibility with
	 * existing callers that still pass exact_amount; see docs/plans/landed/28-open-container-measurement.md).
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
	 * @param bool $consumeExactAmount Retired with the product-level tare mechanism (ADR-0022); has no effect
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

		if ($transactionType === self::TRANSACTION_TYPE_CONSUME || $transactionType === self::TRANSACTION_TYPE_INVENTORY_CORRECTION)
		{
			if ($transactionId === null)
			{
				$transactionId = uniqid();
			}

			// One booking per touched stock entry, each paired with a delete or an amount
			// update - so `stock` and `stock_log` can only ever agree if all of them land.
			DatabaseService::GetInstance()->InTransaction(function () use ($amount, $productId, $spoiled, $transactionType, $recipeId, $allowSubproductSubstitution, $locationId, $specificStockEntryId, &$transactionId)
			{
				// Locked, then read (issue #458): reading the candidate entries and the
				// aggregated-amount check before the lock would let two concurrent consumes
				// both read the same pre-write state, both pass a check only one of them
				// should, and together consume more than was in stock. With substitution
				// on, GetProductStockEntries() below can return sub product rows too, so
				// every sub product is locked in the same call, ascending, alongside
				// $productId (PR #471 follow-up) - a lock taken on just $productId would
				// leave a direct booking of the sub product unlocked against this one, and
				// a nested multi-product caller (ConsumeRecipe()) locking sub products
				// individually after its own ascending set would risk a lock order
				// inversion against this call.
				if ($allowSubproductSubstitution)
				{
					DatabaseService::GetInstance()->LockProductsStock($this->SubstitutionLockSet($productId));
				}
				else
				{
					DatabaseService::GetInstance()->LockProductStock($productId);
				}

				$productDetails = (object)$this->GetProductDetails($productId);

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
						// Take the whole stock entry. The four opened_* columns are mirrored
						// onto the booking (ADR-0022 decision 9) so undoing this consume can
						// rebuild the deleted row with its measurement intact - see
						// UndoBooking()'s TRANSACTION_TYPE_CONSUME branch.
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
							'shopping_location_id' => $stockEntry->shopping_location_id,
							'opened_amount' => $stockEntry->opened_amount,
							'opened_qu_id' => $stockEntry->opened_qu_id,
							'opened_tare' => $stockEntry->opened_tare,
							'opened_measured_at' => $stockEntry->opened_measured_at
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

						// A partial consume of a measured entry always leaves an amount other
						// than 1 - the entry could only be measured in the first place with
						// amount = 1 (ADR-0022 decision 8) - so the measurement is dropped
						// rather than left to violate the coherence CHECK. Re-measuring the
						// remaining container is MeasureStockEntry()'s job, not this one's.
						$measurementClear = $stockEntry->opened_amount !== null ? [
							'opened_amount' => null,
							'opened_qu_id' => null,
							'opened_tare' => null,
							'opened_measured_at' => null,
						] : [];

						$stockEntry->update(array_merge([
							'amount' => $restStockAmount
						], $measurementClear));

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
	 * A measurement already on the entry (ADR-0022 decisions 1, 8, 9) is carried through
	 * unchanged when the edit leaves it coherent (still open = 1, amount = 1), and dropped -
	 * all four opened_* columns cleared - the moment it would not be, since the new amount or
	 * open state would otherwise violate the coherence CHECK on `stock`. This is deliberate,
	 * not incidental: an edit is not the place to carry a remainder across a change in what
	 * the row describes. The OLD log row mirrors whatever the entry carried before the edit,
	 * so an undo of TRANSACTION_TYPE_STOCK_EDIT_OLD restores it exactly - including a
	 * measurement this edit itself dropped.
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

		$productId = $stockRow->product_id;
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
			$stockRowId, $productId, $correlationId, $transactionId, $amount, $bestBeforeDate, $locationId,
			$shoppingLocationId, $price, $open, $purchasedDate, $note
		)
		{
			// Locked, then re-read (issue #458): the row fetched above this transaction can
			// have been changed, or the entry removed outright, by a concurrent booking
			// before this lock was taken.
			DatabaseService::GetInstance()->LockProductStock($productId);

			$stockRow = $this->DB->stock()->where('id = :1', $stockRowId)->fetch();
			if ($stockRow === null)
			{
				throw new \Exception('Stock does not exist');
			}

			// Whether the edited state still permits the measurement (if any) this entry
			// already carries. round() guards the float amount comparison the CHECK itself
			// does exactly.
			$staysCoherent = boolval($open) && round($amount, 2) == 1.0;

			$measurementBefore = [
				'opened_amount' => $stockRow->opened_amount,
				'opened_qu_id' => $stockRow->opened_qu_id,
				'opened_tare' => $stockRow->opened_tare,
				'opened_measured_at' => $stockRow->opened_measured_at,
			];
			$measurementAfter = $staysCoherent ? $measurementBefore : [
				'opened_amount' => null,
				'opened_qu_id' => null,
				'opened_tare' => null,
				'opened_measured_at' => null,
			];

			$logOldRowForStockUpdate = $this->DB->stock_log()->createRow(array_merge([
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
			], $measurementBefore));
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

			$stockRow->update(array_merge([
				'amount' => $amount,
				'price' => $price,
				'best_before_date' => $bestBeforeDate,
				'location_id' => $locationId,
				'shopping_location_id' => $shoppingLocationId,
				'opened_date' => $openedDate,
				'open' => BoolToInt($open),
				'purchased_date' => $purchasedDate,
				'note' => $note
			], $measurementAfter));

			$logNewRowForStockUpdate = $this->DB->stock_log()->createRow(array_merge([
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
			], $measurementAfter));
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
	 * Records a new measurement of an already-open, single-unit stock entry (re-measuring a
	 * container over its life - ADR-0022 decision 6, this plan's open question 1). This is
	 * deliberately not an amount edit: stock.amount, the container count, never changes here,
	 * and the change is recorded as a reversible before/after pair - the review recommendation
	 * for open question 2 - rather than inferred as a consumption or updated silently.
	 *
	 * Requires the entry to already be a coherent single opened container (open = 1,
	 * amount = 1); a fresh measurement on a container being opened for the first time goes
	 * through OpenProduct()'s own $measurement parameter instead, since only that method knows
	 * whether opening will leave the entry at amount = 1.
	 *
	 * @param int $stockRowId The `stock` table row id (not the stock_id)
	 * @param array $measurement ['amount' => float, 'qu_id' => int, 'tare' => float|null, 'is_gross' => bool]
	 * @return string The transaction id of the booking pair
	 * @throws \Exception When the stock entry does not exist, is not a coherent single opened
	 *                    container, or the measurement unit does not convert to the product's stock unit
	 */
	public function MeasureStockEntry(int $stockRowId, array $measurement)
	{
		$stockRow = $this->DB->stock()->where('id = :1', $stockRowId)->fetch();
		if ($stockRow === null)
		{
			throw new \Exception('Stock does not exist');
		}

		$productId = $stockRow->product_id;
		$correlationId = uniqid();
		$transactionId = uniqid();

		DatabaseService::GetInstance()->InTransaction(function () use (
			$stockRowId, $productId, $correlationId, $transactionId, $measurement
		)
		{
			// Locked, then re-read (issue #458): the coherence check below (open = 1,
			// amount = 1) has to see the committed state of any booking that touched this
			// entry first, not the possibly stale row fetched above this transaction.
			DatabaseService::GetInstance()->LockProductStock($productId);

			$stockRow = $this->DB->stock()->where('id = :1', $stockRowId)->fetch();
			if ($stockRow === null)
			{
				throw new \Exception('Stock does not exist');
			}

			if ($stockRow->open != 1 || round($stockRow->amount, 2) != 1.0)
			{
				throw new \Exception('Only a single opened container (open, amount = 1) can be measured');
			}

			$resolved = $this->ResolveMeasurement($stockRow->product_id, $measurement);

			$measurementBefore = [
				'opened_amount' => $stockRow->opened_amount,
				'opened_qu_id' => $stockRow->opened_qu_id,
				'opened_tare' => $stockRow->opened_tare,
				'opened_measured_at' => $stockRow->opened_measured_at,
			];

			$logOldRow = $this->DB->stock_log()->createRow(array_merge([
				'product_id' => $stockRow->product_id,
				'amount' => $stockRow->amount,
				'best_before_date' => $stockRow->best_before_date,
				'purchased_date' => $stockRow->purchased_date,
				'stock_id' => $stockRow->stock_id,
				'transaction_type' => self::TRANSACTION_TYPE_STOCK_MEASURED_OLD,
				'price' => $stockRow->price,
				'opened_date' => $stockRow->opened_date,
				'location_id' => $stockRow->location_id,
				'shopping_location_id' => $stockRow->shopping_location_id,
				'correlation_id' => $correlationId,
				'transaction_id' => $transactionId,
				'stock_row_id' => $stockRow->id,
				'user_id' => VICTUAL_USER_ID,
				'note' => $stockRow->note
			], $measurementBefore));
			$logOldRow->save();

			$stockRow->update($resolved);

			$logNewRow = $this->DB->stock_log()->createRow(array_merge([
				'product_id' => $stockRow->product_id,
				'amount' => $stockRow->amount,
				'best_before_date' => $stockRow->best_before_date,
				'purchased_date' => $stockRow->purchased_date,
				'stock_id' => $stockRow->stock_id,
				'transaction_type' => self::TRANSACTION_TYPE_STOCK_MEASURED_NEW,
				'price' => $stockRow->price,
				'opened_date' => $stockRow->opened_date,
				'location_id' => $stockRow->location_id,
				'shopping_location_id' => $stockRow->shopping_location_id,
				'correlation_id' => $correlationId,
				'transaction_id' => $transactionId,
				'stock_row_id' => $stockRow->id,
				'user_id' => VICTUAL_USER_ID,
				'note' => $stockRow->note
			], $resolved));
			$logNewRow->save();

			// Inside the transaction on purpose: the outbox row and the ledger rows commit
			// together or not at all, so a rolled back measurement leaves no event behind and
			// a crash after the commit still delivers one.
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
							// The extension may already be known from the URL's path. When it
							// is, and it fails the allow-list below, there is no reason to
							// fetch anything at all - checked first, so a disallowed
							// extension never reaches the host check or the network.
							$fileExtension = strtolower(pathinfo(parse_url($pluginOutput['__image_url'], PHP_URL_PATH), PATHINFO_EXTENSION));

							if ($fileExtension !== '' && !in_array($fileExtension, self::ALLOWED_PICTURE_EXTENSIONS, true))
							{
								$fileExtension = '';
							}
							else
							{
								// __image_url is chosen by the barcode source, not by this
								// deployment. $plugin->Fetch() (issue #460) is the shared seam
								// every outbound request this tree makes on a source's say-so
								// goes through: it resolves and refuses a loopback, private,
								// link-local or otherwise internal host before requesting
								// anything (sweep finding S14, docs/security-sweep.md, via
								// helpers/OutboundHostPolicy.php), pins the request to the
								// validated address for every host except an IPv6 literal -
								// which is never looked up, so there is nothing to pin, and
								// whose colons curl's host:port:address form cannot express -
								// does not follow redirects, and does not honour an
								// HTTP_PROXY/HTTPS_PROXY environment override - a proxy would
								// resolve the host itself and make the pin meaningless. Every
								// barcode-lookup request goes through the same Fetch() and so
								// ignores the environment proxy the same way, not only this
								// picture fetch: a deployment behind a mandatory outbound
								// proxy cannot use any barcode source at all, and this fetch
								// failing soft (unlike a source's own request, which is not
								// caught here) only means the picture step in particular does
								// not fail the whole add.
								$response = $plugin->Fetch($pluginOutput['__image_url']);

								if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300)
								{
									// Fallback to Content-Type header if the URL's path gave no extension
									if ($fileExtension === '' && $response->hasHeader('Content-Type'))
									{
										$fileExtension = strtolower(explode('+', explode('/', $response->getHeader('Content-Type')[0])[1])[0]);

										if (!in_array($fileExtension, self::ALLOWED_PICTURE_EXTENSIONS, true))
										{
											$fileExtension = '';
										}
									}

									if ($fileExtension !== '')
									{
										$imageData = $response->getBody();
									}
								}
								else
								{
									// A non-2xx response (a redirect, since allow_redirects is
									// off, or an error page http_errors let through) is never a
									// picture, whatever extension the URL implied.
									$fileExtension = '';
								}
							}
						}
						elseif (preg_match('/data:image\/(\w+?);base64,([A-Za-z0-9+\/]*={0,2})$/', $pluginOutput['__image_url'], $matches))
						{
							$fileExtension = strtolower($matches[1]);

							if (in_array($fileExtension, self::ALLOWED_PICTURE_EXTENSIONS, true) && !($imageData = base64_decode($matches[2])))
							{
								unset($imageData);
							}
						}

						if (!empty($fileExtension) && !empty($imageData) && in_array($fileExtension, self::ALLOWED_PICTURE_EXTENSIONS, true))
						{
							// Not written yet: writing it here, before the transaction
							// below, would leave an orphaned file in productpictures if
							// the transaction then rolled back. It is written only after
							// the transaction commits, once the product row it belongs to
							// is durable.
							$pictureFileName = $pluginOutput['__barcode'] . '.' . $fileExtension;
							$pictureData = (string)$imageData;
						}
					}
					catch (\Exception)
					{
						// Ignore - includes OutboundHostRefusedException, a plain \Exception
						// subclass: the picture step fails soft, same as a download error.
					}
				}

				DatabaseService::GetInstance()->InTransaction(function () use ($productData, $pluginOutput, &$newProductRow)
				{
					$newProductRow = $this->DB->products()->createRow($productData);
					$newProductRow->save();

					$this->DB->product_barcodes()->createRow([
						'product_id' => $newProductRow->id,
						'barcode' => $pluginOutput['__barcode']
					])->save();

					if ($pluginOutput['qu_id_stock'] != $pluginOutput['qu_id_purchase'])
					{
						// products_default_qu_conversions_INS only creates the 1:1
						// purchase->stock conversion for this product when no conversion
						// (including a global, product_id IS NULL one) already resolves
						// that unit pair. When it did create one, set the plugin's factor
						// onto that row instead of inserting a second one for the same
						// pair, which qu_conversions_custom_constraint_INS refuses as a
						// duplicate. When it did not - a global conversion already covers
						// the pair - insert the product-specific conversion ourselves, as
						// the old code did; that is accepted because the constraint keys
						// on (from_qu_id, to_qu_id, product_id) and a global row's
						// product_id is NULL, not this product's id.
						$conversionRow = $this->DB->quantity_unit_conversions()->where(
							'product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3',
							$newProductRow->id,
							$pluginOutput['qu_id_purchase'],
							$pluginOutput['qu_id_stock']
						)->fetch();

						if ($conversionRow !== null)
						{
							$conversionRow->update([
								'factor' => $pluginOutput['__qu_factor_purchase_to_stock'],
							]);
						}
						else
						{
							$this->DB->quantity_unit_conversions()->createRow([
								'product_id' => $newProductRow->id,
								'from_qu_id' => $pluginOutput['qu_id_purchase'],
								'to_qu_id' => $pluginOutput['qu_id_stock'],
								'factor' => $pluginOutput['__qu_factor_purchase_to_stock'],
							])->save();
						}
					}
				});

				// Written only now, after the transaction committed, and updated onto
				// the durable product row directly - not as part of the transaction
				// above, so a picture failure here still leaves the product without a
				// picture rather than failing the whole add.
				if (isset($pictureFileName) && isset($pictureData))
				{
					try
					{
						FileStorage::GetInstance()->Write('productpictures', $pictureFileName, $pictureData);
						$newProductRow->update(['picture_file_name' => $pictureFileName]);
					}
					catch (\Exception)
					{
						// Ignore
					}
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
	 * Returns every location with its display path and its level, in tree pre-order.
	 *
	 * This is the one method every dropdown, list and filter that renders a location reads,
	 * so that "Top shelf" in two different rooms is two distinguishable options rather than
	 * two identical ones. `path` is the location's names from its root joined by " / " and
	 * `level` is its distance from that root, 0 for a root. Both come from
	 * `locations_resolved` (migrations/0273.pgsql.sql): the path off the row that pairs a
	 * location with itself, the level off the largest depth it appears at as a descendant.
	 *
	 * Siblings are ordered by name through the `nocase` collation, which is what every other
	 * list page here orders by (db/pgsql/README.md hazard 15), and the walk below turns that
	 * flat order into pre-order without re-sorting anything.
	 *
	 * A row whose parent is not in the result - filtered out by $activeOnly, or pointing at
	 * an id no row answers to, which `parent_location_id` permits because it carries no
	 * foreign key - is walked as a root rather than dropped. Every row the query returned is
	 * returned exactly once, which is what makes this safe to build a <select> from.
	 *
	 * @param bool $activeOnly When true, only locations with active = 1 are returned
	 * @return array Array of row objects: the locations columns plus `path` and `level`
	 */
	public function GetLocationsWithPaths(bool $activeOnly = false)
	{
		$sql = 'SELECT l.*, self.path AS path, levels.level AS level
			FROM locations l
			JOIN locations_resolved self
				ON self.descendant_location_id = l.id
				AND self.ancestor_location_id = l.id
			JOIN (
				SELECT descendant_location_id, MAX(depth) AS level
				FROM locations_resolved
				GROUP BY descendant_location_id
			) levels
				ON levels.descendant_location_id = l.id';

		if ($activeOnly)
		{
			$sql .= ' WHERE l.active = 1';
		}

		$sql .= ' ORDER BY l.name COLLATE NOCASE';

		$rows = DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);

		$byParent = [];
		$present = [];

		foreach ($rows as $row)
		{
			$present[$row->id] = true;
		}

		foreach ($rows as $row)
		{
			$parent = $row->parent_location_id;
			$key = ($parent === null || !isset($present[$parent])) ? 0 : $parent;
			$byParent[$key][] = $row;
		}

		$ordered = [];
		$walk = function ($parentKey) use (&$walk, &$ordered, $byParent)
		{
			foreach ($byParent[$parentKey] ?? [] as $row)
			{
				$ordered[] = $row;
				$walk($row->id);
			}
		};
		$walk(0);

		return $ordered;
	}

	/**
	 * Returns, per location id, that location's id followed by every ancestor's id.
	 *
	 * This is what makes a location filter roll up: a product stocked at
	 * "Basement / StorageRoom / UprightFreezer / Door" has to match a filter set to
	 * "Basement", so the page renders the ancestors alongside the location itself and the
	 * filter matches on any of them. Plan 08 question 4 wanted the roll-up for filtering and
	 * not for the location content sheet, which still groups by the exact location id.
	 *
	 * @return array Map of location id => array of location ids, the location itself first
	 */
	public function GetLocationAncestorIds()
	{
		$sql = 'SELECT descendant_location_id, ancestor_location_id, depth
			FROM locations_resolved
			ORDER BY descendant_location_id, depth';

		$ancestors = [];

		foreach (DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ) as $row)
		{
			$ancestors[$row->descendant_location_id][] = intval($row->ancestor_location_id);
		}

		return $ancestors;
	}

	/**
	 * Returns every product group with a path (e.g. "Spices / Garlic / Fresh") and a level
	 * derived from product_groups_resolved, in tree pre-order with siblings ordered
	 * case-insensitively by name - the group tree's counterpart to GetLocationsWithPaths().
	 *
	 * @param bool $activeOnly When true, only active groups are returned
	 * @return array Array of row objects
	 */
	public function GetProductGroupsWithPaths(bool $activeOnly = false)
	{
		$sql = 'SELECT pg.*, self.path AS path, levels.level AS level
			FROM product_groups pg
			JOIN product_groups_resolved self
				ON self.descendant_product_group_id = pg.id
				AND self.ancestor_product_group_id = pg.id
			JOIN (
				SELECT descendant_product_group_id, MAX(depth) AS level
				FROM product_groups_resolved
				GROUP BY descendant_product_group_id
			) levels
				ON levels.descendant_product_group_id = pg.id';

		if ($activeOnly)
		{
			$sql .= ' WHERE pg.active = 1';
		}

		$sql .= ' ORDER BY pg.name COLLATE NOCASE';

		$rows = DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);

		$byParent = [];
		$present = [];

		foreach ($rows as $row)
		{
			$present[$row->id] = true;
		}

		foreach ($rows as $row)
		{
			$parent = $row->parent_product_group_id;
			$key = ($parent === null || !isset($present[$parent])) ? 0 : $parent;
			$byParent[$key][] = $row;
		}

		$ordered = [];
		$walk = function ($parentKey) use (&$walk, &$ordered, $byParent)
		{
			foreach ($byParent[$parentKey] ?? [] as $row)
			{
				$ordered[] = $row;
				$walk($row->id);
			}
		};
		$walk(0);

		return $ordered;
	}

	/**
	 * Returns, per product group id, that group's id followed by every ancestor's id -
	 * including itself, since product_groups_resolved's self row is depth 0. This is what
	 * lets the group form's parent picker exclude a group being edited and its whole subtree
	 * (StockController::ProductGroupEditForm()), the same way GetLocationAncestorIds() does
	 * for locations: the database refuses a cycle either way, but offering an option that is
	 * certain to be refused is a trap, not a form.
	 *
	 * @return array Array keyed by product group id, each value an array of ancestor ids
	 */
	public function GetProductGroupAncestorIds()
	{
		$sql = 'SELECT descendant_product_group_id, ancestor_product_group_id, depth
			FROM product_groups_resolved
			ORDER BY descendant_product_group_id, depth';

		$ancestors = [];

		foreach (DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ) as $row)
		{
			$ancestors[$row->descendant_product_group_id][] = intval($row->ancestor_product_group_id);
		}

		return $ancestors;
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
			$stockCurrentRow->amount_measured = 0;
		}

		$detailsRow = $this->DB->uihelper_product_details()->where('id', $productId)->fetch();
		$product = $this->DB->products($productId);
		$productBarcodes = $this->DB->product_barcodes()->where('product_id', $productId)->fetchAll();
		$quPurchase = $this->DB->quantity_units($product->qu_id_purchase);
		$quStock = $this->DB->quantity_units($product->qu_id_stock);
		$quConsume = $this->DB->quantity_units($product->qu_id_consume);
		$quPrice = $this->DB->quantity_units($product->qu_id_price);
		$location = $this->DB->locations()->select('id, name, description, row_created_timestamp, is_freezer, active')->where('id', $product->location_id)->fetch();

		$defaultConsumeLocation = null;
		if (!empty($product->default_consume_location_id))
		{
			$defaultConsumeLocation = $this->DB->locations()->select('id, name, description, row_created_timestamp, is_freezer, active')->where('id', $product->default_consume_location_id)->fetch();
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
			// The measured (not merely counted) contents across this product's opened,
			// measured entries, in the stock unit - additive, ADR-0022 decision 2 and this
			// plan's open question 3. Left beside stock_amount* rather than folded into them;
			// see migrations/0275.pgsql.sql. ?? 0 rather than a bare property read: above
			// SQLITE_FROZEN_MIGRATION_ID this column exists on PostgreSQL's stock_current
			// only, so a real row read against SQLite (still a legitimate way to run this
			// fork locally, and what the differential suite's rollback phase does) has no
			// such property at all.
			'stock_amount_measured' => $stockCurrentRow->amount_measured ?? 0,
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
			'qu_conversion_factor_price_to_stock' => $detailsRow->qu_factor_price_to_stock,
			// What on hand will do where this product is called for (plan 31, issue 125) -
			// direct edges and the existing parent/child mechanism, unioned by
			// product_substitutions_resolved (migrations/0279.pgsql.sql). Ordered by whether
			// the candidate is actually in stock, then by its own earliest best-before date -
			// there is no cross-source priority between 'directed' and 'shared_parent', per
			// the plan's own open question on ordering. GetProductDetails() runs on both
			// engines (AddProduct() calls it after every product creation, SQLite included -
			// see .devtools/pgsql/rollback-tests.php), but the view is PostgreSQL-only, above
			// SQLITE_FROZEN_MIGRATION_ID, the same reason stock_amount_measured is guarded a
			// few lines up - an empty list on SQLite rather than a query against a table that
			// engine never gets.
			'substitution_candidates' => DatabaseService::GetInstance()->GetDialect()->GetName() === 'pgsql'
				? $this->DB->product_substitutions_resolved()
					->where('to_product_id', $productId)
					->orderBy('from_product_amount_in_stock', 'DESC')
					->orderBy('from_product_best_before_date', 'ASC')
					->fetchAll()
				: []
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
	 * $productId plus every sub product id GetProductStockEntries() and
	 * GetProductStockEntriesForLocation() would draw into a substitution-aware read of it
	 * (products_resolved, the same source those two methods use) - the set a
	 * substitution-aware booking has to lock as one unit before touching any of them
	 * (issue #458, PR #471 follow-up).
	 *
	 * A caller with substitution off never reads or writes another product's stock, so it
	 * has no reason to call this: DatabaseService::LockProductStock($productId) alone is
	 * correct there, and locking a whole family it never touches would only make it wait
	 * on bookings of products it has nothing to do with.
	 *
	 * @param int $productId
	 * @return int[] $productId and its sub product ids, in no particular order -
	 *               DatabaseService::LockProductsStock() sorts them
	 */
	public function SubstitutionLockSet(int $productId): array
	{
		$subProductIds = array_map('intval', DatabaseService::GetInstance()->ExecuteDbQuery(
			'SELECT sub_product_id FROM products_resolved WHERE parent_product_id = ?',
			[$productId]
		)->fetchAll(\PDO::FETCH_COLUMN));

		$subProductIds[] = $productId;

		return $subProductIds;
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

		// The whole decision has to be made and acted on under one lock (issue #458):
		// reading stock_amount, deciding whether and by how much to add or consume, and
		// booking that difference are one read-then-write unit. Locking only around the
		// AddProduct()/ConsumeProduct() delegation below - as this used to - still lets a
		// concurrent booking change stock_amount between the read above and the lock,
		// so the correction is computed against state that is no longer current by the
		// time it is applied.
		return DatabaseService::GetInstance()->InTransaction(function () use ($productId, $newAmount, $bestBeforeDate, $locationId, $price, $shoppingLocationId, $purchasedDate, $stockLabelType, $note)
		{
			DatabaseService::GetInstance()->LockProductStock($productId);

			$productDetails = (object)$this->GetProductDetails($productId);

			$resolvedPrice = $price;
			if ($resolvedPrice === null)
			{
				$resolvedPrice = $productDetails->last_price;
			}

			$resolvedShoppingLocationId = $shoppingLocationId;
			if ($resolvedShoppingLocationId === null)
			{
				$resolvedShoppingLocationId = $productDetails->last_shopping_location_id;
			}

			$resolvedPurchasedDate = $purchasedDate;
			if ($resolvedPurchasedDate == null)
			{
				$resolvedPurchasedDate = date('Y-m-d');
			}

			// Product-level tare weight handling (the gross-reading passthrough this used to
			// describe) is retired under ADR-0022 decisions 4 and 7 (2026-09-14); see AddProduct()
			// and ConsumeProduct(). $newAmount is always the net counted total now.
			if ($newAmount == $productDetails->stock_amount)
			{
				throw new \Exception('The new amount cannot equal the current stock amount');
			}
			elseif ($newAmount > $productDetails->stock_amount)
			{
				$bookingAmount = $newAmount - $productDetails->stock_amount;

				return $this->AddProduct($productId, $bookingAmount, $bestBeforeDate, self::TRANSACTION_TYPE_INVENTORY_CORRECTION, $resolvedPurchasedDate, $resolvedPrice, $locationId, $resolvedShoppingLocationId, $unusedTransactionId, $stockLabelType, $note);
			}
			elseif ($newAmount < $productDetails->stock_amount)
			{
				$bookingAmount = $productDetails->stock_amount - $newAmount;

				return $this->ConsumeProduct($productId, $bookingAmount, false, self::TRANSACTION_TYPE_INVENTORY_CORRECTION);
			}

			return null;
		});
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
	 * $measurement, when given, records the container's contents as it is opened (ADR-0022
	 * decisions 1, 3, 4, 8; docs/plans/landed/28-open-container-measurement.md). It requires
	 * $specificStockEntryId naming one entry and $amount = 1.0 - opening exactly one container
	 * - because a measurement describes exactly one container and the coherence constraint on
	 * `stock` enforces open = 1 AND amount = 1 wherever one is attached. Shape:
	 * ['amount' => float, 'qu_id' => int, 'tare' => float|null, 'is_gross' => bool]. A gross
	 * reading has its tare subtracted before storage, so $productDetails and the ledger always
	 * carry a net opened_amount; see ResolveMeasurement().
	 *
	 * @param int $productId
	 * @param float $amount Amount to open, in the product's stock quantity unit
	 * @param string $specificStockEntryId 'default' opens in default order; otherwise a stock_id restricting opening to that single stock entry
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @param bool $allowSubproductSubstitution When true, unopened stock of resolved sub products may be opened
	 * @param array|null $measurement See above
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product does not exist, has opening disabled, the amount exceeds the
	 *                    current unopened (aggregated) stock amount, a measurement is given without
	 *                    targeting a specific single-unit entry, or a measurement's unit does not
	 *                    convert to the product's stock unit
	 */
	public function OpenProduct(int $productId, float $amount, $specificStockEntryId = 'default', &$transactionId = null, $allowSubproductSubstitution = false, ?array $measurement = null)
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

		if ($transactionId === null)
		{
			$transactionId = uniqid();
		}

		// The booking and the stock entry it describes (and the split-off rest entry) have to
		// land together, or the ledger records an opening that stock does not show. The
		// unopened-amount check and the candidate entries below move inside the lock for the
		// same reason (issue #458): reading them before the lock would let a concurrent
		// booking change what is actually unopened before this call decided against it.
		DatabaseService::GetInstance()->InTransaction(function () use ($amount, $product, $productId, $allowSubproductSubstitution, $specificStockEntryId, $measurement, &$transactionId)
		{
			// With substitution on, GetProductStockEntries() below can return sub product
			// rows too, and "move on open" further down can transfer one of them - so every
			// sub product is locked in the same call, ascending, alongside $productId
			// (PR #471 follow-up on issue #458), for the same reason ConsumeProduct() does.
			if ($allowSubproductSubstitution)
			{
				DatabaseService::GetInstance()->LockProductsStock($this->SubstitutionLockSet($productId));
			}
			else
			{
				DatabaseService::GetInstance()->LockProductStock($productId);
			}

			$productDetails = (object)$this->GetProductDetails($productId);
			$productStockAmountUnopened = $productDetails->stock_amount_aggregated - $productDetails->stock_amount_opened_aggregated;
			$potentialStockEntries = $this->GetProductStockEntries($productId, true, $allowSubproductSubstitution);

			if ($amount > $productStockAmountUnopened)
			{
				throw new \Exception('Amount to be opened cannot be > current unopened stock amount');
			}

			if ($specificStockEntryId !== 'default')
			{
				$potentialStockEntries = FindAllObjectsInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
			}

			$resolvedMeasurement = null;
			if ($measurement !== null)
			{
				// Coherence (ADR-0022 decision 8): a measurement describes exactly one container,
				// so this call has to name that one entry and open exactly one unit of it. The
				// entry also has to already hold >= 1 unit, or opening it fully would leave an
				// amount other than 1 - see 0275.pgsql.sql's coherence CHECK, and the spike's
				// prerequisite 5 (.spike-adr22/RESULTS.md#prerequisite-5-container-identity).
				if ($specificStockEntryId === 'default')
				{
					throw new \Exception('A measurement requires opening a specific stock entry');
				}

				if (round($amount, 2) != 1.0)
				{
					throw new \Exception('A measurement requires opening exactly one unit');
				}

				$targetEntry = FindObjectInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
				if ($targetEntry === null || round($targetEntry->amount, 2) < 1.0)
				{
					throw new \Exception('This stock entry cannot be opened as a single measured container');
				}

				$resolvedMeasurement = $this->ResolveMeasurement($productId, $measurement);
			}

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

					if (VICTUAL_FEATURE_FLAG_LABELS && $productDetails->product->auto_reprint_stock_label == 1 && $newBestBeforeDate != $stockEntry->best_before_date)
					{
						$this->ReviseStockEntryLabelIfLive((int)$stockEntry->id);
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

				// Attaches only to the one entry named by $specificStockEntryId - the coherence
				// pre-checks above guarantee this entry ends the branch below at amount = 1.
				$measurementColumns = [];
				if ($resolvedMeasurement !== null && $stockEntry->stock_id === $specificStockEntryId)
				{
					$measurementColumns = [
						'opened_amount' => $resolvedMeasurement['opened_amount'],
						'opened_qu_id' => $resolvedMeasurement['opened_qu_id'],
						'opened_tare' => $resolvedMeasurement['opened_tare'],
						'opened_measured_at' => $resolvedMeasurement['opened_measured_at'],
					];
				}

				if ($amount >= $stockEntry->amount)
				{
					// Mark the whole stock entry as opened
					$logRow = $this->DB->stock_log()->createRow(array_merge([
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
					], $measurementColumns));
					$logRow->save();

					$stockEntry->update(array_merge([
						'open' => 1,
						'opened_date' => date('Y-m-d'),
						'best_before_date' => $newBestBeforeDate
					], $measurementColumns));

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

					$logRow = $this->DB->stock_log()->createRow(array_merge([
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
					], $measurementColumns));
					$logRow->save();

					$stockEntry->update(array_merge([
						'amount' => $amount,
						'open' => 1,
						'opened_date' => date('Y-m-d'),
						'best_before_date' => $newBestBeforeDate
					], $measurementColumns));

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

		$productRow = $this->DB->shopping_list()->where('product_id = :1 AND shopping_list_id = :2', $productId, $listId)->fetch();

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

		// The product-level tare mechanism's refusal used to sit here: ADR-0022 decision 7
		// retires enable_tare_weight_handling's arithmetic entirely, and this plan owns
		// removing this refusal specifically (plan 28 owns OpenProduct()'s, on a concurrent
		// branch) because backstock feeding a vessel needs the transfer to work. A tare-
		// enabled product's amount is no longer reinterpreted as a gross weight here - the
		// field stays on the wire at zero per decision 7, and weighing a vessel goes through
		// the location-scoped tare in WeighLocation() below instead, which corrects one stock
		// entry after the transfer has already moved the (untared) amount.
		if ($transactionId === null)
		{
			$transactionId = uniqid();
		}

		// Both bookings of an entry plus the stock row itself have to land together, or the
		// stock ends up split across the two locations. The source-location amount check and
		// the candidate entries below move inside the lock for the same reason (issue #458):
		// reading them before the lock would let a concurrent booking change what is
		// actually at the source location before this call decided against it.
		DatabaseService::GetInstance()->InTransaction(function () use ($amount, $productId, $locationIdFrom, $locationIdTo, $specificStockEntryId, &$transactionId)
		{
			DatabaseService::GetInstance()->LockProductStock($productId);

			$productDetails = (object)$this->GetProductDetails($productId);

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

					if (VICTUAL_FEATURE_FLAG_LABELS && $productDetails->product->auto_reprint_stock_label == 1 && $stockEntry->best_before_date != $newBestBeforeDate)
					{
						$this->ReviseStockEntryLabelIfLive((int)$stockEntry->id);
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

		return $transactionId;
	}

	/**
	 * Weighs a vessel (a bin, a spice jar - a location that stock passes through rather than
	 * arrives in) and corrects its one stock entry to match, per ADR-0022 decision 4's
	 * location-scoped tare and docs/plans/landed/29-working-container-replenishment.md.
	 *
	 * The device posts a gross reading in the location's own tare unit; this method
	 * subtracts the location's tare weight, converts the net remainder into the stocked
	 * product's stock unit through cache__quantity_unit_conversions_resolved - the same
	 * per-product conversion cache every other write path in this class reads (ADR-0022
	 * decision 3), refusing rather than assuming when no conversion path exists - and hands
	 * the result to EditStockEntry(), which does no tare arithmetic of its own and simply
	 * sets the entry's amount, exactly as it does for a human-entered correction.
	 *
	 * Refuses when the location has no tare configured, when zero or more than one distinct
	 * product is stocked there (weighing a shared shelf makes no sense - a vessel holds one
	 * product), or when more than one stock entry for that product remains at the location
	 * after compaction (weighing one physical container requires one row to correct).
	 *
	 * @param int $locationId
	 * @param float $grossAmount The gross reading, in the location's own tare_qu_id.
	 * @param int|null $grossQuId When given, must equal the location's tare_qu_id - present
	 *                            so a client's unit mismatch is refused rather than silently
	 *                            misweighed, per ADR-0022 question 5's "gross" contract.
	 * @return string The transaction id of the resulting stock edit.
	 * @throws \Exception When the location, its tare, or a single correctable entry cannot be resolved.
	 */
	public function WeighLocation(int $locationId, float $grossAmount, ?int $grossQuId = null): string
	{
		$location = $this->DB->locations()->where('id = :1 AND active = 1', $locationId)->fetch();
		if ($location === null)
		{
			throw new \Exception('Location does not exist or is inactive');
		}

		if ($location->tare_weight === null || $location->tare_qu_id === null)
		{
			throw new \Exception('This location has no tare configured, so it cannot be weighed as a vessel');
		}

		if ($grossQuId !== null && (int)$grossQuId !== (int)$location->tare_qu_id)
		{
			throw new \Exception('The gross reading must be given in the location\'s own tare unit');
		}

		$netInTareUnit = $grossAmount - $location->tare_weight;
		if ($netInTareUnit < 0)
		{
			throw new \Exception('The gross reading is less than the location\'s tare weight');
		}

		$stockAtLocation = $this->DB->stock()->where('location_id = :1', $locationId)->fetchAll();
		$productIds = array_unique(array_map(fn($row) => $row->product_id, $stockAtLocation));
		if (count($productIds) === 0)
		{
			throw new \Exception('No product is stocked at this location');
		}
		if (count($productIds) > 1)
		{
			throw new \Exception('More than one product is stocked at this location, so it cannot be weighed as a single vessel');
		}
		$productId = reset($productIds);

		// Everything from here on reads and rewrites this one product's stock rows -
		// compacting, counting the survivors, editing the one that remains - and has to see
		// one consistent, locked snapshot of them (issue #458): a concurrent booking of the
		// same product between any of these reads and the final edit could otherwise leave
		// this correction acting on a row count or amount that is no longer current.
		return DatabaseService::GetInstance()->InTransaction(function () use ($productId, $locationId, $netInTareUnit, $location)
		{
			DatabaseService::GetInstance()->LockProductStock($productId);

			// Re-checked under the lock: a concurrent transfer into or out of this location
			// could have changed which (or how many) products are stocked here since the
			// unlocked read above.
			$stockAtLocation = $this->DB->stock()->where('location_id = :1', $locationId)->fetchAll();
			$productIdsAtLocation = array_unique(array_map(fn($row) => $row->product_id, $stockAtLocation));
			if (count($productIdsAtLocation) === 0)
			{
				throw new \Exception('No product is stocked at this location');
			}
			if (count($productIdsAtLocation) > 1 || reset($productIdsAtLocation) != $productId)
			{
				throw new \Exception('More than one product is stocked at this location, so it cannot be weighed as a single vessel');
			}

			$productDetails = (object)$this->GetProductDetails($productId);
			$stockQuId = $productDetails->product->qu_id_stock;

			$conversion = $this->DB->cache__quantity_unit_conversions_resolved()
				->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $productId, $location->tare_qu_id, $stockQuId)
				->fetch();
			if ($conversion === null)
			{
				throw new \Exception('The location\'s tare unit cannot be converted to this product\'s stock unit');
			}

			$newAmount = $netInTareUnit * $conversion->factor;

			// A measured entry describes exactly one container (ADR-0022 decision 8's coherence
			// argument, applied here to a vessel rather than to an opened purchased container):
			// compaction first, so that ordinary backstock-fed refills - which each mint a new row
			// via TransferProduct() - collapse into the one row this correction can set the amount
			// of, rather than leaving the weighing refused by an accident of how many transfers
			// happened to run before it.
			$this->CompactStockEntries($productId);

			$stockRows = $this->DB->stock()->where('product_id = :1 AND location_id = :2', $productId, $locationId)->fetchAll();
			if (count($stockRows) !== 1)
			{
				throw new \Exception('This location does not hold exactly one stock entry to weigh');
			}
			$stockRow = $stockRows[0];

			return $this->EditStockEntry($stockRow->id, $newAmount, $stockRow->best_before_date, $locationId,
				$stockRow->shopping_location_id, $stockRow->price, $stockRow->open, $stockRow->purchased_date, $stockRow->note);
		});
	}

	/**
	 * Undoes a single stock_log booking by reversing its effect on the `stock` table,
	 * then marks it undone = 1 with an undone_timestamp (bookings are never deleted).
	 *
	 * Reversal per transaction type:
	 * - PURCHASE / SELF_PRODUCTION / positive INVENTORY_CORRECTION: this booking's own amount is
	 *   subtracted from the corresponding stock entry, deleting it only if that reaches zero (a
	 *   shared stock_id, from CompactStockEntries() merging same-day additions, can still hold
	 *   other live bookings' units)
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
	/**
	 * Marks a stock_log row undone, with the current timestamp. Every branch of
	 * UndoBooking() below reverses the booking's effect on `stock` differently, but ends
	 * the same way; factored out so the seven-plus repetitions of this pair do not drift
	 * from each other one at a time (plan 15-C10).
	 */
	private function MarkBookingUndone($logRow): void
	{
		$logRow->update([
			'undone' => 1,
			'undone_timestamp' => date('Y-m-d H:i:s')
		]);
	}

	public function UndoBooking($bookingId, $skipCorrelatedBookings = false)
	{
		// The whole thing - the "does it exist", "any subsequent booking depends on it" and
		// per-branch "does the row it would restore still exist" checks, and the reversal
		// itself - has to run under one lock (issue #458). The subsequent-bookings guard in
		// particular exists to stop a later booking's write from landing between this
		// check and this undo's own write; checking it before any lock or transaction opens
		// (as this used to) does not stop that at all, since a consume racing between the
		// check and the purchase branch's delete() below books against an entry this undo
		// is about to remove.
		DatabaseService::GetInstance()->InTransaction(function () use ($bookingId, $skipCorrelatedBookings)
		{
			$logRow = $this->DB->stock_log()->where('id = :1 AND undone = 0', $bookingId)->fetch();
			if ($logRow == null)
			{
				throw new \Exception('Booking does not exist or was already undone');
			}

			DatabaseService::GetInstance()->LockProductStock($logRow->product_id);

			// Re-read under the lock: a concurrent booking or undo of this product could
			// have already undone this row, or changed the state the checks below depend
			// on, between the unlocked read above and the lock being taken.
			$logRow = $this->DB->stock_log()->where('id = :1 AND undone = 0', $bookingId)->fetch();
			if ($logRow == null)
			{
				throw new \Exception('Booking does not exist or was already undone');
			}

			// Undo all correlated bookings first, in order from newest first to the oldest
			if (!$skipCorrelatedBookings && !empty($logRow->correlation_id))
			{
				$correlatedBookings = $this->DB->stock_log()->where('undone = 0 AND correlation_id = :1', $logRow->correlation_id)->orderBy('id', 'DESC')->fetchAll();

				// The correlated bookings (a stock edit's old/new pair, a transfer's from/to
				// pair) are only meaningful undone as a set - already covered by the outer
				// transaction and lock this call opened above.
				foreach ($correlatedBookings as $correlatedBooking)
				{
					$this->UndoBooking($correlatedBooking->id, true);
				}

				// Inside the transaction on purpose: the outbox row and the ledger rows
				// commit together or not at all, so a rolled back undo leaves no event
				// behind and a crash after the commit still delivers one.
				BookingEventPublisher::RecordTransaction($logRow->transaction_id);

				return;
			}

			// A booking can only be undone when it is the newest (not yet undone) one of its stock entry -
			// otherwise later bookings would reference stock state this undo would remove. This is a plain
			// stock_id/id/undone check: a booking's own correlated half never reaches here as a "subsequent"
			// booking, because the group-undo branch above already marks it undone (in id-descending order)
			// before this member's own check runs.
			$hasSubsequentBookings = $this->DB->stock_log()->where('stock_id = :1 AND id > :2 AND undone = 0', $logRow->stock_id, $logRow->id)->count() > 0;
			if ($hasSubsequentBookings)
			{
				throw new \Exception('Booking has subsequent dependent bookings, undo not possible');
			}

			if ($logRow->transaction_type === self::TRANSACTION_TYPE_CONSUME
				|| ($logRow->transaction_type === self::TRANSACTION_TYPE_INVENTORY_CORRECTION && $logRow->amount < 0)
				|| $logRow->transaction_type === self::TRANSACTION_TYPE_TRANSFER_FROM
				|| $logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_EDIT_OLD)
			{
				$this->LockUndoLocation($logRow->location_id);
			}

			// Every branch below reverses the booking's effect on `stock` and only then marks the
			// booking undone - a failure between those two writes would leave a booking whose
			// undone flag disagrees with the stock it was supposed to restore.
			if ($logRow->transaction_type === self::TRANSACTION_TYPE_PURCHASE || $logRow->transaction_type === self::TRANSACTION_TYPE_SELF_PRODUCTION || ($logRow->transaction_type === self::TRANSACTION_TYPE_INVENTORY_CORRECTION && $logRow->amount > 0))
			{
				// Subtract only this booking's own contribution, the way TRANSFER_TO already
				// does for a stock_id it shares with another location (below): CompactStockEntries()
				// (issue #457) can merge several same-day additions that agree on every grouping
				// column - product, due date, purchased date, price, open/opened_date, location,
				// shopping location - onto one stock_id, summing their amounts into a single row
				// and rewriting every stock_log row of the group to point at it. Deleting the whole
				// row here, as before, would take every other merged addition's units with it.
				//
				// Matched by stock_id and location together (not stock_id alone): the CONSUME/
				// negative-INVENTORY_CORRECTION branch below restores a fully-taken entry by
				// creating a *new* stock row rather than incrementing the one still there, so more
				// than one live row can already share a stock_id at the very same location without
				// any compaction being involved (a later purchase-undo hitting that state is exactly
				// testUndoAllowsAConsumeWithNoLaterDependents). Summing every row at this location
				// and comparing the total, rather than trusting a single row's amount, covers both
				// that case and the ordinary compacted-purchase one, where exactly one row matches.
				// A location match uses "IS NOT DISTINCT FROM" because location_id is nullable and
				// SQL's own "=" never matches NULL to NULL.
				$stockRows = $this->DB->stock()->where('stock_id = :1 AND location_id IS NOT DISTINCT FROM :2', $logRow->stock_id, $logRow->location_id)->fetchAll();
				if (count($stockRows) === 0)
				{
					throw new \Exception('Booking does not exist or was already undone');
				}

				$totalAmount = array_sum(array_map(fn($stockRow) => $stockRow->amount, $stockRows));
				$newAmount = $totalAmount - $logRow->amount;

				// stock.amount is a float column and CompactStockEntries() sums it in SQL, so an
				// exact `== 0` comparison here would miss by a rounding hair (e.g. purchases of
				// 0.1 and 0.2 merge to 0.30000000000000004) and leave a phantom near-zero row
				// behind. round() to two places is this file's existing convention for comparing
				// a float amount against a target (e.g. :590, :769, :893, :1851).
				$roundedNewAmount = round($newAmount, 2);

				if ($roundedNewAmount < 0)
				{
					// This booking's own amount is larger than what the matched row(s) currently
					// hold - something else has already reduced the entry below this purchase's
					// contribution - so there is nothing to correctly subtract it from.
					throw new \Exception('Booking cannot be undone: its stock entry holds less than this booking added');
				}

				if ($roundedNewAmount == 0)
				{
					foreach ($stockRows as $stockRow)
					{
						$stockRow->delete();
					}
				}
				elseif (count($stockRows) === 1)
				{
					$stockRows[0]->update([
						'amount' => $newAmount
					]);
				}
				else
				{
					// More than one row shares this stock_id and location, and what remains after
					// removing this booking's amount cannot be attributed to a single one of them
					// without guessing which row holds which purchase's units.
					throw new \Exception('Booking cannot be undone: its stock entry is split across multiple rows in a way that cannot be unambiguously reversed');
				}

				// Update log entry
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_CONSUME || ($logRow->transaction_type === self::TRANSACTION_TYPE_INVENTORY_CORRECTION && $logRow->amount < 0))
			{
				// Add corresponding amount back to stock. The four opened_* columns are
				// mirrored from $logRow (ADR-0022 decision 9) so a fully-consumed measured
				// entry - deleted from `stock` entirely - comes back with its remainder,
				// unit, tare and timestamp intact rather than reconstructed bare; see
				// .spike-adr22/RESULTS.md#prerequisite-6-undo.
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
					'shopping_location_id' => $logRow->shopping_location_id,
					'opened_amount' => $logRow->opened_amount,
					'opened_qu_id' => $logRow->opened_qu_id,
					'opened_tare' => $logRow->opened_tare,
					'opened_measured_at' => $logRow->opened_measured_at
				]);
				$stockRow->save();

				// Update log entry
				$this->MarkBookingUndone($logRow);
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
				$this->MarkBookingUndone($logRow);
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
						'location_id' => $logRow->location_id,
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
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_PRODUCT_OPENED)
			{
				// Remove opened flag from corresponding stock entry. Clearing all four
				// opened_* columns here is required, not merely stylistic (ADR-0022 decision
				// 9, sharpened by the spike): an unopened container has no remainder, and
				// leaving a measurement in place while clearing `open` would violate the
				// coherence CHECK outright and abort this very undo -
				// see .spike-adr22/RESULTS.md#prerequisite-6-undo.
				$stockRows = $this->DB->stock()->where('stock_id = :1 AND amount = :2 AND purchased_date = :3', $logRow->stock_id, $logRow->amount, $logRow->purchased_date)->limit(1);
				$stockRows->update([
					'open' => 0,
					'opened_date' => null,
					'best_before_date' => $logRow->best_before_date, // Is only relevant when the product has "Default due days after opened", but also doesn't hurt for other products
					'opened_amount' => null,
					'opened_qu_id' => null,
					'opened_tare' => null,
					'opened_measured_at' => null
				]);

				// Update log entry
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_EDIT_NEW)
			{
				// Update log entry, no action needed
				$this->MarkBookingUndone($logRow);
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
					'note' => $logRow->note,
					// Restores whatever measurement (if any) EditStockEntry() found on the
					// entry before this edit - including one the edit itself dropped for
					// coherence. See EditStockEntry()'s own comment.
					'opened_amount' => $logRow->opened_amount,
					'opened_qu_id' => $logRow->opened_qu_id,
					'opened_tare' => $logRow->opened_tare,
					'opened_measured_at' => $logRow->opened_measured_at
				]);

				// Update log entry
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_MEASURED_NEW)
			{
				// Update log entry, no action needed - undoing the correlated OLD booking
				// (below) is what actually restores the prior measurement.
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_STOCK_MEASURED_OLD)
			{
				$stockRow = $this->DB->stock()->where('id = :1', $logRow->stock_row_id)->fetch();

				if ($stockRow == null)
				{
					throw new \Exception('Booking does not exist or was already undone');
				}

				// Only the measurement columns are touched - MeasureStockEntry() never
				// changes amount, dates, price, location or note, so there is nothing else to
				// restore, and touching them here could clobber changes made by some other
				// booking on this entry since.
				$stockRow->update([
					'opened_amount' => $logRow->opened_amount,
					'opened_qu_id' => $logRow->opened_qu_id,
					'opened_tare' => $logRow->opened_tare,
					'opened_measured_at' => $logRow->opened_measured_at
				]);

				// Update log entry
				$this->MarkBookingUndone($logRow);
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
			// A transaction id can group bookings of more than one product (a recipe
			// consumption books every ingredient under one transaction id), so every
			// product touched is locked in ascending order before any of them is undone -
			// otherwise two transactions undone concurrently over an overlapping product
			// set, read in different orders, could deadlock rather than one simply waiting
			// for the other (issue #458).
			DatabaseService::GetInstance()->LockProductsStock(array_map(fn($booking) => $booking->product_id, $transactionBookings));

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
			// Both products' stock rows are read and rewritten below, so both are locked,
			// in ascending order, before either is touched (issue #458) - the ascending
			// order is what keeps a merge running concurrently with another one over an
			// overlapping product pair from deadlocking instead of simply queuing.
			DatabaseService::GetInstance()->LockProductsStock([$productIdToKeep, $productIdToRemove]);

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

			// product_substitutions is not in trg_cascade_product_removal's list of tables
			// this method itself re-points before deleting - it is that trigger's own list,
			// fired by the DELETE below, and left unguarded it would silently drop every edge
			// naming the removed product rather than carrying it over to the kept one. Two
			// passes before repointing, both required because trg_cascade_product_removal's
			// own delete happens after this method's UPDATE statements, not instead of them:
			// first, an edge between the two products being merged would become a self-edge
			// once repointed (refused by product_substitutions_no_self_edge), so it is dropped
			// outright rather than carried over in either direction; second, an edge from or
			// to the removed product that would duplicate one the kept product already has
			// (refused by product_substitutions_pair_key) is dropped rather than repointed,
			// so the kept product's real edge - not a copy of it - is what survives.
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM product_substitutions WHERE (from_product_id = ' . $productIdToRemove . ' AND to_product_id = ' . $productIdToKeep . ') OR (from_product_id = ' . $productIdToKeep . ' AND to_product_id = ' . $productIdToRemove . ')');
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM product_substitutions ps_remove WHERE ps_remove.from_product_id = ' . $productIdToRemove . ' AND EXISTS (SELECT 1 FROM product_substitutions ps_keep WHERE ps_keep.from_product_id = ' . $productIdToKeep . ' AND ps_keep.to_product_id = ps_remove.to_product_id)');
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM product_substitutions ps_remove WHERE ps_remove.to_product_id = ' . $productIdToRemove . ' AND EXISTS (SELECT 1 FROM product_substitutions ps_keep WHERE ps_keep.to_product_id = ' . $productIdToKeep . ' AND ps_keep.from_product_id = ps_remove.from_product_id)');
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE product_substitutions SET from_product_id = ' . $productIdToKeep . ' WHERE from_product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE product_substitutions SET to_product_id = ' . $productIdToKeep . ' WHERE to_product_id = ' . $productIdToRemove);

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
		// Only which products have anything to compact is read before any lock - what
		// each one's groups actually are (total_amount, stock_id_group, id_group) is
		// re-read fresh, under that product's own lock, below. Reading a group's contents
		// this early and writing them after locking - as this used to - lets a booking
		// that commits while compaction waits on the lock get silently overwritten:
		// stock.amount would be set back to a total that no longer includes what the
		// booking just consumed or added (issue #458, CodeRabbit follow-up on PR #471).
		if ($productId !== null)
		{
			$productIds = [(int)$productId];
		}
		else
		{
			$productIds = array_map('intval', DatabaseService::GetInstance()->ExecuteDbQuery('SELECT DISTINCT product_id FROM stock_splits')->fetchAll(\PDO::FETCH_COLUMN));
		}

		// Ascending, so compacting several products in one call cannot deadlock against
		// another multi-product caller (MergeProducts(), ConsumeRecipe()) over an
		// overlapping set.
		sort($productIds);

		foreach ($productIds as $oneProductId)
		{
			DatabaseService::GetInstance()->InTransaction(function () use ($oneProductId)
			{
				DatabaseService::GetInstance()->LockProductStock($oneProductId);

				// The re-read this fix is about: every group belonging to this product,
				// current as of right now under the lock rather than from before it.
				$splittedStockEntries = $this->DB->stock_splits()->where('product_id = :1', $oneProductId)->fetchAll();

				foreach ($splittedStockEntries as $splittedStockEntry)
				{
					$stockIds = explode(',', $splittedStockEntry->stock_id_group);
					foreach ($stockIds as $stockId)
					{
						if ($stockId != $splittedStockEntry->stock_id_to_keep)
						{
							DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock SET stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE stock_id = \'' . $stockId . '\'');
							DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_log SET stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE stock_id = \'' . $stockId . '\'');

							// The split lineage moves with the stock_ids above, or it would point
							// at an entry that no longer exists. Three statements, and the order
							// is load-bearing.
							//
							// First the disappearing entry's own row goes rather than being
							// rewritten: what survives the merge is one entry, and it keeps the
							// origin it already had.
							//
							// Then the row, if any, that would be left describing the surviving
							// entry as split off itself. The third statement is about to point
							// everything that descended from the disappearing entry at the
							// surviving one - which is right, because that is where the
							// disappearing entry's bookings just went - and the surviving entry
							// may be one of those descendants. Once its origin's bookings are its
							// own, it is its own origin and the row says nothing; leaving it to be
							// rewritten instead would violate CHECK (stock_id <> origin_stock_id)
							// and abort the whole compaction, and cleaning it up afterwards is not
							// possible for the same reason - the constraint rejects the row the
							// moment the update tries to write it, so no later DELETE can reach it.
							DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM stock_entry_origins WHERE stock_id = \'' . $stockId . '\'');
							DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM stock_entry_origins WHERE origin_stock_id = \'' . $stockId . '\' AND stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\'');
							DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_entry_origins SET origin_stock_id = \'' . $splittedStockEntry->stock_id_to_keep . '\' WHERE origin_stock_id = \'' . $stockId . '\'');
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
	 * Resolves a raw measurement input into the net amount/tare pair stored on `stock` and
	 * `stock_log` (ADR-0022 decisions 3 and 4). $measurement['amount'] is the reading in
	 * $measurement['qu_id']; when $measurement['is_gross'] is true, $measurement['tare'] (in
	 * the same unit) is required and subtracted before storage, so opened_amount is always
	 * net contents and opened_tare records what was subtracted (null for a net reading).
	 *
	 * Convertibility (decision 3) is checked here, against cache__quantity_unit_conversions_resolved,
	 * because it cannot be a database CHECK: it depends on a value the recursive conversions
	 * view computes, not on the row being written. This is deliberately a different property
	 * than the coherence CHECK on `stock` (one container, one unit) - see
	 * .spike-adr22/RESULTS.md#prerequisite-7-conversion-failure, which is where this
	 * distinction was first demonstrated.
	 *
	 * @param int $productId
	 * @param array $measurement ['amount' => float, 'qu_id' => int, 'tare' => float|null, 'is_gross' => bool]
	 * @return array ['opened_amount' => float, 'opened_qu_id' => int, 'opened_tare' => float|null, 'opened_measured_at' => string]
	 * @throws \Exception When required fields are missing/invalid, a gross reading has no tare,
	 *                    or the measurement unit does not convert to the product's stock unit
	 */
	private function ResolveMeasurement(int $productId, array $measurement)
	{
		if (!array_key_exists('amount', $measurement) || !is_numeric($measurement['amount']) || $measurement['amount'] <= 0)
		{
			throw new \Exception('A measurement requires a positive amount');
		}

		if (!array_key_exists('qu_id', $measurement) || !is_numeric($measurement['qu_id']))
		{
			throw new \Exception('A measurement requires a quantity unit');
		}

		$quId = (int)$measurement['qu_id'];
		$isGross = boolval($measurement['is_gross'] ?? false);
		$tare = null;

		$product = $this->DB->products($productId);

		if ($quId != $product->qu_id_stock)
		{
			$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $productId, $quId, $product->qu_id_stock)->fetch();
			if ($conversion === null)
			{
				throw new \Exception('This measurement unit cannot be converted to the product\'s stock unit');
			}
		}

		$openedAmount = (float)$measurement['amount'];

		if ($isGross)
		{
			if (!array_key_exists('tare', $measurement) || !is_numeric($measurement['tare']) || $measurement['tare'] < 0)
			{
				throw new \Exception('A gross measurement requires a tare weight');
			}

			$tare = (float)$measurement['tare'];

			if ($tare >= $openedAmount)
			{
				throw new \Exception('The tare weight cannot be >= the gross reading');
			}

			$openedAmount -= $tare;
		}

		return [
			'opened_amount' => $openedAmount,
			'opened_qu_id' => $quId,
			'opened_tare' => $tare,
			'opened_measured_at' => date('Y-m-d H:i:s'),
		];
	}

	/** Protects a historical restore location until the outer undo transaction ends. */
	private function LockUndoLocation($locationId): void
	{
		if ($locationId === null)
		{
			return;
		}
		$database = DatabaseService::GetInstance();
		$sql = 'SELECT id FROM locations WHERE id = ?';
		if ($database->GetDialect()->GetName() === 'pgsql')
		{
			$sql .= ' FOR KEY SHARE';
		}
		$statement = $database->GetDbConnectionRaw()->prepare($sql);
		$statement->execute([$locationId]);
		if ($statement->fetchColumn() === false)
		{
			throw new \Exception('Cannot undo booking: original location no longer exists');
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
