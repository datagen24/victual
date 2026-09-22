# ADR-0014: Administering a user means holding everything they hold

- **Status:** **Accepted, 2026-09-14.** A may administer B when every permission B resolves
  to is one A resolves to, and may grant a set when A resolves to everything granting it
  would confer, over the closure. The record names no acceptance prerequisites; the rule has
  been in the code since 2026-09-04 as `User::MayAdminister()` and `User::CheckMayGrant()`,
  so this is bookkeeping only per the lifecycle rule.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-09-04, which is when the rule was written into the code. The
  [security sweep](../security-sweep.md)'s S5 and S6 name this remediation — "refuse to
  edit users holding permissions the caller lacks", "never grant a permission the creating
  user lacks" — and the [roadmap](../plans/README.md) unparked both onto wave 2 with the
  argument for why they are answerable today. Wave 2 implemented that. What it *also* did,
  and what this record exists for, is generalise a two-endpoint remediation into a rule
  that every future permission-touching change inherits. That generalisation is a decision,
  it is not written down anywhere a reader of neither document would find it, and
  [AGENTS.md](../../AGENTS.md) says a plan that makes one on its way to shipping leaves an
  ADR behind.
- **Referenced by:** [security sweep](../security-sweep.md) S5, S6, S27 and the
  `userpictures` residual; [19](../plans/19-rbac.md), whose question 9 carries wave 2's
  answer; [15](../plans/landed/15-deliberate-cleanup.md)'s C1, which opened the files.

## Context

This fork has thirty permission constants, a `permission_hierarchy` table that gives them
parents, and two views over it: `permission_tree`, which maps a granted permission id to
every name it confers, and `user_permissions_resolved`, which maps a user to every name
they hold once inheritance is applied. There is no role model, no notion of an
administrator except the `ADMIN` permission at the root of the tree, and — until wave 2 —
no answer at all to "may this account act on that one".

The consequence was not theoretical. `USERS_EDIT` let its holder rewrite any account's
password, including an administrator's, and then log in as them. And it did not even need
`USERS_EDIT`: `USERS` → `USERS_CREATE` → `USERS_EDIT` → `USERS_READ` is a chain in
`migrations/0110.sql` and the tree resolves downward, so an account that may *create* users
already resolved to `USERS_EDIT`. `DEFAULT_PERMISSIONS` was `['ADMIN']`, so creating a user
was a way to create an administrator.

The sweep parked these on [19](../plans/19-rbac.md) on the grounds that there was no
permission *model* to fix them against, and every fix would be a guess at what the model was
about to say. **That parking was wrong, and the roadmap's own correction is the argument
this record rests on:** 19's Depends-on line puts it *after* these findings, so parking them
on 19 inverted the dependency 19 itself states.

The distinction the parking missed is between the *rule* and the *model*. The rule needs a
caller's resolved set, a target's resolved set, and the closure of a proposed grant. All
three are the two views above, today, and 19 widens those views with a union over
`role_permissions` rather than changing what a comparison over them means.

## Decision

**A may administer B when every permission B resolves to is one A resolves to.** The empty
set is a subset of everything, so an account holding nothing is administrable by anyone with
the permission to act at all; an account holding `ADMIN` resolves to all thirty and is
administrable only by another such account. `User::MayAdminister()` is the one
implementation and `User::CheckMayAdminister()` the assertion.

**A may grant a set of permissions when A resolves to everything granting them would
confer.** Over the *closure*, not over the ids, so that granting a parent cannot smuggle in
a child the caller does not hold. `User::CheckMayGrant()` is the one implementation, and it
asks a second question in the same place: **every id has to name a real permission**, which
is sweep S27. The endpoints took an id straight from the body into an insert, so an id
naming nothing was stored and granted nothing — a grant that looks like it worked and did
not.

**"Administering" covers every act on an account, not only the one the finding named.**
Editing it, deleting it, changing what it holds, and deleting its picture. The last is the
sweep's `userpictures` residual and the one it left open in either direction. It is answered
yes: deleting the avatar of someone whose permissions you do not hold is the same act of
administering them, and answering no would make it the one place the rule does not reach.

**`DEFAULT_PERMISSIONS` is empty, and creating a user is a grant.** Nothing is conferred by
merely existing, and `POST /api/users` is bounded by what the creator holds like any other
grant. The two creation paths with no creator to compare against — reverse-proxy user
creation, and the LDAP one wave 2 deleted — get the configured default and nothing else,
which is why the default's *value* was the important half there.

**What this record does not decide: who may grant at all.** The permission-assignment
endpoints still require `ADMIN`, so the grant rule above is defence in depth on those two
rather than the thing that gates them. Loosening them to `USERS_EDIT` is a question about
the model — it is the half roles genuinely sharpen, since a grant can then arrive through a
bundle — and it belongs to [19](../plans/19-rbac.md), which states that rule for its own new
endpoints. Wave 2 took the *read* half of that rule and left the write half, and 19's
question 9 records both halves of that answer.

## Consequences

- **An installation where one account is a full administrator and the rest hold less is
  unaffected**, which is the household case. What changes is the case the sweep found: two
  accounts with overlapping but incomparable permissions can no longer administer each
  other, and neither can an account that holds strictly less administer one that holds more.
- **It costs two view reads per protected call.** `user_permissions_resolved` is queried for
  the caller and for the target. That is cheap and it is on write paths rather than reads.
- **It is a partial order, not a hierarchy**, and it therefore has a shape people find
  surprising in one direction: two accounts holding disjoint permissions can administer
  neither each other nor themselves-as-target through the other. That is the intended
  reading of "may administer", not an edge case to file down — a user manager who does not
  hold `STOCK` has no business resetting the password of someone who does.
- **The failure message names what is missing rather than who.** `CheckMayAdminister()`
  answers 403 with "a permission the target user holds" and does not say which, because
  saying which is a read of the target's permissions that the caller may not be entitled to.
  `CheckMayGrant()` does name them, because those are permissions the *caller* asked to
  grant and already knows about.
- **[19](../plans/19-rbac.md) inherits it rather than replacing it.** Both helpers are
  written against `user_permissions_resolved` and `permission_tree`, whose shape that plan
  widens with a union over `role_permissions` rather than changes. A comparison written
  against them keeps working verbatim once roles land — which is the claim the roadmap made
  when it unparked these findings, and this record is where it is now checkable.
- **It forecloses a "user manager" tier that can reset anyone's password.** That is a
  coherent thing to want and some products have it; this decision says the fork does not,
  on the grounds that on a household instance the person who can take over the administrator
  account *is* the administrator, whatever the permission table says.
