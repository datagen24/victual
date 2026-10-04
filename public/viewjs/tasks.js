// Powers the tasks list view (tasks.blade.php): table listing with status/category/user
// filters, marking tasks done/undone, deletion, the done-tasks toggle, and the due-today/
// due-soon/overdue summary widgets.
//
// A partial clone rather than a pure one (plan 12, Q5): it takes the shared table and
// delete-confirmation pieces and keeps its four filters and its own delete behaviour,
// which fades the row out in place rather than reloading the list.
var tasksTable = Victual.EntityList.Table('#tasks-table', {
	order: [[2, 'asc']],
	columnDefs: [
		{ 'type': 'html', 'targets': 2 }
	]
});

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	tasksTable.search(value).draw();
}, Victual.FormFocusDelay));

// Status filter dropdown (e.g. "due soon"/"overdue"), matched against the hidden
// status-info column (index 5)
$("#status-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	// Transfer CSS classes of selected element to dropdown element (for background)
	$(this).attr("class", $("#" + $(this).attr("id") + " option[value='" + value + "']").attr("class") + " form-control");

	tasksTable.column(tasksTable.colReorder.transpose(5)).search(value).draw();
});

// Assigned-user filter, matched against the user column (index 4). The selected name is
// anchored (and regex-escaped) so a user whose name is a substring of another user's name
// does not match both.
$("#user-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}
	else
	{
		value = "^" + $.fn.dataTable.util.escapeRegex(value) + "$";
	}

	tasksTable.column(tasksTable.colReorder.transpose(4)).search(value, true, false).draw();
});

// Category filter, matched against the category column (index 3)
$("#category-filter").on("change", function ()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	tasksTable.column(tasksTable.colReorder.transpose(3)).search(value).draw();
});

// Resets all filters (search/status/category/user)
$("#clear-filter-button").on("click", function ()
{
	$("#search").val("");
	$("#status-filter").val("all");
	$("#category-filter").val("all");
	$("#search").trigger("keyup");
	$("#status-filter").trigger("change");
	$("#category-filter").trigger("change");
	$("#user-filter").val("all");
	$("#show-done-tasks").trigger('checked', false);
});

// Clicking a summary widget (due today/due soon/overdue) applies that status as the filter
$(".status-filter-message").on("click", function ()
{
	var value = $(this).data("status-filter");
	$("#status-filter").val(value);
	$("#status-filter").trigger("change");
});

