# 31. Directed substitution between products

**Goal:** Whole beans stand in for ground coffee and never the reverse. A block of cheddar
stands in for shredded; unsalted butter stands in for salted. The system can say so, for
products that share no parent.
**Depends on:** [ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md),
**accepted 2026-09-14**, decision 4.
**Interacts with:** [30](30-nested-product-groups.md), which groups the products this
relates; [14](14-contract-and-regression-scaffolding.md) piece 2, which freezes the response
contract this adds to.
**Status:** **Landed 2026-09-15**, after [30](30-nested-product-groups.md). Tracked as
[issue 125](https://github.com/datagen24/victual/issues/125). Migration **0279**, renumbered
from 0278 to make room for [issue 148](https://github.com/datagen24/victual/issues/148)'s
migration ahead of 30's (see [RESERVATIONS.md](../../migrations/RESERVATIONS.md)).

## Why this exists

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
under [ADR-0005](../adr/0005-wire-contract-is-the-invariant.md).

**This wants to land before [14](14-contract-and-regression-scaffolding.md) piece 2**
([issue 83](https://github.com/datagen24/victual/issues/83)) freezes the response contract in
wave 5, for the same reason [28](28-open-container-measurement.md) does.

The UI is a product-form section for the edges, and a suggestion where a product is wanted and
absent — on the consume screen and on the shopping list. Per the one-tap requirement recorded
in [29](29-working-container-replenishment.md), accepting a suggestion is one action, not a
navigation.

### Migration

One PostgreSQL-only migration, claimed in [RESERVATIONS.md](../../migrations/RESERVATIONS.md).

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
   than absent, and [ADR-0022](../adr/0022-open-containers-carry-a-measured-remainder.md)
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

**Q1 (quantity factor): no.** The plan named its own precedent for this
(ADR-0022 decision 3: refuse rather than approximate a conversion that is right sometimes,
never a factor that is right sometimes) — a factor that holds for coffee (1:1 by weight) and
not for herbs (a tablespoon of fresh is a teaspoon of dried) is worse than no factor at all. An
edge is the bare ordered pair.

**Q2 (transitivity): no, not in this migration.** A recursive closure is cheap here in the
sense the plan means — the pattern is already load-bearing three times over
(`quantity_unit_conversions_resolved`, `locations_resolved`, `product_groups_resolved`) — but
those three are trees, and this is an arbitrary directed graph: `A → B` and `B → A` can both be
true here without contradiction, which a tree's parent pointers cannot express, so a
transitive closure over it needs cycle protection none of the three precedents had to build.
Nothing in the verification list requires the closure itself, only that the chain case is
*asserted* whichever way it lands — `product-substitutions-tests.php` case 6 does that: whole
spice substitutes for cracked, cracked for ground, and ground spice's candidates carry cracked
but not whole.

**Q3 (counts toward minimum stock): no, unchanged from today.** `stock_missing_products`
(`db/pgsql/baseline/05_views_l2.sql`) never joins `products_current_substitutions`, so a sub
product in stock already does not satisfy its parent's `min_stock_amount` — a directed edge
that did so would be a new, inconsistent exception rather than a preserved behaviour. Left for
a follow-on together with plan 30's own questions 1–3, which would need the same view touched.

**Q4 (recipe fulfilment): no, and not by editing `products_current_substitutions`.** This
turned out to be the load-bearing finding of the whole plan, and it was not visible from the
plan document alone: `products_current_substitutions` is SQLite-line and differential-tested —
`.devtools/pgsql/run-tests.sh views` seeds both engines from the same fixture and compares
`products_current_substitutions`'s output row for row (`.devtools/pgsql/view-tests/02_products_and_pricing.sql`'s
own `@views` header names it). `product_substitutions` is PostgreSQL-only, so folding it into
that view — which the plan's own wording invited ("extend or supersede
`products_current_substitutions`") — would have made the two engines' definitions diverge for
a feature only one of them can run: exactly what AGENTS.md's "do not delete SQLite behaviour
the suite compares against" is guarding against, even though nothing here deletes anything.
`product_substitutions_resolved` is therefore a wholly new, additive view rather than a change
to the existing one, confirmed by running `run-tests.sh views` unchanged (still
"`products_current_substitutions (1 rows identical)`") and `run-tests.sh triggers` unchanged
after the cascade-delete addition. `recipes_pos_resolved` keeps using only the parent/child
mechanism; a later plan decides whether the response contract should carry the distinction
into recipe fulfilment.

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
parent_product_id` (`direction = 'shared_parent'`) are combined with `UNION` (not `UNION ALL`
— the two sources cannot produce the same `(from, to)` pair, since one requires a shared parent
and the other forbids a self-edge, but `UNION`'s dedup is free insurance and costs nothing a
plain edge table pays for). Stock is read through `products_resolved`/`stock_current` rather
than `stock_next_use`, deliberately: `stock_next_use` carries one row per physical stock entry,
so joining it straight to `from_product_id` would have multiplied a candidate row per stock
entry the way `products_current_substitutions` avoids only by ending in a single-row `LIMIT
1`. `stock_current` is already the per-product rollup keyed by the normalized top-level id, so
a candidate that is itself a sub product is normalized to its own rollup the same way the
existing mechanism already treats it, and the view stays one row per edge.

**API.** `product_substitutions` (writable) and `product_substitutions_resolved` (read-only)
in the three `ExposedEntity*` enums and `EntityReadPolicy::PERMISSIONS`
(`PERMISSION_STOCK_VIEW`, matching `product_groups`/`product_groups_resolved`). Neither needed
an entry in `GenericEntityApiController`'s per-entity branches: the self-edge/duplicate-pair
guards are plain `CHECK`/`UNIQUE` constraints, not a `BEFORE` trigger with a custom `RAISE`, so
the existing generic `PDOException` → 400 path (the same one every other constraint violation
in this schema already goes through) applies with no bespoke message, and there is no delete
guard to translate because deleting an edge cascades nothing and blocks nothing. No `oneOf`
entry in `victual.openapi.json`'s `/objects/{entity}` paths, matching `product_groups` and
`quantity_unit_conversions` precedent — the generic path does not require one.
`ProductSubstitutionResolved` is documented purely for readability, the same as
`ProductGroupResolved`. `GetProductDetails()` gains `substitution_candidates`, ordered by
`from_product_amount_in_stock` descending then `from_product_best_before_date` ascending — in
stock first, soonest to expire among those — with no cross-source priority between the two
`direction` values, since the plan's own ordering question was about nearness against the
default consume rule and never named one substitution source as preferred over the other.

**UI.** A "Substitutions" section on the product edit form (`views/productform.blade.php`),
built on the barcodes section's exact pattern: a `DataTable` listing edges naming this product
on either side, worded per direction, linked to the other product by name, and an "Add" button
opening `views/productsubstitutionform.blade.php` as an embedded dialog
(`views/components/productpicker` for the other product, a direction radio, translated to
`from_product_id`/`to_product_id` in `public/viewjs/productsubstitutionform.js` before the
`POST`/`PUT`, the same way the barcode form remaps `display_amount` to `amount`). The
consume-screen and shopping-list suggestion surfaces the plan also asks for are **not built**:
they are a materially different piece of work (surfacing a candidate at the moment a wanted
product is absent, one-tap per plan 29's own requirement) from managing the edges themselves,
and nothing in the verification list depends on them existing yet — left for a follow-on the
way plan 30 left its own questions 1–3.

**Verification**, against real PostgreSQL 16.13 (`postgres:16` at the OS package level, on
2026-09-15): `php .devtools/pgsql/check-migrations.php` reports `MIGRATION NUMBERING OK` with
no waiver. The new `.devtools/pgsql/product-substitutions-tests.php`
(`run-tests.sh substitutions`) passes all 26 assertions on its first run: direction both ways
on two independent pairs, the self-edge and duplicate-pair guards by exact constraint name, a
product with no edges, the parent/child control unchanged, the chain case (Q2), cascade
delete from either side, the API create/read/delete round trip and the resolved view's refusal
of every write verb, the read-policy gate, and `GetProductDetails()`'s ordering. `run-tests.sh
migrate` required `product_substitutions` added to `.devtools/pgsql/migratedifftest.php`'s
`ENGINE_EXCLUSIVE_TABLES` (the same mechanism `product_location_min_stock` and
`storage_classes` already use) before it passed; `run-tests.sh views` and `run-tests.sh
triggers` needed no changes and pass unchanged, which is itself the proof that
`products_current_substitutions` and the differential harness are untouched. `run-tests.sh
all` is clean end to end, the `GetProductDetails()` SQLite fix included. PHP lint (`php -l`) is
clean on every changed file, `victual.openapi.json` parses as valid JSON, the workflow YAML
parses, and `php .devtools/check-cited-jobs.php` reports every cited job exists.

**The browser probe (`.devtools/frontend/product-substitutions.js`) could not be run end to
end in this session**, for the same reason plan 30's could not: this sandbox's PHP is 8.4.19
and the app refuses to boot below 8.5.0 on every route — confirmed by reproduction (booting the
demo instance under PHP 8.4 returns HTTP 200 with the literal refusal text), not assumed. Wired
into the `frontend-security` job (`.github/workflows/tests.yml`) after plan 30's own probe,
where it will run for real the way plan 30's did. Learning from that session's own retrospective
— a speculative check written without a way to see a widget's actual behaviour was added blind
and had to be removed after CI found it wrong — this probe deliberately does not drive the
"Add" dialog's product picker (a bootstrap-combobox typeahead nothing in this tree yet
scripts), which no probe here has exercised and which this sandbox cannot be used to learn
first. The edge itself is created through the API as fixture data instead, and the probe
asserts only what it can be confident about without seeing the app run: the table renders the
right direction sentence and a link to the other product from each endpoint's own page, and
the delete-confirm-then-reload flow (proven to work in real CI by plan 30's own case 6) removes
it. The create path is already fully exercised at the API layer by the PostgreSQL suite's case
8.
