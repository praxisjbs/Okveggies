/**
 * scripts/tests/axe_suite.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR6 accessibility gate: axe-core through Playwright at 390px and
 * 1440px over the public storefront, a signed-in customer, Pro, admin, FAQ,
 * checkout, the Order Trail, Make It Right and a content page.
 *
 *   node scripts/tests/axe_suite.mjs
 *
 * Needs the same stand as visual_pass.mjs. 0 critical or serious violations.
 * run_all.sh skips this when Playwright is not installed, the way it skips
 * the browser pass.
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const axeSource = require.resolve('axe-core/axe.min.js');

const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
const BUSINESS = process.env.OKV_BUSINESS || 'm8biz@example.test';
const OWNER = process.env.OKV_OWNER || 'owner-probe@example.test';
const PASSWORD = process.env.OKV_PASSWORD || 'm8-visual-pass-123';
const OWNER_PASSWORD = process.env.OKV_OWNER_PASSWORD || 'probe-owner-123';
const WIDTHS = [
  { name: '390px', width: 390, height: 844, touch: true },
  { name: '1440px', width: 1440, height: 900, touch: false },
];

const PUBLIC = [
  ['/', 'Home'],
  ['/shop.php', 'Shop'],
  ['/combos.php', 'Combos'],
  ['/kitchen-runs.php', 'Kitchen Runs'],
  ['/contact.php', 'Contact'],
  ['/faq', 'FAQ'],
  ['/our-story', 'Our Story'],
  ['/how-it-works', 'How It Works'],
  ['/cart.php', 'Basket'],
  ['/checkout.php', 'Checkout'],
  ['/public/order.php', 'Order Trail'],
];

let checks = 0;
let passed = 0;
function ok(value, label, detail = '') {
  checks++;
  if (value) { passed++; }
  else { console.error(`  FAIL: ${label}${detail ? ` ${detail}` : ''}`); }
}

function findChromium() {
  if (process.env.OKV_CHROME) { return process.env.OKV_CHROME; }
  const root = process.env.PLAYWRIGHT_BROWSERS_PATH;
  if (!root || !existsSync(root)) { return undefined; }
  const candidates = readdirSync(root)
    .filter((name) => name.startsWith('chromium-'))
    .sort()
    .reverse()
    .map((name) => join(root, name, 'chrome-linux', 'chrome'));
  return candidates.find((file) => existsSync(file));
}

async function signIn(page, path, identifier, password) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  const csrf = await page.getAttribute('input[name="okv_csrf"]', 'value');
  const [status, body] = await page.evaluate(async ([base, form]) => {
    const res = await fetch(base + '/api/v1/auth.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin',
      body: new URLSearchParams(form),
    });
    return [res.status, await res.text()];
  }, [BASE, {
    action: 'login', identifier, password, okv_csrf: csrf,
    context: path.startsWith('/admin') ? 'admin' : 'storefront',
  }]);
  if (status !== 200) { throw new Error(`sign in failed for ${identifier}: ${status} ${body}`); }
}

async function scan(page, label) {
  const landmarks = await page.evaluate(() => ({
    main: document.querySelectorAll('main').length,
    skip: document.querySelectorAll('a[href="#okv-main"]').length,
    h1: document.querySelectorAll('h1').length,
  }));
  ok(landmarks.main === 1, `${label}: exactly one main landmark`, `(${landmarks.main})`);
  ok(landmarks.h1 >= 1, `${label}: has a level-one heading`, `(${landmarks.h1})`);

  await page.addScriptTag({ path: axeSource });
  const axe = await page.evaluate(() => axe.run(document, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
  }));
  const bad = axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  ok(bad.length === 0, `${label}: axe reports no critical or serious violation`,
    bad.map((v) => `${v.id}(${v.impact}): ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(', ')}`).join(' | '));
}

const browser = await chromium.launch({
  args: ['--no-sandbox'],
  ...(findChromium() ? { executablePath: findChromium() } : {}),
});

try {
  for (const viewport of WIDTHS) {
    const context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    for (const [path, name] of PUBLIC) {
      const response = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
      ok(response !== null && response.status() < 500, `${viewport.name} ${name}: responds without a server error`,
        `(${response?.status() ?? 'none'})`);
      await scan(page, `${viewport.name} ${name}`);
    }

    ok(errors.length === 0, `${viewport.name} public: no JavaScript errors`, errors.join(', '));

    // Customer account, then a Pro screen, then Owner admin including Make It Right.
    await signIn(page, '/account.php', BUSINESS, PASSWORD);
    await page.goto(BASE + '/account.php', { waitUntil: 'domcontentloaded' });
    await scan(page, `${viewport.name} Account`);
    await page.goto(BASE + '/pro/index.php', { waitUntil: 'domcontentloaded' });
    await scan(page, `${viewport.name} Pro dashboard`);
    await page.goto(BASE + '/pro/orders.php', { waitUntil: 'domcontentloaded' });
    await scan(page, `${viewport.name} Pro orders`);
    await context.close();

    const adminContext = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
    });
    const adminPage = await adminContext.newPage();
    await signIn(adminPage, '/admin/login.php', OWNER, OWNER_PASSWORD);
    await adminPage.goto(BASE + '/admin/index.php', { waitUntil: 'domcontentloaded' });
    await scan(adminPage, `${viewport.name} Admin dashboard`);
    await adminPage.goto(BASE + '/admin/content.php', { waitUntil: 'domcontentloaded' });
    await scan(adminPage, `${viewport.name} Content editor`);
    await adminPage.goto(BASE + '/admin/make_it_right.php', { waitUntil: 'domcontentloaded' });
    await scan(adminPage, `${viewport.name} Make It Right`);
    await adminContext.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} axe assertions passed.`);
process.exit(passed === checks ? 0 : 1);
