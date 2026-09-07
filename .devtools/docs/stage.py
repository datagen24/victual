#!/usr/bin/env python3
"""Assemble the documentation site's source tree.

Neither section of the site maps to a directory in this repository: the developer
section draws from docs/, from .github/, and from READMEs that sit beside the code
they describe. This script copies those into one tree and rewrites their links, so
that the sources stay where they are and keep working on GitHub.

See docs/plans/26-documentation-site.md for the design and
docs/adr/0020-documentation-publication-boundary.md for what is published and why.
"""
from __future__ import annotations

import argparse
import hashlib
import posixpath
import re
import shutil
import subprocess
import sys
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
BLOB = "https://github.com/datagen24/victual/blob/master/"
TREE = "https://github.com/datagen24/victual/tree/master/"

# phpDocumentor 3.10.0, released 2026-05-13. Both runtimes are pinned to the same
# release and must move together; check_pins() is what enforces that.
PHPDOC_VERSION = "3.10.0"
PHPDOC_IMAGE = (
    "phpdoc/phpdoc@sha256:"
    "312ebf61ed88a6ea79aac768e43c9e9af0dd5bf3e710ed6d40ab8e53bfeeb121"
)
PHPDOC_PHAR_URL = (
    "https://github.com/phpDocumentor/phpDocumentor/releases/download/"
    f"v{PHPDOC_VERSION}/phpDocumentor.phar"
)
PHPDOC_PHAR_SHA256 = "fe1e7c23ba3329aa6f19ac3c807446159a431a195ec5d9163b0c281a15105207"

# Repository path -> path within the staged tree. Anything not named here is not
# published, and a link to it is rewritten to GitHub.
PAGES = {
    "docs/constitution.md": "development/constitution.md",
    "docs/documentation.md": "development/documentation-conventions.md",
    "docs/data-model.md": "development/data-model.md",
    "docs/grocycode.md": "development/grocycode.md",
    ".github/CONTRIBUTING.md": "development/contributing.md",
    "db/pgsql/README.md": "development/postgresql.md",
    "nix/README.md": "development/images.md",
    "deploy/README.md": "development/deployment.md",
    ".devtools/ci/README.md": "development/devtools-ci.md",
    ".devtools/coverage/README.md": "development/devtools-coverage.md",
    ".devtools/frontend/README.md": "development/devtools-frontend.md",
    ".devtools/parity/README.md": "development/devtools-parity.md",
    ".devtools/diagrams/README.md": "development/devtools-diagrams.md",
    ".devtools/docs/README.md": "development/devtools-docs.md",
}

# Directories copied wholesale: every file keeps its name below the staged prefix.
TREES = {
    "docs/adr": "development/adr",
    "docs/diagrams": "development/diagrams",
}

LINK = re.compile(r"(?<!\!)\[([^\]]*)\]\(([^)\s]+)(\s+\"[^\"]*\")?\)")


def staged_for(repo_path: str) -> str | None:
    """Where a repository path lands in the staged tree, or None if unpublished."""
    if repo_path in PAGES:
        return PAGES[repo_path]
    for src, dst in TREES.items():
        if repo_path.startswith(src + "/"):
            return dst + repo_path[len(src):]
    return None


def rewrite_link(target: str, source_repo_path: str, staged_path: str) -> str:
    """Resolve one link against its original home, then point it at its new one."""
    if re.match(r"^[a-z][a-z0-9+.-]*:", target) or target.startswith("#"):
        return target

    path, _, fragment = target.partition("#")
    if not path:
        return target

    resolved = posixpath.normpath(
        posixpath.join(posixpath.dirname(source_repo_path), path)
    )
    if resolved.startswith(".."):
        return target

    destination = staged_for(resolved)
    # A diagram is staged both as the standalone file and as a page of the site.
    # Links from prose should land on the page, which carries the navigation; the
    # "open on its own page" link each page writes for itself keeps the file.
    if destination and destination.startswith("development/diagrams/") and destination.endswith(".html"):
        destination = destination[: -len(".html")] + ".md"
    if destination is None:
        # Not published. Point at the repository, and keep a trailing slash meaning
        # "directory" so the URL lands on a tree listing rather than a 404.
        base = TREE if path.endswith("/") else BLOB
        url = base + resolved.rstrip("/")
        return url + ("#" + fragment if fragment else "")

    relative = posixpath.relpath(destination, posixpath.dirname(staged_path))
    return relative + ("#" + fragment if fragment else "")


