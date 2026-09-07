# Plan 25: Documentation site

Publish one MkDocs site on Read the Docs with two top-level sections: a **Manual** for
someone running Victual, and a **Development** section for someone changing it.
[ADR-0020](../adr/0020-documentation-publication-boundary.md) owns what crosses into the
site and why; this plan owns the build, the navigation, and the writing.

Two things dominate the effort, and neither is configuration. The Manual's task
documentation — what a user does across the application's 81 non-API pages — does not exist
in this repository or upstream, and is new writing. The Development section is mostly
assembly of material that already exists, but it is spread across `docs/`, five READMEs that
sit beside the code they describe, and `.github/`, so it needs a staging step rather than a
`docs_dir`.

## Problem and outcome

Upstream grocy has no official manual. [grocy.info/links](https://grocy.info/links), checked
2026-09-07, carries two sections — "Articles / written tutorials" and "Video tutorials" —
and both are entirely community-contributed: installation guides for Raspberry Pi, Debian,
Ubuntu, Alpine, FreeNAS, cPanel and Arch, web server configuration for nginx, Apache and
Caddy, Home Assistant walkthroughs, and YouTube videos. The page is a curated directory of
other people's work, not documentation the project maintains.

That arrangement has held up for upstream because the installation it describes has been
stable. It does not hold up for this fork. Victual runs on PostgreSQL alone
([ADR-0008](../adr/0008-postgresql-only-runtime-engine.md)), ships production images built by
Nix from `scratch` ([ADR-0013](../adr/0013-nix-built-container-images.md)), runs without a
persistent application volume ([plan 10](10-cold-start-statelessness.md)), and has added
roles and domain read permissions ([plan 19](19-rbac.md)). A community article explaining how
to point nginx at grocy on a Raspberry Pi with a SQLite file is not merely dated for Victual;
it describes a system this one is not compatible with. The fork has made the community's
installation documentation wrong for it and has published nothing of its own aimed at a user.

The developer half has the opposite problem: the material exists and has no shape. `docs/` is
a flat directory mixing standing principles, decisions, in-flight research, review findings
and format specifications, and the schema and deployment documentation is not in it at all.
A contributor gets `AGENTS.md`'s prose reading order and nothing else.

Success is a site where a person who has never seen the repository can install and use
Victual from the Manual, and a contributor can find the data model, the schema, the
deployment layout and the decision behind any of them from the Development section.

## Current behavior

Counts were taken 2026-09-07 against `master` at `ab9d157b`. Each is reproducible from a
clean checkout with the command given.

### What exists

| Document | Lines | Section it serves |
|---|---|---|
| [`docs/usage.md`](../usage.md) | 271 | Manual — install, configuration, operations, operator reference, under 20 headings |
| [`README.md`](../../README.md) | 98 | Both — project goals, current state, reading order |
| [`docs/constitution.md`](../constitution.md) | 96 | Development — standing principles |
| [`docs/documentation.md`](../documentation.md) | 222 | Development — writing conventions |
| [`.github/CONTRIBUTING.md`](../../.github/CONTRIBUTING.md) | 101 | Development — where things go, pull requests, API documentation, licensing |
| [`docs/adr/`](../adr/README.md) | 19 records + index | Development |
| [`db/pgsql/README.md`](../../db/pgsql/README.md) | 841 | Development — schema, porting rules, 18 hazards |
| [`nix/README.md`](../../nix/README.md) | 197 | Development — the three images |
| [`deploy/README.md`](../../deploy/README.md) | 178 | Development — pod manifest |
| [`docs/grocycode.md`](../grocycode.md) | 128 | Development — barcode payload format |
| [`docs/label-printing.md`](../label-printing.md) | 50 | Manual — label printer webhook |

`config-dist.php` is 405 lines carrying 84 settings
(`grep -cE "^(if \(!defined|Setting\()" config-dist.php`). None is documented outside the
comments in that file.

`phpdoc.dist.xml` configures a phpDocumentor build over the four PSR-4 roots, the barcode
lookup plugins and the root entry points, with `private` visibility included. Output goes to
`.phpdoc/build`, which is gitignored; `.github/CONTRIBUTING.md` carries the command. The
reference therefore exists only for whoever ran it.

[Pull request 92](https://github.com/datagen24/victual/pull/92) adds `docs/data-model.md` and
six generated ORM and ERD diagrams as self-contained HTML. The Development section's data
model pages are those.

### What does not exist

Task documentation. `routes.php` defines 81 non-API routes
(`grep -oE "'/[a-z][a-z0-9/_{}-]*'" routes.php | sort -u | grep -vc "^'/api"`) — purchase,
consume, inventory, transfer, the stock journal, chores and their tracking, batteries,
recipes and fulfilment, the meal plan, shopping lists, equipment, locations, quantity units
and conversions, product groups, userfields, userentities, roles, API keys, the calendar.
Nothing in the repository explains what any of them are for. This is the larger half of the
Manual and it is new writing.

### The links the build has to handle

| Measure | Value | Command |
|---|---|---|
| Relative Markdown links within `docs/` | 895 | `grep -rhoE '\]\([^)#][^)]*\.md[^)]*\)' docs/ \| wc -l` |
| Of those, links carrying an anchor | 3 | `grep -rhoE '\]\([^)]*\.md#[^)]+\)' docs/ \| wc -l` |
| Links from `docs/` to paths outside it | 42, to 16 distinct targets | `grep -rhoE '\]\(\.\./\.\./[^)]+\)' docs/ \| wc -l` |
| Links from `docs/adr/` into `docs/plans/` | 173 | `grep -rhoE '\]\((\.\./)?plans/[0-9]+[^)]*\)' docs/adr/ \| wc -l` |

The 173 are the consequence of ADR-0020's boundary and the largest single piece of link
handling this plan owns: the ADRs are published and the plans are not, so every one of those
links has to resolve to the repository instead of to a page.

**One defect found during this research has been fixed.** Four citations in
[plan 22](22-medication-tracking.md) were written as links to a
`services/StockService.php:1457` line-suffix form, which is not a path in the tree —
`git ls-files --error-unmatch 'services/StockService.php:204'` fails — so they rendered as
links on GitHub and resolved to nothing. They are now plain `` `StockService.php:1472` ``
code text, matching the roughly eighty other line citations in `docs/`, of which those four
were the only ones anyone had wrapped in a link. Three of the four line numbers had also
drifted and were corrected against the current file.

## Scope

Included: the site's two-section structure and navigation, the migration of `docs/usage.md`
into the Manual, the initial task documentation, the staging step that assembles the
Development section, an MkDocs configuration, a Read the Docs configuration, and a CI check
that fails a pull request on a broken link.

Excluded because [ADR-0020](../adr/0020-documentation-publication-boundary.md) excludes them:
the 25 plans, both architecture reviews, the security sweep, the MCP interface specification,
and the 83 changelog entries.

Excluded: generated API reference for the REST API. `victual.openapi.json` is served by the
application at `/openapi/specification` and the site links to it rather than re-rendering it.
The PHP API reference is a separate question — see open question 8.

No behaviour of the application changes. Nothing is added to the runtime image; the build
runs on Read the Docs and in CI only.

## Design

### Assembling the two sections

`docs_dir` points at a generated staging tree rather than at any directory in the
repository, because neither section maps to one. The Manual is new pages plus a split
`docs/usage.md`; the Development section draws from `docs/`, from `.github/`, and from five
READMEs that sit beside the code they describe.

Those READMEs are copied into the tree, not moved into it. The
[documentation conventions](../documentation.md) give a folder README the job of explaining
the contents and entry points of the folder it sits in; moving `db/pgsql/README.md` empties
the directory it exists to orient a reader inside. Copying keeps the source unchanged, so the
same relative links still resolve on GitHub.

The staging step therefore does four things:

1. Copies the Manual sources and the Development sources into one tree.
2. Rewrites the 42 links leaving `docs/` to their staged paths.
3. Rewrites the 173 ADR-to-plan links to absolute
   `https://github.com/datagen24/victual/blob/master/docs/plans/…` URLs.
4. Rewrites links to source files — `nix/webcheck.nix`, `controllers/Users/User.php`,
   `nix/build-in-podman.sh` — the same way, because source code's canonical address is the
   tree.

Consequence to accept: the staging step is also the local workflow. `mkdocs serve` against an
unstaged tree reports every one of those links as missing. It has to be one command a
contributor runs first, and CI has to run the same one.

### Where `docs/usage.md` goes

It moves into the Manual and is deleted from `docs/`. Duplication is not an option — the
conventions make one document the authoritative home for a fact — and the inbound cost is one
line: three files mention `usage.md`, of which [`README.md`](../../README.md) line 57 is the
only link, one is this plan, and one is a sentence in
[ADR-0017](../adr/0017-doctrine-dbal-is-the-persistence-seam.md) telling the author of
`usage.md` not to restate a decision more warmly. That constraint carries to the Manual
unchanged: the Manual describes behaviour, and the ADR remains the home for why.

### Proposed structure

**Manual**, in the order a new user meets it:

1. **Getting started** — what Victual is, what it changed from grocy and which upstream
   instructions therefore do not apply, install from a checkout, install from the Nix images,
   first login, and the immediate change of the default `admin` password.
2. **Configuration** — the 84 settings in `config-dist.php`, grouped by what they affect,
   plus the settings that live outside `config.php`.
3. **Using Victual** — stock (purchase, consume, inventory, transfer, the journal), shopping
   lists, recipes and meal planning, chores, batteries and equipment, and the master data
   behind them. New writing.
4. **Operator reference** — the REST API and API keys, barcodes and scanning, label printing,
   Home Assistant and MQTT, roles and permissions, updating, migrations, backup and restore.

**Development**:

1. **Orientation** — the constitution, `CONTRIBUTING.md`, the documentation conventions.
2. **Data model** — `docs/data-model.md` and the six ORM and ERD diagrams, then
   `db/pgsql/README.md` for the schema, the porting rules and its 18 hazards.
3. **Decisions** — the ADR index and all 19 records.
4. **Build and deployment** — `nix/README.md`, `deploy/README.md`, the `.devtools` READMEs.
5. **Formats** — grocycode, and the OpenAPI specification by link.

An explicit `nav` is required; the default alphanumeric listing produces neither order. With
`validation.nav.omitted_files: warn` under `mkdocs build --strict`, a new ADR or manual page
that is not in the nav fails the build. That is the intended maintenance cost: it makes an
unindexed document a build failure rather than a file nobody finds.

### The generated diagrams

MkDocs copies non-Markdown files under `docs_dir` to the built site without alteration, so
the six diagrams are served as they are and `docs/data-model.md`'s links to them resolve with
no change. They will not carry the site's navigation or search, because they are not pages the
generator renders. Exporting each to SVG and embedding it in a Markdown page is the
alternative; it costs the horizontal-scroll container the wider ERDs need below about 1100px.
Serve the HTML as it is and revisit only if the missing navigation proves to matter.

### The PHP API reference

Read the Docs cannot build it. Its `build.tools` key accepts `python`, `nodejs`, `ruby`,
`rust` and `golang`; there is no PHP, and the build environment has no root. Since
`phpdoc.dist.xml`, the command and the output path already exist and work locally, the
options are to generate it in GitHub Actions — where PHP already runs for the test suite —
and publish it separately with the Development section linking across, or to leave it the
local artifact it is today. Open question 8 decides; the design above assumes the second, so
that nothing in the site build depends on the answer.

### Generator: MkDocs

MkDocs with Material for MkDocs, for navigation, client-side search across both sections, and
a version selector without custom templates. The corpus's 895 relative Markdown links against
3 anchors also matter again now that the Development section publishes most of `docs/`: MkDocs
rewrites relative `.md` links at build time, so no published document has to change to render.

Sphinx with MyST remains rejected: no reStructuredText, no autodoc target — the application is
PHP, which Sphinx would not introspect either — and relative Markdown links between documents
need configuration MkDocs does not.

### Versioning

Build per tag. A user runs a specific release, and the installation instructions differ
between releases, so a Manual that only describes `master` is wrong for everyone who has not
just deployed it. `README.md` states there is no regular release schedule, so until there is,
the site has one version and the selector shows one entry. That is a reason to configure
versioning from the start rather than to defer it: retrofitting version-aware URLs after
people have bookmarked flat ones breaks the links.

### Read the Docs configuration

A `.readthedocs.yaml` at the repository root. `version`, `build.os` and `build.tools` are
required by Read the Docs' schema; `build.jobs` is the documented hook mechanism, preferred
over `build.commands`, which overrides the build entirely.

```yaml
version: 2
build:
  os: ubuntu-24.04
  tools:
    python: "3.12"
  jobs:
    pre_build:
      - python3 .devtools/docs/stage.py
mkdocs:
  configuration: mkdocs.yml
```

The repository is public and nothing published is private, so Read the Docs Community
applies: free, advertisement-supported. Business at $50 per month buys nothing this needs.

### CI

`.devtools/ci/changes.py` classifies a Markdown-only change so the differential suite, the
frontend probe, the development image build and Psalm skip it, while the `lint` job in
`tests.yml` still runs. The site build belongs in `lint`, because that is already the job that
runs on the changes this check exists for.

Adding `mkdocs.yml` and `.readthedocs.yaml` means edits to those two files are classified as
test inputs rather than as Markdown, since the classifier treats non-`.md` files
conservatively. That is correct and needs no change to the classifier.

Read the Docs builds on push independently of GitHub Actions. The CI check exists so a broken
link fails the pull request instead of reaching the published site.

## Alternatives

The publication boundary's alternatives — publishing everything, publishing reference only,
two separate sites — are argued in
[ADR-0020](../adr/0020-documentation-publication-boundary.md) and are not restated here. What
remains at this plan's level:

**MkDocs on GitHub Pages through Actions.** The fallback if the advertisements on Read the
Docs Community prove unacceptable, and it would also solve the PHP API reference by putting
both builds in the same pipeline. It loses pull-request preview builds and the version
selector, and adds a deploy workflow holding write permission to the repository.

**No staging step: move the five READMEs into the documentation tree.** Rejected against the
documentation conventions, as above. It would simplify the build to a plain `docs_dir` at the
cost of emptying the directories those files exist to orient a reader inside.

**Publish the Manual first and add the Development section later.** Viable, and it front-loads
the half with no existing material. Rejected as a plan structure because the Development
section is mostly assembly and would ship far sooner than the Manual's part 3; sequencing them
the other way round delivers something usable earlier. See open question 6.

## Dependencies

- **[ADR-0020](../adr/0020-documentation-publication-boundary.md)** is Proposed and carries
  four acceptance prerequisites. This plan implements it and should not be scheduled ahead of
  it.
- **[Pull request 92](https://github.com/datagen24/victual/pull/92)** adds
  `docs/data-model.md` and the six diagrams the Development section's data model pages are.
- **[Plan 20](20-container-infrastructure.md)** — the Manual's installation chapter documents
  the Nix images and the pod deployment, and pieces 2 through 5 remain open along with the
  credential split and the SIGTERM check. The chapter can be written against what has shipped
  but cannot be called complete before that work is.
- **[Plan 19](19-rbac.md)** — the roles and permissions chapter describes wave 3a's six domain
  read permissions. Piece 2, including price visibility, remains, so that chapter will need
  revising when it lands.
- **[Plan 16](16-project-rename.md)** holds the registry and domain research and its claims
  await announcement. A custom documentation domain waits on that; `victual.readthedocs.io`
  does not, so this plan is not blocked by it.

## Open questions

1. **What is the site for: a user manual, or a publication of the whole corpus?**

   > **Response** (maintainer, 2026-09-07): A manual. It is something upstream does not
   > have — grocy.info/links is a page of links to articles written by other people.

   > **Response** (maintainer, 2026-09-07, extending the above): Both. A user manual, and a
   > full developer documentation tree including the ORM and ERD diagrams. The developer tree
   > carries the reference material and the ADRs; the plans stay in the repository. One site
   > with two top-level sections rather than two sites.

   The plan above is reconciled to the second response, and the boundary it draws is recorded
   as [ADR-0020](../adr/0020-documentation-publication-boundary.md), because publishing the
   ADRs constrains every ADR written afterwards. Questions 3 and 5 are affected; see below.

2. **Are advertisements acceptable on the site?** Read the Docs Community is free and
   permanently public but advertisement-supported, and nothing published here is private, so
   Business buys nothing. What remains is a presentation choice, and it now cuts both ways:
   advertisements beside a decision record read differently from advertisements beside the
   installation instructions a household is following. If they are not acceptable, GitHub
   Pages is the alternative rather than a paid plan.

3. **Does everything in `docs/` go on the site?** Answered by
   [ADR-0020](../adr/0020-documentation-publication-boundary.md): the ADRs and the reference
   material do; the plans, both architecture reviews, the security sweep and the MCP interface
   specification do not. The boundary is stability rather than sensitivity, and that record
   carries the reasoning and the hazard.

4. **Which URL?** `victual.readthedocs.io` is available immediately and depends on nothing. A
   custom domain depends on plan 16's claims, which await announcement. Shipping at the Read
   the Docs address and adding a custom domain later is a redirect, not a rewrite.

5. **Does the site carry versions?** The design recommends yes, per tag. What remains open is
   which versions are built and which is the default: every tag, or `latest` from `master`
   plus a `stable` alias on the newest tag. This is sharper now that the Development section
   exists, because a decision record is not version-scoped the way an installation instruction
   is — an ADR accepted after a tag is still in force for someone running that tag.

6. **Does the site ship in one piece or in two?** The Development section is assembly and
   could ship within the effort of the build itself; the Manual's part 3 covers 81 pages and
   does not exist. Publishing the site with a complete Development section and a Manual whose
   Using Victual part is a stub is a worse first impression than publishing the Development
   section alone and adding the Manual when it is written. The answer sets whether this plan
   delivers once or twice.

7. **Who writes the Manual's part 3, and from what?** Nothing in the repository describes what
   the 81 pages do; the knowledge is in the running application and in whoever uses it. The
   realistic sources are the maintainer's own use, upstream's in-app help text, and the
   localization strings. This is the plan's largest unestimated cost and it is not a
   configuration problem.

8. **Is the PHP API reference published, and if so from where?** Read the Docs cannot build
   it, so the options are generating it in GitHub Actions and publishing it separately with
   the site linking across, or leaving it the local artifact it is today. If it is published,
   `phpdoc.dist.xml`'s inclusion of private members needs deciding too — its own comment
   justifies that setting on the grounds that the output is "an internal reference, not a
   published library API". This is open question 1 and 2 of ADR-0020; the answer belongs
   there, and this plan implements it.

## Verification

1. `mkdocs build --strict` succeeds with `validation.links.not_found`,
   `validation.nav.omitted_files` and `validation.links.anchors` set to `warn`. Break one link
   deliberately and confirm the build fails and names the file and the link.
2. No `not_found` warning appears in a clean build log, establishing that all 895 relative
   links and all 42 that leave `docs/` resolve.
3. Every one of the 173 ADR-to-plan links resolves to a GitHub URL that returns the plan.
   Check the count in the built HTML against the source count, so a link silently dropped by
   the rewrite is caught rather than counted as success.
4. A page linking to a staged README resolves within the site, and the same unmodified source
   line still resolves on GitHub. Check both against `db/pgsql/README.md`, which carries 14 of
   the 42 links.
5. A new page added without a nav entry fails `mkdocs build --strict`.
6. `docs/usage.md` is gone and nothing links to it:
   `grep -rn 'usage\.md' --include='*.md' .` returns only references to its new location.
7. Every one of the 84 settings in `config-dist.php` appears in the Manual's Configuration
   part. Compare against `grep -cE "^(if \(!defined|Setting\()" config-dist.php`; a setting in
   one and not the other is the defect this check exists to find.
8. The six diagram pages load from the built site and render their SVG.
9. A reader following Getting started on a machine with no prior Victual installation reaches
   a login prompt using only the Manual. Establish this against both installation paths —
   checkout and Nix images — since they diverge completely.
10. The Manual contains no link into the Development section that a reader must follow to
    complete an installation. This is ADR-0020's fourth acceptance prerequisite.
11. The Read the Docs build succeeds from a clean checkout with `.readthedocs.yaml` alone — no
    configuration entered in the Read the Docs dashboard, so the build is reproducible from
    the repository.
12. A pull request adding a Markdown file with a broken relative link fails the `lint` job,
    and the same pull request skips the differential suite — confirming the check runs on the
    Markdown-only path rather than requiring a code change to trigger.
