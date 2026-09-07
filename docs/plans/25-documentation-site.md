# Plan 25: Documentation site

Publish one MkDocs site on Read the Docs with two top-level sections: a **Manual** for
someone running Victual, and a **Development** section for someone changing it.
[ADR-0020](../adr/0020-documentation-publication-boundary.md) owns what crosses into the
site and why; this plan owns the build, the navigation, and the writing.

It ships in two pieces, in this order.

**Piece 1 — the Development section, and the build that carries it.** Mostly assembly of
material that already exists, though it is spread across `docs/`, `.github/`, and five
READMEs that sit beside the code they describe, so it needs a staging step rather than a
`docs_dir`. It also generates the PHP API reference by calling the phpDocumentor container.
This piece can ship without the Manual existing.

**Piece 2 — the Manual.** `docs/usage.md` splits into it, the 84 configuration settings get
a reference, and the task documentation gets written. That last part — what a user does
across the application's 81 non-API pages — does not exist in this repository or upstream,
and is the reason the pieces are ordered this way rather than the other.

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

**Piece 1** — the staging step, the MkDocs and hosting configuration, the navigation, the
Development section's pages, the phpDocumentor call, and a CI check that fails a pull request
on a broken link.

**Piece 2** — the Manual: `docs/usage.md` split into it, the configuration reference over the
84 settings, the task documentation, and the operator reference. Piece 2 adds pages and a nav
branch to a site piece 1 has already built.

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

This belongs to piece 2; piece 1 leaves the file where it is, and the site's landing page
links to it in the repository until the Manual exists.

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
6. **PHP API reference** — the phpDocumentor output, served as generated.

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

The build script calls the phpDocumentor container and copies `.phpdoc/build` into the
staging tree under the Development section, where MkDocs serves it unaltered the same way it
serves the six diagrams. The call is the one `.github/CONTRIBUTING.md` already documents:

```
docker run --rm -v "$(pwd):/data" phpdoc/phpdoc:3
```

Nothing about `phpdoc.dist.xml` changes for this: it already covers the four PSR-4 roots,
the barcode lookup plugins and the root entry points, and it already writes to
`.phpdoc/build`, which stays gitignored. The generated output remains a build artifact and
is still not committed.

