/**
 * scripts/tests/kitchen_runs_visual_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run custom-items flow, driven the way a customer
 * drives it: add a line, add another, move one, remove one, and send, at 390px
 * and 1440px. Then the same send with JavaScript off, which is the fallback the
 * whole fix rests on.
 *
 *   node scripts/tests/kitchen_runs_visual_test.mjs
 *
 * This is the regression guard for the dead "Add item" button. The button sits
 * beside its rows container, not inside it, so closest('[data-kr-rows]') found
 * nothing and a press added no row at all; a customer who typed three items saw
 * one line go. That was invisible to every server test, because the server never
 * saw the rows that were never added. Only a browser that presses the button
 * can prove it now works. The reorder buttons are asserted here too, because a
 * control that only exists once JavaScript has run is a control no server-side
 * test can see.
 *
 * Needs the same stand the other browser suites need: a seeded scratch server
 * (OKV_BASE, default http://127.0.0.1:8123), a household customer (OKV_HOUSEHOLD,
 * OKV_PASSWORD, defaults below), and Playwright with Chromium.
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');

const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
const HOUSEHOLD = process.env.OKV_HOUSEHOLD || 'm8house@example.test';
const PASSWORD = process.env.OKV_PASSWORD || 'm8-visual-pass-123';
const WIDTHS = [
  { name: '390px', width: 390, height: 844, touch: true },
  { name: '1440px', width: 1440, height: 900, touch: false },
];

let checks = 0;
let passed = 0;
function ok(value, label, detail = '') {
  checks++;
  if (value) { passed++; } else { console.error(`  FAIL: ${label}${detail ? ` ${detail}` : ''}`); }
}

/** Find a Chromium to drive, the same way visual_pass.mjs does. */
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

/** Sign in through the real form, so the session is a real session. Driven from
 *  inside the page, because the session cookie is Secure and a browser sends it
 *  over the trustworthy 127.0.0.1 origin where an API request context would not. */
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
  }, [BASE, { action: 'login', identifier, password, okv_csrf: csrf, context: 'storefront' }]);
  if (status !== 200) { throw new Error(`sign in failed for ${identifier}: ${status} ${body}`); }
}

/** Every typed line of the active (non-shop) section, in DOM order, with the
 *  facts that prove renumbering and the reorder buttons. */
async function snapRows(page) {
  return page.evaluate(() => Array.from(
    document.querySelectorAll('[data-kr-section-text] [data-kr-rows] [data-kr-row]')
  ).map((row) => {
    const name = row.querySelector('[data-kr-name]');
    const label = row.querySelector('[data-kr-label]');
    const up = row.querySelector('[data-kr-up]');
    const down = row.querySelector('[data-kr-down]');
    return {
      nameField: name ? name.getAttribute('name') : '',
      nameValue: name ? String(name.value).trim() : '',
      label: label ? String(label.textContent).trim() : '',
      upHidden: up ? up.hidden : null,
      downHidden: down ? down.hidden : null,
    };
  }));
}

/** Fill one typed row by its index in the active section. */
async function fillRow(page, index, name, qty) {
  const row = page.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').nth(index);
  await row.locator('[data-kr-name]').fill(name);
  await row.locator('[data-kr-qty]').fill(qty);
  // Index 0 is the "Unit" placeholder; index 1 is the first real unit. The
  // storefront only offers active units, so a typed line can never name a
  // retired one from here.
  const unit = row.locator('[data-kr-unit]');
  const unitCount = await unit.locator('option').count();
  ok(unitCount >= 2, `${WIDTHS_LABEL}: the unit picker offers at least one real unit`, `(${unitCount} options)`);
  await unit.selectOption({ index: 1 });
  const chosen = await unit.inputValue();
  ok(chosen !== '', `${WIDTHS_LABEL}: a typed line can choose a real unit`);
}

let WIDTHS_LABEL = ''; // set per viewport so the helpers above can label failures

const executablePath = findChromium();
const browser = await chromium.launch({
  args: ['--no-sandbox'],
  ...(executablePath ? { executablePath } : {}),
});

