"""The Helm chart at deploy/helm/victual/ meets ADR-0038's acceptance prerequisites 1 to 3.

1. Rendered with ci/k3s-values.yaml, it is the committed deploy/k3s/, which the parity tests
   and check_deploy_manifest.py read unchanged; a hand edit there fails this test.
2. Every values file in ci/ renders, hooks included, and passes check_deploy_manifest.py;
   each file in ci/negative/ fails it, which proves the check is looking.
3. values.example.yaml, unedited, is refused at install with the CHANGE-ME field's path.

The rest port deploy/production/render.py's tests (one Secret per credential, plan 36's item
layout) and its refusals, now values.schema.json's.

Helm is required. CI installs a pinned version (tests.yml, the lint job); without Helm this
file skips locally and fails under CI.
"""
import copy
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import yaml

import render_k3s
from check_deploy_manifest import validate

ROOT = Path(__file__).resolve().parents[2]
CHART = ROOT / "deploy/helm/victual"
MATRIX = sorted((CHART / "ci").glob("*.yaml"))
NEGATIVE = sorted((CHART / "ci/negative").glob("*.yaml"))
DB_CREDENTIAL = {"VICTUAL_DB_USER", "VICTUAL_DB_PASSWORD"}

HELM = shutil.which("helm")
if not HELM and os.environ.get("CI"):
    raise RuntimeError("helm is not installed; the lint job installs it before the CI scripts' tests")
needs_helm = unittest.skipUnless(HELM, "helm is not installed")


def helm(*args):
    # No kubeconfig: nothing here may reach a cluster, and the CI runner has none to reach.
    env = dict(os.environ, KUBECONFIG=os.devnull)
    return subprocess.run(["helm", *args], capture_output=True, text=True, env=env)


def render(values, *, hooks=True):
    """The chart's documents for a values file or a dict of values."""
    with tempfile.TemporaryDirectory() as tmp:
        if isinstance(values, dict):
            path = Path(tmp) / "values.yaml"
            path.write_text(yaml.safe_dump(values))
        else:
            path = values
        stream = render_k3s.helm_template(path, hooks=hooks)
    return [d for d in yaml.safe_load_all(stream) if d]


def refusal(values):
    """Helm's error for values the schema refuses; fails the test if it renders."""
    with tempfile.TemporaryDirectory() as tmp:
        path = Path(tmp) / "values.yaml"
        path.write_text(yaml.safe_dump(values))
        result = helm("template", "victual", str(CHART), "-f", str(path))
    if result.returncode == 0:
        raise AssertionError(f"rendered, but should have been refused: {values}")
    return result.stderr


def by_kind(docs, kind):
    return {d["metadata"]["name"]: d for d in docs if d["kind"] == kind}


def app_container(docs):
    deployment = by_kind(docs, "Deployment")["victual"]
    (app,) = [c for c in deployment["spec"]["template"]["spec"]["containers"] if c["name"] == "app"]
    return app


class ChartVersionTest(unittest.TestCase):
    def test_the_chart_version_is_version_json(self):
        """ADR-0038 decision 4: one version for the chart, the application and the images."""
        version = json.loads((ROOT / "version.json").read_text())["Version"]
        chart = yaml.safe_load((CHART / "Chart.yaml").read_text())
        self.assertEqual(chart["version"], version)
        self.assertEqual(chart["appVersion"], version)


