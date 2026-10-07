#!/usr/bin/env python3
"""Checks an issue #650 upgrade rehearsal from snapshot.php output. See README.md.

    verify.py upgrade <manifest> <before> <after> <again>
    verify.py refusal <manifest> <before> <after>
    verify.py reproducible <first> <second>
    verify.py selftest <manifest> <before> <after> <again>

The expected instant of every converted wall clock comes from Python's zoneinfo, reading the
wall clock with fold=0, which PEP 495 defines as the earlier of the two instants a repeated
wall clock names. That is a third implementation, independent of both
victual_local_to_instant() and Instant::FromWallClock(). The named values in the manifest are
checked against the instants written out by hand in generate.php, which are parsed here and
never converted.

Exit status 0 when every check passes, 1 otherwise. Each failure is printed.
"""

import json
import sys
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo

LEGACY = "timestamp without time zone"
INSTANT = "timestamp with time zone"
EPOCH = datetime(1970, 1, 1, tzinfo=timezone.utc)
MICRO = timedelta(microseconds=1)

# Values the source revision's code draws from uniqid(), random_bytes() or a password salt.
# They differ between two generations by design and carry no time; `reproducible` skips them.
RANDOM_BY_DESIGN = {
    "stock.stock_id", "stock_log.stock_id", "stock_log.transaction_id", "stock_log.correlation_id",
    "stock_entry_origins.stock_id", "stock_entry_origins.origin_stock_id",
    "label_template_drafts.revision_token", "users.password", "system_db_changed_time.changed_time",
}

# Rows the target's migrations 0289-0302 add or derive, measured on the first run and
# explained in the evidence record. Anything else that changes is a failure.
CHANGED_BY_LATER_MIGRATIONS = {
    "migrations": "one row per migration 0289-0302 is added",
    "permission_hierarchy": "migration 0299 adds three permission rows",
}

# Written by the upgrade itself rather than converted: applying migrations is a data change.
WRITTEN_BY_UPGRADE = {
    "system_db_changed_time": "the upgrade records its own change time",
}

# Migration 0292 rebuilds these caches with reconcile_stock_log_cache(): new surrogate ids,
# and an average summed in a different order than the triggers summed it can differ in the
# last bit. They are compared by content without the id, prices to 12 significant digits.
REBUILT = {"cache__products_average_price", "cache__products_last_purchased"}

failures = []


def fail(message):
    failures.append(message)
    print("FAIL", message)


def load(path):
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


def wall_of(text):
    return datetime.strptime(text, "%Y-%m-%d %H:%M:%S.%f")


def micros(moment):
    return (moment - EPOCH) // MICRO


def literal_instant(text):
    """Parses an RFC 3339 instant written in generate.php. A parse, not a zone conversion."""
    return micros(datetime.fromisoformat(text.replace("Z", "+00:00")))


def oracle(wall, zone):
    """The earlier instant a wall clock names in zone, or None if the zone skipped it."""
    local = wall.replace(tzinfo=zone, fold=0)
    if local.astimezone(timezone.utc).astimezone(zone).replace(tzinfo=None) != wall:
        return None
    return micros(local.astimezone(timezone.utc))


def ambiguous(wall, zone):
    return wall.replace(tzinfo=zone, fold=0).utcoffset() != wall.replace(tzinfo=zone, fold=1).utcoffset()


def columns_of(snapshot, wanted):
    return {(t, c) for t, table in snapshot["tables"].items() for c, kind in table["types"].items() if kind == wanted}


def compare_untouched(before, after, legacy, allowed, label):
    """Every table, row and column of `before` that is not a converted column is unchanged."""
    changed = 0
    for table, data in before["tables"].items():
        if table not in after["tables"]:
            fail(f"{label}: table {table} is gone")
            continue
        rows_after = after["tables"][table]["rows"]
        if table in REBUILT:
            types = data["types"]
            content = lambda rows: sorted(json.dumps({c: (f"{float(v):.12g}" if v is not None and types[c] == "double precision" else v)
                                                      for c, v in r.items() if c != "id"}, sort_keys=True) for r in rows.values())
            if content(data["rows"]) != content(rows_after):
                fail(f"{label}: {table} content changed")
            continue
        if table in WRITTEN_BY_UPGRADE:
            continue
        if table in allowed:
            missing = set(data["rows"]) - set(rows_after)
            if missing:
                fail(f"{label}: {table} lost rows {sorted(missing)[:5]}")
            continue
        if len(rows_after) != len(data["rows"]):
            fail(f"{label}: {table} has {len(rows_after)} rows, had {len(data['rows'])}")
        for key, row in data["rows"].items():
            if key not in rows_after:
                fail(f"{label}: {table} row {key} is gone")
                continue
            for column, value in row.items():
                if (table, column) in legacy:
                    continue
                if rows_after[key].get(column) != value:
                    changed += 1
                    fail(f"{label}: {table}.{column} row {key}: {value!r} became {rows_after[key].get(column)!r}")
    return changed


