# ADR-0040: Consumption recipes are private rows shared through scoped rights that confer no permission

- **Status:** **Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-09, against `master` at `216af2b2`. Design work only: it changes no
  code, reserves no migration number, and amends no accepted record.
- **Referenced by:** [plan 22](../plans/22-medication-tracking.md) (Q3, Q5, Q11) and
  [issue 695](https://github.com/datagen24/victual/issues/695). Dependent implementation:
  [issue 698](https://github.com/datagen24/victual/issues/698).
- **Relationship:** compatible with accepted
  [ADR-0014](0014-administering-a-user-is-a-subset-question.md),
  [ADR-0018](0018-role-grants-and-domain-reads.md) and
  [ADR-0035](0035-batteries-equipment-calendar-and-custom-entities-require-view-permissions.md).
  Option C below would amend ADR-0014 and is rejected here for the reason given.

## Context

[Plan 22](../plans/22-medication-tracking.md) adds a reusable consumption recipe: a list of
products and quantities that a person consumes together. A prescription is one such recipe.
Its owner can let specifically named members read or use it. Belonging to the household
grants no access.

No row in the current schema has an owner who is allowed to hide it from other holders of
the same permission. Measured on `master` at `216af2b2` (source inspection, 2026-10-09):

- **Reads are gated by a domain leaf and nothing finer.** `EntityReadPolicy::PERMISSIONS`
  (`controllers/Users/EntityReadPolicy.php`) maps each exposed entity to one view
  permission. Anyone holding `RECIPES_VIEW` reads every recipe row. There is no row
  predicate on `/objects/{entity}`, `query[]` filters, counts, `/recipes/fulfillment`,
  the calendar or the label resolver.
- **A new table is not generically readable by default.** `GetObject` and `GetObjects`
  require the entity to be in both `EntityReadPolicy` and the `ExposedEntity` enum in
  `victual.openapi.json`; an entity in neither answers 400. `label_artifacts` and
  `label_captures` use this to stay out of the generic API.
- **`stock_log.recipe_id` is readable with `STOCK_VIEW`.** `GET /objects/stock_log`,
  `GET /stock/bookings/{id}` and `GET /stock/transactions/{id}` return it, so a booking
  that carried a private recipe's id would disclose it to every stock reader.
- **Two paths skip the read policy.** `LabelIdentityService::ResolveTarget` selects a
  recipe's name with a raw query, and `PUT /api/userfields/{entity}/{id}` checks only
  `MASTER_DATA_EDIT` with no entity coverage check.
- **ADR-0014 compares permission names.** `User::MayAdminister()` and
  `User::CheckMayGrant()` read `user_permissions_resolved` and `permission_tree`. A row in a
  sharing table is not a permission, so neither function sees it. The plan 22 draft of
  2026-09-04 named this failure: a table saying "user X may see recipe Y" is "a permission
  wearing a different shape", and an account could hand out access its own administrator
  cannot see it holding.

The decision therefore has to answer two separate questions: how a share is stored and
evaluated, and what an account administrator can and cannot do with it.

### Terms

- **Consumption recipe:** an owned list of product lines, each with a quantity and unit.
  Distinct from the food `recipes` table; see [issue 698](https://github.com/datagen24/victual/issues/698).
- **Owner:** the user who created the recipe, or the user it was transferred to.
- **Share:** one row granting one other user a set of rights on one recipe.
- **Right:** one of `read`, `consume`, `edit`, `undo`, `share`, defined below.

## Decision (proposed)

### 1. Storage separates private data from every generic surface

A consumption recipe, its lines, its shares and its consumption events live in tables
distinct from `recipes`, `recipes_pos`, `meal_plan` and `stock_log`. None of them is added
to `EntityReadPolicy::PERMISSIONS` or to the `ExposedEntity` enum, so every generic path
refuses them with the existing 400. Dedicated routes are the only way to read or change them.

Table and column names are for [issue 698](https://github.com/datagen24/victual/issues/698) to
fix. The decision constrains the shape:

- The owner is a non-null foreign key to `users`.
- Shares are unique on (recipe, user) and never reference the owner.
- `stock_log.recipe_id` is never set for a consumption recipe. The recipe is linked to its
  bookings by a separate event table keyed by `transaction_id`, readable only through the
  dedicated routes. A booking in `stock_log` therefore carries no pointer to the recipe.

### 2. A share narrows rows and confers no permission

Whether a user may perform an action on a recipe is the conjunction of two independent
tests, both evaluated inside the transaction that performs the action:

> **share right held on that recipe** AND **the global permission the same act needs
> elsewhere in the application**

| Right | What it allows on the recipe | Global permission also required |
|---|---|---|
| `read` | View name, lines, quantities and the event history of that recipe | `STOCK_VIEW` |
| `consume` | Book the recipe's lines as one consumption | `STOCK_CONSUME` |
| `edit` | Change the name, lines and quantities | `STOCK_VIEW` |
| `undo` | Undo, through the recipe, a consumption the recipe produced | `STOCK_EDIT`, which the stock undo routes require today |
| `share` | Add, change or remove shares, within rule 4 | `STOCK_VIEW` |

Every non-owner share includes `read`; a grant of any other right adds it. The owner holds all
five rights and the owner row cannot be revoked. Creating a recipe requires `STOCK_VIEW` and
`STOCK_CONSUME`, the permissions needed to use it.

A holder of `STOCK_EDIT` can still undo any booking through the stock routes, including one a
consumption recipe produced, because stock history is shared inventory (rule 10). The `undo`
right governs only the recipe's own undo route, which also returns the recipe's event to
its unconsumed state.

Because the global permission is checked at use time, a user whose `STOCK_CONSUME` is removed
loses the ability to consume through every share at once, with no change to the share rows.
Because a share never adds to the global side, no sequence of share writes can raise anyone's
resolved permission set. `User::CheckMayGrant()` and `User::MayAdminister()` have nothing to
bypass, and neither is modified.

No new `permission_hierarchy` row, permission constant or role grant is introduced.
[ADR-0018](0018-role-grants-and-domain-reads.md)'s view-leaf set is unchanged, and the
`RECIPES_VIEW` leaf plays no part: a consumption recipe is not a food recipe.

### 3. Grants cannot confer more than the grantor holds, and cannot create dead rights

A grantor may share only rights the grantor holds, mirroring ADR-0014's rule that a caller
confers nothing it does not hold. The recipient must be an existing user who holds, at the
moment of the grant, the global permission each granted right needs. A grant that would be
inert (a `consume` share to a user without `STOCK_CONSUME`) is refused with 422 and names the
missing permission, the same reasoning that made unknown permission ids an error in
ADR-0014 (sweep S27). The use-time intersection in rule 2 still applies afterwards, because
a permission can be removed later.

### 4. Who may grant and revoke

- The **owner** may grant, change and revoke any share and may grant the `share` right.
- A **`share` holder** may add, change and revoke shares only for the rights `read`,
  `consume`, `edit` and `undo`, only for rights the `share` holder holds, and not for another
  user's `share` right. A `share` holder cannot grant `share`, transfer or delete the recipe.
  Delegation therefore has depth one, and revoking a `share` holder cannot leave behind a
  chain of shares that nobody can account for.
- A share row records `granted_by` and `granted_at` so the owner can see who added whom.
- A `share` holder cannot change or remove a share that holds a right the holder lacks.
- A user may remove their own share.

### 5. Ownership transfer and deletion

- **Transfer** is by the owner to a user who already holds a share, in one transaction that
  rewrites the owner column and turns the previous owner into a share holding all five
  rights. The new owner may then revoke it. Recipients cannot refuse a transfer in this
  design; requiring a share first means the recipient could already see the recipe.
- **Recipe deletion** is by the owner only. It removes the recipe, its lines and its shares.
  Event rows lose their recipe reference and keep the transaction identifier, so stock
  history is unchanged.
- **Account deletion** removes the user's shares. Recipes the user owns are deleted unless
  open question 2 is answered otherwise. The delete-account confirmation names the count of
  owned recipes.

### 6. Account administrators have no implicit access

`ADMIN` and the `USERS_*` permissions grant no right on any consumption recipe. No dedicated
route reads a recipe on the strength of a global permission. `ADMIN` is the root of the
permission tree, and this decision relies on shares not being in that tree. An administrator
who wants access is shared the recipe like anyone else.

One path stays open and is stated so the decision does not overclaim. ADR-0014 lets an
administrator who holds everything a user holds reset that user's password and sign in as
them. Signing in as the owner reads the owner's recipes. This is the takeover ADR-0014's
Consequences already accepts ("the person who can take over the administrator account is the
administrator"). Open question 1 asks whether to close it.

### 7. Absent means absent

A caller who has no `read` right on a recipe receives the same answer as for a recipe that
does not exist: the row is missing from lists and counts, and a direct fetch or any write
returns **404**. A caller who holds `read` but lacks the right a write needs receives **403**,
which discloses nothing they cannot already see.

List endpoints filter before applying `query[]`, ordering, paging and counts. The
filter is part of the source query so that a malformed or probing `query[]` cannot act as an
existence oracle through a database error.

### 8. Concurrency

Grant, change, revoke, transfer and delete take `SELECT ... FOR UPDATE` on the recipe row.
Every operation that relies on a share (read-then-write, consume, undo, edit) takes
`FOR SHARE` on the same recipe row for the length of its transaction and evaluates the share
and the global permission after the lock is held. The lock order is: recipe row first,
then the ascending product lock set `DatabaseService::LockProductsStock()` already
uses. A single global order prevents a consumption and a revocation from deadlocking.

Outcomes:

| Interleaving | Result |
|---|---|
| Consume begins before revoke commits | Consume completes under the old share; the revoke waits, then applies. |
| Revoke commits before consume takes its lock | Consume finds no share and answers 404. |
| Two grants to the same user | The second sees the first's row and updates it; the unique constraint makes a double insert impossible. |
| Grantor loses a right while granting | The grantor's own share row is re-read under the recipe lock; the grant is refused. |
| Ownership transfer during a consume | The consume completes; ownership changes afterwards. |

A removal of a global permission is read where the application reads permissions today. The
design does not add a lock on the permission tables; a consume that started before the
permission change completes.

### 9. Surface matrix

"Absent" means the recipe, its owner and its refill data do not appear, and the surface
behaves as if the recipe did not exist.

| Surface | Behavior |
|---|---|
| `GET /objects/{entity}` and `/{id}` | The new tables are not in `ExposedEntity` or `EntityReadPolicy`: 400, as for any unexposed entity. |
| `query[]`, counts, ordering | Not reachable through generic routes. Dedicated list routes filter before querying (rule 7). |
| `GET/PUT /userfields/{entity}/{id}` | The new entities are refused explicitly on both routes. The `PUT` route gains a coverage check, since it does not call `EntityReadPolicy::Covers()` today. |
| `userobjects` and `userentity-*` | Not applicable; no user-defined entity may reference a consumption recipe. |
| Food-recipe routes, `/recipes/fulfillment`, meal plan | Unaffected; consumption recipes are not rows in those tables and cannot be placed in the meal plan. |
| Calendar and iCal feed | Never include a consumption recipe or refill date derived from one. |
| Labels, captures, scan resolver | No `recipe`-style label kind is added for consumption recipes. `ResolveTarget` has no branch for them. The stock-entry and location labels in plan 22 already exist and carry no recipe data. |
| Stock reads (`stock_log`, bookings, transactions, journal) | Visible under `STOCK_VIEW` as today. `recipe_id` is null for consumption recipes. A consumption still appears as stock rows sharing a `transaction_id`. |
| Webhooks, MQTT, Influx | No consumption recipe name, owner or refill field is published. Booking events remain stock events. |
| MCP sidecar | No tool reads a consumption recipe. [Issue 694](https://github.com/datagen24/victual/issues/694) records the interface-policy text. |
| Backup, restore and `victual-db-import` | Whole-database operations. They carry the new tables and are not a sharing surface; [issue 698](https://github.com/datagen24/victual/issues/698) decides the import behavior. |
| Dedicated routes | Owner or a share is required for each; the right needed is in rule 2. |

### 10. Shared inventory is not private recipe data

Stock the household holds stays visible to every `STOCK_VIEW` holder: the product, quantity,
location and expiry. A consumption booking is a stock booking and appears in the stock
journal with its products, amounts and time. A stock reader can therefore observe that those
products left stock together. This leak is accepted: it is the same information a shared
household pantry has always exposed, and hiding bookings would break undo, lineage and
auditing. What stays private is the recipe's name, its owner, its shares and its refill
rules and dates ([ADR-0015](0015-medication-records-never-advises.md) and the refill
specification under [issue 697](https://github.com/datagen24/victual/issues/697)).

## Options considered

**A. Scoped shares that narrow rows and confer nothing (this record).** No permission tree
change, no amendment, no change to `User.php`. It leaves the administrator-takeover path
that ADR-0014 already accepts and states it. Recommended.

**B. One permission row per recipe and right in `permission_hierarchy`.**
`CheckMayGrant()` and `MayAdminister()` would see every share unmodified. It fails on the
tree's shape: `ADMIN` is the root and resolves downward to every descendant, so every
administrator would hold every recipe's rights, which contradicts the owner-private
requirement. Excluding these rows from `ADMIN` needs a special case in `permission_tree`.
It also adds a tree row per recipe and right, shows recipe ids in the permission
endpoints and the permission UI, and mixes owner-managed data with administrator-managed
data in one table. Rejected.

**C. Amend ADR-0014 so "everything they hold" includes scoped shares.** An administrator
could then not reset a password of any account that holds a share or owns a recipe, unless
the administrator held the same rights. On a household instance every member who uses the
feature becomes immune to the single administrator, which breaks account recovery for the
exact users the feature serves.

It also needs a superseding ADR accepted before
[issue 698](https://github.com/datagen24/victual/issues/698) can rely on it, so it adds a
governance gate for a rule this record's authors do not recommend. Rejected as the default;
open question 1 offers a narrower variant.

**D. Household-wide "all members" principal.** Rejected for this release. A principal
that means everyone is a permission-shaped grant, and the plan decision is that household
membership alone grants no access. Sharing with every member is N shares.

## Consequences

- **No amendment is required.** This record adds a mechanism that composes with the
  accepted authorization rules; it does not alter them, so [issue 698](https://github.com/datagen24/victual/issues/698)
  is not blocked on a lifecycle change to ADR-0014 or ADR-0018. It is blocked on this
  record's acceptance, because a Proposed record constrains nothing.
- **Every consumption goes through two checks.** A share read per request plus the permission
  checks that already exist. The recipe row lock serializes writes on one recipe, not
  stock-wide.
- **The administrator-takeover path remains.** An administrator who resets the owner's
  password can read the owner's recipes. The record states this rather than hiding it.
- **Users learn the limits of privacy.** Stock history shows what left stock and when. The
  manual page for private recipes states this.
- **The surface matrix is a checklist for [issue 698](https://github.com/datagen24/victual/issues/698).**
  Each row is tested with an owner, an authorized member, an unrelated member and an account
  manager fixture; the plan's verification list names those fixtures.
- **Unlimited sharing breadth is not offered.** No group principal, no link sharing, no
  expiring share. Each would be a separate decision.

## Acceptance prerequisites

1. The maintainer answers open questions 1 and 2, or accepts the stated leans.
2. The maintainer confirms that Option A needs no amendment to ADR-0014. The reading rests
   on this record leaving `MayAdminister()` and `CheckMayGrant()` unmodified and on no share
   write raising anyone's resolved permissions.
3. [Issue 698](https://github.com/datagen24/victual/issues/698) states the table and column
   names and the dedicated route list, and each matrix row in rule 9 has a test named in
   that issue's pull request. This prerequisite is for the implementation pull request, not
   for acceptance.

## Open questions

1. **Should credential reset of an account that owns or holds shares require the same
   rights?** Today an administrator holding the target's permissions can reset a password
   and so read the target's recipes. Requiring the administrator to hold the target's recipe
   rights would block that, and would also block recovery of any such account until the
   owner revokes shares or the account is deleted. *Lean: keep ADR-0014's behavior and state
   it in the manual; a household instance's administrator can read the database regardless.*
2. **What happens to recipes when their owner's account is deleted?** Options: delete them
   (rule 5), or transfer to the lowest-id `share` holder. *Lean: delete, with a count in the
   confirmation, because an unexpected new owner receives data the former owner chose not to
   give them.*
3. **May an administrator delete a recipe they cannot read, to clear a stuck record?** *Lean:
   no dedicated route; the administrator deletes the owner account (question 2).*
4. **Is a household-wide principal wanted later?** Deferred by Option D. Its answer would
   need to say how it appears in permission checks.
