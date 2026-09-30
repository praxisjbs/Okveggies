/**
 * assets/js/basket.js
 * -----------------------------------------------------------------------------
 * OK Veggies. Progressive enhancement for the basket. Two jobs:
 *
 *   1. The mini-cart drawer. The header basket controls are real links to
 *      /cart.php; this upgrades them to open an accessible drawer that fetches
 *      the basket state on open. Escape and the backdrop close it, focus is
 *      trapped while it is open and returned to the control that opened it.
 *   2. Basket and checkout forms. Quantity changes and removals are posted to
 *      the cart API; the page is refreshed from the authoritative basket state.
 *      A removal carries a short-lived Undo action, backed by a server-side
 *      snapshot so the original quantity and price are restored exactly.
 *
 * Every value the shopper sees is written with textContent, never innerHTML, so
 * a product name can never become markup.
 * -----------------------------------------------------------------------------
 */
(function () {
  'use strict';

  var STATE_URL = '/api/v1/cart.php?action=state';
  var PENDING_UNDO_KEY = 'okv-basket-pending-undo';
  var PENDING_UNDO_TTL = 8000;

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

  function readResponse(response) {
    return response.json().then(function (data) {
      if (!response.ok) { throw new Error(data.message || 'We could not update your basket.'); }
      return data;
    });
  }

  function postCart(body) {
    return fetch('/api/v1/cart.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(readResponse);
  }

  function updateCounts(count) {
    document.querySelectorAll('.okv-basket-count').forEach(function (badge) {
      badge.textContent = String(count);
    });
  }

  function csrfPair(form) {
    var field = form ? form.querySelector('input[name="okv_csrf"]') : null;
    if (!field && drawer) { field = drawer.querySelector('[data-mini-cart-csrf] input[name="okv_csrf"]'); }
    if (!field) { field = document.querySelector('input[name="okv_csrf"]'); }
    return field ? { name: field.name, value: field.value } : { name: 'okv_csrf', value: '' };
  }

  function addHidden(form, name, value) {
    var input = el('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value == null ? '' : value);
    form.appendChild(input);
  }

  function clearPendingUndo(token) {
    try {
      var pending = JSON.parse(window.sessionStorage.getItem(PENDING_UNDO_KEY) || 'null');
      if (!token || !pending || pending.token === token) {
        window.sessionStorage.removeItem(PENDING_UNDO_KEY);
      }
    } catch (error) {
      // Storage can be unavailable in a private browsing context.
    }
  }

  function undoRemoval(token) {
    var csrf = csrfPair(null);
    var body = new FormData();
    body.set('action', 'undo_remove');
    body.set('undo_token', token);
    body.set(csrf.name, csrf.value);

    postCart(body).then(function (state) {
      clearPendingUndo(token);
      updateCounts(state.count || state.basket_count || 0);
      if (drawer && !drawer.hidden) {
        renderBody(state);
        if (window.OKV && window.OKV.toast) {
          window.OKV.toast('Item restored to your basket.', 'ok');
        }
        return;
      }
      window.location.reload();
    }).catch(function (error) {
      if (window.OKV && window.OKV.toast) {
        window.OKV.toast(error.message || 'We could not restore that item.', 'error');
      }
    });
  }

  function showUndo(token) {
    if (!token || !window.OKV || !window.OKV.toast) { return; }
    window.OKV.toast('Item removed from your basket.', 'ok', {
      label: 'Undo',
      onClick: function () { undoRemoval(token); }
    });
  }

  function rememberUndoForReload(token) {
    try {
      window.sessionStorage.setItem(PENDING_UNDO_KEY, JSON.stringify({ token: token, created_at: Date.now() }));
      return true;
    } catch (error) {
      // The redirect fallback below still exposes an ordinary Undo form.
      return false;
    }
  }

  function showPendingUndo() {
    if (!window.OKV || !window.OKV.toast) {
      // Checkout loads basket.js before the core bundle. Wait until all page
      // scripts have run before consuming the one-shot undo toast.
      window.addEventListener('load', showPendingUndo, { once: true });
      return;
    }
    // The no-JavaScript redirect renders its own Undo form. Avoid presenting a
    // duplicate toast if a customer later reloads that URL with JavaScript on.
    try {
      var url = new URL(window.location.href);
      if (url.searchParams.get('basket') === 'removed' && url.searchParams.get('undo_token')) { return; }

      var pending = JSON.parse(window.sessionStorage.getItem(PENDING_UNDO_KEY) || 'null');
      window.sessionStorage.removeItem(PENDING_UNDO_KEY);
      if (!pending || !pending.token || Date.now() - Number(pending.created_at || 0) > PENDING_UNDO_TTL) { return; }
      showUndo(pending.token);
    } catch (error) {
      // A toast is a convenience. The cart itself and the server-rendered
      // no-JavaScript Undo form remain available if browser storage is blocked.
    }
  }

  // ---- Mini-cart drawer -----------------------------------------------------

  var drawer = null;
  var lastFocus = null;

  function drawerPanel() {
    return drawer ? drawer.querySelector('[role="dialog"]') : null;
  }

  function setCheckoutEnabled(enabled) {
    if (!drawer) { return; }
    var checkout = drawer.querySelector('[data-mini-cart-checkout]');
    if (!checkout) { return; }
    if (enabled) {
      checkout.href = '/checkout.php';
      checkout.removeAttribute('aria-disabled');
      checkout.removeAttribute('tabindex');
      checkout.classList.remove('pointer-events-none');
      checkout.style.opacity = '';
      checkout.style.pointerEvents = '';
    } else {
      checkout.removeAttribute('href');
      checkout.setAttribute('aria-disabled', 'true');
      checkout.setAttribute('tabindex', '-1');
      checkout.classList.add('pointer-events-none');
      checkout.style.opacity = '0.5';
      checkout.style.pointerEvents = 'none';
    }
  }

  function makeMiniCartForm(action, line, csrf) {
    var form = el('form', 'mt-3 flex items-end gap-2');
    form.setAttribute('data-mini-cart-form', '');
    addHidden(form, 'action', action);
    addHidden(form, 'line_id', line.id);
    addHidden(form, csrf.name, csrf.value);
    form.addEventListener('submit', submitMiniCartForm);
    return form;
  }

  function submitMiniCartForm(event) {
    event.preventDefault();
    var form = event.currentTarget;
    var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"]'));
    buttons.forEach(function (button) { button.disabled = true; });

    postCart(new FormData(form)).then(function (state) {
      renderBody(state);
      updateCounts(state.count || 0);
      if (state.undo_token) { showUndo(state.undo_token); }
    }).catch(function (error) {
      if (window.OKV && window.OKV.toast) {
        window.OKV.toast(error.message || 'We could not update your basket.', 'error');
      }
      buttons.forEach(function (button) { button.disabled = false; });
    });
  }

  function renderBody(state) {
    if (!drawer) { return; }
    var body = drawer.querySelector('[data-mini-cart-body]');
    var subtotal = drawer.querySelector('[data-mini-cart-subtotal]');
    var lines = Array.isArray(state.lines) ? state.lines : [];
    body.textContent = '';
    setCheckoutEnabled(lines.length > 0);

    if (lines.length === 0) {
      var empty = el('div', 'space-y-4');
      empty.appendChild(el('p', 'text-sm text-ink-60', 'Your basket is empty.'));
      var shop = el('a', 'okv-btn-outline inline-flex min-h-[44px] items-center justify-center px-4', 'Continue shopping');
      shop.href = '/shop.php';
      empty.appendChild(shop);
      body.appendChild(empty);
      if (subtotal) { subtotal.textContent = state.subtotal_display || ''; }
      return;
    }

    var list = el('ul', 'space-y-4');
    var csrf = csrfPair(null);
    lines.forEach(function (line) {
      var row = el('li', 'border-b border-mist pb-4 text-sm last:border-0 last:pb-0');
      row.setAttribute('data-basket-line', '');
      row.setAttribute('data-line-id', String(line.id));
      var top = el('div', 'flex justify-between gap-4');
      var left = el('span', 'min-w-0');
      left.appendChild(el('strong', 'block truncate text-ink', line.name));
      left.appendChild(el('span', 'text-ink-60', line.quantity_display + ' ' + line.unit + ' at ' + line.unit_price_display));
      top.appendChild(left);
      top.appendChild(el('span', 'flex-none font-mono text-forest', line.line_total_display));
      row.appendChild(top);

      var updateForm = makeMiniCartForm(line.item_type === 'combo' ? 'update_combo' : 'update_product', line, csrf);
      var label = el('label', 'text-sm font-semibold text-ink', 'Quantity');
      var quantity = el('input', 'okv-input mt-1 w-24');
      quantity.type = 'text';
      quantity.name = 'quantity';
      quantity.inputMode = 'decimal';
      quantity.autocomplete = 'off';
      quantity.value = line.quantity_display;
      quantity.setAttribute('aria-label', 'Quantity for ' + line.name);
      label.appendChild(quantity);
      var update = el('button', 'okv-btn-outline min-h-[44px] px-3', 'Update');
      update.type = 'submit';
      updateForm.appendChild(label);
      updateForm.appendChild(update);
      row.appendChild(updateForm);

      var removeForm = makeMiniCartForm(line.item_type === 'combo' ? 'remove_combo' : 'remove_product', line, csrf);
      removeForm.classList.remove('mt-3', 'items-end', 'gap-2');
      removeForm.classList.add('mt-1');
      var remove = el('button', 'okv-btn-text min-h-[44px] px-2 text-tomato', 'Remove');
      remove.type = 'submit';
      remove.setAttribute('aria-label', 'Remove ' + line.name + ' from basket');
      removeForm.appendChild(remove);
      row.appendChild(removeForm);

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
      setCheckoutEnabled(false);
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

    setCheckoutEnabled(false);
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

  // ---- Basket and checkout forms -------------------------------------------

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
        }).then(readResponse).then(function (state) {
          updateCounts(state.count || 0);
          if (state.undo_token && rememberUndoForReload(state.undo_token)) {
            window.location.reload();
            return;
          }
          if (state.undo_token) {
            var url = new URL(window.location.href);
            url.searchParams.set('basket', 'removed');
            url.searchParams.set('undo_token', state.undo_token);
            window.location.assign(url.pathname + url.search + url.hash);
            return;
          }
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
    showPendingUndo();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
}());
