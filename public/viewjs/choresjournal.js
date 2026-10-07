// View script for choresjournal.blade.php - lists past/logged chore executions in a DataTable
// with filters (chore, date range, free-text search) and lets a tracked execution be undone.
var choresJournalTable = $('#chores-journal-table').DataTable({
	'order': [[2, 'desc']],
	'columnDefs': [
		{ 'orderable': false, 'targets': 0 },
		{ 'searchable': false, "targets": 0 },
		{ 'visible': false, 'targets': 4 }
	].concat($.fn.dataTable.defaults.columnDefs)
});
$('#chores-journal-table tbody').removeClass("d-none");
choresJournalTable.columns.adjust().draw();

// Chore filter dropdown -> reflected in the "chore" URI param and applied server-side on reload
$("#chore-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		RemoveUriParam("chore");
	}
	else
	{
		UpdateUriParam("chore", value);
	}

	window.location.reload();
});

// Date range filter (in months) -> reflected in the "months" URI param and applied server-side on reload
$("#daterange-filter").on("change", function()
{
	UpdateUriParam("months", $(this).val());
	window.location.reload();
});

// Free-text search, filtered client-side against the DataTable
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	choresJournalTable.search(value).draw();
}, Victual.FormFocusDelay));

// Resets search/filters (and the "chore"/"months" URI params) and reloads the page
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#daterange-filter").val("24");
	RemoveUriParam("months");

	if (GetUriParam("embedded") === undefined)
	{
		$("#chore-filter").val("all");
		RemoveUriParam("chore");
	}

	window.location.reload();
});

// Pre-select filter controls from URI params on initial page load
if (typeof GetUriParam("chore") !== "undefined")
{
	$("#chore-filter").val(GetUriParam("chore"));
}

if (typeof GetUriParam("months") !== "undefined")
{
	$("#daterange-filter").val(GetUriParam("months"));
}

// Undoes a previously tracked chore execution (POST chores/executions/{id}/undo) and
// updates the row in place (strike-through name, "Undone on ..." note) without reloading
$(document).on('click', '.undo-chore-execution-button', function(e)
{
	e.preventDefault();

	var element = $(e.currentTarget);
	var executionId = $(e.currentTarget).attr('data-execution-id');

	Victual.Api.Post('chores/executions/' + executionId.toString() + '/undo', {},
		function(result, xhr)
		{
			element.closest("tr").addClass("text-muted");
			element.parent().siblings().find("span.name-anchor").addClass("text-strike-through").after("<br>" + __t("Undone on") + " " + moment().format("YYYY-MM-DD HH:mm:ss") + " <time class='timeago timeago-contextual' datetime='" + moment().format("YYYY-MM-DD HH:mm:ss") + "'></time>");
			element.closest(".undo-stock-booking-button").addClass("disabled");
			RefreshContextualTimeago("#chore-execution-" + executionId + "-row");
			Victual.StockDialogs.ShowUndoSuccess(__t("Chore execution successfully undone"), xhr);
		}
	);
});
