// View script for the chore create/edit form (views/choreform.blade.php):
// period/assignment type dependent UI, saves via POST /api/objects/chores or PUT /api/objects/chores/{id}
// followed by POST /api/chores/executions/calculate-next-assignments; optional product consumption on execution

// Form submit: validate, then create or update depending on Victual.EditMode; after saving
// (incl. userfields) the next chore assignments are recalculated server-side
$('#save-chore-button').on('click', function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("chore-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#chore-form').serializeJSON();
	jsonData.start_date = Victual.Components.DateTimePicker.GetValue();

	if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_CHORES_ASSIGNMENTS)
	{
		jsonData.assignment_config = $("#assignment_config").val().join(",");
	}

	Victual.FrontendHelpers.BeginUiBusy("chore-form");

	if (Victual.EditMode === 'create')
	{
		Victual.Api.Post('objects/chores', jsonData,
			function(result)
			{
				Victual.EditObjectId = result.created_object_id;
				Victual.Components.UserfieldsForm.Save(function()
				{
					Victual.Api.Post('chores/executions/calculate-next-assignments', { "chore_id": Victual.EditObjectId },
						function(result)
						{
							window.location.href = U('/chores');
						},
						function(xhr)
						{
							Victual.FrontendHelpers.EndUiBusy();
							Victual.Api.DefaultErrorHandler(xhr);
						}
					);
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("chore-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
	else
	{
		Victual.Api.Put('objects/chores/' + Victual.EditObjectId, jsonData,
			function(result)
			{
				Victual.Components.UserfieldsForm.Save(function()
				{
					Victual.Api.Post('chores/executions/calculate-next-assignments', { "chore_id": Victual.EditObjectId },
						function(result)
						{
							window.location.href = U('/chores');
						},
						function(xhr)
						{
							Victual.FrontendHelpers.EndUiBusy();
							Victual.Api.DefaultErrorHandler(xhr);
						}
					);
				});
			},
			function(xhr)
			{
				Victual.FrontendHelpers.EndUiBusy("chore-form");
				Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
			}
		);
	}
});

// Live re-validation while typing
$('#chore-form input').keyup(function(event)
{
	Victual.FrontendHelpers.ValidateForm('chore-form');
});

// Enter submits the form (when valid) instead of the browser default
$('#chore-form input').keydown(function(event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('chore-form'))
		{
			return false;
		}
		else
		{
			$('#save-chore-button').click();
		}
	}
});

// Restore the weekday checkboxes from the stored comma-separated period_config value
var checkboxValues = $("#period_config").val().split(",");
for (var i = 0; i < checkboxValues.length; i++)
{
	if (checkboxValues[i])
	{
		$("#" + checkboxValues[i]).prop('checked', true);
	}
}

// Initial setup: load userfield values, validate once and focus the name input
Victual.Components.UserfieldsForm.Load();
Victual.FrontendHelpers.ValidateForm('chore-form');
setTimeout(function()
{
	$('#name').focus();
}, Victual.FormFocusDelay);

// In edit mode the start date is locked once the chore has been executed at least once
// (checked via GET /api/objects/chores_log)
if (Victual.EditMode == "edit")
{
	Victual.Api.Get('objects/chores_log?limit=1&query[]=chore_id=' + Victual.EditObjectId,
		function(journalEntries)
		{
			if (journalEntries.length > 0)
			{
				$(".datetimepicker-input").attr("disabled", "");
			}
		}
	);
}

// Trigger the dependent-UI handlers once so the form reflects the loaded chore's settings
setTimeout(function()
{
	$(".input-group-chore-period-type").trigger("change");
	$(".input-group-chore-assignment-type").trigger("change");

	// Click twice to trigger on-click but not change the actual checked state
	$("#consume_product_on_execution").click();
	$("#consume_product_on_execution").click();

	Victual.Components.ProductPicker.GetPicker().trigger('change');
}, Victual.FormFocusDelay);

// Period type handling: show/hide the matching period inputs, keep period_config in sync
// (weekday list for weekly chores) and update the human-readable schedule explanation
$('.input-group-chore-period-type').on('change keyup', function(e)
{
	var periodType = $('#period_type').val();
	var periodDays = $('#period_days').val();
	var periodInterval = $('#period_interval').val();

	$(".period-type-input").addClass("d-none");
	$(".period-type-" + periodType).removeClass("d-none");
	$("#period_config").val("");

	if (periodType === 'manually')
	{
		$('#chore-schedule-info').text(__t('This means the next execution of this chore is not scheduled'));
		$("#period_days").val(1);
		$("#period_interval").val(1);
	}
	else if (periodType === 'hourly')
	{
		$('#chore-schedule-info').text(__n(periodInterval, "This means the next execution of this chore is scheduled %s hour after the last execution", "This means the next execution of this chore is scheduled %s hours after the last execution"));
	}
	else if (periodType === 'daily')
	{
		$('#chore-schedule-info').text(__n(periodInterval, "This means the next execution of this chore is scheduled at the same time (based on the start date) every day", "This means the next execution of this chore is scheduled at the same time (based on the start date) every %s days"));
		$("#period_days").val(1);
	}
	else if (periodType === 'weekly')
	{
		$('#chore-schedule-info').text(__n(periodInterval, "This means the next execution of this chore is scheduled every week on the selected weekdays", "This means the next execution of this chore is scheduled every %s weeks on the selected weekdays"));
		$("#period_config").val($(".period-type-weekly input:checkbox:checked").map(function() { return this.value; }).get().join(","));
		$("#period_days").val(1);
	}
	else if (periodType === 'monthly')
	{
		$('#chore-schedule-info').text(__n(periodInterval, "This means the next execution of this chore is scheduled on the selected day every month", "This means the next execution of this chore is scheduled on the selected day every %s months"));
		$("label[for='period_days']").text(__t("Day of month"));
		$("#period_days").attr("min", "1");
		$("#period_days").attr("max", "31");
	}
	else if (periodType === 'yearly')
	{
		$('#chore-schedule-info').text(__n(periodInterval, 'This means the next execution of this chore is scheduled every year on the same day (based on the start date)', 'This means the next execution of this chore is scheduled every %s years on the same day (based on the start date)'));
		$("#period_days").val(1);
	}
	else if (periodType === 'adaptive')
	{
		$('#chore-schedule-info').text(__t('This means the next execution of this chore is scheduled dynamically based on the past average execution frequency'));
		$("#period_days").val(1);
		$("#period_interval").val(1);
	}

	Victual.FrontendHelpers.ValidateForm('chore-form');
});

// Assignment type handling: enable/require the user multi-select only for types that need it
// and update the human-readable assignment explanation
$('.input-group-chore-assignment-type').on('change', function(e)
{
	var assignmentType = $('#assignment_type').val();

	$('#chore-period-assignment-info').text("");
	$("#assignment_config").removeAttr("required");
	$("#assignment_config").attr("disabled", "");

	if (assignmentType === 'no-assignment')
	{
		$('#chore-assignment-type-info').text(__t('This means the next execution of this chore will not be assigned to anyone'));
	}
	else if (assignmentType === 'who-least-did-first')
	{
		$('#chore-assignment-type-info').text(__t('This means the next execution of this chore will be assigned to the one who executed it least'));
		$("#assignment_config").attr("required", "");
		$("#assignment_config").removeAttr("disabled");
	}
	else if (assignmentType === 'random')
	{
		$('#chore-assignment-type-info').text(__t('This means the next execution of this chore will be assigned randomly'));
		$("#assignment_config").attr("required", "");
		$("#assignment_config").removeAttr("disabled");
	}
	else if (assignmentType === 'in-alphabetical-order')
	{
		$('#chore-assignment-type-info').text(__t('This means the next execution of this chore will be assigned to the next one in alphabetical order'));
		$("#assignment_config").attr("required", "");
		$("#assignment_config").removeAttr("disabled");
	}

	Victual.FrontendHelpers.ValidateForm('chore-form');
});

// Toggle the product picker + amount inputs depending on "consume product on execution"
$("#consume_product_on_execution").on("click", function()
{
	if (this.checked)
	{
		Victual.Components.ProductPicker.Enable();
		$("#product_amount").removeAttr("disabled");
	}
	else
	{
		Victual.Components.ProductPicker.Disable();
		$("#product_amount").attr("disabled", "");
	}

	Victual.FrontendHelpers.ValidateForm("chore-form");
});

// Show the stock quantity unit name next to the amount input for the selected product (GET /api/stock/products/{id})
Victual.Components.ProductPicker.GetPicker().on('change', function(e)
{
	var productId = $(e.target).val();

	if (productId)
	{
		Victual.Api.Get('stock/products/' + productId,
			function(productDetails)
			{
				$('#amount_qu_unit').text(productDetails.quantity_unit_stock.name);
			}
		);
	}
});

// The print action (views/components/label_print_widget.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'chore',
	trigger: '#chore-form-button',
	printerSelect: '#chore-form-printer',
	status: '#chore-form-status'
});
