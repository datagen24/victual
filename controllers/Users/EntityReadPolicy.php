<?php

namespace Victual\Controllers\Users;

use Victual\Controllers\Api\EInvalidApiQuery;

/** Read policy for generic objects and their separately addressable userfields. */
class EntityReadPolicy
{
	const PERMISSIONS = [
		'products' => User::PERMISSION_STOCK_VIEW,
		'product_barcodes' => User::PERMISSION_STOCK_VIEW,
		'locations' => User::PERMISSION_STOCK_VIEW,
		'quantity_units' => User::PERMISSION_STOCK_VIEW,
		'quantity_unit_conversions' => User::PERMISSION_STOCK_VIEW,
		'shopping_locations' => User::PERMISSION_STOCK_VIEW,
		'product_groups' => User::PERMISSION_STOCK_VIEW,
		'stock_log' => User::PERMISSION_STOCK_VIEW,
		'stock' => User::PERMISSION_STOCK_VIEW,
		'stock_current_locations' => User::PERMISSION_STOCK_VIEW,
		'products_last_purchased' => User::PERMISSION_STOCK_VIEW,
		'products_average_price' => User::PERMISSION_STOCK_VIEW,
		'quantity_unit_conversions_resolved' => User::PERMISSION_STOCK_VIEW,
		'product_barcodes_view' => User::PERMISSION_STOCK_VIEW,
		'mqtt_product_entities' => User::PERMISSION_STOCK_VIEW,
		'shopping_list' => User::PERMISSION_SHOPPINGLIST_VIEW,
		'shopping_lists' => User::PERMISSION_SHOPPINGLIST_VIEW,
		'uihelper_shopping_list' => User::PERMISSION_SHOPPINGLIST_VIEW,
		'recipes' => User::PERMISSION_RECIPES_VIEW,
		'recipes_pos' => User::PERMISSION_RECIPES_VIEW,
		'recipes_nestings' => User::PERMISSION_RECIPES_VIEW,
		'recipes_pos_resolved' => User::PERMISSION_RECIPES_VIEW,
		'meal_plan' => User::PERMISSION_MEALPLAN_VIEW,
		'meal_plan_sections' => User::PERMISSION_MEALPLAN_VIEW,
		'chores' => User::PERMISSION_CHORES_VIEW,
		'chores_log' => User::PERMISSION_CHORES_VIEW,
		'tasks' => User::PERMISSION_TASKS_VIEW,
		'task_categories' => User::PERMISSION_TASKS_VIEW,
		'permission_hierarchy' => User::PERMISSION_USERS_READ,
		'roles' => User::PERMISSION_USERS_READ,
		'users' => User::PERMISSION_USERS_READ,
		'batteries' => null,
		'battery_charge_cycles' => null,
		'equipment' => null,
		'api_keys' => null,
		'userfields' => null,
		'userentities' => null,
		'userobjects' => null,
	];

	public static function Check($request, string $entity): void
	{
		if (str_starts_with($entity, 'userentity-'))
		{
			return;
		}
		if (!array_key_exists($entity, self::PERMISSIONS))
		{
			throw new EInvalidApiQuery('Entity has no read policy');
		}
		if (self::PERMISSIONS[$entity] !== null)
		{
			User::CheckPermission($request, self::PERMISSIONS[$entity]);
		}
	}
}
