// View script for the battery tracking page (views/batterytracking.blade.php):
// battery combobox + tracked time picker, submit via POST /api/batteries/{id}/charge,
// battery card refresh, barcode scanner / grocycode support

// Form submit: fetch battery details (GET /api/batteries/{id}) for the success message,
// track the charge cycle via POST /api/batteries/{id}/charge, save userfields and reset the form
$('#save-batterytracking-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("batterytracking-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonForm = $('#batterytracking-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("batterytracking-form");

	// The device's wall clock with its offset (ADR-0027 decision 2). The picker's own
	// validity refuses a skipped wall clock.
	var trackedTime = Victual.Components.DateTimePicker.GetInstant();

	Victual.Api.Get('batteries/' + jsonForm.battery_id,
		function (batteryDetails)
		{
			Victual.Api.Post('batteries/' + jsonForm.battery_id + '/charge', { 'tracked_time': trackedTime },
				function (result)
				{
					Victual.EditObjectId = result.id;
					Victual.Components.UserfieldsForm.Save(function ()
					{
						Victual.FrontendHelpers.EndUiBusy("batterytracking-form");
					// The battery name is a text column and this toastr message is rendered as
					// HTML - escaped at the point of use. The Undo anchor after it is
					// deliberate markup (sweep finding S29).
					toastr.success(__t('Tracked charge cycle of battery %1$s on %2$s', Victual.FrontendHelpers.EscapeHtml(batteryDetails.battery.name), $('#tracked_time').find('input').val()) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoChargeCycle(' + result.id + ')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>');
						Victual.Components.BatteryCard.Refresh($('#battery_id').val());

						$('#battery_id').val('');
						$('#battery_id_text_input').focus();
						$('#battery_id_text_input').val('');
						$('#tracked_time').find('input').val(moment().format('YYYY-MM-DD HH:mm:ss'));
						$('#battery_id_text_input').trigger('change');
						Victual.FrontendHelpers.ValidateForm('batterytracking-form');
					});
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("batterytracking-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("batterytracking-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// When a battery is selected, refresh the battery info card and move focus to the tracked time input
$('#battery_id').on('change', function (e)
{
	var input = $('#battery_id_text_input').val().toString();
	$('#battery_id_text_input').val(input);
	$('#battery_id').data('combobox').refresh();

	var batteryId = $(e.target).val();
	if (batteryId)
	{
		Victual.Components.BatteryCard.Refresh(batteryId);

		setTimeout(function ()
		{
			$('#tracked_time').find('input').focus();
		}, Victual.FormFocusDelay);

		Victual.FrontendHelpers.ValidateForm('batterytracking-form');
	}
});

// Init the battery combobox and reset the form to a clean state with focus on the battery input
$(".combobox").combobox(BootstrapComboboxDefaults);

$('#battery_id').val('');
$('#battery_id_text_input').val('');
$('#battery_id_text_input').trigger('change');
Victual.Components.DateTimePicker.GetInputElement().trigger('input');
Victual.FrontendHelpers.ValidateForm('batterytracking-form');
setTimeout(function ()
{
	$('#battery_id_text_input').focus();
}, Victual.FormFocusDelay);

// Live re-validation while typing
$('#batterytracking-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('batterytracking-form');
});

// Enter submits the form (when valid) instead of the browser default
$('#batterytracking-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('batterytracking-form'))
		{
			return false;
		}
		else
		{
			$('#save-batterytracking-button').click();
		}
	}
});

$('#tracked_time').find('input').on('keypress', function (e)
{
	Victual.FrontendHelpers.ValidateForm('batterytracking-form');
});

// Barcode scanner hook: put the scanned code into the battery text input and let the
// blur handler below resolve it (e.g. a battery grocycode)
$(document).on("Victual.BarcodeScanned", function (e, barcode, target)
{
	if (!(target == "@batterypicker" || target == "undefined" || target == undefined)) // Default target
	{
		return;
	}

	// Don't know why the blur event does not fire immediately ... this works...
	$("#battery_id_text_input").focusout();
	$("#battery_id_text_input").focus();
	$("#battery_id_text_input").blur();

	$("#battery_id_text_input").val(barcode);

	setTimeout(function ()
	{
		$("#battery_id_text_input").focusout();
		$("#battery_id_text_input").focus();
		$("#battery_id_text_input").blur();
		$('#tracked_time').find('input').focus();
	}, Victual.FormFocusDelay);
});

/**
 * Undoes a tracked charge cycle via POST /api/batteries/charge-cycles/{id}/undo
 * (called from the inline "Undo" link in the success toast).
 * @param {number} chargeCycleId Id of the charge cycle log entry to undo
 */
function UndoChargeCycle(chargeCycleId)
{
	Victual.Api.Post('batteries/charge-cycles/' + chargeCycleId.toString() + '/undo', {},
		function (result)
		{
			toastr.success(__t("Charge cycle successfully undone"));
		}
	);
};

// On blur, resolve a battery grocycode ("grcy:b:{id}") entered/scanned into the text input to the matching battery option
$('#battery_id_text_input').on('blur', function (e)
{
	if ($('#battery_id').hasClass("combobox-menu-visible"))
	{
		return;
	}

	var input = $('#battery_id_text_input').val().toString();
	var possibleOptionElement = [];

	// Grocycode handling
	if (input.startsWith("grcy"))
	{
		var gc = input.split(":");
		if (gc[1] == "b")
		{
			possibleOptionElement = $("#battery_id option[value=\"" + gc[2] + "\"]").first();
		}


		if (possibleOptionElement.length > 0)
		{
			$('#battery_id').val(possibleOptionElement.val());
			$('#battery_id').data('combobox').refresh();
			$('#battery_id').trigger('change');
		}
		else
		{
			$('#battery_id').val(null);
			$('#battery_id_text_input').val("");
			$('#battery_id').data('combobox').refresh();
			$('#battery_id').trigger('change');
		}
	}
});

// Select the whole tracked time value on focus for quick overwriting
$("#tracked_time").find("input").on("focus", function (e)
{
	$(this).select();
});
