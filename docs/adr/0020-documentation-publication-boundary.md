# ADR-0020: The documentation site publishes the manual, the developer reference and the ADRs; plans stay in the repository

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-09-07
- **Referenced by:** [25](../plans/25-documentation-site.md)

## Context

Victual has 55 Markdown files and 18,267 lines under `docs/`, plus nine READMEs elsewhere in
the repository and 84 configuration settings documented only in the comments of
`config-dist.php`. All of it is read on GitHub. [Plan 25](../plans/25-documentation-site.md)
researched publishing it and found two separate problems wearing one name.

**The first problem is that there is no user manual, and upstream does not supply one.**
[grocy.info/links](https://grocy.info/links), checked 2026-09-07, is two sections of
community-contributed articles and videos. That has held up for upstream because its
installation has been stable. It does not hold up here: Victual runs on PostgreSQL alone
([0008](0008-postgresql-only-runtime-engine.md)), ships images built by Nix from `scratch`
([0013](0013-nix-built-container-images.md)), and runs without a persistent application
volume ([10](../plans/10-cold-start-statelessness.md)). A community article about SQLite and
nginx on a Raspberry Pi does not merely date for Victual; it describes a system this one is
not compatible with. The fork has made the available installation documentation wrong for
it and has published nothing of its own aimed at a user.

**The second problem is that the developer documentation has no shape.** `docs/` mixes
standing principles, decisions, in-flight research, review findings, a security sweep, and
format specifications in one flat directory. A contributor arriving at it has `AGENTS.md`'s
prose reading order and nothing else. The schema is documented across `db/pgsql/README.md`
and, once [pull request 92](https://github.com/datagen24/victual/pull/92) lands,
`docs/data-model.md` and six generated ORM and ERD diagrams. The PHP API carries PHPDoc
throughout and `phpdoc.dist.xml` turns it into a browsable reference, but that reference is
a local build artifact — `.phpdoc/` is gitignored — so it exists only for whoever ran the
command.

Publishing anything from `docs/` requires a decision rather than a configuration, for one
reason: **a published site changes what a document is.** A plan in a repository is research
a reader has sought out. The same plan rendered with navigation, search and a stable URL
presents as reference. Delivery status that is a month stale is a footnote in a repository
and a wrong answer on a manual site. The choice of what crosses that line constrains every
document written afterwards, which is what makes it an ADR rather than a step in plan 25.

Three constraints came out of the research and bear on the choice.

**The ADRs and the plans are linked together 173 times.** Against `master` at `ab9d157b`,
`grep -rhoE '\]\((\.\./)?plans/[0-9]+[^)]*\)' docs/adr/ | wc -l` returns 173, of which 13 are
the index's Source column. Any boundary drawn between them severs those links.

**Read the Docs cannot run phpDocumentor.** Its `build.tools` key accepts `python`,
`nodejs`, `ruby`, `rust` and `golang`. There is no PHP, and the build environment has no
root, so the PHP API reference cannot be generated where the rest of the site is.

**`phpdoc.dist.xml` documents private members on a stated premise.** Its comment reads:
"Private members are documented too: this is an internal reference, not a published library
API". Publishing that output removes the premise the setting rests on.

## Decision

Victual publishes **one documentation site with two top-level sections**.

**Manual** — for someone running Victual. Installation from a checkout and from the Nix
images, configuration, the tasks the application performs, and operator reference:
updating, backup and restore, the REST API and API keys, barcodes, label printing, Home
Assistant and MQTT, roles and permissions. `docs/usage.md` moves into it and is deleted
from `docs/`.

**Development** — for someone changing Victual. The constitution, the documentation
conventions, `CONTRIBUTING.md`, the data model with its ORM and ERD diagrams, the schema
and deployment READMEs that live beside the code they describe, the format specifications,
and **the ADRs, complete with their index**.

**Plans are not published.** Neither are the two architecture reviews, the security sweep,
or the MCP interface specification. They stay in the repository, where they are already
public and already legible.

The boundary is **stability, not sensitivity**. An ADR states a decision that is in force
until a superseding record changes it; its status moves between a small set of values under
a lifecycle. A plan states research, delivery status as of a date, and numbered questions
that may still be unanswered. The first is reference. The second is a working document, and
a working document rendered as reference misinforms.

The vehicle is MkDocs on Read the Docs Community, one project, one URL, one version scheme,
building per tag. [Plan 25](../plans/25-documentation-site.md) owns the build, the
navigation and the writing.

### What this decision does not do

It does not change the ADR format, the ADR lifecycle, or who accepts a record. It does not
make publication a criterion for whether something is an ADR — see the first consequence
below. It does not publish the PHP API reference; see open question 1.

## Consequences

**Every ADR written from here on is a published document.** This is the constraint on later
work that the record exists to impose. It does not change what an ADR must contain, and the
existing rule — "keep review chronology and commentary about writing out of the argument" —
already asks for prose that reads without the pull request that produced it. The change is
that an ADR is now read by people who have not read the repository at all, so a record that
assumes the reader has just come from a plan will not carry.

**The plan/ADR boundary acquires a second meaning, and must not drift into it.** Today the
boundary is decision against research. It now also separates published from unpublished, and
that is a hazard: an author with an uncomfortable decision to record has a reason to write it
as a plan instead. A decision that constrains later work is an ADR whether or not it is
comfortable to publish, and if that becomes untrue the correct response is to stop publishing
ADRs, not to stop writing them. Anyone reviewing an ADR should test it against
`.github/CONTRIBUTING.md`'s criterion and not against how it will read on the site.

**173 links from the ADRs into the plans leave the site.** This is the largest cost of the
boundary and it is not avoidable while the boundary holds, because the ADRs genuinely depend
on the plans: [0011](0011-label-namespace.md) is narrowed by plan 06,
[0019](0019-label-printers-are-master-data.md) supplies what plan 20 piece 5 left open, and
the index's Source column names a plan for most records. Plan 25 must rewrite them to
absolute GitHub URLs at build time so they resolve rather than break, which means a reader
following a citation from an ADR leaves the site for the repository. That is the honest
outcome: the reasoning behind a decision is on the site, and the research behind the
reasoning is one click away in the repository.

**Delivery status stays off the site, but decision status does not.** The plan index is the
authority on what has shipped and it is not published, so the site never presents a stale
delivery status as current. The ADR index is published and carries Proposed and Accepted
states plus a review-notes table, which are stable by construction — a Proposed record is
accurately Proposed until a lifecycle pull request changes it. The one thing to watch is
that the ADR index's "Review and implementation notes" table records delivery-adjacent facts
("Images build and serve; production Docker target retired"), and those do go stale.

**The staging step plan 25 had removed comes back.** The Development section carries
`db/pgsql/README.md`, `nix/README.md`, `deploy/README.md`, `.github/CONTRIBUTING.md` and the
`.devtools` READMEs, none of which sit under a single documentation root. They are not moved:
the [documentation conventions](../documentation.md) give a folder README the job of
orienting a reader inside that folder, and moving `db/pgsql/README.md` empties the directory
it exists for. They are copied into the build tree instead, with their paths rewritten, which
is the mechanism the 42 links leaving `docs/` need anyway.

**The manual's reader sees a Development section.** One site was chosen over two for one
build, one URL and one version scheme; the cost is that a household following installation
instructions has ADRs in the sidebar. Two Read the Docs projects remain available later
without changing this decision, since the boundary is about what is published rather than
about how many sites it is published on.

**Read the Docs Community carries advertisements.** The repository is public and nothing
published here is private, so Community applies and Business at $50 per month buys nothing
this needs. Advertisements on a manual a household reads during installation are a
presentation cost; if they prove unacceptable the alternative is GitHub Pages, which changes
plan 25's build and not this record.

**Anything published becomes harder to delete than to write.** A stable URL that people
bookmark and other sites link to is a commitment. Removing a page later leaves a dead link
for readers who did nothing wrong. This argues for publishing the smaller set now — which is
what excluding the plans does — and adding rather than retracting.

## Options considered

**Publish everything in `docs/`.** Maximum completeness, and it is the option that needs no
boundary to be maintained. Rejected on the plans specifically: 25 plans carry Draft status,
delivery state as of a date, and numbered open questions, and a rendered site with search
presents each of those as current reference. Publishing a document that says "Blocked on Q6"
to an audience that cannot see the surrounding process is worse than not publishing it. The
reviews, the sweep and the MCP specification fail for the same reason — all three describe
work that is partly outstanding.

**Publish reference material only; keep the ADRs in the repository too.** The smallest
developer tree, and it has no link-severing cost at all. Rejected because it does not answer
the second problem: a contributor asking why the schema declares four foreign keys and no
others, or why PostgreSQL is the only engine, would find the reference and then have to
leave for the answer. The ADRs are what make a reference tree explain itself, and they are
stable enough to publish, which the plans are not.

**Two Read the Docs projects, one per audience.** Clean separation; nothing in the manual
hints at ADRs. Rejected for now on operating cost: two configurations, two build pipelines,
two version schemes, and every cross-link between them becomes an absolute URL. The
separation is worth less than that while there is one maintainer.

**Publish nothing and keep reading on GitHub.** The status quo, and it costs nothing. It
remains defensible for the developer half — GitHub renders all 55 files and resolves every
relative link, including the 42 that leave `docs/`. It is not defensible for the manual half,
for the reason in the Context: there is no manual to read on GitHub either, and the community
documentation that would substitute describes a different system.

## Open questions

1. **Is the PHP API reference published, and if so from where?** Read the Docs cannot build
   it — there is no PHP in its `build.tools` — so the options are to generate it in GitHub
   Actions, where PHP already runs for the test suite, and publish it separately with the
   site linking across; or to leave it the local artifact it is today, documented in
   `.github/CONTRIBUTING.md`. The second costs nothing and is the current behaviour. The
   first makes the service layer browsable to someone who has not cloned the repository,
   which is most of the value of a Development section.

2. **If it is published, do private members stay in it?** `phpdoc.dist.xml` includes
   `private` visibility on the stated grounds that the output is "an internal reference, not
   a published library API". Publishing it makes that sentence false. Either the
   configuration drops private members, which loses the service layer's private helpers that
   the comment says matter, or this record is amended to accept that Victual publishes its
   internals. Neither is obviously right; Victual is an application, not a library, so there
   is no API-stability promise that publishing a private method would break.

3. **Does the ADR index's review-notes table go on the site?** It is the one published
   artifact carrying delivery-adjacent statements, and it is genuinely useful to a reader
   evaluating a Proposed record. Publishing it accepts a small amount of the staleness the
   plan exclusion exists to avoid.

## Acceptance prerequisites

1. [Pull request 92](https://github.com/datagen24/victual/pull/92) has landed, so
   `docs/data-model.md` and the six diagrams the Development section names exist.
2. [Plan 25](../plans/25-documentation-site.md) records how the 173 ADR-to-plan links are
   rewritten, and a build demonstrates them resolving to the repository rather than 404ing.
   A build that leaves them broken fails this gate.
3. Open question 1 is answered. If the answer is that the API reference is published, open
   question 2 is answered in the same pull request and `phpdoc.dist.xml` is either changed or
   its premise comment is corrected.
4. A built site is inspected and the Manual section contains no link into the Development
   section that a user would need to follow to complete an installation. The manual has to
   stand alone for its own audience.
