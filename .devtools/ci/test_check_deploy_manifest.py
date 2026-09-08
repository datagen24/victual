from pathlib import Path
import unittest

import yaml

from check_deploy_manifest import validate

GOOD_CONTAINER = {
    "name": "app",
    "securityContext": {
        "readOnlyRootFilesystem": True,
        "allowPrivilegeEscalation": False,
        "capabilities": {"drop": ["ALL"]},
    },
    "resources": {"limits": {"memory": "512Mi"}},
    "livenessProbe": {"exec": {"command": ["/opt/victual/healthcheck"]}},
}

GOOD_INIT_CONTAINER = {
    "name": "migrate",
    "securityContext": GOOD_CONTAINER["securityContext"],
    "resources": {"limits": {"memory": "256Mi"}},
}


RUN_TO_COMPLETION_CONTAINER = {k: v for k, v in GOOD_CONTAINER.items() if k != "livenessProbe"}


def pod(containers=None, init_containers=None):
    return {
        "kind": "Pod",
        "spec": {
            "containers": containers if containers is not None else [GOOD_CONTAINER],
            "initContainers": init_containers if init_containers is not None else [GOOD_INIT_CONTAINER],
        },
    }


def job(containers=None):
    return {
        "kind": "Job",
        "metadata": {"name": "render"},
        "spec": {
            "template": {
                "spec": {
                    "containers": containers if containers is not None else [RUN_TO_COMPLETION_CONTAINER],
                }
            }
        },
    }


def cronjob(containers=None):
    return {
        "kind": "CronJob",
        "metadata": {"name": "render-nightly"},
        "spec": {
            "jobTemplate": {
                "spec": {
                    "template": {
                        "spec": {
                            "containers": containers if containers is not None else [RUN_TO_COMPLETION_CONTAINER],
                        }
                    }
                }
            }
        },
    }


class DeployManifestTests(unittest.TestCase):
    def test_the_real_manifest_passes(self):
        root = Path(__file__).resolve().parents[2]
        text = (root / "deploy" / "podman" / "victual.yaml").read_text()
        errors = []
        for doc in yaml.safe_load_all(text):
            errors += validate(doc)
        self.assertEqual(errors, [])

    def test_a_compliant_pod_passes(self):
        self.assertEqual(validate(pod()), [])

    def test_non_pod_kinds_are_skipped(self):
        self.assertEqual(validate({"kind": "ConfigMap", "data": {}}), [])

    def test_missing_security_context_fields_are_each_reported(self):
        container = dict(GOOD_CONTAINER, securityContext={})
        errors = validate(pod(containers=[container]))
        self.assertIn("container/app: securityContext.readOnlyRootFilesystem must be true", errors)
        self.assertIn("container/app: securityContext.allowPrivilegeEscalation must be false", errors)
        self.assertIn("container/app: securityContext.capabilities.drop must include ALL", errors)

    def test_capabilities_dropping_something_other_than_all_fails(self):
        sc = dict(GOOD_CONTAINER["securityContext"], capabilities={"drop": ["NET_RAW"]})
        container = dict(GOOD_CONTAINER, securityContext=sc)
        errors = validate(pod(containers=[container]))
        self.assertIn("container/app: securityContext.capabilities.drop must include ALL", errors)

    def test_missing_memory_limit_fails(self):
        container = dict(GOOD_CONTAINER, resources={})
        errors = validate(pod(containers=[container]))
        self.assertIn("container/app: resources.limits.memory must be set", errors)

    def test_container_with_no_probe_fails(self):
        container = {k: v for k, v in GOOD_CONTAINER.items() if k != "livenessProbe"}
        errors = validate(pod(containers=[container]))
        self.assertIn("container/app: no startupProbe, livenessProbe or readinessProbe", errors)

    def test_init_container_needs_no_probe_but_needs_a_limit_and_security_context(self):
        init_container = {"name": "migrate", "securityContext": {}, "resources": {}}
        errors = validate(pod(init_containers=[init_container]))
        self.assertIn("initContainer/migrate: resources.limits.memory must be set", errors)
        self.assertIn("initContainer/migrate: securityContext.readOnlyRootFilesystem must be true", errors)
        self.assertNotIn("no startupProbe, livenessProbe or readinessProbe", " ".join(errors))

    # ADR-0021 prerequisite 6: a run-to-completion renderer must not escape this gate.

    def test_a_job_without_a_probe_passes(self):
        self.assertEqual(validate(job()), [])

    def test_a_cronjob_without_a_probe_passes(self):
        self.assertEqual(validate(cronjob()), [])

    def test_a_job_still_needs_its_security_context_and_memory_limit(self):
        container = {"name": "render", "securityContext": {}, "resources": {}}
        errors = validate(job(containers=[container]))
        self.assertIn("container/render: securityContext.readOnlyRootFilesystem must be true", errors)
        self.assertIn("container/render: securityContext.allowPrivilegeEscalation must be false", errors)
        self.assertIn("container/render: securityContext.capabilities.drop must include ALL", errors)
        self.assertIn("container/render: resources.limits.memory must be set", errors)
        self.assertNotIn("no startupProbe, livenessProbe or readinessProbe", " ".join(errors))

    def test_a_cronjob_still_needs_its_security_context_and_memory_limit(self):
        container = {"name": "render", "securityContext": {}, "resources": {}}
        errors = validate(cronjob(containers=[container]))
        self.assertIn("container/render: securityContext.readOnlyRootFilesystem must be true", errors)
        self.assertIn("container/render: resources.limits.memory must be set", errors)

    def test_a_job_whose_containers_were_reachable_before_this_change_would_have_passed(self):
        """The gap itself: with Job absent from POD_SPEC_PATHS the bad container was invisible."""
        from check_deploy_manifest import POD_SPEC_PATHS

        bad = job(containers=[{"name": "render", "securityContext": {}, "resources": {}}])
        without_job = {k: v for k, v in POD_SPEC_PATHS.items() if k != "Job"}
        self.assertEqual(without_job.get(bad["kind"]), None)
        self.assertNotEqual(validate(bad), [])

    def test_an_unknown_kind_carrying_containers_is_reported(self):
        doc = {
            "kind": "Rollout",
            "metadata": {"name": "worker"},
            "spec": {"template": {"spec": {"containers": [GOOD_CONTAINER]}}},
        }
        errors = validate(doc)
        self.assertEqual(len(errors), 1)
        self.assertIn("Rollout/worker", errors[0])
        self.assertIn("POD_SPEC_PATHS", errors[0])

    def test_an_unknown_kind_carrying_no_containers_is_still_skipped(self):
        self.assertEqual(validate({"kind": "ConfigMap", "data": {"containers": "text"}}), [])
        self.assertEqual(validate({"kind": "Service", "spec": {"ports": [{"port": 80}]}}), [])


if __name__ == "__main__":
    unittest.main()