def rewrite(text: str, source_repo_path: str, staged_path: str) -> str:
    def replace(match: re.Match) -> str:
        label, target, title = match.group(1), match.group(2), match.group(3) or ""
        return f"[{label}]({rewrite_link(target, source_repo_path, staged_path)}{title})"

    return LINK.sub(replace, text)


def copy_page(repo_path: str, staged_path: str, out: Path) -> None:
    source = REPO / repo_path
    destination = out / staged_path
    destination.parent.mkdir(parents=True, exist_ok=True)
    if source.suffix == ".md":
        destination.write_text(
            rewrite(source.read_text(), repo_path, staged_path), encoding="utf-8"
        )
    else:
        shutil.copy2(source, destination)


def check_pins() -> None:
    """Both phpDocumentor pins must name one release; a half-finished upgrade fails here."""
    if f"v{PHPDOC_VERSION}/" not in PHPDOC_PHAR_URL:
        raise SystemExit(
            f"PHAR URL does not name phpDocumentor {PHPDOC_VERSION}: {PHPDOC_PHAR_URL}"
        )
    contributing = (REPO / ".github/CONTRIBUTING.md").read_text()
    if PHPDOC_IMAGE not in contributing:
        raise SystemExit(
            "CONTRIBUTING.md does not document the pinned image this script uses.\n"
            f"  expected: {PHPDOC_IMAGE}\n"
            "Both pins move together; see docs/plans/26-documentation-site.md."
        )


def container_runtime() -> str | None:
    for candidate in ("docker", "podman"):
        if shutil.which(candidate):
            return candidate
    return None


def fetch_phar(cache: Path) -> Path:
    phar = cache / f"phpDocumentor-{PHPDOC_VERSION}.phar"
    if not phar.exists():
        cache.mkdir(parents=True, exist_ok=True)
        print(f"  fetching {PHPDOC_PHAR_URL}")
        with urllib.request.urlopen(PHPDOC_PHAR_URL, timeout=120) as response:  # noqa: S310
            phar.write_bytes(response.read())
    digest = hashlib.sha256(phar.read_bytes()).hexdigest()
    if digest != PHPDOC_PHAR_SHA256:
        phar.unlink()
        raise SystemExit(
            f"phpDocumentor PHAR checksum mismatch\n  expected {PHPDOC_PHAR_SHA256}\n"
            f"  got      {digest}"
        )
    return phar


def build_api_reference(out: Path, cache: Path) -> bool:
    """Generate the PHP API reference, if a runtime is available. Absence is not failure."""
    runtime = container_runtime()
    if runtime:
        print(f"  phpDocumentor via {runtime}")
        subprocess.run(
            [runtime, "run", "--rm", "-v", f"{REPO}:/data", PHPDOC_IMAGE],
            cwd=REPO,
            check=True,
        )
    elif shutil.which("php"):
        print("  phpDocumentor via PHAR")
        result = subprocess.run(
            ["php", str(fetch_phar(cache)), "--config", "phpdoc.dist.xml"],
            cwd=REPO,
            check=False,
        )
        if result.returncode != 0:
            raise SystemExit(
                "phpDocumentor failed under the PHAR.\n"
                "  It needs PHP 8.1+ with ctype, hash, iconv, json, mbstring, simplexml\n"
                "  and xml, and reaches dom through symfony/console. On Ubuntu that is\n"
                "  php-cli, php-mbstring and php-xml — a missing php-xml reports itself as\n"
                '  "Extension DOM is required", which names neither the package nor this.\n'
                "  The container path carries its own PHP and does not need any of them."
            )
    else:
        print("  no container runtime and no php: API reference omitted")
        return False

    built = REPO / ".phpdoc/build"
    if not built.is_dir():
        raise SystemExit("phpDocumentor ran but produced no .phpdoc/build")
    shutil.copytree(built, out / "development/api", dirs_exist_ok=True)

    # Link it from the section page only now that it exists, so a build without a
    # runtime does not leave a link to a directory that was never staged.
    index = out / "development/index.md"
    index.write_text(
        index.read_text()
        + "\n## PHP API reference\n\n"
        "**[The generated class reference](api/index.html)** covers `controllers/`,\n"
        "`services/`, `middleware/`, `helpers/`, the barcode lookup plugins and the entry\n"
        "points at the repository root, private members included. It is regenerated on every\n"
        "build from the PHPDoc in the source, so it describes the commit this site was built\n"
        "from rather than a release.\n",
        encoding="utf-8",
    )
    return True


