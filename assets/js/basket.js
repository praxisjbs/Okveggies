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

  function submitMiniCartForm(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; }
    fetch('/api/v1/cart.php', {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) { throw new Error(data.message || 'We could not update your basket.'); }
        return data;
      });
    }).then(function (data) {
      renderBody(data);
      updateCounts(data.count || 0);
    }).catch(function (error) {
      if (window.OKV && window.OKV.toast) {
        window.OKV.toast(error.message || 'We could not update your basket.', 'error');
      }
      if (button) { button.disabled = false; }
    });
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

    var list = el('ul', 'space-y-4');
    state.lines.forEach(function (line) {
      var row = el('li', 'border-b border-mist pb-4 text-sm last:border-0 last:pb-0');
      var top = el('div', 'flex justify-between gap-4');
      var left = el('span', 'min-w-0');
      left.appendChild(el('strong', 'block truncate text-ink', line.name));
      left.appendChild(el('span', 'text-ink-60', line.quantity_display + ' ' + line.unit + ' at ' + line.unit_price_display));
      top.appendChild(left);
      top.appendChild(el('span', 'flex-none font-mono text-forest', line.line_total_display));
      row.appendChild(top);

      var form = el('form', 'mt-3 flex items-end gap-2');
      form.setAttribute('data-mini-cart-form', '');
      var action = el('input');
      action.type = 'hidden'; action.name = 'action';
      action.value = line.item_type === 'combo' ? 'update_combo' : 'update_product';
      var lineId = el('input');
      lineId.type = 'hidden'; lineId.name = 'line_id'; lineId.value = String(line.id);
      var csrfSource = drawer.querySelector('[data-mini-cart-csrf] input');
      var csrf = el('input');
      csrf.type = 'hidden'; csrf.name = csrfSource ? csrfSource.name : '_csrf';
      csrf.value = csrfSource ? csrfSource.value : '';
      var label = el('label', 'text-sm font-semibold text-ink', 'Quantity');
      var quantity = el('input', 'okv-input mt-1 w-24');
      quantity.type = 'number'; quantity.name = 'quantity'; quantity.inputMode = 'decimal';
      quantity.value = line.quantity_display;
      quantity.min = line.item_type === 'combo' ? '1' : line.minimum_quantity;
      quantity.step = line.item_type === 'combo' ? '1' : line.quantity_increment;
      quantity.setAttribute('aria-label', 'Quantity for ' + line.name);
      label.appendChild(quantity);
      var update = el('button', 'okv-btn-outline px-3', 'Update');
      update.type = 'submit';
      form.appendChild(action); form.appendChild(lineId); form.appendChild(csrf);
      form.appendChild(label); form.appendChild(update);
      form.addEventListener('submit', submitMiniCartForm);
      row.appendChild(form);
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
