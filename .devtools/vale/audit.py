#!/usr/bin/env python3
"""Run the pinned Vale rules over authored documentation; optionally check a baseline."""
from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
VERSION = '3.22.0'
BASELINE = ROOT / '.devtools/vale/baseline.json'
# These are source records or machine/agent inputs, not maintained documentation pages.
EXCLUDED_PREFIXES = ('changelog/', 'memory/', '.agents/', '.claude/', '.work/',
                     '.spike-', 'docs/plans/.versions/', '.github/ISSUE_TEMPLATE/',
                     '.devtools/vale/audits/', '.devtools/vale/fixtures/')
EXCLUDED_FILES = {'LICENSE.md', 'AGENTS.md', 'CLAUDE.md', '.github/PULL_REQUEST_TEMPLATE.md'}


def in_scope(path: str) -> bool:
    return (path.endswith(('.md', '.markdown', '.mdown', '.markdn'))
            and path not in EXCLUDED_FILES
            and not path.startswith(EXCLUDED_PREFIXES))


def discover() -> tuple[list[str], list[str]]:
    raw = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT)
    markdown = sorted(p for p in raw.decode().split('\0')
                      if p.endswith(('.md', '.markdown', '.mdown', '.markdn')))
    return ([p for p in markdown if in_scope(p)],
            [p for p in markdown if not in_scope(p)])


def source_context(lines: list[str], line: int) -> str:
    """Use the source block, not a line number, so reflow does not spend the baseline."""
    start = max(0, min(line - 1, len(lines) - 1))
    end = start + 1
    # Table cells are reported individually; retain their row rather than the whole table.
    if lines[start].lstrip().startswith('|'):
        return ' '.join(lines[start].split())
    while start > 0 and lines[start - 1].strip():
        start -= 1
    while end < len(lines) and lines[end].strip():
        end += 1
    return ' '.join(' '.join(lines[start:end]).split())


def signature(path: str, alert: dict, context: str) -> str:
    payload = [path, alert['Check'], alert['Severity'], alert['Message'],
               ' '.join(alert['Match'].split()), context]
    return hashlib.sha256(json.dumps(payload, ensure_ascii=False).encode()).hexdigest()


def fingerprints(report: dict) -> Counter:
    return Counter(a['fingerprint'] for alerts in report['findings'].values() for a in alerts)


def new_findings(report: dict, baseline: dict) -> list[tuple[str, dict]]:
    allowance = Counter(baseline['fingerprints'])
    new = []
    for path, alerts in report['findings'].items():
        for alert in alerts:
            key = alert['fingerprint']
            if allowance[key]:
                allowance[key] -= 1
            else:
                new.append((path, alert))
    return new


def stale_fingerprints(report: dict, baseline: dict) -> Counter:
    return Counter(baseline['fingerprints']) - fingerprints(report)


def rules_digest() -> str:
    paths = [ROOT / '.vale.ini', *sorted((ROOT / '.devtools/vale/styles').rglob('*.yml'))]
    data = b''.join(str(p.relative_to(ROOT)).encode() + b'\0' + p.read_bytes() for p in paths)
    return hashlib.sha256(data).hexdigest()


def run_vale(paths: list[str], executable: str) -> dict:
    if not paths:
        raise ValueError('No documentation pages selected.')
    version = subprocess.check_output([executable, '--version'], text=True).strip()
    if version != f'vale version {VERSION}':
        raise ValueError(f'Expected Vale {VERSION}; found {version}.')
    result = subprocess.run([executable, '--no-global', '--config=.vale.ini',
                             '--output=JSON', *paths], cwd=ROOT, text=True,
                            capture_output=True)
    if result.returncode not in (0, 1):
        raise ValueError(f'Vale failed ({result.returncode}): {result.stderr or result.stdout}')
    try:
        raw = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise ValueError(f'Vale did not produce JSON: {result.stderr or result.stdout}') from exc
    if not isinstance(raw, dict):
        raise ValueError('Vale returned an unexpected report type.')
    # Vale can return error objects in JSON; do not mistake them for clean pages.
    for path, alerts in raw.items():
        if path not in paths or not isinstance(alerts, list):
            raise ValueError(f'Unexpected Vale result: {path}: {alerts}')
    report = {}
    for path in paths:
        lines = (ROOT / path).read_text().splitlines()
        alerts = raw.get(path, [])
        for alert in alerts:
            context = source_context(lines, alert['Line'])
            alert['fingerprint'] = signature(path, alert, context)
            alert['source_line'] = lines[alert['Line'] - 1].strip()
        report[path] = alerts
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('paths', nargs='*', help='Specific in-scope source pages; default: all tracked pages')
    parser.add_argument('--vale', default=os.environ.get('VALE', 'vale'))
    parser.add_argument('--output', type=Path, help='Write the complete audit as JSON')
    parser.add_argument('--check', action='store_true', help='Fail on findings absent from the reviewed baseline')
    parser.add_argument('--write-baseline', type=Path, help='Explicitly create a candidate baseline for review')
    args = parser.parse_args()
    try:
        included, excluded = discover()
        paths = sorted(set(args.paths)) if args.paths else included
        if any(p not in included for p in paths):
            raise ValueError('Paths must be tracked documentation pages in the declared scope.')
        if args.write_baseline and args.paths:
            raise ValueError('A baseline requires the complete documentation scope.')
        findings = run_vale(paths, args.vale)
        report = {'schema_version': 1, 'vale_version': VERSION,
                  'source_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
                  'rules_sha256': rules_digest(), 'files_scanned': paths,
                  'excluded_files': excluded, 'findings': findings}
        if args.output:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
        total = sum(map(len, findings.values()))
        pages = sum(bool(a) for a in findings.values())
        print(f'Vale {VERSION}: {total} findings in {pages} of {len(paths)} pages.')
        if args.write_baseline:
            baseline = {k: report[k] for k in ('schema_version', 'vale_version', 'source_commit', 'rules_sha256')}
            baseline['fingerprints'] = dict(sorted(fingerprints(report).items()))
            args.write_baseline.write_text(json.dumps(baseline, indent=2) + '\n')
            print(f'Candidate baseline written to {args.write_baseline}; review changes before committing.')
        if args.check:
            baseline = json.loads(BASELINE.read_text())
            if (baseline.get('schema_version') != 1
                    or baseline.get('vale_version') != VERSION
                    or baseline.get('rules_sha256') != report['rules_sha256']):
                raise ValueError('Baseline schema, Vale version, or rule digest does not match; '
                                 'review a new full audit.')
            added = new_findings(report, baseline)
            for path, alert in added:
                print(f"{path}:{alert['Line']}: {alert['Check']}: {alert['Message']}")
            stale = stale_fingerprints(report, baseline) if not args.paths else Counter()
            print(f'{len(added)} new findings; existing findings remain in the cleanup backlog.')
            if stale:
                print(f'{sum(stale.values())} resolved or changed baseline entries must be removed. '
                      'Review the findings, then regenerate the full baseline; do not accept new findings silently.')
            return int(bool(added or stale))
        return 0
    except (OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
        print(f'Documentation audit failed: {exc}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
