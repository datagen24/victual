// Powers the custom userfield create/edit modal form (userfieldform.blade.php) -
// defines a custom field attached to a userentity/built-in entity. Victual.EditMode
// ('create'/'edit') and Victual.EditObjectId select POST vs PUT, both inside the shared
// form factory (public/js/victual_entity.js). A userfield definition carries no
// userfields of its own, hence userfields: false.

Victual.EntityForm({
	form: 'userfield-form',
	save: '#save-userfield-button',
	endpoint: 'objects/userfields',
	// Opened from one entity's field list, saving returns to that scoped list
	list: function ()
	{
		if (GetUriParam("entity"))
		{
			return "/userfields?entity=" + GetUriParam("entity");
		}

		return "/userfields";
	},
	userfields: false,
	validateOnSelectChange: true,
	focus: null,
	// serializeJSON() hands back the empty string for a blank numberpicker
	// (views/components/numberpicker.blade.php is a plain <input type="number">), and
	// sort_number is a nullable integer: "" is not a valid integer literal, so the generic
	// entity endpoint's write was refused (400, issue #574) instead of leaving the column
	// NULL. Same reasoning as locationform.js's parent_location_id/storage_class_id/
	// tare_weight handling.
	body: function (jsonData)
	{
		jsonData.sort_number = jsonData.sort_number === '' || jsonData.sort_number === undefined
			? null
			: parseInt(jsonData.sort_number, 10);

		return jsonData;
	}
});

// Field type dropdown drives the rest of the form: shows the "config" textarea (list of
// preset values) only for preset-list/preset-checklist types, and swaps in the matching
// default-value input for the selected type (each type has its own hidden .userfield-type-*
// default-value group in the markup)
$("#type").on("change", function (e)
{
	var value = $(this).val();

	if (value === "preset-list" || value === "preset-checklist")
	{
		$("#config").parent().removeClass("d-none");
		$("#config-hint").text(__t("A predefined list of values, one per line"));
	}
	else
	{
		$("#config").parent().addClass("d-none");
		$("#config-hint").text("");
	}

	$("#default-value-group").addClass("d-none");
	$("#default-value-group.userfield-type-" + value).removeClass("d-none");
});

// Pre-select the entity when opened scoped to one (and focus the name field instead of
// the entity picker in that case)
if (GetUriParam("entity"))
{
	$("#entity").val(GetUriParam("entity"));
	$("#entity").trigger("change");
	setTimeout(function ()
	{
		$('#name').focus();
	}, Victual.FormFocusDelay);
}
else
{
	setTimeout(function ()
	{
		$('#entity').focus();
	}, Victual.FormFocusDelay);
}

$("#type").trigger("change");
