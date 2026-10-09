# Frontend baseline harness

Records what the list and form pages do today, so a refactor of them can be checked
against something better than an opinion. It is [plan 12](../../docs/plans/landed/12-frontend-shared-core.md)
verification check 1; `baseline-2026-09-02.json` and its `.md` summary are the recorded
run.

```bash
# 1. a booted demo instance (see .agents/skills/run-app/SKILL.md)
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php -S 127.0.0.1:8200 -t public &
curl -s -o /dev/null http://127.0.0.1:8200/            # seeds the demo data

# 2. the harness
cd .devtools/frontend
PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install
PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node baseline.js \
	--url http://127.0.0.1:8200 --out /tmp/baseline-now.json
```

`--only locations,productgroups` limits the run to named pages. The page inventory
(selectors, URLs, data attributes) lives in `pages.js`; the walk is `baseline.js`.

It creates and then deletes one record per list, so it needs a throwaway database. The
absolute row counts belong to whatever demo data the run used — what a later run must
reproduce is the deltas, the reload conventions, the delete style and the console column.

**Three cells changed on purpose in plan 12 steps 3 and 4**, so a run against a tree that
has those will not match `baseline-2026-09-02.md` exactly, and should not:

| Page | Column | Was | Is | Why |
|---|---|---|---|---|
| `productgroups` | Parent reloads on dialog dismiss | `true` | `false` | Step 4: its form posts `Reload` after a save instead of `CloseLastModal`, which also fires on Escape |
| `productgroups` | Form left disabled on edit save | `true` | `false` | Step 4: `/productgroup/{id}` had no non-embedded branch, so a save outside a dialog never finished |
| `userobjectform` | Enter-to-submit bound | `false` | `true` | Step 3: the factory binds it by construction |

Anything else that moves is a regression.

## The other probes

```bash
# plan 12 check 5 - S29, proved with a stored payload rather than by reading the diff
node s29-payload.js --url http://127.0.0.1:8200 --out /tmp/s29.json

# plan 12 check 3 - error surfacing, forced by intercepting routes and answering 500
node forced-failure.js --url http://127.0.0.1:8200

# every view route in routes.php's non-API group: HTTP status and console problems
node routes-smoke.js --url http://127.0.0.1:8200 --out /tmp/routes.json

# plan 12 check 2, last item - the Undo link in every stock booking toast still undoes
node undo-toasts.js --url http://127.0.0.1:8200

# plan 12 check 6 - two datetimepickers on one page set, clear and validate independently
node two-pickers.js --url http://127.0.0.1:8200
```

`s29-payload.js` seeds records whose name is a live `<img onerror>` tag and asserts that
no page executes it. Run it against an unfixed tree first — there it must report `xss=1`
on every probe, or it is not capable of failing and proves nothing. It leaves its seeded
records behind, named with a per-run token, so it needs a throwaway database too.

It carries four families, added at different times for different reasons:

| Family | Payload arrives via | Asserts |
|---|---|---|
| seeded | a record written through the API | nothing executes, and the payload is visible as text |
| `error-details` | a server error message, route intercepted | the same, for the technical-details dialog |
| `html-column:*`, `description-render` | the API, into the five HTML-rendered columns | nothing dangerous is *stored*, legitimate formatting survives, and the page rendering it executes nothing |
| `file-name`, `barcode-echo` | the browser itself — a chosen file, a typed barcode | nothing executes, and the payload is present as text |

The last two families exist because of
[plan 21](../../docs/plans/landed/21-frontend-sink-discipline.md). `html-column` is the only
assertion anywhere that `BaseApiController::HTML_RENDERED_COLUMNS` and its purifier
configuration still do their job — five columns are deliberately rendered as HTML, so
escaping is not available and that server-side purifier is the whole boundary. To watch the
family fail, make `GetParsedAndFilteredRequestBody` skip `description`: every column then
reports ten offences and `description-render` reports the payload executing.

The local-input family exists because no other family takes its payload from the browser.
The rest read it from the *database*, or, for `error-details`, from an intercepted server
error message. All of them were structurally blind to a sink fed by input the browser never
sent anywhere — which is how two live sinks reached master in September 2026.

