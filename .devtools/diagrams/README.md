# Diagram generator

Emits the six HTML diagrams linked from [docs/data-model.md](../../docs/data-model.md).
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
(`46 tables · 44 views · 55 triggers` on the ORM diagram, the per-cluster counts on the
schema map) come from these, run against a tree with the migrations applied:

```bash
python3 - <<'PY'
import re, glob, os
files = sorted(glob.glob('db/pgsql/baseline/*.sql')) + ['db/pgsql/roles-schema.sql']
files += [f for f in sorted(glob.glob('migrations/0*.sql'))
          if '.sqlite.' not in f and int(os.path.basename(f)[:4]) >= 256]
tables, views, triggers = set(), set(), set()
for f in files:
    s = open(f).read()
    for m in re.finditer(r'(?mi)^\s*CREATE TABLE (?:IF NOT EXISTS )?([a-z_0-9]+)', s): tables.add(m.group(1))
    for m in re.finditer(r'(?mi)^\s*DROP TABLE (?:IF EXISTS )?([a-z_0-9]+)', s): tables.discard(m.group(1))
    for m in re.finditer(r'(?mi)^\s*CREATE (?:OR REPLACE )?VIEW ([a-z_0-9]+)', s): views.add(m.group(1))
    for m in re.finditer(r'(?mi)^\s*DROP VIEW (?:IF EXISTS )?([a-z_0-9]+)', s): views.discard(m.group(1))
    for m in re.finditer(r'(?mi)^\s*CREATE TRIGGER ([a-z_0-9]+)', s): triggers.add(m.group(1))
print(len(tables), 'tables', len(views), 'views', len(triggers), 'triggers')
print(sorted(tables))
PY
```

Migrations 0001-0255 are excluded because PostgreSQL loads `db/pgsql/baseline/` instead of
replaying them, and the `.sqlite.sql` files are excluded because the SQLite line is frozen
at 0265 ([ADR-0008](../../docs/adr/0008-postgresql-only-runtime-engine.md)). The runtime
`migrations` bookkeeping table is not in any DDL file and so is not counted.

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
