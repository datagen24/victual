// View script for the meal plan (views/mealplan.blade.php): renders one FullCalendar (v3)
// week/day view per meal plan section, provides add/edit/copy/delete of meal plan entries
// (recipes, products, notes) and consume/"add missing to shopping list"/done actions.
//
// The Blade template provides these globals (inline <script> block):
// - Victual.FullcalendarEventSources: event feed for all calendars (one event per meal plan entry)
// - Victual.InternalRecipes: the hidden shadow recipes Victual keeps per meal plan entry/day/week
//   (named "<day>#<entry id>", "<day>" and "<year>-<week>" respectively)
// - Victual.RecipesResolved: recipes_resolved rows (costs, calories, stock fulfillment) for those.
//   Redacted server-side by FieldPolicy (RecipesController::MealPlan), so "costs",
//   "costs_per_serving" and "prices_incomplete" are ABSENT - not null - for a caller without
//   STOCK_PRICES_VIEW. Every price this file renders is therefore behind Victual.PricesVisible
//   rather than behind the VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING flag alone: the flag says
//   the instance tracks prices, PricesVisible says this user may see them. Issue #176 items 4
//   and 5 - reading the absent keys under the flag alone printed "undefined" and NaN.
// - Victual.WeekRecipe: the shadow recipe of the currently displayed week (or null)
// Each .calendar container carries data-section-id/-name, data-primary-section and
// data-last-section attributes.

// Tracks whether the visible modal edits an existing entry (true) or creates a new one;
// on edit, Victual.MealPlanEntryEditObject holds the entry being edited
var firstRender = true;
Victual.IsMealPlanEntryEditAction = false;

// First day of week: user/meal plan setting; a meal plan setting of -1 means "today"
var firstDay = null;
if (Victual.CalendarFirstDayOfWeek)
{
	firstDay = Number.parseInt(Victual.CalendarFirstDayOfWeek);
}
if (Victual.MealPlanFirstDayOfWeek)
{
	firstDay = Number.parseInt(Victual.MealPlanFirstDayOfWeek);

	if (firstDay == -1)
	{
		firstDay = moment().day();
	}
}

// Whether the current user holds a permission, from Victual.UserPermissions
// (views/layout/default.blade.php, User::PermissionList()) - the same signal
// recipes.blade.php's own server-rendered buttons are gated on, read here instead because
// this page's action buttons are built client-side (FullCalendar event/toolbar rendering
// below) rather than in a Blade loop. Added for issue #532: the consume and add-missing
// buttons used to render unconditionally, so e.g. the built-in CHILD role (STOCK_CONSUME
// without STOCK_PURCHASE) saw a consume button on a stock-producing entry that always 403s.
function userHasPermission(permissionName)
{
	var permissionRow = FindObjectInArrayByPropertyValue(Victual.UserPermissions, "permission_name", permissionName);
	return permissionRow !== null && permissionRow.has_permission == 1;
}

// Whether the consume button may be offered for a recipe whose resolved "produces product"
// is outputProductId - RECIPES_VIEW + STOCK_CONSUME always, plus STOCK_PURCHASE when it
// produces something, exactly the rule RecipesApiController::ConsumeRecipe() /
// RecipesService::ConsumeRecipe() enforce server-side (issue #532), plus RECIPES_MEALPLAN
// (issue #591). This click flow's *second* call, once the recipe is consumed, is PUT
// objects/meal_plan/{id} to mark the entry done - GenericEntityApiController::EditObject()
// gates that PUT on RECIPES_MEALPLAN for the meal_plan entity - so without this clause the
// button would book the consumption and then report a failure for a "done" mark that will
// always be refused (the defect issue #591 describes). RECIPES_MEALPLAN is required
// unconditionally here rather than only when marking done matters, because this whole button
// only exists to run both calls together. For a meal plan entry, the caller below passes the
// *original* recipe's product_id (event.recipe, embedded by RecipesController::MealPlan()
// from the RECIPE_TYPE_NORMAL row the entry names) rather than the per-entry shadow's own
// (always empty) one - the same output the server resolves a shadow to. A week recipe's own
// product_id is always empty (it is a pure aggregation container, never a "produces product"
// row itself - db/pgsql/baseline/06_triggers_c.sql's mealplan-week insert never sets one), so
// this reduces to RECIPES_VIEW + STOCK_CONSUME + RECIPES_MEALPLAN for the week button without
// needing to special-case it.
function mayConsumeMealPlanRecipe(outputProductId)
{
	return userHasPermission("RECIPES_VIEW") && userHasPermission("STOCK_CONSUME") && userHasPermission("RECIPES_MEALPLAN")
		&& (!outputProductId || userHasPermission("STOCK_PURCHASE"));
}

// The add-missing-to-shoppinglist button needs RECIPES_VIEW + SHOPPINGLIST_ITEMS_ADD (issue
// #532) plus RECIPES (issue #591): the click flow's first call (below, PUT objects/recipes to
// set desired_servings before resolving fulfillment) requires the RECIPES edit permission
// (GenericEntityApiController::EditObject()) - it never adds stock, so STOCK_PURCHASE is never
// relevant, but that PUT is a real write this button always performs, so RECIPES is not
// optional the way it would be if the button never needed the PUT.
function mayAddMealPlanRecipeToShoppingList()
{
	return userHasPermission("RECIPES_VIEW") && userHasPermission("SHOPPINGLIST_ITEMS_ADD") && userHasPermission("RECIPES");
}

// Whether the "consume a product entry" button (a meal-plan entry of type "product", rather
// than a recipe) may be offered - issue #591: this flow's first call is POST
// /api/stock/products/{id}/consume (STOCK_CONSUME), and its second is PUT
// objects/meal_plan/{id} to mark the entry done, which GenericEntityApiController::
// EditObject() gates on RECIPES_MEALPLAN for the meal_plan entity. Unlike the recipe consume
// button above, the product route itself never checks RECIPES_MEALPLAN, so this gate exists
// purely to keep the button from starting a flow whose second half the server will refuse.
function mayConsumeMealPlanProduct()
{
	return userHasPermission("STOCK_CONSUME") && userHasPermission("RECIPES_MEALPLAN");
}

