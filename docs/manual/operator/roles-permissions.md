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

Nine domains have a read permission: `STOCK_VIEW`, `SHOPPINGLIST_VIEW`, `CHORES_VIEW`,
`TASKS_VIEW`, `RECIPES_VIEW`, `MEALPLAN_VIEW`, `BATTERIES_VIEW`, `CALENDAR_VIEW` and
`EQUIPMENT_VIEW`. Prices have the additional `STOCK_PRICES_VIEW` gate described above.
The calendar includes only events from domains the caller can read.

The original six domain leaves were granted to existing users when introduced.
The battery, calendar and equipment leaves follow a different upgrade rule under
[ADR-0035](../../adr/0035-batteries-equipment-calendar-and-custom-entities-require-view-permissions.md):
each is a child of its domain permission. An account holding `BATTERIES`, `CALENDAR`,
`EQUIPMENT` or `ADMIN` inherits the corresponding read permission. The upgrade does not
grant these three leaves directly to every existing user.

`BATTERIES_VIEW` permits battery pages, current battery state, details and generic battery
and charge-cycle reads. `EQUIPMENT_VIEW` permits equipment pages, generic equipment reads
and equipment manual downloads. These read grants do not confer write permissions.

`CALENDAR_VIEW` permits the calendar page, session-based iCal access and creating or reading
a calendar sharing link. An external calendar client holding a valid sharing secret can
read the iCal feed without a separate permission check. Treat that link as a credential.

A stock booking through the API also needs `STOCK_VIEW`, because its answer lists the
`stock_log` rows it wrote. This covers purchase, consume, transfer, open and inventory, their
by-barcode forms, editing or measuring a stock entry, and weighing a location. A caller that
holds the booking permission but not `STOCK_VIEW` gets `403` and nothing is booked. Every seeded
role holds `STOCK_VIEW`, so only an account with custom direct grants is affected. A by-barcode
form checks both permissions before it looks the barcode up, so a caller that lacks either gets
`403` whether or not the barcode exists.

## Medication presets

A [medication preset](../using-victual/consumption-recipes.md) is visible only to its owner
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

Sharing a recipe does not reveal which accounts exist. `POST` and `PUT` on `/api/consumption/recipes/{id}/shares` answer
`204` for every authorised caller, whether the target is a real user, an unknown username or user id,
or the owner; only a real, shareable user gets a row. A right the caller lacks is refused with `403`
whether or not the target exists.

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
| Map to a medication preset | The right to record consumption on that recipe |
| Link an event to a transaction that another user recorded | `STOCK_EDIT` as well |

The bookings an event makes are stock bookings made as the signed-in user, and anyone who can
see the stock journal sees them.

## Adding a product, unit or location

Creating a product, product barcode, location, quantity unit, product group or store needs
`MASTER_DATA_ADD`. Editing or deleting any of those six needs `MASTER_DATA_EDIT`. `MASTER_DATA_ADD`
is a child of `MASTER_DATA_EDIT` in the permission hierarchy, so every account that holds
`MASTER_DATA_EDIT` or `ADMIN` already holds it. A narrower account can hold `MASTER_DATA_ADD`
alone and add those records without being able to change or remove any of them. No seeded role
gets `MASTER_DATA_ADD` automatically. Grant it to an account or a role when a household wants
members to add products without editing them.

The generic object routes (`/api/objects/{entity}`), which the web forms save through, apply
these gates:

| Operation | Entities | Permission |
|---|---|---|
| Create | Products, product barcodes, locations, quantity units, product groups, stores | `MASTER_DATA_ADD` |
| Edit, delete | The same six | `MASTER_DATA_EDIT` |
| Create, edit | Chores | `CHORES` |
| Create, edit | Tasks | `TASKS` |
| Delete | Chores and tasks | `MASTER_DATA_EDIT` |
| Merge | Chores (`POST /chores/{keep}/merge/{remove}`) | `MASTER_DATA_EDIT` |
| Create, edit, delete | Quantity unit conversions, batteries, task categories | `MASTER_DATA_EDIT` |
| Create, edit, delete | Userfield and user entity definitions | `ADMIN` |
| Save values | Userfield values on an object | `MASTER_DATA_EDIT` |

The shopping list, recipes, meal plan and equipment have their own permissions. Nothing in a
stock, consumption or recipe permission lets an account add a product.

An account with `MASTER_DATA_EDIT` and without `CHORES` or `TASKS` can no longer create or edit
chores or tasks. Those two domain permissions gate create and edit for every chore and task,
whoever created it. The seeded Adult role holds `CHORES` and `TASKS`, so it can create and edit
both. The Child role cannot.

Quantity unit conversions stay under `MASTER_DATA_EDIT`. Two exceptions apply. Creating a product
by any route also creates the default conversions that the database makes for it
(`products_default_qu_conversions_INS`), so check their factors as in step 3 below. The barcode
lookup described below can also set the purchase-to-stock conversion of the product it creates.

### Barcode lookup

`GET /api/stock/barcodes/external-lookup/{barcode}` needs `STOCK_VIEW` for a lookup that creates
nothing. With `add=true` it also needs `MASTER_DATA_ADD`, because it creates a product and a
product barcode. A read-only API key is refused either way.

