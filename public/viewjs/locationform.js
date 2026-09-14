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

		// Same reasoning, for storage_class_id: an unclassified location posts null rather
		// than "" or 0. The server derives is_freezer from this column when it is not null
		// (plan 23 questions 1 and 2) and leaves is_freezer alone otherwise, so posting null
		// rather than omitting the key is what lets a class be cleared.
		jsonData.storage_class_id = jsonData.storage_class_id === '' || jsonData.storage_class_id === undefined
			? null
			: parseInt(jsonData.storage_class_id, 10);

		return jsonData;
	}
});

// Creating a child of a freezer almost always means another freezer - the real layout plan
// 08 question 5 was confirmed against has stock sitting in "UprightFreezer / Door", and with
// question 3's "the flag does not inherit" answer the Door row has to carry the flag itself.
// So the checkbox follows the chosen parent while creating, and never while editing: an edit
// would otherwise silently rewrite a flag a person had deliberately set, and changing that
// flag changes due dates. Plan 23 question 3 (adopting 08's answer unchanged) extends the
// same default-from-parent behaviour to the storage class; defaulting it re-runs the
// storage_class_id handler below, which is what keeps the checkbox in sync when the parent
// is classified. Only an unclassified parent needs the direct copy on the last line, which
// is the one case the class cannot drive.
$('#parent_location_id').on('change', function ()
{
	if (Victual.EditMode !== 'create')
	{
		return;
	}

	var selected = $(document).find('#parent_location_id option:selected');
	$('#storage_class_id').val(selected.attr('data-storage-class-id') || '').trigger('change');
	$('#is_freezer').prop('checked', selected.attr('data-is-freezer') === '1');
});

// The freezer checkbox becomes a derived display wherever a class is set (plan 23 question
// 1), disabled so that a stale, unsubmitted value cannot suggest a save would keep it - the
// server derives is_freezer from the class on every write regardless of what a disabled
// field would have sent. Clearing the class re-enables the checkbox for direct editing,
// which is what an unclassified location has always offered.
$('#storage_class_id').on('change', function ()
{
	if ($(this).val() === '')
	{
		$('#is_freezer').prop('disabled', false);
		$('#is-freezer-derived-note').addClass('d-none');
		return;
	}

	var selected = $(document).find('#storage_class_id option:selected');
	$('#is_freezer').prop('checked', selected.attr('data-treats-as-freezer') === '1').prop('disabled', true);
	$('#is-freezer-derived-note').removeClass('d-none');
});

// The same print action the locations list offers, wired to this form's own controls.
Victual.LabelPrinting.Wire({
	trigger: '#location-form-print-button',
	printerSelect: '#location-form-label-printer',
	status: '#location-form-print-status'
});