// FullCalendar setup - one calendar instance per meal plan section; only the primary
// (first) section shows the header/navigation, all others render as bare all-day rows
// (minTime/maxTime squeeze the time grid away so only the all-day row remains)
$(".calendar").each(function()
{
	var container = $(this);
	var sectionId = container.attr("data-section-id");
	var sectionName = container.attr("data-section-name");
	var isPrimarySection = BoolVal(container.attr("data-primary-section"));
	var isLastSection = BoolVal(container.attr("data-last-section"));

	var rightButtonList = "agendaWeek,agendaDay,prev,today,next";
	if ($(window).width() < 768)
	{
		var rightButtonList = "prev,today,next";
	}

	var headerConfig = {
		"left": "title",
		"center": "",
		"right": rightButtonList
	};

	if (!isPrimarySection)
	{
		headerConfig = {
			"left": "",
			"center": "",
			"right": ""
		};
	}

	container.fullCalendar({
		"themeSystem": "bootstrap4",
		"header": headerConfig,
		"weekNumbers": false,
		"eventLimit": false,
		"eventSources": Victual.FullcalendarEventSources,
		"defaultView": ($(window).width() < 768 || GetUriParam("days") == "0") ? "agendaDay" : "agendaWeek",
		"allDayText": sectionName,
		"allDayHtml": sectionName,
		"minTime": "00:00:00",
		"maxTime": "00:00:01",
		"scrollTime": "00:00:00",
		"firstDay": firstDay,
		"height": "auto",
		"defaultDate": GetUriParam("start"),
		// Injects the per-day "add entry" button/menu into the primary calendar's day headers
		// and builds the week summary (week costs plus order-missing/consume buttons for the
		// week's shadow recipe) in the toolbar center
		"viewRender": function(view)
		{
			if (!isPrimarySection)
			{
				return;
			}

			$(".calendar[data-primary-section='true'] .fc-day-header").prepend('\
			<div class="btn-group mr-2 my-1 d-print-none"> \
				<button type="button" class="btn btn-outline-dark btn-xs add-recipe-button" data-toggle="tooltip" title="' + __t('Add recipe') + '"><i class="fa-solid fa-plus"></i></a></button> \
				<button type="button" class="btn btn-outline-dark btn-xs dropdown-toggle dropdown-toggle-split" data-toggle="dropdown"></button> \
				<div class="table-inline-menu dropdown-menu"> \
					<a class="dropdown-item add-note-button" href="#"><span class="dropdown-item-text">' + __t('Add note') + '</span></a> \
					<a class="dropdown-item add-product-button" href="#"><span class="dropdown-item-text">' + __t('Add product') + '</span></a> \
					<a class="dropdown-item copy-day-button" href="#"><span class="dropdown-item-text">' + __t('Copy this day') + '</span></a> \
				</div> \
			</div>');

			var weekCosts = 0;
			var weekRecipeOrderMissingButtonHtml = "";
			var weekRecipeConsumeButtonHtml = "";
			var weekCostsHtml = "";
			if (Victual.WeekRecipe !== null)
			{
				var weekRecipeResolved = FindObjectInArrayByPropertyValue(Victual.RecipesResolved, "recipe_id", Victual.WeekRecipe.id);

				if (Victual.PricesVisible)
				{
					weekCosts = weekRecipeResolved.costs;
					weekCostsHtml = __t("Week costs") + ': <span class="locale-number locale-number-currency">' + weekCosts.toString() + "</span> ";
				}

				var weekRecipeOrderMissingButtonDisabledClasses = "";
				if (weekRecipeResolved.need_fulfilled_with_shopping_list == 1)
				{
					weekRecipeOrderMissingButtonDisabledClasses = "disabled";
				}

				var weekRecipeOrderMissingButtonHtml = "";
				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_SHOPPINGLIST && mayAddMealPlanRecipeToShoppingList())
				{
					weekRecipeOrderMissingButtonHtml = '<a class="ml-2 btn btn-outline-primary btn-xs recipe-order-missing-button d-print-none ' + weekRecipeOrderMissingButtonDisabledClasses + '" href="#" data-toggle="tooltip" title="' + __t("Put missing products on shopping list") + '" data-recipe-id="' + Victual.WeekRecipe.id.toString() + '" data-recipe-name="' + Victual.WeekRecipe.name + '" data-recipe-type="' + Victual.WeekRecipe.type + '"><i class="fa-solid fa-cart-plus"></i></a>';
				}

				if (mayConsumeMealPlanRecipe(Victual.WeekRecipe.product_id))
				{
					weekRecipeConsumeButtonHtml = '<a class="ml-2 btn btn-outline-success btn-xs recipe-consume-button d-print-none" href="#" data-toggle="tooltip" title="' + __t("Consume all ingredients needed by this weeks recipes or products") + '" data-recipe-id="' + Victual.WeekRecipe.id.toString() + '" data-recipe-name="' + Victual.WeekRecipe.name + '" data-recipe-type="' + Victual.WeekRecipe.type + '"><i class="fa-solid fa-utensils"></i></a>'
				}
			}
			$(".calendar[data-primary-section='true'] .fc-header-toolbar .fc-center").html("<h4>" + weekCostsHtml + weekRecipeOrderMissingButtonHtml + weekRecipeConsumeButtonHtml + "</h4>");
		},
		// Renders a single meal plan entry card; each event carries the raw meal_plan row
		// (event.mealPlanEntry, JSON) plus type specific payload (event.recipe /
		// event.productDetails). Returning false skips events belonging to other sections,
		// so every calendar only shows its own section's entries.
		"eventRender": function(event, element)
		{
			element.removeClass("fc-event");
			element.addClass("text-center");
			element.attr("data-meal-plan-entry", event.mealPlanEntry);
			element.addClass("discrete-link");

			var mealPlanEntry = JSON.parse(event.mealPlanEntry);

			if (sectionId != mealPlanEntry.section_id)
			{
				return false;
			}

			var additionalTitleCssClasses = "";
			var doneButtonHtml = '<a class="ml-2 btn btn-outline-secondary btn-xs mealplan-entry-done-button" href="#" data-toggle="tooltip" title="' + __t("Mark this item as done") + '" data-mealplan-entry-id="' + mealPlanEntry.id.toString() + '"><i class="fa-solid fa-check"></i></a>';
			if (BoolVal(mealPlanEntry.done))
			{
				additionalTitleCssClasses = "text-strike-through text-muted";
				doneButtonHtml = '<a class="ml-2 btn btn-outline-secondary btn-xs mealplan-entry-undone-button" href="#" data-toggle="tooltip" title="' + __t("Mark this item as undone") + '" data-mealplan-entry-id="' + mealPlanEntry.id.toString() + '"><i class="fa-solid fa-undo"></i></a>';
			}

			// Recipe entry: card with picture, name, servings, stock fulfillment (from the
			// entry's resolved shadow recipe), costs/calories and action buttons
			if (event.type == "recipe")
			{
				var recipe = JSON.parse(event.recipe);
				if (recipe === null || recipe === undefined)
				{
					return false;
				}

				recipe.name = recipe.name.escapeHTML();

				var internalShadowRecipe = FindObjectInArrayByPropertyValue(Victual.InternalRecipes, "name", mealPlanEntry.day + "#" + mealPlanEntry.id);
				var resolvedRecipe = FindObjectInArrayByPropertyValue(Victual.RecipesResolved, "recipe_id", internalShadowRecipe.id);

				element.attr("data-recipe", event.recipe);

				var recipeOrderMissingButtonDisabledClasses = "";
				if (resolvedRecipe.need_fulfilled_with_shopping_list == 1)
				{
					recipeOrderMissingButtonDisabledClasses = "disabled";
				}

				var fulfillmentInfoHtml = __t('Enough in stock');
				var fulfillmentIconHtml = '<i class="fa-solid fa-check text-success"></i>';
				if (resolvedRecipe.need_fulfilled != 1)
				{
					fulfillmentInfoHtml = __t('Not enough in stock');
					var fulfillmentIconHtml = '<i class="fa-solid fa-times text-danger"></i>';
				}
				var costsAndCaloriesPerServing = ""
				if (Victual.PricesVisible)
				{
					costsAndCaloriesPerServing = '<h5 class="small text-truncate mb-1"><span class="locale-number locale-number-currency">' + resolvedRecipe.costs + '</span> / <span class="locale-number locale-number-generic">' + resolvedRecipe.calories / mealPlanEntry.recipe_servings + '</span> ' + Victual.EnergyUnit + ' ' + __t('per serving') + '</h5>';
				}
				else
				{
					costsAndCaloriesPerServing = '<h5 class="small text-truncate mb-1"><span class="locale-number locale-number-generic">' + resolvedRecipe.calories / mealPlanEntry.recipe_servings + '</span> ' + Victual.EnergyUnit + ' ' + __t('per serving') + '</h5>';
				}

				if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK)
				{
					fulfillmentIconHtml = "";
					fulfillmentInfoHtml = "";
				}

				var shoppingListButtonHtml = "";
				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_SHOPPINGLIST && mayAddMealPlanRecipeToShoppingList())
				{
					shoppingListButtonHtml = '<a class="btn btn-outline-primary btn-xs recipe-order-missing-button ' + recipeOrderMissingButtonDisabledClasses + '" href="#" data-toggle="tooltip" title="' + __t("Put missing products on shopping list") + '" data-recipe-id="' + recipe.id.toString() + '" data-mealplan-servings="' + mealPlanEntry.recipe_servings + '" data-recipe-name="' + recipe.name + '" data-recipe-type="' + recipe.type + '"><i class="fa-solid fa-cart-plus"></i></a>';
				}

				var recipeConsumeButtonHtml = "";
				if (mayConsumeMealPlanRecipe(recipe.product_id))
				{
					recipeConsumeButtonHtml = '<a class="ml-2 btn btn-outline-success btn-xs recipe-consume-button" href="#" data-toggle="tooltip" title="' + __t("Consume all ingredients needed by this recipe") + '" data-recipe-id="' + internalShadowRecipe.id.toString() + '" data-mealplan-entry-id="' + mealPlanEntry.id.toString() + '" data-recipe-name="' + recipe.name + '" data-recipe-type="' + recipe.type + '"><i class="fa-solid fa-utensils"></i></a>';
				}

				element.html('\
				<div> \
					<h5 class="text-truncate mb-1 cursor-link display-recipe-button ' + additionalTitleCssClasses + '" data-toggle="tooltip" title="' + __t("Display recipe") + '" data-recipe-id="' + recipe.id.toString() + '" data-recipe-name="' + recipe.name + '" data-mealplan-servings="' + mealPlanEntry.recipe_servings + '" data-recipe-type="' + recipe.type + '">' + recipe.name + '</h5> \
					<h5 class="small text-truncate mb-1">' + __n(mealPlanEntry.recipe_servings, "%s serving", "%s servings") + '</h5> \
					<h5 class="small timeago-contextual text-truncate mb-1">' + fulfillmentIconHtml + " " + fulfillmentInfoHtml + '</h5> \
					' + costsAndCaloriesPerServing + ' \
					<h5 class="d-print-none"> \
						<a class="ml-2 btn btn-outline-info btn-xs edit-meal-plan-entry-button" href="#" data-toggle="tooltip" title="' + __t("Edit this item") + '"><i class="fa-solid fa-edit"></i></a> \
						<a class="btn btn-outline-danger btn-xs remove-recipe-button" href="#" data-toggle="tooltip" title="' + __t("Delete this item") + '"><i class="fa-solid fa-trash"></i></a> \
						' + recipeConsumeButtonHtml + ' \
						' + shoppingListButtonHtml + ' \
						' + doneButtonHtml + ' \
					</h5> \
				</div>');

				if (recipe.picture_file_name)
				{
					element.prepend('<div class="mx-auto mb-1"><img src="' + U("/api/files/recipepictures/") + btoa(recipe.picture_file_name) + '?force_serve_as=picture&best_fit_width=400" class="img-fluid rounded-circle" loading="lazy"></div>')
				}
			}
			// Product entry: card with picture, name, amount, stock fulfillment (based on the
			// aggregated stock amount), costs/calories and action buttons
			else if (event.type == "product")
			{
				var productDetails = JSON.parse(event.productDetails);
				if (productDetails === null || productDetails === undefined)
				{
					return false;
				}

				element.attr("data-product-details", event.productDetails);

				// The product this entry names was deleted, or deactivated after the entry
				// was created - StockService::GetProductDetails() throws for either
				// (StockService::ProductExists()), so the server sends this reduced marker
				// instead of calling it at all (issue #595). The entry still has to stay
				// visible and deletable - unlike a missing recipe just above, which returns
				// false and hides the event entirely, hiding this one would leave the user
				// with no way to see or remove it. Editing is not offered: the edit dialog's
				// ProductPicker change handler re-fetches GET stock/products/{id}
				// (Victual.Components.ProductPicker.GetPicker().on('change', ...) below),
				// which throws on exactly the same "missing or inactive" condition and is
				// not something this fix reaches (that endpoint is out of scope here).
				if (productDetails.missing || productDetails.inactive)
				{
					var productLabel = productDetails.missing ? __t("Product not found") : productDetails.product.name.escapeHTML();
					var productNote = productDetails.missing ? __t("This product no longer exists") : __t("This product is deactivated");

					element.html('\
					<div> \
						<h5 class="text-truncate mb-1 text-muted ' + additionalTitleCssClasses + '">' + productLabel + '</h5> \
						<h5 class="small text-truncate mb-1 text-muted"><i class="fa-solid fa-triangle-exclamation"></i> ' + productNote + '</h5> \
						<h5 class="d-print-none"> \
							<a class="btn btn-outline-danger btn-xs remove-product-button" href="#" data-toggle="tooltip" title="' + __t("Delete this item") + '"><i class="fa-solid fa-trash"></i></a> \
							' + doneButtonHtml + ' \
						</h5> \
					</div>');

					return;
				}

				// Same reason as recipe.name above: this is concatenated into markup below,
				// and products.name is a text column, so it can contain markup as typed
				productDetails.product.name = productDetails.product.name.escapeHTML();

				// Two different absences, both of which multiply to NaN below: null is
				// "nothing was ever paid for this product", undefined is "you may not see
				// what was" (FieldPolicy removes the key rather than nulling it, so that the
				// first case stays distinguishable from the second on the wire). The
				// rendering is behind Victual.PricesVisible either way; this keeps the
				// arithmetic defined. Issue #176 item 5.
				if (productDetails.last_price === null || productDetails.last_price === undefined)
				{
					productDetails.last_price = 0;
				}

				// product.qu_id_stock carries no FK (db/pgsql/baseline/01_tables.sql), so the
				// quantity unit a product names can be deleted out from under it - both
				// StockService::GetProductDetails() and the reduced non-STOCK_VIEW lookup in
				// RecipesController::MealPlan() then return quantity_unit_stock as null
				// rather than throwing. Falling back first to Victual.QuantityUnits (every
				// QU; harmless to expose, and already page-global for the product-add form)
				// keeps the amount readable instead of a TypeError on .name; an empty unit is
				// the last resort, for the id itself no longer resolving either.
				var quantityUnitStock = productDetails.quantity_unit_stock;
				if (quantityUnitStock === null || quantityUnitStock === undefined)
				{
					quantityUnitStock = FindObjectInArrayByPropertyValue(Victual.QuantityUnits, "id", productDetails.product.qu_id_stock) || { name: "", name_plural: "" };
				}

				// A caller without STOCK_VIEW never receives stock_amount_aggregated (or
				// any other stock-state field) at all - the server omits the key rather
				// than sending a zero, the same distinguishable-absence contract
				// last_price above relies on (issue #594). Whether there is "enough in
				// stock" is then simply unknown, not "not enough" - the consume and
				// add-to-shoppinglist buttons both stay disabled (neither action is safe
				// or even submittable without knowing, see below) and the fulfillment
				// line is left blank instead of showing a guessed answer.
				var stockStateKnown = typeof productDetails.stock_amount_aggregated !== "undefined" && productDetails.stock_amount_aggregated !== null;

				var productOrderMissingButtonDisabledClasses = "disabled";
				var shoppingListButtonTitle = __t("Add to shopping list");
				if (stockStateKnown)
				{
					if (productDetails.stock_amount_aggregated < mealPlanEntry.product_amount)
					{
						productOrderMissingButtonDisabledClasses = "";
					}
				}
				else
				{
					// Stock state is unknown (no STOCK_VIEW) - the button stays disabled
					// rather than being enabled on shopping-list permission alone (round 3
					// review of PR #599): the dialog it opens
					// (shoppinglistitemform.js:221) fills the quantity unit only from a
					// GET stock/products/{id} call, which itself needs STOCK_VIEW
					// (StockApiController::ProductDetails) and has no error handler, so an
					// enabled button would open a form this caller could never submit
					// (the quantity unit field is required and stays empty,
					// productamountpicker.blade.php:42-49). Fixing that form for a caller
					// without STOCK_VIEW is out of scope here - the tooltip explains why
					// the button does nothing instead.
					shoppingListButtonTitle = __t("Stock access is required to add this to the shopping list");
				}

				var productConsumeButtonDisabledClasses = "disabled";
				if (stockStateKnown && productDetails.stock_amount_aggregated >= mealPlanEntry.product_amount)
				{
					productConsumeButtonDisabledClasses = "";
				}

				// Issue #591: this button's click flow consumes stock and then marks the
				// meal plan entry done, so it is offered only to a caller who holds every
				// permission that whole flow needs - see mayConsumeMealPlanProduct()'s own
				// comment. Built as an empty string rather than a "disabled" class (unlike
				// the insufficient-stock case above) because the reason is a permission the
				// user does not have at all, not a transient stock state a click could
				// change - the same treatment mayConsumeMealPlanRecipe() and
				// mayAddMealPlanRecipeToShoppingList() already get below.
				var productConsumeButtonHtml = "";
				if (mayConsumeMealPlanProduct())
				{
					productConsumeButtonHtml = '<a class="ml-2 btn btn-outline-success btn-xs product-consume-button ' + productConsumeButtonDisabledClasses + '" href="#" data-toggle="tooltip" title="' + __t("Consume %1$s of %2$s", mealPlanEntry.product_amount.toLocaleString() + ' ' + __n(mealPlanEntry.product_amount, quantityUnitStock.name, quantityUnitStock.name_plural, true), productDetails.product.name) + '" data-product-id="' + productDetails.product.id.toString() + '" data-product-name="' + productDetails.product.name + '" data-product-amount="' + mealPlanEntry.product_amount + '" data-mealplan-entry-id="' + mealPlanEntry.id.toString() + '"><i class="fa-solid fa-utensils"></i></a>';
				}

				var fulfillmentInfoHtml = "";
				var fulfillmentIconHtml = "";
				if (stockStateKnown)
				{
					fulfillmentInfoHtml = __t('Not enough in stock');
					fulfillmentIconHtml = '<i class="fa-solid fa-times text-danger"></i>';
					if (productDetails.stock_amount_aggregated >= mealPlanEntry.product_amount)
					{
						fulfillmentInfoHtml = __t('Enough in stock');
						fulfillmentIconHtml = '<i class="fa-solid fa-check text-success"></i>';
					}
				}

				var costsAndCaloriesPerServing = ""
				if (Victual.PricesVisible)
				{
					costsAndCaloriesPerServing = '<h5 class="small text-truncate mb-1"><span class="locale-number locale-number-currency">' + productDetails.last_price * mealPlanEntry.product_amount + '</span> / <span class="locale-number locale-number-generic">' + productDetails.product.calories + '</span> ' + Victual.EnergyUnit + ' </h5>';
				}
				else
				{
					costsAndCaloriesPerServing = '<h5 class="small text-truncate mb-1"><span class="locale-number locale-number-generic">' + productDetails.product.calories + '</span> ' + Victual.EnergyUnit + ' </h5>';
				}

				var shoppingListButtonHtml = "";
				if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_SHOPPINGLIST)
				{
					shoppingListButtonHtml = '<a class="btn btn-outline-primary btn-xs show-as-dialog-link ' + productOrderMissingButtonDisabledClasses + '" href="' + U("/shoppinglistitem/new?embedded&updateexistingproduct&list=1&product=") + mealPlanEntry.product_id + '&amount=' + mealPlanEntry.product_amount + '" data-toggle="tooltip" title="' + shoppingListButtonTitle + '" data-product-id="' + productDetails.product.id.toString() + '" data-product-name="' + productDetails.product.name + '" data-product-amount="' + mealPlanEntry.product_amount + '"><i class="fa-solid fa-cart-plus"></i></a>';
				}

				element.html('\
				<div> \
					<h5 class="text-truncate mb-1 cursor-link productcard-trigger ' + additionalTitleCssClasses + '" data-toggle="tooltip" title="' + __t("Display product") + '" data-product-id="' + productDetails.product.id.toString() + '">' + productDetails.product.name + '</h5> \
					<h5 class="small text-truncate mb-1"><span class="locale-number locale-number-quantity-amount">' + mealPlanEntry.product_amount + "</span> " + __n(mealPlanEntry.product_amount, quantityUnitStock.name, quantityUnitStock.name_plural, true) + '</h5> \
					<h5 class="small timeago-contextual text-truncate mb-1">' + fulfillmentIconHtml + " " + fulfillmentInfoHtml + '</h5> \
					' + costsAndCaloriesPerServing + ' \
					<h5 class="d-print-none"> \
						<a class="btn btn-outline-info btn-xs edit-meal-plan-entry-button" href="#" data-toggle="tooltip" title="' + __t("Edit this item") + '"><i class="fa-solid fa-edit"></i></a> \
						<a class="btn btn-outline-danger btn-xs remove-product-button" href="#" data-toggle="tooltip" title="' + __t("Delete this item") + '"><i class="fa-solid fa-trash"></i></a> \
						' + productConsumeButtonHtml + ' \
						' + shoppingListButtonHtml + ' \
						' + doneButtonHtml + ' \
					</h5> \
				</div>');

				if (productDetails.product.picture_file_name)
				{
					element.prepend('<div class="mx-auto mb-1"><img src="' + U("/api/files/productpictures/") + btoa(productDetails.product.picture_file_name) + '?force_serve_as=picture&best_fit_width=400" class="img-fluid rounded-circle" loading="lazy"></div>')
				}
			}
			// Note entry: plain text with edit/delete/done buttons
			else if (event.type == "note")
			{
				element.html('\
				<div> \
					<h5 class="text-wrap text-break mb-1 ' + additionalTitleCssClasses + '">' + mealPlanEntry.note.escapeHTML() + '</h5> \
					<h5 class="d-print-none"> \
						<a class="btn btn-outline-info btn-xs edit-meal-plan-entry-button" href="#" data-toggle="tooltip" title="' + __t("Edit this item") + '"><i class="fa-solid fa-edit"></i></a> \
						<a class="btn btn-outline-danger btn-xs remove-note-button" href="#" data-toggle="tooltip" title="' + __t("Delete this item") + '"><i class="fa-solid fa-trash"></i></a> \
						' + doneButtonHtml + ' \
					</h5> \
				</div>');
			}

			// Append the per-day costs/calories summary (from the day's shadow recipe)
			// to the day header of the primary calendar
			var dayRecipeName = event.start.format("YYYY-MM-DD");
			if (!$("#day-summary-" + dayRecipeName).length) // This runs for every event/recipe, so maybe multiple times per day, so only add the day summary once
			{
				var dayRecipe = FindObjectInArrayByPropertyValue(Victual.InternalRecipes, "name", dayRecipeName);
				if (dayRecipe != null)
				{
					var dayRecipeResolved = FindObjectInArrayByPropertyValue(Victual.RecipesResolved, "recipe_id", dayRecipe.id);

					var costsAndCaloriesPerDay = ""
					if (Victual.PricesVisible)
					{
						costsAndCaloriesPerDay = '<h5 class="small text-truncate"><span class="locale-number locale-number-currency">' + dayRecipeResolved.costs + '</span> / <span class="locale-number locale-number-generic">' + dayRecipeResolved.calories + '</span> ' + Victual.EnergyUnit + ' ' + __t('per day') + '</h5>';
					}
					else
					{
						costsAndCaloriesPerDay = '<h5 class="small text-truncate"><span class="locale-number locale-number-generic">' + dayRecipeResolved.calories + '</span> ' + Victual.EnergyUnit + ' ' + __t('per day') + '</h5>';
					}

					$(".calendar[data-primary-section='true'] .fc-day-header[data-date='" + dayRecipeName + "']").append('<h5 id="day-summary-' + dayRecipeName + '" class="small text-truncate border-top pt-1 pb-0">' + costsAndCaloriesPerDay + '</h5>');
				}
			}
		},
		// After all events rendered: sync the displayed range into the URI (?start / ?days)
		// and reload the page on any navigation (server side data is range dependent);
		// once the last section is done, apply final UI polish (locale numbers, tooltips,
		// hiding stock related buttons when the stock feature is disabled)
		"eventAfterAllRender": function(view)
		{
			if (isPrimarySection)
			{
				UpdateUriParam("start", view.start.format("YYYY-MM-DD"));

				if (view.name == "agendaDay")
				{
					UpdateUriParam("days", "0");
				}
				else
				{
					RemoveUriParam("days");
				}

				if (firstRender)
				{
					firstRender = false
				}
				else
				{
					$(".calendar").addClass("d-none");
					window.location.reload();
					return false;
				}
			}

			if (isLastSection)
			{
				$(".fc-axis span").replaceWith(function()
				{
					return $("<div />", { html: $(this).html() });
				});

				RefreshLocaleNumberDisplay();
				$('[data-toggle="tooltip"]').tooltip();

				if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK)
				{
					$(".recipe-order-missing-button").addClass("d-none");
					$(".recipe-consume-button").addClass("d-none");
				}
			}
		}
	});
});

