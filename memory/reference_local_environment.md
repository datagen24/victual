---
name: Local environment
description: Running the pgsql, frontend and parity suites plus the Nix image build on this Apple Silicon Mac, where the documented docker compose invocation does not work; and the git signing and worktree hazards.
type: reference
---

Everything here was verified on the maintainer's Apple Silicon Mac on the dates given.
Nothing in the repository is wrong — the host is what differs, so these are workarounds and
not corrections to the docs.

## `docker compose` does not work here

`docker compose run --rm dev …` — the invocation `docker-compose.yml` and the docs give —
fails on this machine. `docker` is podman's CLI shim and it shells out to
`/usr/local/bin/docker-compose`, an x86 binary: `bad CPU type in executable`. Drive podman
directly.

## The engine-vs-engine suite (`.devtools/pgsql/`, a CI gate)

About four minutes for the whole run; verified 2026-09-06 at fourteen phases — the runner's
own header is the authority on the count, not this file.

```bash
docker build --target dev -t victual:dev .
docker network create victual-suite
docker run -d --name victual-pg --network victual-suite \
  -e POSTGRES_USER=victual -e POSTGRES_PASSWORD=victual -e POSTGRES_DB=victual \
  --tmpfs /var/lib/postgresql/data postgres:16
docker run --rm --network victual-suite -v "$PWD":/app -v /app/packages -w /app \
  -e VICTUAL_ROOT=/app -e PGHOST=victual-pg -e PGPORT=5432 \
  -e PGUSER=victual -e PGPASSWORD=victual -e SUITE_SCRATCH=/tmp/victual-suite \
  victual:dev .devtools/pgsql/run-tests.sh [phase]
```

The `-v /app/packages` anonymous volume is the part that is easy to miss: without it the host
mount shadows the image's composer output, which is gitignored and does not exist on the
host, and every phase dies on a missing autoloader. Tear down afterwards
(`docker rm -f victual-pg`, `docker network rm victual-suite`) — the databases are on a tmpfs
and every run rebuilds them.

## The frontend security probes (`frontend-security` CI job)

These run from the host, not a container. Install once:
`npm install && npx playwright install chromium` in `.devtools/frontend`, then boot a demo
instance the way `.github/workflows/tests.yml` does.

**Run `npx --yes yarn@1.22.22 install --frozen-lockfile` first.** There is no host `yarn`,
and without `public/packages` the pages load with no jQuery, so every probe fails with "the
sink was never reached" for a reason that has nothing to do with the change under test.

One probe, `manageapikeys-qr`, fails on clean `origin/master` here too (checked 2026-09-06),
so it is the local environment rather than a regression — confirm against a baseline checkout
before chasing it.

## The fork-vs-upstream suite (`.devtools/parity/`, not a gate)

`.devtools/parity/bin/parity all` boots this tree's production image and
`docker.io/linuxserver/grocy:version-v4.6.0` side by side in podman, plus PostgreSQL,
Mosquitto and InfluxDB for the fork. Needs only podman and Node 18+, but the fork side needs
the Nix images, which is the heavy build below. Built 2026-09-04. Things worth not
rediscovering:

- Upstream grocy has **no migrate command** — `SystemController::Root` calls
  `MigrateDatabase()`, so the schema is created by the first request to `/`. `curl -f`
  without `-L` treats the unauthenticated 302 as success and never gets there, leaving a
  0-byte `grocy.db` and a 500 on first login.
- Auth on both sides is a **session cookie**, not an API key. Both ship `admin`/`admin` from
  migration 0027; minting a key would need `psql` on one side and `sqlite3` on the other.
- The login form's password field is `#password_input` (with a hidden `#password_base64`).
- Phase order is load-bearing: `api` seeds, `ui` reads, `side-effects` runs last because it
  writes to the fork only and would otherwise show up as row-count noise.
- `bin/victual-publish-state` publishes the Home Assistant discovery payloads; the
  after-commit path publishes state topics only. InfluxDB measurements are `price_paid` and
  `stock_value`, not `price`.

## The Nix image build

`nix/build-in-podman.sh [bootstrap|check|images|all]`, added 2026-09-04. Container images are
Linux artifacts and Nix on Apple Silicon builds `aarch64-darwin`, so the build runs inside a
podman container on the nixos/nix image. Two constraints that cost real time:

- The store must be a **long-lived container**, not a named volume. Mounting an empty volume
  over `/nix` hides the store nix itself lives in; seeding the volume by copying `/nix` into
  it produces a store whose `nix` segfaults (rc=139, before printing its version).
- The flake ref must be **`path:/src`**, not `.`. A bare `.` makes nix treat it as a git
  flake, and in a worktree `.git` is a file pointing at `<main repo>/.git/worktrees/…`, a
  path that does not exist inside the container (`libgit2 error code = 2`). Likewise
  `nix flake update --flake path:/src` — newer nix reads a bare positional arg as an input
  name.

Images are `localhost/victual-{app,web,migrate}:4.6.0` — 284/205/291 MB against the old
Dockerfile production image's 819 MB.

## Git: signing and the shared stash stack

Commits are SSH-signed through 1Password's agent, which **locks with the screensaver**. A
commit attempted while the machine is unattended fails with `1Password: failed to fill whole
buffer` / `fatal: failed to write commit object`. `ssh-add -l` still lists the keys when
locked, so there is no cheap pre-check.

The failure itself loses nothing. What caused real damage was improvising around it: a
`git stash push` during an in-progress merge discarded the merge state, and a follow-up
`git add -A` swept unrelated staged files into a WIP commit. On a signing failure, stop and
reassess rather than reaching for stash.

**How to apply:** sign normally. Fall back to `--no-gpg-sign` only for checkpoint commits
that set work aside, and squash those away once signing works. Prefer a temporary WIP commit
over the stash stack in any case — this checkout has around twenty worktrees sharing one
stash stack, and concurrent agent sessions can pop each other's entries. If a stash is
unavoidable, `git stash push -u -m "<unique-tag>"`, capture the SHA from
`git stash list --format='%H %gs'`, and restore with `git stash apply <sha>`, never `pop`.

See [[feedback_verification_discipline]] for which of these suites answers which question.
