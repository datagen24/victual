#!/usr/bin/env python3
"""Render the 0.5.2 release views from release-data.json.

Writes dashboard.md, dashboard.html and HANDOFF.md next to this file. It makes
no network calls; run refresh.py first to update the GitHub-observed fields.
"""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
ISSUE_URL = 'https://github.com/datagen24/victual/issues/'


def cell(value):
    """Return a value as one Markdown table cell, escaping pipes and newlines."""
    return str(value if value is not None else 'None').replace('|', '\\|').replace('\n', ' ')


def link(label, url):
    """Return a Markdown link."""
    return f'[{label}]({url})'


def issue_links(numbers):
    """Return comma-separated Markdown links for a list of issue numbers."""
    return ', '.join(link(f'#{n}', f'{ISSUE_URL}{n}') for n in numbers) or 'None'


def table(headers, rows):
    """Return a Markdown table for the given headers and rows."""
    lines = ['| ' + ' | '.join(headers) + ' |', '| ' + ' | '.join('---' for _ in headers) + ' |']
    return '\n'.join(lines + ['| ' + ' | '.join(cell(v) for v in row) + ' |' for row in rows])


def summary(data):
    """Return the one-paragraph lane status shared by every view."""
    obs = data['observation']
    items = data['items']
    done = sum(i['state'] == 'closed' for i in items)
    return (f"{data['lane']}: {done} of {len(items)} issues closed. Latest release {obs['latest_release']} "
            f"(published {obs['latest_release_published_at']}). Master `{obs['master_sha'][:8]}`. "
            f"{obs['open_issues']} open issues and {obs['open_prs']} open PRs repository-wide; "
            f"{len(obs['unassigned_open_issues'])} open issues have no milestone. Observed {obs['observed_at']}.")


def dashboard_md(data):
    """Return the Markdown dashboard."""
    obs = data['observation']
    parts = [
        f"# Victual {data['lane']} release dashboard",
        summary(data),
        link('Interactive dashboard', 'dashboard.html') + ' · ' + link('Structured source', 'release-data.json')
        + ' · ' + link('Execution ledger', 'execution.md') + ' · ' + link('Handoff', 'HANDOFF.md')
        + ' · ' + link(data['predecessor']['label'], data['predecessor']['url']),
        '## Standing rules',
        '\n'.join('- ' + r for r in data['standing_rules']),
        '## Patch policy',
        data['policy']['patch'] + ' ' + data['policy']['priority_legend'] + ' ' + data['policy']['size_legend'],
        f"## {data['lane']} issues",
        table(['Issue', 'State', 'Priority', 'Size', 'Classification', 'Patch fit', 'Next action', 'PR'],
              [[link(f"#{i['number']} {i['title']}", i['url']), i['state'], i['priority'], i['size'], i['classification'],
                i['fit'], i['next_action'], i['pr'] or 'None'] for i in data['items']]),
        '## Open milestones',
        'Observed ' + obs['observed_at'] + '. Unassigned open issues: ' + issue_links(obs['unassigned_open_issues']) + '.',
        table(['Milestone', 'Open', 'Closed', 'Due', 'Description'],
              [[link(m['title'], m['html_url']), m['open_issues'], m['closed_issues'], m['due_on'] or 'Unset', m['description']]
               for m in data['milestones']]),
        '## Gates carried from 0.5.0',
        table(['Gate', 'At v0.5.0', 'Now', 'Issues', 'Remaining', 'Closure rule'],
              [[g['id'] + ': ' + g['title'], g['status_at_v0_5_0'], g['status'], issue_links(g['issues']), g['remaining'], g['closure_rule']]
               for g in data['gates']]),
        '## Planning decisions',
        table(['ID', 'Date', 'Question', 'Decision', 'Issues'],
              [[d['id'], d['date'], d['question'], d['answer'], issue_links(d['issues'])] for d in data['decisions']]),
        '## GitHub writes made by planning',
        table(['When', 'Change'], [[w['at'], link(w['action'], w['url'])] for w in data['github_writes']]),
        '## Evidence',
        f"The latest snapshot is in `{obs['evidence_directory']}`, taken from the {obs['source']}. Assessments are planning estimates, not validation results.",
    ]
    return '\n\n'.join(parts) + '\n'


def handoff_md(data):
    """Return the handoff a fresh session reads before continuing the lane."""
    open_items = [i for i in data['items'] if i['state'] == 'open']
    parts = [
        f"# {data['lane']} release handoff",
        summary(data),
        '## Read first',
        'Read the repository AGENTS.md, then docs/constitution.md, then ' + link('execution.md', 'execution.md')
        + ' for what has merged. The 0.5.1 plan in ' + link('release-planning/2026-10-19', '../2026-10-19/HANDOFF.md')
        + ' is history: do not edit it.',
        '## Standing rules',
        '\n'.join('- ' + r for r in data['standing_rules']),
        '## Keeping the dashboard current',
        '```\npython3 release-planning/2026-10-21/refresh.py\npython3 release-planning/2026-10-21/render.py\n```',
        'refresh.py needs an authenticated `gh` and writes a new evidence directory; render.py is offline. '
        'Edit assessments (priority, size, fit, classification, next action, PR) in release-data.json by hand. '
        'Generated files are dashboard.md, dashboard.html and HANDOFF.md: never edit them directly.',
        '## Open work',
        '\n'.join(f"- #{i['number']} ({i['priority']}, {i['size']}): {i['next_action']}" for i in open_items) or '- None.',
    ]
    return '\n\n'.join(parts) + '\n'


def dashboard_html(data):
    """Return the HTML dashboard with the data embedded as a script literal."""
    payload = json.dumps(data, ensure_ascii=False).replace('<', '\\u003c').replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')
    return (ROOT / 'dashboard-template.html').read_text().replace('__RELEASE_DATA__', payload)


def main():
    """Render every view from release-data.json."""
    data = json.loads((ROOT / 'release-data.json').read_text())
    (ROOT / 'dashboard.md').write_text(dashboard_md(data))
    (ROOT / 'HANDOFF.md').write_text(handoff_md(data))
    (ROOT / 'dashboard.html').write_text(dashboard_html(data))
    print('Rendered dashboard.md, dashboard.html and HANDOFF.md from release-data.json.')


if __name__ == '__main__':
    main()