**`s29-payload.js` is a gate, and it is the one this repository runs on every pull
request** — the `frontend-security` job in `.github/workflows/tests.yml` boots a demo
instance and runs it. That sentence was written here on 2026-09-03 and only became true on
2026-09-04: the job was described in four documents before it was written and then not
written, and while nothing ran the probe two live sinks of the class it guards reached
master.

`php .devtools/check-cited-jobs.php`, in the `lint` job, is what stops a job being
described here again without existing. It prints a `PASS`/`FAIL` line per probe with the
reason, and exits non-zero if any probe is not clean.

Everything that makes a probe uninformative counts as a failure, deliberately: a payload
that executed, an injected `<img>`, a payload not visible as text, a record that was never
seeded, a **sink that never appeared** and an **action that threw**. A run in which every
action silently did nothing must not be able to report success, which is what treating
those last two as skips would allow.

One probe seeds nothing. `error-details` takes the payload from a *server error message*
instead, injected by intercepting the route and answering 500 — because `ShowGenericError`
renders the technical details through bootbox, and a uniqueness violation on PostgreSQL
quotes the offending value back into that message.

**One fixed sink has no probe**: the "Unable to print" body in `shoppinglist.js`, which
interpolates the thermal printer's error response into `.html()`. It sits behind
`VICTUAL_FEATURE_FLAG_THERMAL_PRINTER`, which the demo instance does not set, so reaching
it from here would mean the probe forcing a feature flag on in the page — a probe that
asserts against a state no real instance is in. It is escaped the same way as the others
and covered by reading the diff, which is the weaker evidence and is recorded as such.

`forced-failure.js` exits non-zero if any assertion fails, so it can be run as a gate.

`undo-toasts.js` books stock on each of the eight pages/forms that show an Undo toast and
clicks the Undo link in the toast that page rendered. It confirms every row the booking
wrote came back `undone = 1` by reading the booked rows back through the API -
`GET /stock/transactions/{id}` or `GET /stock/bookings/{id}`, the same endpoint the
toast's own Undo link posts its undo to.

It books and undoes real stock, but needs no throwaway database of its own: it runs
against the same PostgreSQL demo instance the other `frontend-security` probes do, wired
into that CI job. Issue #579 is the gap this closes - the previous version read
`stock_log` through a raw SQLite connection, and nothing under `.github/` ran it.

It is the acceptance test for plan 12 step 5's shared
`public/js/victual_stock_dialogs.js`, and it is known to be capable of failing: delete
the `purchase.js` `@push` from a pre-step-5 `stockoverview.blade.php` and it reports
`UndoStockTransaction is not defined`, 1 row booked and 0 undone.

The `stockentry-edit` scenario additionally covers issue #575: the edit form's Undo link
was built from `result.id`, which is `undefined` against the array
`PUT /stock/entry/{entryId}` actually returns. `EditStockEntry` always writes two
correlated rows (`STOCK_EDIT_OLD` and `STOCK_EDIT_NEW`) sharing one `transaction_id`, so
this scenario books 2 rows. On the unfixed code it undoes 0 of them, because the link's
booking id is `undefined` and the undo POST is refused.

`two-pickers.js` drives both datetimepickers on `stockentryform`, `purchase`, `inventory`
and `mealplan` and, after each action on one, reads the other's value and validity back. It
addresses each picker by the id its Blade include was given and the component API by
whichever name the tree registers, so the same run works before and after step 5's merge.
Note the "clear" it exercises is the component's `Clear()` API: tempusdominus' own trash-can
button is never rendered, because the component enables `showToday` and `showClose` only.
It exits non-zero if any action on one picker moved the other.

`routes-smoke.js` reads its route list from `routes.txt`, which is the `$group->get(...)`
paths of `routes.php`'s non-API group; regenerate it with

```bash
sed -n '33,150p' ../../routes.php | grep -oE "\\\$group->get\('[^']*'" | sed "s/.*get('//;s/'\$//" > routes.txt
```

## Role workflow

`node roles.js <url>` runs against a disposable authenticated admin or demo instance. It
creates a custom role and a user, checks the role form and escaped delete confirmation,
and assigns/removes Child and Guest through the user permissions page while preserving
an overlapping direct grant. `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` optionally selects an
installed Chromium executable. CI runs it in `frontend-security` after the S29 probe.

