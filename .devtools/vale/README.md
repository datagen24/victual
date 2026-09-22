# Proposed prose checks

This proposal adds Vale to enforce repeatable parts of Victual's
[documentation conventions](../../docs/documentation.md) and
[writing style](../../docs/style-guide.md). It uses repository-owned rules and a pinned
Vale binary. No third-party style package or online language service is needed to lint.

The proposed `prose` CI job scans authored documentation on every pull request. It rejects
new findings while the initial backlog is repaired through page-specific issues.
No issue is opened automatically by CI.

## Run locally

Install Python 3 and Git, then run these commands from the repository root:

```sh
python3 .devtools/vale/install.py --directory /tmp/victual-vale
export VALE=/tmp/victual-vale/vale
python3 .devtools/vale/audit.py --output /tmp/prose-audit.json
python3 .devtools/vale/audit.py --check
```

The installer supports Linux, macOS, and Windows on x86-64 and ARM64. On Windows, choose
a local installation directory and set `VALE` to its `vale.exe` path. Only the Linux x86-64
installation has been exercised for this proposal; the other archive checksums are pinned
from the same upstream release.

`install.py` downloads Vale **3.22.0** from its official GitHub release and verifies the
committed SHA-256 checksum before extracting the executable. Installation needs network
access. Subsequent lint runs work offline. An existing Vale installation is acceptable
if its version matches; `audit.py` refuses a different version.

Check selected tracked pages while editing:

```sh
python3 .devtools/vale/audit.py docs/manual/getting-started.md --check
```

The audit command exits 0 after producing a report, even when it finds prose problems.
`--check` exits 1 for new findings or stale baseline entries, and 2 for setup failures. The JSON report
contains every selected page, including pages with no findings, and the excluded-file list.

## Rules

All ten rules live in [styles/Victual](styles/Victual). Length limits are review thresholds,
not automatic rewriting instructions. Fix a passage or explain a narrow exception.

| Rule | Level | What the reviewer checks |
|---|---|---|
| `SentenceLength` | Warning | Sentences over 45 words combine independent claims or hide conditions. |
| `ParagraphLength` | Warning | Body paragraphs over 100 words need separate points or a list. |
| `TableCellLength` | Warning | Cells over 60 words need a concise summary and a link to detail. |
| `Editorializing` | Warning | Replace self-evaluation and metaphors with requirements or evidence. |
| `ReviewNarration` | Warning | State the resulting fact; retain material history in its owning record. |
| `ChatResidue` | Warning | Remove session instructions from durable documentation. |
| `RhetoricalHeading` | Warning | Name the topic or requirement in the heading. |
| `VagueReference` | Warning | Name or link the referenced section. |
| `Wordiness` | Suggestion | Use a shorter expression if its meaning is unchanged. |
| `AssumedEase` | Suggestion | Explain the step or evidence instead of assuming ease. |

Word counts use the rules' word-token expression after Vale parses Markdown. Code spans
and URLs do not contribute to the prose measurement. Table cells have their own length
check and are excluded from sentence and paragraph checks.

Spelling dictionaries, acronym expansion, passive-voice bans, and reading-grade targets
are intentionally absent. They create noise for this technical corpus and do not address
the writing problems that motivated the proposal. Human review still covers document
purpose, duplicated rationale, unsupported claims, and changed technical meaning.

## Scope and protected content

The runner uses `git ls-files` to select maintained Markdown source pages. This includes
`docs/`, code-adjacent READMEs, contributor and security guides, and documentation-site
source pages. Landed, retired, and superseded documents remain in scope for editorial
review, with their decisions and historical meaning preserved.

These records are excluded from the page-cleanup audit:

- Upstream `changelog/` history and `LICENSE.md`.
- Agent instructions, skills, execution records, and `memory/`.
- `.work/`, `.spike-*`, and archived `docs/plans/.versions/` snapshots.
- GitHub issue and pull request templates.
- Generated audit reports and rule-test fixtures.

The exact path list is in each audit's `excluded_files`. Generated documentation and API
reference output are not scanned; scan the authored sources instead.

Vale skips fenced and inline code, URLs, YAML front matter, and blockquotes. Blockquotes
protect recorded `Response` blocks and attributed source text. Keep new explanation
outside the quote; do not move ordinary prose into a blockquote to evade lint.

For a necessary exception, disable only the applicable rule around the smallest passage,
include the reason, and re-enable it immediately:

```markdown
<!-- Keep the formal requirement together to preserve the scope of its exception. -->
<!-- vale Victual.SentenceLength = NO -->
The exact requirement goes here.
<!-- vale Victual.SentenceLength = YES -->
```

## Baseline and rollout

The initial audit provides the reviewed backlog. `baseline.json` stores a fingerprint for
each finding, including its path, rule, message, matched text, and source block. It does
not allow a page-wide count that could hide one new problem behind one resolved problem.
Reflowing unchanged prose preserves a fingerprint; changing a flagged block requires
review of its remaining findings.

The proposed workflow runs the rule tests and the complete audit with `--check`. It uploads
the full report even when the baseline comparison fails. New warnings and suggestions
must be fixed or receive a justified rule-specific exception.

After fixing findings, regenerate a candidate baseline with the complete audit:

```sh
python3 .devtools/vale/audit.py --write-baseline .devtools/vale/baseline.json
```

Review the baseline diff with the prose diff. Cleanup should remove entries. Do not accept
new entries to make a build pass; additions require explicit review, a named issue, and
a documented reason. The complete CI check rejects stale entries, so resolved findings cannot remain allowed
and permit a later reintroduction.
Keep each page's issue open until its findings are fixed or individually justified.

Do not weaken rules to clear the initial backlog. Rule or Vale-version changes need the
fixture tests, a full audit, and review of changes in findings before updating the baseline.
No broad prose replacement is applied automatically.

This workflow is proposed in a draft PR. Merging enables the job; making its check required
for merge is a separate repository setting for the maintainer. The initial audit is a
one-time backlog exercise, not a scheduled issue generator.

## Verify the tooling

```sh
python3 -m unittest discover -s .devtools/vale -p 'test_*.py'
```

Tests run the actual Vale binary. They check every rule's detection, readable prose,
length boundaries, protected code and quotations, front matter, table scoping, and narrow
exceptions. Baseline tests cover new findings, repeated findings, resolved findings, and
reflow without changing the claim.

## References

- [Vale's purpose and limits](https://docs.vale.sh/).
- [Markup and prose scopes](https://docs.vale.sh/topics/scopes).
- [Occurrence rules](https://docs.vale.sh/checks/occurrence).
- [Existence rules](https://docs.vale.sh/checks/existence).
- [Markdown exceptions](https://docs.vale.sh/formats/markdown).
- [Pinned release](https://github.com/vale-cli/vale/releases/tag/v3.22.0).
