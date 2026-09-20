/**
 * Homepage hero regression at 390px and 1440px, JS on/off and reduced motion.
 * OKV_BASE uses a real PHP site. Without it, serve the actual hero template
 * with known fixture values. Fixture evidence is labelled, never PHP evidence.
 * Artifacts go to OKV_HERO_ARTIFACT_DIR or /tmp/okv-homepage-hero.
 */
import { createRequire } from 'node:module';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { extname, join, resolve } from 'node:path';
import { homepageHeroFixture } from './lib/homepage_hero_fixture.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const root = resolve(import.meta.dirname, '../..');
const axeSource = require.resolve('axe-core/axe.min.js');
const dir = process.env.OKV_HERO_ARTIFACT_DIR || '/tmp/okv-homepage-hero';
const mode = process.env.OKV_BASE ? 'homepage' : 'template-fixture';
const reports = [];
let checks = 0;
let passed = 0;
function ok(condition, label, detail = '') {
  checks++;
  if (condition) { passed++; console.log(`  ok   ${label}`); }
  else { console.error(`  FAIL ${label} ${detail}`); }
}

let server;
let base = process.env.OKV_BASE;
if (!base) {
  const html = homepageHeroFixture(root);
  const types = { '.js': 'text/javascript', '.css': 'text/css', '.webp': 'image/webp', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.ico': 'image/x-icon' };
  server = createServer((req, res) => {
    const path = new URL(req.url, 'http://fixture').pathname;
    if (path === '/') { res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html); return; }
    // Serve only public assets. This fixture is not a PHP source-file server.
    const file = resolve(root, '.' + path);
    if ((!path.startsWith('/assets/') && path !== '/favicon.ico') || !file.startsWith(root + '/')) { res.writeHead(404); res.end(); return; }
    try { const bytes = readFileSync(file); res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' }); res.end(bytes); }
    catch { res.writeHead(404); res.end(); }
  });
  await new Promise((done) => server.listen(0, '0.0.0.0', done));
  base = `http://127.0.0.1:${server.address().port}`;
}
console.log(`[hero] ${mode}: ${base}. ${mode === 'template-fixture' ? 'No PHP or database is exercised.' : 'Read-only browser checks.'}`);
mkdirSync(dir, { recursive: true });

// The observers must exist before navigation paint. getEntriesByType alone
// cannot retrieve buffered layout-shift or largest-contentful-paint entries.
function observe() {
  window.__heroAudit = { cls: 0, lcp: 0 };
  new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) { if (!entry.hadRecentInput) { window.__heroAudit.cls += entry.value; } }
  }).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) { window.__heroAudit.lcp = entry.startTime; }
  }).observe({ type: 'largest-contentful-paint', buffered: true });
}
function inspect() {
  const hero = document.querySelector('[data-okv-hero]');
  const photo = hero.querySelector('[data-okv-parallax]');
  const wash = hero.querySelector('.okv-home-hero-wash');
  const box = (el) => el.getBoundingClientRect().toJSON();
  const nodes = [...hero.querySelectorAll('h1, [data-okv-word], [data-okv-hero-sub], [data-okv-hero-cta], [data-okv-hero-seal], [data-okv-hero-hairline]')];
  const preload = document.querySelector('link[rel="preload"][as="image"]');
  const containers = [];
  for (let el = hero.querySelector('h1').parentElement; el !== hero; el = el.parentElement) {
    const style = getComputedStyle(el);
    containers.push([style.backgroundColor, style.backgroundImage, style.backdropFilter, style.borderTopWidth, style.boxShadow]);
  }
  return {
    hero: box(hero), photo: box(photo), wash: box(wash),
    layout: [hero.offsetWidth, hero.offsetHeight, photo.offsetWidth, photo.offsetHeight, hero.querySelector('h1').offsetHeight],
    image: { src: photo.getAttribute('src'), srcset: photo.getAttribute('srcset'), sizes: photo.sizes, currentSrc: photo.currentSrc, alt: photo.alt, loaded: photo.complete && photo.naturalWidth > 0, width: photo.getAttribute('width'), height: photo.getAttribute('height'), fit: getComputedStyle(photo).objectFit, position: getComputedStyle(photo).objectPosition, priority: photo.fetchPriority, decode: photo.decoding, loading: photo.loading },
    preload: { href: preload.getAttribute('href'), srcset: preload.getAttribute('imagesrcset'), sizes: preload.getAttribute('imagesizes') },
    heading: hero.querySelector('h1').textContent.trim(), intro: hero.querySelector('[data-okv-hero-sub]').textContent.trim(),
    seal: box(hero.querySelector('[data-okv-hero-seal] img')),
    visible: nodes.every((el) => getComputedStyle(el).opacity === '1'),
    still: [hero, photo, ...nodes].every((el) => getComputedStyle(el).transform === 'none' && getComputedStyle(el).animationName === 'none'),
    ctas: [...hero.querySelectorAll('[data-okv-hero-cta] a')].map((el) => ({ text: el.textContent.trim(), href: el.getAttribute('href'), box: box(el), colour: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor })),
    containers, washBackground: getComputedStyle(wash).backgroundImage, washTransform: getComputedStyle(wash).transform,
    overflow: document.documentElement.scrollWidth > innerWidth,
    audit: window.__heroAudit || null,
    downloads: performance.getEntriesByType('resource').filter((entry) => entry.name === photo.currentSrc).map((entry) => entry.name),
  };
}
const covers = (outer, inner) => outer.left <= inner.left + 1 && outer.top <= inner.top + 1 && outer.right >= inner.right - 1 && outer.bottom >= inner.bottom - 1;
const same = (a, b) => ['x', 'y', 'width', 'height'].every((key) => Math.abs(a[key] - b[key]) <= 1);
const luminance = (rgb) => rgb.map((v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (a, b) => { const l = [luminance(a), luminance(b)].sort((x, y) => y - x); return (l[0] + 0.05) / (l[1] + 0.05); };
const rgb = (colour) => colour.match(/[\d.]+/g).slice(0, 3).map(Number);

let browser;
try {
  browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'], ...(process.env.OKV_CHROME ? { executablePath: process.env.OKV_CHROME } : {}) });
  for (const width of [390, 1440]) {
    const height = width === 390 ? 844 : 900;
    for (const state of ['js', 'no-js', 'reduced']) {
      const label = `${width}px ${state}`;
      console.log(`\n[${label}]`);
      const context = await browser.newContext({ viewport: { width, height }, hasTouch: width === 390, isMobile: width === 390, deviceScaleFactor: width === 390 ? 2 : 1, javaScriptEnabled: state !== 'no-js', reducedMotion: state === 'reduced' ? 'reduce' : 'no-preference' });
      const page = await context.newPage();
      const errors = [];
      const requests = [];
      page.on('pageerror', (err) => errors.push(err.message));
      page.on('console', (message) => { if (message.type() === 'error') { errors.push(message.text()); } });
      page.on('request', (request) => { if (request.resourceType() === 'image') { requests.push(request.url()); } });
      if (state !== 'no-js') { await page.addInitScript(observe); }
      const response = await page.goto(base, { waitUntil: 'networkidle' });
      ok(response.status() === 200, `${label}: renders successfully`);
      await page.evaluate(() => document.fonts.ready);

      if (state === 'js') {
        const entrance = await page.evaluate(() => {
          const hero = document.querySelector('[data-okv-hero]');
          const photo = hero.querySelector('[data-okv-parallax]');
          const tween = gsap.getTweensOf(photo).find((t) => t.vars.scale === 1);
          const trigger = ScrollTrigger.getAll().find((t) => t.trigger === hero && t.vars.scrub === 1);
          const all = gsap.globalTimeline.getChildren(true, true, false).filter((t) => t.targets().some((el) => el instanceof Element && hero.contains(el)));
          return { scale: Number(gsap.getProperty(photo, 'scaleX')), duration: tween?.duration(), drift: trigger?.animation?.vars.yPercent, words: hero.querySelectorAll('[data-okv-word]').length, safe: all.every((t) => !['width', 'height', 'top', 'left'].some((key) => key in t.vars)), washTweens: gsap.getTweensOf(hero.querySelector('.okv-home-hero-wash')).length };
        });
        ok(entrance.scale > 1 && entrance.scale <= 1.061 && entrance.duration === 8, `${label}: slow 1.06 to 1 image entrance`);
        ok(entrance.drift === -7, `${label}: parallax is limited to 7%`);
        ok(entrance.words === 10, `${label}: all heading words join the existing timeline`);
        ok(entrance.safe && entrance.washTweens === 0, `${label}: transforms only, with a stable wash`);
        const before = await page.evaluate(inspect);
        await page.waitForTimeout(8200);
        const after = await page.evaluate(inspect);
        ok(JSON.stringify(before.layout) === JSON.stringify(after.layout), `${label}: image entrance does not move layout`);
        ok(await page.locator('[data-okv-parallax]').evaluate((el) => Number(gsap.getProperty(el, 'scaleX')) === 1 && el.style.willChange === ''), `${label}: image settles at scale 1 and clears will-change`);
      }

      const result = await page.evaluate(inspect);
      ok(result.hero.width === width && result.hero.height >= height, `${label}: a full viewport below the header`);
      ok(covers(result.photo, result.hero) && same(result.wash, result.hero), `${label}: image and forest wash cover the complete hero`);
      ok(result.washTransform === 'none', `${label}: overlay remains stable`);
      ok(result.containers.every(([colour, image, blur, border, shadow]) => colour === 'rgba(0, 0, 0, 0)' && image === 'none' && blur === 'none' && border === '0px' && shadow === 'none'), `${label}: copy has no panel, frame or glass effect`);
      ok(result.heading === 'Bringing the Best of the Farm Straight to Your Kitchen.' && result.intro === 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.', `${label}: approved wording is exact`);
      ok(result.visible && result.seal.width >= 120 && result.seal.height >= 120, `${label}: copy and seal remain fully visible`);
      ok(result.ctas.length === 2 && result.ctas.every((cta) => cta.box.width >= 44 && cta.box.height >= 44), `${label}: both CTAs are touch-safe`);
      if (mode === 'template-fixture') {
        ok(result.ctas.map((cta) => `${cta.text}|${cta.href}`).join(';') === 'Start shopping|/shop.php;See the combos|/combos.php', `${label}: current fallback CTA labels and destinations are unchanged`);
        ok(result.image.alt === 'Crates of fresh tomatoes, red and yellow peppers, and onions.', `${label}: descriptive fallback alt is unchanged`);
      }
      ok(result.image.loaded && result.image.fit === 'cover' && result.image.width > 0 && result.image.height > 0, `${label}: semantic image loads with intrinsic dimensions and object-cover`);
      ok(result.image.priority === 'high' && result.image.decode === 'async' && result.image.loading !== 'lazy', `${label}: hero remains high-priority and non-lazy`);
      ok(result.preload.href === result.image.src && result.preload.srcset === result.image.srcset && result.preload.sizes === result.image.sizes && result.image.sizes === '100vw', `${label}: preload and image agree on the full-hero layout`);
      const candidates = result.image.srcset.split(',').map((item) => new URL(item.trim().split(/\s+/)[0], base).href);
      const heroRequests = requests.filter((url) => candidates.includes(url));
      ok(heroRequests.length === 1 && result.downloads.length === 1, `${label}: exactly one hero candidate is downloaded`, heroRequests.join(', '));
      ok(!result.overflow, `${label}: no horizontal overflow`);
      // Conservative AA proof: composite every wash stop over pure white,
      // brighter than any pixel of the produce image, then compare white text.
      const stops = [...result.washBackground.matchAll(/rgba\(([^)]+)\)/g)].map((match) => match[1].split(',').map(Number));
      const minimumContrast = Math.min(...stops.map(([r, g, b, alpha]) => contrast([255, 255, 255], [r, g, b].map((v) => v * alpha + 255 * (1 - alpha)))));
      ok(stops.length >= 2 && minimumContrast >= 4.5, `${label}: white copy passes AA even over the brightest possible image pixel`, String(minimumContrast));
      ok(contrast(rgb(result.ctas[0].colour), rgb(result.ctas[0].background)) >= 4.5, `${label}: primary CTA label passes AA`);
      if (state !== 'no-js') {
        ok(result.audit !== null && result.audit.cls <= 0.1, `${label}: observed CLS stays at or below 0.1`, JSON.stringify(result.audit));
      } else {
        ok(result.still, `${label}: complete static state without JavaScript`);
      }
      if (state === 'reduced') { ok(result.still, `${label}: reduced motion is the final still state`); }
      if (state !== 'no-js') {
        await page.addScriptTag({ path: axeSource });
        const violations = await page.evaluate(() => axe.run(document.querySelector('[data-okv-hero]'), { resultTypes: ['violations'] })
          .then((res) => res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)));
        ok(violations.length === 0, `${label}: hero has no serious or critical axe violations`, violations.join(', '));
      }
      ok(errors.length === 0, `${label}: no console or page errors`, errors.join(' | '));
      await page.screenshot({ path: join(dir, `${mode}-${width}-${state}.png`) });
      reports.push({ mode, width, state, ...result, heroRequests, minimumContrast, errors });

      if (state === 'js') {
        for (const fraction of [0.25, 0.75, 0.98]) {
          await page.evaluate((fraction) => { const hero = document.querySelector('[data-okv-hero]'); window.scrollTo(0, hero.offsetTop + hero.offsetHeight * fraction); }, fraction);
          await page.waitForTimeout(1200);
          const scrolled = await page.evaluate(inspect);
          ok(covers(scrolled.photo, scrolled.hero) && same(scrolled.wash, scrolled.hero), `${label}: no exposed image edges at ${fraction * 100}% scroll`);
          ok(JSON.stringify(result.layout) === JSON.stringify(scrolled.layout), `${label}: parallax does not move layout at ${fraction * 100}% scroll`);
        }
        await page.evaluate(() => { const hero = document.querySelector('[data-okv-hero]'); window.scrollTo(0, hero.offsetTop + hero.offsetHeight + innerHeight / 2); });
        await page.waitForTimeout(200);
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(150);
        ok(await page.locator('[data-okv-parallax]').evaluate((el) => Number(gsap.getProperty(el, 'scaleX')) > 1.04), `${label}: returning to the hero replays the entrance`);
        await page.locator('[data-okv-hero-cta] a').first().focus();
        ok((await page.evaluate(inspect)).visible, `${label}: keyboard focus never waits for hidden CTAs`);
        const focus = await page.locator('[data-okv-hero-cta] a').first().evaluate((el) => { const s = getComputedStyle(el); return [s.outlineColor, s.outlineWidth, s.outlineStyle]; });
        ok(focus[0] === 'rgb(201, 146, 43)' && parseFloat(focus[1]) >= 2 && focus[2] !== 'none', `${label}: gold keyboard focus stays visible`);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.waitForTimeout(100);
        ok((await page.evaluate(inspect)).still, `${label}: changing motion preference cancels the hero safely`);
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await page.waitForTimeout(100);
        ok(await page.locator('[data-okv-word]').count() === 10, `${label}: restoring motion does not duplicate heading words`);
      }
      if (state === 'reduced' && mode === 'template-fixture') {
        // The CMS allows 60 characters. Exercise unbroken labels as well as
        // normal prose without changing real stored or customer-facing copy.
        const fits = await page.evaluate(() => {
          const hero = document.querySelector('[data-okv-hero]');
          hero.querySelector('.okv-eyebrow-invert').textContent = 'X'.repeat(60);
          const links = [...hero.querySelectorAll('[data-okv-hero-cta] a')];
          links.forEach((link) => { link.lastChild.textContent = 'W'.repeat(60); });
          return [...links, hero.querySelector('.okv-eyebrow-invert')].every((el) => {
            const box = el.getBoundingClientRect();
            return box.left >= 0 && box.right <= innerWidth && el.scrollWidth <= el.clientWidth + 1 && el.scrollHeight <= el.clientHeight + 1;
          });
        });
        ok(fits, `${label}: maximum-length CMS labels wrap without clipping or overflow`);
        await page.locator('[data-okv-hero-cta] a').first().dispatchEvent('pointerdown');
        await page.waitForTimeout(150);
        ok(await page.locator('[data-okv-hero-cta] a').first().evaluate((el) => getComputedStyle(el).transform === 'none' && getComputedStyle(el).transitionDuration === '0s'), `${label}: CTA micro-motion is also disabled`);
      }
      ok(errors.length === 0, `${label}: interactions introduce no console or page errors`, errors.join(' | '));
      await context.close();
    }
  }
  // If both vendor copies and CDN backups fail, the dead man switch and
  // controller must leave the same readable, full-screen composition.
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await context.route('**/*', (route) => /(?:gsap|ScrollTrigger)\.min\.js/.test(route.request().url()) ? route.abort() : route.continue());
  const page = await context.newPage();
  await page.goto(base, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2800);
  const fallback = await page.evaluate(inspect);
  ok(fallback.visible && fallback.still && covers(fallback.photo, fallback.hero), 'unavailable motion libraries preserve the complete static hero');
  await context.close();
} finally {
  await browser?.close();
  server?.close();
  writeFileSync(join(dir, `${mode}-report.json`), JSON.stringify({ mode, passed, checks, reports }, null, 2));
}
console.log(`\n${passed} / ${checks} hero browser assertions passed (${mode}). Artifacts: ${dir}`);
process.exit(passed === checks ? 0 : 1);
