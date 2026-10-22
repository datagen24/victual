#!/usr/bin/env python3
"""Load a set of quantity units and the global conversions between them into a running Victual.

    VICTUAL_API_KEY=<key> .devtools/seed/seed_units.py --base-url https://victual.example \
        --system us|metric|both [--dry-run]

--system picks the measurement system: us (US customary), metric, or both, for a household that
shops and cooks in both, as in the northern US and Canada. Units every household uses (Piece,
Dozen, the containers) come with each choice. Loading both also links the two systems: a cup
converts to milliliters, a pound to grams. There is no default, because a unit set seeded by
mistake is a cleanup by hand.

It goes through the REST API rather than the database, so every row passes the same checks a
person adding it in the UI would: the unique unit name, the positive-factor CHECK (#868) and the
conversion-range refusal the insert trigger raises (#952). The key needs MASTER_DATA_EDIT.

The data file (default: quantity-units.json next to this script) lists units, each tagged with
the system that loads it. A loaded unit with a dimension and a size gets a global conversion to
every other loaded unit of that dimension. Every pair
is written directly, never left to a chain, because the resolver multiplies factors along a chain
and takes the shortest path: a direct conversion is the exact one, and with every pair direct the
resolver's closure stops at depth 1. Each pair is written once, from the larger unit to the
smaller, so the stored factor is the one at least 1; the database writes the inverse itself
(qu_conversions_inverse_INS). Factors are computed from the sizes as exact fractions and rounded
to a double once.

It only adds. A unit whose name already exists (compared case-insensitively) is reused as it is.
A conversion that exists in either direction is left alone, and if its factor disagrees with the
data file the run reports it and exits 1 - changing a factor rescales every product that uses it,
which is a decision for a person, not for a seed. So it is safe to run again: a second run over
the same instance writes nothing.

Exit status: 0 when everything was created or already present with the expected factor; 1 when a
conversion disagrees or a request failed. Standard library only.
"""
import argparse
from fractions import Fraction
import json
import os
from pathlib import Path
import sys
import urllib.error
import urllib.request

DEFAULT_DATA = Path(__file__).resolve().parent / "quantity-units.json"

# The --system choices, and the unit systems from the data file each one loads.
SYSTEMS = {
    "us": {"us", "common"},
    "metric": {"metric", "common"},
    "both": {"us", "metric", "common"},
}
UNIT_SYSTEMS = SYSTEMS["both"]

# Relative difference below which a stored factor counts as the expected one. The API stores
# doubles at full precision since #951, so a factor this script wrote compares equal; the
# tolerance only absorbs an inverse the database computed as 1/f.
FACTOR_TOLERANCE = 1e-9


class SeedError(Exception):
    """A data file or API response this script cannot go on with."""


def load_units(path):
    """Read and check the data file; return its unit list."""
    data = json.loads(Path(path).read_text())
    units = data.get("units")
    if not isinstance(units, list) or not units:
        raise SeedError(f"{path}: no units list")
    seen = set()
    for unit in units:
        name = unit.get("name")
        if not isinstance(name, str) or not name.strip():
            raise SeedError(f"{path}: a unit has no name")
        if name.casefold() in seen:
            raise SeedError(f"{path}: unit {name!r} is listed twice")
        seen.add(name.casefold())
        if unit.get("system") not in UNIT_SYSTEMS:
            raise SeedError(f"{path}: unit {name!r} has system {unit.get('system')!r}, not one of {sorted(UNIT_SYSTEMS)}")
        if ("dimension" in unit) != ("size" in unit):
            raise SeedError(f"{path}: unit {name!r} needs both dimension and size, or neither")
        if "size" in unit and Fraction(unit["size"]) <= 0:
            raise SeedError(f"{path}: unit {name!r} has a size that is not positive")
    return units


def select_units(units, system):
    """The units a --system choice loads, in data file order."""
    wanted = SYSTEMS[system]
    return [unit for unit in units if unit["system"] in wanted]


def plan_conversions(units):
    """Return (from_name, to_name, factor) for every pair of units sharing a dimension.

    1 from-unit = factor to-units, and from is the larger unit, so factor >= 1.
    """
    by_dimension = {}
    for unit in units:
        if "dimension" in unit:
            by_dimension.setdefault(unit["dimension"], []).append(unit)
    plan = []
    for members in by_dimension.values():
        for i, a in enumerate(members):
            for b in members[i + 1:]:
                ratio = Fraction(a["size"]) / Fraction(b["size"])
                larger, smaller = (a, b) if ratio >= 1 else (b, a)
                plan.append((larger["name"], smaller["name"], float(max(ratio, 1 / ratio))))
    return plan


