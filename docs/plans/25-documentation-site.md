# Plan 25: Documentation site

Publish the Markdown corpus in `docs/` as a browsable site on Read the Docs, built with
MkDocs. The corpus is 55 files and 18,267 lines held together by 895 relative links; it is
readable on GitHub today and gains navigation, full-text search across all of it, and a
stable URL to cite. The work is a build configuration, a navigation tree, and a staging
step for the 42 links that point outside `docs/`; no document has to be rewritten.

The decision this plan does not make is what the site is *for*. A user manual and a
publication of the whole decision corpus are different sites with different audiences and
different maintenance costs, and the answer changes the scope, the nav, and whether an ADR
is required. That is open question 1, and the design below assumes the second reading
because it is the larger of the two and contains the first.

## Problem and outcome

The documentation is read on GitHub. That works for someone who already knows which file
they want: relative links resolve against the repository tree, the ADR and plan indexes
orient a reader, and GitHub's own search covers the repository. It does not work for three
things.

- **Reading across the corpus.** GitHub search is repository-wide and code-first. There is
  no way to search only the documentation, and no way to search the rendered text of 19
  ADRs and 25 plans as one body.
- **Citing a document.** A GitHub blob URL names a branch or a commit. `AGENTS.md` and the
  plans reference each other constantly; an external reference — an issue, a client
  repository, a message — has no stable, non-branch address to point at.
- **Orientation for a reader who is not already inside the repository.** `AGENTS.md` gives
  a reading order in prose. There is no rendered navigation that shows the corpus has a
  constitution, a decision layer, a plan layer, and a reference layer.

Success is a published site where every existing document renders, every existing link
resolves, and a broken link fails a pull request rather than reaching the site.

## Current behavior

Counts were taken 2026-09-07 against `master` at `ab9d157b`. Reproduce them with the
commands given; each is a single line against a clean checkout.

| Measure | Value | Command |
|---|---|---|
| Tracked Markdown files | 161 | `git ls-files '*.md' \| wc -l` |
| Files under `docs/` | 55 | `git ls-files 'docs/*.md' 'docs/**/*.md' \| wc -l` |
| Lines under `docs/` | 18,267 | `git ls-files 'docs/*.md' 'docs/**/*.md' \| xargs wc -l \| tail -1` |
| Release notes in `changelog/` | 83 | `git ls-files 'changelog/*.md' \| wc -l` |
| READMEs outside `docs/` | 9 | `git ls-files '*README.md' \| grep -v '^docs/' \| wc -l` |
| Relative Markdown links within `docs/` | 895 | `grep -rhoE '\]\([^)#][^)]*\.md[^)]*\)' docs/ \| wc -l` |
| Of those, links carrying an anchor | 3 | `grep -rhoE '\]\([^)]*\.md#[^)]+\)' docs/ \| wc -l` |
| Links from `docs/` to paths outside it | 42 | `grep -rhoE '\]\(\.\./\.\./[^)]+\)' docs/ \| wc -l` |
| Distinct targets among those | 16 | add `\| sort -u` to the above |

Three properties of the corpus determine the design.

**The corpus is almost entirely relative Markdown links, and almost never anchors.** 895
links to 3 anchors means a generator that rewrites `foo.md` to a page URL solves the link
problem outright, and heading-slug differences between renderers are a three-link concern
rather than a corpus-wide one.

**Forty-two links leave `docs/`, and they fall into two classes.** Thirty-five point at
nine Markdown files elsewhere in the repository: `db/pgsql/README.md` (14 links),
`deploy/README.md` (5), `nix/README.md` (4), `AGENTS.md` (4), `migrations/RESERVATIONS.md`
(3), `.github/SECURITY.md` (2), and one each to the `.devtools/parity`, `.devtools/frontend`
and `.devtools/coverage` READMEs. The remaining seven point at source files —
`nix/build-in-podman.sh`, `nix/webcheck.nix`, `controllers/Users/User.php`, and four
references into `services/StockService.php` from
[plan 22](22-medication-tracking.md).

**Four of those seven are already broken.** The four `StockService.php` links use a
`path.php:1457` line-suffix form, and `services/StockService.php:1457` is not a path in the
tree — `git ls-files --error-unmatch 'services/StockService.php:204'` fails. They render as
links on GitHub and resolve to nothing. Nothing currently checks this; a site build with
link validation is what would have caught them.

The corpus contains no raw HTML outside code spans, no Mermaid, and no footnotes; the only
fenced languages are `php`, `sql`, `js`, and `markdown`. It does contain XSS payloads
inside inline code spans in [plan 21](21-frontend-sink-discipline.md) and
[plan 12](12-frontend-shared-core.md), which is a reason to keep raw-HTML rendering off
rather than a reason to exclude those pages.

## Scope

Included: a generator configuration, a navigation tree over `docs/`, a staging step that
brings the nine externally-linked Markdown files into the build, a Read the Docs
configuration, and a CI check that fails a pull request on a broken link.

