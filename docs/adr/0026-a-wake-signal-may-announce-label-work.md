# ADR-0026: A contentless wake signal may announce label work; the pull API stays the only way work is claimed

- **Status: Proposed.** A retained MQTT topic per worker may say *that* there is work, carrying
  no description of it. Claiming, leasing and acknowledging are unchanged and stay on the
  authenticated pull API. The broker is an optimisation and never a dependency: with
  `MQTT_ENABLED` false, or with the broker unreachable, every label still prints. One mechanism
  serves two topologies — an event-driven scaler where the orchestrator has one, and a resident
  mode in the binaries where it does not — so a household on Docker Compose, Swarm or a single
  machine gets the same behaviour as the maintainer's k3s cluster.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the lifecycle
  rule in [the index](README.md).
- **Recorded:** 2026-09-19.
- **Supersedes in part:** [ADR-0019](0019-label-printers-are-master-data.md) decision item 2 —
  the paragraph rejecting MQTT at `Why pull rather than the alternatives`, and the Consequences
  sentence *"Victual makes no outbound connection for printing, at any point. Not to a printer,
  not to a worker."* The rest of item 2 stands and this record depends on it: the worker holds
  no database credential, Victual is the server, and work is claimed rather than pushed.
- **Relationship:** [ADR-0010](0010-workload-standard.md) governs both workloads and its
  at-least-once and idempotency rules are what make a lossy hint safe.
  [ADR-0009](0009-database-as-the-logic-layer.md) — **Proposed** — describes the shape this
  record instantiates ("the durable pattern is a table drained with `SELECT … FOR UPDATE SKIP
  LOCKED`, with `NOTIFY` used only to wake the drainer"); this record does not depend on 0009
  being accepted, because the durable half already exists in
  `services/Labels/PrintAttemptService.php` and only the waking half is new.
  [ADR-0007](0007-auth-state-outlives-the-process.md) forbids state in process memory between
  requests, which is why Victual still does not subscribe to anything.
- **Referenced by:** [18 — MQTT state publication](../plans/18-mqtt-state-publication.md), whose
  transport and topic conventions this reuses; [25](../plans/25-label-infrastructure.md), which
  owns the worker and delivery; [27](../plans/landed/27-label-templates-and-rendering.md), which
  owns the renderer and its deployment shape.

## Context

[ADR-0019](0019-label-printers-are-master-data.md) was accepted 2026-09-07 and considered MQTT
by name, rejecting it in four sentences. Each is quoted here, because three of them are still
right and this record only disturbs the part that was answering a different question.

> a print job is a unit of work claimed by exactly one worker with a lease and an
> acknowledgment, not a state fact published to whoever is listening, and QoS 1 gives
> redelivery without giving exclusivity

Correct, and untouched. What that paragraph was rejecting is MQTT *as the queue* — the job
travelling on the topic and the worker treating delivery of a message as possession of the
work. Nothing here proposes that. Exclusivity stays where it already is:
`PrintAttemptService::Claim()` selects `FOR UPDATE OF j SKIP LOCKED` and inserts a
`print_attempts` row under `UNIQUE (outbox_id, attempt_number)`, and the outbox row is
acknowledged only when the attempt completes. A message that carries no job cannot compete with
that, because a worker that receives one still has to ask.

> It would also make the broker a hard dependency of printing

This is the sentence this record has to earn, and it earns it by construction rather than by
argument. The signal is a hint on a best-effort transport. The worker keeps a bounded fallback
poll, drains the queue at startup before it subscribes to anything, and treats every message as
"ask again now" rather than as information. Delete the broker and the system degrades to its
current behaviour exactly; it does not stop printing. That is a property to be tested, and it is
acceptance prerequisite 2 below.

> and put household print jobs on a bus whose access is already noted in plan 18 as wider than
> Victual's own authentication

Two things narrow this. The first is that no print job goes on the bus — decision 1 fixes the
payload to a counter, and decision 2 fixes the topic. The second is a fact the September record
could not assume and the maintainer has since stated: the household broker is authenticated and
uses TLS. Plan 18's warning is about *reader identity* — a retained topic is a visibility
decision made once for every subscriber — and that warning still binds, which is why decision 1
enumerates the payload exhaustively instead of describing it.

> Victual makes no outbound connection for printing, at any point. Not to a printer, not to a
> worker.

This is the sentence that has to be replaced rather than narrowed, and it is worth being exact
about what it was protecting. It was protecting the worker's placement: a USB-attached worker on
a bench outside the cluster must stay possible, which fails the moment Victual has to reach the
worker to give it something. Publishing to a broker does not reach the worker. The worker
reaches the broker, from wherever it sits, with its own credential, exactly as it already
reaches the API. The replacement property is stated in decision 8 and keeps the constraint the
original sentence existed for.

### What changed since 2026-09-07

Three things, and only the first is a defect.

- **The worker is deployed as the wrong shape.** Its acquisition loop breaks on an empty queue
  and the process exits 0, while `deploy/podman/label-workers.yaml` runs it as a `replicas: 1`
  Deployment with no `--once`. The effective poll interval is therefore kubelet restart
  backoff — 10 s ramping to a 5-minute cap — and every restart re-runs
  `POST /api/labels/register`. A queued job can wait five minutes for no reason. The tempting
  reading is that the loop is buggy; it is not. It exits only when it holds no lease, which is
  precisely correct for a run-to-completion workload, and the Deployment is what disagrees with
  it. This is a defect regardless of this record, and decision 5 says which side of the
  disagreement is wrong.
- **The renderer's floor is a minute, and a shopping trip is a batch.** The CronJob runs
  `--drain`, so twenty labels from one trip render in a single run rather than over twenty
  minutes — the wait is one interval, not twenty. One interval is still up to sixty seconds
  before the first label reaches a printer that is standing idle.
- **The broker is authenticated and TLS.** Stated by the maintainer 2026-09-19. ADR-0019
  reasoned from plan 18's unauthenticated-bus caveat, which described what plan 18 could
  guarantee, not what this household runs.

### Why not the alternatives that need no record

**Long-polling the claim route** is the obvious ADR-clean answer and it is the wrong one here.
Holding `POST /api/labels/jobs/claim` open pins a php-fpm child per worker for the life of the
wait, which means Victual is permanently awake. Scale-to-zero is the premise plan 18 is built
on and the premise [ADR-0009](0009-database-as-the-logic-layer.md) says it must be rejected
without. Trading the pod's sleep for a printer's latency is a bad trade, and it is a trade the
corpus has already refused in the other direction.

**`LISTEN`/`NOTIFY`** is the pattern ADR-0009 names, and it cannot reach these workers: a
listener needs a database connection, and the worker holding no database credential is the
whole of ADR-0019 item 2. It would work for an in-cluster bridge, which is a component that does
not exist and whose only job would be to turn a notification into a message on a bus — that is,
to be this record with an extra process.

**A shorter poll interval** is the honest cheap answer and it is what the system gets if this
record is rejected. It costs a request per worker per interval against a pod whose sleeping is
the point, and it buys a latency floor equal to the interval. It is strictly worse than a hint
that costs nothing while idle, but it is not *bad*, and rejecting this record leaves a working
system rather than a gap.

The asymmetry that decides it: a job can only be enqueued by an HTTP request, so Victual is
awake at the exact moment there is something to announce. The publish is free. The worker's wait
costs Victual nothing, because the worker waits on the broker rather than on Victual.

## Decision

1. **The signal is contentless, and its payload is enumerated rather than described.** The
   retained payload is a JSON object with exactly two keys: `pending`, a non-negative integer
   count of jobs awaiting this worker, and `at`, an ISO-8601 timestamp. No label uid, no printer
   id, no job id, no template, no capture field, no entity name, no address. A consumer that
   wants to know what will print has to authenticate to Victual. The
   `/price|cost|value/i` guard that `StateSnapshotAssembler::AssertNoForbiddenKeys()` applies to
   state topics is satisfied trivially and stays in force for anything added later.

2. **One retained topic per worker:** `victual/labels/workers/{worker_id}/pending`, under the
   configured `MQTT_TOPIC_PREFIX`. Per worker rather than global because
   `PrintAttemptService::Claim()` already scopes work by `worker_id`, so this is the granularity
   the system thinks in, and a global topic would wake every worker for every job. The cost is
   that worker ids and per-worker print cadence become visible to anything the broker admits,
   which decision 9 is the answer to.

3. **Claiming is unchanged.** The worker still calls `POST /api/labels/jobs/claim` with its
   typed key, still receives the manifest with the claim, still fetches artifact bytes over its
   own authorized route, and still reports `sent`, `result` and `evidence` as it does today. A
   message is a reason to ask and is never evidence that work exists — the queue may be empty by
   the time the worker asks, and that is an ordinary outcome, not an error.

4. **The broker is optional and the fallback poll is mandatory.** The worker takes a bounded
   poll interval that it honours whether or not MQTT is configured, and it drains the queue at
   startup before subscribing. `MQTT_ENABLED` stays false by default. A worker that cannot reach
   the broker logs once and keeps polling; it does not exit, and it does not report itself
   unhealthy — `--health` stays contact-free, so a broker outage must not restart a worker
   holding a lease.

5. **Both workloads are run-to-completion, and the worker already is one.** An earlier draft of
   this record kept them different shapes on the grounds that the worker is resident because it
   holds a lease. That is wrong about the code. `main.rs` breaks its claim loop only on
   `jobs.is_empty()` and never mid-attempt, so the process exits exactly when it holds no lease.
   The worker is already a run-to-completion workload; what it is *deployed* as is a
   `replicas: 1` Deployment, and that mismatch is the whole of the restart-backoff defect. The
   fix is therefore the manifest, not the loop. Where the platform offers event-driven
   run-to-completion scaling, both the worker and the renderer take it, driven by the same
   mechanism and the same payload shape — `victual/labels/workers/{worker_id}/pending` for the
   worker, `victual/labels/renders/pending` for the renderer. This is safer than scaling a
   Deployment down, because a run-to-completion job is not preempted between claiming and
   reporting, which is the case [ADR-0019](0019-label-printers-are-master-data.md) item 6 calls
   uncertain.

6. **The mechanism is portable, and the binary is the floor.** A scaler is an answer for
   Kubernetes and for nothing else. A household running Docker Compose, Swarm, plain systemd
   units or a single machine with neither has the same printer and the same minute of latency,
   and a design that serves only the maintainer's k3s cluster is not a design this fork ships.
   So there are **two supported topologies over one mechanism**, and every deployment picks one:
   - **Event-scaled**, where the orchestrator has a broker-aware scaler. Both binaries run
     unchanged and unaware, exactly as they do today; the scaler subscribes and starts them.
     This keeps scale-to-zero. **No such scaler exists off the shelf today** — KEDA has no
     MQTT trigger in core and the pull request adding one is unmerged, which open question 1
     documents — so this branch is what the mechanism *permits*, not a shape any deployment
     can adopt right now. It is written conditionally on purpose: nothing here requires it,
     and the payload does not change if it later becomes available.
   - **Resident**, everywhere else. Both binaries gain an optional `--wait` mode with an
     optional MQTT subscription and a mandatory bounded fallback poll, so one long-lived process
     serves a Compose service, a Swarm task or a systemd unit with no orchestrator features
     assumed. `--once` and the existing default behaviour are unchanged, so nothing that runs
     today runs differently.

   The timer stays the floor under both: the per-minute CronJob, a systemd timer or a Compose
   restart loop, so a deployment with no broker and no scaler behaves exactly as it does now.

   This costs the renderer the property an earlier draft protected — that it gains no
   dependency. That trade is worth naming rather than hiding: the closure argument
   [its README](https://github.com/datagen24/victual-label-renderer) makes is about admitting no
   shell and no interpreter, against 316 MB for a Pillow environment and 1.8 GB for Chromium. A
   synchronous pure-Rust MQTT client adds neither a shell nor an interpreter and is a rounding
   error against 62 MB, so the argument survives the dependency; it would not have survived an
   async runtime, which is why decision 7 forbids one.

7. **The client is synchronous and the subscription is not a health signal.** Both binaries are
   blocking-threaded with `panic = "abort"`; pulling an async runtime into either to receive a
   counter would be a poor trade. A broker that cannot be reached is logged once and retried
   while the fallback poll continues; it never exits the process, never fails `--health`, and
   never abandons a lease. A worker restarted for the broker's outage would be restarted for
   somebody else's outage, which is the failure the existing manifest comment already refuses.

8. **Victual connects outward to the broker and to nothing else, for printing or otherwise.**
   It makes no connection to a printer and no connection to a worker, and it exposes no route
   by which work is pushed. A worker's reachability from Victual remains irrelevant to whether
   it can print, which is the property ADR-0019's replaced sentence was defending, and which a
   USB-attached worker on a bench still satisfies.

9. **A broker carrying wake topics is authenticated and TLS.** Unlike the state topics, whose
   threat model is "a wall tablet can read this", the wake topics disclose household activity
   timing. This is a deployment requirement, not something the code can enforce; `MQTT_TLS` and
   broker credentials already exist as settings, and both default to off and empty.
   **The operator manual does not say this today and has to be changed to say it.**
   [The Home Assistant MQTT chapter](../manual/operator/home-assistant-mqtt.md) and
   [the configuration reference](../manual/configuration.md) currently permit an anonymous
   broker with `MQTT_TLS` false, which is a defensible default for the state topics under plan
   18's own threat model and is not defensible for these. Writing that guidance is part of the
   implementing work this record gates, so accepting the record without it would leave the
   requirement stated nowhere an operator reads.

## Consequences

- Printing latency stops being a function of a clock. A job enqueued by a request is announced
  in the same shutdown seam that already publishes state, and a waiting worker claims it in the
  time it takes one HTTP round trip.
- The restart loop that today re-registers capabilities every few minutes stops, under either
  topology: event-scaled, because a finished job is not a crash to back off from; resident,
  because the process no longer ends.
- Victual still subscribes to nothing, so ADR-0007 is untouched and no always-on PHP workload is
  introduced.
- A new dependency appears in **both** Cargo closures, used only in resident mode. It must be a
  synchronous client, per decision 7. An event-scaled deployment never executes that code path,
  so the k3s images carry a dependency they do not run — which is the price of one binary
  serving both topologies, and cheaper than two.
- **Two supported topologies is two things to keep working.** The resident path is the one most
  households will use and the event-scaled path is the one the maintainer runs, so neither is
  the neglected branch by default. Both are exercised by the prerequisites below, and a change
  to the claim loop has to be considered against both.
- Spurious wakes are harmless and expected. Each costs one `SKIP LOCKED` query that returns
  nothing.
- Anything the broker admits learns when the household prints and how often. Decision 9 is the
  mitigation — broker authentication and TLS — and it is an operator obligation that the manual
  does not yet carry.
- Plan 18's rule that only *facts* go on topics is kept: `pending` is a count, not "you should
  print now".

## Acceptance prerequisites

Each is a gate. The accepting pull request says how each was met.

1. **Both topologies print, with no broker at all.** Event-scaled: the worker runs as a
   run-to-completion workload and several jobs print without the restart backoff the Deployment
   produces today — this needs no scaler, only the corrected manifest, so it is unaffected by
   open question 1. Resident: `--wait` with MQTT unconfigured runs for an hour across several
   jobs without the process ending and without a re-registration. This is a prerequisite rather
   than a consequence — it is the floor everything else is an optimisation over.
2. **Killing the broker does not stop a print.** With the worker subscribed, stop the broker,
   enqueue a job, and show it printed on the fallback poll. Then restart the broker and show the
   worker recovers without operator action.
3. **Nothing readable is on the topic.** Subscribe to `${MQTT_TOPIC_PREFIX}/labels/#` with a
   broker client through a full print of a real label, and show the captured payloads contain no
   uid, no entity name and no printer detail. Run it once with a **non-default**
   `MQTT_TOPIC_PREFIX`, because decision 2 puts the wake topics under the configured prefix and
   a check written against the `victual` default would pass while publishing somewhere it never
   looked.
4. **Latency is measured, not asserted.** Record the interval from enqueue to bytes leaving the
   worker, with and without the broker, on the same hardware, and put both numbers in plan 25.
5. **The resident path is demonstrated on something that is not Kubernetes.** A real label
   printed under Docker Compose, woken by the topic, with the images byte-identical to the ones
   k3s runs and the timer still in place. A record that only works on the decider's own cluster
   has not met this gate.

   **This gate was written to require the event-scaled path on k3s as well, and that half is
   withdrawn** — open question 1 found there is no MQTT scaler to demonstrate it with. It
   returns as a gate only if question 1 is answered by building or adopting one; until then,
   demanding a demonstration of a component that does not exist would make this record
   unacceptable rather than rigorous.
6. **A claim is still exclusive, and the lease fence is unmoved.** Run two workers against one
   printer's queue with the subscription live and show that the
   `UNIQUE (outbox_id, attempt_number)` fence still admits exactly one attempt — that the hint
   changed nothing about exclusivity. The neighbouring property, that a report arriving after
   the lease expired is **recorded rather than refused** and leaves the attempt uncertain, is
   [plan 25](../plans/25-label-infrastructure.md)'s verification 8 and is not re-tested here:
   this record changes nothing about leasing, and a gate that restated someone else's would
   drift from it. What is this record's is that waking more often does not reach that fence more
   often than polling did.

## Open questions

1. Which scaler, for the deployments that have one — **and the honest answer today is that
   there is not one.** An earlier revision of this record named KEDA's MQTT `ScaledJob` as the
   obvious candidate. That was wrong, and checking it is what this question is for:

   - [kedacore/keda#1282](https://github.com/kedacore/keda/issues/1282), asking for an MQTT
     scaler, was opened in October 2020 and is **still open**, labelled "help wanted".
   - [kedacore/keda#8189](https://github.com/kedacore/keda/pull/8189) would add one. It was
     opened **2026-09-16, three days before this record**, and is **unmerged**, with review
     concerns outstanding — among them credentials crossing the wire in plaintext when a
     non-TLS scheme is configured, which is the exact hazard decision 9 exists to close.
   - The example most search results reach,
     [andschneider/keda-mqtt-example](https://github.com/andschneider/keda-mqtt-example),
     demonstrates the gap rather than closing it: it builds a custom scaler from a personal
     fork of KEDA and tells you to build and deploy KEDA by hand.

   So the event-scaled topology has no off-the-shelf component behind it. Three ways forward,
   and the recommendation is the third:

   - **Wait for #8189.** Not something a design may depend on: it is unmerged, under review,
     and its current security posture is one this record would have to overrule.
   - **Own an external scaler.** KEDA's `External`/`ExternalPush` trigger is a supported
     extension point — a gRPC service the fork would write, publishing the same
     `pending` counter it already publishes. This is buildable today and would be a workload
     [ADR-0010](0010-workload-standard.md) governs. It is also the fork owning a piece of
     Kubernetes machinery to serve one deployment shape.
   - **Recommended: make resident the only topology the fork supports, and leave event-scaled
     to whoever builds a scaler.** Decision 6's second branch needs nothing that does not
     exist, works identically on k3s and on Docker Compose, and costs the two workloads their
     scale-to-zero in exchange for two small resident processes. If #8189 lands, or if someone
     writes an external scaler, the event-scaled branch becomes available **without changing
     the mechanism or the payload** — which is the property decision 6 was really buying.

   **This needs deciding before acceptance, because prerequisite 5 cannot be met as written
   while the answer is the third option.** See the note under it.
2. Whether `pending` should be published on transitions to zero as well as on enqueue. Publishing
   the zero is what makes the retained value truthful for a late subscriber; not publishing it
   halves the traffic. Recommendation: publish it, because a retained topic that is only ever
   non-zero is a topic that lies to whoever connects next.
3. Whether the renderer topic should also be per worker. Renders are not assigned to a specific
   renderer — `RenderRequestService::Claim()` takes the oldest pending request — so there is no
   per-worker scoping to mirror, and a single topic is proposed above.
4. Whether the wake publish should share the state publication's advisory lock. It does not need
   the lock's mutual exclusion, since a counter is idempotent and self-correcting, and taking it
   would queue a print announcement behind a full snapshot assembly. Recommendation: do not take
   it.
