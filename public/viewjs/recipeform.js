// Powers the recipe create/edit form (views/recipeform.blade.php): saves the recipe (incl. picture upload) via the
// objects/recipes API and manages the ingredient (recipes_pos) and included recipe (recipes_nestings) tables.

/**
 * Post-save step shared by create and edit: stores userfields, uploads the newly selected
 * recipe picture (if any) to the "recipepictures" file group, then redirects to `location + recipeId`.
 * @param {object} result API response of the recipe POST/PUT (used for created_object_id on create)
 * @param {string} location Redirect base path ('/recipe/' or '/recipes?recipe=')
 * @param {object} jsonData The serialized recipe form data (checked for picture_file_name)
 */
function saveRecipePicture(result, location, jsonData)
{
	var recipeId = Victual.EditObjectId || result.created_object_id;
	Victual.EditObjectId = recipeId; // Victual.EditObjectId is not yet set when adding a recipe

	Victual.Components.UserfieldsForm.Save(() =>
	{
		if (jsonData.hasOwnProperty("picture_file_name") && !Victual.DeleteRecipePictureOnSave)
		{
			Victual.Api.UploadFile($("#recipe-picture")[0].files[0], 'recipepictures', jsonData.picture_file_name,
				(result) =>
				{
					window.location.href = U(location + recipeId);
				},
				(xhr) =>
				{
					Victual.FrontendHelpers.EndUiBusy("recipe-form");
					Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
				}
			);
		}
		else
		{
			window.location.href = U(location + recipeId);
		}
	});
}

