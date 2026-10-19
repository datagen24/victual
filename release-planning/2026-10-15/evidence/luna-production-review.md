# Read-only production source review

Reviewer: Codex GPT-6 Luna, medium reasoning. Commit: `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`. Two bounded tasks: Plan 22 release requirements; applicability of existing operational verification issues. Coordinator recorded this report before using it in release recommendations.

## Findings

- Plan 22 lines 9–39 require issues 698–702, ADR-0043/#742, ADR-0045/#774, ADR-0046/#775, #782/#785, #783 disposition, #787/#788 and integrated verification #703. 698–700 are recorded closed; 701 implemented in 748/749/751 plus 755; 702 accepted on simulator evidence. #703 is incomplete.
- Plan 22 lines 254–273 require supported PostgreSQL test runs, pgTAP for SQL behavior, response-contract and Playwright checks, coverage ratchet, upgrade rehearsal, operator documentation, Vale, images/Helm and exact tested commits.
- Plan 22 lines 238–243 and 766–789 accept #702 simulator evidence. No physical-device requirement. The accepted evidence includes role-based UI exercise, 28/28 external-event fixture replays against server f73d2bec and client tests. Real Health store, identifiers on edit/delete, late and background delivery were not observed. The client acceptance matrix has an unresolved old SHA and must be repaired without inventing evidence.
- Plan 22 lines 36–39 explicitly defer #762/#654/#483/#86/#80 and retain #737's accepted post-0.5.0 disposition. #796 is not a whole-release gate; contracts for changed/depended-on operations are required.
- Release procedure docs/releases/README.md:9–37 requires version consistency, a completed release record and a signed tag on its master merge commit. The tag workflow builds and publishes six images and the Helm chart with digests. ADR-0030 remains Proposed despite being referenced by the procedure.
- Backup guide docs/manual/operator/backup-restore.md:1–5 and 33–48 requires operators to test a disposable restore before relying on backups. Plan 22 explicitly requires an upgrade rehearsal but does not separately name a release-wide restore drill; recommend evidence of recovery, distinguishing that recommendation from the existing gate.

## Operational scope

| Issue | Requirement and evidence | Release implication |
|---|---|---|
| #93 | docs/plans/25-label-infrastructure.md:936–942 leaves verification 12 half-met. Native worker printed; deployed worker-image-to-printer path is not established. | Required for a claim that this Kubernetes label path is verified. Not an unconditional Plan 22 requirement. |
| #133 | docs/plans/20-container-infrastructure.md:432–466,535–597 and deploy/README.md:527–541 record old Talos apply, probes, credential split and restart persistence. lifecycle.stopSignal was dropped; printer absent. Helm clean install at deploy/README.md:299–305 is not a 0.5.0 upgrade rehearsal. | Candidate Helm/image/upgrade checks are mandatory under Plan 22. Scope-specific signal and printer residuals need an explicit support claim/disposition. |
| #139 | docs/plans/18-mqtt-state-publication.md:575–580 and plans README:81 leave Home Assistant restart, sleeping-server date rollover and week-idle checks open. | Conditional on those MQTT/HA guarantees; not a Plan 22 dependency. |
| #86 | Plan 22:36 explicitly defers remaining MCP client/Inspector/soak checks; historical two-pod tools are not candidate evidence. | Keep deferred; do not claim those checks passed. |

Accepted ADR-0010:71–85 requires declared, unprivileged, stateless and idempotent workloads; ADR-0013:3–9 requires Nix images; ADR-0038:89–108,136–156 requires Helm and rendered k3s parity. Older evidence does not validate a new candidate.

## Limits

Read-only repository review; no suites or deployments run. References are at the named SHA. GitHub state must come from the coordinator inventory. No ADR was accepted or proposed policy adopted by this review.
