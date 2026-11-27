# 44. Keycloak image: licence review and amd64 build

Record the licence review that
[ADR-0055](../adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
acceptance prerequisite 3 asks for. Also record the amd64 build of the spike's Nix image and
the size options. Keycloak is the only token issuer (ADR-0055 question 26, and the Proposed
[ADR-0058](../adr/0058-upstream-identity-providers-federate-into-keycloak-and-provision-through-scim.md)).
The all-in-one values file turns it on with OAuth mode (ADR-0055 answer 27). Its image would
therefore be published to GHCR with the others under the Proposed
[ADR-0030](../adr/0030-released-images-are-published-to-ghcr.md). Publishing it is binary
redistribution of everything the image contains.

Status: draft, research only, 2026-11-27. No image, workflow or flake output is changed. ADR-0055
stays Proposed. The recommendations below need the maintainer's decision.

## Summary

- Keycloak 26.8.1 is Apache-2.0. Nothing in the image forbids redistribution. No component is
  under AGPL, SSPL or CDDL.
- The image holds 482 jars. 446 are under permissive licences only, and 17 more offer a
  permissive alternative. 16 are copyleft with no permissive option. Ten are Jakarta EE APIs
  under EPL-2.0 or GPL-2.0 with the Classpath exception. The others are Nashorn, a classfile
  backport, H2, MySQL Connector/J (GPL-2.0 with the Universal FOSS exception), the MariaDB
  client and the JBoss Transaction SPI (both LGPL-2.1). The last 3 are Quarkus build outputs.
- The JDK (GPL-2.0 with the Classpath exception), glibc, the GCC runtime libraries,
  libidn2, libunistring and the CA bundle (MPL-2.0) are also copyleft.
- All of these are used unmodified, as separate jars or dynamically linked libraries. The
  obligation they add is to make their source available alongside the image and to ship
  their licence texts. The image does neither for the Nix libraries.
- The image builds on amd64: 827 MB, the same as arm64. It runs as uid 65532 on a read-only
  root with two tmpfs mounts, and has no shell.
- [Plan 40](40-release-sboms.md) does not cover the Keycloak image. Its generators would
  also leave a third of the jars without a licence.
- A `jlink` runtime would cut the JDK from 568 MiB to 166 MiB. That estimate puts the image
  near 405 MB, below the upstream image's 478 MB on arm64.

## Measurement environment

Every measurement below was taken on 2026-11-27, against `master` at `e9c61d42`, on an x86_64
Linux cloud container with Nix 2.34.6 and Docker 29.9.3. The 2026-10-29 arm64 figures come
from the shared podman VM. The spike README's finding 26 records them.

The default `nix-build` fetches nixpkgs as a GitHub tarball, which this container's proxy
refused. The build used a git checkout of the same revision, `a5fc1be5`, instead. Its NAR hash,
`sha256-fmNFBoWUCUJdycc4kB+pMSXhnOOdeEDaxqfjzyyilK8=`, equals the pin in `image.nix`, so the
evaluated package set is the same:

```bash
git -C nixpkgs fetch --depth 1 origin a5fc1be5b6c2d0c934143b57363aef9a15cce508 && git -C nixpkgs checkout FETCH_HEAD
git -C nixpkgs archive HEAD | tar -x -C src && nix hash path src        # prints the pinned sha256
nix-build .devtools/spikes/oauth-keycloak/nix/image.nix --arg pkgs 'import ./src {}' -A image -o result
```

Where GitHub tarballs are reachable, `nix-build .devtools/spikes/oauth-keycloak/nix/image.nix -A image`
builds the same derivation. Two runtime store paths result:
`kb5c6iw4…-keycloak-26.8.1` and `8av96xmb…-openjdk-headless-21.0.12.1+1`.

## What the image contains

`nix-store -qR` over the two runtime paths lists twelve store paths. `streamLayeredImage` adds a
fake passwd and group, `nsswitch.conf` and the CA bundle.

| Store path | Size | Licence (source) |
|---|---|---|
| `keycloak-26.8.1` | 167.8 MiB | Apache-2.0 (`LICENSE.txt`; nixpkgs `meta.license`) |
| `openjdk-headless-21.0.12.1+1` | 568.1 MiB | GPL-2.0-only WITH Classpath-exception-2.0 (`lib/openjdk/legal/java.base/LICENSE` and `ASSEMBLY_EXCEPTION`). nixpkgs `meta.license` says GPL-2.0-only and omits the exception |
| `glibc-2.44-57` | 33.9 MiB | LGPL-2.1-or-later. nixpkgs says LGPL-2.0-or-later |
| `gcc-16.2.0-lib`, `gcc-16.2.0-libgcc`, `xgcc-16.2.0-libgcc` | 10.7 MiB | GPL-3.0-or-later WITH GCC-exception-3.1. nixpkgs says GPL-3.0-or-later. The JVM links `libgcc_s.so.1` and `libstdc++.so.6` |
| `libunistring-1.4.2` | 2.0 MiB | LGPL-3.0-or-later OR GPL-2.0-or-later. nixpkgs lists LGPL-3.0-or-later |
| `libidn2-2.3.8` | 0.4 MiB | LGPL-3.0-or-later OR GPL-2.0-or-later for the library. nixpkgs also lists GPL-3.0-or-later, for the command-line tool |
| `libjpeg-turbo-3.2.0` | 2.3 MiB | IJG AND BSD-3-Clause AND Zlib. nixpkgs lists IJG |
| `lcms2-2.19.1` | 0.5 MiB | MIT |
| `zlib-1.3.2` | 0.1 MiB | Zlib |
| `nss-cacert-3.131.1` | image layer | MPL-2.0 |
| `set-java-classpath-hook`, fake NSS files | under 1 KiB each | nixpkgs, MIT |

The nixpkgs `meta.license` values were read with
`nix-instantiate --eval -E '(import ./src {}).<attr>.meta.license'`. Where a library's own
licence files say more, the table gives those files' terms and the nixpkgs value after them.
None of these Nix store paths carries its licence text into the image.

### Keycloak's jars

`lib/` holds 482 jars:

| Directory | Jars | What it is |
|---|---|---|
| `lib/lib/main` | 354 | Runtime classpath |
| `lib/lib/deployment` | 106 | Quarkus build-time extensions, used by `kc.sh build` |
| `lib/lib/boot` | 18 | Bootstrap classpath |
| `lib/app`, `lib/quarkus`, `lib/quarkus-run.jar` | 4 | `keycloak.jar`, the runner and two generated bytecode jars |

The list matches the release zip that nixpkgs fetches, `keycloak-26.8.1.zip`
(`sha256-OR/BHY56y0htVDiFYk6RcPqYu2RdH26ShC7+20G0Jo0=`), minus the four jars of
`bin/client/` (the admin CLI). `image.nix` deletes `bin/`. Compare with
`find . -name '*.jar' | sort` in both trees.

Quarkus names each jar `<groupId>.<artifactId>-<version>.jar`. Most jars carry no licence of
their own, so the scripts in `.devtools/spikes/oauth-keycloak/licences/` take it from the
following sources, in order:

1. The jar's embedded `META-INF/maven/**/pom.xml` `<licenses>`: 71 jars.
2. Its manifest's `Bundle-License`: 203 jars.
3. The artifact's POM on Maven Central, then its parent POMs until one declares a licence:
   204 jars. For 147 of them the licence comes from a parent POM, in each case the
   project's own parent (`keycloak-parent`, `wildfly-elytron-parent`, the SmallRye and
   Infinispan parents and others).
