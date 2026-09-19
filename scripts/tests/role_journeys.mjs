/**
 * scripts/tests/role_journeys.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. The role journeys, through a browser, on a real session.
 *
 * `visual_pass.mjs` is the layout and accessibility pass: widths, overflow,
 * touch targets, focus rings, the support sheet. This is the other half, and it
 * walks a person through four ranks rather than checking pixels:
 *
 *   guest      browse, an empty basket, checkout without an account, and a
 *              guessed order id that must not open someone else's order;
 *   household  signed in, seen as a customer, and turned away from the Pro
 *              Portal that belongs to business accounts;
 *   manager    the operational screens open, the user and role controls are
 *              refused, and the refusal carries nothing;
 *   owner      the same screens plus the Owner-only user and role controls.
 *
 * The guest pages are walked twice: once with JavaScript on and once with it
 * off, because the storefront promises to work either way.
 *
 * Needs a running site and the visual fixture:
 *
 *   php -S 127.0.0.1:8123 -t . scripts/tests/public_content_router.php
 *   php scripts/tests/seed_visual_fixture.php
 *   node scripts/tests/role_journeys.mjs
 *
 * Set OKV_BASE, OKV_OWNER, OKV_PASSWORD, OKV_OWNER_PASSWORD and
 * OKV_MANAGER_PASSWORD to override the fixture defaults.
 * -----------------------------------------------------------------------------
 */
import { createRequire } from 'node:module';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.OKV_PLAYWRIGHT_PATH || 'playwright');

const BASE = process.env.OKV_BASE || 'http://127.0.0.1:8123';
const HOUSEHOLD = process.env.OKV_HOUSEHOLD || 'm8house@example.test';
const HOUSEHOLD_PASSWORD = process.env.OKV_PASSWORD || 'm8-visual-pass-123';
const MANAGER = process.env.OKV_MANAGER || 'manager-probe@example.test';
const MANAGER_PASSWORD = process.env.OKV_MANAGER_PASSWORD || 'probe-manager-123';
const OWNER = process.env.OKV_OWNER || 'owner-probe@example.test';
const OWNER_PASSWORD = process.env.OKV_OWNER_PASSWORD || 'probe-owner-123';

const NO_ACCESS = 'You do not have access to this page';

let checks = 0;
let failures = 0;

function report(ok, label, detail = '') {
  checks++;
  if (!ok) { failures++; }
  const mark = ok ? 'ok  ' : 'FAIL';
  console.log(`  ${mark} ${label}${detail ? ' ' + detail : ''}`);
}

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

/** Sign in through the real form, so the session is a real session. */
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
  }, [BASE, { action: 'login', identifier, password, okv_csrf: csrf,
              context: path.startsWith('/admin') ? 'admin' : 'storefront' }]);
  if (status !== 200) { throw new Error(`sign in failed for ${identifier}: ${status} ${body}`); }
}

async function visit(page, path, waitUntil = 'domcontentloaded') {
  const response = await page.goto(BASE + path, { waitUntil });
  return {
    status: response ? response.status() : 0,
    url: page.url(),
    text: await page.locator('body').innerText(),
  };
}

const executablePath = findChromium();
const browser = await chromium.launch({
  args: ['--no-sandbox'],
  ...(executablePath ? { executablePath } : {}),
});

