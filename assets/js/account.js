/**
 * assets/js/account.js
 * OK Veggies. The storefront account area: sign in, create account, activate,
 * reset a password, and manage delivery addresses. Vanilla JS, no dependencies,
 * built on the helpers in okv.js. Every form here also works without JavaScript
 * (the server answers a plain post with a redirect); this layer adds the
 * native-app feel: no full reloads, slide-up sheets, a resend cooldown, and
 * clear inline messages.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  var csrf = (window.OKV && window.OKV.csrf) || '';

  /** Post a form as x-www-form-urlencoded so PHP populates $_POST. */
  function post(url, body) {
    var headers = {
      'X-Requested-With': 'fetch',
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded'
    };
    if (csrf) { headers['X-CSRF-Token'] = csrf; }
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: body })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          return { ok: res.ok, status: res.status, data: data || {} };
        });
      });
  }

  function formBody(form) {
    return new URLSearchParams(new FormData(form)).toString();
  }

  function setError(form, message) {
    var box = form.querySelector('[data-okv-error]');
    if (box) { box.textContent = message; box.hidden = false; }
  }
  function clearError(form) {
    var box = form.querySelector('[data-okv-error]');
    if (box) { box.hidden = true; box.textContent = ''; }
  }
  function busy(form, on) {
    var btn = form.querySelector('button[type="submit"], button:not([type])');
    if (!btn) { return; }
    btn.disabled = on;
    if (on) { btn.setAttribute('aria-busy', 'true'); } else { btn.removeAttribute('aria-busy'); }
  }

  /* -------------------------------------------------- sign in and register -- */

  function handleSignin(form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearError(form); busy(form, true);
      post(form.getAttribute('action'), formBody(form)).then(function (r) {
        if (r.ok && r.data.status === 'ok') { window.location.assign(r.data.redirect || '/'); return; }
        setError(form, r.data.message || 'Something went wrong. Please try again.');
        busy(form, false);
      }).catch(function () {
        setError(form, 'We could not reach the server. Check your connection and try again.');
        busy(form, false);
      });
    });
  }

  function handleRegister(form) {
    var modal = document.getElementById('okv-exists-modal');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearError(form); busy(form, true);
      post(form.getAttribute('action'), formBody(form)).then(function (r) {
        if (r.ok && r.data.status === 'ok') {
          // Land on activation if the code could not be emailed, so the person
          // sees a clear resend, never a silent success.
          if (r.data.activation_email_sent === false) {
            window.location.assign('/public/auth/activate.php?error=mail_failed');
          } else {
            window.location.assign(r.data.redirect || '/account.php');
          }
          return;
        }
        if (r.status === 409 && r.data.code === 'account_exists' && modal) {
          var link = modal.querySelector('[data-okv-exists-signin]');
          if (link) { link.setAttribute('href', '/account.php?mode=signin&id=' + encodeURIComponent(r.data.prefill || '')); }
          openSheet(modal);
          busy(form, false);
          return;
        }
        setError(form, r.data.message || 'Something went wrong. Please try again.');
        busy(form, false);
      }).catch(function () {
        setError(form, 'We could not reach the server. Check your connection and try again.');
        busy(form, false);
      });
    });
  }

  /* ------------------------------------------------------------- tabs ------- */

  function wireTabs() {
    var tabs = document.querySelectorAll('[data-okv-tab]');
    if (!tabs.length) { return; }
    function show(mode) {
      var panels = document.querySelectorAll('[data-okv-panel]');
      for (var i = 0; i < panels.length; i++) {
        var name = panels[i].getAttribute('data-okv-panel');
        if (name === 'signin' || name === 'register') { panels[i].hidden = (name !== mode); }
      }
      for (var j = 0; j < tabs.length; j++) {
        var active = tabs[j].getAttribute('data-okv-tab') === mode;
        tabs[j].setAttribute('aria-selected', active ? 'true' : 'false');
        tabs[j].classList.toggle('bg-white', active);
        tabs[j].classList.toggle('text-forest', active);
        tabs[j].classList.toggle('shadow-okv-1', active);
        tabs[j].classList.toggle('text-ink-60', !active);
      }
      try { history.replaceState(null, '', '?mode=' + mode); } catch (err) {}
    }
    for (var k = 0; k < tabs.length; k++) {
      tabs[k].addEventListener('click', function (e) {
        e.preventDefault();
        show(this.getAttribute('data-okv-tab'));
      });
    }
  }

  /* ----------------------------------------------- business fields toggle --- */

  function wireBusinessToggle() {
    var radios = document.querySelectorAll('[data-okv-acctype]');
    var box = document.querySelector('[data-okv-business]');
    if (!radios.length || !box) { return; }
    var nameInput = box.querySelector('#rg_bizname');
    function sync() {
      var checked = document.querySelector('[data-okv-acctype]:checked');
      var isBiz = checked && checked.value === 'business';
      box.hidden = !isBiz;
      if (nameInput) { if (isBiz) { nameInput.setAttribute('required', 'required'); } else { nameInput.removeAttribute('required'); } }
    }
    for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', sync); }
    sync();
  }

  /* ------------------------------------------------------------ sheets ------ */

  var lastFocus = null;
  function openSheet(el) {
    if (!el) { return; }
    lastFocus = document.activeElement;
    el.hidden = false;
    var focusable = el.querySelector('input, select, textarea, button, a[href]');
    if (focusable) { focusable.focus(); }
  }
  function closeSheet(el) {
    if (!el) { return; }
    el.hidden = true;
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }
  function wireSheets() {
    // Open by id.
    document.querySelectorAll('[data-okv-open]').forEach(function (btn) {
      btn.addEventListener('click', function () { openSheet(document.getElementById(btn.getAttribute('data-okv-open'))); });
    });
    // Close buttons and backdrop clicks.
    document.querySelectorAll('.okv-sheet-backdrop').forEach(function (back) {
      back.addEventListener('click', function (e) { if (e.target === back) { closeSheet(back); } });
      back.querySelectorAll('[data-okv-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { closeSheet(back); });
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') { return; }
      document.querySelectorAll('.okv-sheet-backdrop').forEach(function (back) { if (!back.hidden) { closeSheet(back); } });
    });
  }

  /* ------------------------------------------------- generic ajax forms ----- */
  // Profile edit and any simple form marked data-okv-ajax. On success it closes
  // the sheet, and reloads (profile) so the new details show everywhere.

  function wireAjaxForms() {
    document.querySelectorAll('form[data-okv-ajax]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(form); busy(form, true);
        post(form.getAttribute('action'), formBody(form)).then(function (r) {
          if (r.ok && r.data.status === 'ok') {
            var back = form.closest('.okv-sheet-backdrop');
            if (back) { closeSheet(back); }
            OKV.toast(r.data.message || 'Saved.', 'ok');
            if (form.hasAttribute('data-okv-reload')) { setTimeout(function () { window.location.reload(); }, 400); }
            return;
          }
          setError(form, r.data.message || 'Something went wrong. Please try again.');
          busy(form, false);
        }).catch(function () {
          setError(form, 'We could not reach the server. Check your connection and try again.');
          busy(form, false);
        });
      });
    });
  }

  /* ------------------------------------------------------- addresses -------- */

  function wireAddresses() {
    var list = document.getElementById('okv-address-list');
    var sheet = document.getElementById('address-sheet');
    if (!list || !sheet) { return; }
    var form = document.getElementById('okv-address-form');
    var title = document.getElementById('address-sheet-h');

    function openAdd() {
      form.reset();
      form.querySelector('[name="action"]').value = 'add_address';
      form.querySelector('[name="address_id"]').value = '';
      if (title) { title.textContent = 'Add a delivery address'; }
      clearError(form); busy(form, false);
      openSheet(sheet);
    }
    function openEdit(a) {
      form.reset();
      form.querySelector('[name="action"]').value = 'update_address';
      form.querySelector('[name="address_id"]').value = a.id;
      form.querySelector('[name="recipient_name"]').value = a.recipient_name || '';
      form.querySelector('[name="recipient_phone"]').value = a.recipient_phone_display || a.recipient_phone || '';
      form.querySelector('[name="address_line_1"]').value = a.address_line_1 || '';
      form.querySelector('[name="address_line_2"]').value = a.address_line_2 || '';
      form.querySelector('[name="city"]').value = a.city || '';
      form.querySelector('[name="state"]').value = a.state || '';
      form.querySelector('[name="landmark"]').value = a.landmark || '';
      var def = form.querySelector('[name="is_default"]');
      if (def) { def.checked = (a.is_default === true || a.is_default === 1 || a.is_default === '1'); }
      if (title) { title.textContent = 'Edit address'; }
      clearError(form); busy(form, false);
      openSheet(sheet);
    }

    document.querySelectorAll('[data-okv-add-address]').forEach(function (b) { b.addEventListener('click', openAdd); });

    // The address form saves, then re-renders the list without a reload.
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearError(form); busy(form, true);
      post(form.getAttribute('action'), formBody(form)).then(function (r) {
        if (r.ok && r.data.status === 'ok') {
          closeSheet(sheet);
          OKV.toast(r.data.message || 'Address saved.', 'ok');
          refresh();
          return;
        }
        setError(form, r.data.message || 'Something went wrong. Please try again.');
        busy(form, false);
      }).catch(function () {
        setError(form, 'We could not reach the server. Check your connection and try again.');
        busy(form, false);
      });
    });

    function cardHtml(a) {
      var esc = OKV.escape;
      var line = esc(a.address_line_1) + (a.address_line_2 ? ', ' + esc(a.address_line_2) : '') + ', ' + esc(a.city) + ', ' + esc(a.state);
      var badge = a.is_default ? '<span class="okv-badge okv-badge-available ml-1">Default</span>' : '';
      var makeDefault = a.is_default ? '' : '<button type="button" class="okv-btn-text" data-okv-default-address="' + a.id + '">Make default</button>';
      var payload = esc(JSON.stringify(a));
      return '<article class="rounded-md border border-mist p-4" data-address-id="' + a.id + '">' +
        '<p class="font-medium">' + esc(a.recipient_name) + badge + '</p>' +
        '<p class="text-sm text-ink-60 mt-1">' + line + '</p>' +
        '<p class="text-sm text-ink-40 mt-0.5">' + esc(a.recipient_phone_display || a.recipient_phone) + '</p>' +
        '<div class="flex flex-wrap gap-2 mt-3">' +
          '<button type="button" class="okv-btn-text" data-okv-edit-address="' + payload + '">Edit</button>' + makeDefault +
          '<button type="button" class="okv-btn-text text-tomato hover:text-tomato-hover" data-okv-delete-address="' + a.id + '">Remove</button>' +
        '</div></article>';
    }

    function refresh() {
      list.innerHTML = '<div class="okv-skeleton h-24"></div><div class="okv-skeleton h-24"></div>';
      fetch('/api/v1/account.php?action=list_addresses', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          var rows = (data && data.addresses) || [];
          if (!rows.length) {
            list.innerHTML = '<p class="text-ink-60" data-empty>You have no saved addresses yet.</p>';
            return;
          }
          var html = '';
          for (var i = 0; i < rows.length; i++) { html += cardHtml(rows[i]); }
          list.innerHTML = html;
        })
        .catch(function () { OKV.toast('We could not load your addresses.', 'error'); });
    }

    // Delegated actions on the list (works for server-rendered and refreshed cards).
    list.addEventListener('click', function (e) {
      var edit = e.target.closest('[data-okv-edit-address]');
      if (edit) { try { openEdit(JSON.parse(edit.getAttribute('data-okv-edit-address'))); } catch (err) {} return; }

      var mk = e.target.closest('[data-okv-default-address]');
      if (mk) {
        post('/api/v1/account.php', 'action=set_default_address&address_id=' + encodeURIComponent(mk.getAttribute('data-okv-default-address')))
          .then(function (r) { if (r.ok && r.data.status === 'ok') { OKV.toast(r.data.message || 'Default set.', 'ok'); refresh(); } else { OKV.toast(r.data.message || 'That did not work.', 'error'); } });
        return;
      }

      var rm = e.target.closest('[data-okv-delete-address], [data-okv-remove]');
      if (rm) {
        var id = rm.getAttribute('data-okv-delete-address') || rm.getAttribute('data-okv-remove');
        // Two-tap confirm, no blocking dialog.
        if (rm.getAttribute('data-confirm') !== '1') {
          rm.setAttribute('data-confirm', '1');
          var original = rm.textContent;
          rm.textContent = 'Tap again to remove';
          setTimeout(function () { rm.setAttribute('data-confirm', '0'); rm.textContent = original; }, 3000);
          return;
        }
        post('/api/v1/account.php', 'action=delete_address&address_id=' + encodeURIComponent(id))
          .then(function (r) { if (r.ok && r.data.status === 'ok') { OKV.toast(r.data.message || 'Address removed.', 'ok'); refresh(); } else { OKV.toast(r.data.message || 'That did not work.', 'error'); } });
      }
    });

    // Wire the server-rendered edit buttons (they carry JSON in the attribute).
  }

  /* --------------------------------------------------- activation (OTP) ----- */

  function wireActivate() {
    var verify = document.querySelector('form[data-okv-verify]');
    var resend = document.querySelector('form[data-okv-resend]');
    if (verify) {
      verify.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(verify); busy(verify, true);
        post(verify.getAttribute('action'), formBody(verify)).then(function (r) {
          if (r.ok && r.data.status === 'ok') { window.location.assign(r.data.redirect || '/account.php'); return; }
          setError(verify, r.data.message || 'That code is not right or has expired.');
          busy(verify, false);
        }).catch(function () { setError(verify, 'We could not reach the server. Try again.'); busy(verify, false); });
      });
    }
    if (resend) {
      var btn = resend.querySelector('[data-okv-resend-btn]');
      var notice = document.querySelector('[data-okv-notice]');
      function cooldown(secs) {
        if (!btn) { return; }
        btn.disabled = true;
        var left = secs;
        var label = btn.textContent;
        btn.textContent = 'Send again in ' + left + 's';
        var timer = setInterval(function () {
          left -= 1;
          if (left <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = label; }
          else { btn.textContent = 'Send again in ' + left + 's'; }
        }, 1000);
      }
      resend.addEventListener('submit', function (e) {
        e.preventDefault();
        if (btn) { btn.disabled = true; }
        post(resend.getAttribute('action'), formBody(resend)).then(function (r) {
          if (r.ok && r.data.status === 'ok') {
            if (notice) { notice.textContent = r.data.message || 'We have sent a new code to your email.'; notice.hidden = false; }
            cooldown(r.data.cooldown || 60);
            return;
          }
          if (r.status === 429 && r.data.retry_after) { OKV.toast(r.data.message || 'Please wait a moment.', 'error'); cooldown(r.data.retry_after); return; }
          OKV.toast(r.data.message || 'We could not send the code right now. Please try again in a moment.', 'error');
          if (btn) { btn.disabled = false; }
        }).catch(function () { OKV.toast('We could not reach the server. Try again.', 'error'); if (btn) { btn.disabled = false; } });
      });
    }
  }

  /* ------------------------------------------------- password reset flow ---- */

  function wireReset() {
    var forgot = document.querySelector('form[data-okv-forgot]');
    var reset = document.querySelector('form[data-okv-reset]');
    if (forgot) {
      forgot.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(forgot); busy(forgot, true);
        var email = (forgot.querySelector('[name="email"]') || {}).value || '';
        post(forgot.getAttribute('action'), formBody(forgot)).then(function (r) {
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
          busy(forgot, false);
        }).catch(function () { setError(forgot, 'We could not reach the server. Try again.'); busy(forgot, false); });
      });
    }
    if (reset) {
      reset.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(reset); busy(reset, true);
        post(reset.getAttribute('action'), formBody(reset)).then(function (r) {
          if (r.ok && r.data.status === 'ok') { window.location.assign(r.data.redirect || '/account.php?mode=signin&reset=1'); return; }
          setError(reset, r.data.message || 'Something went wrong. Please try again.');
          busy(reset, false);
        }).catch(function () { setError(reset, 'We could not reach the server. Try again.'); busy(reset, false); });
      });
    }
  }

  /* ------------------------------------------------------------- init ------- */

  ready(function () {
    document.querySelectorAll('form[data-okv-auth]').forEach(handleSignin);
    document.querySelectorAll('form[data-okv-register]').forEach(handleRegister);
    wireTabs();
    wireBusinessToggle();
    wireSheets();
    wireAjaxForms();
    wireAddresses();
    wireActivate();
    wireReset();
  });
})();
