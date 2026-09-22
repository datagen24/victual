# Documentation conventions

These conventions apply to repository-authored Markdown, including edits made by coding
assistants. Write for a human with software engineering experience who has not read the
chat or pull request that produced the document. Agents use the same documentation.

Every paragraph should help the reader locate something, understand a requirement,
evaluate a choice, or perform a task. Remove paragraphs that only describe the author's
thinking, praise the work, or explain how the document came to be written. Concision must
preserve technical meaning, evidence, and unresolved questions.

## Put information in the right document

| Document | Purpose | Keep elsewhere |
|---|---|---|
| Root README | Introduce Victual, the maintainer's goals, current state, and getting-started links. | Detailed setup, design arguments, implementation history. |
| Folder README | Explain the contents, entry points, and required reading or work order. | Research essays, review conversations, execution logs. |
| ADR | Explain a specific architectural choice through context, evidence, alternatives, and consequences. | Task assignments and chronological accounts of deliberation. |
| Plan | Research a major change: requirements, current behavior, scope, design, dependencies, unknowns, and verification criteria. | Prompts, session scripts, and an agent's execution checklist. |
| Operational guide | Provide prerequisites, commands, configuration, expected results, and troubleshooting. | Roadmap status and extended architectural arguments. |
| Release record | State what a tag is, what was verified before it was placed (dated, reproducible), what differs from upstream on purpose, and what is known to be missing, linking each to its authority. | Delivery status, decision rationale, implementation history. |

A folder README may contain a concise inventory or status table. A row identifies the
item, its state, and the dependency or next unresolved issue. Detailed evidence belongs
in the linked document. Do not turn table cells into paragraphs to avoid creating a
proper home for the information.

Use descriptive headings such as “Dependencies” or “Migration constraints”. Avoid
headings that editorialize, such as “What this really means” or “The lesson we learned”.

## Write conclusions and supporting reasons

Lead with the fact, requirement, or recommendation. Follow it with the evidence or
technical reason needed to assess it. Use concrete subjects: name the service, field,
consumer, or constraint rather than referring vaguely to “the shape” or “the mechanism”.

- Keep one main point per paragraph. Split sentences that combine a claim, an exception,
  a historical correction, and a conclusion.
- Explain dependencies as “A requires B because …”. Name the required capability and
  whether it is available. A useful reading order is not automatically a build blocker.
- Explain tradeoffs with observable costs: another database write, a stale snapshot,
  a changed response field, or an additional deployment component.
- Define unfamiliar project terms. Pair plan numbers with names when a number alone
  would force the reader to follow a link to understand the sentence.
- Use lists for parallel items and tables for comparisons. Use prose to explain causes
  and consequences. Avoid deeply nested lists and bold sentences used as mini-headings.
- Use emphasis sparingly for short labels or critical constraints, not to make ordinary
  statements sound more significant.

Remove these patterns during editing:

- Narrated deliberation: `the obvious answer was … but then we realised …`. State the
  selected approach and the relevant reason the alternative was rejected.
- Self-evaluation: `the honest accounting`, `the right fix`, `this proves the discipline worked`, or claims about how careful or insightful the work was.
- Rhetorical questions, dramatic contrasts, metaphors, and repeated closing lessons.
- Commentary about prose: `this paragraph used to say`, `listed here so the reader is not left thinking`, or `worth stating plainly`.
- Repeated explanations of the same rule in an introduction, table, and conclusion.
- Chat residue: `as discussed above` when it refers to a conversation, instructions to a later agent, or a recap of who investigated which file in which session.

These are editing criteria, not a banned-word list. A word replacement does not fix a
paragraph whose content belongs elsewhere. Do not shorten a document into unexplained
fragments or remove a necessary technical argument to meet a length target.

## Plans provide research for implementation planning

A plan must give a reviewer enough information to assess the change and a coding
assistant enough information to derive execution steps. Cover the following where
applicable; omit irrelevant sections rather than filling a template with boilerplate:

- **Problem and outcome:** the user need, concrete examples, and what success changes.
- **Current behavior:** relevant services, schema, interfaces, and measured limitations.
- **Scope:** included behavior, exclusions, compatibility requirements, and client impact.
- **Design:** affected components, data flow, contracts, failure behavior, and migrations.
- **Alternatives:** credible options, the recommended option, and consequential tradeoffs.
- **Dependencies:** accepted ADRs, required capabilities, and unresolved external inputs.
- **Open questions:** decisions or research still needed, with the impact of each answer.
- **Verification:** observable results, important edge cases, and how to establish them.

Component breakdowns, schema sketches, code examples, and dependency order are appropriate
when they explain or constrain the design. Distinguish required behavior from illustrative
implementation details. Do not prescribe an edit sequence merely because an author can
imagine one. Agent assignments, parallel sessions, session-duration estimates, tool-use
instructions, and exhortations to run checks belong outside the research plan.

Verification criteria describe evidence of success. For example: “A group containing both
a parent and child counts each stock entry once; compare the result with a fixture holding
both.” “Run tests and make sure everything works” supplies no acceptance criterion.

Keep numbered **Open questions** stable. Put review answers directly below their questions
as `> **Response:**` blocks. Separate unanswered questions from recorded answers. Preserve
the answer's meaning, attribution, conditions, and date where supplied; never invent an
answer or promote a tentative preference into a decision. When an answer changes a draft's
design, reconcile the affected sections in the same edit rather than appending a rebuttal.

For landed work, retain the proposed design in its original tense. Add an **Executed**
section containing what shipped, material departures and their reasons, verification
results, and remaining work. Several delivery entries are appropriate for separate pieces;
repeated retellings of the same implementation are not. An editorial pass must not rewrite
the original proposal to imply that the final implementation was planned all along.

