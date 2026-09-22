# 17. Ecosystem clients

**Goal:** Know, before each plan lands, which third-party clients it breaks — and hold a
standing decision for each about whether this fork forks it, replaces it, or lets it go.
**Depends on:** [14](landed/14-contract-and-regression-scaffolding.md) supplies the mechanism.
[11](11-api-error-handling.md) and [16](16-project-rename.md) are the two plans that break
clients hardest, and both are early. [10](landed/10-cold-start-statelessness.md) has a conflict
with the Home Assistant integration that is not an API-compatibility problem at all.

**Status:** premise replaced 2026-09-15 by
[ADR-0024](../adr/0024-the-fork-writes-its-own-clients.md) (accepted the same day): the
fork writes its own clients, so what follows is a catalogue of couplings those clients
must handle rather than breaks to avoid.

Earlier: draft for review — **and already overtaken on [16](16-project-rename.md)**, which
landed on 2026-08-29, the day this was written, ahead of the roadmap's own "17 before 11,
16 and 10" rule. Two of the breaks below are therefore past tense: the API key header and
the `/system/info` version field are renamed in the tree today.

Coupling 0 records what that costs and what the options are; the three open questions at
the end are all still unanswered, and Q1 is now being asked after the event rather than
before it. The rule still holds for [11](11-api-error-handling.md) and
[10](landed/10-cold-start-statelessness.md), which are both still ahead.

**Answered 2026-08-29, and the premise moved.** Q2 and Q4 now carry responses, and they
change what this document is for. Both tracked clients are being replaced by first-party
ones — a Home Assistant integration built here and a Swift client module written here —
so the question stops being "which third-party client does this break" and becomes "what
does the fork owe the clients it owns".

Q2's answer is the larger of the two: the Home Assistant path is not a polling HTTP client
at all but MQTT state publication from the server to a broker that is already always-on in
the cluster. This dissolves Coupling 1 rather than mitigating it.
[18](18-mqtt-state-publication.md) is the plan that came out of it. Q1 and Q3 are still
open, and Q3 is now half-answered.
## Why this is a plan and not a wiki page

Every other plan is held to "the API is additive — existing endpoints keep their response
shape". That rule protects *this fork's own web frontend* and nothing else, because the
frontend is the only client in the repository. Two clients live outside it, and three of
the roadmap's plans reach them without breaking a single response shape:

- [11](11-api-error-handling.md) changes status codes on ~74 routes and drops
  `error_details` from nine list operations. Response *shapes* survive; error *contracts*
  do not.
- [16](16-project-rename.md) renamed the project, and did it *before* this document was
  read. It moved the API key header from `GROCY-API-KEY` to `VICTUAL-API-KEY` and the
  `/system/info` field from `grocy_version` to `victual_version`. Neither changes a
  response *shape* in the sense the additive rule polices; the first stops both clients
  authenticating at all. Coupling 0.
- [10](landed/10-cold-start-statelessness.md) makes the pod scale to zero. The Home Assistant
  integration polls every thirty seconds and would keep it awake forever. Nothing about
  that is visible in an API contract.

So the additive rule is necessary and not sufficient.

## Scope

Two clients are tracked: **Grocy-SwiftUI** (iOS) and **custom-components/grocy** (Home
Assistant).

**grocy-py** is not a tracked client — it is a decision, and only because the Home
Assistant integration needs a Python client. It gets a section below and question 2, and
depending on that answer it may leave this document entirely. **It has.** Q2's answer is
that the ambient read path is MQTT rather than HTTP polling, so there is no Python client
to depend on or reimplement — see the section below, kept for the reasoning rather than
for the decision.

Out, and recorded so the boundary is deliberate rather than an oversight:

- **[Grocy Android](https://github.com/patzly/grocy-android)** (GPL-3.0, v3.8.3 Jan 2026,
  the largest client by installs). Excluded on capability, not merit: this is an Apple
  household with no Android development experience and no Android hardware beyond some
  rooted Facebook Portals. A fork nobody can build is worse than no fork. It stays in the
  compatibility matrix as a client the fork will break and will not fix.