try {
  // ---- The guest, with JavaScript on ---------------------------------------
  console.log('\n=== guest ===');
  const guestContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const guest = await guestContext.newPage();
  guest.on('pageerror', (err) => report(false, `guest page raised a JavaScript error: ${err.message}`));

  for (const [path, expected, label] of [
    ['/', 'OK Veggies', 'the home page opens to a guest'],
    ['/shop.php', 'Basket', 'the shop opens to a guest'],
    ['/combos.php', 'Combos', 'the combos page opens to a guest'],
    ['/kitchen-runs.php', 'Kitchen Runs', 'the kitchen runs page opens to a guest'],
  ]) {
    const page = await visit(guest, path);
    report(page.status === 200 && page.text.includes(expected), label,
      `(${page.status})`);
  }

  const emptyBasket = await visit(guest, '/cart.php');
  report(emptyBasket.status === 200 && emptyBasket.text.includes('Your basket is empty'),
    'a guest with an empty basket sees the empty state', `(${emptyBasket.status})`);

  const guestCheckout = await visit(guest, '/checkout.php');
  report(guestCheckout.status === 200 && guestCheckout.text.includes('You do not need one'),
    'a guest reaches checkout and is offered a guest order, not a sign-in wall',
    `(${guestCheckout.status})`);

  const account = await visit(guest, '/account.php');
  report(account.status === 200 && /sign in/i.test(account.text),
    'a guest arriving at the account page is offered sign in', `(${account.status})`);

  const guessed = await visit(guest, '/public/order.php?order=999999');
  report(guessed.status === 404 && guessed.text.includes('We could not find that order'),
    'a guessed order id receives the branded not-found page', `(${guessed.status})`);
  report(!/ZZ-|OKV-\d|order number/i.test(guessed.text),
    'the not-found page embeds no order detail');

  // ---- The guest, with JavaScript off --------------------------------------
  console.log('\n=== guest, JavaScript off ===');
  const noJsContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    javaScriptEnabled: false,
  });
  const noJs = await noJsContext.newPage();

  const noJsHome = await visit(noJs, '/');
  report(noJsHome.status === 200 && noJsHome.text.includes('OK Veggies'),
    'the home page renders without JavaScript', `(${noJsHome.status})`);

  const noJsShop = await visit(noJs, '/shop.php');
  const addForms = await noJs.locator('form[action="/api/v1/cart.php"]').count();
  report(noJsShop.status === 200 && addForms > 0,
    'the shop posts to the basket without JavaScript', `(forms ${addForms})`);

  const noJsBasket = await visit(noJs, '/cart.php');
  report(noJsBasket.text.includes('Your basket is empty'),
    'the empty basket state survives JavaScript being off');

  await noJsContext.close();
  await guestContext.close();

  // ---- The household -------------------------------------------------------
  console.log('\n=== household ===');
  const houseContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const house = await houseContext.newPage();
  house.on('pageerror', (err) => report(false, `household page raised a JavaScript error: ${err.message}`));
  await signIn(house, '/account.php', HOUSEHOLD, HOUSEHOLD_PASSWORD);

  const houseAccount = await visit(house, '/account.php');
  report(houseAccount.status === 200 && houseAccount.text.includes('Tunde'),
    'the household sees its own account', `(${houseAccount.status})`);
  report(!houseAccount.text.includes('Mama Chidi Kitchen'),
    'the household account page carries no other customer\'s business');

  const blocked = await visit(house, '/pro/index.php');
  report(!blocked.url.includes('/pro/'),
    'a household is sent away from the Pro Portal', `(landed on ${blocked.url.replace(BASE, '')})`);
  report(!blocked.text.includes('Credit facility'),
    'the household redirection exposes no Pro data');
  await houseContext.close();

  // ---- The manager ---------------------------------------------------------
  console.log('\n=== manager ===');
  const managerContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const manager = await managerContext.newPage();
  manager.on('pageerror', (err) => report(false, `manager page raised a JavaScript error: ${err.message}`));
  await signIn(manager, '/admin/login.php', MANAGER, MANAGER_PASSWORD);

  const managerDashboard = await visit(manager, '/admin/index.php');
  report(managerDashboard.status === 200 && managerDashboard.text.includes("Today's orders"),
    'the manager opens the dashboard', `(${managerDashboard.status})`);

  const managerOrders = await visit(manager, '/admin/orders.php');
  report(managerOrders.status === 200 && /order/i.test(managerOrders.text),
    'the manager opens the orders screen', `(${managerOrders.status})`);

  const managerUsers = await visit(manager, '/admin/users.php');
  report(managerUsers.status === 403 && managerUsers.text.includes(NO_ACCESS),
    'the manager is refused the user and role controls', `(${managerUsers.status})`);
  report(!managerUsers.text.includes('Users and Roles') && !managerUsers.text.includes('Assign'),
    'the refusal carries no user or role data');

  const managerSettings = await visit(manager, '/admin/settings.php');
  report(managerSettings.status === 403,
    'the manager is refused the settings screen', `(${managerSettings.status})`);
  await managerContext.close();

  // ---- The owner -----------------------------------------------------------
  console.log('\n=== owner ===');
  const ownerContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const owner = await ownerContext.newPage();
  owner.on('pageerror', (err) => report(false, `owner page raised a JavaScript error: ${err.message}`));
  await signIn(owner, '/admin/login.php', OWNER, OWNER_PASSWORD);

  const ownerDashboard = await visit(owner, '/admin/index.php');
  report(ownerDashboard.status === 200 && ownerDashboard.text.includes("Today's orders"),
    'the owner opens the dashboard', `(${ownerDashboard.status})`);

  const ownerUsers = await visit(owner, '/admin/users.php');
  report(ownerUsers.status === 200 && ownerUsers.text.includes('Users and Roles'),
    'the owner reaches the user and role controls', `(${ownerUsers.status})`);
  report(ownerUsers.text.includes('Role'),
    'the owner sees the role control on that screen');

  const ownerSettings = await visit(owner, '/admin/settings.php');
  report(ownerSettings.status === 200 && /settings/i.test(ownerSettings.text),
    'the owner opens the settings screen', `(${ownerSettings.status})`);
  await ownerContext.close();
} finally {
  await browser.close();
}

console.log(`\n${checks - failures} / ${checks} role journey checks passed.`);
process.exit(failures === 0 ? 0 : 1);
