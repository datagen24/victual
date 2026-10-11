# 37. API keys that carry chosen rights

**Goal:** A person issuing an API key can say what it may do. A key for a shopping-list
automation holds `SHOPPINGLIST_ITEMS_ADD` and nothing else; a key for the MCP assistant holds
the read leaves its tools need.

Today the dialog offers a key type and, for MCP keys, a read-only checkbox, and every key acts
with all of its owner's rights.

**Depends on:** [ADR-0044](../adr/0044-api-keys-carry-a-subset-of-their-owners-permissions.md)
(Proposed). Builds on [ADR-0018](../adr/0018-role-grants-and-domain-reads.md) (the permission
model) and [ADR-0007](../adr/0007-auth-state-outlives-the-process.md) (no state in process
memory). Touches [plan 02](02-mcp-endpoint.md)'s per-key boundary (spec section 4.2).
**Status:** draft, research only, 2026-10-10. No issue exists yet and no migration number is
claimed; claim the next free one above 0307 in `migrations/RESERVATIONS.md` when it is written.
The first pull request is the ADR and this plan; implementation waits on the ADR being accepted
and on open questions 1 and 2.

## BLUF

The dialog is not hiding a feature: the model has no per-key grant. Add an optional subset of
the owner's permissions to a key. A request made with a scoped key gets the permissions the
owner holds that the key also lists. Roles are offered as a template that pre-ticks the
checkboxes, never as something a key is assigned to. Every permission check already goes
through two methods in `controllers/Users/User.php`, so enforcement is small; the care is in
the edges under "Holes to close".

## What exists today (read from the tree, 2026-10-10)

| Fact | Where |
|---|---|
| Key types a person may issue: `default`, `mcp` (`USER_ISSUED_KEY_TYPES`). The type decides *which client path* accepts it (`VICTUAL-API-KEY-TYPE` header, sidecar), not what it may do | `services/ApiKeyService.php` |
| The only per-key restriction is `api_keys.read_only` (0/1): non-GET/HEAD/OPTIONS refused with 403, plus a short `WRITING_GET_ROUTES` list | migration 0287, `BaseAuthMiddleware::ReadOnlyKeyRefusal` |
| A key authenticates *as its owner*: full rights of that user, nothing narrower | `ApiKeyAuthenticator::Authenticate` returns the user row |
| Permission checks read `user_permissions_resolved` filtered to `VICTUAL_USER_ID` | `User::HasPermission` → `GetPermissions()`, `User::ResolvedPermissionNames()` |
| `/api/user/capabilities` already reports `{key_type, read_only, permissions}` and the MCP sidecar filters its tool list from it | `UsersApiController::CurrentUserCapabilities`, `docs/mcp-interface-spec.md` §4.2/§5 |
| Roles are permission bundles (`roles`, `role_permissions`, `user_roles`); a caller may not grant what they lack (`CheckMayGrant`) | ADR-0018, `docs/manual/operator/roles-permissions.md` |
| The create/rotate UI is `views/manageapikeys.blade.php` + `public/viewjs/manageapikeys.js`, posting a form to `/manageapikeys/new` | `OpenApiController::CreateNewApiKey` |

So the dropdown is not hiding a feature; the model has no per-key grant at all. The one
workaround today is to create a dedicated *user* holding only the wanted rights and issue the
key from that account, which is heavy for a household install.

## Design

### Semantics
- A key is either **unscoped** (today's behaviour, the default for every existing key) or
  **scoped** to a set of permissions.
- Effective permissions of a scoped-key request = owner's resolved set ∩ key's resolved set.
  Granting a key a permission the owner lacks is therefore a no-op, and revoking something
  from the owner narrows the key automatically. A key can never exceed its owner.
- The key's grants are expanded through the same hierarchy as user grants (ticking
  `STOCK_CONSUME` confers only that leaf; ticking `STOCK` confers its children). No deny grants,
  consistent with ADR-0018.
- `read_only` stays as a separate, additional restriction (method-level, enforced before any
  controller). A scoped key may also be read-only; the two compose.
- Scope is **fixed at creation**, as `read_only` is. Changing it means rotating. Rotation
  copies the scope (rotation must never widen, same rule as `read_only` in `RotateApiKey`).
- Key type is untouched and orthogonal: `mcp` still means "accepted via the sidecar path".
  Do **not** invent a new "api role" key type.

