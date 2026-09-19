# ADR-0006: Issues requiring an authenticated account are in scope

- **Status:** **Accepted** 2026-08-29, with the security policy this fork adopted.
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively. The decision itself is stated in
  [.github/SECURITY.md](../../.github/SECURITY.md) and is the reason the
  [security sweep](../security-sweep.md) exists in the shape it does.
- **Referenced by:** [security sweep](../security-sweep.md),
  [11](../plans/11-api-error-handling.md), [15](../plans/landed/15-deliberate-cleanup.md),
  [19](../plans/19-rbac.md).

## Context

Upstream grocy's position is that it "is not an enterprise application and neither one you
(should) host publicly", and that unless something can be abused *unauthenticated* it is
"practically irrelevant" and not worth reporting. That is a reasonable call for upstream's
stated use case.

It is not the call here, for two reasons that are properties of this deployment rather
than opinions about upstream:

- **The perimeter is a tailnet, and a tailnet endpoint is a device on a LAN** that also
  holds IoT hardware, guest devices and whatever a family member installed last week.
  "Behind the perimeter" is not "on a trusted host".
- **The household is multi-user and the users are not peers.** A household ERP with chore
  tracking means real accounts for family members, children included. Thirty permission
  constants exist precisely because those accounts are meant to differ.

## Decision

The authenticated trust boundary is a real boundary. A low-privilege account escalating to
admin, reading another user's data, or reaching configuration is a genuine finding in this
fork and is fixed rather than waved away. Anything exploitable with only network
reachability is in scope regardless of the tailnet.

## Consequences

- **This fork's issue tracker is the right place for such findings and upstream's is not.**
  They will not be reportable there, and saying so is part of the policy.
- It is not theoretical. Two live examples were found in a single review pass before the
  policy was written down: `/api/system/config` returned `DB_PASSWORD`, `DB_USER`,
  `DB_HOST` and `LDAP_BIND_PW` to any authenticated API key, and any authenticated user
  could delete any other user's API key by id, with no ownership check and sequential ids.
  Both fixed in `36650cd`; neither reportable under upstream's policy.
- **It sets the severity scale the sweep uses.** Several sweep findings — S5's
  `DEFAULT_PERMISSIONS = ['ADMIN']`, S6's `USERS_EDIT` password reset, S16's generic
  column write — are only interesting under this model, and are rated as findings because
  of this decision rather than despite it.
- It is the standing reason [19](../plans/19-rbac.md) is worth building at household scale:
  a permission model that gates no field is a gap under this threat model and merely
  untidy under upstream's.
