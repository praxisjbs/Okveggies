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
    ok(parseFloat(motion) <= 0.01, `${viewport.name}: reduced motion collapses the hero animation`, `(${motion})`);
    const metadata = await page.evaluate(() => ({
      canonical: document.querySelector('link[rel=canonical]')?.href || '',
      og: document.querySelector('meta[property="og:url"]')?.content || '',
    }));
    ok(metadata.canonical !== '' && metadata.canonical === metadata.og, `${viewport.name}: canonical and Open Graph URLs match`);

    const performance = await page.evaluate(() => {
      const paint = performance.getEntriesByType('paint').find((entry) => entry.name === 'first-contentful-paint');
      const bytes = performance.getEntriesByType('resource').reduce((sum, entry) => sum + (entry.transferSize || entry.encodedBodySize || 0), 0);
      const navigation = performance.getEntriesByType('navigation')[0];
      return {
        fcp: paint?.startTime || 0,
        bytes,
        responseStart: navigation?.responseStart || 0,
        responseEnd: navigation?.responseEnd || 0,
        domContentLoaded: navigation?.domContentLoadedEventEnd || 0,
      };
    });
    ok(performance.fcp > 0 && performance.fcp < 3000, `${viewport.name}: throttled first contentful paint is under 3 seconds`,
      `(fcp ${Math.round(performance.fcp)}ms, response ${Math.round(performance.responseStart)}-${Math.round(performance.responseEnd)}ms, dom ${Math.round(performance.domContentLoaded)}ms)`);
    ok(performance.bytes < 2_000_000, `${viewport.name}: initial transferred resources stay under 2MB`, `(${performance.bytes} bytes)`);
    await context.close();
  }
} finally {
  await browser.close();
}

console.log(`\n${passed} / ${checks} homepage browser assertions passed.`);
process.exit(passed === checks ? 0 : 1);
