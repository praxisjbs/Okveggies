/** Progressive enhancement for the admin combo builder. */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/combos.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function money(subunit) {
    return window.OKV && OKV.money ? OKV.money(subunit) : '₦' + Math.round(subunit / 100).toLocaleString('en-NG');
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function send(body) {
    return fetch(ENDPOINT, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, body: body
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { ok: response.ok, data: data || {} };
      });
    });
  }

  function clearErrors(form) {
    form.querySelectorAll('[data-field-error]').forEach(function (node) { node.remove(); });
    form.querySelectorAll('.okv-input').forEach(function (field) {
      field.removeAttribute('aria-invalid');
      field.classList.remove('border-tomato');
    });
  }

  function fieldFor(form, name) {
    var component = /^component_quantity_(\d+)$/.exec(name);
    if (component) {
      return form.querySelector('[data-component-row][data-product-id="' + component[1] + '"] [data-component-quantity]');
    }
    return form.querySelector('[name="' + name + '"]');
  }

  function showErrors(form, errors) {
    Object.keys(errors).forEach(function (name) {
      var field = fieldFor(form, name);
      if (!field) { return; }
      field.setAttribute('aria-invalid', 'true');
      field.classList.add('border-tomato');
      var note = document.createElement('p');
      note.className = 'mt-1 text-xs text-tomato';
      note.setAttribute('data-field-error', '');
      note.textContent = errors[name];
      field.parentNode.appendChild(note);
    });
    var first = form.querySelector('[aria-invalid="true"]');
    if (first) { first.focus(); }
  }

  function parseNaira(value) {
    var clean = String(value || '').replace(/[^0-9.]/g, '');
    if (!clean) { return 0; }
    var amount = Number(clean);
    return Number.isFinite(amount) ? Math.round(amount * 100) : 0;
  }

  function calculate(form) {
    var total = 0;
    var unpriced = false;
    form.querySelectorAll('[data-component-row]').forEach(function (row) {
      var check = row.querySelector('[data-component-check]');
      var quantity = row.querySelector('[data-component-quantity]');
      var selected = check && check.checked;
      if (quantity) { quantity.disabled = !selected; }
      if (!selected || !quantity) { return; }
      var price = Number(row.getAttribute('data-price-subunit') || 0);
      var amount = Number(String(quantity.value || '').replace(/[^0-9.]/g, ''));
      if (!Number.isFinite(amount) || amount <= 0) { return; }
      if (price <= 0) { unpriced = true; return; }
      total += Math.round(price * amount);
    });
    var totalNode = form.querySelector('[data-component-total]');
    if (totalNode) { totalNode.textContent = money(total); }
    var unpricedWarning = form.querySelector('[data-unpriced-warning]');
    if (unpricedWarning) { unpricedWarning.hidden = !unpriced; }
    var sell = form.querySelector('[data-sell-price]');
    var loss = parseNaira(sell ? sell.value : '');
    var lossWarning = form.querySelector('[data-loss-warning]');
    if (lossWarning) { lossWarning.hidden = !(loss > 0 && total > loss); }
  }

  function wireBuilder(form) {
    form.querySelectorAll('[data-component-check], [data-component-quantity], [data-sell-price]').forEach(function (field) {
      field.addEventListener('input', function () { calculate(form); });
      field.addEventListener('change', function () { calculate(form); });
    });
    calculate(form);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors(form);
      var error = form.querySelector('[data-okv-error]');
      var button = form.querySelector('button[type="submit"]');
      if (error) { error.hidden = true; error.textContent = ''; }
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
      send(new URLSearchParams(new FormData(form))).then(function (result) {
        if (result.ok && result.data.status === 'ok') {
          toast(result.data.message || 'Combo saved.', 'ok');
          window.setTimeout(function () { window.location.href = '/admin/combos.php?combo=' + encodeURIComponent(result.data.combo_id || ''); }, 400);
          return;
        }
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
        if (result.data.errors) { showErrors(form, result.data.errors); }
        if (error) { error.textContent = result.data.message || 'We could not save that combo.'; error.hidden = false; }
        else { toast(result.data.message || 'We could not save that combo.', 'error'); }
      }).catch(function () {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
        if (error) { error.textContent = 'We could not reach the server. Check your connection and try again.'; error.hidden = false; }
      });
    });
  }

  function wirePhotoForm(form) {
    var input = form.querySelector('[data-photo-input]');
    var preview = form.querySelector('[data-photo-preview]');
    if (input && preview) {
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) { preview.hidden = true; preview.removeAttribute('src'); return; }
        var reader = new FileReader();
        reader.onload = function () { preview.src = String(reader.result || ''); preview.hidden = false; };
        reader.readAsDataURL(file);
      });
    }
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }
      send(new FormData(form)).then(function (result) {
        if (result.ok && result.data.status === 'ok') {
          toast(result.data.message || 'Combo photo updated.', 'ok');
          window.setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        if (button) { button.disabled = false; }
        toast(result.data.message || 'We could not update that photo.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireDelete(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!window.confirm('Remove this combo? If it has been priced, ordered or added to a basket, we will take it off the shop instead.')) { return; }
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }
      send(new URLSearchParams(new FormData(form))).then(function (result) {
        if (result.ok && result.data.status === 'ok') {
          toast(result.data.message || 'Combo removed.', result.data.code === 'unpublished' ? 'error' : 'ok');
          window.setTimeout(function () { window.location.reload(); }, 500);
          return;
        }
        if (button) { button.disabled = false; }
        toast(result.data.message || 'We could not remove that combo.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireAddPanel() {
    var open = document.querySelector('[data-add-open]');
    var panel = document.querySelector('[data-add-panel]');
    var close = document.querySelector('[data-add-close]');
    if (!open || !panel) { return; }
    open.addEventListener('click', function () {
      panel.hidden = false;
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = panel.querySelector('input, textarea');
      if (first) { first.focus(); }
    });
    if (close) { close.addEventListener('click', function () { panel.hidden = true; open.focus(); }); }
  }

  ready(function () {
    document.querySelectorAll('form[data-combo-form]').forEach(wireBuilder);
    document.querySelectorAll('form[data-photo-form]').forEach(wirePhotoForm);
    document.querySelectorAll('form[data-delete-form]').forEach(wireDelete);
    wireAddPanel();
  });
}());
