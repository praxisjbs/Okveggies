/** M12 homepage accessibility, responsive and throttled-performance checks. */
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
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
      reducedMotion: 'reduce',
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false,
      latency: 150,
      downloadThroughput: 1_600_000 / 8,
      uploadThroughput: 750_000 / 8,
      connectionType: 'cellular3g',
    });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });

    const response = await page.goto(BASE + '/', { waitUntil: 'networkidle', timeout: 30000 });
    ok(response?.status() === 200, `${viewport.name}: homepage returns 200`);
    ok(errors.length === 0, `${viewport.name}: homepage has no JavaScript errors`, errors.join(', '));
    ok(await page.locator('main h1').count() === 1, `${viewport.name}: homepage has exactly 1 main heading`);
    ok(await page.getByRole('heading', { name: /Sourced right|The test promise/i }).count() === 1,
      `${viewport.name}: promise heading is present`);
    for (const path of ['/shop.php', '/combos.php', '/kitchen-runs.php']) {
      ok(await page.locator(`main a[href="${path}"]`).count() > 0, `${viewport.name}: main content links to ${path}`);
    }
    const photo = page.locator('figure img[fetchpriority="high"]');
    const pending = page.getByText('Documentary photograph pending', { exact: true });
    ok((await photo.count()) === 1 || (await pending.count()) === 1,
      `${viewport.name}: hero has an approved image or explicit dependency state`);
    if (await photo.count()) {
      ok((await photo.getAttribute('loading')) !== 'lazy', `${viewport.name}: hero image is not lazy-loaded`);
      ok(((await photo.getAttribute('alt')) || '').trim().length > 0, `${viewport.name}: documentary image has meaningful alt text`);
      ok((await photo.getAttribute('srcset')) !== null, `${viewport.name}: documentary image has responsive sources`);
    }
    const lazyCards = await page.locator('[data-product-card] img, [data-combo-card] img').evaluateAll((images) => images.every((image) => image.loading === 'lazy'));
    ok(lazyCards, `${viewport.name}: below-hero catalogue photographs are lazy-loaded`);
    const overflow = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    ok(overflow[0] <= overflow[1] + 1, `${viewport.name}: homepage has no horizontal overflow`, `(${overflow[0]} > ${overflow[1]})`);
    if (viewport.touch) {
      const undersized = await page.evaluate(() => {
        const failures = [];
        document.querySelectorAll('a, button, input:not([type=hidden]), textarea, select, summary').forEach((element) => {
          const box = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          if (box.width === 0 || box.height === 0 || style.display === 'none' || style.visibility === 'hidden') { return; }
          if (element.matches('input[type=checkbox],input[type=radio]')) { return; }
          if (box.height < 44) { failures.push(`${element.tagName}:${Math.round(box.height)}:${(element.textContent || '').trim().slice(0, 32)}:${String(element.className || '').slice(0, 60)}`); }
        });
        return failures;
      });
      ok(undersized.length === 0, `${viewport.name}: visible controls meet the 44px target`, undersized.join(', '));
    }
    const motion = await page.locator('.animate-okv-rise').evaluate((element) => getComputedStyle(element).animationDuration);
    ok(parseFloat(motion) > 0.1, `${viewport.name}: motion is full even with the reduced-motion preference set`, `(${motion})`);
    const metadata = await page.evaluate(() => ({
      canonical: document.querySelector('link[rel=canonical]')?.href || '',
      og: document.querySelector('meta[property="og:url"]')?.content || '',
    }));
    ok(metadata.canonical !== '' && metadata.canonical === metadata.og, `${viewport.name}: canonical and Open Graph URLs match`);

    const performance = await page.evaluate(() => {
      const paint = performance.getEntriesByType('paint').find((entry) => entry.name === 'first-contentful-paint');
      const lcpEntries = performance.getEntriesByType('largest-contentful-paint');
      const cls = performance.getEntriesByType('layout-shift')
        .filter((entry) => !entry.hadRecentInput)
        .reduce((sum, entry) => sum + entry.value, 0);
      const bytes = performance.getEntriesByType('resource').reduce((sum, entry) => sum + (entry.transferSize || entry.encodedBodySize || 0), 0);
      const navigation = performance.getEntriesByType('navigation')[0];
      const vh = window.innerHeight;
      let words = 0;
      const main = document.querySelector('main') || document.body;
      main.querySelectorAll('h1, h2, h3, p').forEach((el) => {
        if (el.closest('button, a.okv-btn, a.okv-btn-outline, a.okv-btn-outline-invert, [hidden], .okv-sheet-backdrop')) {
          return;
        }
        const style = getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') { return; }
        const box = el.getBoundingClientRect();
        if (box.bottom <= 0 || box.top >= vh) { return; }
        const text = (el.innerText || '').trim();
        if (text) { words += text.split(/\s+/).length; }
      });
      return {
        fcp: paint?.startTime || 0,
        lcp: lcpEntries.length ? lcpEntries[lcpEntries.length - 1].startTime : 0,
        cls,
        bytes,
        words,
        responseStart: navigation?.responseStart || 0,
        responseEnd: navigation?.responseEnd || 0,
        domContentLoaded: navigation?.domContentLoadedEventEnd || 0,
      };
    });
    // Before (M12, no hero photo, same frozen profile): FCP 3,780ms.
    const lcp = performance.lcp > 0 ? performance.lcp : performance.fcp;
    ok(performance.fcp > 0 && performance.fcp <= 3000, `${viewport.name}: throttled first contentful paint is 3.0s or less`,
      `(fcp ${Math.round(performance.fcp)}ms, was 3780ms, response ${Math.round(performance.responseStart)}-${Math.round(performance.responseEnd)}ms, dom ${Math.round(performance.domContentLoaded)}ms)`);
    ok(lcp > 0 && lcp <= 4000, `${viewport.name}: throttled largest contentful paint is 4.0s or less`,
      `(lcp ${Math.round(lcp)}ms)`);
    ok(performance.cls <= 0.1, `${viewport.name}: cumulative layout shift is 0.1 or less`,
      `(cls ${performance.cls.toFixed(3)})`);
    ok(performance.bytes < 2_000_000, `${viewport.name}: initial transferred resources stay under 2MB`, `(${performance.bytes} bytes)`);
    if (viewport.width === 390) {
      ok(performance.words <= 80, `${viewport.name}: first viewport of main stays scannable`,
        `(${performance.words} words in view)`);
    }
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} homepage browser assertions passed.`);
process.exit(passed === checks ? 0 : 1);
