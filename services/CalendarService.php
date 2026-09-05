<?php

namespace Victual\Services;

use Victual\Helpers\UrlManager;
use Victual\Controllers\Users\User;

/**
 * Aggregates upcoming due dates from all enabled feature areas (stock due dates, tasks,
 * chores, batteries, meal plan) into one event list for the calendar page and the
 * iCal export.
 */
class CalendarService extends BaseService
{
	public function __construct()
	{
		parent::__construct();
		$this->UrlManager = new UrlManager(VICTUAL_BASE_URL);
	}

	private $UrlManager;

	/**
	 * Collects the calendar events of every feature area enabled by feature flags.
	 *
	 * Meal plan entries without a section time are all-day events ('date_format' =>
	 * 'date'); a section time turns them into timed events ('datetime').
	 *
	 * @return array[] Each event: {title: string, start: string, date_format: 'date'|'datetime', link: string, color: string, description?: string, allDay?: bool}
	 */
	public function GetEvents()
	{
		$usersService = UsersService::GetInstance();

		$stockEvents = [];
		if (VICTUAL_FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING && User::HasPermissions(User::PERMISSION_STOCK_VIEW))
		{
			$products = $this->DB->products();
			$titlePrefix = LocalizationService::GetInstance()->__t('Product due') . ': ';

			foreach (StockService::GetInstance()->GetCurrentStock() as $currentStockEntry)
			{
				if ($currentStockEntry->amount > 0)
				{
					$stockEvents[] = [
						'title' => $titlePrefix . FindObjectInArrayByPropertyValue($products, 'id', $currentStockEntry->product_id)->name,
						'start' => $currentStockEntry->best_before_date,
						'date_format' => 'date',
						'link' => $this->UrlManager->ConstructUrl('/stockoverview'),
						'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_products']
					];
				}
			}
		}

		$taskEvents = [];
		if (VICTUAL_FEATURE_FLAG_TASKS && User::HasPermissions(User::PERMISSION_TASKS_VIEW))
		{
			$titlePrefix = LocalizationService::GetInstance()->__t('Task due') . ': ';

			foreach (TasksService::GetInstance()->GetCurrent() as $currentTaskEntry)
			{
				$taskEvents[] = [
					'title' => $titlePrefix . $currentTaskEntry->name,
					'start' => $currentTaskEntry->due_date,
					'date_format' => 'date',
					'link' => $this->UrlManager->ConstructUrl('/tasks'),
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_tasks']
				];
			}
		}

		$choreEvents = [];
		if (VICTUAL_FEATURE_FLAG_CHORES && User::HasPermissions(User::PERMISSION_CHORES_VIEW))
		{
			$users = UsersService::GetInstance()->GetUsersAsDto();
			$chores = $this->DB->chores()->where('active = 1');
			$titlePrefix = LocalizationService::GetInstance()->__t('Chore due') . ': ';

			foreach (ChoresService::GetInstance()->GetCurrent() as $currentChoreEntry)
			{
				$chore = FindObjectInArrayByPropertyValue($chores, 'id', $currentChoreEntry->chore_id);

				$assignedToText = '';
				if (!empty($currentChoreEntry->next_execution_assigned_to_user_id))
				{
					$assignedToText = ' (' . LocalizationService::GetInstance()->__t('assigned to %s', FindObjectInArrayByPropertyValue($users, 'id', $currentChoreEntry->next_execution_assigned_to_user_id)->display_name) . ')';
				}

				$choreEvents[] = [
					'title' => $titlePrefix . $chore->name . $assignedToText,
					'start' => $currentChoreEntry->next_estimated_execution_time,
					'date_format' => 'datetime',
					'link' => $this->UrlManager->ConstructUrl('/choresoverview'),
					'allDay' => $chore->track_date_only == 1,
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_chores']
				];
			}
		}

		$batteryEvents = [];
		if (VICTUAL_FEATURE_FLAG_BATTERIES)
		{
			$batteries = $this->DB->batteries()->where('active = 1');
			$titlePrefix = LocalizationService::GetInstance()->__t('Battery charge cycle due') . ': ';

			foreach (BatteriesService::GetInstance()->GetCurrent() as $currentBatteryEntry)
			{
				$batteryEvents[] = [
					'title' => $titlePrefix . FindObjectInArrayByPropertyValue($batteries, 'id', $currentBatteryEntry->battery_id)->name,
					'start' => $currentBatteryEntry->next_estimated_charge_time,
					'date_format' => 'datetime',
					'link' => $this->UrlManager->ConstructUrl('/batteriesoverview'),
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_batteries']
				];
			}
		}