## ADRs retain decision rationale

Follow the [ADR format and lifecycle](adr/README.md). Keep context, decision, and
consequences distinct. Explain why the choice addresses the problem, which alternatives
were credible, why they were rejected, and what the choice costs or prevents. A short
worked example is useful when it establishes an otherwise unclear constraint.

Retain relevant uncertainty and evidence against a proposal. Distinguish measured facts,
assumptions, recommendations, and maintainer decisions. Acceptance prerequisites must
remain explicit and testable. A proposed decision stays proposed even if code already
implements it.

An editorial cleanup must not change a decision, its status, acceptance gates, scope, or
unresolved questions. Follow the lifecycle for substantive changes to accepted decisions.
Implementation notes may record how an accepted choice was applied, but must not quietly
extend its authority.

## Keep status and history consistent

| Information | Authoritative home |
|---|---|
| Standing principles | [Constitution](constitution.md). |
| Decision and lifecycle state | The ADR, with its matching [index row](adr/README.md). |
| Delivery status and cross-plan order | [Plan index](plans/README.md). |
| Proposed design and review answers | The owning plan. |
| Shipped behavior, departures, and verification | The plan's Executed section. |
| Findings and supporting evidence | The originating review or finding record. |
| Current setup and operation | The relevant operational guide. |

Summaries may repeat essential facts for orientation, with a link to their authoritative
home. Avoid copying detailed status or rationale into multiple documents. When a fact
changes, update its owner, search for summaries that repeat it, and reconcile those in
the same change. Do not append “this is now complete” below an earlier “not started”.

Keep these states distinct:

- **Proposed:** awaiting a decision.
- **Accepted:** the maintainer has approved the decision under the ADR lifecycle.
- **Implemented / landed:** the code has shipped in the repository.
- **Verified:** specified checks have passed in a named environment.

Acceptance does not establish delivery, and delivery does not establish verification.
Name the completed piece and outstanding checks for partial work. Date time-sensitive
status and measurements; avoid unqualified “now”, “today”, and “still” in durable records.

Correct stale descriptions directly. Preserve material history where it explains a
constraint, a rejected alternative, an implementation departure, or who made a decision.
Record that history once, with a date and source, in the owning document. Routine editing
history belongs in Git. Do not delete evidence or move an unchanged essay into another
README merely to make the original file shorter. Upstream release history and attributed
quotations are not rewritten as part of a style cleanup.

## Evidence and references

Measurements identify the date, working copy or commit, method, and relevant environment.
State limits on the result: a build passing is not evidence that a deployed pod serves,
and a local check is not evidence that CI runs it.

Verify claims about scripts, configuration, checks, and workflows against their actual
definitions. Link to supporting records. Cite code symbols rather than unstable line
numbers; quote a small relevant expression when it makes the reference unambiguous.
Use repository-relative Markdown links and check both targets and section anchors.

When shortening a document, retain source links, finding identifiers, acceptance gates,
compatibility exceptions, and unresolved dependencies. If sources conflict and available
evidence does not resolve the conflict, identify the discrepancy without selecting a
convenient answer. Prose cleanup is not authorization to decide architecture or schedule.

## Examples

**Decision rationale**

Before:

> “The obvious implementation is a counter in memory, and it is wrong for a reason
> that has nothing to do with security craft and everything to do with the deployment.”

After: “Store the counter in a database table. An in-memory counter resets when the pod
scales to zero, allowing another set of attempts after each restart.”

**Status correction**

Before:

> “This section said the pod did not serve until review caught that all three images
> had already landed. The lesson is that a status row is not enough.”

After: “The pod served requests in the 2026-09-04 deployment check. Credential separation
and SIGTERM verification remain open.” Link to the check results in the owning plan.

**Plan scope**

Before:

> “While the auth files are open, have the second agent take the remaining fixes;
> this should be one sitting and leaves every track mergeable.”

After: “Authentication changes include key validation and permission checks. The MCP
endpoint depends on both because its bearer key must resolve to a permission-checked user.”

**Folder README entry**

Before:

> “Plan 20 is complete in the sense this table has always meant, although the nine
> defects discovered in two rounds show why the first build was never the whole question.”

After: “Plan 20: image builds and pod startup complete. Credential separation and signal
checks remain; see Executed for results.”

## Review before submitting

Apply this checklist to new or changed documentation. Review the resulting document,
not only the added lines.

- [ ] The content serves this document's purpose; detailed material has an appropriate home.
- [ ] A reader can understand it without the originating chat or review conversation.
- [ ] Technical reasoning explains a requirement or tradeoff; narrated deliberation,
      self-evaluation, and repeated conclusions have been removed.
- [ ] Requirements, examples, assumptions, proposals, and decisions are distinguishable.
- [ ] Status agrees with the authoritative record; repeated summaries and ordering are reconciled.
- [ ] Claims of delivery, verification, and CI enforcement have supporting evidence.
- [ ] Editing preserved decisions, gates, review answers, material history, and source links.
- [ ] Plans describe design and acceptance criteria without scripting an agent's execution.
- [ ] Links and anchors resolve, and tables remain readable rather than hiding long prose.

Formatting and link checks can support this review. They do not establish factual accuracy,
readability, or compliance with a decision's meaning.

## Proposed automated style checks

The proposed [Vale setup](../.devtools/vale/README.md) turns repeatable parts of these
conventions into checks. Its [writing guide](style-guide.md) covers sentence structure,
terminology, document purpose, and review criteria. The proposal preserves the authority
and lifecycle rules above.

Vale identifies passages for editing; it cannot verify facts, assess an architectural
tradeoff, or decide whether a requirement has changed. Reviewers remain responsible for
those checks. The proposed CI baseline tracks existing findings and rejects new ones.
