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
