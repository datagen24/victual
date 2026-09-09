# 07. Deeply nested products

**Goal:** Support product hierarchies more than one level deep.
**Depends on:** nothing, but do [08 nested locations](08-nested-locations.md) first — same
pattern, far fewer call sites.
**Status:** draft for review, and **blocked on its own question 6** — which asks whether
the requirement is a taxonomy or a packaging relation, and whose recorded response says
that on the taxonomy reading this plan is mostly unnecessary and the change belongs in
[03](03-category-min-stock.md) as a nested `product_groups` column instead. Q6 decides
whether 07 is the largest item on the roadmap or one of the smallest, so it is answered
before any of this is scheduled, not during. Nothing below assumes that answer; Q1 and Q4
in particular are written against the taxonomy reading and are rewritten if Q6 lands the
other way.

## Today

`products.parent_product_id` exists and is used for the parent/sub product feature, but
exactly one level is supported, and that is enforced deliberately. From
`migrations/0130.sql`:

```sql
CREATE TRIGGER enfore_product_nesting_level BEFORE UPDATE ON products
BEGIN
	-- Currently only 1 level is supported
	SELECT CASE WHEN(( SELECT 1 FROM products p
		WHERE IFNULL(NEW.parent_product_id, '') != ''
			AND IFNULL(parent_product_id, '') = NEW.id ) NOTNULL)
	THEN RAISE(ABORT, 'Unsupported product nesting level detected ...') END;
```