## Userform password checkbox (issue #549)

`node userform-password.js <url>` runs against a disposable authenticated admin or demo
instance. It creates a second user and edits that account's page with `#change_password`
left unticked, intercepting the PUT to `/api/users/{id}`.

It asserts the built body carries no `password_base64`. The field used to be sent
unconditionally. A disabled, unserialized password input then became `btoa(undefined)` - a
real new password of the literal word "undefined". A second save, box ticked, with a real
password, asserts `password_base64` is still sent, so the first assertion is not vacuous.

This is entirely a question of what the browser puts in the request body, which the
PostgreSQL phase cannot see. CI runs it in `frontend-security` after the role workflow
probe.

Externally managed (reverse-proxy) authentication, an embedded install and
authentication disabled entirely all render no such checkbox in either mode - there is
no local password to change - so this probe cannot exercise any of them, and
`frontend-security` never boots an instance under any of those backends.
`tests/Pgsql/PasswordRotationTest.php` covers their server-side behavior instead: the
rendered form, and the API's handling of a request shaped like what that form actually
sends.

What none of that reaches is what a real browser's own `serializeJSON()` produces from
the form in edit mode under any of the three. That half is genuinely untested by any
automated check in this repository.

## Location label resolution

`node .devtools/frontend/location-labels.js --url http://127.0.0.1:8200` exercises the
locations link and scanner on a running app, with intercepted API responses for live,
retired, unknown, failure/recovery, literal HTML names and out-of-order responses. It also
checks the camera event and clears on edit/reload. PostgreSQL identity and authorization
coverage is in [the label tests](../labels/README.md).

## Consumption recipes

`node consumption-recipes.js <url>` runs against a disposable demo instance, in the
America/New_York time zone. It creates a private consumption recipe through the page and checks:

- the recipe name and note, seeded with the S29 payload, reach the list and the consume,
  share and history dialogs as text and never as an element
- a consumption asking for more than the chosen location holds shows the stock's refusal and
  books nothing, and the other organizer is not charged
- a consumption sent from the other organizer books there, and the request carries the
  browser's own offset (`-04:00` or `-05:00`) rather than `Z`, so the booked date is the
  user's local date
- history lists the consumption and undo restores what it booked
- sharing by username, changing a share's rights, a refusal for an unknown name, and removing
  the share
- deleting the recipe removes its row

The demo instance has one user, so the probe cannot sign in as a second person. The rights of
an owner, a member and a stranger are covered by `ConsumptionRecipeServiceTest` and
`ConsumptionRecipeApiTest` in the PostgreSQL suite.

CI runs it in `frontend-security` after the nested location checks, against the demo instance
on 8085.

## Consumption inbox

