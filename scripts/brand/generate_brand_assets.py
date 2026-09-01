#!/usr/bin/env python3
"""
scripts/brand/generate_brand_assets.py
-------------------------------------------------------------------------------
OK Veggies. Regenerates every derived brand asset from two sources of truth:

    docs/brand/logo/ok-veggies-seal.jpg   the approved primary seal (640x640)
    assets/fonts/hanken-grotesk-latin.woff2   the brand sans, for the wordmark

It writes:
    assets/img/brand/monogram.svg            circular emblem, light grounds
    assets/img/brand/monogram-white.svg      circular emblem, dark grounds (3.7b)
    assets/img/brand/wordmark.svg            OK VEGGIES lockup text, outlined
    assets/img/brand/lockup.svg              emblem + wordmark, light grounds
    assets/img/brand/lockup-white.svg        emblem + wordmark, dark grounds
    assets/img/brand/seal-640|320|160.png    seal with transparent surround
    assets/img/brand/og-image.png            1200x630 social card
    assets/img/brand/icons/favicon.svg       rounded-tile app icon (vector)
    assets/img/brand/icons/favicon-16|32|48.png
    assets/img/brand/icons/apple-touch-icon.png (180)
    assets/img/brand/icons/icon-192|512.png
    assets/img/brand/icons/icon-maskable-512.png
    favicon.ico                              web-root multi-size icon

The monogram letterforms are the real Hanken Grotesk 800 outlines, embedded as
vector paths, so the marks are font-independent. Colours are the four locked
seal colours (bible 3.9). No em dash is emitted anywhere. Dev tooling only:
requires fonttools, brotli, Pillow, cairosvg. Not run at request time.
"""
import io
import math
import os

from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.boundsPen import BoundsPen
from PIL import Image
import cairosvg

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
BRAND = os.path.join(ROOT, "assets", "img", "brand")
ICONS = os.path.join(BRAND, "icons")
SEAL_SRC = os.path.join(ROOT, "docs", "brand", "logo", "ok-veggies-seal.jpg")
FONT_SRC = os.path.join(ROOT, "assets", "fonts", "hanken-grotesk-latin.woff2")

# Locked seal palette (bible 3.9).
FOREST = "#0F5132"
GOLD = "#C9922B"
TOMATO = "#C8321E"
FOLIAGE = "#3E8B4A"
WHITE = "#FFFFFF"
CREAM = "#F5EBD0"

os.makedirs(ICONS, exist_ok=True)

# --- Letterform outlines ------------------------------------------------------
_font = instancer.instantiateVariableFont(TTFont(FONT_SRC), {"wght": 800}, inplace=False)
_glyphs = _font.getGlyphSet()
_cmap = _font.getBestCmap()
UPM = _font["head"].unitsPerEm


def glyph_path(ch):
    name = _cmap[ord(ch)]
    pen = SVGPathPen(_glyphs)
    _glyphs[name].draw(pen)
    return pen.getCommands(), _glyphs[name].width


def glyph_bounds(ch):
    name = _cmap[ord(ch)]
    pen = BoundsPen(_glyphs)
    _glyphs[name].draw(pen)
    return pen.bounds  # (xMin, yMin, xMax, yMax) in font units, y up


def word_paths(text, letter_spacing=0):
    """Return (list of (d, penx), total_advance, (yMin,yMax))."""
    out = []
    penx = 0
    ymin = math.inf
    ymax = -math.inf
    for ch in text:
        if ch == " ":
            penx += int(UPM * 0.32)
            continue
        d, adv = glyph_path(ch)
        b = glyph_bounds(ch)
        if b:
            ymin = min(ymin, b[1])
            ymax = max(ymax, b[3])
        out.append((d, penx))
        penx += adv + letter_spacing
    penx -= letter_spacing if letter_spacing and out else 0
    return out, penx, (ymin, ymax)


