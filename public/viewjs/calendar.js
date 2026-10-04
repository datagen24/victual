// View script for the calendar page (views/calendar.blade.php):
// FullCalendar setup fed by the server-rendered Victual.FullcalendarEventSources global,
// iCal sharing link retrieval via GET /api/calendar/ical/sharing-link and event color configuration modal

// First day of week comes from the Victual.CalendarFirstDayOfWeek user setting (empty = locale default)
var firstDay = null;
if (Victual.CalendarFirstDayOfWeek)
{
	firstDay = Number.parseInt(Victual.CalendarFirstDayOfWeek);
}

// FullCalendar setup; clicking an event navigates to its associated Victual page (info.link)
var calendar = $("#calendar").fullCalendar({
	"themeSystem": "bootstrap4",
	"header": {
		"left": "month,agendaWeek,agendaDay,listWeek",
		"center": "title",
		"right": "prev,today,next"
	},
	"weekNumbers": Victual.CalendarShowWeekNumbers,
	"defaultView": ($(window).width() < 768) ? "agendaDay" : "month",
	"firstDay": firstDay,
	"eventLimit": false,
	"height": "auto",
	"eventSources": Victual.FullcalendarEventSources,
	// Timed events are instants (ADR-0027 decision 2) and are shown in the viewer's zone.
	// All-day events are calendar dates and are not converted.
	"timezone": "local",
	"eventClick": function(info)
	{
		location.href = info.link;
	},
	"timeFormat": "HH:mm"
});

// Show the public iCal sharing URL (GET /api/calendar/ical/sharing-link) in a dialog including a QR code
$("#ical-button").on("click", function(e)
{
	e.preventDefault();

	Victual.Api.Get('calendar/ical/sharing-link',
		function(result)
		{
			bootbox.alert({
				title: __t('Share/Integrate calendar (iCal)'),
				// The URL is server generated, but it is concatenated into an attribute value
				// inside a message bootbox renders with .html(), so it is escaped like every
				// other interpolation into that sink (sweep finding S29)
				message: __t('Use the following (public) URL to share or integrate the calendar in iCal format') + '<input type="text" class="form-control form-control-sm mt-2 easy-link-copy-textbox" value="' + Victual.FrontendHelpers.EscapeHtml(result.url) + '"><p class="text-center mt-4">'
					+ QrCodeImgHtml(result.url) + "</p>",
				closeButton: false
			});
		}
	);
});

$(window).one("resize", function()
{
	// Automatically switch the calendar to "basicDay" view on small screens
	// and to "month" otherwise
	if ($(window).width() < 768)
	{
		calendar.fullCalendar("changeView", "agendaDay");
	}
	else
	{
		calendar.fullCalendar("changeView", "month");
	}
});

// Event color configuration modal; reload the page on close so changed colors take effect
$("#configure-colors-button").on("click", function(e)
{
	e.preventDefault();

	$("#configure-colors-modal").modal("show");
});

$("#configure-colors-modal").on("hidden.bs.modal", function(e)
{
	window.location.href = U('/calendar');
})