### Schema (one PostgreSQL-only migration, next free number above 0307)
- `api_keys.scoped SMALLINT NOT NULL DEFAULT 0 CHECK (scoped IN (0,1))`
- `api_key_permissions (api_key_id INTEGER REFERENCES api_keys(id) ON DELETE CASCADE,
  permission_id INTEGER REFERENCES permission_hierarchy(id), PRIMARY KEY (api_key_id, permission_id))`
- A separate flag, not "has rows", because *scoped with zero rows* (no access) must differ from
  *unscoped*. The UI refuses an empty scoped set anyway; the schema stays unambiguous.
- Default 0 means every existing key keeps exactly today's authority (same property
  migration 0287 states for `read_only`). Claim the number in `migrations/RESERVATIONS.md`.

### Enforcement (single choke point)
Checked by grep: the only PHP readers of `user_permissions_resolved` / `uihelper_user_permissions`
are `User.php` (current user) and `UsersApiController::ListPermissions` (an admin reading
*another* user). The database never resolves permissions itself: the one session value pushed
down by `SyncDatabaseUserContext()` is the user id for `victual_user_setting()`, and the
`current_setting` uses in migrations are booking/label settings, not permissions. So an
intersection in PHP is complete; nothing needs pushing into the DB session.
- `ApiKeyAuthenticator` already calls `SetActingApiKey`. In `User::GetPermissions()` /
  `HasPermission()` / `ResolvedPermissionNames()` (current-user paths only), when the acting
  key is scoped, restrict to names in the key's resolved set. Resolve the key set once per
  request (it is stateless between requests, per ADR-0007; no process-memory cache).
- Consequences that fall out for free: `User::CheckPermission` on every route,
  `MayAdminister`/`CheckMayGrant` (a narrow key cannot administer or grant), price redaction
  (`PricesVisible` uses `HasPermission`), domain-read gating, and
  `GET /api/user/capabilities`, so the MCP sidecar lists only tools the key can use with no
  sidecar change.
- Add `scoped: bool` to the capabilities response (additive, per the roadmap's additive-API
  rule) and update the contract snapshot and `victual.openapi.json`.
- `ResolvedPermissionNames($otherUserId)` for a *different* user must stay unfiltered: the
  scope applies to the acting credential, not to the target of an admin check. Test this
  explicitly; it is the easiest place to get wrong.

### Holes to close (found while reading, not hypothetical)
1. **`DELETE /api/objects/api_keys/{id}` needs no permission** (`GenericEntityApiController::DeleteObject`,
   ownership only). A "chores only" key could delete its owner's other keys, including the
   one a household relies on. Fix: a scoped key may not delete keys other than itself, or
   require a permission for it. Needs a decision; recommend "scoped keys may delete only
   themselves".
2. **Reads that check no permission** (`EntityReadPolicy` has `'api_keys' => null`; some
   read routes are ungated). A scoped key keeps those reads. State this limit in the docs
   rather than imply a scoped key is sealed; it is a pre-existing property of the route set,
   and plan 19 closed the six domain reads, not everything.
3. **Key minting and editing (checked, currently closed).** Header keys are tried only when
   `IsApiRoute` (`DefaultAuthMiddleware`, `ReverseProxyAuthMiddleware`), so a key cannot reach
   `/manageapikeys/new`. On the generic API `api_keys` is in `ExposedEntityNoEdit` (which also
   blocks add) and `ExposedEntityNoListing`, so a key can neither create, edit nor list keys.
   Only DELETE is open (hole 1). Add tests asserting all of this stays true, and keep
   `api_key_permissions` out of the exposed-entity list, because a scoped key that could mint or
   un-scope a key defeats the feature.
4. **The special-purpose keys** (calendar, label worker/verifier/renderer) have their own
   scoping and are out of scope. `CreateApiKey` for them must keep `scoped = 0`.
5. **Owner demotion.** Intersection handles it, but also assert a scoped key whose owner
   loses ADMIN does not keep an ADMIN-derived leaf.

