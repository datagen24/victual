// View script for choretracking.blade.php - the quick chore-tracking form (chore picker,
// DateTimePicker, done-by user, optional skip) used to log a chore execution.
// Saves (or skips) an execution: GET chores/{id} for the chore name, then
// POST chores/{id}/execute, then persists any userfields, resets the form and refocuses it.
$('.save-choretracking-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("choretracking-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var skipped = $(e.currentTarget).hasClass("skip");

	var jsonForm = $('#choretracking-form').serializeJSON();
	Victual.FrontendHelpers.BeginUiBusy("choretracking-form");

	// The device's wall clock with its offset, or a bare date for a date-only chore
	// (ADR-0027 decision 2). The picker's own validity refuses a skipped wall clock.
	var trackedTime = Victual.Components.DateTimePicker.GetInstant();

	Victual.Api.Get('chores/' + jsonForm.chore_id,
		function (choreDetails)
		{
			Victual.Api.Post('chores/' + jsonForm.chore_id + '/execute', { 'tracked_time': trackedTime, 'done_by': $("#user_id").val(), 'skipped': skipped },
				function (result)
				{
					Victual.EditObjectId = result.id;
					Victual.Components.UserfieldsForm.Save(function ()
					{
						Victual.FrontendHelpers.EndUiBusy("choretracking-form");
					// The chore name is a text column and this toastr message is rendered as
					// HTML - escaped at the point of use. The Undo anchor after it is
					// deliberate markup (sweep finding S29).
					toastr.success(__t('Tracked execution of chore %1$s on %2$s', Victual.FrontendHelpers.EscapeHtml(choreDetails.chore.name), Victual.Components.DateTimePicker.GetValue()) + '<br><a class="btn btn-secondary btn-sm mt-2" href="#" onclick="UndoChoreExecution(' + result.id + ')"><i class="fa-solid fa-undo"></i> ' + __t("Undo") + '</a>');
						Victual.Components.ChoreCard.Refresh($('#chore_id').val());

						$('#chore_id').val('');
						$('#chore_id_text_input').focus();
						$('#chore_id_text_input').val('');
						Victual.Components.DateTimePicker.SetValue(moment().format('YYYY-MM-DD HH:mm:ss'));
						$('#chore_id_text_input').trigger('change');
						Victual.FrontendHelpers.ValidateForm('choretracking-form');
					});
				},
				function (xhr)
				{
					Victual.FrontendHelpers.EndUiBusy("choretracking-form");
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("choretracking-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// When a chore is selected: adjusts the DateTimePicker format/value for date-only chores,
// enables/disables the "skip" button for manually-triggered chores, refreshes the ChoreCard
// preview and focuses the next input
$('#chore_id').on('change', function (e)
{
	var input = $('#chore_id_text_input').val().toString();
	$('#chore_id_text_input').val(input);
	$('#chore_id').data('combobox').refresh();

	var choreId = $(e.target).val();
	if (choreId)
	{
		Victual.Api.Get('objects/chores/' + choreId,
			function (chore)
			{

				if (chore.track_date_only == 1)
				{
					Victual.Components.DateTimePicker.ChangeFormat("YYYY-MM-DD");
					Victual.Components.DateTimePicker.SetValue(moment().format("YYYY-MM-DD"));
				}
				else
				{
					Victual.Components.DateTimePicker.ChangeFormat("YYYY-MM-DD HH:mm:ss");
					Victual.Components.DateTimePicker.SetValue(moment().format("YYYY-MM-DD HH:mm:ss"));
				}

				if (chore.period_type == "manually")
				{
					$(".save-choretracking-button.skip").addClass("disabled");
				}
				else
				{
					$(".save-choretracking-button.skip").removeClass("disabled");
				}

				Victual.FrontendHelpers.ValidateForm('choretracking-form');
			}
		);

		Victual.Components.ChoreCard.Refresh(choreId);

		setTimeout(function ()
		{
			Victual.Components.DateTimePicker.GetInputElement().focus();
		}, Victual.FormFocusDelay);

		Victual.FrontendHelpers.ValidateForm('choretracking-form');
	}
});

$(".combobox").combobox(BootstrapComboboxDefaults);

$('#chore_id_text_input').trigger('change');
Victual.Components.DateTimePicker.GetInputElement().trigger('input');
Victual.FrontendHelpers.ValidateForm('choretracking-form');
setTimeout(function ()
{
	$('#chore_id_text_input').focus();
}, Victual.FormFocusDelay);

$('#choretracking-form input').keyup(function (event)
{
	Victual.FrontendHelpers.ValidateForm('choretracking-form');
});

$('#choretracking-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('choretracking-form'))
		{
			return false;
		}
		else
		{
			$('.save-choretracking-button').first().click();
		}
	}
});

// Handles a scanned Grocycode/barcode targeted at the chore picker (from CameraBarcodeScanner
// or an external scanner), routing it into the chore_id text input as if typed
$(document).on("Victual.BarcodeScanned", function (e, barcode, target)
{
	if (!(target == "@chorepicker" || target == "undefined" || target == undefined)) // Default target
	{
		return;
	}

	// Don't know why the blur event does not fire immediately ... this works...
	$("#chore_id_text_input").focusout();
	$("#chore_id_text_input").focus();
	$("#chore_id_text_input").blur();

	$("#chore_id_text_input").val(barcode);

	setTimeout(function ()
	{
		$("#chore_id_text_input").focusout();
		$("#chore_id_text_input").focus();
		$("#chore_id_text_input").blur();
		$('#tracked_time').find('input').focus();
	}, Victual.FormFocusDelay);
});

Victual.Components.DateTimePicker.GetInputElement().on('keypress', function (e)
{
	Victual.FrontendHelpers.ValidateForm('choretracking-form');
});

/**
 * Undoes a tracked chore execution (POST chores/executions/{id}/undo). Called from the
 * "Undo" link in the success toast shown after saving.
 */
function UndoChoreExecution(executionId)
{
	Victual.Api.Post('chores/executions/' + executionId.toString() + '/undo', {},
		function (result, xhr)
		{
			Victual.StockDialogs.ShowUndoSuccess(__t("Chore execution successfully undone"), xhr);
		}
	);
};

// Resolves the typed value against Grocycodes (grcy:c:<id>) when the chore field loses focus;
// selects the matching chore option or clears the field if nothing matches
$('#chore_id_text_input').on('blur', function (e)
{
	if ($('#chore_id').hasClass("combobox-menu-visible"))
	{
		return;
	}

	var input = $('#chore_id_text_input').val().toString();
	var possibleOptionElement = [];

	// Grocycode handling
	if (input.startsWith("grcy"))
	{
		var gc = input.split(":");
		if (gc[1] == "c")
		{
			possibleOptionElement = $("#chore_id option[value=\"" + gc[2] + "\"]").first();
		}

		if (possibleOptionElement.length > 0)
		{
			$('#chore_id').val(possibleOptionElement.val());
			$('#chore_id').data('combobox').refresh();
			$('#chore_id').trigger('change');
		}
		else
		{
			$('#chore_id').val(null);
			$('#chore_id_text_input').val("");
			$('#chore_id').data('combobox').refresh();
			$('#chore_id').trigger('change');
		}
	}
});

$("#tracked_time").find("input").on("focus", function (e)
{
	$(this).select();
});