def emit_word_group(text, cx, baseline_y, cap_px, fill, letter_spacing=0, weight_group=None):
    """A <g> that draws `text` centred on cx, cap height cap_px, sitting on baseline_y."""
    paths, total_adv, (ymin, ymax) = word_paths(text, letter_spacing)
    cap = ymax - ymin
    s = cap_px / cap
    width_px = total_adv * s
    ox = cx - width_px / 2.0
    # font y-up -> svg y-down: point (fx,fy) -> (ox + s*fx, baseline_y - s*(fy - ymin) - ... )
    # Place so that ymax maps to top: top_y = baseline_y - cap_px.
    inner = []
    for d, penx in paths:
        inner.append(f'<path transform="translate({penx},0)" d="{d}"/>')
    # group: translate to origin, scale(s,-s), shift baseline so ymax at top
    g = (f'<g fill="{fill}" transform="translate({ox:.3f},{baseline_y:.3f}) '
         f'scale({s:.5f},{-s:.5f}) translate(0,{-ymin:.3f})">' + "".join(inner) + "</g>")
    return g, width_px


# Two-leaf sprout (gold), drawn in a 0..100 box, base at bottom-centre.
LEAF_SPROUT = (
    '<path d="M50 96 C50 74 50 58 50 44" stroke="{stem}" stroke-width="5" '
    'stroke-linecap="round" fill="none"/>'
    '<path d="M50 60 C33 60 22 49 22 30 C43 30 50 44 50 60 Z" fill="{leaf}"/>'
    '<path d="M50 52 C67 52 78 41 78 22 C57 22 50 36 50 52 Z" fill="{leaf2}"/>'
)


def sprout(cx, cy, size, leaf=FOLIAGE, leaf2=GOLD, stem=FOLIAGE):
    inner = LEAF_SPROUT.format(leaf=leaf, leaf2=leaf2, stem=stem)
    s = size / 100.0
    return (f'<g transform="translate({cx - size/2:.2f},{cy - size/2:.2f}) '
            f'scale({s:.4f})">{inner}</g>')


# --- Emblem (circular mini-seal) ---------------------------------------------
def emblem_svg(size=64, dark=False, tile=False):
    """Circular OK emblem. dark=True -> white marks for dark grounds."""
    ink = WHITE if dark else WHITE  # OK letters are white on the forest disc
    disc = "none" if dark else FOREST
    ring = "rgba(255,255,255,0.85)" if dark else GOLD
    ok_fill = WHITE
    r = size / 2.0
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" '
             f'role="img" aria-label="OK Veggies">']
    if tile:
        rad = size * 0.22
        parts.append(f'<rect x="0" y="0" width="{size}" height="{size}" rx="{rad:.2f}" '
                     f'fill="{FOREST}"/>')
    elif not dark:
        parts.append(f'<circle cx="{r}" cy="{r}" r="{r-0.5:.2f}" fill="{FOREST}"/>')
    # inner ring accent
    if tile:
        parts.append(f'<rect x="{size*0.09:.2f}" y="{size*0.09:.2f}" '
                     f'width="{size*0.82:.2f}" height="{size*0.82:.2f}" rx="{size*0.15:.2f}" '
                     f'fill="none" stroke="{ring}" stroke-width="{size*0.028:.2f}"/>')
    else:
        parts.append(f'<circle cx="{r}" cy="{r}" r="{r*0.86:.2f}" fill="none" '
                     f'stroke="{ring}" stroke-width="{size*0.03:.2f}"/>')
    # OK wordmark centred, cap height ~ 42% of size, nudged up to leave room for sprout
    cap = size * 0.40
    baseline = size * 0.585
    g, w = emit_word_group("OK", cx=r, baseline_y=baseline, cap_px=cap, fill=ok_fill,
                           letter_spacing=int(UPM * 0.01))
    parts.append(g)
    # sprout under the OK
    parts.append(sprout(cx=r, cy=size * 0.775, size=size * 0.20,
                        leaf=FOLIAGE, leaf2=GOLD, stem=FOLIAGE if not dark else "rgba(255,255,255,0.85)"))
    parts.append("</svg>")
    return "\n".join(parts)


