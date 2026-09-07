"""Kerning check: the expected advance comes from the font's own tables, read by fontTools,
and is compared against the ink the renderer actually put on the canvas.

The point is independence. fontTools walks GPOS; the renderer shapes with rustybuzz. If a
pair's ink width matches the kerned prediction and not the unkerned one, the renderer applied
the font's kerning -- and neither program was asked to agree with the other."""
import json
import subprocess
import sys
from fontTools.ttLib import TTFont

FONT = ".spike-renderer/fonts/NotoSans-Regular.ttf"
BIN = ".spike-renderer/rsrender/target/release/rsrender"
SIZE_PX = 500.0

font = TTFont(FONT)
upem = font["head"].unitsPerEm
cmap = font.getBestCmap()
hmtx = font["hmtx"]
glyf = font["glyf"]
gpos = font["GPOS"].table

kern_lookups = set()
for fr in gpos.FeatureList.FeatureRecord:
    if fr.FeatureTag == "kern":
        kern_lookups.update(fr.Feature.LookupListIndex)


def kern(g1, g2):
    total = 0
    for li in sorted(kern_lookups):
        lk = gpos.LookupList.Lookup[li]
        subs = [st.ExtSubTable for st in lk.SubTable] if lk.LookupType == 9 else list(lk.SubTable)
        for st in subs:
            if getattr(st, "PairSet", None) is not None:
                cov = st.Coverage.glyphs
                if g1 in cov:
                    for pvr in st.PairSet[cov.index(g1)].PairValueRecord:
                        if pvr.SecondGlyph == g2:
                            total += getattr(pvr.Value1, "XAdvance", 0) or 0
            elif getattr(st, "ClassDef1", None) is not None:
                cov = st.Coverage.glyphs
                if g1 in cov:
                    c1 = st.ClassDef1.classDefs.get(g1, 0)
                    c2 = st.ClassDef2.classDefs.get(g2, 0)
                    rec = st.Class1Record[c1].Class2Record[c2]
                    total += getattr(rec.Value1, "XAdvance", 0) or 0
    return total


def probe(text):
    out = subprocess.run(
        [BIN, "--probe", text, "--probe-px", str(SIZE_PX), "--fonts", FONT, "--out", ""],
        capture_output=True, text=True, check=True)
    return json.loads(out.stdout)


scale = SIZE_PX / upem
failures = 0
print(f"font {FONT}, unitsPerEm={upem}, size={SIZE_PX}px, 1 unit = {scale} px\n")
print(f"{'pair':>6} {'kern':>6} {'predicted':>10} {'unkerned':>10} {'measured':>10} {'verdict'}")
for a, b in [("A", "V"), ("T", "o"), ("P", "A"), ("A", "T"), ("L", "T")]:
    g1, g2 = cmap[ord(a)], cmap[ord(b)]
    k = kern(g1, g2)
    adv1, adv2 = hmtx[g1][0], hmtx[g2][0]
    # usvg reports a text node's bounding box as the run's advance from the origin, which
    # single-glyph probes confirm: every one of A V P T o L measures its hmtx advance
    # exactly. Advance is also the quantity kerning changes, so the prediction is direct.
    predicted = (adv1 + k + adv2) * scale
    unkerned = (adv1 + adv2) * scale
    measured = probe(a + b)["ink_width"]
    ok = abs(measured - predicted) < 0.01 and abs(measured - unkerned) > 1.0
    failures += 0 if ok else 1
    print(f"{a+b:>6} {k:>6} {predicted:>10.2f} {unkerned:>10.2f} {measured:>10.2f} "
          f"{'kerned' if ok else 'MISMATCH'}")

print()
sys.exit(1 if failures else 0)