BRAND_INK = "#174B3A"
BRAND_CREAM = "#F2E7D3"


def stage_brand_assets(out: Path) -> None:
    """Copy the marks, plus a cream variant of each for the dark scheme.

    Both marks are drawn in the brand's deep green, which disappears on a dark
    header. The variant swaps that one fill for the brand's cream: both values are
    from `branding/color_scheme.txt` and the logo itself, so this recolours within
    the palette rather than inventing one.
    """
    assets = out / "assets"
    assets.mkdir(parents=True, exist_ok=True)
    for name in ("icon.svg", "logo.svg"):
        source = (REPO / "branding" / name).read_text()
        (assets / name).write_text(source, encoding="utf-8")
        (assets / f"{Path(name).stem}-dark.svg").write_text(
            source.replace(BRAND_INK, BRAND_CREAM), encoding="utf-8"
        )
    shutil.copy2(REPO / "branding/icon-32.png", assets / "icon-32.png")
    shutil.copy2(Path(__file__).parent / "assets/extra.css", assets / "extra.css")


SVG_BLOCK = re.compile(r"(<svg\b.*?</svg>)", re.S)
H1 = re.compile(r"<h1>(.*?)</h1>", re.S)
MIN_WIDTH = re.compile(r"min-width:\s*(\d+)px")


def stage_diagram_pages(out: Path) -> None:
    """Turn each generated diagram into a page of the site.

    The diagrams are self-contained HTML, and copying them through leaves a reader
    on a bare page with no navigation. Lifting the `<svg>` into a Markdown page puts
    them inside the site instead; the standalone file stays beside each one, because
    a full-bleed ERD is genuinely easier to read at its own size.
    """
    staged = out / "development/diagrams"
    for html in sorted(staged.glob("*.html")):
        source = html.read_text()
        svg = SVG_BLOCK.search(source)
        heading = H1.search(source)
        if not svg or not heading:
            raise SystemExit(f"{html.name} is not a generated diagram: no <svg> or <h1>")
        width = MIN_WIDTH.search(source)
        min_width = int(width.group(1)) if width else 1100
        title = re.sub(r"<[^>]+>", "", heading.group(1)).strip()
        (staged / f"{html.stem}.md").write_text(
            f"# {title}\n\n"
            f'<div class="diagram" style="--diagram-min-width: {min_width}px">\n'
            f"{svg.group(1)}\n</div>\n\n"
            f"[Open this diagram on its own page]({html.name}), without the site around "
            "it.\n",
            encoding="utf-8",
        )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--out", default=".docs-build", help="staging tree (default: .docs-build)"
    )
    parser.add_argument(
        "--no-api",
        action="store_true",
        help="skip the PHP API reference even where a runtime exists",
    )
    args = parser.parse_args()

    check_pins()

    out = (REPO / args.out).resolve()
    if out.exists():
        shutil.rmtree(out)
    out.mkdir(parents=True)

    for repo_path, staged_path in PAGES.items():
        copy_page(repo_path, staged_path, out)
    for src, dst in TREES.items():
        for path in sorted((REPO / src).rglob("*")):
            if path.is_file():
                repo_path = path.relative_to(REPO).as_posix()
                copy_page(repo_path, staged_for(repo_path), out)

    for name in ("index.md", "development/index.md"):
        shutil.copy2(Path(__file__).parent / "pages" / name, out / name)

    stage_brand_assets(out)
    stage_diagram_pages(out)

    pages = sum(1 for _ in out.rglob("*.md"))
    try:
        where = out.relative_to(REPO)
    except ValueError:
        where = out
    print(f"  staged {pages} Markdown pages into {where}")

    if not args.no_api:
        build_api_reference(out, REPO / ".phpdoc/cache")

    return 0


if __name__ == "__main__":
    sys.exit(main())
