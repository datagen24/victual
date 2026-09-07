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
        subprocess.run(
            ["php", str(fetch_phar(cache)), "--config", "phpdoc.dist.xml"],
            cwd=REPO,
            check=True,
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

    pages = sum(1 for _ in out.rglob("*.md"))
    print(f"  staged {pages} Markdown pages into {out.relative_to(REPO)}")

    if not args.no_api:
        build_api_reference(out, REPO / ".phpdoc/cache")

    return 0


if __name__ == "__main__":
    sys.exit(main())