4. A recorded override: 1 jar. `jboss-transaction-spi` 8.0.0.Final declares no licence and
   inherits "Public Domain" from `org.jboss:jboss-parent:39`, an organisation-wide parent.
   Its sources are LGPL-2.1-or-later: the `LICENSE` and every `src/main` header at tag
   `8.0.0.Final` of `github.com/jbosstm/jboss-transaction-spi`, commit `5aec0bfb`, which is
   the jar's `Scm-Revision`. `resolve.py` reports a licence inherited from `jboss-parent`
   as unresolved unless an override records the artifact's own.

The three Quarkus build outputs have no artifact of their own. `quarkus-run.jar` is the
runner from the release build, and `generated-bytecode.jar` and `transformed-bytecode.jar`
are written from the jars above. All 482 jars resolve: 479 to a licence and 3 to build
output. The scripts map the strings to SPDX identifiers and write one row per jar to
[`keycloak-26.8.1-jars.tsv`](../../.devtools/spikes/oauth-keycloak/licences/keycloak-26.8.1-jars.tsv):

```bash
cd .devtools/spikes/oauth-keycloak/licences
KC=$(nix-build ../nix/image.nix -A keycloak --no-out-link)
python3 -I jarlic.py "$KC" > /tmp/jars.tsv            # in-jar evidence
python3 -I resolve.py /tmp/jars.tsv > /tmp/resolved.tsv  # Maven Central for the rest (about 40 s)
python3 -I group.py /tmp/resolved.tsv keycloak-26.8.1-jars.tsv
```

