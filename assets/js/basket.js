/**
 * assets/js/basket.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the basket. Two jobs:
 *
 *   1. The mini-cart drawer. The header basket controls are real links to
 *      /cart.php; this upgrades them to open an accessible drawer that fetches
 *      the basket state on open. Escape and the backdrop close it, focus is
 *      trapped while it is open and returned to the control that opened it.
 *   2. The basket page forms. A quantity update or a remove is sent with fetch
 *      and the page is refreshed from the server, so the plain form post stays
 *      the fallback with JavaScript off.
 *
 * Every value the shopper sees is written with textContent, never innerHTML, so
 * a product name can never become markup.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var STATE_URL = '/api/v1/cart.php?action=state';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  function fetchState() {
    return fetch(STATE_URL, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) { throw new Error('state'); }
      return response.json();
    });
  }

  function updateCounts(count) {
    document.querySelectorAll('.okv-basket-count').forEach(function (badge) {
      badge.textContent = String(count);
    });
  }

  // ---- Mini-cart drawer -----------------------------------------------------

  var drawer = null;
  var lastFocus = null;

  function drawerPanel() {
    return drawer ? drawer.querySelector('[role="dialog"]') : null;
  }

  function renderBody(state) {
    var body = drawer.querySelector('[data-mini-cart-body]');
    var subtotal = drawer.querySelector('[data-mini-cart-subtotal]');
    body.textContent = '';

    if (!state.lines || state.lines.length === 0) {
      body.appendChild(el('p', 'text-sm text-ink-60', 'Your basket is empty.'));
      if (subtotal) { subtotal.textContent = state.subtotal_display || ''; }
      return;
    }

    var list = el('ul', 'space-y-3');
    state.lines.forEach(function (line) {
      var row = el('li', 'flex justify-between gap-4 text-sm');
      var left = el('span');
      left.appendChild(el('strong', 'block text-ink', line.name));
      left.appendChild(el('span', 'text-ink-60', line.quantity_display + ' ' + line.unit));
      row.appendChild(left);
      row.appendChild(el('span', 'font-mono text-forest', line.line_total_display));
      list.appendChild(row);
    });
    body.appendChild(list);
    if (subtotal) { subtotal.textContent = state.subtotal_display || ''; }
  }

  function focusables() {
    var panel = drawerPanel();
    if (!panel) { return []; }
    return Array.prototype.slice.call(
      panel.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])')
    ).filter(function (node) { return node.offsetParent !== null; });
  }

  function onKeydown(event) {
    if (event.key === 'Escape') { closeDrawer(); return; }
    if (event.key !== 'Tab') { return; }
    var items = focusables();
    if (items.length === 0) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function openDrawer() {
    if (!drawer) { return; }
    lastFocus = document.activeElement;
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeydown);
    var closeButton = drawer.querySelector('[data-mini-cart-close]');
    if (closeButton) { closeButton.focus(); }

    fetchState().then(function (state) {
      renderBody(state);
      updateCounts(state.count || 0);
    }).catch(function () {
      var body = drawer.querySelector('[data-mini-cart-body]');
      body.textContent = '';
      body.appendChild(el('p', 'text-sm text-tomato', 'We could not load your basket. Open the full basket instead.'));
    });
  }

  function closeDrawer() {
    if (!drawer) { return; }
    drawer.hidden = true;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeydown);
    if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
  }

  function wireDrawer() {
    drawer = document.getElementById('okv-mini-cart');
    if (!drawer) { return; }

    document.querySelectorAll('[data-basket-open]').forEach(function (trigger) {
      trigger.addEventListener('click', function (event) {
        if (!window.fetch) { return; }
        event.preventDefault();
        openDrawer();
      });
    });
    drawer.querySelectorAll('[data-mini-cart-close]').forEach(function (control) {
      control.addEventListener('click', function (event) {
        event.preventDefault();
        closeDrawer();
      });
    });
  }

  // ---- Basket page forms ----------------------------------------------------

  function wireForms() {
    document.querySelectorAll('[data-basket-form]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.fetch) { return; }
        event.preventDefault();
        fetch(form.getAttribute('action'), {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
        }).then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) { throw new Error(data.message || 'We could not update your basket.'); }
            return data;
          });
        }).then(function () {
          window.location.reload();
        }).catch(function (error) {
          if (window.OKV && window.OKV.toast) {
            window.OKV.toast(error.message || 'We could not update your basket.', 'error');
          } else {
            form.submit();
          }
        });
      });
    });
  }

  function ready() {
    wireDrawer();
    wireForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
}());
