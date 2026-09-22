"""Exercise commit enforcement against real indexes and the pinned Vale binary."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import audit


class StagedProse(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='victual-hook-test-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.environment = os.environ.copy()
        for name in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR'):
            self.environment.pop(name, None)
        executable = shutil.which(os.environ.get('VALE', 'vale'))
        self.assertIsNotNone(executable, 'Install the pinned Vale binary to run these tests.')
        self.environment['VALE'] = str(Path(executable).resolve())
        self.git('init', '-q')
        self.git('config', 'user.name', 'Prose test')
        self.git('config', 'user.email', 'prose-test@example.invalid')
        self.git('config', 'commit.gpgsign', 'false')
        # Isolate tests from any hooks the developer configured globally.
        self.git('config', 'core.hooksPath', '.githooks')
        for name in ('.vale.ini', '.devtools/vale/audit.py', '.devtools/vale/check_staged.py'):
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(audit.ROOT / name, target)
        shutil.copytree(audit.ROOT / '.devtools/vale/styles', self.root / '.devtools/vale/styles')
        with patch.object(audit, 'ROOT', self.root):
            baseline = {'schema_version': 1, 'vale_version': audit.VERSION,
                        'source_commit': 'test', 'rules_sha256': audit.rules_digest(),
                        'fingerprints': {}}
        (self.root / '.devtools/vale/baseline.json').write_text(json.dumps(baseline))
        self.write('docs/page.md', 'The API rejects invalid quantities.\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'Create test fixture')
        target = self.root / '.githooks/pre-commit'
        target.parent.mkdir()
        shutil.copy2(audit.ROOT / '.githooks/pre-commit', target)

    def git(self, *arguments, check=True, environment=None):
        return subprocess.run(['git', *arguments], cwd=self.root, text=True,
                              capture_output=True, check=check,
                              env=environment or self.environment)

    def write(self, path, content):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def commit(self, environment=None):
        return self.git('commit', '-qm', 'Change test page', check=False, environment=environment)

    def test_staged_violation_cannot_be_hidden_by_clean_working_file(self):
        bad = 'Let me be clear: the API rejects invalid quantities.\n'
        good = 'The API rejects invalid requests.\n'
        self.write('docs/page.md', bad)
        self.git('add', 'docs/page.md')
        self.write('docs/page.md', good)
        result = self.commit()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Victual.Metadiscourse', result.stdout + result.stderr)
        self.assertEqual(self.git('show', ':docs/page.md').stdout, bad)
        self.assertEqual((self.root / 'docs/page.md').read_text(), good)

    def test_clean_staged_file_passes_despite_unstaged_violation(self):
        good = 'The API rejects invalid requests.\n'
        bad = 'Hope this helps.\n'
        self.write('docs/page.md', good)
        self.git('add', 'docs/page.md')
        self.write('docs/page.md', bad)
        result = self.commit()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(self.git('show', 'HEAD:docs/page.md').stdout, good)
        self.assertEqual((self.root / 'docs/page.md').read_text(), bad)

    def test_non_documentation_commit_skips_missing_vale(self):
        self.write('example.py', 'answer = 42\n')
        self.git('add', 'example.py')
        environment = {**self.environment, 'VALE': '/nonexistent/vale'}
        result = self.commit(environment)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_missing_vale_blocks_documentation_commit(self):
        self.write('docs/page.md', 'The API rejects invalid requests.\n')
        self.git('add', 'docs/page.md')
        result = self.commit({**self.environment, 'VALE': '/nonexistent/vale'})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Vale is missing', result.stdout + result.stderr)

    def test_renamed_file_with_spaces_is_checked(self):
        self.git('mv', 'docs/page.md', 'docs/new page.md')
        self.write('docs/new page.md', 'Hope this helps.\n')
        self.git('add', 'docs/new page.md')
        result = self.commit()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('docs/new page.md', result.stdout + result.stderr)

    def test_staged_config_cannot_be_hidden_by_working_config(self):
        name = '.vale.ini'
        original = (self.root / name).read_text()
        self.write(name, original + '\n# New staged configuration\n')
        self.git('add', name)
        self.write(name, original)
        result = self.commit()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('rule digest does not match', result.stdout + result.stderr)

    def test_alternate_index_is_preserved(self):
        environment = {**self.environment, 'GIT_INDEX_FILE': str(self.root / 'alternate-index')}
        self.git('read-tree', 'HEAD', environment=environment)
        self.write('docs/page.md', 'Hope this helps.\n')
        self.git('add', 'docs/page.md', environment=environment)
        before = (self.root / 'alternate-index').read_bytes()
        result = subprocess.run([sys.executable, '.devtools/vale/check_staged.py'],
                                cwd=self.root, env=environment, text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Victual.Metadiscourse', result.stdout + result.stderr)
        self.assertEqual((self.root / 'alternate-index').read_bytes(), before)
        self.assertEqual(self.git('diff', '--cached', '--name-only').stdout, '')

    def test_staged_symlink_is_rejected(self):
        self.write('outside.md', 'The API rejects invalid quantities.\n')
        (self.root / 'docs/page.md').unlink()
        (self.root / 'docs/page.md').symlink_to('../outside.md')
        self.git('add', 'docs/page.md')
        result = self.commit()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Expected a resolved regular file', result.stdout + result.stderr)

    def test_deleting_baselined_page_requires_removing_allowance(self):
        self.write('docs/page.md', 'Hope this helps.\n')
        subprocess.run([sys.executable, '.devtools/vale/audit.py', '--write-baseline',
                        '.devtools/vale/baseline.json'], cwd=self.root, env=self.environment,
                       check=True, capture_output=True)
        self.git('add', '.')
        self.git('commit', '-qm', 'Create reviewed test backlog')
        self.write('docs/clean.md', 'The API rejects invalid quantities.\n')
        self.git('add', 'docs/clean.md')
        self.git('rm', 'docs/page.md')
        result = self.commit()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('baseline entries must be removed', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
