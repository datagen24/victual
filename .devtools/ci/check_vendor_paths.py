"""Keep tests off vendor files a dist Composer install does not have.

`packages/` is Composer's vendor directory here, and it is gitignored: CI fills it with
`composer install`, which fetches dist archives. A package's `.gitattributes` decides what
those archives contain, and `export-ignore` is normal for a package's own tests, docs and
website. A working copy installed from source has those directories; CI never does.

A test that reads one therefore passes locally and cannot pass in CI. Two label tests read
`packages/php-di/php-di/website/fonts/fontawesome-webfont.ttf` for a real font and blocked
the suite on exactly that. The fix was a fixture the suite owns; this is the guard, because
the next borrowed file would be just as invisible in a working copy.

Two paths are always there and are allowed: `packages/autoload.php`, which is Composer's
own entry point, and `packages/bin/`, the binary proxies Composer writes from each
package's declared `bin`.
"""
import re
from pathlib import Path

ROOTS = ("tests", ".devtools")

# Not `public/packages`, which is the yarn tree and a separate question.
REFERENCE = re.compile(r"(?<!public/)(?<!public\\/)\bpackages/([^\s'\"`)\\]*)")

ALLOWED = re.compile(r"autoload\.php\Z|bin/")


def violations(text):
    """Return each disallowed vendor path in the order it appears."""
    return [match.group(0) for match in REFERENCE.finditer(text) if not ALLOWED.match(match.group(1))]


def select_files(root):
    files = []
    for directory in ROOTS:
        files.extend(sorted(path for path in (root / directory).rglob("*.php")))
    return files


def main():
    root = Path.cwd()
    failures = 0
    files = select_files(root)
    for path in files:
        for reference in violations(path.read_text()):
            print(f"{path.relative_to(root)}: reads {reference}, which a dist install may not have")
            failures += 1
    if failures:
        print("Only packages/autoload.php and packages/bin/ survive `composer install` from dist.")
        print("A fixture the suite owns is the replacement; see tests/Support/SfntFixture.php.")
    print(f"Checked {len(files)} file(s); {failures} error(s).")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
