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

- **2026-10-08 — ADR-0038 prerequisites 1–3: the Helm chart lands** (branch
  `claude/opus5_adr38-helm-chart-8c96ee`, stacked on PR 679): `deploy/k3s/*.yaml` is now
  *generated* from `deploy/helm/victual/` by `.devtools/ci/render_k3s.py`. Never hand-edit
  it; edit the template and re-render, or `lint` fails. `deploy/production/` is gone. Byte
  comparison means the Helm version matters: CI pins v4.3.0 by checksum, the same as the
  workstation's. Bump both together. Gates 4–7 need kind, a release tag and the Talos cluster.
- **2026-10-05 — `stock_edited_entries` was quadratic in the whole ledger** (branch
  `claude/opus5_stock-edited-entries-quadratic-7c41e2`): every booking, undo and product
  details read paid for it; 48 s per purchase at 19,416 rows. Migration 0302 makes it linear
  (0.29 s), same rows; pgTAP 028 guards with a plan-work bound. An upgrade from 0288 still runs
  0292's reconcile under the old view, which needs a maintainer decision. Realistic ledgers come from
  `.devtools/pgsql/ledger-generator.php`; see [[reference_local_environment]] for running a
  coverage suite.
- **2026-09-30 — `run-tests.sh all` was red in the dev image, green in CI** (branch
  `claude/hopeful-mccarthy-3b6bb2`): six cases, one shape — a PHP diagnostic on a subprocess
  helper's **stdout**, in front of the JSON, so `json_decode()` gave `null`. One was PHP 8.5
  (`imagedestroy()`, vendored php-barcode, and #249 deleted that test hours later); five were the
  official `php:*-cli` image compiling
  PDO/pdo_sqlite/sqlite3/tokenizer *in*, which neither an 8.5 CI leg nor an 8.4 pin would catch.
  The `images` job now runs the suite in the image. See [[reference_local_environment]].
- **2026-09-21 — Three write routes stop discarding a caller's timestamp** (branch
  `claude/elegant-burnell-24f169`, [PR 235](https://github.com/datagen24/victual/pull/235),
  merged): `tracked_time` on chore execution and battery charge, `done_time` on task
  completion, all booked **now** whenever the value was not exactly `Y-m-d H:i:s`, answering
  200. [ADR-0028](../docs/adr/0028-a-timestamp-a-write-route-cannot-read-is-refused.md)
  (**Proposed**) refuses *and* widens. Five defects found in review, all from two mistakes:
  the accepted set written twice, and a test comparing the two by sampling. The durable
  part is [[feedback_one_definition_of_an_accepted_set]].
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
## DOCTRINE (operator-locked decisions)

- [Wire moves or document moves](feedback_wire_vs_document.md) — 2026-09-21, issues
  #229-#233: the eleven documented booleans move the wire. The 54 non-RFC-3339 `date-time`
  fields were reversed on 2026-10-04 and **also move the wire**, to RFC 3339 UTC over
  `TIMESTAMPTZ` (ADR-0027 decision 2). Measure from the contract snapshot before asking.


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
