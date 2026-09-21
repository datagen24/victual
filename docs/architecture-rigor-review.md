# Architectural rigor review — 2026-08-29

A second-pass review, run against `master` at `c998aaf5`, of two things together: the
codebase as it stands after the wave-0 tooling, the transaction work and the rename, and
the plan corpus (`docs/plans/01–17`, `docs/plans/README.md`, `docs/architecture-review.md`,
`docs/mcp-interface-spec.md`, `db/pgsql/README.md`) that is supposed to describe it.
The question is not "is the design good" — the 2026-08-27 review answered that and the
answer holds — but whether the plans are rigorous *as instruments*: internally
consistent, consistent with each other, consistent with the code, and honest about what
has and has not happened. Every claim below was checked against the tree or the git
history; the "Where" columns are what to open.

One housekeeping note first, because it was raised alongside the request. The
`.phpdoc/` directories are phpDocumentor output — ephemeral, regenerated on demand
with `phpdoc.dist.xml`, and deliberately never committed. That is the right policy and
`phpdoc.dist.xml` says so in its own comment. But the ignore rule is anchored
(`/.phpdoc`), so it only covers the repository root; `branding/.phpdoc/` exists in the
working tree today and shows up as untracked. See finding H1.

## Executive summary

The plan corpus is unusually rigorous for a one-household fork: every plan carries a
numbered open-questions section with inline review answers, a schema section that names
the migration shape, an API section that says whether a response changes, and a
verification section that demands a booted instance rather than lint. Several plans
correct their own earlier reasoning in place and say why (14-Q3 on the CI image, 14-Q4
on the exemption mechanism, 07-Q6 on what `parent_product_id` means). The ground rules
(additive API, dual-engine migrations from 0256, verification on a real database) are
stated once and enforced by tooling — `check-migrations.php`, the four-phase
differential suite, the rollback tests in CI. That is the strong half.

The weak half is that **the corpus has fallen behind the code it governs, and behind
itself, in the last three days.** Specifically:

1. **Status drift.** Plan 13 is fully implemented (`782289b8`, `7abfd2fa`, `96f9ec99`)
   and wave 0 is complete (`40e1f57f`, `d80a88f0`, `fd506a85`), yet 13 and 14 still read
   "draft for review" and the README status table shows neither as done. Anyone
   working from the README would re-plan finished work.
2. **The rename broke the clients that plan 17 was written to protect, in the order
   the README says not to.** The README's own sequencing rule is "17 before 11, 16 and
   10". 16 landed first, renamed the `GROCY-API-KEY` header and the `grocy_version`
   response field on the explicit premise that "no client exists", and 17 — written
   the same day — documents two external clients that use both. 17's three open
   questions are unanswered and its coupling analysis still describes a pre-rename
   server.
3. **A factual error underpins a design argument in two plans.** 14 and 11 both state
   that `/api/recipes/{recipeId}/copy` is "documented in the spec with no route behind
   it" and use it to argue for a two-way parity assertion. The route exists at
   `routes.php:237` and has since 2021. The parity assertion is still worth building;
   the argument for it is half wrong.
4. **The three-way migration rule is documented in one place and contradicted in
   three.** `db/pgsql/README.md` defines portable / per-engine pair / documented
   engine-exclusive (with mandatory `@engine-exclusive` and `@overrides-generic`
   markers). `CONTRIBUTING.md`, `PULL_REQUEST_TEMPLATE.md` and plan 05's schema section
   all present only the first two.
5. **Plan 07 is scheduled as wave 4's centrepiece while its own Q6 response says it may
   not need to exist.** The README's order of operations was not updated after Q6.

None of these is a code defect. All of them are the kind of thing a plan corpus exists
to prevent, which is why they matter more here than the same slips would in a wiki.

## Status as of 2026-08-30

This section did not exist when the review was written, and its absence was the review's
own blind spot. The security sweep numbers its findings S1–S29 and the roadmap tracks every
one of them by number — which wave takes it, which plan absorbs it, why one was deferred
and then un-deferred. This document numbered its findings the same way and nothing tracked
them, so a reader a day later could not tell which of the twenty-nine were still true.