### UI (`manageapikeys.blade.php`, `manageapikeys.js`)
- In the create dialog keep **Key type**; add a **Rights** group:
  - radio: *Everything I can do* (default) / *Only these rights*;
  - when "Only these", show the permission tree as checkboxes, built as nodes per the
    frontend-sink rule (never concatenated into `.html()`), reusing the user-permissions page's
    tree markup so wording and hierarchy match;
  - a *Start from role* select that pre-ticks boxes from a role's `role_permissions`
    (a copy at creation time; later edits to the role do not change the key). This answers
    the "I can't create an API role" complaint without a new entity.
  - only permissions the creator holds are enabled; others are shown disabled, which matches
    `CheckMayGrant` on the server.
- Keep the MCP *Read-only* checkbox; for an MCP key the default stays on.
- Table: add a **Rights** column ("All" or a count with a tooltip listing names). Reveal
  block after creation states the scope next to the one-time key.
- Server: `CreateNewApiKey` accepts `permissions[]` (ids), validates through the existing
  S27 existence check and the subset-of-caller rule, rejects an empty scoped set, and writes
  `api_key_permissions` in the same transaction as the key.
- API parity: if keys can only be created from this page today, no new REST surface is
  needed. If the maintainer wants key creation over REST later, it inherits the same
  validation; out of scope here.

## Decisions to record
[ADR-0044](../adr/0044-api-keys-carry-a-subset-of-their-owners-permissions.md) decides: (a) subset-of-owner, not a separate role identity; (b) scope fixed at
creation, changed by rotation; (c) roles are templates, not assignments; (d) read-only
composes with scope; (e) the `DELETE api_keys` rule. Cross-reference ADR-0018, ADR-0007,
and amend `docs/mcp-interface-spec.md` §4.2 (the per-key boundary is no longer only
`read_only`). Accepting it is its own bookkeeping pull request.

## Alternatives considered
- **Key assigned to a role.** Reuses a table, but a role is a union-of-grants bundle with
  built-in codes and a delete/edit lifecycle that would silently change live keys. Rejected as
  the primary model; kept as a prefill.
- **More key types (one per bundle).** Multiplies the `USER_ISSUED_KEY_TYPES` surface, the
  header-narrowing logic and the hashing assumption in `FindValidApiKey`. Rejected.
- **Dedicated limited user per client.** Works today with no code and stays a valid advice for
  strong separation (distinct audit identity); document it as the escape hatch.

## Work breakdown
1. ADR draft (Proposed) + this plan into `docs/plans/` with a README row. Separate PR.
2. Migration + `ApiKeyService` (create/rotate carry scope, `ActingKeyPermissions()`),
   `User` intersection, capabilities field. PostgreSQL tests first.
3. `CreateNewApiKey` validation + the `DELETE api_keys` rule.
4. UI: dialog, table column, reveal text, localization strings, `s29-payload.js` probe case
   for the new markup (`manageapikeys` already has a QR case to copy).
5. Docs: manual `roles-permissions.md` (new "API key rights" section, incl. the limits in
   hole 2), `mcp-interface-spec.md` §4.2, changelog, OpenAPI.

## Verification (what would count as evidence)
- `tests/Pgsql/AuthStackTest.php`-style cases and `.devtools/pgsql/apikey-tests.php`:
  - scoped key with `{STOCK_CONSUME}` can consume, gets 403 on purchase, on user admin and on
    a price field; unscoped sibling key of the same owner unchanged;
  - owner loses a permission → key loses it; key lists a permission the owner never had →
    still refused;
  - rotation copies scope and read-only; scoped+read-only composes;
  - `/api/user/capabilities` returns the intersection and `scoped:true`; sidecar tool list shrinks;
  - `ResolvedPermissionNames(otherUser)` unfiltered (admin check from a broad key unchanged);
  - hole 1 and hole 3 regression cases.
- Frontend probe (`s29-payload.js`) and a Playwright pass of the dialog via the `run-app` skill.
- Coverage floor 75% holds for the touched files. Note PHP is absent on some hosts, so run
  the suite per `memory/reference_local_environment.md` (podman), not a bare `php`.

## Open questions for the maintainer
1. Hole 1: scoped keys delete only themselves, or require a permission? (Recommend the former.)
2. Should the first cut hide the permission tree behind presets only (Read-only / Shopping /
   Consume / Custom) to keep the dialog small, or show the full tree? (Recommend presets plus a
   "Custom" that opens the tree; presets are just prefill lists, no new data.)
3. Is an expiry shorter than the 365-day default worth defaulting for scoped keys? Not
   required; mention only because the dialog is being touched.
