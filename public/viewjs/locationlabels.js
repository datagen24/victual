// One scan, one result. No selected location or booking state is persisted.
(function ()
{
	var generation = 0;
	var input = $('#location-label-code');
	var status = $('#location-label-status');
	var name = $('#location-label-name');

	function ClearResult()
	{
		generation++;
		status.text('');
		name.text('');
	}

	function Resolve()
	{
		ClearResult();
		var code = input.val().trim();
		if (code.length === 0) return;
		var requestGeneration = generation;
		status.text(__t('Looking up label…'));
		Victual.Api.Get('labels/resolve/' + encodeURIComponent(code), function (result)
		{
			// A slow response must not replace a newer scan (or an edited input).
			if (requestGeneration !== generation) return;
			if (result.status === 'resolved' && result.kind === 'location')
			{
				status.text(__t('Location found'));
				name.text(result.target.name);
			}
			else if (result.status === 'retired' && result.kind === 'location')
			{
				status.text(__t('Retired label — this location was deleted.'));
				name.text(result.snapshot.name);
			}
			else
			{
				status.text(__t('Unknown location label'));
			}
		}, function ()
		{
			if (requestGeneration !== generation) return;
			status.text(__t('Could not look up the label. Try again.'));
		});
	}

	input.on('input', ClearResult);
	$('#location-label-form').on('submit', function (event)
	{
		event.preventDefault();
		Resolve();
	});
	$(document).on('Victual.BarcodeScanned', function (event, code, target)
	{
		if (target !== '#location-label-code') return;
		input.val(code);
		Resolve();
	});
	input.trigger('focus');
})();
