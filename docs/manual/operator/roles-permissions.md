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
anywhere: not on the stock overview, the entries list, the shopping list, the meal plan, a
recipe, the product card or the product form's barcode table, and not in an API response —
a field you may not see is absent from the JSON rather than `null`, and naming it in
`query[]` or `order` is answered `400` so that it cannot be found by filtering on it
either. `GET /stock/products/{productId}/price-history` and the Spendings report answer
`403`, because they are entirely prices.

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
