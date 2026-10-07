# 36. Database credential rotation

**Goal:** An operator rotates the `victual_migrate` and `victual_app` passwords with one
command. The command generates the new passwords, stores them where the cluster reads
them, applies them to PostgreSQL with a superuser credential typed at a prompt, and
restarts the pods. The cost to running requests is known and measured beforehand.
**Depends on:** [ADR-0038](../adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md)
decision 10 (Proposed), which
keeps `roles.sql` a manual step outside the chart and names this handler as separate work.
[ADR-0010](../adr/0010-workload-standard.md) property 3 defines the two roles.
**Status:** draft, research only. No script exists. Open questions 1 and 2 decide the design.

## Problem and outcome

`deploy/postgres/roles.sql` is the only place either role's password is set, and it needs
a PostgreSQL superuser. ADR-0038 decision 10 keeps that credential out of the Helm chart,
so rotation stays a manual step. Today that step is a hand-typed `psql` command, an edit of
two 1Password items, and a restart, in an order nothing records.

The order matters. php-fpm opens a database connection for each request; it holds no
persistent connection. Pods read the Secrets through `envFrom`, which Kubernetes resolves
once, at container start. A password changed in PostgreSQL before the pods restart with the
new Secret refuses requests in between. A pod restarted before PostgreSQL has the new
password cannot start.

After this plan is implemented:

- one handler rotates either role or both, from a workstation with `op`, `psql` and
  `kubectl`;
- the superuser password and the new role passwords never appear in a process's argument
  list, in shell history, or in a file, and PostgreSQL's logs hold neither the passwords
  nor their verifiers;
- the transport policy for every database connection is explicit and never falls back to
  an unencrypted connection;
- two rotations cannot interleave, and an interrupted one resumes instead of reading its
  own intermediate state as drift;
- the operator chooses the availability trade-off knowingly: the plan names the gap each
  option leaves and how it was measured on `deploy/kind/`;
- a failed step leaves the store, the database and the pods in a state the handler reports
  and can repair.

## Current behaviour

Read from the tree on 2026-10-07.

### Who holds which credential

| Credential | Holder | Read when |
|---|---|---|
| `victual_migrate` | The `migrate` initContainer of the `victual` pod (`deploy/k3s/victual.yaml`, Secret `victual-db-migrate`) | Pod start only. No running container holds it. |
| `victual_app` | The `app` (php-fpm) container of the same pod (Secret `victual-db-app`) | Container start; used for a new connection on every request. |
| PostgreSQL superuser | Whoever runs `roles.sql`; in kind and talos also the roles Job (Secret `victual-postgres-superuser`) | Only while `roles.sql` runs. |

The MCP sidecar (`deploy/k3s/victual-mcp.yaml`) and the label workers
(`deploy/k3s/label-workers.yaml`) hold no database Secret: the sidecar reads
`victual-mcp-config` only, and the workers mount `victual-label-credentials`. Rotation
therefore restarts one Deployment, `victual`.

Each Secret carries `VICTUAL_DB_USER` beside `VICTUAL_DB_PASSWORD`, so the role a container
uses is also a Secret value. The migrate item holds `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD`. The
app item may hold `VICTUAL_MQTT_PASSWORD` and `VICTUAL_INFLUXDB_TOKEN`
(`deploy/production/values.example.yaml`). A rotation must leave those fields unchanged.

### How passwords reach PostgreSQL

`roles.sql` refuses to run unless `db`, `migrate_password` and `app_password` are all set,
and it sets both passwords on every run (`ALTER ROLE … PASSWORD :'…'`). Rotating one role
therefore requires passing the other's current password too.

On master as of 2026-10-07, the documented invocation (the script's header and
`values.example.yaml`) passes both passwords as `psql -v name=value`.
`deploy/kind/roles-job.yaml` does the same through `$(MIGRATE_PASSWORD)` substitution in the
container's `command`. Both put the passwords in `psql`'s argument list, visible in `ps` to
other users of the machine or node.

