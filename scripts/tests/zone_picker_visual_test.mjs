/**
 * scripts/tests/zone_picker_visual_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. The searchable delivery-area picker in a real browser, at 390px
 * (touch) and 1440px (mouse), then again with JavaScript off.
 *
 * The page is the real component, rendered by PHP from
 * scripts/tests/lib/zone_picker_fixture.php, with the real stylesheet and the
 * real built script, served by a small local server this test starts. The
 * zones are generated here and handed to the fixture, so the suite proves the
 * picker follows whatever zones it is given: a different count, different
 * names, notes that only some zones carry, and hostile text.
 *
 * It needs no database and no signed-in session, so it runs anywhere PHP,
 * Node and a Chromium are present:
 *
 *   node scripts/tests/zone_picker_visual_test.mjs
 *
 * Environment: OKV_PHP (default php), OKV_CHROME (a Chromium binary, else
 * Playwright's own), OKV_PLAYWRIGHT_PATH (default playwright), and
 * OKV_SHOTS (a folder for screenshots, default a temporary one).
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync, mkdtempSync, readFileSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP = process.env.OKV_PHP || 'php';
const WORK = mkdtempSync(join(tmpdir(), 'okv-zone-picker-'));
const SHOTS = process.env.OKV_SHOTS || join(WORK, 'shots');
mkdirSync(SHOTS, { recursive: true });

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

function findChromium() {
  if (process.env.OKV_CHROME) { return process.env.OKV_CHROME; }
  const root = process.env.PLAYWRIGHT_BROWSERS_PATH;
  if (!root || !existsSync(root)) { return undefined; }
  return readdirSync(root)
    .filter((name) => name.startsWith('chromium-'))
    .sort()
    .reverse()
    .map((name) => join(root, name, 'chrome-linux', 'chrome'))
    .find((file) => existsSync(file));
}

// ---- Zones, generated ----------------------------------------------------------

/** A zone list of any length, with notes on some and not others. */
function makeZones(count, seed) {
  const zones = [];
  for (let i = 0; i < count; i++) {
    const id = 1000 + seed * 100 + i * 3;
    zones.push({
      id,
      name: `Test Area ${seed}-${String(i + 1).padStart(2, '0')}`,
      slug: `test-area-${seed}-${i + 1}`,
      area_note: i % 3 === 0 ? `Street ${i + 1} and Close ${i + 2}` : null,
    });
  }
  return zones;
}

// A hand-made set for the matching journeys. The ids are deliberately not in
// the order the zones are listed, and nothing is named after a real place.
const NAMED = [
  { id: 41, name: 'Harbour Side', slug: 'harbour-side', area_note: 'Old Quay, Ferry Road' },
  { id: 7, name: 'Hilltop', slug: 'hilltop', area_note: null },
  { id: 93, name: 'Riverbend East', slug: 'riverbend-east', area_note: 'Mill Lane, Saint-Anne Close' },
  { id: 12, name: 'Café Row', slug: 'cafe-row', area_note: 'Market (North) gate' },
  { id: 5, name: '<img src=x onerror="window.__pwned=1">', slug: 'hostile', area_note: 'Plot 1.5 & Block [C]' },
  { id: 66, name: 'Garden Estate', slug: 'garden-estate', area_note: 'Palm Avenue, Orchard Way' },
];

// ---- The fixture server ---------------------------------------------------------

const pages = new Map();
function render(name, zones, options = {}) {
  const file = join(WORK, `${name}.json`);
  writeFileSync(file, JSON.stringify(zones));
  const html = execFileSync(PHP, ['scripts/tests/lib/zone_picker_fixture.php', file, JSON.stringify(options)], {
    cwd: ROOT,
    encoding: 'utf8',
    env: process.env,
  });
  pages.set(name, html);
}