def check_upgrade(manifest_path, before_path, after_path, again_path):
    upgrade(*(load(p) for p in (manifest_path, before_path, after_path, again_path)))


def upgrade(manifest, before, after, again):
    zone = ZoneInfo(manifest["zone"])
    legacy = columns_of(before, LEGACY)
    dates = columns_of(before, "date")
    instants = columns_of(before, INSTANT)
    print(f"source schema: migration {before['migration']}, {len(legacy)} legacy timestamp columns, "
          f"{len(instants)} instant columns, {len(dates)} date columns")
    print(f"target schema: migration {after['migration']}; second run: migration {again['migration']}")
    print(f"server: {after['server_version']}")

    # 1 and 3: types.
    for table, column in sorted(legacy):
        kind = after["tables"].get(table, {}).get("types", {}).get(column)
        if kind != INSTANT:
            fail(f"{table}.{column} is {kind} after the upgrade")
    left = columns_of(after, LEGACY)
    if left:
        fail(f"legacy timestamp columns remain: {sorted(left)}")
    for table, column in sorted(dates | instants):
        if after["tables"][table]["types"].get(column) != before["tables"][table]["types"][column]:
            fail(f"{table}.{column} changed type")
    added = sorted(f"{t}.{c}" for t, data in after["tables"].items() for c in data["types"]
                   if t not in before["tables"] or c not in before["tables"][t]["types"])
    print(f"columns the later migrations add: {', '.join(added) or 'none'}")

    # 1: every converted value against the oracle.
    converted = nulls = repeated = 0
    for table, column in sorted(legacy):
        if table in WRITTEN_BY_UPGRADE:
            continue
        rows_after = after["tables"][table]["rows"]
        for key, row in before["tables"][table]["rows"].items():
            value = row[column]
            got = rows_after.get(key, {}).get(column)
            if value is None:
                nulls += 1
                if got is not None:
                    fail(f"{table}.{column} row {key}: NULL became {got}")
                continue
            wall = wall_of(value)
            expected = oracle(wall, zone)
            if expected is None:
                fail(f"{table}.{column} row {key}: {value} is a wall clock {zone.key} skipped")
                continue
            repeated += ambiguous(wall, zone)
            converted += 1
            if got is None or int(got) != expected:
                fail(f"{table}.{column} row {key}: {value} became {got}, expected {expected}")
    print(f"1. {converted} legacy values equal the zoneinfo oracle ({repeated} in a repeated hour); {nulls} NULLs stayed NULL")

    for entry in manifest["legacy"]:
        table, column, key = entry["table"], entry["column"], str(entry["id"])
        wall = before["tables"][table]["rows"][key][column]
        got = after["tables"][table]["rows"].get(key, {}).get(column)
        if got is None:
            fail(f"named {table}.{column} {key}: missing after the upgrade")
            continue
        got = int(got)
        if wall_of(wall) != wall_of(entry["wall"] + ("" if "." in entry["wall"] else ".0")):
            fail(f"named {table}.{column} {key}: source holds {wall}, manifest says {entry['wall']}")
        if got != literal_instant(entry["expected"]):
            fail(f"named {table}.{column} {key}: {wall} became {got}, written expectation {entry['expected']}")
        if entry["later"] and got == literal_instant(entry["later"]):
            fail(f"named {table}.{column} {key}: took the later instant {entry['later']}")
        if entry["later"] and not ambiguous(wall_of(wall), zone):
            fail(f"named {table}.{column} {key}: {wall} is not in a repeated hour")
        print(f"   {table}.{column} {key}: {entry['wall']} -> {entry['expected']} ({entry['why']})")

    # 2: existing instants keep their meaning and precision.
    kept = 0
    for table, column in sorted(instants):
        rows_after = after["tables"][table]["rows"]
        for key, row in before["tables"][table]["rows"].items():
            if rows_after.get(key, {}).get(column) != row[column]:
                fail(f"{table}.{column} row {key}: instant {row[column]} became {rows_after.get(key, {}).get(column)}")
            elif row[column] is not None:
                kept += 1
    for entry in manifest["instants"]:
        got = after["tables"]["labels"]["rows"][entry["uid"]][entry["column"]]
        if int(got) != literal_instant(entry["instant"]):
            fail(f"named labels.{entry['column']} {entry['uid']}: {got}, expected {entry['instant']}")
        print(f"   labels.{entry['column']} {entry['uid']}: {entry['instant']} kept ({entry['why']})")
    print(f"2. {kept} existing instants identical to the microsecond")

    # 3 and 4: everything that is not a converted column, dates included, is unchanged.
    changed = compare_untouched(before, after, legacy, CHANGED_BY_LATER_MIGRATIONS, "upgrade")
    date_values = sum(1 for t, c in dates for r in before["tables"][t]["rows"].values() if r[c] is not None)
    print(f"3. {date_values} date values in {len(dates)} columns unchanged" if not changed else "3. see failures")
    counts = {t: len(before["tables"][t]["rows"]) for t in ("stock", "stock_log", "chores_log", "battery_charge_cycles", "tasks", "meal_plan", "labels")}
    exceptions = {**CHANGED_BY_LATER_MIGRATIONS, **WRITTEN_BY_UPGRADE, **{t: "rebuilt by 0292, compared by content" for t in REBUILT}}
    print(f"4. every row of every table kept, other than {'; '.join(f'{t} ({why})' for t, why in exceptions.items())}: {counts}")
    stock_total = {}
    for row in after["tables"]["stock"]["rows"].values():
        stock_total[row["product_id"]] = stock_total.get(row["product_id"], 0) + float(row["amount"])
    print(f"   stock by product after: {dict(sorted(stock_total.items(), key=lambda i: int(i[0])))}")
    targets = {"location": "locations", "product": "products", "stock_entry": "stock"}
    for uid, label in after["tables"]["labels"]["rows"].items():
        if label["target_id"] is not None and label["target_id"] not in after["tables"][targets[label["kind"]]]["rows"]:
            fail(f"label {uid} points at a {label['kind']} {label['target_id']} that does not exist")
        if label != before["tables"]["labels"]["rows"][uid]:
            fail(f"label {uid} changed")
    print(f"   {len(after['tables']['labels']['rows'])} labels resolve to the same targets")

    # 5: a second run changes nothing anywhere.
    differences = [(t, k) for t, data in after["tables"].items() for k, r in data["rows"].items()
                   if again["tables"].get(t, {}).get("rows", {}).get(k) != r]
    differences += [(t, k) for t, data in again["tables"].items() for k in data["rows"] if k not in after["tables"].get(t, {}).get("rows", {})]
    if again["tables"].keys() != after["tables"].keys() or any(again["tables"][t]["types"] != after["tables"][t]["types"] for t in after["tables"]):
        fail("the second run changed the schema")
    for table, key in differences[:20]:
        fail(f"the second run changed {table} row {key}")
    print(f"5. second run: {sum(len(d['rows']) for d in again['tables'].values())} rows in {len(again['tables'])} tables, {len(differences)} differences")


