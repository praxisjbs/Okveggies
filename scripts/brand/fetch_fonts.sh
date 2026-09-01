#!/usr/bin/env bash
# scripts/brand/fetch_fonts.sh
# -----------------------------------------------------------------------------
# OK Veggies. Refetches the self-hosted brand fonts into assets/fonts/ as woff2,
# latin and latin-ext subsets only. Dev tooling: run when a face needs updating,
# then rebuild the stylesheet with `npm run build:css`. The app never fetches a
# font from a CDN at runtime (bible 5.1). Hanken Grotesk and JetBrains Mono are
# variable (one file per subset, weight range in the @font-face); DM Serif
# Display is static (regular + italic).
# -----------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/../.."
OUT=assets/fonts
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
mkdir -p "$OUT"

fetch() { # url  outfile
  curl -fsSL -A "$UA" "$1" -o "$OUT/$2"
  printf '  %-40s %s\n' "$2" "$(wc -c <"$OUT/$2") bytes"
}

echo "Fetching Hanken Grotesk (variable 400 to 800)"
css=$(curl -fsSL -A "$UA" 'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400..800&display=swap')
fetch "$(grep -A2 'latin */'      <<<"$css" | grep -o 'https[^)]*woff2' | head -1)" hanken-grotesk-latin.woff2
fetch "$(grep -A2 'latin-ext */'  <<<"$css" | grep -o 'https[^)]*woff2' | head -1)" hanken-grotesk-latin-ext.woff2

echo "Fetching JetBrains Mono (variable 400 to 700)"
css=$(curl -fsSL -A "$UA" 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400..700&display=swap')
fetch "$(grep -A2 'latin */'      <<<"$css" | grep -o 'https[^)]*woff2' | head -1)" jetbrains-mono-latin.woff2
fetch "$(grep -A2 'latin-ext */'  <<<"$css" | grep -o 'https[^)]*woff2' | head -1)" jetbrains-mono-latin-ext.woff2

echo "Fetching DM Serif Display (400 + italic)"
css=$(curl -fsSL -A "$UA" 'https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&display=swap')
mapfile -t urls < <(grep -o 'https[^)]*woff2' <<<"$css")
# order in css: latin(0), latin-ext(0)... normal block then italic block
echo "  (see input.css for the @font-face wiring)"
echo "Done. Rebuild the stylesheet with: npm run build:css"
