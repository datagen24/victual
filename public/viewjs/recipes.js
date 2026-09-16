// Powers the recipes overview view (views/recipes.blade.php): recipe list (table + gallery tab) with search and
// stock fulfillment status filter, the selected recipe's detail card, and actions such as consume all ingredients,
// put missing ingredients on the shopping list, copy/delete a recipe, servings scaling and adding to the meal plan.

// DataTables setup for the recipes list; single row selection shows the recipe (see the "select" handler below)
var recipesTables = $('#recipes-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 2 },
		{ "type": "html-num-fmt", "targets": 2 },
		{ "type": "html-num-fmt", "targets": 3 }
	].concat($.fn.dataTable.defaults.columnDefs),
	select: {
		style: 'single',
		selector: 'tr td:not(:first-child)'
	},
	'initComplete': function()
	{
		this.api().row({ order: 'current' }, 0).select();
	}
});
$('#recipes-table tbody').removeClass("d-none");
recipesTables.columns.adjust().draw();

// Restore the gallery tab when requested via the "tab" URI parameter or remembered in localStorage
if ((typeof GetUriParam("tab") !== "undefined" && GetUriParam("tab") === "gallery") || window.localStorage.getItem("recipes_last_tab_id") == "gallery-tab")
{
	$(".nav-tabs a[href='#gallery']").tab("show");
}

// Highlight the recipe given via the "recipe" URI parameter in table and gallery (the detail card is rendered server-side)
var recipe = GetUriParam("recipe");
if (typeof recipe !== "undefined")
{
	$("#recipes-table tr").removeClass("selected");
	var rowId = "#recipe-row-" + recipe;
	$(document).find(rowId).addClass("selected")

	var cardId = "#RecipeGalleryCard-" + recipe;
	$(document).find(cardId).addClass("border-primary");

	if ($(window).width() < 768)
	{
		// Scroll to recipe card on mobile
		$("#selectedRecipeCard")[0].scrollIntoView();
	}
}

// Re-apply search / status filter passed via URI parameters
if (GetUriParam("search") !== undefined)
{
	$("#search").val(GetUriParam("search"));
	setTimeout(function()
	{
		$("#search").keyup();
	}, 50);
}

if (GetUriParam("status") !== undefined)
{
	$("#status-filter").val(GetUriParam("status"));
	setTimeout(function()
	{
		$("#status-filter").trigger("change");
	}, 50);
}

// Remember the last active tab (list/gallery) in localStorage
$("a[data-toggle='tab']").on("shown.bs.tab", function(e)
{
	var tabId = $(e.target).attr("id");
	window.localStorage.setItem("recipes_last_tab_id", tabId);
});

// Debounced search: filters the table, syncs the "search" URI parameter and hides non-matching gallery cards
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();

	recipesTables.search(value).draw();

	if (!value)
	{
		RemoveUriParam("search");
	}
	else
	{
		UpdateUriParam("search", value);
	}

	$(".recipe-gallery-item").removeClass("d-none");
	// The search box's text, filtered as a predicate rather than concatenated into
	// ":not(:contains_case_insensitive(...))" - an unbalanced bracket in the box would
	// otherwise throw a Sizzle syntax error and leave the gallery half filtered. Same
	// matching rule as the custom expression in extensions.js: case-insensitive substring.
	var needle = String(value).toLowerCase();
	$(".recipe-gallery-item .card-title-search").filter(function ()
	{
		return $(this).text().toLowerCase().indexOf(needle) === -1;
	}).parent().parent().parent().addClass("d-none");
}, Victual.FormFocusDelay));

$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#search").trigger("keyup");
	$("#status-filter").trigger("change");
});

// Stock fulfillment status filter: filters the hidden status column of the table and the gallery cards (by CSS class)
$("#status-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	recipesTables.column(recipesTables.colReorder.transpose(6)).search(value).draw();

	$('.recipe-gallery-item').removeClass('d-none');
	if (value !== "")
	{
		if (value === 'Xenoughinstock')
		{
			$('.recipe-gallery-item').not('.recipe-enoughinstock').addClass('d-none');
		}
		else if (value === 'enoughinstockwithshoppinglist')
		{
			$('.recipe-gallery-item').not('.recipe-enoughinstockwithshoppinglist').addClass('d-none');
		}
		if (value === 'notenoughinstock')
		{
			$('.recipe-gallery-item').not('.recipe-notenoughinstock').addClass('d-none');
		}
	}

	if (!value)
	{
		RemoveUriParam("status");
	}
	else
	{
		UpdateUriParam("status", value);
	}
});