@needs_helm
class RenderedBaseTest(unittest.TestCase):
    """Prerequisite 1: deploy/k3s/ is the chart's rendering, and nothing else."""

    def test_deploy_k3s_is_the_chart_rendered(self):
        rendered = render_k3s.files(render_k3s.helm_template())
        diff = render_k3s.differences(rendered, render_k3s.committed())
        self.assertEqual(diff, "", "deploy/k3s/ differs from the chart; run python3 .devtools/ci/render_k3s.py")

    def test_a_hand_edit_is_reported(self):
        rendered = render_k3s.files(render_k3s.helm_template())
        edited = dict(render_k3s.committed())
        edited["victual.yaml"] = edited["victual.yaml"].replace("replicas: 1", "replicas: 2", 1)
        self.assertNotEqual(edited, render_k3s.committed(), "the edit did not apply")
        self.assertIn("+  replicas: 1", render_k3s.differences(rendered, edited))

    def test_a_stray_file_is_reported(self):
        rendered = render_k3s.files(render_k3s.helm_template())
        extra = dict(render_k3s.committed(), **{"stray.yaml": "kind: ConfigMap\n"})
        self.assertIn("deploy/k3s/stray.yaml", render_k3s.differences(rendered, extra))

    def test_the_base_renders_no_hook(self):
        """Decision 2 renders with --no-hooks; decision 9's hooks would show here."""
        hooked = [
            d for d in render(CHART / "ci/k3s-values.yaml", hooks=True)
            if "helm.sh/hook" in (d["metadata"].get("annotations") or {})
        ]
        self.assertEqual(hooked, [], "a hook is in the k3s values' rendering: is it --no-hooks?")


@needs_helm
class ValuesMatrixTest(unittest.TestCase):
    """Prerequisite 2: what can ship passes ADR-0010's manifest check, not only the base."""

    def test_every_matrix_entry_renders_and_passes(self):
        self.assertGreater(len(MATRIX), 1)
        for path in MATRIX:
            with self.subTest(path.name):
                errors = [e for d in render(path) for e in validate(d)]
                self.assertEqual(errors, [])

    def test_every_negative_control_fails(self):
        self.assertTrue(NEGATIVE, "ci/negative/ is empty: nothing shows the check can fail")
        for path in NEGATIVE:
            with self.subTest(path.name):
                errors = [e for d in render(path) for e in validate(d)]
                self.assertNotEqual(errors, [], f"{path.name} passed check_deploy_manifest.py")

    def test_the_matrix_enables_each_optional_part_and_each_secrets_mode(self):
        """Decision 3: every optional workload and every secrets mode renders at least once."""
        sources, seen = set(), set()
        for path in MATRIX:
            values = yaml.safe_load(path.read_text())
            sources.add(((values.get("secrets") or {}).get("source")) or "placeholder")
            for doc in render(path):
                seen.add((doc["kind"], doc["metadata"]["name"]))
        self.assertEqual(sources, {"placeholder", "inline", "onepassword", "existing"})
        for wanted in [
            ("Deployment", "victual-mcp"),
            ("CronJob", "victual-label-renderer"),
            ("CronJob", "victual-label-worker"),
            ("Ingress", "victual"),
            ("OnePasswordItem", "victual-db-migrate"),
            ("Secret", "victual-mqtt"),
            ("Secret", "victual-influxdb"),
            ("Job", "victual-upgrade-preflight"),
            ("Job", "victual-publish-state"),
        ]:
            self.assertIn(wanted, seen)


def hooks(docs):
    """{name: Job} for the documents Helm runs as hooks."""
    return {
        d["metadata"]["name"]: d for d in docs
        if "helm.sh/hook" in (d["metadata"].get("annotations") or {})
    }


def pod_spec(doc):
    spec = doc["spec"]
    if doc["kind"] == "CronJob":
        spec = spec["jobTemplate"]["spec"]
    return spec["template"]["spec"]


def secret_readers(docs, secret):
    """(kind, name, container) for every container that can read the Secret `secret`."""
    readers = set()
    for doc in docs:
        if doc["kind"] not in {"Deployment", "Job", "CronJob", "Pod", "StatefulSet", "DaemonSet"}:
            continue
        spec = pod_spec(doc)
        mounted = {v["name"] for v in spec.get("volumes") or []
                   if (v.get("secret") or {}).get("secretName") == secret}
        for container in (spec.get("initContainers") or []) + (spec.get("containers") or []):
            names = {r["secretRef"]["name"] for r in container.get("envFrom") or [] if "secretRef" in r}
            names |= {e["valueFrom"]["secretKeyRef"]["name"] for e in container.get("env") or []
                      if "secretKeyRef" in (e.get("valueFrom") or {})}
            if secret in names or mounted & {m["name"] for m in container.get("volumeMounts") or []}:
                readers.add((doc["kind"], doc["metadata"]["name"], container["name"]))
    return readers