def wordmark_svg(height=64, white=False):
    ok_c = WHITE if white else FOREST
    veg_c = GOLD
    cap = height * 0.62
    baseline = height * 0.80
    # OK then VEGGIES, letter-spaced, two colours
    ok_paths, ok_adv, _ = word_paths("OK", 0)
    total_h = height
    # Build by concatenating two coloured groups on one baseline
    gap = UPM * 0.22
    ls = int(UPM * 0.02)
    lsv = int(UPM * 0.16)
    # measure
    _, ok_w_units, (oy0, oy1) = word_paths("OK", ls)
    _, vg_w_units, _ = word_paths("VEGGIES", lsv)
    cap_units = oy1 - oy0
    s = cap / cap_units
    ok_w = ok_w_units * s
    vg_w = vg_w_units * s
    gap_px = gap * s
    total_w = ok_w + gap_px + vg_w
    pad = height * 0.12
    W = total_w + pad * 2
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W:.1f} {height}" '
             f'role="img" aria-label="OK Veggies">']
    g1, w1 = emit_word_group("OK", cx=pad + ok_w / 2, baseline_y=baseline, cap_px=cap,
                             fill=ok_c, letter_spacing=ls)
    g2, w2 = emit_word_group("VEGGIES", cx=pad + ok_w + gap_px + vg_w / 2, baseline_y=baseline,
                             cap_px=cap, fill=veg_c, letter_spacing=lsv)
    parts += [g1, g2, "</svg>"]
    return "\n".join(parts), W


def lockup_svg(height=64, white=False):
    emb = height * 0.96
    wm, wm_w = wordmark_svg(height=height * 0.62, white=white)
    # strip outer svg of wordmark, inline its content translated
    inner = wm[wm.index(">") + 1: wm.rindex("</svg>")]
    # emblem
    emb_svg = emblem_svg(size=emb, dark=white)
    emb_inner = emb_svg[emb_svg.index(">") + 1: emb_svg.rindex("</svg>")]
    gap = height * 0.16
    # wordmark viewBox width was computed for its own height; recompute width
    _, ok_w_units, (oy0, oy1) = word_paths("OK", int(UPM * 0.02))
    _, vg_w_units, _ = word_paths("VEGGIES", int(UPM * 0.16))
    cap = (height * 0.62) * 0.62
    s = cap / (oy1 - oy0)
    total_w = (ok_w_units * s) + (UPM * 0.22 * s) + (vg_w_units * s)
    W = emb + gap + total_w + height * 0.1
    wm_y = (height - height * 0.62) / 2
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W:.1f} {height}" '
             f'role="img" aria-label="OK Veggies">']
    parts.append(f'<g transform="translate(0,{(height-emb)/2:.2f})">{emb_inner}</g>')
    parts.append(f'<g transform="translate({emb+gap:.2f},{wm_y:.2f})">{inner}</g>')
    parts.append("</svg>")
    return "\n".join(parts)


def word_group_left(text, x, baseline_y, cap_px, fill, letter_spacing=0):
    """emit_word_group, but anchored at left x instead of centred. Returns (svg, width)."""
    _, total_adv, (ymin, ymax) = word_paths(text, letter_spacing)
    s = cap_px / (ymax - ymin)
    width = total_adv * s
    g, _ = emit_word_group(text, cx=x + width / 2.0, baseline_y=baseline_y,
                           cap_px=cap_px, fill=fill, letter_spacing=letter_spacing)
    return g, width


def _seal_data_uri(size=320):
    import base64
    with open(os.path.join(BRAND, f"seal-{size}.png"), "rb") as fh:
        b64 = base64.b64encode(fh.read()).decode("ascii")
    return "data:image/png;base64," + b64


