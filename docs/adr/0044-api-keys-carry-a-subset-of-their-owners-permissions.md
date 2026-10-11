# ADR-0044: An API key carries an optional subset of its owner's permissions

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. The maintainer has not yet decided that the feature is needed or in
  which release it ships.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-10, against `master` at `40b0358f`. Design work only: it changes no
  code, reserves no migration number and amends no accepted record.
- **Referenced by:** [plan 37](../plans/37-api-key-scoped-permissions.md).
- **Relationship:** builds on accepted [ADR-0018](0018-role-grants-and-domain-reads.md) and
  [ADR-0007](0007-auth-state-outlives-the-process.md); amends neither. Refines the per-key
  boundary in [`docs/mcp-interface-spec.md`](../mcp-interface-spec.md) section 4.2, which today
  is the `read_only` flag alone. Independent of
  [ADR-0043](0043-creating-shared-master-data-is-a-right-below-master-data-edit.md), though a
  narrower create right makes scoped keys more useful. Relies on
  [ADR-0006](0006-authenticated-issues-in-scope.md).

## Context

The manage-keys dialog offers a key type (Regular or MCP) and, for MCP, a read-only checkbox.
The type only decides which client path accepts the key (`VICTUAL-API-KEY-TYPE`, the sidecar).
`ApiKeyAuthenticator::Authenticate` returns the owner's user row, so every key acts with all of
its owner's resolved permissions. The one per-key restriction is `api_keys.read_only`, enforced
by HTTP method in `BaseAuthMiddleware::ReadOnlyKeyRefusal`.

A household cannot say "this key may add to the shopping list and nothing else". The only
workaround is a dedicated user holding exactly those permissions, which is heavy and puts a
second identity in every audit trail.

Read from the source on 2026-10-10:

- Permission checks reach the database in one place. `User::GetPermissions()` filters
  `user_permissions_resolved` to `VICTUAL_USER_ID`; `HasPermission`, `CheckPermission`,
  `PricesVisible`, `MayAdminister` and `CheckMayGrant` all sit on it or on
  `ResolvedPermissionNames()`. No view or trigger resolves permissions from the session user.
- `GET /api/user/capabilities` reports the acting user's resolved permissions, and the MCP
  sidecar filters its tool list from that response.
- Header keys are tried on API routes only, so a key cannot reach `/manageapikeys/new`. On the
  generic API `api_keys` is in `ExposedEntityNoEdit` (which also refuses add) and
  `ExposedEntityNoListing`, but not in `ExposedEntityNoDelete`: `DeleteObject` checks
  ownership and no permission, so any key can delete its owner's other keys.

The decision is how to let a key hold less than its owner without a second identity and without
a new kind of role.

## Decision (proposed)

### 1. A key is unscoped or scoped to a set of permissions

An unscoped key behaves exactly as today and is what every existing key becomes. A scoped key
carries a set of permission ids. Two columns carry this, a flag on `api_keys` and a join table,
because "scoped with no permissions" (no access) must be distinguishable from "unscoped".
The migration is PostgreSQL-only, per [ADR-0008](0008-postgresql-only-runtime-engine.md), and
defaults every existing key to unscoped.

### 2. Effective rights are the intersection

A request made with a scoped key holds the permissions that are in both the owner's resolved set
and the key's resolved set, each expanded through the existing hierarchy. A key therefore never
exceeds its owner: listing a permission the owner lacks is a no-op, and removing a permission
from the owner narrows the key at once. There are no deny grants, consistent with ADR-0018.

### 3. The intersection lives in `User`, for the acting credential only

The filter applies in `User::GetPermissions()` and `ResolvedPermissionNames()` when the acting
key is scoped. It does not apply when `ResolvedPermissionNames()` is asked about a different
user, because the scope belongs to the credential and not to the target of an administration
check. The key's grants are read from the database on each request, so no state lives in the
process (ADR-0007). `GET /api/user/capabilities` gains a `scoped` boolean, an additive change.

### 4. Roles are templates, not assignments

A key is never assigned to a role. The dialog may pre-tick the permission checkboxes from a
role's grants, copied at creation. Later edits to the role do not change the key. A role has a
lifecycle (edit, delete, built-in codes) that must not silently move a live credential.

### 5. Scope is fixed at creation; rotation copies it

Changing a key's rights means issuing a successor, as with `read_only`. `RotateApiKey` copies
the scope so that rotation cannot widen a key. `read_only` stays a separate, additional
restriction and composes with scope.

### 6. Creation never confers more than the creator holds

Creating a scoped key applies the existing rule: every permission ticked must exist, and the
creator must hold what it would confer (`CheckMayGrant`). An empty scoped set is refused.

### 7. A scoped key may delete only itself

`DELETE /api/objects/api_keys/{id}` today needs no permission. A scoped key may delete only the
key it authenticated with. This is the one change to a route's behaviour.

### 8. Special-purpose keys and the key type are untouched

Calendar and label worker, verifier and renderer keys keep their own scoping and are created
unscoped. The key type stays what it is; no new type is added for a bundle of rights.

## Alternatives considered

- **Assign a key to a role.** Reuses a table, but couples a long-lived credential to a mutable
  bundle. Kept only as the prefill in decision 4.
- **A key type per bundle.** Multiplies `USER_ISSUED_KEY_TYPES`, the header-narrowing logic and
  the assumption in `FindValidApiKey` that user-issued types hash alike.
- **A dedicated limited user per client.** Works today, stays the advice when a separate audit
  identity is wanted, and is not an answer for a household that wants one account.
- **Push the scope into the database session.** Unneeded: the permission check is in PHP only.

## Consequences

- Existing keys, and keys whose owner is an administrator, behave as before until someone scopes
  one. No data migration is needed beyond the default.
- A scoped key still reaches any route that checks no permission today (some reads; see
  [plan 19](../plans/19-rbac.md) for the domain gates). The manual says so; a scoped key is not
  a sandbox.
- The MCP sidecar's tool list narrows to a key's scope with no sidecar change, since it reads
  `GET /api/user/capabilities`. The server still enforces on the forwarded call, as before.
- Cost: one extra query per request made with a scoped key, bounded by the permission count.
