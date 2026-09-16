// Requesting a label for a location, or (plan 32) for a product, stock entry, recipe, chore
// or battery.
//
// Shared by every page that offers this action, which must not disagree about the two rules
// that make it safe.
//
// **The import epoch.** A request carries the generation the target set was at when it was
// composed, and issuance refuses when that no longer matches. Without it a request composed
// before an import and executed after it mints a label for whatever now holds that id - a row
// that is internally consistent and is not what anybody asked for. The epoch is read
// immediately before the request rather than rendered into the page, so a page left open
// across an import fails loudly instead of quietly labelling the wrong shelf or the wrong
// product.
//
// **The idempotency key.** One key per intended action, kept across a retry and a reload, so
// a double-click or a retried request returns the first job rather than printing a second
// label. A new deliberate print gets a new key. The key travels as a header rather than in the
// body, which also keeps it out of the fingerprint the server takes of the body.
//
// AGENTS.md's two frontend sink rules hold throughout: DOM-derived strings reach jQuery via
// $(document).find(sel), and every piece of markup here is built as nodes rather than
// concatenated into .html() - a target's name is household data, and this is the path sweep
// finding S29 was about.

Victual.LabelPrinting = {};

/**
 * @param {Object} options
 * @param {string} options.status - selector for the live status region
 * @param {string} options.printerSelect - selector for the printer <select>
 * @param {string} options.trigger - selector for the print button(s)
 * @param {string} [options.within] - restricts `trigger` to inside this container (a list)
 * @param {string} [options.kind] - one of the six label kinds; defaults to 'location' so the
 *        location list and form, which predate the other five, need no change here.
 */
Victual.LabelPrinting.Wire = function (options)
{
	var statusRegion = document.querySelector(options.status);
	var printerSelect = document.querySelector(options.printerSelect);
	var kind = options.kind || 'location';

	if (statusRegion === null || printerSelect === null)
	{
		return;
	}

	// The location routes predate the other five kinds and keep their own path
	// (routes.php), rather than the generic /labels/{kind}/{id}/... pair plan 32 added.
	function contextUrl(targetId)
	{
		return kind === 'location' ? 'labels/locations/' + targetId + '/context' : 'labels/' + kind + '/' + targetId + '/context';
	}
	function printUrl(targetId)
	{
		return kind === 'location' ? 'labels/locations/' + targetId + '/print' : 'labels/' + kind + '/' + targetId + '/print';
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

	/**
	 * The server's own words, parsed out of `responseText`.
	 *
	 * The API wrapper hands an error callback the raw XMLHttpRequest, so the body is a string
	 * rather than an object. Reading it as an object finds nothing and turns every refusal -
	 * a stale epoch, an unsupported combination, a printer whose worker is inactive - into
	 * the same generic failure, which is the opposite of what those messages are for.
	 */
	function messageOf(xhr)
	{
		var body = {};
		try
		{
			body = JSON.parse((xhr && xhr.responseText) || '{}');
		}
		catch (error)
		{
			// Not JSON: a transport failure or a crash, which the fallback describes.
		}

		if (body.error_message)
		{
			return body.error_message;
		}

		return (xhr && xhr.statusText) || __t('the server did not answer');
	}

	function request(targetId, targetName)
	{
		var printerId = parseInt(printerSelect.value, 10);

		if (pendingKey === null)
		{
			pendingKey = kind + '-' + targetId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
		}

		report('info', __t('Requesting a label for %s...', targetName));

		Victual.Api.Get(contextUrl(targetId),
			function (context)
			{
				Victual.Api.Post(printUrl(targetId),
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
							report('info', __t('Label requested for %s. It prints once its image has been produced and checked.', targetName));
						}
						else
						{
							report('success', __t('Label for %s is queued for printing.', targetName));
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
			request(button.attr('data-target-id'), button.attr('data-target-name'));
		});
	}
	else
	{
		$(document).find(options.trigger).on('click', function (event)
		{
			event.preventDefault();
			var button = $(this);
			request(button.attr('data-target-id'), button.attr('data-target-name'));
		});
	}
};
