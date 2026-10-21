#!/usr/bin/env python3
"""Refresh the 0.5.2 release data from GitHub.

Reads the repository through `gh api`, saves the raw responses under
evidence/refresh-<UTC timestamp>/, and rewrites the GitHub-observed fields in
release-data.json. Maintainer assessments (priority, size, fit, next action)
are kept by issue number; an issue new to the lane gets "Not assessed".
Run render.py afterwards. This script writes nothing to GitHub.
"""
import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / 'release-data.json'
ASSESSED = ('priority', 'size', 'fit', 'classification', 'next_action', 'pr')
ISSUE_FIELDS = ('number', 'title', 'html_url', 'state', 'labels', 'milestone', 'closed_at', 'updated_at', 'pull_request')


def gh(*args):
    """Run `gh` with the given arguments and return its parsed JSON output."""
    out = subprocess.run(['gh', *args], check=True, capture_output=True, text=True).stdout
    return json.loads(out)


def gh_pages(path):
    """Return every item from a paginated `gh api` list endpoint as one list."""
    return [item for page in gh('api', '--paginate', '--slurp', path) for item in page]


def save(directory, name, value):
    """Write one raw GitHub response into the evidence directory and return it."""
    (directory / name).write_text(json.dumps(value, indent=1, ensure_ascii=False) + '\n')
    return value


def trim(issues):
    """Keep only the issue fields this dashboard reads, so evidence stays small."""
    rows = []
    for issue in issues:
        row = {k: issue[k] for k in ISSUE_FIELDS if k in issue}
        row['labels'] = [{'name': label['name']} for label in issue['labels']]
        row['milestone'] = issue['milestone'] and {'title': issue['milestone']['title']}
        rows.append(row)
    return rows


def issue_row(issue, previous):
    """Merge one GitHub issue with the assessment previously recorded for it."""
    row = {
        'number': issue['number'],
        'title': issue['title'],
        'url': issue['html_url'],
        'state': issue['state'],
        'labels': [label['name'] for label in issue['labels']],
        'closed_at': issue.get('closed_at'),
    }
    for key in ASSESSED:
        row[key] = previous.get(key, None if key == 'pr' else 'Not assessed')
    return row


def main():
    """Take a GitHub snapshot, save it as evidence and update release-data.json."""
    data = json.loads(DATA.read_text())
    repo = data['repository']
    lane = data['lane']
    now = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    evidence = ROOT / 'evidence' / ('refresh-' + now.replace(':', ''))
    evidence.mkdir(parents=True)

    milestones = save(evidence, 'milestones.json', gh('api', f'repos/{repo}/milestones?state=all&per_page=100'))
    lane_number = next(m['number'] for m in milestones if m['title'] == lane)
    lane_issues = save(evidence, 'lane-issues.json', trim(gh_pages(f'repos/{repo}/issues?milestone={lane_number}&state=all&per_page=100')))
    open_issues = save(evidence, 'open-issues.json', trim(gh_pages(f'repos/{repo}/issues?state=open&per_page=100')))
    master = save(evidence, 'master.json', {'sha': gh('api', f'repos/{repo}/commits/master')['sha']})
    release = save(evidence, 'latest-release.json', {k: v for k, v in gh('api', f'repos/{repo}/releases/latest').items() if k in ('tag_name', 'published_at', 'target_commitish', 'html_url')})
    (evidence / 'observed-at.txt').write_text(now + '\n')

    previous = {item['number']: item for item in data['items']}
    data['items'] = sorted((issue_row(i, previous.get(i['number'], {})) for i in lane_issues if 'pull_request' not in i),
                           key=lambda x: (x['state'] != 'open', x['number']))
    data['milestones'] = [{k: m[k] for k in ('number', 'title', 'state', 'open_issues', 'closed_issues', 'description', 'html_url', 'due_on')}
                          for m in milestones if m['state'] == 'open' or m['title'] == lane]
    issues_only = [i for i in open_issues if 'pull_request' not in i]
    data['observation'] = {
        'observed_at': now,
        'source': 'GitHub REST API via gh',
        'evidence_directory': str(evidence.relative_to(ROOT)),
        'master_sha': master['sha'],
        'latest_release': release['tag_name'],
        'latest_release_published_at': release['published_at'],
        'open_issues': len(issues_only),
        'open_prs': len(open_issues) - len(issues_only),
        'unassigned_open_issues': sorted(i['number'] for i in issues_only if not i['milestone']),
    }
    DATA.write_text(json.dumps(data, indent=1, ensure_ascii=False) + '\n')
    print(f'Refreshed {len(data["items"])} {lane} items at {now}; evidence in {evidence.relative_to(ROOT)}.')


if __name__ == '__main__':
    main()
