---
name: Operator profile
description: Who the maintainer is, how they want to be answered, and the machine and deployment target the work assumes.
type: user
---

**datagen24 (Steven Peterson), sole maintainer.** The constitution states it as governance:
one maintainer, accountable for the tree, deciding on the merits. Anyone is welcome to run
the fork, and the records are written for someone who does — but nothing is adopted by
momentum or by not being argued with.

**Answer style is specified, not guessed at.** [AGENTS.md](../AGENTS.md) carries it in full
under "Tone and response style" and "Compression" — read it there rather than from a summary.
The load-bearing parts: bottom line up front, always; no validation-as-substance and no
reflexive agreement; plain language over quotable phrasing; cut ceremony, not reasoning; no
tool-call narration; quote the shortest decisive line with `path:line` instead of dumping
files or logs. Security warnings, destructive-action confirmations and ordered multi-step
instructions are explicitly exempt from compression — those get full prose.

**Machine:** Apple Silicon Mac. `docker` is podman's CLI shim, and the x86
`docker-compose` binary behind it does not run — so every documented `docker compose`
invocation needs the podman equivalent instead. Commits are SSH-signed through 1Password's
agent. See [[reference_local_environment]].

**Deployment target:** a non-persistent container on k3s, alongside several other
PostgreSQL-backed apps with backups handled centrally. This is why statelessness matters
more than the backup convenience the PostgreSQL work was originally motivated by: nothing
may depend on a persistent volume surviving. It is also why
[ADR-0007](../docs/adr/0007-auth-state-outlives-the-process.md) and plan 10 exist.

**Fork scope:** nine goals, taken up 2026-08-25 after upstream declined them — API
compatibility for the existing iOS app and Home Assistant integrations (a hard constraint:
additive only), PostgreSQL, an authenticated MCP endpoint, category-level minimum stock,
seed datasets, store-specific shopping lists, location barcodes, and deeply nested products
and locations. [docs/plans/README.md](../docs/plans/README.md) is the authority on which of
those exist now; the goals are why they are on the list at all.

**How to apply:** lead with the answer. Do not re-derive the corpus at the operator — cite
the ADR or plan row and move on.