- **Picture.** A fetched picture is stored only for the product the lookup creates, and never
  replaces an existing file. If a file of that name exists, Victual creates the product without a
  picture and leaves the existing file as it was.
- **Conversion.** The lookup can set the new product's purchase-to-stock factor when it creates
  the product. It never changes a conversion of another product or a global conversion.
- **Shopping list.** Creating a product can add below-minimum-stock products to the shopping
  list, depending on the creating user's setting. That side effect happens only when the account
  also holds `SHOPPINGLIST_ITEMS_ADD`. The product is created either way.

### Limits of the web product form

The web product form is not part of `MASTER_DATA_ADD`. Uploading a picture, saving userfield
values and adding quantity unit conversions from the form still need `MASTER_DATA_EDIT`. An
account that holds only `MASTER_DATA_ADD` can create a product through the API or the lookup,
and can use the new-record buttons that this permission shows. It cannot finish the parts of the
form that need `MASTER_DATA_EDIT`.

Merging two products needs both `STOCK_EDIT` and `MASTER_DATA_EDIT`. A merge moves the stock,
history and references of one product onto the other and then deletes the first product, and it
cannot be undone. An account that lacks either permission gets `403` and nothing changes. The
seeded Adult role holds `STOCK_EDIT` but not `MASTER_DATA_EDIT`, so it cannot merge. The products
page disables the Merge menu item for an account that lacks either permission.

The seeded Adult, Child and Guest roles hold neither `MASTER_DATA_EDIT` nor `MASTER_DATA_ADD`, so
on a household set up with those roles only an administrator can add a medication or vitamin.
`ADMIN` is not required: an account that holds `MASTER_DATA_ADD` can add the product, and an
account that holds `MASTER_DATA_EDIT` can also edit it and add conversions. Such an account cannot
record a dose or save a consumption mapping unless it also holds `STOCK_VIEW` and `STOCK_CONSUME`.

Master data is shared by every account, and the permission covers every product, not only the
ones its holder added. A medication preset's owner has no extra right to the products on it.

To let a client map a new medication:

1. An account that holds `MASTER_DATA_EDIT` adds the product in the web interface. An account
   that holds only `MASTER_DATA_ADD` can create the product through the API, but the web form
   still needs `MASTER_DATA_EDIT` for the picture, userfields and conversions. Give the product
   the quantity unit the medication is counted in ("tablet", "mL") as its stock unit, because a
   mapping in the stock unit needs no conversion.
2. Add a quantity unit conversion for the product only if the client reports in another unit,
   for example milligrams for a product counted in tablets. The conversion is the amount of the
   product's stock unit in one of the other unit. Adding it needs `MASTER_DATA_EDIT`.
3. Check the factor of any conversion Victual created when it saved the product. A purchase,
   consume or price unit that differs from the stock unit gets a conversion of factor 1 unless a
   default conversion already applies.
4. The household member opens the client, approves the mapping and reviews the first dose.

A client that cannot create master data names the missing product or conversion and stops. See
[External consumption events](external-consumption.md#mappings).

## Shopping lists

A shopping list and the items on it use the same permissions. Adding an item, creating a list and
renaming a list need `SHOPPINGLIST_ITEMS_ADD`. Removing an item and deleting a list need
`SHOPPINGLIST_ITEMS_DELETE`. There is no separate permission for lists themselves.

The seeded Child role holds `SHOPPINGLIST_ITEMS_ADD` and not `SHOPPINGLIST_ITEMS_DELETE`. A
Child account can therefore create and rename shopping lists but cannot delete them, or delete
items from them. This is deliberate for v0.5.0. A separate permission for lists would need its own
design decision.

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
| Batteries | `BATTERIES_VIEW` (pages, current state, details and generic reads), `BATTERIES`, `BATTERIES_TRACK_CHARGE_CYCLE` (works without `BATTERIES_VIEW`, such as for a charger integration; without it the response carries only the new charge cycle's id), `BATTERIES_UNDO_CHARGE_CYCLE` (works without `BATTERIES_VIEW`) |
| Equipment | `EQUIPMENT_VIEW` (pages, generic reads and manual downloads), `EQUIPMENT` |
| Calendar | `CALENDAR_VIEW` (page, session iCal and sharing-link access), `CALENDAR` |
| Master data | `MASTER_DATA_ADD` (create products, locations, quantity units, barcodes, stores and product groups; a child of `MASTER_DATA_EDIT`), `MASTER_DATA_EDIT` (edit and delete of products, locations, quantity units, barcodes, stores and product groups, quantity unit conversions, batteries, task categories and userfield values, delete and merge of chores, delete of tasks, and — with the label subsystem — printing itself; see [Adding a product, unit or location](#adding-a-product-unit-or-location)) |
| Users | `USERS`, `USERS_READ`, `USERS_CREATE`, `USERS_EDIT`, `USERS_EDIT_SELF` |
| Everything | `ADMIN` |

Two shapes recur across domains: a bare domain permission (`STOCK`, `CHORES`, …) generally
gates viewing and managing that domain's master data and history, while the `_VIEW` leaf
specifically is the narrower "read the current state" grant introduced for domain reads. A
caller who can print a label needs `MASTER_DATA_EDIT` *and* the relevant domain's `_VIEW`
leaf together — see [Label printing](label-printing.md).
