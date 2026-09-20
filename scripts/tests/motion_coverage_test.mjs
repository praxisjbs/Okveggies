/**
 * scripts/tests/motion_coverage_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR8 static coverage gate: proves no storefront or Pro route can
 * ship static. It needs no database and no browser, so it runs anywhere.
 *
 *   node scripts/tests/motion_coverage_test.mjs
 *
 * Three links in the chain, checked per route:
 *   1. Scripts. Every storefront page renders through okv_shop_footer() (which
 *      prints the vendored GSAP with its SRI pins and okv-motion.min.js, all
 *      defer); account.php carries the same tags; every Pro page closes
 *      through the pro footer. okv_motion_pending() prints before content on
 *      all three shells.
 *   2. Hooks. Each route's own markup plus the components it pulls in carry
 *      at least one entrance hook, so there is content for the motion system
 *      to move. A page with zero hooks would be a static route.
 *   3. Ownership. Every hook name found in templates is a member of the
 *      GROUP_SEL family in okv-motion.js, so the hooks found in step 2 are
 *      hooks the motion system actually animates.
 *
 * Video proof that the hooks move lives in motion_visual_test.mjs; this gate
 * proves the reach across every route.
 * -----------------------------------------------------------------------------
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = join(import.meta.dirname, '..', '..');
let checks = 0;
let passed = 0;
function ok(value, label, detail = '') {
  checks++;
  if (value) { passed++; console.log(`  ok   ${label}${detail ? ` ${detail}` : ''}`); }
  else { console.error(`  FAIL ${label}${detail ? ` ${detail}` : ''}`); }
}

const HOOKS = [
  'okv-panel', 'okv-card', 'okv-step-card', 'okv-empty', 'okv-enter',
  'data-product-card', 'data-combo-card', 'data-faq-item', 'data-kr-mode',
  'data-payment-options', 'data-okv-rise', 'data-okv-hero',
];

const read = (path) => readFileSync(join(ROOT, path), 'utf8');
const componentSource = (path) => read(path);

function hooksIn(source) {
  return [...new Set(HOOKS.filter((hook) => source.includes(hook)))];
}

/** Components a page pulls in, one level deep, as component paths. */
function componentsFor(pagePath) {
  const seen = new Set();
  const walk = (path, depth) => {
    if (depth > 2 || seen.has(path)) { return; }
    seen.add(path);
    const source = read(path);
    for (const match of source.matchAll(/includes\/components\/((?:shop|pro)\/[a-z_0-9]+\.php)/g)) {
      walk(join('includes/components', match[1]), depth + 1);
    }
  };
  walk(pagePath, 0);
  return [...seen].filter((path) => path !== pagePath);
}

console.log('[coverage] 1. Every storefront route loads the motion system');
const STOREFRONT = ['index.php', 'shop.php', 'product.php', 'combo.php', 'combos.php', 'page.php', 'contact.php', 'kitchen-runs.php', 'cart.php', 'checkout.php'];
for (const page of STOREFRONT) {
  const source = read(page);
  ok(source.includes('okv_shop_footer'), `${page} renders through the shared footer (motion scripts + pending glue)`);
}
ok(read('account.php').includes('okv-motion.min.js') && read('account.php').includes('vendor/gsap.min.js'),
  'account.php carries the motion script tags directly');
ok(read('account.php').includes('okv_motion_pending()'), 'account.php prints the pending glue before content');

const footer = read('includes/components/shop/footer.php');
ok(footer.includes('vendor/gsap.min.js') && footer.includes('integrity="sha384-'),
  'shop footer prints self-hosted GSAP with SRI pins');
ok(footer.includes('okv-motion.min.js'), 'shop footer prints okv-motion.min.js defer');
ok(read('includes/components/shop/header.php').includes('okv_motion_pending()'),
  'shop header prints the pending glue before content');

console.log('\n[coverage] 2. Every Pro route loads the motion system');
const proPages = readdirSync(join(ROOT, 'pro')).filter((name) => name.endsWith('.php'));
const proFooter = read('includes/components/pro/footer.php');
ok(proFooter.includes('vendor/gsap.min.js') && proFooter.includes('okv-motion.min.js'),
  'pro footer prints the motion script tags');
ok(read('includes/components/pro/header.php').includes('okv_motion_pending()'),
  'pro header prints the pending glue before content');
for (const page of proPages) {
  const source = read(join('pro', page));
  const closes = source.includes("components/pro/footer.php") || source.includes("pro/footer.php");
  ok(closes, `pro/${page} closes through the pro footer (motion scripts)`);
}

console.log('\n[coverage] 3. Every route carries entrance hooks (no static route)');
for (const page of STOREFRONT) {
  const parts = [read(page), ...componentsFor(page).map(componentSource)];
  const found = [...new Set(parts.flatMap((source) => hooksIn(source)))];
  ok(found.length > 0, `${page} has entrance hooks`, `(via ${found.slice(0, 3).join(', ')})`);
}
for (const page of proPages) {
  const source = read(join('pro', page));
  const found = hooksIn(source);
  ok(found.length > 0, `pro/${page} has entrance hooks`, `(via ${found.slice(0, 3).join(', ')})`);
}

console.log('\n[coverage] 4. Every hook a template uses is owned by okv-motion.js');
const motion = read('assets/js/okv-motion.js');
const used = new Set();
for (const page of [...STOREFRONT.map((p) => p), ...proPages.map((p) => join('pro', p))]) {
  for (const source of [read(page), ...componentsFor(page).map(componentSource)]) {
    hooksIn(source).forEach((hook) => used.add(hook));
  }
}
for (const hook of [...used].sort()) {
  ok(motion.includes(hook), `okv-motion.js owns ${hook}`);
}
ok(read('includes/components/shop/product_card.php').includes('data-add-form')
  && motion.includes('data-add-form'), 'okv-motion.js owns the add-to-basket moment');
ok(motion.includes('.okv-sheet-backdrop') && motion.includes('okv-mini-cart'),
  'okv-motion.js owns the sheet and drawer motion');

console.log(`\n${passed} / ${checks} motion coverage assertions passed.`);
process.exit(passed === checks ? 0 : 1);