def check_refusal(manifest_path, before_path, after_path):
    manifest, before, after = (load(p) for p in (manifest_path, before_path, after_path))
    legacy = columns_of(before, LEGACY)
    print(f"source schema: migration {before['migration']}; after the refused upgrade: migration {after['migration']}")
    for table, column in sorted(legacy):
        kind = after["tables"][table]["types"][column]
        if kind != LEGACY:
            fail(f"{table}.{column} was converted to {kind}")
    if columns_of(after, INSTANT) & legacy:
        fail("a legacy column became an instant")
    if after["migration"] >= 301:
        fail(f"migration {after['migration']} was recorded")
    values = sum(1 for t, c in legacy for r in before["tables"][t]["rows"].values() if r[c] is not None)
    for table, column in sorted(legacy):
        if table in WRITTEN_BY_UPGRADE:
            continue
        for key, row in before["tables"][table]["rows"].items():
            if after["tables"][table]["rows"].get(key, {}).get(column) != row[column]:
                fail(f"{table}.{column} row {key}: {row[column]} became {after['tables'][table]['rows'].get(key, {}).get(column)}")
    for entry in manifest["refused"]:
        got = after["tables"][entry["table"]]["rows"][str(entry["id"])][entry["column"]]
        print(f"   refused value kept: {entry['table']}.{entry['column']} {entry['id']} = {got} ({entry['why']})")
    compare_untouched(before, after, legacy, CHANGED_BY_LATER_MIGRATIONS, "refusal")
    print(f"6. no column converted: {len(legacy)} columns still {LEGACY}, {values} wall clocks byte-identical")