// Marks a task as completed (POST tasks/{id}/complete); either removes the row
// (default, done tasks hidden) or strikes it through in place (when "show done" is on)
$(document).on('click', '.do-task-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var taskId = $(e.currentTarget).attr('data-task-id');
	var taskName = $(e.currentTarget).attr('data-task-name');
	// The device's wall clock with its offset (ADR-0027 decision 2)
	var doneTime = Victual.Instant.Now();

	Victual.Api.Post('tasks/' + taskId + '/complete', { 'done_time': doneTime },
		function ()
		{
			if (!$("#show-done-tasks").is(":checked"))
			{
				animateCSS("#task-" + taskId + "-row", "fadeOut", function ()
				{
					$("#task-" + taskId + "-row").remove();
				});
			}
			else
			{
				$('#task-' + taskId + '-row').addClass("text-muted");
				$('#task-' + taskId + '-name').addClass("text-strike-through");
				$('.do-task-button[data-task-id="' + taskId + '"]').addClass("disabled");
			}

			Victual.FrontendHelpers.EndUiBusy();
			// taskName goes into a toastr message, which is rendered as HTML - escape it
			// here, at the point of use, rather than trusting the data- attribute it came
			// from (sweep finding S29)
			toastr.success(__t('Marked task %s as completed on %s', Victual.FrontendHelpers.EscapeHtml(taskName), Victual.Instant.ToDevice(doneTime)));
			RefreshContextualTimeago("#task-" + taskId + "-row");
			RefreshStatistics();
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Reverts a completed task back to open (POST tasks/{id}/undo); reloads the whole page
// since the row's markup needs to change back to its "open" form
$(document).on('click', '.undo-task-button', function (e)
{
	e.preventDefault();

	Victual.FrontendHelpers.BeginUiBusy();

	var taskId = $(e.currentTarget).attr('data-task-id');
	var taskName = $(e.currentTarget).attr('data-task-name');

	Victual.Api.Post('tasks/' + taskId + '/undo', {},
		function ()
		{
			window.location.reload();
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy();
			Victual.Api.DefaultErrorHandler(xhr);
		}
	);
});

// Deletes a task (DELETE objects/tasks/{id}) after confirmation, fading the row out.
// The shared confirmation escapes the task name into its message.
Victual.EntityList.ConfirmDelete({
	button: '.delete-task-button',
	idAttr: 'data-task-id',
	nameAttr: 'data-task-name',
	endpoint: 'objects/tasks',
	message: 'Are you sure you want to delete task "%s"?',
	after: function (objectId)
	{
		animateCSS("#task-" + objectId + "-row", "fadeOut", function ()
		{
			$("#task-" + objectId + "-row").remove();
		});
	}
});

// Toggling "show done tasks" reloads the page with/without the include_done query param
$("#show-done-tasks").change(function ()
{
	if (this.checked)
	{
		window.location.href = U('/tasks?include_done');
	}
	else
	{
		window.location.href = U('/tasks');
	}
});

// Reflect the current include_done state onto the checkbox on load
if (GetUriParam('include_done'))
{
	$("#show-done-tasks").prop('checked', true);
}

/**
 * Refreshes the due-today/due-soon/overdue summary widgets by fetching all tasks (GET
 * tasks) and bucketing them by due_date relative to today and the configured "next X
 * days" threshold. Called on load and after marking a task done.
 */
function RefreshStatistics()
{
	var nextXDays = $("#info-due-soon-tasks").data("next-x-days");
	Victual.Api.Get('tasks',
		function (result)
		{
			var dueTodayCount = 0;
			var dueSoonCount = 0;
			var overdueCount = 0;
			// due_date is a calendar date, compared with the server's today as TasksController
			// does - never converted between zones (ADR-0027 decision 2)
			var today = Victual.Instant.ServerToday();
			var lastSoonDay = moment(today, "YYYY-MM-DD").add(nextXDays, "days").format("YYYY-MM-DD");

			result.forEach(element =>
			{
				if (element.due_date)
				{
					var date = element.due_date.substring(0, 10);

					if (date < today)
					{
						overdueCount++;
					}
					else if (date == today)
					{
						dueTodayCount++;
						dueSoonCount++;
					}
					else if (date <= lastSoonDay)
					{
						dueSoonCount++;
					}
				}
			});

			$("#info-due-today-tasks").html('<span class="d-block d-md-none">' + dueTodayCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueTodayCount, '%s task is due to be done today', '%s tasks are due to be done today'));
			$("#info-due-soon-tasks").html('<span class="d-block d-md-none">' + dueSoonCount + ' <i class="fa-solid fa-clock"></i></span><span class="d-none d-md-block">' + __n(dueSoonCount, '%s task is due to be done', '%s tasks are due to be done') + ' ' + __n(nextXDays, 'within the next day', 'within the next %s days'));
			$("#info-overdue-tasks").html('<span class="d-block d-md-none">' + overdueCount + ' <i class="fa-solid fa-times-circle"></i></span><span class="d-none d-md-block">' + __n(overdueCount, '%s task is overdue to be done', '%s tasks are overdue to be done'));
		},
		function ()
		{
			// Deliberately silent: a background statistics refresh, not a user initiated
			// action - it runs on load and after every completed task, and a toast for it
			// would report a failure the user did not ask for. Plan 12, Q2.
		}
	);
}

RefreshStatistics();

// Apply filters (there are maybe some set when a task was just edited)
$("#search").trigger("keyup");
$("#status-filter").trigger("change");
$("#category-filter").trigger("change");