const TYPES = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2', '.webmanifest': 'application/manifest+json' };
const server = createServer((req, res) => {
  const url = new URL(req.url, 'http://fixture.test');
  if (url.pathname === '/echo') {
    const params = {};
    for (const [key, value] of url.searchParams) { params[key] = value; }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!doctype html><title>echo</title><pre id="echo">${JSON.stringify(params).replace(/</g, '\\u003c')}</pre>`);
    return;
  }
  if (url.pathname.startsWith('/page/')) {
    const html = pages.get(url.pathname.slice(6));
    res.writeHead(html ? 200 : 404, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(html || 'missing');
    return;
  }
  const file = normalize(join(ROOT, url.pathname));
  if (!file.startsWith(join(ROOT, 'assets')) && !file.endsWith('site.webmanifest')) {
    res.writeHead(404); res.end(); return;
  }
  if (!existsSync(file)) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' });
  res.end(readFileSync(file));
});

// ---- Helpers ----------------------------------------------------------------------

const optionTexts = (page) => page.$$eval('[data-zone-list] [role="option"]', (items) =>
  items.map((item) => item.querySelector('span span').textContent));

async function state(page) {
  return page.evaluate(() => {
    const input = document.querySelector('[data-zone-input]');
    const select = document.querySelector('[data-zone-select]');
    const activeId = input.getAttribute('aria-activedescendant');
    const activeEl = activeId ? document.getElementById(activeId) : null;
    return {
      value: select.value,
      text: input.value,
      expanded: input.getAttribute('aria-expanded'),
      activeId,
      activeExists: !!activeEl,
      activeHasClass: activeEl ? activeEl.classList.contains('is-active') : false,
      activeText: activeEl ? activeEl.querySelector('span span').textContent : '',
      focused: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : '',
      popupHidden: document.querySelector('[data-zone-popup]').hidden,
      options: document.querySelectorAll('[data-zone-list] [role="option"]').length,
    };
  });
}

async function statusText(page) {
  await page.waitForTimeout(320);
  return page.textContent('[data-zone-status]');
}

async function freshPage(context, name) {
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}/page/${name}`);
  await page.waitForSelector('[data-zone-picker][data-zone-ready="1"]', { timeout: 10000 });
  return { page, errors };
}

async function tapOrClick(page, locator, touch) {
  if (touch) { await locator.tap(); } else { await locator.click(); }
}

// ---- Journeys ---------------------------------------------------------------------

async function withJavaScript(browser, viewport) {
  const tag = `[${viewport.name}]`;
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    hasTouch: viewport.touch,
    isMobile: viewport.touch,
  });

  // 1. The combobox replaces the select, and the list is the zones it was given.
  const { page, errors } = await freshPage(context, 'named');
  const setup = await page.evaluate(() => {
    const select = document.querySelector('[data-zone-select]');
    const input = document.querySelector('[data-zone-input]');
    const label = document.querySelector('[data-zone-picker] label');
    return {
      selectHidden: select.hidden && getComputedStyle(select).display === 'none',
      selectRequired: select.required,
      comboShown: !document.querySelector('[data-zone-combo]').hidden,
      labelFor: label.htmlFor,
      inputId: input.id,
      ariaRequired: input.getAttribute('aria-required'),
      role: input.getAttribute('role'),
      expanded: input.getAttribute('aria-expanded'),
      controls: input.getAttribute('aria-controls'),
      listId: document.querySelector('[data-zone-list]').id,
      hintShown: !document.querySelector('[data-zone-hint]').hidden,
    };
  });
  ok(setup.selectHidden, `${tag} the select is hidden once the script runs`);
  ok(setup.comboShown, `${tag} the search box is shown`);
  ok(setup.labelFor === setup.inputId, `${tag} the visible label now names the search box`);
  ok(setup.role === 'combobox' && setup.expanded === 'false', `${tag} a collapsed combobox`);
  ok(setup.controls === setup.listId, `${tag} aria-controls points at the listbox`);
  ok(setup.ariaRequired === 'true' && !setup.selectRequired, `${tag} required moves to the combobox's own check`);
  ok(setup.hintShown, `${tag} the typing hint is shown`);

  await tapOrClick(page, page.locator('[data-zone-input]'), viewport.touch);
  let s = await state(page);
  ok(s.expanded === 'true' && !s.popupHidden, `${tag} pressing the box opens the list`);
  ok(s.options === NAMED.length, `${tag} every zone it was given is listed`, `(${s.options})`);
  const listed = await optionTexts(page);
  ok(JSON.stringify(listed) === JSON.stringify(NAMED.map((z) => z.name)), `${tag} in the order the database gave`);
  ok(await page.evaluate(() => !document.querySelector('[data-zone-list] img') && !window.__pwned), `${tag} a hostile zone name is shown as text, never run`);
  ok(/6 areas to choose from/.test(await statusText(page)), `${tag} the count is announced from the data`);
  await page.screenshot({ path: join(SHOTS, `open-${viewport.name}.png`), fullPage: true });

  // Touch targets, focus ring, and the list staying on screen.
  const sizes = await page.evaluate(() => {
    const rect = (el) => el.getBoundingClientRect();
    const opts = [...document.querySelectorAll('[data-zone-list] [role="option"]')].map((el) => rect(el).height);
    const toggle = rect(document.querySelector('[data-zone-toggle]'));
    const input = document.querySelector('[data-zone-input]');
    const popup = rect(document.querySelector('[data-zone-popup]'));
    return {
      minOption: Math.min(...opts),
      toggleW: toggle.width,
      toggleH: toggle.height,
      inputH: rect(input).height,
      outline: getComputedStyle(input).outlineStyle,
      outlineWidth: parseFloat(getComputedStyle(input).outlineWidth),
      popupLeft: popup.left,
      popupRight: popup.right,
      viewport: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
  ok(sizes.minOption >= 44, `${tag} every option is at least 44px tall`, `(${sizes.minOption})`);
  ok(sizes.toggleW >= 44 && sizes.toggleH >= 44, `${tag} the open button is at least 44px square`, `(${sizes.toggleW}x${sizes.toggleH})`);
  ok(sizes.inputH >= 44, `${tag} the box is at least 44px tall`);
  ok(sizes.popupLeft >= 0 && sizes.popupRight <= sizes.viewport, `${tag} the list stays inside the screen`);
  ok(sizes.scrollWidth <= sizes.viewport, `${tag} nothing scrolls sideways`, `(${sizes.scrollWidth} > ${sizes.viewport})`);
  await page.keyboard.press('Escape');
  await page.focus('#before');
  await page.keyboard.press('Tab');
  const ring = await page.evaluate(() => {
    const input = document.activeElement;
    return { id: input.id, style: getComputedStyle(input).outlineStyle, width: parseFloat(getComputedStyle(input).outlineWidth) };
  });
  ok(ring.id === 'delivery_zone_id-search', `${tag} Tab from the field before lands on the search box`, `(${ring.id})`);
  ok(ring.style === 'solid' && ring.width >= 2, `${tag} the focused box shows a visible focus ring`);

  // 2. Typing filters by name, by note, by several words, in any case.
  const input = page.locator('[data-zone-input]');
  await input.fill('HARB');
  ok(JSON.stringify(await optionTexts(page)) === '["Harbour Side"]', `${tag} typing matches a name, whatever the case`);
  await input.fill('  ferry  ');
  ok(JSON.stringify(await optionTexts(page)) === '["Harbour Side"]', `${tag} typing matches the database note, trimmed`);
  await input.fill('orchard garden');
  ok(JSON.stringify(await optionTexts(page)) === '["Garden Estate"]', `${tag} several words match across the note and the name`);
  await input.fill('saint anne');
  ok(JSON.stringify(await optionTexts(page)) === '["Riverbend East"]', `${tag} a hyphenated note matches with a space`);
  await input.fill('cafe');
  ok(JSON.stringify(await optionTexts(page)) === '["Café Row"]', `${tag} an unaccented search finds an accented name`);
  ok(/1 area matches/.test(await statusText(page)), `${tag} the match count is announced`);

  // 3. No match.
  await input.fill('qqqq');
  s = await state(page);
  const empty = await page.evaluate(() => {
    const el = document.querySelector('[data-zone-empty]');
    return { hidden: el.hidden, text: el.textContent };
  });
  ok(s.options === 0 && !empty.hidden, `${tag} no match shows a message instead of a list`);
  ok(empty.text.includes('"qqqq"') && /nearby/.test(empty.text), `${tag} the message repeats the search and offers a next step`);
  ok(s.activeId === null, `${tag} nothing is active when nothing matches`);
  ok(/No area matches/.test(await statusText(page)), `${tag} no match is announced`);
  await page.keyboard.press('Enter');
  s = await state(page);
  ok(s.value === '', `${tag} Enter on no match picks nothing`);

  // 4. Special characters are text, not patterns, and nothing throws.
  for (const query of ['.*', '^h', 'a|z', '\\', '[', '(']) {
    await input.fill(query);
  }
  await input.fill('.*');
  ok((await state(page)).options === 0, `${tag} ".*" is not a wildcard`);
  await input.fill('(north)');
  ok(JSON.stringify(await optionTexts(page)) === '["Café Row"]', `${tag} brackets match literally`);
  await input.fill('[c]');
  ok((await optionTexts(page)).length === 1, `${tag} square brackets match literally`);
  await input.fill('<img');
  ok((await optionTexts(page)).length === 1 && await page.evaluate(() => !window.__pwned), `${tag} markup in a search is only text`);
  ok(errors.length === 0, `${tag} no script errors while searching`, errors.join(' | '));

  // 5. Keyboard: Down, Down, Up, wrap, Enter, with aria-activedescendant.
  await input.fill('');
  await page.keyboard.press('Escape');
  await page.keyboard.press('ArrowDown');
  s = await state(page);
  ok(s.expanded === 'true', `${tag} Arrow Down opens the list`);
  ok(s.activeExists && s.activeHasClass && s.activeText === NAMED[0].name, `${tag} and highlights the first area`, `(${s.activeText})`);
  ok(s.focused === 'delivery_zone_id-search', `${tag} focus stays in the box`);
  await page.keyboard.press('ArrowDown');
  s = await state(page);
  ok(s.activeText === NAMED[1].name, `${tag} Arrow Down moves to the next area`);
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('ArrowUp');
  s = await state(page);
  ok(s.activeText === NAMED[NAMED.length - 1].name, `${tag} Arrow Up from the top wraps to the last area`);
  const outline = await page.evaluate(() => getComputedStyle(document.querySelector('[data-zone-list] .is-active')).outlineStyle);
  ok(outline === 'solid', `${tag} the highlighted area carries a visible ring`);
  ok((await page.$$eval('[data-zone-list] .is-active', (items) => items.length)) === 1, `${tag} exactly one area is highlighted`);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  s = await state(page);
  ok(s.value === String(NAMED[1].id), `${tag} Enter writes the highlighted area's id to the select`, `(${s.value})`);
  ok(s.text === NAMED[1].name && s.expanded === 'false' && s.activeId === null, `${tag} the box shows its name and the list closes`);
  ok(/Hilltop chosen/.test(await statusText(page)), `${tag} the choice is announced`);

  // Reopening marks the chosen one with a tick and aria-selected.
  await page.keyboard.press('ArrowDown');
  const selected = await page.$$eval('[data-zone-list] [aria-selected="true"]', (items) => items.map((i) => i.getAttribute('data-value')));
  ok(JSON.stringify(selected) === JSON.stringify([String(NAMED[1].id)]), `${tag} the chosen area is the one option marked selected`);
  ok(await page.evaluate(() => getComputedStyle(document.querySelector('[aria-selected="true"] .okv-zone-check')).visibility === 'visible'), `${tag} and carries a tick, not only a colour`);

  // 6. Escape puts back the chosen area.
  await input.fill('harb');
  await page.keyboard.press('Escape');
  s = await state(page);
  ok(s.expanded === 'false' && s.text === NAMED[1].name && s.value === String(NAMED[1].id), `${tag} Escape closes and restores the chosen area`);

  // 7. Tab with a highlighted area picks it and moves on; without one it only moves on.
  await input.fill('river');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Tab');
  s = await state(page);
  ok(s.value === '93' && s.text === 'Riverbend East', `${tag} Tab on a highlighted area picks it`);
  ok(s.focused === 'after', `${tag} and moves focus to the next field`, `(${s.focused})`);
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.type('garden');
  await page.keyboard.press('Tab');
  s = await state(page);
  ok(s.value === '93' && s.text === 'Riverbend East', `${tag} Tab without a highlighted area keeps the chosen one; typed text is not a choice`);

  // 8. Pointer or tap on an option picks it.
  await tapOrClick(page, page.locator('[data-zone-toggle]'), viewport.touch);
  await tapOrClick(page, page.locator('[data-zone-list] [role="option"][data-value="66"]'), viewport.touch);
  s = await state(page);
  ok(s.value === '66' && s.text === 'Garden Estate' && s.expanded === 'false', `${tag} a ${viewport.touch ? 'tap' : 'click'} on an area picks it`);

  // Enter on a single match picks it without arrowing.
  await input.fill('hilltop');
  await page.keyboard.press('Enter');
  ok((await state(page)).value === '7', `${tag} Enter picks the only match`);

  // 9. What is submitted is the id, never the text.
  await input.fill('Riverbend East');
  await page.keyboard.press('Tab');
  ok((await state(page)).value === '7', `${tag} typing a full name without picking it does not change the id`);
  await page.click('[data-fixture-submit]');
  await page.waitForSelector('#echo');
  const sent = JSON.parse(await page.textContent('#echo'));
  ok(sent.delivery_zone_id === '7', `${tag} the form posts the canonical id`, JSON.stringify(sent));
  ok(!Object.values(sent).includes('Riverbend East') && !Object.values(sent).includes('Hilltop'), `${tag} no display text is posted`);
  ok(Object.keys(sent).sort().join(',') === 'after,before,delivery_zone_id', `${tag} only the form's own fields are posted`, Object.keys(sent).join(','));

  // 10. Back keeps the choice, and the box agrees with the select.
  await page.goBack();
  await page.waitForSelector('[data-zone-picker][data-zone-ready="1"]');
  s = await state(page);
  ok(s.value === '7' && s.text === 'Hilltop', `${tag} Back returns to the area that was chosen`, `(${s.value} / ${s.text})`);
  await page.close();

  // 11. Required: nothing chosen, nothing sent, and the reason is beside the field.
  const required = await freshPage(context, 'named');
  await required.page.click('[data-fixture-submit]');
  await required.page.waitForTimeout(200);
  const refusal = await required.page.evaluate(() => ({
    url: location.pathname,
    error: document.querySelector('[data-zone-error]').textContent,
    errorShown: !document.querySelector('[data-zone-error]').hidden,
    invalid: document.querySelector('[data-zone-input]').getAttribute('aria-invalid'),
    describedBy: document.querySelector('[data-zone-input]').getAttribute('aria-describedby'),
    focused: document.activeElement.id,
  }));
  ok(refusal.url.startsWith('/page/'), `${tag} a form with no area chosen is not sent`);
  ok(refusal.errorShown && /Choose your delivery area/.test(refusal.error), `${tag} it says what is missing`);
  ok(refusal.invalid === 'true' && refusal.describedBy.includes('delivery_zone_id-error'), `${tag} the box is marked invalid and described by the message`);
  ok(refusal.focused === 'delivery_zone_id-search', `${tag} focus goes to the box`);
  await required.page.locator('[data-zone-input]').fill('ferry');
  await required.page.keyboard.press('Enter');
  ok(await required.page.evaluate(() => document.querySelector('[data-zone-error]').hidden), `${tag} choosing an area clears the message`);
  await required.page.close();

  // 12. A saved selection opens chosen.
  const saved = await freshPage(context, 'saved');
  s = await state(saved.page);
  ok(s.value === '93' && s.text === 'Riverbend East', `${tag} a saved area opens already chosen`);
  await saved.page.close();

  // 13. A switched-off area is named, not chosen and not offered.
  const stale = await freshPage(context, 'stale');
  s = await state(stale.page);
  const staleNote = await stale.page.textContent('[data-zone-stale]');
  ok(s.value === '' && s.text === '', `${tag} a switched-off area is not chosen`);
  ok(/Old Wharf is no longer on our delivery list/.test(staleNote), `${tag} and the notice names it`);
  await stale.page.locator('[data-zone-input]').fill('wharf');
  ok((await state(stale.page)).options === 0, `${tag} and it cannot be found in the list`);
  await stale.page.screenshot({ path: join(SHOTS, `stale-${viewport.name}.png`), fullPage: true });
  await stale.page.close();

  // 14. Dynamic zones: a longer list and a shorter one, and the picker follows.
  for (const [name, zones] of [['many', MANY], ['few', FEW]]) {
    const dyn = await freshPage(context, name);
    await dyn.page.locator('[data-zone-input]').click();
    ok((await state(dyn.page)).options === zones.length, `${tag} ${zones.length} zones in, ${zones.length} listed`);
    const pickedZone = zones[zones.length - 1];
    await dyn.page.locator('[data-zone-input]').fill(pickedZone.name.slice(-5));
    await dyn.page.keyboard.press('ArrowDown');
    await dyn.page.keyboard.press('Enter');
    ok((await state(dyn.page)).value === String(pickedZone.id), `${tag} the last of ${zones.length} can be found and chosen by typing`);
    if (name === 'many') {
      await dyn.page.locator('[data-zone-input]').fill('');
      await dyn.page.keyboard.press('End');
      for (let i = 0; i < 3; i++) { await dyn.page.keyboard.press('ArrowUp'); }
      const visible = await dyn.page.evaluate(() => {
        const active = document.querySelector('[data-zone-list] .is-active');
        const popup = document.querySelector('[data-zone-popup]').getBoundingClientRect();
        const r = active.getBoundingClientRect();
        return r.top >= popup.top - 1 && r.bottom <= popup.bottom + 1;
      });
      ok(visible, `${tag} the highlighted area is scrolled into view in a long list`);
      await dyn.page.screenshot({ path: join(SHOTS, `many-${viewport.name}.png`) });
    }
    ok(dyn.errors.length === 0, `${tag} no script errors with ${zones.length} zones`, dyn.errors.join(' | '));
    await dyn.page.close();
  }

  // 15. No zones at all.
  const none = await freshPage(context, 'none');
  await none.page.locator('[data-zone-input]').click();
  ok(await none.page.isVisible('[data-zone-none]'), `${tag} with no active zone the page says so`);
  await none.page.close();

  // 16. Automated accessibility check over the open picker.
  const axePath = join(ROOT, 'node_modules/axe-core/axe.min.js');
  if (existsSync(axePath)) {
    const a11y = await freshPage(context, 'named');
    await a11y.page.addScriptTag({ path: axePath });
    await a11y.page.locator('[data-zone-input]').fill('e');
    await a11y.page.keyboard.press('ArrowDown');
    const result = await a11y.page.evaluate(async () => window.axe.run(document.querySelector('[data-zone-picker]'), {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    }));
    const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    ok(serious.length === 0, `${tag} axe finds no serious or critical issue in the open picker`, serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(', ')}`).join(' | '));
    await a11y.page.close();
  } else {
    console.log('  note: axe-core not installed, accessibility scan skipped');
  }

  await context.close();
}

async function withoutJavaScript(browser, viewport) {
  const tag = `[${viewport.name}, no JavaScript]`;
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  await page.goto(`http://127.0.0.1:${server.address().port}/page/named`);
  const view = await page.evaluate(() => ({
    selectShown: getComputedStyle(document.querySelector('[data-zone-select]')).display !== 'none',
    comboHidden: getComputedStyle(document.querySelector('[data-zone-combo]')).display === 'none',
    required: document.querySelector('[data-zone-select]').required,
    options: document.querySelectorAll('[data-zone-select] option').length,
    firstIsEmpty: document.querySelector('[data-zone-select] option').value === '',
    labelFor: document.querySelector('[data-zone-picker] label').htmlFor,
    selectHeight: document.querySelector('[data-zone-select]').getBoundingClientRect().height,
  }));
  ok(view.selectShown && view.comboHidden, `${tag} the plain select is the picker`);
  ok(view.required && view.firstIsEmpty, `${tag} it is required and starts on the empty choice`);
  ok(view.options === NAMED.length + 1, `${tag} it lists every zone`);
  ok(view.labelFor === 'delivery_zone_id', `${tag} the label names the select`);
  ok(view.selectHeight >= 44, `${tag} the select is at least 44px tall`);

  await page.click('[data-fixture-submit]');
  await page.waitForTimeout(200);
  ok(page.url().includes('/page/'), `${tag} the browser will not send it without an area`);

  await page.selectOption('[data-zone-select]', '66');
  await page.click('[data-fixture-submit]');
  await page.waitForSelector('#echo');
  const sent = JSON.parse(await page.textContent('#echo'));
  ok(sent.delivery_zone_id === '66', `${tag} choosing and sending posts the id`, JSON.stringify(sent));
  await page.screenshot({ path: join(SHOTS, `nojs-${viewport.name}.png`) });

  const saved = await context.newPage();
  await saved.goto(`http://127.0.0.1:${server.address().port}/page/saved`);
  ok(await saved.$eval('[data-zone-select]', (el) => el.value) === '93', `${tag} a saved area is selected`);
  await context.close();
}

// ---- Run ----------------------------------------------------------------------------

const MANY = makeZones(57, 3);
const FEW = makeZones(2, 4);

render('named', NAMED);
render('saved', NAMED, { selected: 93 });
render('stale', NAMED, { selected: 0, stale_name: 'Old Wharf' });
render('many', MANY, { icon: true, large: true });
render('few', FEW);
render('none', []);

await new Promise((done) => server.listen(0, '127.0.0.1', done));
const executablePath = findChromium();
const browser = await chromium.launch({
  headless: true,
  ...(executablePath ? { executablePath } : {}),
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
try {
  for (const viewport of WIDTHS) {
    await withJavaScript(browser, viewport);
    await withoutJavaScript(browser, viewport);
  }
} catch (error) {
  checks++;
  console.error(`  FAIL: the zone picker journey stopped: ${error.message}`);
} finally {
  await browser.close();
  server.close();
}

console.log(`\nScreenshots: ${SHOTS}`);
console.log(`${passed} / ${checks} zone picker browser checks passed.`);
process.exit(passed === checks ? 0 : 1);
