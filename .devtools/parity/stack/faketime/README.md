# Simulated time in the parity stack

The year phase replays a simulated year of household activity. The application has no clock
seam to make that possible: `POST /consume`, `/open` and `/transfer` accept no date at all,
and `stock_log.used_date` (`services/StockService.php:635,671`), `stock.opened_date`
(`:1521,1530,1564,1574`) and the `row_created_timestamp` column default on ~40 tables are
clock reads. Only `purchased_date`, `best_before_date`, `tracked_time`, `done_time` and
`meal_plan.day` can be placed in simulated time through the API — a year built from those
alone would be a year of *state* at a few minutes of *chronology*.

So the clock is faked, with `libfaketime`, in the three runtimes that read one.

**Everything below was measured on 2026-09-05**, podman 6.0.2, aarch64, against
`victual-{app,migrate}:4.6.0`, `postgres:16` and `linuxserver/grocy:version-v4.6.0`. None of
it is inference, and the negative results are kept because each one cost a run to find.

## What is faked, and how

| Runtime | Why it needs faking | How |
|---|---|---|
| `victual-app`, `victual-migrate` | PHP's `date()`/`time()` | Library **mounted**, `LD_PRELOAD` in env. The image is not rebuilt. |
| `parity-postgres` | `LOCALTIMESTAMP` — the `row_created_timestamp` default lives here, not in PHP | Derived image, preload **on the binaries** (see below) |
| `parity-upstream` | PHP `date()`, and SQLite `CURRENT_TIMESTAMP` in the same process | Derived image, `LD_PRELOAD` in env |

`victual-web` (nginx) and mosquitto read no clock the suite asserts on.

**The fork's images are never rebuilt for the clock.** A nixpkgs-built `libfaketime` has
`RUNPATH=/nix/store/6wjykzqf…-glibc-2.42-84/lib`, and that is the exact glibc store path the
app image already carries — so the library resolves inside a *scratch* image with nothing in
it but the app's own closure. Mount plus environment is the whole intervention, and the suite
still boots the artifact that ships, under the same `--read-only --cap-drop ALL
--security-opt no-new-privileges` the manifest sets.

Those flags do not obstruct this: `no-new-privileges` only makes `ld.so` ignore `LD_PRELOAD`
for *setuid* binaries, and php-fpm is not one.

**The library must come from the image's own nixpkgs pin.** Built from any other revision its
RUNPATH names a glibc the image does not have, and `LD_PRELOAD` is then *ignored* rather than
failed — the process runs on at wall-clock time. `ft_assert_loaded` exists for exactly that
and runs before the first booking.

## Measurements

| | Result |
|---|---|
| `FAKETIME_NO_CACHE=1` | **521 µs per clock call** — 200k PHP `time()` calls took 104s |
| `FAKETIME_CACHE_DURATION=1` | 0.05 µs/call, against a 0.02 µs unpreloaded baseline |
| PostgreSQL startup, shimmed | 2s — the same as unpreloaded |
| Monotonic clock | `sleep(5)` took **5.00s** real while the wall clock jumped 6 months |
| Step propagation | a **running** process and a **newly spawned child** both reported the stepped time |
| PostgreSQL `row_created_timestamp` default | carried the simulated moment, and moved with a step |

`NO_CACHE` is therefore unusable and the one-second cache is not a tuning choice. The price is
up to one real second of staleness after a step, which is why `ft_wait_until` polls instead of
sleeping — and why it is **bounded on both sides**. A lower bound alone is not a check: every
target is historical, so `observed >= target` is satisfied by the real clock the instant the
preload silently fails.

## Result

`bin/clock-check`, 2026-09-05, against images rebuilt from this tree:
**PASS — 17 checks, every surface agreed at every step.**

## Four things that do not work, and cost a run each

**`LD_PRELOAD` as container environment hangs PostgreSQL.** The official entrypoint re-execs
itself through `gosu postgres`, and the second pass never reaches bash's first traced
command — empty logs, no children, no error. Every piece works preloaded on its own (dash,
bash, gosu, `initdb --version`, `postgres --version`); only the combination hangs, and it took
a `SHELLOPTS=xtrace` run to localise. `Containerfile.postgres` shims `postgres` and `initdb`
in `/usr/local/bin` — which precedes `/usr/lib/postgresql/*/bin` on the image's PATH — so the
preload lands on the binaries that read the clock and never on `env`, bash or gosu.

**A long mountpoint basename is silently mangled.** Mounting at the library's own store path
produced a directory named `…-libfaketime-0.9o`: 51 characters in, 49 out. The preload could
not be opened, and the only symptom was an `ld.so` line on stderr and a container still
running at real time. The library is mounted at `/ft` for that reason; it still resolves its
own libc, because that comes from RUNPATH rather than from where the `.so` sits.

**An empty bash array under `set -u`** is an error in the bash 3.2 macOS ships, so the
argument builders expand as `${ft[@]+"${ft[@]}"}`. Missing this broke the *un-faked* path,
which is the one every other phase uses. (Nor does bash 3.2 have negative array subscripts,
so `${STEPS[-1]}` is spelled the long way.)

**A stale image looks exactly like a clock fault.** `/api/system/time` answered
`{"error_message":"could not find driver"}` on every faked run, which reads as the preload
having broken PDO. It had not: the *same* failure appeared with `PARITY_FAKETIME=off` and
stock images, and the image's own copy of `services/ApplicationService.php` called
`new \PDO('sqlite::memory:')` unconditionally — the `PDO::getAvailableDrivers()` guard from
c50a59f4 was in the tree and not in the image. Rebuilding fixed it, and `time_local_sqlite3`
went back to `""`, which is what the `sqlite-version-is-empty-without-the-driver` accepted
difference already describes.

Two habits came out of that and are worth keeping: **run the un-faked control before
blaming the clock**, and have the probe report the endpoint's own words. `clock-check` prints
the response body next to `UNREADABLE` for exactly this reason — "UNREACHABLE" on its own
sent the first run looking in the wrong place.

## Checking it

```bash
.devtools/parity/bin/clock-check
```

Boots both stacks at an anchor, steps the clock three times, and asks every runtime what time
it is through the surfaces the application itself uses — there is no `podman exec … date`
option, because the fork's images are built from scratch and carry no shell and no PATH. The
matrix is checked **after three steps**, not only at T0: a worker spawned after a step could
re-derive its own origin. It does not, but that is a result and this is what keeps it one.

## What this is not

Upstream's image gains a library, so in year runs it is **not** the published image
byte-for-byte. grocy's own code is untouched and only time syscalls are intercepted, but the
suite should say so rather than let it be discovered. PostgreSQL is infrastructure rather than
the artifact under test — ADR-0008 makes the engine a fork implementation detail — so the same
change there asks nothing different of the comparison.