def horizontal_lockup_svg(white=False):
    """The official horizontal lockup: seal, divider, OK (leaf in the O), VEGGIES,
    and the FRESH PICKS rule line. Vector text, the seal embedded as a data URI so
    the file stands alone in an <img>. white=True recolours for dark grounds."""
    W, H = 1200.0, 470.0
    ok_c = WHITE if white else FOREST
    picks_c = WHITE if white else FOREST
    veg_c = GOLD
    rule_c = TOMATO
    div_c = "rgba(255,255,255,0.55)" if white else FOREST
    leaf_in_o = FOLIAGE
    # Embed the 160px seal: the lockup shows it small (headers, letterhead), so
    # this keeps the standalone SVG light for mobile data.
    seal = _seal_data_uri(160)

    parts = []
    # Seal, left, vertically centred.
    sd = 430.0
    sx, sy = 6.0, (H - sd) / 2.0
    parts.append(f'<image href="{seal}" x="{sx:.1f}" y="{sy:.1f}" '
                 f'width="{sd:.1f}" height="{sd:.1f}"/>')
    # Divider.
    dx = 476.0
    parts.append(f'<line x1="{dx}" y1="100" x2="{dx}" y2="370" stroke="{div_c}" '
                 f'stroke-width="5" stroke-linecap="round"/>')
    # Top line: OK (big) then VEGGIES (gold), OK's optical centre near y=188.
    left = 512.0
    ok_cap = 208.0
    ok_base = 262.0
    g_ok, ok_w = word_group_left("OK", left, ok_base, ok_cap, ok_c,
                                 letter_spacing=int(UPM * 0.01))
    parts.append(g_ok)
    # Leaf inside the O counter (first glyph). O advance ~747 units.
    s_ok = ok_cap / (word_paths("OK", int(UPM * 0.01))[2][1] - word_paths("OK", int(UPM * 0.01))[2][0])
    o_cx = left + (747 * s_ok) * 0.5
    o_cy = ok_base - ok_cap * 0.52
    parts.append(sprout(cx=o_cx, cy=o_cy, size=ok_cap * 0.46,
                        leaf=leaf_in_o, leaf2=leaf_in_o, stem="none"))
    veg_cap = 116.0
    veg_x = left + ok_w + 46.0
    g_veg, veg_w = word_group_left("VEGGIES", veg_x, 226.0, veg_cap, veg_c,
                                   letter_spacing=int(UPM * 0.14))
    parts.append(g_veg)
    right_edge = veg_x + veg_w
    # Bottom line: red rule, FRESH PICKS, red rule, leaf sprig.
    fp_cap = 60.0
    fp_base = 372.0
    fp_x = left + 60.0
    g_fp, fp_w = word_group_left("FRESH PICKS", fp_x, fp_base, fp_cap, picks_c,
                                 letter_spacing=int(UPM * 0.16))
    ry = fp_base - fp_cap * 0.35
    parts.append(f'<line x1="{left}" y1="{ry}" x2="{fp_x - 18:.1f}" y2="{ry}" '
                 f'stroke="{rule_c}" stroke-width="4" stroke-linecap="round"/>')
    parts.append(g_fp)
    r2x1 = fp_x + fp_w + 18
    parts.append(f'<line x1="{r2x1:.1f}" y1="{ry}" x2="{r2x1 + 60:.1f}" y2="{ry}" '
                 f'stroke="{rule_c}" stroke-width="4" stroke-linecap="round"/>')
    parts.append(sprout(cx=r2x1 + 96, cy=ry - 4, size=54, leaf=FOLIAGE, leaf2=GOLD, stem="none"))
    # Fit the canvas to whatever the text needs, so nothing clips.
    W = max(right_edge, r2x1 + 96 + 30) + 34
    head = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W:.0f} {H:.0f}" '
            f'role="img" aria-label="OK Veggies, Fresh Picks">')
    return head + "\n" + "\n".join(parts) + "\n</svg>"


def write(path, text):
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text + "\n")
    print("  wrote", os.path.relpath(path, ROOT))


# --- Raster from SVG ----------------------------------------------------------
def svg_to_png(svg_text, out, w, h=None):
    cairosvg.svg2png(bytestring=svg_text.encode("utf-8"), write_to=out,
                     output_width=w, output_height=h or w)
    print("  wrote", os.path.relpath(out, ROOT))


# --- Seal with transparent surround ------------------------------------------
def seal_transparent():
    im = Image.open(SEAL_SRC).convert("RGBA")
    w, h = im.size
    cx, cy = w / 2.0, h / 2.0
    px = im.load()
    # detect ring outer radius: farthest clearly non-white pixel from centre
    maxr = 0.0
    step = 2
    for y in range(0, h, step):
        for x in range(0, w, step):
            r, g, b, a = px[x, y]
            if min(r, g, b) < 225 or (max(r, g, b) - min(r, g, b)) > 28:
                d = math.hypot(x - cx, y - cy)
                if d > maxr:
                    maxr = d
    rad = min(maxr + 3, min(cx, cy))
    # feathered circular alpha
    out = im.copy()
    op = out.load()
    for y in range(h):
        for x in range(w):
            d = math.hypot(x - cx, y - cy)
            if d > rad:
                r, g, b, a = op[x, y]
                op[x, y] = (r, g, b, 0)
            elif d > rad - 1.5:
                r, g, b, a = op[x, y]
                op[x, y] = (r, g, b, int(a * (rad - d) / 1.5))
    for size in (640, 320, 160):
        out.resize((size, size), Image.LANCZOS).save(
            os.path.join(BRAND, f"seal-{size}.png"))
        print("  wrote", f"assets/img/brand/seal-{size}.png")
    return out


