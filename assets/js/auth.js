/**
 * assets/js/auth.js
 * OK Veggies. Progressive enhancement for the staff sign-in and password-change
 * forms. Vanilla JS, no dependencies. Every form it touches also works without
 * JavaScript: the server answers a plain post with a redirect.
 *
 * Mark up a form with data-okv-auth, give it a [data-okv-error] container, and
 * this script posts it with fetch, shows a plain error inline on failure, and
 * follows the redirect field on success.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function setError(form, message) {
    var box = form.querySelector('[data-okv-error]');
    if (box) {
      box.textContent = message;
      box.hidden = false;
    }
  }

  function clearError(form) {
    var box = form.querySelector('[data-okv-error]');
    if (box) { box.hidden = true; box.textContent = ''; }
  }

  function enhance(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearError(form);

      var button = form.querySelector('button[type="submit"], button:not([type])');
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
            window.location.assign(data.redirect || '/admin/');
            return;
          }
          setError(form, (data && data.message) || 'Something went wrong. Please try again.');
          release();
        });
      }).catch(function () {
        setError(form, 'We could not reach the server. Check your connection and try again.');
        release();
      });
    });
  }

  ready(function () {
    var forms = document.querySelectorAll('form[data-okv-auth]');
    for (var i = 0; i < forms.length; i++) { enhance(forms[i]); }
  });
})();
