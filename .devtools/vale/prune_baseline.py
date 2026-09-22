#!/usr/bin/env python3
"""Remove resolved fingerprints from .devtools/vale/baseline.json.

Prune-only: it never adds an allowance, so a newly introduced finding still fails
`audit.py --check` instead of being absorbed into the baseline. Metadata fields
(schema_version, vale_version, source_commit, rules_sha256) are left untouched so
concurrent page cleanups do not collide on the same line.
"""
import json
import os
import subprocess
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / '.devtools/vale'))
BASELINE = ROOT / '.devtools/vale/baseline.json'

os.chdir(ROOT)
import audit  # noqa: E402

included, _ = audit.discover()
report = {'findings': audit.run_vale(included, os.environ.get('VALE', 'vale'))}
present = audit.fingerprints(report)

baseline = json.loads(BASELINE.read_text())
old = Counter(baseline['fingerprints'])
kept = {k: min(v, present[k]) for k, v in sorted(old.items()) if present[k]}
removed = sum(old.values()) - sum(kept.values())

baseline['fingerprints'] = kept
BASELINE.write_text(json.dumps(baseline, indent=2) + '\n')
print(f'Pruned {removed} resolved baseline entr{"y" if removed == 1 else "ies"}; '
      f'{sum(kept.values())} remain.')
