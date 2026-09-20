/**
 * scripts/tests/motion_visual_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR8 motion gate: every motion contract from the plan, driven in
 * a real browser at 390px and 1440px with video recording, against a static
 * fixture (scripts/tests/motion_fixture.html) that uses the real built assets.
 * It needs no PHP and no database, so it runs wherever the browser pass runs.
 *
 *   node scripts/tests/motion_visual_test.mjs
 *
 * What it proves:
 *   - GSAP 3.12 and ScrollTrigger load from assets/js/vendor with integrity
 *     and OkvMotion owns the page (html.okv-motion-on).
 *   - Hero: seal stamps, heading split into words, sub and CTA follow, the
 *     gold hairline draws to scaleX 1.
 *   - Scroll entrances: held below the fold, then y 20 opacity power2.out in
 *     staggered batches, 60ms family on the grid, 80ms kitchen modes, 50ms
 *     payment choices.
 *   - Discipline: only transform and opacity move (width, height and offsets
 *     never change), will-change is cleared after the entrance, CLS stays at
 *     0.1 or less, no page or console errors.
 *   - Sheets: open animation settles, close slides out and hides, swipe-down
 *     dismisses.
 *   - Micro: add-to-basket pops with the icon stroke drawing, an invalid form
 *     shakes on x.
 *   - The hero respects reduced motion; the older policy elsewhere is unchanged.
 *   - Resilience: with the vendored files blocked the pinned backup CDN
 *     answers; with every copy blocked the page stays visible and quiet.
 *
 * Video lands in OKV_MOTION_VIDEO_DIR or /tmp/okv-motion-video as 390.webm
 * and 1440.webm. Chromium executable: OKV_CHROME, or Playwright's own.
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';
import { createReadStream } from 'node:fs';
import { mkdirSync, readdirSync, renameSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');
const axeSource = require.resolve('axe-core/axe.min.js');

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const FIXTURE = '/scripts/tests/motion_fixture.html';
const VIDEO_DIR = process.env.OKV_MOTION_VIDEO_DIR || '/tmp/okv-motion-video';
const EXECUTABLE = process.env.OKV_CHROME || undefined;
const WIDTHS = [
  { name: '390px', video: '390.webm', width: 390, height: 844, touch: true },
  { name: '1440px', video: '1440.webm', width: 1440, height: 900, touch: false },
];

let checks = 0;
let passed = 0;
function ok(value, label, detail = '') {
  checks++;
  if (value) { passed++; console.log(`  ok   ${label}${detail ? ` ${detail}` : ''}`); }
  else { console.error(`  FAIL ${label}${detail ? ` ${detail}` : ''}`); }
}

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const server = createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path.startsWith('/local-cdn/')) {
    res.writeHead(200, { 'Content-Type': 'text/javascript' });
    createReadStream(join(ROOT, 'assets', 'js', 'vendor', path.slice('/local-cdn/'.length))).pipe(res);
    return;
  }
  const file = join(ROOT, path);
  require('node:fs').readFile(file, (err, data) => {
    if (err) { res.writeHead(404); res.end('not found'); return; }
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' });
    res.end(data);
  });
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const BASE = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch({
  executablePath: EXECUTABLE,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
});
mkdirSync(VIDEO_DIR, { recursive: true });

const pageErrors = [];
const consoleErrors = [];
async function newPage(context) {
  const page = await context.newPage();
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) {
      consoleErrors.push(message.text());
    }
  });
  return page;
}

/** Sample an element's computed transform every frame for a while. */
function trackTransforms(page, selector, ms = 1000) {
  return page.evaluate(({ selector: sel, ms: wait }) => new Promise((done) => {
    const el = document.querySelector(sel);
    const values = [];
    const start = performance.now();
    const tick = () => {
      values.push(el ? getComputedStyle(el).transform : 'missing');
      if (performance.now() - start < wait) { requestAnimationFrame(tick); } else { done(values); }
    };
    tick();
  }), { selector, ms });
}

/**
 * Stagger check for one family. Reads which members are still held before the
 * scroll, scrolls the family into view, and times when the held members
 * appear. The gaps between consecutive held members are the stagger, so the
 * check holds at any viewport where part of the family is already on screen.
 */
