# Documentation site build

Assembles and builds the site published at Read the Docs. What goes on the site and why is
[ADR-0020](../../docs/adr/0020-documentation-publication-boundary.md); the design is
[plan 26](../../docs/plans/26-documentation-site.md).

```bash
pip install -r .devtools/docs/requirements.txt
python3 .devtools/docs/stage.py
mkdocs serve
```

`stage.py` must run first. `mkdocs.yml`'s `docs_dir` is `.docs-build/`, a generated tree that
does not exist until it does, and building against a stale one silently publishes stale
pages — delete it if in doubt, since the script rebuilds it from scratch every run.

## Why there is a staging step

Neither section of the site maps to a directory. The developer section draws from `docs/`,
from `.github/CONTRIBUTING.md`, and from READMEs that sit beside the code they describe —
`db/pgsql/`, `nix/`, `deploy/`, and four under `.devtools/`. Those are not moved into a
documentation directory, because the
[documentation conventions](../../docs/documentation.md) give a folder README the job of
orienting a reader inside that folder; moving `db/pgsql/README.md` empties the directory it
exists for.

So `stage.py` copies them into one tree and rewrites their links. A link to a page that is
published becomes a relative link to its new home; a link to anything else — a plan, a
source file, a migration — becomes an absolute GitHub URL. Sources are never modified, so
the same link text keeps working when the file is read on GitHub.

## Branding and diagrams

`stage.py` also stages three things the repository does not keep in a documentation
directory.

- **The marks**, from `branding/`. Both are drawn in the brand's deep green, which is also
  the header colour, so the script writes a cream variant of each by swapping that one fill;
  both values come from the brand palette, so this is a recolour within it rather than an
  invented colour. `assets/extra.css` uses the cream mark in the header and switches the
  home page's wordmark by colour scheme.
- **The generated diagrams**, as pages. Each `docs/diagrams/*.html` is self-contained, and
  copying it through leaves a reader on a bare page with no navigation. The script lifts the
  `<svg>` out and writes a Markdown page around it, so the diagram sits inside the site with
  its nav and search. Links from prose are rewritten to the page; the standalone file stays
  beside it, and each page links to it, because a full-bleed ER diagram is genuinely easier
  to read at its own size.
- **`assets/extra.css`**, which carries the palette, the diagram's scroll container, and the
  font families the diagrams were drawn in.

The palette overrides target `[data-md-color-primary="custom"]` rather than `:root`, and
`mkdocs.yml` sets `primary: custom` and `accent: custom` to match. The theme sets its own
values through that attribute, and an attribute selector outranks `:root`.

`PAGES` and `TREES` at the top of the script are the whole map. Adding a page to the site
means adding a line there and a `nav` entry in `mkdocs.yml`; a page in one and not the other
fails `mkdocs build --strict`, which is the point.

## The PHP API reference

`stage.py` generates it with phpDocumentor and copies it in, using whichever runtime it
finds:

| Runtime | Where it applies |
|---|---|
| Container, pinned by digest | A developer's machine. Needs no PHP installed. |
| PHAR, pinned by version and SHA-256 | Read the Docs, which has no Docker daemon but can install `php-cli` and `php-mbstring` through `build.apt_packages`. |
| Neither | The site builds without the API reference, and without a link to it. CI takes this path with `--no-api`. |

**Both pins name phpDocumentor 3.10.0 and have to move together.** `check_pins()` fails the
build if `.github/CONTRIBUTING.md` stops documenting the image digest the script uses, which
is what catches a half-finished upgrade. Upgrading means changing four things in one commit:
`PHPDOC_VERSION`, `PHPDOC_IMAGE`, `PHPDOC_PHAR_SHA256` in `stage.py`, and the `docker run`
line in `CONTRIBUTING.md`.

Get the new checksum and digest from the release itself:

```bash
curl -sSL https://github.com/phpDocumentor/phpDocumentor/releases/download/vX.Y.Z/phpDocumentor.phar | shasum -a 256
curl -sS https://hub.docker.com/v2/repositories/phpdoc/phpdoc/tags/X.Y.Z | python3 -c 'import sys,json; print(json.load(sys.stdin)["digest"])'
```

Use the tag's own digest, not a per-architecture one: it is the multi-architecture manifest,
so it resolves on both arm64 and amd64.

## What CI checks

The `lint` job in `tests.yml` runs `stage.py --no-api` and `mkdocs build --strict`. Strict
mode turns a broken link, a page missing from the nav, and an anchor that does not resolve
into a failed pull request rather than a defect on the published site. `lint` is the job that
runs on Markdown-only changes, which is what this check exists for.

Read the Docs builds on push independently of that, using `.readthedocs.yaml` at the
repository root.