Branch `claude/roles-sql-env-passwords` replaces that interface, as open question 8's
answer requires. `roles.sql` reads `MIGRATE_PASSWORD` and `APP_PASSWORD` from psql's
environment with `\getenv`, discards any `-v` value, and refuses to run without them or on
a psql older than 15. On that branch a refusal also exits non-zero; on master, `\quit 1`
exits 0. Every call site in the tree moves to the environment form there.

PostgreSQL stores one password per role. `VALID UNTIL` sets an expiry, not a second accepted
value, so one role cannot accept an old and a new password at the same time.

### How passwords reach the pods

| Deployment | Store | Path to the Secret |
|---|---|---|
| `deploy/talos/` | 1Password, vault `DevSecOps` | `OnePasswordItem` → Connect server → 1Password operator, which polls every `POLLING_INTERVAL` seconds (default 600) and writes a Secret with one key per field. |
| `deploy/production/` | 1Password (`secrets.source: onepassword`) or values file (`inline`) | The same operator path, or `render.py` writes the Secret. |
| `deploy/kind/` | `deploy/kind/.secrets/*.env` (gitignored) | kustomize `secretGenerator` on `kubectl apply -k`. |

The operator can restart Deployments when a Secret it manages changes. The
`operator.1password.io/auto-restart` annotation enables this on the `OnePasswordItem`, the
Deployment or the namespace, and `AUTO_RESTART` sets it for the whole operator, in that
order of precedence (operator usage guide, read 2026-10-07). The guide does not say how the
operator finds the Deployments that use a Secret; that is open question 6.

