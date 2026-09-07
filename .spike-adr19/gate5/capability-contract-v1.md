# Gate 5 — capability contract v1, expressed for two real driver families

Version 1 keys, per ADR-0019 decision item 3: `connection_types`, `models`, `combinations`,
`completion_evidence`. `combinations` is a list, not the product of several lists.

## Family A — `brother.ql`

```json
{
  "contract_version": 1,
  "driver_id": "brother.ql",
  "schema_version": "1.0",
  "connection_types": ["tcp", "usb"],
  "models": ["QL-700", "QL-800", "QL-810W", "QL-820NWB", "QL-1100"],
  "completion_evidence": "transport",
  "combinations": [
    {"model": "QL-820NWB", "media": "62",     "resolution_x": 300, "resolution_y": 300,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 696, "printable_width_mm": 58.9,
                  "length": {"kind": "continuous", "min_mm": 12.7, "max_mm": 1000.0, "increment_mm": 0.085}}},

    {"model": "QL-820NWB", "media": "62",     "resolution_x": 300, "resolution_y": 600,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 696, "printable_width_mm": 58.9,
                  "length": {"kind": "continuous", "min_mm": 12.7, "max_mm": 1000.0, "increment_mm": 0.042}}},

    {"model": "QL-820NWB", "media": "62red",  "resolution_x": 300, "resolution_y": 300,
     "color_mode": "black_red",
     "geometry": {"raster_width_px": 696, "printable_width_mm": 58.9,
                  "length": {"kind": "continuous", "min_mm": 12.7, "max_mm": 1000.0, "increment_mm": 0.085}}},

    {"model": "QL-820NWB", "media": "62x100", "resolution_x": 300, "resolution_y": 300,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 696, "printable_width_mm": 58.9,
                  "length": {"kind": "die_cut", "fixed_mm": 100.0}}},

    {"model": "QL-700",    "media": "62",     "resolution_x": 300, "resolution_y": 300,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 696, "printable_width_mm": 58.9,
                  "length": {"kind": "continuous", "min_mm": 12.7, "max_mm": 1000.0, "increment_mm": 0.085}}}
  ]
}
```

The three stresses the gate names are all present and all load-bearing: a **continuous length
range** with an increment; an **asymmetric resolution** (300 × 600, where the increment halves
because the feed step does); and a **colour mode on only some combinations** — `black_red`
appears for `QL-820NWB` on `62red` and nowhere else, and `QL-700` has no red row at any media.
A driver that published `color_modes: ["mono","black_red"]` as its own list would claim red on
plain 62 tape, which is the failure the list-of-combinations shape exists to prevent.

## Family B — `zebra.zpl`

```json
{
  "contract_version": 1,
  "driver_id": "zebra.zpl",
  "schema_version": "1.0",
  "connection_types": ["tcp", "usb", "cups"],
  "models": ["ZD421", "ZT411"],
  "completion_evidence": "device_reported",
  "combinations": [
    {"model": "ZD421", "media": "continuous-4in", "resolution_x": 203, "resolution_y": 203,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 812, "printable_width_mm": 101.6,
                  "length": {"kind": "continuous", "min_mm": 6.35, "max_mm": 991.0, "increment_mm": 0.125}}},

    {"model": "ZD421", "media": "diecut-4x6",     "resolution_x": 203, "resolution_y": 203,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 812, "printable_width_mm": 101.6,
                  "length": {"kind": "die_cut", "fixed_mm": 152.4}}},

    {"model": "ZT411", "media": "continuous-4in", "resolution_x": 300, "resolution_y": 300,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 1200, "printable_width_mm": 101.6,
                  "length": {"kind": "continuous", "min_mm": 6.35, "max_mm": 991.0, "increment_mm": 0.085}}},

    {"model": "ZT411", "media": "continuous-4in", "resolution_x": 600, "resolution_y": 600,
     "color_mode": "mono",
     "geometry": {"raster_width_px": 2400, "printable_width_mm": 101.6,
                  "length": {"kind": "continuous", "min_mm": 6.35, "max_mm": 991.0, "increment_mm": 0.042}}}
  ]
}
```

Its settings document carries `x-zebra.zpl.darkness` and `x-zebra.zpl.tear_off_offset` as
namespaced extensions, which a generic template ignores.

## What the exercise shows is missing

**`artifact_forms`.** Every key in version 1 describes the *device* — what it is, what it
holds, what it can report. Nothing describes **what the driver accepts as input**, and the two
families differ there in a way that is not cosmetic: `brother.ql` takes a raster it converts to
Brother's raster command stream, while `zebra.zpl` natively takes **ZPL, a page-description
language**, and a raster reaches it only wrapped in a `^GF` command. A worker advertising
`zebra.zpl` cannot say which of those it wants, and Victual cannot refuse a job whose artifact
is the wrong form, so the mismatch would surface as a failed print rather than as a refusal at
enqueue — the exact outcome decision item 3's last paragraph exists to prevent.

Proposed amendment, before acceptance, per the gate's own rule that a missing key amends the
contract rather than being noted:

```json
"artifact_forms": ["raster/png-indexed"]        // brother.ql
"artifact_forms": ["zpl/2", "raster/png-indexed"] // zebra.zpl
```

with the job's artifact naming its form, and a claim precondition requiring the worker to
advertise it. This is also the key that makes ADR-0021's open raster-versus-page-description
question expressible rather than a fork in the road: a deployment can carry both, and the
capability document says which printers can take which.
