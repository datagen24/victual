# Artifact format: a raster against a page description

ADR-0021 acceptance prerequisite 2. Written 2026-09-07 against the two device families this
deployment has — a Brother QL-820NWBc and a networked laser — and against the renderer spike
that produces the artifact.

**BLUF.** Wave 3b ships **one** artifact form: an indexed raster whose pixel grid is fixed by
the resolved combination. Not because a page description is worse in general, but because the
QL-820NWBc is the device wave 3b must print on and it advertises no page-description format on
either of its transports, so a page description reaches it only through a converting host that
this deployment does not have. `pdf/1.4` stays registerable and is what a laser-only deployment
would add. The retention argument usually made for page descriptions does not survive
measurement: the two forms are within a factor of 1.6 once the raster is encoded as the form
identifier says it is.

## 1. What each family can actually take

| | Brother QL-820NWBc | Networked laser |
|---|---|---|
| Raw 9100 | Native raster commands. **Demonstrated**: two-colour on `62red` at 300 dpi, printed and scanned | not used |
| IPP `document-format-supported` | `application/octet-stream`, `image/urf` — **no page-description format at all** | `application/pdf`, PostScript, PCL-XL, URF |
| IPP, demonstrated | The native raster stream as `application/octet-stream`: `job-completed-successfully`, 1 impression | `application/pdf`: `job-completed-successfully`, 1 impression, ruler and box measure true, QR scans off paper |
| Completion evidence | `none` over 9100 (no reply to a status request); `device_reported` over IPP | `device_reported` |

The QL's `application/octet-stream` is a passthrough to the print engine, not a format the
device parses. Handing it a PDF would hand the raster engine a PDF. That is not a claim this
spike tested — the QL was powered down when the comparison was written — and it is recorded
here as **advertised only**, on the same rule the capability contract now applies to every row.

## 2. Geometry: what each form leaves the adapter to decide

This is the substantive difference, and it maps exactly onto the `geometry` key ADR-0019's
`artifact_forms` now carries.

**`fixed_grid`** — Victual computes the pixel grid from the resolved combination's
`printable_width_um` and `resolution_x`/`resolution_y`, the renderer emits exactly that grid,
and the worker scales nothing. A grid that does not match the combination is a refusal, not a
resize. Nothing downstream decides anything, which is the whole point:
issue [#90](https://github.com/datagen24/victual/issues/90) is a geometry defect produced by
two components disagreeing about which dot count a dimension meant, and a form that fixes the
grid removes the disagreement rather than documenting it.

**`device_placed`** — the artifact carries physical units and the device rasterizes at its own
resolution, applies its own unprintable margins, and follows its own scaling policy. On the
laser this was demonstrated to be *correct*: a 100 mm ruler and a 50.0 × 30.0 mm box measured
true off the page. That is a fact about that device's interpretation, not about the form.

## 3. Conversion, and whether the evidence is readable

The prerequisite asks whether a downstream service exists that verifiably converts **and**
delivers with readable evidence.

- **For the laser, yes, and it is the device itself.** It accepts PDF, rasterizes internally,
  and reports `job-state` and `job-impressions-completed`. Demonstrated twice — gate 5's page
  and again as ADR-0021 prerequisite 4's reprint (IPP job 56, `job-completed-successfully`,
  one impression).
- **For the QL, no.** Neither transport offers one. Raw 9100 has no format negotiation and
  returns nothing at all; IPP advertises no page-description format. A converter is therefore
  an additional host, and the completion evidence would then be that host's claim about what
  it did rather than the printer's about what it printed — a different statement about a
  different component, which is exactly the distinction ADR-0019's `completion_evidence` scope
  exists to keep.
- **Shipping the converter has a price** measured against ADR-0013's no-shell requirement; see
  section 6.

## 4. Size, measured rather than assumed

The same label — QR of `vctl:0123456789ABC`, the name "Pantry top shelf", the red accent rule —
at 62 mm on the Brother profile, 696 × 543 device pixels at 300 × 600 dpi.