@needs_helm
class HookJobTest(unittest.TestCase):
    """ADR-0038 decision 9: the preflight before an upgrade, MQTT publication after one."""

    PREFLIGHT = "victual-upgrade-preflight"
    PUBLISH = "victual-publish-state"

    def test_the_migrate_secret_has_two_readers(self):
        """Consequences: the preflight Job and the migrate initContainer, and nothing else.

        test_deploy_pod_parity.py checks the pod; this checks everything the chart renders,
        hooks included, for every values file in the matrix.
        """
        for path in MATRIX:
            with self.subTest(path.name):
                values = yaml.safe_load(path.read_text()) or {}
                name = ((values.get("secrets") or {}).get("names") or {}).get("migrate", "victual-db-migrate")
                expected = {("Deployment", "victual", "migrate")}
                if (values.get("upgradePreflight") or {}).get("enabled", True):
                    expected.add(("Job", self.PREFLIGHT, "preflight"))
                self.assertEqual(secret_readers(render(path), name), expected)

    def test_the_preflight_runs_before_an_upgrade_from_the_migrate_image(self):
        job = hooks(render(CHART / "ci/inline-no-features.yaml"))[self.PREFLIGHT]
        annotations = job["metadata"]["annotations"]
        self.assertEqual(annotations["helm.sh/hook"], "pre-upgrade")
        # The refusal's report is the Job's log: a failed hook must not be deleted.
        self.assertNotIn("hook-failed", annotations.get("helm.sh/hook-delete-policy", ""))
        # One run: a retry would repeat the refusal and hide the exit code behind a backoff.
        self.assertEqual(job["spec"]["backoffLimit"], 0)
        (container,) = pod_spec(job)["containers"]
        self.assertEqual(container["image"].rsplit(":", 1)[0], "ghcr.io/datagen24/victual-migrate")
        self.assertEqual(container["command"], ["/opt/victual/php", "bin/victual-timestamp-preflight"])

    def test_the_preflight_can_be_turned_off(self):
        values = filled_inline(False)
        values["upgradePreflight"] = {"enabled": False}
        self.assertNotIn(self.PREFLIGHT, hooks(render(values)))

    def test_publication_runs_after_install_and_upgrade_only_with_mqtt(self):
        self.assertNotIn(self.PUBLISH, hooks(render(filled_inline(False))))
        job = hooks(render(filled_inline(True)))[self.PUBLISH]
        self.assertEqual(job["metadata"]["annotations"]["helm.sh/hook"], "post-install,post-upgrade")
        (container,) = pod_spec(job)["containers"]
        self.assertEqual(container["image"].rsplit(":", 1)[0], "ghcr.io/datagen24/victual-app")
        self.assertEqual(container["command"], ["/opt/victual/php", "bin/victual-publish-state"])

    def test_publication_holds_what_the_app_container_holds(self):
        """The app role, and the broker's Secret required exactly when the app's is."""
        for values in (filled_inline(True), onepassword(True), CHART / "ci/hooks-inline-anonymous-mqtt.yaml"):
            docs = render(values)
            (container,) = pod_spec(hooks(docs)[self.PUBLISH])["containers"]
            self.assertEqual(container["envFrom"], app_container(docs)["envFrom"])

    def test_no_hook_pod_is_selected_by_the_service(self):
        """The Service selects app.kubernetes.io/name: victual; a hook pod answering it would be wrong."""
        docs = render(CHART / "ci/inline-all-features.yaml")
        selector = by_kind(docs, "Service")["victual"]["spec"]["selector"]
        for name, job in hooks(docs).items():
            with self.subTest(name):
                labels = job["spec"]["template"]["metadata"]["labels"]
                self.assertFalse(selector.items() <= labels.items())