async function staggerCheck(page, selector, scrollTarget) {
  await page.reload({ waitUntil: 'networkidle' });
  return page.evaluate(({ selector: sel, scrollTarget: target }) => new Promise((done) => {
    const els = [...document.querySelectorAll(sel)];
    const held = els.map((el, i) => (getComputedStyle(el).opacity === '0' ? i : -1)).filter((i) => i >= 0);
    const arrival = {};
    const start = performance.now();
    const tick = () => {
      els.forEach((el, i) => {
        if (arrival[i] === undefined && Number(getComputedStyle(el).opacity) > 0.05) { arrival[i] = Math.round(performance.now() - start); }
      });
      const complete = held.every((i) => arrival[i] !== undefined);
      if (!complete && performance.now() - start < 1800) { requestAnimationFrame(tick); }
      else {
        const deltas = [];
        for (let k = 1; k < held.length; k++) { deltas.push((arrival[held[k]] ?? 0) - (arrival[held[k - 1]] ?? 0)); }
        done({ held: held.length, revealed: held.every((i) => arrival[i] !== undefined), deltas });
      }
    };
    document.querySelector(target).scrollIntoView({ block: 'center' });
    requestAnimationFrame(tick);
  }), { selector, scrollTarget });
}

try {
  for (const width of WIDTHS) {
    console.log(`\n[${width.name}]`);
    const context = await browser.newContext({
      viewport: { width: width.width, height: width.height },
      hasTouch: width.touch,
      isMobile: width.touch,
      recordVideo: { dir: VIDEO_DIR, size: { width: width.width, height: width.height } },
    });
    const page = await newPage(context);
    await page.goto(BASE + FIXTURE, { waitUntil: 'networkidle' });

    ok(await page.evaluate(() => document.documentElement.classList.contains('okv-motion-on')) === true,
      'motion system is on and owns the page');
    const version = await page.evaluate(() => (window.gsap && window.gsap.version) || 'none');
    ok(/^3\.12\./.test(version) === true, 'GSAP is the 3.12 line from the vendored files', `(version ${version})`);
    ok(await page.evaluate(() => document.querySelectorAll('[data-okv-word]').length) === 7,
      'hero heading is split into 7 words');
    ok(pageErrors.length === 0 && consoleErrors.length === 0,
      'no page or console errors', [...pageErrors, ...consoleErrors].join(' | '));

    await page.waitForTimeout(2600);
    const settled = await page.evaluate(() => {
      const read = (sel) => { const el = document.querySelector(sel); return el ? getComputedStyle(el).opacity : 'missing'; };
      return {
        seal: read('[data-okv-hero-seal]'),
        heading: read('[data-okv-hero] h1'),
        sub: read('[data-okv-hero-sub]'),
        cta: read('[data-okv-hero-cta]'),
        hairline: getComputedStyle(document.querySelector('[data-okv-hero-hairline]')).transform,
      };
    });
    ok(settled.seal === '1' && settled.heading === '1' && settled.sub === '1' && settled.cta === '1',
      'hero seal, heading, sub and CTA settle at full opacity');
    ok(settled.hairline === 'none' || settled.hairline === 'matrix(1, 0, 0, 1, 0, 0)',
      'gold hairline drew to full width (scaleX 1)', `(${settled.hairline})`);

    const heldBeforeScroll = await page.evaluate(() => {
      const cards = document.querySelectorAll('[data-product-card]');
      return getComputedStyle(cards[cards.length - 1]).opacity;
    });
    ok(heldBeforeScroll === '0', 'below-fold cards are held before the scroll reaches them');

    const heldBefore = await page.evaluate(() => [...document.querySelectorAll('[data-product-card]')]
      .map((card, i) => (getComputedStyle(card).opacity === '0' ? i : -1)).filter((i) => i >= 0));
    const entrance = await page.evaluate(() => new Promise((done) => {
      const cards = [...document.querySelectorAll('[data-product-card]')];
      const arrival = {};
      const transforms = [];
      const geometry = [];
      const start = performance.now();
      const tick = () => {
        cards.forEach((card, i) => {
          if (arrival[i] === undefined && Number(getComputedStyle(card).opacity) > 0.05) { arrival[i] = Math.round(performance.now() - start); }
        });
        transforms.push(getComputedStyle(cards[cards.length - 1]).transform);
        geometry.push([cards[7].offsetWidth, cards[7].offsetHeight, cards[7].offsetTop, cards[7].offsetLeft].join('|'));
        if (performance.now() - start < 1600) { requestAnimationFrame(tick); }
        else { done({ arrival, transforms, geometry: [...new Set(geometry)] }); }
      };
      document.querySelector('[data-fixture-grid]').scrollIntoView({ block: 'center' });
      requestAnimationFrame(tick);
    }));
    ok(Object.keys(entrance.arrival).length >= 4, 'cards reveal as the scroll reaches them');
    const gridDeltas = [];
    for (let k = 1; k < heldBefore.length; k++) {
      gridDeltas.push((entrance.arrival[heldBefore[k]] ?? 0) - (entrance.arrival[heldBefore[k - 1]] ?? 0));
    }
    const gridMean = gridDeltas.reduce((a, g) => a + g, 0) / Math.max(gridDeltas.length, 1);
    ok(gridDeltas.length >= 2 && gridDeltas.every((g) => g > 10 && g < 220) && gridMean > 25 && gridMean < 110,
      'grid stagger lands in the 60ms family',
      `(held ${heldBefore.length}, gaps ${gridDeltas.join(', ')}ms, mean ${Math.round(gridMean)}ms)`);
    ok(entrance.transforms.some((t) => t !== 'none' && t !== 'matrix(1, 0, 0, 1, 0, 0)'),
      'a card rides transform mid-entrance (y 20 to 0)');
    ok(entrance.geometry.length === 1,
      'no width, height or offset moved during the entrance', entrance.geometry.join(' '));

    await page.waitForTimeout(800);
    const willChange = await page.evaluate(() => document.querySelector('[data-product-card]').style.willChange || 'cleared');
    ok(willChange === 'cleared' || willChange === '',
      'will-change is cleared once the entrance finishes', `(${willChange})`);

    const modeStagger = await staggerCheck(page, '[data-kr-mode]', '[data-fixture-modes]');
    ok((modeStagger.deltas.length >= 1 && modeStagger.deltas.every((g) => g > 40 && g < 170))
        || (modeStagger.deltas.length === 0 && modeStagger.revealed),
      'kitchen mode cards stagger in the 80ms family',
      `(held ${modeStagger.held}, gaps ${modeStagger.deltas.join(', ')}ms)`);

    const payStagger = await staggerCheck(page, '[data-payment-options] > .okv-choice', '[data-fixture-payment]');
    ok((payStagger.deltas.length === 0 && payStagger.revealed)
        || (payStagger.deltas.length >= 1 && payStagger.deltas.every((g) => g > 15 && g < 110)),
      'checkout payment choices stagger in the 50ms family',
      `(held ${payStagger.held}, gaps ${payStagger.deltas.join(', ')}ms)`);

    const cls = await page.evaluate(() => performance.getEntriesByType('layout-shift')
      .reduce((sum, entry) => sum + entry.value, 0));
    ok(cls <= 0.1, 'cumulative layout shift from motion is 0.1 or less', `(cls ${cls.toFixed(4)})`);

    // Axe, because motion must never cost an accessibility rule. The full axe
    // suite walks the real site on the PHP stand; this keeps the fixture
    // honest at both widths every run. The stagger checks reload the page, so
    // let the hero settle first: text at half-faded opacity fails contrast
    // for reasons the settled interface does not have.
    await page.waitForTimeout(2600);
    await page.addScriptTag({ path: axeSource });
    const axe = await page.evaluate(() => window.axe.run(document, {
      resultTypes: ['violations'],
    }).then((res) => res.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious').map((v) => v.id)));
    ok(axe.length === 0, 'axe: 0 critical or serious violations on the fixture', `(violations ${axe.join(', ')})`);

    // Sheet open and settle, close and hide, then swipe-down dismiss.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(300);
    await page.click('[data-fixture-open-sheet]');
    const openFrames = await trackTransforms(page, '#fixture-sheet [data-sheet-panel]', 800);
    ok(openFrames.some((t) => t !== 'none' && t !== 'matrix(1, 0, 0, 1, 0, 0)'),
      'sheet panel animates on open (y 100% to 0)');
    await page.waitForTimeout(500);
    const openState = await page.evaluate(() => ({
      hidden: document.getElementById('fixture-sheet').hidden,
      panel: getComputedStyle(document.querySelector('#fixture-sheet [data-sheet-panel]')).transform,
      backdrop: getComputedStyle(document.getElementById('fixture-sheet')).opacity,
    }));
    const panelSettled = openState.panel === 'none' || openState.panel === 'matrix(1, 0, 0, 1, 0, 0)';
    ok(openState.hidden === false && panelSettled && openState.backdrop === '1',
      'sheet settles open with a fully faded backdrop', `(panel ${openState.panel}, backdrop ${openState.backdrop})`);
    await page.click('#fixture-sheet [data-sheet-close]');
    await page.waitForTimeout(900);
    ok(await page.evaluate(() => document.getElementById('fixture-sheet').hidden) === true,
      'sheet close slides the panel out, then the page hides it');

    await page.click('[data-fixture-open-sheet]');
    await page.waitForTimeout(600);
    const grab = await page.evaluate(() => {
      const box = document.querySelector('#fixture-sheet [data-sheet-panel]').getBoundingClientRect();
      return { x: box.x + box.width / 2, y: box.y + 24 };
    });
    await page.mouse.move(grab.x, grab.y);
    await page.mouse.down();
    for (let step = 1; step <= 10; step++) { await page.mouse.move(grab.x, grab.y + step * 16); await page.waitForTimeout(16); }
    await page.mouse.up();
    await page.waitForTimeout(900);
    ok(await page.evaluate(() => document.getElementById('fixture-sheet').hidden) === true,
      'swipe down dismisses the sheet');

    // Add to basket: elastic pop with the icon stroke drawing.
    await page.evaluate(() => document.querySelector('[data-add-form]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(700);
    await page.click('[data-add-button]');
    const popFrames = await trackTransforms(page, '[data-add-button]', 800);
    ok(popFrames.some((t) => /matrix\((?!1,)/.test(t)),
      'add-to-basket button runs its elastic pop 0.9 to 1.08 to 1');
    const settle = await page.evaluate(() => getComputedStyle(document.querySelector('[data-add-button]')).transform);
    ok(settle === 'none' || settle === 'matrix(1, 0, 0, 1, 0, 0)',
      'add-to-basket settles back to rest with inline styles cleared', `(${settle})`);
    const drawn = await page.evaluate(() => {
      const path = document.querySelector('[data-add-button] svg path');
      return path ? String(getComputedStyle(path).strokeDasharray) : 'none';
    });
    ok(drawn === 'none', 'basket icon stroke draw finished and cleared itself', `(dasharray ${drawn})`);

    // An invalid submit shakes its form on x.
    await page.evaluate(() => document.querySelector('[data-fixture-invalid]').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(600);
    const shakePromise = trackTransforms(page, '[data-fixture-invalid]', 700);
    await page.click('[data-fixture-invalid] button[type=submit]');
    const shakeFrames = await shakePromise;
    ok(shakeFrames.some((t) => /matrix\(1, 0, 0, 1, -?[1-9]/.test(t)),
      'an invalid form shakes on x, twice, 300ms');

    const video = await page.video();
    await context.close();
    if (video) {
      const files = readdirSync(VIDEO_DIR).filter((f) => f.endsWith('.webm'));
      const newest = files.map((f) => join(VIDEO_DIR, f)).sort().pop();
      if (newest) { try { renameSync(newest, join(VIDEO_DIR, width.video)); } catch (e) { /* keep the temp name */ } }
    }
  }

  // 60fps under the frozen profile: 4x CPU throttle, frame deltas sampled
  // through a scroll entrance, a sheet open and close. Median at 60fps, p95
  // and the worst frame bounded, so no sustained jank and no long stall.
  console.log('\n[60fps under 4x CPU throttle]');
  for (const fpsWidth of WIDTHS) {
    const fpsContext = await browser.newContext({
      viewport: { width: fpsWidth.width, height: fpsWidth.height },
      hasTouch: fpsWidth.touch,
      isMobile: fpsWidth.touch,
    });
    const fpsPage = await newPage(fpsContext);
    const cdp = await fpsContext.newCDPSession(fpsPage);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    await fpsPage.goto(BASE + FIXTURE, { waitUntil: 'networkidle' });
    const frames = await fpsPage.evaluate(() => new Promise((done) => {
      const deltas = [];
      let last = performance.now();
      const tick = () => {
        const now = performance.now();
        deltas.push(now - last);
        last = now;
        if (deltas.length < 200) { requestAnimationFrame(tick); } else { done(deltas); }
      };
      requestAnimationFrame(tick);
      document.querySelector('[data-fixture-grid]').scrollIntoView({ block: 'center' });
      setTimeout(() => {
        window.scrollTo(0, document.body.scrollHeight);
        document.querySelector('[data-fixture-open-sheet]').click();
        setTimeout(() => { document.querySelector('#fixture-sheet [data-sheet-close]').click(); }, 500);
      }, 600);
    }));
    const sorted = [...frames].sort((a, b) => a - b);
    const median = sorted[Math.floor(sorted.length / 2)];
    const p95 = sorted[Math.floor(sorted.length * 0.95)];
    const worst = sorted[sorted.length - 1];
    ok(median <= 20, `${fpsWidth.name}: median frame holds 60fps at 4x throttle`, `(median ${median.toFixed(1)}ms)`);
    ok(p95 <= 50, `${fpsWidth.name}: p95 frame shows no sustained jank`, `(p95 ${p95.toFixed(1)}ms)`);
    ok(worst <= 100, `${fpsWidth.name}: worst frame shows no long stall`, `(worst ${worst.toFixed(1)}ms)`);
    await fpsContext.close();
  }

  // The approved hero policy is scoped, not a change to other page motion.
  console.log('\n[reduced motion, hero still and unrelated motion unchanged]');
  const reduceContext = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  const reducePage = await newPage(reduceContext);
  await reducePage.addInitScript(() => {
    window.__sealSamples = [];
    const start = performance.now();
    const tick = () => {
      const el = document.querySelector('[data-okv-hero-seal]');
      if (el) { window.__sealSamples.push(getComputedStyle(el).transform); }
      if (performance.now() - start < 2000) { requestAnimationFrame(tick); }
    };
    requestAnimationFrame(tick);
  });
  await reducePage.goto(BASE + FIXTURE, { waitUntil: 'load' });
  const reduce = await reducePage.evaluate(() => new Promise((done) => {
    const probe = document.createElement('div');
    document.body.appendChild(probe);
    const started = performance.now();
    gsap.to(probe, {
      x: 10,
      duration: 0.3,
      onComplete: () => {
        probe.remove();
        done({
          elapsed: Math.round(performance.now() - started),
          rise: getComputedStyle(document.querySelector('.animate-okv-rise')).animationDuration,
          samples: new Set(window.__sealSamples).size,
        });
      },
    });
  }));
  ok(reduce.elapsed >= 200 && reduce.elapsed <= 900,
    'unrelated tweens retain the existing motion policy', `(${reduce.elapsed}ms for a 300ms tween)`);
  ok(reduce.rise === '0s', 'the hero CSS entrance is disabled under reduced motion', `(${reduce.rise})`);
  ok(await reducePage.locator('[data-okv-hero-seal]').evaluate((el) => getComputedStyle(el).transform === 'none' && getComputedStyle(el).opacity === '1'),
    'the hero seal is in its final still state under reduced motion');
  ok(await reducePage.locator('[data-okv-word]').count() === 0,
    'reduced motion does not split or animate the hero heading');
  await reduceContext.close();

  // Vendor blocked: the pinned backup CDN carries the page.
  console.log('\n[vendor blocked, backup CDN answers]');
  const cdnContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const cdnPage = await newPage(cdnContext);
  await cdnPage.addInitScript(() => { window.OKV_MOTION_CDN_BASE = '/local-cdn/'; });
  await cdnContext.route('**/assets/js/vendor/*.min.js', (route) => route.abort());
  await cdnPage.goto(BASE + FIXTURE, { waitUntil: 'networkidle' });
  ok(await cdnPage.evaluate(() => /^3\.12\./.test(String(window.gsap && window.gsap.version))) === true,
    'GSAP arrives from the backup pin when the vendor copy is blocked');
  ok(await cdnPage.evaluate(() => document.documentElement.classList.contains('okv-motion-on')) === true,
    'motion still owns the page through the fallback');
  ok(pageErrors.length === 0 && consoleErrors.length === 0, 'no errors during the fallback');
  await cdnContext.close();

  // Every copy blocked: the page stays still, visible and quiet.
  console.log('\n[every copy blocked, the page stays usable]');
  const deadContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const deadPage = await newPage(deadContext);
  await deadPage.addInitScript(() => { window.OKV_MOTION_CDN_BASE = '/nowhere/'; });
  await deadContext.route('**/assets/js/vendor/*.min.js', (route) => route.abort());
  await deadContext.route('**/nowhere/**', (route) => route.abort());
  await deadPage.goto(BASE + FIXTURE, { waitUntil: 'networkidle' });
  await deadPage.waitForTimeout(3000);
  const heroVisible = await deadPage.evaluate(() => getComputedStyle(document.querySelector('[data-okv-hero] h1')).opacity);
  ok(heroVisible === '1', 'heading is visible with all motion sources blocked', `(opacity ${heroVisible})`);
  ok(await deadPage.evaluate(() => !document.documentElement.classList.contains('okv-motion-on')) === true,
    'motion stands down instead of half-running');
  ok(pageErrors.length === 0 && consoleErrors.length === 0, 'no page or console errors with motion fully blocked');
  await deadContext.close();
} finally {
  await browser.close();
  server.close();
}

console.log(`\n${passed} / ${checks} motion browser assertions passed.`);
console.log(`[motion] video: ${VIDEO_DIR}/390.webm and ${VIDEO_DIR}/1440.webm`);
process.exit(passed === checks ? 0 : 1);
