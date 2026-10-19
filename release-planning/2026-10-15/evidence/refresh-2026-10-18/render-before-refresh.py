#!/usr/bin/env python3
"""Render local release planning views from release-data.json. No network or backend."""
import html
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
data = json.loads((ROOT / 'release-data.json').read_text())


def cell(value):
    return str(value if value is not None else 'Not set').replace('|', '\\|').replace('\n', ' ')


def link(label, url):
    return f'[{label}]({url})'


def refs(values):
    return ', '.join(link(x['label'], x['url']) for x in values)


def table(headers, rows):
    return '\n'.join(['| ' + ' | '.join(headers) + ' |', '| ' + ' | '.join('---' for _ in headers) + ' |'] + ['| ' + ' | '.join(cell(v) for v in r) + ' |' for r in rows])


def item_link(x):
    return link(('PR ' if x['type'] == 'pull request' else '') + '#' + str(x['number']) + ' ' + x['title'], x['url'])


adjustment = table(
    ['Issue or PR', 'Current milestone', 'Proposed milestone', 'Bundle', 'Classification', 'Reason', 'Dependency or release consequence', 'Confidence'],
    [[item_link(x), x['current_milestone'], x['proposed_milestone'], x['bundle'], x['classification'], x['change_tier'].capitalize() + ': ' + x['reason'], x['dependency_summary'], x['confidence']] for x in data['items']]
)
scope_rows = [x for x in data['items'] if x['state'] == 'open' and x['proposed_milestone'] == '0.5.0']
scope_counts = {key: sum(x['release_necessity'] == key for x in scope_rows) for key in sorted({x['release_necessity'] for x in scope_rows})}
scope_explanation = (f"The proposed 0.5.0 lane has {len(scope_rows)} direct issue rows. Classification within those rows: " + '; '.join(f"{count} {key.lower()}" for key, count in scope_counts.items()) + ". In particular, candidate/deferred rows are not automatic production gates; review the six recommended dispositions for narrowing or deferral before implementation. The original milestone proposal is preserved for maintainer review.")
intro = f"{data['verdict']}\n\nObserved: {data['observation']['observed_at']}. Planning baseline SHA: `{data['sha']}`. Current master SHA: `{data['observation']['remote_sha']}`. Current assignments were refreshed from the complete GitHub open-issue list and milestone API. Original assignments are preserved in `baseline_milestone` and evidence/release-data-before-apply.json.\n"
(ROOT / 'milestone-adjustments.md').write_text('# Proposed milestone adjustment batch\n\n' + intro + '\n' + adjustment + '\n\nA split row requires child work units before assigning a GitHub milestone; one issue cannot have two milestones. Required means necessary for release-policy compliance. Recommended means focus or dependency sequencing. Retain means no assignment change.\n')

parts = ['# Victual release plan and dashboard', intro,
         link('Interactive dashboard', 'dashboard.html') + ' · ' + link('Structured source', 'release-data.json') + ' · ' + link('Adjustment table', 'milestone-adjustments.md') + ' · ' + link('Handoff', 'HANDOFF.md'),
         '## Evidence and scope',
         'Inventory refreshed ' + data['observation']['observed_at'] + ': ' + str(data['observation']['open_issues']) + ' open issues and ' + str(data['observation']['open_prs']) + ' open PRs across the repository. ' + str(data['observation']['newly_closed_since_snapshot']) + ' further issues from the original 53-issue cohort closed since the prior snapshot; ' + str(data['observation']['current_open_in_cohort']) + ' remain open. Latest release: ' + data['observation']['latest_release'] + ', published ' + data['observation']['published_at'] + ' from `' + data['observation']['release_master_sha'] + '`. Actual issue milestone assignments and all six milestone states/counts were refreshed from GitHub.',
         'Priority, severity, size and release necessity are separate assessments. ' + data['policy']['size_legend'] + ' ' + data['policy']['priority_legend'],
         '## Release outcomes']
for r in data['releases']:
    lane = 'Shipped release history' if r.get('historical') else 'Active or proposed release lanes'
    parts += [f"### {lane}: {r['version']} — {r['status']}", r['outcome'], r['actual'], 'Proposed scope: ' + r['scope'], 'Deferral or limit: ' + r['defers'], refs(r['evidence'])]
