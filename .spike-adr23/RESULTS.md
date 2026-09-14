# ADR-0023 acceptance prerequisites 2 and 3 — spike results

Run 2026-09-14 against PostgreSQL 16.15 (`postgres:16`, Debian build), booted with:

```
docker run -d --name adr23-spike-pg -e POSTGRES_USER=victual -e POSTGRES_PASSWORD=victual \
  -e POSTGRES_DB=victual --tmpfs /var/lib/postgresql/data postgres:16
docker exec -i adr23-spike-pg psql -U victual -d victual -v ON_ERROR_STOP=1 < 00-schema.sql
docker exec -i adr23-spike-pg psql -U victual -d victual -v ON_ERROR_STOP=1 < 01-nested-product-groups.pgsql.sql
docker exec -i adr23-spike-pg psql -U victual -d victual -v ON_ERROR_STOP=1 < 02-spice-tree-seed.sql
docker exec -i adr23-spike-pg psql -U victual -d victual < 03-mixed-node-assertions.sql
docker exec -i adr23-spike-pg psql -U victual -d victual < 04-uniqueness-tests.sql
```

`docker` here is podman's shim (`Emulate Docker CLI using podman`), which is this machine's
setup — see the local-environment memory this branch's session used, and
`.devtools/pgsql/run-tests.sh`'s own doc for the same substitution.

## Prerequisite 2 — the mixed node

`03-mixed-node-assertions.sql` against the spice tree seeded by `02-spice-tree-seed.sql`:

```
--- Garlic: its own row, its subgroup, and the product filed directly in it ---
 garlic_group | subgroup | product_in_garlic
--------------+----------+-------------------
 Garlic       | Fresh    | Dried (Garlic)
(1 row)

--- product_groups_resolved: full path for every group, Garlic/Fresh included ---
 descendant_product_group_id |          path
-----------------------------+-------------------------
                           1 | Spices
                           4 | Spices / Blend
                           6 | Spices / Garlic
                           7 | Spices / Garlic / Fresh
                           8 | Spices / Mustard
                           5 | Spices / Paprika
                           2 | Spices / Parsley
                           3 | Spices / Pepper
(8 rows)

--- Fresh (the subgroup) still resolves its own products (Whole, Crushed) ---
  name
---------
 Crushed
 Whole
(2 rows)
```

`Garlic` (id 6) is simultaneously the `parent_product_group_id` of `Fresh` (id 7) and the
`product_group_id` of the product `Dried (Garlic)`, with no constraint, trigger or view
special-casing that combination — decision 6's claim, demonstrated against the schema rather
than argued from it. `product_groups_resolved` reaches `Spices / Garlic / Fresh` at depth 2
without incident, and `Fresh` still resolves the products filed in it directly, so nothing
about the mixed node above it changes how a leaf group works.

## Prerequisite 3 — name uniqueness

`04-uniqueness-tests.sql`, three cases against `UNIQUE NULLS NOT DISTINCT
(parent_product_group_id, name)`:

```
--- case A: two groups named Dried under different parents (Parsley, Garlic) ---
expect: both succeed
INSERT 0 1
INSERT 0 1

--- case B: a second Dried under the SAME parent (Parsley again) ---
expect: refused, duplicate key value violates unique constraint "product_groups_parent_name_key"
ERROR:  duplicate key value violates unique constraint "product_groups_parent_name_key"
DETAIL:  Key (parent_product_group_id, name)=(2, Dried) already exists.

--- case C: a second root group named Spices (parent NULL) — the NULLS NOT DISTINCT case ---
expect: refused
ERROR:  duplicate key value violates unique constraint "product_groups_parent_name_key"
DETAIL:  Key (parent_product_group_id, name)=(null, Spices) already exists.

--- final state: the two accepted Dried rows, distinguishable only by parent ---
 id | name  | parent_name
----+-------+-------------
  9 | Dried | Parsley
 10 | Dried | Garlic
(2 rows)
```

All three land as plan 30's verification section predicts: two same-named groups under
different parents are both accepted (case A); a second one under a parent that already has
that name is refused (case B); and the refusal reaches the NULL-parent root case too (case
C) — which is the entire reason for `NULLS NOT DISTINCT` rather than a plain `UNIQUE`, since
PostgreSQL's default treats every NULL as distinct from every other and case C would
otherwise pass. Confirms plan 30's citation of PostgreSQL 15 as the floor this needs: `NULLS
NOT DISTINCT` does not parse on 14 or earlier.

## What this spike does not cover

No cycle guard, no depth guard, no delete guard, and no concurrency case — those are not
what prerequisites 2 or 3 ask for, and migration 0279 copies them from
`migrations/0273.pgsql.sql` per plan 30's instruction rather than inventing them here. This
spike also does not touch `products.product_group_id`'s own behaviour beyond what the mixed
node needs (it is unchanged by this decision).
