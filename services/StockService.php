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

	/** Absolute floor for ADR-0032's stock-unit comparison tolerance. */
	const AMOUNT_TOLERANCE = 1e-9;

	/**
	 * Compares finite amounts in the same stock unit under ADR-0032.
	 * Pass the subtraction operands when deciding whether a remainder is zero:
	 * comparing the remainder alone would lose the relative tolerance's scale.
	 * Measured-container coherence and input sign checks remain exact.
	 *
	 * @return int -1, 0 or 1 when $a is less than, equal to or greater than $b
	 */
	public static function CompareAmounts(float $a, float $b): int
	{
		if (!is_finite($a) || !is_finite($b))
		{
			throw new \InvalidArgumentException('Stock amounts must be finite');
		}

		$difference = $a - $b;
		$tolerance = max(self::AMOUNT_TOLERANCE, 1e-12 * max(abs($a), abs($b)));
		return $difference > $tolerance ? 1 : ($difference < -$tolerance ? -1 : 0);
	}

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

	/** @var object|null Backing instance for KeepStoredValue(); see that method's own comment. */
	private static $KeepStoredValueInstance = null;

	/**
	 * Marks an EditStockEntry() argument the caller's request did not supply: the method
	 * resolves it against the entry's own row, re-read fresh under this method's own
	 * product lock, rather than against a value read before this method was even called.
	 *
	 * A value a caller (StockApiController::EditStockEntry()) read before this method's
	 * lock can already be stale by the time the lock is taken: a concurrent booking can
	 * have opened the entry, moved it to another location, or changed its price in between.
	 * Persisting that stale value would silently revert the concurrent change - a lost
	 * update found in review of issues #519/#524's partial-update fix. Resolving "keep the
	 * current value" here, against the locked re-read every other field of this method
	 * already uses, closes that window instead of moving it.
	 *
	 * Distinct from null, which several arguments (price, shopping_location_id, note)
	 * accept as a caller's explicit "clear this field".
	 *
	 * A fresh anonymous-class instance, cached here and handed back on every call, rather
	 * than the in-band string constant this replaced (`"\0victual-stock-service-..."`).
	 * That string was reachable: nothing stops an API caller from sending it as, say,
	 * `note`, JSON's `\u0000` escape and all, and EditStockEntry() then read that supplied
	 * value back as "omitted" and kept whatever note was already stored - the one string a
	 * client could never actually save as a note (found in review of #519/#524/#487). No
	 * value json_decode() can ever produce - no string however it is spelled, no int,
	 * float, bool, null or array - is ever `===` an object instance, so this has no
	 * equivalent reachable case.
	 *
	 * @return object
	 */
	public static function KeepStoredValue()
	{
		return self::$KeepStoredValueInstance ??= new class
		{
		};
	}

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
	 * With FEATURE_FLAG_LABELS enabled and a label type of 1 or 2, each new stock entry gets a stock_entry label
	 * and a print job for the default printer, issued inside the booking transaction (IssueStockEntryLabel()),
	 * so a label that cannot be issued rolls the whole booking back.
	 * Does not merge the new entry with another that now happens to match it - that is a maintenance
	 * command's job, not this method's, per ADR-0033 decision 1 (2026-09-27).
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
		if (!is_finite($amount) || $amount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
		}


		if ($amount <= 0)
		{
			throw new \Exception('Amount can\'t be <= 0');
		}

		if (self::CompareAmounts($amount, 0) == 0)
		{
			throw new \InvalidArgumentException('Amount must be greater than ' . self::AMOUNT_TOLERANCE);
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
				// Serialises against every other booking of this product (issue #458).
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

				// No CompactStockEntries() call here (ADR-0033 decision 1, 2026-09-27): merging
				// duplicate stock rows is now an explicit maintenance command
				// (bin/victual-compact-stock), not a side effect of every purchase. Issue #488
				// reproduced compaction silently discarding a live booking's units on a later
				// undo; issue #491 reproduced it retiring a live stock_entry label out from
				// under a purchase that happened to match an already-labelled row. See
				// CompactStockEntries()'s own docblock for the restricted eligibility the
				// maintenance command now applies.
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
	 * Sums a set of stock entry candidates in $productId's own stock quantity unit.
	 *
	 * The candidate set handed in here is already the exact scope a caller validates and then
	 * consumes/opens from (a location, a specific stock entry, a substitution set) - issue #487
	 * findings H1 and H4. Summing the raw amounts of that set is wrong once it can contain a
	 * different product's stock entries in a different quantity unit (#487 correction 4), and an
	 * availability check has to agree with what the caller's own consume/open loop can actually
	 * take from these same candidates. This mirrors that loop's own per-entry conversion: an
	 * entry belonging to a product other than $productId is converted through the same
	 * cache__quantity_unit_conversions_resolved row the loop looks up, from $productId's own
	 * stock unit to that entry's product's stock unit, and divided back out of it.
	 *
	 * $stockEntries is drawn from GetProductStockEntries() (directly, or via
	 * GetProductStockEntriesForLocation()), which - with substitution allowed - already excludes
	 * a sub product with no such resolvable conversion from the candidate set entirely
	 * (maintainer decision D4, issue #553): it is never handed to this method or to the loop, so
	 * neither ever falls back to counting it unconverted (1:1). The `$conversion != null` check
	 * below is therefore never false for a real candidate; it stays as a defensive fallback
	 * (matching the loop's own) rather than a live branch.
	 *
	 * @param iterable $stockEntries Candidate stock entries already narrowed to the exact scope being validated
	 * @param int $productId The product the result is expressed in terms of
	 * @param int $productQuIdStock $productId's own qu_id_stock
	 * @return float Sum, in $productId's stock quantity unit
	 */
	private function SumStockEntriesInProductUnit(iterable $stockEntries, int $productId, int $productQuIdStock): float
	{
		$sum = 0.0;
		foreach ($stockEntries as $stockEntry)
		{
			if ($stockEntry->product_id != $productId)
			{
				$subProduct = $this->DB->products($stockEntry->product_id);
				$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $stockEntry->product_id, $productQuIdStock, $subProduct->qu_id_stock)->fetch();
				$sum += $conversion != null ? ($stockEntry->amount / $conversion->factor) : $stockEntry->amount;
			}
			else
			{
				$sum += $stockEntry->amount;
			}
		}

		return $sum;
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
	 *                    the stock amount available within the requested scope (location and/or
	 *                    specific stock entry, in a common unit when substitution is allowed), or
	 *                    $transactionType is not valid here
	 */
	public function ConsumeProduct(int $productId, float $amount, bool $spoiled, $transactionType, $specificStockEntryId = 'default', $recipeId = null, $locationId = null, &$transactionId = null, $allowSubproductSubstitution = false, $consumeExactAmount = false)
	{
		if (!is_finite($amount) || $amount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

		if (self::CompareAmounts($amount, 0) == 0)
		{
			throw new \InvalidArgumentException('Amount must be greater than ' . self::AMOUNT_TOLERANCE);
		}

		if (!$this->ProductExists($productId))
		{
			throw new \Exception('Product does not exist or is inactive');
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
				else
				{
					// Materialized once so the availability check below sums exactly the
					// entries the loop further down iterates, instead of re-running the query
					// and risking the two seeing different rows (issue #487, H1).
					$materializedStockEntries = [];
					foreach ($potentialStockEntries as $candidateStockEntry)
					{
						$materializedStockEntries[] = $candidateStockEntry;
					}
					$potentialStockEntries = $materializedStockEntries;
				}

				// H1 (issue #487): validated against the exact candidate scope above (location
				// and/or specific stock entry, substitution set), not the product-wide aggregate
				// - a narrower scope can hold less than the product-wide total. Mixed-unit
				// substitution candidates are summed in $productId's own stock unit rather than
				// as raw amounts (#487 correction 4).
				$productStockAmount = $this->SumStockEntriesInProductUnit($potentialStockEntries, $productId, $productDetails->product->qu_id_stock);
				if (self::CompareAmounts($amount, $productStockAmount) > 0)
				{
					throw new \Exception('Amount to be consumed cannot be > current stock amount (if supplied, at the desired location)');
				}

				foreach ($potentialStockEntries as $stockEntry)
				{
					// A whole-take below can leave $amount a hair off zero either way (CodeRabbit
					// review of PR #531: the subtraction is exact float arithmetic on values that
					// were not exact multiples of each other to begin with) - compared within
					// the shared tolerance, like every other zero/negative decision this file makes,
					// rather than by exact equality, so a residue too small to be real does not
					// send the loop looking for one more candidate.
					if (self::CompareAmounts($amount, 0) == 0)
					{
						break;
					}

					if (self::CompareAmounts($stockEntry->amount, 0) < 0)
					{
						// A persisted stock row should never be genuinely negative, but a
						// defect elsewhere in the ledger (#489 C2) could still leave one, and
						// stock_next_use() carries no amount filter of its own. Taking it below
						// would record `$stockEntry->amount * -1` - a *positive* amount under a
						// 'consume' transaction_type - which books stock arriving, not leaving,
						// and raises on-hand amount instead of lowering it. Refusing here rolls
						// back the whole InTransaction() call, so no earlier iteration's write
						// in this same loop is left half-applied.
						throw new \Exception('Cannot consume: a candidate stock entry holds a non-positive amount and stock data needs correction');
					}

					if (self::CompareAmounts($stockEntry->amount, 0) == 0)
					{
						// A legitimate zero-amount row - WeighLocation() can leave one for a
						// vessel whose gross reading equals its tare, and so can
						// EditStockEntry(..., 0) - holds nothing to take. Skipping it (rather
						// than refusing, which the guard above still does for a genuinely
						// negative row) leaves it untouched and lets the loop continue to the
						// next candidate for the requested amount.
						continue;
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

					$takeWholeEntry = self::CompareAmounts($stockEntry->amount, $amount) <= 0;

					// A measured entry is never a split candidate (maintainer decision D8,
					// issue #502 round 2): stock_next_use() sorts open DESC, so an opened,
					// measured container is ordinarily the very first candidate here, and an
					// ordinary fractional consume, inventory correction or recipe/chore booking
					// has no business being refused just because one happens to exist - weighed
					// partial containers belong to the tare/working-container flow (ADR-0022),
					// not to this one. Left completely untouched and skipped in favor of other
					// stock; the amount-not-yet-zero check after this loop refuses only when no
					// other candidate - including one explicitly named via $specificStockEntryId,
					// which narrows $potentialStockEntries to just this entry - could cover what
					// remains. The conversion this block may have just applied is undone first,
					// symmetric to the whole-take branch's own reversal below, so the next
					// candidate (a different sub product, or none) starts from the true
					// $productId-unit remainder rather than this entry's converted one.
					if (!$takeWholeEntry && $stockEntry->opened_amount !== null)
					{
						if ($allowSubproductSubstitution && $stockEntry->product_id != $productId && $conversion != null)
						{
							$amount = $amount / $conversion->factor;
						}

						continue;
					}

					if ($takeWholeEntry)
					{
						// Take the whole stock entry - not only when $amount covers it exactly
						// or more, but also when it falls short by no more than the shared tolerance
						// (CodeRabbit review of PR #531): splitting on a shortfall that small
						// would write $stockEntry->amount - $amount, a float residue like
						// 0.30000000000000004 - 0.3 = 5.5e-17, onto the row below instead of
						// taking it. Before this PR that residue row was still consumable - the
						// next whole-take absorbed and deleted it. The zero-row skip this PR
						// added just above the loop (ConsumeProduct()'s own candidate check)
						// would instead leave it there forever, showing in entry lists and
						// tripping WeighLocation()'s "does not hold exactly one stock entry".
						// The four opened_* columns are mirrored onto the booking (ADR-0022
						// decision 9) so undoing this consume can rebuild the deleted row with
						// its measurement intact - see UndoBooking()'s TRANSACTION_TYPE_CONSUME
						// branch.
						$logRow = $this->DB->stock_log()->createRow([
							'product_id' => $stockEntry->product_id,
							'amount' => $stockEntry->amount * -1,
							'best_before_date' => $stockEntry->best_before_date,
							'purchased_date' => $stockEntry->purchased_date,
							'used_date' => date('Y-m-d'),
							'spoiled' => $spoiled,
							'stock_id' => $stockEntry->stock_id,
							// The exact row this booking took from (#488, fourth review round):
							// a whole-take consume deletes it, so undoing this booking has to
							// rebuild it, and any later-undone TRANSFER_TO/FROM or
							// PRODUCT_OPENED booking that named this same row needs that
							// rebuild to land under the same id it had before, or its own
							// undo's id-based match goes stale.
							'stock_row_id' => $stockEntry->id,
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

						$amount = self::CompareAmounts($amount, $stockEntry->amount) == 0 ? 0.0 : $amount - $stockEntry->amount;

						if ($allowSubproductSubstitution && $stockEntry->product_id != $productId && $conversion != null)
						{
							// A sub product with QU conversions was used
							// => Convert the rest amount back to be based on the original (parent) product for the next round
							$amount = $amount / $conversion->factor;
						}
					}
					else
					{
						// A measured entry never reaches here - the defer above this branch's
						// own top sends it back before any write, since no split of it (amount
						// other than 1) can carry its measurement forward (ADR-0022 decision 8).
						//
						// Stock entry amount is > than needed amount by more than the shared tolerance
						// (the branch above now also takes anything closer than that) -> split the
						// stock entry resp. update the amount. $restStockAmount is a real remainder,
						// not a float artifact, by construction.
						$restStockAmount = $stockEntry->amount - $amount;

						$logRow = $this->DB->stock_log()->createRow([
							'product_id' => $stockEntry->product_id,
							'amount' => $amount * -1,
							'best_before_date' => $stockEntry->best_before_date,
							'purchased_date' => $stockEntry->purchased_date,
							'used_date' => date('Y-m-d'),
							'spoiled' => $spoiled,
							'stock_id' => $stockEntry->stock_id,
							// The row this partial consume reduced. It is never deleted by a
							// partial take, so undoing this booking always keeps today's plain-
							// insert behaviour regardless (#488, fourth review round) - recorded
							// for the same reason the whole-take branch above now does.
							'stock_row_id' => $stockEntry->id,
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

				// Every candidate has now either been taken (whole or split) or skipped for
				// being a measured entry's split (the defer above). $amount can only still be
				// nonzero here because the only stock left able to cover it was one of those
				// deferred, measured entries - the pre-loop availability check already
				// guarantees the product-wide total (measured entries' full amount included)
				// covers the request, so this is not an ordinary shortfall. Refused rather
				// than silently taking a whole extra unit the caller never asked for, or
				// falling through to the split branch's own coherence violation - covers both
				// "the measured entry is the only remaining source" and "the caller named it
				// explicitly via stock_entry_id" (issue #487, M2 round 2 / maintainer decision D8).
				if (self::CompareAmounts($amount, 0) != 0)
				{
					throw new \Exception('Cannot consume a fraction of a measured container: weigh it instead (the working container flow), or consume the whole container');
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
	 * a shared correlation id, so an undo restores the old state). Does not merge the edited entry
	 * with another that now happens to match it - that is a maintenance command's job, not this
	 * method's, per ADR-0033 decision 1 (2026-09-27): CompactStockEntries() used to run inline here
	 * and issue #488 found it could delete a row an in-flight undo still depended on.
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
		if (!is_finite($amount) || $amount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

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

			// A field the caller's request did not supply is resolved here, against this
			// locked, freshly re-read row - never against the unlocked read the controller
			// took before calling in, which a concurrent booking can have moved past by now.
			// See KeepStoredValue()'s own comment.
			$keepStoredValue = self::KeepStoredValue();
			$bestBeforeDate = $bestBeforeDate === $keepStoredValue ? $stockRow->best_before_date : $bestBeforeDate;
			$locationId = $locationId === $keepStoredValue ? $stockRow->location_id : $locationId;
			$shoppingLocationId = $shoppingLocationId === $keepStoredValue ? $stockRow->shopping_location_id : $shoppingLocationId;
			$price = $price === $keepStoredValue ? $stockRow->price : $price;
			$open = $open === $keepStoredValue ? $stockRow->open : $open;
			$purchasedDate = $purchasedDate === $keepStoredValue ? $stockRow->purchased_date : $purchasedDate;
			$note = $note === $keepStoredValue ? $stockRow->note : $note;

			// Whether the edited state still permits the measurement (if any) this entry
			// already carries. ADR-0032 requires the same exact amount as the SQL CHECK.
			$staysCoherent = boolval($open) && $amount == 1.0;

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

			// No CompactStockEntries() call here (ADR-0033 decision 1, 2026-09-27): an edit
			// that happens to make this entry match another no longer merges them inline. See
			// CompactStockEntries()'s own docblock for why, and for the maintenance command
			// that now performs merging under a tightened eligibility rule.
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

			if ($stockRow->open != 1 || $stockRow->amount != 1.0)
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
		$locationColumns = 'id, name, description, row_created_timestamp, is_freezer, active';
		// The differential harness retains SQLite's pre-location-hierarchy schema.
		if (DatabaseService::GetInstance()->GetDialect()->GetName() === 'pgsql')
		{
			$locationColumns .= ', parent_location_id';
		}
		$location = $this->DB->locations()->select($locationColumns)->where('id', $product->location_id)->fetch();

		$defaultConsumeLocation = null;
		if (!empty($product->default_consume_location_id))
		{
			$defaultConsumeLocation = $this->DB->locations()->select($locationColumns)->where('id', $product->default_consume_location_id)->fetch();
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
	 * @param bool $allowSubproductSubstitution When true, entries of resolved sub products are included -
	 *             but only for a sub product whose stock unit resolves to $productId's own stock unit
	 *             through cache__quantity_unit_conversions_resolved. A sub product with no such
	 *             resolved conversion is excluded from the candidate set entirely (maintainer decision
	 *             D4, issue #553): it must never be counted 1:1 by SumStockEntriesInProductUnit()'s
	 *             availability check or by the consume/open loop that iterates this same result, both
	 *             of which convert (or, for a product-owned entry, pass through unconverted) exactly
	 *             the candidates this method hands them. cache__quantity_unit_conversions_resolved
	 *             always carries the identity row (factor 1.0) for a sub product sharing $productId's
	 *             own stock unit (db/pgsql/baseline/03_views_group2.sql, "Priority 2" of
	 *             product_conversions), so this only ever excludes a genuinely unconvertible sub
	 *             product, never a same-unit one.
	 * @return \LessQL\Result Iterable stock entry rows (amounts in the entry's product's stock quantity unit)
	 */
	public function GetProductStockEntries(int $productId, $excludeOpened = false, $allowSubproductSubstitution = false)
	{
		$sqlWhereProductId = $allowSubproductSubstitution
			? $this->SubstitutionAwareProductIdWhereClause($productId)
			: 'product_id = ' . $productId;

		$sqlWhereAndOpen = 'AND open IN (0, 1)';
		if ($excludeOpened)
		{
			$sqlWhereAndOpen = 'AND open = 0';
		}

		return $this->DB->stock_next_use()->where($sqlWhereProductId . ' ' . $sqlWhereAndOpen);
	}

	/**
	 * The `product_id = ...` (or, with substitution, `product_id IN (...) OR product_id = ...`)
	 * WHERE fragment shared by GetProductStockEntries() and GetProductStockLocations(): with
	 * substitution, a sub product is admitted only when its own stock unit resolves from
	 * $productId's own stock unit through cache__quantity_unit_conversions_resolved (maintainer
	 * decision D4, issue #553) - excluded entirely, never counted 1:1, when no such conversion
	 * exists. See GetProductStockEntries()'s own docblock for why this must not be a plain
	 * `products_resolved` membership test.
	 *
	 * @param int $productId
	 * @return string A SQL boolean expression on `product_id`, safe to AND with further conditions
	 */
	private function SubstitutionAwareProductIdWhereClause(int $productId): string
	{
		// A nonexistent $productId (neither caller has an existence check of its own - the raw
		// API routes behind both don't either) previously just produced an always-empty result
		// via the plain IN (...) below; kept that behaviour here instead of a null-property fatal.
		$parentProduct = $this->DB->products($productId);
		if ($parentProduct === null)
		{
			return '(product_id IN (SELECT sub_product_id FROM products_resolved WHERE parent_product_id = ' . $productId . ') OR product_id = ' . $productId . ')';
		}

		$parentQuIdStock = (int)$parentProduct->qu_id_stock;
		return '('
			. 'product_id IN ('
			. 'SELECT pr.sub_product_id FROM products_resolved pr '
			. 'JOIN products p_sub ON p_sub.id = pr.sub_product_id '
			. 'JOIN cache__quantity_unit_conversions_resolved qucr '
			. 'ON qucr.product_id = pr.sub_product_id '
			. 'AND qucr.from_qu_id = ' . $parentQuIdStock . ' '
			. 'AND qucr.to_qu_id = p_sub.qu_id_stock '
			// qucr.factor is declared TEXT on both engines (db/pgsql/baseline/01_tables.sql,
			// migrations/0225.sql), so a plain `> 0` needs an explicit numeric CAST to even
			// type-check on PostgreSQL; CAST(... AS NUMERIC) rather than the PG-only `::` sugar
			// keeps this valid under DatabaseDialect::SQLITE_TOOLING_ENV too, where the
			// differential suite can run this same code path against SQLite. Nothing puts a
			// CHECK on quantity_unit_conversions.factor, so a NEGATIVE factor can reach this
			// cache table (a factor of exactly 0 cannot: quantity_unit_conversions_INS's own
			// inverse-row computation divides by it and raises first - see MergeProducts()'s
			// equivalent guard, StockService.php ~4177). Admitting a sub product on a
			// non-positive factor would let SumStockEntriesInProductUnit()'s availability check
			// divide by it and Consume/Open multiply by it; exclude it here exactly like "no
			// resolved conversion at all" (maintainer decision D4).
			. 'AND CAST(qucr.factor AS NUMERIC) > 0 '
			. 'WHERE pr.parent_product_id = ' . $productId
			. ') OR product_id = ' . $productId
			. ')';
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
	 * @param bool $allowSubproductSubstitution When true, locations of resolved sub products are included -
	 *             but, like GetProductStockEntries(), only for a sub product whose stock unit
	 *             resolves to $productId's own (maintainer decision D4, issue #553). A location
	 *             holding only an unconvertible sub product's stock is not offered here - it
	 *             would otherwise show a location whose real (converted) maximum is 0.
	 * @return \LessQL\Result Iterable row objects
	 */
	public function GetProductStockLocations(int $productId, $allowSubproductSubstitution = false)
	{
		$sqlWhereProductId = $allowSubproductSubstitution
			? $this->SubstitutionAwareProductIdWhereClause($productId)
			: 'product_id = ' . $productId;

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
		if (!is_finite($newAmount) || $newAmount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

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
			$amountComparison = self::CompareAmounts($newAmount, $productDetails->stock_amount);
			if ($amountComparison == 0)
			{
				throw new \Exception('The new amount cannot equal the current stock amount');
			}
			elseif ($amountComparison > 0)
			{
				$bookingAmount = $newAmount - $productDetails->stock_amount;

				return $this->AddProduct($productId, $bookingAmount, $bestBeforeDate, self::TRANSACTION_TYPE_INVENTORY_CORRECTION, $resolvedPurchasedDate, $resolvedPrice, $locationId, $resolvedShoppingLocationId, $unusedTransactionId, $stockLabelType, $note);
			}
			else
			{
				$bookingAmount = $productDetails->stock_amount - $newAmount;

				return $this->ConsumeProduct($productId, $bookingAmount, false, self::TRANSACTION_TYPE_INVENTORY_CORRECTION);
			}
		});
	}

	/**
	 * Marks the given amount of a product as opened.
	 *
	 * Unopened stock entries are processed in default consume order; an entry covering more than the
	 * remaining amount is split (the unopened rest gets a new stock entry with a new stock_id). Each
	 * touched entry gets open = 1, opened_date = today and - when the product has "default due days
	 * after opened" - a shortened due date (never later than the original one; with FEATURE_FLAG_LABELS
	 * and the product's auto_reprint_stock_label set, a date change sends a revised print of the entry's
	 * label, but only if it already carries a live one - see ReviseStockEntryLabelIfLive()).
	 * One TRANSACTION_TYPE_PRODUCT_OPENED booking is written per touched entry; the stock amount
	 * itself is unchanged. When the product has "move on open" set,
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
	 *                    unopened stock amount available within the requested scope (specific stock
	 *                    entry and/or substitution set, in a common unit when substitution is
	 *                    allowed), a measurement is given without targeting a specific single-unit
	 *                    entry, or a measurement's unit does not convert to the product's stock unit
	 */
	public function OpenProduct(int $productId, float $amount, $specificStockEntryId = 'default', &$transactionId = null, $allowSubproductSubstitution = false, ?array $measurement = null)
	{
		if (!is_finite($amount) || $amount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

		if (self::CompareAmounts($amount, 0) == 0)
		{
			throw new \InvalidArgumentException('Amount must be greater than ' . self::AMOUNT_TOLERANCE);
		}

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
			$potentialStockEntries = $this->GetProductStockEntries($productId, true, $allowSubproductSubstitution);

			if ($specificStockEntryId !== 'default')
			{
				$potentialStockEntries = FindAllObjectsInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
			}
			else
			{
				// Materialized once so the availability check below sums exactly the
				// entries the loop further down iterates, instead of re-running the query
				// and risking the two seeing different rows (issue #487, H1/H4).
				$materializedStockEntries = [];
				foreach ($potentialStockEntries as $candidateStockEntry)
				{
					$materializedStockEntries[] = $candidateStockEntry;
				}
				$potentialStockEntries = $materializedStockEntries;
			}

			// Measurement-specific checks run before the general availability check below:
			// they name a narrower failure (this exact entry cannot carry a measurement)
			// than "the scope holds too little", and a caller relying on one of these
			// messages should still see it even when the named entry also happens to be
			// short - issue #487 workstream 18 validation. $targetEntry can still be null
			// here (a nonexistent stock_id, or one excluded by excludeOpened = true because
			// it is already open) - the availability check has not run yet to catch that
			// emptiness first.
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

				if ($amount != 1.0)
				{
					throw new \Exception('A measurement requires opening exactly one unit');
				}

				$targetEntry = FindObjectInArrayByPropertyValue($potentialStockEntries, 'stock_id', $specificStockEntryId);
				if ($targetEntry === null || $targetEntry->amount < 1.0)
				{
					throw new \Exception('This stock entry cannot be opened as a single measured container');
				}

				$resolvedMeasurement = $this->ResolveMeasurement($productId, $measurement);
			}

			// H1/H4 (issue #487): validated against the exact candidate scope above (specific
			// stock entry, substitution set), not the product-wide unopened aggregate - a
			// narrower scope can hold less than the product-wide total. Mixed-unit substitution
			// candidates are summed in $productId's own stock unit rather than as raw amounts
			// (#487 correction 4).
			$productStockAmountUnopened = $this->SumStockEntriesInProductUnit($potentialStockEntries, $productId, $product->qu_id_stock);

			if (self::CompareAmounts($amount, $productStockAmountUnopened) > 0)
			{
				throw new \Exception('Amount to be opened cannot be > current unopened stock amount');
			}

			foreach ($potentialStockEntries as $stockEntry)
			{
				// Compared within the shared tolerance rather than by exact equality (CodeRabbit
				// review of PR #531; same reasoning as ConsumeProduct()'s own loop): the
				// whole-entry branch below can leave $amount a hair off zero either way.
				if (self::CompareAmounts($amount, 0) == 0)
				{
					break;
				}

				$newBestBeforeDate = $stockEntry->best_before_date;
				$shouldReviseStockEntryLabel = false;
				if ($product->default_best_before_days_after_open > 0)
				{
					$newBestBeforeDate = date('Y-m-d', strtotime('+' . $product->default_best_before_days_after_open . ' days'));

					// The new due date should be never > the original due date
					if (strtotime($newBestBeforeDate) > strtotime($stockEntry->best_before_date))
					{
						$newBestBeforeDate = $stockEntry->best_before_date;
					}

					// Deferred until after $stockEntry->update() below writes the new due date
					// (issue #523): calling ReviseStockEntryLabelIfLive() here, before that write,
					// captured the due date the entry still had - the one about to be replaced -
					// so an auto-reprint always reflected the *previous* opening's due date
					// instead of the one this booking is making current.
					$shouldReviseStockEntryLabel = VICTUAL_FEATURE_FLAG_LABELS && $productDetails->product->auto_reprint_stock_label == 1 && $newBestBeforeDate != $stockEntry->best_before_date;
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

				// Coherence is expressed in the candidate's stock unit after substitution.
				if ($resolvedMeasurement !== null && $amount != 1.0)
				{
					throw new \Exception('A measurement requires opening exactly one unit');
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

				// A measured opening must split off exactly one unit, even inside the tolerance.
				$takeWholeEntry = $resolvedMeasurement !== null
					? $stockEntry->amount <= $amount
					: self::CompareAmounts($stockEntry->amount, $amount) <= 0;
				if ($takeWholeEntry)
				{
					// Mark the whole stock entry as opened - not only when $amount covers it
					// exactly or more, but also when it falls short by no more than
					// the shared tolerance (CodeRabbit review of PR #531; same reasoning as
					// ConsumeProduct()'s own whole-take branch): splitting on a shortfall that
					// small would leave a float-residue remainder row below instead.
					$logRow = $this->DB->stock_log()->createRow(array_merge([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						// The exact row this booking opened (#488, sibling of C1/M22): a split
						// transfer can leave more than one row sharing this stock_id, amount
						// and purchased_date at different locations, which made UndoBooking()'s
						// old (stock_id, amount, purchased_date) match ambiguous.
						'stock_row_id' => $stockEntry->id,
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

					$amount = self::CompareAmounts($amount, $stockEntry->amount) == 0 ? 0.0 : $amount - $stockEntry->amount;

					if ($allowSubproductSubstitution && $stockEntry->product_id != $productId && $conversion != null)
					{
						// A sub product with QU conversions was used
						// => Convert the rest amount back to be based on the original (parent)
						// product for the next round - without this, a second substitution
						// candidate has the factor applied on top of an amount already in the
						// first candidate's unit, over-opening it (issue #487, H4).
						$amount = $amount / $conversion->factor;
					}
				}
				else
				{
					// Stock entry amount is > than needed amount by more than the shared tolerance
					// -> split the stock entry. $restStockAmount is a real remainder, not a
					// float artifact, by construction.
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
						// See the whole-entry branch's own comment on stock_row_id above -
						// $stockEntry is updated in place below (it becomes the opened
						// portion), so its id is already the row this booking describes.
						'stock_row_id' => $stockEntry->id,
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

				// Now that $stockEntry->update() above has written $newBestBeforeDate (issue
				// #523): both branches update this same row in place (the split branch turns
				// it into the opened portion), so its id is the row a reprint has to describe
				// either way, and the capture below reads the due date that is now actually
				// on the row rather than the one it is replacing.
				if ($shouldReviseStockEntryLabel)
				{
					$this->ReviseStockEntryLabelIfLive((int)$stockEntry->id);
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
	 * @param int $listId Shopping list id (scopes the removal operation; defaults to 1 per API contract)
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
	 * "default due days after thawing". With FEATURE_FLAG_LABELS and the product's auto_reprint_stock_label
	 * set, a whole-entry transfer that changes the due date sends a revised print of the entry's label, but
	 * only if it already carries a live one - see ReviseStockEntryLabelIfLive(). A split transfer revises
	 * nothing: the source row, the only one that can carry a live label, keeps its original due date, and
	 * the new destination row has no live label yet.
	 *
	 * @param int $productId
	 * @param float $amount Amount in the product's stock quantity unit
	 * @param int $locationIdFrom Source location id
	 * @param int $locationIdTo Destination location id
	 * @param string $specificStockEntryId 'default' transfers in default order; otherwise a stock_id restricting the transfer to that single stock entry
	 * @param string|null $transactionId By-reference; generated via uniqid() when null, shared across all bookings of this call
	 * @return string The transaction id of the booking(s)
	 * @throws \Exception When the product or a location does not exist, the product is tare weight handled
	 *                    (not supported), or the amount exceeds the stock amount available within the
	 *                    requested scope (source location and/or specific stock entry)
	 */
	public function TransferProduct(int $productId, float $amount, int $locationIdFrom, int $locationIdTo, $specificStockEntryId = 'default', &$transactionId = null)
	{
		if (!is_finite($amount) || $amount < 0)
		{
			throw new \InvalidArgumentException('Stock amount must be finite and non-negative');
		}

		if (self::CompareAmounts($amount, 0) == 0)
		{
			throw new \InvalidArgumentException('Amount must be greater than ' . self::AMOUNT_TOLERANCE);
		}

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

			$potentialStockEntriesAtFromLocation = $this->GetProductStockEntriesForLocation($productId, $locationIdFrom);

			if ($specificStockEntryId !== 'default')
			{
				$potentialStockEntriesAtFromLocation = FindAllObjectsInArrayByPropertyValue($potentialStockEntriesAtFromLocation, 'stock_id', $specificStockEntryId);
			}

			// H1 (issue #487): validated against the exact candidate scope above (source
			// location and/or specific stock entry), not the whole source-location aggregate -
			// a named single entry can hold less than the location's total.
			$productStockAmountAtFromLocation = $this->SumStockEntriesInProductUnit($potentialStockEntriesAtFromLocation, $productId, $productDetails->product->qu_id_stock);

			if (self::CompareAmounts($amount, $productStockAmountAtFromLocation) > 0)
			{
				throw new \Exception('Amount to be transferred cannot be > current stock amount at the source location');
			}

			foreach ($potentialStockEntriesAtFromLocation as $stockEntry)
			{
				// Compared within the shared tolerance rather than by exact equality (CodeRabbit
				// review of PR #531; same reasoning as ConsumeProduct()'s own loop): the
				// whole-entry branch below can leave $amount a hair off zero either way.
				if (self::CompareAmounts($amount, 0) == 0)
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

					// Deferred until after the row that actually receives $newBestBeforeDate is
					// written below (issue #523; same reasoning as OpenProduct()'s own fix):
					// capturing here read the due date the entry still had, before the write
					// that changes it.
					$shouldReviseStockEntryLabel = VICTUAL_FEATURE_FLAG_LABELS && $productDetails->product->auto_reprint_stock_label == 1 && $stockEntry->best_before_date != $newBestBeforeDate;
				}
				else
				{
					$shouldReviseStockEntryLabel = false;
				}

				$takeWholeEntry = self::CompareAmounts($stockEntry->amount, $amount) <= 0;

				// A measured entry is never a split candidate (maintainer decision D8, issue
				// #487 M2 round 2), for the same reason ConsumeProduct()'s own loop defers one:
				// a measurement only ever describes exactly one whole unit (ADR-0022 decision
				// 8), so no split of it can carry that forward on either resulting row. Left
				// completely untouched and skipped in favor of other stock at the source
				// location; the amount-not-yet-zero check after this loop refuses only when no
				// other candidate - including one explicitly named via $specificStockEntryId,
				// which narrows the candidate list to just this entry - could cover what remains.
				if (!$takeWholeEntry && $stockEntry->opened_amount !== null)
				{
					continue;
				}

				$correlationId = uniqid();
				if ($takeWholeEntry)
				{
					// Take the whole stock entry - not only when $amount covers it exactly or
					// more, but also when it falls short by no more than the shared tolerance
					// (CodeRabbit review of PR #531; same reasoning as ConsumeProduct()'s own
					// whole-take branch): splitting on a shortfall that small would leave a
					// float-residue remainder row at the source below instead.
					$logRowForLocationFrom = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						// The exact row this booking's amount came from/landed on - here, the
						// same physical row on both halves, since a whole-entry transfer
						// relocates it in place rather than creating a new one. Undo matches
						// on this first (#489 C2 below), since a second split transfer into the
						// same destination can leave more than one row sharing (stock_id,
						// location), which made the old match ambiguous.
						'stock_row_id' => $stockEntry->id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_FROM,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note,
						// Mirrored (ADR-0022 decision 9, the same reason ConsumeProduct()'s own
						// bookings mirror these) so TRANSFER_FROM undo can rebuild this entry
						// with its measurement intact if the row has since left this location
						// entirely (#522 M22).
						'opened_amount' => $stockEntry->opened_amount,
						'opened_qu_id' => $stockEntry->opened_qu_id,
						'opened_tare' => $stockEntry->opened_tare,
						'opened_measured_at' => $stockEntry->opened_measured_at
					]);
					$logRowForLocationFrom->save();

					$logRowForLocationTo = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $stockEntry->amount,
						'best_before_date' => $newBestBeforeDate,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'stock_row_id' => $stockEntry->id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_TO,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $locationIdTo,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note,
						'opened_amount' => $stockEntry->opened_amount,
						'opened_qu_id' => $stockEntry->opened_qu_id,
						'opened_tare' => $stockEntry->opened_tare,
						'opened_measured_at' => $stockEntry->opened_measured_at
					]);
					$logRowForLocationTo->save();

					$stockEntry->update([
						'location_id' => $locationIdTo,
						'best_before_date' => $newBestBeforeDate
					]);

					// Now that the write above has actually put $newBestBeforeDate on this row
					// (issue #523): the whole-entry transfer relocates $stockEntry in place, so
					// its id is still the row a live label describes and the capture below reads
					// the due date this transfer is making current, not the one it replaced.
					if ($shouldReviseStockEntryLabel)
					{
						$this->ReviseStockEntryLabelIfLive((int)$stockEntry->id);
					}

					$amount = self::CompareAmounts($amount, $stockEntry->amount) == 0 ? 0.0 : $amount - $stockEntry->amount;
				}
				else
				{
					// A measured entry never reaches here - the defer above this branch's own
					// top sends it back before any write, since no split of it (amount other
					// than 1) can carry its measurement forward (ADR-0022 decision 8).
					//
					// Stock entry amount is > than needed amount by more than the shared tolerance
					// -> split the stock entry resp. update the amount. $restStockAmount is a
					// real remainder, not a float artifact, by construction.
					//
					// No reprint call here even when $shouldReviseStockEntryLabel is true: a split
					// transfer leaves $stockEntry (the only row that could already carry a live
					// label) at the source with its ORIGINAL due date - only the brand new
					// $stockEntryNew below gets $newBestBeforeDate, and a row created this instant
					// cannot yet have a live label of its own (ReviseStockEntryLabelIfLive() would
					// no-op for it regardless). There is no row here where "reprint the live label
					// with the new due date" is a coherent action.
					$restStockAmount = $stockEntry->amount - $amount;

					$logRowForLocationFrom = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount * -1,
						'best_before_date' => $stockEntry->best_before_date,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'stock_row_id' => $stockEntry->id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_FROM,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $stockEntry->location_id,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note,
						'opened_amount' => $stockEntry->opened_amount,
						'opened_qu_id' => $stockEntry->opened_qu_id,
						'opened_tare' => $stockEntry->opened_tare,
						'opened_measured_at' => $stockEntry->opened_measured_at
					]);
					$logRowForLocationFrom->save();

					// This is the existing stock entry -> remains at the source location with the rest amount
					$stockEntry->update([
						'amount' => $restStockAmount
					]);

					// The transferred amount gets into a new stock entry - created (and
					// saved, so its id is known) before the TRANSFER_TO booking below, so
					// that booking can name the exact row its amount landed on (#489 C2; see
					// the whole-entry branch's own comment on stock_row_id above). A measured
					// entry cannot reach here: the defer just above this loop's own top skips it
					// before any write, so this new row's own opened_* columns are correctly left
					// null.
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

					$logRowForLocationTo = $this->DB->stock_log()->createRow([
						'product_id' => $stockEntry->product_id,
						'amount' => $amount,
						'best_before_date' => $newBestBeforeDate,
						'purchased_date' => $stockEntry->purchased_date,
						'stock_id' => $stockEntry->stock_id,
						'stock_row_id' => $stockEntryNew->id,
						'transaction_type' => self::TRANSACTION_TYPE_TRANSFER_TO,
						'price' => $stockEntry->price,
						'opened_date' => $stockEntry->opened_date,
						'location_id' => $locationIdTo,
						'shopping_location_id' => $stockEntry->shopping_location_id,
						'correlation_id' => $correlationId,
						'transaction_id' => $transactionId,
						'user_id' => VICTUAL_USER_ID,
						'note' => $stockEntry->note,
						'opened_amount' => $stockEntryNew->opened_amount,
						'opened_qu_id' => $stockEntryNew->opened_qu_id,
						'opened_tare' => $stockEntryNew->opened_tare,
						'opened_measured_at' => $stockEntryNew->opened_measured_at
					]);
					$logRowForLocationTo->save();

					$amount = 0;
				}
			}

			// Every candidate at the source location has now either been taken (whole or
			// split) or skipped for being a measured entry's split (the defer above). See
			// ConsumeProduct()'s own identical check for why $amount can only still be
			// nonzero here, and why that means "no other stock could cover it" rather than
			// an ordinary shortfall - covers both "the measured entry is the only remaining
			// source" and "the caller named it explicitly via stock_entry_id" (issue #487,
			// M2 round 2 / maintainer decision D8).
			if (self::CompareAmounts($amount, 0) != 0)
			{
				throw new \Exception('Cannot transfer a fraction of a measured container: weigh it instead (the working container flow), or transfer the whole container');
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
	 * arrives in) and corrects its stock TOTAL at that location to match, per ADR-0022 decision
	 * 4's location-scoped tare, docs/plans/landed/29-working-container-replenishment.md, and
	 * ADR-0033 decision 5 (2026-09-27).
	 *
	 * The device posts a gross reading in the location's own tare unit; this method subtracts
	 * the location's tare weight, converts the net remainder into the stocked product's stock
	 * unit through cache__quantity_unit_conversions_resolved - the same per-product conversion
	 * cache every other write path in this class reads (ADR-0022 decision 3), refusing rather
	 * than assuming when no conversion path exists - and compares it against the SUM of every
	 * stock row this product holds at this exact location (not the product's stock everywhere,
	 * and not one merged row: ADR-0033 removed the compaction this method used to run first, so
	 * a vessel refilled more than once, or holding a dated or labelled lot, can legitimately
	 * hold several rows here at once):
	 *
	 * - A reading within ADR-0032's tolerance of that sum books nothing and returns an empty
	 *   string - there is nothing to correct, and StockApiController::WeighLocation() answers
	 *   200 with an empty transaction array rather than treating this as a refusal.
	 * - A lower reading consumes the difference through ConsumeProduct() in its own ordinary
	 *   consume order, restricted to this location ($locationId) - existing rows are reduced or
	 *   deleted as that order already decides, exactly as any other inventory correction would.
	 * - A higher reading adds ONE new row through AddProduct(), at this location, as a
	 *   TRANSACTION_TYPE_INVENTORY_CORRECTION. That row needs a due date nothing here can
	 *   guess (an arbitrary existing lot's date would misrepresent what was actually added -
	 *   ADR-0033's own Decision text), so the caller must supply $bestBeforeDate explicitly;
	 *   its price, shopping location and purchased date come from the same defaults
	 *   InventoryProduct() already uses for its own positive correction (the product's last
	 *   price/shopping location, purchased today).
	 *
	 * Every existing row at the location keeps its own due date, label and identity either
	 * way - this method never merges, and ordinary full-consumption label retirement (the
	 * retire_stock_entry_labels trigger, migrations/0283.pgsql.php) applies only if the
	 * consume branch happens to empty a labelled row completely, exactly as any other consume
	 * would.
	 *
	 * Refuses when the location has no tare configured, when zero or more than one distinct
	 * product is stocked there (weighing a shared shelf makes no sense - a vessel holds one
	 * product; still refused after ADR-0033, unchanged), or when the reading is higher and no
	 * $bestBeforeDate was supplied.
	 *
	 * @param int $locationId
	 * @param float $grossAmount The gross reading, in the location's own tare_qu_id.
	 * @param int|null $grossQuId When given, must equal the location's tare_qu_id - present
	 *                            so a client's unit mismatch is refused rather than silently
	 *                            misweighed, per ADR-0022 question 5's "gross" contract.
	 * @param string|null $bestBeforeDate Required only when the reading turns out to be higher
	 *                            than what is on record (a positive correction adds a new row
	 *                            and needs its own due date); ignored otherwise.
	 * @return string The transaction id of the resulting booking, or '' when the reading
	 *                 matched what was on record and nothing was booked.
	 * @throws \Exception When the location, its tare, or the product stocked there cannot be
	 *                    resolved, or a higher reading is given no $bestBeforeDate.
	 */
	public function WeighLocation(int $locationId, float $grossAmount, ?int $grossQuId = null, ?string $bestBeforeDate = null): string
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

		// Everything from here on reads and rewrites this one product's stock rows - summing
		// them, deciding the correction, booking it - and has to see one consistent, locked
		// snapshot of them (issue #458): a concurrent booking of the same product between any
		// of these reads and the final booking could otherwise leave this correction acting on
		// a total that is no longer current.
		return DatabaseService::GetInstance()->InTransaction(function () use ($productId, $locationId, $netInTareUnit, $location, $bestBeforeDate)
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

			// ADR-0033 decision 5: the location's TOTAL, not one merged row. Backstock-fed
			// refills (TransferProduct()) and a real due date or a live label (which, since
			// ADR-0033 decision 3, can now keep a row from ever being merged by the
			// maintenance command) can all leave more than one row stocked here at once; this
			// sums across every one of them rather than requiring compaction to have already
			// collapsed them into a single row.
			$currentAmountAtLocation = array_sum(array_map(fn($row) => (float)$row->amount, $stockAtLocation));

			$comparison = self::CompareAmounts($newAmount, $currentAmountAtLocation);

			if ($comparison === 0)
			{
				// A matching reading books nothing (ADR-0033 decision 5): the vessel already
				// reads what is on record. StockApiController::WeighLocation() treats this as
				// success with an empty result rather than delegating to StockTransactions(),
				// which requires a transaction id to exist.
				return '';
			}

			if ($comparison > 0)
			{
				if ($bestBeforeDate === null)
				{
					throw new \Exception('The weighed amount is higher than what is on record; a best_before_date is required for the new stock entry, since one cannot be guessed from the rows already here');
				}

				// Same defaults InventoryProduct() already uses for its own positive
				// correction (ADR-0033 decision 5, resolving the ADR's open question 2):
				// last price, last shopping location, purchased today. An arbitrary existing
				// lot's own price/location/date would misrepresent what was actually added.
				return $this->AddProduct($productId, $newAmount - $currentAmountAtLocation, $bestBeforeDate,
					self::TRANSACTION_TYPE_INVENTORY_CORRECTION, date('Y-m-d'), $productDetails->last_price,
					$locationId, $productDetails->last_shopping_location_id);
			}

			// Lower reading: consume the difference in ordinary consume order, restricted to
			// this location - existing rows elsewhere are never touched, and a row emptied
			// completely here retires its label the same way any other full consume does
			// (tested separately from the maintenance merge exclusion, ADR-0033 acceptance
			// prerequisite 4).
			return $this->ConsumeProduct($productId, $currentAmountAtLocation - $newAmount, false,
				self::TRANSACTION_TYPE_INVENTORY_CORRECTION, 'default', null, $locationId);
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
				$amountComparison = self::CompareAmounts($totalAmount, $logRow->amount);

				// stock.amount is a float column and CompactStockEntries() sums it in SQL, so an
				// exact `== 0` comparison here would miss by a rounding hair (e.g. purchases of
				// 0.1 and 0.2 merge to 0.30000000000000004) and leave a phantom near-zero row
				// behind. Compared against the shared tolerance rather than round()ed to two decimal
				// places: that convention's 0.005-unit threshold could itself destroy a real
				// small remainder, e.g. a 0.004 lot compacted into a larger purchase and then
				// separated again by undoing the larger one alone (maintainer decision, #487).
				if ($amountComparison < 0)
				{
					// This booking's own amount is larger than what the matched row(s) currently
					// hold - something else has already reduced the entry below this purchase's
					// contribution - so there is nothing to correctly subtract it from.
					throw new \Exception('Booking cannot be undone: its stock entry holds less than this booking added');
				}

				if ($amountComparison == 0)
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
				$rebuiltStockRow = [
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
				];

				// stock_measurement_coherence_check (migrations/0275.pgsql.sql) requires
				// amount = 1 (and open = 1) on any row carrying a measurement. An ordinary
				// whole-take consume of a measured container always logs amount -1, so this
				// rebuild's amount is always exactly 1 - but a ledger rescale of this
				// booking's own amount (MergeProducts() itself now refuses this before
				// writing when the factor is not 1 - issue #546 - but
				// trg_cascade_change_qu_id_stock*'s own rescale of stock_log on a single
				// product's own qu_id_stock change is not guarded the same way) can leave a
				// measured consume booking whose amount is no longer -1. Inserting that
				// rebuild would violate the CHECK outright with a raw 23514, which
				// BaseApiController's own generic PDOException handling would surface only
				// as a generic "database rejected this request" message - never explaining
				// what specifically could not be restored. Refuse truthfully here instead,
				// before any row is touched, exactly as this class refuses every other
				// undo shape it cannot safely reverse.
				if ($rebuiltStockRow['opened_amount'] !== null && self::CompareAmounts($rebuiltStockRow['amount'], 1.0) !== 0)
				{
					throw new \Exception('Booking cannot be undone: its measured container amount is inconsistent with a single stock unit and cannot be safely restored');
				}

				// A booking with a stock_row_id (set by ConsumeProduct() above for every
				// CONSUME and negative INVENTORY_CORRECTION booking from here on) whose own
				// row is gone at undo time - a whole-take consume deletes it, and
				// CompactStockEntries() can remove it too, if a later matching purchase
				// merged something into a different surviving row - is rebuilt under that
				// exact same id rather than a fresh auto-increment one, so a later-undone
				// TRANSFER_TO/FROM or PRODUCT_OPENED booking that named this row still finds
				// it: those undos now refuse outright rather than falling back once their
				// own stock_row_id is set, and a plain re-insert used to sever that match
				// (#488, fourth review round). This schema's id columns are GENERATED BY
				// DEFAULT AS IDENTITY, which accepts an explicit value with no special
				// syntax, unlike GENERATED ALWAYS - but accepting it is not the same as the
				// sequence knowing about it: AdvanceIdentitySequence() has to tell it
				// explicitly, since nothing else does (CodeRabbit review of PR #531 - an
				// earlier version of this comment claimed the sequence could never reissue
				// a once-used id, which does not hold after DatabaseImporter::Import() has
				// run; see that call for why). Called before the insert below, not after -
				// unlike a plain advance-then-insert, the choice of which id to insert under
				// depends on what it reports.
				//
				// AdvanceIdentitySequence() refuses - advancing nothing, drawing no
				// nextval() at all - when the gap between the sequence's current position
				// and this id is wider than PostgresDialect::MAX_SEQUENCE_ADVANCE_GAP; see
				// that constant's own docblock for the cost measurements behind the limit
				// (CodeRabbit review of PR #577). Only operator-imported data can open a gap
				// that wide (stock_log is not otherwise editable through the API), and a gap
				// a real import leaves is ordinarily small. A refusal here falls back to the
				// same plain re-insert under a fresh, ordinary id that a booking with no
				// recorded stock_row_id at all already gets below; a later TRANSFER_TO/FROM
				// or PRODUCT_OPENED undo that still names the abandoned id then refuses
				// safely on its own, exactly as it already does whenever that id's row is
				// simply gone (#488) - reusing the id is only ever an optimization that keeps
				// such a later undo working, never a correctness requirement of this rebuild
				// itself.
				//
				// A row that still exists - a partial consume never deletes it - is left
				// alone: this booking's own restored amount becomes a new, separate row
				// alongside it, exactly as before. A booking recorded before stock_row_id
				// was tracked for consumes (it is null) also keeps that same plain-insert
				// behaviour: there is no id to preserve.
				if ($logRow->stock_row_id !== null && $this->DB->stock()->where('id = :1', $logRow->stock_row_id)->fetch() === null)
				{
					// GetDialect() rather than a hardcoded PostgresDialect reference, so this
					// stays a no-op that always reports success on any future engine that
					// has not implemented it, the same as ResyncGeneratedIdCounters().
					$sequenceAdvanced = DatabaseService::GetInstance()->GetDialect()->AdvanceIdentitySequence(
						DatabaseService::GetInstance()->GetDbConnectionRaw(), 'stock', 'id', $logRow->stock_row_id + 1
					);

					if ($sequenceAdvanced)
					{
						$rebuiltStockRow['id'] = $logRow->stock_row_id;
					}
				}

				// The existence check above and the insert below are two separate
				// statements: AdvanceIdentitySequence() closes the narrower window between
				// its own read and its own nextval() draw (#584), but a wider one remains
				// open the whole time this method runs between that existence check and
				// this insert - an entirely different connection can insert and commit a
				// real row under this exact id in between, which no sequence check can see
				// (a real INSERT never has to draw from the sequence at all if its own
				// caller already resolved its id some other way, and even when it does,
				// nothing here observes that connection's commit until this statement
				// itself runs). A plain explicit-id INSERT would then collide outright.
				//
				// ON CONFLICT (id) DO NOTHING - rather than a savepoint plus catching the
				// resulting unique-violation - makes the INSERT itself the single
				// authoritative check of whether this id is still free, at the exact moment
				// it actually runs rather than at the moment this method decided to try it;
				// no savepoint is needed because a no-op ON CONFLICT arm never aborts the
				// enclosing transaction the way an uncaught unique-violation would. Booleans
				// are normalised to int first: unlike LessQL's own createRow()->save() below,
				// a raw PDOStatement::execute() array binds every value as a string, and
				// PHP's (string) cast of false is "" - not "0" - which the `open` column's
				// own SMALLINT type rejects outright.
				if (isset($rebuiltStockRow['id']))
				{
					$columns = array_keys($rebuiltStockRow);
					$values = array_map(fn($value) => is_bool($value) ? (int)$value : $value, array_values($rebuiltStockRow));

					$insert = DatabaseService::GetInstance()->GetDbConnectionRaw()->prepare(
						'INSERT INTO stock (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ') ON CONFLICT (id) DO NOTHING'
					);
					$insert->execute($values);

					if ($insert->rowCount() === 0)
					{
						// Someone else's real, committed row already holds this id - the
						// same fresh-id fallback a lost sequence-advance race already takes.
						unset($rebuiltStockRow['id']);
					}
					else
					{
						// This bypasses LessQL (the only reason the explicit-id case ever
						// needed a raw statement at all), so the changed-time and MQTT/Influx
						// "did this request write anything" bookkeeping LessQL's own query
						// callback would otherwise have handled for this insert has to be
						// done here instead - DatabaseService::MarkDbChanged()'s own docblock
						// names exactly this situation ("code that prepares its own
						// statements on the raw connection").
						DatabaseService::GetInstance()->MarkDbChanged();

						if (!DatabaseService::GetInstance()->IsBookkeeping())
						{
							DatabaseService::GetInstance()->MarkDataChanged();
						}
					}
				}

				if (!isset($rebuiltStockRow['id']))
				{
					$stockRow = $this->DB->stock()->createRow($rebuiltStockRow);
					$stockRow->save();
				}

				// Update log entry
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_TRANSFER_TO)
			{
				// A whole-row transfer (TransferProduct()'s "if" branch) relocates the same
				// physical row rather than creating a new one, so its correlated
				// TRANSFER_FROM booking names that very same stock_row_id. Detected here so
				// undo can move that row back in place - preserving its id, and so any label
				// printed against it (#491/#483) - instead of this branch deleting it and
				// TRANSFER_FROM's own undo below rebuilding it under a new one. Rebuilding
				// under a new id is exactly what broke a move_on_open product's opening: its
				// PRODUCT_OPENED booking's own stock_row_id named the pre-transfer row, which
				// the old delete-and-rebuild made stale by the time that booking's own undo
				// ran (#488). UndoTransaction()/the correlation group above always undo
				// TRANSFER_TO before its correlated TRANSFER_FROM (higher log id -
				// TransferProduct() saves FROM first), so this half performs the whole move,
				// restoring every attribute the transfer itself changed - location_id and
				// best_before_date (a freezing transfer's adjusted due date is exactly this
				// one) - from what the correlated FROM booking recorded; amount is untouched,
				// since a whole-row transfer never changes it. TRANSFER_FROM's own undo below
				// recognizes the same case and leaves `stock` alone.
				//
				// Audited for the same gap CodeRabbit found elsewhere (finding 4128248701,
				// PR #618): neither this branch nor the split/legacy branch below it ever
				// restores opened_amount/opened_qu_id from a log row - a transfer moves or
				// splits `stock.amount` in place and leaves whatever measurement the row
				// already carries untouched. No guard needed here; see TRANSFER_FROM's own
				// rebuild path below for the one TRANSFER* shape that does restore a
				// measurement and is guarded accordingly.
				$correlatedFrom = $logRow->correlation_id !== null
					? $this->DB->stock_log()->where('correlation_id = :1 AND transaction_type = :2', $logRow->correlation_id, self::TRANSACTION_TYPE_TRANSFER_FROM)->fetch()
					: null;
				$wholeRowTransfer = $correlatedFrom !== null && $logRow->stock_row_id !== null && $logRow->stock_row_id === $correlatedFrom->stock_row_id;

				// A whole-row pairing only relocates in place when the row still holds
				// exactly what this booking moved - still at the destination location, its
				// amount unchanged since (within the shared tolerance, the same comparison the
				// split/legacy branch below already uses for its own arithmetic). Something
				// else can dirty it first: CompactStockEntries() can fold another live lot
				// into this very row, keeping its id as the merge survivor while summing in
				// units this transfer never touched. Relocating the *whole* row then would
				// carry that other lot's units back to the source with it, with no booking
				// to account for them (#488, fourth review round). When it is not clean,
				// this falls through to the same subtract-and-rebuild path a split transfer
				// already uses below - including that path's own "row is gone entirely"
				// refusal - rather than guessing which part of the row's current amount
				// belongs to this transfer.
				$cleanRelocateRow = null;
				if ($wholeRowTransfer)
				{
					// stock_id is required alongside id (#555, fifth review round): a
					// GENERATED BY DEFAULT AS IDENTITY sequence is not guaranteed forward-
					// only on its own (ResyncGeneratedIdCounters() used to be able to move
					// one backward after a batch deleted the newest rows - fixed in
					// PostgresDialect.php, but this match does not get to assume every
					// database it ever runs against already has that fix), so an id alone
					// can land on an unrelated row that happens to reuse it. Requiring the
					// booking's own stock_id too means a reused id can only ever match a row
					// that is still genuinely part of the same lot, refusing otherwise
					// instead of relocating a stranger.
					$cleanRelocateRow = $this->DB->stock()->where('id = :1 AND stock_id = :2', $logRow->stock_row_id, $logRow->stock_id)->fetch();
					if ($cleanRelocateRow !== null && ($cleanRelocateRow->location_id !== $logRow->location_id || self::CompareAmounts($cleanRelocateRow->amount, abs($logRow->amount)) != 0))
					{
						$cleanRelocateRow = null;
					}
				}

				if ($cleanRelocateRow !== null)
				{
					// This branch is the one writing the source location back onto the row
					// (LockUndoLocation() is otherwise only invoked for the transaction types
					// listed above, which does not include TRANSFER_TO), so it has to check
					// here too - the stock_location_id_fkey constraint would otherwise abort
					// this write with a raw PDOException, translated at the API boundary into
					// the generic "Location does not exist" rather than this method's own
					// truthful, undo-specific refusal.
					$this->LockUndoLocation($correlatedFrom->location_id);

					$cleanRelocateRow->update([
						'location_id' => $correlatedFrom->location_id,
						'best_before_date' => $correlatedFrom->best_before_date
					]);
				}
				else
				{
					// A transfer that split a stock entry (TransferProduct()'s "else"
					// branch) creates a new row at the destination rather than reusing one,
					// so a second split transfer into the same (stock_id, location) leaves
					// more than one row there. Matching by (stock_id, location) alone, as
					// this used to, picked whichever row came back first and subtracted this
					// booking's amount from it regardless of whether it was the row this
					// booking actually created, driving it negative while the other live
					// contribution at the same location went untouched (#489 C2).
					// stock_row_id (set by TransferProduct() above for every booking from
					// here on) names the exact row this booking landed on, so it is matched
					// first - and, unlike the whole-row case above, a booking with a
					// stock_row_id that no longer resolves is refused outright rather than
					// falling back to a descriptive match: two other live bookings' rows can
					// coincidentally share the very same (stock_id, amount, location), and
					// the fallback cannot tell them apart from the one this booking actually
					// describes (#488, second review round). A booking recorded before
					// stock_row_id was tracked for transfers falls back to (stock_id,
					// location) alone, null-safely (ADR-0029 keeps both columns nullable) -
					// but only when that pair is unambiguous, for the same reason.
					if ($logRow->stock_row_id !== null)
					{
						$stockRow = $this->DB->stock()->where('id = :1 AND stock_id = :2 AND location_id IS NOT DISTINCT FROM :3', $logRow->stock_row_id, $logRow->stock_id, $logRow->location_id)->fetch();
						if ($stockRow === null)
						{
							throw new \Exception('Booking cannot be undone: its destination stock entry no longer exists');
						}
					}
					else
					{
						$candidateRows = $this->DB->stock()->where('stock_id = :1 AND location_id IS NOT DISTINCT FROM :2', $logRow->stock_id, $logRow->location_id)->fetchAll();
						if (count($candidateRows) === 0)
						{
							throw new \Exception('Booking does not exist or was already undone');
						}
						if (count($candidateRows) > 1)
						{
							throw new \Exception('Booking cannot be undone: its destination holds more than one stock entry sharing this lot and cannot be unambiguously reversed');
						}
						$stockRow = $candidateRows[0];
					}

					// stock.amount is a float column and CompactStockEntries() sums it in
					// SQL (see the PURCHASE branch's own comment above), so an exact `== 0`
					// comparison here would miss a residue by a rounding hair and leave a
					// phantom near-zero row at the destination behind (#470) - but comparing
					// against the shared tolerance rather than rounding to two decimals, so a
					// real small remainder (e.g. a compacted 0.004 lot) is kept rather than
					// deleted, and a shortfall of a few thousandths still refuses rather
					// than silently clearing to zero (maintainer decision, #487).
					$newAmount = $stockRow->amount - $logRow->amount;
					$amountComparison = self::CompareAmounts($stockRow->amount, $logRow->amount);
					if ($amountComparison < 0)
					{
						throw new \Exception('Booking cannot be undone: its destination stock entry holds less than this booking added');
					}

					if ($amountComparison == 0)
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
				}

				// Update log entry
				$this->MarkBookingUndone($logRow);
			}
			elseif ($logRow->transaction_type === self::TRANSACTION_TYPE_TRANSFER_FROM)
			{
				// Symmetric detection with TRANSFER_TO above.
				$correlatedTo = $logRow->correlation_id !== null
					? $this->DB->stock_log()->where('correlation_id = :1 AND transaction_type = :2', $logRow->correlation_id, self::TRANSACTION_TYPE_TRANSFER_TO)->fetch()
					: null;
				$wholeRowTransfer = $correlatedTo !== null && $logRow->stock_row_id !== null && $logRow->stock_row_id === $correlatedTo->stock_row_id;

				// Symmetric with TRANSFER_TO above: only treat the pairing as already
				// handled when the row is back home, at this exact booking's own location,
				// holding what this booking removed. The correlated TRANSFER_TO booking
				// undoes first (higher log id) and either relocated the row here already, or
				// - not clean itself - left it dirtied at the destination instead; this
				// check reads the row's actual current state rather than assuming which one
				// happened.
				$cleanRelocatedHome = null;
				if ($wholeRowTransfer)
				{
					// stock_id required alongside id, same reasoning as TRANSFER_TO above
					// (#555, fifth review round).
					$cleanRelocatedHome = $this->DB->stock()->where('id = :1 AND stock_id = :2', $logRow->stock_row_id, $logRow->stock_id)->fetch();
					if ($cleanRelocatedHome !== null && ($cleanRelocatedHome->location_id !== $logRow->location_id || self::CompareAmounts($cleanRelocatedHome->amount, abs($logRow->amount)) != 0))
					{
						$cleanRelocatedHome = null;
					}
				}

				if ($cleanRelocatedHome !== null)
				{
					// That correlated TRANSFER_TO booking, undone first (higher log id),
					// already moved the row back to this booking's own location and
					// restored its best_before_date - both from data this exact
					// TRANSFER_FROM booking supplied. Nothing further to do to `stock`.
				}
				else
				{
					// Prefer the exact source row this booking took from, when known, over
					// the (stock_id, location) pair alone - and, like TRANSFER_TO above,
					// refuse outright rather than fall back to a descriptive match when a
					// stock_row_id no longer resolves, since another live booking's row can
					// coincidentally match the same columns (#488, second review round).
					if ($logRow->stock_row_id !== null)
					{
						$stockRow = $this->DB->stock()->where('id = :1 AND stock_id = :2 AND location_id IS NOT DISTINCT FROM :3', $logRow->stock_row_id, $logRow->stock_id, $logRow->location_id)->fetch();
					}
					else
					{
						$candidateRows = $this->DB->stock()->where('stock_id = :1 AND location_id IS NOT DISTINCT FROM :2', $logRow->stock_id, $logRow->location_id)->fetchAll();
						if (count($candidateRows) > 1)
						{
							throw new \Exception('Booking cannot be undone: its source holds more than one stock entry sharing this lot and cannot be unambiguously reversed');
						}
						$stockRow = $candidateRows[0] ?? null;
					}

					if ($stockRow === null)
					{
						// Rebuilding here only ever adds back this exact booking's own
						// amount, at this exact booking's own location - it cannot merge
						// into, or steal from, any other row, unlike the descriptive
						// fallbacks this method refuses to fall back on elsewhere (#488,
						// third/fourth review rounds). That makes it safe regardless of
						// whether stock_row_id resolved: a whole-row transfer left dirty by
						// something else (handled by the branch above, which falls through
						// to here when it is not clean) rebuilds exactly as a legacy booking
						// with no stock_row_id always has, including any measurement
						// (ADR-0022 decision 9; #522 M22), which TransferProduct() mirrors
						// onto this booking for exactly this purpose - the same fields
						// ConsumeProduct()'s own bookings restore a fully-taken entry with,
						// in the CONSUME branch above.
						$rebuiltStockRow = [
							'product_id' => $logRow->product_id,
							'amount' => $logRow->amount * -1,
							'best_before_date' => $logRow->best_before_date,
							'purchased_date' => $logRow->purchased_date,
							'stock_id' => $logRow->stock_id,
							'price' => $logRow->price,
							'location_id' => $logRow->location_id,
							'opened_date' => $logRow->opened_date,
							'open' => $logRow->opened_date !== null,
							'note' => $logRow->note,
							'shopping_location_id' => $logRow->shopping_location_id,
							'opened_amount' => $logRow->opened_amount,
							'opened_qu_id' => $logRow->opened_qu_id,
							'opened_tare' => $logRow->opened_tare,
							'opened_measured_at' => $logRow->opened_measured_at
						];

						// Same gap as the CONSUME branch above, found by CodeRabbit review
						// of PR #618 (finding 4128248701): a whole-row measured transfer
						// mirrors opened_amount/opened_qu_id onto this exact TRANSFER_FROM
						// booking for the same reason ConsumeProduct() mirrors it onto a
						// CONSUME booking (comment above), and this method's own #546
						// narrowing now permits a ledger-only rescale of that booking's own
						// amount (trg_cascade_change_qu_id_stock's `UPDATE stock_log SET
						// amount = amount * v_factor ...` touches every stock_log row of the
						// product, not only CONSUME ones, and MergeProducts() only refuses on
						// a live `stock` row). Refuse truthfully here instead of letting the
						// rebuild violate stock_measurement_coherence_check outright.
						if ($rebuiltStockRow['opened_amount'] !== null && self::CompareAmounts($rebuiltStockRow['amount'], 1.0) !== 0)
						{
							throw new \Exception('Booking cannot be undone: its measured container amount is inconsistent with a single stock unit and cannot be safely restored');
						}

						$stockRow = $this->DB->stock()->createRow($rebuiltStockRow);
						$stockRow->save();
					}
					else
					{
						// Reviewed for the same class of defect as TRANSFER_TO above:
						// compared against the shared tolerance and refused rather than risking
						// a negative row, even though undoing a FROM booking only ever adds
						// back what it removed and so cannot reach a negative result unless
						// the row was already invalid beforehand.
						$newAmount = $stockRow->amount - $logRow->amount;
						$amountComparison = self::CompareAmounts($stockRow->amount, $logRow->amount);
						if ($amountComparison < 0)
						{
							throw new \Exception('Booking cannot be undone: its source stock entry holds less than this booking removed');
						}

						$stockRow->update([
							'amount' => $newAmount
						]);
					}
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
				//
				// Audited for the same gap CodeRabbit found in the CONSUME/STOCK_EDIT_OLD/
				// TRANSFER_FROM branches (finding 4128248701, PR #618): this branch never
				// restores $logRow's own opened_amount/opened_qu_id/opened_tare/
				// opened_measured_at onto `stock` - it always writes null unconditionally,
				// below - so a ledger-only rescale of this booking's amount cannot make this
				// specific write violate stock_measurement_coherence_check. No guard needed.
				//
				// Matched on stock_row_id when the booking has one (set by OpenProduct() for
				// every booking from here on - a stale one is no longer possible for the
				// move_on_open case a prior fix's fallback here targeted, since TRANSFER_TO/
				// FROM above now preserve a whole-row transfer's row id through its own
				// undo instead of deleting and rebuilding it). A booking with a stock_row_id
				// must match that exact row, or refuse outright: it is NOT safe to fall back
				// to a descriptive (stock_id, amount, purchased_date, open, location) match
				// in that case, because another live booking's row can coincidentally share
				// every one of those columns - three whole-row-opened entries of the same
				// product, purchased date and price, all sitting open at the same location,
				// are indistinguishable by them alone, and CompactStockEntries() merging two
				// of the three (grouped apart from the third only by its own edited note,
				// which this match does not consider) let an earlier version of this
				// fallback close the untouched third entry while the booking actually being
				// undone stayed marked live (#488, third review round). Existence alone is
				// also not enough even when stock_row_id resolves: that same
				// CompactStockEntries() merge can keep this row's id while overwriting its
				// amount with the group's sum, so the amount is verified too, within
				// the shared tolerance.
				//
				// Only a booking recorded before stock_row_id was tracked for openings (it
				// is null) uses the descriptive fallback, refusing unless exactly one row
				// matches; `purchased_date = :3` never matched NULL to NULL either, so a
				// purchase with no purchased_date used to match and update zero rows while
				// the booking was still marked undone regardless (#504 M4).
				//
				// stock_id is required alongside id (#555, fifth review round): a
				// GENERATED BY DEFAULT AS IDENTITY sequence is not guaranteed forward-only
				// on its own (ResyncGeneratedIdCounters() used to be able to move one
				// backward after a batch deleted the newest rows, reissuing a deleted row's
				// id to an unrelated later purchase - fixed in PostgresDialect.php, but this
				// match does not get to assume every database it runs against already has
				// that fix), so id alone is not enough to trust a match against.
				if ($logRow->stock_row_id !== null)
				{
					$stockRow = $this->DB->stock()->where('id = :1 AND stock_id = :2', $logRow->stock_row_id, $logRow->stock_id)->fetch();
					if ($stockRow === null || self::CompareAmounts($stockRow->amount, $logRow->amount) != 0)
					{
						throw new \Exception('Booking cannot be undone: the stock entry it opened no longer exists in that state');
					}
				}
				else
				{
					$candidateRows = $this->DB->stock()->where('stock_id = :1 AND amount = :2 AND purchased_date IS NOT DISTINCT FROM :3 AND open = 1 AND location_id IS NOT DISTINCT FROM :4', $logRow->stock_id, $logRow->amount, $logRow->purchased_date, $logRow->location_id)->fetchAll();
					if (count($candidateRows) === 0)
					{
						throw new \Exception('Booking cannot be undone: the stock entry it opened no longer exists in that state');
					}
					if (count($candidateRows) > 1)
					{
						throw new \Exception('Booking cannot be undone: more than one stock entry matches the one this booking opened and it cannot be unambiguously reversed');
					}
					$stockRow = $candidateRows[0];
				}

				$stockRow->update([
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
				// Make sure there is a stock row still. stock_id is required alongside id
				// (#555, sixth review round): after #555, no application path reissues a
				// deleted row's id, but bin/victual-db-import still does - it reissues the
				// ids of source rows deleted above the source's own surviving maximum, and
				// an imported edit booking carries its own stock_row_id right along with it.
				// An edit never changes a row's stock_id, so requiring it here costs nothing
				// on any real edit; it just stops an id that got reused - by an import or any
				// future path - from being trusted as if it still named the same lot.
				$stockRow = $this->DB->stock()->where('id = :1 AND stock_id = :2', $logRow->stock_row_id, $logRow->stock_id)->fetch();

				if ($stockRow == null)
				{
					// CompactStockEntries() (#488 C1) can delete the row this booking's
					// stock_row_id names, if a later matching purchase or edit merged it into
					// a different surviving row - the merge itself books nothing, so the "no
					// later dependent booking" guard above cannot see it. Its units are not
					// necessarily gone (they likely live on in whichever row absorbed them),
					// so this says the entry is gone "in that state" rather than claiming the
					// booking itself never existed.
					throw new \Exception('Booking cannot be undone: its stock entry no longer exists in its edited state');
				}

				// The check above is blind to a merge that happens to keep this row's id
				// alive (the other row in the merge was the one deleted): the row still
				// exists, but CompactStockEntries() has overwritten its amount with the
				// whole group's sum. The correlated STOCK_EDIT_NEW booking - written by the
				// same edit that produced this OLD booking, and never touched since (the "no
				// later dependent booking" guard above already refused if anything did) -
				// records exactly what this row held immediately after the edit, before any
				// merge. If the row's amount has since moved away from that, restoring this
				// booking's pre-edit amount over it would silently discard whatever else the
				// merge folded in while leaving that other purchase's booking marked live.
				// Compared against the shared tolerance rather than rounded to two decimals: that
				// convention's 0.005-unit threshold missed any merge that added less than
				// 0.005 (e.g. a compacted 0.004 lot), letting the undo through to overwrite a
				// row that in fact held another purchase's units too (maintainer decision, #487).
				$correlatedNew = $this->DB->stock_log()->where('correlation_id = :1 AND transaction_type = :2', $logRow->correlation_id, self::TRANSACTION_TYPE_STOCK_EDIT_NEW)->fetch();
				if ($correlatedNew !== null && self::CompareAmounts($stockRow->amount, $correlatedNew->amount) != 0)
				{
					throw new \Exception('Booking cannot be undone: its stock entry has changed since this edit (likely merged with another entry) and the edit\'s own effect cannot be isolated');
				}

				$openedDate = $logRow->opened_date;
				$open = true;
				if ($openedDate == null)
				{
					$open = false;
				}

				// CodeRabbit review of PR #618 (finding 4128248701): EditStockEntry()
				// mirrors a measured entry's pre-edit opened_amount/opened_qu_id onto this
				// exact OLD booking (see its own comment and EditStockEntry()'s docblock)
				// while it always logs amount 1 - the entry's own pre-edit amount is
				// coherent by construction, since MeasureStockEntry() only ever measures a
				// single-unit row. But this booking sits live (undone = 0) for as long as
				// the edit itself is not undone, exactly like a CONSUME booking sits live
				// until its own undo - and this method's own #546 narrowing now permits a
				// ledger-only rescale of a live booking's amount (trg_cascade_change_qu_id_stock's
				// `UPDATE stock_log SET amount = amount * v_factor ...` touches every
				// stock_log row of the product, not only CONSUME ones, and MergeProducts()
				// only refuses on a live `stock` row). Refuse truthfully here instead of
				// letting the restore below violate stock_measurement_coherence_check
				// outright, exactly as the CONSUME branch above already does.
				if ($logRow->opened_amount !== null && self::CompareAmounts($logRow->amount, 1.0) !== 0)
				{
					throw new \Exception('Booking cannot be undone: its measured container amount is inconsistent with a single stock unit and cannot be safely restored');
				}

				$stockRow->update([
					'amount' => $logRow->amount,
					'best_before_date' => $logRow->best_before_date,
					'purchased_date' => $logRow->purchased_date,
					'price' => $logRow->price,
					'location_id' => $logRow->location_id,
					// The OLD booking records shopping_location_id the same as every other
					// edited column (see its own creation in EditStockEntry()), but this
					// restore array omitted it - so undoing an edit that had cleared a
					// store left it NULL instead of restoring whichever store the entry
					// held before the edit.
					'shopping_location_id' => $logRow->shopping_location_id,
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
				// stock_id required alongside id, same reasoning as STOCK_EDIT_OLD above
				// (#555, sixth review round): a measurement never changes a row's stock_id
				// either, so this costs nothing on any real measurement undo.
				$stockRow = $this->DB->stock()->where('id = :1 AND stock_id = :2', $logRow->stock_row_id, $logRow->stock_id)->fetch();

				if ($stockRow == null)
				{
					throw new \Exception('Booking does not exist or was already undone');
				}

				// Only the measurement columns are touched - MeasureStockEntry() never
				// changes amount, dates, price, location or note, so there is nothing else to
				// restore, and touching them here could clobber changes made by some other
				// booking on this entry since.
				//
				// Audited for the same gap CodeRabbit found elsewhere (finding 4128248701,
				// PR #618): this write never touches $stockRow->amount, and this trigger's
				// own guard already refuses a ledger-wide rescale while $stockRow itself
				// (not this log row) carries a live measurement - so `stock.amount` here is
				// always already 1 whenever opened_amount is restored. No guard needed.
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
	 * Re-assigns stock, stock_log, barcodes, QU conversions, chores, recipe positions/recipes,
	 * meal plan entries, shopping list entries, location minimums and child products to the
	 * kept product inside a single database transaction (rolled back on any error, including
	 * the refusals below - neither product is changed unless the whole merge succeeds). An
	 * amount column is multiplied by the stock QU conversion factor from the removed product's
	 * stock unit to the kept product's stock unit; a per-stock-unit price column is divided by
	 * that same factor instead, so amount * price - the row's monetary value - is unchanged by
	 * the merge (issue #503, M3: 500 g at 0.01/g was becoming 0.5 "kg" still priced at 0.01/kg,
	 * a thousandfold understatement). product_barcodes.last_price and recipes_pos.price_factor
	 * are deliberately left alone: the former is a total price for that barcode's own (amount,
	 * qu_id) pair, which this method does not touch, and the latter is a unitless cost
	 * multiplier, not a per-unit price (see the costs columns in db/pgsql/baseline/05_views_l3.sql).
	 * The stale cache__products_average_price/cache__products_last_purchased rows the removed
	 * product leaves behind are deleted, since nothing else does.
	 *
	 * The merge refuses outright, before any row is touched, when: the two products' stock
	 * units differ and no conversion between them exists (no factor-1 fallback - that would
	 * silently misinterpret the removed product's amounts and prices as already being in the
	 * kept unit); the resolved conversion factor is not greater than zero (a zero or negative
	 * factor would zero out or negate a rescaled amount, and divide-by-zero or negate a
	 * rescaled price); the removed product has a measured open container live in `stock` and
	 * the factor is not 1 (rescaling it would violate stock's measurement coherence CHECK,
	 * migrations/0275.pgsql.sql, which requires amount = 1 on any measured row - part of issue
	 * #546, whose sibling failure through trg_cascade_change_qu_id_stock's own rescale is not
	 * this method's to fix); or repointing the removed product's own child products to the
	 * kept product would leave the kept product with both a parent of its own and children of
	 * its own. enfore_product_nesting_level (migrations/0277.pgsql.sql) does reject that last
	 * shape too - it fires BEFORE INSERT OR UPDATE and refuses a row's own parent already
	 * having a parent - but only with a generic message and only once the repoint below is
	 * already mid-transaction; refusing it here first gives a clear, merge-specific message
	 * before anything is written.
	 *
	 * The measured-container check above does NOT also inspect `stock_log` for a live
	 * (`undone` = 0) measured consume booking with no live `stock` row - the shape
	 * ConsumeProduct() leaves behind when a whole measured container is taken. An earlier
	 * round of this fix did, and that over-refused: such a booking is permanent history that
	 * nothing ever clears, so it locked the merge forever with nothing left in stock to
	 * consume, weigh, or otherwise resolve. That booking's own undo is already refused
	 * truthfully by UndoBooking() itself (its CONSUME branch) if and when it is ever undone,
	 * which is where that protection belongs.
	 *
	 * @param int $productIdToKeep
	 * @param int $productIdToRemove
	 * @return void
	 * @throws \Exception When either product does not exist / is inactive, both ids are equal,
	 *                    one of the refusal conditions above applies, or any of the update
	 *                    statements fails
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

			if ($conversion == null && $productToRemove->qu_id_stock != $productToKeep->qu_id_stock)
			{
				// Falling back to a factor of 1 here (as this method used to) would silently
				// misread every moved amount and price as already being in the kept product's
				// unit - 500 g becoming "500 kg". trg_cascade_change_qu_id_stock refuses the
				// equivalent single-product unit change for the same reason; refuse the merge
				// the same way, before any row is touched.
				throw new \Exception('Cannot merge: no quantity unit conversion exists from $productIdToRemove\'s stock unit to $productIdToKeep\'s stock unit');
			}

			$factor = 1.0;
			if ($conversion != null)
			{
				$factor = $conversion->factor;
			}

			if ($factor <= 0)
			{
				// A non-positive factor would make "amount * factor" zero or negative and
				// "price / factor" a division by zero or a negative price. Nothing in
				// db/pgsql/baseline/01_tables.sql puts a CHECK on
				// quantity_unit_conversions.factor, and cache__quantity_unit_conversions_resolved
				// returns whatever is stored unfiltered - a NEGATIVE factor reaches this method
				// (MergeProductsTest::testMergeRefusesWhenTheResolvedConversionFactorIsNotPositive
				// stores one directly), though a factor of exactly 0 does not: inserting it
				// makes quantity_unit_conversions_INS's own inverse-row computation,
				// "1 / COALESCE(NEW.factor, 1)", raise a genuine division-by-zero (Postgres
				// raises on this for float8 too, unlike raw IEEE 754) before the row commits.
				// Refuse before any row is touched rather than let either arithmetic run.
				throw new \Exception('Cannot merge: quantity unit conversion factor must be greater than zero');
			}

			if ($factor != 1.0
				&& $this->DB->stock()->where('product_id = :1 AND opened_amount IS NOT NULL', $productIdToRemove)->fetch() != null)
			{
				// stock_measurement_coherence_check (migrations/0275.pgsql.sql) requires
				// amount = 1 on any row carrying a measurement. Rescaling amount by anything
				// other than 1 would violate that CHECK outright (a raw 23514) instead of
				// producing a meaningfully converted measurement, which nothing here attempts.
				// Refuse cleanly before any row is touched instead.
				//
				// Deliberately `stock` only, not also `stock_log` for a live, undone = 0
				// measured consume booking with no live `stock` row (the shape
				// ConsumeProduct() leaves behind when a whole measured container is fully
				// taken, migrations/0275.pgsql.sql). An earlier round of this fix checked
				// both, and that over-refused: such a booking is permanent history nothing
				// ever clears - the merge would have been locked forever with nothing left
				// in stock to consume, weigh, or otherwise resolve. That booking's own undo
				// is already refused truthfully by UndoBooking() itself (its CONSUME branch)
				// if and when it is ever undone (issue #546) - that is where this protection
				// belongs, not here on every future merge regardless of whether undo is ever
				// attempted.
				throw new \Exception('Cannot merge: $productIdToRemove has a measured open container and the unit conversion factor is not 1');
			}

			if ($productToKeep->parent_product_id != null
				&& $productToKeep->parent_product_id != $productIdToRemove
				&& $this->DB->products()->where('parent_product_id = :1', $productIdToRemove)->fetch() != null)
			{
				// enfore_product_nesting_level (migrations/0277.pgsql.sql) allows only one
				// level of nesting, checked both ways since that migration: a row cannot be
				// given a parent that itself already has a parent, and a row cannot be given a
				// parent while something else already treats the row itself as a parent. The
				// repoint below (child.parent_product_id = $productIdToKeep) WOULD hit the
				// first of those the moment the kept product has a parent of its own - the
				// trigger does not miss this shape - but only mid-transaction, after the
				// UPDATE statements above have already run, and only with its own generic
				// "Unsupported product nesting level detected" message. Refusing here first
				// gives a clear, merge-specific reason before anything is written at all. The
				// exception excludes the kept product's parent being the removed product
				// itself, which the block below clears in this same merge, leaving room for
				// exactly that repoint.
				throw new \Exception('Cannot merge: $productIdToRemove has sub products, and $productIdToKeep already has an unrelated parent product (only one level of nesting is supported)');
			}

			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ', price = price / ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE stock_log SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ', price = price / ' . $factor . ' WHERE product_id = ' . $productIdToRemove);

			// Neither cache__products_average_price nor cache__products_last_purchased is
			// cleaned up by trg_products_DELETE (which only clears
			// cache__quantity_unit_conversions_resolved) or by stock_log_UPD above (which
			// refreshes the row for the NEW, i.e. kept, product_id and has no reason to touch
			// the removed product's row at all). Left alone, the removed product's rows in
			// both caches would survive the merge, keyed by a product id that no longer exists.
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM cache__products_average_price WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM cache__products_last_purchased WHERE product_id = ' . $productIdToRemove);

			// last_price is a total price for this row's own (amount, qu_id) - a barcode's
			// typical purchase package, e.g. "500 g for $2.50" - not a per-stock-unit price:
			// public/viewjs/purchase.js sets it from the #price field in "total price" mode
			// right after prefilling that same field from the scanned barcode's own last_price
			// (purchase.js:414-417), and neither amount nor qu_id on this same row is touched
			// by this method. It is therefore left as-is, unlike stock.price/stock_log.price
			// above.
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE product_barcodes SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);

			// quantity_unit_conversions_INS/_UPD/_DEL (db/pgsql/baseline/06_triggers_a.sql)
			// keep an automatic inverse row in sync with every conversion, and
			// qu_conversions_custom_constraint_UPD refuses an UPDATE that would leave two rows
			// sharing the same (from_qu_id, to_qu_id, product_id) - which is exactly what
			// repointing would do wherever the kept product already defines the same pair,
			// including the common case where products_default_qu_conversions_INS
			// auto-created the same 1:1 purchase/consume/price -> stock pair on both products.
			// Dropping the removed product's conflicting row first, the same dedupe-then-move
			// rule as product_substitutions below (the kept product's own factor wins), lets
			// the DELETE trigger drop that row's own inverse along with it, so only the
			// genuinely new pairs are left to repoint.
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM quantity_unit_conversions quc_remove WHERE quc_remove.product_id = ' . $productIdToRemove . ' AND EXISTS (SELECT 1 FROM quantity_unit_conversions quc_keep WHERE quc_keep.product_id = ' . $productIdToKeep . ' AND quc_keep.from_qu_id = quc_remove.from_qu_id AND quc_keep.to_qu_id = quc_remove.to_qu_id)');
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE quantity_unit_conversions SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);

			// price_factor is a unitless cost multiplier applied on top of amount * price in
			// the recipe costs columns (db/pgsql/baseline/05_views_l3.sql), not a per-unit
			// price itself, so only amount - the ingredient quantity - is converted.
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE recipes_pos SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE recipes SET product_id = ' . $productIdToKeep . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE meal_plan SET product_id = ' . $productIdToKeep . ', product_amount = product_amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE shopping_list SET product_id = ' . $productIdToKeep . ', amount = amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);

			// chores.product_amount is converted the same way trg_cascade_change_qu_id_stock
			// converts it for a single product's own qu_id_stock change (same table, the same
			// "amount * factor" line). Left unhandled, TrackChore() would throw "Product does
			// not exist or is inactive" the next time this chore executed, since product_id
			// would still name the now-deleted removed product.
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE chores SET product_id = ' . $productIdToKeep . ', product_amount = product_amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);

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

			// product_location_min_stock carries a real FOREIGN KEY to products - unlike every
			// other table this method touches, and unlike product_substitutions above, both of
			// which do "application-level and trigger-level referential integrity, not
			// FK-level" (migrations/0279.pgsql.sql) - so a removed product with a location
			// minimum made the DELETE below fail outright with a foreign-key violation (issue
			// #503, M3's second symptom). Repointing follows the same dedupe-then-move shape as
			// product_substitutions above: a location where the kept product already has its
			// own minimum keeps that row untouched - the removed product's is dropped rather
			// than silently overwriting a minimum someone set deliberately on the surviving
			// product - and min_stock_amount is converted by the same factor as every other
			// amount above, so a surviving minimum still means the same physical quantity.
			DatabaseService::GetInstance()->ExecuteDbStatement('DELETE FROM product_location_min_stock plms_remove WHERE plms_remove.product_id = ' . $productIdToRemove . ' AND EXISTS (SELECT 1 FROM product_location_min_stock plms_keep WHERE plms_keep.product_id = ' . $productIdToKeep . ' AND plms_keep.location_id = plms_remove.location_id)');
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE product_location_min_stock SET product_id = ' . $productIdToKeep . ', min_stock_amount = min_stock_amount * ' . $factor . ' WHERE product_id = ' . $productIdToRemove);

			// products.parent_product_id: a dangling parent left on the removed product's own
			// children would point at a row that no longer exists, and their stock would stop
			// being aggregated under any parent at all. The kept product's own parent is
			// cleared first when it was the removed product - repointing next would otherwise
			// try to set it to itself - and only then are the removed product's children (if
			// any) repointed to the kept product; the guard above already refused the whole
			// merge if that repoint would have left the kept product with both a parent of its
			// own and children of its own at once.
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE products SET parent_product_id = NULL WHERE id = ' . $productIdToKeep . ' AND parent_product_id = ' . $productIdToRemove);
			DatabaseService::GetInstance()->ExecuteDbStatement('UPDATE products SET parent_product_id = ' . $productIdToKeep . ' WHERE parent_product_id = ' . $productIdToRemove);

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
	 * single entry holding the summed amount. Called only by the explicit maintenance command
	 * (bin/victual-compact-stock) since ADR-0033 decision 1 (2026-09-27) - never inline from a
	 * booking path. Safe to call directly in a test, as before.
	 *
	 * Candidate groups come from the stock_splits view (migrations/0290.pgsql.sql), which
	 * excludes: entries with per-unit labels (stock_id starting with "x"), entries with
	 * userfield values, entries carrying a measured remainder (opened_amount IS NOT NULL),
	 * entries with a real due date (best_before_date other than NULL or the 2999-12-31
	 * never-expires sentinel - ADR-0033 decision 3, closing issue #488's C1), and entries
	 * carrying a live stock_entry label (ADR-0033 decision 3, closing issue #491's H2). A row
	 * with a real due date or a live label is therefore never a merge candidate, regardless of
	 * how many other rows would otherwise match it.
	 *
	 * For each surviving group, inside its own database transaction: the group's own rows are
	 * locked (SELECT ... FOR UPDATE, ascending id) and eligibility, groups and totals are
	 * re-read fresh under those locks (ADR-0033 decision 3) - a label committed while this
	 * call waited for the locks is seen before anything is deleted, since
	 * LabelIdentityService::Issue() takes the same row lock before inserting a label and so
	 * either wins the race and is honoured here, or loses it and fails cleanly against a row
	 * already gone. A group sharing a stock_id with a row outside it is skipped outright
	 * (below) rather than merged - a split (partial open/transfer) can leave two different
	 * `stock` rows carrying the same stock_id, and rewriting "every row with this stock_id",
	 * the only kind of statement this method can issue since stock_id rather than id is the
	 * merge key, would corrupt that outside row's identity and history. A group is likewise
	 * skipped when an outside row's own stock_entry_origins lineage names one of this group's
	 * stock_ids as its origin (RecordSplitOrigin() leaves exactly that on the untouched
	 * remainder of an earlier partial open/transfer) - that outside row was never a merge
	 * candidate and must keep recording which purchase it actually split from, not whichever
	 * one happened to end up surviving this group's own merge. All stock and stock_log rows
	 * of an accepted group are rewritten to the surviving stock_id, the redundant stock rows
	 * are deleted and the kept row is set to the group's total amount. The split lineage in
	 * stock_entry_origins (see RecordSplitOrigin()) is rewritten with them. stock_log.stock_row_id
	 * is never rewritten by this method.
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

				// First pass: which rows are candidates right now, under the product lock -
				// used only to know what to lock next. LockProductStock() already serialises
				// this call against every other StockService write path for this product, so
				// the one thing that can still change between this read and the locks below
				// is a label issuance, which never takes that lock (ADR-0033 decision 3
				// explicitly keeps it that way - see LabelIdentityService::Issue()).
				$candidateGroups = $this->DB->stock_splits()->where('product_id = :1', $oneProductId)->fetchAll();
				if (count($candidateGroups) === 0)
				{
					return;
				}

				$candidateRowIds = [];
				foreach ($candidateGroups as $group)
				{
					foreach (explode(',', $group->id_group) as $id)
					{
						$candidateRowIds[] = (int)$id;
					}
				}
				$candidateRowIds = array_unique($candidateRowIds);
				sort($candidateRowIds);

				// The actual synchronisation boundary against a concurrent label issuance
				// (ADR-0033 decision 3): a real PostgreSQL row lock, taken in ascending id
				// order across every candidate row in one statement so this call cannot
				// deadlock against another multi-row locker ordering differently.
				// LabelIdentityService::Issue() locks its one target row the same way before
				// inserting into labels, so it either committed before this statement runs
				// (and is visible in the re-read below) or blocks on it until this call
				// commits or rolls back, at which point its row either no longer exists (this
				// call merged/deleted it - Issue() then fails cleanly with no label ever
				// inserted) or is exactly as this call left it.
				$placeholders = implode(',', array_fill(0, count($candidateRowIds), '?'));
				// This exact statement - the row lock this whole re-read strategy depends on -
				// is also the seam a test observes CompactStockEntries() genuinely paused at:
				// see tests/Pgsql/compact-stock-subprocess-helper.php's DatabaseService subclass,
				// installed via ReflectionProperty before this call, which pauses here when
				// VICTUAL_TEST_COMPACT_PAUSE_AT names this checkpoint. Never set outside the
				// test suite's own subprocess helper, so this line runs unpaused for every real
				// caller (bin/victual-compact-stock and any future one).
				DatabaseService::GetInstance()->ExecuteDbQuery("SELECT id FROM stock WHERE id IN ($placeholders) ORDER BY id ASC FOR UPDATE", $candidateRowIds);

				// The re-read this fix is about: every group belonging to this product,
				// current as of right now under the lock rather than from before it -
				// including eligibility, since a label committed between the first read
				// above and the row locks just taken removes its row from stock_splits here.
				$splittedStockEntries = $this->DB->stock_splits()->where('product_id = :1', $oneProductId)->fetchAll();

				foreach ($splittedStockEntries as $splittedStockEntry)
				{
					$stockIds = explode(',', $splittedStockEntry->stock_id_group);
					$idGroup = explode(',', $splittedStockEntry->id_group);

					// Newly-eligible-since-the-first-read guard: every id in this re-read group
					// must already be one this transaction actually locked above. Nothing that
					// takes LockProductStock() first can add a row here between the two reads -
					// that lock is held for the whole transaction - but ADR-0033 decision 3
					// deliberately leaves label issuance able to race unlocked, and issuance
					// only ever narrows eligibility (retiring an existing label is a distinct
					// operation this guard also has no way to exclude by construction). A row
					// that entered this group only after the lock was taken was never protected
					// by it, so rewriting it here would act on a row this transaction could not
					// prove nothing else was concurrently doing something to - skip the whole
					// group rather than merge on the strength of a lock never actually held.
					$idsOutsideLock = array_diff(array_map('intval', $idGroup), $candidateRowIds);
					if (count($idsOutsideLock) > 0)
					{
						continue;
					}

					// Shared stock_id guard (ADR-0033 decision 3): skip this whole group if any
					// of its stock_id values is also carried by a `stock` row this group does
					// not include. That other row's history and lineage must stay byte-for-byte
					// unchanged, and the UPDATE ... WHERE stock_id = '...' statements below have
					// no way to spare it - they rewrite every row sharing that stock_id, group
					// member or not.
					$idPlaceholders = implode(',', array_fill(0, count($idGroup), '?'));
					$stockIdPlaceholders = implode(',', array_fill(0, count($stockIds), '?'));
					$outsideRowCheck = DatabaseService::GetInstance()->ExecuteDbQuery(
						"SELECT 1 FROM stock WHERE stock_id IN ($stockIdPlaceholders) AND id NOT IN ($idPlaceholders) LIMIT 1",
						array_merge($stockIds, $idGroup)
					);
					if ($outsideRowCheck->fetchColumn() !== false)
					{
						continue;
					}

					// Lineage confinement (ADR-0033 decision 3's last sentence). Two guards,
					// for two different failure modes. RecordSplitOrigin() always stores the
					// FLATTENED origin (the ultimate purchase a chain of splits descends from,
					// never an intermediate parent - see its own docblock), so this table can
					// only ever link a stock_id to its true root: there is no chain to walk and
					// no cycle it could form, so "the root of X" is a single lookup, not a
					// recursion.
					//
					// Guard 1 (round 2, unchanged): an outside row can have RecordSplitOrigin()
					// lineage naming one of the group's DISAPPEARING ids as its own origin - left
					// behind on the untouched remainder of an earlier partial open/transfer that
					// has nothing else to do with this group. The rewrite below only ever fires
					// for a disappearing id (stock_id_to_keep's own identity never changes), but
					// within that it rewrites every row naming one, group member or not, which
					// would silently reattribute a real, unrelated entry's history to a different
					// purchase than the one it actually split from. Unconditional: flattening
					// means a disappearing id can only ever be named directly by an outside row
					// when that id is itself a root, and round 3's probes below show exactly why
					// that can never be let through.
					$disappearingStockIds = [];
					foreach ($stockIds as $stockId)
					{
						if ($stockId != $splittedStockEntry->stock_id_to_keep)
						{
							$disappearingStockIds[] = $stockId;
						}
					}
					if (count($disappearingStockIds) > 0)
					{
						$disappearingPlaceholders = implode(',', array_fill(0, count($disappearingStockIds), '?'));
						$outsideLineageCheck = DatabaseService::GetInstance()->ExecuteDbQuery(
							"SELECT 1 FROM stock_entry_origins WHERE origin_stock_id IN ($disappearingPlaceholders) AND stock_id NOT IN ($stockIdPlaceholders) LIMIT 1",
							array_merge($disappearingStockIds, $stockIds)
						);
						if ($outsideLineageCheck->fetchColumn() !== false)
						{
							continue;
						}
					}

					// Guard 2 (round 3): round 2's guard alone still misses two shapes, both
					// real application flows, and both share one structural trait round 2's
					// guard ignored - the group spans MORE THAN ONE ORIGIN ROOT (two originally
					// separate purchases, only one of which has since been split):
					//   - An outside row can name the group's KEPT id as its origin instead of a
					//     disappearing one. The kept id's own identity never changes, but the
					//     merge still moves a DIFFERENT root's history onto it, which the outside
					//     row's lineage never signed up for.
					//   - A group member's OWN lineage can name an outside row as ITS origin (the
					//     member is an unopened remainder of a different root than its sibling).
					// Merging either silently reattributes one root's purchase price/history onto
					// the other, which prerequisite 1 forbids. But merging portions that all
					// descend from a SINGLE shared root changes no purchase's resolved root and
					// no root's total - that is ordinary compaction (e.g.
					// testUndoRefusesProductOpenedAfterExplicitMaintenanceMerge's second merge:
					// two opened portions of the same original purchase, one of them a second-
					// generation remainder of the other) and must stay allowed even though an
					// unrelated, still-live remainder of that same shared root sits outside the
					// group - that remainder's resolved root does not change either, only the
					// surviving id's spelling does. So this guard only ever runs when the group's
					// members resolve to more than one distinct root; when they all share one
					// root it is skipped entirely, deliberately including the kept id in the
					// outside-link check when it does run.
					$originRows = DatabaseService::GetInstance()->ExecuteDbQuery(
						"SELECT stock_id, origin_stock_id FROM stock_entry_origins WHERE stock_id IN ($stockIdPlaceholders)",
						$stockIds
					)->fetchAll(\PDO::FETCH_KEY_PAIR);
					$roots = [];
					foreach ($stockIds as $stockId)
					{
						$roots[$originRows[$stockId] ?? $stockId] = true;
					}
					if (count($roots) > 1)
					{
						$outsideLineageCheck = DatabaseService::GetInstance()->ExecuteDbQuery(
							"SELECT 1 FROM stock_entry_origins WHERE"
							. " (stock_id IN ($stockIdPlaceholders) AND origin_stock_id NOT IN ($stockIdPlaceholders))"
							. " OR (origin_stock_id IN ($stockIdPlaceholders) AND stock_id NOT IN ($stockIdPlaceholders))"
							. " LIMIT 1",
							array_merge($stockIds, $stockIds, $stockIds, $stockIds)
						);
						if ($outsideLineageCheck->fetchColumn() !== false)
						{
							continue;
						}
					}

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

					// This exact statement - the survivor's amount rewrite, the last statement
					// of a group actually merged - is the seam a test observes
					// CompactStockEntries() genuinely paused after a group's first rewrite: see
					// tests/Pgsql/compact-stock-subprocess-helper.php's DatabaseService subclass
					// for the 'after_first_rewrite' checkpoint.
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
		if (!array_key_exists('amount', $measurement) || !is_numeric($measurement['amount']) || !is_finite((float)$measurement['amount']) || $measurement['amount'] <= 0)
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
			if (!array_key_exists('tare', $measurement) || !is_numeric($measurement['tare']) || !is_finite((float)$measurement['tare']) || $measurement['tare'] < 0)
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