// "Add recipe" (day header button): open the add-recipe modal preset to that day
// (the shared #day input and datetimepicker are moved into the corresponding form first,
// since the modals share these elements)
$(document).on("click", ".add-recipe-button", function(e)
{
	var day = $(this).parent().parent().data("date");

	$("#add-recipe-modal-title").text(__t("Add meal plan entry"));
	$(".datetimepicker-wrapper").detach().prependTo("#add-recipe-form");
	$("input#day").detach().appendTo("#add-recipe-form");
	Victual.Components.DateTimePicker.Init(true);
	Victual.Components.DateTimePicker.SetValue(day);
	Victual.Components.RecipePicker.Clear();
	$("#section_id_note").val(-1);
	$("#add-recipe-modal").modal("show");
	Victual.FrontendHelpers.ValidateForm("add-recipe-form");
	Victual.IsMealPlanEntryEditAction = false;
});

// "Add note" (day header menu): open the add-note modal preset to that day
$(document).on("click", ".add-note-button", function(e)
{
	var day = $(this).parent().parent().parent().data("date");

	$("#add-note-modal-title").text(__t("Add meal plan entry"));
	$(".datetimepicker-wrapper").detach().prependTo("#add-note-form");
	$("input#day").detach().appendTo("#add-note-form")
	Victual.Components.DateTimePicker.Init(true);
	Victual.Components.DateTimePicker.SetValue(day);
	$("#note").val("");
	$("#section_id_note").val(-1);
	$("#add-note-modal").modal("show");
	Victual.FrontendHelpers.ValidateForm("add-note-form");
	Victual.IsMealPlanEntryEditAction = false;
});

