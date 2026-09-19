# 18. MQTT state publication

**Goal:** Home Assistant knows what is in stock, what is due and what is expiring without
ever asking the server — so the pod can sleep for days and the household still sees
current information.
**Depends on:** [13](landed/13-write-path-transactions.md), landed, which centralised the write
entrypoints and established that side effects fire *after* commit. Pairs with
[10](landed/10-cold-start-statelessness.md): 18 is what makes 10's scale-to-zero survive contact
with an always-on consumer.
**Status:** landed; see Executed. Three Home Assistant verifications remain
([issue 139](https://github.com/datagen24/victual/issues/139)). Exists because of [17](17-ecosystem-clients.md)'s Q2, which
asked which Python HTTP client the Home Assistant integration should use and was answered
"none of them".

## Today

Nothing in the tree publishes anything. The server is asked or it is idle.

- The only outbound network call the application makes on its own behalf is the label
  printer webhook, and its target is the `VICTUAL_LABEL_PRINTER_WEBHOOK` constant. The
  2026-08-29 [security sweep](../security-sweep.md) records this as the reason there is no
  SSRF surface in the tree: no user-configurable outbound URL exists.
- `GET /system/db-changed-time` exists so a client can poll cheaply before fetching. It is
  the best a pull design can do and it is still a pull design.
- Upstream's Home Assistant integration awaits one HTTP round trip per entity type,
  sequentially, every thirty seconds — thirteen serialized requests a minute, forever.
  [17](17-ecosystem-clients.md)'s Coupling 1 has the detail.

Against a pod that is meant to scale to zero, that last point is not a performance problem
but a contradiction. There is no poll interval that is both fresh enough to be useful and
long enough to let the pod sleep.

Three cluster services are always on and were not being used by any of this: an MQTT
broker, Redis and InfluxDB. The first one is the whole plan.

## The property this design rests on

**While the pod is asleep, the data cannot change except by the clock.**

Writes only happen when something is talking to the server, and the server is awake exactly
then. So a consumer does not need to ask what changed while nobody was looking — nothing
did. What it needs is:

1. to be told when something *is* written, by the server, which is awake at that moment;
2. to hold the last thing it was told, across its own restarts and arbitrarily long server
   absences;
3. to handle the passage of time itself, since that is the only thing that moves while the
   server is away.

Retained MQTT topics give (1) and (2) for free. (3) is a design constraint on what gets
published, and it is the part that is easy to get wrong.

## Proposed change

### Publish facts, never derived states

This is the rule the rest of the plan follows from. Publish `best_before_date`, not
"expiring soon". Publish `next_estimated_execution_time`, not "chore overdue".

A derived state is a function of the data *and the current time*. Publishing one means
something has to recompute it as time passes — which means something has to be awake at
midnight, which is precisely what this plan exists to avoid. A fact is a function of the
data alone: correct when published, still correct three days later, and re-derived by the
consumer whenever it likes.

Home Assistant is well suited to this and does not need help: a sensor published with
`device_class: timestamp` renders as relative time in the UI and compares against `now()`
in automations and templates, with no server involvement at all. "Due in 3 days" is a
presentation concern and it belongs on the always-on side of the boundary.

The same rule keeps the entity count sane. Rather than one entity per product, publish a
small set of summary sensors whose *state* is a count or a timestamp — both facts — and
carry the underlying rows as JSON attributes. Home Assistant templates derive whatever
views the household wants from the attributes, locally, and each new view costs nothing on
the server.

### Publish a whole snapshot, not deltas

Every publish is the complete ambient read model. At household scale this is kilobytes, and
it buys three properties that a delta stream does not have:

- **Self-healing.** A publish lost to a broker restart is repaired by the next write, with
  no reconciliation logic and nothing to detect.
- **No ordering to get wrong.** There is no sequence of deltas that can be applied out of
  order, because there are no deltas.
- **Out-of-band changes are caught.** A migration, a `bin/victual-db-import`, or someone
  in `psql` all get picked up by the next boot publish rather than silently diverging.

### Publish after commit, and on boot

Two triggers, both moments the server is provably awake and correct:

- **After commit**, on the write paths [13](landed/13-write-path-transactions.md) already
  centralised. The label printer webhook established the precedent and the reasoning is the
  same: never inside the transaction, because a published state that was then rolled back
  is a lie that persists in a retained topic.
- **On boot**, once per cold start. This is what makes the design self-healing rather than
  merely eventually-consistent, and on this deployment "boot" is roughly "whenever anyone
  first touches it", which is exactly when a refresh is wanted.

### What Home Assistant actually supports

Checked against `home-assistant/core` on 2026-08-29 rather than assumed, because two of
these decide the shape of the plan.

- **Discovery covers the entity types this needs and one it does not.** `sensor`,
  `binary_sensor`, `button`, `number`, `select`, `text`, `event`, `image`, `date`,
  `datetime` and about twenty more are dispatched by the MQTT discovery flow
  (`homeassistant/components/mqtt/const.py`, `ENTITY_PLATFORMS`). **`todo` is not among
  them**, and there is no `mqtt/todo.py` in core — so a shopping list as a real Home
  Assistant to-do entity cannot come from MQTT and is exactly the interactive surface
  [17](17-ecosystem-clients.md) says needs a thin first-party integration. Everything in
  question 1's proposed set is a `sensor`.
- **Discovery topics are `<discovery_prefix>/<component>/[<node_id>/]<object_id>/config`**,
  with `homeassistant` as the default prefix.
- **Device-based discovery exists**: one config payload can declare many entities at once
  under a `components` key, with `device` and `origin` at the root and a `platform` and
  `unique_id` per entity (`mqtt/discovery.py` handles `CONF_COMPONENTS` explicitly). That
  is the right shape here — Victual is one device with a handful of sensors, and it makes
  the config a single retained topic rather than five. It requires a reasonably current
  Home Assistant; the corroboration for *which* release introduced it points at 2024.11
  but is second-hand, so confirm the floor before relying on it. The per-entity topic
  format above is the fallback and costs nothing but four extra topics.
- **An empty retained payload to a config topic removes the entity**, and in device-based
  mode propagates the removal to that device's other components. This is the retraction
  path verification 6 exercises.
- **An entity with no availability topic is always available.** This is the one that makes
  the design legal rather than merely convenient, and it is why the section above refuses
  a last will and testament: absence has to mean nothing, and omitting availability is how
  you say so.

### Writes go the other way, over HTTP

Consuming a product or ticking a chore from Home Assistant is an HTTP call that wakes the
pod. That is correct rather than a compromise: a write is user-initiated, so the cold start
sits behind a button press where it is invisible, and the household's own pattern —
shopping once or twice a week, bulk shopping every other week — puts that at a handful of
wakes a week rather than 2,880 polls a day.

This keeps the interesting property of the split: **the ambient path never wakes the
server, and the interactive path always may.**

Home Assistant's `rest_command` is a core integration that calls arbitrary HTTP endpoints
from automations and scripts, with a `headers` block for the API key and templating on the
URL and payload — so the simplest version of the write path needs no custom component at
all.

### Absence is normal and must not read as failure

The subtle one, and the place a conventional MQTT design would be wrong here.

The usual pattern is a last-will-and-testament message marking the device offline, so
entities show as unavailable when it stops publishing. That is exactly backwards for this
deployment: the server is *intentionally* absent most of the time, and its absence carries
no information about whether the data is still true. An entity that goes unavailable every
night because the pod scaled down is an entity the household learns to ignore.

So the ambient entities have no availability tracking: retained state stands until it is
replaced. If a freshness signal is wanted, it is a fact like any other — a `last_published`
timestamp sensor the household can look at or alert on — rather than a state that
invalidates everything else.

## Client impact

**No HTTP client sees anything change; this plan adds a channel rather than altering one.**
No route, status code, header or response field moves, so
[14](landed/14-contract-and-regression-scaffolding.md)'s snapshot is unaffected and neither is
anything [17](17-ecosystem-clients.md) tracks over REST.

The impact is that a *new* class of consumer appears with no authentication to Victual at
all — anything holding broker credentials. That is the reason the security notes below
draw the line where they do, and the reason [19](19-rbac.md)'s Q5 is carried here as
question 8 rather than inherited from that plan: what goes on a retained topic is a
visibility decision made at publish time, by this plan, for every subscriber at once, and
there is no reader identity to gate it on afterwards.

## Verification

A booted instance and a real broker, per the standard the rest of the roadmap is held to.
Lint is not verification, and neither is watching one message arrive.

1. **Retention actually retains.** Publish, then stop the pod entirely. Attach a *fresh*
   subscriber and confirm every topic arrives from the broker with the retain flag set and
   the correct payload. A subscriber that was already connected proves nothing.
2. **Home Assistant survives the pod being away.** With the pod scaled to zero, restart
   Home Assistant. Every entity must repopulate with its last known value. None may show
   `unavailable`, and none may show `unknown`.
3. **A write propagates without the consumer asking.** Change stock in the browser and
   confirm the Home Assistant entity updates within seconds, with the server's access log
   showing no request from Home Assistant. The log is the evidence, not the entity.
4. **Time moves without the server.** With the pod asleep, confirm a chore's due sensor
   still reads correctly relative to now, and that a template derived from the attributes
   (something expiring within five days) changes as the day rolls over. This is the check
   that the facts-not-derived-states rule was actually followed.
5. **The broker being down cannot hurt a write.** Stop the broker, then consume a product.
   The write must succeed, the response must not be visibly delayed beyond the configured
   timeout, and the failure must be logged once rather than raised.
6. **Retraction works.** Remove an entity from the published set and confirm the empty
   retained payload actually removes it from Home Assistant. Stale retained topics are this
   design's one operational wart and the household should have seen the cleanup work once.
7. **The assembler agrees on both engines.** Run it against SQLite and PostgreSQL over the
   same fixtures and diff the payloads through 14's `ValueComparison` normalisation. It
   reads the same views the UI does, so a divergence here is a divergence anywhere — this
   is the cheapest place to notice.
8. **The number that actually matters.** Over a week of ordinary household use, measure how
   long the pod stays scaled to zero, and confirm at the end of it that Home Assistant's
   entities are still correct. [17](17-ecosystem-clients.md)'s verification 3 named pod
   idle time as the real acceptance criterion for all of this; this is where it is
   collected.

## Sequencing

**Wave 1, alongside 10 and 01.** Disjoint from every other track: it adds a service and a
call at the end of write paths that 13 already centralised, and touches no file 10, 01 or
12 opens.

It wants to land *with* 10 rather than after it. 10's deliverable is a pod that scales to
zero; until 18 exists, the household's own Home Assistant is the reason it will not, so 10
would ship a capability nothing is allowed to use. Neither blocks the other's code.

Not a dependency of [02](02-mcp-endpoint.md), and deliberately not shared with it. An
assistant query is on-demand — it wakes the pod and asks, like any interactive client — so
the sidecar reads the API and has no use for retained state. Keeping them separate avoids
inventing a general "always-on read tier" that only one consumer needs.

**This plan owns [19](19-rbac.md)'s Q5 rather than deferring to it, and the reason is the
ordering.** 19 asks whether published state carries prices, offering three answers: publish
without the visibility-gated fields, publish per-role topics, or declare MQTT an admin
channel. It asks 18 to record which it chose — but 19's piece 2 is wave 5 and this is wave
1, so on the roadmap as written 18 merges four waves before the plan whose question it
defers to. That is not a deferral anyone could honour: a retained topic is *retained*, so
an unanswered question here is not a decision postponed, it is household pricing sitting on
the broker until something re-publishes without it.

It is therefore **question 8 below**, answered here or not at all — and, like every other
question in this plan, it carries a lean rather than a settled answer until it has a
Response. The lean is the first option: publish no price or cost field, on any topic. It
costs little to lean that way because it is what this plan's own rules already say — the
entity set is facts a wall tablet would show, and the security notes below already exclude
user records, notes fields and API keys on exactly the "anything with broker access reads
this without authenticating to Victual" reasoning that applies to prices with more force,
not less. Per-role topics are the option to revisit if 19's piece 2 ever gives the
publisher a role to publish *as*; until then there is no reader identity here to gate on,
which is 19's own framing of why this channel is the hard case.

## Open questions

1. **What is in v1's ambient entity set?** Everything else here is mechanism; this is the
   only part that is a judgement about what the household looks at. I lean to five: a stock
   summary (count, with rows and dates as attributes), a shopping list count, and three
   `timestamp` sensors for the next due chore, battery and task. That covers what a wall
   tablet or a phone glance is for, and anything else is a template away rather than a
   server change.

   > **Response:** The lean's five, plus two promoted counts — products due soon and
   > products expired — for seven ambient entities. The two counts are the classic
   > glanceable numbers a wall tablet exists to show, they are already computed by the
   > views the stock summary reads, and promoting them now costs two discovery payloads
   > rather than the household rediscovering question 2's promote-on-demand path on day
   > one. Everything else stays a template away, as the lean says. (2026-08-31.)

2. **Few sensors with rich attributes, or one entity per tracked thing?** I lean strongly
   to the former, as above. Per-product entities mean hundreds of entities, a discovery
   payload per product, and a retraction problem every time a product is deleted. The case
   against my lean is that attributes are second-class in Home Assistant — not recorded in
   long-term statistics, awkward in some UI cards — so anything the household wants
   *graphed* over time has to be a state rather than an attribute. That is an argument for
   promoting specific values to their own sensors as they prove wanted, not for starting
   there.

   > **Response:** The summary set stands as the ambient default, and the answer to
   > few-versus-many is *both, deliberately*: per-product entities exist, but only for
   > products the household opts in — a per-product flag, so the Home Assistant entity
   > count is chosen rather than inherited from the catalogue. The lean's retraction
   > problem is handled rather than avoided: deleting a product, deactivating it, or
   > clearing its flag publishes an empty retained payload to its discovery and state
   > topics, on the same after-commit seam as every other publish. This is the lean's own
   > escape hatch — "promoting specific values to their own sensors as they prove
   > wanted" — with the promotion made a flag the household sets rather than a server
   > change per product. What stays rejected is the maximal reading: an entity per
   > product for the whole catalogue, which is hundreds of discovery payloads and the
   > retraction problem at scale for entities mostly nothing looks at. (2026-08-31.)

3. **Does a bulk write publish once or many times?** An import or a shopping trip is many
   commits in quick succession, and publishing a full snapshot per commit is wasteful even
   if it is harmless. I lean to marking the request dirty and publishing once as it ends,
   which also makes the publish trivially skippable for reads. The cost is that a
   long-running CLI operation publishes only at the end.

   > **Response:** As leaned — mark the request dirty on the first committed write,
   > publish once as the request ends. The tree already has the pattern this hangs off:
   > `DatabaseService::InTransaction` is reentrancy-aware (only the outermost call
   > commits), and `StockService` already collects label-webhook payloads during a
   > transaction and fires them after commit — the dirty flag is that pattern carrying
   > one bit. Reads never publish. A CLI operation publishing only at the end is correct
   > rather than a cost: the importer and `bin/victual-migrate` both end. (2026-08-31.)

4. **Does the topic prefix carry a schema version?** `victual/…` versus `victual/v1/…`. I
   lean to no version. A version in the topic makes every consumer's configuration a
   migration when it changes, in exchange for a transition this household can perform by
   hand in five minutes; and retained topics need explicit retraction on rename either way,
   so the version does not save the cleanup it appears to.

   > **Response:** No version in the prefix, as leaned. One consequence of question 2's
   > opt-in entities is noted rather than feared: per-product topics raise the
   > payload-evolution surface, but a payload gaining an attribute is not a topic rename,
   > and the retraction mechanism question 2 commits to is the same one a rename would
   > need anyway. Revisit only if a change ever requires incompatible payloads on the
   > same topic. (2026-08-31.)

5. **QoS, protocol version, and whether the connection is per-request.** I lean to **QoS 0**
   with retain, a fresh connect-publish-disconnect per publish, and MQTT 3.1.1.

   That is against the instinct to reach for QoS 1, and the library is what changed it.
   `php-mqtt/client` — MIT, `php: ^8.0`, v2.3.2 as of 2026-03-28, TLS and retain
   first-class, and the obvious choice on adoption and upkeep — supports MQTT 3, 3.1 and
   3.1.1 but **not 5.0**, and needs `loop()` to be running to process the acknowledgements
   QoS 1 and 2 depend on. Running an MQTT event loop at the end of a web request to collect
   an ACK is precisely the shape this design exists to avoid, and the library additionally
   has no cross-session persistence for QoS 1/2, so the guarantee would be weaker than it
   looks anyway.

   QoS 0 is the right level here for a reason that is about the design rather than the
   library: **the snapshot is idempotent and every publish supersedes the last**, so a lost
   message costs nothing that the next write or the next boot does not repair. Paying for
   delivery guarantees on a message that is about to be replaced is paying for the wrong
   thing. Once the broker has accepted a retained publish it is durable there, which is the
   part that actually matters.

   Note the one thing this gives up: a publish lost between the pod and the broker is
   invisible until the next one, and if the pod then sleeps for a day the household sees
   stale entities for a day. Question 3's per-request publish and the boot publish are what
   bound that, and verification 8 is where it would show up.

   > **Response:** As leaned, entirely: QoS 0 with retain, connect-publish-disconnect
   > per publish, MQTT 3.1.1, `php-mqtt/client`. The library argument and the design
   > argument agree, and the design one is load-bearing: every publish supersedes the
   > last, so delivery guarantees on a message about to be replaced buy nothing the boot
   > publish does not already bound. (2026-08-31.)

6. **Is the publish path allowed to be optional?** `MQTT_ENABLED` defaulting to false means
   the fork ships a feature the fork itself is the only user of, and every other
   installation carries dead config. Defaulting it to true means an unconfigured install
   tries to reach a broker that is not there and logs a failure per write. I lean to
   disabled by default with the failure logged once per process rather than once per
   publish, on the grounds that this is a household-specific integration and should look
   like one.

   > **Response:** As leaned: `MQTT_ENABLED` defaults false, an unreachable broker logs
   > once per process rather than once per publish, and a failed publish never surfaces
   > into the write that triggered it — the security notes' last bullet already requires
   > that. A household-specific integration should look like one. (2026-08-31.)

7. **Does anything publish to InfluxDB?** I lean to no, and to recording why: Home
   Assistant's own InfluxDB integration already writes entity history, and Home Assistant
   is the component that is always on. A pod awake an hour a week would write a sparse
   series full of holes that mean "nobody was shopping", not "nothing was true". The
   history belongs to the consumer that is always there to observe it.

   > **Response:** Yes — scoped, and not for the reason the lean feared. The
   > sparse-series objection is right about *sampled state* and does not apply to
   > *events*: a point written when a purchase or a price change commits produces a
   > series whose gaps mean "no purchases", which is true rather than an artifact of the
   > pod sleeping. So the server writes price and valuation events directly to InfluxDB
   > on the same after-commit seam — per product: price paid, stock value — and that
   > channel, not MQTT, is where "how has spending shifted over time" is answered.
   > InfluxDB has its own credentials and is queried rather than broadcast, so the
   > wall-tablet test never applies to it; this is exactly the deliberate add question 8
   > says a household wanting pricing history should have to make. Home Assistant's own
   > InfluxDB integration still records entity history for the entities that exist, and
   > nothing pricing-shaped ever becomes a Home Assistant entity. What stays rejected is
   > the lean's actual target: sampling stock state into InfluxDB from a pod that is
   > mostly asleep. (2026-08-31.)


8. **Does the ambient payload carry prices?** [19](19-rbac.md)'s Q5 asks this plan to
   record the choice and offers three: publish without the fields it annotates
   `x-visibility`, publish per-role topics, or declare MQTT an admin channel. The first is
   the only one consistent with what is already here — per-role topics multiply the single
   discovery payload question 2 argues for, and "admin channel" is a claim about
   subscribers this design cannot check. I lean to **publish no prices**, with the
   assembler dropping every `x-visibility` field rather than maintaining a second list, so
   that MQTT never becomes the channel that answers a question the API refuses. The cost
   is that a household wanting a spending sensor has to add one deliberately, which is the
   right default for a payload anything on the broker can read. Note this is a constraint
   on the assembler rather than on the entity set: question 1 can keep the stock summary
   and still drop three columns from it.

   > **Response:** As leaned — no price or cost field, on any topic, with the assembler
   > dropping every `x-visibility` field rather than maintaining a second list, so MQTT
   > never becomes the channel that answers a question the API refuses. Taken as written
   > and knowingly: the `x-visibility` annotations arrive with [19](19-rbac.md)'s piece 2
   > in wave 5, so until then the price fields the security note below names are what the
   > rule denotes, and the assembler adopts the spec-driven form the day the annotations
   > exist. The cost the lean names — a spending sensor requires a deliberate add — is
   > now paid deliberately: question 7's InfluxDB event stream is that add, on a channel
   > with its own credentials rather than a broadcast one. (2026-08-31.)

## Executed

Landed 2026-09-02, against the eight Responses recorded above on 2026-08-31 rather than
against the leans they replaced. Measurements below were taken on
`.claude/worktrees/agent-ad096754dd4a1d9ea` (branch `worktree-agent-ad096754dd4a1d9ea`),
PHP 8.4.19, PostgreSQL 16 on `127.0.0.1:5432`, and an `aedes` broker on `127.0.0.1:1884`.

Eight commits, in the order the plan argues for — the dependency, then the transport, then
the assembler, then the triggers, then question 2's schema, then the two answers that
changed the shape of the work:

- **`e794ea8` — the dependency.** `php-mqtt/client` v2.3.2, MIT. **Divergence from the
  security notes' third bullet:** it is not one package with no runtime dependencies beyond
  PSR-3. It also pulls `myclabs/php-enum` 1.8.5, so `composer.json` grows by one direct and
  two installed packages. Cheap either way, and worth the sweep's dependency review knowing
  the real number.
- **`39cd2f7` — settings, publisher, discovery.** `MqttPublisher` connects, publishes a
  batch of retained QoS 0 topics over MQTT 3.1.1, disconnects. No last will, no availability
  topic. `DiscoveryPayloadBuilder` owns the topic layout and both discovery shapes.
  `ConfigurationValidator` refuses `MQTT_ENABLED=true` with an empty host.
- **`d4911d6` — the assembler**, reading the views the UI reads, with the price deny-list
  and the per-entity allow-list.
- **`185ed1c` — the triggers**, and `bin/victual-publish-state`.
- **`d8ed0b9` — migration 257 and the `ExposedEntity` entry**, question 2's opt-in flag and
  the publication ledger.
- **`30179ea` — the seventh and eighth ambient entities and the per-product diff.**
- **`e888f3e` — question 7's InfluxDB event writer.**
- **`5653658` — the devtools** under `.devtools/mqtt/`.

### The seam the after-commit trigger hangs off

`DatabaseService`, not the seven `StockService` entrypoints, and the choice is worth
recording because the plan's own text points at the entrypoints.

A write statement reaching the database marks the request dirty; `SetDbChangedTime()` — the
call `SessionService` and `ApiKeyService` already make to hide a last-used stamp from
`GET /api/system/db-changed-time` — clears the mark again. The shutdown handler then
publishes once, after `FlushDbChangedTime()`, having first asked PDO whether a transaction
is still open and skipped with a log line if one is.

Three properties follow, and only the first is available at the entrypoints:

1. **It is the same question the changed time already answers.** Chores, batteries, tasks,
   the shopping list and every generic CRUD write are covered without being named. Explicit
   `StockService` calls would have published a stock snapshot on a purchase and nothing at
   all on a chore being ticked.
2. **It fires once per request rather than once per commit,** which is what question 3 asks
   for. A shopping trip is many commits and one snapshot.
3. **Bookkeeping writes cost nothing,** because the existing restore idiom already says
   they are not data changes. Measured: an authenticated `GET /api/stock` advanced
   `api_keys.last_used` from `12:34:50` to `12:35:01` and published no topic.

One cost, and it is real: `DatabaseService` now names `MqttStatePublicationService` and
`BookingEventPublisher` directly. A listener registry would be cleaner, but registering a
listener needs a boot event PHP does not have, and holding the registry in process memory
between requests is what ADR-0007 forbids. The reference is guarded by
`defined('VICTUAL_MQTT_ENABLED')`, so an installation with the feature off pays one
constant read and never loads the class.

On SQLite the LessQL query callback had to be installed where it previously was not:
`SqliteDialect::RequiresChangeTracking()` returns false because the file modification time
*is* the changed time, so nothing was watching statements at all. The callback is now
installed when either change tracking or MQTT wants it.

### Topic layout

Prefix `victual`, no version (question 4). Each entity's state and attributes ride **one**
retained topic carrying `{"state": …, "attributes": {…}}`, read back through
`value_template` and `json_attributes_template`. One topic rather than two means state and
attributes can never be seen half updated, and halves what a subscriber receives.

```
victual/state/stock                              7 ambient sensors
victual/state/shopping_list
victual/state/next_chore
victual/state/next_battery
victual/state/next_task
victual/state/products_due_soon
victual/state/products_expired
victual/state/last_published                     the freshness fact
victual/state/product/<product_id>               one per opted-in product

homeassistant/device/victual/config              MQTT_DISCOVERY_MODE=device (default)
homeassistant/sensor/victual/<object_id>/config  MQTT_DISCOVERY_MODE=entity
homeassistant/sensor/victual/product_<id>/config always, whatever the mode
```

Per-product entities always take the per-entity discovery form. Removing one product must
retract exactly that entity, and folding hundreds of them into the single device config
would make every removal a rewrite of every other product's config.

Example payloads, from the demo database:

```
victual/state/stock
{"state":24,"attributes":{"products":[{"product_id":1,"product_name":"Cookies",
 "amount":14,"unit":"Pack","best_before_date":"2027-01-01"}, …]}}

victual/state/next_chore
{"state":"2026-08-27T23:59:59+00:00","attributes":{"chores":[{"chore_id":2,
 "chore_name":"Mop the kitchen floor",
 "next_estimated_execution_time":"2026-08-27T23:59:59+00:00"}, …]}}

victual/state/products_due_soon
{"state":4,"attributes":{"due_soon_days":5}}

victual/state/product/1
{"state":14,"attributes":{"product_id":1,"product_name":"Cookies","unit":"Pack",
 "best_before_date":"2027-01-01"}}
```

### On boot

PHP has no boot event, so "publish on boot" is `bin/victual-publish-state`, run from a
postStart hook or a Job alongside the initContainer that runs `bin/victual-migrate`. It
publishes discovery and the full snapshot, exits 0/1, and suppresses the request-end trigger
so one CLI run cannot publish twice. `--retract` clears every retained topic this version
owns, in **both** discovery modes plus every per-product topic the ledger remembers.

`bin/victual-migrate` and `bin/victual-db-import` deliberately do *not* suppress it: they
change data, so the request-end publish is exactly right for them and an out-of-band change
self-heals. There is no first-request-per-process publish; that would need process state.

### Divergences from the Responses, and what was deferred

- **Question 2's flag is a side table, not a column on `products`.** A column would change
  the shape of every products response — the invariant [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md)
  names — and on PostgreSQL it would not reach `products_view` or the views built on it
  without recreating all of them, where SQLite's `SELECT p.*` would pick it up silently.
  That is a divergence the differential suite would catch and nobody should have to fix.
  `mqtt_product_entities` is invisible to both.
- **Migration 257 is a pair, not one portable file.** Generated primary keys have no
  spelling both engines accept: SQLite's `INTEGER PRIMARY KEY AUTOINCREMENT` assigns ids on
  insert and PostgreSQL's `INTEGER PRIMARY KEY` does not.
  `.devtools/pgsql/check-migrations.php` reports a complete per-engine set, which needs no
  marker. Everything else about the two files is the same schema.
- **No foreign key on `mqtt_product_entities.product_id`.** This schema does its cascades
  with triggers rather than constraints (`products_DELETE`, `migrations/0225.sql`), and
  SQLite would not enforce a `REFERENCES` clause without a pragma the application does not
  set. The cascade is handled where it matters: the publisher joins `products`, so a
  deleted or deactivated product's entity is retracted and its orphan flag row dropped on
  the next publish. Verified below.
- **No product-form checkbox — deferred.** The flag is set and cleared through
  `POST`/`DELETE /api/objects/mqtt_product_entities`. The product form files belong to
  track B this wave, so a UI for it is a follow-on rather than part of this change.
- **`mqtt_product_entities` joins the `ExposedEntity` enum,** which adds a value to
  `GET /api/openapi/specification`. Additive, and the only wire change this plan makes; the
  Client impact section's "no HTTP client sees anything change" holds for every existing
  route and field.
- **The two count sensors are a knowing exception to this plan's own first rule.** A count
  of what is due within N days is a function of the clock, so it is a fact at publish time
  and stale afterwards. Question 1's Response promotes them anyway; the exception is
  contained by publishing no per-row derived boolean anywhere, so the stock summary still
  carries the dates a consumer needs to re-derive the number locally after midnight. The
  horizon comes from the configured `stock_due_soon_days` default rather than from the user
  who happened to make the request, because these topics are one household-wide snapshot
  with no reader identity.
- **Question 8's `x-visibility` form is a marked seam, not code.** The annotations arrive
  with [19](19-rbac.md)'s piece 2 in wave 5. Until then `StateSnapshotAssembler::DENIED_COLUMNS`
  is the deny-list the rule denotes, written out so it can be checked against the views by
  eye, and its docblock says where the spec-driven form plugs in.
- **Question 6's "once per process" is APCu-if-available.** ADR-0007 allows process memory
  only for pure caches, and a suppression window whose loss costs one extra log line is
  exactly that. Where APCu is absent — as it is in this environment — it degrades to one
  line per publish attempt, which for a web request is one line per request. Measured: three
  failing writes produced three log lines.

### Verification

Home Assistant itself was not available, so verifications **2** (entities repopulate after
a Home Assistant restart with the pod at zero), the Home-Assistant half of **4** (a template
derived from the attributes changes as the day rolls over) and **8** (a week of pod idle
time) **could not be run**. Everything else was, against a real broker.

1. **Retention actually retains.** Publisher connected, published, disconnected. A *fresh*
   subscriber (`php .devtools/mqtt/subscribe.php 127.0.0.1 1884 '#' 2`, clean session, a
   client id nothing else uses) then received all 11 topics — 8 ambient, 1 device discovery
   config, and one opted-in product's config and state — every one with `retain=true` and
   the correct payload.
3. **A write propagates, a read does not.** `POST /api/stock/products/1/add` (3 units at
   2.75) moved `victual/state/stock`'s product 1 from `amount 11, best_before 2027-03-01`
   to `amount 14, best_before 2027-01-01`, observed by a subscriber that connected only
   after the write. Two reads — `GET /api/stock` and `GET /stockoverview` — left the
   broker's log at exactly 28 lines. A bookkeeping-only request published nothing while
   provably writing: `api_keys.last_used` advanced from `12:34:50` to `12:35:01`.
4. **Facts, not derived states.** Every payload was read. No boolean appears anywhere in
   any of them; the only clock-dependent values are the two counts question 1's Response
   promotes deliberately, and `chores_current`'s own rollover recomputation, which is the
   view's behaviour and the same number the chores page shows.
5. **The broker being down cannot hurt a write.** Against a *refused* connection the write
   succeeded in **0.046 s** (`curl -w '%{time_total}'`) — a closed local port fails
   instantly, so this bounds nothing. Against an *unroutable* address (`10.255.255.1`, with
   InfluxDB pointed at the same) the write succeeded in **4.11 s** and **4.05 s** on two
   attempts, which is the two configured 2-second timeouts in sequence and nothing more; a
   read on the same instance took 0.012 s. Both failures were logged, neither reached the
   response, and both writes returned 200. **Worth an operator's attention:** the shutdown
   handler calls `fastcgi_finish_request()` when the runtime has it, so **the delay is off
   the response under php-fpm and on it under mod_php** or the built-in server — where two
   unreachable targets cost the sum of their timeouts. The 4 s above was measured under
   `php -S`, which is the on-the-response case; `function_exists('fastcgi_finish_request')`
   is false there, confirmed by probe, so it is the honest upper bound rather than the
   deployed one.
6. **Retraction works.** With 11 retained topics on the broker,
   `php bin/victual-publish-state --retract` exited 0 and a fresh subscriber then saw
   **0 messages**. The per-product path was verified three ways: clearing the flag
   (`DELETE /api/objects/mqtt_product_entities/1`) published empty payloads to
   `homeassistant/sensor/victual/product_1/config` and `victual/state/product/1`;
   deactivating a flagged product (`PUT /api/objects/products/2` with `active: 0`) did the
   same and left **0** orphan flag rows behind; and running `bin/victual-publish-state`
   twice in a row published the per-product topics **once**, the second run finding the
   ledger hash unchanged.
7. **The assembler agrees on both engines.** `.devtools/mqtt/engine-diff.sh` migrates a
   fresh SQLite database, seeds it, migrates a fresh PostgreSQL database, copies the first
   into it with the real `bin/victual-db-import`, assembles on each and diffs:
   `MQTT PAYLOAD IDENTICAL ON BOTH ENGINES`, 3735 bytes over 124 lines, byte-identical
   rather than merely equivalent. The fixture is deliberately awkward — the null-due-date
   sentinel, a below-minimum product with no stock, a priced purchase, a shopping list note,
   an expired product, a chore with no schedule, a battery with no interval, a task with no
   due date, and two opted-in products of which one has no stock.

Alongside those: `.devtools/mqtt/price-guard.php` passes all 22 checks (the deny-list covers
every column the security note names, no allow-list admits a money-shaped key, and
`AssertNoForbiddenKeys` rejects a price nested inside an attribute row while accepting a
realistic snapshot). `.devtools/pgsql/run-tests.sh` is green on all five phases —
`MIGRATION NUMBERING OK`, `MIGRATED STATE IDENTICAL`, `ALL VIEWS IDENTICAL` ×5,
`TRIGGER BEHAVIOUR IDENTICAL`, `EVERY FAILED OPERATION ROLLED BACK` on both engines,
`QUERY FILTER OPERATORS IDENTICAL`, `SUITE PASSED` — which matters because
`services/DatabaseService.php` was touched. `php -l` is clean on all 15 changed PHP files
and `victual.openapi.json` parses. The suite ran with `MQTT_ENABLED` and `INFLUXDB_ENABLED`
at their defaults, so the whole feature is provably inert when off.

Question 7's write path was verified against a stand-in InfluxDB (a small node server that
records the body and answers 204). A three-unit purchase at 2.75 produced exactly:

```
POST /api/v2/write?org=household&bucket=victual&precision=ns
authorization: Token …
content-type: text/plain; charset=utf-8
price_paid,product_id=1 price=2.75,amount=3.0 1788352542000000000
stock_value,product_id=1 value=33.66,amount=14.0 1788352542000000000
```

No user id, no note, no location — `product_id` is the only tag.

**The InfluxDB path does not depend on the MQTT gate, and did on first landing.** Review
caught it and the fix is recorded here with the baseline that makes it mean something.
`SqliteDialect::RequiresChangeTracking()` is false — the file modification time *is* the
changed time — so on SQLite the LessQL query callback is installed only because something
else asks for it, and the flag asked only whether MQTT was enabled. With
`INFLUXDB_ENABLED=true` and `MQTT_ENABLED=false` on SQLite, measured against the pre-fix
code: a **purchase** wrote both points, and a **consume** wrote nothing at all. The purchase
survived by accident — `CompactStockEntries` issues raw SQL, which marks the request dirty
through `ExecuteDbStatement` — while `ConsumeProduct` is LessQL only and had no such
accident. Two entrypoints of the same feature behaving differently is the shape of the bug,
and it would have been silent: the write succeeds, nothing is logged, and the series simply
has a hole.

Two halves to the fix. The callback is now installed when *either* publisher wants it
(`MqttStatePublicationService::IsEnabled() || InfluxEventWriter::IsEnabled()`), and the
shutdown guard asks for two independent pieces of evidence rather than one
(`!self::$DataChanged && !BookingEventPublisher::HasBookings()`) — the booking collector is
the InfluxDB path's own record of work and should not have to borrow MQTT's. The
open-transaction check still gates both. After the fix, the same consume on the same
configuration wrote its `stock_value` point (and correctly no `price_paid`, a consume not
being a purchase). Reads still publish nothing: three reads left the broker log unchanged
and the following write produced the full batch.

SQLite is dev-only once [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) is
accepted, and on PostgreSQL `RequiresChangeTracking()` is true so the callback was always
installed. The guard was wrong on its own terms regardless, which is why it is fixed rather
than documented as an engine quirk.

### Review fixes

Four findings on PR #32, all blocking, fixed 2026-09-02 in the same working copy. Two of
them are about durability and are the reason this section is long: the code was correct
about what it published and wrong about what happened when publishing failed.

**1. InfluxDB events were held in process memory and could be lost after a commit.** The
transaction ids lived in a static array, cleared before the batch was built, so a crash, a
timeout or a rejected write permanently lost a committed purchase — silently, since the
booking succeeded and nothing logged. That is the at-least-once invariant the constitution's
workload standard states and [ADR-0010](../adr/0010-workload-standard.md) formalises.

The fix is **a transactional outbox, and it is the first instance of ADR-0010's "one outbox
schema discriminated by event type"** rather than a private queue for this one consumer.
Migration 259 (a pair, for the same generated-primary-key reason 257 is) creates `outbox`
with `event_type`, a JSON `payload`, `delivered_at`, `attempts` and `last_error`.
`BookingEventPublisher::RecordTransaction()` now writes a row **inside the booking's own
transaction** — the calls moved into the `InTransaction` closures, one line each — so a
rollback takes the event with it and the queue can never describe a booking that did not
happen. (**Round 3 moved them again**, from the closures to the outermost commit, because
one call per entrypoint is several per transaction once the entrypoints nest.) `Drain()` reads the undelivered rows, builds one batch, POSTs it, and marks them
delivered **only on success**; a failure increments `attempts`, stores `last_error` and
leaves the rows. Both the request-end seam and `bin/victual-publish-state --drain` drain,
and the acknowledgement is a bookkeeping write through the restore-changed-time idiom so
draining cannot dirty the request that triggered it.

Two consequences worth stating. **Nothing is enqueued when `INFLUXDB_ENABLED` is false** —
an outbox nobody drains is a leak, so the gate is at the enqueue site and not only at the
drain. And the request-end guard now asks the outbox rather than process memory, which costs
one indexed query per request when InfluxDB is on and buys the thing that matters: a request
that books nothing still delivers what an earlier failed attempt left behind, so a queue
drains itself when the endpoint comes back rather than waiting for somebody to notice.

`EditStockEntry` was the one call not inside a transaction, because that method had none.
**Corrected in the second review round below**: it is now an eighth transactional
entrypoint, and [13](landed/13-write-path-transactions.md)'s Executed section records that too.

**2. A purchase point had no unique identity.** `price_paid` was identified by `product_id`
and a timestamp truncated to the second, so two purchases of one product within one second
were one point in InfluxDB, not two — the second silently replacing the first. `price_paid`
now carries the `stock_log` row id as `booking_id` and the `transaction_id`; `stock_value`
carries `transaction_id` too, so two transactions in one request cannot collide either.

This is also what makes finding 1's fix safe. At-least-once delivery means a batch can be
delivered twice, and a point in InfluxDB is identified by measurement, tag set and
timestamp — so writing the same identity again overwrites rather than appends, and a
redelivered batch is indistinguishable from one delivered once. Without unique identity the
outbox would have traded lost events for merged ones.

**3. Retained state publication could go backwards.** Request A assembles, request B commits
something later and publishes it, A publishes last — and retained topics carry no version
and no ordering, so the broker keeps A's stale snapshot until the next write. On a pod that
sleeps for days that is the failure this plan exists to prevent, and nothing logs it.

`DatabaseDialect::WithPublicationLock()` now sits beside `WithMigrationLock()`, on its own
advisory key so a publish never queues behind a migration. **Assembly is inside the lock,
not just the publish**: a lock around the publish alone still lets both requests read before
either writes, which is the same lost update with a smaller window. `Retract()` takes it
too, since a retraction racing a publish would otherwise be undone by it. The PostgreSQL
implementation carries the same session-mode pooling caveat as the migration lock, restated
rather than cross-referenced because the consequence is the same and the failure is as
quiet. SQLite's is a documented no-op for the reason its migration lock is.

No version was added to any topic. The `last_published` sensor already carries the freshness
fact and topic versioning is what question 4 declined; the lock is the fix.

**4. The MQTT client id could lose its randomness.** The suffix was appended and *then* the
whole string trimmed to 23 characters, so a configured prefix approaching 23 ate the
randomness and a prefix of 23 or more removed it entirely — two overlapping requests would
present the same client id and knock each other off the broker. The prefix is now trimmed to
ten characters first and the full twelve-hex suffix appended after.

**Verification of the fixes**, all reproducible from `.devtools/mqtt/`:

- **Durability and atomicity** — `outbox-check.php`, run against an unroutable endpoint. A
  booking enqueued one row of type `stock.transaction_booked` (whose payload was the
  identifier only at the time; the second round made it self-contained); the drain failed,
  the row stayed undelivered, `attempts` advanced to 1
  and `last_error` recorded `cURL error 28: Connection timed out after 2003 milliseconds`. A
  booking failed deliberately mid-transaction left the outbox at exactly the row count it
  started with. Pointing `INFLUXDB_URL` at a stand-in server and running
  `bin/victual-publish-state --drain` then delivered the events made while it was down —
  `price_paid,product_id=1,booking_id=1,transaction_id=… price=1.23,amount=2.0` among them —
  and left 0 undelivered; a second drain reported an empty outbox and sent nothing.
- **Recovery without anybody noticing** — a demo instance booked a purchase with the
  endpoint unroutable (89 events queued, counting demo generation), then a **read-only**
  `GET /api/stock` after the endpoint came back delivered all 89 in one batch, the 4.44
  purchase included, in 0.157 s.
- **Identity** — `outbox-check.php`'s third part: two purchases of one product produced two
  `price_paid` points with `booking_id` 2 and 3 and *identical* second-truncated timestamps,
  which is precisely the collision case, and every `stock_value` line carried a
  `transaction_id`.
- **Serialisation** — `lock-check.php` holds the lock in a child process and times how long
  the parent waits to get in. On PostgreSQL: `waited=3.00s while another process held the
  lock for 3.0s`, `SERIALISED`. On SQLite: `waited=0.00s`, `NOT SERIALISED, as documented for
  this engine`, which the probe treats as a pass because it is what
  `SqliteDialect::WithPublicationLock()` says it does.
- **Client id** — `client-id-check.php` across eight prefix lengths. A 30-character prefix
  yields `aaaaaaaaaa-a46fab290460`, 23 characters ending in 12 hex; a 23-character prefix the
  same; two ids from one long prefix differ.
- **Everything that was green stayed green** — `.devtools/pgsql/run-tests.sh` `SUITE PASSED`
  on all five phases with migration 259 in place (`MIGRATION NUMBERING OK` reports a complete
  pair, so no exemption), the both-engine payload diff still `MQTT PAYLOAD IDENTICAL ON BOTH
  ENGINES` at 3735 bytes over 124 lines, `price-guard.php` 22/22, and `php -l` clean on every
  changed file. A publish on PostgreSQL through the new lock put all nine topics on the
  broker.

One environment note for anyone reproducing this: the PostgreSQL server had been restarted
between sessions and came back as a stock cluster without the `victual` role, so the role was
recreated with the documented credentials before the suite would run. Nothing in the data was
reinitialised.

### Review fixes, second round

Four more findings on the merged branch, plus one process gap, fixed 2026-09-02. The
outbox landed with the right shape and the wrong details: it made delivery durable and left
three ways for the durability to be undone.

**1. An enqueue failure was swallowed, which made the guarantee conditional.**
`RecordTransaction()` caught everything `Enqueue()` threw and logged it. The reasoning at
the time - a metrics queue should not be able to stop the household recording their
shopping - reads well and is wrong, because catching it does not make the booking succeed
cleanly. An error that does not abort the transaction commits a booking with no event; on
PostgreSQL an error that *does* abort it surfaces later at `commit()` as something
apparently unrelated. Either way the caller is lied to. The catch is gone: a booking whose
event cannot be recorded has not fully happened, and rolling it back is the honest outcome.

**2. `stock_value` was derived at delivery time, so redelivery was not idempotent.** Two
distinct defects, one cause. The point used the clock at delivery, so a retry after a POST
that succeeded but was never acknowledged wrote a *second* point a second later - the exact
case at-least-once delivery makes routine. And it re-read `stock_current`, so a backlog
drained after an outage gave every queued transaction the *latest* snapshot rather than each
one's own post-commit value: a week of shopping would arrive as a week of identical
readings.

The fix is that the outbox event is now a self-contained immutable record. `CaptureEvent()`
runs inside the booking's transaction and stores `occurred_at` (the commit moment), the
`bookings` rows as they stand at that moment, and the per-product `stock_current` snapshot
for the products touched. `BuildLines()` reads the payload and **queries nothing**, so
rebuilding an event a week later is byte-identical. Payloads stay bounded because a
transaction touches a handful of products, and the facts are still the ledger's - captured
from it rather than duplicated into it by hand.

One thing that fell out of this: `UndoBooking` was enqueuing a second event for a
transaction `UndoTransaction` had already recorded, describing a half-undone state. Only the
outermost call records now.

**3. `--drain` delivered one batch and reported success.** A drain takes a bounded batch so
that one request never becomes an unbounded write, but the CLI is the thing an operator runs
*because* a backlog exists, so stopping after 200 events and exiting 0 was the least useful
possible behaviour. It now loops until the outbox is empty, reporting the batch count, and
stops at the first failed delivery with exit 1. The request-end drain deliberately still
takes one batch per request, which its docblock now says.

**4. `EditStockEntry` had no transaction.** It writes a correlated pair of `stock_log` rows
and mutates the stock row between them, so a failure part way left a booking pair whose
halves disagreed - and adding the outbox event gave it a ninth write that has to commit with
the rest. It is now an eighth transactional entrypoint in 13's shape, recorded in
[13](landed/13-write-path-transactions.md)'s Executed section as well as here, because that list is
the authority on which paths are transactional.

**5. The probes were not run by anything.** Three probes guarding four silent defects sat in
`.devtools/mqtt/` where neither the suite nor CI invoked them, so the green light protected
none of the fixes. `run-tests.sh` has a sixth phase, `mqtt`, part of `all` and therefore of
the workflow, which runs the client-id check, the price guard, the lock check (against its
own `SUITE_PGSQL_MQTT_DB`), the outbox probe, the new idempotency probe and the both-engine
payload diff. Every one of them is self-contained: no broker, no node, and InfluxDB stood in
for by PHP's own built-in server (`.devtools/mqtt/influx-standin.php`), because a probe that
only runs where somebody installed extra software is a probe CI skips. `db/pgsql/README.md`
listed four phases and now lists six.

**Verification.** All of it through the suite, which is the point of finding 5:

- **The enqueue is part of the booking** - `outbox-check.php` renames the outbox table out
  from under a purchase: the booking throws and `stock_log` is at exactly the row count it
  started with.
- **Redelivery is idempotent** - `idempotency-check.php` builds one event's lines, waits past
  a second boundary, books more stock to move the ledger underneath it, and builds again:
  byte-identical. Then two transactions on one product drained in one batch, `12` and `16`
  units, each `stock_value` point carrying its own post-commit amount rather than the later
  one.
- **The whole backlog drains** - 450 synthetic events delivered by one
  `bin/victual-publish-state --drain` in **3 batches of up to 200**, exit 0, 0 undelivered.
  With a stand-in rigged to reject after two writes: `failed after 2 batch(es)`, exit **1**,
  **50** left queued with `attempts=1`.
- **The edit path rolls back whole** - a failure injected after `EditStockEntry`'s ledger
  writes leaves neither the `stock_log` pair nor an outbox row.
- **The suite** - `SUITE PASSED` on all six phases with the `trackd_*` databases, the new
  phase's output visible: client id, price guard, `SERIALISED - the second caller waited for
  the first`, outbox, idempotency, and `MQTT PAYLOAD IDENTICAL ON BOTH ENGINES`.
  `check-migrations.php` reports four numbers above the baseline, and `php -l` is clean.

### Review fixes, round 3

Three more findings and two coverage asks, fixed 2026-09-02. All three findings are about
the same thing from different angles: the outbox was durable, and "one event" was not yet a
well-defined idea.

**1. Distinct events shared one InfluxDB identity.** A point is identified by measurement,
tag set and timestamp, and `stock_value` carried only `product_id`, `transaction_id` and a
second. Two things collide under that. **A transaction id is reused** - undoing a
transaction writes rows under the one it names - so an undo landing in the same second as
its purchase overwrote it, and landing in a different second left both standing as if they
were separate truths. And **the call graph nests**: `OpenProduct` delegates to
`TransferProduct`, `ConsumeRecipe` wraps `ConsumeProduct`, `UndoTransaction` loops over
`UndoBooking`, and `InTransaction()` deliberately lets an inner call join the outer one - so
capturing at each entrypoint produced several events per transaction, each recording a state
that was real only part way through the work.

Both halves fixed. Every captured event now carries a UUID `event_id`, tagged on
`price_paid` and `stock_value` alike, so identity is per event and no two can collide
however they are timed. And capture moved to the outermost commit:
`DatabaseService::RegisterBeforeOutermostCommit()` runs keyed work inside the outermost
transaction just before it commits, `RecordTransaction()` registers under the transaction id
rather than capturing, and the listeners are cleared on rollback so nothing leaks into the
next transaction. That hook is the general shape of "once per transaction, describing its
final state", which the tree had no seam for; putting the logic in `StockService` instead
would have meant every future entrypoint rediscovering the same trap.

**2. Unreadable events were silently discarded.** `BuildLines()` skipped a payload it could
not read, and `Drain()` then marked every row in the batch delivered - so a committed event
vanished with no `attempts`, no `last_error` and no trace. Retrying it forever is no better,
because it blocks every valid row behind it.

So the outbox has a third state. Every payload carries a `payload_version` (version 1 was
the transaction-id-only shape, before the event became self-contained; it is not upgraded
in place, because the ledger it would have re-read has moved on). `DescribeUnreadable()`
decides before anything is built, `Drain()` dead-letters those rows individually with the
reason, `GetUndelivered()` excludes them, and the CLI reports the count so a queue that is
empty only because rows stopped counting does not read as a queue that drained. The
`dead_lettered_at` column went into migration 0259 in place rather than a 0260, because 0259
has not reached master and no database anywhere ran the earlier shape.

**3. A database error looked like an empty queue.** `HasBookings()` caught everything and
returned false, which is right for its caller - a shutdown handler must not throw - and
wrong for the CLI, which exits 0 on the answer. They are now two methods:
`CountUndelivered()` throws and is what `--drain` uses, so it cannot report success on a
queue nobody managed to look at; `HasBookings()` still swallows, and its docblock says why
and points at the other one.

**Coverage.** The 450-event CLI scenario is now `backlog-check.php`, running the real
`bin/victual-publish-state --drain` as a subprocess - the exit code is half of what is being
asserted, and a reimplemented loop would pass while the real one was broken. It queues 450
against a rejecting stand-in, flips the stand-in to accepting through a control file rather
than restarting it (a restart would lose the request log the failure case counts), and
asserts three batches and exit 0; then repeats with the stand-in failing after two writes
and asserts exit 1 with exactly 50 left, and that a later run finishes the job.

And four probes - `outbox-check`, `idempotency-check`, `event-identity-check`,
`backlog-check` - now run **twice, once per engine**, from one SQLite database imported into
PostgreSQL through `bin/victual-db-import` so both sides start from identical data. The
outbox is where this feature turns on transaction semantics, and asserting it only on the
engine [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) makes a development one
would have left the deployment engine untested for exactly the properties the mechanism
exists to provide.

**Verification**, all inside the suite:

- **One event per transaction, with the final state** - `event-identity-check.php`: two
  entrypoints inside one transaction produce **one** event covering **both** bookings, whose
  snapshot equals the committed ledger amount rather than the state between them.
- **An undo cannot overwrite its purchase** - the same probe books and then undoes a
  transaction: two events, one transaction id, distinct `event_id`s, **identical
  second-truncated timestamps** (the collision case, reproduced), and **3 points, 3 distinct
  identities**.
- **Unreadable rows are set aside** - `outbox-check.php` queues a version 1 payload ahead of
  a valid one: the legacy row is dead-lettered with `payload has no payload_version`,
  `attempts` advanced, **not** marked delivered, no longer counted as waiting, and the valid
  row behind it is still deliverable.
- **The backlog scenario** - 450 events, `in 3 batch(es) of up to 200`, exit 0, 0 waiting;
  then with the stand-in failing after two writes, `failed after 2 batch(es)`, exit **1**,
  **50 of 450** left, and a later run clearing them.
- **The suite** - `SUITE PASSED` on all six phases, with every outbox probe reported twice:
  `(engine: sqlite)` and `(engine: pgsql)`.

### Review fixes, round 4

Three blocking findings, fixed 2026-09-03. Two are defects in what round 3 built; the third
is not a defect in the code at all but in this branch's relationship to the two beside it.

**1. This branch is not independently mergeable, and now says so.** Migration 0258 belongs to
[plan 01](landed/01-file-storage.md) and lives in PR #34; this branch carries 0257 and 0259. A
deployment migrated through this tree alone records `MAX(migration) = 259` while never having
run 0258, and 0258 merging afterwards does not fix what has already been decided on that
number: the migration *runner* is not fooled — it asks per number whether a row exists, so a
later 0258 is applied — but every gate built on the maximum is, and a gate is what decides
whether a deployment is allowed to serve. Fixing the gate is PR #33's, which makes the boot
check verify the complete required migration set instead of the highest recorded number. This
branch owns the other half: saying which number belongs to whom, and refusing to be green
while the sequence has a hole in it.

So `migrations/RESERVATIONS.md` is new — the record of every number above the baseline, its
owning plan, and whether its file is in this tree — and
`.devtools/pgsql/check-migrations.php` parses it and fails on a gap. It fails on this branch
today, by design and with the merge order in the message:

    #33 (boot check)  →  #34 (0258, files)  →  #36 (this branch)

Nothing was renumbered. Moving 0259 down to 0258 would collide with plan 01 rather than
close the hole, and moving *both* of this branch's numbers up leaves the same gap one place
further along. The check has a `--allow-reserved-holes` waiver, wired to
`SUITE_ALLOW_RESERVED_HOLES=1` in `run-tests.sh`, so that a branch in this position can still
run its own suite; CI does not set it, which is what keeps the enforcement real. Two stale
references to plan 01 shipping `0257.pgsql.sql` — in its own body and in the roadmap's note
on the engine-exclusive rule — are recorded in `RESERVATIONS.md` rather than edited here, so
the correction lands with the file it describes.

**2. Bookkeeping writes no longer rewind the global change timestamp.** The publication
ledger and the outbox both hid their writes with the idiom `SessionService` and
`ApiKeyService` use for last-used stamps: read `db-changed-time`, write, put the old value
back. That is safe for a last-used stamp inside one request and not safe here. Another
request can commit and flush a newer `system_db_changed_time` inside the window, and the
restore then overwrites it with the stale snapshot — so a client polling
`GET /api/system/db-changed-time`, which is a timestamp rather than a version, never learns
of the committed change until something else happens to write. The publication lock does not
close this: it serializes publishers, not the application writes happening beside them. The
old code also cleared the request's dirty flag as a side effect, so acknowledging a delivery
made the request forget it had changed data.

The fix is suppression rather than a monotonic restore, because the honest version of "this
was not a data change" is not to record one. `DatabaseService::RunAsBookkeeping()` holds a
reentrant depth counter; while it is above zero the LessQL query callback and
`ExecuteDbStatement()` skip both halves of change tracking, so nothing shared is written and
nothing has to be put back. A monotonic update was the alternative and is strictly worse
here: it still writes a row two requests contend on, to achieve what not writing achieves
exactly.

**One residual, and it is SQLite's.** There the changed time *is* the database file's
modification time, which the operating system advances when the write lands with no PHP
involved to suppress; hiding a write from it means rewinding it, which is the hazard. So it
is not hidden, and a ledger or outbox row can advance the changed time on SQLite. The cost is
at worst one redundant client refetch, and almost never even that — these writes happen on
the back of a request that already changed data, or during a backlog drain, on an engine
[ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) retires from runtime use and which
has no concurrent writer to be wrong about. The two last-used stamps deliberately keep the
old idiom and are **not** converted: they fire on every authenticated read, so letting them
advance the file modification time would make SQLite's changed time useless rather than
slightly noisy, and their window is one statement inside one request.

**3. A malformed nested payload is dead-lettered instead of being written as zeros.**
`DescribeUnreadable()` checked the version and that `bookings` and `stock` were arrays, so a
version 2 payload whose arrays were present but whose contents were missing or the wrong type
counted as readable. `BuildLines()` then cast what was there: an absent `product_id` became
product 0, an absent `amount` or `value` became 0.0, an absent `row_created_timestamp` became
`ToNanoseconds('')`. That is a corrupt point written into a series nothing will ever correct,
on a row then marked delivered — arriving through the gap between "the arrays are there" and
"the arrays say something".

Every key `BuildLines()` reads is now required with its type: `event_id` as an actual UUID
(`Uuid::isValid`, not merely non-empty — a blank identity puts every point of the event under
the same nothing and brings back the collision the UUID was added to prevent),
`transaction_id` as a non-empty string, `occurred_at` and each booking's
`row_created_timestamp` as a `Y-m-d H:i:s` timestamp that `DateTimeImmutable` accepts (shape
checked first, because that constructor also accepts "now" and "+1 day" and neither belongs
in a series), each booking's `booking_id`, `product_id` and `undone` as integers, `amount` as
a number, `price` as null or a number, `transaction_type` as a non-empty string, and each
stock entry's `product_id`, `amount` and `value`. The failure names the element and the
field — `bookings[2].row_created_timestamp is not a "Y-m-d H:i:s" timestamp ("")` — because
`last_error` is read by a person asking what stopped. Dead-lettering is per row and already
was, so one bad payload never stops the batch. `OutboxService::GetUndelivered()` also stopped
handing back a decoded scalar for a corrupt row, which would have been a `TypeError` taking
the whole drain with it.

**Verification**, all inside the suite, on both engines:

- **`payload-validation-check.php`** — fifteen malformed shapes, each refused with the field
  named, each building **zero** lines (in particular none carrying `product_id=0`), each
  dead-lettered individually with `attempts` advanced and **not** marked delivered, and a
  real booking queued behind all fifteen still deliverable afterwards.
- **`changed-time-check.php`** — a bookkeeping write does not mark the request dirty; a real
  data change made before one **survives** it; a concurrent writer's newer changed time,
  committed from a second connection inside a bookkeeping section that has already written
  its row, is still standing when the section ends; and on PostgreSQL the changed-time row's
  `xmin` is **unchanged** across ledger and outbox writes, which is the exact assertion a
  snapshot-and-restore implementation fails even though the value it restores is identical.
- **The numbering check** — `check-migrations.php` names 0258, its owner and the merge order,
  and exits 1.
- **The suite** — `SUITE PASSED` on all six phases with `SUITE_ALLOW_RESERVED_HOLES=1`, the
  two new probes reported twice each, `(engine: sqlite)` and `(engine: pgsql)`.

One unrelated defect was fixed on the way: `client-id-check.php` called
`ReflectionMethod::setAccessible()`, a no-op since PHP 8.1 which on 8.5 — the version
`composer.json` pins and the dev image runs — emits a deprecation notice onto stdout, where
the parent process read it back as the client id and failed all eight cases with a
diagnostic. The call is gone.

### Review fixes, round 5

Two blocking findings, fixed 2026-09-03. Both are the same mistake in two places: trusting a
record of what *this application did* as if it were a fact about *the system it was talking
to*.

**1. A write was acknowledged by things that were not acknowledgements.**
`InfluxEventWriter::Write()` discarded the response and returned true whenever Guzzle did not
throw — and Guzzle's `http_errors` raises for 4xx and 5xx but not for 3xx, while
`allow_redirects` is on by default. So a bare HTTP 302 came back as an ordinary response
nobody looked at, and a 302 followed to a login page came back as an HTTP 200 written by
something that is not InfluxDB. Both reported success. Through the real drain the bare 302 set
`delivered_at` with zero attempts and no `last_error`, so the event was never retried: a
committed event discarded silently, which is the one failure the whole outbox exists to
prevent, arriving at the last step.

The client now sets `ALLOW_REDIRECTS => false` and `HTTP_ERRORS => false`, and one place
decides what an acknowledgement is: a 2xx, from the address the request was sent to, with no
body. The empty body is part of the contract rather than fussiness — InfluxDB's v2 write API
answers `204 No Content`, so a 2xx carrying a page was answered by a proxy or a portal in
front of it. Every other outcome goes through `Reject()`, which records the status and a
bounded, single-line rendering of the body in `last_error` and leaves the row pending. If a
deployment ever puts something in front of InfluxDB that answers 2xx with a body, that is the
check to loosen deliberately, with the endpoint named.

**This was the only instance in this plan's code**, checked rather than assumed: the tree has
three Guzzle clients (`grep -rn "new Client("` over `services/`, `helpers/`, `controllers/`,
`bin/`), and the other two — `WebhookRunner` and `StockService`'s barcode-plugin image fetch
— are untouched by this plan and are not the same shape. Neither has a delivery ledger, so
neither can mark anything delivered on a non-acknowledgement; `WebhookRunner` is a label
printer fired and forgotten by design. The MQTT path shares no builder with any of them: it
is `php-mqtt/client` over TCP, not HTTP.

**2. A full refresh skipped the per-product topics the ledger said it had already sent.**
`PublishLocked()` compared each product's payload hash with `mqtt_published_entities` and
skipped a match — on every path, including the boot and CLI publish. But the ledger records
what this application last *sent*, and a full refresh exists to answer a different question:
what does the broker still *retain*? Everything here is QoS 0, so a message can simply be
lost, and a broker can be restarted without persistence, replaced, or have its retained
messages cleared by hand. In all of those the ledger still says "sent", so the product's
discovery and state topics stayed missing from Home Assistant until that particular product's
payload happened to change — which for a product nobody buys is never. The ambient topics
never had this problem because they are resent every time; the recovery this design promises
for QoS 0 losses simply did not cover the per-product half.

The parameter that already distinguished the two paths was named `$includeDiscovery`, which
described one of its consequences rather than what it means. It is now `$fullRefresh`, and the
ledger comparison is `!$fullRefresh && …`. The incremental path keeps the diff, because there
the ledger is answering the question it is good for — nothing has changed since we last sent
this — and resending hundreds of identical discovery payloads on every purchase is the cost
the diff exists to avoid. No other topic had the same skip: the ambient state topics are
rebuilt and published unconditionally on both paths, and the ambient discovery payloads are
already unconditional on a full refresh.

**Verification**, all inside the suite, on both engines:

- **`write-ack-check.php`** — a bare 302, a 200 carrying a login page, and a 500, each driven
  through the real `Drain()`: the row stays **undelivered and not dead-lettered**, `attempts`
  advances, and `last_error` names the status. **`/login` is never requested**, which is what
  proves the redirect was refused rather than followed. Then a 204 from the write endpoint,
  which *is* delivered. Then the same four cases against `Write()` directly.
- **`full-refresh-check.php`** — two consecutive `PublishDiscoveryAndState()` calls emit
  **13 topics, 4 of them per-product, both times** (the reviewer's two-then-zero, inverted),
  and the same topics rather than merely as many. A following `PublishState()` emits **8
  ambient topics and 0 per-product**, so the fix did not turn every write into a full resend.
  A booking then puts exactly that product's two topics back on the incremental path.
- **The suite** — `SUITE PASSED` on all six phases with `SUITE_ALLOW_RESERVED_HOLES=1`; the
  migration gap check still fails by design without the waiver, unchanged from round 4.

Two pieces of tooling made that possible and are worth naming, because both are stand-ins and
neither proves anything about the real thing. **`broker-standin.php`** is a PHP stream socket
speaking the CONNECT/PUBLISH/DISCONNECT subset `MqttPublisher` uses, recording each topic —
the "recording publisher" the finding asks for, and the first thing in this tree to exercise
`MqttPublisher` end to end. **`influx-standin.php`** grew `redirect` and `ok-with-body` modes
and a `/login` page that is always served, so "the redirect was not followed" is an assertion
about a request that was never made rather than about a response.

The first version of the full-refresh probe read the broker log as soon as `PublishBatch()`
returned, which races the stand-in still draining buffered packets. It failed *quietly*: the
SQLite leg reported 4 ambient topics where PostgreSQL reported 8, and a short topic list is
exactly the shape this probe treats as the defect. The stand-in now writes `=== end` when the
connection closes and the probe waits for it. Recorded because the near miss is the lesson: a
probe whose failure mode is indistinguishable from its finding is worse than no probe.

**What is still not covered.** Neither stand-in is the real system. There is no Mosquitto, no
Home Assistant and no InfluxDB in the suite, so "Home Assistant creates the entity", "the
broker retains the payload across a restart" and "InfluxDB accepts this line protocol" remain
hand verifications — the same three the Executed section already lists as outstanding.

### What a later record adds to this

[ADR-0012](../adr/0012-observations-are-proposals.md), **accepted 2026-09-04**, names this
plan's published snapshot as where a household is nagged about pending proposals. Nothing
of it is built — no `proposals` table, no endpoint, no plan owns the work — so this is a
constraint on the addition rather than a description of it, recorded here because the
addition would otherwise be designed against a shipped publisher nobody re-read.

- It is an **eighth ambient sensor**, `victual/state/pending_proposals`, on the topic layout
  above and in whichever discovery mode is configured. It is not a per-product entity.
- **The count is publishable; a proposal payload is not.** A proposal proposes a booking, so
  its payload can carry a price — and `StateSnapshotAssembler`'s `/price|cost|value/i` guard
  refuses to publish a column matching that whatever the allow-list says. That guard is the
  right answer here and not an obstacle to route around: the retained topic has no reader
  identity, question 8 settled on publishing no price or cost field on any topic, and a
  household that needs the detail opens the queue. `state` is the count; attributes carry
  at most non-priced identification of what is waiting.
- Publication follows the same after-commit seam as everything else, so confirming or
  rejecting a proposal republishes the snapshot exactly as a booking does.

### What this changes in the record above

The security notes' third bullet understates the dependency: two packages are installed,
not one (`e794ea8`). The fourth bullet's column list is now implemented as
`StateSnapshotAssembler::DENIED_COLUMNS`, which adds `avg_price`, `last_price_unit`,
`last_price_total`, `note` and `api_key` to the names it gives, and is enforced twice over
by a per-entity allow-list and a `/price|cost|value/i` guard that refuses to publish rather
than publishing something with a price in it.

## Effort

Small, and mostly not the MQTT part.

- The snapshot assembler is the real work, and it is the same work as an aggregate read
  endpoint: query the views the UI already uses, shape them into a payload. If something
  later wants that synchronously, it is the same function behind a route.
- The publisher is a service, a config block of the usual `Setting()` shape, and a call at
  two seams that already exist.
- The discovery payloads are a builder that runs once per publish and is mostly a literal.
- One new Composer dependency, `php-mqtt/client` (MIT, `php: ^8.0`, v2.3.2 2026-03-28,
  ~2.7M installs, actively kept current with PHP 8.4). The alternatives are a ReactPHP
  client, two coroutine-runtime clients built for Swoole and Workerman, and an
  unmaintained one last released in 2021 — none of which fit a synchronous connect,
  publish, disconnect inside a PHP request. See question 5 for what its QoS support
  implies.

The judgement in question 1 is what will take the time, and it is the kind that is settled
by living with the result rather than by design.

## Security notes

Recorded here rather than left to a later sweep, since this plan adds the first outbound
connection the application makes that is not the label printer.

- **The broker address is a configured constant**, exactly like
  `VICTUAL_LABEL_PRINTER_WEBHOOK`. Nothing in a request can influence where a publish goes,
  so the sweep's finding that no user-configurable outbound URL exists still holds after
  this plan. That property is worth protecting deliberately: if a future change ever lets a
  user name a broker, it needs S4's trusted-target treatment first.
- **Credentials are settings**, so they land in `data/config.php` or the environment and
  must never be added to `SystemApiController`'s `EXPOSED_SETTINGS` allowlist. The
  allowlist is an allowlist precisely so this stays a non-event.
- **A new dependency is a new supply chain.** `php-mqtt/client` is the cheap end of this,
  but it is the first addition to `composer.json` this fork has made and the sweep's
  dependency review should pick it up next time round. Corrected on landing: it is not one
  package with no runtime dependencies beyond PSR-3 — it also pulls `myclabs/php-enum`
  1.8.5, so the installed count grows by two. Question 7's InfluxDB writer adds no package
  at all; it goes through the Guzzle client already in the tree.
- **The retained payload is household data on a shared broker.** Anything with access to
  the broker can read the household's stock and chores without authenticating to Victual.
  That is a real widening of who can see this data, it is accepted here because the broker
  is on the same private cluster with its own credentials, and it is a reason not to
  publish anything that would not also be shown on a wall tablet — no user records, no

  notes fields, no API keys, and — per **question 8**, answered — no prices. Prices are the
  addition [19](19-rbac.md) forces and the one this plan would otherwise have missed. The
  wall-tablet test excludes them on its own reasoning, since a broker subscriber is not a
  logged-in user and cannot be made into one, which is why 19's Q5 is carried here rather
  than gating that plan; question 8's Response adopts the lean (2026-08-31). The exposure is real either way: if the
  stock summary's attributes are assembled from `uihelper_stock_current_overview`, which
  the UI reads and which selects `value`, `last_price` and `average_price`
  (`migrations/0252.sql:38-39`), they ship by default unless something removes them.
  Concretely, the v1 entity set in question 1 carries none of `stock.price`,
  `stock_log.price`, `products_average_price`, `product_price_history`,
  `products_last_purchased.price`, `last_price`, `avg_price` or a recipe's `costs`, and
  adding an entity that would is a change this bullet has to be edited to permit. As built
  that list is `StateSnapshotAssembler::DENIED_COLUMNS`, which also names
  `last_price_unit`, `last_price_total`, `note` and `api_key`, and it is not trusted on its
  own: each entity carries an allow-list of the only keys it may emit, and
  `AssertNoForbiddenKeys()` walks the finished payload and throws on any key matching
  `/price|cost|value/i` rather than publishing it. `.devtools/mqtt/price-guard.php` is the
  check. Question 2's per-product entities carry product name, unit, amount and
  `best_before_date` and nothing else, so they do not widen this.
- **Question 7 adds a second outbound connection: InfluxDB.** Same treatment as the
  broker — the endpoint is a configured constant nothing in a request can influence, the
  token is a setting that never joins `EXPOSED_SETTINGS`, and a failed write to it never
  surfaces into the committed write that triggered it. Unlike the broker there is no
  retained-payload exposure: InfluxDB is queried with credentials, not subscribed to.
- **A publish must never carry a failure into a committed write.** Short connect and
  publish timeouts, exceptions caught and logged, and the write path unaffected. The
  transaction is already closed by then, per 13.