The path column identifies each jar. The Maven coordinate columns are empty for 16 rows: the
3 build outputs, and 13 jars that embed no POM and whose licence came from the manifest.

A jar with several licences lists alternatives. Every multi-licence jar in 26.8.1 is dual or
triple licensed. `group.py` states that assumption so that a later release's flagged rows are
checked again.

### Licences of the jars, grouped

| Licence | Jars | Examples |
|---|---|---|
| Apache-2.0 | 415 | Keycloak, Quarkus, Infinispan, JGroups, Hibernate ORM, Narayana, Netty, Jackson, Vert.x (some), gRPC, Guava, FreeMarker, OpenTelemetry |
| BSD-3-Clause | 17 | ASM, ANTLR, protobuf-java, Jakarta Activation and XML Binding (EDL-1.0, which SPDX lists as BSD-3-Clause) |
| MIT, MIT-0 | 8 | Bouncy Castle (its licence is the MIT text), dbus-java, mssql-jdbc, SLF4J, Reactive Streams (MIT-0) |
| BSD-2-Clause, CC0-1.0 or a choice of the two | 4 | PostgreSQL JDBC, CRaC, HdrHistogram, LatencyUtils |
| UPL-1.0 | 2 | GraalVM SDK `nativeimage` and `word`, in `deployment/` |
| A choice that includes a permissive licence | 17 | Vert.x (Apache-2.0 or EPL), JNA (Apache-2.0 or LGPL-2.1), Jakarta Persistence and JTS (EDL or EPL-2.0), Jakarta Mail and Angus Mail (EDL, EPL-2.0 or GPL-2.0 with CPE) |
| Copyleft only | 16 | Listed in the next table |
| Build output | 3 | `quarkus-run.jar` and the two generated bytecode jars |

Copyleft-only jars, with what redistributing them unmodified obliges:

