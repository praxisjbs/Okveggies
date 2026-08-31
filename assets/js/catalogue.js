/** Progressive enhancements for catalogue filters and add controls. */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusable(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0;
    });
  }

  /** Keep Tab inside the sheet while it is open. It claims aria-modal, so it
      has to behave like one for a keyboard and a screen reader. */
  function trapTab(sheet, event) {
    if (event.key !== 'Tab') { return; }
    var items = focusable(sheet);
    if (items.length === 0) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    } else if (!sheet.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    }
  }

  function setSheet(open) {
    var sheet = document.querySelector('[data-filter-sheet]');
    var opener = document.querySelector('[data-filter-open]');
    if (!sheet) { return; }
    sheet.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
    if (opener) { opener.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (open) {
      var close = sheet.querySelector('[data-filter-close]');
      if (close) { close.focus(); }
    } else if (opener) {
      opener.focus();
    }
  }

  function enhanceAddForm(form) {
    form.addEventListener('submit', function (event) {
      if (!window.fetch) { return; }
      event.preventDefault();
      var button = form.querySelector('[data-add-button]');
      if (!button || button.disabled) { return; }
      var original = button.textContent;
      button.disabled = true;
      button.textContent = 'Adding';

      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
      }).then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (data) {
          if (!response.ok || data.status === 'error') {
            throw new Error(data.message || 'We could not add that item. Please try again.');
          }
          // basket.js owns the badge, the mini-cart and the screen-reader
          // label once it has the full basket. Without it (a page that does
          // not carry the mini-cart) the count is still updated here.
          if (data.basket) {
            document.dispatchEvent(new CustomEvent('okv:basket-changed', { detail: data.basket }));
          } else {
            document.querySelectorAll('.okv-basket-count').forEach(function (count) {
              count.textContent = data.basket_count;
            });
          }
          button.textContent = 'Added';
          button.classList.add('animate-okv-pop');
          if (window.OKV && OKV.toast) { OKV.toast(data.message, 'ok'); }
          window.setTimeout(function () {
            button.textContent = original;
            button.disabled = false;
            button.classList.remove('animate-okv-pop');
          }, 1200);
        });
      }).catch(function (error) {
        button.textContent = original;
        button.disabled = false;
        if (window.OKV && OKV.toast) { OKV.toast(error.message, 'error'); }
      });
    });
  }

  function ready() {
    var opener = document.querySelector('[data-filter-open]');
    var sheet = document.querySelector('[data-filter-sheet]');
    if (opener) { opener.addEventListener('click', function () { setSheet(true); }); }
    if (sheet) {
      var close = sheet.querySelector('[data-filter-close]');
      if (close) { close.addEventListener('click', function () { setSheet(false); }); }
      sheet.addEventListener('click', function (event) {
        if (event.target === sheet) { setSheet(false); }
      });
      document.addEventListener('keydown', function (event) {
        if (sheet.hidden) { return; }
        if (event.key === 'Escape') { setSheet(false); return; }
        trapTab(sheet, event);
      });
    }
    document.querySelectorAll('[data-add-form]').forEach(enhanceAddForm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
