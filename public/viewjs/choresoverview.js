// View script for choresoverview.blade.php - shows all chores with due dates in a DataTable,
// supports quick tracking/skipping/rescheduling of a chore and rendering the "due" status stats.
var choresOverviewTable = $('#chores-overview-table').DataTable({
	'order': [[2, 'asc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ "type": "html", "targets": 5 },
		{ "type": "html", "targets": 2 },
		{ "type": "html", "targets": 3 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#chores-overview-table tbody').removeClass("d-none");
choresOverviewTable.columns.adjust().draw();

// Free-text search, filtered client-side against the DataTable
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	choresOverviewTable.search(value).draw();
}, Victual.FormFocusDelay));

// Status filter (overdue/due today/due soon/...) - filters the hidden status column (index 5)
$("#status-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	choresOverviewTable.column(choresOverviewTable.colReorder.transpose(5)).search(value).draw();
});

// "Assigned to" user filter - filters the hidden user column (index 6) and reflects it in the "user" URI param
$("#user-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	choresOverviewTable.column(choresOverviewTable.colReorder.transpose(6)).search(value).draw();

	if (value)
	{
		UpdateUriParam("user", $("#user-filter option:selected").data("user-id"));
	}
});

// Resets search/status/user filters (and the "user" URI param)
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#user-filter").val("all");
	choresOverviewTable.column(choresOverviewTable.colReorder.transpose(5)).search("").draw();
	choresOverviewTable.column(choresOverviewTable.colReorder.transpose(6)).search("").draw();
	choresOverviewTable.search("").draw();
	RemoveUriParam("user");
});