// Form submit: POSTs objects/recipes (create) or PUTs objects/recipes/{id} (edit, id from Victual.EditObjectId);
// a pending picture deletion (Victual.DeleteRecipePictureOnSave) removes the old file from the "recipepictures" group first.
// The save button's data-location attribute decides whether to return to the recipes list or stay on the recipe page
$('.save-recipe').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("recipe-form", true))
	{
		return;
	}

	var jsonData = $('#recipe-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("recipe-form");

	if ($("#recipe-picture")[0].files.length > 0)
	{
		jsonData.picture_file_name = RandomString() + CleanFileName($("#recipe-picture")[0].files[0].name);
	}

	const location = $(e.currentTarget).attr('data-location') == 'return' ? '/recipes?recipe=' : '/recipe/';

	if (Victual.EditMode == 'create')
	{
		Victual.Api.Post('objects/recipes', jsonData,
			(result) => saveRecipePicture(result, location, jsonData));
		return;
	}

	if (Victual.DeleteRecipePictureOnSave)
	{
		jsonData.picture_file_name = null;

		Victual.Api.DeleteFile(Victual.RecipePictureFileName, 'recipepictures',
			function(result)
			{
				// Nothing to do
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("recipe-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}

	Victual.Api.Put('objects/recipes/' + Victual.EditObjectId, jsonData,
		(result) => saveRecipePicture(result, location, jsonData),
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("recipe-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// DataTables setup for the ingredients list, grouped by the hidden ingredient group column (4)
var recipesPosTables = $('#recipes-pos-table').DataTable({
	'order': [[1, 'asc']],
	"orderFixed": [[4, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 4 }
	].concat($.fn.dataTable.defaults.columnDefs),
	'rowGroup': {
		enable: true,
		dataSrc: 4
	}
});
$('#recipes-pos-table tbody').removeClass("d-none");
recipesPosTables.columns.adjust().draw();

// DataTables setup for the included recipes list
var recipesIncludesTables = $('#recipes-includes-table').DataTable({
	'order': [[1, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#recipes-includes-table tbody').removeClass("d-none");
recipesIncludesTables.columns.adjust().draw();

Victual.FrontendHelpers.ValidateForm('recipe-form');
setTimeout(function()
{
	$("#name").focus();
}, Victual.FormFocusDelay);

// Live validation while typing
$('#recipe-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('recipe-form');
});

// Enter submits the form (when valid)
$('#recipe-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('recipe-form'))
		{
			return false;
		}
		else
		{
			// The save buttons carry the class ".save-recipe", not an id - this used to
			// click "#save-recipe-button", which does not exist, so Enter did nothing
			$('.save-recipe').first().click();
		}
	}
});

// Delete an ingredient (expects data-recipe-pos-name / data-recipe-pos-id); confirms, then DELETEs objects/recipes_pos/{id}
$(document).on('click', '.recipe-pos-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-recipe-pos-name');
	var objectId = $(e.currentTarget).attr('data-recipe-pos-id');

	bootbox.confirm({
		// objectName came from a data- attribute read back with .attr(), which returns the
		// decoded string, and bootbox renders its message with .html() (sweep finding S29)
		message: __t('Are you sure you want to delete recipe ingredient "%s"?', Victual.FrontendHelpers.EscapeHtml(objectName)),
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
				Victual.Api.Delete('objects/recipes_pos/' + objectId, {},
					function(result)
					{
						window.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
					}
				);
			}
		}
	});
});

// Remove an included recipe (expects data-recipe-include-name / data-recipe-include-id); DELETEs objects/recipes_nestings/{id}
$(document).on('click', '.recipe-include-delete-button', function(e)
{
	var objectName = $(e.currentTarget).attr('data-recipe-include-name');
	var objectId = $(e.currentTarget).attr('data-recipe-include-id');

	bootbox.confirm({
		message: __t('Are you sure you want to remove the included recipe "%s"?', Victual.FrontendHelpers.EscapeHtml(objectName)),
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
				Victual.Api.Delete('objects/recipes_nestings/' + objectId, {},
					function(result)
					{
						window.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
					}
				);
			}
		}
	});
});

// Show an ingredient's note (from data-recipe-pos-note) in an alert dialog
$(document).on('click', '.recipe-pos-show-note-button', function(e)
{
	// recipes_pos.note is a text column, so the API stores what was typed - markup
	// included. The Blade template escapes it into the attribute, but .attr() returns the
	// *decoded* string and bootbox renders its message with .html(), so the escaping has
	// to happen here, at the point of use (sweep finding S29).
	var note = Victual.FrontendHelpers.EscapeHtml($(e.currentTarget).attr('data-recipe-pos-note'));

	bootbox.alert(note);
});

// Edit an ingredient: opens the recipe position form (/recipe/{id}/pos/{posId}) embedded in an iframe dialog
$(document).on('click', '.recipe-pos-edit-button', function(e)
{
	e.preventDefault();

	var productId = $(e.currentTarget).attr("data-product-id");
	var recipePosId = $(e.currentTarget).attr('data-recipe-pos-id');

	bootbox.dialog({
		message: '<iframe class="embed-responsive" src="' + U("/recipe/") + Victual.EditObjectId.toString() + '/pos/' + recipePosId.toString() + '?embedded&product=' + productId.toString() + '"></iframe>',
		size: 'large',
		backdrop: true,
		closeButton: false,
		className: "form"
	});
});

// Edit an included recipe: saves the recipe form first (so unsaved edits survive the reload triggered later),
// then opens the include modal prefilled from the row's data attributes
$(document).on('click', '.recipe-include-edit-button', function(e)
{
	var id = $(e.currentTarget).attr('data-recipe-include-id');
	var recipeId = $(e.currentTarget).attr('data-recipe-included-recipe-id');
	var recipeServings = $(e.currentTarget).attr('data-recipe-included-recipe-servings');

	Victual.Api.Put('objects/recipes/' + Victual.EditObjectId, $('#recipe-form').serializeJSON(),
		function(result)
		{
			$("#recipe-include-editform-title").text(__t("Edit included recipe"));
			$("#recipe-include-form").data("edit-mode", "edit");
			$("#recipe-include-form").data("recipe-nesting-id", id);
			Victual.Components.RecipePicker.SetId(recipeId);
			$("#includes_servings").val(recipeServings);
			$("#recipe-include-editform-modal").modal("show");
			Victual.FrontendHelpers.ValidateForm("recipe-include-form");
		}
	);
});

// Add an ingredient: opens the recipe position form (/recipe/{id}/pos/new) embedded in an iframe dialog
$("#recipe-pos-add-button").on("click", function(e)
{
	e.preventDefault();

	bootbox.dialog({
		message: '<iframe class="embed-responsive" src="' + U("/recipe/") + Victual.EditObjectId + '/pos/new?embedded"></iframe>',
		size: 'large',
		backdrop: true,
		closeButton: false,
		className: "form"
	});
});

// Add an included recipe: saves the recipe form first, then opens the include modal in create mode
$("#recipe-include-add-button").on("click", function(e)
{
	Victual.Api.Put('objects/recipes/' + Victual.EditObjectId, $('#recipe-form').serializeJSON(),
		function(result)
		{
			$("#recipe-include-editform-title").text(__t("Add included recipe"));
			$("#recipe-include-form").data("edit-mode", "create");
			Victual.Components.RecipePicker.Clear();
			Victual.Components.RecipePicker.GetInputElement().focus();
			$("#recipe-include-editform-modal").modal("show");
			Victual.FrontendHelpers.ValidateForm("recipe-include-form");
		}
	);
});

// Include modal submit: POSTs objects/recipes_nestings (create) or PUTs objects/recipes_nestings/{id} (edit,
// id from the form's recipe-nesting-id data), then triggers the IngredientsChanged reload below
$('#save-recipe-include-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("recipe-include-form", true))
	{
		return false;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var nestingId = $("#recipe-include-form").data("recipe-nesting-id");
	var editMode = $("#recipe-include-form").data("edit-mode");

	var jsonData = {};
	jsonData.includes_recipe_id = Victual.Components.RecipePicker.GetValue();
	jsonData.servings = $("#includes_servings").val();
	jsonData.recipe_id = Victual.EditObjectId;

	if (editMode === 'create')
	{
		Victual.Api.Post('objects/recipes_nestings', jsonData,
			function(result)
			{
				window.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/recipes_nestings/' + nestingId, jsonData,
			function(result)
			{
				window.postMessage(WindowMessageBag("IngredientsChanged"), Victual.BaseUrl);
			},
			function(xhr)
			{
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Picking a new picture cancels any pending picture deletion and updates the picture labels
$("#recipe-picture").on("change", function(e)
{
	$("#recipe-picture-label").removeClass("d-none");
	$("#recipe-picture-label-none").addClass("d-none");
	$("#delete-current-recipe-picture-on-save-hint").addClass("d-none");
	$("#current-recipe-picture").addClass("d-none");
	Victual.DeleteRecipePictureOnSave = false;
});

// Mark the current picture for deletion on the next save (actual file delete happens in the submit handler)
Victual.DeleteRecipePictureOnSave = false;
$("#delete-current-recipe-picture-button").on("click", function(e)
{
	Victual.DeleteRecipePictureOnSave = true;
	$("#current-recipe-picture").addClass("d-none");
	$("#delete-current-recipe-picture-on-save-hint").removeClass("d-none");
	$("#recipe-picture-label").addClass("d-none");
	$("#recipe-picture-label-none").removeClass("d-none");
});

Victual.Components.UserfieldsForm.Load();

// When ingredients or includes changed (posted by the embedded forms / handlers above),
// save the recipe form and reload the recipe page to re-render the tables
$(window).on("message", function(e)
{
	var data = e.originalEvent.data;

	if (data.Message === "IngredientsChanged")
	{
		Victual.Api.Put('objects/recipes/' + Victual.EditObjectId, $('#recipe-form').serializeJSON(),
			function(result)
			{
				window.location.href = U('/recipe/' + Victual.EditObjectId);
			}
		);
	}
});

// The print action (views/components/label_print_widget.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'recipe',
	trigger: '#recipe-form-button',
	printerSelect: '#recipe-form-printer',
	status: '#recipe-form-status'
});
