/**
 * assets/js/admin-customers.js
 * OK Veggies. The Customers screen's one write: correcting a customer's own
 * name, email or phone. The form is marked data-okv-json, posts to
 * api/v1/customers.php by fetch, shows a plain error on failure, and reloads
 * the page on success so the detail panel always reflects the server. The
 * server re-checks the customers.edit permission and CSRF token regardless.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function submit(form) {
    var errBox = form.querySelector('[data-okv-error]');
    var button = form.querySelector('button[type="submit"], button:not([type])');
    if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }

    function release() {
      if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
    }

    fetch(form.getAttribute('action'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams(new FormData(form))
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (res.ok && data && data.status === 'ok') {
          toast(data.message || 'Saved.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        var msg = (data && data.message) || 'Something went wrong. Please try again.';
        if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
        else { toast(msg, 'error'); }
        release();
      });
    }).catch(function () {
      var msg = 'We could not reach the server. Check your connection and try again.';
      if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
      else { toast(msg, 'error'); }
      release();
    });
  }

  ready(function () {
    var forms = document.querySelectorAll('form[data-okv-json]');
    for (var i = 0; i < forms.length; i++) {
      forms[i].addEventListener('submit', function (event) {
        event.preventDefault();
        submit(event.currentTarget);
      });
    }
  });
})();