def check_reproducible(first_path, second_path):
    first, second = load(first_path), load(second_path)
    compared = 0
    for table, data in first["tables"].items():
        rows = second["tables"][table]["rows"]
        if set(rows) != set(data["rows"]):
            fail(f"{table}: different row keys")
            continue
        for key, row in data["rows"].items():
            for column, value in row.items():
                if f"{table}.{column}" in RANDOM_BY_DESIGN:
                    continue
                compared += 1
                if rows[key][column] != value:
                    fail(f"{table}.{column} row {key}: {value!r} vs {rows[key][column]!r}")
    print(f"two generations: {compared} values identical; skipped {', '.join(sorted(RANDOM_BY_DESIGN))}")


def check_selftest(manifest_path, before_path, after_path, again_path):
    """Negative controls: each tampered copy of a passing upgrade must fail."""
    import copy
    import io
    import contextlib
    manifest, before, after, again = (load(p) for p in (manifest_path, before_path, after_path, again_path))
    repeated = next(e for e in manifest["legacy"] if e["later"])
    instant = manifest["instants"][0]
    date_table, date_column = ("stock", "best_before_date")
    date_key = next(k for k, r in before["tables"][date_table]["rows"].items() if r[date_column] is not None)

    def later(a, g):
        a["tables"][repeated["table"]]["rows"][str(repeated["id"])][repeated["column"]] = str(literal_instant(repeated["later"]))

    def nudge_instant(a, g):
        row = a["tables"]["labels"]["rows"][instant["uid"]]
        row[instant["column"]] = str(int(row[instant["column"]]) + 1)

    def move_date(a, g):
        a["tables"][date_table]["rows"][date_key][date_column] = "1999-12-31"

    def drop_ledger_row(a, g):
        del a["tables"]["stock_log"]["rows"][next(iter(a["tables"]["stock_log"]["rows"]))]

    def second_run_writes(a, g):
        row = g["tables"]["chores_log"]["rows"][str(repeated["id"])] if repeated["table"] == "chores_log" else next(iter(g["tables"]["chores_log"]["rows"].values()))
        row["tracked_time"] = str(int(row["tracked_time"]) + 3600000000)

    def run(a, g):
        start = len(failures)
        with contextlib.redirect_stdout(io.StringIO()):
            upgrade(manifest, before, a, g)
        count = len(failures) - start
        del failures[start:]
        return count

    baseline = run(after, again)
    if baseline:
        fail(f"the untampered upgrade already fails {baseline} checks; the controls would prove nothing")
        return
    for name, tamper in [("repeated hour read as the later instant", later), ("existing instant moved by 1 us", nudge_instant),
                         (f"{date_table}.{date_column} changed", move_date), ("a ledger row lost", drop_ledger_row),
                         ("second run changed a row", second_run_writes)]:
        a, g = copy.deepcopy(after), copy.deepcopy(again)
        tamper(a, g)
        caught = run(a, g)
        if caught == 0:
            fail(f"negative control not detected: {name}")
        else:
            print(f"control detected: {name} ({caught} failures)")


if __name__ == "__main__":
    modes = {"upgrade": (check_upgrade, 4), "refusal": (check_refusal, 3), "reproducible": (check_reproducible, 2), "selftest": (check_selftest, 4)}
    if len(sys.argv) < 2 or sys.argv[1] not in modes or len(sys.argv) != 2 + modes[sys.argv[1]][1]:
        print(__doc__)
        sys.exit(2)
    modes[sys.argv[1]][0](*sys.argv[2:])
    print("RESULT", "FAIL" if failures else "PASS", f"({len(failures)} failures)")
    sys.exit(1 if failures else 0)
