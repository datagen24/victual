# ADR-0001: PostgreSQL is supported alongside SQLite

- **Status:** **Superseded by [ADR-0008](0008-postgresql-only-runtime-engine.md)**,
  2026-08-31. Originally Accepted; the original date is not recorded — the decision
  landed with the PostgreSQL support work, which the roadmap lists as **landed**.
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively. The decision was made when the work was done;
  this file only gives it a citable name.
- **Referenced by:** every plan that touches schema; [10](../plans/landed/10-cold-start-statelessness.md),
  [01](../plans/landed/01-file-storage.md), [07](../plans/retired/07-nested-products.md),
  [08](../plans/landed/08-nested-locations.md) most directly.

## Context

Upstream grocy stores its data in a SQLite file. This fork's deployment target is
immutable, scale-to-zero pods on k3s, where a database file on a writable volume is the
thing standing between the deployment and having no volume at all.

## Decision

Support PostgreSQL as a first-class engine selected by `DB_DRIVER`, with SQLite retained
and equally supported. Prove the two agree rather than asserting it: a differential test
suite migrates, seeds and compares both engines table by table and view by view.

## Consequences

- A fresh, empty PostgreSQL database is a valid target, and an existing SQLite
  installation can be moved across with `bin/victual-db-import`, preserving row ids.
- **Every schema change is now two changes**, or one portable change proved to work as
  both. The porting rules and the seventeen hazards in
  [db/pgsql/README.md](../../db/pgsql/README.md) are the standing cost of this decision.
- SQLite became, incidentally, the fork's **reference implementation** — the differential
  suite is what turns "PostgreSQL still behaves like grocy" from a claim into a test. That
  side effect was not the point of this decision but is now load-bearing, and it is the
  main thing [ADR-0008](0008-postgresql-only-runtime-engine.md) has to answer for.
- Both `pdo_sqlite` and `pdo_pgsql` are required regardless of driver, and
  `PrerequisiteChecker` opens a throwaway SQLite connection on every request to check its
  version. Making that conditional is [plan 10](../plans/landed/10-cold-start-statelessness.md).

## Source

[db/pgsql/README.md](../../db/pgsql/README.md) is the authority on how, and remains so.
This record exists to name the *decision* so later ADRs can supersede it.
