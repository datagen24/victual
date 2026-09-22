# Victual writing style

Use this guide and the repository's Vale rules alongside
[the documentation conventions](documentation.md). These prose checks preserve
architectural decisions and their acceptance process.

Write for the person who needs to understand or use the software. Start with the result,
requirement, or action. Follow it with the reason and evidence needed to assess it.

## Choose the reader and document

| Document | Start with | Then explain |
|---|---|---|
| User guide | The task and expected result | Prerequisites, steps, verification, and recovery |
| Developer reference | The interface or component's responsibility | Inputs, outputs, dependencies, failure behavior, and examples |
| ADR | The decision and its status | Context, alternatives, consequences, and acceptance gates |
| Plan | The problem and intended outcome | Scope, constraints, proposed design, unresolved questions, and verification |
| Folder README | What is here and where to start | Entry points and links to detailed records |
| Findings record | The observed defect and impact | Reproduction, evidence, scope, and disposition |

Put implementation history in the owning plan's Executed section. Keep a README's status
summary brief and link to that record. Preserve an original proposal's tense when adding
delivery evidence; do not make the delivered design appear to have been planned earlier.

## Write complete, direct sentences

Use a concrete subject and verb. Identify the component, action, and consequence. Keep
conditions and exceptions next to the requirement they qualify.

Prefer sentences around 15–30 words. Review sentences over 45 words for independent
claims that can be separated. This is an editing threshold, not permission to remove a
condition or turn prose into fragments.

Before:

> The step is the one that counts because it sees everything the job measured, which is
> the point of putting it at the end rather than where the first report happens to run.

After:

The final step merges coverage from every measured process. CI applies the threshold to
that report after all measured tests finish.

Use active voice when the actor matters. Passive voice is appropriate when the result is
the focus. Do not rewrite every passive construction or remove technical vocabulary to
satisfy a reading-grade formula.

## Organize paragraphs and tables

Give each paragraph one main point. State it first, then supply its reason or evidence.
Review paragraphs over 100 words. Split at a change in subject, condition, or conclusion.
Use lists for parallel requirements and numbered steps when sequence matters.

Tables help readers compare values or find mappings. Review cells over 60 words: keep the
identifying fact and status in the cell, and move detailed rationale to a linked section.
Moving the same long explanation into another table does not resolve the problem.

## Explain reasons without narrating the review

Describe the failure and its consequence. Retain material findings, dates, attribution,
and evidence. Remove accounts of how insightful, surprising, or careful the work was.

Before:

> The rule is load-bearing, and that is the point of the check.

After:

Without the rule, a missing coverage file can leave the build green. The check fails
when any required test step produces no coverage file.

A metaphor should not substitute for a technical relationship. Explain what depends on a
setting, what fails without it, or what evidence the check provides.

Session instructions and assignments belong in execution notes. Durable documentation
states the requirement and dependency so that any contributor can act on them.

## Use precise terms and references

Define project-specific terms on first use. For example, a coverage floor is the minimum
acceptable percentage; a ratchet is a threshold that preserves the achieved percentage.
Use established identifiers exactly and format literal code, paths, and settings as code.

Name the referenced document or section and link to it. Avoid location-only references
that break when content moves. Use headings that identify the subject, such as
“Coverage aggregation” or “Missing measurement,” rather than an editorial conclusion.

Use shorter familiar words where they preserve meaning. State counts when known. Avoid
claims that a task is obvious or easy; give the necessary step or prerequisite instead.
Do not remove a term whose technical meaning is needed.

## Remove framing that adds no information

Start with the fact or action. Remove announcements such as `let me be clear` and
`it's worth noting`. Do not preview the answer, repeat the reader's question, or explain
why you are about to say something. End when the explanation is complete.

- Remove general lessons and closing maxims. A conclusion should add a specific finding
  or required action; it should not repeat the explanation as a slogan.
- Replace inflated significance and promotional wording with a supported effect.
  Phrases such as `marks a pivotal moment` and `seamless` supply no measurement.
- Remove trailing interpretation such as `, highlighting the importance of review`.
  If a consequence matters, name it and explain the cause.
- Avoid invented contrasts such as `not just X but Y` and `X, not Y`.
  Retain comparisons that explain a real choice or correct a stated error.
- Name the subject when a reference is unclear. Introduce unfamiliar terms before
  treating them as shared vocabulary. Do not give software or documents motives or feelings.
- Name sources for claims such as `experts believe` or `research shows`.
  Give the source's relevant result and its limits.
- Prefer direct verbs: `is` and `has` usually express the meaning of `serves as` and
  `boasts`. Keep one main point per sentence and consistent names for each concept.
- Use simple past for completed events unless another tense communicates needed timing
  or duration. Do not pad lists or remove a useful item to force a particular list length.

Do not use bold labels on every bullet or an em dash to stage a reveal.
Preserve complete sentences, uncertainty, conditions, and reasons. Articles, continuous
tenses, technical contrasts, and lists of three remain valid when they convey meaning.

## State requirements, status, and evidence separately

Use **must** for a requirement, **should** for a recommendation, and **may** for permission.
Use **can** for capability. Preserve the strength of existing requirements when editing.
Mark proposals, assumptions, and unresolved questions explicitly.

Keep these states distinct:

- Proposed: awaiting a decision.
- Accepted: approved through the project's decision process.
- Implemented: code exists in the stated revision.
- Verified: named checks passed in a stated environment.

A passing build does not demonstrate a working deployment. Record measurement dates,
commits, methods, environments, and limitations. Link to the evidence's authoritative
location rather than copying detailed status into multiple documents.

## Preserve technical and historical meaning

An editorial fix must retain decisions, constraints, exclusions, security warnings,
acceptance gates, source links, and unresolved questions. A clearer sentence must not
widen permission, weaken a requirement, or claim verification that did not occur.

Keep attributed quotations and recorded maintainer responses unchanged. Use a blockquote
for quoted source text. Resolve contradictions through the owning record; do not silently
choose a convenient interpretation during a prose cleanup.

## Apply automated checks with review

The [Vale rules](../.devtools/vale/README.md#rules) flag recurring patterns and passages
that need review. Warnings identify likely structural or wording problems. Suggestions
identify wording that may have a valid contextual use. Both are included in the
baseline check so new findings require a fix or a documented exception.

A passing lint run does not establish readability or factual accuracy. A failing length
check does not authorize deleting evidence. If a flagged passage is clearer as written,
use a narrow rule-specific exception with a reason and review it with the prose change.
Do not disable a whole file or raise a threshold to hide a difficult passage.

Before approving documentation, check that the intended reader can locate the action or
requirement, understand the reason, and distinguish fact from proposal. Then verify the
technical claims against their sources and check that the edit preserves meaning.
