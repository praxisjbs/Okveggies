/** Focused M12 public-content browser pass at narrow and wide viewports. */
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const BASE = process.env.OKV_BASE || process.env.OKV_TEST_BASE || 'http://127.0.0.1:8123';
const executablePath = process.env.OKV_CHROME || undefined;
let checks = 0;
let passed = 0;
function check(ok, label, detail = '') { checks++; if (ok) { passed++; } else { console.error(`  FAIL: ${label}${detail ? ` (${detail})` : ''}`); } }

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'], ...(executablePath ? { executablePath } : {}) });
try {
  for (const width of [390, 1440]) {
    const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, hasTouch: width === 390, isMobile: width === 390 });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(BASE + '/our-story', { waitUntil: 'networkidle' });
    check(response.status() === 200, `Our Story loads at ${width}px`, String(response.status()));
    check(await page.locator('main h1').count() === 1, `Our Story has one main heading at ${width}px`);
    check(await page.locator('[data-content-body]').count() === 1, `published copy is present at ${width}px`);
    check(await page.locator('[data-support-trigger]').count() === 1, `shared support appears once at ${width}px`);
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    check(dimensions.document <= dimensions.viewport + 1, `Our Story has no horizontal overflow at ${width}px`, `${dimensions.document}/${dimensions.viewport}`);
    const small = await page.evaluate(() => {
      const failures = [];
      document.querySelectorAll('main a, main button, footer a, [data-support-trigger]').forEach(element => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        if (!box.width || !box.height || style.display === 'none' || style.visibility === 'hidden') return;
        if (box.height < 44) failures.push(`${element.tagName}:${Math.round(box.height)}:${String(element.textContent || '').trim().slice(0, 18)}`);
      });
      return failures;
    });
    check(small.length === 0, `visible content controls meet 44px at ${width}px`, small.join(', '));
    check(errors.length === 0, `Our Story has no JavaScript errors at ${width}px`, errors.join('; '));
    await context.close();

    const faqContext = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, hasTouch: width === 390, isMobile: width === 390 });
    const faqPage = await faqContext.newPage();
    const faqErrors = [];
    faqPage.on('pageerror', error => faqErrors.push(error.message));
    const faqResponse = await faqPage.goto(BASE + '/faq', { waitUntil: 'networkidle' });
    check(faqResponse.status() === 200, `FAQ loads at ${width}px`, String(faqResponse.status()));
    check(await faqPage.locator('[data-faq-item]').count() === 2, `FAQ renders 2 disclosures at ${width}px`);
    check(await faqPage.locator('[data-faq-item][open]').count() === 1, `FAQ opens only its first answer initially at ${width}px`);
    check(await faqPage.locator('[role="heading"][aria-level="2"]').count() === 2, `FAQ questions retain heading semantics at ${width}px`);
    const summaryBox = await faqPage.locator('summary').first().boundingBox();
    check(summaryBox && summaryBox.height >= 44, `FAQ summary meets 44px at ${width}px`, String(Math.round(summaryBox?.height || 0)));
    await faqPage.locator('[data-faq-expand]').click();
    check(await faqPage.locator('[data-faq-item][open]').count() === 2, `Expand all opens every answer at ${width}px`);
    await faqPage.locator('[data-faq-collapse]').click();
    check(await faqPage.locator('[data-faq-item][open]').count() === 0, `Collapse all closes every answer at ${width}px`);
    await faqPage.locator('summary').first().focus();
    await faqPage.keyboard.press('Enter');
    check(await faqPage.locator('[data-faq-item]').first().getAttribute('open') !== null, `keyboard opens a question at ${width}px`);
    const faqDimensions = await faqPage.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    check(faqDimensions.document <= faqDimensions.viewport + 1, `FAQ has no horizontal overflow at ${width}px`, `${faqDimensions.document}/${faqDimensions.viewport}`);
    check(faqErrors.length === 0, `FAQ has no JavaScript errors at ${width}px`, faqErrors.join('; '));
    await faqContext.close();

    const noJsContext = await browser.newContext({ viewport: { width, height: 844 }, javaScriptEnabled: false });
    const noJsPage = await noJsContext.newPage();
    const noJsResponse = await noJsPage.goto(BASE + '/faq', { waitUntil: 'domcontentloaded' });
    check(noJsResponse.status() === 200, `FAQ loads without JavaScript at ${width}px`, String(noJsResponse.status()));
    await noJsPage.locator('summary').nth(1).click();
    check(await noJsPage.locator('[data-faq-item]').nth(1).getAttribute('open') !== null, `native answer opens without JavaScript at ${width}px`);
    check(await noJsPage.locator('[data-faq-answer]').nth(1).isVisible(), `answer remains visible without JavaScript at ${width}px`);
    await noJsContext.close();
  }
} finally {
  await browser.close();
}
console.log(`\n${passed} / ${checks} public content browser checks passed.`);
process.exit(passed === checks ? 0 : 1);