**Read the Docs cannot run that container, and cannot be handed a build made elsewhere.**
Its `build.tools` has no PHP and its documentation offers no Docker daemon; its API v3
exposes build listing, build detail and a trigger endpoint, and nothing that uploads
prebuilt HTML — an open request since 2014
([readthedocs.org#1083](https://github.com/readthedocs/readthedocs.org/issues/1083)). A
GitHub Action therefore cannot build the site and push it to Read the Docs. It could only
trigger a build there, or leave an artifact for one to fetch.

**It can, however, install PHP, and phpDocumentor needs very little.** phpDocumentor 3
requires PHP 8.1.2 or higher and the `mbstring` extension, and ships as a PHAR. Ubuntu 24.04
carries PHP 8.3, and `build.apt_packages` installs Ubuntu standard-repository packages; it is
incompatible only with `build.commands`, not with the `build.jobs` hooks this build uses. So
Read the Docs runs a pinned PHAR where a developer runs the container, both reading the same
`phpdoc.dist.xml` and writing the same `.phpdoc/build`.

The build script therefore picks a runtime rather than requiring one: the container when a
container runtime is present, the PHAR when `php` is, and neither when a contributor has
only MkDocs — in which case the site still builds with the API reference section absent
rather than the build failing. Pin the PHAR to a release rather than fetching the floating
`https://phpdoc.org/phpDocumentor.phar`, so a documentation build is reproducible the way
the rest of the repository's builds are.

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
  apt_packages:
    - php-cli
    - php-mbstring
  jobs:
    pre_build:
      - python3 .devtools/docs/stage.py
mkdocs:
  configuration: mkdocs.yml
```

`apt_packages` and `build.jobs` coexist; only `build.commands`, the full override, excludes
`apt_packages`, and this build does not need it. The staging script is the one entry point:
it generates the API reference through whichever runtime it finds, assembles the tree, and
rewrites the links.

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

**Publish the Manual first and add the Development section later.** It front-loads the half
with no existing material. Rejected by open question 6's answer, and for the reason that
answer implies: the Development section is assembly and ships within the build's own effort,
while the Manual's part 3 covers 81 undocumented pages, so ordering it first would hold the
whole site behind the slowest part of it.

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

6. **Does the site ship in one piece or in two?**

   > **Response** (maintainer, 2026-09-07): Two. The Development section ships first; the
   > Manual follows when its part 3 is written.

   The plan is structured as piece 1 and piece 2 accordingly. One consequence to hold onto:
   piece 1 ships a site whose only section is Development, so its landing page and its name
   have to make sense to a user who arrives looking for installation help and finds a
   contributor reference. A line saying the Manual is being written, with the existing
   `docs/usage.md` linked in the repository, is enough and is cheaper than the alternative of
   holding the site back.

7. **Who writes the Manual's part 3, and from what?** Nothing in the repository describes what
   the 81 pages do; the knowledge is in the running application and in whoever uses it. The
   realistic sources are the maintainer's own use, upstream's in-app help text, and the
   localization strings. This is the plan's largest unestimated cost and it is not a
   configuration problem.

8. **Is the PHP API reference published, and if so how is it generated?**

   > **Response** (maintainer, 2026-09-07): Published, generated by calling the phpDocumentor
   > container from the documentation build script.

   Recorded as [ADR-0020](../adr/0020-documentation-publication-boundary.md)'s answered
   question 1 and implemented in the design above. Two things it does not settle stay with
   that record: whether private members remain in the output, since `phpdoc.dist.xml`
   justifies including them on the grounds that it is "not a published library API" (its
   question 2), and where a build with a container runtime actually runs (its question 4).
   The second determines whether this site stays on Read the Docs.

## Verification

### Piece 1 — the Development section

1. `mkdocs build --strict` succeeds with `validation.links.not_found`,
   `validation.nav.omitted_files` and `validation.links.anchors` set to `warn`. Break one
   link deliberately and confirm the build fails and names the file and the link.
2. No `not_found` warning appears in a clean build log, establishing that every relative link
   in the published set resolves.
3. Every one of the 173 ADR-to-plan links resolves to a GitHub URL that returns the plan.
   Compare the count in the built HTML against the source count, so a link silently dropped
   by the rewrite is caught rather than counted as success.
4. A page linking to a staged README resolves within the site, and the same unmodified source
   line still resolves on GitHub. Check both against `db/pgsql/README.md`, which carries 14 of
   the 42 links.
5. A new ADR added without a nav entry fails `mkdocs build --strict`.
6. The six diagram pages load from the built site and render their SVG.
7. The phpDocumentor output is reachable from the Development navigation, and a class page —
   `StockService` — loads with its methods listed. Confirm the build regenerated it rather
   than serving a stale copy by checking that a method added in the same commit appears.
8. The staging step produces the same API reference from either runtime. Generate it once
   through the container and once through the PHAR and compare the file lists; a class
   present in one and not the other means the two paths are not reading `phpdoc.dist.xml`
   the same way.
9. The staging step run with neither runtime available still produces a site, with the API
   reference section absent rather than the build failing. A contributor with only MkDocs
   must be able to build the documentation.
10. The Read the Docs build installs `php-cli` and `php-mbstring` and fetches the pinned
    PHAR. This is the one step that assumes outbound network access from a Read the Docs
    build beyond PyPI; confirm it on a real build rather than by reasoning, and if it is
    blocked, vendor the PHAR or fall back to open question 4's alternatives.
11. The build succeeds from a clean checkout with the committed configuration alone — no
    settings entered in a hosting dashboard — so the build is reproducible from the
    repository.
12. A pull request adding a Markdown file with a broken relative link fails the `lint` job,
    and the same pull request skips the differential suite, confirming the check runs on the
    Markdown-only path rather than requiring a code change to trigger.
13. The landing page of a site whose only section is Development tells a user looking for
    installation help where to go. This is the cost of shipping the pieces in this order and
    it has to be paid on the page, not assumed away.
14. No page in the Development section is incomprehensible without a plan. This is
    [ADR-0020](../adr/0020-documentation-publication-boundary.md)'s fourth acceptance
    prerequisite.

### Piece 2 — the Manual

15. `docs/usage.md` is gone and nothing links to it:
    `grep -rn 'usage\.md' --include='*.md' .` returns only references to its new location.
16. Every one of the 84 settings in `config-dist.php` appears in the Configuration part.
    Compare against `grep -cE "^(if \(!defined|Setting\()" config-dist.php`; a setting in
    one and not the other is the defect this check exists to find.
17. A reader following Getting started on a machine with no prior Victual installation
    reaches a login prompt using only the Manual. Establish this against both installation
    paths — checkout and Nix images — since they diverge completely.
18. The Manual contains no link into the Development section that a reader must follow to
    complete an installation.
19. The site's landing page no longer defers the Manual, and the notice added under piece 1
    check 13 is removed.