parts += ['Version 0.4.4 is the latest shipped release. Treat 0.4.1-hotfix1 through 0.4.3 as history, not active release lanes. The next active destination is 0.5.0; 0.4.4 closed with #926 and #898. No new patch milestone is proposed. Rehearse the 0.5.0 upgrade from the actual preceding release.',
          '## Open backlog and 0.5.0 scope',
          data['observation']['assignment_distribution']['note'],
          scope_explanation,
          'The 0.5.0 checklist distinguishes existing mandatory gates, individually selected additional gates, and issues that may be deferred. Milestone assignment by itself does not make an issue a production gate.',
          '## Current GitHub milestone inventory',
          'Observed ' + data['observation']['observed_at'] + '. Dates remain unset.',
          table(['Milestone', 'State', 'Open issues', 'Closed issues', 'Due date', 'Description'], [[link(m['title'],m['html_url']),m['state'],m['open_issues'],m['closed_issues'],m['due_on'] or 'Unset',m['description']] for m in data['actual_milestones']]),
          '## Milestone metadata proposal',
          table(['Milestone', 'Action', 'Proposed title', 'Proposed description', 'Date', 'Reason'], [[link(m['current'], m['url']), m['action'], m['proposed'], m['proposed_description'], 'Leave unset', m['reason']] for m in data['milestone_changes']]),
          '## Shipped 0.4.1 compatibility conflicts',
          'These are historical deviations and corrective documentation proposals. Moving an issue changes no bytes in a release branch or tag. No tag is to be rewritten. The original 0.4.1 release object exists, while its automatic image publication failed; hotfix1 has published digests.',
          table(['Shipped change', 'Observable effect', 'Policy assessment', 'Concrete disposition', 'Source'], [[x['change'],x['observable'],x['policy'],x['disposition'],refs([x['source']])] for x in data['shipped_conflicts']]),
          '## Consolidated issue and PR placement', adjustment,
          '## Finite 0.5.0 production checklist',
          'M1–M9 are existing mandatory requirements. The maintainer adopted R1–R6 as additional 0.5.0 gates. Each closes with evidence at the candidate SHA or artifact digest, or a narrowly justified production restriction. No issue-count percentage represents production readiness.']
for category in ['Existing mandatory', 'Recommended additional']:
    parts += ['### ' + category, table(['Gate', 'Status', 'Owner', 'Evidence', 'Remaining work', 'Release consequence', 'Source'], [[g['id'] + ': ' + g['title'], g['status'], g['owner'], g['evidence'] + ' Validated SHA: ' + (g['validated_sha'] or 'No final candidate evidence'), g['remaining'], g['release_consequence'], refs(g['sources'])] for g in data['gates'] if g['category'] == category])]
parts += ['### Improvements and evidence that can follow production', table(['Items', 'Disposition'], [[', '.join(link('#'+str(n), f'https://github.com/datagen24/victual/issues/{n}') for n in x['items']),x['reason']] for x in data['follow_after_production']]),
          '## Critical dependency sequence']
for s in data['critical_sequence']:
    parts += [f"{s['step']}. {s['title']}. {s['detail']} Owner: {s['owner']}. Gates: {', '.join(s['gate_ids'])}."]
parts += ['## Smallest useful first implementation batch', data['batch_note'], table(['Order', 'Unit', 'Scope', 'Owner / size', 'Prerequisites', 'Validation', 'Release'], [[x['order'],x['unit'],x['scope'],x['owner']+' / '+x['size'],x['prerequisites'],x['validation'],x['release']] for x in data['next_batch']]),
          '## Decision batch and application status',
          'The historical decisions are preserved verbatim in ' + link('DECISIONS.md snapshot', 'evidence/historical-DECISIONS.md') + '. Price-zero interpretation, option B, idempotency retention/discovery, simulator acceptance, conversion-preservation policy, numeric-string option A and the userfile read/view-key decisions are not asked again.',
          table(['ID / question', 'Factual question', 'Recorded decision', 'Status', 'Release effect', 'Needed for'], [[x['id']+': '+x['title'], x['factual_question'], x.get('recorded_answer', '; '.join(x['options'])), x['status'], x.get('recorded_effect', x['consequence']), x['needed_for']] for x in data['decisions']]),
          '## New findings from planning',
          table(['Finding', 'Classification', 'Evidence and effect', 'Existing tracking', 'Next action / role'], [[x['id']+': '+x['title'],x['classification'],x['effect']+' '+refs(x['evidence']),x['existing_tracking'],x['action']+' '+x['role']] for x in data['findings']]),
          '## Historical leads reconciled', table(['Lead', 'Current conclusion'], [[x['lead'],x['result']] for x in data['historical_reconciliation']]),
          '## Full assessment of each open issue and execution PR']
