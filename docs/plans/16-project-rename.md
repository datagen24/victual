# 16. Project rename

**Goal:** Give the fork its own name — repo, branding, and internal
identifiers — landing the would-be-breaking parts while nothing is deployed
to break.
**Depends on:** nothing — and the internal renames depend on *happening before
the first deployment*, not on any other plan (the earlier lean on
[15](landed/15-deliberate-cleanup.md)'s breaking batch is the fallback, not the plan).
**Status:** **landed in the codebase.** Direction settled (Q1), namespaces
checked (Q3), and Tiers 1, 2 and 3 all executed — see [Executed](#executed)
below for what landed, what the survey missed, and what deliberately did not
move. No instance of this fork was deployed anywhere when it landed — the
household runs upstream grocy — which is exactly why the breaking parts went in
now rather than waiting for [15](landed/15-deliberate-cleanup.md)'s batch. What remains
is outside the repository: the GitHub repo rename and the registry/domain claims
of Q3, which are done at announcement time, not by a commit.

## Background

The fork has diverged past the point where "grocy fork" describes it: nested
product-group taxonomy, directed substitutions with conversion factors, additive
fulfillment fields, density conversions on the horizon, and an agent (Hermes)
maintaining the knowledge base via MCP. Breaking changes are now deliberate
policy rather than accidents. A fork that keeps the parent's name reads as
derivative, confuses search and issue triage, and risks trademark friction.
Precedent for clean renames: Jellyfin (Emby), Forgejo (Gitea), Valkey (Redis),
LibreOffice (OpenOffice).

Naming direction: the project is provisioning, not list-keeping — stores,
minimums, substitutions, resupply. The nautical/victualling frame fits both the
domain and the maintainer.

## Candidates

| Name | For | Against |
|---|---|---|
| **Victual** (chosen direction, core name) | Names the domain (the goods themselves); no spelling variance, unlike the -er forms; softer and more approachable; pairs naturally with *Victualer* as the actor name (below) | Dictionary word, so more collision-prone in package registries than the rarer -er form; pronunciation ("VIT-l") doesn't match spelling |
| **Victualer** (chosen direction, automation actor) | Names the system's role; admiralty pedigree (victualling bills, the Victualling Board) maps exactly to the problem domain; short forms come free (`vict`, `victd`, "victualling yard" for the knowledge base) | Spelling variance (victualer/victualler); same pronunciation gap |
| Provisioner | Plain-English, nautical resonance | Generic; heavy namespace collision in infra tooling (Terraform provisioners etc.) |
| Steward | Personal, crew-role framing | Generic; collisions likely |
| Larder | Evocative, short | Prior apps by this name; less distinctive |
| Mise | Chef-insider, very short | Opaque to non-cooks; collision-prone |
| Galley | Kitchen + ship | Heavily overloaded in software |

The pairing resolves each name's main objection with the other: Victual alone
"names the goods, not the actor" — so the actor name goes to the actual actor.
Automated processes write log entries as **the Victualer**; humans appear as
themselves. That split also does real work: it distinguishes machine writes from
human ones in the audit trail, and [02](02-mcp-endpoint.md)'s sidecar wants
exactly such an actor identity when MCP writes arrive. Meanwhile the
spelling-variance problem is contained to an in-app label where a canonical
spelling is simply picked, instead of afflicting the repo, domain, and package
names.

## Codebase touchpoints

Survey of everything carrying the old name, tiered by what renaming it breaks.
Counts from the tree as of this plan's writing.

### Tier 0 — never rename (wire formats)

- **The grocycode magic `grcy:`** — **held, as intended** (`helpers/Grocycode.php`,
  [docs/grocycode.md](../grocycode.md)). No fork instance is deployed, but the
  household's *upstream* grocy instance prints labels with this magic, and
  `bin/grocy-db-import` exists precisely to bring that instance's database —
  and its shelf full of printed labels — into the fork. Renaming the magic
  breaks that migration path and every upstream-printed label. It is a wire
  format: keep `grcy` forever, documented as a historical artifact — at most
  accept a second magic alongside it someday. Do not let a rename sweep "fix"
  this. The reasoning now lives where someone would look for it, in
  [docs/grocycode.md](../grocycode.md) itself, and the format's *name*
  ("Grocycode", including its per-locale translations) is held with the magic:
  it names the wire format, not the project.

### Tier 1 — would break deployed instances; free while none exist — **all landed**

- **Env-var prefix `GROCY_*`** — every setting is overridable via the prefix
  (`helpers/extensions.php:244`), and `GROCY_DATAPATH` is load-bearing at boot
  (`app.php:14`). Renaming the prefix breaks every deployment's environment.
- **In-database identifiers**: `grocy_user_setting()`,
  `grocy_next_internal_recipe_id`, `grocy_sqlite_percent_w`,
  `grocy_mealplan_week_name` (`db/pgsql/baseline/`, referenced from views and
  migrations). On a live database these would need a rename-migration pair;
  with none deployed, a baseline edit plus a sweep of the migrations that
  reference them suffices — but only while nothing has run them.
- **`grocy.db` default filename** (`services/Database/SqliteDialect.php:154`) —
  a deployed volume would hold the file under that name and need a fallback
  probe; renamed before first deployment, it is a one-line change.
- **DB defaults** `DB_NAME`/`DB_USER` = `grocy` (`config-dist.php:33-34`) and
  the compose file's matching PostgreSQL service values. Defaults only, but
  changing them strands anyone who relied on them.
- **`bin/grocy-migrate` / `bin/grocy-db-import`** — ops entry points that
  would live in cron jobs and runbooks once deployed; today they live only in
  docs and plans, so a rename is a doc sweep.
- **Session cookie `grocy_session`**
  (`services/SessionService.php:11`) — renaming logs every user of a deployed
  instance out once; with none, free.

### Tier 2 — internal, non-breaking but high-churn — **all landed**

- **PHP namespace `Grocy\`** — 72 files plus composer autoload. Safe but a
  merge-conflict bomb for every open branch; do it at a quiet point between
  waves, as one mechanical commit with nothing else in it.
- **JS global `Grocy.` and the `public/js/grocy_*.js` files** — coordinate with
  [12](landed/12-frontend-shared-core.md), which rewrites those files wholesale
  anyway. Renaming before or with 12 avoids touching them twice.
- **`grocy.openapi.json`** — filename and `"title": "Grocy REST API"`. The API
  paths themselves carry no name (all `/api/...`), so this is cosmetic — but
  the filename is referenced from code and docs.
- **Localization msgids** — UI source strings embed the name ("About Grocy",
  "Do you find Grocy useful?"). Changing a msgid orphans that string's
  translation in every locale. As a hard fork with no live Transifex feed this
  is tolerable, but it is a known cost, not free.

### Tier 3 — free, and some should change regardless of the rename — **all landed**

- **User-Agent on barcode API calls** (`services/StockService.php:849`) sends
  `Grocy/<version> (https://grocy.info)` — advertising *upstream's* URL on this
  fork's traffic. That misattributes traffic to upstream and should change as
  soon as there is a name to change it to; arguably before.
- **README, about page, logo** — **done for the logo**: the Victual branding
  set (masters in `branding/`, production copies in `public/img/`) replaced
  the four upstream images and the README's hotlink of upstream's SVG; the
  three views showing the logo were resized from upstream's 3.8:1 box to the
  new lockup's 2.5:1, and the PWA manifest colors moved to the brand green
  `#174B3A`. The about page and `grocy.info` links still move to the
  attribution section (Q5) with the text rename.
- **The MCP sidecar repo** — the interface spec already anticipates this plan:
  the repo takes the working name `grocy-mcp` "with the real name settled
  before the first tagged release" ([mcp-interface-spec.md](../mcp-interface-spec.md),
  §Repo home). Settling Q1 unblocks naming it correctly from the start.
- **Dev tooling image `grocy-fork-dev`** (`db/pgsql/README.md`, Dockerfile /
  compose) — dev-only, rename any time.

## Open questions

1. **Final name.** Decision deliberately deferred at least one day past initial
   enthusiasm.

   > **Response:** **Victual** as the core/project name, **Victualer** as the
   > automation actor — automated processes write log entries as the Victualer.
   > The pairing beats either name alone (see Candidates). Recorded as the
   > direction, subject to the one-day cooling rule and to Q3's namespace
   > verification; if either sours it, the table stands ready.

2. **Spelling.** American *victualer* or British *victualler*? Whichever is
   chosen, register/redirect the other where possible (repo redirects, domain).

   > **Response:** Mostly dissolved by Q1 — *Victual* has one spelling, and it
   > is the name on the repo, domain, packages, and env prefix. For the actor
   > label, American *Victualer*, matching the maintainer's locale; claim both
   > spellings wherever a claim is cheap (domains, org names).

3. **Namespace claims.** GitHub org/repo, domain(s), package names. Verify
   clear before announcing; claim both spellings. Note *victual* is a
   dictionary word, so check registries (GitHub, npm, Docker Hub, domains)
   more carefully than the rarer *victualer* would need.

   > **Response:** Checked 2026-08-29:
   >
   > | Name | GitHub user/org | npm | Docker Hub namespace |
   > |---|---|---|---|
   > | victual | **taken** (`Victual`, u/73016391) | free | **taken** (idle user "aXe_ru", joined 2025-07, zero repos) |
   > | victualer | free | free | free |
   > | victualler | **taken** (`Victualler`, u/105707722) | free | free |
   >
   > The squats don't block the plan: the repo renames in place to
   > `datagen24/victual` (GitHub auto-redirects the old path), and the Docker
   > image lives under the maintainer's own namespace either way. They do rule
   > out a vanity `victual` org/namespace — if one is ever wanted, *victualer*
   > is the clean claim on all three registries, which pleasingly matches the
   > actor name. Claim the free names (npm `victual`, Docker/GitHub
   > `victualer`) at announcement time, not before.
   >
   > Domains, checked 2026-08-29 via NS delegation (NXDOMAIN at the TLD =
   > unregistered; method calibrated against grocy.info and jellyfin.org):
   > **victual, victualer, and victualler are all unregistered on .io, .app,
   > and .dev** — nine for nine. Two caveats: registry-reserved names also
   > NXDOMAIN (unlikely for these, but the registrar page is the final word),
   > and Google Registry prices dictionary-word .app/.dev domains at premium
   > tiers, so `victual.app`/`victual.dev` may cost more than list price.
   > `victual.io` is the natural primary; register the -er/-ller variants as
   > redirects per Q2.

4. **Scope of the rename.** Repo and branding only, or also internal
   identifiers — DB name defaults, config env-var prefixes (`GROCY_*` → ?),
   Docker image name, user-agent strings on barcode API calls? Internal renames
   are the breaking part; decide whether they land with the rename or lag
   behind a compatibility window.

   > **Response:** Split per the touchpoint tiers above, but the timing
   > changed on learning there are **no deployed instances of this fork** —
   > the earlier instinct to park Tier 1 on [15](landed/15-deliberate-cleanup.md)'s
   > breaking batch assumed something running that could break. Nothing is.
   > So: the outward rename (repo, branding, docs, Tier 3) happens first, and
   > Tier 1 — `GROCY_*` prefixes, DB defaults, database identifiers, the
   > `grocy.db` filename, bin scripts, the session cookie — follows as part of
   > the rename itself, **before the first deployment ever happens**, while
   > "breaking" is still a category error. Every day of heavy development
   > mints more code under the old identifiers; the batch-with-15 fallback
   > remains only if the rename somehow slips past first deployment. Tier 2
   > lands opportunistically (namespace between waves, JS global with
   > [12](landed/12-frontend-shared-core.md)). Tier 0 never lands.

5. **Upstream attribution.** grocy is the origin and its license terms follow
   the code. Decide the attribution wording in README/about and whether any
   upstream sync relationship survives the rename (likely already dead given
   hard-fork status — confirm and state it).

   > **Response:** Largely done already: `LICENSE.md` is structured for exactly
   > this (upstream code MIT © Bernd Bestel, fork changes BSD 3-Clause), and
   > the README leads with the hard-fork attribution. The rename keeps both,
   > moves the remaining `grocy.info` links out of the UI chrome into an
   > attribution block on the about page, and states plainly that no upstream
   > sync relationship exists. Confirmed: there is none to preserve.

6. **Migration story for existing instances.** Do current deployments (this
   household's) rename in place, or does the rename coincide with a version
   boundary that's already breaking anyway? Bundling it with an existing
   breaking release costs nothing extra.

   > **Response:** Dissolved: there is no existing instance of the fork to
   > migrate — the household still runs upstream grocy. The migration story is
   > therefore the one that already exists (`bin/grocy-db-import` from the
   > upstream database), and it must work under the *new* names, which is one
   > more reason to finish Tier 1 before that import ever happens. The
   > import's inputs — upstream's schema, and grocycodes on printed labels —
   > keep their upstream names by definition (Tier 0).

7. **Timing.** Rename before or after the next implementation work lands?
   Renaming first means all new docs/plans carry the new name; renaming later
   means a sweep. Earlier is cheaper.

   > **Response:** Sooner. Every plan doc and Response block written from here
   > forward either carries the new name or gets swept later, and the MCP
   > sidecar repo is explicitly waiting on this decision for its real name.
   > Once Q1 survives its cooling day (Q3's registry checks are done and
   > don't block), the outward rename (Tier 3 + repo/branding) proceeds before
   > the next implementation wave starts, with Tier 1 riding along per Q4 —
   > the whole rename lands while there is still nothing deployed to break.

## Client impact

**The largest on the roadmap, and it was recorded as "none".** This section exists because
[17](17-ecosystem-clients.md)'s item 2 — every plan carries a client-impact line, even
when it reads "none" — was written the same day this plan landed without one, and this is
the plan that proves the mechanism is worth the row. Tier 1 renamed the `GROCY-API-KEY`
request header and the `grocy_version` response field on the recorded justification that
no client exists; that was true of *deployed instances of this fork* and false of
*clients*, of which 17 tracks two, both sending the header on every request and one
reading the version field. Every request from either now answers 401.

Neither break is a path change, so a manifest of routes would have passed. That is 17's
argument for widening item 1 from paths to **request headers and response keys**, and it
is this plan that supplied the evidence. The decision that follows is 17's Q4 — answered
*no shim*, since every client that will exist is first-party — and the whole exchange is
written up as Coupling 0 there. See also the Correction block under Executed.

## Constraints

- License continuity from upstream grocy is non-negotiable.
- The upstream-to-fork migration path (`bin/grocy-db-import` from the
  household's upstream grocy database) must work under the new names, on both
  engines — there is no deployed fork instance to protect, but there is a
  future import to keep working.
- The rename itself must not silently change any API surface — if env-var
  prefixes or endpoints change, that is a called-out breaking change per
  ground rules, not a side effect.
- The grocycode wire format (`grcy:` magic) is out of scope permanently
  (Tier 0): printed labels outlive branding.

## Executed

Landed 2026-08-29 on `claude/plan-16-remaining-steps-cb7a43`, in the order the
tiers argue for: outward first, then the breaking identifiers, then the
high-churn internals, each as its own commit.

**Tier 3 — outward.** Both barcode-lookup User-Agents (`StockService` and the
Open Food Facts plugin) now name Victual and this repository instead of
upstream's URL. The about page drops the "Do you find Grocy useful? / Say
thanks" block from the system-info tab and gains the Q5 attribution footer in
its place: hard fork of grocy, the two licences and who holds each, the plain
statement that no upstream sync relationship exists in either direction, and
the say-thanks link kept there rather than in the UI chrome. Plus README prose,
the package name, the phpDocumentor title, the dev image (`victual-dev`), and
the MCP spec, whose sidecar repo is now `victual-mcp` outright rather than
under a working name.

**Tier 1 — breaking, and free.** `GROCY_*` → `VICTUAL_*` across the whole
setting surface; the four database helper functions in both engines, plus the
PostgreSQL session variables (`grocy.user_id`, `grocy.in_quc_*`) the survey did
not list; `data/grocy.db` → `data/victual.db` and the per-locale demo file;
`DB_NAME`/`DB_USER` and every matching value in compose, CI and the suite;
`bin/victual-migrate` and `bin/victual-db-import`; and the session cookie.

**Tier 2 — internal.** The `Grocy\` namespace as one mechanical commit of its
own; the frontend global, its `public/js/grocy*.js` and `public/css/grocy*.css`
files and the two CSS classes; `victual.openapi.json` with its title and a
`license` block that no longer claims grocy.info is a licence; and the three
name-bearing msgids, with two now-unused entries dropped.

### What the survey missed

Four things in the tree carried the name and were not in the tiers above. All
four are the same category as items that were, and all four landed with them:

- **The API key header `GROCY-API-KEY`** (`app.php:96`) — exactly the session
  cookie's category. Now `VICTUAL-API-KEY`, in the app, the OpenAPI document
  and the MCP spec that accepts it "for parity".
- **`grocy_version` in `GET /api/system/info`** — the only response *field* in
  the whole API surface carrying the name, now `victual_version`. This is a
  breaking API change and is called out as one per the roadmap's ground rules,
  not slipped in; the justification is Tier 1's, since no client exists.

  > **Correction, recorded after the fact.** "Since no client exists" is true of
  > *deployed instances of this fork* — which is what Tier 1 is about, and why
  > everything else in it was free — and false of *clients*.
  > [17](17-ecosystem-clients.md) tracks two, Grocy-SwiftUI and
  > custom-components/grocy, and both use both of these. The header is the worse
  > of the two: it is the only API authentication grocy has, so an unmodified
  > client does not get a warning banner, it fails to authenticate at all. The
  > roadmap's sequencing rule is "17 before 11, 16 and 10" precisely so that this
  > cost is written down before the decision; 17 was written the same day and
  > read after. Nothing was deployed, so no one lost anything — what was lost is
  > that the compatibility decision got taken by a rename sweep instead of on
  > purpose. It is now [17](17-ecosystem-clients.md)'s Q4, with the options and
  > their costs, and the shim it discusses is a single string in the
  > `ApiKeyHeaderName` container binding if it is wanted. Nothing here needs
  > reverting; the record needed correcting.
- **User-visible strings**: the PWA manifest `name`/`short_name`, the iCal
  export's `PRODID` and its `Grocy.ics` attachment filename, the thermal
  printer banner, page titles, and the "Unable to run Grocy" boot message.
- **The 500 page**, which sent people to *upstream's* issue tracker for
  failures in this fork's code.

### What the verification missed

- **Every feature flag, dropped from the UI and the API.** The `GROCY_FEATURE_FLAG_`
  prefix is 19 characters and `VICTUAL_FEATURE_FLAG_` is 21, and both loops that
  select the flag constants compared only the first 19 — `BaseController`'s and
  `SystemApiController::GetConfig`'s. Neither ever matched again, so
  `Victual.FeatureFlags` was `{}` in every page and all 64 flag checks in
  `public/viewjs` read false: location tracking, price tracking, camera scanning,
  chore assignments and the rest were silently off in the browser while the
  server-rendered menus still offered them. `GetConfig` also stripped six
  characters where the prefix is now eight, so its keys would have been
  `_FEATURE_FLAG_*` had the comparison matched.

  This is the shape of regression a rename produces and a diff does not show: both
  loops still read as if they worked. It was found by the 2026-08-29 security sweep
  rather than by this plan's verification, which is the thing worth recording —
  nothing in the verification opened a page and looked for a field that should be
  there. It is fixed in the wave 0.5 hotfix as R1, with `str_starts_with` in place
  of both length comparisons, and 14 piece 2 gains a `/system/config` contract test
  so it cannot recur silently.

### What deliberately did not move

- **Tier 0**, widened slightly in the doing: the `grcy:` magic, and with it the
  name "Grocycode" and its per-locale translations ("Grocykood", "Grocy-код",
  "GrocyKoodi"). Those name the wire format, not the project. The reasoning is
  now recorded in [docs/grocycode.md](../grocycode.md) where a reader would look
  for it. `GROCYCODE_TYPE` keeps its stem for the same reason — it configures
  that format — and so reads `VICTUAL_GROCYCODE_TYPE` as an environment
  variable, which is accurate rather than awkward.
- **Every reference to upstream**: the fork attribution, `grocy/grocy` issue
  links, the upstream demos, r/grocy, the say-thanks URL, `LICENSE.md`'s MIT
  section, the `.tx` Transifex project (upstream's), the `mcp-grocy` prior art
  in the MCP spec's Appendix A, and the changelog, which is upstream's release
  record and not ours to rewrite.
- **`update.sh`**, which downloads and installs an upstream *release*. Renaming
  its strings would have made it look like this fork's updater, which it is
  emphatically not; it now carries a header comment saying so.
- **Inflected translations of the app name** in Estonian and Finnish
  ("Grocyt", "Grocyssäsi") — the known cost the Tier 2 note predicted, left
  rather than guessed at. Where the brand stood as its own word the translation
  was carried across instead of orphaned, in 14 locales.
- **The Victualer actor** (Q1). Naming automated writers "the Victualer" in the
  audit trail is an actor identity, not a rename: it needs a writer to attribute
  and belongs with [02](02-mcp-endpoint.md)'s sidecar, which is where the
  credential→user seam already lives.

### Verification

- Fresh SQLite database: migrations run clean to 256, and the app serves
  stockoverview, shoppinglist, recipes, mealplan, chores, products, calendar
  and about at 200 with demo data. The layout requests only `victual*` assets
  and each one serves 200.
- `GET /api` 200, `/api/openapi/specification` reports "Victual REST API",
  `/api/system/info` answers with `victual_version`, the manifest renders
  "Victual", and the iCal feed emits `PRODID:Victual` as `Victual.ics`.
- The differential suite passes all four phases against a real PostgreSQL 16:
  migration numbering, freshly-migrated state identical, all views identical,
  trigger behaviour identical across the eight scripts, and every failed write
  path rolled back on both engines. That exercises `bin/victual-migrate` and
  `bin/victual-db-import` under the renamed identifiers, which is the migration
  path the Constraints protect.

## Outstanding — for later review

Everything below is deliberately not done. Each line says why, and where it
goes instead. Nothing here blocks the codebase rename, which is complete.

### Outside the repository — announcement time, not a commit

Q3 answers these and says explicitly to claim them *at announcement time, not
before*, so they are not oversights and there is nothing to do in code:

- [x] **Rename `datagen24/grocy` to `datagen24/victual` on GitHub** — **done
      2026-08-29.** This was the one ordering dependency the code rename left
      behind: four places already wrote `https://github.com/datagen24/victual`
      — the two barcode-lookup User-Agents, the 500 page's issue link, and the
      OpenAPI `license.url` — and none of them resolved until the repository
      carried that name. They do now. GitHub auto-redirects the old path, so no
      existing clone had to be re-pointed.
- [ ] **Register `victual.io`**, with `victualer`/`victualler` as redirects per
      Q2. Q3 verified nine-for-nine unregistered on .io/.app/.dev, with the two
      caveats it records (registry-reserved names also NXDOMAIN, and Google
      Registry prices dictionary-word .app/.dev at premium tiers).
- [ ] **Claim npm `victual`, and Docker Hub / GitHub `victualer`.** The squats
      Q3 found (GitHub `Victual` and `Victualler`, an idle Docker Hub
      `victual`) rule out a vanity `victual` org but block nothing: the image
      lives under the maintainer's own namespace either way.

### Deferred to another plan

- [ ] **The Victualer actor** (Q1). "Automated processes write log entries as
      the Victualer" is an actor identity in the audit trail, not a rename:
      it needs a writer to attribute and a column to attribute it in. It
      belongs with [02](02-mcp-endpoint.md), whose sidecar already needs
      exactly such an identity when MCP writes arrive and already owns the
      credential→user seam. Landing it here would have meant inventing the
      write path it describes.
- [x] **`update.sh`.** Kept verbatim with a header at the time of the rename,
      pending the deletion question this note routed to
      [15](landed/15-deliberate-cleanup.md). That plan's C11 answered it: deleted,
      along with `.devtools/create_release_package.bat`, which packaged a
      release this fork does not cut. Neither file exists in the tree any
      longer.

### Accepted costs, recorded rather than fixed

- **Stale translations of the name.** Where the brand stood as its own word the
  translation was carried across, in 14 locales. Where it is inflected or
  compounded it was left: Estonian "Grocyt", Finnish "Grocyssäsi", and the
  app-name mentions embedded inside the Grocycode description in Russian,
  Hungarian, Hebrew and Lithuanian. This is precisely the cost the Tier 2 note
  predicted; fixing it means inventing grammar in languages nobody here reads,
  and there is no live Transifex feed to route it through. Revisit only if a
  translation workflow ever comes back.
- **`.tx/config` still points at upstream's Transifex project**
  (`o:grocy:p:grocy:*`), and `transifex_*.bat` still pulls `grocy.strings`.
  That is correct as long as translations come *from* upstream, which
  README.md says they do. It becomes wrong the day this fork runs its own
  localization project — at which point the config is rewritten, not renamed.
- **`VICTUAL_GROCYCODE_TYPE`.** The setting configures the Tier 0 wire format,
  so it keeps the format's stem. Reads oddly, is accurate, and renaming the
  stem would be exactly the sweep Tier 0 warns against.
- **The working copy and git remote still say `grocy`.** Both follow the GitHub
  rename above; neither is repository content.
