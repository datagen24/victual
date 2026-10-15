"""Bump every copy of the release version at once, or check that they all agree.

    python3 .devtools/ci/release_version.py 0.4.1                  # bump, dated today
    python3 .devtools/ci/release_version.py 0.4.1 --date 2026-10-14
    python3 .devtools/ci/release_version.py --check                # exit 1 if any copy disagrees

ADR-0038 decision 4: the tag, the image tags, the chart's `version` and `appVersion`, and the
version `GET /api/system/info` reports are one string, read from version.json. That string
is also spelled out in the files listed in COPIES below, because each is read by something
that cannot read version.json: Helm, the OpenAPI document clients fetch, phpDocumentor, and
the podman and Compose manifests an operator applies by hand. A bump used to be fourteen
hand edits found by memory; this writes them, re-renders deploy/k3s/, adds the release table
row, and drafts docs/releases/<version>.md from the merged pull requests since the last tag.

The draft is not a record. It carries DRAFT_MARK, and --check refuses a record that still
does, so the lint job fails until the maintainer has filled in what changed and what was
verified. release.yml's tag check is unchanged: a tag still has to name what is committed.
"""
import argparse
import datetime
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERSION_JSON = ROOT / "version.json"
RELEASES = ROOT / "docs/releases"
INDEX = RELEASES / "README.md"

DRAFT_MARK = "<!-- DRAFT from .devtools/ci/release_version.py"
# 0.4.1, 0.2.0-MVP. The tag is `v` plus this; image and chart tags allow no `+`.
VERSION_FORMAT = re.compile(r"^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$")
DATE_FORMAT = re.compile(r"^\d{4}-\d{2}-\d{2}$")

# A local image reference the podman and Compose files pull with imagePullPolicy: Never.
# `localhost/victual:parity` (the retired Dockerfile image) has no dash and does not match.
LOCAL_IMAGE = r"localhost/victual-[a-z-]+:(?P<v>\d[0-9A-Za-z.-]*)"

# (glob, pattern). Every named group starting with `v` holds the version and every one
# starting with `d` the release date. Each file must match at least once.
COPIES = [
    ("version.json", r'"Version":\s*"(?P<v>[^"]+)",\s*"ReleaseDate":\s*"(?P<d>[^"]+)"'),
    ("deploy/helm/victual/Chart.yaml", r"(?m)^version:\s*(?P<v>\S+)$"),
    ("deploy/helm/victual/Chart.yaml", r'(?m)^appVersion:\s*"(?P<v>[^"]+)"$'),
    # The first "version" inside info; the description before it escapes its quotes.
    ("victual.openapi.json", r'(?s)"info":\s*\{.*?"version":\s*"(?P<v>[^"]+)"'),
    ("phpdoc.dist.xml", r'<version number="(?P<v>[^"]+)">'),
    ("deploy/podman/*.yaml", LOCAL_IMAGE),
    ("deploy/compose/*.yml", LOCAL_IMAGE),
    (".devtools/parity/README.md", LOCAL_IMAGE),
    ("README.md",
     r"\*\*Current version: \[(?P<v1>[^\]]+)\]\(docs/releases/(?P<v2>[^)]+)\.md\), "
     r"(?P<d1>\d{4}-\d{2}-\d{2})\*\*; release tag\n`v(?P<v3>[^`]+)`"),
]


def current():
    """(Version, ReleaseDate) from version.json."""
    data = json.loads(VERSION_JSON.read_text())
    return data["Version"], data["ReleaseDate"]


def files(glob):
    """The files a COPIES glob names. A glob must match somewhere, not in every file: a
    manifest may carry no local image."""
    return sorted(ROOT.glob(glob))


def occurrences(text, pattern):
    """[(group name, start, end, value)] for every version or date group in text."""
    found = []
    for match in re.finditer(pattern, text):
        for name, value in match.groupdict().items():
            if value is not None and name[0] in "vd":
                found.append((name, match.start(name), match.end(name), value))
    return found


def check():
    """Every disagreement with version.json, as human-readable lines. Empty means clean."""
    version, date = current()
    problems = []
    for glob, pattern in COPIES:
        matched_any = False
        for path in files(glob):
            for name, start, _, value in occurrences(path.read_text(), pattern):
                matched_any = True
                want = version if name[0] == "v" else date
                if value != want:
                    line = path.read_text().count("\n", 0, start) + 1
                    problems.append(f"{path.relative_to(ROOT)}:{line}: {value}, expected {want}")
        if not matched_any:
            problems.append(f"{glob}: no version found; COPIES in {Path(__file__).name} is stale")
    record = RELEASES / f"{version}.md"
    if not record.is_file():
        problems.append(f"docs/releases/{version}.md does not exist")
    elif DRAFT_MARK in record.read_text():
        problems.append(f"docs/releases/{version}.md is still the generated draft; "
                        "fill it in and delete the DRAFT comment")
    if f"({version}.md)" not in INDEX.read_text():
        problems.append(f"docs/releases/README.md does not list {version}")
    return problems


def rewrite(version, date):
    """Write version and date into every copy. Returns the files changed."""
    changed = []
    for glob, pattern in COPIES:
        for path in files(glob):
            text = path.read_text()
            spans = occurrences(text, pattern)
            # Right to left, so earlier offsets stay valid.
            for name, start, end, _ in sorted(spans, key=lambda s: s[1], reverse=True):
                text = text[:start] + (version if name[0] == "v" else date) + text[end:]
            if text != path.read_text():
                path.write_text(text)
                changed.append(path)
    return sorted(set(changed))


