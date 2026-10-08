"""The two Victual pod manifests describe one pod, and only one container holds the migrate role.

deploy/podman/victual.yaml is a bare Pod for `podman kube play`; deploy/k3s/victual.yaml is
the same pod as a Deployment. Two files that are meant to be one thing drift the first time
somebody fixes a probe in one of them, so this compares what the pod *is* - its containers,
probes, limits, security context, credentials and volumes - and allows only the differences
each target needs: a laptop wants `hostPort` and `imagePullPolicy: Never`, a cluster wants an
`os` for `lifecycle.stopSignal`, and the two name the same image in different registries -
podman a local build under `localhost/`, k3s the release on GHCR (ADR-0030). The image name
and tag must still agree.

The credential half is plan 20 verification 8 at manifest level: the migrate role's Secret
may be named by the migrate initContainer and by nothing else. Plan 36's item layout adds the
rest: each database Secret holds the database credential and nothing else, and the bootstrap
administrator's, MQTT and InfluxDB Secrets are each named only by the container that reads
them. check_deploy_manifest.py
cannot say that, because it asks what a container declares and not which credential it
holds, and ADR-0010's open question 2 names "is this credential scoped to the job" as review's
question - the half of it a script can answer is which containers can read which Secret.
"""
import copy
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
MIGRATE_SECRET = "victual-db-migrate"
APP_SECRET = "victual-db-app"
BOOTSTRAP_SECRET = "victual-bootstrap-admin"
MQTT_SECRET = "victual-mqtt"
INFLUX_SECRET = "victual-influxdb"
DB_CREDENTIAL = {"VICTUAL_DB_USER", "VICTUAL_DB_PASSWORD"}


def documents(path):
    return [d for d in yaml.safe_load_all((ROOT / path).read_text()) if d]


def podman_spec():
    (pod,) = [d for d in documents("deploy/podman/victual.yaml") if d["kind"] == "Pod"]
    return pod["spec"]


def k3s_spec():
    (deployment,) = [d for d in documents("deploy/k3s/victual.yaml") if d["kind"] == "Deployment"]
    return deployment["spec"]["template"]["spec"]


# The registries a target may name, and nothing else: podman and compose a local build, k3s
# the release on GHCR (ADR-0030). Only these prefixes are removed, so two images that differ
# anywhere else in their repository path still compare unequal.
REGISTRIES = ("localhost/", "ghcr.io/datagen24/")


def image_without_registry(image):
    for registry in REGISTRIES:
        if image.startswith(registry):
            return image[len(registry):]
    return image


def normalised(spec):
    """The pod spec with the differences the two targets legitimately have removed."""
    spec = copy.deepcopy(spec)
    spec.pop("os", None)
    spec.pop("restartPolicy", None)
    for container in spec.get("initContainers", []) + spec.get("containers", []):
        container.pop("imagePullPolicy", None)
        container["image"] = image_without_registry(container["image"])
        for port in container.get("ports", []):
            port.pop("hostPort", None)
    return spec


def secrets_named(container):
    return {
        ref["secretRef"]["name"]
        for ref in container.get("envFrom", [])
        if "secretRef" in ref
    } | {
        env["valueFrom"]["secretKeyRef"]["name"]
        for env in container.get("env", [])
        if "secretKeyRef" in env.get("valueFrom", {})
    }


def optional_secrets(container):
    return {
        ref["secretRef"]["name"]
        for ref in container.get("envFrom", [])
        if ref.get("secretRef", {}).get("optional")
    }


class PodParityTest(unittest.TestCase):
    def test_the_two_manifests_describe_the_same_pod(self):
        self.assertEqual(normalised(podman_spec()), normalised(k3s_spec()))

    def test_each_target_names_its_own_registry(self):
        for target, spec, registry in (
            ("podman", podman_spec(), "localhost/"),
            ("k3s", k3s_spec(), "ghcr.io/datagen24/"),
        ):
            for container in spec.get("initContainers", []) + spec["containers"]:
                self.assertTrue(container["image"].startswith(registry), f"{target}: {container['image']}")

    def test_the_cluster_pod_names_an_os_for_stop_signal(self):
        # lifecycle.stopSignal is refused for a pod that does not say what OS it is for
        self.assertEqual(k3s_spec()["os"]["name"], "linux")


