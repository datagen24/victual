"""A test artifact with geometry you can measure with a ruler.

Vector PDF, so nothing is resampled anywhere in the chain: the QR modules are filled
rectangles at an exact physical size, and the rulers are drawn at exact millimetre marks. If
the printed page measures right, the whole path preserved scale; if the QR scans, the module
size survived rasterization inside the printer.
"""
import sys, json
import qrcode

MM = 72.0 / 25.4          # PDF units are points; 1 pt = 1/72 inch
PAGE_W, PAGE_H = 215.9, 279.4   # US Letter in mm

def rect(x_mm, y_mm, w_mm, h_mm, gray=0.0):
    return f"{gray:.2f} g {x_mm*MM:.4f} {y_mm*MM:.4f} {w_mm*MM:.4f} {h_mm*MM:.4f} re f\n"

def text(x_mm, y_mm, size_pt, s):
    esc = s.replace('\\', r'\\').replace('(', r'\(').replace(')', r'\)')
    return f"BT /F1 {size_pt} Tf {x_mm*MM:.4f} {y_mm*MM:.4f} Td ({esc}) Tj ET\n"

payload = sys.argv[1] if len(sys.argv) > 1 else "vctl:0123456789ABC"
module_mm = float(sys.argv[2]) if len(sys.argv) > 2 else 1.0
out = sys.argv[3] if len(sys.argv) > 3 else "/tmp/gate5-laser.pdf"

qr = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_L,
                   box_size=1, border=4)
qr.add_data(payload); qr.make(fit=True)
m = qr.get_matrix()
n = len(m)

body = []
# Title and provenance
body.append(text(20, PAGE_H - 25, 14, "Victual - ADR-0019 gate 5, laser family"))
body.append(text(20, PAGE_H - 33, 9, "Xerox Phaser 6600DN, sent as application/pdf over IPP"))
body.append(text(20, PAGE_H - 40, 9, f"QR payload {payload}   module {module_mm:g} mm   {n}x{n} modules"))

# The QR, drawn as vector squares at an exact physical module size.
qx, qy = 20.0, PAGE_H - 55 - n * module_mm
for r, row in enumerate(m):
    for c, on in enumerate(row):
        if on:
            body.append(rect(qx + c * module_mm, qy + (n - 1 - r) * module_mm,
                             module_mm, module_mm))

# A 100 mm ruler with 10 mm ticks: measure it to check the whole chain kept scale.
ry = qy - 20
body.append(rect(20, ry, 100, 0.4))
for i in range(11):
    body.append(rect(20 + i * 10, ry, 0.4, 3 if i % 5 else 5))
body.append(text(20, ry - 8, 9, "100 mm ruler, ticks every 10 mm (tall tick every 50 mm)"))

# A 50 x 30 mm box, so a second dimension is checkable independently of the ruler.
by = ry - 45
body.append(rect(20, by, 50, 0.4)); body.append(rect(20, by + 30, 50, 0.4))
body.append(rect(20, by, 0.4, 30)); body.append(rect(69.6, by, 0.4, 30))
body.append(text(20, by - 8, 9, "box above is exactly 50.0 mm wide x 30.0 mm tall"))

# A grey wedge, to see how the printer's halftone treats flat tone next to pure black.
for i, g in enumerate([0.0, 0.25, 0.5, 0.75]):
    body.append(rect(140 + i * 15, by, 12, 12, g))
body.append(text(140, by - 8, 9, "0 / 25 / 50 / 75% grey"))

content = "".join(body).encode("latin-1")

objs = []
objs.append(b"<< /Type /Catalog /Pages 2 0 R >>")
objs.append(b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>")
objs.append(f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {PAGE_W*MM:.4f} {PAGE_H*MM:.4f}] "
            f"/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>".encode())
objs.append(b"<< /Length " + str(len(content)).encode() + b" >>\nstream\n" + content + b"endstream")
objs.append(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

pdf = bytearray(b"%PDF-1.4\n")
offsets = []
for i, o in enumerate(objs, start=1):
    offsets.append(len(pdf))
    pdf += f"{i} 0 obj\n".encode() + o + b"\nendobj\n"
xref = len(pdf)
pdf += f"xref\n0 {len(objs)+1}\n0000000000 65535 f \n".encode()
for off in offsets:
    pdf += f"{off:010d} 00000 n \n".encode()
pdf += (f"trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n").encode()
open(out, "wb").write(pdf)
print(json.dumps({"file": out, "bytes": len(pdf), "qr_modules": n,
                  "module_mm": module_mm, "qr_size_mm": round(n * module_mm, 2),
                  "payload": payload}))
