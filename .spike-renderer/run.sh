#!/usr/bin/env bash
# Drives both candidates over the five cases and prints one JSON report per run.
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${OUT:-/tmp/renderspike}"
mkdir -p "$OUT"

for case in c1_wrap c2_glyph c3_qr_geometry c4_colour c5_length; do
  for cand in pillow svg; do
    script="$DIR/render_${cand}.py"
    printf '%s %s ' "$cand" "$case"
    python3 "$script" --dir "$DIR/contract" --case "$case" \
      --fonts "$FONTDIR" --out "$OUT/${cand}_${case}.png" 2>&1 | tail -1
  done
done