class CredentialSplitTest(unittest.TestCase):
    def specs(self):
        return {"podman": podman_spec(), "k3s": k3s_spec()}

    def test_each_secret_is_named_by_its_one_consumer(self):
        expected = {
            MIGRATE_SECRET: ["migrate"],
            BOOTSTRAP_SECRET: ["migrate"],
            APP_SECRET: ["app"],
            MQTT_SECRET: ["app"],
            INFLUX_SECRET: ["app"],
        }
        for target, spec in self.specs().items():
            for secret, consumers in expected.items():
                holders = [
                    c["name"]
                    for c in spec.get("initContainers", []) + spec.get("containers", [])
                    if secret in secrets_named(c)
                ]
                self.assertEqual(holders, consumers, f"{target}: {secret} is held by {holders}")

    def test_each_container_holds_exactly_its_secrets(self):
        for target, spec in self.specs().items():
            (migrate,) = spec["initContainers"]
            self.assertEqual(secrets_named(migrate), {MIGRATE_SECRET, BOOTSTRAP_SECRET}, target)
            by_name = {c["name"]: secrets_named(c) for c in spec["containers"]}
            self.assertEqual(by_name["app"], {APP_SECRET, MQTT_SECRET, INFLUX_SECRET}, target)
            self.assertEqual(by_name["web"], set(), f"{target}: the web tier holds no credential")

    def test_only_the_mqtt_and_influxdb_secrets_are_optional(self):
        # A deployment without MQTT or InfluxDB creates neither Secret. The database and
        # bootstrap Secrets stay required: a pod that started before the bootstrap Secret
        # existed would seed a generated admin password in place of the operator's.
        for target, spec in self.specs().items():
            optional = set()
            for c in spec.get("initContainers", []) + spec["containers"]:
                optional |= optional_secrets(c)
            self.assertEqual(optional, {MQTT_SECRET, INFLUX_SECRET}, target)

    def test_the_db_user_is_not_in_a_configmap_where_it_could_shadow_a_secret(self):
        for doc in documents("deploy/k3s/victual.yaml"):
            if doc["kind"] == "ConfigMap":
                self.assertNotIn("VICTUAL_DB_USER", doc.get("data", {}))

    def test_each_secret_names_its_own_role(self):
        by_name = {d["metadata"]["name"]: d for d in documents("deploy/k3s/victual.yaml") if d["kind"] == "Secret"}
        self.assertEqual(by_name[MIGRATE_SECRET]["stringData"]["VICTUAL_DB_USER"], "victual_migrate")
        self.assertEqual(by_name[APP_SECRET]["stringData"]["VICTUAL_DB_USER"], "victual_app")

    def test_the_database_secrets_hold_the_database_credential_and_nothing_else(self):
        # Plan 36, "Item layout": the rotation handler rewrites a database item whole, and
        # `op` has no compare-and-swap, so a sibling field there could be lost.
        by_name = {d["metadata"]["name"]: d for d in documents("deploy/k3s/victual.yaml") if d["kind"] == "Secret"}
        for name in (MIGRATE_SECRET, APP_SECRET):
            self.assertEqual(set(by_name[name]["stringData"]), DB_CREDENTIAL, name)

    def test_the_base_bootstrap_password_is_blank(self):
        # Blank is unset to InitialDataSeeder: a generated password and a forced change. Any
        # literal here would be the admin password of every deployment that forgot it.
        by_name = {d["metadata"]["name"]: d for d in documents("deploy/k3s/victual.yaml") if d["kind"] == "Secret"}
        self.assertEqual(by_name[BOOTSTRAP_SECRET]["stringData"], {"VICTUAL_BOOTSTRAP_ADMIN_PASSWORD": ""})


if __name__ == "__main__":
    unittest.main()
