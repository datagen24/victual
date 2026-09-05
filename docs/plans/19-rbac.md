# 19. Roles and data-visibility permissions

**Goal:** Let a household say "adults see what things cost, children see chores and
recipes, one person administers" as three named roles rather than thirty checkboxes per
user — and make "see what things cost" a permission the server actually enforces, on every
channel, rather than a column the web UI hides.

**Depends on:** wave 2's **S5/S6** (never grant what the caller lacks) because role
assignment is a grant — this plan inherits that rule rather than defining it, which is
why S5 and S6 are not parked here. **Piece 2 additionally** depends on
[11](11-api-error-handling.md) for the single error helper (a redacted field and a
refused call must be distinguishable and both must be spec'd) and on
[14](14-contract-and-regression-scaffolding.md) piece 2 for the response-contract
snapshot that proves redaction; piece 1 needs neither, and verifies its views against
14 **piece 1**, which landed in wave 0. Feeds [04](04-seed-datasets.md) (the four
roles are a seed) and constrains [02](02-mcp-endpoint.md) and
[18](18-mqtt-state-publication.md) (both are channels that carry prices — see Q4 and Q5).
**Status:** draft for review and **on the roadmap as of 2026-08-30** — the README's
Status table and its waves both carry it, rather than the tail bullet that promised it a
number. **Question 8 answered 2026-09-04: (a), gate reads in piece 1**, which makes
piece 1 a model change and gives it wave 3a to itself, and **split across two waves** —
piece 1 in wave 3a, alone,
piece 2 in wave 5 alongside 14 piece 2, whose snapshot harness it extends. Piece 1
is in wave 3a rather than later because it grows the API read surface, which the roadmap
requires to happen *before* 14 piece 2 freezes the contract; piece 2 is in wave 5 because
it changes existing response shapes and cannot precede the harness that proves it. Both
land before any client work in [17](17-ecosystem-clients.md) resumes, because the Swift
client renders price fields and needs to know they may be absent.
One of the five permission findings that were parked against "an RBAC plan in draft on a
branch" stays here — the permissions page's `ADMIN`-versus-`USERS_READ` mismatch from
[14](14-contract-and-regression-scaffolding.md)'s section 2b, carried as question 9. The
other four (**S5**, **S6**, **S27** and the `userpictures` residual) go back to wave 2;
see the roadmap's tail for why parking them here inverted this plan's own Depends-on line.

## Why this is two plans wearing one number

The question that raised this — *who can see pricing history and costs?* — has no answer
in the current model, and the reason is structural rather than a missing checkbox. Every
one of the 30 permissions in `permission_hierarchy` gates an **action**: consume, purchase,
open, transfer, edit master data, mark a chore done. Not one gates a **field**.

It is worse than that, and the plan is written against the wrong baseline if this is not
said first: **no permission gates a read of household data.** `PERMISSION_STOCK` is
declared at `controllers/Users/User.php:30` and checked in exactly zero places; every read
method on `StockApiController` — current stock, product details, stock entries, price
history — and both `GenericEntityApiController::GetObject` and `::GetObjects`
(`controllers/Api/GenericEntityApiController.php:242,277`) run with no `CheckPermission`
at all, and `routes.php:268` adds only CORS and JSON middleware to the group. The users
surface is the sole exception and the only gated read anywhere:
`UsersController.php:23,93` and `UsersApiController::GetUsers` (`:174`) require
`USERS_READ`, and `::ListPermissions` (`:210`) requires `ADMIN` — which is the mismatch
question 9 is about. Everywhere else, *every authenticated user* already sees `stock.price`, `stock_log.price`,
`products_average_price`, `product_price_history`, `products_last_purchased.price`, a
product's `last_price` and `avg_price` in `/stock/products/{id}`, and every recipe's
`costs` — holding nothing. The action permissions gate writes; reads are open to anyone
who can log in.

So the household policy needs two things, and they are separable:

1. **Roles** — a named bundle of permissions assignable to users. This is a thin layer
   over the machinery that already exists and changes nothing about how a permission is
   checked.
2. **A data-visibility permission** for prices, with enforcement in the service/API layer.
   This is the new kind of thing, and it is where the risk and the effort are.

They are kept in one plan because the second is what makes the first worth doing for the
family that asked, and because both touch `controllers/Users/User.php`, `0110.sql`'s
views and the users UI. They are staged separately (piece 1, piece 2) so that the roles
layer can land alone if piece 2's questions stall.

## Today

**Permissions.** `permission_hierarchy` (id, name, parent) holds 30 rows; `ADMIN` is the
root and every other permission is under it, so ADMIN resolves to everything through the
recursive `permission_tree` view. `user_permissions` (user_id, permission_id) stores the
direct grants; `user_permissions_resolved` is the closure and is what
`User::HasPermission()` queries; `uihelper_user_permissions` drives the checkbox tree at
`/user/{id}/permissions`. The API mirrors it: `GET/POST/PUT /users/{id}/permissions`.
Controllers call `User::CheckPermission($request, User::PERMISSION_*)` and the exception
becomes a 403 (or, on the web side, an error page) — but only on write paths; see above.
`permission_hierarchy` is itself an exposed read-only entity, and since the generic read
is ungated, the tree is world-readable to any authenticated user.

**The tree is downward-inclusive, and the `USERS` subtree is a chain.** Holding a
permission resolves to every *descendant* name, never to an ancestor: `permission_tree`
seeds on each id and walks to its children (`migrations/0110.sql:98-109`). Two
consequences this plan has to design around. First, granting a parent grants every leaf
under it, so a role cannot be built by naming a parent and excluding some of its children
— there is no deny. Second, `USERS` → `USERS_CREATE` → `USERS_EDIT` → `USERS_READ` is a
chain rather than a fan (`migrations/0110.sql:24-43`), so **`USERS_CREATE` alone already
resolves to `USERS_EDIT` and `USERS_READ`**: an account that may create users may today
rewrite any admin's password. That is sweep S6 as a structural fact rather than a missing
check, and it is what S6's fix in wave 2 has to be written against. The read/write split
this plan proposes for the roles API is unaffected and works as intended — `USERS_EDIT`
resolves down to `USERS_READ`.

**Defaults.** `UsersService::CreateUser()` grants `VICTUAL_DEFAULT_PERMISSIONS` to every
new user; today that is `['ADMIN']` (sweep S5, fixed in wave 2). There is no notion of "a
new user is a child".

**Prices.** `FEATURE_FLAG_STOCK_PRICE_TRACKING` is consumed in exactly two places:
`config-dist.php` where it is declared, and the Blade views, where it adds `d-none` to
price columns and hides the price inputs. No service, controller or API path reads it. It
is a per-instance UI preference, and the API returns prices whether it is on or off.
That is the same shape as sweep R1 — the flag exists on one side of the wall — and it is
the reason a per-role version cannot be built by moving the flag into the session.

**Where prices leave the server.** Enumerated from `victual.openapi.json` and the views:

| Channel | Carrier | Fields |
|---|---|---|
| `GET /objects/stock`, `/objects/stock_log` | generic entity read | `price` |
| `GET /objects/products_average_price`, `/objects/products_last_purchased` | generic entity read | `average_price`, `price` |
| `GET /stock`, `GET /stock/entry/{id}`, `GET /stock/products/{id}/entries` | StockApiController | `price` per entry |
| `GET /stock/products/{id}` | StockApiController → `StockService::GetProductDetails` | `last_price`, `avg_price` |
| `GET /stock/products/{id}/price-history` | StockApiController | the whole payload |
| `GET /objects/recipes_pos_resolved`, `GET /recipes/{id}/fulfillment` | RecipesService | `costs` |
| Blade: stockoverview, stockentries, purchase, inventory, products, recipes, mealplan, productcard | server-rendered | all of the above |
| [02](02-mcp-endpoint.md) MCP tools (draft) | whatever 02 chooses to expose | inherits the API's |
| [18](18-mqtt-state-publication.md) (draft, `pr/25`) | published state | **no user in the loop** — Q5 |

**Recipes.** `RECIPES` is one permission, and it gates *writes only* — creating, editing
and deleting recipes and their ingredients (`GenericEntityApiController` maps `recipes`,
`recipes_pos`, `recipes_nestings` writes to it; `MASTER_DATA_EDIT` does not cover them).
Reading them is ungated like every other read. So the problem is not that a child who may
read may also rewrite; it is that reading is not a grant at all, and `RECIPES_VIEW` has
nothing to narrow until it is. See Q8.

## Proposed change

### Piece 1 — roles

Roles are a bundling layer only. Nothing in the check path changes; `HasPermission()`
still reads `user_permissions_resolved`, which is widened to union the user's direct
grants with the grants of every role they hold.

#### Schema

```sql
CREATE TABLE roles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,         -- immutable identity: ADMIN, ADULT, CHILD
    name        TEXT NOT NULL UNIQUE,         -- editable display label
    description TEXT,
    builtin     TINYINT NOT NULL DEFAULT 0,   -- seeded rows: renamable, not deletable
    row_created_timestamp DATETIME DEFAULT (datetime('now', 'localtime'))
);
CREATE TABLE role_permissions (
    role_id       INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permission_hierarchy(id),
    PRIMARY KEY (role_id, permission_id)
);
CREATE TABLE user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);
```

**`code` exists because `name` is editable and the built-in roles are both renamable and
re-shippable, which cannot both be true of one column.** [04](04-seed-datasets.md)'s format
references entities by name; a household that renames "Child" to "Kids" would leave a later
seed import unable to find the row it means, so it either creates a second Child role or
overwrites the rename. `code` is what the seed keys on, what a migration matches when it
adds a permission to a built-in role, and what an API caller can rely on; `name` is what
the UI shows and the household may change. `code` is immutable for `builtin` rows and
assigned once for custom ones, and `PUT /roles/{id}` never accepts it.

`user_permissions_resolved` becomes:

```sql
SELECT u.id AS id, u.id AS user_id, pt.name AS permission_name
FROM permission_tree pt, users u
WHERE pt.id IN (
    SELECT permission_id FROM user_permissions WHERE user_id = u.id
    UNION
    SELECT rp.permission_id FROM user_roles ur
      JOIN role_permissions rp ON rp.role_id = ur.role_id
    WHERE ur.user_id = u.id
);
```

`uihelper_user_permissions` gains a `via_roles` column so the checkbox tree can show a
grant as inherited and read-only rather than silently ticked. It is the **comma-separated
list of the `code`s of every role granting that permission to that user, sorted
ascending**, or NULL for a permission held only directly. "The first role that grants it"
was the first draft and is wrong twice over: it is not deterministic — SQLite and
PostgreSQL are free to return the rows of an unordered join in different orders, which
would make `difftest.php` fail on a correct implementation — and it discards information
the UI wants, since a permission inherited from two roles survives the removal of either
one. Sorting on the immutable `code` rather than the editable `name` keeps the column
stable across a rename, and the UI resolves codes to display names for the tooltip. This migration is PostgreSQL-only — it lands after the
[ADR-0008](../adr/0008-postgresql-only-runtime-engine.md) retirement sitting (README, wave
2.5) — so `SMALLINT` and an identity column, no `AUTOINCREMENT` twin; the `permission_tree`
recursive CTE already runs there, so the closure needs no new SQL of its own.

Direct grants in `user_permissions` stay. A user's effective permissions are the union of
direct and role grants; there is no "deny". This keeps the existing API and the existing
per-user page working unchanged, and it is what lets the roles layer land with zero
behaviour change for a database that has no rows in `user_roles`.

#### Seed

Four built-in roles, shipped by the migration (and re-shippable by [04](04-seed-datasets.md)) — Guest added by Q7's Response, and the `*_VIEW` leaves by Q8's:

| Role | Grants | Deliberately absent |
|---|---|---|
| **Admin** | `ADMIN` | — |
| **Adult** | `STOCK` (all children), `SHOPPINGLIST` (all), `RECIPES`, `RECIPES_MEALPLAN`, `CHORES` (all), `TASKS` (all), `BATTERIES` (all), `EQUIPMENT`, `CALENDAR`, `USERS_READ`, `USERS_EDIT_SELF`, and piece 2's `STOCK_PRICES_VIEW` | `ADMIN`, `MASTER_DATA_EDIT`, `USERS`, `USERS_CREATE`, `USERS_EDIT` |
| **Child** | **leaves only** — `STOCK_VIEW`, `STOCK_CONSUME`, `STOCK_OPEN`, `SHOPPINGLIST_VIEW`, `SHOPPINGLIST_ITEMS_ADD`, `CHORES_VIEW`, `CHORE_TRACK_EXECUTION`, `TASKS_VIEW`, `TASKS_MARK_COMPLETED`, `RECIPES_VIEW`, `MEALPLAN_VIEW` (Q3), `CALENDAR`, `USERS_EDIT_SELF` | everything that creates or destroys: purchase (which now implies prices, Q6), inventory, transfer, stock edit, undo of anything, delete from shopping list, recipe edit, meal-plan edit, all master data, prices |
| **Guest** | `STOCK_VIEW`, `RECIPES_VIEW`, `MEALPLAN_VIEW` | everything else — no writes, no prices, no chores or tasks, no calendar |

The Child row is leaves-only for a reason worth stating, because the first draft of this
table got it wrong in four places. It granted `STOCK`, `SHOPPINGLIST`, `CHORES` and
`TASKS` alongside the leaves — and since the tree is downward-inclusive, each of those
grants exactly what the same row's third column says is deliberately absent: `STOCK`
carries `STOCK_PURCHASE`, `STOCK_INVENTORY`, `STOCK_TRANSFER` and `STOCK_EDIT`,
`SHOPPINGLIST` carries `SHOPPINGLIST_ITEMS_DELETE`, and `CHORES` and `TASKS` each carry
their `*_UNDO_EXECUTION`. As written it was an administrator-of-stock role. **A role in
this model is a set of leaves**; naming a parent is a decision to grant its whole subtree,
which is right for Adult and wrong for Child.

Dropping the parents costs the Child nothing today only because reads are ungated — see
Q8, which is where that debt is recorded rather than being silently relied on.

`VICTUAL_DEFAULT_PERMISSIONS` gains a sibling `VICTUAL_DEFAULT_ROLES` (default `[]`).
With wave 2's S5 in place a new user starts with nothing unless the creator assigns a
role, and the creator can only assign a role whose grants are a subset of their own
effective permissions — the same rule S5/S6 impose on direct grants, applied to the
bundle. `Admin` therefore cannot be handed out by an Adult.

#### API

Additive, in the style of the existing users endpoints:

```
GET    /roles                          list (ExposedEntity: roles is also readable via /objects)
POST   /roles                          create custom role
PUT    /roles/{id}                     rename / describe; builtin rows accept name+description only
DELETE /roles/{id}                     refused for builtin
GET    /roles/{id}/permissions
PUT    /roles/{id}/permissions         replace set (subset-of-caller rule applies)
GET    /users/{id}/roles
PUT    /users/{id}/roles               replace set (subset-of-caller rule applies)
GET    /users/{id}/permissions         additive: each row gains "via_roles": string|null
                                       (see Client impact; the permission question is Q9)
```

All role management is behind `USERS_EDIT`; reading roles is behind `USERS_READ`. Editing
a **role's** permission set is a grant to every holder at once, so it also requires that
the caller's effective permissions be a superset of the *new* set, not just of the delta.

#### UI

The users list gains a Roles column. `/user/{id}/permissions` grows a roles multi-select
above the existing tree; inherited ticks render disabled with the role name as a tooltip.
A new `/roles` and `/role/{id}` pair reuses the same tree component with the checkbox
tree bound to `role_permissions` instead. Under [12](12-frontend-shared-core.md) that is
one `Victual.EntityList` call for `/roles` and **two mixin adopters, not a factory form**
— `/role/{id}` is the same partial-clone shape 12's Q5 response already buckets
`userpermissions.js` into, and this plan modifies `userpermissions.js` itself. Nor is
`/roles` a plain `EntityList`/`EntityForm` pair, since roles are readable through
`/objects` but written through `PUT /roles/{id}/permissions`. So 12's ordering applies
here for the same reason it applies to 05, 06 and 08, plus sweep S29's: a role name is a
text column rendered through `bootbox`'s `.html()` delete confirmation.

### Piece 2 — data-visibility permissions

#### New permission leaves

| Name | Parent | Gates |
|---|---|---|
| `STOCK_PRICES_VIEW` | `STOCK` | every field in the table above, on every channel |
| `RECIPES_VIEW` | `RECIPES` | reading recipes; `RECIPES` itself keeps write (Q1) |

`STOCK_PRICES_VIEW` under `STOCK` means anyone who already holds `STOCK` or `ADMIN`
resolves to it through the tree, so for those users **an upgraded instance behaves exactly
as before** until an administrator removes the leaf or builds a role without it.

That is the migration-safety property this plan wants, and **it does not hold as stated**,
because reads are ungated: a user holding only `CHORES`, or holding nothing at all, reads
every price today and holds no `STOCK` grant to inherit the new leaf from. Gating a field
that was never gated necessarily takes it from someone. The honest form of the property is
therefore narrower — *no user who holds `STOCK` or `ADMIN` loses a field on upgrade* — and
the residue is a deliberate change of behaviour for everyone else, which is the point of
the plan rather than an accident of it. Q8 asks whether the two halves land together.

`RECIPES_VIEW` is the inverse case: `RECIPES` today means read+write, and the plan keeps
that meaning (so existing grants keep working) while making `RECIPES_VIEW` the narrower
leaf a Child role can hold. Q1 asks whether to invert this to the more usual
`RECIPES_EDIT` under `RECIPES`, which is cleaner but changes what `RECIPES` alone means.

#### Enforcement — one place, at the boundary

A new `Victual\Services\FieldPolicy` (or a static on `User`, Q2) answers one question:
*which response fields must this user not see?* It returns a set of `(entity, field)`
pairs derived from the user's resolved permissions — today only the price group, but
shaped so a future `CHORES_ASSIGNEE_VIEW` or similar is a table row, not a code change:

```php
const FIELD_POLICY = [
    User::PERMISSION_STOCK_PRICES_VIEW => [
        'stock'                     => ['price'],
        'stock_log'                 => ['price'],
        'products_average_price'    => ['average_price'],
        'products_last_purchased'   => ['price'],
        'recipes_pos_resolved'      => ['costs'],
        'recipes_resolved'          => ['costs', 'costs_per_serving', 'prices_incomplete'],
        'uihelper_stock_current_overview' => ['value', 'last_price', 'average_price'],
        'products_price_history'    => ['*'],  // the /objects path; the route is refused
        'uihelper_shopping_list'    => ['last_price_unit', 'last_price_total', 'price'],
        'product_details'           => ['last_price', 'avg_price'],
        'stock_entry'               => ['price'],
        'recipe_fulfillment'        => ['costs'],
    ],
];
```

Four of those rows were missing from the first draft — `recipes_resolved`,
`uihelper_shopping_list`, `uihelper_stock_current_overview` and `products_price_history` —
and each is a real channel rather than belt-and-braces. `recipes_resolved` is returned wholesale by `GET /recipes/fulfillment`
through `FilteredApiResponse` (`controllers/Api/RecipesApiController.php:73`), so it is
both unredacted *and* filterable today; it also carries `prices_incomplete`, which is
`MIN(costs) = 0` (`migrations/0247.sql:15`) and therefore survives the redaction of
`costs` while still answering a question about them. It leaks one bit — some ingredient
has no recorded cost — rather than a value, which is enough to make the point: a policy
has to enumerate derived columns, not columns named `price`.
`uihelper_shopping_list` selects `last_price_unit`, `last_price_total` and `price`
(`migrations/0251.sql:7-9`) and is an exposed entity, so it is a live ungated price
channel today rather than a future one.
`uihelper_stock_current_overview` (`migrations/0252.sql:38-39` — the highest-numbered
migration defining it; 0219 is superseded) is what the Blade stock overview renders, what [02](02-mcp-endpoint.md)'s `stock_overview` tool reads and what
[18](18-mqtt-state-publication.md) would publish as attributes; it is not an API path
today, but [14](14-contract-and-regression-scaffolding.md)'s section 2b requires it to
become one before piece 2 freezes the contract, so it will be. And
`products_price_history` is the entity 14's 2b parked as "a deliberate widening pending a
decision" — this plan is that decision, and the leaf is what makes exposing it safe.

Redaction is applied where the entity is still known: **`FilteredApiResponse()`**
(`BaseApiController::FilteredApiResponse`), which receives a LessQL `Result` and can
therefore tell a `stock` row from a `chores` row, plus the hand-built responses' own
shaping — and in **`BaseController`'s view data** for the Blade side. It is *not*
`ApiResponse()`: that takes bare `$data` and no entity name
(`controllers/Api/BaseApiController.php:31`), so a policy keyed by `(entity, field)`
cannot tell what it is looking at by the time it gets there. The first draft named
`ApiResponse()` and also said [11](11-api-error-handling.md) was centralising it; neither
is right. `ApiResponse()` predates 11, and what 11 centralises is the *error* path
(`HandleApiCall`). 11 is still the dependency, for the filter rule below.

Redacted fields are **removed from the object**, not nulled — a null price is a legitimate
value in `stock_log` (a consumption has none) and must stay distinguishable from "you may
not see this". **A redacted field and a refused call are already distinguishable without a
new error kind**, so this plan asks 11 for no taxonomy slot: redaction is a 200 with a
shorter body, refusal is 11's 403. `GET /stock/products/{id}/price-history` is refusal —
the whole endpoint is the field, so it gets
`User::CheckPermission(STOCK_PRICES_VIEW)` and 11's 403 body.

The filter hole that verification 5 names closes at one call site rather than in a new
mechanism. `BaseApiController::AssertFieldExists()`
(`controllers/Api/BaseApiController.php:180`) already rejects a field the entity does not
have with 400, and is reached from both the `query[]` and the `order` path with the
entity's column types in hand; it gains the caller's policy alongside the column list, and
a filter on a redacted field is refused with 11's `EInvalidApiQuery`. The message
distinguishes it from an unknown field and the status code deliberately does not, since a
distinct code would confirm the field exists.

Write paths are untouched. `price` on `POST /stock/products/{id}/add` is already gated by
`STOCK_PURCHASE`; a user who may purchase but not view prices can still record one (Q6
asks whether that is what the household wants).

#### OpenAPI

Every redactable field gets `x-visibility: STOCK_PRICES_VIEW` in `victual.openapi.json`
and is no longer listed as `required` on its schema. [14](14-contract-and-regression-scaffolding.md)'s
contract snapshot is run twice per affected path, once as Admin and once as a fixture
user without the leaf, and the second snapshot is asserted to equal the first minus
exactly the `x-visibility` fields. That assertion is the proof that redaction *works* — and
on its own it proves nothing about redaction being *complete*, which the first draft
claimed it did.

The claim was that a new path returning `price` without the annotation fails the diff for
the fixture user. It passes. With no annotation and no `FIELD_POLICY` row, nothing redacts
the field, both identities receive it, and "Admin minus the annotated fields" still equals
the restricted response. The diff can only ever police fields somebody has already
classified, so an unclassified leak is exactly the case it is blind to.

Completeness therefore needs its own assertion, and it is a different shape: **every field
this repository can return that matches the sensitive set must be classified**, whether it
is redacted or deliberately not. Concretely, walk the OpenAPI schemas and the recorded
snapshot bodies for field names matching the price/cost vocabulary — `price`, `cost`,
`value`, `amount_paid` and their prefixed and suffixed forms — and fail on any that carries
neither `x-visibility` nor an explicit `x-visibility: none` with a one-line reason. The
snapshot bodies are needed alongside the schemas because the hand-built responses
(`/stock/products/{id}`, the MCP tool payloads 02 describes) are not all schema-backed. The
allow-list is the deliverable: `qu_factor_price_to_stock` is a unit factor and is annotated
`none`, and every future exception has to be written down rather than merely absent. This
lives with the harness in 14 piece 2, alongside the double snapshot.

Both legs are load-bearing, and it is worth saying why since the assertion is described as
the proof. A field that is absent *for everyone* — dropped by a bug, renamed, never
populated — satisfies the restricted leg exactly as a correctly redacted one does. Only
the Admin leg distinguishes them, so the restricted snapshot proves redaction only in
combination with an Admin snapshot that shows the field was there to redact.

#### UI

The Blade `d-none` on `VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING` becomes
`@if(!VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING || !User::HasPermission(STOCK_PRICES_VIEW))`
via one helper, so the instance-wide flag and the per-user leaf collapse to a single
condition. The feature flag stays: it is still the right knob for "this household does not
track prices at all".

#### What ADR-0012 fixes about this plan, and what it leaves open

[ADR-0012](../adr/0012-observations-are-proposals.md) was **accepted 2026-09-04** and calls
this plan "the shape it exists to express". No `proposals` table exists and no plan owns
that work, so nothing below is a change to this plan's pieces — it is what this plan must
already be able to express, recorded here rather than rediscovered when proposals are
built.

**Two permission facts are decided, and neither adds a role.**

1. **Creating a proposal is its own narrow grant** — one leaf (this plan names it; 0012
   does not), held by the user an observer's API key resolves to and by nothing else. It
   belongs in no seed role: Adult and Child are people, and a camera is not a household
   member with a small role. That is a fourth *identity* shape rather than a fourth role,
   and it is the case Q7's "Guest" question is not.
2. **Confirming a proposal requires exactly the permission the proposed booking requires
   directly, and rejecting requires the same** — so no `PROPOSALS_CONFIRM` leaf, no reviewer
   role, and the seed table above is unchanged. This is a property of the tree this plan
   already builds rather than an addition to it: a Child holding `STOCK_CONSUME` may confirm
   a proposed consume and nothing else, which falls out of the leaves-only Child row without
   any further design. Worth asserting when it is built rather than assuming, since it is
   exactly the shape of risk verification 10 exists for — a rule written against direct
   grants and not re-checked against inherited ones.

**One thing is named and not decided: the field policy has no path form.** `FIELD_POLICY`
above is keyed `(entity → column)`, and a proposal's price is not a column — it is a key
inside a payload that is partial by design. 0012's decision item 6 handles the *reader's*
half of that (`proposed_fields` carries the key set as submitted and is never redacted, so a
key missing from the payload means redacted and a key missing from both means unobserved),
which is what makes redaction expressible on a partial object at all. What it does not
decide is which side of this plan's funnel does the removing: a `proposals` row would need
either a `FIELD_POLICY` entry that can name `payload.price`, or a rule that a reader without
`STOCK_PRICES_VIEW` is refused priced proposal *kinds* wholesale. Q2's constant-versus-table
question is the natural place for it, and it is cheaper to answer while the constant is
still being written than after.

Note what this does **not** wait on. 0012's payload contract is settled against the two
rules of this plan that are decided — redaction removes the key rather than nulling it, and
a redacted field is already distinguishable from a refused call — and not against
[Q8](#open-questions). Either answer to Q8 leaves it intact: a proposal a reader may not see
at all is a refusal, and a proposal they may partly see is the redacted case.

## Client impact

**Piece 1: one additive field. Piece 2: fields that were always present become optional,
which is the kind of change a lenient decoder handles and a strict one does not.** Roles
otherwise add an entity and a grant path and take nothing away.

Piece 1's field is `via_roles` on every row of `GET /users/{id}/permissions`. Calling that
"unchanged shape" was this plan's own first draft and it is the mistake this section exists
to catch: additive is not the same as invisible. A strict decoder that rejects unknown keys
breaks on it exactly as a strict decoder breaks on a removed key in piece 2 — the same
compatibility model, applied in the other direction — and neither tracked client is known
to read this endpoint, which is what makes it affordable rather than what makes it absent.
It goes in [14](14-contract-and-regression-scaffolding.md)'s contract snapshot as an
addition on a path piece 2 later removes fields from, so both directions are recorded on
the same endpoint. Piece 2 removes `price`, `costs` and their
relatives from responses to users who lack `STOCK_PRICES_VIEW` — `stock.price`,
`stock_log.price`, `products_average_price`, `product_price_history`,
`products_last_purchased.price`, and `last_price`/`avg_price` on `/stock/products/{id}`.

The Swift module [17](17-ecosystem-clients.md) commits to renders both unconditionally,
which is why the roadmap sequences this before client work resumes: in Swift an absent key
is a decode failure rather than a blank cell, so the first Child login from a phone would
break the whole screen rather than hide a number. A redacted field and a refused call must
also be distinguishable — that is why this plan waits on [11](11-api-error-handling.md)
rather than inventing a second error shape.

[18](18-mqtt-state-publication.md) is the other channel, and it carries the question
rather than this plan: its question 8 leans to publishing no price or cost field on any
topic, and has no Response yet. See Q5.

## Verification

1. Fresh database: `user_roles` empty, every existing permission test passes unchanged.
2. Upgrade test: a pre-19 fixture with users holding `STOCK` directly; after migration
   every one of them still receives every price field on every path in the table above.
3. Roles: create Adult and Child from the seed, assign each to a fixture user, snapshot
   `GET /users/{id}/permissions` and assert `via_roles` on every inherited row, carrying
   both role codes in sorted order where two roles grant the same permission; remove the
   role, assert the rows revert.
4. Subset rule: as an Adult, `PUT /users/{id}/roles` with Admin → 403; `PUT
   /roles/{child}/permissions` adding `MASTER_DATA_EDIT` → 403.
5. Redaction: the double contract snapshot described above, across all paths in the
   table. Include `/objects/{entity}` with `query[]` filters on `price` — a filter on a
   redacted field must be refused (400 via 11's helper), not silently applied, or a
   child can binary-search a price by filtering on it.
6. Blade: render stockoverview, stockentries, recipes and productcard as the fixture user
   and assert no `locale-number-currency` span is emitted.
7. Views on both engines: `.devtools/pgsql/difftest.php` on `user_permissions_resolved`
   and `uihelper_user_permissions`.
8. Negative: `GET /stock/products/{id}/price-history` as the fixture user → 403 with 11's
   body, not an empty array.
9. Sweep **S27** on this plan's own new endpoints: `PUT /roles/{id}/permissions` and
   `PUT /users/{id}/roles` both take ids, which is exactly the shape S27 found writing
   unvalidated into `user_permissions` and answering 204 while granting nothing. Both
   must reject an id absent from `permission_hierarchy` / `roles` with 400 rather than
   accepting it silently. Assert it, because this plan doubles the number of places the
   bug can exist.
10. The **`userpictures` residual** stays closed under roles. Wave 2 replaces "may edit
    some user" with an ownership lookup through `users.picture_file_name`
    (`controllers/Api/FilesApiController.php:143-151`); assert here that a user whose only
    `USERS_EDIT` arrives *through a role* is bound by it identically to one holding it
    directly. That is the general shape of this plan's risk — a rule written against
    direct grants and silently not re-checked against inherited ones — and this is the
    cheapest place to catch it.

## Sequencing

**Piece 1 — wave 3, its own track.**

- **After wave 2's S5/S6** — the subset-of-caller rule is theirs and this plan reuses it.
  It is a mechanism over `user_permissions_resolved` and `permission_tree`, both of which
  exist today, so nothing about it waits on this plan; the roles layer only widens the
  view the same comparison reads. That is why the roadmap does *not* park S5 and S6 here.
- **After 12** for the UI, or accept that `/roles` is the last pre-12 form written. The
  API does not wait on 12.
- **Before 14 piece 2**, not after it. Piece 1 adds `/roles`, `/roles/{id}/permissions`,
  `/users/{id}/roles`, a `roles` exposed entity and a `via_roles` field on an existing
  response — all read surface, and the roadmap's rule is that the read surface grows
  before the snapshot freezes it.
- It touches `User.php`, `0110`-successor views, `UsersApiController` and the users
  views, none of which 03, 06 or (deferred) 09 open. The two files the wave's tracks
  would share are `routes.php` and `victual.openapi.json` — additive in every case, and
  named here because the wave rule says disjoint rather than mostly disjoint.

**Piece 2 — wave 5, co-scheduled with 14 piece 2.**

- **After 11** — the 403 body a refusal returns and the 400 a refused filter returns are
  11's error helper; building them here first would mean building them twice. Note this is
  11's *error* path only: the redaction funnel is `FilteredApiResponse()`, which 11 does
  not touch, and the first draft's claim that 11 was centralising it was wrong.
- **With 14 piece 2, not after it.** The double snapshot is not a consumer of that harness
  but an extension of it: the harness has to run a path under two identities and diff the
  results, which single-identity snapshotting does not do. Build it once, in 14, with this
  plan as its first caller — and land piece 2 before the freeze is signed off, since
  removing fields from `required` changes existing response shapes.
- **Before the Swift transport is generated**, which is stronger than 17's "before the
  client work resumes". The client decodes `price` and `costs` into non-optional
  properties, so a Child logging in from a phone gets a decoding failure rather than a
  list without prices — the app stops rather than degrading. Generated after this plan,
  the optionality is generated; generated before it, every model is generated twice.
  Recorded as **Coupling 5** in 17, which has numbered Coupling sections rather than the
  table the first draft referred to.
- **Before 02 exposes any stock tool** — see Q4. **Not before 18**, which the first draft
  asked for and which is not achievable: 18 is wave 1 track D, two waves ahead of piece 1.
  Q5 moves to 18 instead, as its question 8, since 18's security notes are where that
  reasoning already lives. Moved, not answered: 18 records a lean to publish no prices and
  has no response yet, so this plan should read 18's question 8 before piece 2 rather than
  assume it.

## Open questions

1. **`RECIPES_VIEW` under `RECIPES` (keeps today's meaning of `RECIPES`) or
   `RECIPES_EDIT` under `RECIPES` (makes `RECIPES` mean read, and edit the leaf under
   it)?** The second is what a new user would expect; the first is the one that changes
   nothing on upgrade. Note that the drafted comparison to `STOCK` and `CHORES` does not
   hold — those do not gate reads either (see Q8), so there is no existing read/write
   split in the tree for this to be consistent with.
   A migration that grants `RECIPES_EDIT` to every user currently holding `RECIPES`
   gets both, at the cost of one more row per user.


   > **Response (2026-09-04):** `X_VIEW` leaf under `X`, and — because Q8 answers (a) —
   > the same shape five times: `STOCK_VIEW`, `SHOPPINGLIST_VIEW`, `CHORES_VIEW`,
   > `TASKS_VIEW`, `RECIPES_VIEW`. `X` keeps today's read+write meaning, so no existing
   > grant row changes on upgrade. See Q8's Response for the upgrade backfill.

2. **Where does the field policy live** — a table (`permission_fields`, editable, so a
   household can decide `note` on `stock_log` is also adults-only) or a PHP constant
   (auditable, versioned, and the contract snapshot can be generated from it)? The plan
   above says constant. A table is a small step later if wanted; a constant cannot be
   made from a table without losing the snapshot generation.


   > **Response (2026-09-04):** A table, `permission_fields`, so a household can widen the
   > policy without a release. The contract snapshot is generated from the **seeded rows
   > shipped in the migration**, never from a live database — that keeps the snapshot
   > versioned and reviewable; local additions are by definition outside the contract.

3. **Meal plan for children: read, or read+write?** `RECIPES_MEALPLAN` is one leaf
   covering both. If a child should be able to pick Tuesday's dinner, no split is
   needed. If not, it is the same split as Q1, one more time.


   > **Response (2026-09-04):** Split. `MEALPLAN_VIEW` is a leaf under `RECIPES_MEALPLAN`;
   > the Child seed role holds only the view leaf. Picking dinner is an Adult act.

4. **[02](02-mcp-endpoint.md): the MCP endpoint runs as a user, and that half is already
   answered.** 02's Q1 and Q6 responses put a sidecar behind an `API_KEY_TYPE_MCP` bearer
   key that resolves to a Victual user, with every REST call permission-checked as that
   user, so redaction is inherited and this plan needs nothing further. What survives is
   narrower and belongs to 02: a single shared MCP key means *one* user's price
   visibility for every household member the assistant talks to, so the key is per person
   or its user holds no `STOCK_PRICES_VIEW`. Worth noting alongside it that 02's read
   tools already carry prices — `stock_overview` reads
   `uihelper_stock_current_overview` and `recipes_i_can_cook` reads `recipes_resolved` —
   and that 02's Q5 response keeps them out by construction (small hand-built responses)
   rather than by policy. This plan makes it policy.

5. **[18](18-mqtt-state-publication.md): published state has no reader.** If stock
   state is published with prices, every subscriber sees them regardless of role. The
   options are to publish without the `x-visibility` fields, to publish per-role topics,
   or to declare MQTT an admin channel. This plan proposes the first, and 18 already
   argues for it without naming prices: its security notes say publish nothing that would
   not also be shown on a wall tablet, and a broker subscriber is not a logged-in user and
   cannot be made into one. Per-role topics are foreclosed by the same plan's case for a
   single discovery payload. So the question is really 18's to close, and is carried there
   as its own numbered question rather than gating this plan — see Sequencing. The live
   leak to close is 18's stock summary, whose row attributes come from
   `uihelper_stock_current_overview` and therefore carry `value`, `last_price` and
   `average_price`.

   > **Response: reassigned to 18, which is the only plan that can answer it in time.**
   > The question as written assumed 18 could wait for this plan to land and then record a
   > choice, and the roadmap says otherwise: 18 is wave 1 track D and this plan's piece 2
   > is wave 5, so 18 merges four waves earlier. A retained topic makes that gap matter in
   > a way an ordinary ordering slip would not — retained means the payload sits on the
   > broker until something republishes, so an unsettled question there is not a decision
   > deferred but pricing already published. 18 therefore carries it as its own question 8
   > rather than inheriting an answer from here.
   >
   > That is a question moved, not a question answered. 18's question 8 records a lean to
   > the first option — publish no price or cost field on any topic — and like every other
   > question in that plan it has no Response yet. Read it before piece 2 rather than
   > assuming it.
   >
   > What this plan keeps is the *reason* the option is right rather than merely early.
   > Per-role topics are the only answer that survives piece 2's model, and they are
   > unbuildable today for the reason this question opens with: there is no reader
   > identity on a retained topic to gate against. If piece 2 ever gives the publisher a
   > role to publish *as*, per-role topics are the revisit, and 18's bullet is written to
   > have to be edited before any priced entity is added.

6. **Should `STOCK_PURCHASE` imply `STOCK_PRICES_VIEW`?** A user who records purchases
   without seeing prices is coherent (a child doing the shopping run with a list) but
   odd. Leaving them independent is the flexible choice; the seed roles never combine
   them the odd way.


   > **Response (2026-09-04):** Implied — `STOCK_PURCHASE` resolves down to
   > `STOCK_PRICES_VIEW`. Recording a purchase without seeing what it cost is not a case
   > this household has. The consequence is carried by the seed roles: **the Child role
   > does not hold `STOCK_PURCHASE`**, so a child can consume and open but not record
   > purchases, and prices stay hidden from it.

7. **Is there a fourth role?** "Guest" — read-only stock and recipes, nothing else — is
   the obvious one for a visitor's phone, and costs one seed row. It is not the family
   that asked, so it is a question rather than a row.


   > **Response (2026-09-04):** Yes — a fourth seed role, `Guest`: `STOCK_VIEW`,
   > `RECIPES_VIEW` and `MEALPLAN_VIEW`, nothing else. A visitor's phone can see what is
   > in the house, the recipes and what is for dinner; no prices, no writes.

8. **Does this plan also gate reads, and if so does that land with piece 1 or piece 2?**
   This is the largest question the plan has and it was not in the first draft, because
   the draft assumed reads were already gated. Outside the users surface, they are not:
   no read of household data checks a permission. Three things follow. The Child role's *withheld* permissions are real
   (writes are checked) but its *granted* ones are decorative — it could hold nothing and
   still read all stock. `RECIPES_VIEW` narrows a permission that does not currently gate
   reading, so as drafted it grants nothing that is not already universal. And piece 2's
   field redaction, which *is* enforced, would be the only read-side authorization in the
   system — a field-level control sitting on top of an object-level control that does not
   exist, which is a strange shape to build and a stranger one to explain.

   The honest options are: (a) gate reads in piece 1, which means a `*_VIEW` leaf for
   stock, shopping list, chores and tasks — the same split Q1 debates for recipes, four
   more times — and turns piece 1 from a bundling layer into a model change with real
   upgrade risk; (b) gate reads in piece 2, where the redaction funnel already has to
   inspect every response and can refuse the whole object as easily as a field; or
   (c) declare read-gating out of scope *for roles* and say so in the plan, accepting that
   roles restrict what a user can *do*, that prices restrict what they can *see*, and that
   a domain needing its own read predicate owns it locally rather than waiting for a
   general mechanism. Option (c) is coherent and is what the family actually asked for — it
   is only unacceptable if left unsaid. This question is worth answering before
   piece 1 is scheduled, because (a) changes piece 1's size and its wave.

   **One read gate now exists outside this plan, decided 2026-09-04.**
   [22](22-medication-tracking.md) Q5 ships subject-scoped visibility for regimens and
   administrations in `MedicationService`, rather than waiting for this plan — because
   waiting would hold half of 22 behind several waves, and because the medication case is
   **row filtering** (an invisible subject is absent) rather than field redaction, so it does
   not touch the absent-versus-redacted contract that makes piece 2 hard. It is explicitly
   narrow and explicitly a client of whatever this plan builds. Two things follow for this
   question, and **neither answers it** — the Response below does, later the same day.

   Option (c) was reworded rather than left standing: as originally written it said only
   prices restrict what a user can see, and that is now false, so it reads *read-gating out
   of scope for roles*, with a domain that needs its own predicate owning it locally. The
   substance of the option is unchanged and it is no more or less favoured than it was. And
   option (a) gains a worked example of what a `*_VIEW` leaf costs when the domain is
   row-shaped, which is much less than the general case: no wire-contract question, no
   redaction funnel. Whether that generalises to stock, which is not row-shaped in the same
   way, is precisely what this question still has to decide.


   > **Response (2026-09-04): (a) — gate reads, in piece 1.** Roles that restrict what a
   > user can do while every read stays universal are decorative, and the field-level
   > redaction of piece 2 would otherwise be the only read-side authorization in the
   > system. Piece 1 is therefore a model change, and is sized and scheduled as one:
   >
   > - Five `*_VIEW` leaves per Q1, plus `MEALPLAN_VIEW` per Q3, each a child of the
   >   permission it narrows; the tree still resolves downward, so every existing holder
   >   of `STOCK` already resolves to `STOCK_VIEW`.
   > - Every read path in those subtrees gains a `CheckPermission` on the view leaf —
   >   controllers, API controllers, and the exposed-entity generic reads, which today
   >   check nothing.
   > - **Upgrade is behaviour-preserving:** the migration grants each `*_VIEW` leaf to
   >   every existing user. Nobody loses a read on upgrade; admins narrow deliberately
   >   afterwards. This is the same rule the Verification section already states for
   >   price fields, applied to objects.
   > - Piece 1 runs **alone**, as wave 3a, before 03 and 06 add read paths of their own —
   >   see the README's wave 3.
   >
   > Piece 2's redaction funnel is unchanged by this: it still removes keys from objects
   > the caller may see; refusing the object is now piece 1's job at the route.
   > [22](22-medication-tracking.md)'s Q5 subject filter is consistent with this answer
   > rather than an exception to it: a `*_VIEW` leaf says whether the caller may read the
   > domain at all, and 22's predicate says which rows within it — the leaf gates, the
   > domain filters, and neither replaces the other.

9. **The permissions page's `ADMIN`-versus-`USERS_READ` mismatch, which
   [14](14-contract-and-regression-scaffolding.md)'s section 2b deferred to this plan.**
   `UsersController` renders `/user/{id}/permissions` behind `USERS_READ` in the resolved,
   hierarchy-joined shape; the API returns raw unresolved rows behind `ADMIN`. So a
   `USERS_READ` user can open the page, tick boxes and get a 403 on save. This plan
   changes what that endpoint returns (`via_roles`) without deciding either half, and 14
   cannot freeze the contract until it does — it is one of the eight reads 2b lists. The
   resolved shape is the one both consumers want; the permission is the real question, and
   roles sharpen it, because "who may read the permission model" and "who may read *this
   user's* grants" come apart once a grant can arrive through a bundle.

   What this plan states is the rule for its own new endpoints — role management behind
   `USERS_EDIT`, reading roles behind `USERS_READ`, which the tree supports since
   `USERS_EDIT` resolves down to `USERS_READ`. It does **not** decide the existing
   `GET /users/{id}/permissions`, which the API section above leaves at "unchanged shape".
   The obvious extension is to apply the same rule there and return the resolved shape both
   consumers want, and wave 2 may take that rather than wait — but it is an extension of
   this plan's rule, not a decision this plan has recorded, and it wants an answer here
   before anyone relies on it.

   > **Response, from wave 2 (2026-09-04): the read half is taken, the write half is not.**
   > `GET /api/users/{userId}/permissions` now requires `USERS_READ`, which is what the page
   > has always required — the "tick boxes and get a 403 on save" case above was two
   > *different* halves of one screen disagreeing, and the strict half was the one nothing
   > rendered from. `POST` and `PUT /users/{userId}/permissions` stay on `ADMIN`.
   >
   > Loosening those to `USERS_EDIT` is a decision about who may *grant*, and this plan is
   > where that is decided: it is the half roles genuinely sharpen, since a grant can then
   > arrive through a bundle. Wave 2 declined it for the same reason the roadmap gave for
   > unparking S5 and S6 — a rule computable from views that exist today is wave 2's, and a
   > question about what the model should say is this plan's.
   >
   > What wave 2 *did* build that this plan inherits: `User::MayAdminister()` (the target's
   > resolved permissions are a subset of the caller's) and `User::CheckMayGrant()` (every
   > id is real, and the caller holds the closure of what granting it would confer). Both
   > are written against `user_permissions_resolved` and `permission_tree`, whose shape this
   > plan widens rather than changes, so both keep working verbatim once roles land — and
   > `CheckMayGrant()` is what makes loosening the write half safe to consider at all.
   > The shape question is untouched: the endpoint still returns raw unresolved rows.
   > Also unchanged by wave 2: `permission_id` validation, which this plan's verification
   > asserts on its own two id-taking endpoints, is now `CheckMayGrant()`'s first job.
   >
   > **Response, from this plan (2026-09-04): piece 1 takes the write half and the
   > shape.** `POST`/`PUT /users/{userId}/permissions` move from `ADMIN` to `USERS_EDIT`,
   > gated by wave 2's `CheckMayGrant()`, which is what makes that loosening safe; and
   > `GET` returns the resolved, hierarchy-joined shape the page already renders, with
   > `via_roles` on each row. One change to the endpoint's shape rather than two, and 14
   > piece 2 freezes it after piece 1 lands.

## Effort

Piece 1 (roles and read gating): **medium — Q8 answered (a).** One migration (PostgreSQL
only, after the 0008 retirement) with two views rewritten, six new `*_VIEW` leaves, the
`permission_fields` table and its seed, a `CheckPermission` on every read path in five
subtrees, the behaviour-preserving `*_VIEW` backfill, ~8 routes on `UsersApiController` (or
a new `RolesApiController`), the Q9 endpoint change, one list plus two mixin adopters, four
seed roles. Its own sitting, as wave 3a; four to five sittings of work.

Piece 2 (visibility): medium. Two permission leaves, the policy constant, the funnel in
`BaseApiController`, the Blade helper, the OpenAPI annotations, and 14's double snapshot
plus its completeness check. The snapshot work is the bulk of it and is also the part that
pays back on every future endpoint; the completeness check is the smaller half and the one
that keeps paying after this plan, since it fails on a field nobody thought about rather
than on one somebody already classified. Three to four sittings, of which the first is answering Q1, Q3 and Q8 —
Q2 the plan answers itself (a constant), and Q4, Q5 and Q9 are now questions for 02, 18
and wave 2 rather than for this plan.

## Executed

### Piece 1 — wave 3a (2026-09-05)

Migration 0266 adds `roles`, `role_permissions` and `user_roles`, widens the resolved
permission views, and adds the six view leaves specified by Q1/Q3/Q8. Every existing user
receives those leaves; `user_roles` starts empty. Admin, Adult, Child and Guest are seeded
by immutable code. New users receive only configured direct grants and `DEFAULT_ROLES`
(default `[]`). Account creation and both sets of defaults are transactional.

Role APIs and the role list/editor are implemented, together with the users list's Roles
column and the per-user role selector. Q9's permissions endpoint now returns every
hierarchy row with `has_permission` and sorted `via_roles`, and direct grant writes require
`USERS_EDIT`. This replaces the old unresolved-row shape, rather than merely adding a
field to it. The OpenAPI specification describes the new endpoints and response shape.

Assignments check the complete effective grant set. Changing a role also checks its
existing grants and every current holder's permissions, so removing a role cannot strip
a stronger user. Authorization-model writes lock the three grant tables through the
check and transaction. The UI retains overlapping direct grants when roles are removed
and renders role names as text, including delete confirmations.

The six domains' page and API reads are gated, including generic objects, separately
addressable userfields, label/print reads and product/recipe pictures. Calendar aggregation
filters those domains by the caller's view leaves. Navigation and the default landing-page
choice respect read permissions. Batteries, equipment and custom entities retain their
previous read policy; this wave does not add view leaves for them.

Implementation differences and remaining scope:

- Migration 0266 is PostgreSQL-only PHP so its transaction can execute the schema and
  shared SQL seed. The importer reuses that seed after checking its verbatim copy,
  restoring leaves and role grants that a frozen SQLite source cannot carry.
- Unwritten storage/medication reservations move to 0267–0269. The frozen SQLite migration
  line is unchanged. Differential tests still compare the old permission model and six
  original UI-helper columns; PostgreSQL tests assert the new model separately.
- The Effort paragraph includes `permission_fields` in piece 1, but that table and its
  pricing seed are delivered with piece 2's enforcement and snapshot, as Q2 requires.
  **This wave does not hide prices from Child or Guest.** Existing direct grants also
  remain effective when assigning a narrower role; administrators must remove them
  deliberately when narrowing an upgraded user's access.
- [ADR-0018](../adr/0018-role-grants-and-domain-reads.md) records the implemented model as
  Proposed; it does not accept or change another ADR.

Verification is reproducible with `.devtools/pgsql/run-tests.sh rbac`, `import`, and the
full suite. The role phase exercises denied routed reads, allowed view leaves, seed roles,
role provenance, removal, malformed/unknown IDs, delegated grants, the inherited
user-picture ownership rule, injected rollback failures, default roles and actual Blade
renders. `.devtools/frontend/roles.js <url>` exercises role creation and editing, inherited
checkboxes and overlapping direct grants in a browser. The existing S29 probe now includes
role-name delete confirmations. Both browser checks run in `frontend-security`.

On 2026-09-05, the wave 3a working copy based on `96b21711` passed the complete
`.devtools/pgsql/run-tests.sh` suite in the local development image against PostgreSQL 16,
including 256 role-phase assertions and three default-role subprocesses. The role browser
workflow passed with local Chrome, and all 27 S29 probes were clean. PHP syntax, runtime SQL,
API path typing, ADR headers and the eight CI-script unit tests also passed.
