# ADR-0023: Taxonomy lives in product groups; `parent_product_id` means packaging and stays one level deep

- **Status: Accepted, 2026-09-14.** **The taxonomy lives in nested `product_groups`;
  `parent_product_id` keeps its upstream packaging meaning and its existing one-level
  depth.** All four acceptance prerequisites below are met, each annotated in place with
  what met it and how to reproduce it. **Nothing in the decision was revised by this
  acceptance.**
- **Decider:** datagen24 (maintainer). Acceptance follows the [ADR lifecycle](README.md).
- **Recorded:** 2026-09-13.
- **Answers** [plan 07 question 6](https://github.com/datagen24/victual/issues/82), which
  blocked wave 4's product half.
- **Referenced by:** [30 — Nested product groups](../plans/landed/30-nested-product-groups.md) and
  [31 — Directed substitution](../plans/landed/31-directed-substitution.md), which own the work;
  [07 — Deeply nested products](../plans/retired/07-nested-products.md), which is retired **after**
  this record is accepted, by a separate pull request and never by the acceptance itself —
  that plan stays blocked meanwhile;
  [03 — Category minimum stock](../plans/landed/03-category-min-stock.md), whose table gains the
  parent column.

## Context

`products.parent_product_id` exists and supports exactly one level, enforced by the
`enfore_product_nesting_level` trigger from `migrations/0130.sql`. Upstream it means *the
same product in different packagings*: stock rolls up to the parent and siblings substitute
for one another. [Plan 07](../plans/retired/07-nested-products.md) proposed making that recursive.

Its question 6 asked whether the requirement is that relation at all, or a taxonomy — a
kind-of tree whose interior nodes are labels rather than things you buy. The answer could
not come from the code, only from the real catalogue, and the two branches were not the same
order of magnitude: on the packaging reading plan 07 was the largest item on the roadmap; on
the taxonomy reading it was mostly unnecessary.

**The catalogue was sampled deliberately, on 2026-09-13.** Thirteen candidate pairs drawn
from this household's kitchen were each classified as sharing a stock pool, being separate
products, or being a quantity multiple of one another. The maintainer and a second member of
the household answered together. Seven of the thirteen were separate products; one was a
quantity multiple; one was not a product question at all. The worked taxonomy that settled
the remaining ambiguity was a spice tree supplied afterwards, reaching three group levels
with a node holding a product and a subgroup at once.

Two findings from that exercise constrain the decision rather than merely informing it.

**Interior nodes of the real tree are never stocked.** `Spices`, `Garlic` and `Garlic/Fresh`
are labels. Nothing is bought or consumed at those names. A relation whose defining property
is that stock rolls up to the parent has nothing to roll up.

**The forms under a leaf are usually separate products, not a pool.** Whole beans and ground
coffee, a block and a bag of shredded cheddar, whole and crushed garlic, salted and unsalted
butter, tomato paste and tomato sauce — in each pair a recipe calling for one is not
satisfied by the other. What relates them is a *one-way* usability: beans can be ground,
grounds cannot be un-ground; a block can be grated; unsalted butter can be salted. That is a
directed relation between separate products, which is not what sibling substitution under a
shared parent expresses.

## Decision (proposed)

### 1. The taxonomy is nested product groups

`product_groups` gains a nullable `parent_product_group_id`. The tree of kinds — `Spices /
Garlic / Fresh`, `Dairy / Cheese`, `Drinks / Soda / Coca-Cola` — lives there. Groups hold no
stock and carry no quantity unit, so nothing aggregates across them and no unit has to agree.

This is [plan 03](../plans/landed/03-category-min-stock.md)'s table, and the column is additive to
what that plan shipped. [Plan 30](../plans/landed/30-nested-product-groups.md) owns the work.

### 2. `parent_product_id` keeps its upstream meaning and its existing depth

It means the same product in different packagings. The one-level limit stays; the
`enfore_product_nesting_level` trigger is not relaxed, `products_resolved` does not become
recursive, and no depth cap is introduced for products.

Its surviving use is container sizes of one SKU family — an 8 oz can, a 12 oz can and a 2 L
bottle of one soda under a parent that supplies the combined total, which
[plan 28](../plans/landed/28-open-container-measurement.md) records as forced by per-unit labelling
rather than chosen.

### 3. A relation that is a taxonomy does not go in `parent_product_id`

Stated separately from decision 2 because it is the constraint on future work. Putting a
kind-of tree in that column points a stock-aggregating, substitution-bearing mechanism at
something that neither aggregates nor substitutes. Two specific harms, both silent:

- `stock_current` converts a child's stock unit to its parent's through
  `COALESCE(qucr.factor, 1.0)` (`db/pgsql/baseline/04_views_l1a.sql`). A parent whose
  children have no common unit does not error — it sums litres and grams as though the
  factor were 1.
- `products_current_substitutions` offers any sibling under a parent. Under a taxonomy that
  makes heavy cream a substitute for whole milk.

### 4. Substitution becomes a directed relation between products, not a property of parentage

Today substitution is a view over the parent/child relation: when a parent is out of stock,
one of its children is used. With the forms above modelled as separate products, there is no
shared parent, so no substitution is expressible at all.

The relation is therefore its own directed edge between arbitrary products — beans substitute
for grounds, not the reverse — and the shared-parent case becomes one source of edges rather
than the definition. [Plan 31](../plans/landed/31-directed-substitution.md) owns it.

### 5. A UPC identifies a product; it does not define one

Several barcodes may resolve to one product. Whether container sizes collapse into a single
product or become one product each is decided by whether they are counted separately on the
shelf, not by whether they carry distinct UPCs:

- Anything per-unit labelled needs a stock unit of pieces, so each container size is its own
  product — the soda case.
- Anything not labelled per unit is one product in a weight or volume unit with several
  barcodes carrying `qu_id` and `amount` — the flour case
  ([plan 29](../plans/landed/29-working-container-replenishment.md)).

A deli counter sticker is not a UPC at all; it is a scale barcode with the weight embedded,
so it cannot mint a product in principle.

### 6. A group may hold products and subgroups at once

`Garlic` holds the product `Dried` and the subgroup `Fresh` together. Products attach to a
group by `product_group_id` and groups to a parent by `parent_product_group_id`, so the two
memberships are independent and a node carrying both needs no special case.

This is the mixed-node problem from plan 07's question 2 dissolving rather than being solved.
A group holds no stock, so a node that is both a container of things and a thing itself costs
nothing; the same shape in `parent_product_id` is where double counting would have to be
reasoned about.

## Consequences

**Plan 07 is retired rather than shrunk — after acceptance, in its own pull request.** The recursive `products_resolved`, the audit of
the eight sites built on the one-level assumption, the whole-subtree roll-up, the depth cap
and the mixed-node fixture were all conditional on the packaging reading. None of them is
required. Its questions 1 and 4 were written against the taxonomy reading and are answered by
this record rather than rewritten.

**The depth function gets its second consumer, not the one it was built for.**
`hierarchy_depth_limit()` was written generic in `migrations/0273.pgsql.sql` on the
expectation that plan 07 would share it. Plan 30 shares it instead, for group nesting. The
observed tree reaches three group levels against a limit of six nodes.

**`product_groups.name` is globally `UNIQUE` and has to stop being.** Nesting makes the rule
`UNIQUE(parent_product_group_id, name) NULLS NOT DISTINCT`, exactly the change
[plan 08](../plans/landed/08-nested-locations.md) made to `locations` and for the same reason. Plan
30 inherits that plan's finding that `NULLS NOT DISTINCT` requires PostgreSQL 15.

**The catalogue gets wider and shallower.** Seven of thirteen sampled pairs are separate
products, so there are more product rows than a pooling model would produce. Plan 31's
directed substitution is what keeps that from being a usability cost; without it, a
Split-heavy catalogue loses relationships it can express today.

**Nothing here is built, and nothing here is yet in force.** No column, no view, no
substitution table. This record constrains work rather than describing code, the two plans it
names are drafts, and while its status is Proposed it authorises none of them.

**The lifecycle is three steps, not two, and the middle one changes nothing but status.**
Under this repository's [ADR lifecycle](README.md) an acceptance pull request carries
bookkeeping only — a status line, an index row, supersession pointers. It does not retire a
plan, schedule a plan, or edit one. Applying what this record decides is separate work in a
separate pull request:

| Step | Pull request | What it changes |
|---|---|---|
| 1 | this one | the record exists, Proposed; plan statuses untouched |
| 2 | acceptance | `Proposed` → `Accepted`, the index row, supersession pointers. Nothing else. |
| 3 | application | plan 07 retired; plans 30 and 31 become its replacements |

So retirement happens **after** acceptance rather than as part of it. Anything in this record
phrased as a consequence — plan 07 retired, 30 and 31 owning the work — describes step 3, and
a reader who finds it written as though acceptance performed it should read it as this table
says instead.

## Options considered

**Make `parent_product_id` recursive, as plan 07 proposed.** Rejected on the evidence in
*Context*: the interior nodes of the real tree hold no stock, so the mechanism's defining
behaviour has nothing to act on, and both of decision 3's harms are silent rather than loud.

**Nest groups and also deepen `parent_product_id`.** Rejected as paying plan 07's cost for a
case the sampling did not find. If genuine three-level packaging appears later, this record
is superseded by one that says so.

**Add a direction flag to the existing substitution view.** Rejected because it does not
reach: with the forms modelled as separate products there is no parent/child relation for a
flag to qualify.

**Treat each UPC as a product.** Rejected as contradicting a decision already recorded for
flour, and as answering a question about *counting* with a fact about *labelling*.

## Acceptance prerequisites

**All four are met.**

1. **The sampled classification recorded in plan 07**, with the answer and its date, so the
   evidence this record rests on is inspectable rather than asserted.

   **Met 2026-09-14.** [Plan 07 question 6](../plans/retired/07-nested-products.md)'s response block
   carries the thirteen-pair classification, the date it was run (2026-09-13) and the method
   (the maintainer and a second household member, together, against real kitchen items).

2. **A worked example of the mixed node** — a group holding a product and a subgroup — shown
   against the proposed schema, since decision 6 claims it needs no special case.
3. **The name-uniqueness change demonstrated**, including two same-named groups under
   different parents and the refusal of two under the same parent, against PostgreSQL 15 or
   later.

   **Both met 2026-09-14**, by a disposable spike on the branch
   `claude/sonnet5_adr0023-prerequisites` at `4da3d35df9a2add018349865a7c7994ad8667465`, run
   against PostgreSQL 16.15 (`postgres:16`) on the maintainer's Apple silicon machine via
   podman. **`.spike-adr23/` is a path in that commit, not in a checkout of `master`, where it
   has never existed and never will** — this is preview work for
   [migration 0277](../../migrations/RESERVATIONS.md), which stays unwritten until
   [plan 30](../plans/landed/30-nested-product-groups.md) is scheduled. Read a file with
   `git show 4da3d35d:.spike-adr23/<path>`, or check the branch out into a worktree to run
   it; `.spike-adr23/RESULTS.md` at that SHA has the full transcript.

   The spike applies `ALTER TABLE product_groups ADD COLUMN parent_product_group_id
   INTEGER`, replaces the plain `UNIQUE` on `name` with `UNIQUE NULLS NOT DISTINCT
   (parent_product_group_id, name)`, and adds a `product_groups_resolved` view copied from
   `locations_resolved`'s shape (`migrations/0273.pgsql.sql`) — no cycle, depth or delete
   guard, since those are not what these two prerequisites test and 0277 copies them from
   0273 directly per plan 30.

   **Prerequisite 2.** The worked spice tree from plan 30 is seeded, with `Garlic` filed as
   both the `parent_product_group_id` of subgroup `Fresh` and the `product_group_id` of
   product `Dried (Garlic)` in the same row, no special case anywhere in the schema or the
   query. `product_groups_resolved` reaches `Spices / Garlic / Fresh` at depth 2, and `Fresh`
   still resolves its own products (`Whole`, `Crushed`) independently of what its parent
   holds.

   **Prerequisite 3.** Three cases against the new constraint: two groups named `Dried`
   under different parents (`Parsley`, `Garlic`) both insert cleanly; a second `Dried` under
   the same parent (`Parsley` again) is refused
   (`duplicate key value violates unique constraint "product_groups_parent_name_key"`); and a
   second root group named `Spices` (`parent_product_group_id IS NULL`) is refused the same
   way — the case a plain `UNIQUE` would miss, since PostgreSQL treats every `NULL` as
   distinct from every other by default and `NULLS NOT DISTINCT` is exactly the clause that
   closes that gap. Confirms plan 30's citation of PostgreSQL 15 as this fork's floor: the
   clause does not parse on 14 or earlier.

4. **A statement of what happens to existing `parent_product_id` rows.** The column is in use
   today; this record does not change its meaning, and the accepting pull request should say
   whether any current row contradicts decision 2.

   **Met 2026-09-14.** This fork has no production catalogue of its own yet, so the
   inspection is of the maintainer's most recent pre-fork upstream Grocy backup — SQLite,
   `grocy_backup/a0d7b954_grocy/data/grocy/grocy.db`, 257 migrations applied, 66 products —
   which is the closest thing to "the live catalogue" that currently exists and is real
   multi-year household usage rather than demo data.

   **22 of 66 products (33%) carry `parent_product_id`.** Every one of the 22 is a taxonomy
   label, not packaging: seven cuts (`Beef Roast`, `Beef Steak`, `Ground Beef`, `Whole Packer
   Brisket`, `Smoked Pastrami`, `Corned Beef`, `Texas Brisket`) under `Beef`; `Cheese`,
   `Cream`, `Milk` under `Dairy`; `Beef` itself and eight more (`Butcher Sausage`, `Chicken
   Breast`, `Chicken Thigh`, `Ground Sausage`, `Pork Chop`, `Pork Loin`, `Pork Ribs`, `Protein
   Supplement`) under `Protein`; two filament brands under `PETG Filament`; one part under
   `AnkerMake M5 Parts`. None is a container-size variant of the same purchasable thing — a
   second, independent catalogue landing on the same reading as the sampling this record's
   Context section describes.

   **One chain contradicts decision 2 directly: `Protein` → `Beef` → its seven cuts is two
   levels, not one.** `Beef` (id 4) has `parent_product_id = 3` (`Protein`) while itself being
   the `parent_product_id` of seven other rows — a genuine violation of "stays one level
   deep," sitting in real data today.

   **Why the one-level trigger didn't catch it.** `enfore_product_nesting_level`
   (`migrations/0121.sql`, carried through 0155, 0207 and 0254) and its PostgreSQL port
   `trg_enfore_product_nesting_level`
   (`db/pgsql/baseline/06_triggers_a.sql:760-776`) are both declared `BEFORE UPDATE`, never
   `BEFORE INSERT`, in every version either engine has shipped — this is upstream's original
   design, faithfully preserved by the port. A two-level chain built by inserting `Beef Roast`
   with `parent_product_id` already pointing at a product that itself has a parent is never
   checked by either engine; only a later `UPDATE` of one of the rows involved would trigger
   the guard. `migrations/0130.sql` once cleared exactly this class of row for existing data,
   but nothing stops a fresh insert from recreating it, which this backup shows happened.

   **This does not change what decision 2 says**, and it does not touch anything this record
   builds — no column, no trigger edit is part of accepting it. It does mean this fork
   inherits a live enforcement gap: `parent_product_id`'s "stays one level deep" is a rule the
   schema does not actually enforce on the write path most likely to create violations. That
   is a defect independent of this ADR, tracked as
   [issue 148](https://github.com/datagen24/victual/issues/148) rather than fixed by this
   bookkeeping pull request.

## Open questions

**None of the three below is answered by this acceptance.** Questions 1-3 carry no responses
here; accepting the record above them settles the taxonomy/packaging split, not these. Question
1 is restated as plan 30's own Q1 and is answered there, not here — it stays open past this
acceptance rather than being implicitly closed by it.

1. **Does a group minimum roll up to descendant groups?**
   [Plan 03](../plans/landed/03-category-min-stock.md) shipped `product_groups.min_stock_amount`
   against a flat table. Whether a parent group's minimum covers products in its children is
   undecided, and plan 03's Q1 note-only shopping list follow-up interacts with it.
2. **Is the one-level trigger left as it is, or made explicit?**
   `enfore_product_nesting_level` enforces decision 2 today by accident of upstream design
   rather than by intent. Leaving it is free; restating it as this fork's own rule costs a
   migration and gains a comment that says why.
3. **Do the five entity types that print labels interact with group nesting?** Product group
   is not currently a label target, and nothing here makes it one.
