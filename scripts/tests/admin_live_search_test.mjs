/**
 * scripts/tests/admin_live_search_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. Unit test for assets/js/admin-live-search.js, the auto-fill
 * search bar on Payments, Customers and Kitchen Runs.
 *
 * Runs the real module inside a jsdom document against a stubbed fetch and
 * checks the behaviour the screens rely on: it asks the server from the first
 * character, debounced at 300ms; a second character cancels the request the
 * first one started; the answer swaps the results region and the summary and
 * opens the suggestion listbox; and committing (a suggestion, here) fills the
 * box and submits the GET form, which is what opens a record on one match.
 *
 * Needs jsdom (a devDependency, so `npm install` first). Without it the suite
 * reports a skip and exits zero, the way the visual suites report a missing
 * Chromium: on a laptop that is information, not a defect.
 *
 *   npm install
 *   node scripts/tests/admin_live_search_test.mjs
 * -----------------------------------------------------------------------------
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch {
  console.log('[skip] admin live search: jsdom not installed (run npm install)');
  process.exit(0);
}

const here = path.dirname(fileURLToPath(import.meta.url));
const source = readFileSync(path.join(here, '..', '..', 'assets', 'js', 'admin-live-search.js'), 'utf8');

let tests = 0;
let failed = 0;
function ok(cond, label) {
  tests++;
  if (cond) { console.log('  ok   ' + label); }
  else { failed++; console.log('  FAIL ' + label); }
}
function sleep(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }

function makeWorld() {
  const dom = new JSDOM(`<!doctype html><html><body>
    <form method="GET" data-live-form data-live-endpoint="/api/v1/payments.php"
          data-live-param="q" data-live-page="/admin/payments.php"
          data-live-keep="order_id" data-live-min="1">
      <label><input name="q" data-live-input></label>
      <button type="submit">Search</button>
    </form>
    <div data-live-results><p>initial</p></div>
    <span data-live-summary>0 total</span>
    <p data-live-status></p>
  </body></html>`, { url: 'https://okveggies.test/admin/payments.php', pretendToBeVisual: true, runScripts: 'outside-only' });

  const calls = [];
  let release = null;
  dom.window.fetch = (url, options) => {
    const call = { url: String(url), signal: options && options.signal ? options.signal : null };
    calls.push(call);
    // The first request stays in flight until the test releases it, so a
    // cancelled request is observable.
    return new Promise((resolve) => {
      call.resolve = () => resolve({
        ok: true,
        json: () => Promise.resolve({
          status: 'ok',
          html: '<table><tbody><tr><td>rows</td></tr></tbody></table>',
          summary: '2 orders match',
          suggestions: [{ value: 'OKV260013', label: 'Love Adeola', sub: 'OKV260013 . 29 Sep 2026' }],
        }),
      });
      if (release) { call.resolve(); }
    });
  };
  dom.window.addEventListener('error', () => {});
  dom.window.eval(source);

  const submitted = [];
  dom.window.document.querySelector('form').addEventListener('submit', (event) => {
    event.preventDefault();
    submitted.push(dom.window.document.querySelector('[data-live-input]').value);
  });

  return { dom, calls, submitted, releaseNow: () => { release = true; calls.forEach((c) => c.resolve()); } };
}

function type(window, input, value) {
  input.value = value;
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
}

// --- 1. One character is enough, debounced at 300ms. -------------------------
{
  const { dom, calls, releaseNow } = makeWorld();
  await sleep(30); // jsdom fires DOMContentLoaded a tick after construction
  const input = dom.window.document.querySelector('[data-live-input]');
  type(dom.window, input, 'a');
  await sleep(120);
  ok(calls.length === 0, 'nothing is asked before the 300ms of quiet');
  await sleep(320);
  ok(calls.length === 1, 'one character asks the server once the debounce settles');
  ok(calls[0].url.includes('action=browse') && calls[0].url.includes('q=a'), 'the request is the browse action carrying the term');
  releaseNow();
  await sleep(30);
  const results = dom.window.document.querySelector('[data-live-results]');
  ok(results.innerHTML.includes('rows'), 'the server markup replaces the results region');
  ok(dom.window.document.querySelector('[data-live-summary]').textContent === '2 orders match', 'the summary keeps step with the results');
  ok(dom.window.document.querySelector('[data-live-status]').textContent === '2 orders match', 'the hidden status line announces the count');
  ok(dom.window.location.search.includes('q=a'), 'the address bar keeps up while typing');
  const listbox = dom.window.document.getElementById(input.id ? input.id + '-suggestions' : 'okv-live-search-suggestions')
    || dom.window.document.querySelector('[role="listbox"]');
  ok(!!listbox && listbox.hidden === false, 'the suggestion listbox opens with the answer');
  ok(listbox.querySelectorAll('[role="option"]').length === 1, 'one suggestion for one match');
  ok(input.getAttribute('role') === 'combobox' && input.getAttribute('aria-expanded') === 'true', 'the box is a combobox and says it is open');
  dom.window.close();
}

// --- 2. The second character cancels the first request. --------------------
{
  const { dom, calls, releaseNow } = makeWorld();
  await sleep(30);
  const input = dom.window.document.querySelector('[data-live-input]');
  type(dom.window, input, 'a');
  await sleep(320);
  ok(calls.length === 1 && calls[0].signal && calls[0].signal.aborted === false, 'the first character has a request in flight');
  type(dom.window, input, 'ab');
  await sleep(320);
  ok(calls.length === 2 && calls[1].url.includes('q=ab'), 'the two-character term is what reaches the server');
  ok(calls[0].signal.aborted === true, 'the request the first character started is cancelled');
  releaseNow();
  await sleep(30);
  ok(dom.window.document.querySelector('[data-live-results]').innerHTML.includes('rows'), 'the newer answer wins the region');
  dom.window.close();
}

// --- 3. Picking a suggestion fills the box and submits the GET form. -------
{
  const { dom, calls, submitted, releaseNow } = makeWorld();
  await sleep(30);
  const input = dom.window.document.querySelector('[data-live-input]');
  type(dom.window, input, 'l');
  await sleep(320);
  releaseNow();
  await sleep(30);
  const option = dom.window.document.querySelector('[role="option"]');
  ok(!!option, 'a suggestion is on offer');
  option.dispatchEvent(new dom.window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
  ok(submitted.length === 1 && submitted[0] === 'OKV260013', 'choosing it fills the box with the order number and submits');
  ok(input.value === 'OKV260013', 'the box holds what was chosen');
  ok(dom.window.document.querySelector('[role="listbox"]').hidden === true, 'the listbox closes once chosen');
  dom.window.close();
}

// --- 4. Clearing the box asks for the unfiltered list again. ---------------
{
  const { dom, calls, releaseNow } = makeWorld();
  await sleep(30);
  releaseNow();
  const input = dom.window.document.querySelector('[data-live-input]');
  type(dom.window, input, 'a');
  await sleep(320);
  type(dom.window, input, '');
  await sleep(320);
  ok(calls.length === 2 && !calls[1].url.includes('q='), 'an empty box reloads the unfiltered list');
  dom.window.close();
}

console.log(failed === 0
  ? `[admin-live-search] ${tests} checks, all green.`
  : `[admin-live-search] ${failed} of ${tests} checks FAILED.`);
process.exit(failed === 0 ? 0 : 1);
