# 31. Directed substitution between products

**Goal:** Whole beans stand in for ground coffee and never the reverse. A block of cheddar
stands in for shredded; unsalted butter stands in for salted. The system can say so, for
products that share no parent.
**Depends on:** [ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md),
**accepted 2026-09-14**, decision 4.
**Interacts with:** [30](30-nested-product-groups.md), which groups the products this
relates; [14](14-contract-and-regression-scaffolding.md) piece 2, which freezes the response
contract this adds to.
**Status:** draft for review, **scheduled into wave 4 2026-09-14**, after
[30](30-nested-product-groups.md). Tracked as
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
