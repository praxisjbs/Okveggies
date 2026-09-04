/**
 * assets/js/admin-settings.js
 * OK Veggies. The Settings screen. Tab switching, dirty tracking, the
 * confirmation step for a change that moves money, and inline field errors.
 *
 * Everything here is a convenience. The server re-checks the permission and the
 * CSRF token on every write, re-validates every value, and decides for itself
 * what actually changed, so nothing below is load bearing for correctness. The
 * confirmation list is drawn from what the server said the change would be, not
 * from the form, so it can never claim a change the server would not make.
 *
 * All values are written with textContent, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/settings.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function post(body) {
    return fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: body
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, data: data || {} };
      });
    });
  }

  // --- Tabs ------------------------------------------------------------------

  function wireTabs() {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-settings-tab]'));
    if (!tabs.length) { return; }

    function show(name) {
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('data-settings-tab') === name;
        tab.setAttribute('aria-selected', String(on));
        tab.classList.toggle('border-forest', on);
        tab.classList.toggle('text-forest', on);
        tab.classList.toggle('border-transparent', !on);
        tab.classList.toggle('text-ink-60', !on);
      });
      document.querySelectorAll('[data-settings-panel]').forEach(function (panel) {
        panel.classList.toggle('hidden', panel.getAttribute('data-settings-panel') !== name);
      });
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { show(tab.getAttribute('data-settings-tab')); });
    });
  }

  // --- One form --------------------------------------------------------------

  function wireForm(form) {
    var group    = form.getAttribute('data-group');
    var fields   = Array.prototype.slice.call(form.querySelectorAll('[data-settings-field]'));
    var save     = form.querySelector('[data-settings-save]');
    var reset    = form.querySelector('[data-settings-reset]');
    var dirtyTag = form.querySelector('[data-settings-dirty]');
    var confirm  = form.querySelector('[data-settings-confirm]');
    var list     = form.querySelector('[data-settings-confirm-list]');
    var yes      = form.querySelector('[data-settings-confirm-yes]');
    var no       = form.querySelector('[data-settings-confirm-no]');

    if (!save) { return; }   // read-only for this person, nothing to wire.

    function currentValue(input) {
      return input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
    }

    function isDirty() {
      return fields.some(function (input) {
        return currentValue(input) !== (input.getAttribute('data-original') || '');
      });
    }

    function refreshDirty() {
      var dirty = isDirty();
      if (dirtyTag) { dirtyTag.classList.toggle('hidden', !dirty); }
      if (reset) { reset.classList.toggle('hidden', !dirty); }
    }

    function clearErrors() {
      form.querySelectorAll('[data-settings-error]').forEach(function (node) {
        node.textContent = '';
        node.classList.add('hidden');
      });
    }

    function showErrors(errors) {
      var firstBad = null;
      Object.keys(errors || {}).forEach(function (key) {
        var node = form.querySelector('[data-settings-error="' + key + '"]');
        if (node) {
          node.textContent = errors[key];
          node.classList.remove('hidden');
        }
        if (!firstBad) { firstBad = form.querySelector('[data-settings-field="' + key + '"]'); }
      });
      if (firstBad) { firstBad.focus(); }
    }

    function hideConfirm() {
      if (confirm) { confirm.classList.add('hidden'); }
      if (list) { list.textContent = ''; }
    }

    function showConfirm(changes) {
      if (!confirm || !list) { return; }
      list.textContent = '';
      Object.keys(changes).forEach(function (key) {
        var change = changes[key];
        var li = el('li', 'flex flex-wrap gap-x-2');
        li.appendChild(el('span', 'font-medium', change.label));
        li.appendChild(el('span', 'text-ink-60', 'from'));
        li.appendChild(el('span', 'font-mono text-xs', change.from));
        li.appendChild(el('span', 'text-ink-60', 'to'));
        li.appendChild(el('span', 'font-mono text-xs', change.to));
        list.appendChild(li);
      });
      confirm.classList.remove('hidden');
      if (yes) { yes.focus(); }
    }

    /**
     * Take the form as it stands, plus the CSRF token, the named action and the
     * tab it belongs to. The preview action is shared by both tabs, so it needs
     * the group named or the server has no way to tell which one it is looking at.
     */
    function body(action) {
      var data = new FormData(form);
      data.set('action', action);
      data.set('group', group);
      if (window.OKV && OKV.csrf) { data.set('okv_csrf', OKV.csrf); }
      return data;
    }

    function applySaved(payload) {
      // Re-baseline every field against what the server now holds, so the form
      // stops reading as dirty and a second save posts no change.
      var byKey = {};
      ((payload && payload.group && payload.group.fields) || []).forEach(function (field) {
        byKey[field.key] = field;
      });
      fields.forEach(function (input) {
        var field = byKey[input.getAttribute('data-settings-field')];
        if (!field) { return; }
        if (input.type === 'checkbox') {
          input.checked = !!field.value;
          input.setAttribute('data-original', field.value ? '1' : '0');
        } else {
          input.setAttribute('data-original', input.value);
        }
      });
      refreshDirty();
    }

    function doSave() {
      save.disabled = true;
      post(body(form.querySelector('[name="action"]').value)).then(function (res) {
        save.disabled = false;
        hideConfirm();
        if (!res.ok) {
          if (res.data.errors) { showErrors(res.data.errors); }
          toast(res.data.message || 'We could not save that.', 'error');
          return;
        }
        clearErrors();
        applySaved(res.data);
        toast(res.data.message || 'Saved.', 'ok');
        // The audit panel is rendered on the server, so a reload is the honest
        // way to show the change that was just recorded.
        if (res.data.changed && Object.keys(res.data.changed).length) {
          window.setTimeout(function () { window.location.reload(); }, 900);
        }
      }).catch(function () {
        save.disabled = false;
        toast('We could not reach the server. Nothing was changed.', 'error');
      });
    }

    fields.forEach(function (input) {
      input.addEventListener('input', function () { refreshDirty(); hideConfirm(); });
      input.addEventListener('change', function () { refreshDirty(); hideConfirm(); });
    });

    if (reset) {
      reset.addEventListener('click', function () {
        fields.forEach(function (input) {
          var original = input.getAttribute('data-original') || '';
          if (input.type === 'checkbox') { input.checked = original === '1'; }
          else { input.value = original; }
        });
        clearErrors();
        hideConfirm();
        refreshDirty();
      });
    }

    if (no) { no.addEventListener('click', function () { hideConfirm(); save.focus(); }); }
    if (yes) { yes.addEventListener('click', doSave); }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();

      if (!isDirty()) {
        toast('Nothing has changed yet.', 'ok');
        return;
      }

      // Ask the server what this would do. It answers with the change list and
      // whether any of it needs confirming, and writes nothing either way.
      save.disabled = true;
      post(body('preview')).then(function (res) {
        save.disabled = false;
        if (!res.ok) {
          if (res.data.errors) { showErrors(res.data.errors); }
          toast(res.data.message || 'Some values need a look.', 'error');
          return;
        }
        var changes = res.data.changed || {};
        if (!Object.keys(changes).length) {
          toast('Nothing had changed, so nothing was saved.', 'ok');
          return;
        }
        if (res.data.needs_confirm) { showConfirm(changes); }
        else { doSave(); }
      }).catch(function () {
        save.disabled = false;
        toast('We could not reach the server. Nothing was changed.', 'error');
      });
    });

    // A person who edits the deposit and then clicks away deserves a warning.
    window.addEventListener('beforeunload', function (event) {
      if (isDirty()) { event.preventDefault(); event.returnValue = ''; }
    });

    refreshDirty();
  }

  // --- Notification templates -----------------------------------------------
  // The words each automated email sends. Preview renders the branded shell in
  // a sandboxed iframe so an Owner sees the real thing before saving. The
  // preview HTML comes from our own server and is written with srcdoc into a
  // sandbox with no scripts, so it cannot reach the admin page around it.

  function showPreview(form, html) {
    var frame = form.querySelector('[data-template-frame]');
    if (!frame) { return; }
    frame.setAttribute('sandbox', '');
    frame.srcdoc = String(html || '');
    frame.classList.remove('hidden');
  }

  function say(form, message, isError) {
    var slot = form.querySelector('[data-template-message]');
    if (!slot) { return; }
    slot.textContent = String(message || '');
    slot.className = 'text-sm ' + (isError ? 'text-tomato' : 'text-forest');
  }

  function templateBody(form, action) {
    var body = new FormData(form);
    body.set('action', action);
    return body;
  }

  function wireTemplateForm(form) {
    var preview = form.querySelector('[data-template-preview]');
    if (preview) {
      preview.addEventListener('click', function () {
        say(form, 'Rendering the preview.', false);
        post(templateBody(form, 'preview_template')).then(function (res) {
          if (!res.ok) { say(form, res.data.message || 'The preview could not be built.', true); return; }
          say(form, 'This is what the customer sees.', false);
          showPreview(form, res.data.preview);
        }).catch(function () { say(form, 'We could not reach the server.', true); });
      });
    }

    var test = form.querySelector('[data-template-test]');
    if (test) {
      test.addEventListener('click', function () {
        test.disabled = true;
        say(form, 'Sending one to you.', false);
        post(templateBody(form, 'send_test_email')).then(function (res) {
          test.disabled = false;
          say(form, res.data.message || (res.ok ? 'Sent.' : 'It could not be sent.'), !res.ok);
          toast(res.data.message || (res.ok ? 'Sent.' : 'It could not be sent.'), res.ok ? 'ok' : 'error');
        }).catch(function () {
          test.disabled = false;
          say(form, 'We could not reach the server.', true);
        });
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      say(form, 'Saving.', false);
      post(templateBody(form, 'save_template')).then(function (res) {
        if (!res.ok) {
          say(form, res.data.message || 'Those words were not saved.', true);
          toast(res.data.message || 'Those words were not saved.', 'error');
          return;
        }
        say(form, res.data.message || 'Saved.', false);
        toast(res.data.message || 'Saved.', 'ok');
        showPreview(form, res.data.preview);
      }).catch(function () {
        say(form, 'We could not reach the server. Nothing was changed.', true);
      });
    });
  }

  ready(function () {
    wireTabs();
    document.querySelectorAll('[data-settings-form]').forEach(wireForm);
    document.querySelectorAll('[data-template-form]').forEach(wireTemplateForm);
  });
})();
