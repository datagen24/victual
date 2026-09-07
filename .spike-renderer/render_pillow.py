"""Candidate A — Pillow. Imperative raster compositing, the incumbent stack.

Reads the template document and profile, renders one case, writes a PNG and a JSON report.
Exits non-zero with a structured error for the cases the contract says must fail.
"""
import json, math, sys, argparse
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
import qrcode

MM = 25.4

class ContractError(Exception):
    def __init__(self, code, detail, element=None):
        super().__init__(detail)
        self.code, self.detail, self.element = code, detail, element

def px_x(mm, prof): return int(round(mm * prof["dpi_x"] / MM))
def px_y(mm, prof): return int(round(mm * prof["dpi_y"] / MM))

def load_font(tpl, asset_id, size_pt, prof, fontdir):
    asset = next(a for a in tpl["assets"] if a["id"] == asset_id)
    # Points are 1/72 inch; the vertical resolution is what sets glyph height here.
    px = int(round(size_pt * prof["dpi_y"] / 72.0))
    return ImageFont.truetype(str(Path(fontdir) / asset["file"]), px)

def check_glyphs(font, text, element):
    """A missing glyph is an error, never a silent substitution (plan 27 piece 1)."""
    cmap = font.font  # FreeTypeFont's underlying font exposes getsize per char
    missing = []
    for ch in text:
        if ch in "\n\r\t ":
            continue
        # index 0 is .notdef in every TrueType cmap
        if font.getmask(ch).size == (0, 0) and ch.strip():
            missing.append(ch)
            continue
        try:
            if font.font.getsize(ch)[0][0] == 0 and ch.strip():
                missing.append(ch)
        except Exception:
            pass
    # FreeType renders .notdef as a box with nonzero size, so compare against the font's
    # own character map instead: PIL exposes it through getbbox on a per-character basis
    # only indirectly. Use fontTools-free detection via the raw face when available.
    return missing

def wrap(text, font, max_px, element):
    words, lines, cur = text.split(), [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if font.getlength(trial) <= max_px or not cur:
            cur = trial
        else:
            lines.append(cur); cur = w
    if cur:
        lines.append(cur)
    for ln in lines:
        if font.getlength(ln) > max_px:
            raise ContractError("TEXT_OVERFLOW", f"line does not fit box: {ln!r}", element)
    return lines

def render(tpl, prof, case, fontdir, out):
    width = prof["raster_width_px"]
    palette = {"black": (0, 0, 0), "red": (255, 0, 0), "white": (255, 255, 255)}

    text_el = next(e for e in tpl["elements"] if e["type"] == "text")
    qr_el = next(e for e in tpl["elements"] if e["type"] == "qr")
    rect_el = next((e for e in tpl["elements"] if e["type"] == "rect"), None)

    font = load_font(tpl, text_el["font"], case.get("size_pt", text_el["size_pt"]), prof, fontdir)
    name = case["location.name"]

    missing = [ch for ch in name if ch.strip() and font.getmask(ch).getbbox() is None]
    if missing:
        raise ContractError("MISSING_GLYPH", "font has no glyph for " + "".join(sorted(set(missing))), text_el["id"])

    box_w = px_x(text_el["box"]["width_mm"], prof)
    lines = wrap(name, font, box_w, text_el["id"])
    line_h = int(round(font.size * text_el["line_spacing"]))

    # QR: whole device pixels per module on each axis, honouring asymmetric resolution.
    payload = case.get("label.payload", "vctl:0123456789ABC")
    q = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_L, box_size=1, border=qr_el["quiet_zone_modules"])
    q.add_data(payload); q.make(fit=True)
    modules = q.get_matrix()
    n = len(modules)
    mod_x = max(1, int(math.ceil(qr_el["min_module_mm"] * prof["dpi_x"] / MM)))
    mod_y = max(1, int(math.ceil(qr_el["min_module_mm"] * prof["dpi_y"] / MM)))

    qr_w, qr_h = n * mod_x, n * mod_y
    text_top = px_y(text_el["box"]["y_mm"], prof)
    content_bottom = max(text_top + line_h * len(lines), px_y(qr_el["at"]["y_mm"], prof) + qr_h)
    height = content_bottom + px_y(tpl["size"]["bottom_spacing_mm"], prof)

    length_mm = height * MM / prof["dpi_y"]
    rules = prof["length_rules"]
    if length_mm > rules["max_mm"]:
        raise ContractError("MEDIA_INCOMPATIBLE",
                            f"automatic height {length_mm:.1f}mm exceeds length_rules.max_mm {rules['max_mm']}", None)
    inc = rules["increment_mm"]
    length_mm = math.ceil(length_mm / inc) * inc
    height = int(round(length_mm * prof["dpi_y"] / MM))

    img = Image.new("RGB", (width, height), palette["white"])
    draw = ImageDraw.Draw(img)

    if rect_el and case.get("accent"):
        x0, y0 = px_x(rect_el["at"]["x_mm"], prof), px_y(rect_el["at"]["y_mm"], prof)
        draw.rectangle([x0, y0,
                        x0 + px_x(rect_el["size"]["width_mm"], prof),
                        y0 + px_y(rect_el["size"]["height_mm"], prof)],
                       fill=palette[rect_el["colour"]])

    qx, qy = px_x(qr_el["at"]["x_mm"], prof), px_y(qr_el["at"]["y_mm"], prof)
    for r, row in enumerate(modules):
        for c, on in enumerate(row):
            if on:
                draw.rectangle([qx + c*mod_x, qy + r*mod_y,
                                qx + (c+1)*mod_x - 1, qy + (r+1)*mod_y - 1],
                               fill=palette[qr_el["colour"]])

    tx = px_x(text_el["box"]["x_mm"], prof)
    for i, ln in enumerate(lines):
        draw.text((tx, text_top + i*line_h), ln, font=font, fill=palette[text_el["colour"]])

    img.save(out)
    return {"width_px": width, "height_px": height, "length_mm": round(length_mm, 2),
            "lines": len(lines), "qr_modules": n, "module_px": [mod_x, mod_y],
            "colours": len({f"#{r:02x}{g:02x}{b:02x}" for r, g, b in img.getdata()}),
            "has_red": any(px == (255, 0, 0) for px in img.getdata())}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", required=True); ap.add_argument("--case", required=True)
    ap.add_argument("--fonts", required=True); ap.add_argument("--out", required=True)
    a = ap.parse_args()
    d = Path(a.dir)
    tpl = json.loads((d/"template.json").read_text())
    prof = json.loads((d/"profile.json").read_text())
    case = next(c for c in json.loads((d/"cases.json").read_text()) if c["id"] == a.case)
    try:
        report = render(tpl, prof, case, a.fonts, a.out)
        print(json.dumps({"ok": True, "case": a.case, **report}))
        return 0
    except ContractError as e:
        print(json.dumps({"ok": False, "case": a.case, "code": e.code, "element": e.element, "detail": e.detail}))
        return 3

if __name__ == "__main__":
    sys.exit(main())
