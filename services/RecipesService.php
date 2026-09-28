<?php

namespace Victual\Services;

use LessQL\Result;
use Victual\Controllers\Users\PermissionMissingException;
use Victual\Controllers\Users\User;

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
	 * A self-production is a stock addition like any other, so it also requires
	 * STOCK_PURCHASE in addition to STOCK_CONSUME (maintainer decision on issue #532,
	 * 2026-09-26) - including when the recipe is a meal-plan shadow whose *original*
	 * recipe produces a product. The check itself is unconditional on $request (issue #532
	 * round 2): User::HasPermissions() only ever consults the ambient VICTUAL_USER_ID, never
	 * the request, so a caller that forgets to pass one must not be read as "skip the check" -
	 * that silently let a caller reach self-production without STOCK_PURCHASE, and no error
	 * at all, which is exactly the gap this check exists to close. $request only shapes the
	 * refusal: RecipesApiController passes the real request, so a refused HTTP call still
	 * throws the same PermissionMissingException User::CheckPermission() throws elsewhere
	 * (HandleApiCall() answers 403, as before); a caller with no request - the direct calls
	 * this method's own tests and dev tooling make, predating this permission - gets a plain
	 * \Exception instead when the ambient user lacks STOCK_PURCHASE, and nothing is booked
	 * either way. This only has to sit inside the transaction below, rather than in the
	 * controller (the enforcement boundary for every other permission), because *what* is
	 * being authorized - the resolved output, after following a meal-plan shadow to its
	 * original recipe - is only known once the lock the transaction takes is held.
	 *
	 * @param int $recipeId
	 * @param \Psr\Http\Message\ServerRequestInterface|null $request The current request, used
	 *              only to shape the refusal when the resolved output produces a product and
	 *              the acting (ambient VICTUAL_USER_ID) user lacks STOCK_PURCHASE - the check
	 *              itself always runs, request or not (see above).
	 * @throws \Exception When the recipe does not exist, or the acting user lacks
	 *              STOCK_PURCHASE for a producing recipe and no request was given to shape a
	 *              PermissionMissingException instead
	 * @throws \Victual\Controllers\Users\PermissionMissingException When a request was given
	 *              and the acting user lacks STOCK_PURCHASE for a recipe that produces stock
	 */
	public function ConsumeRecipe($recipeId, $request = null)
	{
		if (!$this->RecipeExists($recipeId))
		{
			throw new \Exception('Recipe does not exist');
		}

		$transactionId = uniqid();

		// Only which products the recipe (and any recipe it nests - recipes_pos_resolved
		// joins recipes_nestings_resolved, see db/pgsql/baseline/05_views_l3.sql) names is
		// read before any lock; recipes_pos_resolved's other columns - stock_amount above
		// all - are re-read fresh under the lock below, so what this books is capped by
		// current stock rather than a value read before the wait (issue #458, PR #471
		// follow-up): a concurrent consume that shrinks an ingredient's stock while this
		// call queues on the lock is what the fresh read has to see. Reading recipes_pos
		// alone here (as this used to) missed a nested recipe's ingredients entirely, so
		// the consume loop below - which does read recipes_pos_resolved - could lock one of
		// them late, inside ConsumeProduct(), out of the ascending order this whole scheme
		// exists to guarantee.
		$ingredientProductIds = array_map(fn($row) => (int)$row->product_id, $this->DB->recipes_pos_resolved()->where('recipe_id', $recipeId)->fetchAll());

		// Which product (if any) this recipe produces, resolved before locking purely to
		// learn its id for the lock set built below - this is structural configuration
		// (which row self-production books into), not stock state, so unlike stock_amount
		// below it does not need to be current at lock time; it is re-read inside the
		// transaction, under the lock, before it is used for anything that is.
		$outputProductRecipe = $this->DB->recipes()->where('id = :1', $recipeId)->fetch();
		$outputProductId = $outputProductRecipe->product_id;
		if ($outputProductRecipe->type == self::RECIPE_TYPE_MEALPLAN_SHADOW)
		{
			$outputMealPlanEntry = $this->DB->meal_plan()->where('id = :1', explode('#', $outputProductRecipe->name)[1])->fetch();
			$outputProductId = $this->DB->recipes()->where('id = :1', $outputMealPlanEntry->recipe_id)->fetch()->product_id;
		}

		DatabaseService::GetInstance()->InTransaction(function () use ($ingredientProductIds, $outputProductId, $recipeId, $request, &$transactionId)
		{
			// A recipe can name several ingredient products, and ConsumeProduct() below
			// always substitutes sub products for a recipe consume, so each ingredient's own
			// sub products are part of the set too (SubstitutionLockSet()) - locked here,
			// upfront and in ascending order, so two recipes (or a recipe and a direct
			// consume of one ingredient's sub product) touching an overlapping set always
			// request their first conflicting lock in the same order and queue rather than
			// deadlock (issue #458).
			//
			// The produced product, if any, joins the same ascending call rather than being
			// left to the lock AddProduct() below takes on its own (issue #494/H5): consuming
			// ingredients and booking the recipe's own output are now one transaction, so a
			// pair of recipes whose ingredient and output sets overlap but swap roles (A's
			// output is B's ingredient and vice versa) must still request their first
			// conflicting lock in the same order, or the two could deadlock against each other
			// instead of queuing.
			$lockSet = [];
			foreach ($ingredientProductIds as $ingredientProductId)
			{
				$lockSet = array_merge($lockSet, StockService::GetInstance()->SubstitutionLockSet($ingredientProductId));
			}
			if (!empty($outputProductId))
			{
				$lockSet[] = (int)$outputProductId;
			}
			DatabaseService::GetInstance()->LockProductsStock($lockSet);

			// The recipe's own "produces product", resolved once now that every lock in the
			// set above is held (issue #532): following a meal-plan shadow to its original
			// recipe here, rather than separately at booking time below, is what lets the
			// STOCK_PURCHASE check right after and the self-production booking further down
			// agree on what "the output" is, instead of each asking the question on its own.
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

			// A consumption that produces stock is a stock addition like any other and needs
			// STOCK_PURCHASE in addition to STOCK_CONSUME (maintainer decision on issue #532,
			// 2026-09-26): the built-in CHILD role holds STOCK_CONSUME without STOCK_PURCHASE,
			// and used to be able to add stock this way. Checked here, before any write (the
			// ingredient consumption below is one), against the output resolved just above so
			// a meal-plan shadow is judged by what its original recipe produces rather than by
			// the shadow's own (always empty) product_id.
			//
			// Unconditional on $request (issue #532 round 2): HasPermissions() only reads the
			// ambient VICTUAL_USER_ID, so gating the check itself on $request !== null let a
			// caller that simply forgot the argument reach self-production with no
			// STOCK_PURCHASE and no error - failing open. $request only decides which
			// exception shapes the refusal: with one, the same PermissionMissingException
			// User::CheckPermission() throws everywhere else (HandleApiCall() still answers
			// 403); without one, a plain refusal that still aborts this transaction before any
			// write. Either way nothing is booked - a caller with no request must instead
			// already hold STOCK_PURCHASE, exactly like one that does.
			if (!empty($productId) && !User::HasPermissions(User::PERMISSION_STOCK_PURCHASE))
			{
				if ($request !== null)
				{
					throw new PermissionMissingException($request, User::PERMISSION_STOCK_PURCHASE);
				}

				throw new \Exception('Permission missing: ' . User::PERMISSION_STOCK_PURCHASE);
			}

			// Re-read now that every lock in the set above is held, so stock_amount
			// reflects any booking that committed while this call waited on it.
			$recipePositions = $this->DB->recipes_pos_resolved()->where('recipe_id', $recipeId)->fetchAll();

			foreach ($recipePositions as $recipePosition)
			{
				if ($recipePosition->only_check_single_unit_in_stock == 0 && StockService::CompareAmounts($recipePosition->stock_amount, 0) > 0)
				{
					$consumeAmount = $recipePosition->recipe_amount;
					if (StockService::CompareAmounts($recipePosition->stock_amount, 0) > 0 && StockService::CompareAmounts($recipePosition->stock_amount, $recipePosition->recipe_amount) < 0)
					{
						$consumeAmount = $recipePosition->stock_amount;
					}

					StockService::GetInstance()->ConsumeProduct($recipePosition->product_id, $consumeAmount, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', $recipeId, null, $transactionId, true, true);
				}
			}

			// The recipe's own "produces product" is booked back in as self-production inside
			// the same transaction as the ingredient consumption above (issue #494/H5): an
			// output product that cannot be booked - inactive, for instance - must not leave
			// the ingredients it was made from consumed. $recipe/$productId/$amount are the
			// single resolution from above (issue #532), not re-read here - recipes_resolved
			// below is still re-read fresh under the lock, because unlike them,
			// costs_per_serving depends on ingredient prices a concurrent purchase could have
			// changed while this call queued.
			if (!empty($productId))
			{
				$product = $this->DB->products()->where('id = :1', $productId)->fetch();
				$recipeResolvedRow = $this->DB->recipes_resolved()->where('recipe_id = :1', $recipeId)->fetch();
				$dummyTransactionId = null;
				StockService::GetInstance()->AddProduct($productId, $amount, null, StockService::TRANSACTION_TYPE_SELF_PRODUCTION, date('Y-m-d'), $recipeResolvedRow->costs_per_serving, null, null, $dummyTransactionId, $product->default_stock_label_type, $recipe->name);
			}
		});
	}

	/**
	 * The product id ConsumeRecipe() would try to self-produce for $recipe, resolved the same
	 * way it resolves it: a meal-plan shadow's own product_id is always empty, so this follows
	 * the shadow to its original recipe first, exactly as the STOCK_PURCHASE check inside
	 * ConsumeRecipe() does. Used by views/recipes.blade.php to gate the consume button on the
	 * same output the server will actually check (issue #532), rather than on a shadow's own
	 * product_id, which is never a reliable signal for it.
	 *
	 * This is a plain, unlocked read for a UI eligibility hint, not an authorization decision -
	 * ConsumeRecipe() re-resolves the output itself, under its own lock, before booking
	 * anything, so a concurrent change between this read and a submit is not a race this
	 * method needs to guard against.
	 *
	 * @param object $recipe A row from the recipes table (id, type and name at least)
	 * @return int|string|null The product id, or empty when the recipe produces nothing
	 */
	public function GetEffectiveOutputProductId($recipe)
	{
		if ($recipe->type == self::RECIPE_TYPE_MEALPLAN_SHADOW)
		{
			$mealPlanEntry = $this->DB->meal_plan()->where('id = :1', explode('#', $recipe->name)[1])->fetch();
			return $this->DB->recipes()->where('id = :1', $mealPlanEntry->recipe_id)->fetch()->product_id;
		}

		return $recipe->product_id;
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

		// The three inserts are one copy (issue #494/H5): recipes_pos and recipes_nestings
		// both key off the new recipe row's id and off the source recipe still existing, so a
		// failure partway through - the second or third insert - must not leave the new
		// recipe (or its ingredients without its nestings) committed on its own.
		return DatabaseService::GetInstance()->InTransaction(function () use ($recipeId, $newName)
		{
			DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes (name, description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id) SELECT :new_name, description, picture_file_name, base_servings, desired_servings, not_check_shoppinglist, type, product_id FROM recipes WHERE id = :recipe_id', ['recipe_id' => $recipeId, 'new_name' => $newName]);
			$lastInsertId = $this->DB->lastInsertId();
			DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes_pos (recipe_id, product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor) SELECT :last_insert_id, product_id, amount, note, qu_id, only_check_single_unit_in_stock, ingredient_group, not_check_stock_fulfillment, variable_amount, price_factor FROM recipes_pos WHERE recipe_id = :recipe_id', ['recipe_id' => $recipeId, 'last_insert_id' => $lastInsertId]);
			DatabaseService::GetInstance()->ExecuteDbStatement('INSERT INTO recipes_nestings (recipe_id, includes_recipe_id, servings) SELECT :last_insert_id, includes_recipe_id, servings FROM recipes_nestings WHERE recipe_id = :recipe_id', ['recipe_id' => $recipeId, 'last_insert_id' => $lastInsertId]);

			return $lastInsertId;
		});
	}

	private function RecipeExists($recipeId)
	{
		$recipeRow = $this->DB->recipes()->where('id = :1', $recipeId)->fetch();
		return $recipeRow !== null;
	}
}
