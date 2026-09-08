---
name: Verification discipline
description: What counts as evidence in this repository — "it loads" does not, which suite answers which question, and why the parity suite is not a CI gate.
type: feedback
---

## "It loads" is not evidence

Stated 2026-08-26 while porting views to PostgreSQL. A view that loads without error says
almost nothing about correctness. `.devtools/pgsql/difftest.php` seeds SQLite (letting its
triggers fire), copies the resulting tables into PostgreSQL, and compares what each engine's
views return over the demo dataset. On its first run against 21 already-"working" views it
found three defects loading had not: a scalar subquery that raises a cardinality violation on
PostgreSQL whenever a product has more than one child; three `DATETIME` columns PostgreSQL
rendered as `"2026-08-26 00:00:00"` where SQLite returns `"2026-08-26"`; and an
identity-sequence resync that failed on the one table holding a negative id.

Two of those three were silent API-response changes, which is the one thing
[ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md) says this fork must not do.
Hand-written seeds were too thin to surface them; the demo dataset was not. Generate the
fixture by calling `DemoDataGeneratorService::PopulateDemoData()` directly with `GROCY_MODE=demo`
and `GROCY_LOCALE` defined — migrating alone does not populate it.

**Also: do not take a subagent's equivalence claim on trust.** The `FIRST_VALUE` →
`DISTINCT ON` substitution was justified, but only checking it against a deliberately
inconsistent conversion graph established that.

## Which suite answers which question

The two suites are easy to conflate and neither subsumes the other.

- **`.devtools/pgsql/`** asks *is this fork the same on SQLite as on PostgreSQL*, and answers
  it by driving SQL at both engines — comparing views and triggers, never entering a
  controller. It is a CI gate. So is `.github/workflows/nix.yml`.
- **`.devtools/parity/`** asks *is this fork the same as upstream 4.6.0*, over HTTP: ~285 API
  calls across eight scenarios, a browser walk over 49 view routes, and the fork-only
  MQTT/InfluxDB surfaces.

SQLite-flavoured SQL built in PHP at request time is **invisible to the pgsql suite by
construction**. Three such defects were live in master when the parity suite was built
(issues #44, #45): `IFNULL` at `services/StockService.php:500` and `:982`, and `COLLATE NOCASE`
passed as an `orderBy()` direction at `controllers/StockController.php:534` and neighbours.
The view port was careful; the PHP-level SQL was not audited to the same standard. Those call
sites are few enough to grep exhaustively —
`grep -rn 'IFNULL\|COLLATE NOCASE\|strftime\|julianday\|group_concat' controllers services helpers`.

## The parity suite is not a CI gate

Stated 2026-09-04: it is a tool to test as we go along so we don't introduce regressions.
There is no near-term plan to wire it into `.github/workflows/`.

**Why:** it is for the moment of changing a controller, a service or a view — run it then,
against the thing you touched, and read what it says. Making it a gate would change what it
optimises for (a green tick) away from what it is for (a readable answer). It also exits
non-zero against master today because its findings are real, so a gate would be red on
arrival.

**How to apply:** don't propose CI integration for it unless asked. When extending it, favour
what a person running it by hand needs — fast selective runs (`parity api --only stock`), a
stack that stays up between runs (`parity up` once, phases many times), reports that name the
step, the pointer and both values. A difference is either a defect or an entry in
`.devtools/parity/harness/lib/accepted.js` citing the record that decided it — never silently
dropped.

## Logging a done-claim

The Stop hook wants a fresh entry within 15 minutes of the claim:

```bash
python3 .claude/hooks/log_claim.py "<what you claim>" "<how you verified it>"
```

A usable verification string names the command and its result — "ran
`.devtools/pgsql/run-tests.sh` under podman, 14 phases, all passed" — not "checked the code".
The hook cannot tell the difference; the next session reading the log can. Writing a log entry
for a verification that did not happen defeats the whole harness, so don't.

See [[reference_local_environment]] for how to actually run each suite here.
