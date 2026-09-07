"""Render time and resources, measured rather than estimated.

Twenty runs per case of the template contract, wall time from the harness and peak resident
set from /usr/bin/time -l. The process is measured whole -- start, font load, shape, raster,
threshold, write -- because that is what a render job costs when it is one invocation of one
binary with no daemon to amortise the start.
"""
import json
import os
import re
import statistics
import subprocess
import sys
import time

BIN = ".spike-renderer/rsrender/target/release/rsrender"
DIR = ".spike-renderer/contract"
FONTS = ".spike-renderer/fonts"
OUT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/rsrender-cost"
N = 20
CASES = ["c1_wrap", "c2_glyph", "c3_qr_geometry", "c4_colour", "c5_length"]

os.makedirs(OUT, exist_ok=True)
print(f"{BIN}, {N} runs per case\n")
print(f"{'case':<16} {'outcome':<20} {'mean ms':>8} {'p95 ms':>8} {'peak RSS':>10} {'png bytes':>10} {'px':>12}")

for case in CASES:
    out_png = os.path.join(OUT, case + ".png")
    times = []
    report = None
    for _ in range(N):
        t0 = time.perf_counter()
        r = subprocess.run([BIN, "--dir", DIR, "--case", case, "--fonts", FONTS, "--out", out_png],
                           capture_output=True, text=True)
        times.append((time.perf_counter() - t0) * 1000)
        report = json.loads(r.stdout)
    timed = subprocess.run(["/usr/bin/time", "-l", BIN, "--dir", DIR, "--case", case,
                            "--fonts", FONTS, "--out", out_png], capture_output=True, text=True)
    m = re.search(r"(\d+)\s+maximum resident set size", timed.stderr)
    rss = f"{int(m.group(1)) / 1048576:.1f} MiB" if m else "?"
    size = os.path.getsize(out_png) if os.path.exists(out_png) else 0
    if report.get("ok"):
        outcome = f"{report['lines']} line(s)"
        px = f"{report['width_px']}x{report['height_px']}"
    else:
        outcome = report["code"]
        px = "-"
        size = 0
    times.sort()
    print(f"{case:<16} {outcome:<20} {statistics.mean(times):>8.1f} "
          f"{times[int(0.95 * (N - 1))]:>8.1f} {rss:>10} {size:>10} {px:>12}")
