# 41. Building and shipping Authentik with Nix

Research how Authentik, the reference identity provider for the 0.6.0 OAuth work, can be
built by Nix the way Victual's own images are ([ADR-0013](../adr/0013-nix-built-container-images.md)),
so its version is pinned in `flake.lock` and delivered with the rest of the stack.

Status: draft, research only, 2026-10-28. The maintainer answered six of the seven open
questions on 2026-10-28; the answers are recorded under [Open questions](#open-questions)
and reconciled into the sections below. Question 3's stripping step is a proposal awaiting
confirmation, and question 6 is unanswered. No implementation, image, chart change or
accepted decision follows from this document. It does not amend
[ADR-0055](../adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
or [ADR-0056](../adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md),
both Proposed.

## Recommendation

Take Authentik from the community flake
[`nix-community/authentik-nix`](https://github.com/nix-community/authentik-nix) as a flake
input pinned to a release tag (`version/2026.8.5` today), **without** making its `nixpkgs`
follow Victual's. Our own flake builds the image itself, from that flake's components,
with the same `streamLayeredImage` conventions as the other images. Deploy it with the
upstream `goauthentik/helm` chart as a separate release whose values override the image,
entrypoint-dependent probes and security context, and carry the blueprints.

Following the maintainer's answers, that work lives in its own repository, suggested name
`datagen24/victual-authentik`, with its own release lifecycle and the SBOM and attestation
work of [plan 40](40-release-sboms.md). The image is built by us, published with the
unified release, and (proposed) built with `authentik/enterprise/` removed so it can be
public. It carries a named exception to the no-shell check for Python and is rebuilt only
when its pin changes. It includes no outposts, pending the maintainer's answer to question 6. See [Repository split](#repository-split).

The nixpkgs package is not usable for this purpose: it is two minor versions behind the
version the ADR-0055 evidence was gathered on, and it has been exposed to three published
high-severity advisories for 49 days (see [Security-update lag](#security-update-lag)).
Writing a derivation of our own would reproduce the community flake's work, and its
maintenance, for no gain; vendoring that flake's `components/` (MIT) is the fallback if it
stops being maintained.

## Problem and outcome

The 0.6.0 release moves API and MCP authentication to OAuth 2.1 tokens from an external
authorization server, with Authentik as the required compatibility target (ADR-0055
decisions 2, 3 and 7; ADR-0056). The Authentik spike
([`.devtools/spikes/oauth-authentik/`](../../.devtools/spikes/oauth-authentik/README.md))
runs `ghcr.io/goauthentik/server:2026.8.5` as two containers, server and worker. That image
is a Debian build pinned by tag, outside the flake, with a shell, running as uid 1000.

Success has four parts. The Authentik version Victual is tested against is a reviewed
`flake.lock` entry. The image is built by us, with the same Nix conventions and CI
pattern as Victual's images, not pulled from upstream. It
meets ADR-0013's image properties or names each exception. And an Authentik upgrade is a pull
request of a known, bounded size.

## Current packaging

Measured 2026-10-28. Commands are in [Research](#research).

| Source | Version | Upstream lag | Python dependencies | Image-relevant notes |
|---|---|---|---|---|
| Upstream image `ghcr.io/goauthentik/server` | 2026.8.5 (2026-10-21) | — | `uv.lock`, FIPS Python base | 1,392 MB uncompressed (arm64), uid 1000, `dumb-init -- ak`, bundles GeoLite2 databases |
| nixpkgs `authentik`, `nixos-unstable` and Victual's `flake.lock` pin (`a5fc1be5`, 2026-10-21) | 2026.5.6 | 2026.8.0 (2026-08-18) not packaged; 2026.5.7 (2026-09-09) PR [NixOS/nixpkgs#571577](https://github.com/NixOS/nixpkgs/pull/571577) open since 2026-10-08 | nixpkgs' own Python set, `pythonRelaxDeps = true` | Hydra builds it on both architectures, but neither architecture's output at Victual's pin is in `cache.nixos.org` (Hydra cancelled both builds of an evaluation that week), so it builds from source at that pin |
| nixpkgs `authentik`, `nixos-26.05` | 2025.12.6 | Three minor versions | as above | — |
| `nix-community/authentik-nix` | 2026.8.5 (tagged 2026-10-21) | 0–2 days for each 2026.8.x release | uv2nix from upstream `uv.lock`, `sourcePreference = "wheel"` | Server/worker components in the `nix-community` Cachix for both architectures; NixOS module and VM test, no image |

Victual's flake follows `nixos-unstable` only; there is no stable channel in it.

### nixpkgs

[`pkgs/by-name/au/authentik/package.nix`](https://github.com/NixOS/nixpkgs/blob/master/pkgs/by-name/au/authentik/package.nix)
(616 lines) builds every piece: the generated Go and TypeScript API clients, the web UI
(npm), the documentation site, the Django backend as a Python package, the Rust worker
(`authentik` binary), the Go server, and an `ak` wrapper. `passthru.outposts` carries the
LDAP, proxy and RADIUS outposts. Maintainers are `jvanbruegge` and `rissson` (GitHub handles). `meta.license`
is plain MIT, which omits the enterprise directory (see [Licence](#licence)). nixpkgs has no
NixOS module for Authentik; two module PRs have been open since 2025.

Updates arrive in bursts with gaps. From the package's history on `nixos-unstable`:
2025.10.1 to 2025.12.4 on 2026-02-15, 2026.2.x never packaged, 2025.12.6 to 2026.5.3 on
2026-06-24, 2026.5.6 on 2026-08-03 with the commit title "fix build", and nothing since.
A bump is not a hash change: the 2026.5.3 to 2026.5.6 update needed build fixes, and the
web UI dependency hash differs per architecture.

### nix-community/authentik-nix

A flake with a NixOS module, a VM test and an overridable scope
(`lib.mkAuthentikScope { pkgs; python; authentik-version; }`). Its components are
`frontend` (pnpm), `pythonEnv` (uv2nix over upstream `uv.lock`), `rust` (the `authentik`
binary, built `--no-default-features --features core` as upstream's Dockerfile does, with a
patch removing `aws-lc-fips-sys`), `gopkgs` (server plus outposts), `staticWorkdirDeps`,
`manage` and `migrate`. Its README states it has no official support from the Authentik
project. Two people account for most commits (`Ma27` 169, `WilliButz` 145); Dependabot
bumps its inputs weekly; release bumps are a short `update: X -> Y` commit.

The upstream 2026.8.5 image carries no Go binary. `ak server` and `ak worker` exec the Rust
`authentik` binary (`lifecycle/ak`, `run_authentik`), which embeds Python through PyO3 and
starts the Django side itself. The community module starts the same binary directly
(`${rust}/bin/authentik server` and `… worker`). So an image needs `rust`, `pythonEnv`,
`frontend` and `staticWorkdirDeps`, and not `gopkgs`. That matters because `gopkgs` is the
one component not in the Cachix for either architecture.

## Design

### Repository split

Answer to question 1. The Authentik build and deployment move to a dedicated repository
with its own release lifecycle. **It does not exist yet; creating it is a maintainer
action, not part of this plan's pull request.** Suggested name: `datagen24/victual-authentik`,
which follows the `victual-label-renderer` and `victual-label-worker` naming. If the
repository may later hold Keycloak as well (ADR-0055 keeps the authorization server
swappable), `datagen24/victual-identity` is the alternative.

| Moves to the new repository | Stays in `datagen24/victual` |
|---|---|
| The `authentik-nix` flake input and its `flake.lock` pin | Victual's side of the OAuth configuration: issuer URL, client settings and the login mode, in Victual's chart values (ADR-0056) |
| The image derivation, its checks and the image publication | A reference to the pinned Authentik release: a released tag of the new repository, pinned like the label repositories' |
| The values file for the upstream `goauthentik/helm` chart | The spike under `.devtools/spikes/oauth-authentik/` and the swap test, pointed at the released image |
| The blueprints the swap test relies on (ADR-0055 prerequisite 8) | |
| Release SBOMs, attestation and provenance for the image, per [plan 40](40-release-sboms.md) | |

Plan 40 already describes this shape for the label repositories
([its section on them](40-release-sboms.md#label-repositories-publish-their-own-attested-sboms)):
a release workflow on a signed tag, immutable releases, an attested SBOM and SLSA
provenance, and a pin in Victual to the released tag rather than a branch. The Authentik
repository adopts the same list.

Its image SBOMs come from the image bytes and the Nix
closure as plan 40's do. Plan 40 measured Composer, npm and Cargo packages but no Python
application; whether its generators list the roughly 200 Python packages in this closure
by name and version, rather than only as store paths, was not checked.

Sections below that say "the flake" mean the new repository's flake. Victual's own
`flake.lock` carries no Authentik input under this split.

### Flake input

```nix
inputs.authentik-nix = {
  url = "github:nix-community/authentik-nix/version/2026.8.5";
  # Deliberately no `inputs.nixpkgs.follows = "nixpkgs"`; see below.
};
```

If that repository also pins its own nixpkgs (for the image tooling), `flake.lock` records
two nixpkgs revisions: its own and the community flake's (`55bfb4d7`, 2026-10-14, at this
tag). That is a cost in lock size, not in closure.

Following Victual's nixpkgs was measured and rejected. A dry run of the four image
components plus `gopkgs`, `manage` and `migrate` on aarch64:

| Variant | Derivations built locally | Download |
|---|---|---|
| Community flake's own nixpkgs, with the `nix-community` substituter | 7, all small except `gopkgs`; for the four image components only copy steps | 580.3 MiB (2.1 GiB unpacked) |
| `--override-input nixpkgs` to Victual's pin | 207, including Node.js 26 from source, the web UI, the Python environment and the Rust build | 1.1 GiB |

The community README also warns that a different nixpkgs can break the Python set.

The flake needs the `nix-community` binary cache as an extra substituter, both in
`nix/build-in-podman.sh`'s builder and in the `nix` CI workflow. Without it every image
build compiles the web UI and the Rust binary.

### Pinning and upgrades

A routine upgrade is: change the tag in the new repository's `flake.nix`, run
`nix flake update authentik-nix`, rebuild, run the spike probes against the new image, and
release. Victual then moves its reference to that release. No Authentik hash lives in
either tree. From 2026.8.2 to 2026.8.5 the community flake
tagged each release on the day upstream published it, except 2026.8.4, two days later;
earlier releases were not checked.

CI builds the Authentik image only when the `authentik-nix` entry in `flake.lock` changes
(question 7), not on every push to the default branch. Its closure is roughly five times
the size of a PHP image's, and nothing else in the new repository changes the image bytes
except the derivation itself, so a change to the image derivation also triggers it.

To take a release before the community flake tags it, or to carry a patch, use the scope's
`overrideScope`: replace `authentik-src` and `authentik-version`, and update the three
fixed-output hashes the flake's own `update-hashes.sh` maintains (`frontend`'s pnpm
dependencies, `rust`'s `cargoHash`, `gopkgs`' `vendorHash`, the last only if outposts are
built). The Python environment needs no hash because uv2nix reads `uv.lock`. Such a build
will not be in Cachix, so it compiles everything; see [Build cost](#build-cost-on-this-mac).

The nixpkgs route, for comparison, cannot be pinned by an override. `package.nix` builds its
components inside one `let` block, so `overrideAttrs` on `authentik` reaches only the final
wrapper. Pinning a different version means copying the file into `nix/` and editing the
version plus six hashes (`src`, the documentation site's `npmDepsHash`, two
architecture-specific `webui-deps` hashes, `cargoHash`, `vendorHash`). The Python
dependency versions stay whatever nixpkgs carries, with version constraints relaxed, so
the image would not run the dependency set upstream tested.

### Image

One image, `victual-authentik` (name open), built in `nix/images/authentik.nix` with
`imageLib`. Server and worker are the same image with different `args`, as upstream:

- `Entrypoint = [ "${rust}/bin/authentik" ]`, so `args: [server]` and `args: [worker]`
  work unchanged with the upstream chart, which sets `args` and never `command`. There is
  no `ak` script, no `dumb-init` and no `setpriv`: the root-only branch of `ak` (chown of
  `/data`, docker-socket group) is irrelevant to a non-root pod.
- `User = 65532:65532` from `imageLib`. Authentik's files are store paths and its writable
  state is mounted, so uid 1000 has no meaning to preserve.
- `Env` reproduces what `ak` and upstream's Dockerfile set: `TMPDIR=/dev/shm/`,
  `PROMETHEUS_MULTIPROC_DIR` under it (the directory must exist; `ak` creates it, so the
  image or an init step must), `PYTHONDONTWRITEBYTECODE=1`, `PYTHONUNBUFFERED=1`, and
  `AUTHENTIK_BLUEPRINTS_DIR` pointing at a real `/blueprints` directory (upstream's
  `authentik/lib/default.yml` sets `blueprints_dir: /blueprints`; the community flake
  points it into the store).
- The Rust binary's closure (24 paths, 264 MB) does not reference `pythonEnv` or
  `staticWorkdirDeps`; it finds them through the environment. The community module sets
  `PATH` to include `pythonEnv`'s `bin`, `PYTHONPATH` to `staticWorkdirDeps`, and a working
  directory (`%S/authentik` for the server, `%t/authentik` for the worker) into which its
  start script symlinks `staticWorkdirDeps/*`. The image does the same at build time:
  `Env` carries `PATH` and `PYTHONPATH`, and `WorkingDir` is a directory in the image
  holding those symlinks, which `nix/images/lib.nix` requires every image to state.
- The community module runs `migrate.py` as its own unit before server and worker start.
  Whether `authentik server` migrates on start, as upstream's image relies on, or the chart
  needs a migration step, was not checked.
- `/blueprints` is a real directory in the image holding the stock blueprints and an empty
  `mounted/`, not a symlink into the store, because the upstream chart mounts ConfigMaps at
  `/blueprints/mounted/cm-<name>`.
- Writable paths become volumes: `/dev/shm` (memory-backed `emptyDir`), `/tmp`, and
  `/data` for media when file storage is local.
- The probes call `ak healthcheck` in the upstream chart; the values file replaces them with
  `authentik healthcheck`.

| Image contents, 2026.8.5 | aarch64 | x86_64 |
|---|---|---|
| Closure of `rust`, `pythonEnv`, `frontend` and `staticWorkdirDeps` (224 store paths, sum of NAR sizes from the binary caches) | 1,359 MB | 1,342 MB |
| Upstream image, uncompressed (`podman image inspect`) | 1,392 MB | not measured |

A `streamLayeredImage` is its closure plus a few kilobytes, so the closure is a fair proxy
for the image size; no image was built.

The largest closure entries are `msgraph-sdk` (250 MB), `python3` (147 MB), the Authentik
source tree twice (106 MB as the raw input and 106 MB patched), `google-api-python-client`
(103 MB), `kubernetes` (79 MB) and the web UI (74 MB). The duplicated source tree is the
obvious reduction.

The Nix image also lacks what upstream adds: FIPS builds of Python, OpenSSL and `aws-lc`,
and the GeoLite2 databases. The maintainer accepts the FIPS loss for a home inventory
deployment (question 5). GeoIP is optional; see [GeoIP](#geoip).

Without outposts (question 6, coordinator's recommendation pending the maintainer), the
image and the build exclude `gopkgs`, which also removes the one component missing from
the `nix-community` Cachix.

Stripping `authentik/enterprise/` (question 3, proposed) removes 1.7 MB of source from each
copy of the tree in the closure. It changes the patched source and the `authentik` package
inside `pythonEnv`, so those two rebuild locally; the dependency wheels, the web UI and the
Rust binary still come from Cachix. See [Licence](#licence) for the feasibility reading.

ADR-0013 decision 5's "empty `/bin`" holds; its `image-has-no-shell` check does not pass as
written. `nix/checks.nix` forbids `python3` and `bash` in a runtime closure. Authentik is a
Python application, and nixpkgs' `python3-3.14.7` references `bash-5.3p15` directly (read
from its narinfo references).

The maintainer's answer to question 2 is a named, documented exception for this image, not
a separate check set. The existing check machinery makes that the simpler choice.
`nix/checks.nix` already runs one closure check per image against a shared
`forbiddenInRuntimeClosure` list, and the MCP image's Node exception consists of Node not
being on that list.

The Authentik check is one more derivation of the same shape, with
`lib.subtractLists [ "python3" "bash" ] forbiddenInRuntimeClosure` and a comment naming
why each is allowed. Every other entry (`dash`, `busybox`, `zsh`, `perl`, …) stays
forbidden. A separate check set would duplicate the non-root, label and closure checks
for one image.

Removing `bash` from Python's closure stays a later improvement, not a
gate. Under the repository split, the check lives with the image in the new repository,
copied from Victual's `nix/checks.nix` and recorded as an exception to ADR-0013 decision 5
in that repository's documentation.

The read-only root filesystem is unproven in both directions: the spike ran the upstream
image read-write, and the upstream chart leaves `readOnlyRootFilesystem` commented out.
Victual's chart and checks would need to prove it, as plan 20 did for Victual's images.

### Deployment

The recommendation is the upstream chart, `goauthentik/helm` (its 2026.8.5 release on
2026-10-21), as its own Helm release, not a sub-chart of `deploy/helm/victual/`:

- Authentik has its own lifecycle, database, secrets and upgrade cadence, and under ADR-0056
  it is the home lab's identity provider rather than a Victual component. A sub-chart
  would tie every Authentik upgrade to a Victual chart version, and the reverse.
- The upstream chart already exposes everything an image swap needs:
  `global.image.repository`, `tag` and `digest`; per-component `securityContext`,
  `volumes`, `volumeMounts` and probes; `blueprints.configMaps` and `blueprints.secrets`;
  and an `additionalObjects` list (rendered by its `additional-objects.yaml` template) for
  ConfigMaps defined in values.
- Writing Authentik templates in Victual's chart would duplicate the upstream chart and
  fall behind it in the same way the nixpkgs package does.

Victual's chart continues to take only the issuer URL and client settings, which ADR-0056
already places in environment variables and Helm values. Under the repository split, the
values file lives in the new repository. The image is published beside the others
by the release workflow ([ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md),
Proposed) and referenced by digest in the Authentik values file.

### GeoIP

Optional (question 5). Authentik uses the GeoLite2 City and ASN databases for location and
ASN context in events and policies; without them those fields are empty. Two ways to supply
them:

- **Setup-time fetch.** The deployment's setup script downloads the databases once with a
  MaxMind account ID and licence key (a free GeoLite2 account; the GeoLite2 licence
  restricts redistribution, so the databases cannot be baked into a public image). The
  files go on a persistent volume mounted at `/geoip`, where Authentik's defaults look
  (`events.context_processors.geoip` and `.asn` in `authentik/lib/default.yml`), and the
  key is a Secret. Refreshing them is a re-run of the script.
- **The chart's `geoipupdate` sidecar.** The upstream chart already runs
  `ghcr.io/maxmind/geoipupdate` beside the server and worker when `geoip.enabled` is set,
  with the same account ID and key, and refreshes on a schedule. It is a third-party image,
  which the maintainer's answer to question 3 prefers to avoid for the core release; as an
  optional add-on it is the lower-effort route.

Neither is required for the swap test.

### Blueprints and ADR-0055 prerequisite 8

Prerequisite 8 requires every provider policy and mapping the swap test relies on to use a
supported interface, live with the deployment configuration under version control, and have
positive and negative tests. Authentik's blueprints are the supported declarative interface.
The worker discovers them under its blueprints directory, and the upstream chart mounts
named ConfigMaps or Secrets there.

So the version-controlled form is a directory of blueprint YAML files, rendered into
ConfigMaps by the Authentik release's values (`additionalObjects` plus
`blueprints.configMaps`). It is pinned together with the image, because both are in the
same release. The blueprints themselves must avoid the expression-policy pattern that
question 15's answer excludes (internal model imports, row deletion). Generated per-client
objects from the registration adapter stay runtime state under question 18's answer; only
the recipe and its version are in Git.

## Licence

Authentik's root `LICENSE` is MIT except for `website/` (CC BY-SA 4.0) and
`authentik/enterprise/`, which is under the Enterprise Edition licence. That licence permits
copying and modification for development and testing without a subscription and requires a
subscription for production use; it forbids distributing the enterprise software otherwise.
The enterprise code is inside the Python package in every build: upstream's image,
nixpkgs' package and the community flake's. Without a licence key it is inert.

For a Nix build this means:

- Building and running the image privately is the same position as pulling upstream's
  image. Using an Enterprise-only feature in production needs a subscription; it needs no
  different build, because the code is already present and is enabled by a key.
- ADR-0055 question 8 is the live case: `act` on open-source Authentik needs an Actor that
  only the Enterprise Agents API creates. Answering it with a subscription is a licence
  decision, not a packaging one.
- Publishing the image to a public registry redistributes the enterprise directory. Upstream
  does the same with its own image, but Victual is not the licensor.

The maintainer's answer to question 3 combines with that constraint: our own Nix-built
image, published with the unified release, built with `authentik/enterprise/` removed so
the public package carries only MIT-licensed Python code. **The stripping step is proposed
and needs the maintainer's confirmation.** If stripping turns out not to work, the
fallback is a private GHCR package for the Authentik image, with everything else unchanged.

Stripping looks feasible from the 2026.8.5 source (read 2026-10-28; not run):

- `authentik/root/settings.py` loads the enterprise app only if
  `importlib.import_module("authentik.enterprise.apps")` succeeds, and skips it on
  `ImportError`. Upstream designs for its absence.
- Six non-test modules outside `authentik/enterprise/` import from it (the blueprint
  importer, the devices, object-types and system APIs, the agent connector API, the SCIM
  provider model). Every import is inside `try`/`except ImportError`.
- Seven API classes add enterprise mixins through `ConditionalInheritance`
  (`authentik/lib/utils/reflection.py`), which inherits only when the class can be
  imported.
- No migration outside `authentik/enterprise/` names one of its twenty app labels as a
  dependency (`grep` of every non-enterprise `migrations/` directory). On a fresh database
  the 16 enterprise migration directories are absent and never run.
- RFC 8693 token exchange, which ADR-0055 prerequisite 4 relies on, is core
  (`authentik/providers/oauth2/token/token_exchange.py`). So is the Entra ID OAuth source
  ADR-0056 configures upstream (`authentik/sources/oauth/types/entra_id.py`).

Three places need a run to confirm:

- `SPECTACULAR_SETTINGS` in `settings.py` names three enum classes inside
  `authentik.enterprise` by import string. If drf-spectacular resolves them when serving
  `/api/v3/schema/`, that endpoint fails in a stripped image. The web UI uses a generated
  client and does not need the endpoint at run time.
- The web UI still contains the enterprise pages; they would call endpoints that no longer
  exist.
- A database that previously ran the enterprise migrations keeps those tables. A stripped
  image must start against a fresh database, or the orphaned tables must be shown harmless.

Stripping also forecloses the subscription route for ADR-0055 question 8: the Enterprise
Agents API that creates an `act` Actor lives in `authentik/enterprise/agents/`. Choosing a
subscription later means a private, unstripped image.
- nixpkgs' `meta.license = mit` understates this; the community flake states no licence in
  its package metadata either.

## Risks

### Build cost on this Mac

With the `nix-community` substituter and the community flake's own nixpkgs, the four image
components download (580.3 MiB, 2.1 GiB unpacked, for those plus `gopkgs`' inputs) and only
copy steps build. The cost of a full source build (a release not yet in Cachix, a local
patch, or a Cachix outage) was not measured. It covers Node.js-based web UI bundling, a
Rust release build with `aws-lc`, and the Python environment; the follows dry run lists 207
derivations. Expect it to be the longest build in the flake on a 4 CPU, 8 GiB podman VM.

The builder container `victual-nix-builder-release` had 3.5 GB free of 100 GB on
2026-10-28 (`df -h /nix`, 97% used). Realising the closure would have taken most of that
while another session was running the Authentik spike on the same VM, so no build was run.
A build needs a store garbage collection or a larger VM disk first.

### Cross-building amd64 for the Talos cluster

The cluster is amd64; this Mac builds aarch64-linux inside podman. Cross-compiling is not
needed. The `nix` workflow already builds the flake natively on `ubuntu-latest`
(x86_64-linux) for ADR-0013 prerequisite 4, and the x86_64 outputs of `rust`, `pythonEnv`,
`frontend` and `staticWorkdirDeps` are in the `nix-community` Cachix (all four returned 200;
`gopkgs` returned 404 on both architectures). Building amd64 locally through emulation
would compile everything that is not cached, under QEMU, and is not recommended.

The fixed-output hashes are architecture-independent in the community flake (one hash per
fetcher). nixpkgs' web UI dependency hash is per architecture, which is one more reason the
nixpkgs route costs more per bump.

### Security-update lag

Upstream published three high-severity advisories on 2026-09-09:
[GHSA-cxwx-9x59-28qm](https://github.com/goauthentik/authentik/security/advisories/GHSA-cxwx-9x59-28qm)
(SAML denial of service),
[GHSA-qgqp-xh8r-v73r](https://github.com/goauthentik/authentik/security/advisories/GHSA-qgqp-xh8r-v73r)
(MFA bypass in the email authenticator) and
[GHSA-h6c5-mpvq-j4jc](https://github.com/goauthentik/authentik/security/advisories/GHSA-h6c5-mpvq-j4jc)
(privilege escalation through delegated management). Each affects `<= 2026.5.6` and is fixed
in 2026.5.7 and 2026.8.2. nixpkgs still ships 2026.5.6 on 2026-10-28, 49 days later. The
community flake tagged 2026.8.2 the day it was released.

A Nix-built Authentik is therefore only as current as its source, plus Victual's own
reaction time. Nothing in the flake notices an advisory. Dependabot does bump flake
inputs by revision (the community flake's own history shows it bumping `nixpkgs`,
`uv2nix` and `pyproject-nix`), but it does not move an input pinned to a tag such as
`version/2026.8.5` to a newer tag. Dependency advisories are currently assigned to the next
Victual release, which for an identity provider exposed through Cloudflare may be too slow.

The maintainer's answer to question 4: an Authentik advisory can take a faster path, and the
release machinery must allow rapid patch releases for Dependabot and other vulnerability
findings. A patch release carries no breaking API change; it contains only dependency
changes or regression fixes. That machinery is cross-cutting, not specific to Authentik.

[ADR-0047](../adr/0047-one-trunk-release-branches-and-promoted-images.md) (Proposed) already
provides the branch shape: a fix lands on `master`, is cherry-picked to
`release/<major>.<minor>`, and a patch version is a tag on that branch, promoted through UAT
without a rebuild. It does not define how fast that path runs, what may enter a patch
release, or how a dependency-only bump is fast-tracked. Those need their own decision.
This plan depends on it and does not design it.

Under the repository split, an Authentik patch is a release of the new repository (a new
`authentik-nix` tag adopted, rebuilt, attested), followed by a Victual patch release that
moves the pinned reference. Plan 40's proposed advisory monitoring
([Advisory monitoring for the Nix closure](40-release-sboms.md#advisory-monitoring-for-the-nix-closure))
would apply to the new repository's closure too; upstream's GitHub security advisories
are the more direct signal for Authentik itself.

Two smaller differences from upstream's image: the community flake uses PyPI wheels for
Python dependencies (pinned by hash in `uv.lock`, but prebuilt, not built from source), and
it drops FIPS, which the maintainer accepts (question 5). Neither weakens the pin; both are
departures from what upstream tests.

### Dependency on a community project

The community flake has no upstream support and two main maintainers. If it stalls, the
fallback is to copy its `components/` directory (MIT) into `nix/authentik/` and maintain
the three hashes ourselves, which is the "own derivation" option at a known starting point.

## Alternatives

| Option | Version available | Upgrade cost | Verdict |
|---|---|---|---|
| nixpkgs package | 2026.5.6 | Copy 616 lines, edit version and six hashes, fix builds; Python set not upstream's | Rejected: behind the tested version, security lag, unpinnable by override |
| Community flake input | 2026.8.5 | Tag change and lock update; Cachix supplies binaries | Recommended |
| Own derivation | Any | Write and maintain web UI, Python, Rust and Go builds | Rejected: duplicates the community flake; becomes the fallback by vendoring it |
| Keep upstream image, pin by digest | Any | Digest bump | Not Nix-built, so it does not meet the maintainer's goal; it is the comparison baseline and what the spike uses |

## Open questions

Answers recorded 2026-10-28. Question 6 has no answer from the maintainer yet.

1. **Where does the Authentik release live?** Victual's deploy tree (`deploy/authentik/`,
   values file plus blueprints), or the infrastructure repository that holds Traefik,
   Cloudflare and the cluster's other services? Prerequisite 8 asks for the provider
   configuration to live "with the deployment configuration", which either answer can
   satisfy; the blueprints Victual's tests depend on are the part that most wants to be in
   this repository.

   > **Response:** datagen24, 2026-10-28: ideally in its own `victual-*` repository with the
   > full lifecycle management it needs, together with the SBOM work from plan 40. The
   > repository does not exist yet; creating it is a maintainer action. (Reconciled in
   > [Repository split](#repository-split), which suggests `datagen24/victual-authentik`
   > and lists what moves and what stays.)

2. **Does the no-shell check get a Python exception?** The image cannot pass
   `image-has-no-shell` as written. Options: a named Python exception like the MCP image's
   Node one, plus removing `bash` from the Python closure; or a separate, weaker check set
   for third-party images.

   > **Response:** datagen24, 2026-10-28: no shell is a nice-to-have where fully possible,
   > and it is not possible here. Record a named, documented exception for the Authentik
   > image. (Reconciled in [Image](#image): one more per-image closure check with `python3`
   > and `bash` subtracted from the shared list, which the existing machinery makes simpler
   > than a separate check set.)

3. **Is a public `ghcr.io` package acceptable** given the enterprise directory inside it, or
   is the Authentik image private, or built with `authentik/enterprise/` removed?

   > **Response:** datagen24, 2026-10-28: the target is a fully unified release of the core
   > application and everything it needs, built by us; not an external image whose contents
   > are unknown. Combined with the licence constraint, the reading recorded here is our
   > own Nix-built image, published with the unified release, with `authentik/enterprise/`
   > stripped at build time so it can be public. **The stripping part is proposed and needs
   > confirmation.** (Reconciled in [Licence](#licence), which records the feasibility
   > reading and the private-package fallback.)

4. **How fast must an Authentik advisory reach production?** If faster than "next release",
   the Authentik pin needs its own path, for example a patch-release exception or a
   scheduled check of upstream's advisories against `flake.lock`.

   > **Response:** datagen24, 2026-10-28: it can be faster. The release machinery needs to
   > allow rapid patch releases for Dependabot and other vulnerability findings. A patch
   > release carries no breaking API changes, only dependency changes or regression fixes.
   > (Reconciled in [Security-update lag](#security-update-lag): the patch-release
   > machinery is cross-cutting, builds on ADR-0047, and needs its own decision; this plan
   > depends on it.)

5. **Is losing FIPS and the bundled GeoIP databases acceptable?** GeoIP can come from the
   chart's `geoipupdate` sidecar with a MaxMind account; FIPS has no Nix equivalent here.

   > **Response:** datagen24, 2026-10-28: FIPS does not matter for a home inventory
   > package. GeoIP is nice to have; the build or setup scripts could fetch the database
   > during setup and load it. (Reconciled in [GeoIP](#geoip): optional, setup-time fetch
   > or the chart's sidecar.)

6. **Which outposts, if any?** The image needs none. If the proxy or LDAP outpost is wanted
   later, `gopkgs` is not in Cachix and must be built locally or in CI.

   > **Not answered yet.** The coordinating session recommends none: Victual is an OIDC
   > relying party (ADR-0056 decision 2 rejects the forward-auth proxy path), so the proxy,
   > LDAP, RADIUS and RAC outposts serve nothing in this stack. The design keeps `gopkgs`
   > out of the build pending the maintainer's confirmation.

7. **Does CI build the Authentik image on every `master` push**, or only when `flake.lock`'s
   `authentik-nix` entry changes? Its closure is roughly five times the PHP images'.

   > **Response:** datagen24, 2026-10-28: only when the lock changes. (Reconciled in
   > [Pinning and upgrades](#pinning-and-upgrades).)

## Verification

- `nix build .#image-authentik` on aarch64-linux in the builder and on x86_64-linux in CI;
  record image size and `nix path-info -rSh` of its roots, and compare with the upstream
  image of the same version.
- The spike's `run.sh` runs server and worker from the Nix image instead of
  `ghcr.io/goauthentik/server`, with `--read-only`, uid 65532 and only the declared
  writable mounts, and `setup.mjs` and `probe.mjs` produce the same case results as the
  2026.8.5 upstream run.
- From inside the running server and worker containers, writes to `/nix`, `/etc` and
  `/blueprints` are refused while `/dev/shm`, `/tmp` and `/data` succeed.
- A fresh database reaches a working admin login with only the Nix image's server and
  worker running, which settles whether migrations need their own step.
- `authentik healthcheck` succeeds as the liveness and readiness command in the upstream
  chart with the Nix image on kind.
- A blueprint shipped as a ConfigMap through the values file is applied by the worker, and
  removing it from the values file is reflected after the next reconcile.
- An upgrade rehearsal: move the pin from one 2026.8.x tag to the next and record the diff
  size, build time and probe results.
- `nix flake check` includes the Authentik image's non-root, no-shell (with `python3` and
  `bash` as its documented exception, and every other forbidden name still refused) and
  labels assertions.
- With `authentik/enterprise/` stripped: the server and worker start, all migrations apply
  to a fresh database, `GET /api/v3/schema/` answers, the swap-test probes give the same
  results as the unstripped image, and `grep -r authentik/enterprise` over the image's
  closure finds nothing. If any of these fails, the image is published as a private package
  instead.
- A CI run that changes nothing in the `authentik-nix` lock entry or the image derivation
  does not build the Authentik image; one that changes the entry does.
- The released image's SBOM and attestations verify with plan 40's documented command, and
  Victual's reference names that released tag.
- If GeoIP is enabled: an event recorded from a public address carries a country and ASN.

## Research

All read or measured 2026-10-28.

- nixpkgs version at Victual's pin: `nix eval github:NixOS/nixpkgs/a5fc1be5…#legacyPackages.aarch64-linux.authentik`
  in `victual-nix-builder-release` returned `2026.5.6`, maintainers `jvanbruegge`,
  `rissson`, licence `MIT`, platforms aarch64-linux and x86_64-linux. Branch heads:
  `gh api repos/NixOS/nixpkgs/contents/pkgs/by-name/au/authentik/package.nix?ref=<branch>`
  for `nixos-unstable`, `master` (2026.5.6) and `nixos-26.05` (2025.12.6).
- nixpkgs history: `gh api 'repos/NixOS/nixpkgs/commits?path=pkgs/by-name/au/authentik/package.nix&sha=nixos-unstable'`.
  Hydra job pages `nixos/unstable/nixpkgs.authentik.{aarch64,x86_64}-linux` show
  successful builds on most evaluations, and both architectures' builds of one evaluation six days earlier cancelled.
  `curl https://cache.nixos.org/<hash>.narinfo` for the pin's output paths
  (`d72ka9qj…` aarch64, `baad9l5s…` x86_64) returned 404.
- Upstream releases and advisories: `gh api repos/goauthentik/authentik/releases`,
  `gh api repos/goauthentik/authentik/security-advisories`. Upstream Dockerfile:
  `lifecycle/container/Dockerfile` at `version/2026.8.5`; entry script `lifecycle/ak`.
- Upstream image: `podman image inspect ghcr.io/goauthentik/server:2026.8.5` gave size
  1,392,101,580 bytes, arm64, user `1000`, entrypoint `dumb-init -- ak`.
- Community flake: `flake.nix`, `components/*.nix`, `module.nix`, `update-hashes.sh` and
  `README.md` at `main` (last pushed 2026-10-26); output paths from
  `nix eval github:nix-community/authentik-nix/version/2026.8.5#packages.<system>`;
  Cachix presence by `curl https://nix-community.cachix.org/<hash>.narinfo`.
- Dry runs: `nix build --dry-run` of `rust`, `gopkgs`, `pythonEnv`, `frontend`,
  `staticWorkdirDeps`, `migrate` and `manage` with
  `--option extra-substituters https://nix-community.cachix.org`, with and without
  `--override-input nixpkgs github:NixOS/nixpkgs/a5fc1be5…`.
- Closure size: a breadth-first walk of `nix path-info --json --store <cache>` over the
  roots' references, querying `nix-community.cachix.org` then `cache.nixos.org`, summing
  `narSize`. No paths were downloaded.
- Upstream chart: `charts/authentik/values.yaml` and `templates/{server,worker}/deployment.yaml`
  in `goauthentik/helm`.
- Licence: `LICENSE` and `authentik/enterprise/LICENSE` at `version/2026.8.5`.