`deploy/talos/onepassword-items.yaml` sets no auto-restart. Its comment says rotation is
"ALTER ROLE first and the item second". That order keeps an operator-triggered restart from
starting a pod against a password PostgreSQL does not yet accept. It also makes every
request fail between the `ALTER ROLE` and the eventual restart, which waits for the next
poll. The [ordering section](#ordering) replaces that guidance. The maintainer's direction
(2026-10-07): the comment changes when the handler is implemented, to the order it uses.

### How the pod restarts

`victual` runs `replicas: 1` with the default `RollingUpdate` strategy. Kubernetes rounds
the default 25 % `maxSurge` up to one pod and the 25 % `maxUnavailable` down to zero. A
restart therefore starts a new pod while the old one still serves, and removes the old pod
only when the new one is Ready. The new pod's readiness probe fetches `/login` through
nginx, PHP and the database every 5 s.

During that overlap the old pod's php-fpm needs the old `victual_app` password and the new
pod's containers need the new ones. With one role per job, no single password state serves
both pods.

## Scope

Included:

- rotating `victual_migrate`, `victual_app`, or both, against an external or in-cluster
  PostgreSQL;
- 1Password through `op`, writing the items `deploy/talos/seed-1password.sh` creates, with
  their field labels unchanged;
- HashiCorp Vault KV version 2 as an optional second store (see open question 4 for its
  cluster-side consumer);
- running `roles.sql` without exposing secrets, as described in [Secret handling](#secret-handling);
- the Kubernetes Secret propagation wait and the pod restart;
- the kind harness as the place the gap is measured.

Excluded:

- rotating the PostgreSQL superuser, `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD`, the MQTT password,
  the InfluxDB token and the label credentials;
- any Helm hook or chart value: ADR-0038 decision 10 keeps the superuser credential out of
  the chart;
- scheduled or unattended rotation. The handler is interactive by design, because it asks
  for the superuser credential;
- Vault's database secrets engine, which rotates passwords itself (open question 5).

## Design

### Handler outline

The handler is one script under `deploy/postgres/`. It sits beside `roles.sql`, which it
drives, because rotation is a database-administration task for every deployment. The
outline below is required behaviour; the names are illustrative.

1. **Prompt.** Ask for the superuser name and password on the terminal (see
   [Secret handling](#secret-handling)). Confirm `op` is signed in to the account and vault
   given, and that `kubectl` names the intended context and namespace.
2. **Lock.** Open the superuser connection that holds the rotation lock for the whole run
   (see [Concurrency](#concurrency)). A second handler stops here.
3. **Classify.** Read the rotation journal (see [Recovery](#recovery)). With an operation
   in flight, reconcile it. With none, check that each store's current password
   authenticates as its role; a mismatch is drift, and the handler stops before changing
   anything.
4. **Generate.** Create 32-character passwords with Python's `secrets` module, as
   `seed-1password.sh` does, and compute each one's SCRAM-SHA-256 verifier (see
   [Server logs](#server-logs)).
5. **Store, apply, propagate and restart,** in the order the chosen
   [ordering option](#ordering) gives, recording each phase in the journal.
6. **Confirm.** The new pod is Ready; a connection with the old app password is refused;
   the store's current version is the new value. Report each result, close the journal
   entry and release the lock.

The handler accepts which roles to rotate, the store (`1password`, `vault`, or a
`kind-files` backend for the harness), and the target namespace. It refuses to run with
`set -x` tracing enabled or without a terminal. Every PostgreSQL connection it opens,
including the lock session, preflight, `roles.sql` and verification, uses the transport
policy in [Transport](#transport).

### Secret handling

**The superuser password.** The handler reads it from `/dev/tty` with echo off. It never
reaches an argument, an exported shell variable, a file or a log. Two credible designs:

- *psql prompts for it.* `psql` reads the password from `/dev/tty` when the server asks.
  The handler then never holds the password, but the operator types it once for every
  connection: the lock session, preflight and the `roles.sql` run.
- *the handler holds it* and passes it as `PGPASSWORD` in the environment of each `psql`
  child only. The operator types it once. The residual exposure is
  `/proc/<pid>/environ` of the `psql` process, readable by the same user and by root for
  the life of that process.

The recommendation is the second: the operator types the password once, and the exposure
is limited to the operator's own user and root. `PGPASSFILE` is not used, because it needs
a file.

**The role passwords.** The store receives the plaintext, because the pods log in with it.
PostgreSQL receives only its SCRAM verifier (see [Server logs](#server-logs)). The handler
passes the verifiers the way `roles.sql` reads its inputs, in the `psql` child's
environment beside `PGPASSWORD`:

```sh
# Illustrative. The values are verifiers, in psql's environment; no argument holds a secret.
PGPASSWORD="$su" PGSSLMODE="$sslmode" MIGRATE_PASSWORD="$m_verifier" APP_PASSWORD="$a_verifier" \
  psql -v ON_ERROR_STOP=1 -v db=victual -f deploy/postgres/roles.sql \
  "postgresql://$suname@$host/$db"
```

The variables are set for that one command, not exported into the handler's shell. Feeding
`\set` lines to `psql` on standard input does not work: `roles.sql` unsets both variables
before reading the environment, so that a `-v` value cannot survive.

**Rotating one role.** `roles.sql` sets both passwords on every run. For the role not being
rotated, the handler passes that role's current `pg_authid.rolpassword`, read under the
rotation lock. PostgreSQL stores an already-hashed value unchanged, so the role keeps
exactly the password it had. The handler never needs that role's plaintext or its store
value, and cannot write back a stale one. A role with no stored password (`NULL`) stops the
run.

Measured on `postgres:16`, 2026-10-07: a role's `rolpassword` passed back through
`ALTER ROLE … PASSWORD` compared equal afterwards, and the original password still logged
in.

### Server logs

`roles.sql` runs `ALTER ROLE … PASSWORD '<value>'`. psql interpolates the value before
sending, so the server receives it as a literal. A server with `log_statement = 'all'` or
`'ddl'`, `log_min_duration_statement`, or an error logged with its statement keeps that
literal in its log. The `ALTER ROLE` reference warns of this. A scan of the operator's
workstation cannot see it.

Two measures, both required:

- **Verifiers, not plaintext.** The handler computes a SCRAM-SHA-256 verifier
  (`SCRAM-SHA-256$<iterations>:<salt>$<StoredKey>:<ServerKey>`) from each new password,
  with a fresh random salt, and passes the verifier to `roles.sql`. PostgreSQL stores a
  value already in SCRAM format as given, whatever `password_encryption` says. The
  verifier is still sensitive: it allows server impersonation and an offline guessing
  attack. With 32 random alphanumeric characters (about 190 bits) the guessing attack is
  not practical.
- **No statement logging for the session.** Before the `ALTER ROLE`, the session sets
  `log_statement = 'none'`, `log_min_error_statement = 'panic'` and
  `log_min_duration_statement = -1`. All three are superuser-settable, and `roles.sql`
  already needs a superuser. The settings belong in `roles.sql` itself, so that the kind
  and talos roles Jobs and manual runs, which still send plaintext, are covered as well
  (open question 11).

Audit extensions log independently of these settings. Preflight reads
`shared_preload_libraries`. With `pgaudit` loaded, the session also sets `pgaudit.log =
'none'`. With any other library loaded that could log statements, the handler stops and
names it; the operator decides.

Measured on `postgres:16` with `log_statement = all`, 2026-10-07:

- a client-computed verifier passed through `ALTER ROLE` stored as given, and the
  plaintext it was computed from logged in;
- with logging left on, the log held the verifier and no plaintext;
- with the three settings above, the log held neither, including for an `ALTER ROLE` that
  failed.

### Transport

libpq's default `sslmode` is `prefer`: it falls back to an unencrypted connection when the
server offers no TLS. With verifiers, what crosses the network is a verifier, not
plaintext, but it still needs protecting. The handler therefore sets `PGSSLMODE`
explicitly on every connection, never inheriting it, and uses the same value for the lock
session, preflight, `roles.sql` and verification.

**Direct connection to an external server** (outside the cluster, typically on the same
LAN): `require` at least, which refuses a server without TLS before any statement is sent.
`require` encrypts but does not authenticate the server. Whether to verify the server's
certificate (`verify-ca` or `verify-full`) depends on the network the deployment trusts,
not on the server being outside Kubernetes; open question 9.

**Through `kubectl port-forward`** (kind and talos, whose `postgres:16` has no TLS): libpq
connects to `localhost`, and the connection reaches the pod inside kubectl's TLS stream to
the API server and the kubelet. The handler sets `disable` for this path and says so,
because `require` would fail against a server that has no TLS. The PostgreSQL hop itself
is inside the pod's network namespace.

Every path refuses rather than falls back: a value of `allow` or `prefer` is never used.

### Concurrency

Two handlers run at the same time, from one workstation or two, could each read state,
rotate a different role, and overwrite each other's work. A rotation therefore holds one
lock for the whole role pair, from before its first read to the end of the rollout.

The lock is a session-level PostgreSQL advisory lock, `pg_try_advisory_lock(<constant>)`,
taken on a dedicated superuser connection that stays open for the run. Every handler must
reach that database, so the lock works across workstations. PostgreSQL releases it when
the connection closes, so a killed handler cannot leave it held. A handler that fails to
take the lock stops with a message; it does not wait.

`roles.sql` does not take the lock, because the handler runs it in a second session, and
that session would wait for the lock the handler already holds. Manual `roles.sql` runs
and the roles Jobs are therefore not serialised with the handler; open question 10.

Store writes are compare-and-swap. Under the lock the handler re-reads the item and checks
that its version (the 1Password item `version`, the Vault KV `current_version`) is the one
it read in classification. Vault writes pass `-cas=<version>`. `op` has no compare-and-swap
on edit, so for 1Password the check and the edit are two calls, and the lock is what keeps
another handler out between them. An edit made in the 1Password app at that moment is not
prevented; the handler re-reads after writing and stops if the version moved by more than
one.

### Store writes

**1Password.** The handler reads the item as JSON, changes the one field in Python, and
pipes the whole item to `op item edit <item> --vault … --account …` as a template on
standard input. The `op` reference says piped templates are accepted and that assignment
arguments are "visible to other processes" (1Password CLI reference, read 2026-10-07).
Sending the whole item keeps sibling fields. Whether `op` merges or replaces fields from a
template decides whether sending only the changed field is safe; open question 7. 1Password
keeps item history, so the previous password is recoverable for rollback.

**Vault.** The handler writes with `vault kv patch`, which merges the change into the
existing data instead of replacing it, and reads the value from standard input when given
`key=-` (Vault `kv patch` reference, read 2026-10-07). KV v2 keeps prior versions, which
gives the same rollback as 1Password item history. No overlay deploys a Vault
consumer today (open question 4).

**kind.** The `kind-files` backend rewrites `deploy/kind/.secrets/{migrate,app}.env` with
mode 0600 and runs `kubectl apply -k deploy/kind`. This writes the password to disk, which
the other backends must not do. That is acceptable only because those files already hold
the harness's passwords. The backend exists so the ordering and the gap can be measured
without a 1Password account.

### Ordering

Four events matter for each role: the store write (S), the Secret update in the namespace
(K, which follows S after the operator's poll), the `ALTER ROLE` (A), and the restart until
the new pod is Ready (R).

**`victual_migrate` has no live gap.** No running container holds it. Any order works if S
and A are both complete before the next pod start. A pod restarted between A and K, or
between K and A, fails its initContainer and stays not Ready; the old pod keeps serving
because `maxUnavailable` is zero. The handler can rotate it alone without a restart.

**`victual_app` decides the gap.** For one role, the options are:

| Order | What fails | Gap |
|---|---|---|
| A, then S, K, R (the talos comment's order) | Every request after A, until the new pod is Ready | A to R; with the operator's 600 s poll, up to ten minutes plus a pod start |
| S, K, then A, then R (recommended for option A) | Requests between A and the new pod's readiness | A to R: one pod start, migrate check and readiness probe |
| S, K with auto-restart, then A | Rotating `victual_migrate` too: the surge pod's initContainer fails until A, then waits out crash-loop back-off. Rotating `victual_app` alone: the surge pod starts but stays not Ready until A. The old pod fails from A either way | A to R, plus up to the back-off (minutes) when the migrate role rotates |

Writing the store first is harmless: PostgreSQL still accepts the old password, and running
pods do not reread the Secret. With option A, the handler waits until the Secret holds the
new value. It compares a hash of the Secret's key with a hash of the value it wrote, never
printing either. Then it runs `roles.sql` and immediately runs `kubectl rollout restart`.
Auto-restart stays off under option A, because it restarts pods before the `ALTER ROLE`.

### Options to close the gap

**A. Accept one restart's outage.** Order S, K, A, R. Requests fail from the `ALTER ROLE`
until the new pod is Ready. The old pod's php-fpm returns database errors; its readiness
probe takes up to 30 s (6 × 5 s) to remove it from the Service. No change to roles or
schema. Observable cost: the number of seconds a request loop sees failures, measured by
[verification 4](#verification).

**B. Temporary second login role.** Create `victual_app_rotate` with `LOGIN` and the new
password, and grant it membership in `victual_app` with inheritance. `victual_app`'s own
`NOINHERIT` limits what it inherits, not what its members inherit from it. Every table,
sequence and default privilege granted to `victual_app` then applies to the new role.

The sequence: point the Secret at the temporary role and roll; `ALTER ROLE victual_app` to
the new password while nothing uses it; point the Secret back and roll again; drop the
temporary role. Because `VICTUAL_DB_USER` is in the same Secret, a store write changes user
and password together. No password the running pod uses ever changes, so auto-restart is
safe here.

Observable cost: zero failed requests if the rollout behaves as measured. It takes two
rollouts and two propagation waits, and for minutes a role exists that `roles.sql` and
ADR-0010 do not describe. `CredentialSplitTest` and `test_deploy_pod_parity.py` assert the
role name `victual_app`; the temporary role would need the same no-DDL checks.

**C. Alternating login roles.** Make `victual_app` a `NOLOGIN` group role holding the
grants, with two login members, for example `victual_app_a` and `victual_app_b`. Each
rotation sets the idle member's password, points the Secret at it, rolls once, then
disables the other member's login. The cost: one rollout per rotation and no failed
requests, but a permanent change to the role model. It touches `roles.sql`, `render.py`,
the parity test and the documentation of ADR-0010 property 3. The migrate role can stay as
it is: it has no live gap.

**D. Password read from a mounted file on each connection.** Mount the Secret as a volume
and have the app read the password when it connects. The kubelet refreshes mounted Secrets
on its sync period, so the gap shrinks from a pod restart to the difference between the
file update and the `ALTER ROLE`. Rejected: the race remains, it changes the application's
configuration path and ADR-0010's `envFrom` convention, and the kind files backend cannot
reproduce the kubelet's timing.

**E. A connection pooler** (PgBouncer) between the app and PostgreSQL, so the app's
password and the server's are separate. Rejected for this plan: it adds a component to every
deployment to solve a rotation that happens rarely.

**Recommendation.** Implement option A first, with the S, K, A, R order and the measured gap
reported to the operator before the `ALTER ROLE`. Option B gives zero failed requests
without a permanent role change, but it creates a role outside ADR-0010's model, so it needs
the ADR decision in open question 1.

### Recovery

The handler records each operation in a journal: a ConfigMap,
`victual-credential-rotation`, in the deployment's namespace. It holds no secret: an
operation id, the roles being rotated, the phase reached (`stored`, `propagated`,
`altered`, `restarted`, `done`), and for each role the store version before and after the
write and a SHA-256 fingerprint of the role's `pg_authid.rolpassword` before the change. A
salted verifier's hash reveals nothing usable. A ConfigMap works the same for every store;
a 1Password field would become a key in the Secret and an environment variable in the pods.

After taking the lock, the handler reads the journal before anything else:

- **No open operation:** a fresh run. A store password that does not authenticate is
  drift, and the handler stops.
- **An open operation:** recovery. For each role in it, the handler compares the role's
  current `rolpassword` with the journal's fingerprint, and tests the store's current
  password by SCRAM login, which does not send the password to the server.
  - The store's new password logs in: the `ALTER ROLE` happened. Resume at propagation or
    rollout.
  - The fingerprint matches: the `ALTER ROLE` did not happen. Finish the rotation, or
    restore the store's previous version with a compare-and-swap write; the operator
    chooses.
  - Neither: something outside the handler changed the role. Stop and report.

Recovery never needs the old plaintext. Restoring the store does: Vault reads it with
`-version`, and whether `op` can read a past item version is open question 12 (1Password's
app can restore one by hand). Each role is reconciled separately, because
`roles.sql` sets `victual_migrate` before `victual_app`, and an interruption between the two
leaves them in different phases. The `kind-files` backend keeps each file's previous
version beside it (mode 0600), so that restore is testable on the harness.

### Failure behaviour

| Fails at | State | Handler's response |
|---|---|---|
| Prompt, lock or classification | Nothing changed | Stop. |
| Store write | Store unchanged or written; journal says which | Restore the prior version with a compare-and-swap write; close the journal entry; stop. |
| Secret propagation timeout | Store new, database old, pods old | Report; nothing is broken. A rerun enters recovery and resumes at the wait. |
| `roles.sql` | Each role at old or new; journal at `stored` or `propagated` | Reconcile each role as in [Recovery](#recovery), then finish or restore. |
| Rollout timeout | Database new; old pod refusing app requests | Report the pod's state. Do not revert the database, which would break the new pod. |
| Handler killed at any point | Lock released by PostgreSQL; journal shows the last phase | The next run enters recovery. |

## Dependencies

- ADR-0038 decision 10 (Proposed): the handler is outside the chart and needs a human with
  the superuser credential.
- ADR-0010 property 3: one role per job. Options B and C stretch it.
- `roles.sql`'s environment interface, on branch `claude/roles-sql-env-passwords` (open
  question 8). The handler's [secret handling](#secret-handling) assumes it.
- `deploy/talos/seed-1password.sh` and `values.example.yaml`: item names and field labels
  the handler must keep.
- The 1Password Connect operator's polling and auto-restart behaviour, read from its usage
  guide; open question 6 covers what the guide does not say.
- `psql` on the operator's workstation, able to reach the database. In kind and talos the
  database is in-cluster, so this needs a `kubectl port-forward` (open question 3).

## Open questions

1. **Does a zero-gap rotation justify a role outside ADR-0010's two?** Options B and C keep
   requests flowing but add a login role, temporarily or permanently. If the answer is yes,
   it needs an ADR that amends ADR-0010 property 3 and records which option. If no, option A
   is the design and its measured gap is documented as the cost of rotation.
2. **Is a gap of one pod start acceptable for a household deployment?** Verification 4
   measures it on kind. The answer decides whether option A is the end state or an interim
   one.
3. **Where does `psql` run?** On the operator's workstation through a port-forward (the
   superuser password stays local), or through `kubectl exec` into a pod (it transits the
   API server's exec stream). External PostgreSQL in production needs the first.
4. **Which consumer turns Vault into Secrets?** No overlay deploys the Vault Secrets
   Operator or the External Secrets Operator. Without one, the Vault backend writes a store
   nothing reads. Choosing one is a deployment decision and may belong in ADR-0038's chart
   values.
5. **Vault's database secrets engine** rotates static-role passwords itself. It needs a
   database credential with the right to alter both roles held by Vault. That credential
   cuts against ADR-0038 decision 10's intent of keeping that power with a person, and it
   would need its own decision.
6. **How does the 1Password operator choose the Deployments to restart, and can it be made
   to sync now?** Its usage guide does not say. The operator's source decides whether
   `envFrom` references count, and whether anything shorter than `POLLING_INTERVAL` exists.
   The propagation wait's timeout depends on the answer.
7. **Does `op item edit` with a template merge fields or replace them?** If it replaces
   them, the handler must always send the whole item, and a field added between the read
   and the write is lost. Test against a scratch item before relying on either behaviour.
8. ~~**Should the kind roles Job and the documented `-v` form stop passing passwords as
   arguments?**~~ **Answered: yes.** It is the same exposure this plan rejects for the
   handler.

   > **Response (datagen24, 2026-10-07):** This needs to be fixed; that is bad practice.
   > *Fixed on branch `claude/roles-sql-env-passwords`; reconciled in
   > [How passwords reach PostgreSQL](#how-passwords-reach-postgresql) and
   > [Secret handling](#secret-handling).*
9. **Does the handler verify the server's certificate on a direct connection?** `require`
   encrypts; `verify-ca` or `verify-full` also authenticates the server, and needs its CA
   on the workstation. The answer depends on the network a deployment trusts, so it may be
   a per-deployment setting with `require` as the floor.
10. **Should manual `roles.sql` runs and the roles Jobs be serialised with the handler?**
    The handler's lock does not cover them (see [Concurrency](#concurrency)). Covering them
    means `roles.sql` takes the lock itself and the handler runs it inside its lock
    session, which needs the verifiers in that session's environment when it starts.
11. **Do the logging settings land in `roles.sql` before the handler?** They protect the
    roles Jobs and manual runs, which send plaintext today. That is a change to the file
    [#674](https://github.com/datagen24/victual/pull/674) already changes, and could ship with it.
12. **Can `op` read a previous item version?** Recovery does not need it, but restoring the
    store after an abandoned rotation does. If it cannot, a 1Password restore is a manual
    step in the 1Password app, and the handler says so.

## Verification

Each criterion runs on `deploy/kind/` with the `kind-files` backend unless it names
1Password.

1. **No secret in arguments, history or files.** During a rotation, a sampler running
   `ps -eo args` every 100 ms records no superuser password, role password or verifier. The shell history file
   gains no line containing either value. A `find` of files modified under `$HOME`, `/tmp` and the repository during
   the run lists none holding either value, apart from `deploy/kind/.secrets/*.env` for the
   `kind-files` backend.
2. **The rotation takes effect.** Afterwards `psql` as `victual_app` with the old password
   is refused, with the new one succeeds, and likewise for `victual_migrate`. `pg_roles`
   shows both roles `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION`, as before.
3. **One-role rotation leaves the other role alone.** Rotating only `victual_app` leaves
   `victual_migrate`'s `rolpassword` byte for byte as it was, and its prior password
   authenticating. In 1Password, a scratch copy of
   the migrate item keeps `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` byte for byte.
4. **The gap is measured.** A request loop through `kubectl port-forward svc/victual` hits
   an API GET every 200 ms with an API key and records each non-200 with a timestamp. Under
   option A the loop's failure window starts at the `ALTER ROLE` and ends when the new pod is
   Ready. The handler prints its length, and it is recorded in this plan's Executed section
   from three runs. Under option B or C, if implemented, the loop records zero failures
   across the whole rotation.
5. **The talos-comment order is worse, as claimed.** The same loop, with the steps run by
   hand in A, S, K, R order and a Secret update delayed to simulate a poll, shows a failure
   window at least as long as that delay.
6. **Failure injection.** A wrong superuser password stops the handler with the store
   unchanged. A rollout that never becomes Ready (a
   bad image tag) is reported as such, with the database left on the new password.
7. **Drift is caught.** With no open journal entry and a store value that does not match the
   database, the handler stops before writing anything.
8. **Idempotence.** Two consecutive rotations succeed, and the second leaves the database,
   store and pods consistent.
9. **Nothing reaches the server log.** kind's PostgreSQL runs with `log_statement = all`
   and `log_min_error_statement = error`. A rotation with synthetic canary passwords leaves
   neither a canary nor its verifier in `kubectl logs` of the PostgreSQL pod. The same holds
   for a run whose `ALTER ROLE` is forced to fail, and for a run killed during `roles.sql`.
   A server with an unrecognised logging library in `shared_preload_libraries` stops the
   handler before it connects with any credential.
10. **Transport refuses fallback.** With `require`, a connection to the harness's
    PostgreSQL, which has no TLS, fails with libpq's "server does not support SSL" before
    any statement runs, for the lock session, preflight and `roles.sql` alike. The
    port-forward path runs with `disable` set explicitly, and the handler reports which
    policy it used.
11. **Concurrent handlers cannot undo each other.** Two handlers, one rotating
    `victual_migrate` and one `victual_app`, started together: the second fails to take
    the lock and changes nothing. With the lock disabled in a test build, the interleaving
    in which an app-only run follows a migrate-only run still leaves `victual_migrate` on
    the newer password, because the app-only run passes back the current `rolpassword`.
    A store edit whose version moved during the run stops the handler without writing.
12. **An interrupted rotation resumes.** Killing the handler after the store write, and
    again after `roles.sql`'s first `ALTER ROLE` (migrate changed, app not), then starting a
    fresh handler: it enters recovery, classifies each role correctly, and converges to
    both roles on their new passwords with the store, Secret and pods agreeing. It does not
    report its own intermediate state as drift.
