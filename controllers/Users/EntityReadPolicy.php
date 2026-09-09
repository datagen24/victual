<?php

namespace Victual\Controllers\Users;

use Victual\Controllers\Api\EInvalidApiQuery;

/** Read policy for generic objects and their separately addressable userfields. */
class EntityReadPolicy
{
	const PERMISSIONS = [
		'label_workers' => User::PERMISSION_ADMIN,
		'label_drivers' => User::PERMISSION_ADMIN,
		'label_worker_capabilities' => User::PERMISSION_ADMIN,
		'label_printers' => User::PERMISSION_ADMIN,
		'label_printer_status' => User::PERMISSION_ADMIN,
		'print_jobs' => User::PERMISSION_ADMIN,
		'print_attempts' => User::PERMISSION_ADMIN,
		'print_evidence' => User::PERMISSION_ADMIN,

		'products' => User::PERMISSION_STOCK_VIEW,
		'product_barcodes' => User::PERMISSION_STOCK_VIEW,
		'locations' => User::PERMISSION_STOCK_VIEW,
		'quantity_units' => User::PERMISSION_STOCK_VIEW,
		'quantity_unit_conversions' => User::PERMISSION_STOCK_VIEW,
		'shopping_locations' => User::PERMISSION_STOCK_VIEW,
		'product_groups' => User::PERMISSION_STOCK_VIEW,
		'product_groups_missing' => User::PERMISSION_STOCK_VIEW,
		'stock_log' => User::PERMISSION_STOCK_VIEW,
		'stock' => User::PERMISSION_STOCK_VIEW,
		'stock_current_locations' => User::PERMISSION_STOCK_VIEW,
		'products_last_purchased' => User::PERMISSION_STOCK_VIEW,
		'products_average_price' => User::PERMISSION_STOCK_VIEW,
		'quantity_unit_conversions_resolved' => User::PERMISSION_STOCK_VIEW,
		'locations_resolved' => User::PERMISSION_STOCK_VIEW,
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
		// Plan 27's readable tables. ADMIN, and fail-closed by this class's own rule: an
		// entity absent from here throws rather than reading. `label_artifacts` and
		// `label_captures` are deliberately absent from ExposedEntity altogether - an
		// artifact manifest carries captured household data and names bytes, which is the
		// reason migration 0258 gives for keeping `files` out of it.
		'label_templates' => User::PERMISSION_ADMIN,
		'label_template_versions' => User::PERMISSION_ADMIN,
		'label_assets' => User::PERMISSION_ADMIN,
		'label_media_profiles' => User::PERMISSION_ADMIN,
		'label_render_requests' => User::PERMISSION_ADMIN,
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
