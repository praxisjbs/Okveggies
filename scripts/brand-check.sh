#!/usr/bin/env bash
# =============================================================================
# scripts/brand-check.sh
# OK Veggies. Static brand-consistency guard. Runs with no database and no live
# site, so it fits a pre-commit hook, CI, and the deploy pipeline. It fails the
# build when shipped source breaks a house law from CLAUDE.md:
#
#   1. No em dash anywhere in shipped source.
#   2. No banned enterprise jargon in customer-facing code.
#   3. Gold is never a solid button fill (bg-gold with text on top).
#   4. No arbitrary colour: no Tailwind [#hex] and no inline style hex.
#   5. The brand assets exist (logo, favicon set, manifest, fonts, the
#      single-ink set for documents and the raster mark for email).
#   6. Every page that loads the stylesheet also emits the brand head partial,
#      so a new page can never ship without a favicon and the fonts.
#
# Reference docs (docs/), third-party code (vendor/), build output and minified
# bundles are out of scope: this guards what we write and ship as our own.
#   bash scripts/brand-check.sh
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
note() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fail=1; }
okay() { printf '  ok   %s\n' "$1"; }

# Source we author and ship. Excludes vendor, node_modules, docs, git, minified
# bundles and the compiled stylesheet.
SRC_PHP=$(find . -type f -name '*.php' \
  -not -path './vendor/*' -not -path './node_modules/*' -not -path './docs/*' -not -path './.git/*')
SRC_JS=$(find assets/js -type f -name '*.js' -not -name '*.min.js' 2>/dev/null)
SRC_CSS="assets/css/src/input.css"
SRC_COPY="$SRC_PHP $SRC_JS $SRC_CSS"

echo "[brand] 1. em dash"
# Match the em dash by its exact UTF-8 bytes (E2 80 94) so detection does not
# depend on the shell locale.
EMDASH=$(printf '\xe2\x80\x94')
if printf '%s\n' $SRC_COPY | xargs grep -lF -- "$EMDASH" 2>/dev/null | grep -q .; then
  printf '%s\n' $SRC_COPY | xargs grep -nF -- "$EMDASH" 2>/dev/null | sed 's/^/       /'
  note "em dash found. Use a full stop, comma, colon or semicolon."
else
  okay "no em dash in shipped source"
fi

echo "[brand] 2. banned jargon"
JARGON='(?<![\w$])(curated|artisanal|bespoke|leverage|utilise|endeavour|elevate|seamless|unlock|robust|premium selection|one-stop solution)(?![\w])'
if printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -inP "$JARGON" 2>/dev/null | grep -q .; then
  printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -inP "$JARGON" 2>/dev/null | sed 's/^/       /'
  note "banned jargon word in source. Write from inside the kitchen."
else
  okay "no banned jargon"
fi

echo "[brand] 3. gold as a button fill"
if printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -nP 'bg-gold(?![-\w])' 2>/dev/null | grep -q .; then
  printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -nP 'bg-gold(?![-\w])' 2>/dev/null | sed 's/^/       /'
  note "bg-gold used as a fill. Gold is a ring, border or divider, never a button fill (bible 3.10)."
else
  okay "gold is never a solid fill"
fi

echo "[brand] 4. arbitrary colour"
if printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -nE '\[#[0-9a-fA-F]{3,6}\]|style="[^"]*#[0-9a-fA-F]{3,6}' 2>/dev/null | grep -q .; then
  printf '%s\n' $SRC_PHP $SRC_JS | xargs grep -nE '\[#[0-9a-fA-F]{3,6}\]|style="[^"]*#[0-9a-fA-F]{3,6}' 2>/dev/null | sed 's/^/       /'
  note "arbitrary hex colour. Colours come from tailwind.config.js tokens."
else
  okay "no arbitrary hex in markup"
fi

echo "[brand] 5. brand assets present"
ASSETS="favicon.ico site.webmanifest
  assets/img/brand/monogram.svg assets/img/brand/monogram-white.svg
  assets/img/brand/lockup.svg assets/img/brand/lockup-white.svg
  assets/img/brand/lockup-mono-green.svg assets/img/brand/monogram-mono-green.svg
  assets/img/brand/lockup-white-720.png
  assets/img/brand/wordmark.svg assets/img/brand/seal-640.png assets/img/brand/og-image.png
  assets/img/brand/icons/favicon.svg assets/img/brand/icons/favicon-32.png
  assets/img/brand/icons/apple-touch-icon.png assets/img/brand/icons/icon-192.png
  assets/img/brand/icons/icon-512.png assets/img/brand/icons/icon-maskable-512.png
  assets/fonts/hanken-grotesk-latin.woff2 assets/fonts/hanken-grotesk-latin-ext.woff2
  assets/fonts/dm-serif-display-latin.woff2 assets/fonts/jetbrains-mono-latin.woff2"
missing=0
for a in $ASSETS; do [ -f "$a" ] || { note "missing brand asset: $a"; missing=1; }; done
[ "$missing" -eq 0 ] && okay "all brand assets present"

echo "[brand] 6. every page carries the brand head partial"
offenders=0
for f in $SRC_PHP; do
  if grep -qE 'rel="stylesheet"[^>]*tailwind\.css' "$f" && ! grep -q 'okv_head_meta' "$f"; then
    note "page loads the stylesheet but never calls okv_head_meta(): $f"
    offenders=1
  fi
done
[ "$offenders" -eq 0 ] && okay "every page emits okv_head_meta()"

echo "[brand] 7. compiled stylesheet is current with the brand"
CSS="assets/css/tailwind.css"
if [ ! -f "$CSS" ]; then
  note "missing built stylesheet: $CSS (run: npm run build:css)"
else
  grep -q 'hanken-grotesk-latin.woff2' "$CSS" || note "tailwind.css does not self-host Hanken Grotesk. Run npm run build:css."
  grep -q 'DM Serif Display' "$CSS"          || note "tailwind.css is missing DM Serif Display. Run npm run build:css."
  grep -q 'font-editorial' "$CSS"            || note "tailwind.css is missing the .font-editorial utility. Run npm run build:css."
  if grep -q 'Plus Jakarta' "$CSS"; then note "tailwind.css still references the retired Plus Jakarta placeholder. Run npm run build:css."; fi
  [ "$fail" -eq 0 ] && okay "stylesheet self-hosts the three brand faces"
fi

echo
if [ "$fail" -eq 0 ]; then
  echo "[brand] all green. On brand."
else
  echo "[brand] brand check failed. See the lines above."
fi
exit "$fail"
