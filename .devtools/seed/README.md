# Master data seeds

`seed_units.py` loads quantity units and the global conversions between them into a running
instance through the REST API. `quantity-units.json` is the data it loads: US customary units,
metric units, and units every household uses, such as Piece and the container units.

A new installation has two units, Piece and Pack, and no conversions. Run the seed once after
the first login to give the household a working set.

## Choosing a system

`--system` is required and picks which units to load:

| `--system` | For | Loads |
|---|---|---|
| `us` | A household that uses US customary units only | US customary units and the common units |
| `metric` | A household that uses metric units only | Metric units and the common units |
| `both` | A household that uses both, as in the northern US and Canada | All of them, with conversions between the two systems |

Only `both` links the systems: a cup converts to milliliters and a pound to grams. A household
that started with `us` or `metric` can run `both` later. That adds the other system's units and
the conversions between the two, and keeps what is already there.

## Running it

The API key must belong to an account with the `MASTER_DATA_EDIT` permission, such as `admin`.
Create one under **Manage API keys** after the first login's password change. Pass it in the
environment so it stays out of shell history and process listings:

```bash
read -rs VICTUAL_API_KEY && export VICTUAL_API_KEY
```

Check first. The dry run reads the instance and writes nothing:

```bash
.devtools/seed/seed_units.py --base-url https://victual.example --system both --dry-run
```

Then seed:

```bash
.devtools/seed/seed_units.py --base-url https://victual.example --system both
```

`--base-url` is the instance's root URL, including any `VICTUAL_BASE_PATH`. The script needs
Python 3 and nothing else.

## What it writes

| System | Dimension | Units |
|---|---|---|
| `us` | Volume | Teaspoon, Tablespoon, Fluid ounce, Cup, Pint, Quart, Gallon |
| `us` | Weight | Ounce, Pound |
| `us` | None | Stick (butter) |
| `metric` | Volume | Milliliter, Liter |
| `metric` | Weight | Gram, Kilogram |
| Common | Count | Piece, Dozen |
| Common | None | Pack, Can, Bottle, Jar, Box, Bag, Carton, Bunch, Loaf |

Each loaded unit with a dimension gets a conversion to every other loaded unit of that
dimension. That is 23 conversions for `us`, 3 for `metric`, and 43 for `both`.

Sizes are the NIST definitions: 1 US fluid ounce is 29.5735295625 mL and 1 avoirdupois pound
is 453.59237 g. Each factor is computed from those sizes as an exact fraction and rounded once.

Every pair in a dimension gets its own conversion. The resolver in
`quantity_unit_conversions_resolved` multiplies factors along a chain and uses the shortest
path, so a direct conversion is the exact one. With every pair direct, the resolver does not
build chains.

The script writes each pair once, from the larger unit to the smaller. The database adds the
reverse conversion itself, as `1 / factor`.

Volume and weight are not converted into each other. A cup of flour and a cup of water weigh
different amounts, so that conversion belongs on the product. The units with no dimension
have no conversions for the same reason. Ounce is weight and Fluid ounce is volume; keep them
apart when you set up a product.

The metric system has no teaspoon, tablespoon or cup. The metric measures (5 mL, 15 mL,
250 mL) are within 2% of the US ones, and a second unit named Cup cannot exist alongside the
US one. A metric household that measures in spoons and cups can add them by hand.

## Running it again

The script only adds rows:

- A unit whose name already exists, in any letter case, is used as it is.
- A conversion that exists in either direction is left alone.
- A conversion whose stored factor differs from the data file is reported, and the script
  exits 1. Changing a factor rescales every product that uses it, so the script never does it.

A second run with the same `--system` writes nothing and exits 0.

## Tests

```bash
python3 -m unittest discover -s .devtools/seed
```

The tests check the shipped factors against known values and the units each `--system` choice
loads. They also run the seed against an in-memory stand-in for the API that adds reverse
conversions the way the database does. The `lint` job in `tests.yml` runs them.
