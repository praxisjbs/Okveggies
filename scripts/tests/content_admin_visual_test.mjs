/** Focused M12 Page Copy browser pass at the required narrow and wide widths. */
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
const OWNER = process.env.OKV_OWNER || 'owner-probe@example.test';
const PASSWORD = process.env.OKV_OWNER_PASSWORD || 'probe-owner-123';
const executablePath = process.env.OKV_CHROME || undefined;
let checks = 0;
let passed = 0;

function check(ok, label, detail = '') {
  checks++;
  if (ok) { passed++; }
  else { console.error(`  FAIL: ${label}${detail ? ` (${detail})` : ''}`); }
}

async function signIn(page) {
  await page.goto(BASE + '/admin/login.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.getAttribute('input[name="okv_csrf"]', 'value');
  const result = await page.evaluate(async ([base, token, owner, password]) => {
    const response = await fetch(base + '/api/v1/auth.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' },
      body: new URLSearchParams({ action: 'login', identifier: owner, password, okv_csrf: token }),
    });
    return response.status;
  }, [BASE, csrf, OWNER, PASSWORD]);
  check(result === 200, 'the browser fixture Owner signs in', String(result));
}

async function inspect(page, label, width) {
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  check(dimensions.viewport === width, `${label} uses the requested ${width}px viewport`, String(dimensions.viewport));
  check(dimensions.document <= dimensions.viewport + 1, `${label} has no horizontal overflow`, `${dimensions.document}/${dimensions.viewport}`);
  const small = await page.evaluate(() => {
    const failures = [];
    document.querySelectorAll('#okv-admin-main a, #okv-admin-main button, #okv-admin-main input:not([type=hidden]), #okv-admin-main textarea, #okv-admin-main select, #okv-admin-main summary').forEach((element) => {
      const box = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      if (!box.width || !box.height || style.display === 'none' || style.visibility === 'hidden') { return; }
      if (element.type === 'checkbox' || element.type === 'radio' || String(element.className).includes('sr-only')) { return; }
      if (box.height < 44) { failures.push(`${element.tagName}:${Math.round(box.height)}:${String(element.textContent || element.value || '').trim().slice(0, 20)}`); }
    });
    return failures;
  });
  check(small.length === 0, `${label} keeps visible controls at least 44px tall`, small.join(', '));
}

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'], ...(executablePath ? { executablePath } : {}) });
try {
  for (const width of [390, 1440]) {
    const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, hasTouch: width === 390, isMobile: width === 390 });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await signIn(page);
    const response = await page.goto(BASE + '/admin/content.php?tab=page-copy&page=about', { waitUntil: 'networkidle' });
    check(response.status() === 200, `Page Copy loads at ${width}px`, String(response.status()));
    check(await page.locator('h2', { hasText: 'Managed pages' }).count() === 1, `page list is present at ${width}px`);
    check(await page.locator('h2', { hasText: 'Our Story' }).count() >= 1, `selected editor is present at ${width}px`);
    const listBox = await page.locator('h2', { hasText: 'Managed pages' }).locator('xpath=ancestor::section[1]').boundingBox();
    const editorBox = await page.locator('#editor-heading').locator('xpath=ancestor::section[1]').boundingBox();
    check(width === 390 ? editorBox.y > listBox.y : editorBox.x > listBox.x, `list and editor use the agreed ${width === 390 ? 'stacked' : 'two-column'} layout`);
    await inspect(page, 'Page Copy editor', width);
    const faqResponse = await page.goto(BASE + '/admin/content.php?tab=page-copy&page=faq', { waitUntil: 'networkidle' });
    check(faqResponse.status() === 200, `FAQ editor loads at ${width}px`, String(faqResponse.status()));
    check(await page.getByRole('heading', { name: 'FAQ format' }).count() === 1, `FAQ format guidance is present at ${width}px`);
    check(await page.getByRole('heading', { name: 'Published order' }).count() === 1, `FAQ source ordering is visible at ${width}px`);
    check(await page.getByRole('heading', { name: 'Current operational values' }).count() === 1, `FAQ operational tokens are documented at ${width}px`);
    await inspect(page, 'FAQ editor', width);
    const previewResponse = await page.goto(BASE + '/admin/content-preview.php?page=about', { waitUntil: 'networkidle' });
    check(previewResponse.status() === 200, `saved-draft preview loads at ${width}px`, String(previewResponse.status()));
    check(await page.getByText('Staff preview only.', { exact: false }).count() === 1, `preview is visibly marked staff-only at ${width}px`);
    await inspect(page, 'Page Copy preview', width);
    check(errors.length === 0, `Page Copy has no browser JavaScript errors at ${width}px`, errors.join('; '));
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} content admin browser checks passed.`);
process.exit(passed === checks ? 0 : 1);