Excluded: rewriting any document for the site. If a document has to change to render, the
generator is wrong. The one exception is the four broken `StockService.php` links, which
are fixed because a link check will fail on them either way.

Also excluded: generated API reference. `victual.openapi.json` is a specification the site
could render, and there are tools that do it, but the corpus problem and the API-reference
problem are separate and the second has no stated need.

No behaviour of the application changes. Nothing is added to the runtime image, and the
build runs on Read the Docs and in CI only.

## Design

### Generator: MkDocs

MkDocs adjusts relative paths between Markdown documents during the build, which is what
the corpus's 895 links need and the reason to prefer it over Sphinx here. Material for
MkDocs supplies navigation, client-side search over the rendered corpus, and a dark theme
without custom templates.

The cost is that MkDocs has one `docs_dir`, and the nine Markdown files outside `docs/` are
not in it. That is handled by staging rather than by moving them; see below.

### The docs root, and the 42 links that leave it

MkDocs indexes only files under `docs_dir`, and a link resolving outside it triggers
`validation.links.not_found`, whose default level is `warn`. Under `mkdocs build --strict` a
warning fails the build, so all 42 links have to be handled before the check can be turned
on.

A `pre_build` step assembles a staging tree: `docs/` copied verbatim, plus the nine
externally-linked Markdown files copied under a `repo/` prefix, with `../../db/pgsql/README.md`
rewritten to the staged path. Sources are not modified, so the same link text keeps working
on GitHub, where `../../db/pgsql/README.md` is correct.

The nine files are **not** moved into `docs/`. The
[documentation conventions](../documentation.md) give a folder README the job of explaining
the contents and entry points of the folder it sits in; moving `db/pgsql/README.md` into
`docs/` empties the directory it exists to orient a reader inside.

The seven source-file links are rewritten by the same step to absolute
`https://github.com/datagen24/victual/blob/master/…` URLs, because source code is not
documentation and its canonical address is the tree. The four line-suffixed links become
`blob/master/services/StockService.php#L1457`, which is a working address for the line they
name — the rewrite fixes them rather than preserving the current form.

Consequence to accept: the staging step is also the local workflow. `mkdocs serve` against
a tree that has not been staged will report the 42 links as missing. The step has to be a
single command that a contributor runs first, and the CI check has to run the same one.

### The generated diagrams

