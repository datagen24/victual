// Implements the UserPicker widget (views/components/userpicker.blade.php): a combobox of
// users, driven by a hidden #user_id select plus #user_id_text_input.
// Public API: GetPicker/GetInputElement/GetValue/SetValue/SetId/Clear.
Victual.Components.UserPicker = {};

/** @returns {jQuery} The hidden select backing the combobox (#user_id) */
Victual.Components.UserPicker.GetPicker = function ()
{
	return $('#user_id');
}

/** @returns {jQuery} The visible text input of the combobox (#user_id_text_input) */
Victual.Components.UserPicker.GetInputElement = function ()
{
	return $('#user_id_text_input');
}

/** @returns {string} The currently selected user id */
Victual.Components.UserPicker.GetValue = function ()
{
	return $('#user_id').val();
}

/** Sets the visible text and triggers change (does not itself resolve it to an option) */
Victual.Components.UserPicker.SetValue = function (value)
{
	Victual.Components.UserPicker.GetInputElement().val(value);
	Victual.Components.UserPicker.GetInputElement().trigger('change');
}

/** Selects the option with the given user id directly, refreshing the combobox display */
Victual.Components.UserPicker.SetId = function (value)
{
	var picker = Victual.Components.UserPicker.GetPicker();
	var combobox = picker.data('combobox');

	picker.val(value);
	combobox.refresh();

	// bootstrap-combobox serializes the form from its own internal hidden input (its
	// $target), not from #user_id itself - the plugin moves #user_id's name attribute onto
	// that hidden input at init time, so #user_id is display-only from then on. Only the
	// plugin's own select()/clearTarget() (wired to picking a dropdown item, or clicking
	// its "x" button) keep $target in sync with #user_id; setting #user_id directly, as
	// above, updates what's shown but left $target holding whatever id was already there,
	// so this function silently failed to change what actually gets submitted (issue #587,
	// surfaced through Clear() and choresoverview.js's own SetId(null) call).
	combobox.$target.val(value === null ? '' : value).trigger('change');

	Victual.Components.UserPicker.GetInputElement().trigger('change');
}

/** Clears both the text and the selected id */
Victual.Components.UserPicker.Clear = function ()
{
	Victual.Components.UserPicker.SetValue('');
	Victual.Components.UserPicker.SetId(null);
}

$(".user-combobox").combobox(BootstrapComboboxDefaults);

// Prefill by username (from the template's data-prefill-by-username attribute on the wrapper),
// matched against additional-searchdata first, falling back to a name-contains match
var prefillUser = Victual.Components.UserPicker.GetPicker().parent().data('prefill-by-username').toString();
if (typeof prefillUser !== "undefined")
{
	var possibleOptionElement = $("#user_id option[data-additional-searchdata*=\"" + prefillUser + "\"]").first();
	if (possibleOptionElement.length === 0)
	{
		possibleOptionElement = $("#user_id option:contains(\"" + prefillUser + "\")").first();
	}

	if (possibleOptionElement.length > 0)
	{
		$('#user_id').val(possibleOptionElement.val());
		$('#user_id').data('combobox').refresh();
		$('#user_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.UserPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}

// Prefill by user id (from the template's data-prefill-by-user-id attribute on the wrapper)
var prefillUserId = Victual.Components.UserPicker.GetPicker().parent().data('prefill-by-user-id').toString();
if (typeof prefillUserId !== "undefined")
{
	// A predicate, not a selector built by concatenation - see productpicker.js's
	// FindOptionByValue for why this file no longer quotes values into Sizzle syntax.
	var possibleOptionElement = $('#user_id option').filter(function ()
	{
		return $(this).attr('value') === prefillUserId;
	}).first();
	if (possibleOptionElement.length > 0)
	{
		$('#user_id').val(possibleOptionElement.val());
		$('#user_id').data('combobox').refresh();
		$('#user_id').trigger('change');

		var nextInputElement = $(document).find(Victual.Components.UserPicker.GetPicker().parent().data('next-input-selector').toString());
		nextInputElement.focus();
	}
}
