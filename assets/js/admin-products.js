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
    var field = form ? form.querySelector('input[name="okv_csrf"]') : null;
    if (field && field.value) { return field.value; }
    var modal = document.querySelector('[data-catalogue-settings-modal]');
    if (modal && modal.getAttribute('data-csrf')) {
      return modal.getAttribute('data-csrf');
    }
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

  function wireCatalogueSettingsModal() {
    var modal = document.querySelector('[data-catalogue-settings-modal]');
    var openBtn = document.querySelector('[data-catalogue-settings-open]');
    if (!modal || !openBtn) { return; }

    var closeBtn = modal.querySelector('[data-catalogue-settings-close]');
    var opener = null;
    var catalogueSettingsChanged = false;

    // Tabs
    var tabCategories = modal.querySelector('[data-tab-btn="categories"]');
    var tabUnits = modal.querySelector('[data-tab-btn="units"]');
    var panelCategories = modal.querySelector('[data-tab-panel="categories"]');
    var panelUnits = modal.querySelector('[data-tab-panel="units"]');

    // Add containers and forms
    var catAddToggle = modal.querySelector('[data-category-add-toggle]');
    var catAddContainer = modal.querySelector('[data-category-add-container]');
    var catAddForm = modal.querySelector('[data-category-add-form]');
    var catAddCancel = modal.querySelector('[data-category-add-cancel]');

    var unitAddToggle = modal.querySelector('[data-unit-add-toggle]');
    var unitAddContainer = modal.querySelector('[data-unit-add-container]');
    var unitAddForm = modal.querySelector('[data-unit-add-form]');
    var unitAddCancel = modal.querySelector('[data-unit-add-cancel]');

    // Lists
    var catList = modal.querySelector('[data-category-list]');
    var unitList = modal.querySelector('[data-unit-list]');

    function getCsrf() {
      return modal.getAttribute('data-csrf') || (window.OKV && OKV.csrf ? OKV.csrf : '');
    }

    function shut() {
      modal.hidden = true;
      if (opener && typeof opener.focus === 'function') {
        opener.focus();
      }
      if (catalogueSettingsChanged) {
        window.location.reload();
      }
    }

    function open() {
      opener = openBtn;
      modal.hidden = false;
      var first = modal.querySelector('button, input');
      if (first) { first.focus(); }
      loadCategories();
      loadUnits();
    }

    openBtn.addEventListener('click', open);
    if (closeBtn) { closeBtn.addEventListener('click', shut); }

    modal.addEventListener('click', function (event) {
      if (event.target === modal) { shut(); }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        shut();
      }
    });

    function switchTab(target) {
      var isCat = (target === 'categories');
      if (tabCategories) {
        tabCategories.setAttribute('aria-selected', isCat ? 'true' : 'false');
        tabCategories.className = 'min-h-[44px] px-3 text-sm font-medium border-b-2 -mb-px transition-colors '
          + (isCat ? 'border-forest text-forest' : 'border-transparent text-ink-60 hover:text-ink');
      }
      if (tabUnits) {
        tabUnits.setAttribute('aria-selected', !isCat ? 'true' : 'false');
        tabUnits.className = 'min-h-[44px] px-3 text-sm font-medium border-b-2 -mb-px transition-colors '
          + (!isCat ? 'border-forest text-forest' : 'border-transparent text-ink-60 hover:text-ink');
      }
      if (panelCategories) { panelCategories.hidden = !isCat; }
      if (panelUnits) { panelUnits.hidden = isCat; }
    }

    if (tabCategories) {
      tabCategories.addEventListener('click', function () { switchTab('categories'); });
    }
    if (tabUnits) {
      tabUnits.addEventListener('click', function () { switchTab('units'); });
    }

    // Toggle Category Add Form
    if (catAddToggle && catAddContainer) {
      catAddToggle.addEventListener('click', function () {
        catAddContainer.hidden = !catAddContainer.hidden;
        if (!catAddContainer.hidden) {
          var input = catAddContainer.querySelector('input[name="name"]');
          if (input) { input.focus(); }
        }
      });
    }
    if (catAddCancel && catAddContainer && catAddForm) {
      catAddCancel.addEventListener('click', function () {
        catAddContainer.hidden = true;
        catAddForm.reset();
        var err = catAddContainer.querySelector('[data-category-add-error]');
        if (err) { err.hidden = true; err.textContent = ''; }
      });
    }

    // Toggle Unit Add Form
    if (unitAddToggle && unitAddContainer) {
      unitAddToggle.addEventListener('click', function () {
        unitAddContainer.hidden = !unitAddContainer.hidden;
        if (!unitAddContainer.hidden) {
          var input = unitAddContainer.querySelector('input[name="name"]');
          if (input) { input.focus(); }
        }
      });
    }
    if (unitAddCancel && unitAddContainer && unitAddForm) {
      unitAddCancel.addEventListener('click', function () {
        unitAddContainer.hidden = true;
        unitAddForm.reset();
        var err = unitAddContainer.querySelector('[data-unit-add-error]');
        if (err) { err.hidden = true; err.textContent = ''; }
      });
    }

    // Submit Category Add Form
    if (catAddForm) {
      catAddForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var errBox = catAddForm.querySelector('[data-category-add-error]');
        var submitBtn = catAddForm.querySelector('button[type="submit"]');
        if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
        if (submitBtn) { submitBtn.disabled = true; }

        var formData = new FormData(catAddForm);
        var body = new URLSearchParams();
        body.set('action', 'category_save');
        body.set('okv_csrf', getCsrf());
        body.set('name', formData.get('name') || '');
        body.set('description', formData.get('description') || '');
        body.set('is_active', formData.get('is_active') ? '1' : '0');

        send(body).then(function (res) {
          if (submitBtn) { submitBtn.disabled = false; }
          if (res.ok && res.data.status === 'ok') {
            toast(res.data.message || 'Category added.', 'ok');
            catalogueSettingsChanged = true;
            catAddForm.reset();
            if (catAddContainer) { catAddContainer.hidden = true; }
            loadCategories();
            return;
          }
          var msg = res.data.message || 'We could not add that category.';
          if (res.data.errors && res.data.errors.name) {
            msg = res.data.errors.name;
          }
          if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
          else { toast(msg, 'error'); }
        }).catch(function () {
          if (submitBtn) { submitBtn.disabled = false; }
          var msg = 'We could not reach the server. Check your connection.';
          if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
          else { toast(msg, 'error'); }
        });
      });
    }

    // Submit Unit Add Form
    if (unitAddForm) {
      unitAddForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var errBox = unitAddForm.querySelector('[data-unit-add-error]');
        var submitBtn = unitAddForm.querySelector('button[type="submit"]');
        if (errBox) { errBox.hidden = true; errBox.textContent = ''; }
        if (submitBtn) { submitBtn.disabled = true; }

        var formData = new FormData(unitAddForm);
        var body = new URLSearchParams();
        body.set('action', 'unit_save');
        body.set('okv_csrf', getCsrf());
        body.set('name', formData.get('name') || '');
        body.set('symbol', formData.get('symbol') || '');
        body.set('allows_decimal', formData.get('allows_decimal') ? '1' : '0');
        body.set('is_active', formData.get('is_active') ? '1' : '0');

        send(body).then(function (res) {
          if (submitBtn) { submitBtn.disabled = false; }
          if (res.ok && res.data.status === 'ok') {
            toast(res.data.message || 'Unit added.', 'ok');
            catalogueSettingsChanged = true;
            unitAddForm.reset();
            if (unitAddContainer) { unitAddContainer.hidden = true; }
            loadUnits();
            return;
          }
          var msg = res.data.message || 'We could not add that unit.';
          if (res.data.errors) {
            var firstErr = res.data.errors.name || res.data.errors.symbol;
            if (firstErr) { msg = firstErr; }
          }
          if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
          else { toast(msg, 'error'); }
        }).catch(function () {
          if (submitBtn) { submitBtn.disabled = false; }
          var msg = 'We could not reach the server. Check your connection.';
          if (errBox) { errBox.textContent = msg; errBox.hidden = false; }
          else { toast(msg, 'error'); }
        });
      });
    }

    function loadCategories() {
      if (!catList) { return; }
      fetch(ENDPOINT + '?action=category_list', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (res) {
        return res.json().catch(function () { return {}; });
      }).then(function (data) {
        if (data.status !== 'ok' || !Array.isArray(data.categories)) {
          catList.innerHTML = '<p class="text-sm text-tomato p-3">Could not load categories.</p>';
          return;
        }
        renderCategoryList(data.categories);
      }).catch(function () {
        catList.innerHTML = '<p class="text-sm text-tomato p-3">Could not load categories.</p>';
      });
    }

    function renderCategoryList(categories) {
      catList.innerHTML = '';
      if (categories.length === 0) {
        var empty = document.createElement('p');
        empty.className = 'text-sm text-ink-60 py-3 text-center';
        empty.textContent = 'No categories found.';
        catList.appendChild(empty);
        return;
      }

      categories.forEach(function (cat) {
        var card = document.createElement('div');
        card.className = 'p-4 rounded-lg border border-mist bg-white space-y-3';

        // Row preview
        var row = document.createElement('div');
        row.className = 'flex flex-wrap items-start justify-between gap-3';

        var info = document.createElement('div');
        info.className = 'space-y-1 min-w-0';

        var titleLine = document.createElement('div');
        titleLine.className = 'flex flex-wrap items-center gap-2';

        var nameSpan = document.createElement('span');
        nameSpan.className = 'font-semibold text-ink text-sm';
        nameSpan.textContent = cat.name;
        titleLine.appendChild(nameSpan);

        var slugTag = document.createElement('code');
        slugTag.className = 'font-mono text-xs text-ink-40 bg-forest-tint/40 px-1.5 py-0.5 rounded';
        slugTag.textContent = cat.slug;
        titleLine.appendChild(slugTag);

        var statusBadge = document.createElement('span');
        statusBadge.className = 'okv-badge ' + (cat.is_active ? 'okv-badge-available' : 'okv-badge-neutral');
        statusBadge.textContent = cat.is_active ? 'Active' : 'Hidden';
        titleLine.appendChild(statusBadge);

        var countBadge = document.createElement('span');
        countBadge.className = 'text-xs text-ink-60 font-mono';
        var pCount = parseInt(cat.product_count, 10) || 0;
        countBadge.textContent = pCount + ' ' + (pCount === 1 ? 'product' : 'products');
        titleLine.appendChild(countBadge);

        info.appendChild(titleLine);

        if (cat.description) {
          var desc = document.createElement('p');
          desc.className = 'text-xs text-ink-60 break-words';
          desc.textContent = cat.description;
          info.appendChild(desc);
        }

        row.appendChild(info);

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'okv-btn-outline-sm text-xs py-1 px-3';
        editBtn.textContent = 'Edit';
        row.appendChild(editBtn);

        card.appendChild(row);

        // Inline edit form
        var editForm = document.createElement('form');
        editForm.className = 'mt-3 pt-3 border-t border-mist space-y-3';
        editForm.hidden = true;

        var errBox = document.createElement('div');
        errBox.className = 'okv-note-bad text-xs';
        errBox.hidden = true;
        errBox.setAttribute('role', 'alert');
        editForm.appendChild(errBox);

        // Name input
        var nameDiv = document.createElement('div');
        var nameLabel = document.createElement('label');
        nameLabel.className = 'okv-label text-xs';
        nameLabel.textContent = 'Category name';
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.name = 'name';
        nameInput.required = true;
        nameInput.maxLength = 120;
        nameInput.className = 'okv-input text-sm';
        nameInput.value = cat.name;
        nameDiv.appendChild(nameLabel);
        nameDiv.appendChild(nameInput);
        editForm.appendChild(nameDiv);

        // Slug notice (immutable per Option C)
        var slugDiv = document.createElement('div');
        slugDiv.className = 'text-xs text-ink-60 bg-forest-tint/30 rounded p-2';
        var slugBold = document.createElement('span');
        slugBold.className = 'font-semibold text-ink';
        slugBold.textContent = 'Slug: ';
        slugDiv.appendChild(slugBold);
        var slugCode = document.createElement('code');
        slugCode.className = 'font-mono text-xs';
        slugCode.textContent = cat.slug;
        slugDiv.appendChild(slugCode);
        var slugHelp = document.createElement('span');
        slugHelp.className = 'text-ink-40 ml-1.5';
        slugHelp.textContent = '(Slug is fixed to protect web links)';
        slugDiv.appendChild(slugHelp);
        editForm.appendChild(slugDiv);

        // Description textarea
        var descDiv = document.createElement('div');
        var descLabel = document.createElement('label');
        descLabel.className = 'okv-label text-xs';
        descLabel.textContent = 'Description';
        var descInput = document.createElement('textarea');
        descInput.name = 'description';
        descInput.rows = 2;
        descInput.maxLength = 2000;
        descInput.className = 'okv-input text-sm';
        descInput.value = cat.description || '';
        descDiv.appendChild(descLabel);
        descDiv.appendChild(descInput);
        editForm.appendChild(descDiv);

        // Active toggle
        var activeLabel = document.createElement('label');
        activeLabel.className = 'inline-flex items-center gap-2 cursor-pointer min-h-[44px]';
        var activeCheck = document.createElement('input');
        activeCheck.type = 'checkbox';
        activeCheck.name = 'is_active';
        activeCheck.value = '1';
        activeCheck.checked = !!cat.is_active;
        activeCheck.className = 'rounded border-mist text-forest focus:ring-gold';
        var activeText = document.createElement('span');
        activeText.className = 'text-xs font-medium text-ink';
        activeText.textContent = 'Active on the shop';
        activeLabel.appendChild(activeCheck);
        activeLabel.appendChild(activeText);
        editForm.appendChild(activeLabel);

        // Action buttons
        var actDiv = document.createElement('div');
        actDiv.className = 'flex flex-wrap items-center gap-2 pt-1';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'submit';
        saveBtn.className = 'okv-btn-sm text-xs';
        saveBtn.textContent = 'Save changes';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'okv-btn-text text-xs';
        cancelBtn.textContent = 'Cancel';
        actDiv.appendChild(saveBtn);
        actDiv.appendChild(cancelBtn);
        editForm.appendChild(actDiv);

        card.appendChild(editForm);
        catList.appendChild(card);

        editBtn.addEventListener('click', function () {
          var willShow = editForm.hidden;
          editForm.hidden = !willShow;
          editBtn.textContent = willShow ? 'Close' : 'Edit';
          if (willShow) {
            nameInput.focus();
          }
        });

        cancelBtn.addEventListener('click', function () {
          editForm.hidden = true;
          editBtn.textContent = 'Edit';
          errBox.hidden = true;
          errBox.textContent = '';
          nameInput.value = cat.name;
          descInput.value = cat.description || '';
          activeCheck.checked = !!cat.is_active;
        });

        editForm.addEventListener('submit', function (event) {
          event.preventDefault();
          errBox.hidden = true;
          errBox.textContent = '';
          saveBtn.disabled = true;

          var body = new URLSearchParams();
          body.set('action', 'category_save');
          body.set('category_id', cat.id);
          body.set('okv_csrf', getCsrf());
          body.set('name', nameInput.value.trim());
          body.set('description', descInput.value.trim());
          body.set('is_active', activeCheck.checked ? '1' : '0');

          send(body).then(function (res) {
            saveBtn.disabled = false;
            if (res.ok && res.data.status === 'ok') {
              toast(res.data.message || 'Category saved.', 'ok');
              catalogueSettingsChanged = true;
              loadCategories();
              return;
            }
            var msg = res.data.message || 'We could not save that category.';
            if (res.data.errors && res.data.errors.name) {
              msg = res.data.errors.name;
            }
            errBox.textContent = msg;
            errBox.hidden = false;
          }).catch(function () {
            saveBtn.disabled = false;
            errBox.textContent = 'We could not reach the server. Check your connection.';
            errBox.hidden = false;
          });
        });
      });
    }

    function loadUnits() {
      if (!unitList) { return; }
      fetch(ENDPOINT + '?action=unit_list', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (res) {
        return res.json().catch(function () { return {}; });
      }).then(function (data) {
        if (data.status !== 'ok' || !Array.isArray(data.units)) {
          unitList.innerHTML = '<p class="text-sm text-tomato p-3">Could not load units of measurement.</p>';
          return;
        }
        renderUnitList(data.units);
      }).catch(function () {
        unitList.innerHTML = '<p class="text-sm text-tomato p-3">Could not load units of measurement.</p>';
      });
    }

    function renderUnitList(units) {
      unitList.innerHTML = '';
      if (units.length === 0) {
        var empty = document.createElement('p');
        empty.className = 'text-sm text-ink-60 py-3 text-center';
        empty.textContent = 'No units found.';
        unitList.appendChild(empty);
        return;
      }

      units.forEach(function (unit) {
        var card = document.createElement('div');
        card.className = 'p-4 rounded-lg border border-mist bg-white space-y-3';

        // Row preview
        var row = document.createElement('div');
        row.className = 'flex flex-wrap items-start justify-between gap-3';

        var info = document.createElement('div');
        info.className = 'space-y-1 min-w-0';

        var titleLine = document.createElement('div');
        titleLine.className = 'flex flex-wrap items-center gap-2';

        var nameSpan = document.createElement('span');
        nameSpan.className = 'font-semibold text-ink text-sm';
        nameSpan.textContent = unit.name + ' (' + unit.symbol + ')';
        titleLine.appendChild(nameSpan);

        var decBadge = document.createElement('span');
        decBadge.className = 'okv-badge ' + (unit.allows_decimal ? 'okv-badge-info' : 'okv-badge-neutral');
        decBadge.textContent = unit.allows_decimal ? 'Decimals allowed' : 'Whole units only';
        titleLine.appendChild(decBadge);

        var statusBadge = document.createElement('span');
        statusBadge.className = 'okv-badge ' + (unit.is_active ? 'okv-badge-available' : 'okv-badge-neutral');
        statusBadge.textContent = unit.is_active ? 'Active' : 'Inactive';
        titleLine.appendChild(statusBadge);

        var countBadge = document.createElement('span');
        countBadge.className = 'text-xs text-ink-60 font-mono';
        var pCount = (parseInt(unit.product_count, 10) || 0) + (parseInt(unit.combo_item_count, 10) || 0);
        countBadge.textContent = pCount + ' ' + (pCount === 1 ? 'item' : 'items');
        titleLine.appendChild(countBadge);

        info.appendChild(titleLine);
        row.appendChild(info);

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'okv-btn-outline-sm text-xs py-1 px-3';
        editBtn.textContent = 'Edit';
        row.appendChild(editBtn);

        card.appendChild(row);

        // Inline edit form
        var editForm = document.createElement('form');
        editForm.className = 'mt-3 pt-3 border-t border-mist space-y-3';
        editForm.hidden = true;

        var errBox = document.createElement('div');
        errBox.className = 'okv-note-bad text-xs';
        errBox.hidden = true;
        errBox.setAttribute('role', 'alert');
        editForm.appendChild(errBox);

        // Name and Symbol grid
        var grid = document.createElement('div');
        grid.className = 'grid gap-3 sm:grid-cols-2';

        var nameDiv = document.createElement('div');
        var nameLabel = document.createElement('label');
        nameLabel.className = 'okv-label text-xs';
        nameLabel.textContent = 'Unit name';
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.name = 'name';
        nameInput.required = true;
        nameInput.maxLength = 80;
        nameInput.className = 'okv-input text-sm';
        nameInput.value = unit.name;
        nameDiv.appendChild(nameLabel);
        nameDiv.appendChild(nameInput);
        grid.appendChild(nameDiv);

        var symDiv = document.createElement('div');
        var symLabel = document.createElement('label');
        symLabel.className = 'okv-label text-xs';
        symLabel.textContent = 'Symbol';
        var symInput = document.createElement('input');
        symInput.type = 'text';
        symInput.name = 'symbol';
        symInput.required = true;
        symInput.maxLength = 20;
        symInput.className = 'okv-input font-mono text-sm';
        symInput.value = unit.symbol;
        symDiv.appendChild(symLabel);
        symDiv.appendChild(symInput);
        grid.appendChild(symDiv);

        editForm.appendChild(grid);

        // Checkboxes
        var checkGroup = document.createElement('div');
        checkGroup.className = 'space-y-2';

        var decLabel = document.createElement('label');
        decLabel.className = 'flex items-start gap-2 cursor-pointer min-h-[44px]';
        var decCheck = document.createElement('input');
        decCheck.type = 'checkbox';
        decCheck.name = 'allows_decimal';
        decCheck.value = '1';
        decCheck.checked = !!unit.allows_decimal;
        decCheck.className = 'mt-1 rounded border-mist text-forest focus:ring-gold';
        var decWrap = document.createElement('span');
        decWrap.className = 'text-xs text-ink';
        var decBold = document.createElement('span');
        decBold.className = 'font-medium block';
        decBold.textContent = 'Allow decimal quantities';
        var decMuted = document.createElement('span');
        decMuted.className = 'text-ink-60 block';
        decMuted.textContent = 'Permit fractions like 0.5 or 1.5 in customer orders.';
        decWrap.appendChild(decBold);
        decWrap.appendChild(decMuted);
        decLabel.appendChild(decCheck);
        decLabel.appendChild(decWrap);
        checkGroup.appendChild(decLabel);

        var activeLabel = document.createElement('label');
        activeLabel.className = 'flex items-start gap-2 cursor-pointer min-h-[44px]';
        var activeCheck = document.createElement('input');
        activeCheck.type = 'checkbox';
        activeCheck.name = 'is_active';
        activeCheck.value = '1';
        activeCheck.checked = !!unit.is_active;
        activeCheck.className = 'mt-1 rounded border-mist text-forest focus:ring-gold';
        var activeWrap = document.createElement('span');
        activeWrap.className = 'text-xs text-ink';
        var activeBold = document.createElement('span');
        activeBold.className = 'font-medium block';
        activeBold.textContent = 'Active for new products';
        var activeMuted = document.createElement('span');
        activeMuted.className = 'text-ink-60 block';
        activeMuted.textContent = 'Available when creating or editing produce.';
        activeWrap.appendChild(activeBold);
        activeWrap.appendChild(activeMuted);
        activeLabel.appendChild(activeCheck);
        activeLabel.appendChild(activeWrap);
        checkGroup.appendChild(activeLabel);

        editForm.appendChild(checkGroup);

        // Safety confirmation box (Option C: Full editing with confirmation)
        var confirmBox = document.createElement('div');
        confirmBox.className = 'p-3 bg-gold-tint2 border border-gold rounded text-xs text-gold-ink space-y-2';
        confirmBox.hidden = true;

        var confirmTitle = document.createElement('p');
        confirmTitle.className = 'font-semibold text-ink';
        confirmTitle.textContent = 'Check before saving:';
        confirmBox.appendChild(confirmTitle);

        var confirmText = document.createElement('p');
        var itemCount = (parseInt(unit.product_count, 10) || 0) + (parseInt(unit.combo_item_count, 10) || 0);
        confirmText.textContent = itemCount + ' catalogue item' + (itemCount === 1 ? ' is' : 's are')
          + ' currently using this unit. Changing decimal settings or deactivating it may affect pricing and order calculation.';
        confirmBox.appendChild(confirmText);

        var confirmLabel = document.createElement('label');
        confirmLabel.className = 'flex items-start gap-2 cursor-pointer font-medium text-ink pt-1';
        var confirmCheck = document.createElement('input');
        confirmCheck.type = 'checkbox';
        confirmCheck.name = 'confirmed';
        confirmCheck.value = '1';
        confirmCheck.className = 'mt-0.5 rounded border-mist text-forest focus:ring-gold';
        var confirmCheckText = document.createElement('span');
        confirmCheckText.textContent = 'I understand the impact and confirm this change';
        confirmLabel.appendChild(confirmCheck);
        confirmLabel.appendChild(confirmCheckText);
        confirmBox.appendChild(confirmLabel);

        editForm.appendChild(confirmBox);

        function updateSafetyWarning() {
          if (itemCount > 0) {
            var decimalChanged = decCheck.checked !== !!unit.allows_decimal;
            var deactivated = !activeCheck.checked && !!unit.is_active;
            confirmBox.hidden = !(decimalChanged || deactivated);
          } else {
            confirmBox.hidden = true;
          }
        }

        decCheck.addEventListener('change', updateSafetyWarning);
        activeCheck.addEventListener('change', updateSafetyWarning);

        // Action buttons
        var actDiv = document.createElement('div');
        actDiv.className = 'flex flex-wrap items-center gap-2 pt-1';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'submit';
        saveBtn.className = 'okv-btn-sm text-xs';
        saveBtn.textContent = 'Save changes';
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'okv-btn-text text-xs';
        cancelBtn.textContent = 'Cancel';
        actDiv.appendChild(saveBtn);
        actDiv.appendChild(cancelBtn);
        editForm.appendChild(actDiv);

        card.appendChild(editForm);
        unitList.appendChild(card);

        editBtn.addEventListener('click', function () {
          var willShow = editForm.hidden;
          editForm.hidden = !willShow;
          editBtn.textContent = willShow ? 'Close' : 'Edit';
          if (willShow) {
            nameInput.focus();
            updateSafetyWarning();
          }
        });

        cancelBtn.addEventListener('click', function () {
          editForm.hidden = true;
          editBtn.textContent = 'Edit';
          errBox.hidden = true;
          errBox.textContent = '';
          nameInput.value = unit.name;
          symInput.value = unit.symbol;
          decCheck.checked = !!unit.allows_decimal;
          activeCheck.checked = !!unit.is_active;
          confirmCheck.checked = false;
          confirmBox.hidden = true;
        });

        editForm.addEventListener('submit', function (event) {
          event.preventDefault();
          errBox.hidden = true;
          errBox.textContent = '';
          saveBtn.disabled = true;

          var body = new URLSearchParams();
          body.set('action', 'unit_save');
          body.set('unit_id', unit.id);
          body.set('okv_csrf', getCsrf());
          body.set('name', nameInput.value.trim());
          body.set('symbol', symInput.value.trim());
          body.set('allows_decimal', decCheck.checked ? '1' : '0');
          body.set('is_active', activeCheck.checked ? '1' : '0');
          if (confirmCheck.checked) {
            body.set('confirmed', '1');
          }

          send(body).then(function (res) {
            saveBtn.disabled = false;
            if (res.ok && res.data.status === 'ok') {
              toast(res.data.message || 'Unit saved.', 'ok');
              catalogueSettingsChanged = true;
              loadUnits();
              return;
            }
            if (res.data.code === 'confirmation_required') {
              confirmBox.hidden = false;
              confirmCheck.focus();
              errBox.textContent = res.data.message || 'Please confirm this change before saving.';
              errBox.hidden = false;
              return;
            }
            var msg = res.data.message || 'We could not save that unit.';
            if (res.data.errors) {
              var firstErr = res.data.errors.name || res.data.errors.symbol;
              if (firstErr) { msg = firstErr; }
            }
            errBox.textContent = msg;
            errBox.hidden = false;
          }).catch(function () {
            saveBtn.disabled = false;
            errBox.textContent = 'We could not reach the server. Check your connection.';
            errBox.hidden = false;
          });
        });
      });
    }
  }

  ready(function () {
    wireList(document);
    wirePanel('[data-add-open]', '[data-add-panel]', '[data-add-close]');
    wireCatalogueSettingsModal();
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
