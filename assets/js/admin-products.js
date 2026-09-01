/**
 * assets/js/admin-products.js
 * OK Veggies. The catalogue screen. The search and the two dropdowns filter
 * live (debounced against the server), forms marked data-product-form post to
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

  /** Wire every interactive form and button inside a rendered list. Runs once
      on page load, then again on the freshly swapped nodes whenever the live
      filter replaces the list. */
  function wireList(root) {
    root.querySelectorAll('form[data-product-form]').forEach(wireProductForm);
    root.querySelectorAll('[data-availability]').forEach(wireAvailability);
    root.querySelectorAll('form[data-image-form]').forEach(wireImageForm);
    root.querySelectorAll('[data-image-primary], [data-image-delete]').forEach(wireImageButtons);
    root.querySelectorAll('form[data-delete-form]').forEach(wireDeleteForm);
  }

  /**
   * The live filter. Typing in Search or changing a dropdown asks the server
   * for that page of the catalogue (debounced on keystrokes) and swaps the
   * list with exactly the markup a plain reload of the same URL renders. The
   * Filter button and the GET form still work without JavaScript.
   */
  function liveAdminFilter(container) {
    if (!container || !window.fetch || !window.AbortController) { return; }
    var form = document.querySelector('[data-admin-filter]');
    var searchInput = document.getElementById('search');
    var categoryInput = document.getElementById('category');
    var statusInput = document.getElementById('status');
    if (!form || !searchInput || !categoryInput || !statusInput) { return; }

    var summary = document.querySelector('[data-admin-summary]');
    var timer = null;
    var controller = null;

    function readState() {
      return {
        search: searchInput.value.trim(),
        category: categoryInput.value,
        status: statusInput.value
      };
    }

    function pageUrl(state, page) {
      var params = new URLSearchParams();
      if (state.search !== '') { params.set('search', state.search); }
      if (state.category !== '') { params.set('category', state.category); }
      if (state.status !== '') { params.set('status', state.status); }
      if (page > 1) { params.set('page', page); }
      var query = params.toString();
      return '/admin/products.php' + (query ? '?' + query : '');
    }

    function browse(page, push) {
      var state = readState();
      if (controller) { controller.abort(); }
      controller = new AbortController();
      container.setAttribute('aria-busy', 'true');

      var api = '/api/v1/products.php?action=browse'
        + '&search=' + encodeURIComponent(state.search)
        + '&category=' + encodeURIComponent(state.category)
        + '&status=' + encodeURIComponent(state.status)
        + '&page=' + page;

      fetch(api, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: controller.signal
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          return { ok: res.ok, data: data };
        });
      }).then(function (res) {
        if (!res.ok || res.data.status !== 'ok' || typeof res.data.html !== 'string') {
          throw new Error(res.data.message || 'browse failed');
        }
        container.innerHTML = res.data.html;
        container.removeAttribute('aria-busy');
        if (summary) { summary.textContent = res.data.summary; }
        var url = pageUrl(state, res.data.page);
        if (push) { window.history.pushState(null, '', url); }
        else { window.history.replaceState(null, '', url); }
        wireList(container);
      }).catch(function (error) {
        if (error && error.name === 'AbortError') { return; }
        container.removeAttribute('aria-busy');
        toast('We could not load that page. Check your connection and try again.', 'error');
      });
    }

    searchInput.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { browse(1, false); }, 300);
    });
    categoryInput.addEventListener('change', function () { browse(1, true); });
    statusInput.addEventListener('change', function () { browse(1, true); });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      window.clearTimeout(timer);
      browse(1, true);
    });

    document.addEventListener('click', function (event) {
      var link = event.target && event.target.closest ? event.target.closest('[data-pagination] a') : null;
      if (!link || !container.contains(link)) { return; }
      var href = link.getAttribute('href') || '';
      if (href.indexOf('/admin/products.php') !== 0) { return; }
      event.preventDefault();
      var page = parseInt(new URLSearchParams(href.split('?')[1] || '').get('page'), 10) || 1;
      browse(page, true);
    });

    window.addEventListener('popstate', function () {
      var params = new URLSearchParams(window.location.search);
      searchInput.value = (params.get('search') || '').trim();
      categoryInput.value = params.get('category') || '';
      statusInput.value = params.get('status') || '';
      browse(parseInt(params.get('page'), 10) || 1, false);
    });
  }

  ready(function () {
    wireList(document);
    wirePanel('[data-add-open]', '[data-add-panel]', '[data-add-close]');
    liveAdminFilter(document.querySelector('[data-admin-results]'));

    // Opened straight from the pricing screen: bring that product into view.
    var params = new URLSearchParams(window.location.search);
    var wanted = params.get('product');
    if (wanted) {
      var card = document.getElementById('product-' + wanted);
      if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
  });
})();
