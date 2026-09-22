"""Optional integration checks for the local OpenSTE vocabulary."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
BINARY = os.environ.get('OPENSTE') or shutil.which('openste-vale')


@unittest.skipUnless(BINARY, 'Set OPENSTE to the installed stuffbucket/vale binary')
class VocabularyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        shutil.copyfile(ROOT / '.vale-ste.yml', self.root / '.vale-ste.yml')
        self.cwd = self.root / 'docs'
        self.cwd.mkdir()
        self.profile = self.root / 'personal.yml'
        self.profile.write_text('slop:\n  enabled: true\nvocabulary:\n  allow: [customwidget]\n')
        self.env = dict(os.environ, XDG_CONFIG_HOME=str(self.root / 'config'),
                        XDG_CONFIG_DIRS=str(self.root / 'config'),
                        XDG_STATE_HOME=str(self.root / 'state'))

    def run_tool(self, arguments, **kwargs):
        return subprocess.run([BINARY, *arguments], cwd=self.cwd, env=self.env,
                              capture_output=True, text=True, timeout=30,
                              check=True, **kwargs).stdout

    def lint(self, text):
        path = self.cwd / 'sample.md'
        path.write_text(text)
        result = json.loads(self.run_tool(['lint', '--audit', '--format', 'json',
                                          '--config', str(self.profile), str(path)]))
        return [f for item in result['results'] for f in (item['findings'] or [])]

    def test_project_terms_combine_with_personal_config(self):
        findings = self.lint('Labels identify products.\n\nState persists.\n\n'
                             'A customwidget stores booleans.\n')
        self.assertFalse([f for f in findings if f['ruleId'] == 'STE.Vocabulary'], findings)

    def test_phrase_scope_and_filler_remain_checked(self):
        findings = self.lint('Running helps.\n\nThe result is genuinely useful.\n\n'
                             'A test harness checks the API.\n')
        self.assertTrue(any(f.get('match', '').lower() == 'running' and
                            f['ruleId'] == 'STE.Vocabulary' for f in findings))
        self.assertTrue(any(f.get('match') == 'genuinely' and
                            f['ruleId'] == 'STE.SlopVocabulary' for f in findings))
        self.assertTrue(any(f.get('match') == 'harness' and
                            f['ruleId'] == 'STE.SlopVocabulary' for f in findings))

    def test_mcp_loads_project_vocabulary(self):
        requests = [
            {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
                'protocolVersion': '2024-11-05', 'capabilities': {},
                'clientInfo': {'name': 'vocabulary-test', 'version': '1'}}},
            {'jsonrpc': '2.0', 'method': 'notifications/initialized'},
            {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call', 'params': {
                'name': 'lint_text', 'arguments': {
                    'text': 'Labels identify products.', 'filename': 'sample.md'}}},
        ]
        output = self.run_tool(['mcp', '--config', str(self.profile), '--vocab-store',
                               str(self.root / 'learned.yml')],
                              input=''.join(json.dumps(r) + '\n' for r in requests))
        result = next(json.loads(line) for line in output.splitlines()
                      if json.loads(line).get('id') == 2)
        self.assertNotIn('error', result)
        self.assertFalse(result['result'].get('isError'))
        self.assertNotIn('STE.Vocabulary', result['result']['content'][0]['text'])


if __name__ == '__main__':
    unittest.main()
