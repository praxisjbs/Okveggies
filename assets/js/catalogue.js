/** Progressive enhancements for catalogue filters and add controls. */
(function () {
  'use strict';

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
          document.querySelectorAll('.okv-basket-count').forEach(function (count) {
            count.textContent = data.basket_count;
          });
          document.querySelectorAll('a[href="/cart.php"]').forEach(function (link) {
            link.setAttribute('aria-label', 'Basket, ' + data.basket_count + ' items');
          });
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
        if (event.key === 'Escape' && !sheet.hidden) { setSheet(false); }
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
