# 31. Directed substitution between products

**Goal:** Whole beans stand in for ground coffee and never the reverse. A block of cheddar
stands in for shredded; unsalted butter stands in for salted. The system can say so, for
products that share no parent.
**Depends on:** [ADR-0023](../../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md),
**accepted 2026-09-14**, decision 4.
**Interacts with:** [30](30-nested-product-groups.md), which groups the products this
relates; [14](14-contract-and-regression-scaffolding.md) piece 2, which freezes the response
contract this adds to.
**Status:** **Landed 2026-09-15**, after [30](30-nested-product-groups.md). Tracked as
[issue 125](https://github.com/datagen24/victual/issues/125). Migration **0279**, renumbered
from 0278 to make room for [issue 148](https://github.com/datagen24/victual/issues/148)'s
migration ahead of 30's (see [RESERVATIONS.md](../../../migrations/RESERVATIONS.md)).

## Missing relation after separate products

The catalogue sampling behind ADR-0023 made seven of thirteen candidate pairs **separate
products**. That is the right model — a recipe wanting whole cloves is not satisfied by a jar
of crushed, and a machine that grinds beans cannot take grounds — but it removes every
relationship the system can currently express between them.

Today substitution is a view over parentage. `products_current_substitutions` exists to
answer one question: *when a parent product is not in stock itself, which of its sub products
should be used?* Its whole mechanism is `products_resolved`, the parent-to-child mapping.
There is no substitution table anywhere in the schema.

So with beans and grounds modelled as separate products, they share no parent, and **no
substitution is expressible at all**. The relationship the maintainer described in one
sentence — *"I can grind beans but I can't put grounds in a machine that needs beans"* — has
nowhere to live.

## The relation

A directed edge between two products: *A can be used where B is called for.* Direction is the
whole point and the reason a flag on the existing view does not reach.

| Where B is wanted | A that will do | Reverse |
|---|---|---|
| ground coffee | whole beans | no — a burr grinder will not take grounds |
| shredded cheddar | a block | no — you cannot un-grate |
| salted butter | unsalted, plus salt | no — you cannot un-salt |
| crushed garlic | whole cloves | no |
| ground spice | whole spice | no |

Each is one-way, and each crosses a product boundary that has no parent above it.

## Proposed change

### Schema

A table of directed edges — `(from_product_id, to_product_id)` meaning *from* substitutes
*for* to — with a uniqueness constraint on the pair and a guard against an edge to itself.

Whether it carries more than the pair is open question 1. A conversion factor is the obvious
candidate and the obvious trap: some substitutions are 1:1 by weight and some are not, and a
factor that is sometimes meaningful is worse than none.

The existing parent/child substitution is **not** migrated into rows. Decision 4 makes the
shared-parent case one source of edges rather than the definition, so the view can union both;
rewriting shipped behaviour into data is a change this plan does not need to make.

### Views

Extend or supersede `products_current_substitutions`. The present view answers "what should be
used instead of this parent"; the new question is "what on hand will do where this product is
called for", which has a different shape: several candidates, ordered, each with its own
reason for being offered.

Ordering has to be decided rather than inherited. Today's rule is the default consume rule —
priority, open, best-before, purchased date. With edges, nearness is a second axis and the two
can disagree.

### API and UI

Additive: the edges as an entity, and the candidates on the product detail read. Permitted
under [ADR-0005](../../adr/0005-wire-contract-is-the-invariant.md).

**This wants to land before [14](14-contract-and-regression-scaffolding.md) piece 2**
([issue 83](https://github.com/datagen24/victual/issues/83)) freezes the response contract in
wave 5, for the same reason [28](28-open-container-measurement.md) does.

The UI is a product-form section for the edges, and a suggestion where a product is wanted and
absent — on the consume screen and on the shopping list. Per the one-tap requirement recorded
in [29](29-working-container-replenishment.md), accepting a suggestion is one action, not a
navigation.

### Migration

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../../migrations/RESERVATIONS.md).

## Verification

A suite phase, PostgreSQL-only for the reason 08's and 30's are. Cases:

- beans offered where grounds are wanted; grounds **not** offered where beans are wanted
- a block offered for shredded, one way
- an edge to itself refused, and a duplicate pair refused
- a product with no edges behaving exactly as it does today
- the existing parent/child substitution still working, unchanged, with no edges present —
  the control that proves this is additive
- a chain — if A substitutes for B and B for C, whether A is offered for C is open question 2,
  and whichever way it lands the case is asserted

## Open questions

1. **Does an edge carry a quantity factor?** Whole and ground coffee are 1:1 by weight; a
   tablespoon of fresh herb is a teaspoon of dried. A factor that is right sometimes is worse
   than absent, and [ADR-0022](../../adr/0022-open-containers-carry-a-measured-remainder.md)
   decision 3 has already set a precedent for refusing rather than approximating.
2. **Is the relation transitive?** Whole spice → cracked → ground is a plausible chain. A
   recursive closure is the pattern this repository has three times already
   (`quantity_unit_conversions_resolved`, `locations_resolved`, plan 30's), so it is cheap to
   build and the question is whether it is wanted.
3. **Does a substitute satisfy a minimum stock amount?** If beans are in stock and grounds are
   not, is ground coffee missing? Answering yes keeps the shopping list short and risks
   never buying grounds; answering no is simpler and occasionally wrong.
4. **Does it participate in recipe fulfilment?** `not_check_stock_fulfillment_for_recipes`
   exists on products, and recipe stock checks are where a cook most wants to be told the
   block will do.

## Effort

Small to medium. The table and the edges are straightforward; the work is in the view's
ordering rule and in the surfaces that offer a suggestion. Open question 2 decides whether a
recursive closure joins the three this repository already maintains.

## Executed

Landed as `migrations/0279.pgsql.sql`, at the number RESERVATIONS.md already reserved. One
table (`product_substitutions`: `from_product_id`, `to_product_id`, a `CHECK` refusing a
self-edge and a `UNIQUE` refusing a duplicate ordered pair — no `FOREIGN KEY`, matching every
other product-referencing table in the baseline), one view
(`product_substitutions_resolved`), a cascade-delete addition, the API surface, a product-form
UI section, a PostgreSQL-only suite phase and a browser probe. None of the four open questions
gated the start, per the issue's own scheduling comment, so each is answered and recorded here
rather than left for a later PR to discover the schema already assumed one way.

**Q1 (quantity factor): no.** The plan named its own precedent for this: ADR-0022 decision 3
refuses to approximate a conversion that is right sometimes, rather than accept a factor that
is right sometimes and wrong otherwise. A factor that holds for coffee (1:1 by weight) and not
for herbs (a tablespoon of fresh is a teaspoon of dried) is worse than no factor at all. An
edge is therefore the bare ordered pair.

**Q2 (transitivity): no, not in this migration.** A recursive closure is cheap here in the
sense the plan means: the pattern already appears in three other resolved views
(`quantity_unit_conversions_resolved`, `locations_resolved`, `product_groups_resolved`). Those
three are trees, though, and this relation is an arbitrary directed graph, where `A → B` and
`B → A` can both be true here without contradiction — something a tree's parent pointers
cannot express. A transitive closure over it therefore needs cycle protection none of the
three precedents had to build.

Nothing in the verification list requires the closure itself, only that the chain case is
*asserted* whichever way it lands. `product-substitutions-tests.php` case 6 does that: whole
spice substitutes for cracked, cracked for ground, and ground spice's candidates carry cracked
but not whole.

**Q3 (counts toward minimum stock): no, unchanged from today.** `stock_missing_products`
(`db/pgsql/baseline/05_views_l2.sql`) never joins `products_current_substitutions`, so a sub
product in stock already does not satisfy its parent's `min_stock_amount` — a directed edge
that did so would be a new, inconsistent exception rather than a preserved behaviour. Left for
a follow-on together with plan 30's own questions 1–3, which would need the same view touched.

**Q4 (recipe fulfilment): no, and not by editing `products_current_substitutions`.** This
finding decided how `product_substitutions_resolved` had to be built, and it was not visible
from the plan document alone. `products_current_substitutions` is SQLite-line and
differential-tested: `.devtools/pgsql/run-tests.sh views` seeds both engines from the same
fixture and compares `products_current_substitutions`'s output row for row
(`.devtools/pgsql/view-tests/02_products_and_pricing.sql`'s own `@views` header names it).

`product_substitutions` is PostgreSQL-only, so folding it into that view — which the plan's
own wording invited ("extend or supersede `products_current_substitutions`") — would have made
the two engines' definitions diverge for a feature only one of them can run. That is exactly
what AGENTS.md's "do not delete SQLite behaviour the suite compares against" guards against,
even though nothing here deletes anything. `product_substitutions_resolved` is therefore a
wholly new, additive view rather than a change to the existing one, confirmed by running
`run-tests.sh views` unchanged (still "`products_current_substitutions (1 rows identical)`")
and `run-tests.sh triggers` unchanged after the cascade-delete addition.

`recipes_pos_resolved` keeps using only the parent/child mechanism; a later plan decides
whether the response contract should carry the distinction into recipe fulfilment.

**`GetProductDetails()` runs on both engines, and the view does not — this was the one real
defect the local suite caught rather than predicted.** `AddProduct()` calls
`GetProductDetails()` after every product creation, SQLite included
(`.devtools/pgsql/rollback-tests.php` drives it against SQLite as part of `run-tests.sh
rollback`), and an unconditional read of `product_substitutions_resolved` fataled there with
`SQLSTATE[HY000]: General error: 1 no such table`. Fixed the same way
`stock_amount_measured` a few lines above it already is: `substitution_candidates` is `[]` on
SQLite, gated on `DatabaseService::GetInstance()->GetDialect()->GetName() === 'pgsql'`, and
`run-tests.sh all` is clean with this fix in place.

**The candidates view unions two sources without disturbing either.** `product_substitutions`
(`direction = 'directed'`) and `products_resolved` filtered to `sub_product_id !=
parent_product_id` (`direction = 'shared_parent'`) are combined with `UNION`. A first review
round found two defects in the first draft that broke this guarantee.

The first draft's comment claimed the two sources "cannot produce the same `(from, to)` pair"
— this was false. Nothing ties `product_substitutions` to `parent_product_id`, so a directed
edge `X -> P` can be added where `X` already is `P`'s sub product under the existing mechanism.
Because the two branches disagree on `direction`, the rows differ and `UNION`'s dedup does not
catch it, so `P`'s candidates listed `X` twice. The fix excludes, from the directed branch
only, any pair `products_resolved` already carries (`WHERE NOT EXISTS (SELECT 1 FROM
products_resolved pr WHERE pr.sub_product_id = ps.from_product_id AND pr.parent_product_id =
ps.to_product_id)`).

Stock was read through `products_resolved` to a candidate's *parent's* `stock_current` row,
which is that parent's family total, not the candidate's own contribution. This was invisible
for a top-level candidate, since its own id and its "parent" id (via `products_resolved`) are
the same row, so the bug and the fix read identically. It was wrong for any candidate that is
itself a sub product, though, since `stock_current` already carries that sub product's own
un-rolled-up row too (`04_views_l1a.sql`'s second `UNION` half, keyed by
`pr.sub_product_id AS product_id`).

The fix joins `stock_current` on `from_product_id` directly, dropping the parent join
entirely. It still does not use `stock_next_use`, for the original, separate, correct reason:
`stock_next_use` carries one row per physical stock entry, so joining it straight to
`from_product_id` would multiply a candidate row per stock entry —
`products_current_substitutions` avoids that only by ending in a single-row `LIMIT 1`.

Both defects were in the migration as first pushed to the PR, caught by a maintainer review
round and not by the suite, whose case 9 at the time only ever created top-level candidates. A
case exercising a sub-product candidate was added alongside the fix, per the same lesson plan
30's own Executed section already recorded once: a suite that does not construct the exact
shape a defect needs does not find it by accident.

**API.** `product_substitutions` (writable) and `product_substitutions_resolved` (read-only)
in the three `ExposedEntity*` enums and `EntityReadPolicy::PERMISSIONS`
(`PERMISSION_STOCK_VIEW`, matching `product_groups`/`product_groups_resolved`). Neither needed
an entry in `GenericEntityApiController`'s per-entity branches. The self-edge/duplicate-pair
guards are plain `CHECK`/`UNIQUE` constraints, not a `BEFORE` trigger with a custom `RAISE`, so
the existing generic `PDOException` → 400 path (the same one every other constraint violation
in this schema already goes through) applies with no bespoke message. There is no delete guard
to translate, either, because deleting an edge cascades nothing and blocks nothing.

No `oneOf` entry in `victual.openapi.json`'s `/objects/{entity}` paths, matching
`product_groups` and `quantity_unit_conversions` precedent — the generic path does not require
one. `ProductSubstitutionResolved` is documented purely for readability, the same as
`ProductGroupResolved`.

`GetProductDetails()` gains `substitution_candidates`, ordered by
`from_product_amount_in_stock` descending then `from_product_best_before_date` ascending — in
stock first, soonest to expire among those. There is no cross-source priority between the two
`direction` values, since the plan's own ordering question was about nearness against the
default consume rule and never named one substitution source as preferred over the other.

**UI.** A "Substitutions" section on the product edit form (`views/productform.blade.php`) is
built on the barcodes section's exact pattern: a `DataTable` lists edges naming this product on
either side, worded per direction, and links to the other product by name.

That lookup uses an unfiltered product list. A review round caught the first version filtering
it to active products only, so an edge naming a since-deactivated product rendered an empty
cell next to a still-live delete button. The picker for choosing a *new* edge's other product
stays active-only, correctly.

An "Add" button opens `views/productsubstitutionform.blade.php` as an embedded dialog
(`views/components/productpicker` for the other product, plus a direction radio).
`public/viewjs/productsubstitutionform.js` translates the choice to
`from_product_id`/`to_product_id` before the `POST`, the same way the barcode form remaps
`display_amount` to `amount`.

**Create only** — an edge has nothing to edit beyond which two products and which direction,
both picked once, so the table offers delete, not edit. The same review round found the form's
edit-mode branch, its route parameter, and the JS `PUT` path were all unreachable dead code,
since nothing in the table ever linked to them. They were cut rather than wired up, matching
plan 30's own "removed rather than debugged blind" precedent for speculative surface.

The route (`GET /productsubstitutions/new`) also gained a guard the first version lacked: the
required `product` query parameter is checked before use, raising `HttpNotFoundException` when
absent, rather than dereferencing a null product straight into the blade.

The consume-screen and shopping-list suggestion surfaces the plan also asks for are **not
built**. They are a materially different piece of work — surfacing a candidate at the moment a
wanted product is absent, one-tap per plan 29's own requirement — from managing the edges
themselves, and nothing in the verification list depends on them existing yet. This is left for
a follow-on the way plan 30 left its own questions 1–3.

**`MergeProducts()` needed a fourth review-round fix, in code this plan does not otherwise
touch.** It re-points `stock`, `stock_log`, `product_barcodes`, `quantity_unit_conversions`,
`recipes_pos`, `recipes`, `meal_plan` and `shopping_list` from the removed product to the kept
one before deleting the removed row. `trg_cascade_product_removal`'s own
`product_substitutions` cleanup, fired by that `DELETE`, would otherwise drop every edge naming
the removed product rather than carrying it to the survivor. This failed silently, with no
error and no suite anywhere noticing, because nothing before this plan referenced the table at
all.

The fix uses the same re-point-then-delete shape the method already uses for every other
table, in three passes required by the two constraints an edge can violate once repointed.
First, an edge *between* the two products being merged is dropped outright, since it would
become a self-edge refused by `product_substitutions_no_self_edge`. Second, an edge from or to
the removed product that would duplicate one the kept product already has is dropped rather
than repointed, since it would violate `product_substitutions_pair_key` — this leaves the kept
product's own pre-existing edge as the survivor rather than a copy. Third, whatever remains is
repointed on both columns.

**Verification**, against real PostgreSQL 16.13 (`postgres:16` at the OS package level, on
2026-09-15): `php .devtools/pgsql/check-migrations.php` reports `MIGRATION NUMBERING OK` with
no waiver.

`.devtools/pgsql/product-substitutions-tests.php` (`run-tests.sh substitutions`) passed all 26
of its original assertions on first run, before the maintainer review round above found the
two view defects and the `MergeProducts()` gap none of those 26 exercised. Three new cases — a
sub-product candidate's own stock vs. its parent's, a directed edge duplicating a
`shared_parent` pair, and `MergeProducts()`'s three-pass repoint — bring it to **34 assertions,
all passing** against the fixed migration and service method.

`run-tests.sh migrate` required `product_substitutions` added to
`.devtools/pgsql/migratedifftest.php`'s `ENGINE_EXCLUSIVE_TABLES` (the same mechanism
`product_location_min_stock` and `storage_classes` already use) before it passed. `run-tests.sh
views` and `run-tests.sh triggers` needed no changes and pass unchanged, which is itself the
proof that `products_current_substitutions` and the differential harness are untouched.

`run-tests.sh all` is clean end to end, both the `GetProductDetails()` SQLite fix and the
review-round fixes included. PHP lint (`php -l`) is clean on every changed file,
`victual.openapi.json` parses as valid JSON, the workflow YAML parses, and `php
.devtools/check-cited-jobs.php` reports every cited job exists.

**The browser probe (`.devtools/frontend/product-substitutions.js`) carried a race the same
review round found, and the fix that round applied did not close it.** The race: the
post-delete assertions ran immediately after the confirm click, rather than waiting for the
delete-then-`PUT`-then-`reload()` chain that click starts.

The follow-on API check compounded it. Its bare `.catch(() => false)` could not tell a
genuine 404 (edge gone) apart from "execution context destroyed" (asked while the page was
mid-navigation), so a torn-down context read as a pass either way. Sequencing that check
strictly after the waiter was the right repair and it stands.

The waiter itself was wrong. `page.waitForLoadState('load')` reports the state of the
document that is current when it is called. That document reached `load` at the
`page.goto()` above, and the confirm click only opens a bootbox modal, so the waiter
resolved at once whether it was armed before the click or after. Arming position matters
only for a waiter that listens for the *next* event, which `waitForLoadState` is not. The
probe now arms `page.waitForEvent('load', { timeout: 15000 })` in the same `Promise.all`.

**The probe has since been run end to end, which the session that wrote it could not do.**
Running it found two defects that reasoning had not. The first is the one above, and the
measurement is blunt: run three times against a live instance, the pre-fix probe printed
`PRODUCT SUBSTITUTION BROWSER CHECKS PASSED` every time, while the reload it claims to wait
for never happened at all.

The second defect explains why no reload happened. The probe created its two fixture
products with `location_id: 1`, and no instance this job boots has a location with that id.
`InitialDataSeeder`'s `DEFAULT_LOCATION_ID` is 2, and `migrations/8888.php` mints id 1 only
when `FEATURE_FLAG_STOCK_LOCATION_TRACKING` is off.

The API accepts the dangling id, so the fixture looks sound until the product form renders
it. The location `<select>` then carries no matching option and the form is invalid, so the
delete handler's `$('#save-product-button').click()` does nothing: no `PUT`, no
`window.location.reload()`. The probe now reads the location the way `group-min-stock.js`
and `open-container-measurement.js` do, `(await api('objects/locations'))[0].id`.

**Verification of both**, on 2026-09-22 against this working copy. The demo instance was
booted from the `localhost/victual:dev` image (PHP 8.5.10) against `postgres:16`, by the
commands the `frontend-security` job runs: `php bin/victual-migrate --quiet`, then `php -S`
on port 8085 over `public/` under `VICTUAL_MODE=demo`, then a `GET /` to generate the demo
data before the probe seeds through the API. The Playwright harness ran from the host.

`node product-substitutions.js http://127.0.0.1:8085` prints `PRODUCT SUBSTITUTION BROWSER
CHECKS PASSED`, three runs out of three. An instrumented copy of the same steps records what
the waiter now waits for: `DELETE /api/objects/product_substitutions/8` answering 204, `PUT
/api/objects/products/51` answering 204, then the load event. The waiter resolved 1283 ms
after the confirm click rather than immediately.

The probe stays out of the "Add" dialog's product picker, a bootstrap-combobox typeahead
nothing in this tree scripts. That is plan 30's retrospective applied: a speculative check
written without seeing the widget behave was added blind there, and CI found it wrong.

The edge is created through the API as fixture data instead. The probe asserts the table's
direction sentence and its link to the other product, from each endpoint's own page, and
that the delete-confirm-then-reload flow removes the edge. The create path is already fully
exercised at the API layer by the PostgreSQL suite's case 8.
