# The parity suite

Does this fork still behave like the grocy it forked from?

```bash
.devtools/parity/bin/parity all
```

That builds what is missing, boots both applications in Podman, drives every feature area
through the HTTP API against each, walks every page of both in a browser, and checks the
fork-only MQTT and InfluxDB surfaces against a real broker and a real InfluxDB. It takes
about six minutes on a laptop after the first run and needs nothing installed but Podman
and Node 18+.

## What it is for

**A tool to run as you work, not a gate.** It is deliberately not wired into CI: you run it
while changing a controller, a service or a view, to find out whether the change moved
something away from upstream — and you run one phase or one scenario, not the whole thing,
when you know what you touched.

```bash
.devtools/parity/bin/parity up                        # once
.devtools/parity/bin/parity api --only stock          # then, as often as you like
```

That is why the report is written to be read rather than merely to exit non-zero: every
difference names the step, the JSON pointer and both values, and accepted ones say which
record accepted them. The suite currently exits non-zero against `master` because the
differences it reports are real, which is another reason it is not a gate — a gate that is
red on arrival is one people learn to route around.

## Why this exists next to `.devtools/pgsql/`

They ask different questions and neither subsumes the other.

`.devtools/pgsql/` asks **is this fork the same on SQLite as on PostgreSQL** — an internal
question, answered by driving SQL straight at both engines and comparing views, triggers,
migrations and a handful of application paths. This one asks **is this fork the same as
upstream grocy 4.6.0**, the version `version.json` says the tree forked from, and answers
it by driving the HTTP surface of two running instances.

