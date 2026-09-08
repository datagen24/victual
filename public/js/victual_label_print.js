// Requesting a location label.
//
// Shared by the locations list and the location form, which offer the same action and must
// not disagree about the two rules that make it safe.
//
// **The import epoch.** A request carries the generation the location set was at when it was
// composed, and issuance refuses when that no longer matches. Without it a request composed
// before an import and executed after it mints a label for whatever now holds that id - a row
// that is internally consistent and is not what anybody asked for. The epoch is read
// immediately before the request rather than rendered into the page, so a page left open
// across an import fails loudly instead of quietly labelling the wrong shelf.
//
// **The idempotency key.** One key per intended action, kept across a retry and a reload, so
// a double-click or a retried request returns the first job rather than printing a second
// label. A new deliberate print gets a new key. The key travels as a header rather than in the
// body, which also keeps it out of the fingerprint the server takes of the body.
//
// AGENTS.md's two frontend sink rules hold throughout: DOM-derived strings reach jQuery via
// $(document).find(sel), and every piece of markup here is built as nodes rather than
// concatenated into .html() - a location name is household data, and this is the path sweep
// finding S29 was about.

Victual.LabelPrinting = {};

Victual.LabelPrinting.Wire = function (options)
{
	var statusRegion = document.querySelector(options.status);
	var printerSelect = document.querySelector(options.printerSelect);

	if (statusRegion === null || printerSelect === null)
	{
		return;
	}

	var pendingKey = null;

	function report(kind, text)
	{
		while (statusRegion.firstChild !== null)
		{
			statusRegion.removeChild(statusRegion.firstChild);
		}

		var box = document.createElement('div');
		box.className = 'alert alert-' + kind + ' mb-0';
		box.appendChild(document.createTextNode(text));
		statusRegion.appendChild(box);
	}

	function messageOf(xhr)
	{
		if (xhr && xhr.response && xhr.response.error_message)
		{
			return xhr.response.error_message;
		}

		return (xhr && xhr.statusText) || __t('the server did not answer');
	}

	function request(locationId, locationName)
	{
		var printerId = parseInt(printerSelect.value, 10);

		if (pendingKey === null)
		{
			pendingKey = 'loc-' + locationId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
		}

		report('info', __t('Requesting a label for %s...', locationName));

		Victual.Api.Get('labels/locations/' + locationId + '/context',
			function (context)
			{
				Victual.Api.Post('labels/locations/' + locationId + '/print',
					{
						'import_epoch': context.import_epoch,
						'printer_id': printerId,
						'locale': document.documentElement.lang || 'en',
						'timezone': (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC'
					},
					function (job)
					{
						// The action happened, so the key is spent: a later click is a second
						// deliberate print and gets a key of its own.
						pendingKey = null;

						if (job.state === 'awaiting_artifact')
						{
							report('info', __t('Label requested for %s. It prints once its image has been produced and checked.', locationName));
						}
						else
						{
							report('success', __t('Label for %s is queued for printing.', locationName));
						}
					},
					function (xhr)
					{
						// The key is kept. Nothing was created, so a retry is the same
						// intention rather than a new one.
						report('danger', __t('The label was not requested: %s', messageOf(xhr)));
					},
					{ 'Idempotency-Key': pendingKey });
			},
			function (xhr)
			{
				pendingKey = null;
				report('danger', __t('The label was not requested: %s', messageOf(xhr)));
			});
	}

	if (options.within)
	{
		$(document).find(options.within).on('click', options.trigger, function (event)
		{
			event.preventDefault();
			var button = $(this);
			request(button.attr('data-location-id'), button.attr('data-location-name'));
		});
	}
	else
	{
		$(document).find(options.trigger).on('click', function (event)
		{
			event.preventDefault();
			var button = $(this);
			request(button.attr('data-location-id'), button.attr('data-location-name'));
		});
	}
};