- **[grocy-desktop](https://github.com/grocy/grocy-desktop)** (MIT, v2.15.0 Mar 2026), a
  Windows wrapper bundling PHP and nginx around the server. The deployment target here is
  scale-to-zero pods on k3s; the wrapper solves a problem this fork does not have.

## Where the two tracked clients actually stand

| Client | Language | License | Last commit | Health | Posture |
|---|---|---|---|---|---|
| [Grocy-SwiftUI](https://github.com/supergeorg/Grocy-SwiftUI) | Swift | **GPL-3.0** | 2026-08-29 | active, single maintainer | fork |
| [custom-components/grocy](https://github.com/custom-components/grocy) | Python | Apache-2.0 | 2025-07-23 | stale, dead dependency, poll design incompatible with the deployment | fork, and rework rather than rebase |

Measured against this fork's `victual.openapi.json` (73 paths): Grocy-SwiftUI uses 48
endpoints, 47 of which exist here. The Home Assistant integration uses a narrow slice —
stock, volatile stock, chores, tasks, batteries, meal plan, shopping list — plus
`/api/files/{picture_type}/{filename}` for product and recipe pictures, all present.

Coverage is not the risk, and it never was. What a path list does not show is the
couplings — and as of 2026-08-29 the first of them has stopped being a risk and become a
fact. Neither client authenticates against the fork any more.

## Coupling 0 — the rename already broke both clients

This section is written after the event, which is the thing it is chiefly evidence of.
[16](16-project-rename.md) landed on 2026-08-29 and its "What the survey missed" section
records two renames whose justification is, verbatim, "the justification is Tier 1's,
since no client exists".

That premise is true of *deployed instances of this fork* — there are none, which is what
Tier 1 is about — and false of *clients*, of which this document names two. The roadmap's
own sequencing rule ("17 before 11, 16 and 10") exists precisely to put this document in
front of that decision, and it did not happen.

Two things changed, and they are not the same size:

**The API key header, `GROCY-API-KEY` → `VICTUAL-API-KEY`** (`app.php:96`). This is the
transport, not a field. `ApiKeyAuthenticator` — `ApiKeyAuthMiddleware` when this was
written; wave 2's 15-C1 renamed it and gave it one job — looks for exactly one header name,
resolved from the `ApiKeyHeaderName` container binding. The query parameter of the same
name is gone too, removed by sweep S11.

Both tracked clients send `GROCY-API-KEY` on every request, because it is the only API
authentication grocy has. Against the current tree every one of those requests is
unauthenticated: not a warning banner, not a degraded feature — Grocy-SwiftUI cannot log
in and the Home Assistant integration's config flow cannot complete. This is the hardest
break either client has ever been handed by this fork, and it was taken in a commit whose
reasoning says no client exists.

The mitigation, if one is wanted, is unusually cheap, and that is worth writing down before
the decision is made rather than after. The header name is a single string in a DI
binding, and the middleware already reads it from there, so accepting a legacy name
alongside the canonical one is a contained change in one file, not a compatibility layer.
The cost is that it keeps upstream's name alive in the auth path indefinitely — the sort
of thing that gets added quickly and stays forever. See Q4.

**`grocy_version` → `victual_version` in `GET /api/system/info`** (`ApplicationService.php:89`).
16 correctly calls this out as the only response *field* in the whole API surface carrying
the name, and correctly calls it a breaking API change. Coupling 2 below was written about
the version *value*; this is the *key*, and it is the harder of the two, because a client
that gates on a value it cannot find is in a different situation from one that finds an
unfamiliar value.

Grocy-SwiftUI reads `grocy_version.Version`; that key is now absent. Whether that surfaces
as a decode failure on `SystemInfo` or as a silently-nil version depends on how the Swift
model declares it, and this plan should not guess. Verification 5 below is the check that
answers it, and it is now a check on something that has already happened rather than a
rehearsal for something that has not.

The Home Assistant integration reads `/system/info` through `pygrocy2` for its version
sensor and is subject to the same key rename, though it is moot while the header break
stops it reaching the endpoint at all.

**What this costs, and what it does not.** Nothing is deployed and no household member has
lost anything, which is exactly what 16's Tier 1 reasoning gets right. What was lost is the
*ordering*: the decision about how much upstream compatibility to keep in the auth path was
taken implicitly, by a rename sweep, instead of explicitly here with its cost written down.
That decision is now Q4's, taken after the fact. The three older questions below are still
unanswered.

**What it says about the mechanism.** "How this plan stays current" proposes client
endpoint manifests asserted against 14's snapshot, and notes that a manifest would fail CI
with the client named. A path manifest would not have caught either of these: `/system/info`
is still there and still a `GET`, and the API key header does not appear in a path list at
all.

So the manifest is necessary and not sufficient, in the same way the additive rule is.
Whatever piece 2 builds needs to cover the request headers a client sends and the response
*keys* it reads, not only the routes it calls.

The couplings below are the ones still ahead.

## Coupling 1 — the Home Assistant integration defeats scale-to-zero

The largest of the findings still ahead, and the only one that is not about the API at all.
Coupling 0 is worse today, but it is a decision to take rather than a design to change.

`GrocyCoordinator._async_update_data` iterates every enabled entity and awaits one HTTP
round trip per entity, **sequentially**, on a `SCAN_INTERVAL` of thirty seconds. With the
integration's thirteen entity types enabled that is thirteen serialized requests every
thirty seconds, each one a blocking `requests` call hopped onto the executor pool because
the underlying library is synchronous.

[10](landed/10-cold-start-statelessness.md) exists to produce a pod that scales to zero. A client
that touches the API twice a minute, forever, means the pod never idles and never scales
down. The two plans are in direct conflict, and the conflict does not appear in any
response shape, status code or schema — it is a design assumption of the client that the
server is always on and cheap to ask.

Grocy already has the endpoint that fixes this: `/system/db-changed-time`. A correctly
built integration polls that one cheap route and fetches entity data only when the
timestamp moves. That is one request per interval instead of thirteen, and it lets the
interval stretch to minutes without the entities going stale in practice.

This matters to question 2 more than anything else in this document. Rebasing upstream's
integration inherits the poll design; reworking it is where the actual value is.

### Resolved, 2026-08-29 — the conflict is removed rather than tuned

The analysis above is correct and its conclusion was too small. It assumes the client
polls *something*, and argues about what and how often. Both halves of that are wrong for
this deployment, for two reasons that only became visible once the household's own numbers
and cluster inventory were on the table:

- **The idle windows are long and the usage is bursty.** Shopping is once or twice a week,
  bulk shopping every other week, and whole nights pass with nothing happening. The pod
  should be awake for something like an hour a week, not continuously. Against that, *any*
  poll interval short enough to keep entities fresh keeps the pod hot, and any interval
  long enough to let it sleep leaves them stale. Polling has no setting that is correct
  here.
- **An always-on MQTT broker is already in the cluster**, alongside Redis and InfluxDB, and
  Home Assistant speaks MQTT natively.

That second fact supplies the always-on component this problem needs. Published to
**retained** topics, the broker holds the last known state and hands it to Home Assistant
on connect — after Home Assistant restarts, and after the pod has been asleep for days.
Home Assistant polls nothing and the server is not asked anything.

What makes this sound rather than merely convenient is a property of the system: **while
the pod is asleep, the data cannot change except by the clock.** Writes only happen when
something is talking to the server, and the server is awake whenever that is true. So the
freshness contract has three parts and no timer:

1. the server publishes after every commit, and again on boot — it is awake at both moments
   by definition;
2. the server publishes **facts, not derived states** — `best_before_date`, not "expiring
   soon" — so Home Assistant derives every time-relative view locally and nothing has to
   wake at midnight to recompute anything;
3. a full snapshot on boot repairs anything changed out of band, which is the only way
   retained state can drift.

Writes from Home Assistant go the other way, over HTTP, and wake the pod. That is correct
rather than a compromise: a write is user-initiated, so a cold start sits behind a button
press where it is invisible, and the household's own numbers put that at a handful of wakes
a week.

[18](18-mqtt-state-publication.md) is the server half. The client half shrinks to a thin
integration for the interactive surfaces, since discovery-created entities cover the
ambient ones without any custom code at all.

## Coupling 2 — `/system/info` and the version *value*

This is about the string, not the key; the key rename is Coupling 0 and has already
happened.

Grocy-SwiftUI exact-matches `grocy_version.Version` against `["4.4.0", "4.5.0", "4.6.0"]`.
Outside that set it warns — "the server version is currently unsupported by the app, you
can use it anyways, but there can be problems" — and continues. It is a banner, not a
refusal.

`version.json` still reads `4.6.0`, the last value in that list. [16](16-project-rename.md)
landed without touching it, so the version *string* is unchanged and this gate is exactly
where it was; what moved is the field it is read from. That is luck rather than design, and
it was already fragile before the rename: the iOS app's most recent commit is a fix for
upstream 4.7.0, which this fork is not.

So Q1 — what version string the rename ships — is still genuinely open, and is now asked
about a rename that has already shipped without deciding it. The ecosystem cost of the
answer is unchanged and still small: one warning banner, in an app this fork is taking over
anyway, and one that is moot until the client can read the field again at all. See Q1.

## Coupling 3 — the error contract

[11](11-api-error-handling.md) carries its own breaking-changes list. What reaches clients
is the status codes: permission denied 400→403, malformed filter 500→400, `PUT`/`DELETE`
on a missing object 400→404, and the removal of `error_details` (`stack_trace`, `file`,
`line`) from nine list operations that currently document `Error500`.

Both tracked clients branch on status. Grocy-SwiftUI surfaces failures to a user;
the Home Assistant integration wraps every failure as `UpdateFailed` and marks the whole
coordinator unavailable, so a single 403 on one entity takes out all of them. That is a
defect in that integration worth fixing in the fork regardless of plan 11.

Error *message* text is not a tracked coupling. No client in scope matches on it, so
[14](landed/14-contract-and-regression-scaffolding.md)'s snapshot has no reason to freeze message
strings, and plan 11 stays free to reword them.

**One thing on this surface was a client-visible decision rather than a status code, and it
was decided the cheap way round while it was still cheap.** `db/pgsql/README.md`'s hazard 16:
the `~` operator of the generic list filter emitted `LIKE`, so `?query[]=name~milk` matched
"Milk" on SQLite and did not on PostgreSQL.

Making the engines agree necessarily changes the answers some client gets on one of them, so
the direction mattered: SQLite's case-insensitive behaviour was taken as the reference, and
PostgreSQL was moved to `ILIKE`. That choice costs a client nothing it can observe: SQLite is
what every existing client has ever been pointed at, and it is what the spec documented. It
was also taken before the forked Home Assistant integration exists to be written against the
other behaviour, since deciding it after would have meant changing a client this household
maintains.

Two things to carry forward from it, both of which sharpen "How this plan stays current":

- **The endpoint manifest of item 1 would not have caught this.** The path, the method and
  the response shape were all unchanged; only the rows differed. Same lesson as Coupling 0,
  from the opposite direction — a path list is not the contract.
- **The client-impact line of item 2 is what caught it**, in the sense that writing one for
  this change is what forced the direction to be chosen rather than defaulted. The line here
  reads: no observable change for either tracked client, because both are SQLite-era clients
  and SQLite is the side that did not move.

## Coupling 4 — hierarchies presented to a client that assumes flatness

[07](retired/07-nested-products.md) and [08](landed/08-nested-locations.md) add depth to
`objects/product_groups` and `objects/locations`. Both are additive on the wire — a new
nullable parent column — and both change what a flat list *means*.

Grocy-SwiftUI fetches both and renders them as flat pickers. After 07/08 it shows every
node in the tree as a sibling, with no path and no indication that "Cheese" under "Dairy"
is not a peer of "Dairy". Nothing errors; the app quietly becomes wrong to look at, which
is the outcome an additive-API rule is least good at preventing. This is the first real
feature work the iOS fork owes, and it is not small — pickers become hierarchical
navigation.

The Home Assistant integration does not model groups or locations and is unaffected.

The same shape recurs for location labels, in the form
[ADR-0011](../adr/0011-label-namespace.md) gave them when it was accepted 2026-09-04: there
is no `l` Grocycode type and no `grcy:l:{uuid}`, and the iOS app's scanner, which resolves
`grcy:p:42`, will not recognise a `vctl:<uid>` payload. That is the better version of the
same problem: an unrecognised namespace fails visibly, where a non-numeric id in a
`grcy:` code was a parser hazard. Neither tracked client *generates* Grocycodes, though,
which is the check ADR-0011's consequences asked this catalogue for before its acceptance
claimed a zero blast radius at print time.

## Coupling 5 — fields that may be absent per user

[19](19-rbac.md) makes `price`, `average_price`, `last_price`, `costs` and the
price-history endpoint conditional on a `STOCK_PRICES_VIEW` leaf, enforced server-side and
**removed** from the response rather than nulled — because a null price is a legitimate
value in `stock_log` and has to stay distinguishable from "you may not see this". Every
one of those fields stops being `required` on its schema.

This is the additive rule's blind spot in its purest form. No route moves, no status code
moves, no key is renamed, and the change is invisible to a diff of the route table — yet
it can break a client for one user and not another, which no coupling here has done
before.

Whether it *does* break Grocy-SwiftUI depends on how the Swift model declares those
properties, and this plan should not guess. If they are non-optional, a Child logging in
from a phone gets a decoding failure on the stock list rather than a list without prices,
and the app stops rather than degrading. Verification 5 is the check that answers it, and
it should be run for `price` and `costs` specifically before 19's piece 2 lands.

The Home Assistant exposure is **unassessed, not absent.** The custom component is a REST
consumer — stock, volatile stock, chores, tasks, batteries, meal plan, shopping list — so
[18](18-mqtt-state-publication.md) publishing no prices protects the MQTT path and says
nothing about whether `pygrocy2` survives `price` disappearing from `/api/stock`. That is
a second instance of the same check.

The mechanism half of this document is what makes it cheap. The Swift module's transport
is generated from `victual.openapi.json` after [11](11-api-error-handling.md), so if 19
lands first the optionality is generated rather than retrofitted. The manifests item 1 asks
for should cover response *keys*, not just paths — the widening item 3 recommends after
Coupling 0 — because without it a field that silently stops appearing in a response would
pass an unwidened manifest unnoticed.

So the rule is **19 before the Swift generation**, which is stronger than "before the
client work resumes": after it, every generated model is generated twice.

## Lower-exposure plans

| Plan | Client exposure |
|---|---|
| [01](landed/01-file-storage.md) files in the database | Plan 01 states "No change. Same three routes, same headers, same 404 behaviour": Home Assistant builds picture URLs from `/api/files/{picture_type}/{filename}`, and Grocy-SwiftUI uses `/files/{group}/{fileName}`. Both are URL constructors, so the *route* is the contract, not the storage. The risk plan 01 already lists — `mime_content_type($path)` and `finfo_buffer($bytes)` disagreeing and shifting `Content-Type` — is visible to an image client but not to a schema snapshot. |
| [03](landed/03-category-min-stock.md) | Home Assistant's missing-products sensor reads `stock/volatile`. The plan keeps group shortfalls out of `stock_missing_products`, so the sensor is unchanged — which is also why the feature is invisible to it until the integration is taught about it. |
| [05](05-store-shopping-lists.md) | Additive fields on `objects/shopping_list`; both clients ignore unknown fields. Note Grocy-SwiftUI reads `objects/shopping_locations` by that name, so 15-Q5's declined rename stays declined. |
| [02](02-mcp-endpoint.md), [12](landed/12-frontend-shared-core.md), [13](landed/13-write-path-transactions.md) | None. |

## Posture per client

### custom-components/grocy — fork, and rework rather than rebase

This one needs work regardless of anything this fork does. Its last commit is 2025-07-23.
It pins `pygrocy2==2.4.1`, a library its own author archived on 2026-02-09 in favour of
`grocy-py`. It polls thirteen endpoints serially every thirty seconds. It collapses any
single-entity failure into whole-integration unavailability. Apache-2.0, so forking is
clean.

The temptation is to fork, swap the dead dependency, and stop. That produces a working
integration and inherits every design decision above, including the one that stops
[10](landed/10-cold-start-statelessness.md) from delivering what it is for.

Posture: fork, and treat the upstream code as a reference implementation of the entity
model rather than a base to rebase on. The entity descriptions, config flow and service
definitions are worth keeping; the data layer is not. Ship as a HACS custom repository.
Not blocked by any plan — this can start now.

> **Revised, 2026-08-29.** Not a fork at all, and smaller than this section assumes. With
> [18](18-mqtt-state-publication.md) publishing retained state and discovery configs, the
> ambient entities — stock counts, what is due, what is expiring, chores, batteries, tasks
> — are created by Home Assistant's MQTT integration with no custom code in the loop. There
> is no coordinator, no poll interval, no data layer, and therefore nothing left of
> upstream's integration worth forking: what this section identified as the parts worth
> keeping (entity descriptions, config flow, service definitions) are the parts that
> discovery generates or that a handful of `rest_command` calls replace.
>
> What remains custom is the interactive surface, and it is worth being honest that it is
> not nothing: anything that wants a real Home Assistant entity type outside MQTT
> discovery's platform set needs a small first-party integration doing HTTP on demand. A
> shopping list as a `todo` entity is the concrete case and it is confirmed rather than
> suspected — `todo` is absent from the MQTT integration's `ENTITY_PLATFORMS` and there is
> no `mqtt/todo.py` in `home-assistant/core` (checked 2026-08-29). Sensors, buttons,
> numbers and selects are all there, which covers everything ambient. On-demand is the easy case; it
> wakes the pod, which is what waking is for. Build that only when something actually wants
> it, rather than up front.
>
> The upstream integration's own defects listed above stop being this fork's problem, since
> none of its code is carried. They stay recorded as the reason not to carry it.

### The add-on question, asked and closed

Home Assistant runs Supervised on a Yellow, so add-ons are available — and the first
instinct was a component/add-on pair, where the add-on holds state and talks to the
scale-to-zero pod on the integration's behalf. That instinct was right about the shape of
the problem: something always-on has to sit between an ambient consumer and a server that
is usually absent.

It is not needed, because the broker is already that thing. An add-on would be a second
always-on process holding a copy of state that the broker holds anyway, installed and
versioned separately, with the freshness logic split across two artifacts instead of
living in one. The state belongs in exactly one place; MQTT retention puts it there
without anything being built.

Recorded rather than dropped, because "why is there no add-on" is a question this household
will ask itself again in a year.

### grocy-py — a dependency question, not a project to track

The only reason a Python client entered this list is that the Home Assistant integration
needs one. So the question is what that integration talks to, and there are two answers.

**Depend on grocy-py.** It is actively maintained, MIT, committed to semantic versioning
since 1.0.0, `>=3.12`, pydantic v2 and uv-managed — the last of which matches the standard
every Python tool built for these systems is held to anyway. Its 1.1.0 release exists
because Home Assistant's `cv.date` validator produces a `datetime.date`, so the maintainer
is already tracking downstream integration needs. Depending on it means free maintenance
and no client code to own.

**Reimplement inside the integration.** Three arguments, in ascending order of weight:

1. **Surface.** grocy-py covers roughly seventy endpoints across fifteen managers. The
   integration needs seven read paths and a handful of service calls. Reimplementing the
   slice is a few hundred lines, not seventy endpoints — the surface-area argument for
   depending on a library mostly evaporates once the slice is measured.
2. **Async.** grocy-py is synchronous `requests` by design, which is why the current
   integration has fifteen `async_add_executor_job` wrappers. An integration written
   against `aiohttp` and Home Assistant's shared session drops the executor hop entirely
   and can issue its reads concurrently instead of in a for loop. That is not a style
   preference; it is the difference between one round trip and thirteen serial ones per
   interval. No amount of maintaining grocy-py gets there, because making it async would
   be a rewrite of its public surface.
3. **Decay.** The free maintenance grocy-py provides is maintenance against *upstream
   grocy's* API. This fork is diverging from that API on purpose. The value of the free
   maintenance therefore falls at exactly the rate the fork succeeds, while the cost of
   carrying a dependency whose models no longer describe the server stays flat.

The honest case against reimplementation is that it is code owned forever with no
upstream, written by a household of one. The first year of a hand-rolled HTTP client is
mostly rediscovering timeouts, retries, connection reuse, and error mapping that a
maintained library already got right. That case is real. It is weaker here than usual
because the client is narrow, the server is on the same cluster, and
[11](11-api-error-handling.md) is about to make the error mapping worth writing fresh
against rather than inheriting.

Recommendation: reimplement, async-native, inside the forked integration, and do not track
grocy-py at all. See Q2 — this is the one open question in this plan whose answer changes
what gets built rather than how it is labelled.

> **Answered, and by dissolution.** Both options above presuppose a Python HTTP client
> polling the server on a timer, and Coupling 1's revision removes the timer. The ambient
> read path is MQTT; there is no client library to depend on or to write, because there is
> no client doing reads. Whatever HTTP the interactive surface eventually needs is a few
> `rest_command` calls or a thin integration, at which point "should this depend on
> grocy-py" answers itself: seven read paths of surface, none.
>
> The three arguments above survive the question they were written for, and two of them are
> why the MQTT answer is right rather than merely different. **Async** was really an
> argument that thirteen serial round trips per interval is the wrong shape — and zero
> round trips per interval is the shape it was reaching for. **Decay** — that free
> maintenance is maintenance against *upstream grocy's* API, and its value falls exactly as
> fast as this fork succeeds — applies to any dependency modelling this server's responses,
> and is now an argument for the topic schema in [18](18-mqtt-state-publication.md) being
> this fork's own rather than anything inherited.
>
> The note about offering the `pygrocy2`→`grocy-py` migration back to upstream as a PR is
> moot: no upstream integration code is being carried, so there is no migration performed
> here to offer.

### Grocy-SwiftUI — fork, and accept GPL-3.0

GPL-3.0, one maintainer, actively developed. Forking is permitted and the fork stays
GPL-3.0 — which is fine, because it is a separate repository talking HTTP to the server.
There is no license interaction with this fork's MIT/BSD-3 tree.

Two things are already broken in it, independent of any divergence, and both are worth
knowing before deciding how much to trust the codebase:

1. All six `by-barcode` endpoint constants contain U+200B zero-width spaces, evidently
   copy-pasted out of rendered API documentation — `"​/stock​/products​/by-barcode​/{barcode}"`.
   Any request built from them URL-encodes to `%E2%80%8B` and 404s. They are currently
   unreferenced, so this is latent rather than live: it detonates the moment barcode
   scanning is wired through them.
2. `/system/log-missing/localization` does not exist. The route is
   `/system/log-missing-localization`. It 404s against upstream grocy too.

Neither is caught by anything, which tells us what the test coverage is worth.

Posture: fork. **The order of work changed when [16](16-project-rename.md) landed**: the
first commit is now the API key header, because without it the app cannot authenticate and
nothing else in the list is reachable to test. Then the `victual_version` key, then the
version gate's value, then the two latent 404s above, then hierarchical pickers when
[07](retired/07-nested-products.md)/[08](landed/08-nested-locations.md) land.

Track upstream and rebase; a single-maintainer app is easier to follow than to diverge
from. See Q3 on distribution, which is the real cost here and is not a code problem, and
Q4 on whether the server meets the client half way on the header so this fork is not the
only way to reach the server.

> **Revised, 2026-08-29 — write it, do not fork it.** The Apple client is first-party: a
> Swift client module — models, networking, auth, state — with independent UI modules per
> platform on top of it, iOS first and iPad and Mac as their own targets against the same
> module. Nothing above survives that except the two defects, which stay recorded as
> evidence about the codebase rather than as a work list.
>
> Three consequences worth stating, because each one is a cost avoided rather than a
> preference:
>
> - **The licence stops being a question.** A fork of Grocy-SwiftUI is GPL-3.0 and stays
>   GPL-3.0. Written fresh, the module is this fork's own and carries the tree's BSD-3,
>   which matters precisely because it is a *module* — something other things link against
>   — rather than an application talking HTTP at arm's length. Do not read Grocy-SwiftUI's
>   source while writing it.
> - **The rebase burden disappears**, and with it the reason this section gave for forking:
>   "a single-maintainer app is easier to follow than to diverge from" is an argument about
>   carrying someone else's code, and there is none to carry.
> - **The wire contract can be generated rather than hand-written.**
>   `victual.openapi.json` is 73 paths and [14](landed/14-contract-and-regression-scaffolding.md)
>   piece 2 is about to freeze the response shapes; generating the module's transport from
>   the spec makes that snapshot the client's contract too. Sequence it after
>   [11](11-api-error-handling.md), which moves status codes across ~74 routes — generating
>   before that means generating twice.
>
> This narrows Q3 to its real half: distribution. It also settles Q4 — see there.
>
> **One thing this commits the server to.** A Swift module generated from the spec can
> only render what the API returns, and the API does not currently return everything the
> web UI shows — [14](landed/14-contract-and-regression-scaffolding.md)'s section 2b measures the
> gap, and a stock overview is the first thing on the list. So the read surface is not a
> someday concern of a hypothetical tier split: it is a scheduled dependency of a client
> decided here, and it has to grow before 14 piece 2 freezes the contract the module is
> generated from. This is exactly the client-impact line this plan's own mechanism section
> asks every plan to carry, arriving for once before the work rather than after it.

## How this plan stays current

A document that lists what breaks is worthless the day someone changes an endpoint without
opening it. The mechanism, not the list, is the deliverable:

1. **The client surface becomes a fixture, not prose.** Each tracked client's endpoint and
   field usage is extracted into a checked-in manifest and asserted against
   [14](landed/14-contract-and-regression-scaffolding.md)'s snapshot. A plan that changes a route
   or a status code on a path some client consumes fails CI with the client named. The
   failure has to arrive before the merge, not from a household member saying the app
   stopped working.
2. **Each plan carries a client-impact line.** One row, even when it reads "none". Absent
   is not the same as none.

   > **Adopted 2026-08-30.** For the first day of this mechanism's life — this document was
   > written 2026-08-29 — it had
   > exactly zero adopters — this document defined it and no plan carried one, which the
   > rigor review recorded as D2. All eighteen other plans now do, and writing them was
   > more informative than the rule promised. Three were not "none" when the plan implied
   > it was: **12**'s API section opens "No API change" and Q1 then chooses the strict
   > spelling, which silently stops honouring `transactiontype`; **07**'s says no public
   > response changes, and the change it does make is a one-level assumption quietly
   > becoming false, which no manifest can catch; **16**'s did not exist and would have
   > been the one line that stopped Coupling 0. The rule earns its place on cases where
   > the shape does not move and the meaning does — which is the class of change item 1's
   > fixtures are structurally unable to see.
3. **This document is reviewed at [16](16-project-rename.md)** — the rename is the largest
   client-facing event on the roadmap and the one with the least code in it. *This did not
   happen*: 16 landed first, on the same day this was written. Item 2 above is what would
   have caught it — a client-impact line on 16 could not have been written as "none" — and
   item 1 would not have, since neither break shows up in a path manifest. That is the
   argument for making item 2 a checklist row in the pull request template rather than a
   convention, and for widening item 1 from paths to request headers and response keys.
4. **Fork repositories live under the same owner**, each with an `UPSTREAM.md` recording
   the upstream commit last rebased onto and the divergence held. No fork is created
   before there is a change to put in it.

## Open questions

All four are unanswered. Q1 is now asked after the rename it was written to precede, and
Q4 exists because that rename took a decision this document was supposed to hold.

1. **What version string does the rename ship?** ***Asked after the fact.***
   [16](16-project-rename.md) landed on 2026-08-29 without changing `version.json`, so the
   fork still reports `4.6.0` — from `victual_version` rather than `grocy_version`, but the
   same string. The question is therefore live and unchanged, only later than it should
   be; nothing has foreclosed any of the answers. With the iOS app the only version gate
   left, and a soft one, this is no longer constrained by the ecosystem. I lean to
   resetting the version at the rename rather than continuing upstream's `4.x.y` line. The
   fork stops being 4.x in any meaningful sense around [07](retired/07-nested-products.md), and a
   client that believes it is talking to grocy 4.8 fails in more confusing ways than one
   that knows it is talking to something else. The forked iOS app's supported-versions list
   is updated in the same change, so the warning banner never appears. The case against is
   that it is churn with no functional benefit, and that keeping `4.x.y` costs nothing
   until something actually reads the string for compatibility rather than display.

   > **Response:**

2. **Does the Home Assistant fork depend on grocy-py, or reimplement its client?** The
   argument is worked above; I recommend reimplementing async-native against `aiohttp`,
   polling `/system/db-changed-time` and fetching entity data only when it moves. The
   decisive reason is [10](landed/10-cold-start-statelessness.md): thirteen serial requests every
   thirty seconds is not a client that lets a pod scale to zero, and no dependency choice
   fixes that. The async rewrite is also the thing that makes depending on a sync library
   pointless, so the two decisions are one decision. If the answer is to depend on
   grocy-py after all, then the `pygrocy2`→`grocy-py` migration is work upstream needs,
   is not fork-specific, and should be offered back as a PR rather than kept private.

   > **Response:** Neither, and the question was asked one level too low. Home Assistant
   > is a first-party integration for this household, so the real question was never which
   > Python library it depends on but what shape the coupling takes — and the answer is
   > that the ambient read path is not HTTP at all. The server publishes retained state
   > and discovery configs to the MQTT broker already running in the cluster; Home
   > Assistant creates the entities from those and polls nothing. See Coupling 1's
   > resolution above and [18](18-mqtt-state-publication.md) for the server half.
   >
   > What this buys, in the terms the question was asked in: thirteen serial requests
   > every thirty seconds becomes zero requests, forever, until someone writes something.
   > No amount of choosing between grocy-py and an `aiohttp` reimplementation gets there,
   > because both of them are still a client asking a sleeping server how things are.
   >
   > The recommendation's own reasoning holds and points here: it identified the poll
   > design as the thing to fix and the dependency as incidental. Publishing facts rather
   > than derived states is what makes the long idle windows safe — the household's are
   > overnight and often longer, with shopping once or twice a week — since Home Assistant
   > computes every time-relative view locally and nothing needs waking at midnight.
   >
   > Writes stay HTTP and wake the pod, which is correct. Whatever interactive surface
   > eventually wants a real entity type outside MQTT discovery's platform set gets a thin
   > first-party integration, built when something actually wants it.

3. **How does a forked Grocy-SwiftUI get onto devices?** This is the real cost of that
   fork and none of it is code. TestFlight needs a paid Apple developer account and a build
   pipeline; sideloading through AltStore or a personal signing certificate means a
   seven-day resign cycle per device. A third option is not forking the app at all and
   letting [02](02-mcp-endpoint.md) carry the mobile use case — an assistant that answers
   "what is expiring this week" covers a real share of what the app is opened for, without
   an app. I lean to forking and sideloading initially, deferring the developer account
   until the fork does something the stock app cannot.

   > **Response, partial.** The forking half is answered elsewhere and against the lean:
   > the Apple client is written here, not forked, as a Swift client module with
   > independent UI modules per platform (see the revised posture above). So "how does a
   > forked Grocy-SwiftUI get onto devices" becomes "how does *our* app get onto devices",
   > which is the same problem minus the licence.
   >
   > The distribution half is still open and is still the real cost. One thing the module
   > split changes about it: a Mac target signs and notarizes for direct distribution
   > without the App Store, while iOS has no equivalent — so the platforms have genuinely
   > different answers, and a shared module means the choice can be made per target rather
   > than once for everything. That is an argument for the architecture, not an answer to
   > the question.

4. **Does the server keep accepting `GROCY-API-KEY`, and for how long?** New, and forced
   by Coupling 0 rather than chosen. Three answers, and the roadmap needs one of them
   written down rather than arrived at by default:

   - **No shim.** The forked clients set the new header and no unmodified client ever
     reaches this server. Cleanest, and consistent with the hard-fork posture — but it
     means the *only* way to talk to this fork from iOS or Home Assistant is through
     software this household also maintains, and it forecloses anyone else's client
     before there is anyone else. It also means the Q3 distribution problem controls iOS
     access entirely: if the forked iOS app cannot be got onto a device, there is no iOS
     access at all.
   - **Accept both, indefinitely.** One extra string in the `ApiKeyHeaderName` binding and
     one extra lookup in `ApiKeyAuthenticator`. Cheap to write, and the sort of
     compatibility shim that is never removed — upstream's name stays in the auth path
     forever, which is precisely the outcome [16](16-project-rename.md)'s Tier 0/Tier 1
     split exists to make deliberate rather than accidental.
   - **Accept both, with an expiry.** Same change plus a deprecation window and a log line
     on the legacy header, removed at a stated point — most plausibly when the forked
     clients ship, since after that nothing this household runs needs it. This is the
     answer I lean to, because it makes the shim's removal a scheduled item rather than a
     someday.

   Whichever is chosen, note that the same question does *not* arise for
   `grocy_version` → `victual_version`: a client reading a missing key needs its own fix
   regardless. Answering `/system/info` with both keys would be adding a field to a
   response purely to keep an old name alive, which the additive rule permits and the
   hard-fork posture argues against.

   > **Response: no shim.** The first option, and it is now the cheap one rather than the
   > austere one, because Q2 and Q3's answers removed what made it expensive.
   >
   > The case against "no shim" was that it makes this household's own software the only
   > way to reach the server, and that it leans on Q3's distribution problem. Both are now
   > true by construction rather than by choice: Home Assistant reaches the server over
   > MQTT and does not send the header at all, and the Apple client is written here, so
   > every client that exists is first-party and sets `VICTUAL-API-KEY` because it was
   > written to. There is no unmodified client left for a shim to serve — the shim would be
   > compatibility with software nobody here runs.
   >
   > "It forecloses anyone else's client before there is anyone else" stands as the honest
   > cost, and is accepted. The header is one string in the `ApiKeyHeaderName` binding if
   > that ever stops being true, and this response is the record that it was declined
   > deliberately rather than never considered.
   >
   > Coupling 0 is therefore closed at no cost, which is a better outcome than it deserved:
   > the ordering breach cost a decision taken implicitly, and the decision it would have
   > taken is the one being taken here anyway. That is luck, not vindication — the same
   > breach with a third-party client in the household's daily use would have cost an
   > outage.

## Verification

Verification here is a booted instance and a real client, per the standard the rest of the
roadmap is held to. Lint is not verification, and neither is reading a client's source.

1. **Baseline, before anything changes** — ***and it has to be taken on a pre-rename
   checkout now.*** Point both tracked clients at a booted fork instance on both engines
   and record what works: Grocy-SwiftUI logging in and reaching stock, Home Assistant's
   config flow completing and its sensors populating. This is the before-picture that
   every later claim of "still works" is measured against, and against the current head it
   is not obtainable — both clients fail at authentication per Coupling 0. Take it at
   `93605da`, the last commit before [16](16-project-rename.md) merged, which is the state
   this document was written about. Losing the ability to take a baseline on `master` is
   the concrete cost of the ordering breach, and it is small only because the checkout is
   still there.
2. **The manifest matches reality.** The extracted endpoint manifests are diffed against
   the fork's `victual.openapi.json` and every mismatch is explained — the two found while
   writing this plan are client-side bugs, and any future mismatch must be classified as
   client bug or fork divergence, not left in the diff.
3. **Request count per poll interval is measured, not assumed.** Instrument the server and
   count requests from the Home Assistant integration over ten minutes, before and after
   the rework. The target is one `/system/db-changed-time` request per interval with no
   entity fetches while nothing changes, and the number that matters is how long the pod
   stays idle — [10](landed/10-cold-start-statelessness.md)'s scale-to-zero is the actual
   acceptance criterion, not the request count itself.

   > **Revised with Q2.** The target is now *zero* requests from Home Assistant while
   > nothing is written, so counting requests over ten minutes is no longer the
   > interesting measurement — a ten minute window where the answer should be zero proves
   > very little. What replaces it, per this item's own last clause: measure how long the
   > pod actually stays scaled to zero over a week of ordinary household use, and confirm
   > Home Assistant's entities are still correct at the end of it. [18](18-mqtt-state-publication.md)
   > carries that as its own verification, including the case this one cannot see —
   > restarting Home Assistant while the pod is asleep and confirming entities repopulate
   > from retained topics rather than going unavailable.
4. **[11](11-api-error-handling.md)'s breaking-changes list is replayed against clients.**
   For each moved status code, exercise the path from a client that reaches it and record
   the client-visible behaviour before and after. The permission-denied 400→403 change on
   `POST /chores/{id}/execute` is reachable from both clients and is the case to check
   first, along with confirming that one failing entity no longer marks the whole Home
   Assistant coordinator unavailable.
5. **[16](16-project-rename.md)'s renames are tested against a stock client — the version
   *key* now, the version *string* before it is chosen.** Two checks, in that order, both
   with the stock app rather than the forked one, because the stock app is the honest test
   of what an unmodified client sees:

   a. *After the fact, and overdue.* On the current head, with the legacy
      `GROCY-API-KEY` header temporarily accepted so the app can get far enough to ask,
      confirm what Grocy-SwiftUI does when `/system/info` answers `victual_version` and
      not `grocy_version` — a decode failure on `SystemInfo`, or a nil version and a
      warning banner. Coupling 0 declines to guess, and this is the check that stops it
      being a guess. Repeat for the Home Assistant integration's version sensor.

   b. *Before it is chosen, as originally written.* Set the candidate version value in
      `version.json` on a disposable instance and confirm what the client does with it.
      This one is still genuinely ahead of the decision: Q1 is unanswered and
      `version.json` still reads `4.6.0`.

6. **Q4's answer is tested as a pair.** Whichever shim answer Q4 lands on, exercise a stock
   client against a booted instance with the legacy header and with the new one, and
   confirm both the accepted and the rejected case behave as the answer says. If the answer
   has an expiry, confirm the deprecation log line fires too. A shim nobody has watched
   reject a request is a shim nobody knows the shape of.

   > **Reduced by Q4's answer.** There is no shim, so the pair collapses to one case:
   > confirm `GROCY-API-KEY` is rejected as an unauthenticated request rather than
   > silently accepted anywhere. Still worth doing once — "we removed it" and "it is gone
   > from every path" are different claims, and the API key path had more than one way in
   > when this was written (sweep S11's query-string path, S17's iCal `secret` branch).
   > **Wave 2 reduced it to two**, which makes the check smaller rather than unnecessary:
   > S11's query-string path is deleted and S17's branch now works, so what is left to
   > confirm is the header and the calendar `secret` parameter.
7. **After [07](retired/07-nested-products.md)/[08](landed/08-nested-locations.md), look at the pickers.**
   Screenshot Grocy-SwiftUI's product-group and location pickers against a seeded tree
   three levels deep. The failure mode is visual and correct-looking, so it has to be
   looked at rather than asserted.
