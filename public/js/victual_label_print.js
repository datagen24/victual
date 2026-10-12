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
// The server binds a key to a fingerprint of route, arguments and body (printer included), so
// "one action" means one (kind, target, printer): a key reused for another target or printer
// is answered 409 idempotency_conflict, and would be on every click after. Pending keys are
// therefore held per (kind, target, printer) and kept in sessionStorage, which is what lets a
// reload after a lost response retry with the key the first attempt used.
// Changing the printer does not clear anything: each printer has its own slot, and a slot whose
// response was lost must survive a trip to another printer and back, or that retry prints twice.
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

	// Pending keys by 'kind|target|printer'. sessionStorage is tab-scoped, so a key outlives a
	// reload but not the tab. memoryKeys always holds the latest map. Once a write has thrown
	// (quota, old Safari private mode: getItem works, setItem throws) storage is stale, so
	// storageBroken makes memoryKeys authoritative; otherwise every read would see {} and every
	// click would mint a fresh key. In that state only a reload loses the keys.
	var STORAGE_NAME = 'victual.labelprint.pendingKeys';
	var memoryKeys = {};
	var storageBroken = false;

	function readKeys()
	{
		if (storageBroken)
		{
			return memoryKeys;
		}

		try
		{
			var stored = JSON.parse(window.sessionStorage.getItem(STORAGE_NAME) || '{}');
			return (stored !== null && typeof stored === 'object') ? stored : {};
		}
		catch (error)
		{
			return memoryKeys;
		}
	}

	function writeKeys(keys)
	{
		memoryKeys = keys;
		try
		{
			window.sessionStorage.setItem(STORAGE_NAME, JSON.stringify(keys));
		}
		catch (error)
		{
			storageBroken = true;
		}
	}

	function slotOf(targetId, printerId)
	{
		return kind + '|' + targetId + '|' + printerId;
	}

	function keyFor(targetId, printerId)
	{
		var keys = readKeys();
		var slot = slotOf(targetId, printerId);

		if (typeof keys[slot] !== 'string')
		{
			keys[slot] = kind + '-' + targetId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
			writeKeys(keys);
		}

		return keys[slot];
	}

	function spendKey(targetId, printerId)
	{
		var keys = readKeys();
		delete keys[slotOf(targetId, printerId)];
		writeKeys(keys);
	}

	// Whether a refusal says the request was understood and will never succeed as sent, so its
	// key is finished. 408 and 429 are 4xx but say "try again"; 409 is handled by the caller.
	function isDefinitiveRefusal(xhr)
	{
		var status = (xhr && xhr.status) || 0;
		return status >= 400 && status < 500 && status !== 408 && status !== 409 && status !== 429;
	}

	function codeOf(xhr)
	{
		try
		{
			return JSON.parse((xhr && xhr.responseText) || '{}').code || '';
		}
		catch (error)
		{
			return '';
		}
	}

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

		var key = keyFor(targetId, printerId);

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
						spendKey(targetId, printerId);

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
						if (xhr && xhr.status === 409 && codeOf(xhr) === 'idempotency_conflict')
						{
							// The server holds this key for a different request. Resending it
							// would be refused the same way, so it is dropped; the user's next
							// click starts afresh rather than the page doing it unasked.
							spendKey(targetId, printerId);
							report('danger', __t('The label was not requested: %s', __t('this request conflicts with an earlier one. Try again.')));
							return;
						}

						if (isDefinitiveRefusal(xhr))
						{
							// Refused outright, so nothing was created and the intention is
							// over; a corrected retry is a new one.
							spendKey(targetId, printerId);
						}

						// Otherwise the outcome is unknown (a lost response, a timeout, a 5xx,
						// an in-progress original) and the first attempt may have committed.
						// The key is kept so a retry returns that job instead of printing again.
						report('danger', __t('The label was not requested: %s', messageOf(xhr)));
					},
					{ 'Idempotency-Key': key });
			},
			function (xhr)
			{
				// The context read creates nothing and carries no key. Any key kept from an
				// earlier lost response stays: the server decides on retry whether it was spent.
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
