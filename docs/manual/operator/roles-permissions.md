# Roles and permissions

## The model

A user's effective permissions are the union of their direct grants and every role
assigned to them, expanded through the hierarchy below — there is no deny grant, so
assigning a role never takes anything away. Role codes are immutable once created; display
names can be edited, and the built-in roles cannot be deleted. Managing roles or another
user's direct grants requires `USERS_EDIT`; merely inspecting them requires `USERS_READ`. A
caller must already hold everything a grant would confer and everything the target user
already holds — you cannot grant, via a role or directly, a permission you do not have
yourself.

Assign roles and direct grants on a user's edit page (`/user/{id}`) or its permission list
(`/user/{id}/permissions`); manage the role bundles themselves at `/roles` and `/role/{id}`.
`DEFAULT_PERMISSIONS` and `DEFAULT_ROLES` ([Configuration](../configuration.md#authentication))
set what a newly created user starts with — empty by default, deliberately, so creating a
user grants nothing until someone chooses to grant it.

## Prices

Prices are their own permission, `STOCK_PRICES_VIEW`, which sits under `STOCK_PURCHASE` —
so anyone who records purchases sees what things cost, and an account holding `STOCK` or
`ADMIN` is unaffected. The seeded Child and Guest roles hold neither, so they see no prices
anywhere:

- the stock overview, the entries list, the shopping list, the meal plan, a recipe, the
  product card, and the product form's barcode table
- an API response, where a field you may not see is absent from the JSON rather than
  `null`
- filtering: naming the field in `query[]` or `order` is answered `400`, so it cannot be
  found that way either

`GET /stock/products/{productId}/price-history` and the Spendings report answer `403`,
because they are entirely prices.

Two things this does *not* do. It is not a deny grant: an account that still holds `STOCK`,
`STOCK_PURCHASE` or `ADMIN` directly keeps seeing prices whatever role you also assign, so
narrowing an upgraded user means removing the direct grant deliberately. And upgrading does
not take a field from anyone who already held `STOCK` or `ADMIN` — what changes on upgrade
is the account whose only stock grant is `STOCK_VIEW`, which could read every price before
and cannot now.

`FEATURE_FLAG_STOCK_PRICE_TRACKING` ([Configuration](../configuration.md#feature-flags)) is
the other half and is independent: it says whether this installation tracks prices at all.
Prices are shown when the flag is on *and* the user holds the permission.

## Domain reads

Six domains each carry their own `*_VIEW` leaf, and a page, an API response or a generic
object read from that domain requires it: `STOCK_VIEW`, `SHOPPINGLIST_VIEW`, `CHORES_VIEW`,
`TASKS_VIEW`, `RECIPES_VIEW`, `MEALPLAN_VIEW`. The calendar overview includes only the
events from domains the caller can read. An installation upgraded from before this model
existed keeps all six for its existing users; only a newly created user starts without them.

## Consumption recipes

A [consumption recipe](../using-victual/consumption-recipes.md) is visible only to its owner
and the users it is shared with. A share names a user and the rights they hold on that one
recipe (view, record consumption, edit, undo, share). It confers no permission and changes
no one's effective permissions, so it needs no entry in the permission tree and the rules for
granting permissions do not apply to it. A right works only together with the permission the
same act needs elsewhere:

| Act on a recipe | Share right | Also needs |
|---|---|---|
| List or open | Any share, or ownership | `STOCK_VIEW` |
| Create | None | `STOCK_VIEW` and `STOCK_CONSUME` |
| Record consumption | Record consumption | `STOCK_VIEW` and `STOCK_CONSUME` |
| Edit | Edit | `STOCK_VIEW` |
| Undo through the recipe | Undo | `STOCK_VIEW` and `STOCK_EDIT` |
| Share, or change a share | Share | `STOCK_VIEW` |
| Delete, or transfer ownership | Ownership | `STOCK_VIEW` |

`ADMIN` and the `USERS_*` permissions give no access to a recipe. An account administrator who
can change a user's password can sign in as that user
([ADR-0014](../../adr/0014-administering-a-user-is-a-subset-question.md)), which this model
accepts for a household instance.

## Consumption events and mappings

An [external consumption event](external-consumption.md) and the mapping it books through belong
to one user. Nothing is shared: no share, role or administrator permission lets another user
read or act on them, and another user's event answers `404`.

| Act | Needs |
|---|---|
| Read events, mappings and capabilities | `STOCK_VIEW` |
| Report, delete or resolve an event; write or delete a mapping | `STOCK_VIEW` and `STOCK_CONSUME` |
| Map to a consumption recipe | The right to record consumption on that recipe |
| Link an event to a transaction that another user recorded | `STOCK_EDIT` as well |

The bookings an event makes are stock bookings made as the signed-in user, and anyone who can
see the stock journal sees them.

## Permission reference

`ADMIN` implies every other permission. The rest, by domain:

| Domain | Permissions |
|---|---|
| Stock | `STOCK_VIEW`, `STOCK`, `STOCK_PURCHASE`, `STOCK_PRICES_VIEW`, `STOCK_CONSUME`, `STOCK_TRANSFER`, `STOCK_INVENTORY`, `STOCK_OPEN`, `STOCK_EDIT` |
| Shopping lists | `SHOPPINGLIST_VIEW`, `SHOPPINGLIST`, `SHOPPINGLIST_ITEMS_ADD`, `SHOPPINGLIST_ITEMS_DELETE` |
| Chores | `CHORES_VIEW`, `CHORES`, `CHORE_TRACK_EXECUTION`, `CHORE_UNDO_EXECUTION` |
| Tasks | `TASKS_VIEW`, `TASKS`, `TASKS_MARK_COMPLETED`, `TASKS_UNDO_EXECUTION` |
| Recipes | `RECIPES_VIEW`, `RECIPES` |
| Meal plan | `MEALPLAN_VIEW`, `RECIPES_MEALPLAN` |
| Batteries | `BATTERIES`, `BATTERIES_TRACK_CHARGE_CYCLE`, `BATTERIES_UNDO_CHARGE_CYCLE` |
| Equipment | `EQUIPMENT` |
| Calendar | `CALENDAR` |
| Master data | `MASTER_DATA_EDIT` (products, locations, quantity units, product groups, and — with the label subsystem — templates, printers and printing itself) |
| Users | `USERS`, `USERS_READ`, `USERS_CREATE`, `USERS_EDIT`, `USERS_EDIT_SELF` |
| Everything | `ADMIN` |

Two shapes recur across domains: a bare domain permission (`STOCK`, `CHORES`, …) generally
gates viewing and managing that domain's master data and history, while the `_VIEW` leaf
specifically is the narrower "read the current state" grant introduced for domain reads. A
caller who can print a label needs `MASTER_DATA_EDIT` *and* the relevant domain's `_VIEW`
leaf together — see [Label printing](label-printing.md).