`node consumption-inbox.js --url <url> --admin-password <password>` drives `/consumptioninbox`,
the private reconciliation page for external consumption events (issue #700, ADR-0041). It needs
a disposable instance in `VICTUAL_MODE=production` with a fresh database and
`VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` set before the first migration (or `VICTUAL_PROBE_ADMIN_PASSWORD`
in the probe's environment). It cannot use the demo instance: demo and dev mode have one identity
for every request, and the probe needs users that sign in with different permissions and an API
key for the external client. It creates its own users, products, a consumption recipe and mappings,
and checks each outcome on the page and through the API or the stock ledger:

- a user without stock permissions gets the 403 page, and a user with `STOCK_VIEW` only sees the
  actions disabled with an explanation while the API refuses the same action
- a manual consumption (recorded through the recipes page) and an external event for the same
  product (sent with the user's API key) are listed as a possible duplicate; a transaction id
  that cannot be linked shows the server's sentence and changes nothing; Link leaves the event
  linked and exactly one deduction
- an event booked, undone in the stock journal page, replayed (it stays undone), listed under
  the undone toggle with Book again, and booked once more by that action
- a `needs_mapping` event, an action on a stale row that the server refuses with 409
  `invalid_transition`, and Dismiss
- a unit label sent by the source (seeded with the S29 payload) shown as text and never as an
  element, approve_unit, candidate locations shown by name, and a bulk dismiss of 55 events that
  takes two requests and ends with none remaining
- the medication filter, with the list response stubbed to add `medication_ref`, which the API
  does not return yet

CI boots the instance on 8093 in `frontend-security`, runs the probe after the consumption
recipe checks and tears the instance down.

## Nested locations

`node nested-locations.js <url>` runs against a disposable demo instance. It builds plan 08's
fixture tree through `/location/new` with the parent picker and checks:

- creating a child of a freezer pre-ticks *Is freezer*, while creating a child of an ordinary
  location does not
- a location dropdown offers a location by its whole path
- a purchase made there lands at that location's id
- the stock overview's location filter rolls up (Basement finds stock held at Door three
  levels below, Main does not)
- deleting a location with children shows the API's own refusal while leaving the row in
  place

One location in the tree is named with the S29 payload, so every page above renders it. Every
name carries a per-run token, so a second run against the same instance neither collides with
the first nor asserts against it.

CI runs it in `frontend-security` after the product group minimum stock checks, against the
demo instance on 8085. The database coverage — the view, the guards, the depth cap and the
entities — is `.devtools/pgsql/nested-locations-tests.php`, run by `run-tests.sh locations`.

## Nested product groups

`node nested-product-groups.js <url>` runs against a disposable demo instance. It builds a
small group tree (`Spices / Garlic / Fresh`) through `/productgroup/new` with the parent
picker and checks:

- the product form's group dropdown offers a group by its whole path
- the product groups list renders a path column
- deleting a group with children shows the API's own refusal (`Product group has child
  groups`) while leaving the row in place

Every name carries a per-run token, so a second run against the same instance neither
collides with the first nor asserts against it. No S29 payload row here: `s29-payload.js`'s
own `productgroups` probe, run earlier in the same job, already plants one and asserts the
list renders it as text.

CI runs it in `frontend-security` after the nested location checks, against the demo instance
on 8085. The database coverage — the view, the guards, the depth cap, the mixed node and the
entities — is `.devtools/pgsql/nested-product-groups-tests.php`, run by
`run-tests.sh productgroups`.

## Product form nullable-integer pickers

`node product-nullable-pickers.js <url>` is the regression test for
[issue 159](https://github.com/datagen24/victual/issues/159): `public/viewjs/productform.js`'s
save handler always sent every nullable-integer picker's field even when nothing was picked,
and `serializeJSON()` reports an unselected `<select>` as `""`. PostgreSQL refuses that empty
string for the integer column underneath, so the browser sees an opaque 400 naming no field.
The issue reproduced this directly against `POST /api/objects/products` for four fields
(`parent_product_id`, `product_group_id`, `shopping_location_id`,
`default_consume_location_id`); the same direct reproduction, done while writing this probe,
found the identical 400 for the other two nullable-integer pickers the form grew under plan 29
(`default_refill_location_id_from`/`_to`, migration `0276.pgsql.sql`) — the fix and this probe
cover all six.

No PHP phase can see this: a round trip through `/objects/products` posts real integers, never
`""`. This probe drives `/product/new` exactly as a person filling in only the required fields
would, leaving every nullable-integer picker at its default blank selection. It asserts the
create succeeds and every one of the six reads back as `null` rather than an id of `0`. It then
reopens the created product and does an untouched re-save to exercise the edit (PUT) branch of
the same handler, which runs the identical `jsonData` transform.

CI runs it in `frontend-security` after the working container replenishment checks, against the
demo instance on 8085.

## Descendant product group filtering

`node group-min-stock-descendants.js <url>` checks ADR-0034's controller/browser gate
against a disposable dev or demo instance. With zero-stock products otherwise hidden,
a short ancestor must reveal its descendant through an inactive intermediate group.
The probe also checks independent filtering of same-named groups, displayed paths,
inactive-product exclusion, and exclusion of unrelated branches. The `frontend-security`
CI job runs it alongside `group-min-stock.js`.

## Nullable-integer and nullable-date form fields

`node nullable-integer-forms.js <url>` is the regression test for the JS half of
[issue 574](https://github.com/datagen24/victual/issues/574) and for
[issue 587](https://github.com/datagen24/victual/issues/587): the same shape of defect as
issue 159 above, in three `Victual.EntityForm` `body()` hooks that probe did not cover.
`mealplansectionform.js` and `userfieldform.js` each convert a blank `sort_number`;
`taskform.js` converts `category_id`, `assigned_to_user_id` (renamed from the user picker's
own `user_id`) and `due_date` (read from the `DateTimePicker` component, not a plain input).
Without the conversion, `serializeJSON()`'s `""` for the blank field reaches PostgreSQL and is
refused the same way as the product form's pickers.

No PHP phase can see this either, for the same reason: a server-side test sends a
correctly-typed body, so it cannot tell whether the conversion is still there. This probe
drives each form as a person leaving an optional field blank would:

- **meal plan section, userfield**: create a row with `sort_number` blank and assert the
  stored value is `null`. Resave a stored `0` row unchanged and assert `0` is kept - the same
  case issue 574 itself was, a `!empty()` view check treating `0` as blank. Resave the blank
  (`null`) row unchanged and assert `null` is kept with no error surfacing.
- **task**: create a task with category, assignee and due date all blank, and assert all
  three are `null`. Resave it unchanged and assert success.

## Chore reschedule with no assignee

`node chore-reschedule.js <url>` is the regression test for the reschedule modal on
`/choresoverview`. Its Save handler in `public/viewjs/choresoverview.js` sent
`rescheduled_next_execution_assigned_to_user_id` straight from
`Victual.Components.UserPicker.GetValue()`, which reads back `""` when no one is picked —
always, with `VICTUAL_FEATURE_FLAG_CHORES_ASSIGNMENTS` off, as in demo mode. PostgreSQL refuses
`""` for the nullable integer column, so the reschedule was lost behind the same opaque 400 as
the forms above.

The probe opens the modal for an active chore, leaves the assignee blank, sets a date and saves.
It asserts the PUT carried `null`, answered 204, and stored the date with a `NULL` assignee,
then puts the chore back. CI runs it in `frontend-security` after the nullable-integer form
checks, against the demo instance on 8085.

CI runs it in `frontend-security` after the product form nullable picker checks, against the
demo instance on 8085.

## Meal plan button permission gates (issue #591)

`node mealplan-permissions.js --child-url <url> --guest-url <url> --full-url <url>` asserts
which of the meal plan's consume-recipe, add-missing-to-shopping-list and consume-product
buttons render for the built-in CHILD and GUEST roles versus a fully-granted user, and that
the week aggregate's consume button never `PUT`s `objects/meal_plan/undefined`.

It cannot run against the shared demo instance on 8085 the way most other probes here do.
Demo/dev mode has exactly one identity for every request
(`SessionService::GetDefaultUser()`, the lowest user id). `PUT /api/users/{id}/permissions`
refuses granting anything the caller does not already hold (`User::CheckMayGrant()`). So
once that one shared identity were reduced to CHILD's or GUEST's permission set, it could
never be raised back for a later probe in the same job, nor moved sideways to the next
role shape.

Each of the three URLs is therefore its own disposable `VICTUAL_MODE=dev` instance with its
own empty database. The probe seeds one recipe entry and one product entry through the API
while the instance's own bootstrap administrator still holds every permission. It then
downgrades that one identity to the target role's exact grant set exactly once (a one-way
trip, for the reason above) before loading `/mealplan` and reading the DOM back.

Every button on the page is otherwise gated only on what its own click flow's routes
already require, so `mayConsumeMealPlanRecipe()`/`mayAddMealPlanRecipeToShoppingList()`/
`mayConsumeMealPlanProduct()` (`public/viewjs/mealplan.js`) are asserted, not just read.

On unfixed `mealplan.js`, CHILD's `RECIPES_VIEW` + `STOCK_CONSUME` +
`SHOPPINGLIST_ITEMS_ADD` were enough to see all three buttons - the composite-permission
gap issue #591 reports. Each button's own click flow also calls a route requiring
`RECIPES_MEALPLAN` or `RECIPES`, which CHILD lacks. The product-consume button rendered for
every role with no permission gate at all.

The week button's `data-mealplan-entry-id` check runs only for the fully-granted shape -
the one role whose gate lets that button render.

CI boots the three disposable instances after the labels instance and runs this probe in
`frontend-security` after the role workflow probe.
