# Diagram generator

Emits the ten HTML diagrams linked from [docs/data-model.md](../../docs/data-model.md).
The entity lists, box coordinates, and connector routes are a Python spec in `build.py`;
the output files are self-contained HTML with inline SVG and no build step of their own.

```bash
python3 .devtools/diagrams/build.py docs/diagrams
```

Nothing is read from the schema at run time. The generator is a layout tool, not a schema
introspector: it will happily emit a diagram that no longer matches `db/pgsql/`. Changing
what a diagram says means editing the spec.

## After a schema change

A migration that adds or removes a table, changes a reference column, or moves a table
between clusters needs the spec updated. The counts printed on the diagrams
(`71 tables · 50 views · 65 triggers` on the ORM diagram, the per-cluster counts on the
schema map) come from this, run against a tree with the migrations applied. Statements are
matched in file order, so a `DROP VIEW` followed by a `CREATE VIEW` of the same name (0261
does this twice) leaves the view in place, and the `.php` migrations are read too, because
they create `storage_classes` (0274) and the label retirement triggers (0283):

```bash
python3 - <<'PY'
import re, glob, os
files = sorted(glob.glob('db/pgsql/baseline/*.sql')) + ['db/pgsql/roles-schema.sql']
files += [f for f in sorted(glob.glob('migrations/0*'))
          if '.sqlite.' not in f and int(os.path.basename(f)[:4]) >= 256]
tables, views, triggers = set(), set(), set()
stmt = re.compile(r'(?mi)^\s*(?:'
                  r'CREATE TABLE (?:IF NOT EXISTS )?(?P<ct>[a-z_0-9]+)'
                  r'|DROP TABLE (?:IF EXISTS )?(?P<dt>[a-z_0-9]+)'
                  r'|CREATE (?:OR REPLACE )?VIEW (?P<cv>[a-z_0-9]+)'
                  r'|DROP VIEW (?:IF EXISTS )?(?P<dv>[a-z_0-9]+)'
                  r'|CREATE TRIGGER (?P<cg>[a-z_0-9]+)'
                  r'|DROP TRIGGER (?:IF EXISTS )?(?P<dg>[a-z_0-9]+))')
ops = {'ct': tables.add, 'dt': tables.discard, 'cv': views.add,
       'dv': views.discard, 'cg': triggers.add, 'dg': triggers.discard}
for f in files:
    for m in stmt.finditer(open(f).read()):
        op = ops[m.lastgroup]
        op(m.group(m.lastgroup))
print(len(tables), 'tables', len(views), 'views', len(triggers), 'triggers')
print(sorted(tables))
PY
```

The authoritative check is the catalogue of a migrated database, and it should agree
(`bin/victual-migrate` against an empty PostgreSQL, then):

```sql
SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
SELECT count(*) FROM information_schema.views  WHERE table_schema = 'public';
SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
  JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT tgisinternal AND n.nspname = 'public';
```

The live catalogue holds two more base tables than the DDL files define, because two are
created at run time: `migrations` (by `DatabaseMigrationService`, before the baseline loads)
and `system_db_changed_time` (by `PostgresDialect`). Neither is on a diagram, so the
diagrams print the file count (71) and the live count is 73.

The ORM diagram's controller and service counts are `controllers/Api/*ApiController.php`
less `BaseApiController` (18), and the files under `services/` that `extends BaseService` (23).
The declared-foreign-key claims on the ER diagrams come from `pg_constraint` on the same
database (`contype = 'f'`; 46 today, 36 of them in the label subsystem).

Migrations 0001-0255 are excluded because PostgreSQL loads `db/pgsql/baseline/` instead of
replaying them, and the `.sqlite.sql` files are excluded because the SQLite line is frozen
at 0265 ([ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md)).

## Checking the output

Boxes and connectors are placed by hand in the spec, so a moved box can leave a connector
crossing another one or a label sitting on top of a box. After regenerating, open each file
and confirm that no connector passes behind a box that is not one of its endpoints, that
every label sits clear of both its own line and every box, and that no two connectors
overlap.

The diagrams follow the `diagram-design` skill's conventions and its `victual` skin, which
is extracted from `branding/logo.svg`: cream paper `#f2e7d3`, deep-green ink `#174b3a`,
terracotta accent `#c85a3d`. Those values are duplicated in `build.py` because the output
has to be self-contained; the `.diagram-design` marker in the repository root is what points
the skill at the same skin. The skill's own checker validates the accessible-SVG contract
and single-file safety:

```bash
python3 <skill-dir>/scripts/self_check.py docs/diagrams/erd-stock.html
```
