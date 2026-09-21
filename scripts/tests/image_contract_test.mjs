/**
 * scripts/tests/image_contract_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR6 image contract that does not need PHP or a browser.
 * Storefront photographs carry width, height and async decode. Catalogue
 * WebP siblings exist at 400/800/1200. ContentImages widths are 640/960/1280.
 *
 *   node scripts/tests/image_contract_test.mjs
 * -----------------------------------------------------------------------------
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const storefront = [
  'index.php',
  'page.php',
  'shop.php',
  'product.php',
  'combo.php',
  'combos.php',
  'contact.php',
  'cart.php',
  'kitchen-runs.php',
  'public/order.php',
  'includes/components/shop/picture.php',
  'includes/components/shop/header.php',
  'includes/components/shop/footer.php',
  'includes/components/shop/brand.php',
  'includes/components/shop/product_card.php',
  'includes/components/shop/combo_card.php',
];

let checks = 0;
let passed = 0;
function ok(value, label, detail = '') {
  checks++;
  if (value) {
    passed++;
    return;
  }
  console.error(`  FAIL: ${label}${detail ? ` ${detail}` : ''}`);
}

const picture = fs.readFileSync(path.join(root, 'includes/components/shop/picture.php'), 'utf8');
ok(picture.includes('fetchpriority="high"') && picture.includes('loading="lazy"'),
  'picture marks a hero high-priority and lazy-loads the rest');
ok(picture.includes('decoding="async"'), 'every catalogue photograph decodes asynchronously');
ok(picture.includes('width=') && picture.includes('height='), 'picture emits explicit width and height');

const productImages = fs.readFileSync(path.join(root, 'includes/classes/ProductImages.php'), 'utf8');
ok(productImages.includes('public const WIDTHS = [400, 800, 1200]'),
  'catalogue WebP widths are 400, 800 and 1200');

const contentImages = fs.readFileSync(path.join(root, 'includes/classes/ContentImages.php'), 'utf8');
ok(contentImages.includes('private const WIDTHS = [640, 960, 1280]'),
  'content WebP widths are 640, 960 and 1280');

// Read intrinsic dimensions, not just filenames. No image decoder dependency.
function webpSize(bytes) {
  if (bytes.toString('ascii', 0, 4) !== 'RIFF' || bytes.toString('ascii', 8, 12) !== 'WEBP') { return []; }
  for (let offset = 12; offset + 8 <= bytes.length;) {
    const type = bytes.toString('ascii', offset, offset + 4);
    const size = bytes.readUInt32LE(offset + 4);
    const data = bytes.subarray(offset + 8, offset + 8 + size);
    if (type === 'VP8 ' && data.length >= 10) { return [data.readUInt16LE(6) & 16383, data.readUInt16LE(8) & 16383]; }
    if (type === 'VP8X' && data.length >= 10) { return [data.readUIntLE(4, 3) + 1, data.readUIntLE(7, 3) + 1]; }
    if (type === 'VP8L' && data.length >= 5) {
      const bits = data.readUInt32LE(1);
      return [(bits & 16383) + 1, ((bits >>> 14) & 16383) + 1];
    }
    offset += 8 + size + (size % 2);
  }
  return [];
}
for (const [width, height] of [[640, 360], [960, 541], [1280, 721]]) {
  const hero = path.join(root, 'assets/img/hero', `fresh-produce-${width}.webp`);
  ok(fs.existsSync(hero) && fs.statSync(hero).size > 0,
    `homepage hero has a non-empty ${width}px WebP variant`);
  const bytes = fs.readFileSync(hero);
  ok(webpSize(bytes).join('x') === `${width}x${height}`, `hero candidate really measures ${width} by ${height}`);
  ok(bytes.length < 200_000, `${width}px hero candidate remains below 200kB`);
}
const source = fs.readFileSync(path.join(root, 'assets/img/brand/hero section.png'));
ok(source.subarray(1, 4).toString() === 'PNG' && source.readUInt32BE(16) === 1671 && source.readUInt32BE(20) === 941,
  'the chosen source photograph remains at its committed 1671 by 941 dimensions');

const optimiser = fs.readFileSync(path.join(root, 'scripts/brand/optimise_catalogue_images.sh'), 'utf8');
ok(optimiser.includes('400 800 1200') && optimiser.includes('.webp'),
  'the optimiser writes the three WebP widths');

const catalogueDir = path.join(root, 'assets/img/product_images');
const jpegs = fs.readdirSync(catalogueDir).filter((name) => /\.jpe?g$/i.test(name));
ok(jpegs.length >= 20, 'launch catalogue JPEGs are present', `(${jpegs.length})`);
for (const jpeg of jpegs) {
  const stem = jpeg.replace(/\.(jpe?g)$/i, '');
  for (const width of [400, 800, 1200]) {
    const sibling = path.join(catalogueDir, `${stem}-${width}.webp`);
    ok(fs.existsSync(sibling), `${stem} has a ${width}px WebP sibling`);
  }
}

for (const relative of storefront) {
  if (relative.endsWith('picture.php')) {
    continue;
  }
  const source = fs.readFileSync(path.join(root, relative), 'utf8')
    .replace(/<\?[\s\S]*?\?>/g, 'PHP');
  const tags = [...source.matchAll(/<img\b[^>]*>/gi)];
  for (const match of tags) {
    const tag = match[0].replace(/\s+/g, ' ');
    const preview = tag.slice(0, 110);
    ok(/\bwidth="/i.test(tag) && /\bheight="/i.test(tag),
      `${relative}: img has explicit width and height`, preview);
    const isLockup = /lockup|seal-|paystack\.svg|brand\/|w-auto|Fresh Picks/.test(tag);
    if (!isLockup) {
      ok(/\bdecoding="async"/i.test(tag),
        `${relative}: photograph decodes asynchronously`, preview);
    }
  }
}

const product = fs.readFileSync(path.join(root, 'product.php'), 'utf8');
ok(product.includes("'priority' => $index === 0"),
  'the first product photograph is the high-priority LCP image');

const combo = fs.readFileSync(path.join(root, 'combo.php'), 'utf8');
ok(combo.includes("'priority' => true"),
  'the combo photograph is the high-priority LCP image');

const index = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
ok((index.match(/fetchpriority="high"/g) || []).length === 1,
  'homepage reserves fetchpriority=high for the documentary hero only');

const hero = index.match(/<section class="okv-home-hero[\s\S]*?<\/section>/)?.[0] || '';
ok(hero.includes('data-okv-hero>') && hero.includes('okv-home-hero-media') && hero.includes('okv-home-hero-wash'),
  'the motion root owns both the full-hero photograph and the separate forest wash');
ok(index.includes("$heroSizes = '100vw';") && !index.includes('50vw'), 'hero sizes describes the full viewport on every breakpoint');
for (const [preload, image, expression] of [
  ['href', 'src', 'okv_e(okv_image_url($heroPath))'],
  ['imagesrcset', 'srcset', "okv_e($heroImage['srcset'])"],
  ['imagesizes', 'sizes', 'okv_e($heroSizes)'],
]) {
  ok(index.includes(`${preload}="<?= ${expression} ?>"`) && hero.includes(`${image}="<?= ${expression} ?>"`),
    `hero preload ${preload} and image ${image} use the same value`);
}
ok(hero.includes('object-cover') && hero.includes('decoding="async"') && !hero.includes('loading="lazy"'),
  'the full-hero image keeps object-cover, asynchronous decoding and eager loading');
ok(!hero.includes('backdrop-blur') && !hero.includes('bg-white/') && !hero.includes('okv-card') && !hero.includes('md:grid-cols-2'),
  'hero is not a glass panel, opaque card or two-column photograph');
ok(!hero.includes('animate-okv-rise'), 'no CSS entrance prevents a complete no-JavaScript still state');
ok(!index.includes('hero section.png'), 'the full-size source PNG is not downloaded by the homepage');
ok(!contentImages.includes('fresh-produce-'), 'the committed homepage asset is not hard-coded into generic CMS handling');
ok(contentImages.includes("($size['mime'] ?? '') !== 'image/webp'") && contentImages.includes('(int) $size[0] !== $width'),
  'published responsive candidates validate their format and intrinsic width');

console.log(`\n${passed} / ${checks} image-contract assertions passed.`);
process.exit(passed === checks ? 0 : 1);
