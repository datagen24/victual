"""Disposable PostgreSQL design probes; no production tables or code are used."""
import os
from pathlib import Path
import subprocess
import time

CONTAINER = os.environ.get('ADR29_CONTAINER', 'victual-461-spike')
CMD = ['podman', 'exec', '-i', CONTAINER, 'psql', '-X', '-qAt', '-U', 'postgres', '-v', 'ON_ERROR_STOP=1']

def sql(statement, fail=None):
    result = subprocess.run(CMD, input=statement, text=True, capture_output=True, timeout=15)
    if fail is None:
        assert result.returncode == 0, result.stderr
    else:
        assert result.returncode != 0 and fail in result.stderr, result.stderr
    return result.stdout.strip()

class Session:
    def __init__(self):
        self.process = subprocess.Popen(CMD, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    def run(self, statement):
        self.process.stdin.write(statement + '\n\\echo marker\n')
        self.process.stdin.flush()
        rows = []
        while True:
            line = self.process.stdout.readline()
            assert line, 'session ended before marker'
            if line.strip() == 'marker':
                return '\n'.join(rows)
            rows.append(line.strip())
    def close(self):
        self.process.communicate('ROLLBACK;\n', timeout=10)
        assert self.process.returncode == 0

print(sql('SELECT version();'))
tap = sql(Path(__file__).with_name('constraint.sql').read_text())
assert 'not ok' not in tap and '1..12' in tap, tap
print(tap)

# Every table below lives in a dedicated schema in the disposable container.
sql('DROP SCHEMA IF EXISTS adr29_probe CASCADE; CREATE SCHEMA adr29_probe; '
    'CREATE TABLE adr29_probe.locations(id integer PRIMARY KEY); '
    'CREATE TABLE adr29_probe.stock(id integer PRIMARY KEY, location_id integer); '
    'INSERT INTO adr29_probe.locations VALUES(1), (2); '
    'INSERT INTO adr29_probe.stock VALUES(1, 99), (2, NULL);')
setup = 'SET search_path = adr29_probe; '
migration = """
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
LOCK TABLE locations, stock IN SHARE ROW EXCLUSIVE MODE;
DO $$ DECLARE bad text; BEGIN
  SELECT string_agg(s.id || ':' || s.location_id, ', ' ORDER BY s.id)
    INTO bad FROM stock s LEFT JOIN locations l ON l.id = s.location_id
    WHERE s.location_id IS NOT NULL AND l.id IS NULL;
  IF bad IS NOT NULL THEN
    RAISE EXCEPTION 'Dangling stock id:location id: %. Repair explicitly and retry.', bad;
  END IF;
END $$;
CREATE INDEX stock_location_id_idx ON stock(location_id);
ALTER TABLE stock ADD CONSTRAINT stock_location_id_fkey FOREIGN KEY(location_id)
 REFERENCES locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT NOT DEFERRABLE;
COMMIT;
"""
sql(setup + migration, fail='Dangling stock id:location id: 1:99')
assert sql(setup + 'SELECT location_id FROM stock WHERE id=1') == '99'
assert sql("SELECT count(*) FROM pg_constraint WHERE conrelid='adr29_probe.stock'::regclass AND contype='f'") == '0'
print('PASS: dirty migration refuses, identifies row, preserves data and schema')
# Explicit test-fixture repair, never a proposed migration action.
sql(setup + 'UPDATE stock SET location_id=1 WHERE id=1')
sql(setup + migration)
print('PASS: migration succeeds after explicit fixture repair')

# Observe real peer lock waits before permitting either transaction to commit.
def race(first, second, expected):
    holder = Session()
    try:
        holder.run(setup + 'BEGIN; ' + first)
        peer = subprocess.Popen(CMD, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        peer.stdin.write(setup + "SET application_name='adr29_peer'; SET statement_timeout='5s'; " + second)
        peer.stdin.close()
        peer.stdin = None
        deadline = time.monotonic() + 4
        while sql("SELECT count(*) FROM pg_stat_activity WHERE application_name='adr29_peer' AND wait_event_type='Lock'") != '1':
            assert time.monotonic() < deadline, 'peer did not wait on lock'
            time.sleep(0.02)
        holder.run('COMMIT;')
        _, error = peer.communicate(timeout=8)
        assert peer.returncode != 0 and expected in error, error
    finally:
        holder.close()

race('INSERT INTO stock VALUES(3, 2);', 'DELETE FROM locations WHERE id=2;', 'stock_location_id_fkey')
assert sql(setup + 'SELECT count(*) FROM locations WHERE id=2') == '1'
sql(setup + 'DELETE FROM stock WHERE id=3')
race('DELETE FROM locations WHERE id=2;', 'INSERT INTO stock VALUES(3, 2);', 'stock_location_id_fkey')
assert sql(setup + 'SELECT count(*) FROM stock WHERE id=3') == '0'
print('PASS: booking-first and deletion-first races wait and reject the losing write')

holder = Session()
try:
    holder.run(setup + 'BEGIN; INSERT INTO stock VALUES(8, 1);')
    sql(setup + "BEGIN; SET LOCAL lock_timeout='100ms'; LOCK TABLE locations, stock IN SHARE ROW EXCLUSIVE MODE;", fail='lock timeout')
finally:
    holder.close()
assert sql(setup + 'SELECT count(*) FROM stock WHERE id=8') == '0'
print('PASS: bounded lock acquisition fails and rolls back')

sql(setup + 'CREATE TABLE validation_probe(id integer, location_id integer)')
holder = Session()
try:
    holder.run(setup + 'BEGIN; '
               'ALTER TABLE validation_probe ADD CONSTRAINT probe_fkey FOREIGN KEY(location_id) REFERENCES locations(id) NOT VALID; '
               'ALTER TABLE validation_probe VALIDATE CONSTRAINT probe_fkey;')
    sql(setup + "SET lock_timeout='100ms'; INSERT INTO validation_probe VALUES(9, 1)", fail='lock timeout')
finally:
    holder.close()
print('PASS: validation does not release the initial lock before commit')
sql(setup + "BEGIN; SET LOCAL statement_timeout='100ms'; UPDATE stock SET location_id=NULL WHERE id=1; SELECT pg_sleep(1); COMMIT;", fail='statement timeout')
assert sql(setup + 'SELECT location_id FROM stock WHERE id=1') == '1'
print('PASS: statement timeout rolls back earlier transaction writes')
sql('DROP SCHEMA adr29_probe CASCADE;')