@needs_helm
class ExampleValuesTest(unittest.TestCase):
    """Prerequisite 3: the example is refused at install until every CHANGE-ME is filled."""

    EXAMPLE = CHART / "values.example.yaml"

    @staticmethod
    def placeholders(node, path=""):
        if isinstance(node, dict):
            for key, value in node.items():
                yield from ExampleValuesTest.placeholders(value, f"{path}/{key}")
        elif isinstance(node, str) and "CHANGE-ME" in node:
            yield path

    def test_install_with_the_example_unedited_names_a_change_me_field(self):
        # --dry-run=client validates exactly as an install does, without a cluster.
        result = helm(
            "install", "victual", str(CHART), "-f", str(self.EXAMPLE), "--dry-run=client",
        )
        self.assertNotEqual(result.returncode, 0)
        fields = list(self.placeholders(yaml.safe_load(self.EXAMPLE.read_text())))
        self.assertTrue(fields)
        named = [f for f in fields if f"at '{f}'" in result.stderr]
        self.assertTrue(named, f"no CHANGE-ME field named in:\n{result.stderr}")

    def test_the_example_with_its_placeholders_filled_renders_and_passes(self):
        text = self.EXAMPLE.read_text().replace("CHANGE-ME", "filled")
        docs = render(yaml.safe_load(text))
        self.assertEqual([e for d in docs for e in validate(d)], [])
        self.assertIn("victual", by_kind(docs, "Ingress"))


def filled_inline(features):
    """Inline secrets, with MQTT and InfluxDB on or off: render.py's test matrix."""
    values = {
        "baseUrl": "https://victual.example.com",
        "database": {"host": "postgres.example.internal"},
        "mqtt": {"enabled": features, "host": "mqtt.example.internal", "username": "victual" if features else ""},
        "influxdb": {"enabled": features, "url": "http://influxdb.example.internal:8086"},
        "labelWorkers": {"enabled": False},
        "secrets": {"source": "inline", "inline": {
            "migratePassword": "m", "appPassword": "a", "bootstrapAdminPassword": "b",
        }},
    }
    if features:
        values["secrets"]["inline"].update(mqttPassword="mq", influxdbToken="it")
    return values


def onepassword(features):
    values = filled_inline(features)
    values["secrets"] = {"source": "onepassword", "onepassword": {"vault": "household"}}
    return values


@needs_helm
class SecretPerCredentialTest(unittest.TestCase):
    """deploy/production/render.py's tests, against the chart (plan 36, "Item layout")."""

    def test_inline_secrets_hold_one_credential_each(self):
        for features in (False, True):
            secrets = {n: set(d["stringData"]) for n, d in by_kind(render(filled_inline(features)), "Secret").items()}
            expected = {
                "victual-db-migrate": DB_CREDENTIAL,
                "victual-db-app": DB_CREDENTIAL,
                "victual-bootstrap-admin": {"VICTUAL_BOOTSTRAP_ADMIN_PASSWORD"},
            }
            if features:
                expected["victual-mqtt"] = {"VICTUAL_MQTT_PASSWORD"}
                expected["victual-influxdb"] = {"VICTUAL_INFLUXDB_TOKEN"}
            self.assertEqual(secrets, expected, f"features={features}")

    def test_onepassword_has_one_item_per_credential_and_no_secret(self):
        for features in (False, True):
            docs = render(onepassword(features))
            items = by_kind(docs, "OnePasswordItem")
            expected = {"victual-db-migrate", "victual-db-app", "victual-bootstrap-admin"}
            if features:
                expected |= {"victual-mqtt", "victual-influxdb"}
            self.assertEqual(set(items), expected, f"features={features}")
            self.assertEqual(
                items["victual-db-app"]["spec"]["itemPath"], "vaults/household/items/victual-db-app",
            )
            # Nothing for an apply to overwrite what the Connect operator writes.
            self.assertEqual(by_kind(docs, "Secret"), {}, f"features={features}")

    def test_existing_secrets_are_named_and_not_rendered(self):
        docs = render(CHART / "ci/existing-secrets.yaml")
        self.assertEqual(by_kind(docs, "Secret"), {})
        self.assertEqual(by_kind(docs, "OnePasswordItem"), {})
        refs = {r["secretRef"]["name"] for r in app_container(docs)["envFrom"] if "secretRef" in r}
        self.assertEqual(refs, {"vault-victual-app", "vault-victual-mqtt", "vault-victual-influxdb"})

    def test_enabled_features_make_their_secret_required(self):
        for values in (filled_inline(True), onepassword(True)):
            refs = {r["secretRef"]["name"]: r["secretRef"].get("optional")
                    for r in app_container(render(values))["envFrom"] if "secretRef" in r}
            self.assertIs(refs["victual-mqtt"], False)
            self.assertIs(refs["victual-influxdb"], False)
        refs = {r["secretRef"]["name"]: r["secretRef"].get("optional")
                for r in app_container(render(filled_inline(False)))["envFrom"] if "secretRef" in r}
        self.assertIs(refs["victual-mqtt"], True)
        self.assertIs(refs["victual-influxdb"], True)

    def test_anonymous_mqtt_needs_no_secret(self):
        values = filled_inline(True)
        values["mqtt"]["username"] = ""
        docs = render(values)
        self.assertNotIn("victual-mqtt", by_kind(docs, "Secret"))
        refs = {r["secretRef"]["name"]: r["secretRef"].get("optional")
                for r in app_container(docs)["envFrom"] if "secretRef" in r}
        self.assertIs(refs["victual-mqtt"], True)


