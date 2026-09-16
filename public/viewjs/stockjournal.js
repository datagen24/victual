// Powers the stock journal view (stockjournal.blade.php): lists all stock transactions
// with product/type/location/user/date-range filters, and lets the user undo a booking
// or print a product's Grocycode label. Product and date-range filters reload the page
// (via URI params, since they affect the server-side query); the rest filter client-side.
//
// A partial clone rather than a pure one (plan 12, Q5): it takes the shared table piece
// and keeps its five filters and the undo action, none of which any other list has.
var stockJournalTable = Victual.EntityList.Table('#stock-journal-table', {
	order: [[3, 'desc']]
});

// Product filter is server-side (the journal is queried per-product), so it updates the
// "product" URI param and reloads
$("#product-filter").on("change", function()
{
	var value = $(this).val();
	if (value === "all")
	{
		RemoveUriParam("product");
	}
	else
	{
		UpdateUriParam("product", value);
	}

	window.location.reload();
});

// Transaction type filter, matched against the type column (index 4), client-side
$("#transaction-type-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#transaction-type-filter option:selected").text();
	if (value === "all")
	{
		text = "";
	}

	stockJournalTable.column(stockJournalTable.colReorder.transpose(4)).search(text).draw();
});

// Location filter, matched against the location column (index 5), client-side
$("#location-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#location-filter option:selected").text();
	if (value === "all")
	{
		text = "";
	}

	stockJournalTable.column(stockJournalTable.colReorder.transpose(5)).search(text).draw();
});

// User filter, matched against the user column (index 6), client-side
$("#user-filter").on("change", function()
{
	var value = $(this).val();
	var text = $("#user-filter option:selected").text();
	if (value === "all")
	{
		text = "";
	}

	stockJournalTable.column(stockJournalTable.colReorder.transpose(6)).search(text).draw();
});

// Date range filter (number of months back) is server-side, so it updates the "months"
// URI param and reloads
$("#daterange-filter").on("change", function()
{
	UpdateUriParam("months", $(this).val());
	window.location.reload();
});

// Free-text search box, debounced via Delay()
$("#search").on("keyup", Delay(function()
{
	var value = $(this).val();
	if (value === "all")
	{
		value = "";
	}

	stockJournalTable.search(value).draw();
}, Victual.FormFocusDelay));

// Resets all filters (leaving the product filter alone when embedded, e.g. opened
// scoped to one product) and reloads
$("#clear-filter-button").on("click", function()
{
	$("#search").val("");
	$("#transaction-type-filter").val("all");
	$("#location-filter").val("all");
	$("#user-filter").val("all");
	$("#daterange-filter").val("6");
	RemoveUriParam("months");

	if (GetUriParam("embedded") === undefined)
	{
		RemoveUriParam("product");
		$("#product-filter").val("all");
	}

	window.location.reload();
});

// Reflect the current product/months URI params onto their filter dropdowns on load
if (typeof GetUriParam("product") !== "undefined")
{
	$("#product-filter").val(GetUriParam("product"));
}

if (typeof GetUriParam("months") !== "undefined")
{
	$("#daterange-filter").val(GetUriParam("months"));
}

// Undoes a stock booking (stock/bookings/{id}/undo); if the booking has a correlation id
// (data-correlation-id, e.g. linking a transfer's "from" and "to" bookings), all rows
// sharing that correlation are struck through together, since undoing one undoes the group
$(document).on('click', '.undo-stock-booking-button', function(e)
{
	e.preventDefault();

	var bookingId = $(e.currentTarget).attr('data-booking-id');
	var correlationId = $("#stock-booking-" + bookingId + "-row").attr("data-correlation-id");

	var correspondingBookingsRoot = $("#stock-booking-" + bookingId + "-row");
	if (correlationId)
	{
		correspondingBookingsRoot = $(".stock-booking-correlation-" + correlationId);
	}

	Victual.Api.Post('stock/bookings/' + bookingId.toString() + '/undo', {},
		function(result)
		{
			correspondingBookingsRoot.addClass("text-muted");
			correspondingBookingsRoot.find("span.name-anchor").addClass("text-strike-through").after("<br>" + __t("Undone on") + " " + moment().format("YYYY-MM-DD HH:mm:ss") + " <time class='timeago timeago-contextual' datetime='" + moment().format("YYYY-MM-DD HH:mm:ss") + "'></time>");
			correspondingBookingsRoot.find(".undo-stock-booking-button").addClass("disabled");
			RefreshContextualTimeago("#stock-booking-" + bookingId + "-row");
			toastr.success(__t("Booking successfully undone"));
		}
		// No error callback: this hand-rolled toastr.error was one of two in the tree that
		// re-implemented what Victual.Api.DefaultErrorHandler now does by default - and
		// did it worse, since it JSON.parses a response that a dropped connection never
		// produces, and renders a server-supplied message straight into an HTML sink.
	);
});

// The print action (views/components/label_print_list_header.blade.php), plan 32.
Victual.LabelPrinting.Wire({
	kind: 'product',
	trigger: '.product-label-print',
	within: '#stock-journal-table',
	printerSelect: '#stockjournal-printer',
	status: '#stockjournal-status'
});
