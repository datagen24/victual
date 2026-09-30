# Shopping lists

Requires `FEATURE_FLAG_SHOPPINGLIST`.

**`/shoppinglist`** is the list itself: check items off as you buy them, and add a product or a
free-text note. Where stock is enabled, you can also convert a checked item straight into a
purchase booking using the last known price and, if the product has default due days set, submit
that booking automatically (`shopping_list_to_stock_workflow_auto_submit_when_prefilled`, a
per-user setting). **`/shoppinglistitem/{id}`** edits one item directly.

With `FEATURE_FLAG_SHOPPINGLIST_MULTIPLE_LISTS`, a household can keep more than one list
(**`/shoppinglist/{listId}`** manages a list's own name); without it, there is exactly one.

## Filling a list automatically

From the stock overview or the shopping list itself:

- Add every product currently below its minimum stock amount.
- Add every overdue product.
- Add every expired product.

Each is a one-shot bulk action, not a standing rule — nothing re-adds a product later just
because it is still short. The per-user setting `shopping_list_auto_add_below_min_stock_amount`
(with `shopping_list_auto_add_below_min_stock_amount_list_id` naming which list) instead adds
a product automatically the moment stock drops below the minimum, without waiting for one of
these actions.

## Which list an item lands on

With more than one list, an item goes to the list you pick when you add it, or to list 1 if
you pick none. The automatic below-minimum add uses the list its setting names. A recipe's
"add not fulfilled products" action always uses list 1. If any list already holds that
product, the action raises that item's amount instead of adding a new one.

Products and recipes carry a `default_shopping_list_id` field in the API. Victual stores it
and does nothing else with it: it does not route anything to that list yet.

## Printing

Two independent print paths, each with its own default options as per-user settings
(`shopping_list_print_show_header`, `shopping_list_print_group_by_product_group`,
`shopping_list_print_layout_type`):

- An ordinary printable page, formatted as a table or a plain list.
- A thermal receipt printer, when `FEATURE_FLAG_THERMAL_PRINTER` and the `TPRINTER_*`
  [settings](../configuration.md#thermal-printer) are configured.

## Settings

**`/shoppinglistsettings`** holds the settings above at the household level, alongside a
small calendar view toggle (`shopping_list_show_calendar`) and whether quantities always
round up on display (`shopping_list_round_up`).