// Clicking a "due soon"/"overdue"/... info badge applies the matching status filter
$(".status-filter-message").on("click", function()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Clicking the "assigned to me" info badge applies the matching user filter
$(".user-filter-message").on("click", function()
{
	var value = $(this).data("user-filter");
	$("#user-filter").val(value);
	$("#user-filter").trigger("change");
});

// Inline "track"/"skip" button on a chore row: determines the tracked time (now, or the chore's
// next estimated execution time when tracking in the past / skipping), posts the execution via
// GET chores/{id} + POST chores/{id}/execute, then updates the row's due-state styling and
// timestamps in place instead of reloading the whole table.
$(document).on('click', '.track-chore-button', function(e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var choreId = $(e.currentTarget).attr('data-chore-id');
	var choreName = $(e.currentTarget).attr('data-chore-name');
	var skipped = $(e.currentTarget).hasClass("skip");
	var now = $(e.currentTarget).hasClass("now");

	Victual.Api.Get('chores/' + choreId,
		function(choreDetails)
		{
			var trackedTime = moment().format('YYYY-MM-DD HH:mm:ss');
			if ((skipped || !now) && choreDetails.next_estimated_execution_time != null)
			{
				trackedTime = moment(choreDetails.next_estimated_execution_time).format('YYYY-MM-DD HH:mm:ss');
			}

			if (choreDetails.chore.track_date_only == 1)
			{
				if ((skipped || !now) && choreDetails.next_estimated_execution_time != null)
				{
					trackedTime = moment(choreDetails.next_estimated_execution_time).format('YYYY-MM-DD');
				}
				else
				{
					trackedTime = moment().format('YYYY-MM-DD');
				}
			}

			Victual.Api.Post('chores/' + choreId + '/execute', { 'tracked_time': trackedTime, 'skipped': skipped },
				function()
				{
					// Re-fetch the chore to get its newly calculated next execution time/assignee
					Victual.Api.Get('chores/' + choreId,
						function(result)
						{
							var choreRow = $('#chore-' + choreId + '-row');
							var nextXDaysThreshold = moment().add($("#info-due-soon-chores").data("next-x-days"), "days");
							var todayThreshold = moment().endOf("day");
							var now = moment();
							var nextExecutionTime = moment(result.next_estimated_execution_time);

							choreRow.removeClass("table-warning");
							choreRow.removeClass("table-danger");
							choreRow.removeClass("table-info");
							$('#chore-' + choreId + '-due-filter-column').html("");
							if (nextExecutionTime.isBefore(now))
							{
								choreRow.addClass("table-danger");
								$('#chore-' + choreId + '-due-filter-column').html("overdue");
							}
							else if (nextExecutionTime.isSameOrBefore(todayThreshold))
							{
								choreRow.addClass("table-info");
								$('#chore-' + choreId + '-due-filter-column').html("duetoday");
							}
							else if (nextExecutionTime.isBefore(nextXDaysThreshold))
							{
								choreRow.addClass("table-warning");
								$('#chore-' + choreId + '-due-filter-column').html("duesoon");
							}

							animateCSS("#chore-" + choreId + "-row td:not(:first)", "flash");

							$('#chore-' + choreId + '-last-tracked-time').text(trackedTime);
							$('#chore-' + choreId + '-last-tracked-time-timeago').attr('datetime', trackedTime);

							if (result.next_estimated_execution_time)
							{
								$('#chore-' + choreId + '-next-execution-time').text(result.next_estimated_execution_time);
								$('#chore-' + choreId + '-next-execution-time-timeago').attr('datetime', result.next_estimated_execution_time);
							}
							else
							{
								$('#chore-' + choreId + '-next-execution-time').text("-");
								$('#chore-' + choreId + '-next-execution-time-timeago').removeAttr('datetime');
							}

							if (result.chore.next_execution_assigned_to_user_id != null)
							{
								$('#chore-' + choreId + '-next-execution-assigned-user').text(result.next_execution_assigned_user.display_name);
							}
							else
							{
								$('#chore-' + choreId + '-next-execution-assigned-user').text("-");
							}

							$('#chore-' + choreId + '-rescheduled-icon').remove();
							$('#chore-' + choreId + '-reassigned-icon').remove();

							Victual.FrontendHelpers.EndUiBusy();
						// choreName came from a data- attribute read back with .attr(), which returns
						// the decoded string, and this toastr message is rendered as HTML - so it is
						// escaped here, at the point of use (sweep finding S29).
						toastr.success(__t('Tracked execution of chore %1$s on %2$s', Victual.FrontendHelpers.EscapeHtml(choreName), trackedTime));
							RefreshStatistics();

							// Delay due to delayed/animated set of new timestamps above; re-applies the
							// status filters afterwards since the row's due-status column changed
							setTimeout(function()
							{
								RefreshContextualTimeago("#chore-" + choreId + "-row");

								// Refresh the DataTable to re-apply filters
								choresOverviewTable.rows().invalidate().draw(false);
								$(".input-group-filter").trigger("change");
							}, Victual.FormFocusDelay);
						},
						function(xhr)
						{
							Victual.FrontendHelpers.EndUiBusy();
							Victual.Api.DefaultErrorHandler(xhr);
						}
					);
				},
				function(xhr)
				{
					Victual.FrontendHelpers.EndUiBusy();
					Victual.Api.DefaultErrorHandler(xhr);
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("choretracking-form");
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// The print action (views/components/label_print_list_header.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'chore',
	trigger: '.chore-label-print',
	within: '#chores-overview-table',
	printerSelect: '#choresoverview-printer',
	status: '#choresoverview-status'
});

/**
 * Recomputes the due-today/due-soon/overdue/assigned-to-me counters shown above the table
 * by fetching all chores (GET chores) and re-evaluating their next_estimated_execution_time.
 */
function RefreshStatistics()
{
	var nextXDays = $("#info-due-soon-chores").data("next-x-days");
	Victual.Api.Get('chores',
		function(result)
		{
			var dueTodayCount = 0;
			var dueSoonCount = 0;
			var overdueCount = 0;
			var assignedToMeCount = 0;
			var overdueThreshold = moment();
			var nextXDaysThreshold = moment().add(nextXDays, "days");
			var todayThreshold = moment().endOf("day");

			result.forEach(element =>
			{
				var date = moment(element.next_estimated_execution_time);

				if (date.isBefore(overdueThreshold))
				{
					overdueCount++;
				}
				else if (date.isSameOrBefore(todayThreshold))
				{
					dueTodayCount++;
					dueSoonCount++;
				}
				else if (date.isSameOrBefore(nextXDaysThreshold))
				{
					dueSoonCount++;
				}

				if (element.next_execution_assigned_to_user_id == Victual.UserId)
				{
					assignedToMeCount++;
				}
			});

			$("#info-due-today-chores").html('<span class="d-block d-md-none">' + dueTodayCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueTodayCount, '%s chore is due to be done today', '%s chores are due to be done today'));
			$("#info-due-soon-chores").html('<span class="d-block d-md-none">' + dueSoonCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueSoonCount, '%s chore is due to be done', '%s chores are due to be done') + ' ' + __n(nextXDays, 'within the next day', 'within the next %s days'));
			$("#info-overdue-chores").html('<span class="d-block d-md-none">' + overdueCount + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(overdueCount, '%s chore is overdue to be done', '%s chores are overdue to be done'));
			$("#info-assigned-to-me-chores").html('<span class="d-block d-md-none">' + assignedToMeCount + ' <i class="fa-solid fa-exclamation-circle"></i></span><span class="d-none d-md-block">' + __n(assignedToMeCount, '%s chore is assigned to me', '%s chores are assigned to me'));
		},
		function()
		{
			// Deliberately silent: a background statistics refresh, not a user initiated
			// action - it runs on load and after every tracked execution, and a toast for
			// it would report a failure the user did not ask for. Plan 12, Q2.
		}
	);
}

// Opens the reschedule modal for a chore, prefilling the DateTimePicker and UserPicker
// components with the chore's current (rescheduled or estimated) next execution
$(document).on("click", ".reschedule-chore-button", function(e)
{
	e.preventDefault();

	var choreId = $(e.currentTarget).attr("data-chore-id");
	Victual.EditObjectId = choreId;
	Victual.Api.Get("chores/" + choreId, function(choreDetails)
	{
		var prefillDate = choreDetails.next_estimated_execution_time || moment().format("YYYY-MM-DD HH:mm:ss");
		if (choreDetails.chore.rescheduled_date)
		{
			prefillDate = choreDetails.chore.rescheduled_date;
		}

		if (choreDetails.chore.track_date_only == 1)
		{
			Victual.Components.DateTimePicker.ChangeFormat("YYYY-MM-DD");
			Victual.Components.DateTimePicker.SetValue(moment(prefillDate).format("YYYY-MM-DD"));
		}
		else
		{
			Victual.Components.DateTimePicker.ChangeFormat("YYYY-MM-DD HH:mm:ss");
			Victual.Components.DateTimePicker.SetValue(moment(prefillDate).format("YYYY-MM-DD HH:mm:ss"));
		}

		if (typeof choreDetails.chore.next_execution_assigned_to_user_id != "string")
		{
			choreDetails.chore.next_execution_assigned_to_user_id = "";
		}
		if (choreDetails.chore.next_execution_assigned_to_user_id)
		{
			Victual.Components.UserPicker.SetId(choreDetails.chore.next_execution_assigned_to_user_id)
		}
		else
		{
			Victual.Components.UserPicker.SetValue("");
			Victual.Components.UserPicker.SetId(null);
		}

		$("#reschedule-chore-modal-title").text(choreDetails.chore.name);
		$("#reschedule-chore-modal").modal("show");
	});
});

// Saves the reschedule modal: persists rescheduled_date/rescheduled_next_execution_assigned_to_user_id
// on the chore, then recalculates chore assignments and reloads the page
$("#reschedule-chore-save-button").on("click", function(e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("reschedule-chore-form", true))
	{
		return;
	}

	Victual.Api.Put('objects/chores/' + Victual.EditObjectId, { "rescheduled_date": Victual.Components.DateTimePicker.GetValue(), "rescheduled_next_execution_assigned_to_user_id": Victual.Components.UserPicker.GetValue() },
		function(result)
		{
			Victual.Api.Post('chores/executions/calculate-next-assignments', { "chore_id": Victual.EditObjectId },
				function(result)
				{
					window.location.reload();
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

// Clears a previously set reschedule on the chore, reverting to the normal schedule
$("#reschedule-chore-clear-button").on("click", function(e)
{
	e.preventDefault();

	Victual.Api.Put('objects/chores/' + Victual.EditObjectId, { "rescheduled_date": null, "rescheduled_next_execution_assigned_to_user_id": null },
		function(result)
		{
			Victual.Api.Post('chores/executions/calculate-next-assignments', { "chore_id": Victual.EditObjectId },
				function(result)
				{
					window.location.reload();
				}
			);
		},
		function(xhr)
		{
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this item already exists', xhr.response);
		}
	);
});

// Pre-select the user filter from the "user" URI param on initial page load
if (GetUriParam("user") !== undefined)
{
	$("#user-filter").val("xx" + GetUriParam("user") + "xx");
	$("#user-filter").trigger("change");
}

RefreshStatistics();
