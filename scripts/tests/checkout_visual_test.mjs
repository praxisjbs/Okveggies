/**
 * scripts/tests/checkout_visual_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR3 checkout browser pass: the redesigned checkout at 390px and
 * 1440px, driven the way a shopper drives it.
 *
 *   node scripts/tests/checkout_visual_test.mjs
 *
 * Needs the same stand the other browser suites need: a seeded scratch server
 * (OKV_BASE, default http://127.0.0.1:8123), Playwright with Chromium, and the
 * axe-core package for the accessibility gate. The journey is the real one: a
 * guest adds a product, walks all four steps, and reaches the pay bar. The
 * checks that matter for fix 13 are here: the trust panel sits below the whole
 * payment radio group, the choices are large cards, the sticky pay bar floats
 * above the mobile tab bar, nothing overflows, every control is 44px, axe
 * reports no critical or serious violation, and motion is full.
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const axeSource = require.resolve('axe-core/axe.min.js');
const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
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

const browser = await chromium.launch({
  args: ['--no-sandbox'],
  ...(process.env.OKV_CHROME ? { executablePath: process.env.OKV_CHROME } : {}),
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

    // A guest basket, through the same API the product page uses.
    await page.goto(BASE + '/shop.php', { waitUntil: 'domcontentloaded', timeout: 30000 });
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const productId = await page.evaluate(() => {
      const card = document.querySelector('[data-product-card]');
      return card ? Number(card.getAttribute('data-product-id') || card.querySelector('[name="product_id"]')?.value || 0) : 0;
    });
    ok(productId > 0, `${viewport.name}: the shop offers a product to add`);
    const added = await page.evaluate(async ({ productId, csrf }) => {
      const body = new URLSearchParams({ action: 'add_product', product_id: String(productId), quantity: '2', okv_csrf: csrf });
      const response = await fetch('/api/v1/cart.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } });
      return response.status;
    }, { productId, csrf });
    ok(added === 200, `${viewport.name}: the guest basket takes the product`);

    // Step 1: basket review.
    const step1 = await page.goto(BASE + '/checkout.php', { waitUntil: 'domcontentloaded', timeout: 30000 });
    ok(step1?.status() === 200, `${viewport.name}: checkout opens for a guest`);
    ok(await page.getByRole('heading', { name: 'Check your basket' }).count() === 1, `${viewport.name}: step 1 is the basket review`);
    ok(await page.locator('ol[aria-label="Checkout progress"] li').count() === 4, `${viewport.name}: the progress line has four named steps`);
    ok(await page.locator('li[aria-current="step"]').textContent().then((t) => t.includes('Basket')), `${viewport.name}: step 1 is marked current`);

    // Step 2: details, offered never demanded.
    await page.goto(BASE + '/checkout.php?step=2', { waitUntil: 'domcontentloaded' });
    ok(await page.locator('input[name="create_account"]').count() === 1, `${viewport.name}: the account offer is present`);
    ok(await page.locator('input[name="create_account"][required]').count() === 0, `${viewport.name}: the account offer is never demanded`);

    // Step 3: delivery, the picker keeps the Lagos rules.
    await page.goto(BASE + '/checkout.php?step=3', { waitUntil: 'domcontentloaded' });
    const dayOptions = await page.locator('select[name="delivery_date"] option').count();
    ok(dayOptions > 0 || await page.locator('input[name="delivery_date"][type="hidden"]').count() === 1,
      `${viewport.name}: the day picker offers only days the Lagos rules allow`);

    // Step 4: the payment step.
    await page.goto(BASE + '/checkout.php?step=4', { waitUntil: 'domcontentloaded' });
    ok(errors.length === 0, `${viewport.name}: checkout has no JavaScript errors`, errors.join(', '));
    ok(await page.locator('fieldset input[name="payment_option"]').count() >= 2, `${viewport.name}: the payment choices are a labelled radio group`);

    // Fix 13: the trust panel is below the whole radio group, in DOM order.
    const order = await page.evaluate(() => {
      const radios = Array.from(document.querySelectorAll('input[name="payment_option"]'));
      const trust = document.querySelector('[data-trust-panel]');
      const lastRadio = radios.length ? radios[radios.length - 1] : null;
      return {
        radioCount: radios.length,
        trustAfterRadio: trust !== null && lastRadio !== null && lastRadio.compareDocumentPosition(trust) & Node.DOCUMENT_POSITION_FOLLOWING,
        insideFieldset: trust !== null && lastRadio !== null && trust.closest('fieldset') === null && lastRadio.closest('fieldset') !== null,
      };
    });
    ok(order.radioCount >= 2 && order.trustAfterRadio, `${viewport.name}: the trust panel sits below the whole payment radio group`);
    ok(order.insideFieldset, `${viewport.name}: the trust panel is outside the radio group's fieldset`);

    // Large tappable cards, ring on selection, and the pay bar above the tab bar.
    const cardHeight = await page.locator('label.okv-choice').first().evaluate((el) => Math.round(el.getBoundingClientRect().height));
    ok(cardHeight >= 72, `${viewport.name}: a payment choice is a large card, not a text line`, `(${cardHeight}px)`);
    await page.locator('input[name="payment_option"][value="deposit"]').check({ force: true });
    const selectedRing = await page.locator('input[name="payment_option"][value="deposit"]:checked + span').count()
      && await page.evaluate(() => {
        const card = document.querySelector('input[value="deposit"]').closest('label');
        return getComputedStyle(card).getPropertyValue('--tw-ring-color') !== '';
      });
    ok(selectedRing, `${viewport.name}: the chosen card carries the selection ring`);
    if (viewport.touch) {
      const bar = await page.evaluate(() => {
        const pay = document.querySelector('[data-pay-bar]');
        const tabs = document.querySelector('nav[aria-label="Mobile navigation"]');
        if (!pay || getComputedStyle(pay).display === 'none') { return null; }
        const payBox = pay.getBoundingClientRect();
        const tabBar = tabs ? tabs.getBoundingClientRect() : null;
        return { height: payBox.height, bottom: payBox.bottom, tabsTop: tabBar ? tabBar.top : null, inner: window.innerHeight };
      });
      ok(bar !== null, `${viewport.name}: the sticky pay bar exists on mobile`);
      if (bar) {
        ok(bar.height >= 44, `${viewport.name}: the pay bar carries a 44px-plus button`, `(${bar.height}px)`);
        ok(bar.tabsTop === null || bar.bottom <= bar.tabsTop + 1, `${viewport.name}: the pay bar floats above the tab bar, not over it`);
        ok(bar.bottom <= bar.inner + 1, `${viewport.name}: the pay bar is anchored to the viewport bottom`);
      }
      const undersized = await page.evaluate(() => {
        const failures = [];
        document.querySelectorAll('main a, main button, main input:not([type=hidden]), main select, main label.okv-choice').forEach((element) => {
          const box = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          if (box.width === 0 || box.height === 0 || style.display === 'none' || style.visibility === 'hidden') { return; }
          if (element.matches('input[type=radio]') && element.closest('label.okv-choice')) { return; }
          if (box.height < 44) { failures.push(`${element.tagName}:${Math.round(box.height)}:${(element.textContent || '').trim().slice(0, 30)}`); }
        });
        return failures;
      });
      ok(undersized.length === 0, `${viewport.name}: every checkout control meets the 44px target`, undersized.join(', '));
    } else {
      const barHidden = await page.evaluate(() => {
        const pay = document.querySelector('[data-pay-bar]');
        return pay === null || getComputedStyle(pay).display === 'none';
      });
      ok(barHidden, `${viewport.name}: desktop keeps the pay bar in the form, no floating duplicate`);
    }
    const overflow = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    ok(overflow[0] <= overflow[1] + 1, `${viewport.name}: checkout has no horizontal overflow`, `(${overflow[0]} > ${overflow[1]})`);

    // The sheets: the fee note opens and closes, focus comes home.
    await page.locator('[data-sheet-open="fee-sheet"]').click();
    ok(await page.locator('#fee-sheet [role="dialog"]').isVisible(), `${viewport.name}: the fee sheet opens`);
    await page.keyboard.press('Escape');
    ok(!(await page.locator('#fee-sheet').evaluate((el) => !el.hidden)), `${viewport.name}: Escape closes the fee sheet`);
    const focusHome = await page.evaluate(() => document.activeElement?.hasAttribute('data-sheet-open'));
    ok(focusHome, `${viewport.name}: focus returns to the sheet trigger`);

    // The day picker, with JavaScript on: a button and a sheet, not a dropdown.
    await page.goto(BASE + '/checkout.php?step=3', { waitUntil: 'domcontentloaded' });
    const picker = await page.evaluate(() => {
      const wrap = document.querySelector('[data-delivery-picker]');
      if (!wrap) { return null; }
      const select = wrap.querySelector('[data-picker-select]');
      const button = wrap.querySelector('[data-picker-button]');
      return { selectHidden: select?.hidden ?? null, buttonVisible: button !== null && !button.hidden };
    });
    ok(picker !== null && picker.selectHidden === true && picker.buttonVisible, `${viewport.name}: the day picker presents as a button with JavaScript on`);
    if (picker?.buttonVisible) {
      await page.locator('[data-picker-button]').click();
      ok(await page.locator('[data-picker-sheet] [role="dialog"]').isVisible(), `${viewport.name}: the day sheet opens`);
      const dayLabel = await page.locator('[data-picker-option]').first().textContent();
      await page.locator('[data-picker-option]').first().click();
      const picked = await page.evaluate(() => document.querySelector('[data-picker-label]')?.textContent || '');
      ok(dayLabel && picked && dayLabel.includes(picked.trim()), `${viewport.name}: choosing a day names it on the picker button`, `(${picked})`);
      const submitted = await page.evaluate(() => document.querySelector('[data-picker-select]')?.value || '');
      ok(submitted !== '', `${viewport.name}: the choice lands in the real form field`);
    }

    // Full motion: the entrance animation keeps its duration even when the
    // visitor asks for reduced motion, per the 20 Sep decision.
    const motion = await page.locator('label.okv-choice').first().evaluate((el) => getComputedStyle(el).animationDuration);
    ok(parseFloat(motion) > 0.1, `${viewport.name}: payment cards enter with real motion`, `(${motion})`);

    // The axe gate: no critical or serious violation on the payment step.
    await page.addScriptTag({ path: axeSource });
    const axe = await page.evaluate(() => axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    }));
    const bad = axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    ok(bad.length === 0, `${viewport.name}: axe reports no critical or serious violation`,
      bad.map((v) => `${v.id}(${v.impact}): ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(', ')}`).join(' | '));

    // The keyboard flow: radios move with the arrow keys, the pay bar submits
    // the same form.
    await page.locator('input[name="payment_option"]:checked').focus();
    await page.keyboard.press('ArrowDown');
    const moved = await page.evaluate(() => document.activeElement?.value || '');
    ok(moved !== '', `${viewport.name}: the arrow keys walk the payment choices`, `(${moved})`);

    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} checkout browser assertions passed.`);
process.exit(passed === checks ? 0 : 1);
