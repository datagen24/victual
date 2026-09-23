<?php

namespace Victual\Services;

use LessQL\Result;

/**
 * Recipe operations beyond plain CRUD: shopping list integration, consuming a recipe's
 * ingredients from stock, and copying. The meal plan reuses recipes internally via the
 * RECIPE_TYPE_MEALPLAN_* shadow types below.
 */
class RecipesService extends BaseService
{
	const RECIPE_TYPE_MEALPLAN_DAY = 'mealplan-day'; // A recipe per meal plan day => name = YYYY-MM-DD
	const RECIPE_TYPE_MEALPLAN_WEEK = 'mealplan-week'; // A recipe per meal plan week => name = YYYY-WW (week number)
	const RECIPE_TYPE_MEALPLAN_SHADOW = 'mealplan-shadow'; // A recipe per meal plan recipe (for separated stock fulfillment checking) => name = YYYY-MM-DD#<meal_plan.id>
	const RECIPE_TYPE_NORMAL = 'normal'; // Normal / manually created recipes

	/**
	 * Puts every ingredient the stock cannot fulfill onto the (default) shopping list,
	 * adding to an existing shopping list entry when the product already has one.
	 * The ordered amount is the missing amount minus what is already on the list,
	 * unless the recipe is flagged not_check_shoppinglist.
	 *
	 * @param int $recipeId
	 * @param int[]|null $excludedProductIds Product ids to skip (null means none)
	 */
	public function AddNotFulfilledProductsToShoppingList($recipeId, $excludedProductIds = null)
	{
		$recipe = $this->DB->recipes($recipeId);
		$recipePositions = $this->GetRecipesPosResolved();

		if ($excludedProductIds == null)
		{
			$excludedProductIds = [];
		}

		foreach ($recipePositions as $recipePosition)
		{
			if ($recipePosition->recipe_id == $recipeId && !in_array($recipePosition->product_id, $excludedProductIds))
			{
				$product = $this->DB->products($recipePosition->product_id);
				$toOrderAmount = round(($recipePosition->missing_amount - $recipePosition->amount_on_shopping_list), 2);
				$quId = $product->qu_id_purchase;

				if ($recipe->not_check_shoppinglist == 1)
				{
					$toOrderAmount = round($recipePosition->missing_amount, 2);
				}

				// When the recipe ingredient option "Only check if any amount is in stock" is enabled,
				// any QU can be used and the amount is not based on qu_stock then
				// => Do the unit conversion here (if any)
				if ($recipePosition->only_check_single_unit_in_stock == 1)
				{
					$conversion = $this->DB->cache__quantity_unit_conversions_resolved()->where('product_id = :1 AND from_qu_id = :2 AND to_qu_id = :3', $recipePosition->product_id, $recipePosition->qu_id, $product->qu_id_stock)->fetch();
					if ($conversion != null)
					{
						$toOrderAmount = $toOrderAmount * $conversion->factor;
					}
					else
					{
						// No conversion exists => take the amount/unit as is
						$quId = $recipePosition->qu_id;
						$toOrderAmount = $recipePosition->missing_amount;
					}
				}

				if ($toOrderAmount > 0)
				{
					$alreadyExistingEntry = $this->DB->shopping_list()->where('product_id', $recipePosition->product_id)->fetch();
					if ($alreadyExistingEntry)
					{
						// Update
						$alreadyExistingEntry->update([
							'amount' => $alreadyExistingEntry->amount + $toOrderAmount
						]);
					}
					else
					{
						// Insert
						$shoppinglistRow = $this->DB->shopping_list()->createRow([
							'product_id' => $recipePosition->product_id,
							'amount' => $toOrderAmount,
							'qu_id' => $quId
						]);
						$shoppinglistRow->save();
					}
				}
			}
		}
	}

