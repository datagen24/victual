"""Read supported SQLite fixtures; mutate only private in-memory copies."""
from pathlib import Path
import sqlite3

root = Path(__file__).resolve().parents[1]
query = '''SELECT s.id, s.product_id, s.location_id FROM stock s
LEFT JOIN locations l ON l.id=s.location_id
WHERE s.location_id IS NOT NULL AND l.id IS NULL ORDER BY s.id'''
for version in (255, 265):
    path = root / f'.devtools/pgsql/fixtures/import/victual-{version}.db'
    source = sqlite3.connect(f'file:{path}?mode=ro', uri=True)
    assert source.execute(query).fetchall() == []
    copy = sqlite3.connect(':memory:')
    source.backup(copy)
    source.close()
    row = copy.execute('SELECT id, product_id FROM stock ORDER BY id LIMIT 1').fetchone()
    assert row is not None
    copy.execute('UPDATE stock SET location_id=2147483647 WHERE id=?', (row[0],))
    assert copy.execute(query).fetchall() == [(row[0], row[1], 2147483647)]
    copy.execute('UPDATE stock SET location_id=NULL WHERE id=?', (row[0],))
    assert copy.execute(query).fetchall() == []
    copy.close()
    print(f'PASS: source {version}: valid references; dangling stock/product/location report; null allowed')
