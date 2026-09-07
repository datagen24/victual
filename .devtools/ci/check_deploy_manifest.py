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


def _template_spec(doc):
    return ((doc.get("spec") or {}).get("template") or {}).get("spec") or {}


# Kinds this check knows how to find containers in, and where. Kinds outside this set
# (ConfigMap, Secret, Service) are skipped rather than rejected: this file validates
# workload security and resourcing, not every object deploy/** may ever carry.
POD_SPEC_PATHS = {
    "Pod": lambda doc: doc.get("spec") or {},
    "Deployment": _template_spec,
    "StatefulSet": _template_spec,
    "DaemonSet": _template_spec,
    "Job": _template_spec,
    "CronJob": lambda doc: _template_spec((doc.get("spec") or {}).get("jobTemplate") or {}),
}

# Workloads whose containers run once and exit. They carry every security and resourcing
# obligation a serving container does, and none of the liveness obligations: a probe on a
# container that is *meant* to terminate either never runs or reports a failure that is the
# normal end of the work. This is the same exemption init containers already had, applied to
# the kinds ADR-0021's renderer needs — a scale-to-zero render job is exactly this shape.
RUN_TO_COMPLETION_KINDS = frozenset({"Job", "CronJob"})

# Keys whose value is a list of container definitions, wherever they appear.
CONTAINER_KEYS = ("containers", "initContainers")


def _container_lists(node):
    """Yield every container list nested anywhere under `node`."""
    if isinstance(node, dict):
        for key, value in node.items():
            if key in CONTAINER_KEYS and isinstance(value, list):
                yield value
            else:
                yield from _container_lists(value)
    elif isinstance(node, list):
        for item in node:
            yield from _container_lists(item)


def _unrecognized_workload_errors(doc):
    """Refuse a document that holds containers this check cannot find the pod spec for.

    The gap ADR-0021's prerequisite 6 named was not that Job and CronJob were missing --
    it was that *any* unknown kind passed by not being examined, silently, with no
    difference between "this object has no containers" and "this object has containers I
    could not reach". Adding two kinds fixes today's instance; failing closed on the rest
    is what stops the next one recurring. A ConfigMap still passes: its `data` values are
    strings, so no container list is reachable inside it.
    """
    kind = doc.get("kind", "<no kind>")
    name = (doc.get("metadata") or {}).get("name", "<unnamed>")
    if any(
        any(isinstance(container, dict) for container in containers)
        for containers in _container_lists(doc)
    ):
        return [
            f"{kind}/{name}: containers found in a kind this check cannot locate a pod "
            f"spec for; add it to POD_SPEC_PATHS"
        ]
    return []


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
    not init containers, and not the containers of a Job or CronJob, all of which run once
    to completion and exit — must also declare at least one probe.

    A document of a kind this file does not know is skipped only when it holds no
    containers; one that holds containers is an error, because passing it silently is
    indistinguishable from checking it.
    """
    kind = doc.get("kind")
    spec_of = POD_SPEC_PATHS.get(kind)
    if spec_of is None:
        return _unrecognized_workload_errors(doc)
    spec = spec_of(doc)
    needs_probe = kind not in RUN_TO_COMPLETION_KINDS

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
        if needs_probe and not any(container.get(probe) for probe in PROBES):
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
