"""The three label-workload files describe one pair of workloads, on three platforms.

`deploy/k3s/label-workers.yaml` runs the renderer and the worker as `CronJob`s;
`deploy/podman/label-workers.yaml` runs them as `Job`s, because `podman kube play` does not
support CronJob and skips it silently rather than refusing the file;
`deploy/compose/label-workers.yml` runs them as profiled services a systemd timer invokes,
because Compose has no scheduler at all. Three files, one workload each side, and the kind
is the only reason they are not one file.

Three copies of a thing drift the first time somebody tightens a limit in one of them -
that is what `test_deploy_pod_parity.py` already exists to prevent for the two Victual pod
manifests, and this is the same argument with a third target. What is compared is what the
workload *is*: which image, which arguments, which key, which uid, which filesystem, which
capabilities, which memory ceiling, which tmpfs. What is allowed to differ is enumerated
here rather than left to judgement - the API base, because podman reaches a published host
port and Kubernetes reaches a Service; `imagePullPolicy: Never`, because podman loads
its images locally and has no registry to consult; and the image's registry, because k3s
pulls the release from GHCR (ADR-0030) where the other two name a local build. The image
name and tag are compared.

The Compose half is not decoration. `check_deploy_manifest.py` globs `deploy/**/*.yaml` and
reads Kubernetes objects; a Compose file has no `kind` and no container list it can find, so
it would pass that check by being invisible to it. The Compose file is `.yml` so it stays
out of a checker that cannot judge it, and these assertions are what judge it instead.
"""
from pathlib import Path
import re
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]

K3S = "deploy/k3s/label-workers.yaml"
PODMAN = "deploy/podman/label-workers.yaml"
COMPOSE = "deploy/compose/label-workers.yml"

# The workload pair, by the name each file gives it: (k8s object name, compose service).
WORKLOADS = {"renderer": ("victual-label-renderer", "renderer"),
             "worker": ("victual-label-worker", "worker")}

UID = "65532:65532"
MEMORY_LIMIT_BYTES = 128 * 1024 * 1024
TMPFS_BYTES = 16 * 1024 * 1024

# Whatever each platform substitutes for "where Victual answers". The value legitimately
# differs; that an --api argument is passed at all does not.
API_BASE = re.compile(r"^\$[({]VICTUAL_API_BASE(:-[^}]*)?[)}]$")
API_SENTINEL = "<the API base>"


def documents(path):
    return [d for d in yaml.safe_load_all((ROOT / path).read_text()) if d]


def compose():
    return yaml.safe_load((ROOT / COMPOSE).read_text())


def _bytes(value):
    """128Mi, 128m, 16Mi, 16m -> bytes. Kubernetes and Compose spell the same ceiling differently."""
    match = re.fullmatch(r"(\d+)\s*([KMG])i?", str(value).strip(), re.IGNORECASE)
    if not match:
        raise AssertionError(f"unparseable size: {value!r}")
    return int(match.group(1)) * {"k": 1024, "m": 1024 ** 2, "g": 1024 ** 3}[match.group(2).lower()]


def _normalised_args(args):
    return [API_SENTINEL if API_BASE.match(a) else a for a in args]


def _kube_container(path, object_name):
    (doc,) = [d for d in documents(path) if d.get("metadata", {}).get("name") == object_name
              and d["kind"] in ("CronJob", "Job")]
    spec = doc["spec"]
    if doc["kind"] == "CronJob":
        spec = spec["jobTemplate"]["spec"]
    pod = spec["template"]["spec"]
    (container,) = pod["containers"]
    return doc, pod, container


# The registries a target may name, and nothing else: podman and compose a local build, k3s
# the release on GHCR (ADR-0030). Only these prefixes are removed, so two images that differ
# anywhere else in their repository path still compare unequal.
REGISTRIES = ("localhost/", "ghcr.io/datagen24/")


def _image_without_registry(image):
    for registry in REGISTRIES:
        if image.startswith(registry):
            return image[len(registry):]
    return image