for x in data['items']:
    if x['state'] != 'open':
        continue
    parts += [f"### #{x['number']} {x['title']}",
              f"Outcome: {x['outcome']}",
              f"{x['classification']}; {x['priority']} ({x['severity']}); size {x['size']}; uncertainty {x['uncertainty']}; confidence {x['confidence']}. Release necessity: {x['release_necessity']}.",
              f"Original baseline: {x.get('baseline_milestone', x['current_milestone'])}. Actual milestone: {x['current_milestone']}. Proposed: {x['proposed_milestone']}. Actual assignees: {', '.join(x['assignees']) or 'none'}. Responsible role is proposed: {x['responsible_role']}.",
              'Affected users / consequence of deferral: ' + x['deferral'],
              'Next action: ' + x['next_action'],
              'Evidence: ' + refs(x['evidence']) + '. Repository inspection SHA: `' + x['head_sha'] + '`; no item-specific new validation in this session.']
    if x.get('work_units'):
        parts += [table(['Separate work unit', 'Proposed destination', 'Classification', 'Boundary'], [[u['id'],u['proposed_milestone'],u['classification'],u['scope']] for u in x['work_units']])]
    if x.get('dependencies'):
        parts += [table(['Dependency', 'Implemented', 'Merged', 'Verified', 'Edge'], [[link('#'+str(v['number']),v['url']),v['implemented'],v['merged'],v['verified'],v['edge_type']] for v in x['dependencies']])]
    else:
        parts += ['Dependencies: no additional issue-level prerequisite identified in the bounded review. Accepted repository requirements still apply.']
if data.get('agent_plan'):
    parts += ['## Next release agent plan', f"{data['agent_plan']['release']}: {data['agent_plan']['status']}", link('Historical 0.4.3 agent briefs', 'agents-0.4.3.md'), f"Current planning baseline: `{data['agent_plan']['base_sha']}`. Agents dispatched: no."]
parts += ['## Verification, freshness and budget', table(['Check', 'Result', 'Evidence'], [[v['check'],v['result'],link('Evidence',v['evidence'])] for v in data['validation']]),
          f"Session started {data['budget']['started_at']}; two-hour deadline {data['budget']['deadline']}; no new research after {data['budget']['stop_new_research_at']}. Recorded elapsed time: {data['budget'].get('elapsed_minutes','pending final measurement')} minutes. {data['budget']['usage_note']}",
          data['models']['coordinator'],
          'Readers: GPT-6 Luna medium, two bounded source tasks; GPT-6 Astra high, one consolidated compatibility task. At most two readers ran concurrently. Reports are saved in ' + link('Luna report','evidence/luna-production-review.md') + ' and ' + link('Astra report','evidence/astra-compatibility-review.md') + '.',
          'Remaining work: ' + ('; '.join(data['budget']['remaining_work']) or 'Planning artifacts complete; maintainer decisions and future implementation remain.'),
          '## Limits on the conclusion']
parts += ['- ' + x for x in data['limitations']]
parts += ['## Approval boundary', 'The approved milestone metadata batch was applied and live GitHub assignments were refreshed. #944 remains split between the proposed 0.4.2 documentation work unit and its 0.5.0 runtime disposition; the child issue was not created. The recorded D2–D7 decisions do not accept Proposed ADRs. Version 0.4.4 is signed and published. These facts do not establish 0.5.0 production readiness. Integrated 0.5.0 workflows, required client adoption, upgrade/restore evidence and unresolved integrity gates still need closure or a supported restriction.']
if data.get('execution'):
    e = data['execution']
    parts[1:1] = ['## Active release execution', e['active_release'] + ': ' + e['state'], link('Execution ledger', e['ledger']), 'Current master: `' + e.get('current_master', e['base_sha']) + '`. Current head: `' + e.get('current_head', '') + '`. Fully validated candidate: `' + e.get('validated_candidate', '') + '`.', 'Remaining: ' + '; '.join(e['remaining']), table(['Unit', 'Commit', 'Review', 'State'], [[u['name'], u['commit'], u.get('pr', 'Pending'), u['state']] for u in e['completed_units']])]
(ROOT / 'dashboard.md').write_text('\n\n'.join(parts)+'\n')

