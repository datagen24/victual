# 37. API keys that carry chosen rights

**Goal:** A person issuing an API key can say what it may do. A key for a shopping-list
automation holds `SHOPPINGLIST_ITEMS_ADD` and nothing else; a key for the MCP assistant holds
the read leaves its tools need.

Today the dialog offers a key type and, for MCP keys, a read-only checkbox, and every key acts
with all of its owner's rights.

**Depends on:** [ADR-0044](../adr/0044-api-keys-carry-a-subset-of-their-owners-permissions.md)
(accepted 2026-10-25). Builds on [ADR-0018](../adr/0018-role-grants-and-domain-reads.md) (the permission
model) and [ADR-0007](../adr/0007-auth-state-outlives-the-process.md) (no state in process
memory). Touches [plan 02](02-mcp-endpoint.md)'s per-key boundary (spec section 4.2).

**Status:** draft, research only; revised 2026-10-25. No issue exists yet and no migration number is
claimed; claim the next free one in `migrations/RESERVATIONS.md` when it is written.
ADR-0044 was accepted on 2026-10-25, and its open questions 1 and 2 were answered on
2026-10-11, so implementation is no longer gated on the record. No release is chosen. The
Proposed [ADR-0057](../adr/0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
keeps the key intersection but would express key scopes over its per-resource catalog.

Question 1 below is answered by ADR-0044 decision 7. Questions 2 and 3 retain draft
recommendations; no maintainer answer is recorded for them.

## BLUF

The dialog is not hiding a feature: the model has no per-key grant. Add an optional subset of
the owner's permissions to a key. A request made with a scoped key gets the permissions the
owner holds that the key also lists. Roles are offered as a template that pre-ticks the
checkboxes, never as something a key is assigned to. Every permission check already goes
through two methods in `controllers/Users/User.php`, so enforcement is small; the care is in
the edges under "Holes to close".

## Relationship to the permission and OAuth proposals

ADR-0044 authorizes scoped user-owned keys against the accepted permission catalog.
This plan does not depend on selecting an OAuth authorization server. It does not implement
the Proposed ADR-0055, ADR-0056 or ADR-0057.

The Proposed [ADR-0057](../adr/0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
would put roles, key selections and OAuth scopes over one per-resource permission catalog.
Its groups would contribute to the owner's effective permissions; the key would remain
bounded by its own selection. Its service credentials would use immutable API role package
versions. User-owned keys would continue to use explicit selections, with roles and presets
used only to prefill the dialog. This plan adds no service accounts or packages.

If ADR-0057 is accepted and implemented, presets and the permission tree must use that
catalog. A catalog migration must compare scoped keys' allowed operations before and after,
as well as users' access. ADR-0044 currently expands key grants through the hierarchy;
this plan does not replace that rule with the frozen leaf sets proposed for service packages.

The Proposed [ADR-0055](../adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
would retain static keys indefinitely on installs without an authorization server.
On OAuth installs, the operator would move through coexistence, refusal of new keys and
rotation, and retirement of existing user-issued keys. Scope replacement creates a new key,
so it would also be refused in the second phase. Those lifecycle controls belong to the
OAuth implementation. Token exchange, audience validation and exchange attribution do not
change this plan's owner-permissions intersection.

## Existing behavior (source review, 2026-10-10)

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
- Scope is **fixed at creation**. Changing rights revokes the key and creates a replacement
  with the selected scope, under ADR-0044 decision 5. Routine rotation copies the existing
  scope and `read_only`; it cannot widen either restriction.
- Key type is untouched and orthogonal: `mcp` still means "accepted via the sidecar path".
  Do **not** invent a new "api role" key type.

### Schema (one PostgreSQL-only migration, number reserved at implementation)
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
   one a household relies on. ADR-0044 decision 7 requires a scoped key to delete only
   the key it authenticated with. No general key-management permission is added.
   This ownership restriction does not bypass `read_only`: the method-level check
   continues to refuse DELETE for a read-only key.
2. **Reads that check no permission** (`EntityReadPolicy` has `'api_keys' => null`; some
   read routes are ungated). A scoped key keeps those reads. State this limit in the docs
   rather than imply a scoped key is sealed; it is a pre-existing property of the route set,
   and plan 19 closed the six domain reads, not everything. ADR-0057 proposes explicit
   resource-read checks, but they are not supplied by this plan. Recheck the remaining
   ungated reads against the implementation commit and document what remains reachable.
3. **Key minting and editing (checked, currently closed).** Header keys are tried only when
   `IsApiRoute` (`DefaultAuthMiddleware`, `ReverseProxyAuthMiddleware`), so a key cannot reach
   `/manageapikeys/new`. On the generic API `api_keys` is in `ExposedEntityNoEdit` (which also
   blocks add) and `ExposedEntityNoListing`, so a key can neither create, edit nor list keys.
   Only DELETE is open (hole 1). Add tests asserting all of this stays true, and keep
   `api_key_permissions` out of the exposed-entity list, because a scoped key that could mint or
   un-scope a key defeats the feature.
4. **The special-purpose keys** (calendar, label worker/verifier/renderer) have their own
   scoping and are out of scope. `CreateApiKey` for them must keep `scoped = 0`.
   ADR-0057 proposes moving them individually to service accounts and package versions.
   Each move requires its own plan and evidence that the credential's rights are preserved.
5. **Owner demotion.** Intersection handles it, but also assert a scoped key whose owner
   loses ADMIN does not keep an ADMIN-derived leaf.

### UI (`manageapikeys.blade.php`, `manageapikeys.js`)
- In the create dialog keep **Key type**; add a **Rights** group:
  - radio: *Everything I can do* (default) / *Only these rights*;
  - proposed presentation, pending question 2: when "Only these", show Read-only, Shopping,
    Consume and Custom. Presets prefill explicit permission selections. Custom opens a
    searchable tree grouped by feature and resource, using the same catalog and wording
    as the user-permissions page;
  - build checkboxes as nodes per the frontend-sink rule, never concatenated into `.html()`;
  - show the selected rights before creation. Include any required reads in that review;
    never add them silently. Keep Consume limited to its workflow even if a future OAuth
    Stock bundle includes purchase, transfer or inventory;
  - Read-only prefills read permissions and enables the separate `read_only` restriction.
    Customizing the selection does not silently clear that restriction;
  - a *Start from role* select that pre-ticks boxes from a role's `role_permissions`
    (a copy at creation time; later edits to the role do not change the key). This answers
    the "I can't create an API role" complaint without a new entity.
  - only permissions the creator holds are enabled; others are shown disabled, which matches
    `CheckMayGrant` on the server.
- Keep the MCP *Read-only* checkbox; for an MCP key the default stays on.
- Pending question 3, the draft keeps the existing 365-day expiry default. Keep expiry
  visible and configurable; this plan does not shorten existing keys' lifetimes.
- Table: add a **Rights** column ("All" or a count with a tooltip listing names). Reveal
  block after creation states the scope next to the one-time key.
- Server: `CreateNewApiKey` accepts `permissions[]` (ids), validates through the existing
  S27 existence check and the subset-of-caller rule, rejects an empty scoped set, and writes
  `api_key_permissions` in the same transaction as the key.
- API parity: if keys can only be created from this page today, no new REST surface is
  needed. If the maintainer wants key creation over REST later, it inherits the same
  validation; out of scope here.

## Accepted decisions and implementation boundaries
[ADR-0044](../adr/0044-api-keys-carry-a-subset-of-their-owners-permissions.md) decides: (a) subset-of-owner, not a separate role identity; (b) scope fixed at
creation, changed by revocation and replacement; routine rotation preserves scope; (c) roles are templates, not assignments; (d) read-only
composes with scope; (e) the `DELETE api_keys` rule. Cross-reference ADR-0018, ADR-0007,
and update `docs/mcp-interface-spec.md` §4.2 with the implementation. ADR-0044 was accepted
on 2026-10-25. The Proposed ADR-0057 changes the catalog and service-credential model only
if separately accepted; this plan does not grant it authority.

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
   Done; ADR-0044 accepted 2026-10-25.
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
  - changing scope revokes the old key and creates a replacement; routine rotation copies
    scope and read-only; scoped+read-only composes;
  - `/api/user/capabilities` returns the intersection and `scoped:true`; sidecar tool list shrinks;
  - `ResolvedPermissionNames(otherUser)` unfiltered (admin check from a broad key unchanged);
  - a scoped key can delete itself when not read-only, but cannot delete a sibling key;
    a scoped read-only key remains unable to DELETE; hole 3 remains closed;
  - presets copy selections without assigning a role or package; later preset edits do
    not change stored grants, and selections cannot exceed the creator's permissions.
- Frontend probe (`s29-payload.js`) and a Playwright pass of the dialog via the `run-app` skill.
- Application coverage meets the repository's aggregate ratchet and per-file policy.
  Record the measured coverage in the implementation PR. PHP is absent on some hosts, so run
  the suite per `memory/reference_local_environment.md` (podman), not a bare `php`.

## Open questions for the maintainer
1. Hole 1: scoped keys delete only themselves, or require a permission?

   > **Response:** Answered by ADR-0044 decision 7, accepted 2026-10-25: a scoped key
   > may delete only the key it authenticated with.

   The separate read-only restriction continues to apply; see hole 1.

2. Should the first cut hide the permission tree behind presets only (Read-only / Shopping /
   Consume / Custom) to keep the dialog small, or show the full tree?

   Draft recommendation, awaiting a maintainer answer: presets plus Custom opening the
   grouped, searchable tree. Presets prefill explicit selections from the shared catalog;
   they create no role assignment or service package. Review their exact permission
   lists and read requirements before implementation.

3. Is an expiry shorter than the 365-day default worth defaulting for scoped keys?

   Draft recommendation, awaiting a maintainer answer: retain the current default for
   this change. OAuth's proposed short access-token lifetime depends on separate renewal
   mechanisms; it does not determine a static key's expiry. A shorter static-key default
   needs a separate renewal policy. Existing keys retain their expiry.