| Form | Bytes |
|---|---|
| Raster as the spike renderer emits it (RGBA PNG) | 37,145 |
| Raster as the form identifier says (2-bit indexed PNG, profile palette) | **2,573** |
| Page description (PDF 1.4, vector, uncompressed streams) | 12,046 |
| Page description, Flate-compressed streams | **1,573** |

Compared honestly — each form encoded the way it would actually ship — the raster is **1.6×**
the page description, not the order of magnitude it is usually charged with. At ten thousand
retained artifacts that is 25 MB against 16 MB, which decides nothing.

Two findings fall out of this table and both belong in [plan 27](../docs/plans/27-label-templates-and-rendering.md):

1. **The renderer must emit indexed PNG, not RGBA.** tiny-skia's `save_png` writes RGBA8, which
   is 14× the size of the same pixels indexed, and it makes the artifact not be what
   `raster/png-indexed;v=1` names. The re-encode is lossless — the decoded palette counts match
   the renderer's own reported counts exactly, 38,439 black / 0 red / 339,489 white.
2. **The form identifier has to pin colour type and bit depth**, not just "PNG". "The same
   form" otherwise covers artifacts that differ by more than an order of magnitude in size and
   by whether the worker has to quantise.

## 5. Reprint fidelity, which is the argument that actually decides it

ADR-0021 decision item 2 makes a reprint a replay of retained bytes. What "the same bytes"
guarantees differs by form:

- Under `fixed_grid` the bytes **are** the dots. Replaying them reproduces the label, on that
  device or a replacement of the same combination.
- Under `device_placed` the bytes are *instructions*. The same bytes through a firmware update,
  a different tray, or a device whose scaling policy differs produce a different page. Nothing
  in the artifact records which interpretation produced the first one.

For a label whose function is to be scanned and to match a shelf for years, "the same dots" is
the stronger of the two guarantees, and it is the one the reprint contract implies. This does
not disqualify page descriptions — it means an exact reprint means something weaker for them,
and a deployment that registers `pdf/1.4` is accepting that.

## 6. What shipping a converter would cost

Recorded because the alternative to "the QL cannot take a page description" is "Victual
converts one for it", and that alternative has a measurable price against ADR-0013.

`convert-closure.nix` takes the closure of `cups`, `cups-filters` and `ghostscript` from this
flake's pinned nixpkgs, on `aarch64-linux`, using the same `closureInfo` the image checks use.
Measured 2026-09-07:

| | Conversion path | The Rust label worker |
|---|---|---|
| Closure | **419,574,616 bytes** | 62,644,200 bytes |
| Store paths | **119** | 7 |
| Shell or interpreter paths | **2** — `bash-5.3p15` and `bash-interactive-5.3p15` | **0** |

Six and a half times the size, seventeen times the paths, and it fails
[ADR-0013](../docs/adr/0013-nix-images.md)'s no-shell assertion outright — the check added with
gate 1 rejects `bash-interactive-5.3p15/bin/sh` by name, because that is precisely the string
its negative control was built to catch.

The comparison is not only size. The previous packaging spike spent its whole length getting
**one** Python interpreter shell-free and failed, until the worker was rewritten in Rust. A
CUPS filter chain is a larger version of that problem, in a component that would sit in the
delivery path of every label.

## 7. Recommendation

**Ship one form in wave 3b.**

```json
{
  "form": "raster/png-indexed;v=1",
  "geometry": "fixed_grid",
  "encoding": {"png_colour_type": 3, "png_bit_depth": 2, "palette": "profile.pixel_policy.palette"}
}
```

`pdf/1.4` with `geometry: device_placed` remains expressible and unshipped. A laser-only
deployment adds it by registering a driver that advertises it; nothing above has to change for
that to work, which is what ADR-0019's `artifact_forms` key was added for.

**Neither of ADR-0019's two format-dependent edits depended on this comparison**, which is why
they were written first. The job payload carries a reference, a `form` identifier and the
resolved combination; the claim precondition compares the form against the worker's
`artifact_forms` for that combination. Both are stated over form identifiers, so choosing which
forms exist does not change either.
