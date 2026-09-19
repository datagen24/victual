# Memory Index

> Auto-loaded at session start by `.claude/hooks/auto_orient.py`. The injector truncates at
> **8000 bytes** (`AUTO_ORIENT_MAX_BYTES`), at a line boundary — so this file stays short and
> keeps detail in the linked topic files. Everything below the cap is silently dropped.

## What memory is for here

This repository already has a documentation corpus that is the authority on the project:
[AGENTS.md](../AGENTS.md) for ground rules, [docs/constitution.md](../docs/constitution.md)
for standing principles, [docs/adr/README.md](../docs/adr/README.md) for decisions in force,
[docs/plans/README.md](../docs/plans/README.md) for what work exists and what gates it.

**Memory does not restate any of that** — it would drift, and the corpus would still be
right. Memory carries the three things the corpus does not: how this machine actually runs
the suites, what verification counts as evidence, and where the last session left off.

When memory and the corpus disagree, the corpus wins and the memory file is wrong: fix it.

## Verification protocol (the Stop hook enforces this)

`.claude/hooks/claim_check_hook.py` blocks or warns at turn end when a reply claims
done / shipped / verified / fixed / complete without a fresh verification entry. After you
actually verify something, log it:

```bash
python3 .claude/hooks/log_claim.py "<what you claim>" "<how you verified it>"
```

The entry must name a command that ran and what it returned. See
[feedback_verification_discipline.md](feedback_verification_discipline.md) for what counts.

## File conventions

Memory files live in this directory, prefix-typed:

| Prefix | Contents | Lifetime |
|---|---|---|
| `project_*` | Active work, session logs, in-flight state | Days to months |
| `feedback_*` | Operator decisions, locked doctrine, lessons learned | Long-lived |
| `reference_*` | Canonical patterns, playbooks, how-to | Long-lived |
| `user_*` | Operator identity, preferences, focus | Long-lived |

Frontmatter on every file:

```markdown
---
name: <short title>
description: <one line, used to decide relevance in a future session>
type: <project | feedback | reference | user>
---
```

Bodies of `feedback_*` and `project_*` files carry **Why** and **How to apply** lines, and
link related files as `[[name]]`. Facts that were measured name the date and how to
reproduce them, as [docs/documentation.md](../docs/documentation.md) requires of records.

## RECENT SESSIONS

<newest first; keep five. Concurrent branches both add a line here — on conflict keep both.>

- **2026-09-19 — Issue #208 Victual-side MCP auth** (branch `claude/issue-208-mcp-auth`):
  `API_KEY_TYPE_MCP`, `api_keys.read_only` (**0287** — plan 22's unwritten claims moved to
  0288–0289), the read-only 403 in `BaseAuthMiddleware` (plus a named list of upstream GET
  routes that write), a `VICTUAL-API-KEY-TYPE` header that narrows the lookup, and
  `GET /api/user/capabilities`. Also fixed `ApiKeyIsReadable()`, which would have shown an MCP
  key's hash. New phase `mcpauth`; `tests/Pgsql/request-subprocess-helper.php` sends any
  request through the full stack. Full suite green. Sidecar side (send the header) is on
  #86's branch. See [[project_issue86_mcp_sidecar]].
- **2026-09-19 — Issue #86 sidecar built, deployed to kind** (branch
  `claude/issue-86-kubernetes-deploy-cc575a`): six tools implemented on the real SDK v2
  (`@modelcontextprotocol/server`+`/node` 2.0.0 — the scaffold's `sdk ^2.0.0` did not
  exist), 15 node:test tests, `.#image-mcp` built shell-free, and `deploy/k3s` applied to a
  real cluster for the first time via `deploy/kind/up.sh`. Found: `stopSignal` dropped on
  k8s 1.37. Next: #208 (capabilities endpoint, MCP key type, read_only). Detail and gotchas:
  [[project_issue86_mcp_sidecar]].
- **2026-09-19 — Issue #86 framework merged, in-repo, unbuilt** (PR
  [207](https://github.com/datagen24/victual/pull/207), branch
  `claude/cool-faraday-mx372b`): `mcp/` (Zod schemas for all six §5 tools, handlers
  unimplemented) plus a fourth Nix image (`.#image-mcp`, `nix/mcp.nix`) — reversing the
  interface spec's Open Question 1 ("new repo") per ADR-0013's precedent, after
  `create_repository` for `datagen24/victual-mcp` hit `403` (no repo-creation scope on
  the GitHub App). CodeRabbit's review found one real self-inflicted CI break (a
  premature `checks.nix` entry forced `nix flake check` to build the still-unbuildable
  `mcp` package) and one real Nix bug (`sourceRoot` missing), both fixed before merge;
  an HTTPS-enforcement suggestion was declined in writing as contradicting spec §8/§9.
  Nothing built or run — no Nix/npm in the sandbox. Full detail and the ordered
  next-steps list: [[project_issue86_mcp_sidecar]].
