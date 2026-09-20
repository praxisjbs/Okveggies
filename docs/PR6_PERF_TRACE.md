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

## After

The throttled numbers are the assertions in `homepage_visual_test.mjs`:

- FCP <= 3,000 ms (was 3,780 ms)
- LCP <= 4,000 ms
- CLS <= 0.1
- initial transfer < 2 MB
- first viewport of `main` at 390px stays scannable

Run:

```
php -S 127.0.0.1:8123 -t .
node scripts/tests/homepage_visual_test.mjs
```

The suite prints the measured FCP, LCP, CLS and bytes next to each assertion. That printout is the after column for the frozen SHA.
