# ADR-0040: Consumption recipes are private rows shared through scoped rights that confer no permission

- **Status:** **Accepted 2026-10-09.** Shares narrow rows and confer no permission, so ADR-0014 and ADR-0018 are not amended. Prerequisites are stated in the accepting pull request.
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

No entity that `EntityReadPolicy` covers has an owner who may hide a row from other holders of
the same permission (read from the policy map; the rest of the schema was not audited).
Measured on `master` at `216af2b2` (source inspection, 2026-10-09):

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
- **One path skips the read policy.** `LabelIdentityService::ResolveTarget` selects a
  recipe's name with a raw `SELECT id, name FROM recipes WHERE id = ?`, gated only by the
  label kind's permission. `PUT /api/userfields/{entity}/{id}` checks `MASTER_DATA_EDIT`
  and relies on `UserfieldsService::SetValues()` refusing an entity that is not exposed.
- **ADR-0014 compares permission names.** `User::MayAdminister()` and
  `User::CheckMayGrant()` read `user_permissions_resolved` and `permission_tree`. A row in a
  sharing table is not a permission, so neither function sees it. The plan 22 draft of
  2026-09-04 (before commit `4ce3c662` rewrote the plan) named this failure: a table saying
  "user X may see recipe Y" is a permission in a different shape, and an account could hand
  out access its own administrator cannot see it holding.

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
- Consumption events are visible to the user who recorded them. A share on the recipe does not
  expose another user's events, which can describe when that person took a medication.
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
| `read` | View name, lines and quantities of that recipe, and the caller's own consumption events for it | `STOCK_VIEW` |
| `consume` | Book the recipe's lines as one consumption | `STOCK_CONSUME` |
| `edit` | Change the name, lines and quantities | `STOCK_VIEW` |
| `undo` | Undo, through the recipe, a consumption the recipe produced | `STOCK_EDIT`, which the stock undo routes require today |
| `share` | Add, change or remove shares, within rule 4 | `STOCK_VIEW` |

Every non-owner share includes `read`; a grant of any other right adds it. The owner holds all
five rights and the owner row cannot be revoked. Creating a recipe requires `STOCK_VIEW` and
`STOCK_CONSUME`, the permissions needed to use it.