		$mealPlanRecipeEvents = [];
		$mealPlanNotesEvents = [];
		$mealPlanProductEvents = [];
		if (VICTUAL_FEATURE_FLAG_RECIPES_MEALPLAN && User::HasPermissions(User::PERMISSION_MEALPLAN_VIEW))
		{
			$mealPlanSections = $this->DB->meal_plan_sections();

			$recipes = $this->DB->recipes()->where('type', 'normal');
			$mealPlanDayRecipes = $this->DB->meal_plan()->where('type', 'recipe');
			$titlePrefix = LocalizationService::GetInstance()->__t('Meal plan recipe') . ': ';
			foreach ($mealPlanDayRecipes as $mealPlanDayRecipe)
			{
				$start = $mealPlanDayRecipe->day;
				$dateFormat = 'date';
				$section = FindObjectInArrayByPropertyValue($mealPlanSections, 'id', $mealPlanDayRecipe->section_id);
				if (!empty($section->time_info))
				{
					$start = $mealPlanDayRecipe->day . ' ' . $section->time_info . ':00';
					$dateFormat = 'datetime';
				}

				$titlePrefix2 = '';
				if (!empty($section->name))
				{
					$titlePrefix2 = $section->name . ': ';
				}

				$mealPlanRecipeEvents[] = [
					'title' => $titlePrefix . $titlePrefix2 . FindObjectInArrayByPropertyValue($recipes, 'id', $mealPlanDayRecipe->recipe_id)->name,
					'start' => $start,
					'date_format' => $dateFormat,
					'description' => $this->UrlManager->ConstructUrl('/mealplan' . '?week=' . $mealPlanDayRecipe->day),
					'link' => $this->UrlManager->ConstructUrl('/recipes' . '?recipe=' . $mealPlanDayRecipe->recipe_id . '#fullscreen'),
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_meal_plan']
				];
			}

			$mealPlanDayNotes = $this->DB->meal_plan()->where('type', 'note');
			$titlePrefix = LocalizationService::GetInstance()->__t('Meal plan note') . ': ';
			foreach ($mealPlanDayNotes as $mealPlanDayNote)
			{
				$start = $mealPlanDayNote->day;
				$dateFormat = 'date';
				$section = FindObjectInArrayByPropertyValue($mealPlanSections, 'id', $mealPlanDayNote->section_id);
				if (!empty($section->time_info))
				{
					$start = $mealPlanDayNote->day . ' ' . $section->time_info . ':00';
					$dateFormat = 'datetime';
				}

				$titlePrefix2 = '';
				if (!empty($section->name))
				{
					$titlePrefix2 = $section->name . ': ';
				}


				$mealPlanNotesEvents[] = [
					'title' => $titlePrefix . $titlePrefix2 . $mealPlanDayNote->note,
					'start' => $start,
					'date_format' => $dateFormat,
					'link' => $this->UrlManager->ConstructUrl('/mealplan' . '?start=' . $start),
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_meal_plan']
				];
			}

			$products = $this->DB->products();
			$mealPlanDayProducts = $this->DB->meal_plan()->where('type', 'product');
			$titlePrefix = LocalizationService::GetInstance()->__t('Meal plan product') . ': ';
			foreach ($mealPlanDayProducts as $mealPlanDayProduct)
			{
				$start = $mealPlanDayProduct->day;
				$dateFormat = 'date';
				$section = FindObjectInArrayByPropertyValue($mealPlanSections, 'id', $mealPlanDayProduct->section_id);
				if (!empty($section->time_info))
				{
					$start = $mealPlanDayProduct->day . ' ' . $section->time_info . ':00';
					$dateFormat = 'datetime';
				}

				$titlePrefix2 = '';
				if (!empty($section->name))
				{
					$titlePrefix2 = $section->name . ': ';
				}

				$mealPlanProductEvents[] = [
					'title' => $titlePrefix . $titlePrefix2 . FindObjectInArrayByPropertyValue($products, 'id', $mealPlanDayProduct->product_id)->name,
					'start' => $start,
					'date_format' => $dateFormat,
					'link' => $this->UrlManager->ConstructUrl('/mealplan' . '?start=' . $start),
					'color' => $usersService->GetUserSettings(VICTUAL_USER_ID)['calendar_color_meal_plan']
				];
			}
		}

		return array_merge($stockEvents, $taskEvents, $choreEvents, $batteryEvents, $mealPlanRecipeEvents, $mealPlanNotesEvents, $mealPlanProductEvents);
	}
}
