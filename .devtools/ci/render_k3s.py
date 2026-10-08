"""Render deploy/k3s/ from the Helm chart, or check that the committed copy matches it.

    python3 .devtools/ci/render_k3s.py            # rewrite deploy/k3s/*.yaml
    python3 .devtools/ci/render_k3s.py --check    # exit 1, with a diff, if they differ

ADR-0038 decision 2: the chart at deploy/helm/victual/ is the one source, and deploy/k3s/ is
its rendering with ci/k3s-values.yaml, committed so that `kubectl apply -k deploy/k3s` and
the parity tests keep reading plain files. kustomization.yaml there is
hand-written and is not touched.

Each template becomes the file of the same name. Helm sorts its output by kind and puts a
chunk holding only comments last, so this script owns the layout instead: per template, the
comment-only chunks (a file's preamble) first, then the documents in Helm's order. The drift
check then depends on what the chart renders, not on how Helm happens to print it.
"""
import argparse
import difflib
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CHART = ROOT / "deploy/helm/victual"
VALUES = CHART / "ci/k3s-values.yaml"
OUT = ROOT / "deploy/k3s"
HAND_WRITTEN = {"kustomization.yaml"}

SOURCE = re.compile(r"^---\n# Source: [^/\n]+/templates/(?P<name>[^\n]+)\n", re.MULTILINE)


def helm_template(values=VALUES, *, hooks=False, extra=()):
    """`helm template` of the chart, as text. Raises CalledProcessError with Helm's stderr."""
    command = ["helm", "template", "victual", str(CHART), "-f", str(values), *extra]
    if not hooks:
        command.append("--no-hooks")
    return subprocess.run(command, check=True, capture_output=True, text=True).stdout


def chunks(stream):
    """(template file name, chunk text) for every chunk of `helm template`'s output."""
    marks = list(SOURCE.finditer(stream))
    for mark, following in zip(marks, marks[1:] + [None]):
        end = following.start() if following else len(stream)
        yield mark["name"], stream[mark.end():end].strip("\n")


def is_comment_only(chunk):
    return all(not line.strip() or line.lstrip().startswith("#") for line in chunk.splitlines())


def files(stream):
    """{file name: file text} for deploy/k3s/, from `helm template`'s output."""
    grouped = {}
    for name, chunk in chunks(stream):
        if chunk:
            grouped.setdefault(name, []).append(chunk)
    rendered = {}
    for name, parts in grouped.items():
        preamble = [p for p in parts if is_comment_only(p)]
        documents = [p for p in parts if not is_comment_only(p)]
        header = (
            f"# GENERATED from deploy/helm/victual/templates/{name} by .devtools/ci/render_k3s.py\n"
            "# with ci/k3s-values.yaml. Edit the template and run the script; the lint job fails\n"
            "# when this file and the chart disagree (ADR-0038 decision 2).\n"
            "#\n"
        )
        rendered[name] = header + "\n".join(preamble) + "\n" + "".join(f"---\n{d}\n" for d in documents)
    return rendered


def committed():
    return {p.name: p.read_text() for p in OUT.glob("*.yaml") if p.name not in HAND_WRITTEN}


def differences(rendered, current):
    out = []
    for name in sorted(set(rendered) | set(current)):
        want, have = rendered.get(name, ""), current.get(name, "")
        if want != have:
            out += difflib.unified_diff(
                have.splitlines(keepends=True), want.splitlines(keepends=True),
                f"deploy/k3s/{name} (committed)", f"deploy/k3s/{name} (rendered)",
            )
    return "".join(out)


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--check", action="store_true", help="compare, do not write")
    args = parser.parse_args()
    rendered = files(helm_template())
    if args.check:
        diff = differences(rendered, committed())
        if diff:
            sys.stdout.write(diff)
            print("deploy/k3s/ differs from the chart. Edit deploy/helm/victual/ and run "
                  "python3 .devtools/ci/render_k3s.py, not deploy/k3s/ by hand.", file=sys.stderr)
            return 1
        print(f"deploy/k3s/ matches the chart ({len(rendered)} file(s)).")
        return 0
    for name in set(committed()) - set(rendered):
        (OUT / name).unlink()
    for name, text in rendered.items():
        (OUT / name).write_text(text)
    print(f"Rendered {len(rendered)} file(s) into deploy/k3s/.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
