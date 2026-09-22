# Building the container images

This tree builds three production images with Nix. It is not the `Dockerfile` at the
repository root and does not replace it: that one is the development and CI image —
Debian, a compiler toolchain, git, composer, a shell — and it exists so a contributor
can run the differential suite from a clean checkout. These are the other thing.

The decision is [ADR-0013](../docs/adr/0013-nix-built-container-images.md); the work and
what remains unproved is [plan 20](../docs/plans/20-container-infrastructure.md).

**This was first built on 2026-09-04**, on macOS through
[`build-in-podman.sh`](build-in-podman.sh). `nix flake check` passes its 34 assertions and
all three images build and load. The sentence that stood here until then — "nothing here
has been built yet, so treat the first `nix build` as part of the work rather than as a
formality" — turned out to be right. The first build found five defects, three of them
contradicting ADR-0013's claim that these images carry no shell. Every one was fixed in the
source rather than in [`checks.nix`](checks.nix), which is what found them.

**The pod runs, as of the same day.** `deploy/podman/victual.yaml` serves under
`podman kube play` — [issue #49](https://github.com/datagen24/victual/issues/49) is closed
and [ADR-0013](../docs/adr/0013-nix-built-container-images.md) was accepted on the strength
of it. That took two more defects that were invisible in the YAML, both of them this
manifest meaning something different under podman than under Kubernetes:

- `fsGroup` is not honoured for `emptyDir` (fixed by making `config.php` optional, which
  removed the writable mount entirely rather than working around it).
- `httpGet` probes are rewritten into `CMD-SHELL curl -f …` and run *inside* the container,
  which an image with no shell and no curl cannot pass.

Plus a third that no check could have found: every error page on these images was a fatal
error, because `GetSystemInfo()` opened a SQLite connection these images have no driver for.

Plan 20's verification section is still the list. As of 2026-09-18 the credential split
(check 8) is done — `victual-app` runs under a role with no DDL rights, see
[deploy/postgres/roles.sql](../deploy/postgres/roles.sql) — and so is the extension list
(check 6, which trimmed `zip` and `xmlwriter`). Open, and needing a cluster: the SIGTERM half
of the signal check (9), and the K3S apply.

## What gets built

| Output | What it is | Ships |
|---|---|---|
| `.#image-app` | php-fpm on loopback:9000 | PHP 8.5 + the extensions in `php.nix`, the application at `/app` |
| `.#image-web` | nginx on :8080 | nginx, `public/` and the yarn-built `packages/`, no PHP at all |
| `.#image-migrate` | `bin/victual-migrate`, a Job | PHP CLI, the application, and the `bin/` CLI entry points |
| `.#image-label-renderer` | the label renderer, a Rust binary | pinned from `datagen24/victual-label-renderer` |
| `.#image-label-worker` | the label delivery worker, a Rust binary | pinned from `datagen24/victual-label-worker` |
| `.#image-mcp` | `victual-mcp`, the read-only MCP sidecar | a Node process built from `mcp/` in this repository — **unbuilt**; see [issue #86](https://github.com/datagen24/victual/issues/86) |

All run as uid 65532, contain no shell and no package manager, and are built from
`scratch` — there is no base image and therefore no base image's CVEs.

**`.#image-mcp` has never been built.** `mcp/`
([issue #86](https://github.com/datagen24/victual/issues/86)) was scaffolded in a sandbox
with no npm registry access and no Nix, so `mcp/package-lock.json` does not exist and
`nix/hashes.nix`'s `mcpNpmDeps` is still the fakeHash placeholder. The first
`nix build .#mcp` after that lockfile is committed fails on purpose and names the real
hash; see "Bootstrapping the hashes" below, and `mcp/README.md` for what else the local
session needs to do first.

Unlike the label renderer and worker, whose source is pinned from its own repository
(`flake.nix`'s `label-renderer`/`label-worker` inputs), the sidecar's TypeScript lives in
this repository at `mcp/`. See `docs/mcp-interface-spec.md`'s Open Question 1 amendment
(2026-09-19) for why that reverses the spec's original "new repository" answer.

The split is not decoration. The web tier holds the document root and no credential; the
app tier holds the credential and no document root; the migrate tier is the only one
that should ever hold a role able to run DDL. That is ADR-0010's "its own credential,
its own database role, least privilege for the one job it does".

**What separates them is the credential, not the bytes.** The application tree is identical
in all three images and is meant to be. `migrations/` and `db/` ship in every one because
they are read on the request path — `SchemaVersionMiddleware` asks
`GetLatestMigrationNumber($dialect)` on every request, which reads the migration
directory, and the check is deliberately unconditional even though auto-migration
(`MIGRATE_ON_ROOT_REQUEST`) is off by default.

Trimming them is not blocked on plan 10, as an earlier draft of this file said. It is ruled
out by the view cache below, whose compiled file names hash the application root, so a
trimmed root would warm a cache the serving image could not use.

## Building it

### The awkward fact about macOS

Container images are Linux artifacts. On an Apple Silicon Mac, Nix builds
`aarch64-darwin` by default and **cannot build `aarch64-linux` without a Linux builder**.
This flake evaluates fine on a Mac — the devShell and the application derivation are
there — but `nix build .#image-app` will fail with "a 'aarch64-linux' with features {}
is required to build" unless one of the following is true.

**Option A — build inside podman**, which is what [`build-in-podman.sh`](build-in-podman.sh)
does. No change to the host, and podman is already the stated bootstrap tool:

```sh
nix/build-in-podman.sh bootstrap   # fill nix/hashes.nix and write flake.lock
nix/build-in-podman.sh check       # nix flake check
nix/build-in-podman.sh images      # build all three and load them into podman
nix/build-in-podman.sh all         # all of the above
nix/build-in-podman.sh clean       # discard the builder and its warm store
```

Three things it does that the obvious one-liner does not, each learned by the one-liner
failing:

- **The store is a long-lived container, not a `-v victual-nix-store:/nix` volume.** An
  empty volume mounted over `/nix` hides the store nix itself lives in, and seeding it by
  copying `/nix` in produces a store whose `nix` segfaults — rc=139, before it prints its
  own version.
- **The flake ref is `path:/src`, not `.`.** A bare `.` makes nix treat the directory as a
  *git* flake, and in a git worktree `.git` is a file pointing at
  `<main repo>/.git/worktrees/<name>` — a path that does not exist inside the container
  (`failed to resolve path … libgit2 error code = 2`). `path:` reads a directory as a
  directory, and does not care that the tree is dirty, which is what you want while
  bootstrapping hashes that are by definition uncommitted.
- **It captures the streamer's path and runs it**, rather than `| xargs -I{} {}`. The
  nixos/nix image's busybox `xargs` will not substitute `{}` into the command position and
  answers `{}: No such file or directory`.

**Option B — a persistent Linux builder.** `nix-darwin`'s `nix.linux-builder.enable`
gives a small NixOS VM registered as a remote builder, after which
`nix build .#image-app` works from the Mac shell with no ceremony and, unlike option A,
keeps its store between builds. This is the better answer once the images are being
rebuilt regularly; option A is the better answer for finding out whether any of this
works at all.

### On a Linux host

```sh
nix build .#image-app          # then: ./result | podman load
nix run  .#load                # builds all three and loads them
nix flake check                # the assertions in nix/checks.nix
```

`nix run .#load` honours `CONTAINER_ENGINE`; set it to `docker` if that is what you run.

### Bootstrapping the hashes

Two derivations fetch from the network and therefore need a content hash:
the Composer vendor tree and the yarn offline mirror. Both start as `lib.fakeHash` in
[`hashes.nix`](hashes.nix), which means **the first build of each fails on purpose**:

```
error: hash mismatch in fixed-output derivation '/nix/store/…-victual-composer-vendor-4.6.0.drv':
         specified: sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
            got:    sha256-h9Xk…=
```

Paste the `got:` value into `hashes.nix` and build again. `nix build .#app` gives you
`composerVendor`; `nix build .#frontend` gives you `yarnOfflineCache`. They change only
when `composer.lock` or `yarn.lock` changes — and a changed lockfile with an unchanged
hash here is a build failure rather than a silently stale dependency set, which is the
property being bought. A version bump does not move them. The vendor tree records a root
package version, and `nix/app.nix` pins the one Composer sees rather than passing
`version.json`'s through, both for this property and because Composer's parser refuses a
suffix such as `-MVP` that an image tag is free to carry.

`flake.lock` is committed, for the same reason the hashes are: it cannot be written by
hand, `nix flake update` produces it, and it is the pin that makes "reproducible" mean
anything — a supply-chain input that belongs under review like any other. The `nix` CI
workflow runs `nix flake metadata --no-update-lock-file`, so a stale lock is a failed job
rather than a silent update.

## The tree

```
flake.nix              outputs; the only file a consumer needs to read
nix/
  overlay.nix          everything hangs off pkgs.victual
  hashes.nix           the two fixed-output hashes, in one place
  source.nix           what goes into an image — an allowlist, not an ignore file
  php.nix              PHP 8.5 with exactly the extensions the tree calls
  app.nix              the application + Composer dependencies
  frontend.nix         yarn → public/packages
  webroot.nix          the static tree the web tier serves
  healthcheck.nix      /opt/victual/healthcheck, the app tier's exec probe
  webcheck.nix         /opt/victual/webcheck, the web tier's — statically linked
  mcp.nix              the MCP sidecar, buildNpmPackage over mcp/ — unbuilt, see above
  checks.nix           what `nix flake check` proves
  runtime/
    php-ini.nix        production php.ini, applied to every SAPI
    fpm-conf.nix       the php-fpm pool
    nginx-conf.nix     the static tier's configuration
    webcheck.c         the web tier's probe; see it for why podman forces an exec probe
  images/
    lib.nix            uid, labels, the shared half of the OCI config
    app.nix / web.nix / migrate.nix / label-renderer.nix / label-worker.nix / mcp.nix
    load.nix           `nix run .#load`
```

## Three things worth knowing before you change something here

**The application is served from its store path, and that is not an aesthetic choice.**
`bin/victual-warm-cache` compiles Blade templates whose compiled file names hash the
*absolute path* of the views directory — the warmer's own comment warns that moving this
path breaks every request. Warm at one path and serve from another and every page is a 500
against a read-only cache. Baking the cache inside `nix/app.nix`, the derivation that owns
the tree, makes the warm path and the serve path the same one by construction. An earlier
draft copied the application to `/app` and would have produced exactly that failure.

The same constraint is why there is one application derivation for all three images rather
than a trimmed one for the serving tier: a second root is a second path, and its cache
would not match.

**nginx names the app's store path without depending on it.** `SCRIPT_FILENAME` is a path
in the *other* container, so `nix/runtime/nginx-conf.nix` wraps it in
`builtins.unsafeDiscardStringContext` — keeping the text and dropping the dependency edge.
Without that, the web image would carry every PHP file it exists in order not to have.
`nix/checks.nix` asserts it.

**Everything the request path opens by `__DIR__`-relative path has to be in the
allowlist, and `nix/checks.nix` check 4 is the list of what that turned out to mean.**
The first review found three files missing from it — `victual.openapi.json`,
`migrations/`, `db/pgsql/baseline` — each of which fails at request time rather than at
build time. Adding a `file_get_contents(__DIR__ . '/…')` to the application is therefore
a change to `nix/source.nix` as well.

**The application is copied to `/app`, not symlinked.** PHP resolves `__DIR__` through
the realpath cache, so a symlinked `public/index.php` reports its store path and
`require_once __DIR__ . '/../app.php'` then looks inside a store path that has no
`app.php` in it. The copy costs a few megabytes in one layer and removes the whole
class of surprise.