| Jar | Licence | Obligation on binary redistribution |
|---|---|---|
| `jakarta.annotation-api` 3.0.0, `jakarta.el-api` 6.0.1, `jakarta.interceptor-api` 2.2.0, `jakarta.json-api` 2.1.3, `jakarta.resource-api` 2.1.0, `jakarta.servlet-api` 6.0.0 (`deployment/`), `jakarta.transaction-api` 2.0.1, `jakarta.ws.rs-api` 3.1.0, `org.eclipse.parsson` 1.1.9, `org.glassfish.expressly` 6.0.0 | EPL-2.0 OR GPL-2.0-only WITH Classpath-exception-2.0 | Choose EPL-2.0. Section 3 requires telling recipients that the source is available and how to get it, and keeping the EPL as the licence of that code. Other code that only uses these jars is not affected |
| `nashorn-core` 15.4, `jdk-classfile-backport` 26 (`deployment/`) | GPL-2.0-only WITH Classpath-exception-2.0 | GPL-2.0 section 3: ship the source or a written offer, or offer the source from the same place as the binary. The exception keeps the GPL from reaching code that links them |
| `h2` 2.4.240 | MPL-2.0 OR EPL-1.0 | Choose MPL-2.0. Section 3.2: make the source of H2's files available and tell recipients how. File-level, so nothing else is affected |
| `mysql-connector-j` 9.7.0 | GPL-2.0-only WITH Universal-FOSS-exception-1.0 | GPL-2.0 section 3 source obligation for the connector. The exception lets it combine with Keycloak's Apache-2.0 code |
| `mariadb-java-client` 3.5.10, `jboss-transaction-spi` 8.0.0.Final | LGPL-2.1-or-later | LGPL-2.1 section 4: accompany the library's object code with its source, or offer the source from the same place. Section 6 also requires that a user can replace the library, which a separate jar on the classpath allows |

None of these jars is modified, so no change of Victual's or Keycloak's licence follows. With
`db=postgres`, the MySQL, MariaDB, SQL Server and H2 drivers sit in `lib/lib/main` unused.

## NOTICE and attribution

Apache-2.0 section 4 requires a copy of the licence with each redistribution. Section 4(d)
adds one more condition: if a work includes a `NOTICE` file, redistribution must carry its
attribution notices in one of a stated set of places. A `NOTICE` file shipped as part of the
distributed work is one of them.

What the image carries:

- Keycloak's `LICENSE.txt` (Apache-2.0, SHA-256 `cfc7749b…`), at the store path's root. The
  Keycloak distribution has no `NOTICE` file.
- The JDK's `lib/openjdk/legal/`: 69 module directories with `LICENSE`, `ASSEMBLY_EXCEPTION`,
  `ADDITIONAL_LICENSE_INFO` and 29 distinct third-party notices (ICU, Unicode, CLDR, ASM,
  Xerces, HarfBuzz and others).
- Inside the jars: 222 carry a licence file and 54 a `NOTICE` under `META-INF/`. They travel
  unmodified inside the jars, which meets 4(d) literally. No aggregated file exists, so
  checking it means opening every jar.
- Nothing for the Nix libraries (glibc, the GCC runtime, libidn2, libunistring, zlib, lcms2,
  libjpeg-turbo, the CA bundle). GPL, LGPL, MPL, zlib and IJG terms each require their
  licence text or notice with a binary copy.

The OCI label `org.opencontainers.image.licenses` reads `Apache-2.0`. The OCI annotation
specification defines that label as the licences of the contained software, as an SPDX
expression, so `Apache-2.0` alone understates it.

The same gaps hold for the six images already published. Every Nix image carries glibc and
the GCC runtime libraries, and none ships their licence texts or source. So Keycloak brings
the gap into view but does not create it. A fix belongs in the shared image builder
(`nix/images/lib.nix`), not in a Keycloak-only patch.

## SBOM coverage (plan 40)

[Plan 40](40-release-sboms.md) does not cover this image. Its table of what a release ships
lists six images, and its verification criteria name "each of the six images". The image is
not yet in `release.yml` or the flake, so that is consistent. Plan 40 says SBOMs are
published "for every image a release ships". Once the Keycloak image is released, that rule
brings it into scope.

Plan 40 would then meet two gaps. Each was measured with Syft 1.54.0 from the pinned nixpkgs
(`nix-build ./src -A syft`), against the amd64 image tar:

```bash
syft scan docker-archive:image.tar -o syft-json=syft.json
syft scan docker-archive:image.tar --enrich java -o syft-json=syft-enriched.json
```

