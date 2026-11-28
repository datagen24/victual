# ADR-0059: Properties are separate records above location roots

- **Status:** **Proposed.** Nothing here constrains work until the maintainer accepts it in a
  separate pull request.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-11-28, against `master` at `ce24e247`. Design only: it changes no code,
  reserves no migration number and amends no accepted record.
- **Referenced by:** [plan 38](../plans/38-household-inventory.md) question 11,
  [plan 45](../plans/45-lending-and-custody.md).
- **Builds on:** [plan 08](../plans/landed/08-nested-locations.md)'s location tree
  (migration 0273), [ADR-0005](0005-wire-contract-is-the-invariant.md),
  [ADR-0011](0011-label-namespace.md) and [ADR-0029](0029-stock-locations-reference-existing-locations.md).

## Context

[Plan 38](../plans/38-household-inventory.md), household inventory and the equipment
rebuild, adds houses and storage units above the existing location tree. Plan 38 calls
these premises properties. A household can have several properties, and a storage unit
need not belong to a house (plan 38 question 1). Question 11 asks how a property is stored,
how existing locations are assigned to one, and how an occupied location moves between
properties.

The location tree as built constrains the answer:

- `locations.parent_location_id` has no foreign key. `locations_resolved` walks the tree
  with a cycle guard and stops at `hierarchy_depth_limit()`, which is 6 nodes. The layout
  plan 08 was confirmed against, Floor / Room / SubSpace / Shelf, uses 4.
- `UNIQUE NULLS NOT DISTINCT (parent_location_id, name)` makes root names unique across the
  installation, because every root has the same NULL parent.
- `locations_resolved.path` is shown in pickers and the locations list. It is also printed
  on location labels, per plan 06 question 5.
- Locations are stock master data, readable with `STOCK_VIEW`, which the `GUEST` role holds.
  They carry stock-only fields: `storage_class_id`, `is_freezer`, `tare_weight`, and plan
  29's per-location minimums. Stock rows reference a location under ADR-0029.
- A property has fields that are not stock data: address, unit identifier, rental agreement,
  assessment history and mortgage documents. Plan 38 requires that the property contain the
  location hierarchy without exposing private documents through stock reads.
- Plan 38 forbids inferring a property from existing location names.

## Decision

1. **A property is its own record.** A `properties` table holds the name, a type (house or
   storage unit, extensible), and optional details. A property is not a location row.
2. **Only root locations carry a property.** `locations.property_id` is nullable. A trigger
   refuses a non-NULL `property_id` on a location with a parent, and clears nothing
   silently. A descendant's property is its root's, so a descendant cannot belong to a
   different property from its parent.
3. **`locations_resolved` exposes the derived property.** The view gains a `property_id`
   column taken from each descendant's root. The column is additive under ADR-0005; the
   OpenAPI document and the response-contract snapshot are regenerated in the same change.
   Existing `path` strings do not change.
4. **Root names are unique within a property.** The unique key becomes
   `UNIQUE NULLS NOT DISTINCT (property_id, parent_location_id, name)`. Descendants keep
   today's rule because their `property_id` is NULL. Two properties can each have a root
   named "Garage". Unassigned roots stay unique among themselves.
5. **Existing roots are unassigned after the upgrade.** The migration creates no property
   and assigns no root. An unassigned root behaves exactly as a root does today. The release
   notes and an in-application notice say that properties can now be created and roots
   assigned to them; the UI offers a bulk assignment.
6. **A property's identity is visible to stock readers.** A caller who may read
   `locations_resolved` sees each location's `property_id`, and may read the property's name
   and type. The property's other details, its documents and its financial records need a
   property read permission that `STOCK_VIEW` does not imply.
7. **Changing a location's property is an explicit move.** The generic
   `PUT /objects/locations` path refuses a write that would change a location's effective
   property: assigning a root that is already assigned elsewhere, or reparenting a location
   under a tree in another property. A dedicated move operation performs the change. It
   records a dated property-change entry for the location, requires the caller's rights on
   both properties, and leaves stock rows and the stock journal unchanged. Assigning an
   unassigned root to a property for the first time is not a move and may use the generic
   path.
8. **An asset may be placed in a property without a location.** Plan 38 requires direct
   placement in a storage unit without a fictitious room. An asset's placement is a property
   with an optional location; when a location is given, its property must match. Stock
   continues to require a location, so stock held directly in a storage unit needs a root
   location in that property.

## Consequences

- No existing path, label or stock reference changes on upgrade. Printed location labels
  stay correct.
- The depth limit is unaffected. A typed root would have used one of its 6 levels.
- Property details live in their own table behind their own permission, so they never reach
  a `GUEST` through stock reads.
- Readers who need "everything in property X" join `locations_resolved` on `property_id`
  rather than walking to a root themselves.
- A second check is needed on every location write: the property trigger beside the existing
  cycle and depth guard. It shares that guard's advisory lock, because reparenting changes
  both the tree shape and the effective property.
- Assets add a second kind of occupancy. The move operation, and any "is this location
  empty" check, must count assets placed in a location as well as stock rows.
- Decision 6 gives up hiding the existence of a property from stock readers. The maintainer
  stated on 2026-11-28 that a second home is generally not secret from someone with access
  to the household's inventory. A household that needs a hidden property would need a
  superseding decision.
- Whether a grant on a property passes to its belongings remains plan 38 question 2. This
  record decides only the property's name and type visibility.

## Options considered

| Option | Why not chosen |
|---|---|
| A property is a typed root location | Every existing path gains a prefix, which changes text on printed labels. It uses one level of the depth limit. A house row would carry stock fields that mean nothing for it, and stock could be booked into the house itself. Private fields still need a side table to stay out of `STOCK_VIEW`. The migration would have to create houses and choose one for every existing root. |
| `property_id` on every location row | Two sources for the same fact. A trigger would have to keep each descendant equal to its root, and reparenting a subtree would rewrite every row below it. |
| Assign existing roots to a default "Home" property during the upgrade | The migration would make an assignment the household did not make. The maintainer chose unassigned roots on 2026-11-28. |
| Hide property names from stock readers | Every path display would need a permission check per property. The maintainer judged on 2026-11-28 that property existence is not sensitive within a household. |
| Allow cross-property reparenting through the generic path | The contents' recorded whereabouts would change with no history entry, and access could broaden with no check on the destination property. |

## Acceptance prerequisites

1. A pgTAP test shows the trigger refusing `property_id` on a non-root location, and a
   reparent under another property refused through the generic path.
2. A fixture holding two properties with a root named "Garage" in each, plus an unassigned
   root, loads; `locations_resolved` returns the right `property_id` for every descendant.
3. An upgrade rehearsal on a copy of a real installation shows every existing
   `locations_resolved.path`, label payload and stock reference unchanged, and every root
   unassigned.
4. The response-contract snapshot diff shows `property_id` as the only change to
   `locations_resolved`.
