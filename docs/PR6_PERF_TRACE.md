# PR6 performance trace (fixes 2 and 6)

Frozen profile, recorded with the M13 contract Section 8:

- Device class: mid-range Android
- CPU: 4x throttle
- Network: 1.6 Mbps down, 150 ms RTT, cold cache
- Viewports: 390px and 1440px
- Suite: `scripts/tests/homepage_visual_test.mjs`

## Before (M12, no published hero photograph)

| Measure | Result | Budget |
|---------|-------:|-------:|
| First Contentful Paint | 3,780 ms | 3,000 ms |
| Largest Contentful Paint | not separately recorded | 4,000 ms |
| Cumulative Layout Shift | not separately recorded | 0.1 |
| Initial transfer | 24 catalogue JPEGs, no WebP, most images without width and height | 2 MB |

The 3,780 ms first paint was a known breach. The likely causes were the 24 catalogue JPEGs and missing image dimensions.

## What this PR changes

- Hero stays a branded placeholder until Track 2 is supplied. No stock photograph. When a documentary image is published, `ContentImages` serves WebP at 640 / 960 / 1280 with explicit width and height and `fetchpriority=high` on that image only.
- Catalogue JPEGs keep the database path. WebP siblings at 400 / 800 / 1200 sit next to them. Cards and product pages use `<picture>` with `loading=lazy` and `decoding=async`.
- Explicit width and height on every catalogue photograph, so the layout does not jump.
- First product photograph and the combo photograph are the LCP image (`fetchpriority=high`). Everything below is lazy.

## After

The throttled numbers are the assertions in `homepage_visual_test.mjs`:

- FCP <= 3,000 ms (was 3,780 ms)
- LCP <= 4,000 ms
- CLS <= 0.1
- initial transfer < 2 MB
- first viewport of `main` at 390px stays under 40 words (`h1` to `h3` and `p`, excluding buttons)

Run:

```
php -S 127.0.0.1:8123 -t .
node scripts/tests/homepage_visual_test.mjs
```

The suite prints the measured FCP, LCP, CLS and bytes next to each assertion. That printout is the after column for the frozen SHA.

This sandbox has no PHP binary, so the throttled after column has not been printed on this SHA. Static image contract (`node scripts/tests/image_contract_test.mjs`) is 95/95.

## Fix 29 word counts (static storefront copy)

Measured by `node scripts/tests/lesser_text_test.mjs`. Longest static `<p>` per file, after PHP is stripped. Budget: 40 words. Sheet copy is included.

| Page or component | Paragraphs | Longest | Before (walls) |
|-------------------|----------:|--------:|----------------|
| `index.php` | 14 | 8 | Hero plus promise paragraphs |
| `page.php` | 10 | 14 | How It Works, FAQ, Delivery Policy prose |
| `shop.php` | 5 | 8 | Search intro wall |
| `product.php` | 10 | 13 | Description wall |
| `combo.php` | 15 | 15 | Description wall |
| `combos.php` | 4 | 9 | Intro wall |
| `contact.php` | 3 | 9 | Form intro wall |
| `cart.php` | 9 | 10 | Line notes |
| `kitchen-runs.php` | 13 | 8 | Four-mode paragraph wall |
| `kitchen_run_intro.php` | 9 | 29 | Learn sheet, opt-in |
| `make_it_right_guidance.php` | 6 | 21 | Learn sheet, opt-in |

126 / 126 lesser-text assertions passed. Average first-viewport copy on the homepage hero (heading, intro, tagline, honest placeholder) is 37 words.