// Delete recipe (expects data-recipe-name / data-recipe-id); confirms via bootbox, then DELETEs objects/recipes/{id}
$(".recipe-delete").on('click', function(e)
{
	e.preventDefault();

	var objectName = $(e.currentTarget).attr('data-recipe-name');
	var objectId = $(e.currentTarget).attr('data-recipe-id');

	bootbox.confirm({
		// objectName came from a data- attribute read back with .attr(), which returns the
		// decoded string, and bootbox renders its message with .html() (sweep finding S29)
		message: __t('Are you sure you want to delete recipe "%s"?', Victual.FrontendHelpers.EscapeHtml(objectName)),
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
				Victual.Api.Delete('objects/recipes/' + objectId, {},
					function(result)
					{
						window.location.href = U('/recipes');
					}
				);
			}
		}
	});
});

// Copy recipe: POSTs recipes/{id}/copy and navigates to the new copy
$(".recipe-copy").on('click', function(e)
{
	e.preventDefault();

	var objectId = $(e.currentTarget).attr('data-recipe-id');

	Victual.Api.Post("recipes/" + objectId.toString() + "/copy", {},
		function(result)
		{
			window.location.href = U('/recipes?recipe=' + result.created_object_id.toString());
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

// "Put missing products on shopping list": shows a confirmation containing the (server-rendered, hidden) list of missing
// ingredients with checkboxes, then POSTs recipes/{id}/add-not-fulfilled-products-to-shoppinglist with the unchecked
// products as excludedProductIds
$(document).on('click', '.recipe-shopping-list', function(e)
{
	var objectName = $(e.currentTarget).attr('data-recipe-name');
	var objectId = $(e.currentTarget).attr('data-recipe-id');

	bootbox.confirm({
		// The recipe name is escaped; the outerHTML appended after it is markup this page
		// rendered itself and is deliberate (sweep finding S29)
		message: __t('Are you sure you want to put all missing ingredients for recipe "%s" on the shopping list?', Victual.FrontendHelpers.EscapeHtml(objectName)) + "<br><br>" + __t("Uncheck ingredients to not put them on the shopping list") + ":" + $("#missing-recipe-pos-list")[0].outerHTML.replace("d-none", ""),
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

				var excludedProductIds = new Array();
				$(".missing-recipe-pos-product-checkbox:checkbox:not(:checked)").each(function()
				{
					excludedProductIds.push($(this).data("product-id"));
				});

				Victual.Api.Post('recipes/' + objectId + '/add-not-fulfilled-products-to-shoppinglist', { "excludedProductIds": excludedProductIds },
					function(result)
					{
						window.location.reload();
					},
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

// "Consume all ingredients": confirms, then POSTs recipes/{id}/consume to book all needed in-stock amounts out of stock
$(".recipe-consume").on('click', function(e)
{
	var objectName = $(e.currentTarget).attr('data-recipe-name');
	var objectId = $(e.currentTarget).attr('data-recipe-id');

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

				Victual.Api.Post('recipes/' + objectId + '/consume', {},
					function(result)
					{
						Victual.FrontendHelpers.EndUiBusy();
						toastr.success(__t('Removed all in stock ingredients needed by recipe \"%s\" from stock', Victual.FrontendHelpers.EscapeHtml(objectName)));
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

// Row selection: with the "side by side" user setting the page reloads with the selected recipe in the "recipe"
// URI parameter, otherwise the recipe is opened embedded in a fullscreen bootbox dialog (row's data-recipe-id)
recipesTables.on('select', function(e, dt, type, indexes)
{
	if (type === 'row')
	{
		var selectedRecipeId = $(recipesTables.row(indexes[0]).node()).data("recipe-id");
		var currentRecipeId = location.search.split('recipe=')[1];

		if (BoolVal(Victual.UserSettings.recipes_show_list_side_by_side))
		{
			if (selectedRecipeId.toString() !== currentRecipeId)
			{
				UpdateUriParam("recipe", selectedRecipeId.toString());
				window.location.reload();
			}
		}
		else
		{
			$("body").addClass("fullscreen-card");

			bootbox.dialog({
				message: '<iframe class="embed-responsive" src="' + U("/recipes?embedded&recipe=") + selectedRecipeId + '#fullscreen"></iframe>',
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
	}
});

// Gallery card click: same behavior as row selection above (side-by-side navigation or embedded dialog)
$(".recipe-gallery-item").on("click", function(e)
{
	e.preventDefault();

	var selectedRecipeId = $(this).data("recipe-id");

	if (BoolVal(Victual.UserSettings.recipes_show_list_side_by_side))
	{
		window.location.href = U('/recipes?tab=gallery&recipe=' + selectedRecipeId);
	}
	else
	{
		$("body").addClass("fullscreen-card");

		bootbox.dialog({
			message: '<iframe class="embed-responsive" src="' + U("/recipes?embedded&recipe=") + selectedRecipeId + '#fullscreen"></iframe>',
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
});

$(".recipe-edit-button").on("click", function(e)
{
	e.stopPropagation();
});

// Toggle the recipe card between normal and fullscreen layout (reflected in the #fullscreen location hash)
$(".recipe-fullscreen").on('click', function(e)
{
	e.preventDefault();

	$("#selectedRecipeCard").toggleClass("fullscreen");
	$("body").toggleClass("fullscreen-card");
	$("#selectedRecipeCard .card-header").toggleClass("fixed-top");
	$("#selectedRecipeCard .card-body").toggleClass("mt-5");
	$(".recipe-content-container").toggleClass("row");
	$(".recipe-content-container .ingredients").toggleClass("tab-pane").toggleClass("col-12 col-md-6 col-xl-4");
	$(".recipe-content-container .preparation").toggleClass("tab-pane").toggleClass("col-12 col-md-6 col-xl-8");
	$(".recipe-headline").toggleClass("d-none");

	if ($("body").hasClass("fullscreen-card"))
	{
		window.location.hash = "#fullscreen";
	}
	else
	{
		window.history.replaceState(null, null, " ");
	}
});

// Print: leave fullscreen layout first, then open the browser print dialog
$(".recipe-print").on('click', function(e)
{
	e.preventDefault();

	$("#selectedRecipeCard").removeClass("fullscreen");
	$("body").removeClass("fullscreen-card");
	$("#selectedRecipeCard .card-header").removeClass("fixed-top");
	$("#selectedRecipeCard .card-body").removeClass("mt-5");

	window.history.replaceState(null, null, " ");
	window.print();
});

// Servings scaling: persists desired_servings via PUT objects/recipes/{id} (id from the input's data-recipe-id)
// and reloads so the server re-renders the scaled ingredient amounts
$('#servings-scale').keyup(function(event)
{
	var data = {};
	data.desired_servings = $(this).val();

	Victual.Api.Put('objects/recipes/' + $(this).data("recipe-id"), data,
		function(result)
		{
			window.location.reload();
		}
	);
});

// Checkbox handling inside the "missing ingredients" confirmation dialog (toggle row <-> checkbox)
$(document).on("click", ".missing-recipe-pos-select-button", function(e)
{
	e.preventDefault();

	var checkbox = $(this).find(".form-check-input");
	checkbox.prop("checked", !checkbox.prop("checked"));

	$(this).toggleClass("list-group-item-primary");
});

$(document).on("click", ".missing-recipe-pos-product-checkbox", function(e)
{
	e.stopPropagation();

	$(this).prop("checked", !$(this).prop("checked"));
	$(this).parent().parent().click();
});

if (window.location.hash === "#fullscreen")
{
	$("#selectedRecipeToggleFullscreenButton").click();
}

// The print action (views/components/label_print_list_header.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'recipe',
	trigger: '.recipe-label-print',
	within: '#recipes-table',
	printerSelect: '#recipes-printer',
	status: '#recipes-status'
});

// Strike through an ingredient line when its "done" checkbox is clicked (visual only, not persisted)
$(document).on('click', '.ingredient-done-button', function(e)
{
	e.preventDefault();

	$(e.currentTarget).parent().toggleClass("text-strike-through").toggleClass("text-muted");
});

// "Add to meal plan": opens the modal prefilled with today's date and the clicked recipe (data-recipe-id)
$(document).on("click", ".add-to-mealplan-button", function(e)
{
	Victual.Components.DateTimePicker.Init(true);
	Victual.Components.DateTimePicker.SetValue(moment().format("YYYY-MM-DD"));
	Victual.Components.RecipePicker.Clear();
	$("#add-to-mealplan-modal").modal("show");
	$('#recipe_id').val($(e.currentTarget).attr("data-recipe-id"));
	$('#recipe_id').data('combobox').refresh();
	$('#recipe_id').trigger('change');
	Victual.FrontendHelpers.ValidateForm("add-to-mealplan-form");
	$("#recipe_servings").focus();
});

// Meal plan modal submit: POSTs objects/meal_plan with the form data plus the picked day
$('#save-add-to-mealplan-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("add-to-mealplan-form", true) || $(".combobox-menu-visible").length)
	{
		return false;
	}

	var formData = $('#add-to-mealplan-form').serializeJSON();
	formData.day = Victual.Components.DateTimePicker.GetValue();

	Victual.Api.Post('objects/meal_plan', formData,
		function(result)
		{
			toastr.success(__t("Successfully added the recipe to the meal plan"));
			$("#add-to-mealplan-modal").modal("hide");
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

$('#add-to-mealplan-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('add-to-mealplan-form'))
		{
			return false;
		}
		else
		{
			$("#save-add-to-mealplan-button").click();
		}
	}
});
