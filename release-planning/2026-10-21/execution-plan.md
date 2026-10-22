# 0.5.2 execution plan

The plan to take 0.5.2 from its six milestone issues to a tag. Assessments and decisions live
in [release-data.json](release-data.json) and render into the [dashboard](dashboard.md). This
file holds what the dashboard does not: the order of the work, who does each part, and how
the release closes.

## Gate: maintainer decisions first

The maintainer accepted the recommended answers to D1 to D8 on 2026-10-21. No code agent
starts on an issue without its decision. Each brief quotes the decision it implements.

## Order of the work

Shared files set the order:

- `mcp/package-lock.json` and `nix/hashes.nix`: PR 0 only.
- `helpers/StderrLogger.php` and `controllers/Api/BaseApiController.php`: #1026 lands before #912, because #912's logged cause uses the logger fallback that #1026 may remove.
- `victual.openapi.json` and the `ContractTest` snapshot: #912 (option a), #737 and #762 (option b only) take turns.
- `localization/strings.pot`: #762 lands before #1007.

| Wave | PR | Issue | Branch | Agent (model) | Validation (`run-tests.sh` phases unless noted) | Depends on |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0 | Dependabot #1030 to #1032 | `claude/v0-5-2-mcp-deps` | devops-engineer (sonnet) | `nix build .#image-mcp` with the new `mcpNpmDeps`; `mcp` npm tests; `flake` CI job | D8 |
| 1 | 1 | #1026 | `claude/v0-5-2-1026-fallbacks` | php-pro (sonnet) | helperunits, labelservices, labelapi on PHP 8.4 and 8.5 | D1 |
| 1 | 2 | #762 | `claude/v0-5-2-762-user-roles` | frontend-developer (sonnet) | householdpages, rbac, new users-list/create-with-roles probe, routes-smoke | D4 |
| 1 | 3 | #948 (merge-field copy only) | `claude/v0-5-2-948-field-descriptions` | frontend-developer (sonnet) | labelservices, `.devtools/frontend/label-designer.js` | D6 |
| 1 | 4 | #1035 empty-upload 415 | `claude/v0-5-2-files-415` | php-pro (sonnet) | files, storagefiles, httpboot, openapidocument, wirecontract | D7 |
| 2 | 5 | #912 | `claude/v0-5-2-912-json-throw` | php-pro (sonnet) | errors, wirecontract, contract, openapidocument, helperunits | PR 1, D2 |
| 2 | 6 | #1007 | `claude/v0-5-2-1007-medication-flyout` | frontend-developer (sonnet) | consumption, mealplan, consumption-* probes, routes-smoke | PR 2, D5 |
| 2 | 8 | #1043 PHP2Shell floor (P1) | `claude/v0-5-2-1043-php-floor` | devops-engineer (sonnet) | helperunits, httpboot, rootentry; `nix flake check`, image build, app image PHP 8.5.12 | D9 |
| 3 | 7 | #737 | `claude/v0-5-2-737-share-oracle` | php-pro (sonnet) | consumption, contract, wirecontract, openapidocument, openapisecurity | PR 5 (OpenAPI), D3 |
| Each | — | Review of every PR | — | code-reviewer (sonnet) | Reads the diff against the issue's decision; checks `gh pr checks` before ready | Its PR |

Research and status checks (refresh.py, CI reads, issue reads) use Haiku agents.

## Progress

- **Wave 1: merged 2026-10-21.** #1037 (`/mcp` bumps), #1038 (#948), #1039 (#1035), #1040 (#1026)
  and #1041 (#762). The planning PR #1036 merged with #1037. The planning files of every
  parallel PR conflicted at each merge, which led to decision D10.
- **Wave 2: merged 2026-10-21.** #1045 (#1043, PHP2Shell), #1046 (#912, D2 amended to roll back an
  in-transaction write on an encode failure) and #1048 (#1007). The wave-1 records PR #1044 merged too.
- **Wave 3: merged 2026-10-22.** #1050 and #1051 (#737, D3 extended to DELETE). Test image
  `victual:dev-1043` (PHP 8.5.12), because the new floor refuses the 8.5.10 image.
- **Close-out: in progress.**

## Machine limits

- At most five agents at once on the 4 CPU / 8 GiB podman VM. Wave 1 is five PRs, so the
  reviewer for wave 1 starts when its first PR is up.
- The pgsql suite runs one at a time, behind the lock directory, with each phase under
  `timeout`. Agents run podman directly, not `docker compose`.
- Each code agent works in its own worktree and branch from `master`. Wave 2 and 3 branches
  start from `master` after their dependency merges.

## What every code brief carries

- The issue, the maintainer's decision on it, and the files this plan names.
- Do not touch release-planning/. The coordinator records each wave in its own planning PR
  after the wave merges (decision D10). Docstring coverage at or above 80% for every function
  in every touched file, counted before the first push.
- The named phases, run on a fresh database, with results in the PR body.
- No push to `master`, no merge, no milestone or issue writes.

## Close-out

1. When every 0.5.2 issue is closed or moved, run `refresh.py`, then `render.py`.
2. Migrations: none are expected. If one is added, 0.5.2 needs an upgrade rehearsal from
   `v0.5.1` and a new restore drill, because gate R5's evidence holds only while the
   schema and deployment paths are unchanged. The MCP image bump changes neither.
3. Run `python3 .devtools/ci/release_version.py 0.5.2`. Write `docs/releases/0.5.2.md`:
   the effect of each change, the compatibility notes (#912's 500 body, #737's 422 to 204,
   the 415 on uploads), the checks that ran, the known limitations. Gate R6 stays unplaced.
4. Run all 56 phases of `run-tests.sh` on the release branch, then the CI helper checks
   that the 0.5.1 record lists.
5. Open the release PR. Read `gh pr checks` before calling it ready.
6. The release record gets a security section for #1043 (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp) and
   states that the v0.5.0 and v0.5.1 images run PHP 8.5.10.
7. The maintainer places the signed tag `v0.5.2` on the merge commit. `release.yml`
   publishes the images and the chart.

## GitHub writes

Made on 2026-10-21 and recorded in [release-data.json](release-data.json): #1033 and #1034
opened in 0.6.0 from #948, which keeps the merge-field descriptions under a new title; #1035
opened in 0.5.2 for the empty upload; a decision comment on each of the six issues.
#1030, #1031 and #1032 were closed in favour of PR #1037 once its `flake` job passed (D8).