(The typo in the trigger name is upstream's and is preserved in the PostgreSQL port.)

The resolution view is a flat `CASE`, not a hierarchy — `migrations/0106.sql`:

```sql
CREATE VIEW products_resolved AS
SELECT CASE WHEN p.parent_product_id IS NULL THEN p.id ELSE p.parent_product_id END
	AS parent_product_id, p.id AS sub_product_id
FROM products p WHERE p.active = 1;
```

So "the parent of X" is a single hop, and everything downstream inherits that assumption.

## The actual work

Making `products_resolved` recursive is the easy part and works identically on both
engines. The work is auditing everything built on the one-level assumption:

| Place | Assumption |
|---|---|
| `products_resolved` | one hop, `CASE` not recursion |
| `stock_current` | both UNION branches group by `parent_product_id` to aggregate sub product stock |
| `products_view.has_sub_products` | direct children only |
| `stock_missing_products` | `cumulate_min_stock_amount_of_sub_products` sums direct children |
| `products_current_substitutions` | picks a substitute among direct children |
| `enfore_product_nesting_level` | actively forbids depth > 1 |
| `enforce_min_stock_amount_for_cumulated_childs_INS/UPD` | cascades to direct children |
| `cascade_change_qu_id_stock` | rescales amounts for a product and its children |

Each needs a decision about what the operation *means* at depth: does a grandparent's
cumulated minimum include grandchildren? Does stock aggregate all the way up? My default
answer to both is yes — a tree where roll-ups stop after one level would be more confusing
than no tree at all — but that is a product decision, not a technical one.

## Proposed change

### Schema
No new columns. `parent_product_id` already carries the tree.

### Views
Replace `products_resolved` with a recursive CTE producing `(root_product_id,
sub_product_id, depth)` for every ancestor/descendant pair, plus each product paired with
itself at depth 0 (which is what today's `CASE` effectively does for roots). Keep the
existing column names so dependent views need minimal edits.

`WITH RECURSIVE` is supported by SQLite 3.8.3+ and PostgreSQL, so one definition serves
both — the same approach already used for `quantity_unit_conversions_resolved` and
`recipes_nestings_resolved`, both of which are ported and verified.

### Triggers
Replace `enfore_product_nesting_level` with a **cycle** check rather than a depth check:
walk ancestors from `NEW.parent_product_id` and reject if `NEW.id` appears. `recipes` has
exactly this in `prevent_infinite_nested_recipes_*`, which is ported and can be copied.

Optionally cap depth at something sane (5?) so a mis-click cannot create a pathological
tree — see Q3.

**The cap already exists.** [08](08-nested-locations.md) landed
`hierarchy_depth_limit()` in `migrations/0273.pgsql.sql` — an `IMMUTABLE` SQL function
returning 6, which is what question 3's shared constant asked for. This plan's guard calls
it rather than writing a second number, and 08's `check_location_parent` trigger is the
shape to copy: it refuses a cycle, and it compares the *parent's level plus one plus the
height of the moved row's own subtree* against the limit, so re-parenting a deep branch
under a deep node is caught rather than only adding a leaf.

### API
Additive: `products_resolved` gains a `depth` column. It is not in `ExposedEntity`, so no
public response changes. `products.parent_product_id` semantics widen but its shape does
not change.

**Client impact: no field changes, and a semantic change that is worse than one.** Every
client holding a one-level assumption about `parent_product_id` — that a parent has no
parent, that summing children is summing a leaf set — keeps compiling and starts being
wrong, with no shape difference to notice. Absent is not the same as none, and this is the
plan where the distinction has teeth: a manifest of paths and response keys, which is what
[17](17-ecosystem-clients.md)'s item 1 builds, cannot catch it either. It is caught by
reading each client's aggregation code, or not at all.

### UI
The product form's parent picker currently lists candidate parents; it must exclude the
product's own descendants, not just itself. Product lists that show a parent/child
indent will need to indent by depth.

## Verification

This is the change most likely to break something quietly. Before touching anything,
extend `.devtools/pgsql/` with a fixture that builds a three level tree and asserts on
`stock_current`, `stock_missing_products` and `products_current_substitutions` — so there
is a baseline of what one-level behaviour produces, and the deep-tree behaviour can be
compared against a deliberate expectation rather than against whatever falls out.

## Open questions

1. **Do roll-ups aggregate the whole subtree or only direct children?** I propose whole
   subtree for stock aggregation and for `cumulate_min_stock_amount_of_sub_products`.

   > **Response:** Whole subtree — a partial tree is worse than no tree.
2. **Can a product with its own children also be a child?** Required for depth > 2. Any
   reason to forbid mixed nodes?

   > **Response:** None — they must be allowed (depth > 2 requires them). The
   > fixtures should include a middle node that itself holds stock, because that is
   > where aggregation is most likely to double-count.
3. **Cap the depth?** A cycle check is required for correctness; a depth cap is only
   guard-rail. I lean toward a cap of 5, configurable, mainly so a bad import cannot
   produce a 200 deep chain that makes every view slow.

   > **Response:** Yes, cap ~5 — and prefer a plain hard-coded sane cap over a
   > config knob if reaching config from the trigger is awkward: dual-engine
   > trigger-pair simplicity wins. The cycle check is the correctness item; copy the
   > recipe guards as planned.
4. **Substitution at depth.** When a grandparent is out of stock, should
   `products_current_substitutions` reach past direct children into grandchildren?

   > **Response:** Whole subtree, ordered by depth (nearest first).
   > Direct-children-only would be the one roll-up that stops early, violating Q1's own answer.
5. **Is there a real use case beyond two levels** for you specifically? If the answer is
   "brand > variant > size", that is three, and worth designing for concretely rather
   than in the abstract.

   > **Response:** Answered — the shape is `MajorClass / SubClass / Item / Variant`,
   > three levels normally and four where a variant matters:
   >
   > ```
   > Dairy / Milk   / Whole
   > Dairy / Creme  / Heavy
   > Dairy / Cheese / Cheddar / Sharp
   > ```
   >
   > So: **four levels, not arbitrary recursion** — the same depth as 08's locations
   > tree. The recursive CTE is still the right implementation — a fixed four-level
   > join would be worse to read and no faster — but the plan is sized for a bounded,
   > shallow tree, and the depth cap in Q3 is a real bound rather than a formality.
   >
   > It also raises something larger than depth, which is now question 6.

6. **Is this a classification, or is it what `parent_product_id` means?** The tree in
   Q5 is a taxonomy: `Dairy` is a kind-of relation, not a packaging relation. Only the
   leaves are things you buy, hold and consume; `Dairy` and `Dairy/Milk` are labels.
   Upstream, `parent_product_id` means the opposite — a parent and its children are the
   *same product in different packagings*, which is precisely why stock rolls up to the
   parent and why siblings substitute for one another. Putting a taxonomy into that
   column reuses the mechanism for something it was not built for, and three of the
   answers already recorded above change on the real data:

   - **Q1's whole-subtree roll-up collides with quantity units.** `stock_current`
     aggregates in the parent's `qu_id_stock`. `Dairy/Cheese` can plausibly total in
     grams. `Dairy` cannot total milk in litres, cream in millilitres and cheese in
     grams — there is no unit for the parent to aggregate into. Either intermediate
     nodes carry a real stock unit and roll-up stops where the units stop agreeing,
     or roll-up is display-only and never enters `stock_current`. This needs deciding
     before the recursive view is written — it is the difference between changing
     `/stock`'s row set and not touching it. Note this also settles the Review note
     below: if roll-up stays out of `stock_current`, the Home Assistant consumer sees
     nothing new.
   - **Q4's whole-subtree substitution is wrong here.** Nearest-first correctly makes
     `Sharp` a substitute for `Cheddar`. It also makes `Heavy` cream a substitute for
     `Whole` milk, because both sit under `Dairy`. That is not a substitution anyone
     wants offered. Either substitution is capped at a small relative depth (1, i.e.
     today's behaviour), or it is opt-in per product, or the taxonomy does not live
     in `parent_product_id` at all.
   - **Q2's mixed middle node is now hypothetical rather than typical.** Nothing in
     the real tree stocks an intermediate node. The fixture should still cover it — a
     three-level tree with a stocked middle node is the double-counting case — but it
     is a robustness fixture, not a model of the real catalogue.

   Neither of the first two is a flaw in the recursive mechanics; both say the
   mechanics are being pointed at the wrong relation.

   > **Response:** Settle this before any of 07 starts — it decides whether 07 is the
   > largest item on the roadmap or one of the smallest, and it cannot be answered
   > from the code.
   >
   > If the requirement is purely taxonomy — browse and report by class, group the
   > shopping list by aisle — then **nesting `product_groups` is the right change and
   > this plan is mostly unnecessary**. That is [03](03-category-min-stock.md)'s
   > territory, one nullable parent column on a lookup table, and it costs none of
   > what 07 costs: no stock aggregation, no substitution semantics, no
   > `cascade_change_qu_id_stock`, none of the one-level audit at the top of this
   > plan.
   >
   > `parent_product_id` earns its cost only if the real requirement is that
   > `Dairy/Cheese/Cheddar/Sharp` and a plain `Cheddar` **share stock** — one pool
   > consumed and purchased through either name. That is a packaging relation, and it
   > is the only thing the existing column is built to express.
   >
   > The two are not exclusive. The likely honest answer is nested `product_groups`
   > for the taxonomy, and `parent_product_id` left at its current depth for the few
   > genuine same-product-different-packaging cases. If that is where it lands, 07
   > shrinks to whatever the packaging cases actually need, and Q1 and Q4 above are
   > rewritten against that narrower relation rather than against the taxonomy.
   >
   > The locations tree in [08](08-nested-locations.md) has no equivalent problem —
   > containment is exactly what `parent_location_id` would mean — which is one more
   > reason 08 goes first.

## Review notes

- Middle-node semantics leak into the API: `stock_current` gaining aggregated rows for
  intermediate parents changes what `/stock` returns for consumers like the Home
  Assistant integration — technically additive (new rows, same shape), but it should
  be a deliberate decision in this plan's API section, not a side effect.

## Effort

**Conditional on Q6, and the two branches are not the same order of magnitude.**

On the packaging reading, large — the largest item on the roadmap. The recursive view is
an afternoon; the audit, the decisions above and the regression fixtures are the rest.
Worth doing after 08 has established the pattern.

On the taxonomy reading, most of that cost belongs to a nullable parent column on
`product_groups` in [03](03-category-min-stock.md), and what is left here is only the
genuine same-product-different-packaging cases — small, and possibly nothing at all. The
roadmap's wave 4 is written around the first branch and says so.