def same_factor(stored, expected):
    """True when two factors agree within FACTOR_TOLERANCE, relative to the expected one."""
    return abs(float(stored) - expected) <= FACTOR_TOLERANCE * expected


class Api:
    """The two /api/objects calls this script needs."""

    def __init__(self, base_url, api_key):
        self.base = base_url.rstrip("/") + "/api"
        self.headers = {"VICTUAL-API-KEY": api_key, "Accept": "application/json"}

    def call(self, method, path, body=None):
        """Send one request and return the decoded JSON body; raise SeedError on a non-2xx."""
        headers = dict(self.headers)
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(self.base + path, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                text = response.read().decode()
        except urllib.error.HTTPError as error:
            raise SeedError(f"{method} {path}: HTTP {error.code}: {error.read().decode(errors='replace')[:500]}")
        except urllib.error.URLError as error:
            raise SeedError(f"{method} {path}: {error.reason}")
        try:
            return json.loads(text) if text else None
        except json.JSONDecodeError:
            # A wrong --base-url, or an instance that refuses to start, answers 200 with a page.
            raise SeedError(f"{method} {path}: not a JSON response: {text[:300]}")

    def list(self, entity):
        """GET /objects/{entity}."""
        return self.call("GET", f"/objects/{entity}")

    def create(self, entity, row):
        """POST /objects/{entity}; return the new row's id."""
        return int(self.call("POST", f"/objects/{entity}", row)["created_object_id"])


def seed(api, units, dry_run, out=print):
    """Create the missing units and conversions. Return the number of problems found."""
    problems = 0

    ids = {}
    existing = {row["name"].casefold(): row for row in api.list("quantity_units")}
    for unit in units:
        row = existing.get(unit["name"].casefold())
        if row is not None:
            ids[unit["name"]] = int(row["id"])
            note = "" if row["name"] == unit["name"] else f" (as {row['name']!r})"
            out(f"unit {unit['name']}: exists{note}")
            continue
        if dry_run:
            out(f"unit {unit['name']}: would create")
            continue
        fields = {key: unit[key] for key in ("name", "name_plural", "description") if key in unit}
        ids[unit["name"]] = api.create("quantity_units", fields)
        out(f"unit {unit['name']}: created")

    stored = {}
    for row in api.list("quantity_unit_conversions"):
        if row.get("product_id") is None:
            stored[(int(row["from_qu_id"]), int(row["to_qu_id"]))] = float(row["factor"])

    for from_name, to_name, factor in plan_conversions(units):
        label = f"conversion 1 {from_name} = {factor:.15g} {to_name}"
        a, b = ids.get(from_name), ids.get(to_name)
        if a is None or b is None:
            out(f"{label}: would create")
            continue
        if (a, b) in stored or (b, a) in stored:
            found = stored[(a, b)] if (a, b) in stored else 1 / stored[(b, a)]
            if same_factor(found, factor):
                out(f"{label}: exists")
            else:
                problems += 1
                out(f"{label}: EXISTS WITH {found:.15g}, left unchanged")
            continue
        if dry_run:
            out(f"{label}: would create")
            continue
        api.create("quantity_unit_conversions", {"from_qu_id": a, "to_qu_id": b, "factor": factor})
        stored[(a, b)] = factor
        out(f"{label}: created")

    return problems


def main(argv=None):
    """Parse arguments, run the seed, and return the exit status."""
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--base-url", required=True, help="the instance's root URL, including any base path")
    parser.add_argument("--system", required=True, choices=sorted(SYSTEMS), help="which measurement system's units to load")
    parser.add_argument("--data", default=DEFAULT_DATA, help=f"unit data file (default: {DEFAULT_DATA.name})")
    parser.add_argument("--dry-run", action="store_true", help="report what would be created; write nothing")
    args = parser.parse_args(argv)

    api_key = os.environ.get("VICTUAL_API_KEY")
    if not api_key:
        print("VICTUAL_API_KEY is not set", file=sys.stderr)
        return 1
    try:
        units = select_units(load_units(args.data), args.system)
        problems = seed(Api(args.base_url, api_key), units, args.dry_run)
    except SeedError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1
    if problems:
        print(f"{problems} conversion(s) disagree with the data file", file=sys.stderr)
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
