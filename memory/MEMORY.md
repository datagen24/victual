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

- **2026-09-19 — First release freeze review** (branch `claude/first-release-freeze-e0318a`,
  [PR 221](https://github.com/datagen24/victual/pull/221)): all nine open issues reviewed
  against the tree. #219 closed (every decision landed in #220). #217's second half fixed:
  `FileSizeLimit` no longer logs or memoizes, `ConfigurationValidator` announces the clamp
  under `PHP_SAPI === 'cli'` only; new phase `uploadclamp` boots the validator under `php`
  and `php-cgi` in a subprocess. What stays open is verification up a layer, not code:
  #133/#93 (K3S apply, SIGTERM on a cluster), #139 (Home Assistant), #86 §11.4 (the real
  client), plus backlogs #192, #209, #80. **Version identity is the freeze's one decision:**
  `version.json` still says upstream's `4.6.0`, and `nix/overlay.nix`, every deploy manifest
  and `deploy/kind/up.sh` derive the image tag from it. No git tag exists yet.
- **2026-09-19 — First full-stack year run against the MVP** (branch
  `claude/full-stack-1yr-test-a49f77`): `parity year` PASS, 14/14 invariants, 0 clock
  violations, ~23 min, after retiring the year's tare product (ADR-0022 removed product-level
  tare). `parity all` now exits **0** for the first time. New: the stack boots on a
  *generated* admin password and walks the forced change (`harness/bootstrap-admin.js`); a
  `parity mcp` phase drives the sidecar with the SDK v2 client against the REST GETs each tool
  wraps. Found and fixed: unknown-entity 500s (#218), the 32 MB upload clamp (#217, the
  per-request log line half still open), and login checks that accepted wrong passwords (a
  failed login is also a 302). Plan 19's permissions shape and #46's prices approved as
  differences (#219). See [[reference_local_environment]].
- **2026-09-19 — No more admin/admin** (branch `claude/opus5_bootstrap-admin-credential-435539`,
  CodeRabbit's finding on PR 211): `InitialDataSeeder` seeds `admin` from
  `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` (getenv, never a Setting) or a generated 24-hex password
  printed once to the migration's stderr and flagged `must_change_password`; login now only
  *raises* the flag. A flagged account gets 403 on every API route but `PUT /api/users/{own}`,
  `GET /api/user`, `GET /api/system/db-changed-time` — keys included. No migration: existing
  admin/admin installs are flagged at next login. `run-tests.sh bootstrapadmin`
  (`tests/Pgsql/BootstrapAdminTest.php`); run-tests.sh exports a suite bootstrap password so
  migrated schemas don't log generated ones. **walk.py now needs `--password`** (nix.yml reads
  the generated one from the migrate container); the parity stack uses
  `PARITY_VICTUAL_ADMIN_PASSWORD`. The kind half is PR 214.
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

## DOCTRINE (operator-locked decisions)

- [Wire moves or document moves](feedback_wire_vs_document.md) — 2026-09-21, issues
  #229-#233: the eleven documented booleans move the wire; the 54 non-RFC-3339 `date-time`
  fields move the document. Measure from the contract snapshot before asking.


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
