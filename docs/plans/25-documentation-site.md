# Plan 25: Documentation site

Publish a **user manual** — installation, configuration, and task documentation for someone
running Victual — as an MkDocs site on Read the Docs. The decision corpus in `docs/` stays
on GitHub and is not published; see open question 1 for the answer that settled this and
the reasoning it replaced.

This is mostly writing, not publishing. [`docs/usage.md`](../usage.md) already covers
installation and operation in 271 lines under 20 headings, and that is the manual's first
half: it needs splitting into navigable pages, not authoring. The second half — what a user
does with stock, chores, recipes, meal plans and shopping lists across the application's 81
non-API pages — does not exist in this repository, and does not exist upstream either.

## Problem and outcome

Upstream grocy has no official manual. [grocy.info/links](https://grocy.info/links), checked
2026-09-07, carries two sections — "Articles / written tutorials" and "Video tutorials" —
and both are entirely community-contributed: installation guides for Raspberry Pi, Debian,
Ubuntu, Alpine, FreeNAS, cPanel and Arch, web server configuration for nginx, Apache and
Caddy, Home Assistant integration walkthroughs, and YouTube videos. The page is a curated
directory of other people's work, not documentation the project maintains.

That arrangement has held up for upstream because the installation it describes has been
stable. It does not hold up for this fork. Victual runs on PostgreSQL alone
([ADR-0008](../adr/0008-postgresql-only-runtime-engine.md)), ships production images built
by Nix from `scratch` rather than a PHP base image
([ADR-0013](../adr/0013-nix-built-container-images.md)), runs without a persistent
application volume ([plan 10](10-cold-start-statelessness.md)), and has added roles and
domain read permissions ([plan 19](19-rbac.md)). A community article explaining how to point
nginx at grocy on a Raspberry Pi with a SQLite file is not merely dated for Victual; it
describes a system this one is no longer compatible with.

So the fork has made the community's installation documentation wrong for it, and has
nothing of its own aimed at a user. `docs/usage.md` is close, but it is one long page
written for someone who has already cloned the repository — it opens with "Commands below
run from the repository root."

Success is a published manual that a person who has never seen the repository can follow
from "I want to run this" to "I have it running and I know how to use it", and that says
what Victual does differently from grocy where a reader would otherwise reach for an
upstream article.

## Current behavior

Counts were taken 2026-09-07 against `master` at `ab9d157b`. Each is reproducible from a
clean checkout with the command given.

### What user-facing documentation exists

| Document | Lines | Covers |
|---|---|---|
| [`docs/usage.md`](../usage.md) | 271 | Install from checkout, container images, PostgreSQL, SQLite migration, file storage, Home Assistant, updating, localization, REST API, barcode scanning, input and keyboard shorthands, PWA, migrations, feature flags, custom CSS/JS, demo mode, configuration, embedded mode |
| [`README.md`](../../README.md) | 98 | Project goals, current state, reading order |
| [`docs/grocycode.md`](../grocycode.md) | 128 | The barcode payload format |
| [`docs/label-printing.md`](../label-printing.md) | 50 | Label printer webhook |
| [`deploy/README.md`](../../deploy/README.md) | 178 | Pod manifest and applied deployment |
| [`nix/README.md`](../../nix/README.md) | 197 | The three images and how they are built |

`config-dist.php` is 405 lines carrying 84 settings
(`grep -cE "^(if \(!defined|Setting\()" config-dist.php`). None of them is documented
outside the comments in that file.

### What does not exist

Task documentation. `routes.php` defines 81 non-API routes
(`grep -oE "'/[a-z][a-z0-9/_{}-]*'" routes.php | sort -u | grep -vc "^'/api"`) — purchase,
consume, inventory, transfer, the stock journal, chores and their tracking, batteries,
recipes and fulfilment, the meal plan, shopping lists, equipment, locations, quantity units
and their conversions, product groups, userfields, userentities, roles, API keys, the
calendar. Nothing in the repository explains what any of them are for or how to use them.
This is the larger half of the manual and it is new writing.

### The corpus that is not being published

| Measure | Value | Command |
|---|---|---|
| Files under `docs/` | 55, 18,267 lines | `git ls-files 'docs/*.md' 'docs/**/*.md' \| wc -l` |
| Relative Markdown links within `docs/` | 895 | `grep -rhoE '\]\([^)#][^)]*\.md[^)]*\)' docs/ \| wc -l` |
| Of those, links carrying an anchor | 3 | `grep -rhoE '\]\([^)]*\.md#[^)]+\)' docs/ \| wc -l` |
| Links from `docs/` to paths outside it | 42, to 16 distinct targets | `grep -rhoE '\]\(\.\./\.\./[^)]+\)' docs/ \| wc -l` |
| Release notes in `changelog/` | 83 | `git ls-files 'changelog/*.md' \| wc -l` |

These figures are retained because they are the argument for leaving the corpus on GitHub.
Forty-two links leave `docs/` and would each need handling before any generator could build
it; 19 ADRs and 25 plans would each need a navigation entry; and the plans record delivery
status as of a date, which a rendered site with search presents as current reference more
insistently than a file in a repository does. GitHub already renders all of it with every
relative link resolving, including the 42.

**One defect found during this research has been fixed.** Four citations in
[plan 22](22-medication-tracking.md) were written as links to a
`services/StockService.php:1457` line-suffix form, which is not a path in the tree —
`git ls-files --error-unmatch 'services/StockService.php:204'` fails — so they rendered as
links on GitHub and resolved to nothing. They are now plain `` `StockService.php:1472` ``
code text, matching the roughly eighty other line citations in `docs/`, of which those four
were the only ones anyone had wrapped in a link. Three of the four line numbers had also
drifted and were corrected against the current file; a fix to the link syntax alone would
have preserved wrong citations. The manual would not have surfaced any of this, because the
corpus is not built.

## Scope

Included: the manual's page structure, the migration of `docs/usage.md` into it, the initial
task documentation, an MkDocs configuration, a Read the Docs configuration, and a CI check
that fails a pull request on a broken link within the manual.

Excluded, and staying on GitHub: ADRs, plans, the architecture reviews, the security sweep,
`AGENTS.md`, the constitution, the documentation conventions, `docs/data-model.md` and the
schema diagrams, and the 83 changelog entries. These are written for someone changing
Victual, not running it.

Excluded: generated API reference. `victual.openapi.json` is served by the application at
`/openapi/specification` and the manual links to it rather than re-rendering it.

No behaviour of the application changes. Nothing is added to the runtime image; the build
runs on Read the Docs and in CI only.

## Design

### A separate documentation root

`docs_dir` points at a new `manual/` tree at the repository root, containing only manual
pages. This is the design's largest simplification and it follows directly from question 1's
answer: with a root that holds nothing but the manual, there are no links leaving it, no
staging step to bring outside files in, and no exclusion list. The corpus-site design this
plan previously carried needed all three.

`manual/` sits at the repository root rather than under `docs/` so that the split is visible
in a directory listing: `docs/` is written for contributors, `manual/` for users. A reader
who opens `docs/` should not have to work out which of 55 files were meant for them.

### Where `docs/usage.md` goes

It moves into the manual and is deleted from `docs/`. Duplication is not an option — the
documentation conventions make one document the authoritative home for a fact — and the
inbound cost is one line: three files mention `usage.md`, of which
[`README.md`](../../README.md) line 57 is the only link, one is this plan, and one is a
sentence in [ADR-0017](../adr/0017-doctrine-dbal-is-the-persistence-seam.md) telling the
author of `usage.md` not to restate a decision more warmly. That constraint carries over to
the manual unchanged, and is worth stating in the conventions once the move happens: the
manual describes behaviour, and the ADR remains the home for why.

The 271 lines split roughly into installation, configuration, operations, and the
feature-specific material that belongs with the tasks it supports — barcode scanning next to
purchase and consume, rather than in a list of miscellany.

### Proposed structure

Four parts, in the order a new user meets them:

1. **Getting started** — what Victual is, what it changed from grocy, install from a
   checkout, install from the Nix images, first login, and the immediate change of the
   default `admin` password.
2. **Configuration** — the 84 settings in `config-dist.php`, grouped by what they affect,
   plus the settings that live outside `config.php`.
3. **Using Victual** — the task documentation: stock (purchase, consume, inventory,
   transfer, the journal), shopping lists, recipes and meal planning, chores, batteries and
   equipment, and the master data behind them. This part is new writing.
4. **Reference** — the REST API and API keys, barcodes and grocycode, label printing, Home
   Assistant and MQTT, roles and permissions, migrations and updating, backup and restore.

An explicit `nav` is required; the default alphanumeric listing would not produce that
order. With `validation.nav.omitted_files: warn` under `mkdocs build --strict`, a new manual
page that is not in the nav fails the build.

### Generator: MkDocs

MkDocs with Material for MkDocs, for navigation, client-side search, and a versioned
selector without custom templates. The corpus-site version of this plan chose MkDocs because
it rewrites relative `.md` links at build time and the corpus is 895 of them; the manual is
new Markdown in one tree, so that reason no longer carries the decision. What carries it now
is that a manual needs nav, search, and versions with as little build machinery as possible,
and MkDocs supplies all three from one YAML file.

Sphinx with MyST remains rejected: there is no reStructuredText, no autodoc target — the
application is PHP — and no API reference to generate.

### Versioning

Build per tag, and this reverses the recommendation the plan carried before. For a corpus
site, versions are a hazard: a superseded plan status would be served as the current answer
for an old tag. For a manual they are the point. A user runs a specific release, the
installation instructions differ between releases, and a manual that only describes `master`
is wrong for everyone who has not just deployed it.

The dependency this creates is on releases existing. `README.md` states there is no regular
release schedule, so until there is, the site has one version and the selector shows one
entry. That is a reason to configure versioning from the start rather than to defer it —
retrofitting version-aware URLs after people have bookmarked flat ones breaks the links.

### Read the Docs configuration

A `.readthedocs.yaml` at the repository root. `version`, `build.os` and `build.tools` are
required by Read the Docs' schema; `mkdocs.configuration` names the config file. No
`build.jobs` hook is needed, because the staging step the corpus design required is gone.

```yaml
version: 2
build:
  os: ubuntu-24.04
  tools:
    python: "3.12"
mkdocs:
  configuration: mkdocs.yml
```

The repository is public and the manual is public, so Read the Docs Community applies: free,
public documentation, advertisement-supported. Read the Docs for Business, from $50/month, is
what private documentation would require and nothing here needs it. Question 2 is narrowed to
whether advertisements are acceptable on a user-facing manual.

### CI

`.devtools/ci/changes.py` classifies a Markdown-only change so the differential suite, the
frontend probe, the development image build and Psalm skip it, while the `lint` job in
`tests.yml` still runs. The manual build belongs in `lint`, because that is already the job
that runs on the changes this check exists for.

Adding `mkdocs.yml` and `.readthedocs.yaml` means edits to those two files are classified as
test inputs rather than as Markdown, since the classifier treats non-`.md` files
conservatively. That is correct and needs no change to the classifier.

Read the Docs builds on push independently of GitHub Actions. The CI check exists so a broken
link fails the pull request instead of reaching the published manual.

## Alternatives

**Publish the whole `docs/` corpus as the site.** This was the plan's assumption before
question 1 was answered. Rejected: the corpus is written for someone changing Victual, and
publishing it costs a navigation entry for 44 ADRs and plans, handling for 42 escaping links,
and a second surface on which a stale delivery status is visible — while solving none of the
problem the manual solves. GitHub already renders it well. The two are not exclusive: if the
corpus later wants a site, it can have a separate one, and nothing in this design prevents
that.

**In-app help instead of a manual.** grocy carries some explanatory text in the interface,
and extending it would put the documentation where the task is. Rejected as the primary
answer: it cannot cover installation, which is where a reader needs help before the interface
exists, and it is not indexable. It is a reasonable complement later.

**Continue relying on community articles, as upstream does.** The status quo, and the
alternative that has to be argued against rather than dismissed, because it costs nothing and
has served grocy for years. It fails here for the specific reason given above: the fork's
installation is no longer the one those articles describe, so the existing corpus of
community documentation is not merely thin for Victual, it is misleading.

**MkDocs on GitHub Pages through Actions.** The fallback if question 2 or 4 makes Read the
Docs unattractive. It removes the third party and the advertisements; it loses pull-request
preview builds and the version selector, and adds a deploy workflow holding write permission
to the repository.

## Dependencies

- **[Plan 20](20-container-infrastructure.md)** — the manual's installation chapter
  documents the Nix images and the pod deployment, and pieces 2 through 5 remain open along
  with the credential split and the SIGTERM check. The chapter can be written against what
  has shipped, but it cannot be called complete before that work is.
- **[Plan 19](19-rbac.md)** — a chapter on roles and permissions describes wave 3a's six
  domain read permissions. Piece 2, including price visibility, remains, so that chapter will
  need revising when it lands.
- **[Plan 16](16-project-rename.md)** holds the registry and domain research and its claims
  await announcement. A custom documentation domain waits on that; `victual.readthedocs.io`
  does not, so this plan is not blocked by it.
- **No ADR is required.** This was conditional on question 1: publishing the decision corpus
  would have constrained later work, because every subsequent plan and ADR would then be
  written for a public audience. A user manual does not, and choosing a generator for it does
  not either.

## Open questions

1. **What is the site for: a user manual, or a publication of the whole corpus?**

   > **Response** (maintainer, 2026-09-07): A manual. It is something upstream does not
   > have — grocy.info/links is a page of links to articles written by other people.

   The plan above is reconciled to this answer: the documentation root is a manual-only
   tree, the staging step for links leaving `docs/` is gone, versioning is now recommended
   rather than argued against, and no ADR is required. Questions 3 and 5 are affected; see
   below.

2. **Are advertisements acceptable on the manual?** Read the Docs Community is free and
   permanently public but advertisement-supported, and question 1's answer removed the only
   reason Business was under consideration — nothing on this site is private. What remains is
   a presentation choice: advertisements on a corpus of internal records read by contributors
   are one thing; advertisements on the manual a household reads while installing the
   software are another. If they are not acceptable, GitHub Pages is the alternative rather
   than a paid plan.

3. **Does everything in `docs/` go on the site?** Resolved by question 1: nothing in `docs/`
   goes on the site. The security sweep, the architecture reviews and the plans with open
   questions stay on GitHub, where they are already public and already legible.

4. **Which URL?** `victual.readthedocs.io` is available immediately and depends on nothing. A
   custom domain depends on plan 16's claims, which await announcement. A user manual makes a
   memorable address worth more than a corpus site would, so this is more pressing under
   question 1's answer than it was before — but shipping at the Read the Docs address and
   adding a custom domain later is a redirect, not a rewrite.

5. **Does the site carry versions?** The design now recommends yes, per-tag, for the reason
   given above. What remains open is which versions are built and which is the default:
   every tag, or `latest` from `master` plus a `stable` alias on the newest tag. This depends
   on whether a release schedule appears; `README.md` currently states there is none.

6. **Does the manual's task documentation ship incrementally or complete?** The Using
   Victual part is the larger half and covers 81 pages. Shipping the manual with an
   installation chapter and an empty Recipes chapter is arguably worse than not shipping,
   because a published manual implies coverage in a way a repository file does not. The
   alternatives are to publish parts 1, 2 and 4 first and add part 3 as it is written, or to
   hold the whole site until part 3 exists. The answer sets whether this plan can deliver
   anything before the writing is done.

7. **Who writes part 3, and from what?** Nothing in the repository describes what the 81
   pages do; the knowledge is in the running application and in whoever uses it. The
   realistic sources are the maintainer's own use, upstream's in-app help text, and the
   localization strings. This is the plan's largest unestimated cost and it is not a
   configuration problem.

## Verification

1. `mkdocs build --strict` succeeds with `validation.links.not_found`,
   `validation.nav.omitted_files` and `validation.links.anchors` set to `warn`. Break one
   link deliberately and confirm the build fails and names the file and the link.
2. A new page added under `manual/` without a nav entry fails `mkdocs build --strict`.
3. The Read the Docs build succeeds from a clean checkout with `.readthedocs.yaml` alone —
   no configuration entered in the Read the Docs dashboard, so the build is reproducible from
   the repository.
4. A pull request adding a manual page with a broken relative link fails the `lint` job, and
   the same pull request skips the differential suite — confirming the check runs on the
   Markdown-only path rather than requiring a code change to trigger.
5. `docs/usage.md` is gone and nothing links to it: `grep -rn 'usage\.md' --include='*.md' .`
   returns only references to the manual's new location.
6. Every one of the 84 settings in `config-dist.php` appears in the configuration part.
   Compare the count in the manual against
   `grep -cE "^(if \(!defined|Setting\()" config-dist.php`; a setting present in one and not
   the other is the defect this check exists to find.
7. A reader following the Getting started part on a machine with no prior Victual
   installation reaches a login prompt, using only the manual. Establish this against both
   installation paths — checkout and Nix images — since they diverge completely.
8. The manual states, in the Getting started part, which upstream grocy instructions do not
   apply to Victual. A reader arriving from a community article about SQLite and nginx is the
   case this is for.
