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

console.log(`\n${passed} / ${checks} image-contract assertions passed.`);
process.exit(passed === checks ? 0 : 1);
