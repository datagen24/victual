"""seed_units.py plans exact factors from the shipped data file for each --system choice, and seeds against a fake API the
way the real one behaves: the database writes each conversion's inverse, and a rerun writes
nothing.
"""
import itertools
import unittest

import seed_units as su


class FakeApi:
    """In-memory /objects for quantity_units and quantity_unit_conversions, with the inverse
    trigger and the duplicate-conversion refusal the database has."""

    def __init__(self, units=(), conversions=()):
        self.rows = {"quantity_units": [], "quantity_unit_conversions": []}
        self.posts = []
        for unit in units:
            self._insert("quantity_units", dict(unit))
        for conversion in conversions:
            self._insert("quantity_unit_conversions", dict(conversion))

    def _insert(self, entity, row):
        row["id"] = len(self.rows[entity]) + 1
        self.rows[entity].append(row)
        return row["id"]

    def list(self, entity):
        return [dict(row) for row in self.rows[entity]]

    def create(self, entity, row):
        self.posts.append((entity, row))
        if entity == "quantity_units":
            if any(r["name"] == row["name"] for r in self.rows[entity]):
                raise su.SeedError("duplicate unit")
            return self._insert(entity, dict(row))
        if row["factor"] <= 0:
            raise su.SeedError("factor CHECK")
        for r in self.rows[entity]:
            if (r["from_qu_id"], r["to_qu_id"], r.get("product_id")) == (row["from_qu_id"], row["to_qu_id"], None):
                raise su.SeedError("QU conversion already exists")
        new_id = self._insert(entity, dict(row, product_id=None))
        self._insert(entity, {"from_qu_id": row["to_qu_id"], "to_qu_id": row["from_qu_id"], "factor": 1 / row["factor"], "product_id": None})
        return new_id


def factor(plan, a, b):
    """The planned factor for 1 a = ? b, whichever direction the plan stored it in."""
    for f, t, x in plan:
        if (f, t) == (a, b):
            return x
        if (f, t) == (b, a):
            return 1 / x
    raise KeyError((a, b))


class ShippedData(unittest.TestCase):
    def setUp(self):
        self.all_units = su.load_units(su.DEFAULT_DATA)
        self.units = su.select_units(self.all_units, "both")
        self.plan = su.plan_conversions(self.units)

    def names(self, system):
        return {u["name"] for u in su.select_units(self.all_units, system)}

    def test_each_system_loads_its_own_units_and_the_common_ones(self):
        us, metric, both = self.names("us"), self.names("metric"), self.names("both")
        self.assertEqual(us | metric, both)
        self.assertEqual(us & metric, {"Piece", "Dozen", "Pack", "Can", "Bottle", "Jar", "Box", "Bag", "Carton", "Bunch", "Loaf"})
        self.assertTrue({"Cup", "Ounce", "Pound", "Stick"} <= us - metric)
        self.assertTrue({"Milliliter", "Liter", "Gram", "Kilogram"} <= metric - us)

    def test_conversion_counts_per_system(self):
        counts = {s: len(su.plan_conversions(su.select_units(self.all_units, s))) for s in su.SYSTEMS}
        # us: 7 volume units (21 pairs), 2 weight (1), Piece/Dozen (1); metric: 1 + 1 + 1;
        # both: 9 volume (36), 4 weight (6), 1.
        self.assertEqual(counts, {"us": 23, "metric": 3, "both": 43})

    def test_only_both_links_the_systems(self):
        for system in ("us", "metric"):
            for f, t, _ in su.plan_conversions(su.select_units(self.all_units, system)):
                self.assertFalse({f, t} & {"Cup", "Ounce"} and {f, t} & {"Gram", "Milliliter"}, (system, f, t))

    def test_kitchen_factors_are_exact(self):
        cases = [
            ("Tablespoon", "Teaspoon", 3), ("Fluid ounce", "Tablespoon", 2), ("Cup", "Fluid ounce", 8),
            ("Cup", "Tablespoon", 16), ("Cup", "Teaspoon", 48), ("Pint", "Cup", 2), ("Quart", "Pint", 2),
            ("Gallon", "Quart", 4), ("Gallon", "Cup", 16), ("Gallon", "Teaspoon", 768),
            ("Pound", "Ounce", 16), ("Kilogram", "Gram", 1000), ("Liter", "Milliliter", 1000), ("Dozen", "Piece", 12),
        ]
        for a, b, expected in cases:
            with self.subTest(a=a, b=b):
                self.assertEqual(factor(self.plan, a, b), expected)

    def test_metric_bridges_match_nist(self):
        self.assertEqual(factor(self.plan, "Pound", "Gram"), 453.59237)
        self.assertEqual(factor(self.plan, "Gallon", "Liter"), 3.785411784)
        self.assertAlmostEqual(factor(self.plan, "Ounce", "Gram"), 28.349523125, places=12)

    def test_every_pair_in_a_dimension_once_and_never_across(self):
        dimension = {u["name"]: u.get("dimension") for u in self.units}
        pairs = [frozenset((f, t)) for f, t, _ in self.plan]
        self.assertEqual(len(pairs), len(set(pairs)))
        for f, t, x in self.plan:
            self.assertEqual(dimension[f], dimension[t])
            self.assertGreaterEqual(x, 1)
        for d in {v for v in dimension.values() if v}:
            names = [n for n, v in dimension.items() if v == d]
            for a, b in itertools.combinations(names, 2):
                self.assertIn(frozenset((a, b)), pairs)
        self.assertNotIn(frozenset(("Ounce", "Fluid ounce")), pairs)

    def test_factors_stay_far_inside_the_952_range(self):
        for _, _, x in self.plan:
            self.assertTrue(2.0 ** -1022 <= x <= 2.0 ** 1022)


