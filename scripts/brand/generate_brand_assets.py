#!/usr/bin/env python3
"""
scripts/brand/generate_brand_assets.py
-------------------------------------------------------------------------------
OK Veggies. One logo everywhere.

The Owner's decision of 7 October 2026 retires every derived mark (the flat
monogram, the horizontal lockups, the wordmark): the approved photographic
seal IS the logo, at every size, on every surface. This script therefore only
reproduces the seal at the sizes the site, the PWA, the documents, the email
letterhead and the social card need. It never redraws, re-letters or restyles
the seal; it only scales it and, where a square tile is required, centres it
on a plain white ground.

Source of truth:
    docs/brand/logo/ok-veggies-seal.jpg   the approved seal (640x640)

It writes:
    assets/img/brand/seal-640|320|160.png|webp   seal with transparent surround
    assets/img/brand/og-image.png                1200x630 social card
    assets/img/brand/icons/favicon-16|32|48.png  the seal on a white tile
    assets/img/brand/icons/apple-touch-icon.png  (180)
    assets/img/brand/icons/icon-192|512.png
    assets/img/brand/icons/icon-maskable-512.png (seal in the 80% safe zone)
    favicon.ico                                  web-root multi-size icon

Dev tooling only: requires Pillow, plus fonttools and brotli so the social
card's tagline can be set in the brand sans. Not run at request time.
"""
import math
import os

from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
BRAND = os.path.join(ROOT, "assets", "img", "brand")
ICONS = os.path.join(BRAND, "icons")
SEAL_SRC = os.path.join(ROOT, "docs", "brand", "logo", "ok-veggies-seal.jpg")
FONT_SRC = os.path.join(ROOT, "assets", "fonts", "hanken-grotesk-latin.woff2")

# Locked seal palette (bible 3.9).
FOREST = (15, 81, 50)
INK = (42, 29, 20)
CREAM = (245, 235, 208)
WHITE = (255, 255, 255)

os.makedirs(ICONS, exist_ok=True)


# --- Seal with transparent surround ------------------------------------------
def seal_transparent():
    """The approved seal with its paper surround feathered to transparency,
    so it sits on white, forest or butter cream without a box around it.
    Written at 640, 320 and 160, each as PNG and WebP."""
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
        small = out.resize((size, size), Image.LANCZOS)
        small.save(os.path.join(BRAND, f"seal-{size}.png"))
        print("  wrote", f"assets/img/brand/seal-{size}.png")
        small.save(os.path.join(BRAND, f"seal-{size}.webp"), quality=90)
        print("  wrote", f"assets/img/brand/seal-{size}.webp")
    return out


# --- Square tiles: the seal centred on white ---------------------------------
def tile(seal_rgba, size, inset):
    """A square white tile with the seal centred at `inset` of the tile.
    The seal is only scaled, never restyled; the white ground is what a
    square icon slot (tab, home screen, maskable) requires around a round
    mark."""
    t = Image.new("RGBA", (size, size), WHITE + (255,))
    s = int(round(size * inset))
    t.alpha_composite(seal_rgba.resize((s, s), Image.LANCZOS),
                      ((size - s) // 2, (size - s) // 2))
    return t


def write_icons(seal_rgba):
    print("Favicon / app icons (the seal on a white tile):")
    for sz in (16, 32, 48):
        tile(seal_rgba, sz, 1.0).save(os.path.join(ICONS, f"favicon-{sz}.png"))
        print("  wrote", f"assets/img/brand/icons/favicon-{sz}.png")
    tile(seal_rgba, 180, 0.92).save(os.path.join(ICONS, "apple-touch-icon.png"))
    print("  wrote assets/img/brand/icons/apple-touch-icon.png")
    tile(seal_rgba, 192, 0.92).save(os.path.join(ICONS, "icon-192.png"))
    print("  wrote assets/img/brand/icons/icon-192.png")
    tile(seal_rgba, 512, 0.92).save(os.path.join(ICONS, "icon-512.png"))
    print("  wrote assets/img/brand/icons/icon-512.png")
    # maskable: full-bleed ground, the seal inside the 80% safe zone
    tile(seal_rgba, 512, 0.80).save(os.path.join(ICONS, "icon-maskable-512.png"))
    print("  wrote assets/img/brand/icons/icon-maskable-512.png")

    print("favicon.ico:")
    imgs = [Image.open(os.path.join(ICONS, f"favicon-{s}.png")).convert("RGBA")
            for s in (16, 32, 48)]
    imgs[0].save(os.path.join(ROOT, "favicon.ico"),
                 sizes=[(16, 16), (32, 32), (48, 48)], append_images=imgs[1:])
    print("  wrote favicon.ico")


# --- Social card: the seal and the tagline, nothing else ----------------------
def og_image(seal_rgba):
    """1200x630 social card. The retired wordmark is gone: the card carries
    the seal itself and the tagline lines, set in the brand sans."""
    W, H = 1200, 630
    card = Image.new("RGBA", (W, H), CREAM + (255,))
    from PIL import ImageDraw, ImageFont
    d = ImageDraw.Draw(card)
    d.rectangle([0, H - 12, W, H], fill=FOREST + (255,))  # forest base rule
    s = seal_rgba.resize((470, 470), Image.LANCZOS)
    card.alpha_composite(s, (70, (H - 470) // 2))

    tx = 610
    try:
        f_ttf = os.path.join(ROOT, "assets", "fonts", "_render_hanken800.ttf")
        instancer.instantiateVariableFont(TTFont(FONT_SRC), {"wght": 800},
                                          inplace=False).save(f_ttf)
        med = ImageFont.truetype(f_ttf, 34)
        small = ImageFont.truetype(f_ttf, 30)
    except Exception:
        med = small = ImageFont.load_default()
        f_ttf = None
    d.text((tx, 250), "Sourced right. Priced right.", font=med, fill=INK + (255,))
    d.text((tx, 296), "Delivered right.", font=med, fill=INK + (255,))
    d.text((tx, 380), "Fresh from farms we can name.", font=small,
           fill=FOREST + (255,))
    out = os.path.join(BRAND, "og-image.png")
    card.convert("RGB").save(out, quality=90)
    print("  wrote assets/img/brand/og-image.png")
    if f_ttf and os.path.exists(f_ttf):
        os.remove(f_ttf)


def main():
    # The seal variants come first: the tiles and the social card reuse the
    # transparent-surround image.
    print("Seal (transparent surround):")
    seal = seal_transparent()
    write_icons(seal)
    print("Social card:")
    og_image(seal)
    print("Done. One logo everywhere: every asset above is the approved seal.")


if __name__ == "__main__":
    main()
