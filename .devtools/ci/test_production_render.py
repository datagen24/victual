"""deploy/production/render.py keeps one Secret per credential.

Plan 36's item layout: each database Secret (and its 1Password item) holds VICTUAL_DB_USER
and VICTUAL_DB_PASSWORD and nothing else, because the credential rotation handler rewrites
the item whole and `op` has no compare-and-swap. The bootstrap administrator's password, the
MQTT password and the InfluxDB token each get a Secret of their own. This checks what the
renderer writes, without kustomize: the files under build/ are the overlay.
"""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
DB_CREDENTIAL = {"VICTUAL_DB_USER", "VICTUAL_DB_PASSWORD"}

spec = importlib.util.spec_from_file_location("render", ROOT / "deploy/production/render.py")
render = importlib.util.module_from_spec(spec)
spec.loader.exec_module(render)


def filled(node):
    """values.example.yaml with every CHANGE-ME replaced, so it renders."""
    if isinstance(node, dict):
        return {k: filled(v) for k, v in node.items()}
    if isinstance(node, str) and render.PLACEHOLDER in node:
        return node.replace(render.PLACEHOLDER, "x")
    return node


EXAMPLE = filled(yaml.safe_load((ROOT / "deploy/production/values.example.yaml").read_text()))


def values(source, features):
    v = copy.deepcopy(EXAMPLE)
    v["secrets"]["source"] = source
    v["mqtt"]["enabled"] = features
    v["influxdb"]["enabled"] = features
    if features:
        v["mqtt"]["username"] = "victual"
        v["secrets"]["inline"]["mqttPassword"] = "mqtt-password"
        v["secrets"]["inline"]["influxdbToken"] = "influxdb-token"
    return v


def rendered(v):
    """Every document the overlay writes, and the overlay's kustomization and deploy.env."""
    with tempfile.TemporaryDirectory() as out:
        out = Path(out) / "build"
        env = render.render(v, out)
        kustomization = yaml.safe_load((out / "kustomization.yaml").read_text())
        docs = []
        for path in out.glob("*.yaml"):
            if path.name != "kustomization.yaml":
                docs += [d for d in yaml.safe_load_all(path.read_text()) if d]
        return docs, kustomization, env


class ProductionRenderTest(unittest.TestCase):
    def test_inline_secrets_hold_one_credential_each(self):
        for features in (False, True):
            docs, _, env = rendered(values("inline", features))
            secrets = {d["metadata"]["name"]: set(d["stringData"]) for d in docs if d["kind"] == "Secret"}
            expected = {
                "victual-db-migrate": DB_CREDENTIAL,
                "victual-db-app": DB_CREDENTIAL,
                "victual-bootstrap-admin": {"VICTUAL_BOOTSTRAP_ADMIN_PASSWORD"},
            }
            if features:
                expected["victual-mqtt"] = {"VICTUAL_MQTT_PASSWORD"}
                expected["victual-influxdb"] = {"VICTUAL_INFLUXDB_TOKEN"}
            self.assertEqual(secrets, expected, f"features={features}")
            self.assertEqual(env["OPERATOR_SECRETS"], "")

    def test_onepassword_has_one_item_per_credential(self):
        for features in (False, True):
            docs, _, env = rendered(values("onepassword", features))
            items = {d["metadata"]["name"]: d["spec"]["itemPath"] for d in docs if d["kind"] == "OnePasswordItem"}
            expected = ["victual-db-migrate", "victual-db-app", "victual-bootstrap-admin"]
            if features:
                expected += ["victual-mqtt", "victual-influxdb"]
            self.assertEqual(sorted(items), sorted(expected), f"features={features}")
            self.assertEqual(env["OPERATOR_SECRETS"].split(), expected)
            # The base's placeholders are deleted, so an apply cannot overwrite the operator.
            deleted = {d["metadata"]["name"] for d in docs if d.get("$patch") == "delete"}
            self.assertEqual(deleted, {"victual-db-migrate", "victual-db-app", "victual-bootstrap-admin"})

    def test_enabled_features_make_their_secret_required(self):
        for source in ("inline", "onepassword"):
            _, kustomization, _ = rendered(values(source, True))
            (patch,) = [p for p in kustomization["patches"] if p.get("target", {}).get("kind") == "Deployment"]
            ops = yaml.safe_load(patch["patch"])
            tested = [op["value"] for op in ops if op["op"] == "test"]
            self.assertEqual(tested, ["victual-mqtt", "victual-influxdb"], source)
            self.assertTrue(all(op["value"] is False for op in ops if op["op"] == "replace"))

            _, kustomization, _ = rendered(values(source, False))
            self.assertFalse([p for p in kustomization["patches"] if "target" in p], source)

    def test_anonymous_mqtt_needs_no_secret(self):
        v = values("inline", True)
        v["mqtt"]["username"] = ""
        docs, _, _ = rendered(v)
        self.assertNotIn("victual-mqtt", {d["metadata"]["name"] for d in docs if d["kind"] == "Secret"})

    def test_a_values_file_without_the_bootstrap_item_is_refused(self):
        v = values("onepassword", False)
        del v["secrets"]["onepassword"]["bootstrapAdminItem"]
        with self.assertRaisesRegex(render.ValuesError, "bootstrapAdminItem: missing"):
            rendered(v)


if __name__ == "__main__":
    unittest.main()
