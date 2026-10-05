# Gate G1 Supervisor probe, first run

Run 2026-10-05 01:12 UTC (2026-10-04 local) for [plan 35](../../../docs/plans/35-home-assistant-target.md),
from the probe add-ons in [`../probe/`](../probe/) at commit `2db0fc14`. The reports were
read from `/share/victual-probe/` over SSH. Request headers that identify the household (the
public host name, client address, Home Assistant user, and session values) are omitted.

## Environment

| Item | Value |
|---|---|
| Hardware | Home Assistant Yellow, Raspberry Pi Compute Module 5, 16 GB, `aarch64` |
| Home Assistant | 2026.9.4 (maintainer); Supervisor 2026.09.3 (maintainer; matches `sentry-release` in the Ingress request) |
| Container runtime | Docker, overlay2 on NVMe, `/sbin/docker-init` as PID 1 |
| Add-ons | `local_victual_probe_root` (no image `USER`), `local_victual_probe_user` (`USER 65532:65532`); both built locally from `alpine:3.20` |
| Manifest | `ingress: true`, `ingress_port: 8099`, `map: share` read-write, no `privileged`, default `apparmor`, `watchdog` set but not enabled |

## Results

| Property | Root probe | UID 65532 probe |
|---|---|---|
| Process user | `uid=0` | `uid=65532 gid=65532`; the Supervisor honours the image `USER` |
| Effective capabilities | `0xa80425fb`: chown, dac_override, fowner, fsetid, kill, setgid, setuid, setpcap, net_bind_service, net_raw, sys_chroot, mknod, audit_write, setfcap | none; bounding set as root |
| `NoNewPrivs` | 0 | 0 |
| Seccomp | 0 (no filter) | 0 (no filter) |
| AppArmor | `docker-default (enforce)` | `docker-default (enforce)` |
| Root filesystem | writable overlay | not writable (ownership, not a read-only mount) |
| `/tmp` | writable, on the overlay (no tmpfs) | writable, on the overlay |
| `/data` | `755 root:root`, writable | not writable |
| `/data/options.json` | `600 root:root`, readable | **not readable** |
| `/share` (mapped read-write) | writable | not writable (`755 root:root`); the report directory `/share/victual-probe` was made `1777` by `deploy.sh`, so the report landed there |
| Environment names | `HASSIO_TOKEN`, `SUPERVISOR_TOKEN`, `TZ`, plus image variables | same |
| `memory.max` / `cpu.max` / `pids.max` | `max` / `max 100000` / `19125` | same |
| Open-file limit | 1024 | 1024 |
| Network | `172.30.33.13/23`, gateway `172.30.32.1` | `172.30.33.14/23` |
| Names resolved | `supervisor` 172.30.32.2, `homeassistant` 172.30.32.1, `core-mosquitto` and `a0d7b954-influxdb` (IPv6 only) | same |
| Stop | SIGTERM reached the probe script through `docker-init` | same |

From the root probe, a child switched to UID 65532 with `su-exec`:

- could not read `/data/options.json`;
- could not read `/proc/<pid>/environ` or list `/proc/<pid>/fd` of a root process
  started with a marker variable;
- could read that root process's `/proc/<pid>/cmdline`.

`/proc` is mounted without `hidepid`.

## Ingress requests

One browser request per probe through the Home Assistant panel:

- `REMOTE_ADDR` was `::ffff:172.30.32.2` both times, the Supervisor address the Ingress
  documentation names.
- `X-Ingress-Path` was `/api/hassio_ingress/<token>`, with a different 43-character token per
  add-on.
- The request URI did not include the prefix: the Supervisor strips it before forwarding.
- Other forwarded headers: `X-Forwarded-For` (client, then 172.30.32.1),
  `X-Forwarded-Host` (the public host name), `X-Forwarded-Proto: https`,
  `X-Hass-Source: core.ingress`, `X-Remote-User-Id`, `X-Remote-User-Name`,
  `X-Remote-User-Display-Name`, and the browser's `Cookie`.

## Second run: probe 0.2.1

Run 2026-10-05 01:20 UTC from commit `9a29ee32`, with `tmpfs: true` added to both manifests.
Version 0.2.0 wrote its helper scripts to `/tmp` and could not run them; 0.2.1 builds them
into the image.

- `/tmp` became `tmpfs` mounted `rw,nosuid,nodev,noexec`. Executables placed in `/tmp`
  cannot run.
- `GET http://supervisor/addons/self/options/config` with the inherited `SUPERVISOR_TOKEN`
  returned `HTTP/1.1 200 OK` and the option keys `fail_after_seconds` and `probe_secret`:
  - as root in the root probe;
  - as a UID 65532 child of root that inherited the environment;
  - as UID 65532 in the user probe.
- With `SUPERVISOR_TOKEN` and `HASSIO_TOKEN` removed from the environment, the helper had no
  credential to send.
- The manifests declare no `hassio_api` or `hassio_role`, so the token's default role
  returns the add-on's own options, including fields of schema type `password`.

The probe records option keys only. That the response holds the values follows from the
endpoint's purpose and the 200 status; the values were not printed.

## Third run: probe 0.3.0

Run 2026-10-05 01:26 UTC from commit `85150f32`, both probes running.

Another add-on's options, with each probe's own token:

| Request | Root probe | UID 65532 probe |
|---|---|---|
| `GET /addons/<other>/info` | 403 Forbidden | 403 Forbidden |
| `GET /addons/<other>/options/config` | 403 Forbidden | 403 Forbidden |
| `GET /addons` | 403 Forbidden | 403 Forbidden |

Environment of other processes, reporting only whether `SUPERVISOR_TOKEN` is present:

| Reader | PID 1 (`docker-init`) | A sibling holding the token |
|---|---|---|
| Root probe, root process started without the tokens | readable, token present | readable, token present |
| Root probe, UID 65532 child started without the tokens | not readable | not readable |
| UID 65532 probe, process started without the tokens | readable, token present (PID 1 runs as 65532) | readable, token present |

In every case the reader's own environment had no token, so removing the variables
worked for the process itself.

## Watchdog run

Root probe 0.3.0 with the watchdog enabled and `fail_after_seconds: 60`
(`watchdog: "tcp://[HOST]:[PORT:8099]"`, port 8099 not published to the host):

| Time (UTC) | Event |
|---|---|
| 01:30:21 | Start number 6; listener up |
| 01:31:23 | Listener stopped; PID 1 still running |
| 01:34:01 | SIGTERM to the probe |
| 01:34:02 | Start number 7; listener up |

The restart came 2 minutes 38 seconds after the listener stopped, with no restart requested
from the user interface (maintainer to confirm). The TCP watchdog works on an unpublished
Ingress port, and it restarts a container whose PID 1 is still alive.

## Not measured in these runs

- Whether children survive the stop of PID 1's script; the container stop ends them
  regardless.
- Any manifest control that lowers the capability set, enables seccomp, sets a memory limit,
  or makes the root filesystem read-only.
- A forged `X-Ingress-Path` on a directly published port; the probe published none.
- The `amd64` architecture.