A holder of `STOCK_EDIT` can still undo any booking through the stock routes, including one a
consumption recipe produced, because stock history is shared inventory (rule 11). The `undo`
right governs only the recipe's own undo route. The event row reads `stock_log.undone` for its
bookings and does not copy it. [Issue 696](https://github.com/datagen24/victual/issues/696)
owns how a stock-level undo is reconciled with an imported event.

Because the global permission is checked at use time, a user whose `STOCK_CONSUME` is removed
loses the ability to consume through every share at once, with no change to the share rows.
Because a share never adds to the global side, no sequence of share writes can raise anyone's
resolved permission set. `User::CheckMayGrant()` and `User::MayAdminister()` have nothing to
bypass, and neither is modified.

No new `permission_hierarchy` row, permission constant or role grant is introduced.
[ADR-0018](0018-role-grants-and-domain-reads.md)'s view-leaf set is unchanged, and the
`RECIPES_VIEW` leaf plays no part: a consumption recipe is not a food recipe.

### 3. Grants cannot confer more than the grantor holds

A grantor may share only rights the grantor holds, mirroring ADR-0014's rule that a caller
confers nothing it does not hold. The recipient must be an existing, active user.

A share is accepted whether or not the recipient currently holds the global permission the
right needs. A refusal that depended on the recipient's permissions would tell a grantor
who lacks `USERS_READ` what the recipient holds, which ADR-0014 avoids when it declines to
name the missing permission for `CheckMayAdminister()`. The share is inert until the
recipient holds the permission, because rule 2 evaluates both tests at use time. The share
list shows the owner and any `share` holder the rights granted, not the recipient's
permissions.

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

Two lock classes apply to the recipe row, in this order before any product lock.

- **`FOR UPDATE`:** grant, change or revoke a share, transfer, delete, and `edit`. An edit
  writes the recipe row or its lines, so it belongs here.
- **`FOR SHARE`:** consume, undo, and reads that do not write the recipe row. These
  evaluate the share and the global permission after the lock is held.

The lock order is the recipe row first, then the ascending product lock set that
`DatabaseService::LockProductsStock()` builds. One global order prevents a consumption and a
revocation from deadlocking. It does not make two `FOR SHARE` holders safe if both then write
the recipe row: the evidence below shows that pattern deadlocking, which is why `edit` takes
`FOR UPDATE`.

Outcomes follow from who takes the recipe lock first, not from who started first:

| Interleaving | Result |
|---|---|
| Revoke takes the lock first | The consume waits, finds no share and answers 404. |
| Consume takes the lock first | The consume completes under the old share; the revoke waits, then applies. |
| Two grants to the same user | The second waits for the first and updates its row. The unique constraint alone is not enough: without the recipe lock, 256 of 400 second grants failed with `23505`. |
| Two edits | They serialize on `FOR UPDATE`. |
| Grantor loses a right while granting | The grantor's own share row is re-read under the recipe lock; the grant is refused. |
| Ownership transfer and a consume | The consume succeeds either way. It completes before the transfer, or runs after it under the new owner's share table. |

A removal of a global permission is read where the application reads permissions today. The
design does not add a lock on the permission tables; a consume that started before the
permission change completes.

### 9. Action matrix

Paths belong to [issue 698](https://github.com/datagen24/victual/issues/698). Every action
first needs an authenticated user. "Share right" is the right in rule 2; "Global" is the
permission that must also hold. "None" means no share right is needed because the caller is
creating a record they will own. A caller without `read` receives 404 for every action on an
existing recipe; a caller with `read` but not the listed right receives 403.

| Action | Share right | Global permission | Outcome without the right |
|---|---|---|---|
| List recipes | `read` (rows without it are omitted) | `STOCK_VIEW` | Empty or shorter list |
| Get one recipe | `read` | `STOCK_VIEW` | 404 |
| Create recipe | None | `STOCK_VIEW`, `STOCK_CONSUME` | 403 |
| Edit name or lines | `edit` | `STOCK_VIEW` | 404 or 403 |
| Delete recipe | Owner only | `STOCK_VIEW` | 404 or 403 |
| Consume through the recipe | `consume` | `STOCK_CONSUME` | 404 or 403 |
| Undo through the recipe | `undo` | `STOCK_EDIT` | 404 or 403 |
| List shares | `share` (the owner sees all; a holder sees shares they may change) | `STOCK_VIEW` | 404 or 403 |
| Add, change or remove a share | `share`, within rules 3 and 4 | `STOCK_VIEW` | 404 or 403 |
| Remove own share | Any share | `STOCK_VIEW` | 404 |
| Transfer ownership | Owner only | `STOCK_VIEW` | 404 or 403 |
| Read own consumption events for the recipe | `read` | `STOCK_VIEW` | 404 |

### 10. Surface matrix

"Absent" means the recipe, its owner and its refill data do not appear, and the surface
behaves as if the recipe did not exist.

| Surface | Behavior |
|---|---|
| `GET /objects/{entity}` and `/{id}` | The new tables are not in `ExposedEntity` or `EntityReadPolicy`: 400, as for any unexposed entity. |
| `query[]`, counts, ordering | Not reachable through generic routes. Dedicated list routes filter before querying (rule 7). |
| `GET/PUT /userfields/{entity}/{id}` | The new entities are not exposed, so `SetValues()` refuses a write and the `GET` route's coverage check refuses a read. A test pins both. `DeleteObject` answers 400 "Invalid entity" where the read paths say "not exposed"; both are 400. |
| `userobjects` and `userentity-*` | `EntityReadPolicy::Covers()` returns true for any `userentity-` name, so nothing in the tree stops a user-defined entity referencing a consumption recipe id. [Issue 698](https://github.com/datagen24/victual/issues/698) must refuse it. |
| Food-recipe routes, `/recipes/fulfillment`, meal plan | Unaffected; consumption recipes are not rows in those tables and cannot be placed in the meal plan. |
| Calendar and iCal feed | Never include a consumption recipe or refill date derived from one. |
| Labels, captures, scan resolver | No `recipe`-style label kind is added for consumption recipes. `ResolveTarget` has no branch for them. The stock-entry and location labels in plan 22 already exist and carry no recipe data. |
| Stock reads (`stock_log`, bookings, transactions, journal) | Visible under `STOCK_VIEW` as today. `recipe_id` is null for consumption recipes. A consumption still appears as stock rows sharing a `transaction_id`. |
| Webhooks, MQTT, Influx | No consumption recipe name, owner or refill field is published. Booking events remain stock events. |
| MCP sidecar | No tool reads a consumption recipe. [Issue 694](https://github.com/datagen24/victual/issues/694) records the interface-policy text. |
| Backup, restore and `victual-db-import` | Whole-database operations. They carry the new tables and are not a sharing surface; [issue 698](https://github.com/datagen24/victual/issues/698) decides the import behavior. |
| Dedicated routes | Owner or a share is required for each; the right needed is in rule 2. |

### 11. Shared inventory is not private recipe data

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

## Evidence

[Pull request 720](https://github.com/datagen24/victual/pull/720) holds the probes and
`RESULTS.md` (2026-10-09, PostgreSQL 16.15 and 15.19, 400 iterations per scenario):

- 18 of 18 permission checkpoints were byte-identical after 350 share-row writes. A real
  `USERS_EDIT` grant changed 8 of 15 sections, so the check detects a change.
- Consume, grant, revoke, transfer and delete under rule 8: 0 deadlocks, 0 lost revocations,
  0 consumes committed after a revoke. A consume that decided from the share without the
  recipe lock committed after the revoke in 336 of 400 iterations.
- Edits under `FOR SHARE` deadlocked (`40P01`) in 194 of 400 iterations on 16.15 and 196 of 400
  on 15.19; edits rewriting lines in 83 and 88. `FOR UPDATE` had 0.
- 72 of 72 generic-surface calls were refused for an administrator and for a `STOCK_VIEW`
  holder. The stock-journal residual reproduces: two products under one `transaction_id`
  with `recipe_id` null.

The evidence does not cover starvation of a waiting `FOR UPDATE`, three or more actors,
removal of a global permission during a consume, or substitution products.

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
- **Implementation obligation.** [Issue 698](https://github.com/datagen24/victual/issues/698)
  states the table and column names and the route list, and names a test for each row of the
  action and surface matrices in its pull request.
- **Test fixtures.** The tests for those matrices use an owner, an authorized member, an
  unrelated member and an account manager, the fixtures the plan's verification list names.
- **Unlimited sharing breadth is not offered.** No group principal, no link sharing, no
  expiring share. Each would be a separate decision.

## Acceptance prerequisites

1. The maintainer answers open questions 1 and 2, or accepts the stated leans. Answered
   2026-10-09; see the open questions.
2. The maintainer confirms that Option A needs no amendment to ADR-0014. The reading rests
   on this record leaving `MayAdminister()` and `CheckMayGrant()` unmodified and on no share
   write raising anyone's resolved permissions.

## Open questions

1. **Should credential reset of an account that owns or holds shares require the same
   rights?** Today an administrator holding the target's permissions can reset a password
   and so read the target's recipes. Requiring the administrator to hold the target's recipe
   rights would block that, and would also block recovery of any such account until the
   owner revokes shares or the account is deleted. *Lean: keep ADR-0014's behavior and state
   it in the manual; a household instance's administrator can read the database regardless.*
   *Decider's answer, 2026-10-09: keep ADR-0014's behavior.*
2. **What happens to recipes when their owner's account is deleted?** Options: delete them
   (rule 5), or transfer to the lowest-id `share` holder. *Lean: delete, with a count in the
   confirmation, because an unexpected new owner receives data the former owner chose not to
   give them.*
   *Decider's answer, 2026-10-09: medication and vitamin recipes are deleted with the owner. Meal, drink and food recipes
   stay in the existing shared `recipes` table, are global, and have no owner to delete. The
   private type is chosen by the person creating a consumption recipe; Victual infers
   privacy from no product name or classification.*
3. **May an administrator delete a recipe they cannot read, to clear a stuck record?** *Lean:
   no dedicated route; the administrator deletes the owner account (question 2).*
4. **Is a household-wide principal wanted later?** Deferred by Option D. Its answer would
   need to say how it appears in permission checks.
   *Decider's answer, 2026-10-09: not for v0.5.0; share with each member separately.*
5. **Should `edit` hold `FOR UPDATE` on the recipe row?** The evidence shows two `FOR SHARE`
   editors deadlocking in about half of iterations. *Lean: yes.* *Decider's answer,
   2026-10-09: yes, as rule 8 now says. Whether an edit writes the recipe row or only its
   lines is for [issue 698](https://github.com/datagen24/victual/issues/698); the lock class
   does not depend on it.*