The gap between them is not theoretical, and the first run of this suite found it. The
pgsql suite compares *views and triggers* by executing SQL against both engines; it never
enters a controller. So SQLite-flavoured SQL written in PHP — in a service method, in a
`->orderBy()` argument — is invisible to it, because there is no view to compare and the
statement is only ever built at request time. Three such defects were sitting in `master`
when this suite was written, each producing a 500 or a 400 on a page or endpoint that works
upstream. See [What the first run found](#what-the-first-run-found).

The other direction holds too: this suite would pass on a fork whose SQLite path was
broken, because since [ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md)
there is no SQLite path left to break.

## What runs

| Phase | Command | What it drives |
|---|---|---|
| API | `parity api` | 288 calls across 8 scenarios, both instances, responses diffed |
| Browser | `parity ui` | 49 view routes plus a purchase workflow, both instances |
| Side effects | `parity side-effects` | MQTT retained topics, Home Assistant discovery, InfluxDB points — fork-only |

**The order matters and `all` fixes it.** `api` is what puts data into both instances, so
the browser walk has tables with rows in them and the MQTT check has a stock level to
publish. Running `ui` against a cold stack compares two empty applications, which is a
comparison that cannot fail. `side-effects` runs last because it writes to the fork only,
and anything it creates would show up in the browser walk as a row-count difference that
means nothing — which is exactly what happened while this was being written.

### The scenarios

They run in a fixed order and each sees what the ones before it left behind, on both
instances alike. That is deliberate: a derived query over an empty table returns the same
empty answer on any engine and proves nothing.

| Scenario | Covers |
|---|---|
| `system` | `/system/info`, `/system/config`, `/system/time`, localization |
| `entities` | Full CRUD over 9 writable entities, 8 read-only views, 4 failure paths |
| `stock` | Purchase, consume, open, transfer, inventory, entries, price history, the ledger |
| `barcodes-and-undo` | All 6 by-barcode operations, undo by booking and by transaction |
| `shoppinglist` | Explicit and derived membership, clear, the uihelper view |
| `chores-batteries-tasks` | Execute, charge, complete, and the undo of each, plus a chore whose assignment group is empty |
| `recipes-mealplan` | Positions, fulfilment, nesting, copy, consume, meal plan |
| `users-files-calendar` | Users, the recursive permission view, userfields, files, iCal |

## Reading a report

Every difference is **reported** or **accepted**, never dropped. The exit code is driven by
the reported count alone; accepted differences are printed on every run under their own
heading with the record that accepted them.

An accepted difference lives in
[`harness/lib/accepted.js`](harness/lib/accepted.js). The bar for adding one is
[ADR-0005](../../docs/adr/0005-wire-contract-is-the-invariant.md)'s: name what it touches,
say whether any of it is exposed, and cite the record that decided it. "It has always done
that" is not a reason, and neither is "no endpoint returns it" — ADR-0005 records that
exact reasoning being withdrawn once already, over `qu_factor`.

Sixteen entries exist today, plus an explicit `FORK_ADDED_FIELDS` list for fields the
fork adds that upstream never had — deliberately a named list rather than a blanket "extra
fields are fine", so that a field arriving by accident is still a difference somebody has
to explain. Two entries are ADR-0005's own accepted exceptions, and a third is the second
of those seen downstream — `chores.next_estimated_execution_time`, which is `null` upstream
because a date-only `start_date` breaks the positional `SUBSTR` upstream slices the time of
day out of. The rest are the fork's deliberate divergences, and three of those are worth
knowing about because they are the fork being *more careful* than upstream rather than
merely different:

- **`exposed-settings-allowlist`** — `GET /api/system/config` returns 21 fewer settings
  here. `SystemApiController::EXPOSED_SETTINGS` is an allowlist; upstream returns
  essentially every constant, including `LDAP_BIND_PW`. It is still a wire-contract
  narrowing, and [plan 17](../../docs/plans/17-ecosystem-clients.md) is where that lands.
- **`error-details-not-returned`** — upstream's API errors carry an `error_details` object
  with an absolute path, a line number and a stack frame from inside the container. This
  fork does not send it.
- **`non-integer-object-id`** — an id-taking endpoint given something that is not an
  integer is a `400` here and a `404` upstream, where SQLite's dynamic typing makes the
  comparison a silent non-match. The fork used to answer `500` and quote the failing
  statement; see [issue #48](https://github.com/datagen24/victual/issues/48).
- **`missing-object-is-404-on-every-verb`** — `PUT` and `DELETE` against an id that does
  not exist are `404` here and `400` upstream. `GET` of the same id is `404` on both,
  which is the point: the status used to depend on the verb rather than on the fact.
- **`create-with-no-fields-refused`** — `POST /api/objects/{entity}` with a body that sets
  no column of the entity is a `400` here and a `200` upstream. Neither side creates
  anything; upstream answers with a `created_object_id` of `"0"`, which identifies no
  object. This suite's first run recorded the `null`-against-`"0"` difference and said the
  `200` was the defect; [plan 11](../../docs/plans/11-api-error-handling.md) has since
  refused the request instead.

What normalisation erases is listed in [`harness/lib/normalize.js`](harness/lib/normalize.js)
and is deliberately thin: wall-clock moments, `uniqid()`-derived opaque handles, the
interpreter version, the instance's own base URL and generated secrets. **It does not
coerce types.** A PostgreSQL `true` where SQLite sent `"1"` is precisely the defect class
the fork can introduce without noticing, so it survives normalisation and is reported.

## What the first run found

Recorded here rather than fixed, because fixing them is substantive change that belongs in
its own pull request with its own dual-engine proof. Measured 2026-09-04 against
`localhost/victual:parity` — the `Dockerfile` production image this suite built before the
port to the Nix images described above — and `docker.io/linuxserver/grocy:version-v4.6.0`.
Kept as the record of that run rather than rewritten: several have since been fixed, and
what a later run reports is in `reports/`, not here.

**All three are the same family: SQLite-flavoured SQL written in PHP rather than in a
view**, which is why the existing differential suite could not see them.

| # | Symptom | Cause |
|---|---|---|
| 1 | `/locationcontentsheet` answers **500**; upstream answers 200 | `services/StockService.php:982` builds `SELECT IFNULL(sclc.location_id, …)`. PostgreSQL has no `IFNULL` — `ERROR: function ifnull(integer, integer) does not exist` |
| 2 | `POST /api/stock/shoppinglist/clear` with `done_only` answers **400**; upstream answers 204 | `services/StockService.php:500` — the same `IFNULL`, in a `where()` |
| 3 | `/shoppinglist` and `/mealplan` answer **500**; upstream answers 200 | `controllers/StockController.php:534` and neighbours pass `'COLLATE NOCASE'` as an `orderBy()` direction. It is SQLite-only, and against `uihelper_shopping_list` PostgreSQL answers `ERROR: column "uihelper_shopping_list.product_name" must appear in the GROUP BY clause` |

Three more differences are reported and are behavioural rather than broken. They need a
decision, not a fix:

- `GET /api/stock/products/{id}` reports `last_price` **1.23** where upstream reports
  **2.50** after the same sequence of two purchases and two inventories, and `avg_price`
  **2.222222** where upstream reports **2**. These are not the float-accumulation
  difference ADR-0005 accepts — that one is ~1e-15 — so `products_average_price` and
  `products_last_purchased` disagree with upstream about which bookings count.
- `GET /api/chores/{id}` returns a computed `next_estimated_execution_time` where upstream
  returns `null`.
- `POST /api/objects/locations` with an empty body returns `created_object_id: null` where
  upstream returns `"0"`.

And one finding came from the harness getting something wrong, which is worth keeping:
passing permission *names* where `SetPermissions` documents *ids* is rejected by PostgreSQL
(`23502`, not-null) and **accepted by SQLite**, which answered 204 and wrote nothing
usable. A suite that only drives valid input would not have seen it.

## The stack

**The fork side is the artifact the fork ships.** Since
[ADR-0013](../../docs/adr/0013-nix-built-container-images.md) the production images are the
three built by `flake.nix`, and this suite boots exactly those: `victual-migrate` runs and
exits, then `victual-app` (php-fpm on loopback) and `victual-web` (nginx) serve together,
with the same read-only root filesystem, the same dropped capabilities and the same
in-container probes [`deploy/podman/victual.yaml`](../../deploy/podman/victual.yaml) uses.
Until 2026-09-04 it built the `Dockerfile`'s `production` target, which ADR-0013's
acceptance removed; [issue #56](https://github.com/datagen24/victual/issues/56) was that
port. Comparing upstream against an image the fork does not ship was the weaker question.

Plain `podman run` on one network with DNS aliases, orchestrated by
[`stack/stack.sh`](stack/stack.sh) — with one pod, and still not `podman kube play`.

The pod is not optional and is not a step toward the manifest: php-fpm binds
`127.0.0.1:9000` (`nix/runtime/fpm-conf.nix`) so that nothing outside the pod can reach it,
and nginx's `fastcgi_pass` names that address, so the two serving containers must share a
network namespace. `podman pod create --network victual-parity` gives them that *and* keeps
`postgres`, `mosquitto` and `influxdb` resolvable; the published port lives on the pod,
because that is where a pod's ports live.

What is still avoided is playing the manifest itself. `deploy/podman/victual.yaml` is a
Kubernetes Pod on purpose and is the right shape for a *deployment*. It is the wrong shape
for this: Kubernetes runs every initContainer to completion before any regular container
starts, so a migrate initContainer in a pod that also contains PostgreSQL waits for a
database that has not been started yet. `stack.sh` runs the migrate image as its own
container once PostgreSQL is up. This is a test fixture, not a deployment artifact, and it
says so by not pretending to be one.

| Container | Image | Port |
|---|---|---|
| `parity-victual-app` | `localhost/victual-app:4.6.0`, in pod `parity-victual` | — (FastCGI on the pod's loopback) |
| `parity-victual-web` | `localhost/victual-web:4.6.0`, in the same pod | 8080, published by the pod |
| — | `localhost/victual-migrate:4.6.0`, run once and removed | — |
| `parity-upstream` | `docker.io/linuxserver/grocy:version-v4.6.0` | 8081 |
| `parity-postgres` | `postgres:16`, on a tmpfs | — |
| `parity-mosquitto` | `eclipse-mosquitto:2` | 1883 |
| `parity-influxdb` | `influxdb:2.7` | 8086 |

The tag is `version.json`'s `Version`, read at run time, because that is what
`nix/overlay.nix` tags with. There is **no data volume** on the fork side: the application
no longer requires a `config.php` ([issue #49](https://github.com/datagen24/victual/issues/49)),
uploads go to the database (`VICTUAL_FILE_STORAGE=database`, which this stack sets because
nothing writable is mounted), and `/data` is an empty read-only directory in the image.

### Building the fork's images

`parity all` builds them if they are not loaded and `--build` forces a rebuild. On a Linux
host with Nix that is `nix run .#load`; everywhere else — a Mac included — it is
`nix/build-in-podman.sh images`, which builds inside a podman container running the
official Nix image and keeps a warm store between runs, so only the first build is slow.
Either way the requirement is still Podman and Node 18+ and nothing else. See
[`nix/README.md`](../../nix/README.md), which has the whole story about why a Mac cannot
build a Linux image on its own.

The app container's readiness gate is the manifest's `startupProbe` run the same way —
`podman exec … /opt/victual/healthcheck`, a PHP script with the interpreter in its shebang,
because these images have no shell to run anything else with.

The upstream image is pinned to `version-v4.6.0` rather than `latest`, and that is the
whole argument of the suite: `version.json` says 4.6.0 / 2026-03-06 and so does the
upstream image's own `version.json`, so a difference the suite reports is one *this fork*
introduced, not one upstream shipped in a release the fork has not merged. Comparing
against `latest` would produce a report full of upstream's changelog.

Both databases are thrown away and rebuilt on `parity reset`, and a cold start is a
first-class check rather than a convenience: [plan 10](../../docs/plans/10-cold-start-statelessness.md)
is about what happens on the first request after a scale-up, and the only way to test that
is to have a first request.

**Two ordering traps are worth knowing before changing `stack.sh`.** Upstream grocy has no
migrate command — `SystemController::Root` is what calls `MigrateDatabase()`, so the schema
is created by the first request to `/` and by nothing else, and `curl -f` without `-L`
treats the unauthenticated 302 as success and never reaches it. The readiness gate
therefore follows redirects and then asserts that `admin`/`admin` actually logs in. The
fork does not have this problem, because plan 10 made migrating a step
(`bin/victual-migrate`) rather than a side effect of whoever knocks first.

## Credentials

`victual`/`victual` for PostgreSQL, `admin`/`admin` for both applications, a fixed InfluxDB
token — all in the open, for the reason `docker-compose.yml` already states about the same
shape: these exist for the length of a suite run, on a tmpfs, with every run creating them
from nothing. Sweep finding **S25** records exactly this as an Info-level observation.

`admin`/`admin` in particular is not a choice this suite made: both projects create that
user in migration `0027`, so it is the one credential that is the same on both sides, which
is what lets a scenario be written once. Authentication is a session cookie rather than an
API key for the same reason — `DefaultAuthMiddleware` accepts either on API routes, and
minting a key would mean `psql` on one side and `sqlite3` on the other, i.e. a bootstrap
that differs between the two things being compared.

## Commands

```bash
.devtools/parity/bin/parity all            # everything, in the right order
.devtools/parity/bin/parity all --build    # rebuild the Victual images from the tree first
.devtools/parity/bin/parity up             # boot both and leave them running
.devtools/parity/bin/parity api --only stock,entities
.devtools/parity/bin/parity ui --headed    # watch the browser walk
.devtools/parity/bin/parity reset          # cold start: drop both databases, re-migrate
.devtools/parity/bin/parity logs app       # php-fpm — where a PHP error goes
.devtools/parity/bin/parity logs web       # nginx — where a 502 is explained
.devtools/parity/bin/parity logs upstream
.devtools/parity/bin/parity status
.devtools/parity/bin/parity down
```

Reports land in `reports/` — `api-parity.md` for reading, `api-parity.json`,
`ui-parity.json` and `side-effects.json` for diffing against a later run. The directory is
gitignored.

## What this does not prove

Stated plainly, because a suite's blind spots are the part worth writing down:

- **It does not compare pixels.** Two separately branded applications differ on every page,
  and a screenshot diff would report the rename forever. What is compared is HTTP status,
  console and page errors, failed requests, and table/form/row structure.
- **It does not prove the SQLite path**, because ADR-0008 says there should not be one.
  That is `.devtools/pgsql/`'s importer fixtures.
- **It does not prove Home Assistant consumes anything.** A retained topic with a
  well-formed discovery payload is evidence that it *could*. Plan 18's verifications 2, 4
  and 8 still need the household's Home Assistant.
- **It does not cover the label printer or external barcode lookup.** Both reach outward —
  a thermal printer and a third-party HTTP API — and neither is present in this stack.
- **Row-level ordering within a response is normalised away** where every element has an
  `id`. An endpoint that documents an order and stops honouring it would not be caught
  here.