MkDocs copies non-Markdown files under `docs_dir` to the built site without alteration, so
the six self-contained HTML diagrams from
[pull request 92](https://github.com/datagen24/victual/pull/92) are served as they are and
`docs/data-model.md`'s links to them resolve on the site with no change. They will not carry
the site's navigation or search, since they are not pages the generator renders.

The alternative is exporting each to SVG and embedding it in a Markdown page, which the
generator in `.devtools/diagrams/` can do. It costs the horizontal-scroll container the HTML
pages provide, which the wider ERDs need below about 1100px. Recommend serving the HTML as
it is and revisiting only if the missing navigation proves to matter.

### Navigation

An explicit `nav` is required. The default is an alphanumeric listing of `docs_dir`, which
interleaves `architecture-review.md` with `constitution.md` and presents 25 plans as a flat
list. The proposed grouping follows the reading order `AGENTS.md` already states:
constitution first, then decisions, then plans, then reference material, then the staged
repository READMEs.

With `validation.nav.omitted_files: warn` and `--strict`, a new ADR or plan that is not added
to the nav fails the build. That is the intended maintenance cost: it makes an unindexed
document a build failure rather than a file nobody finds.

### Read the Docs configuration

A `.readthedocs.yaml` at the repository root. `version`, `build.os`, and `build.tools` are
all required by Read the Docs' schema; `build.jobs` is the documented hook mechanism and is
preferred over `build.commands`, which overrides the build entirely and is not needed here.

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

The repository is public, so Read the Docs Community applies: free, public documentation
only, advertisement-supported. Read the Docs for Business starts at $50/month and is what
private documentation would require. Open question 2 decides whether Community is
sufficient.

### CI

`.devtools/ci/changes.py` classifies a Markdown-only change so that the differential suite,
the frontend probe, the development image build, and Psalm skip it; the `lint` job in
`tests.yml` still runs. A documentation build belongs in `lint`, because that is already the
job that runs on the changes this check exists for.

Adding `mkdocs.yml` and `.readthedocs.yaml` means edits to those two files are classified as
test inputs rather than as Markdown, since the classifier treats non-`.md` files
conservatively. That is correct and needs no change to the classifier.

Read the Docs builds on push independently of GitHub Actions. The CI check exists so that a
broken link fails the pull request instead of reaching the published site.

## Alternatives

**Sphinx with MyST on Read the Docs.** Rejected. The corpus has no reStructuredText, no
autodoc target — the application is PHP — and no API reference to generate. Against that,
relative Markdown links between documents need configuration that MkDocs does not, and the
corpus's dominant link form is exactly that.

**MkDocs on GitHub Pages through Actions.** Viable, and the fallback if open questions 4 or
5 make Read the Docs unattractive. It removes the third party and the advertisements. It
loses pull-request preview builds and version switching, and it adds a deploy workflow
holding write permission to the repository.

**Publish nothing; keep reading on GitHub.** The honest baseline, and it should be weighed
rather than dismissed. GitHub renders all 161 files, resolves every relative link including
the 42 that leave `docs/`, and searches the repository. A site buys navigation, search
scoped to the documentation, and a citable URL. It costs a nav to keep current, a build to
keep green, and a second surface on which a stale status statement is visible. If the answer
to open question 1 is "a user manual", most of that cost is spent on documents the manual
would not include, and this alternative becomes the better one for the rest of the corpus.

## Dependencies

- **[Pull request 92](https://github.com/datagen24/victual/pull/92)** adds
  `docs/data-model.md` and `docs/diagrams/`. The diagram handling above assumes both. If it
  does not land, that subsection drops and the counts in this plan fall by one file.
- **[ADR-0006](../adr/0006-authenticated-issues-in-scope.md)** bears on open question 3:
  the security sweep tracks findings by S-number, and whether that document is part of a
  published site is a question about the sweep's audience.
- **[Plan 16](16-project-rename.md)** holds the registry and domain research; its claims
  await announcement. A custom documentation domain waits on that. `victual.readthedocs.io`
  does not, so this plan is not blocked by it.
- **No ADR is required to choose a generator.** If open question 1 answers that the whole
  decision corpus is published as an official site, that *is* a decision constraining later
  work — every subsequent plan and ADR would be written for a public audience — and the
  repository's own rule requires it to leave an ADR behind.

## Open questions

1. **What is the site for: a user manual, or a publication of the whole corpus?** A manual
   is `README.md`, `usage.md`, installation, and configuration — perhaps six documents,
   aimed at someone running Victual. A corpus site is those plus 19 ADRs, 25 plans, the
   constitution, the reviews, and the reference material, aimed at someone changing Victual.
   The design above assumes the second. If the answer is the first, the nav shrinks to a
   handful of pages, the staging step is probably unnecessary, and the "publish nothing"
   alternative becomes the recommendation for everything the manual excludes.

2. **Community or Business?** Community is free and permanently public with
   advertisements; Business starts at $50/month and is what private documentation requires.
   The answer follows from question 3: if anything must not be public, Community is out.

3. **Given question 1's answer, does everything in `docs/` go on the site?** The specific
   documents to decide are `security-sweep.md` (517 lines of findings by S-number), the two
   architecture reviews, and the plans with unresolved open questions. Everything named is
   already public on GitHub, so publishing changes discoverability and framing, not access.
   It is still a decision, because a rendered site with navigation and search presents a
   document as current reference in a way a file in a repository does not. Excluding a page
   is not free: 895 links cross this corpus, and an excluded page that another page links to
   becomes a broken link that the strict build will fail on, so each exclusion needs its
   inbound links handled.

4. **Which URL?** `victual.readthedocs.io` is available immediately and depends on nothing.
   A custom domain depends on plan 16's claims, which await announcement. The answer decides
   whether the site ships now or waits.

5. **Does the site carry versions?** Read the Docs builds per branch and per tag. The
   corpus describes the tree at `master`, and the plan index states delivery status as of a
   date; a versioned site would present a superseded status as the current answer for an old
   tag. Building `master` alone avoids that and gives up the ability to read the
   documentation that shipped with a release.

## Verification

1. `mkdocs build --strict` succeeds with `validation.links.not_found`,
   `validation.nav.omitted_files`, and `validation.links.anchors` set to `warn`. Break one
   link deliberately and confirm the build fails and names the file and the link.
2. No `not_found` warning appears in a clean build log, establishing that all 895 relative
   links and all 42 external ones resolve.
3. A page linking to a staged README resolves within the site, and the same unmodified
   source line still resolves on GitHub. Check both against `db/pgsql/README.md`, which
   carries 14 of the 42 links.
4. Each of the four rewritten `StockService.php` links loads github.com at the line it
   names. Before the change, `git ls-files --error-unmatch 'services/StockService.php:204'`
   fails, which is the defect being fixed.
5. Adding a new file under `docs/` without a nav entry fails `mkdocs build --strict`.
6. The six diagram pages load from the built site and render their SVG, confirming that
   copying non-Markdown files unaltered is sufficient for them.
7. The Read the Docs build succeeds from a clean checkout with `.readthedocs.yaml` alone —
   no configuration entered in the Read the Docs dashboard, so the build is reproducible
   from the repository.
8. A pull request adding a Markdown file with a broken relative link fails the `lint` job,
   and the same pull request skips the differential suite, confirming that the check runs on
   the Markdown-only path rather than requiring a code change to trigger.
