# ADR-0057: Permissions are per-resource leaves, assigned through roles, groups and service packages

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request. It records the assignment model the maintainer set out in
  [issue 1111](https://github.com/datagen24/victual/issues/1111) on 2026-10-25 and proposes
  answers to that issue's four design questions; none of those answers is the
  maintainer's yet.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-25, against `master` at `42883e0e`. Design only: it changes no
  code and reserves no migration number.
- **Referenced by:** [issue 1111](https://github.com/datagen24/victual/issues/1111);
  [ADR-0044](0044-api-keys-carry-a-subset-of-their-owners-permissions.md),
  [ADR-0055](0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
  and [ADR-0056](0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md),
  each of which names the decision here that reconciles it.
- **Relationship:** if accepted, partly supersedes accepted
  [ADR-0043](0043-creating-shared-master-data-is-a-right-below-master-data-edit.md)
  decisions 1 and 2 (one flat create right over a stated set of entities) and extends
  accepted [ADR-0018](0018-role-grants-and-domain-reads.md)'s union with a group source
  (decision 4). Keeps [ADR-0014](0014-administering-a-user-is-a-subset-question.md) and
  ADR-0018's no-deny rule unchanged. Reconciles the Proposed ADR-0044, ADR-0055 and ADR-0056
  without treating any of them as accepted. Must be accepted before ADR-0055 fixes its scope
  list, because issue 1111 asks for the model to be agreed first.

## Context

Victual's permission catalog is 41 constants in `controllers/Users/User.php`, arranged in
`permission_hierarchy` and resolved downward by `permission_tree`: holding a permission
means holding every permission below it. Several of them gate many resources at once.
ADR-0043 measured `MASTER_DATA_EDIT` gating create, edit and delete for products,
conversions, locations, quantity units, barcodes, stores, product groups and more, and
added `MASTER_DATA_ADD` below it as one create right over a stated set of entities. A
household still cannot grant "edit locations" without granting "delete products".

Issue 1111 sets out the model the maintainer wants before OAuth scopes are fixed:

```text
Users and agents:   Permissions → Roles → Users or Groups   (group members inherit)
                    plus exceptional direct grants, primarily break-glass
User-owned keys:    explicit key permissions ∩ owner's effective permissions
Service keys:       Permissions → API role packages → service API keys
```

It also requires separate Create, Read, Update and Delete permissions per resource type,
explicit domain actions beside them, an explicit read for most data, and no silent
privilege change on upgrade.

The existing records each carry part of this:

- ADR-0018 (accepted): effective permissions are the union of direct grants and assigned
  roles, expanded through the tree, with no deny grants. It has no groups.
- ADR-0043 (accepted): one create right, `MASTER_DATA_ADD`, below `MASTER_DATA_EDIT`.
- ADR-0044 (Proposed): a user key carries an optional subset of its owner's permissions;
  the intersection rule is what issue 1111 wants. Its decision 4 forbids assigning a key
  to a role, so that a role edit cannot move a live credential. Its decision 8 leaves the
  label worker, verifier and renderer keys with their own scoping.
- ADR-0055 (Proposed): OAuth tokens narrow by scope through the same intersection; four
  bundle scopes plus one scope per permission leaf.
- ADR-0056 (Proposed): provider groups map directly to roles.

The inventory of every resource, action and current check is
[`.devtools/issue1111/INVENTORY.md`](../../.devtools/issue1111/INVENTORY.md). It is a static
read of the source at `42883e0e`; nothing in it was exercised.

## Decision (proposed)

### 1. One catalog of leaves: CRUD per resource type, plus named actions

Every resource type the inventory lists gets a leaf per operation it supports, named
`<RESOURCE>_CREATE`, `<RESOURCE>_READ`, `<RESOURCE>_UPDATE` and `<RESOURCE>_DELETE`.
A resource type is a table or an equivalent addressable set (products, locations, stores,
chores), never a single row. An operation a resource does not support has no leaf: stock
history is never deleted directly, so there is no `STOCK_LOG_DELETE`. The inventory's
"n/a" entries are that list, and no leaf is invented to complete a matrix.

Domain actions that are not CRUD keep or gain their own leaves, named for the action.
They include consume, purchase, open, transfer, inventory and undo for stock; track and
undo for chores and battery charges; complete and undo for tasks; print, reprint and
cancel for label jobs; and merging records. Their data-integrity rules do not change; a leaf decides who
may ask, never what the action writes.

This catalog is the only list. Roles, user keys, service packages (decision 7) and OAuth
scopes (decision 9) all refer to its leaves, so there is no second vocabulary to keep in
step.

### 2. Today's broad permissions become parent nodes, so the upgrade changes nobody

The new leaves are added as children of the permission that gates the operation today,
as ADR-0043 decision 1 added `MASTER_DATA_ADD`. Because the tree resolves downward, an
account holding `MASTER_DATA_EDIT` holds every leaf placed beneath it from the moment
the rows exist, with no backfill and no change in what it can do. In outline:

- `MASTER_DATA_ADD` becomes the parent of each master-data `*_CREATE` leaf.
- `MASTER_DATA_EDIT` keeps `MASTER_DATA_ADD` as a child and becomes the parent of each
  master-data `*_UPDATE` and `*_DELETE` leaf.
- Domain permissions (`STOCK`, `CHORES`, `TASKS`, `RECIPES`, `SHOPPINGLIST`,
  `BATTERIES`, `EQUIPMENT`) become parents of their resources' leaves in the same way.
- Where one check today guards an operation on several resources, each resource's leaf
  sits under that same parent.

The broad names survive as convenience bundles: assignable, shown in the UI as "all of
these", and expanded to leaves before any comparison. A grant of a single leaf is the
new capability. Code checks leaves only; a check against a parent node is a defect once
the leaves exist, because it ignores a leaf granted alone.

The acceptance rehearsal compares every account's resolved permission set and a probe of
allowed and refused requests before and after on each install path. Equality is the
gate. Where the inventory shows today's checks already disagree between page and API, or
check nothing, the record lists the change as an intentional one, with the accounts it
affects, in the accepting pull request. ADR-0043 decision 7 used the same rule for chores
and tasks.

This partly supersedes ADR-0043 decisions 1 and 2: `MASTER_DATA_ADD` stops being a leaf
that gates create on a stated set and becomes the parent of per-resource create leaves.
Decisions 3 to 7 of ADR-0043 hold. Decision 6 matters most here: creating a row still
confers no right over it.

### 3. Reads are explicit, and upgrade grants the reads people already have

Every readable resource gets a `*_READ` leaf, and every route that reads it, page, API,
generic object route, MCP tool and file access alike, checks it. Authentication alone
reads nothing beyond the caller's own profile and settings and the capabilities response
that describes the caller.

Where today's code checks no permission on a read, the upgrade gives every existing user
and every existing role that read, as ADR-0018 did for its six view leaves. New users get
the reads their roles carry. The six existing view leaves (`STOCK_VIEW` and the rest)
become parents of the reads in their domain, so an account that holds them keeps what it
can see.

Read prerequisites are stated per leaf in the catalog, not inferred. A write leaf whose
form needs another resource's data (adding a product needs the quantity-unit and location
lists) names those reads. The role editor and key dialog show the prerequisite and offer
to add it. They never grant it silently, because a read granted as a side effect is a read
nobody approved. A leaf granted without its prerequisite is legal: it works through the
API with known ids and fails in the form with a message naming the missing read.

### 4. Roles reach users directly and through groups

A user's effective permissions are the union of:

1. roles assigned to the user;
2. roles assigned to any local group the user belongs to;
3. exceptional direct grants (decision 5);

each expanded through the tree, with no deny grants. This extends ADR-0018's first
sentence with source 2 and changes nothing else in it. A group is a named set of users
with assigned roles. It holds no permission of its own, so there is one place a
permission set is defined: the role.

Agents, meaning automated actors that act as a household member would (an assistant with
its own sign-in), are users of kind `agent`. They take roles and groups like any user and
never hold a password login (open question 5).

Assigning a role to a group, or a user to a group, is a grant to every member. It applies
ADR-0014's subset rule to the union it would create, as `CheckMayGrant` does for a role
assignment today. Editing a role or a group membership previews the users, agents and
credentials whose effective set would change before it saves.

### 5. Direct grants stay, as the exception path

Direct grants remain available. They are the break-glass path and the place for a
narrow exception. The UI presents them apart from role assignment, labelled as direct,
with their effect on the user's effective set shown. They are deliberately removable, one
by one, and removing a role does not remove them (ADR-0018). This record neither
deprecates nor removes them. Deprecation needs its own decision, a migration path and a
replacement for emergency access.

### 6. Effective access is traceable to its source

For any principal (user, agent, user key, service credential, OAuth grant) Victual can
answer "which leaves, and why". The answer lists each leaf with the role,
group-and-role, direct grant, package version, key subset or token scope that contributes
it. For a key or token it also lists what the owner's ceiling removes. The permission UI and an administration API both
read that answer. A key or scope leaf the owner no longer holds is shown as ineffective,
with that reason, not hidden.

### 7. Service credentials hold versioned API role packages

A **service account** is a system-owned principal for one Victual workload (initial
candidates: MCP sidecar, Victualer, label renderer, label printer worker). It is not a
user, cannot log in, and owns no data. A **service credential** authenticates as a
service account.

An **API role package** is a named, versioned set of leaves, kept apart from roles:

- Editing a package creates a new version. A credential is pinned to one version and
  keeps it until an administrator moves it, after a preview of what the move changes.
  That keeps ADR-0044 decision 4's rule that a mutable bundle never silently moves a
  live credential, and answers issue 1111's second design question (open question 2).
- Revoking a credential takes effect on its next request, as a key revocation does today.
  Retiring a package version requires moving or revoking its credentials first.
- A package may contain only leaves its author holds (ADR-0014), and never `ADMIN`, a
  `USERS_*` leaf, role or group management, key or client management, or package
  management. A service credential cannot administer anything.

A service account acts as itself. A service may call Victual **on behalf of a user**,
as the MCP sidecar does for a household member's tool call. That request is the user's.
It holds the user's effective permissions narrowed by the user's key or token, as
ADR-0055 decision 7 describes. The service's package plays no part. A package therefore never
lifts a user-delegated request above the user's own ceiling, which is issue 1111's
requirement that packages must not bypass the owner's ceiling.

The calendar-sharing, label worker, verifier and renderer keys keep their current scoping
(ADR-0044 decision 8) until a plan moves each to a service account with a package that
resolves to exactly its current rights. The same equality gate as decision 2 applies.

### 8. User-owned keys keep ADR-0044's intersection, over the new catalog

ADR-0044 decisions 1, 2, 3, 5, 6 and 7 apply unchanged, with "permission" meaning a leaf
or bundle of this catalog. A key is never assigned to a role or a package (ADR-0044
decision 4); a role may still prefill the dialog. A user cannot give a key a leaf they do
not hold. Removing a leaf from the owner removes it from the key at once, and adding one
to the owner adds nothing to the key.

ADR-0044 decision 8 is narrowed by decision 7 here: special-purpose keys keep their own
scoping only until each moves to a service account.

### 9. OAuth scopes are catalog leaves and named bundles of them

For ADR-0055 decision 5: each leaf scope is one leaf of this catalog, under its own name
in a `victual:` namespace. Each bundle scope is a named set of leaves defined in the
catalog beside the roles. A user-delegated token holds the intersection of the leaves its
scopes expand to and the user's effective set at the time of the request (decision 4),
not at consent. Consent cannot grant a leaf the user lacks, and the consent screen marks
such a leaf as unavailable with the reason.

For ADR-0055 decision 4 a client-credentials client takes one of two forms.
A client a user registers acts as that user narrowed by its ceiling, as ADR-0055 says. A
client an administrator registers for a workload is a service credential of decision 7,
pinned to a package version. Nothing else can hold a client-credentials grant.

For ADR-0056 decision 4: a provider group maps to a **local group**, not directly to a
role. The provider sets membership of mapped groups; the group's roles come from
Victual. A mapping can confer only what its author holds (ADR-0014), and membership from
the provider is recorded with that source so it is never mistaken for a manual one
(open question 4).

## Options considered

- **Replace the broad permissions outright.** Delete `MASTER_DATA_EDIT` and friends and
  backfill each holder with the equivalent leaves. Each holder ends with the same rights,
  but it rewrites every install's grants and loses the bundle every administrator uses
  today. Decision 2 reaches the same effective sets with no backfill.
- **A permission per row.** Rejected by issue 1111 itself: per resource type, not per
  product or location.
- **Owner-scoped edit.** ADR-0043 decision 6 deferred ownership columns; nothing here
  changes that.
- **Service keys assigned to roles.** It reuses one table, but a role edit would move every
  service credential at once, and roles carry user-administration leaves no service
  should hold. Versioned packages avoid both.
- **Provider groups straight to roles (ADR-0056 as proposed).** It works, but it gives a
  second assignment path that the local group UI cannot show or preview. Mapping to local
  groups keeps one path.

## Consequences

- The catalog grows from 41 permissions to several times that; the inventory's section 6
  counts it. The UI must group leaves by feature and resource with plain-language labels,
  or the model is unusable. That is plan work.
- No install loses or gains access on upgrade except where the accepting pull request
  lists an intentional change. The rehearsal that proves it is a prerequisite.
- Code paths that check a broad permission today move to leaf checks. A missed site keeps
  working for existing accounts (the parent still resolves the leaf) but ignores a leaf
  granted alone. The inventory is the checklist, and a test asserts no check names a
  parent.
- Groups, agents, service accounts and packages are new tables. Their migration numbers
  are reserved by the implementing pull requests, not here.
- ADR-0055's scope list becomes derived from this catalog, so the four bundles are
  redefined over leaves rather than over the 41 permissions.

## Acceptance prerequisites

1. **The inventory is complete and current.** `.devtools/issue1111/INVENTORY.md` lists
   every resource and action with its current check, and is refreshed against the
   `master` commit the accepting pull request names.
2. **The catalog and tree are written down**: every leaf, its parent, its read
   prerequisites and the resources' unsupported operations, in a plan under
   `docs/plans/`.
3. **Household roles are agreed.** Administrator, Household Manager, Adult, Child and
   Guest (or the maintainer's replacement list) each with representative workflows and an
   exact leaf list, approved by the maintainer.
4. **The equality rehearsal passes.** On each install path (fresh install, upgrade from
   the latest release, legacy import), every account's resolved set and a probe of
   allowed and refused requests match before and after, except the listed intentional
   changes.
5. **Open questions 1 to 4 answered** by the maintainer.

## Open questions

Each has a proposed answer for the maintainer to accept, change or reject.

1. **Who may provision service credentials, who owns them, and what ceiling applies?**
   Proposed: provisioning requires a new `SERVICE_ACCOUNTS_MANAGE` leaf under `ADMIN`. The
   service account, not the provisioning administrator, owns the credential, so it
   survives that administrator's removal. The ceiling is the package version (decision 7),
   which its author must hold in full when writing it.
2. **Do package edits change existing credentials immediately?** Proposed: no. An edit is
   a new version; credentials move only when an administrator moves them after a preview;
   revocation is immediate. A security fix that must narrow every credential at once is a
   move of all credentials in one action, still previewed.
3. **What exact CRUD and action matrix, read prerequisites and household-role defaults?**
   Proposed: the plan in prerequisite 2 derives them from the inventory. This record
   fixes the shape (decisions 1 to 3), not the matrix.
4. **How are local and provider groups reconciled?** Proposed: provider groups map to
   local groups (decision 9). A local group is either manual or provider-managed, never
   both, so the provider cannot erase a manual membership and an administrator cannot
   be overwritten silently at the next login. ADR-0056 decision 4's recompute rule
   applies to provider-managed memberships only.
5. **What is an agent?** Proposed: a user row of kind `agent` with no password and no
   browser login. It authenticates by key or OAuth grant only and is visible in the user
   list with its kind, so it cannot be mistaken for a household member.
