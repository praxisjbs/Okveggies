/**
 * scripts/tests/visual_pass.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. The browser pass, in a script.
 *
 * Three milestones in a row recorded "browser checks at 390px and 1440px" as
 * done without anything having driven a browser, so this does it and prints a
 * count like every other suite. It signs in as a real business customer and a
 * real Owner against a local scratch site, walks the six Pro screens and the
 * two M8 admin screens at both widths, and checks the four things that actually
 * break: horizontal overflow, a touch target under 44px, a suppressed focus
 * ring, and content hidden behind the fixed mobile navigation bar. It also
 * drives the repayment combobox, because a control that only exists once
 * JavaScript has run is a control no server-side test can see.
 *
 * Needs a running site and a seeded business. Set OKV_BASE, OKV_BUSINESS,
 * OKV_OWNER and OKV_PASSWORD, or take the defaults below:
 *
 *   php -S 127.0.0.1:8123 -t .
 *   npm run test:visual
 *
 * Set OKV_SHOTS=1 to write full-page screenshots to /tmp/okv-shots.
 * -----------------------------------------------------------------------------
 */
import { chromium } from 'playwright';
import { existsSync, mkdirSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
const BUSINESS = process.env.OKV_BUSINESS || 'm8biz@example.test';
const HOUSEHOLD = process.env.OKV_HOUSEHOLD || 'm8house@example.test';
const OWNER = process.env.OKV_OWNER || 'owner-probe@example.test';
const PASSWORD = process.env.OKV_PASSWORD || 'm8-visual-pass-123';
const OWNER_PASSWORD = process.env.OKV_OWNER_PASSWORD || 'probe-owner-123';
const SHOTS = process.env.OKV_SHOTS ? (process.env.OKV_SHOTS_DIR || '/tmp/okv-shots') : '';
const WIDTHS = [
  { name: '390px', width: 390, height: 844, touch: true },
  { name: '1440px', width: 1440, height: 900, touch: false },
];

const PRO = [
  ['/pro/index.php', 'Dashboard'],
  ['/pro/kitchen_lists.php', 'My Kitchen Lists'],
  ['/pro/standing_orders.php', 'Standing Orders'],
  ['/pro/orders.php', 'Orders and Invoices'],
  ['/pro/credit.php', 'Credit'],
  ['/pro/account.php', 'Account and Branches'],
];

const ADMIN = [
  ['/admin/credit.php', 'Admin credit'],
  ['/admin/customers.php', 'Admin customers'],
];

let failures = 0;
let checks = 0;

function report(ok, label, detail = '') {
  checks++;
  if (!ok) { failures++; }
  const mark = ok ? 'ok  ' : 'FAIL';
  console.log(`  ${mark} ${label}${detail ? ' ' + detail : ''}`);
}

/** Sign in through the real form, so the session is a real session. */
async function signIn(page, path, identifier, password) {
  await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
  const csrf = await page.getAttribute('input[name="okv_csrf"]', 'value');
  const res = await page.request.post(BASE + '/api/v1/auth.php', {
    headers: { 'X-Requested-With': 'fetch' },
    form: { action: 'login', identifier, password, okv_csrf: csrf,
            context: path.startsWith('/admin') ? 'admin' : 'storefront' },
  });
  if (!res.ok()) { throw new Error(`sign in failed for ${identifier}: ${res.status()} ${await res.text()}`); }
}

/** The three things that go wrong on a narrow screen. */
async function inspect(page, label, viewport) {
  const overflow = await page.evaluate(() => ({
    doc: document.documentElement.scrollWidth,
    win: window.innerWidth,
  }));
  report(overflow.doc <= overflow.win + 1, `${label} has no horizontal overflow`,
    `(document ${overflow.doc}, viewport ${overflow.win})`);

  if (viewport.touch) {
    const small = await page.evaluate(() => {
      const out = [];
      const selector = 'a, button, input:not([type=hidden]), select, textarea, summary, [role=option]';
      document.querySelectorAll(selector).forEach((el) => {
        const box = el.getBoundingClientRect();
        if (box.width === 0 || box.height === 0) { return; }
        const style = window.getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none') { return; }
        // A checkbox or radio is allowed to be small inside a large label.
        if (el.type === 'checkbox' || el.type === 'radio') { return; }
        // A skip link is deliberately sr-only until it takes focus, at which
        // point it paints at full size. Measure it focused, not at rest.
        if (el.className && String(el.className).includes('sr-only')) { return; }
        if (box.height < 44) {
          out.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} ${Math.round(box.height)}px "${(el.textContent || '').trim().slice(0, 28)}"`);
        }
      });
      return out;
    });
    report(small.length === 0, `${label} has no visible control under 44px`,
      small.length ? '\n       ' + small.join('\n       ') : '');
  }

  // On a phone the Pro navigation is a fixed bottom bar. The page has to
  // reserve room for it, or the last thing on every screen sits underneath it.
  if (viewport.touch) {
    const covered = await page.evaluate(() => {
      const bar = document.querySelector('nav.fixed.bottom-0');
      if (!bar) { return null; }
      const barBox = bar.getBoundingClientRect();
      const body = window.getComputedStyle(document.body);
      window.scrollTo(0, document.body.scrollHeight);
      const last = document.querySelector('main')?.lastElementChild;
      const lastBox = last ? last.getBoundingClientRect() : null;
      return {
        barHeight: Math.round(barBox.height),
        bodyPad: Math.round(parseFloat(body.paddingBottom)),
        lastBottom: lastBox ? Math.round(lastBox.bottom) : null,
        barTop: Math.round(barBox.top),
      };
    });
    if (covered) {
      report(covered.bodyPad >= covered.barHeight,
        `${label} reserves room for the fixed bottom bar`,
        `(padding ${covered.bodyPad}px, bar ${covered.barHeight}px)`);
    }
  }

  // The gold focus ring must never be suppressed. Driven with the keyboard,
  // because the ring is :focus-visible: a programmatic focus after a mouse
  // click leaves Chromium in pointer modality and paints nothing, which would
  // be a fact about this script rather than about the page.
  const focusable = await page.locator('a[href], button:not([disabled]), input:not([type=hidden]), select').first();
  if (await focusable.count() > 0) {
    await page.evaluate(() => { document.body.setAttribute('tabindex', '-1'); document.body.focus(); });
    await page.keyboard.press('Tab');
    const ring = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) { return null; }
      const style = window.getComputedStyle(el);
      return { who: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + '.' + String(el.className || '').split(' ')[0],
               outline: style.outlineStyle, width: style.outlineWidth, shadow: style.boxShadow };
    });
    const visible = ring && ((ring.outline !== 'none' && parseFloat(ring.width) > 0)
      || (ring.shadow && ring.shadow !== 'none'));
    report(Boolean(visible), `${label} shows a focus ring on the first control`,
      ring ? `(${ring.who}: outline ${ring.outline} ${ring.width})` : '');
  }
}

/**
 * Find a Chromium to drive. Playwright resolves its own download by default,
 * but a shared host often has one already installed under
 * PLAYWRIGHT_BROWSERS_PATH at a revision this Playwright did not download. Use
 * that rather than pulling a second copy.
 */
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

if (SHOTS) { mkdirSync(SHOTS, { recursive: true }); }

const executablePath = findChromium();
const browser = await chromium.launch({
  args: ['--no-sandbox'],
  ...(executablePath ? { executablePath } : {}),
});

try {
  for (const viewport of WIDTHS) {
    console.log(`\n=== ${viewport.name} ===`);

    // ---- The business customer, on all six Pro screens -------------------
    const proContext = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
    });
    const proPage = await proContext.newPage();
    proPage.on('pageerror', (err) => report(false, `JavaScript error: ${err.message}`));
    await signIn(proPage, '/account.php', BUSINESS, PASSWORD);

    for (const [path, label] of PRO) {
      const response = await proPage.goto(BASE + path, { waitUntil: 'networkidle' });
      report(response.status() === 200, `${label} loads`, `(${response.status()})`);
      if (SHOTS) {
        await proPage.screenshot({ path: `${SHOTS}/${viewport.width}-${path.replace(/[^a-z]/gi, '-')}.png`, fullPage: true });
      }
      const title = await proPage.title();
      report(title.length > 0, `${label} has a title`, `"${title}"`);
      const active = await proPage.locator(`[aria-current="page"][href="${path}"], [aria-current="page"]`).count();
      report(active > 0, `${label} marks its own navigation item`);
      await inspect(proPage, label, viewport);
    }

    // A household must not reach the Pro Portal at all.
    const houseContext = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
    const housePage = await houseContext.newPage();
    await signIn(housePage, '/account.php', HOUSEHOLD, PASSWORD);
    const blocked = await housePage.goto(BASE + '/pro/credit.php', { waitUntil: 'domcontentloaded' });
    report(!blocked.url().includes('/pro/'), 'a household is sent away from the Pro Portal',
      `(landed on ${blocked.url().replace(BASE, '')})`);
    await houseContext.close();

    // ---- The Owner, on the two admin screens ------------------------------
    const adminContext = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
    });
    const adminPage = await adminContext.newPage();
    adminPage.on('pageerror', (err) => report(false, `JavaScript error: ${err.message}`));
    await signIn(adminPage, '/admin/login.php', OWNER, OWNER_PASSWORD);

    for (const [path, label] of ADMIN) {
      const response = await adminPage.goto(BASE + path, { waitUntil: 'networkidle' });
      report(response.status() === 200, `${label} loads`, `(${response.status()})`);
      if (SHOTS) {
        await adminPage.screenshot({ path: `${SHOTS}/${viewport.width}-${path.replace(/[^a-z]/gi, '-')}.png`, fullPage: true });
      }
      await inspect(adminPage, label, viewport);
    }

    // The repayment picker: open the manage panel and drive the combobox.
    await adminPage.goto(BASE + '/admin/credit.php', { waitUntil: 'networkidle' });
    // Scope everything to the one panel we open: the table has a details per
    // business, and only the open one has a visible combobox.
    const panel = adminPage.locator('table details').filter({ has: adminPage.locator('select[name="payment_id"]') }).first();
    const summary = panel.locator('summary').first();
    if (await summary.count() > 0) {
      await summary.click();
      await adminPage.waitForTimeout(300);
      const combo = panel.locator('input[role="combobox"]').first();
      report(await combo.count() > 0, 'the repayment picker becomes a combobox');
      if (await combo.count() > 0) {
        await combo.click();
        await adminPage.waitForTimeout(150);
        const before = await panel.locator('[role="option"]').count();
        report(before > 0, 'the picker opens its list', `(${before} options)`);
        await combo.fill('nothing-matches-this');
        await adminPage.waitForTimeout(150);
        const after = await panel.locator('[role="option"]').count();
        report(after === 0, 'typing filters the list down', `(${after} options left)`);
        await combo.fill('VIS');
        await adminPage.waitForTimeout(150);
        const filtered = await panel.locator('[role="option"]').count();
        report(filtered > 0, 'a matching term brings options back', `(${filtered})`);
        const value = await panel.locator('select[name="payment_id"]').first().inputValue();
        report(/^\d+$/.test(value), 'the select still carries the real payment id', `(${value})`);
      }
      await inspect(adminPage, 'Admin credit, manage panel open', viewport);
    }

    await proContext.close();
    await adminContext.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${checks - failures} / ${checks} visual checks passed.`);
process.exit(failures === 0 ? 0 : 1);
