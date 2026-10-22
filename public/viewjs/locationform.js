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

		// Same reasoning again, for the vessel tare (plan 29): a blank numberpicker posts "",
		// which a nullable double precision column would refuse the same way a nullable
		// integer refuses "" for a parent id - so both null together mean "not a vessel"
		// rather than a tare of zero.
		jsonData.tare_weight = jsonData.tare_weight === '' || jsonData.tare_weight === undefined
			? null
			: parseFloat(jsonData.tare_weight);
		jsonData.tare_qu_id = jsonData.tare_qu_id === '' || jsonData.tare_qu_id === undefined
			? null
			: parseInt(jsonData.tare_qu_id, 10);

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

// The same print action the locations list offers, wired to this form's own controls
// (views/components/label_print_widget.blade.php).
Victual.LabelPrinting.Wire({
	kind: 'location',
	trigger: '#location-form-button',
	printerSelect: '#location-form-printer',
	status: '#location-form-status'
});

// Labels for everything under this location (LabelOperationsService::IssueSubtree). Preview
// is a dry run; Print sends the same options with an idempotency key minted for that preview,
// so a double click or a retried request queues the batch once.
(function()
{
	var holder = document.getElementById('location-batch');
	if (holder === null)
	{
		return;
	}
	var locationId = parseInt(holder.getAttribute('data-location-id'), 10);
	var status = document.getElementById('location-batch-status');
	var list = document.getElementById('location-batch-list');
	var printButton = document.getElementById('location-batch-print');
	var planned = null;

	function clear(node)
	{
		while (node.firstChild !== null)
		{
			node.removeChild(node.firstChild);
		}
	}

	function report(kind, text)
	{
		clear(status);
		var box = document.createElement('div');
		box.className = 'alert alert-' + kind + ' mb-0';
		box.appendChild(document.createTextNode(text));
		status.appendChild(box);
	}

	function refusal(xhr)
	{
		try
		{
			var body = JSON.parse((xhr && xhr.responseText) || '{}');
			if (body.error_message)
			{
				return body.error_message;
			}
		}
		catch (error)
		{
			// Not JSON: a transport failure, described by the fallback below.
		}
		return __t('the server did not answer');
	}

	var REASONS = { already_labelled: __t('already has a label'), inactive: __t('inactive') };

	function show(result)
	{
		clear(list);
		result.queued.forEach(function(entry)
		{
			var item = document.createElement('li');
			item.appendChild(document.createTextNode((result.dry_run ? '\u2610 ' : '\u2713 ') + entry.path + (entry.reprint ? ' (' + __t('reprint') + ')' : '')));
			list.appendChild(item);
		});
		result.skipped.forEach(function(entry)
		{
			var item = document.createElement('li');
			item.className = 'text-muted';
			item.appendChild(document.createTextNode('\u2014 ' + entry.path + ': ' + __t('skipped, %s', REASONS[entry.reason] || entry.reason)));
			list.appendChild(item);
		});
	}

	function send(dryRun, key, onDone)
	{
		var printerId = parseInt(document.getElementById('location-form-printer').value, 10);
		Victual.Api.Get('labels/locations/' + locationId + '/context', function(context)
		{
			Victual.Api.Post('labels/locations/' + locationId + '/print-subtree',
				{
					'import_epoch': context.import_epoch,
					'printer_id': printerId,
					'include_root': document.getElementById('location-batch-include-root').checked,
					'reprint_existing': document.getElementById('location-batch-reprint').checked,
					'dry_run': dryRun,
					'locale': document.documentElement.lang || 'en',
					'timezone': (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC'
				},
				onDone,
				function(xhr) { printButton.disabled = false; report('danger', __t('The labels were not requested: %s', refusal(xhr))); },
				key === null ? undefined : { 'Idempotency-Key': key });
		},
		function(xhr) { printButton.disabled = false; report('danger', __t('The labels were not requested: %s', refusal(xhr))); });
	}

	function forget()
	{
		planned = null;
		printButton.classList.add('d-none');
	}
	['location-batch-include-root', 'location-batch-reprint', 'location-form-printer'].forEach(function(id)
	{
		var node = document.getElementById(id);
		if (node !== null)
		{
			node.addEventListener('change', forget);
		}
	});

	document.getElementById('location-batch-preview').addEventListener('click', function()
	{
		forget();
		send(true, null, function(result)
		{
			show(result);
			if (result.queued.length === 0)
			{
				report('info', __t('Nothing to print: every location under this one is skipped.'));
				return;
			}
			planned = 'location-batch-' + locationId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
			printButton.querySelector('span').textContent = __n(result.queued.length, 'Print %s label', 'Print %s labels');
			printButton.classList.remove('d-none');
			report('info', __n(result.queued.length, '%s label would print.', '%s labels would print.') + ' ' + __n(result.skipped.length, '%s location is skipped.', '%s locations are skipped.'));
		});
	});

	printButton.addEventListener('click', function()
	{
		if (planned === null)
		{
			return;
		}
		var key = planned;
		printButton.disabled = true;
		send(false, key, function(result)
		{
			printButton.disabled = false;
			forget();
			show(result);
			report('success', __n(result.queued.length, '%s label is queued for printing.', '%s labels are queued for printing.'));
		});
	});
})();
