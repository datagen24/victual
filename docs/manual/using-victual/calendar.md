# Calendar

Requires `FEATURE_FLAG_CALENDAR`. **`/calendar`** overlays everything else's due dates on
one month view: due products, due tasks, due chores, due battery charge cycles and meal plan
entries, each in its own color (`calendar_color_products`, `calendar_color_tasks`,
`calendar_color_chores`, `calendar_color_batteries`, `calendar_color_meal_plan` — per-user
settings, hex codes). `CALENDAR_FIRST_DAY_OF_WEEK` and `CALENDAR_SHOW_WEEK_OF_YEAR`
([Configuration](../configuration.md#localization-and-display)) control the week layout.

## Subscribing from another calendar app

`GET /api/calendar/ical` serves the same due dates as an iCalendar feed; a signed-in user's
personal, unguessable feed URL is available from `GET /api/calendar/ical/sharing-link`.
Anyone holding that URL can read the feed without further authentication — treat it like a
password. If it leaks, delete your calendar sharing key on `/manageapikeys`, then call
`GET /api/calendar/ical/sharing-link` to create a new one and update your subscriptions.
Calling the endpoint without deleting the unexpired key returns the existing URL. Calendar
sharing keys are never redacted, including through the SQLite import path described in
[Getting started](../getting-started.md#moving-an-existing-sqlite-installation-across).
