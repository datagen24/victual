# 04. Seed product datasets

**Goal:** Optional baseline master data — common household staples, quantity units,
product groups, and barcodes — so a new install is not an empty database.
**Upstream:** [grocy/grocy#2679](https://github.com/grocy/grocy/issues/2679)
**Status:** draft for review.

## Today

A fresh install has nothing but the defaults created during migration. Populating master
data means entering products by hand or scripting against the API.

There is no import mechanism of any kind — no CSV, no JSON, nothing in the API controllers.
The one precedent is `DemoDataGeneratorService`, which builds a full sample database in PHP
for demo mode. It is a reasonable model for *how* to write rows (it goes through LessQL and
lets triggers fire, which is what keeps derived tables consistent) but not for *what*,
since its content is illustrative rather than useful.

## Proposed change

Two separable things, and conflating them is the main risk in this plan:

**A. An import mechanism.** A command that takes a dataset file and creates master data,
skipping anything that already exists. Useful on its own — it is also how you would restore
master data, share a setup, or bootstrap a second instance.

**B. Actual dataset content.** The staples themselves. This is curation work, not
engineering, and it is where the effort really goes.

### Format

JSON, one file per dataset, covering the master data entities only — `quantity_units`,
`quantity_unit_conversions`, `product_groups`, `locations`, `shopping_locations`,
`products`, `product_barcodes` — and, from [19](19-rbac.md), `roles` and
`role_permissions`. Deliberately not stock, chores, recipes or user *accounts*: roles are
in because they are name-keyed master data like everything else here, and because 19's
three built-in roles want to be re-shippable rather than migration-only. `role_permissions`
comes with them not as master data in its own right but as the role's content — a role
without its grants is an empty name — and it is the one place this format's
reference-by-name rule does real work, since permission names are stable and their ids are
not. Users stay out: a user is an identity rather than a row.

Entities reference each other by **name**, not id, so a dataset is portable between
installs and merges into an existing one. The importer resolves names to ids as it goes.

**Roles are the one exception and key on `code` instead**, because a role's `name` is an
editable display label ([19](19-rbac.md)'s schema) and its `code` — `ADMIN`, `ADULT`,
`CHILD` — is immutable. Matching a role by name would mean that a household which renamed
"Child" to "Kids" gets a second role on the next import, or has its rename overwritten;
matching on `code` is the same stability argument this format already makes for permission
names, applied to the column that actually has it.

### Import path

`bin/victual-seed-import <dataset.json> [--dry-run]`, mirroring `bin/victual-db-import`:

- idempotent — an existing name is skipped, not duplicated or updated
- `--dry-run` prints what would be created
- goes through LessQL so triggers fire and derived tables stay correct
- reports what it created and what it skipped

Writing directly through the service layer rather than over HTTP avoids needing an API key
and keeps it usable during first setup.

**It mirrors `bin/victual-db-import` in shape and takes the opposite trigger stance, which
is deliberate.** That tool disables triggers for the duration of its copy; this one needs
them to fire. Both are right, because they are importing different things. `db-import`
replays rows that are *already shaped* — they came out of a migrated database with every
derived table already consistent — so letting triggers run would recompute the same values
from data that already reflects them, and on the change-tracking tables it would be worse
than redundant. A seed dataset is the opposite case: it is raw master data with no derived
rows behind it, so the triggers are exactly what produces the state a hand-written fixture
would otherwise have to fake. Recorded because the two tools sit next to each other in
`bin/` and read as an inconsistency, and the next person to notice should not "fix" either
one to match the other. `db/pgsql/README.md` documents the importer's side.

### Datasets shipped

Start with one small, obviously useful set rather than an ambitious library:
`datasets/household-staples-en.json` — a few dozen products across a handful of groups,
sensible quantity units, no barcodes.

### API

None. This is a CLI concern. Adding an import endpoint later is possible but is a new API
surface with real authorisation questions, and is not needed for the goal.

**Client impact: none.** Nothing on the wire changes. What a client *would* notice is
seeded rows appearing in `/objects/products` and friends on an instance someone seeded —
data, not contract, and only on instances that opt in by running the command.

## The barcode problem

The issue asks for "common household staple barcodes", and that is the part to be careful
about. Barcodes are regional — the same product carries different codes in different
markets, so a shipped list is wrong for most users. Worse, a *wrong* barcode is more harmful
than none, because scanning silently matches the wrong product.

Victual resolves barcodes at scan time through `STOCK_BARCODE_LOOKUP_PLUGIN`, which is
regionally correct and always current. That is the better place to invest — and it needs
investment, because the default source is weak for US products. See
[09 barcode lookup sources](09-barcode-lookup-sources.md), which exists largely because of
this question.

My recommendation is to ship **no** barcodes here and fix lookup instead — see Q2.

## Open questions

1. **Is this actually valuable to you**, or is it a "good for the fork's users" item? You
   already have a populated instance. If it is the latter, it is the easiest thing on the
   roadmap to defer, and A without B is most of the durable value.

   > **Response:** Reframed: **A is worth building for this fork's own operational
   > life regardless of B.** A name-keyed, idempotent master-data importer is also
   > how disposable test instances get seeded — which the difftest workflow and
   > every future plan's manual testing will want. Build A when the first plan needs
   > a seeded instance (probably 06 or 08); let B wait indefinitely.
2. **Ship barcodes or not?** I recommend not, for the reasons above. If yes, they need a
   region marker and a clear statement that they are best-effort.

   > **Response:** Ship none. Agreed completely, for the stated reasons — and 09's
   > Q1 experiment settles it on data.
3. **Should a dataset be able to update existing rows**, or only create? Create-only is
   safe and predictable. Update-capable makes datasets a sync mechanism, which is a much
   bigger idea with conflict questions attached. Note that this now interacts with
   question 6: create-only means a re-imported role never has its permission set
   corrected, which is defensible for a product and awkward for a role whose grants were
   wrong.

   > **Response:** Create-only. Update-capable turns a seed file into a sync
   > protocol; decline.
4. **Where do datasets live?** In the repo (reviewable, versioned, but every user carries
   every dataset) or fetched on demand (flexible, but adds a network dependency and a
   trust question about what is being fetched).

   > **Response:** In the repo. A network fetch for master data is a trust and
   > availability cost with no household-scale benefit.
5. **Localisation.** Product names are language specific. One dataset per locale, or
   English only to start? English only, unless you want otherwise.

   > **Response:** English only.

6. **Who is the caller when a seed grants permissions?** [19](19-rbac.md)'s rule, inherited
   from sweep S5/S6, is that nobody may assign a role whose grants exceed their own
   effective permissions — and this importer is a CLI with no caller to compare against, so
   a dataset file carrying `roles` is a grant path with no subject. Three answers: refuse
   `roles` blocks unless the database has no users yet (first-setup only, which is what the
   importer is for); require a `--as-user` whose permissions bound the import; or accept
   that filesystem access to the dataset already implies database access, and record that
   as the reasoning. The first is cheapest and matches "usable during first setup"; the
   third is honest but makes 19's rule true everywhere except here.

   > **Response:**

## Review notes

- Give the JSON format a `schema_version` field and validate the whole file before
  writing anything, so `--dry-run` and "half-imported dataset" both stay honest. Once
  `roles` are in scope that validation covers permission *names* against
  `permission_hierarchy` — which is sweep S27 (an unvalidated id that silently grants
  nothing) arriving on a second path, and it should not be re-derived here.
- Plans [03](landed/03-category-min-stock.md) and [05](05-store-shopping-lists.md) make
  `product_groups` load-bearing (a minimum, a per-store position) — the shipped
  dataset should include a sane default group set.

## Effort

Medium, and unusually lopsided: the importer is a day, the curation is open-ended. Suggest
scoping to the importer plus one deliberately small dataset, and treating additional
datasets as ongoing content rather than part of the change.