// "Add product" (day header menu): open the add-product modal preset to that day
$(document).on("click", ".add-product-button", function(e)
{
	var day = $(this).parent().parent().parent().data("date");

	$("#add-product-modal-title").text(__t("Add meal plan entry"));
	$(".datetimepicker-wrapper").detach().prependTo("#add-product-form");
	$("input#day").detach().appendTo("#add-product-form")
	Victual.Components.DateTimePicker.Init(true);
	Victual.Components.DateTimePicker.SetValue(day);
	Victual.Components.ProductPicker.Clear();
	$("#section_id_note").val(-1);
	$("#add-product-modal").modal("show");
	Victual.FrontendHelpers.ValidateForm("add-product-form");
	Victual.IsMealPlanEntryEditAction = false;
});

// Edit an entry: reuse the matching add-modal, prefilled from the entry JSON stored
// on the calendar element (data-meal-plan-entry); switches to edit mode via
// Victual.IsMealPlanEntryEditAction / Victual.MealPlanEntryEditObject
$(document).on("click", ".edit-meal-plan-entry-button", function(e)
{
	var mealPlanEntry = JSON.parse($(this).parents(".fc-h-event:first").attr("data-meal-plan-entry"));

	if (mealPlanEntry.type == "recipe")
	{
		$(".datetimepicker-wrapper").detach().prependTo("#add-recipe-form");
		$("input#day").detach().appendTo("#add-recipe-form")
		Victual.Components.DateTimePicker.Init(true);
		Victual.Components.DateTimePicker.SetValue(mealPlanEntry.day);
		$("#add-recipe-modal-title").text(__t("Edit meal plan entry"));
		$("#recipe_servings").val(mealPlanEntry.recipe_servings);
		Victual.Components.RecipePicker.SetId(mealPlanEntry.recipe_id);
		$("#add-recipe-modal").modal("show");
		$("#section_id_recipe").val(mealPlanEntry.section_id);
		Victual.FrontendHelpers.ValidateForm("add-recipe-form");
	}
	else if (mealPlanEntry.type == "product")
	{
		$(".datetimepicker-wrapper").detach().prependTo("#add-product-form");
		$("input#day").detach().appendTo("#add-product-form")
		Victual.Components.DateTimePicker.Init(true);
		Victual.Components.DateTimePicker.SetValue(mealPlanEntry.day);
		$("#add-product-modal-title").text(__t("Edit meal plan entry"));
		Victual.Components.ProductPicker.SetId(mealPlanEntry.product_id);
		$("#add-product-modal").modal("show");
		$("#section_id_product").val(mealPlanEntry.section_id);
		Victual.FrontendHelpers.ValidateForm("add-product-form");
		Victual.Components.ProductPicker.GetPicker().trigger("change");
	}
	else if (mealPlanEntry.type == "note")
	{
		$(".datetimepicker-wrapper").detach().prependTo("#add-note-form");
		$("input#day").detach().appendTo("#add-note-form");
		Victual.Components.DateTimePicker.Init(true);
		Victual.Components.DateTimePicker.SetValue(mealPlanEntry.day);
		$("#add-note-modal-title").text(__t("Edit meal plan entry"));
		$("#note").val(mealPlanEntry.note);
		$("#add-note-modal").modal("show");
		$("#section_id_note").val(mealPlanEntry.section_id);
		Victual.FrontendHelpers.ValidateForm("add-note-form");
	}
	Victual.IsMealPlanEntryEditAction = true;
	Victual.MealPlanEntryEditObject = mealPlanEntry;
});

