// Implements the LocationPicker widget (views/components/locationpicker.blade.php): a combobox
// of locations, driven by a hidden #location_id select plus #location_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Victual.Components.LocationPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#location_id) */
Victual.Components.LocationPicker.GetPicker = function ()
{
	return $('#location_id');
}

/** @returns {jQuery} The visible text input of the combobox (#location_id_text_input) */
Victual.Components.LocationPicker.GetInputElement = function ()
{
	return $('#location_id_text_input');
}

/** @returns {string} The currently selected location id */
Victual.Components.LocationPicker.GetValue = function ()
{
	return $('#location_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Victual.Components.LocationPicker.SetValue = function (value)
{
	Victual.Components.LocationPicker.GetInputElement().val(value);
	Victual.Components.LocationPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given location id directly, refreshing the combobox display */
Victual.Components.LocationPicker.SetId = function (value)
{
	Victual.Components.LocationPicker.GetPicker().val(value);
	Victual.Components.LocationPicker.GetPicker().data('combobox').refresh();
	Victual.Components.LocationPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Victual.Components.LocationPicker.Clear = function ()
{
	Victual.Components.LocationPicker.SetValue('');
	Victual.Components.LocationPicker.SetId(null);
}

$(".location-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by location name (from the template's data-prefill-by-name attribute on the wrapper),
// then focus the configured next input (data-next-input-selector).
//
// The option text is now the location's full path ("Kitchen / Pantry / Top shelf"), so the
// :contains() match below matches any path containing the string - "Kitchen" selects the
// first option under the kitchen rather than the kitchen itself, and a name that is only
// unique among siblings can match in the wrong branch. No template passes prefillByName
// today (the stock entry form passes prefillById; purchase and inventory pass neither), so
// this is left as it is: new callers should prefer the id.
var prefillByName = Victual.Components.LocationPicker.GetPicker().parent().data('prefill-by-name').toString();
if (typeof prefillByName !== "undefined")
{
	possibleOptionElement = $("#location_id option:contains(\"" + prefillByName + "\")").first();

	if (possibleOptionElement.length > 0)
	{
		$('#location_id').val(possibleOptionElement.val());
		$('#location_id').data('combobox').refresh();
		$('#location_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.LocationPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by location id (from the template's data-prefill-by-id attribute on the wrapper)
var prefillById = Victual.Components.LocationPicker.GetPicker().parent().data('prefill-by-id').toString();
if (typeof prefillById !== "undefined")
{
	$('#location_id').val(prefillById);
	$('#location_id').data('combobox').refresh();
	$('#location_id').trigger('change');

	var nextInputElement = $(document).find(Victual.Components.LocationPicker.GetPicker().parent().data('next-input-selector').toString());
	nextInputElement.focus();
}
