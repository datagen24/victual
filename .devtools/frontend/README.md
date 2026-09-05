# Frontend baseline harness

Records what the list and form pages do today, so a refactor of them can be checked
against something better than an opinion. It is [plan 12](../../docs/plans/12-frontend-shared-core.md)
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
node undo-toasts.js --url http://127.0.0.1:8200 --db "$VDATA/victual_en.db"

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
[plan 21](../../docs/plans/21-frontend-sink-discipline.md). `html-column` is the only
assertion anywhere that `BaseApiController::HTML_RENDERED_COLUMNS` and its purifier
configuration still do their job — five columns are deliberately rendered as HTML, so
escaping is not available and that server-side purifier is the whole boundary. To watch the
family fail, make `GetParsedAndFilteredRequestBody` skip `description`: every column then
reports ten offences and `description-render` reports the payload executing. The
local-input family exists because every other family takes its payload from the *database*,
and so was structurally blind to a sink fed by input the browser never sent anywhere —
which is how two live sinks reached master in September 2026.

**`s29-payload.js` is a gate, and it is the one this repository runs on every pull
request** — the `frontend-security` job in `.github/workflows/tests.yml` boots a demo
instance and runs it. That sentence was written here on 2026-09-03 and only became true on
2026-09-04: the job was described in four documents before it was written and then not
written, and while nothing ran the probe two live sinks of the class it guards reached
master. `php .devtools/check-cited-jobs.php`, in the `lint` job, is what stops a job being
described here again without existing. It prints a `PASS`/`FAIL` line per probe with the reason, and exits
non-zero if any probe is not clean. Everything that makes a probe uninformative counts as
a failure, deliberately: a payload that executed, an injected `<img>`, a payload not
visible as text, a record that was never seeded, a **sink that never appeared** and an
**action that threw**. A run in which every action silently did nothing must not be able
to report success, which is what treating those last two as skips would allow.

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

`undo-toasts.js` books stock on each of the seven pages that show an Undo toast, clicks
the Undo link in the toast that page rendered, and reads `stock_log` back to confirm every
row the booking wrote came back `undone = 1`. It books and undoes real stock, so it needs a
throwaway database, and it reads that database directly because `stock_log` has no read
API - hence `--db`. It is the acceptance test for plan 12 step 5's shared
`public/js/victual_stock_dialogs.js`, and it is known to be capable of failing: delete the
`purchase.js` `@push` from a pre-step-5 `stockoverview.blade.php` and it reports
`UndoStockTransaction is not defined`, 1 row booked and 0 undone.

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