// "Copy this day" (day header menu): open the copy-day modal (source day preset,
// target day picked via SecondaryDateTimePicker)
$(document).on("click", ".copy-day-button", function(e)
{
	var day = $(this).parent().parent().parent().data("date");

	$("#copy-day-modal-title").text(__t("Copy all meal plan entries of %s", day.toString()));
	Victual.Components.DateTimePicker.SetValue(day);
	Victual.Components.SecondaryDateTimePicker.Clear();
	$("#copy-day-modal").modal("show");
	Victual.FrontendHelpers.ValidateForm("copy-day-form");
	Victual.IsMealPlanEntryEditAction = false;
});

// Focus (and camera barcode scanner) handling when the modals open
$("#add-recipe-modal").on("shown.bs.modal", function(e)
{
	if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_DISABLE_BROWSER_BARCODE_CAMERA_SCANNING)
	{
		Victual.Components.CameraBarcodeScanner.Init();
	}

	Victual.Components.RecipePicker.GetInputElement().focus();
});

$("#add-note-modal").on("shown.bs.modal", function(e)
{
	$("#note").focus();
});

$("#add-product-modal").on("shown.bs.modal", function(e)
{
	if (!Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_DISABLE_BROWSER_BARCODE_CAMERA_SCANNING)
	{
		Victual.Components.CameraBarcodeScanner.Init();
	}

	Victual.Components.ProductPicker.GetInputElement().focus();
});

