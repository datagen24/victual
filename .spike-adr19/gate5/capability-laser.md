# Gate 5, second family — the networked laser, from verified attributes

Not from a datasheet. Every value below was read from the device on 2026-09-07 with
`ipptool` Get-Printer-Attributes against `ipp://10.130.30.13/ipp/print`, and the format claim
was then confirmed by printing.

```
printer-make-and-model       = Xerox Phaser 6600DN
document-format-supported    = application/octet-stream, application/postscript,
                               application/pdf, image/urf, application/vnd.hp-PCL,
                               application/vnd.hp-PCLXL, image/tiff
printer-resolution-supported = 600dpi
color-supported              = true
output-mode-supported        = color, monochrome, auto
media-supported              = iso_a4, iso_b5, iso_a5, na_letter, na_legal, oe_folio,
                               na_executive, na_number-10, na_monarch, iso_dl, iso_c5,
                               custom_min_76x127mm, custom_max_215.9x356mm
media-type-supported         = auto, stationery, ..., labels, envelope, ...
urf-supported                = ... RS600 ...
```

**IPP alone did not establish PDF support, and this is what did.** `document-format-supported`
advertises `application/pdf`; a `Print-Job` carrying `document-format application/pdf` and a
16 KB vector PDF was accepted (`successful-ok`, job 54) and reached `job-state = completed`,
`job-state-reasons = job-completed-successfully`, `job-impressions-completed = 1`.

## The capability document

```json
{
  "contract_version": 1,
  "driver_id": "ipp.everywhere",
  "schema_version": "1.0",
  "connection_types": ["ipp"],
  "models": ["Xerox Phaser 6600DN"],
  "completion_evidence": "device_reported",
  "artifact_forms": [
    {"form": "pdf/1.4",           "applies_to": ["*"]},
    {"form": "postscript/3",      "applies_to": ["*"]},
    {"form": "raster/urf;rs=600", "applies_to": ["*"]},
    {"form": "pcl/xl",            "applies_to": ["*"]}
  ],
  "combinations": [
    {"model": "Xerox Phaser 6600DN", "media": "na_letter_8.5x11in",
     "resolution_x": 600, "resolution_y": 600, "color_mode": "color",
     "geometry": {"printable_width_um": 211900, "printable_length_um": {"fixed": 275300},
                  "margins_um": 4100, "feed_direction": "y"}},
    {"model": "Xerox Phaser 6600DN", "media": "na_letter_8.5x11in",
     "resolution_x": 600, "resolution_y": 600, "color_mode": "mono",
     "geometry": {"printable_width_um": 211900, "printable_length_um": {"fixed": 275300},
                  "margins_um": 4100, "feed_direction": "y"}},
    {"model": "Xerox Phaser 6600DN", "media": "custom",
     "resolution_x": 600, "resolution_y": 600, "color_mode": "color",
     "geometry": {"printable_width_um": {"min": 76000, "max": 215900},
                  "printable_length_um": {"min": 127000, "max": 356000},
                  "margins_um": 4100, "feed_direction": "y"}}
  ]
}
```

## What this family exercises, and what it cannot

| Contract stress | Brother QL | This laser |
|---|---|---|
| `artifact_forms` — a page description against a raster | `raster/png-indexed` only | **`pdf/1.4`, `postscript/3`, `pcl/xl`, `raster/urf`** — verified by printing |
| `completion_evidence` | `transport` at best over raw TCP | **`device_reported`** — verified: state `completed`, impressions 1 |
| A dimension expressed as a range | endless tape length | **custom media, 76×127 mm to 215.9×356 mm** |
| Asymmetric horizontal/vertical resolution | 300 × 600 | **Not present** — 600 dpi only |
| A colour mode on only some combinations | `black_red` on `62red` alone | **Not present** — colour on every combination |

**No pair of devices in this deployment exercises all three of gate 5's stresses**, and the
record should say so rather than imply the pair covers them. Asymmetric resolution and
conditional colour are Brother-only and verified there; the laser verifies the two things the
Brother cannot — a page-description input format and a device-reported completion — which are
precisely what `artifact_forms` and `completion_evidence` were added and defined for.

## The physical half, which needs a person

The printed page carries three measurable things, and the numbers are what the artifact
claims: a **100 mm ruler** with 10 mm ticks, a box exactly **50.0 × 30.0 mm**, and a QR of
**33 × 33 modules at 1.0 mm**, so 33.0 mm square. The QR decoded to `vctl:0123456789ABC` from
the pre-print render. Whether it decodes off paper, and whether the ruler and box measure true,
is the check a person makes with a ruler and a scanner.