- Syft finds 496 `java-archive` packages: the 482 jars, `jrt-fs.jar`, and nested
  entries. Offline, 195 have no licence. With `--enrich java`, which queries Maven Central,
  156 still have none. The 12 Nix packages and the `openjdk` binary have no licence either
  way.
- Syft's licence for `mysql-connector-j` is `Apache-2.0, BSD-3-Clause, EPL-1.0, GPL-2.0,
  LGPL-2.1`. That list comes from the third-party section of its bundled `LICENSE`. The
  connector's own POM states GPL-2.0 with the Universal FOSS exception. An SBOM licence field
  from Syft is therefore not a licence review.
- bombon, plan 40's closure generator, reads vendored ecosystems through passthru helpers for
  Cargo, npm, pnpm and Go (plan 40's tool table). It has no Maven helper. The closure SBOM
  would list `keycloak-26.8.1` as one Apache-2.0 package and none of its jars.

Plan 40 needs three additions for this image: a row in its image table, Maven as a vendored
ecosystem, and a licence source for jars. The inventory TSV in this plan is one such source.
Turning it into a CycloneDX fragment that bombon or the release workflow merges is plan 40
design work, not done here.

## amd64 build

The amd64 build ran on 2026-11-27, with the commands in
[Measurement environment](#measurement-environment). `nix-build` took 42 s: everything but
the Keycloak derivation came from `cache.nixos.org`, and `kc.sh build` ran locally.

| Measurement | amd64, 2026-11-27 | arm64, 2026-10-29 |
|---|---|---|
| Stream size (`./result > image.tar`) | 826,818,560 bytes | 827 MB |
| `docker image ls` content size | 827 MB, 17 layers | — |
| Image `Architecture`, `User` | `amd64`, `65532:65532` | `arm64`, `65532:65532` |
| Runtime closure | Keycloak 167.8 MiB, JDK 568.1 MiB (618.0 MiB with its closure) | 168 MiB, 618 MiB |

The run used Docker, not podman, because this container has no podman. It was:

```bash
docker run -d --read-only --tmpfs /tmp:rw,mode=1777 --tmpfs /var/lib/keycloak/data:rw,mode=1777 \
  -e KC_DB_URL=jdbc:postgresql://kc-pg:5432/keycloak_nix -e KC_DB_USERNAME=keycloak -e KC_DB_PASSWORD=… \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=… \
  -e KC_HTTP_ENABLED=true -e KC_HOSTNAME=http://localhost:8180 localhost/victual-spike-keycloak-nix:26.8.1