$("#copy-day-modal").on("shown.bs.modal", function(e)
{
	Victual.Components.SecondaryDateTimePicker.GetInputElement().focus();
});

// Delete an entry of any type (DELETE /api/objects/meal_plan/{id}) and reload
$(document).on("click", ".remove-recipe-button, .remove-note-button, .remove-product-button", function(e)
{
	var mealPlanEntry = JSON.parse($(this).parents(".fc-h-event:first").attr("data-meal-plan-entry"));

	Victual.Api.Delete('objects/meal_plan/' + mealPlanEntry.id.toString(), {},
		function(result)
		{
			window.location.reload();
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

$('#save-add-recipe-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("add-recipe-form", true) || $(".combobox-menu-visible").length)
	{
		return false;
	}

	var formData = $('#add-recipe-form').serializeJSON();
	formData.section_id = formData.section_id_recipe;
	delete formData.section_id_recipe;
	formData.day = Victual.Components.DateTimePicker.GetValue();

	if (Victual.IsMealPlanEntryEditAction)
	{
		Victual.Api.Put('objects/meal_plan/' + Victual.MealPlanEntryEditObject.id, formData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Post('objects/meal_plan', formData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

$('#save-add-note-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("add-note-form", true) || $(".combobox-menu-visible").length)
	{
		return false;
	}

	var jsonData = $('#add-note-form').serializeJSON();
	jsonData.day = Victual.Components.DateTimePicker.GetValue();
	jsonData.section_id = jsonData.section_id_note;
	delete jsonData.section_id_note;

	if (Victual.IsMealPlanEntryEditAction)
	{
		Victual.Api.Put('objects/meal_plan/' + Victual.MealPlanEntryEditObject.id, jsonData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Post('objects/meal_plan', jsonData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

});

$('#save-add-product-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("add-product-form", true) || $(".combobox-menu-visible").length)
	{
		return false;
	}

	var jsonData = $('#add-product-form').serializeJSON();
	jsonData.day = Victual.Components.DateTimePicker.GetValue();
	delete jsonData.display_amount;
	jsonData.product_amount = jsonData.amount;
	delete jsonData.amount;
	jsonData.product_qu_id = $("#qu_id").val();
	delete jsonData.qu_id;
	jsonData.section_id = jsonData.section_id_product;
	delete jsonData.section_id_product;

	if (Victual.IsMealPlanEntryEditAction)
	{
		Victual.Api.Put('objects/meal_plan/' + Victual.MealPlanEntryEditObject.id, jsonData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Post('objects/meal_plan', jsonData,
			function(result)
			{
				window.location.reload();
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

var itemsToCopy = 0;
var itemsCopied = 0;
$('#save-copy-day-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("copy-day-form", true))
	{
		return false;
	}

	var dayFrom = Victual.Components.DateTimePicker.GetValue();
	var dayTo = Victual.Components.SecondaryDateTimePicker.GetValue();

	Victual.Api.Get('objects/meal_plan?query[]=day=' + dayFrom,
		function(sourceMealPlanEntries)
		{
			itemsToCopy = sourceMealPlanEntries.length;

			sourceMealPlanEntries.forEach((item) =>
			{
				item.day = dayTo;
				item.done = 0;
				delete item.id;
				delete item.row_created_timestamp;

				Victual.Api.Post("objects/meal_plan", item,
					function(result)
					{
						itemsCopied++;

						if (itemsCopied == itemsToCopy)
						{
							window.location.reload();
						}
					},
					function(xhr)
					{
						Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
					}
				);
			});

			//window.location.reload();
		}
	);
});

$('#add-recipe-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('add-recipe-form'))
		{
			return false;
		}
		else
		{
			$("#save-add-recipe-button").click();
		}
	}
});

$('#add-product-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('add-product-form'))
		{
			return false;
		}
		else
		{
			$("#save-add-product-button").click();
		}
	}
});

$(document).on("keydown", "#servings", function(e)
{
	if (e.keyCode === 13) // Enter
	{
		e.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('add-recipe-form'))
		{
			return false;
		}
		else
		{
			$("#save-add-recipe-button").click();
		}
	}
});

$(document).on('click', '.recipe-order-missing-button', function(e)
{
	// Escaped again on the way out: attr() returns the decoded value, so whatever
	// escaping built the attribute is not in effect once it is read back
	var objectName = $(e.currentTarget).attr('data-recipe-name').escapeHTML();
	var objectId = $(e.currentTarget).attr('data-recipe-id');
	var button = $(this);
	var servings = $(e.currentTarget).attr('data-mealplan-servings');

	bootbox.confirm({
		// objectName came from a data- attribute read back with .attr(), which returns the
		// decoded string, and bootbox renders its message with .html() (sweep finding S29)
		message: __t('Are you sure you want to put all missing ingredients for recipe "%s" on the shopping list?', Victual.FrontendHelpers.EscapeHtml(objectName)),
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function(result)
		{
			if (result === true)
			{
				Victual.FrontendHelpers.BeginUiBusy();

				// Set the recipes desired_servings so that the "recipes resolved"-views resolve correctly based on the meal plan entry servings
				Victual.Api.Put('objects/recipes/' + objectId, { "desired_servings": servings },
					function(result)
					{
						Victual.Api.Post('recipes/' + objectId + '/add-not-fulfilled-products-to-shoppinglist', {},
							function(result)
							{
								if (button.attr("data-recipe-type") == "normal")
								{
									button.addClass("disabled");
									Victual.FrontendHelpers.EndUiBusy();
								}
								else
								{
									window.location.reload();
								}
							},
							function(xhr)
							{
								Victual.FrontendHelpers.EndUiBusy();
								Victual.Api.DefaultErrorHandler(xhr);
							}
						);
					},
					// Issue #591: this call used to have no error callback, so a refusal here
					// (mayAddMealPlanRecipeToShoppingList() should now prevent this for a caller
					// lacking RECIPES, but a stale button or a permission revoked mid-session
					// can still reach it) reported nothing and left the page stuck busy forever
					// instead of returning to the recipe-error case above.
					function(xhr)
					{
						Victual.FrontendHelpers.EndUiBusy();
						Victual.Api.DefaultErrorHandler(xhr);
					}
				);
			}
		}
	});
});

$(document).on('click', '.product-consume-button', function(e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var productId = $(e.currentTarget).attr('data-product-id');
	var consumeAmount = Number.parseFloat($(e.currentTarget).attr('data-product-amount'));
	var mealPlanEntryId = $(e.currentTarget).attr('data-mealplan-entry-id');

	Victual.Api.Post('stock/products/' + productId + '/consume', { 'amount': consumeAmount, 'spoiled': false },
		function(bookingResponse)
		{
			Victual.Api.Get('stock/products/' + productId,
				function(result)
				{
					// toastr renders its message as HTML (escapeHtml defaults to false), so the
					// product name is escaped before it goes in - see sweep finding S29
					var toastMessage = __t('Removed %1$s of %2$s from stock', consumeAmount.toString() + " " + __n(consumeAmount, Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name), Victual.FrontendHelpers.EscapeHtml(result.quantity_unit_stock.name_plural), true), Victual.FrontendHelpers.EscapeHtml(result.product.name)) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoStockTransaction(\'' + bookingResponse[0].transaction_id + '\')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>';

					Victual.Api.Put('objects/meal_plan/' + mealPlanEntryId, { "done": 1 },
						function(result)
						{
							Victual.FrontendHelpers.EndUiBusy();
							toastr.success(toastMessage);
							window.location.reload();
						},
						function(xhr)
						{
							// Coordinator round 2 fold-in: this used to call ShowGenericError() with a fixed
							// "probably this item already exists" message and no EndUiBusy() - wrong on both
							// counts for this PUT (a 403, e.g. from a permission revoked mid-session, is not a
							// uniqueness conflict, and the page stayed busy forever after the stock was already
							// consumed). DefaultErrorHandler() shows a generic message with the server's actual
							// error_message available on click, matching the sibling error callbacks around it.
							Victual.FrontendHelpers.EndUiBusy();
							Victual.Api.DefaultErrorHandler(xhr);
						}
					);
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy();
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

$(document).on('click', '.recipe-consume-button', function(e)
{
	// See the note above on attr() returning the decoded value
	var objectName = $(e.currentTarget).attr('data-recipe-name').escapeHTML();
	var objectId = $(e.currentTarget).attr('data-recipe-id');
	var mealPlanEntryId = $(e.currentTarget).attr('data-mealplan-entry-id');

	bootbox.confirm({
		message: __t('Are you sure you want to consume all ingredients needed by recipe "%s" (ingredients marked with "only check if any amount is in stock" will be ignored)?', Victual.FrontendHelpers.EscapeHtml(objectName)) +
			"<br><br>(" + __t("For ingredients that are only partially in stock, the in stock amount will be consumed.") + ")",
		closeButton: false,
		buttons: {
			confirm: {
				label: __t('Yes'),
				className: 'btn-success'
			},
			cancel: {
				label: __t('No'),
				className: 'btn-danger'
			}
		},
		callback: function(result)
		{
			if (result === true)
			{
				Victual.FrontendHelpers.BeginUiBusy();

				// Consuming stock succeeded; finishes the click either by marking one meal
				// plan entry done (the per-entry button - mealPlanEntryId is that entry's
				// own id) or, when there is none, straight away. Coordinator round 2
				// fold-in: the week button (weekRecipeConsumeButtonHtml above) sets no
				// data-mealplan-entry-id at all - RECIPE_TYPE_MEALPLAN_WEEK aggregates every
				// entry in the week, so there is no single meal_plan row "this" consume
				// could mean - and attr() then reads back `undefined`, which string
				// concatenation below turned into a real PUT to
				// objects/meal_plan/undefined that always 400ed after the stock was already
				// consumed. Adding a data-mealplan-entry-id to the week button would be
				// wrong in the other direction - inventing an entry id that does not
				// describe what was actually consumed - so this checks for one instead of
				// assuming it is always there.
				function finishConsume()
				{
					Victual.FrontendHelpers.EndUiBusy();
					toastr.success(__t('Removed all in stock ingredients needed by recipe \"%s\" from stock', Victual.FrontendHelpers.EscapeHtml(objectName)));
					window.location.reload();
				}

				Victual.Api.Post('recipes/' + objectId + '/consume', {},
					function(result)
					{
						if (!mealPlanEntryId)
						{
							finishConsume();
							return;
						}

						Victual.Api.Put('objects/meal_plan/' + mealPlanEntryId, { "done": 1 },
							finishConsume,
							function(xhr)
							{
								// Coordinator round 2 fold-in - see the product-consume-button handler's
								// identical fix above for why: EndUiBusy() was missing and the fixed message
								// was wrong for this PUT's own failures (e.g. a 403 read as "already exists").
								Victual.FrontendHelpers.EndUiBusy();
								Victual.Api.DefaultErrorHandler(xhr);
							}
						);
					},
					function(xhr)
					{
						Victual.FrontendHelpers.EndUiBusy();
						Victual.FrontendHelpers.ShowGenericError("A server error occured while processing your request", xhr.response);
					}
				);
			}
		}
	});
});

$(document).on("click", ".display-recipe-button", function(e)
{
	var objectId = $(e.currentTarget).attr('data-recipe-id');
	var servings = $(e.currentTarget).attr('data-mealplan-servings');

	// Set the recipes desired_servings so that the "recipes resolved"-views resolve correctly based on the meal plan entry servings
	Victual.Api.Put('objects/recipes/' + objectId, { "desired_servings": servings },
		function(result)
		{
			$("body").addClass("fullscreen-card");

			bootbox.dialog({
				message: '<iframe class="embed-responsive" src="' + U("/recipes?embedded&recipe=") + objectId + '#fullscreen"></iframe>',
				size: 'extra-large',
				backdrop: true,
				closeButton: false,
				buttons: {
					cancel: {
						label: __t('Close'),
						className: 'btn-secondary responsive-button',
						callback: function()
						{
							$(".modal").last().modal("hide");
						}
					}
				}
			});
		}
	);
});

$(document).on("click", ".mealplan-entry-done-button", function(e)
{
	e.preventDefault();

	var mealPlanEntryId = $(e.currentTarget).attr("data-mealplan-entry-id");
	Victual.Api.Put("objects/meal_plan/" + mealPlanEntryId, { "done": 1 },
		function(result)
		{
			window.location.reload();
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

$(document).on("click", ".mealplan-entry-undone-button", function(e)
{
	e.preventDefault();

	var mealPlanEntryId = $(e.currentTarget).attr("data-mealplan-entry-id");
	Victual.Api.Put("objects/meal_plan/" + mealPlanEntryId, { "done": 0 },
		function(result)
		{
			window.location.reload();
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

$(window).one("resize", function()
{
	// Automatically switch the calendar to "agendaDay" view on small screens and to "agendaWeek" otherwise
	var windowWidth = $(window).width();
	$(".calendar").each(function()
	{
		if (windowWidth < 768)
		{
			$(this).fullCalendar("changeView", "agendaDay");
		}
		else
		{
			$(this).fullCalendar("changeView", "agendaWeek");
		}
	});
});

Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				Victual.Components.ProductAmountPicker.Reload(productDetails.product.id, productDetails.quantity_unit_stock.id);
				Victual.Components.ProductAmountPicker.SetQuantityUnit(productDetails.quantity_unit_stock.id);

				if (Victual.IsMealPlanEntryEditAction)
				{
					$('#display_amount').val(Victual.MealPlanEntryEditObject.product_amount);
				}
				else
				{
					$('#display_amount').val(1);
				}

				RefreshLocaleNumberInput();
				$('#display_amount').focus();
				$('#display_amount').select();
				$(".input-group-productamountpicker").trigger("change");
				Victual.FrontendHelpers.ValidateForm('add-product-form');
			}
		);
	}
});

Victual.Components.RecipePicker.GetPicker().on('change', function(e)
{
	var recipeId = $(e.target).val();

	if (recipeId)
	{
		Victual.Api.Get('objects/recipes/' + recipeId,
			function(recipe)
			{
				$("#recipe_servings").val(recipe.base_servings);
				$("#recipe_servings").focus();
				$("#recipe_servings").select();
			}
		);
	}
});

$("#print-meal-plan-button").on("click", function(e)
{
	window.print();
});
