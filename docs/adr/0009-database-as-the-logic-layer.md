# ADR-0009: The database is the logic layer

- **Status: Proposed.** Not accepted, not scheduled, not in the roadmap's wave order.
  [ADR-0008](0008-postgresql-only-runtime-engine.md), which this depends on, was
  accepted 2026-08-31 — the dependency no longer stands in the way of judging this
  record on its merits.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request, and this record
  carries **acceptance prerequisites** — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30.
- **Depends on:** [ADR-0008](0008-postgresql-only-runtime-engine.md). **This decision is
  not seriously available while SQLite is a runtime engine** — see *Why this depends on
  0008*. If 0008 is rejected, this is rejected with it.
- **Would affect:** [18](../plans/18-mqtt-state-publication.md),
  [02](../plans/02-mcp-endpoint.md), [19](../plans/19-rbac.md),
  [10](../plans/landed/10-cold-start-statelessness.md),
  [14](../plans/landed/14-contract-and-regression-scaffolding.md).

## Context: what scale-to-zero actually charges for

Two things, and only two:

- **Time to first correct response** on a cold pod.
- **Anything that refuses to let the pod go cold.**

**Views and functions barely touch the first.** Cold start here is PHP bootstrap —
autoload, Slim, Blade, config, `PrerequisiteChecker` — and that cost is identical whether
`StockService` issues forty queries or one. Published measurements for KEDA-style HTTP
scale-to-zero put the first request after a scale-up at 2–5 seconds, and 15+ where the
image and its init logic are heavy. Against that, saving thirty round trips inside one
household-sized database is noise. **Any version of this proposal that sells itself on
cold-start latency is overselling.**

**It hits the second decisively, and that is the entire prize.**

PostgreSQL is the one component of this deployment that cannot scale to zero. Today it
holds the data while the app pod holds the knowledge of what the data *means*. That
asymmetry is the whole reason [18](../plans/18-mqtt-state-publication.md) exists: Home
Assistant wants to know what is expiring this week, only the sleeping pod can compute it,
so it polls every thirty seconds and the pod never sleeps. The roadmap already states the
circularity — "18 wants 10 to be real, and 10 wants 18 to exist."

Put the shape of that answer in a view and the always-awake component can answer without
the sleeping one. This is an argument about **who is awake**, not about how fast a query
runs, and it is the only argument here strong enough to carry the rest.

## Why this depends on 0008

Every `LATERAL`, every recursive CTE with PostgreSQL's semantics, every generated column,
every JSONB path, every RLS policy and every extension would need either a SQLite
counterpart or a PHP fallback — and then a differential test proving the two agree. The
dual-engine tax is not spread evenly across the codebase; it is concentrated precisely in
the layer this record wants to grow. That is why the two proposals were written as one
concept, and why they are nonetheless two records: 0008 is defensible alone, and this one
is not.

## Decision (proposed)

Reports and derived read state move into views. Write logic moves into functions only
where atomicity is the argument. Extensions are used minimally and chosen last.

Explicitly **not** proposed: a rewrite of `StockService`, an argument that PHP is the
wrong language, or a claim that the current architecture is failing. It is not failing.
This is about where the next several years of *added* logic should live.

## Where it helps, honestly ranked

1. **It lets the pod sleep.** The one that matters. A scheduled recompute plus a bridge
   means [18](../plans/18-mqtt-state-publication.md) does not need PHP awake, and
   [02](../plans/02-mcp-endpoint.md)'s read tools become "select from a view" rather than
   "wake the application."
2. **It gives [19](../plans/19-rbac.md) *a* place to express a field-level projection —
   not an inherited policy.** An earlier draft of this record claimed price redaction
   expressed once in a view or in RLS would be inherited by API, MCP and MQTT alike. That
   is wrong three times over and the correction matters, because it was the second-strongest
   argument here and is now a much weaker one:

   - **RLS selects rows, not columns.** It cannot make `price` invisible while leaving the
     row visible. PostgreSQL's column-level `GRANT` is the column-shaped primitive, and it
     works per role and raises an error rather than eliding a field.
   - **A view has a fixed column set.** The best it can do is
     `CASE WHEN … THEN price ELSE NULL END`, which produces a `NULL` indistinguishable
     from a legitimately absent price. For an API whose responses are rows serialised
     as-is, "redacted" and "not recorded" collapsing into the same value is a contract
     defect, not an implementation detail.
   - **MQTT has no reader identity at all.** A retained topic is read by whoever subscribes.
     No database-side mechanism can redact per reader here, which is exactly why
     [18](../plans/18-mqtt-state-publication.md)'s Q8 leans to publishing no price field on
     any topic — a policy decision, reached independently, that this record cannot improve on.

   What survives: **one place to define the projection**, so the same shaped answer is not
   re-derived per channel. That is worth something and is not the same claim. The mechanism
   is unchosen — see open question 4, now a prerequisite rather than a detail.