- **2026-09-18 — Plan 20 / issue #133** (branch `claude/issue-133-f49546`): credential split
  done — `deploy/postgres/roles.sql` (`victual_migrate` owns the schema, `victual_app` is DML
  only), a Secret per workload in both pod manifests. The first run of the restricted role could
  not connect: `PostgresDialect::OnConnected()` ran `CREATE TABLE IF NOT EXISTS` on every
  connection and PG checks schema CREATE before existence; now `to_regclass()` first.
  `tests/Pgsql/CredentialSplitTest.php` (`run-tests.sh credentialsplit`) holds it, verified by
  reverting the fix. `zip` and `xmlwriter` trimmed from `nix/php.nix` (listed on callers that
  do not exist); simplexml/openssl/dom/curl kept, each with a measured or sourced reason.
  `.devtools/nix/walk.py` walks every page + API + a write cycle over HTTP and is now a step in
  the `nix` workflow. **Found, not fixed** (spawned as tasks): `GET /` and `/mealplan` 500 on
  master. **Not done, needs a cluster**: `deploy/k3s/victual.yaml` is validated structurally
  only (rootless podman cannot host k3s: no cpuset cgroup v2); plan 25's verification 12 and
  #93 stay open. SIGTERM half of check 9 measured on podman: php-fpm resets a DB-blocked
  request on SIGQUIT too. Piece 3's issue text was stale (boot test has been on the Nix images
  since 09-04). Warm nix builder: `podman commit victual-nix-builder` then
  `BUILDER=… NIX_IMAGE=… nix/build-in-podman.sh` (existing builder is bound to another
  worktree). Local `master` was stale; branch from `origin/master`.
- **2026-09-18 — Plan 05 parts A/C landed**, issue #85: `migrations/0286.pgsql.sql` —
  three nullable columns (`shopping_lists.shopping_location_id`,
  `products.default_shopping_list_id`, `recipes.default_shopping_list_id`), no defaults,
  no foreign keys, PostgreSQL-only above the freeze exactly as the plan specified.
  **Deliberately did NOT re-issue `products_view`/`shopping_lists_view`**: both flatten
  `p.*`/`sl.*` at `CREATE VIEW` time, and migration 0276 already hit and documented the
  failure mode (`CREATE OR REPLACE VIEW` refuses to reposition an existing output column) —
  the generic API is unaffected either way since `GenericEntityApiController` reads the
  base tables directly. Added `default_shopping_list_id` to the `Product`/
  `ProductWithoutUserfields` OpenAPI schemas (no schema exists for `recipes`/
  `shopping_lists` to extend) and regenerated `tests/Pgsql/snapshots/contract-{admin,
  restricted}.json` per ADR-0024 decision 1 — diff is exactly the three new fields,
  identical on both sweeps (no sensitive-vocabulary match). New tier-1 test
  `tests/Pgsql/ShoppingListStoresTest.php` (`run-tests.sh shopliststores`, wired into
  `phpunit.xml` and the `all` target) round-trips all three columns through the real API
  and pins the view non-reissue as a test, not just a comment. Verified against real
  PostgreSQL 16.13 in podman (`docker build --target dev`, stock `postgres:16` +
  `apt-get install postgresql-16-pgtap`): `run-tests.sh all` (25 phases,
  `SUITE_ALLOW_RESERVED_HOLES=1` for still-unwritten 0284/0285) ends `SUITE PASSED`. Next
  unclaimed migration: 0287. Wave 5 remaining: 20's pieces (#133), then 02 (#86), then 18's
  HA checks (#139).
- **2026-09-18 — Wave 5 order set**: 05 A/C (0286 claimed, snapshot regenerates with it)
  and 20's remaining pieces (#133) first because they change responses and deployment;
  then 02 (#86); then 18's HA checks (#139). Found 14 piece 2 had landed 2026-09-17
  (`fb97824`, `ContractTest.php`) with #83 still open and plan 14's status line stale —
  closed and fixed. Next unclaimed migration (at the time): 0287, since claimed and landed
  by the entry above.


## REFERENCE PATTERNS

- [Local environment](reference_local_environment.md) — running the three suites on this
  Apple Silicon Mac: podman directly (the documented `docker compose` invocation is broken
  here), the frontend probes' yarn/Playwright prerequisites, the Nix build, git signing.
- [DEVONthink index](reference_devonthink_index.md) — `~/src/grocy` is indexed into the
  `Code-Projects` database, so full-text and proximity search spans the code and the docs
  corpus at once. It indexes **master's tree only, never a worktree** — find things with it,
  verify nothing with it.

## USER PROFILE

- [Operator profile](user_profile.md) — datagen24, sole maintainer; BLUF replies; the
  deployment target and the machine the work happens on.

## ACTIVE PROJECT STATE

- [Project state](project_state.md) — pointer to the authorities plus what is in flight
  that no plan row states yet.

## Archive

When this index passes ~150 lines, move superseded entries to
`archive/MEMORY_pre_consolidation_YYYYMMDD.md` and leave a pointer here.
