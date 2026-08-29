/**
 * assets/js/admin-products.js
 * OK Veggies. The catalogue screen. Forms marked data-product-form post to
 * api/v1/products.php by fetch, show field errors in place, and reload on
 * success. Photo actions and the remove confirmation live here too.
 *
 * Without JavaScript the list still renders and every form still posts, because
 * each one carries its own action and CSRF token. The server re-checks every
 * permission regardless. User data is written with textContent, never innerHTML.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/v1/products.php';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function toast(message, type) {
    if (window.OKV && OKV.toast) { OKV.toast(message, type); }
  }

  function csrfFrom(form) {
    var field = form.querySelector('input[name="okv_csrf"]');
    if (field) { return field.value; }
    return (window.OKV && OKV.csrf) ? OKV.csrf : '';
  }

  function send(body) {
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

  /** Clear the field-level marks a previous attempt left behind. */
  function clearFieldErrors(form) {
    form.querySelectorAll('[data-field-error]').forEach(function (node) { node.remove(); });
    form.querySelectorAll('.okv-input').forEach(function (input) {
      input.removeAttribute('aria-invalid');
      input.classList.remove('border-tomato');
    });
  }

  /** Put each server-side error next to the field it belongs to. */
  function showFieldErrors(form, errors) {
    Object.keys(errors).forEach(function (name) {
      var field = form.querySelector('[name="' + name + '"]');
      if (!field) { return; }
      field.setAttribute('aria-invalid', 'true');
      field.classList.add('border-tomato');
      var note = document.createElement('p');
      note.className = 'text-xs text-tomato mt-1';
      note.setAttribute('data-field-error', '');
      note.textContent = errors[name];
      if (field.parentNode) { field.parentNode.appendChild(note); }
    });
    var first = form.querySelector('[aria-invalid="true"]');
    if (first) { first.focus(); }
  }

  function wireProductForm(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var errBox = form.querySelector('[data-okv-error]');
      var button = form.querySelector('button[type="submit"]');
      clearFieldErrors(form);
      if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }

      function release() {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
      }

      send(new URLSearchParams(new FormData(form))).then(function (res) {
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Saved.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        release();
        if (res.data.errors) { showFieldErrors(form, res.data.errors); }
        var message = res.data.message || 'Something went wrong. Please try again.';
        if (errBox) { errBox.textContent = message; errBox.hidden = false; }
        else { toast(message, 'error'); }
      }).catch(function () {
        release();
        var message = 'We could not reach the server. Check your connection and try again.';
        if (errBox) { errBox.textContent = message; errBox.hidden = false; }
        else { toast(message, 'error'); }
      });
    });
  }

  /** A restock date only means something while a product is restocking. */
  function wireAvailability(select) {
    var form = select.closest('form');
    if (!form) { return; }
    var field = form.querySelector('[data-restock-field]');
    if (!field) { return; }
    select.addEventListener('change', function () {
      field.hidden = select.value !== 'restocking';
    });
  }

  function wireImageForm(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.textContent = 'Uploading'; }

      var body = new FormData(form);
      send(body).then(function (res) {
        if (button) { button.disabled = false; button.textContent = 'Upload'; }
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Photo added.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        toast(res.data.message || 'We could not add that photo.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; button.textContent = 'Upload'; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireImageButtons(root) {
    var container = root.closest('[data-images-for]');
    if (!container) { return; }
    var productId = container.getAttribute('data-images-for');
    var form = container.querySelector('[data-image-form]');
    if (!form) { return; }

    root.addEventListener('click', function () {
      var imageId = root.getAttribute('data-image-id');
      var isDelete = root.hasAttribute('data-image-delete');

      if (isDelete && !window.confirm('Remove this photo? It cannot be undone.')) { return; }

      var body = new URLSearchParams();
      body.set('action', isDelete ? 'delete_image' : 'set_primary_image');
      body.set('product_id', productId);
      body.set('image_id', imageId);
      body.set('okv_csrf', csrfFrom(form));

      root.disabled = true;
      send(body).then(function (res) {
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Saved.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        root.disabled = false;
        toast(res.data.message || 'We could not do that.', 'error');
      }).catch(function () {
        root.disabled = false;
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wireDeleteForm(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!window.confirm('Remove this product? If anything refers to it we will tell you instead, and you can switch it off.')) {
        return;
      }
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }

      send(new URLSearchParams(new FormData(form))).then(function (res) {
        if (res.ok && res.data.status === 'ok') {
          toast(res.data.message || 'Product removed.', 'ok');
          setTimeout(function () { window.location.reload(); }, 400);
          return;
        }
        if (button) { button.disabled = false; }
        // The "in use" answer is the useful one: it names what is holding it.
        toast(res.data.message || 'We could not remove that product.', 'error');
      }).catch(function () {
        if (button) { button.disabled = false; }
        toast('We could not reach the server. Check your connection and try again.', 'error');
      });
    });
  }

  function wirePanel(openSelector, panelSelector, closeSelector) {
    var open = document.querySelector(openSelector);
    var panel = document.querySelector(panelSelector);
    if (!open || !panel) { return; }
    var close = panel.querySelector(closeSelector);

    open.addEventListener('click', function () {
      panel.hidden = false;
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = panel.querySelector('input, select, textarea');
      if (first) { first.focus(); }
    });
    if (close) {
      close.addEventListener('click', function () {
        panel.hidden = true;
        open.focus();
      });
    }
  }

  ready(function () {
    document.querySelectorAll('form[data-product-form]').forEach(wireProductForm);
    document.querySelectorAll('[data-availability]').forEach(wireAvailability);
    document.querySelectorAll('form[data-image-form]').forEach(wireImageForm);
    document.querySelectorAll('[data-image-primary], [data-image-delete]').forEach(wireImageButtons);
    document.querySelectorAll('form[data-delete-form]').forEach(wireDeleteForm);
    wirePanel('[data-add-open]', '[data-add-panel]', '[data-add-close]');

    // Opened straight from the pricing screen: bring that product into view.
    var params = new URLSearchParams(window.location.search);
    var wanted = params.get('product');
    if (wanted) {
      var card = document.getElementById('product-' + wanted);
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
  });
})();