def add_index_row(version, date):
    """Insert the release table's row, newest first, unless it is already there."""
    text = INDEX.read_text()
    if f"({version}.md)" in text:
        return False
    header = "| Version | Date | Record |\n|---|---|---|\n"
    if header not in text:
        raise SystemExit("docs/releases/README.md has no release table to add a row to")
    INDEX.write_text(text.replace(header, f"{header}| {version} | {date} | [{version}.md]({version}.md) |\n", 1))
    return True


def git(*args):
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout


def merged_pull_requests(since_tag):
    """[(number, title)] for the pull requests merged onto this branch since since_tag."""
    try:
        log = git("log", "--first-parent", "--merges", "--format=%s%x1f%b%x1e", f"{since_tag}..HEAD")
    except subprocess.CalledProcessError:
        return None
    merged = []
    for entry in filter(None, (e.strip() for e in log.split("\x1e"))):
        subject, _, body = entry.partition("\x1f")
        number = re.match(r"Merge pull request #(\d+)", subject)
        if number:
            title = next((line for line in body.splitlines() if line.strip()), subject)
            merged.append((int(number[1]), title.strip()))
    return merged


def repository_url():
    url = git("remote", "get-url", "origin").strip()
    slug = re.search(r"github\.com[:/](.+?)(?:\.git)?$", url)
    return f"https://github.com/{slug[1]}" if slug else None


def draft_record(version, previous):
    """docs/releases/<version>.md, drafted from the pull requests since v<previous>."""
    record = RELEASES / f"{version}.md"
    if record.exists():
        return None
    merged = merged_pull_requests(f"v{previous}")
    url = repository_url()
    if merged is None:
        rows = f"| (no tag v{previous} to list pull requests from) | |\n"
    elif not merged:
        rows = f"| (no pull requests merged since v{previous}) | |\n"
    else:
        rows = "".join(
            f"| {title} ([PR {n}]({url}/pull/{n})). | |\n" if url else f"| {title} (PR {n}). | |\n"
            for n, title in merged
        )
    record.write_text(
        f"# Victual {version}\n\n"
        f"{DRAFT_MARK}:\n"
        "     say what each change means for an operator or client, delete rows that mean\n"
        "     nothing to either, record what was verified, then delete this comment.\n"
        "     `release_version.py --check`, and so the lint job, fails while it is here. -->\n\n"
        f"**Tag `v{version}`, on `master` at the commit that merges this record.** It follows\n"
        f"[{previous}]({previous}.md).\n\n"
        "## What changed\n\n"
        "| Change | Effect for an operator or client |\n"
        "|---|---|\n"
        f"{rows}\n"
        "## Verified before the tag\n\n"
        "| Check | Result | Reproduce |\n"
        "|---|---|---|\n"
        "| Chart, manifest and CI helper checks, after the version bump | not run | "
        "`python3 .devtools/ci/render_k3s.py --check`; `python3 .devtools/ci/release_version.py --check`; "
        "`python3 -m unittest discover -s .devtools/ci` |\n"
        f"| Upgrade from `v{previous}` over a populated database | not run | "
        "`.devtools/pgsql/upgrade-rehearsal/rehearse.sh` |\n"
        "| `tests` workflow on the merge commit | not run | CI |\n"
        "| `nix` workflow: flake check, boot, page and API walk, amd64 and arm64 | not run | the `nix` workflow |\n"
    )
    return record


def render_k3s():
    """Re-render deploy/k3s/ from the bumped chart. False if Helm is not installed."""
    sys.path.insert(0, str(Path(__file__).parent))
    import render_k3s as renderer
    try:
        rendered = renderer.files(renderer.helm_template())
    except FileNotFoundError:
        return False
    for name, text in rendered.items():
        (renderer.OUT / name).write_text(text)
    return True


def bump(version, date):
    previous, _ = current()
    changed = rewrite(version, date)
    rendered = render_k3s()
    for path in changed:
        print(f"updated  {path.relative_to(ROOT)}")
    if add_index_row(version, date):
        print("updated  docs/releases/README.md (table row)")
    record = draft_record(version, previous)
    if record:
        print(f"drafted  {record.relative_to(ROOT)}")
    if rendered:
        print("rendered deploy/k3s/")
    else:
        print("Helm is not installed: run python3 .devtools/ci/render_k3s.py before committing.",
              file=sys.stderr)
    print(f"\n{previous} -> {version}. Fill in docs/releases/{version}.md, commit, merge, then:\n"
          f"  git tag -s v{version} -m \"Victual {version}\" <merge commit>\n"
          f"  git push origin v{version}")
    return 0 if rendered else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("version", nargs="?", help="the new version, without the leading v")
    parser.add_argument("--date", default=datetime.date.today().isoformat(),
                        help="ReleaseDate, YYYY-MM-DD (default: today)")
    parser.add_argument("--check", action="store_true", help="compare, do not write")
    args = parser.parse_args()
    if args.check == bool(args.version):
        parser.error("give a version to bump to, or --check")
    if args.check:
        problems = check()
        for problem in problems:
            print(problem, file=sys.stderr)
        if problems:
            print("Run python3 .devtools/ci/release_version.py <version> to bump every copy together.",
                  file=sys.stderr)
            return 1
        print(f"Every copy of the version is {current()[0]}.")
        return 0
    version = args.version.removeprefix("v")
    if not VERSION_FORMAT.match(version):
        parser.error(f"{version!r} is not MAJOR.MINOR.PATCH with an optional -suffix")
    if not DATE_FORMAT.match(args.date):
        parser.error(f"--date {args.date!r} is not YYYY-MM-DD")
    return bump(version, args.date)


if __name__ == "__main__":
    raise SystemExit(main())