The count, once someone checked: **ten had already been fixed**, quietly, by the doc-drift
pass that followed the review. **Two needed no action** and said so. **Seventeen were still
open**, and two of those were worse than open — they had been *recorded as fixed
elsewhere*, in the roadmap and in the sweep, while the fix was never made. Of the
seventeen, **nine are closed by the branch that added this table**, one (D5) becomes a
stated rule rather than a sweep, and **seven carry an owning plan** because they are code
or config changes and this branch is scoped to `docs/`.

Every row below was re-checked against the tree on 2026-08-30, at `4fa97e8`. "Closed"
means the finding was verified gone, not that a commit claimed it — a distinction this
document has more reason than most to insist on, since two of its rows are exactly the
case where a commit claimed it and it was not so. Rows marked "closed 2026-08-30" were
closed by the same branch that added this table; rows marked "closed" without a date were
already fixed before it.

| # | State | Evidence, or where it now lives |
|---|---|---|
| A1 | **closed** | 13's status line reads "landed in the codebase (2026-08-29)" and it carries an Executed section. |
| A2 | **closed** | 14 reads "partly landed", with an Executed section covering pieces 1, 3 and 4 and what piece 2 still owes. |
| A3 | **closed 2026-08-30** | 10's second-seam paragraph required the helper to "count depth"; the shipped helper asks `PDO::inTransaction()`, which is strictly better — a counter cannot see `DatabaseMigrationService`'s own transactions and would *cause* the mis-nesting the paragraph feared. The paragraph now says so and marks the constraint discharged. |
| A4 | **closed 2026-09-17** | `WithMigrationLock()` landed on `DatabaseDialect` (plan 10) since this row was written, so the pointer already resolved to a real method; **15-C12** turned the prose into an `@see DatabaseDialect::WithMigrationLock()` tag rather than leave a reader to find it by inspection. |
| A5 | **closed** | Both plans corrected. 14 now says "Only the first is real. The copy route exists" and keeps the parity check on its remaining leg. The real gap — `/api/openapi/specification` absent from the spec — is still confirmed (`grep -c` returns 0) and is piece 2's to land. |
| A6 | **closed, and re-measured** | Still exactly as the plan says: 148 `console.error` in 41 `public/viewjs/*.js` plus 9 in 5 `viewjs/components`, total 157. The `DeleteUserePictureOnSave` typo and the `transaction_type` override are both still present and still annotated in-code. Four more `console.error` live in `public/js/`, outside the plan's scope and outside its verification grep — which is correct, not a miscount. |
| A7 | **closed 2026-09-04** | Both halves landed with plan 11 in wave 2, commit `7c4a60a0`. 11-Q6 resolved to *populate*: the enum now reads `["userfields", "userentities"]`, so `GenericEntityApiController::IsEntityWithEditRequiresAdmin()` fires at all three call sites (`:60`, `:175`, `:264`) and a non-admin holding only `MASTER_DATA_EDIT` gets 403 on `POST`/`PUT`/`DELETE` of those two entities — a new denial, which is why it is on 11's breaking-changes list rather than read as a bug fix. Both entities are in `ExposedEntity` and in neither `ExposedEntityNoEdit` nor `ExposedEntityNoDelete`, so the gate is reachable. The finding's second half is also spent: `UsersApiController`'s three hand-written `catch (HttpSpecializedException)` blocks are deleted and `BaseApiController` is now the only catcher. The finding body below cites `victual.openapi.json:6003`; the schema is at `:11193` today. |
| A8 | **closed 2026-09-17** | **15-C7** resolved Q4 to 8.4: `composer.json` now `^8.4.1`, `PrerequisiteChecker::REQUIRED_PHP_VERSION` now `8.4.1` - not the rounder `8.4.0` the first pass declared, corrected the same day once locked dependencies `symfony/clock`/`symfony/translation` were found to require `>=8.4.1`. `Dockerfile`/nix still build on 8.5 deliberately (Q4's response: "keep shipping 8.5 in the image") - that is the declared floor and the serving image agreeing to differ on purpose, not the drift this finding named. |
| A9 | **closed 2026-09-17** | **15-C14** added it: the `lint` job runs `node --check` over every `public/**/*.js` (excluding the gitignored `public/packages`), right after the `php -l` sweep it mirrors. |
| A10 | **open, split** | `version.json` is `4.6.0`; the spec's `info.version` is still the literal `"xxx"`. The *fork's* version string is [17](plans/17-ecosystem-clients.md)-Q1 and still open. The *spec placeholder* is a different artefact and is now [14](plans/landed/14-contract-and-regression-scaffolding.md) piece 2's, landing with the `/api/openapi/specification` fix. |
| B1 | **closed** | 17 carries "Coupling 0 — the rename already broke both clients", and 16's Executed section carries a "Correction, recorded after the fact" block against its own "since no client exists" justification. Q4 answered it: no shim. |
| B2 | **closed** | The violation is recorded in the README's sequencing rule and in 16, with what it cost. |
| B3 | **closed** | The README's wave 4 opens on "07-Q6, answered before anything in this wave is scheduled" and names both branches; 03's Status row carries the possible parent column. |
| B4 | **closed 2026-08-30** | 03's Views and API sections now describe `product_groups_missing`, say why the obvious third-`UNION` design loses (every row of `stock_missing_products` is keyed by a `products.id` that `AddMissingProductsToShoppingList` dereferences, and a group shortfall has no product to name), and fold in Q2–Q4. Effort no longer prices the design Q1 declined. |
| B5 | **closed** | `CONTRIBUTING.md`, `PULL_REQUEST_TEMPLATE.md` and 05's schema section all state the three forms and the `@engine-exclusive` marker. |
| B6 | **open, and further out of date; now owned** | `db/pgsql/README.md:96` still says `[migrate\|views\|triggers]`. The review found four selectors; there are now five — `filter` landed with the `~` operator fix, per 14's section 3. Routed to [14](plans/landed/14-contract-and-regression-scaffolding.md), which owns the suite, to be fixed by whatever next opens that file. |
| B7 | **closed 2026-08-30** | All four corrected against `Grocycode.php`: four entity types with `r` for Recipes and 06's coming `l`, an opaque object id with 06-Q1's UUID named, single colon throughout, and DataMatrix restated as the default rather than the format. `label-printing.md`'s `grocy:` is now `grcy:`. The file carries a note on why a format that never changes still needs its documentation checked. |
| B8 | **closed 2026-08-30** | `purchase_product` takes `shopping_location_id`, the deferred `list` descriptor matches, and the spec records why the nicer noun loses — a sidecar parameter named `store_id` over a REST field named `shopping_location_id` pays the two-names-forever cost 15-Q5 refused, in a second place. |
| B9 | **closed 2026-08-30** | The three reversed sections are struck and annotated with what the sidecar does instead; the surviving reasoning (auth seam, permissions, tool shortlist) is left standing, since the spec's design reads as over-engineered without the integrated version to compare against. The API section's "purely additive routes" becomes the truer claim: no new routes at all, and what 02 needs from this repo is 14 piece 2. |
| B10 | **closed** | The Status table's "Depends on" column now carries 12 for 05 and 06, 12 and 14 for 08, and 11/13/14/15-C1 for 02. |
| B11 | **closed 2026-08-30** | 04 now says why the two differ: `db-import` replays already-shaped rows whose derived tables are consistent, so triggers would recompute from data that already reflects them; a seed dataset is raw master data and the triggers are what produce the derived state. |
| D1 | **closed as a pattern** | 13, 14 and 16 all carry Executed sections; the roadmap's preamble states the rule ("the Executed section, not the prose, is the record of the code"). |
| D2 | **closed 2026-08-30** | All eighteen other plans now carry one, and the roadmap states the rule alongside the Verification rule. Three turned out not to be "none" where the plan implied it was — 12's strict `transaction_type`, 07's one-level assumption going quietly false, and 16's, which is the case that motivated the mechanism. See 17's note on its item 2. |
| D3 | **closed** | All three README tables have a Status column. |
| D4 | no action | 09 remains the deliberate counter-example. |
| D5 | **partly adopted, now a stated rule** | The security sweep adopted it explicitly in its own preamble; the plans had not, and 15-C2's `StockReportsController.php:71,90,107` is the kind that rots first. The roadmap's ground rules now carry it for new citations. Existing bare line numbers are deliberately not swept — rewriting a hundred references by hand is the likeliest way to introduce a wrong one, and a stale line number is a weaker failure than a confidently wrong symbol. |
| H1 | **open, owned** | `.gitignore:5` is still `/.phpdoc`, anchored to the root, so `branding/.phpdoc/` stays untracked-and-unignored. Now **15-C13**. |
| H2 | not reproducible here | `git worktree list` shows one worktree in this checkout. A working-copy condition, not a repository one. |
| H3 | **closed 2026-08-30 — and it was the worst of these** | `update.sh` is now **15-C11**, with `.devtools/create_release_package.bat`. It is worth reading the note below rather than the row: two documents recorded a placement that had never been made, and each read as evidence for the other. |

**H3 is the one worth reading twice, because it is this review's own failure mode
happening to it.** The finding was that `update.sh` had no owning plan. The README's tail
now says it is "Added to 15's non-breaking table so it has a home", and the security
sweep's roadmap section says "**15**'s non-breaking table gains `update.sh` (S13) and
`.dockerignore`/non-root (S25...)". Plan 15 contains neither row. Two documents record a
placement that was never made, and each of them reads as evidence for the other.

S25 is worse than homeless, it is *contested*: the README assigns it to 10 ("10 is the
first plan to publish an image from the Dockerfile, so sweep S25 ... is 10's") and the
sweep assigns it to 15, and plan 10 does not mention `.dockerignore` either. So an item
with two claimed owners has none.

The pattern in both is the same one the roadmap already learned from S4 and wrote down:
*a statement about where work lives is a claim, and a claim nobody checks decays into a
false one.* The difference is that S4's inconsistency was caught in review. These two were
not, because nothing re-reads a routing sentence once it is written. That is the argument
for this table existing rather than for any single row in it.

Both are settled as of 2026-08-30. `update.sh` is 15-C11; S25 is plan 10's, in a section
of its own, on the roadmap's reasoning rather than the sweep's — 15's table is for cleanup
that can ride with any PR that opens the file, and hardening a production image cannot,
because it is meaningless before the image exists and mandatory in the same commit as it.
The sweep now points at 10 instead of claiming it.

**A closing note on this table, which is the part most likely to rot next.** It is a
snapshot with a date on it, and the failure it documents is precisely that snapshots
without dates get read as current. Every row was verified against the tree at `4fa97e8`
and several were closed the same day by the branch that added the table, so the states
here are not a work log — they are a claim about the tree, and the tree moves. Re-verify
before trusting a row, and prefer the plans: an item routed to 15-C11 or 10 lives in a
plan that gets read when the work is scheduled, which is more than this document can say
for itself.

## Method

Read all seventeen plans, the README, the prior review, the MCP spec, the PostgreSQL
port README, CONTRIBUTING, the PR template and the coverage README in full. For every
`file:line` or "X exists / does not exist" claim that a design decision rests on, opened
the file. Checked plan status against `git log` on `master`. Did not boot an instance —
this is a review of the plans' rigor, not a re-verification of the plans' verification
sections, and the one place that distinction matters is called out (F6).

## A. Plan-versus-code drift

| # | Finding | Where | Consequence |
|---|---|---|---|
| A1 | **Plan 13 is done and undeclared.** `DatabaseService::InTransaction()` exists; all seven stock entrypoints (`AddProduct`, `ConsumeProduct`, `InventoryProduct`, `OpenProduct`, `TransferProduct`, `UndoBooking`, `UndoTransaction`) plus the four pre-existing sites use it; label webhooks are collected and fired after commit exactly per Q1's response; `DatabaseImporter::Import` wraps truncate, trigger toggling and copy in one target-side transaction; `rollback-tests.php` runs in CI as the fourth suite phase, which is 13's verification item 3 "committed rather than run once". | `services/DatabaseService.php:221`, `services/StockService.php:290–2291`, `services/Database/DatabaseImporter.php:90`, `.devtools/pgsql/run-tests.sh` | Plan 13 still says "Status: draft for review" and has no Executed section. README table row 13 has no status. 15-C10 ("revisit after 13") is now unblocked and nothing says so. |
| A2 | **Wave 0 is done and undeclared.** Dockerfile, compose file, `bin/victual-migrate`, `run-tests.sh` with committed view seeds (`view-tests/01–05`), `migratedifftest.php`, and `.github/workflows/tests.yml` all exist. This is 14 piece 1, piece 3, piece 4 and 10's pulled-forward CLI. | `Dockerfile`, `docker-compose.yml`, `bin/`, `.devtools/pgsql/`, `.github/workflows/tests.yml` | Plan 14 says "draft for review"; its Today section still opens with "the fork has exactly one safety net and it is entirely manual". Plan 10's Today section still says migrations "only run from `GET /`" without noting the CLI exists. |
| A3 | **13-Q2's recorded decision does not match the implementation, and the implementation is better.** Q2 chose "depth counting"; the code asks `PDO::inTransaction()` instead, with a docblock explaining that a counter would be blind to `DatabaseMigrationService`'s own raw transactions. Plan 10's "second seam" paragraph still warns that the helper must "count depth rather than assume it opens the outermost transaction". | `services/DatabaseService.php:207–215`, `docs/plans/13:242–254`, `docs/plans/10:236–242` | The decision record says one thing, the code another. Update Q2's response and 10's seam note to the mechanism that shipped; it resolves 10's worry outright. |
| A4 | **`InTransaction`'s docblock forward-references a dialect method that does not exist.** "See `DatabaseDialect` for the per-engine locking used around migrations." `DatabaseDialect` has no `WithMigrationLock` or any lock method; that is plan 10 work not yet started. | `services/DatabaseService.php:213–215`, `services/Database/DatabaseDialect.php` | A reader following the pointer finds nothing. Either reword to "will live on the dialect (plan 10)" or leave a `@see` that resolves. |
| A5 | **The `/api/recipes/{recipeId}/copy` mismatch is not a mismatch.** 14 and 11 state it is in the spec with no route. `routes.php:237` registers it, `RecipesApiController::CopyRecipe` implements it, and `git log -S` traces it to a 2021 upstream commit. The only real route/spec gap is `/api/openapi/specification`, absent from the spec — that one is confirmed. | `routes.php:154,237`, `victual.openapi.json:3548`, `docs/plans/14:59–66,176–181`, `docs/plans/11:242–244` | 14's "two mismatches in opposite directions" argument for a set-comparison parity check loses one direction. Keep the check (it is right regardless), fix the prose, and fix the spec for `/openapi/specification`. |
| A6 | **Plan 12 has not started, and the code confirms every one of its premises.** 157 `console.error` handlers (12's count, exactly); no `request()` core, no `timeout`, no `onerror` in `public/js/victual.js`; the `DeleteUserePictureOnSave` typo and the `transaction_type`/`transactiontype` mismatch are both still present, each annotated in-code as known. | `public/js/victual.js`, `public/viewjs/userform.js:157`, `controllers/Api/StockApiController.php:344` | Nothing wrong — this is wave 1 track B and the plan is accurate. Recorded so the next reader knows the four "latent bugs" are still latent, not fixed by the defects pass. |
| A7 | **Plan 11 has not started; `ExposedEntityEditRequiresAdmin` is still an empty enum with live call sites.** `UsersApiController` remains the only controller catching `HttpSpecializedException`. | `victual.openapi.json:6003`, `controllers/Api/UsersApiController.php:35,217,269` | Accurate to the plan. The empty enum is the one item 11-Q6 called "definitely wrong" to leave; it is a five-minute change that does not need the rest of 11 and could land now. |
| A8 | **PHP floor: three declarations, two values, and the resolution is decided but not applied.** `composer.json` pins `8.5.*`, `PrerequisiteChecker::REQUIRED_PHP_VERSION` is `8.5.0`, the Dockerfile is `php:8.5`, CI runs 8.4 with `--ignore-platform-req=php`, and the `run-app` skill patches the constant with `sed` on every boot. 15-Q4 decided 8.4. | `composer.json:3`, `helpers/PrerequisiteChecker.php:19`, `Dockerfile:12`, `.github/workflows/tests.yml`, `.claude/skills/run-app/SKILL.md` | A decided one-liner that every tool in the repo works around instead. 15-C7 should be pulled out of 15 and landed alone — it is the cheapest rigor win in the tree. |
| A9 | **14 piece 3 specifies `node --check` over every `.js`; CI does not run it.** | `docs/plans/14:185`, `.github/workflows/tests.yml` | Minor. Either add it or amend the plan. |
| A10 | **`version.json` says `4.6.0` and the OpenAPI document's `info.version` is the literal `"xxx"`.** 17-Q1 (what version string does the fork ship) is unanswered; 16 did not touch it. | `version.json`, `victual.openapi.json:6` | The iOS client's version gate is the only consumer, and after A/B2 below it cannot reach this field anyway. Still: the spec's version should not be a placeholder in a tree that now runs a contract suite. |

## B. Cross-plan contradictions

| # | Finding | Where | Consequence |
|---|---|---|---|
| B1 | **16's breaking renames rest on "no client exists"; 17 names two.** `GROCY-API-KEY` → `VICTUAL-API-KEY` and `grocy_version` → `victual_version` are recorded in 16's Executed section as justified "since no client exists". 17 measures Grocy-SwiftUI at 47 of 48 endpoints working and the Home Assistant integration fully covered, both sending `GROCY-API-KEY`, the iOS app reading `grocy_version.Version`. 17 also states "both clients work against the fork today", which stopped being true when 16 merged. | `docs/plans/16:290–296`, `docs/plans/17:20–22,61,88–96`, `app.php:96` | Every request from either stock client now answers 401. 17's coupling 2 analysis ("one warning banner") assumed the key still existed; a missing key is a decode question in Swift, not a banner. 17 needs a reconciliation section against 16's Executed section, and 16 needs its justification corrected. Cheapest mitigation, if wanted: accept `GROCY-API-KEY` as an alias and emit both version keys until 17 decides per client. |
| B2 | **The README's own sequencing rule was violated by the corpus.** "17 before 11, 16 and 10 — the first two break third-party clients and 17 is where the cost of each candidate decision is written down." 16 landed with 17's three questions blank. | `docs/plans/README.md:70–75`, `docs/plans/17:263,275,286` | The rule is right; it was not followed. Either answer 17-Q1–Q3 now, or record in the README that 16 pre-empted 17 and what that cost. |
| B3 | **Wave 4 schedules 07 as written; 07-Q6 says 07 may collapse into 03.** Q6's response: "settle this before any of 07 starts — it decides whether 07 is the largest item on the roadmap or one of the smallest", and the likely answer is nested `product_groups` (03's territory) with `parent_product_id` left at depth one. The README's Wave 4 text, 07's "large" sizing, and 03's scope were not revisited. | `docs/plans/07:177–202`, `docs/plans/README.md:184–190`, `docs/plans/03` | The largest item on the roadmap has an unresolved scope gate that the roadmap does not show. Add the gate to Wave 4 explicitly, and give 03 a note that it may inherit a `parent_product_group_id` column. |
| B4 | **Plan 03's body describes the design its Q1 response rejected.** The Views and API sections still specify a third `UNION` branch in `stock_missing_products`; Q1's response moves group shortfalls to a new view, and the MCP spec (§4, `missing_products`) relies on the response's version. | `docs/plans/03:34–40,53–55` vs `:78–82`; `docs/mcp-interface-spec.md:225–227` | An implementer reading top-down builds the rejected design. Rewrite the two sections. |
| B5 | **Migration shapes: one rule, three stale restatements.** `db/pgsql/README.md` (§Supported ways) defines three forms and two mandatory markers. `CONTRIBUTING.md:35–36`, `PULL_REQUEST_TEMPLATE.md` ("a portable `NNNN.sql`, or a per engine pair") and plan 05's schema section (no shape stated at all for four `ALTER`s and a new table) know only two. The repo's own `0256.sqlite.sql` is the third form. | `db/pgsql/README.md:63–90`, `.github/CONTRIBUTING.md:35`, `.github/PULL_REQUEST_TEMPLATE.md`, `docs/plans/05:31–52` | A contributor following CONTRIBUTING or the PR template will ship a lone engine file without the marker and be refused by `check-migrations.php` with no idea why. Update both, and have 05 state its shape. |
| B6 | **The suite is described as three-phase in the document that owns it.** `db/pgsql/README.md:96` lists `[migrate\|views\|triggers]`; `run-tests.sh:6` has four selectors including `rollback`, and the README's claim that "the other three all populate PostgreSQL by copying" is false for the rollback phase, which migrates PostgreSQL independently. The coverage README has it right. | `db/pgsql/README.md:96–101`, `.devtools/pgsql/run-tests.sh:6,250,284` | Stale by one commit; fix the two sentences. |
| B7 | **`docs/grocycode.md` contradicts the code and plan 06 on the format it is the authority for.** It says three entity types (code has four, `RECIPE = 'r'`); it mandates object ids match `[0-9]+` (06-Q1's accepted answer puts a UUID there); it says parts are "double-colon separated" while every example uses one colon; and it argues for DataMatrix over QR while 06 adds QR. `docs/label-printing.md:31` spells the magic `grocy:x:xxx` where the code emits `grcy`. | `docs/grocycode.md:20–35,61–63`, `docs/label-printing.md:31`, `helpers/Grocycode.php:22–34` | Tier 0 of the rename says the grocycode format is the one thing that must never drift, and its own documentation has. Fix before 06 extends the format, and add the Tier 0 reasoning's cross-reference to 06. |
| B8 | **The MCP spec uses the vocabulary 15-Q5 declined.** `purchase_product` takes `store_id`; the deferred `list` descriptor is `(id, name, store)`. 05-Q4 and 15-Q5 both decline `shopping_locations` → `stores`, and 17 notes Grocy-SwiftUI reads `objects/shopping_locations` by name. | `docs/mcp-interface-spec.md:252,276` | A sidecar parameter named `store_id` mapping to a REST field named `shopping_location_id` is exactly the two-names-forever cost 15-Q5 refused. Pick one name in the spec. |
| B9 | **Plan 02's body is superseded and still describes the integrated design.** The status line says so, but the Mount point, Where the code goes and API sections still specify `/api/mcp`, `controllers/Api/McpController.php` and `services/Mcp/`. The spec's gate line cites "15 C1" without the spec ever explaining what C1 is. | `docs/plans/02:37–48,108–117`, `docs/mcp-interface-spec.md:8–10` | Low risk because the status line is prominent, but the body should be struck through or trimmed to the decision record it claims to be. |
| B10 | **README dependency table disagrees with README prose and with the plans.** Table rows 05, 06 and 08 show "Depends on: —"; the README's own blocking list and the plans' headers say 12 (and 14 for 08). | `docs/plans/README.md:16–19` vs `:55–56` | Whoever reads only the table schedules 05/06/08 ahead of 12. |
| B11 | **Plan 04 and `bin/victual-db-import` take opposite trigger stances, unacknowledged.** 04's importer is designed to "go through LessQL so triggers fire"; the existing importer deliberately disables triggers because it replays already-shaped rows. Both are correct for their purpose; 04 should say why it differs from the tool it claims to mirror. | `docs/plans/04:41–45`, `db/pgsql/README.md:409` | Prevents the next author from "fixing" one to match the other. |

## C. Where the rigor is genuinely strong

Worth recording so the findings above are read in proportion.

- **Self-correction is written down.** 14-Q3 reverses an earlier answer about the CI
  image and says what the reversal costs (8.4 in CI vs 8.5 in the image). 14-Q4
  corrects a wrong reachability claim and names the real reason the decision stands.
  07-Q6 is a plan questioning its own premise, at the right moment. 13's verification
  item 3 corrects an earlier wording that overclaimed what `trigdifftest.php` proves.
  This is rarer than it should be and it is the single best property of the corpus.
- **Ground rules have teeth.** The additive-API rule, the dual-engine migration rule,
  and "verification means a booted instance" are each enforced by something that fails:
  `check-migrations.php`, the four-phase suite in CI, and the rollback tests that go
  through `StockService` on each engine. 14's "which of its inputs does each phase take
  on trust" question produced `migratedifftest.php`, which found a real defect the day
  it ran.
- **The transaction work matched its plan and improved on it.** Seven entrypoints,
  webhooks after commit with payloads built eagerly, validation outside the
  transaction, importer atomic — every Q1–Q6 answer is visible in the code, and the one
  deviation (A3) is documented in the code with a better reason than the plan had.
- **16 is the model for an Executed section.** What landed, what the survey missed,
  what deliberately did not move, verification actually run, and an outstanding list
  with reasons. Plans 13 and 14 should get the same treatment (A1, A2).
- **The MCP spec's "no state, no credentials at rest, two replicas indistinguishable"
  constraints** are the right ones for the k3s target and are stated as testable
  properties rather than aspirations.

## D. Rigor gaps that are structural rather than stale

| # | Finding | Recommendation |
|---|---|---|
| D1 | **Verification sections specify what to run; only plan 16 records what was run.** 13's seven checks were evidently executed (the rollback tests exist, the suite passes) but the plan carries no results, no dates, no engine versions. The PR template asks for "what was actually run", so the evidence exists in PR bodies — but the plan is where the next person looks. | Adopt 16's Executed/Verification pattern as mandatory at close-out: one section per landed plan, results not intentions. |
| D2 | **17's "each plan carries a client-impact line" mechanism has no adopters.** Not one of the sixteen other plans has one, including 16, which is the plan with the largest client impact. | Add the line to every plan's API section now, even where it reads "none"; 17 says absent is not the same as none, and 16 proved it. |
| D3 | **The README status table has no "done" state for hardening plans.** The Meta table has one ("done in the codebase"); the Hardening table's Size column doubles as status and cannot express completion. | Add a Status column to all three tables; populate from `git log`. |
| D4 | **Plan 09 is the one plan with all questions unanswered, by design, and it is fine.** Its deferral note says exactly why and what data would unblock it. Recorded here as the counter-example: an unanswered question with a stated reason is rigorous; 17's three unanswered questions with a landed dependency (B2) are not. | None. |
| D5 | **Line-number references are already rotting.** The prior review said this of itself ("the column names the file rather than a location to trust"); the plans did not inherit the caveat. The MCP spec's `ApiKeyAuthMiddleware.php:50` is already `:49`. | Prefer symbol names over line numbers in plans (`ApiKeyAuthMiddleware::IsValidApiKey` call, not `:50`). Where a line is quoted, quote the code alongside it so the reference survives a shift. |

## E. Housekeeping

| # | Finding | Fix |
|---|---|---|
| H1 | `branding/.phpdoc/` is untracked and not ignored: `.gitignore:5` is `/.phpdoc`, anchored to the root. The intent (phpDocumentor output is generated on demand and never committed) is stated in `phpdoc.dist.xml` and `CONTRIBUTING.md`. | Change the rule to `.phpdoc/` (unanchored, directory-only) so any nested run is ignored. Consider also `/phpdoc.xml` → already present. Nothing to delete from history; nothing has been committed. |
| H2 | Two stale worktrees under `.claude/worktrees/` (`git worktree list` marks both prunable); `.claude/` is partially tracked (skills) and partially ignored (settings). | `git worktree prune`. Not a rigor issue. |
| H3 | `update.sh` and `.devtools/create_release_package.bat` ship in a fork that cuts no releases; 16 routed the deletion question to 15, which does not list it. | Add to 15's non-breaking table so it stops being homeless. |

## Recommended order

1. **Reconcile 16 and 17** (B1, B2): answer 17-Q1–Q3, add a reconciliation section to
   17 against 16's Executed list, and decide today whether `GROCY-API-KEY` and
   `grocy_version` get compatibility aliases. This is the only item with a live
   external consequence.
2. **Close out 13 and 14** (A1, A2, A3, A4, D1, D3): Executed sections, README status,
   the two docblock/plan text fixes. One documentation commit.
3. **Fix the factual errors that carry design weight** (A5, B4, B7): the recipe-copy
   claim, 03's body, `docs/grocycode.md` and `docs/label-printing.md`.
4. **Unify the migration-shape rule** (B5, B6): CONTRIBUTING, PR template, 05, and the
   two stale sentences in `db/pgsql/README.md`.
5. **Land the decided one-liners that everything works around** (A7, A8): the PHP floor
   to 8.4 in both places, and `ExposedEntityEditRequiresAdmin` populated or deleted.
   *Both done — A7 with plan 11 on 2026-09-04 (populated), A8 as 15-C7 on 2026-09-17.
   See the state table above.*
6. **Gate wave 4 on 07-Q6** (B3) in the README, and add client-impact lines everywhere
   (D2).
7. **`.gitignore` for nested `.phpdoc/`** (H1).

Items 2–7 are documentation and one-line code changes; together they are a single short
session. Item 1 is a decision.

## What this review did not do

It did not boot an instance or re-run the suite; it trusted CI's green on `c998aaf5`.
It did not evaluate the MCP spec's description of the `2026-07-28` protocol revision
against the protocol's published text — every downstream design choice in that spec
rests on that description, and it carries no citation, so that check is worth doing
before the sidecar is built. It did not read the two external clients' source; 17's
claims about them were taken as written, except where the fork's own tree contradicts
them (B1).
