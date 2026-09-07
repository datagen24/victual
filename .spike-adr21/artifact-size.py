"""Artifact size for the same label in two forms, measured rather than assumed.

The raster is what the renderer already emits for the Brother profile. The page description
is the same label drawn as vectors -- QR modules as filled rectangles, the name as a text
operator -- at the same physical size. Retention cost is the one place the two forms differ
by an order of magnitude, and ADR-0021 decision item 2 retains bytes, so the number matters.
"""
import os
import subprocess
import sys
import zlib

import qrcode

MM = 72.0 / 25.4
LABEL_W_MM, LABEL_H_MM = 62.0, 23.0


def pdf(payload, name, module_mm, out):
    qr = qrcode.QRCode(version=1, error_correction=qrcode.constants.ERROR_CORRECT_L,
                       box_size=1, border=4)
    qr.add_data(payload)
    qr.make(fit=True)
    m = qr.get_matrix()
    n = len(m)

    body = ["0.00 g\n"]
    for r in range(n):
        for c in range(n):
            if m[r][c]:
                x = 3.0 + c * module_mm
                y = LABEL_H_MM - 3.0 - (r + 1) * module_mm
                body.append(f"{x*MM:.4f} {y*MM:.4f} {module_mm*MM:.4f} {module_mm*MM:.4f} re f\n")
    esc = name.replace("\\", r"\\").replace("(", r"\(").replace(")", r"\)")
    body.append(f"BT /F1 11 Tf {26.0*MM:.4f} {14.0*MM:.4f} Td ({esc}) Tj ET\n")
    body.append(f"1.00 0.00 0.00 rg {3.0*MM:.4f} {(LABEL_H_MM-1.6)*MM:.4f} "
                f"{56.0*MM:.4f} {0.8*MM:.4f} re f\n")
    stream = "".join(body).encode()

    objs = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {LABEL_W_MM*MM:.2f} {LABEL_H_MM*MM:.2f}] "
        f"/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>".encode(),
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"endstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    buf = bytearray(b"%PDF-1.4\n")
    offsets = []
    for i, o in enumerate(objs, 1):
        offsets.append(len(buf))
        buf += f"{i} 0 obj\n".encode() + o + b"\nendobj\n"
    xref = len(buf)
    buf += f"xref\n0 {len(objs)+1}\n0000000000 65535 f \n".encode()
    for off in offsets:
        buf += f"{off:010d} 00000 n \n".encode()
    buf += (f"trailer\n<< /Size {len(objs)+1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n").encode()
    open(out, "wb").write(buf)
    return len(buf)


payload = "vctl:0123456789ABC"
name = "Pantry top shelf"
raster = sys.argv[1]
pdf_path = "/tmp/spike-adr21-label.pdf"
pdf_bytes = pdf(payload, name, 0.5, pdf_path)
raster_bytes = os.path.getsize(raster)

print(f"  raster (PNG, 696x543, palette-thresholded) : {raster_bytes:>8} bytes  {raster}")
print(f"  page description (PDF 1.4, vector)         : {pdf_bytes:>8} bytes  {pdf_path}")
print(f"  ratio                                      : {raster_bytes / pdf_bytes:>8.1f}x")
raw = open(raster, "rb").read()
print(f"  raster deflated again                      : {len(zlib.compress(raw, 9)):>8} bytes "
      f"(PNG is already deflate; no headroom)")
print(f"  page description deflated                  : "
      f"{len(zlib.compress(open(pdf_path,'rb').read(), 9)):>8} bytes")


def indexed_png(src_png, out):
    """Rewrite an RGBA PNG as a 2-bit indexed one over the profile palette.

    The renderer's own output is RGBA because tiny-skia writes RGBA; the form identifier
    `raster/png-indexed;v=1` says indexed. The difference is not cosmetic -- it is the
    retention figure -- so the form's definition has to pin colour type and bit depth, not
    just "PNG".
    """
    import struct
    import zlib as z
    raw = open(src_png, "rb").read()
    pos, w, h, idat = 8, 0, 0, b""
    while pos < len(raw):
        (ln,) = struct.unpack(">I", raw[pos:pos + 4])
        typ = raw[pos + 4:pos + 8]
        data = raw[pos + 8:pos + 8 + ln]
        if typ == b"IHDR":
            w, h, depth, ctype = struct.unpack(">IIBB", data[:10])
            assert (depth, ctype) == (8, 6), f"expected RGBA8, got depth={depth} type={ctype}"
        elif typ == b"IDAT":
            idat += data
        pos += 12 + ln
    px = z.decompress(idat)
    stride, bpp = w * 4, 4
    lines, prev = [], bytearray(stride)
    for y in range(h):
        ft = px[y * (stride + 1)]
        cur = bytearray(px[y * (stride + 1) + 1:(y + 1) * (stride + 1)])
        for i in range(stride):
            a = cur[i - bpp] if i >= bpp else 0
            b_ = prev[i]
            c = prev[i - bpp] if i >= bpp else 0
            if ft == 1:
                cur[i] = (cur[i] + a) & 0xFF
            elif ft == 2:
                cur[i] = (cur[i] + b_) & 0xFF
            elif ft == 3:
                cur[i] = (cur[i] + ((a + b_) >> 1)) & 0xFF
            elif ft == 4:
                pa, pb, pc = abs(b_ - c), abs(a - c), abs(a + b_ - 2 * c)
                pr = a if (pa <= pb and pa <= pc) else (b_ if pb <= pc else c)
                cur[i] = (cur[i] + pr) & 0xFF
        lines.append(bytes(cur))
        prev = cur
    rows = []
    for y in range(h):
        line = lines[y]
        out_row = bytearray(b"\x00")
        acc, nbits = 0, 0
        for x in range(w):
            r, g, b = line[x * 4], line[x * 4 + 1], line[x * 4 + 2]
            idx = 0 if (r, g, b) == (0, 0, 0) else 1 if (r, g, b) == (255, 0, 0) else 2
            acc = (acc << 2) | idx
            nbits += 2
            if nbits == 8:
                out_row.append(acc)
                acc, nbits = 0, 0
        if nbits:
            out_row.append(acc << (8 - nbits))
        rows.append(bytes(out_row))

    def chunk(typ, data):
        return struct.pack(">I", len(data)) + typ + data + struct.pack(">I", z.crc32(typ + data))

    body = (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 2, 3, 0, 0, 0))
            + chunk(b"PLTE", bytes([0, 0, 0, 255, 0, 0, 255, 255, 255]))
            + chunk(b"IDAT", z.compress(b"".join(rows), 9))
            + chunk(b"IEND", b""))
    open(out, "wb").write(body)
    return len(body)


idx_path = "/tmp/spike-adr21-label-indexed.png"
idx_bytes = indexed_png(raster, idx_path)
print(f"  raster re-encoded as 2-bit indexed PNG     : {idx_bytes:>8} bytes  {idx_path}")
print(f"  indexed raster vs page description         : "
      f"{idx_bytes / len(zlib.compress(open(pdf_path,'rb').read(), 9)):>8.1f}x")
