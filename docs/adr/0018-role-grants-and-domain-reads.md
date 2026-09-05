# ADR-0018: Roles contribute grants and domain reads require view permissions

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-09-05, alongside wave 3a implementation
- **Referenced by:** [plan 19](../plans/19-rbac.md)

## Context

Plan 19's answered questions specify roles as permission bundles and require domain
read gating before additional features expand the read surface. The existing permission
hierarchy resolves downward, and user administration compares effective permission sets.
The implementation needs to preserve those rules when grants arrive through roles.

## Decision

A user's effective permissions are the union of direct grants and all assigned roles,
expanded through the existing hierarchy. There are no deny grants. Role codes are immutable;
display names are editable, and built-in roles cannot be deleted.

Stock, shopping lists, chores, tasks, recipes and meal plans each have a view leaf under
the permission whose domain they narrow. Their page, API and generic object reads require
that leaf. Separately addressable userfields and product/recipe pictures use the same
policy. Calendar aggregation includes only events from domains the caller may read.
Existing users receive all six view leaves on upgrade; new users receive configured defaults.

Role management and direct grant changes require `USERS_EDIT`; inspecting grants requires
`USERS_READ`. A caller must hold everything a grant confers and everything a target user
already holds. Editing or deleting a role applies the target check to every current holder,
as well as checking the role's own grants. Permission-model writes serialize their checks
and mutations in a database transaction so concurrent role edits cannot invalidate a check.

## Consequences

This records the implementation of plan 19's answered questions; its Proposed status does
not change any accepted ADR. Acceptance, if chosen, remains a separate bookkeeping PR.

An existing installation retains its reads until an administrator deliberately narrows
them. Assigning Child or Guest does not subtract prior direct permissions. Inherited grants
remain visible and read-only in the permission tree; direct grants survive role removal.

Domain reads and price visibility remain separate. Wave 3a does not redact prices:
`STOCK_PRICES_VIEW`, `permission_fields` and redaction belong to plan 19 piece 2, alongside
the response snapshot in wave 5. Applications must not present Child or Guest as hiding
prices before that work ships.

The SQLite model remains frozen. PostgreSQL-only tests cover the new permission model;
the differential suite continues comparing the pre-existing model and view columns.