3. **Some write paths gain encapsulation and clearer concurrency behaviour — not
   termination safety.** A second correction, and this one is checkable: the draft claimed
   a consume expressed as one statement survives a pod terminated mid-request where a PHP
   loop does not. It does not, because there is no such loop to survive.
   `StockService::ConsumeProduct` already runs its mutation inside
   `DatabaseService::InTransaction`, which rolls back on any throw — and a connection lost
   before commit is rolled back by PostgreSQL regardless of what the client was doing.
   [13](../plans/landed/13-write-path-transactions.md) bought this outright, not "most of it."
   A stored function may still be worth it for encapsulation, for holding invariants the
   application cannot express, or for reducing a multi-statement critical section — but
   crash safety is not among the reasons, and this record should not have claimed it.
4. **It shrinks what the HTTP layer has to be.** If the read surface is views, the thing
   serving MCP or MQTT need not be PHP-FPM. A strategic option rather than a justification
   — but the kind of option that closes quietly if the logic stays in `StockService`.

## Where it does not help, said plainly

- **Cold start.** See the context section. PHP bootstrap dominates.
- **The HTML pages.** 71 rendered templates and 173 direct `$this->DB->` call sites
  (measured in [14](../plans/landed/14-contract-and-regression-scaffolding.md) §2b) still need
  Blade, the baked view cache and a warm pod. Views do not make a page render.
- **The HTMLPurifier serializer path**, which is plan 10's most annoying writable-path
  problem and is untouched by any of this.
- **Performance.** At household scale nobody is waiting on the database. Do not sell this
  as a speed-up, and specifically **do not reach for materialized views** — a few thousand
  stock rows is a plain view's territory, and a matview is refresh scheduling plus
  staleness bugs bought against no measured problem.

## Consequences

**Behaviour moves into the deploy path.** Today a bad release is an image rollback. With
logic in views, a bad release is a *schema state*: roll forward only, and two image
versions cannot share a database during any overlap. Plan 10-Q6 already wants fail-fast on
a database *ahead* of the code; under this record that case goes from theoretical to
routine.

Views sharpen this further: `CREATE OR REPLACE VIEW` may only **append** columns, never
rename, reorder, retype or drop one. Any real change to a view is a drop-and-recreate
cascading through five dependency layers, which is exactly the shape of change a growing
report layer produces.

**Failures move further from their causes.** This fork already has the canonical example
written down, in [ADR-0003](0003-seed-data-in-php.md): an empty seed table made
`quantity_unit_conversions_resolved` return nothing, so `products_ins` copied nothing, and
it surfaced as `recipes_pos` rejecting an ingredient. "The trigger was faithful; it had
nothing to copy." More logic in views means more of that.

**The general case against logic in stored procedures is four objections; two are
answerable here, one is not, and one is already moot.**

| Objection | Standing here |
|---|---|
| Poorly served by version control | **Moot** — the DDL is already in `db/pgsql/baseline/` and reviewed like code |
| Hard to unit test | **Answerable** — pgTAP asserts on functions, views, constraints and triggers, and runs in CI |
| Hard to trace | **Partly answerable** — but nothing gives PL/pgSQL a stack trace into [11](../plans/11-api-error-handling.md)'s error logging |
| Splits the domain across two places | **Not answerable.** After this, "what happens when stock is consumed" means reading two languages in two conventions. No tooling fixes it |

The split is the cost. It should be paid deliberately or not at all.

**Extensions pin the hosting.** `pg_cron` cannot be enabled by `CREATE EXTENSION` alone; it
must be in `shared_preload_libraries` at server start, which means a Postgres this project
configures — self-hosted, or CloudNativePG with `postInitSQL`. That is fine for k3s and it
forecloses something worth naming: **a serverless Postgres that itself scales to zero is
incompatible with this design.**

