# Working in this repository

Victual is a hard fork of [grocy](https://github.com/grocy/grocy),
governed by a documentation corpus that is taken seriously. Read in this order before
changing anything: this file, then [docs/constitution.md](docs/constitution.md) (standing
principles), then the [ADR index](docs/adr/README.md) (decisions in force), then the
[plans status table](docs/plans/README.md) (what work exists and what gates it).

## Ground rules

- **Decisions live in ADRs.** Do not contradict an Accepted ADR; do not treat a Proposed
  one as accepted. Accepting or rejecting an ADR is its own pull request carrying
  bookkeeping only — status line, index row, supersede pointers — never substantive edits.
  A plan that makes a new architectural decision on its way to shipping leaves an ADR
  behind.
- **PostgreSQL is the only engine, and SQLite is an input format.**
  [ADR-0008](docs/adr/0008-postgresql-only-runtime-engine.md) was accepted 2026-08-31 and
  its retirement landed with [plan 24](docs/plans/24-sqlite-runtime-retirement.md):
  `DB_DRIVER` accepts `pgsql` alone, **new migrations are PostgreSQL-only** (the SQLite line
  is frozen at 0265 and `check-migrations.php` refuses a `.sqlite.sql` above it), and
  `bin/victual-db-import` reads SQLite within a stated span that committed fixtures hold it
  to.
  Two things survive the retirement and are not oversights. The differential harness in
  `.devtools/pgsql/` still builds a SQLite side — ADR-0008's option C keeps it until
  [14](docs/plans/14-contract-and-regression-scaffolding.md) piece 2's response snapshot
  replaces it, so **do not delete SQLite behaviour the suite compares against**; it is
  permitted to construct that dialect only through
  `DatabaseDialect::SQLITE_TOOLING_ENV`, which is an environment variable rather than a
  setting precisely so it is not a way to run this fork. And migrations 0256–0265 stay a
  matched two-engine set per
  [ADR-0004](docs/adr/0004-engine-specific-migrations.md), because that range is history and
  the suite replays it.
- **The wire contract is the invariant** ([ADR-0005](docs/adr/0005-wire-contract-is-the-invariant.md)).
  Response shapes do not change casually; two accepted exceptions are documented there.
- **No state in process memory** between requests ([ADR-0007](docs/adr/0007-auth-state-outlives-the-process.md)) —
  Postgres, Redis, or a table. APCu only for pure caches.
- **Security posture:** authenticated issues are in scope
  ([ADR-0006](docs/adr/0006-authenticated-issues-in-scope.md)). Sweep findings are
  tracked by S-number in the plans README; do not introduce user-configurable outbound
  URLs (the tree currently has no SSRF surface — keep it that way).
- **Labels are opaque; grocycode is read-only.**
  [ADR-0011](docs/adr/0011-label-namespace.md) was **accepted 2026-09-04**: a new label
  payload is `vctl:<uid>` — 13 uppercase Crockford base32 characters over a `labels`
  mapping table — and no row id leaves the database on paper. The fork parses `grcy:*`
  indefinitely and emits it never, so no new Grocycode type is added and `grcy:l:` is not
  minted; printing becomes an outbox a drainer consumes, so nothing new extends
  `VICTUAL_LABEL_PRINTER_WEBHOOK`. **None of it is built yet, and as of 2026-09-06 it is
  scheduled**: plan [25](docs/plans/25-label-infrastructure.md) owns the machinery in wave 3b
  and plan [06](docs/plans/06-location-barcodes.md) — narrowed to placement, the locations UI
  and the current-location notion — depends on 25's first usable release. Until 25 lands, the
  tree still prints Grocycodes through the webhook, as [docs/grocycode.md](docs/grocycode.md)
  and [docs/label-printing.md](docs/label-printing.md) describe. Note what 25 does *not* do:
  the five entity types that already print keep the webhook through wave 3b, which is a
  delivery stage toward the retirement ADR-0011 accepted rather than a change to it. New
  printing extends neither the webhook nor Grocycode.
- **Observations propose; they never book.**
  [ADR-0012](docs/adr/0012-observations-are-proposals.md) was **accepted 2026-09-04**: a
  client with a confidence value writes a `proposals` row, and a person confirming it is
  what executes the booking through the existing service write paths. Creating a proposal is
  its own narrow grant; confirming *and rejecting* require exactly the permission the
  proposed booking requires directly, so no reviewer role and no `PROPOSALS_CONFIRM` leaf is
  minted, and there is no auto-confirm threshold. A proposal payload carries
  `proposed_fields` — the key set as submitted, never redacted — so a key missing from the
  payload means "you may not see this" and a key missing from both means "nobody proposed
  one". Nothing of this is built or scheduled: no table, no endpoint, no plan owns it, so
  the record constrains new work rather than describing the code. Deterministic clients — a
  human with a scanner, a tared scale — keep using the booking API.
- **Frontend sinks, two rules.** A string that came out of the DOM reaches jQuery through
  `$(document).find(sel)`, never `$(sel)` — `$()` parses a string beginning with `<` as
  HTML. And markup is built as nodes (`$("<option>").text(value)`), never by concatenating
  a value into a string that is then handed to `.html()` or `.append()`. Both are checked
  on every pull request by `.devtools/frontend/s29-payload.js` in the `frontend-security`
  job; [plan 21](docs/plans/21-frontend-sink-discipline.md) is why.

## Tone and response style
I am a very busy person you must write in bottom-line upfront always BLUF

Don't validate my feelings or reactions as a move ("you're right to feel that,"
"that's valid," "that's not your fault," "the tool's to blame, not you"). A brief
acknowledgment before getting to work is fine; validation that stands in for
substance is not.

