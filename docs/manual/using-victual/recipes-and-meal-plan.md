# Recipes and meal plan

Requires `FEATURE_FLAG_RECIPES`; the meal plan additionally requires `FEATURE_FLAG_RECIPES_MEALPLAN`.

## Recipes

**`/recipes`** is the recipe browser and editor in one page: the `recipe` query parameter
selects which recipe is shown (the first one alphabetically if none is given). The page
displays that recipe's resolved ingredient positions — including any sub-recipe used as an
ingredient — with its total cost and calories rolled up. **`/recipe/{id}`** is the plain
edit form for the recipe's own fields; **`/recipe/{id}/pos/{posId}`** edits one ingredient
line.

**Fulfilment** is the question "can I cook this with what's in stock right now" — the
recipe view shows it per-recipe, and it accounts for the directed product substitutions set
up in [Stock](stock.md#products-and-their-master-data). From the recipe view you can add
whatever is missing straight to the shopping list, or consume the recipe outright (book
every ingredient's stock deduction as one action).

Copying a recipe duplicates it, positions included, as a starting point for a variant.

## Meal plan

**`/mealplan`** is a calendar of which recipe (or a free-text note) is planned for which
day, built around a requested week. `MEAL_PLAN_FIRST_DAY_OF_WEEK` sets which day a week
starts on (or `-1` to always start "today"'s week on today); see
[Configuration](../configuration.md#localization-and-display). **`/mealplansections`** and
**`/mealplansection/{id}`** manage the named sections a day's plan is grouped into (e.g.
"Breakfast", "Dinner").

## Settings

**`/recipessettings`** covers ingredient list grouping and display options — grouping
ingredients by product group, showing the recipe list beside the recipe rather than above
it, and an optional per-ingredient "done" checkbox while cooking.
