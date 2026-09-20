#!/usr/bin/env bash
# =============================================================================
# scripts/brand/optimise_catalogue_images.sh
# OK Veggies. Rebuild the catalogue WebP siblings at 400, 800 and 1200, and the
# seal WebP fallbacks. ImageMagick convert only, shrink never enlarge.
#
#   bash scripts/brand/optimise_catalogue_images.sh
# =============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if ! command -v convert >/dev/null 2>&1; then
  echo "convert is not installed (ImageMagick)." >&2
  exit 1
fi

count=0
for f in assets/img/product_images/*.jpeg; do
  [ -f "$f" ] || continue
  stem="${f%.jpeg}"
  for w in 400 800 1200; do
    convert "$f" -resize "${w}x${w}>" -strip -quality 78 "${stem}-${w}.webp"
  done
  count=$((count + 1))
done

for s in 160 320 640; do
  convert "assets/img/brand/seal-${s}.png" -strip -quality 78 "assets/img/brand/seal-${s}.webp"
done

echo "[okv] catalogue WebP: $count products x 3 widths, plus 3 seal WebP files."