Don't reflexively agree or praise ("you're absolutely right," "great question,"
"sharp instinct"). Agree when it's earned and say why. Don't manufacture
disagreement to seem independent either.

Don't reach for polished aphorisms, metaphors, or named "tensions" that perform
insight ("that's the real tension").

Default to plain, specific language over elegant phrasing. When a plainer
sentence and a more quotable one say the same thing, use the plainer one. Direct
isn't terse — explain reasoning fully, just without the editorializing.

Test: if a sentence would fit unchanged in a different conversation, cut it or
replace it with something specific to what I actually said.

## Compression
Cut ceremony, not reasoning. The target is fewer wasted tokens per answer, not
shorter thinking. Keep articles and complete sentences; the rules below remove
words that carry no information.

- No preamble or recap: don't restate my request, don't announce what you're
  about to do, don't summarize what you just said.
- No tool-call narration. I can see the calls.
- Cut filler and hedges: just, really, basically, actually, simply, essentially,
  it's worth noting, I should mention.
- Cut pleasantries: sure, certainly, of course, happy to.
- No emoji, no decorative headers on a short answer.
- Don't dump long logs, full files, or full diffs. Quote the shortest decisive
  line and cite `path:line`.
- State each fact once per response. Don't re-derive what's already established
  in the conversation.
- Never invent abbreviations (cfg, impl, req, fn). The tokenizer splits them the
  same as the full word, so you save nothing and cost me a decode.

Do NOT compress: security warnings, confirmations for destructive or
irreversible actions, and ordered multi-step instructions where dropping a
connective makes the order ambiguous. Those get full prose.

## Documentation conventions

- Follow [docs/documentation.md](docs/documentation.md) for document purpose and prose.
  READMEs orient readers; ADRs explain decisions; plans provide research and design inputs
  from which implementation steps can be prepared.
- Plans carry numbered **Open questions**; review answers go inline as `> **Response:**`
  blocks under the question, so question and answer read together.
- A landed plan gains an **Executed** section recording what actually shipped, including
  divergence from the plan above it. The plan body stays in its original present tense;
  the status table in the plans README is the authority on what is real.
- ADR format and lifecycle are specified in [docs/adr/README.md](docs/adr/README.md).
  Acceptance prerequisites are gates; the accepting PR says how each was met.
- Measurements quoted in records name the date and the working copy they were taken
  against, and say how to reproduce them.

## Conventions

- Branches: `{claude,codex,gemini}/<model>_<topic>-<suffix>` for agent work, `scp/<topic>` for the maintainer.
  PRs are small and single-purpose; lifecycle PRs (ADR acceptance) never mix with
  substantive change.
- Commit messages: imperative mood, optionally prefixed (`docs:`, `chore:`, `fix:`) as
  the log already does.
- The default branch is `master`; do not push to it directly.

## Running things

- Boot the app locally (PHP built-in server, SQLite demo mode — legitimate until
  ADR-0008's retirement work lands, not merely until the record was accepted):
  [.agents/skills/run-app/SKILL.md](.agents/skills/run-app/SKILL.md).
- PostgreSQL work: baseline DDL in `db/pgsql/baseline/`, differential test phases in
  `.devtools/pgsql/` (see its README), CI runs both engines against `postgres:16`.
- Business logic lives in `services/`; routes in `routes.php`; permissions are the 36
  constants in `controllers/Users/User.php` resolved through `user_permissions_resolved`.
- Container images: there is one answer now.
  [ADR-0013](docs/adr/0013-nix-built-container-images.md) was **accepted 2026-09-04** and
  production images are built by Nix — `flake.nix` and `nix/`, three images on no base
  image, see [nix/README.md](nix/README.md). The `Dockerfile`'s `production` target was
  retired with the acceptance, per that record's question 5, so the root `Dockerfile`
  builds the `dev` image the suite runs in and nothing else; the `images` CI job's five
  assertions moved to `nix/checks.nix` and the `nix` workflow's boot test rather than
  disappearing. The flake **has** been built and the pod **does** serve
  ([deploy/](deploy/README.md), applied 2026-09-04) — a sentence here said otherwise until
  2026-09-04 and treating a first build as part of the work was right while it lasted:
  that build found nine defects in two rounds. What is still open is
  [plan 20](docs/plans/20-container-infrastructure.md) pieces 2–5 and two of its ten
  verification checks (the credential split, and the SIGTERM half of the signal check).
