# 21. Frontend sink discipline

**Goal:** Make the frontend XSS gate real. The 23 CodeQL alerts of 2026-09-04 were closed
by hand; the job that was supposed to have caught them does not exist, the harness it
would run cannot boot against this tree, and the one sink that is a decision rather than a
defect is still open. Close all four.
**Depends on:** nothing. Step 1 unblocks [12](12-frontend-shared-core.md)'s whole harness,
so it comes first regardless of what else is scheduled.
**Status:** **landed**, 2026-09-04 — steps 0 to 5, all six open questions answered, and one
of those answers overturned step 4 before it was built. The body below is kept as written;
[Executed](#executed) is the record of what shipped and of what the plan got wrong.

## Today

### What the alerts were

GitHub had 23 open CodeQL alerts on `master`, all JavaScript, all High. Triaged one by
one against the tree rather than by title:

| Class | Alerts | Verdict |
|---|---|---|
| `.html()` / markup concatenation with browser-local input | #32, #10 | **exploitable**, proved with a payload |
| `.html()` of a summernote field | #17 | **wrong when written** — read as real and a decision; it is a false positive, and step 4's Executed section is why |
| DOM string reaching `$()` | #31, #30, #29, #28, #5, #4, #3 | not exploitable — jQuery 3.6 parses a string as HTML only when it starts with `<` and ends with `>`, and every one of these starts with `#` |
| DOM value interpolated into a query string | #37, #36, #35, #20, #19, #18, #14 | not exploitable — fixed path prefix, so no scheme is reachable |
| `replace("'", …)` escaping only the first quote | #25, #24, #23 | real bug, Sizzle selector rather than HTML |
| `stripHtml` single-pass tag strip | #22 | real weakness, but a DataTables search/sort normaliser and not a sanitiser |

**Step 0 (landed, `bdfa00c`)** fixed all 23 rather than dismissing the 20: the two live
sinks properly, the rest as one-line hardenings that also make the sinks honest.
`victual.js:616` rendered a chosen file name with `.html()` — a file named
`<img src=x onerror=…>.txt` executed on selection, in every form with a picture field.
`barcodescannertesting.js:90` concatenated the scanned barcode into `<option>` markup.
Both were verified by reverting the fix and driving a demo instance with Playwright:
`window.__pwned` was `true` before and `false` after.

### The gate does not exist

Three documents in this repository state that the S29 payload probe runs on every pull
request:

- [plans README row 12](../README.md#hardening) — "the probe now runs on every pull request as
  the `frontend-security` job"
- [12-frontend-shared-core.md](12-frontend-shared-core.md) — "the probe now runs on every
  pull request rather than once"
- [.devtools/frontend/README.md](../../../.devtools/frontend/README.md) — "**it is the one
  this repository runs on every pull request** — the `frontend-security` job in
  `.github/workflows/tests.yml`"

`.github/workflows/tests.yml` has three jobs: `lint`, `suite`, `images`. `psalm.yml` has
one: `php-security`. Neither file mentions `s29-payload.js`, and `grep -r s29-payload
.github/` returns nothing. The probe exists and is good; nothing runs it.

This is the reason the 23 alerts are the record they are. S29's second pass closed a
stored-XSS class in September 2026 and declared a standing guard against its return; two
sinks of exactly that shape were then merged and sat open for five days until CodeQL — not
this repository's own gate — reported them.

It is also a second instance of the failure the plans README already named once, in its
own words: *"a deferral that contradicts a stated gate has to change the gate's wording at
the same time, or the wording quietly becomes a claim nobody checks."* Here the wording was
never true to begin with. Step 2 is the fix; **Q1** is whether the corpus needs something
stronger than another promise.

### The harness cannot boot

Independent of CI, `.devtools/frontend/`'s documented recipe does not work against the
current tree. Every probe in it needs a demo instance, and demo mode 503s after its first
request:

```
Victual cannot serve requests: the database schema does not match this code.
  Applied but unknown to this code: -1
```

`DemoDataGeneratorService` records "the demo data already ran" as a row with the magic
migration number `-1` (`services/DemoDataGeneratorService.php:35`).
`DatabaseMigrationService::GetUnknownMigrationNumbers()` returns every applied number this
engine has no file for, `-1` included, and `SchemaVersionMiddleware:77` refuses to serve
when that set is non-empty. So the first `GET /` seeds the demo data and thereby breaks
every request after it.

The dates say this was not noticed rather than accepted: the harness landed 2026-09-02
(`1403cd0`), the middleware started consulting the unknown set on 2026-09-03 (`b46781e`,
"gate on the whole migration set"). Nothing ran the harness in between, which is the same
finding as the section above from the other end.

It also means step 2 cannot be done first. A `frontend-security` job added today would
fail on its first run for a reason that has nothing to do with the frontend.

## Proposed change

Five steps. 1 and 2 are the ones that matter; 3, 4 and 5 are the work the gate then holds.

### Step 1 — the demo marker is not a migration

`GetUnknownMigrationNumbers()` filters to `>= 0`, so the demo marker stops reading as a
rolled-back deployment. One line, plus the comment saying why negative numbers are
bookkeeping rather than schema versions.

`GetMissingMigrationNumbers()` is unaffected (`-1` is never in the required set), and
`.devtools/pgsql/schemagatetest.php` calls the same method at lines 123 and 260 — it must
keep asserting that a genuinely rolled-back database is refused. Both directions get a
test, because the fix is in exactly the code whose job is to be strict. See **Q2** on
whether the marker belongs in the `migrations` table at all.

### Step 2 — the job, for real

Add `frontend-security` to `.github/workflows/tests.yml`: boot a demo instance the way
`.agents/skills/run-app/SKILL.md` does, `npm install` in `.devtools/frontend`, run
`s29-payload.js`, fail the job on a non-zero exit. `PLAYWRIGHT_BROWSERS_PATH` and
`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD` per that skill.

The job has to be shown capable of failing before it is believed, by the probe's own rule:
run it once against a tree with step 0 reverted and confirm it reports the two sinks (after
step 3 gives it probes for them), then restore. A gate whose failure mode has never been
observed is the thing this plan exists to stop shipping.

`routes-smoke.js` is cheap and covers every non-API view route; adding it to the same job
is nearly free. See **Q3**.

### Step 3 — probes for the shape that got through

`s29-payload.js` seeds records through the API and asserts no page executes their names. It
is well built for the sinks S29 was about, and structurally blind to both sinks step 0
fixed, because neither involves a stored record:

- **A file name.** `<input type="file">` → `.custom-file-label`. The payload never touches
  the server; Playwright's `setInputFiles` with a file named `<img src=x onerror=…>.txt`
  reaches it in one call.
- **Local keyboard/scanner input.** `/barcodescannertesting`, where a typed barcode is
  echoed into an `<option>`.

Add both as a `local-input` probe family alongside the seeded one, under the probe's
existing rule that a sink which never appeared and an action that threw both count as
failures. That rule is the reason this suite is worth extending rather than replacing.

**Q4** asks how far the family should go — every `.custom-file-input` on the six forms that
have one, or one representative.

### Step 4 — rich text, sanitised in the browser at render time

Alert #17, `shoppinglist.js:560`, is the one that is not a defect: `#description` is a
summernote field and `.html()` is what renders it. **Decision taken: sanitise in the
browser with DOMPurify.** Two things about the shape of that, both established against the
running app rather than assumed:

**Sanitising on save does not close it.** `PUT /api/objects/shopping_lists/{id}`
(`routes.php:168`) writes `description` directly. A browser-side sanitiser on the save path
keeps the database clean against paste accidents and is worth having for that, but any
account that can edit a list can also skip the page and post the payload. It is hygiene,
not the control.

**Sanitising on render does close it, and has to happen before summernote.** With
`<img src=x onerror=…>` stored in `shopping_lists.description`, the payload fires **on page
load**, in summernote's own contenteditable, before anyone opens the print dialog — so
patching line 560 alone removes nothing. Purifying at render, in the victim's browser, is
effective precisely because that is where the payload would otherwise run.

So:

1. Add DOMPurify as a frontend package (`.yarnrc` already installs into `public/packages`).
2. Purify every `wysiwyg-editor` textarea's value **before** `victual_summernote.js`
   initialises it, and purify at each `.html()` render of such a value — `shoppinglist.js:560`
   today. One helper next to `Victual.FrontendHelpers.EscapeHtml`, which is where a reader
   will look for it.
3. Purify on save as well, for the hygiene reason above, and say in the comment that it is
   hygiene so nobody later mistakes it for the boundary.
4. Configure the allowlist against what the toolbar can actually produce — the toolbar is
   fixed in `victual_summernote.js` (fontsize, bold/underline, colour, lists, tables, link,
   picture, video) and `codeview` lets a user type anything else. **Q5**.

This decides where HTML is trusted in this application, so per [AGENTS.md](../../../AGENTS.md)
it leaves an ADR behind — *Rich text is sanitised in the browser at render time* — recording
the client-side choice, that the API is deliberately not a sanitising boundary, and what
that means for [ADR-0006](../../adr/0006-authenticated-issues-in-scope.md)'s threat model. The
ADR is accepted in its own PR carrying bookkeeping only, per the same file.

### Step 5 — the last of the selector class, and a convention

CodeQL flagged seven `$(domDerivedString)` sites and step 0 fixed them. Twelve more of the
identical shape are in the tree, unflagged, all reading `data-next-input-selector`:

| File | Lines |
|---|---|
| `components/shoppinglocationpicker.js` | 62, 75 |
| `components/productpicker.js` | 140, 161 |
| `components/locationpicker.js` | 61, 74 |
| `components/recipepicker.js` | 62, 75 |
| `components/datetimepicker.js` | 191, 395 |
| `components/userpicker.js` | 65, 81 |

Every value is a Blade literal and none is exploitable, for the same jQuery 3.6 reason as
the flagged seven. They are here because a security change that leaves the identical sink
twelve lines away is a change a reviewer cannot trust, and because CodeQL will eventually
raise them and this plan will be read again from scratch.

`$(document).find(sel)` throughout, matching step 0. Then one line in
[AGENTS.md](../../../AGENTS.md)'s security posture: **a string that came out of the DOM reaches
jQuery through `.find()`, never through `$()`** — short enough to be checked in review,
which is the only kind of convention that survives.

## Open questions

1. **Is a fourth promise the right fix for a gate that was promised three times?** Step 2
   adds the job and the three documents become true. Nothing stops the next divergence. The
   alternative is mechanical: a `lint`-job assertion that each workflow job name named in
   the corpus exists in `.github/workflows/`, so the claim and the tree cannot separate
   silently. That is a small script and a real widening of scope. Worth it, or is the job
   plus this record enough?

   > **Response:** Mechanical. Three documents said the job existed and a fourth was found
   > while answering this question — `docs/security-sweep.md:343` — so the count was wrong
   > in the direction that argues for the script. `.devtools/check-cited-jobs.php` reads
   > every `` `<name>` job `` citation in the Markdown corpus back against
   > `.github/workflows/`, and the `lint` job runs it. It is 150 lines including its
   > reasoning, it found the fourth citation on its first run, and it fails on an empty
   > match set as well as on a missing job — a check that matches nothing cannot fail,
   > which is the state it exists to prevent. It checks one direction only: a job nobody
   > documents is fine; a document naming a job that does not exist is a lie.

2. **Should the demo marker live in the `migrations` table at all?** Step 1 filters it out
   at the read. A `demo_data_generated` row in a settings table, or a dedicated column,
   would mean nothing has to remember the exception — at the cost of a migration and a
   compatibility path for databases carrying the `-1` row today. Filter now and move it
   later, or move it once?

   > **Response:** Filter now, and probably never move it. The filter is on *negative*
   > numbers rather than on "numbers with no file", which is what lets it coexist with a
   > genuine rollback still being refused — the two halves are schema gate cases 6 and 7,
   > and case 6 was confirmed to fail against the unfixed tree before it was kept. A
   > migration to relocate one boolean would cost a compatibility path for every database
   > carrying the row today and buy nothing the comment does not already say.

3. **How much runs in `frontend-security`?** `s29-payload.js` alone is the security gate.
   `routes-smoke.js` needs the same booted instance and would catch a console error on any
   view route. `baseline.js` is a much longer run and belongs on demand. Probe only, or
   probe plus smoke?

   > **Response:** Probe only. `routes-smoke.js` is worth running and is not a security
   > gate; putting it in a job called `frontend-security` would blur what a red result
   > means, and this plan is entirely about a gate whose meaning nobody could check. It can
   > have its own job when someone wants one.

4. **How wide is the `local-input` family?** One `.custom-file-input` probe proves the
   sink; six prove every form. The sink is one shared handler in `victual.js`, so one is
   arguably honest and six is arguably theatre — but the six differ in what sits next to
   the input, which is what `.next()` resolves.

   > **Response:** One, and the four Blade blocks were read before deciding rather than
   > after. `productform`, `recipeform`, `equipmentform` and `userform` carry the same
   > markup copied — `<div class="custom-file">`, input, then label — and the sink is a
   > single delegated handler in `victual.js`. A second probe would assert that a copy is
   > still a copy. The probe does pin the label *by id* rather than by class: `/product/new`
   > carries a second `.custom-file-label` ("No file selected") that is never written to,
   > and a class selector reads that one instead and reports a clean sink forever. That
   > cost a first run to find, and is the kind of thing six probes would have hidden six
   > times over.

5. **What does the DOMPurify allowlist permit?** Two defensible answers. Match the toolbar
   exactly, which is tightest and silently drops anything an existing description already
   contains from `codeview`. Or take DOMPurify's default HTML profile, which is safe and
   keeps existing content intact. This household's data can be inspected before deciding —
   `SELECT description FROM shopping_lists` and the equivalent for recipes and products is
   the whole population.

   > **Response:** Moot — there is no DOMPurify, because the boundary this step was going
   > to build already exists on the server and holds. See
   > [Executed — step 4](#executed--step-4-the-question-that-overturned-the-step).

6. **Does anything else render a `wysiwyg-editor` value?** Step 4 covers summernote's own
   init and `shoppinglist.js:560`. `productform.js:444` pastes a source product's
   description into the editor on copy. A grep for the class plus the fields that use it
   settles this before the step, not during it.

   > **Response:** Yes, five more, and answering this is what overturned step 4. Four
   > templates carry a `wysiwyg-editor` textarea (product, recipe, shopping list,
   > equipment), and their stored values are rendered as HTML in six places:
   > `shoppinglist.js:560`, `equipment.js:47`, `productcard.js:19`, `chorecard.js:20`,
   > `views/recipes.blade.php:574` (server-side `{!! !!}`) and summernote's own init. That
   > server-side one is the finding: a browser-side sanitiser cannot defend markup that is
   > already in the document before any script runs. Following that led to
   > `BaseApiController::HTML_RENDERED_COLUMNS`, and to the answer below.

## Verification

1. **Step 1** — a demo instance serves every page after seeding: `bin/victual-migrate`, boot,
   `GET /`, then 200 on `/stockoverview`, `/shoppinglist`, `/recipes`. `schemagatetest.php`
   still refuses a database carrying a genuine unknown migration.
2. **Step 2** — the job is green on a clean tree, and **red** on a tree with step 0's
   `victual.js` and `barcodescannertesting.js` fixes reverted. Both runs recorded in the
   Executed section with their run URLs.
3. **Step 3** — each new probe reports `xss=1` against the reverted tree and clean against
   the fixed one. A probe that cannot be made to fail is not evidence and does not ship.
4. **Step 4** — `<img src=x onerror=window.__pwned=1>` stored in `shopping_lists.description`
   by `PUT /api/objects/shopping_lists/1`, then: the shopping list page loads with
   `__pwned` undefined, the print dialog renders with `__pwned` undefined, and a description
   using every toolbar feature survives a save/reload round trip visually unchanged.
5. **Step 5** — the twelve sites converted, and every picker still moves focus to its next
   input: purchase, inventory, stockentryform and mealplan driven end to end.
   `.devtools/frontend/two-pickers.js` covers the datetimepicker pair already.
6. **Whole plan** — CodeQL reports zero open alerts on `master`, and the `frontend-security`
   job has failed at least once, on purpose, on a branch.

## Executed

Landed 2026-09-04 in one pull request on `claude/github-security-vulnerabilities-8lguas`,
after step 0 (`bdfa00c`). Everything below was run against a demo instance and, where it is
a gate, run once against a tree where it had to fail.

### Executed — steps 1, 2, 3 and 5

**Step 1.** The filter went into `GetAppliedMigrationNumbers()` rather than into
`GetUnknownMigrationNumbers()` as the plan says. That method's contract is "every migration
number this database has recorded", and `-1` is not one — filtering at the read keeps the
contract true and fixes both derived methods and `GetAppliedMigrationNumber()` at once,
where filtering in the caller would have left the maximum reporting `-1` for a demo
database that had recorded nothing else.

`.devtools/pgsql/schemagatetest.php` gains cases 6 and 7 as a pair: the demo marker is not
a migration, and a genuinely rolled-back database is still refused. Case 7 is the reason
the filter is on negative numbers rather than on "numbers with no file" — a filter wide
enough to swallow the marker and the rollback together would leave nothing to notice a
rollback. Verified: 11 cases pass on SQLite; with the filter reverted, case 6 fails
(`-1 present, unknown [-1]`) and case 7 still passes. A demo instance now serves
`/stockoverview`, `/shoppinglist`, `/recipes`, `/products`, `/barcodescannertesting` and
`/purchase` with the `-1` row present, all 200 — every one of which was 503 before.

**Step 2.** `frontend-security` in `.github/workflows/tests.yml`. It runs PHP **8.5**, not
the 8.4 the `suite` job uses: this job boots the application through `public/index.php`,
where `PrerequisiteChecker` enforces `REQUIRED_PHP_VERSION`, and `composer.json` pins
`8.5.*` — so nothing has to be ignored to install against it. It installs the frontend
packages too, without which every probe would report "the sink was never reached" for want
of jQuery. Per **Q3** it runs the probe and nothing else.

**Step 3.** Two probes, `file-name` and `barcode-echo`, and the `sink` argument became a
lookup in a `SINKS` map so a probe can name where its payload lands. The two original
families read `innerText`; the local-input sinks read `textContent`, because
`#scanned_codes` is a `<select>` whose `innerText` is not its options' text and
`.custom-file-label` carries `d-none` until a picture exists — neither is a statement about
whether markup was injected. Both probes report `xss=1` against a tree with step 0's two
fixes reverted, and clean against the fixed tree.

**Step 5.** Twelve sites in six picker components converted to `$(document).find()`. The
convention is now a bullet in [AGENTS.md](../../../AGENTS.md), stated as two rules — DOM
strings reach jQuery through `.find()`, and markup is built as nodes — with a pointer at
the job that checks them.

**Q1's script**, `.devtools/check-cited-jobs.php`, runs in `lint`. It reads every
`` `<name>` job `` citation in the Markdown corpus back against `.github/workflows/`. On its
first run it found a **fourth** document making the claim — `docs/security-sweep.md:343` —
which the by-hand search had missed, and reading whole files rather than lines found two
more citations that hard-wrapping had hidden. Renaming the job makes it report ten
citations across five files and exit 1.

### Executed — step 4, the question that overturned the step

**Step 4 was not built, and should not be.** Q6 asked whether anything else renders a
`wysiwyg-editor` value. It does — six sinks across five columns, one of them
`views/recipes.blade.php:574`, a server-side `{!! !!}`. A browser-side sanitiser cannot
defend markup that is in the document before any script runs, so answering Q6 meant
looking at where those columns are written, and that is
`BaseApiController::GetParsedAndFilteredRequestBody`:

- `HTML_RENDERED_COLUMNS` names exactly five columns — `products`, `recipes`, `equipment`,
  `chores` and `shopping_lists`, each `description` — and they are exactly the five with
  HTML render sinks.
- Every write through the API is purified by `ezyang/htmlpurifier`, already a dependency,
  with an allowlist narrowed by sweep findings S1 and S7 (no `iframe`, no `id`).
- For those five, the purified output is what is stored. For every other column the entity
  encoding is undone afterwards, which is what S1 was about.

Asked of the running application rather than of the source: `PUT /api/objects/…` with
`<img src=x onerror=…>` stores `<img src="x" alt="x" />`; `<script>`, `<svg onload>` and
`<iframe>` store as `null`; `<a href="javascript:…">` stores as `<a>click</a>`; and a
paragraph of real summernote formatting comes back intact. **The boundary already exists,
on the server, which is the only place it can cover a Blade `{!! !!}`.**

So alert #17 is a false positive, and this plan said otherwise because the evidence behind
it was bad. The payload that "proved" it was written into SQLite with a `PDO::exec`, a path
no attacker has. A finding proved by putting the payload where the attacker cannot put it
proves nothing, and it is worth saying plainly because everything else in this plan was
checked properly.

What was missing was not a sanitiser but the assertion that the one in the tree still
works. `HTML_RENDERED_COLUMNS` is a hand-maintained list of five entities, its purifier is
configured by four settings, six sinks sit behind it, and nothing anywhere asserted any of
it. So step 4 became a probe family instead:

- `html-column:*` writes nine payloads to each of the five columns through the API and
  reads each back, failing on any stored value still carrying an event handler, a script,
  an iframe, an object, an svg or a `javascript:` URI. The ninth payload is *legitimate*
  summernote formatting, which has to survive — a purifier that deleted everything would
  otherwise pass.
- `description-render` leaves a live payload in a shopping list's description and opens the
  print dialog, which is the only way to reach `shoppinglist.js:560` — CodeQL's alert #17 —
  and asserts nothing executed and no element carries an inline handler.

Both were shown capable of failing by making `GetParsedAndFilteredRequestBody` skip
`description`: every column then reports ten offences, and `description-render` reports
`THE PAYLOAD EXECUTED`. Restored, the full suite is 26/26 clean.

This leaves one thing deliberately not done, and it is the residual risk to record: nothing
purifies these columns in the *browser*. If the server-side purifier is ever misconfigured
or bypassed, the render sinks have no second line. Adding DOMPurify would give one, at the
cost of a second allowlist that has to agree with `HTML.Allowed` forever — and a client
allowlist narrower than the server's silently eats formatting users already have. The
`html-column` family is the cheaper form of the same assurance, because it fails loudly at
the boundary rather than papering over it at the sink. Revisit if that purifier is ever
changed.

### Executed — the round CodeQL sent back

Step 0 fixed `productpicker.js`'s three `replace("'", "\\'")` sites by making the regex
global. CodeQL's first run on the pull request reported **three new high alerts**, all
`js/incomplete-sanitization`, all in that file — and the accurate one (alert 38, line 131)
says why: *"This does not escape backslash characters in the input."* Escaping the quote
without escaping the backslash in front of it is still broken. `a\'b` becomes `a\\'b` —
two literal backslashes, then a quote that closes the string and resumes the selector as
syntax. Confirmed in the browser: the old expression throws
`Syntax error, unrecognized expression` on that input.

So step 0's fix was a smaller version of the same mistake: it treated "the escaper is
wrong" as "the escaper needs one more character handled". The third version would have
been wrong too. **There is no third version.** The four sites that built a selector string
from a value — three flagged, plus an unflagged `option[value="…"]` in `FinishFlow()` that
is the identical shape — now call three predicates on `#product_id`'s options, so nothing
is parsed and nothing needs escaping.

Proved equivalent rather than assumed: over all 30 options on `/purchase`, each predicate
and the selector it replaces select the same option, with zero mismatches; prefill by name
still resolves and moves focus; and the payload that made the old expression throw returns
an empty set. 26/26 probes still clean.

The lesson is the one this plan already carries once, in a different costume: an escaper
hand-written against a parser you do not control is a defect waiting for its next input.
The fix is to stop parsing, not to escape harder.

### Executed — the round review sent back

Two findings on the pull request, both valid, both verified against the code before being
acted on.

**P2 — one selector still concatenated browser input.** `productpicker.js` line 282 built
`option:contains("…")` from the typed or scanned value. It survived the sweep that replaced
the other four because that sweep grepped for `option[`, an *attribute* selector, and this
one is `:contains(...)`. So "there is no third escaper" was true and "the file has none of
these left" was not — a sweep is only as wide as its pattern, and the pattern was written
from the examples in hand rather than from the shape of the defect.

It is now `FindOptionByText(input)`, the helper the same commit added. Two siblings in files
this pull request already touches went with it: `userpicker.js:74` (`option[value='…']` from
a Blade attribute) and `recipes.js:94`, which concatenated the search box's text into
`:not(:contains_case_insensitive(…))`.

Verified rather than assumed, against the running app: `FindOptionByText` and the expression
it replaces agree on all 33 comparisons across every option on `/purchase`; the gallery
filter and its old expression agree on 11 needles including every card-title prefix; typing
`x") , option:not(` throws `Syntax error` through the old expression and returns cleanly
through the new one; and the workflow dialog the selector gates behaves identically before
and after, checked by reverting the line and re-running rather than by reading it.

The gallery case is the one worth keeping. Its hostile input did **not** throw through the
old expression — `:contains_case_insensitive(x) , div:not()` is a valid *selector list*, so
it silently matched something else and filtered the wrong cards. A thrown error would have
been the kinder failure.

**P1 — imported and legacy rich text never met the purifier.** Step 4 concluded that
server-side purification on write is the boundary, and it is, for every row the API wrote.
Two paths do not go through the API and the plan accounted for neither:
`DatabaseImporter::CopyTable()` copies rows verbatim, which is what an importer should do,
and no migration has ever rewritten descriptions already stored — so a payload planted
through upstream grocy, or through this fork before sweep finding S1, survives an upgrade or
an import and lands in the six raw render sinks. The `html-column:*` probes cannot see it,
because they write through the purified API. That is the same blind spot this plan found in
the seeded probe families, one level up, in the probes it added itself.

`StoredHtmlPurifier` now runs the API's own purifier over the five columns where they sit,
from two callers: **migration 0260** for a database upgraded in place, and the end of
`DatabaseImporter::Import()` for one that was imported. It has to be both —
`bin/victual-db-import` migrates the target *before* copying into it, so 0260 alone runs
against an empty database and finds nothing.

Two things shaped the implementation, neither obvious from the finding:

- **Purification runs after `AssertValuesMatch`, never before.** The copy's job is to be
  verbatim and that assertion is what proves it was; a target rewritten mid-copy would read
  as one the importer had corrupted.
- **`Import()` takes `purifyStoredHtml`, defaulting to true.** `difftest.php` and
  `trigdifftest.php` compare the two engines row for row and pass `false`: a description
  purified on the PostgreSQL side only would show up as an engine difference. Default-on
  means a future caller is covered without knowing to ask.

The regression test is a ninth suite phase, `richtext`, on both engines — the routine quotes
identifiers through the dialect and writes through PDO, which is what differs per engine. It
plants payloads with a direct write, the way the gap does, and the PostgreSQL half also
takes `--source` and runs a real `DatabaseImporter` copy, which is the finding's own path.
Two of its nine cases are controls rather than assertions about danger: real summernote
formatting must survive, and a column that is *not* HTML-rendered must be left exactly as
typed, because rewriting those would be data loss dressed as a security fix. With
`StoredHtmlPurifier` stubbed out, six of the nine fail and both controls still pass.

End to end on a real upgrade: a payload planted directly in `shopping_lists.description`,
then `bin/victual-migrate`, comes back `<img src="x" alt="x" />`, with 0260 recorded.

**The phase's own first CI run failed, and was right to.** `build_pgsql` hands it a freshly
migrated PostgreSQL database, where `shopping_lists` is the only HTML-rendered table with a
row in it — the base fixture is applied to the SQLite side. Four of the five columns
reported themselves untestable, which is exactly the "a phase that quietly tested four of
five" failure the case was written to produce rather than swallow. The fix is the ordering
every other PostgreSQL phase in this suite already uses: import first, which is both the
case the finding asked for *and* how the target gets its rows. It was found by running a
local PostgreSQL 16 and reproducing the CI failure verbatim, rather than by reading the log
and guessing — the whole suite now passes locally on both engines, nine phases.

And the import case is capable of failing: with `Import()`'s `purifyStoredHtml` default
flipped to `false`, it reports an inline event handler surviving in all five columns.

### Found on the way, not fixed here

Two things surfaced while verifying, neither in this plan's scope and both worth a line so
the next reader does not have to rediscover them.

**A startup failure answers 200.** `public/index.php` catches `ERequirementNotMet` and
prints "Unable to run Victual: PHP 8.5.0 is required…" with the default status. Every route
on a misconfigured instance is therefore a 200 carrying an error page, and
`.devtools/frontend/routes-smoke.js`, which asserts on status codes, reports 80 healthy
routes for an application that cannot boot — this was found by it doing exactly that. The
`frontend-security` job is not exposed to it (a probe whose sink never appears fails, by
that suite's own rule, which is why it survives this), and `SchemaVersionMiddleware`
already answers 503 for the analogous case, so the house answer exists. One line, someone
else's PR.

**Two view routes have pre-existing console errors**, unrelated to anything here:
`productbarcodeform.js:99` dereferences `Victual.EditObjectProduct.id` unconditionally and
the template emits `null` on `/productbarcodes/new`, and `/api` throws
`require is not defined` from the bundled Swagger UI. Both were traced to their source
lines and to commits predating this work.

A like-for-like `routes-smoke.js` baseline against `master` could not be taken, and the
reason is itself the point: `master` cannot boot a demo instance at all until step 1 lands.

### What this plan got wrong

Two things, both worth keeping:

1. **A verdict was published on evidence that could not have been negative.** `#17` was
   called real because a payload written straight into the database rendered. The write
   path was never tested, and the write path was the answer.
2. **The plan proposed a dependency before reading the code that would have made it
   unnecessary.** Q6 was written as a tidy-up question — "does anything else render one of
   these?" — and it was the question that mattered. It was answered after the decision
   rather than before it, and only the order of work saved that from shipping.
