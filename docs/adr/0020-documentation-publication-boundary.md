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

**Read the Docs has no first-class PHP and no Docker, but it can install PHP.** Its
`build.tools` key accepts `python`, `nodejs`, `ruby`, `rust` and `golang`, and nothing in its
documentation offers a Docker daemon, so the `docker run` in `.github/CONTRIBUTING.md` cannot
run there. `build.apt_packages` can, however, install Ubuntu standard-repository packages;
it is incompatible only with `build.commands`, not with the `build.jobs` hooks. phpDocumentor
3 requires PHP 8.1.2 or higher and the `mbstring` extension, and ships as a PHAR. Ubuntu
24.04 carries PHP 8.3, so `php-cli` and `php-mbstring` satisfy it.

**Read the Docs cannot accept a documentation build made elsewhere.** Its API v3 exposes
`GET` for build listings and details and `POST .../versions/<slug>/builds/` to trigger a
build; there is no endpoint that uploads prebuilt HTML. Uploading pre-built documentation
has been an open request since 2014
([readthedocs.org#1083](https://github.com/readthedocs/readthedocs.org/issues/1083)).
`build.commands` is the project's answer to "use your own tool", and it still runs on their
infrastructure. So a GitHub Action cannot build the site and hand it to Read the Docs; it
could only trigger a build, or leave an artifact for one to fetch.

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

**The PHP API reference is published**, generated by the phpDocumentor container the way
`.github/CONTRIBUTING.md` already documents, called from the documentation build script.

### What this decision does not do

It does not change the ADR format, the ADR lifecycle, or who accepts a record. It does not
make publication a criterion for whether something is an ADR — see the first consequence
below. It does not settle where the build runs; see open question 4.

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

**The PHP API reference is generated two ways, because Read the Docs cannot run a
container.** The container call stays the documented local path: it pins `phpdoc/phpdoc:3`
and needs no PHP installed on the machine. Read the Docs installs `php-cli` and
`php-mbstring` through `build.apt_packages` and runs a pinned phpDocumentor PHAR in a
`build.jobs` hook instead. Both read the same `phpdoc.dist.xml` and write the same
`.phpdoc/build`, so the output is the same and the documentation build script chooses
whichever runtime is present. The cost is one branch in that script and a second thing to
keep working; the alternative was a second host, and this keeps the site on the vehicle
already chosen.

**The reference documents private members, and that was justified on a premise this record
removes.** `phpdoc.dist.xml` includes `private` visibility with the comment "this is an
internal reference, not a published library API". After this record it is a published one.
See open question 2.

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

1. **Is the PHP API reference published, and if so how is it generated?**

   > **Response** (maintainer, 2026-09-07): Published. The documentation build script calls
   > the phpDocumentor container, as `.github/CONTRIBUTING.md` already does.

   Settled, and folded into the Decision. What it leaves is question 4: a container call
   needs a container runtime, and Read the Docs does not document one.

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

4. **Where does the documentation build run?** Narrowed by research after question 1 was
   answered, and the narrowing favours staying put. Read the Docs cannot run the container
   and cannot be handed a build made elsewhere — its API has no upload endpoint — but it can
   install PHP, and phpDocumentor's requirements are modest enough that `php-cli` plus
   `php-mbstring` meet them. So the site stays on Read the Docs and the build script carries
   two ways to reach the same output, as the consequence above describes.

   What is left to decide is whether that split is acceptable, or whether one runtime
   everywhere is worth more. Making the PHAR the only path removes the branch and the
   `docker run` from `.github/CONTRIBUTING.md`, at the cost of requiring PHP 8.1.2 and
   `mbstring` on a contributor's machine where today they need only Docker. Moving the whole
   build to GitHub Actions and GitHub Pages also removes the branch, and costs Read the Docs'
   pull-request previews and version selector plus a workflow holding write permission to the
   repository.

## Acceptance prerequisites

1. [Pull request 92](https://github.com/datagen24/victual/pull/92) has landed, so
   `docs/data-model.md` and the six diagrams the Development section names exist.
2. [Plan 25](../plans/25-documentation-site.md) records how the 173 ADR-to-plan links are
   rewritten, and a build demonstrates them resolving to the repository rather than 404ing.
   A build that leaves them broken fails this gate.
3. Open questions 2 and 4 are answered, and `phpdoc.dist.xml` is either changed to exclude
   private members or its premise comment is corrected to match what this record does.
4. A built Development section is inspected and no page in it is incomprehensible without a
   plan. The 173 rewritten links are citations a reader may follow, not reading the section
   depends on. The equivalent check for the Manual is plan 25's, since the Manual ships
   second.
