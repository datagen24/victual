# 35. Home Assistant add-on target

**Goal:** A household installs, configures, starts, upgrades, and recovers Victual through
the Home Assistant Supervisor from prebuilt images, with label printing and MCP as companion
add-ons and PostgreSQL, MQTT, and InfluxDB at configurable endpoints.
**Depends on:** [issue 654](https://github.com/datagen24/victual/issues/654) (the target) and
[issue 655](https://github.com/datagen24/victual/issues/655) (schema setup and upgrades,
a release gate for 654). [ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md),
which covers image publication, is Proposed.
**Status:** draft. This revision records the baseline inventory and the requirement ledger.
Topology, Ingress path handling, and the lifecycle design are open.

## Problem and outcome

Victual runs today as a Kubernetes pod (`deploy/k3s/`), a podman pod, or a Compose project.
Home Assistant OS has none of these. Its Supervisor runs add-ons from `config.yaml`
manifests, delivers user options as `/data/options.json`, and exposes a web interface
through Ingress, an authenticated reverse proxy that serves each add-on under an
installation-specific path prefix inside a Home Assistant frame.

After this plan, a Home Assistant OS installation can run Victual without a local image build:

- the main add-on sets up or upgrades the schema with the migration role, then serves
  with the restricted role, without the one-time Grocy import add-on;
- the browser interface works under Ingress with ordinary Victual login and permissions;
- the label renderer, label delivery worker, and MCP server run as companion add-ons;
- an operator can back up and restore the database, configuration, and durable files.

[Plan 17](17-ecosystem-clients.md) closed a different add-on question. It rejected an
always-on add-on that would hold MQTT state between Victual and Home Assistant, because the
broker already holds that state. It did not consider Victual itself as an add-on.

## Scope

Included: the main application, fresh setup and version upgrades, label rendering, physical
label delivery, and the existing read-only MCP tools. PostgreSQL is required. MQTT and
InfluxDB keep their independent enable settings. Every service endpoint is configurable,
whether the service runs on the Home Assistant host or elsewhere on the LAN. Release targets
are `amd64` and `aarch64`.

Excluded: the Grocy import flow ([issue 484](https://github.com/datagen24/victual/issues/484)),
MCP write tools ([issue 209](https://github.com/datagen24/victual/issues/209)), new MQTT
integration contracts, mapping Home Assistant users to Victual users, changes to the existing
Kubernetes deployment, and application downgrade after a schema upgrade.

The work has four completion states. Each requires the evidence named in
[Verification](#verification).

| State | Meaning |
|---|---|
| Feasibility established | Gates G1–G4 have reproducible evidence and a reviewed design. |
| Pilot built | Versioned Nix images and an installable experimental repository entry exist; local regression checks pass. |
| Pilot verified | An actual Supervisor installation completes the end-to-end checks, including a physical printer, an MCP client, an upgrade, and a restore. |
| Release ready | Every criterion in issues 654 and 655 is evidenced on every advertised architecture, required ADRs are accepted, and publication and support ownership are settled. |

A container started outside the Supervisor does not count as Supervisor evidence. Build
success on an architecture does not qualify it for advertised support.

## Current behaviour

Measured 2026-10-04 against `46a35e348dd909c7f7614d758e680efcced8f1fe` (master). The issues
were written against `43deecab`. The three merges since then add migrations up to
`0301.pgsql.php`, which converts every timestamp column to `TIMESTAMPTZ` behind a read-only
preflight ([ADR-0027](../adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md)).
Line references below are to that commit.

### Images and architectures

`flake.nix:95-106` builds six images for `x86_64-linux` and `aarch64-linux`: `victual-app`
(php-fpm on 127.0.0.1:9000), `victual-web` (nginx on 8080), `victual-migrate`,
`victual-label-renderer`, `victual-label-worker`, and `victual-mcp` (Node on 3000). Every
image runs as `65532:65532` and has no shell (`nix/checks.nix:59-177`). The Kubernetes
manifests and the `nix.yml` boot test supply the read-only root filesystem and the `/tmp`
tmpfs; the images assume both and do not enforce them.

`release.yml` publishes multi-architecture tags to `ghcr.io/datagen24/victual-<component>`.
Tag `v0.2.0-MVP` (`ecc2944a`, 2026-09-24) published all six; the GitHub release lists their
digests. An anonymous token request and manifest fetch for `victual-app:0.2.0-MVP` returned
HTTP 200 on 2026-10-04, so that package is publicly pullable; the other five were not
tested. The [release record](../releases/0.2.0-MVP.md) still lists setting the packages
public as not done.

The label renderer and delivery worker are Rust crates in separate repositories, pinned at
`f05c432f` and `da925898` in `flake.lock` (`flake.nix:27-32`). Their transports, `--health`
flag, and paired mode cannot be verified from this tree.

### Configuration

`Setting()` (`helpers/extensions.php:331-352`) resolves `VICTUAL_<NAME>` from
`<DATAPATH>/settingoverrides/<NAME>.txt`, then the environment, then the default; a
constant defined by `<DATAPATH>/config.php` wins over all three. `VICTUAL_DATAPATH` is
`/data` in the app and migrate images (`nix/images/app.nix:68`).

The settings an add-on maps are all existing settings in `config-dist.php`:

| Area | Settings |
|---|---|
| PostgreSQL | `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE` |
| Mode and lifecycle | `MODE`, `MIGRATE_ON_ROOT_REQUEST` (default false) |
| Paths | `BASE_PATH`, `BASE_URL` |
| Printing prerequisites | `FEATURE_FLAG_LABELS=true` and `FILE_STORAGE=database` (`helpers/ConfigurationValidator.php:209-227`) |
| MQTT | `MQTT_ENABLED`, `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME`, `MQTT_PASSWORD`, `MQTT_TLS`, `MQTT_CLIENT_ID`, `MQTT_TOPIC_PREFIX`, `MQTT_DISCOVERY_PREFIX`, `MQTT_DISCOVERY_MODE`, `MQTT_CONNECT_TIMEOUT_SECONDS`, `MQTT_DEVICE_NAME` |
| InfluxDB | `INFLUXDB_ENABLED`, `INFLUXDB_URL`, `INFLUXDB_TOKEN`, `INFLUXDB_ORG`, `INFLUXDB_BUCKET`, `INFLUXDB_TIMEOUT_SECONDS` |

`VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` is read with `getenv()` only
(`services/Database/InitialDataSeeder.php:144,221`). `DB_SSLMODE` is the only TLS setting.
No setting names a CA or client certificate for PostgreSQL or MQTT. Whether libpq's
`PGSSLROOTCERT` in the environment satisfies `verify-full` is unverified.

`ConfigurationValidator` already rejects an empty `MQTT_HOST` or InfluxDB URL, org, or bucket
only when that service is enabled (`helpers/ConfigurationValidator.php:301-351`), so a
disabled service does not need a valid endpoint.

### Schema lifecycle

`bin/victual-migrate` loads the baseline and seeds on an empty database, applies missing
migrations on an older one, and exits 0 when the schema is current. Its behaviour differs
from what the issues assume in four ways:

- It does not refuse a newer schema. `GetUnknownMigrationNumbers()` is called only by
  `middleware/SchemaVersionMiddleware.php:77` and `.devtools/pgsql/schemagatetest.php`.
  Migrate applies its own missing files, ignores unknown rows, and exits 0.
- It prints a generated administrator password to stderr even under `--quiet`
  (`bin/victual-migrate:116-122`) when the baseline is loaded and
  `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` is unset.
- It uses the ordinary `VICTUAL_DB_USER` and `VICTUAL_DB_PASSWORD`. No separate migration
  settings exist; Kubernetes separates the roles by giving the init container and the app
  container different Secrets (`deploy/k3s/victual.yaml:64-84`).
- It has no database wait or retry. An unreachable database exits 1; Kubernetes restarts
  supply the retry.

The migration lock is a session-level `pg_advisory_lock(1986947956)`
(`services/Database/PostgresDialect.php:298`), which requires a direct connection or a
session-mode pooler.

`SchemaVersionMiddleware` compares sets of applied and required migrations and answers 503
with a specific message for a missing, unknown, or unreadable schema (`:109-177`).
`deploy/postgres/roles.sql` makes `victual_migrate` the schema owner and the only role that
can run DDL; `victual_app` has DML on tables and sequences only.

### Credential exposure inside one container

Three existing behaviours mean that a migration credential placed anywhere in a container
that also runs PHP is readable by the serving process:

- `nix/runtime/fpm-conf.nix:46` sets `clear_env = no`, so every container environment
  variable reaches the PHP workers.
- Home Assistant writes all options, including any migration password, to
  `/data/options.json`, and the app image uses `/data` as its data path.
- `Setting()` reads `/data/settingoverrides/*.txt` and `/data/config.php`, so a generated
  configuration file in the data path is also an input to the serving process.

Removing the migration password from the PHP environment does not isolate it while
`/data/options.json` is readable by the PHP user.

### Web serving and Ingress

nginx listens on 8080 and forwards to PHP-FPM over 127.0.0.1:9000
(`nix/runtime/nginx-conf.nix:41,89-90`). It has no `real_ip` or trusted-proxy
configuration. Two behaviours conflict with Ingress before path handling is considered:

- nginx sends `X-Frame-Options SAMEORIGIN` (`nix/runtime/nginx-conf.nix:105`). Home
  Assistant renders Ingress panels in a frame on its own origin. Upstream grocy fixed
  dialogs inside a frame for the community Home Assistant add-on in 4.4.2
  (`changelog/79_4.4.2_2025-02-28.md:17`).
- The base path is a build input. `bin/victual-warm-cache` writes a route cache whose file
  name hashes `BASE_PATH` (`helpers/CachePaths.php:39-45`), the cache directory is
  read-only, and a different runtime value makes Slim refuse to start. Every published image
  is built with an empty base path.

URL generation uses `BASE_URL`; with the default `/` it detects scheme and host but not a
path (`helpers/UrlManager.php:16-19,56-66`). The session cookie path is `BASE_PATH`
(`middleware/Auth/SessionCookie.php:34,54`), and the browser's `Victual.BaseUrl` comes from
the server-side helper (`views/layout/default.blade.php:101`). Nothing reads
`X-Ingress-Path`, `X-Forwarded-Prefix`, or `X-Script-Name`.

### Health

No health route exists. The app probe checks a TCP connection to PHP-FPM
(`nix/runtime/healthcheck.php`); the web readiness probe requests `/login`, which passes
through `SchemaVersionMiddleware` and therefore fails on a schema mismatch or an unreachable
database. A Supervisor `watchdog` restarts an add-on whose check fails, so a 503 from a
schema mismatch becomes repeated restarts unless startup fails before serving.

### Background work

Nothing in `deploy/` runs `bin/victual-publish-state`. MQTT publication is a retained
snapshot published in a request's shutdown handler after a data change, and the InfluxDB
outbox drains up to 200 rows in the same handler
(`services/DatabaseService.php:718-850`, `services/Outbox/OutboxService.php:77`).
`bin/victual-publish-state` publishes discovery and the full snapshot, retracts, or drains
the outbox on demand. `config-dist.php:308-310` recommends a start hook that no deployment
has.

### Label printing

Both label workloads authenticate with typed Victual API keys and hold no database
credential. The renderer runs with `--drain`; the delivery worker exits 0 when its queue is
empty. `deploy/k3s/label-workers.yaml` schedules both as one-minute CronJobs with
`concurrencyPolicy: Forbid`. Neither has a resident mode, and the MQTT wake signal of
[ADR-0026](../adr/0026-a-wake-signal-may-announce-label-work.md) (Proposed) is not
implemented.

Print attempts are fenced by `print_jobs.current_attempt_id`, leased for 60 seconds up to a
300-second hard deadline, and reaped to `uncertain` when the lease expires
(`services/Labels/PrintAttemptService.php`). A repeat attempt needs an administrator to call
`POST /api/labels/jobs/{id}/authorize-attempt`; nothing replays automatically. Render
requests retry automatically up to five times under a generation fence
(`services/Labels/RenderRequestService.php`).

ADR-0019 lists `tcp`, `usb`, `cups`, and `ipp` transports. A Brother QL-820NWBc printed over
raw TCP port 9100 and IPP from a native build; no image has printed from a cluster
([issue 93](https://github.com/datagen24/victual/issues/93)). The operator manual names no
supported printer or transport.

### MCP

`victual-mcp` serves Streamable HTTP at `POST /mcp` and `GET /healthz` on `MCP_PORT`
(default 3000), forwards the client's Victual API key with `VICTUAL-API-KEY-TYPE: mcp`, and
stores no key (`mcp/src/auth/resolver.ts:30-46`). Its six tools call only `GET`
(`mcp/src/victual/client.ts:43`). It speaks plain HTTP and leaves TLS to a proxy or a
private network. `mcp/README.md` describes four images, 15 tests, and server version 0.1.0;
the flake builds six images, the suite has 20 tests, and the release is 0.2.0-MVP.

### Home Assistant controls

The [app configuration reference](https://developers.home-assistant.io/docs/apps/configuration/)
(read 2026-10-04) documents `image` with an `{arch}` placeholder, `arch`, `init` (default
true), `ingress`, `ingress_port`, `ports`, `map` with `read_only`, `tmpfs`, `privileged`
capabilities, `apparmor`, `backup` and `backup_exclude`, `schema`, `services`,
`homeassistant` (minimum Core version), `stage`, and `watchdog`. It documents no key for the
container user, the UID, a read-only root filesystem, or a health check other than
`watchdog`. Gate G1 measures these on a running Supervisor.

## Gates

| Gate | Evidence | Work it gates |
|---|---|---|
| G1 Supervisor feasibility | Actual process users, options-file permissions, mounts, capabilities, resource controls, and watchdog behaviour on a running Supervisor | Topology and lifecycle implementation |
| G2 Credential boundary | Serving processes cannot read migration credentials from files, environment, process state, or inherited descriptors; the serving role cannot run DDL | Serving traffic after schema setup |
| G3 Ingress | One prebuilt image works under installation-specific prefixes, accepts the prefix header only from the Ingress proxy, and keeps Victual authorization | The browser pilot |
| G4 Architecture decisions | Topology, access and TLS, release ownership, and any named ADR-0010 exception are recorded; acceptance follows separately | Shipping the affected design |
| G5 Schema lifecycle | Issue 655's fresh, current, older, newer, concurrent, failure, and restore evidence | Completing issue 654 |
| G6 Full target | Companion, dependency, persistence, security, and architecture checks pass | Advertised support and closing the issues |

The current behaviour above already settles part of G2 and G3. A topology that places the
migration password in a container running PHP-FPM with `clear_env = no` and `/data` as its
data path fails G2. An image that sends `X-Frame-Options SAMEORIGIN` fails G3.

## Design candidates

Two topologies are compared under G1. Both keep schema setup inside the main add-on's
lifecycle; a separate import or upgrade add-on does not satisfy issue 655.

1. One main add-on that runs web, PHP, and migration as isolated processes, with the
   migration credential unreadable by the PHP user.
2. Separate serving components coordinated by the main add-on's lifecycle.

The label renderer and delivery worker start as separate companions because their images and
credentials are already separate. MCP is a companion whose client access does not pass
through Ingress. Each recommendation stands only if Supervisor evidence supports it.

## Dependencies

- [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) (Accepted): PostgreSQL only.
- [ADR-0010](../adr/0010-workload-standard.md) (Accepted): non-root, read-only root,
  own identity, probes, and limits. A departure is named in the record proposing the
  workload.
- [ADR-0013](../adr/0013-nix-built-container-images.md) (Accepted): Nix-built images.
- [ADR-0019](../adr/0019-label-printers-are-master-data.md) and
  [ADR-0021](../adr/0021-label-templates-are-application-data.md) (Accepted): label worker
  credentials and rendering.
- [ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md) (Proposed): the GHCR
  publication that a prebuilt `image` reference would use.
- The label renderer and worker repositories, for transport and health behaviour.

The upgrade fixture is `v0.2.0-MVP`: commit `ecc2944a`, schema at migration 0288, images
published with digests in its GitHub release
(`victual-app@sha256:fac1ea08b066e98993720702378b2ebd9117dc573d42a2c4f49f5a79f622c201`,
`victual-migrate@sha256:a431c1726d80984f536d4901e1faada198ab89c9780aec8de3fc6eac5ab8c389`).
An upgrade from it to master crosses migrations 0289–0301, including the 0301 timestamp
conversion and its preflight. Its images predate any Home Assistant packaging, so the
fixture data is created by running those images outside the Supervisor against the test
database, and only the upgrade runs under the Supervisor.

## Open questions

The maintainer answered three of issue 654's questions on 2026-10-04, and the answers apply
here:

- PostgreSQL, MQTT, and InfluxDB endpoints are configurable. The maintainer's deployment
  uses LAN PostgreSQL with MQTT and InfluxDB as add-ons on the Home Assistant host
  (issue question 1).
- Version upgrades belong to the main add-on lifecycle and do not depend on the Grocy
  import add-on (issue question 2).
- Printing and MCP companions are required with the target (issue question 5).

1. Which disposable Home Assistant OS architectures, printer, and MCP client are available
   for testing? The pilot environment below is empty until this is answered.

   | Item | Value |
   |---|---|
   | Home Assistant OS, Core, and Supervisor versions | Not provided |
   | CPU architecture of the first instance | Not provided |
   | PostgreSQL version and location | Not provided |
   | Printer model and transport | Not provided |
   | MCP client and version | Not provided |

2. Which Supervisor-enforceable topology keeps the migration credential away from the serving
   processes?
3. Which direct API and MCP access paths and which TLS termination does the pilot support?
4. Where are experimental artifacts published, and who owns release and recovery support?

## Verification

Each row is one checkbox from the issues. "Actual Supervisor" means a Home Assistant OS
instance whose Supervisor installed the add-on. Evidence records go under
`.devtools/home-assistant/` with the date, source commit, versions, image digests, and
architecture.

### Issue 654

| ID | Criterion | Gate | Evidence | Needs |
|---|---|---|---|---|
| 654-R1 | Issue 655 delivered | G5 | Every 655 row below evidenced and linked | As 655 |
| 654-A1 | Topology, access, and release questions resolved; ADRs accepted separately | G4 | This plan's open questions answered; ADR acceptance PRs linked | Maintainer |
| 654-A2 | Published add-on installs on clean instances for every advertised architecture | G6 | Actual Supervisor install log from repository URL, per architecture, no local build | HA OS per architecture |
| 654-A3 | PostgreSQL, MQTT, InfluxDB with HA-hosted and LAN services; endpoint change, invalid credentials, TLS, disabled services, recovery | G6 | Matrix per service and location, including LAN PostgreSQL with HA-hosted MQTT and InfluxDB | HA OS, LAN PostgreSQL, MQTT and InfluxDB add-ons |
| 654-A4 | Stock booking survives add-on restart and host reboot; users, permissions, uploads, labels intact | G6 | Before and after reads of the same records on an actual Supervisor | HA OS |
| 654-A5 | Ingress login, logout, redirects, assets, uploads, API reads and writes; forged Ingress headers do not bypass authorization | G3 | Request log under two synthetic prefixes and one Supervisor prefix; forged-header requests on the direct listener refused | HA OS for the Supervisor prefix |
| 654-A6 | Invalid options, database outage, migration failure, unhealthy child, stop and start; no migration credential in serving, no incompatible schema served | G2, G5 | Logs and health state per case; G2 probe output | HA OS, PostgreSQL |
| 654-A7 | Fresh install and upgrade from a named release without the import add-on; restore a pre-upgrade backup into a fresh installation | G5 | Upgrade from `v0.2.0-MVP`; restore with matching release; data comparison | HA OS, PostgreSQL |
| 654-A8 | Non-root, filesystem restrictions, least privilege, secrets, health checks, resource behaviour on the actual Supervisor | G1, G2 | Process, mount, and capability listings from the running add-on | HA OS |
| 654-A9 | Configuration and image checks and regression coverage in CI; coverage ratchet kept | G6 | CI run with HA manifest checks; `SUITE_COVERAGE=1` result at or above the `tests.yml` ratchet | CI |
| 654-A10 | Printing companions render and physically print a label; job status; printer outage and worker restart; uncertain delivery documented | G6 | Printed label, final job state, outage and restart runs with no unauthorized replay | HA OS, printer |
| 654-A11 | MCP companion with a supported client; tools, permissions, rejected credentials, reconnect after restart | G6 | Client session transcript outside Ingress | HA OS, MCP client |
| 654-A12 | Companions across a main add-on upgrade, including main service unavailable | G5, G6 | Companion logs during the upgrade window | HA OS, printer, MCP client |
| 654-A13 | Installation, configuration, backup, recovery, limitations, troubleshooting published with tested versions, digests, and steps | G6 | Operator documentation linked from the add-on repository | None |

### Issue 655

| ID | Criterion | Gate | Evidence | Needs |
|---|---|---|---|---|
| 655-A1 | Execution and credential-isolation design recorded, ADR acceptance linked | G2, G4 | Design section of this plan; ADR PRs | Maintainer |
| 655-A2 | Install on an empty database without the import add-on | G5 | Actual Supervisor first start; administrator credential not in Supervisor logs | HA OS, PostgreSQL |
| 655-A3 | Upgrade a named release without the import add-on; users, permissions, stock, files, labels survive | G5 | Upgrade from `v0.2.0-MVP` across 0289–0301; record comparison | HA OS, PostgreSQL |
| 655-A4 | Restart on a current schema without destructive changes or repeated bootstrap | G5 | Second start log; unchanged row counts and administrator | HA OS, PostgreSQL |
| 655-A5 | Simultaneous starts, outage and recovery, interrupted run, failing migration; no incompatible schema serves | G5 | One run per case; serving refused until success | PostgreSQL; HA OS for the Supervisor half |
| 655-A6 | Newer database refused with an actionable error | G5 | Start against a schema with an unknown migration row; refusal before serving | PostgreSQL |
| 655-A7 | Serving processes cannot obtain elevated credentials or run DDL | G2 | Probe output from the serving identity: environment, files, `/proc`, descriptors, and a DDL attempt | HA OS, PostgreSQL |
| 655-A8 | External PostgreSQL backed up before upgrade and restored with the matching release; HA backup contents stated | G5 | Backup, restore, and comparison log | HA OS, PostgreSQL |
| 655-A9 | Supervisor lifecycle on every advertised architecture with versions, digests, environment, steps | G6 | One record per architecture | HA OS per architecture |
| 655-A10 | Coverage ratchet kept; test results in the implementation PR | G6 | `SUITE_COVERAGE=1` result | CI |
| 655-A11 | Implementation PR and evidence linked in issues 655 and 654 | G6 | Issue comments | None |