def _kube_descriptor(path, object_name):
    doc, pod, container = _kube_container(path, object_name)
    security = pod["securityContext"]
    tmp = [v for v in pod["volumes"] if v["name"] == "tmp"][0]
    return {
        "image": _image_without_registry(container["image"]),
        "args": _normalised_args(container["args"]),
        "user": f"{security['runAsUser']}:{security['runAsGroup']}",
        "read_only_root": container["securityContext"]["readOnlyRootFilesystem"],
        "no_new_privileges": container["securityContext"]["allowPrivilegeEscalation"] is False,
        "cap_drop": sorted(container["securityContext"]["capabilities"]["drop"]),
        "memory_bytes": _bytes(container["resources"]["limits"]["memory"]),
        "tmpfs_bytes": _bytes(tmp["emptyDir"]["sizeLimit"]),
        "secret_mount": [m["mountPath"] for m in container["volumeMounts"] if m["name"] == "credentials"],
    }


def _compose_descriptor(service_name):
    service = compose()["services"][service_name]
    (tmpfs,) = service["tmpfs"]
    size = re.search(r"size=(\d+[kmgKMG])", tmpfs).group(1)
    return {
        "image": _image_without_registry(service["image"]),
        "args": _normalised_args(service["command"]),
        "user": service["user"],
        "read_only_root": service["read_only"],
        "no_new_privileges": "no-new-privileges:true" in service["security_opt"],
        "cap_drop": sorted(service["cap_drop"]),
        "memory_bytes": _bytes(service["mem_limit"]),
        "tmpfs_bytes": _bytes(size),
        # Compose mounts a secret at /run/secrets/<name>; the kube files mount the whole
        # directory. Both put the key where the --key-file argument says it is, and that
        # argument is compared above, so the mount point is the constant rather than the list.
        "secret_mount": ["/run/secrets"],
    }


class LabelWorkloadParityTest(unittest.TestCase):
    def test_the_three_files_describe_the_same_two_workloads(self):
        for label, (object_name, service_name) in WORKLOADS.items():
            k3s = _kube_descriptor(K3S, object_name)
            podman = _kube_descriptor(PODMAN, object_name)
            compose_side = _compose_descriptor(service_name)
            self.assertEqual(k3s, podman, f"{label}: k3s and podman disagree")
            self.assertEqual(k3s, compose_side, f"{label}: k3s and compose disagree")

    def test_each_target_uses_the_kind_its_platform_supports(self):
        kinds = {d["metadata"]["name"]: d["kind"] for d in documents(K3S) if "kind" in d}
        for object_name, _ in WORKLOADS.values():
            self.assertEqual(kinds[object_name], "CronJob", "Kubernetes schedules its own runs")
        kinds = {d["metadata"]["name"]: d["kind"] for d in documents(PODMAN) if "kind" in d}
        for object_name, _ in WORKLOADS.values():
            # podman kube play supports Pods, Deployments, DaemonSets, Jobs and PVCs, and
            # skips anything else silently. A CronJob here deploys nothing and exits 0.
            self.assertEqual(kinds[object_name], "Job", "podman kube play cannot run a CronJob")

    def test_no_target_deploys_the_worker_as_a_resident_workload(self):
        """The claim loop exits when the queue is empty; a restart policy turns that into a loop."""
        for path in (K3S, PODMAN):
            for doc in documents(path):
                self.assertNotIn(doc.get("kind"), ("Deployment", "StatefulSet", "DaemonSet"),
                                 f"{path}: {doc.get('metadata', {}).get('name')} is resident")
        for name, service in compose()["services"].items():
            self.assertNotIn("restart", service,
                             f"compose/{name}: a restart policy would loop on every drained queue")
            self.assertIn("manual", service["profiles"],
                          f"compose/{name}: must not start under `docker compose up`")

    def test_the_podman_target_carries_only_the_differences_it_needs(self):
        for object_name, _ in WORKLOADS.values():
            _, _, podman = _kube_container(PODMAN, object_name)
            _, _, k3s = _kube_container(K3S, object_name)
            self.assertEqual(podman["imagePullPolicy"], "Never", "images are loaded locally")
            self.assertNotIn("imagePullPolicy", k3s, "a cluster pulls from wherever it is told")

    def test_the_worker_declares_no_probe_on_any_target(self):
        """A probe on a container meant to terminate reports the end of the work as a failure."""
        for path in (K3S, PODMAN):
            _, _, container = _kube_container(path, "victual-label-worker")
            for probe in ("startupProbe", "livenessProbe", "readinessProbe"):
                self.assertNotIn(probe, container, f"{path}: the worker runs to completion")
        self.assertNotIn("healthcheck", compose()["services"]["worker"])


if __name__ == "__main__":
    unittest.main()
