# CI change selection

Markdown-only changes (including ADR lifecycle bookkeeping) run the `lint` job in
`tests.yml`. The differential suite, frontend security probe, development image build,
and Psalm analysis skip them. Mixed changes run those checks; files other than `.md`
are conservatively treated as test inputs, including configuration, dependencies,
SQL, assets, and workflow definitions. The existing lint checks are PHP syntax,
runtime SQL portability, documented CI job references, and the classifier regression
tests; this does not introduce a Markdown style linter.

`changes.yml` shares the classifier across workflows. Pull requests compare the head
against its merge base with the target branch; pushes compare the before and after
commits. Full Git history avoids changed-file API limits. Rename detection is disabled
so renaming code into a Markdown file still counts as deleting code. An unavailable
comparison, a new branch, or a scheduled run requests all checks. Consumer jobs also
run if classification fails, so a failure cannot silently bypass the suite.

Nix retains its existing build-input path filters, excluding Markdown under `nix/`;
changes to its workflow or the shared classifier also trigger it. Its job uses the
same Markdown-only guard. Psalm's weekly scheduled scan remains unconditional.

Run the classifier regression checks locally:

```sh
python3 -m unittest discover -s .devtools/ci
```

The workflows retain their existing PR and master-push events. A merge still runs
checks for non-Markdown changes: it verifies the resulting master commit.

## ADR headers

The `lint` job validates added or modified `docs/adr/NNNN-*.md` files using the same
PR/push comparison. Unchanged ADRs, deleted files, and the ADR index are skipped.
If the comparison is unavailable, all existing ADRs are checked conservatively.

The check requires a first-line `# ADR-NNNN: <title>` matching the filename, exactly
one nonempty Status, Decider, and Recorded header, a recognized lifecycle status
(Proposed, Accepted, Rejected, or Superseded by ADR-NNNN), and a valid recorded date
in YYYY-MM-DD form. It accepts both existing bold-label conventions and multiline
metadata. Relationship and reference fields vary across existing records and remain
optional; this checks metadata, not Markdown style, index consistency, acceptance
prerequisites, or whether a lifecycle decision was authorized.

To check the entire corpus locally:

```sh
python3 .devtools/ci/check_adr_headers.py --all
```

## Deploy manifests

The `lint` job runs `check_deploy_manifest.py` against every `deploy/**/*.yaml`
document unconditionally, rather than folding it into the `nix` workflow's path
filter — the manifest is not a Nix build input, so gating it behind a full image
rebuild would answer a YAML-only change with a ten-minute job for no reason.

It checks [ADR-0010](../../docs/adr/0010-workload-standard.md)'s manifest-level
properties for every `Pod`, `Deployment`, `StatefulSet` and `DaemonSet` document: every
init and serving container sets `securityContext.readOnlyRootFilesystem: true`,
`allowPrivilegeEscalation: false` and drops the `ALL` capability, and declares
`resources.limits.memory`; every serving container also declares at least one of
`startupProbe`, `livenessProbe` or `readinessProbe`. `nix/checks.nix`'s
`image-runs-unprivileged` and `image-has-no-shell` already assert this property's
image-level half against the built artifact; this is the manifest's half, which
nothing checked before ADR-0010's acceptance review found the gap. It is deliberately
a structural check, not a linter — it does not assess whether the manifest is well
designed, only whether the required fields are present and correctly valued. Other
object kinds (`ConfigMap`, `Secret`, `Service`) are skipped rather than rejected.

To check it locally:

```sh
python3 .devtools/ci/check_deploy_manifest.py
```

The Helm chart's templates under `deploy/helm/*/templates/` are not YAML until Helm
renders them, so the glob leaves them out
([ADR-0038](../../docs/adr/0038-kubernetes-deployments-ship-as-a-helm-chart.md) decision 3).
`test_helm_chart.py` checks the renderings instead: one per values file in
`deploy/helm/victual/ci/`, each of which must pass, and one per file in `ci/negative/`,
each of which must fail. It also checks that the unedited `values.example.yaml` is refused
at install and that the schema refuses what `deploy/production/render.py` used to.

## The k3s base is the chart's rendering

`deploy/k3s/*.yaml` is generated: `render_k3s.py` runs `helm template` with
`deploy/helm/victual/ci/k3s-values.yaml` and `--no-hooks`, and writes one file per
template. `kustomization.yaml` there is hand-written. The `lint` job runs it with
`--check`, which fails with the diff when the committed files and the chart disagree, and
`test_helm_chart.py` fails on the same drift. Both need Helm; the job installs v4.3.0,
checksum-pinned, because the comparison is byte for byte. Without Helm,
`test_helm_chart.py` skips locally and fails under CI.

```sh
python3 .devtools/ci/render_k3s.py           # after editing the chart
python3 .devtools/ci/render_k3s.py --check
```

## Vendor paths in tests

The `lint` job runs `check_vendor_paths.py` over every `.php` file under `tests/` and
`.devtools/`. It refuses any reference to a path inside `packages/` other than
`packages/autoload.php` and `packages/bin/`.

`packages/` is Composer's vendor directory, it is gitignored, and CI fills it from dist
archives. Each package's `.gitattributes` decides what a dist archive holds, and
`export-ignore` on a package's own tests, docs and website is ordinary. A working copy
installed from source carries those directories and CI does not, so a test that reads one
passes locally and fails in CI with a missing file rather than with anything about the
behaviour under test.

That happened: two label tests took a real TrueType font from
php-di's `website/fonts/`, which is export-ignored, and both PostgreSQL suite jobs failed
on it. The font is now built by `tests/Support/SfntFixture.php`.

The check does not distinguish a file dist ships from one it does not. Reaching into a
dependency's file layout at all is the thing being refused; the autoloader and the binary
proxies are the interface Composer promises.

To check it locally:

```sh
python3 .devtools/ci/check_vendor_paths.py
```

## OpenAPI documents

`check_openapi_document.py` holds an OpenAPI document to the official OAS 3.2 structural
schema, vendored at `oas/3.2/schema-2025-09-17.json` and pinned by SHA-256 in the script.
It also fails on a local `$ref` that resolves to nothing, which the schema does not check,
and on an enum containing an empty string anywhere but `StringEnumTemplate`, the
placeholder the served enums are derived from. It needs `jsonschema`, pinned in
`tests.yml`. Example validation is not part of it.

The `lint` job's unit tests run it against the committed `victual.openapi.json` and
against negative-control documents in `fixtures/oas/`. The `suite` job runs it on the
served document, which `OpenApiDocumentTest` writes to `$SUITE_SCRATCH/served-openapi.json`
after the application derives it, and fails if that file is missing.

To check documents locally:

```sh
python3 .devtools/ci/check_openapi_document.py victual.openapi.json
```