try {
  for (const viewport of WIDTHS) {
    WIDTHS_LABEL = viewport.name;
    const context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await signIn(page, '/account.php', HOUSEHOLD, PASSWORD);
    await page.goto(BASE + '/kitchen-runs.php', { waitUntil: 'domcontentloaded' });

    ok(await page.locator('[data-kr-form]').count() === 1, `${viewport.name}: the Kitchen Run form renders for a signed-in customer`);
    const cap = await page.locator('[data-kr-form]').getAttribute('data-max-lines');
    ok(cap !== null && Number(cap) > 0, `${viewport.name}: the form carries its line cap from the server`, `(data-max-lines=${cap})`);

    // Step 1 to step 2: the mode is "Type my list" by default, so the typed
    // section is the live one and the shop section is switched off.
    await page.locator('[data-kr-next="1"]').click();
    ok(await page.locator('[data-kr-step="2"]').isVisible(), `${viewport.name}: Continue moves to the items step`);
    ok(!(await page.locator('[data-kr-step="1"]').isVisible()), `${viewport.name}: the mode step is left behind`);
    ok(await page.locator('[data-kr-section-text]').isVisible(), `${viewport.name}: the typed-lines section is the live one`);
    ok(!(await page.locator('[data-kr-section-shop]').isVisible()), `${viewport.name}: the shop section is switched off for a typed list`);

    // One empty line to start.
    ok(await page.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').count() === 1,
      `${viewport.name}: a typed list opens with one empty line`);

    await fillRow(page, 0, 'Pomo', '6');

    // The regression: press "Add item". The button is a sibling of the rows, so
    // the old closest() walk found nothing and this press did nothing at all.
    await page.locator('[data-kr-section-text] [data-kr-add]').click();
    ok(await page.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').count() === 2,
      `${viewport.name}: "Add item" adds a second line, not silently no-op`);
    await fillRow(page, 1, 'Palm oil', '4');

    // Renumbering and the reorder buttons after an add.
    let snap = await snapRows(page);
    ok(snap.length === 2, `${viewport.name}: two lines are on the list`);
    ok(snap[0].nameField === 'items[0][item_name]' && snap[1].nameField === 'items[1][item_name]',
      `${viewport.name}: the two lines post as items[0] and items[1]`);
    ok(snap[0].label === 'Item 1' && snap[1].label === 'Item 2', `${viewport.name}: each line is labelled by its number`);
    ok(snap[0].upHidden === true && snap[0].downHidden === false, `${viewport.name}: the first line cannot move up but can move down`);
    ok(snap[1].upHidden === false && snap[1].downHidden === true, `${viewport.name}: the last line can move up but cannot move down`);

    // Reorder: move the second line (Palm oil) up. The two trade places, the
    // field names stay items[0]/items[1] in the new order, and the end buttons
    // follow.
    await page.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').nth(1).locator('[data-kr-up]').click();
    snap = await snapRows(page);
    ok(snap.length === 2, `${viewport.name}: reordering keeps both lines`);
    ok(snap[0].nameValue === 'Palm oil' && snap[1].nameValue === 'Pomo',
      `${viewport.name}: moving the second line up puts it first`, `(${snap.map((r) => r.nameValue).join(', ')})`);
    ok(snap[0].nameField === 'items[0][item_name]' && snap[1].nameField === 'items[1][item_name]',
      `${viewport.name}: the moved line is renumbered to its new position`);
    ok(snap[0].upHidden === true && snap[1].downHidden === true, `${viewport.name}: after the move the ends are back at the ends`);

    // Remove the second line. One remains, renumbered, with both ends closed.
    await page.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').nth(1).locator('[data-kr-remove]').click();
    snap = await snapRows(page);
    ok(snap.length === 1, `${viewport.name}: removing a line leaves the rest`);
    ok(snap[0].nameValue === 'Palm oil', `${viewport.name}: the surviving line is the one that stayed`);
    ok(snap[0].nameField === 'items[0][item_name]', `${viewport.name}: the survivor is renumbered to items[0]`);
    ok(snap[0].upHidden === true && snap[0].downHidden === true, `${viewport.name}: a lone line can move neither way`);

    // The whole items step must not spill sideways on a phone, reorder buttons
    // and all.
    const overflow = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    ok(overflow[0] <= overflow[1] + 1, `${viewport.name}: the items step has no horizontal overflow`, `(${overflow[0]} > ${overflow[1]})`);

    // To delivery, fill the address, and send. The day and area already carry a
    // valid default from the server; pressing a chip is the customer's choice.
    await page.locator('[data-kr-next="2"]').click();
    ok(await page.locator('[data-kr-step="3"]').isVisible(), `${viewport.name}: the list passes its own checks and reaches delivery`);
    await page.locator('details:has(#kr-addr1) > summary').click();
    await page.fill('#kr-recipient', 'Kitchen Owner');
    await page.fill('#kr-phone', '08031234567');
    await page.fill('#kr-addr1', '5 Bourdillon Road');
    await page.fill('#kr-city', 'Lagos');
    await page.fill('#kr-state', 'Lagos');
    if (await page.locator('[data-kr-day]').count() > 0) { await page.locator('[data-kr-day]').first().click(); }
    if (await page.locator('[data-kr-zone-btn]').count() > 0) { await page.locator('[data-kr-zone-btn]').first().click(); }

    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/request=\d+&submitted=1/, { timeout: 20000 });
    ok(page.url().includes('submitted=1'), `${viewport.name}: a typed list is sent and the customer is taken to it`);
    ok((await page.content()).includes('List received. We will price it.'),
      `${viewport.name}: the run is recorded as waiting on the team, and the customer is told so`);
    ok(errors.length === 0, `${viewport.name}: the whole flow runs with no JavaScript errors`, errors.join(', '));

    // The fallback the fix rests on: the same send with JavaScript off. A fresh
    // context reuses the session cookies, then the form posts natively. The
    // noscript style reveals the wizard as one flat form and the controller
    // answers with a redirect rather than JSON.
    const noJsContext = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      hasTouch: viewport.touch,
      isMobile: viewport.touch,
      javaScriptEnabled: false,
    });
    try {
      await noJsContext.addCookies(await context.cookies(BASE + '/kitchen-runs.php'));
      const noJs = await noJsContext.newPage();
      await noJs.goto(BASE + '/kitchen-runs.php', { waitUntil: 'domcontentloaded' });
      ok(await noJs.locator('[data-kr-form]').count() === 1, `${viewport.name}: with no JavaScript the form is still there`);
      // The address sits in a disclosure that opens natively, with no script.
      await noJs.locator('details:has(#kr-addr1) > summary').click();
      await noJs.fill('#kr-recipient', 'Kitchen Owner');
      await noJs.fill('#kr-phone', '08031234567');
      await noJs.fill('#kr-addr1', '5 Bourdillon Road');
      await noJs.fill('#kr-city', 'Lagos');
      await noJs.fill('#kr-state', 'Lagos');
      const noJsRow = noJs.locator('[data-kr-section-text] [data-kr-rows] [data-kr-row]').first();
      await noJsRow.locator('[data-kr-name]').fill('Stock fish');
      await noJsRow.locator('[data-kr-qty]').fill('2');
      await noJsRow.locator('[data-kr-unit]').selectOption({ index: 1 });
      await noJs.locator('button[type="submit"]').click();
      await noJs.waitForURL(/request=\d+&submitted=1/, { timeout: 20000 });
      ok(noJs.url().includes('submitted=1'), `${viewport.name}: a typed list sends with no JavaScript at all`);
      ok((await noJs.content()).includes('List received. We will price it.'),
        `${viewport.name}: the no-JavaScript send is recorded and confirmed`);
    } finally {
      await noJsContext.close();
    }

    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} Kitchen Run browser assertions passed.`);
process.exit(passed === checks ? 0 : 1);