Neon documents it directly: pg_cron jobs run only while the compute is awake, so it is
recommended only where scale-to-zero is disabled. If the database ever becomes the thing
that sleeps, the scheduler moves back out to a k3s CronJob and the "always-awake component
answers" premise inverts. **Decide which component is allowed to sleep before building on
the assumption** — open question 3.

**`LISTEN`/`NOTIFY` is best-effort, not a queue.** Notifications are dropped if no listener
is connected, and payloads cap at 8000 bytes with no way to raise it. For an MQTT bridge
that is usually fine — retained topics mean a missed notification is corrected by the next
publish — but it must be *designed* as best-effort. The durable pattern, if
[18](../plans/18-mqtt-state-publication.md) ever needs one, is a table drained with
`SELECT … FOR UPDATE SKIP LOCKED`, with `NOTIFY` used only to wake the drainer.

## Findings that stand on their own

Two things surfaced while researching this that hold **whether or not this record is
accepted**, and should be lifted into their owning plans rather than discarded with it if
it is rejected. They are constraints, not decisions, which is why they are noted here
rather than given ADRs of their own.

**F1 — [Plan 10](../plans/landed/10-cold-start-statelessness.md)'s migration lock is a trap under
transaction-mode pooling.** 10 specifies `pg_advisory_lock`, which is session-scoped, and
correctly notes it is held on the connection. If pgbouncer in transaction mode is ever
introduced — the obvious thing to reach for when many short-lived pods each open
connections — the unlock can land on a different backend than the lock, leaking it
permanently. `pg_advisory_xact_lock` is the safe form where the scope fits inside a
transaction; a `pool_mode = session` entry is the alternative where it does not. Plan 10
should say which it relies on.

**F2 — the same pooling mode breaks `LISTEN`.** `NOTIFY` survives transaction pooling
because it is a single statement; `LISTEN` does not, because it needs a stable session.
Any bridge built for 18 connects directly or through a session-mode pool entry.

## A staged shape, if accepted

Ordered so each stage is useful alone and the irreversible part comes last.

1. **Measure the premise.** Deploy 18 as designed and observe whether the pod sleeps. If it
   does not, stop and fix that first.
2. **Accept [ADR-0008](0008-postgresql-only-runtime-engine.md).** Independently worthwhile;
   does not commit to this record.
3. **Reads into views.** Additive, reversible, and where the payoff is. Start with 18's
   payload and 02's shapes.
4. **19's projection into those views, by a mechanism chosen first.** Not "put the rule in
   it" — question 4 has to be answered before this stage has a shape.
5. **Writes into functions — selectively, or never.** No scale-to-zero argument exists for
   moving the rest, and no crash-safety one either.
6. **Extensions — minimally**, and see open question 6.

## Acceptance prerequisites

Gates, not suggestions. The accepting pull request says how each was met.

- **[ADR-0008](0008-postgresql-only-runtime-engine.md) is accepted.** This record is not
  viable otherwise.
- **The premise is measured** — question 1. Deploy [18](../plans/18-mqtt-state-publication.md)
  and observe whether the pod actually sleeps. If it does not, this record's justification
  collapses to 0008's tax argument and it should be rejected rather than accepted smaller.
- **The redaction mechanism is chosen** — question 4 — with a written answer to how
  identity reaches it and how a redacted field is distinguished from an absent one.
  Without this, stage 4 has no design and [19](../plans/19-rbac.md) inherits an
  unsubstantiated promise.

  **Before this record may claim a database-enforced policy shared across channels, the
  Anonymizer spike has to pass**, and it is seven things rather than one:

  1. A **deterministic** mapping from a Victual user's combined application roles to
     exactly one database masking policy, written down as a rule rather than left to the
     extension's "first in the list wins".
  2. Transaction- and pool-safe role selection, with no identity leaking between requests.
  3. A user receiving masked reads still performing permitted writes — or a deliberate
     read/write connection split, given masked roles are documented read-only.
  4. Explicit masking coverage for every base table *and* derived view carrying price or
     cost data.
  5. Admin-versus-Child API JSON compared, including a legitimately null price against a
     masked one, and whatever [19](../plans/19-rbac.md) decides the absent-key contract is.
  6. Bypass resistance across filtering, ordering, direct table access, views, functions,
     ownership and `SECURITY DEFINER` — and a pinned version ≥ 1.3, with a stated answer
     for who may create security labels.
  7. An explicit unprivileged publishing identity for MQTT.

  A spike that fails on 3 or 5 does not sink this record; it sinks the Anonymizer
  candidate and leaves the lean where it is.
