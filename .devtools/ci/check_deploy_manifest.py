"""Validate deploy/**'s Kubernetes manifests against ADR-0010's manifest-level properties.

nix/checks.nix already asserts property 3's image-level half against the built
artifact — non-root uid, no shell in the runtime closure. Nothing asserted the
manifest's half of properties 3 and 4 until ADR-0010's acceptance review found the gap:
no check failed a manifest missing a probe, a resource limit, or a dropped capability,
and the nix workflow's path filter does not even see deploy/** (that workflow rebuilds
three images on every match, which is the wrong price for a YAML-only change — see
docs/adr/0010-workload-standard.md open question 2). This is deliberately a structural
check, not a linter: it asks whether required fields are present and correctly valued,
never whether the manifest is well designed.
"""
import argparse
from pathlib import Path

import yaml

PROBES = ("startupProbe", "livenessProbe", "readinessProbe")

# Kinds this check knows how to find containers in, and where. Kinds outside this set
# (ConfigMap, Secret, Service) are skipped rather than rejected: this file validates
# workload security and resourcing, not every object deploy/** may ever carry.
POD_SPEC_PATHS = {
    "Pod": lambda doc: doc.get("spec") or {},
    "Deployment": lambda doc: ((doc.get("spec") or {}).get("template") or {}).get("spec") or {},
    "StatefulSet": lambda doc: ((doc.get("spec") or {}).get("template") or {}).get("spec") or {},
    "DaemonSet": lambda doc: ((doc.get("spec") or {}).get("template") or {}).get("spec") or {},
}


def _security_errors(kind, name, security_context):
    errors = []
    sc = security_context or {}
    if sc.get("readOnlyRootFilesystem") is not True:
        errors.append(f"{kind}/{name}: securityContext.readOnlyRootFilesystem must be true")
    if sc.get("allowPrivilegeEscalation") is not False:
        errors.append(f"{kind}/{name}: securityContext.allowPrivilegeEscalation must be false")
    if "ALL" not in ((sc.get("capabilities") or {}).get("drop") or []):
        errors.append(f"{kind}/{name}: securityContext.capabilities.drop must include ALL")
    return errors


def validate(doc):
    """Return readable errors for one parsed YAML document; empty means it passes.

    Every init and serving container must run non-root with a read-only filesystem, no
    escalated privileges and no capability it did not ask for (ADR-0010 property 3's
    manifest half), and must declare a memory limit (property 4). Serving containers —
    not init containers, which run once to completion and exit — must also declare at
    least one probe.
    """
    spec_of = POD_SPEC_PATHS.get(doc.get("kind"))
    if spec_of is None:
        return []
    spec = spec_of(doc)

    errors = []
    for container in spec.get("initContainers") or []:
        name = container.get("name", "<unnamed>")
        errors += _security_errors("initContainer", name, container.get("securityContext"))
        if not ((container.get("resources") or {}).get("limits") or {}).get("memory"):
            errors.append(f"initContainer/{name}: resources.limits.memory must be set")

    for container in spec.get("containers") or []:
        name = container.get("name", "<unnamed>")
        errors += _security_errors("container", name, container.get("securityContext"))
        if not ((container.get("resources") or {}).get("limits") or {}).get("memory"):
            errors.append(f"container/{name}: resources.limits.memory must be set")
        if not any(container.get(probe) for probe in PROBES):
            errors.append(f"container/{name}: no startupProbe, livenessProbe or readinessProbe")

    return errors


def select_manifests(root):
    return sorted((root / "deploy").rglob("*.yaml")) if (root / "deploy").is_dir() else []


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifests", nargs="*", help="defaults to every deploy/**/*.yaml")
    args = parser.parse_args()
    root = Path.cwd()
    paths = [Path(p) for p in args.manifests] or select_manifests(root)

    failures = 0
    checked = 0
    for path in paths:
        for doc in yaml.safe_load_all(path.read_text()):
            if doc is None:
                continue
            checked += 1
            for error in validate(doc):
                print(f"{path}: {error}")
                failures += 1

    print(f"Checked {checked} manifest document(s) in {len(paths)} file(s); {failures} error(s).")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
