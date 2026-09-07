"""Right-to-left check: expected shaping comes from the font's own GSUB and hmtx tables,
read by fontTools; the renderer is asked only where it put ink.

Two independent expectations, neither of them "whatever the renderer does":
  * Hebrew is strong right-to-left, so the FIRST logical character must be the RIGHTMOST
    ink cluster. The two letters chosen have very different ink widths, so which is which
    is decidable from pixels alone.
  * Arabic beh joins on both sides. In a two-letter word the first takes the initial form
    and the second the final form, and they connect -- so the advance is the sum of those
    two forms' advances, not twice the isolated form's, and the ink is one cluster, not two.
    A zero-width non-joiner between them must restore the isolated forms and the gap.
"""
import json
import subprocess
import sys
from fontTools.ttLib import TTFont

FONT = sys.argv[1]
BIN = ".spike-renderer/rsrender/target/release/rsrender"
SIZE_PX = 300.0

MEM = "ם"      # HEBREW LETTER FINAL MEM  -- wide
YOD = "י"      # HEBREW LETTER YOD        -- narrow
BEH = "ب"      # ARABIC LETTER BEH        -- joins both sides
ZWNJ = "‌"

font = TTFont(FONT, fontNumber=0)
upem = font["head"].unitsPerEm
scale = SIZE_PX / upem
cmap = font.getBestCmap()
hmtx = font["hmtx"]
glyf = font["glyf"]
gsub = font["GSUB"].table


def substituted(glyph, feature_tag):
    """The glyph `glyph` becomes under a single-substitution lookup of `feature_tag`."""
    lookups = set()
    for fr in gsub.FeatureList.FeatureRecord:
        if fr.FeatureTag == feature_tag:
            lookups.update(fr.Feature.LookupListIndex)
    for li in sorted(lookups):
        lk = gsub.LookupList.Lookup[li]
        subs = [st.ExtSubTable for st in lk.SubTable] if lk.LookupType == 7 else list(lk.SubTable)
        for st in subs:
            mapping = getattr(st, "mapping", None)
            if mapping and glyph in mapping:
                return mapping[glyph]
    return None


def probe(text):
    out = subprocess.run(
        [BIN, "--probe", text, "--probe-px", str(SIZE_PX), "--fonts", FONT, "--out", ""],
        capture_output=True, text=True, check=True)
    return json.loads(out.stdout)


def ink_units(ch):
    g = cmap[ord(ch)]
    gl = glyf[g]
    return (gl.xMax - gl.xMin) * scale


failures = 0


def check(label, ok, detail):
    global failures
    failures += 0 if ok else 1
    print(f"  {'ok  ' if ok else 'FAIL'} {label:<52} {detail}")


print(f"font {FONT}, unitsPerEm={upem}, size={SIZE_PX}px\n")

print("Hebrew: the first logical character must end up rightmost")
pair = probe(MEM + YOD)
clusters = pair["clusters"]
print(f"  clusters: {clusters}")
check("two ink clusters", len(clusters) == 2, f"{len(clusters)}")
if len(clusters) == 2:
    left = clusters[0][1] - clusters[0][0] + 1
    right = clusters[1][1] - clusters[1][0] + 1
    exp_mem, exp_yod = ink_units(MEM), ink_units(YOD)
    print(f"  expected ink from glyf: final mem {exp_mem:.1f}px, yod {exp_yod:.1f}px")
    print(f"  measured: left cluster {left}px, right cluster {right}px")
    check("rightmost cluster is the final mem (logical first)",
          abs(right - exp_mem) <= 2, f"{right} vs {exp_mem:.1f}")
    check("leftmost cluster is the yod (logical second)",
          abs(left - exp_yod) <= 2, f"{left} vs {exp_yod:.1f}")
    check("left is narrower than right, i.e. order reversed",
          left < right, f"{left} < {right}")

print("\nArabic: beh joins, and a ZWNJ stops it")
g_isol = cmap[ord(BEH)]
g_init = substituted(g_isol, "init")
g_fina = substituted(g_isol, "fina")
adv_isol, adv_init, adv_fina = (hmtx[g][0] for g in (g_isol, g_init, g_fina))
print(f"  glyphs: isol={g_isol}({adv_isol}) init={g_init}({adv_init}) fina={g_fina}({adv_fina})")
joined_expected = (adv_init + adv_fina) * scale
isolated_expected = (adv_isol * 2) * scale
joined = probe(BEH + BEH)
split = probe(BEH + ZWNJ + BEH)
print(f"  joined  : advance {joined['ink_width']:.2f}, clusters {joined['clusters']}")
print(f"  with ZWNJ: advance {split['ink_width']:.2f}, clusters {split['clusters']}")
check("joined advance equals init+fina from GSUB",
      abs(joined["ink_width"] - joined_expected) < 0.5,
      f"{joined['ink_width']:.2f} vs {joined_expected:.2f}")
check("joined advance is not twice the isolated form",
      abs(joined["ink_width"] - isolated_expected) > 1.0,
      f"{joined['ink_width']:.2f} vs {isolated_expected:.2f}")
check("joined letters make one ink cluster", joined["cluster_count"] == 1,
      f"{joined['cluster_count']}")
check("a ZWNJ restores the isolated advance",
      abs(split["ink_width"] - isolated_expected) < 0.5,
      f"{split['ink_width']:.2f} vs {isolated_expected:.2f}")
check("a ZWNJ restores the gap", split["cluster_count"] == 2,
      f"{split['cluster_count']}")

print()
sys.exit(1 if failures else 0)