# --- Social card --------------------------------------------------------------
def og_image(seal_rgba):
    W, H = 1200, 630
    card = Image.new("RGBA", (W, H), (255, 255, 255, 255))
    # cream side panel
    from PIL import ImageDraw, ImageFont
    d = ImageDraw.Draw(card)
    d.rectangle([0, 0, W, H], fill=(245, 235, 208, 255))  # butter cream
    d.rectangle([0, H - 12, W, H], fill=(15, 81, 50, 255))  # forest base rule
    s = seal_rgba.resize((470, 470), Image.LANCZOS)
    card.alpha_composite(s, (70, (H - 470) // 2))
    # text block
    tx = 610
    try:
        f_ttf = os.path.join(ROOT, "assets", "fonts", "_render_hanken800.ttf")
        _font.save(f_ttf)
        big = ImageFont.truetype(f_ttf, 92)
        med = ImageFont.truetype(f_ttf, 40)
        small = ImageFont.truetype(f_ttf, 30)
    except Exception:
        big = med = small = ImageFont.load_default()
    d.text((tx, 150), "OK", font=big, fill=(15, 81, 50, 255))
    d.text((tx + 150, 168), "VEGGIES", font=med, fill=(201, 146, 43, 255))
    d.text((tx, 300), "Sourced right. Priced right.", font=small, fill=(42, 29, 20, 255))
    d.text((tx, 340), "Delivered right.", font=small, fill=(42, 29, 20, 255))
    d.text((tx, 410), "Fresh from farms we can name.", font=small, fill=(15, 81, 50, 255))
    out = os.path.join(BRAND, "og-image.png")
    card.convert("RGB").save(out, quality=90)
    print("  wrote assets/img/brand/og-image.png")
    if os.path.exists(f_ttf):
        os.remove(f_ttf)


def main():
    # The seal transparent variants come first: the horizontal lockup embeds
    # seal-320.png, and the social card uses the returned image.
    print("Seal (transparent surround):")
    seal = seal_transparent()

    print("Vector marks:")
    write(os.path.join(BRAND, "monogram.svg"), emblem_svg(64, dark=False))
    write(os.path.join(BRAND, "monogram-white.svg"), emblem_svg(64, dark=True))
    wm, _ = wordmark_svg(64, white=False)
    write(os.path.join(BRAND, "wordmark.svg"), wm)
    wmw, _ = wordmark_svg(64, white=True)
    write(os.path.join(BRAND, "wordmark-white.svg"), wmw)
    write(os.path.join(BRAND, "lockup.svg"), horizontal_lockup_svg(white=False))
    write(os.path.join(BRAND, "lockup-white.svg"), horizontal_lockup_svg(white=True))
    write(os.path.join(BRAND, "lockup-compact.svg"), lockup_svg(64, white=False))
    write(os.path.join(BRAND, "lockup-compact-white.svg"), lockup_svg(64, white=True))

    print("Favicon / app icons:")
    tile = emblem_svg(64, tile=True)
    write(os.path.join(ICONS, "favicon.svg"), tile)
    for sz in (16, 32, 48):
        svg_to_png(tile, os.path.join(ICONS, f"favicon-{sz}.png"), sz)
    svg_to_png(tile, os.path.join(ICONS, "apple-touch-icon.png"), 180)
    svg_to_png(tile, os.path.join(ICONS, "icon-192.png"), 192)
    svg_to_png(tile, os.path.join(ICONS, "icon-512.png"), 512)
    # maskable: full-bleed forest, mark in safe zone
    maskable = tile.replace('rx="14.08"', 'rx="0"').replace('rx="14.08000"', 'rx="0"')
    # ensure square fill: rebuild with rx 0
    mask_svg = emblem_svg(64, tile=True).replace(f'rx="{64*0.22:.2f}"', 'rx="0"')
    svg_to_png(mask_svg, os.path.join(ICONS, "icon-maskable-512.png"), 512)

    print("favicon.ico:")
    imgs = [Image.open(os.path.join(ICONS, f"favicon-{s}.png")).convert("RGBA")
            for s in (16, 32, 48)]
    imgs[0].save(os.path.join(ROOT, "favicon.ico"),
                 sizes=[(16, 16), (32, 32), (48, 48)], append_images=imgs[1:])
    print("  wrote favicon.ico")

    print("Social card:")
    og_image(seal)
    print("Done.")


if __name__ == "__main__":
    main()
