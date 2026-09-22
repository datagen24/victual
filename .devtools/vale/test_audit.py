"""Exercise actual Vale rules, protected markup, and regression-baseline behavior."""
from collections import Counter
from contextlib import redirect_stdout, redirect_stderr
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import audit

VALE = os.environ.get('VALE', 'vale')


def lint(text):
    result = subprocess.run([VALE, '--no-global', '--config=.vale.ini', '--output=JSON',
                             '--ext=.md'], input=text, text=True, capture_output=True,
                            cwd=audit.ROOT)
    if result.returncode not in (0, 1):
        raise AssertionError(result.stderr or result.stdout)
    return [a for alerts in json.loads(result.stdout).values() for a in alerts]


class StyleRules(unittest.TestCase):
    def test_each_rule_detects_its_target(self):
        cases = {
            'SentenceLength': ' '.join(['word'] * 46) + '.',
            'ParagraphLength': ' '.join(['A short sentence.'] * 34),
            'TableCellLength': '| Topic | Details |\n|---|---|\n| API | ' + ' '.join(['word'] * 61) + ' |',
            'Editorializing': 'The load-bearing requirement is recorded here.',
            'ReviewNarration': 'Review caught a missing condition.',
            'ChatResidue': 'The next agent should perform this work in one sitting.',
            'RhetoricalHeading': '## Why this exists\n',
            'VagueReference': 'See above for the requirement.',
            'Wordiness': 'Use this setting in order to enable logging.',
            'AssumedEase': 'Simply run the command.',
        }
        for rule, text in cases.items():
            with self.subTest(rule=rule):
                self.assertIn('Victual.' + rule, {a['Check'] for a in lint(text)})

    def test_readable_prose_is_clean(self):
        self.assertEqual(lint('## Request validation\n\nThe API rejects invalid quantities. '
                              'A failed write leaves the stock ledger unchanged.\n'), [])

    def test_threshold_boundaries(self):
        for count in (45, 46):
            found = {a['Check'] for a in lint(' '.join(['word'] * count) + '.')}
            self.assertEqual('Victual.SentenceLength' in found, count > 45)

    def test_code_quotes_and_frontmatter_are_protected(self):
        text = ('---\ntitle: The next agent\n---\n\n'
                '```text\nThe next agent should simply act in one sitting.\n```\n\n'
                '> **Response:** The next agent should simply act in one sitting.\n\n'
                'The literal is `the next agent` and the URL is '
                '[reference](https://example.com/simply).\n')
        self.assertEqual(lint(text), [])

    def test_sentence_rule_does_not_treat_tables_as_sentences(self):
        alerts = lint('| Topic | Details |\n|---|---|\n| API | ' + ' '.join(['word'] * 61) + ' |')
        checks = {a['Check'] for a in alerts}
        self.assertIn('Victual.TableCellLength', checks)
        self.assertNotIn('Victual.SentenceLength', checks)
        self.assertNotIn('Victual.ParagraphLength', checks)

    def test_rule_specific_exception_is_narrow(self):
        text = ('<!-- vale Victual.Editorializing = NO -->\n'
                'A load-bearing requirement. Simply run it.\n'
                '<!-- vale Victual.Editorializing = YES -->\n')
        checks = {a['Check'] for a in lint(text)}
        self.assertNotIn('Victual.Editorializing', checks)
        self.assertIn('Victual.AssumedEase', checks)


class BaselineBehavior(unittest.TestCase):
    def report(self, keys):
        return {'findings': {'docs/example.md': [{'fingerprint': x} for x in keys]}}

    def test_check_requires_matching_rule_digest_even_without_findings(self):
        for digest, expected in (('current-rules', 0), ('old-rules', 2), (None, 2)):
            with self.subTest(digest=digest), tempfile.TemporaryDirectory() as directory:
                baseline = {'schema_version': 1, 'vale_version': audit.VERSION,
                            'fingerprints': {}}
                if digest is not None:
                    baseline['rules_sha256'] = digest
                path = Path(directory) / 'baseline.json'
                path.write_text(json.dumps(baseline))
                errors = io.StringIO()
                with patch.object(audit, 'BASELINE', path), \
                        patch.object(audit, 'discover', return_value=(['docs/example.md'], [])), \
                        patch.object(audit, 'run_vale', return_value={'docs/example.md': []}), \
                        patch.object(audit, 'rules_digest', return_value='current-rules'), \
                        patch.object(audit.subprocess, 'check_output', return_value='commit'), \
                        patch('sys.argv', ['audit.py', '--check']), \
                        redirect_stdout(io.StringIO()), redirect_stderr(errors):
                    self.assertEqual(audit.main(), expected)
                if expected:
                    self.assertIn('rule digest does not match', errors.getvalue())

    def test_existing_findings_pass_but_new_findings_fail(self):
        baseline = {'fingerprints': {'old': 1}}
        self.assertEqual(audit.new_findings(self.report(['old']), baseline), [])
        self.assertEqual(len(audit.new_findings(self.report(['new']), baseline)), 1)

    def test_a_duplicate_does_not_reuse_one_allowance(self):
        self.assertEqual(len(audit.new_findings(self.report(['old', 'old']),
                                               {'fingerprints': {'old': 1}})), 1)

    def test_removed_findings_do_not_block_cleanup(self):
        self.assertEqual(audit.new_findings(self.report([]), {'fingerprints': {'old': 1}}), [])

    def test_full_audit_identifies_stale_allowances_for_removal(self):
        self.assertEqual(audit.stale_fingerprints(self.report(['kept']),
                         {'fingerprints': {'kept': 1, 'fixed': 1}}), Counter({'fixed': 1}))

    def test_difficulty_warnings_are_not_assumptions_of_ease(self):
        checks = {a['Check'] for a in lint('This boundary is easy to get wrong. The result is not obviously intended.')}
        self.assertNotIn('Victual.AssumedEase', checks)

    def test_reflow_keeps_fingerprint_but_changed_claim_does_not(self):
        alert = {'Check': 'Victual.SentenceLength', 'Severity': 'warning',
                 'Message': 'Review length.', 'Match': 'The'}
        a = audit.source_context(['The API rejects invalid quantities.', 'The ledger is unchanged.'], 1)
        b = audit.source_context(['', 'The API rejects invalid', 'quantities. The ledger is unchanged.'], 2)
        self.assertEqual(audit.signature('docs/a.md', alert, a), audit.signature('docs/a.md', alert, b))
        self.assertNotEqual(audit.signature('docs/a.md', alert, a),
                            audit.signature('docs/a.md', alert, b.replace('rejects', 'accepts')))

    def test_scope_preserves_historical_records_and_includes_repo_guides(self):
        for path in ('docs/plans/landed/01-file-storage.md', '.devtools/coverage/README.md',
                     'docs/adr/0001-postgresql-alongside-sqlite.md', '.github/CONTRIBUTING.md'):
            self.assertTrue(audit.in_scope(path), path)
        for path in ('LICENSE.md', 'changelog/81_4.6.0_2026-03-06.md',
                     'docs/plans/.versions/17-ecosystem-clients.v1.md', 'memory/MEMORY.md'):
            self.assertFalse(audit.in_scope(path), path)


if __name__ == '__main__':
    unittest.main()