- **The LessQL spike is done** — question 5. Whether a view layer is addressable through
  the existing read layer changes what stage 3 costs and what
  [14](../plans/landed/14-contract-and-regression-scaffolding.md) is testing.

## Open questions

1. **Does the pod actually sleep once 18 exists?** Everything rests on it. Home Assistant
   is the *known* poller, not necessarily the only one — liveness and readiness probes, a
   metrics scrape and a log shipper all count, and health checks defeating scale-to-zero is
   a documented general failure. *Lean: measure this before taking any of the rest
   seriously. It is cheap.* If something else holds the pod awake, this record's
   justification collapses to 0008's tax argument, which is a decent argument and a much
   smaller one.
2. **Is the write path in scope at all?** *Lean: no, beyond two or three operations where
   atomicity is the argument. Reframing this as "reports and reads into the database"
   rather than "logic into the database" would make it a much easier decision and lose very
   little.*
3. **Which component is allowed to sleep — the pod, or the database?** Mutually exclusive
   under a `pg_cron` design. *Lean: the pod. The database is small and cheap to keep awake
   at this scale, and being the always-awake component is what makes it useful here.* This
   is the question that forecloses managed/serverless Postgres and should be answered out
   loud rather than by accident.
4. **By what mechanism is a field redacted, and how does identity reach it?** An
   acceptance prerequisite, and the question benefit 2 above was wrong about. Three
   candidates, none yet chosen:

   - **Identity-scoped functions returning JSON.** `victual_stock_overview(user_id)`
     returns a JSON document whose keys vary by caller, so a redacted field is genuinely
     *absent* rather than `NULL`. Fits MCP naturally; fits an endpoint whose response is
     a serialised row much less naturally, and it is a larger departure from how this
     application reads data than anything else in this record.
   - **Separate public and private projections.** `stock_overview_public` and
     `stock_overview_priced` as distinct views, with the *choice between them* made at the
     application boundary. Keeps views simple and honest — each has one fixed column set
     that means what it says — at the cost of the routing living in PHP, which is to say
     the policy is only half in the database.
   - **Continued application-boundary redaction**, with views supplying shape only. The
     null option, and the one that keeps [19](../plans/19-rbac.md) entirely in code where
     its tests already are.
   - **[PostgreSQL Anonymizer](https://postgresql-anonymizer.readthedocs.io/en/stable/)
     dynamic masking.** The only candidate here that is a real database-enforced column
     policy rather than a hand-rolled one: masking rules are declared as security labels,
     a role is declared `MASKED`, and sessions under it see masked output while unmasked
     roles see cleartext, with no application changes. It makes the database-layer idea
     substantially more credible than "views or RLS" did. It does **not** make the policy
     automatically shared across channels, and the gaps are specific enough to be worth
     listing — they are the spike below:

     | Gap | Why it bites here |
     |---|---|
     | Victual roles are application rows; Anonymizer policies attach to PostgreSQL roles | Needs a mapping, and a safe per-request `SET ROLE` or separate pools — which is F1/F2 territory again |
     | **Masked roles are documented as read-only** — a masked role should not insert, update or delete | Directly collides with [19](../plans/19-rbac.md): a Child who logs a chore execution needs masked reads *and* permitted writes. Either a deliberate read/write connection split, or this candidate does not fit |
     | Masking substitutes a value; it cannot make a key absent | Same `NULL`-doing-two-jobs defect as the plain view. If masking can only return `NULL` or a placeholder, 19 either revises its wire contract or keeps a response-shaping step |
     | Masking views are deliberately constructed interfaces, not an automatic extension over derived views | Every price-bearing view of ours — `products_average_price`, `uihelper_stock_current_overview`, `uihelper_product_details`, `recipes_resolved` — needs explicit coverage, and [ADR-0005](0005-wire-contract-is-the-invariant.md) already lists that exact set |
     | MQTT has no reader identity | Needs an explicitly chosen unprivileged publishing role, never the interactive caller's |
     | **A role gets exactly one masking policy.** Several may be *defined*, but where a role is masked in several, only the first in the list applies — they do not compose | A Victual user holding several application roles must resolve to **one** database masking policy, deterministically and by a rule written down, or the extension picks one silently. Under [ADR-0006](0006-authenticated-issues-in-scope.md) a silent pick would be a permission bug: the failure is a user seeing *more* than intended, with nothing in the output to say so |

     **One thing the review did not have, and it matters under
     [ADR-0006](0006-authenticated-issues-in-scope.md):** Anonymizer 1.1.0 and 1.2.0 carried
     a privilege-escalation vulnerability: a non-superuser able to create security labels
     could inject SQL through masking expressions and escape the trusted-schema restriction.
     Three paths did it: nested functions, `MASKED WITH VALUE` (validated less strictly than
     `masking_function`), and altering a table into a view with a malicious rule. Patched in
     1.3.

     The lasting lesson is not the CVE but its shape: **who may declare a masking rule is
     itself a privilege boundary**, and adopting this makes security-label creation part of
     this fork's permission surface. Any adoption pins ≥ 1.3 and says who may label.

   *Lean: unchanged for now — separate public/private projections, on the grounds that it is
   the only candidate that never makes a `NULL` do two jobs, and the only one with no
   read-only problem.* Anonymizer is the strongest database-enforced option and should be
   spiked before that lean is treated as settled; the read-only constraint is the item most
   likely to decide it.

   Whichever is chosen, if identity is carried in session state then `SET LOCAL` inside the
   request's transaction is the safe form. Plain `SET` on a pooled connection is the
   standard way tenants leak into each other, and an application connecting as the table
   owner silently bypasses unforced RLS.

   This interacts with F1/F2 and with LessQL's connection handling, and it is the single
   most likely place for this record to produce a security bug rather than a cleanup.
   [ADR-0006](0006-authenticated-issues-in-scope.md) raises the stakes: a redaction bug
   here is a finding under this fork's threat model, not a cosmetic issue.
5. **Views through LessQL, or raw SQL?** The read layer is `morris/lessql` (berrnd's fork),
   and hazard 17 is already about its identifier quoting. A view layer LessQL cannot
   address naturally is a view layer read by hand-written SQL, which changes what
   [14](../plans/landed/14-contract-and-regression-scaffolding.md)'s contract tests are testing.
   *Lean: unresolved. Deserves a spike before stage 3 rather than a guess here.*
6. **Does the MQTT bridge live in the database, beside it, or in the app?** `pg_cron` +
   `pg_notify` + a listener is one shape; a k3s CronJob that selects and publishes is
   another and needs no extension at all. *Lean: the CronJob shape first. No
   `shared_preload_libraries`, keeps question 3 open rather than answering it by accident,
   and can be replaced later without changing what is published.* This lean deliberately
   weakens the extensions half of this record — the views are what matter; the scheduler is
   an implementation detail to choose last.

## The strongest arguments against, in order

1. **The split-brain cost is unfixable by tooling.** Two languages, two mental models, one
   behaviour. The strongest one, and it moved up because the two arguments that used to
   outrank it were partly wrong — see 2 and 3.
2. **The prize is smaller than it sounds if the pod does not sleep for other reasons** —
   open question 1, now an acceptance prerequisite.
3. **Two of this record's four stated benefits shrank under review**, which is itself an
   argument for caution: the redaction benefit does not work the way the first draft
   claimed, and the crash-safety benefit does not exist at all. A proposal whose case
   erodes on first contact with the code deserves more skepticism than one that survives
   intact, and the remaining case rests almost entirely on benefit 1.
4. **Rollback stops being an image swap.** Mild on a single-replica household deployment;
   still a real property given up.
5. **It arrives with a contract-enforcement gap** — [ADR-0008](0008-postgresql-only-runtime-engine.md)
   hands the wire contract to [14](../plans/landed/14-contract-and-regression-scaffolding.md)
   piece 2, which is outstanding, and this record starts changing what the views return.
   Doing both before 14 lands would be changing the answers and the check at once.
6. **It is a lot of work for one household.** The tax it removes is paid in small
   increments, and small increments feel worse in aggregate than they are per change.

## Research

- **`CREATE OR REPLACE VIEW` may only append columns** — cannot rename, reorder, retype or
  drop. [PostgreSQL: CREATE VIEW](https://www.postgresql.org/docs/10/sql-createview.html);
  reasoning in [a -hackers thread on renaming view columns](https://www.postgresql.org/message-id/16099.1572530064%40sss.pgh.pa.us).
- **`pg_cron` requires `shared_preload_libraries`**; `CREATE EXTENSION` alone fails.
  [citusdata/pg_cron](https://github.com/citusdata/pg_cron),
  [CloudNativePG PostgreSQL configuration](https://cloudnative-pg.io/documentation/1.16/postgresql_conf/),
  [CloudNativePG discussion #1173](https://github.com/cloudnative-pg/cloudnative-pg/discussions/1173).
- **`pg_cron` is incompatible with a database that scales to zero.**
  [Neon: the pg_cron extension](https://neon.com/docs/extensions/pg_cron),
  [Neon: scale to zero](https://neon.com/docs/introduction/scale-to-zero).
- **Session advisory locks are unsafe under transaction pooling (F1); `LISTEN` does not
  work in transaction mode while `NOTIFY` does (F2).**
  [PgBouncer is useful, important, and fraught with peril](https://jpcamara.com/2023/04/12/pgbouncer-is-useful.html),
  [pgbouncer issue #976](https://github.com/pgbouncer/pgbouncer/issues/976),
  [PgBouncer modes](https://podostack.com/p/postgres-connection-pooling-pgbouncer-modes).
- **`LISTEN`/`NOTIFY` is non-durable with an 8000-byte payload cap**; the durable pattern is
  a table drained with `SKIP LOCKED`.
  [PostgreSQL: NOTIFY](https://www.postgresql.org/docs/current/sql-notify.html),
  [Beyond LISTEN/NOTIFY](https://www.stacksync.com/blog/beyond-listen-notify-postgres-request-reply-real-time-sync).
- **PostgreSQL Anonymizer** — dynamic masking by security label on `MASKED` roles; masking
  views as deliberately built interfaces rather than automatic coverage; masked roles
  documented as not permitted to insert, update or delete.
  [Dynamic masking](https://postgresql-anonymizer.readthedocs.io/en/latest/dynamic_masking/),
  [Masking views](https://postgresql-anonymizer.readthedocs.io/en/stable/masking_views/),
  [Declare masking rules](https://postgresql-anonymizer.readthedocs.io/en/stable/declare_masking_rules/),
  [Postgres dynamic data masking](https://www.bytebase.com/blog/postgres-dynamic-data-masking/).
  The 1.1.0/1.2.0 privilege escalation via masking-expression SQL injection and
  trusted-schema bypass, patched in 1.3:
  [GHSA-468r-mhwc-vxjc](https://github.com/google/security-research/security/advisories/GHSA-468r-mhwc-vxjc).
- **RLS controls rows; columns are a separate mechanism.** Grants decide whether a role may
  run the operation on the table at all, policies decide which rows it applies to, and
  column-level `GRANT` is the column-shaped primitive — which requires revoking table-wide
  `SELECT` first, and denies rather than elides.
  [Column-level security](https://supabase.com/docs/guides/database/postgres/column-level-security),
  [How to implement column and row level security in PostgreSQL](https://www.enterprisedb.com/postgres-tutorials/how-implement-column-and-row-level-security-postgresql),
  [PostgreSQL: Row security policies](https://www.postgresql.org/docs/current/ddl-rowsecurity.html),
  [View permissions and row-level security](https://www.cybertec-postgresql.com/en/view-permissions-and-row-level-security-in-postgresql/).
- **RLS pooling footguns** — `SET` versus `SET LOCAL`, owner roles bypassing unforced RLS,
  the indexing cost.
  [Postgres RLS in practice](https://queryplane.com/blog/postgres-row-level-security-in-practice/),
  [RLS for multi-tenancy: the pattern and the footguns](https://patotski.com/blog/postgres-row-level-security-multi-tenant/).
- **Scale-to-zero cold start is 2–5s and more with heavy init**; health probes are a common
  reason pods never sleep at all.
  [Running HTTP services on Kubernetes with KEDA scale-to-zero](https://medium.com/@swenushika/running-http-services-on-kubernetes-with-keda-scale-to-zero-lessons-from-production-e35f0df11fc8),
  [Your health checks are quietly killing scale-to-zero](https://bex.co/blog/2026/08/23/health-checks-defeating-scale-to-zero-paas).
- **pgTAP** covers functions, views, constraints and triggers and runs in CI.
  [pgTAP](https://pgtap.org/), [theory/pgtap](https://github.com/theory/pgtap).
- **The general case against logic in stored procedures.**
  [Drawbacks of stored procedures](https://dusted.codes/drawbacks-of-stored-procedures),
  [Stop writing business logic in stored procedures](https://nkdagility.com/resources/blog/stop-writing-business-logic-in-stored-procedures/).
