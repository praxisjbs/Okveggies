/**
 * assets/js/auth.js
 * OK Veggies. Progressive enhancement for the staff sign-in and password-change
 * forms. Vanilla JS, no dependencies. Every form it touches also works without
 * JavaScript: the server answers a plain post with a redirect.
 *
 * Mark up a form with data-okv-auth, give it a [data-okv-error] container, and
 * this script posts it with fetch, shows a plain error inline on failure, and
 * follows the redirect field on success.
 *
 * The staff password reset (admin/password_reset.php) is two steps in one page:
 * a [data-okv-forgot] form that asks for a code, and a [data-okv-reset] form
 * that sets the new password. This script moves from the first to the second
 * without a reload and carries the email across. Without JavaScript the server
 * redirects between the two steps instead.
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

  function setBusy(form, on) {
    var button = form.querySelector('button[type="submit"], button:not([type])');
    if (!button) { return; }
    button.disabled = !!on;
    if (on) { button.setAttribute('aria-busy', 'true'); } else { button.removeAttribute('aria-busy'); }
  }

  function postForm(form) {
    return fetch(form.getAttribute('action'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: new URLSearchParams(new FormData(form))
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, data: data || {} };
      });
    });
  }

  function wireReset() {
    var forgot = document.querySelector('form[data-okv-forgot]');
    var reset = document.querySelector('form[data-okv-reset]');

    if (forgot) {
      forgot.addEventListener('submit', function (event) {
        event.preventDefault();
        clearError(forgot); setBusy(forgot, true);
        var email = (forgot.querySelector('[name="email"]') || {}).value || '';
        postForm(forgot).then(function (r) {
          if (r.ok && r.data.status === 'ok') {
            var emailPanel = document.querySelector('[data-okv-panel="email"]');
            var codePanel = document.querySelector('[data-okv-panel="code"]');
            var notice = codePanel ? codePanel.querySelector('[data-okv-notice]') : null;
            var emailField = document.querySelector('[data-okv-reset-email]');
            if (emailField) { emailField.value = email; }
            if (notice) { notice.hidden = false; }
            if (emailPanel) { emailPanel.hidden = true; }
            if (codePanel) { codePanel.hidden = false; }
            var codeInput = codePanel ? codePanel.querySelector('#rp_code') : null;
            if (codeInput) { codeInput.focus(); }
            return;
          }
          setError(forgot, r.data.message || 'Something went wrong. Please try again.');
          setBusy(forgot, false);
        }).catch(function () { setError(forgot, 'We could not reach the server. Try again.'); setBusy(forgot, false); });
      });
    }

    if (reset) {
      reset.addEventListener('submit', function (event) {
        event.preventDefault();
        clearError(reset); setBusy(reset, true);
        postForm(reset).then(function (r) {
          if (r.ok && r.data.status === 'ok') { window.location.assign(r.data.redirect || '/admin/login.php?reset=1'); return; }
          setError(reset, r.data.message || 'Something went wrong. Please try again.');
          setBusy(reset, false);
        }).catch(function () { setError(reset, 'We could not reach the server. Try again.'); setBusy(reset, false); });
      });
    }
  }

  ready(function () {
    var forms = document.querySelectorAll('form[data-okv-auth]');
    for (var i = 0; i < forms.length; i++) { enhance(forms[i]); }
    wireReset();
  });
})();
