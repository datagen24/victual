<?php

namespace Victual\Controllers;

use Victual\Helpers\Grocycode;
use Victual\Services\RecipesService;
use Victual\Services\StockService;
use Victual\Services\UserfieldsService;
use Victual\Controllers\Users\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the recipe and meal plan views (recipe overview,
 * recipe/ingredient edit forms, meal plan calendar and meal plan sections).
 * Recipe fulfillment/resolution logic is delegated to RecipesService.
 */
class RecipesController extends BaseController
{
	use GrocycodeTrait;

	/**
	 * Serves the meal plan calendar view (route GET /mealplan); builds fullcalendar
	 * event objects from the meal plan entries around the requested week.
	 *
	 * Optional query parameters: start (ISO date the displayed week is based on;
	 * default today) and days (int, days before/after start to load; default 6).
	 */
	public function MealPlan(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MEALPLAN_VIEW);
		$start = date('Y-m-d');
		if (isset($request->getQueryParams()['start']) && IsIsoDate($request->getQueryParams()['start']))
		{
			$start = $request->getQueryParams()['start'];
		}

		$days = 6;
		if (isset($request->getQueryParams()['days']) && filter_var($request->getQueryParams()['days'], FILTER_VALIDATE_INT) !== false)
		{
			$days = intval($request->getQueryParams()['days']);
		}

		// The window boundaries are computed here and bound as parameters instead of being
		// expressed in SQL: SQLite's DATE(x, '-N days') has no PostgreSQL equivalent, so
		// date arithmetic must not leak into the query (see DatabaseDialect)
		$startDate = new \DateTimeImmutable($start);
		$mealPlanWhereTimespan = 'day BETWEEN ? AND ?';
		$mealPlanWhereTimespanParams = [
			$startDate->modify(sprintf('%+d days', -$days))->format('Y-m-d'),
			$startDate->modify(sprintf('%+d days', $days))->format('Y-m-d')
		];

		$recipes = $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE')->fetchAll();
		$events = [];
		foreach ($this->DB->meal_plan()->where($mealPlanWhereTimespan, $mealPlanWhereTimespanParams) as $mealPlanEntry)
		{
			$recipe = FindObjectInArrayByPropertyValue($recipes, 'id', $mealPlanEntry['recipe_id']);
			$title = '';

			if ($recipe !== null)
			{
				$title = $recipe->name;
			}

			$productDetails = null;
			if ($mealPlanEntry['product_id'] !== null)
			{
				$productDetails = StockService::GetInstance()->GetProductDetails($mealPlanEntry['product_id']);
			}

			$events[] = [
				'id' => $mealPlanEntry['id'],
				'title' => $title,
				'start' => $mealPlanEntry['day'],
				'date_format' => 'date',
				'recipe' => json_encode($recipe),
				'mealPlanEntry' => json_encode($mealPlanEntry),
				'type' => $mealPlanEntry['type'],
				'productDetails' => json_encode($productDetails)
			];
		}

		// The week recipe name is built in PHP for the same reason (STRFTIME is SQLite only);
		// see RecipesService::GetMealPlanWeekRecipeName() for why it is not date('Y-W')
		$weekRecipe = $this->DB->recipes()->where('type = ? AND name = ?', [RecipesService::RECIPE_TYPE_MEALPLAN_WEEK, RecipesService::GetMealPlanWeekRecipeName($start)])->fetch();
		$weekRecipeId = 0;
		if ($weekRecipe != null)
		{
			$weekRecipeId = $weekRecipe->id;
		}

		// LessQL carries a Result's ORDER BY into aggregate queries. PostgreSQL rejects
		// SELECT COUNT(*) ... ORDER BY sort_number, while SQLite silently accepts it, so
		// count the unordered result before adding the display order (issue #45).
		$usedMealplanSections = $this->DB->meal_plan_sections()
			->where("id IN (SELECT section_id FROM meal_plan WHERE $mealPlanWhereTimespan)", $mealPlanWhereTimespanParams);