class Seeding(unittest.TestCase):
    units = [
        {"name": "Cup", "name_plural": "Cups", "dimension": "volume", "size": "236.5882365"},
        {"name": "Tablespoon", "dimension": "volume", "size": "14.78676478125"},
        {"name": "Teaspoon", "dimension": "volume", "size": "4.92892159375"},
        {"name": "Can"},
    ]

    def run_seed(self, api, dry_run=False):
        lines = []
        problems = su.seed(api, self.units, dry_run, out=lines.append)
        return problems, lines

    def test_empty_instance_gets_units_and_one_direction_per_pair(self):
        api = FakeApi()
        problems, _ = self.run_seed(api)
        self.assertEqual(problems, 0)
        self.assertEqual(sorted(r["name"] for r in api.rows["quantity_units"]), ["Can", "Cup", "Tablespoon", "Teaspoon"])
        posted = [r for e, r in api.posts if e == "quantity_unit_conversions"]
        self.assertEqual(len(posted), 3)
        self.assertEqual(len(api.rows["quantity_unit_conversions"]), 6)
        self.assertNotIn("dimension", api.posts[0][1])
        self.assertEqual(api.posts[0][1]["name_plural"], "Cups")

    def test_second_run_writes_nothing(self):
        api = FakeApi()
        self.run_seed(api)
        api.posts.clear()
        problems, lines = self.run_seed(api)
        self.assertEqual((problems, api.posts), (0, []))
        self.assertTrue(all(line.endswith(": exists") for line in lines))

    def test_existing_unit_is_reused_case_insensitively(self):
        api = FakeApi(units=[{"name": "cup"}])
        _, lines = self.run_seed(api)
        self.assertIn("unit Cup: exists (as 'cup')", lines)
        self.assertEqual(sum(1 for e, _ in api.posts if e == "quantity_units"), 3)

    def test_inverse_direction_counts_as_present(self):
        api = FakeApi(
            units=[{"name": "Cup"}, {"name": "Tablespoon"}],
            conversions=[{"from_qu_id": 2, "to_qu_id": 1, "factor": 1 / 16, "product_id": None}],
        )
        problems, lines = self.run_seed(api)
        self.assertEqual(problems, 0)
        self.assertIn("conversion 1 Cup = 16 Tablespoon: exists", lines)

    def test_disagreeing_factor_is_reported_and_left(self):
        api = FakeApi(
            units=[{"name": "Cup"}, {"name": "Tablespoon"}],
            conversions=[{"from_qu_id": 1, "to_qu_id": 2, "factor": 15, "product_id": None}],
        )
        problems, lines = self.run_seed(api)
        self.assertEqual(problems, 1)
        self.assertIn("conversion 1 Cup = 16 Tablespoon: EXISTS WITH 15, left unchanged", lines)
        self.assertEqual(api.rows["quantity_unit_conversions"][0]["factor"], 15)

    def test_product_conversion_does_not_count_as_global(self):
        api = FakeApi(
            units=[{"name": "Cup"}, {"name": "Tablespoon"}],
            conversions=[{"from_qu_id": 1, "to_qu_id": 2, "factor": 15, "product_id": 7}],
        )
        problems, _ = self.run_seed(api)
        self.assertEqual(problems, 0)

    def test_dry_run_writes_nothing(self):
        api = FakeApi()
        problems, lines = self.run_seed(api, dry_run=True)
        self.assertEqual((problems, api.posts), (0, []))
        self.assertEqual(sum(1 for line in lines if line.endswith("would create")), 7)


class DataChecks(unittest.TestCase):
    def check(self, units):
        import json
        import tempfile
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as handle:
            json.dump({"units": units}, handle)
        return su.load_units(handle.name)

    def test_refuses_duplicate_bad_system_size_without_dimension_and_zero_size(self):
        for units in (
            [{"name": "Cup", "system": "us"}, {"name": "cup", "system": "us"}],
            [{"name": "Cup"}],
            [{"name": "Cup", "system": "imperial"}],
            [{"name": "Cup", "system": "us", "size": "1"}],
            [{"name": "Cup", "system": "us", "dimension": "volume", "size": "0"}],
        ):
            with self.subTest(units=units), self.assertRaises(su.SeedError):
                self.check(units)


if __name__ == "__main__":
    unittest.main()