	/**
	 * Consumes all of the recipe's ingredients from stock in one stock transaction
	 * (capped at what is actually in stock; ingredients flagged "only check single unit
	 * in stock" are skipped) and, when the recipe produces a product, books the produced
	 * amount back in as self-production. For meal plan shadow recipes the produced
	 * product and servings come from the original recipe / meal plan entry.
	 *
	 * @param int $recipeId
	 * @throws \Exception When the recipe does not exist
	 */
	public function ConsumeRecipe($recipeId)
	{
		if (!$this->RecipeExists($recipeId))
		{
			throw new \Exception('Recipe does not exist');
		}

		$transactionId = uniqid();

		// Only which products the recipe names is read before any lock; recipes_pos_resolved
		// itself - stock_amount above all - is re-read fresh under the lock below, so what
		// this books is capped by current stock rather than a value read before the wait
		// (issue #458, PR #471 follow-up): a concurrent consume that shrinks an ingredient's
		// stock while this call queues on the lock is what the fresh read has to see.
		$ingredientProductIds = array_map(fn($row) => (int)$row->product_id, $this->DB->recipes_pos()->where('recipe_id', $recipeId)->fetchAll());

		DatabaseService::GetInstance()->InTransaction(function () use ($ingredientProductIds, $recipeId, &$transactionId)
		{
			// A recipe can name several ingredient products, and ConsumeProduct() below
			// always substitutes sub products for a recipe consume, so each ingredient's own
			// sub products are part of the set too (SubstitutionLockSet()) - locked here,
			// upfront and in ascending order, so two recipes (or a recipe and a direct
			// consume of one ingredient's sub product) touching an overlapping set always
			// request their first conflicting lock in the same order and queue rather than
			// deadlock (issue #458).
			$lockSet = [];
			foreach ($ingredientProductIds as $ingredientProductId)
			{
				$lockSet = array_merge($lockSet, StockService::GetInstance()->SubstitutionLockSet($ingredientProductId));
			}
			DatabaseService::GetInstance()->LockProductsStock($lockSet);

			// Re-read now that every lock in the set above is held, so stock_amount
			// reflects any booking that committed while this call waited on it.
			$recipePositions = $this->DB->recipes_pos_resolved()->where('recipe_id', $recipeId)->fetchAll();

			foreach ($recipePositions as $recipePosition)
			{
				if ($recipePosition->only_check_single_unit_in_stock == 0 && $recipePosition->stock_amount > 0)
				{
					$amount = $recipePosition->recipe_amount;
					if ($recipePosition->stock_amount > 0 && $recipePosition->stock_amount < $recipePosition->recipe_amount)
					{
						$amount = $recipePosition->stock_amount;
					}

					StockService::GetInstance()->ConsumeProduct($recipePosition->product_id, $amount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', $recipeId, null, $transactionId, true, true);
				}
			}
		});

		$recipe = $this->DB->recipes()->where('id = :1', $recipeId)->fetch();
		$productId = $recipe->product_id;
		$amount = $recipe->desired_servings;
		if ($recipe->type == self::RECIPE_TYPE_MEALPLAN_SHADOW)
		{
			// Use "Produces product" of the original recipe
			$mealPlanEntry = $this->DB->meal_plan()->where('id = :1', explode('#', $recipe->name)[1])->fetch();
			$recipe = $this->DB->recipes()->where('id = :1', $mealPlanEntry->recipe_id)->fetch();
			$productId = $recipe->product_id;
			$amount = $mealPlanEntry->recipe_servings;
		}

		if (!empty($productId))
		{
			$product = $this->DB->products()->where('id = :1', $productId)->fetch();
			$recipeResolvedRow = $this->DB->recipes_resolved()->where('recipe_id = :1', $recipeId)->fetch();
			StockService::GetInstance()->AddProduct($productId, $amount, null, StockService::TRANSACTION_TYPE_SELF_PRODUCTION, date('Y-m-d'), $recipeResolvedRow->costs_per_serving, null, null, $dummyTransactionId, $product->default_stock_label_type, $recipe->name);
		}
	}

	/**
	 * All rows of the recipes_pos_resolved view (each recipe ingredient with its stock
	 * fulfillment, missing amount and shopping list state) as plain objects.
	 *
	 * @return object[]
	 */
	public function GetRecipesPosResolved()
	{
		$sql = 'SELECT * FROM recipes_pos_resolved';
		return DatabaseService::GetInstance()->ExecuteDbQuery($sql)->fetchAll(\PDO::FETCH_OBJ);
	}

	/**
	 * The recipes_resolved view (per-recipe fulfillment summary), optionally filtered.
	 *
	 * @param string|null $customWhere Raw SQL WHERE fragment, or null for all rows
	 * @param array $customWhereParams Values for the positional "?" placeholders in $customWhere.
	 *              Anything engine specific (date arithmetic above all) belongs here rather than
	 *              in $customWhere, which has to stay portable across SQLite and PostgreSQL.
	 */
	public function GetRecipesResolved($customWhere = null, array $customWhereParams = []): Result
	{
		if ($customWhere == null)
		{
			return $this->DB->recipes_resolved();
		}
		else
		{
			return $this->DB->recipes_resolved()->where($customWhere, $customWhereParams);
		}
	}

	/**
	 * The name of the internal RECIPE_TYPE_MEALPLAN_WEEK recipe covering the given day,
	 * as "YYYY-WW".
	 *
	 * This deliberately reimplements SQLite's STRFTIME('%W', ...) in PHP - "week of year,
	 * Monday as first day of week 1, days before the year's first Monday are week 00".
	 * The name is written by the meal_plan triggers (migrations/0071.sql, 0073.sql,
	 * 0096.sql, 0139.sql on SQLite; victual_mealplan_week_name() on PostgreSQL, see
	 * db/pgsql/baseline/03_views_group1.sql), and anything looking a week recipe up has to
	 * produce byte identical output or the lookup silently stops matching. Neither PHP's
	 * "W" (ISO-8601, which shifts dates across the year boundary) nor PostgreSQL's
	 * to_char('WW') matches those semantics, hence the explicit arithmetic.
	 *
	 * @param string $day An ISO date (Y-m-d)
	 * @return string
	 */
	public static function GetMealPlanWeekRecipeName(string $day): string
	{
		$date = new \DateTimeImmutable($day);
		$firstOfJanuary = new \DateTimeImmutable($date->format('Y') . '-01-01');
		$firstMonday = $firstOfJanuary->modify('+' . ((8 - intval($firstOfJanuary->format('N'))) % 7) . ' days');

		if ($date < $firstMonday)
		{
			$week = 0;
		}
		else
		{
			$week = intdiv($firstMonday->diff($date)->days, 7) + 1;
		}

		return ltrim($date->format('Y') . '-' . sprintf('%02d', $week), '0');
	}

	/**
	 * Duplicates a recipe including its ingredients and nested (included) recipes,
	 * under a localised "Copy of ..." name.
	 *
	 * @param int $recipeId
	 * @return string Id of the new recipe
	 * @throws \Exception When the recipe does not exist
	 */
	public function CopyRecipe($recipeId)
	{
		if (!$this->RecipeExists($recipeId))
		{
			throw new \Exception('Recipe does not exist');
		}

		$newName = LocalizationService::GetInstance()->__t('Copy of %s', $this->DB->recipes($recipeId)->name);

		DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes (name, description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id) SELECT :new_name, description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id FROM recipes WHERE id = :recipe_id', ['recipe_id' => $recipeId, 'new_name' => $newName]);
		$lastInsertId = $this->DB->lastInsertId();
		DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes_pos (recipe_id, product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor) SELECT :last_insert_id, product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor FROM recipes_pos WHERE recipe_id = :recipe_id', ['recipe_id' => $recipeId, 'last_insert_id' => $lastInsertId]);
		DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) SELECT :last_insert_id, includes_recipe_id, servings FROM recipes_nestings WHERE recipe_id = :recipe_id', ['recipe_id' => $recipeId, 'last_insert_id' => $lastInsertId]);

		return $lastInsertId;
	}

	private function RecipeExists($recipeId)
	{
		$recipeRow = $this->DB->recipes()->where('id = :1', $recipeId)->fetch();
		return $recipeRow !== null;
	}
}
