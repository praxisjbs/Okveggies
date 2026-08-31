/**
 * assets/js/basket.js
 * OK Veggies. The mini-cart and the basket page.
 *
 * Two jobs. First, open and close the mini-cart, which slides in from the
 * right on a laptop and up from the bottom on a phone. Second, keep the
 * basket in step after an add, a quantity change or a removal, without a page
 * reload, from the state the cart API returns.
 *
 * Everything degrades: the Basket control in the header is a real link to
 * /cart.php, and every control on the basket page is a real form. This file
 * only makes them quicker.
 */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var lastFocused = null;

  function drawer() { return document.querySelector('[data-mini-cart]'); }

  function focusable(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0;
    });
  }

  /** Keep Tab inside the drawer while it is open. It claims aria-modal, so it
      has to behave like one for a keyboard and a screen reader. */
  function trapTab(panel, event) {
    if (event.key !== 'Tab') { return; }
    var items = focusable(panel);
    if (items.length === 0) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    } else if (!panel.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    }
  }

  function setDrawer(open) {
    var panel = drawer();
    if (!panel) { return; }
    panel.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
    document.querySelectorAll('[data-mini-cart-open]').forEach(function (opener) {
      opener.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (open) {
      lastFocused = document.activeElement;
      var close = panel.querySelector('[data-mini-cart-close]');
      if (close) { close.focus(); }
    } else if (lastFocused && lastFocused.focus) {
      lastFocused.focus();
    }
  }

  function element(tag, className, text) {
    var el = document.createElement(tag);
    if (className) { el.className = className; }
    if (text !== undefined && text !== null) { el.textContent = String(text); }
    return el;
  }

  function moneyOf(line) {
    return line.line_total_display || (window.OKV && OKV.money ? OKV.money(line.line_total_subunit) : '');
  }

  /** One line inside the mini-cart. Built as nodes, never as an HTML string. */
  function miniCartLine(line) {
    var item = element('li', 'flex gap-3 py-3');

    var thumb = element('div', 'h-12 w-12 flex-none overflow-hidden rounded-md bg-forest-tint');
    if (line.image) {
      var img = document.createElement('img');
      img.src = line.image_url || line.image;
      img.alt = line.name + (line.unit ? ', per ' + line.unit : '');
      img.className = 'h-full w-full object-cover';
      img.loading = 'lazy';
      thumb.appendChild(img);
    }
    item.appendChild(thumb);

    var body = element('div', 'min-w-0 flex-1');
    var name = element('p', 'truncate text-sm font-semibold text-ink');
    var link = element('a', 'hover:text-forest', line.name);
    link.href = line.url;
    name.appendChild(link);
    body.appendChild(name);

    var detail = line.quantity_display + (line.unit || '') + ' at ' + line.unit_price_display + (line.unit ? ' per ' + line.unit : '');
    body.appendChild(element('p', 'mt-0.5 text-xs text-ink-60', detail));

    if (line.price_changed) {
      body.appendChild(element('p', 'mt-1 text-xs font-semibold text-gold-ink', 'Price changed since your first add'));
    }
    item.appendChild(body);

    item.appendChild(element('p', 'font-mono text-sm font-semibold text-ink', moneyOf(line)));
    return item;
  }

  function renderMiniCart(state) {
    var body = document.querySelector('[data-mini-cart-body]');
    if (!body) { return; }
    body.textContent = '';

    if (!state.line_count) {
      body.appendChild(element('p', 'font-display text-base font-bold text-ink', 'Nothing in here yet'));
      body.appendChild(element('p', 'mt-2 text-sm text-ink-60', 'Add what your kitchen needs this week and it will show up here.'));
      var shop = element('a', 'okv-btn mt-4 w-full', 'Shop the produce');
      shop.href = '/shop.php';
      body.appendChild(shop);
      var combos = element('a', 'okv-btn-outline mt-2 w-full', "See this week's combos");
      combos.href = '/combos.php';
      body.appendChild(combos);
      return;
    }

    if (state.has_repriced && state.repriced_notice) {
      var notice = element('p', 'mb-3 rounded-md border border-gold bg-gold-tint px-3 py-2 text-xs text-gold-ink', state.repriced_notice);
      notice.setAttribute('role', 'status');
      body.appendChild(notice);
    }

    body.appendChild(element('p', 'mb-3 text-sm text-ink-60', state.line_count + (state.line_count === 1 ? ' line in your basket' : ' lines in your basket')));

    var list = element('ul', 'divide-y divide-mist');
    state.lines.forEach(function (line) { list.appendChild(miniCartLine(line)); });
    body.appendChild(list);
  }

  /** Badge, screen-reader label, mini-cart and, on the basket page, the totals. */
  function render(state) {
    if (!state) { return; }

    var label = 'Basket, ' + state.line_count + (state.line_count === 1 ? ' line, ' : ' lines, ') + state.subtotal_display;
    document.querySelectorAll('.okv-basket-count').forEach(function (badge) {
      badge.textContent = state.line_count;
    });
    document.querySelectorAll('[data-mini-cart-open], a[href="/cart.php"]').forEach(function (link) {
      link.setAttribute('aria-label', label);
    });
    var live = document.querySelector('[data-basket-live]');
    if (live) { live.textContent = label; }

    var subtotal = document.querySelector('[data-mini-cart-subtotal]');
    if (subtotal) { subtotal.textContent = state.subtotal_display; }

    renderMiniCart(state);

    var summarySubtotal = document.querySelector('[data-summary-subtotal]');
    if (summarySubtotal) { summarySubtotal.textContent = state.subtotal_display; }
    var summaryLines = document.querySelector('[data-summary-lines]');
    if (summaryLines) { summaryLines.textContent = state.line_count; }
    var lineCount = document.querySelector('[data-basket-line-count]');
    if (lineCount) { lineCount.textContent = state.line_count + (state.line_count === 1 ? ' line' : ' lines'); }

    state.lines.forEach(function (line) {
      var row = document.querySelector('[data-basket-line="' + line.id + '"]');
      if (!row) { return; }
      var total = row.querySelector('[data-line-total]');
      if (total) { total.textContent = moneyOf(line); }
      var input = row.querySelector('[data-quantity-input]');
      if (input && document.activeElement !== input) { input.value = line.quantity_display; }
    });
  }

  /** Post a basket form and hand the answer back to the page. */
  function postForm(form, button) {
    var original = button ? button.textContent : '';
    if (button) {
      button.disabled = true;
      button.textContent = 'Saving';
    }
    return fetch(form.getAttribute('action'), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || data.status === 'error') {
          throw new Error(data.message || 'We could not update your basket. Please try again.');
        }
        return data;
      });
    }).finally(function () {
      if (button) {
        button.disabled = false;
        button.textContent = original;
      }
    });
  }

  function announce(data) {
    if (window.OKV && OKV.toast && data.message) { OKV.toast(data.message, 'ok'); }
    if (data.basket) {
      render(data.basket);
      document.dispatchEvent(new CustomEvent('okv:basket-rendered', { detail: data.basket }));
    }
  }

  function failed(error) {
    if (window.OKV && OKV.toast) { OKV.toast(error.message, 'error'); }
  }

  function enhanceLineForm(form) {
    var row = form.closest('[data-basket-line]');
    var input = form.querySelector('[data-quantity-input]');

    form.querySelectorAll('[data-quantity-step]').forEach(function (stepper) {
      stepper.addEventListener('click', function () {
        if (!input) { return; }
        var step = parseFloat(input.getAttribute('step')) || 1;
        var min = parseFloat(input.getAttribute('min')) || step;
        var current = parseFloat(input.value);
        if (isNaN(current)) { current = min; }
        var next = current + (step * parseFloat(stepper.getAttribute('data-quantity-step')));
        if (next < min) { next = min; }
        input.value = String(Math.round(next * 1000) / 1000);
        form.requestSubmit ? form.requestSubmit() : form.submit();
      });
    });

    form.addEventListener('submit', function (event) {
      if (!window.fetch) { return; }
      event.preventDefault();
      postForm(form, form.querySelector('[data-line-update]')).then(function (data) {
        if (data.removed && row) {
          row.remove();
          if (data.basket && data.basket.line_count === 0) { window.location.reload(); return; }
        }
        announce(data);
      }).catch(failed);
    });
  }

  function enhanceRemoveForm(form) {
    var row = form.closest('[data-basket-line]');
    form.addEventListener('submit', function (event) {
      if (!window.fetch) { return; }
      event.preventDefault();
      postForm(form, form.querySelector('[data-line-remove]')).then(function (data) {
        if (row) { row.remove(); }
        if (data.basket && data.basket.line_count === 0) { window.location.reload(); return; }
        announce(data);
      }).catch(failed);
    });
  }

  function ready() {
    var panel = drawer();

    document.querySelectorAll('[data-mini-cart-open]').forEach(function (opener) {
      opener.addEventListener('click', function (event) {
        if (!panel || !window.fetch) { return; }
        event.preventDefault();
        setDrawer(true);
      });
    });

    if (panel) {
      var close = panel.querySelector('[data-mini-cart-close]');
      if (close) { close.addEventListener('click', function () { setDrawer(false); }); }
      panel.addEventListener('click', function (event) {
        if (event.target === panel) { setDrawer(false); }
      });
      document.addEventListener('keydown', function (event) {
        if (panel.hidden) { return; }
        if (event.key === 'Escape') { setDrawer(false); return; }
        trapTab(panel, event);
      });
    }

    document.querySelectorAll('[data-line-form]').forEach(enhanceLineForm);
    document.querySelectorAll('[data-line-remove-form]').forEach(enhanceRemoveForm);

    // An add from a product card, a product page or a combo page tells us what
    // the basket looks like now, so the mini-cart never drifts out of step.
    document.addEventListener('okv:basket-changed', function (event) {
      if (event.detail) { render(event.detail); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
