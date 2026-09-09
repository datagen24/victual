// View script for the location create/edit form (views/locationform.blade.php):
// saves a location via the objects/locations API endpoints, incl. userfields.
// Everything here is the shared form factory (public/js/victual_entity.js), which owns
// validation, Enter-to-submit, the save/disable/re-enable cycle, the userfields round trip
// and the embedded-dialog Reload message.

Victual.EntityForm({
	form: 'location-form',
	save: '#save-location-button',
	endpoint: 'objects/locations',
	list: '/locations',
	body: function (jsonData)
	{
		// serializeJSON() hands back the empty string for an unselected <select>, and the
		// column is a nullable integer: "" would be written as a parent id of 0, which is a
		// location that does not exist. A root location posts null.
		jsonData.parent_location_id = jsonData.parent_location_id === '' || jsonData.parent_location_id === undefined
			? null
			: parseInt(jsonData.parent_location_id, 10);

		return jsonData;
	}
});

// Creating a child of a freezer almost always means another freezer - the real layout plan
// 08 question 5 was confirmed against has stock sitting in "UprightFreezer / Door", and with
// question 3's "the flag does not inherit" answer the Door row has to carry the flag itself.
// So the checkbox follows the chosen parent while creating, and never while editing: an edit
// would otherwise silently rewrite a flag a person had deliberately set, and changing that
// flag changes due dates.
$('#parent_location_id').on('change', function ()
{
	if (Victual.EditMode !== 'create')
	{
		return;
	}

	var selected = $(document).find('#parent_location_id option:selected');
	$('#is_freezer').prop('checked', selected.attr('data-is-freezer') === '1');
});

// The same print action the locations list offers, wired to this form's own controls.
Victual.LabelPrinting.Wire({
	trigger: '#location-form-print-button',
	printerSelect: '#location-form-label-printer',
	status: '#location-form-print-status'
});