@needs_helm
class SchemaRefusalTest(unittest.TestCase):
    """render.py's refusals, now values.schema.json's: each names the field."""

    def refused(self, values, field):
        self.assertIn(f"at '{field}'", refusal(values))

    def test_a_placeholder_is_refused_wherever_it_is(self):
        values = filled_inline(False)
        values["database"]["host"] = "CHANGE-ME-postgres"
        self.refused(values, "/database/host")

    def test_inline_without_a_database_password_is_refused(self):
        values = filled_inline(False)
        values["secrets"]["inline"]["appPassword"] = ""
        self.refused(values, "/secrets/inline/appPassword")

    def test_an_enabled_feature_without_its_password_is_refused(self):
        values = filled_inline(True)
        del values["secrets"]["inline"]["influxdbToken"]
        self.refused(values, "/secrets/inline/influxdbToken")

    def test_onepassword_without_the_bootstrap_item_is_refused(self):
        values = onepassword(False)
        values["secrets"]["onepassword"]["items"] = {"bootstrapAdmin": None}
        self.refused(values, "/secrets/onepassword/items")

    def test_mqtt_enabled_without_a_host_is_refused(self):
        values = filled_inline(False)
        values["mqtt"] = {"enabled": True, "host": ""}
        self.refused(values, "/mqtt/host")

    def test_no_base_url_without_an_ingress_is_refused(self):
        values = filled_inline(False)
        values["baseUrl"] = ""
        self.refused(values, "/baseUrl")

    def test_reserved_and_malformed_settings_are_refused(self):
        for name, field in (("DB_HOST", "/settings/DB_HOST"), ("VICTUAL_CURRENCY", "/settings/VICTUAL_CURRENCY"),
                            ("BASE_PATH", "/settings/BASE_PATH"), ("currency", "/settings")):
            with self.subTest(name):
                values = filled_inline(False)
                values["settings"] = {name: "x"}
                self.refused(values, field)

    def test_a_list_setting_is_refused(self):
        values = filled_inline(False)
        values["settings"] = {"CURRENCY": ["USD", "EUR"]}
        self.refused(values, "/settings/CURRENCY")

    def test_an_unknown_section_is_refused(self):
        """deploy/production's `cluster:` section has no meaning to a chart: Helm's flags own it."""
        values = filled_inline(False)
        values["cluster"] = {"namespace": "victual"}
        self.refused(values, "")

    def test_an_unknown_secrets_source_is_refused(self):
        values = copy.deepcopy(filled_inline(False))
        values["secrets"]["source"] = "vault"
        self.refused(values, "/secrets/source")


if __name__ == "__main__":
    unittest.main()