```

The PostgreSQL 16 container was on the same Docker network. The results:

- `docker inspect` showed `ReadonlyRootfs=true`. `docker top` showed PID 1, `java`, as uid
  65532.
- `docker exec … sh`, `… /bin/sh` and `… bash` each failed with "executable file not found"
  or "no such file or directory".
- Quarkus reported "started in 4.233s". `/health/ready` reached `UP` once the first-boot
  schema initialisation finished. The master realm's discovery document returned 200, and an
  `admin-cli` password grant returned a Bearer token.
- Memory was 552 MiB after that one grant.
- The log showed no write failure on the read-only root.

`setup.mjs` and `probe.mjs` did not run on amd64, because `run.sh` drives podman. The
2026-10-29 verdicts against this image are therefore arm64 evidence only.

## Size

The JDK is 568 MiB of the 827 MB. nixpkgs' `jre_headless` is a full headless JDK. Its largest
parts are `jmods` (310 MiB), `lib/modules` (136 MiB), `lib/server` (55 MiB) and `src.zip`
(52 MiB). A runtime needs neither `jmods` nor `src.zip`.

`jlink` over all 69 modules of the same JDK, with `--strip-debug --no-man-pages
--no-header-files`, gave a 166 MiB runtime, 402 MiB less. That puts the image near 405 MB,
against the upstream image's 478 MB (arm64, 2026-10-25). The estimate was not built as an image or run with
Keycloak. A narrower module list, from `jdeps` over `lib/lib/main`, would cut further.
Keycloak's reflection and service loading make a narrow list a tested change, not a
calculation.

nixpkgs builds such a runtime already: `jre_minimal`
(`pkgs/development/compilers/openjdk/jre.nix`) runs `jlink` with a given module list, so the
change to `image.nix` is one attribute. `jlink` copies `legal/` for the modules it includes:
the 69-module runtime still has 69 directories there.

Two smaller savings follow the licence review. Dropping the four unused JDBC drivers saves
7.3 MiB (MySQL 2.5, H2 2.6, SQL Server 1.5, MariaDB 0.8). `lib/lib/deployment`, 19 MiB, is
used only by `kc.sh build`, which has already run. Neither saving was measured as an image.

## Recommendations

1. **Accept Keycloak's licence for redistribution.** Keycloak is Apache-2.0. The jars and
   libraries the image adds are permissive, dual-licensed with a permissive option, or
   copyleft under terms that permit unmodified redistribution beside Apache-2.0 code.
2. **Delete the unused MySQL and MariaDB drivers in the image derivation.** MySQL Connector/J
   is GPL-2.0 with the Universal FOSS exception and the MariaDB client is LGPL-2.1, and the
   PostgreSQL deployment loads neither. The other LGPL jar, `jboss-transaction-spi`, is part
   of Narayana's transaction manager and stays. A smoke test passed on 2026-11-27: with both jars removed from a copy of the
   Keycloak tree, mounted over the store path, Keycloak started, reached ready and issued an
   admin token. The full probe was not run. Removing H2 and mssql-jdbc too is optional. H2
   backs only `start-dev`'s `dev-file` database. mssql-jdbc is MIT.
3. **Ship licence texts in the image.** Add a directory such as `/share/licenses` with
   Keycloak's `LICENSE.txt`, a `NOTICE` aggregated from the jars' `META-INF/NOTICE` files,
   and each Nix store path's licence files. Build it in `nix/images/lib.nix`, so the six
   existing images gain it too.
4. **Publish corresponding source with each release.** GPL-2.0 section 3 allows "equivalent
   access to copy the source code from the same place". A release asset or OCI artifact,
   built by Nix from the derivations' `src` attributes, would provide that for the JDK,
   glibc, the GCC runtime and the copyleft jars. It applies equally to the six existing
   images, so it needs its own decision. ADR-0030's scope is where to publish, not what.
5. **Correct the OCI licences label** to an SPDX expression for the main components, or point
   it at the SBOM. Plan 40's release workflow is where the SBOM link would come from.
6. **Use a `jlink` runtime** (`jre_minimal`) for the production image, recorded as a
   recommendation only in [Size](#size). Measure the module list against the core probe on both
   architectures before relying on it.
7. **Extend plan 40** with the Keycloak image, a Maven ecosystem and a jar licence source
   ([SBOM coverage](#sbom-coverage-plan-40)).

## Open questions

1. **Does recommendation 4 (source with each release) block the first Keycloak image on
   GHCR?** The six existing images have the same gap. Closing it for Keycloak alone would be
   inconsistent, and closing it for all seven is work outside this plan.
2. **Should the drivers be removed (recommendation 2) before the probe runs again on the
   trimmed image?** The smoke test covers start-up and one token grant only.

## Verification

- Prerequisite 3's image half is met when a Nix-built image runs on each architecture as a
  non-root user on a read-only root with no shell. amd64 has that evidence
  ([amd64 build](#amd64-build)), and arm64 has finding 26 of the spike report.
- The licence half is met when this review is recorded, which this plan does, and the
  maintainer decides recommendations 2 to 5.
- A later Keycloak release repeats the inventory with the three scripts. A new copyleft-only
  row in `group.py`'s output needs review before the image is published.
