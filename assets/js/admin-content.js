/** M12 Page Copy progressive enhancement. Server forms remain fully usable. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var forms = Array.prototype.slice.call(document.querySelectorAll('[data-content-form]'));
    var saveForm = document.querySelector('[data-save-form]');
    var preview = document.querySelector('[data-content-preview]');
    var submitting = false;
    if (!forms.length) { return; }

    var initial = saveForm ? new FormData(saveForm) : null;
    function comparable(data) {
      var values = [];
      data.forEach(function (value, key) {
        if (key !== 'okv_csrf' && key !== 'fingerprint') { values.push(key + '=' + String(value)); }
      });
      return values.sort().join('&');
    }
    function dirty() {
      return !!(saveForm && initial && comparable(new FormData(saveForm)) !== comparable(initial));
    }
    function refreshDirty() {
      var marker = document.querySelector('[data-content-dirty]');
      if (marker) { marker.classList.toggle('hidden', !dirty()); }
    }
    function clearErrors() {
      document.querySelectorAll('[data-field-error]').forEach(function (node) {
        node.textContent = '';
        node.classList.add('hidden');
      });
      var summary = document.querySelector('[data-content-error]');
      if (summary) { summary.textContent = ''; summary.classList.add('hidden'); }
    }
    function showErrors(payload) {
      var summary = document.querySelector('[data-content-error]');
      if (summary) {
        summary.textContent = payload.message || 'Some fields need attention. Nothing was changed.';
        summary.classList.remove('hidden');
      }
      var first = null;
      Object.keys(payload.errors || {}).forEach(function (key) {
        var node = document.querySelector('[data-field-error="' + key + '"]');
        if (node) { node.textContent = payload.errors[key]; node.classList.remove('hidden'); }
        if (!first) {
          var parts = key.split('.');
          var fieldName = parts.length > 1 ? parts.shift() + '[' + parts.join('][') + ']' : key;
          first = document.querySelector('[name="' + fieldName + '"]');
        }
      });
      if (first) { first.focus(); }
      if (summary) { summary.scrollIntoView({ block: 'center' }); }
    }

    if (saveForm) {
      saveForm.addEventListener('input', refreshDirty);
      saveForm.addEventListener('change', refreshDirty);
    }
    if (preview) {
      preview.addEventListener('click', function (event) {
        if (dirty() && !window.confirm('This preview shows the last saved draft. Open it without saving these changes?')) {
          event.preventDefault();
        }
      });
    }

    forms.forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearErrors();
        var button = form.querySelector('[type="submit"]');
        if (button) { button.disabled = true; }
        // The forms carry <input name="action">. That control shadows the form's
        // action property, so form.action is the input, not the URL, and fetch
        // would post to /admin/[object HTMLInputElement]. Read the attribute.
        var endpoint = form.getAttribute('action') || '/api/v1/content.php';
        fetch(endpoint, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
          body: new FormData(form)
        }).then(function (response) {
          return response.json().catch(function () { return {}; }).then(function (payload) {
            if (!response.ok) { showErrors(payload); return; }
            submitting = true;
            var url = new URL(window.location.href);
            url.searchParams.delete('error');
            url.searchParams.set('notice', payload.code || 'updated');
            window.location.assign(url.toString());
          });
        }).catch(function () {
          showErrors({ message: 'We could not reach the server. Nothing was changed.' });
        }).finally(function () {
          if (button) { button.disabled = false; }
        });
      });
    });

    window.addEventListener('beforeunload', function (event) {
      if (!submitting && dirty()) { event.preventDefault(); event.returnValue = ''; }
    });
  });
})();
