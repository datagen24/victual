"""Candidate B — SVG intermediate, rasterized by resvg.

The same document produces an SVG; resvg does shaping (rustybuzz), layout of the text runs,
and rasterization. The emitter deliberately does no glyph work of its own: what this
candidate is being judged on is whether resvg honours the contract — pinned font, missing
glyph, whole-pixel QR modules under asymmetric resolution, and a restricted palette.
"""
import json, math, sys, argparse, subprocess, shutil
from pathlib import Path
import qrcode
from fontTools.ttLib import TTFont

MM = 25.4

class ContractError(Exception):
    def __init__(self, code, detail, element=None):
        super().__init__(detail); self.code, self.detail, self.element = code, detail, element

def px_x(mm, p): return int(round(mm * p["dpi_x"] / MM))
def px_y(mm, p): return int(round(mm * p["dpi_y"] / MM))

def cmap_of(path):
    f = TTFont(str(path), fontNumber=0, lazy=True)
    chars = set()
    for table in f["cmap"].tables:
        chars.update(table.cmap.keys())
    f.close()
    return chars

def advance_px(path, text, size_px):
    """Advance width from the font's own metrics — the emitter measures, resvg shapes."""
    f = TTFont(str(path), fontNumber=0, lazy=True)
    upem = f["head"].unitsPerEm
    hmtx, cmap = f["hmtx"], f.getBestCmap()
    total = 0
    for ch in text:
        gname = cmap.get(ord(ch))
        total += hmtx[gname][0] if gname else 0
    f.close()
    return total * size_px / upem

def render(tpl, prof, case, fontdir, out, resvg):
    width = prof["raster_width_px"]
    text_el = next(e for e in tpl["elements"] if e["type"] == "text")
    qr_el = next(e for e in tpl["elements"] if e["type"] == "qr")
    rect_el = next((e for e in tpl["elements"] if e["type"] == "rect"), None)
    asset = next(a for a in tpl["assets"] if a["id"] == text_el["font"])
    font_path = Path(fontdir) / asset["file"]

    name = case["location.name"]
    supported = cmap_of(font_path)
    missing = [c for c in name if c.strip() and ord(c) not in supported]
    if missing:
        raise ContractError("MISSING_GLYPH", "font has no glyph for " + "".join(sorted(set(missing))), text_el["id"])

    size_px = case.get("size_pt", text_el["size_pt"]) * prof["dpi_y"] / 72.0
    box_w = px_x(text_el["box"]["width_mm"], prof)
    words, lines, cur = name.split(), [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if advance_px(font_path, trial, size_px) <= box_w or not cur:
            cur = trial
        else:
            lines.append(cur); cur = w
    if cur: lines.append(cur)
    for ln in lines:
        if advance_px(font_path, ln, size_px) > box_w:
            raise ContractError("TEXT_OVERFLOW", f"line does not fit box: {ln!r}", text_el["id"])

    payload = case.get("label.payload", "vctl:0123456789ABC")
    q = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_L,
                      box_size=1, border=qr_el["quiet_zone_modules"])
    q.add_data(payload); q.make(fit=True)
    modules = q.get_matrix(); n = len(modules)
    mod_x = max(1, int(math.ceil(qr_el["min_module_mm"] * prof["dpi_x"] / MM)))
    mod_y = max(1, int(math.ceil(qr_el["min_module_mm"] * prof["dpi_y"] / MM)))

    line_h = size_px * text_el["line_spacing"]
    text_top = px_y(text_el["box"]["y_mm"], prof)
    content_bottom = max(text_top + line_h * len(lines),
                         px_y(qr_el["at"]["y_mm"], prof) + n * mod_y)
    height = content_bottom + px_y(tpl["size"]["bottom_spacing_mm"], prof)
    length_mm = height * MM / prof["dpi_y"]
    rules = prof["length_rules"]
    if length_mm > rules["max_mm"]:
        raise ContractError("MEDIA_INCOMPATIBLE",
                            f"automatic height {length_mm:.1f}mm exceeds length_rules.max_mm {rules['max_mm']}", None)
    inc = rules["increment_mm"]
    length_mm = math.ceil(length_mm / inc) * inc
    height = int(round(length_mm * prof["dpi_y"] / MM))

    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" '
             f'viewBox="0 0 {width} {height}"><rect width="100%" height="100%" fill="#ffffff"/>']
    if rect_el and case.get("accent"):
        parts.append('<rect x="%d" y="%d" width="%d" height="%d" fill="#ff0000"/>' % (
            px_x(rect_el["at"]["x_mm"], prof), px_y(rect_el["at"]["y_mm"], prof),
            px_x(rect_el["size"]["width_mm"], prof), px_y(rect_el["size"]["height_mm"], prof)))
    qx, qy = px_x(qr_el["at"]["x_mm"], prof), px_y(qr_el["at"]["y_mm"], prof)
    for r, row in enumerate(modules):
        for c, on in enumerate(row):
            if on:
                parts.append('<rect x="%d" y="%d" width="%d" height="%d" fill="#000000" shape-rendering="crispEdges"/>'
                             % (qx + c*mod_x, qy + r*mod_y, mod_x, mod_y))
    tx = px_x(text_el["box"]["x_mm"], prof)
    parts.append(f'<g font-family="{TTFont(str(font_path), fontNumber=0, lazy=True)["name"].getDebugName(1)}" '
                 f'font-size="{size_px:.2f}" fill="#000000">')
    for i, ln in enumerate(lines):
        esc = ln.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        parts.append(f'<text x="{tx}" y="{text_top + line_h*(i+1):.1f}">{esc}</text>')
    parts.append("</g></svg>")
    svg = "".join(parts)
    Path(out).with_suffix(".svg").write_text(svg)

    cmd = [resvg, "--font-family", "Noto Sans", "--use-fonts-dir", str(fontdir),
           "--skip-system-fonts", str(Path(out).with_suffix(".svg")), str(out)]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        raise ContractError("RENDER_FAILED", p.stderr.strip()[:400], None)

    from PIL import Image
    im = Image.open(out).convert("RGB")
    return {"width_px": im.size[0], "height_px": im.size[1], "length_mm": round(length_mm, 2),
            "lines": len(lines), "qr_modules": n, "module_px": [mod_x, mod_y],
            "colours": len({f"#{r:02x}{g:02x}{b:02x}" for r, g, b in im.getdata()}),
            "has_red": any(px == (255, 0, 0) for px in im.getdata()),
            "resvg_stderr": p.stderr.strip()[:200]}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", required=True); ap.add_argument("--case", required=True)
    ap.add_argument("--fonts", required=True); ap.add_argument("--out", required=True)
    ap.add_argument("--resvg", default=shutil.which("resvg") or "resvg")
    a = ap.parse_args()
    d = Path(a.dir)
    tpl = json.loads((d/"template.json").read_text()); prof = json.loads((d/"profile.json").read_text())
    case = next(c for c in json.loads((d/"cases.json").read_text()) if c["id"] == a.case)
    try:
        print(json.dumps({"ok": True, "case": a.case, **render(tpl, prof, case, a.fonts, a.out, a.resvg)}))
        return 0
    except ContractError as e:
        print(json.dumps({"ok": False, "case": a.case, "code": e.code, "element": e.element, "detail": e.detail}))
        return 3

if __name__ == "__main__":
    sys.exit(main())
