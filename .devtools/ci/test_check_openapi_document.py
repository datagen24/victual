import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

import check_openapi_document as gate

FIXTURES = Path(__file__).resolve().parent / "fixtures" / "oas"
ROOT = Path(__file__).resolve().parents[2]

# Each negative control must fail at exactly this pointer and nowhere else.
NEGATIVE_CONTROLS = {
    "version-3.1.0": "/openapi",
    "missing-info-title": "/info",
    "parameter-in-bogus": "/paths/~1x/get/parameters/0/in",
    "unknown-operation-key": "/paths/~1x/get",
    "dangling-ref": "/paths/~1x/get/responses/200/content/application~1json/schema/$ref",
    "enum-empty-string": "/components/schemas/Bad/enum",
}


def load(name):
    return json.loads((FIXTURES / f"{name}.json").read_text())


class OpenApiGateTests(unittest.TestCase):
    def test_the_positive_controls_pass(self):
        self.assertEqual(gate.check_document(load("valid-minimal")), [])

    def test_the_committed_document_passes(self):
        document = json.loads((ROOT / "victual.openapi.json").read_text())
        self.assertEqual(gate.check_document(document), [])

    def test_every_negative_control_fails_at_its_pointer(self):
        for name, expected in NEGATIVE_CONTROLS.items():
            with self.subTest(name=name):
                self.assertEqual([where for where, _ in gate.check_document(load(name))], [expected])

    def test_the_template_may_hold_the_placeholder_but_nothing_derived_from_it(self):
        document = load("valid-minimal")
        document["components"]["schemas"]["StringEnumTemplate"] = {"type": "string", "enum": [""]}
        self.assertEqual(gate.check_document(document), [])
        document["components"]["schemas"]["Derived"] = {"type": "string", "enum": [""]}
        self.assertEqual([where for where, _ in gate.check_document(document)], ["/components/schemas/Derived/enum"])

    def test_an_anchor_reference_resolves_only_when_the_anchor_exists(self):
        document = load("valid-minimal")
        document["components"]["schemas"]["Anchored"] = {"$anchor": "here", "type": "string"}
        document["components"]["schemas"]["Pointer"] = {"$ref": "#here"}
        self.assertEqual(gate.reference_findings(document), [])
        document["components"]["schemas"]["Pointer"] = {"$ref": "#gone"}
        self.assertEqual(len(gate.reference_findings(document)), 1)

    def test_a_pointer_with_escapes_and_a_bad_index_is_judged_correctly(self):
        document = load("valid-minimal")
        document["paths"]["/x"]["get"]["responses"]["200"]["content"]["application/json"]["schema"] = {
            "$ref": "#/paths/~1x/get/parameters/0/schema"}
        self.assertEqual(gate.reference_findings(document), [])
        document["paths"]["/x"]["get"]["responses"]["200"]["content"]["application/json"]["schema"] = {
            "$ref": "#/paths/~1x/get/parameters/7/schema"}
        self.assertEqual(len(gate.reference_findings(document)), 1)

    def test_a_changed_vendored_schema_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            tampered = Path(directory) / "schema.json"
            tampered.write_bytes(gate.SCHEMA_PATH.read_bytes() + b"\n")
            with self.assertRaises(SystemExit) as raised:
                gate.load_metaschema(tampered)
            self.assertIn("sha256", str(raised.exception))

    def test_main_takes_paths_and_reports_the_failing_pointer(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            self.assertEqual(gate.main([str(FIXTURES / "valid-minimal.json")]), 0)
            self.assertEqual(gate.main([str(FIXTURES / "valid-minimal.json"), str(FIXTURES / "version-3.1.0.json")]), 1)
            self.assertEqual(gate.main([str(FIXTURES / "does-not-exist.json")]), 1)
        self.assertIn("version-3.1.0.json: /openapi:", out.getvalue())
        self.assertIn("unreadable", out.getvalue())
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(gate.main([]), 2)


if __name__ == "__main__":
    unittest.main()