		return $this->RenderPage($response, 'mealplan', [
			'fullcalendarEventSources' => $events,
			'recipes' => $recipes,
			'internalRecipes' => $this->DB->recipes()->where("id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR id = ?", array_merge($mealPlanWhereTimespanParams, [$weekRecipeId]))->fetchAll(),
			'recipesResolved' => RecipesService::GetInstance()->GetRecipesResolved("recipe_id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR recipe_id = ?", array_merge($mealPlanWhereTimespanParams, [$weekRecipeId])),
			'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'mealplanSections' => $this->DB->meal_plan_sections()->orderBy('sort_number'),
			'usedMealplanSections' => $usedMealplanSections->orderBy('sort_number'),
			'usedMealplanSectionsCount' => $usedMealplanSections->count(),
			'weekRecipe' => $weekRecipe
		]);
	}

	/**
	 * Serves the recipes overview view (route GET /recipes) with the selected
	 * recipe's resolved positions, sub recipes, total costs and calories.
	 *
	 * Query parameter recipe (id) selects a recipe; otherwise the first one
	 * (by name) is preselected.
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		$recipes = $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE');
		$recipesResolved = RecipesService::GetInstance()->GetRecipesResolved('recipe_id > 0');

		$selectedRecipe = null;
		if (isset($request->getQueryParams()['recipe']))
		{
			$selectedRecipe = $this->DB->recipes($request->getQueryParams()['recipe']);
		}
		else
		{
			foreach ($recipes as $recipe)
			{
				$selectedRecipe = $recipe;
				break;
			}
		}

		// $selectedRecipe stays null on a fresh install (no recipes at all) and when the
		// "recipe" query parameter names a non existing id, so everything derived from it
		// has to be resolved inside this guard - an empty result for the view, not a
		// dereference of null
		$totalCosts = null;
		$totalCalories = null;
		$recipePositionsResolved = [];
		if ($selectedRecipe)
		{
			$selectedRecipeResolved = FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $selectedRecipe->id);
			if ($selectedRecipeResolved)
			{
				$totalCosts = $selectedRecipeResolved->costs;
				$totalCalories = $selectedRecipeResolved->calories;
			}

			$recipePositionsResolved = $this->DB->recipes_pos_resolved()->where('recipe_id', $selectedRecipe->id);
		}

		$viewData = [
			'recipes' => $recipes,
			'recipesResolved' => $recipesResolved,
			'recipePositionsResolved' => $recipePositionsResolved,
			'selectedRecipe' => $selectedRecipe,
			'products' => $this->DB->products(),
			'quantityUnits' => $this->DB->quantity_units(),
			'userfields' => UserfieldsService::GetInstance()->GetFields('recipes'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('recipes'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved(),
			'selectedRecipeTotalCosts' => $totalCosts,
			'selectedRecipeTotalCalories' => $totalCalories,
			'mealplanSections' => $this->DB->meal_plan_sections()->orderBy('sort_number')
		];

		if ($selectedRecipe)
		{
			$selectedRecipeSubRecipes = $this->DB->recipes()->where('id IN (SELECT includes_recipe_id FROM recipes_nestings_resolved WHERE recipe_id = :1 AND includes_recipe_id != :1)', $selectedRecipe->id)->orderBy('name', 'COLLATE NOCASE')->fetchAll();

			$includedRecipeIdsAbsolute = [];
			$includedRecipeIdsAbsolute[] = $selectedRecipe->id;
			foreach ($selectedRecipeSubRecipes as $subRecipe)
			{
				$includedRecipeIdsAbsolute[] = $subRecipe->id;
			}

			// TODO: Why not directly use recipes_pos_resolved for all recipe positions here (parent and child)?
			// This view already correctly recolves child recipe amounts...
			$allRecipePositions = [];
			foreach ($includedRecipeIdsAbsolute as $id)
			{
				$allRecipePositions[$id] = $this->DB->recipes_pos_resolved()->where('recipe_id = :1 AND is_nested_recipe_pos = 0', $id)->orderBy('ingredient_group', 'ASC', 'product_group', 'ASC');
				foreach ($allRecipePositions[$id] as $pos)
				{
					if ($id != $selectedRecipe->id)
					{
						$pos2 = $this->DB->recipes_pos_resolved()->where('recipe_id = :1  AND recipe_pos_id = :2 AND is_nested_recipe_pos = 1', $selectedRecipe->id, $pos->recipe_pos_id)->fetch();
						$pos->recipe_amount = $pos2->recipe_amount;
						$pos->missing_amount = $pos2->missing_amount;
					}
				}
			}

			$viewData['selectedRecipeSubRecipes'] = $selectedRecipeSubRecipes;
			$viewData['includedRecipeIdsAbsolute'] = $includedRecipeIdsAbsolute;
			$viewData['allRecipePositions'] = $allRecipePositions;
		}

		return $this->RenderPage($response, 'recipes', $viewData);
	}

	/**
	 * Serves the recipe create/edit form (route GET /recipe/{recipeId}).
	 *
	 * @param array $args Route arguments; recipeId is either a recipe id or the literal 'new' for create mode
	 */
	public function RecipeEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		$recipeId = $args['recipeId'];

		return $this->RenderPage($response, 'recipeform', [
			'recipe' => $this->DB->recipes($recipeId),
			'recipePositions' => $this->DB->recipes_pos()->where('recipe_id', $recipeId),
			'mode' => $recipeId == 'new' ? 'create' : 'edit',
			'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->DB->quantity_units(),
			'recipes' => $this->DB->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'recipeNestings' => $this->DB->recipes_nestings()->where('recipe_id', $recipeId),
			'userfields' => UserfieldsService::GetInstance()->GetFields('recipes'),
			'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
		]);
	}

	/**
	 * Serves the recipe ingredient (position) create/edit form
	 * (route GET /recipe/{recipeId}/pos/{recipePosId}).
	 *
	 * @param array $args Route arguments; recipePosId is either a position id or the literal 'new' for create mode
	 */
	public function RecipePosEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		if ($args['recipePosId'] == 'new')
		{
			return $this->RenderPage($response, 'recipeposform', [
				'mode' => 'create',
				'recipe' => $this->DB->recipes($args['recipeId']),
				'recipePos' => new \stdClass(),
				'products' => $this->DB->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
			]);
		}
		else
		{
			return $this->RenderPage($response, 'recipeposform', [
				'mode' => 'edit',
				'recipe' => $this->DB->recipes($args['recipeId']),
				'recipePos' => $this->DB->recipes_pos($args['recipePosId']),
				'products' => $this->DB->products()->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->DB->product_barcodes_comma_separated(),
				'quantityUnits' => $this->DB->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->DB->cache__quantity_unit_conversions_resolved()
			]);
		}
	}

	/**
	 * Serves the recipes settings view (route GET /recipessettings).
	 */
	public function RecipesSettings(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		return $this->RenderPage($response, 'recipessettings');
	}

	/**
	 * Serves the meal plan section create/edit form (route GET /mealplansection/{sectionId}).
	 *
	 * @param array $args Route arguments; sectionId is either a section id or the literal 'new' for create mode
	 */
	public function MealPlanSectionEditForm(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MEALPLAN_VIEW);
		if ($args['sectionId'] == 'new')
		{
			return $this->RenderPage($response, 'mealplansectionform', [
				'mode' => 'create'
			]);
		}
		else
		{
			return $this->RenderPage($response, 'mealplansectionform', [
				'mealplanSection' => $this->DB->meal_plan_sections($args['sectionId']),
				'mode' => 'edit'
			]);
		}
	}

	/**
	 * Serves the meal plan sections list view (route GET /mealplansections).
	 */
	public function MealPlanSectionsList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MEALPLAN_VIEW);
		return $this->RenderPage($response, 'mealplansections', [
			'mealplanSections' => $this->DB->meal_plan_sections()->where('id > 0')->orderBy('sort_number')
		]);
	}

	/**
	 * Serves the Grocycode barcode PNG for a recipe (route GET /recipe/{recipeId}/grocycode).
	 */
	public function RecipeGrocycodeImage(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_RECIPES_VIEW);
		$gc = new Grocycode(Grocycode::RECIPE, $args['recipeId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
