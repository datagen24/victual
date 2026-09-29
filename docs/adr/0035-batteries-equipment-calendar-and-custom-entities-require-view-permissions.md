# ADR-0035: Batteries, equipment, calendar and custom entities require view permissions

- **Status:** Accepted, 2026-09-29. Recorded as the maintainer's decision in the #487
  remediation. The record names no acceptance prerequisites, so this is bookkeeping only per
  the lifecycle rule. **Partly supersedes [ADR-0018](0018-role-grants-and-domain-reads.md)**:
  its decision that batteries, equipment and custom entities keep their previous read policy,
  and plan 19's Executed section statement that piece 1 "does not add view leaves for them,"
  no longer hold. Every other part of ADR-0018 — roles as permission bundles, the six original
  view leaves, role and grant administration — is unchanged.
- **Decider:** datagen24
- **Recorded:** 2026-09-29
- **Referenced by:** [plan 19](../plans/19-rbac.md)

## Context

PR [#630](https://github.com/datagen24/victual/pull/630)'s derived route sweep (issue #521,
part of #487) found that `BatteriesController`'s seven page routes,
`BatteriesApiController::Current`/`BatteryDetails`, `CalendarController::Overview`,
`CalendarApiController::Ical`/`IcalSharingLink`, and `GenericEntityController`'s
userentities/userfields/userobjects pages called `User::CheckPermission()` nowhere. Any
authenticated session, holding no permissions at all, could read every battery, the whole
calendar feed (every due date, chore, task and meal-plan entry `CalendarService::GetEvents()`
assembles), every equipment row and every custom entity/field definition.

ADR-0018 and plan 19's Executed section record this as a deliberate choice for batteries,
equipment and custom entities, not an oversight: "Batteries, equipment and custom entities
retain their previous read policy; this wave does not add view leaves for them."

The calendar page itself was never named at all. Only calendar *aggregation* (filtering
which events the feed contains by the caller's other view leaves) was in scope for piece 1.

#630's sweep is what surfaces that the "previous read policy" for all four is "no gate
whatsoever." That is the security defect this record answers, superseding the parts of
ADR-0018 and plan 19 that called it settled.

## Decision

Three new permission leaves — `BATTERIES_VIEW`, `CALENDAR_VIEW` and `EQUIPMENT_VIEW` — are
added to `permission_hierarchy` as children of `BATTERIES`, `CALENDAR` and `EQUIPMENT`
respectively (migration 0299), the same nesting migration 0281 uses for `STOCK_PRICES_VIEW`
under `STOCK_PURCHASE` rather than the sibling-with-unconditional-backfill shape migration
0266 used for the original six `*_VIEW` leaves. `BatteriesController`'s seven page methods and
`BatteriesApiController::Current`/`BatteryDetails` require `BATTERIES_VIEW`.
`CalendarController::Overview` requires `CALENDAR_VIEW`. `EquipmentController::Overview`/`EditForm`
require `EQUIPMENT_VIEW`.

**Upgrade grant rule.** No row is written into `user_permissions` for the new leaves. A role
or user who already holds the parent permission (`BATTERIES`, `CALENDAR` or `EQUIPMENT`, via a
role grant, a direct grant, or `ADMIN`) resolves to the corresponding new leaf through
`permission_tree`'s existing recursive CTE, the moment the hierarchy row exists. That is the
same mechanism that already makes a holder of `STOCK_PURCHASE` resolve to
`STOCK_PRICES_VIEW`. A user holding neither the parent nor the new leaf loses the read access
this record removes; that loss is the point, not a side effect to work around.

**iCal is a separate case.** `CalendarApiController::Ical()` is reachable two ways: a logged-in
session, and an external calendar client with no session at all, via a `secret` query
parameter `ApiKeyAuthenticator::CalendarSharingSecret()` accepts on this one route, validated
against a special-purpose API key. The token *is* the authorization for that second path — a
shareable calendar link works by possession, the same way a password-reset link does — so a
request carrying a secret that validates against `ApiKeyService` bypasses the permission check
entirely. `CALENDAR_VIEW` is required only when no such secret is presented (ordinary session
access), and unconditionally by `IcalSharingLink()`, which creates or reveals that secret in
the first place.

**Custom entities get no new leaf.** `GenericEntityController`'s userentities/userfields/userobjects
pages require the same permission(s) `GenericEntityApiController::AddObject`/`EditObject`/`DeleteObject`
already require for a *write* to the same entity — `MASTER_DATA_EDIT`, plus `ADMIN` for
userentities/userfields (`victual.openapi.json`'s `ExposedEntityEditRequiresAdmin` enum) —
rather than a `*_VIEW` leaf. `controllers/Users/EntityReadPolicy.php` already maps all three
entities to no read policy at all (`null`), so there is no existing read/write asymmetry to
narrow here. There is only a page that read without checking anything a sibling write path
already checks.

`SystemController::GetEntryPageRelative()` (the root redirect) and the sidebar nav check the
same three new leaves instead of the bare parent permission, matching every sibling domain
(`STOCK_VIEW`, `CHORES_VIEW`, etc.) now that the pages themselves require them.

## Consequences

This reverses ADR-0018's stated choice for exactly three domains (batteries, equipment,
custom entities) and adds the calendar page gate ADR-0018 never addressed; ADR-0018's
six-domain view-leaf model, role administration and grant-resolution rules are otherwise
unchanged and remain in force.

An existing installation's roles and users keep what they can do today wherever they already
hold the parent permission (`BATTERIES`, `CALENDAR` or `EQUIPMENT`); a role or user holding
none of the three loses the corresponding read, which is the security fix. A calendar sharing
link already handed to an external application keeps working with no change, because the
secret it carries is unaffected by this record.

`tests/Pgsql/HouseholdPagesTest.php`'s three tests pinning the old policy —
`testBatteryPagesFollowTheRecordedReadPolicyOfNoViewLeaf`,
`testEquipmentPagesFollowTheRecordedReadPolicyOfNoViewLeaf`,
`testUserentityPagesFollowTheRecordedReadPolicyOfNoViewLeaf` — and the batteries assertion in
`testCalendarEventListShrinksWithTheCallersViewLeaves` are updated to assert this record's
policy instead, renamed to describe what they now assert. `tests/Pgsql/RbacTest.php`'s
`PROTECTED_CONTROLLERS`/`EXCEPTED_GET_CONTROLLERS` partition (#630) moves
`BatteriesController`, `BatteriesApiController`, `CalendarController`, `CalendarApiController`
and `EquipmentController` into `PROTECTED_CONTROLLERS`, now that every one of their GET
methods refuses uniformly without a grant. `GenericEntityController` stays in
`EXCEPTED_GET_CONTROLLERS`: its pages are gated on `MASTER_DATA_EDIT`/`ADMIN`, not a `*_VIEW`
leaf, so it does not fit the six-domain `*_VIEW` pattern the sweep otherwise verifies, even
though every method also refuses uniformly without a grant.