handoff = ['# Release planning handoff', intro,
           '## Durable files',
           '- ' + link('Structured source','release-data.json') + ' generates every planning view.\n- ' + link('HTML dashboard','dashboard.html') + '\n- ' + link('Markdown dashboard','dashboard.md') + '\n- ' + link('Milestone batch','milestone-adjustments.md') + '\n- ' + link('0.4.3 agent briefs','agents-0.4.3.md') + '\n- ' + link('Renderer','render.py') + ' runs with `python3 release-planning/2026-10-15/render.py`. No backend or network is used.',
           '## Authority and preserved decisions',
           'Read repository AGENTS.md, constitution, ADR index and plan index in order. The historical handoff/ledger/decisions/retrospective are copied verbatim under evidence/historical-*.md. Later ledger entries supersede stale retrospective findings. Do not repeat answered maintainer questions. ADR-0045/0046/0048/0049 remain Proposed; acceptance is separate bookkeeping.',
           '## Completed planning work',
           'The planning phase assessed 53 open issues. The 2026-10-16 full repository refresh found 43 open issues and 0 open PRs; 10 original cohort issues are now closed. Six milestone metadata updates and 25 issue reassignments were applied and verified during planning. Current assignments and milestone counts are refreshed from GitHub. PRs #971–#974 have all merged. The 43 open issues are the full backlog: 22 are assigned to 0.5.0, 6 to 0.6.0, and 15 are unassigned.',
           '## Decisions and next action',
           'D1–D4, D5b, D6 and D7 are recorded from the maintainer. D5a requires BATTERIES_VIEW plus tracking permission before charging; the separate undo rule remains unresolved. D2 moves #93/#133 into the proposed 0.5.0 scope while actual GitHub assignments remain unchanged in this local proposal. Home Assistant #139 and MCP #86 remain excluded from the production support claim.',
           f"Signed tag v0.4.4 targets `{data['observation']['release_master_sha']}`; release workflow {data['execution']['published_release']['workflow_id']} published both architectures, six images and the Helm chart. PR #974 contains the release record and corrected 0.4.3 publication status. Issues #898 and #926 closed after PRs #973 and #972 merged. PR #971 also merged. The scheduled Vale baseline-prune workflow pruned 889 resolved entries but its direct push was rejected by the repository rule requiring changes through a pull request; this is an independent automation follow-up and did not affect release checks. Never redispatch completed #919/#921/#945/#955.",
           '## Evidence limits',
           'The v0.4.4 release and its master checks passed. The historical planning-baseline coverage and lint findings remain in the original evidence snapshot and do not describe v0.4.4. Final 0.5.0 integrated/upgrade/restore/client/deployment evidence remains absent. #930 has no two-connection reproduction; #942 has no root cause. Accepted #702 evidence is simulator-only.',
           '## Session and model record',
           data['models']['coordinator'],
           'Luna medium handled two read-only questions; Astra high handled one consequential packet. No account-wide usage was attributed to this session. ' + data['budget']['usage_note'],
           f"Start {data['budget']['started_at']}; research cutoff {data['budget']['stop_new_research_at']}; deadline {data['budget']['deadline']}; elapsed {data['budget'].get('elapsed_minutes','pending')} minutes. Remaining work: " + '; '.join(data['budget']['remaining_work'])]
if data.get('execution'):
    handoff[1:1] = ['## Current execution status', data['execution']['active_release'] + ': ' + data['execution']['state'].rstrip('.') + '. Start with ' + link('the execution ledger', 'execution.md') + ' for merge commits, published artifact identities and remaining evidence limits. Latest release v0.4.4 was published on 2026-10-16. Current assignments and milestone counts were refreshed from GitHub at ' + data['observation']['observed_at'] + '. 43 is the full open backlog, with 22 issues currently assigned to 0.5.0, 6 to 0.6.0, and 15 unassigned.']
(ROOT / 'HANDOFF.md').write_text('\n\n'.join(handoff)+'\n')

template = (ROOT / 'dashboard-template.html').read_text()
payload = json.dumps(data, ensure_ascii=False).replace('<', '\\u003c').replace('\u2028','\\u2028').replace('\u2029','\\u2029')
(ROOT / 'dashboard.html').write_text(template.replace('__RELEASE_DATA__',payload))
print('Rendered dashboard.html, dashboard.md, milestone-adjustments.md and HANDOFF.md from release-data.json.')
