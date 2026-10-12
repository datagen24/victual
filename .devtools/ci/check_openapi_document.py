#!/usr/bin/env python3
"""Validate OpenAPI documents against the vendored OAS 3.2 structural schema.

    python3 .devtools/ci/check_openapi_document.py victual.openapi.json [more.json ...]

Three checks, because the schema alone is not enough:

  1. Structure: the official OAS 3.2 schema (oas/3.2/schema-2025-09-17.json, vendored from
     https://spec.openapis.org/oas/3.2/schema/2025-09-17 and pinned by SHA-256 below). It
     checks the shape of every object and rejects unknown keys, but it does not check
     Schema Object keywords or that a reference points at anything.
  2. Reference targets: every local $ref (`#/...` or `#anchor`) must resolve inside the
     document. The metaschema only checks that a $ref is a string.
  3. Placeholder enums: no string enum may contain "". The served document is derived from
     StringEnumTemplate, whose committed enum is [""], and a derivation that forgets to
     empty it advertises "" as a valid value (issue #799). The template itself is the one
     place the placeholder is meant to live.

Requires jsonschema (pinned in .github/workflows/tests.yml; the referencing package comes
with it). Exits 1 and prints one `file: pointer: message` line per finding.

Example validation is not done here; that is issue #800.
"""

import hashlib
import json
import sys
from pathlib import Path
from urllib.parse import unquote

HERE = Path(__file__).resolve().parent
SCHEMA_PATH = HERE / "oas" / "3.2" / "schema-2025-09-17.json"
SCHEMA_SHA256 = "0c9d74bf25f9b9388b2d81e421ef60fdefa9feffa94898dadfc501b342b3bfcc"

# The one enum allowed to hold "": the template the served enums are cloned from.
EMPTY_ENUM_ALLOWED = {"/components/schemas/StringEnumTemplate/enum"}


def pointer(parts):
    """RFC 6901 pointer for a path of keys and indexes."""
    return "".join("/" + str(part).replace("~", "~0").replace("/", "~1") for part in parts)


def load_metaschema(path=SCHEMA_PATH):
    raw = Path(path).read_bytes()
    found = hashlib.sha256(raw).hexdigest()
    if found != SCHEMA_SHA256:
        raise SystemExit(f"{path}: sha256 {found} is not the pinned {SCHEMA_SHA256}; "
                         "the vendored OAS schema changed, update SCHEMA_SHA256 deliberately")
    return json.loads(raw)


def structure_findings(document, metaschema):
    from jsonschema import Draft202012Validator

    validator = Draft202012Validator(metaschema)
    return sorted((pointer(error.absolute_path), error.message) for error in validator.iter_errors(document))


def _walk(node, path):
    yield path, node
    if isinstance(node, dict):
        for key, value in node.items():
            yield from _walk(value, path + [key])
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from _walk(value, path + [index])


def _anchors(document):
    found = set()
    for _, node in _walk(document, []):
        if isinstance(node, dict):
            for key in ("$anchor", "$dynamicAnchor"):
                if isinstance(node.get(key), str):
                    found.add(node[key])
    return found


def _resolves(document, fragment, anchors):
    if fragment == "":
        return True
    if not fragment.startswith("/"):
        return unquote(fragment) in anchors
    node = document
    for token in unquote(fragment)[1:].split("/"):
        token = token.replace("~1", "/").replace("~0", "~")
        if isinstance(node, dict) and token in node:
            node = node[token]
        elif isinstance(node, list) and token.isdigit() and int(token) < len(node):
            node = node[int(token)]
        else:
            return False
    return True


def reference_findings(document):
    anchors = _anchors(document)
    findings = []
    for path, node in _walk(document, []):
        if isinstance(node, dict) and isinstance(node.get("$ref"), str):
            target = node["$ref"]
            if target.startswith("#") and not _resolves(document, target[1:], anchors):
                findings.append((pointer(path + ["$ref"]), f"unresolved reference {target}"))
    return sorted(findings)


def empty_enum_findings(document):
    findings = []
    for path, node in _walk(document, []):
        if isinstance(node, dict) and isinstance(node.get("enum"), list) and "" in node["enum"]:
            where = pointer(path + ["enum"])
            if where not in EMPTY_ENUM_ALLOWED:
                findings.append((where, 'enum contains "", a placeholder that is not a value'))
    return sorted(findings)


def check_document(document, metaschema=None):
    metaschema = metaschema or load_metaschema()
    return (structure_findings(document, metaschema)
            + reference_findings(document)
            + empty_enum_findings(document))


def main(argv):
    if not argv:
        print(__doc__.strip().splitlines()[0] + "\nusage: check_openapi_document.py FILE [FILE ...]", file=sys.stderr)
        return 2
    metaschema = load_metaschema()
    failed = False
    for name in argv:
        try:
            document = json.loads(Path(name).read_text(encoding="utf-8"))
        except (OSError, ValueError) as error:
            print(f"{name}: unreadable: {error}")
            failed = True
            continue
        for where, message in check_document(document, metaschema):
            print(f"{name}: {where or '/'}: {message}")
            failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
